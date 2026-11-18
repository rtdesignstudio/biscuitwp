<?php
require_once __DIR__ . '/../includes/class-services.php';

cmp_assert_eq( CMP_Services::merge_seen( array( 'a', 'b' ), array( 'b', 'c' ) ), array( 'a', 'b', 'c' ), 'merge_seen: union, seen order kept' );
cmp_assert_eq( CMP_Services::merge_seen( array( 'a', 'b' ), array() ), array( 'a', 'b' ), 'merge_seen: disappearing from scan removes nothing' );
cmp_assert_eq( CMP_Services::merge_seen( array(), array( 'x', 'x' ) ), array( 'x' ), 'merge_seen: no duplicates' );

$cmp_active = CMP_Services::active();
cmp_assert_eq(
	CMP_Services::svc_by_cat( $cmp_active, array( 'google-analytics', 'meta-pixel', 'recaptcha', 'tiktok-pixel' ) ),
	array( 'statistics' => array( 'google-analytics' ), 'marketing' => array( 'meta-pixel', 'tiktok-pixel' ) ),
	'svc_by_cat: seen library services grouped by category, necessary skipped'
);
cmp_assert_eq( CMP_Services::svc_by_cat( $cmp_active, array() ), array(), 'svc_by_cat: nothing seen -> empty' );

$cmp_ex = array( array( 'id' => 'e1', 'category' => 'marketing', 'exempt' => true, 'source' => 'library' ) );
cmp_assert_eq( CMP_Services::svc_by_cat( $cmp_ex, array( 'e1' ) ), array(), 'svc_by_cat: exempt service missing' );

$cmp_cu = array( array( 'id' => 'custom-1', 'category' => 'marketing', 'exempt' => false, 'source' => 'custom' ) );
cmp_assert_eq( CMP_Services::svc_by_cat( $cmp_cu, array() ), array( 'marketing' => array( 'custom-1' ) ), 'svc_by_cat: custom service present without being seen' );

cmp_assert_eq( CMP_Services::history_or_found( array( 'a' ), array( 'b' ) ), array( 'a' ), 'history_or_found: array history wins' );
cmp_assert_eq( CMP_Services::history_or_found( false, array( 'b' ) ), array( 'b' ), 'history_or_found: no history -> found' );
cmp_assert_eq( CMP_Services::history_or_found( false, false ), null, 'history_or_found: neither -> null' );
cmp_assert_eq( CMP_Services::history_or_found( 1, array( 'b' ) ), array( 'b' ), 'history_or_found: non-array history -> found' );
cmp_assert_eq( CMP_Services::history_or_found( array(), false ), array(), 'history_or_found: empty array history is a known (empty) list' );
