<?php
require_once __DIR__ . '/../includes/class-services.php';

$lib = CMP_Services::library();
cmp_assert_eq(
	array_slice( array_column( $lib, 'id' ), 0, 6 ),
	array( 'google-analytics', 'google-tag-manager', 'meta-pixel', 'youtube', 'vimeo', 'google-maps' ),
	'library starts with the six original services in order'
);
$cmp_expected_ids = array( 'google-analytics', 'google-tag-manager', 'meta-pixel', 'youtube', 'vimeo', 'google-maps', 'clarity', 'hotjar', 'tiktok-pixel', 'linkedin-insight', 'hubspot', 'tawk-to', 'crisp', 'recaptcha', 'umami', 'plausible', 'fathom', 'matomo' );
$cmp_have_ids     = array_column( $lib, 'id' );
sort( $cmp_expected_ids );
sort( $cmp_have_ids );
cmp_assert_eq( $cmp_have_ids, $cmp_expected_ids, 'library holds exactly the 18 expected ids' );
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

// ---- Task 4: all() / active() ----
$cmp_slugs = array( 'necessary', 'statistics', 'marketing' );
$cmp_all   = CMP_Services::all( $lib, array( 'overrides' => array( 'clarity' => array( 'category' => 'statistics', 'exempt' => true ) ) ), $cmp_slugs );
$cmp_by    = array_column( $cmp_all, null, 'id' );
cmp_assert_eq( count( $cmp_all ), 18, 'all() without custom keeps 18 services' );
cmp_assert_eq( $cmp_by['clarity']['category'], 'statistics', 'override category applied' );
cmp_assert_eq( $cmp_by['clarity']['exempt'], true, 'override exempt applied' );
cmp_assert_eq( $cmp_by['clarity']['source'], 'library', 'library service has source library' );
cmp_assert_eq( $cmp_by['youtube']['category'], $lib[3]['category'], 'untouched service keeps its category' );

$cmp_all = CMP_Services::all( $lib, array( 'overrides' => array( 'clarity' => array( 'category' => 'foo' ), 'recaptcha' => array( 'category' => 'necessary' ) ) ), $cmp_slugs );
$cmp_by  = array_column( $cmp_all, null, 'id' );
cmp_assert_eq( $cmp_by['clarity']['category'], 'marketing', 'unknown category remapped to marketing' );
cmp_assert_eq( $cmp_by['recaptcha']['category'], 'necessary', 'necessary is never remapped' );

// Empty slug list = unknown -> no remapping.
$cmp_all = CMP_Services::all( $lib, array( 'overrides' => array( 'clarity' => array( 'category' => 'foo' ) ) ), array() );
$cmp_by  = array_column( $cmp_all, null, 'id' );
cmp_assert_eq( $cmp_by['clarity']['category'], 'foo', 'empty slug list: no remap on override' );
$cmp_orig = array_column( $lib, null, 'id' );
cmp_assert_eq( $cmp_by['recaptcha']['category'], $cmp_orig['recaptcha']['category'], 'empty slug list: library category untouched' );
$cmp_lib_cat = array( array_merge( $lib[0], array( 'category' => 'zzz' ) ) );
$cmp_r       = CMP_Services::all( $cmp_lib_cat, array(), $cmp_slugs );
cmp_assert_eq( $cmp_r[0]['category'], 'marketing', 'non-empty list remaps unknown library category' );

$cmp_custom = array(
	'custom' => array(
		array(
			'id' => 'custom-1', 'name' => 'Chat X', 'category' => 'bogus', 'exempt' => false,
			'hosts' => array( 'chatx.io' ),
			'cookies' => array( array( 'name' => 'cx', 'duration' => '1 an', 'purpose' => 'Chat' ) ),
			'script' => '<script>1</script>',
		),
	),
);
$cmp_all = CMP_Services::all( $lib, $cmp_custom, $cmp_slugs );
cmp_assert_eq( count( $cmp_all ), 19, 'custom service appended' );
$cx = $cmp_all[18];
cmp_assert_eq( $cx['source'], 'custom', 'custom source' );
cmp_assert_eq( $cx['type'], 'script', 'custom type script' );
cmp_assert_eq( $cx['category'], 'marketing', 'custom unknown category remapped' );
cmp_assert_eq( $cx['inline'], array(), 'custom inline empty' );
cmp_assert_eq( $cx['transfer'], '', 'custom transfer empty' );
cmp_assert_eq( $cx['privacy_url'], '', 'custom privacy_url empty' );
cmp_assert_eq( $cx['cookies'][0]['duration'], array( 'ro' => '1 an', 'ru' => '1 an', 'en' => '1 an' ), 'custom cookie duration expanded' );
cmp_assert_eq( $cx['cookies'][0]['purpose'], array( 'ro' => 'Chat', 'ru' => 'Chat', 'en' => 'Chat' ), 'custom cookie purpose expanded' );
cmp_assert_eq( $cx['cookies'][0]['name'], 'cx', 'custom cookie name kept' );
foreach ( array_keys( $lib[0] ) as $k ) {
	cmp_assert( array_key_exists( $k, $cx ), "custom service has key {$k}" );
}
$cmp_found = CMP_Services::script_service( 'https://chatx.io/w.js', '', $cmp_all );
cmp_assert( is_array( $cmp_found ) && 'custom-1' === $cmp_found['id'], 'custom service matched by host' );

$cmp_act = CMP_Services::active();
$cmp_exp = array_map(
	function ( $s ) {
		if ( ! empty( $s['exempt_default'] ) ) {
			$s['exempt'] = true;
		}
		$s['source'] = 'library';
		return $s;
	},
	$lib
);
cmp_assert_eq( $cmp_act, $cmp_exp, 'active() without WP equals library() plus source' );
cmp_assert_eq( array_column( CMP_Services::all( $lib, array( 'seq' => 9 ), array() ), 'id' ), array_column( $lib, 'id' ), 'all() ignores seq' );

// newly_blocked: found ids that 0.4.x did not already block.
cmp_assert_eq( CMP_Services::newly_blocked( array( 'clarity', 'google-analytics', 'recaptcha' ), $cmp_act ), array( 'clarity' ), 'newly_blocked: legacy and necessary ids dropped' );
$cmp_ex = CMP_Services::all( $lib, array( 'overrides' => array( 'clarity' => array( 'exempt' => true ) ) ), array() );
cmp_assert_eq( CMP_Services::newly_blocked( array( 'clarity', 'hotjar' ), $cmp_ex ), array( 'hotjar' ), 'newly_blocked: exempt excluded' );
$cmp_cu = CMP_Services::all( $lib, array( 'custom' => array( array( 'id' => 'custom-1', 'name' => 'Chat X', 'category' => 'marketing', 'hosts' => array( 'chatx.io' ) ) ) ), array() );
cmp_assert_eq( CMP_Services::newly_blocked( array( 'custom-1' ), $cmp_cu ), array( 'custom-1' ), 'newly_blocked: custom service included' );
cmp_assert_eq( CMP_Services::newly_blocked( array(), $cmp_act ), array(), 'newly_blocked: empty input' );
cmp_assert_eq( CMP_Services::errors(), array(), 'errors(): none for the real library' );

// found_ids (scan helper): lists every service on the page, exempt and necessary included.
require_once __DIR__ . '/../includes/modules/class-blocker.php';
$cmp_ex2  = CMP_Services::all( $lib, array( 'overrides' => array( 'tawk-to' => array( 'exempt' => true ) ) ), array() );
$cmp_page = '<script src="https://embed.tawk.to/abc/default"></script><script src="https://www.google.com/recaptcha/api.js"></script><script src="https://www.clarity.ms/tag/x"></script>';
$cmp_fnd  = CMP_Blocker::found_ids( $cmp_page, $cmp_ex2 );
cmp_assert( in_array( 'tawk-to', $cmp_fnd, true ), 'found_ids: exempt tawk-to found' );
cmp_assert( in_array( 'recaptcha', $cmp_fnd, true ), 'found_ids: necessary recaptcha found' );
cmp_assert( in_array( 'clarity', $cmp_fnd, true ), 'found_ids: normal service found' );
cmp_assert_eq( CMP_Blocker::detect_services( $cmp_page, $cmp_ex2 )['raw'], array( 'clarity' ), 'detect_services unchanged: raw excludes exempt and necessary' );
cmp_assert_eq( CMP_Blocker::found_ids( '<p>hi</p>', $cmp_ex2 ), array(), 'found_ids: nothing on a clean page' );

// ---- B2 Task 1: cookieless analytics tools, exempt_default ----
$cmp_cl_ids = array( 'umami', 'plausible', 'fathom' );
cmp_assert_eq( count( $lib ), 18, 'library has 18 services' );
$cmp_cl_by = array_column( $lib, null, 'id' );
foreach ( $cmp_cl_ids as $cmp_cl ) {
	cmp_assert( isset( $cmp_cl_by[ $cmp_cl ] ), "{$cmp_cl} in library" );
	cmp_assert_eq( $cmp_cl_by[ $cmp_cl ]['type'], 'script', "{$cmp_cl}: type script" );
	cmp_assert_eq( $cmp_cl_by[ $cmp_cl ]['category'], 'statistics', "{$cmp_cl}: statistics" );
	cmp_assert_eq( $cmp_cl_by[ $cmp_cl ]['cookies'], array(), "{$cmp_cl}: no cookies" );
	cmp_assert( ! empty( $cmp_cl_by[ $cmp_cl ]['exempt_default'] ), "{$cmp_cl}: exempt_default" );
	cmp_assert( ! empty( $cmp_cl_by[ $cmp_cl ]['hosts'] ) && ! empty( $cmp_cl_by[ $cmp_cl ]['inline'] ), "{$cmp_cl}: hosts and markers" );
}
cmp_assert( isset( $cmp_cl_by['matomo'] ) && empty( $cmp_cl_by['matomo']['exempt_default'] ), 'matomo: in library, NOT exempt by default' );
cmp_assert_eq( array_column( $cmp_cl_by['matomo']['cookies'], 'name' ), array( '_pk_id', '_pk_ses', '_pk_ref' ), 'matomo: standard cookies listed (_pk_ref added after vendor check, final fix T1)' );
cmp_assert_eq( $cmp_cl_by['matomo']['hosts'], array( 'matomo.cloud' ), 'matomo: hosts' );
cmp_assert_eq( CMP_Services::validate( array( 'services' => $lib ) ), array(), 'library with cookieless tools validates' );
$cmp_cl_all = array_column( CMP_Services::all( $lib, array(), array() ), null, 'id' );
cmp_assert_eq( $cmp_cl_all['umami']['exempt'], true, 'all(): umami exempt by default' );
cmp_assert( empty( $cmp_cl_all['clarity']['exempt'] ), 'all(): clarity not exempt by default' );
$cmp_cl_ov = array_column( CMP_Services::all( $lib, array( 'overrides' => array( 'umami' => array( 'exempt' => false ) ) ), array() ), null, 'id' );
cmp_assert_eq( $cmp_cl_ov['umami']['exempt'], false, 'all(): override exempt=false beats exempt_default' );
// New hosts must not take ownership of existing services (and vice versa).
$cmp_cl_urls = array(
	'umami'             => 'https://cloud.umami.is/script.js',
	'plausible'         => 'https://plausible.io/js/script.js',
	'fathom'            => 'https://cdn.usefathom.com/script.js',
	'matomo'            => 'https://cdn.matomo.cloud/acme/matomo.js',
);
foreach ( $cmp_cl_urls as $cmp_cl => $cmp_u ) {
	$cmp_m = CMP_Services::script_service( $cmp_u, '', $cmp_cl_all );
	cmp_assert( null !== $cmp_m && $cmp_cl === $cmp_m['id'], "{$cmp_cl}: its loader URL maps to it" );
}
foreach ( $cmp_cl_by as $cmp_id => $cmp_s ) {
	if ( in_array( $cmp_id, array_merge( $cmp_cl_ids, array( 'matomo' ) ), true ) || 'script' !== $cmp_s['type'] ) {
		continue;
	}
	foreach ( $cmp_s['hosts'] as $cmp_h ) {
		foreach ( $cmp_cl_urls as $cmp_cl => $cmp_u ) {
			cmp_assert( ! CMP_Services::host_matches( $cmp_u, $cmp_h ), "{$cmp_id} host {$cmp_h} does not own {$cmp_cl}" );
		}
	}
}
foreach ( array( 'https://www.clarity.ms/tag/x', 'https://static.hotjar.com/c/hotjar-1.js', 'https://www.googletagmanager.com/gtag/js?id=G-1', 'https://connect.facebook.net/en_US/fbevents.js' ) as $cmp_u ) {
	$cmp_m = CMP_Services::script_service( $cmp_u, '', $cmp_cl_all );
	cmp_assert( null !== $cmp_m && ! in_array( $cmp_m['id'], $cmp_cl_ids, true ), 'existing loader not taken by a cookieless tool: ' . $cmp_u );
}

// ---- B2 Task 2: measurement mode ----
cmp_assert( true === CMP_Services::measurement_active( array( 'mode' => 'pro', 'block_scripts' => true, 'measurement' => 'advanced' ) ), 'measurement_active: pro + blocking + advanced' );
cmp_assert( false === CMP_Services::measurement_active( array( 'mode' => 'light', 'block_scripts' => true, 'measurement' => 'advanced' ) ), 'measurement_active: light -> false' );
cmp_assert( false === CMP_Services::measurement_active( array( 'mode' => 'pro', 'block_scripts' => false, 'measurement' => 'advanced' ) ), 'measurement_active: blocking off -> false' );
cmp_assert( false === CMP_Services::measurement_active( array( 'mode' => 'pro', 'block_scripts' => true, 'measurement' => 'strict' ) ), 'measurement_active: strict -> false' );
cmp_assert( false === CMP_Services::measurement_active( array( 'mode' => 'pro', 'block_scripts' => true ) ), 'measurement_active: unset -> false' );
cmp_assert( false === CMP_Services::measurement_active( array() ), 'measurement_active: empty settings -> false' );

$cmp_ma_lib = CMP_Services::library();
$cmp_ma_on  = CMP_Services::apply_measurement( $cmp_ma_lib, true );
$cmp_ma_by  = array();
foreach ( $cmp_ma_on as $cmp_s ) {
	$cmp_ma_by[ $cmp_s['id'] ] = $cmp_s;
}
cmp_assert( true === $cmp_ma_by['google-analytics']['exempt'] && true === $cmp_ma_by['google-analytics']['mode_exempt'], 'apply_measurement(true): GA exempt' );
cmp_assert( true === $cmp_ma_by['google-tag-manager']['exempt'] && true === $cmp_ma_by['google-tag-manager']['mode_exempt'], 'apply_measurement(true): GTM exempt' );
cmp_assert( empty( $cmp_ma_by['meta-pixel']['exempt'] ) && ! isset( $cmp_ma_by['meta-pixel']['mode_exempt'] ), 'apply_measurement(true): Meta Pixel untouched' );
cmp_assert_eq( count( $cmp_ma_on ), count( $cmp_ma_lib ), 'apply_measurement(true): same number of services' );
cmp_assert_eq( CMP_Services::apply_measurement( $cmp_ma_lib, false ), $cmp_ma_lib, 'apply_measurement(false): services unchanged' );
$cmp_ma_over = CMP_Services::all( $cmp_ma_lib, array( 'overrides' => array( 'google-analytics' => array( 'exempt' => false ) ) ), array() );
$cmp_ma_ov2  = CMP_Services::apply_measurement( $cmp_ma_over, true );
foreach ( $cmp_ma_ov2 as $cmp_s ) {
	if ( 'google-analytics' === $cmp_s['id'] ) {
		cmp_assert( true === $cmp_s['exempt'], 'apply_measurement(true): the mode wins over a per-service override' );
	}
}
