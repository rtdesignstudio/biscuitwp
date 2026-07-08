<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone-loadable for tests; WP/plugin calls guarded by function_exists.
}

class CMP_I18n {

	const CONTEXT = 'consent-manager-pro';

	public static function register() {
		// Strings register lazily via translate() during render; nothing at boot.
	}

	public static function resolve( $stored, $default ) {
		$stored = (string) $stored;
		return '' !== trim( $stored ) ? $stored : (string) $default;
	}

	public static function translate( $name, $value ) {
		$name  = (string) $name;
		$value = (string) $value;

		// WPML String Translation (legacy API provided by the ST plugin).
		if ( function_exists( 'icl_register_string' ) && function_exists( 'icl_t' ) ) {
			icl_register_string( self::CONTEXT, $name, $value );
			return (string) icl_t( self::CONTEXT, $name, $value );
		}

		// Polylang.
		if ( function_exists( 'pll_register_string' ) && function_exists( 'pll__' ) ) {
			pll_register_string( $name, $value, self::CONTEXT );
			return (string) pll__( $value );
		}

		return $value;
	}

	public static function text( $key, $stored, $default ) {
		return self::translate( 'cmp_' . $key, self::resolve( $stored, $default ) );
	}
}
