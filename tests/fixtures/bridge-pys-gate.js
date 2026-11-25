/*
 * Lot A, Task 2: runs the PixelYourSite gate on a fake page.
 * argv[2]: JSON { gate, options (pysOptions, absent = none), cmp: null | [slugs],
 *   data: { mode }, freeze: '' | 'gdpr' | 'all' | 'window', steps: [
 *     [ 'allow', [slugs] ] | [ 'gdpr', {…} ] (Object.assign, as public.js does with
 *     the REST reply) | [ 'cookie', {…} ] (options.cookie = …) |
 *     [ 'late', {…} ] (a later global script: var pysOptions = …) | [ 'again' ] (the gate once more) ] }
 * Prints JSON: the pysOptions seen by public.js after the gate and after each step.
 */
'use strict';
var vm = require('vm');
var inp = JSON.parse(process.argv[2]);
var allowed = inp.cmp || [];
var win = {};
win.window = win;
var ctx = vm.createContext(win);
function seen() { return vm.runInContext('typeof pysOptions === "undefined" ? undefined : pysOptions', ctx); }
if (inp.options !== undefined && inp.options !== null) { vm.runInContext('var pysOptions = ' + JSON.stringify(inp.options) + ';', ctx); }
if (inp.freeze === 'gdpr') { vm.runInContext('Object.freeze(pysOptions.gdpr)', ctx); }
if (inp.freeze === 'all') { vm.runInContext('Object.freeze(pysOptions); Object.freeze(pysOptions.gdpr)', ctx); }
// CMP_DATA as the banner publishes it: which category grants which Consent Mode signal.
win.CMP_DATA = Object.assign({ mode: 'pro', categories: inp.categories || [
	{ slug: 'necessary', required: true, gtag_signals: [] },
	{ slug: 'statistics', gtag_signals: ['analytics_storage'] },
	{ slug: 'marketing', gtag_signals: ['ad_storage', 'ad_user_data', 'ad_personalization'] }
] }, inp.data || {});
function cmp() { return { isAllowed: function (s) { return s === 'necessary' || allowed.indexOf(s) !== -1; } }; }
if (inp.cmp !== null && !inp.lateCmp) { win.CMP = cmp(); }
if (inp.throwing) { win.CMP = { isAllowed: function () { throw new Error('x'); } }; }
var out = { error: '', states: [] };
function snap() { var o = seen(); out.states.push(o === undefined ? null : JSON.parse(JSON.stringify(o))); }
try {
	vm.runInContext(inp.gate, ctx);
	snap();
	(inp.steps || []).forEach(function (st) {
		if (st[0] === 'allow') { allowed = st[1]; win.CMP = win.CMP || cmp(); }
		else if (st[0] === 'gdpr') { Object.assign(seen().gdpr, st[1]); }
		else if (st[0] === 'cookie') { seen().cookie = st[1]; }
		else if (st[0] === 'late') { vm.runInContext('var pysOptions = ' + JSON.stringify(st[1]) + ';', ctx); }
		else if (st[0] === 'again') { vm.runInContext(inp.gate, ctx); }
		snap();
	});
} catch (e) { out.error = String(e && e.message || e); }
process.stdout.write(JSON.stringify(out));
