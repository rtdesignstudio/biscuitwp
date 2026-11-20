<?php
/**
 * B6 Task 6 (fix round 1, M3): one wp_ajax_cmp_preview_store request
 * (CMP_Preview::ajax_store()) in its own PHP process (tests/test-preview.php).
 *
 * argv[1]: JSON { cap: bool, user: int, post: {...}, settings: {...} }
 * Prints JSON: { status, body, transients (written), options (written), nonces (created) }.
 * The stubbed AJAX nonce: 'good-ajax' is valid for cmp_preview_store only.
 */
define( 'ABSPATH', __DIR__ . '/' );

$in = json_decode( isset( $argv[1] ) ? $argv[1] : '{}', true );
$GLOBALS['pa'] = array(
	'cap'        => ! empty( $in['cap'] ),
	'user'       => isset( $in['user'] ) ? (int) $in['user'] : 1,
	'settings'   => isset( $in['settings'] ) ? $in['settings'] : array(),
	'transients' => array(),
	'options'    => array(),
	'nonces'     => array(),
	'status'     => null,
	'body'       => null,
);
$_POST = isset( $in['post'] ) ? $in['post'] : array();

function cmp_pa_done() {
	echo json_encode(
		array(
			'status'     => $GLOBALS['pa']['status'],
			'body'       => $GLOBALS['pa']['body'],
			'transients' => $GLOBALS['pa']['transients'],
			'options'    => $GLOBALS['pa']['options'],
			'nonces'     => $GLOBALS['pa']['nonces'],
		)
	);
	exit;
}
function current_user_can( $c ) {
	return 'manage_options' === $c && $GLOBALS['pa']['cap'];
}
function check_ajax_referer( $action, $key, $die = true ) {
	return 'cmp_preview_store' === $action && isset( $_POST[ $key ] ) && 'good-ajax' === $_POST[ $key ] ? 1 : false;
}
function wp_send_json_error( $data = null, $status = null ) {
	$GLOBALS['pa']['status'] = $status ? $status : 200;
	$GLOBALS['pa']['body']   = array( 'success' => false, 'data' => $data );
	cmp_pa_done();
}
function wp_send_json_success( $data = null ) {
	$GLOBALS['pa']['status'] = 200;
	$GLOBALS['pa']['body']   = array( 'success' => true, 'data' => $data );
	cmp_pa_done();
}
function wp_unslash( $v ) {
	return $v;
}
function get_option( $k, $d = false ) {
	return 'cmp_settings' === $k ? $GLOBALS['pa']['settings'] : $d;
}
function update_option( $k, $v ) {
	$GLOBALS['pa']['options'][] = $k;
	return true;
}
function set_transient( $k, $v, $ttl ) {
	$GLOBALS['pa']['transients'][ $k ] = array( 'value' => $v, 'ttl' => $ttl );
	return true;
}
function get_current_user_id() {
	return $GLOBALS['pa']['user'];
}
function wp_create_nonce( $a ) {
	$GLOBALS['pa']['nonces'][] = $a;
	return 'nonce-' . $a;
}
function home_url( $p = '' ) {
	return 'http://example.test' . $p;
}
function is_ssl() {
	return false;
}
function sanitize_text_field( $v ) {
	return trim( strip_tags( (string) $v ) );
}
function sanitize_textarea_field( $v ) {
	return trim( strip_tags( (string) $v ) );
}
function wp_strip_all_tags( $v ) {
	return trim( strip_tags( (string) $v ) );
}
function __( $t, $d = null ) {
	return $t;
}

$root = dirname( __DIR__, 2 );
require $root . '/includes/class-consent.php';
require $root . '/includes/class-contrast.php';
require $root . '/includes/class-appearance.php';
require $root . '/includes/class-sanitizer.php';
require $root . '/includes/modules/class-scanner.php';
require $root . '/includes/modules/class-banner.php';
require $root . '/includes/modules/class-preview.php';

CMP_Preview::ajax_store();
echo json_encode( array( 'status' => 'no answer' ) );
