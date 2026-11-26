<?php
/**
 * #727 step 4: one wp_ajax_cmp_preview_render request (CMP_Preview::ajax_render())
 * in its own PHP process (tests/test-preview-render.php).
 *
 * argv[1]: JSON { cap, post, settings, categories, site_locale, user_locale }
 * Prints JSON: { status, body, transients, options, cookies }.
 * The stubbed AJAX nonce: 'good-render' is valid for cmp_preview_render only.
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'CMP_URL', 'http://example.test/wp-content/plugins/biscuitwp/' );
define( 'CMP_PATH', dirname( __DIR__, 2 ) . '/' );

$in = json_decode( isset( $argv[1] ) ? $argv[1] : '{}', true );
$GLOBALS['pr'] = array(
	'cap'        => ! empty( $in['cap'] ),
	'settings'   => isset( $in['settings'] ) ? $in['settings'] : array(),
	'categories' => isset( $in['categories'] ) ? $in['categories'] : array(),
	'site'       => isset( $in['site_locale'] ) ? $in['site_locale'] : 'ro_RO',
	'user'       => isset( $in['user_locale'] ) ? $in['user_locale'] : 'ro_RO',
	'transients' => array(),
	'options'    => array(),
	'cookies'    => array(),
	'filters'    => array(),
	'registered' => array(),
	'status'     => null,
	'body'       => null,
);
$_POST = isset( $in['post'] ) ? $in['post'] : array();

function cmp_pr_done() {
	$p = $GLOBALS['pr'];
	echo json_encode( array( 'status' => $p['status'], 'body' => $p['body'], 'transients' => $p['transients'], 'options' => $p['options'], 'cookies' => $p['cookies'], 'registered' => $p['registered'] ) );
	exit;
}
function current_user_can( $c ) { return 'manage_options' === $c && $GLOBALS['pr']['cap']; }
function check_ajax_referer( $action, $key, $die = true ) {
	return 'cmp_preview_render' === $action && isset( $_POST[ $key ] ) && 'good-render' === $_POST[ $key ] ? 1 : false;
}
function wp_send_json_error( $data = null, $status = null ) {
	$GLOBALS['pr']['status'] = $status ? $status : 200;
	$GLOBALS['pr']['body']   = array( 'success' => false, 'data' => $data );
	cmp_pr_done();
}
function wp_send_json_success( $data = null ) {
	$GLOBALS['pr']['status'] = 200;
	$GLOBALS['pr']['body']   = array( 'success' => true, 'data' => $data );
	cmp_pr_done();
}
function wp_unslash( $v ) { return $v; }
function get_option( $k, $d = false ) {
	if ( 'cmp_settings' === $k ) { return $GLOBALS['pr']['settings']; }
	if ( 'cmp_categories' === $k ) { return $GLOBALS['pr']['categories']; }
	return $d;
}
function update_option( $k, $v ) { $GLOBALS['pr']['options'][] = $k; return true; }
function add_option( $k, $v = '' ) { $GLOBALS['pr']['options'][] = $k; return true; }
function set_transient( $k, $v, $ttl ) { $GLOBALS['pr']['transients'][] = $k; return true; }
function add_filter( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['pr']['filters'][ $h ][] = $cb; return true; }
function remove_filter( $h, $cb, $p = 10 ) {
	if ( isset( $GLOBALS['pr']['filters'][ $h ] ) ) {
		$GLOBALS['pr']['filters'][ $h ] = array_values( array_filter( $GLOBALS['pr']['filters'][ $h ], function ( $f ) use ( $cb ) { return $f !== $cb; } ) );
	}
	return true;
}
function apply_filters( $h, $v ) {
	foreach ( isset( $GLOBALS['pr']['filters'][ $h ] ) ? $GLOBALS['pr']['filters'][ $h ] : array() as $cb ) { $v = call_user_func( $cb, $v ); }
	return $v;
}
function get_locale() { return $GLOBALS['pr']['site']; }
// As WordPress in an admin (AJAX) request: the user's locale, unless filtered.
function determine_locale() { return apply_filters( 'determine_locale', $GLOBALS['pr']['user'] ); }
function get_current_user_id() { return 1; }
function home_url( $p = '' ) { return 'http://example.test' . $p; }
function site_url( $p = '' ) { return 'http://example.test' . $p; }
function is_ssl() { return false; }
function get_post_status( $id ) { return false; }
function get_permalink( $id ) { return ''; }
function get_bloginfo( $k = '' ) { return 'Exemplu'; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_textarea_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function wp_strip_all_tags( $v ) { return trim( strip_tags( (string) $v ) ); }
function __( $t, $d = null ) { return $t; }
function add_query_arg( $k, $v, $u ) { return $u . ( false === strpos( $u, '?' ) ? '?' : '&' ) . $k . '=' . $v; }
function esc_html__( $t, $d = null ) { return esc_html( $t ); }
function esc_attr__( $t, $d = null ) { return esc_attr( $t ); }

// WPML String Translation: registering a string writes to the database.
if ( ! empty( $in['wpml'] ) ) {
	function icl_register_string( $c, $n, $v ) { $GLOBALS['pr']['registered'][] = $n; }
	function icl_t( $c, $n, $v ) { return $v; }
}
$root = dirname( __DIR__, 2 );
foreach ( array( 'class-assets.php', 'class-consent.php', 'class-contrast.php', 'class-appearance.php', 'class-sanitizer.php', 'modules/class-lang.php', 'modules/class-i18n.php', 'modules/class-scanner.php', 'modules/class-banner.php', 'modules/class-preview.php' ) as $f ) {
	require_once $root . '/includes/' . $f;
}
if ( ! class_exists( 'CMP_Plugin' ) ) {
	class CMP_Plugin {
		public static function asset_ver( $p ) { return 'v1'; }
	}
}

CMP_Preview::ajax_render();
echo json_encode( array( 'status' => 'no answer' ) );
