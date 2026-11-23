<?php
/**
 * "Verifică-ți site-ul": „Ce găsim cel mai des”. Per successful check, one count
 * per kind of problem found (found-stats.json in the counter folder: numbers
 * only), sent by /api/contor.php and shown on the page from 100 checks on.
 */
$cmp_fnd_api = __DIR__ . '/../site/api';
if ( ! defined( 'CMP_CHECK' ) ) {
	define( 'CMP_CHECK', true );
}
foreach ( array( 'check.php', 'fetch.php', 'discover.php', 'ratelimit.php', 'endpoint.php' ) as $f ) {
	require_once $cmp_fnd_api . '/lib/' . $f;
}
if ( ! function_exists( 'cmp_check_found_kinds' ) ) {
	cmp_assert( false, 'found: cmp_check_found_kinds() exists' );
	return;
}

cmp_assert_eq( cmp_check_found_kind_ids(), array( 'tracker', 'google-fonts', 'ip-resources', 'no-policy', 'no-banner' ), 'found: the five kinds' );

// Which kinds a check result shows (the aggregate body).
$f = function ( $id, $sev = 'issue' ) {
	return array( 'id' => $id, 'severity' => $sev );
};
$k = function ( array $findings, $cmp = null ) {
	return cmp_check_found_kinds( array( 'status' => 'issues', 'findings' => $findings, 'cmp' => $cmp ) );
};
cmp_assert_eq( $k( array() ), array(), 'found: nothing found, no kind' );
cmp_assert_eq( $k( array( $f( 'tracker:ga4' ) ) ), array( 'tracker', 'no-banner' ), 'found: a tracker and no CMP -> tracker, no-banner' );
cmp_assert_eq( $k( array( $f( 'tracker:ga4' ) ), 'Complianz' ), array( 'tracker' ), 'found: a tracker with a CMP -> tracker only' );
cmp_assert_eq( $k( array( $f( 'cookie:_ga' ) ) ), array( 'tracker', 'no-banner' ), 'found: a tracker cookie counts as tracker' );
cmp_assert_eq( $k( array( $f( 'tracker:clarity', 'unsure' ), $f( 'loader:gtm', 'unsure' ), $f( 'tracker:plausible', 'info' ) ) ), array(), 'found: unsure and info findings are not counted' );
cmp_assert_eq( $k( array( $f( 'resource:google-fonts' ) ) ), array( 'google-fonts' ), 'found: Google Fonts' );
cmp_assert_eq( $k( array( $f( 'resource:gravatar' ), $f( 'resource:cdn' ) ) ), array( 'ip-resources' ), 'found: other IP resources, once' );
cmp_assert_eq( $k( array( $f( 'resource:google-fonts' ), $f( 'resource:emoji' ) ) ), array( 'google-fonts', 'ip-resources' ), 'found: fonts and another resource' );
cmp_assert_eq( $k( array( $f( 'policy:missing' ) ) ), array( 'no-policy' ), 'found: no policy link' );
cmp_assert_eq( cmp_check_found_kinds( array( 'findings' => 'x' ) ), array(), 'found: a malformed result gives no kind' );

// The stats file.
$cmp_fnd_tmp = sys_get_temp_dir() . '/cmp-found-' . bin2hex( random_bytes( 6 ) );
$cmp_fnd_dir = $cmp_fnd_tmp . '/counters';
$zero        = array( 'of' => 0, 'tracker' => 0, 'google-fonts' => 0, 'ip-resources' => 0, 'no-policy' => 0, 'no-banner' => 0 );
cmp_assert_eq( cmp_check_found_read( $cmp_fnd_dir ), $zero, 'found: missing file reads zeros' );
cmp_assert( ! is_dir( $cmp_fnd_dir ), 'found: reading creates nothing' );
cmp_assert( true === cmp_check_found_add( $cmp_fnd_dir, array( 'tracker', 'no-banner' ) ), 'found: add one check' );
cmp_assert( true === cmp_check_found_add( $cmp_fnd_dir, array() ), 'found: add a check with nothing found' );
cmp_assert( true === cmp_check_found_add( $cmp_fnd_dir, array( 'tracker', 'bogus', 'tracker' ) ), 'found: unknown kinds ignored, a kind once' );
cmp_assert_eq( cmp_check_found_read( $cmp_fnd_dir ), array_merge( $zero, array( 'of' => 3, 'tracker' => 2, 'no-banner' => 1 ) ), 'found: three checks counted' );
cmp_assert( 0600 === ( fileperms( $cmp_fnd_dir . '/found-stats.json' ) & 0777 ), 'found: the file is 0600' );
$raw = json_decode( (string) file_get_contents( $cmp_fnd_dir . '/found-stats.json' ), true );
cmp_assert( is_array( $raw ) && array_keys( $raw ) === array_keys( $zero ) && count( array_filter( $raw, 'is_int' ) ) === 6, 'found: the file holds only the six numbers' );

// Garbage, a count above the checks, a symlink.
$bad = $cmp_fnd_tmp . '/bad';
mkdir( $bad, 0700, true );
$fh = fopen( $bad . '/found-stats.json', 'w' );
fwrite( $fh, '{"of":2,"tracker":9,"google-fonts":"x","evil":"<script>"}' );
fclose( $fh );
cmp_assert_eq( cmp_check_found_read( $bad ), array_merge( $zero, array( 'of' => 2, 'tracker' => 2 ) ), 'found: a count is never above the checks, bad values are 0, unknown keys dropped' );
$fh = fopen( $bad . '/found-stats.json', 'w' );
fwrite( $fh, 'not json' );
fclose( $fh );
cmp_assert_eq( cmp_check_found_read( $bad ), $zero, 'found: broken JSON reads zeros' );
$ln = $cmp_fnd_tmp . '/ln';
mkdir( $ln, 0700, true );
symlink( $cmp_fnd_tmp . '/bad/found-stats.json', $ln . '/found-stats.json' );
cmp_assert( false === cmp_check_found_add( $ln, array( 'tracker' ) ), 'found: add refuses a symlinked file' );
cmp_assert_eq( cmp_check_found_read( $ln ), $zero, 'found: read ignores a symlinked file' );
touch( $cmp_fnd_dir . '/found-stats.json', 1000 );
cmp_check_rate_cleanup( $cmp_fnd_dir, time() + 10 * 86400 );
cmp_assert( 3 === cmp_check_found_read( $cmp_fnd_dir )['of'], 'found: the cleanup keeps the stats' );

// handle() counts kinds only for checks that read the site.
$svc = $cmp_fnd_tmp . '/services.json';
copy( __DIR__ . '/../data/services.json', $svc );
$hd  = $cmp_fnd_tmp . '/handle';
$n   = 0;
$run = function ( $html, $ctype = 'text/html; charset=utf-8' ) use ( $hd, $svc, &$n ) {
	$n++;
	return cmp_check_handle(
		array( 'method' => 'POST', 'content_type' => '', 'body' => '', 'post' => array( 'url' => 'exemplu.md', 'owner' => '1' ), 'ip' => '192.0.2.' . $n ),
		$hd,
		$svc,
		1700000000 + 2 * $n,
		function ( $url ) use ( $html, $ctype ) {
			return array( 'ok' => true, 'status' => 200, 'headers' => array( 'content-type' => $ctype ), 'body' => $html, 'final_url' => 'https://exemplu.md/', 'error' => '' );
		}
	);
};
$r = $run( '<html><head><script async src="https://www.googletagmanager.com/gtag/js?id=G-1"></script><link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto"></head><body>x</body></html>' );
cmp_assert( 'issues' === $r['body']['status'], 'found: the GA + fonts page has issues' );
$s = cmp_check_found_read( $hd );
cmp_assert( 1 === $s['of'] && 1 === $s['tracker'] && 1 === $s['google-fonts'] && 1 === $s['no-banner'] && 1 === $s['no-policy'] && 0 === $s['ip-resources'], 'found: handle counts the kinds of the check (' . json_encode( $s ) . ')' );
$run( "\x89PNG", 'image/png' );
cmp_assert( 1 === cmp_check_found_read( $hd )['of'], 'found: a check in error adds nothing' );

// /api/contor.php sends them.
$r = cmp_check_counter_handle( 'GET', $hd, '' );
cmp_assert( 200 === $r['code'] && isset( $r['body']['checks'], $r['body']['found'] ) && cmp_check_found_read( $hd ) === $r['body']['found'], 'contor: body has checks and found' );

// The privacy pages.
cmp_assert( false !== strpos( (string) file_get_contents( __DIR__ . '/../site/confidentialitate/index.html' ), 'și câte verificări au găsit fiecare tip de problemă: tot doar numere' ), 'confidentialitate: names the counts per kind' );
cmp_assert( false !== strpos( (string) file_get_contents( __DIR__ . '/../site/en/privacy/index.html' ), 'and how many checks found each kind of problem: numbers only' ), 'privacy (en): names the counts per kind' );
// The block on the pages: empty and hidden until the script fills it.
foreach ( array( 'site/index.html', 'site/en/index.html' ) as $p ) {
	cmp_assert( false !== strpos( (string) file_get_contents( __DIR__ . '/../' . $p ), '<div id="verifica-gasim" class="found" hidden></div>' ), "{$p}: the empty, hidden „found” block" );
}
cmp_assert( 1 === preg_match( '/\.found-bar\b/', (string) file_get_contents( __DIR__ . '/../site/style.css' ) ), 'style.css: the bars are styled' );

$rm = function ( $d ) use ( &$rm ) {
	foreach ( array_diff( (array) @scandir( $d ), array( '.', '..' ) ) as $e ) {
		$p = $d . '/' . $e;
		is_dir( $p ) && ! is_link( $p ) ? $rm( $p ) : @unlink( $p );
	}
	@rmdir( $d );
};
$rm( $cmp_fnd_tmp );
