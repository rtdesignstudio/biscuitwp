<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone-loadable for tests; WP-only methods guarded at call time.
}

/**
 * Self-hosted updates from signed static manifests on biscuitwp.rt.md (no
 * application server, no token, no site-identifying data). The manifest is
 * Ed25519-signed and carries the package's sha256 and detached signature, so
 * nothing unsigned is ever offered or installed. Native WP update flow; no
 * wordpress.org.
 */
class CMP_Updater {

	const SLUG = 'consent-manager-pro';
	/** The active path on sites installed before 1.1.0; its file name is the second entry file. */
	const LEGACY_BASENAME = 'consent-manager-pro/consent-manager-pro.php';

	/**
	 * The plugin's active basename (CMP_Plugin::basename(): the entry file
	 * WordPress loaded), so updates work no matter what the install folder is
	 * named. Falls back to the slug default when WordPress isn't loaded
	 * (standalone tests).
	 */
	public static function file() {
		if ( isset( self::$seam['file'] ) ) {
			return (string) self::$seam['file'];
		}
		if ( function_exists( 'plugin_basename' ) && defined( 'CMP_PATH' ) && class_exists( 'CMP_Plugin' ) ) {
			return CMP_Plugin::basename();
		}
		return self::SLUG . '/consent-manager-pro.php';
	}

	const CACHE_KEY    = 'cmp_latest_release';
	const MAX_DOWNLOAD = 26214400; // 25 MB — defensive cap on the update package size.

	/** Static manifest host (manifest-<channel>.json + .json.sig). */
	const BASE = 'https://biscuitwp.rt.md/';
	/** The package prefix manifests use today (the repo's old path; GitHub redirects it). 1.0.0 sites accept only this one. */
	const PACKAGE_PREFIX = 'https://github.com/rtdesignstudio/consent-manager-pro/releases/download/';
	/**
	 * Every package URL in a manifest must start with one of these (same host,
	 * exact path start): the old repo path and the renamed repo, so a later
	 * release can move the manifests to rtdesignstudio/biscuitwp.
	 */
	const PACKAGE_PREFIXES = array(
		'https://github.com/rtdesignstudio/consent-manager-pro/releases/download/',
		'https://github.com/rtdesignstudio/biscuitwp/releases/download/',
	);
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
	const RESCUE_PUBLIC_KEY = '684HoPUDxOrnCrm+ORFYLCZTmJVpF81unp8j9VKmT8E=';

	/** Option: highest `issued` timestamp seen per channel ({beta, stable}), anti-rollback. */
	const ISSUED_OPTION = 'cmp_manifest_issued';
	/** Option: state behind the admin notices (expired, revoked_installed, no_sodium). */
	const STATE_OPTION = 'cmp_update_state';
	/** Public page explaining update security, linked from the notices. */
	const SECURITY_URL = 'https://biscuitwp.rt.md/securitate/';
	/** Cache lifetimes: 6 hours after a usable manifest, 1 hour after a failure. */
	const CACHE_TTL_OK   = 21600;
	const CACHE_TTL_FAIL = 3600;
	/** Manifest request timeout, seconds. */
	const MANIFEST_TIMEOUT = 15;

	/**
	 * Test-only seam, set exclusively through Reflection by tests/test-updater.php:
	 * manifest_keys / package_keys (arrays), sodium (bool), now (int), file
	 * (string, the active basename), core_check (bool). Always
	 * empty in production; there is no constant, option or filter that fills it.
	 *
	 * @var array
	 */
	private static $seam = array();

	public static function register() {
		add_filter( 'update_plugins_biscuitwp.rt.md', array( __CLASS__, 'update_check' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'verified_download' ), 10, 4 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_source_dir' ), 10, 4 );
		// Both entry files carry the plugin header, so WordPress lists both and files
		// an update under each: only the active one is auto-updated and counted.
		add_filter( 'auto_update_plugin', array( __CLASS__, 'skip_duplicate_auto_update' ), PHP_INT_MAX, 2 );
		add_filter( 'wp_get_update_data', array( __CLASS__, 'filter_update_data' ), 10, 2 );
		// One "BiscuitWP" update offered (Dashboard > Updates, WP-CLI, remote managers):
		// the inactive sibling's entry is dropped on read, so nobody updates through it
		// (no maintenance mode for a file that is not active).
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'filter_update_transient' ) );
	}

	/**
	 * update_plugins as read: the inactive sibling's response / no_update entry is
	 * dropped; when the active file has no entry in that list, the sibling's moves
	 * to it (with `plugin` pointing there). Every other entry and `checked` stay.
	 * Works on a clone. Pure.
	 *
	 * @param mixed  $value  The update_plugins site transient.
	 * @param string $active The active basename.
	 * @return mixed
	 */
	public static function single_entry( $value, $active ) {
		$sib = self::sibling( $active );
		if ( '' === $sib || ! is_object( $value ) ) {
			return $value;
		}
		$value = clone $value;
		foreach ( array( 'response', 'no_update' ) as $list ) {
			if ( ! isset( $value->$list ) || ! is_array( $value->$list ) || ! array_key_exists( $sib, $value->$list ) ) {
				continue;
			}
			$l = $value->$list;
			if ( ! array_key_exists( $active, $l ) ) {
				$entry = $l[ $sib ];
				if ( is_object( $entry ) ) {
					$entry         = clone $entry;
					$entry->plugin = $active;
				} elseif ( is_array( $entry ) ) {
					$entry['plugin'] = $active;
				}
				$out = array();
				foreach ( $l as $k => $e ) {
					$out[ $k === $sib ? $active : $k ] = $k === $sib ? $entry : $e;
				}
				$l = $out;
			} else {
				unset( $l[ $sib ] );
			}
			$value->$list = $l;
		}
		return $value;
	}

	/**
	 * Is WordPress core's own plugin update check running (wp_update_plugins()
	 * hooked on these actions)? It then reads the transient as stored.
	 */
	public static function in_core_update_check() {
		if ( isset( self::$seam['core_check'] ) ) {
			return (bool) self::$seam['core_check'];
		}
		if ( ! function_exists( 'doing_action' ) ) {
			return false;
		}
		foreach ( array( 'load-plugins.php', 'load-update.php', 'load-update-core.php', 'wp_update_plugins' ) as $hook ) {
			if ( doing_action( $hook ) ) {
				return true;
			}
		}
		return false;
	}

	/** site_transient_update_plugins filter: see single_entry(); skipped during core's own check. */
	public static function filter_update_transient( $value ) {
		if ( self::in_core_update_check() ) {
			return $value;
		}
		return self::single_entry( $value, self::file() );
	}

	/**
	 * The other entry file in the same folder: <folder>/biscuitwp.php and
	 * <folder>/consent-manager-pro.php are siblings (same header, same plugin).
	 * '' for any other file name. Pure.
	 */
	public static function sibling( $plugin_file ) {
		if ( ! is_string( $plugin_file ) || false === strpos( $plugin_file, '/' ) ) {
			return '';
		}
		$dir  = dirname( $plugin_file );
		$base = basename( $plugin_file );
		if ( 'biscuitwp.php' === $base ) {
			return $dir . '/consent-manager-pro.php';
		}
		if ( basename( self::LEGACY_BASENAME ) === $base ) {
			return $dir . '/biscuitwp.php';
		}
		return '';
	}

	/**
	 * Does $url start with one of PACKAGE_PREFIXES (byte-exact, case-sensitive)?
	 * Refused first: percent-escapes (WordPress' HTTP layer decodes %2e%2e and
	 * removes dot segments, escaping the repo path), query, fragment, backslash,
	 * whitespace and control characters. Our asset URLs never contain them.
	 */
	public static function is_package_url( $url ) {
		if ( ! is_string( $url ) || preg_match( '/[?#%\\\\\s\x00-\x1f\x7f]/', $url ) ) {
			return false;
		}
		foreach ( self::PACKAGE_PREFIXES as $prefix ) {
			if ( 0 === strpos( $url, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Is $plugin_file this plugin? The active basename or its sibling in the same
	 * folder: get_plugins() lists both (both carry the header), so WordPress may
	 * ask about, or install through, either name.
	 */
	public static function owns( $plugin_file ) {
		$file = self::file();
		if ( $file === $plugin_file ) {
			return true;
		}
		$sib = self::sibling( $file );
		return '' !== $sib && $sib === $plugin_file;
	}

	/**
	 * auto_update_plugin filter (PHP_INT_MAX): never auto-update the sibling that
	 * is not the active file (no rollback check on it, and a second install of
	 * the same folder). The active file and every other plugin keep the incoming
	 * decision.
	 */
	public static function skip_duplicate_auto_update( $update, $item ) {
		$plugin = null;
		if ( is_object( $item ) && isset( $item->plugin ) ) {
			$plugin = $item->plugin;
		} elseif ( is_array( $item ) && isset( $item['plugin'] ) ) {
			$plugin = $item['plugin'];
		}
		$sib = self::sibling( self::file() );
		if ( is_string( $plugin ) && '' !== $sib && $sib === $plugin ) {
			return false;
		}
		return $update;
	}

	/**
	 * Count our plugin once in wp_get_update_data() when both of our keys are in
	 * the update list: plugins and total minus 1 (never below 0), and the plugins
	 * part of the title rebuilt with WordPress's own string.
	 *
	 * @param array $data      wp_get_update_data() result: counts + title.
	 * @param array $titles    The per-type titles core built the title from.
	 * @param bool  $duplicate Both of our keys are in the update list.
	 * @return array
	 */
	public static function dedupe_update_data( $data, $titles, $duplicate ) {
		if ( ! $duplicate || ! is_array( $data ) || ! isset( $data['counts']['plugins'] ) || (int) $data['counts']['plugins'] < 1 ) {
			return $data;
		}
		$n                         = (int) $data['counts']['plugins'] - 1;
		$data['counts']['plugins'] = $n;
		if ( isset( $data['counts']['total'] ) ) {
			$data['counts']['total'] = max( 0, (int) $data['counts']['total'] - 1 );
		}
		$titles = is_array( $titles ) ? $titles : array();
		if ( $n > 0 ) {
			// phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress core's own string, as in wp_get_update_data().
			$fmt               = function_exists( '_n' ) ? _n( '%d Plugin Update', '%d Plugin Updates', $n ) : ( 1 === $n ? '%d Plugin Update' : '%d Plugin Updates' );
			$titles['plugins'] = sprintf( $fmt, $n );
		} else {
			unset( $titles['plugins'] );
		}
		$title         = implode( ', ', $titles );
		$data['title'] = function_exists( 'esc_attr' ) ? esc_attr( $title ) : htmlspecialchars( $title, ENT_QUOTES );
		return $data;
	}

	/** wp_get_update_data filter: our plugin counted once when both siblings have an update. */
	public static function filter_update_data( $data, $titles = array() ) {
		if ( ! function_exists( 'get_site_transient' ) ) {
			return $data;
		}
		$file = self::file();
		$sib  = self::sibling( $file );
		$t    = get_site_transient( 'update_plugins' );
		$both = '' !== $sib && is_object( $t ) && isset( $t->response ) && is_array( $t->response ) && isset( $t->response[ $sib ], $t->response[ $file ] );
		return self::dedupe_update_data( $data, $titles, $both );
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
	 * is a non-empty https:// URL; it never changes keys or PACKAGE_PREFIXES, so a
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
		if ( ! isset( $m['package'] ) || ! is_string( $m['package'] ) || ! self::is_package_url( $m['package'] ) ) {
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
	 * Filter update_plugins_biscuitwp.rt.md (WordPress 5.8+, via the Update URI
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
		if ( ! self::owns( $plugin_file ) ) {
			return $update;
		}
		$r = self::fetch_manifest();
		if ( 'ok' !== $r['status'] || ! is_array( $r['manifest'] ) ) {
			return false;
		}
		$m     = $r['manifest'];
		$offer = ! empty( $r['offer'] );
		return array(
			'id'           => 'biscuitwp.rt.md/' . self::SLUG,
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
			return new WP_Error( 'cmp_no_update_info', __( 'Detaliile actualizării nu sunt disponibile acum. Încearcă din nou mai târziu.', 'biscuitwp' ) );
		}
		$m    = $r['manifest'];
		$info = new stdClass();

		$info->name          = esc_html( 'BiscuitWP' );
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
		$security   = '<a href="' . esc_url( self::SECURITY_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Despre siguranța actualizărilor', 'biscuitwp' ) . '</a>';
		$prefix     = '<strong>' . esc_html__( 'BiscuitWP:', 'biscuitwp' ) . '</strong> ';
		$out        = array();

		if ( ! empty( $state['revoked_installed'] ) ) {
			$out[] = '<div class="notice notice-error"><p>' . $prefix . esc_html(
				sprintf(
					/* translators: %s — installed plugin version */
					__( 'Versiunea %s a fost retrasă din motive de siguranță și trebuie actualizată.', 'biscuitwp' ),
					defined( 'CMP_VERSION' ) ? CMP_VERSION : ''
				)
			) . ' <a href="' . esc_url( $updates_url ) . '">' . esc_html__( 'Actualizează acum', 'biscuitwp' ) . '</a> · ' . $security . '</p></div>';
		}
		if ( ! empty( $state['expired'] ) && ( $on_plugins || $on_ours ) ) {
			$out[] = '<div class="notice notice-warning"><p>' . $prefix
				. esc_html__( 'Update-urile nu pot fi verificate acum. Dacă mesajul persistă câteva zile, scrie-ne.', 'biscuitwp' )
				. ' ' . $security . '</p></div>';
		}
		if ( ! empty( $state['no_sodium'] ) && $on_plugins ) {
			$out[] = '<div class="notice notice-warning"><p>' . $prefix
				. esc_html__( 'Update-urile nu pot fi verificate pe acest server: lipsește extensia PHP sodium.', 'biscuitwp' )
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
		$by_hook = is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) && self::owns( $hook_extra['plugin'] );
		$by_url  = self::is_package_url( $package );
		if ( ! $by_hook && ! $by_url ) {
			return $reply;
		}

		$r = self::fetch_manifest();
		if ( 'ok' !== $r['status'] || ! $r['offer'] || ! is_array( $r['manifest'] ) || ! is_string( $package ) || $package !== $r['manifest']['package'] ) {
			return new WP_Error( 'cmp_package_refused', __( 'Pachetul de update nu corespunde manifestului semnat și a fost refuzat. Apasă din nou „Caută actualizări” și reîncearcă.', 'biscuitwp' ) );
		}

		$tmp = self::download_to_temp( $package );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		if ( ! self::verify_package( $tmp, $r['manifest'], self::package_keys() ) ) {
			if ( file_exists( $tmp ) ) {
				unlink( $tmp );
			}
			return new WP_Error( 'cmp_package_unverified', __( 'Pachetul de update nu a trecut verificarea (sha256 sau semnătură) și a fost șters.', 'biscuitwp' ) );
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
			return new WP_Error( 'cmp_download_failed', __( 'Nu am putut descărca pachetul de update.', 'biscuitwp' ) );
		}
		if ( filesize( $tmp ) >= self::MAX_DOWNLOAD ) { // At the cap = truncated by limit_response_size.
			unlink( $tmp );
			return new WP_Error( 'cmp_download_too_large', __( 'Pachetul de update depășește dimensiunea maximă permisă.', 'biscuitwp' ) );
		}
		return $tmp;
	}

	/** GitHub zips extract as <slug>-<tag>/ or <owner>-<repo>-<sha>/ — rename to the real install folder. */
	public static function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( ! is_string( $source ) || is_wp_error( $source ) ) {
			return $source;
		}
		$plugin = isset( $hook_extra['plugin'] ) ? $hook_extra['plugin'] : '';
		// Either entry file of this plugin (both live in the same
		// folder), so the install always lands in place in the existing folder.
		if ( ! self::owns( $plugin ) ) {
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
		// The rename failed: returning the source unrenamed would make core remove the
		// installed folder and install into another one (the plugin would be lost). An
		// error here makes core stop before it touches the installed plugin.
		return new WP_Error( 'cmp_source_dir', __( 'Update-ul BiscuitWP a fost oprit: folderul pachetului nu a putut fi redenumit în folderul pluginului instalat. Pluginul instalat a rămas neatins. Încearcă din nou.', 'biscuitwp' ) );
	}
}
