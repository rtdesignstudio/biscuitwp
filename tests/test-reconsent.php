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

// B4 Task 3: display names for every id of svcByCat (the "new" marker).
$cmp_lbl_svcs = array(
	array( 'id' => 'meta-pixel', 'name' => 'Meta Pixel', 'category' => 'marketing' ),
	array( 'id' => 'custom-1', 'name' => '', 'category' => 'marketing', 'source' => 'custom' ),
	array( 'id' => 'google-analytics', 'name' => 'Google Analytics', 'category' => 'statistics' ),
);
cmp_assert_eq(
	CMP_Services::svc_labels( $cmp_lbl_svcs, array( 'marketing' => array( 'meta-pixel', 'custom-1' ) ) ),
	array( 'meta-pixel' => 'Meta Pixel', 'custom-1' => 'custom-1' ),
	'svc_labels: names for the ids of svcByCat only, id when the name is empty'
);
cmp_assert_eq( CMP_Services::svc_labels( $cmp_lbl_svcs, array() ), array(), 'svc_labels: empty map -> empty' );

// Fix round 1 (I1): a category marked required is never "affected".
$cmp_req_svcs = array(
	array( 'id' => 'f1', 'category' => 'functional', 'source' => 'library' ),
	array( 'id' => 'm1', 'category' => 'marketing', 'source' => 'library' ),
	array( 'id' => 'n1', 'category' => 'necessary', 'source' => 'library' ),
);
cmp_assert_eq( CMP_Services::svc_by_cat( $cmp_req_svcs, array( 'f1', 'm1', 'n1' ), array( 'functional' ) ), array( 'marketing' => array( 'm1' ) ), 'svc_by_cat I1: a required category with a seen service is absent' );
cmp_assert_eq( CMP_Services::svc_by_cat( $cmp_req_svcs, array( 'f1', 'm1', 'n1' ) ), array( 'functional' => array( 'f1' ), 'marketing' => array( 'm1' ) ), 'svc_by_cat I1: without required slugs only necessary is omitted' );
cmp_assert_eq( CMP_Services::svc_by_cat( $cmp_req_svcs, array( 'f1', 'm1', 'n1' ), array( 'marketing' ) ), array( 'functional' => array( 'f1' ) ), 'svc_by_cat I1: necessary is always omitted, even when not listed as required' );
cmp_assert_eq( CMP_Services::required_slugs( array( array( 'slug' => 'necessary', 'required' => true ), array( 'slug' => 'functional', 'required' => 1 ), array( 'slug' => 'marketing', 'required' => false ), array( 'slug' => 'stats' ), 'junk' ) ), array( 'necessary', 'functional' ), 'required_slugs I1: slugs of the categories marked required' );
