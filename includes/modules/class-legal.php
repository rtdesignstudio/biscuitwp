<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone-loadable for tests; WP-only methods guarded at call time.
}

class CMP_Legal {

	const TYPES = array( 'privacy', 'terms', 'cookie' );

	public static function fill_template( $tpl, array $vars ) {
		$out = (string) $tpl;
		foreach ( $vars as $k => $v ) {
			$out = str_replace( '{{' . $k . '}}', (string) $v, $out );
		}
		return $out;
	}

	public static function load_template( $type ) {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return '';
		}
		$path = dirname( __DIR__, 2 ) . '/templates/legal/' . $type . '.html';
		return is_readable( $path ) ? (string) file_get_contents( $path ) : '';
	}

	public static function register() {
		// Shortcodes + privacy guide added in Task 4.
	}
}
