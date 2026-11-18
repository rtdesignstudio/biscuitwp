<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone-loadable for tests; rewrite() is pure. WP-only methods guarded.
}
require_once dirname( __DIR__ ) . '/class-services.php';

/**
 * Prior blocking of Google Analytics / Tag Manager / Meta Pixel: rewrites their
 * <script> tags to non-executing until the visitor consents. On by default in
 * Pro mode; always on in Light mode, which cannot ask for consent, so trackers
 * there never run.
 */
class CMP_Blocker {

	const FONT_HOSTS = array( 'fonts.googleapis.com', 'fonts.gstatic.com' );

	/**
	 * Tracker hosts of the built-in library.
	 *
	 * @deprecated Use CMP_Blocker::tracker_hosts( $services ); kept for older callers and tests.
	 */
	const TRACKER_HOSTS = array( 'googletagmanager.com', 'google-analytics.com', 'connect.facebook.net' );

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
			$hosts = array_merge( $hosts, self::tracker_hosts( self::rewritable( self::default_services() ) ) );
		}
		if ( self::localizes_fonts( $s ) ) {
			$hosts = array_merge( $hosts, self::FONT_HOSTS );
		}
		if ( empty( $hosts ) ) {
			return $urls;
		}
		return self::strip_hints( $urls, $hosts );
	}

	/** Pure: does $text contain $host on a name boundary (not inside another word; a subdomain dot is fine)? */
	public static function has_host( $text, $host ) {
		$host = (string) $host;
		if ( '' === $host ) {
			return false;
		}
		return 1 === preg_match( '/(?<![a-z0-9-])' . preg_quote( $host, '/' ) . '(?![a-z0-9-])/i', (string) $text );
	}

	/** Pure: drop resource-hint entries (string or ['href'=>…]) whose URL matches a host. */
	public static function strip_hints( array $urls, array $hosts ) {
		$out = array();
		foreach ( $urls as $u ) {
			$href = is_array( $u ) ? ( isset( $u['href'] ) ? (string) $u['href'] : '' ) : (string) $u;
			$skip = false;
			foreach ( $hosts as $host ) {
				if ( '' !== $href && self::has_host( $href, $host ) ) {
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
		$html     = (string) $html;
		$services = self::rewritable( CMP_Services::library() );
		$raw      = array();
		foreach ( self::detect_services( $html, $services )['raw'] as $id ) {
			foreach ( $services as $svc ) {
				if ( $svc['id'] === $id && ! in_array( $svc['category'], $raw, true ) ) {
					$raw[] = $svc['category'];
				}
			}
		}
		$blocked = array();
		if ( preg_match_all( '/data-cmp-block\s*=\s*["\']([a-z0-9_-]+)["\']/i', $html, $m ) ) {
			$blocked = array_values( array_unique( $m[1] ) );
		}
		return array( 'raw' => $raw, 'blocked' => $blocked );
	}

	/**
	 * Pure: which services a page's HTML loads directly ('raw', by service id:
	 * live <script> tags) and which this plugin has neutralized ('blocked', from
	 * data-cmp-service on rewritten scripts and embed placeholders).
	 */
	public static function detect_services( $html, array $services ) {
		$html    = (string) $html;
		$raw     = array();
		$blocked = array();
		// Page-wide on purpose: optimizers move trackers to data-src / data-litespeed-src
		// or leave them in <noscript>, and those still load. Neutralized tags hold
		// their URL or body base64-encoded, so only live markup matches here.
		$scripts = array_filter(
			self::rewritable( $services ),
			function ( $s ) {
				return isset( $s['type'] ) && 'script' === $s['type'];
			}
		);
		foreach ( $scripts as $svc ) {
			$page = $html;
			// A longer host of another service (googletagmanager.com/gtag/js) is not this service's.
			foreach ( $scripts as $other ) {
				foreach ( (array) $other['hosts'] as $oh ) {
					foreach ( (array) $svc['hosts'] as $h ) {
						if ( $other['id'] !== $svc['id'] && 0 === stripos( $oh, $h . '/' ) ) {
							$page = str_ireplace( $oh, '', $page );
						}
					}
				}
			}
			$hit = false;
			foreach ( array_merge( (array) $svc['hosts'], (array) $svc['inline'] ) as $needle ) {
				if ( '' !== $needle && false !== stripos( $page, $needle ) ) {
					$hit = true;
					break;
				}
			}
			if ( ! $hit && ! empty( $svc['gtm'] ) && false !== strpos( $page, 'GTM-' ) && false !== strpos( $page, 'dataLayer' ) && preg_match( '/<script\b(?![^>]*\bdata-cmp-block)[^>]*>[^<]*GTM-/i', $page ) ) {
				$hit = true;
			}
			if ( $hit ) {
				$raw[] = $svc['id'];
			}
		}
		if ( preg_match_all( '/data-cmp-service\s*=\s*["\']([a-z0-9-]+)["\']/i', $html, $m ) ) {
			$blocked = array_values( array_unique( $m[1] ) );
		}
		return array( 'raw' => $raw, 'blocked' => $blocked );
	}

	/**
	 * Pure: does the page offer a way to reopen the cookie settings (shortcode
	 * link, a link to #cmp-settings, any [data-cmp-settings] element, or the
	 * floating button)? Withdrawing consent must be as easy as giving it. The
	 * settings link inside an embed placeholder does not count: it disappears
	 * once the embed loads.
	 */
	public static function has_settings_link( $html ) {
		$html = (string) preg_replace( '/<a\b[^>]*\bcmp-embed__link\b[^>]*>.*?<\/a>/is', '', (string) $html );
		return (bool) preg_match( '/data-cmp-settings|data-cmp-reopen|class\s*=\s*["\'][^"\']*\bcmp-(?:cookie-)?settings-link\b|href\s*=\s*["\'][^"\']*#cmp-settings["\']/i', $html );
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
		$body                  = wp_remote_retrieve_body( $res );
		$scan                  = self::detect_trackers( $body );
		$scan['settings_link'] = self::has_settings_link( $body );
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
			$html = self::rewrite( $html, self::embed_labels() ); // services: active() when available, else the library
		}
		if ( self::localizes_fonts( $s ) ) {
			$html = CMP_Fonts::rewrite_links( $html, CMP_Fonts::map() );
			$html = self::strip_hint_links( $html, self::FONT_HOSTS );
		}
		return $html;
	}

	/** The services to act on when the caller gives none: the site's active ones (Task 4), else the library. */
	private static function default_services() {
		return method_exists( 'CMP_Services', 'active' ) ? CMP_Services::active() : CMP_Services::library();
	}

	/** Pure: services that may be blocked (necessary / exempt ones never are). */
	private static function rewritable( array $services ) {
		return array_values(
			array_filter(
				$services,
				function ( $s ) {
					return is_array( $s ) && ( ! isset( $s['category'] ) || 'necessary' !== $s['category'] ) && empty( $s['exempt'] );
				}
			)
		);
	}

	/**
	 * Pure: hosts (no paths, unique) of the script-type services, for <noscript>
	 * stripping and resource hints.
	 */
	public static function tracker_hosts( array $services ) {
		$out = array();
		foreach ( $services as $s ) {
			if ( ! isset( $s['type'] ) || 'script' !== $s['type'] ) {
				continue;
			}
			foreach ( (array) ( isset( $s['hosts'] ) ? $s['hosts'] : array() ) as $h ) {
				$slash = strpos( (string) $h, '/' );
				$h     = strtolower( false === $slash ? (string) $h : substr( (string) $h, 0, $slash ) );
				if ( '' !== $h && ! in_array( $h, $out, true ) ) {
					$out[] = $h;
				}
			}
		}
		return $out;
	}

	/**
	 * Pure: neutralize tracker <script> tags and third-party iframes, drop tracker
	 * <noscript> blocks and resource-hint <link> tags. $labels: placeholder copy
	 * (embed_text with %s for the service, embed_load, settings). $services: the
	 * rules to apply (null = active services, else the library). $thumb: reserved
	 * for embed thumbnails.
	 */
	public static function rewrite( $html, array $labels = array(), ?array $services = null, $thumb = null ) {
		$html     = (string) $html;
		$services = self::rewritable( null === $services ? self::default_services() : $services );
		$html     = preg_replace_callback(
			'/<script\b([^>]*)>(.*?)<\/script>/is',
			function ( $m ) use ( $services ) {
				return self::rewrite_tag( $m, $services );
			},
			$html
		);
		$html  = self::rewrite_embeds( (string) $html, $labels, $services );
		$hosts = self::tracker_hosts( $services );
		$html  = self::strip_tracker_noscript( (string) $html, $hosts );
		return self::strip_hint_links( (string) $html, $hosts );
	}

	/** Placeholder copy in the current page language. */
	public static function embed_labels() {
		if ( ! class_exists( 'CMP_Lang' ) ) {
			return array();
		}
		$s = class_exists( 'CMP_Consent' ) ? CMP_Consent::get_settings() : array();
		return array(
			'embed_text' => CMP_Lang::text( 'embed_text' ),
			'embed_load' => CMP_Lang::text( 'embed_load' ),
			'settings'   => CMP_Lang::text( 'reopen' ),
			'preset'     => isset( $s['preset'] ) ? (string) $s['preset'] : 'neutral',
		);
	}

	/** Pure: true if $url's host is $host or a subdomain of it (parsed, not substring). */
	public static function url_host_is( $url, $host ) {
		$h = parse_url( (string) $url, PHP_URL_HOST );
		if ( ! is_string( $h ) || '' === $h ) {
			return false;
		}
		$h    = strtolower( $h );
		$host = strtolower( (string) $host );
		return $h === $host || substr( $h, -( strlen( $host ) + 1 ) ) === '.' . $host;
	}

	/** Pure: the embed service an iframe URL belongs to, or null. */
	public static function embed_for( $url, ?array $services = null ) {
		return CMP_Services::embed_service( $url, null === $services ? self::default_services() : $services );
	}

	/**
	 * Pure: replace third-party <iframe>s with a consent placeholder. The original
	 * iframe sits in a <template> (inert: the browser never loads it) and is put
	 * back by banner.js once its category is accepted or the visitor clicks "load".
	 */
	public static function rewrite_embeds( $html, array $labels = array(), ?array $services = null ) {
		$services = self::rewritable( null === $services ? self::default_services() : $services );
		$labels = array_merge(
			array(
				'embed_text' => 'Conținut de la %s. Se încarcă doar cu acordul tău, pentru că poate seta cookie-uri.',
				'embed_load' => 'Încarcă conținutul',
				'settings'   => 'Setări cookie',
			),
			array_filter( $labels, 'strlen' )
		);
		return (string) preg_replace_callback(
			'/<iframe\b([^>]*)>(.*?)<\/iframe>/is',
			function ( $m ) use ( $labels, $services ) {
				$attrs = $m[1];
				if ( false !== stripos( $attrs, 'data-cmp-keep' ) ) {
					return $m[0];
				}
				if ( ! preg_match( '/(?:^|\s)src\s*=\s*(["\'])(.*?)\1/is', $attrs, $sm ) ) {
					return $m[0];
				}
				$src   = trim( html_entity_decode( $sm[2], ENT_QUOTES ) );
				$embed = self::embed_for( $src, $services );
				if ( null === $embed ) {
					return $m[0];
				}

				$iframe = $m[0];
				if ( ! empty( $embed['nocookie'] ) && preg_match( '#^https?://(?:www\.)?youtube\.com/embed/#i', $src ) ) {
					$nocookie = preg_replace( '#^https?://(?:www\.)?youtube\.com/#i', 'https://www.youtube-nocookie.com/', $src );
					$iframe   = str_replace( $sm[0], str_replace( $sm[2], htmlspecialchars( $nocookie, ENT_QUOTES, 'UTF-8' ), $sm[0] ), $iframe );
				}

				// Pixel sizes only ("560" or "560px"); a percentage width keeps 16/9.
				$ratio = '16/9';
				$max   = '';
				if ( preg_match( '/\bwidth\s*=\s*["\']?(\d+)(?:px)?["\'\s>]/i', $attrs . ' ', $wm ) && preg_match( '/\bheight\s*=\s*["\']?(\d+)(?:px)?["\'\s>]/i', $attrs . ' ', $hm ) && (int) $wm[1] > 0 && (int) $hm[1] > 0 ) {
					$ratio = (int) $wm[1] . '/' . (int) $hm[1];
					$max   = ';max-width:' . (int) $wm[1] . 'px';
				}
				$e    = function ( $v ) {
					return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' );
				};
				$text = str_replace( '%s', $e( $embed['name'] ), $e( $labels['embed_text'] ) );

				$preset = isset( $labels['preset'] ) && in_array( $labels['preset'], array( 'neutral', 'warm', 'dark', 'egov' ), true ) ? ' cmp--' . $labels['preset'] : '';
				return '<div class="cmp-embed' . $preset . '" data-cmp-embed="' . $e( $embed['category'] ) . '" data-cmp-service="' . $e( $embed['id'] ) . '" style="aspect-ratio:' . $ratio . $max . '">'
					. '<div class="cmp-embed__inner">'
					. '<p class="cmp-embed__text">' . $text . '</p>'
					. '<p class="cmp-embed__actions">'
					. '<button type="button" class="cmp-embed__btn" data-cmp-embed-load>' . $e( $labels['embed_load'] ) . '</button>'
					. '<a href="#cmp-settings" class="cmp-embed__link" data-cmp-settings>' . $e( $labels['settings'] ) . '</a>'
					. '</p></div>'
					. '<template data-cmp-embed-src>' . $iframe . '</template>'
					. '</div>';
			},
			(string) $html
		);
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
					if ( self::has_host( $tag, $host ) ) {
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
					if ( self::has_host( $m[0], $host ) ) {
						return '';
					}
				}
				return $m[0];
			},
			(string) $html
		);
	}

	/** The src attribute of a <script> tag's attributes ('' when none). */
	private static function script_src( $attrs ) {
		return preg_match( '/(?:^|\s)src\s*=\s*["\']([^"\']+)["\']/i', $attrs, $sm ) ? trim( $sm[1] ) : '';
	}

	/**
	 * Pure: the script service a tag belongs to (by src host, inline marker or
	 * GTM container), or null. Inline loaders that only mention a service host
	 * in their body (an SDK injected via js.src) count too.
	 */
	private static function script_for( $src, $body, array $services ) {
		$svc = CMP_Services::script_service( $src, $body, $services );
		if ( null === $svc && '' !== $src ) {
			$svc = CMP_Services::script_service( '', $body, $services );
		}
		if ( null === $svc ) {
			foreach ( $services as $s ) {
				if ( ! isset( $s['type'] ) || 'script' !== $s['type'] ) {
					continue;
				}
				foreach ( (array) ( isset( $s['hosts'] ) ? $s['hosts'] : array() ) as $h ) {
					$slash = strpos( (string) $h, '/' );
					$host  = false === $slash ? (string) $h : substr( (string) $h, 0, $slash );
					if ( '' !== $host && self::has_host( $body, $host ) ) {
						return $s;
					}
				}
			}
		}
		return $svc;
	}

	private static function rewrite_tag( $m, array $services ) {
		$attrs = $m[1];
		$body  = $m[2];

		// Never touch our own tagged scripts (e.g. the Consent Mode init).
		if ( false !== stripos( $attrs, 'data-cmp-keep' ) ) {
			return $m[0];
		}

		$src = self::script_src( $attrs );
		$svc = self::script_for( $src, $body, $services );
		if ( null === $svc ) {
			return $m[0];
		}
		$category = $svc['category'];

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
		return '<script type="text/plain" data-cmp-block="' . $category . '" data-cmp-service="' . $svc['id'] . '"' . $enc . $keep . '></script>';
	}
}
