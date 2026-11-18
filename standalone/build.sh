#!/usr/bin/env bash
# Sync shared assets into the standalone bundle (single source of truth).
set -euo pipefail
cd "$(dirname "$0")/.."
cp assets/css/banner.css standalone/cmp.css
echo "Synced assets/css/banner.css -> standalone/cmp.css"
# Services library for the marker generator. services.js lets the generator work
# when opened from file:// (fetching a local JSON file fails there).
if command -v php >/dev/null 2>&1; then
  php -r 'json_decode(file_get_contents($argv[1])); exit(json_last_error() === JSON_ERROR_NONE ? 0 : 1);' data/services.json \
    || { echo "ERROR: data/services.json is not valid JSON" >&2; exit 1; }
elif command -v python3 >/dev/null 2>&1; then
  python3 -m json.tool data/services.json >/dev/null \
    || { echo "ERROR: data/services.json is not valid JSON" >&2; exit 1; }
else
  echo "WARNING: neither php nor python3 found, services.json not validated" >&2
fi
cp data/services.json standalone/services.json
printf 'window.CMP_SERVICES = %s;\n' "$(cat data/services.json)" > standalone/services.js
echo "Synced data/services.json -> standalone/services.json, standalone/services.js"
