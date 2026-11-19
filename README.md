# Consent Manager Pro

WordPress plugin for GDPR cookie consent, legal page generation and a consent audit log. Theme-agnostic, no jQuery, no external dependencies.

- **Author:** [RT.md](https://rt.md)
- **License:** GPL-2.0-or-later
- **Requires:** WordPress ~6.x, PHP 7.4+

## What it does

- **Consent banner** — centered modal with a full-page blurred overlay, scroll locked until the visitor decides. Configurable position/preset, texts editable from the admin.
- **Google Consent Mode v2** (native) — emits `gtag('consent', 'default'/'update', …)` from the category → signals mapping, before Site Kit/GTM fire their tags.
- **Dynamic categories** — N categories (Necessary/Statistics/Marketing by default), each with cookies (name/provider/duration/purpose) and Consent Mode signals.
- **Consent audit log (GDPR)** — logging with 24h dedup, SHA-256 IP hash + salt, cron-based retention, CSV (BOM) / JSON export, purge.
- **Legal pages wizard** — generates Privacy Policy / Terms & Conditions / Cookie Policy as WP pages; integrates with the native WordPress privacy policy page and the Privacy Policy Guide.
- **GPC** — honors Global Privacy Control (`navigator.globalPrivacyControl`, read in the browser so it works with page caching): automatically rejects optional cookies without showing the banner. Do-Not-Track is ignored (abandoned standard).
- **Multilingual** — WPML / Polylang (string translation + legal page duplication) plus native i18n (`.pot`).

## Installation

Copy the `consent-manager-pro/` folder into `wp-content/plugins/` and activate it from **Plugins**. The audit table and the default categories are created on activation.

Configure under **WP Admin → Consent Manager** (a "Settings" link is also shown directly in the plugins list).

## Configuration

- **Settings** — banner position, preset, policy version, log retention, legal page mapping, **banner texts**.
- **Categories** — CRUD for categories + cookies + Consent Mode signals.
- **Consent log** — filterable list, summary, export, purge.
- **Services** — the services library: category overrides, disabling, custom services.
- **Legal pages** — generation wizard.

> **Policy version:** bump it whenever you change which cookies/purposes you use — older consent cookies become stale and the banner reappears for re-confirmation (a GDPR requirement when the purpose changes).

## Developer API

### PHP

```php
CMP_Consent::is_allowed( 'statistics' );  // bool — server-side gating
CMP_Consent::get_categories();            // configured categories
CMP_Consent::get_consent();               // current decision or null

do_action( 'cmp_consent_updated', $categories, $decision );  // on logging
do_action( 'cmp_legal_page_created', $post_id, $type );      // after a legal page is generated
apply_filters( 'cmp_render_banner', $html );
```

### JavaScript

```js
CMP.isAllowed('statistics');   // bool, from the cookie
CMP.getConsent();              // the consent object
CMP.openPreferences();         // open the preferences panel
document.addEventListener('cmp:consent', e => e.detail.categories);
```

### Shortcodes

```
[cmp_privacy_link]           Link to the Privacy Policy
[cmp_terms_link]             Link to the Terms & Conditions
[cmp_cookie_settings_link]   "Cookie settings" link (reopens preferences) — put it in the footer
[cmp_cookie_table]           Cookie table built from the detected and custom services
```

## Services library

`data/services.json` holds 14 known services, each with provider, hosts, cookies and a default category. The blocker matches the host of each `<script>`/`<iframe>` (parsed, with boundaries) and tags it with `data-cmp-service`; it stays blocked until the category is accepted. Manage it under **WP Admin → Consent Manager → Services** (override a category, disable a service, add custom ones).

| Default category | Services |
|---|---|
| statistics | Google Analytics, Google Tag Manager, Vimeo, Microsoft Clarity, Hotjar |
| marketing | Meta Pixel, YouTube, Google Maps, TikTok Pixel, LinkedIn Insight Tag, HubSpot, Tawk.to, Crisp |
| necessary | Google reCAPTCHA |

### Custom services and `data-cmp-category`

- A custom service has a name, hosts, category and cookies; it can also carry a script, printed in the footer and blocked until consent. Saving a script requires the `unfiltered_html` capability. Its hosts match `<script src>` and `<iframe src>` alike: a matching iframe gets the standard placeholder named after the service, with "Încarcă mereu" (library embeds keep priority). The site's own domain is refused as a custom host.
- `data-cmp-category="<slug>"` works on any `<script>` or `<iframe>` and forces its category. The value is trimmed and lowercased (`Marketing` = `marketing`); `necessary` leaves the tag unchanged and an empty value counts as absent. Any other invalid value fails closed: the library rule applies if one matches, otherwise the tag is blocked as `marketing`. A valid slug that matches no configured category is blocked on purpose (fail-safe). On an `<iframe>` the attribute turns it into a consent placeholder, named after the iframe's host. `data-cmp-keep` always wins.

### Cookie table

`[cmp_cookie_table]` renders a table built from the detected and custom services (plus cookies entered by hand per category). The generated Cookie Policy page uses it.

### "Încarcă mereu" (Always load)

Embed placeholders get a second button next to "Încarcă conținutul". The choice is stored in its own `cmp_svc` cookie, separate from the category consent (it does not close the banner and does not count as a refusal), and can be withdrawn from the preferences panel ("Încărcate mereu: YouTube ×") or all at once with "Reject all". Each grant/withdrawal is logged as `service_allow` / `service_withdraw`, with a `services` column in the register. Not available in Light mode.

### YouTube thumbnail

The thumbnail is stored locally in `uploads/cmp-thumbs/` and downloaded in the background through WP-Cron, so no request goes to YouTube before consent. This needs WP-Cron working (or a system cron hitting `wp-cron.php`); until the download completes, the plain placeholder is shown.

### WP Consent API

Optional. If present, the plugin registers itself and sends `wp_set_consent()` on each decision and on return visits. Mapping: necessary → functional, statistics → statistics + statistics-anonymous, marketing → marketing, preferences → preferences. Custom categories are not sent.

### Limit: GTM and injected JavaScript

Only what is in the page HTML is blocked. Anything that GTM or other JavaScript injects later must be controlled from GTM (Consent Mode).

### Standalone

`standalone/cmp.js` does not load the library: on landing pages markers are added by hand (see `standalone/marker-generator.html`; services in `necessary`, like reCAPTCHA, are not marked at all). It supports "Încarcă mereu" for embeds that carry `data-cmp-service`; it keeps no consent log. See `standalone/README.md`.

## Release notes (batch B)

- Cookie pages generated earlier keep their old static table until regenerated from **Legal pages**.
- The release version must be ≥ 0.5.0, otherwise the post-update notice is not shown (it appears only when updating from a version below 0.5.0).
- "Reject all" / "Necessary only" also clears every "Încarcă mereu" choice (one `service_withdraw` row lists them). Saving a partial selection and the GPC auto-reject keep it; embeds already loaded stay until the page is reloaded.
- The DB schema has its own revision (`cmp_db_schema`), so the `services` column is added even when the version string did not change; a consent record is never lost to a missing column.
- On update, services that used to run freely (Clarity, Hotjar, chat widgets, etc.) start waiting for consent.

## Updates & releases

Sites update through the RT relay at `rt.md/cmp-update` (no wordpress.org, no GitHub token on any site). Updates show up natively under **Plugins / Dashboard → Updates**; the plugin row also has a **Check for updates** link. Every package must carry a valid Ed25519 signature (`CMP_Updater::SIGN_PUBLIC_KEY`), otherwise it is not installed.

To publish a new version: bump `Version:` and `CMP_VERSION` in `consent-manager-pro.php`, commit, then run `./bin/release.sh X.Y.Z` (requires the `gh` CLI and the signing key in `~/.config/cmp-release/signing.key`). The script signs the zip, verifies the signature and uploads `consent-manager-pro.zip.sig` next to it.

### Update relay

The relay in `proxy/cmp-update.php` holds the single read-only GitHub token for the private repo and redirects downloads to GitHub's signed asset URLs (see `proxy/README.md`). Sites only know the relay URL and a shared key (`CMP_Updater::PROXY_URL` / `PROXY_KEY`, overridable per site with `CMP_UPDATE_PROXY` / `CMP_UPDATE_KEY`); the key is not a secret, package integrity comes from the signature.

The `proxy/` folder is excluded from the plugin zip, so it never ships to sites.

## Development

The pure logic (consent evaluation, signals, sanitization, audit, i18n, legal) has standalone PHP tests, with no PHPUnit/Composer:

```bash
php tests/run.php
```

### Rulare teste în browser (e2e)

`bin/e2e.sh` verifică într-un Chromium real că **niciun tracker nu face cereri înainte de acord**, pentru fiecare serviciu din `data/services.json`: WordPress (Pro Strict și Advanced: vizitator nou, „Acceptă tot”, „Doar necesare”) și standalone. Pornește un WordPress Playground de unică folosință (pluginul ca în zip + mu-plugin-ul din `tests/e2e/fixture/`) și un server static pentru `tests/e2e/standalone.html`. Toate cererile spre alte origini sunt înregistrate și oprite: nu pleacă trafic spre terți. `bin/release.sh` refuză release-ul dacă testul pică.

```bash
PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 npm install   # o singură dată; playwright 1.59.1, fără browsere
bash bin/e2e.sh                                  # ~45 s; cod 0 = verde
node tests/e2e/match.test.js                     # testele pure (potrivire cerere → serviciu, fixture)
```

- Cere Chromium pentru Playwright 1.59.1 în cache-ul local (`~/Library/Caches/ms-playwright`, revizia 1217), `python3` și `npx` (Playground CLI din cache-ul npm). Scriptul nu descarcă browsere; dacă lipsesc, iese cu mesaj și cod 1.
- Raportul: `serviciu | scenariu | ok/LEAK/MISSING | url`. LEAK = cerere înainte de acord (sau după „Doar necesare”); MISSING = un serviciu permis înainte de acord nu s-a încărcat, sau nu s-a încărcat după „Acceptă tot”. Scenariul `control:unblocked` rulează cu blocarea oprită și dovedește că testul vede fiecare fragment (NOFIRE = testul e orb).
- Ce are voie înainte de acord vine din plugin (`/?cmp_e2e_expect=1`, doar în fixture): `necessary` sau `exempt`; în Advanced și GA4/GTM.
- Un serviciu nou în `data/services.json` intră automat în fixture și în verificare.
- Înainte de decizie și după „Doar necesare”, orice cerere externă pe care testul nu o poate atribui unui serviciu din bibliotecă e tot LEAK (`(unattributed)`).
- Plasă fixă, independentă de plugin: Meta Pixel, TikTok, LinkedIn, Clarity, Hotjar, YouTube, Vimeo, Google Maps, HubSpot, Tawk.to și Crisp nu au voie niciodată înainte de acord (Strict, Advanced, standalone); Advanced poate adăuga doar GA4/GTM; categoriile din plugin trebuie să fie cele din `data/services.json`.
- Fixture-ul acoperă `<script src>`, scripturi inline (gtag, GTM, fbq, pixel `<img>` creat din JS, `navigator.sendBeacon`, `fetch`), `<noscript><img>` și `<iframe>`. Nu vede: preconnect/dns-prefetch (nu sunt cereri), WebSocket, service workers.
- Variabile: `CMP_E2E_READY_TIMEOUT` (implicit 180 s), `CMP_E2E_RUN_TIMEOUT` (implicit 300 s, toată rularea în browser), `CMP_E2E_PG_PORT`, `CMP_E2E_PLAYGROUND_CLI` (implicit `@wp-playground/cli@3.1.65`), `CMP_E2E_SETTLE_MS` (implicit 1500).

## License

GPL-2.0-or-later.
