#!/usr/bin/env bash
# Build the minified front-end assets. Idempotent.
#   assets/js/banner.js      -> assets/js/banner.min.js
#   assets/css/banner.css    -> assets/css/banner.min.css
#   assets/js/banner-check.js -> assets/js/banner-check.min.js (check mode only; not in the budget)
#   standalone/cmp.js        -> standalone/cmp.min.js
#   standalone/cmp.css       -> standalone/cmp.min.css  (synced from banner.css first)
# Each .min file starts with `/*! cmp-src-sha256:<sha256 of the source> */`;
# tests/test-assets.php fails when a source changed without a rebuild.
# Tools are fetched on demand (npx), nothing is installed in the repo.
set -euo pipefail
cd "$(dirname "$0")/.."

export npm_config_cache="${npm_config_cache:-/tmp/npmcache-cmp}"
TERSER="npx -y terser@5.31.0"
CSSO="npx -y csso-cli@4.0.2"

sha() { shasum -a 256 "$1" | awk '{print $1}'; }

min_js() { # src dest
	local tmp; tmp="$(mktemp)"
	# `-c -m --ecma 5`: ES5 output; top-level names stay (script, not module).
	# --comments '/^!/' keeps `/*!` banner comments from the source.
	$TERSER "$1" -c -m --ecma 5 --comments '/^!/' -o "$tmp"
	{ printf '/*! cmp-src-sha256:%s */\n' "$(sha "$1")"; cat "$tmp"; } > "$2"
	rm -f "$tmp"
	echo "Built $2"
}

min_css() { # src dest
	local tmp; tmp="$(mktemp)"
	$CSSO --input "$1" --output "$tmp"
	# csso turns CSS escapes (e.g. "\203A") into raw UTF-8: escape them back, so
	# the file is pure ASCII whatever charset it is served with.
	perl -CSD -pi -e 's/([^\x00-\x7F])/sprintf("\\%X ", ord($1))/ge' "$tmp"
	{ printf '/*! cmp-src-sha256:%s */\n' "$(sha "$1")"; cat "$tmp"; } > "$2"
	rm -f "$tmp"
	echo "Built $2"
}

bash standalone/build.sh

min_js  assets/js/banner.js   assets/js/banner.min.js
min_css assets/css/banner.css assets/css/banner.min.css
min_js  assets/js/banner-check.js assets/js/banner-check.min.js
min_js  standalone/cmp.js     standalone/cmp.min.js
min_css standalone/cmp.css    standalone/cmp.min.css

echo "gzip sizes (bytes):"
for f in assets/js/banner.min.js assets/css/banner.min.css assets/js/banner-check.min.js standalone/cmp.min.js standalone/cmp.min.css; do
	printf '  %-28s %s\n' "$f" "$(gzip -9 -c "$f" | wc -c | tr -d ' ')"
done
