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
$cmp_site_env   = array(
	'CMP_CHANGELOG'       => $cmp_site_cl,
	'CMP_STABLE_MANIFEST' => $cmp_site_nomf,
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

// No external resources, in the sources and in the output.
$cmp_site_ext = array(
	'<script'              => '/<script/i',
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
