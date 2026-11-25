<?php
/**
 * Lot A, Task 2: one request with the PixelYourSite bridge, in its own PHP
 * process (tests/test-bridge-pys.php), so WordPress, the hook table and the
 * PixelYourSite functions can be stubbed. Nothing of PixelYourSite is loaded.
 *
 * argv[1]: JSON { pys: bool, settings, services (cmp_services), cookie (the JSON
 *   value of cmp_consent as the browser sends it, '' = none), forwarded: bool
 *   (the cookie came through PixelYourSite's loopback, slashed once more),
 *   render: bool (PixelYourSite is building page HTML), events: bool (its
 *   EventsManager exists), broken: bool (its functions are gone), categories
 *   (cmp_categories), html: a page to pass through the blocker and the scan,
 *   scan: a page to scan as it is, pcre: bool (a small backtrack limit for it) }
 *
 * The hook table is real (priorities, removal): PixelYourSite's own callbacks sit
 * where it puts them (managePixels at template_redirect 1, its output, its loopback
 * handler at 10, which dies).
 */
namespace PixelYourSite {
	$in = json_decode( isset( $argv[1] ) ? $argv[1] : '{}', true );
	$GLOBALS['in'] = $in;
	class Fake_Events {
		public function outputNoScriptData() { $GLOBALS['printed'][] = 'noscript'; }
		public function outputPbidPrefetch() { $GLOBALS['printed'][] = 'pbid'; }
	}
	class Fake_PYS {
		public $em;
		public function getEventsManager() { return $this->em; }
		/** As managePixels(), at template_redirect 1. */
		public function managePixels() {
			if ( ! empty( $GLOBALS['in']['events'] ) ) {
				$this->em = new Fake_Events();
				add_action( 'wp_head', array( $this->em, 'outputPbidPrefetch' ), 1 );
				add_action( 'wp_footer', array( $this->em, 'outputNoScriptData' ), 10 );
			}
		}
	}
	if ( ! empty( $in['pys'] ) && empty( $in['broken'] ) ) {
		function PYS() {
			static $p = null;
			if ( null === $p ) {
				$p = new Fake_PYS();
			}
			return $p;
		}
		function pys_html_render_context( $set = null ) {
			return ! empty( $GLOBALS['in']['render'] );
		}
	}
}
namespace {
	error_reporting( E_ALL );
	set_error_handler( function ( $no, $msg ) {
		$GLOBALS['notices'][] = $msg;
		return true;
	} );
	$GLOBALS['notices'] = array();
	$GLOBALS['printed'] = array();
	$GLOBALS['hooks']   = array();
	$GLOBALS['removed'] = array();
	define( 'ABSPATH', __DIR__ . '/' );
	$in = $GLOBALS['in'];
	if ( ! empty( $in['pys'] ) ) {
		define( 'PYS_FREE_VERSION', '11.4.7' );
	}
	$GLOBALS['opt'] = array(
		'cmp_settings'   => isset( $in['settings'] ) ? $in['settings'] : array(),
		'cmp_services'   => isset( $in['services'] ) ? $in['services'] : array(),
		'cmp_categories' => isset( $in['categories'] ) ? $in['categories'] : array(
			array( 'slug' => 'necessary', 'required' => true ),
			array( 'slug' => 'statistics' ),
			array( 'slug' => 'marketing' ),
		),
	);
	$_COOKIE = array();
	if ( isset( $in['cookie'] ) && '' !== $in['cookie'] ) {
		// WordPress slashes $_COOKIE; the loopback forwards the slashed value, which is slashed again.
		$_COOKIE['cmp_consent'] = addslashes( ! empty( $in['forwarded'] ) ? addslashes( $in['cookie'] ) : $in['cookie'] );
	}
	function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opt'] ) ? $GLOBALS['opt'][ $k ] : $d; }
	function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
	function add_filter( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['hooks'][ $h ][ $p ][] = array( $cb, $n ); return true; }
	function add_action( $h, $cb, $p = 10, $n = 1 ) { return add_filter( $h, $cb, $p, $n ); }
	function remove_action( $h, $cb, $p = 10 ) {
		foreach ( isset( $GLOBALS['hooks'][ $h ][ $p ] ) ? $GLOBALS['hooks'][ $h ][ $p ] : array() as $i => $c ) {
			if ( $c[0] === $cb ) {
				unset( $GLOBALS['hooks'][ $h ][ $p ][ $i ] );
				$GLOBALS['removed'][] = $h . ':' . $cb[1] . ':' . $p;
			}
		}
		return true;
	}
	function apply_filters( $h, $v, ...$args ) {
		$list = isset( $GLOBALS['hooks'][ $h ] ) ? $GLOBALS['hooks'][ $h ] : array();
		ksort( $list );
		foreach ( $list as $cbs ) {
			foreach ( $cbs as $c ) {
				$v = call_user_func_array( $c[0], array_slice( array_merge( array( $v ), $args ), 0, max( 1, $c[1] ) ) );
			}
		}
		return $v;
	}
	function do_action( $h ) {
		$prios = array_keys( isset( $GLOBALS['hooks'][ $h ] ) ? $GLOBALS['hooks'][ $h ] : array() );
		sort( $prios );
		foreach ( $prios as $p ) {
			foreach ( $GLOBALS['hooks'][ $h ][ $p ] as $c ) {
				call_user_func( $c[0] );
			}
		}
	}
	require dirname( __DIR__ ) . '/../includes/class-consent.php';
	require dirname( __DIR__ ) . '/../includes/functions.php';
	require dirname( __DIR__ ) . '/../includes/class-services.php';
	require dirname( __DIR__ ) . '/../includes/class-known-plugins.php';
	require dirname( __DIR__ ) . '/../includes/modules/class-blocker.php';
	require dirname( __DIR__ ) . '/../includes/modules/class-bridge-pys.php';
	require dirname( __DIR__ ) . '/../includes/modules/class-banner.php';

	CMP_Bridge_PYS::register();
	$ours = array_keys( $GLOBALS['hooks'] );
	if ( ! empty( $in['pys'] ) && empty( $in['broken'] ) ) {
		add_action( 'template_redirect', array( \PixelYourSite\PYS(), 'managePixels' ), 1 );
	}
	// A theme that rebuilds script tags at the default priority (seen for defer / async).
	if ( ! empty( $in['rebuild'] ) ) {
		add_filter( 'script_loader_tag', function ( $t, $h ) {
			return 'pys' === $h ? '<script defer src="/p/public.min.js" id="pys-js"></script>' : $t;
		}, 10, 2 );
	}
	do_action( 'template_redirect' );
	do_action( 'wp_head' );
	do_action( 'wp_footer' );
	// The loopback: PixelYourSite's handler sends at 10, and ends the request there.
	$sent = null;
	add_action( 'admin_post_nopriv_wp_async_pys_send_server_event', function () use ( &$sent ) {
		$sent = ! apply_filters( 'pys_disable_server_event_filter', false );
	} );
	if ( ! empty( $in['forwarded'] ) ) {
		do_action( 'admin_post_nopriv_wp_async_pys_send_server_event' );
	}
	$html = isset( $in['html'] ) ? CMP_Blocker::rewrite( str_replace( '%PYS%', apply_filters( 'script_loader_tag', '<script src="/p/public.min.js" id="pys-js"></script>', 'pys' ), $in['html'] ) ) : '';
	$check = array();
	foreach ( array( 'facebook', 'ga', 'gtm', 'openai', 'pinterest' ) as $p ) {
		$check[ $p ] = apply_filters( 'pys_check_consent_by_gdpr', true, $p );
	}
	function bridge_scan( array $in ) {
		if ( ! empty( $in['pcre'] ) ) {
			ini_set( 'pcre.jit', '0' );
			ini_set( 'pcre.backtrack_limit', '20000' );
		}
		return CMP_Blocker::detect_services( $in['scan'], CMP_Services::active() )['raw'];
	}
	echo json_encode( array(
		'hooks'      => $ours,
		'sent'       => $sent,
		'stub'       => CMP_Banner::consent_default_script( array( 'ad_storage' => 'denied' ), false ),
		'html'       => $html,
		'scan'       => isset( $in['scan'] ) ? bridge_scan( $in ) : array(),
		'raw'        => CMP_Blocker::detect_services( $html, CMP_Services::active() )['raw'],
		'found'      => CMP_Blocker::found_ids( $html, CMP_Services::active() ),
		'disabled'   => apply_filters( 'pys_disable_server_event_filter', false ),
		'disabled1'  => apply_filters( 'pys_disable_server_event_filter', true ),
		'check'      => $check,
		'check_no'   => apply_filters( 'pys_check_consent_by_gdpr', false, 'facebook' ),
		'restricted' => apply_filters( 'pys_pixel_restricted_mode', true, 'openai' ),
		'restr_no'   => apply_filters( 'pys_pixel_restricted_mode', false, 'openai' ),
		'formdata'   => apply_filters( 'pys_disable_advanced_form_data_cookie', false ),
		'formdata1'  => apply_filters( 'pys_disable_advanced_form_data_cookie', true ),
		'session'    => apply_filters( 'pys_skip_session', false ),
		'session1'   => apply_filters( 'pys_skip_session', true ),
		'tag'        => apply_filters( 'script_loader_tag', '<script src="/p/public.min.js" id="pys-js"></script>', 'pys' ),
		'other'      => apply_filters( 'script_loader_tag', '<script src="/j.js" id="jquery-js"></script>', 'jquery' ),
		'bridged'    => CMP_Known_Plugins::bridged(),
		'removed'    => $GLOBALS['removed'],
		'printed'    => $GLOBALS['printed'],
		'notices'    => $GLOBALS['notices'],
	) );
}
