<?php
/**
 * Uninstall. Temporary data is always removed; settings, categories and the
 * consent log (legal evidence) only when the admin turned on
 * "Șterge toate datele la dezinstalare". See CMP_Uninstaller.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-uninstaller.php';
CMP_Uninstaller::run();
