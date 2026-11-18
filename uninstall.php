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

foreach ( array( 'cmp_services', 'cmp_services_found', 'cmp_services_notice', 'cmp_services_seen', 'cmp_services_history', 'cmp_thumb_queue', 'cmp_db_schema' ) as $cmp_option ) {
	delete_option( $cmp_option );
}
delete_transient( 'cmp_db_schema_retry' );

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
