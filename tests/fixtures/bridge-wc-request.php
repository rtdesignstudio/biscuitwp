<?php
/**
 * Lot A, Task 3: one request with the WooCommerce bridge, in its own PHP process
 * (tests/test-bridge-wc.php). WooCommerce is a small model of its 11.3.1 hooks
 * (OrderAttributionController.php:103-142, 271-342; WPConsentAPI.php:32-74);
 * nothing of it is loaded.
 *
 * argv[1]: JSON { wc: bool, feature: bool (order attribution on in WooCommerce),
 *   old: bool (no FeaturesUtil), api: bool (the WP_CONSENT_API class), fn: bool
 *   (wp_set_consent), api_init: bool (that class appears at init 5), settings,
 *   categories, site: a value the site's own filter returns, site_prio, dequeue:
 *   bool, cookie }
 */
namespace Automattic\WooCommerce\Utilities {
	$GLOBALS['in'] = json_decode( isset( $argv[1] ) ? $argv[1] : '{}', true );
	if ( ! empty( $GLOBALS['in']['wc'] ) && empty( $GLOBALS['in']['old'] ) ) {
		class FeaturesUtil {
			public static function feature_is_enabled( string $id ): bool {
				return 'order_attribution' === $id && ! isset( $GLOBALS['in']['feature'] ) || ! empty( $GLOBALS['in']['feature'] );
			}
		}
	}
}
namespace {
	error_reporting( E_ALL );
	$GLOBALS['notices'] = array();
	set_error_handler( function ( $no, $msg ) {
		$GLOBALS['notices'][] = $msg;
		return true;
	} );
	define( 'ABSPATH', __DIR__ . '/' );
	$in = $GLOBALS['in'];
	$GLOBALS['hooks']   = array();
	$GLOBALS['scripts'] = array(); // handle => array( src, extra, before, after, queued )
	$GLOBALS['touched'] = array(); // script functions called by the bridge
	$GLOBALS['opt']     = array(
		'cmp_settings'   => isset( $in['settings'] ) ? $in['settings'] : array(),
		'cmp_services'   => array(),
		'cmp_categories' => isset( $in['categories'] ) ? $in['categories'] : array(
			array( 'slug' => 'necessary', 'required' => true ),
			array( 'slug' => 'statistics' ),
			array( 'slug' => 'marketing' ),
		),
	);
	$_COOKIE = array();
	if ( ! empty( $in['cookie'] ) ) {
		$_COOKIE['cmp_consent'] = addslashes( $in['cookie'] );
	}
	function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opt'] ) ? $GLOBALS['opt'][ $k ] : $d; }
	function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
	function add_filter( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['hooks'][ $h ][ $p ][] = array( $cb, $n ); return true; }
	function add_action( $h, $cb, $p = 10, $n = 1 ) { return add_filter( $h, $cb, $p, $n ); }
	function apply_filters( $h, $v, ...$args ) {
		$done = array();
		while ( true ) { // a callback added while the hook runs is called too (as WP_Hook does)
			$list = isset( $GLOBALS['hooks'][ $h ] ) ? $GLOBALS['hooks'][ $h ] : array();
			ksort( $list );
			$next = null;
			foreach ( $list as $p => $cbs ) {
				foreach ( $cbs as $i => $c ) {
					if ( ! isset( $done[ "$p-$i" ] ) ) {
						$next = array( "$p-$i", $c );
						break 2;
					}
				}
			}
			if ( null === $next ) {
				return $v;
			}
			$done[ $next[0] ] = true;
			$v = call_user_func_array( $next[1][0], array_slice( array_merge( array( $v ), $args ), 0, max( 1, $next[1][1] ) ) );
		}
	}
	function do_action( $h ) { apply_filters( $h, null ); }
	function wc_string_to_bool( $s ) { return is_bool( $s ) ? $s : in_array( strtolower( (string) $s ), array( 'yes', 'true', '1' ), true ); }
	function wc_bool_to_string( $b ) { return true === wc_string_to_bool( $b ) ? 'yes' : 'no'; }
	function wp_enqueue_script( $h, $src = '', $deps = array(), $v = false, $f = false ) {
		$GLOBALS['scripts'][ $h ] = array( 'src' => $src, 'extra' => '', 'before' => '', 'after' => '', 'queued' => true );
	}
	function wp_localize_script( $h, $n, $d ) { $GLOBALS['scripts'][ $h ]['extra'] = 'var ' . $n . ' = ' . json_encode( $d ) . ';'; }
	function wp_add_inline_script( $h, $js, $pos = 'after' ) {
		if ( ! isset( $GLOBALS['scripts'][ $h ] ) ) {
			return false; // WP_Dependencies::add_data(): not registered
		}
		$GLOBALS['scripts'][ $h ][ $pos ] .= $js;
		$GLOBALS['touched'][] = "inline:{$h}:{$pos}";
		return true;
	}
	function wp_dequeue_script( $h ) { $GLOBALS['touched'][] = "dequeue:{$h}"; }
	function wp_deregister_script( $h ) { $GLOBALS['touched'][] = "deregister:{$h}"; }
	function wp_script_add_data( $h, $k, $v ) { $GLOBALS['touched'][] = "data:{$h}"; return true; }
	if ( ! empty( $in['fn'] ) ) {
		function wp_set_consent() {}
	}
	if ( ! empty( $in['api'] ) ) {
		class WP_CONSENT_API {}
	}
	if ( ! empty( $in['wc'] ) ) {
		class WooCommerce {}
	}
	$root = dirname( __DIR__ ) . '/../includes/';
	foreach ( array( 'class-consent.php', 'functions.php', 'class-services.php', 'class-known-plugins.php', 'modules/class-blocker.php', 'modules/class-bridge-wc.php' ) as $f ) {
		require $root . $f;
	}

	CMP_Bridge_WC::register();
	// WooCommerce: OrderAttributionController at init 10, WPConsentAPI at init 20.
	if ( ! empty( $in['api_init'] ) ) {
		add_action( 'init', function () { eval( 'class WP_CONSENT_API {}' ); }, 5 );
	}
	if ( ! empty( $in['wc'] ) ) {
		add_action( 'init', function () use ( $in ) {
			if ( isset( $in['feature'] ) && ! $in['feature'] ) {
				return;
			}
			add_action( 'init', function () {
				if ( class_exists( 'WP_CONSENT_API' ) ) {
					add_filter( 'wc_order_attribution_allow_tracking', function () { return 'consent-api'; } );
				}
			}, 20 );
			add_action( 'wp_enqueue_scripts', function () {
				wp_enqueue_script( 'sourcebuster-js', '/wc/sourcebuster.min.js', array(), '1', true );
				wp_enqueue_script( 'wc-order-attribution', '/wc/order-attribution.min.js', array( 'sourcebuster-js' ), '1', true );
				$GLOBALS['raw_allow'] = apply_filters( 'wc_order_attribution_allow_tracking', true );
				$allow = 'consent-api' === $GLOBALS['raw_allow'] ? 'yes' : wc_bool_to_string( $GLOBALS['raw_allow'] );
				wp_localize_script( 'wc-order-attribution', 'wc_order_attribution', array( 'params' => array( 'allowTracking' => 'yes' === $allow ) ) );
			} );
			add_action( 'wp_enqueue_scripts', function () {
				wp_enqueue_script( 'wc-cart-fragments', '/wc/cart-fragments.min.js' );
				wp_enqueue_script( 'wc-checkout', '/wc/checkout.min.js' );
			} );
		} );
	}
	if ( array_key_exists( 'site', $in ) ) {
		add_filter( 'wc_order_attribution_allow_tracking', function () use ( $in ) { return $in['site']; }, isset( $in['site_prio'] ) ? $in['site_prio'] : 10 );
	}
	do_action( 'init' );
	do_action( 'wp_enqueue_scripts' );
	$html = '';
	foreach ( $GLOBALS['scripts'] as $h => $s ) {
		foreach ( array( 'extra', 'before' ) as $p ) {
			$html .= '' === $s[ $p ] ? '' : "<script id=\"{$h}-js-{$p}\">\n{$s[ $p ]}\n</script>\n";
		}
		$html .= "<script src=\"{$s['src']}\" id=\"{$h}-js\"></script>\n";
		$html .= '' === $s['after'] ? '' : "<script id=\"{$h}-js-after\">\n{$s['after']}\n</script>\n";
	}
	$ours = array();
	foreach ( $GLOBALS['hooks'] as $h => $ps ) {
		foreach ( $ps as $cbs ) {
			foreach ( $cbs as $c ) {
				if ( is_array( $c[0] ) && 'CMP_Bridge_WC' === $c[0][0] ) {
					$ours[] = $h;
				}
			}
		}
	}
	echo json_encode( array(
		'ours'      => $ours,
		'raw_allow' => isset( $GLOBALS['raw_allow'] ) ? $GLOBALS['raw_allow'] : null,
		'touched'   => $GLOBALS['touched'],
		'scripts'   => $GLOBALS['scripts'],
		'html'      => $html,
		'rewritten' => CMP_Blocker::rewrite( $html ),
		'bridged'   => CMP_Known_Plugins::bridged(),
		'notices'   => $GLOBALS['notices'],
	) );
}
