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

	/** Salted SHA-256 of the user agent (pseudonymized, consistent with hash_ip). */
	public static function hash_ua( $ua, $salt ) {
		return hash( 'sha256', (string) $ua . '|' . (string) $salt );
	}

	private static function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	}

	/** Max consent log requests per hashed IP per hour. */
	const RATE_MAX = 30;

	/**
	 * Public, unauthenticated endpoint. No nonce on purpose: pages are often
	 * served from a cache for days, and an expired nonce silently dropped every
	 * consent record. Abuse is limited instead by same-origin, strict validation
	 * and a per-IP rate limit.
	 */
	public static function ajax_log() {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] ) : '';
		if ( ! self::same_origin( $origin, home_url( '/' ) ) ) {
			wp_send_json_error( 'bad_origin', 403 );
		}

		$key  = 'cmp_rl_' . substr( self::hash_ip( self::client_ip(), wp_salt( 'auth' ) ), 0, 32 );
		$hits = (int) get_transient( $key ) + 1;
		set_transient( $key, $hits, HOUR_IN_SECONDS );
		if ( self::throttled( $hits, self::RATE_MAX ) ) {
			wp_send_json_error( 'too_many', 429 );
		}

		$row = self::parse_request(
			wp_unslash( $_POST ),
			wp_list_pluck( CMP_Consent::get_categories(), 'slug' ),
			CMP_Consent::current_version()
		);
		if ( is_string( $row ) ) {
			wp_send_json_error( $row, 400 );
		}

		$ok = self::record( array_merge( $row, array(
			'lang' => substr( (string) get_locale(), 0, 8 ),
			'url'  => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
		) ) );
		if ( false === $ok ) {
			wp_send_json_error( 'not_logged', 500 );
		}
		wp_send_json_success();
	}

	/**
	 * Pure: validate a consent log request. Returns the row fields (decision,
	 * categories as CSV of known slugs, version) or an error code string.
	 */
	public static function parse_request( array $post, array $valid_slugs, $current_version ) {
		$decision = isset( $post['decision'] ) ? (string) $post['decision'] : '';
		if ( ! in_array( $decision, array( 'accepted_all', 'rejected_all', 'partial', 'withdrawn' ), true ) ) {
			return 'bad_decision';
		}
		$decoded = json_decode( isset( $post['categories'] ) ? (string) $post['categories'] : '', true );
		$cats    = array();
		if ( is_array( $decoded ) ) {
			foreach ( $decoded as $slug ) {
				$slug = is_string( $slug ) ? strtolower( preg_replace( '/[^A-Za-z0-9_-]/', '', $slug ) ) : '';
				if ( '' !== $slug && in_array( $slug, $valid_slugs, true ) && ! in_array( $slug, $cats, true ) ) {
					$cats[] = $slug;
				}
			}
		}
		$version = isset( $post['version'] ) ? (string) $post['version'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9._-]{1,20}$/', $version ) ) {
			$version = (string) $current_version;
		}
		return array(
			'decision'   => $decision,
			'categories' => implode( ',', $cats ),
			'version'    => $version,
		);
	}

	/**
	 * Pure: is the request's Origin our own site? Compares hosts only: a site whose
	 * home URL still says http:// while served over https (common behind proxies)
	 * must not lose its consent records. A missing header is allowed.
	 */
	public static function same_origin( $origin, $home_url ) {
		$origin = (string) $origin;
		if ( '' === $origin ) {
			return true;
		}
		$o = parse_url( $origin, PHP_URL_HOST );
		$h = parse_url( (string) $home_url, PHP_URL_HOST );
		return is_string( $o ) && is_string( $h ) && '' !== $o && strtolower( $o ) === strtolower( $h );
	}

	/** Pure: over the per-IP limit? */
	public static function throttled( $hits, $max ) {
		return (int) $hits > (int) $max;
	}

	/** Insert a consent row. Returns true on write (or dedup skip), false if the DB write failed. */
	public static function record( array $row ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'cmp_consent_log';
		$now     = current_time( 'timestamp', true );
		$ip_hash = self::hash_ip( self::client_ip(), wp_salt( 'auth' ) );
		$ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		$ua_hash = self::hash_ua( $ua, wp_salt( 'auth' ) );

		// Dedup: same (ip_hash, decision) within 24h.
		$last = $wpdb->get_var( $wpdb->prepare(
			"SELECT UNIX_TIMESTAMP(ts) FROM {$table} WHERE ip_hash = %s AND decision = %s ORDER BY ts DESC LIMIT 1",
			$ip_hash,
			$row['decision']
		) );
		if ( self::should_dedup( null !== $last ? (int) $last : null, $now, DAY_IN_SECONDS ) ) {
			return true;
		}

		$done = $wpdb->insert(
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
		return false !== $done;
	}

	public static function to_csv( array $rows ) {
		$cols = array( 'id', 'ts', 'decision', 'categories', 'lang', 'ip_hash', 'ua_hash', 'url', 'version' );
		$out  = "\xEF\xBB\xBF"; // UTF-8 BOM
		$out .= implode( ',', $cols ) . "\r\n";
		foreach ( $rows as $r ) {
			$line = array();
			foreach ( $cols as $c ) {
				$val = isset( $r[ $c ] ) ? (string) $r[ $c ] : '';
				if ( '' !== $val && strpos( '=+-@', $val[0] ) !== false ) {
					$val = "'" . $val;
				}
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
