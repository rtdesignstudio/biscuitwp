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

		require_once CMP_PATH . 'includes/admin/class-audit-page.php';
		add_action( 'admin_post_cmp_export_log', array( 'CMP_Audit_Page', 'export' ) );
		add_action( 'admin_post_cmp_purge_log', array( 'CMP_Audit_Page', 'purge' ) );

		require_once CMP_PATH . 'includes/admin/class-legal-page.php';
		add_action( 'admin_post_cmp_generate_legal', array( 'CMP_Legal_Page', 'generate' ) );

		add_filter( 'plugin_action_links_' . plugin_basename( CMP_PATH . 'consent-manager-pro.php' ), array( $this, 'action_links' ) );
	}

	/**
	 * Add a "Setări" link to the plugin row on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function action_links( $links ) {
		$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Setări', 'consent-manager-pro' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
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
		add_submenu_page( self::SLUG, __( 'Registru consimțăminte', 'consent-manager-pro' ), __( 'Registru consimțăminte', 'consent-manager-pro' ), 'manage_options', 'cmp-audit', array( 'CMP_Audit_Page', 'render' ) );
		add_submenu_page( self::SLUG, __( 'Pagini legale', 'consent-manager-pro' ), __( 'Pagini legale', 'consent-manager-pro' ), 'manage_options', 'cmp-legal', array( 'CMP_Legal_Page', 'render' ) );
	}

	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'cmp-' ) ) {
			return;
		}
		wp_enqueue_style( 'cmp-admin', CMP_URL . 'assets/css/admin.css', array(), CMP_VERSION );
		wp_enqueue_script( 'cmp-admin', CMP_URL . 'assets/js/admin.js', array(), CMP_VERSION, true );
	}
}
