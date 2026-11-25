<?php
require_once __DIR__ . '/../includes/class-assets.php';

$root = dirname( __DIR__ ) . '/';

// Freshness: every shipped .min carries the SHA-256 of its current source.
$pairs = array(
	'assets/js/banner.js'  => 'assets/js/banner.min.js',
	'assets/css/banner.css' => 'assets/css/banner.min.css',
	'assets/js/banner-check.js' => 'assets/js/banner-check.min.js',
	'assets/js/maps-gate.js' => 'assets/js/maps-gate.min.js',
	'standalone/cmp.js'    => 'standalone/cmp.min.js',
	'standalone/cmp.css'   => 'standalone/cmp.min.css',
);
foreach ( $pairs as $src => $min ) {
	cmp_assert( is_file( $root . $min ), "{$min} exists" );
	cmp_assert( CMP_Assets::min_fresh( $root . $min, $root . $src ), "{$min} is fresh against {$src} (run bin/build-assets.sh)" );
}

// A source changed by one byte makes the minified file stale.
$tmp = tempnam( sys_get_temp_dir(), 'cmpsrc' );
file_put_contents( $tmp, file_get_contents( $root . 'assets/js/banner.js' ) . ' ' );
cmp_assert( ! CMP_Assets::min_fresh( $root . 'assets/js/banner.min.js', $tmp ), 'min_fresh false when source differs by one byte' );
unlink( $tmp );
cmp_assert( ! CMP_Assets::min_fresh( $root . 'assets/js/nope.min.js', $root . 'assets/js/banner.js' ), 'min_fresh false when .min is missing' );

// Header format.
$head = (string) file_get_contents( $root . 'assets/js/banner.min.js', false, null, 0, 100 );
cmp_assert( 1 === preg_match( '#^/\*! cmp-src-sha256:[0-9a-f]{64} \*/#', $head ), 'banner.min.js starts with the source-hash header' );

// Budget: banner.min.js + banner.min.css <= 12 KB gzipped.
$js  = CMP_Assets::gz_size( $root . 'assets/js/banner.min.js' );
$css = CMP_Assets::gz_size( $root . 'assets/css/banner.min.css' );
echo "INFO: banner.min.js gz={$js} banner.min.css gz={$css} sum=" . ( $js + $css ) . " (budget 12288)\n";
cmp_assert( $js > 0 && $css > 0 && $js + $css <= 12288, 'banner gzip budget <= 12288 bytes' );
echo 'INFO: standalone cmp.min.js gz=' . CMP_Assets::gz_size( $root . 'standalone/cmp.min.js' ) . ' cmp.min.css gz=' . CMP_Assets::gz_size( $root . 'standalone/cmp.min.css' ) . "\n";

// B5 Task 2: the check-mode runner is a separate file, enqueued only in check
// mode, after cmp-banner, in the footer (not part of the visitor budget).
$bn_src = (string) file_get_contents( $root . 'includes/modules/class-banner.php' );
cmp_assert( 1 === preg_match( '#if \( CMP_Scanner::check_mode\(\) \) \{\s*\$cjs = CMP_Assets::src_or_min\( \'assets/js/banner-check\.js\', \$debug, CMP_PATH \);\s*wp_enqueue_script\( \'cmp-banner-check\', CMP_URL \. \$cjs, array\( \'cmp-banner\' \), CMP_Plugin::asset_ver\( \$cjs \), true \);#', $bn_src ), 'class-banner: banner-check enqueued only in check mode, after cmp-banner, in the footer' );
// Fix wave M4: every enqueue falls back to the source when its .min is missing.
foreach ( array( 'assets/css/banner.css', 'assets/js/banner.js', 'assets/js/banner-check.js' ) as $as_rel ) {
	cmp_assert( false !== strpos( $bn_src, "CMP_Assets::src_or_min( '{$as_rel}', \$debug, CMP_PATH )" ), "M4: class-banner picks {$as_rel} with the file check (CMP_PATH)" );
}
cmp_assert( 0 === preg_match( '#src_or_min\( \'[^\']+\', \$debug \)#', $bn_src ), 'M4: no enqueue without the file check' );

// src_or_min.
cmp_assert_eq( CMP_Assets::src_or_min( 'assets/js/banner.js', false ), 'assets/js/banner.min.js', 'src_or_min js -> .min' );
cmp_assert_eq( CMP_Assets::src_or_min( 'assets/css/banner.css', false ), 'assets/css/banner.min.css', 'src_or_min css -> .min' );
cmp_assert_eq( CMP_Assets::src_or_min( 'assets/js/banner.js', true ), 'assets/js/banner.js', 'src_or_min with debug -> source' );

// Fix wave M4: with a base directory, the .min is used only when it exists.
$as_dir = sys_get_temp_dir() . '/cmp-assets-' . getmypid();
@mkdir( $as_dir . '/assets/js', 0777, true );
file_put_contents( $as_dir . '/assets/js/banner.js', '1' );
cmp_assert_eq( CMP_Assets::src_or_min( 'assets/js/banner.js', false, $as_dir . '/' ), 'assets/js/banner.js', 'M4: .min missing -> the source' );
file_put_contents( $as_dir . '/assets/js/banner.min.js', '1' );
cmp_assert_eq( CMP_Assets::src_or_min( 'assets/js/banner.js', false, $as_dir . '/' ), 'assets/js/banner.min.js', 'M4: .min present -> the .min' );
cmp_assert_eq( CMP_Assets::src_or_min( 'assets/js/banner.js', true, $as_dir . '/' ), 'assets/js/banner.js', 'M4: debug -> the source even with the .min' );
cmp_assert_eq( CMP_Assets::src_or_min( 'assets/js/banner.js', false, $as_dir ), 'assets/js/banner.min.js', 'M4: base without a trailing slash works too' );
unlink( $as_dir . '/assets/js/banner.min.js' );
unlink( $as_dir . '/assets/js/banner.js' );
@rmdir( $as_dir . '/assets/js' );
@rmdir( $as_dir . '/assets' );
@rmdir( $as_dir );
cmp_assert_eq( CMP_Assets::src_or_min( 'assets/css/banner.css', false, $root ), 'assets/css/banner.min.css', 'M4: the shipped .min files are found under the plugin root' );
