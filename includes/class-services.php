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

	private static $cache    = null;
	private static $errors   = array();
	private static $fallback = false;
	private static $active   = null;

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

	/**
	 * Built-in rules for the 6 services 0.4.x always blocked, used when
	 * data/services.json is missing or unreadable so blocking never switches off.
	 * Same hosts, markers and categories as their JSON entries (a test keeps them
	 * in sync). Cookie details live only in the JSON.
	 */
	public static function fallback() {
		$g    = 'Google Ireland Ltd.';
		$list = array(
			array( 'id' => 'google-analytics', 'name' => 'Google Analytics', 'provider' => $g, 'type' => 'script', 'category' => 'statistics', 'hosts' => array( 'google-analytics.com', 'googletagmanager.com/gtag/js' ), 'inline' => array( "gtag('config'", 'gtag("config"', "ga('create'" ), 'gtm' => false ),
			array( 'id' => 'google-tag-manager', 'name' => 'Google Tag Manager', 'provider' => $g, 'type' => 'script', 'category' => 'statistics', 'hosts' => array( 'googletagmanager.com' ), 'inline' => array(), 'gtm' => true ),
			array( 'id' => 'meta-pixel', 'name' => 'Meta Pixel', 'provider' => 'Meta Platforms Ireland Ltd.', 'type' => 'script', 'category' => 'marketing', 'hosts' => array( 'connect.facebook.net' ), 'inline' => array( "fbq('init'", 'fbq("init"', "fbq('track'", 'fbq("track"' ), 'gtm' => false ),
			array( 'id' => 'youtube', 'name' => 'YouTube', 'provider' => $g, 'type' => 'embed', 'category' => 'marketing', 'hosts' => array( 'youtube.com', 'youtube-nocookie.com', 'youtu.be' ), 'nocookie' => true ),
			array( 'id' => 'vimeo', 'name' => 'Vimeo', 'provider' => 'Vimeo.com, Inc.', 'type' => 'embed', 'category' => 'statistics', 'hosts' => array( 'vimeo.com' ) ),
			array( 'id' => 'google-maps', 'name' => 'Google Maps', 'provider' => $g, 'type' => 'embed', 'category' => 'marketing', 'hosts' => array( 'google.com', 'google.md', 'google.ro' ), 'path' => '/maps' ),
		);
		$out = array();
		foreach ( $list as $s ) {
			$out[] = array_merge( self::defaults(), $s );
		}
		return $out;
	}

	/**
	 * Pure: load a library file, falling back to the built-in legacy rules when it
	 * yields no services. Returns services, errors and whether the fallback is used.
	 */
	public static function library_from( $path ) {
		$r        = self::load_file( $path );
		$fallback = ! $r['services'];
		if ( $fallback ) {
			$r['services'] = self::fallback();
			if ( ! $r['errors'] ) {
				$r['errors'][] = 'Services file has no services: ' . $path;
			}
		}
		return array( 'services' => $r['services'], 'errors' => $r['errors'], 'fallback' => $fallback );
	}

	/** The plugin's library (read and parsed once per request). */
	public static function library() {
		if ( null === self::$cache ) {
			$r              = self::library_from( dirname( __DIR__ ) . '/' . self::FILE );
			self::$cache    = $r['services'];
			self::$errors   = $r['errors'];
			self::$fallback = $r['fallback'];
		}
		return self::$cache;
	}

	/** True when data/services.json failed to load and the built-in rules are in use. */
	public static function using_fallback() {
		self::library();
		return self::$fallback;
	}

	/** Drop the per-request active() cache (hooked to the cmp_services / cmp_categories option changes). */
	public static function flush() {
		self::$active = null;
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

	/** Pure: union of the cumulative seen list and a fresh scan; seen order kept, no duplicates. */
	public static function merge_seen( array $seen, array $found ) {
		$out = array();
		foreach ( array_merge( $seen, $found ) as $id ) {
			if ( is_string( $id ) && '' !== $id && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Pure: the cumulative list to use. An array history wins; else the last scan's
	 * found list; else null ("no scan ever": the map is unknown).
	 */
	public static function history_or_found( $history, $found ) {
		if ( is_array( $history ) ) {
			return $history;
		}
		return is_array( $found ) ? $found : null;
	}

	/**
	 * Pure: {category slug: [service ids]} of the services that can be blocked
	 * (not necessary, not exempt) and were seen on the site or are custom.
	 * Ids sorted; categories without services are left out.
	 */
	public static function svc_by_cat( array $services, array $seen ) {
		$out = array();
		foreach ( $services as $s ) {
			if ( ! is_array( $s ) || empty( $s['id'] ) || empty( $s['category'] ) || 'necessary' === $s['category'] || ! empty( $s['exempt'] ) ) {
				continue;
			}
			$custom = isset( $s['source'] ) && 'custom' === $s['source'];
			if ( $custom || in_array( $s['id'], $seen, true ) ) {
				$out[ (string) $s['category'] ][] = (string) $s['id'];
			}
		}
		foreach ( $out as $slug => $ids ) {
			$ids = array_values( array_unique( $ids ) );
			sort( $ids );
			$out[ $slug ] = $ids;
		}
		return $out;
	}

	/** WordPress wrapper: library + cmp_services option + current category slugs (memoised per request). */
	public static function active() {
		if ( null !== self::$active ) {
			return self::$active;
		}
		$stored = function_exists( 'get_option' ) ? get_option( 'cmp_services', array() ) : array();
		$slugs  = array();
		if ( function_exists( 'get_option' ) && class_exists( 'CMP_Consent' ) ) {
			foreach ( (array) CMP_Consent::get_categories() as $cat ) {
				if ( is_array( $cat ) && ! empty( $cat['slug'] ) ) {
					$slugs[] = (string) $cat['slug'];
				}
			}
		}
		self::$active = self::all( self::library(), is_array( $stored ) ? $stored : array(), $slugs );
		return self::$active;
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

	/**
	 * Pure: the first custom service whose hosts match an iframe src (custom
	 * services are script-type, but the admin's domains apply to iframes too).
	 */
	public static function custom_embed_service( $src, array $services ) {
		foreach ( $services as $s ) {
			if ( ! is_array( $s ) || ! isset( $s['source'] ) || 'custom' !== $s['source'] ) {
				continue;
			}
			foreach ( (array) ( isset( $s['hosts'] ) ? $s['hosts'] : array() ) as $h ) {
				if ( self::host_matches( $src, $h ) ) {
					return $s;
				}
			}
		}
		return null;
	}

	/** Pure: the service an iframe src belongs to: a library embed first, else a custom service. */
	public static function iframe_service( $src, array $services ) {
		$svc = self::embed_service( $src, $services );
		return null !== $svc ? $svc : self::custom_embed_service( $src, $services );
	}

	/**
	 * Pure: id => name of the services that can appear as an embed placeholder
	 * (library embeds, custom services with hosts), for the "always loaded" row.
	 */
	public static function svc_names( array $services ) {
		$out = array();
		foreach ( $services as $s ) {
			if ( ! is_array( $s ) || empty( $s['id'] ) ) {
				continue;
			}
			$embed  = isset( $s['type'] ) && 'embed' === $s['type'];
			$custom = isset( $s['source'] ) && 'custom' === $s['source'] && ! empty( $s['hosts'] );
			if ( $embed || $custom ) {
				$name                       = isset( $s['name'] ) ? (string) $s['name'] : '';
				$out[ (string) $s['id'] ] = '' !== $name ? $name : (string) $s['id'];
			}
		}
		return $out;
	}
}
