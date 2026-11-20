#!/usr/bin/env bash
# Release Consent Manager Pro (B8a: signed static manifests on consent.rt.md,
# zips on GitHub Releases).
#
#   bin/release.sh X.Y.Z [--dry-run [--skip-checks]]   build, sign, GitHub prerelease, manifest-beta.json
#   bin/release.sh --promote X.Y.Z [--dry-run]         beta X.Y.Z -> manifest-stable.json, GitHub "latest"
#   bin/release.sh --revoke X.Y.Z [--dry-run]          add X.Y.Z to `revoked` in both manifests, re-sign
#   bin/release.sh --resign [--dry-run]                re-sign both manifests (new issued/expires, +60 days)
#
# --dry-run: no gh, no git tag/push/commit; everything goes to dist/ and must
#   never be uploaded. The state goes to CMP_RELEASE_STATE, or to dist/state,
#   reseeded from release/ on every run. Keys come from CMP_SIGNING_KEY /
#   CMP_MANIFEST_KEY (self-checks use the public keys derived from them); using
#   the default (real) key paths needs --real-keys. --skip-checks (only with
#   --dry-run, never with --real-keys) skips the version match, the tests and
#   e2e. CMP_CHANGELOG overrides CHANGELOG.md in --dry-run.
# Real path (Sergiu only, on main): refuses unless RESCUE_PUBLIC_KEY is a real
#   key (32 bytes, distinct from the other two), verifies with the keys baked
#   into includes/modules/class-updater.php and commits the new release/ state
#   ("chore(release): ..."); the files to upload are the committed release/
#   manifest-*.json + .json.sig. CMP_RELEASE_PREFLIGHT_ONLY=1 stops right after
#   the key preflight (tests; only then is CMP_TEST_RESCUE_KEY honoured).
# Public pages (B8b): after the state is saved, bin/build-site.sh builds the
#   static pages of consent.rt.md: release/site on the real path (committed with
#   the state, uploaded with scp -r), dist/site in --dry-run (never uploaded). A
#   release (X.Y.Z) also writes the CycloneDX SBOM dist/consent-manager-pro-X.Y.Z.cdx.json
#   and attaches it to the GitHub release.
#   Before the tag/push/gh step a preflight site build (dist/site-preflight, not
#   uploaded) runs with this run's stable manifest, so a broken site build stops
#   the script while nothing is published yet.
# Secret keys are never printed and never written outside their key files.
#
# Recovery: if `gh release create` failed after the tag was pushed, fix the
#   cause (gh auth, network) and rerun the same `bin/release.sh X.Y.Z`: the
#   existing tag is reused when it points at HEAD (release/ is only committed at
#   the end, so the tree is still clean). If gh left a half-made release, delete
#   it first (`gh release delete vX.Y.Z --repo <REPO>`, keep the tag). A tag
#   pointing elsewhere stops the script; never move a published tag.
#   If `gh release create` succeeded but the chore(release) commit/push failed,
#   do not rerun the release: the new state is already in release/, so commit
#   and push it by hand, then upload as printed (scp of release/manifest-*):
#     git add release && git commit -m "chore(release): vX.Y.Z (beta)" && git push
#   If the site build fails after `gh release create` succeeded (the preflight
#   build makes this unlikely), do not rerun the release: fix the cause, then run
#   `CMP_STABLE_MANIFEST=release/manifest-stable.json bin/build-site.sh --out release/site`
#   after committing the state by hand as above.
set -euo pipefail

REPO="rtdesignstudio/consent-manager-pro"
SLUG="consent-manager-pro"
TESTED_WP="6.9"
REQUIRES_WP="6.0"
REQUIRES_PHP="7.4"
# SSH host and directory on consent.rt.md that serves manifest-*.json (fill in; not assumed).
SERVER_HOST="${CMP_SERVER_HOST:-<HOST-SSH>}"
SERVER_DIR="${CMP_SERVER_DIR:-<DIRECTOR-CONSENT.RT.MD>}"

usage() {
	echo "Usage: bin/release.sh X.Y.Z [--dry-run [--skip-checks]]" >&2
	echo "       bin/release.sh --promote X.Y.Z [--dry-run]" >&2
	echo "       bin/release.sh --revoke X.Y.Z [--dry-run]" >&2
	echo "       bin/release.sh --resign [--dry-run]" >&2
	echo "       --dry-run with the default key paths also needs --real-keys" >&2
	exit 1
}
die() { echo "ERROR: $*" >&2; exit 1; }

# ---- Arguments -------------------------------------------------------------
CMD=""
VERSION=""
DRY=0
SKIP=0
REAL_KEYS=0
while [ $# -gt 0 ]; do
	case "$1" in
		--promote|--revoke)
			[ -z "$CMD" ] && [ $# -ge 2 ] || usage
			CMD="${1#--}"; VERSION="$2"; shift 2 ;;
		--resign)
			[ -z "$CMD" ] || usage
			CMD="resign"; shift ;;
		--dry-run) DRY=1; shift ;;
		--skip-checks) SKIP=1; shift ;;
		--real-keys) REAL_KEYS=1; shift ;;
		-*) usage ;;
		*)
			[ -z "$CMD" ] || usage
			CMD="release"; VERSION="$1"; shift ;;
	esac
done
[ -n "$CMD" ] || usage
if [ "$CMD" != "resign" ]; then
	echo "$VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$' || die "invalid version '$VERSION' (expected X.Y.Z or X.Y.Z-suffix)"
fi
if [ "$SKIP" = 1 ]; then
	[ "$DRY" = 1 ] || die "--skip-checks is only allowed together with --dry-run"
	[ "$CMD" = "release" ] || die "--skip-checks only applies to a release (X.Y.Z)"
	[ "$REAL_KEYS" = 0 ] || die "--real-keys cannot be combined with --skip-checks: real keys only sign a build that passed the checks."
fi

cd "$(dirname "$0")/.."
UPDATER="includes/modules/class-updater.php"
# Value of a string constant in the updater ('single' or "double" quotes).
const_of() { sed -n "s/.*const $1 *= *['\"]\([^'\"]*\)['\"].*/\1/p" "$UPDATER" | head -n 1; }

SIGNING_KEY="${CMP_SIGNING_KEY:-$HOME/.config/cmp-release/signing.key}"
MANIFEST_KEY="${CMP_MANIFEST_KEY:-$HOME/.config/cmp-release/manifest.key}"
UPLOAD="dist/upload"

# ---- Preflight (real path) --------------------------------------------------
if [ "$DRY" = 0 ]; then
	[ "$REAL_KEYS" = 0 ] || die "--real-keys only applies to --dry-run"
	MANIFEST_CONST="$(const_of MANIFEST_PUBLIC_KEY)"
	SIGN_CONST="$(const_of SIGN_PUBLIC_KEY)"
	RESCUE_CONST="$(const_of RESCUE_PUBLIC_KEY)"
	if [ "${CMP_RELEASE_PREFLIGHT_ONLY:-}" = "1" ] && [ -n "${CMP_TEST_RESCUE_KEY+x}" ]; then
		RESCUE_CONST="$CMP_TEST_RESCUE_KEY"
	fi
	# 0 = all three are valid 32-byte keys and pairwise distinct; 2 = bad rescue key.
	KEYCHECK=0
	php -r '
		$k = array();
		foreach ( array( 1, 2, 3 ) as $i ) {
			$d = base64_decode( (string) $argv[ $i ], true );
			$k[ $i ] = ( false !== $d && 32 === strlen( $d ) ) ? $d : null;
		}
		if ( null === $k[2] || null === $k[3] ) { exit( 3 ); }
		if ( null === $k[1] || $k[1] === $k[2] || $k[1] === $k[3] ) { exit( 2 ); }
		exit( $k[2] === $k[3] ? 3 : 0 );
	' "$RESCUE_CONST" "$MANIFEST_CONST" "$SIGN_CONST" || KEYCHECK=$?
	[ "$KEYCHECK" != 2 ] || die "RESCUE_PUBLIC_KEY in $UPDATER is not a usable key (placeholder, empty, not base64 of 32 bytes, or equal to MANIFEST/SIGN key); refusing to publish. Put the rescue public key in first."
	[ "$KEYCHECK" = 0 ] || die "MANIFEST_PUBLIC_KEY / SIGN_PUBLIC_KEY in $UPDATER are not two distinct 32-byte keys."
	[ -z "${CMP_RELEASE_STATE:-}" ] || [ "$CMP_RELEASE_STATE" = "release" ] || die "CMP_RELEASE_STATE is for --dry-run only; the real state lives in release/."
	[ -z "${CMP_CHANGELOG:-}" ] || die "CMP_CHANGELOG is for --dry-run only; a real release reads CHANGELOG.md."
	if [ "${CMP_RELEASE_PREFLIGHT_ONLY:-}" = "1" ]; then
		echo "Preflight OK."; exit 0
	fi
	STATE="release"
	CHANGELOG="CHANGELOG.md"
	STRICT_DATE=1
else
	CHANGELOG="${CMP_CHANGELOG:-CHANGELOG.md}"
	STRICT_DATE=0
	if [ -n "${CMP_RELEASE_STATE:-}" ]; then
		STATE="$CMP_RELEASE_STATE"
		case "$(cd "$STATE" 2>/dev/null && pwd -P)" in
			"$(pwd -P)/release") echo "ERROR: --dry-run must not write the real release/ state."; exit 1 ;;
		esac
	else
		# Fresh copy of the committed state on every run (never a stale dry-run).
		STATE="dist/state"
		rm -rf "$STATE"
		mkdir -p "$STATE"
		cp release/manifest-*.json release/manifest-*.json.sig "$STATE/" 2>/dev/null || true
	fi
	mkdir -p "$STATE"
	if [ "$REAL_KEYS" = 0 ]; then
		[ -n "${CMP_MANIFEST_KEY:-}" ] || die "--dry-run would sign with the real manifest key ($MANIFEST_KEY): set CMP_MANIFEST_KEY to a test key, or pass --real-keys."
		if [ "$CMD" = "release" ]; then
			[ -n "${CMP_SIGNING_KEY:-}" ] || die "--dry-run would sign with the real release key ($SIGNING_KEY): set CMP_SIGNING_KEY to a test key, or pass --real-keys."
		fi
	fi
fi

[ -r "$MANIFEST_KEY" ] || die "manifest key not found at $MANIFEST_KEY"
if [ "$CMD" = "release" ]; then
	[ -r "$SIGNING_KEY" ] || die "signing key not found at $SIGNING_KEY"
fi

# ---- Manifest tool (PHP; reads/writes JSON, signs with sodium) --------------
read -r -d '' PHP_TOOL <<'PHP' || true
function fail( $msg ) { fwrite( STDERR, "ERROR: " . $msg . "\n" ); exit( 1 ); }

function secret_key( $file ) {
	$sk = base64_decode( trim( (string) @file_get_contents( $file ) ), true );
	if ( false === $sk || 64 !== strlen( $sk ) ) { fail( "invalid secret key in " . $file ); }
	return $sk;
}

function load_state( $dir, $ch ) {
	$f = $dir . "/manifest-" . $ch . ".json";
	if ( ! is_file( $f ) ) { return null; }
	$m = json_decode( (string) file_get_contents( $f ), true );
	if ( ! is_array( $m ) || ! isset( $m["version"], $m["issued"] ) ) { fail( "unreadable state file " . $f ); }
	return $m;
}

function revoked_union( array $ms ) {
	$all = array();
	foreach ( $ms as $m ) {
		if ( is_array( $m ) && isset( $m["revoked"] ) && is_array( $m["revoked"] ) ) {
			foreach ( $m["revoked"] as $v ) { if ( is_string( $v ) ) { $all[] = $v; } }
		}
	}
	$all = array_values( array_unique( $all ) );
	usort( $all, "version_compare" );
	return $all;
}

/** issued: now, but always strictly after every manifest already written. */
function next_issued( array $ms ) {
	$t = time();
	foreach ( $ms as $m ) {
		if ( is_array( $m ) ) {
			$prev = strtotime( (string) $m["issued"] );
			if ( false !== $prev && $prev >= $t ) { $t = $prev + 1; }
		}
	}
	return $t;
}

function plain( $s ) {
	$s = preg_replace( "/\[([^\]]*)\]\([^)]*\)/u", "$1", $s );
	$s = str_replace( array( "**", "__", "`" ), "", $s );
	return trim( preg_replace( "/\s+/u", " ", $s ) );
}

/** Last 5 CHANGELOG sections up to and including $version, newest first. */
function notes( $file, $version, $strict ) {
	if ( ! is_readable( $file ) ) { fail( "CHANGELOG not found at " . $file ); }
	$sections = array();
	$cur      = null;
	foreach ( preg_split( "/\r\n|\n|\r/", (string) file_get_contents( $file ) ) as $line ) {
		if ( preg_match( "/^##\s+v?([0-9][0-9A-Za-z.+-]*)\s+(?:\x{2013}|-)\s+(.+?)\s*$/u", $line, $mm ) ) {
			$cur              = $mm[1];
			$sections[ $cur ] = array( "version" => $cur, "date" => $mm[2], "items" => array() );
			continue;
		}
		if ( preg_match( "/^#/", $line ) ) { $cur = null; continue; }
		if ( null === $cur ) { continue; }
		if ( preg_match( "/^[-*]\s+(.*)$/u", $line, $mm ) ) {
			$sections[ $cur ]["items"][] = $mm[1];
		} elseif ( preg_match( "/^\s+\S/u", $line ) && $sections[ $cur ]["items"] ) {
			$sections[ $cur ]["items"][ count( $sections[ $cur ]["items"] ) - 1 ] .= " " . trim( $line );
		}
	}
	if ( ! isset( $sections[ $version ] ) ) {
		fail( "CHANGELOG has no section \"## " . $version . " \xE2\x80\x93 YYYY-MM-DD\" (" . $file . "); add it before releasing." );
	}
	if ( $strict && ! ( preg_match( "/^(\d{4})-(\d{2})-(\d{2})$/", $sections[ $version ]["date"], $dm ) && checkdate( (int) $dm[2], (int) $dm[3], (int) $dm[1] ) ) ) {
		fail( "CHANGELOG section " . $version . " has date \"" . $sections[ $version ]["date"] . "\"; set the release date (YYYY-MM-DD) first." );
	}
	if ( ! $sections[ $version ]["items"] ) { fail( "CHANGELOG section " . $version . " has no \"- \" items." ); }
	$list = array();
	foreach ( $sections as $v => $s ) {
		if ( version_compare( (string) $v, $version, "<=" ) ) {
			$s["version"] = (string) $v;
			$s["items"]   = array_values( array_filter( array_map( "plain", $s["items"] ), "strlen" ) );
			$list[]       = $s;
		}
	}
	usort( $list, function ( $a, $b ) { return version_compare( $b["version"], $a["version"] ); } );
	return array_slice( $list, 0, 5 );
}

/** Set channel/issued/expires, encode, sign, write to $upload. */
function write_manifest( array $m, $ch, $issued, $sk, $upload ) {
	$m["channel"] = $ch;
	$m["issued"]  = gmdate( "Y-m-d\TH:i:s\Z", $issued );
	$m["expires"] = gmdate( "Y-m-d\TH:i:s\Z", $issued + 60 * 86400 );
	$order = array( "format", "slug", "channel", "version", "package", "sha256", "signature", "requires_wp", "requires_php", "tested_wp", "released", "issued", "expires", "revoked", "notes" );
	$out   = array();
	foreach ( $order as $k ) { if ( array_key_exists( $k, $m ) ) { $out[ $k ] = $m[ $k ]; } }
	$json = json_encode( $out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	if ( false === $json ) { fail( "could not encode the " . $ch . " manifest" ); }
	$json .= "\n";
	if ( strlen( $json ) >= 65536 ) { fail( "the " . $ch . " manifest is over 64 KB" ); }
	$f = $upload . "/manifest-" . $ch . ".json";
	if ( false === file_put_contents( $f, $json ) ) { fail( "could not write " . $f ); }
	if ( false === file_put_contents( $f . ".sig", base64_encode( sodium_crypto_sign_detached( $json, $sk ) ) . "\n" ) ) { fail( "could not write " . $f . ".sig" ); }
	echo "Wrote " . $f . " (" . $m["version"] . ", issued " . $out["issued"] . ")\n";
}

$a  = $argv;
$op = isset( $a[1] ) ? $a[1] : "";

if ( "pubkey" === $op ) { // pubkey <secret key file>: the matching public key.
	echo base64_encode( sodium_crypto_sign_publickey_from_secretkey( secret_key( $a[2] ) ) ), "\n";
	exit( 0 );
}
if ( "sbom" === $op ) { // sbom <version> <zip> <standalone cmp.js> <services.json> <out>
	$b    = random_bytes( 16 );
	$b[6] = chr( ( ord( $b[6] ) & 0x0f ) | 0x40 );
	$b[8] = chr( ( ord( $b[8] ) & 0x3f ) | 0x80 );
	$h    = bin2hex( $b );
	$uuid = substr( $h, 0, 8 ) . "-" . substr( $h, 8, 4 ) . "-" . substr( $h, 12, 4 ) . "-" . substr( $h, 16, 4 ) . "-" . substr( $h, 20 );
	$hash = function ( $f ) {
		$x = hash_file( "sha256", $f );
		if ( false === $x ) { fail( "cannot hash " . $f ); }
		return array( array( "alg" => "SHA-256", "content" => $x ) );
	};
	$bom  = array(
		"bomFormat"    => "CycloneDX",
		"specVersion"  => "1.5",
		"serialNumber" => "urn:uuid:" . $uuid,
		"version"      => 1,
		"metadata"     => array(
			"timestamp" => gmdate( "Y-m-d\TH:i:s\Z" ),
			"component" => array(
				"type"     => "application",
				"name"     => "consent-manager-pro",
				"version"  => $a[2],
				"licenses" => array( array( "license" => array( "id" => "GPL-2.0-or-later" ) ) ),
				"purl"     => "pkg:github/rtdesignstudio/consent-manager-pro@v" . $a[2],
				"hashes"   => $hash( $a[3] ),
			),
		),
		"components"   => array(
			array( "type" => "file", "name" => "standalone/cmp.js", "version" => $a[2], "hashes" => $hash( $a[4] ) ),
			array( "type" => "data", "name" => "data/services.json", "version" => $a[2], "hashes" => $hash( $a[5] ) ),
		),
	);
	$json = json_encode( $bom, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	if ( false === $json || false === file_put_contents( $a[6], $json . "\n" ) ) { fail( "could not write the SBOM " . $a[6] ); }
	echo "Wrote " . $a[6] . "\n";
	exit( 0 );
}
if ( "notes" === $op ) { // notes <changelog> <version> <strict>
	notes( $a[2], $a[3], "1" === $a[4] );
	exit( 0 );
}
if ( "verify" === $op ) { // verify <updater.php> <upload> <manifest pub> <sign pub> <zip|-> <channel>...
	require $a[2];
	foreach ( array_slice( $a, 7 ) as $ch ) {
		$f = $a[3] . "/manifest-" . $ch . ".json";
		$r = CMP_Updater::evaluate_manifest( (string) file_get_contents( $f ), (string) file_get_contents( $f . ".sig" ), $ch, array( "version" => "", "now" => time() ), array( $a[4] ) );
		if ( "ok" !== $r["status"] ) { fail( $f . " does not verify against the manifest public key (" . $r["status"] . ")" ); }
		if ( "-" !== $a[6] && ! CMP_Updater::verify_package( $a[6], $r["manifest"], array( $a[5] ) ) ) {
			fail( $a[6] . " does not match the " . $ch . " manifest (sha256 or release-key signature)" );
		}
		echo "Verified " . $f . "\n";
	}
	exit( 0 );
}

// Writing ops: <op> <state> <upload> <manifest key> ...
$state  = $a[2];
$upload = $a[3];
$sk     = secret_key( $a[4] );
$beta   = load_state( $state, "beta" );
$stable = load_state( $state, "stable" );
$issued = next_issued( array( $beta, $stable ) );
$revoked = revoked_union( array( $beta, $stable ) );

if ( "release" === $op ) { // release ... <changelog> <strict> <version> <zip> <zip.sig> <package url> <req wp> <req php> <tested wp>
	$version = $a[7];
	if ( in_array( $version, $revoked, true ) ) { fail( $version . " is revoked; release a new version number." ); }
	foreach ( array( "beta" => $beta, "stable" => $stable ) as $ch => $m ) {
		if ( $m && version_compare( $version, $m["version"], "<=" ) ) { fail( $version . " is not newer than " . $m["version"] . " in the " . $ch . " manifest." ); }
	}
	$notes = notes( $a[5], $version, "1" === $a[6] );
	$m     = array(
		"format"       => 1,
		"slug"         => "consent-manager-pro",
		"version"      => $version,
		"package"      => $a[10],
		"sha256"       => hash_file( "sha256", $a[8] ),
		"signature"    => trim( (string) file_get_contents( $a[9] ) ),
		"requires_wp"  => $a[11],
		"requires_php" => $a[12],
		"tested_wp"    => $a[13],
		"released"     => gmdate( "Y-m-d\TH:i:s\Z", $issued ),
		"revoked"      => $revoked,
		"notes"        => $notes,
	);
	write_manifest( $m, "beta", $issued, $sk, $upload );
	exit( 0 );
}
if ( "promote" === $op ) { // promote ... <version>
	$version = $a[5];
	if ( ! $beta || $beta["version"] !== $version ) { fail( "can only promote the current beta version (" . ( $beta ? $beta["version"] : "none" ) . "), not " . $version . "." ); }
	if ( in_array( $version, $revoked, true ) ) { fail( $version . " is revoked; it cannot be promoted." ); }
	if ( $stable && version_compare( $version, $stable["version"], "<=" ) ) { fail( $version . " is not newer than stable " . $stable["version"] . "." ); }
	$m            = $beta;
	$m["revoked"] = $revoked;
	write_manifest( $m, "stable", $issued, $sk, $upload );
	exit( 0 );
}
if ( "revoke" === $op ) { // revoke ... <version>
	$version = $a[5];
	if ( ! $beta && ! $stable ) { fail( "no manifests in " . $state . "; nothing to revoke." ); }
	if ( $stable && $stable["version"] === $version ) {
		$fix = $beta && version_compare( $beta["version"], $version, ">" ) && ! in_array( $beta["version"], $revoked, true );
		if ( ! $fix ) { fail( $version . " is the stable version and there is no newer beta: release and promote the fix first." ); }
	}
	$revoked[] = $version;
	$revoked   = revoked_union( array( array( "revoked" => $revoked ) ) );
	foreach ( array( "beta" => $beta, "stable" => $stable ) as $ch => $m ) {
		if ( $m ) {
			$m["revoked"] = $revoked;
			write_manifest( $m, $ch, $issued, $sk, $upload );
		}
	}
	exit( 0 );
}
if ( "resign" === $op ) {
	if ( ! $beta && ! $stable ) { fail( "no manifests in " . $state . "; nothing to re-sign." ); }
	foreach ( array( "beta" => $beta, "stable" => $stable ) as $ch => $m ) {
		if ( $m ) {
			$m["revoked"] = $revoked;
			write_manifest( $m, $ch, $issued, $sk, $upload );
		}
	}
	exit( 0 );
}
fail( "unknown operation " . $op );
PHP
mtool() { php -r "$PHP_TOOL" -- "$@"; }

# Public keys for the self-checks: derived from the env keys in --dry-run,
# baked into the plugin on the real path (so a key mismatch never ships).
if [ "$DRY" = 1 ]; then
	MANIFEST_PUB="$(mtool pubkey "$MANIFEST_KEY")"
	SIGN_PUB=""
	[ "$CMD" != "release" ] || SIGN_PUB="$(mtool pubkey "$SIGNING_KEY")"
else
	MANIFEST_PUB="$MANIFEST_CONST"
	SIGN_PUB="$SIGN_CONST"
fi

# ---- Real path: branch, clean tree ------------------------------------------
if [ "$DRY" = 0 ]; then
	[ "$(git rev-parse --abbrev-ref HEAD)" = "main" ] || die "not on main (on '$(git rev-parse --abbrev-ref HEAD)'); releases are made from main only."
	[ -z "$(git status --porcelain)" ] || die "working tree not clean. Commit or stash first."
fi

# ---- Release: checks, build, sign -------------------------------------------
if [ "$CMD" = "release" ]; then
	if [ "$SKIP" = 0 ]; then
		HEADER_VERSION="$(grep -m1 '^ \* Version:' "$SLUG.php" | awk '{print $3}')"
		[ "$HEADER_VERSION" = "$VERSION" ] || die "plugin header Version is '$HEADER_VERSION', expected '$VERSION'. Bump it (and CMP_VERSION) first."
		grep -q "define( 'CMP_VERSION', '$VERSION' )" "$SLUG.php" || die "CMP_VERSION constant does not match '$VERSION'."
		STABLE_TAG="$(grep -m1 '^Stable tag:' readme.txt | sed 's/^Stable tag:[[:space:]]*//' | tr -d '[:space:]')"
		[ "$STABLE_TAG" = "$VERSION" ] || die "readme.txt Stable tag is '$STABLE_TAG', expected '$VERSION'. Update it first."
	fi

	# Fail early (before tests and the build) when the CHANGELOG section is missing.
	mtool notes "$CHANGELOG" "$VERSION" "$STRICT_DATE"

	if [ "$SKIP" = 0 ]; then
		# Tests (includes the minified-asset freshness check and size budget).
		TEST_LOG="$(mktemp -t cmp-release-tests)"
		if ! php tests/run.php >"$TEST_LOG" 2>&1; then
			tail -20 "$TEST_LOG"
			die "tests failed (run bash bin/build-assets.sh if assets are stale)."
		fi
		if ! node standalone/tests/run.js >"$TEST_LOG" 2>&1; then
			tail -20 "$TEST_LOG"
			die "standalone tests failed."
		fi
		echo "Tests passed."
		# Browser test (B7.1): no tracker may load before consent.
		bash bin/e2e.sh || die "E2E failed: a tracker loads before consent (see report above)"
	fi

	# Clean zip (runtime files only; release/ state excluded, CHANGELOG.md included).
	rm -rf "dist/$SLUG" "dist/$SLUG.zip" "dist/$SLUG.zip.sig"
	mkdir -p "dist/$SLUG"
	rsync -a --exclude 'docs' --exclude 'tests' --exclude 'bin' --exclude 'dist' --exclude '/release' \
		--exclude 'proxy' --exclude 'package.json' --exclude 'package-lock.json' --exclude 'node_modules' --exclude '.superpowers' --exclude '.git*' --exclude '.DS_Store' \
		./ "dist/$SLUG/"
	( cd dist && zip -qr "$SLUG.zip" "$SLUG" )
	echo "Built dist/$SLUG.zip"

	# Detached Ed25519 signature of the zip with the release key (also uploaded
	# as consent-manager-pro.zip.sig, which the old relay still needs).
	php -r '
		$sk  = base64_decode( trim( file_get_contents( $argv[1] ) ), true );
		$zip = file_get_contents( $argv[2] );
		if ( false === $sk || 64 !== strlen( $sk ) ) { fwrite( STDERR, "ERROR: invalid signing key\n" ); exit( 1 ); }
		if ( false === file_put_contents( $argv[2] . ".sig", base64_encode( sodium_crypto_sign_detached( $zip, $sk ) ) . "\n" ) ) { fwrite( STDERR, "ERROR: could not write the zip signature\n" ); exit( 1 ); }
	' "$SIGNING_KEY" "dist/$SLUG.zip"
	echo "Signed dist/$SLUG.zip.sig"

	# CycloneDX SBOM (hashes of the zip, standalone/cmp.js and the services library).
	SBOM="dist/$SLUG-$VERSION.cdx.json"
	mtool sbom "$VERSION" "dist/$SLUG.zip" standalone/cmp.js data/services.json "$SBOM"
fi

# ---- Manifests -> dist/upload, then verify with the plugin's own code -------
rm -rf "$UPLOAD"
mkdir -p "$UPLOAD"
case "$CMD" in
	release)
		mtool release "$STATE" "$UPLOAD" "$MANIFEST_KEY" "$CHANGELOG" "$STRICT_DATE" "$VERSION" \
			"dist/$SLUG.zip" "dist/$SLUG.zip.sig" \
			"https://github.com/$REPO/releases/download/v$VERSION/$SLUG.zip" \
			"$REQUIRES_WP" "$REQUIRES_PHP" "$TESTED_WP"
		mtool verify "$UPDATER" "$UPLOAD" "$MANIFEST_PUB" "$SIGN_PUB" "dist/$SLUG.zip" beta ;;
	promote)
		mtool promote "$STATE" "$UPLOAD" "$MANIFEST_KEY" "$VERSION"
		mtool verify "$UPDATER" "$UPLOAD" "$MANIFEST_PUB" "-" "-" stable ;;
	revoke|resign)
		if [ "$CMD" = "revoke" ]; then
			mtool revoke "$STATE" "$UPLOAD" "$MANIFEST_KEY" "$VERSION"
		else
			mtool resign "$STATE" "$UPLOAD" "$MANIFEST_KEY"
		fi
		CHANNELS=""
		[ ! -f "$UPLOAD/manifest-beta.json" ] || CHANNELS="beta"
		[ ! -f "$UPLOAD/manifest-stable.json" ] || CHANNELS="$CHANNELS stable"
		# shellcheck disable=SC2086
		mtool verify "$UPDATER" "$UPLOAD" "$MANIFEST_PUB" "-" "-" $CHANNELS ;;
esac

save_state() {
	mkdir -p "$STATE"
	cp "$UPLOAD"/manifest-*.json "$UPLOAD"/manifest-*.json.sig "$STATE/"
}

# Real path only: upload the committed, real-key-signed state in release/.
print_upload() {
	FILES=""
	for f in release/manifest-beta.json release/manifest-beta.json.sig release/manifest-stable.json release/manifest-stable.json.sig; do
		[ ! -f "$f" ] || FILES="$FILES $f"
	done
	echo
	echo "Rulează pe Mac:"
	echo "  scp$FILES $SERVER_HOST:$SERVER_DIR/"
	echo "  scp -r release/site/. $SERVER_HOST:$SERVER_DIR/"
}

# Public pages. Real path: release/site from the committed state (CHANGELOG.md).
# Dry run: dist/site from the dry-run state and changelog; the path is not shown.
build_site() {
	if [ "$DRY" = 1 ]; then
		CMP_CHANGELOG="$CHANGELOG" CMP_STABLE_MANIFEST="$STATE/manifest-stable.json" bin/build-site.sh --out dist/site >/dev/null
		echo "Built the public pages (dry run, not for upload)."
	else
		CMP_STABLE_MANIFEST=release/manifest-stable.json bin/build-site.sh --out release/site
	fi
}

if [ "$DRY" = 1 ]; then
	save_state
	build_site
	echo "Dry run: nothing tagged, pushed, committed or published. State in $STATE/, output in $UPLOAD/."
	echo "DRY RUN — nu urca aceste fișiere pe server (sunt doar pentru verificare)."
	exit 0
fi

# ---- Real path: preflight site build, then GitHub, state commit --------------
# Build the public pages once before anything irreversible (tag, push, gh), so a
# broken site build cannot leave a published release without its commit.
PREFLIGHT_MANIFEST="release/manifest-stable.json"
[ ! -f "$UPLOAD/manifest-stable.json" ] || PREFLIGHT_MANIFEST="$UPLOAD/manifest-stable.json"
CMP_STABLE_MANIFEST="$PREFLIGHT_MANIFEST" bin/build-site.sh --out dist/site-preflight >/dev/null || die "site build failed — nothing was published"

case "$CMD" in
	release)
		if git rev-parse -q --verify "refs/tags/v$VERSION" >/dev/null; then
			[ "$(git rev-list -n 1 "v$VERSION")" = "$(git rev-parse HEAD)" ] || die "tag v$VERSION already exists and does not point at HEAD; never move a published tag."
			echo "Tag v$VERSION already at HEAD; reusing it."
		else
			git tag "v$VERSION"
		fi
		git push -q origin main --tags
		gh release create "v$VERSION" "dist/$SLUG.zip" "dist/$SLUG.zip.sig" "dist/$SLUG-$VERSION.cdx.json" --repo "$REPO" \
			--title "v$VERSION" --generate-notes --prerelease
		MSG="chore(release): v$VERSION (beta)" ;;
	promote)
		gh release edit "v$VERSION" --repo "$REPO" --prerelease=false --latest
		MSG="chore(release): promote v$VERSION to stable" ;;
	revoke)
		MSG="chore(release): revoke v$VERSION" ;;
	resign)
		MSG="chore(release): re-sign manifests" ;;
esac

save_state
build_site
git add "$STATE"
git commit -q -m "$MSG"
git push -q origin main
echo "Done: $MSG"
print_upload
