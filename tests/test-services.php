<?php
require_once __DIR__ . '/../includes/class-services.php';

$lib = CMP_Services::library();
cmp_assert_eq(
	array_slice( array_column( $lib, 'id' ), 0, 6 ),
	array( 'google-analytics', 'google-tag-manager', 'meta-pixel', 'youtube', 'vimeo', 'google-maps' ),
	'library starts with the six original services in order'
);
$cmp_expected_ids = array( 'google-analytics', 'google-tag-manager', 'meta-pixel', 'youtube', 'vimeo', 'google-maps', 'clarity', 'hotjar', 'tiktok-pixel', 'linkedin-insight', 'hubspot', 'tawk-to', 'crisp', 'recaptcha', 'umami', 'plausible', 'fathom', 'matomo', 'google-adsense', 'cloudflare-turnstile', 'google-maps-js' );
$cmp_have_ids     = array_column( $lib, 'id' );
sort( $cmp_expected_ids );
sort( $cmp_have_ids );
cmp_assert_eq( $cmp_have_ids, $cmp_expected_ids, 'library holds exactly the 21 expected ids' );
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
cmp_assert_eq( count( $cmp_all ), 21, 'all() without custom keeps 21 services' );
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
cmp_assert_eq( count( $cmp_all ), 22, 'custom service appended' );
$cx = $cmp_all[21];
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
// Without WordPress the maps gate is off: its service is exempt through the setting (apply_gate()), nothing else changes.
$cmp_exp = CMP_Services::apply_gate( $cmp_exp, false );
cmp_assert_eq( $cmp_act, $cmp_exp, 'active() without WP equals library() plus source (and the gate service exempt)' );
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
cmp_assert_eq( count( $lib ), 21, 'library has 21 services' );
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

// ---------------------------------------------------------------------------
// Lot A, M9 / R1: a custom service with a pattern on the two services 1.2.0 adds
// (AdSense, Turnstile) decides for the URLs its own patterns match, and only for them.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../includes/modules/class-blocker.php';
require_once __DIR__ . '/../includes/modules/class-scanner.php';
require_once __DIR__ . '/../includes/modules/class-legal.php';
$ya_lib = CMP_Services::library();
$ya_all = function ( array $custom, array $over = array() ) use ( $ya_lib ) {
	$list = array();
	foreach ( $custom as $i => $c ) {
		$list[] = array_merge( array( 'id' => 'custom-' . ( $i + 1 ), 'name' => 'Al meu ' . ( $i + 1 ), 'category' => 'marketing', 'exempt' => false ), $c );
	}
	return CMP_Services::all( $ya_lib, array( 'custom' => $list, 'overrides' => $over ), array( 'necessary', 'statistics', 'marketing', 'reclame' ) );
};
$ya_get = function ( array $svcs, $id ) {
	foreach ( $svcs as $s ) {
		if ( $s['id'] === $id ) {
			return $s;
		}
	}
	return null;
};
$ya_lvl = function ( array $svcs, $url ) { // [ level, service id ] of one resource in the browser check
	$i = CMP_Scanner::classify( array( $url ), array(), $svcs, 'https://example.md', 'pro' )['items'][0];
	return array( $i[0], $i[3] );
};
$ya_js   = 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js';
$ya_tag  = '<script async src="' . $ya_js . '?client=ca-pub-1"></script>';
$ya_dc   = '<script src="https://googleads.g.doubleclick.net/pagead/x.js"></script>';
$ya_inl  = '<script>var s=document.createElement("script");s.src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=1";</script>';
$ya_esc  = '<script>var u="https:\/\/pagead2.googlesyndication.com\/pagead\/js\/adsbygoogle.js";</script>';
$ya_full = array( 'pagead2.googlesyndication.com', 'googleads.g.doubleclick.net' );
$ya_is   = function ( $html, $id ) { // blocked as this service
	return false !== strpos( $html, 'data-cmp-service="' . $id . '"' );
};
$ya_rw   = function ( $html, array $svcs ) {
	return CMP_Blocker::rewrite( $html, array(), $svcs );
};
$ya_none = $ya_all( array( array( 'hosts' => array( 'vendor.com' ) ) ) );
cmp_assert( ! isset( $ya_get( $ya_none, 'google-adsense' )['except'] ) && $ya_is( $ya_rw( $ya_tag, $ya_none ), 'google-adsense' ), 'except: an unrelated custom service changes nothing' );
cmp_assert( $ya_is( $ya_rw( $ya_inl, $ya_none ), 'google-adsense' ) && $ya_is( $ya_rw( $ya_esc, $ya_none ), 'google-adsense' ), 'except: an inline loader is blocked by the library' );
// "nu bloca" on the bare host(s) is kept everywhere.
$ya_ex = $ya_all( array( array( 'exempt' => true, 'hosts' => $ya_full ) ) );
foreach ( array( $ya_tag, $ya_dc, $ya_inl, $ya_esc, '<noscript><img src="https://pagead2.googlesyndication.com/p.gif"></noscript>', '<link rel="preconnect" href="https://pagead2.googlesyndication.com">' ) as $ya_h ) {
	cmp_assert_eq( $ya_rw( $ya_h, $ya_ex ), $ya_h, 'except, both hosts on "nu bloca": left as it is: ' . substr( $ya_h, 0, 60 ) );
	cmp_assert( $ya_rw( $ya_h, $ya_none ) !== $ya_h, 'fixture: the library alone changes it: ' . substr( $ya_h, 0, 60 ) );
}
cmp_assert_eq( CMP_Blocker::detect_services( $ya_tag . $ya_esc, $ya_ex )['raw'], array(), 'except: ... not reported as loading without consent' );
cmp_assert_eq( CMP_Blocker::found_ids( $ya_tag, $ya_ex ), array( 'custom-1' ), 'except: ... the scan finds the custom service' );
cmp_assert_eq( CMP_Scanner::script_sources( $ya_tag, $ya_ex, 'https://example.md' ), array( 'custom-1' => array( array( 'kind' => 'external', 'slug' => 'pagead2.googlesyndication.com' ) ) ), 'except: ... and its source' );
cmp_assert_eq( $ya_lvl( $ya_ex, $ya_js ), array( 'exempt', 'custom-1' ), 'except: ... the check names it, not red' );
cmp_assert_eq( CMP_Scanner::unknown_hosts( $ya_tag, $ya_ex, 'https://example.md' ), array(), 'except: ... the host is not unknown' );
cmp_assert( CMP_Services::replaced( $ya_get( $ya_ex, 'google-adsense' ) ) && ! CMP_Services::replaced( $ya_get( $ya_none, 'google-adsense' ) ) && ! CMP_Services::replaced( $ya_get( $ya_none, 'hotjar' ) ), 'replaced: every host is decided by a custom service' );
cmp_assert_eq( CMP_Services::notice_ids( array( 'google-adsense' ), array( 'google-adsense' ), $ya_ex, true ), array(), 'except: the update note does not name it' );
// One bare host frees that host only.
$ya_one = $ya_all( array( array( 'exempt' => true, 'hosts' => array( 'pagead2.googlesyndication.com' ) ) ) );
cmp_assert( $ya_rw( $ya_tag, $ya_one ) === $ya_tag && $ya_is( $ya_rw( $ya_dc, $ya_one ), 'google-adsense' ), 'except: a bare host frees that host, not the other one of the service' );
cmp_assert( ! CMP_Services::replaced( $ya_get( $ya_one, 'google-adsense' ) ), 'replaced: not with one host of two' );
cmp_assert_eq( CMP_Blocker::found_ids( $ya_tag . $ya_dc, $ya_one ), array( 'google-adsense', 'custom-1' ), 'except: the scan finds both' );
cmp_assert_eq( array( $ya_lvl( $ya_one, $ya_js ), $ya_lvl( $ya_one, 'https://googleads.g.doubleclick.net/x' ) ), array( array( 'exempt', 'custom-1' ), array( 'red', 'google-adsense' ) ), 'except: the check tells them apart' );
cmp_assert_eq( $ya_rw( str_replace( 'pagead2.', 'eu.pagead2.', $ya_tag ), $ya_one ), str_replace( 'pagead2.', 'eu.pagead2.', $ya_tag ), 'except: a bare host covers its subdomains, as the matcher does' );
// Its category is kept.
$ya_cat = $ya_all( array( array( 'category' => 'reclame', 'hosts' => array( 'pagead2.googlesyndication.com' ) ) ) );
$ya_h   = $ya_rw( $ya_tag, $ya_cat );
cmp_assert( false !== strpos( $ya_h, 'data-cmp-block="reclame"' ) && $ya_is( $ya_h, 'custom-1' ), 'except: the category of the custom service decides' );
cmp_assert( $ya_is( $ya_rw( $ya_inl, $ya_cat ), 'custom-1' ), 'except: ... for an inline loader too' );
cmp_assert_eq( $ya_rw( '<noscript><img src="https://pagead2.googlesyndication.com/p.gif"></noscript>', $ya_cat ), '', 'except: ... its noscript is dropped as any blocked one' );
// R1: the three probes. A path entry decides for its own path only; the real loader stays with the library.
foreach ( array(
	array( 'pagead2.googlesyndication.com/pagead/managed/', true, 'https://pagead2.googlesyndication.com/pagead/managed/js/x.js' ),
	array( 'pagead2.googlesyndication.com/ccm/', false, 'https://pagead2.googlesyndication.com/ccm/x.js' ),
	array( 'googlesyndication.com/safeframe/', true, 'https://pagead2.googlesyndication.com/safeframe/x.js' ),
) as $ya_p ) {
	$ya_s = $ya_all( array( array( 'exempt' => $ya_p[1], 'hosts' => array( $ya_p[0] ) ) ) );
	$ya_o = '<script src="' . $ya_p[2] . '"></script>';
	cmp_assert( $ya_is( $ya_rw( $ya_tag, $ya_s ), 'google-adsense' ), "R1 {$ya_p[0]}: adsbygoogle.js stays blocked by the library" );
	cmp_assert( $ya_is( $ya_rw( $ya_inl, $ya_s ), 'google-adsense' ) && $ya_is( $ya_rw( $ya_esc, $ya_s ), 'google-adsense' ), "R1 {$ya_p[0]}: an inline loader of it too" );
	cmp_assert( $ya_p[1] ? $ya_rw( $ya_o, $ya_s ) === $ya_o : $ya_is( $ya_rw( $ya_o, $ya_s ), 'custom-1' ), "R1 {$ya_p[0]}: its own path is the custom service's" );
	cmp_assert_eq( array( $ya_lvl( $ya_s, $ya_js ), $ya_lvl( $ya_s, $ya_p[2] ) ), array( array( 'red', 'google-adsense' ), array( $ya_p[1] ? 'exempt' : 'red', 'custom-1' ) ), "R1 {$ya_p[0]}: the check agrees" );
	cmp_assert( in_array( 'google-adsense', CMP_Blocker::found_ids( $ya_tag, $ya_s ), true ) && array( 'google-adsense' ) === CMP_Blocker::detect_services( $ya_tag, $ya_s )['raw'], "R1 {$ya_p[0]}: a live loader is reported as AdSense" );
	cmp_assert( ! in_array( 'google-adsense', CMP_Blocker::found_ids( $ya_o, $ya_s ), true ), "R1 {$ya_p[0]}: its own path alone is not AdSense" );
	cmp_assert( ! CMP_Services::replaced( $ya_get( $ya_s, 'google-adsense' ) ), "R1 {$ya_p[0]}: the library service is not replaced" );
	cmp_assert_eq( $ya_rw( '<noscript><img src="https://pagead2.googlesyndication.com/p.gif"></noscript>', $ya_s ), '', "R1 {$ya_p[0]}: another noscript of the host is dropped" );
	cmp_assert_eq( $ya_rw( '<noscript><img src="' . $ya_p[2] . '"></noscript>', $ya_s ), $ya_p[1] ? '<noscript><img src="' . $ya_p[2] . '"></noscript>' : '', "R1 {$ya_p[0]}: a noscript of its own path follows it" );
}
// A subdomain entry does not free the parent host.
$ya_sub = $ya_all( array( array( 'exempt' => true, 'hosts' => array( 'x.pagead2.googlesyndication.com' ) ) ) );
$ya_o   = '<script src="https://x.pagead2.googlesyndication.com/a.js"></script>';
cmp_assert( $ya_is( $ya_rw( $ya_tag, $ya_sub ), 'google-adsense' ) && $ya_rw( $ya_o, $ya_sub ) === $ya_o, 'except: a subdomain entry frees the subdomain, not the parent' );
// The full URL of the loader on "nu bloca" works, pasted as normalize_host() stores it.
require_once __DIR__ . '/../includes/class-sanitizer.php';
$ya_url = $ya_all( array( array( 'exempt' => true, 'hosts' => array( CMP_Sanitizer::normalize_host( $ya_js . '?client=ca-pub-1' ) ) ) ) );
cmp_assert( $ya_rw( $ya_tag, $ya_url ) === $ya_tag && $ya_rw( $ya_inl, $ya_url ) === $ya_inl && $ya_rw( $ya_esc, $ya_url ) === $ya_esc, 'except: the full adsbygoogle.js URL on "nu bloca"' );
cmp_assert( $ya_is( $ya_rw( '<script src="https://pagead2.googlesyndication.com/pagead/show_ads.js"></script>', $ya_url ), 'google-adsense' ), 'except: ... and nothing else of the host' );
cmp_assert( $ya_is( $ya_rw( '<script>var a="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js",b="https://pagead2.googlesyndication.com/pagead/show_ads.js";</script>', $ya_url ), 'google-adsense' ), 'except: an inline script that also names another URL of the host stays blocked' );
// A parent domain covers the host; look-alikes do not.
cmp_assert_eq( $ya_rw( $ya_tag, $ya_all( array( array( 'exempt' => true, 'hosts' => array( 'googlesyndication.com' ) ) ) ) ), $ya_tag, 'except: the parent domain' );
foreach ( array( 'evilpagead2.googlesyndication.com', 'notgooglesyndication.com', 'pagead2.googlesyndication.com.evil.md' ) as $ya_p ) {
	$ya_s = $ya_all( array( array( 'exempt' => true, 'hosts' => array( $ya_p ) ) ) );
	cmp_assert( $ya_is( $ya_rw( $ya_tag, $ya_s ), 'google-adsense' ) && ! isset( $ya_get( $ya_s, 'google-adsense' )['except'] ), "except: $ya_p is another host" );
}
// Only an inline marker / its own script, no hosts: the library decides.
$ya_in = $ya_all( array( array( 'exempt' => true, 'hosts' => array(), 'script' => '<script>window.adsbygoogle=[];</script>' ) ) );
cmp_assert( $ya_is( $ya_rw( $ya_tag, $ya_in ), 'google-adsense' ) && ! isset( $ya_get( $ya_in, 'google-adsense' )['except'] ), 'except: a custom service without domains takes nothing' );
// Turnstile: necessary in the library; a custom service that blocks it keeps blocking it, everywhere.
$ya_ts = $ya_all( array( array( 'hosts' => array( 'challenges.cloudflare.com' ) ) ) );
$ya_t  = '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js"></script>';
cmp_assert( $ya_is( $ya_rw( $ya_t, $ya_ts ), 'custom-1' ) && array( 'red', 'custom-1' ) === $ya_lvl( $ya_ts, 'https://challenges.cloudflare.com/x' ) && array( 'custom-1' ) === CMP_Blocker::found_ids( $ya_t, $ya_ts ), 'except: a custom Turnstile in marketing: blocked, checked and found as such' );
// Only these two ids.
$ya_old = $ya_all( array( array( 'exempt' => true, 'hosts' => array( 'connect.facebook.net', 'static.hotjar.com', 'www.google.com' ) ) ) );
foreach ( array( 'meta-pixel', 'hotjar', 'recaptcha', 'google-maps' ) as $ya_id ) {
	cmp_assert( ! isset( $ya_get( $ya_old, $ya_id )['except'] ), "except: $ya_id is not touched" );
}
// R4: the cookies go with the loader: to the custom service that decides for adsbygoogle.js, else to the library one.
$ya_ck = function ( array $svcs ) {
	$c = CMP_Scanner::classify( array(), array( '__gads' ), $svcs, 'https://example.md', 'pro' );
	return array( $c['status'], $c['items'][0][1], $c['items'][0][3] );
};
foreach ( array( 'both hosts' => $ya_ex, 'the usual host' => $ya_one, 'the full URL' => $ya_url ) as $ya_n => $ya_s ) {
	cmp_assert_eq( $ya_ck( $ya_s ), array( 'green', 'Al meu 1', 'custom-1' ), "cookies, $ya_n on nu bloca: the custom service, not red" );
}
cmp_assert_eq( $ya_ck( $ya_cat ), array( 'red', 'Al meu 1', 'custom-1' ), 'cookies, a custom service that waits: red under its name' );
cmp_assert_eq( $ya_ck( $ya_all( array( array( 'exempt' => true, 'hosts' => array( 'pagead2.googlesyndication.com/pagead/managed/' ) ) ) ) ), array( 'red', 'Google AdSense', 'google-adsense' ), 'cookies, another path on nu bloca: still AdSense, red' );
cmp_assert_eq( $ya_ck( $ya_none ), array( 'red', 'Google AdSense', 'google-adsense' ), 'cookies, no custom service: AdSense' );
// The policy table. Custom before the update: it is found, AdSense is not listed twice, and the cookies are not lost.
$ya_rows = function ( array $found, array $svcs ) {
	return array_column( CMP_Legal::table_services( $found, $svcs ), 'cookies', 'id' );
};
$ya_gads = array_column( $ya_get( $ya_none, 'google-adsense' )['cookies'], 'name' );
$ya_tb   = $ya_rows( CMP_Blocker::found_ids( $ya_tag, $ya_one ), $ya_one );
cmp_assert_eq( array_map( function ( $c ) { return array_column( $c, 'name' ); }, $ya_tb ), array( 'custom-1' => $ya_gads ), 'policy: a custom AdSense that declares no cookies lists the ones of AdSense, once' );
$ya_own = $ya_all( array( array( 'exempt' => true, 'hosts' => array( 'pagead2.googlesyndication.com' ), 'cookies' => array( array( 'name' => 'mine', 'duration' => '1', 'purpose' => 'x' ) ) ) ) );
cmp_assert_eq( array_column( $ya_rows( array( 'custom-1' ), $ya_own )['custom-1'], 'name' ), array( 'mine' ), 'policy: cookies the owner declared are left as they are' );
cmp_assert_eq( array_keys( $ya_rows( CMP_Blocker::found_ids( $ya_tag, $ya_s = $ya_all( array( array( 'exempt' => true, 'hosts' => array( 'pagead2.googlesyndication.com/ccm/' ) ) ) ) ), $ya_s ) ), array( 'google-adsense', 'custom-1' ), 'policy: a path entry: both are listed' );
cmp_assert_eq( $ya_rows( array( 'google-adsense', 'custom-1' ), $ya_s )['custom-1'], array(), 'policy: ... and it does not take the cookies' );
// Added later: until the next scan the stored list still has AdSense (listed, with its cookies), after it only the custom one.
cmp_assert_eq( array_keys( $ya_rows( array( 'google-adsense' ), $ya_one ) ), array( 'google-adsense', 'custom-1' ), 'policy, added later: before the rescan both are listed' );
// Re-consent: at the update nothing new is asked; added later, only a service that waits is new.
$ya_map = function ( array $svcs, array $seen ) {
	$m = CMP_Services::svc_by_cat( $svcs, $seen, array( 'necessary' ) );
	return isset( $m['marketing'] ) ? $m['marketing'] : array();
};
$ya_wait = $ya_all( array( array( 'hosts' => array( 'pagead2.googlesyndication.com' ) ) ) );
cmp_assert_eq( $ya_map( $ya_wait, CMP_Services::merge_seen( array( 'custom-1' ), CMP_Blocker::found_ids( $ya_tag, $ya_wait ) ) ), array( 'custom-1' ), 're-consent at the update: the custom service only, nothing new asked' );
cmp_assert_eq( $ya_map( $ya_one, CMP_Services::merge_seen( array( 'custom-1' ), CMP_Blocker::found_ids( $ya_tag, $ya_one ) ) ), array(), 're-consent at the update, nu bloca: nothing' );
$ya_seen = CMP_Services::merge_seen( array(), CMP_Blocker::found_ids( $ya_tag, $ya_none ) );
cmp_assert_eq( $ya_map( $ya_one, $ya_seen ), array( 'google-adsense' ), 're-consent, added later on nu bloca: the map is unchanged, nobody is asked again' );
cmp_assert_eq( $ya_map( $ya_wait, $ya_seen ), array( 'custom-1', 'google-adsense' ), 're-consent, added later and waiting: the custom service is new, as any other' );
$ya_uw = $ya_all( array( array( 'hosts' => array( 'pagead2.googlesyndication.com/pagead/js/adsbygoogle.js' ) ) ) );
cmp_assert( $ya_is( $ya_rw( $ya_esc, $ya_uw ), 'custom-1' ) && $ya_is( $ya_rw( $ya_inl, $ya_uw ), 'custom-1' ) && $ya_is( $ya_rw( $ya_tag, $ya_uw ), 'custom-1' ), 'except: the full URL of a service that waits: blocked under it, also in an inline loader with escaped slashes' );
// S1: the token of an excepted URL ends where the URL ends: another URL of the host in the same body stays blocked.
$ya_c0 = 'https://pagead2.googlesyndication.com/ccm/a';
foreach ( array( true, false ) as $ya_e ) {
	$ya_s = $ya_all( array( array( 'exempt' => $ya_e, 'hosts' => array( 'pagead2.googlesyndication.com/ccm/' ) ) ) );
	$ya_bodies = array( 'template strings' => 'var u=[`' . $ya_c0 . '`,`' . $ya_js . '`];', 'a comma list' => 'load("' . $ya_c0 . ',' . $ya_js . '");', 'a query' => 'load("' . $ya_c0 . '?u=' . $ya_js . '");', 'escaped, a query' => str_replace( '/', '\/', 'load("' . $ya_c0 . '?u=' . $ya_js . '");' ) );
	foreach ( array( '`', ',', ';', '|', '?', '#', '&', '[', ']', '{', '}', '=', ':', '+', '@', '*', '!', '$', '^', '//', '' ) as $ya_sep ) {
		$ya_bodies[ "separator '$ya_sep'" ] = 'load("' . $ya_c0 . $ya_sep . $ya_js . '");';
	}
	foreach ( $ya_bodies as $ya_n => $ya_b ) {
		$ya_h = '<script>' . $ya_b . '</script>';
		cmp_assert( $ya_is( $ya_rw( $ya_h, $ya_s ), 'google-adsense' ), 'S1, ' . ( $ya_e ? 'nu bloca' : 'blocked' ) . ", $ya_n: the loader next to the custom URL stays with the library" );
		cmp_assert_eq( CMP_Blocker::detect_services( str_replace( '\/', '/', $ya_h ), $ya_s )['raw'][0], 'google-adsense', "S1, $ya_n: and the scan sees it" );
	}
	$ya_h = '<script>var u=[`' . $ya_c0 . '?x=1`,`' . $ya_c0 . '/b.js#y`];</script>';
	cmp_assert( $ya_e ? $ya_rw( $ya_h, $ya_s ) === $ya_h : $ya_is( $ya_rw( $ya_h, $ya_s ), 'custom-1' ), 'S1: a body with only its own URLs (query, fragment) is the custom service\'s' );
}
