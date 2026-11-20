<?php
if ( ! defined( 'CMP_CHECK' ) ) {
	http_response_code( 404 );
	exit;
}
/**
 * Consent Manager Pro, "Verifică-ți site-ul" (consent.rt.md): the page analysis.
 *
 * Pure functions: the homepage HTML and its response headers go in, findings
 * come out. No network, no output, no files except cmp_check_load_services().
 * PHP 7.4+, no extensions beyond PCRE (mbstring is used when present).
 *
 * The HTML is read by a small linear tokenizer (no DOM): comments, <template>
 * and <noscript> are skipped as inert, raw-text elements (<script>, <style>,
 * <title>, <textarea>, <iframe>...) keep their content as text, so markup
 * inside a title or a textarea is never mistaken for a tag.
 *
 * Text fields of the findings are plain text built from our own strings, the
 * service library and sanitised cookie names / hosts; nothing from the page is
 * echoed, and every field goes through cmp_check_plain() at the end.
 *
 * The third-party resource rules (IP_HOSTS) are copied from the plugin's
 * includes/modules/class-scanner.php; the service matching follows
 * includes/class-services.php (host_matches, script_service). The API lives
 * apart from the plugin, so nothing is required from it.
 */

require_once __DIR__ . '/signatures.php';

if ( ! defined( 'CMP_CHECK_MAX_BYTES' ) ) {
	define( 'CMP_CHECK_MAX_BYTES', 2097152 );
	define( 'CMP_CHECK_MAX_TAGS', 200000 );
	define( 'CMP_CHECK_MAX_ITEMS', 3000 );
	define( 'CMP_CHECK_MAX_ATTR_BYTES', 16384 );
	define( 'CMP_CHECK_MAX_TAG_WINDOW', 65536 );
	define( 'CMP_CHECK_MAX_URLS', 20000 );
	// Severity of GA loaded under Consent Mode with a denied default: switch to 'issue' here.
	define( 'CMP_CHECK_CONSENT_MODE_SEVERITY', 'unsure' );
	define( 'CMP_CHECK_MAX_SECONDS', 3.0 );
}

/* ------------------------------------------------------------------
 * Service library.
 * ------------------------------------------------------------------ */

/**
 * The services of a library file (data/services.json): a list of service
 * arrays with id, name, type, category, hosts, inline, gtm, cookies...
 * Never throws: a missing or invalid file gives an empty list.
 */
function cmp_check_load_services( string $json_path ): array {
	if ( '' === $json_path || ! is_file( $json_path ) || ! is_readable( $json_path ) ) {
		return array();
	}
	$raw = @file_get_contents( $json_path, false, null, 0, 8 * 1024 * 1024 ); // phpcs:ignore
	if ( ! is_string( $raw ) || '' === $raw ) {
		return array();
	}
	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		return array();
	}
	return cmp_check_norm_services( isset( $data['services'] ) && is_array( $data['services'] ) ? $data['services'] : $data );
}

/** Services with the fields the analysis reads, entries without an id dropped. */
function cmp_check_norm_services( array $list ): array {
	$out = array();
	foreach ( $list as $s ) {
		if ( ! is_array( $s ) || ! isset( $s['id'] ) || ! is_string( $s['id'] ) || '' === $s['id'] ) {
			continue;
		}
		$cookies = array();
		foreach ( ( isset( $s['cookies'] ) && is_array( $s['cookies'] ) ) ? $s['cookies'] : array() as $c ) {
			$n = is_array( $c ) && isset( $c['name'] ) ? $c['name'] : $c;
			if ( is_string( $n ) && '' !== $n ) {
				$cookies[] = $n;
			}
		}
		$strings = function ( $k ) use ( $s ) {
			return isset( $s[ $k ] ) && is_array( $s[ $k ] ) ? array_values( array_filter( $s[ $k ], 'is_string' ) ) : array();
		};
		$out[] = array(
			'id'             => $s['id'],
			'name'           => isset( $s['name'] ) && is_string( $s['name'] ) && '' !== $s['name'] ? $s['name'] : $s['id'],
			'type'           => isset( $s['type'] ) && is_string( $s['type'] ) ? $s['type'] : 'script',
			'category'       => isset( $s['category'] ) && is_string( $s['category'] ) ? $s['category'] : '',
			'hosts'          => $strings( 'hosts' ),
			'inline'         => $strings( 'inline' ),
			'path'           => isset( $s['path'] ) && is_string( $s['path'] ) ? $s['path'] : '',
			'gtm'            => ! empty( $s['gtm'] ),
			'exempt_default' => ! empty( $s['exempt_default'] ),
			'cookies'        => $cookies,
			'src_re'         => $strings( 'src_re' ),
			'inline_re'      => $strings( 'inline_re' ),
		);
	}
	return $out;
}

/**
 * The library plus the tool's own additions (cmp_check_extra_services): new
 * services (named in $lang) and extra hosts / markers / cookies for existing
 * ones. An addition never replaces a library service with the same id.
 */
function cmp_check_merge_extra( array $services, string $lang ): array {
	$ids = array();
	foreach ( $services as $i => $s ) {
		$ids[ $s['id'] ] = $i;
	}
	$new = array();
	foreach ( cmp_check_extra_services() as $x ) {
		if ( isset( $x['extend'] ) ) {
			if ( ! isset( $ids[ $x['extend'] ] ) ) {
				continue;
			}
			$i = $ids[ $x['extend'] ];
			foreach ( array( 'hosts', 'inline', 'cookies', 'src_re', 'inline_re' ) as $k ) {
				if ( isset( $x[ $k ] ) ) {
					$services[ $i ][ $k ] = array_merge( $services[ $i ][ $k ], $x[ $k ] );
				}
			}
			continue;
		}
		if ( isset( $ids[ $x['id'] ] ) ) {
			continue;
		}
		$x['name'] = isset( $x['name'][ $lang ] ) ? $x['name'][ $lang ] : $x['id'];
		$new[]     = $x;
	}
	// Additions first: their src_re (Google Ads ids on gtag/js) must win over the library's hosts.
	return array_merge( cmp_check_norm_services( $new ), $services );
}

/* ------------------------------------------------------------------
 * Small helpers.
 * ------------------------------------------------------------------ */

/** Plain, single-line text: no tags, no angle brackets, no control characters, at most $max characters. */
function cmp_check_plain( $s, int $max = 400 ): string {
	$s = (string) $s;
	if ( 1 !== preg_match( '//u', $s ) ) {
		$s = (string) preg_replace( '/[^\x20-\x7E]/', '', $s );
	}
	$s = strip_tags( $s );
	$s = str_replace( array( '<', '>' ), '', $s );
	$s = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $s );
	$s = trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
	if ( function_exists( 'mb_substr' ) ) {
		return mb_strlen( $s, 'UTF-8' ) > $max ? rtrim( mb_substr( $s, 0, $max - 3, 'UTF-8' ) ) . '...' : $s;
	}
	return strlen( $s ) > $max ? rtrim( substr( $s, 0, $max - 3 ) ) . '...' : $s;
}

/** Lowercase (UTF-8 aware when mbstring is there). */
function cmp_check_lower( string $s ): string {
	return function_exists( 'mb_strtolower' ) && 1 === preg_match( '//u', $s ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
}

/** Decoded attribute value. */
function cmp_check_decode( string $v ): string {
	return html_entity_decode( $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/** Absolute http(s) URL for a src/href value, or '' (relative, data:, javascript:...). */
function cmp_check_abs( string $url ): string {
	$url = trim( $url );
	if ( 1 === preg_match( '#^//#', $url ) ) {
		$url = 'https:' . $url;
	}
	return 1 === preg_match( '#^https?://#i', $url ) ? $url : '';
}

/** Host of a URL, lowercased, without a leading www. ('' when none). Copied from CMP_Scanner::bare_host. */
function cmp_check_bare_host( string $url ): string {
	$h = @parse_url( $url, PHP_URL_HOST ); // phpcs:ignore
	if ( ! is_string( $h ) || '' === $h ) {
		return '';
	}
	$h = strtolower( $h );
	return 0 === strpos( $h, 'www.' ) ? substr( $h, 4 ) : $h;
}

/** Is the URL on the site's domain (with or without www., plus subdomains)? Copied from CMP_Scanner::is_own_host. */
function cmp_check_is_own_host( string $url, string $home ): bool {
	if ( 1 !== preg_match( '#^(?:https?:)?//#i', $url ) ) {
		return false;
	}
	$own = cmp_check_bare_host( $home );
	$h   = cmp_check_bare_host( 0 === strpos( $url, '//' ) ? 'https:' . $url : $url );
	if ( '' === $own || '' === $h ) {
		return false;
	}
	return $h === $own || substr( $h, -( strlen( $own ) + 1 ) ) === '.' . $own;
}

/** Does a URL match a host pattern ('host' or 'host/path')? Copied from CMP_Services::host_matches. */
function cmp_check_host_matches( string $url, string $pattern ): bool {
	$pattern = strtolower( $pattern );
	$slash   = strpos( $pattern, '/' );
	$host    = false === $slash ? $pattern : substr( $pattern, 0, $slash );
	$path    = false === $slash ? '' : substr( $pattern, $slash );
	$h       = @parse_url( $url, PHP_URL_HOST ); // phpcs:ignore
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
	return 0 === strpos( (string) @parse_url( $url, PHP_URL_PATH ), $path ); // phpcs:ignore
}

/** Does a cookie name match a library pattern ('_ga', '_ga_*')? Copied from CMP_Scanner::cookie_matches. */
function cmp_check_cookie_matches( string $name, string $pattern ): bool {
	if ( '' === $pattern ) {
		return false;
	}
	if ( false === strpos( $pattern, '*' ) ) {
		return $name === $pattern;
	}
	return 1 === preg_match( '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/', $name );
}

/* ------------------------------------------------------------------
 * Tokenizer.
 * ------------------------------------------------------------------ */

/** Attributes that say what a tag loads: read even past the per-tag cap. */
function cmp_check_key_attrs(): array {
	return array_merge( cmp_check_url_attrs(), array( 'type', 'rel', 'as', 'data-blockingmode' ) );
}

/** Attributes that hold the URL a tag loads. */
function cmp_check_url_attrs(): array {
	return array_merge( cmp_check_script_src_attrs(), array( 'href', 'srcset', 'data' ) );
}

/**
 * Attributes of an open tag as name => decoded value (first occurrence wins,
 * names lowercased). A hostile tag can hold 2 MB of attributes, so only the
 * first CMP_CHECK_MAX_ATTR_BYTES are parsed in full; past that, the key
 * attributes (cmp_check_key_attrs) are still looked up one by one within
 * CMP_CHECK_MAX_TAG_WINDOW bytes, so a tracker URL after a long data-*
 * attribute is still seen. $lost is true when the tag was cut and no URL
 * attribute was found: what it loads is unknown.
 */
function cmp_check_attrs( string $raw, bool &$lost = false ): array {
	$lost = false;
	if ( strlen( $raw ) <= CMP_CHECK_MAX_ATTR_BYTES ) {
		return cmp_check_attrs_parse( $raw );
	}
	$out = cmp_check_attrs_parse( substr( $raw, 0, CMP_CHECK_MAX_ATTR_BYTES ) );
	$win = substr( $raw, 0, CMP_CHECK_MAX_TAG_WINDOW );
	foreach ( cmp_check_key_attrs() as $k ) {
		if ( 1 === preg_match( '/(?:^|[\s"\'])' . preg_quote( $k, '/' ) . '\s*+=\s*+(?:"([^"]*+)"|\'([^\']*+)\'|([^\s>]++))/i', $win, $m ) ) {
			$v = '';
			for ( $g = 3; $g >= 1; $g-- ) {
				if ( isset( $m[ $g ] ) && '' !== $m[ $g ] ) {
					$v = $m[ $g ];
					break;
				}
			}
			// An unclosed quoted value (a huge data: URI) comes back through the bare alternative.
			$out[ $k ] = cmp_check_decode( ltrim( $v, '"\'' ) );
		}
	}
	$lost = true;
	foreach ( cmp_check_url_attrs() as $k ) {
		if ( isset( $out[ $k ] ) && '' !== trim( $out[ $k ] ) ) {
			$lost = false;
			break;
		}
	}
	return $out;
}

/** Full attribute parse of a (capped) raw attribute string. */
function cmp_check_attrs_parse( string $raw ): array {
	$out = array();
	if ( '' === trim( $raw ) || ! preg_match_all( '/([^\s"\'>\/=]++)(?:\s*+=\s*+(?:"([^"]*+)"|\'([^\']*+)\'|([^\s>]++)))?/', $raw, $mm, PREG_SET_ORDER ) ) {
		return $out;
	}
	foreach ( $mm as $a ) {
		$k = strtolower( $a[1] );
		if ( isset( $out[ $k ] ) ) {
			continue;
		}
		$v = '';
		if ( isset( $a[4] ) && '' !== $a[4] ) {
			$v = $a[4];
		} elseif ( isset( $a[3] ) && '' !== $a[3] ) {
			$v = $a[3];
		} elseif ( isset( $a[2] ) ) {
			$v = $a[2];
		}
		$out[ $k ] = cmp_check_decode( $v );
		if ( count( $out ) >= 200 ) {
			break;
		}
	}
	return $out;
}

/** Offset of the closing tag '</name' (followed by a non-name character) at or after $from, or false. */
function cmp_check_find_close( string $html, string $name, int $from ) {
	$needle = '</' . $name;
	$n      = strlen( $needle );
	while ( false !== ( $p = stripos( $html, $needle, $from ) ) ) {
		$next = substr( $html, $p + $n, 1 );
		if ( '' === $next || 1 !== preg_match( '/[a-z0-9:-]/i', $next ) ) {
			return $p;
		}
		$from = $p + $n;
	}
	return false;
}

/** Offset just after the <template> that opened before $from (nested templates counted). */
function cmp_check_skip_template( string $html, int $from ): int {
	$len   = strlen( $html );
	$depth = 1;
	$p     = $from;
	while ( $depth > 0 ) {
		if ( 1 !== preg_match( '/<(\/?)template(?=[\s>\/])/i', $html, $m, PREG_OFFSET_CAPTURE, $p ) ) {
			return $len;
		}
		$depth += '' === $m[1][0] ? 1 : -1;
		$p      = $m[0][1] + strlen( $m[0][0] );
	}
	$gt = strpos( $html, '>', $p );
	return false === $gt ? $len : $gt + 1;
}

/**
 * The parts of a page the analysis needs, kept small so a 2 MB page of dense
 * markup stays within a modest memory limit:
 * - scripts: array( 'attrs' => array, 'body' => string ) for every script with a
 *   URL or a body (at most CMP_CHECK_MAX_ITEMS, then 'partial');
 * - iframes: attributes of each <iframe> (same cap);
 * - imgs: distinct absolute <img> URLs as keys (at most CMP_CHECK_MAX_ITEMS, then 'partial');
 * - links: attributes of each <link> (first CMP_CHECK_MAX_ITEMS);
 * - anchors: array( 'href', 'rel', 'label' ) per <a> (first CMP_CHECK_MAX_ITEMS);
 * - res: the URL-bearing attribute values (src, href, srcset, data-src, style)
 *   of the elements that fetch them (not <a>, <area>, <base>, <iframe>, nor a
 *   <link> that fetches nothing), one per line;
 * - styles: contents of <style> elements;
 * - partial: true when a budget (tags, time, items) ran out before the end.
 * Comments, <template> and <noscript> are inert and skipped; raw-text elements
 * (<title>, <textarea>...) are text.
 */
function cmp_check_parse( string $html ): array {
	$out   = array(
		'scripts' => array(),
		'iframes' => array(),
		'imgs'    => array(),
		'links'   => array(),
		'anchors' => array(),
		'res'     => '',
		'styles'  => array(),
		'partial' => false,
	);
	$raw   = array( 'script', 'style', 'title', 'textarea', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript' );
	$len   = strlen( $html );
	$pos   = 0;
	$count = 0;
	$t0    = microtime( true );
	$open  = '/\G<([a-zA-Z][a-zA-Z0-9:-]*+)((?:[^>"\'=]++|=\s*+"[^"]*+"|=\s*+\'[^\']*+\'|=|["\'])*+)>/';
	while ( $pos < $len ) {
		$lt = strpos( $html, '<', $pos );
		if ( false === $lt ) {
			break;
		}
		$count++;
		if ( $count > CMP_CHECK_MAX_TAGS || ( 0 === $count % 256 && microtime( true ) - $t0 > CMP_CHECK_MAX_SECONDS ) ) {
			$out['partial'] = true;
			break;
		}
		$next = substr( $html, $lt + 1, 1 );
		if ( '!' === $next && '<!--' === substr( $html, $lt, 4 ) ) {
			$end = strpos( $html, '-->', $lt + 4 );
			$pos = false === $end ? $len : $end + 3;
			continue;
		}
		if ( '!' === $next || '?' === $next ) {
			// Doctype or bogus comment: up to the next '>'.
			$end = strpos( $html, '>', $lt + 1 );
			$pos = false === $end ? $len : $end + 1;
			continue;
		}
		if ( '' === $next || 1 !== preg_match( '/^[a-zA-Z]$/', $next ) ) {
			$pos = $lt + 1;
			continue;
		}
		if ( false === strpos( $html, '>', $lt ) ) {
			break; // No tag can close any more.
		}
		if ( 1 !== preg_match( $open, $html, $m, 0, $lt ) ) {
			$pos = $lt + 1;
			continue;
		}
		$name  = strtolower( $m[1] );
		$after = $lt + strlen( $m[0] );
		if ( 'template' === $name ) {
			$pos = cmp_check_skip_template( $html, $after );
			continue;
		}
		if ( 'plaintext' === $name ) {
			break;
		}
		$lost  = false;
		$attrs = '' === trim( $m[2] ) ? array() : cmp_check_attrs( $m[2], $lost );
		if ( $lost && in_array( $name, array( 'script', 'iframe', 'link', 'img', 'source', 'embed', 'object' ), true ) ) {
			$out['partial'] = true; // A loading tag cut before its URL: what it loads is unknown.
		}
		if ( in_array( $name, $raw, true ) ) {
			$close = cmp_check_find_close( $html, $name, $after );
			if ( false === $close ) {
				$pos = $len;
			} else {
				$gt  = strpos( $html, '>', $close );
				$pos = false === $gt ? $len : $gt + 1;
			}
			if ( 'script' === $name ) {
				$body = false === $close ? (string) substr( $html, $after ) : (string) substr( $html, $after, $close - $after );
				if ( '' === trim( $body ) && '' === cmp_check_script_src( $attrs ) && ! isset( $attrs['src'] ) ) {
					continue; // Nothing to load, nothing to run.
				}
				if ( count( $out['scripts'] ) >= CMP_CHECK_MAX_ITEMS ) {
					$out['partial'] = true;
					break;
				}
				$out['scripts'][] = array(
					'attrs' => $attrs,
					'body'  => $body,
				);
			} elseif ( 'style' === $name ) {
				$out['styles'][] = false === $close ? (string) substr( $html, $after ) : (string) substr( $html, $after, $close - $after );
			} elseif ( 'iframe' === $name ) {
				if ( count( $out['iframes'] ) >= CMP_CHECK_MAX_ITEMS ) {
					$out['partial'] = true;
					break;
				}
				$out['iframes'][] = $attrs;
			}
			continue;
		}
		$pos = $after;
		if ( 'a' === $name || 'area' === $name ) {
			if ( 'a' === $name && count( $out['anchors'] ) < CMP_CHECK_MAX_ITEMS ) {
				$chunk = (string) substr( $html, $after, 2000 );
				$end   = stripos( $chunk, '</a' );
				if ( false !== $end ) {
					$chunk = substr( $chunk, 0, $end );
				}
				$label = trim( cmp_check_decode( strip_tags( $chunk ) ) );
				foreach ( array( 'aria-label', 'title' ) as $k ) {
					if ( isset( $attrs[ $k ] ) ) {
						$label .= ' ' . $attrs[ $k ];
					}
				}
				$out['anchors'][] = array(
					'href'  => isset( $attrs['href'] ) ? $attrs['href'] : '',
					'rel'   => isset( $attrs['rel'] ) ? $attrs['rel'] : '',
					'label' => $label,
				);
			}
			continue;
		}
		if ( array() === $attrs || 'base' === $name ) {
			continue;
		}
		if ( 'link' === $name ) {
			if ( count( $out['links'] ) < CMP_CHECK_MAX_ITEMS ) {
				$out['links'][] = $attrs;
			}
			if ( ! cmp_check_link_fetches( $attrs ) ) {
				continue;
			}
		}
		if ( 'img' === $name && isset( $attrs['src'] ) ) {
			$src = cmp_check_abs( $attrs['src'] );
			if ( '' !== $src && ! isset( $out['imgs'][ $src ] ) ) {
				if ( count( $out['imgs'] ) >= CMP_CHECK_MAX_ITEMS ) {
					$out['partial'] = true; // Pixels past the cap would go unchecked.
				} else {
					$out['imgs'][ $src ] = true;
				}
			}
		}
		foreach ( array( 'src', 'href', 'srcset', 'data-src', 'style' ) as $k ) {
			if ( isset( $attrs[ $k ] ) && false !== strpos( $attrs[ $k ], '//' ) ) {
				$out['res'] .= $attrs[ $k ] . "\n";
			}
		}
	}
	return $out;
}

/* ------------------------------------------------------------------
 * Scripts: live or held, what they load.
 * ------------------------------------------------------------------ */

/**
 * What a script's type makes of it, the CMP markers aside:
 * - 'live': JavaScript (or no type), or a delay loader (cmp_check_live_types,
 *   Cloudflare Rocket Loader's "<hash>-text/javascript");
 * - 'inert': data or a template the browser never runs (cmp_check_inert_types);
 * - 'unknown': anything else. It may be a loader we do not know, so a tracker
 *   in it is reported as "unsure", never ignored.
 */
function cmp_check_script_kind( array $attrs ): string {
	$js   = array( '', 'module', 'text/javascript', 'application/javascript', 'application/ecmascript', 'application/x-ecmascript', 'application/x-javascript', 'text/ecmascript', 'text/x-ecmascript', 'text/x-javascript', 'text/jscript', 'text/livescript', 'text/javascript1.0', 'text/javascript1.1', 'text/javascript1.2', 'text/javascript1.3', 'text/javascript1.4', 'text/javascript1.5' );
	$type = isset( $attrs['type'] ) ? strtolower( trim( explode( ';', $attrs['type'] )[0] ) ) : '';
	if ( in_array( $type, $js, true ) || in_array( $type, cmp_check_live_types(), true ) || 1 === preg_match( '#^[a-z0-9]+-text/javascript$#', $type ) ) {
		return 'live';
	}
	return in_array( $type, cmp_check_inert_types(), true ) ? 'inert' : 'unknown';
}

/** Does a CMP marker hold this element until consent? */
function cmp_check_held_by_cmp( array $attrs ): bool {
	$markers = cmp_check_defer_markers();
	$run     = cmp_check_run_values();
	foreach ( $markers['attrs'] as $a ) {
		if ( isset( $attrs[ $a ] ) && ! in_array( strtolower( trim( $attrs[ $a ] ) ), $run, true ) ) {
			return true;
		}
	}
	$classes = isset( $attrs['class'] ) ? preg_split( '/\s+/', strtolower( trim( $attrs['class'] ) ) ) : array();
	foreach ( (array) $classes as $c ) {
		if ( '' === $c || in_array( $c, $run, true ) ) {
			continue;
		}
		foreach ( $markers['classes'] as $m ) {
			if ( $c === $m || ( '*' === substr( $m, -1 ) && 0 === strpos( $c, substr( $m, 0, -1 ) ) ) ) {
				return true;
			}
		}
	}
	return false;
}

/** The script URL a script loads ('' for an inline script); delay and lazy loaders keep it in data-*. */
function cmp_check_script_src( array $attrs ): string {
	foreach ( cmp_check_script_src_attrs() as $k ) {
		if ( isset( $attrs[ $k ] ) && '' !== trim( $attrs[ $k ] ) ) {
			return trim( $attrs[ $k ] );
		}
	}
	return '';
}

/** The loader signature a script URL or inline body belongs to, or null. */
function cmp_check_loader_for( string $src, string $body ) {
	$hay = '' !== $src ? strtolower( $src ) : strtolower( $body );
	$key = '' !== $src ? 'src' : 'body';
	foreach ( cmp_check_loader_signatures() as $l ) {
		foreach ( $l[ $key ] as $needle ) {
			if ( '' !== $needle && false !== strpos( $hay, $needle ) ) {
				return $l;
			}
		}
	}
	return null;
}

/** The service whose host pattern matches the URL, longest pattern first; $types limits service types. */
function cmp_check_service_for_url( string $url, array $services, array $types ) {
	foreach ( $services as $s ) {
		if ( in_array( $s['type'], $types, true ) ) {
			foreach ( $s['src_re'] as $re ) {
				if ( 1 === @preg_match( $re, $url ) ) { // phpcs:ignore
					return $s;
				}
			}
		}
	}
	$best = null;
	$len  = -1;
	foreach ( $services as $s ) {
		if ( ! in_array( $s['type'], $types, true ) ) {
			continue;
		}
		$path = 'embed' === $s['type'] ? $s['path'] : '';
		foreach ( $s['hosts'] as $h ) {
			$p = $h . $path;
			if ( strlen( $p ) > $len && cmp_check_host_matches( $url, $p ) ) {
				$best = $s;
				$len  = strlen( $p );
			}
		}
	}
	return $best;
}

/** JavaScript with the spaces around punctuation removed, so "fbq( 'init'" reads as "fbq('init'". */
function cmp_check_norm_js( string $s ): string {
	return (string) preg_replace( '/\s*+([(),;=:\[\]{}])\s*+/', '$1', $s );
}

/** Does a lowercase body name this host as part of a URL (start, or after / . quote, space or =)? */
function cmp_check_body_has_host( string $hay, string $host ): bool {
	$from = 0;
	for ( $i = 0; $i < 50; $i++ ) {
		$p = strpos( $hay, $host, $from );
		if ( false === $p ) {
			return false;
		}
		if ( 0 === $p || false !== strpos( "/.\"' =\t\n\r", $hay[ $p - 1 ] ) ) {
			return true;
		}
		$from = $p + 1;
	}
	return false;
}

/**
 * Script services an inline body loads or runs (all of them, library order):
 * inline markers (spaces around punctuation ignored), the extra regexes, the
 * plugin's GTM rule, and the service's hosts named as URLs in the body (an
 * inline loader that creates the script tag). As in CMP_Blocker::detect_services,
 * a longer host of another service (googletagmanager.com/gtag/js) is not this
 * service's.
 */
function cmp_check_services_for_body( string $body, array $services ): array {
	$out   = array();
	$norm  = cmp_check_norm_js( $body );
	$lower = strtolower( $body );
	foreach ( $services as $s ) {
		if ( 'script' !== $s['type'] ) {
			continue;
		}
		$hit = false;
		foreach ( $s['inline'] as $needle ) {
			if ( '' !== $needle && false !== stripos( $norm, cmp_check_norm_js( $needle ) ) ) {
				$hit = true;
				break;
			}
		}
		foreach ( $hit ? array() : $s['inline_re'] as $re ) {
			if ( 1 === @preg_match( $re, $norm ) ) { // phpcs:ignore
				$hit = true;
				break;
			}
		}
		// The plugin's GTM rule (CMP_Services::script_service): a GTM- id next to dataLayer.
		if ( ! $hit && $s['gtm'] && false !== strpos( $body, 'GTM-' ) && false !== strpos( $body, 'dataLayer' ) ) {
			$hit = true;
		}
		foreach ( $hit ? array() : $s['hosts'] as $h ) {
			$h   = strtolower( $h );
			$hay = $lower;
			foreach ( $services as $o ) {
				if ( $o['id'] === $s['id'] || 'script' !== $o['type'] ) {
					continue;
				}
				foreach ( $o['hosts'] as $oh ) {
					if ( 0 === stripos( $oh, $h . '/' ) ) {
						$hay = str_replace( strtolower( $oh ), '', $hay );
					}
				}
			}
			if ( '' !== $h && cmp_check_body_has_host( $hay, $h ) ) {
				$hit = true;
				break;
			}
		}
		if ( $hit ) {
			$out[] = $s;
		}
	}
	return $out;
}

/* ------------------------------------------------------------------
 * Third-party resources that send the visitor's IP (from the plugin scanner).
 * ------------------------------------------------------------------ */

/** Host (exact, or any subdomain when the entry starts with '*.') => resource type. Same list as CMP_Scanner::IP_HOSTS. */
function cmp_check_ip_hosts(): array {
	return array(
		'fonts.googleapis.com' => 'google-fonts',
		'fonts.gstatic.com'    => 'google-fonts',
		'use.typekit.net'      => 'adobe-fonts',
		'p.typekit.net'        => 'adobe-fonts',
		'use.fontawesome.com'  => 'font-awesome',
		'kit.fontawesome.com'  => 'font-awesome',
		'cdn.jsdelivr.net'     => 'cdn',
		'unpkg.com'            => 'cdn',
		'cdnjs.cloudflare.com' => 'cdn',
		'secure.gravatar.com'  => 'gravatar',
		'*.gravatar.com'       => 'gravatar',
		's.w.org'              => 'emoji',
	);
}

/** Resource type for a host, or ''. Same rule as CMP_Scanner::ip_type. */
function cmp_check_ip_type( string $host ): string {
	$host = strtolower( $host );
	foreach ( cmp_check_ip_hosts() as $h => $type ) {
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
 * Add the IP-leaking resources named in $text (URLs, also JSON-escaped ones) to
 * $found as type => list of hosts. Same URL pattern as CMP_Scanner::ip_resources,
 * read one match at a time (no match list in memory). Returns false when it
 * stopped at CMP_CHECK_MAX_URLS URLs before the end of $text.
 */
function cmp_check_scan_resources( string $text, string $home, array &$found ): bool {
	if ( '' === $text || false === strpos( $text, '/' ) ) {
		return true;
	}
	$len = strlen( $text );
	$off = 0;
	$n   = 0;
	while ( $off < $len && 1 === preg_match( '#(?:https?:)?\\\\?/\\\\?/[a-z0-9.-]+#i', $text, $m, PREG_OFFSET_CAPTURE, $off ) ) {
		if ( ++$n > CMP_CHECK_MAX_URLS ) {
			return false;
		}
		$off = $m[0][1] + max( 1, strlen( $m[0][0] ) );
		$u   = str_replace( '\\', '', $m[0][0] );
		$u   = 0 === strpos( $u, '//' ) ? 'https:' . $u : $u;
		if ( cmp_check_is_own_host( $u, $home ) ) {
			continue;
		}
		$host = @parse_url( $u, PHP_URL_HOST ); // phpcs:ignore
		if ( ! is_string( $host ) ) {
			continue;
		}
		$host = strtolower( $host );
		$type = cmp_check_ip_type( $host );
		if ( '' === $type ) {
			continue;
		}
		if ( ! isset( $found[ $type ] ) ) {
			$found[ $type ] = array();
		}
		if ( ! in_array( $host, $found[ $type ], true ) ) {
			$found[ $type ][] = $host;
		}
	}
	return true;
}

/** Does a <link> fetch something (stylesheet, preload, preconnect, icon...)? Canonical, alternate etc. do not. */
function cmp_check_link_fetches( array $attrs ): bool {
	$rel = isset( $attrs['rel'] ) ? preg_split( '/\s+/', strtolower( trim( $attrs['rel'] ) ) ) : array();
	return array() !== array_intersect( (array) $rel, array( 'stylesheet', 'preload', 'prefetch', 'preconnect', 'modulepreload', 'icon', 'apple-touch-icon', 'manifest', 'prerender' ) );
}

/* ------------------------------------------------------------------
 * Headers, CMPs, policy link.
 * ------------------------------------------------------------------ */

/**
 * Cookie names from the Set-Cookie headers. $headers may be name => value|values
 * or a list of "Name: value" lines. Names are sanitised (letters, digits,
 * _ . - / :, at most 64 characters); at most 50 are kept.
 */
function cmp_check_cookie_names( array $headers ): array {
	$lines = array();
	foreach ( $headers as $k => $v ) {
		if ( is_string( $k ) ) {
			if ( 'set-cookie' !== strtolower( trim( $k ) ) ) {
				continue;
			}
			foreach ( is_array( $v ) ? $v : array( $v ) as $line ) {
				if ( is_string( $line ) ) {
					$lines[] = $line;
				}
			}
		} elseif ( is_string( $v ) && preg_match( '/^\s*set-cookie\s*:(.*)$/is', $v, $m ) ) {
			$lines[] = $m[1];
		}
	}
	$names = array();
	foreach ( $lines as $line ) {
		$eq = strpos( $line, '=' );
		if ( false === $eq ) {
			continue;
		}
		$name = substr( (string) preg_replace( '/[^A-Za-z0-9_.\-\/:]/', '', substr( $line, 0, $eq ) ), 0, 64 );
		if ( '' !== $name && ! in_array( $name, $names, true ) ) {
			$names[] = $name;
		}
		if ( count( $names ) >= 50 ) {
			break;
		}
	}
	return $names;
}

/** The HTML without its <!-- comments --> (an unclosed comment runs to the end). */
function cmp_check_strip_comments( string $html ): string {
	$out = '';
	$pos = 0;
	while ( false !== ( $p = strpos( $html, '<!--', $pos ) ) ) {
		$out .= substr( $html, $pos, $p - $pos );
		$end  = strpos( $html, '-->', $p + 4 );
		if ( false === $end ) {
			return $out;
		}
		$pos = $end + 3;
	}
	return $out . substr( $html, $pos );
}

/** The CMP found on the page as array( slug, name ), ours first, or null (comments ignored). */
function cmp_check_detect_cmp( string $html, array $parsed, string $home ) {
	foreach ( $parsed['scripts'] as $sc ) {
		$src = isset( $sc['attrs']['src'] ) ? trim( $sc['attrs']['src'] ) : '';
		if ( '' === $src ) {
			continue;
		}
		$own  = '' === cmp_check_abs( $src ) || cmp_check_is_own_host( $src, $home );
		$path = (string) @parse_url( $src, PHP_URL_PATH ); // phpcs:ignore
		if ( $own && 1 === preg_match( '#(?:^|/)cmp(?:\.min)?\.js$#i', $path ) ) {
			return array(
				'slug' => 'consent-manager-pro',
				'name' => 'Consent Manager Pro',
			);
		}
	}
	$lower = strtolower( cmp_check_strip_comments( $html ) );
	foreach ( cmp_check_cmp_signatures() as $c ) {
		foreach ( $c['needles'] as $needle ) {
			if ( false !== strpos( $lower, $needle ) ) {
				return array(
					'slug' => $c['slug'],
					'name' => $c['name'],
				);
			}
		}
	}
	return null;
}

/** ASCII-folded lowercase text for keyword matching (ș/ş -> s, ț/ţ -> t, ă/â -> a, î -> i). */
function cmp_check_fold( string $s ): string {
	$s = cmp_check_lower( $s );
	return strtr(
		$s,
		array(
			'ș' => 's',
			'ş' => 's',
			'ț' => 't',
			'ţ' => 't',
			'ă' => 'a',
			'â' => 'a',
			'î' => 'i',
			'Ș' => 's',
			'Ş' => 's',
			'Ț' => 't',
			'Ţ' => 't',
			'Ă' => 'a',
			'Â' => 'a',
			'Î' => 'i',
		)
	);
}

/**
 * Does a folded label or path name a privacy or cookie policy? Privacy words
 * count alone; "cookie" (or "куки") counts only next to policy / politica /
 * политика, so a "Setări cookie" button is not a policy.
 */
function cmp_check_names_policy( string $text, array $words ): bool {
	if ( '' === $text ) {
		return false;
	}
	foreach ( $words as $w ) {
		if ( false !== strpos( $text, $w ) ) {
			return true;
		}
	}
	$cookie = false !== strpos( $text, 'cookie' ) || false !== strpos( $text, 'куки' );
	return $cookie && 1 === preg_match( '/polic|politic|politik|политик/u', $text );
}

/** Is there a link to a privacy or cookie policy (RO / RU / EN texts, common paths, rel="privacy-policy")? */
function cmp_check_has_policy_link( array $parsed ): bool {
	$words = array( 'confidentialitate', 'date personale', 'datelor personale', 'protectia datelor', 'privacy', 'data protection', 'gdpr', 'конфиденциальн', 'персональных данных', 'защита данных', 'защите данных' );
	$paths = array( 'privacy', 'confidentialitate', 'gdpr', 'date-personale', 'datelor-personale', 'protectia-datelor', 'konfidenc', 'konfidents', 'конфиденц', 'politika-konf' );
	// A <link> counts only as rel="privacy-policy" (a stylesheet named privacy.css is not a policy).
	foreach ( $parsed['links'] as $attrs ) {
		if ( isset( $attrs['rel'] ) && in_array( 'privacy-policy', preg_split( '/\s+/', strtolower( trim( $attrs['rel'] ) ) ), true ) ) {
			return true;
		}
	}
	foreach ( $parsed['anchors'] as $a ) {
		if ( in_array( 'privacy-policy', preg_split( '/\s+/', strtolower( trim( $a['rel'] ) ) ), true ) ) {
			return true;
		}
		if ( cmp_check_names_policy( cmp_check_fold( $a['label'] ), $words ) ) {
			return true;
		}
		$href = (string) @parse_url( $a['href'], PHP_URL_PATH ); // phpcs:ignore
		if ( cmp_check_names_policy( cmp_check_fold( rawurldecode( $href ) ), $paths ) ) {
			return true;
		}
	}
	return false;
}

/** Is this an anti-bot challenge page instead of the site? */
function cmp_check_is_challenge( string $html ): bool {
	$l = strtolower( substr( $html, 0, 200000 ) );
	foreach ( array( '_cf_chl_opt', 'cf-browser-verification', '<title>just a moment...</title>', '/.well-known/sgcaptcha/' ) as $n ) {
		if ( false !== strpos( $l, $n ) ) {
			return true;
		}
	}
	return false;
}

/* ------------------------------------------------------------------
 * Texts (RO / EN). Romanian: comma-below ș/ț, " - " instead of a dash, "tu".
 * Laws: only as docs/product/2026-11-18-cadru-legal-moldova.md cites them.
 * ------------------------------------------------------------------ */

/** The law citation for a kind of finding. */
function cmp_check_law( string $kind, string $lang ): string {
	$laws = array(
		// Legal doc line 56, the table row "Opt-in înainte de cookie-urile neesențiale": "L195 art. 6–7, L72 art. 116".
		'consent'  => array(
			'ro' => 'Legea nr. 195/2024, art. 6–7; Legea nr. 72/2025, art. 116',
			'en' => 'Law No. 195/2024, Arts. 6–7; Law No. 72/2025, Art. 116',
		),
		// Legal doc line 31 (art. 44–49, "Pentru alte țări, inclusiv SUA (Google, Meta)") and line 62 ("Transferuri spre SUA (GA, Meta) | L195 art. 44–49").
		'transfer' => array(
			'ro' => 'Legea nr. 195/2024, art. 44–49 (transferuri în afara SEE, de ex. SUA)',
			'en' => 'Law No. 195/2024, Arts. 44–49 (transfers outside the EEA, e.g. the US)',
		),
		// Legal doc line 57, the table row "Informare clară despre scopuri, înainte": "L72 art. 116(5), L195 art. 12–13".
		'inform'   => array(
			'ro' => 'Legea nr. 72/2025, art. 116 alin. (5); Legea nr. 195/2024, art. 12–13',
			'en' => 'Law No. 72/2025, Art. 116(5); Law No. 195/2024, Arts. 12–13',
		),
	);
	return isset( $laws[ $kind ] ) ? $laws[ $kind ][ $lang ] : '';
}

/** Title and explanation of a finding, with {placeholders} filled from $vars (plain text). */
function cmp_check_text( string $key, string $lang, array $vars = array() ): array {
	$t = array(
		'tracker'          => array(
			'ro' => array( '{name} pornește înainte de acord', 'Pagina pornește {name} imediat ce intră cineva, fără să-l întrebe. Un tracker are voie să pornească doar după ce vizitatorul spune „Da” în banner.' ),
			'en' => array( '{name} starts before consent', 'The page starts {name} as soon as someone arrives, without asking. A tracker may only start after the visitor says yes in the banner.' ),
		),
		'embed'            => array(
			'ro' => array( '{name} se încarcă înainte de acord', 'Pagina încarcă {name} direct, așa că furnizorul primește IP-ul vizitatorului și poate pune cookie-uri înainte de acord. Pune în loc o imagine care încarcă {name} doar la clic.' ),
			'en' => array( '{name} loads before consent', 'The page loads {name} directly, so the provider gets the visitor\'s IP address and can set cookies before consent. Show a placeholder that loads {name} only on click.' ),
		),
		'cookieless'       => array(
			'ro' => array( 'Folosești {name}', '{name} spune că nu folosește cookie-uri. Pomenește-l totuși în politica de confidențialitate.' ),
			'en' => array( 'You use {name}', '{name} says it uses no cookies. Mention it in your privacy policy anyway.' ),
		),
		'loader'           => array(
			'ro' => array( '{name} poate încărca trackere pe care nu le vedem', '{name} încarcă alte scripturi după ce pagina pornește, așa că de aici nu putem ști ce aduce. Verifică singur: deschide site-ul într-o fereastră privată, apoi în DevTools, la Network și la Application - Cookies, uită-te ce apare înainte să alegi ceva în banner.' ),
			'en' => array( '{name} may load trackers we cannot see', '{name} loads other scripts after the page starts, so we cannot tell from here what it brings. Check it yourself: open the site in a private window, then in DevTools, under Network and Application - Cookies, look at what shows up before you choose anything in the banner.' ),
		),
		'resource'         => array(
			'ro' => array( '{name} trimite IP-ul vizitatorilor', 'Pagina încarcă {what} ({hosts}). Browserul fiecărui vizitator se conectează la acel server și îi trimite adresa IP, care e o dată personală, fără să fi fost întrebat. Găzduiește fișierele pe serverul tău sau renunță la ele.' ),
			'en' => array( '{name} sends your visitors\' IP address', 'The page loads {what} ({hosts}). Every visitor\'s browser connects to that server and sends it their IP address, which is personal data, without being asked. Host the files on your own server or drop them.' ),
		),
		'held-attribute'   => array(
			'ro' => array( '{name}: marcat pentru banner, dar tot JavaScript', 'Scriptul are marcajul unui banner de cookie-uri, dar tipul lui e tot JavaScript. E oprit doar dacă bannerul rulează în modul de blocare automată; altfel pornește înainte de acord. Verifică în DevTools, la Network, ce se încarcă înainte să alegi ceva în banner.' ),
			'en' => array( '{name}: marked for the banner, but still JavaScript', 'The script carries a cookie banner\'s marker, but its type is still JavaScript. It is held only if the banner runs in automatic blocking mode; otherwise it starts before consent. Check in DevTools, under Network, what loads before you choose anything in the banner.' ),
		),
		'held-unknown'     => array(
			'ro' => array( '{name}: script cu un tip necunoscut', 'Scriptul are un tip pe care nu-l recunoaștem, așa că nu știm dacă pornește înainte de acord. Verifică în DevTools, la Network, ce se încarcă înainte să alegi ceva în banner.' ),
			'en' => array( '{name}: a script of an unknown type', 'The script has a type we do not recognise, so we cannot tell whether it starts before consent. Check in DevTools, under Network, what loads before you choose anything in the banner.' ),
		),
		'consent-mode'     => array(
			'ro' => array( '{name} pornește în Consent Mode, înainte de acord', '{name} se încarcă cu acordul setat pe „refuzat”. Nu pune cookie-uri, dar trimite lui Google semnale fără cookie, cu IP-ul vizitatorului, înainte de acord. E o zonă gri: IP-ul e o dată personală, iar lectura sigură a legii e opt-in înainte de orice cookie neesențial.' ),
			'en' => array( '{name} runs in Consent Mode, before consent', '{name} loads with consent set to "denied". It sets no cookies, but it sends Google cookieless pings, with the visitor\'s IP address, before consent. This is a grey zone: the IP address is personal data, and the safe reading of the law is opt-in before any non-essential cookie.' ),
		),
		'cookie'           => array(
			'ro' => array( 'Cookie-ul {cookie} ({name}) e pus înainte de acord', 'Serverul trimite cookie-ul {cookie}, al serviciului {name}, de la prima vizită. Cookie-urile de analiză sau de marketing au nevoie de acord înainte.' ),
			'en' => array( 'The {cookie} cookie ({name}) is set before consent', 'The server sends the {cookie} cookie, used by {name}, on the very first visit. Analytics and marketing cookies need consent first.' ),
		),
		'cookies-other'    => array(
			'ro' => array( 'Serverul mai pune cookie-uri: {list}', 'Nu le recunoaștem. Dacă sunt strict necesare (sesiune, coș, securitate), nu au nevoie de acord. Dacă urmăresc vizitatorul, au nevoie.' ),
			'en' => array( 'The server sets other cookies too: {list}', 'We do not recognise them. If they are strictly necessary (session, cart, security), they need no consent. If they track the visitor, they do.' ),
		),
		'policy'           => array(
			'ro' => array( 'Nu găsim un link spre politica de confidențialitate', 'Pagina are trackere, dar nu găsim pe ea un link spre politica de confidențialitate sau de cookie-uri. Vizitatorul trebuie să afle clar, dinainte, ce colectezi și de ce.' ),
			'en' => array( 'We cannot find a link to a privacy policy', 'The page has trackers, but we cannot find a link to a privacy or cookie policy on it. Visitors must be told clearly, beforehand, what you collect and why.' ),
		),
		'cmp'              => array(
			'ro' => array( 'Site-ul folosește {name}', 'Am găsit bannerul {name}. Un banner ajută doar dacă ține trackerele oprite până la acord.' ),
			'en' => array( 'The site uses {name}', 'We found the {name} banner. A banner only helps if it keeps trackers off until consent.' ),
		),
		'ours'             => array(
			'ro' => array( 'Folosești deja Consent Manager Pro', 'Pluginul nostru e pe pagină. Mulțumim că îl folosești!' ),
			'en' => array( 'You already use Consent Manager Pro', 'Our plugin is on the page. Thank you for using it!' ),
		),
		'ours-attention'   => array(
			'ro' => array( 'Folosești deja Consent Manager Pro', 'Pluginul nostru e pe pagină, dar ceva tot pornește înainte de acord. Verifică în setări blocarea scripturilor și lista de servicii, apoi actualizează pluginul la ultima versiune.' ),
			'en' => array( 'You already use Consent Manager Pro', 'Our plugin is on the page, but something still starts before consent. Check script blocking and the service list in its settings, then update the plugin to the latest version.' ),
		),
		'empty'            => array(
			'ro' => array( 'Pagina e goală', 'Serverul a răspuns, dar fără conținut, așa că nu avem ce verifica.' ),
			'en' => array( 'The page is empty', 'The server answered, but with no content, so there is nothing to check.' ),
		),
		'challenge'        => array(
			'ro' => array( 'Site-ul blochează verificările automate', 'Am primit o pagină de protecție anti-bot în locul site-ului. Verifică singur, din browser, cu DevTools.' ),
			'en' => array( 'The site blocks automated checks', 'We got an anti-bot protection page instead of the site. Check it yourself in your browser, with DevTools.' ),
		),
		'truncated'        => array(
			'ro' => array( 'Am verificat doar primii 2 MB ai paginii', 'Pagina e foarte mare. Ce e după primii 2 MB nu am citit.' ),
			'en' => array( 'We only checked the first 2 MB of the page', 'The page is very large. We did not read past the first 2 MB.' ),
		),
		'partial'          => array(
			'ro' => array( 'Nu am putut citi toată pagina', 'Pagina are prea multe elemente ca s-o citim până la capăt. Ce e spre final nu am verificat.' ),
			'en' => array( 'We could not read the whole page', 'The page has too many elements to read to the end. We did not check what comes last.' ),
		),
	);
	$pair = isset( $t[ $key ][ $lang ] ) ? $t[ $key ][ $lang ] : array( '', '' );
	$map  = array();
	foreach ( $vars as $k => $v ) {
		$map[ '{' . $k . '}' ] = cmp_check_plain( $v, 200 );
	}
	return array(
		'title'   => strtr( $pair[0], $map ),
		'explain' => strtr( $pair[1], $map ),
	);
}

/** Name of a resource type: 'title' (start of a sentence) or 'what' (inside one). */
function cmp_check_resource_label( string $type, string $lang, string $form = 'title' ): string {
	$labels = array(
		'google-fonts' => array( 'Google Fonts', 'Google Fonts', 'Google Fonts', 'Google Fonts' ),
		'adobe-fonts'  => array( 'Adobe Fonts', 'Adobe Fonts', 'Adobe Fonts', 'Adobe Fonts' ),
		'font-awesome' => array( 'Font Awesome', 'Font Awesome', 'Font Awesome', 'Font Awesome' ),
		'cdn'          => array( 'Un CDN public', 'fișiere de pe un CDN public', 'A public CDN', 'files from a public CDN' ),
		'gravatar'     => array( 'Gravatar', 'avatare Gravatar', 'Gravatar', 'Gravatar avatars' ),
		'emoji'        => array( 'Emoji WordPress', 'emoji-urile WordPress', 'WordPress emoji', 'WordPress emoji' ),
	);
	if ( ! isset( $labels[ $type ] ) ) {
		return $type;
	}
	return $labels[ $type ][ ( 'en' === $lang ? 2 : 0 ) + ( 'what' === $form ? 1 : 0 ) ];
}

/** Finding ids for services: the brief's short names where they differ from the library id. */
function cmp_check_service_slug( string $id ): string {
	$alias = array( 'google-analytics' => 'ga4' );
	return isset( $alias[ $id ] ) ? $alias[ $id ] : $id;
}

/* ------------------------------------------------------------------
 * The analysis.
 * ------------------------------------------------------------------ */

/** Status from findings: any issue -> issues; else any unsure -> unsure; else clean. */
function cmp_check_status( array $findings ): string {
	$sev = array_column( $findings, 'severity' );
	if ( in_array( 'issue', $sev, true ) ) {
		return 'issues';
	}
	return in_array( 'unsure', $sev, true ) ? 'unsure' : 'clean';
}

/**
 * Analyse a homepage fetched without cookies, as a new visitor sees it.
 *
 * @param string $html     The response body (only the first 2 MB are read).
 * @param array  $headers  Response headers: name => value|values, or "Name: value" lines.
 * @param string $url      The checked URL (own-site resources are not third-party).
 * @param array  $services The service library (cmp_check_load_services()).
 * @param string $lang     'ro' (default) or 'en'.
 * @return array array( 'status' => clean|issues|unsure|error, 'findings' => list of
 *               array( id, severity => issue|unsure|info, title, explain, law ), 'cmp' => string|null ).
 */
function cmp_check_analyze( string $html, array $headers, string $url, array $services, string $lang = 'ro' ): array {
	$lang     = 'en' === $lang ? 'en' : 'ro';
	$services = cmp_check_merge_extra( cmp_check_norm_services( $services ), $lang );
	$findings = array();
	$add      = function ( $id, $severity, $text_key, array $vars, $law_kind ) use ( &$findings, $lang ) {
		if ( isset( $findings[ $id ] ) ) {
			return;
		}
		$text            = cmp_check_text( $text_key, $lang, $vars );
		$findings[ $id ] = array(
			'id'       => $id,
			'severity' => $severity,
			'title'    => $text['title'],
			'explain'  => $text['explain'],
			'law'      => '' === $law_kind ? '' : cmp_check_law( $law_kind, $lang ),
		);
	};
	$finish = function ( $status, $cmp ) use ( &$findings ) {
		$list = array();
		foreach ( $findings as $f ) {
			$list[] = array(
				'id'       => cmp_check_plain( $f['id'], 100 ),
				'severity' => $f['severity'],
				'title'    => cmp_check_plain( $f['title'] ),
				'explain'  => cmp_check_plain( $f['explain'], 600 ),
				'law'      => cmp_check_plain( $f['law'] ),
			);
		}
		return array(
			'status'   => null === $status ? cmp_check_status( $list ) : $status,
			'findings' => $list,
			'cmp'      => null === $cmp ? null : cmp_check_plain( $cmp, 100 ),
		);
	};

	if ( '' === trim( $html ) ) {
		$add( 'page:empty', 'info', 'empty', array(), '' );
		return $finish( 'error', null );
	}
	if ( strlen( $html ) > CMP_CHECK_MAX_BYTES ) {
		$html = substr( $html, 0, CMP_CHECK_MAX_BYTES );
		$add( 'page:truncated', 'unsure', 'truncated', array(), '' );
	}
	if ( cmp_check_is_challenge( $html ) ) {
		$findings = array();
		$add( 'page:blocked', 'info', 'challenge', array(), '' );
		return $finish( 'error', null );
	}

	$parsed     = cmp_check_parse( $html );
	$partial    = $parsed['partial'];
	$resources  = array();
	$trackers   = false;
	$denied     = false; // A Consent Mode default with "denied" seen before (Advanced mode).
	$held_attr  = array(); // Names of trackers held only by a CMP attribute on a live script.
	$held_type  = array(); // Names of trackers in a script of an unknown type.
	$service_finding = function ( array $s, $embed ) use ( $add, &$trackers, &$denied ) {
		if ( 'necessary' === $s['category'] ) {
			return;
		}
		if ( $s['gtm'] ) {
			$add( 'loader:gtm', 'unsure', 'loader', array( 'name' => 'Google Tag Manager' ), 'consent' );
			return;
		}
		$id = 'tracker:' . cmp_check_service_slug( $s['id'] );
		if ( $s['exempt_default'] ) {
			$add( $id, 'info', 'cookieless', array( 'name' => $s['name'] ), '' );
			return;
		}
		$trackers = true;
		if ( $denied && 'google-analytics' === $s['id'] ) {
			$add( $id . '-consent-mode', CMP_CHECK_CONSENT_MODE_SEVERITY, 'consent-mode', array( 'name' => $s['name'] ), 'consent' );
			return;
		}
		$add( $id, 'issue', $embed ? 'embed' : 'tracker', array( 'name' => $s['name'] ), 'consent' );
	};
	// What a script loads or runs: loaders and services, as findings to make.
	$hits_of = function ( $src, $body ) use ( $services ) {
		$hits = array();
		if ( '' !== $src ) {
			$loader = cmp_check_loader_for( $src, '' );
			$abs    = cmp_check_abs( $src );
			if ( null !== $loader ) {
				$hits[] = array( 'loader' => $loader );
			} elseif ( '' !== $abs ) {
				$s = cmp_check_service_for_url( $abs, $services, array( 'script' ) );
				if ( null !== $s ) {
					$hits[] = array( 'service' => $s );
				}
			}
			return $hits;
		}
		$loader = cmp_check_loader_for( '', $body );
		if ( null !== $loader ) {
			$hits[] = array( 'loader' => $loader );
		}
		foreach ( cmp_check_services_for_body( $body, $services ) as $s ) {
			$hits[] = array( 'service' => $s );
		}
		return $hits;
	};
	// The name of a hit that would be reported, or '' (necessary / cookieless services).
	$hit_name = function ( array $hit ) {
		if ( isset( $hit['loader'] ) ) {
			return $hit['loader']['name'];
		}
		$s = $hit['service'];
		return 'necessary' === $s['category'] || $s['exempt_default'] ? '' : $s['name'];
	};

	// Cookiebot in automatic blocking mode holds every marked script itself.
	$auto_blocking = false;
	foreach ( $parsed['scripts'] as $sc ) {
		$src = isset( $sc['attrs']['src'] ) ? strtolower( $sc['attrs']['src'] ) : '';
		if ( 1 === preg_match( '#consent\.cookiebot\.(?:com|eu)/uc\.js#', $src ) && isset( $sc['attrs']['data-blockingmode'] ) && 'auto' === strtolower( trim( $sc['attrs']['data-blockingmode'] ) ) ) {
			$auto_blocking = true;
		}
	}

	// Scripts, in page order.
	foreach ( $parsed['scripts'] as $sc ) {
		$kind = cmp_check_script_kind( $sc['attrs'] );
		if ( 'inert' === $kind ) {
			continue;
		}
		$src    = cmp_check_script_src( $sc['attrs'] );
		$body   = '' === $src ? $sc['body'] : '';
		$marked = cmp_check_held_by_cmp( $sc['attrs'] );
		if ( 'live' === $kind && ! $marked && '' !== $body ) {
			$norm = cmp_check_norm_js( $body );
			if ( 1 === preg_match( '#gtag\([\'"]consent[\'"],[\'"]default[\'"],#', $norm ) && false !== stripos( $norm, 'denied' ) ) {
				$denied = true;
			}
		}
		$hits = $hits_of( $src, $body );
		if ( 'unknown' === $kind || $marked ) {
			if ( 'live' === $kind && $auto_blocking ) {
				continue;
			}
			foreach ( $hits as $hit ) {
				$name = $hit_name( $hit );
				if ( '' !== $name ) {
					if ( 'unknown' === $kind ) {
						$held_type[ $name ] = true;
					} else {
						$held_attr[ $name ] = true;
					}
				}
			}
			continue;
		}
		foreach ( $hits as $hit ) {
			if ( isset( $hit['loader'] ) ) {
				$add( 'loader:' . $hit['loader']['slug'], 'unsure', 'loader', array( 'name' => $hit['loader']['name'] ), 'consent' );
			} else {
				$service_finding( $hit['service'], false );
			}
		}
		if ( ! cmp_check_scan_resources( '' !== $src ? $src : $body, $url, $resources ) ) {
			$partial = true;
		}
	}

	// Live iframes (data-src only or about:blank: not live).
	foreach ( $parsed['iframes'] as $a ) {
		$src = isset( $a['src'] ) ? cmp_check_abs( $a['src'] ) : '';
		if ( '' === $src ) {
			continue;
		}
		$s = cmp_check_service_for_url( $src, $services, array( 'embed', 'script' ) );
		if ( cmp_check_held_by_cmp( $a ) ) {
			// A marker does not stop a src that is already there, unless Cookiebot blocks automatically.
			if ( null !== $s && ! $auto_blocking && '' !== $hit_name( array( 'service' => $s ) ) ) {
				$held_attr[ $s['name'] ] = true;
			}
			continue;
		}
		if ( null !== $s ) {
			$service_finding( $s, 'embed' === $s['type'] );
		}
		cmp_check_scan_resources( $src, $url, $resources );
	}
	// <link rel="preload" as="script"> and rel="modulepreload" fetch the script URL before consent.
	foreach ( $parsed['links'] as $a ) {
		$rel  = isset( $a['rel'] ) ? preg_split( '/\s+/', strtolower( trim( $a['rel'] ) ) ) : array();
		$as   = isset( $a['as'] ) ? strtolower( trim( $a['as'] ) ) : '';
		$href = isset( $a['href'] ) ? trim( $a['href'] ) : '';
		if ( '' === $href || ! ( in_array( 'modulepreload', (array) $rel, true ) || ( in_array( 'preload', (array) $rel, true ) && 'script' === $as ) ) ) {
			continue;
		}
		foreach ( $hits_of( $href, '' ) as $hit ) {
			if ( isset( $hit['loader'] ) ) {
				$add( 'loader:' . $hit['loader']['slug'], 'unsure', 'loader', array( 'name' => $hit['loader']['name'] ), 'consent' );
			} else {
				$service_finding( $hit['service'], false );
			}
		}
	}
	// Tracking pixels (<noscript> ones are skipped: they only fire without JavaScript).
	foreach ( array_keys( $parsed['imgs'] ) as $src ) {
		$s = cmp_check_service_for_url( (string) $src, $services, array( 'script' ) );
		if ( null !== $s ) {
			$service_finding( $s, false );
		}
	}
	// Resources fetched by other elements and by <style>.
	foreach ( array_merge( array( $parsed['res'] ), $parsed['styles'] ) as $text ) {
		if ( ! cmp_check_scan_resources( $text, $url, $resources ) ) {
			$partial = true;
		}
	}
	foreach ( $resources as $type => $hosts ) {
		$add(
			'resource:' . $type,
			'issue',
			'resource',
			array(
				'name'  => cmp_check_resource_label( $type, $lang ),
				'what'  => cmp_check_resource_label( $type, $lang, 'what' ),
				'hosts' => implode( ', ', array_slice( $hosts, 0, 3 ) ),
			),
			'transfer'
		);
	}
	$names = function ( array $set ) {
		$list = array_keys( $set );
		return implode( ', ', array_slice( $list, 0, 5 ) ) . ( count( $list ) > 5 ? '...' : '' );
	};
	if ( array() !== $held_attr ) {
		$add( 'held:attribute', 'unsure', 'held-attribute', array( 'name' => $names( $held_attr ) ), 'consent' );
	}
	if ( array() !== $held_type ) {
		$add( 'held:unknown-type', 'unsure', 'held-unknown', array( 'name' => $names( $held_type ) ), 'consent' );
	}
	if ( $partial ) {
		$add( 'page:partial', 'unsure', 'partial', array(), '' );
	}

	// Cookies set by the server on the first visit.
	$other = array();
	foreach ( cmp_check_cookie_names( $headers ) as $name ) {
		$svc = null;
		foreach ( $services as $s ) {
			foreach ( $s['cookies'] as $pattern ) {
				if ( cmp_check_cookie_matches( $name, $pattern ) ) {
					$svc = $s;
					break 2;
				}
			}
		}
		if ( null === $svc ) {
			$other[] = $name;
		} elseif ( 'necessary' !== $svc['category'] && ! $svc['exempt_default'] ) {
			$trackers = true;
			$add(
				'cookie:' . $name,
				'issue',
				'cookie',
				array(
					'cookie' => $name,
					'name'   => $svc['name'],
				),
				'consent'
			);
		}
	}
	if ( array() !== $other ) {
		$add( 'cookies:other', 'info', 'cookies-other', array( 'list' => implode( ', ', array_slice( $other, 0, 5 ) ) . ( count( $other ) > 5 ? '...' : '' ) ), '' );
	}

	if ( $trackers && ! cmp_check_has_policy_link( $parsed ) ) {
		$add( 'policy:missing', 'issue', 'policy', array(), 'inform' );
	}

	$cmp = cmp_check_detect_cmp( $html, $parsed, $url );
	if ( null !== $cmp ) {
		if ( 'consent-manager-pro' === $cmp['slug'] ) {
			$attention = 'clean' !== cmp_check_status( array_values( $findings ) );
			$add( 'cmp:consent-manager-pro', 'info', $attention ? 'ours-attention' : 'ours', array(), '' );
		} else {
			$add( 'cmp:' . $cmp['slug'], 'info', 'cmp', array( 'name' => $cmp['name'] ), '' );
		}
	}
	return $finish( null, null === $cmp ? null : $cmp['name'] );
}
