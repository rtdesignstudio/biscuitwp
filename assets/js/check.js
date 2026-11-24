/* BiscuitWP: "Verificare" admin screen. Loads the scanned pages one
 * at a time in a hidden frame in check mode (?cmp_check=<nonce>), collects what
 * each page reports (before / after a simulated "Acceptă tot") and saves the
 * raw data; the server classifies it and the screen shows the report. ES5. */
(function () {
	'use strict';

	// checkPure:begin
	// A message is ours only from this origin, from our frame, with our type.
	function checkAccept(ev, frameWin, origin) {
		return !!ev && ev.origin === origin && !!frameWin && ev.source === frameWin &&
			!!ev.data && typeof ev.data === 'object' && ev.data.type === 'cmp-check';
	}
	// The page URL in check mode (the hash is dropped, the token appended).
	function checkUrl(url, nonce) {
		var u = String(url).replace(/#.*$/, '');
		return u + (u.indexOf('?') === -1 ? '?' : '&') + 'cmp_check=' + encodeURIComponent(nonce);
	}
	// Strings only, without query and hash, deduped, at most `max`, each at most `maxLen`.
	function checkClean(list, max, maxLen) {
		var out = [];
		if (!list || typeof list.length !== 'number') { return out; }
		for (var i = 0; i < list.length && out.length < max; i++) {
			if (typeof list[i] !== 'string') { continue; }
			var v = list[i].replace(/[?#].*$/, '');
			if (v && v.length <= maxLen && out.indexOf(v) === -1) { out.push(v); }
		}
		return out;
	}
	// The raw result of one page: timeout (no message), nobanner, or ok. `pre`: the
	// cookie names in this browser just before the page's frame was created (an
	// earlier page's "Acceptă tot" may have set some): not a verdict on this page.
	function checkOutcome(st, pre) {
		var b = st && st.before;
		if (!b) { return { result: 'timeout' }; }
		if (!b.banner) { return { result: 'nobanner' }; }
		var out = {
			result: 'ok',
			light: !!b.light,
			before: { resources: checkClean(b.resources, 300, 500), cookies: checkClean(b.cookies, 100, 200) },
			added: null,
			pre: checkClean(pre, 100, 200)
		};
		var a = st.after && st.after.added;
		if (!out.light && a) {
			out.added = { resources: checkClean(a.resources, 300, 500), cookies: checkClean(a.cookies, 100, 200) };
		}
		return out;
	}
	// The origin (scheme://host[:port], lowercased) of an absolute URL, or ''.
	function checkOriginOf(url) {
		var m = /^(https?:\/\/[^\/?#]+)/i.exec(String(url));
		return m ? m[1].toLowerCase() : '';
	}
	// The cookie names of a document.cookie string, deduped, at most 100.
	function checkCookieNames(str) {
		var out = [];
		String(str || '').split(';').forEach(function (part) {
			var i = part.indexOf('='), name = (i === -1 ? part : part.slice(0, i)).replace(/^\s+|\s+$/g, '');
			if (name && name.length <= 200 && out.indexOf(name) === -1 && out.length < 100) { out.push(name); }
		});
		return out;
	}
	// What is saved: the admin's cookies before the run (shown apart, no verdict) and the pages.
	function checkPayload(baseline, pages) {
		return { baseline: checkClean(baseline, 100, 200), pages: pages };
	}
	// checkPure:end

	var C = window.CMP_CHECK;
	if (!C || !C.pages) { return; }
	var btn = document.querySelector('[data-cmp-check-start]');
	var status = document.querySelector('[data-cmp-check-status]');
	if (!btn) { return; }

	function say(text) { if (status) { status.textContent = text; } }
	function fill(tpl, a, b) { return String(tpl).replace('%1$d', a).replace('%2$d', b); }

	var results = [], cur = null, baseline = [];

	window.addEventListener('message', function (ev) {
		if (!cur || !checkAccept(ev, cur.frame.contentWindow, location.origin)) { return; }
		var d = ev.data;
		if (d.phase === 'before' && !cur.state.before) {
			cur.state.before = d;
			// Light, or no banner: nothing follows.
			if (d.light || !d.banner) { finish(); }
		} else if (d.phase === 'after' && cur.state.before && !cur.state.after) {
			cur.state.after = d;
			finish();
		}
	});

	function finish() {
		if (!cur) { return; }
		var c = cur;
		cur = null;
		clearTimeout(c.timer);
		if (c.frame.parentNode) { c.frame.parentNode.removeChild(c.frame); }
		var o = checkOutcome(c.state, c.pre);
		o.url = c.url;
		results.push(o);
		next(c.index + 1);
	}

	function next(i) {
		if (i >= C.pages.length) { save(); return; }
		say(fill(C.i18n.progress, i + 1, C.pages.length));
		// The page talks to us only from our own origin (subdomain, www, http/https differ): no 20 s wait.
		if (checkOriginOf(C.pages[i]) !== String(location.origin).toLowerCase()) {
			results.push({ url: C.pages[i], result: 'origin' });
			next(i + 1);
			return;
		}
		// Per-page baseline: the cookies left by the pages before this one.
		var pre = checkCookieNames(document.cookie);
		var f = document.createElement('iframe');
		f.setAttribute('aria-hidden', 'true');
		f.setAttribute('tabindex', '-1');
		f.title = 'cmp-check';
		// Off-screen but rendered at a desktop size, so the page behaves as for a visitor.
		f.style.cssText = 'position:absolute;left:-10000px;top:0;width:1280px;height:900px;border:0;';
		cur = { index: i, url: C.pages[i], frame: f, pre: pre, state: { before: null, after: null } };
		cur.timer = setTimeout(finish, (+C.timeout) || 20000);
		f.src = checkUrl(C.pages[i], C.checkNonce);
		document.body.appendChild(f);
	}

	function save() {
		say(C.i18n.saving);
		var x = new XMLHttpRequest();
		x.open('POST', C.ajaxUrl, true);
		x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
		x.onreadystatechange = function () {
			if (x.readyState !== 4) { return; }
			var okRes = false;
			try { okRes = x.status === 200 && JSON.parse(x.responseText).success === true; } catch (e) {}
			if (okRes) {
				say(C.i18n.done);
				location.href = C.doneUrl;
			} else {
				say(C.i18n.error);
				btn.disabled = false;
			}
		};
		x.send('action=cmp_check_save&nonce=' + encodeURIComponent(C.saveNonce) + '&report=' + encodeURIComponent(JSON.stringify(checkPayload(baseline, results))));
	}

	btn.addEventListener('click', function () {
		if (cur || !C.pages.length) { return; }
		btn.disabled = true;
		results = [];
		// Cookies already in this browser (same site, path=/) before any page is loaded.
		baseline = checkCookieNames(document.cookie);
		next(0);
	});
})();
