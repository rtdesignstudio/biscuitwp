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
