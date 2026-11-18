<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMP_Plugin {

	/** @var CMP_Plugin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	private function boot() {
		require_once CMP_PATH . 'includes/class-installer.php';
		require_once CMP_PATH . 'includes/class-consent.php';
		require_once CMP_PATH . 'includes/class-sanitizer.php';
		require_once CMP_PATH . 'includes/class-services.php';
		require_once CMP_PATH . 'includes/modules/class-banner.php';
		require_once CMP_PATH . 'includes/modules/class-audit.php';

		load_plugin_textdomain( 'consent-manager-pro', false, dirname( plugin_basename( CMP_PATH ) ) . '/languages' );

		new CMP_Banner();
		CMP_Audit::register();
		require_once CMP_PATH . 'includes/modules/class-legal.php';
		CMP_Legal::register();
		require_once CMP_PATH . 'includes/modules/class-i18n.php';
		CMP_I18n::register();
		require_once CMP_PATH . 'includes/modules/class-updater.php';
		CMP_Updater::register();
		require_once CMP_PATH . 'includes/modules/class-blocker.php';
		CMP_Blocker::register();
		require_once CMP_PATH . 'includes/modules/class-fonts.php';
		CMP_Fonts::register();
		require_once CMP_PATH . 'includes/modules/class-lang.php';
		if ( is_admin() ) {
			require_once CMP_PATH . 'includes/admin/class-admin.php';
			new CMP_Admin();
		}

		// Plugin updates do NOT fire the activation hook, so refresh the schema
		// (create the log table / migrate columns) whenever the version changes.
		// Runs on every load but the version gate makes dbDelta fire only once.
		self::maybe_upgrade();
	}

	/** Run the installer (idempotent dbDelta + add_option) once per version change. */
	public static function maybe_upgrade() {
		$old = get_option( 'cmp_db_version' );
		if ( $old === CMP_VERSION ) {
			return;
		}
		require_once CMP_PATH . 'includes/class-installer.php';
		CMP_Installer::install();
		// 0.3.4: the floating reopen button is off by default (a footer link replaces
		// it). It used to default to on, so switch it off once on existing installs.
		if ( false === $old || version_compare( $old, '0.3.4', '<' ) ) {
			$settings = get_option( 'cmp_settings', array() );
			if ( is_array( $settings ) && ! empty( $settings['reopen_button'] ) ) {
				$settings['reopen_button'] = false;
				update_option( 'cmp_settings', $settings );
			}
		}
		// 0.4.0: blocking trackers until consent is on by default (Consent Mode
		// "basic": no Google/Meta request at all before Accept). Turn it on once
		// on existing installs, where it used to default to off.
		if ( false === $old || version_compare( $old, '0.4.0', '<' ) ) {
			$settings = get_option( 'cmp_settings', array() );
			if ( is_array( $settings ) && empty( $settings['block_scripts'] ) ) {
				$settings['block_scripts'] = true;
				update_option( 'cmp_settings', $settings );
			}
		}
		// 0.4.1: updates come only through the signed relay, so drop any GitHub
		// token still stored on the site (and the unused "layout" setting).
		if ( false === $old || version_compare( $old, '0.4.1', '<' ) ) {
			$settings = get_option( 'cmp_settings', array() );
			if ( is_array( $settings ) && ( array_key_exists( 'github_token', $settings ) || array_key_exists( 'layout', $settings ) ) ) {
				unset( $settings['github_token'], $settings['layout'] );
				update_option( 'cmp_settings', $settings );
			}
		}
		// 0.4.1: consent lasts at most 13 months; cap longer stored durations.
		if ( false === $old || version_compare( $old, '0.4.1', '<' ) ) {
			$settings = get_option( 'cmp_settings', array() );
			if ( is_array( $settings ) && isset( $settings['cookie_days'] ) ) {
				$capped = CMP_Sanitizer::cap_cookie_days( $settings['cookie_days'] );
				if ( $capped !== (int) $settings['cookie_days'] ) {
					$settings['cookie_days'] = $capped;
					update_option( 'cmp_settings', $settings );
				}
			}
		}
		update_option( 'cmp_db_version', CMP_VERSION );
	}

	/**
	 * Asset version = file mtime (busts cache on every change), CMP_VERSION as fallback.
	 *
	 * @param string $relative Path relative to the plugin root, e.g. "assets/css/banner.css".
	 * @return string
	 */
	public static function asset_ver( $relative ) {
		$mtime = @filemtime( CMP_PATH . ltrim( $relative, '/' ) );
		return $mtime ? (string) $mtime : CMP_VERSION;
	}

	public static function activate() {
		require_once CMP_PATH . 'includes/class-installer.php';
		CMP_Installer::install();
		update_option( 'cmp_db_version', CMP_VERSION );
	}
}
