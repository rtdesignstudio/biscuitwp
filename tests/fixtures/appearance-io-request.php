<?php
/**
 * Lot A Task 5: one request to the Aspect screen / the export action, run in
 * its own PHP process (tests/test-appearance-io.php).
 *
 * argv[1]: JSON { scenario: get|import|export, cap: bool, nonce: bool, ml: bool,
 *                 settings: {...}, file: string|null, upload_error: int }
 * Prints JSON: { html, writes, died, uploaded_checks, body, filename, nocache, exited }.
 * Every write to the database (options, transients, meta) is recorded in "writes".
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'CMP_VERSION', '1.1.1' );

$in = json_decode( isset( $argv[1] ) ? $argv[1] : '{}', true );
$GLOBALS['io'] = array(
	'cap'      => ! empty( $in['cap'] ),
	'nonce'    => ! empty( $in['nonce'] ),
	'settings' => isset( $in['settings'] ) ? $in['settings'] : array(),
	'writes'   => array(),
	'died'     => false,
	'uploads'  => 0,
	'nocache'  => 0,
	'headers'  => array(),
);
if ( ! empty( $in['ml'] ) ) {
	function icl_t() {
		return '';
	}
}

foreach ( array( 'update_option', 'add_option', 'delete_option', 'set_transient', 'delete_transient', 'update_user_meta', 'update_site_option' ) as $fn ) {
	eval( "function $fn() { \$GLOBALS['io']['writes'][] = array( '$fn', func_get_args() ); return true; }" );
}
function get_option( $k, $d = false ) {
	return 'cmp_settings' === $k ? $GLOBALS['io']['settings'] : $d;
}
function get_transient( $k ) {
	return false;
}
function get_current_user_id() {
	return 7;
}
function current_user_can( $c ) {
	return 'manage_options' === $c && $GLOBALS['io']['cap'];
}
function check_admin_referer( $a ) {
	if ( ! $GLOBALS['io']['nonce'] ) {
		wp_die( 'nonce' );
	}
	return 1;
}
function wp_die( $m = '' ) {
	$GLOBALS['io']['died'] = true;
	throw new Exception( 'wp_die' );
}
function wp_unslash( $v ) {
	return $v;
}
function nocache_headers() {
	$GLOBALS['io']['nocache']++;
}
function home_url( $p = '' ) {
	return 'https://example.test' . $p;
}
function wp_parse_url( $u, $c = -1 ) {
	return parse_url( $u, $c );
}
function admin_url( $p = '' ) {
	return 'https://example.test/wp-admin/' . $p;
}
function wp_create_nonce( $a = -1 ) {
	return 'nonce-' . $a;
}
function wp_nonce_field( $a = -1 ) {
	echo '<input type="hidden" name="_wpnonce" value="x">';
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
function esc_textarea( $t ) {
	return esc_html( $t );
}
function esc_html__( $t, $d = null ) {
	return esc_html( $t );
}
function esc_attr__( $t, $d = null ) {
	return esc_html( $t );
}
function esc_html_e( $t, $d = null ) {
	echo esc_html( $t );
}
function wp_kses_post( $t ) {
	return strip_tags( (string) $t, '<a><code><strong><em><br>' );
}
function do_action() {
	return null;
}
function checked( $a, $b = true, $echo = true ) {
	$r = (string) $a === (string) $b ? ' checked=\'checked\'' : '';
	if ( $echo ) {
		echo $r;
	}
	return $r;
}
function selected( $a, $b = true, $echo = true ) {
	$r = (string) $a === (string) $b ? ' selected=\'selected\'' : '';
	if ( $echo ) {
		echo $r;
	}
	return $r;
}
function disabled( $a, $b = true, $echo = true ) {
	$r = (string) $a === (string) $b ? ' disabled=\'disabled\'' : '';
	if ( $echo ) {
		echo $r;
	}
	return $r;
}

$root = dirname( __DIR__, 2 );
require $root . '/includes/class-consent.php';
require $root . '/includes/class-contrast.php';
require $root . '/includes/class-appearance.php';
require $root . '/includes/class-sanitizer.php';
require $root . '/includes/class-appearance-io.php';
require $root . '/includes/modules/class-scanner.php';
require $root . '/includes/modules/class-banner.php';
require $root . '/includes/modules/class-lang.php';
require $root . '/includes/modules/class-preview.php';
require $root . '/includes/admin/class-guide.php';
require $root . '/includes/admin/class-appearance-page.php';

CMP_Appearance_Page::$upload_check = function ( $tmp ) {
	$GLOBALS['io']['uploads']++;
	return true;
};
CMP_Appearance_Page::$header_fn = function ( $h ) {
	$GLOBALS['io']['headers'][] = $h;
};

$tmp = tempnam( sys_get_temp_dir(), 'cmpio' );
if ( isset( $in['file'] ) && is_string( $in['file'] ) ) {
	file_put_contents( $tmp, $in['file'] );
}
$_FILES = array();
if ( 'import' === ( isset( $in['scenario'] ) ? $in['scenario'] : '' ) ) {
	$_POST = array( 'cmp_import_appearance' => '1', '_wpnonce' => 'x' );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$err = isset( $in['upload_error'] ) ? (int) $in['upload_error'] : ( isset( $in['file'] ) && is_string( $in['file'] ) ? 0 : 4 );
	$_FILES[ CMP_Appearance_Page::IMPORT_FIELD ] = array( 'name' => '../../x.php', 'type' => 'application/json', 'tmp_name' => $tmp, 'error' => $err, 'size' => is_string( $in['file'] ?? null ) ? strlen( $in['file'] ) : 0 );
}

if ( in_array( isset( $in['scenario'] ) ? $in['scenario'] : '', array( 'toobig', 'emptypost' ), true ) ) { // over post_max_size: PHP leaves both empty
	$_POST                     = array();
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_SERVER['CONTENT_LENGTH'] = 'toobig' === $in['scenario'] ? '9000000' : '0';
}
$completed = false;
ob_start();
register_shutdown_function( function () use ( &$completed, $tmp ) {
	$html = ob_get_clean();
	@unlink( $tmp );
	$sc = isset( $GLOBALS['in_scenario'] ) ? $GLOBALS['in_scenario'] : '';
	$h  = $GLOBALS['io']['headers'];
	$fn = '';
	foreach ( $h as $line ) {
		if ( 0 === strpos( $line, 'Content-Disposition:' ) && preg_match( '/filename="([^"]*)"/', $line, $m ) ) {
			$fn = $m[1];
		}
	}
	echo json_encode( array(
		'html'            => 'export' === $sc ? '' : $html,
		'body'            => 'export' === $sc ? $html : '',
		'filename'        => $fn,
		'writes'          => $GLOBALS['io']['writes'],
		'died'            => $GLOBALS['io']['died'],
		'uploaded_checks' => $GLOBALS['io']['uploads'],
		'nocache'         => $GLOBALS['io']['nocache'],
		'exited'          => ! $completed,
	) );
} );
$GLOBALS['in_scenario'] = isset( $in['scenario'] ) ? $in['scenario'] : '';
try {
	if ( 'export' === $GLOBALS['in_scenario'] ) {
		CMP_Appearance_Page::export();
	} else {
		CMP_Appearance_Page::render();
	}
	$completed = true;
} catch ( Exception $e ) {
	$completed = true;
}
