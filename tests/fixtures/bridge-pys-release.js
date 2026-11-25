/*
 * Lot A, Task 2: a page as the browser runs it. stdin: the blocker's own output
 * (pysOptions, the gate and the src tag). Live inline scripts run in document
 * order; a live src (deferred by PixelYourSite) runs after them, before
 * DOMContentLoaded; then each step of argv[2] releases scripts with
 * unblockScripts() taken from the real assets/js/banner.js.
 * argv[2]: JSON { steps: [[slugs], …], mode }. Prints JSON: log, and for every
 * run of public.js what it would load (its own test: options.gdpr.*_disabled_by_api).
 */
'use strict';
var fs = require('fs'), path = require('path'), vm = require('vm');
var inp = JSON.parse(process.argv[2]);
var html = fs.readFileSync(0, 'utf8');
var banner = fs.readFileSync(path.join(__dirname, '../../assets/js/banner.js'), 'utf8');
var fn = banner.slice(banner.indexOf('function unblockScripts('), banner.indexOf('// Third-party embeds'));
var log = [], runs = [], late = [], accepted = [], nodes = [], inserted = [];
var win = { atob: atob, CMP_DATA: { mode: inp.mode || 'pro', categories: [] }, CMP: { isAllowed: function (s) { return accepted.indexOf(s) !== -1; } } };
win.window = win;
var ctx = vm.createContext(win);
function publicJs(src) {
	log.push('src');
	try {
		runs.push(vm.runInContext('(function(o){return {facebook:!!o.facebook&&!o.gdpr.facebook_disabled_by_api,google:!!(o.ga||o.gtm)&&!o.gdpr.analytics_disabled_by_api,other:!o.gdpr.pinterest_disabled_by_api};})(pysOptions)', ctx));
	} catch (e) { runs.push({ error: String(e && e.message || e) }); }
}
var parent = {
	insertBefore: function (s) {
		if (s.src) { late.push(s.src); } else { log.push('inline:' + (s.id || '')); vm.runInContext(s.text, ctx); }
	}
};
html.replace(/<script\b([^>]*)>([\s\S]*?)<\/script>/g, function (all, attrs, body) {
	var at = {};
	attrs.replace(/([a-z-]+)(?:="([^"]*)")?/g, function (x, k, v) { at[k] = v === undefined ? '' : v; return ''; });
	if (at.type === 'text/plain') {
		nodes.push({
			id: at.id || '', textContent: body, parentNode: parent, nextSibling: null,
			getAttribute: function (k) { return k in at ? at[k] : null; },
			hasAttribute: function (k) { return k in at; },
			removeAttribute: function (k) { delete at[k]; }
		});
	} else if (at.src) { late.push(at.src); } else { vm.runInContext(body, ctx); }
	return '';
});
win.document = {
	querySelectorAll: function () { return nodes.filter(function (n) { return n.hasAttribute('data-cmp-block'); }); },
	createElement: function () { return { setAttribute: function () {} }; }
};
function flush() { late.splice(0).forEach(publicJs); }
vm.runInContext(fn, ctx);
flush();
if (inp.mode !== 'light') { // banner.js never releases in Light
	(inp.steps || []).forEach(function (acc) {
		accepted = acc;
		vm.runInContext('unblockScripts(' + JSON.stringify(acc) + ')', ctx);
		flush();
	});
}
process.stdout.write(JSON.stringify({ log: log, runs: runs }));
