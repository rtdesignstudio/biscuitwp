<?php
/**
 * Uninstall: removes what the services library (B1) adds.
 *
 * Kept on purpose: the consent log table (wp_cmp_consent_log) is the legal
 * evidence of every visitor's choice (GDPR / Law 195/2024 art. 7(1)) and must
 * survive its retention period; settings, categories and cmp_db_version stay
 * with it. Full removal of those is planned separately (B7.2).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'cmp_services', 'cmp_services_found', 'cmp_services_notice', 'cmp_services_seen', 'cmp_services_history', 'cmp_thumb_queue', 'cmp_operator_note_seen', 'cmp_templates_notice', 'cmp_templates_regenerated', 'cmp_db_schema' ) as $cmp_option ) {
	delete_option( $cmp_option );
}
delete_transient( 'cmp_db_schema_retry' );

// B3: the multi-page scan (queue, per-page results, aggregate, state) and its compatibility copy.
foreach ( array( 'cmp_scan_urls', 'cmp_scan_queue', 'cmp_scan_pages', 'cmp_scan_state', 'cmp_scan_aggregate', 'cmp_scan_lock', 'cmp_scan_saved_at' ) as $cmp_option ) {
	delete_option( $cmp_option );
}
delete_transient( 'cmp_tracker_scan' );
delete_transient( 'cmp_tracker_scan_fail' );

// Thumbnail transients (cmp_thumb_fail_<id>, cmp_thumb_lock_<id>).
global $wpdb;
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_cmp_thumb_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_cmp_thumb_' ) . '%'
	)
);

wp_clear_scheduled_hook( 'cmp_fetch_thumbs' );
wp_clear_scheduled_hook( 'cmp_scan_run' );
wp_clear_scheduled_hook( 'cmp_scan_daily' );

// Local YouTube thumbnails: uploads/cmp-thumbs/ (flat: *.jpg plus index.php).
$cmp_up = wp_upload_dir( null, false );
if ( empty( $cmp_up['error'] ) && ! empty( $cmp_up['basedir'] ) ) {
	$cmp_dir = trailingslashit( $cmp_up['basedir'] ) . 'cmp-thumbs';
	if ( is_dir( $cmp_dir ) && ! is_link( $cmp_dir ) ) {
		foreach ( (array) scandir( $cmp_dir ) as $cmp_name ) {
			$cmp_file = $cmp_dir . '/' . $cmp_name;
			if ( '.' !== $cmp_name && '..' !== $cmp_name && ( is_file( $cmp_file ) || is_link( $cmp_file ) ) ) {
				@unlink( $cmp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		@rmdir( $cmp_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}
