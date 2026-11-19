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

	// maxAge: optional override in seconds (the remaining lifetime on adoption).
	function writeCookie(obj, maxAge) {
		var v = encodeURIComponent(JSON.stringify(obj));
		var days = (D.cookieDays && D.cookieDays > 0) ? D.cookieDays : 180;
		if (!(typeof maxAge === 'number' && maxAge > 0)) { maxAge = 60 * 60 * 24 * days; }
		document.cookie = D.cookieName + '=' + v + cookieTail(maxAge, !!D.check);
	}

	// check:begin
	// Check mode (B3): the admin's "Verificare" screen loads a page in a hidden
	// same-origin frame with a cmp_check nonce; the server then publishes
	// CMP_DATA.check and the session check cookies. Pure helpers; tested in
	// standalone/tests/run.js (source-extracted). Standalone has no check mode.
	// The measuring runner is assets/js/banner-check.js, enqueued only in check
	// mode; it reaches this file through the check bridge (below).
	// The consent / "always load" cookie names from CMP_DATA; session = check mode.
	function cookieNames(d) {
		d = d || {};
		return {
			consent: (typeof d.cookieName === 'string' && d.cookieName) ? d.cookieName : 'cmp_consent',
			svc: (typeof d.svcCookie === 'string' && d.svcCookie) ? d.svcCookie : 'cmp_svc',
			session: !!d.check
		};
	}
	// The cookie attributes after the value: a session cookie has no max-age.
	function cookieTail(maxAge, session) {
		return ';path=/' + (session ? '' : ';max-age=' + maxAge) + ';SameSite=Lax';
	}
	// The cookies whose name starts with `prefix`, raw values, in order (first wins).
	function prefixCookies(str, prefix) {
		var out = [], seen = [];
		String(str || '').split(';').forEach(function (part) {
			var i = part.indexOf('=');
			if (i === -1) { return; }
			var name = part.slice(0, i).replace(/^\s+|\s+$/g, '');
			if (!name || name.indexOf(prefix) !== 0 || seen.indexOf(name) !== -1) { return; }
			seen.push(name);
			out.push({ name: name, value: part.slice(i + 1) });
		});
		return out;
	}
	// What to write to put the `prefix` cookies back as in `snap` (taken before
	// the first push): set = the originals that changed or went missing; del =
	// the ones that did not exist then.
	function restorePlan(snap, now, prefix) {
		var cur = prefixCookies(now, prefix), before = Array.isArray(snap) ? snap : [], set = [], del = [];
		before.forEach(function (c) {
			var hit = cur.filter(function (x) { return x.name === c.name; })[0];
			if (!hit || hit.value !== c.value) { set.push({ name: c.name, value: c.value }); }
		});
		cur.forEach(function (x) {
			if (!before.some(function (c) { return c.name === x.name; })) { del.push(x.name); }
		});
		return { set: set, del: del };
	}
	// Attributes of a restored persistent cookie (WP Consent API: `days` days, 30 by default).
	function persistTail(days, secure) {
		var d = Number(days) > 0 ? Number(days) : 30;
		return ';path=/;max-age=' + Math.round(d * 86400) + ';SameSite=Lax' + (secure ? ';secure' : '');
	}
	// The URL without the cmp_check parameter (the nonce must not reach trackers).
	function stripCheckParam(url) {
		var s = String(url), h = s.indexOf('#'), hash = h === -1 ? '' : s.slice(h);
		s = h === -1 ? s : s.slice(0, h);
		var q = s.indexOf('?');
		if (q === -1) { return s + hash; }
		var keep = s.slice(q + 1).split('&').filter(function (p) { return p !== '' && p.split('=')[0] !== 'cmp_check'; });
		return s.slice(0, q) + (keep.length ? '?' + keep.join('&') : '') + hash;
	}
	// check:end

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
	var SVC_COOKIE = cookieNames(D).svc;
	// Check mode: every checked page starts as a first visit and leaves nothing
	// behind. The check cookies are dropped here (the server expired them too)
	// and again at the end; never the real cookies.
	function clearCheckCookies() {
		[D.cookieName, SVC_COOKIE].forEach(function (n) {
			if (typeof n === 'string' && n.indexOf('cmp_check_') === 0) { document.cookie = n + '=' + cookieTail(0, true) + ';max-age=0'; }
		});
	}
	function wpPrefix() {
		var api = window.consent_api;
		return (api && typeof api.cookie_prefix === 'string' && api.cookie_prefix) ? api.cookie_prefix : 'wp_consent';
	}
	// End of a checked page (after the measurement, an early stop, or pagehide):
	// the admin's WP Consent API cookies go back to their values before the first
	// push (the pushes stay, so consent-aware plugins are measured), and the
	// check cookies are removed. Idempotent.
	function endCheck() {
		if (!D.check || !wpSnap) { return; }
		var plan = restorePlan(wpSnap, document.cookie, wpPrefix());
		var api = window.consent_api, days = api ? (api.cookie_expiration || api.cookie_expire) : 0, secure = location.protocol === 'https:';
		plan.set.forEach(function (c) { document.cookie = c.name + '=' + c.value + persistTail(days, secure); });
		plan.del.forEach(function (n) { document.cookie = n + '=;path=/;max-age=0'; });
		clearCheckCookies();
	}
	if (D.check) {
		clearCheckCookies();
		// Before pushWpInit (DOMContentLoaded): the admin's own values.
		var wpSnap = prefixCookies(document.cookie, wpPrefix());
		try { if (window.history && history.replaceState) { history.replaceState(history.state, '', stripCheckParam(location.href)); } } catch (e) {}
		window.addEventListener('pagehide', endCheck);
		// The bridge for assets/js/banner-check.js (check mode only). accept: the
		// same path as the visitor's click (delegated handler -> save()); none in
		// Light (it stores no consent). hasBanner: the accept button, or the Light card.
		window.CMP_CHECK_API = { accept: D.mode === 'light' ? null : function () { var a = banner ? banner.querySelector('[data-cmp-action="accept"]') : null; if (a) { a.click(); } }, endCheck: endCheck, hasBanner: function () { return D.mode === 'light' ? !!document.querySelector('[data-cmp-light]') : !!(banner && banner.querySelector('[data-cmp-action="accept"]')); } };
	}
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
	// svcAfterReject:begin
	// Pure: the cmp_svc value after an explicit "reject": no service left, same
	// policy version, the cookie's own id kept. Tested in standalone/tests/run.js.
	function svcAfterReject(c, version) {
		var out = { v: String(version), svc: [] };
		if (c && typeof c === 'object' && typeof c.id === 'string' && UUID_RE.test(c.id)) { out.id = c.id; }
		return out;
	}
	// svcAfterReject:end
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
		document.cookie = SVC_COOKIE + '=' + v + cookieTail(maxAge, !!D.check);
	}

	function requiredSlugs() {
		return D.categories.filter(function (c) { return c.required; }).map(function (c) { return c.slug; });
	}

	// wpConsentValues:begin
	// WP Consent API values: map = { cmpSlug: [wpCategory, ...] }. 'functional' is
	// always allowed; a WP category is 'allow' if any mapped slug is accepted.
	// Pure; tested in standalone/tests/run.js (source-extracted from this block).
	function wpConsentValues(map, accepted) {
		var out = {};
		if (!map) { return out; }
		Object.keys(map).forEach(function (slug) {
			var on = (accepted || []).indexOf(slug) !== -1;
			(map[slug] || []).forEach(function (wp) {
				if (wp === 'functional' || on) { out[wp] = 'allow'; }
				else if (out[wp] !== 'allow') { out[wp] = 'deny'; }
			});
		});
		return out;
	}
	// What to send on every page view. Without a valid CMP decision (no cookie,
	// an expired or malformed one, another policy version, or Light mode) every
	// mapped category is pushed: 'functional' allow, the rest deny, so stale
	// 'allow' values in WP Consent API's own cookies never survive. With a valid
	// decision, its categories.
	function wpInitValues(map, cookie, version, mode, required) {
		var valid = mode !== 'light' && !!cookie && typeof cookie === 'object' &&
			String(cookie.v) === String(version) && Array.isArray(cookie.categories);
		return wpConsentValues(map, valid ? cookie.categories : (required || []));
	}
	// wpConsentValues:end

	// reconsent:begin
	// Re-consent helpers. svcByCat = { slug: [serviceIds] } as published by the
	// server; a missing / non-object map means "unknown" (no scan has run yet).
	// Pure; tested in standalone/tests/run.js (source-extracted in banner.js).
	function rcMap(m) {
		return (m && typeof m === 'object' && !Array.isArray(m)) ? m : null;
	}
	function rcList(v) { return Array.isArray(v) ? v : []; }
	// Snapshot of the services per accepted category; null = unknown, store none.
	function svcSnapshot(accepted, svcByCat) {
		var map = rcMap(svcByCat), out = {}, acc = rcList(accepted);
		if (!map) { return null; }
		Object.keys(map).forEach(function (slug) {
			if (acc.indexOf(slug) !== -1 && Array.isArray(map[slug])) { out[slug] = map[slug].slice(); }
		});
		return out;
	}
	// Services that appeared since the cookie was saved, in accepted categories.
	function newServices(cookie, svcByCat) {
		var map = rcMap(svcByCat), out = {};
		if (!map || !cookie || typeof cookie !== 'object') { return out; }
		var svc = cookie.svc;
		if (!svc || typeof svc !== 'object' || Array.isArray(svc)) { return out; }
		var cats = rcList(cookie.categories);
		Object.keys(map).forEach(function (slug) {
			if (cats.indexOf(slug) === -1 || !Array.isArray(map[slug])) { return; }
			var known = rcList(svc[slug]);
			var fresh = map[slug].filter(function (id) { return known.indexOf(id) === -1; });
			if (fresh.length) { out[slug] = fresh; }
		});
		return out;
	}
	// The cookie's categories minus those that gained a service. A slug in
	// `required` (always-on categories) is never removed, even by an old map.
	function effectiveCategories(cookie, svcByCat, required) {
		if (!cookie || typeof cookie !== 'object') { return []; }
		var fresh = newServices(cookie, svcByCat), req = rcList(required);
		return rcList(cookie.categories).filter(function (s) { return req.indexOf(s) !== -1 || !Object.prototype.hasOwnProperty.call(fresh, s); });
	}
	// Silent baseline adoption: a cookie without `svc` (saved before the map was
	// known, e.g. by 0.4.x) gets the snapshot of its own categories, so later
	// additions re-prompt. Categories, decision, id and ts are kept; no prompt,
	// no log. Returns the cookie to write, or null when there is nothing to write.
	function backfillSvc(cookie, svcByCat) {
		if (!cookie || typeof cookie !== 'object' || Array.isArray(cookie) || !Array.isArray(cookie.categories)) { return null; }
		if (Object.prototype.hasOwnProperty.call(cookie, 'svc')) { return null; }
		var snap = svcSnapshot(cookie.categories, svcByCat);
		if (snap === null) { return null; }
		var out = {};
		Object.keys(cookie).forEach(function (k) { out[k] = cookie[k]; });
		out.svc = snap;
		return out;
	}
	// Seconds left of a stored decision's lifetime (cookieDays from its ts), for
	// rewriting the cookie without restarting it. 0 = skip the rewrite: ts
	// missing, invalid or in the future, or the lifetime is used up.
	function remainingMaxAge(cookie, cookieDays, nowSec) {
		if (!cookie || typeof cookie !== 'object') { return 0; }
		var ts = cookie.ts, days = (typeof cookieDays === 'number' && cookieDays > 0) ? cookieDays : 180;
		if (typeof ts !== 'number' || !isFinite(ts) || ts <= 0 || typeof nowSec !== 'number' || ts > nowSec) { return 0; }
		var left = Math.floor(days * 86400 - (nowSec - ts));
		return left > 0 ? left : 0;
	}
	// The snapshot's ids as one list (no duplicates), for the consent log.
	function svcFlat(snap) {
		var out = [], map = rcMap(snap);
		if (!map) { return out; }
		Object.keys(map).forEach(function (slug) {
			rcList(map[slug]).forEach(function (id) {
				if (typeof id === 'string' && out.indexOf(id) === -1) { out.push(id); }
			});
		});
		return out;
	}
	// What the re-consent banner shows: show = a category the visitor accepted
	// gained a service; newIds = those services per category; preselect = the
	// toggles to pre-check (the effective categories: the affected ones stay
	// off until the visitor confirms them). No cookie / old cookie / unknown
	// map -> show false (the normal banner, or nothing, handles it). Required
	// categories are never affected.
	function reconsentView(cookie, svcByCat, required) {
		var all = newServices(cookie, svcByCat), req = rcList(required), fresh = {};
		Object.keys(all).forEach(function (slug) { if (req.indexOf(slug) === -1) { fresh[slug] = all[slug]; } });
		return { show: Object.keys(fresh).length > 0, newIds: fresh, preselect: effectiveCategories(cookie, svcByCat, req) };
	}
	// reconsent:end

	// The visitor's categories for the current policy version, minus those that
	// gained a service since the decision: such a category counts as refused
	// everywhere (scripts, embeds, Consent Mode, WP Consent API, the public API)
	// until the visitor decides again. The cookie is rewritten only then.
	function consentedCategories() {
		var c = readCookie();
		return (c && String(c.v) === String(D.version)) ? effectiveCategories(c, D.svcByCat, requiredSlugs()) : [];
	}
	// True when the stored decision (this policy version) has a category that is
	// currently refused because it gained a service.
	function pendingAffected() {
		var c = readCookie();
		if (!c || String(c.v) !== String(D.version)) { return false; }
		return Object.keys(reconsentView(c, D.svcByCat, requiredSlugs()).newIds).length > 0;
	}

	function pushWpConsent(accepted) {
		if (!D.wpConsent || typeof window.wp_set_consent !== 'function') { return; }
		var vals = wpConsentValues(D.wpConsent, accepted);
		Object.keys(vals).forEach(function (cat) {
			try { window.wp_set_consent(cat, vals[cat]); } catch (e) {}
		});
	}

	function pushWpInit() {
		if (!D.wpConsent || typeof window.wp_set_consent !== 'function') { return; }
		// B4: while a category is affected by re-consent, push the effective
		// categories (it is denied until the visitor decides again); otherwise the
		// usual init values. Computed once.
		var affected = D.mode !== 'light' && pendingAffected();
		var vals = affected ? wpConsentValues(D.wpConsent, consentedCategories()) : wpInitValues(D.wpConsent, readCookie(), D.version, D.mode, requiredSlugs());
		Object.keys(vals).forEach(function (cat) {
			try { window.wp_set_consent(cat, vals[cat]); } catch (e) {}
		});
	}

	function pushConsent(accepted) {
		pushWpConsent(accepted);
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
		if (!D.ajaxUrl || D.check) { return; }
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
	// The onConsent / whenAllowed listeners (B7.4), told by notify().
	var apiSubs = subscribers();
	// A stored decision for the current policy version.
	function decided() {
		var c = readCookie();
		return !!c && String(c.v) === String(D.version) && Array.isArray(c.categories);
	}
	// The one place the API listeners hear of a change: every save (banner, panel,
	// GPC, the API itself) and the "Always load" choices, once there is a decision.
	function notify() { if (decided()) { apiSubs.emit(window.CMP.getConsent()); } }

	function save(accepted, decision) {
		var reload = firstDecision && !!D.reloadOnDecision && !D.check;
		firstDecision = false;
		// B4: the services of the accepted categories at this decision, so a service
		// added later can be detected. None when the map is unknown (no scan yet).
		var snap = svcSnapshot(accepted, D.svcByCat);
		var data = { v: D.version, id: consentIdFor(readCookie(), (readSvc() || {}).id), categories: accepted, decision: decision, ts: Math.floor(Date.now() / 1000) };
		if (snap !== null) { data.svc = snap; }
		writeCookie(data);
		pushConsent(accepted);
		unblockScripts(accepted);
		unblockEmbeds(accepted);
		showReopen();
		document.dispatchEvent(new CustomEvent('cmp:consent', { detail: { categories: accepted, decision: decision } }));
		logConsent({ id: data.id, categories: accepted, decision: decision, services: snap === null ? null : svcFlat(snap) });
		// After the log, so a save made by a listener is logged after this one.
		notify();
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
	// The Details button on the main view (phones).
	function details(on) { var main = banner.querySelector('[data-cmp-view="main"]'); return main ? toggleDetails(main, main.querySelector('[data-cmp-action="details"]'), on) : false; }
	// No overlay (policy pages): the page's own scrolling (anchors, focus) stops
	// short of the band: scroll-padding on <html> = the part of the screen it
	// covers, at the bottom (or the top). Restored when hidden; never when blocking.
	var padSide = '', padPrev = '';
	function scrollPad() {
		var s = document.documentElement.style, m = modal(), r;
		// Only the side it set, back to the page's own value; the rest is never touched.
		if (padSide) { s[padSide] = padPrev; padSide = ''; }
		if (!m || banner.hidden || blocking()) { return; }
		r = m.getBoundingClientRect();
		padSide = r.top + r.bottom < window.innerHeight ? 'scrollPaddingTop' : 'scrollPaddingBottom';
		padPrev = s[padSide];
		s[padSide] = Math.ceil(padSide === 'scrollPaddingTop' ? r.bottom : window.innerHeight - r.top) + 'px';
	}
	function show() {
		if (!banner) { return; }
		var wasHidden = banner.hidden;
		banner.hidden = false;
		if (blocking()) {
			// The scrollbar goes away under the lock: the body takes its width as extra
			// padding, so the page does not shift (CLS). Measured only when it appears.
			if (wasHidden) { var de = document.documentElement, cs = getComputedStyle(de); de.style.setProperty('--cmp-sbw', lockPad(window.innerWidth, de.clientWidth, cs.overflowY, cs.scrollbarGutter, getComputedStyle(document.body).paddingRight)); }
			document.body.classList.add('cmp-has-banner');
		}
		hideReopen();
		// Escape works on every banner; the Tab trap only where it blocks the page.
		document.addEventListener('keydown', onKeydown);
		window.addEventListener('resize', scrollPad);
		scrollPad();
		// Trap (and remember the focus to restore) only when it appears; already on
		// screen (openPreferences while it shows), just focus the view.
		if (blocking() && wasHidden) { trapFocus(true); } else if (!wasHidden || prefsOpen()) { focusDialog(); }
	}
	function hide() {
		if (!banner) { return; }
		// Focus moves only when the banner was on screen and held it: never on the
		// GPC auto-refusal, nor off the page on a policy page read without overlay.
		var refocus = !banner.hidden && (blocking() || banner.contains(document.activeElement));
		banner.hidden = true;
		document.body.classList.remove('cmp-has-banner');
		document.documentElement.style.removeProperty('--cmp-sbw');
		window.removeEventListener('resize', scrollPad);
		details(false);
		closePrefs();
		scrollPad();
		document.removeEventListener('keydown', onKeydown);
		// The reopen button first, so focus can land on it (see focusBack).
		if (readCookie()) { showReopen(); }
		if (refocus) { trapFocus(false); }
	}

	// --- Focus trap (WCAG 2.1 AA) ---
	var lastFocus = null;
	// The controls of the view on screen (main or prefs) that are rendered: the
	// other view is display:none, and so are hidden rows / buttons.
	function focusable() {
		var m = modal();
		var scope = banner.querySelector(m && m.classList.contains('is-prefs-open') ? '[data-cmp-view="prefs"]' : '[data-cmp-view="main"]') || banner;
		return Array.prototype.filter.call(scope.querySelectorAll('a[href], button:not([disabled]), input:not([disabled])'), function (el) {
			// Not a control hidden by visibility (the policy link in the clamped phone band).
			return el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden';
		});
	}
	// focusTrap:begin
	// Pure: where Tab / Shift+Tab must go to stay in the dialog, or null for the
	// browser default. Focus starts on the dialog itself (see trapFocus):
	// Shift+Tab from there wraps to the end; focus outside the list comes back.
	// Tested in standalone/tests/run.js (source-extracted).
	function trapStep(list, active, shift, dialog) {
		if (!list.length) { return null; }
		var first = list[0], last = list[list.length - 1], inside = list.indexOf(active) !== -1;
		if (shift) { return (active === first || active === dialog || !inside) ? last : null; }
		return (active === last || (!inside && active !== dialog)) ? first : null;
	}
	// Pure: where focus goes when the banner closes: the element focused before
	// it opened while still usable, else the reopen button (shown first by hide()),
	// else nothing. Tested in standalone/tests/run.js (source-extracted).
	function focusBack(last, reopen, usable) {
		if (last && usable(last)) { return last; }
		return reopen && usable(reopen) ? reopen : null;
	}
	// focusTrap:end
	// keys:begin
	// Pure: what Escape does. In the settings panel it goes back to the main
	// view, like the Back button; anywhere else it does nothing. Not while
	// re-consent hides Back; without the overlay, only with focus inside the
	// banner. It never saves a choice, writes the cookie or closes the banner.
	// Tested in standalone/tests/run.js (source-extracted).
	function escapeAction(prefsOpen, backHidden, blocking, focusInside) {
		return prefsOpen && !backHidden && (blocking || focusInside === true) ? 'back' : null;
	}
	// keys:end
	// band:begin
	// Pure DOM helper: "Details" on phones expands / collapses the intro in the
	// band (.is-expanded on the main view, aria-expanded on the button). No
	// `expanded` = flip. Returns the new state. Tested in standalone/tests/run.js
	// (source-extracted, identical in both files).
	function toggleDetails(main, btn, expanded) {
		var on = typeof expanded === 'boolean' ? expanded : !main.classList.contains('is-expanded');
		if (on) { main.classList.add('is-expanded'); } else { main.classList.remove('is-expanded'); }
		if (btn) { btn.setAttribute('aria-expanded', on ? 'true' : 'false'); }
		return on;
	}
	// Pure: the body's padding-right under the scroll lock = its own, plus the
	// scrollbar width when the lock removes a classic scrollbar (no shift, CLS).
	// None with overlay scrollbars, html overflow-y: scroll or a stable gutter.
	function lockPad(innerW, clientW, overflowY, gutter, padRight) {
		var gap = overflowY === 'scroll' || /stable/.test(gutter || '') ? 0 : Math.max(innerW - clientW, 0);
		return ((parseFloat(padRight) || 0) + gap) + 'px';
	}
	// band:end
	// api:begin
	// Public API (B7.4), identical in banner.js and standalone/cmp.js; tested in
	// standalone/tests/run.js (source-extracted). Pure: each build passes its hooks.
	// A real visitor gesture: the User Activation API where the browser has it,
	// else the trusted event being handled (window.event). Consent comes from the
	// visitor (GDPR art. 7), never from a site script running on its own.
	// Without that API only a trusted activation event counts (a click or a key),
	// never load, scroll, message or other events a script can wait for.
	var API_ACTIVATION = { click: 1, keydown: 1, keyup: 1, mousedown: 1, mouseup: 1, pointerdown: 1, pointerup: 1, touchend: 1 };
	function gestureOk(nav, ev) {
		if (nav && nav.userActivation) { return !!nav.userActivation.isActive; }
		return !!(ev && ev.isTrusted && API_ACTIVATION.hasOwnProperty(ev.type));
	}
	function apiUniq(list) {
		var out = [];
		list.forEach(function (s) { if (out.indexOf(s) === -1) { out.push(s); } });
		return out;
	}
	// A callback's error is reported, never thrown into the caller.
	function apiErr(e) { if (typeof console !== 'undefined' && console.error) { console.error(e); } }
	// The decision plus `slug` (and the required ones); no decision = required + slug.
	function mergeAllow(current, slug, required) {
		return apiUniq((Array.isArray(current) ? current : []).concat(Array.isArray(required) ? required : [], [slug]));
	}
	// The decision without `slug`; a required category is never removed.
	function mergeDeny(current, slug, required) {
		var req = Array.isArray(required) ? required : [];
		return (Array.isArray(current) ? current : []).filter(function (s) { return s !== slug || req.indexOf(s) !== -1; });
	}
	// The onConsent listeners. add() returns its own unsubscribe; a throwing
	// callback is reported and does not stop the others.
	function subscribers() {
		var list = [];
		return {
			add: function (fn) {
				var item = { fn: fn };
				list.push(item);
				return function () { var i = list.indexOf(item); if (i !== -1) { list.splice(i, 1); } };
			},
			emit: function (consent) {
				list.slice().forEach(function (item) {
					try { item.fn(consent); } catch (e) { apiErr(e); }
				});
			}
		};
	}
	// The new members of window.CMP. h: light(), cat(slug), required(),
	// isAllowed(slug) (effective, B4), decided() (a stored decision for this policy
	// version), current() (its effective categories), consent() (getConsent),
	// gesture(), save(categories, decision) (the panel's save path).
	function apiMembers(h, subs) {
		function none() {}
		function optional(slug) { var c = h.cat(slug); return !h.light() && !!c && !c.required; }
		return {
			onConsent: function (fn) {
				if (typeof fn !== 'function' || h.light()) { return none; }
				var off = subs.add(fn);
				if (h.decided()) { try { fn(h.consent()); } catch (e) { apiErr(e); } }
				return off;
			},
			whenAllowed: function (slug, fn) {
				var done = false, off = none, c = h.cat(slug);
				if (typeof fn !== 'function') { return none; }
				function run() {
					if (done) { return; }
					done = true;
					off();
					try { fn(); } catch (e) { apiErr(e); }
				}
				if (c && c.required) { run(); return none; }
				if (h.light()) { return none; }
				if (h.isAllowed(slug)) { run(); return none; }
				off = subs.add(function () { if (h.isAllowed(slug)) { run(); } });
				return function () { done = true; off(); };
			},
			allowCategory: function (slug) {
				if (!optional(slug)) { return false; }
				if (!h.gesture()) {
					if (typeof console !== 'undefined' && console.warn) { console.warn('CMP.allowCategory: ignored, call it from a visitor click'); }
					return false;
				}
				if (h.isAllowed(slug)) { return false; }
				h.save(mergeAllow(h.decided() ? h.current() : null, slug, h.required()), 'custom');
				return true;
			},
			denyCategory: function (slug) {
				if (!optional(slug) || !h.isAllowed(slug)) { return false; }
				h.save(mergeDeny(h.current(), slug, h.required()), 'custom');
				return true;
			}
		};
	}
	// api:end
	function prefsOpen() { var m = modal(); return !!m && m.classList.contains('is-prefs-open'); }
	function onKeydown(e) {
		if (e.key === 'Escape' || e.key === 'Esc') {
			var back = banner.querySelector('[data-cmp-view="prefs"] [data-cmp-action="back"]');
			if (escapeAction(prefsOpen(), !!(back && back.hidden), blocking(), banner.contains(document.activeElement)) === 'back') { e.preventDefault(); goBack(); }
			return;
		}
		if (e.key !== 'Tab' || !blocking()) { return; }
		var target = trapStep(focusable(), document.activeElement, e.shiftKey, modal());
		if (target) { e.preventDefault(); target.focus(); }
	}
	function trapFocus(on) {
		if (on) {
			lastFocus = document.activeElement;
			// Focus the dialog, not its first button: a programmatic focus on a button
			// shows the :focus-visible ring on open. The ring now appears only once
			// the visitor tabs into the buttons.
			focusDialog();
		} else {
			var back = focusBack(lastFocus, reopenBtn, canRefocus);
			lastFocus = null;
			if (back) { back.focus(); }
		}
	}
	// Focus can go back to el: on the page, rendered, outside the banner, not <body>.
	function canRefocus(el) {
		return !!el && typeof el.focus === 'function' && el !== document.body && document.documentElement.contains(el) && !banner.contains(el) && el.getClientRects().length > 0;
	}

	// The dialog on the main view, the settings title when the panel is open.
	function focusDialog() {
		var t = prefsOpen() ? banner.querySelector('[data-cmp-prefs-title]') : modal();
		if (t) { t.focus(); }
	}
	// Back to the main view (Back button or Escape), focus on "Settings".
	function goBack() {
		closePrefs();
		var p = banner.querySelector('[data-cmp-view="main"] [data-cmp-action="prefs"]');
		if (p) { p.focus(); }
		// Its height changed: the policy page's scroll padding follows.
		scrollPad();
	}

	function modal() { return banner ? banner.querySelector('[data-cmp-modal]') : null; }
	// Optional toggles reflect the saved choice for the current policy version,
	// and start OFF when there is none (pre-ticked boxes are not valid consent).
	// A category that gained a service starts OFF too (re-consent preselect).
	function syncToggles() {
		var c = readCookie();
		var accepted = consentedCategories();
		banner.querySelectorAll('[data-cmp-cat]').forEach(function (b) {
			b.checked = accepted.indexOf(b.value) !== -1;
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
	function openPrefs() {
		var m = modal();
		if (!m) { return; }
		syncToggles();
		renderAlways();
		renderReconsent(pendingReconsent());
		m.classList.add('is-prefs-open');
		// The dialog is named by the title on screen.
		m.setAttribute('aria-labelledby', 'cmp-prefs-title');
		// Screen readers start on the panel title (no-op while the banner is hidden: show() focuses it).
		var t = banner.querySelector('[data-cmp-prefs-title]');
		if (t) { t.focus(); }
		// Its height changed: the policy page's scroll padding follows.
		scrollPad();
	}

	// --- Re-consent (B4) ---
	// The re-consent view for the stored decision, if it is for this policy
	// version and a category it accepted (and the banner shows) gained a service.
	var NO_RECONSENT = { show: false, newIds: {}, preselect: [] };
	function pendingReconsent() {
		var c = readCookie();
		if (!c || String(c.v) !== String(D.version)) { return NO_RECONSENT; }
		var view = reconsentView(c, D.svcByCat, requiredSlugs());
		var optional = D.categories.filter(function (x) { return !x.required; }).map(function (x) { return x.slug; });
		// A category the banner cannot show could never be confirmed: do not ask forever.
		var shown = Object.keys(view.newIds).some(function (slug) { return optional.indexOf(slug) !== -1; });
		return shown ? view : NO_RECONSENT;
	}
	function svcLabel(id) {
		if (D.svcLabels && typeof D.svcLabels[id] === 'string' && D.svcLabels[id]) { return D.svcLabels[id]; }
		return serviceName(id);
	}
	// Prefs header: the "new services" title and reason while a re-consent is
	// pending, the normal title otherwise; "new" marker + names (textContent)
	// next to each affected category.
	var prefsTitleText = null;
	function renderReconsent(view) {
		if (!banner) { return; }
		var on = !!(view && view.show);
		var title = banner.querySelector('[data-cmp-prefs-title]');
		var text = banner.querySelector('[data-cmp-reconsent-text]');
		if (title) {
			if (prefsTitleText === null) { prefsTitleText = title.textContent; }
			title.textContent = on ? (title.getAttribute('data-cmp-reconsent-title') || prefsTitleText) : prefsTitleText;
		}
		if (text) { text.hidden = !on; }
		// No way back to the main view while re-consent is pending: it would hide
		// the reason (and "decide later" is not offered). The decision buttons stay.
		banner.querySelectorAll('[data-cmp-view="prefs"] [data-cmp-action="back"]').forEach(function (b) { b.hidden = on; });
		var m = modal();
		if (m) {
			// Described by the reason while it is on screen.
			if (on && text && text.id) { m.setAttribute('aria-describedby', text.id); } else { m.removeAttribute('aria-describedby'); }
		}
		banner.querySelectorAll('[data-cmp-cat-new]').forEach(function (el) {
			var slug = el.getAttribute('data-cmp-cat-new');
			var ids = on && Object.prototype.hasOwnProperty.call(view.newIds, slug) ? view.newIds[slug] : [];
			var names = el.querySelector('[data-cmp-cat-new-names]');
			if (names) { names.textContent = ids.map(svcLabel).join(', '); }
			el.hidden = !ids.length;
		});
	}
	function closePrefs() { var m = modal(); if (m) { m.classList.remove('is-prefs-open'); m.setAttribute('aria-labelledby', 'cmp-title'); m.setAttribute('aria-describedby', 'cmp-intro'); } }

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
			else if (action === 'reject') { save(requiredSlugs(), 'rejected_all'); rejectServices(); }
			else if (action === 'prefs') { openPrefs(); }
			else if (action === 'back') { goBack(); }
			else if (action === 'details') { details(); scrollPad(); }
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
		// Check mode: always shown (the measured page is a first visit).
		if (!D.check) { try { if (localStorage.getItem('cmp_light_dismissed')) { return; } } catch (e) {} }
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
	// in an inert <template> inside [data-cmp-embed]; it takes the placeholder's place
	// once its category is accepted, or when the visitor clicks "load" on it.
	function loadEmbed(box) {
		var tpl = box && box.querySelector('template[data-cmp-embed-src]');
		if (!tpl || !tpl.content) { return; }
		var frag = tpl.content.cloneNode(true);
		// The box stays, same size (aspect-ratio, margin): the iframe goes inside,
		// so nothing below moves (CLS 0). Without data-cmp-embed it is never
		// matched or loaded again.
		while (box.firstChild) { box.removeChild(box.firstChild); }
		box.removeAttribute('data-cmp-embed');
		box.classList.remove('cmp-embed--thumb');
		box.classList.add('cmp-embed--loaded');
		box.style.backgroundImage = '';
		box.appendChild(frag);
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
	function logService(decision, id, cookie) {
		logConsent(svcLogPayload(decision, id, consentedCategories(), svcLogId(cookie)));
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
		notify();
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
		notify();
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
	// The explicit "reject" also withdraws every "always load" choice (one
	// service_withdraw row naming them all). A partial save and GPC do not.
	// Embeds already loaded on this page stay until the next load.
	function rejectServices() {
		var cur = readSvc();
		if (!cur) { return; }
		var gone = svcAllowed(cur, D.version);
		var next = svcAfterReject(cur, D.version);
		writeSvc(next);
		if (gone.length) {
			var payload = svcLogPayload('service_withdraw', gone[0], consentedCategories(), svcLogId(next));
			payload.services = gone;
			logConsent(payload);
		}
		renderAlways();
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
		// The plugin version (CMP_DATA.version is the policy version).
		version: String(D.pluginVersion || ''),
		isAllowed: function (slug) {
			var cat = D.categories.filter(function (x) { return x.slug === slug; })[0];
			if (cat && cat.required) { return true; }
			return consentedCategories().indexOf(slug) !== -1;
		},
		// The stored decision, with the effective categories: one that gained a
		// service is not reported as consented until the visitor decides again.
		getConsent: function () {
			var c = readCookie();
			if (!c || typeof c !== 'object' || !Array.isArray(c.categories)) { return c; }
			var out = {};
			Object.keys(c).forEach(function (k) { out[k] = c[k]; });
			out.categories = effectiveCategories(c, D.svcByCat, requiredSlugs());
			return out;
		},
		// Prefs first, so the dialog has its final name when show() focuses it.
		openPreferences: function () { openPrefs(); show(); }
	};
	(function (m) { for (var k in m) { if (Object.prototype.hasOwnProperty.call(m, k)) { window.CMP[k] = m[k]; } } })(apiMembers({
		light: function () { return D.mode === 'light'; },
		cat: function (slug) { return D.categories.filter(function (x) { return x.slug === slug; })[0]; },
		required: requiredSlugs,
		isAllowed: function (slug) { return window.CMP.isAllowed(slug); },
		decided: decided,
		current: consentedCategories,
		consent: function () { return window.CMP.getConsent(); },
		gesture: function () { return gestureOk(window.navigator, window.event); },
		save: save
	}, apiSubs));

	document.addEventListener('DOMContentLoaded', function () {
		pushWpInit();
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
			// B4: a cookie without `svc` adopts the current services silently (see backfillSvc).
			// Written with the remaining lifetime, so the consent duration is not restarted.
			var adopted = backfillSvc(c, D.svcByCat);
			var left = adopted ? remainingMaxAge(c, D.cookieDays, Math.floor(Date.now() / 1000)) : 0;
			if (adopted && left > 0) { writeCookie(adopted, left); c = adopted; }
			// Replay the stored consent to Consent Mode before the scripts come back,
			// as save() does. Without it the signals stay on the `denied` defaults
			// printed in wp_head (the gtag stub there is data-cmp-keep, so it exists),
			// and a returning visitor who accepted statistics is measured as refused.
			// B4: a category that gained a service stays refused until the visitor decides again.
			var accepted = consentedCategories();
			pushConsent(accepted);
			unblockScripts(accepted);
			unblockEmbeds(accepted);
			if (pendingReconsent().show) {
				// Re-consent: like a first visit (overlay, none on the policy pages),
				// straight on the preferences, on every page until the decision.
				// No "decide later": it would be tacit consent to the new service.
				firstDecision = true;
				openPrefs();
				show();
				return;
			}
			showReopen();
			return;
		}
		if (navigator.globalPrivacyControl === true && !D.check) {
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
