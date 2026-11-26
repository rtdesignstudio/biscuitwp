<?php
/**
 * Plugin Name: BiscuitWP
 * Plugin URI: https://biscuitwp.rt.md/
 * Description: Free and open-source GDPR cookie consent: a consent banner, the legal pages (Privacy Policy, Terms & Conditions, Cookie Policy) and a consent audit log. Multilingual (RO, RU, EN).
 * Version: 1.2.1
 * Requires PHP: 7.4
 * Author: RT.md
 * Author URI: https://rt.md
 * License: GPL-2.0-or-later
 * Text Domain: biscuitwp
 * Domain Path: /languages
 * Update URI: https://biscuitwp.rt.md/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Already loaded in this request through another entry file (the legacy loader
// require_once's this very file, so this only triggers on a second, distinct
// include): the first load wins.
if ( defined( 'CMP_VERSION' ) ) {
	return;
}

// Release step: lot B (services library) must ship as >= 0.5.0, otherwise the
// one-time "services now wait for consent" notice never fires (see
// CMP_Plugin::maybe_upgrade). The DB schema does not depend on this value
// (CMP_Installer::SCHEMA / cmp_db_schema).
define( 'CMP_VERSION', '1.2.1' );

// The entry file WordPress actually loaded: this file, or the legacy loader
// consent-manager-pro.php, which defines it first (CMP_Plugin::main_file()).
if ( ! defined( 'CMP_ENTRY' ) ) {
	define( 'CMP_ENTRY', __FILE__ );
}
define( 'CMP_PATH', plugin_dir_path( __FILE__ ) );
define( 'CMP_URL', plugin_dir_url( __FILE__ ) );

require_once CMP_PATH . 'includes/class-plugin.php';

// On the loaded entry file, so the hooks fire for whichever path is active
// (biscuitwp/biscuitwp.php or, not yet moved, consent-manager-pro/consent-manager-pro.php).
register_activation_hook( CMP_ENTRY, array( 'CMP_Plugin', 'activate' ) );
register_deactivation_hook( CMP_ENTRY, array( 'CMP_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'CMP_Plugin', 'instance' ) );
