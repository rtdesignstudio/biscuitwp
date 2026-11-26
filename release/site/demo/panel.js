/* BiscuitWP, biscuitwp.rt.md: the options of the demo on the landing page (#752).
   Builds the panel and shows every change in the stage, an iframe of /demo/embed/ with
   the choices in ?c= (demo.js reads them, cmp.js validates each value). Without this
   script the three style links still work. No libraries, nothing from other sites. */
(function () {
	'use strict';

	var d = document;
	var sec = d.getElementById('demo');
	var host = sec && sec.querySelector('[data-demo-panel]');
	var frame = sec && sec.querySelector('iframe');
	var stage = sec && sec.querySelector('.stage');
	if (!host || !frame || !stage || !window.JSON) { return; }

	var en = d.documentElement.lang === 'en';
	var t = function (ro, e) { return en ? e : ro; };
	var BASE = frame.getAttribute('src').split('?')[0];
	// As CMP_Appearance::defaults(): what a style sets, then what is the same in all of them.
	var STYLES = {
		minimal: { radius: 4, shadow: 'none', buttons: 'outline', anim: 'fade', maxw: 640 },
		brand: { radius: 12, shadow: 'medium', buttons: 'solid', anim: 'none', maxw: 720 },
		friendly: { radius: 20, shadow: 'large', buttons: 'solid', anim: 'slide', maxw: 680 }
	};
	var SAME = { palette: 'neutral', accent: '', theme: 'light', font: 'system', format: 'modal', position: 'center', backdrop: 'blur', dim: 55, blur: 5, gap: 24, density: 'normal', delay: 0, reopen: 'none', image: 'none', side: 'right', size: 96, out: true, phone: 'small' };
	// What the demo shows first: Minimal as it is, Brand with a colour, Friendly with a picture.
	var PRESET = {
		minimal: {},
		brand: { accent: '#1d4ed8' },
		friendly: { palette: 'warm', dim: 50, accent: '#c2410c', image: 'shield', size: 120 }
	};
	var DIM = { neutral: 55, warm: 50, dark: 60, egov: 60 };
	var BG = { neutral: '#ffffff', warm: '#fbf9f4', dark: '#1e242e', egov: '#ffffff' };
	var POS = { bar: ['bottom', 'top'], card: ['bottom-right', 'bottom-left', 'top-right', 'top-left'], modal: ['center', 'top', 'bottom'] };
	var POS_T = {
		bottom: t('Jos', 'Bottom'), top: t('Sus', 'Top'), center: t('Centru', 'Centre'),
		'bottom-right': t('Dreapta jos', 'Bottom right'), 'bottom-left': t('Stânga jos', 'Bottom left'),
		'top-right': t('Dreapta sus', 'Top right'), 'top-left': t('Stânga sus', 'Top left')
	};
	var IMG = { shield: 'biscuit-shield', wp: 'biscuit' };
	var S = {}, lang = en ? 'en' : 'ro', ui = {}, timer = null, first = true;

	function el(tag, cls, text) {
		var e = d.createElement(tag);
		if (cls) { e.className = cls; }
		if (text !== undefined) { e.textContent = text; }
		return e;
	}
	function preset(style) {
		var k;
		S = { style: style };
		for (k in SAME) { S[k] = SAME[k]; }
		for (k in STYLES[style]) { S[k] = STYLES[style][k]; }
		for (k in PRESET[style]) { S[k] = PRESET[style][k]; }
	}
	function picture(dir, suffix) {
		return { src: dir + IMG[S.image] + suffix + '.webp', side: S.side, size: S.size, out: S.out, phone: S.phone };
	}
	function appearance() {
		var a = {}, k;
		['style', 'palette', 'accent', 'theme', 'font', 'format', 'position', 'backdrop', 'dim', 'blur', 'radius', 'shadow', 'maxw', 'gap', 'density', 'buttons', 'anim', 'delay', 'reopen'].forEach(function (n) { a[n] = S[n]; });
		for (k in a) { if (a[k] === '') { delete a[k]; } }
		if (S.image !== 'none') { a.image = picture('/assets/icon/', '-400'); }
		return a;
	}
	// The same choices for the visitor's own site, without the defaults of the style.
	function code() {
		var a = appearance(), def = STYLES[S.style], k;
		for (k in a) {
			if (k !== 'style' && k !== 'image' && (a[k] === def[k] || (!(k in def) && a[k] === (k === 'dim' ? DIM[S.palette] : SAME[k])))) { delete a[k]; }
		}
		if (a.image) { a.image = picture('/biscuitwp/', ''); a.image.src = '/biscuitwp/biscuit-' + S.image + '.webp'; }
		var cfg = { lang: lang, privacyUrl: t('/politica-cookie/', '/cookie-policy/'), categories: ['necessary', 'statistics', 'marketing'], appearance: a };
		if (S.reopen !== 'none') { cfg.reopenButton = true; }
		return '<link rel="stylesheet" href="/biscuitwp/cmp.min.css">\n<script>\nwindow.CMP_CONFIG = ' + JSON.stringify(cfg, null, 2) + ';\n</' + 'script>\n<script src="/biscuitwp/cmp.min.js" defer></' + 'script>';
	}
	function show() {
		var url = BASE + '?c=' + encodeURIComponent(JSON.stringify({ lang: lang, appearance: appearance() }));
		if (first) { first = false; frame.src = url; return; } // still lazy: loads when it comes near
		try { frame.contentWindow.location.replace(url); } catch (e) { frame.src = url; }
	}
	function later() {
		window.clearTimeout(timer);
		timer = window.setTimeout(show, 180);
	}

	/* ---- Contrast of the accent, as in the plugin: at least 4.5:1 ---- */
	function lum(h) {
		var v = [1, 3, 5].map(function (i) { var c = parseInt(h.substr(i, 2), 16) / 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); });
		return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2];
	}
	function ratio(a, b) {
		var x = lum(a), y = lum(b);
		return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
	}
	function contrast() {
		if (!S.accent) { ui.contrast.textContent = t('Gol = culoarea paletei', 'Empty = the palette colour'); ui.contrast.className = 'p-note'; return; }
		var r = Math.min(ratio(S.accent, BG[S.palette]), Math.max(ratio(S.accent, '#ffffff'), ratio(S.accent, '#000000')));
		var ok = r >= 4.5;
		ui.contrast.textContent = (ok ? '✓ ' : '✕ ') + 'Contrast ' + r.toFixed(2) + ':1, ' + (ok ? t('trece', 'passes') : t('sub 4,5:1; pluginul îți propune o nuanță', 'under 4.5:1; the plugin proposes a shade'));
		ui.contrast.className = 'p-note ' + (ok ? 'p-ok' : 'p-bad');
	}

	/* ---- Controls ---- */
	var uid = 0;
	function row(label, control) {
		var p = el('div', 'p-row'), l = el('label', null, label);
		control.id = control.id || 'demo-p' + (++uid);
		l.htmlFor = control.id;
		p.appendChild(l);
		p.appendChild(control);
		return p;
	}
	function select(key, label, opts) {
		var s = el('select');
		s.addEventListener('change', function () { S[key] = s.value; after(key); });
		ui[key] = { set: function () {
			var o = typeof opts === 'function' ? opts() : opts;
			while (s.firstChild) { s.removeChild(s.firstChild); }
			o.forEach(function (x) { var op = el('option', null, x[1]); op.value = x[0]; s.appendChild(op); });
			s.value = String(S[key]);
		} };
		return row(label, s);
	}
	function range(key, label, min, max, step, unit) {
		var r = el('input'), o = el('output'), p;
		r.type = 'range'; r.min = min; r.max = max; r.step = step;
		r.addEventListener('input', function () { S[key] = parseFloat(r.value); o.textContent = r.value + unit; after(key); });
		ui[key] = { set: function () { r.value = S[key]; o.textContent = S[key] + unit; } };
		p = row(label, r);
		p.className += ' p-range';
		p.appendChild(o);
		return (ui[key].row = p);
	}
	function accent() {
		var c = el('input'), x = el('input'), p, box = el('span', 'p-accent');
		c.type = 'color'; c.setAttribute('aria-label', t('Alege culoarea de accent', 'Pick the accent colour'));
		x.type = 'text'; x.maxLength = 7; x.placeholder = '#rrggbb'; x.autocomplete = 'off'; x.spellcheck = false;
		ui.contrast = el('span', 'p-note');
		ui.contrast.setAttribute('role', 'status');
		c.addEventListener('input', function () { S.accent = c.value; x.value = c.value; after('accent'); });
		x.addEventListener('input', function () {
			var v = x.value.trim().toLowerCase();
			if (v === '' || /^#[0-9a-f]{6}$/.test(v)) { S.accent = v; if (v) { c.value = v; } after('accent'); }
		});
		ui.accent = { set: function () { x.value = S.accent; c.value = S.accent || '#111827'; } };
		p = row('Accent', x);
		p.className += ' p-col';
		p.replaceChild(box, x);
		box.appendChild(c);
		box.appendChild(x);
		p.appendChild(ui.contrast);
		return p;
	}
	function check(key, label) {
		var p = el('div', 'p-row p-check'), i = el('input'), l = el('label');
		i.type = 'checkbox';
		i.addEventListener('change', function () { S[key] = i.checked; after(key); });
		ui[key] = { set: function () { i.checked = S[key]; }, row: p };
		l.appendChild(i);
		l.appendChild(d.createTextNode(' ' + label));
		p.appendChild(l);
		return p;
	}
	function group(title, rows) {
		var g = el('details', 'p-group');
		g.appendChild(el('summary', null, title));
		g.open = !(window.matchMedia && window.matchMedia('(max-width: 760px)').matches);
		rows.forEach(function (r) { g.appendChild(r); });
		return g;
	}
	function sync() {
		Object.keys(ui).forEach(function (k) { if (ui[k] && ui[k].set) { ui[k].set(); } });
		visible();
		contrast();
		Array.prototype.forEach.call(sec.querySelectorAll('.switch a[data-style]'), function (a) {
			if (a.getAttribute('data-style') === S.style) { a.setAttribute('aria-current', 'true'); } else { a.removeAttribute('aria-current'); }
		});
	}
	function visible() {
		ui.dim.row.hidden = S.backdrop === 'none';
		ui.blur.row.hidden = S.backdrop !== 'blur';
		ui.maxw.row.hidden = S.format === 'bar';
		['side', 'size', 'out', 'phone'].forEach(function (k) { ui[k].row.hidden = S.image === 'none'; });
	}
	function after(key) {
		if (key === 'format') { S.position = POS[S.format][0]; ui.position.set(); }
		if (key === 'palette') { S.dim = DIM[S.palette]; ui.dim.set(); }
		visible();
		contrast();
		later();
	}

	var grid = el('div', 'p-grid');
	grid.appendChild(group(t('Culori', 'Colours'), [
		select('palette', t('Paletă', 'Palette'), [['neutral', 'Neutral'], ['warm', 'Warm'], ['dark', 'Dark'], ['egov', 'eGov']]),
		accent(),
		select('theme', t('Temă', 'Theme'), [['light', t('Luminos', 'Light')], ['dark', t('Întunecat', 'Dark')], ['auto', t('Automat', 'Automatic')]]),
		select('font', 'Font', [['system', t('Al sistemului', 'System')], ['inherit', t('Al site-ului', 'Of the site')]])
	]));
	grid.appendChild(group(t('Formă', 'Shape'), [
		range('radius', t('Colțuri', 'Corners'), 0, 24, 1, ' px'),
		select('shadow', t('Umbră', 'Shadow'), [['none', t('Fără', 'None')], ['small', t('Mică', 'Small')], ['medium', t('Medie', 'Medium')], ['large', t('Mare', 'Large')]]),
		select('buttons', t('Butoane', 'Buttons'), [['solid', t('Pline', 'Solid')], ['outline', t('Contur', 'Outline')], ['text', 'Text']]),
		select('density', t('Densitate', 'Density'), [['compact', t('Compact', 'Compact')], ['normal', 'Normal'], ['comfortable', t('Extins', 'Comfortable')]]),
		select('image', t('Imagine (din 1.2.0)', 'Picture (from 1.2.0)'), [['none', t('Fără', 'None')], ['shield', t('Biscuit cu scut', 'Biscuit with a shield')], ['wp', t('Biscuit WP', 'WP biscuit')]]),
		(function () { var r = select('side', t('Locul imaginii', 'Picture side'), [['right', t('Dreapta sus', 'Top right')], ['left', t('Stânga sus', 'Top left')]]); ui.side.row = r; return r; }()),
		range('size', t('Mărimea imaginii', 'Picture size'), 48, 160, 4, ' px'),
		check('out', t('Iese din banner', 'Sticks out of the banner')),
		(function () { var r = select('phone', t('Imaginea pe telefon', 'Picture on phones'), [['small', t('Mai mică', 'Smaller')], ['none', t('Ascunsă', 'Hidden')]]); ui.phone.row = r; return r; }())
	]));
	grid.appendChild(group(t('Așezare', 'Layout'), [
		select('format', 'Format', [['modal', t('Fereastră', 'Window')], ['card', t('Card în colț', 'Corner card')], ['bar', t('Bară', 'Bar')]]),
		select('position', t('Poziție', 'Position'), function () { return POS[S.format].map(function (p) { return [p, POS_T[p]]; }); }),
		select('backdrop', t('Fundal', 'Backdrop'), [['blur', t('Întunecare și blur', 'Dim and blur')], ['dim', t('Doar întunecare', 'Dim only')], ['none', t('Fără', 'None')]]),
		range('dim', t('Întunecare', 'Dim'), 0, 80, 5, '%'),
		range('blur', 'Blur', 0, 10, 1, ' px'),
		range('maxw', t('Lățime maximă', 'Max width'), 360, 960, 10, ' px'),
		range('gap', t('Margine', 'Margin'), 0, 40, 2, ' px')
	]));
	grid.appendChild(group(t('Comportament', 'Behaviour'), [
		select('anim', t('Animație', 'Animation'), [['none', t('Fără', 'None')], ['fade', t('Apariție', 'Fade')], ['slide', t('Alunecare', 'Slide')]]),
		range('delay', t('Întârziere', 'Delay'), 0, 2, 0.5, ' s'),
		select('reopen', t('Buton de redeschidere', 'Reopen button'), [['none', t('Fără', 'None')], ['icon', t('Iconiță', 'Icon')], ['text', 'Text'], ['both', t('Ambele', 'Both')]]),
		(function () {
			var s = el('select');
			[['ro', 'Română'], ['en', 'English'], ['ru', 'Русский']].forEach(function (x) { var o = el('option', null, x[1]); o.value = x[0]; s.appendChild(o); });
			s.value = lang;
			s.addEventListener('change', function () { lang = s.value; later(); });
			return row(t('Limba bannerului', 'Banner language'), s);
		}())
	]));
	host.appendChild(grid);

	var bar = el('p', 'actions p-actions');
	var again = el('button', 'btn', t('Arată din nou', 'Show again'));
	var copy = el('button', 'btn btn-ghost', t('Copiază codul', 'Copy the code'));
	var phone = el('button', 'btn btn-ghost', t('Vezi pe telefon', 'See on a phone'));
	[again, copy, phone].forEach(function (b) { b.type = 'button'; bar.appendChild(b); });
	again.addEventListener('click', show);
	phone.setAttribute('aria-pressed', 'false');
	phone.addEventListener('click', function () {
		var on = !stage.classList.contains('is-phone');
		stage.classList.toggle('is-phone', on);
		phone.setAttribute('aria-pressed', on ? 'true' : 'false');
		show();
	});
	if (navigator.clipboard) {
		copy.addEventListener('click', function () {
			var label = copy.textContent;
			navigator.clipboard.writeText(code()).then(function () {
				copy.textContent = t('Copiat', 'Copied');
				window.setTimeout(function () { copy.textContent = label; }, 2500);
			}, function () {});
		});
	} else { copy.hidden = true; }
	host.appendChild(bar);
	var note = el('p', 'p-foot');
	note.appendChild(d.createTextNode(t('Codul e pentru versiunea fără WordPress. În plugin, aceleași opțiuni sunt în ecranul Aspect. ', 'The code is for the version without WordPress. In the plugin the same options are on the Appearance screen. ')));
	var link = el('a', null, t('Cum se instalează', 'How to install it'));
	link.href = t('/standalone/', '/en/standalone/');
	note.appendChild(link);
	host.appendChild(note);

	Array.prototype.forEach.call(sec.querySelectorAll('.switch a[data-style]'), function (a) {
		a.addEventListener('click', function (ev) {
			ev.preventDefault();
			preset(a.getAttribute('data-style'));
			sync();
			show();
		});
	});
	preset('friendly');
	sync();
	show();
}());
