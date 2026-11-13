<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone-loadable for tests; rewrite() is pure. WP-only methods guarded.
}

/**
 * Prior blocking of Google Analytics / Tag Manager / Meta Pixel: rewrites their
 * <script> tags to non-executing until the visitor consents. On by default in
 * Pro mode; always on in Light mode, which cannot ask for consent, so trackers
 * there never run.
 */
class CMP_Blocker {

	const FONT_HOSTS = array( 'fonts.googleapis.com', 'fonts.gstatic.com' );

	// All tracker hosts across every rule — used for resource-hint stripping.
	const TRACKER_HOSTS = array( 'googletagmanager.com', 'google-analytics.com', 'connect.facebook.net' );

	// Ordered blocking rules. First match wins; its 'cat' becomes data-cmp-block.
	// 'gtm' => container special-case (body has both 'GTM-' and 'dataLayer').
	const RULES = array(
		array(
			'cat'    => 'statistics',
			'hosts'  => array( 'googletagmanager.com', 'google-analytics.com' ),
			'inline' => array( "gtag('config'", 'gtag("config"', "ga('create'" ),
			'gtm'    => true,
		),
		array(
			'cat'    => 'marketing',
			'hosts'  => array( 'connect.facebook.net' ),
			'inline' => array( "fbq('init'", 'fbq("init"', "fbq('track'", 'fbq("track"' ),
			'gtm'    => false,
		),
	);

	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_start' ), 1 );
		add_filter( 'wp_resource_hints', array( __CLASS__, 'filter_hints' ), 10, 2 );
	}

	/** Remove GA/GTM (blocking) and/or Google Fonts (localizing) resource hints. */
	public static function filter_hints( $urls, $relation_type ) {
		if ( ! is_array( $urls ) ) {
			return $urls;
		}
		$s     = CMP_Consent::get_settings();
		$hosts = array();
		if ( self::blocks_trackers( $s ) ) {
			$hosts = array_merge( $hosts, self::TRACKER_HOSTS );
		}
		if ( self::localizes_fonts( $s ) ) {
			$hosts = array_merge( $hosts, self::FONT_HOSTS );
		}
		if ( empty( $hosts ) ) {
			return $urls;
		}
		return self::strip_hints( $urls, $hosts );
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
		$s = CMP_Consent::get_settings();
		return self::blocks_trackers( $s ) || self::localizes_fonts( $s );
	}

	/**
	 * Pure: are trackers blocked for these settings? Light mode has no way to
	 * collect consent, so it always blocks; Pro follows the block_scripts setting.
	 */
	public static function blocks_trackers( $s ) {
		$mode = isset( $s['mode'] ) ? $s['mode'] : 'pro';
		return 'light' === $mode || ! empty( $s['block_scripts'] );
	}

	/** Pure: is Google Fonts localization active (Pro mode only)? */
	public static function localizes_fonts( $s ) {
		$mode = isset( $s['mode'] ) ? $s['mode'] : 'pro';
		return 'pro' === $mode && ! empty( $s['localize_fonts'] );
	}

	/**
	 * Pure: which tracker categories a page's HTML loads directly ('raw') and
	 * which this plugin has neutralized ('blocked'), e.g.
	 * array( 'raw' => array( 'statistics' ), 'blocked' => array( 'marketing' ) ).
	 */
	public static function detect_trackers( $html ) {
		$html    = (string) $html;
		$raw     = array();
		$blocked = array();
		// Hosts/markers inside neutralized tags are base64-encoded, so only live tags match here.
		foreach ( self::RULES as $rule ) {
			$hit = false;
			foreach ( array_merge( $rule['hosts'], $rule['inline'] ) as $needle ) {
				if ( false !== stripos( $html, $needle ) ) {
					$hit = true;
					break;
				}
			}
			if ( ! $hit && $rule['gtm'] && false !== strpos( $html, 'GTM-' ) && false !== strpos( $html, 'dataLayer' ) && preg_match( '/<script\b(?![^>]*\bdata-cmp-block)[^>]*>[^<]*GTM-/i', $html ) ) {
				$hit = true;
			}
			if ( $hit ) {
				$raw[] = $rule['cat'];
			}
		}
		if ( preg_match_all( '/data-cmp-block\s*=\s*["\']([a-z0-9_-]+)["\']/i', $html, $m ) ) {
			$blocked = array_values( array_unique( $m[1] ) );
		}
		return array( 'raw' => $raw, 'blocked' => $blocked );
	}

	/**
	 * Scan the public homepage for trackers (cached 1 hour). Null when the page
	 * could not be fetched. Used for the admin warnings only.
	 */
	public static function scan_homepage( $force = false ) {
		$cached = $force ? false : get_transient( 'cmp_tracker_scan' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$res = wp_remote_get( home_url( '/' ), array( 'timeout' => 10, 'redirection' => 3 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		$scan = self::detect_trackers( wp_remote_retrieve_body( $res ) );
		set_transient( 'cmp_tracker_scan', $scan, HOUR_IN_SECONDS );
		return $scan;
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
		if ( self::blocks_trackers( $s ) ) {
			$html = self::rewrite( $html );
		}
		if ( self::localizes_fonts( $s ) ) {
			$html = CMP_Fonts::rewrite_links( $html, CMP_Fonts::map() );
			$html = self::strip_hint_links( $html, self::FONT_HOSTS );
		}
		return $html;
	}

	/** Pure: neutralize tracker <script> tags, drop tracker <noscript> blocks and resource-hint <link> tags. */
	public static function rewrite( $html ) {
		$html = (string) $html;
		$html = preg_replace_callback(
			'/<script\b([^>]*)>(.*?)<\/script>/is',
			array( __CLASS__, 'rewrite_tag' ),
			$html
		);
		$html = self::strip_tracker_noscript( (string) $html, self::TRACKER_HOSTS );
		return self::strip_hint_links( (string) $html, self::TRACKER_HOSTS );
	}

	/** Pure: drop dns-prefetch / preconnect <link> hints whose href matches one of $hosts. */
	public static function strip_hint_links( $html, array $hosts ) {
		return (string) preg_replace_callback(
			'/<link\b[^>]*>/i',
			function ( $m ) use ( $hosts ) {
				$tag = $m[0];
				if ( ! preg_match( '/rel\s*=\s*["\'](?:dns-prefetch|preconnect)["\']/i', $tag ) ) {
					return $tag;
				}
				foreach ( $hosts as $host ) {
					if ( false !== stripos( $tag, $host ) ) {
						return '';
					}
				}
				return $tag;
			},
			(string) $html
		);
	}

	/** Pure: remove <noscript> blocks whose content references a tracker host.
	 * A <noscript> only renders without JS, where no consent is possible, so the
	 * compliant outcome is to not load the tracker at all (no re-injection). */
	public static function strip_tracker_noscript( $html, array $hosts ) {
		return (string) preg_replace_callback(
			'/<noscript\b[^>]*>.*?<\/noscript>/is',
			function ( $m ) use ( $hosts ) {
				foreach ( $hosts as $host ) {
					if ( false !== stripos( $m[0], $host ) ) {
						return '';
					}
				}
				return $m[0];
			},
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

		$src = '';
		if ( preg_match( '/(?:^|\s)src\s*=\s*["\']([^"\']+)["\']/i', $attrs, $sm ) ) {
			$src = $sm[1];
		}

		$category = '';
		foreach ( self::RULES as $rule ) {
			if ( '' !== $src ) {
				foreach ( $rule['hosts'] as $host ) {
					if ( false !== stripos( $src, $host ) ) {
						$category = $rule['cat'];
						break;
					}
				}
			}
			if ( '' === $category ) {
				foreach ( $rule['inline'] as $needle ) {
					if ( false !== strpos( $body, $needle ) ) {
						$category = $rule['cat'];
						break;
					}
				}
			}
			if ( '' === $category && ! empty( $rule['gtm'] )
				&& false !== strpos( $body, 'GTM-' ) && false !== strpos( $body, 'dataLayer' ) ) {
				$category = $rule['cat'];
			}
			// Inline loaders that reference a tracker host in the body (e.g. an
			// SDK injected via js.src) but do not call fbq()/gtag('config').
			if ( '' === $category ) {
				foreach ( $rule['hosts'] as $host ) {
					if ( false !== stripos( $body, $host ) ) {
						$category = $rule['cat'];
						break;
					}
				}
			}
			if ( '' !== $category ) {
				break;
			}
		}
		if ( '' === $category ) {
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
		return '<script type="text/plain" data-cmp-block="' . $category . '"' . $enc . $keep . '></script>';
	}
}
