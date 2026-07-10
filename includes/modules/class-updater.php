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
	const FILE = 'consent-manager-pro/consent-manager-pro.php';
	const SLUG = 'consent-manager-pro';

	const CACHE_KEY = 'cmp_latest_release';

	public static function register() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check' ) );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'authenticated_download' ), 10, 3 );
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
		$update->plugin      = self::FILE;
		$update->new_version = ltrim( $tag, 'vV' );
		$update->url         = isset( $release['html_url'] ) ? (string) $release['html_url'] : 'https://github.com/' . self::REPO;
		$update->package     = $package;
		return $update;
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
		if ( ! empty( $body['assets'][0]['url'] ) ) {
			$release['assets'][] = array( 'url' => $body['assets'][0]['url'] );
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
			$transient->response[ self::FILE ] = $update;
		}
		return $transient;
	}

	/** Download our package with the auth header (private repo assets/zipballs). */
	public static function authenticated_download( $reply, $package, $upgrader ) {
		if ( false !== $reply ) {
			return $reply;
		}
		if ( ! is_string( $package ) || false === strpos( $package, 'api.github.com/repos/' . self::REPO ) ) {
			return $reply;
		}

		$tmp      = wp_tempnam( 'cmp-update' );
		$response = wp_remote_get(
			$package,
			array(
				'timeout'  => 300,
				'stream'   => true,
				'filename' => $tmp,
				'headers'  => array(
					'Authorization' => 'Bearer ' . self::token(),
					'Accept'        => 'application/octet-stream',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			@unlink( $tmp );
			return new WP_Error( 'cmp_download_failed', __( 'Nu am putut descărca pachetul de update de pe GitHub.', 'consent-manager-pro' ) );
		}
		return $tmp;
	}

	/** GitHub zips extract as <slug>-<tag>/ or <owner>-<repo>-<sha>/ — rename to the plugin folder. */
	public static function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( ! is_string( $source ) || is_wp_error( $source ) ) {
			return $source;
		}
		$plugin = isset( $hook_extra['plugin'] ) ? $hook_extra['plugin'] : '';
		if ( self::FILE !== $plugin ) {
			return $source;
		}

		global $wp_filesystem;
		$basename = basename( untrailingslashit( $source ) );
		if ( self::SLUG === $basename ) {
			return $source;
		}
		$new_source = trailingslashit( dirname( untrailingslashit( $source ) ) ) . self::SLUG . '/';
		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $new_source ) ) ) {
			return $new_source;
		}
		return $source;
	}
}
