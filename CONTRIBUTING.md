# Contributing

Thanks for helping with BiscuitWP. It is free and open-source (GPL-2.0-or-later); issues and pull requests are welcome on GitHub. For security problems, do not open an issue: see [SECURITY.md](SECURITY.md).

Requirements for the plugin itself: PHP 7.4+, WordPress 6.0+. Keep new code compatible with PHP 7.4 and add no runtime dependencies.

## Tests

The pure logic (consent evaluation, signals, sanitization, audit, i18n, legal) has standalone PHP tests, with no PHPUnit or Composer:

```bash
php tests/run.php
node standalone/tests/run.js   # the standalone bundle
```

Both must pass before you open a pull request. Write the test first when you fix a bug or add a feature.

### Browser tests (e2e)

`bin/e2e.sh` checks in a real Chromium that **no tracker makes a request before consent**, for every service in `data/services.json`: WordPress (Pro Strict and Advanced: new visitor, "Accept all", "Necessary only") and the standalone bundle. It starts a throwaway WordPress Playground (the plugin as in the zip plus the mu-plugin in `tests/e2e/fixture/`) and a static server for `tests/e2e/standalone.html`. Every request to another origin is recorded and stopped, so no traffic reaches third parties.

```bash
PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 npm install   # once; playwright 1.59.1, no browsers
bash bin/e2e.sh                                  # about 45 s; exit code 0 = green
node tests/e2e/match.test.js                     # pure tests (request to service matching, fixture)
```

- Needs Chromium for Playwright 1.59.1 in the local cache (`~/Library/Caches/ms-playwright`, revision 1217), `python3` and `npx` (the Playground CLI comes from the npm cache). The script downloads no browsers; if they are missing it exits with a message and code 1.
- The report is `service | scenario | ok/LEAK/MISSING | url`. LEAK = a request before consent (or after "Necessary only"); MISSING = a service allowed before consent did not load, or did not load after "Accept all". The `control:unblocked` scenario runs with blocking off and proves the test sees every snippet (NOFIRE = the test is blind).
- What is allowed before consent comes from the plugin (`/?cmp_e2e_expect=1`, fixture only): `necessary` or `exempt`; in Advanced also GA4/GTM.
- A new service in `data/services.json` enters the fixture and the check automatically.
- Before the decision and after "Necessary only", any external request the test cannot attribute to a library service is also a LEAK (`(unattributed)`).
- A fixed safety net, independent of the plugin: Meta Pixel, TikTok, LinkedIn, Clarity, Hotjar, YouTube, Vimeo, Google Maps, HubSpot, Tawk.to and Crisp may never load before consent (Strict, Advanced, standalone); Advanced may add only GA4/GTM; the plugin's categories must be the ones in `data/services.json`.
- The fixture covers `<script src>`, inline scripts (gtag, GTM, fbq, a pixel `<img>` created from JS, `navigator.sendBeacon`, `fetch`), `<noscript><img>` and `<iframe>`. It does not see preconnect/dns-prefetch (not requests), WebSocket or service workers.
- Variables: `CMP_E2E_READY_TIMEOUT` (default 180 s), `CMP_E2E_RUN_TIMEOUT` (default 300 s, the whole browser run), `CMP_E2E_PG_PORT`, `CMP_E2E_PLAYGROUND_CLI` (default `@wp-playground/cli@3.1.65`), `CMP_E2E_SETTLE_MS` (default 1500).

## Building assets

The front-end assets are minified from their sources and each `.min` file carries the hash of its source. After changing `assets/js/banner.js`, `assets/css/banner.css`, `standalone/cmp.js` or `standalone/cmp.css`, rebuild:

```bash
bash bin/build-assets.sh
```

`tests/test-assets.php` fails when a source changed without a rebuild. Tools are fetched on demand with `npx`; nothing is installed in the repo.

## Translations

Admin texts are written in Romanian in the source and translated to English and Russian. After changing any translatable string:

```bash
bash bin/i18n.sh
```

It regenerates the `.pot`, merges it into each `.po`, compiles the `.mo` and prints how many texts are translated, fuzzy and untranslated. The tests fail on a stale `.pot`, an incomplete or fuzzy `.po`, or a `.mo` older than its `.po`. It needs WP-CLI and gettext.

## Releases (maintainers only)

Releases are made by the maintainers with `bin/release.sh`; contributors do not need it. Updates are delivered as Ed25519-signed static manifests, with the zip on GitHub Releases. The release notes come from `CHANGELOG.md`, and the script refuses to release unless the plugin header `Version`, `CMP_VERSION` and the `Stable tag` in `readme.txt` all equal the release version.

```bash
bin/release.sh X.Y.Z               # tests + e2e, build and sign the zip, GitHub prerelease, signed beta manifest
bin/release.sh --promote X.Y.Z     # the beta version becomes stable
bin/release.sh --revoke X.Y.Z      # never offer X.Y.Z again
bin/release.sh --resign            # re-sign both manifests (monthly; they expire after 60 days)
```

Every one of them also regenerates the public pages of consent.rt.md (`site/` plus the changelog, the keys and the revoked list) into `release/site/`, which is committed with the new state. After the release, upload `release/site/` together with the manifests (the script prints the `scp` commands). A release (`X.Y.Z`) also writes a CycloneDX SBOM, `dist/consent-manager-pro-X.Y.Z.cdx.json`, and attaches it to the GitHub release. Before releasing, bump the `Stable tag` in `readme.txt` along with the header `Version` and `CMP_VERSION`.

Add `--dry-run` to any of them to rehearse: it writes only to `dist/` (the pages go to `dist/site/`; no gh, push or commit) and nothing it produces may be uploaded. The signing keys are kept offline by the maintainers; new manifest or rescue keys are created with `bin/keygen.sh manifest|rescue [--out <path>]`, which prints only the public key. Secret keys are never printed or committed.

### Security fixes for the previous minor version

`bin/release.sh` only publishes versions newer than the current manifests, and sites are only offered newer versions. A fix for the previous minor line (for example 1.0.x after 1.1.0 is out) is therefore published only on GitHub Releases, as a zip signed with the release key, without a manifest entry and without an automatic update (see `SECURITY.md`).
