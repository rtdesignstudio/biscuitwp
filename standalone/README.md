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
| `position` | string | `"modal-center"` | Modal position: `"modal-center"`, `"bottom"`, `"top"` |
| `lang` | string | `"ro"` | Language: `"ro"` (Romanian), `"ru"` (Russian), `"en"` (English) |
| `site` | string | — | Optional site label shown in the banner |
| `categories` | array | `["necessary", "statistics", "marketing"]` | Consent categories to offer. `"necessary"` is always required and locked. |
| `privacyUrl` | string | — | URL to privacy/cookie policy. URLs starting with `javascript:` or `data:` are stripped for security. |
| `texts` | object | — | Override default text strings. Supported keys: `title`, `intro`, `prefs`, `reject`, `accept`, `prefs_title`, `save`, `badge`, `locked`, `privacy`, `back`, `dialogLabel` |
| `respectGPC` | boolean | `false` | If `true`, honor Global Privacy Control (DNT) and auto-reject non-necessary consent |
| `reopenButton` | boolean | `true` | Auto-render a floating round "cookie settings" button (bottom-left) after the visitor decides; set `false` to disable. Pro mode only. |

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

- `"necessary"` — Always included, cannot be toggled. Use for scripts that are legally required.
- `"statistics"` — Analytics and performance monitoring (Google Analytics, Hotjar, etc.).
- `"marketing"` — Advertising and retargeting pixels (Facebook, LinkedIn, etc.).

---

## Public API

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

Any element with `data-cmp-settings` or class `cmp-reopen` will open the preferences when clicked:

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
- The bundle ships unminified (readable source); minification/CDN packaging is deferred to a later phase.

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
