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

// ---- B2 Task 2 fix 1: the measurement mode changes loading only, never consent tracking ----
require_once __DIR__ . '/../includes/class-consent.php';
$cmp_m_lib  = CMP_Services::library();
$cmp_m_seen = array( 'google-analytics', 'google-tag-manager', 'hotjar', 'meta-pixel' );
$cmp_m_st   = CMP_Services::all( $cmp_m_lib, array(), array() );
$cmp_m_adv  = CMP_Services::apply_measurement( $cmp_m_st, true );
cmp_assert_eq( CMP_Services::svc_by_cat( $cmp_m_adv, $cmp_m_seen ), CMP_Services::svc_by_cat( $cmp_m_st, $cmp_m_seen ), 'measurement: svc_by_cat equal in Advanced and Strict (GA blocked)' );
cmp_assert( in_array( 'google-analytics', CMP_Services::svc_by_cat( $cmp_m_adv, $cmp_m_seen )['statistics'], true ), 'measurement: GA stays in the re-consent map in Advanced' );
$cmp_m_stx  = CMP_Services::all( $cmp_m_lib, array( 'overrides' => array( 'google-analytics' => array( 'exempt' => true ) ) ), array() );
$cmp_m_advx = CMP_Services::apply_measurement( $cmp_m_stx, true );
cmp_assert_eq( CMP_Services::svc_by_cat( $cmp_m_advx, $cmp_m_seen ), CMP_Services::svc_by_cat( $cmp_m_stx, $cmp_m_seen ), 'measurement: svc_by_cat equal in Advanced and Strict (GA admin-exempt)' );
foreach ( $cmp_m_advx as $cmp_s ) {
	if ( 'google-analytics' === $cmp_s['id'] ) {
		cmp_assert( true === $cmp_s['exempt'] && true === $cmp_s['own_exempt'], 'apply_measurement: own_exempt keeps the admin value (true)' );
	}
}
foreach ( $cmp_m_adv as $cmp_s ) {
	if ( 'google-analytics' === $cmp_s['id'] ) {
		cmp_assert( true === $cmp_s['exempt'] && false === $cmp_s['own_exempt'], 'apply_measurement: own_exempt keeps the admin value (false)' );
		cmp_assert( false === CMP_Services::consent_exempt( $cmp_s ), 'consent_exempt: mode-exempt GA still needs consent' );
	}
}

// Strict -> Advanced -> Strict round trip: a snapshot taken in Advanced still holds GA; nothing new after the switch back.
$cmp_m_snap   = CMP_Services::svc_by_cat( $cmp_m_adv, $cmp_m_seen );
$cmp_m_cookie = array( 'categories' => array( 'necessary', 'statistics', 'marketing' ), 'svc' => $cmp_m_snap );
cmp_assert( in_array( 'google-analytics', $cmp_m_snap['statistics'], true ), 'round trip: Advanced snapshot contains GA' );
cmp_assert_eq( CMP_Consent::effective_categories( $cmp_m_cookie, CMP_Services::svc_by_cat( $cmp_m_st, $cmp_m_seen ), array( 'necessary' ) ), array( 'necessary', 'statistics', 'marketing' ), 'round trip: back in Strict nothing is new, statistics kept' );
cmp_assert_eq( CMP_Consent::effective_categories( $cmp_m_cookie, CMP_Services::svc_by_cat( $cmp_m_adv, $cmp_m_seen ), array( 'necessary' ) ), array( 'necessary', 'statistics', 'marketing' ), 'round trip: in Advanced nothing is new either' );

// In Advanced, GA added to the history is new for earlier statistics acceptors.
$cmp_m_old    = array( 'hotjar', 'meta-pixel' );
$cmp_m_cookie = array( 'categories' => array( 'necessary', 'statistics' ), 'svc' => CMP_Services::svc_by_cat( $cmp_m_adv, $cmp_m_old ) );
$cmp_m_now    = CMP_Services::svc_by_cat( $cmp_m_adv, array_merge( $cmp_m_old, array( 'google-analytics' ) ) );
cmp_assert_eq( CMP_Consent::effective_categories( $cmp_m_cookie, $cmp_m_now, array( 'necessary' ) ), array( 'necessary' ), 'Advanced: GA added to history is new for earlier statistics acceptors' );

// newly_blocked and the admin-exempt list follow the consent rule too.
$cmp_m_cust = CMP_Services::apply_measurement( array( array( 'id' => 'google-analytics', 'category' => 'statistics', 'exempt' => false ) ), true );
cmp_assert_eq( CMP_Services::admin_exempt_measurement( $cmp_m_cust ), array(), 'admin_exempt_measurement: mode-exempt GA is not an admin exemption' );
cmp_assert_eq( CMP_Services::admin_exempt_measurement( $cmp_m_advx ), array( 'google-analytics' ), 'admin_exempt_measurement: admin-exempt GA listed in Advanced' );
cmp_assert_eq( CMP_Services::admin_exempt_measurement( $cmp_m_stx ), array( 'google-analytics' ), 'admin_exempt_measurement: admin-exempt GA listed in Strict' );
cmp_assert_eq( CMP_Services::admin_exempt_measurement( $cmp_m_st ), array(), 'admin_exempt_measurement: none by default' );
