/*
 * Maps gate, Task 2: pages as the browser runs them, each in its own global scope
 * (vm context: `var google`, class, for…of, … are the page's own). stdin: JSON, a
 * list of cases:
 *   { scripts: [ { js, live, attrs, async, src, next, parent } ], cookie, state,
 *     before, after, proxy, banner, data }
 * `scripts` are inline scripts run one after another with document.currentScript
 * set (live: false = none); `before` / `after` are page code; `after` leaves its
 * answer in the global `out` (a promise is awaited). Page code has tick(ms),
 * ready() (DOMContentLoaded), el(height, position), log, and the document below.
 * Prints a JSON list: { out, error, writes, created, reloads, logs }.
 */
'use strict';
var fs = require('fs'), path = require('path'), vm = require('vm');
var cases = JSON.parse(fs.readFileSync(0, 'utf8'));
var bannerJs = fs.readFileSync(path.join(__dirname, '../../assets/js/banner.js'), 'utf8');

function page(c) {
	var rec = { writes: [], created: [], reloads: 0, logs: [], errors: [] };
	var now = 0, queue = [], listeners = {};
	var ctx = vm.createContext({});
	vm.runInContext('var window = this, self = this;', ctx);
	var w = vm.runInContext('this', ctx);

	function El(tag) {
		this.nodeType = 1; this.tagName = tag.toUpperCase(); this.attrs = {}; this.childNodes = []; this.parentNode = null;
		this.listeners = {}; this.clientHeight = 0; this.css = { position: 'static', height: 'auto' }; this.textContent = ''; this.async = tag === 'script';
		var self = this, text = '';
		this.style = { get cssText() { return text; }, set cssText(v) { text = v; } };
		this.ownText = function () { return self.textContent; };
	}
	El.prototype = {
		get nextSibling() { var l = this.parentNode ? this.parentNode.childNodes : [], i = l.indexOf(this); return i === -1 || i + 1 >= l.length ? null : l[i + 1]; },
		get firstChild() { return this.childNodes[0] || null; },
		setAttribute: function (k, v) { if (!/^[a-z_:][\w:.-]*$/i.test(k)) { throw new Error('InvalidCharacterError'); } this.attrs[k] = String(v); if (k === 'async') { this.async = true; } },
		getAttribute: function (k) { return k in this.attrs ? this.attrs[k] : null; },
		hasAttribute: function (k) { return k in this.attrs; },
		appendChild: function (x) { if (!x || x.nodeType !== 1) { throw new TypeError('not a Node'); } x.parentNode = this; this.childNodes.push(x); added(x); return x; },
		insertBefore: function (x, ref) { x.parentNode = this; var i = ref ? this.childNodes.indexOf(ref) : -1; if (i === -1) { this.childNodes.push(x); } else { this.childNodes.splice(i, 0, x); } added(x); return x; },
		addEventListener: function (t, f) { (this.listeners[t] = this.listeners[t] || []).push(f); },
		fire: function (t) { var s = this; (this.listeners[t] || []).forEach(function (f) { f.call(s, { type: t }); }); },
		matches: function () { return true; }
	};
	function added(x) {
		if (x.tagName !== 'SCRIPT') { return; }
		rec.created.push({ attrs: x.attrs, async: x.async, text: x.text === undefined ? null : x.text, nonce: x.nonce === undefined ? null : x.nonce, after: x.parentNode.childNodes[x.parentNode.childNodes.indexOf(x) - 1] === d.currentScript && !!d.currentScript, parent: x.parentNode.tagName });
		if (x.text !== undefined) { vm.runInContext(x.text, ctx); }
	}
	var html = new El('html'), head = new El('head'), body = new El('body');
	html.childNodes = [head]; head.parentNode = html;
	function all(root, f, out) { root.childNodes.forEach(function (x) { if (f(x)) { out.push(x); } all(x, f, out); }); return out; }
	var d = {
		nodeType: 9, readyState: c.state || 'loading', cookie: c.cookie || '', currentScript: null, head: head, body: c.body === false ? null : body, documentElement: html,
		createElement: function (t) { return new El(t); },
		addEventListener: function (t, f) { (listeners[t] = listeners[t] || []).push(f); },
		dispatchEvent: function (e) { (listeners[e.type] || []).slice().forEach(function (f) { f(e); }); return true; },
		querySelector: function () { return null; },
		querySelectorAll: function (q) {
			if (q !== '[data-cmp-map] button') { return []; }
			return all(body, function (x) { return x.tagName === 'BUTTON' && x.parentNode.hasAttribute('data-cmp-map'); }, []);
		},
		write: function (s) {
			if (c.writeThrows) { throw new Error('InvalidStateError'); }
			rec.writes.push(s);
			// The parser puts the element in at once, unless the page replaced write().
			if (!c.writeNoop) { var n = new El('i'), l = d.currentScript.parentNode.childNodes; n.parentNode = d.currentScript.parentNode; l.splice(l.indexOf(d.currentScript) + 1, 0, n); }
		}
	};
	if (d.body) { html.childNodes.push(body); body.parentNode = html; }
	if (c.cookieThrows) { Object.defineProperty(d, 'cookie', { get: function () { throw new Error('SecurityError'); } }); }
	w.document = d;
	w.navigator = {};
	w.location = { href: 'https://x.test/', protocol: 'https:', reload: function () { rec.reloads++; } };
	w.console = { warn: function (m) { rec.logs.push('warn:' + m); }, error: function (m) { rec.logs.push('error:' + m); }, log: function () {} };
	w.atob = function (s) { return Buffer.from(s, 'base64').toString('latin1'); };
	w.CustomEvent = function (t, o) { this.type = t; this.detail = o && o.detail; };
	w.URLSearchParams = URLSearchParams;
	w.getComputedStyle = function (x) { return { position: x.style.position || x.css.position, height: x.css.height }; };
	w.addEventListener = function (t, f, o) { (listeners['w:' + t] = listeners['w:' + t] || []).push(f); };
	w.fireWindow = function (t) { (listeners['w:' + t] || []).forEach(function (f) { f({ type: t }); }); };
	w.setTimeout = function (f, ms) { queue.push({ at: now + (ms || 0), f: f }); return queue.length; };
	w.clearTimeout = function () {};
	w.tick = function (ms) {
		var until = now + ms, i;
		for (;;) {
			i = -1;
			queue.forEach(function (q, n) { if (q.at <= until && (i === -1 || q.at < queue[i].at)) { i = n; } });
			if (i === -1) { break; }
			now = Math.max(now, queue[i].at);
			var q = queue.splice(i, 1)[0];
			try { q.f(); } catch (e) { rec.errors.push(String(e.message || e)); }
		}
		now = until;
	};
	w.ready = function () { d.readyState = 'interactive'; d.dispatchEvent({ type: 'DOMContentLoaded' }); };
	w.el = function (height, position) { var x = new El('div'); x.clientHeight = height || 0; if (position) { x.css.position = position; } body.appendChild(x); return x; };
	w.log = function (m) { rec.logs.push(String(m)); };
	w.realm = { ours: function () { return w.__cmpMapsGate; } };
	// The stack of `new Error()`: two frames, as for a script the parser runs.
	w.fakeStack = c.stack === undefined ? 'Error\n    at https://x.test/:5:30\n    at https://x.test/:5:900' : c.stack;
	w.fakeLimit = c.stackLimit;
	vm.runInContext('(function (E, s, l) { function F(m) { var e = new E(m); Object.defineProperty(e, "stack", { value: s === null ? undefined : s }); return e; } F.prototype = E.prototype; if (l !== undefined) { F.stackTraceLimit = l; } Error = F; })(Error, fakeStack, fakeLimit);', ctx);
	if (c.proxy === false) { vm.runInContext('Proxy = undefined;', ctx); }
	if (c.banner) { w.CMP_DATA = c.data; vm.runInContext(bannerJs, ctx); }
	if (c.data && !c.banner) { w.CMP_DATA = c.data; }

	function run(code) { return vm.runInContext(code, ctx, { timeout: 8000 }); }
	var error = null;
	try {
		if (c.before) { run(c.before); }
		(c.scripts || []).forEach(function (s) {
			var host = s.parent === 'body' ? body : head, x = new El('script');
			x.async = !!s.async;
			if (s.src) { x.src = s.src; }
			if (s.nonce) { x.nonce = s.nonce; }
			x.attrs = Object.assign({ 'data-cmp-maps-gate': 'marketing' }, s.attrs || {});
			if (s.attrs === null) { x.attrs = {}; }
			host.childNodes.push(x); x.parentNode = host;
			if (s.next) { var nx = new El('div'); host.childNodes.push(nx); nx.parentNode = host; }
			d.currentScript = s.live === false ? null : x;
			run(s.js);
			d.currentScript = null;
		});
		if (c.after) { run(c.after); }
	} catch (e) { error = String(e && e.message || e); }
	return { w: w, rec: rec, error: function () { return error; }, set: function (e) { error = e; } };
}

(async function () {
	var out = [];
	for (var i = 0; i < cases.length; i++) {
		var p = page(cases[i]), o;
		try { o = await Promise.race([p.w.out, new Promise(function (r) { setTimeout(function () { r('PENDING'); }, 300); })]); } catch (e) { p.set('rejected: ' + (e && e.message || e)); }
		out.push({ out: o === undefined ? null : o, error: p.error(), writes: p.rec.writes, created: p.rec.created, reloads: p.rec.reloads, logs: p.rec.logs, errors: p.rec.errors });
	}
	process.stdout.write(JSON.stringify(out), function () { process.exit(0); });
})();
