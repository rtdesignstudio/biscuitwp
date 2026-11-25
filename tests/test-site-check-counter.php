<?php
/**
 * "Verifică-ți site-ul": the public counter of successful checks.
 * One number in the counter folder (total-checks.txt), never anything about
 * the site; GET /api/contor.php answers {"checks": N}. No network.
 */
$cmp_ctr_api = __DIR__ . '/../site/api';
if ( ! is_file( $cmp_ctr_api . '/lib/ratelimit.php' ) || ! is_file( $cmp_ctr_api . '/contor.php' ) ) {
	cmp_assert( false, 'counter: site/api/contor.php and lib/ratelimit.php exist' );
	return;
}
if ( ! defined( 'CMP_CHECK' ) ) {
	define( 'CMP_CHECK', true );
}
foreach ( array( 'check.php', 'fetch.php', 'discover.php', 'ratelimit.php', 'endpoint.php' ) as $f ) {
	require_once $cmp_ctr_api . '/lib/' . $f;
}

$cmp_ctr_tmp = sys_get_temp_dir() . '/cmp-counter-' . bin2hex( random_bytes( 6 ) );
$cmp_ctr_dir = $cmp_ctr_tmp . '/counters';

// The total: 0 when missing, +1 per add, an owner-only file.
cmp_assert( 0 === cmp_check_total_read( $cmp_ctr_dir ), 'counter: missing folder reads 0' );
cmp_assert( ! is_dir( $cmp_ctr_dir ), 'counter: reading creates nothing' );
cmp_assert( true === cmp_check_total_add( $cmp_ctr_dir ), 'counter: first add' );
cmp_assert( true === cmp_check_total_add( $cmp_ctr_dir ), 'counter: second add' );
cmp_assert( 2 === cmp_check_total_read( $cmp_ctr_dir ), 'counter: two adds read 2' );
cmp_assert( '2' === trim( (string) file_get_contents( $cmp_ctr_dir . '/total-checks.txt' ) ), 'counter: the file holds only the number' );
cmp_assert( 0600 === ( fileperms( $cmp_ctr_dir . '/total-checks.txt' ) & 0777 ), 'counter: the file is 0600' );

// Garbage in the file reads 0 and the next add starts again from it.
$cmp_ctr_bad = $cmp_ctr_tmp . '/bad';
mkdir( $cmp_ctr_bad, 0700, true );
$fh = fopen( $cmp_ctr_bad . '/total-checks.txt', 'w' );
fwrite( $fh, "abc\n<script>" );
fclose( $fh );
cmp_assert( 0 === cmp_check_total_read( $cmp_ctr_bad ), 'counter: garbage reads 0' );
cmp_assert( true === cmp_check_total_add( $cmp_ctr_bad ) && 1 === cmp_check_total_read( $cmp_ctr_bad ), 'counter: add after garbage gives 1' );

// A symlink is never followed.
$cmp_ctr_ln = $cmp_ctr_tmp . '/ln';
mkdir( $cmp_ctr_ln, 0700, true );
$fh = fopen( $cmp_ctr_tmp . '/elsewhere.txt', 'w' );
fwrite( $fh, '41' );
fclose( $fh );
symlink( $cmp_ctr_tmp . '/elsewhere.txt', $cmp_ctr_ln . '/total-checks.txt' );
cmp_assert( false === cmp_check_total_add( $cmp_ctr_ln ), 'counter: add refuses a symlinked file' );
cmp_assert( 0 === cmp_check_total_read( $cmp_ctr_ln ), 'counter: read ignores a symlinked file' );
cmp_assert( '41' === (string) file_get_contents( $cmp_ctr_tmp . '/elsewhere.txt' ), 'counter: the symlink target is untouched' );

// The hourly cleanup never deletes it, however old.
touch( $cmp_ctr_dir . '/total-checks.txt', 1000 );
cmp_check_rate_cleanup( $cmp_ctr_dir, time() + 10 * 86400 );
cmp_assert( 2 === cmp_check_total_read( $cmp_ctr_dir ), 'counter: the cleanup keeps the total' );

// Only checks that read the site count: clean, issues, unsure.
$cmp_ctr_svc  = $cmp_ctr_tmp . '/services.json';
copy( __DIR__ . '/../data/services.json', $cmp_ctr_svc );
$cmp_ctr_hd   = $cmp_ctr_tmp . '/handle';
$cmp_ctr_n    = 0;
$cmp_ctr_run  = function ( $fetcher, array $post = array() ) use ( $cmp_ctr_hd, $cmp_ctr_svc, &$cmp_ctr_n ) {
	$cmp_ctr_n++;
	$req = array(
		'method'       => 'POST',
		'content_type' => 'application/x-www-form-urlencoded',
		'body'         => '',
		'post'         => array_merge( array( 'url' => 'exemplu.md', 'owner' => '1' ), $post ),
		'ip'           => '192.0.2.' . $cmp_ctr_n,
	);
	return cmp_check_handle( $req, $cmp_ctr_hd, $cmp_ctr_svc, 1700000000 + 2 * $cmp_ctr_n, $fetcher );
};
$cmp_ctr_page = function ( $html, $status = 200, $ctype = 'text/html; charset=utf-8' ) {
	return function ( $url ) use ( $html, $status, $ctype ) {
		return array( 'ok' => true, 'status' => $status, 'headers' => array( 'content-type' => $ctype ), 'body' => $html, 'final_url' => 'https://exemplu.md/', 'error' => '' );
	};
};
$r = $cmp_ctr_run( $cmp_ctr_page( '<html><body><a href="/privacy">Privacy policy</a></body></html>' ) );
cmp_assert( 'clean' === $r['body']['status'] && 1 === cmp_check_total_read( $cmp_ctr_hd ), 'counter: a clean check counts' );
$r = $cmp_ctr_run( $cmp_ctr_page( '<html><head><script async src="https://www.googletagmanager.com/gtag/js?id=G-1"></script></head><body>x</body></html>' ) );
cmp_assert( 'issues' === $r['body']['status'] && 2 === cmp_check_total_read( $cmp_ctr_hd ), 'counter: a check with issues counts' );
$r = $cmp_ctr_run( $cmp_ctr_page( "\x89PNG", 200, 'image/png' ) );
cmp_assert( 'error' === $r['body']['status'] && 2 === cmp_check_total_read( $cmp_ctr_hd ), 'counter: a check in error does not count' );
$r = $cmp_ctr_run(
	function ( $url ) {
		return array( 'ok' => false, 'status' => 0, 'headers' => array(), 'body' => '', 'final_url' => $url, 'error' => 'timeout' );
	}
);
cmp_assert( 'error' === $r['body']['status'] && 2 === cmp_check_total_read( $cmp_ctr_hd ), 'counter: a site that does not answer does not count' );
$r = $cmp_ctr_run( $cmp_ctr_page( '<html>x</html>' ), array( 'url' => 'file:///etc/passwd' ) );
cmp_assert( 400 === $r['code'] && 2 === cmp_check_total_read( $cmp_ctr_hd ), 'counter: a bad address does not count' );

// GET /api/contor.php.
$r = cmp_check_counter_handle( 'GET', $cmp_ctr_hd, '' );
cmp_assert( 200 === $r['code'] && 2 === $r['body']['checks'], 'contor: GET -> 200 {"checks": 2}' );
cmp_assert( isset( $r['headers']['Cache-Control'] ) && 'public, max-age=300' === $r['headers']['Cache-Control'], 'contor: cached 5 minutes' );
$r = cmp_check_counter_handle( 'HEAD', $cmp_ctr_hd, '' );
cmp_assert( 200 === $r['code'], 'contor: HEAD -> 200' );
$r = cmp_check_counter_handle( 'POST', $cmp_ctr_hd, '' );
cmp_assert( 405 === $r['code'] && isset( $r['headers']['Allow'] ) && 'GET, HEAD' === $r['headers']['Allow'] && 2 === cmp_check_total_read( $cmp_ctr_hd ), 'contor: POST -> 405, counts nothing' );
$r = cmp_check_counter_handle( 'GET', $cmp_ctr_tmp . '/nothing-here', '' );
cmp_assert( 200 === $r['code'] && 0 === $r['body']['checks'], 'contor: no counter yet -> 0' );
$r = cmp_check_counter_handle( 'GET', $cmp_ctr_tmp . '/www/counters', $cmp_ctr_tmp . '/www' );
cmp_assert( 503 === $r['code'] && ! isset( $r['body']['checks'] ), 'contor: a counter folder inside the web root -> 503' );

// The entry point and the build.
$src = (string) file_get_contents( $cmp_ctr_api . '/contor.php' );
cmp_assert( false !== strpos( $src, "ini_set( 'display_errors', '0' );" ) && false !== strpos( $src, 'cmp_check_counter_main(' ), 'contor.php: display_errors off, runs cmp_check_counter_main()' );
foreach ( array( 'error_log', '$_GET', '$_POST', '$_REQUEST', '$_COOKIE', 'REMOTE_ADDR', 'Access-Control', 'file_put_contents' ) as $bad ) {
	cmp_assert( false === strpos( $src, $bad ), "contor.php: does not use {$bad}" );
}

// The pages: the line under the form, hidden until the script fills it.
foreach ( array( 'site/index.html', 'site/en/index.html' ) as $f ) {
	cmp_assert( 1 === preg_match( '#<p id="verifica-contor" class="muted small" hidden></p>#', (string) file_get_contents( __DIR__ . '/../' . $f ) ), "{$f}: an empty, hidden counter line" );
}
// The privacy pages say what the total is.
cmp_assert( false !== strpos( (string) file_get_contents( __DIR__ . '/../site/confidentialitate/index.html' ), 'un total al verificărilor reușite: un singur număr, fără nimic despre site sau despre tine' ), 'confidentialitate: names the total of checks' );
cmp_assert( false !== strpos( (string) file_get_contents( __DIR__ . '/../site/en/privacy/index.html' ), 'a total of successful checks: a single number, with nothing about the site or about you' ), 'privacy (en): names the total of checks' );

// Clean up.
$cmp_ctr_rm = function ( $d ) use ( &$cmp_ctr_rm ) {
	foreach ( array_diff( (array) @scandir( $d ), array( '.', '..' ) ) as $e ) {
		$p = $d . '/' . $e;
		is_dir( $p ) && ! is_link( $p ) ? $cmp_ctr_rm( $p ) : @unlink( $p );
	}
	@rmdir( $d );
};
$cmp_ctr_rm( $cmp_ctr_tmp );
