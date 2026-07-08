<?php
/**
 * Plugin Name: Consent Manager Pro
 * Description: Cookie consent, legal pages & GDPR audit trail. Theme-agnostic, no jQuery.
 * Version: 0.1.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: consent-manager-pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CMP_VERSION', '0.1.0' );
define( 'CMP_PATH', plugin_dir_path( __FILE__ ) );
define( 'CMP_URL', plugin_dir_url( __FILE__ ) );

require_once CMP_PATH . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'CMP_Plugin', 'activate' ) );

add_action( 'plugins_loaded', array( 'CMP_Plugin', 'instance' ) );
