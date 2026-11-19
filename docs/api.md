# Consent Manager Pro: public API

The same JavaScript API is available in the WordPress plugin and in the standalone build (`standalone/cmp.js`), plus one PHP helper for themes. Use it to tie your own code (a chat widget, a map, an analytics snippet) to the visitor's consent without reading the plugin source.

## Availability

- **WordPress:** the banner script (handle `cmp-banner`) loads in the footer, so inline scripts in the page body run before `window.CMP` exists. Enqueue your script with it as a dependency (`wp_enqueue_script( 'my-script', $url, array( 'cmp-banner' ), $ver, true )`) or wait for `DOMContentLoaded`.
- **Standalone:** `window.CMP` exists only **after `DOMContentLoaded`**. Do not call it from a script that runs earlier. Either wait:

  ```javascript
  document.addEventListener('DOMContentLoaded', function () {
    window.CMP.onConsent(function (consent) { /* ... */ });
  });
  ```

  or listen to the `cmp:consent` event (see below), which needs no `window.CMP` at all.

Categories are identified by slug: `necessary` (always allowed), `statistics` and `marketing` by default, plus any custom category the site defines.

## Members

### `CMP.version`

String. The plugin version (WordPress) or the package version (standalone). Not the policy version. Empty if the banner data is missing.

### `CMP.isAllowed(slug)` -> boolean

`true` if the category is allowed right now. A required category (`necessary`) is always allowed. A category that gained a service since the visitor decided is not allowed until they decide again.

```javascript
if (window.CMP.isAllowed('statistics')) { /* load analytics */ }
```

### `CMP.getConsent()` -> object | null

The stored decision, for example `{ v: "3", categories: ["necessary", "statistics"], decision: "partial", ts: 1783952681, id: "..." }`, or `null`/`undefined` when the visitor has not decided. `categories` is the effective list (see `isAllowed`).

### `CMP.openPreferences()`

Opens the preferences panel. Elements with `data-cmp-settings`, the class `cmp-settings-link` / `cmp-reopen`, or a link to `#cmp-settings` do the same on click.

### `CMP.onConsent(fn)` -> `unsubscribe`

Calls `fn(consent)` immediately if the visitor already decided (for the current policy version), then again on every later decision. `consent` is what `getConsent()` returns. It fires for any decision: accept, reject, a panel save (including one that removes categories), a change through `allowCategory` / `denyCategory`, and also the "Încarcă mereu" (always load) choice for a single service or its withdrawal (once the visitor has decided), **even when the categories did not change**. Compare the categories yourself if you only care about changes. The immediate first call happens before `onConsent` returns, so the returned function is not yet available inside the callback.

A listener that throws is reported with `console.error` and does not stop the others. Returns a function that removes the listener. In Light mode nothing is ever called (there is no decision) and the returned function is a no-op.

```javascript
// React to every decision; the flag keeps the widget from starting twice.
var started = false;
window.CMP.onConsent(function (consent) {
  if (!started && consent.categories.indexOf('marketing') !== -1) {
    started = true;
    startChat();
  }
});
```

To start something once, prefer `whenAllowed`:

```javascript
window.CMP.whenAllowed('marketing', startChat);
```

### `CMP.whenAllowed(slug, fn)` -> `unsubscribe`

Runs `fn()` **once**: immediately if `isAllowed(slug)`, otherwise at the first decision that allows the category. A required category runs immediately. Returns a function that cancels the wait. In Light mode, for an optional category, `fn` never runs.

```javascript
window.CMP.whenAllowed('statistics', function () {
  loadAnalytics();
});
```

### `CMP.allowCategory(slug)` -> boolean

Adds the category to the visitor's consent, through the same path as saving the preferences panel: cookie, Consent Mode update, unblocking of scripts and embeds, and (WordPress) an entry in the consent register. With no previous decision, the consent becomes the required categories plus `slug`. Returns `true` if it applied a change; `false` if it did nothing (unknown or required category, already allowed, Light mode, or no visitor gesture).

**It works only from a real visitor gesture** (see the rule below). Typical use is your own "Load map" button:

```html
<button id="load-map">Load map</button>
<script>
document.addEventListener('DOMContentLoaded', function () {
  document.getElementById('load-map').addEventListener('click', function () {
    window.CMP.allowCategory('marketing');   // runs inside the click
  });
  window.CMP.whenAllowed('marketing', function () { initMap(); });
});
</script>
```

Behaviour worth knowing:

- On a first visit the call closes the banner like a panel save. If the site enabled "reload on decision", the page may reload right after (it is the first decision).
- WordPress records the decision in the consent register as `custom` ("through the site's own button"). The standalone build keeps no register; it stores `decision: "custom"` in the cookie.
- Make the control say which category it enables and why: consent must be specific and informed.

### `CMP.denyCategory(slug)` -> boolean

Removes the category from the consent, by the same path. Withdrawing must be as easy as granting (GDPR art. 7(3)), so **no gesture is needed**. Does nothing (returns `false`) for a required or unknown category, a category that is not currently allowed, or in Light mode. Also logged as `custom` (WordPress). Scripts and embeds that already ran on this page keep running until the next page load, as with the panel; consent state and Consent Mode are updated and nothing new loads. If you need a hard stop, reload after the call.

```javascript
document.getElementById('stop-tracking').addEventListener('click', function () {
  window.CMP.denyCategory('statistics');
});
```

## The gesture rule for `allowCategory`

Consent must be a clear affirmative act of the visitor (GDPR art. 4(11) and 7; Moldova Law 195/2024 art. 7), never by a site script acting on its own. So `allowCategory` applies only when:

- the browser has the User Activation API (`navigator.userActivation`) and the visitor's activation is active; **or**
- the browser does not have it, and the call is made inside a trusted event handler (`window.event.isTrusted`) for one of: `click`, `keydown`, `keyup`, `mousedown`, `mouseup`, `pointerdown`, `pointerup`, `touchend`. Events a script can wait for (load, scroll, message, timers...) never count.

With the User Activation API, browsers keep a click "active" for a few seconds (transient activation), so a call that follows a click closely, even after a short async step, still works. In older browsers the call must be made synchronously inside the click/key handler; calling it synchronously from the click is the only form that works everywhere. A call from page load, a timer long after the click, or a script that never involved the visitor is ignored: it returns `false` and logs `CMP.allowCategory: ignored, call it from a visitor click` to the console.

## The `cmp:consent` event

Dispatched on `document` after every saved category decision:

```javascript
document.addEventListener('cmp:consent', function (e) {
  // e.detail = { categories: ['necessary', 'statistics'], decision: 'partial' }
});
```

`decision` is one of `accepted_all`, `rejected_all`, `partial`, `custom`. It does not fire for per-service "Încarcă mereu" choices; use `onConsent` for those. It works in the standalone build before and without `window.CMP`.

## PHP: `cmp_is_allowed( string $category ): bool`

For themes and plugins, server side:

```php
if ( function_exists( 'cmp_is_allowed' ) && cmp_is_allowed( 'statistics' ) ) {
    // print the analytics snippet
}
```

It reads the visitor's consent cookie for the current policy version. A required category is always allowed; with no decision, everything else is not. It is a thin wrapper over `CMP_Consent::is_allowed` and is declared only if no function with that name exists. Do not use it on full-page-cached output: the cookie is per visitor.

## Light mode

Light mode has no preferences and no stored decision. `isAllowed` and `getConsent` keep working, `allowCategory` / `denyCategory` return `false` and change nothing, and `onConsent` / `whenAllowed` never call back for optional categories (a required category still runs `whenAllowed` immediately).

## Check mode

In the admin's scan/check mode the API uses the session check cookies like the rest of the banner, and nothing is written to the consent register.
