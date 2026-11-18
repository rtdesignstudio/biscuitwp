'use strict';
var cmp = require('../cmp.js');
var fails = 0, passes = 0;
function ok(cond, msg) { if (cond) { passes++; } else { fails++; console.error('FAIL: ' + msg); } }
function eq(a, b, msg) { ok(JSON.stringify(a) === JSON.stringify(b), msg + ' (got ' + JSON.stringify(a) + ')'); }

// normalizeConfig defaults
var c = cmp.normalizeConfig({});
eq(c.mode, 'pro', 'default mode pro');
eq(c.lang, 'ro', 'default lang ro');
ok(c.categories.some(function (x) { return x.slug === 'necessary' && x.required; }), 'necessary always present + required');

// invalid values fall back
eq(cmp.normalizeConfig({ mode: 'x', lang: 'zz' }).mode, 'pro', 'bad mode -> pro');
eq(cmp.normalizeConfig({ lang: 'zz' }).lang, 'ro', 'bad lang -> ro');

// categories filtered + necessary forced in
var c2 = cmp.normalizeConfig({ categories: ['statistics', 'bogus'] });
ok(c2.categories.some(function (x) { return x.slug === 'necessary'; }), 'necessary injected even if omitted');
ok(!c2.categories.some(function (x) { return x.slug === 'bogus'; }), 'unknown slug dropped');

// signalsFor
var cats = cmp.normalizeConfig({ categories: ['necessary', 'statistics', 'marketing'] }).categories;
eq(cmp.signalsFor(cats, []).analytics_storage, 'denied', 'analytics denied when not accepted');
eq(cmp.signalsFor(cats, ['statistics']).analytics_storage, 'granted', 'analytics granted when accepted');
eq(cmp.signalsFor(cats, ['marketing']).ad_storage, 'granted', 'ad_storage granted with marketing');

// isToggleOn: off without consent / stale version, mirrors saved choice otherwise
eq(cmp.isToggleOn('marketing', null, '1'), false, 'no consent -> toggle off');
eq(cmp.isToggleOn('marketing', { v: '1', categories: ['necessary', 'marketing'] }, '1'), true, 'saved on -> on');
eq(cmp.isToggleOn('statistics', { v: '1', categories: ['necessary', 'marketing'] }, '1'), false, 'saved off -> off');
eq(cmp.isToggleOn('marketing', { v: '0', categories: ['marketing'] }, '1'), false, 'stale version -> off');
// storedCategories: what a returning visitor's consent replays (signals + scripts)
eq(cmp.storedCategories(null, '1'), null, 'no consent -> nothing to replay');
eq(cmp.storedCategories({ v: '0', categories: ['statistics'] }, '1'), null, 'stale version -> nothing to replay');
eq(JSON.stringify(cmp.storedCategories({ v: '1', categories: ['necessary', 'statistics'] }, '1')), '["necessary","statistics"]', 'valid consent -> its categories');
eq(cmp.signalsFor([{ slug: 'statistics', signals: ['analytics_storage'] }], cmp.storedCategories({ v: '1', categories: ['statistics'] }, '1')).analytics_storage, 'granted', 'returning accept -> analytics granted');
eq(cmp.signalsFor([{ slug: 'statistics', signals: ['analytics_storage'] }], cmp.storedCategories({ v: '1', categories: ['necessary'] }, '1')).analytics_storage, 'denied', 'returning reject -> analytics denied');
// consentIdFor: keep the visitor's ID across changes, create a v4 UUID otherwise
var UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
var fresh = cmp.consentIdFor(null);
ok(UUID.test(fresh), 'new consent ID is a v4 UUID (got ' + fresh + ')');
ok(cmp.consentIdFor(null) !== fresh, 'two new IDs differ');
eq(cmp.consentIdFor({ id: '3f2b8c1e-9a4d-4e6b-8c2f-1a2b3c4d5e6f' }), '3f2b8c1e-9a4d-4e6b-8c2f-1a2b3c4d5e6f', 'existing ID kept');
ok(UUID.test(cmp.consentIdFor({ id: 'garbage' })), 'malformed stored ID replaced');
// isPolicyPage: the privacy policy page itself shows the banner without overlay
eq(cmp.isPolicyPage('https://lp.md/privacy/', 'https://lp.md/privacy/#top'), true, 'same page (hash ignored)');
eq(cmp.isPolicyPage('https://lp.md/privacy', 'https://lp.md/privacy/?utm=x'), true, 'trailing slash and query ignored');
eq(cmp.isPolicyPage('/privacy.html', 'https://lp.md/privacy.html'), true, 'relative privacyUrl resolved against the page');
eq(cmp.isPolicyPage('https://lp.md/privacy/', 'https://lp.md/'), false, 'home page is not the policy page');
eq(cmp.isPolicyPage('https://other.md/privacy/', 'https://lp.md/privacy/'), false, 'other host is not');
eq(cmp.isPolicyPage('', 'https://lp.md/'), false, 'no privacyUrl -> never');
// gpcOn: Global Privacy Control read in the browser (cache-safe); DNT is ignored
eq(cmp.gpcOn({ globalPrivacyControl: true }), true, 'GPC true -> refuse');
eq(cmp.gpcOn({ globalPrivacyControl: false }), false, 'GPC false -> ask');
eq(cmp.gpcOn({ doNotTrack: '1' }), false, 'DNT alone no longer refuses');
eq(cmp.gpcOn(null), false, 'no navigator -> ask');
// embedLoads: an embed comes back only when its category was accepted
eq(cmp.embedLoads('marketing', ['necessary', 'marketing']), true, 'embed loads when its category is accepted');
eq(cmp.embedLoads('marketing', ['necessary']), false, 'embed stays blocked without its category');
eq(cmp.embedLoads('', ['necessary', 'marketing']), false, 'embed without a category never auto-loads');
eq(cmp.embedLoads('statistics', null), false, 'no consent -> embed stays blocked');
// reloadOnDecision: off by default, on only when explicitly true
eq(cmp.normalizeConfig({}).reloadOnDecision, false, 'reloadOnDecision default false');
eq(cmp.normalizeConfig({ reloadOnDecision: true }).reloadOnDecision, true, 'reloadOnDecision true honored');
eq(cmp.normalizeConfig({ reloadOnDecision: 'yes' }).reloadOnDecision, false, 'reloadOnDecision non-boolean ignored');
// fbqConsentFor: Meta Pixel follows the marketing category
eq(cmp.fbqConsentFor(['necessary', 'marketing']), 'grant', 'marketing accepted -> grant');
eq(cmp.fbqConsentFor(['necessary', 'statistics']), 'revoke', 'marketing not accepted -> revoke');
eq(cmp.fbqConsentFor(null), 'revoke', 'no categories -> revoke');
// decisionFor
eq(cmp.decisionFor(['necessary', 'statistics', 'marketing'], cats), 'accepted_all', 'all -> accepted_all');
eq(cmp.decisionFor(['necessary'], cats), 'rejected_all', 'only required -> rejected_all');
eq(cmp.decisionFor(['necessary', 'statistics'], cats), 'partial', 'some -> partial');

// texts: config overrides win, defaults fill the rest, shared defaults stay unmutated
var ct = cmp.normalizeConfig({ lang: 'en', texts: { accept: 'Allow all now' } });
eq(ct.texts.accept, 'Allow all now', 'config.texts overrides the default');
ok(typeof ct.texts.reject === 'string' && ct.texts.reject.length > 0, 'non-overridden text falls back to default');
ct.texts.accept = 'MUTATED';
eq(cmp.normalizeConfig({ lang: 'en' }).texts.accept, cmp.CMP_DEFAULTS.i18n.en.texts.accept, 'mutating a returned texts does not corrupt defaults');

// safeUrl blocks javascript:/data:, keeps relative + http(s) + mailto
eq(cmp.safeUrl('javascript:alert(1)'), '', 'javascript: scheme blocked');
eq(cmp.safeUrl('  javAScript:alert(1)'), '', 'javascript: with leading space/case blocked');
eq(cmp.safeUrl('data:text/html,x'), '', 'data: scheme blocked');
eq(cmp.safeUrl('/politica.html'), '/politica.html', 'relative url kept');
eq(cmp.safeUrl('https://x.md/p'), 'https://x.md/p', 'https url kept');
eq(cmp.normalizeConfig({ privacyUrl: 'javascript:alert(1)' }).privacyUrl, '', 'normalizeConfig sanitizes privacyUrl');

// decisionFor with zero optional categories → accepted_all (nothing to reject)
var onlyReq = cmp.normalizeConfig({ categories: ['necessary'] }).categories;
eq(cmp.decisionFor(['necessary'], onlyReq), 'accepted_all', 'no optional cats -> accepted_all');

// reopenButton: off by default (footer link instead), on only when explicitly true
eq(cmp.normalizeConfig({}).reopenButton, false, 'reopenButton default false');
eq(cmp.normalizeConfig({ reopenButton: false }).reopenButton, false, 'reopenButton false honored');
eq(cmp.normalizeConfig({ reopenButton: true }).reopenButton, true, 'reopenButton true');
// reopen label present in every language
eq(cmp.normalizeConfig({ lang: 'en' }).texts.reopen, 'Cookie settings', 'en reopen label');
ok(typeof cmp.normalizeConfig({ lang: 'ru' }).texts.reopen === 'string' && cmp.normalizeConfig({ lang: 'ru' }).texts.reopen.length > 0, 'ru reopen label present');

// cookie serialize/parse
var s = cmp.serializeConsent({ v: '1', categories: ['necessary'], decision: 'rejected_all', ts: 5 });
eq(cmp.parseConsent(s).decision, 'rejected_all', 'roundtrip decision');
eq(cmp.parseConsent('not json'), null, 'bad json -> null');

// cookieDays: default, clamp, out-of-range fallback
eq(cmp.normalizeConfig({}).cookieDays, 180, 'cookieDays default 180');
eq(cmp.normalizeConfig({ cookieDays: 90 }).cookieDays, 90, 'cookieDays kept');
eq(cmp.normalizeConfig({ cookieDays: 0 }).cookieDays, 180, 'cookieDays 0 -> 180');
eq(cmp.normalizeConfig({ cookieDays: 99999 }).cookieDays, 396, 'cookieDays over 13 months -> 396');
eq(cmp.normalizeConfig({ cookieDays: 396 }).cookieDays, 396, 'cookieDays 396 kept');
eq(cmp.normalizeConfig({ cookieDays: 'x' }).cookieDays, 180, 'cookieDays not a number -> 180');

// escapeHtml
eq(cmp.escapeHtml('<a>"&\''), '&lt;a&gt;&quot;&amp;&#39;', 'escapes html');

// B1: "Always load" cookie (cmp_svc), pure helpers
eq(cmp.svcAllowed(null, '1'), [], 'svcAllowed: no cookie');
eq(cmp.svcAllowed({ v: '0', svc: ['youtube'] }, '1'), [], 'svcAllowed: other version');
eq(cmp.svcAllowed({ v: '1', svc: 'x' }, '1'), [], 'svcAllowed: svc not a list');
eq(cmp.svcAllowed({ v: '1', svc: ['youtube', '<x>'] }, '1'), ['youtube'], 'svcAllowed: bad ids dropped');
eq(cmp.svcAllowed({ v: '1', svc: ['youtube'], id: 'whatever', extra: 1 }, '1'), ['youtube'], 'svcAllowed ignores extra fields');
eq(cmp.svcAllowed({ v: 1, svc: ['vimeo'] }, '1'), ['vimeo'], 'svcAllowed compares version as string');
eq(cmp.withSvc(null, '1', 'youtube', true), { v: '1', svc: ['youtube'] }, 'withSvc adds to an empty cookie');
eq(cmp.withSvc({ v: '1', svc: ['youtube'] }, '1', 'youtube', false).svc, [], 'withSvc removes');
eq(cmp.withSvc({ v: '1', svc: ['youtube'] }, '1', 'youtube', true).svc, ['youtube'], 'withSvc does not duplicate');
eq(cmp.withSvc({ v: '1', svc: ['youtube'] }, '1', 'vimeo', true).svc, ['youtube', 'vimeo'], 'withSvc appends');
eq(cmp.withSvc({ v: '0', svc: ['youtube'] }, '1', 'vimeo', true), { v: '1', svc: ['vimeo'] }, 'withSvc drops an old-version list');
var SID = '3f2b8c1e-9a4d-4e6b-8c2f-1a2b3c4d5e6f';
eq(cmp.withSvc({ v: '1', svc: ['youtube'], id: SID }, '1', 'vimeo', true).id, SID, 'withSvc keeps the id');
ok(cmp.withSvc({ v: '1', svc: ['youtube'], id: SID }, '1', 'youtube', false).id === SID, 'withSvc keeps the id on removal');
ok(!('id' in cmp.withSvc(null, '1', 'youtube', true)), 'withSvc adds no id when there is none');
['ro', 'ru', 'en'].forEach(function (l) {
	var t = cmp.normalizeConfig({ lang: l }).texts;
	['embed_always', 'always_loaded', 'always_remove'].forEach(function (k) { ok(typeof t[k] === 'string' && t[k].length > 0, l + ' text ' + k); });
	ok(t.always_remove.indexOf('%s') !== -1, l + ' always_remove has %s');
});
eq(cmp.normalizeConfig({ lang: 'ro' }).texts.always_loaded, 'Încărcate mereu:', 'ro always_loaded');
eq(cmp.normalizeConfig({ lang: 'ru' }).texts.embed_always, 'Загружать всегда', 'ru embed_always');
eq(cmp.normalizeConfig({ lang: 'en' }).texts.always_remove, 'Stop loading %s', 'en always_remove');

// Fix round 1 (review I1-I3, M4, M6, M7)
eq(cmp.svcAllowed({ v: '1', svc: ['youtube', 'vimeo', 'youtube'] }, '1'), ['youtube', 'vimeo'], 'svcAllowed drops duplicates, keeps order');
eq(cmp.svcLogPayload('service_withdraw', 'youtube', ['marketing'], SID), { id: SID, categories: ['marketing'], decision: 'service_withdraw', services: ['youtube'] }, 'log payload carries only the affected service');
eq(cmp.svcLogPayload('service_allow', 'vimeo', null, ''), { id: '', categories: [], decision: 'service_allow', services: ['vimeo'] }, 'log payload: no categories -> []');
eq(cmp.parseSvcCookie('%E0%A4%A'), null, 'malformed cookie value -> null, no throw');
eq(cmp.parseSvcCookie('not json'), null, 'non-JSON cookie value -> null');
eq(cmp.svcAllowed(cmp.parseSvcCookie('%E0%A4%A'), '1'), [], 'malformed cookie -> no services');
eq(cmp.parseSvcCookie(encodeURIComponent('{"v":"1","svc":["youtube"]}')).svc, ['youtube'], 'valid cookie value parsed');
eq(cmp.consentIdFor(null, SID), SID, 'consentIdFor adopts the fallback id');
eq(cmp.consentIdFor({ id: '3f2b8c1e-9a4d-4e6b-8c2f-1a2b3c4d5e70' }, SID), '3f2b8c1e-9a4d-4e6b-8c2f-1a2b3c4d5e70', 'own id wins over the fallback');
ok(/^[0-9a-f-]{36}$/.test(cmp.consentIdFor(null, 'junk')), 'invalid fallback ignored');
eq(cmp.fillName('Stop loading %s', 'A$&B'), 'Stop loading A$&B', 'fillName is literal');

// wpConsentValues lives in assets/js/banner.js (an IIFE bound to the DOM, so it
// cannot be required); the test extracts the function source and evaluates it.
var bannerSrc = require('fs').readFileSync(require('path').join(__dirname, '../../assets/js/banner.js'), 'utf8');
var wpM = bannerSrc.match(/\/\/ wpConsentValues:begin\n([\s\S]*?)\/\/ wpConsentValues:end/);
ok(!!wpM, 'banner.js exposes the wpConsentValues block');
var wpConsentValues = wpM ? new Function(wpM[1] + '\nreturn wpConsentValues;')() : function () { return {}; };
var wpInitValues = wpM ? new Function(wpM[1] + '\nreturn wpInitValues;')() : function () { return null; };
var WPMAP = { necessary: ['functional'], statistics: ['statistics', 'statistics-anonymous'], marketing: ['marketing'], preferences: ['preferences'] };
eq(wpConsentValues(WPMAP, ['necessary', 'statistics', 'marketing', 'preferences']), { functional: 'allow', statistics: 'allow', 'statistics-anonymous': 'allow', marketing: 'allow', preferences: 'allow' }, 'wp consent: all accepted -> all allow');
eq(wpConsentValues(WPMAP, ['necessary']), { functional: 'allow', statistics: 'deny', 'statistics-anonymous': 'deny', marketing: 'deny', preferences: 'deny' }, 'wp consent: only necessary -> functional allow, rest deny');
eq(wpConsentValues(WPMAP, []), { functional: 'allow', statistics: 'deny', 'statistics-anonymous': 'deny', marketing: 'deny', preferences: 'deny' }, 'wp consent: functional always allow');
eq(wpConsentValues(WPMAP, ['necessary', 'statistics']), { functional: 'allow', statistics: 'allow', 'statistics-anonymous': 'allow', marketing: 'deny', preferences: 'deny' }, 'wp consent: statistics allows statistics + statistics-anonymous');
eq(wpConsentValues(WPMAP, ['necessary', 'custom_x', 'bogus']), { functional: 'allow', statistics: 'deny', 'statistics-anonymous': 'deny', marketing: 'deny', preferences: 'deny' }, 'wp consent: unknown accepted slugs ignored');
eq(wpConsentValues({ necessary: ['functional'], custom_x: ['marketing'], marketing: ['marketing'] }, ['custom_x']), { functional: 'allow', marketing: 'allow' }, 'wp consent: shared WP category allows if any mapped slug is accepted');
eq(wpConsentValues({ necessary: ['functional'], a: ['marketing'], b: ['marketing'] }, []), { functional: 'allow', marketing: 'deny' }, 'wp consent: shared WP category denies when none accepted');
eq(wpConsentValues(null, ['marketing']), {}, 'wp consent: no map -> nothing');

// Final fix wave I2: without a valid decision every mapped category is pushed
// (functional allow, the rest deny), so stale 'allow' values never survive.
var WPDENY = { functional: 'allow', statistics: 'deny', 'statistics-anonymous': 'deny', marketing: 'deny', preferences: 'deny' };
eq(wpInitValues(WPMAP), WPDENY, 'wp init: no decision -> functional allow, every other mapped category deny');
eq(wpInitValues({ marketing: ['marketing'] }), { marketing: 'deny' }, 'wp init: no decision, no functional entry -> marketing deny');
eq(wpInitValues(null), {}, 'wp init: no map -> nothing');
eq(wpInitValues(WPMAP, null, '2', 'pro', ['necessary']), WPDENY, 'wp init I2: no cookie -> deny');
eq(wpInitValues(WPMAP, { v: '1', categories: ['necessary', 'statistics', 'marketing'] }, '2', 'pro', ['necessary']), WPDENY, 'wp init I2: other policy version -> deny');
eq(wpInitValues(WPMAP, { v: '2', categories: ['necessary', 'statistics', 'marketing'] }, '2', 'light', ['necessary']), WPDENY, 'wp init I2: Light mode -> deny even with a cookie');
eq(wpInitValues(WPMAP, { v: '2', categories: 'statistics' }, '2', 'pro', ['necessary']), WPDENY, 'wp init I2: malformed cookie (categories not a list) -> deny');
eq(wpInitValues(WPMAP, 'garbage', '2', 'pro', ['necessary']), WPDENY, 'wp init I2: invalid cookie value -> deny');
eq(wpInitValues(WPMAP, { v: '2', categories: ['necessary', 'statistics'] }, '2', 'pro', ['necessary']), { functional: 'allow', statistics: 'allow', 'statistics-anonymous': 'allow', marketing: 'deny', preferences: 'deny' }, 'wp init I2: valid decision -> its categories');
eq(wpInitValues(WPMAP, { v: 2, categories: ['marketing'] }, '2', 'pro', ['necessary']).marketing, 'allow', 'wp init I2: numeric version in the cookie still matches');
ok(/function pushWpInit\(\) \{[\s\S]*?wpInitValues\(D\.wpConsent, readCookie\(\), D\.version, D\.mode, requiredSlugs\(\)\)/.test(bannerSrc), 'wp init I2: pushWpInit passes the cookie, version, mode and required slugs');

// T8 M5 (owner decision): the explicit reject clears "always load" in both builds.
var SV_ID = '3f2b8c1e-9a4d-4e6b-8c2f-1a2b3c4d5e6f';
var rjM = bannerSrc.match(/\/\/ svcAfterReject:begin\n([\s\S]*?)\/\/ svcAfterReject:end/);
ok(!!rjM, 'banner.js exposes the svcAfterReject block');
var UUIDRE = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
var wpAfterReject = rjM ? new Function('UUID_RE', rjM[1] + '\nreturn svcAfterReject;')(UUIDRE) : function () { return null; };
[['banner.js', wpAfterReject], ['cmp.js', cmp.svcAfterReject]].forEach(function (b) {
	var f = b[1], n = b[0];
	eq(f({ v: '1', svc: ['youtube', 'vimeo'], id: SV_ID }, '1'), { v: '1', svc: [], id: SV_ID }, n + ' svcAfterReject: list cleared, id kept');
	eq(f({ v: '0', svc: ['youtube'], id: SV_ID }, 2), { v: '2', svc: [], id: SV_ID }, n + ' svcAfterReject: current version written');
	eq(f({ v: '1', svc: ['youtube'], id: 'junk' }, '1'), { v: '1', svc: [] }, n + ' svcAfterReject: invalid id dropped');
	eq(f(null, '1'), { v: '1', svc: [] }, n + ' svcAfterReject: no cookie');
});
var cmpSrc = require('fs').readFileSync(require('path').join(__dirname, '../cmp.js'), 'utf8');
[['banner.js', bannerSrc], ['cmp.js', cmpSrc]].forEach(function (b) {
	ok(/action === 'reject'\) \{ save\(requiredSlugs\(\), 'rejected_all'\); rejectServices\(\); \}/.test(b[1]), b[0] + ': the reject action clears always-load');
	ok((b[1].match(/rejectServices\(\)/g) || []).length === 2, b[0] + ': rejectServices is called only by the reject action (not save / GPC)');
	ok(/function rejectServices\(\) \{[\s\S]*?svcAfterReject\([\s\S]*?renderAlways\(\);/.test(b[1]), b[0] + ': rejectServices writes the cleared cookie and refreshes the row');
});
ok(/function rejectServices\(\) \{[\s\S]*?if \(gone\.length\) \{[\s\S]*?'service_withdraw'[\s\S]*?payload\.services = gone;[\s\S]*?logConsent\(payload\)/.test(bannerSrc), 'banner.js: one service_withdraw naming every withdrawn id, only when the list was not empty');
ok(/pushWpInit\(\);\s*bindEmbeds\(\);/.test(bannerSrc), 'wp init runs at the top of DOMContentLoaded, before the Light-mode return');

// stripAlwaysButtons: Light mode drops the "Always load" buttons
var rm = [], fakeBtn = function () { return { parentNode: { removeChild: function (x) { rm.push(x); } } }; };
var b1 = fakeBtn(), b2 = fakeBtn();
eq(cmp.stripAlwaysButtons({ querySelectorAll: function (sel) { return sel === '[data-cmp-embed-always]' ? [b1, b2] : []; } }), 2, 'stripAlwaysButtons removes every always button');
ok(rm.length === 2 && rm[0] === b1 && rm[1] === b2, 'stripAlwaysButtons removes via the parent');
eq(cmp.stripAlwaysButtons({ querySelectorAll: function () { return []; } }), 0, 'stripAlwaysButtons: none present');

console.log(passes + ' passed, ' + fails + ' failed');
process.exit(fails ? 1 : 0);
