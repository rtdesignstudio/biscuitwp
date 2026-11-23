'use strict';
/* Runs site/assets/verifica.js on a tiny fake DOM, submits the form with a fixture as
   the server's answer and prints what the visitor would read, as JSON (for
   tests/test-site.php). innerHTML, outerHTML and insertAdjacentHTML throw here.

   node run.js <ro|en> <fixture name> <owner 1|0> [<checks: a number, or "fail">]
   The counter endpoint answers {"checks": N}, or 404 when no number is given; a JSON
   object instead of the number is sent as the whole answer.
   [<share: "" | "noclip">]: after the report, its "Copy" and "Save as PDF" buttons are
   clicked; "noclip" makes the clipboard refuse. */
var fs = require('fs');
var path = require('path');
var vm = require('vm');

var lang = process.argv[2] === 'en' ? 'en' : 'ro';
var fixture = JSON.parse(fs.readFileSync(path.join(__dirname, process.argv[3] + '.json'), 'utf8'));
var ownerTicked = process.argv[4] !== '0';
var counterArg = process.argv[5] || 'fail';
var counterFetches = 0;
var shareArg = process.argv[6] || '';
var copied = null, printCalls = 0, printState = null;

function Text(t) { this.nodeType = 3; this.data = String(t); this.parentNode = null; }
Object.defineProperty(Text.prototype, 'textContent', { get: function () { return this.data; } });

function El(tag) {
	this.nodeType = 1;
	this.tagName = String(tag).toUpperCase();
	this.childNodes = [];
	this.parentNode = null;
	this.attrs = {};
	this.className = '';
	this.hidden = false;
	this.disabled = false;
	this.value = '';
	this.checked = false;
	this.listeners = {};
}
['innerHTML', 'outerHTML'].forEach(function (k) {
	Object.defineProperty(El.prototype, k, {
		get: function () { throw new Error(k + ' read'); },
		set: function () { throw new Error(k + ' set'); }
	});
});
El.prototype.insertAdjacentHTML = function () { throw new Error('insertAdjacentHTML'); };
Object.defineProperty(El.prototype, 'firstChild', { get: function () { return this.childNodes[0] || null; } });
Object.defineProperty(El.prototype, 'textContent', {
	get: function () { return this.childNodes.map(function (c) { return c.textContent; }).join(''); },
	set: function (v) { this.childNodes = []; if (v !== '') { this.appendChild(new Text(v)); } }
});
El.prototype.appendChild = function (c) {
	if (c.parentNode) { c.parentNode.removeChild(c); }
	c.parentNode = this; this.childNodes.push(c); return c;
};
El.prototype.removeChild = function (c) {
	var i = this.childNodes.indexOf(c);
	if (i >= 0) { this.childNodes.splice(i, 1); c.parentNode = null; }
	return c;
};
El.prototype.setAttribute = function (k, v) {
	if (/^on/i.test(k)) { throw new Error('event attribute ' + k); }
	this.attrs[k] = String(v);
};
El.prototype.getAttribute = function (k) { return Object.prototype.hasOwnProperty.call(this.attrs, k) ? this.attrs[k] : null; };
El.prototype.removeAttribute = function (k) { delete this.attrs[k]; };
El.prototype.addEventListener = function (t, f) { (this.listeners[t] = this.listeners[t] || []).push(f); };
El.prototype.focus = function () { doc.activeElement = this; };
El.prototype.getContext = function () { return null; };
El.prototype.querySelector = function () { return null; };
El.prototype.querySelectorAll = function () { return []; };

var canvases = 0;
var doc = {
	documentElement: { lang: lang },
	activeElement: null,
	body: new El('body'),
	createElement: function (t) { if (t === 'canvas') { canvases++; } return new El(t); },
	createTextNode: function (t) { return new Text(t); },
	querySelector: function () { return null; },
	querySelectorAll: function () { return []; },
	getElementById: function (id) { return byId[id] || null; }
};
var byId = {};
['verifica-form', 'verifica-rezultat', 'verifica-url', 'verifica-buton', 'verifica-anuleaza', 'verifica-owner', 'verifica-owner-eroare', 'verifica-contor', 'verifica-gasim'].forEach(function (id) {
	byId[id] = new El(id === 'verifica-form' ? 'form' : (id === 'verifica-rezultat' ? 'div' : (id.indexOf('eroare') > 0 || id === 'verifica-contor' ? 'p' : 'input')));
	byId[id].id = id;
});
byId['verifica-form'].hidden = true;
byId['verifica-owner-eroare'].hidden = true;
byId['verifica-contor'].hidden = true;
byId['verifica-gasim'].hidden = true;
byId['verifica-url'].value = 'exemplu.md';
byId['verifica-owner'].checked = ownerTicked;

var fetches = 0, sentBody = null, errors = [];
var pending = 0;
function later(f) { pending++; setImmediate(function () { pending--; try { f(); } catch (e) { errors.push(String(e && e.stack || e)); } }); }
var winListeners = {};
var win = {
	addEventListener: function (t, f) { (winListeners[t] = winListeners[t] || []).push(f); },
	location: { origin: 'https://consent.rt.md' },
	navigator: { clipboard: { writeText: function (t) { if (shareArg === 'noclip') { return Promise.reject(new Error('denied')); } copied = t; return Promise.resolve(); } } },
	print: function () {
		printCalls++;
		(winListeners.beforeprint || []).forEach(function (f) { f(); });
		var det = all(byId['verifica-rezultat'], function (n) { return n.tagName === 'DETAILS'; });
		printState = { htmlClass: doc.documentElement.className || '', details: det.length, open: det.filter(function (d) { return d.getAttribute('open') !== null; }).length };
		(winListeners.afterprint || []).forEach(function (f) { f(); });
	},
	matchMedia: function () { return { matches: false }; },
	innerWidth: 1280, innerHeight: 800,
	requestAnimationFrame: function () { return 0; },
	setTimeout: function (f, ms) { if (ms < 1000) { later(f); } return 1; },
	clearTimeout: function () {},
	setInterval: function () { return 1; },
	clearInterval: function () {},
	AbortController: function () { this.signal = {}; this.abort = function () {}; },
	fetch: function (url, opt) {
		if (url === '/api/contor.php') {
			counterFetches++;
			if (counterArg === 'fail') { return Promise.resolve({ status: 404, json: function () { return Promise.reject(new Error('404')); } }); }
			var answer = counterArg.charAt(0) === '{' ? JSON.parse(counterArg) : { checks: Number(counterArg) };
			return Promise.resolve({ status: 200, json: function () { return Promise.resolve(answer); } });
		}
		fetches++;
		sentBody = JSON.parse(opt.body);
		return Promise.resolve({ status: 200, json: function () { return Promise.resolve(fixture); } });
	}
};
win.window = win;
var sandbox = { window: win, document: doc, URL: URL, console: console, Array: Array, String: String, JSON: JSON, Math: Math };
win.document = doc;

try {
	vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../../site/assets/verifica.js'), 'utf8'), sandbox);
	(byId['verifica-form'].listeners.submit || []).forEach(function (f) { f({ preventDefault: function () {} }); });
} catch (e) { errors.push(String(e && e.stack || e)); }

function dump(n, out) {
	if (n.nodeType === 3) { if (n.data.trim()) { out.push(n.data); } return; }
	if (n.childNodes.every(function (c) { return c.nodeType === 3; })) {
		if (n.textContent.trim()) { out.push(n.textContent); }
		return;
	}
	n.childNodes.forEach(function (c) { dump(c, out); });
}
function all(n, f, acc) {
	acc = acc || [];
	if (n.nodeType === 1) { if (f(n)) { acc.push(n); } n.childNodes.forEach(function (c) { all(c, f, acc); }); }
	return acc;
}

var clicked = false;
function finish() {
	if (pending) { setImmediate(finish); return; }
	if (!clicked) {
		clicked = true;
		var btn = function (cls) { return all(byId['verifica-rezultat'], function (n) { return n.tagName === 'BUTTON' && (' ' + n.className + ' ').indexOf(' ' + cls + ' ') >= 0; })[0]; };
		var c = btn('r-copy'), p = btn('r-print');
		try {
			if (c) { (c.listeners.click || []).forEach(function (f) { f({ preventDefault: function () {} }); }); }
			if (p) { (p.listeners.click || []).forEach(function (f) { f({ preventDefault: function () {} }); }); }
		} catch (e) { errors.push(String(e && e.stack || e)); }
		setImmediate(finish);
		return;
	}
	var out = byId['verifica-rezultat'];
	var lines = [];
	dump(out, lines);
	var facts = all(out, function (n) { return /(^| )r-facts( |$)/.test(n.className); }).map(function (ul) {
		return ul.childNodes.map(function (li) { return li.childNodes.map(function (c) { return c.textContent; }).join('|'); }).join(',');
	}).join(';');
	process.stdout.write(JSON.stringify({
		text: lines.join('\n'),
		facts: facts,
		hrefs: all(out, function (n) { return n.tagName === 'A'; }).map(function (a) { return a.getAttribute('href'); }),
		body: sentBody,
		fetches: fetches,
		counterFetches: counterFetches,
		buttons: all(byId['verifica-rezultat'], function (n) { return n.tagName === 'BUTTON'; }).map(function (b) { return b.textContent; }),
		copied: copied,
		shareStatus: (all(byId['verifica-rezultat'], function (n) { return / r-share-status/.test(' ' + n.className); })[0] || { textContent: null }).textContent,
		printCalls: printCalls,
		printState: printState,
		openAfter: all(byId['verifica-rezultat'], function (n) { return n.tagName === 'DETAILS' && n.getAttribute('open') !== null; }).length,
		htmlClassAfter: doc.documentElement.className || '',
		counter: { hidden: byId['verifica-contor'].hidden, text: byId['verifica-contor'].textContent },
		found: (function () {
			var g = byId['verifica-gasim'], lines = [];
			dump(g, lines);
			return {
				hidden: g.hidden,
				text: lines,
				widths: all(g, function (n) { return n.getAttribute('style') !== null; }).map(function (n) { return n.getAttribute('style'); })
			};
		}()),
		confetti: canvases,
		ownerError: byId['verifica-owner-eroare'].hidden ? '' : byId['verifica-owner-eroare'].textContent,
		ownerInvalid: byId['verifica-owner'].getAttribute('aria-invalid'),
		error: errors.join('\n')
	}));
}
setImmediate(finish);
