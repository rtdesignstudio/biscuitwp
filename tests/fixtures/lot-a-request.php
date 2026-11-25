<?php
/**
 * Lot A fix wave: one request of the plugin in its own PHP process, with the
 * WordPress calls the new-domain email, the scan, the Servicii actions and the
 * post-update note need (tests/test-lot-a-requests.php).
 *
 * argv[1]: JSON, see $in below. Prints JSON: { log, db, out, result }.
 * The options API is modelled on WordPress: a per-request cache, "notoptions",
 * add_option() that reads through the cache first and then inserts with ON
 * DUPLICATE KEY UPDATE (so a stale "there is no such option" overwrites), and,
 * with persistent = true, a shared object cache behind the per-request copy.
 * No mail, no HTTP request and no database: everything is recorded.
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'CMP_PATH', dirname( __DIR__, 2 ) . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

$in = json_decode( isset( $argv[1] ) ? $argv[1] : '{}', true );
$in = array_merge( array(
	'scenario'   => '',
	'options'    => array(),  // stored options
	'pages'      => array(),  // url => html (missing = 404)
	'mail'       => true,     // what wp_mail returns
	'persistent' => false,
	'locale'     => 'ru_RU',
	'host'       => '',
	'listed'     => null,
	'version'    => '1.2.0',
	'steps'      => array(),
	'page'       => '',
	'no_cron'    => false,
	'get'        => array(), // \$_GET of the notice scenario
), is_array( $in ) ? $in : array() );
define( 'CMP_VERSION', $in['version'] );
if ( $in['no_cron'] ) {
	define( 'DISABLE_WP_CRON', true );
}

$GLOBALS['w'] = array( 'db' => array(), 'run' => array(), 'per' => array(), 'log' => array(), 'locale' => array(), 'transients' => array(), 'autoload' => array() );
foreach ( $in['options'] as $k => $v ) {
	$GLOBALS['w']['db'][ $k ] = $v;
}
$GLOBALS['w_in'] = $in;

function w_log( $entry ) {
	$GLOBALS['w']['log'][] = $entry;
}
/* Another PHP process: the database (and the shared cache) change, this request's own copies do not. */
function w_other_set( $k, $v ) {
	$GLOBALS['w']['db'][ $k ] = $v;
	if ( $GLOBALS['w_in']['persistent'] ) {
		$GLOBALS['w']['per'][ $k ] = $v;
		unset( $GLOBALS['w']['per']['notoptions'][ $k ] );
	}
}
function w_other_delete( $k ) {
	unset( $GLOBALS['w']['db'][ $k ], $GLOBALS['w']['per'][ $k ] );
}
function wp_cache_get( $k, $g = '' ) {
	if ( array_key_exists( $k, $GLOBALS['w']['run'] ) ) {
		return $GLOBALS['w']['run'][ $k ];
	}
	if ( $GLOBALS['w_in']['persistent'] && array_key_exists( $k, $GLOBALS['w']['per'] ) ) {
		$GLOBALS['w']['run'][ $k ] = $GLOBALS['w']['per'][ $k ];
		return $GLOBALS['w']['run'][ $k ];
	}
	return false;
}
function wp_cache_set( $k, $v, $g = '' ) {
	$GLOBALS['w']['run'][ $k ] = $v;
	if ( $GLOBALS['w_in']['persistent'] ) {
		$GLOBALS['w']['per'][ $k ] = $v;
	}
	return true;
}
function wp_cache_delete( $k, $g = '' ) {
	if ( 'options' !== $g ) {
		return false;
	}
	unset( $GLOBALS['w']['run'][ $k ], $GLOBALS['w']['per'][ $k ] );
	return true;
}
function get_option( $k, $d = false ) {
	$not = wp_cache_get( 'notoptions', 'options' );
	if ( is_array( $not ) && isset( $not[ $k ] ) ) {
		return $d;
	}
	$v = wp_cache_get( $k, 'options' );
	if ( false !== $v ) {
		return $v;
	}
	if ( array_key_exists( $k, $GLOBALS['w']['db'] ) ) {
		wp_cache_set( $k, $GLOBALS['w']['db'][ $k ], 'options' );
		return $GLOBALS['w']['db'][ $k ];
	}
	$not       = is_array( $not ) ? $not : array();
	$not[ $k ] = true;
	wp_cache_set( 'notoptions', $not, 'options' );
	return $d;
}
function add_option( $k, $v = '', $dep = '', $autoload = true ) {
	$not = wp_cache_get( 'notoptions', 'options' );
	if ( ( ! is_array( $not ) || ! isset( $not[ $k ] ) ) && false !== get_option( $k ) ) {
		return false;
	}
	$GLOBALS['w']['db'][ $k ]       = $v; // INSERT ... ON DUPLICATE KEY UPDATE
	$GLOBALS['w']['autoload'][ $k ] = $autoload;
	w_log( array( 'add', $k ) );
	$not = wp_cache_get( 'notoptions', 'options' );
	if ( is_array( $not ) && isset( $not[ $k ] ) ) {
		unset( $not[ $k ] );
		wp_cache_set( 'notoptions', $not, 'options' );
	}
	wp_cache_set( $k, $v, 'options' );
	return true;
}
function update_option( $k, $v, $autoload = null ) {
	$old = get_option( $k );
	if ( $old === $v ) {
		return false;
	}
	if ( false === $old ) {
		return add_option( $k, $v, '', null === $autoload ? true : $autoload );
	}
	$GLOBALS['w']['db'][ $k ] = $v;
	if ( null !== $autoload ) {
		$GLOBALS['w']['autoload'][ $k ] = $autoload;
	}
	w_log( array( 'update', $k ) );
	wp_cache_set( $k, $v, 'options' );
	return true;
}
function delete_option( $k ) {
	if ( ! array_key_exists( $k, $GLOBALS['w']['db'] ) ) {
		return false;
	}
	unset( $GLOBALS['w']['db'][ $k ], $GLOBALS['w']['run'][ $k ], $GLOBALS['w']['per'][ $k ] );
	w_log( array( 'delete', $k ) );
	return true;
}
function get_transient( $k ) {
	return array_key_exists( $k, $GLOBALS['w']['transients'] ) ? $GLOBALS['w']['transients'][ $k ] : false;
}
function set_transient( $k, $v, $e = 0 ) {
	$GLOBALS['w']['transients'][ $k ] = $v;
	return true;
}
function delete_transient( $k ) {
	unset( $GLOBALS['w']['transients'][ $k ] );
	return true;
}
function wp_mail( $to, $subject, $body, $headers = array() ) {
	w_log( array( 'mail', $to, $subject, $body, isset( $GLOBALS['w']['db']['cmp_unknown_hosts_lock'] ) ) );
	return $GLOBALS['w_in']['mail'];
}
function get_locale() {
	return $GLOBALS['w_in']['locale'];
}
function switch_to_locale( $l ) {
	w_log( array( 'locale', $l ) );
	return true;
}
function restore_previous_locale() {
	w_log( array( 'restore' ) );
	return true;
}
$GLOBALS['w_hooks'] = array();
function add_action( $h, $cb, $p = 10, $n = 1 ) {
	$GLOBALS['w_hooks'][ $h ][] = $cb;
	return true;
}
function add_filter( $h, $cb, $p = 10, $n = 1 ) {
	return true;
}
function do_action( $h ) {
	$args = array_slice( func_get_args(), 1 );
	w_log( array( 'action', $h, isset( $args[0] ) ? gettype( $args[0] ) : 'none' ) );
	foreach ( isset( $GLOBALS['w_hooks'][ $h ] ) ? $GLOBALS['w_hooks'][ $h ] : array() as $cb ) {
		call_user_func_array( $cb, $args );
	}
}
function wp_next_scheduled( $h ) {
	return false;
}
function wp_schedule_single_event( $t, $h ) {
	w_log( array( 'cron', $h ) );
	return true;
}
function wp_schedule_event( $t, $r, $h ) {
	return true;
}
function home_url( $p = '' ) {
	return 'https://example.md' . $p;
}
function admin_url( $p = '' ) {
	return 'https://example.md/wp-admin/' . $p;
}
function get_post_types() {
	return array();
}
function wp_remote_get( $url, $args = array() ) {
	return isset( $GLOBALS['w_in']['pages'][ $url ] ) ? array( 'code' => 200, 'body' => $GLOBALS['w_in']['pages'][ $url ] ) : array( 'code' => 404, 'body' => '' );
}
function is_wp_error( $x ) {
	return false;
}
function wp_remote_retrieve_response_code( $r ) {
	return $r['code'];
}
function wp_remote_retrieve_body( $r ) {
	return $r['body'];
}
function wp_specialchars_decode( $s, $q = ENT_NOQUOTES ) {
	return strtr( (string) $s, array( '&amp;' => '&', '&lt;' => '<', '&gt;' => '>', '&quot;' => '"', '&#039;' => "'" ) );
}
function current_user_can( $c ) {
	return true;
}
function check_admin_referer( $a ) {
	return 1;
}
function wp_unslash( $v ) {
	return $v;
}
function add_query_arg( $args, $url ) {
	return $url . '?' . http_build_query( $args );
}
function wp_safe_redirect( $u ) {
	w_log( array( 'redirect', $u ) );
	throw new Exception( 'redirect' );
}
function wp_get_referer() {
	return '';
}
function wp_nonce_url( $u, $a ) {
	return $u . '&_wpnonce=x';
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function is_admin() {
	return true;
}
function wp_doing_ajax() {
	return false;
}
function __( $t, $d = null ) {
	return $t;
}
function esc_html( $t ) {
	return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $t ) {
	return esc_html( $t );
}
function esc_url( $t ) {
	return esc_html( $t );
}
function esc_html__( $t, $d = null ) {
	return esc_html( $t );
}
function esc_html_e( $t, $d = null ) {
	echo esc_html( $t );
}
function selected( $a, $b = true ) {
	echo (string) $a === (string) $b ? ' selected' : '';
}
function checked( $a, $b = true ) {
	echo (string) $a === (string) $b ? ' checked' : '';
}
function esc_textarea( $t ) {
	return esc_html( $t );
}
function esc_js( $t ) {
	return esc_html( $t );
}
function esc_attr__( $t, $d = null ) {
	return esc_html( $t );
}
function _n( $a, $b, $n, $d = null ) {
	return 1 === $n ? $a : $b;
}
function human_time_diff( $t ) {
	return '1 min';
}
function wp_nonce_field( $a ) {
}
function submit_button( $t ) {
}
/* The installer needs a database: upgrade_schema() is replaced, the rest of the class stays. */
class CMP_Installer {
	public static function upgrade_schema() {
		return true;
	}
}

require CMP_PATH . 'includes/class-consent.php';
require CMP_PATH . 'includes/class-services.php';
require CMP_PATH . 'includes/class-sanitizer.php';
require CMP_PATH . 'includes/modules/class-blocker.php';
require CMP_PATH . 'includes/modules/class-scanner.php';
require CMP_PATH . 'includes/modules/class-alerts.php';
require CMP_PATH . 'includes/class-known-plugins.php';
require CMP_PATH . 'includes/modules/class-consent-api.php';
require CMP_PATH . 'includes/admin/class-guide.php';
require CMP_PATH . 'includes/admin/class-services-page.php';
CMP_Alerts::register();

$result = null;
$out    = '';
ob_start();
try {
	switch ( $in['scenario'] ) {
		case 'scan': // the whole background scan, as cron runs it
			CMP_Scanner::enqueue_all();
			for ( $i = 0; $i < 10 && 'running' === CMP_Scanner::state()['status']; $i++ ) {
				CMP_Scanner::run_batch();
			}
			$result = CMP_Scanner::state()['status'];
			break;
		case 'inline': // DISABLE_WP_CRON: the admin screen runs it, without the sitemap
			CMP_Scanner::enqueue_all();
			for ( $i = 0; $i < 10 && 'running' === CMP_Scanner::state()['status']; $i++ ) {
				CMP_Scanner::maybe_run_inline();
			}
			$result = CMP_Scanner::state()['status'];
			break;
		case 'fire': // only the listener, with a ready result
			CMP_Alerts::state(); // cached by this request, then changed by another one
			foreach ( $in['steps'] as $s ) {
				w_other_set( $s[1], $s[2] );
			}
			CMP_Alerts::on_scan_finished( $in['agg'] );
			break;
		case 'change': // ignore / unignore with a state already read in this request
			CMP_Alerts::state();
			if ( ! empty( $in['read_lock'] ) ) {
				get_option( CMP_Alerts::LOCK_OPTION, 0 );
			}
			foreach ( $in['steps'] as $s ) {
				if ( 'set' === $s[0] ) {
					w_other_set( $s[1], $s[2] );
				} else {
					w_other_delete( $s[1] );
				}
			}
			$t0     = microtime( true );
			$result = array( CMP_Alerts::change_wp( $in['op'], $in['host'] ), microtime( true ) - $t0 < 0.5 );
			break;
		case 'ignore':
		case 'unignore':
			$_POST['host'] = $in['host'];
			call_user_func( array( 'CMP_Services_Page', $in['scenario'] . '_domain' ) );
			break;
		case 'upgrade':
			// maybe_upgrade() requires the real installer file: keep the stub above.
			$src = (string) file_get_contents( CMP_PATH . 'includes/class-plugin.php' );
			$src = str_replace( "require_once CMP_PATH . 'includes/class-installer.php';", '', $src );
			$tmp = tempnam( sys_get_temp_dir(), 'cmpup' );
			file_put_contents( $tmp, $src );
			require $tmp;
			unlink( $tmp );
			CMP_Plugin::maybe_upgrade();
			break;
		case 'notice':
			$GLOBALS['w']['transients']['cmp_tracker_scan'] = isset( $in['scan'] ) ? $in['scan'] : false;
			$_GET = $in['get'];
			if ( 'services' === $in['page'] ) { // the Servicii screen itself
				$_GET['page'] = 'cmp-services';
				CMP_Services_Page::update_notice();
				CMP_Services_Page::render();
			} elseif ( 'dismiss' === $in['page'] ) {
				CMP_Services_Page::dismiss();
			} else {
				CMP_Services_Page::update_notice();
			}
			break;
		case 'row': // one library row of the Servicii screen
			foreach ( CMP_Services::active() as $s ) {
				if ( $s['id'] === $in['host'] ) {
					$m = new ReflectionMethod( 'CMP_Services_Page', 'library_row' );
					if ( PHP_VERSION_ID < 80100 ) {
						$m->setAccessible( true );
					}
					$m->invoke( null, $s, array( 'necessary' => 'Necesare', 'statistics' => 'Statistici', 'marketing' => 'Marketing' ), ! empty( $in['found'] ) );
				}
			}
			break;
		case 'gate_state': // Maps gate: the active service and the re-consent map of the front end
			$svc = array();
			foreach ( CMP_Services::active() as $s ) {
				if ( 'google-maps-js' === $s['id'] ) {
					$svc = array_intersect_key( $s, array_flip( array( 'exempt', 'gate_off', 'admin_exempt', 'mode_exempt', 'own_exempt' ) ) );
				}
			}
			$result = array( 'svc' => $svc, 'by_cat' => CMP_Services::current_svc_by_cat( CMP_Consent::get_categories() ) );
			break;
		case 'hints': // Maps gate: the resource hints WordPress prints
			$result = CMP_Blocker::filter_hints( array( 'https://maps.googleapis.com', array( 'href' => '//maps.gstatic.com' ), 'https://www.googletagmanager.com' ), 'preconnect' );
			break;
		case 'apply': // Maps gate: a whole page through the output buffer (get: cmp_check = an isolated admin session)
			require CMP_PATH . 'includes/modules/class-lang.php';
			if ( isset( $in['get']['cmp_check'] ) ) {
				$modes = new ReflectionProperty( 'CMP_Scanner', 'modes' );
				$modes->setAccessible( true );
				$modes->setValue( null, array( 'cmp_check' => true ) );
			}
			$result = CMP_Blocker::apply( $in['page'] );
			break;
		case 'gate_save': // Maps gate: the Servicii save with the switch
			$_POST['cmp_services'] = $in['post'];
			CMP_Services_Page::save();
			break;
		case 'maps_notice': // Maps gate: the one-time note
			$GLOBALS['w']['transients']['cmp_tracker_scan'] = isset( $in['scan'] ) ? $in['scan'] : false;
			$_GET = $in['get'];
			if ( 'services' === $in['page'] ) {
				$_GET['page'] = 'cmp-services';
				CMP_Services_Page::maps_notice();
				CMP_Services_Page::render();
			} elseif ( 'dismiss' === $in['page'] ) {
				CMP_Services_Page::dismiss_maps();
			} else {
				CMP_Services_Page::maps_notice();
			}
			break;
	}
} catch ( Exception $e ) {
	if ( 'redirect' !== $e->getMessage() ) {
		$result = 'EXCEPTION ' . $e->getMessage();
	}
}
$out = ob_get_clean();
echo json_encode( array( 'log' => $GLOBALS['w']['log'], 'db' => $GLOBALS['w']['db'], 'autoload' => $GLOBALS['w']['autoload'], 'out' => $out, 'result' => $result ) );
