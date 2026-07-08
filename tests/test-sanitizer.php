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
