<?php
require_once __DIR__ . '/../includes/class-consent.php';

$cats = array(
	array( 'slug' => 'necessary', 'required' => true, 'gtag_signals' => array() ),
	array( 'slug' => 'statistics', 'required' => false, 'gtag_signals' => array() ),
	array( 'slug' => 'marketing', 'required' => false, 'gtag_signals' => array() ),
);

// No cookie => only required allowed.
$r = CMP_Consent::evaluate( null, $cats, '1' );
cmp_assert_eq( $r['necessary'], true, 'no cookie: necessary allowed' );
cmp_assert_eq( $r['statistics'], false, 'no cookie: statistics denied' );

// Valid cookie, version match, partial accept.
$cookie = array( 'v' => '1', 'categories' => array( 'necessary', 'statistics' ), 'decision' => 'partial' );
$r = CMP_Consent::evaluate( $cookie, $cats, '1' );
cmp_assert_eq( $r['statistics'], true, 'partial: statistics allowed' );
cmp_assert_eq( $r['marketing'], false, 'partial: marketing denied' );

// Stale version => non-required denied even if listed.
$r = CMP_Consent::evaluate( $cookie, $cats, '2' );
cmp_assert_eq( $r['statistics'], false, 'stale version: statistics denied' );
cmp_assert_eq( $r['necessary'], true, 'stale version: necessary still allowed' );

$cats2 = array(
	array( 'slug' => 'necessary', 'required' => true, 'gtag_signals' => array( 'security_storage' ) ),
	array( 'slug' => 'statistics', 'required' => false, 'gtag_signals' => array( 'analytics_storage' ) ),
	array( 'slug' => 'marketing', 'required' => false, 'gtag_signals' => array( 'ad_storage' ) ),
);

$d = CMP_Consent::build_default_signals( $cats2 );
cmp_assert_eq( $d['security_storage'], 'granted', 'default: security_storage granted (required)' );
cmp_assert_eq( $d['analytics_storage'], 'denied', 'default: analytics_storage denied' );
cmp_assert_eq( $d['ad_storage'], 'denied', 'default: ad_storage denied' );

$u = CMP_Consent::build_update_signals( $cats2, array( 'statistics' ) );
cmp_assert_eq( $u['analytics_storage'], 'granted', 'update: analytics granted when statistics accepted' );
cmp_assert_eq( $u['ad_storage'], 'denied', 'update: ad_storage denied when marketing not accepted' );
cmp_assert_eq( $u['security_storage'], 'granted', 'update: security_storage always granted (required)' );

cmp_assert_eq( CMP_Consent::should_autoreject( array( 'DNT' => '1' ) ), true, 'DNT=1 => autoreject' );
cmp_assert_eq( CMP_Consent::should_autoreject( array( 'Sec-GPC' => '1' ) ), true, 'GPC=1 => autoreject' );
cmp_assert_eq( CMP_Consent::should_autoreject( array( 'DNT' => '0' ) ), false, 'DNT=0 => no autoreject' );
cmp_assert_eq( CMP_Consent::should_autoreject( array() ), false, 'no headers => no autoreject' );
