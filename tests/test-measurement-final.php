<?php
// B2 final fix wave: I1 (Consent Mode stub first in Advanced), I2 (Advanced exempts
// only GA4 and the GTM container), I3 (cookieless services in the cookie table),
// I4 (Advanced with no history), M1 (GTM noscript), M2 (Strict label), M3 (message
// by effective mode).
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once __DIR__ . '/../includes/class-consent.php';
require_once __DIR__ . '/../includes/class-services.php';
require_once __DIR__ . '/../includes/class-sanitizer.php';
require_once __DIR__ . '/../includes/modules/class-blocker.php';
require_once __DIR__ . '/../includes/modules/class-banner.php';
require_once __DIR__ . '/../includes/modules/class-lang.php';
require_once __DIR__ . '/../includes/modules/class-legal.php';
require_once __DIR__ . '/../includes/admin/class-legal-page.php';
require_once __DIR__ . '/../includes/admin/class-settings-page.php';

$mf_root = dirname( __DIR__ );
$mf_src  = function ( $rel ) use ( $mf_root ) {
	return (string) file_get_contents( $mf_root . '/' . $rel );
};

// ---------------------------------------------------------------- I1
cmp_assert( method_exists( 'CMP_Banner', 'consent_default_script' ), 'I1: CMP_Banner::consent_default_script exists' );
cmp_assert( method_exists( 'CMP_Blocker', 'inject_after_head' ), 'I1: CMP_Blocker::inject_after_head exists' );
cmp_assert( method_exists( 'CMP_Blocker', 'place_consent_default' ), 'I1: CMP_Blocker::place_consent_default exists' );
if ( method_exists( 'CMP_Banner', 'consent_default_script' ) && method_exists( 'CMP_Blocker', 'inject_after_head' ) && method_exists( 'CMP_Blocker', 'place_consent_default' ) ) {
	// Strict output is byte-identical to the pre-fix echo.
	$mf_sp = CMP_Banner::consent_default_payload( array( 'analytics_storage' => 'denied' ), false );
	$mf_old = "<script data-cmp-keep>\n"
		. "window.dataLayer = window.dataLayer || [];\n"
		. "function gtag(){dataLayer.push(arguments);}\n"
		. 'gtag("consent","default",' . json_encode( $mf_sp ) . ");\n"
		. "</script>\n";
	cmp_assert_eq( CMP_Banner::consent_default_script( $mf_sp, false ), $mf_old, 'I1: Strict stub markup unchanged' );
	$mf_ap   = CMP_Banner::consent_default_payload( array(), true );
	$mf_stub = CMP_Banner::consent_default_script( $mf_ap, true );
	cmp_assert( 1 === preg_match( '/^<script data-cmp-keep data-cmp-default>/', $mf_stub ), 'I1: Advanced stub carries the data-cmp-default marker' );
	cmp_assert( false !== strpos( $mf_stub, 'gtag("consent","default",' . json_encode( $mf_ap ) . ')' ), 'I1: Advanced stub has the Advanced payload' );

	// inject_after_head: pure insertion.
	cmp_assert_eq( CMP_Blocker::inject_after_head( '<html><head lang="ro"><title>x</title></head>', 'S' ), '<html><head lang="ro">S<title>x</title></head>', 'I1: inject_after_head inserts right after <head …>' );
	cmp_assert_eq( CMP_Blocker::inject_after_head( '<HTML><HEAD><title>x</title>', 'S' ), '<HTML><HEAD>S<title>x</title>', 'I1: inject_after_head is case-insensitive' );
	cmp_assert_eq( CMP_Blocker::inject_after_head( '<body><header>h</header></body>', 'S' ), '<body><header>h</header></body>', 'I1: no <head> (only <header>) -> unchanged' );
	cmp_assert_eq( CMP_Blocker::inject_after_head( '<p>fragment</p>', 'S' ), '<p>fragment</p>', 'I1: no <head> -> unchanged' );
	cmp_assert_eq( CMP_Blocker::inject_after_head( '<head><head>', 'S' ), '<head>S<head>', 'I1: only the first <head> gets it' );

	// Scenario: theme prints gtag right after <head>, before wp_head (stub at priority 1).
	$mf_gtag = '<script async src="https://www.googletagmanager.com/gtag/js?id=G-X1"></script>'
		. "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','G-X1');</script>";
	$mf_page = "<!doctype html><html><head><meta charset=\"utf-8\">" . $mf_gtag . '<title>t</title>' . $mf_stub . '<link rel="stylesheet" href="a.css"></head><body><p>x</p></body></html>';
	$mf_adv  = CMP_Services::apply_measurement( CMP_Services::library(), true );
	$mf_out  = CMP_Blocker::place_consent_default( CMP_Blocker::rewrite( $mf_page, array(), $mf_adv ), $mf_stub );
	cmp_assert_eq( substr_count( $mf_out, 'gtag("consent","default"' ), 1, 'I1: Advanced page has the stub exactly once' );
	cmp_assert_eq( substr_count( $mf_out, 'data-cmp-default' ), 1, 'I1: one marked stub only' );
	$mf_first = strpos( $mf_out, '<script' );
	cmp_assert( false !== $mf_first && $mf_first === strpos( $mf_out, '<script data-cmp-keep data-cmp-default>' ), 'I1: the stub is the first script of the page' );
	cmp_assert( strpos( $mf_out, '<head>' ) + 6 === strpos( $mf_out, '<script data-cmp-keep data-cmp-default>' ), 'I1: the stub sits right after <head>' );
	cmp_assert( strpos( $mf_out, 'gtag("consent","default"' ) < strpos( $mf_out, "gtag('config','G-X1')" ), 'I1: default precedes the theme config call' );
	cmp_assert( false !== strpos( $mf_out, 'src="https://www.googletagmanager.com/gtag/js?id=G-X1"' ), 'I1: GA4 loader still live in Advanced' );
	// No <head>: unchanged, so the wp_head print stays as the fallback.
	$mf_nohead = '<html>' . $mf_stub . '<body></body></html>';
	cmp_assert_eq( CMP_Blocker::place_consent_default( $mf_nohead, $mf_stub ), $mf_nohead, 'I1: without <head> the wp_head stub is kept as is' );
	// Already right after <head> (no tag before wp_head): still exactly once.
	$mf_ok = CMP_Blocker::place_consent_default( '<head>' . $mf_stub . '</head>', $mf_stub );
	cmp_assert_eq( $mf_ok, '<head>' . $mf_stub . '</head>', 'I1: a stub already first stays once' );
	// Wiring: apply() places the stub in Advanced only; print_consent_default uses the shared builder.
	$mf_bl = $mf_src( 'includes/modules/class-blocker.php' );
	preg_match( '/function apply\(.*?\n\t}\n/s', $mf_bl, $mf_apply );
	cmp_assert( isset( $mf_apply[0] ) && false !== strpos( $mf_apply[0], 'CMP_Services::measurement_active( $s )' ) && false !== strpos( $mf_apply[0], 'self::place_consent_default(' ) && false !== strpos( $mf_apply[0], 'CMP_Banner::consent_default_payload(' ), 'I1: apply() places the Advanced stub through the shared payload' );
	$mf_bn = $mf_src( 'includes/modules/class-banner.php' );
	preg_match( '/function print_consent_default\(.*?\n\t}\n/s', $mf_bn, $mf_pcd );
	cmp_assert( isset( $mf_pcd[0] ) && false !== strpos( $mf_pcd[0], 'self::consent_default_script(' ), 'I1: print_consent_default uses consent_default_script' );
}

// ---------------------------------------------------------------- I2 + M1
cmp_assert( method_exists( 'CMP_Blocker', 'measurement_tag_exempt' ), 'I2: CMP_Blocker::measurement_tag_exempt exists' );
$mf_adv = CMP_Services::apply_measurement( CMP_Services::library(), true );
$mf_str = CMP_Services::apply_measurement( CMP_Services::library(), false );
$mf_rw  = function ( $html, $svcs ) {
	return CMP_Blocker::rewrite( $html, array(), $svcs );
};
$mf_cases = array(
	'G- loader'          => array( '<script async src="https://www.googletagmanager.com/gtag/js?id=G-ABC123"></script>', 'live' ),
	'G- loader &amp;'    => array( '<script async src="https://www.googletagmanager.com/gtag/js?id=G-ABC123&amp;l=dataLayer"></script>', 'live' ),
	'G- inline'          => array( "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','G-ABC123');</script>", 'live' ),
	'G- inline two G'    => array( "<script>gtag('config', \"G-A1\");gtag('config','G-B2',{send_page_view:false});</script>", 'live' ),
	'AW- loader'         => array( '<script async src="https://www.googletagmanager.com/gtag/js?id=AW-999"></script>', 'google-analytics' ),
	'DC- loader'         => array( '<script async src="https://www.googletagmanager.com/gtag/js?id=DC-999"></script>', 'google-analytics' ),
	'loader no id'       => array( '<script async src="https://www.googletagmanager.com/gtag/js"></script>', 'google-analytics' ),
	'AW- inline'         => array( "<script>gtag('js',new Date());gtag('config','AW-999');</script>", 'google-analytics' ),
	'mixed inline'       => array( "<script>gtag('js',new Date());gtag('config','G-ABC123');gtag('config','AW-999');</script>", 'google-analytics' ),
	'config by variable' => array( "<script>gtag('config',window.myId);gtag('config','G-ABC123');</script>", 'google-analytics' ),
	'analytics.js'       => array( '<script async src="https://www.google-analytics.com/analytics.js"></script>', 'google-analytics' ),
	'ga create inline'   => array( "<script>ga('create','UA-1-1','auto');ga('send','pageview');</script>", 'google-analytics' ),
	'GTM loader'         => array( '<script async src="https://www.googletagmanager.com/gtm.js?id=GTM-ABC12"></script>', 'live' ),
	'GTM inline'         => array( "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','GTM-ABC12');</script>", 'live' ),
	'GTM inline + AW'    => array( "<script>window.dataLayer=window.dataLayer||[];var id='GTM-ABC12';gtag('config','AW-999');</script>", 'google-analytics' ),
);
if ( method_exists( 'CMP_Blocker', 'measurement_tag_exempt' ) ) {
	foreach ( $mf_cases as $mf_name => $mf_c ) {
		$mf_o = $mf_rw( $mf_c[0], $mf_adv );
		if ( 'live' === $mf_c[1] ) {
			cmp_assert_eq( $mf_o, $mf_c[0], "I2 Advanced: $mf_name stays live" );
		} else {
			cmp_assert( false !== strpos( $mf_o, 'data-cmp-block="statistics"' ) && false !== strpos( $mf_o, 'data-cmp-service="' . $mf_c[1] . '"' ), "I2 Advanced: $mf_name stays blocked" );
		}
		// Strict: every one of them is blocked, as before.
		cmp_assert( false !== strpos( $mf_rw( $mf_c[0], $mf_str ), 'data-cmp-block=' ), "I2 Strict: $mf_name blocked" );
	}
	// The admin's own "nu bloca" keeps the whole service live (as in Strict), even AW-.
	$mf_own = array();
	foreach ( CMP_Services::library() as $mf_s ) {
		if ( 'google-analytics' === $mf_s['id'] ) {
			$mf_s['exempt'] = true;
		}
		$mf_own[] = $mf_s;
	}
	$mf_own = CMP_Services::apply_measurement( $mf_own, true );
	cmp_assert_eq( $mf_rw( $mf_cases['analytics.js'][0], $mf_own ), $mf_cases['analytics.js'][0], 'I2: admin-exempt GA stays fully live in Advanced (analytics.js too)' );
	cmp_assert_eq( $mf_rw( $mf_cases['G- loader'][0], $mf_own ), $mf_cases['G- loader'][0], 'I2: admin-exempt GA: the G- loader (matched by the GTM host rule) stays live' );
	// Meta still blocked in Advanced.
	cmp_assert( false !== strpos( $mf_rw( "<script>fbq('init','1');</script>", $mf_adv ), 'data-cmp-service="meta-pixel"' ), 'I2: Meta Pixel still blocked in Advanced' );
}
// Mode-exempt marking unchanged (re-consent and the table read it).
$mf_by = array();
foreach ( $mf_adv as $mf_s ) {
	$mf_by[ $mf_s['id'] ] = $mf_s;
}
cmp_assert( true === $mf_by['google-analytics']['mode_exempt'] && true === $mf_by['google-analytics']['exempt'] && false === $mf_by['google-analytics']['own_exempt'], 'I2: apply_measurement still marks GA mode_exempt' );
cmp_assert( false === CMP_Services::consent_exempt( $mf_by['google-analytics'] ), 'I2: consent_exempt still false for mode-exempt GA' );
cmp_assert_eq( CMP_Services::svc_by_cat( $mf_adv, array( 'google-analytics' ) ), CMP_Services::svc_by_cat( $mf_str, array( 'google-analytics' ) ), 'I2: re-consent map identical in Strict and Advanced' );
// Admin scan: a live GA4 tag in Advanced is not reported as "tracking without consent".
cmp_assert_eq( CMP_Blocker::detect_trackers( $mf_cases['G- loader'][0], $mf_adv )['raw'], array(), 'I2: detect_trackers in Advanced ignores mode-exempt GA (unchanged)' );

// M1: the GTM <noscript> iframe is stripped in Advanced too; hints for GA4 stay.
$mf_ns = '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-ABC12" height="0" width="0"></iframe></noscript>';
cmp_assert_eq( $mf_rw( $mf_ns, $mf_adv ), '', 'M1: GTM noscript stripped in Advanced' );
cmp_assert_eq( $mf_rw( $mf_ns, $mf_str ), '', 'M1: GTM noscript stripped in Strict (unchanged)' );
$mf_hint = '<link rel="preconnect" href="https://www.googletagmanager.com">';
cmp_assert_eq( $mf_rw( $mf_hint, $mf_adv ), $mf_hint, 'M1: GA/GTM preconnect hint kept in Advanced (unchanged)' );
cmp_assert_eq( $mf_rw( $mf_hint, $mf_str ), '', 'M1: GA/GTM preconnect hint stripped in Strict (unchanged)' );

// ---------------------------------------------------------------- I3
$mf_cats = array(
	array( 'slug' => 'statistics', 'label' => 'Statistici', 'cookies' => array() ),
	array( 'slug' => 'marketing', 'label' => 'Marketing', 'cookies' => array() ),
);
$mf_fathom = array( 'id' => 'fathom', 'name' => 'Fathom Analytics', 'provider' => 'Conva Ventures Inc.', 'category' => 'statistics', 'cookies' => array(), 'transfer' => 'US' );
$mf_mk     = array( 'id' => 'nock', 'name' => 'NoCk', 'provider' => 'X <b>', 'category' => 'marketing', 'cookies' => array(), 'transfer' => '' );
$mf_ids    = function ( $list ) {
	return array_map( function ( $s ) {
		return $s['id'];
	}, $list );
};
cmp_assert_eq( $mf_ids( CMP_Legal::table_services( array( 'fathom', 'zzz' ), array( $mf_fathom ) ) ), array( 'fathom' ), 'I3: a found cookieless service is listed' );
cmp_assert_eq( $mf_ids( CMP_Legal::table_services( array(), array( $mf_fathom ) ) ), array(), 'I3: a cookieless library service not found is not listed' );
cmp_assert_eq( $mf_ids( CMP_Legal::table_services( array(), array( array_merge( $mf_mk, array( 'source' => 'custom' ) ) ) ) ), array( 'nock' ), 'I3: a cookieless custom service is listed' );
$mf_pur = array(
	'ro' => array( 'fără cookie-uri (statistici anonime)', 'fără cookie-uri', 'Statistici', 'Marketing' ),
	'ru' => array( 'без файлов cookie (анонимная статистика)', 'без файлов cookie', 'Статистика', 'Маркетинг' ),
	'en' => array( 'no cookies (anonymous statistics)', 'no cookies', 'Statistics', 'Marketing' ),
);
foreach ( $mf_pur as $mf_l => $mf_p ) {
	$mf_h = CMP_Legal::cookie_table_html( $mf_cats, array( $mf_fathom, $mf_mk ), $mf_l );
	cmp_assert( false !== strpos( $mf_h, '<tr><td>Fathom Analytics</td><td>Conva Ventures Inc.</td><td>—</td><td>' . $mf_p[0] . '</td><td>' . $mf_p[2] . '</td><td>US</td></tr>' ), "I3 $mf_l: statistics cookieless row (name, provider, —, purpose, category, transfer)" );
	cmp_assert( false !== strpos( $mf_h, '<tr><td>NoCk</td><td>X &lt;b&gt;</td><td>—</td><td>' . $mf_p[1] . '</td><td>' . $mf_p[3] . '</td><td>—</td></tr>' ), "I3 $mf_l: generic cookieless row for other categories, escaped" );
	cmp_assert_eq( substr_count( $mf_h, '<tr><td>Fathom Analytics</td>' ), 1, "I3 $mf_l: one row per cookieless service" );
}
// A library-provided wording wins (GTM loads other tags; "anonymous statistics" would be wrong).
$mf_gtm = null;
foreach ( CMP_Services::library() as $mf_s ) {
	if ( 'google-tag-manager' === $mf_s['id'] ) {
		$mf_gtm = $mf_s;
	}
}
cmp_assert( is_array( $mf_gtm ) && ! empty( $mf_gtm['nocookie_purpose']['ro'] ) && ! empty( $mf_gtm['nocookie_purpose']['ru'] ) && ! empty( $mf_gtm['nocookie_purpose']['en'] ), 'I3: GTM has its own cookieless purpose in RO/RU/EN' );
if ( is_array( $mf_gtm ) && ! empty( $mf_gtm['nocookie_purpose']['en'] ) ) {
	$mf_hg = CMP_Legal::cookie_table_html( $mf_cats, array( $mf_gtm ), 'en' );
	cmp_assert( false !== strpos( $mf_hg, '<td>' . htmlspecialchars( $mf_gtm['nocookie_purpose']['en'], ENT_QUOTES, 'UTF-8' ) . '</td>' ) && false === strpos( $mf_hg, 'anonymous statistics' ), 'I3: GTM row uses its own wording' );
}
// Services with cookies keep their per-cookie rows (no extra service row).
$mf_ga = array( 'id' => 'ga', 'name' => 'Google Analytics', 'provider' => 'G', 'category' => 'statistics', 'transfer' => 'US',
	'cookies' => array( array( 'name' => '_ga', 'duration' => array( 'ro' => '2 ani' ), 'purpose' => array( 'ro' => 'p' ) ) ) );
cmp_assert( false === strpos( CMP_Legal::cookie_table_html( $mf_cats, array( $mf_ga ), 'ro' ), '<td>Google Analytics</td>' ), 'I3: a service with cookies gets no service-name row' );
// Light lists only exempt / necessary, cookieless ones included.
$mf_lt = CMP_Legal::table_for_mode( $mf_cats, array( array_merge( $mf_fathom, array( 'exempt' => true ) ), $mf_mk ), 'light' );
cmp_assert_eq( $mf_ids( $mf_lt['services'] ), array( 'fathom' ), 'I3: Light lists the exempt cookieless service' );
// Lawyer pack item.
// Internal review document: present only in the internal repo (skipped in the public one).
if ( is_readable( $mf_root . '/docs/product/2026-11-18-texte-juridice-pentru-revizie.md' ) ) {
	$mf_lp = $mf_src( 'docs/product/2026-11-18-texte-juridice-pentru-revizie.md' );
	cmp_assert( 1 === preg_match( '/D19\b.*fără cookie/su', $mf_lp ), 'I3: lawyer pack has D19 on cookieless services in the table' );
} else {
	echo "SKIP: internal lawyer pack not present\n";
}

// ---------------------------------------------------------------- I4
$mf_a = array( 'mode' => 'pro', 'block_scripts' => true, 'measurement' => 'advanced' );
$mf_s = array( 'mode' => 'pro', 'block_scripts' => true, 'measurement' => 'strict' );
foreach ( array( 'false' => false, 'null' => null, 'string' => 'x' ) as $mf_k => $mf_h ) {
	cmp_assert_eq( CMP_Legal_Page::measurement_vars( $mf_a, $mf_h ), array( 'advanced' => '1' ), "I4: Advanced + history $mf_k -> advanced var on" );
	cmp_assert_eq( CMP_Legal_Page::page_mode( $mf_a, $mf_h ), 'advanced', "I4: Advanced + history $mf_k -> page_mode advanced" );
	cmp_assert_eq( CMP_Legal_Page::page_mode( $mf_s, $mf_h ), 'strict', "I4: Strict + history $mf_k -> strict" );
	cmp_assert_eq( CMP_Legal_Page::measurement_vars( $mf_s, $mf_h ), array( 'advanced' => '' ), "I4: Strict + history $mf_k -> advanced var off" );
}
cmp_assert_eq( CMP_Legal_Page::page_mode( $mf_a, array() ), 'strict', 'I4: explicit empty history still means no GA/GTM' );
cmp_assert_eq( CMP_Legal_Page::measurement_vars( $mf_a, array() ), array( 'advanced' => '' ), 'I4: explicit empty history -> advanced var off' );
$mf_lps = $mf_src( 'includes/admin/class-legal-page.php' );
cmp_assert( false !== strpos( $mf_lps, "get_option( 'cmp_services_history', false )" ) && false === strpos( $mf_lps, "get_option( 'cmp_services_history', array() )" ), 'I4: the legal screen reads the history with a false default' );

// ---------------------------------------------------------------- M2
$mf_sp_src = $mf_src( 'includes/admin/class-settings-page.php' );
cmp_assert( false === strpos( $mf_sp_src, 'nimic nu se încarcă înainte de acord' ), 'M2: Strict label no longer says nothing loads before consent' );
cmp_assert( false !== strpos( $mf_sp_src, 'serviciile blocate nu se încarcă înainte de acord' ) && false !== strpos( $mf_sp_src, 'fără cookie-uri' ), 'M2: Strict label names blocked services and the cookieless exceptions' );

// ---------------------------------------------------------------- M3
cmp_assert( method_exists( 'CMP_Settings_Page', 'measurement_change' ), 'M3: CMP_Settings_Page::measurement_change exists' );
if ( method_exists( 'CMP_Settings_Page', 'measurement_change' ) ) {
	$mf_ga_h = array( 'google-analytics' );
	$mf_mc   = function ( $o, $n, $h ) {
		return CMP_Settings_Page::measurement_change( $o, $n, $h );
	};
	cmp_assert_eq( $mf_mc( $mf_s, $mf_a, $mf_ga_h ), array( 'to' => 'advanced', 'regen' => true ), 'M3: Strict -> Advanced with GA seen: message + regenerate' );
	cmp_assert_eq( $mf_mc( $mf_a, $mf_s, $mf_ga_h ), array( 'to' => 'strict', 'regen' => true ), 'M3: Advanced -> Strict with GA seen: message + regenerate' );
	cmp_assert_eq( $mf_mc( $mf_s, $mf_a, array( 'youtube' ) ), array( 'to' => 'advanced', 'regen' => false ), 'M3 (a): no GA/GTM seen: message without regenerate' );
	cmp_assert_eq( $mf_mc( $mf_s, $mf_s, $mf_ga_h ), null, 'M3: no change -> no message' );
	// (b) stored Advanced, effective mode changes through blocking / mode.
	$mf_a_off = array_merge( $mf_a, array( 'block_scripts' => false ) );
	cmp_assert_eq( $mf_mc( $mf_a_off, $mf_a, $mf_ga_h ), array( 'to' => 'advanced', 'regen' => true ), 'M3 (b): turning blocking back on re-activates Advanced: message' );
	cmp_assert_eq( $mf_mc( $mf_a, $mf_a_off, $mf_ga_h ), array( 'to' => 'strict', 'regen' => true ), 'M3 (b): turning blocking off ends Advanced: message' );
	$mf_a_light = array_merge( $mf_a, array( 'mode' => 'light' ) );
	cmp_assert_eq( $mf_mc( $mf_a_light, $mf_a, $mf_ga_h ), array( 'to' => 'advanced', 'regen' => true ), 'M3 (b): Light -> Pro with stored Advanced: message' );
	cmp_assert_eq( $mf_mc( $mf_a_off, array_merge( $mf_s, array( 'block_scripts' => false ) ), $mf_ga_h ), null, 'M3: stored value changes but effective mode does not -> no message' );
	// I4 interplay: unknown history counts as GA present.
	cmp_assert_eq( $mf_mc( $mf_s, $mf_a, false ), array( 'to' => 'advanced', 'regen' => true ), 'M3: unknown history -> regenerate' );
	preg_match( '/function save\(.*?\n\t}\n/s', $mf_sp_src, $mf_save );
	cmp_assert( isset( $mf_save[0] ) && false !== strpos( $mf_save[0], 'self::measurement_change( $old, $clean, CMP_Legal_Page::services_history() )' ), 'M3: save() builds the message from the effective mode' );
}

// ---------------------------------------------------------------- N1
// A GA4 / GTM tag that also carries another tracker is not mode-exempt: it is
// blocked under that other service (as c3a197d did).
$n1_adv = CMP_Services::apply_measurement( CMP_Services::library(), true );
$n1_rw  = function ( $html ) use ( $n1_adv ) {
	return CMP_Blocker::rewrite( $html, array(), $n1_adv );
};
$n1_ga_fbq = "<script>gtag('js',new Date());gtag('config','G-X1');fbq('init','1');fbq('track','PageView');</script>";
$n1_o      = $n1_rw( $n1_ga_fbq );
cmp_assert( false !== strpos( $n1_o, 'data-cmp-service="meta-pixel"' ) && false !== strpos( $n1_o, 'data-cmp-block="marketing"' ), 'N1: GA4 config + fbq inline in Advanced -> blocked as meta-pixel (marketing)' );
$n1_gtm     = "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','GTM-ABC12');";
$n1_clarity = '(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window, document, "clarity", "script", "abc123");';
$n1_o2 = $n1_rw( '<script>' . $n1_gtm . $n1_clarity . '</script>' );
cmp_assert( false !== strpos( $n1_o2, 'data-cmp-service="clarity"' ), 'N1: GTM container + Clarity loader inline in Advanced -> blocked as clarity' );
$n1_o3 = $n1_rw( '<script>' . $n1_gtm . "fbq('init','1');</script>" );
cmp_assert( false !== strpos( $n1_o3, 'data-cmp-service="meta-pixel"' ), 'N1: GTM container + fbq inline in Advanced -> blocked as meta-pixel' );
$n1_pure_ga  = "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','G-X1');</script>";
$n1_pure_gtm = '<script>' . $n1_gtm . '</script>';
cmp_assert_eq( $n1_rw( $n1_pure_ga ), $n1_pure_ga, 'N1: pure GA4 inline still live in Advanced' );
cmp_assert_eq( $n1_rw( $n1_pure_gtm ), $n1_pure_gtm, 'N1: pure GTM inline still live in Advanced' );
// Strict: GA4 + fbq stays blocked (first match, as before).
cmp_assert( false !== strpos( CMP_Blocker::rewrite( $n1_ga_fbq, array(), CMP_Services::apply_measurement( CMP_Services::library(), false ) ), 'data-cmp-block=' ), 'N1: Strict still blocks the combined tag' );
