/* BiscuitWP: the check-mode runner (admin "Verificare" screen).
 * Enqueued only in check mode (CMP_Scanner::check_mode()), after banner.js, so
 * visitors never download it. It measures the page before and after "Accept
 * all" and posts the results to the parent of the same origin only. It reaches
 * banner.js only through one bridge object { accept, endCheck, hasBanner },
 * which banner.js exposes only in check mode. */
(function () {
	'use strict';

	// check:begin
	// Pure helpers; tested in standalone/tests/run.js (source-extracted).
	// The entries of `after` missing from `before`, in order, without duplicates.
	function checkDiff(before, after) {
		var b = Array.isArray(before) ? before : [], out = [];
		(Array.isArray(after) ? after : []).forEach(function (x) {
			if (b.indexOf(x) === -1 && out.indexOf(x) === -1) { out.push(x); }
		});
		return { added: out };
	}
	// The cookie names of a document.cookie string, in order, without duplicates.
	function cookieList(str) {
		var out = [];
		String(str || '').split(';').forEach(function (part) {
			var i = part.indexOf('='), name = (i === -1 ? part : part.slice(0, i)).replace(/^\s+|\s+$/g, '');
			if (name && out.indexOf(name) === -1) { out.push(name); }
		});
		return out;
	}
	// The message posted to the admin screen for one phase.
	function checkMessage(phase, resources, cookies, hasBanner) {
		return { type: 'cmp-check', phase: phase, resources: Array.isArray(resources) ? resources : [], cookies: Array.isArray(cookies) ? cookies : [], banner: !!hasBanner };
	}
	// Is `win` framed by a page of its own origin (the admin "Verificare" screen)?
	// A cross-origin parent throws on access: false.
	function parentSameOrigin(win) {
		try {
			return !!win && win.parent !== win && !!win.parent && win.parent.location.origin === win.location.origin;
		} catch (e) {
			return false;
		}
	}
	// check:end

	// Only in check mode (the bridge exists) and inside a frame.
	var API = window.CMP_CHECK_API;
	if (!API || window.parent === window) { return; }

	function checkSnapshot() {
		var res = [];
		try {
			(window.performance && performance.getEntriesByType ? performance.getEntriesByType('resource') : []).forEach(function (e) {
				if (e && typeof e.name === 'string' && res.indexOf(e.name) === -1) { res.push(e.name); }
			});
		} catch (e) {}
		return { resources: res, cookies: cookieList(document.cookie) };
	}
	function postCheck(msg) {
		try { window.parent.postMessage(msg, location.origin); } catch (e) {}
	}
	function runCheck() {
		setTimeout(function () {
			var before = checkSnapshot();
			if (typeof API.accept !== 'function') {
				// Light stores no consent: "before" only.
				var msgL = checkMessage('before', before.resources, before.cookies, API.hasBanner());
				msgL.light = true;
				postCheck(msgL);
				API.endCheck();
				return;
			}
			var hasBanner = API.hasBanner();
			postCheck(checkMessage('before', before.resources, before.cookies, hasBanner));
			if (!hasBanner) { API.endCheck(); return; }
			// Never "Acceptă tot" under a frame of another origin.
			if (!parentSameOrigin(window)) { API.endCheck(); return; }
			// The same path as the visitor's click (delegated handler -> save()).
			API.accept();
			setTimeout(function () {
				var after = checkSnapshot();
				var msg = checkMessage('after', after.resources, after.cookies, true);
				msg.added = { resources: checkDiff(before.resources, after.resources).added, cookies: checkDiff(before.cookies, after.cookies).added };
				postCheck(msg);
				API.endCheck();
			}, 2500);
		}, 1500);
	}
	// Loaded in the footer, after banner.js: the load event may already have fired.
	if (document.readyState === 'complete') { runCheck(); }
	else { window.addEventListener('load', runCheck); }
})();
