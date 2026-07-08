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
