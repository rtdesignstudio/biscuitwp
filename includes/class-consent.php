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

	/**
	 * Pure PHP port of the banner's effectiveCategories(): the cookie's
	 * categories minus every non-required category that gained a service since
	 * the decision (an id of $svc_by_cat[slug] missing from cookie.svc[slug]).
	 * An unknown map (null) or an old cookie (no `svc`, or a non-map `svc`)
	 * leaves the categories unchanged. One PHP-only reading: json_decode turns
	 * both `{}` and `[]` into array(); the banner only ever writes `{}`, so an
	 * empty array counts as an empty snapshot.
	 *
	 * @param array      $cookie     Decoded consent cookie.
	 * @param array|null $svc_by_cat {slug: [ids]} as published to the banner, or null.
	 * @param array      $required   Slugs of the required (always on) categories.
	 * @return array
	 */
	public static function effective_categories( array $cookie, ?array $svc_by_cat, array $required ): array {
		$cats = isset( $cookie['categories'] ) && is_array( $cookie['categories'] ) ? array_values( $cookie['categories'] ) : array();
		if ( null === $svc_by_cat || ! array_key_exists( 'svc', $cookie ) ) {
			return $cats;
		}
		$svc = $cookie['svc'];
		if ( ! is_array( $svc ) || ( $svc && array_keys( $svc ) === range( 0, count( $svc ) - 1 ) ) ) {
			return $cats; // not a {slug: [ids]} map: like an old cookie.
		}
		$out = array();
		foreach ( $cats as $slug ) {
			if ( ! is_string( $slug ) || in_array( $slug, $required, true ) || ! isset( $svc_by_cat[ $slug ] ) || ! is_array( $svc_by_cat[ $slug ] ) ) {
				$out[] = $slug;
				continue;
			}
			$known = isset( $svc[ $slug ] ) && is_array( $svc[ $slug ] ) ? $svc[ $slug ] : array();
			$fresh = false;
			foreach ( $svc_by_cat[ $slug ] as $id ) {
				if ( ! in_array( $id, $known, true ) ) {
					$fresh = true;
					break;
				}
			}
			if ( ! $fresh ) {
				$out[] = $slug;
			}
		}
		return $out;
	}

	/**
	 * Pure core of is_allowed(): evaluate() on the effective categories, so a
	 * category that gained a service is refused server-side too, until the
	 * visitor decides again (same rule as CMP.isAllowed in the banner).
	 */
	public static function allowed_from( $slug, $cookie, array $categories, $current_version, ?array $svc_by_cat ) {
		if ( is_array( $cookie ) && isset( $cookie['categories'] ) && is_array( $cookie['categories'] ) ) {
			$required = array();
			foreach ( $categories as $cat ) {
				if ( is_array( $cat ) && ! empty( $cat['required'] ) && isset( $cat['slug'] ) ) {
					$required[] = (string) $cat['slug'];
				}
			}
			$cookie['categories'] = self::effective_categories( $cookie, $svc_by_cat, $required );
		}
		$map = self::evaluate( $cookie, $categories, $current_version );
		return ! empty( $map[ $slug ] );
	}

	public static function is_allowed( $slug ) {
		$cookie     = self::read_cookie();
		$categories = self::get_categories();
		$categories = is_array( $categories ) ? $categories : array();
		$version    = self::current_version();
		$by_cat     = null;
		// The map is read only for a cookie of this version (the only case where it matters).
		if ( is_array( $cookie ) && isset( $cookie['v'] ) && (string) $cookie['v'] === (string) $version && class_exists( 'CMP_Services' ) ) {
			$by_cat = CMP_Services::current_svc_by_cat( $categories );
		}
		return self::allowed_from( $slug, $cookie, $categories, $version, $by_cat );
	}
}
