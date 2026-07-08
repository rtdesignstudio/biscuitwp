<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMP_Admin {

	const SLUG = 'cmp-settings';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );

		require_once CMP_PATH . 'includes/class-sanitizer.php';
		require_once CMP_PATH . 'includes/admin/class-settings-page.php';
		add_action( 'admin_post_cmp_save_settings', array( 'CMP_Settings_Page', 'save' ) );

		require_once CMP_PATH . 'includes/admin/class-categories-page.php';
		add_action( 'admin_post_cmp_save_categories', array( 'CMP_Categories_Page', 'save' ) );
	}

	public function menu() {
		add_menu_page(
			__( 'Consent Manager', 'consent-manager-pro' ),
			__( 'Consent Manager', 'consent-manager-pro' ),
			'manage_options',
			self::SLUG,
			array( 'CMP_Settings_Page', 'render' ),
			'dashicons-shield',
			80
		);
		add_submenu_page( self::SLUG, __( 'Setări', 'consent-manager-pro' ), __( 'Setări', 'consent-manager-pro' ), 'manage_options', self::SLUG, array( 'CMP_Settings_Page', 'render' ) );
		add_submenu_page( self::SLUG, __( 'Categorii', 'consent-manager-pro' ), __( 'Categorii', 'consent-manager-pro' ), 'manage_options', 'cmp-categories', array( 'CMP_Categories_Page', 'render' ) );
	}

	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'cmp-admin', CMP_URL . 'assets/css/admin.css', array(), CMP_VERSION );
		wp_enqueue_script( 'cmp-admin', CMP_URL . 'assets/js/admin.js', array(), CMP_VERSION, true );
	}
}
