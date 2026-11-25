/* BiscuitWP: the gate for the Google Maps JavaScript API loader of a theme.
 *
 * Not a file of its own on the page: includes/modules/class-maps-gate.php prints
 * the minified copy inline, in the exact place of the theme's tag, with the config
 * in place of the one free name below. It runs before banner.js and without it.
 *
 * With consent (the same rule as CMP.isAllowed() in banner.js) the original tag
 * runs where it was. Without it, google.maps is a stand-in that takes any use, and
 * the element of every map gets a box with a button. An existing google.maps is
 * never touched, and a script that sets google.maps later is left alone.
 *
 * What the stand-in gives: number / default hint NaN (so every < > <= >= is false
 * and such loops end), string hint '', length 0, empty iteration, no "then". Map
 * callbacks are never called (idle, click, place_changed…). The services (Geocoder,
 * Directions, Places…) answer "UNKNOWN_ERROR" only to something the visitor just
 * did, so that a form does not wait for ever; at page load they stay silent and
 * their promises pending. importLibrary() resolves, and addDomListener() on a real
 * element listens to the DOM as before.
 * Limits: a loop that touches stand-in values is cut after a budget per task (then
 * they read undefined); one that only compares them by identity, or that never
 * touches them, cannot be. A value given to a DOM method that wants a real Node
 * throws, as any non-Node would. Without Proxy (very old browsers) google stays
 * undefined without consent.
 *
 * ES5 syntax plus Proxy / Reflect. Tests: tests/test-maps-gate-page.php. */
(function (w, d) {
	'use strict';
	// { n cookie, v policy version, c category, r required, s service ids or null,
	//   m mode, l language, t texts, k the original tag }
	var C = CMP_MAPS_GATE;
	var G, T, HERE = d.currentScript, MARK = 'data-cmp-map';
	// How deep this script runs: 2 positions (this function, the script) when the
	// parser runs it, more when a script put it in the page (its caller is there too:
	// Chrome, Firefox and Safari print one line:column per frame). Read here, not in
	// a function: that would be one more frame.
	var DEPTH = (String(new Error().stack).match(/:\d+:\d+\)?$/gm) || []).length;
	// The tag: a attributes, b inline body, r its part after Google's loader,
	// u callback, n nonce, w may be written, y async, m inline module. base64 of ASCII JSON.
	try { T = JSON.parse(w.atob(C.k)); } catch (e) { return; }
	// One run per tag, whatever runs this script again.
	G = w.__cmpMapsGate;
	if (!G || !G.done) { G = w.__cmpMapsGate = { done: {} }; }
	if (G.done[C.k]) { return; }
	G.done[C.k] = 1;

	// consent:begin
	// The answer of CMP.isAllowed(category) in banner.js, from the same cookie, for
	// one category; Light never allows. Kept in step by a test that runs both.
	function allowed() {
		var m, c, s, known;
		if (C.m === 'light') { return false; }
		if (C.r) { return true; }
		try {
			m = d.cookie.match('(?:^|; )' + C.n + '=([^;]*)');
			c = m && JSON.parse(decodeURIComponent(m[1]));
		} catch (e) { return false; }
		if (!c || typeof c !== 'object' || String(c.v) !== C.v || !Array.isArray(c.categories) || c.categories.indexOf(C.c) === -1) { return false; }
		// A service that appeared since the decision refuses the category.
		s = c.svc;
		if (!Array.isArray(C.s) || !s || typeof s !== 'object' || Array.isArray(s)) { return true; }
		known = Array.isArray(s[C.c]) ? s[C.c] : [];
		return C.s.every(function (id) { return known.indexOf(id) !== -1; });
	}
	// consent:end

	// The stand-in in place now (another gate of this page put it there).
	function ours() { return !!G.root && !!w.google && w.google.maps === G.root; }

	// Is this the script the parser runs right now? document.write anywhere else
	// wipes the page. A stack that says anything but "the parser" (or says nothing, or
	// is cut short) is a no. A script made in JS also has .async true unless it was
	// switched off; the parser's one is the last thing in <head> (no <body> yet) or <body>.
	function parserRuns() {
		var n = HERE;
		if (DEPTH !== 2 || Error.stackTraceLimit < 3 || d.readyState !== 'loading' || !n || !n.hasAttribute('data-cmp-maps-gate') || n.src || n.async || n.hasAttribute('defer')) { return false; }
		for (; n && n !== d.head && n !== d.body; n = n.parentNode) {
			if (n.nextSibling) { return false; }
		}
		return !!n && (n === d.body || !d.body);
	}
	function insert(attrs, text, nonce) {
		// An inline module waits for the page, as it did.
		if (T.m && d.readyState === 'loading') {
			d.addEventListener('DOMContentLoaded', function () { add(attrs, text, nonce); });
		} else { add(attrs, text, nonce); }
	}
	function add(attrs, text, nonce) {
		var s = d.createElement('script'), at = HERE && HERE.parentNode;
		attrs.forEach(function (a) { try { s.setAttribute(a[0], a[1] === null ? '' : a[1]); } catch (e) {} });
		if (nonce) { s.setAttribute('nonce', nonce); s.nonce = nonce; }
		if (!T.y) { s.async = false; }
		if (text !== null) { s.text = text; }
		if (at) { at.insertBefore(s, HERE.nextSibling); } else { (d.head || d.documentElement).appendChild(s); }
	}
	// The original tag, where it was: written while the parser is here (so it runs
	// before the next script of the page, a deferred one in its order), else created.
	function load(nonce) {
		var lt = String.fromCharCode(60), h = lt + 'script';
		function esc(v) { return String(v).replace(/&/g, '&amp;').replace(/"/g, '&quot;').split(lt).join('&lt;').replace(/>/g, '&gt;'); }
		if (T.w && parserRuns()) {
			T.a.concat(nonce ? [['nonce', nonce]] : []).forEach(function (a) { h += ' ' + a[0] + (a[1] === null ? '' : '="' + esc(a[1]) + '"'); });
			try { d.write(h + '>' + lt + '/script>'); } catch (e) {} // XHTML, Trusted Types
			// In the page at once; not there = write() threw, or the page replaced it.
			if (HERE.nextSibling) { return; }
		}
		insert(T.a, T.b, nonce);
	}

	// --- The box in the element of a map ---
	function css(el, text) { el.style.cssText = text.replace(/;/g, '!important;'); }
	function label() { return allowed() ? C.t.reload : C.t.load; }
	function box(el) {
		var b, p, t, k, cs, tall, size;
		if (!el || el.nodeType !== 1) { return; }
		for (k = el.firstChild; k; k = k.nextSibling) {
			if (k.nodeType === 1 && k.hasAttribute(MARK)) { return; }
		}
		cs = w.getComputedStyle(el);
		// Hidden now (a closed tab): the height it will have, when CSS says one.
		tall = el.clientHeight > 0 || (parseFloat(cs.height) > 0 && cs.height.indexOf('%') === -1);
		// Over the element, so its size stays; only one without a height gets one.
		size = tall ? 'position:absolute;top:0;right:0;bottom:0;left:0;' : 'position:relative;height:100%;min-height:160px;';
		if (tall && cs.position === 'static') { el.style.position = 'relative'; }
		b = d.createElement('div');
		b.setAttribute(MARK, '');
		b.lang = C.l;
		css(b, size + 'box-sizing:border-box;display:flex;flex-direction:column;align-items:center;gap:12px;margin:0;padding:16px;overflow:auto;border:1px solid #6b7280;background:#f3f4f6;color:#1f2937;font:400 15px/1.5 system-ui,sans-serif;text-align:center;pointer-events:auto;');
		p = d.createElement('p');
		p.textContent = C.t.text;
		css(p, 'margin:auto 0 ' + (C.t.load ? '0' : 'auto') + ';padding:0;max-width:40em;color:#1f2937;background:none;font:inherit;text-align:center;');
		b.appendChild(p);
		// No button where the category cannot be given: Light, a deleted category.
		if (C.t.load) {
			t = d.createElement('button');
			t.type = 'button';
			t.textContent = label();
			var look = 'box-sizing:border-box;display:block;width:auto;max-width:100%;min-height:44px;margin:0 0 auto;padding:10px 18px;border:2px solid #111827;border-radius:6px;background:#111827;color:#fff;font:600 15px/1.2 system-ui,sans-serif;letter-spacing:normal;text-transform:none;white-space:normal;cursor:pointer;opacity:1;';
			var ring = function (on) { css(t, look + (on ? 'outline:3px solid #1d4ed8;outline-offset:2px;' : '')); };
			ring(false);
			t.addEventListener('focus', function () {
				var on = true;
				try { on = t.matches(':focus-visible'); } catch (e) {}
				ring(on);
			});
			t.addEventListener('blur', function () { ring(false); });
			t.addEventListener('click', function () {
				var M = w.CMP;
				// Allowed meanwhile, or now by this click (the API needs the gesture).
				if (allowed() || (M && M.allowCategory && M.allowCategory(C.c))) { w.location.reload(); return; }
				if (M && M.openPreferences) { M.openPreferences(); } else { p.textContent = C.t.help; }
			});
			b.appendChild(t);
		}
		el.appendChild(b);
	}

	// --- The stand-in ---
	function standIn() {
		var ANY, INST, ROOT, touched = 0, timer = 0;
		// Methods of OverlayView / MVCObject: vendor code copies them with for…in.
		var BASE = {};
		var SPACES = /^(event|places|geometry|spherical|encoding|poly|drawing|visualization|marker|routes|maps3d|journeySharing|streetView)$/;
		// The services: a callback (the last argument), a promise, or both.
		var LATER = /^(geocode|route|get(DistanceMatrix|Panorama|Elevation\w+|MaxZoomAtLatLng|Details|PlacePredictions|QueryPredictions)|fetch[A-Z]\w*|searchBy\w+|searchNearby|(text|nearby)Search|findPlaceFrom\w+)$/;
		var none = function () {};
		// Answers that are not "another stand-in": no thenable, no DOM or jQuery object,
		// nothing to walk, nothing to take out of a list.
		var FIX = {
			then: undefined, window: undefined, jquery: undefined, nodeType: 0, nodeName: undefined, tagName: undefined,
			parentNode: null, parentElement: null, offsetParent: null, ownerDocument: null, caller: null, 'arguments': null,
			nextSibling: null, previousSibling: null, nextElementSibling: null, previousElementSibling: null,
			firstChild: null, lastChild: null, firstElementChild: null, lastElementChild: null,
			getPlace: none, getPlaces: none, // nothing chosen: what the visitor typed stays
			next: none, pop: none, shift: none, hasNext: none, moveNext: none, nextNode: none,
			name: '', length: 0,
			toString: function () { return ''; }, toLocaleString: function () { return ''; }, valueOf: function () { return NaN; },
			// JSON.stringify() passes the key, a call in the theme passes nothing.
			toJSON: function () { return arguments.length ? null : ANY; }
		};
		Object.setPrototypeOf(FIX, null);
		function prim(hint) { return hint === 'string' ? '' : NaN; }
		function iter() { return [][Symbol.iterator](); }
		function service() {
			var u = w.navigator.userActivation, cb = arguments[arguments.length - 1], e, p;
			if (!u || !u.isActive) { return new Promise(none); }
			e = new Error('UNKNOWN_ERROR');
			e.code = e.message;
			p = Promise.reject(e);
			if (typeof cb === 'function') {
				p['catch'](none); // answered through the callback
				w.setTimeout(function () { cb([], e.code); }, 0);
			}
			return p;
		}

		// Every touch counts, per task: an endless loop runs out of stand-ins.
		function spent(max) {
			if (!timer) { timer = w.setTimeout(function () { timer = touched = 0; }, 0); }
			return ++touched > max;
		}
		function value(t, k) {
			if (k === Symbol.toPrimitive) { return prim; }
			if (k === Symbol.iterator) { return iter; }
			if (typeof k === 'symbol') { return t[k]; }
			if (k in FIX) { return FIX[k]; }
			if (/^\d\d+$/.test(k)) { return undefined; }
			return LATER.test(k) ? service : ANY;
		}
		function result() { return spent(3e5) ? undefined : ANY; }
		function instance(newTarget) {
			var p = newTarget.prototype;
			return new Proxy(Object.create(p && /^(object|function)$/.test(typeof p) ? p : BASE), INST);
		}
		// A property that is really there (set by the theme, or on its prototypes) wins.
		function ownFirst(t, k, r) {
			if (spent(6e5)) { return undefined; }
			return k in t ? Reflect.get(t, k, r) : value(t, k);
		}
		INST = { get: ownFirst };
		// For functions: name, length, toString… are FIX's; prototype, call, apply stay.
		function fixFirst(t, k) {
			if (spent(6e5)) { return undefined; }
			return k in t && !(k in FIX) ? Reflect.get(t, k) : value(t, k);
		}
		// Any value: a call, a property, an item. One object, so nothing grows; what
		// is set on it is dropped (it would show on every other value).
		ANY = new Proxy(function () {}, {
			get: fixFirst,
			set: function () { return true; },
			apply: result,
			construct: function (t, a, n) { return instance(n); }
		});
		'setMap getMap getPanes getProjection draw onAdd onRemove addListener bindTo changed get notify set setValues unbind unbindAll'.split(' ').forEach(function (k) { BASE[k] = ANY; });
		['toString', 'toLocaleString'].forEach(function (k) { Object.defineProperty(BASE, k, { value: FIX[k], writable: true, configurable: true }); });

		// A class of the API. Its prototype is an object the theme can extend or patch.
		function cls(name, root) {
			var f = function () {}, map = root && (name === 'Map' || name === 'StreetViewPanorama'), proto = Object.create(BASE), C1;
			f.prototype = new Proxy(proto, { get: ownFirst });
			C1 = new Proxy(f, {
				get: fixFirst,
				apply: function (t, self, a) {
					if (map) { box(a[0]); }
					return result();
				},
				construct: function (t, a, n) {
					var el, o;
					if (/Element$/.test(name)) {
						// The API's own elements go into the page: a real, empty one.
						el = d.createElement('span');
						Object.setPrototypeOf(el, new Proxy(Object.getPrototypeOf(el), { get: function (x, k, r) { return k in x ? Reflect.get(x, k, r) : value(x, k); } }));
						// The ones a visitor sees or types in say why they are empty.
						if (/^((Basic)?PlaceAutocomplete|Map(3D)?)E/.test(name)) { css(el, 'display:block;'); box(el); }
						return el;
					}
					o = instance(n);
					if (map) {
						box(a[0]);
						Object.defineProperty(o, 'getDiv', { value: function () { return a[0]; }, writable: true, configurable: true });
					}
					return o;
				}
			});
			Object.defineProperty(proto, 'constructor', { value: C1, writable: true, configurable: true });
			return C1;
		}
		// google.maps and its namespaces: plain objects (themes test typeof "object").
		// Children are made once; past 500 names there are no new ones.
		function space(own, depth) {
			var kids = Object.create(null), size = 0;
			return new Proxy(own, {
				get: function (t, k) {
					var sub;
					if (spent(6e5)) { return undefined; }
					if (k in t) { return t[k]; }
					if (typeof k === 'symbol' || k in FIX) { return value(t, k); }
					if (kids[k]) { return kids[k]; }
					sub = depth < 2 && SPACES.test(k);
					if (size > 500 || !(sub || /^[A-Z]/.test(k))) { return value(t, k); }
					size++;
					return (kids[k] = sub ? space(k === 'event' ? EVENT : {}, depth + 1) : cls(k, !depth));
				},
				has: function (t, k) { return k in t || (typeof k === 'string' && !(k in FIX)); }
			});
		}
		function listen(once) {
			return function (el, type, fn, capture) {
				if (el && el.addEventListener) { el.addEventListener(type, fn, once ? { once: true, capture: !!capture } : !!capture); }
				return ANY;
			};
		}
		var EVENT = { addDomListener: listen(false), addDomListenerOnce: listen(true) };
		// version: loaders in bundles look at it before they add Google's script.
		ROOT = space({ version: '3.0', importLibrary: function () { return Promise.resolve(ROOT); } }, 0);
		return ROOT;
	}

	// The tag's callback: once, after DOM ready, waited for up to 10 s.
	function callback(path) {
		var left = 50;
		function run() {
			var self = w, f = w;
			path.split('.').forEach(function (k) { self = f; f = f === undefined || f === null ? f : f[k]; });
			// Not before there is a Map to draw (a live API that is still loading).
			if (typeof f === 'function' && w.google && w.google.maps && typeof w.google.maps.Map === 'function') {
				try { f.call(self); } catch (e) { w.setTimeout(function () { throw e; }, 0); }
			} else if (--left > 0) { w.setTimeout(run, 200); }
		}
		if (G.done['cb:' + path]) { return; }
		G.done['cb:' + path] = 1;
		if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', run); } else { w.setTimeout(run, 0); }
	}

	(function () {
		var nonce = (HERE && HERE.nonce) || T.n || '', mine = ours(), g = w.google;
		// Once the stand-in is there the real API waits for the next page.
		if (!mine && allowed()) { load(nonce); return; }
		// A live google.maps another script put there: left alone, the theme uses it.
		if (!mine && !(g && g.maps)) {
			if (typeof Proxy !== 'function' || typeof Reflect !== 'object') { return; }
			try {
				G.root = standIn();
				if (!g) { g = w.google = {}; }
				g.maps = G.root;
			} catch (e) { return; }
			d.addEventListener('cmp:consent', function () {
				[].forEach.call(d.querySelectorAll('[' + MARK + '] button'), function (b) { b.textContent = label(); });
			});
			if (!ours()) { return; }
		}
		// The theme's own code after Google's loader, in the same inline script.
		if (T.r) { insert(T.a, T.r, nonce); }
		if (T.u) { callback(T.u); }
	})();
})(window, document);
