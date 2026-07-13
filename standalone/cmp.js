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
					locked: 'Activat permanent'
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
					locked: 'Всегда включено'
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
					locked: 'Always active'
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

		return {
			site: typeof raw.site === 'string' ? raw.site : '',
			mode: mode,
			preset: typeof raw.preset === 'string' ? raw.preset : 'default',
			position: typeof raw.position === 'string' ? raw.position : 'modal-center',
			lang: lang,
			privacyUrl: typeof raw.privacyUrl === 'string' ? raw.privacyUrl : '',
			respectGPC: !!raw.respectGPC,
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

	function decisionFor(selected, cats) {
		selected = selected || [];
		var optional = (cats || []).filter(function (c) { return !c.required; });
		var selectedOptional = optional.filter(function (c) { return selected.indexOf(c.slug) !== -1; });

		if (optional.length > 0 && selectedOptional.length === optional.length) {
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

	if (typeof document !== 'undefined') {
		// Browser DOM/controller section — added in a later task.
	}

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = { CMP_DEFAULTS: CMP_DEFAULTS, normalizeConfig: normalizeConfig, signalsFor: signalsFor, decisionFor: decisionFor, serializeConsent: serializeConsent, parseConsent: parseConsent, escapeHtml: escapeHtml };
	}
})();
