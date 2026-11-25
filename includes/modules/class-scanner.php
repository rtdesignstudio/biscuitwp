<?php
require_once dirname( __DIR__ ) . '/class-services.php';
require_once __DIR__ . '/class-blocker.php';
require_once __DIR__ . '/class-fonts.php';

/**
 * The site scanner. The upper part is pure (no WordPress calls: the caller
 * passes the home URL and the data in): which pages to scan, where a script
 * comes from, which third-party resources leak the visitor's IP, per-page
 * results, the aggregate and the queue. The lower part is the WordPress side:
 * the WP-Cron queue (with an admin-only inline fallback) and the fetches.
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
		// PHP < 8 does not match namespace-less elements with the '*' wildcard:
		// fall back to the plain name lookup for a sitemap without xmlns.
		$nodes = $doc->getElementsByTagNameNS( '*', $item );
		if ( 0 === $nodes->length ) {
			$nodes = $doc->getElementsByTagName( $item );
		}
		foreach ( $nodes as $node ) {
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

	/** At most this many unknown hosts are stored per page (and per path in the aggregate). */
	const UNKNOWN_MAX_HOSTS = 200;

	/** Pure: a host name worth reporting (letters, digits, dots, hyphens; a dot; at most 100 characters). */
	private static function reportable_host( $host ) {
		return strlen( $host ) <= 100 && 1 === preg_match( '/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $host ) && false !== strpos( $host, '.' ) && false === strpos( $host, '..' );
	}

	/** Pure: does a tag's attribute string carry a valid data-cmp-category (an admin decision) or one of our own markers? */
	private static function decided_tag( $attrs ) {
		if ( 1 === preg_match( '/(?:^|[\s"\'\/])(?:data-cmp-keep|data-cmp-enc|data-cmp-block)(?=[\s=\/>]|$)/i', $attrs ) ) {
			return true; // ours: kept on purpose, or already rewritten by the blocker (known by definition)
		}
		if ( 1 === preg_match( '/(?:^|[\s"\'\/])data-cmp-category\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>\/]+))/i', $attrs, $m ) ) {
			$v = '';
			for ( $i = 1; $i <= 3; $i++ ) {
				if ( isset( $m[ $i ] ) && '' !== $m[ $i ] ) {
					$v = $m[ $i ];
					break;
				}
			}
			return 1 === preg_match( '/^[a-z0-9_-]{1,40}$/', strtolower( trim( html_entity_decode( $v, ENT_QUOTES ) ) ) );
		}
		return false;
	}

	/** Pure: is this host no news: the site's own, an IP_HOSTS resource or part of a service? (Used to drop what a service now covers.) */
	public static function host_known( $host, array $services, $home ) {
		$host = strtolower( trim( (string) $host ) );
		if ( '' === $host ) {
			return true;
		}
		$url = 'https://' . $host . '/';
		return self::is_own_host( $url, $home ) || '' !== self::ip_type( $host ) || null !== self::host_service( $url, $services );
	}

	/**
	 * Pure: the attribute strings of the <script> and <iframe> elements of a page,
	 * in page order, read left to right: a comment, <noscript>, <template>, <style>,
	 * <textarea>, <title> or script is passed over whole, so what is written inside one (markup in a script
	 * string, a script tag in a comment) is never an element and never hides a
	 * later tag. One that is not closed hides nothing. Null when PCRE gave up.
	 */
	private static function element_attrs( $html ) {
		$out  = array();
		$at   = 0;
		$open = array(); // tags never closed from here on: not searched again
		while ( true ) {
			$hit = preg_match( '#<!--|<(noscript|template|style|textarea|title|script|iframe)(?=[\s/>])([^>]*)>#i', $html, $m, PREG_OFFSET_CAPTURE, $at );
			if ( false === $hit ) {
				return null;
			}
			if ( 1 !== $hit ) {
				return $out;
			}
			$at  = $m[0][1] + strlen( $m[0][0] );
			$tag = isset( $m[1] ) ? strtolower( $m[1][0] ) : '';
			if ( 'script' === $tag || 'iframe' === $tag ) {
				$out[] = $m[2][0];
			}
			// "<title/>" and "<style/>" inside an <svg> are empty.
			if ( 'iframe' === $tag || isset( $open[ $tag ] ) || ( in_array( $tag, array( 'title', 'style' ), true ) && '/' === substr( $m[2][0], -1 ) ) ) {
				continue;
			}
			// "<!-->" and "<!--->" are whole comments: their end may overlap the start.
			$end = '' === $tag
				? preg_match( '#--!?>#', $html, $e, PREG_OFFSET_CAPTURE, $at - 2 )
				: preg_match( '#</' . $tag . '(?=[\s/>])[^>]*>#i', $html, $e, PREG_OFFSET_CAPTURE, $at );
			if ( false === $end ) {
				return null;
			}
			if ( 1 === $end ) {
				$at = $e[0][1] + strlen( $e[0][0] );
			} else {
				$open[ $tag ] = true;
			}
		}
	}

	/**
	 * Pure: the lowercase, unique hosts that load a <script src> or an <iframe src>
	 * on the page and are not the site's own, not part of a service (library or
	 * custom, whatever its category or exemption, embeds with their path), not one
	 * of the IP_HOSTS resources and not already decided (data-cmp-category) or
	 * rewritten by our blocker (data-cmp-enc / <template data-cmp-embed-src>).
	 * Comments, <noscript>, <template> and script bodies are not elements, so
	 * what they mention does not count. Relative, data:, blob: and javascript: URLs have no host.
	 * At most UNKNOWN_MAX_HOSTS. Null when PCRE gave up: the page was not read.
	 *
	 * Limits (the blocker's own patterns have them too): an iframe written in
	 * a raw attribute value counts, and so does a comment mark there; not seen: international (IDN) hosts, <iframe data-src>, a src with no
	 * space before it, an attribute with ">" in its value before src, anything
	 * JavaScript or Tag Manager adds after loading. A service that covers only a
	 * path of a host does not cover the host (host_known()): a host queued for the
	 * email before such a service was added is still mailed, once.
	 *
	 * @return string[]|null
	 */
	public static function unknown_hosts( $html, array $services, $home ) {
		$list = self::element_attrs( (string) $html );
		if ( null === $list ) {
			return null;
		}
		$urls = array();
		foreach ( $list as $attrs ) {
			if ( ! self::decided_tag( $attrs ) && preg_match( '/(?:^|\s)src\s*=\s*(?:(["\'])(.*?)\1|([^\s"\'>]+))/is', $attrs, $sm ) ) {
				$urls[] = isset( $sm[3] ) && '' !== $sm[3] ? $sm[3] : $sm[2];
			}
		}
		$out = array();
		foreach ( $urls as $u ) {
			$u = trim( html_entity_decode( (string) $u, ENT_QUOTES ) );
			if ( 1 === preg_match( '#^//#', $u ) ) {
				$u = 'https:' . $u;
			}
			if ( 1 !== preg_match( '#^https?://#i', $u ) || self::is_own_host( $u, $home ) ) {
				continue;
			}
			$host = parse_url( $u, PHP_URL_HOST );
			if ( ! is_string( $host ) ) {
				continue;
			}
			$host = rtrim( strtolower( $host ), '.' );
			if ( ! self::reportable_host( $host ) || '' !== self::ip_type( $host ) || null !== self::host_service( $u, $services ) ) {
				continue;
			}
			if ( ! in_array( $host, $out, true ) ) {
				$out[] = $host;
				if ( count( $out ) >= self::UNKNOWN_MAX_HOSTS ) {
					break;
				}
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Multi-page scan: per-page results, aggregate, queue (pure helpers).
	 * ------------------------------------------------------------------ */

	const PER_RUN          = 4;
	const FAIL_BACKOFF     = 300;
	const SITEMAP_MAX_SUBS = 3;
	const STALE_AFTER      = 3600;
	const INLINE_AFTER     = 900;
	const INLINE_PAGES     = 2;
	const LOCK_TTL         = 180;
	const LOCK_OPTION      = 'cmp_scan_lock';
	const SAVED_OPTION     = 'cmp_scan_saved_at';

	const URLS_OPTION      = 'cmp_scan_urls';
	const QUEUE_OPTION     = 'cmp_scan_queue';
	const PAGES_OPTION     = 'cmp_scan_pages';
	const STATE_OPTION     = 'cmp_scan_state';
	const AGGREGATE_OPTION = 'cmp_scan_aggregate';
	const RUN_HOOK         = 'cmp_scan_run';
	const DAILY_HOOK       = 'cmp_scan_daily';

	/**
	 * Pure: one page's result. An error page (status other than 'ok') keeps only
	 * url, time and status. An ok page carries the consumer shape of
	 * CMP_Blocker::scan_html() plus home, sources, resources and fonts.
	 */
	public static function page_result( $url, $status, $html, array $services, $home, $time ) {
		$res = array( 'url' => (string) $url, 'time' => (int) $time, 'status' => (string) $status );
		if ( 'ok' !== $res['status'] ) {
			return $res;
		}
		$html             = (string) $html;
		$res             += CMP_Blocker::scan_html( $html, $services );
		$res['home']      = self::url_key( $url ) === self::url_key( $home );
		$res['sources']   = self::script_sources( $html, $services, $home );
		$res['resources'] = self::ip_resources( $html, $home );
		$res['fonts']     = CMP_Fonts::extract_font_links( $html );
		$unknown          = self::unknown_hosts( $html, $services, $home );
		if ( null !== $unknown ) { // no key = not read for unknown hosts (aggregate: "unread")
			$res['unknown'] = $unknown;
		}
		return $res;
	}

	/** Pure: $list plus the new values of $add (strict, order kept). */
	private static function union( array $list, $add ) {
		foreach ( (array) $add as $v ) {
			if ( ! in_array( $v, $list, true ) ) {
				$list[] = $v;
			}
		}
		return $list;
	}

	/** Pure: is this page result usable (fetched with 200)? */
	private static function page_ok( $p ) {
		return is_array( $p ) && isset( $p['status'] ) && 'ok' === $p['status'];
	}

	/**
	 * Pure: the site result from the per-page results, in the shape of the old
	 * homepage scan (raw, blocked, settings_link, services { raw, blocked, found },
	 * time) plus sources, resources, pages (ok count) and failed (urls).
	 * Error pages are ignored. settings_link comes from the home page only and
	 * is absent when the home page has no ok result.
	 */
	public static function aggregate( array $pages ) {
		$agg = array(
			'raw'       => array(),
			'blocked'   => array(),
			'services'  => array( 'raw' => array(), 'blocked' => array(), 'found' => array() ),
			'time'      => 0,
			'sources'   => array(),
			'resources' => array(),
			'pages'     => 0,
			'failed'    => array(),
			// Which pages really answered "what is unknown here" (path => hosts), and which paths of the list did not (CMP_Alerts).
			'unknown_pages' => array(),
			'unread'        => array(),
		);
		$seen_res = array();
		foreach ( $pages as $p ) {
			if ( ! self::page_ok( $p ) ) {
				if ( is_array( $p ) && isset( $p['url'] ) ) {
					$agg['failed'][] = (string) $p['url'];
					$agg['unread'][] = self::url_path( $p['url'] );
				}
				continue;
			}
			if ( ! empty( $p['stale'] ) && isset( $p['url'] ) ) {
				$agg['failed'][] = (string) $p['url']; // last fetch failed: its previous good result is used
			}
			$agg['pages']++;
			$agg['raw']     = self::union( $agg['raw'], isset( $p['raw'] ) ? $p['raw'] : array() );
			$agg['blocked'] = self::union( $agg['blocked'], isset( $p['blocked'] ) ? $p['blocked'] : array() );
			foreach ( array( 'raw', 'blocked', 'found' ) as $k ) {
				$agg['services'][ $k ] = self::union( $agg['services'][ $k ], isset( $p['services'][ $k ] ) ? $p['services'][ $k ] : array() );
			}
			$agg['time'] = max( $agg['time'], isset( $p['time'] ) ? (int) $p['time'] : 0 );
			if ( ! empty( $p['home'] ) && ! array_key_exists( 'settings_link', $agg ) && isset( $p['settings_link'] ) ) {
				$agg['settings_link'] = (bool) $p['settings_link'];
			}
			foreach ( ( isset( $p['sources'] ) && is_array( $p['sources'] ) ? $p['sources'] : array() ) as $id => $srcs ) {
				$agg['sources'][ $id ] = self::union( isset( $agg['sources'][ $id ] ) ? $agg['sources'][ $id ] : array(), $srcs );
			}
			// A stale page (last fetch failed) keeps its old good result for the services, but its
			// unknown hosts were already handled when that result was fresh: no news from it.
			$path = isset( $p['url'] ) ? self::url_path( $p['url'] ) : '/';
			if ( empty( $p['stale'] ) && isset( $p['unknown'] ) && is_array( $p['unknown'] ) ) {
				// Two addresses may share a path (plain permalinks: /?p=1, /?p=2).
				$hosts = self::union( isset( $agg['unknown_pages'][ $path ] ) ? $agg['unknown_pages'][ $path ] : array(), array_filter( $p['unknown'], 'is_string' ) );
				$agg['unknown_pages'][ $path ] = array_slice( $hosts, 0, self::UNKNOWN_MAX_HOSTS );
			} else {
				$agg['unread'][] = $path; // stale, saved before the "unknown" key existed, or PCRE gave up
			}
			foreach ( ( isset( $p['resources'] ) && is_array( $p['resources'] ) ? $p['resources'] : array() ) as $r ) {
				if ( ! is_array( $r ) || ! isset( $r['type'], $r['host'] ) ) {
					continue;
				}
				$key = $r['type'] . '|' . $r['host'];
				if ( ! isset( $seen_res[ $key ] ) ) {
					$seen_res[ $key ]   = true;
					$agg['resources'][] = $r;
				}
			}
		}
		$agg['unread'] = array_values( array_diff( array_unique( $agg['unread'] ), array_keys( $agg['unknown_pages'] ) ) );
		return $agg;
	}

	/** Pure: the page path of a URL without query or fragment ('/' when none), capped. */
	private static function url_path( $url ) {
		$path = parse_url( (string) $url, PHP_URL_PATH );
		$path = is_string( $path ) && '' !== $path ? preg_replace( '/[\x00-\x1f\x7f]/', '', $path ) : '/';
		return substr( (string) $path, 0, 200 );
	}

	/** Pure: Google Fonts links from every ok page, deduped. */
	public static function font_links( array $pages ) {
		$out = array();
		foreach ( $pages as $p ) {
			if ( self::page_ok( $p ) && isset( $p['fonts'] ) ) {
				$out = self::union( $out, $p['fonts'] );
			}
		}
		return $out;
	}

	/** Pure: split the queue into array( 'take' => first $n, 'rest' => the others ). */
	public static function queue_take( array $queue, $n ) {
		$queue = array_values( $queue );
		$n     = max( 0, (int) $n );
		return array( 'take' => array_slice( $queue, 0, $n ), 'rest' => array_slice( $queue, $n ) );
	}

	/** Pure: $queue plus the urls of $urls not already in it (compared by normalized key). */
	public static function queue_add( array $queue, array $urls ) {
		$keys = array();
		foreach ( $queue as $u ) {
			$keys[ self::url_key( $u ) ] = true;
		}
		foreach ( $urls as $u ) {
			if ( ! is_string( $u ) || '' === $u ) {
				continue;
			}
			$k = self::url_key( $u );
			if ( ! isset( $keys[ $k ] ) ) {
				$keys[ $k ] = true;
				$queue[]    = $u;
			}
		}
		return array_values( $queue );
	}

	/** Pure: $queue without the urls of $done (compared by normalized key). */
	public static function queue_remove( array $queue, array $done ) {
		$drop = array();
		foreach ( $done as $u ) {
			$drop[ self::url_key( $u ) ] = true;
		}
		$out = array();
		foreach ( $queue as $u ) {
			if ( ! isset( $drop[ self::url_key( $u ) ] ) ) {
				$out[] = $u;
			}
		}
		return $out;
	}

	/** Pure: array( done, total ) for "Scanare în curs (X/Y)". */
	public static function progress( $state, array $queue ) {
		$total = is_array( $state ) && isset( $state['total'] ) ? (int) $state['total'] : 0;
		$total = max( $total, count( $queue ) );
		return array( $total - count( $queue ), $total );
	}

	/** Pure: did this page fail less than FAIL_BACKOFF seconds before $now? (A stale page: since its error.) */
	public static function in_backoff( $page, $now ) {
		if ( ! is_array( $page ) ) {
			return false;
		}
		if ( ! empty( $page['stale'] ) && isset( $page['error_time'] ) ) {
			return (int) $now - (int) $page['error_time'] < self::FAIL_BACKOFF;
		}
		return isset( $page['status'], $page['time'] ) && 'ok' !== $page['status']
			&& (int) $now - (int) $page['time'] < self::FAIL_BACKOFF;
	}

	/**
	 * Pure: the result to keep for a page. A successful fetch replaces whatever
	 * was there. A failed one keeps the previous good result, marked stale with
	 * the error and its time, so one timeout does not drop that page's services.
	 */
	public static function merge_page_result( $prev, array $new ) {
		if ( self::page_ok( $new ) || ! self::page_ok( $prev ) ) {
			return $new;
		}
		$prev['stale']      = true;
		$prev['error']      = isset( $new['status'] ) ? (string) $new['status'] : 'error';
		$prev['error_time'] = isset( $new['time'] ) ? (int) $new['time'] : 0;
		return $prev;
	}

	/** Pure: the cmp_tracker_scan copy, legacy keys only (it is autoloaded). */
	public static function legacy_copy( array $agg ) {
		$out = array();
		foreach ( array( 'raw', 'blocked', 'settings_link', 'services', 'time' ) as $k ) {
			if ( array_key_exists( $k, $agg ) ) {
				$out[ $k ] = $agg[ $k ];
			}
		}
		return $out;
	}

	/** Pure: last progress time of a run (an older state without it: its start). */
	private static function last_progress( $state ) {
		if ( ! is_array( $state ) ) {
			return 0;
		}
		return isset( $state['progress'] ) ? (int) $state['progress'] : ( isset( $state['started'] ) ? (int) $state['started'] : 0 );
	}

	/** Pure: a running scan with no progress for more than STALE_AFTER seconds. */
	public static function is_stale( $state, $now ) {
		return is_array( $state ) && isset( $state['status'] ) && 'running' === $state['status']
			&& (int) $now - self::last_progress( $state ) > self::STALE_AFTER;
	}

	/** Pure: what to do with a run: 'none', 'reschedule' (stale, pages left) or 'reenqueue' (stale, queue empty). */
	public static function resume_action( $state, array $queue, $now ) {
		if ( ! self::is_stale( $state, $now ) ) {
			return 'none';
		}
		return empty( $queue ) ? 'reenqueue' : 'reschedule';
	}

	/** Pure: array( done, total ) of a live run, null when idle or stale. */
	public static function progress_for( $state, array $queue, $now ) {
		if ( ! is_array( $state ) || ! isset( $state['status'] ) || 'running' !== $state['status'] || self::is_stale( $state, $now ) ) {
			return null;
		}
		return self::progress( $state, $queue );
	}

	/** Pure: run one batch inline on an admin screen? Cron disabled, or no progress for over INLINE_AFTER seconds. */
	public static function should_run_inline( $cron_disabled, $last_progress_ts, $now ) {
		return (bool) $cron_disabled || (int) $now - (int) $last_progress_ts > self::INLINE_AFTER;
	}

	/** Pure: were the results computed before the last Settings/Services save? */
	public static function outdated_after_save( $saved_at, $agg ) {
		$saved_at = (int) $saved_at;
		if ( $saved_at <= 0 ) {
			return false;
		}
		if ( ! is_array( $agg ) ) {
			return true;
		}
		$run = isset( $agg['run'] ) ? (int) $agg['run'] : ( isset( $agg['time'] ) ? (int) $agg['time'] : 0 );
		return $run < $saved_at;
	}

	/** Pure: may a lock taken at $taken be overridden at $now? */
	public static function lock_expired( $taken, $now ) {
		return (int) $now - (int) $taken > self::LOCK_TTL;
	}

	/**
	 * Pure: is the check mode on? $param is the raw ?cmp_check token (null when
	 * absent); $verify gets the token and says whether it is a valid cmp_check
	 * nonce of a user with manage_options. No token -> false, verify not called.
	 */
	public static function check_active( ?string $param, callable $verify ): bool {
		if ( null === $param || '' === $param ) {
			return false;
		}
		return (bool) $verify( $param );
	}

	/**
	 * Pure: the request side of check_mode(). $ctx: init (init has run), parsed
	 * (parse_request has run), admin, ajax, rest (REST_REQUEST, a JSON request or
	 * a REST URI). Returns active and final (whether the answer may be
	 * memoised). Before init: false, not final. Admin, AJAX, REST: false.
	 * Before parse_request the answer is given but not memoised, so a REST
	 * request that is only recognised at parse_request never keeps a `true`.
	 */
	public static function check_request( array $ctx, ?string $param, callable $verify ): array {
		if ( empty( $ctx['init'] ) ) {
			return array( 'active' => false, 'final' => false );
		}
		$final = ! empty( $ctx['parsed'] );
		if ( ! empty( $ctx['admin'] ) || ! empty( $ctx['ajax'] ) || ! empty( $ctx['rest'] ) ) {
			return array( 'active' => false, 'final' => $final );
		}
		return array( 'active' => self::check_active( $param, $verify ), 'final' => $final );
	}

	/** Pure: is this a REST URI (…/<prefix>/… in the path, or ?rest_route)? */
	public static function is_rest_uri( $uri, array $get, $prefix ): bool {
		if ( isset( $get['rest_route'] ) ) {
			return true;
		}
		$path = (string) parse_url( (string) $uri, PHP_URL_PATH );
		return '' !== (string) $prefix && false !== strpos( rtrim( $path, '/' ) . '/', '/' . trim( (string) $prefix, '/' ) . '/' );
	}

	/**
	 * Pure: CMP_DATA for the mode. Inactive -> unchanged. Active -> the check
	 * flag, the session check cookies and no reload after the decision.
	 */
	public static function check_data( array $data, bool $check ): array {
		if ( ! $check ) {
			return $data;
		}
		$names                    = CMP_Consent::cookie_names( true );
		$data['cookieName']       = $names['consent'];
		$data['reloadOnDecision'] = false;
		$data['check']            = true;
		$data['svcCookie']        = $names['svc'];
		return $data;
	}

	/* ------------------------------------------------------------------
	 * Browser check (Verificare): classification and the saved report (pure).
	 * ------------------------------------------------------------------ */

	const CHECK_OPTION        = 'cmp_check_report';
	const CHECK_MAX_RESOURCES = 300;
	const CHECK_MAX_COOKIES   = 100;
	const CHECK_MAX_URL       = 500;
	const MANUAL_MAX_URL      = 2000;

	/** Display names of the IP-leaking resource types. */
	const IP_LABELS = array(
		'google_fonts' => 'Google Fonts',
		'adobe_fonts'  => 'Adobe Fonts',
		'font_awesome' => 'Font Awesome',
		'cdn'          => 'CDN',
		'gravatar'     => 'Gravatar',
		'emoji'        => 'emoji WordPress',
	);

	/** Pure: a cookie set by WordPress or by this plugin (cmp_*, wp_consent_*): not part of the verdict. */
	private static function ignored_cookie( $name ) {
		return 1 === preg_match( '/^(?:cmp_|wp_consent_|wordpress_|wp-settings-|wp_lang$)/', (string) $name );
	}

	/** Pure: does a cookie name match a library pattern ('_ga', '_ga_*')? */
	private static function cookie_matches( $name, $pattern ) {
		$pattern = (string) $pattern;
		if ( '' === $pattern ) {
			return false;
		}
		if ( false === strpos( $pattern, '*' ) ) {
			return (string) $name === $pattern;
		}
		$re = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/';
		return 1 === preg_match( $re, (string) $name );
	}

	/**
	 * Pure: how a service loaded before consent counts: red, or why it is shown
	 * apart (necessary | exempt | advanced). Shared by classify() (browser
	 * verdict) and service_state() (server report label).
	 */
	public static function service_level( array $s, $mode ) {
		if ( isset( $s['category'] ) && 'necessary' === $s['category'] ) {
			return 'necessary';
		}
		$id = isset( $s['id'] ) ? (string) $s['id'] : '';
		if ( ( 'advanced' === $mode || ! empty( $s['mode_exempt'] ) ) && in_array( $id, CMP_Services::MEASUREMENT_IDS, true ) && ! CMP_Services::consent_exempt( $s ) ) {
			return 'advanced';
		}
		return CMP_Services::consent_exempt( $s ) ? 'exempt' : 'red';
	}

	/**
	 * Pure: the state of one service found on a page by the server scan, for the
	 * report label. $page_services: the page's services (raw, blocked, found);
	 * $svc: the active service with this id, or null; $mode: pro | advanced | light.
	 * Returns blocked | blocked_light (marked data-cmp-service by the blocker),
	 * necessary | exempt | advanced (never blocked, see service_level), or
	 * unblocked | unblocked_off (loads without consent; _off: script blocking is off).
	 */
	public static function service_state( $id, array $page_services, $svc, $mode, $blocking_off ) {
		$id      = (string) $id;
		$blocked = isset( $page_services['blocked'] ) ? (array) $page_services['blocked'] : array();
		if ( in_array( $id, $blocked, true ) ) {
			return 'light' === $mode ? 'blocked_light' : 'blocked';
		}
		$off = $blocking_off ? 'unblocked_off' : 'unblocked';
		$raw = isset( $page_services['raw'] ) ? (array) $page_services['raw'] : array();
		if ( in_array( $id, $raw, true ) || ! is_array( $svc ) ) {
			return $off;
		}
		$level = self::service_level( $svc, $mode );
		return 'red' === $level ? $off : $level;
	}

	/** Pure: the service whose host pattern matches the URL; the longest (most specific) pattern wins. */
	private static function host_service( $url, array $services ) {
		$best = null;
		$len  = -1;
		foreach ( $services as $s ) {
			if ( ! is_array( $s ) || empty( $s['id'] ) ) {
				continue;
			}
			$path = isset( $s['type'] ) && 'embed' === $s['type'] && isset( $s['path'] ) ? (string) $s['path'] : '';
			foreach ( (array) ( isset( $s['hosts'] ) ? $s['hosts'] : array() ) as $h ) {
				$p = (string) $h . $path;
				if ( strlen( $p ) > $len && CMP_Services::host_matches( $url, $p ) && ! CMP_Services::excepted( $url, $s ) ) {
					$best = $s;
					$len  = strlen( $p );
				}
			}
		}
		return $best;
	}

	/** Pure: the service that lists this cookie name (the custom one where it decides for the loader of a library service). */
	private static function cookie_service( $name, array $services ) {
		foreach ( $services as $s ) {
			if ( ! is_array( $s ) || empty( $s['id'] ) ) {
				continue;
			}
			foreach ( (array) ( isset( $s['cookies'] ) ? $s['cookies'] : array() ) as $ck ) {
				$pat = is_array( $ck ) && isset( $ck['name'] ) ? $ck['name'] : $ck;
				if ( is_string( $pat ) && self::cookie_matches( $name, $pat ) ) {
					$custom = CMP_Services::loader_service( $s, $services );
					return null !== $custom ? $custom : $s;
				}
			}
		}
		return null;
	}

	/**
	 * Pure: the verdict of one page before consent. $before_hosts: resource URLs
	 * (or bare hosts) contacted; $before_cookies: first-party cookie names.
	 * $mode: 'pro' | 'advanced' (GA4/GTM load with Consent Mode denied) | 'light'.
	 * Returns array( 'status' => red|yellow|green|none, 'items' => [ [level, label, host|cookie, source] ] ).
	 * level: red | yellow change the status; exempt | necessary | advanced |
	 * consentmode (Advanced: a GA/GTM cookie before consent) | preexisting (a
	 * cookie already in the admin's browser before the run, $baseline) never do.
	 * source: the service id, the resource type, or '' when unknown.
	 * One host can carry several services (path patterns): items are deduped
	 * per host and service / resource type / unknown, not per host.
	 */
	public static function classify( array $before_hosts, array $before_cookies, array $services, string $home, string $mode, array $baseline = array() ): array {
		$items = array();
		$seen  = array();
		$data  = false;
		foreach ( $before_hosts as $u ) {
			$u = trim( (string) $u );
			if ( '' === $u ) {
				continue;
			}
			if ( 1 === preg_match( '#^//#', $u ) ) {
				$u = 'https:' . $u;
			} elseif ( 1 !== preg_match( '#^[a-z][a-z0-9+.-]*:#i', $u ) ) {
				$u = 'https://' . $u; // a bare host
			}
			if ( 1 !== preg_match( '#^https?://#i', $u ) ) {
				continue; // data:, blob:, extensions
			}
			$host = parse_url( $u, PHP_URL_HOST );
			if ( ! is_string( $host ) || '' === $host ) {
				continue;
			}
			$data = true;
			$host = strtolower( $host );
			if ( self::is_own_host( $u, $home ) ) {
				continue;
			}
			$svc  = self::host_service( $u, $services );
			$type = null === $svc ? self::ip_type( $host ) : '';
			if ( null !== $svc ) {
				$item = array( self::service_level( $svc, $mode ), isset( $svc['name'] ) ? (string) $svc['name'] : (string) $svc['id'], $host, (string) $svc['id'] );
			} elseif ( '' !== $type ) {
				$item = array( 'yellow', self::IP_LABELS[ $type ], $host, $type );
			} else {
				$item = array( 'red', '', $host, '' );
			}
			$key = 'h|' . $host . '|' . ( '' !== $item[3] ? $item[3] : 'unknown' );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$items[]      = $item;
		}
		foreach ( $before_cookies as $c ) {
			$c = trim( (string) $c );
			if ( '' === $c ) {
				continue;
			}
			$data = true;
			if ( self::ignored_cookie( $c ) || isset( $seen[ 'c|' . $c ] ) ) {
				continue;
			}
			$seen[ 'c|' . $c ] = true;
			$svc   = self::cookie_service( $c, $services );
			$label = null !== $svc ? ( isset( $svc['name'] ) ? (string) $svc['name'] : (string) $svc['id'] ) : '';
			$id    = null !== $svc ? (string) $svc['id'] : '';
			if ( in_array( $c, $baseline, true ) ) {
				// Already in the admin's browser before the run (an earlier real accept, an earlier check): no verdict.
				$items[] = array( 'preexisting', $label, $c, $id );
				continue;
			}
			$level = null !== $svc ? self::service_level( $svc, $mode ) : 'yellow';
			if ( 'advanced' === $level ) {
				$level = 'consentmode'; // with Consent Mode denied this cookie should not exist
			}
			$items[] = array( $level, $label, $c, $id );
		}
		$status = $data ? 'green' : 'none';
		foreach ( $items as $it ) {
			if ( 'red' === $it[0] ) {
				$status = 'red';
				break;
			}
			if ( 'yellow' === $it[0] ) {
				$status = 'yellow';
			}
		}
		return array( 'status' => $status, 'items' => $items );
	}

	/** Pure: a list of strings from untrusted input (array, or a string with one entry per line), capped. */
	private static function str_list( $in, $max, $max_len ) {
		$list = is_array( $in ) ? $in : preg_split( '/[\r\n]+/', (string) $in );
		$out  = array();
		foreach ( (array) $list as $v ) {
			if ( ! is_string( $v ) ) {
				continue;
			}
			$v = trim( $v );
			if ( '' === $v || strlen( $v ) > $max_len || in_array( $v, $out, true ) ) {
				continue;
			}
			$out[] = $v;
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Pure: the manual additions and exclusions to save in cmp_scan_urls. Only
	 * http(s) addresses on the site's domain are kept ('/path' is resolved under
	 * the home URL, subdirectory included); the others are returned in 'rejected'. At most MAX_PAGES each.
	 */
	public static function clean_manual_urls( $add, $exclude, $home ) {
		$base  = '';
		$hpath = '';
		if ( 1 === preg_match( '~^(https?://[^/?#]+)(/[^?#]*)?~i', (string) $home, $m ) ) {
			$base  = $m[1];
			$hpath = isset( $m[2] ) ? rtrim( $m[2], '/' ) : ''; // subdirectory install: /blog
		}
		$res = array( 'add' => array(), 'exclude' => array(), 'rejected' => array() );
		foreach ( array( 'add' => $add, 'exclude' => $exclude ) as $key => $in ) {
			$keys = array();
			foreach ( self::str_list( $in, self::MAX_PAGES * 5, self::MANUAL_MAX_URL ) as $u ) {
				if ( '' !== $base && 1 === preg_match( '#^/(?!/)#', $u ) ) {
					// A path is relative to the home path, unless it already starts with it.
					$u = $base . ( '' !== $hpath && 0 !== strpos( $u . '/', $hpath . '/' ) ? $hpath : '' ) . $u;
				}
				if ( 1 !== preg_match( '#^https?://[^\s<>"\']+$#i', $u ) || ! self::is_own_host( $u, $home ) ) {
					if ( count( $res['rejected'] ) < self::MAX_PAGES ) {
						$res['rejected'][] = $u;
					}
					continue;
				}
				$k = self::url_key( $u );
				if ( isset( $keys[ $k ] ) || count( $res[ $key ] ) >= self::MAX_PAGES ) {
					continue;
				}
				$keys[ $k ]    = true;
				$res[ $key ][] = (string) preg_replace( '/#.*$/s', '', $u );
			}
		}
		return $res;
	}

	/**
	 * Pure: one page of the browser check, from the raw data check.js sends:
	 * url, result (ok | timeout | nobanner | origin), before { resources, cookies },
	 * added { resources, cookies } (after "Acceptă tot"), light, pre (cookie names
	 * already in the browser just before this page's frame: set by an earlier
	 * page's "Acceptă tot"; united with the run's $baseline). Returns null for
	 * a page off the site. status none (with reason) when it could not be checked.
	 */
	public static function check_page( array $raw, array $services, $home, $mode, array $baseline = array() ) {
		$url = isset( $raw['url'] ) && is_string( $raw['url'] ) ? trim( $raw['url'] ) : '';
		if ( '' === $url || strlen( $url ) > self::MANUAL_MAX_URL || 1 !== preg_match( '#^https?://#i', $url ) || ! self::is_own_host( $url, $home ) ) {
			return null;
		}
		$result = isset( $raw['result'] ) && in_array( $raw['result'], array( 'ok', 'timeout', 'nobanner', 'origin' ), true ) ? $raw['result'] : 'timeout';
		$light  = ! empty( $raw['light'] ) || 'light' === $mode;
		$out    = array( 'url' => $url, 'status' => 'none', 'reason' => $result, 'items' => array(), 'after' => null, 'light' => $light );
		if ( 'ok' !== $result ) {
			return $out;
		}
		$b    = isset( $raw['before'] ) && is_array( $raw['before'] ) ? $raw['before'] : array();
		$res  = self::str_list( isset( $b['resources'] ) ? $b['resources'] : array(), self::CHECK_MAX_RESOURCES, self::CHECK_MAX_URL );
		$cks  = self::str_list( isset( $b['cookies'] ) ? $b['cookies'] : array(), self::CHECK_MAX_COOKIES, 200 );
		$pre  = self::str_list( isset( $raw['pre'] ) && is_array( $raw['pre'] ) ? $raw['pre'] : array(), self::CHECK_MAX_COOKIES, 200 );
		$base = self::str_list( array_merge( self::str_list( $baseline, self::CHECK_MAX_COOKIES, 200 ), $pre ), self::CHECK_MAX_COOKIES * 2, 200 );
		$c    = self::classify( $res, $cks, $services, (string) $home, (string) $mode, $base );
		$out['status'] = $c['status'];
		$out['reason'] = 'none' === $c['status'] ? 'nodata' : '';
		$out['items']  = $c['items'];
		if ( ! $light && isset( $raw['added'] ) && is_array( $raw['added'] ) ) {
			$a = $raw['added'];
			$after = self::classify(
				self::str_list( isset( $a['resources'] ) ? $a['resources'] : array(), self::CHECK_MAX_RESOURCES, self::CHECK_MAX_URL ),
				self::str_list( isset( $a['cookies'] ) ? $a['cookies'] : array(), self::CHECK_MAX_COOKIES, 200 ),
				$services,
				(string) $home,
				(string) $mode
			);
			$out['after'] = $after['items'];
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * WordPress side of the isolated admin session (front end): the check mode
	 * (?cmp_check=<nonce>, B3) and the appearance preview (?cmp_preview=<nonce>,
	 * B6, CMP_Preview). Both: a nonce of the current user with manage_options,
	 * otherwise the parameter is ignored completely; no cache, the session check
	 * cookies (so the banner shows even after the admin decided, and nothing is
	 * logged), no auto-open exclusions, the admin bar hidden.
	 * ------------------------------------------------------------------ */

	/** @var bool[] Token modes of this request (param => active), computed once (after init). */
	private static $modes = array();

	/**
	 * Pure: the token of a token mode. $cookie null: the nonce is the value of
	 * ?$param (check mode). $cookie set: ?$param is only a marker and must be
	 * exactly '1'; the nonce is the cookie (preview: no nonce in any URL, so an
	 * old ?cmp_preview=<nonce> link or a leaked marker does nothing). Null when
	 * missing or not a string.
	 */
	public static function token_from( array $get, array $cookies, string $param, ?string $cookie ): ?string {
		if ( ! isset( $get[ $param ] ) || ! is_string( $get[ $param ] ) ) {
			return null;
		}
		if ( null === $cookie ) {
			return $get[ $param ];
		}
		if ( '1' !== $get[ $param ] || ! isset( $cookies[ $cookie ] ) || ! is_string( $cookies[ $cookie ] ) ) {
			return null;
		}
		return $cookies[ $cookie ];
	}

	/**
	 * Is this front-end request in the token mode $param (a valid $action nonce
	 * of a user with manage_options: in ?$param, or with $cookie in that cookie
	 * plus the marker ?$param=1)? Otherwise the parameter is ignored
	 * completely. Before `init` (no current user yet) it answers false without
	 * remembering it.
	 */
	public static function token_mode( string $param, string $action, ?string $cookie = null ): bool {
		if ( isset( self::$modes[ $param ] ) ) {
			return self::$modes[ $param ];
		}
		if ( ! function_exists( 'did_action' ) || ! did_action( 'init' ) ) {
			return false;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- this IS the nonce, verified below.
		$uri  = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$rest = ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() )
			|| self::is_rest_uri( $uri, $_GET, function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json' );
		$raw  = self::token_from( $_GET, $_COOKIE, $param, $cookie );
		$raw  = null === $raw ? null : sanitize_text_field( wp_unslash( $raw ) );
		// phpcs:enable
		$r = self::check_request(
			array(
				'init'   => true,
				'parsed' => (bool) did_action( 'parse_request' ),
				'admin'  => is_admin(),
				'ajax'   => wp_doing_ajax(),
				'rest'   => $rest,
			),
			$raw,
			function ( $token ) use ( $action ) {
				return false !== wp_verify_nonce( $token, $action ) && current_user_can( 'manage_options' );
			}
		);
		if ( $r['final'] ) {
			self::$modes[ $param ] = $r['active'];
		}
		return $r['active'];
	}

	/** Is this front-end request in check mode (?cmp_check, admin "Verificare")? */
	public static function check_mode(): bool {
		return self::token_mode( 'cmp_check', 'cmp_check' );
	}

	/**
	 * Is this front-end request an isolated admin session: the check mode or
	 * the appearance preview (CMP_Preview::active())?
	 */
	public static function isolated(): bool {
		return self::check_mode() || ( class_exists( 'CMP_Preview' ) && CMP_Preview::active() );
	}

	/** show_admin_bar: hidden in an isolated session (the page must look like a visitor's). */
	public static function check_admin_bar( $show ) {
		return self::isolated() ? false : $show;
	}

	/**
	 * template_redirect: an isolated page (check or preview) is never cached,
	 * and every such page starts as a new visitor: the check cookies of an
	 * earlier page (same session) are expired and ignored for this request.
	 */
	public static function check_page_setup() {
		if ( ! self::isolated() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		if ( ! headers_sent() ) {
			// The token stays on this site (the cmp_check URL is also cleaned in banner.js).
			header( 'Referrer-Policy: same-origin' );
			// Only the admin screen (same origin) may frame a check page.
			header( 'X-Frame-Options: SAMEORIGIN' );
		}
		foreach ( CMP_Consent::cookie_names( true ) as $name ) {
			unset( $_COOKIE[ $name ] );
			if ( ! headers_sent() ) {
				setcookie( $name, '', time() - YEAR_IN_SECONDS, '/' );
			}
		}
	}

	/* ------------------------------------------------------------------
	 * WordPress side: cron, queue, fetching. Never runs on a front-end view.
	 * ------------------------------------------------------------------ */

	public static function register() {
		add_action( self::RUN_HOOK, array( __CLASS__, 'run_batch' ) );
		add_action( self::DAILY_HOOK, array( __CLASS__, 'enqueue_all' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_first_scan' ) );
		// Isolated admin session: check mode and preview (front end only; inert without a valid nonce).
		add_filter( 'show_admin_bar', array( __CLASS__, 'check_admin_bar' ), 99 );
		add_action( 'template_redirect', array( __CLASS__, 'check_page_setup' ), 0 );
	}

	/** Schedule the daily refresh when missing (activation, upgrade). */
	public static function schedule_daily() {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY_HOOK );
		}
	}

	/** Remove both scan events (deactivation, uninstall). */
	public static function unschedule() {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::RUN_HOOK );
			wp_clear_scheduled_hook( self::DAILY_HOOK );
		}
	}

	private static function schedule_run() {
		if ( ! wp_next_scheduled( self::RUN_HOOK ) ) {
			wp_schedule_single_event( time(), self::RUN_HOOK );
		}
	}

	/**
	 * admin_init: queue the first scan when there is no result yet (e.g. right
	 * after the update), and resume a run that stopped (lost cron event).
	 */
	public static function maybe_first_scan() {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::schedule_daily(); // also covers installs already on this version before B3
		$state = self::state();
		if ( 'running' === $state['status'] ) {
			$queue = get_option( self::QUEUE_OPTION, array() );
			$act   = self::resume_action( $state, is_array( $queue ) ? $queue : array(), time() );
			if ( 'reenqueue' === $act ) {
				self::enqueue_all();
			} elseif ( 'reschedule' === $act ) {
				$state['progress'] = time();
				update_option( self::STATE_OPTION, $state, false );
				self::schedule_run();
			}
			return;
		}
		// A finished run means a result (or a recent failure) exists: no need to load the aggregate.
		if ( (int) $state['finished'] > 0 || get_transient( 'cmp_tracker_scan_fail' ) ) {
			return;
		}
		if ( false === get_option( self::AGGREGATE_OPTION, false ) ) {
			self::enqueue_all();
		}
	}

	/** @return array{status:string,started:int,finished:int,progress:int,total:int,urls:array,expand:bool} */
	public static function state() {
		$s = get_option( self::STATE_OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		$s += array( 'status' => 'idle', 'started' => 0, 'finished' => 0, 'total' => 0, 'urls' => array(), 'expand' => false );
		if ( ! isset( $s['progress'] ) ) {
			$s['progress'] = (int) $s['started'];
		}
		return $s;
	}

	/** A run with no progress for over an hour (cron lost) no longer counts as running. */
	public static function is_running() {
		$s = self::state();
		return 'running' === $s['status'] && ! self::is_stale( $s, time() );
	}

	/** array( done, total ) of the scan in progress, or null when idle or stale. */
	public static function current_progress() {
		$q = get_option( self::QUEUE_OPTION, array() );
		return self::progress_for( self::state(), is_array( $q ) ? $q : array(), time() );
	}

	public static function cron_disabled() {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}

	/**
	 * Plugin admin screens only (Settings, Services, Legal, Verificare): when
	 * cron is disabled or the run made no progress for 15 minutes, scan up to
	 * INLINE_PAGES pages now. Never called on the front end.
	 */
	public static function maybe_run_inline() {
		if ( ! is_admin() || wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$state = self::state();
		if ( 'running' !== $state['status'] ) {
			return;
		}
		if ( self::should_run_inline( self::cron_disabled(), self::last_progress( $state ), time() ) ) {
			self::batch( self::INLINE_PAGES, false );
		}
	}

	/** "Scanare în curs (X/Y)", with a note when only admin screen views move it on (cron disabled). */
	public static function progress_text( array $progress ) {
		return self::cron_disabled()
			/* translators: 1: pages scanned, 2: pages in the list */
			? sprintf( __( 'Scanare în curs (%1$d/%2$d) — se continuă la fiecare deschidere a acestui ecran', 'biscuitwp' ), $progress[0], $progress[1] )
			/* translators: 1: pages scanned, 2: pages in the list */
			: sprintf( __( 'Scanare în curs (%1$d/%2$d)', 'biscuitwp' ), $progress[0], $progress[1] );
	}

	/** Record a Settings/Services save: warnings from older results are hidden until a newer scan. */
	public static function mark_saved() {
		update_option( self::SAVED_OPTION, time(), false );
	}

	/** Are the shown results older than the last save? */
	public static function outdated_now( $agg ) {
		return self::outdated_after_save( (int) get_option( self::SAVED_OPTION, 0 ), $agg );
	}

	/** Manual additions and exclusions: array( 'add' => [], 'exclude' => [] ). */
	public static function manual_urls() {
		$m = get_option( self::URLS_OPTION, array() );
		$m = is_array( $m ) ? $m : array();
		return array(
			'add'     => isset( $m['add'] ) && is_array( $m['add'] ) ? $m['add'] : array(),
			'exclude' => isset( $m['exclude'] ) && is_array( $m['exclude'] ) ? $m['exclude'] : array(),
		);
	}

	/** Linked legal pages (privacy, terms, cookies), published only. */
	private static function legal_urls() {
		$s   = class_exists( 'CMP_Consent' ) ? CMP_Consent::get_settings() : array();
		$out = array();
		foreach ( array( 'privacy_page_id', 'terms_page_id', 'cookie_page_id' ) as $k ) {
			$id = is_array( $s ) && ! empty( $s[ $k ] ) ? (int) $s[ $k ] : 0;
			if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
				$u = get_permalink( $id );
				if ( is_string( $u ) && '' !== $u ) {
					$out[] = $u;
				}
			}
		}
		return $out;
	}

	/** The latest published entry of each public post type (attachments excluded). */
	private static function latest_urls() {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $type ) {
			if ( 'attachment' === $type ) {
				continue;
			}
			$ids = get_posts(
				array(
					'post_type'        => $type,
					'post_status'      => 'publish',
					'numberposts'      => 1,
					'orderby'          => 'date',
					'order'            => 'DESC',
					'fields'           => 'ids',
					'has_password'     => false,
					'suppress_filters' => false,
				)
			);
			if ( ! empty( $ids ) ) {
				$u = get_permalink( (int) $ids[0] );
				if ( is_string( $u ) && '' !== $u ) {
					$out[] = $u;
				}
			}
		}
		return $out;
	}

	/** GET one own-domain URL: no cookies, generic user agent. */
	private static function fetch( $url ) {
		return wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'cookies'     => array(),
				'user-agent'  => 'Mozilla/5.0 (compatible; ConsentScanner/1.0)',
			)
		);
	}

	/** Page URLs from the native wp-sitemap.xml (index plus the first sub-sitemaps). Cron only. */
	private static function sitemap_urls() {
		$home = home_url( '/' );
		$res  = self::fetch( home_url( '/wp-sitemap.xml' ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return array();
		}
		$map  = self::parse_sitemap( wp_remote_retrieve_body( $res ) );
		$urls = $map['urls'];
		$subs = 0;
		foreach ( $map['index'] as $sub ) {
			if ( $subs >= self::SITEMAP_MAX_SUBS || count( $urls ) >= self::MAX_PAGES ) {
				break;
			}
			if ( ! self::is_own_host( $sub, $home ) ) {
				continue;
			}
			$subs++;
			$r = self::fetch( $sub );
			if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
				continue;
			}
			$urls = array_merge( $urls, self::parse_sitemap( wp_remote_retrieve_body( $r ) )['urls'] );
		}
		return $urls;
	}

	/** The page list; the sitemap is read only when $with_sitemap (cron). */
	private static function build_list( $with_sitemap ) {
		return self::page_list( home_url( '/' ), self::legal_urls(), self::latest_urls(), $with_sitemap ? self::sitemap_urls() : array(), self::manual_urls() );
	}

	/** The pages of the last (or current) scan run; before any run, the list without the sitemap. */
	public static function current_list() {
		$s = self::state();
		return ! empty( $s['urls'] ) && is_array( $s['urls'] ) ? array_values( $s['urls'] ) : self::build_list( false );
	}

	/**
	 * Queue the whole page list for a fresh scan (Rescanează, saves, first
	 * admin visit, daily event). No HTTP request here: the sitemap is read by
	 * the first cron run, which extends the queue.
	 */
	public static function enqueue_all() {
		$list = self::build_list( false );
		$now  = time();
		update_option( self::QUEUE_OPTION, $list, false );
		update_option(
			self::STATE_OPTION,
			array( 'status' => 'running', 'started' => $now, 'progress' => $now, 'finished' => 0, 'total' => count( $list ), 'urls' => $list, 'expand' => true ),
			false
		);
		delete_transient( 'cmp_tracker_scan_fail' );
		self::schedule_run();
	}

	/** Cron: scan at most PER_RUN queued pages (the hook passes no usable argument). */
	public static function run_batch() {
		self::batch( self::PER_RUN, true );
	}

	/** Take the lock (an option added atomically); a lock older than LOCK_TTL is taken over. */
	private static function lock() {
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return true;
		}
		if ( self::lock_expired( (int) get_option( self::LOCK_OPTION, 0 ), time() ) ) {
			update_option( self::LOCK_OPTION, time(), false );
			return true;
		}
		return false;
	}

	/**
	 * Scan at most $max queued pages; finish the scan when the queue is empty.
	 * $in_cron: the sitemap is read (and the queue extended) only in cron.
	 */
	private static function batch( $max, $in_cron ) {
		$state = self::state();
		if ( 'running' !== $state['status'] || ! self::lock() ) {
			return;
		}
		$queue = get_option( self::QUEUE_OPTION, array() );
		$queue = is_array( $queue ) ? $queue : array();
		if ( $in_cron && ! empty( $state['expand'] ) ) {
			// Pages already scanned in this run stay done; the sitemap only adds.
			$done            = self::queue_remove( $state['urls'], $queue );
			$list            = self::build_list( true );
			$queue           = self::queue_remove( $list, $done );
			$state['urls']   = self::queue_add( $done, $list );
			$state['total']  = count( $state['urls'] );
			$state['expand'] = false;
			update_option( self::QUEUE_OPTION, $queue, false );
			update_option( self::STATE_OPTION, $state, false );
		}
		$run   = (int) $state['started'];
		$split = self::queue_take( $queue, $max );
		$home  = home_url( '/' );
		$svcs  = CMP_Services::active();
		$known = get_option( self::PAGES_OPTION, array() );
		$known = is_array( $known ) ? $known : array();
		$got   = array();
		foreach ( $split['take'] as $url ) {
			if ( self::in_backoff( isset( $known[ $url ] ) ? $known[ $url ] : null, time() ) ) {
				continue; // failed less than 5 minutes ago: keep that result, no request
			}
			$res  = self::fetch( $url );
			$code = is_wp_error( $res ) ? 'error' : (string) (int) wp_remote_retrieve_response_code( $res );
			$ok   = '200' === $code;
			$got[ $url ] = self::page_result( $url, $ok ? 'ok' : $code, $ok ? wp_remote_retrieve_body( $res ) : '', $svcs, $home, time() );
		}
		// Merge into a fresh read (pages are written only under the lock).
		$pages = get_option( self::PAGES_OPTION, array() );
		$pages = is_array( $pages ) ? $pages : array();
		foreach ( $split['take'] as $url ) {
			$prev = isset( $pages[ $url ] ) ? $pages[ $url ] : null;
			if ( isset( $got[ $url ] ) ) {
				$pages[ $url ] = self::merge_page_result( $prev, $got[ $url ] );
			}
		}
		update_option( self::PAGES_OPTION, $pages, false );
		// Re-read: an enqueue_all() during the fetches restarted the run; then
		// these pages (fetched before the restart) stay queued for the new run.
		$now   = self::state();
		$fresh = get_option( self::QUEUE_OPTION, array() );
		$fresh = is_array( $fresh ) ? $fresh : array();
		if ( (int) $now['started'] === $run ) {
			$fresh = self::queue_remove( $fresh, $split['take'] );
			update_option( self::QUEUE_OPTION, $fresh, false );
			$now['progress'] = time();
			update_option( self::STATE_OPTION, $now, false );
		}
		delete_option( self::LOCK_OPTION );
		if ( empty( $fresh ) ) {
			self::finish();
		} else {
			self::schedule_run();
		}
	}

	/** Aggregate the pages of this run and save the result. */
	private static function finish() {
		$state = self::state();
		$all   = get_option( self::PAGES_OPTION, array() );
		$all   = is_array( $all ) ? $all : array();
		$pages = array();
		foreach ( $state['urls'] as $u ) {
			if ( isset( $all[ $u ] ) ) {
				$pages[ $u ] = $all[ $u ];
			}
		}
		update_option( self::PAGES_OPTION, $pages, false ); // pages no longer listed are dropped
		$agg  = self::aggregate( array_values( $pages ) );
		$fire = null;
		if ( $agg['pages'] > 0 ) {
			$agg['run']     = (int) $state['started'];
			$agg['sitemap'] = empty( $state['expand'] ); // false: an inline run, the sitemap was not read
			CMP_Blocker::store_scan( $agg );
			$fire = $agg;
		} else {
			set_transient( 'cmp_tracker_scan_fail', 1, self::FAIL_BACKOFF ); // nothing could be read: the last result stays
			delete_option( self::SAVED_OPTION ); // and is shown again rather than "rescanning" forever
		}
		$state['status']   = 'idle';
		$state['finished'] = time();
		update_option( self::STATE_OPTION, $state, false );
		if ( null !== $fire ) {
			// Only a scan that read at least one page: listeners (the new-domain email) never see an empty result.
			do_action( 'cmp_scan_finished', $fire );
		}
	}

	/** Google Fonts links from every scanned page. */
	public static function scanned_font_links() {
		$pages = get_option( self::PAGES_OPTION, array() );
		return self::font_links( is_array( $pages ) ? array_values( $pages ) : array() );
	}
}
