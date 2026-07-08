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

	public static function should_dedup( $last_ts, $now_ts, $window_seconds ) {
		if ( null === $last_ts ) {
			return false;
		}
		return ( $now_ts - (int) $last_ts ) < (int) $window_seconds;
	}

	public static function hash_ip( $ip, $salt ) {
		return hash( 'sha256', (string) $ip . '|' . (string) $salt );
	}

	private static function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	}

	public static function ajax_log() {
		if ( ! check_ajax_referer( 'cmp_log_consent', 'nonce', false ) ) {
			wp_send_json_error( 'bad_nonce', 400 );
		}
		$decisions  = array( 'accepted_all', 'rejected_all', 'partial', 'withdrawn' );
		$decision   = isset( $_POST['decision'] ) ? sanitize_text_field( wp_unslash( $_POST['decision'] ) ) : '';
		if ( ! in_array( $decision, $decisions, true ) ) {
			wp_send_json_error( 'bad_decision', 400 );
		}
		$categories = isset( $_POST['categories'] ) ? wp_unslash( $_POST['categories'] ) : '[]';
		$decoded    = json_decode( $categories, true );
		$cats_csv   = is_array( $decoded ) ? implode( ',', array_map( 'sanitize_key', $decoded ) ) : '';
		$version    = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : CMP_Consent::current_version();

		self::record( array(
			'decision'   => $decision,
			'categories' => $cats_csv,
			'lang'       => substr( (string) get_locale(), 0, 8 ),
			'url'        => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
			'version'    => $version,
		) );
		wp_send_json_success();
	}

	public static function record( array $row ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'cmp_consent_log';
		$now     = current_time( 'timestamp', true );
		$ip_hash = self::hash_ip( self::client_ip(), wp_salt( 'auth' ) );
		$ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		$ua_hash = md5( $ua );

		// Dedup: same (ip_hash, decision) within 24h.
		$last = $wpdb->get_var( $wpdb->prepare(
			"SELECT UNIX_TIMESTAMP(ts) FROM {$table} WHERE ip_hash = %s AND decision = %s ORDER BY ts DESC LIMIT 1",
			$ip_hash,
			$row['decision']
		) );
		if ( self::should_dedup( null !== $last ? (int) $last : null, $now, DAY_IN_SECONDS ) ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'ts'         => gmdate( 'Y-m-d H:i:s', $now ),
				'decision'   => $row['decision'],
				'categories' => $row['categories'],
				'lang'       => $row['lang'],
				'ip_hash'    => $ip_hash,
				'ua_hash'    => $ua_hash,
				'url'        => $row['url'],
				'version'    => $row['version'],
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	public static function to_csv( array $rows ) {
		$cols = array( 'id', 'ts', 'decision', 'categories', 'lang', 'ip_hash', 'ua_hash', 'url', 'version' );
		$out  = "\xEF\xBB\xBF"; // UTF-8 BOM
		$out .= implode( ',', $cols ) . "\r\n";
		foreach ( $rows as $r ) {
			$line = array();
			foreach ( $cols as $c ) {
				$val    = isset( $r[ $c ] ) ? (string) $r[ $c ] : '';
				$line[] = '"' . str_replace( '"', '""', $val ) . '"';
			}
			$out .= implode( ',', $line ) . "\r\n";
		}
		return $out;
	}

	public static function to_json( array $rows ) {
		return json_encode( array_values( $rows ) );
	}

	public static function prune_before_date( $retention_months, $now_ts ) {
		$threshold = strtotime( '-' . (int) $retention_months . ' months', (int) $now_ts );
		return gmdate( 'Y-m-d H:i:s', $threshold );
	}

	public static function cron_prune() {
		global $wpdb;
		$settings = get_option( 'cmp_settings', array() );
		$months   = isset( $settings['retention_months'] ) ? (int) $settings['retention_months'] : 13;
		$table    = $wpdb->prefix . 'cmp_consent_log';
		$before   = self::prune_before_date( $months, current_time( 'timestamp', true ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE ts < %s", $before ) );
	}
}
