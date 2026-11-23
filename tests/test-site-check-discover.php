<?php
/**
 * "Verifică-ți site-ul", v2: page discovery (site/api/lib/discover.php), the
 * multi-page fetch (cmp_check_fetch_many) and the multi-page check of the
 * endpoint (cmp_check_site, cmp_check_handle): owner box, new limits, response.
 *
 * Deterministic and offline: network tests talk only to a local `php -S`
 * fixture (tests/fixtures/site-check-discover/router.php) on a free loopback
 * port, through an injected resolver, an injected list of allowed ports and
 * the test-only loopback exception (function parameters only). No request
 * ever goes to a real third-party site.
 */
if ( ! defined( 'CMP_CHECK' ) ) {
	define( 'CMP_CHECK', true );
}
$cmp_cd_api = __DIR__ . '/../site/api';
cmp_assert( is_file( $cmp_cd_api . '/lib/discover.php' ), 'site check api: site/api/lib/discover.php exists' );
if ( ! is_file( $cmp_cd_api . '/lib/discover.php' ) ) {
	return;
}
require_once $cmp_cd_api . '/lib/check.php';
require_once $cmp_cd_api . '/lib/fetch.php';
require_once $cmp_cd_api . '/lib/discover.php';
require_once $cmp_cd_api . '/lib/ratelimit.php';
require_once $cmp_cd_api . '/lib/endpoint.php';

cmp_assert( false !== strpos( (string) file_get_contents( $cmp_cd_api . '/verifica.php' ), "require_once __DIR__ . '/lib/discover.php';" ), 'verifica.php loads lib/discover.php' );
cmp_assert_eq( cmp_check_user_agent(), 'ConsentManagerPro-Check/1.0 (+https://consent.rt.md/)', 'user-agent unchanged' );

/* ------------------------------------------------------------------
 * Helpers.
 * ------------------------------------------------------------------ */

function cmp_cd_free_port() {
	$s = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
	if ( ! $s ) {
		return 0;
	}
	$name = stream_socket_get_name( $s, false );
	fclose( $s );
	return (int) substr( (string) $name, strrpos( (string) $name, ':' ) + 1 );
}

/** Start `php -S` with several workers; returns [process, port] or [null, 0]. */
function cmp_cd_serve( $router, array $env ) {
	$port = cmp_cd_free_port();
	if ( ! $port ) {
		return array( null, 0 );
	}
	$full = getenv();
	foreach ( $env as $k => $v ) {
		$full[ $k ] = $v;
	}
	// No php.ini (-n): the router needs only core functions, and with some ini files
	// (MAMP PHP 7.4 + OPcache) the built-in server serves one request at a time.
	$cmd  = array( PHP_BINARY, '-n', '-S', '127.0.0.1:' . $port, $router );
	$proc = proc_open( $cmd, array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ), $pipes, null, $full );
	if ( ! is_resource( $proc ) ) {
		return array( null, 0 );
	}
	for ( $i = 0; $i < 100; $i++ ) {
		$c = @fsockopen( '127.0.0.1', $port, $e1, $e2, 0.2 );
		if ( $c ) {
			fclose( $c );
			return array( $proc, $port );
		}
		usleep( 50000 );
	}
	proc_terminate( $proc );
	return array( null, 0 );
}

function cmp_cd_rmdir( $dir ) {
	if ( ! is_dir( $dir ) || is_link( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) as $f ) {
		if ( '.' === $f || '..' === $f ) {
			continue;
		}
		$p = $dir . '/' . $f;
		is_dir( $p ) && ! is_link( $p ) ? cmp_cd_rmdir( $p ) : unlink( $p );
	}
	rmdir( $dir );
}

/** Every key of a nested array (to prove internal keys never reach the response). */
function cmp_cd_keys( $a, array &$out = array() ) {
	if ( is_array( $a ) ) {
		foreach ( $a as $k => $v ) {
			if ( is_string( $k ) ) {
				$out[ $k ] = true;
			}
			cmp_cd_keys( $v, $out );
		}
	}
	return $out;
}

$cmp_cd_tmp = sys_get_temp_dir() . '/cmp-check-discover-test-' . getmypid() . '-' . mt_rand();
mkdir( $cmp_cd_tmp, 0700, true );
$cmp_cd_procs = array();
register_shutdown_function(
	function () use ( &$cmp_cd_procs, $cmp_cd_tmp ) {
		foreach ( $cmp_cd_procs as $p ) {
			if ( is_resource( $p ) ) {
				proc_terminate( $p );
			}
		}
		cmp_cd_rmdir( $cmp_cd_tmp );
	}
);
$cmp_cd_svc = $cmp_cd_tmp . '/services.json';
copy( __DIR__ . '/../data/services.json', $cmp_cd_svc );

/* ------------------------------------------------------------------
 * The visitor's field: a bare site address only.
 * ------------------------------------------------------------------ */
foreach ( array(
	'exemplu.md'               => 'https://exemplu.md/',
	'  Exemplu.MD  '           => 'https://exemplu.md/',
	'www.exemplu.md'           => 'https://www.exemplu.md/',
	'http://exemplu.md'        => 'https://exemplu.md/',
	'https://exemplu.md'       => 'https://exemplu.md/',
	'http://exemplu.md/'       => 'https://exemplu.md/',
	'HTTPS://www.exemplu.md/'  => 'https://www.exemplu.md/',
	'ștefan.md'                => 'https://xn--tefan-e9b.md/',
	'https://bücher.de/'       => 'https://xn--bcher-kva.de/',
	'exemplu.xn--p1ai'         => 'https://exemplu.xn--p1ai/',
	'a-b.c-d.co.uk'            => 'https://a-b.c-d.co.uk/',
) as $in => $want ) {
	cmp_assert_eq( cmp_check_normalize_input( $in ), $want, 'input accepts ' . $in );
}
foreach ( array(
	'', '   ', '8.8.8.8', 'http://1.1.1.1/', 'https://8.8.8.8', '[2001:4860::1]', 'http://[2001:4860::1]/', '2130706433', '0x7f.0.0.1', '0177.0.0.1', '127.1', 'http://0x7f000001/',
	'::1', '1.1.1.1.', 'exemplu.md/?sda=cdasfrf.dcv', 'exemplu.md/pagina', 'exemplu.md/pagina/', 'exemplu.md#x', 'exemplu.md/#x', 'exemplu.md?x', 'exemplu.md//',
	'exemplu.md:443', 'https://exemplu.md:443/', 'exemplu.md:8080', 'user@exemplu.md', 'http://user:pass@exemplu.md/', 'exemplu', 'localhost', 'printer.local',
	'db.internal', 'foo.localhost', 'exe_mplu.md', '-x.md', 'x-.md', 'exemplu.m', 'exemplu.123', 'exemplu.md1', 'exemplu..md', '.exemplu.md', 'exemplu.md.',
	'ftp://exemplu.md', '//exemplu.md', 'javascript:alert(1)', 'file:///etc/passwd', 'exemplu.md\\x', 'exe mplu.md', "exem\nplu.md", '%65xemplu.md',
	str_repeat( 'a', 62 ) . '.' . str_repeat( 'b', 62 ) . '.' . str_repeat( 'c', 62 ) . '.' . str_repeat( 'd', 62 ) . '.md', // 254 characters. str_repeat( 'a', 64 ) . '.md',
) as $bad ) {
	cmp_assert( false === cmp_check_normalize_input( $bad ), 'input refuses ' . json_encode( $bad ) );
}
cmp_assert( 1 === preg_match( '/^Scrie doar adresa site-ului, de exemplu exemplu\.md\. Verificăm singuri pagina principală și celelalte pagini\.$/u', cmp_check_error_message( 'bad_url', 'ro' ) ), 'input: bad_url RO message' );
cmp_assert_eq( cmp_check_error_message( 'bad_url', 'en' ), 'Enter only the site address, for example example.md. We check the homepage and the other pages ourselves.', 'input: bad_url EN message' );
// Through handle: a path or an IP is bad_url, nothing counted; the fetch starts on https://host/.
$cmp_cd_in_dir = $cmp_cd_tmp . '/input-counters';
foreach ( array( '8.8.8.8', 'http://1.1.1.1/', 'exemplu.md/?sda=cdasfrf.dcv', 'exemplu.md/pagina', 'exemplu.md#x', '[2001:4860::1]', 'exemplu.md:443' ) as $bad ) {
	$r = cmp_check_handle( array( 'method' => 'POST', 'content_type' => '', 'body' => '', 'post' => array( 'url' => $bad, 'owner' => '1' ), 'ip' => '192.0.2.9' ), $cmp_cd_in_dir, $cmp_cd_svc, 1700000000, function () {
		return array( 'ok' => false, 'error' => 'fetch_failed' );
	} );
	cmp_assert( 400 === $r['code'] && 'bad_url' === $r['body']['code'], "input via handle: {$bad} -> 400 bad_url" );
}
cmp_assert( ! file_exists( $cmp_cd_in_dir ), 'input via handle: refused input writes nothing' );
$cmp_cd_first = array();
cmp_check_handle( array( 'method' => 'POST', 'content_type' => '', 'body' => '', 'post' => array( 'url' => 'http://Exemplu.md/', 'owner' => '1' ), 'ip' => '192.0.2.9' ), $cmp_cd_in_dir, $cmp_cd_svc, 1700000000, function ( $u ) use ( &$cmp_cd_first ) {
	$cmp_cd_first[] = $u;
	return array( 'ok' => false, 'error' => 'fetch_failed' );
} );
cmp_assert( isset( $cmp_cd_first[0] ) && 'https://exemplu.md/' === $cmp_cd_first[0], 'input via handle: http://Exemplu.md/ is checked as https://exemplu.md/' );

/* ------------------------------------------------------------------
 * Pure: hosts, page URLs, sitemap parser.
 * ------------------------------------------------------------------ */
cmp_assert_eq( cmp_check_site_hosts( 'https://exemplu.md/x' ), array( 'exemplu.md', 'www.exemplu.md' ), 'hosts: bare host + www.' );
cmp_assert_eq( cmp_check_site_hosts( 'https://www.exemplu.md/' ), array( 'www.exemplu.md', 'exemplu.md' ), 'hosts: www. host + bare' );
cmp_assert_eq( cmp_check_site_hosts( 'https://8.8.8.8/' ), array( '8.8.8.8' ), 'hosts: an IP has no www. variant' );
cmp_assert_eq( cmp_check_site_hosts( 'https://[2a00:1450::1]/' ), array( '[2a00:1450::1]' ), 'hosts: an IPv6 has no www. variant' );
$cmp_cd_h = cmp_check_site_hosts( 'https://exemplu.md/' );
foreach ( array(
	'https://exemplu.md/a'                              => 'https://exemplu.md/a',
	'http://www.exemplu.md/b/'                          => 'http://www.exemplu.md/b/',
	'https://Exemplu.MD/c#frag'                         => 'https://exemplu.md/c',
	'https://exemplu.md/d?utm_source=x&id=2&fbclid=y'   => 'https://exemplu.md/d?id=2',
	'https://exemplu.md/e?gclid=1&_ga=2'                => 'https://exemplu.md/e',
	'https://exemplu.md/dir.v2/page'                    => 'https://exemplu.md/dir.v2/page',
) as $in => $want ) {
	cmp_assert_eq( cmp_check_site_url( $in, $cmp_cd_h ), $want, "site_url: {$in}" );
}
foreach ( array(
	'https://evil.md/a', 'https://sub.exemplu.md/a', 'https://exemplu.md.evil.md/a', 'https://ww.exemplu.md/', 'https://www.www.exemplu.md/',
	'http://127.0.0.1/', 'http://[::1]/', 'http://169.254.169.254/latest/', 'http://2130706433/', 'http://0177.0.0.1/',
	'file:///etc/passwd', 'javascript:alert(1)', 'mailto:a@exemplu.md', 'ftp://exemplu.md/', 'gopher://exemplu.md/',
	'http://user:pass@exemplu.md/', 'http://exemplu.md@evil.md/', 'http://exemplu.md:8080/', 'http://exemplu.md:22/',
	'https://exemplu.md/doc.pdf', 'https://exemplu.md/IMG.JPG', 'https://exemplu.md/a.zip?x=1', 'https://exemplu.md/sitemap.xml', 'https://exemplu.md/style.css',
	'https://exemplu.md/wp-admin/', 'https://exemplu.md/wp-login.php', 'https://exemplu.md/feed/', 'https://exemplu.md/blog/feed', 'https://exemplu.md/wp-json/wp/v2/posts',
	"https://exemplu.md/\r\nX: 1", '',
) as $bad ) {
	cmp_assert( false === cmp_check_site_url( $bad, $cmp_cd_h ), 'site_url drops ' . json_encode( $bad ) );
}
cmp_assert_eq( cmp_check_page_key( 'https://www.exemplu.md/a/' ), cmp_check_page_key( 'http://exemplu.md/a' ), 'page key: scheme, www. and trailing slash ignored' );

$cmp_cd_ns   = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://exemplu.md/a</loc></url><url><loc> https://exemplu.md/b </loc></url><url><loc>ftp://exemplu.md/c</loc></url></urlset>';
$cmp_cd_nons = '<urlset><url><loc>https://exemplu.md/a</loc></url></urlset>';
cmp_assert_eq( cmp_check_parse_sitemap( $cmp_cd_ns )['urls'], array( 'https://exemplu.md/a', 'https://exemplu.md/b' ), 'sitemap: urlset with namespace, http(s) only, trimmed' );
cmp_assert_eq( cmp_check_parse_sitemap( $cmp_cd_nons )['urls'], array( 'https://exemplu.md/a' ), 'sitemap: urlset without namespace (PHP 7.4 fix)' );
cmp_assert_eq( cmp_check_parse_sitemap( '<sitemapindex><sitemap><loc>https://exemplu.md/m1.xml</loc></sitemap></sitemapindex>' ), array( 'index' => array( 'https://exemplu.md/m1.xml' ), 'urls' => array(), 'seen' => 1, 'count' => 1 ), 'sitemap: index' );
cmp_assert_eq( cmp_check_parse_sitemap( '<urlset><url><loc>https://exemplu.md/a</loc></url>' )['urls'], array( 'https://exemplu.md/a' ), 'sitemap: broken XML -> regex reader' );
cmp_assert_eq( cmp_check_parse_sitemap( '<html><body>no</body></html>' ), array( 'index' => array(), 'urls' => array(), 'seen' => 0, 'count' => 0 ), 'sitemap: HTML -> nothing' );
cmp_assert_eq( cmp_check_parse_sitemap( '' ), array( 'index' => array(), 'urls' => array(), 'seen' => 0, 'count' => 0 ), 'sitemap: empty -> nothing' );
// Fix round: no libxml for remote XML, bounded work.
$cmp_cd_dsrc = (string) file_get_contents( $cmp_cd_api . '/lib/discover.php' );
cmp_assert( false === strpos( $cmp_cd_dsrc, 'DOMDocument' ) && false === strpos( $cmp_cd_dsrc, 'loadXML' ) && false === strpos( $cmp_cd_dsrc, 'simplexml' ), 'sitemap: never parsed with libxml (DOM / SimpleXML)' );
$cmp_cd_big = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
for ( $i = 0; $i < 9000; $i++ ) {
	$cmp_cd_big .= '<url><loc>https://exemplu.md/pagina-cu-un-nume-lung/' . $i . '</loc><lastmod>2026-01-01</lastmod></url>';
}
$cmp_cd_big .= '</urlset>';
$t = microtime( true );
$m = cmp_check_parse_sitemap( $cmp_cd_big );
cmp_assert( 200 === count( $m['urls'] ) && 200 === $m['seen'] && 9000 === $m['count'], 'sitemap: only the first 200 <loc> are kept; all 9000 are counted' );
cmp_assert( microtime( true ) - $t < 0.5, 'sitemap: a ~1 MB sitemap is read in well under a second (' . round( microtime( true ) - $t, 3 ) . ' s)' );
$m = cmp_check_parse_sitemap( '<urlset>' . str_repeat( '<loc>', 100000 ) . '</urlset>' );
cmp_assert( 0 === count( $m['urls'] ) && 100000 === $m['count'], 'sitemap: unclosed <loc> tags -> nothing, no blow-up' );
$m = cmp_check_parse_sitemap( '<urlset><url><loc>https://exemplu.md/' . str_repeat( 'a', 5000 ) . '</loc></url><url><loc>https://exemplu.md/b</loc></url></urlset>' );
cmp_assert_eq( $m['urls'], array( 'https://exemplu.md/b' ), 'sitemap: a <loc> over 2048 characters is skipped' );
$m = cmp_check_parse_sitemap( '<sm:urlset xmlns:sm="http://www.sitemaps.org/schemas/sitemap/0.9"><sm:url><sm:loc><![CDATA[https://exemplu.md/c?a=1&b=2]]></sm:loc></sm:url><sm:url><sm:loc>https://exemplu.md/d?a=1&amp;b=2</sm:loc></sm:url></sm:urlset>' );
cmp_assert_eq( $m['urls'], array( 'https://exemplu.md/c?a=1&b=2', 'https://exemplu.md/d?a=1&b=2' ), 'sitemap: prefixed tags, CDATA, &amp;' );
// Fix round 2: no backtracking blow-up on hostile <loc> (whitespace then no closing tag), with any backtrack limit.
$cmp_cd_bt = ini_get( 'pcre.backtrack_limit' );
foreach ( array( (string) $cmp_cd_bt, '10000000' ) as $cmp_cd_lim ) {
	ini_set( 'pcre.backtrack_limit', $cmp_cd_lim );
	foreach ( array(
		'ws-unclosed' => '<urlset>' . str_repeat( '<loc>' . str_repeat( ' ', 2000 ) . 'x', 450 ),
		'ws-tab-lt'   => '<urlset>' . str_repeat( '<loc>' . str_repeat( ' ', 1000 ) . str_repeat( "\t", 1000 ) . '<', 450 ),
		'cdata-ws'    => '<urlset>' . str_repeat( '<loc>' . str_repeat( ' ', 100 ) . '<![CDATA[]' . str_repeat( ' ', 1900 ), 450 ),
		'prefix-a'    => '<urlset>' . str_repeat( '<' . str_repeat( 'a', 2000 ), 450 ),
	) as $cmp_cd_hn => $cmp_cd_hx ) {
		$cmp_cd_hx .= str_repeat( '<url><loc>https://exemplu.md/p</loc></url>', 3 );
		$t  = microtime( true );
		$m  = cmp_check_parse_sitemap( $cmp_cd_hx );
		$dt = microtime( true ) - $t;
		cmp_assert( $dt < 0.05, "sitemap: hostile {$cmp_cd_hn} parsed in < 0.05 s with backtrack_limit {$cmp_cd_lim} (" . round( $dt, 3 ) . ' s)' );
	}
}
ini_set( 'pcre.backtrack_limit', (string) $cmp_cd_bt );
$m = cmp_check_parse_sitemap( '<urlset>' . str_repeat( '<loc>' . str_repeat( ' ', 2000 ) . 'x', 3 ) . '<url><loc>  https://exemplu.md/after  </loc></url></urlset>' );
cmp_assert( in_array( 'https://exemplu.md/after', $m['urls'], true ), 'sitemap: a valid <loc> after hostile ones is still read (trimmed)' );
$m = cmp_check_parse_sitemap( "\xFF\xFE<\x00u\x00r\x00l\x00s\x00e\x00t\x00>\x00" );
cmp_assert( array() === $m['urls'] && array() === $m['index'], 'sitemap: UTF-16 input -> nothing' );
$cmp_cd_xxe = '<?xml version="1.0"?><!DOCTYPE urlset [<!ENTITY xxe SYSTEM "file:///etc/passwd"><!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">]><urlset><url><loc>https://exemplu.md/&xxe;</loc></url><url><loc>https://exemplu.md/&b;</loc></url><url><loc>https://exemplu.md/ok</loc></url></urlset>';
$cmp_cd_px  = cmp_check_parse_sitemap( $cmp_cd_xxe );
cmp_assert( false === strpos( json_encode( $cmp_cd_px ), 'root:' ) && false === strpos( json_encode( $cmp_cd_px ), 'aaaaaaaaaa' ), 'sitemap: DOCTYPE / ENTITY never expanded (no XXE, no entity expansion)' );
cmp_assert( in_array( 'https://exemplu.md/ok', $cmp_cd_px['urls'], true ), 'sitemap: a DOCTYPE document still read by the regex reader' );
cmp_assert_eq( count( cmp_check_parse_sitemap( str_repeat( 'x', 2000000 ) . $cmp_cd_ns )['urls'] ), 0, 'sitemap: only the first 1 MB is read' );

/* ------------------------------------------------------------------
 * Pure: cmp_check_discover() with a fake small fetch.
 * ------------------------------------------------------------------ */
$cmp_cd_fake = function ( array $map, array &$asked ) {
	return function ( $url ) use ( $map, &$asked ) {
		$asked[] = $url;
		if ( isset( $map[ $url ] ) ) {
			return array( 'ok' => true, 'status' => 200, 'headers' => array(), 'body' => $map[ $url ], 'final_url' => $url, 'error' => '' );
		}
		return array( 'ok' => true, 'status' => 404, 'headers' => array(), 'body' => 'no', 'final_url' => $url, 'error' => '' );
	};
};
$cmp_cd_set = function ( array $locs ) {
	$x = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
	foreach ( $locs as $l ) {
		$x .= '<url><loc>' . htmlspecialchars( $l, ENT_XML1 ) . '</loc></url>';
	}
	return $x . '</urlset>';
};
$cmp_cd_l50 = array( 'https://exemplu.md/' );
for ( $i = 1; $i <= 49; $i++ ) {
	$cmp_cd_l50[] = 'https://exemplu.md/p/' . $i;
}
$asked = array();
$d     = cmp_check_discover( 'https://exemplu.md/', '<html></html>', $cmp_cd_fake( array( 'https://exemplu.md/sitemap.xml' => $cmp_cd_set( $cmp_cd_l50 ) ), $asked ) );
cmp_assert( 10 === count( $d['urls'] ) && 50 === $d['total'] && 'sitemap' === $d['source'] && 'https://exemplu.md/' === $d['urls'][0] && 'https://exemplu.md/p/9' === $d['urls'][9], 'discover: sitemap of 50 -> first 10, total 50, sitemap' );
cmp_assert_eq( $asked, array( 'https://exemplu.md/robots.txt', 'https://exemplu.md/sitemap.xml' ), 'discover: robots.txt, then /sitemap.xml; the alternatives not asked once one works' );
$asked = array();
$d     = cmp_check_discover( 'https://exemplu.md/', '<html></html>', $cmp_cd_fake( array( 'https://exemplu.md/sitemap.xml' => $cmp_cd_set( array( 'https://exemplu.md/x', 'https://exemplu.md/y' ) ) ), $asked ) );
cmp_assert( 3 === $d['total'] && array( 'https://exemplu.md/', 'https://exemplu.md/x', 'https://exemplu.md/y' ) === $d['urls'], 'discover: a sitemap without the homepage -> homepage added first, counted in total' );
$asked = array();
$d     = cmp_check_discover( 'https://exemplu.md/', '<html><a href="/a">a</a></html>', $cmp_cd_fake( array(), $asked ) );
cmp_assert( 'links' === $d['source'] && 2 === $d['total'] && 'https://exemplu.md/a' === $d['urls'][1], 'discover: no sitemap -> links' );
cmp_assert_eq( count( $asked ), 4, 'discover: robots.txt + 3 well-known sitemap names tried' );
$asked = array();
$d     = cmp_check_discover( 'https://exemplu.md/', '<html><a href="https://evil.md/">x</a><a href="/f.pdf">f</a></html>', $cmp_cd_fake( array(), $asked ) );
cmp_assert( 'none' === $d['source'] && null === $d['total'] && array( 'https://exemplu.md/' ) === $d['urls'], 'discover: nothing usable -> none, total null, homepage only' );
// Robots.txt with many Sitemap lines: at most 4 sitemap tries.
$asked = array();
$cmp_cd_rb = '';
for ( $i = 0; $i < 20; $i++ ) {
	$cmp_cd_rb .= "Sitemap: https://exemplu.md/m{$i}.xml\n";
}
cmp_check_discover( 'https://exemplu.md/', '', $cmp_cd_fake( array( 'https://exemplu.md/robots.txt' => $cmp_cd_rb ), $asked ) );
cmp_assert_eq( count( $asked ), 5, 'discover: robots.txt with 20 sitemaps -> at most 4 sitemaps tried' );
// A sitemap listing only other hosts: ignored, links used; no sitemap URL of another host is ever asked.
$asked = array();
$d     = cmp_check_discover(
	'https://exemplu.md/',
	'<a href="/l">l</a>',
	$cmp_cd_fake(
		array(
			'https://exemplu.md/robots.txt'  => "Sitemap: http://169.254.169.254/latest/meta-data/\nSitemap: https://evil.md/map.xml\nSitemap: http://127.0.0.1/map.xml\n",
			'https://exemplu.md/sitemap.xml' => $cmp_cd_set( array( 'http://127.0.0.1/', 'https://evil.md/a', 'http://10.0.0.1/b' ) ),
		),
		$asked
	)
);
cmp_assert( 'links' === $d['source'] && array( 'https://exemplu.md/', 'https://exemplu.md/l' ) === $d['urls'], 'discover: a sitemap of other hosts is ignored -> links' );
cmp_assert_eq( array_values( array_filter( $asked, function ( $u ) { return 0 !== strpos( $u, 'https://exemplu.md/' ); } ) ), array(), 'discover: robots.txt Sitemap lines on other hosts / IPs never requested' );

/* ------------------------------------------------------------------
 * The multi-page check against the local fixture.
 * ------------------------------------------------------------------ */
$cmp_cd_log = $cmp_cd_tmp . '/requests.log';
// Two servers: the site, and a second one (same host name, another port) for the slow pages only.
list( $cmp_cd_sproc, $cmp_cd_sport ) = cmp_cd_serve( __DIR__ . '/fixtures/site-check-discover/router.php', array( 'CMP_FX_LOG' => $cmp_cd_log ) );
$cmp_cd_procs[] = $cmp_cd_sproc;
list( $cmp_cd_proc, $cmp_cd_port ) = cmp_cd_serve( __DIR__ . '/fixtures/site-check-discover/router.php', array( 'CMP_FX_LOG' => $cmp_cd_log, 'CMP_FX_SLOW_PORT' => (string) $cmp_cd_sport ) );
$cmp_cd_procs[] = $cmp_cd_proc;
cmp_assert( $cmp_cd_port > 0 && $cmp_cd_sport > 0, 'discover fixture servers started' );
if ( $cmp_cd_port > 0 && $cmp_cd_sport > 0 ) {
	$cmp_cd_dns = array();
	$cmp_cd_net = array(
		'resolver' => function ( $host ) use ( &$cmp_cd_dns ) {
			$cmp_cd_dns[] = $host;
			if ( 'internal.cmp-check.test' === $host ) {
				return array( '10.0.0.1' );
			}
			return 1 === preg_match( '/\.cmp-check\.test$/', $host ) ? array( '127.0.0.1' ) : array();
		},
		'ports'    => array( $cmp_cd_port, $cmp_cd_sport ),
		'loopback' => true,
	);
	$cmp_cd_run = function ( $site, array $over = array() ) use ( $cmp_cd_port, $cmp_cd_net, $cmp_cd_svc, $cmp_cd_log, &$cmp_cd_dns ) {
		@unlink( $cmp_cd_log );
		$cmp_cd_dns = array();
		$t          = microtime( true );
		$r          = cmp_check_site( 'http://' . $site . '.cmp-check.test:' . $cmp_cd_port . '/', 'ro', $cmp_cd_svc, null, array_merge( $cmp_cd_net, $over ) );
		$r['secs']  = microtime( true ) - $t;
		$r['log']   = is_file( $cmp_cd_log ) ? array_filter( explode( "\n", (string) file_get_contents( $cmp_cd_log ) ) ) : array();
		$r['dns']   = $cmp_cd_dns;
		return $r;
	};
	$cmp_cd_paths = function ( array $urls ) use ( $cmp_cd_port ) {
		return array_map(
			function ( $u ) use ( $cmp_cd_port ) {
				return (string) preg_replace( '#^http://[^/]+:\d+#', '', $u );
			},
			$urls
		);
	};

	// Sitemap with 50 pages.
	$r = $cmp_cd_run( 's50' );
	$p = $r['body']['pages'];
	cmp_assert( 200 === $r['code'] && 'html-v2' === $r['body']['engine'], 's50: 200, html-v2' );
	cmp_assert( 10 === count( $p['checked'] ) && 50 === $p['total'] && 'sitemap' === $p['total_source'] && true === $p['capped'] && array() === $p['failed'], 's50: 10 checked, total 50, sitemap, capped' );
	cmp_assert_eq( $cmp_cd_paths( $p['checked'] ), array( '/', '/p/1', '/p/2', '/p/3', '/p/4', '/p/5', '/p/6', '/p/7', '/p/8', '/p/9' ), 's50: homepage first, then the sitemap order' );
	cmp_assert_eq( $r['dns'], array( 's50.cmp-check.test', 'www.s50.cmp-check.test' ), 's50: each host (bare, www.) resolved once for the whole check' );
	cmp_assert( ! preg_grep( '#/p/(1\d|[2-4]\d)$#', $r['log'] ), 's50: pages beyond the first 10 are never requested' );
	$gt = null;
	foreach ( $r['body']['findings'] as $f ) {
		if ( 0 === strpos( $f['id'], 'tracker:' ) ) {
			$gt = $f;
		}
	}
	cmp_assert( is_array( $gt ) && array( '/p/3' ) === $cmp_cd_paths( $gt['pages'] ) && 'issues' === $r['body']['status'] && in_array( $gt['id'], $r['body']['top'], true ), 's50: the tracker of /p/3 is reported with its page, issues, in top' );
	// The response shape: the design's keys, nothing internal.
	cmp_assert_eq( array_keys( $r['body'] ), array( 'status', 'findings', 'cmp', 'checked_url', 'engine', 'pages', 'passed', 'top', 'wordpress' ), 'response: top-level keys' );
	cmp_assert_eq( array_keys( $p ), array( 'checked', 'failed', 'total', 'total_source', 'capped', 'stopped' ), 'response: pages keys' );
	cmp_assert( null === $p['stopped'], 's50: not stopped early' );
	$k = cmp_cd_keys( $r['body'] );
	cmp_assert( ! isset( $k['rank'] ) && ! isset( $k['detail'] ) && ! isset( $k['ok'] ) && ! isset( $k['body'] ), 'response: no rank, detail or fetch internals anywhere' );
	foreach ( $r['body']['findings'] as $f ) {
		cmp_assert_eq( array_keys( $f ), array( 'id', 'severity', 'title', 'explain', 'law', 'evidence', 'pages', 'fix', 'cmp_fix' ), 'response: finding keys of ' . $f['id'] );
	}

	// Sitemap index: one level, at most 3 sitemaps; a child on another host never requested.
	$r = $cmp_cd_run( 'idx' );
	$p = $r['body']['pages'];
	cmp_assert( 'sitemap' === $p['total_source'] && 7 === $p['total'] && 7 === count( $p['checked'] ) && false === $p['capped'], 'idx: index -> 3 sitemaps read, 6 pages + homepage' );
	cmp_assert( ! preg_grep( '#map-[45]\.xml#', $r['log'] ) && preg_grep( '#map-3\.xml#', $r['log'] ), 'idx: the 4th and 5th sitemaps are not read' );
	cmp_assert( ! preg_grep( '#^evil\.#', $r['log'] ) && ! in_array( 'evil.cmp-check.test', $r['dns'], true ), 'idx: an index child on another host is neither resolved nor requested' );

	// robots.txt Sitemap: line (relative), other hosts / IPs in robots.txt ignored.
	$r = $cmp_cd_run( 'robots' );
	$p = $r['body']['pages'];
	cmp_assert( 'sitemap' === $p['total_source'] && 3 === $p['total'] && array( '/', '/r/1', '/r/2' ) === $cmp_cd_paths( $p['checked'] ), 'robots: the Sitemap line of robots.txt is used' );
	cmp_assert( ! preg_grep( '#sitemap\.xml|/x\.xml|/y\.xml#', $r['log'] ) && ! preg_grep( '#^(evil|127)#', $r['log'] ), 'robots: no default sitemap tried, no other host / IP requested' );

	// A hostile sitemap: other hosts, IPs, schemes, ports, userinfo, subdomains are all ignored.
	$r = $cmp_cd_run( 'hostile' );
	$p = $r['body']['pages'];
	cmp_assert( 2 === $p['total'] && array( '/', '/ok-1' ) === $cmp_cd_paths( $p['checked'] ) && array() === $p['failed'], 'hostile: only the same-host page is used' );
	cmp_assert_eq( array_values( array_unique( array_map( function ( $l ) { return strstr( $l, ' ', true ); }, $r['log'] ) ) ), array( 'hostile.cmp-check.test' ), 'hostile: every request went to the site host only' );
	cmp_assert( ! preg_grep( '#secret|/v6|mapped|decimal|userinfo|subdomain|suffix#', $r['log'] ), 'hostile: none of the hostile entries was requested' );
	cmp_assert_eq( $r['dns'], array( 'hostile.cmp-check.test', 'www.hostile.cmp-check.test' ), 'hostile: only the site hosts resolved (bare, www.)' );

	// No sitemap: the homepage links (same host + www., files and tracking dropped).
	$r = $cmp_cd_run( 'links' );
	$p = $r['body']['pages'];
	cmp_assert( 'links' === $p['total_source'] && 5 === $p['total'], 'links: source links, total 5 (home, /a, /b/, www /c, /d?id=7)' );
	cmp_assert_eq( $cmp_cd_paths( $p['checked'] ), array( '/', '/a', '/b/', '/c', '/d?id=7' ), 'links: the pages, tracking params stripped, deduplicated' );
	cmp_assert( array() === $p['failed'], 'links: nothing failed (the policy is a rel=privacy-policy <link>, not a page to follow)' );
	cmp_assert( 1 === preg_match( '#^http://www\.links\.cmp-check\.test:#', $p['checked'][3] ), 'links: the www. variant is allowed' );
	cmp_assert( ! preg_grep( '#pdf|JPG|zip|wp-admin|feed|commented|/e$|/loop#', $r['log'] ) && ! preg_grep( '#^(evil|127)#', $r['log'] ), 'links: files, admin, feed, other hosts never requested' );
	cmp_assert_eq( $r['dns'], array( 'links.cmp-check.test', 'www.links.cmp-check.test' ), 'links: one resolution per host' );

	// Sub-pages that redirect away, are too big, compressed, missing or not HTML.
	$r      = $cmp_cd_run( 'redir' );
	$p      = $r['body']['pages'];
	$failed = array();
	foreach ( $p['failed'] as $f ) {
		$failed[ preg_replace( '#^http://[^/]+:' . $cmp_cd_port . '#', '', $f['url'] ) ] = $f['code'];
		cmp_assert_eq( array_keys( $f ), array( 'url', 'code' ), 'redir: a failed page has url and code only' );
	}
	// /r-meta points to port 80, outside the test's allowed ports: refused as a bad URL before the host check.
	// Off-site redirects and non-HTML answers are not pages of the site: skipped, not counted (fix round 2).
	cmp_assert_eq( $failed, array( '/r-meta' => 'bad_url', '/big' => 'too_large', '/gz' => 'fetch_failed', '/missing' => '404' ), 'redir: failed pages and their codes' );
	cmp_assert_eq( $cmp_cd_paths( $p['checked'] ), array( '/', '/ok-1', '/ok-2' ), 'redir: same-site pages checked (www. redirect followed, redirect to the homepage not counted twice)' );
	cmp_assert( ! preg_grep( '#^evil\.|landing|admin|meta-data#', $r['log'] ) && ! in_array( 'evil.cmp-check.test', $r['dns'], true ) && ! in_array( 'internal.cmp-check.test', $r['dns'], true ), 'redir: off-site redirects never resolved nor requested' );
	cmp_assert( 'issues' === $r['body']['status'] || 'unsure' === $r['body']['status'], 'redir: unread pages -> not clean (' . $r['body']['status'] . ')' );

	// A sitemap over 1 MB is ignored.
	$r = $cmp_cd_run( 'bigmap' );
	$p = $r['body']['pages'];
	cmp_assert( 'links' === $p['total_source'] && array( '/', '/from-links' ) === $cmp_cd_paths( $p['checked'] ), 'bigmap: a sitemap over 1 MB is ignored -> links' );

	// Fix round, worst case (review I1): a ~1 MB sitemap and script-heavy pages of 2 MB / 0.95 MB.
	$cmp_cd_cpu = function () {
		$u = getrusage();
		return $u['ru_utime.tv_sec'] + $u['ru_utime.tv_usec'] / 1e6 + $u['ru_stime.tv_sec'] + $u['ru_stime.tv_usec'] / 1e6;
	};
	$c0 = $cmp_cd_cpu();
	$r  = $cmp_cd_run( 'heavy' );
	$c1 = $cmp_cd_cpu() - $c0;
	$p  = $r['body']['pages'];
	$j  = json_encode( $r['body'] );
	cmp_assert( $r['secs'] <= 32, 'heavy: total wall time within 32 s (' . round( $r['secs'], 1 ) . ' s)' );
	cmp_assert( $c1 <= 20, 'heavy: CPU of the check within 20 s (' . round( $c1, 1 ) . ' s, PHP ' . PHP_VERSION . ')' );
	cmp_assert( 200 === $r['code'] && is_string( $j ) && 'sitemap' === $p['total_source'] && $p['total'] > 10000 && true === $p['capped'], 'heavy: JSON response, sitemap counted (' . $p['total'] . ' pages)' );
	$cmp_cd_codes = array_count_values( array_column( $p['failed'], 'code' ) );
	cmp_assert( isset( $cmp_cd_codes['too_large'] ) && 3 === $cmp_cd_codes['too_large'], 'heavy: sub-pages over 1 MB -> too_large (not analysed)' );
	$cmp_cd_n = count( $p['checked'] ) + count( $p['failed'] );
	cmp_assert( count( $p['checked'] ) >= 2 && ( 10 === $cmp_cd_n || ( $cmp_cd_n < 10 && 'time' === $p['stopped'] && true === $p['capped'] ) ), 'heavy: 10 pages, or fewer with stopped: time (analysis budget, our limit); ' . count( $p['checked'] ) . ' analysed' );
	cmp_assert( ! in_array( 'timeout', array_column( $p['failed'], 'code' ), true ), 'heavy: a page skipped by the analysis budget is not in failed (not unread)' );
	cmp_assert( in_array( 'pages:unread', array_column( $r['body']['findings'], 'id' ), true ), 'heavy: the unread pages are counted (pages:unread finding)' );

	// The request budget (review M3): 25 requests per check, one redirect for robots / sitemaps / sub-pages.
	$r    = $cmp_cd_run( 'hops' );
	$p    = $r['body']['pages'];
	$logp = array_map( function ( $l ) { return (string) substr( $l, (int) strpos( $l, ' ' ) + 1 ); }, $r['log'] );
	cmp_assert( count( $r['log'] ) <= 25, 'hops: at most 25 HTTP requests in one check (' . count( $r['log'] ) . ')' );
	cmp_assert( in_array( '/robots-real.txt', $logp, true ) && in_array( '/sm-1-b.xml', $logp, true ) && ! preg_grep( '#-c(\.xml)?$#', $logp ), 'hops: robots.txt and sitemaps follow one redirect, not two' );
	$cmp_cd_codes = array_count_values( array_column( $p['failed'], 'code' ) );
	cmp_assert( array( 'too_many_redirects' => 6 ) === $cmp_cd_codes && 'requests' === $p['stopped'] && true === $p['capped'], 'hops: attempted sub-pages -> too_many_redirects after one hop; the rest unattempted (stopped: requests), not unread ' . json_encode( $cmp_cd_codes ) );
	cmp_assert( 'clean' !== $r['body']['status'], 'hops: unread pages -> not clean' );
	$r = $cmp_cd_run( 'home3' );
	cmp_assert( 200 === $r['code'] && 1 === preg_match( '#/x3$#', $r['body']['checked_url'] ), 'home3: the homepage still follows 3 redirects' );

	// A second host (www.) is resolved before the pages, and skipped when under 3 s are left (review M5).
	$r = $cmp_cd_run( 'links', array( 'total_seconds' => 2.5 ) );
	$p = $r['body']['pages'];
	$w = array_values( array_filter( $p['failed'], function ( $f ) { return 1 === preg_match( '#//www\.#', $f['url'] ); } ) );
	cmp_assert( array() === $w && ! in_array( 'www.links.cmp-check.test', $r['dns'], true ) && 'time' === $p['stopped'], 'links (2.5 s budget): the www. page is not attempted (stopped: time, not unread), its host never resolved' );
	cmp_assert( in_array( '/a', $cmp_cd_paths( $p['checked'] ), true ), 'links (2.5 s budget): the same-host pages are still checked' );

	// Fix round 2, real-site bug (moldova.md): a sitemap that lists 26 MB images as pages.
	// Bytes the fixture really sent, per path (the server logs them when the client hangs up; wait for it).
	$cmp_cd_sent = function ( array $unused, $prefix, $want = 1 ) use ( $cmp_cd_log ) {
		$end = microtime( true ) + 3;
		do {
			$out = array();
			foreach ( is_file( $cmp_cd_log ) ? explode( "\n", (string) file_get_contents( $cmp_cd_log ) ) : array() as $l ) {
				if ( 1 === preg_match( '#^sent (\S+) (\d+)$#', $l, $m ) && 0 === strpos( $m[1], $prefix ) ) {
					$out[ $m[1] ] = (int) $m[2];
				}
			}
			if ( count( $out ) >= $want ) {
				break;
			}
			usleep( 50000 );
		} while ( microtime( true ) < $end );
		return $out;
	};
	$r    = $cmp_cd_run( 'media' );
	$p    = $r['body']['pages'];
	$sent = $cmp_cd_sent( $r['log'], '/toolkit/', 8 );
	cmp_assert_eq( $cmp_cd_paths( $p['checked'] ), array( '/', '/p/1', '/p/2', '/p/3', '/p/4', '/p/5', '/p/6', '/p/7', '/p/8', '/p/9' ), 'media: the images are skipped and later HTML entries fill the 10 pages' );
	cmp_assert( array() === $p['failed'] && array( 'transport:http' ) === array_column( $r['body']['findings'], 'id' ), 'media: images are not unread pages (no pages:unread; only the fixture\'s plain-http finding)' );
	cmp_assert( 8 === count( $sent ) && max( $sent ) < 4194304, 'media: each 26 MB image is cut after its headers (largest sent ' . ( $sent ? max( $sent ) : -1 ) . ' bytes)' );
	cmp_assert( 21 === $p['total'] && true === $p['capped'] && $r['secs'] < 5, 'media: total 21, capped, done quickly (' . round( $r['secs'], 2 ) . ' s)' );
	$r    = $cmp_cd_run( 'clcap' );
	$p    = $r['body']['pages'];
	$sent = $cmp_cd_sent( $r['log'], '/big-cl' );
	cmp_assert( array( array( 'url' => 'http://clcap.cmp-check.test:' . $cmp_cd_port . '/big-cl', 'code' => 'too_large' ) ) === $p['failed'], 'clcap: an HTML page announcing 27 MB -> too_large (a real page, unread)' );
	cmp_assert( 1 === count( $sent ) && $sent['/big-cl'] < 4194304, 'clcap: cut on its Content-Length, almost nothing downloaded (' . ( $sent ? $sent['/big-cl'] : -1 ) . ' bytes)' );
	$r    = $cmp_cd_run( 'mediaidx' );
	$p    = $r['body']['pages'];
	$logp = array_values( array_filter( array_map( function ( $l ) { return (string) substr( $l, (int) strpos( $l, ' ' ) + 1 ); }, $r['log'] ), function ( $x ) { return 1 === preg_match( '#\.xml$#', $x ); } ) );
	cmp_assert( ! preg_grep( '#attachment|image|video#', $logp ), 'mediaidx: media / attachment sitemaps of an index are never requested' );
	cmp_assert_eq( array_values( preg_grep( '#(page|post)-sitemap#', $logp ) ), array( '/page-sitemap.xml', '/post-sitemap.xml' ), 'mediaidx: page sitemap first, then posts' );
	cmp_assert_eq( $cmp_cd_paths( $p['checked'] ), array( '/', '/from/page-sitemap', '/from/post-sitemap' ), 'mediaidx: pages from the page and post sitemaps' );
	cmp_assert( cmp_check_media_sitemap( 'https://x.md/wp-sitemap-posts-attachment-1.xml' ) && cmp_check_media_sitemap( 'https://x.md/image-sitemap.xml?x=1' ) && ! cmp_check_media_sitemap( 'https://x.md/media-kit/page-sitemap.xml' ) && ! cmp_check_media_sitemap( 'https://x.md/post-sitemap.xml' ), 'media sitemap: judged by the file name only' );

	// Fix round 3 (moldova.md): sub-pages that redirect to images under /wp-content/uploads/.
	$r    = $cmp_cd_run( 'uploads' );
	$p    = $r['body']['pages'];
	$logp = array_map( function ( $l ) { return (string) substr( $l, (int) strpos( $l, ' ' ) + 1 ); }, $r['log'] );
	cmp_assert_eq( $cmp_cd_paths( $p['checked'] ), array( '/', '/p/1', '/p/2', '/p/3', '/p/4', '/p/5' ), 'uploads: the HTML pages after the image redirects are checked' );
	cmp_assert( array() === $p['failed'] && null === $p['stopped'] && ! in_array( 'pages:unread', array_column( $r['body']['findings'], 'id' ), true ) && array( 'transport:http' ) === array_column( $r['body']['findings'], 'id' ), 'uploads: image redirects are not pages: no unread, status not forced to unsure' );
	cmp_assert( ! preg_grep( '#wp-content/uploads|\.PDF#i', $logp ) && 9 === count( preg_grep( '#^/toolkit/#', $logp ) ), 'uploads: each image redirect costs one request (never followed); listed files never requested' );
	cmp_assert_eq( count( $r['log'] ), 3 + 9 + 5, 'uploads: 17 requests in all (homepage, robots.txt, sitemap, 9 redirects, 5 pages)' );
	$r = $cmp_cd_run( 'uploads30' );
	$p = $r['body']['pages'];
	cmp_assert( array( '/', '/p/1', '/p/2', '/p/3', '/p/4', '/p/5' ) === $cmp_cd_paths( $p['checked'] ) && array() === $p['failed'] && 'requests' === $p['stopped'] && true === $p['capped'], 'uploads30: the HTML pages are reached (round-robin by first segment), then the image redirects use up the budget -> stopped: requests, nothing unread' );
	cmp_assert( ! in_array( 'pages:unread', array_column( $r['body']['findings'], 'id' ), true ) && count( $r['log'] ) <= 25, 'uploads30: no pages:unread from unattempted pages; at most 25 requests (' . count( $r['log'] ) . ')' );
	cmp_assert( cmp_check_is_file_path( '/wp-content/uploads/2024/a' ) && cmp_check_is_file_path( '/x/Y.JPEG?v=1' ) && cmp_check_is_file_path( '/f.woff2' ) && ! cmp_check_is_file_path( '/blog/v2.0/' ) && ! cmp_check_is_file_path( '/toolkit/sunset/' ), 'file path: extensions and WordPress uploads, not pages' );

	// Fix round 4: homepage links first, then the sitemap by depth, round-robin by first segment.
	$r    = $cmp_cd_run( 'nav' );
	$p    = $r['body']['pages'];
	$logp = array_map( function ( $l ) { return (string) substr( $l, (int) strpos( $l, ' ' ) + 1 ); }, $r['log'] );
	cmp_assert_eq( $cmp_cd_paths( $p['checked'] ), array( '/', '/despre/', '/contact/', '/servicii/', '/echipa/', '/preturi/', '/blog/', '/termeni/', '/blog/a/', '/blog/b/' ), 'nav: nav links first, then sitemap pages by depth; the 40 /toolkit/ entries do not crowd them out' );
	cmp_assert( array() === $p['failed'] && null === $p['stopped'] && 'sitemap' === $p['total_source'] && 51 === $p['total'] && true === $p['capped'], 'nav: nothing unread, not stopped; total from the sitemap (50 + homepage)' );
	// Rounds fetch only the slots still open, so the waiting /toolkit/ entries between the last real pages cost a few requests.
	cmp_assert( count( preg_grep( '#^/toolkit/#', $logp ) ) <= 6 && ! preg_grep( '#wp-content/uploads#', $logp ) && count( $r['log'] ) <= 18, 'nav: a few image redirects tried (' . count( preg_grep( '#^/toolkit/#', $logp ) ) . '), never followed; ' . count( $r['log'] ) . ' requests of 25' );
	$cmp_cd_ord = cmp_check_order_pages( array( 'https://a.md/t/1/', 'https://a.md/t/2/', 'https://a.md/t/3/', 'https://a.md/t/4/', 'https://a.md/t/5/', 'https://a.md/b/x/', 'https://a.md/despre/', 'https://a.md/a/b/c/' ) );
	cmp_assert_eq( $cmp_cd_ord, array( 'https://a.md/despre/', 'https://a.md/t/1/', 'https://a.md/t/2/', 'https://a.md/t/3/', 'https://a.md/b/x/', 'https://a.md/t/4/', 'https://a.md/t/5/', 'https://a.md/a/b/c/' ), 'order: by depth, then at most 3 in a row per first segment' );

	// Fix round 2: the reviewer's composite CPU sites (hostile sitemaps, heavy homepage, heavy pages).
	foreach ( array( 'rvcpu', 'rvmixh', 'rvlinkp' ) as $cmp_cd_rv ) {
		$c0 = $cmp_cd_cpu();
		$r  = $cmp_cd_run( $cmp_cd_rv );
		$c1 = $cmp_cd_cpu() - $c0;
		cmp_assert( 200 === $r['code'] && $r['secs'] <= 28 && $c1 <= 20, "{$cmp_cd_rv}: wall " . round( $r['secs'], 1 ) . ' s (<= 28), CPU ' . round( $c1, 1 ) . ' s (<= 20), PHP ' . PHP_VERSION );
		$cmp_cd_n = count( $r['body']['pages']['checked'] ) + count( $r['body']['pages']['failed'] );
		cmp_assert( 10 === $cmp_cd_n || ( $cmp_cd_n < 10 && 'time' === $r['body']['pages']['stopped'] ), "{$cmp_cd_rv}: 10 pages, or fewer with stopped: time (" . count( $r['body']['pages']['checked'] ) . ' analysed, stopped ' . json_encode( $r['body']['pages']['stopped'] ) . ')' );
		cmp_assert( ! in_array( 'pages:unread', array_column( $r['body']['findings'], 'id' ), true ) || array() !== $r['body']['pages']['failed'], "{$cmp_cd_rv}: no pages:unread unless a page really failed" );
	}

	// A slow page does not hold the others; it is reported as timeout within the budget.
	$r = $cmp_cd_run( 'slow', array( 'page_seconds' => 2, 'total_seconds' => 8 ) );
	$p = $r['body']['pages'];
	cmp_assert_eq( $cmp_cd_paths( $p['checked'] ), array( '/', '/f1', '/f2', '/f3', '/f4' ), 'slow: the quick pages are all checked' );
	cmp_assert( 1 === count( $p['failed'] ) && 'timeout' === $p['failed'][0]['code'] && 1 === preg_match( '#/slow-1$#', $p['failed'][0]['url'] ), 'slow: the slow page -> timeout' );
	cmp_assert( $r['secs'] < 4.0, 'slow: done in about the page budget (' . round( $r['secs'], 2 ) . ' s)' );

	// More slow pages than the total budget: everything left is timeout, the check ends on time.
	$r = $cmp_cd_run( 'allslow', array( 'page_seconds' => 2, 'total_seconds' => 3 ) );
	$p = $r['body']['pages'];
	$codes = array_unique( array_column( $p['failed'], 'code' ) );
	cmp_assert( 6 === count( $p['failed'] ) && array( 'timeout' ) === array_values( $codes ) && array( '/' ) === $cmp_cd_paths( $p['checked'] ), 'allslow: the 6 slow pages -> timeout' );
	cmp_assert( $r['secs'] < 4.5, 'allslow: the total budget is kept (' . round( $r['secs'], 2 ) . ' s)' );
	cmp_assert( 'error' !== $r['body']['status'] && 'clean' !== $r['body']['status'], 'allslow: homepage read, others unread -> not clean, not error (' . $r['body']['status'] . ')' );
}

/* ------------------------------------------------------------------
 * cmp_check_handle(): owner box, new limits, injected fetcher.
 * ------------------------------------------------------------------ */
$cmp_cd_ok = function ( $url ) {
	if ( preg_match( '#/(robots\.txt|sitemap\.xml|sitemap_index\.xml|wp-sitemap\.xml)$#', $url ) ) {
		return array( 'ok' => true, 'status' => 404, 'headers' => array(), 'body' => '', 'final_url' => $url, 'error' => '' );
	}
	return array( 'ok' => true, 'status' => 200, 'headers' => array( 'content-type' => 'text/html' ), 'body' => '<html><body><a href="/despre">Despre</a><a href="/privacy">Privacy policy</a></body></html>', 'final_url' => $url, 'error' => '' );
};
$cmp_cd_req = function ( array $over ) {
	return array_merge( array( 'method' => 'POST', 'content_type' => 'application/x-www-form-urlencoded', 'body' => '', 'post' => array(), 'ip' => '192.0.2.10' ), $over );
};

// Owner missing or wrong: 400 owner_required, nothing written at all.
$cmp_cd_od = $cmp_cd_tmp . '/owner-counters';
foreach ( array(
	'form, no owner'    => array( 'post' => array( 'url' => 'exemplu.md' ) ),
	'form, owner on'    => array( 'post' => array( 'url' => 'exemplu.md', 'owner' => 'on' ) ),
	'form, owner 0'     => array( 'post' => array( 'url' => 'exemplu.md', 'owner' => '0' ) ),
	'form, owner array' => array( 'post' => array( 'url' => 'exemplu.md', 'owner' => array( '1' ) ) ),
	'JSON, no owner'    => array( 'content_type' => 'application/json', 'body' => '{"url":"exemplu.md"}' ),
	'JSON, owner 0'     => array( 'content_type' => 'application/json', 'body' => '{"url":"exemplu.md","owner":0}' ),
	'JSON, owner false' => array( 'content_type' => 'application/json', 'body' => '{"url":"exemplu.md","owner":false}' ),
	'JSON, owner "yes"' => array( 'content_type' => 'application/json', 'body' => '{"url":"exemplu.md","owner":"yes"}' ),
	'JSON, owner 2'     => array( 'content_type' => 'application/json', 'body' => '{"url":"exemplu.md","owner":2}' ),
	'JSON, owner 1.0'   => array( 'content_type' => 'application/json', 'body' => '{"url":"exemplu.md","owner":1.0}' ),
	'JSON, owner "true"' => array( 'content_type' => 'application/json', 'body' => '{"url":"exemplu.md","owner":"true"}' ),
	'bad URL, no owner' => array( 'post' => array( 'url' => 'http://127.0.0.1/' ) ),
) as $label => $over ) {
	$called = 0;
	$r      = cmp_check_handle(
		$cmp_cd_req( $over ),
		$cmp_cd_od,
		$cmp_cd_svc,
		1700000000,
		function ( $u ) use ( &$called, $cmp_cd_ok ) {
			$called++;
			return $cmp_cd_ok( $u );
		}
	);
	cmp_assert( 400 === $r['code'] && 'owner_required' === $r['body']['code'] && 'error' === $r['body']['status'] && 0 === $called, "owner: {$label} -> 400 owner_required, nothing fetched" );
}
cmp_assert( ! file_exists( $cmp_cd_od ), 'owner: refused requests create no counter folder or file at all' );
$r = cmp_check_handle( $cmp_cd_req( array( 'post' => array( 'url' => 'exemplu.md' ) ) ), $cmp_cd_od, $cmp_cd_svc, 1700000000, $cmp_cd_ok );
cmp_assert_eq( $r['body']['message'], 'Bifează că deții site-ul sau ai permisiunea proprietarului.', 'owner: Romanian message' );
$r = cmp_check_handle( $cmp_cd_req( array( 'post' => array( 'url' => 'exemplu.md', 'lang' => 'en' ) ) ), $cmp_cd_od, $cmp_cd_svc, 1700000000, $cmp_cd_ok );
cmp_assert_eq( $r['body']['message'], "Tick that you own the site or have the owner's permission.", 'owner: English message' );
$r = cmp_check_handle( $cmp_cd_req( array( 'post' => array( 'owner' => '1' ) ) ), $cmp_cd_od, $cmp_cd_svc, 1700000000, $cmp_cd_ok );
cmp_assert( 400 === $r['code'] && 'bad_request' === $r['body']['code'], 'owner: no url -> bad_request first' );
cmp_assert( ! file_exists( $cmp_cd_od ), 'owner: still nothing written' );

// Accepted forms: JSON 1, "1", true; form "1".
$i = 0;
foreach ( array(
	'JSON 1'    => array( 'content_type' => 'application/json', 'body' => '{"url":"exemplu.md","owner":1}' ),
	'JSON "1"'  => array( 'content_type' => 'application/json', 'body' => '{"url":"exemplu.md","owner":"1"}' ),
	'JSON true' => array( 'content_type' => 'application/json; charset=utf-8', 'body' => '{"url":"exemplu.md","owner":true}' ),
	'form "1"'  => array( 'post' => array( 'url' => 'exemplu.md', 'owner' => '1' ) ),
) as $label => $over ) {
	$i++;
	$r = cmp_check_handle( $cmp_cd_req( array_merge( $over, array( 'ip' => '192.0.2.' . ( 100 + $i ) ) ) ), $cmp_cd_tmp . '/owner-ok', $cmp_cd_svc, 1700000000, $cmp_cd_ok );
	cmp_assert( 200 === $r['code'] && 'html-v2' === $r['body']['engine'], "owner: {$label} accepted" );
}
cmp_assert( 'owner_required' === cmp_check_error( 'owner_required', 'ro' )['body']['code'] && 400 === cmp_check_error_status( 'owner_required' ), 'owner: error code 400' );

// The injected fetcher path: links found, the sub-page analysed; the response shape.
$r = cmp_check_handle( $cmp_cd_req( array( 'post' => array( 'url' => 'exemplu.md', 'owner' => '1' ), 'ip' => '192.0.2.150' ) ), $cmp_cd_tmp . '/shape', $cmp_cd_svc, 1700000000, $cmp_cd_ok );
cmp_assert( 200 === $r['code'] && array( 'https://exemplu.md/', 'https://exemplu.md/despre', 'https://exemplu.md/privacy' ) === $r['body']['pages']['checked'] && 'links' === $r['body']['pages']['total_source'], 'handle: homepage + link pages checked' );
$k = cmp_cd_keys( $r['body'] );
cmp_assert( ! isset( $k['rank'] ) && ! isset( $k['detail'] ), 'handle: no rank, no detail in the response' );
cmp_assert( 'clean' === $r['body']['status'] && in_array( 'policy', array_column( $r['body']['passed'], 'id' ), true ), 'handle: clean site -> clean, policy passed' );
// A sub-page whose (injected) answer claims another host is never analysed.
$r = cmp_check_handle(
	$cmp_cd_req( array( 'post' => array( 'url' => 'exemplu.md', 'owner' => '1' ), 'ip' => '192.0.2.151' ) ),
	$cmp_cd_tmp . '/shape',
	$cmp_cd_svc,
	1700000000,
	function ( $u ) use ( $cmp_cd_ok ) {
		$res = $cmp_cd_ok( $u );
		if ( 'https://exemplu.md/despre' === $u ) {
			$res['final_url'] = 'https://evil.md/<script>';
			$res['body']      = '<script src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>';
		}
		return $res;
	}
);
cmp_assert( array() === $r['body']['pages']['failed'] && array( 'https://exemplu.md/', 'https://exemplu.md/privacy' ) === $r['body']['pages']['checked'] && false === strpos( json_encode( $r['body'] ), 'evil' ), 'handle: a sub-page answered from another host -> skipped, not analysed, not echoed' );
cmp_assert( 'clean' === $r['body']['status'], 'handle: a skipped off-site page does not make the result unsure' );

// New limits: per IP 5 a minute (6th -> 429), 50 a day; /48 15 a minute; global 30 a minute.
$cmp_cd_rl = $cmp_cd_tmp . '/limits';
$codes     = array();
for ( $n = 0; $n < 6; $n++ ) {
	$r       = cmp_check_handle( $cmp_cd_req( array( 'post' => array( 'url' => 'exemplu.md', 'owner' => '1' ), 'ip' => '198.51.100.9' ) ), $cmp_cd_rl, $cmp_cd_svc, 1700000000 + $n, $cmp_cd_ok );
	$codes[] = $r['code'];
}
cmp_assert_eq( $codes, array( 200, 200, 200, 200, 200, 429 ), 'limits: 5 a minute per IP, the 6th -> 429' );
cmp_assert( isset( $r['headers']['Retry-After'] ) && 'rate_limited' === $r['body']['code'], 'limits: 429 rate_limited with Retry-After' );
cmp_assert_eq( cmp_check_rate_limits(), array( 60 => 5, 86400 => 50 ), 'limits: per IP 5/min, 50/day' );
cmp_assert_eq( cmp_check_rate_limits_48(), array( 60 => 15, 86400 => 150 ), 'limits: IPv6 /48 15/min, 150/day' );
cmp_assert_eq( cmp_check_rate_limits_global(), array( 60 => 30, 86400 => 1000 ), 'limits: global 30/min, 1000/day' );
cmp_assert_eq( cmp_check_slot_count(), 3, 'limits: 3 slots' );
$cmp_cd_gl = $cmp_cd_tmp . '/limits-global';
$codes     = array();
for ( $n = 0; $n < 31; $n++ ) {
	$r       = cmp_check_handle( $cmp_cd_req( array( 'post' => array( 'url' => 'exemplu.md', 'owner' => '1' ), 'ip' => '203.0.113.' . ( $n + 1 ) ) ), $cmp_cd_gl, $cmp_cd_svc, 1700000000, $cmp_cd_ok );
	$codes[] = $r['code'];
}
cmp_assert( 30 === count( array_keys( $codes, 200, true ) ) && 503 === end( $codes ) && 'busy' === $r['body']['code'], 'limits: the 31st check in a minute (any IPs) -> 503 busy' );

// One slot is held for the whole multi-page check (taken before the first fetch, free after).
$cmp_cd_sd   = $cmp_cd_tmp . '/slot-hold';
$cmp_cd_free = array();
$r           = cmp_check_handle(
	$cmp_cd_req( array( 'post' => array( 'url' => 'exemplu.md', 'owner' => '1' ) ) ),
	$cmp_cd_sd,
	$cmp_cd_svc,
	1700000000,
	function ( $u ) use ( $cmp_cd_ok, $cmp_cd_sd, &$cmp_cd_free ) {
		// While the check runs, only 2 more slots can be taken.
		$a = cmp_check_slot_acquire( $cmp_cd_sd );
		$b = cmp_check_slot_acquire( $cmp_cd_sd );
		$c = cmp_check_slot_acquire( $cmp_cd_sd );
		$cmp_cd_free[] = ( false !== $a ) + ( false !== $b ) + ( false !== $c );
		foreach ( array( $a, $b, $c ) as $h ) {
			cmp_check_slot_release( $h );
		}
		return $cmp_cd_ok( $u );
	}
);
cmp_assert( 200 === $r['code'] && count( $cmp_cd_free ) > 2 && array( 2 ) === array_values( array_unique( $cmp_cd_free ) ), 'slots: one slot held during every request of the check' );
$h3 = array( cmp_check_slot_acquire( $cmp_cd_sd ), cmp_check_slot_acquire( $cmp_cd_sd ), cmp_check_slot_acquire( $cmp_cd_sd ) );
cmp_assert( false !== $h3[0] && false !== $h3[1] && false !== $h3[2], 'slots: released after the check' );
foreach ( $h3 as $h ) {
	cmp_check_slot_release( $h );
}

/* ------------------------------------------------------------------
 * Fix round (security review): IP homepage, mapped IPv4 keys, budgets.
 * ------------------------------------------------------------------ */
// M2: a homepage that ends on a bare IP address is refused; nothing is discovered there.
foreach ( array( 'https://8.8.8.8/', 'http://[2a00:1450::1]/', 'https://93.184.216.34/pagina' ) as $cmp_cd_ipf ) {
	$asked = array();
	$r     = cmp_check_handle(
		$cmp_cd_req( array( 'post' => array( 'url' => 'exemplu.md', 'owner' => '1' ), 'ip' => '192.0.2.170' ) ),
		$cmp_cd_tmp . '/ip-home',
		$cmp_cd_svc,
		1700000000 + count( $asked ),
		function ( $u ) use ( &$asked, $cmp_cd_ipf ) {
			$asked[] = $u;
			return array( 'ok' => true, 'status' => 200, 'headers' => array( 'content-type' => 'text/html' ), 'body' => '<a href="/x">x</a>', 'final_url' => $cmp_cd_ipf, 'error' => '' );
		}
	);
	cmp_assert( 200 === $r['code'] && 'fetch_failed' === $r['body']['code'] && 1 === count( $asked ), "M2: homepage redirected to {$cmp_cd_ipf} -> fetch_failed, no discovery" );
}

// M4: an IPv4-mapped IPv6 client is the IPv4 client.
cmp_assert_eq( array_keys( cmp_check_rate_keys( '::ffff:203.0.113.5' ) ), array_keys( cmp_check_rate_keys( '203.0.113.5' ) ), 'M4: ::ffff:203.0.113.5 shares the bucket of 203.0.113.5' );
cmp_assert_eq( array_keys( cmp_check_rate_keys( '::FFFF:cb00:7105' ) ), array_keys( cmp_check_rate_keys( '203.0.113.5' ) ), 'M4: hex form of the mapped address too' );
cmp_assert( 2 === count( cmp_check_rate_keys( '2a00:1450::ffff:cb00:7105' ) ), 'M4: a real IPv6 address keeps its /64 and /48 buckets' );
$cmp_cd_m4 = $cmp_cd_tmp . '/m4';
for ( $n = 0; $n < 5; $n++ ) {
	cmp_check_rate_check( '203.0.113.77', $cmp_cd_m4, 1700000000 );
}
cmp_assert_eq( cmp_check_rate_check( '::ffff:203.0.113.77', $cmp_cd_m4, 1700000000 ), 'limited', 'M4: the mapped form cannot get 5 more checks' );

// I1b: discovery stops at $until (no new request or parse after it).
$asked = array();
$t     = microtime( true );
cmp_check_discover(
	'https://exemplu.md/',
	'<a href="/a">a</a>',
	function ( $u ) use ( &$asked ) {
		$asked[] = $u;
		usleep( 300000 );
		return array( 'ok' => true, 'status' => 404, 'headers' => array(), 'body' => '', 'final_url' => $u, 'error' => '' );
	},
	null,
	microtime( true ) + 0.2
);
cmp_assert( 1 === count( $asked ) && microtime( true ) - $t < 0.6, 'I1b: discovery past $until starts nothing more' );

// I1c: a sub-page over 1 MB is never analysed (even from an injected fetcher); no analysis after the budget.
$r = cmp_check_handle(
	$cmp_cd_req( array( 'post' => array( 'url' => 'exemplu.md', 'owner' => '1' ), 'ip' => '192.0.2.171' ) ),
	$cmp_cd_tmp . '/i1c',
	$cmp_cd_svc,
	1700000000,
	function ( $u ) use ( $cmp_cd_ok ) {
		$res = $cmp_cd_ok( $u );
		if ( 'https://exemplu.md/despre' === $u ) {
			$res['body'] = str_repeat( 'a', 1048577 );
		}
		return $res;
	}
);
cmp_assert( array( array( 'url' => 'https://exemplu.md/despre', 'code' => 'too_large' ) ) === $r['body']['pages']['failed'], 'I1c: a 1 MB + 1 byte sub-page -> too_large' );
$r = cmp_check_site(
	'https://exemplu.md/',
	'ro',
	$cmp_cd_svc,
	function ( $u ) use ( $cmp_cd_ok ) {
		usleep( 150000 );
		return $cmp_cd_ok( $u );
	},
	array( 'analyse_after' => 0.1 )
);
cmp_assert( array( 'https://exemplu.md/' ) === $r['body']['pages']['checked'] && array() === $r['body']['pages']['failed'] && 'time' === $r['body']['pages']['stopped'] && true === $r['body']['pages']['capped'], 'I1c: past the analysis time no page is fetched: stopped (time), not unread' );
