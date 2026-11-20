/* Consent Manager Pro, consent.rt.md: the "Verifică-ți site-ul" tool, its confetti
   and the demo style switch. No libraries, nothing loaded from other sites.
   Everything that comes from the server (titles, explanations, the checked address)
   is written with textContent only, never parsed as HTML. */
(function () {
	'use strict';

	var doc = document;
	var en = doc.documentElement.lang === 'en';
	var T = en ? {
		empty: 'Enter your site\'s address, for example example.com.',
		checking: 'Checking the homepage. It can take up to 15 seconds.',
		cancelled: 'You cancelled the check.',
		checked: 'Checked page: ',
		law: 'Law: ',
		cleanTitle: 'No problems found on the homepage',
		cleanText: 'On the homepage we found no trackers, no resources that send the IP address and no script loaders that start before consent.',
		cleanLimits: 'What we cannot see from here: the other pages, what the site does with the data and what its policy says.',
		issuesTitle: function (n) { return n === 1 ? 'We found one problem' : 'We found ' + n + ' problems'; },
		unsureTitle: 'We cannot be sure',
		unsureText: 'The page has something that can load trackers we cannot see from here. Check it yourself, it takes 5 minutes, with DevTools.',
		unsureList: 'To check',
		info: 'Good to know',
		howto: 'How to check it yourself',
		fixTitle: 'Consent Manager Pro fixes this',
		fixText: 'It keeps trackers off until consent, moves Google Fonts to your server and gives you the legal texts for Moldova. Free.',
		oursTitle: 'You already use Consent Manager Pro',
		oursText: 'Check script blocking and the service list in its settings, then update the plugin to the latest version.',
		download: 'Download',
		latest: 'Latest version',
		features: 'See what it does',
		errorTitle: 'We could not check the site',
		errors: {
			bad_request: 'Enter your site\'s address, for example example.com.',
			bad_url: 'This address is not valid. Use a public address such as example.com, without a port, user name or password.',
			blocked_address: 'This address leads to a private or reserved network. We can only check public sites.',
			fetch_failed: 'We could not open the site. Check the address or try again later.',
			timeout: 'The site answered too slowly. Try again later.',
			too_large: 'The homepage is over 2 MB, too much for an automatic check.',
			too_many_redirects: 'The site redirects too many times.',
			http_status: 'The site answered with an error, so we could not read the page.',
			not_html: 'This address does not lead to a web page.',
			rate_limited: 'You have run many checks in a short time. Try again in a minute.',
			busy: 'There are a lot of checks right now. Try again in a few minutes.',
			unavailable: 'The check is not available right now. Try again later.',
			forbidden: 'Reload this page and try again.',
			method_not_allowed: 'Reload this page and try again.',
			network: 'We could not reach our server. Check your connection and try again.',
			slow: 'The check took too long. Try again later.'
		}
	} : {
		empty: 'Scrie adresa site-ului, de exemplu exemplu.md.',
		checking: 'Verificăm pagina principală. Poate dura până la 15 secunde.',
		cancelled: 'Ai anulat verificarea.',
		checked: 'Pagina verificată: ',
		law: 'Legea: ',
		cleanTitle: 'N-am găsit probleme pe pagina principală',
		cleanText: 'Pe pagina principală n-am găsit trackere, resurse care trimit IP-ul sau încărcătoare de scripturi care pornesc înainte de acord.',
		cleanLimits: 'Ce nu vedem de aici: celelalte pagini, ce face site-ul cu datele și ce scrie în politica lui.',
		issuesTitle: function (n) { var r = n % 100; return n === 1 ? 'Am găsit o problemă' : 'Am găsit ' + n + ( r >= 1 && r <= 19 ? ' probleme' : ' de probleme' ); },
		unsureTitle: 'Nu putem fi siguri',
		unsureText: 'Pe pagină e ceva care poate încărca trackere pe care nu le vedem de aici. Verifică singur, în 5 minute, cu DevTools.',
		unsureList: 'De verificat',
		info: 'De știut',
		howto: 'Cum verifici singur',
		fixTitle: 'Consent Manager Pro rezolvă asta',
		fixText: 'Oprește trackerele până la acord, mută Google Fonts pe serverul tău și îți dă textele legale pentru Moldova. Gratuit.',
		oursTitle: 'Folosești deja Consent Manager Pro',
		oursText: 'Verifică în setări blocarea scripturilor și lista de servicii, apoi actualizează pluginul la ultima versiune.',
		download: 'Descarcă',
		latest: 'Ultima versiune',
		features: 'Vezi ce face',
		errorTitle: 'Nu am putut verifica site-ul',
		errors: {
			bad_request: 'Scrie adresa site-ului, de exemplu exemplu.md.',
			bad_url: 'Adresa nu e validă. Folosește o adresă publică, de exemplu exemplu.md, fără port, utilizator sau parolă.',
			blocked_address: 'Adresa duce spre o rețea privată sau rezervată. Putem verifica doar site-uri publice.',
			fetch_failed: 'Nu am putut deschide site-ul. Verifică adresa sau încearcă din nou mai târziu.',
			timeout: 'Site-ul a răspuns prea încet. Încearcă din nou mai târziu.',
			too_large: 'Pagina principală are peste 2 MB, prea mult pentru o verificare automată.',
			too_many_redirects: 'Site-ul redirecționează de prea multe ori.',
			http_status: 'Site-ul a răspuns cu o eroare, așa că nu am putut citi pagina.',
			not_html: 'Adresa nu duce la o pagină web.',
			rate_limited: 'Ai făcut multe verificări într-un timp scurt. Încearcă peste un minut.',
			busy: 'Sunt foarte multe verificări acum. Încearcă din nou peste câteva minute.',
			unavailable: 'Verificarea nu e disponibilă acum. Încearcă din nou mai târziu.',
			forbidden: 'Reîncarcă pagina și încearcă din nou.',
			method_not_allowed: 'Reîncarcă pagina și încearcă din nou.',
			network: 'Nu am putut ajunge la serverul nostru. Verifică conexiunea și încearcă din nou.',
			slow: 'Verificarea a durat prea mult. Încearcă din nou mai târziu.'
		}
	};

	/* Small DOM helpers: text only. */
	function el(tag, cls, text) {
		var n = doc.createElement(tag);
		if (cls) { n.className = cls; }
		if (text !== undefined && text !== null) { n.textContent = String(text); }
		return n;
	}
	function link(href, text, cls) {
		var a = el('a', cls, text);
		a.setAttribute('href', href);
		return a;
	}
	function str(v) { return typeof v === 'string' ? v : ''; }
	function downloadHref() {
		var a = doc.querySelector('a[data-download]');
		return a ? a.getAttribute('href') : '#instalare';
	}

	/* confetti */
	function confetti() {
		if (!window.matchMedia || window.matchMedia('(prefers-reduced-motion: reduce)').matches || doc.querySelector('canvas.confetti')) { return; }
		var c = doc.createElement('canvas'), x = c.getContext && c.getContext('2d');
		if (!x) { return; }
		var w = c.width = window.innerWidth, h = c.height = window.innerHeight;
		var cols = ['#8bc34a', '#f4b400', '#e4572e', '#3f8efc', '#a259ff'], p = [], t0 = 0, i;
		c.className = 'confetti';
		c.setAttribute('aria-hidden', 'true');
		doc.body.appendChild(c);
		for (i = 0; i < 120; i++) {
			p.push({ x: w / 2 + (Math.random() - 0.5) * w * 0.3, y: h * 0.4, vx: (Math.random() - 0.5) * 14, vy: -4 - Math.random() * 12, r: Math.random() * 6, s: 5 + Math.random() * 5, c: cols[i % 5] });
		}
		function frame(t) {
			if (!t0) { t0 = t; }
			var k = t - t0;
			x.clearRect(0, 0, w, h);
			x.globalAlpha = k > 1500 ? Math.max(0, 1 - (k - 1500) / 500) : 1;
			p.forEach(function (q) {
				q.vy += 0.35; q.vx *= 0.99; q.x += q.vx; q.y += q.vy; q.r += 0.12;
				x.save(); x.translate(q.x, q.y); x.rotate(q.r);
				x.fillStyle = q.c; x.fillRect(-q.s / 2, -q.s / 4, q.s, q.s / 2);
				x.restore();
			});
			if (k < 2000) { window.requestAnimationFrame(frame); } else if (c.parentNode) { c.parentNode.removeChild(c); }
		}
		window.requestAnimationFrame(frame);
	}
	/* /confetti */

	/* The demo style switch: links target the demo iframe; we only mark the current one. */
	Array.prototype.forEach.call(doc.querySelectorAll('.switch a[data-style]'), function (a) {
		a.addEventListener('click', function () {
			Array.prototype.forEach.call(doc.querySelectorAll('.switch a[data-style]'), function (b) { b.removeAttribute('aria-current'); });
			a.setAttribute('aria-current', 'true');
		});
	});

	var form = doc.getElementById('verifica-form');
	var out = doc.getElementById('verifica-rezultat');
	if (!form || !out || !window.fetch || !window.AbortController) { return; }
	form.hidden = false; // Without JS (or fetch) the form stays hidden; the <noscript> text explains.
	var input = doc.getElementById('verifica-url');
	var submit = doc.getElementById('verifica-buton');
	var cancel = doc.getElementById('verifica-anuleaza');
	var ctrl = null, timer = null, slow = false;

	function clear() {
		while (out.firstChild) { out.removeChild(out.firstChild); }
	}
	function busy(on) {
		submit.disabled = on;
		cancel.hidden = !on;
		form.setAttribute('aria-busy', on ? 'true' : 'false');
	}
	// Status lines (checking, cancelled) are announced by the live region. A final
	// result is not: focus moves to its heading instead, so it is read only once.
	function status(text) {
		clear();
		out.setAttribute('aria-live', 'polite');
		out.appendChild(el('p', 'r-loading', text));
	}
	function box(kind, title) {
		clear();
		out.setAttribute('aria-live', 'off');
		var b = el('div', 'r r-' + kind);
		var hd = el('h3', null, title);
		hd.setAttribute('tabindex', '-1');
		b.appendChild(hd);
		out.appendChild(b);
		return { box: b, head: hd };
	}
	function show(r) {
		r.head.focus();
		window.setTimeout(function () { out.setAttribute('aria-live', 'polite'); }, 500);
	}
	function list(parent, items, title, cls) {
		if (!items.length) { return; }
		if (title) { parent.appendChild(el('h4', null, title)); }
		var ul = el('ul', 'findings' + (cls ? ' ' + cls : ''));
		items.forEach(function (f) {
			var li = el('li');
			li.appendChild(el('strong', null, str(f.title)));
			if (str(f.explain)) { li.appendChild(el('p', null, str(f.explain))); }
			if (str(f.law)) { li.appendChild(el('p', 'law', T.law + str(f.law))); }
			ul.appendChild(li);
		});
		parent.appendChild(ul);
	}
	function checkedLine(parent, data) {
		if (str(data.checked_url)) { parent.appendChild(el('p', 'muted small checked', T.checked + str(data.checked_url))); }
	}
	function actions(parent, items) {
		var p = el('p', 'actions');
		items.forEach(function (it) { p.appendChild(link(it[0], it[1], it[2])); });
		parent.appendChild(p);
	}
	function bySeverity(findings, sev) {
		return findings.filter(function (f) { return f && typeof f === 'object' && f.severity === sev; });
	}

	function renderError(code, serverMessage) {
		var r = box('error', T.errorTitle);
		var msg = T.errors[code] || str(serverMessage) || T.errors.unavailable;
		if (code === 'http_status' && str(serverMessage)) { msg = str(serverMessage); }
		r.box.appendChild(el('p', null, msg));
		if (code !== 'rate_limited' && code !== 'busy' && code !== 'bad_url' && code !== 'bad_request') {
			actions(r.box, [['#cum-verifici', T.howto, 'btn btn-ghost']]);
		}
		show(r);
	}

	function render(data) {
		if (!data || typeof data !== 'object') { renderError('unavailable'); return; }
		var findings = Array.isArray(data.findings) ? data.findings : [];
		var issues = bySeverity(findings, 'issue'), unsure = bySeverity(findings, 'unsure'), info = bySeverity(findings, 'info');
		var ours = data.cmp === 'Consent Manager Pro';
		var r;
		if (ours && data.status === 'issues') {
			// The "already using it" block below says the same as our own CMP finding.
			info = info.filter(function (f) { return str(f.id).indexOf('cmp:consent-manager-pro') !== 0; });
		}
		if (data.status === 'clean') {
			r = box('clean', T.cleanTitle);
			r.box.appendChild(el('p', null, T.cleanText));
			checkedLine(r.box, data);
			r.box.appendChild(el('p', 'muted', T.cleanLimits));
			list(r.box, info, T.info, 'muted-list');
			show(r);
			confetti();
		} else if (data.status === 'issues') {
			r = box('issues', T.issuesTitle(issues.length));
			checkedLine(r.box, data);
			list(r.box, issues);
			list(r.box, unsure, T.unsureList);
			list(r.box, info, T.info, 'muted-list');
			var fix = el('div', 'fix');
			fix.appendChild(el('h4', null, ours ? T.oursTitle : T.fixTitle));
			fix.appendChild(el('p', null, ours ? T.oursText : T.fixText));
			r.box.appendChild(fix);
			actions(fix, ours ?
				[[downloadHref(), T.latest, 'btn'], ['#cum-verifici', T.howto, 'btn btn-ghost']] :
				[[downloadHref(), T.download, 'btn'], ['#functii', T.features, 'btn btn-ghost']]);
			show(r);
		} else if (data.status === 'unsure') {
			r = box('unsure', T.unsureTitle);
			r.box.appendChild(el('p', null, T.unsureText));
			checkedLine(r.box, data);
			list(r.box, unsure);
			list(r.box, info, T.info, 'muted-list');
			actions(r.box, [['#cum-verifici', T.howto, 'btn btn-ghost']]);
			show(r);
		} else if (data.status === 'error' && !str(data.code) && findings.length) {
			// The page was fetched, but there was nothing to analyse (an empty page).
			r = box('error', T.errorTitle);
			checkedLine(r.box, data);
			list(r.box, findings);
			show(r);
		} else {
			renderError(str(data.code) || 'unavailable', data.message);
		}
	}

	function stop() {
		if (timer) { window.clearTimeout(timer); timer = null; }
		ctrl = null;
		busy(false);
	}

	cancel.addEventListener('click', function () {
		if (ctrl) { ctrl.abort(); }
	});

	form.addEventListener('submit', function (ev) {
		ev.preventDefault();
		if (ctrl) { return; }
		var url = (input.value || '').trim();
		if (!url) {
			var r0 = box('error', T.errorTitle);
			r0.box.appendChild(el('p', null, T.empty));
			show(r0);
			input.focus();
			return;
		}
		status(T.checking);
		busy(true);
		ctrl = new window.AbortController();
		slow = false;
		var mine = ctrl;
		timer = window.setTimeout(function () { slow = true; mine.abort(); }, 30000);
		window.fetch('/api/verifica.php', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
			body: JSON.stringify({ url: url, lang: en ? 'en' : 'ro' }),
			credentials: 'same-origin',
			cache: 'no-store',
			signal: mine.signal
		}).then(function (res) {
			return res.json().catch(function () { return { status: 'error', code: res.status === 429 ? 'rate_limited' : 'unavailable' }; });
		}).then(function (data) {
			stop();
			render(data);
		}).catch(function (err) {
			stop();
			if (err && err.name === 'AbortError') {
				if (slow) {
					renderError('slow');
				} else {
					status(T.cancelled);
					input.focus();
				}
				return;
			}
			renderError('network');
		});
	});
}());
