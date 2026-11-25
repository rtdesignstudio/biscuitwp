'use strict';
/**
 * B7.1 browser test: zero requests to trackers before consent.
 *
 *   node tests/e2e/run.js --base http://127.0.0.1:<port> \
 *        --standalone http://127.0.0.1:<port2>/tests/e2e/standalone.html
 *
 * Normally started by bin/e2e.sh (Playground + static server + fixture).
 *
 * Every request to another origin than the local test sites is recorded and
 * aborted (context.route); Chromium also resolves every other host to
 * NOTFOUND, so no test traffic ever reaches Google, Meta & co. A request
 * counts for a service when serviceFor() attributes its URL to it.
 *
 * Each scenario runs in a fresh browser context (no cookies from an earlier
 * scenario or run). Exit code: 0 = all ok; 1 = a LEAK, a MISSING service or a
 * harness problem; 2 = bad usage.
 */
const fs = require('fs');
const path = require('path');
const { serviceFor } = require('./match');
const fixture = require('./fixture');

const ROOT = path.join(__dirname, '..', '..');
const SETTLE_MS = Number(process.env.CMP_E2E_SETTLE_MS || 1500);
const LOCAL_HOSTS = ['127.0.0.1', 'localhost', '[::1]', '::1'];

/**
 * Floor independent of the plugin's own logic (the expect endpoint): these
 * services may NEVER load before consent, in WP Strict, WP Advanced or
 * standalone. A wrong `exempt` / `category` in the library or in CMP_Services
 * would otherwise make the test agree with the bug.
 */
const NEVER_BEFORE = ['meta-pixel', 'tiktok-pixel', 'linkedin-insight', 'clarity', 'hotjar', 'youtube', 'vimeo', 'google-maps', 'google-maps-js', 'hubspot', 'tawk-to', 'crisp'];
/** The only services the Advanced mode may add on top of Strict. */
const ADVANCED_EXTRA = ['google-analytics', 'google-tag-manager'];

function arg(name) {
	const i = process.argv.indexOf('--' + name);
	return i !== -1 && process.argv[i + 1] ? process.argv[i + 1] : '';
}

function isLocal(u) {
	try {
		const url = new URL(u);
		if (url.protocol !== 'http:' && url.protocol !== 'https:') {
			return true; // data:, blob:, about: never leave the machine
		}
		return LOCAL_HOSTS.indexOf(url.hostname) !== -1;
	} catch (e) {
		return true;
	}
}

/**
 * One page visit in a fresh context. action: null | 'accept' | 'reject'.
 * Returns the external requests split by phase (before / after the click).
 */
async function visit(browser, { url, cookies, action }) {
	const ctx = await browser.newContext();
	const external = [];
	const seen = new Set();
	let phase = 'before';
	const record = (u) => {
		const key = phase + ' ' + u;
		if (!seen.has(key)) {
			seen.add(key);
			external.push({ url: u, phase });
		}
	};
	try {
		if (cookies && cookies.length) {
			await ctx.addCookies(cookies);
		}
		await ctx.route('**/*', (route) => {
			const u = route.request().url();
			if (isLocal(u)) {
				return route.continue();
			}
			record(u);
			return route.abort('blockedbyclient');
		});
		// Belt and braces: anything the router would not see is still recorded.
		ctx.on('request', (req) => {
			if (!isLocal(req.url())) {
				record(req.url());
			}
		});
		const page = await ctx.newPage();
		const resp = await page.goto(url, { waitUntil: 'load', timeout: 90000 });
		if (!resp || !resp.ok()) {
			throw new Error('page ' + url + ' answered ' + (resp ? resp.status() : 'nothing'));
		}
		await page.waitForTimeout(SETTLE_MS);
		const fixtureOnPage = (await page.locator('[data-cmp-e2e-fixture]').count()) > 0;
		if (!fixtureOnPage) {
			throw new Error('fixture marker missing on ' + url + ' (fixture not loaded?)');
		}
		if (action) {
			const btn = page.locator('[data-cmp-action="' + action + '"]:visible').first();
			await btn.waitFor({ state: 'visible', timeout: 15000 });
			// Everything from here on is a consequence of the decision.
			phase = 'after';
			await btn.click({ timeout: 15000 });
			await page.waitForLoadState('load').catch(() => {}); // reloadOnDecision
			await page.waitForTimeout(SETTLE_MS);
		}
	} finally {
		await ctx.close();
	}
	return external;
}

/**
 * B6.18 guarantees in the browser: a fresh visitor page with the given look
 * (cookie cmp_e2e_look = style.buttons) and optional sabotage CSS. Measured
 * here, independently of the plugin's own guard: Reject and Accept equal size
 * (±2px), rendered, visibility visible, opacity (with ancestors) ≥ 0.9, ≥ 24 px,
 * and whether .cmp--guard was added. Returns a list of problems ('' = ok).
 */
async function look(browser, { url, host, style, buttons, sabotage, width }) {
	const ctx = await browser.newContext({ viewport: { width: width || 1280, height: width ? 740 : 800 } });
	const warns = [];
	try {
		const cookies = [{ name: 'cmp_e2e_look', value: style + '.' + buttons, domain: host, path: '/' }];
		if (sabotage) {
			cookies.push({ name: 'cmp_e2e_sabotage', value: sabotage, domain: host, path: '/' });
		}
		await ctx.addCookies(cookies);
		await ctx.route('**/*', (route) => (isLocal(route.request().url()) ? route.continue() : route.abort('blockedbyclient')));
		const page = await ctx.newPage();
		page.on('console', (m) => { if (m.type() === 'warning') { warns.push(m.text()); } });
		const resp = await page.goto(url, { waitUntil: 'load', timeout: 90000 });
		if (!resp || !resp.ok()) {
			throw new Error('page ' + url + ' answered ' + (resp ? resp.status() : 'nothing'));
		}
		await page.waitForSelector('[data-cmp]:not([hidden])', { timeout: 15000 });
		await page.waitForTimeout(900); // opening animation (0.35 s) + the guard (0.4 s)
		const m = await page.evaluate(() => {
			const root = document.querySelector('[data-cmp]');
			const one = (a) => {
				const el = root.querySelector('[data-cmp-view="main"] [data-cmp-action="' + a + '"]');
				if (!el) { return null; }
				const r = el.getBoundingClientRect();
				let o = 1;
				for (let p = el; p; p = p === root ? null : p.parentElement) { o *= parseFloat(getComputedStyle(p).opacity); }
				return { r: el.getClientRects().length > 0, v: getComputedStyle(el).visibility, o, w: r.width, h: r.height, cls: el.className };
			};
			return { reject: one('reject'), accept: one('accept'), classes: root.className, sabotage: !!document.getElementById('cmp-e2e-sabotage') };
		});
		const bad = [];
		const want = 'cmp--style-' + style;
		if (m.classes.indexOf(want) === -1 || m.classes.indexOf('cmp--btn-' + buttons) === -1) {
			bad.push('look not applied (' + m.classes + ')');
		}
		['reject', 'accept'].forEach((k) => {
			const b = m[k];
			if (!b) { bad.push(k + ' missing'); return; }
			if (!b.r) { bad.push(k + ' not rendered'); }
			if (b.v !== 'visible') { bad.push(k + ' visibility ' + b.v); }
			if (!(b.o >= 0.9)) { bad.push(k + ' opacity ' + b.o); }
			if (!(b.w >= 24 && b.h >= 24)) { bad.push(k + ' ' + b.w + 'x' + b.h + ' < 24px'); }
		});
		if (m.reject && m.accept) {
			if (Math.abs(m.reject.w - m.accept.w) > 2 || Math.abs(m.reject.h - m.accept.h) > 2) {
				bad.push('sizes differ: reject ' + m.reject.w + 'x' + m.reject.h + ', accept ' + m.accept.w + 'x' + m.accept.h);
			}
			if (m.reject.cls !== m.accept.cls) { bad.push('classes differ'); }
		}
		const guard = / cmp--guard\b/.test(' ' + m.classes);
		const warned = warns.some((w) => w.indexOf('CMP: appearance guard reset the consent buttons') !== -1);
		if (sabotage) {
			if (!m.sabotage) { bad.push('sabotage CSS not on the page (fixture)'); }
			if (!guard) { bad.push('guard did not react (no cmp--guard)'); }
			if (!warned) { bad.push('no console warning from the guard'); }
		} else if (guard || warned) {
			bad.push('cmp--guard on the default look (false alarm)');
		}
		return bad;
	} finally {
		await ctx.close();
	}
}

/**
 * Lot review I1 + I2: backdrop "none" behaves like a policy page. The banner is
 * on screen, but the page scrolls (no body lock), a click at the page corner
 * reaches the page, Tab leaves the banner (no trap), the dialog says
 * aria-modal="false", the guard stays quiet; the theme font (inherit) reaches
 * the banner. Trackers are checked by the wp-strict:no-backdrop scenario.
 */
async function noBackdrop(browser, { url, host }) {
	const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
	const warns = [];
	try {
		await ctx.addCookies([{ name: 'cmp_e2e_backdrop', value: 'none', domain: host, path: '/' }, { name: 'cmp_e2e_mode', value: 'strict', domain: host, path: '/' }]);
		await ctx.route('**/*', (route) => (isLocal(route.request().url()) ? route.continue() : route.abort('blockedbyclient')));
		const page = await ctx.newPage();
		page.on('console', (m) => { if (m.type() === 'warning') { warns.push(m.text()); } });
		const resp = await page.goto(url, { waitUntil: 'load', timeout: 90000 });
		if (!resp || !resp.ok()) {
			throw new Error('page ' + url + ' answered ' + (resp ? resp.status() : 'nothing'));
		}
		await page.waitForSelector('[data-cmp]:not([hidden])', { timeout: 15000 });
		await page.waitForTimeout(900);
		const m = await page.evaluate(() => {
			const root = document.querySelector('[data-cmp]');
			const modal = root.querySelector('[data-cmp-modal]');
			// Make sure the page has something to scroll, then scroll it.
			const tall = document.createElement('div');
			tall.style.height = '3000px';
			document.body.appendChild(tall);
			window.scrollTo(0, 400);
			const hit = document.elementFromPoint(5, 5);
			const focusables = [].filter.call(root.querySelectorAll('button, a[href], input, [tabindex]:not([tabindex="-1"])'), (e) => e.getClientRects().length > 0);
			const last = focusables[focusables.length - 1];
			if (last) { last.focus(); }
			return {
				classes: root.className,
				ariaModal: modal ? modal.getAttribute('aria-modal') : null,
				locked: document.body.classList.contains('cmp-has-banner'),
				overflow: getComputedStyle(document.body).overflowY,
				scrollY: window.scrollY,
				clickThrough: !!hit && !root.contains(hit),
				lastFocused: !!last && document.activeElement === last,
				font: getComputedStyle(modal).fontFamily,
				bodyFont: getComputedStyle(document.body).fontFamily,
			};
		});
		await page.keyboard.press('Tab');
		const left = await page.evaluate(() => !document.querySelector('[data-cmp]').contains(document.activeElement));
		const bad = [];
		if (m.classes.indexOf('cmp--backdrop-none') === -1) { bad.push('backdrop none not applied (' + m.classes + ')'); }
		if (m.ariaModal !== 'false') { bad.push('aria-modal=' + m.ariaModal); }
		if (m.locked || m.overflow === 'hidden') { bad.push('scroll locked (body ' + (m.locked ? '.cmp-has-banner' : 'overflow hidden') + ')'); }
		if (!(m.scrollY > 0)) { bad.push('page did not scroll'); }
		if (!m.clickThrough) { bad.push('a click at the page corner hits the banner'); }
		if (!m.lastFocused) { bad.push('could not focus the last banner control'); }
		if (!left) { bad.push('Tab stayed in the banner (focus trap)'); }
		if (m.font !== m.bodyFont) { bad.push('theme font not inherited: ' + m.font + ' vs body ' + m.bodyFont); }
		if (warns.some((w) => w.indexOf('CMP: appearance guard') !== -1)) { bad.push('guard false alarm'); }
		return bad;
	} finally {
		await ctx.close();
	}
}

/**
 * Per-service verdict for one scenario. gated: services behind a gate (the Maps API
 * loader): after "accept" they come back on the next page, never on this one.
 */
function judge(services, allowed, action, external, svcFor, gated) {
	const rows = [];
	// The fixture page holds only library fragments, so an external request the
	// matcher cannot attribute is a leak whenever nothing may load: before the
	// decision, and after "reject". After "accept" it is only noted.
	const unattributed = external.filter((r) => !svcFor(r.url));
	const unknown = [];
	const leaky = [];
	unattributed.forEach((r) => {
		if (r.phase === 'before' || action === 'reject') {
			leaky.push(r);
		} else {
			unknown.push(r.phase + ' ' + r.url);
		}
	});
	for (const s of services) {
		const mine = external.filter((r) => svcFor(r.url) === s.id);
		const before = mine.filter((r) => r.phase === 'before');
		const after = mine.filter((r) => r.phase === 'after');
		let status = 'ok';
		let url = '';
		if (allowed.indexOf(s.id) !== -1) {
			if (!before.length) {
				status = 'MISSING';
				url = '(allowed before consent, but never requested)';
			} else {
				url = before[0].url;
			}
		} else if (before.length) {
			status = 'LEAK';
			url = before[0].url;
		} else if (action === 'accept' && (gated || []).indexOf(s.id) !== -1) {
			if (after.length) {
				status = 'LEAK';
				url = after[0].url + ' (gated: only on the next page)';
			} else {
				url = '(gated: on the next page)';
			}
		} else if (action === 'accept') {
			if (!after.length) {
				status = 'MISSING';
				url = '(not requested after "accept all")';
			} else {
				url = after[0].url;
			}
		} else if (action === 'reject' && after.length) {
			status = 'LEAK';
			url = after[0].url + ' (after "reject")';
		}
		rows.push({ service: s.id, status, url });
	}
	leaky.forEach((r) => rows.push({ service: '(unattributed)', status: 'LEAK', url: r.url + (r.phase === 'after' ? ' (after "reject")' : '') }));
	return { rows, unknown };
}

/** Negative control: with blocking off every fixture fragment must fire. */
function judgeControl(services, external, svcFor, urls) {
	const rows = services.map((s) => {
		const hit = external.find((r) => svcFor(r.url) === s.id);
		// Every fixture URL of the service (script, inline img / beacon / fetch, iframe) must be seen.
		const missed = (urls[s.id] || []).filter((u) => !external.some((r) => r.url === u));
		if (!hit || missed.length) {
			return { service: s.id, status: 'NOFIRE', url: (missed[0] || '') + ' (fixture fragment never requested: harness blind)' };
		}
		return { service: s.id, status: 'ok', url: hit.url };
	});
	const unknown = external.filter((r) => !svcFor(r.url)).map((r) => r.phase + ' ' + r.url);
	return { rows, unknown };
}

function pad(s, n) {
	s = String(s);
	return s.length >= n ? s : s + ' '.repeat(n - s.length);
}

async function main() {
	const base = arg('base').replace(/\/+$/, '');
	const standalone = arg('standalone');
	if (!base || !standalone) {
		console.error('Usage: node tests/e2e/run.js --base http://127.0.0.1:<port> --standalone http://127.0.0.1:<port2>/tests/e2e/standalone.html');
		process.exit(2);
	}
	const started = Date.now();
	const library = JSON.parse(fs.readFileSync(path.join(ROOT, 'data', 'services.json'), 'utf8'));
	const services = library.services;
	const built = fixture.build(library);
	const svcFor = (u) => serviceFor(u, services);
	const harness = [];

	// What the plugin itself lets load before consent.
	let expect;
	try {
		const r = await fetch(base + '/?cmp_e2e_expect=1', { signal: AbortSignal.timeout(30000) });
		expect = await r.json();
	} catch (e) {
		console.error('E2E harness: cannot read ' + base + '/?cmp_e2e_expect=1 (' + e.message + ')');
		process.exit(1);
	}
	if (!expect || !Array.isArray(expect.before) || !Array.isArray(expect.advancedBefore)) {
		console.error('E2E harness: bad expect payload: ' + JSON.stringify(expect));
		process.exit(1);
	}
	if (expect.usingFallback) {
		harness.push('the plugin runs on its fallback library (data/services.json not loaded)');
	}
	const pluginIds = (expect.library || []).map((s) => s.id).sort();
	const fileIds = services.map((s) => s.id).sort();
	if (JSON.stringify(pluginIds) !== JSON.stringify(fileIds)) {
		harness.push('plugin library ' + JSON.stringify(pluginIds) + ' differs from data/services.json ' + JSON.stringify(fileIds));
	}

	const { chromium } = require('playwright');
	const browser = await chromium.launch({
		channel: 'chromium',
		// Every non-local host resolves to nothing: no test traffic can leave the Mac.
		args: ['--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1, EXCLUDE localhost'],
	});

	const wpUrl = base + '/?pagename=cmp-e2e';
	const host = new URL(base).hostname;
	const modeCookie = (v) => [{ name: 'cmp_e2e_mode', value: v, domain: host, path: '/' }];
	// Independent floor (see NEVER_BEFORE) and category cross-check.
	const floor = (label, allowed) => {
		const bad = (allowed || []).filter((id) => NEVER_BEFORE.indexOf(id) !== -1);
		if (bad.length) {
			harness.push(label + ' would let ' + JSON.stringify(bad) + ' load before consent (never allowed)');
		}
	};
	floor('WP Strict', expect.before);
	floor('WP Advanced', expect.advancedBefore);
	floor('standalone', built.standalone.before);
	const advExtra = expect.advancedBefore.filter((id) => expect.before.indexOf(id) === -1 && ADVANCED_EXTRA.indexOf(id) === -1);
	if (advExtra.length) {
		harness.push('Advanced adds ' + JSON.stringify(advExtra) + ' before consent (only GA4 / GTM may be added)');
	}
	const fileCat = {};
	services.forEach((s) => { fileCat[s.id] = s.category; });
	(expect.library || []).forEach((s) => {
		if (fileCat[s.id] !== s.category) {
			harness.push('plugin category of ' + s.id + ' is "' + s.category + '", data/services.json says "' + fileCat[s.id] + '"');
		}
	});

	// Standalone allows only necessary (recaptcha): cmp.js has no exemption
	// logic, it blocks exactly what the site marks, and the fixture marks every
	// non-necessary service as standalone/README.md says (exempt_default is a
	// WordPress-side flag). Do not "align" it with WP Strict.
	const scenarios = [
		{ name: 'control:unblocked', url: wpUrl, cookies: modeCookie('unblocked'), action: null, control: true },
		{ name: 'wp-strict:new', url: wpUrl, cookies: modeCookie('strict'), action: null, allowed: expect.before },
		{ name: 'wp-strict:accept', url: wpUrl, cookies: modeCookie('strict'), action: 'accept', allowed: expect.before },
		{ name: 'wp-strict:reject', url: wpUrl, cookies: modeCookie('strict'), action: 'reject', allowed: expect.before },
		{ name: 'wp-advanced:new', url: wpUrl, cookies: modeCookie('advanced'), action: null, allowed: expect.advancedBefore },
		{ name: 'wp-advanced:accept', url: wpUrl, cookies: modeCookie('advanced'), action: 'accept', allowed: expect.advancedBefore },
		{ name: 'wp-advanced:reject', url: wpUrl, cookies: modeCookie('advanced'), action: 'reject', allowed: expect.advancedBefore },
		// Lot review I2: backdrop "none" never blocks the page, and trackers stay blocked.
		{ name: 'wp-strict:no-backdrop', url: wpUrl, cookies: modeCookie('strict').concat([{ name: 'cmp_e2e_backdrop', value: 'none', domain: host, path: '/' }]), action: null, allowed: expect.before },
		{ name: 'standalone:new', url: standalone, cookies: [], action: null, allowed: built.standalone.before },
		{ name: 'standalone:accept', url: standalone, cookies: [], action: 'accept', allowed: built.standalone.before },
		{ name: 'standalone:reject', url: standalone, cookies: [], action: 'reject', allowed: built.standalone.before },
	];

	const table = [];
	const looks = [];
	const unknownBy = {};
	try {
		for (const sc of scenarios) {
			let res;
			try {
				const external = await visit(browser, sc);
				res = sc.control ? judgeControl(services, external, svcFor, built.urls) : judge(services, sc.allowed, sc.action, external, svcFor, sc.name.indexOf('wp-') === 0 ? expect.gated : []);
			} catch (e) {
				harness.push(sc.name + ': ' + e.message.split('\n')[0]);
				continue;
			}
			res.rows.forEach((r) => table.push(Object.assign({ scenario: sc.name }, r)));
			if (res.unknown.length) {
				unknownBy[sc.name] = res.unknown;
			}
		}
		// B6.18: every style x button style passes on its own; the sabotage is caught.
		for (const style of ['minimal', 'brand', 'friendly']) {
			for (const buttons of ['solid', 'outline', 'text']) {
				looks.push({ name: 'look:' + style + '.' + buttons, url: base + '/', host, style, buttons, sabotage: false });
			}
		}
		// A phone (360 px band) with the outline style.
		looks.push({ name: 'look:phone.friendly.outline', url: base + '/', host, style: 'friendly', buttons: 'outline', sabotage: false, width: 360 });
		// Hostile CSS printed outside the cleaner: plain, id-based and @layer !important.
		['1', 'id', 'layer'].forEach((k) => looks.push({ name: 'look:sabotage.' + k, url: base + '/', host, style: 'minimal', buttons: 'text', sabotage: k }));
		// Lot review I1 + I2: backdrop "none" = no lock, no trap, aria-modal false; theme font.
		looks.push({ name: 'look:no-backdrop', url: base + '/', host, run: noBackdrop });
		for (const lk of looks) {
			try {
				lk.problems = await (lk.run || look)(browser, lk);
			} catch (e) {
				harness.push(lk.name + ': ' + e.message.split('\n')[0]);
				lk.problems = ['harness error'];
			}
		}
	} finally {
		await browser.close();
	}

	// Report.
	console.log('');
	console.log('CMP e2e — zero tracker requests before consent');
	console.log('WordPress: ' + wpUrl);
	console.log('Standalone: ' + standalone);
	console.log('Allowed before consent — Strict: ' + JSON.stringify(expect.before) + ', Advanced: ' + JSON.stringify(expect.advancedBefore) + ', standalone: ' + JSON.stringify(built.standalone.before));
	console.log('');
	console.log(pad('service', 20) + ' | ' + pad('scenario', 20) + ' | ' + pad('status', 7) + ' | url');
	console.log('-'.repeat(20) + '-+-' + '-'.repeat(20) + '-+-' + '-'.repeat(7) + '-+-' + '-'.repeat(40));
	for (const r of table) {
		console.log(pad(r.service, 20) + ' | ' + pad(r.scenario, 20) + ' | ' + pad(r.status, 7) + ' | ' + r.url);
	}
	const bad = table.filter((r) => r.status !== 'ok');
	const leaks = table.filter((r) => r.status === 'LEAK').length;
	const missing = table.filter((r) => r.status === 'MISSING').length;
	const nofire = table.filter((r) => r.status === 'NOFIRE').length;
	console.log('');
	Object.keys(unknownBy).forEach((k) => {
		console.log('Note (' + k + '): external requests not attributed to a library service (aborted, not judged):');
		unknownBy[k].forEach((u) => console.log('  ' + u));
	});
	console.log('');
	console.log('B6.18 guarantees — Accept / Reject equal (±2px), visible, opacity >= 0.9, >= 24px; guard only on sabotage');
	looks.forEach((lk) => console.log(pad(lk.name, 24) + ' | ' + (lk.problems && !lk.problems.length ? 'ok' : 'FAIL ' + (lk.problems || []).join('; '))));
	const looksBad = looks.filter((lk) => !lk.problems || lk.problems.length).length;
	console.log('Guarantees: ' + (looks.length - looksBad) + '/' + looks.length + ' ok');
	harness.forEach((h) => console.log('HARNESS ERROR: ' + h));
	const scenarioCount = scenarios.length;
	const unattributedLeaks = table.filter((r) => r.service === '(unattributed)').length;
	console.log(
		(bad.length || harness.length || looksBad ? 'FAIL' : 'PASS') + ': ' + services.length + ' services x ' + scenarioCount + ' scenarios = '
		+ table.length + ' checks; ok ' + (table.length - bad.length) + ', LEAK ' + leaks + ', MISSING ' + missing + ', NOFIRE ' + nofire
		+ ' (unattributed ' + unattributedLeaks + ')' + ', guarantees ' + (looks.length - looksBad) + '/' + looks.length + ', harness errors ' + harness.length + ' (' + ((Date.now() - started) / 1000).toFixed(1) + ' s)'
	);
	process.exit(bad.length || harness.length || looksBad ? 1 : 0);
}

main().catch((e) => {
	console.error('E2E harness: ' + (e && e.stack ? e.stack : e));
	process.exit(1);
});
