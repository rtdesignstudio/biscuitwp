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
		// WP hooks added in Task 2.
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
}
