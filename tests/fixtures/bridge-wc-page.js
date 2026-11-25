/*
 * Lot A, Task 3: the WooCommerce bridge on a fake page (tests/test-bridge-wc.php).
 * order-attribution.js and sourcebuster are small models of WooCommerce 11.3.1
 * (assets/js/frontend/order-attribution.js:22-61, 101-146, 174-231; sourcebuster.js:310,
 * 419, 660, 685, 847): nothing of WooCommerce is loaded. The checkout element is modelled
 * with what it really does: setOrderTracking() runs before customElements.define(), so
 * `element.values = ...` becomes an own property, and the constructor looks for `_values`
 * (the .min too), so the own property shadows the accessor for good.
 *
 * argv[2]: JSON { before, after (the inline code), host, cookies: { sbjs_*: value },
 *   mode, cats: [slugs] (CMP_DATA.categories), required: [slugs], allowed: [slugs],
 *   steps: [ … ] }. Steps:
 *   'extra' (wp_localize_script), 'extra-true' (allowTracking true in the HTML),
 *   'extra-frozen', 'params' (wc_order_attribution.params replaced by a copy),
 *   [ 'el', form ] (one more <wc-order-attribution-inputs>, in that form),
 *   'no-qsa' (querySelectorAll fails), 'no-cookie' (document.cookie throws), 'before', 'after', 'wc' (order-attribution.js runs), 'sbjs',
 *   'cmp-data', 'cmp' (banner.js runs: CMP_DATA and CMP), 'cmp-throws', 'cmp-odd',
 *   [ 'consent', [slugs] ] (a decision: cookie, then cmp:consent),
 *   [ 'elsewhere', [slugs] ] (a decision in another tab: no event here),
 *   'dcl', 'load', 'pageshow', 'visible', 'script' (an external script loaded),
 *   [ 'wc-set', bool ] (someone else calls setOrderTracking), 'wc-throws'.
 *   forms: [form names with an element from the start], default [ 'checkout' ].
 * Prints JSON { error, calls: [args of setOrderTracking], inits, cookies, writes
 *   (document.cookie assignments made by the bridge), forms: { form: the value of the
 *   hidden <input>, what the order would carry }, form (the checkout's), groups, block,
 *   allow, log }.
 */
'use strict';
var vm = require('vm');
var inp = JSON.parse(process.argv[2]);
var allowed = inp.allowed || [];
var jar = Object.assign({}, inp.cookies || {}); // "name@domain" => value
var out = { error: '', calls: [], inits: 0, writes: [], block: 'unset', log: [], reads: 0 };
function target() {
	var l = {};
	return {
		addEventListener: function (n, fn, cap) { (l[n] = l[n] || []).push([fn, !!cap]); },
		removeEventListener: function (n, fn, cap) { l[n] = (l[n] || []).filter(function (x) { return x[0] !== fn || x[1] !== !!cap; }); },
		fire: function (n, capOnly) { (l[n] || []).slice().forEach(function (x) { if (!capOnly || x[1]) { x[0]({ type: n }); } }); },
		count: function (n) { return (l[n] || []).length; }
	};
}
var doc = target(), winT = target();
var win = { addEventListener: winT.addEventListener, removeEventListener: winT.removeEventListener, document: doc };
win.window = win;
var ctx = vm.createContext(win);
var host = inp.host || 'example.test';
var NAMES = ['sbjs_current', 'sbjs_current_add', 'sbjs_first', 'sbjs_first_add', 'sbjs_session', 'sbjs_udata', 'sbjs_migrations', 'sbjs_promo'];
function get(n) { var k = Object.keys(jar).filter(function (x) { return x.split('@')[0] === n; })[0]; return k ? jar[k] : null; }
// The browser's cookies, as the bridge sees them.
win.location = { hostname: host };
Object.defineProperty(doc, 'cookie', {
	get: function () { if (noCookie) { throw new Error('cookie'); } return Object.keys(jar).map(function (k) { return k.split('@')[0] + '=' + jar[k]; }).join('; '); },
	set: function (c) {
		out.writes.push(c);
		var m = /^([^=]+)=([^;]*)(.*)$/.exec(c), dom = /domain=([^;\s]+)/.exec(m[3]), age = /max-age=(-?\d+)/.exec(m[3]);
		var k = m[1] + '@' + (dom ? dom[1] : host);
		if (age && parseInt(age[1], 10) <= 0 && /path=\/(;|$)/.test(m[3])) { delete jar[k]; } else { jar[k] = m[2]; }
	}
});
// <wc-order-attribution-inputs> (order-attribution.js:174-231).
var els = [], defined = false, qsaFails = false, noCookie = false;
var Proto = {
	upgrade: function () {
		if (Object.prototype.hasOwnProperty.call(this, '_values')) { // the wrong name, as in WooCommerce
			var values = this.values;
			delete this.values;
			this.values = values || {};
		}
		this.input = { value: (this.values && this.values.source) || '' }; // connectedCallback
	}
};
Object.defineProperty(Proto, 'values', {
	get: function () { return this._values; },
	set: function (v) {
		this._values = v;
		if (this.input) { this.input.value = this.values.source === null || this.values.source === undefined ? '' : this.values.source; }
	}
});
function upgrade(el) { Object.setPrototypeOf(el, Proto); el.upgrade(); }
function addEl(form) {
	var el = {};
	Object.defineProperty(el, 'form', { value: form });
	els.push(el);
	if (defined) { upgrade(el); }
}
(inp.forms || ['checkout']).forEach(addEl);
doc.querySelectorAll = function (sel) {
	if (qsaFails) { throw new Error('qsa'); }
	return sel === 'wc-order-attribution-inputs' ? els.slice() : [];
};
function updateFormValues(values) {
	var owners = [];
	els = els.filter(function (el) { if (owners.indexOf(el.form) !== -1) { return false; } owners.push(el.form); return true; });
	els.forEach(function (el) { el.values = values; });
}
function sbjs() {
	win.sbjs = { init: function () {
		out.inits++;
		var dom = '@.' + host.replace('www.', ''); // sourcebuster.js:310, 847
		if (!get('sbjs_current')) { jar['sbjs_current' + dom] = inp.source || 'direct'; }
		jar['sbjs_session' + dom] = String((parseInt(get('sbjs_session'), 10) || 0) + 1);
		this.get = { source: get('sbjs_current') };
	} };
}
function extra(allow) {
	vm.runInContext('var wc_order_attribution = ' + JSON.stringify({ params: { allowTracking: allow, prefix: 'wc_order_attribution_' }, fields: { source: 'source' } }) + ';', ctx);
}
function wc() {
	var o = win.wc_order_attribution, p = o.params;
	if (!win.sbjs) { sbjs(); }
	o.getAttributionData = function () { return { source: p.allowTracking && win.sbjs ? win.sbjs.get.source : null }; };
	o.setOrderTracking = function (allow) {
		out.calls.push(allow);
		if (inp.throwing) { throw new Error('wc'); }
		p.allowTracking = allow;
		if (!allow) { NAMES.forEach(function (n) { delete jar[n + '@.' + host]; }); } // order-attribution.js:126-143
		else { win.sbjs.init({}); }
		updateFormValues(o.getAttributionData()); // the classic checkout and register forms
		out.block = o.getAttributionData().source; // updateCheckoutBlockData: setExtensionData
	};
	o.setOrderTracking(p.allowTracking);
	defined = true; // customElements.define(): after the start
	els.forEach(upgrade);
}
function cmpData() {
	win.CMP_DATA = { mode: inp.mode || 'pro', categories: (inp.cats || ['necessary', 'statistics', 'marketing']).map(function (s) { return { slug: s, required: (inp.required || ['necessary']).indexOf(s) !== -1 }; }) };
}
function cmp() {
	cmpData();
	// As banner.js: isAllowed does not look at the mode, nor whether the category still exists.
	win.CMP = { isAllowed: function (s) {
		out.reads++;
		var c = win.CMP_DATA.categories.filter(function (x) { return x.slug === s; })[0];
		return !!(c && c.required) || allowed.indexOf(s) !== -1;
	} };
}
var act = {
	'extra': function () { extra(false); },
	'extra-true': function () { extra(true); },
	'extra-frozen': function () { extra(false); vm.runInContext('Object.defineProperty(wc_order_attribution.params,"allowTracking",{value:false,writable:true,configurable:false,enumerable:true})', ctx); },
	'params': function () { win.wc_order_attribution.params = { prefix: 'x' }; },
	'el': addEl,
	'no-cookie': function () { noCookie = true; },
	'no-qsa': function () { qsaFails = true; },
	'before': function () { vm.runInContext(inp.before, ctx); },
	'after': function () { vm.runInContext(inp.after, ctx); },
	'wc': wc,
	'sbjs': sbjs,
	'cmp-data': cmpData,
	'cmp': cmp,
	'cmp-foreign': function () { win.CMP = { version: 'x' }; }, // another script's global CMP: no isAllowed, not ours
	'cmp-throws': function () { cmpData(); win.CMP = { isAllowed: function () { throw new Error('x'); } }; },
	'cmp-odd': function () { cmpData(); win.CMP = { isAllowed: function () { return 1; } }; },
	'consent': function (s) { allowed = s; doc.fire('cmp:consent'); },
	'elsewhere': function (s) { allowed = s; },
	'dcl': function () { doc.fire('DOMContentLoaded'); },
	'load': function () { winT.fire('load'); },
	'pageshow': function () { winT.fire('pageshow'); },
	'visible': function () { doc.fire('visibilitychange'); },
	'script': function () { doc.fire('load', true); },
	'wc-throws': function () { inp.throwing = true; },
	'wc-set': function (b) { win.wc_order_attribution.setOrderTracking(b === 'undefined' ? undefined : b); }
};
try {
	(inp.steps || []).forEach(function (st) {
		var n = Array.isArray(st) ? st[0] : st;
		if (!act[n]) { throw new Error('unknown step ' + n); }
		act[n](Array.isArray(st) ? st[1] : undefined);
		out.log.push(n + ':' + JSON.stringify(out.calls));
	});
} catch (e) { out.error = String(e && e.message || e); }
out.forms = {};
els.forEach(function (el) { out.forms[el.form] = el.input ? el.input.value : null; });
out.form = out.forms.checkout;
out.groups = els.length;
out.cookies = Object.keys(jar).sort();
out.source = get('sbjs_current');
out.session = get('sbjs_session');
out.listeners = doc.count('load');
out.allow = win.wc_order_attribution && win.wc_order_attribution.params ? !!win.wc_order_attribution.params.allowTracking : null;
process.stdout.write(JSON.stringify(out));
