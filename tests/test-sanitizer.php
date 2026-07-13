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
cmp_assert_eq( $s['position'], 'bottom', 'invalid position falls back to bottom' );
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

$tk = CMP_Sanitizer::settings( array( 'position' => 'bottom', 'github_token' => "  ghp_abc<script>123  " ) );
cmp_assert_eq( $tk['github_token'], 'ghp_abc123', 'github_token stripped + trimmed' );
$tk2 = CMP_Sanitizer::settings( array( 'position' => 'bottom' ) );
cmp_assert_eq( $tk2['github_token'], '', 'missing github_token => empty string' );

$bs1 = CMP_Sanitizer::settings( array( 'position' => 'bottom', 'block_scripts' => '1' ) );
cmp_assert_eq( $bs1['block_scripts'], true, 'block_scripts truthy -> true' );
$bs2 = CMP_Sanitizer::settings( array( 'position' => 'bottom' ) );
cmp_assert_eq( $bs2['block_scripts'], false, 'block_scripts missing -> false' );

$lf1 = CMP_Sanitizer::settings( array( 'position' => 'bottom', 'localize_fonts' => '1' ) );
cmp_assert_eq( $lf1['localize_fonts'], true, 'localize_fonts truthy -> true' );
$lf2 = CMP_Sanitizer::settings( array( 'position' => 'bottom' ) );
cmp_assert_eq( $lf2['localize_fonts'], false, 'localize_fonts missing -> false' );
