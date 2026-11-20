#!/usr/bin/env bash
# Build the static public pages of consent.rt.md (B8b) from site/.
#
#   bin/build-site.sh [--out <dir>]      default: release/site
#
# Copies site/ to <dir> and fills in the generated parts:
#   changelog/index.html   <!-- CHANGELOG -->: every CHANGELOG.md version, escaped,
#                          with anchors #vX-Y-Z ("TBD" versions shown as unpublished);
#   changelog/feed.xml     RSS 2.0, the 20 newest dated versions;
#   security pages         <!-- KEYS --> (public keys + SHA-256 fingerprints, read
#                          from includes/modules/class-updater.php), {{SIGN_PUBLIC_KEY}},
#                          <!-- REVOKED --> (the `revoked` list of the stable manifest);
#   .well-known/security.txt from security.txt.in ({{EXPIRES}} = now + 180 days).
# The output is deterministic: the same inputs give the same files, except
# Expires (pin it with CMP_BUILD_NOW).
#
# Environment (tests):
#   CMP_CHANGELOG        the changelog to read (default CHANGELOG.md)
#   CMP_STABLE_MANIFEST  the stable manifest (default release/manifest-stable.json;
#                        a missing file means "no revoked versions")
#   CMP_BUILD_NOW        Unix timestamp used for Expires (default: now)
#
# <dir> is replaced as a whole. A non-empty <dir> that does not look like a
# previous build is refused, so a wrong --out never deletes anything else.
# Needs bash and PHP CLI 7.4+; no other dependencies.
set -euo pipefail

die() { echo "ERROR: $*" >&2; exit 1; }
usage() {
	echo "Usage: bin/build-site.sh [--out <dir>]" >&2
	exit 1
}

OUT=""
while [ $# -gt 0 ]; do
	case "$1" in
		--out)
			[ $# -ge 2 ] && [ -n "$2" ] || die "--out needs a directory"
			OUT="$2"
			shift 2
			;;
		-h|--help) usage ;;
		*) echo "ERROR: unknown argument '$1'" >&2; usage ;;
	esac
done

# Paths given by the caller are relative to the caller's directory.
abs() {
	case "$1" in
		/*) printf '%s\n' "$1" ;;
		*) printf '%s/%s\n' "$(pwd -P)" "$1" ;;
	esac
}
[ -z "$OUT" ] || OUT="$(abs "$OUT")"
CHANGELOG="$(abs "${CMP_CHANGELOG:-CHANGELOG.md}")"
[ -z "${CMP_STABLE_MANIFEST:-}" ] || MANIFEST="$(abs "$CMP_STABLE_MANIFEST")"

cd "$(dirname "$0")/.."
ROOT="$(pwd -P)"
[ -n "$OUT" ] || OUT="$ROOT/release/site"
[ -n "${CMP_CHANGELOG:-}" ] || CHANGELOG="$ROOT/CHANGELOG.md"
[ -n "${CMP_STABLE_MANIFEST:-}" ] || MANIFEST="$ROOT/release/manifest-stable.json"
NOW="${CMP_BUILD_NOW:-$(date +%s)}"

command -v php >/dev/null 2>&1 || die "php not found"
echo "$NOW" | grep -Eq '^[0-9]+$' || die "CMP_BUILD_NOW must be a Unix timestamp"
[ -d "$ROOT/site" ] || die "site/ not found"
[ -r "$CHANGELOG" ] || die "changelog not found at $CHANGELOG"
[ -r "$ROOT/includes/modules/class-updater.php" ] || die "includes/modules/class-updater.php not found"
# The output is uploaded next to the manifests: it must never carry one.
if [ -n "$(find "$ROOT/site" -name 'manifest-*' -print)" ]; then
	die "site/ must not contain manifest-* files"
fi

# ---- Where the output goes -------------------------------------------------
OUT="${OUT%/}"
[ -n "$OUT" ] || die "--out must not be /"
OUT_PARENT="$(dirname "$OUT")"
mkdir -p "$OUT_PARENT"
OUT="$(cd "$OUT_PARENT" && pwd -P)/$(basename "$OUT")"
case "$OUT" in
	"$ROOT" | "$ROOT/site" | "$ROOT/site/"* | "$ROOT/release" | "$HOME" | /) die "refusing to write the site into $OUT" ;;
esac
case "$ROOT/" in
	"$OUT/"*) die "refusing to write the site into $OUT (it contains the repository)" ;;
esac
if [ -L "$OUT" ]; then
	die "$OUT is a symlink; refusing to replace it"
fi
if [ -e "$OUT" ] && [ ! -d "$OUT" ]; then
	die "$OUT exists and is not a directory"
fi
if [ -d "$OUT" ] && [ -n "$(ls -A "$OUT")" ]; then
	# Only a previous build may be replaced.
	for f in index.html changelog/index.html securitate/index.html en/security/index.html; do
		[ -f "$OUT/$f" ] || die "$OUT is not empty and does not look like a previous site build; refusing to replace it"
	done
fi

TMP="$OUT_PARENT/.$(basename "$OUT").tmp.$$"
rm -rf "$TMP"
cleanup() { rm -rf "$TMP"; }
trap cleanup EXIT

mkdir -p "$TMP"
cp -R "$ROOT/site/." "$TMP/"
find "$TMP" -name '.DS_Store' -type f -delete

# ---- Generator (PHP 7.4+) --------------------------------------------------
read -r -d '' PHP_GEN <<'PHP' || true
function fail( $msg ) { fwrite( STDERR, "ERROR: " . $msg . "\n" ); exit( 1 ); }
function h( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8" ); }
function x( $s ) { return htmlspecialchars( (string) $s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8" ); }
function rd( $f ) {
	$c = @file_get_contents( $f );
	if ( false === $c ) { fail( "could not read " . $f ); }
	return $c;
}
function wr( $f, $c ) {
	if ( false === file_put_contents( $f, $c ) ) { fail( "could not write " . $f ); }
}

/* Markdown link [text](url) -> text (same as plain() in release.sh). */
function unlink_md( $s ) { return preg_replace( "/\[([^\]]*)\]\([^)]*\)/u", "$1", $s ); }

/* Plain text of a CHANGELOG item (the manifest notes format of release.sh). */
function plain( $s ) {
	$s = str_replace( array( "**", "__", "`" ), "", unlink_md( $s ) );
	return trim( preg_replace( "/\s+/u", " ", $s ) );
}

/* Escaped HTML of a CHANGELOG item: `code` and **bold** only, after escaping. */
function item_html( $s ) {
	$s = h( trim( preg_replace( "/\s+/u", " ", unlink_md( $s ) ) ) );
	$s = preg_replace( "/`([^`]+)`/u", "<code>$1</code>", $s );
	return preg_replace( "/\*\*([^*]+)\*\*/u", "<strong>$1</strong>", $s );
}

/* Same format as notes() in bin/release.sh: "## X.Y.Z – date" (en dash or hyphen), "- " items. */
function sections( $file ) {
	$sections = array();
	$cur      = null;
	foreach ( preg_split( "/\r\n|\n|\r/", rd( $file ) ) as $line ) {
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
	$list = array_values( $sections );
	usort( $list, function ( $a, $b ) { return version_compare( $b["version"], $a["version"] ); } );
	return $list;
}

function anchor( $version ) { return "v" . trim( preg_replace( "/[^0-9A-Za-z]+/", "-", $version ), "-" ); }

/* Timestamp (UTC midnight) of a valid YYYY-MM-DD date, else false (TBD and the like). */
function release_ts( $date ) {
	if ( ! preg_match( "/^(\d{4})-(\d{2})-(\d{2})$/", $date, $dm ) || ! checkdate( (int) $dm[2], (int) $dm[3], (int) $dm[1] ) ) {
		return false;
	}
	return gmmktime( 0, 0, 0, (int) $dm[2], (int) $dm[3], (int) $dm[1] );
}

function ro_date( $ts ) {
	$months = array( "ianuarie", "februarie", "martie", "aprilie", "mai", "iunie", "iulie", "august", "septembrie", "octombrie", "noiembrie", "decembrie" );
	return (int) gmdate( "j", $ts ) . " " . $months[ (int) gmdate( "n", $ts ) - 1 ] . " " . gmdate( "Y", $ts );
}

/* Replace a marker that must appear exactly once. */
function fill( $html, $marker, $with, $file ) {
	if ( 1 !== substr_count( $html, $marker ) ) { fail( $file . ": expected the marker " . $marker . " exactly once" ); }
	return str_replace( $marker, $with, $html );
}

list( , $dir, $changelog, $manifest, $now, $updater ) = $argv;
$now  = (int) $now;
$base = "https://consent.rt.md/";

// ---- Keys (from the plugin) ---------------------------------------------------
$src  = rd( $updater );
$keys = array();
foreach ( array( "SIGN_PUBLIC_KEY", "MANIFEST_PUBLIC_KEY", "RESCUE_PUBLIC_KEY" ) as $c ) {
	if ( ! preg_match( "/\bconst\s+" . $c . "\s*=\s*'([^']*)'\s*;/", $src, $m ) ) { fail( $c . " not found in " . $updater ); }
	$raw        = base64_decode( $m[1], true );
	$keys[ $c ] = array( "b64" => $m[1], "fp" => ( false !== $raw && 32 === strlen( $raw ) ) ? hash( "sha256", $raw ) : null );
}
foreach ( array( "SIGN_PUBLIC_KEY", "MANIFEST_PUBLIC_KEY" ) as $c ) {
	if ( null === $keys[ $c ]["fp"] ) { fail( $c . " in " . $updater . " is not a 32-byte base64 key" ); }
}

$txt = array(
	"ro" => array(
		"SIGN_PUBLIC_KEY"     => array( "Cheia de release", "Semnează pachetele zip." ),
		"MANIFEST_PUBLIC_KEY" => array( "Cheia de manifest", "Semnează manifestele de update." ),
		"RESCUE_PUBLIC_KEY"   => array( "Cheia de rezervă", "Păstrată offline. Poate semna și pachete, și manifeste, dacă una din celelalte chei trebuie înlocuită." ),
		"pub"                 => "Cheia publică (base64)",
		"fp"                  => "Amprenta SHA-256",
		"unset"               => "încă nesetată",
		"none"                => "Momentan niciuna.",
	),
	"en" => array(
		"SIGN_PUBLIC_KEY"     => array( "Release key", "Signs the zip packages." ),
		"MANIFEST_PUBLIC_KEY" => array( "Manifest key", "Signs the update manifests." ),
		"RESCUE_PUBLIC_KEY"   => array( "Rescue key", "Kept offline. It can sign both packages and manifests if one of the other keys has to be replaced." ),
		"pub"                 => "Public key (base64)",
		"fp"                  => "SHA-256 fingerprint",
		"unset"               => "not set yet",
		"none"                => "Currently none.",
	),
);

function keys_html( array $keys, array $t ) {
	$out = "";
	foreach ( $keys as $c => $k ) {
		$out .= "<div class=\"key\">\n<h3>" . h( $t[ $c ][0] ) . "</h3>\n<p>" . h( $t[ $c ][1] ) . "</p>\n<dl>\n";
		if ( null === $k["fp"] ) {
			$out .= "<dt>" . h( $t["pub"] ) . "</dt><dd>" . h( $t["unset"] ) . "</dd>\n";
		} else {
			$out .= "<dt>" . h( $t["pub"] ) . "</dt><dd><code>" . h( $k["b64"] ) . "</code></dd>\n";
			$out .= "<dt>" . h( $t["fp"] ) . "</dt><dd><code>" . h( $k["fp"] ) . "</code></dd>\n";
		}
		$out .= "</dl>\n</div>\n";
	}
	return rtrim( $out, "\n" );
}

// ---- Revoked versions (stable manifest) -------------------------------------------
$revoked = array();
if ( is_file( $manifest ) ) {
	$m = json_decode( rd( $manifest ), true );
	if ( ! is_array( $m ) ) { fail( "unreadable stable manifest " . $manifest ); }
	if ( isset( $m["revoked"] ) ) {
		if ( ! is_array( $m["revoked"] ) ) { fail( "the stable manifest has a non-list revoked field" ); }
		foreach ( $m["revoked"] as $v ) {
			if ( ! is_string( $v ) || ! preg_match( "/^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$/", $v ) ) { fail( "the stable manifest lists an invalid revoked version" ); }
			$revoked[] = $v;
		}
	}
	$revoked = array_values( array_unique( $revoked ) );
	usort( $revoked, function ( $a, $b ) { return version_compare( $b, $a ); } );
}

function revoked_html( array $revoked, array $t ) {
	if ( ! $revoked ) { return "<p id=\"revoked\">" . h( $t["none"] ) . "</p>"; }
	$out = "<ul id=\"revoked\">\n";
	foreach ( $revoked as $v ) { $out .= "<li><code>" . h( $v ) . "</code></li>\n"; }
	return $out . "</ul>";
}

foreach ( array( "securitate/index.html" => "ro", "en/security/index.html" => "en" ) as $f => $lang ) {
	$p    = $dir . "/" . $f;
	$html = rd( $p );
	$html = fill( $html, "<!-- KEYS -->", keys_html( $keys, $txt[ $lang ] ), $f );
	$html = fill( $html, "<!-- REVOKED -->", revoked_html( $revoked, $txt[ $lang ] ), $f );
	$html = str_replace( "{{SIGN_PUBLIC_KEY}}", h( $keys["SIGN_PUBLIC_KEY"]["b64"] ), $html );
	if ( false !== strpos( $html, "{{" ) ) { fail( $f . ": unknown {{...}} placeholder" ); }
	wr( $p, $html );
}

// ---- Changelog page and RSS ---------------------------------------------------------
$list = sections( $changelog );
if ( ! $list ) { fail( "no \"## X.Y.Z – date\" sections in " . $changelog ); }

$page = "";
foreach ( $list as $s ) {
	$id    = anchor( $s["version"] );
	$ts    = release_ts( $s["date"] );
	$date  = ( false === $ts ) ? "nepublicată" : "<time datetime=\"" . gmdate( "Y-m-d", $ts ) . "\">" . ro_date( $ts ) . "</time>";
	$page .= "<section class=\"version\" id=\"" . $id . "\">\n<h2><a href=\"#" . $id . "\">" . h( $s["version"] ) . "</a> <span class=\"date\">" . $date . "</span></h2>\n";
	if ( $s["items"] ) {
		$page .= "<ul>\n";
		foreach ( $s["items"] as $it ) { $page .= "<li>" . item_html( $it ) . "</li>\n"; }
		$page .= "</ul>\n";
	} else {
		$page .= "<p class=\"muted\">Fără note.</p>\n";
	}
	$page .= "</section>\n";
}
$p = $dir . "/changelog/index.html";
wr( $p, fill( rd( $p ), "<!-- CHANGELOG -->", rtrim( $page, "\n" ), "changelog/index.html" ) );

$items = "";
$n     = 0;
$last  = null;
foreach ( $list as $s ) {
	$ts = release_ts( $s["date"] );
	if ( false === $ts ) { continue; } // Unpublished (TBD): not in the feed.
	if ( ++$n > 20 ) { break; }
	if ( null === $last ) { $last = $ts; }
	$link = $base . "changelog/#" . anchor( $s["version"] );
	$desc = "<ul>";
	foreach ( $s["items"] as $it ) {
		$t = plain( $it );
		if ( "" !== $t ) { $desc .= "<li>" . h( $t ) . "</li>"; }
	}
	$desc  .= "</ul>";
	$items .= "<item>\n<title>" . x( "Consent Manager Pro " . $s["version"] ) . "</title>\n<link>" . x( $link ) . "</link>\n<guid isPermaLink=\"true\">" . x( $link ) . "</guid>\n<pubDate>" . gmdate( DATE_RSS, $ts ) . "</pubDate>\n<description>" . x( $desc ) . "</description>\n</item>\n";
}
$feed = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
	. "<rss version=\"2.0\" xmlns:atom=\"http://www.w3.org/2005/Atom\">\n<channel>\n"
	. "<title>Consent Manager Pro: changelog</title>\n"
	. "<link>" . $base . "changelog/</link>\n"
	. "<atom:link href=\"" . $base . "changelog/feed.xml\" rel=\"self\" type=\"application/rss+xml\"/>\n"
	. "<description>Ce s-a schimbat în fiecare versiune a Consent Manager Pro.</description>\n"
	. "<language>ro</language>\n"
	. ( null === $last ? "" : "<lastBuildDate>" . gmdate( DATE_RSS, $last ) . "</lastBuildDate>\n" )
	. $items
	. "</channel>\n</rss>\n";
$prev = libxml_use_internal_errors( true );
$ok   = false !== simplexml_load_string( $feed );
libxml_use_internal_errors( $prev );
if ( ! $ok ) { fail( "the generated feed.xml is not valid XML" ); }
wr( $dir . "/changelog/feed.xml", $feed );

// ---- security.txt (RFC 9116) -----------------------------------------------------
$tpl = $dir . "/.well-known/security.txt.in";
wr( $dir . "/.well-known/security.txt", fill( rd( $tpl ), "{{EXPIRES}}", gmdate( "Y-m-d\TH:i:s\Z", $now + 180 * 86400 ), "security.txt.in" ) );
if ( ! unlink( $tpl ) ) { fail( "could not remove " . $tpl ); }

// ---- Final checks -------------------------------------------------------------------
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	$name = $f->getFilename();
	if ( 0 === strpos( $name, "manifest-" ) ) { fail( "the output must not contain manifest-* files (" . $name . ")" ); }
	if ( preg_match( "/\.html$/", $name ) ) {
		$c = rd( $f->getPathname() );
		foreach ( array( "<!-- CHANGELOG -->", "<!-- KEYS -->", "<!-- REVOKED -->", "{{" ) as $mk ) {
			if ( false !== strpos( $c, $mk ) ) { fail( $f->getPathname() . " still contains " . $mk ); }
		}
	}
}
PHP

php -r "$PHP_GEN" -- "$TMP" "$CHANGELOG" "$MANIFEST" "$NOW" "$ROOT/includes/modules/class-updater.php"

rm -rf "$OUT"
mv "$TMP" "$OUT"
trap - EXIT
echo "Built the public pages in $OUT"
