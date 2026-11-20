#!/usr/bin/env bash
# Generate an Ed25519 key pair for Consent Manager Pro updates.
#   bin/keygen.sh manifest            -> ~/.config/cmp-release/manifest.key
#   bin/keygen.sh rescue --out <path> -> <path> (keep it offline)
# Writes the base64 SECRET key (64 bytes, sodium format) with chmod 600,
# refuses to overwrite an existing file and prints only the PUBLIC key.
set -euo pipefail

usage() { echo "Usage: bin/keygen.sh <manifest|rescue> [--out <path>]" >&2; exit 1; }

TYPE="${1:-}"
case "$TYPE" in
	manifest|rescue) ;;
	*) usage ;;
esac
shift

OUT="$HOME/.config/cmp-release/$TYPE.key"
while [ $# -gt 0 ]; do
	case "$1" in
		--out) [ $# -ge 2 ] && [ -n "$2" ] || usage; OUT="$2"; shift 2 ;;
		*) usage ;;
	esac
done

if [ -e "$OUT" ]; then
	echo "ERROR: $OUT already exists; refusing to overwrite a key." >&2; exit 1
fi

DIR="$(dirname "$OUT")"
if [ ! -d "$DIR" ]; then
	( umask 077 && mkdir -p "$DIR" )
fi

umask 077
PUB="$(php -r '
	if ( ! function_exists( "sodium_crypto_sign_keypair" ) ) { fwrite( STDERR, "ERROR: PHP sodium extension missing\n" ); exit( 1 ); }
	$kp = sodium_crypto_sign_keypair();
	$fh = fopen( $argv[1], "x" );
	if ( false === $fh ) { fwrite( STDERR, "ERROR: cannot create " . $argv[1] . "\n" ); exit( 1 ); }
	fwrite( $fh, base64_encode( sodium_crypto_sign_secretkey( $kp ) ) . "\n" );
	fclose( $fh );
	chmod( $argv[1], 0600 );
	echo base64_encode( sodium_crypto_sign_publickey( $kp ) ), "\n";
' "$OUT")"

CONST="MANIFEST_PUBLIC_KEY"
[ "$TYPE" = "rescue" ] && CONST="RESCUE_PUBLIC_KEY"
echo "Secret key written to $OUT (chmod 600). Back it up in the password manager; never commit it."
echo "Public key (CMP_Updater::$CONST):"
echo "$PUB"
