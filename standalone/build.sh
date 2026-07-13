#!/usr/bin/env bash
# Sync the banner styles into the standalone bundle (single source of truth).
set -euo pipefail
cd "$(dirname "$0")/.."
cp assets/css/banner.css standalone/cmp.css
echo "Synced assets/css/banner.css -> standalone/cmp.css"
