<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone-loadable for tests; WP-only methods guarded at call time.
}

require_once dirname( __DIR__ ) . '/class-services.php';

class CMP_Audit {

	const CRON_HOOK = 'cmp_prune_consent_log';

	/** Logged decisions; service_* are the per-embed "always load" choices. */
	const DECISIONS = array( 'accepted_all', 'rejected_all', 'partial', 'withdrawn', 'service_allow', 'service_withdraw' );

	public static function register() {
		add_action( 'wp_ajax_cmp_log_consent', array( __CLASS__, 'ajax_log' ) );
		add_action( 'wp_ajax_nopriv_cmp_log_consent', array( __CLASS__, 'ajax_log' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_prune' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
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
			CMP_Consent::current_version(),
			wp_list_pluck( CMP_Services::active(), 'id' )
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
	 * categories as CSV of known slugs, services as CSV of known service ids,
	 * version) or an error code string. $valid_services null = the active library.
	 */
	public static function parse_request( array $post, array $valid_slugs, $current_version, ?array $valid_services = null ) {
		$decision = isset( $post['decision'] ) ? (string) $post['decision'] : '';
		if ( ! in_array( $decision, self::DECISIONS, true ) ) {
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
		if ( null === $valid_services ) {
			$valid_services = array();
			foreach ( CMP_Services::active() as $svc ) {
				$valid_services[] = (string) $svc['id'];
			}
		}
		$sdec = json_decode( isset( $post['services'] ) ? (string) $post['services'] : '', true );
		$svcs = array();
		if ( is_array( $sdec ) ) {
			foreach ( $sdec as $sid ) {
				if ( is_string( $sid ) && in_array( $sid, $valid_services, true ) && ! in_array( $sid, $svcs, true ) ) {
					$svcs[] = $sid;
				}
			}
		}
		// Fit the 255-char column by dropping whole ids, never cutting one in half.
		while ( strlen( implode( ',', $svcs ) ) > 255 ) {
			array_pop( $svcs );
		}
		$services = implode( ',', $svcs );
		if ( '' === $services && in_array( $decision, array( 'service_allow', 'service_withdraw' ), true ) ) {
			return 'bad_services';
		}
		$version = isset( $post['version'] ) ? (string) $post['version'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9._-]{1,20}$/', $version ) ) {
			$version = (string) $current_version;
		}
		$cid = isset( $post['consent_id'] ) ? strtolower( (string) $post['consent_id'] ) : '';
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $cid ) ) {
			$cid = '';
		}
		return array(
			'consent_id' => $cid,
			'decision'   => $decision,
			'categories' => implode( ',', $cats ),
			'services'   => $services,
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

	/**
	 * Pure: is $new the same choice as $last from the same visitor (same consent
	 * ID, decision, categories, services and policy version)? Then it is not logged again.
	 * Rows without an ID are never merged.
	 */
	public static function is_repeat( $last, array $new ) {
		if ( ! is_array( $last ) || empty( $new['consent_id'] ) ) {
			return false;
		}
		foreach ( array( 'consent_id', 'decision', 'categories', 'services', 'version' ) as $k ) {
			if ( (string) ( isset( $last[ $k ] ) ? $last[ $k ] : '' ) !== (string) ( isset( $new[ $k ] ) ? $new[ $k ] : '' ) ) {
				return false;
			}
		}
		return true;
	}

	/** Pure: over the per-IP limit? */
	public static function throttled( $hits, $max ) {
		return (int) $hits > (int) $max;
	}

	/** Insert a consent row. Returns true on write (or repeat skip), false if the DB write failed. */
	public static function record( array $row ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'cmp_consent_log';
		$now     = current_time( 'timestamp', true );
		$ip_hash = self::hash_ip( self::client_ip(), wp_salt( 'auth' ) );
		$ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		$ua_hash = self::hash_ua( $ua, wp_salt( 'auth' ) );

		// Same visitor repeating the same choice (e.g. saving preferences twice): skip.
		$row['consent_id'] = isset( $row['consent_id'] ) ? (string) $row['consent_id'] : '';
		if ( '' !== $row['consent_id'] ) {
			$last = $wpdb->get_row( $wpdb->prepare(
				"SELECT consent_id, decision, categories, services, version FROM {$table} WHERE consent_id = %s ORDER BY id DESC LIMIT 1",
				$row['consent_id']
			), ARRAY_A );
			if ( self::is_repeat( $last, $row ) ) {
				return true;
			}
		}

		$data = array(
			'ts'         => gmdate( 'Y-m-d H:i:s', $now ),
			'consent_id' => $row['consent_id'],
			'decision'   => $row['decision'],
			'categories' => $row['categories'],
			'services'   => isset( $row['services'] ) ? (string) $row['services'] : '',
			'lang'       => $row['lang'],
			'ip_hash'    => $ip_hash,
			'ua_hash'    => $ua_hash,
			'url'        => $row['url'],
			'version'    => $row['version'],
		);
		return self::insert_resilient(
			$data,
			function ( $d ) use ( $wpdb, $table ) {
				return $wpdb->insert( $table, $d, '%s' );
			},
			function () use ( $wpdb ) {
				return (string) $wpdb->last_error;
			},
			function () {
				if ( ! class_exists( 'CMP_Installer' ) && defined( 'CMP_PATH' ) ) {
					require_once CMP_PATH . 'includes/class-installer.php';
				}
				// At most one dbDelta per hour when the ALTER keeps failing (public AJAX).
				if ( class_exists( 'CMP_Installer' ) && ! get_transient( 'cmp_db_schema_retry' ) ) {
					CMP_Installer::upgrade_schema();
				}
			}
		);
	}

	/**
	 * Pure (given its callables): write a consent row so that it is never lost to a
	 * missing 'services' column. On "Unknown column 'services'" the schema is
	 * repaired once (dbDelta) and the insert retried; if the column still cannot
	 * be added, the row is written without it.
	 *
	 * @param array    $data       Column => value.
	 * @param callable $insert     function( array $data ): int|false.
	 * @param callable $last_error function(): string, the last DB error.
	 * @param callable $repair     function(): void, runs dbDelta.
	 * @return bool True when the row was written.
	 */
	public static function insert_resilient( array $data, $insert, $last_error, $repair ) {
		if ( false !== call_user_func( $insert, $data ) ) {
			return true;
		}
		if ( ! array_key_exists( 'services', $data ) || false === stripos( (string) call_user_func( $last_error ), 'services' ) ) {
			return false;
		}
		call_user_func( $repair );
		if ( false !== call_user_func( $insert, $data ) ) {
			return true;
		}
		unset( $data['services'] );
		return false !== call_user_func( $insert, $data );
	}

	public static function to_csv( array $rows ) {
		$cols = array( 'id', 'ts', 'consent_id', 'decision', 'categories', 'services', 'lang', 'ip_hash', 'ua_hash', 'url', 'version' );
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
