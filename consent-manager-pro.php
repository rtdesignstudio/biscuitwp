<?php
/**
 * Plugin Name: Consent Manager Pro
 * Plugin URI: https://consent.rt.md/
 * Description: Free and open-source GDPR cookie consent: a consent banner, the legal pages (Privacy Policy, Terms & Conditions, Cookie Policy) and a consent audit log. Multilingual (RO, RU, EN).
 * Version: 1.0.0
 * Requires PHP: 7.4
 * Author: RT.md
 * Author URI: https://rt.md
 * License: GPL-2.0-or-later
 * Text Domain: consent-manager-pro
 * Update URI: https://consent.rt.md/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Release step: lot B (services library) must ship as >= 0.5.0, otherwise the
// one-time "services now wait for consent" notice never fires (see
// CMP_Plugin::maybe_upgrade). The DB schema does not depend on this value
// (CMP_Installer::SCHEMA / cmp_db_schema).
define( 'CMP_VERSION', '1.0.0' );
define( 'CMP_PATH', plugin_dir_path( __FILE__ ) );
define( 'CMP_URL', plugin_dir_url( __FILE__ ) );

require_once CMP_PATH . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'CMP_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CMP_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'CMP_Plugin', 'instance' ) );
