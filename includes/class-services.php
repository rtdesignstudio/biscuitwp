<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone-loadable for tests.
}

/**
 * Services library: data-driven rules (data/services.json) for blocking
 * scripts and embeds. Pure and WordPress-free.
 */
class CMP_Services {

	/** Library path, relative to the plugin root. */
	const FILE = 'data/services.json';

	/** Services 0.4.x already blocked (before the library existed). */
	const LEGACY_IDS = array( 'google-analytics', 'google-tag-manager', 'meta-pixel', 'youtube', 'vimeo', 'google-maps' );

	private static $cache  = null;
	private static $errors = array();

	/** Defaults every service is completed with. */
	private static function defaults() {
		return array(
			'id'          => '',
			'name'        => '',
			'provider'    => '',
			'type'        => 'script',
			'category'    => '',
			'hosts'       => array(),
			'inline'      => array(),
			'gtm'         => false,
			'path'        => '',
			'nocookie'    => false,
			'cookies'     => array(),
			'transfer'    => '',
			'privacy_url' => '',
		);
	}

	/** Load a library file. Never throws: problems come back in 'errors'. */
	public static function load_file( $path ) {
		$out = array( 'services' => array(), 'errors' => array() );
		if ( ! is_string( $path ) || ! is_readable( $path ) || is_dir( $path ) ) {
			$out['errors'][] = 'Services file not found: ' . $path;
			return $out;
		}
		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) || ! isset( $data['services'] ) || ! is_array( $data['services'] ) ) {
			$out['errors'][] = 'Services file is not valid JSON or has no "services" list: ' . $path;
			return $out;
		}
		$errors = self::validate( $data );
		if ( $errors ) {
			$out['errors'] = $errors;
		}
		foreach ( $data['services'] as $service ) {
			if ( is_array( $service ) ) {
				$out['services'][] = array_merge( self::defaults(), $service );
			}
		}
		return $out;
	}

	/** Pure: list of problems in decoded library data (empty = valid). */
	public static function validate( array $data ) {
		$errors = array();
		$seen   = array();
		$list   = isset( $data['services'] ) && is_array( $data['services'] ) ? $data['services'] : array();
		foreach ( $list as $i => $s ) {
			if ( ! is_array( $s ) ) {
				$errors[] = "Service #{$i} is not an object.";
				continue;
			}
			$id = isset( $s['id'] ) ? (string) $s['id'] : '';
			if ( ! preg_match( '/^[a-z0-9-]+$/', $id ) ) {
				$errors[] = "Service #{$i}: invalid id '{$id}'.";
			} elseif ( isset( $seen[ $id ] ) ) {
				$errors[] = "Duplicate service id '{$id}'.";
			}
			$seen[ $id ] = true;
			if ( ! isset( $s['type'] ) || ! in_array( $s['type'], array( 'script', 'embed' ), true ) ) {
				$errors[] = "Service '{$id}': type must be script or embed.";
			}
			if ( ! isset( $s['category'] ) || '' === trim( (string) $s['category'] ) ) {
				$errors[] = "Service '{$id}': category is empty.";
			}
			$hosts = isset( $s['hosts'] ) && is_array( $s['hosts'] ) ? $s['hosts'] : array();
			foreach ( $hosts as $h ) {
				if ( ! is_string( $h ) || '' === $h || false !== strpos( $h, '://' ) ) {
					$errors[] = "Service '{$id}': invalid host " . var_export( $h, true ) . '.';
				}
			}
		}
		return $errors;
	}

	/** The plugin's library (cached per request). */
	public static function library() {
		if ( null === self::$cache ) {
			$r             = self::load_file( dirname( __DIR__ ) . '/' . self::FILE );
			self::$cache   = $r['services'];
			self::$errors  = $r['errors'];
		}
		return self::$cache;
	}

	/** Problems found when the library was last loaded (empty = fine). */
	public static function errors() {
		self::library();
		return self::$errors;
	}

	/**
	 * Pure: of the found service ids, those that would now be blocked (not
	 * necessary, not exempt) and that 0.4.x did not already block.
	 */
	public static function newly_blocked( array $found_ids, array $services ) {
		$out = array();
		foreach ( $services as $s ) {
			if ( ! is_array( $s ) || ! isset( $s['id'] ) || ! in_array( $s['id'], $found_ids, true ) ) {
				continue;
			}
			if ( ( isset( $s['category'] ) && 'necessary' === $s['category'] ) || ! empty( $s['exempt'] ) ) {
				continue;
			}
			if ( in_array( $s['id'], self::LEGACY_IDS, true ) || in_array( $s['id'], $out, true ) ) {
				continue;
			}
			$out[] = $s['id'];
		}
		return $out;
	}

	/**
	 * Pure: the library with stored overrides applied, plus custom services.
	 * An empty $category_slugs means "unknown": categories are not remapped.
	 */
	public static function all( array $library, array $stored, array $category_slugs ) {
		$over = isset( $stored['overrides'] ) && is_array( $stored['overrides'] ) ? $stored['overrides'] : array();
		$out  = array();
		foreach ( $library as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$id = isset( $s['id'] ) ? (string) $s['id'] : '';
			if ( isset( $over[ $id ] ) && is_array( $over[ $id ] ) ) {
				if ( isset( $over[ $id ]['category'] ) && '' !== (string) $over[ $id ]['category'] ) {
					$s['category'] = (string) $over[ $id ]['category'];
				}
				if ( array_key_exists( 'exempt', $over[ $id ] ) ) {
					$s['exempt'] = (bool) $over[ $id ]['exempt'];
				}
			}
			$s['source'] = 'library';
			$out[]       = $s;
		}
		$custom = isset( $stored['custom'] ) && is_array( $stored['custom'] ) ? $stored['custom'] : array();
		foreach ( $custom as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$cookies = array();
			foreach ( (array) ( isset( $c['cookies'] ) ? $c['cookies'] : array() ) as $ck ) {
				if ( ! is_array( $ck ) ) {
					continue;
				}
				$d         = isset( $ck['duration'] ) ? (string) $ck['duration'] : '';
				$p         = isset( $ck['purpose'] ) ? (string) $ck['purpose'] : '';
				$cookies[] = array(
					'name'     => isset( $ck['name'] ) ? (string) $ck['name'] : '',
					'duration' => array( 'ro' => $d, 'ru' => $d, 'en' => $d ),
					'purpose'  => array( 'ro' => $p, 'ru' => $p, 'en' => $p ),
				);
			}
			$row = array_merge(
				self::defaults(),
				array(
					'id'       => isset( $c['id'] ) ? (string) $c['id'] : '',
					'name'     => isset( $c['name'] ) ? (string) $c['name'] : '',
					'type'     => 'script',
					'category' => isset( $c['category'] ) ? (string) $c['category'] : 'marketing',
					'exempt'   => ! empty( $c['exempt'] ),
					'hosts'    => array_values( (array) ( isset( $c['hosts'] ) ? $c['hosts'] : array() ) ),
					'inline'   => array(),
					'cookies'  => $cookies,
					'script'   => isset( $c['script'] ) ? (string) $c['script'] : '',
					'source'   => 'custom',
				)
			);
			$out[] = $row;
		}
		if ( $category_slugs ) {
			foreach ( $out as $i => $s ) {
				$cat = isset( $s['category'] ) ? (string) $s['category'] : '';
				if ( 'necessary' !== $cat && ! in_array( $cat, $category_slugs, true ) ) {
					$out[ $i ]['category'] = 'marketing';
				}
			}
		}
		return $out;
	}

	/** WordPress wrapper: library + cmp_services option + current category slugs. */
	public static function active() {
		$stored = function_exists( 'get_option' ) ? get_option( 'cmp_services', array() ) : array();
		$slugs  = array();
		if ( function_exists( 'get_option' ) && class_exists( 'CMP_Consent' ) ) {
			foreach ( (array) CMP_Consent::get_categories() as $cat ) {
				if ( is_array( $cat ) && ! empty( $cat['slug'] ) ) {
					$slugs[] = (string) $cat['slug'];
				}
			}
		}
		return self::all( self::library(), is_array( $stored ) ? $stored : array(), $slugs );
	}

	/** Pure: does a URL's parsed host (exact or subdomain) and path prefix match 'host' or 'host/path'? */
	public static function host_matches( $url, $pattern ) {
		$pattern = strtolower( (string) $pattern );
		$slash   = strpos( $pattern, '/' );
		$host    = false === $slash ? $pattern : substr( $pattern, 0, $slash );
		$path    = false === $slash ? '' : substr( $pattern, $slash );
		$h       = parse_url( (string) $url, PHP_URL_HOST );
		if ( ! is_string( $h ) || '' === $h || '' === $host ) {
			return false;
		}
		$h = strtolower( $h );
		if ( $h !== $host && substr( $h, -( strlen( $host ) + 1 ) ) !== '.' . $host ) {
			return false;
		}
		if ( '' === $path ) {
			return true;
		}
		return 0 === strpos( (string) parse_url( (string) $url, PHP_URL_PATH ), $path );
	}

	/** Pure: first script-type service matching a script src (when set) or its inline body. */
	public static function script_service( $src, $body, array $services ) {
		$src  = (string) $src;
		$body = (string) $body;
		foreach ( $services as $s ) {
			if ( ! isset( $s['type'] ) || 'script' !== $s['type'] ) {
				continue;
			}
			if ( '' !== $src ) {
				foreach ( (array) ( isset( $s['hosts'] ) ? $s['hosts'] : array() ) as $h ) {
					if ( self::host_matches( $src, $h ) ) {
						return $s;
					}
				}
				continue;
			}
			foreach ( (array) ( isset( $s['inline'] ) ? $s['inline'] : array() ) as $needle ) {
				if ( '' !== $needle && false !== stripos( $body, $needle ) ) {
					return $s;
				}
			}
			if ( ! empty( $s['gtm'] ) && false !== strpos( $body, 'GTM-' ) && false !== strpos( $body, 'dataLayer' ) ) {
				return $s;
			}
		}
		return null;
	}

	/** Pure: first embed-type service an iframe src belongs to (honours 'path'). */
	public static function embed_service( $src, array $services ) {
		foreach ( $services as $s ) {
			if ( ! isset( $s['type'] ) || 'embed' !== $s['type'] ) {
				continue;
			}
			$path = isset( $s['path'] ) ? (string) $s['path'] : '';
			foreach ( (array) ( isset( $s['hosts'] ) ? $s['hosts'] : array() ) as $h ) {
				if ( self::host_matches( $src, $h . $path ) ) {
					return $s;
				}
			}
		}
		return null;
	}
}
