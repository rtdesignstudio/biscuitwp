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

/*
 * Legacy entry file. Sites installed before BiscuitWP 1.1.0 have
 * consent-manager-pro/consent-manager-pro.php as the active plugin path, so
 * WordPress keeps loading this file until the folder move (CMP_Migrate_Folder)
 * makes biscuitwp/biscuitwp.php the active one. It carries exactly the same
 * plugin header as biscuitwp.php: WordPress deactivates an active plugin file
 * without one (validate_active_plugins() on the Plugins screen). The Plugins
 * list shows only the active one of the two (CMP_Plugin::fix_plugin_rows()).
 * Stays in the package for good.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Already loaded through biscuitwp.php in this request (e.g. multisite: one
// path network-active, the other site-active): do nothing.
if ( defined( 'CMP_VERSION' ) ) {
	return;
}

define( 'CMP_ENTRY', __FILE__ );

require_once __DIR__ . '/biscuitwp.php';
