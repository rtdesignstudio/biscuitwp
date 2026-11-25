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

		require_once CMP_PATH . 'includes/admin/class-appearance-page.php';
		add_action( 'admin_post_cmp_save_appearance', array( 'CMP_Appearance_Page', 'save' ) );
		add_action( 'admin_post_cmp_export_appearance', array( 'CMP_Appearance_Page', 'export' ) );
		add_action( 'cmp_appearance_actions', array( 'CMP_Appearance_Page', 'render_preview_button' ) );

		require_once CMP_PATH . 'includes/admin/class-categories-page.php';
		add_action( 'admin_post_cmp_save_categories', array( 'CMP_Categories_Page', 'save' ) );

		require_once CMP_PATH . 'includes/admin/class-services-page.php';
		add_action( 'admin_post_cmp_save_services', array( 'CMP_Services_Page', 'save' ) );
		add_action( 'admin_post_cmp_rescan_services', array( 'CMP_Services_Page', 'rescan' ) );
		add_action( 'admin_post_cmp_reset_seen', array( 'CMP_Services_Page', 'reset_seen' ) );
		add_action( 'admin_post_cmp_ignore_domain', array( 'CMP_Services_Page', 'ignore_domain' ) );
		add_action( 'admin_post_cmp_unignore_domain', array( 'CMP_Services_Page', 'unignore_domain' ) );
		add_action( 'admin_post_cmp_dismiss_services_notice', array( 'CMP_Services_Page', 'dismiss' ) );
		add_action( 'admin_notices', array( 'CMP_Services_Page', 'update_notice' ) );
		add_action( 'admin_notices', array( $this, 'library_notice' ) );

		require_once CMP_PATH . 'includes/admin/class-check-page.php';
		add_action( 'admin_post_cmp_scan_urls', array( 'CMP_Check_Page', 'save_urls' ) );
		add_action( 'wp_ajax_cmp_check_save', array( 'CMP_Check_Page', 'ajax_save' ) );

		require_once CMP_PATH . 'includes/admin/class-audit-page.php';
		add_action( 'admin_post_cmp_export_log', array( 'CMP_Audit_Page', 'export' ) );
		add_action( 'admin_post_cmp_purge_log', array( 'CMP_Audit_Page', 'purge' ) );

		require_once CMP_PATH . 'includes/admin/class-legal-page.php';
		require_once CMP_PATH . 'includes/admin/class-guide.php';
		add_action( 'admin_post_cmp_legal', array( 'CMP_Legal_Page', 'handle' ) );
		add_action( 'admin_post_cmp_dismiss_templates_notice', array( 'CMP_Legal_Page', 'dismiss_templates_notice' ) );
		add_action( 'admin_post_cmp_dismiss_operator_note', array( 'CMP_Legal_Page', 'dismiss_operator_note' ) );

		add_action( 'admin_post_cmp_check_updates', array( $this, 'check_updates' ) );
		add_action( 'admin_notices', array( $this, 'update_check_notice' ) );
		add_action( 'network_admin_notices', array( $this, 'update_check_notice' ) );
		add_action( 'admin_notices', array( $this, 'update_state_notices' ) );
		add_action( 'network_admin_notices', array( $this, 'update_state_notices' ) );

		add_filter( 'plugin_action_links_' . CMP_Plugin::basename(), array( $this, 'action_links' ) );
		add_filter( 'network_admin_plugin_action_links_' . CMP_Plugin::basename(), array( $this, 'network_action_links' ) );
	}

	/**
	 * Add a "Setări" link to the plugin row on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function action_links( $links ) {
		$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Setări', 'biscuitwp' ) . '</a>';
		array_unshift( $links, $settings );
		$check = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cmp_check_updates' ), 'cmp_check_updates' ) ) . '">' . esc_html__( 'Caută actualizări', 'biscuitwp' ) . '</a>';
		array_splice( $links, 1, 0, array( $check ) );
		return $links;
	}

	/**
	 * Network Plugins screen (multisite): only the update check. Updates apply
	 * to the whole network; the settings live on each site.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function network_action_links( $links ) {
		$check = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cmp_check_updates' ), 'cmp_check_updates' ) ) . '">' . esc_html__( 'Caută actualizări', 'biscuitwp' ) . '</a>';
		array_unshift( $links, $check );
		return $links;
	}

	public function menu() {
		add_menu_page(
			__( 'BiscuitWP', 'biscuitwp' ),
			__( 'BiscuitWP', 'biscuitwp' ),
			'manage_options',
			self::SLUG,
			array( 'CMP_Settings_Page', 'render' ),
			'dashicons-shield',
			80
		);
		add_submenu_page( self::SLUG, __( 'Setări', 'biscuitwp' ), __( 'Setări', 'biscuitwp' ), 'manage_options', self::SLUG, array( 'CMP_Settings_Page', 'render' ) );
		add_submenu_page( self::SLUG, __( 'Aspect', 'biscuitwp' ), __( 'Aspect', 'biscuitwp' ), 'manage_options', CMP_Appearance_Page::SLUG, array( 'CMP_Appearance_Page', 'render' ) );
		add_submenu_page( self::SLUG, __( 'Categorii', 'biscuitwp' ), __( 'Categorii', 'biscuitwp' ), 'manage_options', 'cmp-categories', array( 'CMP_Categories_Page', 'render' ) );
		add_submenu_page( self::SLUG, __( 'Servicii', 'biscuitwp' ), __( 'Servicii', 'biscuitwp' ), 'manage_options', 'cmp-services', array( 'CMP_Services_Page', 'render' ) );
		add_submenu_page( self::SLUG, __( 'Verificare', 'biscuitwp' ), __( 'Verificare', 'biscuitwp' ), 'manage_options', CMP_Check_Page::SLUG, array( 'CMP_Check_Page', 'render' ) );
		add_submenu_page( self::SLUG, __( 'Registru consimțăminte', 'biscuitwp' ), __( 'Registru consimțăminte', 'biscuitwp' ), 'manage_options', 'cmp-audit', array( 'CMP_Audit_Page', 'render' ) );
		add_submenu_page( self::SLUG, __( 'Pagini legale', 'biscuitwp' ), __( 'Pagini legale', 'biscuitwp' ), 'manage_options', 'cmp-legal', array( 'CMP_Legal_Page', 'render' ) );
		add_submenu_page( self::SLUG, __( 'Ghid', 'biscuitwp' ), __( 'Ghid', 'biscuitwp' ), 'manage_options', CMP_Guide::SLUG, array( 'CMP_Guide', 'render' ) );
	}

	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'cmp-' ) ) {
			return;
		}
		wp_enqueue_style( 'cmp-admin', CMP_URL . 'assets/css/admin.css', array(), CMP_Plugin::asset_ver( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'cmp-admin', CMP_URL . 'assets/js/admin.js', array(), CMP_Plugin::asset_ver( 'assets/js/admin.js' ), true );
		if ( false !== strpos( (string) $hook, CMP_Check_Page::SLUG ) && current_user_can( 'manage_options' ) ) {
			wp_enqueue_script( 'cmp-check', CMP_URL . 'assets/js/check.js', array(), CMP_Plugin::asset_ver( 'assets/js/check.js' ), true );
			wp_localize_script( 'cmp-check', 'CMP_CHECK', CMP_Check_Page::script_data() );
		}
		if ( false !== strpos( (string) $hook, CMP_Appearance_Page::SLUG ) ) {
			wp_enqueue_script( 'cmp-appearance', CMP_URL . 'assets/js/admin-appearance.js', array(), CMP_Plugin::asset_ver( 'assets/js/admin-appearance.js' ), true );
			wp_localize_script( 'cmp-appearance', 'CMP_APPEARANCE', CMP_Appearance_Page::script_data() );
		}
	}

	/** Manual update check: clear caches, re-check, report via notice on the Plugins screen. */
	public function check_updates() {
		if ( ! current_user_can( 'update_plugins' ) || ! check_admin_referer( 'cmp_check_updates' ) ) {
			wp_die( esc_html__( 'Acțiune neautorizată.', 'biscuitwp' ) );
		}
		delete_site_transient( CMP_Updater::CACHE_KEY );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		// The manifest was just fetched (and cached) by wp_update_plugins(): no extra request.
		$result = CMP_Updater::check_result( CMP_Updater::fetch_manifest() );

		// Back to the screen the check was started from (network or site Plugins).
		$referer = (string) wp_get_referer();
		$back    = ( is_multisite() && false !== strpos( $referer, '/wp-admin/network/' ) ) ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' );
		wp_safe_redirect( add_query_arg( array( 'cmp_up' => rawurlencode( $result ) ), $back ) );
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
			? __( 'Biblioteca de servicii (data/services.json) lipsește sau e coruptă. Blocăm doar Google Analytics, Tag Manager, Meta Pixel, YouTube, Vimeo și Google Maps până o repari (reinstalează pluginul).', 'biscuitwp' )
			: __( 'Biblioteca de servicii (data/services.json) are erori. Unele servicii pot să nu fie blocate corect.', 'biscuitwp' );
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'BiscuitWP:', 'biscuitwp' ) . '</strong> '
			. esc_html( $msg )
			. ' <a href="' . esc_url( admin_url( 'admin.php?page=' . CMP_Services_Page::SLUG ) ) . '">' . esc_html__( 'Detalii în „Servicii”', 'biscuitwp' ) . '</a></p></div>';
	}

	/**
	 * Update safety notices (update_plugins only): installed version revoked,
	 * manifest expired, no sodium. Which screen shows what: CMP_Updater::notices().
	 */
	public function update_state_notices() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$hook = isset( $GLOBALS['hook_suffix'] ) ? (string) $GLOBALS['hook_suffix'] : '';
		foreach ( CMP_Updater::notices( CMP_Updater::state(), $hook, self_admin_url( 'update-core.php' ) ) as $html ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in CMP_Updater::notices().
		}
	}

	public function update_check_notice() {
		if ( empty( $_GET['cmp_up'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$v = sanitize_text_field( wp_unslash( $_GET['cmp_up'] ) );
		if ( 'unavailable' === $v ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'BiscuitWP: nu am putut verifica update-urile acum. Încearcă din nou mai târziu.', 'biscuitwp' ) . '</p></div>';
		} elseif ( 'latest' === $v ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf(
				/* translators: %s — current plugin version */
				__( 'BiscuitWP: ai ultima versiune (%s).', 'biscuitwp' ),
				CMP_VERSION
			) ) . '</p></div>';
		} else {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( sprintf(
				/* translators: %s — new version number */
				__( 'BiscuitWP: este disponibilă versiunea %s — folosește „Update now" din lista de mai jos.', 'biscuitwp' ),
				$v
			) ) . '</p></div>';
		}
	}
}
