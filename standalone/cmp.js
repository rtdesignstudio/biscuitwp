/*!
 * BiscuitWP — standalone bundle
 * Pure helper section (Task 2). Browser DOM/controller code is added in a
 * later task inside the same IIFE.
 */
(function () {
	'use strict';

	var KNOWN_SLUGS = ['necessary', 'statistics', 'marketing'];
	var KNOWN_LANGS = ['ro', 'ru', 'en'];

	var CMP_DEFAULTS = {
		categories: {
			necessary: { slug: 'necessary', required: true },
			statistics: { slug: 'statistics', required: false },
			marketing: { slug: 'marketing', required: false }
		},
		signals: {
			necessary: [],
			statistics: ['analytics_storage'],
			marketing: ['ad_storage', 'ad_user_data', 'ad_personalization']
		},
		i18n: {
			ro: {
				categories: {
					necessary: {
						label: 'Necesare',
						description: 'Cookie-uri esențiale pentru funcționarea site-ului. Nu pot fi dezactivate.'
					},
					statistics: {
						label: 'Statistici',
						description: 'Ne ajută să înțelegem cum este folosit site-ul, prin colectarea de date anonime.'
					},
					marketing: {
						label: 'Marketing',
						description: 'Folosite pentru a afișa reclame relevante și a măsura eficiența campaniilor.'
					}
				},
				texts: {
					title: 'Informații despre cookie-uri',
					intro: 'Folosim cookie-uri esențiale pentru funcționarea site-ului și, cu acordul tău, cookie-uri de analiză pentru a-l îmbunătăți. Poți accepta sau refuza cookie-urile opționale.',
					prefs: 'Gestionează cookie-urile',
					reject: 'Doar cele necesare',
					accept: 'Permite tot',
					prefs_title: 'Alege ce cookie-uri accepți',
					save: 'Confirmă selecția',
					badge: 'Obligatoriu',
					locked: 'Activat permanent',
					privacy: 'Politica de confidențialitate',
					back: 'Înapoi',
					dialogLabel: 'Informații despre cookie-uri',
					reopen: 'Setări cookie',
					consent_id: 'ID-ul consimțământului',
					embed_always: 'Încarcă mereu',
					always_loaded: 'Încărcate mereu:',
					always_remove: 'Nu mai încărca %s',
					reconsent_title: 'Am adăugat servicii noi',
					reconsent_text: 'Pe site au apărut servicii noi în categoriile pe care le-ai acceptat. Alege din nou.',
					svc_new: 'nou',
					details: 'Detalii'
				}
			},
			ru: {
				categories: {
					necessary: {
						label: 'Необходимые',
						description: 'Основные файлы cookie, необходимые для работы сайта. Их нельзя отключить.'
					},
					statistics: {
						label: 'Статистика',
						description: 'Помогают понять, как используется сайт, путём сбора анонимных данных.'
					},
					marketing: {
						label: 'Маркетинг',
						description: 'Используются для показа релевантной рекламы и оценки эффективности кампаний.'
					}
				},
				texts: {
					title: 'Информация о файлах cookie',
					intro: 'Мы используем необходимые файлы cookie для работы сайта и, с вашего согласия, аналитические cookie для его улучшения. Вы можете принять или отклонить дополнительные cookie.',
					prefs: 'Управление cookie',
					reject: 'Только необходимые',
					accept: 'Разрешить все',
					prefs_title: 'Выберите, какие cookie принять',
					save: 'Подтвердить выбор',
					badge: 'Обязательно',
					locked: 'Всегда включено',
					privacy: 'Политика конфиденциальности',
					back: 'Назад',
					dialogLabel: 'Информация о файлах cookie',
					reopen: 'Настройки cookie',
					consent_id: 'ID согласия',
					embed_always: 'Загружать всегда',
					always_loaded: 'Загружаются всегда:',
					always_remove: 'Больше не загружать %s',
					reconsent_title: 'Мы добавили новые сервисы',
					reconsent_text: 'На сайте появились новые сервисы в принятых вами категориях. Сделайте выбор снова.',
					svc_new: 'новый',
					details: 'Подробнее'
				}
			},
			en: {
				categories: {
					necessary: {
						label: 'Necessary',
						description: 'Essential cookies required for the website to function. These cannot be disabled.'
					},
					statistics: {
						label: 'Statistics',
						description: 'Help us understand how the site is used by collecting anonymous data.'
					},
					marketing: {
						label: 'Marketing',
						description: 'Used to show relevant ads and measure the effectiveness of campaigns.'
					}
				},
				texts: {
					title: 'Cookie information',
					intro: 'We use essential cookies for the website to function and, with your consent, analytics cookies to improve it. You can accept or reject optional cookies.',
					prefs: 'Manage cookies',
					reject: 'Necessary only',
					accept: 'Accept all',
					prefs_title: 'Choose which cookies to accept',
					save: 'Confirm selection',
					badge: 'Required',
					locked: 'Always active',
					privacy: 'Privacy policy',
					back: 'Back',
					dialogLabel: 'Cookie information',
					reopen: 'Cookie settings',
					consent_id: 'Consent ID',
					embed_always: 'Always load',
					always_loaded: 'Always loaded:',
					always_remove: 'Stop loading %s',
					reconsent_title: 'We added new services',
					reconsent_text: 'New services were added to categories you accepted. Please choose again.',
					svc_new: 'new',
					details: 'Details'
				}
			}
		}
	};

	// Optional {id: displayName}: string values only.
	function nameMap(m) {
		var out = {};
		if (!m || typeof m !== 'object' || Array.isArray(m)) { return out; }
		Object.keys(m).forEach(function (k) { if (typeof m[k] === 'string' && m[k]) { out[k] = m[k]; } });
		return out;
	}

	function normalizeConfig(raw) {
		raw = raw && typeof raw === 'object' ? raw : {};

		var lang = KNOWN_LANGS.indexOf(raw.lang) !== -1 ? raw.lang : 'ro';
		var mode = raw.mode === 'light' ? 'light' : 'pro';

		// Fresh object: per-language defaults with any config.texts overriding,
		// so callers/renderers never mutate the shared CMP_DEFAULTS.
		var texts = {};
		var defaultTexts = CMP_DEFAULTS.i18n[lang].texts;
		var k;
		for (k in defaultTexts) {
			if (Object.prototype.hasOwnProperty.call(defaultTexts, k)) { texts[k] = defaultTexts[k]; }
		}
		if (raw.texts && typeof raw.texts === 'object') {
			for (k in raw.texts) {
				if (Object.prototype.hasOwnProperty.call(raw.texts, k) && typeof raw.texts[k] === 'string') {
					texts[k] = raw.texts[k];
				}
			}
		}

		var requestedSlugs = [];
		if (Array.isArray(raw.categories)) {
			raw.categories.forEach(function (slugOrObj) {
				var slug = typeof slugOrObj === 'string' ? slugOrObj : (slugOrObj && slugOrObj.slug);
				if (KNOWN_SLUGS.indexOf(slug) !== -1 && requestedSlugs.indexOf(slug) === -1) {
					requestedSlugs.push(slug);
				}
			});
		} else {
			requestedSlugs = KNOWN_SLUGS.slice();
		}

		if (requestedSlugs.indexOf('necessary') === -1) {
			requestedSlugs.unshift('necessary');
		}

		var categories = requestedSlugs.map(function (slug) {
			var base = CMP_DEFAULTS.categories[slug];
			var copy = CMP_DEFAULTS.i18n[lang].categories[slug];
			return {
				slug: slug,
				required: slug === 'necessary' ? true : !!base.required,
				label: copy.label,
				description: copy.description,
				signals: CMP_DEFAULTS.signals[slug].slice()
			};
		});

		var cd = parseInt(raw.cookieDays, 10);
		// Consent lasts at most 13 months (396 days); longer values are capped.
		if (!(cd >= 1)) { cd = 180; } else if (cd > 396) { cd = 396; }

		return {
			site: typeof raw.site === 'string' ? raw.site : '',
			mode: mode,
			preset: typeof raw.preset === 'string' ? raw.preset : 'default',
			position: typeof raw.position === 'string' ? raw.position : 'modal-center',
			lang: lang,
			privacyUrl: safeUrl(raw.privacyUrl),
			respectGPC: !!raw.respectGPC,
			reopenButton: true === raw.reopenButton,
			// B6: classes + CSS variables, delay, theme, reopen shape; null = today's look.
			appearance: normAppearance(raw.appearance, raw.preset),
			reloadOnDecision: true === raw.reloadOnDecision,
			cookieDays: cd,
			// Re-consent (B4): {slug: [serviceIds]} written by hand; anything else = unknown.
			services: rcMap(raw.services),
			serviceNames: nameMap(raw.serviceNames),
			categories: categories,
			texts: texts
		};
	}

	// Consent ID: a random v4 UUID kept in the consent cookie and in the consent
	// log, so a visitor's choice can be found and demonstrated (GDPR / Law
	// 195/2024 art. 7(1)). It is not linked to any other data about the visitor.
	var UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
	function makeConsentId() {
		var b = [];
		var c = (typeof crypto !== 'undefined' && crypto.getRandomValues) ? crypto : null;
		if (c) { b = Array.prototype.slice.call(c.getRandomValues(new Uint8Array(16))); }
		else { for (var i = 0; i < 16; i++) { b.push(Math.floor(Math.random() * 256)); } }
		b[6] = (b[6] & 0x0f) | 0x40;
		b[8] = (b[8] & 0x3f) | 0x80;
		var h = b.map(function (x) { return (x < 16 ? '0' : '') + x.toString(16); }).join('');
		return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
	}
	// Pure (given the RNG): the visitor's existing ID, or a new one.
	// The visitor's existing ID, else the (valid) fallback one from cmp_svc, else a new one.
	function consentIdFor(existing, fallback) {
		if (existing && typeof existing.id === 'string' && UUID_RE.test(existing.id)) { return existing.id; }
		if (typeof fallback === 'string' && UUID_RE.test(fallback)) { return fallback; }
		return makeConsentId();
	}

	// "Always load" (cmp_svc): the embed services the visitor chose to load every
	// time, kept apart from the category consent (clicking it neither closes the
	// banner nor counts as a refusal; save() never touches it, but the explicit
	// "reject" action clears it). `id` is a UUID made on the first click.
	var SVC_ID_RE = /^[a-z0-9-]+$/;
	// Removes the "Always load" buttons from a document (Light mode keeps no cookie).
	// Returns how many were removed.
	function stripAlwaysButtons(doc) {
		var list = doc.querySelectorAll('[data-cmp-embed-always]'), n = 0;
		for (var i = 0; i < list.length; i++) {
			if (list[i].parentNode) { list[i].parentNode.removeChild(list[i]); n++; }
		}
		return n;
	}
	// Pure: the allowed service ids, [] unless the cookie is for this policy version.
	function svcAllowed(c, version) {
		if (!c || typeof c !== 'object' || String(c.v) !== String(version) || !Array.isArray(c.svc)) { return []; }
		return c.svc.filter(function (x, i, a) { return typeof x === 'string' && SVC_ID_RE.test(x) && a.indexOf(x) === i; });
	}
	// Pure: a cookie value (still URI-encoded) as an object, or null when it is
	// malformed (bad %-escape or not JSON). Never throws.
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
	// Pure: the cookie object with a service switched on or off; keeps the cookie's own id.
	function withSvc(c, version, id, on) {
		var list = svcAllowed(c, version).filter(function (x) { return x !== id; });
		if (on) { list.push(id); }
		var out = { v: String(version), svc: list };
		if (c && typeof c.id === 'string' && UUID_RE.test(c.id)) { out.id = c.id; }
		return out;
	}
	// Pure: the cmp_svc value after an explicit "reject": no service left, same
	// policy version, the cookie's own id kept.
	function svcAfterReject(c, version) {
		var out = { v: String(version), svc: [] };
		if (c && typeof c === 'object' && typeof c.id === 'string' && UUID_RE.test(c.id)) { out.id = c.id; }
		return out;
	}

	// Pure: is the current page the privacy policy (privacyUrl)? There the banner
	// shows without the blocking overlay, so the visitor can read it first.
	// Compares host + path; query, hash and a trailing slash are ignored.
	function isPolicyPage(privacyUrl, href) {
		if (!privacyUrl || !href) { return false; }
		try {
			var p = new URL(privacyUrl, href);
			var h = new URL(href);
			var strip = function (s) { return s.replace(/\/+$/, '') || '/'; };
			return p.host === h.host && strip(p.pathname) === strip(h.pathname);
		} catch (e) { return false; }
	}

	// Pure: does the browser send Global Privacy Control? Read client-side so a
	// cached page never carries one visitor's signal to everyone. DNT is ignored
	// (abandoned standard).
	function gpcOn(nav) {
		return !!nav && nav.globalPrivacyControl === true;
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

	// Pure: does an embed of this category load for the accepted categories?
	function embedLoads(category, accepted) {
		return !!category && (accepted || []).indexOf(category) !== -1;
	}

	// Pure: the Meta Pixel consent action for the accepted categories.
	function fbqConsentFor(accepted) {
		return (accepted || []).indexOf('marketing') !== -1 ? 'grant' : 'revoke';
	}

	function signalsFor(cats, accepted) {
		accepted = accepted || [];
		var signals = {};
		(cats || []).forEach(function (cat) {
			var granted = cat.required || accepted.indexOf(cat.slug) !== -1;
			(cat.signals || []).forEach(function (sig) {
				if (signals[sig] !== 'granted') {
					signals[sig] = granted ? 'granted' : 'denied';
				}
			});
		});
		return signals;
	}

	// Optional toggles reflect the saved choice for the current policy version,
	// and start OFF when there is none (pre-ticked boxes are not valid consent).
	function isToggleOn(slug, consent, version) {
		if (!consent || String(consent.v) !== String(version)) { return false; }
		return (consent.categories || []).indexOf(slug) !== -1;
	}

	// Pure: the categories to replay for a returning visitor, or null when the
	// stored consent is missing or belongs to another policy version.
	function storedCategories(consent, version) {
		if (!consent || String(consent.v) !== String(version)) { return null; }
		return consent.categories || [];
	}

	function decisionFor(selected, cats) {
		selected = selected || [];
		var optional = (cats || []).filter(function (c) { return !c.required; });
		var selectedOptional = optional.filter(function (c) { return selected.indexOf(c.slug) !== -1; });

		// No optional categories: nothing to reject, so consent is "all".
		if (optional.length === 0 || selectedOptional.length === optional.length) {
			return 'accepted_all';
		}
		if (selectedOptional.length === 0) {
			return 'rejected_all';
		}
		return 'partial';
	}

	function serializeConsent(obj) {
		return JSON.stringify(obj);
	}

	function parseConsent(str) {
		if (typeof str !== 'string') { return null; }
		try {
			var parsed = JSON.parse(str);
			return parsed && typeof parsed === 'object' ? parsed : null;
		} catch (e) {
			return null;
		}
	}

	function escapeHtml(s) {
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	// Allow only safe href schemes (relative, http(s), mailto). Blocks
	// javascript:/data: etc. — escapeHtml alone would not, since those carry
	// no HTML-special characters. Mirrors WordPress esc_url() intent.
	function safeUrl(u) {
		if (typeof u !== 'string') { return ''; }
		var v = u.replace(/[\t\n\r]/g, '').replace(/^\s+/, '');
		if (v === '') { return ''; }
		if (/^(?:https?:|mailto:)/i.test(v)) { return v; }
		// Relative URLs / fragments / query — no scheme, and not a scheme-relative
		// or protocol-looking prefix.
		if (/^[a-z][a-z0-9+.-]*:/i.test(v)) { return ''; }
		return v;
	}

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

	// appearance:begin
	// Pure helpers for the appearance (B6). Tested in standalone/tests/run.js
	// (source-extracted, identical in both files).
	// The display delay in ms: 0-2 s in 0.1 s steps; anything else = none.
	function delayMs(s) {
		s = parseFloat(s);
		return s > 0 ? Math.round(Math.min(s, 2) * 10) * 100 : 0;
	}
	// Theme "like the site": dark only when <html> or <body> matches the
	// selector list; no match = light (the OS decides only with theme "auto").
	// A bad selector never throws.
	function siteDark(sel, html, body) {
		try { return !!sel && (html.matches(sel) || (!!body && body.matches(sel))); } catch (e) { return false; }
	}
	// Re-checked on every show (no observer): .cmp--dark for theme "site";
	// `own` = the Dark palette's own class, never removed.
	function applySiteTheme(el, a, own, doc) {
		if (!el || !a || a.theme !== 'site') { return; }
		if (own || siteDark(a.darkSelector, doc.documentElement, doc.body)) { el.classList.add('cmp--dark'); } else { el.classList.remove('cmp--dark'); }
	}
	// Compliance guard (B6.18): the backstop behind the sanitizer and the custom
	// CSS cleaner. m = measure(): v visibility, o opacity (with its
	// ancestors up to the banner), w/h (0 = not rendered), x/y in the viewport, s inside a scrolling
	// area of the banner, c text contrast. True = a decision button is not plainly
	// usable (hidden, faded, < 24 px, off screen, unreadable) or the two differ by
	// more than 2 px; ctx = { vw, vh, box: the dialog's measure }.
	function guardBad(m, ctx) {
		return !m || m.v !== 'visible' || !(m.o >= 0.9) || !(m.w >= 24 && m.h >= 24) || !(m.c >= 3) ||
			m.x < -1 || m.x + m.w > ctx.vw + 1 || (!m.s && (m.y < -1 || m.y + m.h > ctx.vh + 1));
	}
	function guardNeeded(a, b, ctx) {
		var x = ctx.box;
		return !!(x && !(x.w > 0 && x.v === 'visible' && x.o >= 0.9)) || guardBad(a, ctx) || guardBad(b, ctx) || Math.abs(a.w - b.w) > 2 || Math.abs(a.h - b.h) > 2;
	}
	// Computed colours other than rgb()/rgba() (oklch, color()) are not parsed:
	// read as transparent (background) and the contrast is skipped (text) - fails open.
	function rgba(s) { var m = /^rgba?\(/.test(s) ? s.match(/[\d.]+/g) : [0, 0, 0, 0]; return [+m[0], +m[1], +m[2], m[3] == null ? 1 : +m[3]]; }
	// f (with alpha) over the opaque b: [r, g, b].
	function over(f, b) { return [0, 1, 2].map(function (i) { return f[i] * f[3] + b[i] * (1 - f[3]); }); }
	// Relative luminance + 0.05 (sRGB curve approximated by gamma 2.2: within 0.1 of WCAG here).
	function lum(c) { var p = function (i) { return Math.pow(c[i] / 255, 2.2); }; return 0.2126 * p(0) + 0.7152 * p(1) + 0.0722 * p(2) + 0.05; }
	// WCAG ratio of a computed text colour (blended) on an opaque background.
	function contrast(color, bg) { var f = lum(over(rgba(color), bg)), b = lum(bg); return f > b ? f / b : b / f; }
	function measure(el, root, win) {
		if (!el) { return null; }
		var r = el.getBoundingClientRect(), cs = win.getComputedStyle(el), o = 1, s = false, bg, p, ps, c;
		for (p = el; p; p = p === root ? null : p.parentElement) {
			ps = win.getComputedStyle(p);
			o *= parseFloat(ps.opacity);
			if (p !== el && p.scrollHeight > p.clientHeight + 1 && /auto|scroll/.test(ps.overflowY)) { s = true; }
			// The effective background: the first non-transparent one (transparent = look further), on white.
			c = rgba(ps.backgroundColor);
			if (!bg && c[3] > 0) { bg = c; }
		}
		bg = over(bg || [0, 0, 0, 0], [255, 255, 255]);
		return { v: cs.visibility, o: o, w: r.width, h: r.height, x: r.left, y: r.top, s: s, c: /^rgba?\(/.test(cs.color) ? contrast(cs.color, bg) : 21 };
	}
	// The reset, inline and !important: it outweighs any stylesheet rule (ids,
	// @layer, !important). Both buttons get the same default (solid) style, in
	// the safe Neutral colours (never the --cmp-primary* ones, which may be the failing pair).
	var GV = 'visibility:visible;opacity:1;transform:none;clip-path:none;';
	var GB = GV + 'display:block;position:static;width:100%;min-height:44px;padding:10px 12px;font-size:14px;color:#fff;background:#111827;border:1px solid #fff;pointer-events:auto;';
	// The decision buttons (.cmp__btn--primary: Reject, Accept, Save) and their
	// equal-columns group; every declaration gets !important.
	function guardFix(root) {
		var c = function (e, css) { e.style.cssText += ';' + css.replace(/;/g, '!important;'); };
		c(root, 'display:flex;' + GV);
		c(root.querySelector('.cmp__modal'), GV);
		[].forEach.call(root.querySelectorAll('.cmp__btn--primary'), function (b) { c(b, GB); c(b.parentNode, GV + 'display:grid;grid-template-columns:1fr 1fr;gap:8px;'); });
	}
	// Run on the banner once it is on screen (no getAnimations, old browser: not checked). Still animating (first frame late; finished CSS
	// animations without fill are no longer listed):
	// measured once more 400 ms later. On a failure: .cmp--guard, the inline reset
	// (re-applied on every later show) and one console warning.
	function runGuard(root, win, again) {
		try {
			if (!root || root.hidden) { return; }
			if (root.classList.contains('cmp--guard')) { guardFix(root); return; }
			if (!again && root.getAnimations({ subtree: true }).length) { win.setTimeout(function () { runGuard(root, win, 1); }, 400); return; }
			var m = root.querySelector('.cmp__modal'), v = m && m.classList.contains('is-prefs-open') ? 'prefs' : 'main';
			var q = function (a) { return measure(root.querySelector('[data-cmp-view="' + v + '"] [data-cmp-action="' + a + '"]'), root, win); };
			if (guardNeeded(q('reject'), q(v === 'main' ? 'accept' : 'save'), { vw: win.innerWidth, vh: win.innerHeight, box: measure(m, root, win) })) {
				root.classList.add('cmp--guard');
				guardFix(root);
				win.console.warn('CMP: appearance guard reset the consent buttons');
			}
		} catch (e) {}
	}
	// appearance:end

	// --- Appearance (B6), standalone: CMP_CONFIG.appearance gives the same
	// classes and CSS variables as the WordPress plugin (CMP_Appearance in PHP;
	// tested against its output in standalone/tests/run.js). No `appearance` =
	// today's look, unchanged.
	// Style => [radius, shadow, buttons, anim, maxw] (the spec's table).
	var AP_STYLES = { minimal: [4, 'none', 'outline', 'fade', 640], brand: [12, 'medium', 'solid', 'none', 720], friendly: [20, 'large', 'solid', 'slide', 680] };
	var AP_SHADOWS = { none: 'none', small: '0 4px 14px rgba(0,0,0,.12)', medium: '0 20px 50px rgba(0,0,0,.25)', large: '0 28px 70px rgba(0,0,0,.28)' };
	// Format => position => the cmp--pos-* class (the first position is the fallback).
	var AP_POS = {
		bar: { bottom: 'bottom', top: 'top' },
		card: { 'bottom-right': 'bottom-right', 'bottom-left': 'bottom-left', 'top-right': 'top-right', 'top-left': 'top-left' },
		modal: { center: 'middle-center', top: 'top-center', bottom: 'bottom-center' }
	};
	var AP_DIM = { neutral: 55, warm: 50, dark: 60, egov: 60 };
	var AP_RANGES = { radius: [0, 24], maxw: [360, 960], gap: [0, 40], dim: [0, 80], blur: [0, 10] };
	var AP_DARK_SEL = '.dark, [data-theme="dark"], .is-dark-theme';
	var AP_SCOPE = '.cmp,.cmp-light,.cmp-reopen,.cmp-embed';
	// Density rules (CMP_Appearance::DENSITY_CSS): only for compact / comfortable; normal = none.
	var AP_DENSITY = {
		compact: '@media (min-width:576px){.cmp--density-compact .cmp__modal{padding:19px 21px}.cmp--density-compact.cmp--fmt-bar .cmp__modal{padding-inline:max(21px,calc(50% - 600px))}.cmp--density-compact .cmp__title{margin-bottom:8px}.cmp--density-compact .cmp__main .cmp__text{margin-bottom:16px}.cmp--density-compact .cmp__actions,.cmp--density-compact .cmp__prefs-actions,.cmp--density-compact .cmp__choices{gap:8px}.cmp--density-compact .cmp__btn{padding:8px 15px}}@media (max-width:575px){.cmp--density-compact .cmp__modal{padding:16px 14px}.cmp--density-compact .cmp__actions{gap:5px 8px}.cmp--density-compact .cmp__choices{gap:5px}.cmp--density-compact .cmp__choices .cmp__btn{min-height:36px;padding:7px 8px}}.cmp--density-compact .cmp__prefs-header{gap:11px;margin-bottom:13px}.cmp--density-compact .cmp__cats{padding:5px 16px;margin-bottom:13px}.cmp--density-compact .cmp__cat{gap:11px;padding:11px 0}.cmp-light.cmp--density-compact{gap:9px;padding:12px 13px}.cmp--density-compact .cmp-light__btn{padding:7px 17px}',
		comfortable: '@media (min-width:576px){.cmp--density-comfortable .cmp__modal{padding:37px 43px}.cmp--density-comfortable.cmp--fmt-bar .cmp__modal{padding-inline:max(43px,calc(50% - 600px))}.cmp--density-comfortable .cmp__title{margin-bottom:16px}.cmp--density-comfortable .cmp__main .cmp__text{margin-bottom:32px}.cmp--density-comfortable .cmp__actions,.cmp--density-comfortable .cmp__prefs-actions,.cmp--density-comfortable .cmp__choices{gap:16px}.cmp--density-comfortable .cmp__btn{padding:16px 29px}}@media (max-width:575px){.cmp--density-comfortable .cmp__modal{padding:32px 27px}.cmp--density-comfortable .cmp__actions{gap:11px 16px}.cmp--density-comfortable .cmp__choices{gap:11px}.cmp--density-comfortable .cmp__choices .cmp__btn{min-height:48px;padding:13px 16px}}.cmp--density-comfortable .cmp__text{font-size:15px}.cmp--density-comfortable .cmp__cat-desc{font-size:14px}.cmp--density-comfortable .cmp__prefs-header{gap:21px;margin-bottom:27px}.cmp--density-comfortable .cmp__cats{padding:11px 32px;margin-bottom:27px}.cmp--density-comfortable .cmp__cat{gap:21px;padding:21px 0}.cmp-light.cmp--density-comfortable{gap:19px;padding:24px 27px;font-size:15px}.cmp--density-comfortable .cmp-light__btn{padding:13px 35px}'
	};

	function apPick(v, list, d) { return list.indexOf(v) !== -1 ? v : d; }
	function apNum(v, k, d) {
		var n = typeof v === 'number' || typeof v === 'string' ? parseFloat(v) : NaN;
		return isNaN(n) ? d : Math.max(AP_RANGES[k][0], Math.min(AP_RANGES[k][1], Math.round(n)));
	}
	function apHex(v) {
		var m = typeof v === 'string' && v.trim().match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i);
		if (!m) { return ''; }
		var h = m[1].toLowerCase();
		return '#' + (h.length === 3 ? h[0] + h[0] + h[1] + h[1] + h[2] + h[2] : h);
	}

	// Pure: CMP_CONFIG.appearance validated (unknown -> the style's / default
	// value), or null when there is none. The palette defaults to `preset`.
	function normAppearance(raw, preset) {
		if (!raw || typeof raw !== 'object' || Array.isArray(raw)) { return null; }
		var pal = ['neutral', 'warm', 'dark', 'egov'];
		var style = apPick(raw.style, ['minimal', 'brand', 'friendly'], 'brand'), sd = AP_STYLES[style];
		var format = apPick(raw.format, ['bar', 'card', 'modal'], 'modal');
		var sel = typeof raw.darkSelector === 'string' ? raw.darkSelector.trim() : '';
		var delay = parseFloat(raw.delay);
		return {
			style: style,
			palette: apPick(raw.palette, pal, apPick(preset, pal, 'neutral')),
			accent: apHex(raw.accent),
			font: apPick(raw.font, ['system', 'inherit'], 'system'),
			theme: apPick(raw.theme, ['light', 'dark', 'auto', 'site'], 'light'),
			darkSelector: sel && sel.length <= 200 && /^[A-Za-z0-9_\-.#\[\]="' ,:()>~+*]+$/.test(sel) ? sel : AP_DARK_SEL,
			format: format,
			position: Object.prototype.hasOwnProperty.call(AP_POS[format], raw.position) ? raw.position : Object.keys(AP_POS[format])[0],
			backdrop: apPick(raw.backdrop, ['blur', 'dim', 'none'], 'blur'),
			dim: raw.dim === null || raw.dim === undefined || raw.dim === '' ? null : apNum(raw.dim, 'dim', null),
			blur: apNum(raw.blur, 'blur', 5),
			radius: apNum(raw.radius, 'radius', sd[0]),
			shadow: Object.prototype.hasOwnProperty.call(AP_SHADOWS, raw.shadow) ? raw.shadow : sd[1],
			maxw: apNum(raw.maxw, 'maxw', sd[4]),
			gap: apNum(raw.gap, 'gap', 24),
			density: apPick(raw.density, ['compact', 'normal', 'comfortable'], 'normal'),
			buttons: apPick(raw.buttons, ['solid', 'outline', 'text'], sd[2]),
			anim: apPick(raw.anim, ['none', 'fade', 'slide'], sd[3]),
			delay: delay > 0 ? Math.round(Math.min(delay, 2) * 10) / 10 : 0,
			reopen: apPick(raw.reopen, ['none', 'icon', 'text', 'both'], 'icon')
		};
	}

	// Pure: the container classes ('pro' | 'light'), as CMP_Appearance::container_classes().
	function appearanceClasses(a, mode) {
		var pro = mode !== 'light', c = ['cmp--' + a.palette, 'cmp--style-' + a.style];
		if (pro) { c.push('cmp--fmt-' + a.format, 'cmp--pos-' + AP_POS[a.format][a.position], 'cmp--btn-' + a.buttons); }
		c.push('cmp--anim-' + a.anim, 'cmp--theme-' + a.theme);
		if (pro) { c.push('cmp--backdrop-' + a.backdrop); }
		if (a.theme === 'dark' && a.palette !== 'dark') { c.push('cmp--dark'); }
		if (a.density !== 'normal') { c.push('cmp--density-' + a.density); }
		return c;
	}

	// WCAG contrast (as CMP_Contrast) and the HSL steps of CMP_Appearance.
	function apLum(hex) {
		var l = [1, 3, 5].map(function (i) {
			var v = parseInt(hex.substr(i, 2), 16) / 255;
			return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
		});
		return 0.2126 * l[0] + 0.7152 * l[1] + 0.0722 * l[2];
	}
	function apRatio(a, b) { var x = apLum(a), y = apLum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); }
	function apText(c) { return apRatio(c, '#ffffff') >= apRatio(c, '#000000') ? '#ffffff' : '#000000'; }
	function apHsl(hex) {
		var r = parseInt(hex.substr(1, 2), 16) / 255, g = parseInt(hex.substr(3, 2), 16) / 255, b = parseInt(hex.substr(5, 2), 16) / 255;
		var max = Math.max(r, g, b), min = Math.min(r, g, b), l = (max + min) / 2, h = 0, s = 0, d = max - min;
		if (d > 0) {
			s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
			h = (max === r ? (g - b) / d + (g < b ? 6 : 0) : max === g ? (b - r) / d + 2 : (r - g) / d + 4) * 60;
		}
		return [h, s * 100, l * 100];
	}
	function apFromHsl(h, s, l) {
		s /= 100; l /= 100;
		var c = (1 - Math.abs(2 * l - 1)) * s, x = c * (1 - Math.abs((h / 60) % 2 - 1)), m = l - c / 2;
		var rgb = [[c, x, 0], [x, c, 0], [0, c, x], [0, x, c], [x, 0, c], [c, 0, x]][Math.floor(h / 60) % 6];
		return '#' + rgb.map(function (v) { var n = Math.max(0, Math.min(255, Math.round((v + m) * 255))); return (n < 16 ? '0' : '') + n.toString(16); }).join('');
	}
	// The accent's --cmp-primary / -hover / -text (hover moves away from the text colour).
	function apAccent(accent, text) {
		var hsl = apHsl(accent), l = text === '#ffffff' ? Math.max(0, hsl[2] - 8) : Math.min(100, hsl[2] + 8);
		return ['--cmp-primary:' + accent, '--cmp-primary-hover:' + apFromHsl(hsl[0], hsl[1], l), '--cmp-primary-text:' + text];
	}
	// The closest shade of the accent that passes 4.5:1 on the Dark background (accent_suggestion).
	function apDarkAccent(hex) {
		var bg = '#1e242e';
		function score(c) { var t = apText(c); return [Math.min(apRatio(c, t), apRatio(c, bg)), t]; }
		var s0 = score(hex);
		if (s0[0] >= 4.5) { return [hex, s0[1]]; }
		var hsl = apHsl(hex);
		for (var i = 1; i <= 200; i++) {
			for (var k = 0; k < 2; k++) {
				var l2 = hsl[2] + (k ? 1 : -1) * i * 0.5;
				if (l2 < 0 || l2 > 100) { continue; }
				var c = apFromHsl(hsl[0], hsl[1], l2), sc = score(c);
				if (sc[0] >= 4.5) { return [c, sc[1]]; }
			}
		}
		var b = score('#000000'), w = score('#ffffff');
		return b[0] >= w[0] ? ['#000000', b[1]] : ['#ffffff', w[1]];
	}
	// Pure: the inline CSS, as CMP_Appearance::inline_css() (only what differs
	// from Brand and the palette; '' for Brand). Built from validated values only.
	function appearanceCss(a) {
		var v = [], css = '', dv;
		if (a.accent) { v = v.concat(apAccent(a.accent, apText(a.accent))); }
		if (a.radius !== 12) { v.push('--cmp-radius:' + a.radius + 'px'); }
		if (a.maxw !== 720) { v.push('--cmp-maxw:' + a.maxw + 'px'); }
		if (a.gap !== 24) { v.push('--cmp-gap:' + a.gap + 'px'); }
		if (a.blur !== 5) { v.push('--cmp-blur:' + a.blur + 'px'); }
		if (a.shadow !== 'medium') { v.push('--cmp-shadow:' + AP_SHADOWS[a.shadow]); }
		if (a.dim !== null && a.dim !== AP_DIM[a.palette]) { v.push('--cmp-dim:' + String(a.dim / 100).replace(/^0\./, '.')); }
		if (a.font === 'inherit') { v.push('font-family:inherit'); } // a real declaration (lot review I1)
		if (v.length) { css = AP_SCOPE + '{' + v.join(';') + '}'; }
		if (a.accent && (a.theme !== 'light' || a.palette === 'dark')) {
			var d = apDarkAccent(a.accent);
			dv = apAccent(d[0], d[1]).join(';');
			css += '.cmp--dark{' + dv + '}';
			if (a.theme === 'auto') { css += '@media (prefers-color-scheme:dark){.cmp--theme-auto{' + dv + '}}'; }
		}
		if (Object.prototype.hasOwnProperty.call(AP_DENSITY, a.density)) { css += AP_DENSITY[a.density]; }
		return css;
	}

	if (typeof document !== 'undefined') {

		var COOKIE_NAME = 'cmp_consent';
		var VERSION = '1';
		// The package version, for CMP.version (VERSION is the policy version of the
		// cookie). Kept equal to the plugin's CMP_VERSION (checked in tests/run.js).
		var PKG_VERSION = '1.1.0';

		function renderPro(cfg) {
			var root = document.createElement('div');
			root.id = 'cmp-overlay';
			var noOverlay = isPolicyPage(cfg.privacyUrl, window.location.href);
			root.className = 'cmp ' + (cfg.appearance ? appearanceClasses(cfg.appearance, 'pro').join(' ') : 'cmp--' + cfg.preset + ' cmp--pos-' + cfg.position) + (noOverlay ? ' cmp--no-overlay' : '');
			root.setAttribute('data-cmp', '');
			root.setAttribute('lang', cfg.lang);
			root.hidden = true;

			var privacyLink = '';
			if (cfg.privacyUrl) {
				privacyLink = ' <a class="cmp__link" href="' + escapeHtml(cfg.privacyUrl) + '">' + escapeHtml(cfg.texts.privacy) + '</a>.';
			}

			var catsHtml = '';
			cfg.categories.forEach(function (cat) {
				var id = 'cmp-cat-' + cat.slug;
				var badge = cat.required ? '<span class="cmp__cat-badge">' + escapeHtml(cfg.texts.badge || 'Required') + '</span>' : '';
				// Re-consent: shown by JS next to a category that gained services.
				var newBadge = cat.required ? '' : '<span class="cmp__cat-new" data-cmp-cat-new="' + escapeHtml(cat.slug) + '" hidden>' +
					'<span class="cmp__cat-badge cmp__cat-badge--new">' + escapeHtml(cfg.texts.svc_new || 'new') + '</span>' +
					'<span class="cmp__cat-new-names" data-cmp-cat-new-names></span></span>';
				var control;
				if (cat.required) {
					control = '<span class="cmp__cat-locked" role="img" aria-label="' + escapeHtml(cfg.texts.locked || 'Always active') + '">' +
						'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>' +
						'</span>';
				} else {
					control = '<label class="cmp__toggle" for="' + escapeHtml(id) + '">' +
						'<input type="checkbox" role="switch" id="' + escapeHtml(id) + '" value="' + escapeHtml(cat.slug) + '" aria-labelledby="' + escapeHtml(id) + '-label" aria-describedby="' + escapeHtml(id) + '-desc" data-cmp-cat>' +
						'<span class="cmp__toggle-slider"></span>' +
						'</label>';
				}
				catsHtml += '<div class="cmp__cat">' +
					'<div class="cmp__cat-body">' +
					'<div class="cmp__cat-head">' +
					'<span class="cmp__cat-label" id="' + escapeHtml(id) + '-label">' + escapeHtml(cat.label) + '</span>' + badge + newBadge +
					'</div>' +
					'<div class="cmp__cat-desc" id="' + escapeHtml(id) + '-desc">' + escapeHtml(cat.description) + '</div>' +
					'</div>' + control +
					'</div>';
			});

			root.innerHTML =
				'<div class="cmp__modal" role="dialog" aria-modal="' + (noOverlay || (cfg.appearance && cfg.appearance.backdrop === 'none') ? 'false' : 'true') + '" aria-labelledby="cmp-title" aria-describedby="cmp-intro" tabindex="-1" data-cmp-modal>' +
				'<div class="cmp__main" data-cmp-view="main">' +
				'<h2 id="cmp-title" class="cmp__title">' + escapeHtml(cfg.texts.title) + '</h2>' +
				'<p id="cmp-intro" class="cmp__text">' + escapeHtml(cfg.texts.intro) + privacyLink + '</p>' +
				'<div class="cmp__actions">' +
				'<button type="button" class="cmp__more" data-cmp-action="details" aria-expanded="false" aria-controls="cmp-intro">' + escapeHtml(cfg.texts.details) + '</button>' +
				'<button type="button" class="cmp__btn cmp__btn--ghost" data-cmp-action="prefs">' + escapeHtml(cfg.texts.prefs) + '<span class="cmp__chev" aria-hidden="true"> &#8250;</span></button>' +
				'<span class="cmp__spacer" aria-hidden="true"></span>' +
				'<span class="cmp__choices">' +
				'<button type="button" class="cmp__btn cmp__btn--primary" data-cmp-action="reject">' + escapeHtml(cfg.texts.reject) + '</button>' +
				'<button type="button" class="cmp__btn cmp__btn--primary" data-cmp-action="accept">' + escapeHtml(cfg.texts.accept) + '</button>' +
				'</span>' +
				'</div>' +
				'</div>' +
				'<div class="cmp__prefs" data-cmp-view="prefs">' +
				'<div class="cmp__prefs-header">' +
				'<h2 id="cmp-prefs-title" class="cmp__title" tabindex="-1" data-cmp-prefs-title data-cmp-reconsent-title="' + escapeHtml(cfg.texts.reconsent_title || '') + '">' + escapeHtml(cfg.texts.prefs_title) + '</h2>' +
				'<button type="button" class="cmp__collapse" data-cmp-action="back" aria-label="' + escapeHtml(cfg.texts.back) + '">' +
				'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 15 12 9 18 15"></polyline></svg>' +
				'</button>' +
				'</div>' +
				'<p id="cmp-reconsent-text" class="cmp__text cmp__reconsent-text" data-cmp-reconsent-text hidden>' + escapeHtml(cfg.texts.reconsent_text || '') + '</p>' +
				'<div class="cmp__cats">' + catsHtml + '</div>' +
				'<div class="cmp__prefs-actions">' +
				'<span class="cmp__choices">' +
				'<button type="button" class="cmp__btn cmp__btn--primary" data-cmp-action="reject">' + escapeHtml(cfg.texts.reject) + '</button>' +
				'<button type="button" class="cmp__btn cmp__btn--primary" data-cmp-action="save">' + escapeHtml(cfg.texts.save) + '</button>' +
				'</span>' +
				'</div>' +
				'<div class="cmp__always" data-cmp-always-list data-cmp-always-remove="' + escapeHtml(cfg.texts.always_remove || 'Stop loading %s') + '" hidden>' +
				'<span class="cmp__always-label">' + escapeHtml(cfg.texts.always_loaded || 'Always loaded:') + '</span>' +
				'<span class="cmp__always-items" data-cmp-always-items></span>' +
				'</div>' +
				'<span class="cmp__sr" aria-live="polite" data-cmp-always-live></span>' +
				'<p class="cmp__consent-id" data-cmp-consent-id hidden>' + escapeHtml(cfg.texts.consent_id || 'Consent ID') + ': <code></code></p>' +
				'</div>' +
				'</div>';

			document.body.appendChild(root);
			return root;
		}

		function renderLight(cfg) {
			var root = document.createElement('div');
			root.className = 'cmp-light ' + (cfg.appearance ? appearanceClasses(cfg.appearance, 'light').join(' ') : 'cmp--' + cfg.preset);
			root.setAttribute('data-cmp-light', '');
			root.setAttribute('lang', cfg.lang);
			root.hidden = true;
			root.setAttribute('role', 'region');
			root.setAttribute('aria-label', cfg.texts.dialogLabel);

			var privacyLink = '';
			if (cfg.privacyUrl) {
				privacyLink = ' <a class="cmp-light__link" href="' + escapeHtml(cfg.privacyUrl) + '">' + escapeHtml(cfg.texts.privacy) + '</a>.';
			}

			root.innerHTML =
				'<p class="cmp-light__text">' + escapeHtml(cfg.texts.light_intro || cfg.texts.intro) + privacyLink + '</p>' +
				'<button type="button" class="cmp-light__btn" data-cmp-light-dismiss>' + escapeHtml(cfg.texts.accept) + '</button>';

			document.body.appendChild(root);
			return root;
		}

		var cfg = null;
		var banner = null;
		var reopenBtn = null;
		// B6: CMP_CONFIG.appearance (delay, theme "like the site"); {} = today's behaviour.
		var A = {};
		var ownDark = false;
		var showT = 0;
		// Opening by itself (first visit, re-consent), after the display delay.
		function autoShow() {
			var ms = delayMs(A.delay);
			if (ms) { showT = setTimeout(show, ms); } else { show(); }
		}

		/** Floating round cookie-settings button (pro mode); shown after a decision. */
		function renderReopen(c) {
			// The reopenButton switch as before; CMP_CONFIG.appearance.reopen picks the shape.
			var shape = c.appearance ? c.appearance.reopen : 'icon';
			if (!c.reopenButton || shape === 'none') { return null; }
			var label = c.texts.reopen || 'Cookie settings';
			var icon = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5Z"/><circle cx="9.5" cy="10.5" r="1"/><circle cx="14.5" cy="14.5" r="1"/><circle cx="9" cy="15" r="1"/></svg>';
			var b = document.createElement('button');
			b.type = 'button';
			b.className = shape === 'icon' ? 'cmp-reopen' : 'cmp-reopen cmp-reopen--text';
			b.setAttribute('data-cmp-reopen', '');
			b.hidden = true;
			if (shape === 'icon') {
				b.setAttribute('aria-label', label);
				b.setAttribute('title', label);
				b.innerHTML = icon;
			} else {
				b.innerHTML = (shape === 'both' ? icon : '') + '<span class="cmp-reopen__label">' + escapeHtml(label) + '</span>';
			}
			document.body.appendChild(b);
			return b;
		}
		// The appearance variables, after cmp.css (appended last in <head>); none for Brand.
		function renderAppearanceCss(c) {
			var css = c.appearance ? appearanceCss(c.appearance) : '';
			if (!css) { return; }
			var st = document.createElement('style');
			st.id = 'cmp-appearance-css';
			st.textContent = css;
			(document.head || document.body).appendChild(st);
		}
		function showReopen() { if (reopenBtn) { reopenBtn.hidden = false; } }
		function hideReopen() { if (reopenBtn) { reopenBtn.hidden = true; } }

		function requiredSlugs() {
			return (cfg.categories || []).filter(function (c) { return c.required; }).map(function (c) { return c.slug; });
		}

		function readCookie() {
			var m = document.cookie.match('(?:^|; )' + COOKIE_NAME + '=([^;]*)');
			if (!m) { return null; }
			return parseSvcCookie(m[1]);
		}

		// maxAge: optional override in seconds (the remaining lifetime on adoption).
		function writeCookie(obj, maxAge) {
			var v = encodeURIComponent(serializeConsent(obj));
			if (!(typeof maxAge === 'number' && maxAge > 0)) { maxAge = 60 * 60 * 24 * (cfg.cookieDays || 180); }
			document.cookie = COOKIE_NAME + '=' + v + ';path=/;max-age=' + maxAge + ';SameSite=Lax';
		}

		var SVC_COOKIE = 'cmp_svc';
		function readSvc() {
			var m = document.cookie.match('(?:^|; )' + SVC_COOKIE + '=([^;]*)');
			if (!m) { return null; }
			return parseSvcCookie(m[1]);
		}
		function writeSvc(obj) {
			var v = encodeURIComponent(serializeConsent(obj));
			var maxAge = 60 * 60 * 24 * (cfg.cookieDays || 180);
			document.cookie = SVC_COOKIE + '=' + v + ';path=/;max-age=' + maxAge + ';SameSite=Lax';
		}

		function pushConsent(accepted) {
			// Meta Pixel follows the marketing category, when it is already on the
			// page: "grant" on consent, "revoke" when withdrawn (stops it at once).
			if (typeof window.fbq === 'function') {
				try { window.fbq('consent', fbqConsentFor(accepted)); } catch (e) {}
			}
			if (typeof window.gtag !== 'function') { return; }
			var signals = signalsFor(cfg.categories, accepted);
			try { window.gtag('consent', 'update', signals); } catch (e) {}
		}

		function logConsent(payload) {
			// No-op in Phase 1 — Phase 2 adds the rt.md POST.
		}

		// True while the banner shown on page load is still waiting for the first
		// decision; only that decision may trigger reloadOnDecision.
		var firstDecision = false;
		// The onConsent / whenAllowed listeners (B7.4), told by notify().
		var apiSubs = subscribers();
		// A stored decision for the current policy version.
		function decided() { return !!storedCategories(readCookie(), VERSION); }
		// The one place the API listeners hear of a change: every save (banner, panel,
		// GPC, the API itself) and the "Always load" choices, once there is a decision.
		function notify() { if (decided()) { apiSubs.emit(window.CMP.getConsent()); } }

		function save(accepted, decision) {
			var reload = firstDecision && cfg.reloadOnDecision;
			firstDecision = false;
			// B4: the services of the accepted categories at this decision, so a service
			// added later can be detected. None when the map is unknown.
			var snap = svcSnapshot(accepted, cfg.services);
			var data = { v: VERSION, id: consentIdFor(readCookie(), (readSvc() || {}).id), categories: accepted, decision: decision, ts: Math.floor(Date.now() / 1000) };
			if (snap !== null) { data.svc = snap; }
			writeCookie(data);
			showReopen();
			pushConsent(accepted);
			unblockScripts(accepted);
			unblockEmbeds(accepted);
			document.dispatchEvent(new CustomEvent('cmp:consent', { detail: { categories: accepted, decision: decision } }));
			logConsent({ categories: accepted, decision: decision });
			// After the log, so a save made by a listener is logged after this one.
			notify();
			hide();
			// Load-time animations ran behind the overlay; reload so they play visibly.
			if (reload) { window.location.reload(); }
		}

		// The visitor's categories for the current policy version, minus those that
		// gained a service since the decision (they count as refused until the
		// visitor decides again). The cookie is rewritten only then.
		function consentedCategories() {
			var c = readCookie();
			return (c && String(c.v) === String(VERSION)) ? effectiveCategories(c, cfg.services, requiredSlugs()) : [];
		}

		function selectedFromUI() {
			// Required categories have no toggle in the DOM — always include them.
			var out = requiredSlugs();
			banner.querySelectorAll('[data-cmp-cat]').forEach(function (b) {
				if (b.checked && out.indexOf(b.value) === -1) { out.push(b.value); }
			});
			return out;
		}

		// Policy pages and backdrop "none": the dialog says aria-modal="false" (one
		// signal, set by renderPro): no scroll lock and no focus trap.
		function blocking() { return !!banner.querySelector('[aria-modal="true"]'); }
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
			if (showT) { clearTimeout(showT); showT = 0; }
			var wasHidden = banner.hidden;
			applySiteTheme(banner, A, ownDark, document);
			hideReopen();
			banner.hidden = false;
			if (blocking()) {
				// The scrollbar goes away under the lock: the body takes its width as extra
				// padding, so the page does not shift (CLS). Measured only when it appears.
				if (wasHidden) { var de = document.documentElement, cs = getComputedStyle(de); de.style.setProperty('--cmp-sbw', lockPad(window.innerWidth, de.clientWidth, cs.overflowY, cs.scrollbarGutter, getComputedStyle(document.body).paddingRight)); }
				document.body.classList.add('cmp-has-banner');
			}
			// Escape works on every banner; the Tab trap only where it blocks the page.
			document.addEventListener('keydown', onKeydown);
			window.addEventListener('resize', scrollPad);
			scrollPad();
			// Trap (and remember the focus to restore) only when it appears; already on
			// screen (openPreferences while it shows), just focus the view.
			if (blocking() && wasHidden) { trapFocus(true); } else if (!wasHidden || prefsOpen()) { focusDialog(); }
			// B6.18: check the two decisions 400 ms after a drawn frame (the 0.35 s opening
			// animation has started; hidden tabs draw nothing, so nothing is judged there).
			requestAnimationFrame(function () { setTimeout(function () { runGuard(banner, window); }, 400); });
		}
		function hide() {
			if (!banner) { return; }
			// The guard's inline display:flex (runGuard) would keep it on screen.
			banner.style.removeProperty('display');
			// A decision during the display delay (API, check mode): no later auto-open.
			if (showT) { clearTimeout(showT); showT = 0; }
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
		function syncToggles() {
			var c = readCookie();
			// A category that gained a service starts OFF too (re-consent preselect).
			var accepted = consentedCategories();
			banner.querySelectorAll('[data-cmp-cat]').forEach(function (b) { b.checked = accepted.indexOf(b.value) !== -1; });
			showConsentId(c);
		}
		// Show the visitor their consent ID (to quote it in a request about their data).
		function showConsentId(c) {
			var el = banner.querySelector('[data-cmp-consent-id]');
			if (!el) { return; }
			var id = c && typeof c.id === 'string' ? c.id : '';
			el.hidden = !id;
			var code = el.querySelector('code');
			if (code) { code.textContent = id; }
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
			// B6.18: the Reject / Save pair now on screen.
			runGuard(banner, window);
			// Its height changed: the policy page's scroll padding follows.
			scrollPad();
		}

		// --- Re-consent (B4) ---
		var NO_RECONSENT = { show: false, newIds: {}, preselect: [] };
		function pendingReconsent() {
			var c = readCookie();
			if (!c || String(c.v) !== String(VERSION)) { return NO_RECONSENT; }
			var view = reconsentView(c, cfg.services, requiredSlugs());
			var optional = cfg.categories.filter(function (x) { return !x.required; }).map(function (x) { return x.slug; });
			// A category the banner cannot show could never be confirmed: do not ask forever.
			var shown = Object.keys(view.newIds).some(function (slug) { return optional.indexOf(slug) !== -1; });
			return shown ? view : NO_RECONSENT;
		}
		function svcLabel(id) {
			if (cfg.serviceNames && typeof cfg.serviceNames[id] === 'string' && cfg.serviceNames[id]) { return cfg.serviceNames[id]; }
			return id;
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
			// the reason. The decision buttons stay.
			banner.querySelectorAll('[data-cmp-view="prefs"] [data-cmp-action="back"]').forEach(function (b) { b.hidden = on; });
			var m = modal();
			if (m) {
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
			if (!banner) { return; }
			banner.addEventListener('click', function (e) {
				var rm = e.target.closest('[data-cmp-svc-remove]');
				if (rm) { withdrawService(rm.getAttribute('data-cmp-svc-remove')); return; }
				var btn = e.target.closest('[data-cmp-action]');
				if (!btn) { return; }
				var action = btn.getAttribute('data-cmp-action');
				if (action === 'accept') { save(cfg.categories.map(function (c) { return c.slug; }), 'accepted_all'); }
				else if (action === 'reject') { save(requiredSlugs(), 'rejected_all'); rejectServices(); }
				else if (action === 'prefs') { openPrefs(); }
				else if (action === 'back') { goBack(); }
				else if (action === 'details') { details(); scrollPad(); }
				else if (action === 'save') {
					var sel = selectedFromUI();
					var decision = decisionFor(sel, cfg.categories);
					save(sel, decision);
				}
			});
		}

		function initLight() {
			var bar = document.querySelector('[data-cmp-light]');
			if (!bar) { return; }
			try { if (localStorage.getItem('cmp_light_dismissed')) { return; } } catch (e) {}
			// B6: after the display delay; theme "like the site".
			var own = bar.classList.contains('cmp--dark'), ms = delayMs(A.delay);
			var open = function () { applySiteTheme(bar, A, own, document); bar.hidden = false; };
			if (ms) { setTimeout(open, ms); } else { open(); }
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

		// Third-party embeds (YouTube, Vimeo, Maps): the original iframe waits in an
		// inert <template> inside [data-cmp-embed] and takes the placeholder's place once
		// its category is accepted, or when the visitor clicks "load" on it.
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
				if (embedLoads(box.getAttribute('data-cmp-embed'), accepted)) { loadEmbed(box); }
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
				if (!btn || cfg.mode === 'light') { return; }
				e.preventDefault();
				allowService(btn.closest('[data-cmp-embed]'));
			});
		}

		// On page load: placeholders of an "always loaded" service load whatever their category.
		function unblockServices() {
			var allowed = svcAllowed(readSvc(), VERSION);
			document.querySelectorAll('[data-cmp-embed][data-cmp-service]').forEach(function (box) {
				if (allowed.indexOf(box.getAttribute('data-cmp-service')) !== -1) { loadEmbed(box); }
			});
		}
		// "Always load": load this embed and every other one of the same service, and
		// remember the choice. The banner and the category consent are left alone.
		// (This build keeps no consent log, so nothing is logged.)
		function allowService(box) {
			var id = box && box.getAttribute('data-cmp-service');
			if (!id || !SVC_ID_RE.test(id)) { return; }
			var same = document.querySelectorAll('[data-cmp-embed][data-cmp-service="' + id + '"]');
			for (var i = 0; i < same.length; i++) { loadEmbed(same[i]); }
			var next = withSvc(readSvc(), VERSION, id, true);
			if (!next.id) { next.id = makeConsentId(); }
			writeSvc(next);
			logConsent(svcLogPayload('service_allow', id, [], next.id));
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
			var next = withSvc(readSvc(), VERSION, id, false);
			writeSvc(next);
			logConsent(svcLogPayload('service_withdraw', id, [], next.id));
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
		// The explicit "reject" also withdraws every "always load" choice. A partial
		// save and GPC do not. Embeds already loaded stay until the next load.
		// (This build keeps no consent log, so nothing is logged.)
		function rejectServices() {
			var cur = readSvc();
			if (!cur) { return; }
			writeSvc(svcAfterReject(cur, VERSION));
			renderAlways();
		}
		function serviceName(id) {
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
			var ids = svcAllowed(readSvc(), VERSION);
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

		function setConsentModeDefaults() {
			window.dataLayer = window.dataLayer || [];
			if (typeof window.gtag !== 'function') {
				window.gtag = function () { window.dataLayer.push(arguments); };
			}
			window.gtag('consent', 'default', {
				ad_storage: 'denied',
				ad_user_data: 'denied',
				ad_personalization: 'denied',
				analytics_storage: 'denied',
				wait_for_update: 500
			});
		}

		document.addEventListener('DOMContentLoaded', function () {
			cfg = normalizeConfig(window.CMP_CONFIG || {});
			setConsentModeDefaults();
			A = cfg.appearance || {};
			renderAppearanceCss(cfg);

			if (cfg.mode === 'light') {
				renderLight(cfg);
				banner = document.querySelector('[data-cmp-light]');
			} else {
				renderPro(cfg);
				banner = document.querySelector('[data-cmp]');
				ownDark = !!banner && banner.classList.contains('cmp--dark');
				bind();
				reopenBtn = renderReopen(cfg);
			}

			window.CMP = {
				version: PKG_VERSION,
				isAllowed: function (slug) {
					var c = readCookie();
					var cat = (cfg.categories || []).filter(function (x) { return x.slug === slug; })[0];
					if (cat && cat.required) { return true; }
					if (!c || String(c.v) !== String(VERSION)) { return false; }
					return consentedCategories().indexOf(slug) !== -1;
				},
				getConsent: function () {
					var c = readCookie();
					if (!c || typeof c !== 'object' || !Array.isArray(c.categories)) { return c; }
					var out = {};
					Object.keys(c).forEach(function (k) { out[k] = c[k]; });
					out.categories = effectiveCategories(c, cfg.services, requiredSlugs());
					return out;
				},
				openPreferences: function () {
					if (cfg.mode === 'light') { return; }
					// Prefs first, so the dialog has its final name when show() focuses it.
					openPrefs();
					show();
				}
			};
			(function (m) { for (var k in m) { if (Object.prototype.hasOwnProperty.call(m, k)) { window.CMP[k] = m[k]; } } })(apiMembers({
				light: function () { return cfg.mode === 'light'; },
				cat: function (slug) { return (cfg.categories || []).filter(function (x) { return x.slug === slug; })[0]; },
				required: requiredSlugs,
				isAllowed: function (slug) { return window.CMP.isAllowed(slug); },
				decided: decided,
				current: consentedCategories,
				consent: function () { return window.CMP.getConsent(); },
				gesture: function () { return gestureOk(window.navigator, window.event); },
				save: save
			}, apiSubs));

			// Any "cookie settings" control reopens the preferences: page-provided
			// [data-cmp-settings] / .cmp-settings-link / .cmp-reopen, a link to
			// #cmp-settings, or the optional floating button. Delegated, so elements
			// added later work too.
			document.addEventListener('click', function (e) {
				var el = e.target.closest && e.target.closest('[data-cmp-settings], .cmp-settings-link, .cmp-cookie-settings-link, .cmp-reopen, a[href$="#cmp-settings"]');
				if (!el) { return; }
				e.preventDefault();
				window.CMP.openPreferences();
			});

			bindEmbeds();
			if (cfg.mode === 'light') {
				// Light has no preferences panel: drop the placeholders' settings link.
				document.querySelectorAll('[data-cmp-embed] [data-cmp-settings]').forEach(function (a) { a.parentNode.removeChild(a); });
				// Light stores no cookie, so there is no "Always load" either.
				stripAlwaysButtons(document);
				initLight();
				return;
			}

			unblockServices();
			// B4: a cookie without `svc` adopts the current services silently (see backfillSvc).
			if (storedCategories(readCookie(), VERSION)) {
				// Written with the remaining lifetime, so the consent duration is not restarted.
				var adopted = backfillSvc(readCookie(), cfg.services);
				var left = adopted ? remainingMaxAge(adopted, cfg.cookieDays, Math.floor(Date.now() / 1000)) : 0;
				if (adopted && left > 0) { writeCookie(adopted, left); }
			}
			// B4: a category that gained a service stays refused until the visitor decides again.
			var stored = storedCategories(readCookie(), VERSION) ? consentedCategories() : null;
			if (stored) {
				// Replay the stored consent to Consent Mode before the scripts come
				// back, as save() does; otherwise the signals stay on the defaults.
				pushConsent(stored);
				unblockScripts(stored);
				unblockEmbeds(stored);
				if (pendingReconsent().show) {
					// Re-consent: like a first visit (overlay, none on the policy page),
					// straight on the preferences, on every page until the decision.
					// No "decide later": it would be tacit consent to the new service.
					firstDecision = true;
					openPrefs();
					autoShow();
					return;
				}
				showReopen();
				return;
			}
			if (cfg.respectGPC && gpcOn(navigator)) {
				save(requiredSlugs(), 'rejected_all');
			} else {
				firstDecision = true;
				autoShow();
			}
		});
	}

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = { parseSvcCookie: parseSvcCookie, svcLogPayload: svcLogPayload, fillName: fillName, svcAllowed: svcAllowed, stripAlwaysButtons: stripAlwaysButtons, withSvc: withSvc, svcAfterReject: svcAfterReject, CMP_DEFAULTS: CMP_DEFAULTS, normalizeConfig: normalizeConfig, signalsFor: signalsFor, fbqConsentFor: fbqConsentFor, embedLoads: embedLoads, gpcOn: gpcOn, isPolicyPage: isPolicyPage, consentIdFor: consentIdFor, decisionFor: decisionFor, isToggleOn: isToggleOn, storedCategories: storedCategories, serializeConsent: serializeConsent, parseConsent: parseConsent, escapeHtml: escapeHtml, safeUrl: safeUrl, svcSnapshot: svcSnapshot, newServices: newServices, effectiveCategories: effectiveCategories, reconsentView: reconsentView, backfillSvc: backfillSvc, remainingMaxAge: remainingMaxAge, svcFlat: svcFlat, nameMap: nameMap, normAppearance: normAppearance, appearanceClasses: appearanceClasses, appearanceCss: appearanceCss, delayMs: delayMs };
	}
})();
