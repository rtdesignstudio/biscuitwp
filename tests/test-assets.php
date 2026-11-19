<?php
require_once __DIR__ . '/../includes/class-assets.php';

$root = dirname( __DIR__ ) . '/';

// Freshness: every shipped .min carries the SHA-256 of its current source.
$pairs = array(
	'assets/js/banner.js'  => 'assets/js/banner.min.js',
	'assets/css/banner.css' => 'assets/css/banner.min.css',
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

// src_or_min.
cmp_assert_eq( CMP_Assets::src_or_min( 'assets/js/banner.js', false ), 'assets/js/banner.min.js', 'src_or_min js -> .min' );
cmp_assert_eq( CMP_Assets::src_or_min( 'assets/css/banner.css', false ), 'assets/css/banner.min.css', 'src_or_min css -> .min' );
cmp_assert_eq( CMP_Assets::src_or_min( 'assets/js/banner.js', true ), 'assets/js/banner.js', 'src_or_min with debug -> source' );
