<?php
// B7.4: server side of the public API (cmp_is_allowed, CMP_DATA.pluginVersion, `custom` decision).
require_once __DIR__ . '/../includes/modules/class-audit.php';

$cmp_t_fn = file_get_contents( __DIR__ . '/../includes/functions.php' );
cmp_assert( false !== $cmp_t_fn, 'functions.php exists' );
$cmp_t_fn = (string) $cmp_t_fn;
cmp_assert( 1 === preg_match( '/if \( ! function_exists\( \'cmp_is_allowed\' \) \) \{\s*\/\*\*.*?function cmp_is_allowed\( string \$category \): bool \{/s', $cmp_t_fn ), 'cmp_is_allowed is declared behind function_exists with a typed signature' );
cmp_assert( false !== strpos( $cmp_t_fn, 'CMP_Consent::is_allowed( $category )' ), 'cmp_is_allowed delegates to CMP_Consent::is_allowed' );
cmp_assert( false !== strpos( (string) file_get_contents( __DIR__ . '/../includes/class-plugin.php' ), "includes/functions.php" ), 'class-plugin loads functions.php' );

$cmp_t_banner = (string) file_get_contents( __DIR__ . '/../includes/modules/class-banner.php' );
cmp_assert( 1 === preg_match( "/'pluginVersion'\s*=>\s*CMP_VERSION,/", $cmp_t_banner ), 'CMP_DATA.pluginVersion is CMP_VERSION' );
cmp_assert( 1 === preg_match( "/'version'\s*=>\s*CMP_Consent::current_version\(\),/", $cmp_t_banner ), 'CMP_DATA.version stays the policy version' );

cmp_assert( in_array( 'custom', CMP_Audit::DECISIONS, true ), 'custom is a known decision' );
$cmp_t_post = array( 'decision' => 'custom', 'categories' => '["necessary","statistics"]' );
$cmp_t_res  = CMP_Audit::parse_request( $cmp_t_post, array( 'necessary', 'statistics' ), '1', array() );
cmp_assert( is_array( $cmp_t_res ) && 'custom' === $cmp_t_res['decision'], 'parse_request accepts custom' );
cmp_assert_eq( CMP_Audit::parse_request( array( 'decision' => 'bogus', 'categories' => '[]' ), array( 'necessary' ), '1', array() ), 'bad_decision', 'parse_request still rejects an unknown decision' );
cmp_assert( false !== strpos( (string) file_get_contents( __DIR__ . '/../includes/admin/class-audit-page.php' ), "'custom'" ), 'audit page labels custom' );
