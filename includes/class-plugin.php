<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMP_Plugin {

	/** @var CMP_Plugin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	private function boot() {
		require_once CMP_PATH . 'includes/class-installer.php';
		require_once CMP_PATH . 'includes/class-consent.php';
		require_once CMP_PATH . 'includes/modules/class-banner.php';
		require_once CMP_PATH . 'includes/modules/class-audit.php';

		load_plugin_textdomain( 'consent-manager-pro', false, dirname( plugin_basename( CMP_PATH ) ) . '/languages' );

		new CMP_Banner();
		CMP_Audit::register();
		require_once CMP_PATH . 'includes/modules/class-legal.php';
		CMP_Legal::register();
		require_once CMP_PATH . 'includes/modules/class-i18n.php';
		CMP_I18n::register();
		if ( is_admin() ) {
			require_once CMP_PATH . 'includes/admin/class-admin.php';
			new CMP_Admin();
		}
	}

	public static function activate() {
		require_once CMP_PATH . 'includes/class-installer.php';
		CMP_Installer::install();
	}
}
