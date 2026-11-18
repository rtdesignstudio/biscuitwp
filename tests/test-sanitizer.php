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
cmp_assert_eq( array_keys( $cmp_meta ), array( 'company', 'contact_email', 'dpo', 'jurisdiction' ), 'exactly the four operator fields' );
