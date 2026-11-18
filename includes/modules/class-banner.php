<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMP_Banner {

	public function __construct() {
		add_action( 'wp_head', array( $this, 'print_consent_default' ), 1 );
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

	/** Emit gtag consent default BEFORE any tag manager fires. */
	public function print_consent_default() {
		$signals = CMP_Consent::build_default_signals( CMP_Consent::get_categories() );
		if ( empty( $signals ) ) {
			return;
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
		echo "<script data-cmp-keep>\n";
		echo "window.dataLayer = window.dataLayer || [];\n";
		echo "function gtag(){dataLayer.push(arguments);}\n";
		echo 'gtag("consent","default",' . wp_json_encode( $signals ) . ");\n";
		echo "</script>\n";
	}

	public function enqueue() {
		$cmp_settings = CMP_Consent::get_settings();

		wp_enqueue_style( 'cmp-banner', CMP_URL . 'assets/css/banner.css', array(), CMP_Plugin::asset_ver( 'assets/css/banner.css' ) );
		wp_enqueue_script( 'cmp-banner', CMP_URL . 'assets/js/banner.js', array(), CMP_Plugin::asset_ver( 'assets/js/banner.js' ), true );

		// Embed services by id, for the "always loaded" row in the preferences:
		// static, so it stays correct on cached pages.
		$svc_names = array();
		foreach ( CMP_Services::active() as $svc ) {
			if ( isset( $svc['type'] ) && 'embed' === $svc['type'] ) {
				$svc_names[ (string) $svc['id'] ] = (string) $svc['name'];
			}
		}

		$cmp_data = array(
			'categories' => CMP_Consent::get_categories(),
			'version'    => CMP_Consent::current_version(),
			'mode'       => isset( $cmp_settings['mode'] ) ? $cmp_settings['mode'] : 'pro',
			'cookieName' => CMP_Consent::COOKIE,
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'reopenButton' => ! empty( $cmp_settings['reopen_button'] ),
			'reloadOnDecision' => ! empty( $cmp_settings['reload_on_decision'] ),
			'svcNames'   => (object) $svc_names,
			'cookieDays' => CMP_Sanitizer::cap_cookie_days( isset( $cmp_settings['cookie_days'] ) ? $cmp_settings['cookie_days'] : 180 ),
		);
		// WP Consent API bridge: only present when that plugin is active.
		if ( CMP_Consent_API::active() ) {
			$cmp_data['wpConsent'] = (object) CMP_Consent_API::map( wp_list_pluck( (array) $cmp_data['categories'], 'slug' ) );
			// wp-consent-api.js is enqueued at PHP_INT_MAX - 100, so add the dependency after that.
			add_action( 'wp_enqueue_scripts', array( $this, 'depend_on_consent_api' ), PHP_INT_MAX - 90 );
		}
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
		$settings   = CMP_Consent::get_settings();
		$categories = CMP_Consent::get_categories();
		$preset     = isset( $settings['preset'] ) ? $settings['preset'] : 'neutral';
		$mode       = isset( $settings['mode'] ) ? $settings['mode'] : 'pro';

		if ( 'light' === $mode ) {
			include CMP_PATH . 'templates/banner-light.php';
			return;
		}
		$no_overlay = CMP_Consent::is_policy_page(
			is_singular() ? get_queried_object_id() : 0,
			is_array( $settings ) ? $settings : array(),
			(int) get_option( 'wp_page_for_privacy_policy' )
		);
		include CMP_PATH . 'templates/banner-bottom.php';

		$reopen = ! empty( $settings['reopen_button'] );
		if ( $reopen ) {
			$label = CMP_Lang::text( 'reopen' );
			echo '<button type="button" class="cmp-reopen" data-cmp-reopen hidden aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '">'
				. '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5Z"/><circle cx="9.5" cy="10.5" r="1"/><circle cx="14.5" cy="14.5" r="1"/><circle cx="9" cy="15" r="1"/></svg>'
				. '</button>';
		}
	}
}
