<?php
/**
 * Lot A, Task 3: the WooCommerce order attribution bridge (CMP_Bridge_WC).
 *  - needed() and the hooks, in their own process (tests/fixtures/bridge-wc-request.php);
 *  - before_js() and js(), run with node on a fake page (tests/fixtures/bridge-wc-page.js).
 * Sources named here are WooCommerce 11.3.1 (wordpress.org), read and never run.
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once __DIR__ . '/../includes/class-services.php';
require_once __DIR__ . '/../includes/class-known-plugins.php';
require_once __DIR__ . '/../includes/modules/class-blocker.php';
require_once __DIR__ . '/../includes/modules/class-bridge-wc.php';
require_once __DIR__ . '/../includes/class-consent.php';

// ---- needed() ----
foreach ( array( true, false ) as $bw_w ) {
	foreach ( array( true, false ) as $bw_a ) {
		foreach ( array( true, false ) as $bw_b ) {
			cmp_assert_eq( CMP_Bridge_WC::needed( $bw_w, $bw_a, $bw_b ), $bw_w && ! $bw_a && $bw_b, 'needed( wc ' . (int) $bw_w . ', consent api ' . (int) $bw_a . ', blocking ' . (int) $bw_b . ' )' );
		}
	}
}

// ---- the hooks ----
$bw_req = function ( array $in ) {
	$raw = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/bridge-wc-request.php' ) . ' ' . escapeshellarg( json_encode( $in ) ) . ' 2>&1' );
	$r   = json_decode( $raw, true );
	if ( ! is_array( $r ) ) {
		echo "bridge-wc-request output: {$raw}\n";
		$r = array();
	}
	return array_merge( array( 'ours' => array( 'none' ), 'raw_allow' => 'none', 'touched' => array( 'none' ), 'scripts' => array(), 'html' => 'none', 'rewritten' => 'none2', 'bridged' => array( 'none' ), 'notices' => array( 'none' ) ), $r );
};
$bw_on     = array( 'mode' => 'pro', 'block_scripts' => true );
$bw_inline = array( 'inline:wc-order-attribution:before', 'inline:wc-order-attribution:after' );
$bw_cookie = json_encode( array( 'v' => '1', 'categories' => array( 'necessary', 'statistics', 'marketing' ), 'decision' => 'accepted_all' ) );

$bw_r = $bw_req( array( 'wc' => true, 'settings' => $bw_on ) );
cmp_assert_eq( $bw_r['raw_allow'], false, 'in effect: wc_order_attribution_allow_tracking is false' );
cmp_assert( false !== strpos( $bw_r['html'], '"allowTracking":false' ), 'in effect: the HTML says allowTracking false' );
cmp_assert_eq( $bw_r['touched'], $bw_inline, 'in effect: only inline code on wc-order-attribution, nothing dequeued, deregistered or given data' );
cmp_assert_eq( isset( $bw_r['scripts']['wc-order-attribution']['after'] ) ? $bw_r['scripts']['wc-order-attribution']['after'] : '', CMP_Bridge_WC::js(), 'in effect: js() after the script' );
cmp_assert_eq( isset( $bw_r['scripts']['wc-order-attribution']['before'] ) ? $bw_r['scripts']['wc-order-attribution']['before'] : '', CMP_Bridge_WC::before_js(), 'in effect: before_js() before the script' );
cmp_assert_eq( $bw_r['rewritten'], $bw_r['html'], 'in effect: the blocker leaves every script of the page as it is' );
cmp_assert_eq( $bw_r['bridged'], array( 'wc-attribution' ), 'in effect: bridged() names it' );
cmp_assert_eq( $bw_r['notices'], array(), 'in effect: no notice' );
cmp_assert_eq( array_keys( $bw_r['scripts'] ), array( 'sourcebuster-js', 'wc-order-attribution', 'wc-cart-fragments', 'wc-checkout' ), 'in effect: the same scripts in the same order' );
$bw_ours = $bw_r['ours'];
sort( $bw_ours );
cmp_assert_eq( array_values( array_unique( $bw_ours ) ), array( 'init', 'wc_order_attribution_allow_tracking', 'wp_enqueue_scripts' ), 'in effect: these hooks only (no order, checkout or script tag hook)' );
cmp_assert( false === strpos( implode( ' ', $bw_r['ours'] ), 'woocommerce_' ), 'in effect: server-side order handling is not touched' );

// Page cache: the page is the same whoever it was made for.
$bw_c = $bw_req( array( 'wc' => true, 'settings' => $bw_on, 'cookie' => $bw_cookie ) );
cmp_assert_eq( $bw_c['html'], $bw_r['html'], 'cache: byte for byte the same page for a visitor who accepted everything' );
// Light blocks always; the page is off there too.
$bw_l = $bw_req( array( 'wc' => true, 'settings' => array( 'mode' => 'light', 'block_scripts' => false ), 'cookie' => $bw_cookie ) );
cmp_assert_eq( $bw_l['raw_allow'], false, 'Light: off, also with an old Pro cookie' );
cmp_assert_eq( $bw_l['bridged'], array( 'wc-attribution' ), 'Light: in effect' );

$bw_off = array(
	'blocking off'          => array( 'wc' => true, 'settings' => array( 'mode' => 'pro', 'block_scripts' => false ) ),
	'no settings'           => array( 'wc' => true, 'settings' => array() ),
	'settings not an array' => array( 'wc' => true, 'settings' => 'x' ),
	'WP Consent API'        => array( 'wc' => true, 'settings' => $bw_on, 'api' => true, 'fn' => true ),
	'its class only'        => array( 'wc' => true, 'settings' => $bw_on, 'api' => true ),
	'its class late (init)' => array( 'wc' => true, 'settings' => $bw_on, 'api_init' => true ),
	'Light + consent API'   => array( 'wc' => true, 'settings' => array( 'mode' => 'light' ), 'api' => true ),
);
foreach ( $bw_off as $bw_l => $bw_in ) {
	$bw_o = $bw_req( $bw_in );
	cmp_assert_eq( $bw_o['touched'], array(), "off ({$bw_l}): nothing attached" );
	cmp_assert_eq( $bw_o['raw_allow'], empty( $bw_in['api'] ) && empty( $bw_in['api_init'] ) ? true : 'consent-api', "off ({$bw_l}): WooCommerce decides by itself" );
	cmp_assert( false === in_array( 'wc_order_attribution_allow_tracking', $bw_o['ours'], true ) && false === in_array( 'wp_enqueue_scripts', $bw_o['ours'], true ), "off ({$bw_l}): no hook of ours" );
	cmp_assert_eq( $bw_o['bridged'], array(), "off ({$bw_l}): not bridged" );
	cmp_assert_eq( $bw_o['notices'], array(), "off ({$bw_l}): no notice" );
}
// Only wp_set_consent (not the class): WooCommerce does not see that (WPConsentAPI.php:81-83), so the bridge stays.
$bw_o = $bw_req( array( 'wc' => true, 'settings' => $bw_on, 'fn' => true ) );
cmp_assert( false === $bw_o['raw_allow'] && $bw_inline === $bw_o['touched'], 'wp_set_consent without the class: WooCommerce does nothing, the bridge does' );
// WooCommerce away, or its attribution switched off.
$bw_o = $bw_req( array( 'settings' => $bw_on ) );
cmp_assert( array() === $bw_o['touched'] && array() === $bw_o['bridged'] && array() === $bw_o['notices'] && '' === $bw_o['html'], 'WooCommerce not active: nothing' );
$bw_o = $bw_req( array( 'wc' => true, 'settings' => $bw_on, 'feature' => false ) );
cmp_assert( array() === $bw_o['touched'] && array() === $bw_o['notices'], 'order attribution off in WooCommerce: nothing attached, no notice' );
cmp_assert_eq( $bw_o['bridged'], array(), 'order attribution off in WooCommerce: not shown as bridged' );
$bw_o = $bw_req( array( 'wc' => true, 'old' => true, 'settings' => $bw_on ) );
cmp_assert( false === $bw_o['raw_allow'] && array( 'wc-attribution' ) === $bw_o['bridged'], 'no FeaturesUtil (renamed): still in effect' );
// The site's own filter: the bridge only refuses more.
foreach ( array( false, 'no', 0, '' ) as $bw_v ) {
	$bw_o = $bw_req( array( 'wc' => true, 'settings' => $bw_on, 'site' => $bw_v ) );
	cmp_assert( false === $bw_o['raw_allow'] && array() === $bw_o['touched'], 'the site says no (' . var_export( $bw_v, true ) . '): it stays off for everyone, no script' );
}
foreach ( array( true, 'yes' ) as $bw_v ) {
	$bw_o = $bw_req( array( 'wc' => true, 'settings' => $bw_on, 'site' => $bw_v, 'site_prio' => 100000 ) );
	cmp_assert( false === $bw_o['raw_allow'] && $bw_inline === $bw_o['touched'], 'the site says yes late (' . var_export( $bw_v, true ) . '): the HTML stays on no, the browser decides' );
}

// ---- the fixture page: no script of WooCommerce is blocked or rewritten ----
$bw_fx   = (string) file_get_contents( __DIR__ . '/fixtures/known-plugins/woocommerce.html' );
$bw_page = str_replace(
	array( '<script src="https://example.test/wp-content/plugins/woocommerce/assets/js/frontend/order-attribution.min.js?ver=11.3.1" id="wc-order-attribution-js"></script>', '"allowTracking":true' ),
	array( '<script id="wc-order-attribution-js-before">' . "\n" . CMP_Bridge_WC::before_js() . "\n</script>\n" . '<script src="https://example.test/wp-content/plugins/woocommerce/assets/js/frontend/order-attribution.min.js?ver=11.3.1" id="wc-order-attribution-js"></script>' . "\n" . '<script id="wc-order-attribution-js-after">' . "\n" . CMP_Bridge_WC::js() . "\n</script>", '"allowTracking":false' ),
	$bw_fx
);
cmp_assert( 2 === substr_count( $bw_page, 'js-before">' ) + substr_count( $bw_page, 'js-after">' ), 'fixture: both inline parts are in the page' );
foreach ( array( false, true ) as $bw_adv ) {
	$bw_svc = CMP_Services::apply_measurement( CMP_Services::all( CMP_Services::library(), array(), array( 'necessary', 'statistics', 'marketing' ) ), $bw_adv );
	cmp_assert_eq( CMP_Blocker::rewrite( $bw_page, array(), $bw_svc ), $bw_page, 'fixture: nothing is blocked or rewritten' . ( $bw_adv ? ' (Advanced)' : '' ) );
	cmp_assert_eq( CMP_Blocker::detect_services( $bw_page, $bw_svc ), array( 'raw' => array(), 'blocked' => array() ), 'fixture: the scan sees no tracker in the bridge' . ( $bw_adv ? ' (Advanced)' : '' ) );
}
foreach ( array( 'before_js' => CMP_Bridge_WC::before_js(), 'js' => CMP_Bridge_WC::js() ) as $bw_l => $bw_js ) {
	cmp_assert( 0 === preg_match( '/[^\x20-\x7E]|<|>/', $bw_js ), "{$bw_l}: plain ASCII, no angle brackets (safe inline)" );
}
cmp_assert( false === strpos( CMP_Bridge_WC::before_js(), 'cookie' ), 'before_js: no cookie is read or written' );
cmp_assert( 1 === substr_count( CMP_Bridge_WC::js(), 'd.cookie=' ) && false !== strpos( CMP_Bridge_WC::js(), 'd.cookie=n+"=; path=/; max-age=-999; domain=."+' ), 'js: the only cookie write is an expiry' );

// What the code relies on in banner.js: the cookie is written before cmp:consent, isAllowed is public.
$bw_banner = (string) file_get_contents( __DIR__ . '/../assets/js/banner.js' );
$bw_save   = substr( $bw_banner, (int) strpos( $bw_banner, 'function save(accepted, decision)' ), 2500 );
cmp_assert( strpos( $bw_save, 'writeCookie(data)' ) < strpos( $bw_save, "document.dispatchEvent(new CustomEvent('cmp:consent'" ) && false !== strpos( $bw_save, 'writeCookie(data)' ), 'banner.js: the decision is stored before cmp:consent' );
cmp_assert( false !== strpos( $bw_banner, 'isAllowed: function (slug)' ) && false !== strpos( $bw_banner, 'window.CMP = {' ), 'banner.js: CMP.isAllowed( slug )' );

// ---- before_js() and js(), with node ----
$bw_node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
if ( '' === $bw_node ) {
	echo "  (skip: node not found, the WooCommerce bridge is not run)\n";
} else {
	$bw_run = function ( array $steps, array $in = array() ) {
		$in  = array_merge( array( 'before' => CMP_Bridge_WC::before_js(), 'after' => CMP_Bridge_WC::js(), 'steps' => $steps ), $in );
		$raw = (string) shell_exec( 'node ' . escapeshellarg( __DIR__ . '/fixtures/bridge-wc-page.js' ) . ' ' . escapeshellarg( json_encode( $in ) ) . ' 2>&1' );
		$r   = json_decode( $raw, true );
		if ( ! is_array( $r ) ) {
			echo "bridge-wc-page output: {$raw}\n";
			$r = array();
		}
		return array_merge( array( 'error' => 'no output', 'calls' => array( 'none' ), 'inits' => -1, 'cookies' => array( 'none' ), 'source' => 'none', 'session' => 'none', 'form' => 'none', 'forms' => array(), 'groups' => -1, 'writes' => array( 'none' ), 'block' => 'none', 'allow' => 'none', 'listeners' => -1 ), $r );
	};
	$bw_m    = array( 'allowed' => array( 'marketing' ) );
	$bw_end  = array( 'dcl', 'load', 'pageshow', 'script', 'visible' );
	$bw_jar  = array( 'sbjs_current@.example.test' => 'utm-spring', 'sbjs_session@.example.test' => '4' );
	$bw_sbjs = array( 'sbjs_current@.example.test', 'sbjs_session@.example.test' );

	// js() alone: what it does with WooCommerce already on "no".
	$bw_alone = array( 'extra', 'cmp', 'wc', 'after' );
	$bw_r     = $bw_run( array_merge( $bw_alone, $bw_end ), $bw_m );
	cmp_assert_eq( $bw_r['error'], '', 'js: no error' );
	cmp_assert_eq( $bw_r['calls'], array( false, true ), 'js: marketing already allowed: setOrderTracking(true) once (after WooCommerce\'s own false)' );
	cmp_assert_eq( $bw_r['inits'], 1, 'js: and later events do not call it again' );
	$bw_r = $bw_run( array_merge( $bw_alone, $bw_end ) );
	cmp_assert_eq( $bw_r['calls'], array( false ), 'js: nothing allowed: never called' );
	foreach ( array( 'statistics' => array( 'statistics' ), 'preferences' => array( 'preferences', 'necessary' ) ) as $bw_l => $bw_a ) {
		cmp_assert_eq( $bw_run( array_merge( $bw_alone, $bw_end ), array( 'allowed' => $bw_a ) )['calls'], array( false ), "js: {$bw_l} only: never called" );
	}
	$bw_r = $bw_run( array_merge( $bw_alone, array( array( 'consent', array( 'marketing' ) ) ), $bw_end ) );
	cmp_assert_eq( $bw_r['calls'], array( false, true ), 'js: a later accept: true once' );
	cmp_assert( 'direct' === $bw_r['form'] && 'direct' === $bw_r['block'], 'js: a later accept fills the classic form and the checkout block' );
	$bw_r = $bw_run( array_merge( $bw_alone, array( array( 'consent', array( 'statistics' ) ) ), $bw_end ) );
	cmp_assert_eq( $bw_r['calls'], array( false, false ), 'js: a refusal or statistics only: told false once' );
	$bw_r = $bw_run( array_merge( $bw_alone, array( array( 'consent', array() ) ), $bw_end ), $bw_m );
	cmp_assert_eq( $bw_r['calls'], array( false, true, false ), 'js: withdrawal: false once' );
	cmp_assert( '' === $bw_r['form'] && null === $bw_r['block'] && array() === $bw_r['cookies'], 'js: withdrawal empties the form and the block, and WooCommerce removes sbjs_*' );
	$bw_r = $bw_run( array_merge( $bw_alone, array( array( 'consent', array() ), array( 'consent', array( 'statistics' ) ), array( 'consent', array( 'marketing' ) ), array( 'consent', array( 'marketing', 'statistics' ) ) ) ), $bw_m );
	cmp_assert_eq( $bw_r['calls'], array( false, true, false, false, true ), 'js: every decision that says no is told, a yes only on a change' );
	$bw_r = $bw_run( array( 'extra', 'before', 'cmp', 'wc', 'after', 'params', array( 'consent', array() ), 'visible' ), $bw_m );
	cmp_assert( array( true, false ) === $bw_r['calls'] && '' === $bw_r['form'], 'js: params replaced by other code: a withdrawal still reaches WooCommerce, once' );

	// When CMP arrives.
	$bw_r = $bw_run( array( 'extra', 'wc', 'after', 'cmp', 'dcl' ), $bw_m );
	cmp_assert_eq( $bw_r['calls'], array( false, true ), 'js: CMP ready only at DOMContentLoaded' );
	$bw_r = $bw_run( array( 'extra', 'wc', 'after', 'dcl', 'cmp', 'load' ), $bw_m );
	cmp_assert_eq( $bw_r['calls'], array( false, true ), 'js: CMP ready only at load' );
	$bw_r = $bw_run( array( 'extra', 'wc', 'after', 'dcl', 'load', 'cmp', 'script' ), $bw_m );
	cmp_assert_eq( $bw_r['calls'], array( false, true ), 'js: CMP from a delayed script after load' );
	$bw_r = $bw_run( array( 'extra', 'wc', 'after', 'dcl', 'load', 'cmp', array( 'consent', array( 'marketing' ) ) ) );
	cmp_assert_eq( $bw_r['calls'], array( false, true ), 'js: CMP late, then a decision' );
	// When order-attribution.js arrives.
	$bw_r = $bw_run( array( 'extra', 'cmp', 'after', 'wc', 'dcl', 'load' ), $bw_m );
	cmp_assert_eq( $bw_r['calls'], array( false, true ), 'js: order-attribution.js deferred' );
	$bw_r = $bw_run( array( 'extra', 'cmp', 'after', 'dcl', 'load', 'wc', 'script', 'script' ), $bw_m );
	cmp_assert_eq( $bw_r['calls'], array( false, true ), 'js: order-attribution.js delayed until after load' );
	$bw_r = $bw_run( array( 'extra', 'cmp', 'after', 'dcl', 'load', array( 'consent', array( 'marketing' ) ), 'wc', 'script' ) );
	cmp_assert_eq( $bw_r['calls'], array( false, true ), 'js: a decision before order-attribution.js is there counts when it comes' );
	$bw_r = $bw_run( array( 'extra', 'wc', 'after', 'script' ) );
	cmp_assert_eq( $bw_r['listeners'], 1, 'js: keeps listening for scripts while CMP is missing' );
	$bw_r = $bw_run( array( 'extra', 'cmp', 'wc', 'after', 'script' ) );
	cmp_assert_eq( $bw_r['listeners'], 0, 'js: stops listening for scripts once both are there' );
	// No signal means no.
	foreach ( array( 'without CMP' => array( 'extra', 'cmp-data', 'wc', 'after' ), 'without CMP and CMP_DATA' => array( 'extra', 'wc', 'after' ), 'CMP throws' => array( 'extra', 'cmp-throws', 'wc', 'after' ), 'CMP answers something odd' => array( 'extra', 'cmp-odd', 'wc', 'after' ) ) as $bw_l => $bw_s ) {
		foreach ( array( 'js' => $bw_s, 'both parts' => array_merge( array( 'extra', 'before' ), array_slice( $bw_s, 1 ) ) ) as $bw_p => $bw_steps ) {
			$bw_r = $bw_run( array_merge( $bw_steps, $bw_end, array( array( 'consent', array( 'marketing' ) ) ) ), $bw_m );
			cmp_assert( '' === $bw_r['error'] && array( false, false ) === $bw_r['calls'] && array() === $bw_r['cookies'], "{$bw_p}, {$bw_l}: never true, no cookie, no error" );
		}
	}
	$bw_both = array( 'extra', 'before', 'cmp', 'wc', 'after' );
	foreach ( array(
		'Light with an old Pro cookie'            => array( 'mode' => 'light' ),
		'Light, marketing made required'          => array( 'mode' => 'light', 'required' => array( 'necessary', 'marketing' ) ),
		'the marketing category deleted'          => array( 'cats' => array( 'necessary', 'statistics', 'ads' ) ),
		'no categories at all'                    => array( 'cats' => array() ),
	) as $bw_l => $bw_in ) {
		foreach ( array( 'js' => $bw_alone, 'both parts' => $bw_both ) as $bw_p => $bw_steps ) {
			$bw_r = $bw_run( array_merge( $bw_steps, $bw_end, array( array( 'consent', array( 'marketing' ) ) ) ), array_merge( $bw_m, $bw_in, array( 'cookies' => $bw_jar ) ) );
			cmp_assert( '' === $bw_r['error'] && array( false, false ) === $bw_r['calls'] && array() === $bw_r['cookies'], "{$bw_p}, {$bw_l}: never true" );
		}
	}
	$bw_r = $bw_run( $bw_both, array( 'required' => array( 'necessary', 'marketing' ) ) );
	cmp_assert_eq( $bw_r['calls'], array( true ), 'Pro: a marketing category the admin made always active needs no decision (as isAllowed)' );
	// Nothing of WooCommerce there.
	foreach ( array( 'no wc_order_attribution' => array( 'before', 'cmp', 'after' ), 'no order-attribution.js' => array( 'extra', 'before', 'cmp', 'after' ) ) as $bw_l => $bw_s ) {
		$bw_r = $bw_run( array_merge( $bw_s, $bw_end, array( array( 'consent', array( 'marketing' ) ), array( 'consent', array() ) ) ), $bw_m );
		cmp_assert( '' === $bw_r['error'] && array() === $bw_r['calls'], "{$bw_l}: no error, no call" );
	}
	$bw_r = $bw_run( array_merge( $bw_alone, array( 'wc-throws', array( 'consent', array( 'marketing' ) ), array( 'consent', array() ) ) ) );
	cmp_assert( '' === $bw_r['error'] && array( false, true, false ) === $bw_r['calls'], 'an error inside WooCommerce does not reach the banner' );

	// before_js(): a later page of the same visit. WooCommerce's own start decides once, with the visitor's
	// answer. (order-attribution.js:145-146: with false it deletes the sbjs_* cookies, and the source with them.)
	$bw_r = $bw_run( array_merge( $bw_both, $bw_end ), array_merge( $bw_m, array( 'cookies' => $bw_jar ) ) );
	cmp_assert_eq( $bw_r['calls'], array( true ), 'later page, marketing allowed: WooCommerce starts with true, no call of ours' );
	cmp_assert( 'utm-spring' === $bw_r['source'] && '5' === $bw_r['session'], 'later page: the source of the visit survives and the page is counted once' );
	cmp_assert( 'utm-spring' === $bw_r['form'] && 'utm-spring' === $bw_r['block'], 'later page: the order would carry it, in both checkouts' );
	$bw_r = $bw_run( array( 'extra', 'before', 'after', 'cmp', 'sbjs', 'wc', 'dcl', 'load' ), array_merge( $bw_m, array( 'cookies' => $bw_jar ) ) );
	cmp_assert( array( true ) === $bw_r['calls'] && 'utm-spring' === $bw_r['source'], 'later page, every src deferred (inline parts first): the same' );
	$bw_r = $bw_run( array_merge( $bw_both, $bw_end ), array( 'cookies' => $bw_jar ) );
	cmp_assert( array( false ) === $bw_r['calls'] && array() === $bw_r['cookies'] && '' === $bw_r['form'], 'later page, not allowed: WooCommerce starts with false and removes sbjs_*' );
	$bw_r = $bw_run( array_merge( $bw_both, array( array( 'consent', array() ), array( 'consent', array( 'marketing' ) ), array( 'consent', array() ) ) ), array_merge( $bw_m, array( 'cookies' => $bw_jar ) ) );
	cmp_assert( array( true, false, true, false ) === $bw_r['calls'] && array() === $bw_r['cookies'], 'both parts: withdraw, accept, withdraw' );
	$bw_r = $bw_run( array_merge( $bw_both, array( array( 'consent', array( 'marketing' ) ) ), $bw_end ) );
	cmp_assert_eq( $bw_r['calls'], array( false, true ), 'both parts: first visit, then accept' );
	// allowTracking true in the HTML (another filter, after ours): the browser still decides.
	$bw_r = $bw_run( array( 'extra-true', 'before', 'cmp', 'wc', 'after' ), array( 'cookies' => $bw_jar ) );
	cmp_assert( array( false ) === $bw_r['calls'] && array() === $bw_r['cookies'], 'allowTracking true in the HTML: off without consent' );
	$bw_r = $bw_run( array( 'extra-true', 'wc', 'cmp', 'after' ), array() );
	cmp_assert_eq( $bw_r['calls'], array( true, false ), 'the same with js() alone: switched off' );
	// The property cannot be redefined: no error, js() still does its part.
	$bw_r = $bw_run( array( 'extra-frozen', 'before', 'cmp', 'wc', 'after' ), $bw_m );
	cmp_assert( '' === $bw_r['error'] && array( false, true ) === $bw_r['calls'], 'allowTracking not configurable: no error, js() switches it on' );
	// The inline part again (an optimizer printing it twice, or after order-attribution.js).
	$bw_r = $bw_run( array( 'extra', 'before', 'before', 'cmp', 'wc', 'before', 'after', 'after', 'load' ), $bw_m );
	cmp_assert( '' === $bw_r['error'] && array( true ) === $bw_r['calls'] && true === $bw_r['allow'], 'printed twice: one call' );
	$bw_r = $bw_run( array( 'extra', 'before', 'cmp', 'wc', 'before', 'after', array( 'consent', array() ) ), array_merge( $bw_m, array( 'cookies' => $bw_jar ) ) );
	cmp_assert( array( true, false ) === $bw_r['calls'] && array() === $bw_r['cookies'], 'printed again after WooCommerce started: a withdrawal still reaches it' );
	// A decision made elsewhere: another tab, or a page restored from the back / forward cache.
	foreach ( array( 'pageshow' => 'back / forward', 'visible' => 'another tab' ) as $bw_s => $bw_l ) {
		$bw_r = $bw_run( array_merge( $bw_both, array( array( 'elsewhere', array() ), $bw_s ) ), $bw_m );
		cmp_assert( array( true, false ) === $bw_r['calls'] && '' === $bw_r['form'], "withdrawn elsewhere ({$bw_l}): off when the page is seen again" );
	}
	// Somebody else switched it off: not switched back on at the next event; a new accept counts.
	$bw_r = $bw_run( array_merge( $bw_alone, array( array( 'wc-set', false ), 'pageshow', 'visible' ) ), $bw_m );
	cmp_assert_eq( $bw_r['calls'], array( false, true, false ), 'switched off by other code: left off' );
	$bw_r = $bw_run( array_merge( $bw_alone, array( array( 'wc-set', false ), array( 'consent', array( 'marketing' ) ), 'visible' ) ), $bw_m );
	cmp_assert_eq( $bw_r['calls'], array( false, true, false, true ), 'switched off by other code, then a new decision: on' );
	$bw_r = $bw_run( array_merge( $bw_both, array( array( 'wc-set', true ), 'visible' ) ) );
	cmp_assert_eq( $bw_r['calls'], array( false, true, false ), 'switched on by other code without consent: switched off' );
	// setOrderTracking(undefined) by other code does not bring the accessor back.
	$bw_r = $bw_run( array_merge( $bw_both, array( array( 'wc-set', 'undefined' ) ) ), $bw_m );
	cmp_assert_eq( $bw_r['allow'], false, 'setOrderTracking(undefined): off, not "ask again"' );

	// The classic checkout: what the hidden input holds is what the order carries.
	$bw_r = $bw_run( array_merge( $bw_both, array( array( 'consent', array() ) ) ), array_merge( $bw_m, array( 'cookies' => $bw_jar ) ) );
	cmp_assert( '' === $bw_r['form'] && null === $bw_r['block'], 'classic checkout open, withdrawn: the source leaves the form' );
	$bw_r = $bw_run( array_merge( $bw_both, array( array( 'consent', array( 'marketing' ) ) ) ), array( 'source' => 'utm-x' ) );
	cmp_assert_eq( $bw_r['form'], 'utm-x', 'classic checkout open, accepted: the source reaches the form' );
	$bw_r = $bw_run( array_merge( $bw_both, array( array( 'consent', array( 'marketing' ) ), array( 'consent', array() ), array( 'consent', array( 'marketing' ) ), array( 'consent', array() ) ) ), array( 'source' => 'utm-x' ) );
	cmp_assert_eq( $bw_r['form'], '', 'classic checkout: accept, withdraw, accept, withdraw' );
	foreach ( array( 'at DOMContentLoaded' => array( 'extra', 'before', 'wc', 'after', 'cmp', 'dcl' ), 'after load' => array( 'extra', 'before', 'wc', 'after', 'dcl', 'load', 'cmp', 'script' ) ) as $bw_l => $bw_s ) {
		$bw_r = $bw_run( $bw_s, array_merge( $bw_m, array( 'source' => 'utm-x' ) ) );
		cmp_assert( array( false, true ) === $bw_r['calls'] && 'utm-x' === $bw_r['form'], "classic checkout, banner.js late ({$bw_l}): the form is filled" );
	}
	$bw_f = array( 'forms' => array( 'checkout', 'checkout', 'register', 'login' ), 'source' => 'utm-x' );
	$bw_r = $bw_run( array_merge( $bw_both, array( array( 'consent', array( 'marketing' ) ) ) ), $bw_f );
	cmp_assert( array( 'checkout' => 'utm-x', 'register' => 'utm-x', 'login' => 'utm-x' ) === $bw_r['forms'] && 3 === $bw_r['groups'], 'several forms: each one filled, the duplicate removed by WooCommerce' );
	$bw_r = $bw_run( array_merge( $bw_both, array( array( 'consent', array() ) ) ), array_merge( $bw_f, $bw_m ) );
	cmp_assert_eq( $bw_r['forms'], array( 'checkout' => '', 'register' => '', 'login' => '' ), 'several forms: each one emptied' );
	$bw_r = $bw_run( array_merge( $bw_both, array( array( 'el', 'late' ), array( 'consent', array( 'marketing' ) ), array( 'el', 'later' ), array( 'consent', array() ) ) ), array( 'source' => 'utm-x' ) );
	cmp_assert_eq( $bw_r['forms'], array( 'checkout' => '', 'late' => '', 'later' => '' ), 'elements inserted after the upgrade are emptied too' );
	$bw_r = $bw_run( array_merge( $bw_both, array( array( 'el', 'late' ), array( 'consent', array( 'marketing' ) ) ) ), array( 'forms' => array(), 'source' => 'utm-x' ) );
	cmp_assert_eq( $bw_r['forms'], array( 'late' => 'utm-x' ), 'an element inserted after the upgrade is filled' );
	$bw_r = $bw_run( array_merge( $bw_both, array( array( 'elsewhere', array() ), 'pageshow' ) ), array_merge( $bw_m, array( 'cookies' => $bw_jar ) ) );
	cmp_assert_eq( $bw_r['form'], '', 'classic checkout restored after a withdrawal elsewhere: emptied' );
	$bw_r = $bw_run( array_merge( $bw_both, array( 'no-qsa', array( 'consent', array() ) ) ), array_merge( $bw_m, array( 'cookies' => $bw_jar ) ) );
	cmp_assert( '' === $bw_r['error'] && array( true, false ) === $bw_r['calls'] && array() === $bw_r['cookies'], 'an error while freeing the inputs does not stop setOrderTracking(false)' );
	// The model really shadows: WooCommerce alone does not update the form (so these tests mean something).
	$bw_r = $bw_run( array( 'extra', 'cmp', 'wc', array( 'wc-set', true ) ), array( 'source' => 'utm-x' ) );
	cmp_assert_eq( $bw_r['form'], '', 'model: WooCommerce by itself leaves the classic input as it was stamped' );

	// The cookies after a no. WooCommerce expires .hostname (order-attribution.js:127, 141), sourcebuster
	// writes .hostname without www. (sourcebuster.js:310, 847), so on www hosts they would stay.
	$bw_www = array( 'host' => 'www.example.test' );
	$bw_all = array();
	foreach ( array( 'current', 'current_add', 'first', 'first_add', 'session', 'udata', 'migrations', 'promo' ) as $bw_n ) {
		$bw_all[ "sbjs_{$bw_n}@.example.test" ] = 'v';
	}
	$bw_keep = array( 'cmp_consent@www.example.test' => '1', 'sbjs_other@.example.test' => '1', 'my_sbjs_first@.example.test' => '1', 'sbjs_first_add2@.example.test' => '1' );
	$bw_kept = array_keys( $bw_keep );
	sort( $bw_kept );
	$bw_r = $bw_run( array_merge( $bw_both, array( array( 'consent', array() ), 'visible', 'pageshow', array( 'consent', array() ) ) ), array_merge( $bw_m, $bw_www, array( 'cookies' => $bw_all + $bw_keep ) ) );
	cmp_assert_eq( $bw_r['cookies'], $bw_kept, 'www, withdrawn: the eight cookies are gone, and only they' );
	cmp_assert_eq( count( $bw_r['writes'] ), 8, 'www, withdrawn: each expired once, not again when there is none' );
	cmp_assert_eq( array_values( array_filter( $bw_r['writes'], function ( $w ) {
		return 1 !== preg_match( '/^sbjs_[a-z_]+=; path=\/; max-age=-999; domain=\.example\.test$/', $w );
	} ) ), array(), 'www: only expired, never set' );
	$bw_r = $bw_run( array_merge( $bw_both, $bw_end ), $bw_www + array( 'cookies' => $bw_all ) );
	cmp_assert_eq( $bw_r['cookies'], array(), 'www, refused or not decided, cookies left from before: gone, as WooCommerce does without www' );
	$bw_r = $bw_run( array_merge( $bw_both, $bw_end ), $bw_m + $bw_www + array( 'cookies' => $bw_all ) );
	cmp_assert( array() === $bw_r['writes'] && 8 === count( $bw_r['cookies'] ), 'www, allowed: no cookie is touched' );
	$bw_r = $bw_run( array( 'extra', 'before', 'wc', 'after', 'dcl', 'cmp', 'load' ), $bw_m + $bw_www + array( 'cookies' => $bw_all ) );
	cmp_assert( array() === $bw_r['writes'] && 8 === count( $bw_r['cookies'] ), 'www, allowed, banner.js late: no answer yet is not a no, the source is kept' );
	// Another script's global CMP (no isAllowed) is not our banner: it is no answer, so no cookie is expired and the load listener stays.
	$bw_r = $bw_run( array( 'extra', 'before', 'wc', 'after', 'dcl', 'cmp-foreign', 'load', 'script' ), $bw_m + $bw_www + array( 'cookies' => $bw_all ) );
	cmp_assert( array() === $bw_r['writes'] && 8 === count( $bw_r['cookies'] ), 'a foreign global CMP without isAllowed: the cookies are not expired' );
	cmp_assert_eq( $bw_r['listeners'], 1, 'a foreign global CMP without isAllowed: still listening for the banner script' );
	cmp_assert( false !== strpos( CMP_Bridge_WC::js(), 'typeof w.CMP.isAllowed==="function"' ), 'js: our API is tested as a function, not just the global name' );
	$bw_r = $bw_run( array_merge( $bw_both, array( array( 'consent', array( 'marketing' ) ), array( 'consent', array() ) ) ), $bw_www );
	cmp_assert( array() === $bw_r['cookies'] && array( false, true, false ) === $bw_r['calls'], 'www: accept, then withdraw' );
	$bw_r = $bw_run( array_merge( $bw_both, array( array( 'consent', array() ) ) ), $bw_m + array( 'cookies' => $bw_jar ) );
	cmp_assert_eq( $bw_r['writes'], array(), 'no www: WooCommerce has removed them, nothing is written' );
	$bw_r = $bw_run( array_merge( $bw_both, array( 'wc-throws', array( 'consent', array() ) ) ), $bw_m + $bw_www + array( 'cookies' => $bw_all ) );
	cmp_assert( '' === $bw_r['error'] && array() === $bw_r['cookies'], 'www, WooCommerce throws: the cookies go anyway' );
	$bw_r = $bw_run( array( 'extra', 'before', 'cmp', 'after', array( 'consent', array() ) ), $bw_m + $bw_www + array( 'cookies' => $bw_all ) );
	cmp_assert( array() === $bw_r['calls'] && array() === $bw_r['cookies'], 'www, withdrawn before order-attribution.js came: the cookies go' );
	$bw_r = $bw_run( array_merge( $bw_both, array( 'no-cookie', array( 'consent', array() ) ) ), $bw_m );
	cmp_assert( '' === $bw_r['error'] && array( true, false ) === $bw_r['calls'], 'document.cookie out of reach (sandboxed frame): no error' );
	foreach ( array( 'shop.example.test', 'wwwx.example.test', 'a.www.example.test' ) as $bw_h ) {
		$bw_r = $bw_run( array_merge( $bw_both, array( 'wc-throws', array( 'consent', array() ) ) ), $bw_m + array( 'host' => $bw_h, 'cookies' => array( 'sbjs_first@.example.test' => '1' ) ) );
		cmp_assert( in_array( 'sbjs_first@.example.test', $bw_r['cookies'], true ) && array() !== $bw_r['writes'] && array() === preg_grep( '/domain=\.' . preg_quote( $bw_h, '/' ) . '$/', $bw_r['writes'], PREG_GREP_INVERT ), "{$bw_h}: only its own domain, never the parent's" );
	}
}

// ---- the row and its note ----
$bw_list = array_column( CMP_Known_Plugins::load( __DIR__ . '/../data/plugins.json' ), null, 'file' );
$bw_note = $bw_list['woocommerce/woocommerce.php']['note'];
foreach ( array( 'ro' => array( 'WP Consent API', 'marketing', 'Light', 'sbjs_*' ), 'ru' => array( 'WP Consent API', 'marketing', 'Light', 'sbjs_*' ), 'en' => array( 'WP Consent API', 'marketing', 'Light', 'sbjs_*' ) ) as $bw_l => $bw_words ) {
	foreach ( $bw_words as $bw_w ) {
		cmp_assert( false !== strpos( $bw_note[ $bw_l ], $bw_w ), "note {$bw_l}: says {$bw_w}" );
	}
	cmp_assert( false === strpos( $bw_note[ $bw_l ], "\u{2014}" ), "note {$bw_l}: no em dash" );
}
// CMP_Plugin loads it.
$bw_plugin = (string) file_get_contents( __DIR__ . '/../includes/class-plugin.php' );
cmp_assert( false !== strpos( $bw_plugin, "includes/modules/class-bridge-wc.php" ) && false !== strpos( $bw_plugin, 'CMP_Bridge_WC::register();' ), 'class-plugin.php registers the bridge' );
