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
	const INLINE  = array( "gtag('config'", 'gtag("config"', "ga('create'" );

	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_start' ), 1 );
		add_filter( 'wp_resource_hints', array( __CLASS__, 'filter_hints' ), 10, 2 );
	}

	/** Remove GA/GTM dns-prefetch / preconnect resource hints while blocking is active. */
	public static function filter_hints( $urls, $relation_type ) {
		if ( ! is_array( $urls ) || ! self::should_run() ) {
			return $urls;
		}
		return self::strip_hints( $urls, self::HOSTS );
	}

	/** Pure: drop resource-hint entries (string or ['href'=>…]) whose URL matches a host. */
	public static function strip_hints( array $urls, array $hosts ) {
		$out = array();
		foreach ( $urls as $u ) {
			$href = is_array( $u ) ? ( isset( $u['href'] ) ? (string) $u['href'] : '' ) : (string) $u;
			$skip = false;
			foreach ( $hosts as $host ) {
				if ( '' !== $href && false !== stripos( $href, $host ) ) {
					$skip = true;
					break;
				}
			}
			if ( ! $skip ) {
				$out[] = $u;
			}
		}
		return $out;
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
		$s     = CMP_Consent::get_settings();
		$block = ! empty( $s['block_scripts'] );
		$fonts = ! empty( $s['localize_fonts'] );
		$mode  = isset( $s['mode'] ) ? $s['mode'] : 'pro';
		return ( $block || $fonts ) && 'pro' === $mode;
	}

	public static function maybe_start() {
		if ( self::should_run() ) {
			ob_start( array( __CLASS__, 'apply' ) );
		}
	}

	/** Output-buffer callback: apply the enabled rewrites (scripts and/or fonts). */
	public static function apply( $html ) {
		$s    = CMP_Consent::get_settings();
		$html = (string) $html;
		if ( ! empty( $s['block_scripts'] ) ) {
			$html = self::rewrite( $html );
		}
		if ( ! empty( $s['localize_fonts'] ) ) {
			$html = CMP_Fonts::rewrite_links( $html, CMP_Fonts::map() );
		}
		return $html;
	}

	/** Pure: neutralize GA/GTM <script> tags and drop GA/GTM resource-hint <link> tags. */
	public static function rewrite( $html ) {
		$html = (string) $html;
		$html = preg_replace_callback(
			'/<script\b([^>]*)>(.*?)<\/script>/is',
			array( __CLASS__, 'rewrite_tag' ),
			$html
		);
		$html = preg_replace_callback(
			'/<link\b[^>]*>/i',
			array( __CLASS__, 'strip_link' ),
			$html
		);
		return (string) $html;
	}

	/** Pure: drop a GA/GTM dns-prefetch / preconnect <link> hint (added directly to wp_head). */
	private static function strip_link( $m ) {
		$tag = $m[0];
		if ( ! preg_match( '/rel\s*=\s*["\'](?:dns-prefetch|preconnect)["\']/i', $tag ) ) {
			return $tag;
		}
		foreach ( self::HOSTS as $host ) {
			if ( false !== stripos( $tag, $host ) ) {
				return '';
			}
		}
		return $tag;
	}

	private static function rewrite_tag( $m ) {
		$attrs = $m[1];
		$body  = $m[2];

		// Never touch our own tagged scripts (e.g. the Consent Mode init).
		if ( false !== stripos( $attrs, 'data-cmp-keep' ) ) {
			return $m[0];
		}

		$src = '';
		if ( preg_match( '/(?:^|\s)src\s*=\s*["\']([^"\']+)["\']/i', $attrs, $sm ) ) {
			$src = $sm[1];
		}

		$is_target = false;
		if ( '' !== $src ) {
			foreach ( self::HOSTS as $host ) {
				if ( false !== stripos( $src, $host ) ) {
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
		if ( ! $is_target && false !== strpos( $body, 'GTM-' ) && false !== strpos( $body, 'dataLayer' ) ) {
			$is_target = true;
		}
		if ( ! $is_target ) {
			return $m[0];
		}

		// Keep the other attributes (id, async, defer, …) but drop type + src.
		$keep = preg_replace( '/(^|\s)type\s*=\s*["\'][^"\']*["\']/i', '$1', $attrs );
		$keep = preg_replace( '/(^|\s)src\s*=\s*["\'][^"\']*["\']/i', '$1', $keep );
		$keep = trim( preg_replace( '/\s+/', ' ', $keep ) );
		$keep = '' === $keep ? '' : ' ' . $keep;

		// Obfuscate: store the URL / inline body base64-encoded so the tracker
		// domain string never appears in the page source before consent.
		if ( '' !== $src ) {
			$enc = ' data-cmp-enc="1" data-cmp-src="' . base64_encode( $src ) . '"';
		} else {
			$enc = ' data-cmp-enc="1" data-cmp-body="' . base64_encode( $body ) . '"';
		}
		return '<script type="text/plain" data-cmp-block="statistics"' . $enc . $keep . '></script>';
	}
}
