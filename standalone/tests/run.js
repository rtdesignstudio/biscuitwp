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

// cookie serialize/parse
var s = cmp.serializeConsent({ v: '1', categories: ['necessary'], decision: 'rejected_all', ts: 5 });
eq(cmp.parseConsent(s).decision, 'rejected_all', 'roundtrip decision');
eq(cmp.parseConsent('not json'), null, 'bad json -> null');

// escapeHtml
eq(cmp.escapeHtml('<a>"&\''), '&lt;a&gt;&quot;&amp;&#39;', 'escapes html');

console.log(passes + ' passed, ' + fails + ' failed');
process.exit(fails ? 1 : 0);
