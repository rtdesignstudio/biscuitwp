<?php
/**
 * Lot A, Task 2: the PixelYourSite bridge (CMP_Bridge_PYS).
 *  - tag(): only the pys src tag is held back, the gate goes before it;
 *  - gate_js(): run with node on a fake page;
 *  - the server filters, in their own process (tests/fixtures/bridge-pys-request.php).
 * Sources named here are PixelYourSite 11.4.7 (wordpress.org), read and never run.
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once __DIR__ . '/../includes/class-services.php';
require_once __DIR__ . '/../includes/class-known-plugins.php';
require_once __DIR__ . '/../includes/modules/class-blocker.php';
require_once __DIR__ . '/../includes/modules/class-bridge-pys.php';
require_once __DIR__ . '/../includes/class-consent.php';
require_once __DIR__ . '/../includes/modules/class-banner.php';

$bp_lib   = CMP_Services::library();
$bp_slugs = array( 'necessary', 'statistics', 'marketing' );
$bp_svc   = function ( array $overrides = array(), $advanced = false ) use ( $bp_lib, $bp_slugs ) {
	return CMP_Services::apply_measurement( CMP_Services::all( $bp_lib, array( 'overrides' => $overrides ), $bp_slugs ), $advanced );
};

// ---- category() ----
foreach ( array( 'ga' => 'statistics', 'gtm' => 'statistics', 'facebook' => 'marketing', 'google_ads' => 'marketing', 'pinterest' => 'marketing', 'bing' => 'marketing', 'reddit' => 'marketing', 'openai' => 'marketing', 'tiktok' => 'marketing', 'new-pixel' => 'marketing', '' => 'marketing' ) as $bp_p => $bp_c ) {
	cmp_assert_eq( CMP_Bridge_PYS::category( (string) $bp_p ), $bp_c, "category: '{$bp_p}' waits for {$bp_c}" );
}

// ---- exempt(): the admin's own "nu bloca" only ----
cmp_assert_eq( CMP_Bridge_PYS::exempt( $bp_svc() ), array(), 'exempt: nothing by default' );
cmp_assert_eq( CMP_Bridge_PYS::exempt( $bp_svc( array( 'meta-pixel' => array( 'exempt' => true ) ) ) ), array( 'facebook' ), 'exempt: meta-pixel gives facebook' );
cmp_assert_eq( CMP_Bridge_PYS::exempt( $bp_svc( array( 'google-analytics' => array( 'exempt' => true ), 'google-tag-manager' => array( 'exempt' => true ) ) ) ), array( 'ga', 'gtm' ), 'exempt: google-analytics gives ga, google-tag-manager gives gtm' );
cmp_assert_eq( CMP_Bridge_PYS::exempt( $bp_svc( array(), true ) ), array(), 'exempt: the Advanced mode exempts nothing here (mode_exempt)' );
cmp_assert_eq( CMP_Bridge_PYS::exempt( $bp_svc( array( 'google-analytics' => array( 'exempt' => true ) ), true ) ), array( 'ga' ), 'exempt: in Advanced the admin\'s own value still counts' );
cmp_assert_eq( CMP_Bridge_PYS::exempt( $bp_svc( array( 'clarity' => array( 'exempt' => true ) ) ) ), array(), 'exempt: other services do not matter' );
cmp_assert_eq( CMP_Bridge_PYS::exempt( $bp_svc( array( 'meta-pixel' => array( 'category' => 'statistics' ) ) ) ), array(), 'exempt: another optional category is not an exemption' );
cmp_assert_eq( CMP_Bridge_PYS::exempt( $bp_svc( array( 'meta-pixel' => array( 'category' => 'necessary' ) ) ) ), array( 'facebook' ), 'exempt: "necessary" counts like one, for that pixel' );
cmp_assert_eq( CMP_Bridge_PYS::exempt( $bp_svc( array( 'google-analytics' => array( 'category' => 'statistics' ) ) ), array( 'necessary', 'statistics' ) ), array( 'ga', 'gtm' ), 'exempt: a required category counts like one' );

// ---- categories(): what each pixel waits for, from the admin's services list ----
$bp_def = array( 'hold' => 'marketing', 'facebook' => 'marketing', 'ga' => 'statistics', 'gtm' => 'statistics' );
cmp_assert_eq( CMP_Bridge_PYS::categories( $bp_svc() ), $bp_def, 'categories: the library defaults' );
cmp_assert_eq( CMP_Bridge_PYS::categories( array() ), $bp_def, 'categories: category() when the services are missing' );
cmp_assert_eq( CMP_Bridge_PYS::categories( array( null, 'x', array( 'id' => 'meta-pixel' ), array( 'id' => 'google-analytics', 'category' => '' ) ) ), $bp_def, 'categories: garbage falls back to category()' );
$bp_cs   = array( 'necessary', 'statistics', 'marketing', 'ads' );
$bp_ads  = CMP_Services::all( $bp_lib, array( 'overrides' => array( 'meta-pixel' => array( 'category' => 'ads' ) ) ), $bp_cs );
$bp_cads = array( 'hold' => 'ads', 'facebook' => 'ads', 'ga' => 'statistics', 'gtm' => 'statistics' );
cmp_assert_eq( CMP_Bridge_PYS::categories( $bp_ads ), $bp_cads, 'categories: Meta moved to ads: the script waits for ads' );
cmp_assert_eq( CMP_Bridge_PYS::categories( CMP_Services::all( $bp_lib, array( 'overrides' => array( 'google-analytics' => array( 'category' => 'marketing' ) ) ), $bp_cs ) ), array_merge( $bp_def, array( 'ga' => 'marketing' ) ), 'categories: GA moved to marketing' );
cmp_assert_eq( CMP_Bridge_PYS::categories( $bp_svc( array( 'meta-pixel' => array( 'category' => 'necessary' ) ) ) )['hold'], 'marketing', 'categories: Meta necessary exempts Meta alone, the other pixels keep waiting for marketing' );
cmp_assert_eq( CMP_Bridge_PYS::categories( $bp_ads, array( 'necessary', 'ads' ) )['hold'], 'marketing', 'categories: the same for a required category' );
cmp_assert_eq( CMP_Bridge_PYS::categories( CMP_Services::all( $bp_lib, array( 'overrides' => array( 'meta-pixel' => array( 'category' => 'ads' ) ) ), array( 'necessary', 'statistics', 'ads' ) ) )['hold'], 'ads', 'categories: the "marketing" slug deleted, Meta in ads: ads' );
cmp_assert_eq( CMP_Bridge_PYS::categories( CMP_Services::apply_measurement( $bp_ads, true ) ), $bp_cads, 'categories: Advanced changes nothing' );
cmp_assert_eq( CMP_Bridge_PYS::exempt( array( 'x', null, array() ) ), array(), 'exempt: garbage is ignored' );

// ---- tag() ----
$bp_src = "<script src=\"https://example.test/wp-content/plugins/pixelyoursite/dist/scripts/public.min.js?ver=11.4.7\" id=\"pys-js\"></script>\n";
$bp_out = CMP_Bridge_PYS::tag( $bp_src, 'pys', array() );
cmp_assert_eq( substr_count( $bp_out, '<script data-cmp-category="marketing" src="' ), 1, 'tag: the src tag gets data-cmp-category="marketing"' );
cmp_assert_eq( substr_count( $bp_out, 'data-cmp-category="marketing"' ), 2, 'tag: once on the src tag, once on the gate' );
cmp_assert( 1 === preg_match( '/^<script id="cmp-pys-gate" data-cmp-category="marketing">.+<\/script>\s*<script data-cmp-category="marketing" src=/s', $bp_out ), 'tag: the gate is printed right before it, inline, same category' );
cmp_assert( "\n" === substr( $bp_out, -1 ) && false !== strpos( $bp_out, 'id="pys-js"' ), 'tag: the rest of the tag is kept' );
cmp_assert_eq( CMP_Bridge_PYS::tag( $bp_out, 'pys', array() ), $bp_out, 'tag: a second pass changes nothing' );
foreach ( array( 'jquery', 'js-cookie-pys', 'jquery-bind-first', 'js-sha256', 'pys-rest', 'pys-bing', 'pys_js', 'PYS', '' ) as $bp_h ) {
	cmp_assert_eq( CMP_Bridge_PYS::tag( $bp_src, $bp_h, array() ), $bp_src, "tag: handle '{$bp_h}' is unchanged" );
}
// Inline parts of the same handle (wp_add_inline_script before / after, translations).
$bp_before = "<script id=\"pys-js-before\">\nvar a = '<script src=\"x.js\">'; window.b = 1;\n</script>\n";
$bp_after  = "<script id=\"pys-js-after\">\nwindow.c = \"data-cmp-category\";\n</script>\n";
$bp_out    = CMP_Bridge_PYS::tag( $bp_before . $bp_src . $bp_after, 'pys', array() );
cmp_assert( 0 === strpos( $bp_out, $bp_before . '<script id="cmp-pys-gate"' ), 'tag: the before inline part is byte for byte the same, the gate after it' );
cmp_assert( $bp_after === substr( $bp_out, -strlen( $bp_after ) ), 'tag: the after inline part is byte for byte the same' );
cmp_assert_eq( substr_count( $bp_out, ' data-cmp-category="marketing"' ), 2, 'tag: the inline parts get no category' );
// Forms of the tag.
foreach ( array(
	'public.js, single quotes' => "<script type='text/javascript' src='https://e.test/p/pixelyoursite/dist/scripts/public.js' id='pys-js'></script>",
	'defer (PixelYourSite adds it)' => '<script defer src="https://e.test/public.min.js" id="pys-js"></script>',
	'async first' => '<script async data-wp-strategy="async" src="https://e.test/public.min.js" id="pys-js"></script>',
	'module' => '<script type="module" src="https://e.test/public.min.js" id="pys-js"></script>',
	'no id' => '<script src="https://e.test/public.min.js"></script>',
	'upper case' => '<SCRIPT SRC="https://e.test/public.min.js" ID="pys-js"></SCRIPT>',
	'new lines' => "<script\n\tsrc=\"https://e.test/public.min.js\"\n\tid=\"pys-js\"></script>",
) as $bp_l => $bp_t ) {
	$bp_out = CMP_Bridge_PYS::tag( $bp_t, 'pys', array() );
	$bp_rw  = CMP_Blocker::rewrite( $bp_out, array(), $bp_svc() );
	cmp_assert( 1 === preg_match_all( '/data-cmp-category="marketing"[^>]*\ssrc=/i', $bp_out ), "tag ({$bp_l}): the src tag has the category once" );
	cmp_assert( 2 === substr_count( $bp_rw, 'type="text/plain"' ) && 2 === substr_count( $bp_rw, 'data-cmp-block="marketing"' ) && 0 === preg_match( '/\ssrc\s*=/i', $bp_rw ), "tag ({$bp_l}): after rewrite both wait for marketing, no live src" );
	cmp_assert( false !== strpos( $bp_rw, 'data-cmp-body=' ) && strpos( $bp_rw, 'data-cmp-body=' ) < strpos( $bp_rw, 'data-cmp-src=' ), "tag ({$bp_l}): the gate comes first" );
	cmp_assert( 1 === preg_match( '/data-cmp-body="([^"]+)"/', $bp_rw, $bp_m ) && base64_decode( $bp_m[1] ) === CMP_Bridge_PYS::gate_js( array() ), "tag ({$bp_l}): the gate is released as it was written" );
}
// A category already on the tag.
$bp_out = CMP_Bridge_PYS::tag( '<script data-cmp-category="preferences" src="/public.min.js" id="pys-js"></script>', 'pys', array() );
cmp_assert_eq( substr_count( $bp_out, 'data-cmp-category="preferences"' ), 2, 'tag: a category already on the tag is kept and the gate follows it' );
cmp_assert( false === strpos( $bp_out, 'data-cmp-category="marketing"' ), 'tag: no second category is added' );
$bp_out = CMP_Bridge_PYS::tag( "<script src='/public.min.js' data-cmp-category=''></script>", 'pys', array() );
cmp_assert( 2 === substr_count( $bp_out, 'data-cmp-category="marketing"' ) && 2 === substr_count( $bp_out, 'data-cmp-category' ), 'tag: a blank category is replaced, not left to the library rules' );
$bp_out = CMP_Bridge_PYS::tag( '<script data-cmp-category="a&quot;><b>" src="/public.min.js"></script>', 'pys', array() );
cmp_assert( false === strpos( $bp_out, 'cmp-pys-gate" data-cmp-category="a"' ), 'tag: a hostile category never breaks out of the gate tag' );
$bp_out = CMP_Bridge_PYS::tag( '<script nonce="abc123" src="/public.min.js" id="pys-js"></script>', 'pys', array() );
cmp_assert( false !== strpos( $bp_out, '<script id="cmp-pys-gate" data-cmp-category="marketing" nonce="abc123">' ), 'tag: the gate carries the CSP nonce of the src tag' );
// Nothing to hold.
cmp_assert_eq( CMP_Bridge_PYS::tag( '', 'pys', array() ), '', 'tag: an empty tag stays empty' );
cmp_assert_eq( CMP_Bridge_PYS::tag( '<!-- removed -->', 'pys', array() ), '<!-- removed -->', 'tag: without any script nothing changes' );
// No src tag recognised: fail closed (M2).
foreach ( array(
	'src renamed to data-src' => '<script data-src="/public.min.js" id="pys-js"></script>',
	'only an inline part'     => '<script>import("/public.min.js");</script>',
	'two parts, no src'       => '<script>var a;</script><script type="rocketlazyloadscript" data-rocket-src="/public.min.js"></script>',
	'unclosed'                => '<script src="/public.min.js" id="pys-js">',
) as $bp_l => $bp_t ) {
	$bp_out = CMP_Bridge_PYS::tag( $bp_t, 'pys', array() );
	cmp_assert( substr_count( $bp_out, '<script' ) === substr_count( $bp_out, 'data-cmp-category="marketing"' ) && 0 === strpos( $bp_out, '<script id="cmp-pys-gate"' ), "tag ($bp_l): every script of the handle waits, the gate first" );
	$bp_out = CMP_Bridge_PYS::tag( $bp_t, 'pys', array( 'facebook' ) );
	cmp_assert( 0 === strpos( $bp_out, '<script id="cmp-pys-gate" data-cmp-keep>' ) && $bp_t === substr( $bp_out, -strlen( $bp_t ) ), "tag ($bp_l), exempt: live behind the live gate" );
}
$bp_big = '<script id="pys-js-before">var big="' . str_repeat( 'a\'<', 600000 ) . '";</script>';
$bp_out = CMP_Bridge_PYS::tag( $bp_big . $bp_src, 'pys', array() );
cmp_assert( 0 === strpos( $bp_out, $bp_big . '<script id="cmp-pys-gate" data-cmp-category="marketing">' ) && 1 === substr_count( $bp_out, '<script data-cmp-category="marketing" src=' ), 'tag: a before part of 1.8 MB does not hide the src tag' );
// Custom categories.
$bp_out = CMP_Bridge_PYS::tag( $bp_src, 'pys', array(), $bp_cads );
cmp_assert( 2 === substr_count( $bp_out, 'data-cmp-category="ads"' ) && false === strpos( $bp_out, '="marketing"' ), 'tag: both wait for the category of Meta Pixel (ads)' );
$bp_rw = CMP_Blocker::rewrite( $bp_out, array(), $bp_ads );
cmp_assert( 2 === substr_count( $bp_rw, 'data-cmp-block="ads"' ), 'tag: and the blocker holds them for ads' );
// Exempt: the src stays live, the gate runs in place.
$bp_out = CMP_Bridge_PYS::tag( $bp_before . $bp_src, 'pys', array( 'facebook' ) );
cmp_assert( false !== strpos( $bp_out, '<script id="cmp-pys-gate" data-cmp-keep>' ) && false === strpos( $bp_out, 'data-cmp-category' ), 'tag, exempt: the gate carries data-cmp-keep, no category anywhere' );
cmp_assert( $bp_src === substr( $bp_out, -strlen( $bp_src ) ), 'tag, exempt: the src tag is byte for byte the same' );
cmp_assert_eq( CMP_Blocker::rewrite( $bp_out, array(), $bp_svc( array( 'meta-pixel' => array( 'exempt' => true ) ) ) ), $bp_out, 'tag, exempt: the blocker leaves both live' );
$bp_out = CMP_Bridge_PYS::tag( '<script data-cmp-keep src="/public.min.js"></script>', 'pys', array() );
cmp_assert( false !== strpos( $bp_out, '<script id="cmp-pys-gate" data-cmp-keep>' ) && false === strpos( $bp_out, 'data-cmp-category' ), 'tag: with data-cmp-keep on the src tag the gate is live too' );
// Advanced changes nothing here.
$bp_rw = CMP_Blocker::rewrite( CMP_Bridge_PYS::tag( $bp_src, 'pys', CMP_Bridge_PYS::exempt( $bp_svc( array(), true ) ) ), array(), $bp_svc( array(), true ) );
cmp_assert_eq( substr_count( $bp_rw, 'data-cmp-block="marketing"' ), 2, 'Advanced: PixelYourSite still waits for marketing' );
// The gate says nothing the scan would take for a tracker.
cmp_assert_eq( CMP_Blocker::detect_services( CMP_Bridge_PYS::tag( $bp_src, 'pys', array( 'facebook', 'ga', 'gtm' ) ), $bp_svc() ), array( 'raw' => array(), 'blocked' => array() ), 'the live gate is not read as a tracker by the scan' );
cmp_assert( 0 === preg_match( '/[^\x20-\x7E\n]|<|>\s*<\/|<\/script/', CMP_Bridge_PYS::gate_js( array( 'facebook' ) ) ), 'gate: plain ASCII, no "<" (safe inline and through atob)' );
cmp_assert_eq( substr_count( CMP_Bridge_PYS::tag( $bp_src, 'pys', array(), array( 'hold' => 'a"><b>' ) ), '"><b>' ), 0, 'tag: a category is escaped' );
// The whole fixture page.
$bp_fx  = (string) file_get_contents( __DIR__ . '/fixtures/known-plugins/pixelyoursite.html' );
$bp_rw  = CMP_Blocker::rewrite( preg_replace_callback( '/<script src="[^"]*pixelyoursite[^"]*"[^>]*><\/script>/', function ( $m ) {
	return CMP_Bridge_PYS::tag( $m[0], 'pys', array() );
}, $bp_fx ), array(), $bp_svc() );
cmp_assert( 0 === preg_match( '/<script\b[^>]*\ssrc="[^"]*pixelyoursite/', $bp_rw ) && false !== strpos( $bp_rw, 'var pysOptions = {' ), 'fixture: public.min.js is not live, pysOptions is untouched' );

// One request in its own process.
$bp_req = function ( array $in ) {
	$raw = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/bridge-pys-request.php' ) . ' ' . escapeshellarg( json_encode( $in ) ) . ' 2>&1' );
	$r   = json_decode( $raw, true );
	if ( ! is_array( $r ) ) {
		echo "bridge-pys-request output: {$raw}\n";
		$r = array();
	}
	return array_merge( array_fill_keys( array( 'hooks', 'check', 'bridged', 'removed', 'printed', 'notices' ), array( 'none' ) ), array_fill_keys( array( 'sent', 'stub', 'html', 'scan', 'raw', 'found', 'disabled', 'disabled1', 'check_no', 'restricted', 'restr_no', 'formdata', 'formdata1', 'session', 'session1', 'tag', 'other' ), 'none' ), $r );
};

// ---- gate_js(), with node ----
$bp_node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
if ( '' === $bp_node ) {
	echo "  (skip: node not found, the PixelYourSite gate is not run)\n";
} else {
	$bp_run = function ( array $exempt, array $in, array $cats = array() ) {
		$in  = array_merge( array( 'gate' => CMP_Bridge_PYS::gate_js( $exempt, $cats ), 'cmp' => null ), $in );
		$raw = (string) shell_exec( 'node ' . escapeshellarg( __DIR__ . '/fixtures/bridge-pys-gate.js' ) . ' ' . escapeshellarg( json_encode( $in ) ) . ' 2>&1' );
		$r   = json_decode( $raw, true );
		return is_array( $r ) ? $r : array( 'error' => 'no JSON: ' . substr( $raw, 0, 300 ), 'states' => array() );
	};
	$bp_mk   = array( 'facebook', 'google_ads', 'pinterest', 'bing', 'reddit', 'openai' );
	$bp_opts = function ( $flag = false, array $more = array() ) use ( $bp_mk ) {
		$g = array( 'ajax_enabled' => true, 'all_disabled_by_api' => false, 'analytics_disabled_by_api' => $flag );
		foreach ( $bp_mk as $p ) {
			$g[ $p . '_disabled_by_api' ] = $flag;
		}
		foreach ( array( 'analytics_storage', 'ad_storage', 'ad_user_data', 'ad_personalization' ) as $s ) {
			$g[ $s ] = array( 'enabled' => true, 'value' => 'granted', 'filter' => false );
		}
		return array_merge( array( 'gdpr' => $g, 'cookie' => array( 'disabled_all_cookie' => false ), 'facebook' => array( 'pixelIds' => array( '1' ) ), 'ga' => array( 'trackingIds' => array( 'G-1' ) ) ), $more );
	};
	$bp_flags = function ( $state ) use ( $bp_mk ) {
		$o = array();
		foreach ( array_merge( $bp_mk, array( 'analytics' ) ) as $p ) {
			$o[ $p ] = isset( $state['gdpr'][ $p . '_disabled_by_api' ] ) ? $state['gdpr'][ $p . '_disabled_by_api' ] : null;
		}
		return $o;
	};
	$bp_all = function ( $marketing_off, $analytics_off ) use ( $bp_mk ) {
		return array_merge( array_fill_keys( $bp_mk, $marketing_off ), array( 'analytics' => $analytics_off ) );
	};
	$bp_case = function ( $label, array $exempt, array $in, array $want ) use ( $bp_run, $bp_flags ) {
		$r = $bp_run( $exempt, $in );
		cmp_assert_eq( $r['error'], '', "gate, {$label}: no error" );
		$s = isset( $r['states'][0] ) ? $r['states'][0] : array();
		cmp_assert_eq( $bp_flags( $s ), $want, "gate, {$label}: flags" );
		cmp_assert( isset( $s['gdpr']['ajax_enabled'] ) && false === $s['gdpr']['ajax_enabled'], "gate, {$label}: ajax_enabled is false (its answer would overwrite the flags)" );
		return $r;
	};
	$bp_case( 'all accepted', array(), array( 'options' => $bp_opts(), 'cmp' => array( 'statistics', 'marketing' ) ), $bp_all( false, false ) );
	$bp_case( 'marketing only (Review Focus 2)', array(), array( 'options' => $bp_opts(), 'cmp' => array( 'marketing' ) ), $bp_all( false, true ) );
	$bp_case( 'statistics only', array(), array( 'options' => $bp_opts(), 'cmp' => array( 'statistics' ) ), $bp_all( true, true ) );
	$bp_case( 'nothing accepted, cached page with false flags (Review Focus 1)', array(), array( 'options' => $bp_opts( false ), 'cmp' => array() ), $bp_all( true, true ) );
	$bp_case( 'no CMP', array(), array( 'options' => $bp_opts() ), $bp_all( true, true ) );
	$bp_case( 'CMP.isAllowed throws', array(), array( 'options' => $bp_opts(), 'throwing' => true ), $bp_all( true, true ) );
	$bp_case( 'Light with an old Pro cookie', array(), array( 'options' => $bp_opts(), 'cmp' => array( 'statistics', 'marketing' ), 'data' => array( 'mode' => 'light' ) ), $bp_all( true, true ) );
	$bp_case( 'Pro (CMP_DATA.mode)', array(), array( 'options' => $bp_opts(), 'cmp' => array( 'statistics', 'marketing' ), 'data' => array( 'mode' => 'pro' ) ), $bp_all( false, false ) );
	$bp_case( 'a site "no" stays (facebook disabled by its own filter)', array(), array( 'options' => array_replace_recursive( $bp_opts(), array( 'gdpr' => array( 'facebook_disabled_by_api' => true ) ) ), 'cmp' => array( 'statistics', 'marketing' ) ), array_merge( $bp_all( false, false ), array( 'facebook' => true ) ) );
	$bp_case( 'a site "no" stays (analytics too)', array( 'ga' ), array( 'options' => array_replace_recursive( $bp_opts(), array( 'gdpr' => array( 'analytics_disabled_by_api' => true ) ) ), 'cmp' => array( 'statistics', 'marketing' ) ), $bp_all( false, true ) );
	$bp_case( 'no gdpr block', array(), array( 'options' => array( 'facebook' => array() ), 'cmp' => array() ), $bp_all( true, true ) );
	// Exempt.
	$bp_case( 'Meta exempt, no CMP', array( 'facebook' ), array( 'options' => $bp_opts() ), array_merge( $bp_all( true, true ), array( 'facebook' => false ) ) );
	$bp_case( 'Meta exempt, Light', array( 'facebook' ), array( 'options' => $bp_opts(), 'cmp' => array( 'statistics', 'marketing' ), 'data' => array( 'mode' => 'light' ) ), array_merge( $bp_all( true, true ), array( 'facebook' => false ) ) );
	$bp_case( 'GA exempt', array( 'ga' ), array( 'options' => $bp_opts(), 'cmp' => array() ), $bp_all( true, false ) );
	$bp_case( 'GTM exempt, only GTM set up', array( 'gtm' ), array( 'options' => array_diff_key( $bp_opts( false, array( 'gtm' => array( 'trackingIds' => array( 'GTM-1' ) ) ) ), array( 'ga' => 1 ) ), 'cmp' => array() ), $bp_all( true, false ) );
	$bp_case( 'GA exempt, GTM not: one shared flag, so both wait', array( 'ga' ), array( 'options' => $bp_opts( false, array( 'gtm' => array( 'trackingIds' => array( 'GTM-1' ) ) ) ), 'cmp' => array() ), $bp_all( true, true ) );
	$bp_case( 'GTM exempt, GA not: both wait', array( 'gtm' ), array( 'options' => $bp_opts( false, array( 'gtm' => array( 'trackingIds' => array( 'GTM-1' ) ) ) ), 'cmp' => array() ), $bp_all( true, true ) );
	$bp_case( 'GA exempt, GTM present but empty', array( 'ga' ), array( 'options' => $bp_opts( false, array( 'gtm' => array( 'trackingIds' => array() ) ) ), 'cmp' => array() ), $bp_all( true, false ) );
	$bp_case( 'GA exempt, GTM data layer mode', array( 'ga' ), array( 'options' => $bp_opts( false, array( 'gtm' => array( 'trackingIds' => array(), 'gtm_just_data_layer' => true ) ) ), 'cmp' => array() ), $bp_all( true, true ) );
	$bp_case( 'GA and GTM exempt', array( 'ga', 'gtm' ), array( 'options' => $bp_opts( false, array( 'gtm' => array( 'trackingIds' => array( 'GTM-1' ) ) ) ), 'cmp' => array() ), $bp_all( true, false ) );
	// Meta exempt: GA still needs the script's category and its own.
	$bp_case( 'Meta exempt, statistics only', array( 'facebook' ), array( 'options' => $bp_opts(), 'cmp' => array( 'statistics' ) ), array_merge( $bp_all( true, true ), array( 'facebook' => false ) ) );
	$bp_case( 'Meta exempt, all accepted', array( 'facebook' ), array( 'options' => $bp_opts(), 'cmp' => array( 'statistics', 'marketing' ) ), $bp_all( false, false ) );
	// A signal no category lists (N4): the category of GA, or of the script.
	$bp_nol = array( array( 'slug' => 'necessary', 'required' => true ), array( 'slug' => 'statistics' ), array( 'slug' => 'ads', 'gtag_signals' => null ) );
	$bp_sg  = function ( array $accepted, array $categories, array $cats ) use ( $bp_run, $bp_opts ) {
		$g = $bp_run( array(), array( 'options' => $bp_opts(), 'cmp' => $accepted, 'categories' => $categories ), $cats )['states'][0]['gdpr'];
		return array( $g['analytics_storage']['value'], $g['ad_storage']['value'], $g['ad_user_data']['value'], $g['ad_personalization']['value'] );
	};
	cmp_assert_eq( $bp_sg( array( 'statistics', 'ads' ), $bp_nol, $bp_cads ), array( 'granted', 'granted', 'granted', 'granted' ), 'gate, signals no category lists: full consent is granted' );
	cmp_assert_eq( $bp_sg( array( 'ads' ), $bp_nol, $bp_cads ), array( 'denied', 'granted', 'granted', 'granted' ), 'gate, signals no category lists: ads alone grants the ad signals' );
	cmp_assert_eq( $bp_sg( array( 'statistics' ), $bp_nol, $bp_cads ), array( 'granted', 'denied', 'denied', 'denied' ), 'gate, signals no category lists: statistics alone grants analytics' );
	cmp_assert_eq( $bp_sg( array(), $bp_nol, $bp_cads ), array( 'denied', 'denied', 'denied', 'denied' ), 'gate, signals no category lists: nothing without consent' );
	// One that lists it decides alone.
	$bp_lis = array( array( 'slug' => 'necessary', 'required' => true ), array( 'slug' => 'statistics' ), array( 'slug' => 'marketing', 'gtag_signals' => array( 'ad_storage' ) ), array( 'slug' => 'ads' ) );
	cmp_assert_eq( $bp_sg( array( 'ads' ), $bp_lis, $bp_cads ), array( 'denied', 'denied', 'granted', 'granted' ), 'gate: a signal some category lists follows that category only' );
	// Missing pysOptions.
	$bp_r = $bp_run( array(), array( 'cmp' => array() ) );
	cmp_assert( '' === $bp_r['error'] && array( null ) === $bp_r['states'], 'gate, no pysOptions: no error, nothing is made up' );
	$bp_r = $bp_run( array(), array( 'options' => 'x', 'cmp' => array() ) );
	cmp_assert_eq( $bp_r['error'], '', 'gate, pysOptions is not an object: no error' );
	// pysOptions after the gate (I1): held all the same.
	$bp_late = function ( array $exempt, array $cmp, array $want, $label ) use ( $bp_run, $bp_opts, $bp_flags ) {
		$r = $bp_run( $exempt, array( 'cmp' => $cmp, 'steps' => array( array( 'late', $bp_opts() ), array( 'late', $bp_opts() ) ) ) );
		cmp_assert( '' === $r['error'] && isset( $r['states'][2] ) && null === $r['states'][0], "gate before pysOptions, {$label}: no error" );
		foreach ( array( 1 => 'first', 2 => 'second' ) as $i => $w ) {
			$st = isset( $r['states'][ $i ] ) ? $r['states'][ $i ] : array();
			cmp_assert_eq( $bp_flags( $st ), $want, "gate before pysOptions, {$label}: flags of the {$w} one" );
			cmp_assert( isset( $st['gdpr']['ajax_enabled'] ) && false === $st['gdpr']['ajax_enabled'] && array( 'G-1' ) === $st['ga']['trackingIds'], "gate before pysOptions, {$label}: the {$w} value is there" );
		}
	};
	$bp_late( array(), array( 'marketing' ), $bp_all( false, true ), 'marketing only' );
	$bp_late( array( 'facebook' ), array(), array_merge( $bp_all( true, true ), array( 'facebook' => false ) ), 'Meta exempt, no consent' );
	$bp_late( array(), array( 'statistics', 'marketing' ), $bp_all( false, false ), 'all accepted' );
	// Objects that cannot be redefined: held on a copy.
	foreach ( array( 'gdpr', 'all' ) as $bp_fz ) {
		$bp_r = $bp_run( array( 'facebook' ), array( 'options' => $bp_opts(), 'cmp' => array(), 'freeze' => $bp_fz ) );
		cmp_assert( '' === $bp_r['error'] && $bp_flags( $bp_r['states'][0] ) === array_merge( $bp_all( true, true ), array( 'facebook' => false ) ), "gate, frozen ({$bp_fz}): no flag stays raw" );
		cmp_assert( isset( $bp_r['states'][0]['ga'] ) && false === $bp_r['states'][0]['gdpr']['ajax_enabled'] && 'denied' === $bp_r['states'][0]['gdpr']['ad_storage']['value'], "gate, frozen ({$bp_fz}): nor the rest" );
	}
	// The gate twice (M3): the flags still follow the visitor.
	$bp_r = $bp_run( array( 'facebook' ), array( 'options' => $bp_opts(), 'cmp' => array(), 'steps' => array( array( 'again' ), array( 'allow', array( 'statistics', 'marketing' ) ) ) ) );
	cmp_assert( '' === $bp_r['error'] && $bp_flags( $bp_r['states'][2] ) === $bp_all( false, false ) && 'granted' === $bp_r['states'][2]['gdpr']['ad_storage']['value'] && false === $bp_r['states'][2]['cookie']['disabled_all_cookie'], 'gate run twice: a later accept still counts' );
	$bp_r = $bp_run( array( 'facebook' ), array( 'cmp' => array(), 'steps' => array( array( 'again' ), array( 'late', $bp_opts() ), array( 'again' ), array( 'allow', array( 'statistics', 'marketing' ) ) ) ) );
	cmp_assert( '' === $bp_r['error'] && $bp_flags( $bp_r['states'][2] ) === array_merge( $bp_all( true, true ), array( 'facebook' => false ) ) && $bp_flags( $bp_r['states'][4] ) === $bp_all( false, false ), 'gate run twice before pysOptions: held, then follows the visitor' );
	// Custom categories (I3).
	$bp_cat4 = array( array( 'slug' => 'necessary', 'required' => true ), array( 'slug' => 'statistics', 'gtag_signals' => array( 'analytics_storage' ) ), array( 'slug' => 'marketing', 'gtag_signals' => array( 'ad_storage' ) ), array( 'slug' => 'ads', 'gtag_signals' => array( 'ad_user_data', 'ad_personalization' ) ) );
	$bp_cc   = function ( array $cats, array $accepted, array $want, $label ) use ( $bp_run, $bp_opts, $bp_flags, $bp_cat4 ) {
		$r = $bp_run( array(), array( 'options' => $bp_opts(), 'cmp' => $accepted, 'categories' => $bp_cat4 ), $cats );
		cmp_assert_eq( '' === $r['error'] ? $bp_flags( $r['states'][0] ) : $r['error'], $want, "gate, {$label}" );
		return $r;
	};
	$bp_cc( $bp_cads, array( 'marketing', 'statistics' ), $bp_all( true, true ), 'Meta in ads: marketing without ads gives nothing' );
	$bp_cc( $bp_cads, array( 'ads' ), $bp_all( false, true ), 'Meta in ads: ads gives Meta and the pixels without a service, not Google' );
	$bp_cc( $bp_cads, array( 'ads', 'statistics' ), $bp_all( false, false ), 'Meta in ads: ads and statistics give both' );
	$bp_gam = array_merge( $bp_def, array( 'ga' => 'marketing' ) );
	$bp_cc( $bp_gam, array( 'marketing' ), $bp_all( false, false ), 'GA moved to marketing: marketing is enough' );
	$bp_cc( $bp_gam, array( 'statistics' ), $bp_all( true, true ), 'GA moved to marketing: statistics is not' );
	$bp_r = $bp_run( array( 'ga' ), array( 'options' => $bp_opts(), 'cmp' => array( 'ads' ), 'categories' => $bp_cat4 ), $bp_cads );
	$bp_g = $bp_r['states'][0]['gdpr'];
	cmp_assert_eq( array( $bp_g['analytics_storage']['value'], $bp_g['ad_storage']['value'], $bp_g['ad_user_data']['value'] ), array( 'denied', 'denied', 'granted' ), 'gate: Consent Mode values follow the signals each category grants on this site' );
	// fetch_user_data_via_rest: public.js copies the REST answer over the flags (Object.assign,
	// public.js:5713) and replaces options.cookie; it must not switch anything back on.
	$bp_rest = array_fill_keys( array_map( function ( $p ) {
		return $p . '_disabled_by_api';
	}, array_merge( $bp_mk, array( 'analytics', 'all' ) ) ), false );
	$bp_no   = $bp_opts();
	foreach ( array_keys( $bp_rest ) as $bp_k ) {
		unset( $bp_no['gdpr'][ $bp_k ] ); // not printed in that mode
	}
	unset( $bp_no['cookie'] );
	$bp_r = $bp_run( array(), array( 'options' => $bp_no, 'cmp' => array( 'marketing' ), 'steps' => array( array( 'gdpr', $bp_rest ), array( 'cookie', array( 'disabled_all_cookie' => false, 'disabled_first_visit_cookie' => true ) ), array( 'allow', array() ) ) ) );
	cmp_assert_eq( $bp_r['error'], '', 'gate, REST options: public.js can write them without an error' );
	cmp_assert_eq( $bp_flags( $bp_r['states'][1] ), $bp_all( false, true ), 'gate, REST options: the answer cannot switch analytics on' );
	cmp_assert_eq( $bp_flags( $bp_r['states'][3] ), $bp_all( true, true ), 'gate, REST options: after withdrawal everything is off' );
	cmp_assert( ! array_key_exists( 'cookie', $bp_r['states'][0] ) && array( 'disabled_all_cookie' => false, 'disabled_first_visit_cookie' => true ) === $bp_r['states'][2]['cookie'], 'gate, REST options: no cookie block is made up (public.js tests hasOwnProperty), the one that comes is taken as it is' );
	$bp_r = $bp_run( array(), array( 'options' => $bp_opts(), 'cmp' => array( 'statistics', 'marketing' ), 'steps' => array( array( 'allow', array() ) ) ) );
	cmp_assert( true === $bp_r['states'][1]['cookie']['disabled_all_cookie'] && $bp_flags( $bp_r['states'][1] ) === $bp_all( true, true ), 'gate: after withdrawal the flags and the cookie block turn off at once' );
	// The live gate (exempt) runs before banner.js: the flags follow once CMP exists.
	$bp_r = $bp_run( array( 'facebook' ), array( 'options' => $bp_opts(), 'cmp' => array( 'statistics', 'marketing' ), 'lateCmp' => true, 'steps' => array( array( 'allow', array( 'statistics', 'marketing' ) ) ) ) );
	cmp_assert_eq( $bp_flags( $bp_r['states'][0] ), array_merge( $bp_all( true, true ), array( 'facebook' => false ) ), 'gate, live, before banner.js: only Meta' );
	cmp_assert_eq( $bp_flags( $bp_r['states'][1] ), $bp_all( false, false ), 'gate, live: a returning visitor who accepted gets the rest when PixelYourSite reads the flags' );
	// PixelYourSite's own cookies, and the Consent Mode values it sends to Google.
	$bp_r = $bp_run( array( 'ga' ), array( 'options' => $bp_opts(), 'cmp' => array() ) );
	cmp_assert( true === $bp_r['states'][0]['cookie']['disabled_all_cookie'], 'gate, GA exempt, no consent: PixelYourSite writes none of its own cookies' );
	$bp_vals = function ( $s ) {
		return array( $s['gdpr']['analytics_storage']['value'], $s['gdpr']['ad_storage']['value'], $s['gdpr']['ad_user_data']['value'], $s['gdpr']['ad_personalization']['value'] );
	};
	cmp_assert_eq( $bp_vals( $bp_r['states'][0] ), array( 'denied', 'denied', 'denied', 'denied' ), 'gate, GA exempt, no consent: Consent Mode values are denied' );
	$bp_r = $bp_run( array( 'ga' ), array( 'options' => $bp_opts(), 'cmp' => array( 'statistics' ) ) );
	cmp_assert_eq( $bp_vals( $bp_r['states'][0] ), array( 'granted', 'denied', 'denied', 'denied' ), 'gate, statistics only: ad values stay denied' );
	$bp_r = $bp_run( array(), array( 'options' => $bp_opts(), 'cmp' => array( 'statistics', 'marketing' ) ) );
	cmp_assert( array( 'granted', 'granted', 'granted', 'granted' ) === $bp_vals( $bp_r['states'][0] ) && false === $bp_r['states'][0]['cookie']['disabled_all_cookie'], 'gate, all accepted: PixelYourSite\'s own values' );
	$bp_r = $bp_run( array( 'facebook' ), array( 'options' => $bp_opts(), 'cmp' => array() ) );
	cmp_assert( false === $bp_r['states'][0]['cookie']['disabled_all_cookie'], 'gate, Meta exempt: its cookies are left as the site set them' );

	// The page as it is printed, through the blocker in its own process (the bridge in effect),
	// then run with the banner's own unblockScripts(): pysOptions, the gate, public.js (C1).
	$bp_page = function ( array $opts, array $in = array() ) use ( $bp_req ) {
		return $bp_req( array_merge( array( 'pys' => true, 'events' => true, 'settings' => array( 'mode' => 'pro', 'block_scripts' => true, 'policy_version' => '3' ), 'html' => "<script id=\"pys-js-extra\">\nvar pysOptions = " . json_encode( $opts ) . ";\n</script>\n%PYS%" ), $in ) );
	};
	$bp_release = function ( $html, array $steps, $mode = 'pro' ) {
		$raw = (string) shell_exec( 'printf %s ' . escapeshellarg( $html ) . ' | node ' . escapeshellarg( __DIR__ . '/fixtures/bridge-pys-release.js' ) . ' ' . escapeshellarg( json_encode( array( 'steps' => $steps, 'mode' => $mode ) ) ) . ' 2>&1' );
		$r   = json_decode( $raw, true );
		return is_array( $r ) ? $r : array( 'log' => array( substr( $raw, 0, 300 ) ), 'runs' => array( 'error' ) );
	};
	$bp_load = function ( $facebook, $google, $other ) {
		return array( 'facebook' => $facebook, 'google' => $google, 'other' => $other );
	};
	// PixelYourSite's own defaults for a container (modules/google_gtm/gtm.php getPixelOptions).
	$bp_gtm  = array( 'gtm' => array( 'trackingIds' => array( 'GTM-ABC123' ), 'gtm_dataLayer_name' => 'dataLayer', 'gtm_container_domain' => 'https://www.googletagmanager.com', 'gtm_just_data_layer' => false ) );
	$bp_mex  = array( 'services' => array( 'overrides' => array( 'meta-pixel' => array( 'exempt' => true ) ) ) );
	$bp_held = array( 'inline:cmp-pys-gate', 'src' );
	foreach ( array( 'without GTM' => array(), 'GTM set up' => $bp_gtm ) as $bp_l => $bp_more ) {
		$bp_h = $bp_page( $bp_opts( false, $bp_more ) )['html'];
		cmp_assert_eq( $bp_release( $bp_h, array( array( 'marketing' ) ) ), array( 'log' => $bp_held, 'runs' => array( $bp_load( true, false, true ) ) ), "release, {$bp_l}, marketing only: the gate first, Meta on, Google off (Review Focus 2)" );
		cmp_assert_eq( $bp_release( $bp_h, array( array( 'marketing' ), array( 'marketing', 'statistics' ) ) ), array( 'log' => $bp_held, 'runs' => array( $bp_load( true, false, true ) ) ), "release, {$bp_l}, marketing then statistics: Meta ran, no error (Google on the next page)" );
		cmp_assert_eq( $bp_release( $bp_h, array( array( 'statistics' ), array( 'marketing', 'statistics' ) ) ), array( 'log' => $bp_held, 'runs' => array( $bp_load( true, true, true ) ) ), "release, {$bp_l}, statistics then marketing: both" );
		cmp_assert_eq( $bp_release( $bp_h, array( array( 'statistics' ) ) ), array( 'log' => array(), 'runs' => array() ), "release, {$bp_l}, statistics only: nothing" );
		cmp_assert_eq( $bp_release( $bp_h, array( array() ) ), array( 'log' => array(), 'runs' => array() ), "release, {$bp_l}, refused: nothing" );
		$bp_h = $bp_page( $bp_opts( false, $bp_more ), $bp_mex )['html'];
		cmp_assert_eq( $bp_release( $bp_h, array( array() ) ), array( 'log' => array( 'src' ), 'runs' => array( $bp_load( true, false, false ) ) ), "release, {$bp_l}, Meta exempt: runs at once, Meta alone" );
		$bp_h = $bp_page( $bp_opts( false, $bp_more ), array( 'settings' => array( 'mode' => 'light', 'policy_version' => '3' ) ) )['html'];
		cmp_assert_eq( $bp_release( $bp_h, array( array( 'marketing', 'statistics' ) ), 'light' ), array( 'log' => array(), 'runs' => array() ), "release, {$bp_l}, Light: nothing runs" );
		$bp_h = $bp_page( $bp_opts( false, $bp_more ), array_merge( $bp_mex, array( 'settings' => array( 'mode' => 'light', 'policy_version' => '3' ) ) ) )['html'];
		cmp_assert_eq( $bp_release( $bp_h, array(), 'light' ), array( 'log' => array( 'src' ), 'runs' => array( $bp_load( true, false, false ) ) ), "release, {$bp_l}, Light, Meta exempt: Meta alone" );
	}
	// Only the options, only as data, only with the bridge in effect, only on a gated page.
	$bp_j    = 'var pysOptions = ' . json_encode( $bp_opts( false, $bp_gtm ) ) . ';';
	$bp_ok   = array(
		'plain'              => "<script id=\"pys-js-extra\">\n{$bp_j}\n</script>",
		'old WordPress, CDATA' => "<script type='text/javascript' id='pys-js-extra'>\n/* <![CDATA[ */\n{$bp_j}\n/* ]]> */\n</script>",
		'sourceURL comment'  => "<script id=\"pys-js-extra\">\n{$bp_j}\n//# sourceURL=pys-js-extra\n</script>",
		'id not first'       => "<script data-x=\"1\" type=\"text/javascript\" id=\"pys-js-extra\">{$bp_j}</script>",
	);
	$bp_has  = function ( $html ) { // the options are in the page as they were printed
		return false !== strpos( $html, 'var pysOptions' );
	};
	foreach ( $bp_ok as $bp_l => $bp_t ) {
		$bp_r = $bp_page( array(), array( 'html' => $bp_t . "\n%PYS%" ) );
		cmp_assert( 0 === strpos( $bp_r['html'], $bp_t . "\n<script type=\"text/plain\"" ), "options ({$bp_l}): left alone on a gated page" );
		cmp_assert_eq( $bp_r['raw'], array(), "options ({$bp_l}): not reported as a tracker that loads without consent" );
		cmp_assert( in_array( 'google-tag-manager', $bp_r['found'], true ), "options ({$bp_l}): GTM is still found on the site (policy, services list)" );
		cmp_assert( ! $bp_has( $bp_page( array(), array( 'html' => $bp_t . "\n%PYS%", 'pys' => false ) )['html'] ), "options ({$bp_l}): blocked as before when PixelYourSite is not active" );
		// A second pass over the same HTML.
		$bp_r2 = $bp_page( array(), array( 'html' => $bp_r['html'] ) );
		cmp_assert( $bp_r2['html'] === $bp_r['html'] && array() === $bp_r2['raw'], "options ({$bp_l}): a second pass changes nothing" );
	}
	$bp_code = "(function(w,d,s,l,i){w[l]=w[l]||[];})(window,document,'script','dataLayer','GTM-ABC123');";
	foreach ( array(
		'other code under the id'  => "<script id=\"pys-js-extra\">{$bp_code}</script>",
		'code after the options'   => "<script id=\"pys-js-extra\">{$bp_j}\n{$bp_code}</script>",
		'code on the same line'    => "<script id=\"pys-js-extra\">{$bp_j}{$bp_code}</script>",
		'code before'              => "<script id=\"pys-js-extra\">{$bp_code}\n{$bp_j}</script>",
		'code inside the value'    => "<script id=\"pys-js-extra\">var pysOptions = {a:{$bp_code}};</script>",
		'code in a comment marker' => "<script id=\"pys-js-extra\">{$bp_j}\n//# sourceURL=x\n{$bp_code}</script>",
		'another variable'         => "<script id=\"pys-js-extra\">{$bp_j}\nvar location = \"javascript:alert(1)\";</script>",
		'another variable, object' => "<script id=\"pys-js-extra\">{$bp_j}\nvar pysMore = {\"a\":1};</script>",
		'a string value'           => "<script id=\"pys-js-extra\">var pysOptions = \"GTM-1 dataLayer\";</script>",
		'another id'               => "<script id=\"pys-js-extra-2\">{$bp_j}</script>",
		'id in another attribute'  => "<script data-id=\"pys-js-extra\">{$bp_j}</script>",
		'id inside a value'        => "<script data-x=\"q id='pys-js-extra'\">{$bp_j}</script>",
		'no id'                    => "<script>{$bp_j}</script>",
		'a src'                    => "<script id=\"pys-js-extra\" src=\"https://www.googletagmanager.com/gtm.js?id=GTM-ABC123\">{$bp_j}</script>",
	) as $bp_l => $bp_t ) {
		cmp_assert( false !== strpos( $bp_page( array(), array( 'html' => $bp_t . '%PYS%' ) )['html'], 'data-cmp-block="statistics"' ), "options, {$bp_l}: stays subject to the blocker" );
	}
	$bp_t = "<script id=\"pys-js-extra\" data-cmp-category=\"marketing\">{$bp_j}</script>%PYS%";
	cmp_assert( ! $bp_has( $bp_page( array(), array( 'html' => $bp_t ) )['html'] ), 'options: an explicit data-cmp-category still wins' );
	$bp_r = $bp_page( array(), array( 'html' => $bp_ok['plain'] . "<script>{$bp_code}</script>%PYS%" ) );
	cmp_assert( false !== strpos( $bp_r['html'], 'data-cmp-block="statistics"' ) && $bp_has( $bp_r['html'] ), 'options: a real GTM snippet next to them is still blocked' );

	// A page that is not gated: the old rule (N1). %PYS% = the gate and the src tag as tag() prints them.
	$bp_pair   = CMP_Bridge_PYS::tag( $bp_src, 'pys', array() );
	$bp_gate   = substr( $bp_pair, 0, strpos( $bp_pair, '<script data-cmp-category="marketing" src' ) );
	$bp_tag    = substr( $bp_pair, strlen( $bp_gate ) );
	$bp_bundle = '<script defer src="/wp-content/cache/autoptimize/js/autoptimize_1.js"></script>';
	$bp_lgate  = str_replace( ' data-cmp-category="marketing"', ' data-cmp-keep', $bp_gate );
	$bp_o      = $bp_ok['plain'];
	foreach ( array(
		'no gate, no pys-js'                   => $bp_o,
		'public.js in a bundle'                => $bp_o . $bp_bundle,
		'gate kept, public.js in a bundle'     => $bp_o . $bp_gate . $bp_bundle,
		'live gate, public.js in a bundle'     => $bp_o . $bp_lgate . $bp_bundle,
		'pys-js without its id'                => $bp_o . $bp_gate . str_replace( ' id="pys-js"', '', $bp_tag ),
		'pys-js renamed'                       => $bp_o . $bp_gate . str_replace( 'pys-js', 'pys-js-2', $bp_tag ),
		'pys-js without src'                   => $bp_o . $bp_gate . '<script data-cmp-category="marketing" id="pys-js"></script>',
		'pys-js live (filter bypassed)'        => $bp_o . $bp_gate . $bp_src,
		'pys-js live, no gate'                 => $bp_o . $bp_src,
		'pys-js held, no gate'                 => $bp_o . $bp_tag,
		'pys-js held, another category'        => $bp_o . $bp_gate . str_replace( '"marketing"', '"statistics"', $bp_tag ),
		'pys-js before the gate'               => $bp_o . $bp_tag . $bp_gate,
		'pys-js twice, one live'               => $bp_o . $bp_pair . $bp_src,
		'public.js also live without id'       => $bp_o . $bp_pair . str_replace( ' id="pys-js"', '', $bp_src ),
		'gate id in another attribute'         => $bp_o . str_replace( 'id="cmp-pys-gate"', 'data-x="id=\'cmp-pys-gate\'"', $bp_gate ) . $bp_tag,
		'pys-js id inside a value'             => $bp_o . $bp_gate . str_replace( 'id="pys-js"', 'data-x="q id=\'pys-js\'"', $bp_tag ),
		'second copy in data-src'              => $bp_o . $bp_pair . '<script type="text/javascript" data-src="/wp-content/plugins/pixelyoursite/dist/scripts/public.min.js?ver=11"></script>',
		'second copy in data-litespeed-src'    => $bp_o . $bp_pair . '<script data-litespeed-src="/wp-content/plugins/pixelyoursite/dist/scripts/public.js"></script>',
		'second copy in data-rocket-src'       => $bp_o . $bp_pair . '<script type="rocketlazyloadscript" data-rocket-src="/wp-content/plugins/pixelyoursite/dist/scripts/public.js" defer></script>',
		'second copy, src in single quotes'    => $bp_o . $bp_pair . "<script data-src='/wp-content/plugins/pixelyoursite/dist/scripts/public.js'></script>",
		'a comment with an unclosed script, then a live copy' => $bp_o . $bp_pair . '<!-- <script> -->' . $bp_src,
		'both only in a comment'               => $bp_o . '<!-- ' . $bp_pair . ' -->' . $bp_bundle,
		'both only as text in a script'        => $bp_o . '<script>var t=\'<script id="cmp-pys-gate" data-cmp-keep><script id="pys-js" src="/public.js">\';</script>' . $bp_bundle,
		'gate without category or keep'        => $bp_o . str_replace( ' data-cmp-category="marketing"', '', $bp_gate ) . $bp_tag,
		'fragment: only the options'           => '<div>' . $bp_o . '</div>',
		'fragment: the options and the gate'   => $bp_o . $bp_gate,
	) as $bp_l => $bp_t ) {
		$bp_r = $bp_page( array(), array( 'html' => $bp_t ) );
		cmp_assert( ! $bp_has( $bp_r['html'] ) && false !== strpos( $bp_r['html'], 'data-cmp-block="statistics"' ), "not gated ({$bp_l}): the options are held as GTM, as before the bridge" );
		// The scan of the same page, were the options live in it.
		$bp_r = $bp_page( array(), array( 'scan' => $bp_o . $bp_page( array(), array( 'html' => str_replace( $bp_o, '', $bp_t ) ) )['html'] ) );
		cmp_assert( in_array( 'google-tag-manager', $bp_r['scan'], true ), "not gated ({$bp_l}): the scan reports the live GTM" );
	}
	foreach ( array(
		'as printed'             => $bp_o . $bp_pair,
		'options after the pair' => $bp_pair . $bp_o,
		'other attribute order'  => $bp_o . preg_replace( '/<script id="cmp-pys-gate" data-cmp-category="marketing">/', "<script data-cmp-category='marketing' defer id='cmp-pys-gate'>", $bp_gate ) . "<script id='pys-js' defer src='/pixelyoursite/dist/scripts/public.js' data-cmp-category='marketing'></script>",
		'a whole page'           => '<!doctype html><html><head><!-- x --><script>var a="<!-- b";</script></head><body>' . $bp_o . '<p>--></p>' . $bp_pair . '<!-- y --></body></html>',
		'live gate (exempt)'     => $bp_o . $bp_lgate . $bp_src,
		'owner says necessary'   => $bp_o . str_replace( '"marketing"', '"necessary"', $bp_pair ),
	) as $bp_l => $bp_t ) {
		$bp_r = $bp_page( array(), array( 'html' => $bp_t ) );
		cmp_assert( $bp_has( $bp_r['html'] ) && array() === $bp_r['raw'], "gated ({$bp_l}): the options stay, the scan is quiet" );
	}
	// The scan trusts only what is really blocked: a page the buffer did not rewrite.
	$bp_r = $bp_page( array(), array( 'scan' => $bp_o . $bp_pair ) );
	cmp_assert( in_array( 'google-tag-manager', $bp_r['scan'], true ), 'scan: data-cmp-category not yet turned into a block is a live script' );
	// Reproduced leak: a bundle that runs public.js on the raw options starts nothing before consent.
	$bp_h = $bp_page( array(), array( 'html' => $bp_o . '<script src="/cache/bundle.js"></script>' ) )['html'];
	$bp_r = $bp_release( $bp_h, array() );
	cmp_assert( 1 === count( $bp_r['runs'] ) && isset( $bp_r['runs'][0]['error'] ), 'release, public.js in a bundle, GTM set up: it finds no options and nothing starts' );
	// N5: a page PCRE gives up on is still scanned.
	$bp_r = $bp_page( array(), array( 'pcre' => true, 'scan' => $bp_o . $bp_page( array(), array( 'html' => $bp_pair ) )['html'] . '<script>' . str_repeat( 'x', 30000 ) . '</script><script async src="https://www.googletagmanager.com/gtm.js?id=GTM-ZZZ"></script>' ) );
	cmp_assert( in_array( 'google-tag-manager', $bp_r['scan'], true ), 'scan: on a PCRE error the page is scanned as it is, not as an empty page' );
}

// ---- server_allowed() ----
$bp_yes = function () {
	return true;
};
$bp_mkt = function ( $c ) {
	return 'marketing' === $c;
};
$bp_nil = function () {
	return false;
};
cmp_assert( CMP_Bridge_PYS::server_allowed( 'facebook', array(), $bp_mkt ) && CMP_Bridge_PYS::server_allowed( 'openai', array(), $bp_mkt ), 'server: marketing pixels with marketing' );
cmp_assert( ! CMP_Bridge_PYS::server_allowed( 'ga', array(), $bp_mkt ) && ! CMP_Bridge_PYS::server_allowed( 'gtm', array(), $bp_mkt ), 'server: ga and gtm also need statistics' );
$bp_is = function ( array $yes ) {
	return function ( $c ) use ( $yes ) {
		return in_array( $c, $yes, true );
	};
};
cmp_assert( ! CMP_Bridge_PYS::server_allowed( 'ga', array(), $bp_is( array( 'statistics' ) ) ) && CMP_Bridge_PYS::server_allowed( 'ga', array(), $bp_yes ), 'server: ga needs the category of the script and its own' );
foreach ( array( 'facebook', 'openai', 'pinterest' ) as $bp_p ) {
	cmp_assert( ! CMP_Bridge_PYS::server_allowed( $bp_p, array(), $bp_is( array( 'marketing' ) ), $bp_cads ) && CMP_Bridge_PYS::server_allowed( $bp_p, array(), $bp_is( array( 'ads' ) ), $bp_cads ), "server, Meta in ads: {$bp_p} needs ads, not marketing" );
}
cmp_assert( CMP_Bridge_PYS::server_allowed( 'ga', array(), $bp_is( array( 'marketing' ) ), array_merge( $bp_def, array( 'ga' => 'marketing' ) ) ) && ! CMP_Bridge_PYS::server_allowed( 'gtm', array(), $bp_is( array( 'marketing' ) ), array_merge( $bp_def, array( 'ga' => 'marketing' ) ) ), 'server: GA moved to marketing, GTM not' );
cmp_assert( ! CMP_Bridge_PYS::server_allowed( 'facebook', array(), $bp_nil ), 'server: nothing without consent' );
cmp_assert( CMP_Bridge_PYS::server_allowed( 'facebook', array( 'facebook' ), $bp_nil ) && ! CMP_Bridge_PYS::server_allowed( 'openai', array( 'facebook' ), $bp_nil ), 'server: an exempt pixel is allowed, the others are not' );

// ---- The hooks, in their own process ----
$bp_cookie = function ( array $cats, $v = '3' ) {
	return json_encode( array( 'v' => $v, 'categories' => array_merge( array( 'necessary' ), $cats ), 'decision' => 'custom' ) );
};
$bp_pro  = array( 'mode' => 'pro', 'block_scripts' => true, 'policy_version' => '3' );
$bp_base = array( 'pys' => true, 'events' => true, 'settings' => $bp_pro );
$bp_chk  = function ( $marketing, $statistics ) {
	return array( 'facebook' => $marketing, 'ga' => $statistics, 'gtm' => $statistics, 'openai' => $marketing, 'pinterest' => $marketing );
};
foreach ( array(
	'no cookie / cron'    => array( '', false, false ),
	'refused'             => array( $bp_cookie( array() ), false, false ),
	'statistics only'     => array( $bp_cookie( array( 'statistics' ) ), false, false ),
	'marketing only'      => array( $bp_cookie( array( 'marketing' ) ), true, false ),
	'all accepted'        => array( $bp_cookie( array( 'statistics', 'marketing' ) ), true, true ),
	'older policy'        => array( $bp_cookie( array( 'statistics', 'marketing' ), '2' ), false, false ),
	'garbage'             => array( '{"v":', false, false ),
) as $bp_l => $bp_c ) {
	$r = $bp_req( array_merge( $bp_base, array( 'cookie' => $bp_c[0] ) ) );
	cmp_assert_eq( $r['disabled'], ! $bp_c[1], "server, {$bp_l}: pys_disable_server_event_filter" );
	cmp_assert_eq( $r['check'], $bp_chk( $bp_c[1], $bp_c[2] ), "server, {$bp_l}: pys_check_consent_by_gdpr per pixel" );
	cmp_assert_eq( $r['restricted'], $bp_c[1], "server, {$bp_l}: pys_pixel_restricted_mode never sends without consent (mayFire = consent OR restricted)" );
	cmp_assert_eq( $r['formdata'], ! $bp_c[1], "server, {$bp_l}: the pys_advanced_form_data cookie (email, phone)" );
	cmp_assert_eq( $r['session'], ! $bp_c[1], "server, {$bp_l}: the PHP session with the landing page" );
	cmp_assert_eq( $r['notices'], array(), "server, {$bp_l}: no notice" );
}
cmp_assert( true === $r['disabled1'] && false === $r['check_no'] && false === $r['restr_no'] && true === $r['formdata1'] && true === $r['session1'], 'server: what PixelYourSite or the site already refused stays refused' );
$r = $bp_req( array_merge( $bp_base, array( 'cookie' => $bp_cookie( array( 'statistics', 'marketing' ) ) ) ) );
cmp_assert( true === $r['disabled1'] && false === $r['check_no'] && false === $r['restr_no'] && true === $r['formdata1'] && true === $r['session1'], 'server: also when the visitor accepted everything' );
cmp_assert_eq( array_values( preg_grep( '/^pys_disable_.*_by_gdpr$|^pys_disable_by_gdpr$|^pys_disable_all_cookie$/', $r['hooks'] ) ), array(), 'server: no pys_disable_*_by_gdpr filter (its value would stay in the page cache)' );
cmp_assert( false !== strpos( $r['tag'], 'cmp-pys-gate' ) && 2 === substr_count( $r['tag'], 'data-cmp-category="marketing"' ), 'page: the tag is held back whatever the visitor chose (Review Focus 1)' );
cmp_assert_eq( $bp_req( $bp_base )['tag'], $r['tag'], 'page: same HTML with and without consent, so a cached page is right for everyone' );
cmp_assert_eq( $r['other'], '<script src="/j.js" id="jquery-js"></script>', 'page: other scripts untouched' );
cmp_assert_eq( $r['bridged'], array( 'pys' ), 'bridged(): pys when the bridge is in effect' );
sort( $r['removed'] );
cmp_assert_eq( $r['removed'], array( 'wp_footer:outputNoScriptData:10', 'wp_head:outputPbidPrefetch:1' ), 'page: the noscript pixels and the early id request are unhooked' );
cmp_assert_eq( $r['printed'], array(), 'page: neither is printed, with consent either (the HTML may be cached)' );
cmp_assert_eq( $bp_req( array_merge( $bp_base, array( 'rebuild' => true ) ) )['tag'], CMP_Bridge_PYS::tag( '<script defer src="/p/public.min.js" id="pys-js"></script>', 'pys', array() ), 'page: a theme that rebuilds the tag at the default priority does not undo the hold' );
// While PixelYourSite prints page HTML the form-data flag must not depend on the visitor.
$r = $bp_req( array_merge( $bp_base, array( 'render' => true ) ) );
cmp_assert( false === $r['formdata'] && true === $r['formdata1'], 'page HTML: the form-data flag is the site\'s own value (cache)' );
// Light: an old Pro cookie does not count.
$r = $bp_req( array_merge( $bp_base, array( 'settings' => array( 'mode' => 'light', 'block_scripts' => false, 'policy_version' => '3' ), 'cookie' => $bp_cookie( array( 'statistics', 'marketing' ) ) ) ) );
cmp_assert( true === $r['disabled'] && $bp_chk( false, false ) === $r['check'] && false === $r['restricted'] && true === $r['session'], 'Light, old Pro cookie: nothing is sent' );
cmp_assert_eq( $r['bridged'], array( 'pys' ), 'Light: the bridge is in effect' );
// Exempt.
$r = $bp_req( array_merge( $bp_base, array( 'services' => array( 'overrides' => array( 'meta-pixel' => array( 'exempt' => true ) ) ) ) ) );
cmp_assert( false === $r['disabled'] && array_merge( $bp_chk( false, false ), array( 'facebook' => true ) ) === $r['check'] && false === $r['formdata'] && false === $r['session'], 'Meta exempt: its server events are allowed, the other pixels are not' );
cmp_assert( false !== strpos( $r['tag'], 'data-cmp-keep' ) && false === strpos( $r['tag'], 'data-cmp-category' ), 'Meta exempt: the script stays live behind the live gate' );
cmp_assert_eq( $r['printed'], array( 'pbid' ), 'Meta exempt: the id request is made, no noscript pixel is printed' );
cmp_assert_eq( $r['removed'], array( 'wp_footer:outputNoScriptData:10' ), 'Meta exempt: the id request stays' );
$r = $bp_req( array_merge( $bp_base, array( 'settings' => array( 'mode' => 'light', 'policy_version' => '3' ), 'services' => array( 'overrides' => array( 'meta-pixel' => array( 'exempt' => true ) ) ) ) ) );
cmp_assert( false === $r['disabled'] && true === $r['check']['facebook'] && false === $r['check']['ga'], 'Light, Meta exempt: exempt here as in the blocker' );
$r = $bp_req( array_merge( $bp_base, array( 'services' => array( 'overrides' => array( 'google-analytics' => array( 'exempt' => true ) ) ) ) ) );
cmp_assert( true === $r['disabled'] && true === $r['check']['ga'] && false === $r['check']['gtm'] && false === $r['check']['facebook'], 'GA exempt: Meta server events still wait' );
$r = $bp_req( array_merge( $bp_base, array( 'settings' => array_merge( $bp_pro, array( 'measurement' => 'advanced' ) ) ) ) );
cmp_assert( false === $r['check']['ga'] && false !== strpos( $r['tag'], 'data-cmp-category="marketing"' ), 'Advanced: exempts nothing in PixelYourSite' );
// Custom categories (I3).
$bp_cats4 = array( array( 'slug' => 'necessary', 'required' => true ), array( 'slug' => 'statistics' ), array( 'slug' => 'marketing' ), array( 'slug' => 'ads' ) );
$bp_inads = array( 'categories' => $bp_cats4, 'services' => array( 'overrides' => array( 'meta-pixel' => array( 'category' => 'ads' ) ) ) );
$r = $bp_req( array_merge( $bp_base, $bp_inads, array( 'cookie' => $bp_cookie( array( 'statistics', 'marketing' ) ) ) ) );
cmp_assert( true === $r['disabled'] && $bp_chk( false, false ) === $r['check'] && true === $r['session'] && 2 === substr_count( $r['tag'], 'data-cmp-category="ads"' ), 'Meta in ads, marketing accepted and ads refused: nothing, in the page or from the server' );
$r = $bp_req( array_merge( $bp_base, $bp_inads, array( 'cookie' => $bp_cookie( array( 'ads' ) ) ) ) );
cmp_assert( false === $r['disabled'] && $bp_chk( true, false ) === $r['check'], 'Meta in ads, ads accepted: Meta and the pixels without a service' );
$r = $bp_req( array_merge( $bp_base, array( 'categories' => array( array( 'slug' => 'necessary', 'required' => true ), array( 'slug' => 'statistics' ), array( 'slug' => 'ads' ) ), 'services' => array( 'overrides' => array( 'meta-pixel' => array( 'category' => 'ads' ) ) ), 'cookie' => $bp_cookie( array( 'ads', 'statistics' ) ) ) ) );
cmp_assert( false === $r['disabled'] && $bp_chk( true, true ) === $r['check'] && false !== strpos( $r['tag'], '"ads"' ), 'the "marketing" slug deleted, Meta in ads: PixelYourSite starts with ads' );
$r = $bp_req( array_merge( $bp_base, array( 'categories' => array( array( 'slug' => 'necessary', 'required' => true ), array( 'slug' => 'statistics' ) ), 'cookie' => $bp_cookie( array( 'statistics', 'marketing' ) ) ) ) );
cmp_assert( true === $r['disabled'] && $bp_chk( false, false ) === $r['check'], 'the "marketing" slug deleted, Meta not moved: closed, as the blocker does with fbq' );
$r = $bp_req( array_merge( $bp_base, array( 'services' => array( 'overrides' => array( 'meta-pixel' => array( 'category' => 'necessary' ) ) ) ) ) );
cmp_assert( false === $r['disabled'] && array_merge( $bp_chk( false, false ), array( 'facebook' => true ) ) === $r['check'] && false !== strpos( $r['tag'], 'data-cmp-keep' ), 'Meta necessary: exempt, the other pixels still wait for marketing' );
$bp_req_ads = array( 'categories' => array( array( 'slug' => 'necessary', 'required' => true ), array( 'slug' => 'statistics' ), array( 'slug' => 'marketing' ), array( 'slug' => 'ads', 'required' => true ) ), 'services' => array( 'overrides' => array( 'meta-pixel' => array( 'category' => 'ads' ) ) ) );
$r = $bp_req( array_merge( $bp_base, $bp_req_ads ) );
cmp_assert( false === $r['disabled'] && false === $r['check']['openai'] && false !== strpos( $r['tag'], 'data-cmp-keep' ), 'Meta in a required category: exempt' );
$r = $bp_req( array_merge( $bp_base, $bp_req_ads, array( 'settings' => array( 'mode' => 'light', 'policy_version' => '3' ) ) ) );
cmp_assert( true === $r['disabled'] && false === strpos( $r['tag'], 'data-cmp-keep' ), 'Light, Meta in a required category: held, as the blocker holds it (Light spares only "necessary" and "nu bloca")' );
// The loopback of PixelYourSite's async task forwards the cookie with WordPress' slashes still in it.
$r = $bp_req( array_merge( $bp_base, array( 'cookie' => $bp_cookie( array( 'marketing' ) ), 'forwarded' => true ) ) );
cmp_assert( false === $r['disabled'] && true === $r['sent'], 'loopback: consent is read from the forwarded cookie, before PixelYourSite\'s handler sends' );
$r = $bp_req( array_merge( $bp_base, array( 'cookie' => $bp_cookie( array( 'statistics' ) ), 'forwarded' => true ) ) );
cmp_assert( true === $r['disabled'] && false === $r['sent'], 'loopback: a refusal is read from it too' );
$r = $bp_req( array_merge( $bp_base, array( 'cookie' => '{"v":', 'forwarded' => true ) ) );
cmp_assert_eq( $r['disabled'], true, 'loopback: garbage is a no' );
$bp_raw = $bp_cookie( array( 'marketing' ) );
cmp_assert_eq( CMP_Bridge_PYS::forwarded_cookie( addslashes( $bp_raw ) ), addslashes( $bp_raw ), 'forwarded_cookie: a normal cookie is left alone' );
cmp_assert_eq( CMP_Bridge_PYS::forwarded_cookie( addslashes( addslashes( $bp_raw ) ) ), addslashes( $bp_raw ), 'forwarded_cookie: one extra level of slashes is taken off' );
cmp_assert_eq( CMP_Bridge_PYS::forwarded_cookie( 'x\\\\"' ), 'x\\\\"', 'forwarded_cookie: anything else is left alone' );
// Not registered.
foreach ( array(
	'PixelYourSite not active' => array( 'pys' => false, 'settings' => $bp_pro ),
	'blocking off'             => array( 'pys' => true, 'events' => true, 'settings' => array( 'mode' => 'pro', 'block_scripts' => false ) ),
) as $bp_l => $bp_in ) {
	$r = $bp_req( $bp_in );
	cmp_assert( array() === $r['hooks'] && array() === $r['removed'] && array() === $r['bridged'] && array() === $r['notices'], "{$bp_l}: nothing is hooked, nothing is bridged" );
	cmp_assert_eq( $r['tag'], '<script src="/p/public.min.js" id="pys-js"></script>', "{$bp_l}: the tag is unchanged" );
}
// PixelYourSite changed: its functions are gone, or it loads nothing on this page (Review Focus 5).
foreach ( array( 'broken' => array( 'broken' => true ), 'no events manager' => array( 'events' => false ) ) as $bp_l => $bp_in ) {
	$r = $bp_req( array_merge( $bp_base, $bp_in ) );
	cmp_assert( array() === $r['notices'] && array() === $r['removed'] && false !== strpos( $r['tag'], 'data-cmp-category="marketing"' ) && true === $r['disabled'], "PixelYourSite, {$bp_l}: no notice, no fatal, its script stays blocked" );
}
cmp_assert( CMP_Bridge_PYS::needed( true, true ) && ! CMP_Bridge_PYS::needed( true, false ) && ! CMP_Bridge_PYS::needed( false, true ), 'needed(): PixelYourSite active and blocking on' );
$bp_plugin = (string) file_get_contents( __DIR__ . '/../includes/class-plugin.php' );
cmp_assert( 1 === preg_match( '/require_once CMP_PATH \. \'includes\/modules\/class-bridge-pys\.php\';\s*CMP_Bridge_PYS::register\(\);/', $bp_plugin ), 'class-plugin.php loads and registers the bridge' );

// ---- Our Consent Mode stub and PixelYourSite's rename of the data layer (class-banner.php) ----
$bp_sig  = array( 'ad_storage' => 'denied' );
$bp_want = "<script data-cmp-keep>\nwindow.dataLayer = window.dataLayer || [];\nfunction gtag(){dataLayer.push(arguments);}\ngtag(\"consent\",\"default\",{\"ad_storage\":\"denied\"});\n</script>\n";
cmp_assert_eq( CMP_Banner::consent_default_script( $bp_sig, false ), $bp_want, 'stub: without PixelYourSite byte for byte as before' );
cmp_assert_eq( CMP_Banner::consent_default_script( $bp_sig, false, false ), $bp_want, 'stub: same when told there is no PixelYourSite' );
cmp_assert_eq( CMP_Banner::consent_default_script( $bp_sig, true ), str_replace( '<script data-cmp-keep>', '<script data-cmp-keep data-cmp-default>', $bp_want ), 'stub, Advanced: byte for byte as before' );
// What includes/…/functions-datalayer.php does to a block with a top-level gtag helper
// (pys_rename_gtag_snippet(), 11.4.7), written again here for the test.
$bp_rename = function ( $html, $name ) {
	return preg_replace_callback( '/(<script\b[^>]*>)(.*?)(<\/script>)/is', function ( $m ) use ( $name ) {
		$helper = '/function\s+gtag\s*\(\s*\)\s*\{\s*(?:window\s*\.\s*)?dataLayer\s*\.\s*push\s*\(\s*arguments\s*\)/';
		if ( ! preg_match( $helper, $m[2], $f, PREG_OFFSET_CAPTURE ) ) {
			return $m[0];
		}
		$before = preg_replace( '/"[^"]*"|\'[^\']*\'/', '', substr( $m[2], 0, $f[0][1] ) );
		if ( substr_count( $before, '{' ) !== substr_count( $before, '}' ) || substr_count( $before, '(' ) !== substr_count( $before, ')' ) ) {
			return $m[0];
		}
		return $m[1] . preg_replace( '/(?<![\w$])dataLayer(?![\w$])/', $name, $m[2] ) . $m[3];
	}, $html );
};
if ( '' !== $bp_node ) {
	// Runs the stub, then an update and an event through window.gtag, as banner.js and a page would.
	$bp_layers = function ( $html ) {
		$js  = preg_replace( '/<\/?script[^>]*>/', '', $html ) . "\ngtag('consent','update',{ad_storage:'granted'});gtag('event','x');";
		$run = 'var vm=require("vm"),w={};w.window=w;vm.runInNewContext(process.argv[1],w);var o={};["dataLayer","dataLayerPYS"].forEach(function(n){o[n]=(w[n]||[]).map(function(a){return a[0]+(a[0]==="consent"?":"+a[1]:"");});});console.log(JSON.stringify(o));';
		$r   = json_decode( (string) shell_exec( 'node -e ' . escapeshellarg( $run ) . ' ' . escapeshellarg( $js ) . ' 2>&1' ), true );
		return is_array( $r ) ? $r : array( 'dataLayer' => 'error', 'dataLayerPYS' => 'error' );
	};
	$bp_cons = array( 'consent:default', 'consent:update' );
	foreach ( array( 'Strict' => false, 'Advanced' => true ) as $bp_l => $bp_adv ) {
		$bp_plain = CMP_Banner::consent_default_script( $bp_sig, $bp_adv );
		$bp_stub  = CMP_Banner::consent_default_script( $bp_sig, $bp_adv, true );
		cmp_assert( 0 === strpos( $bp_stub, $bp_adv ? '<script data-cmp-keep data-cmp-default>' : '<script data-cmp-keep>' ), "stub, {$bp_l}, with PixelYourSite: same opening tag" );
		cmp_assert_eq( $bp_layers( $bp_stub ), array( 'dataLayer' => array_merge( $bp_cons, array( 'event' ) ), 'dataLayerPYS' => array() ), "stub, {$bp_l}, not renamed: each consent command once in dataLayer" );
		cmp_assert_eq( $bp_layers( $bp_rename( $bp_stub, 'dataLayerPYS' ) ), array( 'dataLayer' => $bp_cons, 'dataLayerPYS' => array_merge( $bp_cons, array( 'event' ) ) ), "stub, {$bp_l}, renamed: each consent command once in each layer, events only in PixelYourSite's" );
		cmp_assert_eq( $bp_layers( $bp_rename( $bp_plain, 'dataLayerPYS' ) )['dataLayer'], array(), "stub, {$bp_l}: (the problem) the old stub, renamed, says nothing to dataLayer" );
		cmp_assert_eq( $bp_layers( $bp_rename( $bp_stub, 'dataLayer' ) )['dataLayer'], array_merge( $bp_cons, array( 'event' ) ), "stub, {$bp_l}, renamed to dataLayer: no duplicates" );
	}
	$bp_out = CMP_Blocker::place_consent_default( '<html><head><title>t</title>' . CMP_Banner::consent_default_script( $bp_sig, true, true ) . '</head></html>', CMP_Banner::consent_default_script( $bp_sig, true, true ) );
	cmp_assert( 1 === substr_count( $bp_out, 'gtag("consent","default"' ), 'stub, Advanced, with PixelYourSite: placed once' );
}
cmp_assert_eq( array( 'plain', 'plain', 'mirror', 'mirror' ), array_map( function ( $in ) use ( $bp_req ) {
	$r = $bp_req( $in );
	return false !== strpos( (string) $r['stub'], '"data"+"Layer"' ) ? 'mirror' : 'plain';
}, array( array( 'settings' => $bp_pro ), array( 'settings' => array( 'mode' => 'pro', 'block_scripts' => false ) ), $bp_base, array( 'pys' => true, 'settings' => array( 'mode' => 'pro', 'block_scripts' => false ) ) ) ), 'stub: the mirror only where PixelYourSite is loaded (blocking on or off)' );