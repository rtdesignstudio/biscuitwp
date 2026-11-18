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

console.log(passes + ' passed, ' + fails + ' failed');
process.exit(fails ? 1 : 0);
