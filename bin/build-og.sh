#!/usr/bin/env bash
# The link-preview pictures (Open Graph) of the landing pages:
#   site/assets/og/og-ro.png, og-en.png, og-ru.png (1200×630, committed)
# Each one is a page made by bin/build-og.php (fonts inlined, nothing from the network),
# screenshotted by headless Chrome. Run it after changing the title, the lead or the hero
# picture; the PNGs are committed, the site build only copies them.
#   CHROME=/path/to/chrome bin/build-og.sh
set -euo pipefail
cd "$(dirname "$0")/.."

CHROME="${CHROME:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}"
[ -x "$CHROME" ] || { echo "Chrome not found at $CHROME (set CHROME=...)" >&2; exit 1; }
command -v php >/dev/null 2>&1 || { echo "php not found" >&2; exit 1; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p site/assets/og

for lang in ro en ru; do
	php bin/build-og.php "$lang" "$TMP/og-$lang.html" >/dev/null
	"$CHROME" --headless=new --disable-gpu --hide-scrollbars --no-first-run --no-default-browser-check \
		--user-data-dir="$TMP/profile" --force-device-scale-factor=1 --window-size=1200,630 \
		--screenshot="$TMP/og-$lang.png" "file://$TMP/og-$lang.html" >/dev/null 2>&1
	size="$(php -r '$s = getimagesize( $argv[1] ); echo $s ? $s[0] . "x" . $s[1] : "none";' -- "$TMP/og-$lang.png")"
	[ "$size" = "1200x630" ] || { echo "og-$lang.png is $size, expected 1200x630" >&2; exit 1; }
	mv "$TMP/og-$lang.png" "site/assets/og/og-$lang.png"
	echo "site/assets/og/og-$lang.png"
done
