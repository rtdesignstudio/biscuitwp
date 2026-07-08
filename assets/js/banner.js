(function () {
	'use strict';

	var D = window.CMP_DATA || { categories: [], version: '1', cookieName: 'cmp_consent' };
	var banner = document.querySelector('[data-cmp]');

	function readCookie() {
		var m = document.cookie.match('(?:^|; )' + D.cookieName + '=([^;]*)');
		if (!m) { return null; }
		try { return JSON.parse(decodeURIComponent(m[1])); } catch (e) { return null; }
	}

	function writeCookie(obj) {
		var v = encodeURIComponent(JSON.stringify(obj));
		var maxAge = 60 * 60 * 24 * 180; // 180 days
		document.cookie = D.cookieName + '=' + v + ';path=/;max-age=' + maxAge + ';SameSite=Lax';
	}

	function requiredSlugs() {
		return D.categories.filter(function (c) { return c.required; }).map(function (c) { return c.slug; });
	}

	function pushConsent(accepted) {
		if (typeof window.gtag !== 'function') { return; }
		var signals = {};
		D.categories.forEach(function (cat) {
			var granted = cat.required || accepted.indexOf(cat.slug) !== -1;
			(cat.gtag_signals || []).forEach(function (sig) {
				if (signals[sig] !== 'granted') { signals[sig] = granted ? 'granted' : 'denied'; }
			});
		});
		try { window.gtag('consent', 'update', signals); } catch (e) {}
	}

	function logConsent(payload) {
		if (!D.ajaxUrl) { return; }
		var body = new URLSearchParams();
		body.set('action', 'cmp_log_consent');
		body.set('nonce', D.nonce || '');
		body.set('decision', payload.decision);
		body.set('categories', JSON.stringify(payload.categories));
		body.set('version', D.version);
		fetch(D.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' }).catch(function () {});
	}

	function save(accepted, decision) {
		var data = { v: D.version, categories: accepted, decision: decision, ts: Math.floor(Date.now() / 1000) };
		writeCookie(data);
		pushConsent(accepted);
		document.dispatchEvent(new CustomEvent('cmp:consent', { detail: { categories: accepted, decision: decision } }));
		logConsent({ categories: accepted, decision: decision });
		hide();
	}

	function selectedFromUI() {
		var boxes = banner.querySelectorAll('[data-cmp-cat]');
		var out = [];
		boxes.forEach(function (b) { if (b.checked) { out.push(b.value); } });
		return out;
	}

	function show() { if (banner) { banner.hidden = false; trapFocus(true); } }
	function hide() { if (banner) { banner.hidden = true; trapFocus(false); } }

	// --- Focus trap (WCAG 2.1 AA) ---
	var lastFocus = null;
	function focusable() {
		return banner.querySelectorAll('button:not([disabled]), input:not([disabled])');
	}
	function onKeydown(e) {
		if (e.key !== 'Tab') { return; }
		var f = focusable();
		if (!f.length) { return; }
		var first = f[0], last = f[f.length - 1];
		if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
		else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
	}
	function trapFocus(on) {
		if (on) {
			lastFocus = document.activeElement;
			document.addEventListener('keydown', onKeydown);
			var f = focusable();
			if (f.length) { f[0].focus(); }
		} else {
			document.removeEventListener('keydown', onKeydown);
			if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
		}
	}

	function togglePrefs() {
		var prefs = banner.querySelector('[data-cmp-prefs]');
		if (!prefs) { return; }
		var saveBtn = banner.querySelector('[data-cmp-action="save"]');
		var open = prefs.hidden;
		prefs.hidden = !open;
		if (saveBtn) { saveBtn.hidden = !open; }
	}

	function bind() {
		if (!banner) { return; }
		banner.addEventListener('click', function (e) {
			var btn = e.target.closest('[data-cmp-action]');
			if (!btn) { return; }
			var action = btn.getAttribute('data-cmp-action');
			if (action === 'accept') { save(D.categories.map(function (c) { return c.slug; }), 'accepted_all'); }
			else if (action === 'reject') { save(requiredSlugs(), 'rejected_all'); }
			else if (action === 'prefs') { togglePrefs(); }
			else if (action === 'save') {
				var sel = selectedFromUI();
				var all = D.categories.length;
				var reqCount = requiredSlugs().length;
				var decision = sel.length >= all ? 'accepted_all' : (sel.length <= reqCount ? 'rejected_all' : 'partial');
				save(sel, decision);
			}
		});
	}

	// --- Public API ---
	window.CMP = {
		isAllowed: function (slug) {
			var c = readCookie();
			var cat = D.categories.filter(function (x) { return x.slug === slug; })[0];
			if (cat && cat.required) { return true; }
			if (!c || String(c.v) !== String(D.version)) { return false; }
			return (c.categories || []).indexOf(slug) !== -1;
		},
		getConsent: function () { return readCookie(); },
		openPreferences: function () { show(); togglePrefs(); }
	};

	document.addEventListener('DOMContentLoaded', function () {
		bind();
		var c = readCookie();
		if (!c || String(c.v) !== String(D.version)) { show(); }
	});
})();
