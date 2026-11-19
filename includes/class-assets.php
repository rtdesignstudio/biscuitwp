<?php
/**
 * Helpers for the minified front-end assets (built by bin/build-assets.sh).
 * Pure: no WordPress functions, so it loads in the plain-PHP test runner.
 */
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone-loadable for tests.
}

class CMP_Assets {

	/** True when $min_path starts with the SHA-256 header of the current $src_path. */
	public static function min_fresh( $min_path, $src_path ) {
		if ( ! is_readable( $min_path ) || ! is_readable( $src_path ) ) {
			return false;
		}
		$head = (string) file_get_contents( $min_path, false, null, 0, 100 );
		if ( ! preg_match( '#^/\*! cmp-src-sha256:([0-9a-f]{64}) \*/#', $head, $m ) ) {
			return false;
		}
		return hash_equals( $m[1], (string) hash_file( 'sha256', $src_path ) );
	}

	/** Gzip (level 9) size in bytes of a file. */
	public static function gz_size( $path ) {
		return strlen( (string) gzencode( (string) file_get_contents( $path ), 9 ) );
	}

	/** foo.js -> foo.min.js (or the source itself when debugging). */
	public static function src_or_min( $rel_src, $debug ) {
		if ( $debug ) {
			return $rel_src;
		}
		return preg_replace( '#\.(js|css)$#', '.min.$1', $rel_src );
	}
}
