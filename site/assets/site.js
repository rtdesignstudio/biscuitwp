/* BiscuitWP, biscuitwp.rt.md: the rt.md phone menu and motion, ported from
   rt.md (rt.js). No libraries, nothing loaded from other sites, no statistics.
   Without JavaScript every page is complete: the nav stays inline and nothing is
   hidden. This file adds html.js; the CSS hides things only under that class.
   With prefers-reduced-motion nothing is animated (the menu still works). */
(function () {
	'use strict';

	var doc = document;
	var root = doc.documentElement;
	var en = root.lang === 'en', ru = root.lang === 'ru';
	var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	root.classList.add('js');

	/* "Salvează ca PDF" on printable pages: the browser's own print dialog. */
	Array.prototype.forEach.call(doc.querySelectorAll('[data-print]'), function (b) {
		b.addEventListener('click', function () { window.print(); });
	});

	/* A link to a folded block (<details id>, e.g. #cum-verifici) opens it, on click and
	   when the page loads with that address. */
	function openFolded(hash) {
		var id = hash && hash.charAt(0) === '#' ? hash.slice(1) : '';
		var t = id ? doc.getElementById(id) : null;
		if (t && t.tagName === 'DETAILS') { t.open = true; }
	}
	openFolded(window.location.hash);
	window.addEventListener('hashchange', function () { openFolded(window.location.hash); });
	doc.addEventListener('click', function (e) {
		var a = e.target && e.target.closest ? e.target.closest('a[href^="#"]') : null;
		if (a) { openFolded(a.getAttribute('href')); }
	});

	/* ---- Lightbox for the screenshots (.shot a): a native <dialog> over the page, the
	   picture at full size (scrolls when taller than the screen), its caption, previous /
	   next among the screenshots. Escape, the x and a click on the dark area close it;
	   the arrow keys move between pictures; focus goes back to the link. Without
	   <dialog> support, or with a modifier key, the link opens the picture as before. */
	(function () {
		var links = Array.prototype.slice.call(doc.querySelectorAll('.shot a'));
		if (!links.length || typeof HTMLDialogElement !== 'function') { return; }
		var L = en ? ['Close', 'Previous', 'Next', 'Screenshot'] : ru ? ['Закрыть', 'Предыдущий', 'Следующий', 'Снимок экрана'] : ['Închide', 'Anterioara', 'Următoarea', 'Captură de ecran'];

		var dlg = doc.createElement('dialog');
		dlg.className = 'lb';
		dlg.setAttribute('aria-label', L[3]);
		var bar = doc.createElement('div');
		bar.className = 'lb-bar';
		var count = doc.createElement('span');
		count.className = 'lb-count';
		function button(cls, label, text) {
			var b = doc.createElement('button');
			b.type = 'button';
			b.className = 'lb-btn ' + cls;
			b.setAttribute('aria-label', label);
			b.textContent = text;
			return b;
		}
		var prev = button('lb-prev', L[1], '←');
		var next = button('lb-next', L[2], '→');
		var close = button('lb-close', L[0], '×');
		bar.appendChild(count);
		bar.appendChild(prev);
		bar.appendChild(next);
		bar.appendChild(close);
		var fig = doc.createElement('figure');
		fig.className = 'lb-fig';
		var img = doc.createElement('img');
		img.decoding = 'async';
		var cap = doc.createElement('figcaption');
		fig.appendChild(img);
		fig.appendChild(cap);
		dlg.appendChild(bar);
		dlg.appendChild(fig);
		doc.body.appendChild(dlg);

		var at = 0;
		var opener = null;
		function show(i) {
			at = (i + links.length) % links.length;
			var a = links[at];
			var thumb = a.querySelector('img');
			var fc = a.parentNode.querySelector('figcaption');
			img.src = a.getAttribute('href');
			img.alt = thumb ? thumb.getAttribute('alt') || '' : '';
			// The thumbnail's width/height (half the pixels) keep the space before the picture loads.
			img.width = thumb ? +thumb.getAttribute('width') || 0 : 0;
			img.height = thumb ? +thumb.getAttribute('height') || 0 : 0;
			cap.textContent = fc ? fc.textContent : '';
			count.textContent = (at + 1) + ' / ' + links.length;
			prev.hidden = next.hidden = links.length < 2;
			dlg.scrollTop = 0;
		}
		links.forEach(function (a, i) {
			a.addEventListener('click', function (e) {
				if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) { return; }
				e.preventDefault();
				opener = a;
				show(i);
				root.classList.add('lb-open');
				dlg.showModal();
				close.focus();
			});
		});
		prev.addEventListener('click', function () { show(at - 1); });
		next.addEventListener('click', function () { show(at + 1); });
		close.addEventListener('click', function () { dlg.close(); });
		// A click on the dark area (the dialog itself, not the picture or the bar) closes it.
		dlg.addEventListener('click', function (e) { if (e.target === dlg || e.target === fig) { dlg.close(); } });
		dlg.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowLeft') { e.preventDefault(); show(at - 1); }
			if (e.key === 'ArrowRight') { e.preventDefault(); show(at + 1); }
		});
		dlg.addEventListener('close', function () {
			root.classList.remove('lb-open');
			if (opener) { opener.focus(); }
		});
	})();

	/* ---- Phone menu (rt.md): a button with two lines (long on top, short below) opens a
	   white sheet with the same links as the header nav, large and numbered; the same
	   button turns into an x and closes it. The header stays above the sheet. ---------- */
	(function () {
		var header = doc.querySelector('header.site');
		var nav = header && header.querySelector('nav');
		var bar = header && header.querySelector('.wrap');
		if (!nav || !bar) { return; }
		var L = en ? ['Menu', 'Close'] : ru ? ['Меню', 'Закрыть'] : ['Meniu', 'Închide'];

		var btn = doc.createElement('button');
		btn.className = 'menu-btn';
		btn.type = 'button';
		btn.appendChild(doc.createElement('span'));
		btn.appendChild(doc.createElement('span'));
		btn.setAttribute('aria-label', L[0]);
		btn.setAttribute('aria-expanded', 'false');
		btn.setAttribute('aria-controls', 'sheet');
		bar.appendChild(btn);

		var sheet = doc.createElement('div');
		sheet.className = 'sheet';
		sheet.id = 'sheet';
		sheet.setAttribute('role', 'dialog');
		sheet.setAttribute('aria-modal', 'true');
		sheet.setAttribute('aria-label', L[0]);
		var list = doc.createElement('nav');
		list.className = 'sheet-main';
		list.setAttribute('aria-label', nav.getAttribute('aria-label') || L[0]);
		// The links are copies of the header nav (same addresses, same current page).
		var links = [].slice.call(nav.querySelectorAll('a[href]'));
		links.forEach(function (a, i) {
			var c = a.cloneNode(true);
			var n = doc.createElement('span');
			n.className = 'i';
			n.setAttribute('aria-hidden', 'true');
			n.textContent = (i < 9 ? '0' : '') + (i + 1);
			c.insertBefore(n, c.firstChild);
			c.style.setProperty('--n', String(i));
			list.appendChild(c);
		});
		sheet.appendChild(list);
		// Right after the header: the button is followed by the links in the tab order.
		header.parentNode.insertBefore(sheet, header.nextSibling);

		var inerted = [];
		var isOpen = function () { return sheet.classList.contains('open'); };
		var set = function (open, focus) {
			if (open === isOpen()) { return; }
			if (open) {
				// The sheet starts under the header row, wherever the page is scrolled.
				sheet.style.setProperty('--top', Math.max(0, Math.round(btn.getBoundingClientRect().bottom)) + 'px');
			}
			sheet.classList.toggle('open', open);
			doc.body.classList.toggle('locked', open);
			btn.setAttribute('aria-expanded', open ? 'true' : 'false');
			btn.setAttribute('aria-label', L[open ? 1 : 0]);
			// While the sheet is open, the rest of the page is out of reach (keyboard and screen readers).
			if (open) {
				[].forEach.call(doc.body.children, function (el) {
					if (el !== header && el !== sheet && !el.inert && el.tagName !== 'SCRIPT') { el.inert = true; inerted.push(el); }
				});
				nav.inert = true;
				if (focus) {
					var first = list.querySelector('a');
					if (first) { first.focus(); }
				}
			} else {
				inerted.forEach(function (el) { el.inert = false; });
				inerted = [];
				nav.inert = false;
				if (focus) { btn.focus(); }
			}
		};
		btn.addEventListener('click', function () { set(!isOpen(), true); });
		[].forEach.call(list.querySelectorAll('a'), function (a) {
			a.addEventListener('click', function () { set(false, false); });
		});
		doc.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && isOpen()) { set(false, true); }
		});
		// Back to the desktop width (the button is gone there): close.
		var wide = window.matchMedia && window.matchMedia('(min-width: 761px)');
		var onWide = function () { if (wide.matches) { set(false, false); } };
		if (wide && wide.addEventListener) { wide.addEventListener('change', onWide); }
		else if (wide && wide.addListener) { wide.addListener(onWide); }
		// Coming back with the browser's Back button: never restore an open sheet.
		window.addEventListener('pageshow', function () { set(false, false); });
	})();

	/* ---- Fixed header (rt.md .top.scrolled): after the first steps of scrolling it gets
	   tighter and a background (the CSS animates both, except with reduced motion). ---- */
	(function () {
		var header = doc.querySelector('header.site');
		if (!header) { return; }
		var on = null;
		var upd = function () {
			var s = window.scrollY > 40;
			if (s !== on) { on = s; header.classList.toggle('scrolled', s); }
		};
		window.addEventListener('scroll', upd, { passive: true });
		upd();
	})();

	if (reduce) { return; }

	/* Lines of an animated title (rt.md rt-concept.js): measured at the final weight
	   (.measure), then each line is fixed in its own span (.ln), so the text never
	   re-wraps while the letters thicken and nothing under it moves. Text only. */
	var fixLines = function (el, text) {
		el.textContent = text;
		el.classList.add('measure');
		var words = text.trim().split(/ +/);
		el.textContent = '';
		var spans = words.map(function (w, j) {
			if (j) { el.appendChild(doc.createTextNode(' ')); }
			var s = doc.createElement('span');
			s.className = 'wd';
			s.textContent = w;
			el.appendChild(s);
			return s;
		});
		var lines = [];
		var top = null;
		spans.forEach(function (s) {
			var t = Math.round(s.getBoundingClientRect().top);
			if (top === null || Math.abs(t - top) > 4) { lines.push([]); top = t; }
			lines[lines.length - 1].push(s.textContent);
		});
		if (lines.length > 1) {
			el.textContent = '';
			lines.forEach(function (l) {
				var s = doc.createElement('span');
				s.className = 'ln';
				s.textContent = l.join(' ');
				el.appendChild(s);
			});
		} else {
			el.textContent = text;
		}
		el.classList.remove('measure');
	};
	// Re-measure when the web font arrives (only if it was not there yet) and when the width changes.
	var remeasure = function (run) {
		if (doc.fonts && doc.fonts.ready && doc.fonts.status !== 'loaded') { doc.fonts.ready.then(run); }
		var w = window.innerWidth;
		var tm;
		window.addEventListener('resize', function () {
			if (window.innerWidth === w) { return; }
			w = window.innerWidth;
			clearTimeout(tm);
			tm = setTimeout(run, 150);
		});
	};

	/* ---- Hero title (rt.md /umarul/ .big .row > span): the rows come in one after the
	   other, from blur and from Thin to their weight. The animation itself is CSS, under
	   html.js (without JS the title is simply there). The h1 carries the accessible name
	   (aria-label); the rows are aria-hidden. When a row has finished (.in) it keeps its
	   final state, so a later re-measure (font, resize) does not replay it. ------------ */
	(function () {
		var rows = [].slice.call(doc.querySelectorAll('.big .row > span'));
		if (!rows.length) { return; }
		var src = rows.map(function (el) { return el.textContent; });
		rows.forEach(function (el) {
			el.addEventListener('animationend', function (e) {
				if (e.target === el && e.animationName === 'cmp-weight') { el.classList.add('in'); }
			});
		});
		var run = function () { rows.forEach(function (el, i) { fixLines(el, src[i]); }); };
		run();
		remeasure(run);
	})();

	/* ---- Section headings (rt.md .k): they thicken from Thin to their weight when they
	   enter the screen. The lines are measured at the final weight first and then fixed,
	   so the text never re-wraps and nothing under it moves. --------------------------- */
	(function () {
		if (!('IntersectionObserver' in window)) { return; }
		var heads = [].slice.call(doc.querySelectorAll('.sh h2'));
		if (!heads.length) { return; }
		var src = [];
		var plain = heads.map(function (el) {
			src.push(el.textContent);
			// Only plain-text headings are split into lines; others keep their markup.
			return [].every.call(el.childNodes, function (n) { return n.nodeType === 3; });
		});
		var run = function () { heads.forEach(function (el, i) { if (plain[i]) { fixLines(el, src[i]); } }); };
		// Hidden state first, without a transition, so headings already on screen animate too.
		heads.forEach(function (el) { el.classList.add('k', 'k-init'); });
		run();
		void root.offsetWidth;
		heads.forEach(function (el) { el.classList.remove('k-init'); });
		remeasure(run);
		var io = new IntersectionObserver(function (es) {
			es.forEach(function (e) {
				if (e.isIntersecting) { e.target.classList.add('vis'); io.unobserve(e.target); }
			});
		}, { threshold: 0.35 });
		heads.forEach(function (el) { io.observe(el); });
	})();

	/* ---- Text lights up on scroll (rt.md .fd): each block of text in <main> goes from
	   opacity .3 to 1 while it rises from the bottom of the screen towards the middle.
	   Never the hero, the section headings, the report (#raport), the
	   tables or the demo. Only opacity and blur change: nothing moves. ----------------- */
	(function () {
		var SEL = 'main p, main li, main h3, main h4, main figure, main blockquote, main summary, main dt, main dd';
		var SKIP = '.hero, .sh, #raport, table, .table-scroll, .frame, iframe, .switch, nav, [aria-hidden="true"]';
		var all = [].slice.call(doc.querySelectorAll(SEL)).filter(function (el) { return !el.closest(SKIP); });
		var set = new Set(all);
		var els = all.filter(function (el) {
			for (var a = el.parentElement; a; a = a.parentElement) { if (set.has(a)) { return false; } }
			return true;
		});
		if (!els.length) { return; }
		els.forEach(function (el) { el.classList.add('fd', 'blur'); });
		var MIN = 0.3;
		var ease = function (t) { return 1 - Math.pow(1 - t, 3); };
		var tick = false;
		var upd = function () {
			var vh = window.innerHeight;
			var left = root.scrollHeight - window.scrollY - vh;
			var end = Math.max(0, 1 - left / (vh * 0.4)); // near the end of the page everything on screen lights up
			var tops = els.map(function (el) { return el.getBoundingClientRect().top; });
			els.forEach(function (el, i) {
				var t = Math.min(1, Math.max(0, (vh * 0.94 - tops[i]) / (vh * 0.38), end));
				el.style.setProperty('--o', (MIN + (1 - MIN) * ease(t)).toFixed(3));
				if (t > 0.08 && el.classList.contains('blur')) { el.classList.remove('blur'); }
			});
			tick = false;
		};
		window.addEventListener('scroll', function () {
			if (!tick) { tick = true; window.requestAnimationFrame(upd); }
		}, { passive: true });
		window.addEventListener('resize', upd);
		// <details> opening changes the page height.
		doc.addEventListener('toggle', upd, true);
		upd();
	})();
})();
