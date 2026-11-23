/* Consent Manager Pro, consent.rt.md: the live demo (/demo/, /demo/en/ and /demo/ru/).
   Sets window.CMP_CONFIG for the standalone banner (cmp.min.js, loaded next, with
   defer) from ?style=minimal|brand|friendly. The demo loads no trackers.
   Its only cookie, cmp_consent, is strictly necessary and kept on /demo/ only:
   cmp.js writes it with path=/, so writes from this page are moved to /demo/. */
(function () {
	'use strict';

	var d = document;
	var PATH = '/demo/';
	var m = /[?&]style=(minimal|brand|friendly)(?:&|$)/.exec(window.location.search);
	var style = m ? m[1] : 'brand';
	var lang = d.documentElement.lang === 'en' || d.documentElement.lang === 'ru' ? d.documentElement.lang : 'ro';

	// The native document.cookie accessor (Document.prototype; HTMLDocument in older Safari).
	var desc = Object.getOwnPropertyDescriptor(window.Document.prototype, 'cookie') ||
		(window.HTMLDocument && Object.getOwnPropertyDescriptor(window.HTMLDocument.prototype, 'cookie'));
	function write(v) {
		if (desc && desc.set) { desc.set.call(d, v); } else { d.cookie = v; }
	}
	var cfg = {
		mode: 'pro',
		lang: lang,
		categories: ['necessary', 'statistics', 'marketing'],
		privacyUrl: { ro: '/confidentialitate/', en: '/en/privacy/', ru: '/ru/privacy/' }[lang],
		cookieDays: 1,
		appearance: { style: style, theme: 'light', format: 'modal', position: 'center' }
	};
	// Without a way to move the cookie, the demo runs in Light mode, which sets no
	// cookie at all: nothing may ever land on path=/.
	try {
		if (!desc || !desc.get || !desc.set) { throw new Error('no cookie accessor'); }
		Object.defineProperty(d, 'cookie', {
			configurable: true,
			get: function () { return desc.get.call(d); },
			set: function (v) { desc.set.call(d, String(v).replace(/;\s*path=\/(?=\s*;|\s*$)/i, ';path=' + PATH)); }
		});
	} catch (e) { cfg.mode = 'light'; }
	window.CMP_CONFIG = cfg;

	d.addEventListener('DOMContentLoaded', function () {
		Array.prototype.forEach.call(d.querySelectorAll('[data-style]'), function (a) {
			if (a.getAttribute('data-style') === style) {
				a.setAttribute('aria-current', 'true');
			} else {
				a.removeAttribute('aria-current');
			}
		});
		var b = d.getElementById('demo-reset');
		if (b) {
			b.addEventListener('click', function () {
				write('cmp_consent=; path=' + PATH + '; max-age=0; SameSite=Lax');
				write('cmp_consent=; path=/; max-age=0; SameSite=Lax');
				try { window.localStorage.removeItem('cmp_light_dismissed'); } catch (e2) { /* Light mode only */ }
				window.location.reload();
			});
		}
	});
}());
