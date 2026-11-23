<?php
/**
 * "Verifică-ți site-ul": is the checked site on WordPress? The plugin is for
 * WordPress, so the report says so for other sites (and points to standalone).
 */
$cmp_scp_lib = __DIR__ . '/../site/api/lib/check.php';
if ( ! is_file( $cmp_scp_lib ) ) {
	cmp_assert( false, 'site check platform: site/api/lib/check.php exists' );
	return;
}
if ( ! defined( 'CMP_CHECK' ) ) {
	define( 'CMP_CHECK', true );
}
require_once $cmp_scp_lib;

// Detection on raw HTML.
foreach ( array(
	'theme css'  => '<link rel="stylesheet" href="https://exemplu.md/wp-content/themes/x/style.css">',
	'core js'    => '<script src="/wp-includes/js/jquery/jquery.min.js"></script>',
	'generator'  => '<meta name="generator" content="WordPress 6.7.1">',
	'rest link'  => '<link rel="https://api.w.org/" href="https://exemplu.md/wp-json/">',
	'emoji'      => '<script>window._wpemojiSettings = {};</script>',
) as $label => $html ) {
	cmp_assert( true === cmp_check_is_wordpress( '<html><head>' . $html . '</head><body>x</body></html>' ), "platform: WordPress seen by {$label}" );
}
foreach ( array(
	'next.js'   => '<script src="/_next/static/chunks/main.js"></script><div id="__next">x</div>',
	'plain'     => '<html><body><p>Despre WordPress și altele</p></body></html>',
	'generator' => '<meta name="generator" content="Hugo 0.120">',
) as $label => $html ) {
	cmp_assert( false === cmp_check_is_wordpress( $html ), "platform: not WordPress for {$label}" );
}

// analyze() and aggregate() carry it.
$cmp_scp_services = cmp_check_load_services( __DIR__ . '/../data/services.json' );
$wp    = cmp_check_analyze( '<html><head><link rel="stylesheet" href="/wp-content/x.css"></head><body>x</body></html>', array(), 'https://exemplu.md/', $cmp_scp_services, 'ro' );
$other = cmp_check_analyze( '<html><body><div id="__next">x</div></body></html>', array(), 'https://exemplu.md/', $cmp_scp_services, 'ro' );
cmp_assert( true === $wp['wordpress'], 'platform: analyze says wordpress true' );
cmp_assert( false === $other['wordpress'], 'platform: analyze says wordpress false' );
$empty = cmp_check_analyze( '', array(), 'https://exemplu.md/', $cmp_scp_services, 'ro' );
cmp_assert( false === $empty['wordpress'], 'platform: an empty page is not WordPress' );

$p1 = $other + array( 'url' => 'https://exemplu.md/' );
$p2 = $wp + array( 'url' => 'https://exemplu.md/b/' );
cmp_assert( true === cmp_check_aggregate( array( $p1, $p2 ), 'ro' )['wordpress'], 'platform: aggregate is WordPress when any page shows it' );
cmp_assert( false === cmp_check_aggregate( array( $p1, $p1 ), 'ro' )['wordpress'], 'platform: aggregate not WordPress when no page shows it' );

// The endpoint sends it.
cmp_assert( 1 === preg_match( "#'wordpress'\s*=>\s*isset\( \\\$agg\['wordpress'\] \)#", (string) file_get_contents( __DIR__ . '/../site/api/lib/endpoint.php' ) ), 'platform: endpoint body has wordpress' );
