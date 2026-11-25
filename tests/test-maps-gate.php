<?php
/**
 * Maps gate, Task 1: the service google-maps-js, the maps_gate setting and the
 * switch on the Servicii screen.
 *  - the library entry (21 services), the way a custom service wins for it (as for AdSense);
 *  - gate_on() / apply_gate(): with the gate off the service is exempt through
 *    the setting (gate_off), never through own_exempt / mode_exempt, and it is
 *    out of re-consent, newly blocked notices and the scan's "without consent" list;
 *  - without a gate config rewrite() never touches the Maps API tag (the gate itself: test-maps-gate-page.php);
 *  - "Domenii necunoscute", the setting on every path that copies or sanitizes settings;
 *  - the Servicii row, its switch, the save, the one-time note (own process: tests/fixtures/lot-a-request.php).
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once __DIR__ . '/../includes/class-installer.php';
require_once __DIR__ . '/../includes/class-services.php';
require_once __DIR__ . '/../includes/class-sanitizer.php';
require_once __DIR__ . '/../includes/modules/class-blocker.php';
require_once __DIR__ . '/../includes/modules/class-scanner.php';
require_once __DIR__ . '/../includes/modules/class-legal.php';

$mg_id   = 'google-maps-js';
$mg_lib  = CMP_Services::library();
$mg_by   = array();
foreach ( $mg_lib as $mg_s ) {
	$mg_by[ $mg_s['id'] ] = $mg_s;
}
$mg_slugs = array( 'necessary', 'statistics', 'marketing' );
$mg_off   = array( 'mode' => 'pro', 'block_scripts' => true );
$mg_on    = array( 'mode' => 'pro', 'block_scripts' => true, 'maps_gate' => true );
$mg_svcs  = function ( array $settings, array $stored = array() ) use ( $mg_lib, $mg_slugs ) {
	return CMP_Services::apply_gate( CMP_Services::all( $mg_lib, $stored, $mg_slugs ), CMP_Services::gate_on( $settings ) );
};
$mg_get   = function ( array $services ) use ( $mg_id ) {
	foreach ( $services as $s ) {
		if ( $mg_id === $s['id'] ) {
			return $s;
		}
	}
	return null;
};

// ---- The library entry ----
cmp_assert_eq( count( $mg_lib ), 21, 'library: 21 services' );
cmp_assert( isset( $mg_by[ $mg_id ] ), 'library: google-maps-js is there' );
$mg_e = isset( $mg_by[ $mg_id ] ) ? $mg_by[ $mg_id ] : array();
cmp_assert_eq( $mg_e['name'], 'Google Maps JavaScript API', 'library: the name' );
cmp_assert_eq( $mg_e['provider'], 'Google Ireland Ltd.', 'library: provider' );
cmp_assert_eq( $mg_e['type'], 'script', 'library: a script' );
cmp_assert_eq( $mg_e['category'], 'marketing', 'library: marketing' );
cmp_assert_eq( $mg_e['hosts'], array( 'maps.googleapis.com/maps/api/js' ), 'library: the host with its path' );
cmp_assert_eq( $mg_e['privacy_url'], $mg_by['google-maps']['privacy_url'], 'library: privacy_url as google-maps' );
cmp_assert_eq( $mg_e['transfer'], $mg_by['google-maps']['transfer'], 'library: transfer as google-maps' );
cmp_assert_eq( $mg_e['cookies'], $mg_by['google-maps']['cookies'], 'library: only the cookies google-maps lists' );
cmp_assert( empty( $mg_e['exempt_default'] ), 'library: not exempt by default (the setting decides)' );
cmp_assert( ! in_array( $mg_id, call_user_func_array( 'array_merge', array_values( CMP_Services::ADDED_TO_BLOCKING ) ), true ), 'ADDED_TO_BLOCKING: not a service an update starts blocking' );
cmp_assert_eq( CMP_Services::added_to_blocking( '1.1.1' ), array( 'google-adsense' ), 'added_to_blocking: still AdSense only' );
cmp_assert( isset( CMP_Services::YIELD[ $mg_id ] ), 'YIELD: a custom service wins for it, as for AdSense' );
cmp_assert( null === CMP_Services::script_service( 'https://maps.googleapis.com/maps/api/staticmap?x=1', '', $mg_lib ), 'library: other paths of the host are not the service' );
$mg_hit = CMP_Services::script_service( 'https://maps.googleapis.com/maps/api/js?key=K&callback=initMap', '', $mg_lib );
cmp_assert( null !== $mg_hit && $mg_id === $mg_hit['id'], 'library: the Maps API URL is the service' );
cmp_assert_eq( CMP_Services::validate( array( 'services' => $mg_lib ) ), array(), 'library: valid' );

// ---- gate_on() ----
cmp_assert_eq( CMP_Services::gate_on( array() ), false, 'gate_on: no settings' );
cmp_assert_eq( CMP_Services::gate_on( $mg_off ), false, 'gate_on: setting absent' );
cmp_assert_eq( CMP_Services::gate_on( array_merge( $mg_off, array( 'maps_gate' => false ) ) ), false, 'gate_on: false' );
cmp_assert_eq( CMP_Services::gate_on( $mg_on ), true, 'gate_on: true with blocking on' );
cmp_assert_eq( CMP_Services::gate_on( array( 'mode' => 'pro', 'block_scripts' => false, 'maps_gate' => true ) ), false, 'gate_on: blocking off' );
cmp_assert_eq( CMP_Services::gate_on( array( 'maps_gate' => true ) ), false, 'gate_on: no block_scripts in Pro' );
cmp_assert_eq( CMP_Services::gate_on( array( 'mode' => 'light', 'maps_gate' => true ) ), true, 'gate_on: Light always blocks' );
cmp_assert_eq( CMP_Services::gate_on( array( 'mode' => 'light' ) ), false, 'gate_on: Light, setting absent' );

// ---- apply_gate(): off = exempt through the setting, nothing else ----
$mg_a_off = $mg_get( $mg_svcs( $mg_off ) );
cmp_assert( ! empty( $mg_a_off['exempt'] ) && ! empty( $mg_a_off['gate_off'] ), 'gate off: exempt, marked gate_off' );
cmp_assert( empty( $mg_a_off['mode_exempt'] ) && ! isset( $mg_a_off['own_exempt'] ), 'gate off: never mode_exempt, never own_exempt' );
cmp_assert( empty( $mg_a_off['admin_exempt'] ), 'gate off: the admin did not set "nu bloca"' );
cmp_assert( CMP_Services::consent_exempt( $mg_a_off ), 'gate off: consent_exempt (out of re-consent)' );
$mg_a_on = $mg_get( $mg_svcs( $mg_on ) );
cmp_assert( empty( $mg_a_on['exempt'] ) && empty( $mg_a_on['gate_off'] ) && ! CMP_Services::consent_exempt( $mg_a_on ), 'gate on: a service like any other' );
$mg_ov = array( 'overrides' => array( $mg_id => array( 'exempt' => true ) ) );
$mg_a_ov = $mg_get( $mg_svcs( $mg_off, $mg_ov ) );
cmp_assert( ! empty( $mg_a_ov['exempt'] ) && ! empty( $mg_a_ov['admin_exempt'] ), 'gate off + "nu bloca": the admin value is kept apart' );
cmp_assert( CMP_Services::consent_exempt( $mg_get( $mg_svcs( $mg_on, $mg_ov ) ) ), 'gate on + "nu bloca": still exempt, as the admin chose' );
cmp_assert( empty( $mg_get( $mg_svcs( $mg_on, $mg_ov ) )['gate_off'] ), 'gate on + "nu bloca": not marked gate_off' );
$mg_before = CMP_Services::all( $mg_lib, array(), $mg_slugs );
$mg_after  = CMP_Services::apply_gate( $mg_before, false );
$mg_same   = true;
foreach ( $mg_before as $mg_i => $mg_s ) {
	if ( $mg_id !== $mg_s['id'] && $mg_s !== $mg_after[ $mg_i ] ) {
		$mg_same = false;
	}
}
cmp_assert( $mg_same, 'apply_gate: every other service untouched' );
cmp_assert_eq( CMP_Services::apply_gate( $mg_before, true ), $mg_before, 'apply_gate: gate on changes nothing' );
// Advanced: GA / GTM exemption (mode_exempt) and the gate do not mix.
$mg_adv = CMP_Services::apply_gate( CMP_Services::apply_measurement( CMP_Services::all( $mg_lib, array(), $mg_slugs ), true ), false );
cmp_assert( empty( $mg_get( $mg_adv )['mode_exempt'] ), 'Advanced: the gate service is not mode_exempt' );

// ---- Re-consent, notices, the scan: with the gate off the service does not count ----
$mg_seen = array( $mg_id, 'youtube' );
$mg_map_off = CMP_Services::svc_by_cat( $mg_svcs( $mg_off ), $mg_seen, array( 'necessary' ) );
cmp_assert( ! in_array( $mg_id, isset( $mg_map_off['marketing'] ) ? $mg_map_off['marketing'] : array(), true ), 're-consent, gate off: not in the svcByCat map' );
cmp_assert_eq( $mg_map_off, array( 'marketing' => array( 'youtube' ) ), 're-consent, gate off: the map is what it was without the service' );
$mg_map_on = CMP_Services::svc_by_cat( $mg_svcs( $mg_on ), $mg_seen, array( 'necessary' ) );
cmp_assert_eq( $mg_map_on, array( 'marketing' => array( $mg_id, 'youtube' ) ), 're-consent, gate on: seen, so counted' );
cmp_assert_eq( CMP_Services::svc_by_cat( $mg_svcs( $mg_on ), array( 'youtube' ), array( 'necessary' ) ), array( 'marketing' => array( 'youtube' ) ), 're-consent, gate on: not seen yet, not counted' );
cmp_assert_eq( CMP_Services::newly_blocked( array( $mg_id ), $mg_svcs( $mg_off ) ), array(), 'newly blocked, gate off: not named' );
cmp_assert_eq( CMP_Services::newly_blocked( array( $mg_id ), $mg_svcs( $mg_on ) ), array( $mg_id ), 'newly blocked, gate on: named (it waits for consent)' );
cmp_assert_eq( CMP_Services::notice_ids( 1, array( $mg_id ), $mg_svcs( $mg_off ), true ), array(), 'update note, gate off: not named' );
cmp_assert( ! in_array( $mg_id, CMP_Services::admin_exempt_measurement( $mg_svcs( $mg_off ) ), true ), 'admin_exempt_measurement: untouched' );
$mg_tab = CMP_Legal::table_for_mode( array(), $mg_svcs( $mg_off ), 'light' );
$mg_tab_ids = array_column( $mg_tab['services'], 'id' );
sort( $mg_tab_ids );
cmp_assert_eq( $mg_tab_ids, array( 'cloudflare-turnstile', 'fathom', $mg_id, 'plausible', 'recaptcha', 'umami' ), 'Light table, gate off: listed with the services that load (it does load)' );
$mg_tab_on = CMP_Legal::table_for_mode( array(), $mg_svcs( array( 'mode' => 'light', 'maps_gate' => true ) ), 'light' );
cmp_assert( ! in_array( $mg_id, array_column( $mg_tab_on['services'], 'id' ), true ), 'Light table, gate on: not listed as a service that loads' );

// ---- Pages with the Maps API tag ----
$mg_tag  = '<script src="https://maps.googleapis.com/maps/api/js?key=AIzaFAKE&amp;callback=initMap" async defer></script>';
$mg_page = '<html><head><link rel="preconnect" href="https://maps.googleapis.com"><link rel="dns-prefetch" href="//maps.googleapis.com">' . $mg_tag . '</head><body><noscript><img src="https://maps.googleapis.com/maps/api/staticmap?center=1"></noscript><script>var map = new google.maps.Map(document.getElementById("map"));</script></body></html>';
$mg_ga   = '<script async src="https://www.googletagmanager.com/gtag/js?id=G-ABC123"></script>';
foreach ( array(
	'gate off'          => $mg_off,
	'gate on'           => $mg_on,
	'gate on, blocking off' => array( 'mode' => 'pro', 'block_scripts' => false, 'maps_gate' => true ),
	'gate on, Light'    => array( 'mode' => 'light', 'maps_gate' => true ),
) as $mg_n => $mg_set ) {
	cmp_assert_eq( CMP_Blocker::rewrite( $mg_page, array(), $mg_svcs( $mg_set ) ), $mg_page, "rewrite, $mg_n: the Maps API tag, hints and noscript untouched without a gate config" );
	cmp_assert_eq( CMP_Blocker::rewrite( $mg_tag, array(), $mg_svcs( $mg_set ) ), $mg_tag, "rewrite, $mg_n: the tag alone untouched" );
}
cmp_assert_eq( CMP_Blocker::rewrite( $mg_page, array(), $mg_lib ), $mg_page, 'rewrite: the raw library (no gate applied) leaves it too' );
cmp_assert_eq( CMP_Blocker::rewrite( $mg_page, array() ), $mg_page, 'rewrite: the active services (no WordPress here: gate off) leave it too' );
// Gate off: byte for byte what the page was before the service existed.
$mg_without = array_values( array_filter( $mg_lib, function ( $s ) use ( $mg_id ) {
	return $mg_id !== $s['id'];
} ) );
$mg_mixed = $mg_page . $mg_ga . '<iframe src="https://www.youtube.com/embed/abcdefghijk"></iframe>';
cmp_assert_eq( CMP_Blocker::rewrite( $mg_mixed, array(), $mg_svcs( $mg_off ) ), CMP_Blocker::rewrite( $mg_mixed, array(), CMP_Services::all( $mg_without, array(), $mg_slugs ) ), 'rewrite, gate off: byte for byte the same as without the service in the library' );
cmp_assert_eq( CMP_Blocker::rewrite( $mg_mixed, array(), $mg_svcs( $mg_on ) ), CMP_Blocker::rewrite( $mg_mixed, array(), CMP_Services::all( $mg_without, array(), $mg_slugs ) ), 'rewrite, gate on, no config: the same too' );
cmp_assert( false !== strpos( CMP_Blocker::rewrite( $mg_mixed, array(), $mg_svcs( $mg_on ) ), 'data-cmp-service="google-analytics"' ), 'rewrite: other trackers still blocked' );
cmp_assert( false !== strpos( CMP_Blocker::rewrite( $mg_mixed, array(), $mg_svcs( $mg_on ) ), 'data-cmp-service="youtube"' ), 'rewrite: embeds still blocked' );
// An inline loader that names the API is not handed to the generic rewrite either.
$mg_inl = '<script>(g=>{var h,a,k,p="The Google Maps JavaScript API",c="google",l="importLibrary";var u="https://maps.googleapis.com/maps/api/js?key=K";})({key:"K"});</script>';
cmp_assert_eq( CMP_Blocker::rewrite( $mg_inl, array(), $mg_svcs( $mg_on ) ), $mg_inl, 'rewrite: an inline loader naming the API stays too' );

// A stored custom service with a host that matches the URL wins, as for AdSense.
$mg_cust = array( 'custom' => array( array( 'id' => 'custom-1', 'name' => 'Harta mea', 'category' => 'statistics', 'hosts' => array( 'maps.googleapis.com' ) ) ) );
foreach ( array( 'gate off' => $mg_off, 'gate on' => $mg_on ) as $mg_n => $mg_set ) {
	$mg_all = $mg_svcs( $mg_set, $mg_cust );
	$mg_o   = CMP_Blocker::rewrite( $mg_tag, array(), $mg_all );
	cmp_assert( false !== strpos( $mg_o, 'data-cmp-service="custom-1"' ) && false !== strpos( $mg_o, 'data-cmp-block="statistics"' ), "custom service, $mg_n: it blocks the Maps API tag, in its category" );
	$mg_svc = $mg_get( $mg_all );
	cmp_assert( ! empty( $mg_svc['except'] ) && 'custom-1' === $mg_svc['except'][0]['id'], "custom service, $mg_n: recorded on the library entry (except)" );
	cmp_assert( CMP_Services::replaced( $mg_svc ), "custom service, $mg_n: the library entry is replaced (its only host)" );
	cmp_assert( ! in_array( $mg_id, CMP_Blocker::found_ids( $mg_tag, $mg_all ), true ) && in_array( 'custom-1', CMP_Blocker::found_ids( $mg_tag, $mg_all ), true ), "custom service, $mg_n: the scan finds the custom service, not the library one" );
}
cmp_assert_eq( CMP_Services::loader_service( $mg_get( $mg_svcs( $mg_off, $mg_cust ) ), $mg_svcs( $mg_off, $mg_cust ) )['id'], 'custom-1', 'custom service: decides for the loader (so for its cookies)' );
$mg_one = array( 'custom' => array( array( 'id' => 'custom-1', 'name' => 'Static', 'category' => 'marketing', 'hosts' => array( 'maps.googleapis.com/maps/api/staticmap' ) ) ) );
$mg_one_svc = $mg_get( $mg_svcs( $mg_on, $mg_one ) );
cmp_assert( ! CMP_Services::excepted( 'https://maps.googleapis.com/maps/api/js?key=K', $mg_one_svc ) && ! CMP_Services::replaced( $mg_one_svc ), 'custom service on another path of the host: the library entry keeps the Maps API' );
cmp_assert( in_array( $mg_id, CMP_Blocker::found_ids( $mg_tag, $mg_svcs( $mg_on, $mg_one ) ), true ), 'custom service on another path: the scan still finds the library service' );

// ---- The scan ----
cmp_assert( in_array( $mg_id, CMP_Blocker::found_ids( $mg_tag, $mg_svcs( $mg_off ) ), true ), 'scan, gate off: the service is found (the row and the notice need it)' );
cmp_assert( in_array( $mg_id, CMP_Blocker::found_ids( $mg_tag, $mg_svcs( $mg_on ) ), true ), 'scan, gate on: found' );
cmp_assert( ! in_array( $mg_id, CMP_Blocker::found_ids( '<script src="https://maps.googleapis.com/maps/api/staticmap?x=1"></script>', $mg_svcs( $mg_on ) ), true ), 'scan: another path of the host is not the service' );
$mg_scan_off = CMP_Blocker::scan_html( $mg_tag, $mg_svcs( $mg_off ) );
cmp_assert_eq( $mg_scan_off['services']['raw'], array(), 'scan, gate off: not reported as loading without consent (exempt)' );
cmp_assert_eq( $mg_scan_off['raw'], array(), 'scan, gate off: no category reported either' );
cmp_assert( in_array( $mg_id, $mg_scan_off['services']['found'], true ), 'scan, gate off: still in found' );
$mg_scan_on = CMP_Blocker::scan_html( $mg_tag, $mg_svcs( $mg_on ) );
cmp_assert_eq( $mg_scan_on['services']['raw'], array( $mg_id ), 'scan, gate on: a loader still live is reported' );

// ---- "Domenii necunoscute" ----
$mg_home = 'https://example.md';
$mg_unk  = function ( $html, array $services ) use ( $mg_home ) {
	return CMP_Scanner::unknown_hosts( $html, $services, $mg_home );
};
foreach ( array( 'gate off' => $mg_off, 'gate on' => $mg_on ) as $mg_n => $mg_set ) {
	$mg_all = $mg_svcs( $mg_set );
	cmp_assert_eq( $mg_unk( $mg_tag, $mg_all ), array(), "unknown hosts, $mg_n: the Maps API URL belongs to a service" );
	cmp_assert_eq( $mg_unk( '<script src="https://maps.googleapis.com/maps/api/js"></script>', $mg_all ), array(), "unknown hosts, $mg_n: with no query either" );
	cmp_assert_eq( $mg_unk( '<iframe src="https://maps.googleapis.com/maps/embed/v1/place?q=x"></iframe>', $mg_all ), array( 'maps.googleapis.com' ), "unknown hosts, $mg_n: another path of the host still listed" );
	cmp_assert_eq( $mg_unk( '<script src="https://maps.googleapis.com/maps/api/staticmap?x=1"></script>', $mg_all ), array( 'maps.googleapis.com' ), "unknown hosts, $mg_n: ... a script on another path too" );
}
cmp_assert( CMP_Scanner::host_known( 'maps.googleapis.com', $mg_svcs( $mg_off ), $mg_home ) === false, 'host_known: the host alone is not covered (only its Maps API path)' );

// ---- The setting: every path that copies or sanitizes settings keeps it ----
$mg_post = array( 'mode' => 'pro', 'block_scripts' => '1', 'position' => 'bottom-center', 'preset' => 'neutral' );
foreach ( array( true, false ) as $mg_v ) {
	$mg_old = array( 'mode' => 'pro', 'block_scripts' => true, 'maps_gate' => $mg_v );
	$mg_c   = CMP_Sanitizer::settings( $mg_post, $mg_old );
	cmp_assert_eq( $mg_c['maps_gate'], $mg_v, 'settings(): the stored maps_gate ' . var_export( $mg_v, true ) . ' survives a save of the settings screen' );
	cmp_assert_eq( CMP_Sanitizer::keep_appearance( CMP_Sanitizer::keep_legal( $mg_c, $mg_old ), $mg_old )['maps_gate'], $mg_v, 'keep_appearance(keep_legal()): kept ' . var_export( $mg_v, true ) );
	$mg_light = CMP_Sanitizer::settings( array_merge( $mg_post, array( 'mode' => 'light' ) ), $mg_old );
	cmp_assert( 'light' === $mg_light['mode'] && $mg_v === $mg_light['maps_gate'], 'settings(): the Light switch keeps it ' . var_export( $mg_v, true ) );
	$mg_back = CMP_Sanitizer::settings( $mg_post, $mg_light );
	cmp_assert( 'pro' === $mg_back['mode'] && $mg_v === $mg_back['maps_gate'], 'settings(): ... and so does the way back to Pro ' . var_export( $mg_v, true ) );
	$mg_aspect = CMP_Sanitizer::aspect( array( 'appearance' => array( 'palette' => 'warm' ) ), $mg_old, false );
	cmp_assert_eq( $mg_aspect['maps_gate'], $mg_v, 'aspect(): kept ' . var_export( $mg_v, true ) );
}
cmp_assert( ! array_key_exists( 'maps_gate', CMP_Sanitizer::settings( $mg_post, array( 'mode' => 'pro' ) ) ), 'settings(): absent stays absent (it is off, not saved as a decision)' );
cmp_assert( ! array_key_exists( 'maps_gate', CMP_Sanitizer::aspect( array( 'appearance' => array() ), array( 'mode' => 'pro' ), false ) ), 'aspect(): absent stays absent' );
cmp_assert_eq( CMP_Sanitizer::settings( array_merge( $mg_post, array( 'maps_gate' => '1' ) ), array() )['maps_gate'], true, 'settings(): a posted 1 turns it on' );
cmp_assert_eq( CMP_Sanitizer::settings( array_merge( $mg_post, array( 'maps_gate' => '0' ) ), array( 'maps_gate' => true ) )['maps_gate'], false, 'settings(): a posted 0 turns it off' );
cmp_assert_eq( CMP_Sanitizer::settings( array_merge( $mg_post, array( 'maps_gate' => 'evil' ) ), array( 'maps_gate' => true ) )['maps_gate'], false, 'settings(): anything but a yes is off' );
cmp_assert_eq( CMP_Sanitizer::maps_gate( array( 'maps_gate' => '1' ), array() ), true, 'maps_gate(): posted yes' );
cmp_assert_eq( CMP_Sanitizer::maps_gate( array( 'maps_gate' => '0' ), array( 'maps_gate' => true ) ), false, 'maps_gate(): posted no' );
cmp_assert_eq( CMP_Sanitizer::maps_gate( array(), array( 'maps_gate' => true ) ), true, 'maps_gate(): not posted, the stored value' );
cmp_assert_eq( CMP_Sanitizer::maps_gate( array(), array() ), false, 'maps_gate(): not posted, nothing stored: off' );
// The preview overlay never drops it, and an import of the appearance goes through aspect().
if ( class_exists( 'CMP_Preview' ) || is_readable( __DIR__ . '/../includes/modules/class-preview.php' ) ) {
	require_once __DIR__ . '/../includes/class-consent.php';
	require_once __DIR__ . '/../includes/class-contrast.php';
	require_once __DIR__ . '/../includes/class-appearance.php';
	require_once __DIR__ . '/../includes/modules/class-banner.php';
	require_once __DIR__ . '/../includes/modules/class-preview.php';
	cmp_assert_eq( CMP_Preview::apply( array( 'mode' => 'pro', 'maps_gate' => true ), array( 'preset' => 'warm' ) )['maps_gate'], true, 'preview: the overlay keeps it' );
	cmp_assert( ! in_array( 'maps_gate', CMP_Preview::KEYS, true ), 'preview: not one of the keys an Aspect save changes' );
}
$mg_src_io = (string) file_get_contents( __DIR__ . '/../includes/class-appearance-io.php' );
cmp_assert( false !== strpos( $mg_src_io, "CMP_Sanitizer::aspect( array( 'appearance' => \$ap ), \$current" ), 'appearance import: goes through aspect() over the current settings' );

// Fresh installs: on; absent (an install updated from before): off.
cmp_assert_eq( CMP_Installer::default_settings()['maps_gate'], true, 'installer: a fresh install has the gate on' );
$mg_inst = (string) file_get_contents( __DIR__ . '/../includes/class-installer.php' );
cmp_assert( false !== strpos( $mg_inst, "add_option( 'cmp_settings', self::default_settings() )" ), 'installer: defaults go through add_option (never over a stored value)' );
cmp_assert_eq( CMP_Services::gate_on( array_diff_key( CMP_Installer::default_settings(), array( 'maps_gate' => 1 ) ) ), false, 'an install without the key: off' );
cmp_assert_eq( CMP_Services::gate_on( CMP_Installer::default_settings() ), true, 'a fresh install: on' );

// ---- Pure rules of the one-time note ----
cmp_assert_eq( CMP_Services::maps_notice_due( '1.1.1' ), true, 'note due: an install updated from 1.1.1' );
cmp_assert_eq( CMP_Services::maps_notice_due( '0.9.0' ), true, 'note due: from 0.9.0' );
cmp_assert_eq( CMP_Services::maps_notice_due( false ), false, 'note due: never on a fresh install' );
cmp_assert_eq( CMP_Services::maps_notice_due( '1.2.0' ), false, 'note due: not from 1.2.0' );
cmp_assert_eq( CMP_Services::maps_notice_due( '1.2.0-beta.1' ), true, 'note due: a pre-release of 1.2.0 comes before it' );
cmp_assert_eq( CMP_Services::maps_notice_shown( 1, $mg_off, array( $mg_id ) ), true, 'note shown: flag, gate off, found' );
cmp_assert_eq( CMP_Services::maps_notice_shown( 1, $mg_off, array( 'youtube' ) ), false, 'note shown: not while the scan does not find it' );
cmp_assert_eq( CMP_Services::maps_notice_shown( false, $mg_off, array( $mg_id ) ), false, 'note shown: not without the flag' );
cmp_assert_eq( CMP_Services::maps_notice_shown( 1, $mg_on, array( $mg_id ) ), false, 'note shown: not once the gate is on' );
cmp_assert_eq( CMP_Services::maps_notice_shown( 1, array( 'mode' => 'pro', 'block_scripts' => false ), array( $mg_id ) ), false, 'note shown: not while blocking is off (nothing to switch)' );
cmp_assert_eq( CMP_Services::maps_notice_shown( 1, array( 'mode' => 'light' ), array( $mg_id ) ), true, 'note shown: Light blocks, so yes' );

// ---- WordPress side, each in its own PHP process ----
$mg_run = function ( array $in ) {
	$cmd = escapeshellarg( PHP_BINARY ) . ' -d error_reporting=-1 -d display_errors=1 ' . escapeshellarg( __DIR__ . '/fixtures/lot-a-request.php' ) . ' ' . escapeshellarg( json_encode( $in ) ) . ' 2>&1';
	$raw = (string) shell_exec( $cmd );
	$r   = json_decode( $raw, true );
	return is_array( $r ) ? $r : array( 'log' => array(), 'db' => array(), 'autoload' => array(), 'out' => '', 'result' => 'BROKEN: ' . substr( $raw, 0, 600 ) );
};
$mg_cats = array(
	array( 'slug' => 'necessary', 'label' => 'Necesare', 'required' => true ),
	array( 'slug' => 'statistics', 'label' => 'Statistici' ),
	array( 'slug' => 'marketing', 'label' => 'Marketing' ),
);
$mg_base = array( 'cmp_categories' => $mg_cats, 'cmp_settings' => $mg_off );
$mg_with = function ( array $set, array $more = array() ) use ( $mg_base ) {
	return array_merge( $mg_base, array( 'cmp_settings' => $set ), $more );
};

// The active services and the re-consent map the front end publishes.
$mg_hist = array( 'cmp_services_history' => array( $mg_id, 'youtube' ) );
$r = $mg_run( array( 'scenario' => 'gate_state', 'options' => $mg_with( $mg_off, $mg_hist ) ) );
cmp_assert( is_array( $r['result'] ) && ! empty( $r['result']['svc']['gate_off'] ) && ! empty( $r['result']['svc']['exempt'] ), 'active(), setting absent: exempt through gate_off' );
cmp_assert( empty( $r['result']['svc']['mode_exempt'] ) && ! isset( $r['result']['svc']['own_exempt'] ), 'active(), setting absent: not mode_exempt, not own_exempt' );
cmp_assert_eq( $r['result']['by_cat'], array( 'marketing' => array( 'youtube' ) ), 'current_svc_by_cat, setting absent: the service (in the history) is not in the map' );
$r = $mg_run( array( 'scenario' => 'gate_state', 'options' => $mg_with( array_merge( $mg_off, array( 'maps_gate' => false ) ), $mg_hist ) ) );
cmp_assert_eq( $r['result']['by_cat'], array( 'marketing' => array( 'youtube' ) ), 'current_svc_by_cat, maps_gate false: the same' );
$r = $mg_run( array( 'scenario' => 'gate_state', 'options' => $mg_with( array( 'mode' => 'pro', 'block_scripts' => false, 'maps_gate' => true ), $mg_hist ) ) );
cmp_assert( ! empty( $r['result']['svc']['gate_off'] ) && array( 'marketing' => array( 'youtube' ) ) === $r['result']['by_cat'], 'blocking off, maps_gate true: still exempt, not counted' );
$r = $mg_run( array( 'scenario' => 'gate_state', 'options' => $mg_with( $mg_on, $mg_hist ) ) );
cmp_assert( empty( $r['result']['svc']['exempt'] ) && empty( $r['result']['svc']['gate_off'] ), 'active(), gate on: a service like any other' );
cmp_assert_eq( $r['result']['by_cat'], array( 'marketing' => array( $mg_id, 'youtube' ) ), 'current_svc_by_cat, gate on: counted once seen' );

// wp_resource_hints: the hints to the Maps hosts go only with the gate on; trackers always.
$mg_hosts = array( 'https://maps.googleapis.com', array( 'href' => '//maps.gstatic.com' ) );
foreach ( array( 'gate off' => array( $mg_off, $mg_hosts ), 'gate on' => array( $mg_on, array() ), 'gate on, Light' => array( array( 'mode' => 'light', 'maps_gate' => true ), array() ) ) as $mg_n => $mg_set ) {
	$r = $mg_run( array( 'scenario' => 'hints', 'options' => $mg_with( $mg_set[0] ) ) );
	cmp_assert_eq( $r['result'], $mg_set[1], "wp_resource_hints, $mg_n" );
}
$r = $mg_run( array( 'scenario' => 'hints', 'options' => $mg_with( $mg_on, array( 'cmp_services' => array( 'overrides' => array( $mg_id => array( 'exempt' => true ) ) ) ) ) ) );
cmp_assert_eq( $r['result'], $mg_hosts, 'wp_resource_hints, gate on, "nu bloca": they stay' );

// A whole page through apply(): the config is the banner's own data.
$mg_apply = function ( array $set, array $more = array(), array $get = array() ) use ( $mg_run, $mg_with, $mg_page ) {
	$r = $mg_run( array( 'scenario' => 'apply', 'page' => $mg_page, 'get' => $get, 'locale' => 'ru_RU', 'options' => $mg_with( $set, $more ) ) );
	cmp_assert( is_string( $r['result'] ), 'apply: a page comes back ' . substr( $r['out'], 0, 200 ) );
	return (string) $r['result'];
};
$mg_conf = function ( $html ) {
	return preg_match( '/data-cmp-maps-gate="[^"]*"[^>]*>.*?(\{"n":.*?"k":"[^"]*"\})/s', $html, $m ) ? json_decode( $m[1], true ) : null;
};
cmp_assert_eq( $mg_apply( $mg_off ), $mg_page, 'apply, gate off (an install updated from before): byte for byte' );
cmp_assert_eq( $mg_apply( array_merge( $mg_off, array( 'maps_gate' => false ) ) ), $mg_page, 'apply, maps_gate false: byte for byte' );
cmp_assert_eq( $mg_apply( array_merge( $mg_on, array( 'block_scripts' => false ) ) ), $mg_page, 'apply, blocking off: byte for byte' );
$mg_o = $mg_apply( array_merge( $mg_on, array( 'policy_version' => '7' ) ), $mg_hist );
$mg_k = $mg_conf( $mg_o );
cmp_assert( false === strpos( $mg_o, 'maps.googleapis.com' ), 'apply, gate on: nothing of the API left in the page' );
cmp_assert_eq( is_array( $mg_k ) ? array( $mg_k['n'], $mg_k['v'], $mg_k['c'], $mg_k['r'], $mg_k['s'], $mg_k['m'], $mg_k['l'] ) : null, array( 'cmp_consent', '7', 'marketing', false, array( $mg_id, 'youtube' ), 'pro', 'ru' ), 'apply, gate on: cookie, version, category, service list of the banner, page language' );
cmp_assert_eq( $mg_conf( $mg_apply( $mg_on ) )['s'], null, 'apply, no scan ever: the list is unknown, as for the banner' );
cmp_assert_eq( $mg_conf( $mg_apply( $mg_on, array(), array( 'cmp_check' => 'x' ) ) )['n'], 'cmp_check_consent', 'apply, check mode: its own cookie' );
cmp_assert_eq( $mg_conf( $mg_apply( array( 'mode' => 'light', 'maps_gate' => true ) ) )['m'], 'light', 'apply, Light' );
cmp_assert_eq( $mg_conf( $mg_apply( $mg_on, array( 'cmp_services' => array( 'overrides' => array( $mg_id => array( 'category' => 'statistics' ) ) ) ) ) )['c'], 'statistics', 'apply: the category the admin chose' );
cmp_assert_eq( $mg_conf( $mg_apply( $mg_on, array( 'cmp_services' => array( 'overrides' => array( $mg_id => array( 'category' => 'gone' ) ) ) ) ) )['c'], 'marketing', 'apply: a deleted category is the one the blocker uses' );

// The Servicii row: status, the switch, the note.
$mg_row = function ( array $set, $found, array $more = array() ) use ( $mg_run, $mg_with ) {
	return $mg_run( array( 'scenario' => 'row', 'host' => 'google-maps-js', 'found' => $found, 'options' => $mg_with( $set, $more ) ) )['out'];
};
$mg_sw = 'name="cmp_services[maps_gate]"';
$o = $mg_row( $mg_off, true );
cmp_assert( 2 === substr_count( $o, $mg_sw ), 'row, gate off, found: the switch is a hidden 0 and a checkbox 1' );
cmp_assert( 1 === preg_match( '#type="hidden" name="cmp_services\[maps_gate\]" value="0"#', $o ) && 1 === preg_match( '#type="checkbox" name="cmp_services\[maps_gate\]" value="1"\s*>#', $o ), 'row, gate off: the checkbox is not checked' );
cmp_assert( false !== strpos( $o, 'Probează harta după pornire' ), 'row, gate off: the note' );
cmp_assert( 1 === preg_match( '#cmp-svc-status">se încarcă înainte de acord<#', $o ), 'row, gate off: the state "se încarcă înainte de acord"' );
cmp_assert( 1 === preg_match( '#name="cmp_services\[overrides\]\[google-maps-js\]\[exempt\]" value="1"\s*>#', $o ), 'row, gate off: "nu bloca" shows the admin\'s own choice (not checked), never the gate' );
cmp_assert( false === strpos( $o, '—' ), 'row: no em dash in the new strings' );
$o = $mg_row( $mg_off, false );
cmp_assert( 2 === substr_count( $o, $mg_sw ), 'row, gate off, not found: the switch is there too' );
$o = $mg_row( $mg_on, false );
cmp_assert( 2 === substr_count( $o, $mg_sw ) && 1 === preg_match( '#type="checkbox" name="cmp_services\[maps_gate\]" value="1"\s+checked#', $o ), 'row, gate on, not found: the switch, checked' );
cmp_assert( 1 === preg_match( '#cmp-svc-status">blocat până la acord<#', $o ), 'row, gate on: the labels of a blocked service, for now' );
$o = $mg_row( $mg_off, true, array( 'cmp_services' => array( 'overrides' => array( $mg_id => array( 'exempt' => true ) ) ) ) );
cmp_assert( 1 === preg_match( '#\[exempt\]" value="1"\s+checked#', $o ) && 1 === preg_match( '#cmp-svc-status">excepție<#', $o ), 'row, gate off + the admin\'s "nu bloca": checked, "excepție"' );
$o = $mg_row( array( 'mode' => 'pro', 'block_scripts' => false, 'maps_gate' => true ), true );
cmp_assert( 1 === preg_match( '#cmp-svc-status">se încarcă înainte de acord<#', $o ), 'row, blocking off: the gate-off state (nothing is blocked)' );
cmp_assert( 1 === preg_match( '#type="checkbox" name="cmp_services\[maps_gate\]" value="1"\s+checked#', $o ), 'row, blocking off: the stored switch stays checked, so a save keeps it' );
$o = $mg_run( array( 'scenario' => 'row', 'host' => 'youtube', 'options' => $mg_base ) )['out'];
cmp_assert( false === strpos( $o, $mg_sw ), 'row: other services have no switch' );
cmp_assert( 1 === preg_match( '#cmp-svc-status">blocat până la acord<#', $o ), 'row: other services keep their label' );

// The Servicii screen: the switch, always on the row of the service; the unknown-domain hint.
$mg_screen = function ( array $found, array $set, array $more = array(), array $scan_extra = array() ) use ( $mg_run, $mg_with ) {
	return $mg_run( array( 'scenario' => 'notice', 'page' => 'services', 'options' => $mg_with( $set, $more ), 'scan' => array_merge( array( 'services' => array( 'raw' => array(), 'blocked' => array(), 'found' => $found ) ), $scan_extra ) ) );
};
$r = $mg_screen( array( $mg_id ), $mg_off );
cmp_assert( 2 === substr_count( $r['out'], $mg_sw ), 'screen: found on the site, gate off: the switch is there (once)' );
cmp_assert( 1 === substr_count( $r['out'], 'data-cmp-service="google-maps-js"' ), 'screen: and the row only once (not again in the library)' );
$r = $mg_screen( array( 'youtube' ), $mg_off );
cmp_assert( 2 === substr_count( $r['out'], $mg_sw ), 'screen: not found, gate off: the switch is in the library (once)' );
$r = $mg_screen( array( 'youtube' ), $mg_on );
cmp_assert( 2 === substr_count( $r['out'], $mg_sw ), 'screen: not found, gate on: the switch (to turn it off)' );
$mg_unk_scan = array( 'unknown_pages' => array( '/' => array( 'maps.googleapis.com', 'cdn.other-vendor.com' ) ) );
$r = $mg_screen( array( 'youtube' ), $mg_off, array(), $mg_unk_scan );
cmp_assert( 1 === preg_match( '#data-cmp-use-host="maps\.googleapis\.com" data-cmp-use-hint="[^"]*Google Maps JavaScript API[^"]*"#', $r['out'] ), 'unknown domains: "Adaugă ca serviciu" for maps.googleapis.com carries a hint pointing to the switch' );
cmp_assert( 1 === preg_match( '#data-cmp-use-host="cdn\.other-vendor\.com">#', $r['out'] ), 'unknown domains: other hosts fill the form as before' );
cmp_assert( false === strpos( $r['out'], '—' ) || substr_count( $r['out'], '—' ) === substr_count( $mg_screen( array( 'youtube' ), $mg_off )['out'], '—' ), 'screen: no new em dash' );
$mg_js = (string) file_get_contents( __DIR__ . '/../assets/js/admin.js' );
cmp_assert( false !== strpos( $mg_js, 'data-cmp-use-hint' ), 'admin.js: handles data-cmp-use-hint' );
cmp_assert( 1 === preg_match( '#data-cmp-use-hint[^;]*;[\s\S]{0,400}return#', $mg_js ), 'admin.js: the hint returns before the form is filled' );

// The save: the same "saved, rescan" logic as the services save.
$mg_save = function ( array $post, array $set, array $more = array() ) use ( $mg_run, $mg_with ) {
	return $mg_run( array( 'scenario' => 'gate_save', 'post' => $post, 'options' => $mg_with( $set, array_merge( array( 'cmp_maps_notice' => 1 ), $more ) ) ) );
};
$r_plain = $mg_save( array(), $mg_off );
$r = $mg_save( array( 'maps_gate' => '1' ), $mg_off );
cmp_assert_eq( $r['db']['cmp_settings']['maps_gate'], true, 'save: the switch on' );
cmp_assert_eq( array_diff_key( $r['db']['cmp_settings'], array( 'maps_gate' => 1 ) ), $mg_off, 'save: nothing else in the settings changed' );
cmp_assert( ! isset( $r['db']['cmp_maps_notice'] ), 'save: turning it on closes the note' );
cmp_assert( ! isset( $r['db']['cmp_services']['maps_gate'] ), 'save: the services option does not get the switch' );
cmp_assert( isset( $r['db']['cmp_scan_saved_at'] ) && isset( $r_plain['db']['cmp_scan_saved_at'] ), 'save: the scan is marked saved, as in a services save (warnings of older results stay hidden until the new scan)' );
cmp_assert( isset( $r['db']['cmp_scan_queue'] ), 'save: a new scan is queued' );
$r = $mg_save( array( 'maps_gate' => '0' ), $mg_on );
cmp_assert_eq( $r['db']['cmp_settings']['maps_gate'], false, 'save: the switch off' );
cmp_assert( isset( $r['db']['cmp_scan_saved_at'] ) && isset( $r['db']['cmp_scan_queue'] ), 'save: off also marks saved and queues the scan' );
cmp_assert( isset( $r['db']['cmp_maps_notice'] ), 'save: turning it off does not touch the note' );
$r = $mg_save( array(), $mg_on );
cmp_assert_eq( $r['db']['cmp_settings'], $mg_on, 'save: a form without the switch leaves the setting alone (on)' );
$r = $mg_save( array(), $mg_off );
cmp_assert_eq( $r['db']['cmp_settings'], $mg_off, 'save: ... and absent stays absent' );
cmp_assert( isset( $r['db']['cmp_maps_notice'] ), 'save: ... and the note stays' );
$r = $mg_save( array( 'maps_gate' => '0' ), $mg_off );
cmp_assert( array_key_exists( 'maps_gate', $r['db']['cmp_settings'] ) && false === $r['db']['cmp_settings']['maps_gate'], 'save: an explicit off is stored as an explicit false' );
$r = $mg_save( array( 'maps_gate' => '1', 'overrides' => array( $mg_id => array( 'category' => 'statistics' ) ) ), $mg_off );
cmp_assert( true === $r['db']['cmp_settings']['maps_gate'] && isset( $r['db']['cmp_services']['overrides'][ $mg_id ] ), 'save: the switch and the service overrides in one save' );
$mg_sp = (string) file_get_contents( __DIR__ . '/../includes/admin/class-services-page.php' );
cmp_assert( 1 === preg_match( '/function save\(\)[\s\S]*?CMP_Scanner::mark_saved\(\);[\s\S]*?CMP_Scanner::enqueue_all\(\);/', $mg_sp ), 'services page: save() marks saved and queues the scan, once, for both' );

// The one-time note.
$mg_scan = function ( array $found ) {
	return array( 'services' => array( 'raw' => array(), 'blocked' => array(), 'found' => $found ) );
};
$mg_note = function ( array $found, array $set = array(), $page = '', array $more = array() ) use ( $mg_run, $mg_with, $mg_scan, $mg_off ) {
	return $mg_run( array( 'scenario' => 'maps_notice', 'page' => $page, 'options' => $mg_with( $set ? $set : $mg_off, array_merge( array( 'cmp_maps_notice' => 1 ), $more ) ), 'scan' => $mg_scan( $found ) ) );
};
$r = $mg_note( array( $mg_id, 'youtube' ) );
cmp_assert( false !== strpos( $r['out'], 'notice' ) && false !== strpos( $r['out'], 'înainte de acordul vizitatorului' ), 'note: the map loads before consent, as today' );
cmp_assert( false !== strpos( $r['out'], 'page=cmp-services' ) && false !== strpos( $r['out'], 'cmp_dismiss_maps_notice' ), 'note: links to Servicii and can be closed' );
cmp_assert( false === strpos( $r['out'], '—' ), 'note: no em dash' );
cmp_assert( isset( $r['db']['cmp_maps_notice'] ), 'note: showing it on another screen does not clear it' );
cmp_assert_eq( $mg_note( array( 'youtube' ) )['out'], '', 'note: nothing while the scan does not find the service' );
cmp_assert_eq( $mg_run( array( 'scenario' => 'maps_notice', 'options' => $mg_with( $mg_off, array( 'cmp_maps_notice' => 1 ) ) ) )['out'], '', 'note: nothing without a scan' );
cmp_assert_eq( $mg_note( array( $mg_id ), $mg_on )['out'], '', 'note: nothing once the gate is on' );
cmp_assert_eq( $mg_note( array( $mg_id ), array( 'mode' => 'pro', 'block_scripts' => false ) )['out'], '', 'note: nothing while blocking is off' );
cmp_assert_eq( $mg_run( array( 'scenario' => 'maps_notice', 'options' => $mg_base, 'scan' => $mg_scan( array( $mg_id ) ) ) )['out'], '', 'note: nothing without the flag (a fresh install never has it)' );
$r = $mg_run( array( 'scenario' => 'maps_notice', 'get' => array( 'page' => 'cmp-services' ), 'options' => $mg_with( $mg_off, array( 'cmp_maps_notice' => 1 ) ), 'scan' => $mg_scan( array( $mg_id ) ) ) );
cmp_assert( '' === $r['out'] && isset( $r['db']['cmp_maps_notice'] ), 'note: admin_notices on the Servicii screen prints nothing (the screen itself does) and clears nothing' );
$r = $mg_note( array( $mg_id ), array(), 'services' );
cmp_assert( 1 === preg_match( '#<div class="wrap cmp-admin">.*înainte de acordul vizitatorului#s', $r['out'] ), 'note: the Servicii screen shows it' );
cmp_assert( ! isset( $r['db']['cmp_maps_notice'] ), 'note: ... once, then it is cleared' );
$r = $mg_note( array( 'youtube' ), array(), 'services' );
cmp_assert( false === strpos( $r['out'], 'înainte de acordul vizitatorului' ) && isset( $r['db']['cmp_maps_notice'] ), 'note: opening Servicii before it is found shows nothing and keeps it' );
$r = $mg_note( array( $mg_id ), array(), 'dismiss' );
cmp_assert( ! isset( $r['db']['cmp_maps_notice'] ), 'note: "Închide" clears it for good' );
cmp_assert( ! isset( $mg_note( array( 'youtube' ), array(), 'dismiss' )['db']['cmp_maps_notice'] ), 'note: ... also when the scan changed since it was shown' );
$r = $mg_note( array( $mg_id ), array(), '', array( 'cmp_services_notice' => array( 'google-adsense' ) ) );
cmp_assert( isset( $r['db']['cmp_services_notice'] ), 'note: the update note of lot A is not touched' );
// The lot A note is not disturbed: it still says what it said, and does not name the map.
$r = $mg_run( array( 'scenario' => 'notice', 'options' => $mg_with( $mg_off, array( 'cmp_services_notice' => 1 ) ), 'scan' => array( 'services' => array( 'raw' => array(), 'blocked' => array( $mg_id, 'hotjar' ), 'found' => array( $mg_id, 'hotjar' ) ) ) ) );
cmp_assert( false !== strpos( $r['out'], 'Hotjar' ) && false === strpos( $r['out'], 'Google Maps' ), 'lot A note: names Hotjar, not the map (it does not wait for consent yet)' );

// Upgrade: the flag is set for existing installs only.
$mg_up = function ( $old, array $more = array(), $version = '1.2.0' ) use ( $mg_run, $mg_base ) {
	$o = array_merge( $mg_base, $more );
	if ( false !== $old ) {
		$o['cmp_db_version'] = $old;
	}
	$r = $mg_run( array( 'scenario' => 'upgrade', 'options' => $o, 'version' => $version ) );
	return $r['db'];
};
cmp_assert_eq( isset( $mg_up( '1.1.1' )['cmp_maps_notice'] ), true, 'upgrade 1.1.1 -> 1.2.0: the note is flagged' );
cmp_assert_eq( isset( $mg_up( '0.9.0' )['cmp_maps_notice'] ), true, 'upgrade 0.9.0 -> 1.2.0: flagged' );
cmp_assert_eq( isset( $mg_up( false )['cmp_maps_notice'] ), false, 'fresh install: no flag' );
cmp_assert_eq( isset( $mg_up( '1.2.0', array(), '1.2.1' )['cmp_maps_notice'] ), false, 'upgrade 1.2.0 -> 1.2.1: no flag' );
cmp_assert_eq( isset( $mg_up( '1.1.1', array( 'cmp_settings' => array_merge( $mg_off, array( 'maps_gate' => true ) ) ) )['cmp_maps_notice'] ), false, 'upgrade: no flag when the setting is already there' );
cmp_assert_eq( $mg_up( '1.1.1' )['cmp_settings'], $mg_off, 'upgrade: the settings are not given a maps_gate (absent means off)' );
