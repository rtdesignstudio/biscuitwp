<?php
// B7.2: CMP_Uninstaller (per-site cleanup, opt-in full removal) + the new setting.
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['cmp_un']['options'] ) ? $GLOBALS['cmp_un']['options'][ $k ] : $d; }
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { unset( $GLOBALS['cmp_un']['options'][ $k ] ); return true; }
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $k ) { unset( $GLOBALS['cmp_un']['options'][ '_transient_' . $k ] ); return true; }
}
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $h ) { $GLOBALS['cmp_un']['cron'][] = $h; }
}
if ( ! function_exists( 'delete_post_meta_by_key' ) ) {
	function delete_post_meta_by_key( $k ) { $GLOBALS['cmp_un']['meta'][] = $k; return true; }
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir( $t = null, $c = true ) { return array( 'basedir' => $GLOBALS['cmp_un']['basedir'], 'error' => false ); }
}

if ( ! class_exists( 'CMP_Un_Wpdb' ) ) {
	class CMP_Un_Wpdb {
		public $prefix  = 'wp_';
		public $options = 'wp_options';
		public $queries = array();
		public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
		public function prepare( $q, ...$args ) {
			foreach ( $args as $a ) {
				$q = preg_replace( '/%s/', "'" . str_replace( '\\', '\\\\', $a ) . "'", $q, 1 );
			}
			return $q;
		}
		public function query( $sql ) {
			$this->queries[] = $sql;
			// Simulate DELETE ... LIKE against the option store.
			if ( 0 === strpos( $sql, 'DELETE FROM' ) && preg_match_all( "/LIKE '((?:[^'\\\\]|\\\\.)*)'/", $sql, $m ) ) {
				foreach ( $m[1] as $like ) {
					$re = '';
					$n  = strlen( $like );
					for ( $i = 0; $i < $n; $i++ ) {
						$c = $like[ $i ];
						if ( '\\' === $c && $i + 1 < $n ) {
							$re .= preg_quote( $like[ ++$i ], '/' );
						} elseif ( '%' === $c ) {
							$re .= '.*';
						} elseif ( '_' === $c ) {
							$re .= '.';
						} else {
							$re .= preg_quote( $c, '/' );
						}
					}
					foreach ( array_keys( $GLOBALS['cmp_un']['options'] ) as $k ) {
						if ( preg_match( '/^' . $re . '$/s', $k ) ) {
							unset( $GLOBALS['cmp_un']['options'][ $k ] );
						}
					}
				}
			}
			return 1;
		}
	}
}

require_once __DIR__ . '/../includes/class-uninstaller.php';
require_once __DIR__ . '/../includes/class-installer.php';
require_once __DIR__ . '/../includes/class-sanitizer.php';

function cmp_un_reset( $full_flag = false ) {
	$tmp = sys_get_temp_dir() . '/cmp-un-' . uniqid();
	mkdir( $tmp . '/cmp-thumbs', 0777, true );
	mkdir( $tmp . '/cmp-fonts', 0777, true );
	file_put_contents( $tmp . '/cmp-thumbs/a.jpg', 'x' );
	file_put_contents( $tmp . '/cmp-fonts/a.woff2', 'x' );
	file_put_contents( $tmp . '/cmp-fonts/a.css', 'x' );
	$GLOBALS['cmp_un'] = array(
		'basedir' => $tmp,
		'cron'    => array(),
		'meta'    => array(),
		'options' => array(
			'cmp_settings'                 => array( 'delete_data_on_uninstall' => $full_flag ),
			'cmp_categories'               => array( 'a' ),
			'cmp_db_version'               => '1',
			'cmp_scan_aggregate'           => array( 1 ),
			'cmp_fonts_map'                => array( 'u' => 'v' ),
			'cmp_services'                 => array( 1 ),
			'cmp_services_history'         => array( 'youtube' ),
			'cmp_services_seen'            => 1,
			'cmp_services_found'           => array( 'youtube' ),
			'cmp_unknown_hosts'            => array( 'known' => array( 'a.com' ) ),
			'cmp_unknown_hosts_lock'       => 1,
			'cmp_future_option'            => 'x',
			'cmpx_other'                   => 'keep',
			'other_cmp_thing'              => 'keep',
			'_transient_cmp_thumb_fail_1'  => '1',
			'_transient_timeout_cmp_thumb_fail_1' => '1',
			'_transient_cmp_tracker_scan'  => '1',
			'_transient_timeout_cmp_tracker_scan' => '1',
			'_transient_cmpx_other'        => 'keep',
		),
	);
	return $tmp;
}

// --- Flag off: data kept, temporary data removed.
$tmp = cmp_un_reset( false );
$db  = new CMP_Un_Wpdb();
cmp_assert_eq( CMP_Uninstaller::wants_full(), false, 'wants_full false when flag off' );
CMP_Uninstaller::cleanup_site( $db, false );
$o = $GLOBALS['cmp_un']['options'];
cmp_assert( isset( $o['cmp_settings'] ), 'off: cmp_settings kept' );
cmp_assert( isset( $o['cmp_categories'] ), 'off: cmp_categories kept' );
cmp_assert( isset( $o['cmp_db_version'] ), 'off: cmp_db_version kept' );
cmp_assert( isset( $o['cmp_future_option'] ), 'off: unknown cmp_ option kept' );
cmp_assert( ! isset( $o['cmp_scan_aggregate'] ), 'off: cmp_scan_aggregate deleted' );
cmp_assert( ! isset( $o['cmp_fonts_map'] ), 'off: cmp_fonts_map deleted' );
cmp_assert( ! isset( $o['cmp_unknown_hosts'] ) && ! isset( $o['cmp_unknown_hosts_lock'] ), 'off: the new-domain state and its lock deleted' );
cmp_assert( isset( $o['cmp_services'] ) && isset( $o['cmp_services_history'] ) && isset( $o['cmp_services_seen'] ), 'off: configured services, history and seen flag kept' );
cmp_assert( ! isset( $o['cmp_services_found'] ), 'off: cmp_services_found (scan cache) deleted' );
cmp_assert( ! isset( $o['_transient_cmp_thumb_fail_1'] ) && ! isset( $o['_transient_timeout_cmp_thumb_fail_1'] ), 'off: thumb transients deleted' );
cmp_assert( ! isset( $o['_transient_cmp_tracker_scan'] ), 'off: tracker scan transient deleted' );
cmp_assert_eq( in_array( 'cmp_scan_daily', $GLOBALS['cmp_un']['cron'], true ), true, 'off: cron cleared' );
cmp_assert( ! is_dir( $tmp . '/cmp-thumbs' ), 'off: cmp-thumbs removed' );
cmp_assert( ! is_dir( $tmp . '/cmp-fonts' ), 'off: cmp-fonts removed' );
$drop = array_filter( $db->queries, function ( $q ) { return false !== strpos( $q, 'DROP TABLE' ); } );
cmp_assert_eq( count( $drop ), 0, 'off: no DROP TABLE' );
cmp_assert_eq( $GLOBALS['cmp_un']['meta'], array(), 'off: post meta kept' );
rmdir( $tmp );

// --- Flag on: everything cmp_ removed, foreign options untouched.
$tmp = cmp_un_reset( true );
$db  = new CMP_Un_Wpdb();
cmp_assert_eq( CMP_Uninstaller::wants_full(), true, 'wants_full true when flag on' );
CMP_Uninstaller::cleanup_site( $db, true );
$o = $GLOBALS['cmp_un']['options'];
cmp_assert( ! isset( $o['cmp_settings'] ) && ! isset( $o['cmp_categories'] ) && ! isset( $o['cmp_db_version'] ), 'on: base options deleted' );
cmp_assert( ! isset( $o['cmp_future_option'] ), 'on: any cmp_ option deleted' );
cmp_assert( ! isset( $o['cmp_scan_aggregate'] ) && ! isset( $o['cmp_fonts_map'] ), 'on: temporary data deleted' );
cmp_assert( ! isset( $o['cmp_unknown_hosts'] ) && ! isset( $o['cmp_unknown_hosts_lock'] ), 'on: the new-domain state and its lock deleted' );
cmp_assert( ! isset( $o['cmp_services'] ) && ! isset( $o['cmp_services_history'] ) && ! isset( $o['cmp_services_seen'] ) && ! isset( $o['cmp_services_found'] ), 'on: services, history, seen flag and scan cache deleted' );
cmp_assert( isset( $o['cmpx_other'] ), 'on: cmpx_other untouched' );
cmp_assert( isset( $o['other_cmp_thing'] ), 'on: other_cmp_thing untouched' );
cmp_assert( isset( $o['_transient_cmpx_other'] ), 'on: _transient_cmpx_other untouched' );
cmp_assert( ! isset( $o['_transient_cmp_tracker_scan'] ), 'on: cmp transients deleted' );
cmp_assert_eq( in_array( 'DROP TABLE IF EXISTS wp_cmp_consent_log', $db->queries, true ), true, 'on: DROP TABLE emitted' );
$likes = array_filter( $db->queries, function ( $q ) { return false !== strpos( $q, "LIKE 'cmp\\_%'" ); } );
cmp_assert( count( $likes ) >= 1, 'on: LIKE uses escaped cmp\\_%' );
cmp_assert_eq( $GLOBALS['cmp_un']['meta'], array( '_cmp_legal_type', '_cmp_legal_mode' ), 'on: legal post meta deleted' );
rmdir( $tmp );

// --- Symlinked cmp-fonts is neither followed nor removed.
$tmp    = cmp_un_reset( false );
$target = sys_get_temp_dir() . '/cmp-un-target-' . uniqid();
mkdir( $target );
file_put_contents( $target . '/keep.txt', 'k' );
unlink( $tmp . '/cmp-fonts/a.woff2' );
unlink( $tmp . '/cmp-fonts/a.css' );
rmdir( $tmp . '/cmp-fonts' );
symlink( $target, $tmp . '/cmp-fonts' );
$db = new CMP_Un_Wpdb();
CMP_Uninstaller::cleanup_site( $db, false );
cmp_assert( is_link( $tmp . '/cmp-fonts' ), 'symlink: link itself kept' );
cmp_assert( file_exists( $target . '/keep.txt' ), 'symlink: target contents untouched' );
cmp_assert_eq( CMP_Uninstaller::remove_upload_dir( $tmp . '/cmp-fonts' ), false, 'symlink: remove_upload_dir refuses' );
unlink( $tmp . '/cmp-fonts' );
unlink( $target . '/keep.txt' );
rmdir( $target );
rmdir( $tmp );

// --- Missing folder is not an error.
cmp_assert_eq( CMP_Uninstaller::remove_upload_dir( sys_get_temp_dir() . '/cmp-un-missing-' . uniqid() ), false, 'missing dir -> false' );

// --- Setting default + sanitizer.
$d = CMP_Installer::default_settings();
cmp_assert_eq( $d['delete_data_on_uninstall'], false, 'installer default delete_data_on_uninstall false' );
cmp_assert_eq( CMP_Sanitizer::settings( array() )['delete_data_on_uninstall'], false, 'sanitizer missing -> false' );
cmp_assert_eq( CMP_Sanitizer::settings( array( 'delete_data_on_uninstall' => '1' ) )['delete_data_on_uninstall'], true, "sanitizer '1' -> true" );

// --- Flag off: the remaining "always" options and every cmp transient go.
cmp_un_reset( false );
$GLOBALS['cmp_un']['options'] += array(
	'cmp_operator_note_seen'     => 1,
	'cmp_templates_notice'       => 1,
	'cmp_templates_regenerated'  => 1,
	'_transient_cmp_rl_abc'      => 1,
	'_transient_timeout_cmp_rl_abc' => 1,
	'_transient_cmp_latest_release' => 1,
	'_transient_cmp_measurement_msg_5' => 1,
);
CMP_Uninstaller::cleanup_site( new CMP_Un_Wpdb(), false );
$o = $GLOBALS['cmp_un']['options'];
cmp_assert( ! isset( $o['cmp_operator_note_seen'] ) && ! isset( $o['cmp_templates_notice'] ) && ! isset( $o['cmp_templates_regenerated'] ), 'off: operator note and templates options deleted' );
cmp_assert( ! isset( $o['_transient_cmp_rl_abc'] ) && ! isset( $o['_transient_timeout_cmp_rl_abc'] ) && ! isset( $o['_transient_cmp_latest_release'] ) && ! isset( $o['_transient_cmp_measurement_msg_5'] ), 'off: rate-limit, release and message transients deleted' );
cmp_assert( isset( $o['_transient_cmpx_other'] ), 'off: foreign transient kept' );
cmp_assert_eq( in_array( 'cmp_prune_consent_log', $GLOBALS['cmp_un']['cron'], true ), true, 'off: prune cron cleared' );

// --- B8a: updater counter/state are network-wide (site options), the manifest cache a site transient.
if ( ! function_exists( 'get_site_option' ) ) {
	function get_site_option( $k, $d = false ) { return isset( $GLOBALS['cmp_un']['site'] ) && array_key_exists( $k, $GLOBALS['cmp_un']['site'] ) ? $GLOBALS['cmp_un']['site'][ $k ] : $d; }
}
if ( ! function_exists( 'update_site_option' ) ) {
	function update_site_option( $k, $v ) { $GLOBALS['cmp_un']['site'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_site_option' ) ) {
	function delete_site_option( $k ) { unset( $GLOBALS['cmp_un']['site'][ $k ] ); return true; }
}
if ( ! function_exists( 'get_site_transient' ) ) {
	function get_site_transient( $k ) { return isset( $GLOBALS['cmp_un']['site'] ) && array_key_exists( '_site_transient_' . $k, $GLOBALS['cmp_un']['site'] ) ? $GLOBALS['cmp_un']['site'][ '_site_transient_' . $k ] : false; }
}
if ( ! function_exists( 'set_site_transient' ) ) {
	function set_site_transient( $k, $v, $ttl = 0 ) {
		$GLOBALS['cmp_un']['site'][ '_site_transient_' . $k ]         = $v;
		$GLOBALS['cmp_un']['site'][ '_site_transient_timeout_' . $k ] = $ttl;
		return true;
	}
}
if ( ! function_exists( 'delete_site_transient' ) ) {
	function delete_site_transient( $k ) { unset( $GLOBALS['cmp_un']['site'][ '_site_transient_' . $k ], $GLOBALS['cmp_un']['site'][ '_site_transient_timeout_' . $k ] ); return true; }
}
function cmp_un_site_store() {
	return array(
		'cmp_manifest_issued'                    => array( 'beta' => 1, 'stable' => 2 ),
		'cmp_update_state'                       => array( 'expired' => true, 'revoked_installed' => false, 'no_sodium' => false ),
		'_site_transient_cmp_latest_release'     => array( 'channel' => 'stable' ),
		'_site_transient_timeout_cmp_latest_release' => 1,
		'other_network_option'                   => 'keep',
	);
}
foreach ( array( false, true ) as $t_full ) {
	$t_tmp                     = cmp_un_reset( $t_full );
	$GLOBALS['cmp_un']['site'] = cmp_un_site_store();
	$GLOBALS['wpdb']           = new CMP_Un_Wpdb();
	CMP_Uninstaller::run();
	$t_site = $GLOBALS['cmp_un']['site'];
	$label  = $t_full ? 'on' : 'off';
	cmp_assert_eq( isset( $t_site['cmp_manifest_issued'] ), ! $t_full, "single site {$label}: cmp_manifest_issued (site option) " . ( $t_full ? 'deleted' : 'kept' ) );
	cmp_assert_eq( isset( $t_site['cmp_update_state'] ), ! $t_full, "single site {$label}: cmp_update_state (site option) " . ( $t_full ? 'deleted' : 'kept' ) );
	cmp_assert( ! isset( $t_site['_site_transient_cmp_latest_release'] ), "single site {$label}: manifest cache (site transient) deleted" );
	cmp_assert( isset( $t_site['other_network_option'] ), "single site {$label}: foreign network option untouched" );
	@rmdir( $t_tmp );
}
$t_un_src = file_get_contents( __DIR__ . '/../includes/class-uninstaller.php' );
cmp_assert( false !== strpos( $t_un_src, "delete_site_option( 'cmp_manifest_issued' )" ) && false !== strpos( $t_un_src, "delete_site_option( 'cmp_update_state' )" ) && false !== strpos( $t_un_src, "delete_site_transient( 'cmp_latest_release' )" ), 'uninstaller: network-level cleanup uses delete_site_option / delete_site_transient' );

// --- Multisite run(): two sites, different flags.
if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() { return ! empty( $GLOBALS['cmp_ms']['on'] ); }
}
if ( ! function_exists( 'get_sites' ) ) {
	function get_sites( $a = array() ) { return array_keys( $GLOBALS['cmp_ms']['sites'] ); }
}
if ( ! function_exists( 'switch_to_blog' ) ) {
	function switch_to_blog( $id ) {
		$GLOBALS['cmp_ms']['stack'][] = $GLOBALS['cmp_ms']['cur'];
		$GLOBALS['cmp_ms']['cur']     = $id;
		$GLOBALS['cmp_ms']['log'][]   = 'switch:' . $id;
		$GLOBALS['cmp_un']['options'] = &$GLOBALS['cmp_ms']['sites'][ $id ];
		$GLOBALS['wpdb']->prefix      = 'wp_' . $id . '_';
		$GLOBALS['wpdb']->options     = 'wp_' . $id . '_options';
		return true;
	}
}
if ( ! function_exists( 'restore_current_blog' ) ) {
	function restore_current_blog() {
		$prev = array_pop( $GLOBALS['cmp_ms']['stack'] );
		$GLOBALS['cmp_ms']['cur'] = $prev;
		$GLOBALS['cmp_ms']['log'][] = 'restore';
		$GLOBALS['wpdb']->prefix  = 'wp_';
		$GLOBALS['wpdb']->options = 'wp_options';
		return true;
	}
}
$tmp = cmp_un_reset( false );
$GLOBALS['wpdb'] = new CMP_Un_Wpdb();
$GLOBALS['cmp_ms'] = array(
	'on'    => true,
	'cur'   => 0,
	'stack' => array(),
	'log'   => array(),
	'sites' => array(
		1 => array( 'cmp_settings' => array( 'delete_data_on_uninstall' => true ), 'cmp_future_option' => 'x' ),
		2 => array( 'cmp_settings' => array( 'delete_data_on_uninstall' => false ), 'cmp_future_option' => 'y' ),
	),
);
$GLOBALS['cmp_un']['site'] = cmp_un_site_store();
CMP_Uninstaller::run();
$drops = array_values( array_filter( $GLOBALS['wpdb']->queries, function ( $q ) { return false !== strpos( $q, 'DROP TABLE' ); } ) );
cmp_assert_eq( $drops, array( 'DROP TABLE IF EXISTS wp_1_cmp_consent_log' ), 'multisite: only the flagged site drops its log table' );
cmp_assert( ! isset( $GLOBALS['cmp_ms']['sites'][1]['cmp_settings'] ) && ! isset( $GLOBALS['cmp_ms']['sites'][1]['cmp_future_option'] ), 'multisite: site 1 fully removed' );
cmp_assert( isset( $GLOBALS['cmp_ms']['sites'][2]['cmp_settings'] ) && isset( $GLOBALS['cmp_ms']['sites'][2]['cmp_future_option'] ), 'multisite: site 2 data kept' );
cmp_assert_eq( $GLOBALS['cmp_ms']['log'], array( 'switch:1', 'restore', 'switch:2', 'restore' ), 'multisite: switch and restore balanced' );
cmp_assert( ! isset( $GLOBALS['cmp_un']['site']['cmp_manifest_issued'] ) && ! isset( $GLOBALS['cmp_un']['site']['cmp_update_state'] ), 'multisite: main site (1) flagged -> network updater options deleted once' );
cmp_assert( ! isset( $GLOBALS['cmp_un']['site']['_site_transient_cmp_latest_release'] ), 'multisite: manifest cache (site transient) deleted' );
// Main site not flagged -> network options kept, cache still deleted.
$GLOBALS['cmp_ms']['sites'] = array(
	1 => array( 'cmp_settings' => array( 'delete_data_on_uninstall' => false ) ),
	2 => array( 'cmp_settings' => array( 'delete_data_on_uninstall' => true ) ),
);
$GLOBALS['cmp_ms']['log'] = array();
$GLOBALS['cmp_un']['site'] = cmp_un_site_store();
CMP_Uninstaller::run();
cmp_assert( isset( $GLOBALS['cmp_un']['site']['cmp_manifest_issued'], $GLOBALS['cmp_un']['site']['cmp_update_state'] ), 'multisite: main site not flagged -> network updater options kept' );
cmp_assert( ! isset( $GLOBALS['cmp_un']['site']['_site_transient_cmp_latest_release'] ), 'multisite: main site not flagged -> manifest cache still deleted' );
unset( $GLOBALS['cmp_un']['options'] );
$GLOBALS['cmp_ms']['on'] = false;
@rmdir( $tmp . '/cmp-thumbs' ); @rmdir( $tmp . '/cmp-fonts' ); @rmdir( $tmp );

// --- CMP_Plugin multisite hooks (install_site stubbed by a subclass).
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'CMP_PATH' ) ) {
	define( 'CMP_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
	function is_plugin_active_for_network( $p ) { return ! empty( $GLOBALS['cmp_ms']['net_active'] ); }
}
if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $f ) { return basename( dirname( $f ) ) . '/' . basename( $f ); }
}
class CMP_Wpdb_Blog extends CMP_Un_Wpdb {
	public function get_blog_prefix( $id ) { return 'wp_' . $id . '_'; }
}
require_once __DIR__ . '/../includes/class-plugin.php';
class CMP_Test_Plugin extends CMP_Plugin {
	public static $installed = array();
	public static $stopped   = array();
	public static function install_site() { self::$installed[] = $GLOBALS['cmp_ms']['cur']; }
	public static function deactivate_site() { self::$stopped[] = $GLOBALS['cmp_ms']['cur']; }
}
$GLOBALS['cmp_ms'] = array( 'on' => true, 'cur' => 0, 'stack' => array(), 'log' => array(), 'net_active' => true,
	'sites' => array( 1 => array(), 2 => array(), 3 => array() ) );
$GLOBALS['wpdb'] = new CMP_Wpdb_Blog();
$GLOBALS['cmp_un'] = array( 'options' => array() );
CMP_Test_Plugin::activate( true );
cmp_assert_eq( CMP_Test_Plugin::$installed, array( 1, 2, 3 ), 'activate(network): install_site once per site' );
cmp_assert_eq( $GLOBALS['cmp_ms']['log'], array( 'switch:1', 'restore', 'switch:2', 'restore', 'switch:3', 'restore' ), 'activate(network): switch/restore balanced' );
CMP_Test_Plugin::$installed = array();
CMP_Test_Plugin::activate( false );
cmp_assert_eq( CMP_Test_Plugin::$installed, array( 0 ), 'activate(single site): install_site once, no switching' );
CMP_Test_Plugin::deactivate( true );
cmp_assert_eq( CMP_Test_Plugin::$stopped, array( 1, 2, 3 ), 'deactivate(network): every site' );
CMP_Test_Plugin::$installed = array();
CMP_Test_Plugin::on_new_site( (object) array( 'blog_id' => 7 ) );
cmp_assert_eq( CMP_Test_Plugin::$installed, array( 7 ), 'new site: installed when network-active' );
$GLOBALS['cmp_ms']['net_active'] = false;
CMP_Test_Plugin::$installed = array();
CMP_Test_Plugin::on_new_site( (object) array( 'blog_id' => 8 ) );
cmp_assert_eq( CMP_Test_Plugin::$installed, array(), 'new site: nothing installed when not network-active' );
cmp_assert_eq( CMP_Plugin::drop_tables( array( 'wp_9_posts' ), 9 ), array( 'wp_9_posts', 'wp_9_cmp_consent_log' ), 'wpmu_drop_tables adds the site log table' );
$src = file_get_contents( __DIR__ . '/../includes/class-plugin.php' );
cmp_assert( false !== strpos( $src, "'wp_initialize_site', array( __CLASS__, 'on_new_site' ), 20" ) && false !== strpos( $src, "'wpmu_drop_tables'" ), 'hooks registered in boot' );
$GLOBALS['cmp_ms']['on'] = false;

// --- M5 (final review): uninstall removes the folder-move stub, and only when it
// holds exactly our stub files (plugins/consent-manager-pro and the hidden build folder).
if ( ! class_exists( 'CMP_Migrate_Folder' ) ) {
	require_once __DIR__ . '/../includes/class-migrate-folder.php';
}
$t_sd   = sys_get_temp_dir() . '/cmp-un-stub-' . getmypid() . '-' . mt_rand();
$t_stub = CMP_Migrate_Folder::stub_source();
$t_mk   = function ( $dir, array $files ) {
	@mkdir( $dir, 0777, true );
	foreach ( $files as $n => $c ) {
		file_put_contents( $dir . '/' . $n, $c );
	}
};
$t_mk( $t_sd . '/consent-manager-pro', array( 'consent-manager-pro.php' => $t_stub, 'biscuitwp.php' => $t_stub ) );
$t_mk( $t_sd . '/.biscuitwp-move-stub', array( 'consent-manager-pro.php' => $t_stub, 'biscuitwp.php' => $t_stub ) );
$t_mk( $t_sd . '/biscuitwp', array( 'biscuitwp.php' => '<?php // real plugin' ) );
cmp_assert( method_exists( 'CMP_Uninstaller', 'remove_move_stub' ), 'uninstall (M5): CMP_Uninstaller::remove_move_stub() exists' );
if ( method_exists( 'CMP_Uninstaller', 'remove_move_stub' ) ) {
	CMP_Uninstaller::remove_move_stub( $t_sd );
	clearstatcache();
	cmp_assert( ! file_exists( $t_sd . '/consent-manager-pro' ), 'uninstall (M5): our stub folder plugins/consent-manager-pro removed' );
	cmp_assert( ! file_exists( $t_sd . '/.biscuitwp-move-stub' ), 'uninstall (M5): the hidden stub build folder removed' );
	cmp_assert( is_file( $t_sd . '/biscuitwp/biscuitwp.php' ), 'uninstall (M5): plugins/biscuitwp left to WordPress' );
	// Not exactly our stub: a real copy, an extra file, a changed stub. Never touched.
	$t_mk( $t_sd . '/consent-manager-pro', array( 'consent-manager-pro.php' => $t_stub, 'biscuitwp.php' => $t_stub, 'readme.txt' => 'x' ) );
	CMP_Uninstaller::remove_move_stub( $t_sd );
	clearstatcache();
	cmp_assert( is_file( $t_sd . '/consent-manager-pro/readme.txt' ) && is_file( $t_sd . '/consent-manager-pro/consent-manager-pro.php' ), 'uninstall (M5): a folder with any other file is kept whole' );
	@unlink( $t_sd . '/consent-manager-pro/readme.txt' );
	file_put_contents( $t_sd . '/consent-manager-pro/biscuitwp.php', $t_stub . "// edited\n" );
	CMP_Uninstaller::remove_move_stub( $t_sd );
	clearstatcache();
	cmp_assert( is_file( $t_sd . '/consent-manager-pro/biscuitwp.php' ), 'uninstall (M5): a changed stub file is kept' );
	CMP_Uninstaller::remove_move_stub( $t_sd . '/missing' ); // No folder: no error.
}
$t_un_src2 = (string) file_get_contents( __DIR__ . '/../includes/class-uninstaller.php' );
cmp_assert( 2 === substr_count( $t_un_src2, 'self::remove_move_stub( WP_PLUGIN_DIR )' ), 'uninstall (M5): run() removes the stub on single site and multisite' );
foreach ( array( '/consent-manager-pro/readme.txt', '/consent-manager-pro/biscuitwp.php', '/consent-manager-pro/consent-manager-pro.php', '/biscuitwp/biscuitwp.php' ) as $t_f ) {
	@unlink( $t_sd . $t_f );
}
@rmdir( $t_sd . '/consent-manager-pro' );
@rmdir( $t_sd . '/biscuitwp' );
@rmdir( $t_sd );
