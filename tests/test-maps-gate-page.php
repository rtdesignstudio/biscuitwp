<?php
/**
 * Maps gate, Task 2: the gate that replaces the Maps API loader of a theme.
 *  - CMP_Maps_Gate::is_tag() / url(): which tags;
 *  - CMP_Blocker::rewrite(): where it goes, what goes with it, gate off byte for byte;
 *  - config() / gate(): the data and the markup (nothing can close the inline script);
 *  - with node (tests/fixtures/maps-gate-page.js): the consent rule next to the real
 *    banner.js, the allowed path, the stand-in, the box;
 *  - the scan and "Verifică în browser".
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once __DIR__ . '/../includes/class-services.php';
require_once __DIR__ . '/../includes/class-consent.php';
require_once __DIR__ . '/../includes/modules/class-lang.php';
require_once __DIR__ . '/../includes/modules/class-maps-gate.php';
require_once __DIR__ . '/../includes/modules/class-blocker.php';
require_once __DIR__ . '/../includes/modules/class-scanner.php';

$mp_id    = 'google-maps-js';
$mp_lib   = CMP_Services::library();
$mp_slugs = array( 'necessary', 'statistics', 'marketing' );
$mp_on    = array( 'mode' => 'pro', 'block_scripts' => true, 'maps_gate' => true );
$mp_light = array( 'mode' => 'light', 'maps_gate' => true );
$mp_svcs  = function ( array $stored = array(), $on = true, $slugs = null ) use ( $mp_lib, $mp_slugs ) {
	return CMP_Services::apply_gate( CMP_Services::all( $mp_lib, $stored, null === $slugs ? $mp_slugs : $slugs ), $on );
};
$mp_cats = array(
	array( 'slug' => 'necessary', 'required' => true ),
	array( 'slug' => 'statistics', 'required' => false ),
	array( 'slug' => 'marketing', 'required' => false ),
);
$mp_cfg = function ( array $o = array() ) use ( $mp_on, $mp_cats ) {
	$o = array_merge( array( 'settings' => $mp_on, 'cats' => $mp_cats, 'svc' => array( 'marketing' => array( 'google-maps-js' ) ), 'cookie' => 'cmp_consent', 'version' => '3', 'slug' => 'marketing', 'lang' => 'ro' ), $o );
	return CMP_Maps_Gate::config( $o['settings'], $o['cats'], $o['svc'], $o['cookie'], $o['version'], $o['slug'], $o['lang'] );
};
$mp_rw = function ( $html, ?array $services = null, ?array $cfg = null ) use ( $mp_svcs, $mp_cfg ) {
	return CMP_Blocker::rewrite( $html, array(), null === $services ? $mp_svcs() : $services, null, null === $cfg ? $mp_cfg() : $cfg );
};
$mp_u = 'https://maps.googleapis.com/maps/api/js?key=AIzaFAKE';
/* The inline JS of every gate in $html. */
$mp_js = function ( $html ) {
	preg_match_all( '#<script\b[^>]*\bdata-cmp-maps-gate\b[^>]*>(.*?)</script>#s', (string) $html, $m );
	return $m[1];
};
/* The decoded tag of a gate. */
$mp_tag = function ( $html ) use ( $mp_js ) {
	$js = $mp_js( $html );
	if ( ! $js || ! preg_match( '/"k":"([A-Za-z0-9+\\\\\/=]+)"/', $js[0], $m ) ) {
		return null;
	}
	return json_decode( base64_decode( stripslashes( $m[1] ) ), true );
};
$mp_boot = '(g=>{var h,a,k,p="The Google Maps JavaScript API",c="google",l="importLibrary",q="__ib__",m=document,b=window;b=b[c]||(b[c]={});var d=b.maps||(b.maps={}),r=new Set,e=new URLSearchParams,u=()=>h||(h=new Promise(async(f,n)=>{await (a=m.createElement("script"));e.set("libraries",[...r]+"");for(k in g)e.set(k.replace(/[A-Z]/g,t=>"_"+t[0].toLowerCase()),g[k]);e.set("callback",c+".maps."+q);a.src=`https://maps.${c}apis.com/maps/api/js?`+e;d[q]=f;a.onerror=()=>h=n(Error(p+" could not load."));a.nonce=m.querySelector("script[nonce]")?.nonce||"";m.head.append(a)}));d[l]?console.warn(p+" only loads once. Ignoring:",g):d[l]=(f,...n)=>r.add(f)&&u().then(()=>d[l](f,...n))})({key:"AIzaFAKE",v:"weekly"});';

// ---- Which tags ----
cmp_assert( CMP_Services::host_matches( 'https://' . CMP_Maps_Gate::HOST, CMP_Maps_Gate::HOST ), 'host: a pattern' );
foreach ( $mp_lib as $mp_s ) {
	if ( $mp_id === $mp_s['id'] ) {
		cmp_assert_eq( $mp_s['hosts'], array( CMP_Maps_Gate::HOST ), 'host: the one of the library entry' );
		cmp_assert_eq( $mp_s['name'], 'Google Maps JavaScript API', 'library: the name, the same in every language' );
	}
}
foreach ( array(
	'sync'                 => ' src="' . $mp_u . '"',
	'single quotes'        => " type='text/javascript' src='" . $mp_u . "' id='maps-js'",
	'async defer'          => ' async defer src="' . $mp_u . '&amp;callback=initMap"',
	'module'               => ' type="module" src="' . $mp_u . '"',
	'unquoted'             => ' src=' . $mp_u . ' async',
	'unquoted, query'      => ' src=//maps.googleapis.com/maps/api/js?key=K&callback=init',
	'&#038;'               => ' src="' . $mp_u . '&#038;callback=initMap"',
	'protocol-relative'    => ' src="//maps.googleapis.com/maps/api/js?sensor=false"',
	'http'                 => ' src="http://maps.googleapis.com/maps/api/js"',
	'upper case'           => ' SRC="HTTPS://MAPS.GOOGLEAPIS.COM/maps/api/js?key=K"',
	'spaces round the URL' => " src=\" \n" . $mp_u . ' "',
	'a tab in the URL'     => " src=\"https://maps.googleapis.com/ma\tps/api/js\"",
	'port'                 => ' src="https://maps.googleapis.com:443/maps/api/js"',
	'nonce, id'            => ' id="gm" nonce="abc" src="' . $mp_u . '"',
	'data-src'             => ' data-src="' . $mp_u . '"',
	'WP Rocket'            => ' type="rocketlazyloadscript" data-rocket-type="text/javascript" data-rocket-src="' . $mp_u . '"',
	'LiteSpeed'            => ' type="litespeed/javascript" data-src="' . $mp_u . '"',
	'data-litespeed-src'   => ' data-litespeed-src="' . $mp_u . '"',
	'an empty src'         => ' src="" data-src="' . $mp_u . '"',
	'a data: src'          => ' src="data:text/javascript;base64,LyoqLw==" data-lazy-src="' . $mp_u . '"',
	'Perfmatters'          => ' type="pmdelayedscript" src="' . $mp_u . '"',
	'arrow in onload'      => ' src="' . $mp_u . '" onload="(()=',
) as $mp_n => $mp_a ) {
	cmp_assert( CMP_Maps_Gate::is_tag( $mp_a, '' ), "is_tag: $mp_n" );
}
cmp_assert( CMP_Maps_Gate::is_tag( '', $mp_boot ), 'is_tag: the inline importLibrary loader' );
cmp_assert( CMP_Maps_Gate::is_tag( ' type="module"', "\n" . str_replace( '"', "'", $mp_boot ) ), 'is_tag: ... single quotes, module' );
foreach ( array(
	'maps/embed'         => array( ' src="https://www.google.com/maps/embed?pb=1"', '' ),
	'maps embed v1'      => array( ' src="https://maps.googleapis.com/maps/embed/v1/place"', '' ),
	'staticmap'          => array( ' src="https://maps.googleapis.com/maps/api/staticmap?x=1"', '' ),
	'fonts'              => array( ' src="https://fonts.googleapis.com/css?family=Roboto"', '' ),
	'own proxy'          => array( ' src="/wp-content/maps.googleapis.com/maps/api/js"', '' ),
	'own proxy, query'   => array( ' src="https://x.test/p.js?u=https://maps.googleapis.com/maps/api/js"', '' ),
	'suffix host'        => array( ' src="https://maps.googleapis.com.evil.test/maps/api/js"', '' ),
	'prefix host'        => array( ' src="https://notmaps.googleapis.com.test/maps/api/js"', '' ),
	'no src'             => array( '', '' ),
	'x-src'              => array( ' x-src="' . $mp_u . '"', '' ),
	'data-cmp-src'       => array( ' data-cmp-src="' . $mp_u . '"', '' ),
	'data-src next to a live src' => array( ' src="/a.js" data-src="' . $mp_u . '"', '' ),
	'held by another tool (text/plain)' => array( ' type="text/plain" src="' . $mp_u . '"', '' ),
	'JSON'               => array( ' type="application/json"', '{"t":"The Google Maps JavaScript API importLibrary"}' ),
	'template'           => array( ' type="text/x-template"', $mp_boot ),
	'theme code'         => array( '', 'var map = new google.maps.Map(el); google.maps.importLibrary("maps");' ),
	'name only'          => array( '', 'console.log("The Google Maps JavaScript API")' ),
	'inline loader that names the URL' => array( '', 's.src="https://maps.googleapis.com/maps/api/js";' ),
	'src wins over body' => array( ' src="/a.js"', $mp_boot ),
) as $mp_n => $mp_a ) {
	cmp_assert( ! CMP_Maps_Gate::is_tag( $mp_a[0], $mp_a[1] ), "is_tag: not $mp_n" );
}

// ---- rewrite(): gate off is byte for byte, gate on replaces the tag at its place ----
$mp_sync = '<script src="' . $mp_u . '"></script>';
$mp_page = '<!doctype html><html><head><link rel="preconnect" href="https://maps.googleapis.com"><link rel=\'dns-prefetch\' href=\'//maps.gstatic.com\'><link rel="preconnect" href="https://fonts.gstatic.com">'
	. $mp_sync . '<script>' . $mp_boot . '</script><script src="/theme.js"></script></head><body><noscript><img src="https://maps.googleapis.com/maps/api/staticmap?center=1"></noscript><noscript>ok</noscript><iframe src="https://www.google.com/maps/embed?pb=1"></iframe></body></html>';
$mp_without = array_values( array_filter( $mp_lib, function ( $s ) use ( $mp_id ) {
	return $mp_id !== $s['id'];
} ) );
$mp_plain = CMP_Blocker::rewrite( $mp_page, array(), CMP_Services::all( $mp_without, array(), $mp_slugs ) );
cmp_assert_eq( CMP_Blocker::rewrite( $mp_page, array(), $mp_svcs( array(), false ) ), $mp_plain, 'rewrite, gate off: byte for byte the page without the service' );
cmp_assert_eq( CMP_Blocker::rewrite( $mp_page, array(), $mp_svcs( array(), false ), null, $mp_cfg() ), $mp_plain, 'rewrite, gate off (service exempt): a config alone changes nothing' );
cmp_assert_eq( CMP_Blocker::rewrite( $mp_page, array(), $mp_svcs() ), $mp_plain, 'rewrite, no config: nothing gated' );
$mp_o = $mp_rw( $mp_page );
cmp_assert_eq( count( $mp_js( $mp_o ) ), 2, 'rewrite, gate on: both loaders replaced' );
cmp_assert( false === strpos( $mp_o, 'maps.googleapis.com' ) && false === strpos( $mp_o, 'maps.gstatic.com' ) && false === strpos( $mp_o, 'only loads once' ), 'rewrite, gate on: no loader, hint or noscript left' );
cmp_assert( false !== strpos( $mp_o, 'fonts.gstatic.com' ) && false !== strpos( $mp_o, '<noscript>ok</noscript>' ), 'rewrite, gate on: other hints and noscript stay' );
cmp_assert( 1 === preg_match( '#fonts\.gstatic\.com"><script data-cmp-keep [^>]*>.*</script><script data-cmp-keep [^>]*>.*</script><script src="/theme\.js"></script>#s', $mp_o ), 'rewrite, gate on: at their place, in order' );
cmp_assert( false !== strpos( $mp_o, 'data-cmp-embed="marketing"' ), 'rewrite, gate on: the iframe map stays an embed placeholder' );
cmp_assert_eq( $mp_rw( $mp_o ), $mp_o, 'rewrite: a second pass changes nothing' );
cmp_assert_eq( CMP_Blocker::rewrite( $mp_o, array(), $mp_svcs( array(), false ) ), $mp_o, 'rewrite: ... nor with the gate off meanwhile (cached HTML)' );
$mp_one = $mp_rw( $mp_sync );
cmp_assert( 1 === preg_match( '#^<script data-cmp-keep data-cmp-service="google-maps-js" data-cmp-maps-gate="marketing" data-no-optimize="1" data-cfasync="false" nowprocket data-no-defer="1" data-no-minify="1">#', $mp_one ), 'gate: the markup, with the opt-outs of optimizers' );
cmp_assert( 1 === substr_count( $mp_one, '</script' ) && 1 === substr_count( strtolower( $mp_one ), '<script' ) && false === strpos( $mp_one, '<!--' ) && false === strpos( $mp_one, '-->' ), 'gate: one script, nothing that could end it or open a comment' );
cmp_assert( false === strpos( $mp_one, CMP_Maps_Gate::TOKEN ), 'gate: the config is in place' );
foreach ( array( "fbq('", 'GTM-', 'gtag(', 'data-cmp-embed', 'googletagmanager' ) as $mp_n ) {
	cmp_assert( false === strpos( $mp_one, $mp_n ), "gate: no marker of another service ($mp_n)" );
}
// The gate's script cannot be read: a plain block, never the live tag.
$mp_jsp = new ReflectionProperty( 'CMP_Maps_Gate', 'js' );
if ( PHP_VERSION_ID < 80100 ) {
	$mp_jsp->setAccessible( true );
}
$mp_jsp->setValue( null, '' );
$mp_closed = $mp_rw( $mp_sync . '<script>' . $mp_boot . '</script>' );
$mp_jsp->setValue( null, null );
cmp_assert( 2 === substr_count( $mp_closed, '<script type="text/plain" data-cmp-block="marketing" data-cmp-service="google-maps-js"' ) && false === strpos( $mp_closed, 'maps.googleapis.com' ) && false === strpos( $mp_closed, 'only loads once' ), 'no script file: fails closed, the tag and the inline loader blocked' );
// Only a service that can be blocked; who decides.
$mp_ex = $mp_svcs( array( 'overrides' => array( $mp_id => array( 'exempt' => true ) ) ) );
cmp_assert_eq( $mp_rw( $mp_sync, $mp_ex ), $mp_sync, '"nu bloca": live, gate on' );
cmp_assert_eq( $mp_rw( $mp_sync, $mp_svcs( array( 'overrides' => array( $mp_id => array( 'category' => 'necessary' ) ) ) ) ), $mp_sync, 'necessary: live, gate on' );
$mp_cust = function ( $host, $cat = 'statistics', $exempt = false ) use ( $mp_svcs ) {
	return $mp_svcs( array( 'custom' => array( array( 'id' => 'custom-1', 'name' => 'Harta', 'category' => $cat, 'exempt' => $exempt, 'hosts' => array( $host ) ) ) ) );
};
cmp_assert( 1 === preg_match( '/^<script type="text\/plain" data-cmp-block="statistics" data-cmp-service="custom-1"/', $mp_rw( $mp_sync, $mp_cust( 'maps.googleapis.com' ) ) ), 'custom service on the host: it decides' );
cmp_assert_eq( $mp_rw( $mp_sync, $mp_cust( 'maps.googleapis.com', 'statistics', true ) ), $mp_sync, 'custom service, "nu bloca": live' );
cmp_assert_eq( $mp_rw( $mp_page, $mp_cust( 'maps.googleapis.com', 'necessary' ) ), CMP_Blocker::rewrite( $mp_page, array(), $mp_cust( 'maps.googleapis.com', 'necessary' ) ), 'custom service that decides for the whole service: the gate does nothing (inline loader, hints and noscript too)' );
cmp_assert( 1 === count( $mp_js( $mp_rw( $mp_sync, $mp_cust( 'maps.googleapis.com/maps/api/staticmap' ) ) ) ), 'custom service on another path: the gate stays' );
$mp_part = '<script src="https://maps.googleapis.com/maps/api/js/mine.js"></script>';
cmp_assert_eq( $mp_rw( $mp_part, $mp_cust( 'maps.googleapis.com/maps/api/js/mine.js', 'marketing', true ) ), $mp_part, 'custom service for one URL of the API, "nu bloca": that one is live' );
cmp_assert( 1 === count( $mp_js( $mp_rw( $mp_sync, $mp_cust( 'maps.googleapis.com/maps/api/js/mine.js', 'marketing', true ) ) ) ), 'custom service for one URL of the API: the others stay gated' );
cmp_assert( 1 === preg_match( '/data-cmp-block="marketing" data-cmp-enc/', $mp_rw( '<script data-cmp-category="marketing" src="' . $mp_u . '"></script>' ) ), 'data-cmp-category: the author\'s own plain block' );
cmp_assert_eq( $mp_rw( '<script data-cmp-keep src="' . $mp_u . '"></script>' ), '<script data-cmp-keep src="' . $mp_u . '"></script>', 'data-cmp-keep: left' );
cmp_assert_eq( $mp_rw( '<script data-cmp-category="necessary" src="' . $mp_u . '"></script>' ), '<script data-cmp-category="necessary" src="' . $mp_u . '"></script>', 'data-cmp-category="necessary": left' );
cmp_assert( 1 === count( $mp_js( $mp_rw( '<script data-cmp-category="?!" src="' . $mp_u . '"></script>' ) ) ), 'an invalid data-cmp-category: the gate' );
$mp_mix = '<script>' . $mp_boot . 'fbq(\'init\',\'1\');</script>';
cmp_assert( 1 === preg_match( '/^<script type="text\/plain" data-cmp-block="marketing" data-cmp-service="meta-pixel"/', $mp_rw( $mp_mix ) ), 'a tracker in the same inline script: blocked under it, as today' );
$mp_adv = CMP_Services::apply_gate( CMP_Services::apply_measurement( CMP_Services::all( $mp_lib, array(), $mp_slugs ), true ), true );
cmp_assert( 1 === count( $mp_js( $mp_rw( '<script>' . $mp_boot . "gtag('config','G-ABC');</script>", $mp_adv ) ) ), 'Advanced: GA4 in the same inline script does not let the loader through' );
$mp_loader = '<script>var s=document.createElement("script");s.src="https://maps.googleapis.com/maps/api/js?key=K";document.head.appendChild(s);</script>';
cmp_assert_eq( $mp_rw( $mp_loader ), $mp_loader, 'another inline loader: untouched (limit)' );
// Attribute order of other services' tags etc. is not this task's: same as without the gate.
$mp_ga = '<script async src="https://www.googletagmanager.com/gtag/js?id=G-ABC123"></script><iframe src="https://www.youtube.com/embed/abcdefghijk"></iframe>';
cmp_assert_eq( $mp_rw( $mp_ga ), CMP_Blocker::rewrite( $mp_ga, array(), $mp_svcs( array(), false ) ), 'other services: the same with the gate on' );
// Hints from WordPress (wp_resource_hints) go through gate_hosts().
cmp_assert_eq( CMP_Blocker::gate_hosts( $mp_svcs() ), array( 'maps.googleapis.com', 'maps.gstatic.com' ), 'gate_hosts: the host, and the one that serves only Maps' );
cmp_assert_eq( CMP_Blocker::gate_hosts( $mp_svcs( array(), false ) ), array(), 'gate_hosts: none with the gate off' );
cmp_assert_eq( CMP_Blocker::gate_hosts( $mp_ex ), array(), 'gate_hosts: none with "nu bloca"' );
cmp_assert_eq( CMP_Blocker::gate_hosts( $mp_cust( 'maps.googleapis.com' ) ), array(), 'gate_hosts: none when a custom service decides' );
$mp_src_b = (string) file_get_contents( __DIR__ . '/../includes/modules/class-blocker.php' );
cmp_assert( 1 === preg_match( '/function filter_hints[\s\S]*?CMP_Services::gate_on\( \(array\) \$s \)[\s\S]*?gate_hosts\([\s\S]*?function has_host/', $mp_src_b ), 'filter_hints: adds the hosts of the gate, when on' );
cmp_assert( 1 === preg_match( '/function apply\([\s\S]*?CMP_Services::gate_on\( \(array\) \$s \) \? CMP_Maps_Gate::current\(\) : null/', $mp_src_b ), 'apply(): the gate is on only through gate_on()' );

// ---- config() ----
$mp_c = $mp_cfg();
cmp_assert_eq( array_diff_key( $mp_c, array( 't' => 1 ) ), array( 'n' => 'cmp_consent', 'v' => '3', 'c' => 'marketing', 'r' => false, 's' => array( 'google-maps-js' ), 'm' => 'pro', 'l' => 'ro' ), 'config: what the rule reads' );
cmp_assert_eq( $mp_cfg( array( 'svc' => null ) )['s'], null, 'config: an unknown map stays unknown' );
cmp_assert_eq( $mp_cfg( array( 'svc' => array( 'statistics' => array( 'x' ) ) ) )['s'], null, 'config: no list for the category' );
cmp_assert_eq( $mp_cfg( array( 'version' => 12 ) )['v'], '12', 'config: the version as a string' );
cmp_assert_eq( $mp_cfg( array( 'settings' => $mp_light ) )['m'], 'light', 'config: Light' );
cmp_assert_eq( $mp_cfg( array( 'settings' => array() ) )['m'], 'pro', 'config: no mode is Pro, as everywhere' );
cmp_assert_eq( $mp_cfg( array( 'cats' => array( array( 'slug' => 'marketing', 'required' => true ) ) ) )['r'], true, 'config: a required category' );
cmp_assert_eq( $mp_cfg( array( 'cats' => array() ) )['r'], false, 'config: a deleted category is not required' );
cmp_assert_eq( $mp_cfg( array( 'slug' => 'harta' ) )['c'], 'harta', 'config: the category the admin chose' );
cmp_assert_eq( $mp_cfg( array( 'cookie' => CMP_Consent::cookie_names( true )['consent'] ) )['n'], 'cmp_check_consent', 'config: the cookie of check mode and preview' );
cmp_assert_eq( CMP_Maps_Gate::config( $mp_on, array( null, 'x', array( 'slug' => 'marketing' ) ), 'x', 'c', '1' )['r'], false, 'config: garbage is ignored' );
$mp_src_g = (string) file_get_contents( __DIR__ . '/../includes/modules/class-maps-gate.php' );
cmp_assert( 1 === preg_match( '/function current\([\s\S]*?CMP_Consent::cookie_name\(\)[\s\S]*?CMP_Consent::current_version\(\)/', $mp_src_g ) && false !== strpos( $mp_src_g, 'CMP_Services::current_svc_by_cat(' ), 'current(): the cookie, the version and the map of the banner' );
foreach ( array( 'ro', 'ru', 'en' ) as $mp_l ) {
	foreach ( array( 'map_text', 'map_load', 'map_reload', 'map_light', 'map_help', 'map_off' ) as $mp_k ) {
		cmp_assert( '' !== CMP_Lang::text( $mp_k, $mp_l ) && false === strpos( CMP_Lang::text( $mp_k, $mp_l ), '—' ), "text $mp_k present in $mp_l, no em dash" );
	}
	$mp_t = $mp_cfg( array( 'lang' => $mp_l ) );
	cmp_assert_eq( array( $mp_t['l'], $mp_t['t']['load'] ), array( $mp_l, CMP_Lang::text( 'map_load', $mp_l ) ), "config: texts in $mp_l" );
}
cmp_assert_eq( CMP_Lang::text( 'map_load', 'ro' ), 'Încarcă harta', 'ro map_load' );
cmp_assert_eq( CMP_Lang::text( 'map_reload', 'ro' ), 'Reîncarcă pagina ca să vezi harta', 'ro map_reload' );
cmp_assert_eq( $mp_cfg( array( 'settings' => $mp_light ) )['t'], array( 'text' => CMP_Lang::text( 'map_light', 'ro' ) ), 'config: Light has its own text and no button' );

// ---- gate(): the tag as data ----
$mp_t = $mp_tag( $mp_rw( '<script id="gm" nonce="abc" data-x=\'1"2\' crossorigin src="' . $mp_u . '&amp;callback=initMap"></script>' ) );
cmp_assert_eq( $mp_t, array( 'a' => array( array( 'id', 'gm' ), array( 'data-x', '1"2' ), array( 'crossorigin', null ), array( 'src', $mp_u . '&callback=initMap' ) ), 'b' => null, 'r' => '', 'u' => 'initMap', 'n' => 'abc', 'w' => true, 'y' => false, 'm' => false ), 'tag: attributes in order, decoded; nonce and callback apart' );
cmp_assert( false !== strpos( $mp_rw( '<script nonce="a&quot;b" src="' . $mp_u . '"></script>' ), ' nonce="a&quot;b">' ), 'tag: the nonce is on the gate, escaped' );
cmp_assert( 0 === preg_match( '/^<script[^>]*nonce=/', $mp_rw( $mp_sync ) ), 'tag: no nonce, none on the gate' );
foreach ( array(
	'sync'   => array( '', true, false ),
	'defer'  => array( ' defer', true, false ),
	'async'  => array( ' async', false, true ),
	'async defer' => array( ' async="async" defer', false, true ),
	'module' => array( ' type="module"', true, false ),
	'async module' => array( ' type="module" async', false, true ),
) as $mp_n => $mp_a ) {
	$mp_t = $mp_tag( $mp_rw( '<script' . $mp_a[0] . ' src="' . $mp_u . '"></script>' ) );
	cmp_assert_eq( array( $mp_t['w'], $mp_t['y'] ), array( $mp_a[1], $mp_a[2] ), "tag: $mp_n, may be written / is async" );
}
$mp_t = $mp_tag( $mp_rw( '<script type="rocketlazyloadscript" data-rocket-type="text/javascript" data-rocket-src="' . $mp_u . '" data-rocket-defer defer></script>' ) );
cmp_assert( in_array( array( 'src', $mp_u ), $mp_t['a'], true ) && ! in_array( 'type', array_column( $mp_t['a'], 0 ), true ) && ! in_array( 'data-rocket-src', array_column( $mp_t['a'], 0 ), true ), 'tag: an optimizer\'s hold is undone (src back, its type dropped)' );
$mp_t = $mp_tag( $mp_rw( '<script type="text/javascript" SRC="' . $mp_u . '" src="/b.js" @click="x" data-cmp-category=""></script>' ) );
cmp_assert_eq( $mp_t['a'], array( array( 'type', 'text/javascript' ), array( 'src', $mp_u ) ), 'tag: the first of a repeated attribute; names a browser would not take in JS, and ours, dropped' );
foreach ( array(
	'initMap' => 'initMap', 'App.maps.init' => 'App.maps.init', 'google.maps.__ib__' => 'google.maps.__ib__', '$.cb' => '$.cb',
	'alert(1)' => '', 'a..b' => '', 'a[0]' => '', '' => '',
) as $mp_in => $mp_want ) {
	cmp_assert_eq( $mp_tag( $mp_rw( '<script src="' . $mp_u . '&amp;callback=' . rawurlencode( $mp_in ) . '"></script>' ) )['u'], $mp_want, "tag: callback '$mp_in'" );
}
cmp_assert_eq( $mp_tag( $mp_rw( '<script src="' . $mp_u . '&callback[]=x"></script>' ) )['u'], '', 'tag: a callback that is a list is none' );
$mp_t = $mp_tag( $mp_rw( '<script src="' . $mp_u . '" onload="(()=>{go(1>0)})()"></script><p>after</p>' ) );
cmp_assert_eq( $mp_t['a'], array( array( 'src', $mp_u ), array( 'onload', '(()=>{go(1>0)})()' ) ), 'tag: a > inside an attribute value does not cut the tag' );
cmp_assert( '<p>after</p>' === substr( $mp_rw( '<script src="' . $mp_u . '" onload="(()=>{go()})()"></script><p>after</p>' ), -12 ), 'tag: ... and nothing of it leaks into the page' );
$mp_evil = '</script><script>alert(1)</script><!--' . "\xE2\x80\xA8\xE2\x80\xA9" . "'\"&\\";
foreach ( array(
	'in the URL'   => '<script src="' . $mp_u . '&amp;x=' . htmlspecialchars( $mp_evil, ENT_QUOTES, 'UTF-8' ) . '"></script>',
	'in data-x'    => '<script data-x="' . htmlspecialchars( $mp_evil, ENT_QUOTES, 'UTF-8' ) . '" src="' . $mp_u . '"></script>',
	'in the nonce' => '<script nonce="' . htmlspecialchars( $mp_evil, ENT_QUOTES, 'UTF-8' ) . '" src="' . $mp_u . '"></script>',
	'invalid UTF-8' => "<script data-x=\"\xC3\x28\" src=\"" . $mp_u . '"></script>',
) as $mp_n => $mp_h ) {
	$mp_g = $mp_rw( $mp_h );
	cmp_assert( 1 === substr_count( $mp_g, '</script' ) && 1 === substr_count( strtolower( $mp_g ), '<script' ) && false === strpos( $mp_g, '<!--' ) && false === strpos( $mp_g, "\xE2\x80\xA8" . "'" ) && is_array( $mp_tag( $mp_g ) ), "escape: $mp_n cannot leave the script" );
}
$mp_g = CMP_Maps_Gate::gate( ' src="' . $mp_u . '"', '', array_merge( $mp_cfg(), array( 'l' => $mp_evil, 'c' => $mp_evil, 't' => array( 'text' => $mp_evil ) ) ) );
cmp_assert( 1 === substr_count( $mp_g, '</script' ) && 1 === substr_count( strtolower( $mp_g ), '<script' ) && false === strpos( $mp_g, '<!--' ) && false === strpos( $mp_js( $mp_g )[0], "\xE2\x80\xA8" ), 'escape: the config cannot either' );
// Google's inline loader: the whole body with consent, without it only what follows it.
$mp_t = $mp_tag( $mp_rw( '<script nonce="n1">' . $mp_boot . '</script>' ) );
cmp_assert_eq( array( $mp_t['b'], $mp_t['r'], $mp_t['u'], $mp_t['n'], $mp_t['w'] ), array( $mp_boot, '', '', 'n1', false ), 'inline: the body kept, nothing left over' );
$mp_mine = "\nasync function initMap(){const{Map}=await google.maps.importLibrary('maps');new Map(document.getElementById('m'),{})}\ninitMap();";
$mp_t = $mp_tag( $mp_rw( '<script>' . "var a=1;\n" . $mp_boot . $mp_mine . '</script>' ) );
cmp_assert_eq( $mp_t['r'], "var a=1;\n(0);" . $mp_mine, 'inline: the theme code round it is told apart' );
foreach ( array(
	'if(!window.google) %s initMap();'  => 'if(!window.google) (0); initMap();',
	'run(%s, initMap);'                 => 'run((0), initMap);',
	'window.ready=%s var map;'          => 'window.ready=(0); var map;',
	'void%s go();'                      => 'void(0); go();',
	"  %s\n"                            => '',
) as $mp_in => $mp_want ) {
	$mp_b = false === strpos( $mp_in, 'run(' ) ? $mp_boot : rtrim( $mp_boot, ';' );
	cmp_assert_eq( CMP_Maps_Gate::rest( sprintf( $mp_in, $mp_b ) ), $mp_want, "inline: the code round the loader stays valid: $mp_in" );
}
$mp_pretty = str_replace( array( '=>', '({key', '});' ), array( ' => ', "({\n  // Use the 'v' parameter (see docs)\n  key", "\n});" ), $mp_boot );
cmp_assert_eq( $mp_tag( $mp_rw( '<script>' . $mp_pretty . '</script>' ) )['r'], '', 'inline: the formatted snippet too' );
cmp_assert_eq( $mp_tag( $mp_rw( '<script>var t="The Google Maps JavaScript API", l="importLibrary"; x();</script>' ) )['r'], '', 'inline: a loader it cannot tell apart runs nothing without consent' );
cmp_assert( false === strpos( CMP_Maps_Gate::rest( 'a();' . $mp_boot . 'b();' . str_replace( 'AIzaFAKE', 'TWO', $mp_boot ) ), 'importLibrary' ), 'inline: two loaders in one script: none is left to run' );

// ---- The shipped JS ----
$mp_root = dirname( __DIR__ ) . '/';
$mp_min  = (string) file_get_contents( $mp_root . 'assets/js/maps-gate.min.js' );
$mp_full = (string) file_get_contents( $mp_root . 'assets/js/maps-gate.js' );
cmp_assert( 1 === substr_count( $mp_min, CMP_Maps_Gate::TOKEN ) && 1 === substr_count( $mp_full, CMP_Maps_Gate::TOKEN ), 'js: one place for the config, in both' );
cmp_assert( false === stripos( $mp_full, '</script' ) && false === strpos( $mp_full, '<!--' ) && false === stripos( $mp_full, '<script' ), 'js: the source can be inlined too' );
cmp_assert( false === strpos( CMP_Maps_Gate::js(), 'cmp-src-sha256' ), 'js: the hash header is not printed' );
cmp_assert( 0 === preg_match( '/innerHTML|outerHTML|insertAdjacentHTML|\beval\b|new Function|\bconst\b|\blet\b|=>/', preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $mp_full ) ), 'js: DOM methods only, ES5' );
cmp_assert( strlen( CMP_Maps_Gate::js() ) < 8192, 'js: under 8 KB' );
echo 'INFO: inlined gate ' . strlen( $mp_one ) . ' bytes, gz ' . strlen( (string) gzencode( $mp_one, 9 ) ) . "\n";

// ---- With node ----
$mp_node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
$mp_run  = function ( array $cases ) {
	$p = proc_open( 'node ' . escapeshellarg( __DIR__ . '/fixtures/maps-gate-page.js' ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
	fwrite( $pipes[0], json_encode( array_values( $cases ) ) );
	fclose( $pipes[0] );
	$raw = stream_get_contents( $pipes[1] );
	$err = stream_get_contents( $pipes[2] );
	proc_close( $p );
	$r = json_decode( (string) $raw, true );
	if ( ! is_array( $r ) ) {
		cmp_assert( false, 'node: ' . substr( $err . $raw, 0, 400 ) );
		return array();
	}
	return array_combine( array_keys( $cases ), $r );
};
$mp_ck = function ( $o, $name = 'cmp_consent' ) {
	return $name . '=' . rawurlencode( is_string( $o ) ? $o : json_encode( $o ) );
};
$mp_yes = $mp_ck( array( 'v' => '3', 'categories' => array( 'necessary', 'marketing' ) ) );
$mp_gjs = function ( $tag = null, array $o = array() ) use ( $mp_rw, $mp_svcs, $mp_cfg, $mp_js, $mp_sync ) {
	return $mp_js( $mp_rw( null === $tag ? $mp_sync : $tag, $mp_svcs(), $mp_cfg( $o ) ) )[0];
};
if ( '' === $mp_node ) {
	echo "  (skip: node not found, the gate is not run)\n";
} else {
	// -- the consent rule, next to the real banner.js --
	$mp_g3 = array( 'necessary', 'marketing' );
	$mp_cookies = array(
		'none'                   => '',
		'other cookies'          => 'a=1; xcmp_consent=1; b=2',
		'broken JSON'            => 'cmp_consent=%7Bbroken',
		'broken escape'          => 'cmp_consent=%E0%A4%A',
		'empty'                  => 'cmp_consent=',
		'a number'               => $mp_ck( '5' ),
		'a string'               => $mp_ck( '"marketing"' ),
		'null'                   => $mp_ck( 'null' ),
		'a list'                 => $mp_ck( '["marketing"]' ),
		'no version'             => $mp_ck( array( 'categories' => $mp_g3 ) ),
		'old version'            => $mp_ck( array( 'v' => '2', 'categories' => $mp_g3 ) ),
		'numeric version'        => $mp_ck( array( 'v' => 3, 'categories' => $mp_g3 ) ),
		'no categories'          => $mp_ck( array( 'v' => '3' ) ),
		'categories a string'    => $mp_ck( array( 'v' => '3', 'categories' => 'marketing' ) ),
		'categories an object'   => $mp_ck( '{"v":"3","categories":{"0":"marketing"}}' ),
		'refused'                => $mp_ck( array( 'v' => '3', 'categories' => array( 'necessary' ) ) ),
		'statistics only'        => $mp_ck( array( 'v' => '3', 'categories' => array( 'necessary', 'statistics' ) ) ),
		'accepted, no svc'       => $mp_yes,
		'accepted, after others' => 'a=1; ' . $mp_yes . '; b=2',
		'svc null'               => $mp_ck( '{"v":"3","categories":["marketing"],"svc":null}' ),
		'svc a list'             => $mp_ck( '{"v":"3","categories":["marketing"],"svc":["google-maps-js"]}' ),
		'svc a string'           => $mp_ck( '{"v":"3","categories":["marketing"],"svc":"x"}' ),
		'svc empty'              => $mp_ck( '{"v":"3","categories":["marketing"],"svc":{}}' ),
		'svc same'               => $mp_ck( '{"v":"3","categories":["marketing"],"svc":{"marketing":["google-maps-js","youtube"]}}' ),
		'svc more'               => $mp_ck( '{"v":"3","categories":["marketing"],"svc":{"marketing":["zzz","youtube","google-maps-js"]}}' ),
		'svc less'               => $mp_ck( '{"v":"3","categories":["marketing"],"svc":{"marketing":["youtube"]}}' ),
		'svc not a list'         => $mp_ck( '{"v":"3","categories":["marketing"],"svc":{"marketing":"google-maps-js"}}' ),
		'svc of another'         => $mp_ck( '{"v":"3","categories":["marketing"],"svc":{"statistics":["google-maps-js"]}}' ),
		'mixed types'            => $mp_ck( '{"v":"3","categories":[null,1,"marketing"],"svc":{"marketing":[1,"google-maps-js","youtube"]}}' ),
		'twice, first wins'      => $mp_ck( array( 'v' => '3', 'categories' => array() ) ) . '; ' . $mp_yes,
		'check cookie'           => $mp_ck( array( 'v' => '3', 'categories' => $mp_g3 ), 'cmp_check_consent' ),
	);
	$mp_maps = array(
		'unknown map'   => null,
		'empty map'     => array(),
		'the service'   => array( 'marketing' => array( 'google-maps-js' ) ),
		'two services'  => array( 'marketing' => array( 'google-maps-js', 'youtube' ), 'statistics' => array( 'x' ) ),
		'other category' => array( 'statistics' => array( 'google-analytics' ) ),
	);
	$mp_catsets = array(
		'usual'     => $mp_cats,
		'required'  => array( array( 'slug' => 'necessary', 'required' => true ), array( 'slug' => 'marketing', 'required' => true ) ),
		'deleted'   => array( array( 'slug' => 'necessary', 'required' => true ) ),
	);
	$mp_cases = array();
	$mp_names = array( 'cmp_consent', 'cmp_check_consent' );
	foreach ( $mp_cookies as $mp_cn => $mp_ckv ) {
		foreach ( $mp_maps as $mp_mn => $mp_mv ) {
			foreach ( $mp_catsets as $mp_kn => $mp_kv ) {
				foreach ( $mp_names as $mp_nm ) {
					$mp_data = array( 'categories' => $mp_kv, 'version' => '3', 'cookieName' => $mp_nm, 'mode' => 'pro' );
					if ( null !== $mp_mv ) {
						$mp_data['svcByCat'] = (object) $mp_mv;
					}
					$mp_cases[ "$mp_cn / $mp_mn / $mp_kn / $mp_nm" ] = array(
						'banner'  => true,
						'data'    => $mp_data,
						'cookie'  => $mp_ckv,
						'state'   => 'complete',
						'scripts' => array( array( 'js' => $mp_gjs( null, array( 'cats' => $mp_kv, 'svc' => $mp_mv, 'cookie' => $mp_nm ) ) ) ),
						'after'   => 'var out = [!(window.google && google.maps), CMP.isAllowed("marketing")];',
					);
				}
			}
		}
	}
	$mp_bad = array();
	$mp_yesno = array( 0, 0 );
	foreach ( $mp_run( $mp_cases ) as $mp_n => $mp_r ) {
		if ( null !== $mp_r['error'] || ! is_array( $mp_r['out'] ) || $mp_r['out'][0] !== $mp_r['out'][1] || count( $mp_r['created'] ) !== ( $mp_r['out'][1] ? 1 : 0 ) ) {
			$mp_bad[] = $mp_n . ' ' . json_encode( $mp_r );
			continue;
		}
		$mp_yesno[ $mp_r['out'][1] ? 1 : 0 ]++;
	}
	cmp_assert_eq( $mp_bad, array(), 'consent: the same answer as CMP.isAllowed() of banner.js on ' . count( $mp_cases ) . ' cases' );
	cmp_assert( $mp_yesno[0] > 100 && $mp_yesno[1] > 50, 'consent: the table has both answers (' . implode( ' no, ', $mp_yesno ) . ' yes)' );
	$mp_l = $mp_run( array(
		'light, accepted'  => array( 'cookie' => $mp_yes, 'scripts' => array( array( 'js' => $mp_gjs( null, array( 'settings' => $mp_light ) ) ) ) ),
		'light, required'  => array( 'scripts' => array( array( 'js' => $mp_gjs( null, array( 'settings' => $mp_light, 'cats' => $mp_catsets['required'] ) ) ) ) ),
		'no cookies'       => array( 'cookieThrows' => true, 'scripts' => array( array( 'js' => $mp_gjs() ) ) ),
		'pro, accepted'    => array( 'cookie' => $mp_yes, 'scripts' => array( array( 'js' => $mp_gjs( null, array( 'svc' => null ) ) ) ) ),
		'cached old version, new decision' => array( 'cookie' => $mp_ck( array( 'v' => '4', 'categories' => $mp_g3 ) ), 'scripts' => array( array( 'js' => $mp_gjs() ) ) ),
		'cached shorter list, new decision' => array( 'cookie' => $mp_ck( '{"v":"3","categories":["marketing"],"svc":{"marketing":["google-maps-js","new-one"]}}' ), 'scripts' => array( array( 'js' => $mp_gjs() ) ) ),
		'new list, old decision' => array( 'cookie' => $mp_ck( '{"v":"3","categories":["marketing"],"svc":{"marketing":["google-maps-js"]}}' ), 'scripts' => array( array( 'js' => $mp_gjs( null, array( 'svc' => array( 'marketing' => array( 'google-maps-js', 'new-one' ) ) ) ) ) ) ),
		'category moved, old page' => array( 'cookie' => $mp_ck( array( 'v' => '3', 'categories' => array( 'necessary', 'harta' ) ) ), 'scripts' => array( array( 'js' => $mp_gjs() ) ) ),
	) );
	foreach ( array( 'light, accepted' => 0, 'light, required' => 0, 'no cookies' => 0, 'pro, accepted' => 1, 'cached old version, new decision' => 0, 'cached shorter list, new decision' => 1, 'new list, old decision' => 0, 'category moved, old page' => 0 ) as $mp_n => $mp_v ) {
		cmp_assert( null === $mp_l[ $mp_n ]['error'] && count( $mp_l[ $mp_n ]['writes'] ) + count( $mp_l[ $mp_n ]['created'] ) === $mp_v, "consent: $mp_n => " . ( $mp_v ? 'yes' : 'no' ) );
	}

	// -- the allowed path --
	$mp_ok   = array( 'svc' => null );
	$mp_big  = '<script id="gm" nonce="abc" data-x="a&quot;&lt;b&gt;&amp;amp;" crossorigin src="' . $mp_u . '&amp;callback=initMap"></script>';
	$mp_case = function ( $tag, array $script = array(), array $more = array() ) use ( $mp_gjs, $mp_ok, $mp_yes ) {
		return array_merge( array( 'cookie' => $mp_yes, 'body' => false, 'scripts' => array( array_merge( array( 'js' => $mp_gjs( $tag, $mp_ok ) ), $script ) ) ), $more );
	};
	$mp_twice = $mp_case( $mp_sync );
	$mp_twice['scripts'][] = array( 'js' => $mp_gjs( $mp_sync, $mp_ok ) );
	$mp_r = $mp_run( array(
		'sync'      => $mp_case( $mp_big ),
		'in body'   => $mp_case( $mp_sync, array( 'parent' => 'body' ), array( 'body' => true ) ),
		'defer'     => $mp_case( '<script defer src="' . $mp_u . '"></script>' ),
		'async'     => $mp_case( '<script async id="gm" src="' . $mp_u . '"></script>' ),
		'module'    => $mp_case( '<script type="module" src="' . $mp_u . '"></script>' ),
		'interactive' => $mp_case( $mp_big, array(), array( 'state' => 'interactive' ) ),
		'complete'  => $mp_case( $mp_big, array(), array( 'state' => 'complete' ) ),
		'no currentScript' => $mp_case( $mp_sync, array( 'live' => false ) ),
		'made in JS'  => $mp_case( $mp_sync, array( 'async' => true ) ),
		'merged into a file' => $mp_case( $mp_sync, array( 'src' => '/all.js' ) ),
		'merged into another inline' => $mp_case( $mp_sync, array( 'attrs' => null ) ),
		'marked defer' => $mp_case( $mp_sync, array( 'attrs' => array( 'defer' => '' ) ) ),
		'not the last node' => $mp_case( $mp_sync, array( 'next' => true ) ),
		'in head, body there' => $mp_case( $mp_sync, array(), array( 'body' => true ) ),
		'write throws' => $mp_case( $mp_big, array(), array( 'writeThrows' => true ) ),
		'nonce of the running gate' => $mp_case( $mp_big, array( 'nonce' => 'late' ) ),
		'nonce added later only' => $mp_case( $mp_sync, array( 'nonce' => 'late' ) ),
		'write is a no-op' => $mp_case( $mp_sync, array(), array( 'writeNoop' => true ) ),
		'stack: Firefox'   => $mp_case( $mp_sync, array(), array( 'stack' => "@https://x.test/a:1:2/:5:30\n@https://x.test/a:1:2/:5:900\n" ) ),
		'stack: Safari'    => $mp_case( $mp_sync, array(), array( 'stack' => "@https://x.test/:5:30\nglobal code@https://x.test/:5:900" ) ),
		'stack: Chrome, in JS' => $mp_case( $mp_sync, array(), array( 'stack' => "Error\n    at https://x.test/:5:30\n    at https://x.test/:5:900\n    at go (https://x.test/o.js:2:9)" ) ),
		'stack: Chrome, in a timer' => $mp_case( $mp_sync, array(), array( 'stack' => "Error\n    at https://x.test/:1:30\n    at https://x.test/:1:900\n    at https://x.test/o.js:2:9" ) ),
		'stack: Firefox, in JS' => $mp_case( $mp_sync, array(), array( 'stack' => "@https://x.test/:5:30\n@https://x.test/:5:900\ngo@https://x.test/o.js:2:9\n" ) ),
		'stack: Safari, in JS' => $mp_case( $mp_sync, array(), array( 'stack' => "@https://x.test/:5:30\nglobal code@https://x.test/:5:900\nappendChild@[native code]\n@https://x.test/o.js:2:9" ) ),
		'stack: missing'   => $mp_case( $mp_sync, array(), array( 'stack' => null ) ),
		'stack: no positions' => $mp_case( $mp_sync, array(), array( 'stack' => "@https://x.test/\n@https://x.test/" ) ),
		'stack: cut short' => $mp_case( $mp_sync, array(), array( 'stackLimit' => 2 ) ),
		'twice'     => $mp_twice,
		'inline'    => $mp_case( '<script nonce="n1" id="gl">var ran = (window.ran || 0) + 1; "' . 'The Google Maps JavaScript API' . '"; "importLibrary";</script>', array(), array( 'after' => 'var out = ran;' ) ),
	) );
	$mp_wr = '<script id="gm" data-x="a&quot;&lt;b&gt;&amp;amp;" crossorigin src="' . $mp_u . '&amp;callback=initMap" nonce="abc"></script>';
	cmp_assert_eq( array( $mp_r['sync']['writes'], $mp_r['sync']['created'] ), array( array( $mp_wr ), array() ), 'allowed: sync, loading: written at its place, attributes and nonce kept and escaped' );
	cmp_assert_eq( $mp_r['in body']['writes'], array( '<script src="' . $mp_u . '"></script>' ), 'allowed: the same in the body' );
	cmp_assert_eq( $mp_r['defer']['writes'], array( '<script defer src="' . $mp_u . '"></script>' ), 'allowed: defer is written, so it keeps its place among the deferred scripts' );
	cmp_assert_eq( $mp_r['module']['writes'], array( '<script type="module" src="' . $mp_u . '"></script>' ), 'allowed: a module is written too: it joins the deferred scripts, in its place' );
	foreach ( array( 'stack: Firefox', 'stack: Safari' ) as $mp_n ) {
		cmp_assert( 1 === count( $mp_r[ $mp_n ]['writes'] ) && array() === $mp_r[ $mp_n ]['created'], "allowed: $mp_n, the parser runs it: written" );
	}
	foreach ( array( 'stack: Chrome, in JS', 'stack: Chrome, in a timer', 'stack: Firefox, in JS', 'stack: Safari, in JS', 'stack: missing', 'stack: no positions', 'stack: cut short' ) as $mp_n ) {
		cmp_assert( array() === $mp_r[ $mp_n ]['writes'] && 1 === count( $mp_r[ $mp_n ]['created'] ), "allowed: $mp_n: created, never written" );
	}
	cmp_assert_eq( count( $mp_r['write is a no-op']['created'] ), 1, 'allowed: a document.write the page made useless: created' );
	foreach ( array( 'async' => true ) as $mp_n => $mp_v ) {
		cmp_assert( array() === $mp_r[ $mp_n ]['writes'] && 1 === count( $mp_r[ $mp_n ]['created'] ) && $mp_v === $mp_r[ $mp_n ]['created'][0]['async'] && $mp_u === $mp_r[ $mp_n ]['created'][0]['attrs']['src'] && true === $mp_r[ $mp_n ]['created'][0]['after'], "allowed: $mp_n is created after the gate, not written" );
	}
	cmp_assert_eq( $mp_r['async']['created'][0]['attrs']['id'], 'gm', 'allowed: the attributes are on the element' );
	foreach ( array( 'interactive', 'complete', 'no currentScript', 'made in JS', 'merged into a file', 'merged into another inline', 'marked defer', 'not the last node', 'in head, body there', 'write throws' ) as $mp_n ) {
		cmp_assert( array() === $mp_r[ $mp_n ]['writes'] && 1 === count( $mp_r[ $mp_n ]['created'] ) && false === $mp_r[ $mp_n ]['created'][0]['async'] && null === $mp_r[ $mp_n ]['error'], "allowed: $mp_n: created in order, never written" );
	}
	cmp_assert_eq( array( $mp_r['complete']['created'][0]['attrs'], $mp_r['complete']['created'][0]['nonce'] ), array( array( 'id' => 'gm', 'data-x' => 'a"<b>&amp;', 'crossorigin' => '', 'src' => $mp_u . '&callback=initMap', 'nonce' => 'abc' ), 'abc' ), 'allowed: created with the same attributes and nonce' );
	cmp_assert_eq( $mp_r['no currentScript']['created'][0]['parent'], 'HEAD', 'allowed: no place known: in head' );
	cmp_assert( false !== strpos( $mp_r['nonce of the running gate']['writes'][0], ' nonce="late"' ) && 1 === substr_count( $mp_r['nonce of the running gate']['writes'][0], 'nonce=' ), 'allowed: the live nonce of the gate wins (a CSP plugin that sets it later)' );
	cmp_assert( false !== strpos( $mp_r['nonce added later only']['writes'][0], ' nonce="late"' ), 'allowed: ... also when the tag had none' );
	cmp_assert_eq( count( $mp_r['twice']['writes'] ), 1, 'allowed: the same tag runs once' );
	cmp_assert( 1 === $mp_r['inline']['out'] && array() === $mp_r['inline']['writes'] && 'n1' === $mp_r['inline']['created'][0]['nonce'] && 'gl' === $mp_r['inline']['created'][0]['attrs']['id'], 'allowed: the inline loader runs its original body, at its place, with its nonce' );
	$mp_im = '<script type="module">var ran = 1; "The Google Maps JavaScript API"; "importLibrary";</script>';
	$mp_r  = $mp_run( array(
		'loading'  => $mp_case( $mp_im, array(), array( 'after' => 'var early = window.ran; ready(); var out = [early === undefined, window.ran];' ) ),
		'complete' => $mp_case( $mp_im, array(), array( 'state' => 'complete', 'after' => 'var out = window.ran;' ) ),
		'async'    => $mp_case( str_replace( '"module"', '"module" async', $mp_im ), array(), array( 'after' => 'var out = window.ran;' ) ),
		'rest'     => array( 'scripts' => array( array( 'js' => $mp_gjs( '<script type="module">var later = 1;' . $mp_boot . '</script>' ) ) ), 'after' => 'var early = window.later; ready(); var out = [early === undefined, window.later];' ),
	) );
	cmp_assert_eq( array( $mp_r['loading']['out'], $mp_r['complete']['out'], $mp_r['async']['out'], $mp_r['rest']['out'] ), array( array( true, 1 ), 1, 1, array( true, 1 ) ), 'inline module: waits for the page as a module does, with consent or without; an async or late one does not' );
	$mp_r = $mp_run( array( 'boot' => $mp_case( '<script>' . $mp_boot . '</script>', array(), array( 'after' => 'google.maps.importLibrary("maps"); var out = typeof google.maps.importLibrary;' ) ) ) );
	cmp_assert( null === $mp_r['boot']['error'] && 'function' === $mp_r['boot']['out'], 'allowed: Google\'s own loader installs itself' );

	// -- without consent: the stand-in --
	$mp_no  = function ( $after, array $more = array(), $tag = null, array $o = array() ) use ( $mp_gjs ) {
		return array_merge( array( 'scripts' => array( array( 'js' => $mp_gjs( $tag, $o ) ) ), 'after' => $after ), $more );
	};
	$mp_s = array(
		'typeof'        => array( 'var out=[typeof google,typeof google.maps,typeof google.maps.Map,typeof google.maps.event,typeof google.maps.event.addListener,typeof google.maps.places,typeof google.maps.geometry.spherical.computeDistanceBetween,typeof new google.maps.LatLng(1,2)];', array( 'object', 'object', 'function', 'object', 'function', 'object', 'function', 'object' ) ),
		'var google'    => array( 'var google = google || {}; var out=[typeof google.maps, window.google === google];', array( 'object', true ) ),
		'guards'        => array( 'var out=["importLibrary" in google.maps, "Map" in google.maps, google.maps.Map === google.maps.Map, google.maps.places === google.maps.places, !!google.maps.version, !!window.google.maps.importLibrary];', array( true, true, true, true, true, true ) ),
		'theme map'     => array( 'var e=el(300);var map=new google.maps.Map(e,{center:new google.maps.LatLng(47,28),zoom:15,mapTypeId:google.maps.MapTypeId.ROADMAP,styles:[]});var m=new google.maps.Marker({position:map.getCenter(),map:map,animation:google.maps.Animation.DROP,icon:{size:new google.maps.Size(1,2),anchor:new google.maps.Point(0,0)}});var iw=new google.maps.InfoWindow({content:"x"});google.maps.event.addListener(m,"click",function(){log("called")});google.maps.event.addListenerOnce(map,"idle",function(){log("called")});m.addListener("click",function(){log("called")});iw.open(map,m);map.fitBounds(new google.maps.LatLngBounds());google.maps.event.trigger(map,"resize");map.controls[google.maps.ControlPosition.TOP_RIGHT].push(e);map.mapTypes.set("s",new google.maps.StyledMapType([],{}));map.setMapTypeId("s");map.panTo(m.getPosition());var out=[e.childNodes.length,map.getDiv()===e];', array( 1, true ) ),
		'class extends' => array( 'class X extends google.maps.OverlayView{constructor(a){super();this.a=a}draw(){return "mine"}};var x=new X(5);x.setMap(new google.maps.Map(el(10)));var out=[x.a,x.draw(),x instanceof X,x instanceof google.maps.OverlayView,typeof x.getPanes().overlayLayer];', array( 5, 'mine', true, true, 'function' ) ),
		'prototype = new' => array( 'function IB(o){google.maps.OverlayView.apply(this,arguments);this.c_=o;} IB.prototype=new google.maps.OverlayView();IB.prototype.constructor=IB;IB.prototype.draw=function(){return this.c_};var a=new IB(1),b=new IB(2);a.setMap(null);a.extra=7;var out=[a.draw(),b.draw(),a.extra,Object.keys(b),a instanceof IB,a instanceof google.maps.OverlayView,typeof b.getProjection()];', array( 1, 2, 7, array( 'c_' ), true, true, 'function' ) ),
		'prototype = proto' => array( 'function T(){} T.prototype=google.maps.Marker.prototype;function M(){google.maps.Marker.apply(this,arguments)} M.prototype=new T();M.prototype.constructor=M;M.prototype.setMap=function(m){google.maps.Marker.prototype.setMap.apply(this,arguments);this.m=m};var a=new M();a.setMap(3);var out=[a.m,a instanceof google.maps.Marker,new google.maps.Marker().m===3];', array( 3, true, false ) ),
		'Object.create' => array( 'function Y(){} Y.prototype=Object.create(google.maps.OverlayView.prototype);var y=new Y();y.setMap(1);y.q=1;var out=[y instanceof google.maps.OverlayView,y.q,new Y().hasOwnProperty("q")];', array( true, 1, false ) ),
		'MarkerClusterer' => array( 'function MC(map,ms){this.extend(MC,google.maps.OverlayView);this.map_=map;this.ready_=false;this.markers_=[];this.setMap(map);var that=this;google.maps.event.addListener(this.map_,"zoom_changed",function(){log("called")});for(var i=0,m;m=ms[i];i++){this.markers_.push(m)}} MC.prototype.extend=function(o1,o2){return (function(o){for(var p in o.prototype){this.prototype[p]=o.prototype[p]}return this}).apply(o1,[o2])};MC.prototype.onAdd=function(){this.ready_=true};var c=new MC(new google.maps.Map(el(9)),[new google.maps.Marker({}),new google.maps.Marker({})]);var out=[c.markers_.length,c.ready_,typeof c.getMap,typeof c.getProjection()];', array( 2, false, 'function', 'function' ) ),
		'patched prototype' => array( 'var old=google.maps.Map.prototype.setCenter;google.maps.Map.prototype.setCenter=function(){this.n=(this.n||0)+1;return old.apply(this,arguments)};google.maps.Map.prototype.mine=function(){return "mine"};var m=new google.maps.Map(el(9));m.setCenter({});m.n=5;m.setCenter({});var out=[m.n,m.mine(),new google.maps.Map(el(8)).mine()];', null ),
		'wrapped class' => array( 'var O=google.maps.Map;google.maps.Map=function(e,o){return O.call(this,e,o)};var e=el(50);new google.maps.Map(e);var out=e.childNodes.length;', 1 ),
		'instanceof'    => array( 'var l=new google.maps.LatLng(1,2);var out=[l instanceof google.maps.LatLng,l instanceof google.maps.Marker,({}) instanceof google.maps.LatLng,l.constructor===google.maps.LatLng,google.maps.event.x instanceof google.maps.Map];', array( true, false, false, true, false ) ),
		'identity'      => array( 'var a=new google.maps.Marker(),b=new google.maps.Marker(),l=[a,b];a.id=1;b.id=2;a.name="n";var out=[l.indexOf(b),a.id,b.id,a===b,new Map([[a,1]]).get(a),a.name];', array( 1, 1, 2, false, 1, 'n' ) ),
		'for of, spread' => array( 'var n=0;for(var x of google.maps.places.foo()){n++}for(var y of new google.maps.MVCArray()){n++}var [a,b]=google.maps.x;var {p,q}=google.maps.y();var out=[n,[...google.maps.x],Array.from(google.maps.y()),Object.keys({...google.maps.z}),Math.max(...google.maps.x,3),a===undefined,typeof p];', array( 0, array(), array(), array(), 3, true, 'function' ) ),
		'lists'         => array( 'var l=google.maps.x().items,n=0;l.forEach(function(){n++});for(var i=0;i<l.length;i++){n++}for(var k in l){n++}for(var j=0,m;m=l[j];j++){n++}var out=[n<20,l.length,Array.isArray(l),[].slice.call(l).length,typeof l.map(function(){}).join(",")];', array( true, 0, false, 0, 'function' ) ),
		'keys'          => array( 'var i=new google.maps.Marker();var out=[Object.keys(google.maps),Object.keys(google.maps.Map),Object.keys(i),Object.entries(google.maps.q),Object.assign({},i,google.maps.q),i.hasOwnProperty("x")];', array( array( 'version', 'importLibrary' ), array(), array(), array(), array(), false ) ),
		'JSON'          => array( 'var out=[JSON.stringify({p:new google.maps.LatLng(1,2),q:google.maps.x.y(),r:1}),JSON.stringify(google.maps.x),typeof new google.maps.LatLng(1,2).toJSON().lat];', array( '{"p":null,"q":null,"r":1}', 'null', 'function' ) ),
		'numbers'       => array( 'var v=new google.maps.LatLng(1,2).lat();var out=[v<10,v>10,v<=0,v>=0,isNaN(v*2),isNaN(v+1),isNaN(v-v),isNaN(Math.max(v,1)),isNaN(parseFloat(v)),v==5,!v,isNaN(+new Date(v))];', array( false, false, false, false, true, true, true, true, true, false, false, true ) ),
		'strings'       => array( 'var v=google.maps.x();var o={};o[v]=1;var out=[String(v),`${v}`,v.toString(),Object.keys(o),[v,v].join("-"),encodeURIComponent(v),parseInt(v,10)!==parseInt(v,10),String(new google.maps.LatLng(1,2)),String(new google.maps.LatLng(1,2).toString())];', array( '', '', '', array( '' ), '-', '', true, '', '' ) ),
		'concat'        => array( 'var out="lat: "+google.maps.x().lat();', 'lat: NaN' ),
		'delete, define' => array( '"use strict";var m=new google.maps.Map(el(5));m.z=1;delete m.z;delete google.maps.foo;delete google.maps.x.y;google.maps.mine=3;Object.defineProperty(m,"k",{value:4});google.maps.x.set=9;var out=[google.maps.mine,m.k,typeof google.maps.x.set,typeof google.maps.y.set];', array( 3, 4, 'function', 'function' ) ),
		'freeze'        => array( 'var m=Object.freeze(new google.maps.LatLng(1,2));Object.freeze(google.maps.x);var out=[typeof m.lat(),typeof google.maps.x.y,Object.isFrozen(m)];', array( 'function', 'function', true ) ),
		'thenables'     => array( 'var out=(async function(){var a=await google.maps.x();var b=await new google.maps.Marker();var c=await google.maps;return [typeof a,typeof b,c===google.maps,await Promise.resolve(google.maps.y)===google.maps.y]})();', array( 'function', 'object', true, true ) ),
		'importLibrary' => array( 'var out=(async function(){var e=el(70);const {Map,InfoWindow}=await google.maps.importLibrary("maps");const {AdvancedMarkerElement,PinElement}=await google.maps.importLibrary("marker");var m=new Map(e,{mapId:"x"});var pin=new PinElement({});new AdvancedMarkerElement({map:m,content:pin.element}).addListener("click",function(){});return [e.childNodes.length,google.maps.importLibrary("x") instanceof Promise,Map===google.maps.Map]})();', array( 1, true, true ) ),
		'import then'   => array( 'var out=google.maps.importLibrary("maps").then(function(l){return typeof l.Map}).catch(function(){return "no"});', 'function' ),
		'service promises' => array( 'var g=new google.maps.Geocoder(),n=0;g.geocode({},function(){n++});g.geocode({}).then(function(){n++}).catch(function(){n++}).finally(function(){n++});new google.maps.DirectionsService().route({}).then(function(){n++});new google.maps.DistanceMatrixService().getDistanceMatrix({},function(){n++});new google.maps.places.PlacesService(1).textSearch({},function(){n++});google.maps.places.AutocompleteSuggestion.fetchAutocompleteSuggestions({}).then(function(){n++});var out=new Promise(function(r){Promise.resolve().then(function(){r(n)})});', 0 ),
		'no place chosen' => array( 'var a=new google.maps.places.Autocomplete(el(5)),s=new google.maps.places.SearchBox(el(5));var v="typed",p=a.getPlace();if(p&&p.formatted_address){v=p.formatted_address}var out=[v,a.getPlace()===undefined,s.getPlaces()===undefined];', array( 'typed', true, true ) ),
		'elements with a box' => array( 'var out=["PlaceAutocompleteElement","BasicPlaceAutocompleteElement"].map(function(n){return new google.maps.places[n]().childNodes.length}).concat([new google.maps.MapElement().childNodes.length,new google.maps.maps3d.Map3DElement().childNodes.length,new google.maps.marker.PinElement().childNodes.length]);', array( 1, 1, 1, 1, 0 ) ),
		'visitor gesture' => array( 'navigator.userActivation={isActive:true};var l=[];var g=new google.maps.Geocoder();g.geocode({},function(r,s){l.push(r.length,s)});new google.maps.places.PlacesService(1).textSearch({},function(r,s){l.push(s)});var sync=l.length;tick(0);var out=(async function(){try{await g.geocode({})}catch(e){l.push("rejected",e.code)}return [sync].concat(l)})();', array( 0, 0, 'UNKNOWN_ERROR', 'UNKNOWN_ERROR', 'rejected', 'UNKNOWN_ERROR' ) ),
		'no gesture' => array( 'navigator.userActivation={isActive:false};var n=0;new google.maps.Geocoder().geocode({},function(){n++}).then(function(){n++},function(){n++});tick(60000);var out=Promise.resolve().then(function(){return n});', 0 ),
		'real elements' => array( 'var e=el(5);var p=new google.maps.places.PlaceAutocompleteElement();e.appendChild(p);p.id="x";p.addListener("a",function(){});var out=[e.childNodes.length,p.id,p instanceof google.maps.places.PlaceAutocompleteElement];', null ),
		'jQuery.extend' => array( 'var ext=function(t,s){for(var k in s){var c=s[k],p=c&&Object.prototype.toString.call(c)==="[object Object]"&&(function(o){var r=Object.getPrototypeOf(o);if(!r){return true}var C=Object.prototype.hasOwnProperty.call(r,"constructor")&&r.constructor;return typeof C==="function"&&Function.prototype.toString.call(C)===Function.prototype.toString.call(Object)})(c);if(k==="__proto__"||t===c){continue}if(p||Array.isArray(c)){t[k]=ext(Array.isArray(c)?[]:{},c)}else if(c!==undefined){t[k]=c}}return t};var o={center:new google.maps.LatLng(1,2),list:[google.maps.x],fn:google.maps.Map,deep:{a:google.maps.y()}};var r=ext({},o);ext({},google.maps.x);ext({},google.maps);ext({},new google.maps.Marker());var out=[r.center===o.center,r.list.length,r.fn===o.fn,Object.prototype.toString.call(o.center)];', array( true, 1, true, '[object Object]' ) ),
		'jQuery sees'   => array( 'var v=google.maps.x();var out=[v.window===undefined,v.nodeType===0,v.jquery===undefined,v.parentNode,typeof v];', array( true, true, true, null, 'function' ) ),
		'walks end'     => array( 'var n=0,e=google.maps.x();while(e=e.parentNode){n++}var x=new google.maps.MVCArray();while(x.next()){n++}while(x.pop()){n++}var it=google.maps.x().entries();while(it.hasNext()){n++}var out=n;', 0 ),
		'while <'       => array( 'var n=0,b=new google.maps.LatLngBounds();while(b.getNorthEast().lat()<10){n++}while(b.getNorthEast().lat()>10){n++}for(var z=1;z<b.zoom();z++){n++}do{n++}while(b.x()<=3);var out=n;', 1 ),
		'endless loops' => array( 'var n=0,r=[];function t(f){try{f();r.push("end")}catch(e){r.push("threw")}}var v=google.maps.x();t(function(){while(v.more()){n++}});t(function(){while(!(v>=10)){n++}});t(function(){var p=v;while(p=p.parent){n++}});t(function(){for(var i=0,m;m=v[0];i++){n++}});t(function(){var s=google.maps;while(s=s.marker){n++}});var out=r;', array( 'end', 'threw', 'end', 'end', 'end' ) ),
		'async endless' => array( 'var out=(async function(){var n=0;while(await google.maps.x()){n++}return n>1000})();', true ),
		'budget per task' => array( 'for(var k=0;k<3;k++){for(var i=0;i<40000;i++){var m=new google.maps.Marker({position:new google.maps.LatLng(i,i)});}tick(0)}var out=typeof google.maps.x().y().z;', 'function' ),
		'many markers'  => array( 'var l=[];for(var i=0;i<10000;i++){var m=new google.maps.Marker({position:new google.maps.LatLng(i,i),map:null});m.addListener("click",function(){});l.push(m)}var b=new google.maps.LatLngBounds();l.forEach(function(m){b.extend(m.getPosition())});var out=typeof b.getCenter().lat();', 'function' ),
		'no growth'     => array( 'var a=google.maps.x().y.z();var b=google.maps.q.w;for(var i=0;i<2000;i++){google.maps["K"+i];google.maps.places["k"+i]}var out=[a===b,a.b.c.d===a,google.maps.K1999===a,google.maps.K1===google.maps.K1];', array( true, true, true, true ) ),
		'callable'      => array( 'var f=google.maps.event.addListener;var g=f.bind(null,1);var out=[typeof f.call(null,1),typeof f.apply(null,[1]),typeof g(2),typeof google.maps.LatLng(1,2),typeof new (google.maps.foo)(),typeof Reflect.construct(google.maps.LatLng,[1])];', array( 'function', 'function', 'function', 'function', 'object', 'object' ) ),
		'addDomListener' => array( 'var n=0;google.maps.event.addDomListener(window,"load",function(){n++;new google.maps.Map(e)});var e=el(90);google.maps.event.addDomListener(e,"click",function(){n+=10});google.maps.event.addDomListener(null,"x",function(){});google.maps.event.addDomListener(google.maps.x(),"x",function(){});var before=n;fireWindow("load");e.fire("click");var out=[before,n,e.childNodes.length];', array( 0, 11, 1 ) ),
		'errors of nothing' => array( 'new google.maps.Map();new google.maps.Map(null);new google.maps.Map("map");new google.maps.Map({0:el(5),length:1});new google.maps.Map(google.maps.x());new google.maps.StreetViewPanorama(undefined,{});google.maps.Map();var out="ok";', 'ok' ),
		'google.charts kept' => array( 'var out=[google.charts.v,typeof google.maps.Map,google.accounts.id];', array( 1, 'function', 2 ), array( 'before' => 'var google={charts:{v:1},accounts:{id:2}};' ) ),
		'real kept'     => array( 'var out=[google.maps.real,google.maps===keep,google.maps.Marker===undefined];', array( 1, true, true ), array( 'before' => 'var google={maps:{real:1}};var keep=google.maps;' ) ),
		'set later'     => array( 'google.maps={real:2};var out=[google.maps.real,Object.getOwnPropertyDescriptor(google,"maps").writable];', array( 2, true ) ),
		'frozen google' => array( 'var out=typeof google.maps;', 'undefined', array( 'before' => 'var google=Object.freeze({});' ) ),
		'no Proxy'      => array( 'var out=typeof window.google;', 'undefined', array( 'proxy' => false ) ),
		'loader guards' => array( 'var injected=0;if(!(window.google&&window.google.maps&&window.google.maps.version)){injected++}if(!window.google||!window.google.maps||!window.google.maps.importLibrary){injected++}if(typeof google!=="object"||typeof google.maps!=="object"){injected++}var out=injected;', 0 ),
	);
	$mp_cases = array();
	foreach ( $mp_s as $mp_n => $mp_a ) {
		$mp_cases[ $mp_n ] = $mp_no( $mp_a[0], isset( $mp_a[2] ) ? $mp_a[2] : array() );
	}
	$mp_rs = $mp_run( $mp_cases );
	foreach ( $mp_s as $mp_n => $mp_a ) {
		$mp_r = $mp_rs[ $mp_n ];
		cmp_assert( null === $mp_r['error'] && array() === $mp_r['errors'], "stand-in: $mp_n throws nothing" . ( null === $mp_r['error'] ? '' : ' (' . $mp_r['error'] . ')' ) );
		cmp_assert( array() === $mp_r['writes'] && ! in_array( 'called', $mp_r['logs'], true ) && array() === array_filter( $mp_r['created'], function ( $x ) {
			return isset( $x['attrs']['src'] );
		} ), "stand-in: $mp_n loads nothing and calls no map callback" );
		if ( null !== $mp_a[1] ) {
			cmp_assert_eq( $mp_r['out'], $mp_a[1], "stand-in: $mp_n" );
		}
	}
	cmp_assert_eq( $mp_rs['patched prototype']['out'], array( 6, 'mine', 'mine' ), 'stand-in: methods added to a prototype are used' );
	cmp_assert_eq( array_slice( (array) $mp_rs['real elements']['out'], 0, 2 ), array( 1, 'x' ), 'stand-in: the API\'s own elements can go into the page' );

	// -- callback, rest of an inline script, several gates --
	$mp_cbt = '<script async src="' . $mp_u . '&amp;callback=initMap"></script>';
	$mp_c2  = $mp_no( 'var n=0;function initMap(){n++}ready();tick(60000);var out=n;', array(), $mp_cbt );
	$mp_c2['scripts'][] = array( 'js' => $mp_gjs( '<script src="' . $mp_u . '&amp;x=1&amp;callback=initMap"></script>' ) );
	$mp_both = $mp_no( 'var out=[same===google.maps,typeof google.maps.Map];', array(), $mp_sync );
	$mp_both['scripts'][] = array( 'js' => 'var same=google.maps;' );
	$mp_both['scripts'][] = array( 'js' => $mp_gjs( '<script src="' . $mp_u . '&amp;two"></script>' ) );
	$mp_acc = $mp_no( 'var out=google.maps===same;', array(), $mp_sync, array( 'svc' => null ) );
	$mp_acc['scripts'][] = array( 'js' => 'var same=google.maps;document.cookie=' . json_encode( $mp_yes ) . ';' );
	$mp_acc['scripts'][] = array( 'js' => $mp_gjs( '<script src="' . $mp_u . '&amp;two"></script>', array( 'svc' => null ) ) );
	$mp_r = $mp_run( array(
		'ready'     => $mp_no( 'var n=0;function initMap(){n++;new google.maps.Map(e)}var e=el(80);tick(5000);var early=n;ready();tick(60000);var out=[early,n,e.childNodes.length];', array(), $mp_cbt ),
		'late'      => $mp_no( 'var n=0;ready();tick(3000);window.initMap=function(){n++};tick(60000);var out=n;', array(), $mp_cbt ),
		'too late'  => $mp_no( 'var n=0;ready();tick(11000);window.initMap=function(){n++};tick(60000);var out=n;', array(), $mp_cbt ),
		'after load' => $mp_no( 'var n=0;function initMap(){n++}tick(1);var out=n;', array( 'state' => 'complete' ), $mp_cbt ),
		'dotted'    => $mp_no( 'var A={b:{go:function(){this.x=1}}};ready();tick(1);var out=A.b.x;', array(), '<script src="' . $mp_u . '&amp;callback=A.b.go"></script>' ),
		'noop'      => $mp_no( 'ready();tick(60000);var out=1;', array(), '<script src="' . $mp_u . '&amp;callback=Function.prototype"></script>' ),
		'__ib__'    => $mp_no( 'ready();tick(60000);var out=1;', array(), '<script src="' . $mp_u . '&amp;callback=google.maps.__ib__"></script>' ),
		'throws'    => $mp_no( 'function initMap(){throw new Error("theme")}ready();tick(60000);var out=1;', array(), $mp_cbt ),
		'real set'  => $mp_no( 'var n=0;function initMap(){n++}google.maps={};ready();tick(60000);var out=n;', array(), $mp_cbt ),
		'real there' => $mp_no( 'var n=0;function initMap(){n++}ready();tick(60000);var out=n;', array( 'before' => 'var google={maps:{real:1}};' ), $mp_cbt ),
		'real with Map' => $mp_no( 'var n=0;function initMap(){n++}ready();tick(60000);var out=[n,google.maps===keep];', array( 'before' => 'var google={maps:{Map:function(){}}};var keep=google.maps;' ), $mp_cbt ),
		'two tags'  => $mp_c2,
		'rest'      => $mp_no( 'var out=(async function(){await 0;await 0;await 0;return [window.a,e0.childNodes.length]})();', array( 'before' => 'var e0=el(60);document.getElementById=function(){return e0};' ), '<script>var a=1;' . $mp_boot . $mp_mine . '</script>' ),
		'boot only' => $mp_no( 'var out=(async function(){var e=el(60);const {Map}=await google.maps.importLibrary("maps");new Map(e);return e.childNodes.length})();', array(), '<script>' . $mp_boot . '</script>' ),
		'both'      => $mp_both,
		'accepted between' => $mp_acc,
		'boot found' => array( 'scripts' => array( array( 'js' => $mp_gjs() ) ), 'after' => $mp_boot . 'var out=typeof google.maps.Map;' ),
	) );
	foreach ( $mp_r as $mp_n => $mp_x ) {
		cmp_assert( null === $mp_x['error'], "callback / inline: $mp_n throws nothing " . $mp_x['error'] );
	}
	cmp_assert_eq( $mp_r['ready']['out'], array( 0, 1, 1 ), 'callback: called once, after DOM ready, and the map gets its box' );
	cmp_assert_eq( array( $mp_r['late']['out'], $mp_r['too late']['out'], $mp_r['after load']['out'] ), array( 1, 0, 1 ), 'callback: defined later is waited for, 10 s at most; a late gate calls it at once' );
	cmp_assert_eq( $mp_r['dotted']['out'], 1, 'callback: a dotted name keeps its object' );
	cmp_assert_eq( $mp_r['throws']['errors'], array( 'theme' ), 'callback: its error is the page\'s own, reported once' );
	cmp_assert_eq( array( $mp_r['real set']['out'], $mp_r['real there']['out'] ), array( 0, 0 ), 'callback: not while the google.maps there has no Map yet' );
	cmp_assert_eq( $mp_r['real with Map']['out'], array( 1, true ), 'callback: a live google.maps that is already there gets its callback, and stays' );
	cmp_assert_eq( $mp_r['two tags']['out'], 1, 'callback: the same one from two tags is called once' );
	cmp_assert_eq( $mp_r['rest']['out'], array( 1, 1 ), 'inline: the theme code of the same script runs on the stand-in' );
	cmp_assert( array() === array_filter( $mp_r['rest']['created'], function ( $x ) {
		return false !== strpos( (string) $x['text'], 'only loads once' );
	} ), 'inline: ... never Google\'s loader' );
	cmp_assert( array() === $mp_r['boot only']['created'] && 1 === $mp_r['boot only']['out'], 'inline: the loader alone runs nothing' );
	cmp_assert_eq( $mp_r['both']['out'], array( true, 'function' ), 'two gates: one stand-in' );
	cmp_assert( true === $mp_r['accepted between']['out'] && array() === $mp_r['accepted between']['writes'] && array() === $mp_r['accepted between']['created'], 'accepted between two gates: the real API waits for the next page' );
	cmp_assert( array() === $mp_r['boot found']['created'] && 'function' === $mp_r['boot found']['out'], 'a loader snippet the server did not see: finds importLibrary and loads nothing' );

	// -- the box --
	$mp_find = 'function b(e){return e.childNodes[e.childNodes.length-1]}function btn(e){return b(e).childNodes.filter(function(x){return x.tagName==="BUTTON"})[0]}';
	$mp_api  = 'var asked=[];var CMP={allowCategory:function(s){asked.push(s);return %s},openPreferences:function(){log("prefs")}};';
	$mp_bx   = function ( $after, array $more = array(), array $o = array() ) use ( $mp_no, $mp_find ) {
		return $mp_no( $mp_find . $after, $more, null, $o );
	};
	$mp_acceptjs = 'document.cookie=' . json_encode( $mp_yes ) . ';document.dispatchEvent({type:"cmp:consent",detail:{categories:["necessary","marketing"]}});';
	$mp_denyjs   = 'document.cookie="";document.dispatchEvent({type:"cmp:consent",detail:{categories:["necessary"]}});';
	$mp_box = array(
		'one'       => $mp_bx( 'var e=el(300);new google.maps.Map(e);new google.maps.Map(e);new google.maps.StreetViewPanorama(e);var s=el(200);new google.maps.StreetViewPanorama(s);var out=[e.childNodes.length,s.childNodes.length,b(e).hasAttribute("data-cmp-map"),b(e).lang,btn(e).type,btn(e).textContent,b(e).childNodes[0].textContent];' ),
		'nested'    => $mp_bx( 'var e=el(300);var i=document.createElement("div");i.clientHeight=50;e.appendChild(i);new google.maps.Map(i);new google.maps.Map(e);var out=[i.childNodes.length,e.childNodes.length];' ),
		'height'    => $mp_bx( 'var e=el(300);new google.maps.Map(e);var f=el(300,"absolute");new google.maps.Map(f);var out=[b(e).style.cssText,e.style.position,f.style.position===undefined,e.style.cssText];' ),
		'no height' => $mp_bx( 'var e=el(0);new google.maps.Map(e);var out=[b(e).style.cssText,e.style.position===undefined];' ),
		'hidden'    => $mp_bx( 'var e=el(0);e.css.height="400px";new google.maps.Map(e);var f=el(0);f.css.height="100%";new google.maps.Map(f);var out=[/position:absolute/.test(b(e).style.cssText),/min-height:160px/.test(b(f).style.cssText)];' ),
		'click'     => $mp_bx( sprintf( $mp_api, 'true' ) . 'var e=el(300);new google.maps.Map(e);btn(e).fire("click");var out=asked;' ),
		'refused'   => $mp_bx( sprintf( $mp_api, 'false' ) . 'var e=el(300);new google.maps.Map(e);btn(e).fire("click");var out=asked;' ),
		'no CMP'    => $mp_bx( 'var e=el(300);new google.maps.Map(e);btn(e).fire("click");var out=b(e).childNodes[0].textContent;' ),
		'accepted'  => $mp_bx( sprintf( $mp_api, 'true' ) . 'var e=el(300);new google.maps.Map(e);' . $mp_acceptjs . 'var l=btn(e).textContent;var f=el(100);new google.maps.Map(f);btn(e).fire("click");var out=[l,btn(f).textContent,asked];', array(), array( 'svc' => null ) ),
		'accepted alone' => $mp_bx( 'var e=el(300);new google.maps.Map(e);' . $mp_acceptjs . 'tick(60000);var out=1;', array(), array( 'svc' => null ) ),
		'withdrawn' => $mp_bx( sprintf( $mp_api, 'true' ) . 'var e=el(300);new google.maps.Map(e);' . $mp_acceptjs . $mp_denyjs . 'var out=[btn(e).textContent,asked];', array(), array( 'svc' => null ) ),
		'other accepted' => $mp_bx( 'var e=el(300);new google.maps.Map(e);document.cookie=' . json_encode( $mp_ck( array( 'v' => '3', 'categories' => array( 'necessary', 'statistics' ) ) ) ) . ';document.dispatchEvent({type:"cmp:consent",detail:{categories:["necessary","statistics"]}});var out=btn(e).textContent;', array(), array( 'svc' => null ) ),
		'focus'     => $mp_bx( 'var e=el(300);new google.maps.Map(e);var t=btn(e),a=t.style.cssText;t.fire("focus");var f=t.style.cssText;t.fire("blur");var out=[/outline/.test(a),f.indexOf("outline:3px solid #1d4ed8!important")!==-1,t.style.cssText===a,/min-height:44px/.test(a)];' ),
		'light'     => $mp_bx( 'var e=el(300);new google.maps.Map(e);var out=[!!btn(e),b(e).childNodes.length,b(e).childNodes[0].textContent];', array(), array( 'settings' => $mp_light ) ),
		'deleted'   => $mp_bx( 'var e=el(300);new google.maps.Map(e);var out=[!!btn(e),b(e).childNodes[0].textContent];', array(), array( 'cats' => array( array( 'slug' => 'necessary', 'required' => true ) ) ) ),
		'ru'        => $mp_bx( 'var e=el(300);new google.maps.Map(e);var out=[b(e).lang,btn(e).textContent];', array(), array( 'lang' => 'ru' ) ),
		'evil text' => $mp_bx( 'var e=el(300);new google.maps.Map(e);var out=b(e).childNodes.length;' ),
	);
	// The real banner.js: its own API is asked from the click.
	$mp_box['real banner'] = array_merge(
		$mp_bx( 'navigator.userActivation={isActive:true};var e=el(300);new google.maps.Map(e);btn(e).fire("click");var out=[CMP.isAllowed("marketing"),btn(e).textContent];', array(), array( 'svc' => null ) ),
		array( 'banner' => true, 'data' => array( 'categories' => $mp_cats, 'version' => '3', 'cookieName' => 'cmp_consent', 'mode' => 'pro' ) )
	);
	$mp_box['real banner, no gesture'] = $mp_box['real banner'];
	$mp_box['real banner, no gesture']['after'] = str_replace( 'isActive:true', 'isActive:false', str_replace( 'var e=el', 'CMP.openPreferences=function(){log("prefs")};var e=el', $mp_box['real banner']['after'] ) );
	$mp_r = $mp_run( $mp_box );
	foreach ( $mp_r as $mp_n => $mp_x ) {
		cmp_assert( null === $mp_x['error'], "box: $mp_n throws nothing " . $mp_x['error'] );
	}
	$mp_txt = function ( $k, $l = 'ro' ) {
		return CMP_Lang::text( $k, $l );
	};
	cmp_assert_eq( $mp_r['one']['out'], array( 1, 1, true, 'ro', 'button', $mp_txt( 'map_load' ), $mp_txt( 'map_text' ) ), 'box: one per element, in the page language, a real button' );
	cmp_assert_eq( $mp_r['nested']['out'], array( 1, 2 ), 'box: a map inside the element of another has its own' );
	cmp_assert( false !== strpos( $mp_r['height']['out'][0], 'position:absolute!important;top:0!important' ) && false === strpos( $mp_r['height']['out'][0], 'min-height' ) && 'relative' === $mp_r['height']['out'][1] && true === $mp_r['height']['out'][2], 'box: an element with a height keeps its size (the box lies over it)' );
	cmp_assert( false !== strpos( $mp_r['no height']['out'][0], 'min-height:160px!important' ) && true === $mp_r['no height']['out'][1], 'box: an element without a height gets one' );
	cmp_assert_eq( $mp_r['hidden']['out'], array( true, true ), 'box: hidden now, the height CSS gives it' );
	cmp_assert_eq( array( $mp_r['click']['out'], $mp_r['click']['reloads'] ), array( array( 'marketing' ), 1 ), 'box: the click asks for the category, then reloads' );
	cmp_assert_eq( array( $mp_r['refused']['reloads'], $mp_r['refused']['logs'] ), array( 0, array( 'prefs' ) ), 'box: refused by the API: the cookie settings open, no reload' );
	cmp_assert_eq( array( $mp_r['no CMP']['reloads'], $mp_r['no CMP']['out'] ), array( 0, $mp_txt( 'map_help' ) ), 'box: no API on the page: it says what to do' );
	cmp_assert_eq( array( $mp_r['accepted']['out'], $mp_r['accepted']['reloads'] ), array( array( $mp_txt( 'map_reload' ), $mp_txt( 'map_reload' ), array() ), 1 ), 'box: accepted in the banner: the label changes (later boxes too), the click reloads' );
	cmp_assert_eq( $mp_r['accepted alone']['reloads'], 0, 'box: no automatic reload' );
	cmp_assert_eq( $mp_r['withdrawn']['out'], array( $mp_txt( 'map_load' ), array() ), 'box: withdrawn again: the button says what it does' );
	cmp_assert_eq( $mp_r['other accepted']['out'], $mp_txt( 'map_load' ), 'box: another category does not change it' );
	cmp_assert_eq( $mp_r['focus']['out'], array( false, true, true, true ), 'box: visible focus, 44 px target' );
	cmp_assert_eq( $mp_r['light']['out'], array( false, 1, $mp_txt( 'map_light' ) ), 'box: Light: text, no button' );
	cmp_assert_eq( $mp_r['deleted']['out'], array( false, $mp_txt( 'map_off' ) ), 'box: the category does not exist any more: no dead button' );
	cmp_assert_eq( $mp_r['ru']['out'], array( 'ru', $mp_txt( 'map_load', 'ru' ) ), 'box: in Russian' );
	cmp_assert_eq( array( $mp_r['real banner']['out'], $mp_r['real banner']['reloads'] ), array( array( true, $mp_txt( 'map_reload' ) ), 1 ), 'box: with the real banner.js: consent given by the click, then the reload' );
	cmp_assert( 0 === $mp_r['real banner, no gesture']['reloads'] && in_array( 'prefs', $mp_r['real banner, no gesture']['logs'], true ), 'box: without a real gesture the API refuses' );
}
// Contrast of the fixed colours (the box is opaque, so the page theme does not matter).
require_once __DIR__ . '/../includes/class-contrast.php';
foreach ( array( array( '#1f2937', '#f3f4f6', 4.5, 'text' ), array( '#ffffff', '#111827', 4.5, 'button' ), array( '#111827', '#f3f4f6', 3, 'button edge' ), array( '#1d4ed8', '#f3f4f6', 3, 'focus ring' ) ) as $mp_a ) {
	cmp_assert( false !== strpos( $mp_full, 'ffffff' === ltrim( $mp_a[0], '#' ) ? '#fff;' : $mp_a[0] ) && CMP_Contrast::ratio( $mp_a[0], $mp_a[1] ) >= $mp_a[2], "contrast: {$mp_a[3]}" );
}

// ---- The scan and "Verifică în browser" ----
$mp_scan = CMP_Blocker::scan_html( $mp_rw( $mp_page ), $mp_svcs() );
cmp_assert( ! in_array( $mp_id, $mp_scan['services']['raw'], true ) && in_array( $mp_id, $mp_scan['services']['blocked'], true ) && in_array( $mp_id, $mp_scan['services']['found'], true ), 'scan: blocked in the page, not raw, found' );
cmp_assert( array() === $mp_scan['raw'] && in_array( 'marketing', $mp_scan['blocked'], true ), 'scan: the category is blocked, none loads without consent' );
$mp_sv = array_values( array_filter( $mp_svcs(), function ( $s ) use ( $mp_id ) {
	return $mp_id === $s['id'];
} ) )[0];
cmp_assert_eq( CMP_Scanner::service_state( $mp_id, $mp_scan['services'], $mp_sv, 'pro', false ), 'blocked', 'Verifică în browser: the row says blocked' );
cmp_assert_eq( CMP_Scanner::service_state( $mp_id, $mp_scan['services'], $mp_sv, 'light', false ), 'blocked_light', 'Verifică în browser: ... for good, in Light' );
cmp_assert_eq( CMP_Scanner::unknown_hosts( $mp_rw( $mp_sync ), $mp_svcs(), 'https://example.md' ), array(), 'scan: the gate names no unknown domain' );
$mp_scan2 = CMP_Blocker::scan_html( $mp_rw( $mp_sync ) . $mp_loader, $mp_svcs() );
cmp_assert_eq( $mp_scan2['services']['raw'], array( $mp_id ), 'scan: a loader the gate cannot hold is still reported' );
