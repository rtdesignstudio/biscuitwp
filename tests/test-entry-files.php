<?php
/**
 * Two entry files: biscuitwp.php is the real plugin file (header + bootstrap),
 * consent-manager-pro.php is the legacy loader (same plugin header) for sites whose active
 * path is still consent-manager-pro/consent-manager-pro.php.
 */

$cmp_ef_root   = dirname( __DIR__ );
$cmp_ef_main   = (string) @file_get_contents( $cmp_ef_root . '/biscuitwp.php' );
$cmp_ef_legacy = (string) @file_get_contents( $cmp_ef_root . '/consent-manager-pro.php' );

// biscuitwp.php: the plugin header, today's values.
cmp_assert( '' !== $cmp_ef_main, 'entry: biscuitwp.php exists' );
cmp_assert( 1 === preg_match( '/^ \* Plugin Name: BiscuitWP$/m', $cmp_ef_main ), 'entry: biscuitwp.php has Plugin Name: BiscuitWP' );
cmp_assert( 1 === preg_match( '/^ \* Text Domain: biscuitwp$/m', $cmp_ef_main ), 'entry: biscuitwp.php has Text Domain: biscuitwp' );
cmp_assert( 1 === preg_match( '/^ \* Update URI: https:\/\/biscuitwp\.rt\.md\/$/m', $cmp_ef_main ), 'entry: biscuitwp.php has Update URI: https://biscuitwp.rt.md/' );
cmp_assert( 1 === preg_match( '/^ \* Version: \S+$/m', $cmp_ef_main ), 'entry: biscuitwp.php has a Version header' );
cmp_assert( false !== strpos( $cmp_ef_main, "define( 'CMP_VERSION'," ), 'entry: biscuitwp.php defines CMP_VERSION' );
cmp_assert( false !== strpos( $cmp_ef_main, "if ( ! defined( 'CMP_ENTRY' ) ) {" ) && false !== strpos( $cmp_ef_main, "define( 'CMP_ENTRY', __FILE__ );" ), 'entry: biscuitwp.php defines CMP_ENTRY only if not yet defined' );
cmp_assert( false !== strpos( $cmp_ef_main, "register_activation_hook( CMP_ENTRY, array( 'CMP_Plugin', 'activate' ) );" ), 'entry: activation hook on the loaded entry file (CMP_ENTRY)' );
cmp_assert( false !== strpos( $cmp_ef_main, "register_deactivation_hook( CMP_ENTRY, array( 'CMP_Plugin', 'deactivate' ) );" ), 'entry: deactivation hook on the loaded entry file (CMP_ENTRY)' );

// consent-manager-pro.php: legacy loader with the SAME plugin header as biscuitwp.php
// (WordPress deactivates an active plugin file without a header: validate_plugin()).
cmp_assert( '' !== $cmp_ef_legacy, 'entry: consent-manager-pro.php exists' );
$cmp_ef_hdr = function ( $src ) {
	return 1 === preg_match( '#\A<\?php\s*\n(/\*\*\n.*?\*/)#s', $src, $m ) && false !== strpos( $m[1], ' * Plugin Name: ' ) ? $m[1] : '';
};
cmp_assert( '' !== $cmp_ef_hdr( $cmp_ef_main ), 'entry: biscuitwp.php opens with its plugin header docblock' );
cmp_assert( '' !== $cmp_ef_hdr( $cmp_ef_legacy ) && $cmp_ef_hdr( $cmp_ef_main ) === $cmp_ef_hdr( $cmp_ef_legacy ), 'entry: consent-manager-pro.php carries exactly the same plugin header as biscuitwp.php' );
foreach ( array( 'Plugin Name: BiscuitWP', 'Description: ', 'Version: ', 'Requires PHP: ', 'Author: ', 'License: ', 'Text Domain: biscuitwp', 'Domain Path: /languages', 'Update URI: ' ) as $cmp_ef_h ) {
	cmp_assert( false !== strpos( $cmp_ef_hdr( $cmp_ef_legacy ), ' * ' . $cmp_ef_h ), "entry: legacy header has $cmp_ef_h" );
}
$cmp_ef_rel = (string) @file_get_contents( $cmp_ef_root . '/bin/release.sh' );
cmp_assert( false !== strpos( $cmp_ef_rel, 'LEGACY_FILE="consent-manager-pro.php"' ) && false !== strpos( $cmp_ef_rel, 'plugin headers of $MAIN_FILE and $LEGACY_FILE differ' ), 'release: refuses to build when the two plugin headers differ' );
$cmp_ef_p_entry  = strpos( $cmp_ef_legacy, "define( 'CMP_ENTRY', __FILE__ );" );
$cmp_ef_p_req    = strpos( $cmp_ef_legacy, "require_once __DIR__ . '/biscuitwp.php';" );
cmp_assert( false !== $cmp_ef_p_entry && false !== $cmp_ef_p_req, 'entry: legacy loader defines CMP_ENTRY and requires biscuitwp.php' );
cmp_assert( false !== $cmp_ef_p_req && $cmp_ef_p_entry < $cmp_ef_p_req, 'entry: legacy loader defines CMP_ENTRY before requiring biscuitwp.php' );
// M1 (final review): the unused CMP_LEGACY_LOAD flag is gone; CMP_ENTRY says which file loaded.
cmp_assert( false === strpos( $cmp_ef_legacy, 'CMP_LEGACY_LOAD' ) && false === strpos( $cmp_ef_main, 'CMP_LEGACY_LOAD' ), 'entry: no CMP_LEGACY_LOAD constant (dead code removed)' );
cmp_assert( false === strpos( $cmp_ef_legacy, "define( 'CMP_VERSION'" ) && false === strpos( $cmp_ef_legacy, 'CMP_PATH' ), 'entry: legacy loader carries no plugin logic (does not define CMP_VERSION or use CMP_PATH)' );

// No hardcoded main file under includes/.
$cmp_ef_hits = array();
$cmp_ef_it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $cmp_ef_root . '/includes', FilesystemIterator::SKIP_DOTS ) );
foreach ( $cmp_ef_it as $cmp_ef_f ) {
	if ( 'php' === $cmp_ef_f->getExtension() && false !== strpos( (string) file_get_contents( $cmp_ef_f->getPathname() ), "'consent-manager-pro.php'" ) ) {
		$cmp_ef_hits[] = substr( $cmp_ef_f->getPathname(), strlen( $cmp_ef_root ) + 1 );
	}
}
cmp_assert_eq( $cmp_ef_hits, array(), "entry: no PHP file under includes/ contains 'consent-manager-pro.php'" );
$cmp_ef_pb = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $cmp_ef_root . '/includes', FilesystemIterator::SKIP_DOTS ) ) as $cmp_ef_f ) {
	$cmp_ef_c = 'php' === $cmp_ef_f->getExtension() ? (string) file_get_contents( $cmp_ef_f->getPathname() ) : '';
	if ( preg_match( "/plugin_basename\(\s*CMP_PATH/", $cmp_ef_c ) || preg_match( "/CMP_PATH\s*\.\s*'[^']*consent-manager-pro\.php'/", $cmp_ef_c ) ) {
		$cmp_ef_pb[] = substr( $cmp_ef_f->getPathname(), strlen( $cmp_ef_root ) + 1 );
	}
}
cmp_assert_eq( $cmp_ef_pb, array(), 'entry: no plugin_basename( CMP_PATH ... ) and no CMP_PATH . \'...consent-manager-pro.php\' under includes/ (use CMP_Plugin::basename())' );

// CMP_Plugin::basename() for both entry paths, in a child process (constants
// are per process). Each entry file is copied into a fake plugins/<folder>/
// with includes/ symlinked to the real one; WordPress functions are stubbed.
// realpath(): __FILE__ in the child is resolved (macOS /var -> /private/var).
$cmp_ef_tmp = realpath( sys_get_temp_dir() ) . '/cmp-entry-' . getmypid() . '-' . mt_rand();
@mkdir( $cmp_ef_tmp . '/plugins', 0777, true );
file_put_contents(
	$cmp_ef_tmp . '/driver.php',
	'<?php
define( "ABSPATH", "/" );
function plugin_basename( $f ) { return basename( dirname( $f ) ) . "/" . basename( $f ); }
function plugin_dir_path( $f ) { return dirname( $f ) . "/"; }
function plugin_dir_url( $f ) { return "http://example.test/" . basename( dirname( $f ) ) . "/"; }
$GLOBALS["cmp_ef_hooks"] = array();
function register_activation_hook( $f, $cb ) { $GLOBALS["cmp_ef_hooks"]["activate"] = $f; }
function register_deactivation_hook( $f, $cb ) { $GLOBALS["cmp_ef_hooks"]["deactivate"] = $f; }
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS["cmp_ef_hooks"]["action:" . $h] = is_array( $cb ) ? implode( "::", $cb ) : $cb; }
require $argv[1];
if ( ! class_exists( "CMP_Updater" ) ) {
	require_once CMP_PATH . "includes/modules/class-updater.php";
}
echo json_encode( array(
	"updater_file" => CMP_Updater::file(),
	"basename"  => CMP_Plugin::basename(),
	"main_file" => CMP_Plugin::main_file(),
	"legacy"    => defined( "CMP_LEGACY_LOAD" ) && true === CMP_LEGACY_LOAD,
	"path"      => CMP_PATH,
	"version"   => CMP_VERSION,
	"hooks"     => $GLOBALS["cmp_ef_hooks"],
) );
'
);
$cmp_ef_run = function ( $folder, $copy ) use ( $cmp_ef_tmp, $cmp_ef_root ) {
	$dir = $cmp_ef_tmp . '/plugins/' . $folder;
	@mkdir( $dir, 0777, true );
	foreach ( $copy as $f ) {
		copy( $cmp_ef_root . '/' . $f, $dir . '/' . $f );
	}
	@symlink( $cmp_ef_root . '/includes', $dir . '/includes' );
	$entry = $dir . '/' . end( $copy );
	$out   = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $cmp_ef_tmp . '/driver.php' ) . ' ' . escapeshellarg( $entry ) . ' 2>&1' );
	$res   = json_decode( (string) $out, true );
	return array( is_array( $res ) ? $res : array(), $entry, $dir . '/', (string) $out );
};

if ( file_exists( $cmp_ef_root . '/biscuitwp.php' ) ) {
	list( $cmp_ef_new, $cmp_ef_new_entry, $cmp_ef_new_dir, $cmp_ef_new_out ) = $cmp_ef_run( 'biscuitwp', array( 'biscuitwp.php' ) );
	list( $cmp_ef_old, $cmp_ef_old_entry, $cmp_ef_old_dir, $cmp_ef_old_out ) = $cmp_ef_run( 'consent-manager-pro', array( 'biscuitwp.php', 'consent-manager-pro.php' ) );
} else {
	$cmp_ef_new = $cmp_ef_old = array();
	$cmp_ef_new_entry = $cmp_ef_old_entry = $cmp_ef_new_dir = $cmp_ef_old_dir = $cmp_ef_new_out = $cmp_ef_old_out = '';
}
$cmp_ef_get = function ( $a, $k ) {
	return isset( $a[ $k ] ) ? $a[ $k ] : null;
};

cmp_assert_eq( $cmp_ef_get( $cmp_ef_new, 'basename' ), 'biscuitwp/biscuitwp.php', 'entry: CMP_Plugin::basename() is biscuitwp/biscuitwp.php when loaded through biscuitwp.php' . ( $cmp_ef_new ? '' : ': ' . substr( $cmp_ef_new_out, 0, 300 ) ) );
cmp_assert_eq( $cmp_ef_get( $cmp_ef_new, 'main_file' ), $cmp_ef_new_entry, 'entry: CMP_Plugin::main_file() is biscuitwp.php when loaded through it' );
cmp_assert_eq( $cmp_ef_get( $cmp_ef_new, 'legacy' ), false, 'entry: CMP_LEGACY_LOAD never set (biscuitwp.php)' );
cmp_assert_eq( $cmp_ef_get( $cmp_ef_new, 'path' ), $cmp_ef_new_dir, 'entry: CMP_PATH is the plugin folder (biscuitwp path)' );
cmp_assert_eq( $cmp_ef_get( $cmp_ef_new, 'hooks' ), array( 'activate' => $cmp_ef_new_entry, 'deactivate' => $cmp_ef_new_entry, 'action:plugins_loaded' => 'CMP_Plugin::instance' ), 'entry: hooks registered on biscuitwp.php, plugins_loaded boots CMP_Plugin' );

cmp_assert_eq( $cmp_ef_get( $cmp_ef_old, 'basename' ), 'consent-manager-pro/consent-manager-pro.php', 'entry: CMP_Plugin::basename() is consent-manager-pro/consent-manager-pro.php when loaded through the legacy loader' . ( $cmp_ef_old ? '' : ': ' . substr( $cmp_ef_old_out, 0, 300 ) ) );
cmp_assert_eq( $cmp_ef_get( $cmp_ef_old, 'main_file' ), $cmp_ef_old_entry, 'entry: CMP_Plugin::main_file() is the legacy loader when loaded through it' );
cmp_assert_eq( $cmp_ef_get( $cmp_ef_old, 'legacy' ), false, 'entry: CMP_LEGACY_LOAD never set (legacy loader)' );
cmp_assert_eq( $cmp_ef_get( $cmp_ef_old, 'path' ), $cmp_ef_old_dir, 'entry: CMP_PATH is the plugin folder (legacy path)' );
cmp_assert_eq( $cmp_ef_get( $cmp_ef_old, 'hooks' ), array( 'activate' => $cmp_ef_old_entry, 'deactivate' => $cmp_ef_old_entry, 'action:plugins_loaded' => 'CMP_Plugin::instance' ), 'entry: hooks registered on the legacy loader (the file WordPress loaded)' );
cmp_assert_eq( $cmp_ef_get( $cmp_ef_new, 'updater_file' ), 'biscuitwp/biscuitwp.php', 'entry: CMP_Updater::file() is the active basename (biscuitwp/biscuitwp.php)' );
cmp_assert_eq( $cmp_ef_get( $cmp_ef_old, 'updater_file' ), 'consent-manager-pro/consent-manager-pro.php', 'entry: CMP_Updater::file() is the active basename (legacy consent-manager-pro/consent-manager-pro.php)' );
cmp_assert( null !== $cmp_ef_get( $cmp_ef_new, 'version' ) && $cmp_ef_get( $cmp_ef_new, 'version' ) === $cmp_ef_get( $cmp_ef_old, 'version' ), 'entry: same CMP_VERSION through both entry paths' );

// Callers use the helper.
$cmp_ef_plugin_src = (string) file_get_contents( $cmp_ef_root . '/includes/class-plugin.php' );
$cmp_ef_admin_src  = (string) file_get_contents( $cmp_ef_root . '/includes/admin/class-admin.php' );
cmp_assert( false !== strpos( $cmp_ef_plugin_src, 'is_plugin_active_for_network( CMP_Plugin::basename() )' ), 'entry: on_new_site checks network activation with CMP_Plugin::basename()' );
cmp_assert( false !== strpos( $cmp_ef_admin_src, "add_filter( 'plugin_action_links_' . CMP_Plugin::basename(), array( \$this, 'action_links' ) );" ), 'entry: action links filter on CMP_Plugin::basename()' );
cmp_assert( false !== strpos( $cmp_ef_admin_src, "add_filter( 'network_admin_plugin_action_links_' . CMP_Plugin::basename(), array( \$this, 'network_action_links' ) );" ), 'entry: network action links filter on CMP_Plugin::basename()' );

// Both entry files in one request (multisite: one network-active, the other
// site-active), in each order: the first one wins, the second is a no-op, with
// no warnings and one consistent CMP_ENTRY.
file_put_contents(
	$cmp_ef_tmp . '/driver2.php',
	'<?php
error_reporting( E_ALL );
$GLOBALS["cmp_ef_errors"] = array();
set_error_handler( function ( $no, $str ) { $GLOBALS["cmp_ef_errors"][] = $str; return true; } );
define( "ABSPATH", "/" );
function plugin_basename( $f ) { return basename( dirname( $f ) ) . "/" . basename( $f ); }
function plugin_dir_path( $f ) { return dirname( $f ) . "/"; }
function plugin_dir_url( $f ) { return "http://example.test/" . basename( dirname( $f ) ) . "/"; }
$GLOBALS["cmp_ef_calls"] = array();
function register_activation_hook( $f, $cb ) { $GLOBALS["cmp_ef_calls"][] = "activate:" . $f; }
function register_deactivation_hook( $f, $cb ) { $GLOBALS["cmp_ef_calls"][] = "deactivate:" . $f; }
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS["cmp_ef_calls"][] = "action:" . $h; }
include_once $argv[1];
include_once $argv[2];
echo json_encode( array(
	"errors"   => $GLOBALS["cmp_ef_errors"],
	"calls"    => $GLOBALS["cmp_ef_calls"],
	"basename" => CMP_Plugin::basename(),
	"entry"    => CMP_ENTRY,
	"legacy"   => defined( "CMP_LEGACY_LOAD" ) ? CMP_LEGACY_LOAD : null,
) );
'
);
$cmp_ef_dbl = function ( $first, $second ) use ( $cmp_ef_tmp ) {
	$dir = $cmp_ef_tmp . '/plugins/consent-manager-pro/';
	$out = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $cmp_ef_tmp . '/driver2.php' ) . ' ' . escapeshellarg( $dir . $first ) . ' ' . escapeshellarg( $dir . $second ) . ' 2>&1' );
	$res = json_decode( (string) $out, true );
	return array( is_array( $res ) ? $res : array(), $dir, (string) $out );
};
if ( is_file( $cmp_ef_tmp . '/plugins/consent-manager-pro/consent-manager-pro.php' ) ) {
	list( $cmp_ef_d1, $cmp_ef_dir, $cmp_ef_d1_out ) = $cmp_ef_dbl( 'consent-manager-pro.php', 'biscuitwp.php' );
	cmp_assert_eq( $cmp_ef_get( $cmp_ef_d1, 'errors' ), array(), 'double load (legacy first): no warnings or notices' . ( $cmp_ef_d1 ? '' : ': ' . substr( $cmp_ef_d1_out, 0, 300 ) ) );
	cmp_assert_eq( $cmp_ef_get( $cmp_ef_d1, 'calls' ), array( 'activate:' . $cmp_ef_dir . 'consent-manager-pro.php', 'deactivate:' . $cmp_ef_dir . 'consent-manager-pro.php', 'action:plugins_loaded' ), 'double load (legacy first): hooks registered once, on the legacy loader' );
	cmp_assert( 'consent-manager-pro/consent-manager-pro.php' === $cmp_ef_get( $cmp_ef_d1, 'basename' ) && $cmp_ef_dir . 'consent-manager-pro.php' === $cmp_ef_get( $cmp_ef_d1, 'entry' ) && null === $cmp_ef_get( $cmp_ef_d1, 'legacy' ), 'double load (legacy first): legacy state kept (CMP_ENTRY legacy)' );

	list( $cmp_ef_d2, $cmp_ef_dir, $cmp_ef_d2_out ) = $cmp_ef_dbl( 'biscuitwp.php', 'consent-manager-pro.php' );
	cmp_assert_eq( $cmp_ef_get( $cmp_ef_d2, 'errors' ), array(), 'double load (biscuitwp first): no warnings or notices' . ( $cmp_ef_d2 ? '' : ': ' . substr( $cmp_ef_d2_out, 0, 300 ) ) );
	cmp_assert_eq( $cmp_ef_get( $cmp_ef_d2, 'calls' ), array( 'activate:' . $cmp_ef_dir . 'biscuitwp.php', 'deactivate:' . $cmp_ef_dir . 'biscuitwp.php', 'action:plugins_loaded' ), 'double load (biscuitwp first): hooks registered once, on biscuitwp.php' );
	cmp_assert( 'consent-manager-pro/biscuitwp.php' === $cmp_ef_get( $cmp_ef_d2, 'basename' ) && $cmp_ef_dir . 'biscuitwp.php' === $cmp_ef_get( $cmp_ef_d2, 'entry' ) && null === $cmp_ef_get( $cmp_ef_d2, 'legacy' ), 'double load (biscuitwp first): biscuitwp state' );

	// Legacy loader in consent-manager-pro/, then a separately installed biscuitwp/biscuitwp.php.
	$cmp_ef_out3 = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $cmp_ef_tmp . '/driver2.php' ) . ' ' . escapeshellarg( $cmp_ef_dir . 'consent-manager-pro.php' ) . ' ' . escapeshellarg( $cmp_ef_tmp . '/plugins/biscuitwp/biscuitwp.php' ) . ' 2>&1' );
	$cmp_ef_d3   = json_decode( (string) $cmp_ef_out3, true );
	$cmp_ef_d3   = is_array( $cmp_ef_d3 ) ? $cmp_ef_d3 : array();
	cmp_assert_eq( $cmp_ef_get( $cmp_ef_d3, 'errors' ), array(), 'double load (legacy, then biscuitwp/ from another folder): no warnings' . ( $cmp_ef_d3 ? '' : ': ' . substr( (string) $cmp_ef_out3, 0, 300 ) ) );
	cmp_assert( 3 === count( (array) $cmp_ef_get( $cmp_ef_d3, 'calls' ) ) && 'consent-manager-pro/consent-manager-pro.php' === $cmp_ef_get( $cmp_ef_d3, 'basename' ) && null === $cmp_ef_get( $cmp_ef_d3, 'legacy' ), 'double load (legacy, then biscuitwp/ from another folder): legacy state kept, hooks once' );
} else {
	cmp_assert( false, 'double load: legacy fixture folder missing' );
}
cmp_assert( 1 === preg_match( "/defined\( 'ABSPATH' \).*?\n\}\s*\n(?:\s*\/\/[^\n]*\n)*if \( defined\( 'CMP_VERSION' \) \) \{\s*return;\s*\}/s", $cmp_ef_legacy ), 'entry: legacy loader returns early when CMP_VERSION is already defined (before any define)' );
cmp_assert( false !== ( $cmp_ef_g = strpos( $cmp_ef_main, "if ( defined( 'CMP_VERSION' ) ) {" ) ) && $cmp_ef_g < strpos( $cmp_ef_main, "define( 'CMP_VERSION'," ), 'entry: biscuitwp.php returns early when CMP_VERSION is already defined (before its defines)' );

// Plugins screen: both entry files carry the header, so WordPress lists two rows per
// folder; the inactive one would offer Delete (removing the active plugin's folder)
// and Activate (a second load). CMP_Plugin::fix_plugin_rows() keeps only the active one.
if ( ! class_exists( 'CMP_Plugin' ) ) {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	require_once $cmp_ef_root . '/includes/class-plugin.php';
}
$cmp_ef_row  = array( 'Name' => 'BiscuitWP', 'Version' => '1.0.0', 'TextDomain' => 'biscuitwp' );
$cmp_ef_fix  = function ( array $rows, array $active, $loaded = '' ) {
	return method_exists( 'CMP_Plugin', 'fix_plugin_rows' ) ? CMP_Plugin::fix_plugin_rows( $rows, $active, $loaded ) : null;
};
// Both entry files in one folder, either folder: the non-active sibling is hidden,
// the row of the active one stays in place.
foreach ( array( 'consent-manager-pro', 'biscuitwp' ) as $cmp_ef_dir ) {
	$cmp_ef_rows = array(
		'akismet/akismet.php'                      => array( 'Name' => 'Akismet' ),
		$cmp_ef_dir . '/biscuitwp.php'             => $cmp_ef_row,
		$cmp_ef_dir . '/consent-manager-pro.php'   => $cmp_ef_row,
		'hello.php'                                => array( 'Name' => 'Hello Dolly' ),
	);
	foreach ( array( 'consent-manager-pro.php', 'biscuitwp.php' ) as $cmp_ef_act ) {
		$cmp_ef_a   = $cmp_ef_dir . '/' . $cmp_ef_act;
		$cmp_ef_out = $cmp_ef_fix( $cmp_ef_rows, array( 'akismet/akismet.php', $cmp_ef_a ), $cmp_ef_a );
		cmp_assert_eq( is_array( $cmp_ef_out ) ? array_keys( $cmp_ef_out ) : null, array( 'akismet/akismet.php', $cmp_ef_a, 'hello.php' ), "plugin rows ($cmp_ef_a active): only the active entry file is listed, in place" );
	}
	// I4 (final review): neither file active. In consent-manager-pro/ the row is the
	// historical active path (the one auto_update_plugins and uninstall_plugins name),
	// so a deactivate + reactivate keeps the auto-update setting; in biscuitwp/ it is biscuitwp.php.
	$cmp_ef_idle = 'consent-manager-pro' === $cmp_ef_dir ? 'consent-manager-pro/consent-manager-pro.php' : 'biscuitwp/biscuitwp.php';
	$cmp_ef_out  = $cmp_ef_fix( $cmp_ef_rows, array( 'akismet/akismet.php' ) );
	cmp_assert_eq( is_array( $cmp_ef_out ) ? array_keys( $cmp_ef_out ) : null, array( 'akismet/akismet.php', $cmp_ef_idle, 'hello.php' ), "plugin rows ($cmp_ef_dir, inactive): only $cmp_ef_idle is listed" );
}
// I4: the same with the rows in the other order (get_plugins() sorts by name, not by file).
$cmp_ef_rev = array( 'consent-manager-pro/consent-manager-pro.php' => $cmp_ef_row, 'consent-manager-pro/biscuitwp.php' => $cmp_ef_row );
cmp_assert_eq( array_keys( (array) $cmp_ef_fix( $cmp_ef_rev, array() ) ), array( 'consent-manager-pro/consent-manager-pro.php' ), 'plugin rows (I4, unmoved, inactive, other order): consent-manager-pro/consent-manager-pro.php is listed' );
// I4: a consent-manager-pro/ folder holding only an inactive consent-manager-pro.php (not
// flagged as the stub) still shows nothing: the legacy row needs both entry files.
cmp_assert_eq( array_keys( (array) $cmp_ef_fix( array( 'consent-manager-pro/consent-manager-pro.php' => $cmp_ef_row ), array() ) ), array(), 'plugin rows (I4): a lone inactive consent-manager-pro.php stays hidden' );
// Both siblings active (multisite: one network-wide, one per site): the loaded one stays.
$cmp_ef_both = array( 'consent-manager-pro/biscuitwp.php' => $cmp_ef_row, 'consent-manager-pro/consent-manager-pro.php' => $cmp_ef_row );
cmp_assert_eq( array_keys( (array) $cmp_ef_fix( $cmp_ef_both, array( 'consent-manager-pro/biscuitwp.php', 'consent-manager-pro/consent-manager-pro.php' ), 'consent-manager-pro/consent-manager-pro.php' ) ), array( 'consent-manager-pro/consent-manager-pro.php' ), 'plugin rows (both siblings active): the loaded one is listed' );
// Moved site with a leftover stub folder (consent-manager-pro/ holding only the stub): hidden while inactive.
$cmp_ef_moved = array(
	'consent-manager-pro/consent-manager-pro.php' => $cmp_ef_row,
	'biscuitwp/biscuitwp.php'                     => $cmp_ef_row,
	'biscuitwp/consent-manager-pro.php'           => $cmp_ef_row,
);
cmp_assert_eq( array_keys( (array) $cmp_ef_fix( $cmp_ef_moved, array( 'biscuitwp/biscuitwp.php' ), 'biscuitwp/biscuitwp.php' ) ), array( 'biscuitwp/biscuitwp.php' ), 'plugin rows (moved, leftover stub folder): only biscuitwp/biscuitwp.php is listed' );
cmp_assert_eq( array_keys( (array) $cmp_ef_fix( $cmp_ef_moved, array( 'consent-manager-pro/consent-manager-pro.php' ), 'biscuitwp/consent-manager-pro.php' ) ), array( 'consent-manager-pro/consent-manager-pro.php', 'biscuitwp/biscuitwp.php' ), 'plugin rows (stub path active during the move): the active stub path stays, the other folder shows biscuitwp.php' );
// R1-I3: after the move, plugins/consent-manager-pro holds the two-file stub (both
// headered). Recognised as our stub, it is grouped with biscuitwp/: exactly one row.
$cmp_ef_layout = array(
	'akismet/akismet.php'                         => array( 'Name' => 'Akismet' ),
	'biscuitwp/biscuitwp.php'                     => $cmp_ef_row,
	'biscuitwp/consent-manager-pro.php'           => $cmp_ef_row,
	'consent-manager-pro/biscuitwp.php'           => $cmp_ef_row,
	'consent-manager-pro/consent-manager-pro.php' => $cmp_ef_row,
);
$cmp_ef_out = method_exists( 'CMP_Plugin', 'fix_plugin_rows' ) ? CMP_Plugin::fix_plugin_rows( $cmp_ef_layout, array( 'akismet/akismet.php', 'biscuitwp/biscuitwp.php' ), 'biscuitwp/biscuitwp.php', array( 'consent-manager-pro' ) ) : null;
cmp_assert_eq( is_array( $cmp_ef_out ) ? array_keys( $cmp_ef_out ) : null, array( 'akismet/akismet.php', 'biscuitwp/biscuitwp.php' ), 'plugin rows (R1-I3, moved with the stub folder): exactly one BiscuitWP row, biscuitwp/biscuitwp.php' );
// A stale write put the old path back (active through the stub): the stub row stays, biscuitwp/ is not offered as an inactive row.
$cmp_ef_out = method_exists( 'CMP_Plugin', 'fix_plugin_rows' ) ? CMP_Plugin::fix_plugin_rows( $cmp_ef_layout, array( 'consent-manager-pro/consent-manager-pro.php' ), 'biscuitwp/consent-manager-pro.php', array( 'consent-manager-pro' ) ) : null;
cmp_assert_eq( is_array( $cmp_ef_out ) ? array_keys( $cmp_ef_out ) : null, array( 'akismet/akismet.php', 'consent-manager-pro/consent-manager-pro.php' ), 'plugin rows (R1-I3, old path active through the stub): one row, the active stub path; the real folder is not offered for deletion' );
// Without the stub flag (consent-manager-pro/ is a real, unmoved copy) both folders keep their own row.
$cmp_ef_out = method_exists( 'CMP_Plugin', 'fix_plugin_rows' ) ? CMP_Plugin::fix_plugin_rows( $cmp_ef_layout, array( 'biscuitwp/biscuitwp.php' ), 'biscuitwp/biscuitwp.php' ) : null;
cmp_assert_eq( is_array( $cmp_ef_out ) ? array_keys( $cmp_ef_out ) : null, array( 'akismet/akismet.php', 'biscuitwp/biscuitwp.php', 'consent-manager-pro/consent-manager-pro.php' ), 'plugin rows: a folder that is not our stub keeps its own row (I4: the historical path)' );
cmp_assert( false !== strpos( $cmp_ef_plugin_src, "CMP_Migrate_Folder::is_our_stub_dir( WP_PLUGIN_DIR . '/' . CMP_Migrate_Folder::OLD_DIR )" ), 'plugin rows (R1-I3): the filter flags plugins/consent-manager-pro when it is exactly our stub' );
// Not ours: an old 0.4.x copy (its own text domain) and other plugins are never touched.
$cmp_ef_old04 = array( 'consent-manager-pro/consent-manager-pro.php' => array( 'Name' => 'Consent Manager Pro', 'TextDomain' => 'consent-manager-pro' ), 'x/biscuitwp.php' => array( 'Name' => 'Other', 'TextDomain' => 'other' ) );
cmp_assert_eq( $cmp_ef_fix( $cmp_ef_old04, array() ), $cmp_ef_old04, 'plugin rows: files of another text domain are never hidden' );
cmp_assert_eq( $cmp_ef_fix( array( 'akismet/akismet.php' => array( 'Name' => 'Akismet' ) ), array() ), array( 'akismet/akismet.php' => array( 'Name' => 'Akismet' ) ), 'plugin rows: list without our files unchanged' );
cmp_assert( 1 === preg_match( "/if \( is_admin\(\) \) \{\s*add_filter\( 'all_plugins', array\( __CLASS__, 'filter_plugin_rows' \) \);/", $cmp_ef_plugin_src ) && false === strpos( $cmp_ef_plugin_src, "is_admin() && defined( 'CMP_LEGACY_LOAD' )" ), 'plugin rows: all_plugins filter registered in admin on every load (legacy or not)' );

// Cleanup.
if ( is_dir( $cmp_ef_tmp ) ) {
	$cmp_ef_rm = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $cmp_ef_tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $cmp_ef_rm as $cmp_ef_f ) {
		( $cmp_ef_f->isDir() && ! $cmp_ef_f->isLink() ) ? rmdir( $cmp_ef_f->getPathname() ) : unlink( $cmp_ef_f->getPathname() );
	}
	rmdir( $cmp_ef_tmp );
}
