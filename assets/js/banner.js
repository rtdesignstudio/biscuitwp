(function () {
	'use strict';

	var D = window.CMP_DATA || { categories: [], version: '1', cookieName: 'cmp_consent' };
	var banner = document.querySelector('[data-cmp]');
	var reopenBtn = document.querySelector('[data-cmp-reopen]');
	function showReopen() { if (reopenBtn) { reopenBtn.hidden = false; } }
	function hideReopen() { if (reopenBtn) { reopenBtn.hidden = true; } }

	function readCookie() {
		var m = document.cookie.match('(?:^|; )' + D.cookieName + '=([^;]*)');
		if (!m) { return null; }
		try { return JSON.parse(decodeURIComponent(m[1])); } catch (e) { return null; }
	}

	function writeCookie(obj) {
		var v = encodeURIComponent(JSON.stringify(obj));
		var days = (D.cookieDays && D.cookieDays > 0) ? D.cookieDays : 180;
		var maxAge = 60 * 60 * 24 * days;
		document.cookie = D.cookieName + '=' + v + ';path=/;max-age=' + maxAge + ';SameSite=Lax';
	}

	// Consent ID: a random v4 UUID kept in the consent cookie and in the consent
	// log, so a visitor's choice can be found and demonstrated (GDPR / Law
	// 195/2024 art. 7(1)). It is not linked to any other data about the visitor.
	var UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
	function makeConsentId() {
		var b = [];
		if (window.crypto && window.crypto.getRandomValues) { b = Array.prototype.slice.call(window.crypto.getRandomValues(new Uint8Array(16))); }
		else { for (var i = 0; i < 16; i++) { b.push(Math.floor(Math.random() * 256)); } }
		b[6] = (b[6] & 0x0f) | 0x40;
		b[8] = (b[8] & 0x3f) | 0x80;
		var h = b.map(function (x) { return (x < 16 ? '0' : '') + x.toString(16); }).join('');
		return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
	}
	// The visitor's existing ID, else the (valid) fallback one from cmp_svc, else a new one.
	function consentIdFor(existing, fallback) {
		if (existing && typeof existing.id === 'string' && UUID_RE.test(existing.id)) { return existing.id; }
		if (typeof fallback === 'string' && UUID_RE.test(fallback)) { return fallback; }
		return makeConsentId();
	}

	// "Always load" (cmp_svc): the embed services the visitor chose to load every
	// time. Kept apart from the category consent, so clicking it neither closes the
	// banner nor counts as a refusal of the categories; save() never touches it.
	// `id` is a UUID made on the first click, used only to log when the consent
	// cookie has none.
	var SVC_COOKIE = 'cmp_svc';
	var SVC_ID_RE = /^[a-z0-9-]+$/;
	// Pure: the allowed service ids, [] unless the cookie is for this policy version.
	function svcAllowed(c, version) {
		if (!c || typeof c !== 'object' || String(c.v) !== String(version) || !Array.isArray(c.svc)) { return []; }
		return c.svc.filter(function (x, i, a) { return typeof x === 'string' && SVC_ID_RE.test(x) && a.indexOf(x) === i; });
	}
	// Pure: the cookie object with `id` switched on or off; keeps the cookie's own id.
	function withSvc(c, version, id, on) {
		var list = svcAllowed(c, version).filter(function (x) { return x !== id; });
		if (on) { list.push(id); }
		var out = { v: String(version), svc: list };
		if (c && typeof c.id === 'string' && UUID_RE.test(c.id)) { out.id = c.id; }
		return out;
	}
	// Pure: a cookie value (still URI-encoded) as an object, or null when it is
	// malformed (bad %-escape or not JSON). Never throws.
	function parseSvcCookie(raw) {
		try {
			var o = JSON.parse(decodeURIComponent(raw));
			return o && typeof o === 'object' ? o : null;
		} catch (e) { return null; }
	}
	// Pure: the log payload of an "always" decision. It names only the affected
	// service, so a withdraw row says what was withdrawn.
	function svcLogPayload(decision, id, categories, consentId) {
		return { id: consentId || '', categories: categories || [], decision: decision, services: [id] };
	}
	// Pure: fill the service name in a "%s" text, literally ($ in the name is safe).
	function fillName(tpl, name) { return String(tpl).split('%s').join(name); }
	function readSvc() {
		var m = document.cookie.match('(?:^|; )' + SVC_COOKIE + '=([^;]*)');
		if (!m) { return null; }
		return parseSvcCookie(m[1]);
	}
	function writeSvc(obj) {
		var v = encodeURIComponent(JSON.stringify(obj));
		var days = (D.cookieDays && D.cookieDays > 0) ? D.cookieDays : 180;
		var maxAge = 60 * 60 * 24 * days;
		document.cookie = SVC_COOKIE + '=' + v + ';path=/;max-age=' + maxAge + ';SameSite=Lax';
	}

	function requiredSlugs() {
		return D.categories.filter(function (c) { return c.required; }).map(function (c) { return c.slug; });
	}

	function pushConsent(accepted) {
		// Meta Pixel follows the marketing category, when it is already on the page:
		// "grant" on consent, "revoke" when it is withdrawn (stops it immediately).
		if (typeof window.fbq === 'function') {
			try { window.fbq('consent', accepted.indexOf('marketing') !== -1 ? 'grant' : 'revoke'); } catch (e) {}
		}
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
		body.set('decision', payload.decision);
		body.set('categories', JSON.stringify(payload.categories));
		body.set('version', D.version);
		body.set('consent_id', payload.id || '');
		if (payload.services) { body.set('services', JSON.stringify(payload.services)); }
		// keepalive: the request survives an immediate page reload (reloadOnDecision).
		fetch(D.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true }).catch(function () {});
	}

	// True while the banner shown on page load is still waiting for the first
	// decision; only that decision may trigger reloadOnDecision.
	var firstDecision = false;

	function save(accepted, decision) {
		var reload = firstDecision && !!D.reloadOnDecision;
		firstDecision = false;
		var data = { v: D.version, id: consentIdFor(readCookie(), (readSvc() || {}).id), categories: accepted, decision: decision, ts: Math.floor(Date.now() / 1000) };
		writeCookie(data);
		pushConsent(accepted);
		unblockScripts(accepted);
		unblockEmbeds(accepted);
		showReopen();
		document.dispatchEvent(new CustomEvent('cmp:consent', { detail: { categories: accepted, decision: decision } }));
		logConsent({ id: data.id, categories: accepted, decision: decision });
		hide();
		// Load-time animations ran behind the overlay; reload so they play visibly.
		if (reload) { window.location.reload(); }
	}

	function selectedFromUI() {
		// Required categories have no toggle in the DOM — always include them.
		var out = requiredSlugs();
		banner.querySelectorAll('[data-cmp-cat]').forEach(function (b) {
			if (b.checked && out.indexOf(b.value) === -1) { out.push(b.value); }
		});
		return out;
	}

	// On the policy pages the banner has no overlay (.cmp--no-overlay): no scroll
	// lock and no focus trap, so the visitor can read the policy before deciding.
	function blocking() { return !banner.classList.contains('cmp--no-overlay'); }
	function show() {
		if (!banner) { return; }
		banner.hidden = false;
		if (blocking()) { document.body.classList.add('cmp-has-banner'); }
		hideReopen();
		if (blocking()) { trapFocus(true); }
	}
	function hide() {
		if (!banner) { return; }
		banner.hidden = true;
		document.body.classList.remove('cmp-has-banner');
		closePrefs();
		trapFocus(false);
		if (readCookie()) { showReopen(); }
	}

	// --- Focus trap (WCAG 2.1 AA) ---
	var lastFocus = null;
	function focusable() {
		return banner.querySelectorAll('a[href], button:not([disabled]), input:not([disabled])');
	}
	function onKeydown(e) {
		if (e.key !== 'Tab') { return; }
		var f = focusable();
		if (!f.length) { return; }
		var first = f[0], last = f[f.length - 1];
		// Focus starts on the dialog itself (see trapFocus): Shift+Tab from there wraps to the end.
		if (e.shiftKey && (document.activeElement === first || document.activeElement === modal())) { e.preventDefault(); last.focus(); }
		else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
	}
	function trapFocus(on) {
		if (on) {
			lastFocus = document.activeElement;
			document.addEventListener('keydown', onKeydown);
			// Focus the dialog, not its first button: a programmatic focus on a button
			// shows the :focus-visible ring on open. The ring now appears only once
			// the visitor tabs into the buttons.
			var m = modal();
			if (m) { m.focus(); }
		} else {
			document.removeEventListener('keydown', onKeydown);
			if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
		}
	}

	function modal() { return banner ? banner.querySelector('[data-cmp-modal]') : null; }
	// Optional toggles reflect the saved choice for the current policy version,
	// and start OFF when there is none (pre-ticked boxes are not valid consent).
	function syncToggles() {
		var c = readCookie();
		var valid = c && String(c.v) === String(D.version);
		banner.querySelectorAll('[data-cmp-cat]').forEach(function (b) {
			b.checked = !!valid && (c.categories || []).indexOf(b.value) !== -1;
		});
		// Show the visitor their consent ID (to quote it in a request about their data).
		var idEl = banner.querySelector('[data-cmp-consent-id]');
		if (idEl) {
			var id = c && typeof c.id === 'string' ? c.id : '';
			idEl.hidden = !id;
			var code = idEl.querySelector('code');
			if (code) { code.textContent = id; }
		}
	}
	function openPrefs() { var m = modal(); if (m) { syncToggles(); renderAlways(); m.classList.add('is-prefs-open'); } }
	function closePrefs() { var m = modal(); if (m) { m.classList.remove('is-prefs-open'); } }

	function bind() {
		// Any "cookie settings" control reopens the preferences: the shortcode link,
		// [data-cmp-settings] / .cmp-settings-link, a menu item pointing to
		// #cmp-settings, or the optional floating button. Delegated, so links
		// rendered later (menus, page builders) work too.
		document.addEventListener('click', function (e) {
			var el = e.target.closest && e.target.closest('[data-cmp-settings], .cmp-settings-link, .cmp-cookie-settings-link, .cmp-reopen, a[href$="#cmp-settings"]');
			if (!el) { return; }
			e.preventDefault();
			window.CMP.openPreferences();
		});
		if (!banner) { return; }
		banner.addEventListener('click', function (e) {
			var rm = e.target.closest('[data-cmp-svc-remove]');
			if (rm) { withdrawService(rm.getAttribute('data-cmp-svc-remove')); return; }
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
			var enc = old.hasAttribute('data-cmp-enc');
			var src = old.getAttribute('data-cmp-src');
			var body = old.getAttribute('data-cmp-body');
			function dec(v) { try { return enc ? atob(v) : v; } catch (e) { return v; } }
			if (src) { s.src = dec(src); }
			else if (body) { s.text = dec(body); }
			else { s.text = old.textContent; }
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

	// Third-party embeds (YouTube, Vimeo, Maps): the server put the original iframe
	// in an inert <template> inside [data-cmp-embed]; it replaces the placeholder
	// once its category is accepted, or when the visitor clicks "load" on it.
	function loadEmbed(box) {
		var tpl = box && box.querySelector('template[data-cmp-embed-src]');
		if (!tpl || !tpl.content || !box.parentNode) { return; }
		box.parentNode.replaceChild(tpl.content.cloneNode(true), box);
	}
	function unblockEmbeds(accepted) {
		document.querySelectorAll('[data-cmp-embed]').forEach(function (box) {
			var cat = box.getAttribute('data-cmp-embed');
			if (cat && accepted.indexOf(cat) !== -1) { loadEmbed(box); }
		});
	}
	function bindEmbeds() {
		document.addEventListener('click', function (e) {
			var btn = e.target.closest && e.target.closest('[data-cmp-embed-load]');
			if (!btn) { return; }
			e.preventDefault();
			loadEmbed(btn.closest('[data-cmp-embed]'));
		});
		document.addEventListener('click', function (e) {
			var btn = e.target.closest && e.target.closest('[data-cmp-embed-always]');
			if (!btn || D.mode === 'light') { return; }
			e.preventDefault();
			allowService(btn.closest('[data-cmp-embed]'));
		});
	}

	// On page load: placeholders of an "always loaded" service load whatever their category.
	function unblockServices() {
		var allowed = svcAllowed(readSvc(), D.version);
		document.querySelectorAll('[data-cmp-embed][data-cmp-service]').forEach(function (box) {
			if (allowed.indexOf(box.getAttribute('data-cmp-service')) !== -1) { loadEmbed(box); }
		});
	}
	// The consent ID to log with: the consent cookie's, else the cmp_svc one.
	function svcLogId(svcCookie) {
		var c = readCookie();
		if (c && typeof c.id === 'string' && UUID_RE.test(c.id)) { return c.id; }
		return (svcCookie && svcCookie.id) || '';
	}
	function currentCategories() {
		var c = readCookie();
		return (c && String(c.v) === String(D.version) && Array.isArray(c.categories)) ? c.categories : [];
	}
	function logService(decision, id, cookie) {
		logConsent(svcLogPayload(decision, id, currentCategories(), svcLogId(cookie)));
	}
	// "Always load": load this embed and every other one of the same service, remember
	// the choice and log it. The banner and the category consent are left alone.
	function allowService(box) {
		var id = box && box.getAttribute('data-cmp-service');
		if (!id || !SVC_ID_RE.test(id)) { return; }
		var same = document.querySelectorAll('[data-cmp-embed][data-cmp-service="' + id + '"]');
		for (var i = 0; i < same.length; i++) { loadEmbed(same[i]); }
		var next = withSvc(readSvc(), D.version, id, true);
		if (!next.id) { next.id = makeConsentId(); }
		writeSvc(next);
		logService('service_allow', id, next);
		renderAlways();
	}
	// Withdrawing is as easy as granting. Embeds already loaded on this page stay
	// until the next load.
	function withdrawService(id) {
		if (!id || !SVC_ID_RE.test(id)) { return; }
		var name = serviceName(id);
		var rm = banner && banner.querySelector('[data-cmp-svc-remove="' + id + '"]');
		var btns = banner ? Array.prototype.slice.call(banner.querySelectorAll('[data-cmp-svc-remove]')) : [];
		var idx = rm ? btns.indexOf(rm) : -1;
		var next = withSvc(readSvc(), D.version, id, false);
		writeSvc(next);
		logService('service_withdraw', id, next);
		renderAlways();
		if (!banner) { return; }
		// The focused button was removed: keep the keyboard user inside the dialog.
		var left = banner.querySelectorAll('[data-cmp-svc-remove]');
		var target = left.length ? left[Math.min(Math.max(idx, 0), left.length - 1)] : null;
		if (!target) {
			var first = banner.querySelector('[data-cmp-view="prefs"] button:not([disabled]), [data-cmp-view="prefs"] a[href]');
			target = first || modal();
		}
		if (target && target.focus) { target.focus(); }
		var live = banner.querySelector('[data-cmp-always-live]');
		var row = banner.querySelector('[data-cmp-always-list]');
		if (live) {
			live.textContent = '';
			live.textContent = fillName((row && row.getAttribute('data-cmp-always-remove')) || '%s', name);
		}
	}
	function serviceName(id) {
		if (D.svcNames && typeof D.svcNames[id] === 'string' && D.svcNames[id]) { return D.svcNames[id]; }
		var el = document.querySelector('[data-cmp-service="' + id + '"][data-cmp-service-name]');
		return (el && el.getAttribute('data-cmp-service-name')) || id;
	}
	// The "Always loaded: YouTube ×" row in the preferences; hidden when empty.
	function renderAlways() {
		if (!banner) { return; }
		var row = banner.querySelector('[data-cmp-always-list]');
		var box = row && row.querySelector('[data-cmp-always-items]');
		if (!row || !box) { return; }
		var tpl = row.getAttribute('data-cmp-always-remove') || '%s';
		var ids = svcAllowed(readSvc(), D.version);
		while (box.firstChild) { box.removeChild(box.firstChild); }
		ids.forEach(function (id) {
			var name = serviceName(id);
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'cmp__always-item';
			b.setAttribute('data-cmp-svc-remove', id);
			b.setAttribute('aria-label', fillName(tpl, name));
			b.textContent = name + ' \u00d7';
			box.appendChild(b);
		});
		row.hidden = !ids.length;
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
		bindEmbeds();
		if (D.mode === 'light') {
			// Light has no preferences panel: drop the placeholders' settings link.
			document.querySelectorAll('[data-cmp-embed] [data-cmp-settings]').forEach(function (a) { a.parentNode.removeChild(a); });
			initLight();
			return;
		}
		bind();
		unblockServices();
		var c = readCookie();
		if (c && String(c.v) === String(D.version)) {
			// Replay the stored consent to Consent Mode before the scripts come back,
			// as save() does. Without it the signals stay on the `denied` defaults
			// printed in wp_head (the gtag stub there is data-cmp-keep, so it exists),
			// and a returning visitor who accepted statistics is measured as refused.
			pushConsent(c.categories || []);
			unblockScripts(c.categories || []);
			unblockEmbeds(c.categories || []);
			showReopen();
			return;
		}
		if (navigator.globalPrivacyControl === true) {
			// Global Privacy Control: record refusal without prompting. Read in the
			// browser, not from request headers in PHP: a cached page would otherwise
			// replay the first visitor's signal to everyone. DNT is ignored.
			save(requiredSlugs(), 'rejected_all');
		} else {
			firstDecision = true;
			show();
		}
	});
})();
