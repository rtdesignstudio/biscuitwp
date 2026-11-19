# Consent Manager Pro — Standalone Bundle

A standalone, plugin-independent consent management solution for cookie consent, preferences, and tracking control.

## Quick Start

### Step 1: Link the stylesheet
```html
<link rel="stylesheet" href="path/to/cmp.css">
```

### Step 2: Configure and load the script
```html
<script>
  window.CMP_CONFIG = {
    mode: 'pro',
    preset: 'egov',
    position: 'modal-center',
    lang: 'ro',
    categories: ['necessary', 'statistics', 'marketing'],
    privacyUrl: 'https://example.com/privacy',
    respectGPC: false
  };
</script>
<script src="path/to/cmp.js" defer></script>
```

### Step 3: Mark tracker scripts
Replace `<script src="...">` with `data-cmp-src` attribute and `type="text/plain"`:

**Before:**
```html
<script src="https://www.googletagmanager.com/gtag/js?id=G-XXXXX"></script>
```

**After (with base64-encoded URL):**
```html
<script type="text/plain"
        data-cmp-block="statistics"
        data-cmp-enc="1"
        data-cmp-src="aHR0cHM6Ly93d3cuZ29vZ2xldGFnbWFuYWdlci5jb20vZ3RhZy9qcz9pZD1HLVhYWFhY"></script>
```

For inline scripts, mark with `data-cmp-block` and `type="text/plain"`:

**Before:**
```html
<script>
  window.fbq('track', 'PageView');
</script>
```

**After:**
```html
<script type="text/plain" data-cmp-block="marketing">
  window.fbq('track', 'PageView');
</script>
```

---

## Configuration Keys

All keys are **optional**. Defaults are applied if not specified.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `mode` | string | `"pro"` | `"pro"` (full consent form) or `"light"` (compact banner) |
| `preset` | string | `"default"` | Preset theme: `"neutral"`, `"warm"`, `"dark"`, `"egov"`, `"default"` |
| `position` | string | `"modal-center"` | Banner position: `"top-left"`, `"top-center"`, `"top-right"`, `"middle-center"`, `"bottom-left"`, `"bottom-center"`, `"bottom-right"` |
| `lang` | string | `"ro"` | Language: `"ro"` (Romanian), `"ru"` (Russian), `"en"` (English) |
| `site` | string | — | Optional site label shown in the banner |
| `categories` | array | `["necessary", "statistics", "marketing"]` | Consent categories to offer. `"necessary"` is always required and locked. |
| `privacyUrl` | string | — | URL to privacy/cookie policy. URLs starting with `javascript:` or `data:` are stripped for security. |
| `texts` | object | — | Override default text strings. Supported keys: `title`, `intro`, `prefs`, `reject`, `accept`, `prefs_title`, `save`, `badge`, `locked`, `privacy`, `back`, `dialogLabel` |
| `respectGPC` | boolean | `false` | If `true`, honor Global Privacy Control (`navigator.globalPrivacyControl`) and auto-reject non-necessary consent. Do-Not-Track is ignored. |
| `reopenButton` | boolean | `false` | Auto-render a floating round "cookie settings" button (bottom-left) after the visitor decides; set `true` to enable. Pro mode only. Recommended: leave it off and put a "Cookie settings" link in the footer (see below). |
| `services` | object | — | Services per category, `{ slug: [ids] }`, written by hand. Enables re-consent (see below). Missing or invalid = unknown: behaviour as before. |
| `serviceNames` | object | — | Optional `{ id: "Display name" }` for the "new" badges. Without it the id is shown. |
| `cookieDays` | number | `180` | Number of days the consent choice is remembered before the banner reappears. Clamped to 1-3650, else falls back to 180. |

### Example: Custom texts
```javascript
window.CMP_CONFIG = {
  texts: {
    title: 'Cookie Settings',
    intro: 'We use cookies to improve your experience.',
    reject: 'Reject All',
    accept: 'Accept All',
    prefs_title: 'Cookie Preferences'
  }
};
```

---

## Re-consent for new services

When you add a service to a category (say a TikTok pixel under `marketing`), visitors who already accepted that category should be asked again. The banner does this on its own when `services` is set:

- On every decision the banner stores, in the consent cookie, the services of the categories the visitor accepted.
- On the next visit, if a category the visitor accepted now lists an id the cookie does not know, that category counts as **refused** (scripts, embeds, Consent Mode, `CMP.isAllowed`, `CMP.getConsent`) until the visitor decides again.
- The banner opens straight on the preferences, with the title "We added new services", a short reason, and a "new" badge with the service names next to the affected category. That category starts switched off. There is no "decide later": it would be tacit consent to the new service.
- Cookies saved before you set `services` carry no list, so nothing is re-asked for them. Removing a service never re-asks. Always-on (`necessary`) categories never re-ask.

**You must update `services` whenever you add a service on the site.** The bundle cannot see what the page loads; the list is the only signal. Without `services` (or with an invalid value) nothing is stored and nothing is re-asked.

```html
<script>
  window.CMP_CONFIG = {
    categories: ['necessary', 'statistics', 'marketing'],
    services: {
      statistics: ['google-analytics'],
      marketing: ['meta-pixel', 'tiktok-pixel']
    },
    serviceNames: {
      'google-analytics': 'Google Analytics',
      'meta-pixel': 'Meta Pixel',
      'tiktok-pixel': 'TikTok Pixel'
    }
  };
</script>
```

The texts (`reconsent_title`, `reconsent_text`, `svc_new`) come in the RO / RU / EN packs and can be overridden through `texts`. Light mode has no re-consent.

## Tracker Script Marking

### External scripts (third-party trackers)

**Option A: Base64-encoded URL** (recommended for external resources)

1. Encode the tracker URL in base64:
   ```bash
   echo -n "https://www.googletagmanager.com/gtag/js?id=G-DEMO" | base64
   # Output: aHR0cHM6Ly93d3cuZ29vZ2xldGFnbWFuYWdlci5jb20vZ3RhZy9qcz9pZD1HLERFTU8=
   ```

2. Replace `<script src="...">` with:
   ```html
   <script type="text/plain"
           data-cmp-block="statistics"
           data-cmp-enc="1"
           data-cmp-src="aHR0cHM6Ly93d3cuZ29vZ2xldGFnbWFuYWdlci5jb20vZ3RhZy9qcz9pZD1HLERFTU8="></script>
   ```

**Option B: Plain URL** (for trusted/internal trackers)

```html
<script type="text/plain"
        data-cmp-block="statistics"
        data-cmp-src="https://www.googletagmanager.com/gtag/js?id=G-DEMO"></script>
```

### Inline scripts (your own tracking code)

Simply change `type="text/javascript"` (or omit it) to `type="text/plain"`:

```html
<script type="text/plain" data-cmp-block="marketing">
  // Your tracking code here
  console.log('Track user action');
</script>
```

### Supported categories

- `"necessary"` — Always included, cannot be toggled. Scripts that are legally required are left unmarked, as normal `<script>` tags. A `text/plain` marker with `necessary` would only run after the visitor decides, and never runs in Light mode.
- `"statistics"` — Analytics and performance monitoring (Google Analytics, Hotjar, etc.).
- `"marketing"` — Advertising and retargeting pixels (Facebook, LinkedIn, etc.).

---

## Embeds (YouTube, Vimeo, Google Maps)

Iframes set cookies as soon as they load, so wrap them in a placeholder. The
iframe inside `<template>` is inert: the browser does not load it until the
visitor accepts the category, or clicks the load button on that embed (which
loads only that one embed and does not change the saved consent).

```html
<div class="cmp-embed" data-cmp-embed="marketing" style="aspect-ratio:560/315;max-width:560px">
  <div class="cmp-embed__inner">
    <p class="cmp-embed__text">Conținut de la YouTube. Se încarcă doar cu acordul tău, pentru că poate seta cookie-uri.</p>
    <p class="cmp-embed__actions">
      <button type="button" class="cmp-embed__btn" data-cmp-embed-load>Încarcă conținutul</button>
      <a href="#cmp-settings" class="cmp-embed__link" data-cmp-settings>Setări cookie</a>
    </p>
  </div>
  <template data-cmp-embed-src>
    <iframe width="560" height="315" src="https://www.youtube-nocookie.com/embed/VIDEO_ID" allowfullscreen></iframe>
  </template>
</div>
```

Categories: YouTube and Google Maps → `marketing`, Vimeo → `statistics`. Prefer
`youtube-nocookie.com` for YouTube. The WordPress plugin generates a similar
markup automatically (the generator's output follows it).

### "Încarcă mereu" (Always load)

Add `data-cmp-service="<id>"` (and optionally `data-cmp-service-name="YouTube"`,
used in the preferences panel) on the `.cmp-embed` wrapper, plus a button with
`data-cmp-embed-always` next to the load button (use the class
`cmp-embed__always` and an `aria-label`, as the plugin does):

```html
<div class="cmp-embed" data-cmp-embed="marketing" data-cmp-service="youtube" data-cmp-service-name="YouTube"
     style="aspect-ratio:560/315;max-width:560px">
  <div class="cmp-embed__inner">
    <p class="cmp-embed__text">Conținut de la YouTube. Se încarcă doar cu acordul tău, pentru că poate seta cookie-uri.</p>
    <p class="cmp-embed__actions">
      <button type="button" class="cmp-embed__btn" data-cmp-embed-load>Încarcă conținutul</button>
      <button type="button" class="cmp-embed__always" data-cmp-embed-always aria-label="Încarcă mereu: YouTube">Încarcă mereu</button>
      <a href="#cmp-settings" class="cmp-embed__link" data-cmp-settings>Setări cookie</a>
    </p>
  </div>
  <template data-cmp-embed-src>
    <iframe width="560" height="315" src="https://www.youtube-nocookie.com/embed/VIDEO_ID" allowfullscreen></iframe>
  </template>
</div>
```

- Clicking "Încarcă mereu" loads this embed and every other embed with the same
  `data-cmp-service` on the page, and remembers the choice in the **`cmp_svc`**
  cookie (`{"v":"<policy version>","svc":["youtube"],"id":"<uuid>"}`, same lifetime as
  `cmp_consent`). On later visits those embeds load on their own.
- It is separate from the category consent: it does not close the banner and does
  not count as accepting or rejecting categories.
- **Withdrawal:** the preferences panel shows a row "Încărcate mereu: YouTube ×".
  Clicking × removes the service. Embeds already loaded stay until the next page load.
  The explicit "reject" button also clears the whole list (the cookie keeps its `id`);
  saving a partial selection and the GPC auto-reject do not.
- A different policy version ignores an older `cmp_svc`.
- In `mode: "light"` (no cookie) the button is removed at init; leave it out of the markup there.
- This build keeps no consent log: nothing is recorded for these choices.
- Without `data-cmp-service` the embed behaves as before (no "Încarcă mereu").

## Public API

Full reference for developers: [docs/api.md](../docs/api.md). In short, `window.CMP` (defined after `DOMContentLoaded`) has `version`, `isAllowed`, `getConsent`, `openPreferences`, `onConsent`, `whenAllowed`, `allowCategory` (visitor gesture only) and `denyCategory`, plus the `cmp:consent` event.

### Check consent for a category
```javascript
if (window.CMP.isAllowed('statistics')) {
  // Load analytics
}
```

### Retrieve full consent state
```javascript
const consent = window.CMP.getConsent();
// Returns: { necessary: true, statistics: false, marketing: true, ... }
```

### Open preferences modal
```javascript
window.CMP.openPreferences();
```

### Re-open via data attributes

Any element with `data-cmp-settings`, class `cmp-settings-link` / `cmp-reopen`, or a link to `#cmp-settings` will open the preferences when clicked (put one in the footer):

```html
<button data-cmp-settings>Cookie Settings</button>
<!-- OR -->
<a class="cmp-reopen">Manage cookies</a>
```

---

## Consent Cookie

The consent state is stored in a browser cookie named **`cmp_consent`**.

**Format:** URL-encoded JSON, `SameSite=Lax`, 180-day `max-age`. The cookie
must be readable by JavaScript (the bundle reads it via `document.cookie`), so it
is **not** `httpOnly`.

**Example (decoded):**
```
cmp_consent={"v":"1","categories":["necessary","statistics"],"decision":"partial","ts":1783952681}
```

---

## Implementation Notes

### Security
- **URL encoding:** External tracker URLs in `data-cmp-src` should be base64-encoded to obfuscate them from casual inspection.
- **Privacy URL validation:** URLs starting with `javascript:` or `data:` schemes are stripped to prevent XSS.
- **Consent cookie is client-readable** (not `httpOnly`) by design — the bundle reads it via `document.cookie` to restore the visitor's choice. It stores only the consent decision, no sensitive data.

### Performance
- The `defer` attribute on `<script src="cmp.js">` ensures the bundle loads after DOM parsing.
- Marked tracker scripts (`type="text/plain"`) are not executed by the browser; CMP injects them on consent.
- Minified builds ship next to the readable source: `cmp.min.js` and `cmp.min.css` (each starts with a `/*! cmp-src-sha256:… */` header). Use them in production. CDN packaging is deferred to a later phase.

### Browser Support
Modern browsers (Chrome, Firefox, Safari, Edge) with ES6 support. Requires `localStorage` and `fetch` for full functionality.

---

## Demo

See `demo.html` for a working example with two sample trackers (one external base64-encoded, one inline). Open in a browser and check the Developer Console to observe tracker execution when consent is given.

---

## Build & Sync

The `cmp.css` file is kept in sync with the WordPress plugin's banner styles via `./build.sh`. To rebuild after modifying the plugin:

```bash
./build.sh
```

This ensures the standalone bundle always matches the plugin's visual design.
To regenerate the minified files (`cmp.min.js`, `cmp.min.css`, and the plugin's `.min` assets) run `bash bin/build-assets.sh` from the repo root; it calls `build.sh` first.
It also produces, from `data/services.json`:

- `services.json`, an identical copy of the library;
- `services.js`, containing `window.CMP_SERVICES = <json>;`, which the marker
  generator loads with a plain `<script>` so it works when opened from `file://`
  (where fetching a local JSON file fails).

`cmp.js` does not load the library; on landing pages the markup is added by hand.

### Marker generator

`marker-generator.html` (open it in a browser, no server needed) has a "Serviciu"
select at the top, with the library grouped as "Scripturi" and "Embed-uri":
Google Analytics, Google Tag Manager, Meta Pixel, Microsoft Clarity, Hotjar,
TikTok Pixel, LinkedIn Insight Tag, HubSpot, Tawk.to, Crisp, Google reCAPTCHA
(scripts) and YouTube, Vimeo, Google Maps (embeds). Choosing one sets the
category; for a script it fills the URL field with an example on the service's
first host (if empty); for an embed it switches to the Embed type and adds
`data-cmp-service` to the markup, so "Încarcă mereu" works. If `services.js` is
missing, the select is hidden and the generator works as before.

reCAPTCHA is `necessary`: do not mark it. A `text/plain` marker is only
activated after the visitor decides (and never in Light mode), so a marked
reCAPTCHA would stay dead until then. Leave the original `<script>` as it is;
the generator then outputs the original tag unchanged and says so. The
standalone bundle blocks only what you mark.

---

## Future: Phase 2

Phase 2 will add **central audit logging** on rt.md, enabling:
- Consent event tracking across all sites using the bundle
- Historical audit trail of consent changes
- Compliance reporting for GDPR/CCPA audits
- Cross-site analytics (with user consent)

---

## License

Proprietary — Consent Manager Pro. All rights reserved.
