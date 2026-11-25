/* BiscuitWP, biscuitwp.rt.md: the live demo (/demo/, and /demo/en/).
   Sets window.CMP_CONFIG for the standalone banner (cmp.min.js, loaded next, with
   defer) from ?style=minimal|brand|friendly, or from ?c= (the panel of the landing page,
   panel.js: { lang, appearance }, validated by cmp.js). The demo loads no trackers.
   Its only cookie, cmp_consent, is strictly necessary and kept on /demo/ only:
   cmp.js writes it with path=/, so writes from this page are moved to /demo/. */
(function () {
	'use strict';

	var d = document;
	var PATH = '/demo/';
	var m = /[?&]style=(minimal|brand|friendly)(?:&|$)/.exec(window.location.search);
	var style = m ? m[1] : 'brand';
	var lang = d.documentElement.lang === 'en' ? 'en' : 'ro';

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
		privacyUrl: { ro: '/confidentialitate/', en: '/en/privacy/' }[lang],
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
	// The choices of the panel: always a fresh banner, and no focus taken from the panel
	// until the visitor does something here. A picture only from our own icons.
	var c = null, mc = /[?&]c=([^&]+)/.exec(window.location.search);
	if (mc) { try { c = JSON.parse(decodeURIComponent(mc[1])); } catch (e3) { c = null; } }
	if (c && typeof c === 'object' && c.appearance && typeof c.appearance === 'object') {
		var a0 = c.appearance;
		if (a0.image && !(typeof a0.image.src === 'string' && /^\/assets\/icon\/[a-z0-9-]+\.webp$/.test(a0.image.src))) { delete a0.image; }
		cfg.appearance = a0;
		cfg.lang = c.lang === 'en' || c.lang === 'ru' ? c.lang : 'ro';
		cfg.reopenButton = typeof a0.reopen === 'string' && a0.reopen !== 'none';
		d.documentElement.className += ' is-stage';
		write('cmp_consent=; path=' + PATH + '; max-age=0; SameSite=Lax');
		var focus = window.HTMLElement.prototype.focus, used = false;
		['pointerdown', 'keydown'].forEach(function (t) { window.addEventListener(t, function () { used = true; }, true); });
		window.HTMLElement.prototype.focus = function () { if (used) { focus.apply(this, arguments); } };
	}
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
