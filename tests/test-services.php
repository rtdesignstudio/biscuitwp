<?php
require_once __DIR__ . '/../includes/class-services.php';

$lib = CMP_Services::library();
cmp_assert_eq(
	array_slice( array_column( $lib, 'id' ), 0, 6 ),
	array( 'google-analytics', 'google-tag-manager', 'meta-pixel', 'youtube', 'vimeo', 'google-maps' ),
	'library starts with the six original services in order'
);
$cmp_expected_ids = array( 'google-analytics', 'google-tag-manager', 'meta-pixel', 'youtube', 'vimeo', 'google-maps', 'clarity', 'hotjar', 'tiktok-pixel', 'linkedin-insight', 'hubspot', 'tawk-to', 'crisp', 'recaptcha' );
$cmp_have_ids     = array_column( $lib, 'id' );
sort( $cmp_expected_ids );
sort( $cmp_have_ids );
cmp_assert_eq( $cmp_have_ids, $cmp_expected_ids, 'library holds exactly the 14 expected ids' );
cmp_assert_eq( CMP_Services::validate( array( 'services' => $lib ) ), array(), 'real library validates' );
foreach ( array( 'id', 'name', 'provider', 'type', 'category', 'hosts', 'inline', 'gtm', 'path', 'nocookie', 'cookies', 'transfer', 'privacy_url' ) as $k ) {
	cmp_assert( array_key_exists( $k, $lib[0] ), "service has key {$k}" );
}

$ok  = array( 'id' => 'a', 'type' => 'script', 'category' => 'statistics', 'hosts' => array( 'a.com' ) );
cmp_assert_eq( count( CMP_Services::validate( array( 'services' => array( $ok, $ok ) ) ) ), 1, 'duplicate id -> one error' );
cmp_assert_eq( count( CMP_Services::validate( array( 'services' => array( array_merge( $ok, array( 'hosts' => array( 'https://a.com' ) ) ) ) ) ) ), 1, 'host with :// -> one error' );
cmp_assert_eq( count( CMP_Services::validate( array( 'services' => array( array_merge( $ok, array( 'type' => 'x' ) ) ) ) ) ), 1, 'bad type -> one error' );

$r = CMP_Services::load_file( '/nu/exista.json' );
cmp_assert( array() === $r['services'] && ! empty( $r['errors'] ), 'missing file -> empty + error' );
$tmp = tempnam( sys_get_temp_dir(), 'cmp' );
file_put_contents( $tmp, '{' );
$r = CMP_Services::load_file( $tmp );
cmp_assert( array() === $r['services'] && ! empty( $r['errors'] ), 'invalid json -> empty + error' );
unlink( $tmp );

cmp_assert( true === CMP_Services::host_matches( 'https://www.google-analytics.com/a.js', 'google-analytics.com' ), 'host match subdomain' );
cmp_assert( false === CMP_Services::host_matches( 'https://evil.com/?u=google-analytics.com', 'google-analytics.com' ), 'host in query does not match' );
cmp_assert( true === CMP_Services::host_matches( 'https://www.googletagmanager.com/gtag/js?id=G', 'googletagmanager.com/gtag/js' ), 'host+path match' );
cmp_assert( false === CMP_Services::host_matches( 'https://www.googletagmanager.com/ns.html', 'googletagmanager.com/gtag/js' ), 'host+path mismatch' );

$m = CMP_Services::embed_service( 'https://www.google.com/maps/embed?pb=1', $lib );
cmp_assert( is_array( $m ) && 'google-maps' === $m['id'], 'maps embed matched' );
cmp_assert( null === CMP_Services::embed_service( 'https://www.google.com/search', $lib ), 'google search not an embed' );
$y = CMP_Services::embed_service( 'https://www.youtube.com/embed/x', $lib );
cmp_assert( is_array( $y ) && 'youtube' === $y['id'] && true === $y['nocookie'], 'youtube embed nocookie' );

$s = CMP_Services::script_service( 'https://www.google-analytics.com/analytics.js', '', $lib );
cmp_assert( is_array( $s ) && 'google-analytics' === $s['id'], 'GA script by src' );
$s = CMP_Services::script_service( 'https://www.googletagmanager.com/gtm.js?id=GTM-X', '', $lib );
cmp_assert( is_array( $s ) && 'google-tag-manager' === $s['id'], 'GTM script by src' );
$s = CMP_Services::script_service( '', "ga('create', 'UA-1')", $lib );
cmp_assert( is_array( $s ) && 'google-analytics' === $s['id'], 'GA inline marker' );
$s = CMP_Services::script_service( '', "(function(w){w.dataLayer=1;'GTM-ABC'})", $lib );
cmp_assert( is_array( $s ) && 'google-tag-manager' === $s['id'], 'GTM inline container' );
$s = CMP_Services::script_service( '', "fbq('init','1')", $lib );
cmp_assert( is_array( $s ) && 'meta-pixel' === $s['id'], 'Meta inline marker' );
cmp_assert( null === CMP_Services::script_service( 'https://www.youtube.com/x.js', '', $lib ), 'embed service not a script match' );
cmp_assert( null === CMP_Services::script_service( '', 'var a = 1;', $lib ), 'plain inline no match' );
