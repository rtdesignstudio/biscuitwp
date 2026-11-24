<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMP_Banner {

	/** wp_head priority of the appearance <style>: after wp_print_styles (8), so after banner.css. */
	const STYLE_PRIORITY = 9;

	/** The floating reopen button's cookie icon (today's markup). */
	const REOPEN_ICON = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5Z"/><circle cx="9.5" cy="10.5" r="1"/><circle cx="14.5" cy="14.5" r="1"/><circle cx="9" cy="15" r="1"/></svg>';

	public function __construct() {
		add_action( 'wp_head', array( $this, 'print_consent_default' ), 1 );
		add_action( 'wp_head', array( $this, 'print_appearance_css' ), self::STYLE_PRIORITY );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'render' ) );
		add_action( 'wp_footer', array( $this, 'print_custom_scripts' ), 20 );
	}

	/** Scripts of the custom services: blocked until consent (Pro), or run directly when necessary/exempt. */
	public function print_custom_scripts() {
		$settings = CMP_Consent::get_settings();
		$mode     = isset( $settings['mode'] ) ? $settings['mode'] : 'pro';
		echo CMP_Blocker::custom_script_html( CMP_Services::active(), $mode ); // phpcs:ignore WordPress.Security.EscapeOutput -- admin-authored script markup, built and escaped in the blocker.
	}

	/** Pure: the gtag consent default payload, or null when nothing is printed (Strict with no signals). */
	public static function consent_default_payload( array $signals, $advanced ) {
		if ( empty( $signals ) && ! $advanced ) {
			return null;
		}
		$defaults = array(
			'analytics_storage'       => 'denied',
			'ad_storage'              => 'denied',
			'ad_user_data'            => 'denied',
			'ad_personalization'      => 'denied',
			'functionality_storage'   => 'denied',
			'personalization_storage' => 'denied',
			'security_storage'        => 'granted',
			'wait_for_update'         => 500,
		);
		$signals = array_merge( $defaults, $signals );
		return $advanced ? CMP_Consent::advanced_defaults( $signals ) : $signals;
	}

	/**
	 * Pure: the Consent Mode default <script>. $advanced adds the data-cmp-default
	 * marker, by which the blocker's output buffer moves this stub to right after
	 * <head> (CMP_Blocker::place_consent_default). Strict markup is unmarked.
	 */
	public static function consent_default_script( array $signals, $advanced ) {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $signals ) : json_encode( $signals );
		return '<script data-cmp-keep' . ( $advanced ? ' data-cmp-default' : '' ) . ">\n"
			. "window.dataLayer = window.dataLayer || [];\n"
			. "function gtag(){dataLayer.push(arguments);}\n"
			. 'gtag("consent","default",' . $json . ");\n"
			. "</script>\n";
	}

	/**
	 * Emit gtag consent default BEFORE any tag manager fires. In Advanced the
	 * output buffer then moves it to right after <head> (tags hardcoded before
	 * wp_head stay live there); this print is the fallback when it cannot.
	 */
	public function print_consent_default() {
		$advanced = CMP_Services::advanced_now();
		$signals  = self::consent_default_payload(
			CMP_Consent::build_default_signals( CMP_Consent::get_categories() ),
			$advanced
		);
		if ( null === $signals ) {
			return;
		}
		echo self::consent_default_script( $signals, $advanced ); // phpcs:ignore WordPress.Security.EscapeOutput -- fixed markup plus JSON-encoded signals.
	}

	public function enqueue() {
		$cmp_settings = self::settings();

		// Minified build unless SCRIPT_DEBUG (see bin/build-assets.sh), or the source
		// when a .min file is missing on the server.
		$debug = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;
		$css   = CMP_Assets::src_or_min( 'assets/css/banner.css', $debug, CMP_PATH );
		$js    = CMP_Assets::src_or_min( 'assets/js/banner.js', $debug, CMP_PATH );
		wp_enqueue_style( 'cmp-banner', CMP_URL . $css, array(), CMP_Plugin::asset_ver( $css ) );
		wp_enqueue_script( 'cmp-banner', CMP_URL . $js, array(), CMP_Plugin::asset_ver( $js ), true );
		// The check-mode runner (admin "Verificare"): only on a checked page, after banner.js.
		if ( CMP_Scanner::check_mode() ) {
			$cjs = CMP_Assets::src_or_min( 'assets/js/banner-check.js', $debug, CMP_PATH );
			wp_enqueue_script( 'cmp-banner-check', CMP_URL . $cjs, array( 'cmp-banner' ), CMP_Plugin::asset_ver( $cjs ), true );
		}

		// Embed services by id (library embeds and custom services, which can
		// embed too), for the "always loaded" row in the preferences: static, so
		// it stays correct on cached pages.
		$svc_names = CMP_Services::svc_names( CMP_Services::active() );

		$cmp_data = array(
			'categories' => CMP_Consent::get_categories(),
			'version'    => CMP_Consent::current_version(),
			// The plugin's own version, for window.CMP.version (`version` is the policy version).
			'pluginVersion' => CMP_VERSION,
			'mode'       => isset( $cmp_settings['mode'] ) ? $cmp_settings['mode'] : 'pro',
			'cookieName' => CMP_Consent::COOKIE,
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'reopenButton' => ! empty( $cmp_settings['reopen_button'] ),
			// B6: display delay, theme "like the site", excluded page (no auto-open).
			// Check mode measures every page as a first visit and the preview always
			// shows the banner: never excluded in an isolated admin session.
			'appearance' => self::appearance_data(
				self::appearance( $cmp_settings ),
				self::excluded_now( self::appearance( $cmp_settings ), CMP_Scanner::isolated() )
			),
			'reloadOnDecision' => ! empty( $cmp_settings['reload_on_decision'] ),
			// Global Privacy Control: auto-refusal without the banner (Settings, default on).
			'respectGPC' => self::respect_gpc( $cmp_settings ),
			'svcNames'   => (object) $svc_names,
			'cookieDays' => CMP_Sanitizer::cap_cookie_days( isset( $cmp_settings['cookie_days'] ) ? $cmp_settings['cookie_days'] : 180 ),
		);
		// svcByCat: a MISSING key means "unknown" (no scan has ever run). Custom
		// services alone are not a known map either: a partial map would re-prompt
		// everyone once the first scan lands. The JS must then store no `svc`
		// snapshot (legacy behaviour) and never trigger re-consent.
		// Same map as CMP_Consent::is_allowed() (one shared, memoised helper).
		$cmp_by_cat = CMP_Services::current_svc_by_cat( (array) $cmp_data['categories'] );
		if ( null !== $cmp_by_cat ) {
			$cmp_data['svcByCat'] = (object) $cmp_by_cat;
			// Names for the "new" marker of the re-consent view (svcNames covers embeds only).
			$cmp_data['svcLabels'] = (object) CMP_Services::svc_labels( CMP_Services::active(), $cmp_by_cat );
		}
		// WP Consent API bridge: only present when that plugin is active.
		if ( CMP_Consent_API::active() ) {
			$cmp_data['wpConsent'] = (object) CMP_Consent_API::map( wp_list_pluck( (array) $cmp_data['categories'], 'slug' ) );
			// wp-consent-api.js is enqueued at PHP_INT_MAX - 100, so add the dependency after that.
			add_action( 'wp_enqueue_scripts', array( $this, 'depend_on_consent_api' ), PHP_INT_MAX - 90 );
		}
		// Check mode (B3) and preview (B6): session check cookies, no reload, no
		// register write (CMP_DATA.check); unchanged otherwise.
		$cmp_data = CMP_Scanner::check_data( $cmp_data, CMP_Scanner::isolated() );
		wp_localize_script( 'cmp-banner', 'CMP_DATA', $cmp_data );
	}

	/** Make cmp-banner load after wp-consent-api, when that script is registered. */
	public function depend_on_consent_api() {
		$scripts = wp_scripts();
		if ( wp_script_is( 'wp-consent-api', 'registered' ) && isset( $scripts->registered['cmp-banner'] )
			&& ! in_array( 'wp-consent-api', $scripts->registered['cmp-banner']->deps, true ) ) {
			$scripts->registered['cmp-banner']->deps[] = 'wp-consent-api';
		}
	}

	public function render() {
		$settings   = self::settings();
		$categories = CMP_Consent::get_categories();
		$preset     = isset( $settings['preset'] ) ? $settings['preset'] : 'neutral';
		$mode       = isset( $settings['mode'] ) ? $settings['mode'] : 'pro';
		$cmp_a      = self::appearance( $settings );

		if ( 'light' === $mode ) {
			include CMP_PATH . 'templates/banner-light.php';
			return;
		}
		$no_overlay = CMP_Consent::is_policy_page(
			is_singular() ? get_queried_object_id() : 0,
			$settings,
			(int) get_option( 'wp_page_for_privacy_policy' )
		);
		include CMP_PATH . 'templates/banner-bottom.php';

		echo self::reopen_html( self::reopen_shape( $settings, $cmp_a ), CMP_Lang::text( 'reopen' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- fixed markup, label escaped inside.
	}

	/** The appearance variables and the admin's custom CSS, after banner.css (both modes). */
	public function print_appearance_css() {
		$settings = self::settings();
		$a        = self::appearance( is_array( $settings ) ? $settings : array() );
		echo self::style_html( $a, self::theme_palette( $a ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- generated CSS; custom CSS cleaned, no "<".
	}

	/**
	 * The theme's colour palette for "Preia din temă" (B6.1): theme.json (block
	 * themes; classic themes' editor-color-palette lands there too). Read only
	 * when the option is on; array() before WordPress 5.9 or in tests.
	 */
	public static function theme_palette( array $a ) {
		if ( empty( $a['theme_colors'] ) || ! function_exists( 'wp_get_global_settings' ) ) {
			return array();
		}
		$p = wp_get_global_settings( array( 'color', 'palette', 'theme' ) );
		return is_array( $p ) ? $p : array();
	}

	/**
	 * Pure: the <style> blocks for an appearance, '' when there is nothing to
	 * print (Brand + any palette, no custom CSS: legacy sites print nothing).
	 * The inline variables first, then the custom CSS (stored cleaned; cleaned
	 * again here, and any "<" left is dropped, so it can never close the block).
	 */
	public static function style_html( array $a, array $theme_palette = array() ) {
		$out = '';
		$css = CMP_Appearance::inline_css( $a, $theme_palette );
		if ( '' !== $css ) {
			$out .= '<style id="cmp-appearance-css">' . str_replace( '<', '', $css ) . "</style>\n";
		}
		$custom = isset( $a['custom_css'] ) && is_string( $a['custom_css'] ) ? $a['custom_css'] : '';
		if ( '' !== trim( $custom ) ) {
			$clean  = CMP_Appearance::clean_custom_css( $custom );
			$custom = trim( str_replace( '<', '', $clean['css'] ) );
			if ( '' !== $custom ) {
				$out .= '<style id="cmp-custom-css">' . $custom . "</style>\n";
			}
		}
		return $out;
	}

	/**
	 * Pure: the banner container classes. With a stored appearance, exactly
	 * CMP_Appearance::container_classes(). A legacy site (no appearance yet)
	 * keeps today's classes first (cmp--<preset>, and in Pro cmp--pos-<position>,
	 * 'modal-center' when unset), then the new ones (which carry no rule for
	 * the migrated defaults), so its HTML is a superset of today's.
	 *
	 * @return string[]
	 */
	public static function classes( array $settings, array $a, $mode ) {
		$new = CMP_Appearance::container_classes( $a, $mode );
		if ( isset( $settings['appearance'] ) && is_array( $settings['appearance'] ) ) {
			return $new;
		}
		$old    = array();
		$preset = isset( $settings['preset'] ) ? (string) $settings['preset'] : 'neutral';
		$pos    = isset( $settings['position'] ) ? (string) $settings['position'] : 'modal-center';
		if ( preg_match( '/^[a-z0-9-]+$/', $preset ) ) {
			$old[] = 'cmp--' . $preset;
		}
		if ( 'light' !== $mode && preg_match( '/^[a-z0-9-]+$/', $pos ) ) {
			$old[] = 'cmp--pos-' . $pos;
		}
		return array_values( array_unique( array_merge( $old, $new ) ) );
	}

	/**
	 * Pure: the reopen button's shape: 'none' | 'icon' | 'text' | 'both'. The
	 * Settings checkbox stays the on/off switch (as today); the appearance
	 * picks the shape ('none' there hides it too).
	 */
	public static function reopen_shape( array $settings, array $a ) {
		if ( empty( $settings['reopen_button'] ) ) {
			return 'none';
		}
		$shape = isset( $a['reopen'] ) ? $a['reopen'] : 'icon';
		return in_array( $shape, array( 'none', 'icon', 'text', 'both' ), true ) ? $shape : 'icon';
	}

	/** Pure: the floating reopen button ('' for none). The icon alone is today's markup. */
	public static function reopen_html( $shape, $label ) {
		if ( 'icon' === $shape ) {
			return '<button type="button" class="cmp-reopen" data-cmp-reopen hidden aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '">' . self::REOPEN_ICON . '</button>';
		}
		if ( 'text' !== $shape && 'both' !== $shape ) {
			return '';
		}
		return '<button type="button" class="cmp-reopen cmp-reopen--text" data-cmp-reopen hidden>'
			. ( 'both' === $shape ? self::REOPEN_ICON : '' )
			. '<span class="cmp-reopen__label">' . esc_html( $label ) . '</span></button>';
	}

	/** Pure: honour Global Privacy Control? Not stored (saved before the option) = on, as before. */
	public static function respect_gpc( array $settings ) {
		return ! array_key_exists( 'respect_gpc', $settings ) || ! empty( $settings['respect_gpc'] );
	}

	/** Pure: CMP_DATA.appearance (what the JS needs: delay, theme, its selector, no auto-open). */
	public static function appearance_data( array $a, $excluded ) {
		$d = array(
			'delay'      => isset( $a['delay'] ) ? (float) $a['delay'] : 0.0,
			'theme'      => isset( $a['theme'] ) ? (string) $a['theme'] : 'light',
			'noAutoOpen' => (bool) $excluded,
		);
		if ( 'site' === $d['theme'] ) {
			$d['darkSelector'] = isset( $a['dark_selector'] ) ? (string) $a['dark_selector'] : CMP_Appearance::DARK_SELECTOR;
		}
		return $d;
	}

	/**
	 * The normalized appearance of these settings, computed once per request
	 * (from_settings re-cleans the custom CSS, up to 20 KB).
	 */
	public static function appearance( array $settings ) {
		static $cache = array();
		$key = md5( serialize( $settings ) );
		if ( ! isset( $cache[ $key ] ) ) {
			$cache[ $key ] = CMP_Appearance::from_settings( $settings );
		}
		return $cache[ $key ];
	}

	/**
	 * The settings the banner renders with: the stored ones, or in a valid
	 * appearance preview the stored ones with the admin's unsaved appearance.
	 */
	public static function settings() {
		$s = CMP_Consent::get_settings();
		$s = is_array( $s ) ? $s : array();
		return class_exists( 'CMP_Preview' ) ? CMP_Preview::settings( $s ) : $s;
	}

	/** No auto-open on this request: an excluded page, never in an isolated admin session (check, preview). */
	public static function excluded_now( array $a, $isolated ) {
		return ! $isolated && self::is_excluded_request( $a );
	}

	/** Is the current request on an excluded page ($a = the normalized appearance)? */
	public static function is_excluded_request( array $a ) {
		if ( empty( $a['exclude'] ) || empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}
		$uri  = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only compared, never printed.
		$path = wp_parse_url( (string) $uri, PHP_URL_PATH );
		$base = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return CMP_Appearance::excluded( $a['exclude'], is_string( $path ) ? $path : '', is_string( $base ) ? $base : '' );
	}

	/** WPML or Polylang active: the banner texts then go through CMP_I18n (today's path). */
	public static function multilingual() {
		return defined( 'ICL_SITEPRESS_VERSION' ) || function_exists( 'icl_t' ) || function_exists( 'pll__' ) || function_exists( 'pll_current_language' );
	}

	/**
	 * A banner text. With WPML / Polylang: today's path (the single stored
	 * text, translated by CMP_I18n). Otherwise the appearance text for $lang,
	 * else the old single text, else $default (CMP_Lang).
	 *
	 * @param bool|null $multilingual null = detect.
	 */
	public static function text( $key, array $settings, array $a, $lang, $default, $multilingual = null ) {
		$old    = isset( $settings['texts'] ) && is_array( $settings['texts'] ) ? $settings['texts'] : array();
		$stored = isset( $old[ $key ] ) && is_scalar( $old[ $key ] ) ? (string) $old[ $key ] : '';
		if ( null === $multilingual ? self::multilingual() : $multilingual ) {
			return CMP_I18n::text( 'banner_' . $key, $stored, $default );
		}
		$own = isset( $a['texts'][ $lang ][ $key ] ) ? (string) $a['texts'][ $lang ][ $key ] : '';
		return CMP_I18n::resolve( '' !== trim( $own ) ? $own : $stored, $default );
	}
}
