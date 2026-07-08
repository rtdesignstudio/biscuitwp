<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone-loadable for tests; WP-only methods guarded at call time.
}

class CMP_Audit {

	const CRON_HOOK = 'cmp_prune_consent_log';

	public static function register() {
		add_action( 'wp_ajax_cmp_log_consent', array( __CLASS__, 'ajax_log' ) );
		add_action( 'wp_ajax_nopriv_cmp_log_consent', array( __CLASS__, 'ajax_log' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_prune' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}
}
