/*!
 * Consent Manager Pro — standalone bundle
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
					intro: 'Folosim cookie-uri esențiale pentru funcționarea site-ului și, cu acordul dumneavoastră, cookie-uri de analiză pentru a-l îmbunătăți. Puteți accepta sau refuza cookie-urile opționale.',
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
					svc_new: 'nou'
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
					svc_new: 'новый'
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
					svc_new: 'new'
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
	// focusTrap:end

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

	if (typeof document !== 'undefined') {

		var COOKIE_NAME = 'cmp_consent';
		var VERSION = '1';

		function renderPro(cfg) {
			var root = document.createElement('div');
			root.id = 'cmp-overlay';
			var noOverlay = isPolicyPage(cfg.privacyUrl, window.location.href);
			root.className = 'cmp cmp--' + cfg.preset + ' cmp--pos-' + cfg.position + (noOverlay ? ' cmp--no-overlay' : '');
			root.setAttribute('data-cmp', '');
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
					control = '<span class="cmp__cat-locked" aria-label="' + escapeHtml(cfg.texts.locked || 'Always active') + '">' +
						'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>' +
						'</span>';
				} else {
					control = '<label class="cmp__toggle" for="' + escapeHtml(id) + '">' +
						'<input type="checkbox" id="' + escapeHtml(id) + '" value="' + escapeHtml(cat.slug) + '" data-cmp-cat>' +
						'<span class="cmp__toggle-slider"></span>' +
						'</label>';
				}
				catsHtml += '<div class="cmp__cat">' +
					'<div class="cmp__cat-body">' +
					'<div class="cmp__cat-head">' +
					'<span class="cmp__cat-label">' + escapeHtml(cat.label) + '</span>' + badge + newBadge +
					'</div>' +
					'<div class="cmp__cat-desc">' + escapeHtml(cat.description) + '</div>' +
					'</div>' + control +
					'</div>';
			});

			root.innerHTML =
				'<div class="cmp__modal" role="dialog" aria-modal="' + (noOverlay ? 'false' : 'true') + '" aria-labelledby="cmp-title" tabindex="-1" data-cmp-modal>' +
				'<div class="cmp__main" data-cmp-view="main">' +
				'<h2 id="cmp-title" class="cmp__title">' + escapeHtml(cfg.texts.title) + '</h2>' +
				'<p class="cmp__text">' + escapeHtml(cfg.texts.intro) + privacyLink + '</p>' +
				'<div class="cmp__actions">' +
				'<button type="button" class="cmp__btn cmp__btn--ghost" data-cmp-action="prefs">' + escapeHtml(cfg.texts.prefs) + '</button>' +
				'<span class="cmp__spacer" aria-hidden="true"></span>' +
				'<span class="cmp__choices">' +
				'<button type="button" class="cmp__btn cmp__btn--primary" data-cmp-action="reject">' + escapeHtml(cfg.texts.reject) + '</button>' +
				'<button type="button" class="cmp__btn cmp__btn--primary" data-cmp-action="accept">' + escapeHtml(cfg.texts.accept) + '</button>' +
				'</span>' +
				'</div>' +
				'</div>' +
				'<div class="cmp__prefs" data-cmp-view="prefs">' +
				'<div class="cmp__prefs-header">' +
				'<h2 id="cmp-prefs-title" class="cmp__title" data-cmp-prefs-title data-cmp-reconsent-title="' + escapeHtml(cfg.texts.reconsent_title || '') + '">' + escapeHtml(cfg.texts.prefs_title) + '</h2>' +
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
			root.className = 'cmp-light cmp--' + cfg.preset;
			root.setAttribute('data-cmp-light', '');
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

		/** Floating round cookie-settings button (pro mode); shown after a decision. */
		function renderReopen(c) {
			if (!c.reopenButton) { return null; }
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'cmp-reopen';
			b.setAttribute('data-cmp-reopen', '');
			b.hidden = true;
			b.setAttribute('aria-label', c.texts.reopen || 'Cookie settings');
			b.setAttribute('title', c.texts.reopen || 'Cookie settings');
			b.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5Z"/><circle cx="9.5" cy="10.5" r="1"/><circle cx="14.5" cy="14.5" r="1"/><circle cx="9" cy="15" r="1"/></svg>';
			document.body.appendChild(b);
			return b;
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

		function writeCookie(obj) {
			var v = encodeURIComponent(serializeConsent(obj));
			var maxAge = 60 * 60 * 24 * (cfg.cookieDays || 180);
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

		// Policy page (.cmp--no-overlay): no scroll lock and no focus trap.
		function blocking() { return !banner.classList.contains('cmp--no-overlay'); }
		function show() {
			if (!banner) { return; }
			hideReopen();
			banner.hidden = false;
			if (blocking()) { document.body.classList.add('cmp-has-banner'); }
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
		// The controls of the view on screen (main or prefs) that are rendered: the
		// other view is display:none, and so are hidden rows / buttons.
		function focusable() {
			var m = modal();
			var scope = banner.querySelector(m && m.classList.contains('is-prefs-open') ? '[data-cmp-view="prefs"]' : '[data-cmp-view="main"]') || banner;
			return Array.prototype.filter.call(scope.querySelectorAll('a[href], button:not([disabled]), input:not([disabled])'), function (el) {
				return el.getClientRects().length > 0;
			});
		}
		function onKeydown(e) {
			if (e.key !== 'Tab') { return; }
			var target = trapStep(focusable(), document.activeElement, e.shiftKey, modal());
			if (target) { e.preventDefault(); target.focus(); }
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
		function closePrefs() { var m = modal(); if (m) { m.classList.remove('is-prefs-open'); m.setAttribute('aria-labelledby', 'cmp-title'); m.removeAttribute('aria-describedby'); } }

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
				else if (action === 'back') { closePrefs(); }
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

		// Third-party embeds (YouTube, Vimeo, Maps): the original iframe waits in an
		// inert <template> inside [data-cmp-embed] and replaces the placeholder once
		// its category is accepted, or when the visitor clicks "load" on it.
		function loadEmbed(box) {
			var tpl = box && box.querySelector('template[data-cmp-embed-src]');
			if (!tpl || !tpl.content || !box.parentNode) { return; }
			box.parentNode.replaceChild(tpl.content.cloneNode(true), box);
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

			if (cfg.mode === 'light') {
				renderLight(cfg);
				banner = document.querySelector('[data-cmp-light]');
			} else {
				renderPro(cfg);
				banner = document.querySelector('[data-cmp]');
				bind();
				reopenBtn = renderReopen(cfg);
			}

			window.CMP = {
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
					show();
					return;
				}
				showReopen();
				return;
			}
			if (cfg.respectGPC && gpcOn(navigator)) {
				save(requiredSlugs(), 'rejected_all');
			} else {
				firstDecision = true;
				show();
			}
		});
	}

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = { parseSvcCookie: parseSvcCookie, svcLogPayload: svcLogPayload, fillName: fillName, svcAllowed: svcAllowed, stripAlwaysButtons: stripAlwaysButtons, withSvc: withSvc, svcAfterReject: svcAfterReject, CMP_DEFAULTS: CMP_DEFAULTS, normalizeConfig: normalizeConfig, signalsFor: signalsFor, fbqConsentFor: fbqConsentFor, embedLoads: embedLoads, gpcOn: gpcOn, isPolicyPage: isPolicyPage, consentIdFor: consentIdFor, decisionFor: decisionFor, isToggleOn: isToggleOn, storedCategories: storedCategories, serializeConsent: serializeConsent, parseConsent: parseConsent, escapeHtml: escapeHtml, safeUrl: safeUrl, svcSnapshot: svcSnapshot, newServices: newServices, effectiveCategories: effectiveCategories, reconsentView: reconsentView, svcFlat: svcFlat, nameMap: nameMap };
	}
})();
