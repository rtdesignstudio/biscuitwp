# BiscuitWP

Free and open-source GDPR cookie consent for WordPress: a consent banner that blocks scripts and embeds until the visitor decides, Google Consent Mode v2, a legal pages generator and a consent audit log. Theme-agnostic, no jQuery, no external dependencies. Available in Romanian, Russian and English.

- **Made by:** [RT](https://rt.md)
- **License:** GPL-2.0-or-later (see [LICENSE](LICENSE))
- **Requires:** WordPress 6.0+, PHP 7.4+

BiscuitWP (formerly Consent Manager Pro) is free, and it stays free: there is no paid version, no license key and no upsell.

## What it does

- **Consent banner**: centered modal with a full-page blurred overlay, scroll locked until the visitor decides. Configurable position/preset, texts editable from the admin.
- **Google Consent Mode v2** (native): emits `gtag('consent', 'default'/'update', ...)` from the category to signals mapping, before Site Kit/GTM fire their tags.
- **Dynamic categories**: N categories (Necessary/Statistics/Marketing by default), each with cookies (name/provider/duration/purpose) and Consent Mode signals.
- **Consent audit log (GDPR)**: logging (an identical repeated choice is not logged again), SHA-256 IP hash + salt, cron-based retention, CSV (BOM) / JSON export, purge.
- **Legal pages wizard**: generates Privacy Policy / Terms & Conditions / Cookie Policy as WP pages; integrates with the native WordPress privacy policy page and the Privacy Policy Guide.
- **Google Fonts localisation** (Pro mode, opt-in option): one admin button downloads the fonts once, and the Google Fonts `<link>` tags are then rewritten to the local copy, so visitors do not contact Google. `@import` rules and JavaScript font loaders are not covered.
- **GPC**: honors Global Privacy Control (`navigator.globalPrivacyControl`, read in the browser so it works with page caching) and automatically rejects optional cookies without showing the banner. On by default; it can be turned off per site in Settings, and GPC visitors then see the banner like everyone else. Do-Not-Track is ignored (abandoned standard).
- **Multilingual**: WPML / Polylang (string translation + legal page duplication) plus native i18n (`.pot`, RO/RU/EN).

## Installation

1. Download `consent-manager-pro.zip` from [GitHub Releases](https://github.com/rtdesignstudio/consent-manager-pro/releases).
2. In WordPress, go to **Plugins > Add New > Upload Plugin**, or unzip it into `wp-content/plugins/`.
3. Activate it. The audit table and the default categories are created on activation.

Configure under **WP Admin > BiscuitWP** (a "Settings" link is also shown directly in the plugins list).

## Configuration

- **Settings**: banner position, preset, policy version, log retention, legal page mapping, **banner texts**.
- **Categories**: CRUD for categories + cookies + Consent Mode signals.
- **Consent log**: filterable list, summary, export, purge.
- **Services**: the services library: category overrides, disabling, custom services.
- **Legal pages**: generation wizard.

> **Policy version:** bump it whenever you change which cookies/purposes you use. Older consent cookies become stale and the banner reappears for re-confirmation (a GDPR requirement when the purpose changes).

## Updates and security

- Updates are **signed (Ed25519)** and delivered from <https://consent.rt.md/> as a signed manifest, with the zip hosted on GitHub Releases. Updates show up natively under **Plugins / Dashboard > Updates**.
- **No telemetry.** The update check is a plain request for a static file and sends no site data.
- Sites follow the `stable` channel; `define( 'CMP_UPDATE_CHANNEL', 'beta' );` in `wp-config.php` switches a site to beta.
- Keys and how to verify a download by hand: [Security](https://consent.rt.md/en/security/) ([RO](https://consent.rt.md/securitate/)).
- What the plugin does and does not send: [Privacy](https://consent.rt.md/en/privacy/).
- Release history: [Changelog](https://consent.rt.md/changelog/) (also in [CHANGELOG.md](CHANGELOG.md)).
- Reporting a vulnerability: see [SECURITY.md](SECURITY.md).

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

`data/services.json` holds 14 known services, each with provider, hosts, cookies and a default category. The blocker matches the host of each `<script>`/`<iframe>` (parsed, with boundaries) and tags it with `data-cmp-service`; it stays blocked until the category is accepted. Manage it under **WP Admin → BiscuitWP → Services** (override a category, disable a service, add custom ones).

| Default category | Services |
|---|---|
| statistics | Google Analytics, Google Tag Manager, Vimeo, Microsoft Clarity, Hotjar |
| marketing | Meta Pixel, YouTube, Google Maps, TikTok Pixel, LinkedIn Insight Tag, HubSpot, Tawk.to, Crisp |
| necessary | Google reCAPTCHA |

### Custom services and `data-cmp-category`

- A custom service has a name, hosts, category and cookies; it can also carry a script, printed in the footer and blocked until consent. Saving a script requires the `unfiltered_html` capability. Its hosts match `<script src>` and `<iframe src>` alike: a matching iframe gets the standard placeholder named after the service, with "Always load" (library embeds keep priority). The site's own domain is refused as a custom host.
- `data-cmp-category="<slug>"` works on any `<script>` or `<iframe>` and forces its category. The value is trimmed and lowercased (`Marketing` = `marketing`); `necessary` leaves the tag unchanged and an empty value counts as absent. Any other invalid value fails closed: the library rule applies if one matches, otherwise the tag is blocked as `marketing`. A valid slug that matches no configured category is blocked on purpose (fail-safe). On an `<iframe>` the attribute turns it into a consent placeholder, named after the iframe's host. `data-cmp-keep` always wins.

### Cookie table

`[cmp_cookie_table]` renders a table built from the detected and custom services (plus cookies entered by hand per category). The generated Cookie Policy page uses it.

### "Always load" (Always load)

Embed placeholders get a second button next to "Load content". The choice is stored in its own `cmp_svc` cookie, separate from the category consent (it does not close the banner and does not count as a refusal), and can be withdrawn from the preferences panel ("Always loaded: YouTube ×") or all at once with "Reject all". Each grant/withdrawal is logged as `service_allow` / `service_withdraw`, with a `services` column in the register. Not available in Light mode.

### YouTube thumbnail

The thumbnail is stored locally in `uploads/cmp-thumbs/` and downloaded in the background through WP-Cron, so no request goes to YouTube before consent. This needs WP-Cron working (or a system cron hitting `wp-cron.php`); until the download completes, the plain placeholder is shown.

### WP Consent API

Optional. If present, the plugin registers itself and sends `wp_set_consent()` on each decision and on return visits. Mapping: necessary → functional, statistics → statistics + statistics-anonymous, marketing → marketing, preferences → preferences. Custom categories are not sent.

### Limit: GTM and injected JavaScript

Only what is in the page HTML is blocked. Anything that GTM or other JavaScript injects later must be controlled from GTM (Consent Mode).

### Standalone

`standalone/cmp.js` does not load the library: on landing pages markers are added by hand (see `standalone/marker-generator.html`; services in `necessary`, like reCAPTCHA, are not marked at all). It supports "Always load" for embeds that carry `data-cmp-service`; it keeps no consent log. See `standalone/README.md`.

See [docs/api.md](docs/api.md) for the complete reference.

## Contributing

Bug reports and pull requests are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) for how to run the tests and build the assets, and [docs/api.md](docs/api.md) for the full developer API.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
