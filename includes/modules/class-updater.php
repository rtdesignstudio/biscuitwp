<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone-loadable for tests; WP-only methods guarded at call time.
}

/**
 * Self-hosted updates from signed static manifests on consent.rt.md (no
 * application server, no token, no site-identifying data). The manifest is
 * Ed25519-signed and carries the package's sha256 and detached signature, so
 * nothing unsigned is ever offered or installed. Native WP update flow; no
 * wordpress.org.
 */
class CMP_Updater {

	const SLUG = 'consent-manager-pro';

	/**
	 * The plugin's basename ("<folder>/consent-manager-pro.php"), computed from
	 * where the plugin actually lives so updates work no matter what the install
	 * folder is named. Falls back to the slug default when WordPress isn't loaded
	 * (standalone tests).
	 */
	public static function file() {
		if ( function_exists( 'plugin_basename' ) && defined( 'CMP_PATH' ) ) {
			return plugin_basename( CMP_PATH . 'consent-manager-pro.php' );
		}
		return self::SLUG . '/consent-manager-pro.php';
	}

	const CACHE_KEY    = 'cmp_latest_release';
	const MAX_DOWNLOAD = 26214400; // 25 MB — defensive cap on the update package size.

	/** Static manifest host (manifest-<channel>.json + .json.sig). */
	const BASE = 'https://consent.rt.md/';
	/** Every package URL in a manifest must start with this. */
	const PACKAGE_PREFIX = 'https://github.com/rtdesignstudio/consent-manager-pro/releases/download/';
	/** A manifest is valid for this many days after `issued` (informative; `expires` is authoritative). */
	const EXPIRY_DAYS = 60;
	/** Manifests larger than this are rejected unread. */
	const MAX_MANIFEST = 65536;

	/**
	 * Ed25519 public key that every update package must be signed with
	 * (bin/release.sh signs with the matching secret key, which never leaves
	 * the release machine).
	 */
	const SIGN_PUBLIC_KEY = 'YeJZw3HBsttEaUxHNqbxC+13IewsPPbOyUOU08hkE8o=';
	/**
	 * Ed25519 public key for manifests (bin/release.sh signs with the matching
	 * secret key in ~/.config/cmp-release/manifest.key on the release machine).
	 */
	const MANIFEST_PUBLIC_KEY = '9XrkCKvjusbXCC6fQjKNy7A6DsPNiUcbybEZVdyBssA=';
	/** Offline emergency key: may sign packages or manifests. Placeholder (verifies nothing) until set. */
	const RESCUE_PUBLIC_KEY = 'RESCUE_KEY_NOT_SET';

	/** Option: highest `issued` timestamp seen per channel ({beta, stable}), anti-rollback. */
	const ISSUED_OPTION = 'cmp_manifest_issued';
	/** Option: state behind the admin notices (expired, revoked_installed, no_sodium). */
	const STATE_OPTION = 'cmp_update_state';
	/** Public page explaining update security, linked from the notices. */
	const SECURITY_URL = 'https://consent.rt.md/securitate/';
	/** Cache lifetimes: 6 hours after a usable manifest, 1 hour after a failure. */
	const CACHE_TTL_OK   = 21600;
	const CACHE_TTL_FAIL = 3600;
	/** Manifest request timeout, seconds. */
	const MANIFEST_TIMEOUT = 15;

	/**
	 * Test-only seam, set exclusively through Reflection by tests/test-updater.php:
	 * manifest_keys / package_keys (arrays), sodium (bool), now (int). Always
	 * empty in production; there is no constant, option or filter that fills it.
	 *
	 * @var array
	 */
	private static $seam = array();

	public static function register() {
		add_filter( 'update_plugins_consent.rt.md', array( __CLASS__, 'update_check' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'verified_download' ), 10, 4 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_source_dir' ), 10, 4 );
	}

	/** Pure: is the remote tag (with or without a leading "v") newer? */
	public static function is_newer( $remote_tag, $current_version ) {
		$remote = ltrim( (string) $remote_tag, 'vV' );
		if ( '' === $remote ) {
			return false;
		}
		return version_compare( $remote, (string) $current_version, 'gt' );
	}

	/** Pure: 'beta' only when CMP_UPDATE_CHANNEL === 'beta'; anything else is 'stable'. */
	public static function channel() {
		return ( defined( 'CMP_UPDATE_CHANNEL' ) && 'beta' === CMP_UPDATE_CHANNEL ) ? 'beta' : 'stable';
	}

	/** Pure: the manifest URL for a channel (unknown channels map to stable). */
	public static function manifest_url( $channel ) {
		$channel = ( 'beta' === $channel ) ? 'beta' : 'stable';
		return self::base( defined( 'CMP_UPDATE_BASE' ) ? CMP_UPDATE_BASE : null ) . 'manifest-' . $channel . '.json';
	}

	/**
	 * Pure: the manifest host. CMP_UPDATE_BASE (staging) is honoured only when it
	 * is a non-empty https:// URL; it never changes keys or PACKAGE_PREFIX, so a
	 * staging host still has to serve manifests signed with the real keys.
	 */
	private static function base( $override ) {
		if ( is_string( $override ) && '' !== trim( $override ) && 0 === stripos( trim( $override ), 'https://' ) ) {
			return rtrim( trim( $override ), '/' ) . '/';
		}
		return self::BASE;
	}

	/**
	 * Arguments for every update request: an anonymous user-agent (no site URL,
	 * which WordPress puts in its default user-agent) and no extra headers.
	 */
	public static function request_args( int $timeout ): array {
		return array(
			'timeout'     => $timeout,
			'redirection' => 5,
			'user-agent'  => 'Consent-Manager-Pro/' . ( defined( 'CMP_VERSION' ) ? CMP_VERSION : '0' ),
			'headers'     => array(),
		);
	}

	/**
	 * Pure: is $data signed (detached Ed25519, base64 $sig_b64) by any of
	 * $keys_b64? Keys that are not valid base64 of 32 bytes (the placeholders)
	 * are skipped. Never throws.
	 */
	public static function verify_detached( $data, $sig_b64, array $keys_b64 ) {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) || ! is_string( $data ) || ! is_string( $sig_b64 ) ) {
			return false;
		}
		$sig = base64_decode( trim( $sig_b64 ), true );
		if ( false === $sig || 64 !== strlen( $sig ) ) {
			return false;
		}
		foreach ( $keys_b64 as $key_b64 ) {
			if ( ! is_string( $key_b64 ) ) {
				continue;
			}
			$pk = base64_decode( $key_b64, true );
			if ( false === $pk || 32 !== strlen( $pk ) ) {
				continue;
			}
			try {
				if ( sodium_crypto_sign_verify_detached( $sig, $data, $pk ) ) {
					return true;
				}
			} catch ( Exception $e ) {
				continue;
			} catch ( Error $e ) {
				continue;
			}
		}
		return false;
	}

	/** Pure: is $file signed (detached Ed25519, base64 $sig_b64) by the given key? */
	public static function verify_signature( $file, $sig_b64, $public_key_b64 = self::SIGN_PUBLIC_KEY ) {
		if ( ! is_string( $file ) || ! is_readable( $file ) ) {
			return false;
		}
		return self::verify_detached( (string) file_get_contents( $file ), (string) $sig_b64, array( (string) $public_key_b64 ) );
	}

	/** Pure: strict ISO 8601 UTC ("YYYY-MM-DDTHH:MM:SSZ") to a timestamp; false when unreadable. */
	private static function parse_time( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value ) ) {
			return false;
		}
		$ts = strtotime( $value );
		if ( false === $ts ) {
			return false;
		}
		// Round trip: rejects dates PHP silently normalises (2027-02-30, 24:00:00).
		return ( gmdate( 'Y-m-d\TH:i:s\Z', (int) $ts ) === $value ) ? (int) $ts : false;
	}

	/**
	 * Pure: verify and evaluate a manifest.
	 *
	 * @param string     $json    Exact bytes of manifest-<channel>.json.
	 * @param string     $sig_b64 Contents of the .json.sig file.
	 * @param string     $channel The channel that was requested.
	 * @param array      $env     version, php, wp, now (int), issued_seen (int, this channel only).
	 * @param array|null $keys    Manifest keys; null = MANIFEST_PUBLIC_KEY + RESCUE_PUBLIC_KEY.
	 * @return array status ('invalid'|'expired'|'ok'), manifest (array|null), offer (bool),
	 *               revoked_installed (bool), issued (int, 0 when invalid).
	 */
	public static function evaluate_manifest( $json, $sig_b64, $channel, array $env, ?array $keys = null ) {
		$invalid = array(
			'status'            => 'invalid',
			'manifest'          => null,
			'offer'             => false,
			'revoked_installed' => false,
			'issued'            => 0,
		);
		if ( null === $keys ) {
			$keys = array( self::MANIFEST_PUBLIC_KEY, self::RESCUE_PUBLIC_KEY );
		}
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_MANIFEST ) {
			return $invalid;
		}
		if ( ! self::verify_detached( $json, (string) $sig_b64, $keys ) ) {
			return $invalid;
		}

		$m = json_decode( $json, true );
		if ( ! is_array( $m ) ) {
			return $invalid;
		}
		if ( ! isset( $m['format'] ) || 1 !== $m['format'] ) {
			return $invalid;
		}
		if ( ! isset( $m['slug'] ) || self::SLUG !== $m['slug'] ) {
			return $invalid;
		}
		if ( ! isset( $m['channel'] ) || ! is_string( $channel ) || $channel !== $m['channel'] ) {
			return $invalid;
		}
		if ( ! isset( $m['package'] ) || ! is_string( $m['package'] ) || 0 !== strpos( $m['package'], self::PACKAGE_PREFIX ) ) {
			return $invalid;
		}
		if ( false !== strpos( $m['package'], '..' ) || preg_match( '/[?#\\\\\s]/', $m['package'] ) ) {
			return $invalid; // No path tricks, query, fragment, backslash or whitespace.
		}
		if ( ! isset( $m['version'] ) || ! is_string( $m['version'] ) || ! preg_match( '/^[0-9A-Za-z.+-]+$/', $m['version'] ) ) {
			return $invalid;
		}
		if ( ! isset( $m['sha256'] ) || ! is_string( $m['sha256'] ) || ! preg_match( '/^[0-9a-fA-F]{64}$/', $m['sha256'] ) ) {
			return $invalid;
		}
		if ( ! isset( $m['signature'] ) || ! is_string( $m['signature'] ) || '' === $m['signature'] ) {
			return $invalid;
		}
		$revoked = isset( $m['revoked'] ) ? $m['revoked'] : array();
		if ( ! is_array( $revoked ) ) {
			return $invalid;
		}
		$revoked = array_values( array_filter( $revoked, 'is_string' ) );

		$issued  = self::parse_time( isset( $m['issued'] ) ? $m['issued'] : null );
		$expires = self::parse_time( isset( $m['expires'] ) ? $m['expires'] : null );
		if ( false === $issued || false === $expires ) {
			return $invalid;
		}
		$now = isset( $env['now'] ) ? (int) $env['now'] : time();
		if ( $expires - $issued > self::EXPIRY_DAYS * 86400 ) {
			return $invalid; // A manifest is never valid for longer than EXPIRY_DAYS.
		}
		if ( $issued > $now + 300 ) {
			return $invalid; // Issued in the future (beyond 5 minutes of clock skew).
		}
		$seen = isset( $env['issued_seen'] ) ? (int) $env['issued_seen'] : 0;
		if ( $issued < $seen ) {
			return $invalid; // Anti-rollback: older than a manifest already seen on this channel.
		}

		$installed = isset( $env['version'] ) ? (string) $env['version'] : '';
		$result    = array(
			'status'            => 'ok',
			'manifest'          => $m,
			'offer'             => false,
			'revoked_installed' => ( '' !== $installed && in_array( $installed, $revoked, true ) ),
			'issued'            => $issued,
		);

		// An expired manifest offers nothing, but its (signed) revoked list still applies.
		if ( $expires < $now ) {
			$result['status'] = 'expired';
			return $result;
		}

		$offer = ( '' !== $installed )
			&& version_compare( $m['version'], $installed, '>' )
			&& ! in_array( $m['version'], $revoked, true )
			&& self::requirement_met( isset( $m['requires_php'] ) ? $m['requires_php'] : null, isset( $env['php'] ) ? $env['php'] : '' )
			&& self::requirement_met( isset( $m['requires_wp'] ) ? $m['requires_wp'] : null, isset( $env['wp'] ) ? $env['wp'] : '' );

		$result['offer'] = $offer;
		return $result;
	}

	/** Pure: is a minimum version satisfied? Missing requirement or unknown environment = satisfied. */
	private static function requirement_met( $required, $actual ) {
		if ( ! is_string( $required ) && ! is_int( $required ) && ! is_float( $required ) ) {
			return null === $required;
		}
		$required = (string) $required;
		$actual   = (string) $actual;
		if ( '' === $required || '' === $actual ) {
			return true;
		}
		return version_compare( $actual, $required, '>=' );
	}

	/**
	 * Pure: does $file match the manifest's sha256 and is the manifest's
	 * `signature` a valid signature of it? $keys null = SIGN_PUBLIC_KEY + RESCUE_PUBLIC_KEY.
	 */
	public static function verify_package( $file, array $manifest, ?array $keys = null ) {
		if ( null === $keys ) {
			$keys = array( self::SIGN_PUBLIC_KEY, self::RESCUE_PUBLIC_KEY );
		}
		if ( ! is_string( $file ) || ! is_file( $file ) || ! is_readable( $file ) ) {
			return false;
		}
		if ( empty( $manifest['sha256'] ) || ! is_string( $manifest['sha256'] ) || ! preg_match( '/^[0-9a-fA-F]{64}$/', $manifest['sha256'] ) ) {
			return false;
		}
		if ( empty( $manifest['signature'] ) || ! is_string( $manifest['signature'] ) ) {
			return false;
		}
		$data = file_get_contents( $file );
		if ( false === $data ) {
			return false;
		}
		if ( ! hash_equals( strtolower( $manifest['sha256'] ), hash( 'sha256', $data ) ) ) {
			return false;
		}
		return self::verify_detached( $data, $manifest['signature'], $keys );
	}

	/** Manifest keys: the baked constants (a test may substitute temporary keys through $seam). */
	private static function manifest_keys() {
		return isset( self::$seam['manifest_keys'] ) ? (array) self::$seam['manifest_keys'] : array( self::MANIFEST_PUBLIC_KEY, self::RESCUE_PUBLIC_KEY );
	}

	/** Package keys: the baked constants (a test may substitute temporary keys through $seam). */
	private static function package_keys() {
		return isset( self::$seam['package_keys'] ) ? (array) self::$seam['package_keys'] : array( self::SIGN_PUBLIC_KEY, self::RESCUE_PUBLIC_KEY );
	}

	private static function has_sodium() {
		return isset( self::$seam['sodium'] ) ? (bool) self::$seam['sodium'] : function_exists( 'sodium_crypto_sign_verify_detached' );
	}

	private static function now() {
		return isset( self::$seam['now'] ) ? (int) self::$seam['now'] : time();
	}

	/**
	 * The saved notice state, normalised to the three booleans. Like the
	 * counter and the cache, it is network-wide (site option): plugin updates
	 * are network-wide on multisite. On single site these are plain options.
	 */
	public static function state() {
		$saved = get_site_option( self::STATE_OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'expired'           => ! empty( $saved['expired'] ),
			'revoked_installed' => ! empty( $saved['revoked_installed'] ),
			'no_sodium'         => ! empty( $saved['no_sodium'] ),
		);
	}

	private static function save_state( array $state ) {
		if ( get_site_option( self::STATE_OPTION, null ) !== $state ) {
			update_site_option( self::STATE_OPTION, $state );
		}
	}

	/**
	 * GET a small file (manifest or .sig): body string, or false on any failure.
	 * A body at the MAX_MANIFEST cap was cut by limit_response_size: unusable.
	 */
	private static function get_small( $url ) {
		$args                        = self::request_args( self::MANIFEST_TIMEOUT );
		$args['limit_response_size'] = self::MAX_MANIFEST;
		$response                    = wp_safe_remote_get( $url, $args );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}
		$body = (string) wp_remote_retrieve_body( $response );
		return ( strlen( $body ) >= self::MAX_MANIFEST ) ? false : $body;
	}

	/**
	 * The current channel's manifest, verified and evaluated (evaluate_manifest's
	 * result). The raw bytes and signature are cached (6 h; failures 1 h) and
	 * re-evaluated on every call, so the cache never skips the signature,
	 * expiry or anti-rollback checks.
	 *
	 * Side effects: raises cmp_manifest_issued[channel] after any validly signed
	 * manifest (expired included) and recomputes cmp_update_state. A network
	 * failure or an invalid manifest ("unavailable") keeps the saved state,
	 * except that it turns `expired` on once the newest manifest ever verified
	 * on this channel is older than EXPIRY_DAYS (see unavailable()).
	 *
	 * @param bool $force Ignore the cache.
	 * @return array
	 */
	public static function fetch_manifest( bool $force = false ): array {
		$unavailable = array(
			'status'            => 'invalid',
			'manifest'          => null,
			'offer'             => false,
			'revoked_installed' => false,
			'issued'            => 0,
		);

		if ( ! self::has_sodium() ) {
			// Nothing can be verified: flag it, keep the last verified expired/revoked flags.
			$state              = self::state();
			$state['no_sodium'] = true;
			self::save_state( $state );
			return $unavailable;
		}

		$channel    = self::channel();
		$issued_all = get_site_option( self::ISSUED_OPTION, array() );
		$issued_all = is_array( $issued_all ) ? $issued_all : array();
		$seen       = isset( $issued_all[ $channel ] ) ? (int) $issued_all[ $channel ] : 0;

		$cached = $force ? false : get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && isset( $cached['channel'] ) && $channel === $cached['channel'] ) {
			if ( ! empty( $cached['fail'] ) || ! isset( $cached['json'], $cached['sig'] ) ) {
				return self::unavailable( $unavailable, $seen );
			}
			$json    = (string) $cached['json'];
			$sig     = (string) $cached['sig'];
			$fetched = false;
		} else {
			$url     = self::manifest_url( $channel );
			$json    = self::get_small( $url );
			$sig     = ( false === $json ) ? false : self::get_small( $url . '.sig' );
			$fetched = true;
			if ( false === $json || false === $sig ) {
				set_site_transient( self::CACHE_KEY, array( 'channel' => $channel, 'fail' => true ), self::CACHE_TTL_FAIL );
				return self::unavailable( $unavailable, $seen );
			}
		}

		$result = self::evaluate_manifest(
			$json,
			$sig,
			$channel,
			array(
				'version'     => defined( 'CMP_VERSION' ) ? CMP_VERSION : '',
				'php'         => PHP_VERSION,
				'wp'          => isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '',
				'now'         => self::now(),
				'issued_seen' => $seen,
			),
			self::manifest_keys()
		);

		if ( $fetched ) {
			if ( 'invalid' === $result['status'] ) {
				set_site_transient( self::CACHE_KEY, array( 'channel' => $channel, 'fail' => true ), self::CACHE_TTL_FAIL );
			} else {
				set_site_transient( self::CACHE_KEY, array( 'channel' => $channel, 'json' => $json, 'sig' => $sig ), self::CACHE_TTL_OK );
			}
		}
		if ( 'invalid' === $result['status'] ) {
			return self::unavailable( $result, $seen ); // Keep the counter and the other flags.
		}

		if ( $result['issued'] > 0 && $result['issued'] > $seen ) {
			$issued_all[ $channel ] = (int) $result['issued'];
			update_site_option( self::ISSUED_OPTION, $issued_all );
		}
		self::save_state(
			array(
				'expired'           => ( 'expired' === $result['status'] ),
				'revoked_installed' => (bool) $result['revoked_installed'],
				'no_sodium'         => false,
			)
		);
		return $result;
	}

	/**
	 * An "unavailable" outcome (network error, non-200, truncated body, invalid
	 * signature or fields, rollback, cached failure). The saved flags are kept,
	 * but if the newest manifest ever verified on this channel ($seen) has
	 * outlived EXPIRY_DAYS, `expired` is turned on: no manifest we could still
	 * trust exists, so serving errors must not freeze a site silently.
	 *
	 * @param array $result The unavailable result, returned unchanged.
	 * @param int   $seen   cmp_manifest_issued[channel] (0 = never seen).
	 * @return array
	 */
	private static function unavailable( array $result, $seen ) {
		$seen = (int) $seen;
		if ( $seen > 0 && self::now() - $seen > self::EXPIRY_DAYS * 86400 ) {
			$state            = self::state();
			$state['expired'] = true;
			self::save_state( $state );
		}
		return $result;
	}

	/**
	 * Pure: what the manual "Caută actualizări" check reports: the offered
	 * version, 'latest' (verified manifest, nothing newer) or 'unavailable'
	 * (manifest missing, invalid or expired).
	 */
	public static function check_result( array $r ): string {
		if ( ! isset( $r['status'] ) || 'ok' !== $r['status'] ) {
			return 'unavailable';
		}
		if ( ! empty( $r['offer'] ) && isset( $r['manifest']['version'] ) && is_string( $r['manifest']['version'] ) ) {
			return $r['manifest']['version'];
		}
		return 'latest';
	}

	/**
	 * Filter update_plugins_consent.rt.md (WordPress 5.8+, via the Update URI
	 * header). Foreign plugin files are returned untouched.
	 *
	 * wp_update_plugins() files the returned entry under `response` when its
	 * version is newer than the installed one and under `no_update` otherwise
	 * (which is what shows the auto-update toggle). So a valid manifest without
	 * an offer returns the descriptive fields with version = CMP_VERSION and an
	 * empty package: a revoked or incompatible version never reaches `response`.
	 * Invalid, expired or unavailable manifest: false (nothing recorded).
	 */
	public static function update_check( $update, $plugin_data, $plugin_file, $locales ) {
		if ( self::file() !== $plugin_file ) {
			return $update;
		}
		$r = self::fetch_manifest();
		if ( 'ok' !== $r['status'] || ! is_array( $r['manifest'] ) ) {
			return false;
		}
		$m     = $r['manifest'];
		$offer = ! empty( $r['offer'] );
		return array(
			'id'           => 'consent.rt.md/' . self::SLUG,
			'slug'         => self::SLUG,
			'version'      => $offer ? $m['version'] : ( defined( 'CMP_VERSION' ) ? CMP_VERSION : '' ),
			'url'          => self::BASE,
			'package'      => $offer ? $m['package'] : '',
			'tested'       => self::str_field( $m, 'tested_wp' ),
			'requires_php' => self::str_field( $m, 'requires_php' ),
			'requires'     => self::str_field( $m, 'requires_wp' ),
		);
	}

	private static function str_field( array $m, $key ) {
		return ( isset( $m[ $key ] ) && is_scalar( $m[ $key ] ) ) ? (string) $m[ $key ] : '';
	}

	/**
	 * Filter plugins_api: the "View details" window for our slug, built only
	 * from the signed manifest, every string escaped. Other slugs and actions
	 * are untouched. Without a valid manifest our slug gets an error, never a
	 * wordpress.org lookup.
	 */
	public static function plugin_info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $res;
		}
		$r = self::fetch_manifest();
		if ( ! is_array( $r['manifest'] ) ) {
			return new WP_Error( 'cmp_no_update_info', __( 'Detaliile actualizării nu sunt disponibile acum. Încearcă din nou mai târziu.', 'consent-manager-pro' ) );
		}
		$m    = $r['manifest'];
		$info = new stdClass();

		$info->name          = esc_html( 'Consent Manager Pro' );
		$info->slug          = self::SLUG;
		$info->version       = esc_html( $m['version'] );
		$info->requires      = esc_html( self::str_field( $m, 'requires_wp' ) );
		$info->requires_php  = esc_html( self::str_field( $m, 'requires_php' ) );
		$info->tested        = esc_html( self::str_field( $m, 'tested_wp' ) );
		$info->last_updated  = esc_html( self::str_field( $m, 'released' ) );
		$info->author        = esc_html( 'RT.md' );
		$info->homepage      = self::BASE;
		$info->external      = true;
		$info->sections      = array(
			'changelog' => self::changelog_html( isset( $m['notes'] ) && is_array( $m['notes'] ) ? $m['notes'] : array() ),
		);
		return $info;
	}

	/** Pure: notes -> <h4>version – date</h4><ul><li>item</li></ul>, every string escaped. */
	public static function changelog_html( array $notes ): string {
		$html = '';
		foreach ( $notes as $note ) {
			if ( ! is_array( $note ) || ! isset( $note['version'] ) || ! is_scalar( $note['version'] ) ) {
				continue;
			}
			$title = esc_html( (string) $note['version'] );
			if ( isset( $note['date'] ) && is_scalar( $note['date'] ) && '' !== (string) $note['date'] ) {
				$title .= ' – ' . esc_html( (string) $note['date'] );
			}
			$items = '';
			foreach ( ( isset( $note['items'] ) && is_array( $note['items'] ) ) ? $note['items'] : array() as $item ) {
				if ( is_string( $item ) ) {
					$items .= '<li>' . esc_html( $item ) . '</li>';
				}
			}
			$html .= '<h4>' . $title . '</h4>' . ( '' !== $items ? '<ul>' . $items . '</ul>' : '' );
		}
		return $html;
	}

	/**
	 * Pure: the admin notices (HTML) for a saved state on a given screen hook.
	 * The caller checks update_plugins. Revoked: every screen; expired: the
	 * Plugins screen and the plugin's own screens; no sodium: the Plugins screen.
	 *
	 * @param array  $state       cmp_update_state.
	 * @param string $hook        Current admin page hook ($hook_suffix).
	 * @param string $updates_url Link to the updates screen.
	 * @return string[]
	 */
	public static function notices( array $state, $hook, $updates_url ) {
		$hook       = (string) $hook;
		$on_plugins = ( 'plugins.php' === $hook );
		$on_ours    = ( false !== strpos( $hook, 'cmp-' ) );
		$security   = '<a href="' . esc_url( self::SECURITY_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Despre siguranța actualizărilor', 'consent-manager-pro' ) . '</a>';
		$prefix     = '<strong>' . esc_html__( 'Consent Manager Pro:', 'consent-manager-pro' ) . '</strong> ';
		$out        = array();

		if ( ! empty( $state['revoked_installed'] ) ) {
			$out[] = '<div class="notice notice-error"><p>' . $prefix . esc_html(
				sprintf(
					/* translators: %s — installed plugin version */
					__( 'Versiunea %s a fost retrasă din motive de siguranță și trebuie actualizată.', 'consent-manager-pro' ),
					defined( 'CMP_VERSION' ) ? CMP_VERSION : ''
				)
			) . ' <a href="' . esc_url( $updates_url ) . '">' . esc_html__( 'Actualizează acum', 'consent-manager-pro' ) . '</a> · ' . $security . '</p></div>';
		}
		if ( ! empty( $state['expired'] ) && ( $on_plugins || $on_ours ) ) {
			$out[] = '<div class="notice notice-warning"><p>' . $prefix
				. esc_html__( 'Update-urile nu pot fi verificate acum. Dacă mesajul persistă câteva zile, scrie-ne.', 'consent-manager-pro' )
				. ' ' . $security . '</p></div>';
		}
		if ( ! empty( $state['no_sodium'] ) && $on_plugins ) {
			$out[] = '<div class="notice notice-warning"><p>' . $prefix
				. esc_html__( 'Update-urile nu pot fi verificate pe acest server: lipsește extensia PHP sodium.', 'consent-manager-pro' )
				. ' ' . $security . '</p></div>';
		}
		return $out;
	}

	/**
	 * Filter upgrader_pre_download. Fail closed: any package aimed at this plugin
	 * (by hook_extra or by URL prefix) is installed only if it is exactly the
	 * `package` of the currently verified manifest that offers it, and only after
	 * verify_package() passes; anything else is a WP_Error. Packages of other
	 * plugins pass through untouched.
	 */
	public static function verified_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		$by_hook = is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) && self::file() === $hook_extra['plugin'];
		$by_url  = is_string( $package ) && 0 === strpos( $package, self::PACKAGE_PREFIX );
		if ( ! $by_hook && ! $by_url ) {
			return $reply;
		}

		$r = self::fetch_manifest();
		if ( 'ok' !== $r['status'] || ! $r['offer'] || ! is_array( $r['manifest'] ) || ! is_string( $package ) || $package !== $r['manifest']['package'] ) {
			return new WP_Error( 'cmp_package_refused', __( 'Pachetul de update nu corespunde manifestului semnat și a fost refuzat. Apasă din nou „Caută actualizări” și reîncearcă.', 'consent-manager-pro' ) );
		}

		$tmp = self::download_to_temp( $package );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		if ( ! self::verify_package( $tmp, $r['manifest'], self::package_keys() ) ) {
			if ( file_exists( $tmp ) ) {
				unlink( $tmp );
			}
			return new WP_Error( 'cmp_package_unverified', __( 'Pachetul de update nu a trecut verificarea (sha256 sau semnătură) și a fost șters.', 'consent-manager-pro' ) );
		}
		return $tmp;
	}

	/** Stream a URL to a temp file (redirects followed), capped at MAX_DOWNLOAD while streaming. */
	private static function download_to_temp( $url ) {
		$tmp                         = wp_tempnam( 'cmp-update' );
		$args                        = self::request_args( 300 );
		$args['stream']              = true;
		$args['filename']            = $tmp;
		$args['limit_response_size'] = self::MAX_DOWNLOAD;
		$response                    = wp_safe_remote_get( $url, $args );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			if ( file_exists( $tmp ) ) {
				unlink( $tmp );
			}
			return new WP_Error( 'cmp_download_failed', __( 'Nu am putut descărca pachetul de update.', 'consent-manager-pro' ) );
		}
		if ( filesize( $tmp ) >= self::MAX_DOWNLOAD ) { // At the cap = truncated by limit_response_size.
			unlink( $tmp );
			return new WP_Error( 'cmp_download_too_large', __( 'Pachetul de update depășește dimensiunea maximă permisă.', 'consent-manager-pro' ) );
		}
		return $tmp;
	}

	/** GitHub zips extract as <slug>-<tag>/ or <owner>-<repo>-<sha>/ — rename to the real install folder. */
	public static function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( ! is_string( $source ) || is_wp_error( $source ) ) {
			return $source;
		}
		$plugin = isset( $hook_extra['plugin'] ) ? $hook_extra['plugin'] : '';
		if ( self::file() !== $plugin ) {
			return $source;
		}

		global $wp_filesystem;
		// Rename the extracted dir to whatever the plugin's install folder is
		// actually called, so WordPress replaces it in place.
		$target   = basename( dirname( $plugin ) );
		$basename = basename( untrailingslashit( $source ) );
		if ( $target === $basename ) {
			return $source;
		}
		$new_source = trailingslashit( dirname( untrailingslashit( $source ) ) ) . $target . '/';
		if ( $wp_filesystem && $wp_filesystem->exists( untrailingslashit( $new_source ) ) ) {
			$wp_filesystem->delete( untrailingslashit( $new_source ), true );
		}
		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $new_source ) ) ) {
			return $new_source;
		}
		return $source;
	}
}
