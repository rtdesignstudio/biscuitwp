<?php
require_once __DIR__ . '/../includes/class-sanitizer.php';

$in = array(
	'position'         => 'hacker',
	'layout'           => 'detailed',
	'preset'           => 'egov',
	'policy_version'   => '3',
	'retention_months' => '0',
	'privacy_page_id'  => '12',
	'terms_page_id'    => '-5',
	'cookie_page_id'   => 'abc',
);
$s = CMP_Sanitizer::settings( $in );
cmp_assert_eq( $s['position'], 'middle-center', 'invalid position falls back to middle-center' );
cmp_assert_eq( $s['preset'], 'egov', 'valid preset kept' );
cmp_assert_eq( $s['policy_version'], '3', 'policy_version kept' );
cmp_assert_eq( $s['retention_months'], 13, 'retention < 1 falls back to 13' );
cmp_assert_eq( $s['privacy_page_id'], 12, 'privacy_page_id cast to int' );
cmp_assert_eq( $s['terms_page_id'], 0, 'negative page id clamped to 0' );
cmp_assert_eq( $s['cookie_page_id'], 0, 'non-numeric page id becomes 0' );

$cats_in = array(
	array(
		'slug'         => 'Stat istics!',
		'label'        => '  <b>Statistici</b>  ',
		'description'  => 'desc <script>x</script>',
		'required'     => '',
		'gtag_signals' => array( 'analytics_storage', 'evil_signal' ),
		'cookies'      => array(
			array( 'name' => '_ga', 'provider' => 'Google', 'duration' => '2 ani', 'purpose' => 'x' ),
		),
	),
	array( 'slug' => '', 'label' => 'no slug' ), // dropped
);
$c = CMP_Sanitizer::categories( $cats_in );
cmp_assert_eq( count( $c ), 1, 'category without slug is dropped' );
cmp_assert_eq( $c[0]['slug'], 'statistics', 'slug lowercased and stripped to [a-z0-9_]' );
cmp_assert_eq( $c[0]['label'], 'Statistici', 'label stripped of tags and trimmed' );
cmp_assert_eq( $c[0]['required'], false, 'required coerced to bool false' );
cmp_assert_eq( $c[0]['gtag_signals'], array( 'analytics_storage' ), 'unknown signal removed' );
cmp_assert_eq( $c[0]['cookies'][0]['name'], '_ga', 'cookie name kept' );

$st = CMP_Sanitizer::settings( array(
	'position' => 'bottom',
	'texts'    => array(
		'title'   => '  <b>Titlu</b>  ',
		'accept'  => 'Accept tot',
		'evilkey' => 'nope',
	),
) );
cmp_assert( isset( $st['texts'] ), 'settings returns texts array' );
cmp_assert_eq( $st['texts']['title'], 'Titlu', 'text stripped + trimmed' );
cmp_assert_eq( $st['texts']['accept'], 'Accept tot', 'known text key kept' );
cmp_assert( ! isset( $st['texts']['evilkey'] ), 'unknown text key dropped' );
cmp_assert( array_key_exists( 'reject', $st['texts'] ), 'all known text keys present (empty string if unset)' );

$m1 = CMP_Sanitizer::settings( array( 'position' => 'bottom', 'mode' => 'light' ) );
cmp_assert_eq( $m1['mode'], 'light', 'valid mode light kept' );
$m2 = CMP_Sanitizer::settings( array( 'position' => 'bottom', 'mode' => 'hacker' ) );
cmp_assert_eq( $m2['mode'], 'pro', 'invalid mode falls back to pro' );
$m3 = CMP_Sanitizer::settings( array( 'position' => 'bottom' ) );
cmp_assert_eq( $m3['mode'], 'pro', 'missing mode defaults to pro' );

$lt = CMP_Sanitizer::settings( array( 'position' => 'bottom', 'texts' => array( 'light_intro' => '  <i>Notice</i> light  ' ) ) );
cmp_assert( array_key_exists( 'light_intro', $lt['texts'] ), 'light_intro key present in texts' );
cmp_assert_eq( $lt['texts']['light_intro'], 'Notice light', 'light_intro stripped + trimmed' );

// 0.4.1: updates come only through the signed relay; no GitHub token is stored,
// and the unused "layout" setting is gone.
$tk = CMP_Sanitizer::settings( array( 'position' => 'bottom', 'github_token' => 'ghp_abc', 'layout' => 'compact' ) );
cmp_assert( ! array_key_exists( 'github_token', $tk ), 'github_token is no longer a setting' );
cmp_assert( ! array_key_exists( 'layout', $tk ), 'layout is no longer a setting' );

$bs1 = CMP_Sanitizer::settings( array( 'position' => 'bottom', 'block_scripts' => '1' ) );
cmp_assert_eq( $bs1['block_scripts'], true, 'block_scripts truthy -> true' );
$bs2 = CMP_Sanitizer::settings( array( 'position' => 'bottom' ) );
cmp_assert_eq( $bs2['block_scripts'], false, 'block_scripts missing -> false' );

$lf1 = CMP_Sanitizer::settings( array( 'position' => 'bottom', 'localize_fonts' => '1' ) );
cmp_assert_eq( $lf1['localize_fonts'], true, 'localize_fonts truthy -> true' );
$lf2 = CMP_Sanitizer::settings( array( 'position' => 'bottom' ) );
cmp_assert_eq( $lf2['localize_fonts'], false, 'localize_fonts missing -> false' );

// new positions accepted; legacy still accepted; invalid -> middle-center
cmp_assert_eq( CMP_Sanitizer::settings( array( 'position' => 'bottom-left' ) )['position'], 'bottom-left', 'new position kept' );
cmp_assert_eq( CMP_Sanitizer::settings( array( 'position' => 'modal-center' ) )['position'], 'modal-center', 'legacy position kept' );
cmp_assert_eq( CMP_Sanitizer::settings( array( 'position' => 'nope' ) )['position'], 'middle-center', 'invalid position -> middle-center' );
// cookie_days clamps
cmp_assert_eq( CMP_Sanitizer::settings( array( 'cookie_days' => 90 ) )['cookie_days'], 90, 'cookie_days kept' );
cmp_assert_eq( CMP_Sanitizer::settings( array() )['cookie_days'], 180, 'cookie_days default 180' );
cmp_assert_eq( CMP_Sanitizer::settings( array( 'cookie_days' => 0 ) )['cookie_days'], 180, 'cookie_days 0 -> 180' );
// 0.4.1: consent lasts at most 13 months (396 days); longer values are capped, not reset.
cmp_assert_eq( CMP_Sanitizer::settings( array( 'cookie_days' => 99999 ) )['cookie_days'], 396, 'cookie_days over 13 months -> 396' );
cmp_assert_eq( CMP_Sanitizer::settings( array( 'cookie_days' => 3650 ) )['cookie_days'], 396, 'cookie_days 10 years -> 396' );
cmp_assert_eq( CMP_Sanitizer::settings( array( 'cookie_days' => 396 ) )['cookie_days'], 396, 'cookie_days 396 kept' );
cmp_assert_eq( CMP_Sanitizer::cap_cookie_days( 730 ), 396, 'cap_cookie_days caps stored values (migration)' );
cmp_assert_eq( CMP_Sanitizer::cap_cookie_days( 180 ), 180, 'cap_cookie_days keeps valid values' );
cmp_assert_eq( CMP_Sanitizer::cap_cookie_days( -5 ), 180, 'cap_cookie_days: invalid -> 180' );
cmp_assert_eq( CMP_Sanitizer::MAX_COOKIE_DAYS, 396, 'MAX_COOKIE_DAYS is 13 months' );

// reload_on_decision: off by default, on only when ticked
cmp_assert_eq( CMP_Sanitizer::settings( array() )['reload_on_decision'], false, 'reload_on_decision default off' );
cmp_assert_eq( CMP_Sanitizer::settings( array( 'reload_on_decision' => '1' ) )['reload_on_decision'], true, 'reload_on_decision ticked' );

// 0.4.2: legal pages live on the "Pagini legale" screen. Saving the main settings
// must keep the page links and the operator data it does not post.
$cmp_old = array( 'privacy_page_id' => 12, 'terms_page_id' => 0, 'cookie_page_id' => 14, 'legal_meta' => array( 'company' => 'Vin de Autor' ) );
$cmp_kept = CMP_Sanitizer::keep_legal( CMP_Sanitizer::settings( array( 'position' => 'bottom' ) ), $cmp_old );
cmp_assert_eq( $cmp_kept['privacy_page_id'], 12, 'settings save keeps the privacy page' );
cmp_assert_eq( $cmp_kept['cookie_page_id'], 14, 'settings save keeps the cookie page' );
cmp_assert_eq( $cmp_kept['legal_meta']['company'], 'Vin de Autor', 'settings save keeps the operator data' );
cmp_assert_eq( CMP_Sanitizer::keep_legal( array( 'position' => 'bottom' ), array() )['privacy_page_id'], 0, 'nothing stored -> 0' );

// Operator data is stored, sanitized.
$cmp_meta = CMP_Sanitizer::legal_meta( array( 'company' => ' Vin <b>de</b> Autor ', 'contact_email' => 'not an email', 'dpo' => 'Ion', 'jurisdiction' => 'Republica Moldova', 'x' => 'y' ) );
cmp_assert_eq( $cmp_meta['company'], 'Vin de Autor', 'company stripped and trimmed' );
cmp_assert_eq( $cmp_meta['contact_email'], '', 'invalid email dropped' );
cmp_assert_eq( CMP_Sanitizer::legal_meta( array( 'contact_email' => 'a@b.md' ) )['contact_email'], 'a@b.md', 'valid email kept' );
cmp_assert( ! array_key_exists( 'x', $cmp_meta ), 'unknown keys dropped' );
cmp_assert_eq( array_keys( $cmp_meta ), array( 'company', 'contact_email', 'dpo', 'jurisdiction', 'address' ), 'exactly the five operator fields' );

// ---- Task 4: services / normalize_host ----
cmp_assert_eq( CMP_Sanitizer::normalize_host( ' https://Chat.IO/ ' ), 'chat.io', 'normalize_host: scheme, case, trailing slash' );
cmp_assert_eq( CMP_Sanitizer::normalize_host( 'chat.io/widget?x=1' ), 'chat.io/widget', 'normalize_host: strips query' );
cmp_assert_eq( CMP_Sanitizer::normalize_host( 'chat.io/widget#a' ), 'chat.io/widget', 'normalize_host: strips fragment' );
cmp_assert_eq( CMP_Sanitizer::normalize_host( 'javascript:alert(1)' ), '', 'normalize_host: javascript: rejected' );
cmp_assert_eq( CMP_Sanitizer::normalize_host( 'data:text/html,x' ), '', 'normalize_host: data: rejected' );
cmp_assert_eq( CMP_Sanitizer::normalize_host( 'a b.com' ), '', 'normalize_host: inner space rejected' );
cmp_assert_eq( CMP_Sanitizer::normalize_host( '' ), '', 'normalize_host: empty' );
cmp_assert_eq( CMP_Sanitizer::normalize_host( 'localhost' ), '', 'normalize_host: no dot rejected' );

$cmp_ids = array( 'clarity', 'hotjar' );
$cmp_sl  = array( 'necessary', 'statistics', 'marketing' );
$cmp_sv  = CMP_Sanitizer::services(
	array( 'overrides' => array(
		'clarity' => array( 'category' => 'Statistics', 'exempt' => '1', 'junk' => 'x' ),
		'nope'    => array( 'category' => 'marketing' ),
	) ),
	$cmp_ids, $cmp_sl, false, array()
);
cmp_assert_eq( $cmp_sv['overrides'], array( 'clarity' => array( 'category' => 'statistics', 'exempt' => true ) ), 'override kept, unknown id and extra keys dropped' );
cmp_assert_eq( $cmp_sv['custom'], array(), 'no custom' );

$cmp_old = array( 'custom' => array(
	array( 'id' => 'custom-3', 'name' => 'Old', 'category' => 'marketing', 'exempt' => false, 'hosts' => array( 'old.io' ), 'cookies' => array(), 'script' => '<script>old</script>' ),
	array( 'id' => 'custom-5', 'name' => 'Gone', 'category' => 'marketing', 'exempt' => false, 'hosts' => array( 'gone.io' ), 'cookies' => array(), 'script' => '' ),
) );
$cmp_in = array( 'custom' => array(
	array( 'id' => 'custom-3', 'name' => ' <b>Old2</b> ', 'category' => 'foo', 'exempt' => '', 'hosts' => array( 'https://Old.io/', 'javascript:x', 'bb.io/p?q=1' ), 'cookies' => array( array( 'name' => '<i>c</i>', 'duration' => '1d', 'purpose' => 'p' ), array( 'name' => '', 'duration' => 'x', 'purpose' => 'y' ) ), 'script' => '<script>new</script>' ),
	array( 'id' => 'custom-99', 'name' => 'New', 'category' => 'statistics', 'hosts' => array( 'new.io' ), 'script' => '<script>n</script>' ),
	array( 'id' => '', 'name' => '', 'hosts' => array( 'javascript:1' ) ),
) );
$cmp_sv = CMP_Sanitizer::services( $cmp_in, $cmp_ids, $cmp_sl, false, $cmp_old );
cmp_assert_eq( count( $cmp_sv['custom'] ), 2, 'empty custom dropped, absent old one deleted' );
$c0 = $cmp_sv['custom'][0];
cmp_assert_eq( $c0['id'], 'custom-3', 'existing id preserved' );
cmp_assert_eq( $c0['name'], 'Old2', 'name stripped and trimmed' );
cmp_assert_eq( $c0['category'], 'marketing', 'unknown custom category -> marketing' );
cmp_assert_eq( $c0['exempt'], false, 'exempt bool' );
cmp_assert_eq( $c0['hosts'], array( 'old.io' ), 'scripted service keeps old hosts (I1)' );
cmp_assert_eq( CMP_Sanitizer::services( array( 'custom' => array( array( 'name' => 'N', 'hosts' => array( 'https://Old.io/', 'javascript:x', 'bb.io/p?q=1' ) ) ) ), array(), array(), false, array() )['custom'][0]['hosts'], array( 'old.io', 'bb.io/p' ), 'hosts normalized, invalid dropped' );
cmp_assert_eq( $c0['cookies'], array( array( 'name' => 'c', 'duration' => '1d', 'purpose' => 'p' ) ), 'cookies sanitized, nameless dropped, plain strings' );
cmp_assert_eq( $c0['script'], '<script>old</script>', 'no can_script: script from $old kept' );
cmp_assert_eq( $cmp_sv['custom'][1]['id'], 'custom-100', 'unknown incoming id -> next free (max of old+incoming + 1)' );
cmp_assert_eq( $cmp_sv['custom'][1]['script'], '', 'no can_script and no old: empty script' );
$cmp_sv = CMP_Sanitizer::services( $cmp_in, $cmp_ids, $cmp_sl, true, $cmp_old );
cmp_assert_eq( $cmp_sv['custom'][0]['script'], '<script>new</script>', 'can_script: raw script stored' );
$cmp_sv = CMP_Sanitizer::services( array( 'custom' => array( array( 'name' => 'A', 'hosts' => array( 'a.io' ) ) ) ), array(), array(), false, $cmp_old );
cmp_assert_eq( $cmp_sv['custom'][0]['id'], 'custom-6', 'next id is max(old)+1' );
cmp_assert_eq( $cmp_sv['custom'][0]['category'], 'marketing', 'missing category -> marketing' );
$cmp_many = array();
for ( $i = 0; $i < 60; $i++ ) {
	$cmp_many[] = array( 'name' => 'S' . $i, 'hosts' => array( 'h' . $i . '.io' ) );
}
cmp_assert_eq( count( CMP_Sanitizer::services( array( 'custom' => $cmp_many ), array(), array(), false, array() )['custom'] ), 50, 'custom services capped at 50' );
$cmp_h = array();
for ( $i = 0; $i < 30; $i++ ) {
	$cmp_h[] = 'h' . $i . '.io';
}
$cmp_ck = array();
for ( $i = 0; $i < 40; $i++ ) {
	$cmp_ck[] = array( 'name' => 'c' . $i );
}
$cmp_cap = CMP_Sanitizer::services( array( 'custom' => array( array( 'name' => str_repeat( 'x', 150 ), 'hosts' => $cmp_h, 'cookies' => $cmp_ck ) ) ), array(), array(), false, array() )['custom'][0];
cmp_assert_eq( count( $cmp_cap['hosts'] ), 20, 'hosts capped at 20' );
cmp_assert_eq( count( $cmp_cap['cookies'] ), 30, 'cookies capped at 30' );
cmp_assert_eq( strlen( $cmp_cap['name'] ), 100, 'name capped at 100' );

// ---- Task 4 fix round 1 ----
// I1: no unfiltered_html -> a stored script keeps its old category/exempt/hosts.
$cmp_o1 = array( 'seq' => 2, 'custom' => array(
	array( 'id' => 'custom-1', 'name' => 'A', 'category' => 'marketing', 'exempt' => false, 'hosts' => array( 'a.io' ), 'cookies' => array(), 'script' => '<script>A</script>' ),
	array( 'id' => 'custom-2', 'name' => 'B', 'category' => 'statistics', 'exempt' => false, 'hosts' => array( 'b.io' ), 'cookies' => array(), 'script' => '<script>B</script>' ),
) );
$cmp_r = CMP_Sanitizer::services( array( 'custom' => array( array( 'id' => 'custom-1', 'name' => 'A2', 'category' => 'necessary', 'exempt' => '1', 'hosts' => array( 'evil.io' ), 'cookies' => array( array( 'name' => 'k' ) ) ) ) ), array(), $cmp_sl, false, $cmp_o1 );
cmp_assert_eq( $cmp_r['custom'][0]['category'], 'marketing', 'I1: scripted category stays' );
cmp_assert_eq( $cmp_r['custom'][0]['exempt'], false, 'I1: scripted exempt stays' );
cmp_assert_eq( $cmp_r['custom'][0]['hosts'], array( 'a.io' ), 'I1: scripted hosts stay' );
cmp_assert_eq( $cmp_r['custom'][0]['name'], 'A2', 'I1: name still editable' );
cmp_assert_eq( $cmp_r['custom'][0]['cookies'][0]['name'], 'k', 'I1: cookies still editable' );
cmp_assert_eq( $cmp_r['custom'][0]['script'], '<script>A</script>', 'I1: script kept' );
$cmp_r = CMP_Sanitizer::services( array( 'custom' => array(
	array( 'id' => 'custom-1', 'name' => 'B', 'category' => 'statistics', 'hosts' => array( 'b.io' ) ),
	array( 'id' => 'custom-2', 'name' => 'A', 'category' => 'marketing', 'hosts' => array( 'a.io' ) ),
) ), array(), $cmp_sl, false, $cmp_o1 );
cmp_assert_eq( array( $cmp_r['custom'][0]['script'], $cmp_r['custom'][0]['category'], $cmp_r['custom'][0]['hosts'] ), array( '<script>A</script>', 'marketing', array( 'a.io' ) ), 'I1: swap keeps script A with its category and hosts' );
cmp_assert_eq( array( $cmp_r['custom'][1]['script'], $cmp_r['custom'][1]['category'], $cmp_r['custom'][1]['hosts'] ), array( '<script>B</script>', 'statistics', array( 'b.io' ) ), 'I1: swap keeps script B with its category and hosts' );
$cmp_r = CMP_Sanitizer::services( array( 'custom' => array( array( 'id' => 'custom-1', 'name' => 'A2', 'category' => 'necessary', 'exempt' => '1', 'hosts' => array( 'new.io' ) ) ) ), array(), $cmp_sl, true, $cmp_o1 );
cmp_assert_eq( array( $cmp_r['custom'][0]['category'], $cmp_r['custom'][0]['exempt'], $cmp_r['custom'][0]['hosts'] ), array( 'necessary', true, array( 'new.io' ) ), 'I1: privileged user can change everything' );

// I2a
foreach ( array( 'co.uk', 'com.md', '1.2.3.4', 'gov.uk', 'com.ua' ) as $cmp_bad ) {
	cmp_assert_eq( CMP_Sanitizer::normalize_host( $cmp_bad ), '', "I2: {$cmp_bad} rejected" );
}
foreach ( array( 'x.com', 't.co', 'a.co' ) as $cmp_ok ) {
	cmp_assert_eq( CMP_Sanitizer::normalize_host( $cmp_ok ), $cmp_ok, "I2: {$cmp_ok} accepted" );
}
cmp_assert_eq( CMP_Sanitizer::normalize_host( 'foo.co.uk' ), 'foo.co.uk', 'I2: registrable domain under public suffix kept' );
// M3
cmp_assert_eq( CMP_Sanitizer::normalize_host( 'Chat.IO/Widget' ), 'chat.io/Widget', 'M3: path keeps case' );
// M1
foreach ( array( 'off', 'no', '', '0', 'false' ) as $cmp_f ) {
	cmp_assert_eq( CMP_Sanitizer::services( array( 'overrides' => array( 'clarity' => array( 'category' => 'marketing', 'exempt' => $cmp_f ) ) ), $cmp_ids, $cmp_sl, false, array() )['overrides']['clarity']['exempt'], false, "M1: '{$cmp_f}' is false" );
}
foreach ( array( true, 1, '1', 'on', 'true', 'yes' ) as $cmp_f ) {
	cmp_assert_eq( CMP_Sanitizer::services( array( 'overrides' => array( 'clarity' => array( 'category' => 'marketing', 'exempt' => $cmp_f ) ) ), $cmp_ids, $cmp_sl, false, array() )['overrides']['clarity']['exempt'], true, 'M1: ' . var_export( $cmp_f, true ) . ' is true' );
}
// M4
$cmp_big = CMP_Sanitizer::services( array( 'custom' => array( array( 'name' => 'A', 'hosts' => array( 'a.io' ), 'script' => str_repeat( 'x', 70000 ) ) ) ), array(), array(), true, array() );
cmp_assert_eq( strlen( $cmp_big['custom'][0]['script'] ), 65535, 'M4: script capped' );
// M5
$cmp_lib_cat = array( 'clarity' => 'marketing' );
$cmp_r = CMP_Sanitizer::services( array( 'overrides' => array( 'clarity' => array( 'category' => 'marketing', 'exempt' => '' ), 'hotjar' => array( 'category' => 'marketing', 'exempt' => '1' ) ) ), $cmp_ids, $cmp_sl, false, array(), array( 'clarity' => 'marketing', 'hotjar' => 'marketing' ) );
cmp_assert( ! isset( $cmp_r['overrides']['clarity'] ), 'M5: default-equal override skipped' );
cmp_assert( isset( $cmp_r['overrides']['hotjar'] ), 'M5: real override kept' );
// M6
$cmp_o6 = array( 'custom' => array( array( 'id' => 'custom-1', 'name' => 'A', 'hosts' => array( 'a.io' ) ), array( 'id' => 'custom-3', 'name' => 'C', 'hosts' => array( 'c.io' ) ) ) );
$cmp_r6 = CMP_Sanitizer::services( array( 'custom' => array( array( 'id' => 'custom-1', 'name' => 'A', 'hosts' => array( 'a.io' ) ) ) ), array(), array(), false, $cmp_o6 );
cmp_assert_eq( $cmp_r6['seq'], 3, 'M6: seq carried from max existing' );
$cmp_r6 = CMP_Sanitizer::services( array( 'custom' => array( array( 'id' => 'custom-1', 'name' => 'A', 'hosts' => array( 'a.io' ) ), array( 'name' => 'N', 'hosts' => array( 'n.io' ) ) ) ), array(), array(), false, $cmp_r6 );
cmp_assert_eq( $cmp_r6['custom'][1]['id'], 'custom-4', 'M6: deleted highest id not reused' );
cmp_assert_eq( $cmp_r6['seq'], 4, 'M6: seq updated' );

// B4 Task 5: operator address.
cmp_assert_eq( CMP_Sanitizer::legal_meta( array( 'address' => '  str. X <b>1</b> ' ) )['address'], 'str. X 1', 'address stripped and trimmed' );
cmp_assert_eq( strlen( CMP_Sanitizer::legal_meta( array( 'address' => str_repeat( 'a', 300 ) ) )['address'] ), 200, 'address capped at 200' );
cmp_assert_eq( CMP_Sanitizer::legal_meta( array() )['address'], '', 'address defaults to empty' );

// ---- B2 Task 1: three-state exempt override ----
$cmp_xd  = array( 'umami' => 'statistics', 'clarity' => 'statistics' );
$cmp_xe  = array( 'umami' => true );
$cmp_xs  = CMP_Sanitizer::services( array( 'overrides' => array( 'umami' => array( 'category' => '' ) ) ), array( 'umami', 'clarity' ), $cmp_sl, false, array(), $cmp_xd, '', $cmp_xe );
cmp_assert_eq( $cmp_xs['overrides'], array( 'umami' => array( 'exempt' => false ) ), 'exempt unchecked on an exempt_default service is stored as false' );
$cmp_xs  = CMP_Sanitizer::services( array( 'overrides' => array( 'umami' => array( 'category' => 'statistics', 'exempt' => '1' ) ) ), array( 'umami', 'clarity' ), $cmp_sl, false, array(), $cmp_xd, '', $cmp_xe );
cmp_assert_eq( $cmp_xs['overrides'], array(), 'row equal to the defaults (exempt on) is not stored' );
$cmp_xs  = CMP_Sanitizer::services( array( 'overrides' => array( 'clarity' => array( 'category' => 'statistics' ) ) ), array( 'umami', 'clarity' ), $cmp_sl, false, array(), $cmp_xd, '', $cmp_xe );
cmp_assert_eq( $cmp_xs['overrides'], array(), 'default row of a normal service is not stored' );
$cmp_xs  = CMP_Sanitizer::services( array( 'overrides' => array( 'clarity' => array( 'exempt' => '1' ) ) ), array( 'umami', 'clarity' ), $cmp_sl, false, array(), $cmp_xd, '', $cmp_xe );
cmp_assert_eq( $cmp_xs['overrides'], array( 'clarity' => array( 'exempt' => true ) ), 'exempt on a normal service still stored' );

// ---- B2 Task 2: measurement mode (Strict / Advanced) ----
$cmp_mm = CMP_Sanitizer::measurement( array(), array() );
cmp_assert_eq( $cmp_mm, array( 'value' => 'strict', 'error' => '' ), 'measurement: default strict' );
cmp_assert_eq( CMP_Sanitizer::measurement( array(), array( 'measurement' => 'advanced' ) ), array( 'value' => 'advanced', 'error' => '' ), 'measurement: field absent keeps the old value (advanced)' );
cmp_assert_eq( CMP_Sanitizer::measurement( array(), array( 'measurement' => 'strict' ) ), array( 'value' => 'strict', 'error' => '' ), 'measurement: field absent keeps the old value (strict)' );
cmp_assert_eq( CMP_Sanitizer::measurement( array( 'measurement' => 'advanced' ), array( 'measurement' => 'strict' ) ), array( 'value' => 'strict', 'error' => 'ack' ), 'measurement: advanced without ack from strict stays strict with ack error' );
cmp_assert_eq( CMP_Sanitizer::measurement( array( 'measurement' => 'advanced' ), array() ), array( 'value' => 'strict', 'error' => 'ack' ), 'measurement: advanced without ack, nothing stored, stays strict' );
cmp_assert_eq( CMP_Sanitizer::measurement( array( 'measurement' => 'advanced', 'measurement_ack' => '1' ), array( 'measurement' => 'strict' ) ), array( 'value' => 'advanced', 'error' => '' ), 'measurement: advanced with ack' );
cmp_assert_eq( CMP_Sanitizer::measurement( array( 'measurement' => 'advanced' ), array( 'measurement' => 'advanced' ) ), array( 'value' => 'advanced', 'error' => '' ), 'measurement: advanced already active, saved again without ack' );
cmp_assert_eq( CMP_Sanitizer::measurement( array( 'measurement' => 'strict' ), array( 'measurement' => 'advanced' ) ), array( 'value' => 'strict', 'error' => '' ), 'measurement: back to strict' );
cmp_assert_eq( CMP_Sanitizer::measurement( array( 'measurement' => 'hacker', 'measurement_ack' => '1' ), array( 'measurement' => 'advanced' ) ), array( 'value' => 'strict', 'error' => '' ), 'measurement: unknown value -> strict' );
cmp_assert_eq( CMP_Sanitizer::measurement( array( 'measurement' => array( 'advanced' ) ), array( 'measurement' => 'advanced' ) ), array( 'value' => 'strict', 'error' => '' ), 'measurement: non-string value -> strict' );
cmp_assert_eq( CMP_Sanitizer::measurement( array(), array( 'measurement' => 'junk' ) ), array( 'value' => 'strict', 'error' => '' ), 'measurement: absent field with junk old value -> strict' );
cmp_assert( ! array_key_exists( 'measurement_ack', CMP_Sanitizer::settings( array( 'measurement' => 'advanced', 'measurement_ack' => '1' ) ) ), 'measurement_ack is never stored' );

// A GA/GTM override locked by Advanced (its checkbox disabled, not posted) keeps its stored exempt value.
$cmp_lk_ids = array( 'google-analytics', 'google-tag-manager', 'meta-pixel' );
$cmp_lk_cat = array( 'google-analytics' => 'statistics', 'google-tag-manager' => 'statistics', 'meta-pixel' => 'marketing' );
$cmp_lk_old = array( 'overrides' => array( 'google-analytics' => array( 'exempt' => true ) ) );
$cmp_lk_in  = array( 'overrides' => array(
	'google-analytics'   => array( 'category' => 'statistics' ),
	'google-tag-manager' => array( 'category' => 'statistics', 'exempt' => '1' ),
	'meta-pixel'         => array( 'category' => 'marketing', 'exempt' => '1' ),
) );
$cmp_lk = CMP_Sanitizer::services( $cmp_lk_in, $cmp_lk_ids, $cmp_sl, false, $cmp_lk_old, $cmp_lk_cat, '', array(), array( 'google-analytics', 'google-tag-manager' ) );
cmp_assert_eq( $cmp_lk['overrides']['google-analytics'], array( 'category' => 'statistics', 'exempt' => true ), 'Advanced: a disabled GA override is preserved on Services save' );
cmp_assert( ! isset( $cmp_lk['overrides']['google-tag-manager'] ), 'Advanced: a posted GTM exempt is ignored, no stored override stays none' );
cmp_assert_eq( $cmp_lk['overrides']['meta-pixel'], array( 'category' => 'marketing', 'exempt' => true ), 'Advanced: other services still take the posted exempt' );
$cmp_lk = CMP_Sanitizer::services( $cmp_lk_in, $cmp_lk_ids, $cmp_sl, false, $cmp_lk_old, $cmp_lk_cat, '', array() );
cmp_assert( ! isset( $cmp_lk['overrides']['google-analytics'] ), 'Strict: GA exempt follows the posted form (unchecked)' );
cmp_assert_eq( $cmp_lk['overrides']['google-tag-manager'], array( 'category' => 'statistics', 'exempt' => true ), 'Strict: GTM exempt follows the posted form (checked)' );
