<?php
require_once dirname( __DIR__ ) . '/class-services.php';

/**
 * Pure helpers of the site scanner: which pages to scan, where a script comes
 * from, and which third-party resources leak the visitor's IP. No WordPress
 * calls: the caller passes the home URL and the data in.
 */
class CMP_Scanner {

	const MAX_PAGES = 20;

	/** Host (exact, or any subdomain when the entry starts with '*.') => resource type. */
	const IP_HOSTS = array(
		'fonts.googleapis.com'    => 'google_fonts',
		'fonts.gstatic.com'       => 'google_fonts',
		'use.typekit.net'         => 'adobe_fonts',
		'p.typekit.net'           => 'adobe_fonts',
		'use.fontawesome.com'     => 'font_awesome',
		'kit.fontawesome.com'     => 'font_awesome',
		'cdn.jsdelivr.net'        => 'cdn',
		'unpkg.com'               => 'cdn',
		'cdnjs.cloudflare.com'    => 'cdn',
		'secure.gravatar.com'     => 'gravatar',
		'*.gravatar.com'          => 'gravatar',
		's.w.org'                 => 'emoji',
	);

	/** Host of a URL, lowercased, without a leading www. ('' when none). */
	private static function bare_host( $url ) {
		$h = parse_url( (string) $url, PHP_URL_HOST );
		if ( ! is_string( $h ) || '' === $h ) {
			return '';
		}
		$h = strtolower( $h );
		return 0 === strpos( $h, 'www.' ) ? substr( $h, 4 ) : $h;
	}

	/** Pure: is the URL on the site's domain (home host with or without www., plus subdomains)? */
	public static function is_own_host( $url, $home ) {
		$url = (string) $url;
		if ( 1 !== preg_match( '#^(?:https?:)?//#i', $url ) ) {
			return false;
		}
		$own = self::bare_host( $home );
		$h   = self::bare_host( $url );
		if ( '' === $own || '' === $h ) {
			return false;
		}
		return $h === $own || substr( $h, -( strlen( $own ) + 1 ) ) === '.' . $own;
	}

	/** Comparison key only (never output): scheme-less, lowercase host, no fragment, no trailing slash. */
	private static function url_key( $url ) {
		$u = preg_replace( '/#.*$/s', '', trim( (string) $url ) );
		$u = preg_replace( '#^https?://#i', '', (string) $u );
		if ( 1 === preg_match( '#^([^/?]*)(.*)$#s', $u, $m ) ) {
			$u = strtolower( $m[1] ) . rtrim( $m[2], '/' );
		}
		return $u;
	}

	/**
	 * Pure: the ordered, de-duplicated list of own-domain pages to scan (max 20):
	 * home, legal, latest, manual additions, sitemap. $manual = array( 'add' => [], 'exclude' => [] ).
	 */
	public static function page_list( $home, array $legal_urls, array $latest_urls, array $sitemap_urls, array $manual ) {
		$exclude = array();
		foreach ( (array) ( isset( $manual['exclude'] ) ? $manual['exclude'] : array() ) as $u ) {
			if ( is_string( $u ) && '' !== trim( $u ) ) {
				$exclude[ self::url_key( $u ) ] = true;
			}
		}
		$add    = isset( $manual['add'] ) && is_array( $manual['add'] ) ? $manual['add'] : array();
		$groups = array( array( $home ), $legal_urls, $latest_urls, $add, $sitemap_urls );
		$out    = array();
		foreach ( $groups as $group ) {
			foreach ( $group as $u ) {
				if ( ! is_string( $u ) ) {
					continue;
				}
				$u = trim( $u );
				if ( 1 !== preg_match( '#^https?://#i', $u ) || ! self::is_own_host( $u, $home ) ) {
					continue;
				}
				$k = self::url_key( $u );
				if ( isset( $exclude[ $k ] ) || isset( $out[ $k ] ) ) {
					continue;
				}
				// The first original URL is kept as is (minus its fragment); an excluded home is dropped too.
				$out[ $k ] = (string) preg_replace( '/#.*$/s', '', $u );
				if ( count( $out ) >= self::MAX_PAGES ) {
					return array_values( $out );
				}
			}
		}
		return array_values( $out );
	}

	const SITEMAP_MAX_BYTES = 5242880;
	const SITEMAP_MAX_URLS  = 1000;

	/** Pure: only http(s) locations, trimmed, capped. */
	private static function clean_locs( array $locs ) {
		$out = array();
		foreach ( $locs as $loc ) {
			$loc = trim( (string) $loc );
			if ( 1 === preg_match( '#^https?://#i', $loc ) ) {
				$out[] = $loc;
				if ( count( $out ) >= self::SITEMAP_MAX_URLS ) {
					break;
				}
			}
		}
		return $out;
	}

	/** Pure: regex-only sitemap reader (no DOM, so no entity handling at all). Never throws. */
	public static function parse_sitemap_regex( $xml ) {
		$res = array( 'index' => array(), 'urls' => array() );
		$xml = substr( (string) $xml, 0, self::SITEMAP_MAX_BYTES );
		if ( 1 === preg_match( '/<(?:[a-z0-9_-]+:)?sitemapindex\b/i', $xml ) ) {
			$key = 'index';
		} elseif ( 1 === preg_match( '/<(?:[a-z0-9_-]+:)?urlset\b/i', $xml ) ) {
			$key = 'urls';
		} else {
			return $res;
		}
		if ( preg_match_all( '#<loc>\s*(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?\s*</loc>#is', $xml, $m ) ) {
			$res[ $key ] = self::clean_locs( array_map( 'html_entity_decode', $m[1] ) );
		}
		return $res;
	}

	/** Pure: parse a sitemap. Returns array( 'index' => sub-sitemap URLs, 'urls' => page URLs ). Never throws. */
	public static function parse_sitemap( $xml ) {
		$xml = substr( (string) $xml, 0, self::SITEMAP_MAX_BYTES );
		if ( '' === trim( $xml ) ) {
			return array( 'index' => array(), 'urls' => array() );
		}
		if ( ! class_exists( 'DOMDocument' ) ) {
			return self::parse_sitemap_regex( $xml );
		}
		$res  = array( 'index' => array(), 'urls' => array() );
		$prev = function_exists( 'libxml_use_internal_errors' ) ? libxml_use_internal_errors( true ) : null;
		$ok   = false;
		$doc  = null;
		try {
			$doc = new DOMDocument();
			// LIBXML_NONET: never fetch external entities; no NOENT/DTDLOAD, so entities are not expanded.
			$ok = $doc->loadXML( $xml, LIBXML_NONET );
		} catch ( \Throwable $e ) {
			$ok = false;
		}
		if ( null !== $prev ) {
			libxml_clear_errors();
			libxml_use_internal_errors( $prev );
		}
		if ( ! $ok || ! $doc || ! $doc->documentElement ) {
			return self::parse_sitemap_regex( $xml );
		}
		$root = strtolower( (string) $doc->documentElement->localName );
		$key  = 'sitemapindex' === $root ? 'index' : ( 'urlset' === $root ? 'urls' : '' );
		if ( '' === $key ) {
			return $res;
		}
		$item = 'index' === $key ? 'sitemap' : 'url';
		$locs = array();
		foreach ( $doc->getElementsByTagNameNS( '*', $item ) as $node ) {
			foreach ( $node->childNodes as $c ) {
				if ( XML_ELEMENT_NODE === $c->nodeType && 'loc' === strtolower( (string) $c->localName ) ) {
					$locs[] = (string) $c->textContent;
				}
			}
			if ( count( $locs ) > self::SITEMAP_MAX_URLS * 2 ) {
				break;
			}
		}
		$res[ $key ] = self::clean_locs( $locs );
		return $res;
	}

	/**
	 * Pure: where a URL comes from. kind = plugin|theme|core|external|inline|site.
	 * site = an own-domain file outside plugins, themes and core (uploads, /assets/, mu-plugins...);
	 * inline = no URL at all (script body only).
	 */
	public static function source_for( $url, $home ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return array( 'kind' => 'inline', 'slug' => '' );
		}
		if ( 1 === preg_match( '#^//#', $url ) ) {
			$url = 'https:' . $url;
		}
		if ( 1 === preg_match( '#^https?://#i', $url ) && ! self::is_own_host( $url, $home ) ) {
			$h = parse_url( $url, PHP_URL_HOST );
			return array( 'kind' => 'external', 'slug' => is_string( $h ) ? strtolower( $h ) : '' );
		}
		$path = parse_url( $url, PHP_URL_PATH );
		$path = is_string( $path ) ? $path : '';
		if ( preg_match( '#(?:^|/)wp-content/plugins/([^/]+)/#', $path, $m ) ) {
			return array( 'kind' => 'plugin', 'slug' => $m[1] );
		}
		if ( preg_match( '#(?:^|/)wp-content/themes/([^/]+)/#', $path, $m ) ) {
			return array( 'kind' => 'theme', 'slug' => $m[1] );
		}
		if ( preg_match( '#(?:^|/)wp-(?:includes|admin)/#', $path ) ) {
			return array( 'kind' => 'core', 'slug' => '' );
		}
		return array( 'kind' => 'site', 'slug' => '' );
	}

	/** Decoded data-cmp-* base64 value of a tag's attributes ('' when absent or not valid base64). */
	private static function decoded_attr( $attrs, $name ) {
		if ( 1 !== preg_match( '/(?:^|\s)' . $name . '\s*=\s*(["\'])(.*?)\1/is', $attrs, $m ) ) {
			return '';
		}
		$d = base64_decode( $m[2], true );
		return is_string( $d ) ? $d : '';
	}

	/** Visible <script> tags as array( 'src' => string, 'body' => string ), rewritten ones decoded. */
	private static function script_tags( $html ) {
		$out = array();
		if ( ! preg_match_all( '/<script\b([^>]*)>(.*?)<\/script>/is', (string) $html, $tags, PREG_SET_ORDER ) ) {
			return $out;
		}
		foreach ( $tags as $t ) {
			$attrs = $t[1];
			$src   = '';
			$body  = (string) $t[2];
			if ( 1 === preg_match( '/(?:^|\s)data-cmp-enc\b/i', $attrs ) ) {
				$src = self::decoded_attr( $attrs, 'data-cmp-src' );
				if ( '' === $src ) {
					$body = self::decoded_attr( $attrs, 'data-cmp-body' );
				}
			} elseif ( preg_match( '/(?:^|\s)src\s*=\s*(?:(["\'])(.*?)\1|([^\s"\'>]+))/is', $attrs, $sm ) ) {
				$src = trim( html_entity_decode( isset( $sm[3] ) && '' !== $sm[3] ? $sm[3] : $sm[2], ENT_QUOTES ) );
			}
			$out[] = array( 'src' => $src, 'body' => $body );
		}
		return $out;
	}

	/**
	 * Pure: per detected service, the sources that load it:
	 * array( service_id => array( array( 'kind' => .., 'slug' => .. ), ... ) ).
	 */
	public static function script_sources( $html, array $services, $home ) {
		$res = array();
		foreach ( self::script_tags( $html ) as $tag ) {
			$svc = CMP_Services::script_service( $tag['src'], $tag['body'], $services );
			if ( null === $svc && '' !== $tag['src'] ) {
				$svc = CMP_Services::script_service( '', $tag['body'], $services );
			}
			if ( null === $svc || empty( $svc['id'] ) ) {
				continue;
			}
			$src = '' !== $tag['src'] ? self::source_for( $tag['src'], $home ) : array( 'kind' => 'inline', 'slug' => '' );
			$id  = (string) $svc['id'];
			if ( ! isset( $res[ $id ] ) ) {
				$res[ $id ] = array();
			}
			if ( ! in_array( $src, $res[ $id ], true ) ) {
				$res[ $id ][] = $src;
			}
		}
		return $res;
	}

	/** Pure: resource type for a host, or ''. */
	private static function ip_type( $host ) {
		$host = strtolower( (string) $host );
		foreach ( self::IP_HOSTS as $h => $type ) {
			if ( 0 === strpos( $h, '*.' ) ) {
				if ( substr( $host, -strlen( $h ) + 1 ) === substr( $h, 1 ) ) {
					return $type;
				}
			} elseif ( $host === $h ) {
				return $type;
			}
		}
		return '';
	}

	/**
	 * Pure: third-party resources in the HTML that send the visitor's IP.
	 * array( array( 'type' => .., 'host' => .., 'source' => array( kind, slug ) ) ), one per type+host.
	 * A resource named in an inline script body gets source 'inline'; one referenced from a tag
	 * attribute gets its own host as an 'external' source.
	 */
	public static function ip_resources( $html, $home ) {
		$html = (string) $html;
		$seen = array();
		$out  = array();
		$add  = function ( $url, $inline ) use ( $home, &$seen, &$out ) {
			$url = trim( html_entity_decode( (string) $url, ENT_QUOTES ) );
			if ( 1 === preg_match( '#^//#', $url ) ) {
				$url = 'https:' . $url;
			}
			if ( 1 !== preg_match( '#^https?://#i', $url ) || self::is_own_host( $url, $home ) ) {
				return;
			}
			$host = parse_url( $url, PHP_URL_HOST );
			if ( ! is_string( $host ) ) {
				return;
			}
			$host = strtolower( $host );
			$type = self::ip_type( $host );
			$key  = $type . '|' . $host;
			if ( '' === $type || isset( $seen[ $key ] ) ) {
				return;
			}
			$seen[ $key ] = true;
			$out[]        = array(
				'type'   => $type,
				'host'   => $host,
				'source' => $inline ? array( 'kind' => 'inline', 'slug' => '' ) : array( 'kind' => 'external', 'slug' => $host ),
			);
		};
		// Inline script bodies first (including blocked ones, decoded), so they win over tag attributes.
		$bodies = '';
		foreach ( self::script_tags( $html ) as $tag ) {
			if ( '' === $tag['src'] ) {
				$bodies .= "\n" . $tag['body'];
			}
		}
		$scan = function ( $text, $inline ) use ( $add ) {
			if ( preg_match_all( '#(?:https?:)?\\\\?/\\\\?/[a-z0-9.-]+#i', $text, $m ) ) {
				foreach ( $m[0] as $u ) {
					$add( str_replace( '\\', '', $u ), $inline );
				}
			}
		};
		$scan( $bodies, true );
		$tags = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $html );
		if ( preg_match_all( '/<[a-z][^>]*>/i', (string) $tags, $tm ) ) {
			foreach ( $tm[0] as $tag ) {
				if ( preg_match_all( '/(?:^|[\s"\'\/])(?:src|href|srcset|data-src|style)\s*=\s*(?:(["\'])(.*?)\1|([^\s"\'>]+))/is', $tag, $am, PREG_SET_ORDER ) ) {
					foreach ( $am as $a ) {
						$scan( isset( $a[3] ) && '' !== $a[3] ? $a[3] : $a[2], false );
					}
				}
			}
		}
		if ( preg_match_all( '/<style\b[^>]*>(.*?)<\/style>/is', $html, $sb ) ) {
			foreach ( $sb[1] as $css ) {
				$scan( $css, false );
			}
		}
		// Rewritten script tags keep their URL base64-encoded.
		foreach ( self::script_tags( $html ) as $tag ) {
			if ( '' !== $tag['src'] ) {
				$add( $tag['src'], false );
			}
		}
		return $out;
	}
}
