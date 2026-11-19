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


// 0.4.1: on the policy pages the banner must not block reading the policy.
$cmp_ps = array( 'privacy_page_id' => 12, 'cookie_page_id' => 14, 'terms_page_id' => 0 );
cmp_assert_eq( CMP_Consent::is_policy_page( 12, $cmp_ps, 0 ), true, 'privacy page from settings is a policy page' );
cmp_assert_eq( CMP_Consent::is_policy_page( 14, $cmp_ps, 0 ), true, 'cookie page is a policy page' );
cmp_assert_eq( CMP_Consent::is_policy_page( 3, $cmp_ps, 3 ), true, "WordPress' own privacy policy page counts" );
cmp_assert_eq( CMP_Consent::is_policy_page( 7, $cmp_ps, 3 ), false, 'any other page is not' );
cmp_assert_eq( CMP_Consent::is_policy_page( 0, $cmp_ps, 0 ), false, 'no page (archive, 404) is not, even with unset ids' );


// B2 task 3: Advanced forces the four ad/analytics signals to denied.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once __DIR__ . '/../includes/modules/class-banner.php';
$adv = CMP_Consent::advanced_defaults( array( 'analytics_storage' => 'granted', 'functionality_storage' => 'granted' ) );
cmp_assert_eq( $adv['analytics_storage'], 'denied', 'advanced_defaults: required analytics forced denied' );
cmp_assert_eq( $adv['functionality_storage'], 'granted', 'advanced_defaults: other signals unchanged' );
$adv = CMP_Consent::advanced_defaults( array() );
foreach ( array( 'analytics_storage', 'ad_storage', 'ad_user_data', 'ad_personalization' ) as $cmp_sig ) {
	cmp_assert_eq( $adv[ $cmp_sig ], 'denied', "advanced_defaults: empty set gets {$cmp_sig} denied" );
}
cmp_assert_eq( CMP_Banner::consent_default_payload( array(), false ), null, 'payload: Strict with no signals prints nothing' );
$pl = CMP_Banner::consent_default_payload( array(), true );
cmp_assert_eq( $pl['ad_user_data'], 'denied', 'payload: Advanced with no signals still prints, ad_user_data denied' );
cmp_assert_eq( $pl['security_storage'], 'granted', 'payload: Advanced security_storage granted' );
cmp_assert_eq( $pl['wait_for_update'], 500, 'payload: Advanced keeps wait_for_update' );
$req = CMP_Consent::build_default_signals( array( array( 'slug' => 'statistics', 'required' => true, 'gtag_signals' => array( 'analytics_storage' ) ) ) );
cmp_assert_eq( CMP_Banner::consent_default_payload( $req, false )['analytics_storage'], 'granted', 'payload: Strict keeps required analytics granted' );
cmp_assert_eq( CMP_Banner::consent_default_payload( $req, true )['analytics_storage'], 'denied', 'payload: Advanced forces required analytics denied' );
$strict = CMP_Banner::consent_default_payload( CMP_Consent::build_default_signals( $cats2 ), false );
cmp_assert_eq( $strict, array(
	'analytics_storage'       => 'denied',
	'ad_storage'              => 'denied',
	'ad_user_data'            => 'denied',
	'ad_personalization'      => 'denied',
	'functionality_storage'   => 'denied',
	'personalization_storage' => 'denied',
	'security_storage'        => 'granted',
	'wait_for_update'         => 500,
), 'payload: Strict equals the previous merge' );
