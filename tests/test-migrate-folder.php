<?php
/**
 * CMP_Migrate_Folder (spec "varianta B"): moves plugins/consent-manager-pro to
 * plugins/biscuitwp only on demand (confirmed admin-post request, run at
 * shutdown), rewrites every option holding an old path (either entry file of the
 * old folder), keeps a headered two-file stub at the old path, and removes it
 * later. Pure helpers in this process; run(), the screen, cleanup and the purge
 * in child processes (constants are per process) against a temp WP_PLUGIN_DIR,
 * stubbed options and a WordPress-like get_plugins() / validate_plugin().
 */

$mf_root = dirname( __DIR__ );
$mf_file = $mf_root . '/includes/class-migrate-folder.php';
cmp_assert( is_file( $mf_file ), 'migrate: includes/class-migrate-folder.php exists' );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( is_file( $mf_file ) ) {
	require_once $mf_file;
}
$mf_ok = class_exists( 'CMP_Migrate_Folder' );
cmp_assert( $mf_ok, 'migrate: CMP_Migrate_Folder exists' );

$mf_old = 'consent-manager-pro/consent-manager-pro.php';
$mf_sib = 'consent-manager-pro/biscuitwp.php';
$mf_new = 'biscuitwp/biscuitwp.php';

if ( $mf_ok ) {
	cmp_assert( CMP_Migrate_Folder::OLD === $mf_old && CMP_Migrate_Folder::OLD_SIB === $mf_sib && CMP_Migrate_Folder::NEW === $mf_new, 'migrate: OLD, OLD_SIB, NEW basenames' );

	// replace_in_list: active_plugins, auto_update_plugins. Both old-folder files are "the old path" (C1).
	cmp_assert_eq( CMP_Migrate_Folder::replace_in_list( array( 'a/a.php', $mf_old, 'b/b.php' ) ), array( 'a/a.php', $mf_new, 'b/b.php' ), 'migrate: replace_in_list OLD -> NEW, order kept' );
	cmp_assert_eq( CMP_Migrate_Folder::replace_in_list( array( 'a/a.php', $mf_sib ) ), array( 'a/a.php', $mf_new ), 'migrate: replace_in_list old-folder biscuitwp.php -> NEW' );
	cmp_assert_eq( CMP_Migrate_Folder::replace_in_list( array( $mf_sib, 'a/a.php', $mf_old ) ), array( $mf_new, 'a/a.php' ), 'migrate: replace_in_list with both old files -> NEW once, at the first' );
	cmp_assert_eq( CMP_Migrate_Folder::replace_in_list( array( 'a/a.php', $mf_old, $mf_new, 'b/b.php' ) ), array( 'a/a.php', $mf_new, 'b/b.php' ), 'migrate: replace_in_list with OLD and NEW keeps NEW once' );
	cmp_assert_eq( CMP_Migrate_Folder::replace_in_list( array() ), array(), 'migrate: replace_in_list of an empty list' );
	cmp_assert_eq( CMP_Migrate_Folder::replace_in_list( array( 'a/a.php', 'biscuitwp/consent-manager-pro.php' ) ), array( 'a/a.php', 'biscuitwp/consent-manager-pro.php' ), 'migrate: replace_in_list leaves other paths alone' );

	// replace_in_map: active_sitewide_plugins, uninstall_plugins.
	cmp_assert_eq( CMP_Migrate_Folder::replace_in_map( array( $mf_old => 1700000000, 'x/x.php' => 1 ) ), array( $mf_new => 1700000000, 'x/x.php' => 1 ), 'migrate: replace_in_map renames the key, value kept' );
	cmp_assert_eq( CMP_Migrate_Folder::replace_in_map( array( 'x/x.php' => 1, $mf_sib => 5 ) ), array( 'x/x.php' => 1, $mf_new => 5 ), 'migrate: replace_in_map old-folder biscuitwp.php key -> NEW' );
	cmp_assert_eq( CMP_Migrate_Folder::replace_in_map( array( $mf_sib => 5, $mf_old => 6 ) ), array( $mf_new => 5 ), 'migrate: replace_in_map both old keys -> NEW once, first value' );
	cmp_assert_eq( CMP_Migrate_Folder::replace_in_map( array( $mf_old => 1, $mf_new => 2 ) ), array( $mf_new => 2 ), 'migrate: replace_in_map keeps an existing NEW and its value' );
	cmp_assert_eq( CMP_Migrate_Folder::replace_in_map( array( 'x/x.php' => 1, $mf_old => array( 'C', 'u' ) ) ), array( 'x/x.php' => 1, $mf_new => array( 'C', 'u' ) ), 'migrate: replace_in_map keeps a callable value' );

	// decide(): every branch, in order.
	$mf_base = array( 'legacy' => true, 'old_dir_exists' => true, 'new_dir_exists' => false, 'plugins_writable' => true, 'locked' => false, 'upgrading' => false );
	$mf_d    = function ( array $over ) use ( $mf_base ) {
		return CMP_Migrate_Folder::decide( array_merge( $mf_base, $over ) );
	};
	cmp_assert_eq( $mf_d( array() ), 'move', 'migrate: decide moves when every check passes' );
	cmp_assert_eq( $mf_d( array( 'legacy' => false, 'locked' => true ) ), 'skip', 'migrate: decide skips when not running from the old folder (first)' );
	cmp_assert_eq( $mf_d( array( 'old_dir_exists' => false ) ), 'skip', 'migrate: decide skips when the old folder is gone' );
	cmp_assert_eq( $mf_d( array( 'locked' => true, 'new_dir_exists' => true ) ), 'busy', 'migrate: decide busy when locked (before fail checks)' );
	cmp_assert_eq( $mf_d( array( 'upgrading' => true, 'plugins_writable' => false ) ), 'busy', 'migrate: decide busy while an update runs' );
	cmp_assert_eq( $mf_d( array( 'new_dir_exists' => true, 'plugins_writable' => false ) ), 'fail:new_exists', 'migrate: decide fails when plugins/biscuitwp exists (before writable)' );
	cmp_assert_eq( $mf_d( array( 'plugins_writable' => false ) ), 'fail:not_writable', 'migrate: decide fails when plugins/ is not writable' );
	cmp_assert_eq( CMP_Migrate_Folder::decide( array() ), 'skip', 'migrate: decide with no input skips' );

	// The stub: same plugin header as biscuitwp.php, marker, loads the plugin from biscuitwp/.
	$mf_stub     = CMP_Migrate_Folder::stub_source();
	$mf_main_hdr = CMP_Migrate_Folder::header_block( (string) file_get_contents( $mf_root . '/biscuitwp.php' ) );
	cmp_assert( '' !== $mf_main_hdr && CMP_Migrate_Folder::header_block( $mf_stub ) === $mf_main_hdr, 'migrate stub: carries exactly the plugin header of biscuitwp.php (validate_plugin() needs one)' );
	cmp_assert( 0 === strpos( $mf_stub, "<?php\n/**\n * Plugin Name: BiscuitWP\n" ), 'migrate stub: the header opens the file (within get_file_data()\'s first 8 KB)' );
	cmp_assert( false !== strpos( $mf_stub, '// BiscuitWP folder-move stub v1.' ) && 'BiscuitWP folder-move stub v1' === CMP_Migrate_Folder::STUB_MARKER, 'migrate stub: carries the fixed marker' );
	cmp_assert( false !== strpos( $mf_stub, "\$cmp_stub_target = dirname( __DIR__ ) . '/biscuitwp/consent-manager-pro.php';\nif ( is_readable( \$cmp_stub_target ) ) {\n\trequire_once \$cmp_stub_target;\n}\nunset( \$cmp_stub_target );" ) && false !== strpos( $mf_stub, "if ( ! defined( 'ABSPATH' ) ) {" ), 'migrate stub (R1-I3): requires the legacy loader in biscuitwp/ only when it is readable, exits outside WordPress' );
	cmp_assert_eq( CMP_Migrate_Folder::stub_source( '' ), '', 'migrate stub: no header, no stub' );
	// R2-M2: the stub text is an interface. is_our_stub_dir() regenerates it from the
	// stub's own header, so stubs written by 1.1.0 are recognised only while this text
	// stays byte-identical. NEVER change it: a change strands every 1.1.0 stub (never
	// cleaned up, a second Plugins row). Header values (Version, ...) may vary freely.
	cmp_assert_eq( hash( 'sha256', CMP_Migrate_Folder::stub_source( "/**\n * Plugin Name: BiscuitWP\n * Version: 1.1.0\n */" ) ), '5f57a6c66f12b907fc85ba8417388473af7c5eb3533a2f91fb7fbeda7a813abd', 'migrate stub (R2-M2): the stub text is pinned (1.1.0 shape)' );
	cmp_assert_eq( CMP_Migrate_Folder::stub_source( "/**\n * Not a plugin\n */" ), '', 'migrate stub: a docblock without Plugin Name is refused' );
}

// Sources: registration, the trigger, deletions, uninstall.
$mf_src        = is_file( $mf_file ) ? (string) file_get_contents( $mf_file ) : '';
$mf_plugin_src = (string) file_get_contents( $mf_root . '/includes/class-plugin.php' );
cmp_assert( false === strpos( $mf_plugin_src, 'CMP_Migrate_Folder::register()' ) && false === strpos( $mf_plugin_src, "'run' )" ), 'migrate: CMP_Plugin registers no automatic move' );
cmp_assert( 1 === preg_match( "/if \( is_admin\(\) \|\| wp_doing_cron\(\) \) \{\s*require_once CMP_PATH \. 'includes\/class-migrate-folder\.php';\s*if \( is_admin\(\) \) \{\s*CMP_Migrate_Folder::register_admin\(\);\s*\}\s*if \( defined\( 'WP_PLUGIN_DIR' \) && is_dir\( WP_PLUGIN_DIR \. '\/consent-manager-pro' \) && ! CMP_Migrate_Folder::runs_from_old_folder\(\) \) \{\s*CMP_Migrate_Folder::register_cleanup\(\);/", $mf_plugin_src ), 'migrate: CMP_Plugin registers the move screen in admin and the stub cleanup (admin/cron, from biscuitwp/)' );
cmp_assert( 1 === substr_count( $mf_src, "array( __CLASS__, 'run' )" ) && 1 === preg_match( "/public static function queue\\( \\\$token = '' \\) \\{[^}]*self::\\\$queued  = true;[^}]*add_action\\( 'shutdown', array\\( __CLASS__, 'run' \\), PHP_INT_MAX \\);/", $mf_src ), 'migrate: run() is hooked only by queue(), on shutdown at PHP_INT_MAX' );
cmp_assert( 1 === preg_match( "/public static function run\(\) \{\s*if \( ! self::\\\$queued \) \{\s*return;/", $mf_src ), 'migrate: run() does nothing unless queued by the confirmed request' );
cmp_assert( 1 === preg_match( "/public static function handle\(\) \{\s*if \( ! self::user_may_move\(\) \) \{\s*wp_die\([^;]*;\s*\}\s*check_admin_referer\( self::ACTION \);/", $mf_src ), 'migrate: the handler checks the capability, then the nonce' );
cmp_assert( false !== strpos( $mf_src, "add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );" ) && "cmp_folder_move" === ( $mf_ok ? CMP_Migrate_Folder::ACTION : '' ), 'migrate: admin-post action cmp_folder_move' );
cmp_assert( false !== strpos( $mf_src, "add_action( 'shutdown', array( __CLASS__, 'cleanup_leftover' ), PHP_INT_MAX );" ), 'migrate: cleanup_leftover() on shutdown at PHP_INT_MAX' );
$mf_rest = preg_replace( '/private static function (remove_stub_dir|make_stub)\(.*?\n\t\}\n/s', '', $mf_src );
cmp_assert( '' !== $mf_src && 2 === preg_match_all( '/private static function (remove_stub_dir|make_stub)\(/', $mf_src ) && false === strpos( $mf_rest, 'unlink(' ) && false === strpos( $mf_rest, 'rmdir(' ), 'migrate: unlink/rmdir only in remove_stub_dir() and make_stub()' );
cmp_assert( false === strpos( $mf_src, 'delete_plugins' ) && false === strpos( $mf_src, 'copy(' ) && false === strpos( $mf_src, 'wp_delete_file' ), 'migrate: never copies files or deletes plugins' );
cmp_assert( false !== strpos( $mf_src, "\$stub = self::stub_source();\n\t\tif ( '' === \$stub ) {" ), 'migrate stub: built before the rename (its header is read from the folder about to move)' );
foreach ( array( 'rocket_clean_domain', 'w3tc_flush_all', 'litespeed_purge_all', 'wp_cache_clear_cache', 'wpfc_clear_all_cache', 'autoptimizeCache', 'sg_cachepress_purge_cache', 'breeze_clear_all_cache' ) as $mf_p ) {
	cmp_assert( false !== strpos( $mf_src, "'" . $mf_p . "'" ), "migrate purge: knows $mf_p" );
}
$mf_un_src = (string) file_get_contents( $mf_root . '/includes/class-uninstaller.php' );
cmp_assert( false !== strpos( $mf_un_src, "delete_site_option( 'cmp_folder_move' );" ) && false !== strpos( $mf_un_src, "delete_site_option( 'cmp_folder_move_lock' );" ), 'migrate: uninstall removes cmp_folder_move and cmp_folder_move_lock' );

// ---------------------------------------------------------------------------
// Child processes.
// ---------------------------------------------------------------------------
$mf_tmp = realpath( sys_get_temp_dir() ) . '/cmp-mf-' . getmypid() . '-' . mt_rand();
@mkdir( $mf_tmp, 0777, true );
file_put_contents(
	$mf_tmp . '/driver.php',
	<<<'PHPDRV'
<?php
error_reporting( E_ALL );
$GLOBALS['errors'] = array();
set_error_handler( function ( $no, $str ) { $GLOBALS['errors'][] = $str; return true; } );
$sc = json_decode( file_get_contents( $argv[1] ), true );
$GLOBALS['sc'] = $sc;
define( 'ABSPATH', $sc['abspath'] . '/' );
define( 'WP_PLUGIN_DIR', $sc['plugins'] );
define( 'CMP_ENTRY', $sc['entry'] );
define( 'CMP_PATH', $sc['root'] . '/' );
$GLOBALS['o']        = $sc['blogs'];
$GLOBALS['so']       = $sc['site'];
$GLOBALS['blog']     = isset( $sc['current_blog'] ) ? (int) $sc['current_blog'] : 1;
$GLOBALS['stack']    = array();
$GLOBALS['fail']     = isset( $sc['fail'] ) ? $sc['fail'] : array();
$GLOBALS['attempts'] = array();
$GLOBALS['log']      = array();
$GLOBALS['wp_filter'] = array();
// An option_active_plugins filter (a plugin-loading manager) that hides one plugin on this page.
if ( ! empty( $sc['strip'] ) ) {
	$GLOBALS['wp_filter']['option_active_plugins'] = array( function ( $v ) { return array_values( array_diff( (array) $v, array( $GLOBALS['sc']['strip'] ) ) ); } );
}
foreach ( isset( $sc['purge_fns'] ) ? $sc['purge_fns'] : array() as $fn ) {
	eval( 'function ' . $fn . '() { $GLOBALS["log"][] = "purge:' . $fn . '"; }' );
}
/** Fail id on attempt n ("id#n"), or a plain "id" once (its first attempt). */
function mf_fail( $id ) {
	$n = isset( $GLOBALS['attempts'][ $id ] ) ? ++$GLOBALS['attempts'][ $id ] : ( $GLOBALS['attempts'][ $id ] = 1 );
	foreach ( array( $id . '#' . $n, $id ) as $k ) {
		$i = array_search( $k, $GLOBALS['fail'], true );
		if ( false !== $i ) { unset( $GLOBALS['fail'][ $i ] ); return true; }
	}
	return false;
}
function mf_filtered( $hook, $v ) {
	foreach ( isset( $GLOBALS['wp_filter'][ $hook ] ) ? $GLOBALS['wp_filter'][ $hook ] : array() as $cb ) { $v = $cb( $v ); }
	return $v;
}
function is_multisite() { return ! empty( $GLOBALS['sc']['multisite'] ); }
function get_option( $k, $d = false ) { $b = $GLOBALS['blog']; $v = isset( $GLOBALS['o'][ $b ] ) && array_key_exists( $k, $GLOBALS['o'][ $b ] ) ? $GLOBALS['o'][ $b ][ $k ] : $d; return mf_filtered( 'option_' . $k, $v ); }
function update_option( $k, $v, $a = null ) { $id = 'blog:' . $GLOBALS['blog'] . ':' . $k; $GLOBALS['log'][] = 'update:' . $id; if ( mf_fail( $id ) ) { return false; } if ( ! empty( $GLOBALS['sc']['reorder'] ) && 'active_plugins' === $k && is_array( $v ) ) { $v = array_reverse( $v ); } $GLOBALS['o'][ $GLOBALS['blog'] ][ $k ] = $v; return true; }
function add_option( $k, $v, $dep = '', $a = null ) { $id = 'blog:' . $GLOBALS['blog'] . ':' . $k; $GLOBALS['log'][] = 'add:' . $id; if ( mf_fail( 'add:' . $id ) || ( isset( $GLOBALS['o'][ $GLOBALS['blog'] ] ) && array_key_exists( $k, $GLOBALS['o'][ $GLOBALS['blog'] ] ) ) ) { return false; } $GLOBALS['o'][ $GLOBALS['blog'] ][ $k ] = $v; return true; }
function delete_option( $k ) { $GLOBALS['log'][] = 'delete:blog:' . $GLOBALS['blog'] . ':' . $k; unset( $GLOBALS['o'][ $GLOBALS['blog'] ][ $k ] ); return true; }
function get_site_option( $k, $d = false ) { if ( ! is_multisite() ) { return get_option( $k, $d ); } $v = array_key_exists( $k, $GLOBALS['so'] ) ? $GLOBALS['so'][ $k ] : $d; return mf_filtered( 'site_option_' . $k, $v ); }
function update_site_option( $k, $v ) { if ( ! is_multisite() ) { return update_option( $k, $v ); } $GLOBALS['log'][] = 'update:site:' . $k; if ( mf_fail( 'site:' . $k ) ) { return false; } $GLOBALS['so'][ $k ] = $v; return true; }
function add_site_option( $k, $v ) { if ( ! is_multisite() ) { return add_option( $k, $v ); } $GLOBALS['log'][] = 'add:site:' . $k; if ( mf_fail( 'add:site:' . $k ) || array_key_exists( $k, $GLOBALS['so'] ) ) { return false; } $GLOBALS['so'][ $k ] = $v; return true; }
function delete_site_option( $k ) { if ( ! is_multisite() ) { return delete_option( $k ); } $GLOBALS['log'][] = 'delete:site:' . $k; unset( $GLOBALS['so'][ $k ] ); return true; }
function get_site_transient( $k ) {
	if ( ! array_key_exists( '_t_' . $k, $GLOBALS['so'] ) ) { return false; }
	$o = new stdClass();
	foreach ( $GLOBALS['so'][ '_t_' . $k ] as $p => $x ) {
		if ( 'response' === $p || 'no_update' === $p ) { $x = array_map( function ( $e ) { return (object) $e; }, (array) $x ); }
		$o->$p = $x;
	}
	return $o;
}
function set_site_transient( $k, $v, $e = 0 ) { $GLOBALS['log'][] = 'set_transient:' . $k; $GLOBALS['so'][ '_t_' . $k ] = json_decode( json_encode( $v ), true ); return true; }
function get_sites( $a = array() ) {
	if ( ! empty( $a['count'] ) ) { return isset( $GLOBALS['sc']['site_count'] ) ? $GLOBALS['sc']['site_count'] : count( $GLOBALS['o'] ); }
	$ids = array_map( 'intval', array_keys( $GLOBALS['o'] ) );
	return isset( $a['number'] ) && $a['number'] > 0 ? array_slice( $ids, 0, $a['number'] ) : $ids;
}
function get_networks( $a = array() ) { return isset( $GLOBALS['sc']['networks'] ) ? $GLOBALS['sc']['networks'] : 1; }
function get_main_site_id() { return 1; }
function switch_to_blog( $id ) { $GLOBALS['stack'][] = $GLOBALS['blog']; $GLOBALS['blog'] = (int) $id; return true; }
function restore_current_blog() { $GLOBALS['blog'] = array_pop( $GLOBALS['stack'] ); return true; }
function wp_installing() { return ! empty( $GLOBALS['sc']['installing'] ); }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function is_admin() { return true; }
function is_network_admin() { return ! empty( $GLOBALS['sc']['network_admin'] ); }
function is_super_admin() { return ! empty( $GLOBALS['sc']['super'] ); }
function did_action( $h ) { return isset( $GLOBALS['sc']['did'] ) && in_array( $h, $GLOBALS['sc']['did'], true ) ? 1 : 0; }
function doing_action( $h = null ) { return false; }
function has_action( $h, $cb = false ) { return isset( $GLOBALS['sc']['actions'] ) && in_array( $h, $GLOBALS['sc']['actions'], true ); }
function do_action( $h, ...$args ) { $GLOBALS['log'][] = 'do_action:' . $h; }
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['log'][] = 'add_action:' . $h . ':' . $p; return true; }
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['log'][] = 'add_filter:' . $h; return true; }
function add_submenu_page( ...$a ) { $GLOBALS['log'][] = 'submenu:' . $a[0] . ':' . $a[3] . ':' . $a[4]; return 'hook'; }
function plugin_basename( $f ) { $p = WP_PLUGIN_DIR . '/'; return 0 === strpos( $f, $p ) ? substr( $f, strlen( $p ) ) : basename( dirname( $f ) ) . '/' . basename( $f ); }
function wp_clean_plugins_cache( $clear = true ) { $GLOBALS['log'][] = 'clean_plugins_cache:' . var_export( $clear, true ); }
function current_user_can( $c ) { return isset( $GLOBALS['sc']['caps'] ) && in_array( $c, $GLOBALS['sc']['caps'], true ); }
function __( $t, $d = null ) { return $t; }
function esc_html__( $t, $d = null ) { return htmlspecialchars( $t, ENT_QUOTES ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); }
function admin_url( $p = '' ) { return 'http://example.test/wp-admin/' . $p; }
function network_admin_url( $p = '' ) { return 'http://example.test/wp-admin/network/' . $p; }
function home_url( $p = '' ) { return 'http://example.test' . $p; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function wp_unslash( $v ) { return $v; }
function wp_nonce_field( $a ) { echo '<input type="hidden" name="_wpnonce" value="nonce-' . $a . '">'; }
require CMP_PATH . 'includes/class-plugin.php';
require CMP_PATH . 'includes/class-migrate-folder.php';
// WordPress's get_plugins(): *.php directly in WP_PLUGIN_DIR or one folder down
// (dot entries skipped), listed when the first 8 KB carry a Plugin Name header.
function mf_get_plugins() {
	$out = array();
	foreach ( (array) @scandir( WP_PLUGIN_DIR ) as $f ) {
		if ( '' === $f || '.' === $f[0] ) { continue; }
		$files = is_dir( WP_PLUGIN_DIR . '/' . $f ) ? array_map( function ( $x ) use ( $f ) { return $f . '/' . $x; }, array_filter( (array) @scandir( WP_PLUGIN_DIR . '/' . $f ), function ( $x ) { return '' !== $x && '.' !== $x[0]; } ) ) : array( $f );
		foreach ( $files as $k ) {
			if ( '.php' !== substr( $k, -4 ) || ! is_readable( WP_PLUGIN_DIR . '/' . $k ) ) { continue; }
			$head = (string) file_get_contents( WP_PLUGIN_DIR . '/' . $k, false, null, 0, 8192 );
			if ( preg_match( '/^[ \t\/*#@]*Plugin Name:(.*)$/mi', $head, $m ) && '' !== trim( $m[1] ) ) { $out[ $k ] = trim( $m[1] ); }
		}
	}
	return $out;
}
// WordPress's validate_plugin(): the file exists and get_plugins() lists it.
function mf_validate_plugin( $p ) {
	$all = mf_get_plugins();
	return is_file( WP_PLUGIN_DIR . '/' . $p ) && isset( $all[ $p ] );
}
// Every option that activates our plugin (each site's active_plugins, the network
// list) holds a path that validate_plugin() keeps and that loads the plugin.
function mf_loadable() {
	$slots = array();
	foreach ( $GLOBALS['o'] as $opts ) {
		$slots[] = isset( $opts['active_plugins'] ) ? (array) $opts['active_plugins'] : array();
	}
	if ( is_multisite() && isset( $GLOBALS['so']['active_sitewide_plugins'] ) ) {
		$slots[] = array_keys( (array) $GLOBALS['so']['active_sitewide_plugins'] );
	}
	$ours = 0;
	clearstatcache();
	foreach ( $slots as $paths ) {
		$mine = array_filter( $paths, function ( $p ) { return 0 === strpos( $p, 'consent-manager-pro/' ) || 0 === strpos( $p, 'biscuitwp/' ); } );
		if ( ! $mine ) { continue; }
		$ours++;
		$ok = false;
		foreach ( $mine as $p ) {
			$f = WP_PLUGIN_DIR . '/' . $p;
			if ( mf_validate_plugin( $p ) && ( false === strpos( (string) file_get_contents( $f ), CMP_Migrate_Folder::STUB_MARKER ) || is_file( WP_PLUGIN_DIR . '/biscuitwp/consent-manager-pro.php' ) ) ) { $ok = true; }
		}
		if ( ! $ok ) { return false; }
	}
	return $ours > 0;
}
$GLOBALS['steps']           = array();
$GLOBALS['loadable_before'] = mf_loadable();
$seam                       = isset( $sc['seam'] ) ? $sc['seam'] : array();
$seam['on_step']            = function ( $name ) {
	clearstatcache();
	$GLOBALS['steps'][] = array(
		'step'     => $name,
		'loadable' => mf_loadable(),
		'stub'     => CMP_Migrate_Folder::is_our_stub_dir( WP_PLUGIN_DIR . '/consent-manager-pro' ),
		'new_dir'  => is_dir( WP_PLUGIN_DIR . '/biscuitwp' ),
	);
};
$rp = new ReflectionProperty( 'CMP_Migrate_Folder', 'seam' );
$rp->setAccessible( true );
$rp->setValue( null, $seam );
$out = array();
switch ( $sc['mode'] ) {
	case 'page':
		ob_start();
		$_GET = isset( $sc['get'] ) ? $sc['get'] : array();
		CMP_Migrate_Folder::render();
		$out['html'] = ob_get_clean();
		break;
	case 'check':
		$out['check'] = CMP_Migrate_Folder::check_request( $sc['post'], $sc['allowed'] );
		break;
	case 'row':
		$out['links'] = CMP_Migrate_Folder::row_link( array( 'x' ) );
		break;
	case 'cleanup':
		CMP_Migrate_Folder::cleanup_leftover();
		break;
	case 'menu':
		CMP_Migrate_Folder::add_page();
		break;
	case 'unqueued':
		CMP_Migrate_Folder::run();
		break;
	case 'admin_init':
		$_GET               = isset( $sc['get'] ) ? $sc['get'] : array();
		$out['purge_first'] = CMP_Migrate_Folder::purge_after_move();
		$out['purge_again'] = CMP_Migrate_Folder::purge_after_move();
		break;
	default:
		CMP_Migrate_Folder::queue( 'attempt1' );
		CMP_Migrate_Folder::run();
}
clearstatcache();
$old = WP_PLUGIN_DIR . '/consent-manager-pro';
echo json_encode( $out + array(
	'errors'          => $GLOBALS['errors'],
	'blogs'           => $GLOBALS['o'],
	'site'            => $GLOBALS['so'],
	'log'             => $GLOBALS['log'],
	'blog'            => $GLOBALS['blog'],
	'old_dir'         => is_dir( $old ),
	'new_dir'         => is_dir( WP_PLUGIN_DIR . '/biscuitwp' ),
	'moved_file'      => is_file( WP_PLUGIN_DIR . '/biscuitwp/includes-marker.txt' ),
	'real_old'        => is_file( $old . '/includes-marker.txt' ),
	'tmp_dir'         => file_exists( WP_PLUGIN_DIR . '/.biscuitwp-move-stub' ),
	'steps'           => $GLOBALS['steps'],
	'stub_dir'        => CMP_Migrate_Folder::is_our_stub_dir( $old ),
	'old_entries'     => is_dir( $old ) ? array_values( array_diff( scandir( $old ), array( '.', '..' ) ) ) : null,
	'loadable'        => mf_loadable(),
	'loadable_before' => $GLOBALS['loadable_before'],
	'valid'           => array_keys( mf_get_plugins() ),
) );
PHPDRV
);

/**
 * One scenario in a fresh temp WordPress: plugins/consent-manager-pro/ with the
 * real entry files (both headered) and a marker file. Returns the child's JSON.
 */
$mf_n   = 0;
$mf_run = function ( array $sc ) use ( $mf_tmp, $mf_root, &$mf_n ) {
	$mf_n++;
	$base    = $mf_tmp . '/s' . $mf_n;
	$plugins = $base . '/wp/wp-content/plugins';
	@mkdir( $plugins . '/consent-manager-pro', 0777, true );
	copy( $mf_root . '/consent-manager-pro.php', $plugins . '/consent-manager-pro/consent-manager-pro.php' );
	copy( $mf_root . '/biscuitwp.php', $plugins . '/consent-manager-pro/biscuitwp.php' );
	file_put_contents( $plugins . '/consent-manager-pro/includes-marker.txt', 'real plugin folder' );
	$sc += array(
		'mode'   => 'run',
		'legacy' => true,
		'entry'  => $plugins . '/consent-manager-pro/consent-manager-pro.php',
		'blogs'  => array( 1 => array() ),
		'site'   => array(),
		'caps'   => array(),
	);
	if ( isset( $sc['prepare'] ) ) {
		$sc['entry'] = call_user_func( $sc['prepare'], $base, $plugins, $sc['entry'] );
		unset( $sc['prepare'] );
	}
	$after = isset( $sc['after'] ) ? $sc['after'] : null;
	unset( $sc['after'] );
	$sc['abspath'] = $base . '/wp';
	$sc['plugins'] = $plugins;
	$sc['root']    = $mf_root;
	if ( empty( $sc['site'] ) ) {
		$sc['site'] = new stdClass();
	}
	file_put_contents( $base . '/sc.json', json_encode( $sc ) );
	$out = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $mf_tmp . '/driver.php' ) . ' ' . escapeshellarg( $base . '/sc.json' ) . ' 2>&1' );
	if ( $after ) {
		call_user_func( $after, $base, $plugins );
	}
	$res = json_decode( (string) $out, true );
	if ( ! is_array( $res ) ) {
		echo 'child output: ' . substr( (string) $out, 0, 600 ) . "\n";
		return array();
	}
	return $res;
};
$mf_g = function ( $r, $path, $def = null ) {
	foreach ( explode( '.', $path ) as $k ) {
		if ( ! is_array( $r ) || ! array_key_exists( $k, $r ) ) {
			return $def;
		}
		$r = $r[ $k ];
	}
	return $r;
};
$mf_logs = function ( $r, $needle ) use ( $mf_g ) {
	foreach ( (array) $mf_g( $r, 'log', array() ) as $line ) {
		if ( false !== strpos( $line, $needle ) ) {
			return true;
		}
	}
	return false;
};
$mf_unmoved = function ( $r ) use ( $mf_g ) {
	return true === $mf_g( $r, 'real_old' ) && false === $mf_g( $r, 'new_dir' ) && false === $mf_g( $r, 'tmp_dir' );
};
$mf_steps = function ( $r ) use ( $mf_g ) {
	return array_map( function ( $x ) { return $x['step']; }, (array) $mf_g( $r, 'steps', array() ) );
};
$mf_step = function ( $r, $name ) use ( $mf_g ) {
	foreach ( (array) $mf_g( $r, 'steps', array() ) as $x ) {
		if ( $x['step'] === $name ) {
			return $x;
		}
	}
	return array();
};
// Loadable (validate_plugin() + loads) before the move, at every step boundary, and after.
$mf_all_loadable = function ( $r ) use ( $mf_g ) {
	$st = (array) $mf_g( $r, 'steps', array() );
	foreach ( $st as $x ) {
		if ( empty( $x['loadable'] ) ) {
			return false;
		}
	}
	return count( $st ) > 0 && true === $mf_g( $r, 'loadable' ) && true === $mf_g( $r, 'loadable_before' );
};
$mf_state = function ( $r, $ms = false ) use ( $mf_g ) {
	return $mf_g( $r, $ms ? 'site.cmp_folder_move' : 'blogs.1.cmp_folder_move' );
};

$mf_now = time();
$mf_upd = array(
	'checked'   => array( $mf_sib => '1.1.0', $mf_old => '1.1.0', 'akismet/akismet.php' => '5.0' ),
	'response'  => array(
		$mf_sib               => array( 'plugin' => $mf_sib, 'new_version' => '1.1.1' ),
		$mf_old               => array( 'plugin' => $mf_old, 'new_version' => '1.1.1' ),
		'akismet/akismet.php' => array( 'plugin' => 'akismet/akismet.php', 'new_version' => '5.1' ),
	),
	'no_update' => array(),
);
$mf_single      = array(
	1 => array(
		'active_plugins'      => array( 'akismet/akismet.php', $mf_old, 'z/z.php' ),
		'uninstall_plugins'   => array( $mf_old => array( 'CMP_X', 'uninstall' ), 'a/a.php' => 'a_un' ),
		'auto_update_plugins' => array( $mf_old, 'akismet/akismet.php' ),
	),
);
$mf_single_site = array( '_t_update_plugins' => $mf_upd );

// A. Single site, confirmed: moved, options rewritten, stub kept at the old path, done.
$r = $mf_run( array( 'blogs' => $mf_single, 'site' => $mf_single_site ) );
cmp_assert_eq( $mf_g( $r, 'errors' ), array(), 'migrate run (single): no PHP warnings' );
cmp_assert( true === $mf_g( $r, 'moved_file' ) && false === $mf_g( $r, 'real_old' ) && false === $mf_g( $r, 'tmp_dir' ), 'migrate run (single): the real folder is now plugins/biscuitwp, no temp stub left' );
cmp_assert( true === $mf_g( $r, 'stub_dir' ) && array( 'biscuitwp.php', 'consent-manager-pro.php' ) === $mf_g( $r, 'old_entries' ), 'migrate run (single): plugins/consent-manager-pro is kept as our two-file stub (I1)' );
cmp_assert_eq( $mf_g( $r, 'blogs.1.active_plugins' ), array( 'akismet/akismet.php', $mf_new, 'z/z.php' ), 'migrate run (single): active_plugins rewritten, order kept' );
cmp_assert_eq( $mf_g( $r, 'blogs.1.uninstall_plugins' ), array( $mf_new => array( 'CMP_X', 'uninstall' ), 'a/a.php' => 'a_un' ), 'migrate run (single): uninstall_plugins key rewritten' );
cmp_assert_eq( $mf_g( $r, 'blogs.1.auto_update_plugins' ), array( $mf_new, 'akismet/akismet.php' ), 'migrate run (single): auto_update_plugins rewritten' );
cmp_assert( 'done' === $mf_g( $mf_state( $r ), 'state' ) && '' === $mf_g( $mf_state( $r ), 'reason' ) && abs( (int) $mf_g( $mf_state( $r ), 'ts' ) - $mf_now ) < 60, 'migrate run (single): state done, ts now' );
cmp_assert( null === $mf_g( $r, 'blogs.1.cmp_folder_move_lock' ) && $mf_logs( $r, 'add:blog:1:cmp_folder_move_lock' ), 'migrate run (single): lock taken and released' );
cmp_assert( $mf_logs( $r, 'clean_plugins_cache:false' ) && $mf_logs( $r, 'do_action:biscuitwp_folder_moved' ), 'migrate run (single): plugins cache cleaned, biscuitwp_folder_moved fired' );
$mf_t = $mf_g( $r, 'site._t_update_plugins', array() );
cmp_assert( ! isset( $mf_t['response'][ $mf_old ] ) && ! isset( $mf_t['response'][ $mf_sib ] ) && ! isset( $mf_t['checked'][ $mf_sib ] ) && isset( $mf_t['response']['akismet/akismet.php'] ), 'migrate run (single): old-folder update entries dropped, others kept' );
cmp_assert_eq( $mf_steps( $r ), array( 'stub', 'option', 'option', 'option', 'done' ), 'migrate run (single): steps stub, 3 options, done' );
$mf_x = $mf_step( $r, 'stub' );
cmp_assert( ! empty( $mf_x['stub'] ) && ! empty( $mf_x['new_dir'] ), 'migrate run (single): right after the rename, the old path holds our stub and biscuitwp/ exists' );
cmp_assert( $mf_all_loadable( $r ), 'migrate run (single): the active path passes validate_plugin() and loads before, at every step and after' );
cmp_assert( in_array( $mf_new, (array) $mf_g( $r, 'valid' ), true ) && in_array( $mf_old, (array) $mf_g( $r, 'valid' ), true ) && in_array( $mf_sib, (array) $mf_g( $r, 'valid' ), true ), 'migrate run (single): after the move NEW and both old paths pass validate_plugin() (stale requests keep working)' );

// Not queued (no confirmed request): nothing.
$r = $mf_run( array( 'mode' => 'unqueued', 'blogs' => $mf_single ) );
cmp_assert( $mf_unmoved( $r ) && ! $mf_logs( $r, 'update:' ) && ! $mf_logs( $r, 'add:' ), 'migrate run: without the confirmed request run() does nothing' );

// C1. Multisite: site 2 active through the old-folder biscuitwp.php, network list too.
$mf_ms_blogs = array(
	1 => array( 'active_plugins' => array( $mf_old ), 'uninstall_plugins' => array( $mf_old => 'cb' ) ),
	2 => array( 'active_plugins' => array( 'akismet/akismet.php', $mf_sib ) ),
	3 => array( 'active_plugins' => array( 'a/a.php', $mf_old, $mf_new ) ),
	4 => array( 'active_plugins' => array( 'akismet/akismet.php' ) ),
);
$mf_ms_site  = array(
	'active_sitewide_plugins' => array( 'hello.php' => 1600000000, $mf_sib => 1700000000 ),
	'auto_update_plugins'     => array( $mf_old, $mf_sib ),
	'_t_update_plugins'       => $mf_upd,
);
$mf_ms       = array( 'multisite' => true, 'network_admin' => true, 'super' => true, 'blogs' => $mf_ms_blogs, 'site' => $mf_ms_site );
$r           = $mf_run( $mf_ms );
cmp_assert_eq( $mf_g( $r, 'errors' ), array(), 'migrate run (multisite): no PHP warnings' );
cmp_assert( true === $mf_g( $r, 'moved_file' ) && 'done' === $mf_g( $mf_state( $r, true ), 'state' ), 'migrate run (multisite): moved, done as a network option' );
cmp_assert_eq( $mf_g( $r, 'blogs.2.active_plugins' ), array( 'akismet/akismet.php', $mf_new ), 'migrate run (multisite, C1): a site active through consent-manager-pro/biscuitwp.php is rewritten too' );
cmp_assert_eq( $mf_g( $r, 'blogs.3.active_plugins' ), array( 'a/a.php', $mf_new ), 'migrate run (multisite): OLD dropped where NEW is already active' );
cmp_assert_eq( $mf_g( $r, 'site.active_sitewide_plugins' ), array( 'hello.php' => 1600000000, $mf_new => 1700000000 ), 'migrate run (multisite, C1): network key consent-manager-pro/biscuitwp.php -> NEW, timestamp kept' );
cmp_assert_eq( $mf_g( $r, 'site.auto_update_plugins' ), array( $mf_new ), 'migrate run (multisite): auto_update_plugins both old files -> NEW once' );
cmp_assert( ! $mf_logs( $r, 'update:blog:4:' ), 'migrate run (multisite): sites without the plugin are not written' );
cmp_assert( $mf_all_loadable( $r ) && 1 === $mf_g( $r, 'blog' ), 'migrate run (multisite): every site loadable at every step; back on the original site' );
$r = $mf_run(
	$mf_ms + array(
		'legacy'  => false,
		'prepare' => function ( $base, $plugins, $entry ) {
			return $plugins . '/consent-manager-pro/biscuitwp.php';
		},
	)
);
cmp_assert( true === $mf_g( $r, 'moved_file' ) && 'done' === $mf_g( $mf_state( $r, true ), 'state' ), 'migrate run (multisite, C1): also runs when loaded through consent-manager-pro/biscuitwp.php' );

// Rollback: a write fails, every option restored and checked, stub removed, folder back.
$r = $mf_run( array( 'blogs' => $mf_single, 'site' => $mf_single_site, 'fail' => array( 'blog:1:uninstall_plugins' ) ) );
cmp_assert_eq( $mf_g( $r, 'errors' ), array(), 'migrate rollback: no PHP warnings' );
cmp_assert( $mf_unmoved( $r ) && null !== $mf_g( $r, 'old_entries' ) && in_array( 'includes-marker.txt', $mf_g( $r, 'old_entries' ), true ), 'migrate rollback: the real folder is back at plugins/consent-manager-pro' );
foreach ( $mf_single[1] as $mf_k => $mf_v ) {
	cmp_assert_eq( $mf_g( $r, "blogs.1.$mf_k" ), $mf_v, "migrate rollback: $mf_k as before" );
}
cmp_assert( 'failed' === $mf_g( $mf_state( $r ), 'state' ) && 'options' === $mf_g( $mf_state( $r ), 'reason' ) && null === $mf_g( $r, 'blogs.1.cmp_folder_move_lock' ), 'migrate rollback: failed / options, lock released' );
cmp_assert_eq( $mf_steps( $r ), array( 'stub', 'option', 'restored', 'renamed_back' ), 'migrate rollback: stub, option, restored (stub still there), renamed_back' );
cmp_assert( $mf_all_loadable( $r ) && ! empty( $mf_step( $r, 'restored' )['stub'] ), 'migrate rollback: loadable at every step; options restored while the stub serves the old path' );
$r = $mf_run( $mf_ms + array( 'fail' => array( 'site:auto_update_plugins' ) ) );
cmp_assert( $mf_unmoved( $r ) && $mf_all_loadable( $r ) && 'options' === $mf_g( $mf_state( $r, true ), 'reason' ), 'migrate rollback (multisite): last write fails, all restored, renamed back, loadable at every step' );
foreach ( $mf_ms_blogs as $mf_id => $mf_opts ) {
	foreach ( $mf_opts as $mf_k => $mf_v ) {
		cmp_assert_eq( $mf_g( $r, "blogs.$mf_id.$mf_k" ), $mf_v, "migrate rollback (multisite): site $mf_id $mf_k restored" );
	}
}
cmp_assert_eq( $mf_g( $r, 'site.active_sitewide_plugins' ), $mf_ms_site['active_sitewide_plugins'], 'migrate rollback (multisite): network list restored' );

// I2. A restore fails too: the folder must not go back; everything forward to biscuitwp/.
$r = $mf_run( array( 'blogs' => $mf_single, 'site' => $mf_single_site, 'fail' => array( 'blog:1:uninstall_plugins', 'blog:1:active_plugins#2' ) ) );
cmp_assert_eq( $mf_steps( $r ), array( 'stub', 'option', 'restored', 'forward_stub', 'forward' ), 'migrate rollback, restore fails (I2): no rename back, forward' );
cmp_assert( true === $mf_g( $r, 'moved_file' ) && true === $mf_g( $r, 'stub_dir' ) && array( 'akismet/akismet.php', $mf_new, 'z/z.php' ) === $mf_g( $r, 'blogs.1.active_plugins' ) && 'rollback' === $mf_g( $mf_state( $r ), 'reason' ), 'migrate rollback, restore fails (I2): folder in biscuitwp/, stub kept, options on NEW, failed / rollback' );
cmp_assert( $mf_all_loadable( $r ), 'migrate rollback, restore fails (I2): loadable at every step' );

// The stub cannot be put in place: folder back, failed / stub, options untouched.
$r = $mf_run( array( 'blogs' => $mf_single, 'seam' => array( 'fail_place' => true ) ) );
cmp_assert( $mf_unmoved( $r ) && ! $mf_logs( $r, 'update:blog:1:active_plugins' ) && 'stub' === $mf_g( $mf_state( $r ), 'reason' ), 'migrate stub not placed: folder back, temp stub removed, no option written, failed / stub' );
// ... and the folder cannot go back either: forward (stub recreated at the old path).
$r = $mf_run( array( 'blogs' => $mf_single, 'seam' => array( 'fail_place' => true, 'fail_rename_back' => true ) ) );
cmp_assert( true === $mf_g( $r, 'moved_file' ) && true === $mf_g( $r, 'stub_dir' ) && false === $mf_g( $r, 'tmp_dir' ) && array( 'akismet/akismet.php', $mf_new, 'z/z.php' ) === $mf_g( $r, 'blogs.1.active_plugins' ) && 'rollback' === $mf_g( $mf_state( $r ), 'reason' ), 'migrate stub not placed, no way back: stub at the old path, options on NEW, failed / rollback' );
cmp_assert( true === $mf_g( $r, 'loadable' ), 'migrate stub not placed, no way back: loadable at the end' );
// The stub cannot even be built (before the rename): nothing moves.
$r = $mf_run( array( 'blogs' => $mf_single, 'seam' => array( 'fail_stub' => true ) ) );
cmp_assert( $mf_unmoved( $r ) && ! $mf_logs( $r, 'update:blog:1:active_plugins' ) && 'stub' === $mf_g( $mf_state( $r ), 'reason' ) && null === $mf_g( $r, 'blogs.1.cmp_folder_move_lock' ), 'migrate stub not built: nothing moved, failed / stub, lock released' );
// An option write fails and the folder cannot go back: stub recreated, forward.
$r = $mf_run( array( 'blogs' => $mf_single, 'fail' => array( 'blog:1:uninstall_plugins' ), 'seam' => array( 'fail_rename_back' => true ) ) );
cmp_assert( array( 'stub', 'option', 'restored', 'forward_stub', 'forward' ) === $mf_steps( $r ) && $mf_all_loadable( $r ) && true === $mf_g( $r, 'stub_dir' ), 'migrate rollback, no way back: stub recreated, forward, loadable at every step' );

// A stale temp stub of ours is replaced; anything else there stops the move untouched.
$r = $mf_run(
	array(
		'blogs'   => $mf_single,
		'prepare' => function ( $base, $plugins, $entry ) {
			@mkdir( $plugins . '/.biscuitwp-move-stub' );
			file_put_contents( $plugins . '/.biscuitwp-move-stub/notes.txt', 'not ours' );
			return $entry;
		},
	)
);
cmp_assert( true === $mf_g( $r, 'real_old' ) && false === $mf_g( $r, 'new_dir' ) && true === $mf_g( $r, 'tmp_dir' ) && 'stub' === $mf_g( $mf_state( $r ), 'reason' ), 'migrate: a foreign folder at the temp stub path stops the move and is not touched' );

// I5. Raw options: a plugin-loading filter hides akismet on this page; the stored list keeps it.
$r = $mf_run( array( 'blogs' => $mf_single, 'strip' => 'akismet/akismet.php' ) );
cmp_assert_eq( $mf_g( $r, 'blogs.1.active_plugins' ), array( 'akismet/akismet.php', $mf_new, 'z/z.php' ), 'migrate (I5): an option_active_plugins filter does not leak into the rewritten list' );
cmp_assert( 'done' === $mf_g( $mf_state( $r ), 'state' ), 'migrate (I5): the move still completes' );

// Guards: busy / refused, recorded for the result screen; quiet when not in the old folder.
$mf_busy = function ( $r, $label, $reason = 'busy' ) use ( $mf_g, $mf_unmoved, $mf_logs, $mf_state, $mf_single ) {
	cmp_assert( $mf_unmoved( $r ) && $mf_g( $r, 'blogs.1.active_plugins' ) === $mf_single[1]['active_plugins'] && $reason === $mf_g( $mf_state( $r ), 'reason' ), "migrate guard: $label -> no move, failed / $reason" );
};
$mf_busy( $mf_run( array( 'blogs' => $mf_single, 'installing' => true ) ), 'wp_installing()' );
$mf_busy( $mf_run( array( 'blogs' => $mf_single, 'did' => array( 'upgrader_process_complete' ) ) ), 'upgrader_process_complete in this request' );
$mf_lb                            = $mf_single;
$mf_lb[1]['auto_updater.lock']    = $mf_now - 10;
$mf_busy( $mf_run( array( 'blogs' => $mf_lb ) ), 'auto_updater.lock held' );
$mf_lb                            = $mf_single;
$mf_lb[1]['core_updater.lock']    = $mf_now - 10;
$mf_busy( $mf_run( array( 'blogs' => $mf_lb ) ), 'core_updater.lock held' );
$mf_msl                           = $mf_ms_blogs;
$mf_msl[1]['auto_updater.lock']   = $mf_now - 10;
$r                                = $mf_run( array( 'current_blog' => 2, 'blogs' => $mf_msl ) + $mf_ms );
cmp_assert( $mf_unmoved( $r ) && 'busy' === $mf_g( $mf_state( $r, true ), 'reason' ) && 2 === $mf_g( $r, 'blog' ), 'migrate guard (M1): the main site\'s auto_updater.lock counts from another site' );
$mf_busy(
	$mf_run(
		array(
			'blogs'   => $mf_single,
			'prepare' => function ( $base, $plugins, $entry ) {
				file_put_contents( $base . '/wp/.maintenance', "<?php \$upgrading = time();\n" );
				return $entry;
			},
		)
	),
	'.maintenance present'
);
$mf_lb                            = $mf_single;
$mf_lb[1]['cmp_folder_move_lock'] = $mf_now - 5;
$r                                = $mf_run( array( 'blogs' => $mf_lb ) );
cmp_assert( $mf_unmoved( $r ) && 'busy' === $mf_g( $mf_state( $r ), 'reason' ) && $mf_now - 5 === $mf_g( $r, 'blogs.1.cmp_folder_move_lock' ), 'migrate lock: a fresh lock (another move) -> busy, lock left alone' );
$mf_lb[1]['cmp_folder_move_lock'] = $mf_now - 301;
$r                                = $mf_run( array( 'blogs' => $mf_lb ) );
cmp_assert( true === $mf_g( $r, 'moved_file' ) && 'done' === $mf_g( $mf_state( $r ), 'state' ), 'migrate lock: a stale lock (301 s) is replaced and the move runs' );
$mf_busy( $mf_run( array( 'blogs' => $mf_single, 'fail' => array( 'add:blog:1:cmp_folder_move_lock' ) ) ), 'losing the add_option lock race' );
$r = $mf_run(
	array(
		'blogs'   => $mf_single,
		'prepare' => function ( $base, $plugins, $entry ) {
			@mkdir( $plugins . '/biscuitwp' );
			file_put_contents( $plugins . '/biscuitwp/keep.txt', 'x' );
			return $entry;
		},
	)
);
cmp_assert( true === $mf_g( $r, 'real_old' ) && 'new_exists' === $mf_g( $mf_state( $r ), 'reason' ) && $mf_g( $r, 'blogs.1.active_plugins' ) === $mf_single[1]['active_plugins'], 'migrate fail: plugins/biscuitwp exists -> failed / new_exists, nothing touched' );
$r = $mf_run(
	array(
		'blogs'   => $mf_single,
		'prepare' => function ( $base, $plugins, $entry ) {
			chmod( $plugins, 0555 );
			return $entry;
		},
		'after'   => function ( $base, $plugins ) {
			chmod( $plugins, 0777 );
		},
	)
);
$mf_busy( $r, 'plugins/ not writable', 'not_writable' );
$r = $mf_run( $mf_ms + array( 'site_count' => 1001 ) );
cmp_assert( true === $mf_g( $r, 'real_old' ) && 'sites' === $mf_g( $mf_state( $r, true ), 'reason' ) && ! $mf_logs( $r, 'update:blog:' ), 'migrate (multisite): over 1000 sites -> failed / sites' );
$r = $mf_run( $mf_ms + array( 'networks' => 2 ) );
cmp_assert( true === $mf_g( $r, 'real_old' ) && 'networks' === $mf_g( $mf_state( $r, true ), 'reason' ), 'migrate (M4): more than one network -> failed / networks' );
$r = $mf_run( array( 'blogs' => array( 1 => array( 'active_plugins' => array( 'akismet/akismet.php' ), 'auto_update_plugins' => array( $mf_old ) ) ) ) );
cmp_assert( true === $mf_g( $r, 'real_old' ) && 'not_active' === $mf_g( $mf_state( $r ), 'reason' ) && ! $mf_logs( $r, 'update:blog:1:auto' ), 'migrate: not active through an old path -> failed / not_active, nothing written' );
$mf_quiet = function ( $r, $label ) use ( $mf_g, $mf_logs, $mf_state ) {
	cmp_assert( null === $mf_state( $r ) && ! $mf_logs( $r, 'update:' ) && ! $mf_logs( $r, 'add:' ) && false === $mf_g( $r, 'new_dir' ), "migrate guard: $label -> nothing, nothing recorded" );
};
$mf_quiet(
	$mf_run(
		array(
			'blogs'   => $mf_single,
			'prepare' => function ( $base, $plugins, $entry ) {
				@mkdir( $base . '/src', 0777, true );
				rename( $plugins . '/consent-manager-pro', $base . '/src/consent-manager-pro' );
				symlink( $base . '/src/consent-manager-pro', $plugins . '/consent-manager-pro' );
				return $base . '/src/consent-manager-pro/consent-manager-pro.php';
			},
		)
	),
	'symlinked plugins/consent-manager-pro'
);
$mf_quiet(
	$mf_run(
		array(
			'blogs'   => $mf_single,
			'prepare' => function ( $base, $plugins, $entry ) {
				@mkdir( $base . '/wp/wp-content/mu-plugins/consent-manager-pro', 0777, true );
				file_put_contents( $base . '/wp/wp-content/mu-plugins/consent-manager-pro/consent-manager-pro.php', "<?php\n" );
				return $base . '/wp/wp-content/mu-plugins/consent-manager-pro/consent-manager-pro.php';
			},
		)
	),
	'loaded from mu-plugins'
);
// M3: a request that started before the move (old path, now our stub) does nothing.
$mf_stale = array(
	'blogs'   => array( 1 => array( 'active_plugins' => array( $mf_new ), 'cmp_folder_move' => array( 'state' => 'done', 'reason' => '', 'ts' => $mf_now ) ) ),
	'prepare' => function ( $base, $plugins, $entry ) use ( $mf_ok ) {
		rename( $plugins . '/consent-manager-pro', $plugins . '/biscuitwp' );
		mkdir( $plugins . '/consent-manager-pro' );
		foreach ( array( 'consent-manager-pro.php', 'biscuitwp.php' ) as $f ) {
			file_put_contents( $plugins . '/consent-manager-pro/' . $f, $mf_ok ? CMP_Migrate_Folder::stub_source() : '' );
		}
		return $entry;
	},
);
$r = $mf_run( $mf_stale );
cmp_assert( true === $mf_g( $r, 'stub_dir' ) && 'done' === $mf_g( $mf_state( $r ), 'state' ) && ! $mf_logs( $r, 'update:' ), 'migrate guard (M3): a stale request on the old path (now our stub) changes nothing' );

// C2. Page caches purged after the move, only those present.
$r = $mf_run( array( 'blogs' => $mf_single, 'purge_fns' => array( 'rocket_clean_domain', 'w3tc_flush_all' ), 'actions' => array( 'litespeed_purge_all' ) ) );
cmp_assert( $mf_logs( $r, 'purge:rocket_clean_domain' ) && $mf_logs( $r, 'purge:w3tc_flush_all' ) && $mf_logs( $r, 'do_action:litespeed_purge_all' ), 'migrate purge (C2): WP Rocket, W3TC and LiteSpeed purged when present' );
cmp_assert( ! $mf_logs( $r, 'do_action:breeze_clear_all_cache' ) && ! $mf_logs( $r, 'do_action:wpfc_clear_all_cache' ), 'migrate purge (C2): absent cache plugins are not called' );
$r = $mf_run( array( 'blogs' => $mf_single, 'purge_fns' => array( 'rocket_clean_domain' ), 'fail' => array( 'blog:1:uninstall_plugins' ) ) );
cmp_assert( ! $mf_logs( $r, 'purge:rocket_clean_domain' ), 'migrate purge: nothing purged when the move was rolled back' );

// I1. The stub is removed by a later load through biscuitwp/, at least STUB_KEEP after the move.
$mf_left = function ( $extra = null, $content = null ) use ( $mf_ok ) {
	return function ( $base, $plugins, $entry ) use ( $extra, $content, $mf_ok ) {
		rename( $plugins . '/consent-manager-pro', $plugins . '/biscuitwp' );
		mkdir( $plugins . '/consent-manager-pro' );
		foreach ( array( 'consent-manager-pro.php', 'biscuitwp.php' ) as $f ) {
			file_put_contents( $plugins . '/consent-manager-pro/' . $f, null !== $content ? $content : ( $mf_ok ? CMP_Migrate_Folder::stub_source() : '' ) );
		}
		if ( $extra ) {
			file_put_contents( $plugins . '/consent-manager-pro/' . $extra, 'x' );
		}
		return $plugins . '/biscuitwp/biscuitwp.php';
	};
};
$mf_moved = function ( $age, $active = null ) use ( $mf_now, $mf_new ) {
	return array( 1 => array( 'active_plugins' => null !== $active ? $active : array( 'akismet/akismet.php', $mf_new ), 'cmp_folder_move' => array( 'state' => 'done', 'reason' => '', 'ts' => $mf_now - $age ) ) );
};
$r = $mf_run( array( 'mode' => 'cleanup', 'legacy' => false, 'blogs' => $mf_moved( 3601 ), 'prepare' => $mf_left() ) );
cmp_assert( false === $mf_g( $r, 'old_dir' ) && true === $mf_g( $r, 'new_dir' ) && array() === $mf_g( $r, 'errors' ), 'migrate cleanup (I1): our stub removed after 3601 s when nothing points to an old path' );
$r = $mf_run( array( 'mode' => 'cleanup', 'legacy' => false, 'blogs' => $mf_moved( 60 ), 'prepare' => $mf_left() ) );
cmp_assert( true === $mf_g( $r, 'stub_dir' ), 'migrate cleanup (I1): kept within the first hour after the move' );
$r = $mf_run( array( 'mode' => 'cleanup', 'legacy' => false, 'blogs' => $mf_moved( 3601, array( $mf_sib ) ), 'prepare' => $mf_left() ) );
cmp_assert( true === $mf_g( $r, 'stub_dir' ), 'migrate cleanup: kept while an option still points to consent-manager-pro/biscuitwp.php' );
$r = $mf_run( array( 'mode' => 'cleanup', 'multisite' => true, 'legacy' => false, 'blogs' => array( 1 => array(), 2 => array( 'active_plugins' => array( $mf_old ) ) ), 'site' => array( 'active_sitewide_plugins' => array( $mf_new => 1 ), 'cmp_folder_move' => array( 'state' => 'done', 'reason' => '', 'ts' => $mf_now - 7200 ) ), 'prepare' => $mf_left() ) );
cmp_assert( true === $mf_g( $r, 'stub_dir' ), 'migrate cleanup (multisite): kept while one site still activates an old path' );
$r = $mf_run( array( 'mode' => 'cleanup', 'legacy' => false, 'blogs' => $mf_moved( 3601 ), 'prepare' => $mf_left( 'other.php' ) ) );
cmp_assert_eq( $mf_g( $r, 'old_entries' ), array( 'biscuitwp.php', 'consent-manager-pro.php', 'other.php' ), 'migrate cleanup: a folder with anything besides our stub is not touched' );
$r = $mf_run( array( 'mode' => 'cleanup', 'legacy' => false, 'blogs' => $mf_moved( 3601 ), 'prepare' => $mf_left( null, "<?php\n// BiscuitWP folder-move stub v1\n" ) ) );
cmp_assert_eq( $mf_g( $r, 'old_entries' ), array( 'biscuitwp.php', 'consent-manager-pro.php' ), 'migrate cleanup: files that are not exactly our stub are not touched' );
$mf_v2 = $mf_ok ? CMP_Migrate_Folder::stub_source( str_replace( ' * Version: ', ' * Version: 9', CMP_Migrate_Folder::header_block( (string) file_get_contents( $mf_root . '/biscuitwp.php' ) ) ) ) : '';
$r     = $mf_run( array( 'mode' => 'cleanup', 'legacy' => false, 'blogs' => $mf_moved( 3601 ), 'prepare' => $mf_left( null, $mf_v2 ) ) );
cmp_assert( '' !== $mf_v2 && false === $mf_g( $r, 'old_dir' ), 'migrate cleanup: a stub written by another version is still recognised' );
$r = $mf_run( array( 'mode' => 'cleanup', 'legacy' => true, 'blogs' => $mf_moved( 3601 ), 'prepare' => function ( $b, $p, $e ) { return $e; } ) );
cmp_assert( true === $mf_g( $r, 'real_old' ), 'migrate cleanup: never on a load from the old folder' );

// The move screen, the row link and the confirmed request.
$mf_page = function ( array $sc ) use ( $mf_run, $mf_g ) {
	return (string) $mf_g( $mf_run( array( 'mode' => 'page' ) + $sc ), 'html', '' );
};
$mf_h = $mf_page( array( 'caps' => array( 'manage_options' ) ) );
cmp_assert( false !== strpos( $mf_h, 'action="http://example.test/wp-admin/admin-post.php"' ) && false !== strpos( $mf_h, 'name="action" value="cmp_folder_move"' ) && false !== strpos( $mf_h, 'name="_wpnonce" value="nonce-cmp_folder_move"' ), 'migrate screen (single, admin): a POST form to admin-post with the action and the nonce' );
cmp_assert( false !== strpos( $mf_h, 'name="cmp_confirm" value="1" required' ) && false !== strpos( $mf_h, '>Mută pluginul în folderul biscuitwp</button>' ), 'migrate screen: a required confirmation checkbox and the button "Mută pluginul în folderul biscuitwp"' );
// E2E (b): the checkbox's accessible name is the confirmation sentence (an explicit <label for> / id pair), not its value "1".
cmp_assert( 1 === preg_match( '#<input type="checkbox" id="cmp-confirm" name="cmp_confirm" value="1" required>\s*<label for="cmp-confirm">Am înțeles: pluginul își mută folderul din plugins/consent-manager-pro în plugins/biscuitwp, fără să se dezactiveze\.</label>#u', $mf_h ), 'migrate screen (E2E b): the confirmation checkbox is labelled by the sentence through <label for="cmp-confirm">' );
cmp_assert( 1 === substr_count( $mf_h, 'id="cmp-confirm"' ), 'migrate screen (E2E b): the checkbox id is unique on the page' );
cmp_assert_eq( $mf_page( array( 'caps' => array( 'edit_posts' ) ) ), '', 'migrate screen: nothing without manage_options' );
$mf_h = $mf_page( array( 'caps' => array( 'manage_options' ), 'legacy' => false, 'prepare' => $mf_left() ) );
cmp_assert( false === strpos( $mf_h, '<form' ) && false !== strpos( $mf_h, 'Nu e nimic de mutat.' ), 'migrate screen: no button once the plugin runs from biscuitwp/' );
cmp_assert( false === strpos( $mf_page( array( 'multisite' => true, 'super' => true, 'network_admin' => false ) ), '<form' ), 'migrate screen (multisite): no button outside the network admin' );
cmp_assert( false !== strpos( $mf_page( array( 'multisite' => true, 'super' => true, 'network_admin' => true ) ), '<form' ), 'migrate screen (multisite): button for a super admin in the network admin' );
cmp_assert_eq( $mf_page( array( 'multisite' => true, 'super' => false, 'network_admin' => true, 'caps' => array( 'manage_options' ) ) ), '', 'migrate screen (multisite): nothing for a site admin who is not a super admin' );
$mf_done5 = $mf_moved( 5 );
$mf_done5[1]['cmp_folder_move']['attempt'] = 'attempt1';
$mf_h = $mf_page( array( 'caps' => array( 'manage_options' ), 'legacy' => false, 'prepare' => $mf_left(), 'get' => array( 'cmp_moved' => 'attempt1' ), 'blogs' => $mf_done5 ) );
cmp_assert( false !== strpos( $mf_h, 'notice-success' ) && false !== strpos( $mf_h, 'Golește cache-ul paginilor' ) && false !== strpos( $mf_h, 'href="http://example.test/"' ), 'migrate result: success tells to clear the page cache and links the front page' );
// E2E (a): after a successful move the result page shows only the result, not also "Nu e nimic de mutat".
cmp_assert( false === strpos( $mf_h, 'Nu e nimic de mutat.' ), 'migrate result (E2E a): no "nothing to move" note under a shown result' );
$mf_h = $mf_page( array( 'caps' => array( 'manage_options' ), 'legacy' => false, 'prepare' => $mf_left(), 'get' => array( 'cmp_moved' => 'attempt9' ), 'blogs' => array( 1 => array() ) ) );
cmp_assert( false !== strpos( $mf_h, 'Rezultatul mutării nu e încă disponibil.' ) && false === strpos( $mf_h, 'Nu e nimic de mutat.' ), 'migrate result (E2E a): no "nothing to move" note under the pending result notice either' );
$mf_h = $mf_page( array( 'caps' => array( 'manage_options' ), 'get' => array( 'cmp_moved' => 'attempt1' ), 'blogs' => array( 1 => array( 'cmp_folder_move' => array( 'state' => 'failed', 'reason' => 'not_writable', 'ts' => $mf_now, 'attempt' => 'attempt1' ) ) ) ) );
cmp_assert( false !== strpos( $mf_h, 'notice-error' ) && false !== strpos( $mf_h, 'Motiv: serverul nu permite scrierea în folderul plugins' ) && false !== strpos( $mf_h, '<form' ), 'migrate result: failure with its reason, and the button again' );
// R1-M2: the result is shown only for this attempt's token.
$mf_h = $mf_page( array( 'caps' => array( 'manage_options' ), 'get' => array( 'cmp_moved' => 'other' ), 'blogs' => array( 1 => array( 'cmp_folder_move' => array( 'state' => 'failed', 'reason' => 'busy', 'ts' => $mf_now, 'attempt' => 'attempt1' ) ) ) ) );
cmp_assert( false === strpos( $mf_h, 'Motiv:' ) && false !== strpos( $mf_h, 'Rezultatul mutării nu e încă disponibil.' ), 'migrate result (R1-M2): a result of another attempt is not shown; asks to reload' );
$r = $mf_run( array( 'blogs' => $mf_single ) );
cmp_assert( 'attempt1' === $mf_g( $mf_state( $r ), 'attempt' ), 'migrate result (R1-M2): the state records the attempt token' );
$mf_src2 = (string) file_get_contents( $mf_file );
cmp_assert( false !== strpos( $mf_src2, "self::queue( \$token );" ) && false !== strpos( $mf_src2, "wp_safe_redirect( self::page_url( array( 'cmp_moved' => \$token ) ) );" ), 'migrate result (R1-M2): the handler queues and redirects with the same token' );

// R1-I2: the result page purges the page caches once more, once per move.
$mf_pi = function ( array $state, array $get, array $caps = array( 'manage_options' ) ) use ( $mf_run ) {
	return $mf_run( array( 'mode' => 'admin_init', 'caps' => $caps, 'get' => $get, 'purge_fns' => array( 'rocket_clean_domain' ), 'actions' => array( 'litespeed_purge_all' ), 'blogs' => array( 1 => array( 'cmp_folder_move' => $state ) ) ) );
};
$mf_done = array( 'state' => 'done', 'reason' => '', 'ts' => $mf_now, 'attempt' => 'attempt1' );
$r       = $mf_pi( $mf_done, array( 'page' => 'cmp-folder-move', 'cmp_moved' => 'attempt1' ) );
cmp_assert( array( 'rocket_clean_domain', 'litespeed_purge_all' ) === $mf_g( $r, 'purge_first' ) && array() === $mf_g( $r, 'purge_again' ), 'migrate purge (R1-I2): the result page purges once, a second call does nothing' );
cmp_assert( true === $mf_g( $r, 'blogs.1.cmp_folder_move.purged' ) && 1 === count( array_filter( (array) $mf_g( $r, 'log' ), function ( $l ) { return 'purge:rocket_clean_domain' === $l; } ) ), 'migrate purge (R1-I2): flagged in the state, called exactly once' );
$r = $mf_pi( $mf_done + array( 'purged' => true ), array( 'page' => 'cmp-folder-move', 'cmp_moved' => 'attempt1' ) );
cmp_assert( array() === $mf_g( $r, 'purge_first' ), 'migrate purge (R1-I2): not again on a later load (flag kept)' );
$r = $mf_pi( array( 'state' => 'failed', 'reason' => 'busy', 'ts' => $mf_now, 'attempt' => 'attempt1' ), array( 'page' => 'cmp-folder-move', 'cmp_moved' => 'attempt1' ) );
cmp_assert( array() === $mf_g( $r, 'purge_first' ), 'migrate purge (R1-I2): nothing after a failed move' );
$r = $mf_pi( $mf_done, array( 'page' => 'cmp-folder-move', 'cmp_moved' => 'other' ) );
cmp_assert( array() === $mf_g( $r, 'purge_first' ), 'migrate purge (R1-I2): nothing for another attempt token' );
$r = $mf_pi( $mf_done, array( 'page' => 'cmp-settings', 'cmp_moved' => 'attempt1' ) );
cmp_assert( array() === $mf_g( $r, 'purge_first' ), 'migrate purge (R1-I2): only on the move screen' );
$r = $mf_pi( $mf_done, array( 'page' => 'cmp-folder-move', 'cmp_moved' => 'attempt1' ), array( 'edit_posts' ) );
cmp_assert( array() === $mf_g( $r, 'purge_first' ), 'migrate purge (R1-I2): only for a user who may move' );
cmp_assert( false !== strpos( $mf_src2, "add_action( 'admin_init', array( __CLASS__, 'purge_after_move' ) );" ), 'migrate purge (R1-I2): hooked on admin_init' );

// R2-I1: a double submit / stale form redirects with a token the state does not carry.
// Once the plugin no longer runs from the old folder and the move is done, the
// result page still shows the success (cache reminder) and the purge runs once.
$mf_after = array( 'caps' => array( 'manage_options' ), 'legacy' => false, 'prepare' => $mf_left(), 'blogs' => $mf_done5 );
$mf_h     = $mf_page( $mf_after + array( 'get' => array( 'cmp_moved' => 'attempt2' ) ) );
cmp_assert( false !== strpos( $mf_h, 'notice-success' ) && false !== strpos( $mf_h, 'Golește cache-ul paginilor' ) && false === strpos( $mf_h, 'Rezultatul mutării nu e încă disponibil.' ), 'migrate result (R2-I1): another token, but moved and done -> the success message with the cache reminder' );
$r = $mf_run( array( 'mode' => 'admin_init', 'caps' => array( 'manage_options' ), 'legacy' => false, 'prepare' => $mf_left(), 'get' => array( 'page' => 'cmp-folder-move', 'cmp_moved' => 'attempt2' ), 'purge_fns' => array( 'rocket_clean_domain' ), 'blogs' => $mf_done5 ) );
cmp_assert( array( 'rocket_clean_domain' ) === $mf_g( $r, 'purge_first' ) && array() === $mf_g( $r, 'purge_again' ), 'migrate purge (R2-I1): another token after the move -> purged once' );
// Still in the old folder (the move did not happen for this attempt): no result guessed, no purge.
$mf_h = $mf_page( array( 'caps' => array( 'manage_options' ), 'get' => array( 'cmp_moved' => 'attempt2' ), 'blogs' => $mf_done5 ) );
cmp_assert( false !== strpos( $mf_h, 'Rezultatul mutării nu e încă disponibil.' ) && false === strpos( $mf_h, 'notice-success' ), 'migrate result (R2-I1): another token while still in the old folder -> asks to reload' );
$r = $mf_pi( $mf_done, array( 'page' => 'cmp-folder-move', 'cmp_moved' => 'attempt2' ) );
cmp_assert( array() === $mf_g( $r, 'purge_first' ), 'migrate purge (R2-I1): another token while still in the old folder -> no purge' );

// R2-M1: the forward fallback moved the folder: recorded as moved, failure detail kept.
$r = $mf_run( array( 'blogs' => $mf_single, 'seam' => array( 'fail_place' => true, 'fail_rename_back' => true ) ) );
cmp_assert( 'failed' === $mf_g( $mf_state( $r ), 'state' ) && 'rollback' === $mf_g( $mf_state( $r ), 'reason' ) && true === $mf_g( $mf_state( $r ), 'moved' ), 'migrate (R2-M1): forward fallback -> failed / rollback, moved = true' );
$r = $mf_run( array( 'blogs' => $mf_single ) );
cmp_assert( true === $mf_g( $mf_state( $r ), 'moved' ), 'migrate (R2-M1): a successful move is recorded as moved' );
$r = $mf_run( array( 'blogs' => $mf_single, 'fail' => array( 'blog:1:uninstall_plugins' ) ) );
cmp_assert( false === $mf_g( $mf_state( $r ), 'moved' ), 'migrate (R2-M1): a rolled-back move is recorded as not moved' );
$mf_fwd = array( 'state' => 'failed', 'reason' => 'rollback', 'ts' => $mf_now, 'attempt' => 'attempt1', 'moved' => true );
$mf_h   = $mf_page( array( 'caps' => array( 'manage_options' ), 'legacy' => false, 'prepare' => $mf_left(), 'get' => array( 'cmp_moved' => 'attempt1' ), 'blogs' => array( 1 => array( 'cmp_folder_move' => $mf_fwd ) ) ) );
cmp_assert( false !== strpos( $mf_h, 'Golește cache-ul paginilor' ) && false !== strpos( $mf_h, 'notice-warning' ) && false !== strpos( $mf_h, 'Verifică pe ecranul Plugins că BiscuitWP e activ.' ) && false === strpos( $mf_h, 'merge în continuare din cel vechi' ), 'migrate result (R2-M1): forward -> the cache reminder plus the problem detail, not "still in the old folder"' );
$r = $mf_run( array( 'mode' => 'admin_init', 'caps' => array( 'manage_options' ), 'legacy' => false, 'prepare' => $mf_left(), 'get' => array( 'page' => 'cmp-folder-move', 'cmp_moved' => 'attempt1' ), 'purge_fns' => array( 'rocket_clean_domain' ), 'blogs' => array( 1 => array( 'cmp_folder_move' => $mf_fwd ) ) ) );
cmp_assert( array( 'rocket_clean_domain' ) === $mf_g( $r, 'purge_first' ) && array() === $mf_g( $r, 'purge_again' ), 'migrate purge (R2-M1): forward -> the result page purges once too' );

// R1-M1: a filter that reorders active_plugins on write is not a failure.
$r = $mf_run( array( 'blogs' => $mf_single, 'reorder' => true ) );
cmp_assert( 'done' === $mf_g( $mf_state( $r ), 'state' ) && true === $mf_g( $r, 'moved_file' ), 'migrate (R1-M1): a reordered write still counts as written (done, no rollback)' );
$mf_h = $mf_page( array( 'caps' => array( 'manage_options' ), 'get' => array( 'cmp_confirm_missing' => '1' ) ) );
cmp_assert( false !== strpos( $mf_h, 'Bifează confirmarea ca să muți pluginul.' ), 'migrate screen: asks for the confirmation when it was missing' );
$mf_chk = function ( array $post, $allowed, array $sc = array() ) use ( $mf_run, $mf_g ) {
	return $mf_g( $mf_run( array( 'mode' => 'check', 'post' => $post, 'allowed' => $allowed ) + $sc ), 'check' );
};
cmp_assert_eq( $mf_chk( array( 'cmp_confirm' => '1' ), false ), 'denied', 'migrate request: not allowed -> denied' );
cmp_assert_eq( $mf_chk( array(), true ), 'confirm', 'migrate request: no confirmation -> confirm' );
cmp_assert_eq( $mf_chk( array( 'cmp_confirm' => '1' ), true ), 'queued', 'migrate request: confirmed in the old folder -> queued' );
cmp_assert_eq( $mf_chk( array( 'cmp_confirm' => '1' ), true, array( 'legacy' => false, 'prepare' => $mf_left() ) ), 'not_eligible', 'migrate request: already in biscuitwp/ -> not_eligible' );
$r = $mf_run( array( 'mode' => 'row', 'caps' => array( 'manage_options' ) ) );
cmp_assert( 2 === count( (array) $mf_g( $r, 'links' ) ) && false !== strpos( (string) $mf_g( $r, 'links.1' ), 'page=cmp-folder-move' ), 'migrate row link: offered on the Plugins row in the old folder' );
$r = $mf_run( array( 'mode' => 'row', 'caps' => array( 'manage_options' ), 'legacy' => false, 'prepare' => $mf_left() ) );
cmp_assert_eq( $mf_g( $r, 'links' ), array( 'x' ), 'migrate row link: not after the move' );
$r = $mf_run( array( 'mode' => 'menu', 'caps' => array( 'manage_options' ) ) );
cmp_assert( $mf_logs( $r, 'submenu:options.php:manage_options:cmp-folder-move' ), 'migrate screen: hidden page cmp-folder-move (manage_options)' );
$r = $mf_run( array( 'mode' => 'menu', 'multisite' => true, 'network_admin' => true ) );
cmp_assert( $mf_logs( $r, 'submenu:options.php:manage_network_options:cmp-folder-move' ), 'migrate screen (multisite): network admin page (manage_network_options)' );

// The stub really loads the plugin from the new folder.
if ( $mf_ok ) {
	$mf_sb = $mf_tmp . '/stubload/plugins';
	@mkdir( $mf_sb . '/biscuitwp', 0777, true );
	@mkdir( $mf_sb . '/consent-manager-pro', 0777, true );
	copy( $mf_root . '/biscuitwp.php', $mf_sb . '/biscuitwp/biscuitwp.php' );
	copy( $mf_root . '/consent-manager-pro.php', $mf_sb . '/biscuitwp/consent-manager-pro.php' );
	@symlink( $mf_root . '/includes', $mf_sb . '/biscuitwp/includes' );
	file_put_contents( $mf_sb . '/consent-manager-pro/consent-manager-pro.php', CMP_Migrate_Folder::stub_source() );
	file_put_contents( $mf_sb . '/consent-manager-pro/biscuitwp.php', CMP_Migrate_Folder::stub_source() );
	file_put_contents(
		$mf_tmp . '/stubload/driver.php',
		'<?php
define( "ABSPATH", "/" );
define( "WP_PLUGIN_DIR", $argv[2] );
function plugin_basename( $f ) { $p = WP_PLUGIN_DIR . "/"; return 0 === strpos( $f, $p ) ? substr( $f, strlen( $p ) ) : basename( dirname( $f ) ) . "/" . basename( $f ); }
function plugin_dir_path( $f ) { return dirname( $f ) . "/"; }
function plugin_dir_url( $f ) { return "http://example.test/" . basename( dirname( $f ) ) . "/"; }
function register_activation_hook( $f, $cb ) {}
function register_deactivation_hook( $f, $cb ) {}
function add_action( $h, $cb, $p = 10, $a = 1 ) {}
require $argv[1];
echo json_encode( array( "entry" => CMP_ENTRY, "path" => CMP_PATH, "basename" => CMP_Plugin::basename() ) );
'
	);
	$mf_sbp = realpath( $mf_sb );
	foreach ( array( 'consent-manager-pro.php', 'biscuitwp.php' ) as $mf_f ) {
		$mf_out = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $mf_tmp . '/stubload/driver.php' ) . ' ' . escapeshellarg( $mf_sbp . '/consent-manager-pro/' . $mf_f ) . ' ' . escapeshellarg( $mf_sbp ) . ' 2>&1' );
		$mf_res = json_decode( (string) $mf_out, true );
		cmp_assert(
			is_array( $mf_res ) && $mf_sbp . '/biscuitwp/consent-manager-pro.php' === $mf_res['entry'] && $mf_sbp . '/biscuitwp/' === $mf_res['path'] && 'biscuitwp/consent-manager-pro.php' === $mf_res['basename'],
			"migrate stub ($mf_f): loading it boots the plugin from biscuitwp/ (not the old folder, so no second move)" . ( is_array( $mf_res ) ? '' : ': ' . substr( (string) $mf_out, 0, 300 ) )
		);
	}
}

// R1-I3: a stub whose target is gone returns silently (no fatal).
if ( $mf_ok ) {
	$mf_sb2 = $mf_tmp . '/stubmissing/plugins';
	@mkdir( $mf_sb2 . '/consent-manager-pro', 0777, true );
	file_put_contents( $mf_sb2 . '/consent-manager-pro/consent-manager-pro.php', CMP_Migrate_Folder::stub_source() );
	file_put_contents( $mf_tmp . '/stubmissing/driver.php', '<?php error_reporting( E_ALL ); define( "ABSPATH", "/" ); require $argv[1]; echo json_encode( array( "ok" => true, "loaded" => defined( "CMP_VERSION" ), "leak" => isset( $cmp_stub_target ) ) );' );
	$mf_out = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $mf_tmp . '/stubmissing/driver.php' ) . ' ' . escapeshellarg( $mf_sb2 . '/consent-manager-pro/consent-manager-pro.php' ) . ' 2>&1' );
	cmp_assert_eq( json_decode( $mf_out, true ), array( 'ok' => true, 'loaded' => false, 'leak' => false ), 'migrate stub (R1-I3): target missing -> loads nothing, no error, no leaked variable' );
}

// Guide paragraph and translations.
$mf_guide = (string) file_get_contents( $mf_root . '/templates/admin/guide.php' );
cmp_assert( false !== strpos( $mf_guide, 'Mută pluginul în folderul biscuitwp' ) && false === strpos( $mf_guide, 'își mută singur folderul' ), 'migrate guide: the update section explains the button, no automatic move' );
foreach ( array( 'en_US', 'ru_RU' ) as $mf_loc ) {
	$mf_po = str_replace( "\"\n\"", '', (string) @file_get_contents( $mf_root . '/languages/biscuitwp-' . $mf_loc . '.po' ) );
	cmp_assert( 1 === preg_match( '/msgid "Mută pluginul în folderul biscuitwp"\nmsgstr "[^"]+"/', $mf_po ), "migrate i18n: button translated ($mf_loc)" );
}

// Cleanup.
if ( is_dir( $mf_tmp ) ) {
	shell_exec( 'chmod -R u+w ' . escapeshellarg( $mf_tmp ) . ' 2>/dev/null; rm -rf ' . escapeshellarg( $mf_tmp ) );
}
