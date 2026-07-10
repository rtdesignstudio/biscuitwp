#!/usr/bin/env bash
# Release Consent Manager Pro: ./bin/release.sh 0.1.1
# Verifies the version bump, builds a clean zip, tags, and publishes a GitHub Release.
set -euo pipefail

VERSION="${1:-}"
[ -n "$VERSION" ] || { echo "Usage: ./bin/release.sh X.Y.Z"; exit 1; }

cd "$(dirname "$0")/.."
SLUG="consent-manager-pro"

# 1. Clean working tree.
if [ -n "$(git status --porcelain)" ]; then
	echo "ERROR: working tree not clean. Commit or stash first."; exit 1
fi

# 2. Header version must match.
HEADER_VERSION="$(grep -m1 '^ \* Version:' "$SLUG.php" | awk '{print $3}')"
if [ "$HEADER_VERSION" != "$VERSION" ]; then
	echo "ERROR: plugin header Version is '$HEADER_VERSION', expected '$VERSION'. Bump it (and CMP_VERSION) first."; exit 1
fi
if ! grep -q "define( 'CMP_VERSION', '$VERSION' )" "$SLUG.php"; then
	echo "ERROR: CMP_VERSION constant does not match '$VERSION'."; exit 1
fi

# 3. Build a clean zip (runtime files only).
rm -rf dist && mkdir -p "dist/$SLUG"
rsync -a --exclude 'docs' --exclude 'tests' --exclude 'bin' --exclude 'dist' \
	--exclude '.superpowers' --exclude '.git*' --exclude '.DS_Store' \
	./ "dist/$SLUG/"
( cd dist && zip -qr "$SLUG.zip" "$SLUG" )
echo "Built dist/$SLUG.zip"

# 4. Tag + push + release.
git tag "v$VERSION"
git push -q origin main --tags
gh release create "v$VERSION" "dist/$SLUG.zip" --title "v$VERSION" --generate-notes
echo "Released v$VERSION."
