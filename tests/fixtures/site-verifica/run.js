'use strict';
/* Runs site/assets/verifica.js on a tiny fake DOM, submits the form with a fixture as
   the server's answer and prints what the visitor would read, as JSON (for
   tests/test-site.php). innerHTML, outerHTML and insertAdjacentHTML throw here.

   node run.js <ro|en> <fixture name> <owner 1|0> */
var fs = require('fs');
var path = require('path');
var vm = require('vm');

var lang = process.argv[2] === 'en' ? 'en' : 'ro';
var fixture = JSON.parse(fs.readFileSync(path.join(__dirname, process.argv[3] + '.json'), 'utf8'));
var ownerTicked = process.argv[4] !== '0';

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
['verifica-form', 'verifica-rezultat', 'verifica-url', 'verifica-buton', 'verifica-anuleaza', 'verifica-owner', 'verifica-owner-eroare'].forEach(function (id) {
	byId[id] = new El(id === 'verifica-form' ? 'form' : (id === 'verifica-rezultat' ? 'div' : (id.indexOf('eroare') > 0 ? 'p' : 'input')));
	byId[id].id = id;
});
byId['verifica-form'].hidden = true;
byId['verifica-owner-eroare'].hidden = true;
byId['verifica-url'].value = 'exemplu.md';
byId['verifica-owner'].checked = ownerTicked;

var fetches = 0, sentBody = null, errors = [];
var pending = 0;
function later(f) { pending++; setImmediate(function () { pending--; try { f(); } catch (e) { errors.push(String(e && e.stack || e)); } }); }
var win = {
	matchMedia: function () { return { matches: false }; },
	innerWidth: 1280, innerHeight: 800,
	requestAnimationFrame: function () { return 0; },
	setTimeout: function (f, ms) { if (ms < 1000) { later(f); } return 1; },
	clearTimeout: function () {},
	setInterval: function () { return 1; },
	clearInterval: function () {},
	AbortController: function () { this.signal = {}; this.abort = function () {}; },
	fetch: function (url, opt) {
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

function finish() {
	if (pending) { setImmediate(finish); return; }
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
		confetti: canvases,
		ownerError: byId['verifica-owner-eroare'].hidden ? '' : byId['verifica-owner-eroare'].textContent,
		ownerInvalid: byId['verifica-owner'].getAttribute('aria-invalid'),
		error: errors.join('\n')
	}));
}
setImmediate(finish);
