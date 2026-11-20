<?php
/**
 * B6 Task 6: one front-end request with ?cmp_preview / ?cmp_check, run in its
 * own PHP process (tests/test-preview.php), so the per-request memo of
 * CMP_Scanner::token_mode() and the WordPress stubs stay isolated.
 *
 * argv[1]: JSON { get: {...}, cap: bool, user: int, transients: {...},
 *                 settings: {...}, cookies: {...}, uri: string,
 *                 admin: bool, ajax: bool }
 * Prints JSON: what the plugin decides for that request.
 *
 * Stubbed nonces: 'good-preview' is valid for cmp_preview only, 'good-check'
 * for cmp_check only; anything else is invalid. The preview nonce is read
 * from the cmp_preview_t cookie (with the marker ?cmp_preview=1).
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'YEAR_IN_SECONDS', 31536000 );

$in = json_decode( isset( $argv[1] ) ? $argv[1] : '{}', true );
$GLOBALS['pr'] = array(
	'cap'        => ! empty( $in['cap'] ),
	'user'       => isset( $in['user'] ) ? (int) $in['user'] : 1,
	'transients' => isset( $in['transients'] ) ? $in['transients'] : array(),
	'settings'   => isset( $in['settings'] ) ? $in['settings'] : array(),
	'writes'     => array(),
	'nocache'    => 0,
	'nonce_args' => array(),
	'admin'      => ! empty( $in['admin'] ),
	'ajax'       => ! empty( $in['ajax'] ),
);
$_GET             = isset( $in['get'] ) ? $in['get'] : array();
$_COOKIE          = isset( $in['cookies'] ) ? $in['cookies'] : array();
$_SERVER['REQUEST_URI'] = isset( $in['uri'] ) ? $in['uri'] : '/';

function did_action( $a ) {
	return in_array( $a, array( 'init', 'parse_request' ), true ) ? 1 : 0;
}
function wp_unslash( $v ) {
	return $v;
}
function sanitize_text_field( $v ) {
	return trim( (string) $v );
}
function is_admin() {
	return $GLOBALS['pr']['admin'];
}
function wp_doing_ajax() {
	return $GLOBALS['pr']['ajax'];
}
function wp_verify_nonce( $t, $a ) {
	$GLOBALS['pr']['nonce_args'][] = array( $t, $a );
	return ( 'good-preview' === $t && 'cmp_preview' === $a ) || ( 'good-check' === $t && 'cmp_check' === $a ) ? 1 : false;
}
function current_user_can( $c ) {
	return 'manage_options' === $c && $GLOBALS['pr']['cap'];
}
function get_current_user_id() {
	return $GLOBALS['pr']['user'];
}
function get_transient( $k ) {
	return array_key_exists( $k, $GLOBALS['pr']['transients'] ) ? $GLOBALS['pr']['transients'][ $k ] : false;
}
function set_transient( $k, $v, $ttl ) {
	$GLOBALS['pr']['writes'][] = array( 'transient', $k );
	return true;
}
function get_option( $k, $d = false ) {
	return 'cmp_settings' === $k ? $GLOBALS['pr']['settings'] : $d;
}
function update_option( $k, $v ) {
	$GLOBALS['pr']['writes'][] = array( 'option', $k );
	return true;
}
function wp_parse_url( $u, $c = -1 ) {
	return parse_url( $u, $c );
}
function home_url( $p = '' ) {
	return 'http://example.test' . $p;
}
function nocache_headers() {
	$GLOBALS['pr']['nocache']++;
}
function __( $t, $d = null ) {
	return $t;
}
function esc_attr( $t ) {
	return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $t ) {
	return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
}
function esc_attr__( $t, $d = null ) {
	return esc_attr( $t );
}
function esc_html__( $t, $d = null ) {
	return esc_attr( $t );
}

$root = dirname( __DIR__, 2 );
require $root . '/includes/class-consent.php';
require $root . '/includes/class-contrast.php';
require $root . '/includes/class-appearance.php';
require $root . '/includes/class-sanitizer.php';
require $root . '/includes/modules/class-scanner.php';
require $root . '/includes/modules/class-banner.php';
require $root . '/includes/modules/class-preview.php';

$base = array( 'version' => '3', 'cookieName' => 'cmp_consent', 'reloadOnDecision' => true, 'cookieDays' => 180 );

$out = array(
	'preview'    => CMP_Preview::active(),
	'check'      => CMP_Scanner::check_mode(),
	'isolated'   => CMP_Scanner::isolated(),
	'cookieName' => CMP_Consent::cookie_name(),
	// The CMP_DATA overlay exactly as CMP_Banner::enqueue() applies it.
	'data'       => CMP_Scanner::check_data( $base, CMP_Scanner::isolated() ),
	'adminBar'   => CMP_Scanner::check_admin_bar( true ),
	'settings'   => CMP_Banner::settings(),
	'excludedRule' => CMP_Banner::excluded_now( array( 'exclude' => array( '/landing/*' ) ), CMP_Scanner::isolated() ),
);
ob_start();
CMP_Preview::print_bar();
CMP_Preview::print_bar();
$out['bar'] = ob_get_clean();
CMP_Scanner::check_page_setup();
$out['nocache']    = $GLOBALS['pr']['nocache'];
$out['donotcache'] = defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE;
$out['cookies']    = $_COOKIE;
$out['writes']     = $GLOBALS['pr']['writes'];
$out['nonceCalls'] = count( $GLOBALS['pr']['nonce_args'] );
echo json_encode( $out );
