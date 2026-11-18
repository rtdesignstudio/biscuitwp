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
		body.set('nonce', D.nonce || '');
		body.set('decision', payload.decision);
		body.set('categories', JSON.stringify(payload.categories));
		body.set('version', D.version);
		// keepalive: the request survives an immediate page reload (reloadOnDecision).
		fetch(D.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true }).catch(function () {});
	}

	// True while the banner shown on page load is still waiting for the first
	// decision; only that decision may trigger reloadOnDecision.
	var firstDecision = false;

	function save(accepted, decision) {
		var reload = firstDecision && !!D.reloadOnDecision;
		firstDecision = false;
		var data = { v: D.version, categories: accepted, decision: decision, ts: Math.floor(Date.now() / 1000) };
		writeCookie(data);
		pushConsent(accepted);
		unblockScripts(accepted);
		unblockEmbeds(accepted);
		showReopen();
		document.dispatchEvent(new CustomEvent('cmp:consent', { detail: { categories: accepted, decision: decision } }));
		logConsent({ categories: accepted, decision: decision });
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

	function show() {
		if (!banner) { return; }
		banner.hidden = false;
		document.body.classList.add('cmp-has-banner');
		hideReopen();
		trapFocus(true);
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
	}
	function openPrefs() { var m = modal(); if (m) { syncToggles(); m.classList.add('is-prefs-open'); } }
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
		if (D.autoReject) {
			// Global Privacy Control / Do-Not-Track: record refusal without prompting.
			save(requiredSlugs(), 'rejected_all');
		} else {
			firstDecision = true;
			show();
		}
	});
})();
