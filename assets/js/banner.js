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
		unblockScripts(accepted);
		document.dispatchEvent(new CustomEvent('cmp:consent', { detail: { categories: accepted, decision: decision } }));
		logConsent({ categories: accepted, decision: decision });
		hide();
	}

	function selectedFromUI() {
		// Required categories have no toggle in the DOM — always include them.
		var out = requiredSlugs();
		banner.querySelectorAll('[data-cmp-cat]').forEach(function (b) {
			if (b.checked && out.indexOf(b.value) === -1) { out.push(b.value); }
		});
		return out;
	}

	function show() {
		if (!banner) { return; }
		banner.hidden = false;
		document.body.classList.add('cmp-has-banner');
		trapFocus(true);
	}
	function hide() {
		if (!banner) { return; }
		banner.hidden = true;
		document.body.classList.remove('cmp-has-banner');
		closePrefs();
		trapFocus(false);
	}

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

	function modal() { return banner ? banner.querySelector('[data-cmp-modal]') : null; }
	function openPrefs() { var m = modal(); if (m) { m.classList.add('is-prefs-open'); } }
	function closePrefs() { var m = modal(); if (m) { m.classList.remove('is-prefs-open'); } }

	function bind() {
		if (!banner) { return; }
		banner.addEventListener('click', function (e) {
			var btn = e.target.closest('[data-cmp-action]');
			if (!btn) { return; }
			var action = btn.getAttribute('data-cmp-action');
			if (action === 'accept') { save(D.categories.map(function (c) { return c.slug; }), 'accepted_all'); }
			else if (action === 'reject') { save(requiredSlugs(), 'rejected_all'); }
			else if (action === 'prefs') { openPrefs(); }
			else if (action === 'back') { closePrefs(); }
			else if (action === 'save') {
				var sel = selectedFromUI();
				var all = D.categories.length;
				var reqCount = requiredSlugs().length;
				var decision = sel.length >= all ? 'accepted_all' : (sel.length <= reqCount ? 'rejected_all' : 'partial');
				save(sel, decision);
			}
		});
	}

	function initLight() {
		var bar = document.querySelector('[data-cmp-light]');
		if (!bar) { return; }
		try { if (localStorage.getItem('cmp_light_dismissed')) { return; } } catch (e) {}
		bar.hidden = false;
		var btn = bar.querySelector('[data-cmp-light-dismiss]');
		if (btn) {
			btn.addEventListener('click', function () {
				try { localStorage.setItem('cmp_light_dismissed', '1'); } catch (e) {}
				bar.hidden = true;
			});
		}
	}

	function unblockScripts(accepted) {
		var blocked = document.querySelectorAll('script[type="text/plain"][data-cmp-block]');
		blocked.forEach(function (old) {
			var cat = old.getAttribute('data-cmp-block');
			if (!cat || accepted.indexOf(cat) === -1) { return; }
			var s = document.createElement('script');
			var src = old.getAttribute('data-cmp-src');
			if (src) { s.src = src; } else { s.text = old.textContent; }
			if (old.hasAttribute('async')) { s.async = true; }
			if (old.hasAttribute('defer')) { s.defer = true; }
			if (old.id) { s.id = old.id; }
			var nonce = old.nonce || old.getAttribute('nonce');
			if (nonce) { s.setAttribute('nonce', nonce); }
			['crossorigin', 'integrity', 'referrerpolicy'].forEach(function (a) {
				if (old.hasAttribute(a)) { s.setAttribute(a, old.getAttribute(a)); }
			});
			old.parentNode.insertBefore(s, old.nextSibling);
			old.removeAttribute('data-cmp-block'); // deselect so it won't be re-injected
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
		openPreferences: function () { show(); openPrefs(); }
	};

	document.addEventListener('DOMContentLoaded', function () {
		if (D.mode === 'light') { initLight(); return; }
		bind();
		var c = readCookie();
		if (c && String(c.v) === String(D.version)) {
			unblockScripts(c.categories || []);
			return;
		}
		if (D.autoReject) {
			// Global Privacy Control / Do-Not-Track: record refusal without prompting.
			save(requiredSlugs(), 'rejected_all');
		} else {
			show();
		}
	});
})();
