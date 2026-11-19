<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone-loadable for tests; rewrite() is pure. WP-only methods guarded.
}
require_once dirname( __DIR__ ) . '/class-services.php';
require_once __DIR__ . '/class-thumbs.php';

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
	 * $services: the same rules blocking uses (null = the active services), so an
	 * exempt or necessary service is never reported as tracking without consent.
	 */
	public static function detect_trackers( $html, ?array $services = null ) {
		$html     = (string) $html;
		$services = self::rewritable( null === $services ? self::default_services() : $services );
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
	 * Pure: ids of every service present on the page (live or already blocked),
	 * exempt and necessary ones included, live iframes too (an exempt embed, or
	 * any embed when blocking is off). For the admin screen only.
	 */
	public static function found_ids( $html, array $services ) {
		$open = array();
		foreach ( $services as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			unset( $s['exempt'] );
			if ( isset( $s['category'] ) && 'necessary' === $s['category'] ) {
				$s['category'] = 'marketing';
			}
			$open[] = $s;
		}
		$d     = self::detect_services( $html, $open );
		$ids   = array_merge( $d['raw'], $d['blocked'] );
		if ( preg_match_all( '/<iframe\b[^>]*?(?:^|\s)src\s*=\s*(["\'])(.*?)\1/is', (string) $html, $im ) ) {
			foreach ( $im[2] as $src ) {
				$svc = CMP_Services::iframe_service( trim( html_entity_decode( $src, ENT_QUOTES ) ), $open );
				if ( null !== $svc && isset( $svc['id'] ) ) {
					$ids[] = (string) $svc['id'];
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Pure: one page's scan in the shape the admin consumers read:
	 * raw / blocked (categories), settings_link, services { raw, blocked, found }.
	 */
	public static function scan_html( $html, array $services ) {
		$html                      = (string) $html;
		$scan                      = self::detect_trackers( $html, $services );
		$scan['settings_link']     = self::has_settings_link( $html );
		$scan['services']          = self::detect_services( $html, $services );
		$scan['services']['found'] = self::found_ids( $html, $services );
		return $scan;
	}

	/**
	 * The last site scan (the multi-page aggregate, see CMP_Scanner). It never
	 * fetches a page: with $force, or when there is no result yet, the page list
	 * is queued for the background scan (WP-Cron) and the current result (or
	 * null) comes back. A whole scan that failed waits 5 minutes before a new
	 * automatic one. Used for the admin warnings and screens only.
	 */
	public static function scan_homepage( $force = false ) {
		$agg = get_option( CMP_Scanner::AGGREGATE_OPTION, false );
		$agg = is_array( $agg ) ? $agg : null;
		if ( ! $force && null !== $agg ) {
			return $agg;
		}
		if ( null === $agg ) {
			// Right after the update: the last homepage scan (pre-B3 transient) until the first aggregate.
			$legacy = get_transient( 'cmp_tracker_scan' );
			$agg    = is_array( $legacy ) ? $legacy : null;
		}
		if ( $force || ( ! get_transient( 'cmp_tracker_scan_fail' ) && ! CMP_Scanner::is_running() ) ) {
			CMP_Scanner::enqueue_all();
		}
		return $agg;
	}

	/**
	 * Save a finished scan: the aggregate (non-autoloaded), the services found
	 * on the site, the cumulative history, and the cmp_tracker_scan copy (no
	 * expiry: it is refreshed by the next scan, not by time; legacy keys only,
	 * since it is autoloaded; sources/resources live in cmp_scan_aggregate).
	 */
	public static function store_scan( array $scan ) {
		update_option( 'cmp_services_found', $scan['services']['found'], false );
		$history = get_option( 'cmp_services_history', array() );
		update_option( 'cmp_services_history', CMP_Services::merge_seen( is_array( $history ) ? $history : array(), (array) $scan['services']['found'] ), true );
		update_option( CMP_Scanner::AGGREGATE_OPTION, $scan, false );
		set_transient( 'cmp_tracker_scan', CMP_Scanner::legacy_copy( $scan ) );
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
			$thumb = class_exists( 'CMP_Thumbs' ) ? array( 'CMP_Thumbs', 'url' ) : null;
			$html  = self::rewrite( $html, self::embed_labels(), null, $thumb ); // services: active() when available, else the library
			// Advanced leaves GA4/GTM live wherever the theme put them (often before
			// wp_head), so the Consent Mode default must come first in <head>.
			if ( CMP_Services::measurement_active( $s ) && class_exists( 'CMP_Banner' ) ) {
				$signals = CMP_Banner::consent_default_payload( CMP_Consent::build_default_signals( CMP_Consent::get_categories() ), true );
				$html    = self::place_consent_default( $html, CMP_Banner::consent_default_script( $signals, true ) );
			}
		}
		if ( self::localizes_fonts( $s ) ) {
			$html = CMP_Fonts::rewrite_links( $html, CMP_Fonts::map() );
			$html = self::strip_hint_links( $html, self::FONT_HOSTS );
		}
		return $html;
	}

	/**
	 * Pure: $snippet inserted right after the first opening <head …> tag (not
	 * <header>). Without one, $html comes back unchanged.
	 */
	public static function inject_after_head( string $html, string $snippet ): string {
		if ( ! preg_match( '/<head(?=[\s>\/])[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		$at = $m[0][1] + strlen( $m[0][0] );
		return substr( $html, 0, $at ) . $snippet . substr( $html, $at );
	}

	/**
	 * Pure (Advanced): put the Consent Mode stub first in <head>. The copy
	 * printed at wp_head (marked data-cmp-default) is removed and $snippet is
	 * injected right after <head>, so the page has it exactly once. Without a
	 * <head> tag nothing changes and the wp_head copy stays as the fallback.
	 */
	public static function place_consent_default( string $html, string $snippet ): string {
		if ( ! preg_match( '/<head(?=[\s>\/])[^>]*>/i', $html ) ) {
			return $html;
		}
		$html = (string) preg_replace( '/<script\b[^>]*\sdata-cmp-default(?=[\s=>\/])[^>]*>.*?<\/script>\n?/is', '', $html );
		return self::inject_after_head( $html, $snippet );
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
	 * rules to apply (null = active services, else the library). $thumb: optional
	 * callable( $youtube_id ): local thumbnail URL or '' (YouTube embeds only).
	 */
	public static function rewrite( $html, array $labels = array(), ?array $services = null, $thumb = null ) {
		$thumb    = is_callable( $thumb ) ? $thumb : null;
		$html     = (string) $html;
		$all      = null === $services ? self::default_services() : $services;
		$services = self::rewritable( $all );
		// Script tags: also the services only the Advanced mode exempts, decided per tag.
		$scripts = self::blockable( $all );
		$html    = preg_replace_callback(
			'/<script\b([^>]*)>(.*?)<\/script>/is',
			function ( $m ) use ( $scripts ) {
				return self::rewrite_tag( $m, $scripts );
			},
			$html
		);
		$html = self::rewrite_embeds( (string) $html, $labels, $services, $thumb );
		// <noscript> never gets Consent Mode and no-JS visitors cannot consent, so
		// the GTM noscript iframe is stripped in Advanced too.
		$html = self::strip_tracker_noscript( (string) $html, self::tracker_hosts( $scripts ) );
		return self::strip_hint_links( (string) $html, self::tracker_hosts( $services ) );
	}

	/**
	 * Pure: the rewritable services plus those exempt only by the Advanced mode
	 * (mode_exempt, not the admin's own "nu bloca"). For the latter, rewrite_tag()
	 * decides per tag (measurement_tag_exempt). Same as rewritable() in Strict.
	 */
	private static function blockable( array $services ) {
		return array_values(
			array_filter(
				$services,
				function ( $s ) {
					if ( ! is_array( $s ) || ( isset( $s['category'] ) && 'necessary' === $s['category'] ) ) {
						return false;
					}
					return empty( $s['exempt'] ) || ( ! empty( $s['mode_exempt'] ) && empty( $s['own_exempt'] ) );
				}
			)
		);
	}

	/**
	 * Pure: does Advanced leave this tag live? Only GA4 and the GTM container:
	 * a gtag.js loader for a G- id, inline gtag whose config calls are all
	 * literal G- ids, the gtm.js loader for a GTM- id, or the inline GTM
	 * container snippet. Google Ads / Floodlight (AW-, DC-), mixed configs and
	 * legacy analytics.js / ga('create') stay blocked as in Strict.
	 */
	public static function measurement_tag_exempt( $service_id, $src, $body ) {
		$src  = trim( html_entity_decode( (string) $src, ENT_QUOTES ) );
		$body = (string) $body;
		if ( '' !== $src ) {
			$host = strtolower( (string) parse_url( $src, PHP_URL_HOST ) );
			$path = (string) parse_url( $src, PHP_URL_PATH );
			parse_str( (string) parse_url( $src, PHP_URL_QUERY ), $q );
			$id = isset( $q['id'] ) && is_string( $q['id'] ) ? $q['id'] : '';
			if ( ! in_array( $service_id, CMP_Services::MEASUREMENT_IDS, true ) || ( 'googletagmanager.com' !== $host && '.googletagmanager.com' !== substr( $host, -21 ) ) ) {
				return false;
			}
			// Either id: with GA set to "nu bloca" by the admin, gtag.js matches the GTM host rule.
			return ( 0 === strpos( $path, '/gtag/js' ) && self::is_ga4_id( $id ) )
				|| ( '/gtm.js' === $path && 1 === preg_match( '/^GTM-[A-Z0-9]+$/i', $id ) );
		}
		if ( ! in_array( $service_id, CMP_Services::MEASUREMENT_IDS, true ) || self::foreign_google_tag( $body ) ) {
			return false;
		}
		return (bool) preg_match( '/gtag\s*\(\s*[\'"]config[\'"]\s*,\s*[\'"]G-|gtag\/js\?id=G-|GTM-[A-Z0-9]/i', $body );
	}

	/** Pure: a GA4 measurement id (G-XXXX). */
	private static function is_ga4_id( $id ) {
		return 1 === preg_match( '/^G-[A-Z0-9]+$/i', (string) $id );
	}

	/**
	 * Pure: does an inline script carry a Google tag other than GA4/GTM? Legacy
	 * Universal Analytics (ga('create'), analytics.js), a gtag config call whose
	 * id is not a literal G- id (AW-, DC-, a variable), or a gtag.js loader URL
	 * for a non-G- id. Fails closed.
	 */
	private static function foreign_google_tag( $body ) {
		if ( preg_match( '/\bga\s*\(\s*[\'"]create[\'"]|analytics\.js|\/ga\.js/i', $body ) ) {
			return true;
		}
		if ( preg_match_all( '/gtag\s*\(\s*[\'"]config[\'"]\s*,\s*([^,)]*)/i', $body, $m ) ) {
			foreach ( $m[1] as $arg ) {
				if ( ! preg_match( '/^\s*([\'"])G-[A-Z0-9]+\1\s*$/i', $arg ) ) {
					return true;
				}
			}
		}
		if ( preg_match_all( '/gtag\/js\?id=([^&\'"\s]*)/i', $body, $m ) ) {
			foreach ( $m[1] as $id ) {
				if ( ! self::is_ga4_id( $id ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Placeholder copy in the current page language. */
	public static function embed_labels() {
		if ( ! class_exists( 'CMP_Lang' ) ) {
			return array();
		}
		$s = class_exists( 'CMP_Consent' ) ? CMP_Consent::get_settings() : array();
		$labels = array(
			'embed_text'   => CMP_Lang::text( 'embed_text' ),
			'embed_load'   => CMP_Lang::text( 'embed_load' ),
			'embed_always' => CMP_Lang::text( 'embed_always' ),
			'settings'     => CMP_Lang::text( 'reopen' ),
			'preset'       => isset( $s['preset'] ) ? (string) $s['preset'] : 'neutral',
		);
		// Light mode stores no cookie, so it has no "always load" (the button is omitted).
		if ( isset( $s['mode'] ) && 'light' === $s['mode'] ) {
			$labels['light'] = true;
		}
		return $labels;
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
	public static function rewrite_embeds( $html, array $labels = array(), ?array $services = null, $thumb = null ) {
		$services = self::rewritable( null === $services ? self::default_services() : $services );
		$labels = array_merge(
			array(
				'embed_text' => 'Conținut de la %s. Se încarcă doar cu acordul tău, pentru că poate seta cookie-uri.',
				'embed_load' => 'Încarcă conținutul',
				'embed_always' => 'Încarcă mereu',
				'settings'   => 'Setări cookie',
			),
			array_filter( $labels, 'strlen' )
		);
		return (string) preg_replace_callback(
			'/(<template\b[^>]*\bdata-cmp-embed-src\b[^>]*>.*?<\/template>)|<iframe\b([^>]*)>(.*?)<\/iframe>/is',
			function ( $m ) use ( $labels, $services, $thumb ) {
				if ( ! empty( $m[1] ) ) {
					return $m[1]; // an iframe already held in a consent placeholder
				}
				$attrs = $m[2];
				$m     = array( $m[0], $attrs );
				if ( self::has_keep( $attrs ) ) {
					return $m[0];
				}
				if ( ! preg_match( '/(?:^|\s)src\s*=\s*(["\'])(.*?)\1/is', $attrs, $sm ) ) {
					return $m[0];
				}
				$src = trim( html_entity_decode( $sm[2], ENT_QUOTES ) );

				$host  = parse_url( $src, PHP_URL_HOST );
				$hname = is_string( $host ) && '' !== $host ? $host : preg_replace( '/[?#].*$/s', '', $src );

				// An explicit data-cmp-category wins over the library.
				$cat = self::tag_category( $attrs );
				if ( false === $cat ) {
					return $m[0];
				}
				if ( null !== $cat && '' !== $cat ) {
					return self::embed_placeholder( $m[0], $attrs, $sm, $src, $cat, null, $hname, $labels, false );
				}

				// Library embeds first, then the admin's custom services (by host).
				$embed = CMP_Services::iframe_service( $src, $services );
				if ( null === $embed ) {
					// An invalid data-cmp-category fails closed: blocked as marketing.
					return '' === $cat ? self::embed_placeholder( $m[0], $attrs, $sm, $src, 'marketing', null, $hname, $labels, false ) : $m[0];
				}
				if ( isset( $embed['source'] ) && 'custom' === $embed['source'] ) {
					$cname = isset( $embed['name'] ) && '' !== (string) $embed['name'] ? (string) $embed['name'] : $hname;
					return self::embed_placeholder( $m[0], $attrs, $sm, $src, $embed['category'], $embed['id'], $cname, $labels, false );
				}
				$thumb_url = '';
				if ( null !== $thumb && 'youtube' === $embed['id'] ) {
					$yid = self::youtube_id( $src );
					if ( '' !== $yid ) {
						$thumb_url = (string) call_user_func( $thumb, $yid );
					}
				}
				return self::embed_placeholder( $m[0], $attrs, $sm, $src, $embed['category'], $embed['id'], $embed['name'], $labels, ! empty( $embed['nocookie'] ), $thumb_url );
			},
			(string) $html
		);
	}

	/**
	 * Pure: the YouTube video id of an embed URL (/embed/<id> on a YouTube host), or ''.
	 * Strict on purpose: the id later names a local file.
	 */
	public static function youtube_id( $src ) {
		$parts = parse_url( trim( (string) $src ) );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return '';
		}
		$hosts = array( 'youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com' );
		if ( ! in_array( strtolower( $parts['host'] ), $hosts, true ) ) {
			return '';
		}
		return 1 === preg_match( '#\A/embed/([A-Za-z0-9_-]{11})\z#', $parts['path'], $m ) ? $m[1] : '';
	}

	/** Pure: a thumbnail URL made safe for an HTML attribute and a CSS url('...'), or ''. */
	private static function thumb_css_url( $url ) {
		$url = strtr( trim( (string) $url ), array( "'" => '%27', '"' => '%22', '(' => '%28', ')' => '%29', '\\' => '%5C', ' ' => '%20', "\t" => '%09', "\n" => '%0A', "\r" => '%0D', "\f" => '%0C', '<' => '%3C', '>' => '%3E' ) );
		return function_exists( 'esc_url' ) ? (string) esc_url( $url ) : htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Pure: the data-cmp-category of a tag's attributes, trimmed and lowercased.
	 * Null = no attribute or a blank value (normal library handling), false =
	 * "necessary" (leave the tag alone), '' = present but invalid (fail closed:
	 * the library rule if one matches, else blocked as marketing), else the slug.
	 * Unknown valid slugs are blocked on purpose (fail-safe). Quoted,
	 * single-quoted and unquoted values are recognised, also with no whitespace
	 * before or after the attribute.
	 */
	private static function tag_category( $attrs ) {
		if ( ! preg_match( '/(?:^|[\s\/"\'])data-cmp-category\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>\/]+)(?=\s|\/|>|$))/i', $attrs, $cm ) ) {
			return null;
		}
		$v = '';
		for ( $i = 1; $i <= 3; $i++ ) {
			if ( isset( $cm[ $i ] ) && '' !== $cm[ $i ] ) {
				$v = $cm[ $i ];
				break;
			}
		}
		$v = strtolower( trim( html_entity_decode( $v, ENT_QUOTES ) ) );
		if ( '' === $v ) {
			return null;
		}
		if ( ! preg_match( '/^[a-z0-9_-]{1,40}$/', $v ) ) {
			return '';
		}
		return 'necessary' === $v ? false : $v;
	}

	/** Pure: does the attribute string carry the data-cmp-keep attribute (not just the text)? */
	private static function has_keep( $attrs ) {
		return 1 === preg_match( '/(?:^|\s)data-cmp-keep(?=[\s=\/>]|$)/i', (string) $attrs );
	}

	/** Pure: an already-blocked script: type="text/plain" plus an exact data-cmp-block attribute. */
	private static function is_blocked_tag( $attrs ) {
		return 1 === preg_match( '/(?:^|\s)type\s*=\s*["\']?text\/plain/i', $attrs )
			&& 1 === preg_match( '/(?:^|[\s"\'\/])data-cmp-block(?=[\s=\/>]|$)/i', $attrs );
	}

	/** Pure: the consent placeholder wrapping an iframe ($service_id null = anonymous). */
	private static function embed_placeholder( $iframe, $attrs, array $sm, $src, $category, $service_id, $name, array $labels, $nocookie, $thumb_url = '' ) {
		if ( $nocookie && preg_match( '#^https?://(?:www\.)?youtube\.com/embed/#i', $src ) ) {
			$nc     = preg_replace( '#^https?://(?:www\.)?youtube\.com/#i', 'https://www.youtube-nocookie.com/', $src );
			$iframe = str_replace( $sm[0], str_replace( $sm[2], htmlspecialchars( $nc, ENT_QUOTES, 'UTF-8' ), $sm[0] ), $iframe );
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
		$text = str_replace( '%s', $e( $name ), $e( $labels['embed_text'] ) );

		$preset  = isset( $labels['preset'] ) && in_array( $labels['preset'], array( 'neutral', 'warm', 'dark', 'egov' ), true ) ? ' cmp--' . $labels['preset'] : '';
		$service = null === $service_id ? '' : ' data-cmp-service="' . $e( $service_id ) . '" data-cmp-service-name="' . $e( $name ) . '"';
		// "Always load" only for library services, and never in Light mode (no cookie there).
		$always = ( null === $service_id || ! empty( $labels['light'] ) )
			? ''
			: '<button type="button" class="cmp-embed__always" data-cmp-embed-always aria-label="' . $e( $labels['embed_always'] . ': ' . $name ) . '">' . $e( $labels['embed_always'] ) . '</button>';
		$thumb_css = '' === (string) $thumb_url ? '' : self::thumb_css_url( $thumb_url );
		$bg        = '' === $thumb_css ? '' : ";background-image:url('" . $thumb_css . "')";
		$thumb_cls = '' === $bg ? '' : ' cmp-embed--thumb';
		return '<div class="cmp-embed' . $preset . $thumb_cls . '" data-cmp-embed="' . $e( $category ) . '"' . $service . ' style="aspect-ratio:' . $ratio . $max . $bg . '">'
			. '<div class="cmp-embed__inner">'
			. '<p class="cmp-embed__text">' . $text . '</p>'
			. '<p class="cmp-embed__actions">'
			. '<button type="button" class="cmp-embed__btn" data-cmp-embed-load>' . $e( $labels['embed_load'] ) . '</button>'
			. $always
			. '<a href="#cmp-settings" class="cmp-embed__link" data-cmp-settings>' . $e( $labels['settings'] ) . '</a>'
			. '</p></div>'
			. '<template data-cmp-embed-src>' . $iframe . '</template>'
			. '</div>';
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
				// A host/path rule (www.google.com/recaptcha) needs its full pattern in
				// the body; the bare host alone would catch unrelated scripts.
				foreach ( (array) ( isset( $s['hosts'] ) ? $s['hosts'] : array() ) as $h ) {
					if ( '' !== (string) $h && self::has_host( $body, (string) $h ) ) {
						return $s;
					}
				}
			}
		}
		return $svc;
	}

	/**
	 * Pure: the non-executing replacement for a script. $src / $body are stored
	 * base64-encoded so the tracker URL never appears in the page source before
	 * consent; $attrs keeps id, async, defer, … but drops type + src.
	 * $service_id null = anonymous (no data-cmp-service).
	 */
	private static function blocked_script( $category, $service_id, $src, $body, $attrs ) {
		$keep = preg_replace( '/(^|\s)type\s*=\s*["\'][^"\']*["\']/i', '$1', (string) $attrs );
		$keep = preg_replace( '/(^|\s)src\s*=\s*["\'][^"\']*["\']/i', '$1', $keep );
		$keep = trim( preg_replace( '/\s+/', ' ', $keep ) );
		$keep = '' === $keep ? '' : ' ' . $keep;
		if ( '' !== $src ) {
			$enc = ' data-cmp-enc="1" data-cmp-src="' . base64_encode( $src ) . '"';
		} else {
			$enc = ' data-cmp-enc="1" data-cmp-body="' . base64_encode( $body ) . '"';
		}
		$svc = null === $service_id ? '' : ' data-cmp-service="' . htmlspecialchars( (string) $service_id, ENT_QUOTES, 'UTF-8' ) . '"';
		return '<script type="text/plain" data-cmp-block="' . htmlspecialchars( (string) $category, ENT_QUOTES, 'UTF-8' ) . '"' . $svc . $enc . $keep . '></script>';
	}

	/**
	 * Pure: the footer markup for the custom services' own scripts. Necessary /
	 * exempt ones run normally (both modes); others are emitted blocked in Pro
	 * and not at all in Light (which cannot collect consent).
	 */
	public static function custom_script_html( array $services, $mode ) {
		$out = '';
		foreach ( $services as $s ) {
			if ( ! is_array( $s ) || ! isset( $s['source'] ) || 'custom' !== $s['source'] || empty( $s['script'] ) || ! is_string( $s['script'] ) ) {
				continue;
			}
			$cat  = isset( $s['category'] ) ? (string) $s['category'] : '';
			$free = 'necessary' === $cat || ! empty( $s['exempt'] );
			if ( ! $free && ( 'pro' !== $mode || ! preg_match( '/^[a-z0-9_-]{1,40}$/', $cat ) ) ) {
				continue;
			}
			$tags = array(); // each: array( attrs, body )
			if ( false !== stripos( $s['script'], '<script' ) ) {
				if ( preg_match_all( '/<script\b([^>]*)>(.*?)<\/script>/is', $s['script'], $mm, PREG_SET_ORDER ) ) {
					foreach ( $mm as $t ) {
						$tags[] = array( $t[1], $t[2] );
					}
				}
			} else {
				$tags[] = array( '', $s['script'] );
			}
			foreach ( $tags as $t ) {
				if ( $free ) {
					$attrs = ! self::has_keep( $t[0] ) ? ' data-cmp-keep' . $t[0] : $t[0];
					$out  .= '<script' . $attrs . '>' . $t[1] . "</script>\n";
				} else {
					$out .= self::blocked_script( $cat, isset( $s['id'] ) ? $s['id'] : null, self::script_src( $t[0] ), $t[1], $t[0] ) . "\n";
				}
			}
		}
		return $out;
	}

	private static function rewrite_tag( $m, array $services ) {
		$attrs = $m[1];
		$body  = $m[2];

		// Never touch our own tagged scripts (e.g. the Consent Mode init) or ones
		// already blocked (footer custom scripts pass through the buffer too).
		if ( self::has_keep( $attrs ) || self::is_blocked_tag( $attrs ) ) {
			return $m[0];
		}

		$src = self::script_src( $attrs );

		// An explicit data-cmp-category wins over the library.
		$cat = self::tag_category( $attrs );
		if ( false === $cat ) {
			return $m[0];
		}
		if ( null !== $cat && '' !== $cat ) {
			return self::blocked_script( $cat, null, $src, $body, $attrs );
		}

		$svc = self::script_for( $src, $body, $services );
		if ( null === $svc ) {
			// An invalid data-cmp-category fails closed: blocked as marketing.
			return '' === $cat ? self::blocked_script( 'marketing', null, $src, $body, $attrs ) : $m[0];
		}
		// Advanced: only GA4 / the GTM container stay live, decided per tag.
		if ( ! empty( $svc['mode_exempt'] ) && empty( $svc['own_exempt'] ) && self::measurement_tag_exempt( $svc['id'], $src, $body ) ) {
			// A non-Google tracker in the same tag (GA4 config + fbq, GTM + Clarity)
			// keeps it blocked, under that other service (N1).
			$others = array_values(
				array_filter(
					$services,
					function ( $s ) {
						return ! isset( $s['id'] ) || ! in_array( (string) $s['id'], CMP_Services::MEASUREMENT_IDS, true );
					}
				)
			);
			$other = self::script_for( $src, $body, $others );
			if ( null === $other ) {
				return $m[0];
			}
			return self::blocked_script( $other['category'], $other['id'], $src, $body, $attrs );
		}
		return self::blocked_script( $svc['category'], $svc['id'], $src, $body, $attrs );
	}
}
