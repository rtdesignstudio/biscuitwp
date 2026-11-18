<?php
if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) {
	exit; // The CLI exception keeps the class loadable by tests/run.php; map() and active() use no WP.
}

/**
 * Optional bridge to the WP Consent API plugin (wp-consent-api).
 * Active only when that plugin is installed; otherwise nothing is registered.
 */
class CMP_Consent_API {

	/** CMP category slug => WP Consent API categories. Other slugs are not sent. */
	private static $map = array(
		'necessary'   => array( 'functional' ),
		'statistics'  => array( 'statistics', 'statistics-anonymous' ),
		'marketing'   => array( 'marketing' ),
		'preferences' => array( 'preferences' ),
	);

	/** Map the given CMP category slugs to WP Consent API categories; unknown slugs are dropped. */
	public static function map( array $category_slugs ) {
		$out = array();
		foreach ( $category_slugs as $slug ) {
			if ( is_string( $slug ) && isset( self::$map[ $slug ] ) ) {
				$out[ $slug ] = self::$map[ $slug ];
			}
		}
		return $out;
	}

	/** True when the WP Consent API plugin is loaded. */
	public static function active() {
		return function_exists( 'wp_set_consent' );
	}

	/** Hook in on plugins_loaded (after WP Consent API, which boots at priority 9). */
	public static function register() {
		add_action( 'plugins_loaded', array( __CLASS__, 'declare_support' ), 20 );
	}

	public static function declare_support() {
		if ( ! self::active() ) {
			return;
		}
		$basename = plugin_basename( CMP_PATH . 'consent-manager-pro.php' );
		add_filter( "wp_consent_api_registered_{$basename}", '__return_true' );
		add_filter( 'wp_get_consent_type', array( __CLASS__, 'consent_type' ) );
	}

	public static function consent_type() {
		return 'optin';
	}
}
