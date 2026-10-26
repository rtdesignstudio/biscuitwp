<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone-loadable for tests; WP-only methods guarded at call time.
}

/**
 * Self-hosted updates from GitHub Releases of the private repo. Native WP
 * update flow; no wordpress.org. Inactive when no token is configured.
 */
class CMP_Updater {

	const REPO = 'rtgit003/consent-manager-pro';
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

	const CACHE_KEY = 'cmp_latest_release';
	const MAX_DOWNLOAD = 26214400; // 25 MB — defensive cap on the update package size.
	const PROXY_URL = 'https://rt.md/cmp-update';
	const PROXY_KEY = '668df26dc0d04136723a5492a767b4268f4693d210294151';
	/**
	 * Ed25519 public key that every update package must be signed with
	 * (bin/release.sh signs with the matching secret key, which never leaves
	 * the release machine). A compromised relay or GitHub account cannot push
	 * code to sites without that key.
	 */
	const SIGN_PUBLIC_KEY = 'YeJZw3HBsttEaUxHNqbxC+13IewsPPbOyUOU08hkE8o=';

	public static function register() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check' ) );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'verified_download' ), 10, 3 );
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

	/**
	 * Pure: build the update object for the update_plugins transient from a
	 * GitHub release payload. Returns null when not newer or data is missing.
	 */
	public static function build_update( array $release, $current_version ) {
		$tag = isset( $release['tag_name'] ) ? (string) $release['tag_name'] : '';
		if ( '' === $tag || ! self::is_newer( $tag, $current_version ) ) {
			return null;
		}

		$package = '';
		if ( ! empty( $release['assets'][0]['url'] ) ) {
			$package = (string) $release['assets'][0]['url'];
		} elseif ( ! empty( $release['zipball_url'] ) ) {
			$package = (string) $release['zipball_url'];
		}
		if ( '' === $package ) {
			return null;
		}

		$update              = new stdClass();
		$update->slug        = self::SLUG;
		$update->plugin      = self::file();
		$update->new_version = ltrim( $tag, 'vV' );
		$update->url         = isset( $release['html_url'] ) ? (string) $release['html_url'] : 'https://github.com/' . self::REPO;
		$update->package     = $package;
		return $update;
	}

	public static function proxy_url() {
		if ( defined( 'CMP_UPDATE_PROXY' ) && CMP_UPDATE_PROXY ) {
			return (string) CMP_UPDATE_PROXY;
		}
		return self::PROXY_URL;
	}

	public static function proxy_key() {
		if ( defined( 'CMP_UPDATE_KEY' ) && CMP_UPDATE_KEY ) {
			return (string) CMP_UPDATE_KEY;
		}
		return self::PROXY_KEY;
	}

	/** Pure: shape the proxy {version} payload into a release array. package = proxy download URL. */
	public static function release_from_proxy( $json, $proxy_url, $proxy_key ) {
		if ( ! is_array( $json ) || empty( $json['version'] ) ) {
			return null;
		}
		$sep     = ( false === strpos( (string) $proxy_url, '?' ) ) ? '?' : '&';
		$package = (string) $proxy_url . $sep . 'action=download&key=' . rawurlencode( (string) $proxy_key );
		return array(
			'tag_name'    => (string) $json['version'],
			'html_url'    => (string) $proxy_url,
			'zipball_url' => $package,
			'assets'      => array(),
		);
	}

	/**
	 * Pure: from a GitHub release's assets, the API URLs of the package zip and
	 * of its detached signature (<slug>.zip.sig). Falls back to the first
	 * non-signature asset for the zip.
	 */
	public static function pick_assets( $assets ) {
		$out = array( 'zip' => '', 'sig' => '' );
		if ( ! is_array( $assets ) ) {
			return $out;
		}
		foreach ( $assets as $a ) {
			$name = isset( $a['name'] ) ? (string) $a['name'] : '';
			$url  = isset( $a['url'] ) ? (string) $a['url'] : '';
			if ( '' === $url ) {
				continue;
			}
			if ( self::SLUG . '.zip.sig' === $name ) {
				$out['sig'] = $url;
			} elseif ( self::SLUG . '.zip' === $name ) {
				$out['zip'] = $url;
			} elseif ( '' === $out['zip'] && '.sig' !== substr( $name, -4 ) ) {
				$out['zip'] = $url;
			}
		}
		return $out;
	}

	/** Pure: does this package URL come from our relay or our GitHub repo? Returns 'proxy', 'github' or ''. */
	public static function package_kind( $package, $proxy_url ) {
		if ( ! is_string( $package ) || '' === $package ) {
			return '';
		}
		$proxy_url = (string) $proxy_url;
		if ( '' !== $proxy_url && 0 === strpos( $package, $proxy_url ) && false !== strpos( $package, 'action=download' ) ) {
			return 'proxy';
		}
		$parts = parse_url( $package );
		$host  = isset( $parts['host'] ) ? $parts['host'] : '';
		$path  = isset( $parts['path'] ) ? $parts['path'] : '';
		if ( 'api.github.com' === $host && 0 === strpos( $path, '/repos/' . self::REPO . '/' ) ) {
			return 'github';
		}
		return '';
	}

	/** Pure: is $file signed (detached Ed25519, base64 $sig_b64) by the release key? */
	public static function verify_signature( $file, $sig_b64, $public_key_b64 = self::SIGN_PUBLIC_KEY ) {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) || ! is_readable( $file ) ) {
			return false;
		}
		$sig = base64_decode( trim( (string) $sig_b64 ), true );
		$pk  = base64_decode( (string) $public_key_b64, true );
		if ( false === $sig || false === $pk || 64 !== strlen( $sig ) || 32 !== strlen( $pk ) ) {
			return false;
		}
		try {
			return sodium_crypto_sign_verify_detached( $sig, (string) file_get_contents( $file ), $pk );
		} catch ( Exception $e ) {
			return false;
		} catch ( Error $e ) {
			return false;
		}
	}

	public static function token() {
		if ( defined( 'CMP_GITHUB_TOKEN' ) && CMP_GITHUB_TOKEN ) {
			return (string) CMP_GITHUB_TOKEN;
		}
		$s = CMP_Consent::get_settings();
		return isset( $s['github_token'] ) ? (string) $s['github_token'] : '';
	}

	/** Query GitHub for the latest release (cached). Null when unavailable. */
	public static function fetch_release( $force = false ) {
		$cmp_proxy = self::proxy_url();
		if ( '' !== $cmp_proxy ) {
			return self::fetch_release_proxy( $cmp_proxy, $force );
		}

		if ( '' === self::token() ) {
			return null;
		}
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( 'none' === $cached ) {
				return null;
			}
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization'        => 'Bearer ' . self::token(),
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::CACHE_KEY, 'none', HOUR_IN_SECONDS );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_transient( self::CACHE_KEY, 'none', HOUR_IN_SECONDS );
			return null;
		}

		// Keep only what we need — the transient stays small.
		$release = array(
			'tag_name'    => $body['tag_name'],
			'html_url'    => isset( $body['html_url'] ) ? $body['html_url'] : '',
			'zipball_url' => isset( $body['zipball_url'] ) ? $body['zipball_url'] : '',
			'assets'      => array(),
		);
		$picked = self::pick_assets( isset( $body['assets'] ) ? $body['assets'] : array() );
		if ( '' !== $picked['zip'] ) {
			$release['assets'][] = array( 'url' => $picked['zip'] );
		}
		$release['sig_url'] = $picked['sig'];
		set_transient( self::CACHE_KEY, $release, 6 * HOUR_IN_SECONDS );
		return $release;
	}

	private static function fetch_release_proxy( $proxy, $force ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( 'none' === $cached ) {
				return null;
			}
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$url      = $proxy . ( false === strpos( $proxy, '?' ) ? '?' : '&' ) . 'action=version&key=' . rawurlencode( self::proxy_key() );
		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::CACHE_KEY, 'none', HOUR_IN_SECONDS );
			return null;
		}

		$json    = json_decode( wp_remote_retrieve_body( $response ), true );
		$release = self::release_from_proxy( $json, $proxy, self::proxy_key() );
		if ( null === $release ) {
			set_transient( self::CACHE_KEY, 'none', HOUR_IN_SECONDS );
			return null;
		}
		set_transient( self::CACHE_KEY, $release, 6 * HOUR_IN_SECONDS );
		return $release;
	}

	/** Inject our update into the native update_plugins transient. */
	public static function check( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$release = self::fetch_release();
		if ( null === $release ) {
			return $transient;
		}
		$update = self::build_update( $release, CMP_VERSION );
		if ( $update ) {
			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = array();
			}
			$transient->response[ self::file() ] = $update;
		}
		return $transient;
	}

	/**
	 * Download our package (relay or GitHub) and install it only if its
	 * signature verifies against SIGN_PUBLIC_KEY. Other plugins' packages
	 * pass through untouched.
	 */
	public static function verified_download( $reply, $package, $upgrader ) {
		if ( false !== $reply ) {
			return $reply;
		}
		$kind = self::package_kind( $package, self::proxy_url() );
		if ( '' === $kind ) {
			return $reply;
		}

		$file = 'proxy' === $kind ? self::download_to_temp( $package ) : self::authenticated_download( false, $package, $upgrader );
		if ( is_wp_error( $file ) || ! is_string( $file ) || '' === $file ) {
			return is_wp_error( $file ) ? $file : new WP_Error( 'cmp_download_failed', __( 'Nu am putut descărca pachetul de update.', 'consent-manager-pro' ) );
		}

		if ( ! self::verify_signature( $file, self::fetch_signature( $kind ) ) ) {
			unlink( $file );
			return new WP_Error( 'cmp_bad_signature', __( 'Pachetul de update nu are o semnătură validă și nu a fost instalat.', 'consent-manager-pro' ) );
		}
		return $file;
	}

	/** Stream a URL to a temp file (redirects followed), capped at MAX_DOWNLOAD. */
	private static function download_to_temp( $url ) {
		$tmp      = wp_tempnam( 'cmp-update' );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'  => 300,
				'stream'   => true,
				'filename' => $tmp,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			if ( file_exists( $tmp ) ) {
				unlink( $tmp );
			}
			return new WP_Error( 'cmp_download_failed', __( 'Nu am putut descărca pachetul de update.', 'consent-manager-pro' ) );
		}
		if ( filesize( $tmp ) > self::MAX_DOWNLOAD ) {
			unlink( $tmp );
			return new WP_Error( 'cmp_download_too_large', __( 'Pachetul de update depășește dimensiunea maximă permisă.', 'consent-manager-pro' ) );
		}
		return $tmp;
	}

	/** The detached signature of the latest release ('' when unavailable). */
	private static function fetch_signature( $kind ) {
		if ( 'proxy' === $kind ) {
			$proxy    = self::proxy_url();
			$url      = $proxy . ( false === strpos( $proxy, '?' ) ? '?' : '&' ) . 'action=signature&key=' . rawurlencode( self::proxy_key() );
			$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return '';
			}
			return substr( trim( (string) wp_remote_retrieve_body( $response ) ), 0, 200 );
		}
		$release = self::fetch_release( true );
		if ( ! is_array( $release ) || empty( $release['sig_url'] ) ) {
			return '';
		}
		$tmp = self::authenticated_download( false, $release['sig_url'], null );
		if ( is_wp_error( $tmp ) || ! is_string( $tmp ) || ! is_readable( $tmp ) ) {
			return '';
		}
		$sig = substr( trim( (string) file_get_contents( $tmp ) ), 0, 200 );
		unlink( $tmp );
		return $sig;
	}

	/** Download our package: ask GitHub with the token but do NOT follow its
	 * redirect with the token — GitHub 302s to a signed host, which we then
	 * fetch without any auth header (prevents leaking the token cross-host). */
	public static function authenticated_download( $reply, $package, $upgrader ) {
		if ( false !== $reply ) {
			return $reply;
		}
		if ( ! is_string( $package ) ) {
			return $reply;
		}
		$parts = wp_parse_url( $package );
		$host  = isset( $parts['host'] ) ? $parts['host'] : '';
		$path  = isset( $parts['path'] ) ? $parts['path'] : '';
		if ( 'api.github.com' !== $host || 0 !== strpos( $path, '/repos/' . self::REPO . '/' ) ) {
			return $reply;
		}

		// Step 1: request the asset WITHOUT following redirects, so the Bearer
		// token is only ever sent to api.github.com.
		$auth = wp_remote_get(
			$package,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Bearer ' . self::token(),
					'Accept'        => 'application/octet-stream',
				),
			)
		);
		if ( is_wp_error( $auth ) ) {
			return new WP_Error( 'cmp_download_failed', __( 'Nu am putut descărca pachetul de update de pe GitHub.', 'consent-manager-pro' ) );
		}
		$code     = (int) wp_remote_retrieve_response_code( $auth );
		$location = wp_remote_retrieve_header( $auth, 'location' );
		$tmp      = wp_tempnam( 'cmp-update' );

		// Step 2a: follow GitHub's redirect to the signed URL WITHOUT the token.
		if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) && $location ) {
			$response = wp_remote_get(
				$location,
				array(
					'timeout'  => 300,
					'stream'   => true,
					'filename' => $tmp,
				)
			);
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				if ( file_exists( $tmp ) ) {
					unlink( $tmp );
				}
				return new WP_Error( 'cmp_download_failed', __( 'Nu am putut descărca pachetul de update de pe GitHub.', 'consent-manager-pro' ) );
			}
			if ( filesize( $tmp ) > self::MAX_DOWNLOAD ) {
				unlink( $tmp );
				return new WP_Error( 'cmp_download_too_large', __( 'Pachetul de update depășește dimensiunea maximă permisă.', 'consent-manager-pro' ) );
			}
			return $tmp;
		}

		// Step 2b: rare direct 200 from api.github.com — the body is the package.
		if ( 200 === $code ) {
			$body = wp_remote_retrieve_body( $auth );
			if ( '' !== $body && strlen( $body ) <= self::MAX_DOWNLOAD && false !== file_put_contents( $tmp, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				return $tmp;
			}
		}

		if ( file_exists( $tmp ) ) {
			unlink( $tmp );
		}
		return new WP_Error( 'cmp_download_failed', __( 'Nu am putut descărca pachetul de update de pe GitHub.', 'consent-manager-pro' ) );
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
