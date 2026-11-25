<?php
require_once __DIR__ . '/../includes/class-services.php';
require_once __DIR__ . '/../includes/modules/class-blocker.php';

$cmp_tco = array( array( 'id' => 'custom-1', 'name' => 'T', 'type' => 'script', 'category' => 'marketing', 'hosts' => array( 't.co' ), 'inline' => array(), 'gtm' => false, 'exempt' => false ) );
$cmp_h1  = "<script>document.cookie='x=1'</script>";
cmp_assert_eq( CMP_Blocker::rewrite( $cmp_h1, array(), $cmp_tco ), $cmp_h1, 'I2b: t.co does not match document.cookie in a body' );
cmp_assert( false !== strpos( CMP_Blocker::rewrite( '<script src="https://t.co/x.js"></script>', array(), $cmp_tco ), 'text/plain' ), 'I2b: t.co src still rewritten' );
cmp_assert( false !== strpos( CMP_Blocker::rewrite( "<script>var s='//t.co/x.js';</script>", array(), $cmp_tco ), 'text/plain' ), 'I2b: t.co in loader body still rewritten' );
cmp_assert_eq( CMP_Blocker::strip_hints( array( 'https://document.co/x' ), array( 't.co' ) ), array( 'https://document.co/x' ), 'I2b: hint boundary' );
cmp_assert_eq( CMP_Blocker::strip_hints( array( 'https://cdn.t.co/x' ), array( 't.co' ) ), array(), 'I2b: hint subdomain matches' );
$cmp_ns = '<noscript>document.cookie</noscript>';
cmp_assert_eq( CMP_Blocker::strip_tracker_noscript( $cmp_ns, array( 't.co' ) ), $cmp_ns, 'I2b: noscript boundary' );
$cmp_ln = '<link rel="dns-prefetch" href="//document.co">';
cmp_assert_eq( CMP_Blocker::strip_hint_links( $cmp_ln, array( 't.co' ) ), $cmp_ln, 'I2b: link boundary' );

// Hints (1.2.0): preload, prefetch, modulepreload and prerender of what is blocked go too.
$cmp_hint = function ( $tag, array $services = array(), $gate = null ) {
	return CMP_Blocker::rewrite( $tag, array(), $services ? $services : CMP_Services::library(), null, $gate );
};
foreach ( array(
	'<link rel="preload" as="script" href="https://www.googletagmanager.com/gtag/js?id=G-X">',
	'<link as=script href=https://connect.facebook.net/en_US/fbevents.js rel=preload>',
	"<link href='//static.hotjar.com/c/hotjar-1.js' rel='PREFETCH'>",
	'<link rel="modulepreload" href="https://www.clarity.ms/tag/x">',
	'<link rel="dns-prefetch preload" href="https://www.clarity.ms/tag/x" as="script">',
	'<link rel="prerender" href="https://www.youtube.com/embed/abc">',
	'<link rel=preconnect href=https://www.googletagmanager.com crossorigin>',
	'<link rel="preload" href="https://www.googletagmanager.com/gtm.js?id=GTM-1&amp;l=x" as="script" />',
) as $tag ) {
	cmp_assert_eq( $cmp_hint( $tag ), '', "hint removed: {$tag}" );
}
foreach ( array(
	'<link rel="preload" as="script" href="/wp-includes/js/jquery.js">',
	'<link rel="preload" as="script" href="https://notgoogletagmanager.com/gtag/js">',
	'<link rel="preload" as="script" href="https://example.com/?u=https://www.googletagmanager.com/gtag/js">',
	'<link rel="preload" as="style" href="https://connect.facebook.net/x.css" onload="this.rel=\'stylesheet\'">',
	'<link rel="stylesheet preload" href="https://connect.facebook.net/x.css">',
	'<link rel="canonical" data-rel="preload" href="https://www.clarity.ms/tag/x">',
	'<link rel="preload" as="image" href="https://www.google.com/logo.png">',
	'<link rel="prefetch" href="https://www.google.com/recaptcha/api.js">',
	'<link rel="preconnect" href="https://document.co">',
) as $tag ) {
	cmp_assert_eq( $cmp_hint( $tag ), $tag, "hint kept: {$tag}" );
}
$cmp_h_custom = array( array( 'id' => 'mine', 'name' => 'Mine', 'type' => 'script', 'source' => 'custom', 'category' => 'marketing', 'hosts' => array( 'cdn.example.org/track' ) ) );
cmp_assert_eq( $cmp_hint( '<link rel="preload" as="script" href="https://cdn.example.org/track/a.js">', $cmp_h_custom ), '', 'hint: the path of a rule counts' );
cmp_assert( '' !== $cmp_hint( '<link rel="preload" as="script" href="https://cdn.example.org/site/a.js">', $cmp_h_custom ), 'hint: another path of the same host stays' );
$cmp_h_custom[0]['exempt'] = true;
cmp_assert( '' !== $cmp_hint( '<link rel="preload" as="script" href="https://cdn.example.org/track/a.js">', $cmp_h_custom ), 'hint: "nu bloca" keeps it' );
$cmp_h_maps = '<link rel="preload" as="script" href="https://maps.googleapis.com/maps/api/js?key=x">';
cmp_assert_eq( $cmp_hint( $cmp_h_maps ), $cmp_h_maps, 'hint: maps gate off keeps the Maps preload' );

// A very long inline script never empties the page (PCRE gives up at 1,000,000 steps by default).
$cmp_big = '<p>a</p><script>var x="' . str_repeat( 'a', 3000000 ) . '";</script><script src="https://www.clarity.ms/tag/x"></script><p>b</p>';
foreach ( array( '1', '0' ) as $jit ) {
	$cmp_jit = ini_set( 'pcre.jit', $jit );
	$out     = CMP_Blocker::rewrite( $cmp_big, array(), CMP_Services::library() );
	cmp_assert( strlen( $out ) > 3000000 && false !== strpos( $out, '<p>b</p>' ) && false === strpos( $out, 'src="https://www.clarity.ms' ), "long inline script: the page stays and the tracker is blocked (jit {$jit})" );
	ini_set( 'pcre.jit', (string) $cmp_jit );
}
// A host with ini_set disabled still gets its page.
$cmp_ini = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -d disable_functions=ini_set -r ' . escapeshellarg( 'define("ABSPATH","/"); chdir(' . var_export( dirname( __DIR__ ), true ) . '); require "includes/class-services.php"; require "includes/modules/class-blocker.php"; echo CMP_Blocker::rewrite("<p>ok</p><script src=\"https://www.clarity.ms/tag/x\"></script>", array(), CMP_Services::library());' ) . ' 2>&1' );
cmp_assert( 0 === strpos( $cmp_ini, '<p>ok</p>' ) && false === strpos( $cmp_ini, 'src="https://www.clarity.ms' ), 'ini_set disabled: the page comes back, the tracker is blocked' );
