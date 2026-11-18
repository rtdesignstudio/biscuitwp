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

		require_once CMP_PATH . 'includes/admin/class-services-page.php';
		add_action( 'admin_post_cmp_save_services', array( 'CMP_Services_Page', 'save' ) );
		add_action( 'admin_post_cmp_rescan_services', array( 'CMP_Services_Page', 'rescan' ) );
		add_action( 'admin_post_cmp_reset_seen', array( 'CMP_Services_Page', 'reset_seen' ) );
		add_action( 'admin_post_cmp_dismiss_services_notice', array( 'CMP_Services_Page', 'dismiss' ) );
		add_action( 'admin_notices', array( 'CMP_Services_Page', 'update_notice' ) );
		add_action( 'admin_notices', array( $this, 'library_notice' ) );

		require_once CMP_PATH . 'includes/admin/class-audit-page.php';
		add_action( 'admin_post_cmp_export_log', array( 'CMP_Audit_Page', 'export' ) );
		add_action( 'admin_post_cmp_purge_log', array( 'CMP_Audit_Page', 'purge' ) );

		require_once CMP_PATH . 'includes/admin/class-legal-page.php';
		add_action( 'admin_post_cmp_legal', array( 'CMP_Legal_Page', 'handle' ) );
		add_action( 'admin_post_cmp_dismiss_templates_notice', array( 'CMP_Legal_Page', 'dismiss_templates_notice' ) );
		add_action( 'admin_post_cmp_dismiss_operator_note', array( 'CMP_Legal_Page', 'dismiss_operator_note' ) );

		add_action( 'admin_post_cmp_check_updates', array( $this, 'check_updates' ) );
		add_action( 'admin_notices', array( $this, 'update_check_notice' ) );

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
		$check = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cmp_check_updates' ), 'cmp_check_updates' ) ) . '">' . esc_html__( 'Caută actualizări', 'consent-manager-pro' ) . '</a>';
		array_splice( $links, 1, 0, array( $check ) );
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
		add_submenu_page( self::SLUG, __( 'Servicii', 'consent-manager-pro' ), __( 'Servicii', 'consent-manager-pro' ), 'manage_options', 'cmp-services', array( 'CMP_Services_Page', 'render' ) );
		add_submenu_page( self::SLUG, __( 'Registru consimțăminte', 'consent-manager-pro' ), __( 'Registru consimțăminte', 'consent-manager-pro' ), 'manage_options', 'cmp-audit', array( 'CMP_Audit_Page', 'render' ) );
		add_submenu_page( self::SLUG, __( 'Pagini legale', 'consent-manager-pro' ), __( 'Pagini legale', 'consent-manager-pro' ), 'manage_options', 'cmp-legal', array( 'CMP_Legal_Page', 'render' ) );
	}

	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'cmp-' ) ) {
			return;
		}
		wp_enqueue_style( 'cmp-admin', CMP_URL . 'assets/css/admin.css', array(), CMP_Plugin::asset_ver( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'cmp-admin', CMP_URL . 'assets/js/admin.js', array(), CMP_Plugin::asset_ver( 'assets/js/admin.js' ), true );
	}

	/** Manual update check: clear caches, re-check, report via notice on the Plugins screen. */
	public function check_updates() {
		if ( ! current_user_can( 'update_plugins' ) || ! check_admin_referer( 'cmp_check_updates' ) ) {
			wp_die( esc_html__( 'Acțiune neautorizată.', 'consent-manager-pro' ) );
		}
		delete_transient( CMP_Updater::CACHE_KEY );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$updates   = get_site_transient( 'update_plugins' );
		$available = isset( $updates->response[ CMP_Updater::file() ] ) ? $updates->response[ CMP_Updater::file() ]->new_version : '';

		wp_safe_redirect( add_query_arg( array( 'cmp_up' => $available ? $available : 'latest' ), admin_url( 'plugins.php' ) ) );
		exit;
	}

	/**
	 * On every admin screen (manage_options only): the services library failed to
	 * load. Blocking then runs on the built-in rules for the 6 classic services.
	 * The "Servicii" screen lists the errors itself.
	 */
	public function library_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! CMP_Services::errors() ) {
			return;
		}
		if ( isset( $_GET['page'] ) && CMP_Services_Page::SLUG === $_GET['page'] ) {
			return;
		}
		$msg = CMP_Services::using_fallback()
			? __( 'Biblioteca de servicii (data/services.json) lipsește sau e coruptă. Blocăm doar Google Analytics, Tag Manager, Meta Pixel, YouTube, Vimeo și Google Maps până o repari (reinstalează pluginul).', 'consent-manager-pro' )
			: __( 'Biblioteca de servicii (data/services.json) are erori. Unele servicii pot să nu fie blocate corect.', 'consent-manager-pro' );
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Consent Manager Pro:', 'consent-manager-pro' ) . '</strong> '
			. esc_html( $msg )
			. ' <a href="' . esc_url( admin_url( 'admin.php?page=' . CMP_Services_Page::SLUG ) ) . '">' . esc_html__( 'Detalii în „Servicii”', 'consent-manager-pro' ) . '</a></p></div>';
	}

	public function update_check_notice() {
		if ( empty( $_GET['cmp_up'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$v = sanitize_text_field( wp_unslash( $_GET['cmp_up'] ) );
		if ( 'latest' === $v ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf(
				/* translators: %s — current plugin version */
				__( 'Consent Manager Pro: ai ultima versiune (%s).', 'consent-manager-pro' ),
				CMP_VERSION
			) ) . '</p></div>';
		} else {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( sprintf(
				/* translators: %s — new version number */
				__( 'Consent Manager Pro: este disponibilă versiunea %s — folosește „Update now" din lista de mai jos.', 'consent-manager-pro' ),
				$v
			) ) . '</p></div>';
		}
	}
}
