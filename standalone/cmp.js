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
					reopen: 'Setări cookie'
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
					reopen: 'Настройки cookie'
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
					reopen: 'Cookie settings'
				}
			}
		}
	};

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
		if (!(cd >= 1 && cd <= 3650)) { cd = 180; }

		return {
			site: typeof raw.site === 'string' ? raw.site : '',
			mode: mode,
			preset: typeof raw.preset === 'string' ? raw.preset : 'default',
			position: typeof raw.position === 'string' ? raw.position : 'modal-center',
			lang: lang,
			privacyUrl: safeUrl(raw.privacyUrl),
			respectGPC: !!raw.respectGPC,
			reopenButton: true === raw.reopenButton,
			cookieDays: cd,
			categories: categories,
			texts: texts
		};
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
			root.className = 'cmp cmp--' + cfg.preset + ' cmp--pos-' + cfg.position;
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
					'<span class="cmp__cat-label">' + escapeHtml(cat.label) + '</span>' + badge +
					'</div>' +
					'<div class="cmp__cat-desc">' + escapeHtml(cat.description) + '</div>' +
					'</div>' + control +
					'</div>';
			});

			root.innerHTML =
				'<div class="cmp__modal" role="dialog" aria-modal="true" aria-labelledby="cmp-title" tabindex="-1" data-cmp-modal>' +
				'<div class="cmp__main" data-cmp-view="main">' +
				'<h2 id="cmp-title" class="cmp__title">' + escapeHtml(cfg.texts.title) + '</h2>' +
				'<p class="cmp__text">' + escapeHtml(cfg.texts.intro) + privacyLink + '</p>' +
				'<div class="cmp__actions">' +
				'<button type="button" class="cmp__btn cmp__btn--ghost" data-cmp-action="prefs">' + escapeHtml(cfg.texts.prefs) + '</button>' +
				'<span class="cmp__spacer" aria-hidden="true"></span>' +
				'<button type="button" class="cmp__btn cmp__btn--outline" data-cmp-action="reject">' + escapeHtml(cfg.texts.reject) + '</button>' +
				'<button type="button" class="cmp__btn cmp__btn--primary" data-cmp-action="accept">' + escapeHtml(cfg.texts.accept) + '</button>' +
				'</div>' +
				'</div>' +
				'<div class="cmp__prefs" data-cmp-view="prefs">' +
				'<div class="cmp__prefs-header">' +
				'<h2 class="cmp__title">' + escapeHtml(cfg.texts.prefs_title) + '</h2>' +
				'<button type="button" class="cmp__collapse" data-cmp-action="back" aria-label="' + escapeHtml(cfg.texts.back) + '">' +
				'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 15 12 9 18 15"></polyline></svg>' +
				'</button>' +
				'</div>' +
				'<div class="cmp__cats">' + catsHtml + '</div>' +
				'<div class="cmp__prefs-actions">' +
				'<button type="button" class="cmp__btn cmp__btn--outline" data-cmp-action="reject">' + escapeHtml(cfg.texts.reject) + '</button>' +
				'<button type="button" class="cmp__btn cmp__btn--primary" data-cmp-action="save">' + escapeHtml(cfg.texts.save) + '</button>' +
				'</div>' +
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
			return parseConsent(decodeURIComponent(m[1]));
		}

		function writeCookie(obj) {
			var v = encodeURIComponent(serializeConsent(obj));
			var maxAge = 60 * 60 * 24 * (cfg.cookieDays || 180);
			document.cookie = COOKIE_NAME + '=' + v + ';path=/;max-age=' + maxAge + ';SameSite=Lax';
		}

		function pushConsent(accepted) {
			if (typeof window.gtag !== 'function') { return; }
			var signals = signalsFor(cfg.categories, accepted);
			try { window.gtag('consent', 'update', signals); } catch (e) {}
		}

		function logConsent(payload) {
			// No-op in Phase 1 — Phase 2 adds the rt.md POST.
		}

		function save(accepted, decision) {
			var data = { v: VERSION, categories: accepted, decision: decision, ts: Math.floor(Date.now() / 1000) };
			writeCookie(data);
			showReopen();
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
			hideReopen();
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
		function syncToggles() {
			var c = readCookie();
			banner.querySelectorAll('[data-cmp-cat]').forEach(function (b) { b.checked = isToggleOn(b.value, c, VERSION); });
		}
		function openPrefs() { var m = modal(); if (m) { syncToggles(); m.classList.add('is-prefs-open'); } }
		function closePrefs() { var m = modal(); if (m) { m.classList.remove('is-prefs-open'); } }

		function bind() {
			if (!banner) { return; }
			banner.addEventListener('click', function (e) {
				var btn = e.target.closest('[data-cmp-action]');
				if (!btn) { return; }
				var action = btn.getAttribute('data-cmp-action');
				if (action === 'accept') { save(cfg.categories.map(function (c) { return c.slug; }), 'accepted_all'); }
				else if (action === 'reject') { save(requiredSlugs(), 'rejected_all'); }
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
					return (c.categories || []).indexOf(slug) !== -1;
				},
				getConsent: function () { return readCookie(); },
				openPreferences: function () {
					if (cfg.mode === 'light') { return; }
					show();
					openPrefs();
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

			if (cfg.mode === 'light') {
				initLight();
				return;
			}

			var stored = readCookie();
			if (stored && String(stored.v) === String(VERSION)) {
				unblockScripts(stored.categories || []);
				showReopen();
				return;
			}
			if (cfg.respectGPC && (navigator.globalPrivacyControl === true || navigator.doNotTrack === '1')) {
				save(requiredSlugs(), 'rejected_all');
			} else {
				show();
			}
		});
	}

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = { CMP_DEFAULTS: CMP_DEFAULTS, normalizeConfig: normalizeConfig, signalsFor: signalsFor, decisionFor: decisionFor, isToggleOn: isToggleOn, serializeConsent: serializeConsent, parseConsent: parseConsent, escapeHtml: escapeHtml, safeUrl: safeUrl };
	}
})();
