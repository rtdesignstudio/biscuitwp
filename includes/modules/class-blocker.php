<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone-loadable for tests; rewrite() is pure. WP-only methods guarded.
}

/**
 * Prior blocking of Google Analytics / Tag Manager: rewrites their <script>
 * tags to non-executing until the visitor consents. Opt-in, Pro mode only.
 */
class CMP_Blocker {

	const HOSTS   = array( 'googletagmanager.com', 'google-analytics.com' );
	const INLINE  = array( "gtag('config'", 'gtag("config"', 'GTM-', "ga('create'" );

	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_start' ), 1 );
	}

	public static function should_run() {
		if ( is_admin() ) {
			return false;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( function_exists( 'is_feed' ) && is_feed() ) {
			return false;
		}
		$s    = CMP_Consent::get_settings();
		$on   = ! empty( $s['block_scripts'] );
		$mode = isset( $s['mode'] ) ? $s['mode'] : 'pro';
		return $on && 'pro' === $mode;
	}

	public static function maybe_start() {
		if ( self::should_run() ) {
			ob_start( array( __CLASS__, 'rewrite' ) );
		}
	}

	/** Pure: neutralize GA/GTM <script> tags. */
	public static function rewrite( $html ) {
		return (string) preg_replace_callback(
			'/<script\b([^>]*)>(.*?)<\/script>/is',
			array( __CLASS__, 'rewrite_tag' ),
			(string) $html
		);
	}

	private static function rewrite_tag( $m ) {
		$attrs = $m[1];
		$body  = $m[2];

		// Never touch our own tagged scripts (e.g. the Consent Mode init).
		if ( false !== stripos( $attrs, 'data-cmp-keep' ) ) {
			return $m[0];
		}

		$is_target = false;
		if ( preg_match( '/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $attrs, $sm ) ) {
			foreach ( self::HOSTS as $host ) {
				if ( false !== stripos( $sm[1], $host ) ) {
					$is_target = true;
					break;
				}
			}
		}
		if ( ! $is_target ) {
			foreach ( self::INLINE as $needle ) {
				if ( false !== strpos( $body, $needle ) ) {
					$is_target = true;
					break;
				}
			}
		}
		if ( ! $is_target ) {
			return $m[0];
		}

		$new_attrs = preg_replace( '/\btype\s*=\s*["\'][^"\']*["\']/i', '', $attrs );
		$new_attrs = preg_replace( '/\bsrc\s*=/i', 'data-cmp-src=', $new_attrs );
		$new_attrs = trim( preg_replace( '/\s+/', ' ', $new_attrs ) );
		$prefix    = '' === $new_attrs ? '' : ' ' . $new_attrs;
		return '<script type="text/plain" data-cmp-block="statistics"' . $prefix . '>' . $body . '</script>';
	}
}
