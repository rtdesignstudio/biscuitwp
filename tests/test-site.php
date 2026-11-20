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
foreach ( array( 'api/verifica.php', 'api/.htaccess', 'api/lib/check.php', 'api/lib/signatures.php', 'api/lib/fetch.php', 'api/lib/ratelimit.php', 'api/lib/endpoint.php' ) as $f ) {
	cmp_assert( is_file( $cmp_site_out1 . '/' . $f ) && hash_file( 'sha256', $cmp_site_out1 . '/' . $f ) === hash_file( 'sha256', $cmp_site_root . '/site/' . $f ), "build-site: copies site/{$f} as is" );
}
cmp_assert( is_file( $cmp_site_out1 . '/api/services.json' ) && hash_file( 'sha256', $cmp_site_out1 . '/api/services.json' ) === hash_file( 'sha256', $cmp_site_root . '/data/services.json' ), 'build-site: api/services.json is data/services.json' );

$cmp_site_read = function ( $f ) use ( $cmp_site_out1 ) {
	$p = $cmp_site_out1 . '/' . $f;
	return is_file( $p ) ? (string) file_get_contents( $p ) : '';
};

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
	cmp_assert_eq( (string) $it->title, 'Consent Manager Pro 1.2.3', 'feed: newest item title' );
	cmp_assert_eq( (string) $it->link, 'https://consent.rt.md/changelog/#v1-2-3', 'feed: item link to the anchor' );
	cmp_assert( 1 === preg_match( '/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun), \d{2} (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) \d{4} \d{2}:\d{2}:\d{2} [+-]\d{4}$/', (string) $it->pubDate ), 'feed: pubDate is RFC 822' );
	cmp_assert_eq( strtotime( (string) $it->pubDate ), strtotime( '2026-10-05T00:00:00Z' ), 'feed: pubDate is the release date' );
	cmp_assert( false !== strpos( (string) $it->description, '&lt;b&gt;&amp;&lt;/b&gt;' ), 'feed: description keeps the item text escaped as HTML' );
	cmp_assert( false !== strpos( (string) $it->description, '<li>' ), 'feed: description is a list' );
	cmp_assert_eq( (string) $xml->channel->item[1]->title, 'Consent Manager Pro 1.1.0', 'feed: second item' );
}

// ---- security.txt -------------------------------------------------------------
$st = $cmp_site_read( '.well-known/security.txt' );
foreach ( array(
	'Contact: mailto:security@rt.md',
	'Contact: https://github.com/rtdesignstudio/consent-manager-pro/security/advisories/new',
	'Expires: ' . gmdate( 'Y-m-d\TH:i:s\Z', $cmp_site_now + 180 * 86400 ),
	'Preferred-Languages: ro, en, ru',
	'Canonical: https://consent.rt.md/.well-known/security.txt',
	'Policy: https://consent.rt.md/en/security/',
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

// ---- Every page --------------------------------------------------------------------
foreach ( $cmp_site_pages as $page => $lang ) {
	$html = $cmp_site_read( $page );
	cmp_assert( false !== strpos( $html, '<html lang="' . $lang . '"' ), "{$page}: <html lang=\"{$lang}\">" );
	cmp_assert( 1 === preg_match( '#<link rel="stylesheet" href="[^"]*style\.css"#', $html ), "{$page}: links style.css" );
	cmp_assert( false !== strpos( $html, '<meta name="viewport"' ), "{$page}: viewport meta" );
	cmp_assert( false !== strpos( $html, 'https://github.com/rtdesignstudio/consent-manager-pro' ), "{$page}: links the GitHub repo" );
}

// ---- Shared header and footer, style assets (Task 1 of the landing) ---------------
// page => [ lang, current nav key, language-switch target ]
$cmp_site_frame = array(
	'index.html'                   => array( 'ro', 'home', '/en/' ),
	'en/index.html'                => array( 'en', 'home', '/' ),
	'securitate/index.html'        => array( 'ro', 'security', '/en/security/' ),
	'en/security/index.html'       => array( 'en', 'security', '/securitate/' ),
	'confidentialitate/index.html' => array( 'ro', 'privacy', '/en/privacy/' ),
	'en/privacy/index.html'        => array( 'en', 'privacy', '/confidentialitate/' ),
	'changelog/index.html'         => array( 'ro', 'changelog', '/en/' ),
);
$cmp_site_nav = array(
	'ro' => array( 'home' => '/', 'security' => '/securitate/', 'privacy' => '/confidentialitate/', 'changelog' => '/changelog/' ),
	'en' => array( 'home' => '/en/', 'security' => '/en/security/', 'privacy' => '/en/privacy/', 'changelog' => '/changelog/' ),
);
foreach ( $cmp_site_frame as $page => $fr ) {
	list( $lang, $cur, $alt ) = $fr;
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
		cmp_assert( false !== strpos( $part, 'https://github.com/rtdesignstudio/consent-manager-pro' ), "{$page}: {$where} links GitHub" );
	}
	cmp_assert( false !== strpos( $head, 'href="' . $cmp_site_nav[ $lang ]['home'] . '"' ), "{$page}: header links home" );
	cmp_assert( false !== strpos( $head, 'href="' . $alt . '"' ), "{$page}: header has the language switch to {$alt}" );
	cmp_assert( false !== strpos( $head, 'hreflang="' . ( 'ro' === $lang ? 'en' : 'ro' ) . '"' ), "{$page}: language switch has hreflang" );
	cmp_assert( false !== strpos( $foot, 'href="' . $alt . '"' ) && false !== strpos( $foot, 'hreflang="' . ( 'ro' === $lang ? 'en' : 'ro' ) . '"' ), "{$page}: footer has the language switch to {$alt}" );
	cmp_assert( 1 === preg_match( '#<a href="' . preg_quote( $cmp_site_nav[ $lang ][ $cur ], '#' ) . '"[^>]*aria-current="page"#', $head ), "{$page}: current page marked in the nav" );
	cmp_assert_eq( substr_count( $head, 'aria-current="page"' ), 1, "{$page}: exactly one current item" );
	cmp_assert( false !== strpos( $foot, 'href="https://rt.md/"' ) && false !== strpos( $foot, 'RT Design Studio' ), "{$page}: footer links RT Design Studio" );
	cmp_assert( false !== strpos( $foot, 'GPL-2.0-or-later' ), "{$page}: footer states the licence" );
	cmp_assert( 1 === preg_match( '#<link rel="stylesheet" href="[^"]*tokens\.css"#', $html ) && 1 === preg_match( '#<link rel="stylesheet" href="[^"]*typography\.css"#', $html ), "{$page}: links tokens.css and typography.css" );
	if ( 'ro' === $lang ) {
		cmp_assert( false === strpos( $src, "\xe2\x80\x94" ), "site/{$page}: no em dash in Romanian text" );
	}
}
foreach ( array( 'header.ro.html', 'footer.ro.html', 'header.en.html', 'footer.en.html' ) as $f ) {
	cmp_assert( is_file( $cmp_site_root . '/site/partials/' . $f ), "site/partials/{$f} exists" );
}
foreach ( array( 'partials/header.ro.html', 'partials/footer.ro.html', 'demo/index.html', 'demo/embed/index.html' ) as $f ) {
	cmp_assert( false === strpos( (string) @file_get_contents( $cmp_site_root . '/site/' . $f ), "\xe2\x80\x94" ), "site/{$f}: no em dash" );
}
cmp_assert( ! is_dir( $cmp_site_out1 . '/partials' ), 'build-site: partials are not copied to the output' );
foreach ( array( 'assets/tokens.css', 'assets/typography.css', 'fonts/inter-tight/OFL.txt', 'fonts/inter-tight/inter-tight-normal-latin.woff2', 'fonts/inter-tight/inter-tight-normal-latin-ext.woff2' ) as $f ) {
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
	cmp_assert( false !== strpos( $css, 'prefers-color-scheme: dark' ), "{$label}/style.css: dark mode" );
	cmp_assert( false !== strpos( $css, ':focus-visible' ), "{$label}/style.css: visible focus" );
}

// No external resources, in the sources and in the output. The only scripts are
// our own files, loaded from this site, with no inline code (Task 4: the tool and the demo).
$cmp_site_ext = array(
	'inline or external <script' => '/<script(?![^>]*\bsrc="\/(?!\/)[A-Za-z0-9\/_.-]+\.js"( defer)?><\/script>)/i',
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
	foreach ( array( 'consent.rt.md', 'github.com', 'fonts.googleapis.com', 'fonts.gstatic.com', 'i.ytimg.com', 'youtube-nocookie.com', 'cmp_consent', 'cmp_svc', 'SHA-256', '13', 'security@rt.md', 'rt.md/cmp/', 'standalone/README.md' ) as $needle ) {
		cmp_assert( false !== strpos( $html, $needle ), "{$page}: mentions {$needle}" );
	}
}

// ---- Landing page, the tool and the demo (Task 4 of the landing) -------------------
$cmp_site_repo = 'https://github.com/rtdesignstudio/consent-manager-pro/releases';
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
$cmp_site_badbeta = $cmp_site_tmp . '/bad-beta.json';
file_put_contents( $cmp_site_badbeta, json_encode( array( 'version' => '1.0"><script>' ) ) );
list( $rc, $out ) = cmp_site_run( 'bash bin/build-site.sh --out ' . escapeshellarg( $cmp_site_tmp . '/out8' ), array_merge( $cmp_site_env, array( 'CMP_BETA_MANIFEST' => $cmp_site_badbeta ) ) );
cmp_assert( 0 !== $rc, 'build-site: a beta manifest with an invalid version fails' );

foreach ( array( 'index.html' => 'ro', 'en/index.html' => 'en' ) as $page => $lang ) {
	$html = $cmp_site_read( $page );
	foreach ( array( 'verifica', 'legea', 'functii', 'demo', 'comparatie', 'resurse' ) as $id ) {
		cmp_assert( false !== strpos( $html, 'id="' . $id . '"' ), "{$page}: section #{$id}" );
	}
	// Download: stable -> releases/latest; else the beta manifest's version; else the releases page.
	foreach ( array(
		'beta only'   => array( $html, $cmp_site_repo . '/tag/v1.2.0' ),
		'stable'      => array( (string) @file_get_contents( $cmp_site_out3 . '/' . $page ), $cmp_site_repo . '/latest' ),
		'no manifest' => array( (string) @file_get_contents( $cmp_site_out7 . '/' . $page ), $cmp_site_repo ),
	) as $case => $pair ) {
		$links = $cmp_site_dl( $pair[0] );
		cmp_assert( count( $links ) >= 2, "{$page} ({$case}): at least two download links" );
		cmp_assert( array() !== $links && array( $pair[1] ) === array_values( array_unique( $links ) ), "{$page} ({$case}): download links go to {$pair[1]}" );
		cmp_assert( false === strpos( $pair[0], 'DOWNLOAD_URL' ), "{$page} ({$case}): download marker replaced" );
	}
	cmp_assert_eq( substr_count( (string) @file_get_contents( $cmp_site_root . '/site/' . $page ), '<!-- DOWNLOAD_URL -->' ), count( $cmp_site_dl( $html ) ), "site/{$page}: every download link uses the marker" );
	// The tool.
	cmp_assert( 1 === preg_match( '#<section[^>]*id="verifica".*?</section>#s', $html, $sm ), "{$page}: #verifica is a section" );
	$sec = isset( $sm[0] ) ? $sm[0] : '';
	cmp_assert( 1 === preg_match( '#<form\b[^>]*\bid="verifica-form"[^>]*>#', $sec, $fm ), "{$page}: the tool's form" );
	cmp_assert( isset( $fm[0] ) && false !== strpos( $fm[0], 'action="/api/verifica.php"' ) && false !== strpos( $fm[0], 'method="post"' ), "{$page}: the form posts to /api/verifica.php" );
	cmp_assert( isset( $fm[0] ) && 1 === preg_match( '#\shidden[\s>]#', $fm[0] ), "{$page}: the form is hidden until verifica.js shows it" );
	cmp_assert( 1 === preg_match( '#<input\b[^>]*\bname="url"[^>]*>#', $sec, $im ) && false !== strpos( $im[0], 'required' ), "{$page}: a required url field" );
	cmp_assert( 1 === preg_match( '#<label\b[^>]*\bfor="verifica-url"#', $sec ) && false !== strpos( $sec, 'id="verifica-url"' ), "{$page}: the url field has a label" );
	cmp_assert( 1 === preg_match( '#<noscript>(.*?)</noscript>#s', $sec, $nm ) && strlen( trim( strip_tags( $nm[1] ) ) ) > 20, "{$page}: <noscript> message for the tool" );
	cmp_assert( 1 === preg_match( '#<div\b[^>]*\bid="verifica-rezultat"[^>]*>#', $sec, $rm ) && false !== strpos( $rm[0], 'aria-live="polite"' ), "{$page}: result region with aria-live=polite" );
	cmp_assert( false !== strpos( $html, '<script src="/assets/verifica.js" defer></script>' ), "{$page}: loads /assets/verifica.js" );
	cmp_assert( false !== strpos( $sec, 'id="cum-verifici"' ), "{$page}: how to check it yourself (#cum-verifici)" );
	// The demo, in an iframe of /demo/, with three styles.
	$demo_base = 'ro' === $lang ? '/demo/embed/' : '/demo/en/embed/';
	cmp_assert( 1 === preg_match( '#<iframe\b[^>]*\bsrc="' . preg_quote( $demo_base, '#' ) . '\?style=(minimal|brand|friendly)"[^>]*\bname="cmp-demo"[^>]*\btitle="[^"]+"#', $html ), "{$page}: demo iframe of {$demo_base}" );
	foreach ( array( 'minimal', 'brand', 'friendly' ) as $st ) {
		cmp_assert( 1 === preg_match( '#<a\b[^>]*href="' . preg_quote( $demo_base, '#' ) . '\?style=' . $st . '"[^>]*target="cmp-demo"#', $html ), "{$page}: demo style switch {$st}" );
	}
	// The comparison: every claim about another product has a note with a link and a date.
	cmp_assert( 1 === preg_match( '#<section[^>]*id="comparatie".*?</section>#s', $html, $cm ), "{$page}: #comparatie is a section" );
	$cmp_sec = isset( $cm[0] ) ? $cm[0] : '';
	cmp_assert( 1 === preg_match( '#<table\b.*?</table>#s', $cmp_sec, $tm ), "{$page}: comparison table" );
	$table = isset( $tm[0] ) ? $tm[0] : '';
	foreach ( array( 'Consent Manager Pro', 'Complianz', 'CookieYes', 'Cookiebot' ) as $prod ) {
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
		cmp_assert( 1 === preg_match( '#<a href="https://(?!consent\.rt\.md)[^"]+"#', $li ), "{$page}: note {$n} links its source" );
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
foreach ( array( 'textContent', 'AbortController', '/api/verifica.php', 'prefers-reduced-motion', '#functii', '#cum-verifici', 'aria-current', 'rate_limited', 'busy', "'clean'", "'issues'", "'unsure'", "'error'", 'Consent Manager Pro' ) as $need ) {
	cmp_assert( false !== strpos( $cmp_site_js, $need ), "verifica.js: uses {$need}" );
}
cmp_assert( false === strpos( $cmp_site_js, "\xe2\x80\x94" ), 'verifica.js: no em dash' );
cmp_assert( false === strpos( $cmp_site_js, "out.setAttribute('aria-busy'" ), 'verifica.js: no aria-busy on the live region' );
cmp_assert( false !== strpos( $cmp_site_js, "querySelector('canvas.confetti')" ), 'verifica.js: confetti is not started twice' );
cmp_assert( false !== strpos( $cmp_site_js, 'form.hidden = false' ), 'verifica.js: shows the form only when it can run' );
cmp_assert( false !== strpos( $cmp_site_js, "'cmp:consent-manager-pro'" ), 'verifica.js: our own CMP finding is not shown twice' );
cmp_assert( 1 === preg_match( '#<canvas|createElement\(\s*\'canvas\'\s*\)#', $cmp_site_js ), 'verifica.js: confetti on its own canvas' );
cmp_assert( 1 === preg_match( '#/\* confetti \*/(.*?)/\* /confetti \*/#s', $cmp_site_js, $cf ) && strlen( preg_replace( '#\s+#', ' ', preg_replace( '#//[^\n]*|/\*.*?\*/#s', '', $cf[1] ) ) ) < 2048, 'verifica.js: confetti under 2 KB (without comments and whitespace runs)' );

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
	preg_match_all( '#<script\b[^>]*>#', $html, $sm2 );
	cmp_assert_eq( $sm2[0], array( '<script src="/demo/demo.js">', '<script src="/demo/cmp.min.js" defer>' ), "{$page}: loads only demo.js and the local cmp.min.js" );
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
cmp_assert( false !== $x && 'Consent Manager Pro 2.25.0' === (string) $x->channel->item[0]->title, 'feed: newest of 25 first' );
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

// ---- release/ untouched -----------------------------------------------------------
cmp_assert_eq( cmp_site_tree_hash( $cmp_site_root . '/release' ), $cmp_site_release_before, 'build-site tests: release/ unchanged' );
cmp_assert_eq( is_dir( $cmp_site_root . '/release/site' ), $cmp_site_release_site, 'build-site tests: release/site not created' );

cmp_site_rmdir( $cmp_site_tmp );
