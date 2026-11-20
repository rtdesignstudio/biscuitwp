<?php
/**
 * "Verifică-ți site-ul": the public endpoint (site/api/verifica.php), the
 * SSRF-safe fetcher (site/api/lib/fetch.php) and the rate limiter
 * (site/api/lib/ratelimit.php).
 *
 * Deterministic and offline: network tests talk only to a local `php -S`
 * fixture (tests/fixtures/site-check-fetch/router.php) on a free loopback port,
 * through an injected resolver, an injected list of allowed ports and the
 * test-only loopback exception of cmp_check_fetch() (function parameters).
 * No request ever goes to a real third-party site.
 */
if ( ! defined( 'CMP_CHECK' ) ) {
	define( 'CMP_CHECK', true );
}
$cmp_cf_api = __DIR__ . '/../site/api';
foreach ( array( 'lib/check.php', 'lib/fetch.php', 'lib/discover.php', 'lib/ratelimit.php', 'lib/endpoint.php', 'verifica.php' ) as $f ) {
	cmp_assert( is_file( $cmp_cf_api . '/' . $f ), "site check api: site/api/{$f} exists" );
}
if ( ! is_file( $cmp_cf_api . '/lib/fetch.php' ) || ! is_file( $cmp_cf_api . '/lib/ratelimit.php' ) || ! is_file( $cmp_cf_api . '/lib/endpoint.php' ) ) {
	return;
}
require_once $cmp_cf_api . '/lib/check.php';
require_once $cmp_cf_api . '/lib/fetch.php';
require_once $cmp_cf_api . '/lib/discover.php';
require_once $cmp_cf_api . '/lib/ratelimit.php';
require_once $cmp_cf_api . '/lib/endpoint.php';

/* ------------------------------------------------------------------
 * Helpers.
 * ------------------------------------------------------------------ */

/** A free TCP port on 127.0.0.1. */
function cmp_cf_free_port() {
	$s = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
	if ( ! $s ) {
		return 0;
	}
	$name = stream_socket_get_name( $s, false );
	fclose( $s );
	return (int) substr( (string) $name, strrpos( (string) $name, ':' ) + 1 );
}

/** Start `php -S` on a free port; returns [process, port] or [null, 0]. */
function cmp_cf_serve( array $args, array $env = array() ) {
	$port = cmp_cf_free_port();
	if ( ! $port ) {
		return array( null, 0 );
	}
	$full = getenv();
	foreach ( $env as $k => $v ) {
		$full[ $k ] = $v;
	}
	// Same ini as this process (the endpoint needs json, hash, curl).
	$ini = php_ini_loaded_file();
	$cmd = array_merge( array( PHP_BINARY ), $ini ? array( '-c', $ini ) : array(), array( '-S', '127.0.0.1:' . $port ), $args );
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

/** Raw HTTP request to the local test server: [status, headers (lowercase name => list), body]. */
function cmp_cf_http( $port, $method, $path, $body = '', $content_type = '', array $extra = array() ) {
	$c = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 5 );
	if ( ! $c ) {
		return array( 0, array(), '' );
	}
	stream_set_timeout( $c, 15 );
	$req = $method . ' ' . $path . " HTTP/1.0\r\nHost: 127.0.0.1:" . $port . "\r\nConnection: close\r\n";
	if ( '' !== $content_type ) {
		$req .= 'Content-Type: ' . $content_type . "\r\n";
	}
	foreach ( $extra as $k => $v ) {
		$req .= $k . ': ' . $v . "\r\n";
	}
	if ( 'POST' === $method ) {
		$req .= 'Content-Length: ' . strlen( $body ) . "\r\n";
	}
	fwrite( $c, $req . "\r\n" . $body );
	$raw = stream_get_contents( $c );
	fclose( $c );
	$split = strpos( (string) $raw, "\r\n\r\n" );
	if ( false === $split ) {
		return array( 0, array(), (string) $raw );
	}
	$lines   = explode( "\r\n", substr( $raw, 0, $split ) );
	$status  = preg_match( '#^HTTP/\S+\s+(\d{3})#', $lines[0], $m ) ? (int) $m[1] : 0;
	$headers = array();
	foreach ( array_slice( $lines, 1 ) as $l ) {
		$p = strpos( $l, ':' );
		if ( false !== $p ) {
			$headers[ strtolower( trim( substr( $l, 0, $p ) ) ) ][] = trim( substr( $l, $p + 1 ) );
		}
	}
	return array( $status, $headers, (string) substr( $raw, $split + 4 ) );
}

function cmp_cf_rmdir( $dir ) {
	if ( ! is_dir( $dir ) || is_link( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) as $f ) {
		if ( '.' === $f || '..' === $f ) {
			continue;
		}
		$p = $dir . '/' . $f;
		is_dir( $p ) && ! is_link( $p ) ? cmp_cf_rmdir( $p ) : unlink( $p );
	}
	rmdir( $dir );
}

$cmp_cf_tmp = sys_get_temp_dir() . '/cmp-check-fetch-test-' . getmypid() . '-' . mt_rand();
mkdir( $cmp_cf_tmp, 0700, true );
$cmp_cf_procs = array();
register_shutdown_function(
	function () use ( &$cmp_cf_procs, $cmp_cf_tmp ) {
		foreach ( $cmp_cf_procs as $p ) {
			if ( is_resource( $p ) ) {
				proc_terminate( $p );
			}
		}
		cmp_cf_rmdir( $cmp_cf_tmp );
	}
);

/* ------------------------------------------------------------------
 * Web access to the libraries is harmless (guard).
 * ------------------------------------------------------------------ */
foreach ( array( 'check.php', 'signatures.php', 'fetch.php', 'discover.php', 'ratelimit.php', 'endpoint.php' ) as $f ) {
	$src = (string) file_get_contents( $cmp_cf_api . '/lib/' . $f );
	cmp_assert( 1 === preg_match( "#^<\?php\s*(?:/\*\*.*?\*/\s*)?if \( ! defined\( 'CMP_CHECK' \) \) \{\s*http_response_code\( 404 \);\s*exit;\s*\}#s", $src ), "lib/{$f}: starts with the CMP_CHECK guard" );
}
$cmp_cf_ht = (string) @file_get_contents( $cmp_cf_api . '/.htaccess' );
cmp_assert( 1 === preg_match( '#^\s*RewriteRule\s+\^lib/\s+-\s+\[F#m', $cmp_cf_ht ), 'site/api/.htaccess: blocks lib/ with a RewriteRule' );

cmp_assert( 1 === preg_match( "#ini_set\\( 'display_errors', '0' \\);#", (string) file_get_contents( $cmp_cf_api . '/verifica.php' ) ), 'verifica.php: display_errors off' );
cmp_assert( false === strpos( (string) file_get_contents( $cmp_cf_api . '/lib/fetch.php' ), 'CURLOPT_ENCODING' ), 'fetch.php: no CURLOPT_ENCODING (no decompression)' );
// Nothing logs or prints the URL.
foreach ( array( 'lib/fetch.php', 'lib/discover.php', 'lib/ratelimit.php', 'lib/endpoint.php', 'verifica.php' ) as $f ) {
	$src = (string) file_get_contents( $cmp_cf_api . '/' . $f );
	foreach ( array( 'error_log', 'syslog', 'var_dump', 'print_r', 'eval(', 'file_put_contents', 'include $', 'require $', 'CURLOPT_FOLLOWLOCATION, true', 'CURLOPT_SSL_VERIFYPEER, false', 'getenv', '$_GET', '$_REQUEST', '$_COOKIE', 'HTTP_X_FORWARDED_FOR', 'Access-Control' ) as $bad ) {
		cmp_assert( false === strpos( $src, $bad ), "{$f}: does not use {$bad}" );
	}
}

/* ------------------------------------------------------------------
 * cmp_check_normalize_url().
 * ------------------------------------------------------------------ */
$cmp_cf_norm = array(
	'exemplu.md'                          => 'https://exemplu.md/',
	'  Exemplu.MD/Pagina?x=1#frag '       => 'https://exemplu.md/Pagina?x=1',
	'HTTP://www.exemplu.md'               => 'http://www.exemplu.md/',
	'https://exemplu.md:443/'             => 'https://exemplu.md/',
	'http://exemplu.md:80/a'              => 'http://exemplu.md/a',
	'https://exemplu.md:80/'              => 'https://exemplu.md:80/',
	'//exemplu.md/x'                      => 'https://exemplu.md/x',
	'exemplu.md:443'                      => 'https://exemplu.md/',
	'https://exemplu.md./'                => 'https://exemplu.md/',
	'https://exemplu.md/a b/ș'            => 'https://exemplu.md/a%20b/%C8%99',
	'https://bücher.de/'                  => 'https://xn--bcher-kva.de/',
	'ștefan.md'                           => 'https://xn--tefan-e9b.md/',
	'https://8.8.8.8/'                    => 'https://8.8.8.8/',
	'https://[2a00:1450:0::1]/'           => 'https://[2a00:1450::1]/',
	'https://127.0.0.1/'                  => 'https://127.0.0.1/', // syntactically fine; the fetch refuses the address.
);
foreach ( $cmp_cf_norm as $in => $want ) {
	cmp_assert_eq( cmp_check_normalize_url( $in ), $want, "normalize_url: {$in}" );
}
foreach ( array(
	'',
	'   ',
	'file:///etc/passwd',
	'ftp://exemplu.md/',
	'javascript:alert(1)',
	'JavaScript:alert(1)',
	'data:text/html,<b>x</b>',
	'mailto:a@b.md',
	'gopher://exemplu.md/',
	'http:exemplu.md',
	'http:/exemplu.md',
	'http://user:pass@x.md/',
	'http://user@x.md/',
	'http://x.md@evil.md/',
	'http://x.md:8080/',
	'x.md:8080',
	'http://x.md:0/',
	'http://x.md:99999/',
	'http://x.md:44 3/',
	'http://x.md:-1/',
	'http://2130706433/',
	'http://0177.0.0.1/',
	'http://0x7f.0.0.1/',
	'http://0x7f000001/',
	'http://127.1/',
	'http://127.0.1/',
	'http://017700000001/',
	'http://1.2.3.4.5/',
	'http://[::1/',
	'http://[fe80::1%25en0]/',
	'http://[::ffff:127.0.0.1]x/',
	'http://localhost/',
	'http://LOCALHOST./',
	'http://foo.localhost/',
	'http://printer.local/',
	'http://db.internal/',
	'http://intranet/',
	'http://exemplu.md%2e/',
	'http://exe mplu.md/',
	"http://exemplu.md/\r\nX-Injected: 1",
	"http://exemplu.md/\0",
	'http://exemplu.md\\@evil.md/',
	'http://-bad-.md/',
	'http://' . str_repeat( 'a', 64 ) . '.md/',
	'http://exemplu.md/' . str_repeat( 'a', 2100 ),
	'http://exemplu..md/',
	'https://',
	'https:///x',
) as $bad ) {
	cmp_assert( false === cmp_check_normalize_url( $bad ), 'normalize_url refuses ' . json_encode( substr( $bad, 0, 60 ) ) );
}
cmp_assert_eq( cmp_check_normalize_url( 'http://x.md:8080/', array( 8080 ) ), 'http://x.md:8080/', 'normalize_url: an injected port list allows that port' );
cmp_assert( false === cmp_check_normalize_url( 'http://x.md/', array( 8080 ) ), 'normalize_url: the injected list replaces 80/443' );
cmp_assert_eq( cmp_check_punycode_label( 'bücher' ), 'xn--bcher-kva', 'punycode (fallback without intl): bücher' );
cmp_assert_eq( cmp_check_punycode_label( 'ștefan' ), 'xn--tefan-e9b', 'punycode (fallback without intl): ștefan' );
cmp_assert_eq( cmp_check_punycode_label( 'münchen' ), 'xn--mnchen-3ya', 'punycode (fallback without intl): münchen' );
cmp_assert_eq( cmp_check_punycode_label( 'ascii' ), 'ascii', 'punycode: ASCII label unchanged' );
cmp_assert_eq( cmp_check_resolve_location( 'https://a.md/x/y?q', '/z' ), 'https://a.md/z', 'location: absolute path' );
cmp_assert_eq( cmp_check_resolve_location( 'https://a.md/x/y?q', 'z' ), 'https://a.md/x/z', 'location: relative path' );
cmp_assert_eq( cmp_check_resolve_location( 'https://a.md/x/y', '?p=1' ), 'https://a.md/x/y?p=1', 'location: query only' );
cmp_assert_eq( cmp_check_resolve_location( 'https://a.md:8443/x', '/z' ), 'https://a.md:8443/z', 'location: keeps the port' );
cmp_assert_eq( cmp_check_resolve_location( 'https://a.md/x', '//b.md/z' ), 'https://b.md/z', 'location: scheme-relative' );
cmp_assert_eq( cmp_check_resolve_location( 'https://a.md/x', 'http://b.md/' ), 'http://b.md/', 'location: absolute' );
cmp_assert( false === cmp_check_resolve_location( 'https://a.md/x', 'file:///etc/passwd' ), 'location: file: refused' );
cmp_assert( false === cmp_check_resolve_location( 'https://a.md/x', 'localhost:80' ), 'location: scheme-like value without // refused' );

/* ------------------------------------------------------------------
 * cmp_check_ip_allowed().
 * ------------------------------------------------------------------ */
foreach ( array(
	'127.0.0.1', '127.255.255.254', '10.0.0.1', '172.16.0.1', '172.31.255.255', '192.168.1.1', '169.254.169.254', '100.64.0.1', '100.127.255.255',
	'0.0.0.0', '0.1.2.3', '224.0.0.1', '239.255.255.250', '240.0.0.1', '255.255.255.255', '192.0.0.8', '192.0.2.1', '192.88.99.1', '198.18.0.1',
	'198.19.255.255', '198.51.100.7', '203.0.113.9',
	'::', '::1', 'fe80::1', 'febf::1', 'fc00::1', 'fd12:3456::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::ffff:169.254.169.254', '::ffff:7f00:1',
	'::127.0.0.1', '64:ff9b::7f00:1', '64:ff9b::a9fe:a9fe', '64:ff9b:1::1', '100::1', '2001:db8::1', 'ff02::1', 'ff00::', '2002:7f00:1::1', '2002:a9fe:a9fe::',
	'2001::1', '2001:0:4136:e378::1', '2001:10::1', '2001:20::1', '3fff::1', '5f00::1', '::2', '1::1', 'not-an-ip', '', '8.8.8.8 ', '010.0.0.1', '1.2.3', 'fe80::1%en0',
) as $ip ) {
	cmp_assert( false === cmp_check_ip_allowed( $ip ), 'ip_allowed refuses ' . json_encode( $ip ) );
}
foreach ( array( '8.8.8.8', '1.1.1.1', '93.184.216.34', '172.15.255.255', '172.32.0.1', '100.63.255.255', '100.128.0.1', '198.17.255.255', '198.20.0.1', '2a00:1450::1', '2606:4700:4700::1111', '::ffff:8.8.8.8', '64:ff9b::808:808', '2002:808:808::1', '2400:cb00::1' ) as $ip ) {
	cmp_assert( true === cmp_check_ip_allowed( $ip ), 'ip_allowed accepts ' . $ip );
}

/* ------------------------------------------------------------------
 * cmp_check_fetch(), against the local fixture server.
 * ------------------------------------------------------------------ */
// Without the loopback exception, a name that resolves to 127.0.0.1 is refused before any connection.
$cmp_cf_calls = 0;
$cmp_cf_r     = cmp_check_fetch(
	'http://loop.cmp-check.test/',
	function ( $host ) use ( &$cmp_cf_calls ) {
		$cmp_cf_calls++;
		return array( '127.0.0.1' );
	}
);
cmp_assert( false === $cmp_cf_r['ok'] && 'fetch_failed' === $cmp_cf_r['error'] && 'private_address' === $cmp_cf_r['detail'], 'fetch: a name resolving to 127.0.0.1 is refused (fetch_failed, private_address)' );
cmp_assert_eq( $cmp_cf_calls, 1, 'fetch: the resolver is asked once' );
foreach ( array( array( '8.8.8.8', '10.0.0.1' ), array( '2a00:1450::1', '::1' ), array( '8.8.8.8', 'garbage' ), array() ) as $ips ) {
	$r = cmp_check_fetch(
		'https://mixed.cmp-check.test/',
		function ( $host ) use ( $ips ) {
			return $ips;
		}
	);
	cmp_assert( false === $r['ok'] && 'fetch_failed' === $r['error'] && ( $ips ? 'private_address' === $r['detail'] : 'dns' === $r['detail'] ), 'fetch: refuses when not every resolved address is allowed: ' . json_encode( $ips ) );
}
foreach ( array( 'http://127.0.0.1/', 'http://[::1]/', 'http://[::ffff:127.0.0.1]/', 'http://169.254.169.254/latest/', 'http://10.1.2.3/' ) as $u ) {
	$r = cmp_check_fetch(
		$u,
		function ( $host ) {
			return array( '8.8.8.8' );
		}
	);
	cmp_assert( false === $r['ok'] && 'blocked_address' === $r['error'], "fetch: IP literal {$u} refused" );
}
foreach ( array( 'http://2130706433/', 'http://0177.0.0.1/', 'http://user:pass@x.md/', 'file:///etc/passwd', 'http://x.md:8080/' ) as $u ) {
	$r = cmp_check_fetch( $u, function ( $host ) {
		return array( '8.8.8.8' );
	} );
	cmp_assert( false === $r['ok'] && 'bad_url' === $r['error'], "fetch: {$u} is a bad URL" );
}

list( $cmp_cf_proc, $cmp_cf_port ) = cmp_cf_serve( array( __DIR__ . '/fixtures/site-check-fetch/router.php' ) );
$cmp_cf_procs[] = $cmp_cf_proc;
cmp_assert( $cmp_cf_port > 0, 'fixture server started' );
if ( $cmp_cf_port > 0 ) {
	$cmp_cf_base     = 'http://fixture.cmp-check.test:' . $cmp_cf_port;
	$cmp_cf_resolved = array();
	$cmp_cf_resolver = function ( $host ) use ( &$cmp_cf_resolved ) {
		$cmp_cf_resolved[] = $host;
		$map = array(
			'fixture.cmp-check.test'  => array( '127.0.0.1' ),
			'internal.cmp-check.test' => array( '10.0.0.1' ),
		);
		return isset( $map[ $host ] ) ? $map[ $host ] : array();
	};
	$cmp_cf_fetch = function ( $path, $budget = 10 ) use ( $cmp_cf_base, $cmp_cf_resolver, $cmp_cf_port ) {
		return cmp_check_fetch( $cmp_cf_base . $path, $cmp_cf_resolver, array( $cmp_cf_port ), true, $budget );
	};

	// The loopback exception is off by default even with the port allowed.
	$r = cmp_check_fetch( $cmp_cf_base . '/ok', $cmp_cf_resolver, array( $cmp_cf_port ) );
	cmp_assert( false === $r['ok'] && 'fetch_failed' === $r['error'] && 'private_address' === $r['detail'], 'fetch: loopback is refused unless the test exception is passed' );

	$cmp_cf_resolved = array();
	// A normal page. The name exists only in the injected resolver (.test never
	// resolves in real DNS): success proves curl used the pinned address.
	$r = $cmp_cf_fetch( '/ok' );
	cmp_assert( true === $r['ok'] && '' === $r['error'], 'fetch: /ok succeeds through the pinned address' . ( $r['ok'] ? '' : ' (' . $r['error'] . ')' ) );
	cmp_assert_eq( $r['status'], 200, 'fetch: status 200' );
	cmp_assert_eq( $r['final_url'], $cmp_cf_base . '/ok', 'fetch: final_url' );
	cmp_assert( isset( $r['headers']['set-cookie'] ) && is_array( $r['headers']['set-cookie'] ) && 2 === count( $r['headers']['set-cookie'] ), 'fetch: both Set-Cookie headers kept' );
	cmp_assert( isset( $r['headers']['content-type'] ) && 0 === strpos( $r['headers']['content-type'], 'text/html' ), 'fetch: Content-Type kept' );
	$seen = preg_match( '#<pre id="seen">(.*?)</pre>#s', $r['body'], $m ) ? json_decode( html_entity_decode( $m[1] ), true ) : null;
	cmp_assert( is_array( $seen ) && 'ConsentManagerPro-Check/1.0 (+https://consent.rt.md/)' === $seen['ua'], 'fetch: sends our user-agent' );
	cmp_assert( is_array( $seen ) && '' === $seen['cookie'], 'fetch: sends no cookies' );
	cmp_assert( is_array( $seen ) && 0 === strpos( $seen['accept'], 'text/html' ), 'fetch: Accept text/html' );
	cmp_assert( is_array( $seen ) && 0 === strpos( $seen['lang'], 'ro' ) && false !== strpos( $seen['lang'], 'en' ), 'fetch: Accept-Language ro, en' );
	cmp_assert( is_array( $seen ) && 'GET' === $seen['method'], 'fetch: GET' );
	cmp_assert_eq( $cmp_cf_resolved, array( 'fixture.cmp-check.test' ), 'fetch: resolved once, the host only' );

	// Redirects: followed manually, each one re-validated.
	$r = $cmp_cf_fetch( '/relative' );
	cmp_assert( $r['ok'] && $cmp_cf_base . '/ok' === $r['final_url'], 'fetch: relative redirect followed' );
	$r = $cmp_cf_fetch( '/to-relative-root' );
	cmp_assert( $r['ok'] && $cmp_cf_base . '/ok' === $r['final_url'], 'fetch: root-relative 303 followed' );
	$r = $cmp_cf_fetch( '/chain/1' );
	cmp_assert( $r['ok'] && $cmp_cf_base . '/chain/4' === $r['final_url'], 'fetch: three redirects are followed' );
	$r = $cmp_cf_fetch( '/chain/0' );
	cmp_assert( false === $r['ok'] && 'too_many_redirects' === $r['error'], 'fetch: the fourth redirect is refused' );
	foreach ( array(
		'/to-metadata'        => 'blocked_address',
		'/to-loopback-mapped' => 'blocked_address',
		'/to-private-name'    => 'fetch_failed',
		'/to-decimal'         => 'bad_url',
		'/to-octal'           => 'bad_url',
		'/to-userinfo'        => 'bad_url',
		'/to-file'            => 'bad_url',
		'/to-gopher'          => 'bad_url',
		'/to-port'            => 'bad_url',
	) as $path => $code ) {
		$r = $cmp_cf_fetch( $path );
		cmp_assert( false === $r['ok'] && $code === $r['error'] && '' === $r['body'], "fetch: redirect {$path} refused ({$code})" . ( $r['ok'] ? '' : ', got ' . $r['error'] ) );
	}

	// Body limit: 2 MB.
	foreach ( array( '/big', '/big-cl' ) as $path ) {
		$r = $cmp_cf_fetch( $path );
		cmp_assert( false === $r['ok'] && 'too_large' === $r['error'] && strlen( $r['body'] ) <= 2097152, "fetch: {$path} over 2 MB refused" );
	}


	// No resolve call starts with less than 1 s of budget left (each hop is checked).
	$cmp_cf_slow_calls = 0;
	$r                 = cmp_check_fetch(
		$cmp_cf_base . '/chain/1',
		function ( $host ) use ( &$cmp_cf_slow_calls ) {
			$cmp_cf_slow_calls++;
			usleep( 600000 );
			return array( '127.0.0.1' );
		},
		array( $cmp_cf_port ),
		true,
		2
	);
	cmp_assert( false === $r['ok'] && 'timeout' === $r['error'] && 'dns_budget' === $r['detail'], 'fetch: slow DNS on later hops -> timeout (' . $r['error'] . '/' . $r['detail'] . ')' );
	cmp_assert_eq( $cmp_cf_slow_calls, 2, 'fetch: the third resolve is not started with under 1 s left' );

	// Compressed answers are never analysed (we ask for identity).
	foreach ( array( '/gzip', '/gzip-chain', '/br' ) as $path ) {
		$r = $cmp_cf_fetch( $path );
		cmp_assert( false === $r['ok'] && 'fetch_failed' === $r['error'] && 'encoded' === $r['detail'] && '' === $r['body'], "fetch: {$path} (Content-Encoding) refused" );
	}
	foreach ( array( '/gzip-raw', '/zstd-raw' ) as $path ) {
		$r = $cmp_cf_fetch( $path );
		cmp_assert( false === $r['ok'] && 'fetch_failed' === $r['error'] && 'compressed_body' === $r['detail'] && '' === $r['body'], "fetch: {$path} (compressed bytes, no Content-Encoding) refused" );
	}
	$r = $cmp_cf_fetch( '/identity' );
	cmp_assert( $r['ok'] && false !== strpos( $r['body'], 'plain' ), 'fetch: Content-Encoding identity accepted' );
	$r    = $cmp_cf_fetch( '/ok' );
	$seen = preg_match( '#<pre id="seen">(.*?)</pre>#s', $r['body'], $m ) ? json_decode( html_entity_decode( $m[1] ), true ) : null;
	cmp_assert( is_array( $seen ) && 'identity' === $seen['enc'], 'fetch: sends Accept-Encoding: identity' );

	// Non-2xx and non-HTML answers are returned as they are (the endpoint decides).
	$r = $cmp_cf_fetch( '/missing' );
	cmp_assert( $r['ok'] && 404 === $r['status'], 'fetch: a 404 is a response' );

	// Time budget (last: the single-threaded fixture keeps sleeping after curl gives up).
	$t = microtime( true );
	$r = $cmp_cf_fetch( '/slow', 2 );
	cmp_assert( false === $r['ok'] && 'timeout' === $r['error'], 'fetch: slow page times out' );
	cmp_assert( microtime( true ) - $t < 3.5, 'fetch: the time budget is respected' );

}

/* ------------------------------------------------------------------
 * cmp_check_rate_allow().
 * ------------------------------------------------------------------ */
$cmp_cf_rd = $cmp_cf_tmp . '/counters';
$cmp_cf_t0 = 1700000000;
$cmp_cf_ok = 0;
for ( $i = 0; $i < 5; $i++ ) {
	$cmp_cf_ok += cmp_check_rate_allow( '203.0.113.50', $cmp_cf_rd, $cmp_cf_t0 + $i ) ? 1 : 0;
}
cmp_assert_eq( $cmp_cf_ok, 5, 'rate: 5 checks in a minute allowed' );
cmp_assert( false === cmp_check_rate_allow( '203.0.113.50', $cmp_cf_rd, $cmp_cf_t0 + 30 ), 'rate: the 6th in the same minute refused' );
cmp_assert( true === cmp_check_rate_allow( '198.51.100.1', $cmp_cf_rd, $cmp_cf_t0 + 30 ), 'rate: another IP is counted apart' );
cmp_assert( true === cmp_check_rate_allow( '203.0.113.50', $cmp_cf_rd, $cmp_cf_t0 + 61 ), 'rate: allowed again after a minute' );
cmp_assert( is_dir( $cmp_cf_rd ) && 0700 === ( fileperms( $cmp_cf_rd ) & 0777 ), 'rate: counter folder created with 0700' );
$cmp_cf_salt = $cmp_cf_rd . '/.salt';
cmp_assert( is_file( $cmp_cf_salt ) && 0600 === ( fileperms( $cmp_cf_salt ) & 0777 ) && 1 === preg_match( '/^[0-9a-f]{64}$/', trim( (string) file_get_contents( $cmp_cf_salt ) ) ), 'rate: random salt in a 0600 file' );
$cmp_cf_all = '';
foreach ( scandir( $cmp_cf_rd ) as $f ) {
	if ( '.' !== $f && '..' !== $f ) {
		$cmp_cf_all .= $f . "\n" . file_get_contents( $cmp_cf_rd . '/' . $f ) . "\n";
		if ( '.salt' !== $f && '.cleanup' !== $f ) {
			cmp_assert( 1 === preg_match( '/^[0-9a-f]{64}\.cnt$/', $f ) && 0600 === ( fileperms( $cmp_cf_rd . '/' . $f ) & 0777 ), "rate: counter file {$f} is a hash, 0600" );
		}
	}
}
foreach ( array( '203.0.113.50', '198.51.100.1', '203.0.113', bin2hex( inet_pton( '203.0.113.50' ) ) ) as $needle ) {
	cmp_assert( false === strpos( $cmp_cf_all, $needle ), "rate: {$needle} not in any counter file" );
}
cmp_assert( false === strpos( $cmp_cf_all, hash( 'sha256', '203.0.113.50' ) ), 'rate: not an unsalted hash of the IP' );

// Day limit: 50 per IP, spread one a minute.
$cmp_cf_d  = $cmp_cf_tmp . '/counters-day';
$cmp_cf_ok = 0;
for ( $i = 0; $i < 50; $i++ ) {
	$cmp_cf_ok += cmp_check_rate_allow( '192.0.2.77', $cmp_cf_d, $cmp_cf_t0 + $i * 61 ) ? 1 : 0;
}
cmp_assert_eq( $cmp_cf_ok, 50, 'rate: 50 a day allowed' );
cmp_assert( false === cmp_check_rate_allow( '192.0.2.77', $cmp_cf_d, $cmp_cf_t0 + 50 * 61 ), 'rate: the 51st in the same day refused' );
cmp_assert( true === cmp_check_rate_allow( '192.0.2.77', $cmp_cf_d, $cmp_cf_t0 + 86400 + 120 ), 'rate: allowed again a day later' );

// IPv6: one /64 is one visitor.
$cmp_cf_6 = $cmp_cf_tmp . '/counters-v6';
for ( $i = 0; $i < 5; $i++ ) {
	cmp_check_rate_allow( '2a00:1450:4001:81c::' . dechex( $i + 1 ), $cmp_cf_6, $cmp_cf_t0 );
}
cmp_assert( false === cmp_check_rate_allow( '2a00:1450:4001:81c:ffff::1', $cmp_cf_6, $cmp_cf_t0 ), 'rate: IPv6 counted per /64' );
// ... and per /48 (15 a minute, 150 a day): many /64s of one /48 share it.
$cmp_cf_48 = $cmp_cf_tmp . '/counters-v6-48';
$cmp_cf_ok = 0;
for ( $n = 1; $n <= 3; $n++ ) {
	for ( $i = 0; $i < 5; $i++ ) {
		$cmp_cf_ok += cmp_check_rate_allow( '2a00:1450:4001:' . $n . '::' . dechex( $i + 1 ), $cmp_cf_48, $cmp_cf_t0 ) ? 1 : 0;
	}
}
cmp_assert_eq( $cmp_cf_ok, 15, 'rate: three /64s of one /48 get 5 each' );
cmp_assert( false === cmp_check_rate_allow( '2a00:1450:4001:4::1', $cmp_cf_48, $cmp_cf_t0 ), 'rate: a fourth /64 of the same /48 is refused (the /48 bucket is full)' );
cmp_assert( true === cmp_check_rate_allow( '2a00:1450:4002:4::1', $cmp_cf_48, $cmp_cf_t0 ), 'rate: another /48 is counted apart' );
cmp_assert_eq( count( preg_grep( '/^[0-9a-f]{64}\.cnt$/', scandir( $cmp_cf_48 ) ) ), 6, 'rate: one file per allowed /64 (four) plus one per /48 (two); the refused /64 got none' );
$cmp_cf_before = count( preg_grep( '/^[0-9a-f]{64}\.cnt$/', scandir( $cmp_cf_48 ) ) );
$cmp_cf_ok     = 0;
for ( $i = 0; $i < 50; $i++ ) {
	$cmp_cf_ok += cmp_check_rate_allow( '2a00:1450:4001:' . dechex( 0x100 + $i ) . '::1', $cmp_cf_48, $cmp_cf_t0 + 1 ) ? 1 : 0;
}
cmp_assert_eq( $cmp_cf_ok, 0, 'rate: 50 new /64s inside a full /48 are refused' );
cmp_assert_eq( count( preg_grep( '/^[0-9a-f]{64}\.cnt$/', scandir( $cmp_cf_48 ) ) ), $cmp_cf_before, 'rate: ... and create no new counter files' );

// Global volume: 30 a minute, 1000 a day, for everybody together.
$cmp_cf_g  = $cmp_cf_tmp . '/counters-global';
$cmp_cf_ok = 0;
for ( $i = 0; $i < 30; $i++ ) {
	$cmp_cf_ok += 'ok' === cmp_check_rate_global( $cmp_cf_g, $cmp_cf_t0 ) ? 1 : 0;
}
cmp_assert_eq( $cmp_cf_ok, 30, 'global: 30 a minute' );
cmp_assert_eq( cmp_check_rate_global( $cmp_cf_g, $cmp_cf_t0 + 1 ), 'limited', 'global: the 31st in a minute is refused' );
cmp_assert( is_file( $cmp_cf_g . '/global.cnt' ) && 0600 === ( fileperms( $cmp_cf_g . '/global.cnt' ) & 0777 ), 'global: global.cnt, 0600' );
$cmp_cf_g2 = $cmp_cf_tmp . '/counters-global-day';
$cmp_cf_ok = 0;
for ( $i = 0; $i < 1000; $i++ ) {
	$cmp_cf_ok += 'ok' === cmp_check_rate_global( $cmp_cf_g2, $cmp_cf_t0 + $i * 3 ) ? 1 : 0;
}
cmp_assert_eq( $cmp_cf_ok, 1000, 'global: 1000 a day' );
cmp_assert_eq( cmp_check_rate_global( $cmp_cf_g2, $cmp_cf_t0 + 3000 + 120 ), 'limited', 'global: the 1001st in a day is refused' );

// Concurrency: 3 slots for the whole account.
$cmp_cf_sd = $cmp_cf_tmp . '/counters-slots';
mkdir( $cmp_cf_sd, 0700 );
$cmp_cf_s1 = cmp_check_slot_acquire( $cmp_cf_sd );
$cmp_cf_s2 = cmp_check_slot_acquire( $cmp_cf_sd );
$cmp_cf_s3 = cmp_check_slot_acquire( $cmp_cf_sd );
cmp_assert( is_resource( $cmp_cf_s1 ) && is_resource( $cmp_cf_s2 ) && is_resource( $cmp_cf_s3 ), 'slots: three can be taken' );
cmp_assert( false === cmp_check_slot_acquire( $cmp_cf_sd ), 'slots: a fourth is refused' );
cmp_check_slot_release( $cmp_cf_s2 );
$cmp_cf_s4 = cmp_check_slot_acquire( $cmp_cf_sd );
cmp_assert( is_resource( $cmp_cf_s4 ), 'slots: a released slot can be taken again' );
cmp_check_slot_release( $cmp_cf_s1 );
cmp_check_slot_release( $cmp_cf_s3 );
cmp_check_slot_release( $cmp_cf_s4 );

// Cleanup reads a bounded number of entries per run.
cmp_assert( cmp_check_rate_cleanup_cap() > 0 && cmp_check_rate_cleanup_cap() <= 10000, 'rate: cleanup is capped per run' );

// Old files are removed.
$cmp_cf_c = $cmp_cf_tmp . '/counters-clean';
cmp_check_rate_allow( '192.0.2.1', $cmp_cf_c, $cmp_cf_t0 );
$cmp_cf_old = glob( $cmp_cf_c . '/*.cnt' );
file_put_contents( $cmp_cf_c . '/keep.txt', 'not ours' );
touch( $cmp_cf_c . '/keep.txt', $cmp_cf_t0 - 5 * 86400 );
cmp_check_rate_allow( '192.0.2.2', $cmp_cf_c, $cmp_cf_t0 + 86400 + 7200 );
cmp_assert( 1 === count( $cmp_cf_old ) && ! file_exists( $cmp_cf_old[0] ), 'rate: counter files older than a day are deleted' );
cmp_assert( 1 === count( glob( $cmp_cf_c . '/*.cnt' ) ), 'rate: the fresh counter stays' );
cmp_assert( is_file( $cmp_cf_c . '/keep.txt' ) && is_file( $cmp_cf_c . '/.salt' ), 'rate: only counter files are deleted' );

// An unusable folder fails closed.
file_put_contents( $cmp_cf_tmp . '/a-file', 'x' );
cmp_assert( false === cmp_check_rate_allow( '192.0.2.1', $cmp_cf_tmp . '/a-file', $cmp_cf_t0 ), 'rate: unusable folder -> refused' );
cmp_assert_eq( cmp_check_rate_check( '192.0.2.1', $cmp_cf_tmp . '/a-file', $cmp_cf_t0 ), 'error', 'rate: unusable folder -> error' );
@symlink( $cmp_cf_rd, $cmp_cf_tmp . '/link' );
if ( is_link( $cmp_cf_tmp . '/link' ) ) {
	cmp_assert_eq( cmp_check_rate_check( '192.0.2.1', $cmp_cf_tmp . '/link', $cmp_cf_t0 ), 'error', 'rate: a symlinked folder is refused' );
}

// Default folder: computed from the library's location, outside the web root.
$cmp_cf_def = cmp_check_rate_default_dir();
$cmp_cf_home = function_exists( 'posix_getpwuid' ) && function_exists( 'posix_geteuid' ) ? posix_getpwuid( posix_geteuid() ) : false;
$cmp_cf_want = is_array( $cmp_cf_home ) && ! empty( $cmp_cf_home['dir'] ) ? rtrim( $cmp_cf_home['dir'], '/' ) . '/tmp/cmp-check' : dirname( realpath( $cmp_cf_api . '/lib' ), 4 ) . '/tmp/cmp-check';
cmp_assert_eq( $cmp_cf_def, $cmp_cf_want, 'rate: default folder is <home>/tmp/cmp-check (posix home, else from __DIR__)' );
$cmp_cf_docroot = $cmp_cf_tmp . '/www';
mkdir( $cmp_cf_docroot . '/site', 0700, true );
cmp_assert( true === cmp_check_dir_inside( $cmp_cf_docroot . '/site/tmp/cmp-check', $cmp_cf_docroot ), 'docroot: a folder (not yet created) inside the web root is detected' );
cmp_assert( true === cmp_check_dir_inside( $cmp_cf_docroot, $cmp_cf_docroot . '/' ), 'docroot: the web root itself is inside' );
cmp_assert( true === cmp_check_dir_inside( $cmp_cf_docroot . '/site/../site/x', $cmp_cf_docroot ), 'docroot: .. is resolved' );
cmp_assert( false === cmp_check_dir_inside( $cmp_cf_tmp . '/www-other/x', $cmp_cf_docroot ), 'docroot: a sibling with the same prefix is outside' );
cmp_assert( false === cmp_check_dir_inside( $cmp_cf_tmp . '/counters', '' ), 'docroot: no web root -> not inside' );

/* ------------------------------------------------------------------
 * cmp_check_handle() with an injected fetcher (no network).
 * ------------------------------------------------------------------ */
$cmp_cf_svc = $cmp_cf_tmp . '/services.json';
copy( __DIR__ . '/../data/services.json', $cmp_cf_svc );
$cmp_cf_hn  = 0;
$cmp_cf_h   = function ( array $req, $fetcher = null, $svc = null ) use ( $cmp_cf_tmp, $cmp_cf_svc, &$cmp_cf_hn ) {
	$cmp_cf_hn++;
	$req = array_merge( array( 'method' => 'POST', 'content_type' => 'application/x-www-form-urlencoded', 'body' => '', 'post' => array(), 'ip' => '192.0.2.' . $cmp_cf_hn ), $req );
	if ( is_array( $req['post'] ) && isset( $req['post']['url'] ) && ! isset( $req['post']['owner'] ) ) {
		$req['post']['owner'] = '1'; // The owner box is ticked unless a test says otherwise.
	}
	return cmp_check_handle( $req, $cmp_cf_tmp . '/handle-counters', null === $svc ? $cmp_cf_svc : $svc, 1700000000 + 2 * $cmp_cf_hn, $fetcher );
};
$cmp_cf_page = function ( $html, $headers = array( 'content-type' => 'text/html; charset=utf-8' ), $status = 200, $final = 'https://exemplu.md/' ) {
	return function ( $url ) use ( $html, $headers, $status, $final ) {
		return array( 'ok' => true, 'status' => $status, 'headers' => $headers, 'body' => $html, 'final_url' => $final, 'error' => '' );
	};
};
$r = $cmp_cf_h( array( 'method' => 'GET' ) );
cmp_assert( 405 === $r['code'] && 'method_not_allowed' === $r['body']['code'] && isset( $r['headers']['Allow'] ), 'handle: GET -> 405 with Allow' );
$r = $cmp_cf_h( array() );
cmp_assert( 400 === $r['code'] && 'bad_request' === $r['body']['code'] && 'error' === $r['body']['status'], 'handle: no url -> 400 bad_request' );
$r = $cmp_cf_h( array( 'post' => array( 'url' => array( 'x' ) ) ) );
cmp_assert( 400 === $r['code'] && 'bad_request' === $r['body']['code'], 'handle: url as an array -> 400' );
$r = $cmp_cf_h( array( 'post' => array( 'url' => str_repeat( 'a', 2049 ) ) ) );
cmp_assert( 400 === $r['code'] && 'bad_url' === $r['body']['code'], 'handle: url over 2048 chars -> 400 bad_url' );
$r = $cmp_cf_h( array( 'post' => array( 'url' => '<script>alert(1)</script>' ) ) );
cmp_assert( 400 === $r['code'] && 'bad_url' === $r['body']['code'] && false === strpos( json_encode( $r['body'] ), 'script' ), 'handle: hostile url -> bad_url, never echoed' );
$r = $cmp_cf_h( array( 'content_type' => 'application/json', 'body' => '{"url":' ) );
cmp_assert( 400 === $r['code'] && 'bad_request' === $r['body']['code'], 'handle: broken JSON -> 400' );
$r = $cmp_cf_h( array( 'content_type' => 'application/json', 'body' => str_repeat( ' ', 20000 ) . '{"url":"exemplu.md"}' ) );
cmp_assert( 400 === $r['code'] && 'bad_request' === $r['body']['code'], 'handle: oversized JSON body -> 400' );
$cmp_cf_seen = array();
$r = $cmp_cf_h(
	array( 'content_type' => 'application/json; charset=utf-8', 'body' => '{"url":"exemplu.md","lang":"en","owner":1}' ),
	function ( $url ) use ( &$cmp_cf_seen, $cmp_cf_page ) {
		$cmp_cf_seen[] = $url;
		$f = $cmp_cf_page( '<!doctype html><html><head><title>x</title></head><body><p>Hello</p><a href="/privacy">Privacy policy</a></body></html>' );
		return $f( $url );
	}
);
cmp_assert( 200 === $r['code'] && 'clean' === $r['body']['status'] && 'html-v2' === $r['body']['engine'] && 'https://exemplu.md/' === $r['body']['checked_url'], 'handle: JSON request, clean page -> clean, html-v2, checked_url' );
cmp_assert( isset( $cmp_cf_seen[0] ) && 'https://exemplu.md/' === $cmp_cf_seen[0], 'handle: the fetcher gets the normalised URL first' );
cmp_assert( array_key_exists( 'findings', $r['body'] ) && array_key_exists( 'cmp', $r['body'] ), 'handle: findings and cmp present' );
$r = $cmp_cf_h( array( 'post' => array( 'url' => 'exemplu.md' ) ), $cmp_cf_page( '<html><head><script async src="https://www.googletagmanager.com/gtag/js?id=G-1"></script></head><body>x</body></html>' ) );
cmp_assert( 200 === $r['code'] && 'issues' === $r['body']['status'] && count( $r['body']['findings'] ) > 0, 'handle: form request, tracker -> issues' );
$r = $cmp_cf_h( array( 'post' => array( 'url' => 'exemplu.md' ) ), $cmp_cf_page( 'x', array( 'content-type' => 'text/html' ), 200, 'https://exemplu.md/<script>' ) );
cmp_assert( false === strpos( json_encode( $r['body'] ), '<script' ), 'handle: checked_url is sanitised' );
$r = $cmp_cf_h( array( 'post' => array( 'url' => 'exemplu.md' ) ), $cmp_cf_page( "\x89PNG", array( 'content-type' => 'image/png' ) ) );
cmp_assert( 200 === $r['code'] && 'error' === $r['body']['status'] && 'not_html' === $r['body']['code'], 'handle: non-HTML answer -> error not_html' );
$r = $cmp_cf_h( array( 'post' => array( 'url' => 'exemplu.md' ) ), $cmp_cf_page( '<html>gone</html>', array( 'content-type' => 'text/html' ), 503 ) );
cmp_assert( 200 === $r['code'] && 'error' === $r['body']['status'] && 'http_status' === $r['body']['code'] && false !== strpos( $r['body']['message'], '503' ), 'handle: HTTP 503 -> error http_status with the code' );
$r = $cmp_cf_h( array( 'post' => array( 'url' => 'exemplu.md' ) ), $cmp_cf_page( '<html><head><title>Just a moment...</title></head><body><script>window._cf_chl_opt={}</script></body></html>', array( 'content-type' => 'text/html' ), 403 ) );
cmp_assert( 200 === $r['code'] && 'error' === $r['body']['status'] && 'page:blocked' === $r['body']['findings'][0]['id'], 'handle: anti-bot 403 -> the analysis explains it' );
foreach ( array( 'timeout', 'too_large', 'fetch_failed', 'too_many_redirects' ) as $code ) {
	$r = $cmp_cf_h(
		array( 'post' => array( 'url' => 'exemplu.md', 'lang' => 'en' ) ),
		function ( $url ) use ( $code ) {
			return array( 'ok' => false, 'status' => 0, 'headers' => array(), 'body' => '', 'final_url' => $url, 'error' => $code );
		}
	);
	cmp_assert( 200 === $r['code'] && 'error' === $r['body']['status'] && $code === $r['body']['code'] && '' !== $r['body']['message'], "handle: fetch {$code} -> error {$code}" );
}
$r = $cmp_cf_h(
	array( 'post' => array( 'url' => 'exemplu.md' ) ),
	function ( $url ) {
		return array( 'ok' => false, 'status' => 0, 'headers' => array(), 'body' => '', 'final_url' => $url, 'error' => 'blocked_address' );
	}
);
cmp_assert( 400 === $r['code'] && 'blocked_address' === $r['body']['code'], 'handle: blocked address -> 400' );
cmp_assert( 1 === preg_match( '/[ăâîșț]/u', $r['body']['message'] ) && false === strpos( $r['body']['message'], "\xe2\x80\x94" ), 'handle: Romanian message by default, no em dash' );
$r = $cmp_cf_h( array( 'post' => array( 'url' => 'exemplu.md' ) ), $cmp_cf_page( '<html>x</html>' ), $cmp_cf_tmp . '/no-services.json' );
cmp_assert( 503 === $r['code'] && 'unavailable' === $r['body']['code'], 'handle: no service library -> 503 unavailable' );
foreach ( array( 'bad_request', 'bad_url', 'blocked_address', 'fetch_failed', 'timeout', 'too_large', 'too_many_redirects', 'http_status', 'not_html', 'rate_limited', 'method_not_allowed', 'unavailable', 'busy', 'forbidden' ) as $code ) {
	foreach ( array( 'ro', 'en' ) as $lang ) {
		$msg = cmp_check_error_message( $code, $lang, 500 );
		cmp_assert( '' !== $msg && false === strpos( $msg, "\xe2\x80\x94" ), "message {$code}/{$lang}" );
	}
}
// Rate limit inside handle: the 6th from one IP in a minute.
$cmp_cf_last = null;
for ( $i = 0; $i < 6; $i++ ) {
	$cmp_cf_last = cmp_check_handle(
		array( 'method' => 'POST', 'content_type' => '', 'body' => '', 'post' => array( 'url' => 'exemplu.md', 'owner' => '1' ), 'ip' => '198.51.100.200' ),
		$cmp_cf_tmp . '/handle-counters-ip',
		$cmp_cf_svc,
		1700000000,
		function ( $url ) {
			return array( 'ok' => false, 'error' => 'fetch_failed' );
		}
	);
}
cmp_assert( 429 === $cmp_cf_last['code'] && 'rate_limited' === $cmp_cf_last['body']['code'] && isset( $cmp_cf_last['headers']['Retry-After'] ), 'handle: 6th in a minute -> 429 with Retry-After' );

// Order: origin checks first, invalid input creates no per-IP file.
$cmp_cf_hd   = $cmp_cf_tmp . '/handle-order';
$cmp_cf_hreq = function ( array $over ) {
	$req = array_merge( array( 'method' => 'POST', 'content_type' => '', 'body' => '', 'post' => array( 'url' => 'exemplu.md' ), 'ip' => '192.0.2.250' ), $over );
	if ( ! isset( $req['post']['owner'] ) ) {
		$req['post']['owner'] = '1';
	}
	return $req;
};
$cmp_cf_ok_fetch = $cmp_cf_page( '<html><body><a href="/privacy">Privacy policy</a></body></html>' );
$cmp_cf_hexcnt   = function ( $dir ) {
	return is_dir( $dir ) ? count( preg_grep( '/^[0-9a-f]{64}\.cnt$/', scandir( $dir ) ) ) : 0;
};
foreach ( array( 'userinfo' => 'http://user:pass@x.md/', 'single label' => 'x', 'file' => 'file:///etc/passwd', 'decimal' => '2130706433' ) as $label => $bad ) {
	$r = cmp_check_handle( $cmp_cf_hreq( array( 'post' => array( 'url' => $bad ) ) ), $cmp_cf_hd, $cmp_cf_svc, 1700000000, $cmp_cf_ok_fetch );
	cmp_assert( 400 === $r['code'] && 'bad_url' === $r['body']['code'], "handle: bad url {$label} -> 400" );
}
cmp_assert_eq( $cmp_cf_hexcnt( $cmp_cf_hd ), 0, 'handle: invalid URLs create no per-IP counter file' );
foreach ( array(
	'cross-site Sec-Fetch-Site'   => array( 'sec_fetch_site' => 'cross-site' ),
	'same-site Sec-Fetch-Site'    => array( 'sec_fetch_site' => 'same-site' ),
	'none Sec-Fetch-Site'         => array( 'sec_fetch_site' => 'none' ),
	'foreign Origin'              => array( 'origin' => 'https://evil.example' ),
	'http Origin'                 => array( 'origin' => 'http://consent.rt.md' ),
	'null Origin'                 => array( 'origin' => 'null' ),
) as $label => $over ) {
	$r = cmp_check_handle( $cmp_cf_hreq( $over ), $cmp_cf_hd, $cmp_cf_svc, 1700000000, $cmp_cf_ok_fetch );
	cmp_assert( 403 === $r['code'] && 'forbidden' === $r['body']['code'], "handle: {$label} -> 403" );
}
cmp_assert( ! file_exists( $cmp_cf_hd . '/global.cnt' ), 'handle: invalid URLs and refused cross-site requests are not counted globally' );
$r = cmp_check_handle( $cmp_cf_hreq( array( 'origin' => 'https://consent.rt.md', 'sec_fetch_site' => 'same-origin' ) ), $cmp_cf_hd, $cmp_cf_svc, 1700000000, $cmp_cf_ok_fetch );
cmp_assert( 200 === $r['code'], 'handle: same-origin request from https://consent.rt.md accepted' );
$r = cmp_check_handle( $cmp_cf_hreq( array( 'origin' => 'http://127.0.0.1:8000' ) ), $cmp_cf_hd, $cmp_cf_svc, 1700000001, $cmp_cf_ok_fetch, array( 'http://127.0.0.1:8000' ) );
cmp_assert( 200 === $r['code'], 'handle: the allowed origins can be injected (tests)' );
cmp_assert_eq( $cmp_cf_hexcnt( $cmp_cf_hd ), 1, 'handle: one valid IPv4 request -> one per-IP file' );

// Global volume through handle: counts only requests that passed the per-IP limit.
$cmp_cf_gcount = function ( $dir ) {
	return is_file( $dir . '/global.cnt' ) ? count( array_filter( explode( "\n", (string) file_get_contents( $dir . '/global.cnt' ) ) ) ) : 0;
};
$cmp_cf_gd = $cmp_cf_tmp . '/handle-global';
for ( $i = 0; $i < 31; $i++ ) {
	$r = cmp_check_handle( $cmp_cf_hreq( array( 'ip' => '203.0.113.7', 'post' => array( 'url' => 'not a url' ) ) ), $cmp_cf_gd, $cmp_cf_svc, 1700000000, $cmp_cf_ok_fetch );
}
cmp_assert( 400 === $r['code'] && 'bad_url' === $r['body']['code'], 'handle: 31 invalid URLs from one IP -> still bad_url' );
cmp_assert_eq( $cmp_cf_gcount( $cmp_cf_gd ), 0, 'handle: 31 invalid URLs from one IP do not consume the global budget' );
$r = cmp_check_handle( $cmp_cf_hreq( array( 'ip' => '203.0.113.8' ) ), $cmp_cf_gd, $cmp_cf_svc, 1700000000, $cmp_cf_ok_fetch );
cmp_assert( 200 === $r['code'], 'handle: after the junk, another visitor is served' );
for ( $i = 0; $i < 6; $i++ ) {
	$r = cmp_check_handle( $cmp_cf_hreq( array( 'ip' => '203.0.113.9' ) ), $cmp_cf_gd, $cmp_cf_svc, 1700000000, $cmp_cf_ok_fetch );
}
cmp_assert( 429 === $r['code'] && 'rate_limited' === $r['body']['code'], 'handle: the 6th valid request from one IP is stopped by the per-IP limit' );
cmp_assert_eq( $cmp_cf_gcount( $cmp_cf_gd ), 6, 'handle: only requests that passed the per-IP limit count globally (1 + 5)' );
for ( $i = 0; $i < 24; $i++ ) {
	cmp_check_handle( $cmp_cf_hreq( array( 'ip' => '198.51.100.' . ( $i + 1 ) ) ), $cmp_cf_gd, $cmp_cf_svc, 1700000000, $cmp_cf_ok_fetch );
}
cmp_assert_eq( $cmp_cf_gcount( $cmp_cf_gd ), 30, 'handle: 30 counted globally' );
$r = cmp_check_handle( $cmp_cf_hreq( array( 'ip' => '203.0.113.99' ) ), $cmp_cf_gd, $cmp_cf_svc, 1700000000, $cmp_cf_ok_fetch );
cmp_assert( 503 === $r['code'] && 'busy' === $r['body']['code'] && isset( $r['headers']['Retry-After'] ) && '' !== $r['body']['message'], 'handle: global volume over 30 a minute -> 503 busy' );

// Concurrency through handle: all slots busy -> 503 unavailable, Retry-After 10, no fetch.
$cmp_cf_cd = $cmp_cf_tmp . '/handle-slots';
cmp_check_handle( $cmp_cf_hreq( array() ), $cmp_cf_cd, $cmp_cf_svc, 1700000000, $cmp_cf_ok_fetch );
$cmp_cf_held = array( cmp_check_slot_acquire( $cmp_cf_cd ), cmp_check_slot_acquire( $cmp_cf_cd ), cmp_check_slot_acquire( $cmp_cf_cd ) );
$cmp_cf_called = 0;
$r = cmp_check_handle(
	$cmp_cf_hreq( array( 'ip' => '192.0.2.251' ) ),
	$cmp_cf_cd,
	$cmp_cf_svc,
	1700000001,
	function ( $url ) use ( &$cmp_cf_called, $cmp_cf_ok_fetch ) {
		$cmp_cf_called++;
		return $cmp_cf_ok_fetch( $url );
	}
);
cmp_assert( 503 === $r['code'] && 'unavailable' === $r['body']['code'] && isset( $r['headers']['Retry-After'] ) && '10' === $r['headers']['Retry-After'] && 0 === $cmp_cf_called, 'handle: no free slot -> 503 unavailable, Retry-After 10, nothing fetched' );
foreach ( $cmp_cf_held as $fh ) {
	cmp_check_slot_release( $fh );
}
$r = cmp_check_handle( $cmp_cf_hreq( array( 'ip' => '192.0.2.252' ) ), $cmp_cf_cd, $cmp_cf_svc, 1700000002, $cmp_cf_ok_fetch );
cmp_assert( 200 === $r['code'], 'handle: slots are released after a check' );

// Counter folder inside the web root: refused before anything is written.
$r = cmp_check_handle( $cmp_cf_hreq( array( 'docroot' => $cmp_cf_docroot ) ), $cmp_cf_docroot . '/site/tmp/cmp-check', $cmp_cf_svc, 1700000000, $cmp_cf_ok_fetch );
cmp_assert( 503 === $r['code'] && 'unavailable' === $r['body']['code'] && ! file_exists( $cmp_cf_docroot . '/site/tmp' ), 'handle: counter folder inside DOCUMENT_ROOT -> 503, nothing created' );

/* ------------------------------------------------------------------
 * The endpoint over HTTP (php -S on site/api, test-only router).
 * ------------------------------------------------------------------ */
$cmp_cf_erd = $cmp_cf_tmp . '/endpoint-counters';
list( $cmp_cf_eproc, $cmp_cf_eport ) = cmp_cf_serve( array( '-t', realpath( $cmp_cf_api ), __DIR__ . '/fixtures/site-check-fetch/endpoint-router.php' ), array( 'CMP_TEST_RATE_DIR' => $cmp_cf_erd ) );
$cmp_cf_procs[] = $cmp_cf_eproc;
cmp_assert( $cmp_cf_eport > 0, 'endpoint server started' );
if ( $cmp_cf_eport > 0 ) {
	$cmp_cf_hdrs = function ( array $h, $label ) {
		cmp_assert( isset( $h['content-type'] ) && 'application/json; charset=utf-8' === $h['content-type'][0], "{$label}: Content-Type application/json; charset=utf-8" );
		cmp_assert( isset( $h['cache-control'] ) && 'no-store' === $h['cache-control'][0], "{$label}: Cache-Control no-store" );
		cmp_assert( isset( $h['x-content-type-options'] ) && 'nosniff' === $h['x-content-type-options'][0], "{$label}: X-Content-Type-Options nosniff" );
		cmp_assert( isset( $h['referrer-policy'] ) && 'no-referrer' === $h['referrer-policy'][0], "{$label}: Referrer-Policy no-referrer" );
		cmp_assert( ! isset( $h['access-control-allow-origin'] ), "{$label}: no CORS header" );
		cmp_assert( ! isset( $h['x-powered-by'] ), "{$label}: no X-Powered-By" );
	};
	list( $st, $h, $b ) = cmp_cf_http( $cmp_cf_eport, 'GET', '/verifica.php' );
	cmp_assert_eq( $st, 405, 'endpoint: GET -> 405' );
	$cmp_cf_hdrs( $h, 'endpoint GET' );
	$j = json_decode( $b, true );
	cmp_assert( is_array( $j ) && 'error' === $j['status'] && 'method_not_allowed' === $j['code'], 'endpoint: GET body is JSON error' );

	list( $st, $h, $b ) = cmp_cf_http( $cmp_cf_eport, 'POST', '/verifica.php', 'lang=en', 'application/x-www-form-urlencoded' );
	cmp_assert_eq( $st, 400, 'endpoint: POST without url -> 400' );
	$cmp_cf_hdrs( $h, 'endpoint 400' );
	$j = json_decode( $b, true );
	cmp_assert( is_array( $j ) && 'bad_request' === $j['code'] && 'error' === $j['status'], 'endpoint: 400 body is JSON bad_request' );

	list( $st, $h, $b ) = cmp_cf_http( $cmp_cf_eport, 'POST', '/verifica.php', 'url=' . rawurlencode( '"><script>alert(1)</script>' ), 'application/x-www-form-urlencoded' );
	cmp_assert( 400 === $st && false === stripos( $b, '<script' ), 'endpoint: hostile input not echoed' );

	foreach ( array( 'http://[::ffff:127.0.0.1]/', '2130706433', '8.8.8.8', 'exemplu.md/pagina', 'exemplu.md:443' ) as $cmp_cf_in ) {
		list( $st, $h, $b ) = cmp_cf_http( $cmp_cf_eport, 'POST', '/verifica.php', json_encode( array( 'url' => $cmp_cf_in, 'owner' => 1 ) ), 'application/json' );
		$j = json_decode( $b, true );
		cmp_assert( 400 === $st && is_array( $j ) && 'bad_url' === $j['code'] && 0 === strpos( $j['message'], 'Scrie doar adresa site-ului' ), "endpoint: {$cmp_cf_in} -> bad_url (bare domain only)" );
	}

	list( $st, $h, $b ) = cmp_cf_http( $cmp_cf_eport, 'POST', '/verifica.php', 'url=exemplu.md', 'application/x-www-form-urlencoded', array( 'Origin' => 'https://evil.example' ) );
	cmp_assert( 403 === $st && false !== strpos( $b, '"forbidden"' ), 'endpoint: foreign Origin -> 403' );
	$cmp_cf_hdrs( $h, 'endpoint 403' );
	list( $st, $h, $b ) = cmp_cf_http( $cmp_cf_eport, 'POST', '/verifica.php', 'url=exemplu.md', 'application/x-www-form-urlencoded', array( 'Sec-Fetch-Site' => 'cross-site' ) );
	cmp_assert( 403 === $st, 'endpoint: Sec-Fetch-Site cross-site -> 403' );

	// Without the owner box: 400 owner_required, nothing counted.
	list( $st, $h, $b ) = cmp_cf_http( $cmp_cf_eport, 'POST', '/verifica.php', json_encode( array( 'url' => 'exemplu.md', 'lang' => 'en' ) ), 'application/json' );
	$j = json_decode( $b, true );
	cmp_assert( 400 === $st && is_array( $j ) && 'owner_required' === $j['code'] && "Tick that you own the site or have the owner's permission." === $j['message'] && 'html-v2' === $j['engine'], 'endpoint: no owner -> 400 owner_required (EN message)' );
	cmp_assert( ! file_exists( $cmp_cf_erd ), 'endpoint: refused requests (owner, bad URLs, origin) wrote nothing' );
	// The 429 path over HTTP without any network: 127.0.0.1's per-IP counter is filled in advance
	// (5 checks this minute, keyed like cmp_check_rate_check does), so the next valid request is refused.
	mkdir( $cmp_cf_erd, 0700, true );
	$cmp_cf_salt = bin2hex( random_bytes( 32 ) );
	file_put_contents( $cmp_cf_erd . '/.salt', $cmp_cf_salt );
	chmod( $cmp_cf_erd . '/.salt', 0600 );
	file_put_contents( $cmp_cf_erd . '/' . hash_hmac( 'sha256', 'v4:' . inet_pton( '127.0.0.1' ), $cmp_cf_salt ) . '.cnt', str_repeat( time() . "\n", 5 ) );
	list( $st, $h, $b ) = cmp_cf_http( $cmp_cf_eport, 'POST', '/verifica.php', 'owner=1&url=exemplu.md', 'application/x-www-form-urlencoded' );
	cmp_assert_eq( $st, 429, 'endpoint: over the limit (5 a minute) -> 429' );
	$cmp_cf_hdrs( $h, 'endpoint 429' );
	$j = json_decode( $b, true );
	cmp_assert( is_array( $j ) && 'rate_limited' === $j['code'] && '' !== $j['message'], 'endpoint: 429 body is JSON rate_limited' );
	cmp_assert( 1 === count( preg_grep( '/^[0-9a-f]{64}\.cnt$/', scandir( $cmp_cf_erd ) ) ) && ! file_exists( $cmp_cf_erd . '/global.cnt' ), 'endpoint: the injected folder is used; a refused request is not counted globally' );

	// The libraries requested directly: 404, nothing runs, nothing printed.
	foreach ( array( 'check.php', 'signatures.php', 'fetch.php', 'discover.php', 'ratelimit.php', 'endpoint.php' ) as $f ) {
		list( $st, $h, $b ) = cmp_cf_http( $cmp_cf_eport, 'GET', '/lib/' . $f );
		cmp_assert( 404 === $st && '' === trim( $b ), "endpoint: /lib/{$f} requested directly -> 404, empty" );
	}
}
