#!/usr/bin/env bash
# Sync shared assets into the standalone bundle (single source of truth).
set -euo pipefail
cd "$(dirname "$0")/.."
cp assets/css/banner.css standalone/cmp.css
echo "Synced assets/css/banner.css -> standalone/cmp.css"
# Services library for the marker generator. services.js lets the generator work
# when opened from file:// (fetching a local JSON file fails there).
command -v php >/dev/null 2>&1 || { echo "ERROR: php not found" >&2; exit 1; }
php -r 'json_decode(file_get_contents($argv[1])); exit(json_last_error() === JSON_ERROR_NONE ? 0 : 1);' data/services.json \
  || { echo "ERROR: data/services.json is not valid JSON" >&2; exit 1; }
# Without the services marked "standalone": false (the Maps API loader: a plain marker
# breaks the theme that uses it; the plugin has a gate for it, the bundle does not).
php -r '
$d = json_decode( file_get_contents( "data/services.json" ), true );
$d["services"] = array_values( array_filter( $d["services"], function ( $s ) { return ! isset( $s["standalone"] ) || false !== $s["standalone"]; } ) );
$j = json_encode( $d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
file_put_contents( "standalone/services.json", $j . "\n" );
file_put_contents( "standalone/services.js", "window.CMP_SERVICES = " . $j . ";\n" );
'
echo "Synced data/services.json -> standalone/services.json, standalone/services.js"
