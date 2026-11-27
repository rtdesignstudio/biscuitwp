<?php
/**
 * B8b: the static public pages in site/ and bin/build-site.sh (changelog, RSS,
 * key fingerprints, revoked versions, security.txt). The generator always runs
 * with --out in a temporary directory: release/ is never written.
 */
require_once __DIR__ . '/../includes/modules/class-updater.php';

/** Run a command in the plugin root with extra env vars; returns [exit code, stdout+stderr]. */
function cmp_site_run( $cmd, array $env ) {
	$full = getenv();
	foreach ( $env as $k => $v ) {
		if ( null === $v ) {
			unset( $full[ $k ] );
		} else {
			$full[ $k ] = (string) $v;
		}
	}
	$proc = proc_open( $cmd . ' 2>&1', array( 1 => array( 'pipe', 'w' ) ), $pipes, dirname( __DIR__ ), $full );
	if ( ! is_resource( $proc ) ) {
		return array( 255, 'proc_open failed' );
	}
	$out = stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	return array( proc_close( $proc ), (string) $out );
}

/** Every file under $dir (relative paths, sorted), dotfiles included. */
function cmp_site_files( $dir ) {
	$out = array();
	if ( ! is_dir( $dir ) ) {
		return $out;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		if ( $f->isFile() ) {
			$out[] = substr( $f->getPathname(), strlen( rtrim( $dir, '/' ) ) + 1 );
		}
	}
	sort( $out );
	return $out;
}

function cmp_site_rmdir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) as $f ) {
		if ( '.' === $f || '..' === $f ) {
			continue;
		}
		$p = $dir . '/' . $f;
		is_dir( $p ) && ! is_link( $p ) ? cmp_site_rmdir( $p ) : unlink( $p );
	}
	rmdir( $dir );
}

/** A fingerprint of release/ (names + contents), to prove the generator never touches it. */
function cmp_site_tree_hash( $dir ) {
	$h = '';
	foreach ( cmp_site_files( $dir ) as $f ) {
		$h .= $f . ':' . hash_file( 'sha256', $dir . '/' . $f ) . "\n";
	}
	return hash( 'sha256', $h );
}

/** The inner HTML of the element with id="$id" (first match, non-greedy up to its closing tag). */
function cmp_site_by_id( $html, $id ) {
	if ( preg_match( '#<(ul|p|table|div|tbody)\b[^>]*\bid="' . preg_quote( $id, '#' ) . '"[^>]*>(.*?)</\1>#s', $html, $m ) ) {
		return $m[2];
	}
	return '';
}

$cmp_site_root = dirname( __DIR__ );

if ( ! is_file( $cmp_site_root . '/bin/build-site.sh' ) ) {
	cmp_assert( false, 'site: bin/build-site.sh exists' );
}

$cmp_site_tmp = sys_get_temp_dir() . '/cmp-site-test-' . getmypid() . '-' . mt_rand();
mkdir( $cmp_site_tmp, 0700, true );
$cmp_site_release_before = cmp_site_tree_hash( $cmp_site_root . '/release' );
$cmp_site_release_site   = is_dir( $cmp_site_root . '/release/site' );

// ---- Inputs ------------------------------------------------------------------
$cmp_site_cl = $cmp_site_tmp . '/CHANGELOG.md';
file_put_contents(
	$cmp_site_cl,
	"# Changelog\n\nIntro text, not a version.\n\n"
	. "## 1.2.4 – TBD\n\n- Ce urmează.\n\n"
	. "## 1.2.3 – 2026-10-05\n\n- Text cu <b>&</b> și ]]> în el.\n- Al doilea punct cu `cod`,\n  pe două rânduri.\n\n"
	. "## 1.1.0 - 2026-09-01\n\n- Versiune veche.\n"
);
$cmp_site_now   = 1767225600; // 2026-01-01T00:00:00Z
$cmp_site_nomf  = $cmp_site_tmp . '/no-such-manifest.json';
$cmp_site_mf    = $cmp_site_tmp . '/manifest-stable.json';
file_put_contents( $cmp_site_mf, json_encode( array( 'format' => 1, 'channel' => 'stable', 'version' => '1.2.3', 'revoked' => array( '0.9.1' ) ) ) );
$cmp_site_beta  = $cmp_site_tmp . '/manifest-beta.json';
file_put_contents( $cmp_site_beta, json_encode( array( 'format' => 1, 'channel' => 'beta', 'version' => '1.2.0' ) ) );
$cmp_site_env   = array(
	'CMP_CHANGELOG'       => $cmp_site_cl,
	'CMP_STABLE_MANIFEST' => $cmp_site_nomf,
	'CMP_BETA_MANIFEST'   => $cmp_site_beta,
	'CMP_BUILD_NOW'       => (string) $cmp_site_now,
);
$cmp_site_out1 = $cmp_site_tmp . '/out1';
$cmp_site_out2 = $cmp_site_tmp . '/out2';
$cmp_site_out3 = $cmp_site_tmp . '/out3';

list( $rc, $out ) = cmp_site_run( 'bash bin/build-site.sh --out ' . escapeshellarg( $cmp_site_out1 ), $cmp_site_env );
cmp_assert_eq( $rc, 0, 'build-site: exits 0' . ( 0 === $rc ? '' : ' — ' . trim( $out ) ) );

$cmp_site_pages = array(
	'index.html'                  => 'ro',
	'en/index.html'               => 'en',
	'securitate/index.html'       => 'ro',
	'en/security/index.html'      => 'en',
	'confidentialitate/index.html' => 'ro',
	'en/privacy/index.html'       => 'en',
	'changelog/index.html'        => 'ro',
);
foreach ( array_merge( array_keys( $cmp_site_pages ), array( 'changelog/feed.xml', 'style.css', '.well-known/security.txt' ) ) as $f ) {
	cmp_assert( is_file( $cmp_site_out1 . '/' . $f ), "build-site: writes {$f}" );
}
cmp_assert( ! file_exists( $cmp_site_out1 . '/.well-known/security.txt.in' ), 'build-site: the security.txt template is not copied' );
foreach ( array( 'api/verifica.php', 'api/contor.php', 'api/.htaccess', 'api/lib/check.php', 'api/lib/signatures.php', 'api/lib/fetch.php', 'api/lib/ratelimit.php', 'api/lib/endpoint.php' ) as $f ) {
	cmp_assert( is_file( $cmp_site_out1 . '/' . $f ) && hash_file( 'sha256', $cmp_site_out1 . '/' . $f ) === hash_file( 'sha256', $cmp_site_root . '/site/' . $f ), "build-site: copies site/{$f} as is" );
}
cmp_assert( is_file( $cmp_site_out1 . '/api/services.json' ) && hash_file( 'sha256', $cmp_site_out1 . '/api/services.json' ) === hash_file( 'sha256', $cmp_site_root . '/data/services.json' ), 'build-site: api/services.json is data/services.json' );

$cmp_site_read = function ( $f ) use ( $cmp_site_out1 ) {
	$p = $cmp_site_out1 . '/' . $f;
	// Without the cache version of local CSS / JS (checked once below), so the tests read plain paths.
	return is_file( $p ) ? (string) preg_replace( '#(\.(?:css|js))\?v=[0-9a-f]{8}"#', '$1"', (string) file_get_contents( $p ) ) : '';
};
preg_match_all( '#(?:href|src)="(/[^":?]+\.(?:css|js))(\?v=([0-9a-f]{8}))?"#', (string) @file_get_contents( $cmp_site_out1 . '/demo/embed/index.html' ) . (string) @file_get_contents( $cmp_site_out1 . '/en/index.html' ), $cmp_site_vm, PREG_SET_ORDER );
cmp_assert( count( $cmp_site_vm ) >= 5, 'versions: local CSS and JS found' );
foreach ( $cmp_site_vm as $m ) {
	cmp_assert( isset( $m[3] ) && substr( hash_file( 'sha256', $cmp_site_out1 . $m[1] ), 0, 8 ) === $m[3], "versions: {$m[1]} carries the hash of its content" );
}

// ---- Changelog page ------------------------------------------------------------
$cl = $cmp_site_read( 'changelog/index.html' );
cmp_assert( false !== strpos( $cl, 'id="v1-2-3"' ), 'changelog: anchor v1-2-3' );
cmp_assert( false !== strpos( $cl, 'id="v1-2-4"' ), 'changelog: anchor v1-2-4 (TBD)' );
cmp_assert( false !== strpos( $cl, 'id="v1-1-0"' ), 'changelog: anchor v1-1-0 (hyphen heading)' );
cmp_assert( false !== strpos( $cl, '&lt;b&gt;&amp;&lt;/b&gt;' ), 'changelog: item text is escaped' );
cmp_assert( false === strpos( $cl, '<b>&</b>' ), 'changelog: no raw HTML from CHANGELOG' );
cmp_assert( false !== strpos( $cl, ']]&gt;' ), 'changelog: ]]> escaped' );
cmp_assert( false !== strpos( $cl, 'pe două rânduri' ), 'changelog: continuation lines are joined' );
cmp_assert( false === strpos( $cl, 'Intro text' ), 'changelog: intro paragraph is not a version' );
cmp_assert( 1 === preg_match( '#id="v1-2-4".*?nepublicată#su', $cl ), 'changelog: TBD version shown as „nepublicată”' );
cmp_assert( 1 === preg_match( '#id="v1-2-4".*id="v1-2-3".*id="v1-1-0"#s', $cl ), 'changelog: newest version first' );
cmp_assert( false === strpos( $cl, '<!-- CHANGELOG -->' ), 'changelog: marker replaced' );
cmp_assert( false !== strpos( $cl, 'feed.xml' ), 'changelog: links the RSS feed' );

// ---- RSS -------------------------------------------------------------------
$feed = $cmp_site_read( 'changelog/feed.xml' );
$prev = libxml_use_internal_errors( true );
$xml  = simplexml_load_string( $feed );
libxml_use_internal_errors( $prev );
cmp_assert( false !== $xml, 'feed: valid XML' );
if ( false !== $xml ) {
	cmp_assert_eq( (string) $xml['version'], '2.0', 'feed: RSS 2.0' );
	cmp_assert_eq( count( $xml->channel->item ), 2, 'feed: 2 items (TBD skipped)' );
	$it = $xml->channel->item[0];
	cmp_assert_eq( (string) $it->title, 'BiscuitWP 1.2.3', 'feed: newest item title' );
	cmp_assert_eq( (string) $it->link, 'https://biscuitwp.rt.md/changelog/#v1-2-3', 'feed: item link to the anchor' );
	cmp_assert( 1 === preg_match( '/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun), \d{2} (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) \d{4} \d{2}:\d{2}:\d{2} [+-]\d{4}$/', (string) $it->pubDate ), 'feed: pubDate is RFC 822' );
	cmp_assert_eq( strtotime( (string) $it->pubDate ), strtotime( '2026-10-05T00:00:00Z' ), 'feed: pubDate is the release date' );
	cmp_assert( false !== strpos( (string) $it->description, '&lt;b&gt;&amp;&lt;/b&gt;' ), 'feed: description keeps the item text escaped as HTML' );
	cmp_assert( false !== strpos( (string) $it->description, '<li>' ), 'feed: description is a list' );
	cmp_assert_eq( (string) $xml->channel->item[1]->title, 'BiscuitWP 1.1.0', 'feed: second item' );
}

// ---- security.txt -------------------------------------------------------------
$st = $cmp_site_read( '.well-known/security.txt' );
foreach ( array(
	'Contact: mailto:security@rt.md',
	'Contact: https://github.com/rtdesignstudio/biscuitwp/security/advisories/new',
	'Expires: ' . gmdate( 'Y-m-d\TH:i:s\Z', $cmp_site_now + 180 * 86400 ),
	'Preferred-Languages: ro, en, ru',
	'Canonical: https://biscuitwp.rt.md/.well-known/security.txt',
	'Policy: https://biscuitwp.rt.md/en/security/',
) as $line ) {
	cmp_assert( 1 === preg_match( '/^' . preg_quote( $line, '/' ) . '$/m', $st ), "security.txt: {$line}" );
}
cmp_assert_eq( preg_match_all( '/^Contact: /m', $st ), 2, 'security.txt: two Contact lines' );
cmp_assert_eq( preg_match_all( '/^Expires: /m', $st ), 1, 'security.txt: one Expires line' );
cmp_assert( false === strpos( $st, '{{' ), 'security.txt: no placeholders left' );

// ---- Security pages: keys and revoked ----------------------------------------------
$cmp_site_keys = array( CMP_Updater::SIGN_PUBLIC_KEY, CMP_Updater::MANIFEST_PUBLIC_KEY, CMP_Updater::RESCUE_PUBLIC_KEY );
foreach ( array( 'securitate/index.html' => array( 'încă nesetată', 'niciuna' ), 'en/security/index.html' => array( 'not set yet', 'none' ) ) as $page => $words ) {
	$html = $cmp_site_read( $page );
	foreach ( $cmp_site_keys as $k ) {
		$raw = base64_decode( $k, true );
		if ( false !== $raw && 32 === strlen( $raw ) ) {
			cmp_assert( false !== strpos( $html, $k ), "{$page}: shows public key {$k}" );
			cmp_assert( false !== strpos( $html, hash( 'sha256', $raw ) ), "{$page}: shows the SHA-256 fingerprint of {$k}" );
		} else {
			cmp_assert( false !== strpos( $html, $words[0] ), "{$page}: placeholder key shown as „{$words[0]}”" );
			cmp_assert( false === strpos( $html, $k ), "{$page}: placeholder text itself is not shown" );
		}
	}
	cmp_assert( false !== strpos( $html, CMP_Updater::SIGN_PUBLIC_KEY ), "{$page}: verification command uses the release key" );
	cmp_assert( false !== strpos( $html, 'sodium_crypto_sign_verify_detached' ), "{$page}: php -r verification command" );
	cmp_assert( false !== strpos( $html, 'shasum -a 256' ), "{$page}: shasum command" );
	cmp_assert( false !== strpos( cmp_site_by_id( $html, 'revoked' ), $words[1] ), "{$page}: no manifest -> revoked „{$words[1]}”" );
	foreach ( array( '<!-- KEYS -->', '<!-- REVOKED -->', '{{' ) as $marker ) {
		cmp_assert( false === strpos( $html, $marker ), "{$page}: marker {$marker} replaced" );
	}
}

list( $rc, $out ) = cmp_site_run( 'bash bin/build-site.sh --out ' . escapeshellarg( $cmp_site_out3 ), array_merge( $cmp_site_env, array( 'CMP_STABLE_MANIFEST' => $cmp_site_mf ) ) );
cmp_assert_eq( $rc, 0, 'build-site with a stable manifest: exits 0' . ( 0 === $rc ? '' : ' — ' . trim( $out ) ) );
foreach ( array( 'securitate/index.html' => 'niciuna', 'en/security/index.html' => 'none' ) as $page => $none ) {
	$rev = cmp_site_by_id( (string) @file_get_contents( $cmp_site_out3 . '/' . $page ), 'revoked' );
	cmp_assert( false !== strpos( $rev, '0.9.1' ), "{$page}: lists revoked 0.9.1 from the stable manifest" );
	cmp_assert( false === strpos( $rev, $none ), "{$page}: revoked list is not „{$none}” when versions are revoked" );
}

// Security pages: biscuitwp.zip (new installs) is checked by its own .sig; its hash is not in the manifest.
foreach ( array( 'securitate/index.html', 'en/security/index.html' ) as $page ) {
	$html = $cmp_site_read( $page );
	cmp_assert( false !== strpos( $html, '<code>biscuitwp.zip.sig</code>' ), "{$page}: explains verifying biscuitwp.zip with biscuitwp.zip.sig" );
	cmp_assert( 1 === preg_match( '#</ol>\s*<p>[^<]*<code>biscuitwp\.zip</code>#u', $html ), "{$page}: the biscuitwp.zip note follows the verification steps" );
}

// ---- Every page --------------------------------------------------------------------
foreach ( $cmp_site_pages as $page => $lang ) {
	$html = $cmp_site_read( $page );
	cmp_assert( false !== strpos( $html, '<html lang="' . $lang . '"' ), "{$page}: <html lang=\"{$lang}\">" );
	cmp_assert( 1 === preg_match( '#<link rel="stylesheet" href="[^"]*style\.css"#', $html ), "{$page}: links style.css" );
	cmp_assert( false !== strpos( $html, '<meta name="viewport"' ), "{$page}: viewport meta" );
	cmp_assert( false !== strpos( $html, 'https://github.com/rtdesignstudio/biscuitwp' ), "{$page}: links the GitHub repo" );
}

// ---- Shared header and footer, style assets (Task 1 of the landing) ---------------
// page => [ lang, current nav key, language switch: lang => target ]
$cmp_site_home  = array( 'ro' => '/', 'en' => '/en/' );
$cmp_site_sec   = array( 'ro' => '/securitate/', 'en' => '/en/security/' );
$cmp_site_priv  = array( 'ro' => '/confidentialitate/', 'en' => '/en/privacy/' );
$cmp_site_frame = array(
	'index.html'                   => array( 'ro', 'home', $cmp_site_home ),
	'en/index.html'                => array( 'en', 'home', $cmp_site_home ),
	'securitate/index.html'        => array( 'ro', 'security', $cmp_site_sec ),
	'en/security/index.html'       => array( 'en', 'security', $cmp_site_sec ),
	'confidentialitate/index.html' => array( 'ro', 'privacy', $cmp_site_priv ),
	'en/privacy/index.html'        => array( 'en', 'privacy', $cmp_site_priv ),
	'changelog/index.html'         => array( 'ro', 'changelog', array( 'ro' => '/changelog/', 'en' => '/en/' ) ),
);
$cmp_site_nav = array(
	'ro' => array( 'home' => '/', 'security' => '/securitate/', 'privacy' => '/confidentialitate/', 'changelog' => '/changelog/' ),
	'en' => array( 'home' => '/en/', 'security' => '/en/security/', 'privacy' => '/en/privacy/', 'changelog' => '/changelog/' ),
);
foreach ( $cmp_site_frame as $page => $fr ) {
	list( $lang, $cur, $alts ) = $fr;
	$src = (string) @file_get_contents( $cmp_site_root . '/site/' . $page );
	cmp_assert_eq( substr_count( $src, '<!-- HEADER' ), 1, "site/{$page}: one header marker" );
	cmp_assert_eq( substr_count( $src, '<!-- FOOTER' ), 1, "site/{$page}: one footer marker" );

	$html = $cmp_site_read( $page );
	cmp_assert( false === strpos( $html, '<!-- HEADER' ), "{$page}: header marker replaced" );
	cmp_assert( false === strpos( $html, '<!-- FOOTER' ), "{$page}: footer marker replaced" );
	cmp_assert( 1 === preg_match( '#<header class="site">(.*?)</header>#s', $html, $hm ), "{$page}: has the shared header" );
	cmp_assert( 1 === preg_match( '#<footer class="site">(.*?)</footer>#s', $html, $fm ), "{$page}: has the shared footer" );
	$head = isset( $hm[1] ) ? $hm[1] : '';
	$foot = isset( $fm[1] ) ? $fm[1] : '';
	foreach ( array( 'header' => $head, 'footer' => $foot ) as $where => $part ) {
		foreach ( array( 'security', 'privacy', 'changelog' ) as $k ) {
			cmp_assert( false !== strpos( $part, 'href="' . $cmp_site_nav[ $lang ][ $k ] . '"' ), "{$page}: {$where} links {$cmp_site_nav[ $lang ][ $k ]}" );
		}
		cmp_assert( false !== strpos( $part, 'https://github.com/rtdesignstudio/biscuitwp' ), "{$page}: {$where} links GitHub" );
	}
	cmp_assert( false !== strpos( $head, 'href="' . $cmp_site_nav[ $lang ]['home'] . '"' ), "{$page}: header links home" );
	foreach ( $alts as $al => $alt ) {
		$sw = '<a href="' . $alt . '" hreflang="' . $al . '" lang="' . $al . '">';
		if ( $al === $lang ) {
			cmp_assert( false === strpos( $head, 'hreflang="' . $al . '"' ) && false === strpos( $foot, 'hreflang="' . $al . '"' ), "{$page}: no switch to its own language" );
		} else {
			cmp_assert( false !== strpos( $head, $sw ) && false !== strpos( $foot, '<li>' . $sw ), "{$page}: header and footer switch to {$al} ({$alt})" );
		}
	}
	cmp_assert( 1 === preg_match( '#<a href="' . preg_quote( $cmp_site_nav[ $lang ][ $cur ], '#' ) . '"[^>]*aria-current="page"#', $head ), "{$page}: current page marked in the nav" );
	cmp_assert_eq( substr_count( $head, 'aria-current="page"' ), 1, "{$page}: exactly one current item" );
	cmp_assert( false !== strpos( $foot, 'href="https://rt.md/"' ) && false !== strpos( $foot, 'RT Design Studio' ), "{$page}: footer links RT Design Studio" );
	cmp_assert( false !== strpos( $foot, 'GPL-2.0-or-later' ), "{$page}: footer states the licence" );
	preg_match_all( '#<link rel="stylesheet" href="([^"]+)"#', $html, $css_links );
	cmp_assert_eq( array_map( 'basename', $css_links[1] ), array( 'style.css' ), "{$page}: one stylesheet, style.css (rt.md v2 tokens live there)" );
	cmp_assert( false !== strpos( $html, '<meta name="color-scheme" content="light">' ), "{$page}: light only, like rt.md" );
	if ( 'en' !== $lang ) {
		cmp_assert( false === strpos( $src, "\xe2\x80\x94" ), "site/{$page}: no em dash in {$lang} text" );
	}
}
foreach ( array( 'header.ro.html', 'footer.ro.html', 'header.en.html', 'footer.en.html' ) as $f ) {
	cmp_assert( is_file( $cmp_site_root . '/site/partials/' . $f ), "site/partials/{$f} exists" );
}
foreach ( array( 'partials/header.ro.html', 'partials/footer.ro.html', 'demo/index.html', 'demo/embed/index.html' ) as $f ) {
	cmp_assert( false === strpos( (string) @file_get_contents( $cmp_site_root . '/site/' . $f ), "\xe2\x80\x94" ), "site/{$f}: no em dash" );
}
cmp_assert( ! is_dir( $cmp_site_out1 . '/partials' ), 'build-site: partials are not copied to the output' );
foreach ( array( 'assets/tokens.css', 'assets/typography.css' ) as $f ) {
	cmp_assert( ! is_file( $cmp_site_root . '/site/' . $f ) && ! is_file( $cmp_site_out1 . '/' . $f ), "build-site: no old design-system file {$f}" );
}
foreach ( array( 'fonts/inter-tight/OFL.txt', 'fonts/inter-tight/inter-tight-normal-latin.woff2', 'fonts/inter-tight/inter-tight-normal-latin-ext.woff2', 'fonts/inter-tight/inter-tight-normal-cyrillic.woff2', 'fonts/inter-tight/inter-tight-normal-cyrillic-ext.woff2' ) as $f ) {
	cmp_assert( is_file( $cmp_site_out1 . '/' . $f ), "build-site: writes {$f}" );
}
$cmp_site_ofl = $cmp_site_read( 'fonts/inter-tight/OFL.txt' );
cmp_assert( false !== strpos( $cmp_site_ofl, 'SIL OPEN FONT LICENSE Version 1.1' ) && false !== strpos( $cmp_site_ofl, 'The Inter Project Authors' ), 'OFL.txt: SIL OFL 1.1 with the Inter Project Authors copyright' );
foreach ( array( $cmp_site_out1 => 'out', $cmp_site_root . '/site' => 'site' ) as $dir => $label ) {
	$css = (string) @file_get_contents( $dir . '/style.css' );
	cmp_assert( 1 === preg_match( '/@font-face/', $css ), "{$label}/style.css: declares @font-face" );
	preg_match_all( '/@font-face\s*\{[^}]*\}/s', $css, $ffs );
	foreach ( $ffs[0] as $ff ) {
		cmp_assert( 1 === preg_match( '/url\(\s*["\']?(?![a-z][a-z0-9+.-]*:|\/\/)[^)]*\.woff2/i', $ff ), "{$label}/style.css: @font-face loads a local woff2" );
	}
	cmp_assert( false === strpos( $css, 'prefers-color-scheme' ), "{$label}/style.css: no dark mode (rt.md is always light)" );
	cmp_assert( 1 === preg_match( '/color-scheme:\s*light\s*;/', $css ), "{$label}/style.css: color-scheme: light" );
	foreach ( array( '--ink: #0a0a0a', '--mute: #8a8a8a', '--line: #e6e6e6', '--bg: #fff', '--pad: clamp(16px, 4vw, 56px)' ) as $tok ) {
		cmp_assert( false !== strpos( $css, $tok ), "{$label}/style.css: rt.md v2 token {$tok}" );
	}
	cmp_assert( 0 === preg_match( '/oklch|--rt-|--p:/', $css ), "{$label}/style.css: no old design-system tokens or accent" );
	cmp_assert( 1 === preg_match( '/\[hidden\]\s*\{\s*display:\s*none\s*!important/', $css ), "{$label}/style.css: [hidden] wins over .btn display (the Cancel button stays hidden)" );
	cmp_assert( false !== strpos( $css, ':focus-visible' ), "{$label}/style.css: visible focus" );
}

$cmp_site_widget = '<script>(function(k,u){try{if(localStorage.getItem(\'wsa-feedback-\'+k)||/[#&]wsa=/.test(location.hash)){var s=document.createElement(\'script\');s.src=u;s.async=true;document.head.appendChild(s)}}catch(e){}})(\'wd7raatys6vat1uk7ehwrm7g\',\'https://cemainou.rt.md/api/widget/wd7raatys6vat1uk7ehwrm7g.js\');</script>';
// No external resources, in the sources and in the output. The only scripts are
// our own files, loaded from this site, with no inline code (Task 4: the tool and the demo).
$cmp_site_ext = array(
	'inline or external <script' => '/<script(?![^>]*\bsrc="\/(?!\/)[A-Za-z0-9\/_.-]+\.js(?:\?v=[0-9a-f]{8})?"( defer)?><\/script>)/i',
	'<link to http'        => '/<link\b[^>]*href\s*=\s*["\']?(https?:)?\/\//i',
	'src="http'            => '/\bsrc\s*=\s*["\']?(https?:)?\/\//i',
	'@import'              => '/@import/i',
	'url(http'             => '/url\(\s*["\']?(https?:)?\/\//i',
);
foreach ( array( 'site' => $cmp_site_root . '/site', 'out' => $cmp_site_out1 ) as $label => $dir ) {
	foreach ( cmp_site_files( $dir ) as $f ) {
		if ( ! preg_match( '/\.(html|css|in)$/', $f ) ) {
			continue;
		}
		$c = (string) file_get_contents( $dir . '/' . $f );
		// The one allowed inline script: the RT team's feedback widget loader (exact text; loads nothing for visitors).
		$c = str_replace( $cmp_site_widget, '', $c );
		$c = (string) preg_replace( '#<pre><code>[^<]*</code></pre>#', '', $c ); // Code shown as text (/standalone/).
		foreach ( $cmp_site_ext as $what => $re ) {
			cmp_assert( 0 === preg_match( $re, $c ), "{$label}/{$f}: no {$what}" );
		}
	}
	foreach ( cmp_site_files( $dir ) as $f ) {
		cmp_assert( 0 !== strpos( basename( $f ), 'manifest-' ), "{$label}/{$f}: not a manifest-* file" );
	}
	// Our own scripts contact nothing outside the site (the bundled cmp.min.js is the plugin's, tested elsewhere).
	foreach ( cmp_site_files( $dir ) as $f ) {
		if ( preg_match( '/\.js$/', $f ) && ! preg_match( '#^demo/cmp\.min\.js$#', $f ) ) {
			cmp_assert( 0 === preg_match( '#(https?:)?//[a-z0-9]#i', (string) file_get_contents( $dir . '/' . $f ) ), "{$label}/{$f}: no external URL" );
		}
	}
}

// Public sources: no internal names or paths.
foreach ( cmp_site_files( $cmp_site_root . '/site' ) as $f ) {
	$c = (string) file_get_contents( $cmp_site_root . '/site/' . $f );
	foreach ( array( 'rtgit003', 'PROXY_KEY', 'cmp-update', 'public_html', 'rtmd', '/Users/', 'sergiupascaru' ) as $bad ) {
		cmp_assert( false === strpos( $c, $bad ), "site/{$f}: does not contain {$bad}" );
	}
}

// Privacy pages: every outgoing host the plugin can contact is declared.
foreach ( array( 'confidentialitate/index.html', 'en/privacy/index.html' ) as $page ) {
	$html = $cmp_site_read( $page );
	foreach ( array( 'biscuitwp.rt.md', 'github.com', 'fonts.googleapis.com', 'fonts.gstatic.com', 'i.ytimg.com', 'youtube-nocookie.com', 'cmp_consent', 'cmp_svc', 'SHA-256', '13', 'security@rt.md', 'rt.md/cmp/', 'standalone/">' ) as $needle ) {
		cmp_assert( false !== strpos( $html, $needle ), "{$page}: mentions {$needle}" );
	}
}

// ---- Landing page, the tool and the demo (Task 4 of the landing) -------------------
$cmp_site_repo = 'https://github.com/rtdesignstudio/biscuitwp/releases';
/** hrefs of the download links (data-download) of a page. */
$cmp_site_dl = function ( $html ) {
	preg_match_all( '#<a\b[^>]*\bdata-download\b[^>]*>#', $html, $m );
	$out = array();
	foreach ( $m[0] as $tag ) {
		$out[] = preg_match( '#\bhref="([^"]*)"#', $tag, $hm ) ? $hm[1] : '';
	}
	return $out;
};
$cmp_site_out7 = $cmp_site_tmp . '/out7';
list( $rc, $out ) = cmp_site_run( 'bash bin/build-site.sh --out ' . escapeshellarg( $cmp_site_out7 ), array_merge( $cmp_site_env, array( 'CMP_BETA_MANIFEST' => $cmp_site_tmp . '/no-beta.json' ) ) );
cmp_assert_eq( $rc, 0, 'build-site without any manifest: exits 0' . ( 0 === $rc ? '' : ' — ' . trim( $out ) ) );
// A stable release older than 1.1.0 has no biscuitwp.zip asset: link the latest release page instead.
$cmp_site_mf100 = $cmp_site_tmp . '/manifest-stable-100.json';
file_put_contents( $cmp_site_mf100, json_encode( array( 'format' => 1, 'channel' => 'stable', 'version' => '1.0.0', 'revoked' => array() ) ) );
$cmp_site_out9 = $cmp_site_tmp . '/out9';
list( $rc, $out ) = cmp_site_run( 'bash bin/build-site.sh --out ' . escapeshellarg( $cmp_site_out9 ), array_merge( $cmp_site_env, array( 'CMP_STABLE_MANIFEST' => $cmp_site_mf100 ) ) );
cmp_assert_eq( $rc, 0, 'build-site with a 1.0.0 stable manifest: exits 0' . ( 0 === $rc ? '' : ' — ' . trim( $out ) ) );
$cmp_site_badbeta = $cmp_site_tmp . '/bad-beta.json';
file_put_contents( $cmp_site_badbeta, json_encode( array( 'version' => '1.0"><script>' ) ) );
list( $rc, $out ) = cmp_site_run( 'bash bin/build-site.sh --out ' . escapeshellarg( $cmp_site_tmp . '/out8' ), array_merge( $cmp_site_env, array( 'CMP_BETA_MANIFEST' => $cmp_site_badbeta ) ) );
cmp_assert( 0 !== $rc, 'build-site: a beta manifest with an invalid version fails' );

foreach ( array( 'index.html' => 'ro', 'en/index.html' => 'en' ) as $page => $lang ) {
	$html = $cmp_site_read( $page );
	foreach ( array( 'verifica', 'legea', 'functii', 'demo', 'comparatie', 'resurse' ) as $id ) {
		cmp_assert( false !== strpos( $html, 'id="' . $id . '"' ), "{$page}: section #{$id}" );
	}
	// Download: stable -> the new-install zip of the latest release (biscuitwp.zip); else the beta manifest's version; else the releases page.
	foreach ( array(
		'beta only'   => array( $html, $cmp_site_repo . '/tag/v1.2.0' ),
		'stable'      => array( (string) @file_get_contents( $cmp_site_out3 . '/' . $page ), $cmp_site_repo . '/latest/download/biscuitwp.zip' ),
		'no manifest' => array( (string) @file_get_contents( $cmp_site_out7 . '/' . $page ), $cmp_site_repo ),
		'stable 1.0.0' => array( (string) @file_get_contents( $cmp_site_out9 . '/' . $page ), $cmp_site_repo . '/latest' ),
	) as $case => $pair ) {
		$links = $cmp_site_dl( $pair[0] );
		cmp_assert( count( $links ) >= 2, "{$page} ({$case}): at least two download links" );
		cmp_assert( array() !== $links && array( $pair[1] ) === array_values( array_unique( $links ) ), "{$page} ({$case}): download links go to {$pair[1]}" );
		cmp_assert( false === strpos( $pair[0], 'DOWNLOAD_URL' ), "{$page} ({$case}): download marker replaced" );
	}
	cmp_assert_eq( substr_count( (string) @file_get_contents( $cmp_site_root . '/site/' . $page ), '<!-- DOWNLOAD_URL -->' ), count( $cmp_site_dl( $html ) ), "site/{$page}: every download link uses the marker" );
	cmp_assert( 1 === preg_match( '#<a data-download href="[^"]*">[^<]+</a> <code>biscuitwp\.zip</code>#', $html ), "{$page}: the install step names biscuitwp.zip" );
	cmp_assert( false === strpos( $html, '<code>consent-manager-pro.zip</code>' ), "{$page}: the install step no longer names consent-manager-pro.zip" );
	// The tool.
	// The form in the hero (#verifica), what it reads behind "Detalii", the report under it (#raport), all in the hero.
	cmp_assert( 1 === preg_match( '#<section class="hero".*?</section>#s', $html, $sm ) && false !== strpos( $sm[0], '<div id="verifica" class="hero-check">' ) && 1 === substr_count( $html, 'id="verifica"' ), "{$page}: the check is in the hero" );
	cmp_assert( false === strpos( $html, 'verifica-owner' ), "{$page}: no owner box" );
	$sec = isset( $sm[0] ) ? $sm[0] : '';
	cmp_assert( 1 === preg_match( '#<form\b[^>]*\bid="verifica-form"[^>]*>#', $sec, $fm ), "{$page}: the tool's form" );
	cmp_assert( isset( $fm[0] ) && false !== strpos( $fm[0], 'action="/api/verifica.php"' ) && false !== strpos( $fm[0], 'method="post"' ), "{$page}: the form posts to /api/verifica.php" );
	cmp_assert( isset( $fm[0] ) && 1 === preg_match( '#\shidden[\s>]#', $fm[0] ), "{$page}: the form is hidden until verifica.js shows it" );
	cmp_assert( 1 === preg_match( '#<input\b[^>]*\bname="url"[^>]*>#', $sec, $im ) && false !== strpos( $im[0], 'required' ), "{$page}: a required url field" );
	cmp_assert( 1 === preg_match( '#<label\b[^>]*\bfor="verifica-url"#', $sec ) && false !== strpos( $sec, 'id="verifica-url"' ), "{$page}: the url field has a label" );
	cmp_assert( 1 === preg_match( '#<noscript>(.*?)</noscript>#s', $sec, $nm ) && strlen( trim( strip_tags( $nm[1] ) ) ) > 20, "{$page}: <noscript> message for the tool" );
	cmp_assert( false === strpos( $html, '<section id="raport"' ) && 1 === preg_match( '#<div id="raport" class="hero-report">.*?</div>\n<ul class="facts">#s', $sec, $rpm ), "{$page}: the report opens in the hero, above the facts" );
	cmp_assert( 1 === preg_match( '#<button type="button" class="more" aria-expanded="false" aria-controls="verifica-detalii">[^<]+</button></p>#', $sec ) && 1 === preg_match( '#<div id="verifica-detalii" class="check-more" hidden>#', $sec ), "{$page}: \"Detalii\" under the field opens the folded panel" );
	cmp_assert( 1 === preg_match( '#<p class="lead hero-lead">.*?</p>\n<div class="hero-foot">\n<div id="verifica" class="hero-check">#s', $sec ), "{$page}: the intro above the check, the check next to the download" );
	cmp_assert( 1 === preg_match( '#<div\b[^>]*\bid="verifica-rezultat"[^>]*>#', $sec, $rm ) && false !== strpos( $rm[0], 'aria-live="polite"' ), "{$page}: result region with aria-live=polite" );
	cmp_assert( false !== strpos( $html, '<script src="/assets/verifica.js" defer></script>' ), "{$page}: loads /assets/verifica.js" );
	cmp_assert( false !== strpos( $sec, 'id="cum-verifici"' ), "{$page}: how to check it yourself (#cum-verifici)" );
	cmp_assert( 1 === preg_match( '#<button\b[^>]*\bid="verifica-anuleaza"[^>]*>#', $sec, $cbm ) && 1 === preg_match( '#\shidden[\s>]#', $cbm[0] ), "{$page}: the Cancel button is hidden until a check runs" );
	// The demo, in an iframe of /demo/, with three styles.
	$demo_base = 'ro' === $lang ? '/demo/embed/' : '/demo/' . $lang . '/embed/';
	cmp_assert( 1 === preg_match( '#<iframe\b[^>]*\bsrc="' . preg_quote( $demo_base, '#' ) . '\?style=(minimal|brand|friendly)"[^>]*\bname="cmp-demo"[^>]*\btitle="[^"]+"#', $html ), "{$page}: demo iframe of {$demo_base}" );
	foreach ( array( 'minimal', 'brand', 'friendly' ) as $st ) {
		cmp_assert( 1 === preg_match( '#<a\b[^>]*href="' . preg_quote( $demo_base, '#' ) . '\?style=' . $st . '"[^>]*target="cmp-demo"#', $html ), "{$page}: demo style switch {$st}" );
	}
	// The comparison: every claim about another product has a note with a link and a date.
	cmp_assert( 1 === preg_match( '#<section[^>]*id="comparatie".*?</section>#s', $html, $cm ), "{$page}: #comparatie is a section" );
	$cmp_sec = isset( $cm[0] ) ? $cm[0] : '';
	cmp_assert( 1 === preg_match( '#<table\b.*?</table>#s', $cmp_sec, $tm ), "{$page}: comparison table" );
	$table = isset( $tm[0] ) ? $tm[0] : '';
	foreach ( array( 'BiscuitWP', 'Complianz', 'CookieYes', 'Cookiebot' ) as $prod ) {
		cmp_assert( false !== strpos( $table, $prod ), "{$page}: comparison has {$prod}" );
	}
	preg_match_all( '#<tr>(.*?)</tr>#s', $table, $rows );
	$cells = 0;
	$notes = array();
	foreach ( $rows[1] as $row ) {
		if ( false !== strpos( $row, '<th scope="col"' ) ) {
			continue;
		}
		preg_match_all( '#<td\b[^>]*>(.*?)</td>#s', $row, $tds );
		cmp_assert_eq( count( $tds[1] ), 4, "{$page}: comparison row has 4 product cells" );
		foreach ( array_slice( $tds[1], 1 ) as $td ) {
			++$cells;
			$ok = preg_match_all( '#<a href="\#(n-[a-z0-9-]+)"#', $td, $nm2 );
			cmp_assert( $ok > 0, "{$page}: comparison cell about another product has a note: " . trim( strip_tags( $td ) ) );
			foreach ( $nm2[1] as $n ) {
				$notes[ $n ] = true;
			}
		}
	}
	cmp_assert( $cells >= 24, "{$page}: comparison covers 8 criteria x 3 products" );
	foreach ( array_keys( $notes ) as $n ) {
		cmp_assert( 1 === preg_match( '#<li id="' . preg_quote( $n, '#' ) . '">(.*?)</li>#s', $cmp_sec, $lm2 ), "{$page}: note {$n} exists" );
		$li = isset( $lm2[1] ) ? $lm2[1] : '';
		cmp_assert( 1 === preg_match( '#<a href="https://(?!biscuitwp\.rt\.md)[^"]+"#', $li ), "{$page}: note {$n} links its source" );
		cmp_assert( false !== strpos( $li, '<time datetime="2026-11-20">' ), "{$page}: note {$n} has the verification date" );
	}
	cmp_assert( false === strpos( $html, "\xe2\x80\x94" ) || 'en' === $lang, "{$page}: no em dash in Romanian text" );
}

// verifica.js: response data only through textContent, cancellable, confetti respects reduced motion.
$cmp_site_js = (string) @file_get_contents( $cmp_site_root . '/site/assets/verifica.js' );
cmp_assert( '' !== $cmp_site_js, 'site/assets/verifica.js exists' );
cmp_assert( $cmp_site_js === (string) @file_get_contents( $cmp_site_out1 . '/assets/verifica.js' ), 'build-site: copies assets/verifica.js as is' );
foreach ( array( 'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'setAttribute(\'on', 'createContextualFragment', 'DOMParser' ) as $bad ) {
	cmp_assert( false === strpos( $cmp_site_js, $bad ), "verifica.js: does not use {$bad}" );
}
foreach ( array( 'textContent', 'AbortController', '/api/verifica.php', 'prefers-reduced-motion', '#functii', '#cum-verifici', 'rate_limited', 'busy', "'clean'", "'issues'", "'unsure'", "'error'", 'BiscuitWP' ) as $need ) {
	cmp_assert( false !== strpos( $cmp_site_js, $need ), "verifica.js: uses {$need}" );
}
cmp_assert( false === strpos( $cmp_site_js, "\xe2\x80\x94" ), 'verifica.js: no em dash' );
cmp_assert( false === strpos( $cmp_site_js, "out.setAttribute('aria-busy'" ), 'verifica.js: no aria-busy on the live region' );
cmp_assert( false !== strpos( $cmp_site_js, "querySelector('canvas.confetti')" ), 'verifica.js: confetti is not started twice' );
cmp_assert( false !== strpos( $cmp_site_js, 'form.hidden = false' ), 'verifica.js: shows the form only when it can run' );
cmp_assert( false !== strpos( $cmp_site_js, 'cancel.hidden = !on' ), 'verifica.js: shows Cancel only while a check runs' );
cmp_assert( false !== strpos( $cmp_site_js, "'cmp:consent-manager-pro'" ), 'verifica.js: our own CMP finding is not shown twice' );
cmp_assert( 1 === preg_match( '#<canvas|createElement\(\s*\'canvas\'\s*\)#', $cmp_site_js ), 'verifica.js: confetti on its own canvas' );
cmp_assert( 1 === preg_match( '#/\* confetti \*/(.*?)/\* /confetti \*/#s', $cmp_site_js, $cf ) && strlen( preg_replace( '#\s+#', ' ', preg_replace( '#//[^\n]*|/\*.*?\*/#s', '', $cf[1] ) ) ) < 2048, 'verifica.js: confetti under 2 KB (without comments and whitespace runs)' );

// While a check runs: an ordered list of what is being checked (both languages),
// hidden from screen readers (the status line and the final result are announced).
foreach ( array(
	'Deschidem pagina principală, ca un vizitator nou',
	'Căutăm paginile site-ului (sitemap)',
	'Verificăm până la 10 pagini',
	'Căutăm trackere: Google Analytics, Meta Pixel, Yandex, Clarity și altele',
	'Verificăm cookie-urile puse înainte de acord',
	'Căutăm resurse care trimit IP-ul: Google Fonts, CDN-uri, Gravatar',
	'Căutăm bannerul de cookie-uri și linkul spre politică',
	'Opening the homepage as a new visitor',
	'Looking for the pages of the site (sitemap)',
	'Checking up to 10 pages',
	'Looking for trackers: Google Analytics, Meta Pixel, Yandex, Clarity and others',
	'Checking cookies set before consent',
	'Looking for resources that send the IP address: Google Fonts, CDNs, Gravatar',
	'Looking for the cookie banner and the policy link',
) as $step ) {
	cmp_assert( false !== strpos( $cmp_site_js, "'" . $step . "'" ), "verifica.js: check step „{$step}”" );
}
$cmp_site_js_nocf = preg_replace( '#/\* confetti \*/.*?/\* /confetti \*/#s', '', $cmp_site_js );
cmp_assert( false !== strpos( $cmp_site_js_nocf, "createElement('ol')" ) || false !== strpos( $cmp_site_js_nocf, "el('ol'" ), 'verifica.js: the steps are an ordered list' );
cmp_assert( false !== strpos( $cmp_site_js_nocf, "setAttribute('aria-hidden', 'true')" ), 'verifica.js: the step list is hidden from screen readers' );
cmp_assert( false !== strpos( $cmp_site_js_nocf, 'prefers-reduced-motion' ), 'verifica.js: the steps respect prefers-reduced-motion' );
cmp_assert( 1 === preg_match( '#@media \(prefers-reduced-motion: reduce\)\s*\{[^@]*\.run-steps#s', (string) @file_get_contents( $cmp_site_root . '/site/style.css' ) ), 'style.css: no step animation under reduced motion' );

// ---- V2 (landing v2 design): detailed report, up to 10 pages, hero ----
foreach ( array( 'index.html', 'en/index.html' ) as $page ) {
	$html = $cmp_site_read( $page );
	// The tool's texts now speak of up to 10 pages; never „conform” in RO.
	cmp_assert( false !== strpos( $html, 'index.html' === $page ? 'până la 10 pagini' : 'up to 10 pages' ), "{$page}: the tool mentions up to 10 pages" );
}
cmp_assert( false === strpos( $cmp_site_read( 'index.html' ), 'site-ul e conform' ), 'index.html: the tool limits do not say „conform”' );
cmp_assert( false !== strpos( $cmp_site_js, '45000' ) && false === strpos( $cmp_site_js, '30000' ), 'verifica.js: client timeout 45 s' );
cmp_assert( 1 === preg_match( '#\}, 1200\);#', $cmp_site_js ), 'verifica.js: a step every 1.2 s' );
// The report: everything from the response through textContent, no numeric score.
foreach ( array( 'f.evidence', 'f.pages', 'f.fix', 'f.cmp_fix', 'f.explain', 'f.law', 'data.top', 'data.passed', 'pages.failed', 'pages.total_source', 'pages.total', "'sitemap'", "'links'", "el('details'", "el('summary'", "el('dl'", "'facts r-facts'" ) as $need ) {
	cmp_assert( false !== strpos( $cmp_site_js, $need ), "verifica.js: the report uses {$need}" );
}
foreach ( array( 'Ce faci întâi', 'Ce am găsit', 'Pe ce pagini', 'De ce contează', 'Cum rezolvi', 'În regulă', 'de verificat',
	'What to do first', 'What we found', 'On which pages', 'Why it matters', 'How to fix it', 'All good', 'to check',
	'; am verificat primele ', '. Verificarea completă nu e disponibilă acum.', 'Site-ul are cel puțin ', 'Site-ul are ',
	'; we checked the first ', '. A full check is not available right now.', 'The site has at least ', 'The site has ',
	'Am verificat ', 'We checked ' ) as $need ) {
	cmp_assert( false !== strpos( $cmp_site_js, $need ), "verifica.js: text „{$need}”" );
}
cmp_assert( 0 === preg_match( '#score|scor#i', $cmp_site_js ), 'verifica.js: no numeric score' );
cmp_assert( 1 === preg_match( "#data\.status === 'clean'\)\s*\{[^}]*confetti\(\)#s", $cmp_site_js ) && 1 === substr_count( preg_replace( '#/\* confetti \*/.*?/\* /confetti \*/#s', '', $cmp_site_js ), 'confetti()' ), 'verifica.js: confetti only for clean' );
// Links in the report: only fixed addresses, never one built from the response.
preg_match_all( '#link\(([^,]+),#', $cmp_site_js, $lk );
foreach ( $lk[1] as $href ) {
	$href = trim( $href );
	cmp_assert( in_array( $href, array( 'href', "'#cum-verifici'", "'#functii'", 'downloadHref()', 'standaloneHref()', 'it[0]' ), true ), "verifica.js: link href {$href} is a fixed address" );
}
cmp_assert( false === strpos( $cmp_site_js, "setAttribute('href', data" ) && false === strpos( $cmp_site_js, '.href =' ), 'verifica.js: no href from the response' );
// The renderer, run in node on a fake DOM (innerHTML and friends throw there) with
// response fixtures: what the visitor reads, as text.
$cmp_site_node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
if ( '' === $cmp_site_node ) {
	echo "  (skip: node not found, the verifica.js render run is not checked)\n";
} else {
	$cmp_site_dom = function ( $lang, $fixture, $owner = true, $checks = 'fail', $share = '' ) use ( $cmp_site_root ) {
		$cmd = 'node ' . escapeshellarg( $cmp_site_root . '/tests/fixtures/site-verifica/run.js' ) . ' ' . escapeshellarg( $lang ) . ' ' . escapeshellarg( $fixture ) . ' ' . ( $owner ? '1' : '0' ) . ' ' . escapeshellarg( (string) $checks ) . ' ' . escapeshellarg( (string) $share );
		$o   = json_decode( (string) shell_exec( $cmd . ' 2>&1' ), true );
		return is_array( $o ) ? $o : array( 'text' => '', 'body' => null, 'error' => 'no output', 'fetches' => 0 );
	};
	// Issues on 7 of 10 pages, 84 pages in the sitemap.
	$r = $cmp_site_dom( 'ro', 'issues-sitemap' );
	cmp_assert( empty( $r['error'] ), 'render issues (ro): runs without errors' . ( empty( $r['error'] ) ? '' : ' — ' . $r['error'] ) );
	cmp_assert( array( 'url' => 'exemplu.md', 'lang' => 'ro' ) === $r['body'], 'render (ro): POST body has the address and the language' );
	foreach ( array( 'exemplu.md', 'Am găsit 3 probleme', 'Ce faci întâi', 'Google Analytics pornește înainte de acord', 'Mută scriptul după acord.', 'Ce am găsit', 'www.googletagmanager.com/gtag/js', 'Pe ce pagini (7)', '/contacte/', 'De ce contează', 'Baza legală: Legea 195/2024', 'Cum rezolvi', 'Cu BiscuitWP: ', 'Am verificat 10 pagini.', 'Site-ul are 84 de pagini; am verificat primele 10. Verificarea completă nu e disponibilă acum.', 'În regulă', 'HTTPS', 'Nu am putut citi: ', '/lent/ (nu a răspuns la timp)', 'De știut', 'BiscuitWP rezolvă asta' ) as $need ) {
		cmp_assert( false !== strpos( $r['text'], $need ), "render issues (ro): shows „{$need}”" );
	}
	cmp_assert( false !== strpos( $r['facts'], '3|probleme' ) && false !== strpos( $r['facts'], '1|de verificat' ) && false !== strpos( $r['facts'], '4|în regulă' ), 'render issues (ro): counters 3 problems, 1 to check, 4 passed (' . $r['facts'] . ')' );
	cmp_assert( false === strpos( $r['text'], 'https://exemplu.md/contacte/' ), 'render issues (ro): page paths are relative' );
	cmp_assert( false === strpos( $r['text'], '<script' ) && false !== strpos( $r['text'], '<b>x</b>' ), 'render issues (ro): server text is shown as text, never parsed' );
	cmp_assert( 0 === $r['confetti'], 'render issues: no confetti' );
	cmp_assert_eq( $r['hrefs'], array( '#instalare', '#functii' ), 'render issues: only fixed links' );
	$r = $cmp_site_dom( 'en', 'issues-sitemap' );
	foreach ( array( 'We found 3 problems', 'What to do first', 'We checked 10 pages.', 'The site has 84 pages; we checked the first 10. A full check is not available right now.', 'All good', 'On which pages (7)', 'With BiscuitWP: ' ) as $need ) {
		cmp_assert( false !== strpos( $r['text'], $need ), "render issues (en): shows „{$need}”" );
	}
	foreach ( array( 'site/index.html', 'site/en/index.html' ) as $f ) {
		cmp_assert( 1 === preg_match( '#<a data-standalone href="/(?:en/)?standalone/">#', (string) file_get_contents( $cmp_site_root . '/' . $f ) ), "{$f}: the standalone link is marked data-standalone for the report" );
	}
	// The counter line under the form: from 100 successful checks, +1 after a check that read the site.
	$r = $cmp_site_dom( 'ro', 'clean', true, 1239 );
	cmp_assert( empty( $r['error'] ) && 1 === $r['counterFetches'], 'counter (ro): asks /api/contor.php once' . ( empty( $r['error'] ) ? '' : ' — ' . $r['error'] ) );
	cmp_assert( false === $r['counter']['hidden'] && '1.240 de verificări făcute până acum' === $r['counter']['text'], 'counter (ro): 1239 + this check -> „1.240 de verificări făcute până acum” (' . $r['counter']['text'] . ')' );
	$r = $cmp_site_dom( 'en', 'clean', true, 1239 );
	cmp_assert( false === $r['counter']['hidden'] && '1,240 checks so far' === $r['counter']['text'], 'counter (en): „1,240 checks so far” (' . $r['counter']['text'] . ')' );
	$r = $cmp_site_dom( 'ro', 'issues-sitemap', true, 99 );
	cmp_assert( false === $r['counter']['hidden'] && '100 de verificări făcute până acum' === $r['counter']['text'], 'counter (ro): 99 + a check with issues reaches the 100 threshold (' . $r['counter']['text'] . ')' );
	$r = $cmp_site_dom( 'ro', 'unsure', true, 115 );
	cmp_assert( '116 verificări făcute până acum' === $r['counter']['text'], 'counter (ro): 116 without „de” (' . $r['counter']['text'] . ')' );
	$r = $cmp_site_dom( 'ro', 'clean', true, 50 );
	cmp_assert( true === $r['counter']['hidden'] && '' === $r['counter']['text'], 'counter: under 100 the line stays hidden and empty' );
	$r = $cmp_site_dom( 'ro', 'clean', true, 'fail' );
	cmp_assert( empty( $r['error'] ) && true === $r['counter']['hidden'], 'counter: endpoint down -> the line stays hidden, the check still works' );
	$r = $cmp_site_dom( 'ro', 'error', false, 500 );
	cmp_assert( '500 de verificări făcute până acum' === $r['counter']['text'], 'counter: no check sent -> the number does not move (' . $r['counter']['text'] . ')' );
	// „Ce găsim cel mai des”: from 100 checks, rows sorted, whole percentages.
	$cmp_site_found = json_encode( array( 'checks' => 160, 'found' => array( 'of' => 150, 'tracker' => 101, 'google-fonts' => 92, 'ip-resources' => 55, 'no-policy' => 28, 'no-banner' => 36 ) ) );
	$r = $cmp_site_dom( 'ro', 'error', false, $cmp_site_found );
	cmp_assert( empty( $r['error'] ) && false === $r['found']['hidden'], 'found (ro): shown from 100 checks' . ( empty( $r['error'] ) ? '' : ' — ' . $r['error'] ) );
	cmp_assert_eq(
		$r['found']['text'],
		array( 'Ce găsim cel mai des', 'Tracker pornit înainte de acord', '67%', 'Google Fonts încărcate de la Google', '61%', 'Alte resurse care trimit IP-ul vizitatorului', '37%', 'Trackere fără niciun banner de cookie-uri', '24%', 'Niciun link spre politica de confidențialitate', '19%', 'Din 150 de verificări făcute cu instrumentul. Un site verificat de mai multe ori se numără de fiecare dată.' ),
		'found (ro): title, rows from most to least frequent, note'
	);
	cmp_assert_eq( $r['found']['widths'], array( 'width:67%', 'width:61%', 'width:37%', 'width:24%', 'width:19%' ), 'found: bar widths match the percentages' );
	$r = $cmp_site_dom( 'en', 'error', false, $cmp_site_found );
	cmp_assert( 'What we find most often' === $r['found']['text'][0] && 'Tracker started before consent' === $r['found']['text'][1] && 'From 150 checks made with the tool. A site checked several times counts each time.' === end( $r['found']['text'] ), 'found (en): title, first row, note' );
	$r = $cmp_site_dom( 'ro', 'error', false, json_encode( array( 'checks' => 120, 'found' => array( 'of' => 99, 'tracker' => 50, 'google-fonts' => 1, 'ip-resources' => 1, 'no-policy' => 1, 'no-banner' => 1 ) ) ) );
	cmp_assert( true === $r['found']['hidden'] && array() === $r['found']['text'], 'found: under 100 checks the block stays hidden and empty' );
	$r = $cmp_site_dom( 'ro', 'error', false, json_encode( array( 'checks' => 300, 'found' => array( 'of' => 200, 'tracker' => 'x', 'google-fonts' => 900, 'ip-resources' => -3 ) ) ) );
	cmp_assert( false === $r['found']['hidden'] && in_array( '100%', $r['found']['text'], true ) && in_array( '0%', $r['found']['text'], true ) && 7 === count( array_filter( $r['found']['text'], function ( $t ) { return '0%' === $t || '100%' === $t; } ) ) + 2, 'found: bad numbers read 0, nothing above 100%' );
	$r = $cmp_site_dom( 'ro', 'error', false, 'fail' );
	cmp_assert( true === $r['found']['hidden'], 'found: endpoint down -> hidden' );
	// Sharing the report: "Salvează ca PDF" (the browser's print, every detail open) and "Copiază raportul" (plain text).
	$r = $cmp_site_dom( 'ro', 'issues-sitemap' );
	cmp_assert( empty( $r['error'] ), 'share (ro): runs without errors' . ( empty( $r['error'] ) ? '' : ' — ' . $r['error'] ) );
	cmp_assert_eq( $r['buttons'], array( 'Salvează ca PDF', 'Copiază raportul', 'Verifică alt site' ), 'share (ro): the two buttons under the report' );
	cmp_assert( is_string( $r['copied'] ), 'share (ro): "Copiază raportul" writes to the clipboard' );
	$cmp_site_txt = (string) $r['copied'];
	foreach ( array( 'exemplu.md Am găsit 3 probleme', '3 probleme', 'Ce faci întâi', 'Google Analytics pornește înainte de acord', 'Mută scriptul după acord.', 'www.googletagmanager.com/gtag/js', '- /contacte/', 'Baza legală: Legea 195/2024', 'Am verificat 10 pagini.', 'În regulă', '- HTTPS', 'Raport făcut cu „Verifică-ți site-ul”, https://biscuitwp.rt.md/' ) as $need ) {
		cmp_assert( false !== strpos( $cmp_site_txt, $need ), "share (ro): the copied text has „{$need}”" );
	}
	foreach ( array( 'Salvează ca PDF', 'Copiază raportul', 'Descarcă', 'Vezi ce face', 'Raport „Verifică-ți site-ul” de pe biscuitwp.rt.md' ) as $bad ) {
		cmp_assert( false === strpos( $cmp_site_txt, $bad ), "share (ro): the copied text has no „{$bad}”" );
	}
	cmp_assert( false === strpos( $cmp_site_txt, "\n\n\n" ), 'share (ro): no triple blank lines' );
	cmp_assert( 0 === strpos( (string) $r['shareStatus'], 'Am copiat raportul' ), 'share (ro): says it was copied (' . $r['shareStatus'] . ')' );
	cmp_assert( 1 === $r['printCalls'], 'share: "Salvează ca PDF" opens the print dialog' );
	cmp_assert( is_array( $r['printState'] ) && $r['printState']['details'] > 0 && $r['printState']['details'] === $r['printState']['open'], 'share: every folded detail is open while printing' );
	cmp_assert( is_array( $r['printState'] ) && false !== strpos( $r['printState']['htmlClass'], 'print-report' ), 'share: the page is marked print-report while printing' );
	cmp_assert( 0 === $r['openAfter'] && false === strpos( $r['htmlClassAfter'], 'print-report' ), 'share: after printing the details fold again and the mark goes' );
	cmp_assert( false !== strpos( $r['text'], 'Raport „Verifică-ți site-ul” de pe biscuitwp.rt.md, ' ), 'share (ro): the print-only title line is in the report' );
	$r = $cmp_site_dom( 'en', 'issues-sitemap' );
	cmp_assert_eq( $r['buttons'], array( 'Save as PDF', 'Copy the report', 'Check another site' ), 'share (en): the two buttons' );
	cmp_assert( false !== strpos( (string) $r['copied'], 'Report made with “Check your site”, https://biscuitwp.rt.md/' ) && 0 === strpos( (string) $r['shareStatus'], 'Report copied' ), 'share (en): footer and status' );
	$r = $cmp_site_dom( 'ro', 'issues-sitemap', true, 'fail', 'noclip' );
	cmp_assert( null === $r['copied'] && 0 === strpos( (string) $r['shareStatus'], 'Nu am putut copia raportul' ), 'share (ro): a refused clipboard says so (' . $r['shareStatus'] . ')' );
	foreach ( array( 'clean', 'unsure' ) as $fx ) {
		$r = $cmp_site_dom( 'ro', $fx );
		cmp_assert_eq( $r['buttons'], array( 'Salvează ca PDF', 'Copiază raportul', 'Verifică alt site' ), "share: {$fx} report has the buttons" );
	}
	$r = $cmp_site_dom( 'ro', 'error', false );
	cmp_assert_eq( $r['buttons'], array(), 'share: no report, no buttons' );
	$cmp_site_css = (string) file_get_contents( $cmp_site_root . '/site/style.css' );
	cmp_assert( 1 === preg_match( '/@media print\s*\{[^@]*\.print-report/s', $cmp_site_css ) && 1 === preg_match( '/\.r-print-head\s*\{\s*display:\s*none/', $cmp_site_css ), 'style.css: print rules for the report, the title line hidden on screen' );
	// A site that does not look like WordPress: the plugin is for WordPress, the problems stay.
	$r = $cmp_site_dom( 'ro', 'issues-other' );
	cmp_assert( empty( $r['error'] ), 'render issues-other (ro): runs without errors' . ( empty( $r['error'] ) ? '' : ' — ' . $r['error'] ) );
	foreach ( array( 'Site-ul tău nu pare făcut pe WordPress', 'Problemele de mai sus rămân valabile pe orice platformă', 'versiunea standalone', 'Versiunea standalone' ) as $need ) {
		cmp_assert( false !== strpos( $r['text'], $need ), "render issues-other (ro): shows „{$need}”" );
	}
	cmp_assert( false === strpos( $r['text'], 'BiscuitWP rezolvă asta' ), 'render issues-other (ro): no „rezolvă asta”' );
	cmp_assert_eq( $r['hrefs'], array( '#instalare', '#cum-verifici' ), 'render issues-other: standalone (fallback in the fake DOM) and how-to links' );
	cmp_assert( false === strpos( $r['text'], 'Vezi ce face' ), 'render issues-other (ro): no plugin features button' );
	$r = $cmp_site_dom( 'en', 'issues-other' );
	foreach ( array( 'Your site does not look like WordPress', 'The problems above apply on any platform', 'Standalone version' ) as $need ) {
		cmp_assert( false !== strpos( $r['text'], $need ), "render issues-other (en): shows „{$need}”" );
	}
	$r = $cmp_site_dom( 'ro', 'links' );
	cmp_assert( false !== strpos( $r['text'], 'Site-ul are cel puțin 14 pagini; am verificat primele 10. Verificarea completă nu e disponibilă acum.' ), 'render links (ro): „cel puțin” for pages found in links' );
	$r = $cmp_site_dom( 'en', 'links' );
	cmp_assert( false !== strpos( $r['text'], 'The site has at least 14 pages; we checked the first 10.' ), 'render links (en): "at least"' );
	$r = $cmp_site_dom( 'ro', 'clean' );
	cmp_assert( false !== strpos( $r['text'], 'Am verificat o pagină.' ) && false === strpos( $r['text'], 'Site-ul are' ), 'render clean (ro): one page, no >10 message' );
	cmp_assert( 1 === $r['confetti'], 'render clean: confetti' );
	cmp_assert( false === strpos( $r['text'], 'Ce faci întâi' ), 'render clean: no „Ce faci întâi”' );
	$r = $cmp_site_dom( 'ro', 'unsure' );
	cmp_assert( false !== strpos( $r['text'], 'Nu putem fi siguri' ) && false !== strpos( $r['text'], 'De verificat' ) && 0 === $r['confetti'], 'render unsure (ro): title and list, no confetti' );
	cmp_assert( false !== strpos( $r['text'], 'Pe ce pagini' ) && false === strpos( $r['text'], 'Pe ce pagini (' ), 'render unsure (ro): up to 3 pages listed without folding' );
	$r = $cmp_site_dom( 'ro', 'clean', false );
	cmp_assert( 0 === $r['fetches'] && 0 === strpos( $r['urlError'], 'Scrie adresa site-ului' ) && 'true' === $r['urlInvalid'], 'render (ro): an empty address sends nothing and says so next to the field' );
}
// The hero: rt.md /umarul/ rows, with the accessible name on the h1.
foreach ( array( 'index.html' => array( 'Cookie-uri cu acord, nu cu noroc.', 'Cookie-uri cu acord,', 'nu cu noroc.' ), 'en/index.html' => array( 'Cookies by consent, not by luck.', 'Cookies by consent,', 'not by luck.' ) ) as $page => $h ) {
	$html = $cmp_site_read( $page );
	cmp_assert( 1 === preg_match( '#<h1\b[^>]*>(.*?)</h1>#s', $html, $hm2 ), "{$page}: hero h1" );
	cmp_assert( 1 === preg_match( '#<h1 id="titlu" class="big" aria-label="' . preg_quote( $h[0], '#' ) . '">#', $html ), "{$page}: hero h1.big with aria-label" );
	$rows = isset( $hm2[1] ) ? $hm2[1] : '';
	cmp_assert_eq( preg_match_all( '#<span class="row" aria-hidden="true"><span>([^<]+)</span></span>#', $rows, $rm2 ), 2, "{$page}: hero has two aria-hidden rows of spans" );
	cmp_assert( isset( $rm2[1] ) && array( $h[1], $h[2] ) === $rm2[1], "{$page}: hero rows keep the title text" );
}
// #728 lot 1: the first screen sells to someone with 30 seconds; the page follows how they decide.
foreach ( array(
	'index.html'    => array( 'Plugin gratuit pentru WordPress.', 'Verifică-ți site-ul', 'Descarcă pentru WordPress', 'Legea 195/2024 e în vigoare din 23 august 2026.', 'Înainte de acord', 'După „Accept”', 'Repară asta cu pluginul' ),
	'en/index.html' => array( 'A free WordPress plugin.', 'Check your site', 'Download for WordPress', 'Law 195/2024 has been in force since 23 August 2026.', 'Before consent', 'After “Accept”', 'Fix this with the plugin' ),
) as $page => $t ) {
	$html = $cmp_site_read( $page );
	$hero = 1 === preg_match( '#<section class="hero".*?</section>#s', $html, $hsm ) ? $hsm[0] : '';
	cmp_assert( false !== strpos( $hero, '<p class="lead hero-lead"><strong>' . $t[0] . '</strong>' ), "{$page}: the lead starts by saying it is a free WordPress plugin" );
	cmp_assert( 1 === preg_match( '#id="verifica-buton">.*<p class="actions">\s*<a class="btn btn-ghost" data-download href="[^"]*">' . preg_quote( $t[2], '#' ) . '</a>#s', $hero ) && false !== strpos( $hero, $t[1] ), "{$page}: the check comes first, the download second" );
	cmp_assert( 1 === preg_match( '#<p class="alt small">[^<]*<a data-standalone href="/(?:en/)?standalone/">#', $hero ), "{$page}: right under the buttons, the line for sites without WordPress" );
	cmp_assert( 1 === preg_match( '#</h1>\s*<figure class="hero-demo" aria-labelledby="hero-demo-cap">.*?</figure>\s*<p class="urgent"><strong>' . preg_quote( $t[3], '#' ) . '</strong>#s', $hero ), "{$page}: after the title, the product picture and the law line" );
	$fig = 1 === preg_match( '#<figure class="hero-demo".*?</figure>#s', $hero, $fgm ) ? $fgm[0] : '';
	cmp_assert( false !== strpos( $fig, '<figcaption id="hero-demo-cap">' ) && false !== strpos( $fig, $t[4] ) && false !== strpos( $fig, $t[5] ), "{$page}: the picture shows before and after consent, with a caption" );
	cmp_assert_eq( substr_count( $fig, '<li class="off">' ), substr_count( $fig, '<li class="on">' ), "{$page}: the same requests before (blocked) and after (loaded)" );
	cmp_assert( 1 === preg_match( '#<div class="hd-banner" aria-hidden="true">#', $fig ) && false === strpos( $fig, '<button' ) && false === strpos( $fig, '<a ' ), "{$page}: the small banner is a picture, with nothing to click" );
	preg_match_all( '#<section id="([a-z]+)"[^>]*>\s*<div class="wrap">\s*<div class="sh"><p class="n">(\d\d) · #', $html, $om );
	cmp_assert_eq( $om[1], array( 'functii', 'demo', 'instalare', 'legea', 'comparatie', 'resurse', 'incredere', 'intrebari' ), "{$page}: what it does, demo, install, then the law and the comparison" );
	cmp_assert_eq( $om[2], array( '01', '02', '03', '04', '05', '06', '07', '08' ), "{$page}: the section numbers follow the new order" );
	cmp_assert( 1 === preg_match( '#<details id="cum-verifici" class="howto">\s*<summary>[^<]+</summary>\s*<ol>#', $html ), "{$page}: the manual how-to is folded" );
	cmp_assert( false !== strpos( $cmp_site_js, "fixCta: '" . $t[6] . "'" ), "verifica.js ({$page}): the report ends with „{$t[6]}”" );
}
cmp_assert( false !== strpos( $cmp_site_js, "[[downloadHref(), T.fixCta, 'btn'], ['#functii', T.features, 'btn btn-ghost']]" ), 'verifica.js: a WordPress site with issues gets the fix button to the download' );
// #728 lot 2: link previews (Open Graph) per language, and the comparison as cards on a phone.
foreach ( array( 'index.html' => array( 'ro', '/', 'ro_RO' ), 'en/index.html' => array( 'en', '/en/', 'en_US' ) ) as $page => $og ) {
	$html  = $cmp_site_read( $page );
	$title = 1 === preg_match( '#<title>([^<]+)</title>#', $html, $tm3 ) ? $tm3[1] : '';
	$desc  = 1 === preg_match( '#<meta name="description" content="([^"]+)">#', $html, $dm3 ) ? $dm3[1] : '';
	foreach ( array(
		'og:type'         => 'website',
		'og:site_name'    => 'BiscuitWP',
		'og:title'        => $title,
		'og:description'  => $desc,
		'og:url'          => 'https://biscuitwp.rt.md' . $og[1],
		'og:locale'       => $og[2],
		'og:image'        => 'https://biscuitwp.rt.md/assets/og/og-' . $og[0] . '.png',
		'og:image:width'  => '1200',
		'og:image:height' => '630',
	) as $prop => $val ) {
		cmp_assert( '' !== $val && false !== strpos( $html, '<meta property="' . $prop . '" content="' . $val . '">' ), "{$page}: {$prop} = {$val}" );
	}
	cmp_assert( 1 === preg_match( '#<meta property="og:image:alt" content="[^"]{40,}">#', $html ), "{$page}: the preview picture has a text alternative" );
	cmp_assert( false !== strpos( $html, '<meta name="twitter:card" content="summary_large_image">' ), "{$page}: large preview on X too" );
	$png  = $cmp_site_root . '/site/assets/og/og-' . $og[0] . '.png';
	$size = is_readable( $png ) ? getimagesize( $png ) : false;
	cmp_assert( is_array( $size ) && 1200 === $size[0] && 630 === $size[1] && IMAGETYPE_PNG === $size[2], "site/assets/og/og-{$og[0]}.png: a 1200×630 PNG" );
	cmp_assert( is_readable( $png ) && filesize( $png ) < 300 * 1024, "site/assets/og/og-{$og[0]}.png: under 300 KB" );
}
cmp_assert( is_executable( $cmp_site_root . '/bin/build-og.sh' ) && is_readable( $cmp_site_root . '/bin/build-og.php' ), 'bin/build-og.sh makes the preview pictures again' );
// #728 lot 3: three screenshots from the plugin in #functii, and the 18 sites (a number only, no client names).
foreach ( array( 'index.html' => array( 'ro', 'Folosit pe 18 site-uri din Moldova' ), 'en/index.html' => array( 'en', 'Used on 18 sites in Moldova' ) ) as $page => $l3 ) {
	$html = $cmp_site_read( $page );
	cmp_assert( false !== strpos( $html, '<ul class="facts">' . "\n" . '<li>' . $l3[1] . '</li>' ), "{$page}: the first fact is the 18 sites" );
	$fn = 1 === preg_match( '#<section id="functii".*?</section>#s', $html, $fsm ) ? $fsm[0] : '';
	preg_match_all( '#<figure class="shot shot-[a-z]+"><a href="/assets/shots/[a-z]+-([a-z0-9]+)\.webp"><img src="/assets/shots/([a-z]+)-([a-z0-9]+)\.webp" width="(\d+)" height="(\d+)" alt="([^"]{50,})" loading="lazy" decoding="async"></a><figcaption>[^<]{20,}</figcaption></figure>#', $fn, $shm, PREG_SET_ORDER );
	cmp_assert_eq( array_map( function ( $m ) { return $m[1]; }, $shm ), array( 'aspect', 'stil1', 'stil2', 'stil3', 'gasite', 'registru', 'verificare' ), "{$page}: seven captioned screenshots with alt text in #functii" );
	foreach ( $shm as $m ) {
		cmp_assert( $m[2] === $l3[0] && $m[3] === $m[1], "{$page}: screenshot {$m[1]} is the {$l3[0]} one" );
		$f = $cmp_site_root . '/site/assets/shots/' . $m[2] . '-' . $m[3] . '.webp';
		$sz = is_readable( $f ) ? getimagesize( $f ) : false;
		cmp_assert( is_array( $sz ) && abs( $sz[0] / 2 - (int) $m[4] ) <= 1 && abs( $sz[1] / 2 - (int) $m[5] ) <= 1, "site/assets/shots/{$m[2]}-{$m[3]}.webp: width/height are half its pixels (2x, no layout shift)" );
		cmp_assert( is_readable( $f ) && filesize( $f ) < 300 * 1024, "site/assets/shots/{$m[2]}-{$m[3]}.webp: under 300 KB" );
	}
	cmp_assert( false !== strpos( $fn, 'example.com' ) && 1 === preg_match( '#<p class="muted small shots-note">[^<]*example\.com[^<]*</p>#', $fn ), "{$page}: the note says the screenshots come from a test site" );
	cmp_assert( 1 === preg_match( '#</div>\s*<p class="muted small shots-note">[^<]*</p>\s*<p class="actions actions-center"><a class="btn" data-download#', $fn ), "{$page}: the download button still closes #functii" );
}
// The screenshots open in a lightbox (a native <dialog>); without it the link opens the picture.
$cmp_site_lb = (string) @file_get_contents( $cmp_site_root . '/site/assets/site.js' );
foreach ( array( "querySelectorAll('.shot a')", "typeof HTMLDialogElement !== 'function'", 'dlg.showModal()', "e.metaKey || e.ctrlKey || e.shiftKey || e.altKey", "'ArrowLeft'", "'ArrowRight'", 'opener.focus()', "a.getAttribute('href')", "['Închide', 'Anterioara', 'Următoarea'", "['Close', 'Previous', 'Next'" ) as $need ) {
	cmp_assert( false !== strpos( $cmp_site_lb, $need ), "site.js lightbox: has {$need}" );
}
cmp_assert( false === strpos( $cmp_site_lb, 'innerHTML' ), 'site.js: the lightbox builds its DOM without innerHTML' );
cmp_assert( 1 === preg_match( '#dialog\.lb \{[^}]*position: fixed;#', (string) @file_get_contents( $cmp_site_root . '/site/style.css' ) ), 'style.css: the lightbox covers the screen' );
$cmp_site_css_l2 = preg_replace( '#/\*.*?\*/#s', '', (string) @file_get_contents( $cmp_site_root . '/site/style.css' ) );
cmp_assert( 1 === preg_match( '#@media \(max-width: 760px\)\s*\{.*?\.compare, \.compare tbody, \.compare tr, \.compare th, \.compare td \{ display: block;#s', $cmp_site_css_l2 ), 'style.css: on a phone the comparison rows become cards' );
foreach ( array( 2 => 'BiscuitWP', 3 => 'Complianz', 4 => 'CookieYes', 5 => 'Cookiebot' ) as $nth => $prod ) {
	cmp_assert( false !== strpos( $cmp_site_css_l2, '.compare td:nth-child(' . $nth . ')::before { content: "' . $prod . '"' ), "style.css: card answer {$nth} is labelled {$prod}" );
	cmp_assert( 1 === preg_match( '#<th scope="col">[^<]*</th>(?:<th scope="col">[^<]*</th>){' . ( $nth - 2 ) . '}<th scope="col">' . $prod . '</th>#', $cmp_site_read( 'index.html' ) ), "index.html: column {$nth} of the comparison is {$prod}, as the card labels say" );
}
$cmp_site_sjs = (string) @file_get_contents( $cmp_site_root . '/site/assets/site.js' );
cmp_assert( false !== strpos( $cmp_site_sjs, "t.tagName === 'DETAILS'" ) && false !== strpos( $cmp_site_sjs, "'hashchange'" ), 'site.js: a link to #cum-verifici opens the folded how-to' );
foreach ( array( "'.big .row > span'", "'measure'", "'ln'", "'wd'", 'animationend', 'fonts.ready' ) as $need ) {
	cmp_assert( false !== strpos( $cmp_site_site_js_v2 = (string) @file_get_contents( $cmp_site_root . '/site/assets/site.js' ), $need ), "site.js: hero rows use {$need}" );
}
cmp_assert( strpos( $cmp_site_site_js_v2, "'.big .row > span'" ) > strpos( $cmp_site_site_js_v2, 'if (reduce) { return; }' ), 'site.js: the hero lines are fixed only with motion (reduced motion: static)' );
$cmp_site_css_v2 = (string) @file_get_contents( $cmp_site_root . '/site/style.css' );
cmp_assert( 1 === preg_match( '#\.js \.big \.row > span\s*\{[^}]*opacity:\s*0[^}]*animation:\s*cmp-bin#', $cmp_site_css_v2 ), 'style.css: hero rows hidden then animated, only under .js' );
cmp_assert( 0 === preg_match( '#(^|[{},;]|\*/)\s*\.big \.row > span\s*\{[^}]*opacity:\s*0#', preg_replace( '#/\*.*?\*/#s', '', $cmp_site_css_v2 ) ), 'style.css: without JS the hero rows are visible' );
cmp_assert( 1 === preg_match( '#@keyframes cmp-bin\s*\{#', $cmp_site_css_v2 ) && 1 === preg_match( '#@keyframes cmp-weight\s*\{#', $cmp_site_css_v2 ), 'style.css: hero keyframes' );
cmp_assert( 1 === preg_match( '#@media \(prefers-reduced-motion: reduce\)\s*\{[^@]*\.js \.big \.row > span\s*\{[^}]*opacity:\s*1[^}]*animation:\s*none#s', $cmp_site_css_v2 ), 'style.css: reduced motion shows the hero rows at once' );
cmp_assert( 1 === preg_match( '#\.r-facts#', $cmp_site_css_v2 ) && 1 === preg_match( '#\.passed#', $cmp_site_css_v2 ), 'style.css: report counters and the passed list are styled' );
// Privacy pages: up to 10 pages, robots.txt and sitemap, the limits.
foreach ( array(
	'confidentialitate/index.html' => array( 'până la 10 pagini publice', 'robots.txt', 'harta site-ului', '5 verificări pe minut și 50 pe zi', '15 pe minut și 150 pe zi', '30 de verificări pe minut și 1000 pe zi' ),
	'en/privacy/index.html'        => array( 'up to 10 public pages', 'robots.txt', 'sitemap', '5 checks a minute and 50 a day', '15 a minute and 150 a day', '30 checks a minute and 1000 a day' ),
) as $page => $needles ) {
	$html = $cmp_site_read( $page );
	$sec  = 1 === preg_match( '#id="instrument".*?id="pag(ini|es)"#s', $html, $pm ) ? $pm[0] : '';
	foreach ( $needles as $needle ) {
		cmp_assert( false !== strpos( $sec, $needle ), "{$page}: the tool section mentions {$needle}" );
	}
	foreach ( array( '10 verificări pe minut', '100 pe zi', '10 checks a minute', '100 a day', 'only the homepage', 'doar pagina principală' ) as $old ) {
		cmp_assert( false === strpos( $sec, $old ), "{$page}: the tool section no longer says {$old}" );
	}
}
$cmp_site_rev = (string) @file_get_contents( $cmp_site_root . '/docs/product/2026-11-20-landing-pentru-revizie.md' );
foreach ( array( 'până la 10 pagini publice', '5 verificări pe minut și 50 pe zi', 'Căutăm paginile site-ului (sitemap)' ) as $needle ) {
	cmp_assert( false !== strpos( $cmp_site_rev, $needle ), "landing review doc: mirrors „{$needle}”" );
}
foreach ( array( 'site/assets/verifica.js', 'site/assets/site.js', 'site/index.html', 'site/confidentialitate/index.html' ) as $f ) {
	$c = (string) @file_get_contents( $cmp_site_root . '/' . $f );
	cmp_assert( false === strpos( $c, "\xe2\x80\x94" ), "{$f}: no em dash" );
	cmp_assert( 0 === preg_match( "#[\xc5\x9f\xc5\xa3\xc5\x9e\xc5\xa2]#u", $c ), "{$f}: ș/ț with comma below, not cedilla" );
}

// The demo: pages under /demo/, only local files. The embed pages (in the landing's
// iframe) are bare: no site header or footer and no style switch of their own.
foreach ( array( 'demo/index.html' => array( 'ro', false ), 'demo/en/index.html' => array( 'en', false ), 'demo/embed/index.html' => array( 'ro', true ), 'demo/en/embed/index.html' => array( 'en', true ) ) as $page => $dm ) {
	list( $lang, $bare ) = $dm;
	$html = $cmp_site_read( $page );
	cmp_assert( '' !== $html, "build-site: writes {$page}" );
	cmp_assert( false !== strpos( $html, '<html lang="' . $lang . '"' ), "{$page}: <html lang=\"{$lang}\">" );
	cmp_assert( false === strpos( $html, '<!-- HEADER' ) && false === strpos( $html, '<!-- FOOTER' ) && false === strpos( $html, '<!-- BARE' ), "{$page}: markers replaced" );
	if ( $bare ) {
		$src = (string) @file_get_contents( $cmp_site_root . '/site/' . $page );
		cmp_assert_eq( substr_count( $src, '<!-- BARE -->' ), 1, "site/{$page}: the BARE marker" );
		cmp_assert( false === strpos( $html, '<header class="site">' ) && false === strpos( $html, '<footer class="site">' ) && false === strpos( $html, 'class="skip"' ), "{$page}: no site header or footer" );
		cmp_assert( false === strpos( $html, 'class="switch"' ) && false === strpos( $html, '?style=' ), "{$page}: no style switch of its own" );
	} else {
		cmp_assert( false !== strpos( $html, '<header class="site">' ) && false !== strpos( $html, '<footer class="site">' ), "{$page}: has the site header and footer" );
	}
	cmp_assert_eq( substr_count( $html, $cmp_site_widget ), $bare ? 0 : 1, "{$page}: feedback widget loader " . ( $bare ? 'absent (bare embed)' : 'present once' ) );
	preg_match_all( '#<script\b[^>]*>#', str_replace( $cmp_site_widget, '', $html ), $sm2 );
	cmp_assert_eq( $sm2[0], array_merge( array( '<script src="/demo/demo.js">', '<script src="/demo/cmp.min.js" defer>' ), $bare ? array() : array( '<script src="/assets/site.js" defer>' ) ), "{$page}: loads only demo.js, the local cmp.min.js" . ( $bare ? '' : ' and site.js' ) );
	preg_match_all( '#<link\b[^>]*rel="stylesheet"[^>]*href="([^"]+)"#', $html, $lm3 );
	cmp_assert( in_array( '/demo/cmp.min.css', $lm3[1], true ), "{$page}: loads the local cmp.min.css" );
	foreach ( $lm3[1] as $href ) {
		cmp_assert( 0 === strpos( $href, '/' ) && 0 !== strpos( $href, '//' ), "{$page}: stylesheet {$href} is local" );
	}
	cmp_assert( 1 === preg_match( '#<button\b[^>]*\bid="demo-reset"#', $html ), "{$page}: the „show again” button" );
	foreach ( $bare ? array() : array( 'minimal', 'brand', 'friendly' ) as $st ) {
		cmp_assert( false !== strpos( $html, '?style=' . $st . '"' ), "{$page}: style switch {$st}" );
	}
	cmp_assert( false === strpos( $html, 'data-cmp-src' ) && false === strpos( $html, 'text/plain' ), "{$page}: no trackers, not even blocked ones" );
}
foreach ( array( 'cmp.min.js', 'cmp.min.css' ) as $f ) {
	cmp_assert( is_file( $cmp_site_out1 . '/demo/' . $f ) && hash_file( 'sha256', $cmp_site_out1 . '/demo/' . $f ) === hash_file( 'sha256', $cmp_site_root . '/standalone/' . $f ), "build-site: demo/{$f} is standalone/{$f}" );
}
cmp_assert( ! file_exists( $cmp_site_root . '/site/demo/cmp.min.js' ), 'site/demo/: cmp.min.js is not kept in site/ (copied by the build)' );
$cmp_site_demojs = $cmp_site_read( 'demo/demo.js' );
cmp_assert( false !== strpos( $cmp_site_demojs, 'CMP_CONFIG' ), 'demo.js: sets CMP_CONFIG' );
cmp_assert( false !== strpos( $cmp_site_demojs, "'/demo/'" ), 'demo.js: keeps the consent cookie on /demo/' );
cmp_assert( 1 === preg_match( "#catch \\(e\\) \\{ cfg\\.mode = 'light'; \\}#", $cmp_site_demojs ), 'demo.js: falls back to Light mode (no cookie) when the path cannot be kept' );

// Editor and system junk never reaches the output.
$cmp_site_junk = array( 'site/.cmp-test.swp', 'site/assets/._cmp-test.css', 'site/cmp-test.html~', 'site/demo/.DS_Store' );
foreach ( $cmp_site_junk as $f ) {
	file_put_contents( $cmp_site_root . '/' . $f, 'x' );
}
$cmp_site_out9 = $cmp_site_tmp . '/out9';
list( $rc, $out ) = cmp_site_run( 'bash bin/build-site.sh --out ' . escapeshellarg( $cmp_site_out9 ), $cmp_site_env );
foreach ( $cmp_site_junk as $f ) {
	@unlink( $cmp_site_root . '/' . $f );
}
cmp_assert_eq( $rc, 0, 'build-site with junk files in site/: exits 0' . ( 0 === $rc ? '' : ' — ' . trim( $out ) ) );
foreach ( $cmp_site_junk as $f ) {
	cmp_assert( ! file_exists( $cmp_site_out9 . '/' . substr( $f, 5 ) ), "build-site: {$f} is not copied" );
}
foreach ( array( 'innerHTML', 'outerHTML', 'document.write', 'eval(' ) as $bad ) {
	cmp_assert( false === strpos( $cmp_site_demojs, $bad ), "demo.js: does not use {$bad}" );
}

// Privacy pages: the tool, no statistics, the demo cookie.
foreach ( array( 'confidentialitate/index.html' => array( 'Verifică-ți site-ul', 'o zi', 'statistici' ), 'en/privacy/index.html' => array( 'Check your site', 'a day', 'statistics' ) ) as $page => $words ) {
	$html = $cmp_site_read( $page );
	foreach ( array_merge( $words, array( 'id="instrument"', '/api/verifica.php', '/demo/', 'cmp_consent', 'SHA-256' ) ) as $needle ) {
		cmp_assert( false !== strpos( $html, $needle ), "{$page}: mentions {$needle}" );
	}
}

// ---- rt.md elements: favicon, RT symbol (rt.md .sym), phone menu and motion (site.js) ------------
$cmp_site_fav   = '/assets/favicon/biscuit-192.png';
$cmp_site_sjs   = '<script src="/assets/site.js" defer></script>';
$cmp_site_badge = '<svg class="sym" viewBox="168.695 162.277 46.734 60.156" aria-hidden="true" focusable="false"><path fill="currentColor" d="M 208.25 177.652344 ';
foreach ( array_merge( array_keys( $cmp_site_pages ), array( 'demo/index.html', 'demo/en/index.html', 'demo/embed/index.html', 'demo/en/embed/index.html' ) ) as $page ) {
	$html = $cmp_site_read( $page );
	$bare = false !== strpos( $page, '/embed/' );
	cmp_assert( false !== strpos( $html, '<link rel="icon" href="/favicon.ico" sizes="32x32">' . "\n" . '<link rel="icon" type="image/png" sizes="192x192" href="' . $cmp_site_fav . '">' ) && false !== strpos( $html, '<link rel="manifest" href="/site.webmanifest">' ), "{$page}: links the favicon" );
	cmp_assert( false !== strpos( $html, '<link rel="apple-touch-icon" href="/assets/favicon/biscuit-180.png">' ), "{$page}: links the apple-touch-icon" );
	if ( $bare ) {
		cmp_assert( false === strpos( $html, '/assets/site.js' ), "{$page}: a bare page does not load site.js" );
		continue;
	}
	cmp_assert_eq( substr_count( $html, $cmp_site_sjs ), 1, "{$page}: loads /assets/site.js once, deferred" );
	cmp_assert( 1 === preg_match( '#<header class="site">(.*?)</header>#s', $html, $hm ) && 1 === preg_match( '#^\s*<div class="wrap">\s*<p class="brand"><a href="/(?:en/)?"[^>]*><img src="/assets/icon/biscuit-88\.webp" width="40" height="40" alt="">BiscuitWP</a></p>#', $hm[1] ) && false === strpos( $hm[1], 'rt.md/"' ), "{$page}: header starts with the biscuit and the name (the RT symbol is in the footer)" );
	cmp_assert( isset( $hm[1] ) && false === strpos( $hm[1], 'class="badge"' ) && false === strpos( $hm[1], 'xmlns' ), "{$page}: header uses the current symbol, not the old framed badge" );
	cmp_assert( isset( $hm[1] ) && false !== strpos( $hm[1], '>BiscuitWP</a>' ) && 1 === preg_match( '#<nav\b#', $hm[1] ), "{$page}: header keeps the product name and the inline nav" );
	cmp_assert( 1 === preg_match( '#<footer class="site">(.*?)</footer>#s', $html, $fm ) && 1 === preg_match( '#<a href="https://rt\.md/"[^>]*>' . preg_quote( $cmp_site_badge, '#' ) . '.*?</svg>RT Design Studio</a>#s', $fm[1] ), "{$page}: footer shows the RT symbol next to RT Design Studio" );
}
foreach ( array( 'site' => $cmp_site_root . '/site', 'out' => $cmp_site_out1 ) as $label => $dir ) {
	$png = (string) @file_get_contents( $dir . $cmp_site_fav );
	$sz  = '' === $png ? false : @getimagesizefromstring( $png );
	cmp_assert( false !== $sz && 192 === $sz[0] && 192 === $sz[1] && IMAGETYPE_PNG === $sz[2], "{$label}{$cmp_site_fav}: a 192x192 PNG" );
}
$cmp_site_site_js = (string) @file_get_contents( $cmp_site_root . '/site/assets/site.js' );
cmp_assert( '' !== $cmp_site_site_js && $cmp_site_site_js === $cmp_site_read( 'assets/site.js' ), 'build-site: copies assets/site.js as is' );
cmp_assert( 0 === preg_match( '#(https?:)?//[a-z0-9]#i', $cmp_site_site_js ), 'site.js: no external URL' );
foreach ( array( 'prefers-reduced-motion', "'aria-expanded'", "'aria-controls'", "'Escape'", 'IntersectionObserver', "classList.add('js')" ) as $need ) {
	cmp_assert( false !== strpos( $cmp_site_site_js, $need ), "site.js: uses {$need}" );
}
foreach ( array( 'innerHTML', 'outerHTML', 'document.write', 'eval(', 'umami', 'track(', "\xe2\x80\x94" ) as $bad ) {
	cmp_assert( false === strpos( $cmp_site_site_js, $bad ), "site.js: does not use {$bad}" );
}
cmp_assert( 1 === preg_match( "#'Meniu'#", $cmp_site_site_js ) && 1 === preg_match( "#'Menu'#", $cmp_site_site_js ), 'site.js: the menu button label in RO and EN' );
// Without JS everything is visible: the hidden-until-revealed states exist only under html.js.
$cmp_site_css = (string) @file_get_contents( $cmp_site_root . '/site/style.css' );
cmp_assert( 1 === preg_match( '#\.js \.fd\s*\{[^}]*opacity#', $cmp_site_css ), 'style.css: reveal (.fd) only under .js' );
cmp_assert( 1 === preg_match( '#\.js \.k\s*\{[^}]*opacity#', $cmp_site_css ), 'style.css: heading weight animation (.k) only under .js' );
cmp_assert( 0 === preg_match( '#(^|[{},;]|\*/)\s*\.(fd|k|blur)\b#', preg_replace( '#/\*.*?\*/#s', '', $cmp_site_css ) ), 'style.css: no .fd/.k/.blur rule outside .js' );
cmp_assert( 1 === preg_match( '#@media \(prefers-reduced-motion: reduce\)\s*\{.*?\.js \.fd.*?\.js \.k#s', $cmp_site_css ), 'style.css: reduced motion shows .fd and .k at once' );
cmp_assert( 1 === preg_match( '#\.menu-btn\s*\{[^}]*display:\s*none#', $cmp_site_css ), 'style.css: the menu button is hidden on desktop' );
// Fixed header (rt.md .top): only with JS (without it the header stays in the page flow),
// a background that fades in once scrolled, room for it above the content and the anchors.
cmp_assert( 1 === preg_match( '#\.js header\.site\s*\{[^}]*position:\s*fixed#', $cmp_site_css ), 'style.css: the header is fixed, under .js' );
cmp_assert( 1 === preg_match( '#\.js header\.site\s*\{[^}]*pointer-events:\s*none#', $cmp_site_css ) && 1 === preg_match( '#\.js header\.site \.wrap > \*\s*\{[^}]*pointer-events:\s*auto#', $cmp_site_css ), 'style.css: the fixed header lets clicks through except on its links and button' );
cmp_assert( 1 === preg_match( '#\.js header\.site\.scrolled::before\s*\{[^}]*opacity:\s*1#', $cmp_site_css ), 'style.css: the header background appears when scrolled' );
cmp_assert( 1 === preg_match( '#\.js body\s*\{[^}]*padding-top:\s*var\(--head\)#', $cmp_site_css ), 'style.css: the content starts under the fixed header' );
cmp_assert( 1 === preg_match( '#\.js main \[id\]\s*\{[^}]*scroll-margin-top#', $cmp_site_css ), 'style.css: anchor jumps land below the fixed header' );
cmp_assert( 1 === preg_match( "#classList\.toggle\('scrolled'#", $cmp_site_site_js ), 'site.js: toggles .scrolled on the header' );

// ---- Determinism ------------------------------------------------------------------
list( $rc, $out ) = cmp_site_run( 'bash bin/build-site.sh --out ' . escapeshellarg( $cmp_site_out2 ), $cmp_site_env );
cmp_assert_eq( $rc, 0, 'build-site second run: exits 0' );
$f1 = cmp_site_files( $cmp_site_out1 );
cmp_assert_eq( cmp_site_files( $cmp_site_out2 ), $f1, 'determinism: same file list' );
$same = true;
foreach ( $f1 as $f ) {
	if ( (string) @file_get_contents( $cmp_site_out1 . '/' . $f ) !== (string) @file_get_contents( $cmp_site_out2 . '/' . $f ) ) {
		$same = false;
		echo "  differs: {$f}\n";
	}
}
cmp_assert( $same && $f1, 'determinism: identical files with the same CMP_BUILD_NOW' );

// A rebuild into the same directory replaces it (no stale files).
file_put_contents( $cmp_site_out2 . '/stale.html', 'x' );
list( $rc, $out ) = cmp_site_run( 'bash bin/build-site.sh --out ' . escapeshellarg( $cmp_site_out2 ), $cmp_site_env );
cmp_assert_eq( $rc, 0, 'build-site rebuild over a previous build: exits 0' );
cmp_assert( ! file_exists( $cmp_site_out2 . '/stale.html' ), 'build-site rebuild: stale files removed' );

// ---- RSS cap: 20 newest -------------------------------------------------------------
$big = "# Changelog\n\n";
for ( $i = 25; $i >= 1; $i-- ) {
	$big .= sprintf( "## 2.%d.0 – 2026-%02d-%02d\n\n- Punct %d.\n\n", $i, (int) ceil( $i / 3 ), ( $i % 28 ) + 1, $i );
}
$cmp_site_big = $cmp_site_tmp . '/CHANGELOG-big.md';
file_put_contents( $cmp_site_big, $big );
$cmp_site_out4 = $cmp_site_tmp . '/out4';
list( $rc, $out ) = cmp_site_run( 'bash bin/build-site.sh --out ' . escapeshellarg( $cmp_site_out4 ), array_merge( $cmp_site_env, array( 'CMP_CHANGELOG' => $cmp_site_big ) ) );
cmp_assert_eq( $rc, 0, 'build-site, 25 versions: exits 0' );
$x = @simplexml_load_string( (string) @file_get_contents( $cmp_site_out4 . '/changelog/feed.xml' ) );
cmp_assert( false !== $x && 20 === count( $x->channel->item ), 'feed: at most 20 items' );
cmp_assert( false !== $x && 'BiscuitWP 2.25.0' === (string) $x->channel->item[0]->title, 'feed: newest of 25 first' );
cmp_assert( false !== strpos( (string) @file_get_contents( $cmp_site_out4 . '/changelog/index.html' ), 'id="v2-1-0"' ), 'changelog page: all 25 versions (oldest included)' );

// ---- Refusals ---------------------------------------------------------------------
list( $rc, $out ) = cmp_site_run( 'bash bin/build-site.sh --out', $cmp_site_env );
cmp_assert( 0 !== $rc, 'build-site: --out without a directory fails' );
list( $rc, $out ) = cmp_site_run( 'bash bin/build-site.sh --bogus', $cmp_site_env );
cmp_assert( 0 !== $rc, 'build-site: unknown option fails' );
$cmp_site_foreign = $cmp_site_tmp . '/foreign';
mkdir( $cmp_site_foreign );
file_put_contents( $cmp_site_foreign . '/keep.txt', 'mine' );
list( $rc, $out ) = cmp_site_run( 'bash bin/build-site.sh --out ' . escapeshellarg( $cmp_site_foreign ), $cmp_site_env );
cmp_assert( 0 !== $rc, 'build-site: refuses a non-empty directory that is not a site build' );
cmp_assert( is_file( $cmp_site_foreign . '/keep.txt' ), 'build-site: the foreign directory is left untouched' );
list( $rc, $out ) = cmp_site_run( 'bash bin/build-site.sh --out ' . escapeshellarg( $cmp_site_tmp . '/out5' ), array_merge( $cmp_site_env, array( 'CMP_CHANGELOG' => $cmp_site_tmp . '/missing.md' ) ) );
cmp_assert( 0 !== $rc, 'build-site: a missing CHANGELOG fails' );
$cmp_site_badmf = $cmp_site_tmp . '/bad-manifest.json';
file_put_contents( $cmp_site_badmf, '{not json' );
list( $rc, $out ) = cmp_site_run( 'bash bin/build-site.sh --out ' . escapeshellarg( $cmp_site_tmp . '/out6' ), array_merge( $cmp_site_env, array( 'CMP_STABLE_MANIFEST' => $cmp_site_badmf ) ) );
cmp_assert( 0 !== $rc, 'build-site: an unreadable stable manifest fails' );

// ---- final UI fixes: titles, labels, strict input, failure labels, privacy facts ----------
$cmp_f_ro = $cmp_site_read( 'index.html' );
$cmp_f_en = $cmp_site_read( 'en/index.html' );
cmp_assert( false !== strpos( $cmp_f_ro, '<title>BiscuitWP. Cookie-uri cu acord, nu cu noroc.</title>' ), 'landing RO: the title is the slogan with the product name' );
cmp_assert( false !== strpos( $cmp_f_en, '<title>BiscuitWP. Cookies by consent, not by luck.</title>' ), 'landing EN: the title is the slogan with the product name' );
foreach ( array( 'index.html', 'en/index.html' ) as $cmp_f_page ) {
	$cmp_f_html = $cmp_site_read( $cmp_f_page );
	cmp_assert( false === strpos( $cmp_f_html, 'Consent Manager Pro' ) && 0 === preg_match( '#<summary>[^<]*(?:„Pro”|"Pro"|«Pro»)#u', $cmp_f_html ), "{$cmp_f_page}: only the BiscuitWP name, no FAQ about \"Pro\"" );
}
cmp_assert( false === stripos( $cmp_f_ro, 'conform' ), 'landing RO: never „conform”' );
cmp_assert( false === stripos( $cmp_f_en, 'compliant' ) && false === stripos( $cmp_f_en, 'compliance' ), 'landing EN: never "compliant"' );
cmp_assert( false === stripos( $cmp_f_ro . $cmp_f_en, 'og:title' ) || ( false === stripos( $cmp_f_ro, 'og:title" content="BiscuitWP · Cookie-uri conf' ) ), 'landing: no og claim of conformity' );
$cmp_f_js = (string) file_get_contents( $cmp_site_root . '/site/assets/verifica.js' );
cmp_assert( false !== strpos( $cmp_f_js, "law: 'Baza legală: '" ) && false !== strpos( $cmp_f_js, "law: 'Legal basis: '" ), 'verifica.js: the label before the law is „Baza legală:” / "Legal basis:"' );
cmp_assert( false === strpos( $cmp_f_js, "law: 'Legea: '" ) && false === strpos( $cmp_f_js, "law: 'Law: '" ), 'verifica.js: no doubled „Legea: Legea”' );
foreach ( array( 'timeout' => 'nu a răspuns la timp', 'too_large' => 'pagina e prea mare', 'other_host' => 'duce spre alt site', 'limit' => 'limita de cereri atinsă', 'fetch_failed' => 'nu a putut fi citită' ) as $code => $label ) {
	cmp_assert( false !== strpos( $cmp_f_js, $code . ": '" . $label . "'" ), "verifica.js: RO page failure label for {$code}" );
}
foreach ( array( 'timeout' => 'did not answer in time', 'too_large' => 'the page is too big', 'other_host' => 'leads to another site', 'limit' => 'request limit reached', 'not_html' => 'could not be read' ) as $code => $label ) {
	cmp_assert( false !== strpos( $cmp_f_js, $code . ": '" . $label . "'" ), "verifica.js: EN page failure label for {$code}" );
}
foreach ( array( 'index.html' => 'Doar domeniul, fără căi sau parametri.', 'en/index.html' => 'Domain only, no paths or parameters.' ) as $page => $help ) {
	$html = $cmp_site_read( $page );
	cmp_assert( 1 === preg_match( '#<input\b[^>]*\bid="verifica-url"[^>]*>#', $html, $im ), "{$page}: url input" );
	foreach ( array( 'inputmode="url"', 'autocapitalize="off"', 'spellcheck="false"', 'maxlength="300"', 'aria-describedby="verifica-eroare verifica-nota"' ) as $attr ) {
		cmp_assert( false !== strpos( $im[0], $attr ), "{$page}: url input has {$attr}" );
	}
	cmp_assert( false !== strpos( $html, 'id="verifica-nota" class="muted small">' . $help . ' ' ), "{$page}: help text under the field" );
	cmp_assert( 1 === preg_match( '#<p\b[^>]*\bid="verifica-eroare"[^>]*\shidden[^>]*>\s*</p>#', $html ), "{$page}: an empty, hidden place for the address error" );
}
cmp_assert( false !== strpos( $cmp_f_js, 'Scrie doar adresa site-ului, de exemplu exemplu.md. Verificăm singuri pagina principală și celelalte pagini.' ), 'verifica.js: RO bad-address message' );
cmp_assert( false !== strpos( $cmp_f_js, 'Enter only the site address, for example example.md. We check the homepage and the other pages ourselves.' ), 'verifica.js: EN bad-address message' );
// The validator, run in node (no DOM: the script only exposes window.cmpSiteAddress).
$cmp_f_node = trim( (string) @shell_exec( 'command -v node' ) );
if ( '' === $cmp_f_node ) {
	cmp_assert( true, 'node not found: the validator cases are skipped' );
} else {
	$cmp_f_cases = array(
		'exemplu.md' => 'exemplu.md', 'www.exemplu.md' => 'www.exemplu.md', 'https://exemplu.md' => 'exemplu.md',
		'http://Exemplu.MD/' => 'exemplu.md', '  exemplu.md  ' => 'exemplu.md', 'bălți.md' => 'xn--blți-' ,
		'exemplu.md/pagina' => '', 'exemplu.md//' => '', 'exemplu.md?a=1' => '', 'exemplu.md#x' => '', 'exemplu.md:8080' => '',
		'user@exemplu.md' => '', 'http://user:pw@exemplu.md' => '', '127.0.0.1' => '', '8.8.8.8' => '', '[::1]' => '', '2130706433' => '',
		'0x7f.1' => '', 'localhost' => '', 'intranet' => '', 'exemplu' => '', 'exem_plu.md' => '', '-exemplu.md' => '', 'exemplu-.md' => '',
		'exemplu.m1' => '', 'exemplu.123' => '', 'exemplu.md.' => '', 'ftp://exemplu.md' => '', 'exemplu .md' => '', 'a.local' => '', 'a.lan' => '',
		'xn--80ak6aa92e.com' => 'xn--80ak6aa92e.com', 'exemplu.xn--p1ai' => 'exemplu.xn--p1ai', str_repeat( 'a', 250 ) . '.md' => '', str_repeat( 'a', 64 ) . '.md' => '',
	);
	$cmp_f_script = 'global.window={};global.document={documentElement:{lang:"ro"},getElementById:function(){return null;},querySelectorAll:function(){return [];},querySelector:function(){return null;}};'
		. 'require(process.argv[1]);var c=JSON.parse(process.argv[2]),o={};Object.keys(c).forEach(function(k){o[k]=window.cmpSiteAddress(k);});console.log(JSON.stringify(o));';
	$cmp_f_out = (string) @shell_exec( escapeshellarg( $cmp_f_node ) . ' -e ' . escapeshellarg( $cmp_f_script ) . ' ' . escapeshellarg( $cmp_site_root . '/site/assets/verifica.js' ) . ' ' . escapeshellarg( json_encode( array_fill_keys( array_keys( $cmp_f_cases ), 1 ) ) ) . ' 2>&1' );
	$cmp_f_got = json_decode( trim( $cmp_f_out ), true );
	cmp_assert( is_array( $cmp_f_got ), 'verifica.js: the validator runs in node' );
	foreach ( $cmp_f_cases as $in => $want ) {
		$got = is_array( $cmp_f_got ) && array_key_exists( $in, $cmp_f_got ) ? $cmp_f_got[ $in ] : null;
		$ok  = '' === $want ? '' === $got : ( 'xn--blți-' === $want ? ( is_string( $got ) && 0 === strpos( $got, 'xn--' ) ) : $got === $want );
		cmp_assert( $ok, 'verifica.js validator: ' . json_encode( $in, JSON_UNESCAPED_UNICODE ) . ( '' === $want ? ' refused' : ' accepted' ) );
	}
}
cmp_assert( false !== strpos( $cmp_f_js, 'Ne-am oprit după ' ) && false !== strpos( $cmp_f_js, 'iar o verificare are o limită de cereri.' ) && false !== strpos( $cmp_f_js, ', ca verificarea să nu dureze prea mult.' ), 'verifica.js: RO stopped notes' );
cmp_assert( false !== strpos( $cmp_f_js, 'a request limit.' ) && false !== strpos( $cmp_f_js, 'so the check does not take too long.' ) && false !== strpos( $cmp_f_js, 'pages.stopped' ), 'verifica.js: EN stopped notes, reads pages.stopped' );
cmp_assert( false !== strpos( $cmp_f_js, '!stopped && total > checked && checked >= 10' ), 'verifica.js: the total sentence shows only when we checked 10 pages and did not stop early' );
// The privacy pages say what the server does.
foreach ( array( 'confidentialitate/index.html' => array( 'primele 200 de intrări', 'cel mult 25 de cereri', 'cel mult 30 de secunde', '::ffff:a.b.c.d', 'cel mult 3 în același timp', '15 pe minut și 150 pe zi', '30 de verificări pe minut și 1000 pe zi', 'BiscuitWP-Check/1.0 (+https://biscuitwp.rt.md/)', 'contoarele' ),
	'en/privacy/index.html' => array( 'first 200 entries', 'at most 25 requests', 'at most 30 seconds', '::ffff:a.b.c.d', 'at most 3 at the same time', '15 a minute and 150 a day', '30 checks a minute and 1000 a day', 'BiscuitWP-Check/1.0 (+https://biscuitwp.rt.md/)', 'the counters' ) ) as $page => $facts ) {
	$html = $cmp_site_read( $page );
	foreach ( $facts as $f ) {
		cmp_assert( false !== strpos( $html, $f ), "{$page}: tool section says „{$f}”" );
	}
}
$cmp_f_doc = (string) file_get_contents( $cmp_site_root . '/docs/product/2026-11-20-landing-pentru-revizie.md' );
cmp_assert( false !== strpos( $cmp_f_doc, 'primele 200 de intrări' ) && false !== strpos( $cmp_f_doc, 'cel mult 25 de cereri' ) && false !== strpos( $cmp_f_doc, '::ffff:a.b.c.d' ), 'revision doc mirrors the privacy tool section' );
cmp_assert( false === stripos( $cmp_f_doc, 'fie conform' ), 'revision doc: no „conform”' );

// ---- release/ untouched -----------------------------------------------------------
cmp_assert_eq( cmp_site_tree_hash( $cmp_site_root . '/release' ), $cmp_site_release_before, 'build-site tests: release/ unchanged' );
cmp_assert_eq( is_dir( $cmp_site_root . '/release/site' ), $cmp_site_release_site, 'build-site tests: release/site not created' );

cmp_site_rmdir( $cmp_site_tmp );

// The RT feedback widget loader is on every page with the site footer, and only there.
foreach ( cmp_site_files( $cmp_site_out1 ) as $f ) {
	if ( '.html' !== substr( $f, -5 ) ) { continue; }
	$h = (string) file_get_contents( $cmp_site_out1 . '/' . $f );
	cmp_assert_eq( substr_count( $h, $cmp_site_widget ), false !== strpos( $h, '<footer class="site">' ) ? 1 : 0, "out/{$f}: feedback widget loader with the footer only" );
}

// ---- „Legea 195/2024 pe scurt”: the checklist page in two languages ----------------
$cmp_site_law = array( 'legea-195/index.html' => 'ro', 'en/law-195/index.html' => 'en' );
$cmp_site_law_n = null;
foreach ( $cmp_site_law as $f => $lang ) {
	$src = (string) @file_get_contents( $cmp_site_root . '/site/' . $f );
	cmp_assert( '' !== $src, "site/{$f} exists" );
	cmp_assert( false !== strpos( $src, '<html lang="' . $lang . '"' ) && false !== strpos( $src, '<body class="checklist">' ), "site/{$f}: lang {$lang}, checklist body" );
	cmp_assert( false !== strpos( $src, '<!-- HEADER ro=/legea-195/ en=/en/law-195/ -->' ) && 1 === substr_count( $src, '<!-- FOOTER -->' ), "site/{$f}: header and footer markers" );
	cmp_assert( false === strpos( $src, "\xe2\x80\x94" ), "site/{$f}: no em dash" );
	cmp_assert( 1 === preg_match( '#<button type="button" class="btn btn-ghost" data-print>[^<]+</button>#', $src ), "site/{$f}: the Save as PDF button" );
	preg_match_all( '#<li><label><input type="checkbox"> <strong>[^<]+</strong></label>.*?<p class="law">[^<]+</p>.*?</li>#s', $src, $items );
	$n = count( $items[0] );
	cmp_assert( $n >= 18, "site/{$f}: at least 18 checklist items, each with a checkbox and a law reference ({$n})" );
	cmp_assert( null === $cmp_site_law_n || $cmp_site_law_n === $n, "site/{$f}: same number of items in every language" );
	$cmp_site_law_n = $n;
	foreach ( array( '195/2024', '1 000 000 MDL', '2 000 000 MDL', '72', '250', '14', 'https://www.legis.md/cautare/getResults?doc_id=155899&amp;lang=ro', 'https://datepersonale.md/wp-content/uploads/2024/09/Law-no.-195-2024-on-personal-data-protection-1.pdf', '<time datetime="2026-11-23">' ) as $need ) {
		cmp_assert( false !== strpos( $src, $need ), "site/{$f}: has {$need}" );
	}
}
foreach ( array( 'index.html' => '/legea-195/', 'en/index.html' => '/en/law-195/' ) as $f => $href ) {
	cmp_assert( false !== strpos( (string) @file_get_contents( $cmp_site_root . '/site/' . $f ), '<p class="law-link">' ) && false !== strpos( (string) @file_get_contents( $cmp_site_root . '/site/' . $f ), 'href="' . $href . '"' ), "site/{$f}: the law section links {$href}" );
}
cmp_assert( false !== strpos( (string) @file_get_contents( $cmp_site_root . '/site/assets/site.js' ), "querySelectorAll('[data-print]')" ), 'site.js: data-print buttons open the print dialog' );
cmp_assert( 1 === preg_match( '/@media print\s*\{[^@]*\.checklist header\.site/s', (string) @file_get_contents( $cmp_site_root . '/site/style.css' ) ), 'style.css: the checklist prints without header and footer' );

// The Russian pages are gone (#754): nothing links to them and their addresses redirect.
foreach ( cmp_site_files( ( $cmp_site_root . '/site' ) ) as $f ) {
	if ( preg_match( '/\.(html|js)$/', $f ) ) {
		cmp_assert( 0 === preg_match( '#(href|src)="[^"]*/ru/|hreflang="ru"#', (string) file_get_contents( ( $cmp_site_root . '/site' ) . '/' . $f ) ), "site/{$f}: no link to a Russian page" );
	}
}
cmp_assert( is_file( ( $cmp_site_root . '/site' ) . '/.htaccess' ) && ! is_dir( ( $cmp_site_root . '/site' ) . '/ru' ) && ! is_dir( ( $cmp_site_root . '/site' ) . '/demo/ru' ), 'site: the redirects of the old Russian addresses, no Russian pages' );
