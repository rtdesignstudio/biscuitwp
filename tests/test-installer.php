<?php
require_once __DIR__ . '/../includes/class-installer.php';

$cats = CMP_Installer::default_categories();
cmp_assert_eq( count( $cats ), 3, 'default_categories returns 3 categories' );
cmp_assert_eq( $cats[0]['slug'], 'necessary', 'first category is necessary' );
cmp_assert_eq( $cats[0]['required'], true, 'necessary is required' );

$stat = null;
foreach ( $cats as $c ) {
	if ( 'statistics' === $c['slug'] ) {
		$stat = $c;
	}
}
cmp_assert( $stat !== null, 'statistics category exists' );
cmp_assert( in_array( 'analytics_storage', $stat['gtag_signals'], true ), 'statistics maps to analytics_storage' );

$settings = CMP_Installer::default_settings();
cmp_assert_eq( $settings['policy_version'], '1', 'default policy_version is 1' );
cmp_assert_eq( $settings['position'], 'bottom', 'default position is bottom' );
cmp_assert( ! array_key_exists( 'layout', $settings ), 'no unused layout default' );

// 0.4.0: tracker blocking is on by default on new installs.
$cmp_ds = CMP_Installer::default_settings();
cmp_assert( ! empty( $cmp_ds['block_scripts'] ), 'default_settings: block_scripts on' );

// 0.4.1: the log table stores a per-visitor consent ID (proof of consent).
$cmp_sql = CMP_Installer::log_table_sql( 'wp_cmp_consent_log', 'DEFAULT CHARSET=utf8mb4' );
cmp_assert( false !== strpos( $cmp_sql, 'consent_id CHAR(36)' ), 'log table has a consent_id column' );
cmp_assert( false !== strpos( $cmp_sql, 'KEY consent_id (consent_id)' ), 'consent_id is indexed' );
cmp_assert( false !== strpos( $cmp_sql, 'CREATE TABLE wp_cmp_consent_log' ), 'table name used' );
