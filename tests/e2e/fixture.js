'use strict';
/**
 * Fixture fragments generated from data/services.json: one real fragment per
 * library service, so a service added to the library is covered without
 * touching the test.
 *
 *   - script service: <script async src="https://<first host>/…">, plus the
 *     typical inline form where the service has one (gtag, GTM, fbq);
 *   - embed service:  <iframe src="https://…">.
 *
 * WordPress gets them raw (as a theme prints them: scripts in wp_head, inline
 * snippets in wp_footer, iframes in a page's content) and the plugin must
 * neutralise them. The standalone page gets them marked as standalone/README.md
 * says (necessary scripts left unmarked, the rest type="text/plain").
 *
 * CLI: node tests/e2e/fixture.js <services.json> <out.json>
 */
const fs = require('fs');
const path = require('path');
const { splitPattern } = require('./match');

/** Test-only ids (never real accounts). */
const GA4_ID = 'G-E2ETEST01';
const GTM_ID = 'GTM-E2ETEST';

/**
 * Typical real-world forms for well-known services. Anything not listed here
 * gets the generic fragment built from its first host.
 */
const TYPICAL = {
	'google-analytics': {
		// GA4 gtag.js loader: the tag the Advanced mode leaves live.
		src: 'https://www.googletagmanager.com/gtag/js?id=' + GA4_ID,
		inline: "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','" + GA4_ID + "');",
	},
	'google-tag-manager': {
		src: 'https://www.googletagmanager.com/gtm.js?id=' + GTM_ID,
		inline: "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','" + GTM_ID + "');",
		loads: ['https://www.googletagmanager.com/gtm.js?id=' + GTM_ID],
	},
	'meta-pixel': {
		inline: "!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','000000000000000');fbq('track','PageView');",
		loads: ['https://connect.facebook.net/en_US/fbevents.js'],
	},
	// Other request channels than <script src> / <iframe>, each from an inline
	// script carrying the service's own marker (so the plugin attributes it):
	// an <img> pixel built in JS, plus the <noscript><img> pixel form.
	'linkedin-insight': {
		keepSrc: true,
		inline: "window._linkedin_partner_id='0000000';(new Image()).src='https://px.ads.linkedin.com/collect/?pid=0000000&fmt=gif&e2e=img';",
		loads: ['https://px.ads.linkedin.com/collect/?pid=0000000&fmt=gif&e2e=img'],
		noscript: '<img height="1" width="1" style="display:none" alt="" src="https://px.ads.linkedin.com/collect/?pid=0000000&fmt=gif&e2e=noscript">',
	},
	// navigator.sendBeacon from an inline script.
	hotjar: {
		keepSrc: true,
		inline: "window._hjSettings={hjid:0,hjsv:6};navigator.sendBeacon('https://script.hotjar.com/e2e-beacon','e2e');",
		loads: ['https://script.hotjar.com/e2e-beacon'],
	},
	// fetch() from an inline script.
	'tiktok-pixel': {
		keepSrc: true,
		inline: "window.ttq=window.ttq||{load:function(){}};ttq.load('E2E');fetch('https://analytics.tiktok.com/api/v2/pixel/e2e',{method:'POST',mode:'no-cors',body:'e2e'}).catch(function(){});",
		loads: ['https://analytics.tiktok.com/api/v2/pixel/e2e'],
	},
	youtube: { iframe: 'https://www.youtube.com/embed/E2Etest0001' },
	vimeo: { iframe: 'https://player.vimeo.com/video/1' },
	'google-maps': { iframe: 'https://www.google.com/maps/embed?pb=e2e' },
};

/** Generic URL on a service's first host: https://host[/path]/e2e(.js). */
function genericUrl(service) {
	const first = (service.hosts || [])[0];
	if (!first) {
		return '';
	}
	const { host, path: p } = splitPattern(first);
	const base = 'https://' + host + (service.type === 'embed' ? p + (service.path || '') : p);
	return base.replace(/\/+$/, '') + (service.type === 'embed' ? '/e2e' : '/e2e.js');
}

/** The fragment parts of one service: { src, inline, iframe, urls }. */
function partsFor(service) {
	const t = TYPICAL[service.id] || {};
	const out = { src: '', inline: '', iframe: '', noscript: t.noscript || '', urls: [] };
	if (service.type === 'embed') {
		out.iframe = t.iframe || genericUrl(service);
		out.urls.push(out.iframe);
		return out;
	}
	out.src = t.src || (t.inline && !t.keepSrc ? '' : genericUrl(service));
	out.inline = t.inline || '';
	if (out.src) {
		out.urls.push(out.src);
	}
	(t.loads || []).forEach((u) => out.urls.push(u));
	if (!out.src && !out.inline) {
		out.src = genericUrl(service);
		out.urls.push(out.src);
	}
	return out;
}

function esc(s) {
	return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/** Raw fragments for WordPress (the plugin must neutralise them). */
function wpFragments(services) {
	const head = [];
	const footer = [];
	const content = [];
	for (const s of services) {
		const p = partsFor(s);
		const tag = ' data-e2e-service="' + esc(s.id) + '"';
		if (p.src) {
			head.push('<script async src="' + esc(p.src) + '"' + tag + '></script>');
		}
		if (p.inline) {
			footer.push('<script' + tag + '>' + p.inline + '</script>');
		}
		if (p.noscript) {
			footer.push('<noscript>' + p.noscript + '</noscript>');
		}
		if (p.iframe) {
			content.push('<iframe width="560" height="315" src="' + esc(p.iframe) + '"' + tag + ' allowfullscreen></iframe>');
		}
	}
	return { head: head.join('\n'), footer: footer.join('\n'), content: content.join('\n') };
}

/** Fragments marked for the standalone bundle, as standalone/README.md documents. */
function standaloneFragments(services) {
	const out = [];
	for (const s of services) {
		const p = partsFor(s);
		const cat = String(s.category || 'marketing');
		const plain = cat === 'necessary'; // necessary scripts are left unmarked
		if (p.src) {
			out.push(plain
				? '<script async src="' + esc(p.src) + '"></script>'
				: '<script type="text/plain" data-cmp-block="' + esc(cat) + '" data-cmp-enc="1" data-cmp-src="' + Buffer.from(p.src).toString('base64') + '"></script>');
		}
		if (p.inline) {
			out.push(plain
				? '<script>' + p.inline + '</script>'
				: '<script type="text/plain" data-cmp-block="' + esc(cat) + '">' + p.inline + '</script>');
		}
		if (p.noscript) {
			out.push('<noscript>' + p.noscript + '</noscript>');
		}
		if (p.iframe) {
			const name = esc(s.name || s.id);
			out.push(
				'<div class="cmp-embed" data-cmp-embed="' + esc(cat) + '" data-cmp-service="' + esc(s.id) + '" data-cmp-service-name="' + name + '" style="aspect-ratio:560/315;max-width:560px">'
				+ '<div class="cmp-embed__inner"><p class="cmp-embed__text">' + name + '</p><p class="cmp-embed__actions">'
				+ '<button type="button" class="cmp-embed__btn" data-cmp-embed-load>Load</button>'
				+ '<a href="#cmp-settings" class="cmp-embed__link" data-cmp-settings>Settings</a></p></div>'
				+ '<template data-cmp-embed-src><iframe width="560" height="315" src="' + esc(p.iframe) + '" allowfullscreen></iframe></template>'
				+ '</div>'
			);
		}
	}
	return out.join('\n');
}

/** Categories the standalone config must offer: necessary first, then the library's own. */
function standaloneCategories(services) {
	const cats = ['necessary'];
	for (const s of services) {
		const c = String(s.category || '');
		if (c && cats.indexOf(c) === -1) {
			cats.push(c);
		}
	}
	return cats;
}

/** Everything the fixture needs, from the library. */
function build(library) {
	const services = (library && library.services) || [];
	const urls = {};
	services.forEach((s) => {
		urls[s.id] = partsFor(s).urls;
	});
	return {
		services: services.map((s) => ({ id: s.id, name: s.name || s.id, category: s.category, type: s.type })),
		urls,
		wp: wpFragments(services),
		standalone: {
			html: standaloneFragments(services),
			categories: standaloneCategories(services),
			// cmp.js has no exemption logic (exempt_default is a WordPress-side flag):
			// it blocks exactly what the site marks, and README says to mark every
			// non-necessary tracker. So only necessary services load before consent.
			before: services.filter((s) => s.category === 'necessary').map((s) => s.id),
		},
	};
}

/** Fill the standalone template (marker comments) with the generated fragments. */
function fillStandalone(template, built) {
	return String(template)
		.replace('/*CMP-E2E-CATEGORIES*/[]', JSON.stringify(built.standalone.categories))
		.replace('<!--CMP-E2E-FRAGMENTS-->', built.standalone.html);
}

module.exports = { build, partsFor, genericUrl, fillStandalone, TYPICAL, GA4_ID, GTM_ID };

if (require.main === module) {
	const [src, outJson, tplIn, tplOut] = process.argv.slice(2);
	if (!src || !outJson) {
		console.error('Usage: node tests/e2e/fixture.js <services.json> <out.json> [standalone.html template] [filled standalone.html]');
		process.exit(2);
	}
	const built = build(JSON.parse(fs.readFileSync(src, 'utf8')));
	fs.mkdirSync(path.dirname(outJson), { recursive: true });
	fs.writeFileSync(outJson, JSON.stringify(built, null, 1));
	if (tplIn && tplOut) {
		fs.mkdirSync(path.dirname(tplOut), { recursive: true });
		fs.writeFileSync(tplOut, fillStandalone(fs.readFileSync(tplIn, 'utf8'), built));
	}
}
