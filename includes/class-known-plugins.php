<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone-loadable for tests.
}

/**
 * Known plugins: popular plugins whose trackers the library already covers
 * (data/plugins.json). The list only drives the "Pluginuri recunoscute" section
 * of the Servicii screen. It never feeds the scan, the seen list or re-consent:
 * what the page really loads stays the scan's business. Pure except current().
 */
class CMP_Known_Plugins {

	/** List path, relative to the plugin root. */
	const FILE = 'data/plugins.json';

	/** Bridges a plugin entry may name ('' = recognition only). */
	const BRIDGES = array( '', 'pys', 'wc-attribution' );

	/** Defaults every entry is completed with. */
	private static function defaults() {
		return array(
			'file'     => '',
			'name'     => '',
			'services' => array(),
			'bridge'   => '',
			'note'     => array( 'ro' => '', 'ru' => '', 'en' => '' ),
		);
	}

	/** Read a list file. Never throws: anything unreadable gives an empty list. */
	public static function load( $path ) {
		if ( ! is_string( $path ) || ! is_readable( $path ) || is_dir( $path ) ) {
			return array();
		}
		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) || ! isset( $data['plugins'] ) || ! is_array( $data['plugins'] ) ) {
			return array();
		}
		$out = array();
		foreach ( $data['plugins'] as $entry ) {
			if ( is_array( $entry ) ) {
				$e         = array_merge( self::defaults(), $entry );
				$e['note'] = array_merge( self::defaults()['note'], is_array( $e['note'] ) ? $e['note'] : array() );
				$out[]     = $e;
			}
		}
		return $out;
	}

	/** Pure: list of problems in decoded list data (empty = valid). */
	public static function validate( array $data, array $service_ids ) {
		if ( ! isset( $data['plugins'] ) || ! is_array( $data['plugins'] ) ) {
			return array( 'Plugins list has no "plugins" list.' );
		}
		$errors = array();
		$seen   = array();
		foreach ( $data['plugins'] as $i => $p ) {
			if ( ! is_array( $p ) ) {
				$errors[] = "Plugin #{$i} is not an object.";
				continue;
			}
			$file = isset( $p['file'] ) && is_string( $p['file'] ) ? $p['file'] : '';
			if ( ! preg_match( '#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+\.php$#', $file ) || false !== strpos( $file, '..' ) ) {
				$errors[] = "Plugin #{$i}: invalid file '{$file}'.";
			} elseif ( isset( $seen[ $file ] ) ) {
				$errors[] = "Duplicate plugin file '{$file}'.";
			}
			$seen[ $file ] = true;
			if ( ! isset( $p['name'] ) || '' === trim( (string) $p['name'] ) ) {
				$errors[] = "Plugin '{$file}': name is empty.";
			}
			foreach ( ( isset( $p['services'] ) && is_array( $p['services'] ) ? $p['services'] : array() ) as $id ) {
				if ( ! is_string( $id ) || ! in_array( $id, $service_ids, true ) ) {
					$errors[] = "Plugin '{$file}': unknown service id " . var_export( $id, true ) . '.';
				}
			}
			$bridge = isset( $p['bridge'] ) ? $p['bridge'] : '';
			if ( ! is_string( $bridge ) || ! in_array( $bridge, self::BRIDGES, true ) ) {
				$errors[] = "Plugin '{$file}': unknown bridge " . var_export( $bridge, true ) . '.';
			}
			foreach ( array( 'ro', 'ru', 'en' ) as $lang ) {
				if ( ! isset( $p['note'][ $lang ] ) || ! is_string( $p['note'][ $lang ] ) || '' === trim( $p['note'][ $lang ] ) ) {
					$errors[] = "Plugin '{$file}': note '{$lang}' is empty.";
				}
			}
		}
		return $errors;
	}

	/**
	 * Pure: the entries whose plugin is active. $active is the site's
	 * active_plugins (a list of basenames); $network_active is the network's
	 * active_sitewide_plugins (basename => time). List order is kept.
	 */
	public static function active( array $list, array $active, array $network_active ) {
		$on = array();
		foreach ( $active as $file ) {
			if ( is_string( $file ) ) {
				$on[ $file ] = true;
			}
		}
		foreach ( array_keys( $network_active ) as $file ) {
			if ( is_string( $file ) ) {
				$on[ $file ] = true;
			}
		}
		$out = array();
		foreach ( $list as $entry ) {
			if ( isset( $entry['file'] ) && isset( $on[ $entry['file'] ] ) ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Pure: the rows of the "Pluginuri recunoscute" section. $services is the
	 * effective list (CMP_Services::active()), so categories and "nu bloca"
	 * follow what the admin set. $bridged lists the bridges now in effect.
	 * $blocking: are trackers blocked at all (CMP_Blocker::blocks_trackers()).
	 * State: 'bridged' (its bridge is on), 'necessary' / 'exempt' ("nu bloca") /
	 * 'blocked' (every one of its services is so now), '' (nothing to claim: any
	 * mix, a service Advanced leaves live or a custom service decides for, no
	 * services and no bridge, blocking off, or a bridge it needs not in effect).
	 */
	public static function rows( array $active, array $services, array $bridged, $lang = 'ro', $blocking = true ) {
		$lang = in_array( $lang, array( 'ro', 'ru', 'en' ), true ) ? $lang : 'ro';
		$by   = array();
		foreach ( $services as $s ) {
			if ( is_array( $s ) && isset( $s['id'] ) ) {
				$by[ (string) $s['id'] ] = $s;
			}
		}
		$rows = array();
		foreach ( $active as $p ) {
			$list = array();
			foreach ( (array) ( isset( $p['services'] ) ? $p['services'] : array() ) as $id ) {
				if ( isset( $by[ $id ] ) ) {
					$list[] = $by[ $id ];
				}
			}
			$bridge = isset( $p['bridge'] ) ? (string) $p['bridge'] : '';
			if ( '' !== $bridge && in_array( $bridge, $bridged, true ) ) {
				$state = 'bridged';
			} else {
				$states = array_unique( array_map( array( __CLASS__, 'service_state' ), $list ) );
				$state  = 1 === count( $states ) ? $states[0] : '';
				// Blocking off, or a local loader that only its bridge can hold back.
				if ( 'blocked' === $state && ( ! $blocking || '' !== $bridge ) ) {
					$state = '';
				}
			}
			$names = array();
			foreach ( $list as $s ) {
				$names[] = array(
					'name'     => isset( $s['name'] ) ? (string) $s['name'] : (string) $s['id'],
					'category' => isset( $s['category'] ) ? (string) $s['category'] : '',
				);
			}
			$rows[] = array(
				'name'     => isset( $p['name'] ) ? (string) $p['name'] : '',
				'services' => $names,
				'state'    => $state,
				'note'     => isset( $p['note'][ $lang ] ) ? (string) $p['note'][ $lang ] : ( isset( $p['note']['ro'] ) ? (string) $p['note']['ro'] : '' ),
			);
		}
		return $rows;
	}

	/** Pure: one service of a plugin: necessary | exempt | blocked (when blocking is on), '' = live by Advanced, or a custom service decides for a host of it. */
	private static function service_state( $s ) {
		if ( ! empty( $s['except'] ) ) {
			return '';
		}
		if ( isset( $s['category'] ) && 'necessary' === $s['category'] ) {
			return 'necessary';
		}
		if ( CMP_Services::consent_exempt( $s ) ) {
			return 'exempt';
		}
		return empty( $s['exempt'] ) ? 'blocked' : '';
	}

	/** Bridges in effect on this site now. */
	public static function bridged() {
		$out = array();
		if ( class_exists( 'CMP_Bridge_PYS' ) && CMP_Bridge_PYS::in_effect() ) {
			$out[] = 'pys';
		}
		if ( class_exists( 'CMP_Bridge_WC' ) && CMP_Bridge_WC::shown() ) {
			$out[] = 'wc-attribution';
		}
		return $out;
	}

	/** WordPress wrapper: the rows for the plugins active on this site (empty when none). */
	public static function current() {
		if ( ! function_exists( 'get_option' ) || ! defined( 'CMP_PATH' ) ) {
			return array();
		}
		$site    = get_option( 'active_plugins', array() );
		$network = function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_site_option' ) ? get_site_option( 'active_sitewide_plugins', array() ) : array();
		$active  = self::active( self::load( CMP_PATH . self::FILE ), is_array( $site ) ? $site : array(), is_array( $network ) ? $network : array() );
		if ( ! $active ) {
			return array();
		}
		$lang = class_exists( 'CMP_Lang' ) ? CMP_Lang::current() : 'ro';
		$s    = CMP_Consent::get_settings();
		return self::rows( $active, CMP_Services::active(), self::bridged(), $lang, CMP_Blocker::blocks_trackers( is_array( $s ) ? $s : array() ) );
	}
}
