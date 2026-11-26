/*
 * "Aspect" admin screen (B6). ES5. Loaded only on that screen, with
 * CMP_APPEARANCE from CMP_Appearance_Page::script_data():
 *   styles   - each style's values (CMP_Appearance::defaults, STYLE_KEYS);
 *   bg       - each palette's background (CMP_Appearance::PALETTE_BG);
 *   minRatio - the WCAG threshold (4.5);
 *   i18n     - texts.
 *
 * - Style switch: confirm when the current values differ from the old style's
 *   (the admin's adjustments would be lost), then load the new style's values.
 * - Accent: live contrast with the same formula as CMP_Appearance::accent_suggestion
 *   (CMP_Contrast WCAG ratio; HSL lightness search); "use the proposed shade".
 * - Theme "Ca site-ul": show the selector field. Format: its own position list.
 *   Backdrop: show dim / blur. Texts: RO/RU/EN tabs.
 * - Light mode: the fields that do not apply are disabled (also server-side).
 * - "Vezi pe site" (CMP_Preview): the unsaved form goes to the per-user
 *   preview transient (AJAX), then the home page opens with ?cmp_preview.
 *
 * The pure functions are exported for node (standalone/tests/run.js checks
 * them against tests/fixtures/accent-parity.json, the PHP output).
 */
(function () {
	'use strict';

	// --- Pure: WCAG contrast (port of CMP_Contrast) and the accent suggestion. ---

	function hex6(v) {
		if (typeof v !== 'string') { return ''; }
		var h = v.replace(/^\s+|\s+$/g, '');
		if (!/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i.test(h)) { return ''; }
		h = h.slice(1).toLowerCase();
		if (h.length === 3) { h = h.charAt(0) + h.charAt(0) + h.charAt(1) + h.charAt(1) + h.charAt(2) + h.charAt(2); }
		return '#' + h;
	}

	function rgb(hex) {
		hex = hex6(hex);
		if (!hex) { return [0, 0, 0]; }
		return [parseInt(hex.substr(1, 2), 16), parseInt(hex.substr(3, 2), 16), parseInt(hex.substr(5, 2), 16)];
	}

	function luminance(hex) {
		var c = rgb(hex), l = [], i, s;
		for (i = 0; i < 3; i++) {
			s = c[i] / 255;
			l.push(s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4));
		}
		return 0.2126 * l[0] + 0.7152 * l[1] + 0.0722 * l[2];
	}

	function ratio(a, b) {
		if (!hex6(a) || !hex6(b)) { return 0; }
		var l1 = luminance(a), l2 = luminance(b);
		return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
	}

	function hsl(hex) {
		var c = rgb(hex), r = c[0] / 255, g = c[1] / 255, b = c[2] / 255;
		var max = Math.max(r, g, b), min = Math.min(r, g, b), l = (max + min) / 2, h = 0, s = 0, d = max - min;
		if (d > 0) {
			s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
			if (max === r) {
				h = (g - b) / d + (g < b ? 6 : 0);
			} else if (max === g) {
				h = (b - r) / d + 2;
			} else {
				h = (r - g) / d + 4;
			}
			h *= 60;
		}
		return [h, s * 100, l * 100];
	}

	function fromHsl(h, s, l) {
		s /= 100;
		l /= 100;
		var c = (1 - Math.abs(2 * l - 1)) * s;
		var x = c * (1 - Math.abs((h / 60) % 2 - 1));
		var m = l - c / 2;
		var seg = Math.floor(h / 60) % 6;
		var t = [[c, x, 0], [x, c, 0], [0, c, x], [0, x, c], [x, 0, c], [c, 0, x]][seg];
		var out = '#', i, v;
		for (i = 0; i < 3; i++) {
			v = Math.max(0, Math.min(255, Math.round((t[i] + m) * 255)));
			out += (v < 16 ? '0' : '') + v.toString(16);
		}
		return out;
	}

	function textFor(c) {
		return ratio(c, '#ffffff') >= ratio(c, '#000000') ? '#ffffff' : '#000000';
	}

	function floor2(r) {
		return Math.floor(r * 100) / 100;
	}

	// [min(text on accent, accent on bg), text colour]
	function score(c, bg, fixed) {
		var t = fixed || textFor(c);
		return [Math.min(ratio(c, t), ratio(c, bg)), t];
	}

	/** Same result as CMP_Appearance::accent_suggestion( hex, bg, text ). */
	function accentSuggestion(hex, bgHex, textHex, minRatio) {
		var min = minRatio || 4.5;
		hex = hex6(hex);
		var bg = hex6(bgHex), fixed = hex6(textHex || '');
		if (!hex || !bg) { return { ok: false, ratio: 0, suggest: '', text: '', suggest_ratio: 0 }; }
		var s0 = score(hex, bg, fixed);
		if (s0[0] >= min) {
			return { ok: true, ratio: floor2(s0[0]), suggest: hex, text: s0[1], suggest_ratio: floor2(s0[0]) };
		}
		var p = hsl(hex), i, k, l2, c, sc;
		for (i = 1; i <= 200; i++) {
			for (k = 0; k < 2; k++) {
				l2 = p[2] + (k ? 1 : -1) * i * 0.5;
				if (l2 < 0 || l2 > 100) { continue; }
				c = fromHsl(p[0], p[1], l2);
				sc = score(c, bg, fixed);
				if (sc[0] >= min) {
					return { ok: false, ratio: floor2(s0[0]), suggest: c, text: sc[1], suggest_ratio: floor2(sc[0]) };
				}
			}
		}
		var sb = score('#000000', bg, fixed), sw = score('#ffffff', bg, fixed);
		var best = sb[0] >= sw[0] ? ['#000000', sb] : ['#ffffff', sw];
		return { ok: false, ratio: floor2(s0[0]), suggest: best[0], text: best[1][1], suggest_ratio: floor2(best[1][0]) };
	}

	/** Do the form values differ from a style's values (= adjustments a switch would lose)? */
	function adjusted(values, styleDefaults) {
		var k;
		for (k in styleDefaults) {
			if (Object.prototype.hasOwnProperty.call(styleDefaults, k) && Object.prototype.hasOwnProperty.call(values, k)
				&& String(values[k]) !== String(styleDefaults[k])) {
				return true;
			}
		}
		return false;
	}

	function fmt(s, args) {
		var i = 0;
		return String(s).replace(/%(\d+\$)?s/g, function (m, pos) {
			var v = pos ? args[parseInt(pos, 10) - 1] : args[i++];
			return v === undefined ? '' : String(v);
		});
	}

	var api = { hex6: hex6, ratio: ratio, hsl: hsl, fromHsl: fromHsl, accentSuggestion: accentSuggestion, adjusted: adjusted, fmt: fmt };
	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	}
	if (typeof document === 'undefined') { return; }

	// --- The screen. ---

	function each(list, fn) {
		var i;
		for (i = 0; i < list.length; i++) { fn(list[i], i); }
	}

	function init() {
		var root = document.querySelector('[data-cmp-ap]');
		var D = window.CMP_APPEARANCE;
		if (!root || !D) { return; }
		var T = D.i18n || {};
		var form = root.querySelector('form');
		var light = root.getAttribute('data-cmp-ap-mode') === 'light';

		function field(name) { return form.querySelectorAll('[name="cmp[appearance][' + name + ']"]'); }
		function radioValue(name) {
			var v = '';
			each(field(name), function (el) { if (el.checked) { v = el.value; } });
			return v;
		}

		// Style switch.
		var styleBox = root.querySelector('[data-cmp-ap-style]');
		var current = styleBox ? styleBox.getAttribute('data-cmp-ap-style-current') : '';
		function readStyleValues() {
			var v = {};
			each(form.querySelectorAll('[data-cmp-ap-key]'), function (el) {
				var k = el.getAttribute('data-cmp-ap-key');
				if (el.type === 'radio') {
					if (el.checked) { v[k] = el.value; }
				} else {
					v[k] = el.value;
				}
			});
			return v;
		}
		function loadStyle(style) {
			var d = D.styles[style];
			if (!d) { return; }
			each(form.querySelectorAll('[data-cmp-ap-key]'), function (el) {
				var k = el.getAttribute('data-cmp-ap-key');
				if (!Object.prototype.hasOwnProperty.call(d, k)) { return; }
				if (el.type === 'radio') {
					el.checked = String(el.value) === String(d[k]);
				} else {
					el.value = d[k];
				}
				syncOut(el);
			});
		}
		if (styleBox) {
			each(styleBox.querySelectorAll('input[type="radio"]'), function (r) {
				r.addEventListener('change', function () {
					if (!r.checked || r.value === current) { return; }
					var old = D.styles[current];
					if (old && adjusted(readStyleValues(), old) && !window.confirm(T.confirmStyle || '')) {
						each(styleBox.querySelectorAll('input[type="radio"]'), function (x) { x.checked = x.value === current; });
						return;
					}
					current = r.value;
					loadStyle(current);
				});
			});
		}

		// Range outputs.
		function syncOut(el) {
			var id = el.getAttribute('data-cmp-ap-out');
			var out = id ? document.getElementById(id) : null;
			if (out) { out.textContent = el.value; }
		}
		each(form.querySelectorAll('[data-cmp-ap-out]'), function (el) {
			el.addEventListener('input', function () { syncOut(el); });
		});

		// Accent contrast.
		var accent = form.querySelector('[data-cmp-accent]');
		var status = form.querySelector('[data-cmp-accent-status]');
		var useBtn = form.querySelector('[data-cmp-accent-use]');
		var swatch = form.querySelector('[data-cmp-accent-swatch]');
		var palette = form.querySelector('[data-cmp-ap-palette]');
		var proposed = '';
		function checkAccent() {
			if (!accent || !status) { return; }
			var raw = accent.value.replace(/^\s+|\s+$/g, '');
			if (raw && raw.charAt(0) !== '#') { raw = '#' + raw; }
			var hex = hex6(raw);
			proposed = '';
			status.className = 'cmp-ap-accent__status';
			if (useBtn) { useBtn.hidden = true; }
			if (swatch) { swatch.style.background = hex || ''; }
			if (!raw) { status.textContent = ''; return; }
			if (!hex) { status.textContent = T.invalid || ''; status.className += ' is-bad'; return; }
			var bg = (palette && D.bg[palette.value]) || '#ffffff';
			var r = accentSuggestion(hex, bg, '', parseFloat(D.minRatio) || 4.5);
			if (r.ok) {
				status.textContent = '✓ ' + fmt(T.ok || '%s', [r.ratio]);
				status.className += ' is-ok';
			} else {
				status.textContent = '✗ ' + fmt(T.fail || '%1$s %2$s %3$s', [r.ratio, r.suggest, r.suggest_ratio]);
				status.className += ' is-bad';
				proposed = r.suggest;
				if (useBtn) { useBtn.hidden = false; }
			}
		}
		if (accent) {
			accent.addEventListener('input', checkAccent);
			if (palette) { palette.addEventListener('change', checkAccent); }
			if (useBtn) {
				useBtn.addEventListener('click', function () {
					if (proposed) { accent.value = proposed; accent.dispatchEvent(new Event('input', { bubbles: true })); accent.focus(); }
				});
			}
			checkAccent();
		}

		// Theme "like the site": the selector field.
		var selRow = form.querySelector('[data-cmp-ap-selector]');
		function applyTheme() {
			if (selRow) { selRow.hidden = radioValue('theme') !== 'site'; }
		}
		each(field('theme'), function (el) { el.addEventListener('change', applyTheme); });
		applyTheme();

		// Format: its own position list (the others are disabled, so not posted).
		var format = form.querySelector('[data-cmp-ap-format]');
		function applyFormat() {
			if (!format) { return; }
			each(form.querySelectorAll('[data-cmp-ap-pos]'), function (box) {
				var mine = box.getAttribute('data-cmp-ap-pos') === format.value;
				box.hidden = !mine;
				each(box.querySelectorAll('select'), function (s) { s.disabled = !mine || light; });
			});
		}
		if (format) { format.addEventListener('change', applyFormat); }
		applyFormat();

		// Backdrop: dim for blur/dim, blur for blur.
		var backdrop = form.querySelector('[data-cmp-ap-backdrop]');
		function applyBackdrop() {
			if (!backdrop) { return; }
			each(form.querySelectorAll('[data-cmp-ap-show-backdrop]'), function (el) {
				el.hidden = (' ' + el.getAttribute('data-cmp-ap-show-backdrop') + ' ').indexOf(' ' + backdrop.value + ' ') < 0;
			});
		}
		if (backdrop) { backdrop.addEventListener('change', applyBackdrop); }
		applyBackdrop();

		// Texts: RO / RU / EN tabs (all panels stay visible without JS).
		var tabs = root.querySelectorAll('[data-cmp-ap-tab]');
		function selectTab(lang, focus) {
			each(tabs, function (t) {
				var on = t.getAttribute('data-cmp-ap-tab') === lang;
				t.setAttribute('aria-selected', on ? 'true' : 'false');
				t.tabIndex = on ? 0 : -1;
				if (on && focus) { t.focus(); }
			});
			each(root.querySelectorAll('[data-cmp-ap-panel]'), function (p) {
				p.hidden = p.getAttribute('data-cmp-ap-panel') !== lang;
			});
		}
		each(tabs, function (t, i) {
			t.addEventListener('click', function () { selectTab(t.getAttribute('data-cmp-ap-tab'), false); });
			t.addEventListener('keydown', function (e) {
				var k = e.key || '', n = -1;
				if (k === 'ArrowRight') { n = (i + 1) % tabs.length; }
				if (k === 'ArrowLeft') { n = (i - 1 + tabs.length) % tabs.length; }
				if (k === 'Home') { n = 0; }
				if (k === 'End') { n = tabs.length - 1; }
				if (n >= 0) { e.preventDefault(); selectTab(tabs[n].getAttribute('data-cmp-ap-tab'), true); }
			});
		});
		if (tabs.length) { selectTab(tabs[0].getAttribute('data-cmp-ap-tab'), false); }

		// "Vezi pe site": post the unsaved form (AJAX, cmp_preview_store) and open
		// the preview link it answers in a new tab. The tab is opened on the click
		// itself (popup blockers), then pointed at the link.
		var pv = root.querySelector('[data-cmp-preview]');
		var pvStatus = root.querySelector('[data-cmp-preview-status]');
		if (pv && window.FormData && window.XMLHttpRequest) {
			pv.hidden = false;
			pv.addEventListener('click', function () {
				var win = window.open('', '_blank');
				if (win) { try { win.opener = null; } catch (e) {} }
				var fd = new FormData(form);
				if (fd.set) {
					fd.set('action', 'cmp_preview_store');
					fd.set('nonce', pv.getAttribute('data-cmp-preview-nonce') || '');
				} else {
					fd.append('action', 'cmp_preview_store');
					fd.append('nonce', pv.getAttribute('data-cmp-preview-nonce') || '');
				}
				function fail() {
					if (win) { try { win.close(); } catch (e) {} }
					if (pvStatus) { pvStatus.textContent = pv.getAttribute('data-cmp-preview-error') || ''; }
				}
				var xhr = new XMLHttpRequest();
				xhr.open('POST', pv.getAttribute('data-cmp-preview-ajax') || '', true);
				xhr.onload = function () {
					pv.disabled = false;
					var r = null;
					try { r = JSON.parse(xhr.responseText); } catch (e) {}
					var url = r && r.success && r.data && typeof r.data.url === 'string' ? r.data.url : '';
					if (xhr.status !== 200 || !url) { fail(); return; }
					if (pvStatus) { pvStatus.textContent = ''; }
					if (win) { win.location.href = url; return; }
					// The tab was blocked: a real link (a new click is a user activation).
					if (pvStatus) {
						var a = document.createElement('a');
						a.href = url;
						a.target = '_blank';
						a.rel = 'noopener';
						a.textContent = pv.getAttribute('data-cmp-preview-open') || url;
						pvStatus.appendChild(a);
					}
				};
				xhr.onerror = function () { pv.disabled = false; fail(); };
				xhr.timeout = 15000;
				xhr.ontimeout = function () { pv.disabled = false; fail(); };
				pv.disabled = true;
				xhr.send(fd);
			});
		}
	}

	// The banner picture and the logo of the settings window: show their fields,
	// choose an image from the media library.
	function initPicker(key, title) {
		var box = document.querySelector('[data-cmp-ap-' + key + ']');
		if (!box) { return; }
		var own = box.querySelector('[data-cmp-ap-' + key + '-own]'), more = box.querySelector('[data-cmp-ap-' + key + '-more]');
		var id = box.querySelector('[data-cmp-ap-' + key + '-id]'), thumb = box.querySelector('[data-cmp-ap-' + key + '-thumb]');
		var pick = box.querySelector('[data-cmp-ap-' + key + '-pick]'), frame = null;
		box.addEventListener('change', function (e) {
			if (!e.target || e.target.getAttribute('data-cmp-ap-key') !== key) { return; }
			own.hidden = e.target.value !== 'own';
			if (more) { more.hidden = e.target.value === 'none'; }
		});
		if (!window.wp || !window.wp.media) { pick.disabled = true; return; }
		pick.addEventListener('click', function () {
			var t = (window.CMP_APPEARANCE && window.CMP_APPEARANCE.i18n) || {};
			if (!frame) {
				frame = window.wp.media({ title: t[title], button: { text: t.imageUse }, library: { type: 'image' }, multiple: false });
				frame.on('select', function () {
					var m = frame.state().get('selection').first().toJSON();
					id.value = m.id;
					id.dispatchEvent(new Event('change', { bubbles: true }));
					thumb.src = m.sizes && m.sizes.thumbnail ? m.sizes.thumbnail.url : m.url;
					thumb.hidden = false;
				});
			}
			frame.open();
		});
	}

	// The live preview (#727 step 4): 300 ms after a change, the server renders the
	// banner of the unsaved form (cmp_preview_render); it goes into a frame without
	// scripts, so nothing in it can write consent. The switches drive the frame.
	function initLivePreview() {
		var root = document.querySelector('[data-cmp-live]');
		var form = document.querySelector('.cmp-ap-form');
		if (!root || !form || !window.FormData || !('srcdoc' in document.createElement('iframe'))) { return; }
		var frame = root.querySelector('[data-cmp-live-frame]'), stage = root.querySelector('[data-cmp-live-stage]');
		var status = root.querySelector('[data-cmp-live-status]'), langSel = root.querySelector('[data-cmp-live-lang]');
		var light = (document.querySelector('[data-cmp-ap]') || root).getAttribute('data-cmp-ap-mode') === 'light';
		var st = { view: 'desktop', theme: 'light', pane: 'banner' }, SIZES = { desktop: [1280, 800], phone: [375, 667] };
		var seq = 0, xhr = null, timer = null, sent = '';
		root.hidden = false;
		if (light) {
			var pg = root.querySelector('[data-cmp-live-pane]');
			if (pg && pg.parentNode) { pg.parentNode.hidden = true; }
		}

		function fit() {
			var sz = SIZES[st.view], w = (root.clientWidth - 34) || sz[0], h = Math.max(240, (window.innerHeight || 800) - 300), k = Math.min(1, w / sz[0], h / sz[1]);
			frame.style.width = sz[0] + 'px';
			frame.style.height = sz[1] + 'px';
			frame.style.transform = 'scale(' + k + ')';
			stage.style.height = Math.round(sz[1] * k) + 'px';
			stage.style.width = Math.round(sz[0] * k) + 'px';
			stage.style.maxWidth = '100%';
		}
		function apply() {
			var d = frame.contentDocument;
			if (!d || !d.body) { return; }
			var b = d.querySelector('[data-cmp], [data-cmp-light]'), m = d.querySelector('[data-cmp-modal]');
			d.body.className = st.theme === 'dark' ? 'dark' : '';
			if (!b) { return; }
			if (b.getAttribute('data-cmp-own-dark') === null) { b.setAttribute('data-cmp-own-dark', b.classList.contains('cmp--dark') ? '1' : '0'); }
			b.hidden = false;
			// As banner.js on the site: "light" never goes dark, "dark" always is; for
			// auto / site the switch decides (the computer's own mode stays out).
			var th = form.querySelector('[name*="[theme]"]:checked'), base = th ? th.value : 'light';
			var dark = base === 'light' ? false : (base === 'dark' ? true : st.theme === 'dark');
			b.classList.remove('cmp--theme-auto');
			b.classList.toggle('cmp--dark', dark || b.getAttribute('data-cmp-own-dark') === '1');
			d.body.className = dark ? 'dark' : '';
			var tg = root.querySelector('[data-cmp-live-theme]');
			if (tg && tg.parentNode) { tg.parentNode.hidden = base === 'light' || base === 'dark'; }
			if (m) { m.classList.toggle('is-prefs-open', st.pane === 'prefs' && !light); }
		}
		function page(p) {
			var lines = '';
			for (var i = 0; i < 14; i++) { lines += '<i style="width:' + (55 + (i * 37) % 40) + '%"></i>'; }
			return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
				'<link rel="stylesheet" href="' + String(p.bannerCss || '').replace(/"/g, '&quot;') + '">' + (p.css || '') +
				'<style>html,body{margin:0;min-height:100%}body{background:#eef0f3;font:16px/1.5 system-ui,sans-serif}body.dark{background:#16181d}' +
				'.fake{max-width:720px;margin:0 auto;padding:48px 24px}.fake b{display:block;height:28px;width:60%;margin:0 0 24px;background:#d5d9e0;border-radius:4px}' +
				'.fake i{display:block;height:12px;margin:0 0 14px;background:#dde1e7;border-radius:4px}.dark .fake b,.dark .fake i{background:#2a2e36}' +
				'.cmp,.cmp *,.cmp-light,.cmp-light *{animation:none!important;transition:none!important}.cmp a,.cmp-light a{pointer-events:none}</style></head>' +
				'<body><div class="fake" aria-hidden="true"><b></b>' + lines + '</div>' + String(p.html || '').replace(/\shref="[^"]*"/g, '') + '</body></html>';
		}
		function request() {
			// Folded (narrow screens): nothing to show, nothing to ask; it renders when opened.
			if (!root.open) { return; }
			var fd = new FormData(form), key = '';
			fd.set('action', 'cmp_preview_render');
			fd.set('nonce', root.getAttribute('data-cmp-live-nonce') || '');
			fd.set('lang', langSel ? langSel.value : '');
			fd.forEach(function (v, k) { key += k + '=' + (typeof v === 'string' ? v : '') + '&'; });
			// A text field fires input, then change on blur: the same form once only.
			if (key === sent) { return; }
			sent = key;
			var my = ++seq;
			if (xhr) { xhr.abort(); }
			status.textContent = root.getAttribute('data-cmp-live-busy') || '';
			xhr = new XMLHttpRequest();
			xhr.open('POST', root.getAttribute('data-cmp-live-ajax') || '', true);
			xhr.onload = function () {
				if (my !== seq) { return; }
				var r = null;
				try { r = JSON.parse(xhr.responseText); } catch (e) { r = null; }
				if (!r || !r.success || !r.data) { sent = ''; status.textContent = root.getAttribute('data-cmp-live-error') || ''; return; }
				status.textContent = '';
				frame.srcdoc = page(r.data);
			};
			xhr.onerror = function () { if (my === seq) { sent = ''; status.textContent = root.getAttribute('data-cmp-live-error') || ''; } };
			xhr.send(fd);
		}
		function later() {
			clearTimeout(timer);
			timer = setTimeout(request, 300);
		}

		frame.addEventListener('load', apply);
		form.addEventListener('input', later);
		form.addEventListener('change', later);
		if (langSel) { langSel.addEventListener('change', later); }
		root.addEventListener('toggle', function () { if (root.open) { fit(); request(); } });
		// The texts tab opened (RO / RU / EN) is the language shown.
		document.addEventListener('click', function (e) {
			var tab = e.target && e.target.closest ? e.target.closest('[data-cmp-ap-tab]') : null;
			if (tab && langSel) { langSel.value = tab.getAttribute('data-cmp-ap-tab'); later(); }
		});
		root.addEventListener('click', function (e) {
			var b = e.target && e.target.closest ? e.target.closest('.cmp-live__sw') : null;
			if (!b) { return; }
			['view', 'theme', 'pane'].forEach(function (k) {
				var v = b.getAttribute('data-cmp-live-' + k);
				if (v === null) { return; }
				st[k] = v;
				root.querySelectorAll('[data-cmp-live-' + k + ']').forEach(function (o) { o.setAttribute('aria-pressed', o === b ? 'true' : 'false'); });
			});
			fit();
			apply();
		});
		window.addEventListener('resize', fit);
		fit();
		request();
	}

	function initPickers() {
		initPicker('image', 'imageTitle');
		initPicker('logo', 'logoTitle');
		initLivePreview();
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initPickers);
	} else {
		initPickers();
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
