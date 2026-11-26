<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * B6 "Vezi pe site": the admin previews the UNSAVED appearance on the real site.
 *
 * - The Aspect screen posts the form (AJAX, nonce + manage_options). The values
 *   go through the same path as the save (CMP_Sanitizer::aspect() over the
 *   stored settings) and the keys that save changes are kept in the transient
 *   cmp_preview_<user id> (30 minutes, overwritten at each click).
 * - The same AJAX answer sets the cookie cmp_preview_t = a cmp_preview nonce
 *   (HttpOnly, SameSite=Strict, Secure on HTTPS, 30 minutes) and gives the
 *   home page with the marker ?cmp_preview=1. No nonce in any URL, so none
 *   reaches history, logs or the trackers that run after "Accept".
 * - On the front end the preview is on only with the marker =1 AND a cookie
 *   that verifies as a cmp_preview nonce of the current user with
 *   manage_options (CMP_Scanner::token_mode(), as the check mode); otherwise
 *   the parameter is ignored completely (an old ?cmp_preview=<nonce> link too).
 * - „Închide previzualizarea” links to the same page without the marker; the
 *   cookie is then inert and expires by itself (30 minutes).
 * - A preview page is an isolated admin session (CMP_Scanner::isolated()): no
 *   cache, the admin bar hidden, the session check cookies (the banner shows
 *   even when the admin has decided; nothing reaches the consent register:
 *   CMP_DATA.check), no exclusions. Blocking runs normally. The banner renders
 *   with the transient's appearance (fallback: the stored one).
 * - Not a byte in banner.js / banner.css: the banner side is the check mode;
 *   banner-check.js (measuring) is NOT loaded. The bar is printed by PHP.
 */
class CMP_Preview {

	/** The front-end marker parameter (?cmp_preview=1) and the nonce action. */
	const PARAM = 'cmp_preview';

	/** The cookie holding the cmp_preview nonce (set by the AJAX answer). */
	const COOKIE = 'cmp_preview_t';

	/** The AJAX action (and its nonce action) that stores the form values. */
	const AJAX = 'cmp_preview_store';

	/** The live preview of the Aspect screen (#727 step 4): the AJAX action and its nonce action. */
	const RENDER = 'cmp_preview_render';

	/** Transient prefix (+ user id). */
	const KEY = 'cmp_preview_';

	/** Transient lifetime: 30 minutes. */
	const TTL = 1800;

	/** The settings keys an Aspect save changes (CMP_Sanitizer::aspect()), so the preview overrides. */
	const KEYS = array( 'appearance', 'preset', 'reopen_button', 'texts' );

	/** @var bool Bar already printed on this page. */
	private static $bar_done = false;

	public static function register() {
		add_action( 'wp_ajax_' . self::AJAX, array( __CLASS__, 'ajax_store' ) );
		add_action( 'wp_ajax_' . self::RENDER, array( __CLASS__, 'ajax_render' ) );
		add_action( 'wp_body_open', array( __CLASS__, 'print_bar' ), 1 );
		// Themes without wp_body_open: at the end of the page.
		add_action( 'wp_footer', array( __CLASS__, 'print_bar' ), 1 );
	}

	/** Is this front-end request a valid preview (nonce of the current user + manage_options)? */
	public static function active(): bool {
		return class_exists( 'CMP_Scanner' ) && CMP_Scanner::token_mode( self::PARAM, self::PARAM, self::COOKIE );
	}

	/** Pure: the transient name of a user. */
	public static function key( $user_id ) {
		return self::KEY . (int) $user_id;
	}

	/**
	 * Pure: what the transient keeps, from the posted form ($raw = $_POST['cmp'],
	 * unslashed) over the stored settings $old: the same sanitizing as the
	 * save, then only the keys that save changes.
	 */
	public static function build( array $raw, array $old, $multilingual ) {
		$saved = CMP_Sanitizer::aspect( $raw, $old, $multilingual );
		$out   = array();
		foreach ( self::KEYS as $k ) {
			if ( array_key_exists( $k, $saved ) ) {
				$out[ $k ] = $saved[ $k ];
			}
		}
		return $out;
	}

	/**
	 * Pure: the settings a preview renders with: the stored settings with the
	 * preview keys taken from $overlay (the transient; anything that is not
	 * an array -> the stored settings unchanged). Only the known keys, with
	 * their types.
	 */
	public static function apply( array $stored, $overlay ) {
		if ( ! is_array( $overlay ) ) {
			return $stored;
		}
		if ( isset( $overlay['appearance'] ) && is_array( $overlay['appearance'] ) ) {
			$stored['appearance'] = $overlay['appearance'];
		}
		if ( isset( $overlay['preset'] ) && is_string( $overlay['preset'] ) ) {
			$stored['preset'] = $overlay['preset'];
		}
		if ( array_key_exists( 'reopen_button', $overlay ) ) {
			$stored['reopen_button'] = (bool) $overlay['reopen_button'];
		}
		if ( isset( $overlay['texts'] ) && is_array( $overlay['texts'] ) ) {
			$stored['texts'] = $overlay['texts'];
		}
		return $stored;
	}

	/**
	 * The settings the banner renders with on this request: the stored ones,
	 * or in a preview the stored ones with the current user's transient.
	 */
	public static function settings( array $stored ) {
		if ( ! self::active() ) {
			return $stored;
		}
		return self::apply( $stored, get_transient( self::key( get_current_user_id() ) ) );
	}

	/**
	 * Store the overlay for $user_id (overwrites the previous one), 30 minutes.
	 *
	 * @param callable|null $set set_transient (injectable for the tests).
	 */
	public static function store( $user_id, array $overlay, $set = null ) {
		$set = $set ? $set : 'set_transient';
		return (bool) call_user_func( $set, self::key( $user_id ), $overlay, self::TTL );
	}

	/** Pure: the preview link (home page + the marker; never the nonce). */
	public static function url( $home ) {
		$home = (string) $home;
		$h    = strpos( $home, '#' );
		if ( false !== $h ) {
			$home = substr( $home, 0, $h );
		}
		return $home . ( false === strpos( $home, '?' ) ? '?' : '&' ) . self::PARAM . '=1';
	}

	/** Pure: the options of the nonce cookie (PHP 7.3+ setcookie array form). */
	public static function cookie_options( $now, $secure, $path, $domain = '' ) {
		return array(
			'expires'  => (int) $now + self::TTL,
			'path'     => '' !== (string) $path ? (string) $path : '/',
			'domain'   => (string) $domain,
			'secure'   => (bool) $secure,
			'httponly' => true,
			'samesite' => 'Strict',
		);
	}

	/** Set the nonce cookie on the site paths (COOKIEPATH, and SITECOOKIEPATH when different). */
	public static function send_cookie( $nonce ) {
		if ( headers_sent() ) {
			return;
		}
		$domain = defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '';
		$paths  = array_unique( array( defined( 'COOKIEPATH' ) ? COOKIEPATH : '/', defined( 'SITECOOKIEPATH' ) ? SITECOOKIEPATH : '/' ) );
		foreach ( $paths as $path ) {
			setcookie( self::COOKIE, (string) $nonce, self::cookie_options( time(), is_ssl(), $path, $domain ) );
		}
	}

	/**
	 * Pure: the edge for the preview bar, opposite the previewed banner:
	 * 'bottom' when the banner sits at the top (bar/modal top, top cards),
	 * 'top' otherwise (the Light card is at the bottom).
	 */
	public static function bar_side( array $a, $mode ) {
		if ( 'light' === $mode ) {
			return 'top';
		}
		$pos = isset( $a['position'] ) ? (string) $a['position'] : '';
		return 0 === strpos( $pos, 'top' ) ? 'bottom' : 'top';
	}

	/** Pure: the URL without the cmp_preview parameter ("Închide previzualizarea"). */
	public static function close_url( $url ) {
		$s    = (string) $url;
		$h    = strpos( $s, '#' );
		$hash = false === $h ? '' : substr( $s, $h );
		$s    = false === $h ? $s : substr( $s, 0, $h );
		$q    = strpos( $s, '?' );
		if ( false === $q ) {
			return $s . $hash;
		}
		$keep = array();
		foreach ( explode( '&', substr( $s, $q + 1 ) ) as $p ) {
			$name = explode( '=', $p, 2 );
			if ( '' !== $p && self::PARAM !== rawurldecode( $name[0] ) ) {
				$keep[] = $p;
			}
		}
		return substr( $s, 0, $q ) . ( $keep ? '?' . implode( '&', $keep ) : '' ) . $hash;
	}

	/**
	 * Pure: the admin-only preview bar. Above everything (also the banner's
	 * overlay and blur: readable and clickable on first view), on the edge
	 * opposite the banner ($side 'top' | 'bottom'). Not part of the banner.
	 */
	public static function bar_html( $close_url, $side = 'top' ) {
		$side = 'bottom' === $side ? 'bottom' : 'top';
		return '<div id="cmp-preview-bar" role="region" aria-label="' . esc_attr__( 'Previzualizare aspect', 'biscuitwp' ) . '"'
			. ' style="position:fixed;' . $side . ':0;left:0;right:0;z-index:2147483647;display:flex;flex-wrap:wrap;gap:4px 16px;align-items:center;justify-content:center;'
			. 'padding:8px 16px;background:#1d2327;color:#fff;font:600 14px/1.4 system-ui,-apple-system,sans-serif;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.3)">'
			. '<span>' . esc_html__( 'Previzualizare: setările nu sunt salvate', 'biscuitwp' ) . '</span>'
			. '<a href="' . esc_url( $close_url ) . '" style="color:#72aee6;text-decoration:underline;padding:4px;outline-offset:2px">' . esc_html__( 'Închide previzualizarea', 'biscuitwp' ) . '</a>'
			. '</div>' . "\n";
	}

	/** wp_body_open / wp_footer: the bar, once, only in a valid preview. */
	public static function print_bar() {
		if ( self::$bar_done || ! self::active() ) {
			return;
		}
		self::$bar_done = true;
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- escaped by esc_url in bar_html().
		// Same page, without the parameter (a root-relative link: always this site).
		$close = self::close_url( '/' . ltrim( $uri, '/' ) );
		$s     = CMP_Banner::settings();
		$mode  = isset( $s['mode'] ) && 'light' === $s['mode'] ? 'light' : 'pro';
		echo self::bar_html( $close, self::bar_side( CMP_Banner::appearance( $s ), $mode ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in bar_html().
	}

	/** wp_ajax_cmp_preview_store: store the posted form for this user, answer the preview link. */
	public static function ajax_store() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( self::AJAX, 'nonce', false ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
		$raw = isset( $_POST['cmp'] ) && is_array( $_POST['cmp'] ) ? wp_unslash( $_POST['cmp'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in CMP_Sanitizer::aspect().
		$old = CMP_Consent::get_settings();
		$old = is_array( $old ) ? $old : array();
		self::store( get_current_user_id(), self::build( $raw, $old, CMP_Banner::multilingual() ) );
		self::send_cookie( wp_create_nonce( self::PARAM ) );
		wp_send_json_success( array( 'url' => self::url( home_url( '/' ) ) ) );
	}

	/** The locales of the panel's languages (the packs of CMP_Lang). */
	const LOCALES = array( 'ro' => 'ro_RO', 'ru' => 'ru_RU', 'en' => 'en_US' );

	/**
	 * The banner of these settings as the site renders it, for the live preview:
	 * the template's HTML (Pro or Light, as the stored mode; without the reopen
	 * button) and the generated CSS. No script, no link.
	 *
	 * @param string $lang 'ro' | 'ru' | 'en', or '' for the site language. Ignored with WPML / Polylang.
	 */
	public static function render_parts( array $settings, array $categories, $lang ) {
		$locale = isset( self::LOCALES[ $lang ] ) ? self::LOCALES[ $lang ] : ( function_exists( 'get_locale' ) ? get_locale() : 'ro_RO' );
		// admin-ajax.php is an admin request: determine_locale() would give the admin's own language.
		$force = function () use ( $locale ) {
			return $locale;
		};
		add_filter( 'determine_locale', $force, PHP_INT_MAX );
		// WPML / Polylang: read the translations, never register the unsaved texts.
		$dry             = CMP_I18n::$dry;
		CMP_I18n::$dry   = true;
		$preset          = isset( $settings['preset'] ) ? $settings['preset'] : 'neutral';
		$mode            = isset( $settings['mode'] ) ? $settings['mode'] : 'pro';
		$cmp_a           = CMP_Banner::appearance( $settings );
		$no_overlay      = false;
		$level           = ob_get_level();
		// WPML: its current language in the admin is the admin's; show the site's default.
		// Only real WPML: Polylang's WPML layer defines the same constant and filters.
		$switched = false;
		$wpml_cur = null;
		if ( '' === (string) $lang && defined( 'ICL_SITEPRESS_VERSION' ) && ! function_exists( 'pll_current_language' ) && function_exists( 'apply_filters' ) && function_exists( 'do_action' ) ) {
			$wpml_def = apply_filters( 'wpml_default_language', null );
			$wpml_cur = apply_filters( 'wpml_current_language', null );
			if ( is_string( $wpml_def ) && '' !== $wpml_def && $wpml_def !== $wpml_cur ) {
				do_action( 'wpml_switch_language', $wpml_def );
				$switched = true;
			}
			if ( is_string( $wpml_def ) && '' !== $wpml_def ) {
				CMP_Lang::$force = $wpml_def;
			}
		}
		try {
			ob_start();
			include CMP_PATH . ( 'light' === $mode ? 'templates/banner-light.php' : 'templates/banner-bottom.php' );
			$html = (string) ob_get_clean();
			$used = CMP_Lang::current();
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			CMP_I18n::$dry   = $dry;
			CMP_Lang::$force = null;
			if ( $switched ) {
				// null resets WPML to its own language for the request ('' would be an invalid code).
				do_action( 'wpml_switch_language', is_string( $wpml_cur ) && '' !== $wpml_cur ? $wpml_cur : null );
			}
			remove_filter( 'determine_locale', $force, PHP_INT_MAX );
		}
		return array(
			'html' => $html,
			'css'  => CMP_Banner::style_html( $cmp_a, CMP_Banner::theme_palette( $cmp_a ) ),
			'lang' => $used,
		);
	}

	/** wp_ajax_cmp_preview_render: the banner of the posted (unsaved) form. Writes nothing. */
	public static function ajax_render() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( self::RENDER, 'nonce', false ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
		$raw  = isset( $_POST['cmp'] ) && is_array( $_POST['cmp'] ) ? wp_unslash( $_POST['cmp'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in CMP_Sanitizer::aspect().
		$lang = isset( $_POST['lang'] ) && is_string( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked against LOCALES.
		$old  = CMP_Consent::get_settings();
		$old  = is_array( $old ) ? $old : array();
		$cats = CMP_Consent::get_categories();
		$set  = self::apply( $old, self::build( $raw, $old, CMP_Banner::multilingual() ) );
		$out  = self::render_parts( $set, is_array( $cats ) ? $cats : array(), CMP_Banner::multilingual() ? '' : $lang );
		$css  = CMP_Assets::src_or_min( 'assets/css/banner.css', defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG, CMP_PATH );
		$out['bannerCss'] = add_query_arg( 'ver', CMP_Plugin::asset_ver( $css ), CMP_URL . $css );
		wp_send_json_success( $out );
	}
}
