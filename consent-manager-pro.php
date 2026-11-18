<?php
/**
 * Plugin Name: Consent Manager Pro
 * Plugin URI: https://github.com/rtgit003/consent-manager-pro
 * Description: Displays a GDPR-compliant cookie consent banner, generates the legal pages (Privacy Policy, Terms & Conditions, Cookie Policy) and keeps a consent audit log. Quick to configure, multilingual-ready.
 * Version: 0.4.2
 * Requires PHP: 7.4
 * Author: RT.md
 * Author URI: https://rt.md
 * License: GPL-2.0-or-later
 * Text Domain: consent-manager-pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Release step: lot B (services library) must ship as >= 0.5.0, otherwise the
// one-time "services now wait for consent" notice never fires (see
// CMP_Plugin::maybe_upgrade). The DB schema does not depend on this value
// (CMP_Installer::SCHEMA / cmp_db_schema).
define( 'CMP_VERSION', '0.4.2' );
define( 'CMP_PATH', plugin_dir_path( __FILE__ ) );
define( 'CMP_URL', plugin_dir_url( __FILE__ ) );

require_once CMP_PATH . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'CMP_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CMP_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'CMP_Plugin', 'instance' ) );
