<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMP_Plugin {

	/** The active path on sites installed before 1.1.0 (not yet moved to biscuitwp/). */
	const LEGACY_BASENAME = 'consent-manager-pro/consent-manager-pro.php';

	/** @var CMP_Plugin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Absolute path of the entry file WordPress loaded: biscuitwp.php, or the
	 * legacy loader consent-manager-pro.php on a site not yet moved (CMP_ENTRY,
	 * defined by that file). Falls back to biscuitwp.php outside WordPress.
	 */
	public static function main_file() {
		if ( defined( 'CMP_ENTRY' ) ) {
			return CMP_ENTRY;
		}
		return CMP_PATH . 'biscuitwp.php';
	}

	/** The active plugin basename: biscuitwp/biscuitwp.php or consent-manager-pro/consent-manager-pro.php. */
	public static function basename() {
		return plugin_basename( self::main_file() );
	}

	/**
	 * Plugins screen: every folder of ours holds two entry files with the same
	 * header (biscuitwp.php and consent-manager-pro.php), so WordPress lists both.
	 * Only one row per folder is kept: the active entry file (the loaded one when
	 * both are active). When neither is active, the row is the path the options
	 * already name: consent-manager-pro/consent-manager-pro.php in an unmoved
	 * consent-manager-pro/ folder holding both files (the historical active path,
	 * kept in auto_update_plugins and uninstall_plugins), else <folder>/biscuitwp.php.
	 * A folder holding only an inactive consent-manager-pro.php (the stub the folder
	 * move leaves while it runs) shows nothing. Only rows with our text domain are
	 * touched; the kept row stays in place. Pure.
	 *
	 * Folders holding exactly the folder-move stub ($stub_folders) count as the same
	 * plugin as biscuitwp/: one row for both together (the active one, else
	 * biscuitwp/biscuitwp.php), so the stub never shows as a second plugin and the
	 * real folder is never offered as an inactive row while the stub is active.
	 *
	 * @param array  $plugins      get_plugins() shape: basename => header data.
	 * @param array  $active       Active basenames (site and network-wide).
	 * @param string $loaded       The entry file this request loaded (CMP_Plugin::basename()).
	 * @param array  $stub_folders Folders recognised as our stub (CMP_Migrate_Folder::is_our_stub_dir()).
	 * @return array
	 */
	public static function fix_plugin_rows( array $plugins, array $active, $loaded = '', array $stub_folders = array() ) {
		$ours = array(); // folder => array( basename, ... )
		foreach ( $plugins as $key => $data ) {
			$base = basename( (string) $key );
			if ( false === strpos( (string) $key, '/' ) || ! in_array( $base, array( 'biscuitwp.php', basename( self::LEGACY_BASENAME ) ), true ) ) {
				continue;
			}
			if ( ! is_array( $data ) || ! isset( $data['TextDomain'] ) || 'biscuitwp' !== $data['TextDomain'] ) {
				continue;
			}
			$group            = in_array( dirname( $key ), $stub_folders, true ) ? 'biscuitwp' : dirname( $key );
			$ours[ $group ][] = $key;
		}
		$hide = array();
		foreach ( $ours as $folder => $keys ) {
			$on = array_values( array_intersect( $keys, $active ) );
			if ( count( $on ) > 1 ) {
				$keep = in_array( $loaded, $on, true ) ? $loaded : $folder . '/biscuitwp.php';
			} elseif ( 1 === count( $on ) ) {
				$keep = $on[0];
			} elseif ( dirname( self::LEGACY_BASENAME ) === $folder && in_array( self::LEGACY_BASENAME, $keys, true ) && in_array( $folder . '/biscuitwp.php', $keys, true ) ) {
				$keep = self::LEGACY_BASENAME;
			} else {
				$keep = $folder . '/biscuitwp.php';
			}
			foreach ( $keys as $key ) {
				if ( $key !== $keep ) {
					$hide[ $key ] = true;
				}
			}
		}
		return $hide ? array_diff_key( $plugins, $hide ) : $plugins;
	}

	/** all_plugins filter (admin, site and network): see fix_plugin_rows(). */
	public static function filter_plugin_rows( $plugins ) {
		if ( ! is_array( $plugins ) ) {
			return $plugins;
		}
		$active = (array) get_option( 'active_plugins', array() );
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$stubs = array();
		if ( defined( 'WP_PLUGIN_DIR' ) && class_exists( 'CMP_Migrate_Folder' ) ) {
			foreach ( CMP_Migrate_Folder::stub_candidates() as $cmp_dir ) {
				if ( CMP_Migrate_Folder::is_our_stub_dir( WP_PLUGIN_DIR . '/' . $cmp_dir ) ) {
					$stubs[] = $cmp_dir;
				}
			}
		}
		return self::fix_plugin_rows( $plugins, $active, self::basename(), $stubs );
	}

	private function boot() {
		require_once CMP_PATH . 'includes/class-installer.php';
		require_once CMP_PATH . 'includes/class-assets.php';
		require_once CMP_PATH . 'includes/class-consent.php';
		require_once CMP_PATH . 'includes/functions.php';
		require_once CMP_PATH . 'includes/class-contrast.php';
		require_once CMP_PATH . 'includes/class-appearance.php';
		require_once CMP_PATH . 'includes/class-sanitizer.php';
		require_once CMP_PATH . 'includes/class-appearance-io.php';
		require_once CMP_PATH . 'includes/class-services.php';
		require_once CMP_PATH . 'includes/class-known-plugins.php';
		require_once CMP_PATH . 'includes/modules/class-banner.php';
		require_once CMP_PATH . 'includes/modules/class-audit.php';

		// <plugin folder>/languages, relative to WP_PLUGIN_DIR. plugin_basename() of the loaded
		// entry file maps a symlinked plugin folder back to its name under WP_PLUGIN_DIR.
		load_plugin_textdomain( 'biscuitwp', false, dirname( CMP_Plugin::basename() ) . '/languages' );

		new CMP_Banner();
		CMP_Audit::register();
		require_once CMP_PATH . 'includes/modules/class-legal.php';
		CMP_Legal::register();
		require_once CMP_PATH . 'includes/modules/class-i18n.php';
		CMP_I18n::register();
		require_once CMP_PATH . 'includes/modules/class-updater.php';
		CMP_Updater::register();
		require_once CMP_PATH . 'includes/modules/class-thumbs.php';
		CMP_Thumbs::register();
		require_once CMP_PATH . 'includes/modules/class-maps-gate.php';
		require_once CMP_PATH . 'includes/modules/class-blocker.php';
		CMP_Blocker::register();
		require_once CMP_PATH . 'includes/modules/class-scanner.php';
		CMP_Scanner::register();
		require_once CMP_PATH . 'includes/modules/class-alerts.php';
		CMP_Alerts::register();
		// B6 "Vezi pe site": the appearance preview (inert without a valid ?cmp_preview nonce).
		require_once CMP_PATH . 'includes/modules/class-preview.php';
		CMP_Preview::register();
		require_once CMP_PATH . 'includes/modules/class-fonts.php';
		CMP_Fonts::register();
		require_once CMP_PATH . 'includes/modules/class-privacy-tweaks.php';
		CMP_Privacy_Tweaks::register();
		require_once CMP_PATH . 'includes/modules/class-lang.php';
		require_once CMP_PATH . 'includes/modules/class-consent-api.php';
		CMP_Consent_API::register();
		require_once CMP_PATH . 'includes/modules/class-bridge-pys.php';
		CMP_Bridge_PYS::register();
		require_once CMP_PATH . 'includes/modules/class-bridge-wc.php';
		CMP_Bridge_WC::register();
		if ( is_admin() ) {
			require_once CMP_PATH . 'includes/admin/class-admin.php';
			new CMP_Admin();
		}
		// Two entry files with the same header per folder: one Plugins row, the active one.
		if ( is_admin() ) {
			add_filter( 'all_plugins', array( __CLASS__, 'filter_plugin_rows' ) );
		}
		// Folder move, on demand only (spec: varianta B): the move screen and the
		// Plugins row link in admin; the stub left in the old folder is removed later
		// by a load through biscuitwp/ (admin or cron).
		if ( is_admin() || wp_doing_cron() ) {
			require_once CMP_PATH . 'includes/class-migrate-folder.php';
			if ( is_admin() ) {
				CMP_Migrate_Folder::register_admin();
			}
			if ( CMP_Migrate_Folder::stub_candidate_exists() && ! CMP_Migrate_Folder::runs_from_old_folder() ) {
				CMP_Migrate_Folder::register_cleanup();
			}
		}

		// Multisite: new sites get installed, deleted sites lose the consent log table.
		add_action( 'wp_initialize_site', array( __CLASS__, 'on_new_site' ), 20, 1 );
		add_filter( 'wpmu_drop_tables', array( __CLASS__, 'drop_tables' ), 10, 2 );

		// Per-request caches of the services list follow their options.
		foreach ( array( 'cmp_services', 'cmp_categories', 'cmp_settings' ) as $opt ) { // cmp_settings: the measurement mode
			add_action( 'add_option_' . $opt, array( 'CMP_Services', 'flush' ) );
			add_action( 'update_option_' . $opt, array( 'CMP_Services', 'flush' ) );
			add_action( 'delete_option_' . $opt, array( 'CMP_Services', 'flush' ) );
		}

		// Plugin updates do NOT fire the activation hook, so refresh the schema
		// (create the log table / migrate columns) whenever the version changes.
		// Runs on every load but the version gate makes dbDelta fire only once.
		self::maybe_upgrade();
		// The schema has its own revision (cmp_db_schema), checked even when
		// CMP_VERSION did not change, so a missing column is always added.
		CMP_Installer::maybe_upgrade_schema();
	}

	/** Run the installer (idempotent dbDelta + add_option) once per version change. */
	public static function maybe_upgrade() {
		$old = get_option( 'cmp_db_version' );
		if ( $old === CMP_VERSION ) {
			return;
		}
		require_once CMP_PATH . 'includes/class-installer.php';
		CMP_Installer::upgrade_schema();
		// 0.3.4: the floating reopen button is off by default (a footer link replaces
		// it). It used to default to on, so switch it off once on existing installs.
		if ( false === $old || version_compare( $old, '0.3.4', '<' ) ) {
			$settings = get_option( 'cmp_settings', array() );
			if ( is_array( $settings ) && ! empty( $settings['reopen_button'] ) ) {
				$settings['reopen_button'] = false;
				update_option( 'cmp_settings', $settings );
			}
		}
		// 0.4.0: blocking trackers until consent is on by default (Consent Mode
		// "basic": no Google/Meta request at all before Accept). Turn it on once
		// on existing installs, where it used to default to off.
		if ( false === $old || version_compare( $old, '0.4.0', '<' ) ) {
			$settings = get_option( 'cmp_settings', array() );
			if ( is_array( $settings ) && empty( $settings['block_scripts'] ) ) {
				$settings['block_scripts'] = true;
				update_option( 'cmp_settings', $settings );
			}
		}
		// 0.4.1: sites no longer use a GitHub token (updates now come from the
		// signed manifest on biscuitwp.rt.md), so drop any token still stored on
		// the site (and the unused "layout" setting).
		if ( false === $old || version_compare( $old, '0.4.1', '<' ) ) {
			$settings = get_option( 'cmp_settings', array() );
			if ( is_array( $settings ) && ( array_key_exists( 'github_token', $settings ) || array_key_exists( 'layout', $settings ) ) ) {
				unset( $settings['github_token'], $settings['layout'] );
				update_option( 'cmp_settings', $settings );
			}
		}
		// 0.4.1: consent lasts at most 13 months; cap longer stored durations.
		if ( false === $old || version_compare( $old, '0.4.1', '<' ) ) {
			$settings = get_option( 'cmp_settings', array() );
			if ( is_array( $settings ) && isset( $settings['cookie_days'] ) ) {
				$capped = CMP_Sanitizer::cap_cookie_days( $settings['cookie_days'] );
				if ( $capped !== (int) $settings['cookie_days'] ) {
					$settings['cookie_days'] = $capped;
					update_option( 'cmp_settings', $settings );
				}
			}
		}
		// B1: services that used to run freely now wait for consent. On existing
		// installs, flag a one-time admin note (never on fresh installs).
		if ( false !== $old && version_compare( $old, '0.5.0', '<' ) && false === get_option( 'cmp_services_seen', false ) ) {
			update_option( 'cmp_services_notice', 1 );
		}
		// Lot A: what this update starts blocking (AdSense): the same note, shown only once
		// the service is found on the site. A general note still open already covers it.
		$added  = CMP_Services::added_to_blocking( $old );
		$notice = $added ? get_option( 'cmp_services_notice', false ) : false;
		if ( $added && ( ! $notice || is_array( $notice ) ) ) {
			update_option( 'cmp_services_notice', array_values( array_unique( array_merge( $notice ? $notice : array(), $added ) ) ) );
		}
		// Maps gate: the Maps API loader of a theme still loads before consent after the
		// update (maps_gate is absent = off). A one-time note on existing installs tells
		// the owner, once the scan finds it (CMP_Services_Page::maps_notice()); never on
		// a fresh install, where the gate is on from the start.
		if ( CMP_Services::maps_notice_due( $old ) ) {
			$settings = get_option( 'cmp_settings', array() );
			if ( ! is_array( $settings ) || ! array_key_exists( 'maps_gate', $settings ) ) {
				update_option( 'cmp_maps_notice', 1 );
			}
		}
		// B4: the legal templates changed (Moldova texts, RO/RU/EN): flag a one-time
		// note on "Pagini legale" for existing installs only.
		if ( false !== $old && version_compare( $old, '0.5.0', '<' ) ) {
			update_option( 'cmp_templates_notice', 1 );
			delete_option( 'cmp_templates_regenerated' ); // documents regenerated since this update
		}
		// B4: the history is read on every front-end page: autoload it. update_option()
		// changes autoload only when the value changes, so flip an existing one
		// explicitly (WP 6.4+; on older WP the next changed write does it).
		if ( function_exists( 'wp_set_option_autoload' ) && false !== get_option( 'cmp_services_history', false ) ) {
			wp_set_option_autoload( 'cmp_services_history', true );
		}
		// B4: seed the cumulative services list from the last scan, so the first
		// admin scan after the update does not re-prompt every visitor at once.
		if ( false === get_option( 'cmp_services_history', false ) ) {
			$seed = CMP_Services::history_or_found( false, get_option( 'cmp_services_found', false ) );
			if ( null !== $seed ) {
				update_option( 'cmp_services_history', $seed, true );
			}
		}
		// B3: the multi-page scan refreshes itself once a day (first scan: first admin visit).
		require_once CMP_PATH . 'includes/modules/class-scanner.php';
		CMP_Scanner::schedule_daily();
		update_option( 'cmp_db_version', CMP_VERSION );
	}

	/**
	 * Asset version = file mtime (busts cache on every change), CMP_VERSION as fallback.
	 *
	 * @param string $relative Path relative to the plugin root, e.g. "assets/css/banner.css".
	 * @return string
	 */
	public static function asset_ver( $relative ) {
		$mtime = @filemtime( CMP_PATH . ltrim( $relative, '/' ) );
		return $mtime ? (string) $mtime : CMP_VERSION;
	}

	/** Activation. Network-wide on multisite: install on every existing site. */
	public static function activate( $network_wide = false ) {
		if ( $network_wide && function_exists( 'is_multisite' ) && is_multisite() ) {
			foreach ( (array) get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $id ) {
				switch_to_blog( (int) $id );
				static::install_site();
				restore_current_blog();
			}
			return;
		}
		static::install_site();
	}

	/** Per-site install: schema, daily scan, version stamp. */
	public static function install_site() {
		require_once CMP_PATH . 'includes/class-installer.php';
		CMP_Installer::upgrade_schema();
		require_once CMP_PATH . 'includes/modules/class-scanner.php';
		CMP_Scanner::schedule_daily();
		update_option( 'cmp_db_version', CMP_VERSION );
	}

	/** Deactivation: stop the cron jobs only (settings and data stay). Every site when network-wide. */
	public static function deactivate( $network_wide = false ) {
		if ( $network_wide && function_exists( 'is_multisite' ) && is_multisite() ) {
			foreach ( (array) get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $id ) {
				switch_to_blog( (int) $id );
				static::deactivate_site();
				restore_current_blog();
			}
			return;
		}
		static::deactivate_site();
	}

	/** Stop this site's cron jobs. */
	public static function deactivate_site() {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( 'cmp_fetch_thumbs' );
			wp_clear_scheduled_hook( 'cmp_prune_consent_log' );
		}
		require_once CMP_PATH . 'includes/modules/class-scanner.php';
		CMP_Scanner::unschedule();
	}

	/** A site created while the plugin is network-active gets installed right away. */
	public static function on_new_site( $new_site ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'is_plugin_active_for_network' ) || ! is_plugin_active_for_network( CMP_Plugin::basename() ) ) {
			return;
		}
		switch_to_blog( (int) $new_site->blog_id );
		static::install_site();
		restore_current_blog();
	}

	/** Deleting a site also drops its consent log table. */
	public static function drop_tables( $tables, $site_id = 0 ) {
		global $wpdb;
		$tables   = (array) $tables;
		$tables[] = $wpdb->get_blog_prefix( (int) $site_id ) . 'cmp_consent_log';
		return $tables;
	}
}
