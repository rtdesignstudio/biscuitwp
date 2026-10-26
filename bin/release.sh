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
	--exclude 'proxy' --exclude '.superpowers' --exclude '.git*' --exclude '.DS_Store' \
	./ "dist/$SLUG/"
( cd dist && zip -qr "$SLUG.zip" "$SLUG" )
echo "Built dist/$SLUG.zip"

# 3b. Sign the zip (detached Ed25519). The secret key lives only on the release
#     machine; sites refuse any package whose signature does not verify against
#     CMP_Updater::SIGN_PUBLIC_KEY. Override the key path with CMP_SIGNING_KEY.
SIGNING_KEY="${CMP_SIGNING_KEY:-$HOME/.config/cmp-release/signing.key}"
[ -r "$SIGNING_KEY" ] || { echo "ERROR: signing key not found at $SIGNING_KEY"; exit 1; }
php -r '
	$sk  = base64_decode( trim( file_get_contents( $argv[1] ) ), true );
	$zip = file_get_contents( $argv[2] );
	if ( false === $sk || 64 !== strlen( $sk ) ) { fwrite( STDERR, "ERROR: invalid signing key\n" ); exit( 1 ); }
	file_put_contents( $argv[2] . ".sig", base64_encode( sodium_crypto_sign_detached( $zip, $sk ) ) . "\n" );
' "$SIGNING_KEY" "dist/$SLUG.zip"
# Verify with the public key baked into the plugin, so a key mismatch never ships.
PUBKEY="$(grep -o "SIGN_PUBLIC_KEY = '[^']*'" includes/modules/class-updater.php | cut -d"'" -f2)"
php -r '
	$ok = sodium_crypto_sign_verify_detached( base64_decode( trim( file_get_contents( $argv[2] . ".sig" ) ) ), file_get_contents( $argv[2] ), base64_decode( $argv[1] ) );
	if ( ! $ok ) { fwrite( STDERR, "ERROR: signature does not verify against SIGN_PUBLIC_KEY\n" ); exit( 1 ); }
' "$PUBKEY" "dist/$SLUG.zip"
echo "Signed dist/$SLUG.zip.sig"

# 4. Tag + push + release.
git tag "v$VERSION"
git push -q origin main --tags
gh release create "v$VERSION" "dist/$SLUG.zip" "dist/$SLUG.zip.sig" --title "v$VERSION" --generate-notes
echo "Released v$VERSION."
