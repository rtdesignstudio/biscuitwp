'use strict';
// Pure tests for the request → service matcher and the generated fixture.
// Run: node tests/e2e/match.test.js
var fs = require('fs');
var path = require('path');
var match = require('./match');
var fixture = require('./fixture');

var fails = 0, passes = 0;
function ok(cond, msg) { if (cond) { passes++; } else { fails++; console.error('FAIL: ' + msg); } }
function eq(a, b, msg) { ok(JSON.stringify(a) === JSON.stringify(b), msg + ' (got ' + JSON.stringify(a) + ')'); }

var library = JSON.parse(fs.readFileSync(path.join(__dirname, '..', '..', 'data', 'services.json'), 'utf8'));
var services = library.services;
var sf = function (u) { return match.serviceFor(u, services); };

// Google tags: host + path wins over the bare host.
eq(sf('https://www.googletagmanager.com/gtag/js?id=G-ABC123'), 'google-analytics', 'gtag/js -> GA');
eq(sf('https://www.google-analytics.com/g/collect?v=2'), 'google-analytics', 'google-analytics.com -> GA');
eq(sf('https://www.googletagmanager.com/gtm.js?id=GTM-XXXX'), 'google-tag-manager', 'gtm.js -> GTM');
eq(sf('https://googletagmanager.com/ns.html?id=GTM-XXXX'), 'google-tag-manager', 'bare GTM host -> GTM');

// Same host, two services: reCAPTCHA (necessary) vs Maps (marketing).
eq(sf('https://www.google.com/recaptcha/api.js'), 'recaptcha', 'www.google.com/recaptcha -> recaptcha');
eq(sf('https://www.google.com/maps/embed?pb=1'), 'google-maps', 'www.google.com/maps -> google-maps');
eq(sf('https://www.gstatic.com/recaptcha/releases/x/recaptcha__en.js'), 'recaptcha', 'gstatic recaptcha -> recaptcha');
eq(sf('https://www.google.com/search?q=x'), null, 'www.google.com elsewhere -> no service');
eq(sf('https://www.gstatic.com/other.js'), null, 'gstatic outside /recaptcha -> no service');

// Subdomains and alternative hosts.
eq(sf('https://www.youtube-nocookie.com/embed/abc'), 'youtube', 'youtube-nocookie subdomain -> youtube');
eq(sf('https://www.youtube.com/embed/abc'), 'youtube', 'youtube.com -> youtube');
eq(sf('https://player.vimeo.com/video/1'), 'vimeo', 'player.vimeo.com -> vimeo');
eq(sf('https://connect.facebook.net/en_US/fbevents.js'), 'meta-pixel', 'fbevents -> meta-pixel');
eq(sf('https://cdn-eu.usefathom.com/script.js'), 'fathom', 'fathom EU -> fathom');

// Look-alike hosts never match.
eq(sf('https://notyoutube.com/embed/abc'), null, 'suffix without dot -> null');
eq(sf('https://youtube.com.evil.example/x'), null, 'prefix look-alike -> null');
eq(sf('https://unknown-tracker.example/t.js'), null, 'unknown host -> null');
eq(sf('not a url'), null, 'garbage -> null');
eq(sf('http://127.0.0.1:9400/wp-content/plugins/x.js'), null, 'local -> null');

// hostMatches mirrors CMP_Services::host_matches().
ok(match.hostMatches('https://a.b.clarity.ms/x', 'clarity.ms'), 'deep subdomain matches');
ok(!match.hostMatches('https://www.google.com/recaptchax', 'www.google.com/recaptcha/'), 'path prefix respected');

// Fixture: complete (every service has a URL that is attributed to it).
var built = fixture.build(library);
services.forEach(function (s) {
	var urls = built.urls[s.id] || [];
	ok(urls.length > 0, 'fixture has a URL for ' + s.id);
	ok(urls.some(function (u) { return sf(u) === s.id; }), 'a fixture URL of ' + s.id + ' matches it (got ' + JSON.stringify(urls.map(sf)) + ')');
	urls.forEach(function (u) { ok(/^https:\/\//.test(u), s.id + ' fixture URL is https: ' + u); });
});

// A service added to the library is covered with no change to the test.
var extended = JSON.parse(JSON.stringify(library));
extended.services.push({ id: 'new-pixel', name: 'New Pixel', type: 'script', category: 'marketing', hosts: ['px.newpixel.example'], inline: [] });
extended.services.push({ id: 'new-embed', name: 'New Embed', type: 'embed', category: 'marketing', hosts: ['player.newembed.example'], path: '/v' });
var b2 = fixture.build(extended);
eq(b2.urls['new-pixel'].map(function (u) { return match.serviceFor(u, extended.services); }), ['new-pixel'], 'new script service gets a matching fixture URL');
eq(b2.urls['new-embed'].map(function (u) { return match.serviceFor(u, extended.services); }), ['new-embed'], 'new embed service (with path) gets a matching fixture URL');
ok(b2.wp.head.indexOf('px.newpixel.example') !== -1, 'new script service printed in wp_head');
ok(b2.wp.content.indexOf('player.newembed.example/v/') !== -1, 'new embed service printed in content');
ok(b2.standalone.html.indexOf('data-cmp-service="new-embed"') !== -1, 'new embed service in standalone page');

// WordPress fragments are raw; standalone ones are marked (README), necessary left unmarked.
ok(built.wp.head.indexOf('type="text/plain"') === -1, 'WP fragments are raw');
ok(built.wp.head.indexOf('gtag/js?id=' + fixture.GA4_ID) !== -1, 'GA4 loader in wp_head (Advanced keeps it live)');
ok(built.wp.footer.indexOf("gtag('config','" + fixture.GA4_ID + "')") !== -1, 'gtag inline form');
ok(built.wp.footer.indexOf("fbq('init'") !== -1, 'fbq inline form');
ok(built.wp.content.indexOf('<iframe') !== -1, 'embeds are iframes');
services.forEach(function (s) {
	if (s.type === 'script' && s.category !== 'necessary') {
		var p = fixture.partsFor(s);
		if (p.src) {
			ok(built.standalone.html.indexOf('data-cmp-src="' + Buffer.from(p.src).toString('base64') + '"') !== -1, s.id + ' marked (base64) in standalone');
			ok(built.standalone.html.indexOf('src="' + p.src) === -1, s.id + ' never as a live src in standalone');
		}
	}
});
eq(built.standalone.before, services.filter(function (s) { return s.category === 'necessary'; }).map(function (s) { return s.id; }), 'standalone: only necessary loads before consent');
ok(built.standalone.html.indexOf('<script async src="https://www.google.com/recaptcha/') !== -1, 'reCAPTCHA left unmarked in standalone');
eq(built.standalone.categories[0], 'necessary', 'standalone categories start with necessary');

// Other request channels: <img> pixel (JS + noscript), sendBeacon, fetch — attributed to their service.
eq(sf('https://px.ads.linkedin.com/collect/?pid=0000000&fmt=gif&e2e=img'), 'linkedin-insight', 'img pixel -> linkedin-insight');
eq(sf('https://script.hotjar.com/e2e-beacon'), 'hotjar', 'sendBeacon -> hotjar');
eq(sf('https://analytics.tiktok.com/api/v2/pixel/e2e'), 'tiktok-pixel', 'fetch -> tiktok-pixel');
ok(built.wp.footer.indexOf('new Image()') !== -1 && built.wp.footer.indexOf('_linkedin_partner_id') !== -1, 'img pixel inline carries the LinkedIn marker');
ok(built.wp.footer.indexOf('<noscript><img') !== -1, 'noscript img pixel printed');
ok(built.wp.footer.indexOf('navigator.sendBeacon') !== -1 && built.wp.footer.indexOf('_hjSettings') !== -1, 'sendBeacon inline carries the Hotjar marker');
ok(built.wp.footer.indexOf('fetch(') !== -1 && built.wp.footer.indexOf('ttq.load') !== -1, 'fetch inline carries the TikTok marker');
['linkedin-insight', 'hotjar', 'tiktok-pixel'].forEach(function (id) {
	var p = fixture.partsFor(services.filter(function (s) { return s.id === id; })[0]);
	ok(p.src && p.inline, id + ' keeps its <script src> and adds the inline variant');
	ok(built.standalone.html.indexOf('<script type="text/plain" data-cmp-block="' + services.filter(function (s) { return s.id === id; })[0].category + '">' + p.inline) !== -1, id + ' inline variant marked in standalone');
});

// Template markers are present and filled.
var tpl = fs.readFileSync(path.join(__dirname, 'standalone.html'), 'utf8');
ok(tpl.indexOf('<!--CMP-E2E-FRAGMENTS-->') !== -1 && tpl.indexOf('/*CMP-E2E-CATEGORIES*/[]') !== -1, 'standalone template has both markers');
var filled = fixture.fillStandalone(tpl, built);
ok(filled.indexOf('CMP-E2E-') === -1, 'filled template has no marker left');
ok(filled.indexOf('categories: ["necessary"') !== -1, 'filled template carries the categories');

console.log((fails ? 'FAILED' : 'OK') + ': ' + passes + ' passed, ' + fails + ' failed');
process.exit(fails ? 1 : 0);
