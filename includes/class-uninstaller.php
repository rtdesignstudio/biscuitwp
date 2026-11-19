<?php
/**
 * Uninstall logic (testable). Called from uninstall.php.
 *
 * Always removed: temporary and derived data (services library, scan results,
 * thumbnails, localized fonts, transients, cron hooks).
 *
 * Kept by default: the consent log table (the legal evidence of every
 * visitor's choice), settings, categories and cmp_db_version. They are removed
 * only when the admin turned on "Șterge toate datele la dezinstalare"
 * (cmp_settings['delete_data_on_uninstall']). Legal pages and WPML/Polylang
 * strings are never touched.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_UNINSTALL_PLUGIN' ) && ! isset( $GLOBALS['cmp_tests'] ) ) {
	exit;
}

class CMP_Uninstaller {

	/**
	 * Delete the data of the current site.
	 *
	 * @param object $wpdb Database object ($wpdb or a stub).
	 * @param bool   $full Also remove settings, the consent log and legal post meta.
	 */
	public static function cleanup_site( $wpdb, $full ) {
		$always = array(
			// B1: services library.
			'cmp_services', 'cmp_services_found', 'cmp_services_notice', 'cmp_services_seen', 'cmp_services_history', 'cmp_thumb_queue', 'cmp_operator_note_seen', 'cmp_templates_notice', 'cmp_templates_regenerated', 'cmp_db_schema',
			// B3: multi-page scan, browser check report, compatibility copy.
			'cmp_scan_urls', 'cmp_scan_queue', 'cmp_scan_pages', 'cmp_scan_state', 'cmp_scan_aggregate', 'cmp_scan_lock', 'cmp_scan_saved_at', 'cmp_check_report',
			// Localized Google Fonts map.
			'cmp_fonts_map',
		);
		foreach ( $always as $option ) {
			delete_option( $option );
		}
		delete_transient( 'cmp_db_schema_retry' );
		delete_transient( 'cmp_tracker_scan' );
		delete_transient( 'cmp_tracker_scan_fail' );
		delete_transient( 'cmp_latest_release' );

		// All plugin transients (thumbnail locks, rate limits, release check, admin messages).
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_cmp_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_cmp_' ) . '%'
			)
		);

		wp_clear_scheduled_hook( 'cmp_fetch_thumbs' );
		wp_clear_scheduled_hook( 'cmp_scan_run' );
		wp_clear_scheduled_hook( 'cmp_scan_daily' );
		wp_clear_scheduled_hook( 'cmp_prune_consent_log' );

		// uploads/cmp-thumbs (YouTube thumbnails) and uploads/cmp-fonts (localized fonts): flat folders.
		$up = wp_upload_dir( null, false );
		if ( empty( $up['error'] ) && ! empty( $up['basedir'] ) ) {
			self::remove_upload_dir( trailingslashit( $up['basedir'] ) . 'cmp-thumbs' );
			self::remove_upload_dir( trailingslashit( $up['basedir'] ) . 'cmp-fonts' );
		}

		if ( ! $full ) {
			return;
		}

		foreach ( array( 'cmp_settings', 'cmp_categories', 'cmp_db_version' ) as $option ) {
			delete_option( $option );
		}
		// Any other cmp_* option, plus cmp_* transients. esc_like escapes the "_" wildcard.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( 'cmp_' ) . '%',
				$wpdb->esc_like( '_transient_cmp_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_cmp_' ) . '%'
			)
		);
		// The SQL sweep bypasses the object cache: drop stale cached copies.
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cmp_consent_log" );
		delete_post_meta_by_key( '_cmp_legal_type' );
		delete_post_meta_by_key( '_cmp_legal_mode' );
	}

	/** Whether the current site asked for full removal. */
	public static function wants_full() {
		$settings = get_option( 'cmp_settings', array() );
		return is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] );
	}

	/**
	 * Empty and remove a flat folder. Symbolic links are refused (not followed, not removed).
	 *
	 * @return bool True when the folder is gone.
	 */
	public static function remove_upload_dir( $dir ) {
		if ( is_link( $dir ) || ! is_dir( $dir ) ) {
			return false;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			$path = $dir . '/' . $name;
			if ( '.' !== $name && '..' !== $name && ( is_file( $path ) || is_link( $path ) ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		return @rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/** Entry point from uninstall.php. Multisite: every site by its own flag. */
	public static function run() {
		global $wpdb;
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
			foreach ( (array) $ids as $id ) {
				switch_to_blog( (int) $id );
				self::cleanup_site( $wpdb, self::wants_full() );
				restore_current_blog();
			}
			return;
		}
		self::cleanup_site( $wpdb, self::wants_full() );
	}
}
