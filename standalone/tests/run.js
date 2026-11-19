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

// Re-consent helpers (B4): same cases against both builds. banner.js's block is
// source-extracted like wpConsentValues; standalone/cmp.js exports its copy.
var rcM = bannerSrc.match(/\/\/ reconsent:begin\n([\s\S]*?)\/\/ reconsent:end/);
ok(!!rcM, 'banner.js exposes the reconsent block');
var rcBanner = rcM ? new Function(rcM[1] + '\nreturn { svcSnapshot: svcSnapshot, newServices: newServices, effectiveCategories: effectiveCategories };')() : {};
[['banner', rcBanner], ['standalone', cmp]].forEach(function (pair) {
	var n = 'reconsent ' + pair[0] + ': ', h = pair[1];
	ok(typeof h.svcSnapshot === 'function' && typeof h.newServices === 'function' && typeof h.effectiveCategories === 'function', n + 'helpers present');
	if (typeof h.svcSnapshot !== 'function' || typeof h.newServices !== 'function' || typeof h.effectiveCategories !== 'function') { return; }
	var MAP = { marketing: ['meta-pixel', 'tiktok-pixel'] };
	eq(h.svcSnapshot(['necessary', 'marketing'], { marketing: ['meta-pixel'], statistics: ['google-analytics'] }), { marketing: ['meta-pixel'] }, n + 'snapshot only accepted categories');
	eq(h.svcSnapshot(['necessary'], { marketing: ['meta-pixel'] }), {}, n + 'known map, nothing accepted -> {}');
	eq(h.svcSnapshot(['marketing'], {}), {}, n + 'known empty map -> {}');
	eq(h.svcSnapshot(['marketing'], undefined), null, n + 'snapshot: undefined map -> null');
	eq(h.svcSnapshot(['marketing'], null), null, n + 'snapshot: null map -> null');
	eq(h.svcSnapshot(['marketing'], 'x'), null, n + 'snapshot: non-object map -> null');
	eq(h.svcSnapshot('marketing', { marketing: ['a'] }), {}, n + 'snapshot: accepted not an array -> {}');
	eq(h.svcSnapshot(['marketing'], { marketing: 'oops' }), {}, n + 'snapshot: non-array value skipped');
	var ck = { categories: ['necessary', 'marketing'], svc: { marketing: ['meta-pixel'] } };
	eq(h.newServices(ck, MAP), { marketing: ['tiktok-pixel'] }, n + 'new service in accepted category');
	eq(h.effectiveCategories(ck, MAP), ['necessary'], n + 'effective drops category with new service');
	eq(h.newServices({ categories: ['necessary'], svc: {} }, { marketing: ['tiktok-pixel'] }), {}, n + 'new service in refused category ignored');
	eq(h.newServices({ categories: ['necessary', 'marketing'], svc: { marketing: ['meta-pixel', 'tiktok-pixel'] } }, { marketing: ['meta-pixel'] }), {}, n + 'removed service -> nothing new');
	var old = { categories: ['marketing'] };
	eq(h.newServices(old, { marketing: ['tiktok-pixel'] }), {}, n + 'old cookie without svc -> {}');
	eq(h.effectiveCategories(old, { marketing: ['tiktok-pixel'] }), ['marketing'], n + 'old cookie keeps categories');
	eq(h.newServices(null, MAP), {}, n + 'no cookie -> {}');
	eq(h.effectiveCategories(null, MAP), [], n + 'no cookie -> []');
	eq(h.newServices(ck, undefined), {}, n + 'unknown map -> no new services');
	eq(h.newServices(ck, null), {}, n + 'null map -> no new services');
	eq(h.effectiveCategories(ck, undefined), ['necessary', 'marketing'], n + 'unknown map keeps cookie categories');
	eq(h.effectiveCategories(ck, null), ['necessary', 'marketing'], n + 'null map keeps cookie categories');
	eq(h.effectiveCategories({}, undefined), [], n + 'cookie without categories, unknown map -> []');
	eq(h.newServices({ categories: ['marketing'], svc: 'junk' }, MAP), {}, n + 'svc string -> like old cookie');
	eq(h.effectiveCategories({ categories: ['marketing'], svc: 'junk' }, MAP), ['marketing'], n + 'svc string keeps categories');
	eq(h.newServices({ categories: 'marketing', svc: { marketing: [] } }, MAP), {}, n + 'categories not an array -> {}');
	eq(h.effectiveCategories({ categories: 'marketing', svc: {} }, MAP), [], n + 'categories not an array -> []');
	eq(h.newServices({ categories: ['marketing'], svc: { marketing: 'x' } }, MAP), { marketing: ['meta-pixel', 'tiktok-pixel'] }, n + 'svc value not an array -> all new');
	eq(h.newServices({ categories: ['marketing'], svc: [] }, MAP), {}, n + 'svc array -> like old cookie');
	eq(h.newServices(ck, { marketing: 'oops' }), {}, n + 'map value not an array skipped');
	eq(h.newServices('str', MAP), {}, n + 'cookie string -> {}');
	eq(h.newServices({ categories: ['marketing'], svc: {} }, { marketing: ['b', 'a', 'c'] }), { marketing: ['b', 'a', 'c'] }, n + 'ids keep map order');
});

// B4 Task 3: reconsentView + svcFlat (banner.js only; same extracted block).
var rcView = rcM ? new Function(rcM[1] + '\nreturn { reconsentView: typeof reconsentView === "function" ? reconsentView : null, svcFlat: typeof svcFlat === "function" ? svcFlat : null };')() : {};
ok(typeof rcView.reconsentView === 'function', 'reconsentView present in the banner.js reconsent block');
ok(typeof rcView.svcFlat === 'function', 'svcFlat present in the banner.js reconsent block');
if (typeof rcView.reconsentView === 'function') {
	var rv = rcView.reconsentView;
	var RVMAP = { statistics: ['google-analytics'], marketing: ['meta-pixel', 'tiktok-pixel'] };
	var rvCk = { v: '1', categories: ['necessary', 'statistics', 'marketing'], svc: { statistics: ['google-analytics'], marketing: ['meta-pixel'] } };
	eq(rv(rvCk, RVMAP), { show: true, newIds: { marketing: ['tiktok-pixel'] }, preselect: ['necessary', 'statistics'] }, 'reconsentView: new service in accepted category -> show, affected category not preselected');
	eq(rv({ v: '1', categories: ['necessary', 'statistics', 'marketing'] }, RVMAP), { show: false, newIds: {}, preselect: ['necessary', 'statistics', 'marketing'] }, 'reconsentView: old cookie without svc -> no show');
	eq(rv(null, RVMAP), { show: false, newIds: {}, preselect: [] }, 'reconsentView: no cookie -> no show (the normal banner handles it)');
	eq(rv(rvCk, undefined), { show: false, newIds: {}, preselect: ['necessary', 'statistics', 'marketing'] }, 'reconsentView: unknown map -> no show');
	eq(rv(rvCk, null).show, false, 'reconsentView: null map -> no show');
	eq(rv({ v: '1', categories: ['necessary', 'statistics'], svc: { statistics: ['google-analytics'] } }, RVMAP).show, false, 'reconsentView: new service in a refused category -> no show');
	eq(rv({ v: '1', categories: ['necessary', 'marketing'], svc: { marketing: ['meta-pixel', 'tiktok-pixel'] } }, RVMAP).show, false, 'reconsentView: every service already known -> no show');
	eq(rv({ v: '1', categories: ['necessary', 'marketing'], svc: { marketing: ['meta-pixel', 'tiktok-pixel', 'gone'] } }, RVMAP).show, false, 'reconsentView: removed service -> no show');
	eq(rv('junk', RVMAP), { show: false, newIds: {}, preselect: [] }, 'reconsentView: non-object cookie -> no show');
	var rvTwo = rv({ v: '1', categories: ['necessary', 'statistics', 'marketing'], svc: { statistics: [], marketing: ['meta-pixel'] } }, RVMAP);
	eq(rvTwo.newIds, { statistics: ['google-analytics'], marketing: ['tiktok-pixel'] }, 'reconsentView: every affected category listed');
	eq(rvTwo.preselect, ['necessary'], 'reconsentView: every affected category left unchecked');
}
if (typeof rcView.svcFlat === 'function') {
	eq(rcView.svcFlat({ statistics: ['google-analytics'], marketing: ['meta-pixel', 'tiktok-pixel'] }), ['google-analytics', 'meta-pixel', 'tiktok-pixel'], 'svcFlat: flattened ids');
	eq(rcView.svcFlat({ a: ['x'], b: ['x', 'y'] }), ['x', 'y'], 'svcFlat: no duplicates');
	eq(rcView.svcFlat({}), [], 'svcFlat: empty snapshot -> []');
	eq(rcView.svcFlat(null), [], 'svcFlat: null -> []');
	eq(rcView.svcFlat({ a: 'oops', b: ['z', 5] }), ['z'], 'svcFlat: non-array values and non-string ids skipped');
}
// Cookie size: 18 library ids + custom-1 … custom-50, all accepted, stays small.
if (typeof rcBanner.svcSnapshot === 'function') {
	var libSvc = JSON.parse(require('fs').readFileSync(require('path').join(__dirname, '../../data/services.json'), 'utf8'));
	libSvc = libSvc.services || libSvc;
	var bigMap = {};
	libSvc.forEach(function (x) { (bigMap[x.category] = bigMap[x.category] || []).push(x.id); });
	for (var ci = 1; ci <= 50; ci++) { bigMap.marketing.push('custom-' + ci); }
	var bigIds = Object.keys(bigMap).reduce(function (n, k) { return n + bigMap[k].length; }, 0);
	eq(bigIds, 68, 'cookie size: 68 service ids in the test map');
	var bigCats = Object.keys(bigMap);
	var bigSnap = rcBanner.svcSnapshot(bigCats, bigMap);
	var snapLen = encodeURIComponent(JSON.stringify(bigSnap)).length;
	ok(snapLen < 2000, 'cookie size: snapshot of 68 ids under 2000 chars encoded (got ' + snapLen + ')');
	var bigCookie = { v: '12', id: '3f2b8c1e-9a4d-4e6b-8c2f-1a2b3c4d5e6f', categories: bigCats, decision: 'accepted_all', ts: 1790000000, svc: bigSnap };
	var cookieLen = encodeURIComponent(JSON.stringify(bigCookie)).length;
	ok(cookieLen < 2000, 'cookie size: whole cmp_consent value under 2000 chars encoded (got ' + cookieLen + ')');
}
// banner.js wiring (source checks): every read of the visitor's categories goes
// through effectiveCategories; save() stores the snapshot only when it is known.
ok(/function save\(accepted, decision\) \{[\s\S]*?var snap = svcSnapshot\(accepted, D\.svcByCat\);[\s\S]*?if \(snap !== null\) \{ data\.svc = snap; \}[\s\S]*?writeCookie\(data\);/.test(bannerSrc), 'banner.js save(): svc written from svcSnapshot only when not null');
ok(/function save\(accepted, decision\) \{[^]*?logConsent\(\{ id: data\.id, categories: accepted, decision: decision, services: snap === null \? null : svcFlat\(snap\) \}\);/.test(bannerSrc), 'banner.js save(): the log carries the flattened snapshot');
ok(/function consentedCategories\(\) \{[^}]*effectiveCategories\(c, D\.svcByCat[,)]/.test(bannerSrc), 'banner.js: consentedCategories() reads through effectiveCategories');
ok(!/c\.categories \|\| \[\]/.test(bannerSrc), 'banner.js: no raw `c.categories || []` read left');
ok(!/pushConsent\(c\.categories|unblockScripts\(c\.categories|unblockEmbeds\(c\.categories/.test(bannerSrc), 'banner.js: the replay does not use the raw cookie categories');
ok(/isAllowed: function \(slug\) \{[\s\S]*?consentedCategories\(\)\.indexOf\(slug\)/.test(bannerSrc), 'banner.js: isAllowed uses the effective categories');
ok(/getConsent: function \(\) \{[\s\S]*?effectiveCategories\(/.test(bannerSrc), 'banner.js: getConsent reports the effective categories');
ok(/reconsentView\(c, D\.svcByCat[,)]/.test(bannerSrc), 'banner.js: init computes the re-consent view');

// Fix round 1 (I1): a required category is never removed / never prompts, even with an old map.
if (typeof rcBanner.effectiveCategories === 'function') {
	var reqCk = { v: '1', categories: ['necessary', 'functional', 'marketing'], svc: { functional: ['a'], marketing: ['meta-pixel'] } };
	var reqMap = { functional: ['a', 'b'], marketing: ['meta-pixel'] };
	eq(rcBanner.effectiveCategories(reqCk, reqMap, ['necessary', 'functional']), ['necessary', 'functional', 'marketing'], 'I1: effectiveCategories keeps a required category that gained a service');
	eq(rcBanner.effectiveCategories(reqCk, reqMap), ['necessary', 'marketing'], 'I1: without required slugs the category is still dropped (shared semantics)');
}
if (typeof rcView.reconsentView === 'function') {
	var reqCk2 = { v: '1', categories: ['necessary', 'functional', 'marketing'], svc: { functional: ['a'], marketing: ['meta-pixel'] } };
	eq(rcView.reconsentView(reqCk2, { functional: ['a', 'b'], marketing: ['meta-pixel'] }, ['necessary', 'functional']), { show: false, newIds: {}, preselect: ['necessary', 'functional', 'marketing'] }, 'I1: reconsentView ignores a required category');
	eq(rcView.reconsentView(reqCk2, { functional: ['a', 'b'], marketing: ['meta-pixel', 'tiktok-pixel'] }, ['necessary', 'functional']), { show: true, newIds: { marketing: ['tiktok-pixel'] }, preselect: ['necessary', 'functional'] }, 'I1: reconsentView lists only optional categories');
}
ok(/function consentedCategories\(\) \{[^}]*effectiveCategories\(c, D\.svcByCat, requiredSlugs\(\)\)/.test(bannerSrc), 'I1: consentedCategories passes the required slugs');
ok(/reconsentView\(c, D\.svcByCat, requiredSlugs\(\)\)/.test(bannerSrc), 'I1: pendingReconsent passes the required slugs');
ok(/getConsent: function \(\) \{[\s\S]*?effectiveCategories\(c, D\.svcByCat, requiredSlugs\(\)\)/.test(bannerSrc), 'I1: getConsent passes the required slugs');
// M3: pushWpInit computes the values once.
ok(/function pushWpInit\(\) \{[^]*?var vals = [^;]*\? wpConsentValues\(D\.wpConsent, consentedCategories\(\)\) : wpInitValues\(D\.wpConsent, readCookie\(\), D\.version, D\.mode, requiredSlugs\(\)\);/.test(bannerSrc), 'M3: pushWpInit computes the values in one expression');
ok(!/vals = wpConsentValues\(D\.wpConsent, consentedCategories\(\)\);/.test(bannerSrc), 'M3: no second assignment of vals');
// M1: during a re-consent the back button is hidden; the dialog is named by the visible title.
ok(/function renderReconsent\(view\) \{[\s\S]*?\[data-cmp-action="back"\][\s\S]*?\.hidden = on;/.test(bannerSrc), 'M1: back button hidden while re-consent is pending');
ok(/function openPrefs\(\) \{[\s\S]*?setAttribute\('aria-labelledby', 'cmp-prefs-title'\)/.test(bannerSrc), 'M1: open prefs -> aria-labelledby = prefs title');
ok(/function closePrefs\(\) \{[^\n]*setAttribute\('aria-labelledby', 'cmp-title'\)[^\n]*setAttribute\('aria-describedby', 'cmp-intro'\)/.test(bannerSrc), 'M1 (B5 T3): close prefs -> aria-labelledby = main title, described by the intro again');
// M2: the focus trap wraps on the visible controls of the active view.
var ftM = bannerSrc.match(/\/\/ focusTrap:begin\n([\s\S]*?)\/\/ focusTrap:end/);
ok(!!ftM, 'M2: banner.js exposes the focusTrap block');
if (ftM) {
	var trapStep = new Function(ftM[1] + '\nreturn trapStep;')();
	var A = { n: 'back' }, B = { n: 'toggle' }, C = { n: 'save' }, DLG = { n: 'dialog' }, OUT = { n: 'page' };
	var L = [A, B, C];
	eq(trapStep(L, A, true, DLG), C, 'M2: Shift+Tab from the first control wraps to the last');
	eq(trapStep(L, C, false, DLG), A, 'M2: Tab from the last control wraps to the first');
	eq(trapStep(L, DLG, true, DLG), C, 'M2: Shift+Tab from the dialog itself wraps to the last');
	eq(trapStep(L, B, false, DLG), null, 'M2: Tab in the middle -> browser default');
	eq(trapStep(L, B, true, DLG), null, 'M2: Shift+Tab in the middle -> browser default');
	eq(trapStep(L, OUT, false, DLG), A, 'M2: focus outside the list -> back to the first');
	eq(trapStep([], A, false, DLG), null, 'M2: nothing focusable -> nothing');
}
ok(/function focusable\(\) \{[\s\S]*?is-prefs-open[\s\S]*?getClientRects\(\)\.length/.test(bannerSrc), 'M2: focusable() scopes to the active view and keeps only rendered controls');

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

// B4 Task 4: re-consent in the standalone build.
// normalizeConfig: services map (unknown -> null) and optional display names.
var SVC_IN = { marketing: ['meta-pixel'] };
eq(cmp.normalizeConfig({ services: SVC_IN }).services, SVC_IN, 'T4: valid services map kept');
eq(cmp.normalizeConfig({}).services, null, 'T4: no services -> unknown (null)');
eq(cmp.normalizeConfig({ services: 'x' }).services, null, 'T4: services string -> unknown');
eq(cmp.normalizeConfig({ services: ['a'] }).services, null, 'T4: services array -> unknown');
eq(cmp.normalizeConfig({ services: null }).services, null, 'T4: services null -> unknown');
eq(cmp.normalizeConfig({ serviceNames: { 'meta-pixel': 'Meta Pixel', bad: 5 } }).serviceNames, { 'meta-pixel': 'Meta Pixel' }, 'T4: serviceNames keeps string values only');
eq(cmp.normalizeConfig({ serviceNames: 'x' }).serviceNames, {}, 'T4: bad serviceNames -> {}');
eq(cmp.normalizeConfig({}).serviceNames, {}, 'T4: no serviceNames -> {}');
// Texts: exact copy in the three packs.
var RC_TEXTS = {
	ro: ['Am adăugat servicii noi', 'Pe site au apărut servicii noi în categoriile pe care le-ai acceptat. Alege din nou.', 'nou'],
	ru: ['Мы добавили новые сервисы', 'На сайте появились новые сервисы в принятых вами категориях. Сделайте выбор снова.', 'новый'],
	en: ['We added new services', 'New services were added to categories you accepted. Please choose again.', 'new']
};
Object.keys(RC_TEXTS).forEach(function (l) {
	var t = cmp.normalizeConfig({ lang: l }).texts;
	eq([t.reconsent_title, t.reconsent_text, t.svc_new], RC_TEXTS[l], 'T4: re-consent texts ' + l);
});
// The pure block of cmp.js behaves exactly like banner.js's (shared case table).
var rcC = cmpSrc.match(/\/\/ reconsent:begin\n([\s\S]*?)\/\/ reconsent:end/);
ok(!!rcC, 'T4: cmp.js exposes the reconsent block');
if (rcC && rcM) {
	var RC_RET = '\nreturn { svcSnapshot: svcSnapshot, newServices: newServices, effectiveCategories: effectiveCategories, reconsentView: reconsentView, svcFlat: svcFlat };';
	var rcB = new Function(rcM[1] + RC_RET)(), rcS = new Function(rcC[1] + RC_RET)();
	var TBL_CK = [
		null, 'junk', {}, { categories: ['necessary', 'marketing'] },
		{ categories: ['necessary', 'functional', 'marketing'], svc: { functional: ['a'], marketing: ['meta-pixel'] } },
		{ categories: ['necessary', 'statistics', 'marketing'], svc: { statistics: ['ga'], marketing: ['meta-pixel'] } },
		{ categories: ['marketing'], svc: 'junk' }, { categories: ['marketing'], svc: [] }, { categories: 'marketing', svc: {} },
		{ categories: ['marketing'], svc: { marketing: 'x' } }
	];
	var TBL_MAP = [undefined, null, 'x', [], {}, { marketing: 'oops' }, { marketing: ['meta-pixel', 'tiktok-pixel'] }, { functional: ['a', 'b'], statistics: ['ga', 'ga2'], marketing: ['meta-pixel', 'tiktok-pixel'] }];
	var TBL_REQ = [undefined, null, [], ['necessary'], ['necessary', 'functional'], 'necessary'];
	var diffs = 0;
	TBL_CK.forEach(function (ck) { TBL_MAP.forEach(function (mp) {
		['newServices', 'svcSnapshot'].forEach(function (fn) {
			var a = JSON.stringify(fn === 'svcSnapshot' ? rcB[fn](['necessary', 'marketing'], mp) : rcB[fn](ck, mp)), b = JSON.stringify(fn === 'svcSnapshot' ? rcS[fn](['necessary', 'marketing'], mp) : rcS[fn](ck, mp));
			if (a !== b) { diffs++; console.error('DIFF ' + fn + ' ' + JSON.stringify([ck, mp]) + ' ' + a + ' vs ' + b); }
		});
		TBL_REQ.forEach(function (rq) {
			['effectiveCategories', 'reconsentView'].forEach(function (fn) {
				var a = JSON.stringify(rcB[fn](ck, mp, rq)), b = JSON.stringify(rcS[fn](ck, mp, rq));
				if (a !== b) { diffs++; console.error('DIFF ' + fn + ' ' + JSON.stringify([ck, mp, rq]) + ' ' + a + ' vs ' + b); }
			});
		});
	}); });
	eq(diffs, 0, 'T4: banner.js and cmp.js reconsent blocks agree on the shared case table');
	eq(rcS.svcFlat({ marketing: ['a', 'b'], statistics: ['b', 'c'] }), ['a', 'b', 'c'], 'T4: svcFlat dedupes');
	eq(rcS.svcFlat(null), [], 'T4: svcFlat unknown -> []');
	eq(rcS.effectiveCategories(reqCk, reqMap, ['necessary', 'functional']), ['necessary', 'functional', 'marketing'], 'T4: standalone effectiveCategories keeps required slugs');
	eq(rcS.reconsentView(reqCk2, { functional: ['a', 'b'], marketing: ['meta-pixel', 'tiktok-pixel'] }, ['necessary', 'functional']), { show: true, newIds: { marketing: ['tiktok-pixel'] }, preselect: ['necessary', 'functional'] }, 'T4: standalone reconsentView lists only optional categories');
	eq(rcS.reconsentView({ v: '1', categories: ['necessary', 'marketing'], svc: { marketing: ['meta-pixel'] } }, undefined, ['necessary']).show, false, 'T4: unknown map -> no re-prompt');
}
ok(typeof cmp.reconsentView === 'function' && typeof cmp.svcFlat === 'function', 'T4: cmp.js exports reconsentView and svcFlat');
// cmp.js wiring (source checks), mirroring banner.js.
ok(/function save\(accepted, decision\) \{[\s\S]*?var snap = svcSnapshot\(accepted, cfg\.services\);[\s\S]*?if \(snap !== null\) \{ data\.svc = snap; \}[\s\S]*?writeCookie\(data\);/.test(cmpSrc), 'T4: cmp.js save(): svc written only when the map is known');
ok(/function consentedCategories\(\) \{[\s\S]*?effectiveCategories\(c, cfg\.services, requiredSlugs\(\)\)/.test(cmpSrc), 'T4: cmp.js consentedCategories() reads through effectiveCategories');
ok(!/c\.categories \|\| \[\]/.test(cmpSrc), 'T4: cmp.js: no raw `c.categories || []` read left');
ok(!/storedCategories\(readCookie\(\), VERSION\)/.test(cmpSrc.replace(/function storedCategories[\s\S]*?\n\t\}/, '')) || /consentedCategories\(\)/.test(cmpSrc), 'T4: cmp.js replay uses the effective categories');
ok(/isAllowed: function \(slug\) \{[\s\S]*?consentedCategories\(\)\.indexOf\(slug\)/.test(cmpSrc), 'T4: cmp.js isAllowed uses the effective categories');
ok(/getConsent: function \(\) \{[\s\S]*?effectiveCategories\(c, cfg\.services, requiredSlugs\(\)\)/.test(cmpSrc), 'T4: cmp.js getConsent reports the effective categories');
ok(/reconsentView\(c, cfg\.services, requiredSlugs\(\)\)/.test(cmpSrc), 'T4: cmp.js pendingReconsent passes the required slugs');
ok(/function renderReconsent\(view\) \{[\s\S]*?\[data-cmp-action="back"\][\s\S]*?\.hidden = on;/.test(cmpSrc), 'T4: cmp.js hides the back button during re-consent');
ok(/function openPrefs\(\) \{[\s\S]*?renderReconsent\(pendingReconsent\(\)\)/.test(cmpSrc), 'T4: cmp.js openPrefs renders re-consent');
ok(/textContent = ids\.map\(svcLabel\)/.test(cmpSrc), 'T4: cmp.js names the new services via textContent');
ok(/data-cmp-reconsent-text/.test(cmpSrc) && /data-cmp-cat-new=/.test(cmpSrc), 'T4: cmp.js renders the re-consent text and badges');
ok(/if \(pendingReconsent\(\)\.show\) \{[\s\S]*?firstDecision = true;\s*openPrefs\(\);\s*show\(\);\s*return;/.test(cmpSrc), 'T4: cmp.js re-prompts on load straight on the preferences');
ok(/openPreferences: function \(\) \{[\s\S]*?openPrefs\(\);\s*show\(\);/.test(cmpSrc), 'T4: cmp.js openPreferences: prefs first, then show');
// Light mode untouched: renderLight has no re-consent.
ok(!/function renderLight[\s\S]*?reconsent[\s\S]*?function renderReopen/.test(cmpSrc), 'T4: Light mode untouched');
// Focus trap: same bug as banner.js (the hidden view's controls were counted); ported.
var ftC = cmpSrc.match(/\/\/ focusTrap:begin\n([\s\S]*?)\/\/ focusTrap:end/);
ok(!!ftC, 'T4: cmp.js exposes the focusTrap block');
if (ftC && ftM) {
	var tsC = new Function(ftC[1] + '\nreturn trapStep;')();
	var tsB = new Function(ftM[1] + '\nreturn trapStep;')();
	var tA = { n: 1 }, tB = { n: 2 }, tC = { n: 3 }, tD = { n: 4 }, tO = { n: 5 }, tL = [tA, tB, tC], tdiff = 0;
	[tA, tB, tC, tD, tO].forEach(function (act) { [true, false].forEach(function (sh) {
		if (tsC(tL, act, sh, tD) !== tsB(tL, act, sh, tD)) { tdiff++; }
	}); });
	eq(tdiff, 0, 'T4: cmp.js trapStep agrees with banner.js');
	eq(tsC([], tA, false, tD), null, 'T4: cmp.js trapStep: nothing focusable -> null');
}
ok(/function focusable\(\) \{[\s\S]*?is-prefs-open[\s\S]*?getClientRects\(\)\.length/.test(cmpSrc), 'T4: cmp.js focusable() scopes to the active view');
ok(/trapStep\(focusable\(\), document\.activeElement, e\.shiftKey, modal\(\)\)/.test(cmpSrc), 'T4: cmp.js onKeydown uses trapStep');

// B4 final fix (I3/R1): silent baseline adoption. A valid cookie without `svc`
// gets the current snapshot, nothing else changes; later additions re-prompt.
var rcBf = rcM ? new Function(rcM[1] + '\nreturn typeof backfillSvc === "function" ? backfillSvc : null;')() : null;
[['banner', rcBf], ['standalone', cmp.backfillSvc]].forEach(function (pair) {
	var n = 'backfillSvc ' + pair[0] + ': ', bf = pair[1];
	ok(typeof bf === 'function', n + 'present');
	if (typeof bf !== 'function') { return; }
	var BFMAP = { statistics: ['google-analytics'], marketing: ['meta-pixel'] };
	var legacy = { v: '3', id: '3f2b8c1e-9a4d-4e6b-8c2f-1a2b3c4d5e6f', categories: ['necessary', 'marketing'], decision: 'custom', ts: 1700000000 };
	var legacyCopy = JSON.stringify(legacy);
	var out = bf(legacy, BFMAP);
	eq(out, { v: '3', id: '3f2b8c1e-9a4d-4e6b-8c2f-1a2b3c4d5e6f', categories: ['necessary', 'marketing'], decision: 'custom', ts: 1700000000, svc: { marketing: ['meta-pixel'] } }, n + 'legacy cookie gets the snapshot of its accepted categories, rest unchanged');
	eq(JSON.stringify(legacy), legacyCopy, n + 'input cookie not mutated');
	ok(out !== null && cmp.newServices(out, BFMAP) && Object.keys(cmp.newServices(out, BFMAP)).length === 0, n + 'after adoption nothing is new (no prompt)');
	eq(cmp.effectiveCategories(out, BFMAP, ['necessary']), ['necessary', 'marketing'], n + 'after adoption the categories stay effective');
	eq(cmp.newServices(out, { marketing: ['meta-pixel', 'tiktok-pixel'] }), { marketing: ['tiktok-pixel'] }, n + 'a service added after adoption is new');
	eq(bf({ v: '3', categories: ['necessary'], decision: 'rejected_all', ts: 1 }, BFMAP), { v: '3', categories: ['necessary'], decision: 'rejected_all', ts: 1, svc: {} }, n + 'refused all -> empty snapshot written');
	eq(bf({ v: '3', categories: ['marketing'], svc: { marketing: ['meta-pixel'] } }, BFMAP), null, n + 'cookie with svc -> nothing to write');
	eq(bf({ v: '3', categories: ['marketing'], svc: {} }, BFMAP), null, n + 'cookie with empty svc -> nothing to write');
	eq(bf({ v: '3', categories: ['marketing'], svc: 'junk' }, BFMAP), null, n + 'cookie with a junk svc -> left alone');
	eq(bf(legacy, undefined), null, n + 'unknown map -> nothing to write');
	eq(bf(legacy, null), null, n + 'null map -> nothing to write');
	eq(bf(legacy, 'x'), null, n + 'non-object map -> nothing to write');
	eq(bf(null, BFMAP), null, n + 'no cookie -> null');
	eq(bf('str', BFMAP), null, n + 'non-object cookie -> null');
	eq(bf({ v: '3', categories: 'marketing' }, BFMAP), null, n + 'categories not an array -> null');
});
// Wiring: init adopts the baseline (only for a valid cookie of this version,
// Pro only), through writeCookie (same attributes), with no prompt and no log.
ok(/var adopted = backfillSvc\(c, D\.svcByCat\);\n\t\t\tvar left = adopted \? remainingMaxAge\(c, D\.cookieDays, Math\.floor\(Date\.now\(\) \/ 1000\)\) : 0;\n\t\t\tif \(adopted && left > 0\) \{ writeCookie\(adopted, left\); c = adopted; \}\n/.test(bannerSrc), 'R1/N1: banner.js init writes the backfilled cookie through writeCookie with the remaining lifetime');
ok(/if \(storedCategories\(readCookie\(\), VERSION\)\) \{\n[^\n]*\n\t\t\t\tvar adopted = backfillSvc\(readCookie\(\), cfg\.services\);\n\t\t\t\tvar left = adopted \? remainingMaxAge\(adopted, cfg\.cookieDays, Math\.floor\(Date\.now\(\) \/ 1000\)\) : 0;\n\t\t\t\tif \(adopted && left > 0\) \{ writeCookie\(adopted, left\); \}\n\t\t\t\}/.test(cmpSrc), 'R1/N1: cmp.js init writes the backfilled cookie with the remaining lifetime, before the stored categories are read');
// N1: adoption keeps the original lifetime (13-month cap); expired / no ts -> no write.
var rcRm = rcM ? new Function(rcM[1] + '\nreturn typeof remainingMaxAge === "function" ? remainingMaxAge : null;')() : null;
[['banner', rcRm], ['standalone', cmp.remainingMaxAge]].forEach(function (pair) {
	var n = 'N1 remainingMaxAge ' + pair[0] + ': ', rm = pair[1];
	ok(typeof rm === 'function', n + 'present');
	if (typeof rm !== 'function') { return; }
	var NOW = 1790000000;
	eq(rm({ ts: NOW }, 180, NOW), 180 * 86400, n + 'fresh ts -> full lifetime');
	eq(rm({ ts: NOW - 60 }, 180, NOW), 180 * 86400 - 60, n + 'ts a minute ago -> about the full lifetime');
	eq(rm({ ts: NOW - (396 * 86400 - 3600) }, 396, NOW), 3600, n + 'ts near expiry -> small remaining value');
	eq(rm({ ts: NOW - 396 * 86400 }, 396, NOW), 0, n + 'exactly expired -> 0');
	eq(rm({ ts: NOW - 400 * 86400 }, 396, NOW), 0, n + 'expired ts -> 0 (nothing written)');
	eq(rm({ v: '1' }, 180, NOW), 0, n + 'missing ts -> 0');
	eq(rm({ ts: '1790000000' }, 180, NOW), 0, n + 'string ts -> 0');
	eq(rm({ ts: NaN }, 180, NOW), 0, n + 'NaN ts -> 0');
	eq(rm({ ts: 0 }, 180, NOW), 0, n + 'zero ts -> 0');
	eq(rm({ ts: NOW + 100 }, 180, NOW), 0, n + 'future ts -> 0');
	eq(rm(null, 180, NOW), 0, n + 'no cookie -> 0');
	eq(rm({ ts: NOW - 10 }, undefined, NOW), 180 * 86400 - 10, n + 'no cookieDays -> 180 days default');
});
ok(/function writeCookie\(obj, maxAge\) \{[^}]*if \(!\(typeof maxAge === 'number' && maxAge > 0\)\) \{ maxAge = 60 \* 60 \* 24 \* days; \}/.test(bannerSrc), 'N1: banner.js writeCookie takes an optional max-age, default unchanged');
ok(/function writeCookie\(obj, maxAge\) \{[^}]*if \(!\(typeof maxAge === 'number' && maxAge > 0\)\) \{ maxAge = 60 \* 60 \* 24 \* \(cfg\.cookieDays \|\| 180\); \}/.test(cmpSrc), 'N1: cmp.js writeCookie takes an optional max-age, default unchanged');
ok(!/backfillSvc\([^\n]*\n[^\n]*(logConsent|save\(|show\(\))/.test(bannerSrc) && !/backfillSvc\([^\n]*\n[^\n]*(logConsent|save\(|show\(\))/.test(cmpSrc), 'R1: adoption neither logs nor prompts');
// The two reconsent blocks stay identical (textual, modulo indentation).
var rcC = cmpSrc.match(/\/\/ reconsent:begin\n([\s\S]*?)\/\/ reconsent:end/);
ok(!!rcM && !!rcC && rcM[1].replace(/^\t+/gm, '') === rcC[1].replace(/^\t+/gm, ''), 'reconsent blocks identical in banner.js and cmp.js');

// B3 Task 4: check mode helpers (banner.js only; standalone has no check mode).
// B5 Task 2: the runner and its helpers moved to assets/js/banner-check.js
// (loaded only in check mode); the cookie helpers stay in banner.js. Both
// check blocks are extracted and evaluated together.
var ckM = bannerSrc.match(/\/\/ check:begin\n([\s\S]*?)\/\/ check:end/);
ok(!!ckM, 'banner.js exposes the check block');
var bcSrc = (function () { try { return require('fs').readFileSync(require('path').join(__dirname, '../../assets/js/banner-check.js'), 'utf8'); } catch (e) { return ''; } })();
var ckC = bcSrc.match(/\/\/ check:begin\n([\s\S]*?)\/\/ check:end/);
ok(!!ckC, 'banner-check.js exposes the check block');
var ckAll = (ckM && ckC) ? ckM[1] + ckC[1] : null;
ok(!/check:begin/.test(cmpSrc) && !/cmp-check/.test(cmpSrc), 'standalone cmp.js has no check mode');
var ck = ckAll ? new Function(ckAll + '\nreturn { checkDiff: checkDiff, cookieNames: cookieNames, cookieTail: cookieTail, cookieList: cookieList, checkMessage: checkMessage };')() : {};
if (ckAll) {
	eq(ck.checkDiff(['a', 'b'], ['b', 'c', 'a', 'd']), { added: ['c', 'd'] }, 'checkDiff: only new entries, in the order of after');
	eq(ck.checkDiff(['a'], ['c', 'c', 'a', 'c']), { added: ['c'] }, 'checkDiff: no duplicates');
	eq(ck.checkDiff([], ['x']), { added: ['x'] }, 'checkDiff: empty before');
	eq(ck.checkDiff(['x'], []), { added: [] }, 'checkDiff: nothing after');
	eq(ck.checkDiff(null, undefined), { added: [] }, 'checkDiff: non-arrays are empty');
	eq(ck.checkDiff('ab', ['a']), { added: ['a'] }, 'checkDiff: a string before is not a list');
	eq(ck.cookieNames({ cookieName: 'cmp_consent' }), { consent: 'cmp_consent', svc: 'cmp_svc', session: false }, 'cookieNames: normal CMP_DATA -> real cookies');
	eq(ck.cookieNames({ check: true, cookieName: 'cmp_check_consent', svcCookie: 'cmp_check_svc' }), { consent: 'cmp_check_consent', svc: 'cmp_check_svc', session: true }, 'cookieNames: check CMP_DATA -> check cookies, session');
	eq(ck.cookieNames(undefined), { consent: 'cmp_consent', svc: 'cmp_svc', session: false }, 'cookieNames: no CMP_DATA -> defaults');
	eq(ck.cookieNames({ cookieName: '', svcCookie: 5 }), { consent: 'cmp_consent', svc: 'cmp_svc', session: false }, 'cookieNames: invalid names -> defaults');
	eq(ck.cookieTail(3600, false), ';path=/;max-age=3600;SameSite=Lax', 'cookieTail: persistent cookie keeps max-age');
	eq(ck.cookieTail(3600, true), ';path=/;SameSite=Lax', 'cookieTail: session cookie has no max-age');
	eq(ck.cookieList('a=1; cmp_check_consent=%7B%7D; _ga=GA1; a=2'), ['a', 'cmp_check_consent', '_ga'], 'cookieList: names in order, no duplicates');
	eq(ck.cookieList(''), [], 'cookieList: empty string');
	eq(ck.cookieList(' x=; =y; z'), ['x', 'z'], 'cookieList: odd entries');
	eq(ck.checkMessage('before', ['https://a/x.js'], ['_ga'], true), { type: 'cmp-check', phase: 'before', resources: ['https://a/x.js'], cookies: ['_ga'], banner: true }, 'checkMessage: before shape');
	eq(ck.checkMessage('before', null, null, 0), { type: 'cmp-check', phase: 'before', resources: [], cookies: [], banner: false }, 'checkMessage: lists and flag normalised');
}
// Wiring (source checks).
ok(/var SVC_COOKIE = cookieNames\(D\)\.svc;/.test(bannerSrc), 'banner.js: cmp_svc name follows CMP_DATA');
ok(/function writeCookie\(obj, maxAge\) \{[\s\S]*?document\.cookie = D\.cookieName \+ '=' \+ v \+ cookieTail\(maxAge, !!D\.check\);/.test(bannerSrc), 'banner.js: consent cookie is a session cookie in check mode');
ok(/function writeSvc\(obj\) \{[\s\S]*?document\.cookie = SVC_COOKIE \+ '=' \+ v \+ cookieTail\(maxAge, !!D\.check\);/.test(bannerSrc), 'banner.js: svc cookie is a session cookie in check mode');
ok(/function logConsent\(payload\) \{\n\t\tif \(!D\.ajaxUrl \|\| D\.check\) \{ return; \}/.test(bannerSrc), 'banner.js: no consent log in check mode');
ok(/var reload = firstDecision && !!D\.reloadOnDecision && !D\.check;/.test(bannerSrc), 'banner.js: no reload in check mode');
ok(/parent\.postMessage\([^)]*, location\.origin\)/.test(bcSrc), 'banner-check.js: check results posted to the same origin only');
ok(/if \(D\.check\) \{[\s\S]*?window\.CMP_CHECK_API = \{/.test(bannerSrc) && (bannerSrc.match(/CMP_CHECK_API/g) || []).length === 1, 'banner.js: the check bridge exists only in check mode');
ok(/var API = window\.CMP_CHECK_API;\n\tif \(!API \|\| window\.parent === window\) \{ return; \}/.test(bcSrc), 'banner-check.js: measuring runs only in check mode, inside a frame');
// B5 Task 2: the split.
ok(!/function runCheck/.test(bannerSrc), 'B5: banner.js no longer contains function runCheck');
ok(/function runCheck\(\)/.test(bcSrc), 'B5: banner-check.js contains runCheck');
ok(!/function (checkDiff|cookieList|checkMessage|parentSameOrigin|checkSnapshot|postCheck)\(/.test(bannerSrc) && !/postMessage/.test(bannerSrc), 'B5: the measuring helpers and postMessage left banner.js');
ok(!/function (cookieNames|cookieTail|prefixCookies|restorePlan|persistTail|stripCheckParam|endCheck|clearCheckCookies)\(/.test(bcSrc), 'B5: the cookie / restore helpers stay in banner.js only');
ok(!/CMP_DATA/.test(bcSrc) && (bcSrc.match(/CMP_CHECK_API/g) || []).length === 1, 'B5: banner-check.js reads only window.CMP_CHECK_API');
ok(/window\.CMP_CHECK_API = \{ accept: [^\n]*, endCheck: endCheck, hasBanner: /.test(bannerSrc), 'B5: the bridge is { accept, endCheck, hasBanner }');
ok(/if \(document\.readyState === 'complete'\) \{ runCheck\(\); \}\n\telse \{ window\.addEventListener\('load', runCheck\); \}/.test(bcSrc), 'B5: runCheck starts on load, or at once when load already fired');

// B3 Task 4 fix round 1: restore the admin's WP Consent API cookies, clean up, strip the token.
var ck2 = ckAll ? new Function(ckAll + '\nreturn { prefixCookies: typeof prefixCookies === "function" ? prefixCookies : null, restorePlan: typeof restorePlan === "function" ? restorePlan : null, persistTail: typeof persistTail === "function" ? persistTail : null, stripCheckParam: typeof stripCheckParam === "function" ? stripCheckParam : null };')() : {};
ok(typeof ck2.prefixCookies === 'function' && typeof ck2.restorePlan === 'function' && typeof ck2.persistTail === 'function' && typeof ck2.stripCheckParam === 'function', 'check block: restore / strip helpers present');
if (typeof ck2.restorePlan === 'function') {
	eq(ck2.prefixCookies('cmp_consent=x; wp_consent_statistics=deny; wp_consent_marketing=allow; other=1; wp_consent_statistics=dup', 'wp_consent'), [{ name: 'wp_consent_statistics', value: 'deny' }, { name: 'wp_consent_marketing', value: 'allow' }], 'prefixCookies: names and raw values, first wins');
	eq(ck2.prefixCookies('wp_consentx=1; xwp_consent_a=1', 'wp_consent'), [{ name: 'wp_consentx', value: '1' }], 'prefixCookies: prefix match at the start only');
	eq(ck2.prefixCookies('', 'wp_consent'), [], 'prefixCookies: none');
	var snap = [{ name: 'wp_consent_statistics', value: 'deny' }, { name: 'wp_consent_marketing', value: 'deny' }];
	eq(ck2.restorePlan(snap, 'wp_consent_statistics=allow; wp_consent_marketing=deny; wp_consent_functional=allow; _ga=1', 'wp_consent'),
		{ set: [{ name: 'wp_consent_statistics', value: 'deny' }], del: ['wp_consent_functional'] }, 'restorePlan: rewrite changed values, delete the ones absent before');
	eq(ck2.restorePlan(snap, '', 'wp_consent'), { set: snap, del: [] }, 'restorePlan: a deleted original is rewritten');
	eq(ck2.restorePlan([], 'wp_consent_a=allow', 'wp_consent'), { set: [], del: ['wp_consent_a'] }, 'restorePlan: nothing before -> delete all');
	eq(ck2.restorePlan(snap, 'wp_consent_statistics=deny; wp_consent_marketing=deny', 'wp_consent'), { set: [], del: [] }, 'restorePlan: unchanged -> nothing to write');
	eq(ck2.persistTail(30, false), ';path=/;max-age=2592000;SameSite=Lax', 'persistTail: days -> max-age, http');
	eq(ck2.persistTail(0, true), ';path=/;max-age=2592000;SameSite=Lax;secure', 'persistTail: default 30 days, https -> secure');
	eq(ck2.stripCheckParam('https://ex.md/p/?cmp_check=abc123'), 'https://ex.md/p/', 'stripCheckParam: only param');
	eq(ck2.stripCheckParam('https://ex.md/p/?a=1&cmp_check=abc&b=2#x'), 'https://ex.md/p/?a=1&b=2#x', 'stripCheckParam: middle param');
	eq(ck2.stripCheckParam('https://ex.md/p/?cmp_check=abc&b=2'), 'https://ex.md/p/?b=2', 'stripCheckParam: first param');
	eq(ck2.stripCheckParam('https://ex.md/p/?a=1&cmp_check=abc#x'), 'https://ex.md/p/?a=1#x', 'stripCheckParam: last param with hash');
	eq(ck2.stripCheckParam('https://ex.md/p/?xcmp_check=1'), 'https://ex.md/p/?xcmp_check=1', 'stripCheckParam: other names kept');
}
ok(/if \(typeof n === 'string' && n\.indexOf\('cmp_check_'\) === 0\)/.test(bannerSrc), 'M4: check-cookie cleanup only touches cmp_check_* names');
ok(/navigator\.globalPrivacyControl === true && !D\.check/.test(bannerSrc), 'M4: GPC bypass only in check mode');
ok(/if \(D\.check\) \{[\s\S]{0,400}?var wpSnap = prefixCookies\(document\.cookie, wpPrefix\(\)\);/.test(bannerSrc), 'I1: WP Consent API cookies snapshotted at parse, only in check mode');
ok(/function endCheck\(\) \{[\s\S]*?restorePlan\(wpSnap, document\.cookie, wpPrefix\(\)\)[\s\S]*?clearCheckCookies\(\);/.test(bannerSrc), 'I1/I2: endCheck restores wp_consent_* and clears the check cookies');
ok(/postCheck\(msg\);\n\t\t\t\tAPI\.endCheck\(\);/.test(bcSrc), 'I1/I2: endCheck after the after message');
ok((bcSrc.match(/postCheck\(msgL\);\n\t\t\t\tAPI\.endCheck\(\);/) || []).length === 1 && /if \(!hasBanner\) \{ API\.endCheck\(\); return; \}/.test(bcSrc), 'I1/I2: endCheck in the early-stop paths');
ok(/window\.addEventListener\('pagehide', endCheck\)/.test(bannerSrc), 'I1/I2: endCheck on pagehide');
ok(/history\.replaceState\(history\.state, '', stripCheckParam\(location\.href\)\)/.test(bannerSrc), 'M1: token stripped from the URL in check mode');

// B3 Task 5: the pure helpers of assets/js/check.js (admin "Verificare" screen).
var checkSrc = (function () { try { return require('fs').readFileSync(require('path').join(__dirname, '../../assets/js/check.js'), 'utf8'); } catch (e) { return ''; } })();
var ckM = checkSrc.match(/\/\/ checkPure:begin\n([\s\S]*?)\/\/ checkPure:end/);
ok(!!ckM, 'check.js exposes the checkPure block');
var ck = ckM ? new Function(ckM[1] + '\nreturn { accept: checkAccept, url: checkUrl, clean: checkClean, outcome: checkOutcome, origin: checkOriginOf, names: checkCookieNames, payload: checkPayload };')() : {};
if (ckM) {
	var fw = {}, other = {};
	eq(ck.accept({ origin: 'https://a.md', source: fw, data: { type: 'cmp-check', phase: 'before' } }, fw, 'https://a.md'), true, 'check: same origin, our frame, our type -> accepted');
	eq(ck.accept({ origin: 'https://evil.md', source: fw, data: { type: 'cmp-check' } }, fw, 'https://a.md'), false, 'check: other origin -> rejected');
	eq(ck.accept({ origin: 'https://a.md', source: other, data: { type: 'cmp-check' } }, fw, 'https://a.md'), false, 'check: another window -> rejected');
	eq(ck.accept({ origin: 'https://a.md', source: fw, data: { type: 'x' } }, fw, 'https://a.md'), false, 'check: other message type -> rejected');
	eq(ck.accept({ origin: 'https://a.md', source: fw, data: null }, fw, 'https://a.md'), false, 'check: no data -> rejected');
	eq(ck.url('https://a.md/p/', 'N1'), 'https://a.md/p/?cmp_check=N1', 'check: token added');
	eq(ck.url('https://a.md/p/?x=1#top', 'N 1'), 'https://a.md/p/?x=1&cmp_check=N%201', 'check: token appended to a query, hash dropped');
	eq(ck.clean(['https://g.com/a?id=1', 'https://g.com/a?id=2', 'https://g.com/b#x', 5, ''], 10, 500), ['https://g.com/a', 'https://g.com/b'], 'check: query/hash stripped, deduped, non-strings dropped');
	eq(ck.clean(['a', 'b', 'c'], 2, 500).length, 2, 'check: capped');
	eq(ck.outcome({ before: null }).result, 'timeout', 'check: no message -> timeout (never green)');
	eq(ck.outcome({ before: { banner: false, resources: [], cookies: [] } }).result, 'nobanner', 'check: banner missing -> nobanner');
	eq(ck.outcome({ before: { banner: true, light: true, resources: ['x'], cookies: [] } }).light, true, 'check: Light -> before only');
	var oc = ck.outcome({ before: { banner: true, resources: ['https://g.com/a'], cookies: ['k'] }, after: { added: { resources: ['https://h.com/x?y'], cookies: ['_ga'] } } });
	eq([oc.result, oc.added.resources, oc.added.cookies], ['ok', ['https://h.com/x'], ['_ga']], 'check: before + after -> ok with additions');
	eq(ck.outcome({ before: { banner: true, resources: [], cookies: [] }, after: null }).added, null, 'check: after missing -> no additions');

	// Fix round 1: origin of a page (M1), the admin's cookie baseline (I2).
	eq(ck.origin('HTTPS://Shop.Example.md/a?x'), 'https://shop.example.md', 'check M1: origin of a page, lowercased');
	eq(ck.origin('https://example.md:8443/'), 'https://example.md:8443', 'check M1: port kept');
	eq(ck.origin('/relative'), '', 'check M1: no origin for a relative URL');
	eq(ck.names(' _ga=GA1.1; cmp_consent=x ;_ga=dup; empty; =v'), ['_ga', 'cmp_consent', 'empty'], 'check I2: cookie names of document.cookie, deduped');
	eq(ck.payload(['_ga'], [{ url: 'u', result: 'timeout' }]), { baseline: ['_ga'], pages: [{ url: 'u', result: 'timeout' }] }, 'check I2: payload carries the baseline and the pages');
}

// B3 final review fix wave.
// I2: per-page cookie baseline (cookies already in the browser just before each frame).
var ckF = ckM ? new Function(ckM[1] + '\nreturn { outcome: checkOutcome, payload: checkPayload };')() : {};
if (ckM) {
	var ocP = ckF.outcome({ before: { banner: true, resources: [], cookies: ['_ga'] }, after: null }, ['_ga', '_ga', 5, 'cmp_consent']);
	eq(ocP.pre, ['_ga', 'cmp_consent'], 'check I2 (final): an ok page carries its own baseline, cleaned');
	eq(ckF.outcome({ before: { banner: true, resources: [], cookies: [] } }).pre, [], 'check I2 (final): no per-page baseline -> empty list');
	var bigPre = []; for (var bp = 0; bp < 150; bp++) { bigPre.push('c' + bp); }
	eq(ckF.outcome({ before: { banner: true, resources: [], cookies: [] } }, bigPre).pre.length, 100, 'check I2 (final): per-page baseline capped at 100');
	eq(ckF.payload([], [ocP]).pages[0].pre, ['_ga', 'cmp_consent'], 'check I2 (final): payload pages carry pre');
}
ok(/function next\(i\) \{[\s\S]*?var pre = checkCookieNames\(document\.cookie\);[\s\S]*?document\.createElement\('iframe'\)/.test(checkSrc), 'check I2 (final): per-page baseline taken in next() before the frame is created');
ok(/checkOutcome\(c\.state, c\.pre\)/.test(checkSrc), 'check I2 (final): finish() passes the per-page baseline');
// M5: auto "Acceptă tot" only under a same-origin parent.
var ckB = bcSrc.match(/\/\/ check:begin\n([\s\S]*?)\/\/ check:end/);
var ck5 = ckB ? new Function(ckB[1] + '\nreturn { po: typeof parentSameOrigin === "function" ? parentSameOrigin : null };')() : {};
ok(!!ck5.po, 'M5: banner-check.js check block has parentSameOrigin');
if (ck5.po) {
	var wSelf = { location: { origin: 'https://a.md' } }; wSelf.parent = wSelf;
	eq(ck5.po(wSelf), false, 'M5: top window (no parent) -> false');
	eq(ck5.po({ location: { origin: 'https://a.md' }, parent: { location: { origin: 'https://a.md' } } }), true, 'M5: same-origin parent -> true');
	eq(ck5.po({ location: { origin: 'https://a.md' }, parent: { location: { origin: 'https://evil.md' } } }), false, 'M5: other-origin parent -> false');
	var wX = { location: { origin: 'https://a.md' }, parent: {} };
	Object.defineProperty(wX.parent, 'location', { get: function () { throw new Error('SecurityError'); } });
	eq(ck5.po(wX), false, 'M5: cross-origin parent (access throws) -> false');
	eq(ck5.po(null), false, 'M5: no window -> false');
}
ok(/if \(!parentSameOrigin\(window\)\) \{ API\.endCheck\(\); return; \}\n\t\t\t\/\/ The same path as the visitor's click \(delegated handler -> save\(\)\)\.\n\t\t\tAPI\.accept\(\);/.test(bcSrc), 'M5: runCheck checks the parent origin right before the accept click and stops via endCheck');
ok(/accept: D\.mode === 'light' \? null : function \(\) \{ var a = banner \? banner\.querySelector\('\[data-cmp-action="accept"\]'\) : null; if \(a\) \{ a\.click\(\); \} \}/.test(bannerSrc), 'B5: CMP_CHECK_API.accept clicks the banner\'s accept button (same path as the visitor), none in Light');

// B5 Task 2: run banner-check.js against a fake frame (the event order of B3).
function runCheckSim(opts) {
	var log = [], timers = [], loadFns = [];
	var parentWin = { location: { origin: opts.parentOrigin || 'https://a.md' }, postMessage: function (m, o) { log.push(['post', m.phase, o, m]); } };
	var win = { location: { origin: 'https://a.md' }, parent: opts.top ? null : parentWin, addEventListener: function (ev, fn) { if (ev === 'load') { loadFns.push(fn); } } };
	if (opts.top) { win.parent = win; }
	var res = ['https://a.md/x.js'];
	var cookies = '_pre=1';
	if (opts.api !== false) {
		win.CMP_CHECK_API = {
			accept: opts.light ? null : function () { log.push(['accept']); res.push('https://t.com/ga.js'); cookies += '; _ga=1'; },
			endCheck: function () { log.push(['end']); },
			hasBanner: function () { return opts.banner !== false; }
		};
	}
	var doc = { readyState: opts.ready || 'complete' };
	Object.defineProperty(doc, 'cookie', { get: function () { return cookies; } });
	var perf = { getEntriesByType: function () { return res.map(function (n) { return { name: n }; }); } };
	win.performance = perf;
	new Function('window', 'document', 'performance', 'location', 'setTimeout', bcSrc)(win, doc, perf, win.location, function (fn, ms) { timers.push([fn, ms]); });
	loadFns.forEach(function (fn) { fn(); });
	while (timers.length) { var t = timers.shift(); log.push(['wait', t[1]]); t[0](); }
	return log;
}
if (bcSrc) {
	var simOk = runCheckSim({});
	eq(simOk.map(function (e) { return e[0] === 'post' ? 'post:' + e[1] + ':' + e[2] : e[0] === 'wait' ? 'wait:' + e[1] : e[0]; }), ['wait:1500', 'post:before:https://a.md', 'accept', 'wait:2500', 'post:after:https://a.md', 'end'], 'B5 sim: before -> accept -> +2500 ms -> after -> endCheck');
	var aft = simOk.filter(function (e) { return e[1] === 'after'; })[0];
	eq(aft ? aft[3].added : null, { resources: ['https://t.com/ga.js'], cookies: ['_ga'] }, 'B5 sim: after carries the additions');
	var bef = simOk.filter(function (e) { return e[1] === 'before'; })[0];
	eq(bef ? [bef[3].type, bef[3].banner, bef[3].resources, bef[3].cookies] : null, ['cmp-check', true, ['https://a.md/x.js'], ['_pre']], 'B5 sim: before message shape');
	eq(runCheckSim({ ready: 'interactive' }).filter(function (e) { return e[0] === 'post'; }).length, 2, 'B5 sim: waits for load when it has not fired yet');
	var simL = runCheckSim({ light: true });
	eq([simL.filter(function (e) { return e[0] === 'post'; }).length, simL.filter(function (e) { return e[0] === 'post'; })[0][3].light, simL[simL.length - 1][0]], [1, true, 'end'], 'B5 sim: Light -> before only (light: true), then endCheck');
	var simN = runCheckSim({ banner: false });
	eq(simN.map(function (e) { return e[0]; }), ['wait', 'post', 'end'], 'B5 sim: no banner -> before (banner: false), endCheck, no accept');
	eq(simN[1][3].banner, false, 'B5 sim: no banner flag');
	var simX = runCheckSim({ parentOrigin: 'https://evil.md' });
	eq(simX.filter(function (e) { return e[0] === 'accept'; }).length, 0, 'B5 sim: other-origin parent -> never accept');
	eq(simX[simX.length - 1][0], 'end', 'B5 sim: other-origin parent -> endCheck');
	eq(runCheckSim({ api: false }), [], 'B5 sim: no bridge (not check mode) -> nothing');
	eq(runCheckSim({ top: true }), [], 'B5 sim: not in a frame -> nothing');
}

// B5 Task 3: Escape, focus, described dialog, lang (banner.js and cmp.js).
function keysFns(src) {
	var m = src.match(/\/\/ keys:begin\n([\s\S]*?)\/\/ keys:end/);
	return { m: m, escapeAction: m ? new Function(m[1] + '\nreturn escapeAction;')() : null };
}
function fnBody(src, name) {
	var i = src.indexOf('function ' + name + '(');
	if (i === -1) { return ''; }
	var j = src.indexOf('{', i), depth = 0, k;
	for (k = j; k < src.length; k++) {
		if (src[k] === '{') { depth++; } else if (src[k] === '}') { depth--; if (!depth) { break; } }
	}
	return src.slice(i, k + 1);
}
var kB = keysFns(bannerSrc), kC = keysFns(cmpSrc);
ok(!!kB.m, 'B5 T3: banner.js exposes the keys block');
ok(!!kC.m, 'B5 T3: cmp.js exposes the keys block');
ok(!!kB.m && !!kC.m && kB.m[1].replace(/^\t+/gm, '') === kC.m[1].replace(/^\t+/gm, ''), 'B5 T3: keys blocks identical in banner.js and cmp.js');
[['banner.js', kB, bannerSrc], ['cmp.js', kC, cmpSrc]].forEach(function (t) {
	var n = t[0], ea = t[1].escapeAction, src = t[2];
	if (ea) {
		// escapeAction(prefsOpen, backHidden, blocking, focusInside)
		eq(ea(true, false, true, true), 'back', 'B5 T3 ' + n + ': prefs open, blocking -> back');
		eq(ea(true, false, true, false), 'back', 'B5 T3 ' + n + ': prefs open, blocking, focus outside -> back');
		eq(ea(false, false, true, true), null, 'B5 T3 ' + n + ': prefs closed -> null');
		eq(ea(false, false, false, true), null, 'B5 T3 ' + n + ': prefs closed, no overlay -> null');
		eq(ea(true, true, true, true), null, 'B5 T3 ' + n + ': back hidden (re-consent) -> null');
		eq(ea(true, false, false, false), null, 'B5 T3 ' + n + ': no overlay, focus outside the banner -> null');
		eq(ea(true, false, false, true), 'back', 'B5 T3 ' + n + ': no overlay, focus inside the banner -> back');
		eq(ea(true), null, 'B5 T3 ' + n + ': missing flags -> null (focus not known inside)');
	} else { ok(false, 'B5 T3 ' + n + ': escapeAction defined'); }
	var kd = fnBody(src, 'onKeydown'), gb = fnBody(src, 'goBack');
	ok(/'Escape'/.test(kd) && /escapeAction\(/.test(kd), 'B5 T3 ' + n + ': onKeydown handles Escape through escapeAction');
	ok(/goBack\(\)/.test(kd), 'B5 T3 ' + n + ': Escape -> goBack()');
	ok(!!gb && /closePrefs\(\)/.test(gb) && /\[data-cmp-action="prefs"\]/.test(gb) && /\.focus\(\)/.test(gb), 'B5 T3 ' + n + ': goBack closes the settings and focuses the Settings button');
	var NOSAVE = /\b(saveConsent|writeCookie|decide|save|hide|logConsent|rejectServices)\(/;
	ok(!!kd && !NOSAVE.test(kd), 'B5 T3 ' + n + ': onKeydown never saves, writes the cookie or closes');
	ok(!!gb && !NOSAVE.test(gb), 'B5 T3 ' + n + ': goBack never saves, writes the cookie or closes');
	ok(!NOSAVE.test(t[1].m ? t[1].m[1] : 'save('), 'B5 T3 ' + n + ': keys block never saves');
	ok(/else if \(action === 'back'\) \{ goBack\(\); \}/.test(src), 'B5 T3 ' + n + ': the Back button also returns focus to Settings');
	ok(/\[data-cmp-prefs-title\][\s\S]*?\.focus\(\)/.test(fnBody(src, 'openPrefs')), 'B5 T3 ' + n + ': openPrefs focuses the settings title');
	ok(/setAttribute\('aria-describedby', 'cmp-intro'\)/.test(fnBody(src, 'closePrefs')), 'B5 T3 ' + n + ': closePrefs restores aria-describedby = cmp-intro');
	ok(/blocking\(\)/.test(kd) && /e\.key !== 'Tab'/.test(kd), 'B5 T3 ' + n + ': Tab trap only while the banner blocks');
	ok(/addEventListener\('keydown', onKeydown\)/.test(fnBody(src, 'show')) && /removeEventListener\('keydown', onKeydown\)/.test(fnBody(src, 'hide')), 'B5 T3 ' + n + ': keydown listened while the banner shows (also without overlay)');
	ok(!/keydown/.test(fnBody(src, 'initLight')), 'B5 T3 ' + n + ': Light card has no key handling');
	ok(/escapeAction\(prefsOpen\(\), !!\(back && back\.hidden\), blocking\(\), banner\.contains\(document\.activeElement\)\)/.test(kd), 'B5 T3 ' + n + ': onKeydown passes the four flags to escapeAction');
	// M2: show() on an already visible banner (CMP.openPreferences while it shows)
	// must not re-run trapFocus (lastFocus would be recorded inside the banner).
	var shSrc = fnBody(src, 'show');
	var mkShow = function (hidden, block, open) {
		var log = [], bn = { hidden: hidden };
		// B5 T5: the scroll lock measures the scrollbar first (--cmp-sbw), the band pads the page scroll.
		var docStub = { body: { classList: { add: function (c) { log.push('class:' + c); } } }, addEventListener: function () { log.push('listen'); },
			documentElement: { clientWidth: 985, style: { setProperty: function (k, v) { log.push('set:' + k + '=' + v); } } } };
		var winStub = { innerWidth: 1000, addEventListener: function (ev) { log.push('win:' + ev); } };
		var f = new Function('banner', 'blocking', 'prefsOpen', 'trapFocus', 'focusDialog', 'hideReopen', 'document', 'onKeydown', 'window', 'scrollPad', 'getComputedStyle', 'lockPad', shSrc + '\nreturn show;')(
			bn, function () { return block; }, function () { return open; },
			function (on) { log.push('trap:' + on); }, function () { log.push('focus'); }, function () {}, docStub, function () {}, winStub, function () { log.push('pad'); },
			function () { return { overflowY: 'visible', scrollbarGutter: 'auto', paddingRight: '0px' }; }, function () { return '15px'; });
		f();
		var BAND = /^(class:|set:|win:|pad$)/;
		return { log: log.filter(function (x) { return x !== 'listen' && !BAND.test(x); }), band: log.filter(function (x) { return BAND.test(x); }), hidden: bn.hidden };
	};
	if (shSrc) {
		eq(mkShow(true, true, false).log, ['trap:true'], 'B5 T3 ' + n + ': show() from hidden, blocking -> trapFocus');
		eq(mkShow(false, true, true).log, ['focus'], 'B5 T3 ' + n + ': show() already visible, blocking -> only focusDialog (no trap)');
		eq(mkShow(false, false, true).log, ['focus'], 'B5 T3 ' + n + ': show() already visible, no overlay -> focusDialog');
		eq(mkShow(true, false, true).log, ['focus'], 'B5 T3 ' + n + ': show() from hidden, no overlay, prefs open -> focusDialog');
		eq(mkShow(true, false, false).log, [], 'B5 T3 ' + n + ': show() from hidden, no overlay, main view -> no focus taken');
		eq(mkShow(true, true, false).hidden, false, 'B5 T3 ' + n + ': show() unhides');
	} else { ok(false, 'B5 T3 ' + n + ': show() found'); }
});
// Standalone markup: described dialog, focusable title, lang from the chosen language.
ok(/aria-labelledby="cmp-title" aria-describedby="cmp-intro"/.test(cmpSrc), 'B5 T3 cmp.js: dialog described by the intro');
ok(/'<p id="cmp-intro" class="cmp__text">'/.test(cmpSrc), 'B5 T3 cmp.js: intro has id="cmp-intro"');
ok(/<h2 id="cmp-prefs-title" class="cmp__title" tabindex="-1"/.test(cmpSrc), 'B5 T3 cmp.js: settings title tabindex="-1"');
ok(/function renderPro\(cfg\) \{[\s\S]*?root\.setAttribute\('lang', cfg\.lang\)/.test(cmpSrc), 'B5 T3 cmp.js: .cmp gets lang from the chosen language');
ok(/function renderLight\(cfg\) \{[\s\S]*?root\.setAttribute\('lang', cfg\.lang\)[\s\S]*?function renderReopen/.test(cmpSrc), 'B5 T3 cmp.js: .cmp-light gets lang from the chosen language');
eq(cmp.normalizeConfig({ lang: 'ru' }).lang, 'ru', 'B5 T3 cmp.js: chosen language ru');
eq(cmp.normalizeConfig({ lang: 'xx' }).lang, 'ro', 'B5 T3 cmp.js: unknown language -> ro');

// B5 Task 5: the two-row band on phones (banner.js and cmp.js).
function bandFns(src) {
	var m = src.match(/\/\/ band:begin\n([\s\S]*?)\/\/ band:end/);
	return { m: m, toggleDetails: m ? new Function(m[1] + '\nreturn toggleDetails;')() : null };
}
function bandFn(m, name) {
	try { return m ? new Function(m[1] + '\nreturn ' + name + ';')() : null; } catch (e) { return null; }
}
function stubEl() {
	var cls = [], attrs = {};
	return {
		classList: { add: function (c) { if (cls.indexOf(c) === -1) { cls.push(c); } }, remove: function (c) { cls = cls.filter(function (x) { return x !== c; }); }, contains: function (c) { return cls.indexOf(c) !== -1; } },
		setAttribute: function (k, v) { attrs[k] = String(v); }, getAttribute: function (k) { return Object.prototype.hasOwnProperty.call(attrs, k) ? attrs[k] : null; }
	};
}
var bB = bandFns(bannerSrc), bC = bandFns(cmpSrc);
ok(!!bB.m, 'B5 T5: banner.js has the band block');
ok(!!bC.m, 'B5 T5: cmp.js has the band block');
ok(!!bB.m && !!bC.m && bB.m[1].replace(/^\t+/gm, '') === bC.m[1].replace(/^\t+/gm, ''), 'B5 T5: band blocks identical in banner.js and cmp.js');
[['banner.js', bB, bannerSrc], ['cmp.js', bC, cmpSrc]].forEach(function (t) {
	var n = t[0], td = t[1].toggleDetails, src = t[2];
	if (td) {
		var main = stubEl(), btn = stubEl();
		btn.setAttribute('aria-expanded', 'false');
		eq(td(main, btn), true, 'B5 T5 ' + n + ': toggleDetails flips collapsed -> expanded');
		eq([main.classList.contains('is-expanded'), btn.getAttribute('aria-expanded')], [true, 'true'], 'B5 T5 ' + n + ': expanded -> .is-expanded + aria-expanded="true"');
		eq(td(main, btn), false, 'B5 T5 ' + n + ': a second press collapses');
		eq([main.classList.contains('is-expanded'), btn.getAttribute('aria-expanded')], [false, 'false'], 'B5 T5 ' + n + ': collapsed -> no .is-expanded, aria-expanded="false"');
		eq(td(main, btn, true), true, 'B5 T5 ' + n + ': toggleDetails(true) expands');
		eq(td(main, btn, true), true, 'B5 T5 ' + n + ': toggleDetails(true) twice stays expanded');
		eq([td(main, btn, false), main.classList.contains('is-expanded'), btn.getAttribute('aria-expanded')], [false, false, 'false'], 'B5 T5 ' + n + ': toggleDetails(false) collapses');
		eq(td(main, null, true), true, 'B5 T5 ' + n + ': no button -> still toggles the band');
	} else { ok(false, 'B5 T5 ' + n + ': toggleDetails defined'); }
	ok(/else if \(action === 'details'\) \{ details\(\); scrollPad\(\); \}/.test(src), 'B5 T5 ' + n + ': the Details button toggles the band and re-pads the page');
	ok(/toggleDetails\(main, main\.querySelector\('\[data-cmp-action="details"\]'\), on\)/.test(fnBody(src, 'details')), 'B5 T5 ' + n + ': details() drives toggleDetails on the main view');
	var hd = fnBody(src, 'hide');
	ok(/details\(false\)/.test(hd), 'B5 T5 ' + n + ': hide() collapses the band');
	ok(/document\.documentElement\.style\.removeProperty\('--cmp-sbw'\)/.test(hd), 'B5 T5 ' + n + ': hide() drops --cmp-sbw');
	ok(/window\.removeEventListener\('resize', scrollPad\)/.test(hd) && /scrollPad\(\)/.test(hd), 'B5 T5 ' + n + ': hide() stops the resize listener and clears the scroll padding');
	// show(): scrollbar width measured before the lock, only when it appears.
	var shSrc = fnBody(src, 'show');
	var run = function (hidden, block, bodyPad) {
		var log = [], bn = { hidden: hidden };
		var docStub = { body: { classList: { add: function (c) { log.push('class:' + c); } } }, addEventListener: function () {},
			documentElement: { clientWidth: 985, style: { setProperty: function (k, v) { log.push('set:' + k + '=' + v); } } } };
		var winStub = { innerWidth: 1000, addEventListener: function (ev, fn) { log.push('win:' + ev + ':' + (fn === padFn)); } };
		var padFn = function () { log.push('pad'); };
		var lp = bandFn(t[1].m, 'lockPad') || function () { return '?'; };
		new Function('banner', 'blocking', 'prefsOpen', 'trapFocus', 'focusDialog', 'hideReopen', 'document', 'onKeydown', 'window', 'scrollPad', 'getComputedStyle', 'lockPad', shSrc + '\nreturn show;')(
			bn, function () { return block; }, function () { return false; }, function () {}, function () {}, function () {}, docStub, function () {}, winStub, padFn,
			function (el) { return el === docStub.body ? { paddingRight: bodyPad || '0px' } : { overflowY: 'visible', scrollbarGutter: 'auto' }; }, lp)();
		return log;
	};
	if (shSrc) {
		eq(run(true, true), ['set:--cmp-sbw=15px', 'class:cmp-has-banner', 'win:resize:true', 'pad'], 'B5 T5 ' + n + ': show() blocking -> --cmp-sbw (innerWidth - clientWidth) before the lock, then resize + scroll padding');
		eq(run(false, true), ['class:cmp-has-banner', 'win:resize:true', 'pad'], 'B5 T5 ' + n + ': show() already visible -> no re-measure (the scrollbar is already gone)');
		eq(run(true, false), ['win:resize:true', 'pad'], 'B5 T5 ' + n + ': show() without overlay -> no lock, no --cmp-sbw');
		eq(run(true, true, '20px')[0], 'set:--cmp-sbw=35px', 'B5 T5 fix ' + n + ': show() keeps the theme\'s body padding-right and adds the scrollbar width');
	} else { ok(false, 'B5 T5 ' + n + ': show() found'); }
	// scrollPad(): only without overlay; bottom band -> padding-bottom, top band -> padding-top.
	// Fix round 1: only the side it set is touched, and the theme's own value comes back.
	var spSrc = fnBody(src, 'scrollPad');
	var mkPad = function (theme) {
		var st = { hidden: false, block: false, rect: { top: 600.4, bottom: 800 } };
		var style = { scrollPaddingTop: theme.top || '', scrollPaddingBottom: theme.bottom || '' };
		var f = new Function('banner', 'blocking', 'modal', 'document', 'window', 'var padSide = \'\', padPrev = \'\';\n' + spSrc + '\nreturn scrollPad;')(
			st, function () { return st.block; }, function () { return { getBoundingClientRect: function () { return st.rect; } }; },
			{ documentElement: { style: style } }, { innerHeight: 800 });
		return { st: st, run: function () { f(); return [style.scrollPaddingTop, style.scrollPaddingBottom]; } };
	};
	if (spSrc) {
		var P = mkPad({ top: '80px' });
		eq(P.run(), ['80px', '200px'], 'B5 T5 ' + n + ': no overlay, bottom band -> scroll-padding-bottom = its height; theme top kept');
		P.st.rect = { top: 500, bottom: 800 };
		eq(P.run(), ['80px', '300px'], 'B5 T5 fix ' + n + ': resize / Details -> re-measured');
		P.st.hidden = true;
		eq(P.run(), ['80px', ''], 'B5 T5 fix ' + n + ': hidden -> only the side it set is cleared, theme top survives');
		var P2 = mkPad({ bottom: '10px' });
		eq(P2.run(), ['', '200px'], 'B5 T5 fix ' + n + ': theme bottom overridden while the band shows');
		eq(P2.run(), ['', '200px'], 'B5 T5 fix ' + n + ': repeated calls do not stack');
		P2.st.hidden = true;
		eq(P2.run(), ['', '10px'], 'B5 T5 fix ' + n + ': hidden -> the theme\'s own bottom value comes back');
		var P3 = mkPad({ top: '80px', bottom: '5px' });
		P3.st.block = true;
		eq([P3.run(), P3.run()], [['80px', '5px'], ['80px', '5px']], 'B5 T5 fix ' + n + ': blocking banner never touches scroll-padding');
		var P4 = mkPad({});
		P4.st.rect = { top: 0, bottom: 150.2 };
		eq(P4.run(), ['151px', ''], 'B5 T5 ' + n + ': no overlay, top band -> scroll-padding-top');
	} else { ok(false, 'B5 T5 ' + n + ': scrollPad() found'); }
	ok(/scrollPad\(\);?\s*\}$/.test(fnBody(src, 'openPrefs')) && /scrollPad\(\);?\s*\}$/.test(fnBody(src, 'goBack')), 'B5 T5 fix ' + n + ': Settings and Back re-measure the scroll padding');
	// lockPad (band block): the body's padding under the lock.
	var lpF = bandFn(t[1].m, 'lockPad');
	if (lpF) {
		eq(lpF(1000, 985, 'visible', 'auto', '0px'), '15px', 'B5 T5 fix ' + n + ': classic scrollbar -> its width');
		eq(lpF(1000, 985, 'auto', '', '12.5px'), '27.5px', 'B5 T5 fix ' + n + ': theme padding-right kept, scrollbar added');
		eq(lpF(1000, 1000, 'visible', 'auto', '0px'), '0px', 'B5 T5 fix ' + n + ': overlay scrollbars -> no shift');
		eq(lpF(1000, 985, 'scroll', 'auto', '4px'), '4px', 'B5 T5 fix ' + n + ': html overflow-y: scroll keeps the bar -> only the theme padding');
		eq(lpF(1000, 985, 'visible', 'stable', '0px'), '0px', 'B5 T5 fix ' + n + ': scrollbar-gutter: stable -> no shift');
		eq(lpF(1000, 985, 'visible', undefined, 'x'), '15px', 'B5 T5 fix ' + n + ': unknown gutter / padding -> safe');
	} else { ok(false, 'B5 T5 fix ' + n + ': lockPad defined'); }
	// focusable(): a visibility:hidden control (the clamped policy link) is not in the trap.
	var fcSrc = fnBody(src, 'focusable');
	if (fcSrc) {
		var mkEl = function (name, vis) { return { name: name, vis: vis, getClientRects: function () { return [1]; } }; };
		var els = [mkEl('link', 'hidden'), mkEl('details', 'visible'), mkEl('accept', 'visible')];
		var scope = { querySelectorAll: function () { return els; } };
		var fc = new Function('banner', 'modal', 'getComputedStyle', fcSrc + '\nreturn focusable;')(
			{ querySelector: function () { return scope; } }, function () { return { classList: { contains: function () { return false; } } }; },
			function (el) { return { visibility: el.vis }; });
		eq(fc().map(function (e) { return e.name; }), ['details', 'accept'], 'B5 T5 fix ' + n + ': focusable() skips visibility:hidden controls (Tab wrap stays inside)');
	} else { ok(false, 'B5 T5 fix ' + n + ': focusable() found'); }
});
// Standalone texts and markup.
eq(['ro', 'ru', 'en'].map(function (l) { return cmp.CMP_DEFAULTS.i18n[l].texts.details; }), ['Detalii', 'Подробнее', 'Details'], 'B5 T5 cmp.js: details text in ro / ru / en');
eq(cmp.normalizeConfig({ lang: 'ru' }).texts.details, 'Подробнее', 'B5 T5 cmp.js: normalizeConfig carries the details text');
ok(/'<p id="cmp-intro" class="cmp__text">' \+ escapeHtml\(cfg\.texts\.intro\) \+ privacyLink \+ '<\/p>' \+\n\t*'<div class="cmp__actions">' \+\n\t*'<button type="button" class="cmp__more" data-cmp-action="details" aria-expanded="false" aria-controls="cmp-intro">' \+ escapeHtml\(cfg\.texts\.details\) \+ '<\/button>' \+\n\t*'<button type="button" class="cmp__btn cmp__btn--ghost" data-cmp-action="prefs">'/.test(cmpSrc), 'B5 M1 cmp.js: Details is the first item of the action row, right before Settings (DOM order = phone order)');
eq((cmpSrc.match(/class="cmp__more"/g) || []).length, 1, 'B5 M1 cmp.js: a single Details button');

// ---- B5 final-review fix wave ----
// I1: category toggles named by their category, described by its text; the
// "always active" mark announced (role="img" + aria-label). cmp.js markup builder.
var rpSrc = fnBody(cmpSrc, 'renderPro');
ok(/'<span class="cmp__cat-label" id="' \+ escapeHtml\(id\) \+ '-label">' \+ escapeHtml\(cat\.label\) \+ '<\/span>'/.test(rpSrc), 'B5 I1 cmp.js: category name has id="cmp-cat-<slug>-label"');
ok(/'<div class="cmp__cat-desc" id="' \+ escapeHtml\(id\) \+ '-desc">' \+ escapeHtml\(cat\.description\) \+ '<\/div>'/.test(rpSrc), 'B5 I1 cmp.js: description has id="cmp-cat-<slug>-desc"');
ok(/'<input type="checkbox" role="switch" id="' \+ escapeHtml\(id\) \+ '" value="' \+ escapeHtml\(cat\.slug\) \+ '" aria-labelledby="' \+ escapeHtml\(id\) \+ '-label" aria-describedby="' \+ escapeHtml\(id\) \+ '-desc" data-cmp-cat>'/.test(rpSrc), 'B5 I1 cmp.js: toggle = native checkbox, role="switch", labelled by the name, described by the text');
ok(/'<span class="cmp__cat-locked" role="img" aria-label="'/.test(rpSrc), 'B5 I1 cmp.js: locked mark has role="img"');
ok(!/aria-describedby="[^"]*new/.test(rpSrc), 'B5 I1 cmp.js: aria-describedby never points at the hidden "new" marker');

// I2: loading an embed keeps its box (same size, no layout shift); the iframe
// goes inside, the box is never matched as a placeholder again.
function embedBox(thumb) {
	var kids = [{ tag: 'inner' }, { tag: 'template' }], attrs = { 'data-cmp-embed': 'marketing', 'data-cmp-service': 'youtube', 'class': 'cmp-embed cmp--neutral' + (thumb ? ' cmp-embed--thumb' : '') };
	var frag = { tag: 'iframe' }, replaced = false;
	var cl = function () { return attrs['class'].split(/\s+/); };
	var box = {
		style: { backgroundImage: thumb ? "url('x.jpg')" : '' },
		parentNode: { replaceChild: function () { replaced = true; } },
		get firstChild() { return kids[0] || null; },
		removeChild: function (k) { kids.splice(kids.indexOf(k), 1); return k; },
		appendChild: function (k) { kids.push(k); return k; },
		querySelector: function (sel) { return sel === 'template[data-cmp-embed-src]' && kids.indexOf(this._tpl) !== -1 ? this._tpl : null; },
		getAttribute: function (k) { return Object.prototype.hasOwnProperty.call(attrs, k) ? attrs[k] : null; },
		hasAttribute: function (k) { return Object.prototype.hasOwnProperty.call(attrs, k); },
		removeAttribute: function (k) { delete attrs[k]; },
		classList: {
			add: function (c) { if (cl().indexOf(c) === -1) { attrs['class'] += ' ' + c; } },
			remove: function (c) { attrs['class'] = cl().filter(function (x) { return x !== c; }).join(' '); },
			contains: function (c) { return cl().indexOf(c) !== -1; }
		},
		kids: function () { return kids; }, wasReplaced: function () { return replaced; }
	};
	box._tpl = kids[1];
	box._tpl.content = { cloneNode: function (deep) { return deep ? frag : null; } };
	box.frag = frag;
	return box;
}
[['banner.js', bannerSrc], ['cmp.js', cmpSrc]].forEach(function (t) {
	var n = t[0], le = fnBody(t[1], 'loadEmbed');
	var f = le ? new Function(le + '\nreturn loadEmbed;')() : null;
	if (!f) { ok(false, 'B5 I2 ' + n + ': loadEmbed found'); return; }
	var b = embedBox(true);
	f(b);
	ok(!b.wasReplaced(), 'B5 I2 ' + n + ': the placeholder box is kept (not replaced by the bare iframe)');
	eq(b.kids().length === 1 && b.kids()[0] === b.frag, true, 'B5 I2 ' + n + ': the box now holds only the iframe');
	eq(b.hasAttribute('data-cmp-embed'), false, 'B5 I2 ' + n + ': data-cmp-embed removed (never matched / loaded again)');
	eq([b.classList.contains('cmp-embed--loaded'), b.classList.contains('cmp-embed--thumb'), b.classList.contains('cmp-embed')], [true, false, true], 'B5 I2 ' + n + ': .cmp-embed--loaded added, thumbnail class dropped, .cmp-embed kept');
	eq(b.style.backgroundImage, '', 'B5 I2 ' + n + ': thumbnail background cleared');
	eq(b.getAttribute('data-cmp-service'), 'youtube', 'B5 I2 ' + n + ': the service attributes stay (service name lookup)');
	f(b);
	eq(b.kids().length, 1, 'B5 I2 ' + n + ': a second call does nothing (no template left)');
	f(null);
	ok(true, 'B5 I2 ' + n + ': loadEmbed(null) is a no-op');
	var p = embedBox(false);
	f(p);
	eq([p.kids()[0] === p.frag, p.classList.contains('cmp-embed--loaded')], [true, true], 'B5 I2 ' + n + ': plain placeholder (no thumbnail) loads the same way');
	ok(!/replaceChild/.test(le), 'B5 I2 ' + n + ': loadEmbed no longer swaps the box out');
});
eq(fnBody(bannerSrc, 'loadEmbed').replace(/^\t+/gm, ''), fnBody(cmpSrc, 'loadEmbed').replace(/^\t+/gm, ''), 'B5 I2: loadEmbed identical in banner.js and cmp.js');

// M2: focus after the banner closes: the element focused before it opened when
// it is still usable, else the reopen button, else nothing.
[['banner.js', bannerSrc], ['cmp.js', cmpSrc]].forEach(function (t) {
	var n = t[0], m = t[1].match(/\/\/ focusTrap:begin\n([\s\S]*?)\/\/ focusTrap:end/);
	var fb = null;
	try { fb = m ? new Function(m[1] + '\nreturn focusBack;')() : null; } catch (e) { fb = null; }
	if (!fb) { ok(false, 'B5 M2 ' + n + ': focusBack in the focusTrap block'); return; }
	var LINK = { n: 'link', ok: true }, GONE = { n: 'gone', ok: false }, RE = { n: 'reopen', ok: true }, REH = { n: 'reopen-hidden', ok: false };
	var usable = function (el) { return !!el && el.ok; };
	eq(fb(LINK, RE, usable), LINK, 'B5 M2 ' + n + ': usable last focus -> back to it');
	eq(fb(GONE, RE, usable), RE, 'B5 M2 ' + n + ': last focus gone / hidden / in the banner -> the reopen button');
	eq(fb(null, RE, usable), RE, 'B5 M2 ' + n + ': no last focus (no-overlay page) -> the reopen button');
	eq(fb(GONE, REH, usable), null, 'B5 M2 ' + n + ': nothing usable -> nothing');
	eq(fb(null, null, usable), null, 'B5 M2 ' + n + ': no reopen button -> nothing');
	// hide(): the reopen button is shown before focus moves; focus only moves
	// when the banner was on screen and held the focus (not on the GPC auto-refusal).
	var hd = fnBody(t[1], 'hide');
	var runHide = function (hidden, block, focusIn, cookie) {
		var log = [], bn = { hidden: hidden, contains: function () { return focusIn; } };
		var docStub = { activeElement: {}, body: { classList: { remove: function () {} } }, documentElement: { style: { removeProperty: function () {} } }, removeEventListener: function () {} };
		new Function('banner', 'blocking', 'document', 'window', 'details', 'closePrefs', 'scrollPad', 'onKeydown', 'trapFocus', 'readCookie', 'showReopen', hd + '\nreturn hide;')(
			bn, function () { return block; }, docStub, { removeEventListener: function () {} }, function () {}, function () {}, function () {}, function () {},
			function (on) { log.push('trap:' + on); }, function () { return cookie ? {} : null; }, function () { log.push('reopen'); })();
		return log;
	};
	if (hd) {
		eq(runHide(false, true, true, true), ['reopen', 'trap:false'], 'B5 M2 ' + n + ': hide() shows the reopen button, then restores focus');
		eq(runHide(false, false, true, true), ['reopen', 'trap:false'], 'B5 M2 ' + n + ': no overlay, focus in the banner -> focus restored too');
		eq(runHide(false, false, false, true), ['reopen'], 'B5 M2 ' + n + ': no overlay, focus on the page -> focus left alone');
		eq(runHide(true, true, false, true), ['reopen'], 'B5 M2 ' + n + ': banner never shown (GPC auto-refusal) -> focus not taken');
	} else { ok(false, 'B5 M2 ' + n + ': hide() found'); }
	var tf = fnBody(t[1], 'trapFocus');
	ok(/focusBack\(lastFocus, reopenBtn, canRefocus\)/.test(tf), 'B5 M2 ' + n + ': trapFocus(false) uses focusBack with the reopen button');
	var cr = fnBody(t[1], 'canRefocus');
	ok(/document\.body/.test(cr) && /banner\.contains\(el\)/.test(cr) && /getClientRects\(\)\.length/.test(cr) && /documentElement\.contains\(el\)/.test(cr), 'B5 M2 ' + n + ': canRefocus rejects body, detached, hidden and in-banner elements');
});

// M8: the shipped cmp.min.js behaves like cmp.js (same exports, same results).
var cmpMin = require('../cmp.min.js');
eq(Object.keys(cmpMin).sort(), Object.keys(cmp).sort(), 'B5 M8: cmp.min.js exports the same keys as cmp.js');
(function () {
	var catsM = cmp.normalizeConfig({ categories: ['necessary', 'statistics', 'marketing'] }).categories;
	var ck = { v: '2', categories: ['necessary', 'marketing'], svc: { marketing: ['meta-pixel'] } };
	var map = { marketing: ['meta-pixel', 'tiktok-pixel'] };
	var cases = [
		['decisionFor', [['necessary', 'statistics'], catsM]],
		['decisionFor', [['necessary', 'statistics', 'marketing'], catsM]],
		['decisionFor', [['necessary'], catsM]],
		['reconsentView', [ck, map, ['necessary']]],
		['reconsentView', [null, map, ['necessary']]],
		['svcAllowed', [{ v: '1', svc: ['youtube', 'vimeo'] }, '1']],
		['svcAllowed', [{ v: '0', svc: ['youtube'] }, '1']],
		['parseConsent', ['{"v":"1","categories":["necessary"]}']],
		['parseConsent', ['not json']],
		['signalsFor', [catsM, ['statistics']]],
		['normalizeConfig', [{ lang: 'ru', categories: ['statistics'] }]],
		['escapeHtml', ['<a href="x">&\'</a>']],
		['embedLoads', ['marketing', ['marketing']]]
	];
	cases.forEach(function (c) {
		var a = JSON.stringify(cmp[c[0]].apply(null, c[1])), b = JSON.stringify(cmpMin[c[0]].apply(null, c[1]));
		ok(a === b, 'B5 M8: cmp.min.js ' + c[0] + '(' + JSON.stringify(c[1]).slice(0, 60) + ') matches cmp.js (min ' + b + ', src ' + a + ')');
	});
})();

console.log(passes + ' passed, ' + fails + ' failed');
process.exit(fails ? 1 : 0);
