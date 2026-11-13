<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Allow standalone loading in tests (no WP). Guard only WP-dependent methods.
}

class CMP_Installer {

	public static function default_categories() {
		return array(
			array(
				'slug'         => 'necessary',
				'label'        => 'Necesare',
				'description'  => 'Cookie-uri esențiale pentru funcționarea site-ului.',
				'required'     => true,
				'gtag_signals' => array( 'security_storage' ),
				'cookies'      => array(),
			),
			array(
				'slug'         => 'statistics',
				'label'        => 'Statistici',
				'description'  => 'Ne ajută să înțelegem cum este folosit site-ul.',
				'required'     => false,
				'gtag_signals' => array( 'analytics_storage' ),
				'cookies'      => array(),
			),
			array(
				'slug'         => 'marketing',
				'label'        => 'Marketing',
				'description'  => 'Folosite pentru publicitate relevantă.',
				'required'     => false,
				'gtag_signals' => array( 'ad_storage', 'ad_user_data', 'ad_personalization' ),
				'cookies'      => array(),
			),
		);
	}

	public static function default_settings() {
		return array(
			'position'         => 'bottom',
			'layout'           => 'detailed',
			'preset'           => 'neutral',
			'policy_version'   => '1',
			'retention_months' => 13,
			'privacy_page_id'  => 0,
			'terms_page_id'    => 0,
			'cookie_page_id'   => 0,
			'block_scripts'    => true,
		);
	}

	public static function install() {
		global $wpdb;

		add_option( 'cmp_categories', self::default_categories() );
		add_option( 'cmp_settings', self::default_settings() );

		$table   = $wpdb->prefix . 'cmp_consent_log';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ts DATETIME NOT NULL,
			decision VARCHAR(20) NOT NULL,
			categories VARCHAR(255) NOT NULL,
			lang VARCHAR(8) NOT NULL,
			ip_hash CHAR(64) NOT NULL,
			ua_hash CHAR(64) NOT NULL,
			url VARCHAR(500) NOT NULL,
			version VARCHAR(20) NOT NULL,
			PRIMARY KEY  (id),
			KEY ts (ts),
			KEY decision (decision),
			KEY ip_hash (ip_hash)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
