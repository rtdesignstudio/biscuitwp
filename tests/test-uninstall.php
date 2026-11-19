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
cmp_assert( ! isset( $o['cmp_services'] ), 'off: cmp_services deleted' );
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
