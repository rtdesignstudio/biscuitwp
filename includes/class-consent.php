<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone-loadable for tests; WP-only methods guarded at call time.
}

class CMP_Consent {

	const COOKIE = 'cmp_consent';

	/**
	 * Pure evaluation. $cookie is the decoded cookie array or null.
	 * Returns map slug => bool.
	 */
	public static function evaluate( $cookie, array $categories, $current_version ) {
		$valid    = is_array( $cookie )
			&& isset( $cookie['v'] )
			&& (string) $cookie['v'] === (string) $current_version;
		$accepted = ( $valid && isset( $cookie['categories'] ) && is_array( $cookie['categories'] ) )
			? $cookie['categories']
			: array();

		$out = array();
		foreach ( $categories as $cat ) {
			$slug = $cat['slug'];
			if ( ! empty( $cat['required'] ) ) {
				$out[ $slug ] = true;
				continue;
			}
			$out[ $slug ] = $valid && in_array( $slug, $accepted, true );
		}
		return $out;
	}

	/**
	 * Pure: is this page one of the policy pages (privacy / cookies / terms from
	 * the settings, or WordPress' own privacy policy page)? There the banner shows
	 * without the blocking overlay, so the visitor can read before deciding.
	 */
	public static function is_policy_page( $page_id, array $settings, $wp_privacy_page_id ) {
		$page_id = (int) $page_id;
		if ( $page_id <= 0 ) {
			return false;
		}
		$ids = array( (int) $wp_privacy_page_id );
		foreach ( array( 'privacy_page_id', 'cookie_page_id', 'terms_page_id' ) as $k ) {
			$ids[] = isset( $settings[ $k ] ) ? (int) $settings[ $k ] : 0;
		}
		return in_array( $page_id, $ids, true );
	}

	public static function get_settings() {
		return get_option( 'cmp_settings', array() );
	}

	public static function get_categories() {
		return get_option( 'cmp_categories', array() );
	}

	public static function current_version() {
		$s = self::get_settings();
		return isset( $s['policy_version'] ) ? (string) $s['policy_version'] : '1';
	}

	public static function build_default_signals( array $categories ) {
		return self::map_signals(
			$categories,
			function ( $cat ) {
				return ! empty( $cat['required'] );
			}
		);
	}

	public static function build_update_signals( array $categories, array $accepted ) {
		return self::map_signals(
			$categories,
			function ( $cat ) use ( $accepted ) {
				return ! empty( $cat['required'] ) || in_array( $cat['slug'], $accepted, true );
			}
		);
	}

	private static function map_signals( array $categories, $is_granted ) {
		$signals = array();
		foreach ( $categories as $cat ) {
			$granted = (bool) call_user_func( $is_granted, $cat );
			foreach ( (array) ( isset( $cat['gtag_signals'] ) ? $cat['gtag_signals'] : array() ) as $sig ) {
				// Granted wins if any category grants the signal.
				if ( ! isset( $signals[ $sig ] ) || $granted ) {
					$signals[ $sig ] = $granted ? 'granted' : 'denied';
				}
			}
		}
		return $signals;
	}


	private static function read_cookie() {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return null;
		}
		$raw     = wp_unslash( $_COOKIE[ self::COOKIE ] );
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	public static function is_allowed( $slug ) {
		$map = self::evaluate( self::read_cookie(), self::get_categories(), self::current_version() );
		return ! empty( $map[ $slug ] );
	}
}
