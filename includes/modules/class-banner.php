<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMP_Banner {

	public function __construct() {
		add_action( 'wp_head', array( $this, 'print_consent_default' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'render' ) );
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
		echo "<script>\n";
		echo "window.dataLayer = window.dataLayer || [];\n";
		echo "function gtag(){dataLayer.push(arguments);}\n";
		echo 'gtag("consent","default",' . wp_json_encode( $signals ) . ");\n";
		echo "</script>\n";
	}

	public function enqueue() {
		wp_enqueue_style( 'cmp-banner', CMP_URL . 'assets/css/banner.css', array(), CMP_VERSION );
		wp_enqueue_script( 'cmp-banner', CMP_URL . 'assets/js/banner.js', array(), CMP_VERSION, true );

		wp_localize_script(
			'cmp-banner',
			'CMP_DATA',
			array(
				'categories' => CMP_Consent::get_categories(),
				'version'    => CMP_Consent::current_version(),
				'cookieName' => CMP_Consent::COOKIE,
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'cmp_log_consent' ),
				'autoReject' => CMP_Consent::should_autoreject( array(
					'DNT'     => isset( $_SERVER['HTTP_DNT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_DNT'] ) ) : '',
					'Sec-GPC' => isset( $_SERVER['HTTP_SEC_GPC'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) ) : '',
				) ),
			)
		);
	}

	public function render() {
		$settings   = CMP_Consent::get_settings();
		$categories = CMP_Consent::get_categories();
		$preset     = isset( $settings['preset'] ) ? $settings['preset'] : 'neutral';
		include CMP_PATH . 'templates/banner-bottom.php';
	}
}
