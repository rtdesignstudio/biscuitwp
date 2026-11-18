# Consent Manager Pro

WordPress plugin for GDPR cookie consent, legal page generation and a consent audit log. Theme-agnostic, no jQuery, no external dependencies.

- **Author:** Sergiu Pascaru — [rt.md](https://rt.md)
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
```

## Updates & releases

Sites update straight from this repo's GitHub Releases (no wordpress.org):

1. Define a fine-grained, read-only token on each site: `define( 'CMP_GITHUB_TOKEN', '...' );` in `wp-config.php` (or paste it in Settings → GitHub token).
2. Updates then show up natively under **Plugins / Dashboard → Updates**; the plugin row also has a **Check for updates** link.

To publish a new version: bump `Version:` and `CMP_VERSION` in `consent-manager-pro.php`, commit, then run `./bin/release.sh X.Y.Z` (requires the `gh` CLI).

### Update proxy (optional, for many client sites)

To avoid putting a GitHub token on every site, deploy the relay in `proxy/cmp-update.php` on your own server (e.g. rt.md) and let the plugin talk to it instead of GitHub:

1. On the server: upload `proxy/cmp-update.php`, copy `proxy/config.sample.php` to `config.php` (kept out of the web root or via the `CMP_PROXY_CONFIG` env var), and fill in the GitHub token + a shared key.
2. In the plugin: set `CMP_Updater::PROXY_URL` to the proxy URL and `CMP_Updater::PROXY_KEY` to the same shared key (or define `CMP_UPDATE_PROXY` / `CMP_UPDATE_KEY` per site). Commit and release.
3. Client sites then need **no token at all** — the proxy holds the single token and 302-redirects downloads to GitHub's signed CDN URL.

The `proxy/` folder is excluded from the plugin zip, so it never ships to sites.

## Development

The pure logic (consent evaluation, signals, sanitization, audit, i18n, legal) has standalone PHP tests, with no PHPUnit/Composer:

```bash
php tests/run.php
```

## License

GPL-2.0-or-later.
