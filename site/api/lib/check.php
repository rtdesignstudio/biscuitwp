<?php
if ( ! defined( 'CMP_CHECK' ) ) {
	http_response_code( 404 );
	exit;
}
/**
 * BiscuitWP, "Verifică-ți site-ul" (consent.rt.md): the page analysis.
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
		// A language without its own name (signatures.php names ro / en) uses the Romanian one: the names are brand names.
		$x['name'] = isset( $x['name'][ $lang ] ) ? $x['name'][ $lang ] : ( isset( $x['name']['ro'] ) ? $x['name']['ro'] : $x['id'] );
		$new[]     = $x;
	}
	// Additions first: their src_re (Google Ads ids on gtag/js) must win over the library's hosts.
	return array_merge( cmp_check_norm_services( $new ), $services );
}

/* ------------------------------------------------------------------
 * Small helpers.
 * ------------------------------------------------------------------ */

/** Plain, single-line text: no tags, no angle brackets, no control, bidi or zero-width characters, at most $max characters. */
function cmp_check_plain( $s, int $max = 400 ): string {
	$s = (string) $s;
	if ( 1 !== preg_match( '//u', $s ) ) {
		$s = (string) preg_replace( '/[^\x20-\x7E]/', '', $s );
	}
	// Unicode format characters (bidi overrides, zero-width, BOM) and C1 controls: dropped.
	$s = (string) preg_replace( '/[\p{Cf}\x{80}-\x{9F}]+/u', '', $s );
	$s = strip_tags( $s );
	$s = str_replace( array( '<', '>' ), '', $s );
	$s = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $s );
	$s = trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
	if ( function_exists( 'mb_substr' ) ) {
		return mb_strlen( $s, 'UTF-8' ) > $max ? rtrim( mb_substr( $s, 0, $max - 3, 'UTF-8' ) ) . '...' : $s;
	}
	return strlen( $s ) > $max ? rtrim( substr( $s, 0, $max - 3 ) ) . '...' : $s;
}

/**
 * Evidence for a URL the page loads: host + path, no scheme, query or fragment,
 * reduced to URL-safe characters and capped at 120. A relative URL gives its
 * path; any other scheme (data:, javascript:...) gives ''.
 */
function cmp_check_evidence_url( string $url ): string {
	// Browsers ignore C0 controls and spaces around (and tabs / newlines inside) a URL.
	$url = (string) preg_replace( '/[\x00-\x20]+/', '', substr( $url, 0, 2048 ) );
	if ( 0 === strpos( $url, '//' ) ) {
		$url = 'https:' . $url;
	}
	$host = '';
	if ( 1 === preg_match( '#^https?://#i', $url ) ) {
		$host = @parse_url( $url, PHP_URL_HOST ); // phpcs:ignore
		$path = @parse_url( $url, PHP_URL_PATH ); // phpcs:ignore
		$host = is_string( $host ) ? strtolower( $host ) : '';
		if ( 1 !== preg_match( '/^[a-z0-9.-]{1,253}$/', $host ) ) {
			return '';
		}
		$path = is_string( $path ) ? $path : '';
	} elseif ( 1 === preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) ) {
		return '';
	} else {
		$path = substr( $url, 0, strcspn( $url, '?#' ) );
	}
	$path = (string) preg_replace( '#[^A-Za-z0-9._~%/+@:-]#', '', $path );
	return cmp_check_plain( $host . $path, 120 );
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
				'name' => 'BiscuitWP',
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
 * Texts (RO / EN / RU). Romanian: comma-below ș/ț, " - " instead of a dash, "tu".
 * Russian: «вы», «…» quotes, no em dash (the site's ru glossary).
 * Laws: only as docs/product/2026-11-18-cadru-legal-moldova.md cites them.
 * ------------------------------------------------------------------ */

/** A supported language: 'ro' (default), 'en' or 'ru'; anything else gives 'ro'. */
function cmp_check_lang( $lang ): string {
	return is_string( $lang ) && in_array( $lang, array( 'ro', 'en', 'ru' ), true ) ? $lang : 'ro';
}

/** Russian plural form for $n: $one (1, 21...), $few (2-4, 22-24...) or $many (0, 5-20, 25...). */
function cmp_check_ru_plural( int $n, string $one, string $few, string $many ): string {
	$a = abs( $n ) % 10;
	$b = abs( $n ) % 100;
	if ( 1 === $a && 11 !== $b ) {
		return $one;
	}
	return $a >= 2 && $a <= 4 && ( $b < 12 || $b > 14 ) ? $few : $many;
}

/** The law citation for a kind of finding. */
function cmp_check_law( string $kind, string $lang ): string {
	$laws = array(
		// Legal doc line 56, the table row "Opt-in înainte de cookie-urile neesențiale": "L195 art. 6–7, L72 art. 116".
		'consent'  => array(
			'ro' => 'Legea nr. 195/2024, art. 6–7; Legea nr. 72/2025, art. 116',
			'en' => 'Law No. 195/2024, Arts. 6–7; Law No. 72/2025, Art. 116',
			'ru' => 'Закон № 195/2024, ст. 6–7; Закон № 72/2025, ст. 116',
		),
		// Legal doc line 31 (art. 44–49, "Pentru alte țări, inclusiv SUA (Google, Meta)") and line 62 ("Transferuri spre SUA (GA, Meta) | L195 art. 44–49").
		'transfer' => array(
			'ro' => 'Legea nr. 195/2024, art. 44–49 (transferuri în afara SEE, de ex. SUA)',
			'en' => 'Law No. 195/2024, Arts. 44–49 (transfers outside the EEA, e.g. the US)',
			'ru' => 'Закон № 195/2024, ст. 44–49 (передача за пределы ЕЭЗ, например в США)',
		),
		// Legal doc line 57, the table row "Informare clară despre scopuri, înainte": "L72 art. 116(5), L195 art. 12–13".
		'inform'   => array(
			'ro' => 'Legea nr. 72/2025, art. 116 alin. (5); Legea nr. 195/2024, art. 12–13',
			'en' => 'Law No. 72/2025, Art. 116(5); Law No. 195/2024, Arts. 12–13',
			'ru' => 'Закон № 72/2025, ст. 116 ч. (5); Закон № 195/2024, ст. 12–13',
		),
	);
	return isset( $laws[ $kind ] ) ? $laws[ $kind ][ cmp_check_lang( $lang ) ] : '';
}

/** Title and explanation of a finding, with {placeholders} filled from $vars (plain text). */
function cmp_check_text( string $key, string $lang, array $vars = array() ): array {
	$t = array(
		'tracker'          => array(
			'ro' => array( '{name} pornește înainte de acord', 'Pagina pornește {name} imediat ce intră cineva, fără să-l întrebe. Un tracker are voie să pornească doar după ce vizitatorul spune „Da” în banner.' ),
			'en' => array( '{name} starts before consent', 'The page starts {name} as soon as someone arrives, without asking. A tracker may only start after the visitor says yes in the banner.' ),
			'ru' => array( '{name} запускается до согласия', 'Страница запускает {name}, как только кто-то на неё заходит, не спрашивая его. Трекер можно запускать только после того, как посетитель скажет «Да» в баннере.' ),
		),
		'embed'            => array(
			'ro' => array( '{name} se încarcă înainte de acord', 'Pagina încarcă {name} direct, așa că furnizorul primește IP-ul vizitatorului și poate pune cookie-uri înainte de acord. Pune în loc o imagine care încarcă {name} doar la clic.' ),
			'en' => array( '{name} loads before consent', 'The page loads {name} directly, so the provider gets the visitor\'s IP address and can set cookies before consent. Show a placeholder that loads {name} only on click.' ),
			'ru' => array( '{name} загружается до согласия', 'Страница загружает {name} напрямую, поэтому поставщик получает IP-адрес посетителя и может установить файлы cookie до согласия. Поставьте вместо него изображение, которое загружает {name} только по клику.' ),
		),
		'cookieless'       => array(
			'ro' => array( 'Folosești {name}', '{name} spune că nu folosește cookie-uri. Pomenește-l totuși în politica de confidențialitate.' ),
			'en' => array( 'You use {name}', '{name} says it uses no cookies. Mention it in your privacy policy anyway.' ),
			'ru' => array( 'Вы используете {name}', '{name} заявляет, что не использует файлы cookie. Всё равно упомяните его в политике конфиденциальности.' ),
		),
		'loader'           => array(
			'ro' => array( '{name} poate încărca trackere pe care nu le vedem', '{name} încarcă alte scripturi după ce pagina pornește, așa că de aici nu putem ști ce aduce. Verifică singur: deschide site-ul într-o fereastră privată, apoi în DevTools, la Network și la Application - Cookies, uită-te ce apare înainte să alegi ceva în banner.' ),
			'en' => array( '{name} may load trackers we cannot see', '{name} loads other scripts after the page starts, so we cannot tell from here what it brings. Check it yourself: open the site in a private window, then in DevTools, under Network and Application - Cookies, look at what shows up before you choose anything in the banner.' ),
			'ru' => array( '{name} может загружать трекеры, которых мы не видим', '{name} загружает другие скрипты после запуска страницы, поэтому отсюда мы не можем знать, что он приносит. Проверьте сами: откройте сайт в приватном окне, затем в DevTools, на вкладках Network и Application - Cookies, посмотрите, что появляется до того, как вы что-либо выберете в баннере.' ),
		),
		'resource'         => array(
			'ro' => array( '{name} trimite IP-ul vizitatorilor', 'Pagina încarcă {what} ({hosts}). Browserul fiecărui vizitator se conectează la acel server și îi trimite adresa IP, care e o dată personală, fără să fi fost întrebat. Găzduiește fișierele pe serverul tău sau renunță la ele.' ),
			'en' => array( '{name} sends your visitors\' IP address', 'The page loads {what} ({hosts}). Every visitor\'s browser connects to that server and sends it their IP address, which is personal data, without being asked. Host the files on your own server or drop them.' ),
			'ru' => array( '{name}: передача IP-адреса посетителей', 'Страница загружает {what} ({hosts}). Браузер каждого посетителя подключается к этому серверу и передаёт ему IP-адрес, а это персональные данные, хотя посетителя никто не спросил. Разместите файлы на своём сервере или откажитесь от них.' ),
		),
		'held-attribute'   => array(
			'ro' => array( '{name}: marcat pentru banner, dar tot JavaScript', 'Scriptul are marcajul unui banner de cookie-uri, dar tipul lui e tot JavaScript. E oprit doar dacă bannerul rulează în modul de blocare automată; altfel pornește înainte de acord. Verifică în DevTools, la Network, ce se încarcă înainte să alegi ceva în banner.' ),
			'en' => array( '{name}: marked for the banner, but still JavaScript', 'The script carries a cookie banner\'s marker, but its type is still JavaScript. It is held only if the banner runs in automatic blocking mode; otherwise it starts before consent. Check in DevTools, under Network, what loads before you choose anything in the banner.' ),
			'ru' => array( '{name}: помечен для баннера, но всё равно JavaScript', 'У скрипта есть пометка баннера cookie, но его тип по-прежнему JavaScript. Он остановлен, только если баннер работает в режиме автоматической блокировки; иначе он запускается до согласия. Проверьте в DevTools, на вкладке Network, что загружается до того, как вы что-либо выберете в баннере.' ),
		),
		'held-unknown'     => array(
			'ro' => array( '{name}: script cu un tip necunoscut', 'Scriptul are un tip pe care nu-l recunoaștem, așa că nu știm dacă pornește înainte de acord. Verifică în DevTools, la Network, ce se încarcă înainte să alegi ceva în banner.' ),
			'en' => array( '{name}: a script of an unknown type', 'The script has a type we do not recognise, so we cannot tell whether it starts before consent. Check in DevTools, under Network, what loads before you choose anything in the banner.' ),
			'ru' => array( '{name}: скрипт неизвестного типа', 'У скрипта тип, который мы не распознаём, поэтому мы не знаем, запускается ли он до согласия. Проверьте в DevTools, на вкладке Network, что загружается до того, как вы что-либо выберете в баннере.' ),
		),
		'consent-mode'     => array(
			'ro' => array( '{name} pornește în Consent Mode, înainte de acord', '{name} se încarcă cu acordul setat pe „refuzat”. Nu pune cookie-uri, dar trimite lui Google semnale fără cookie, cu IP-ul vizitatorului, înainte de acord. E o zonă gri: IP-ul e o dată personală, iar lectura sigură a legii e opt-in înainte de orice cookie neesențial.' ),
			'en' => array( '{name} runs in Consent Mode, before consent', '{name} loads with consent set to "denied". It sets no cookies, but it sends Google cookieless pings, with the visitor\'s IP address, before consent. This is a grey zone: the IP address is personal data, and the safe reading of the law is opt-in before any non-essential cookie.' ),
			'ru' => array( '{name} запускается в Consent Mode, до согласия', '{name} загружается с согласием в состоянии «отказ» (denied). Он не устанавливает файлы cookie, но до согласия отправляет Google сигналы без cookie, с IP-адресом посетителя. Это серая зона: IP-адрес относится к персональным данным, а безопасное прочтение закона: opt-in до любого необязательного cookie.' ),
		),
		'cookie'           => array(
			'ro' => array( 'Cookie-ul {cookie} ({name}) e pus înainte de acord', 'Serverul trimite cookie-ul {cookie}, al serviciului {name}, de la prima vizită. Cookie-urile de analiză sau de marketing au nevoie de acord înainte.' ),
			'en' => array( 'The {cookie} cookie ({name}) is set before consent', 'The server sends the {cookie} cookie, used by {name}, on the very first visit. Analytics and marketing cookies need consent first.' ),
			'ru' => array( 'Файл cookie {cookie} ({name}) устанавливается до согласия', 'Сервер отправляет cookie {cookie} сервиса {name} с первого визита. Аналитическим и маркетинговым cookie сначала нужно согласие.' ),
		),
		'cookies-other'    => array(
			'ro' => array( 'Serverul mai pune cookie-uri: {list}', 'Nu le recunoaștem. Dacă sunt strict necesare (sesiune, coș, securitate), nu au nevoie de acord. Dacă urmăresc vizitatorul, au nevoie.' ),
			'en' => array( 'The server sets other cookies too: {list}', 'We do not recognise them. If they are strictly necessary (session, cart, security), they need no consent. If they track the visitor, they do.' ),
			'ru' => array( 'Сервер устанавливает и другие файлы cookie: {list}', 'Мы их не распознаём. Если они строго необходимы (сессия, корзина, безопасность), согласие для них не нужно. Если они отслеживают посетителя, нужно.' ),
		),
		'policy'           => array(
			'ro' => array( 'Nu găsim un link spre politica de confidențialitate', 'Pagina are trackere, dar nu găsim pe ea un link spre politica de confidențialitate sau de cookie-uri. Vizitatorul trebuie să afle clar, dinainte, ce colectezi și de ce.' ),
			'en' => array( 'We cannot find a link to a privacy policy', 'The page has trackers, but we cannot find a link to a privacy or cookie policy on it. Visitors must be told clearly, beforehand, what you collect and why.' ),
			'ru' => array( 'Мы не находим ссылку на политику конфиденциальности', 'На странице есть трекеры, но мы не находим на ней ссылку на политику конфиденциальности или политику cookie. Посетитель должен заранее и ясно узнать, что вы собираете и зачем.' ),
		),
		'cmp'              => array(
			'ro' => array( 'Site-ul folosește {name}', 'Am găsit bannerul {name}. Un banner ajută doar dacă ține trackerele oprite până la acord.' ),
			'en' => array( 'The site uses {name}', 'We found the {name} banner. A banner only helps if it keeps trackers off until consent.' ),
			'ru' => array( 'Сайт использует {name}', 'Мы нашли баннер {name}. Баннер помогает, только если держит трекеры выключенными до согласия.' ),
		),
		'ours'             => array(
			'ro' => array( 'Folosești deja BiscuitWP', 'Pluginul nostru e pe pagină. Mulțumim că îl folosești!' ),
			'en' => array( 'You already use BiscuitWP', 'Our plugin is on the page. Thank you for using it!' ),
			'ru' => array( 'Вы уже используете BiscuitWP', 'Наш плагин есть на странице. Спасибо, что пользуетесь им!' ),
		),
		'ours-attention'   => array(
			'ro' => array( 'Folosești deja BiscuitWP', 'Pluginul nostru e pe pagină, dar ceva tot pornește înainte de acord. Verifică în setări blocarea scripturilor și lista de servicii, apoi actualizează pluginul la ultima versiune.' ),
			'en' => array( 'You already use BiscuitWP', 'Our plugin is on the page, but something still starts before consent. Check script blocking and the service list in its settings, then update the plugin to the latest version.' ),
			'ru' => array( 'Вы уже используете BiscuitWP', 'Наш плагин есть на странице, но что-то всё равно запускается до согласия. Проверьте в настройках блокировку скриптов и список сервисов, затем обновите плагин до последней версии.' ),
		),
		'empty'            => array(
			'ro' => array( 'Pagina e goală', 'Serverul a răspuns, dar fără conținut, așa că nu avem ce verifica.' ),
			'en' => array( 'The page is empty', 'The server answered, but with no content, so there is nothing to check.' ),
			'ru' => array( 'Страница пустая', 'Сервер ответил, но без содержимого, поэтому нам нечего проверять.' ),
		),
		'challenge'        => array(
			'ro' => array( 'Site-ul blochează verificările automate', 'Am primit o pagină de protecție anti-bot în locul site-ului. Verifică singur, din browser, cu DevTools.' ),
			'en' => array( 'The site blocks automated checks', 'We got an anti-bot protection page instead of the site. Check it yourself in your browser, with DevTools.' ),
			'ru' => array( 'Сайт блокирует автоматические проверки', 'Вместо сайта мы получили страницу защиты от ботов. Проверьте сами, в браузере, с помощью DevTools.' ),
		),
		'truncated'        => array(
			'ro' => array( 'Am verificat doar primii 2 MB ai paginii', 'Pagina e foarte mare. Ce e după primii 2 MB nu am citit.' ),
			'en' => array( 'We only checked the first 2 MB of the page', 'The page is very large. We did not read past the first 2 MB.' ),
			'ru' => array( 'Мы проверили только первые 2 МБ страницы', 'Страница очень большая. То, что идёт после первых 2 МБ, мы не читали.' ),
		),
		'partial'          => array(
			'ro' => array( 'Nu am putut citi toată pagina', 'Pagina are prea multe elemente ca s-o citim până la capăt. Ce e spre final nu am verificat.' ),
			'en' => array( 'We could not read the whole page', 'The page has too many elements to read to the end. We did not check what comes last.' ),
			'ru' => array( 'Мы не смогли прочитать всю страницу', 'На странице слишком много элементов, чтобы прочитать её до конца. То, что ближе к концу, мы не проверили.' ),
		),
		// No law: the legal doc (docs/product/2026-11-18-cadru-legal-moldova.md) says nothing about HTTPS or transport security.
		'transport'        => array(
			'ro' => array( 'Site-ul nu folosește HTTPS', 'Pagina s-a deschis prin http, fără criptare, așa că tot ce trimite vizitatorul, inclusiv formularele și cookie-urile, circulă necriptat. Sursele noastre legale nu numesc HTTPS, așa că nu dăm aici un articol de lege, dar e o măsură de bază de securitate.' ),
			'en' => array( 'The site does not use HTTPS', 'The page opened over http, without encryption, so everything the visitor sends, forms and cookies included, travels unencrypted. Our legal sources do not name HTTPS, so we cite no article of law here, but it is a basic security measure.' ),
			'ru' => array( 'Сайт не использует HTTPS', 'Страница открылась по http, без шифрования, поэтому всё, что отправляет посетитель, включая формы и файлы cookie, передаётся незашифрованным. Наши правовые источники не упоминают HTTPS, поэтому мы не приводим здесь статью закона, но это базовая мера безопасности.' ),
		),
	);
	$lang = cmp_check_lang( $lang );
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

/**
 * How to fix a finding: 'fix' (the generic action) and 'cmp_fix' (what
 * BiscuitWP does for it, only features the plugin has: README.md,
 * CHANGELOG.md 1.0.0). {placeholders} as in cmp_check_text().
 */
function cmp_check_fix( string $key, string $lang, array $vars = array() ): array {
	$check = array(
		'ro' => 'Ecranul Verificare din BiscuitWP testează în browserul tău ce se încarcă înainte și după acord.',
		'en' => 'The Check screen in BiscuitWP tests in your browser what loads before and after consent.',
		'ru' => 'Экран «Проверка» в BiscuitWP проверяет в вашем браузере, что загружается до и после согласия.',
	);
	$t     = array(
		'tracker'         => array(
			'ro' => array( 'Pornește {name} doar după ce vizitatorul acceptă în banner.', 'BiscuitWP recunoaște {name} și îl blochează până la acord, fără cod în plus.' ),
			'en' => array( 'Start {name} only after the visitor accepts in the banner.', 'BiscuitWP recognises {name} and blocks it until consent, with no extra code.' ),
			'ru' => array( 'Запускайте {name} только после того, как посетитель согласится в баннере.', 'BiscuitWP распознаёт {name} и блокирует его до согласия, без дополнительного кода.' ),
		),
		'tracker-custom'  => array(
			'ro' => array( 'Pornește {name} doar după ce vizitatorul acceptă în banner.', 'Adaugă {name} în BiscuitWP ca serviciu personalizat, cu domeniile lui, și pluginul îl blochează până la acord.' ),
			'en' => array( 'Start {name} only after the visitor accepts in the banner.', 'Add {name} to BiscuitWP as a custom service, with its hosts, and the plugin blocks it until consent.' ),
			'ru' => array( 'Запускайте {name} только после того, как посетитель согласится в баннере.', 'Добавьте {name} в BiscuitWP как пользовательский сервис, с его доменами, и плагин заблокирует его до согласия.' ),
		),
		'tracker-pixel'   => array(
			'ro' => array( 'Pornește pixelul {name} doar după ce vizitatorul acceptă în banner.', 'BiscuitWP nu blochează pixelii puși ca imagine. Mută pixelul în fragmentul JavaScript al serviciului, pe care pluginul îl blochează până la acord, sau afișează-l doar când cmp_is_allowed(\'{category}\') e adevărat.' ),
			'en' => array( 'Fire the {name} pixel only after the visitor accepts in the banner.', 'BiscuitWP does not block pixels added as images. Move the pixel into the service\'s JavaScript snippet, which the plugin blocks until consent, or output it only when cmp_is_allowed(\'{category}\') is true.' ),
			'ru' => array( 'Запускайте пиксель {name} только после того, как посетитель согласится в баннере.', 'BiscuitWP не блокирует пиксели, добавленные как изображение. Перенесите пиксель во фрагмент JavaScript сервиса, который плагин блокирует до согласия, или выводите его, только когда cmp_is_allowed(\'{category}\') возвращает true.' ),
		),
		'embed'           => array(
			'ro' => array( 'Înlocuiește {name} cu o previzualizare statică și încarcă playerul sau harta abia după ce vizitatorul acceptă.', 'BiscuitWP afișează în locul lui {name} un înlocuitor, care îl încarcă doar după acord.' ),
			'en' => array( 'Replace {name} with a static preview and load the player or map only once the visitor accepts.', 'BiscuitWP shows a placeholder instead of {name} and loads it only after consent.' ),
			'ru' => array( 'Замените {name} статичным превью и загружайте плеер или карту, только когда посетитель согласится.', 'BiscuitWP показывает вместо {name} заглушку, которая загружает его только после согласия.' ),
		),
		'cookieless'      => array(
			'ro' => array( 'Pomenește {name} în politica de confidențialitate.', 'BiscuitWP îl scutește implicit de acord, ca instrument fără cookie-uri, și îți generează politica de confidențialitate.' ),
			'en' => array( 'Mention {name} in your privacy policy.', 'BiscuitWP exempts it from consent by default, as a cookieless tool, and generates your privacy policy.' ),
			'ru' => array( 'Упомяните {name} в политике конфиденциальности.', 'BiscuitWP по умолчанию освобождает его от согласия как инструмент без cookie и создаёт для вас политику конфиденциальности.' ),
		),
		'loader-gtm'      => array(
			'ro' => array( 'În Google Tag Manager, pornește fiecare tag doar cu acord, prin Consent Mode.', 'BiscuitWP trimite semnalele Google Consent Mode v2 înainte ca GTM să pornească tagurile. Ce încarcă GTM controlezi din GTM.' ),
			'en' => array( 'In Google Tag Manager, fire each tag only with consent, through Consent Mode.', 'BiscuitWP sends the Google Consent Mode v2 signals before GTM fires its tags. What GTM loads, you control in GTM.' ),
			'ru' => array( 'В Google Tag Manager запускайте каждый тег только при согласии, через Consent Mode.', 'BiscuitWP передаёт сигналы Google Consent Mode v2 до того, как GTM запустит теги. Что загружает GTM, вы контролируете в GTM.' ),
		),
		'loader'          => array(
			'ro' => array( 'Află ce scripturi aduce {name} și pornește-le doar după acord.', $check['ro'] ),
			'en' => array( 'Find out which scripts {name} brings and start them only after consent.', $check['en'] ),
			'ru' => array( 'Выясните, какие скрипты приносит {name}, и запускайте их только после согласия.', $check['ru'] ),
		),
		'resource-google-fonts' => array(
			'ro' => array( 'Găzduiește fonturile pe serverul tău.', 'În modul Pro, BiscuitWP are o opțiune care descarcă o dată fonturile încărcate printr-un tag link și le servește de pe serverul tău. Fonturile aduse prin @import sau prin JavaScript trebuie mutate de mână.' ),
			'en' => array( 'Host the fonts on your own server.', 'In Pro mode, BiscuitWP has an option that downloads, once, the fonts loaded through a link tag and serves them from your own server. Fonts brought in by @import or by JavaScript have to be moved by hand.' ),
			'ru' => array( 'Разместите шрифты на своём сервере.', 'В режиме Pro в BiscuitWP есть опция, которая один раз скачивает шрифты, загружаемые через тег link, и отдаёт их с вашего сервера. Шрифты, подключённые через @import или через JavaScript, нужно перенести вручную.' ),
		),
		'resource-emoji'  => array(
			'ro' => array( 'Oprește emoji-urile WordPress sau servește-le de pe serverul tău.', 'BiscuitWP are un comutator care oprește emoji-urile WordPress.' ),
			'en' => array( 'Turn off WordPress emoji or serve them from your own server.', 'BiscuitWP has a switch that turns off WordPress emoji.' ),
			'ru' => array( 'Отключите эмодзи WordPress или отдавайте их со своего сервера.', 'В BiscuitWP есть переключатель, который отключает эмодзи WordPress.' ),
		),
		'resource-gravatar' => array(
			'ro' => array( 'Folosește avatare locale în locul Gravatar.', 'BiscuitWP are un comutator care înlocuiește Gravatar cu un avatar local.' ),
			'en' => array( 'Use local avatars instead of Gravatar.', 'BiscuitWP has a switch that replaces Gravatar with a local avatar.' ),
			'ru' => array( 'Используйте локальные аватары вместо Gravatar.', 'В BiscuitWP есть переключатель, который заменяет Gravatar локальным аватаром.' ),
		),
		'resource'        => array(
			'ro' => array( 'Găzduiește fișierele pe serverul tău sau renunță la ele.', 'Scanarea din BiscuitWP îți arată pe ce pagini se încarcă {what}, ca să știi ce muți pe serverul tău.' ),
			'en' => array( 'Host the files on your own server or drop them.', 'The BiscuitWP scan shows you which pages load {what}, so you know what to move to your own server.' ),
			'ru' => array( 'Разместите файлы на своём сервере или откажитесь от них.', 'Сканирование в BiscuitWP показывает, на каких страницах загружаются {what}, чтобы вы знали, что перенести на свой сервер.' ),
		),
		'held-attribute'  => array(
			'ro' => array( 'Fă scriptul inert până la acord (type="text/plain") sau pornește blocarea automată a bannerului.', 'BiscuitWP rescrie pe server scripturile cunoscute ca inerte până la acord, fără să depindă de un mod de blocare automată.' ),
			'en' => array( 'Make the script inert until consent (type="text/plain") or turn on the banner\'s automatic blocking.', 'BiscuitWP rewrites known scripts on the server as inert until consent, without relying on an automatic blocking mode.' ),
			'ru' => array( 'Сделайте скрипт неактивным до согласия (type="text/plain") или включите автоматическую блокировку баннера.', 'BiscuitWP на сервере переписывает известные скрипты в неактивные до согласия, не завися от режима автоматической блокировки.' ),
		),
		'held-unknown'    => array(
			'ro' => array( 'Verifică în DevTools ce se încarcă înainte de acord și ține trackerele oprite până atunci.', $check['ro'] ),
			'en' => array( 'Check in DevTools what loads before consent and keep trackers off until then.', $check['en'] ),
			'ru' => array( 'Проверьте в DevTools, что загружается до согласия, и держите трекеры выключенными до этого момента.', $check['ru'] ),
		),
		'consent-mode'    => array(
			'ro' => array( 'Pentru siguranță deplină, încarcă {name} doar după acord.', 'BiscuitWP are două moduri de măsurare: Strict încarcă Google Analytics doar după acord, Advanced îl pornește în Consent Mode.' ),
			'en' => array( 'To be fully safe, load {name} only after consent.', 'BiscuitWP has two measurement modes: Strict loads Google Analytics only after consent, Advanced starts it in Consent Mode.' ),
			'ru' => array( 'Для полной уверенности загружайте {name} только после согласия.', 'В BiscuitWP есть два режима измерения: Strict загружает Google Analytics только после согласия, Advanced запускает его в Consent Mode.' ),
		),
		'cookie'          => array(
			'ro' => array( 'Pune cookie-ul {cookie} doar după acord: pornește {name} abia după ce vizitatorul acceptă.', 'BiscuitWP blochează serviciile cunoscute până la acord. Dacă serverul tău pune cookie-ul, întreabă întâi pluginul, cu cmp_is_allowed().' ),
			'en' => array( 'Set the {cookie} cookie only after consent: start {name} only once the visitor accepts.', 'BiscuitWP blocks known services until consent. If your server sets the cookie, ask the plugin first, with cmp_is_allowed().' ),
			'ru' => array( 'Устанавливайте cookie {cookie} только после согласия: запускайте {name}, только когда посетитель согласится.', 'BiscuitWP блокирует известные сервисы до согласия. Если cookie устанавливает ваш сервер, сначала спросите плагин, через cmp_is_allowed().' ),
		),
		'cookies-other'   => array(
			'ro' => array( 'Află ce face fiecare cookie. Cele care urmăresc vizitatorul se pun doar după acord.', 'În BiscuitWP le poți trece în categorii, cu furnizor, durată și scop, iar ele apar în tabelul de cookie-uri.' ),
			'en' => array( 'Find out what each cookie does. The ones that track the visitor are set only after consent.', 'In BiscuitWP you can list them under categories, with provider, duration and purpose, and they show up in the cookie table.' ),
			'ru' => array( 'Выясните, что делает каждый cookie. Те, что отслеживают посетителя, устанавливаются только после согласия.', 'В BiscuitWP их можно распределить по категориям, с поставщиком, сроком и целью, и они появятся в таблице cookie.' ),
		),
		'policy'          => array(
			'ro' => array( 'Pune pe fiecare pagină, de obicei în subsol, un link spre politica de confidențialitate și de cookie-uri.', 'BiscuitWP generează politica de confidențialitate și pe cea de cookie-uri (RO, RU, EN), iar shortcode-ul [cmp_privacy_link] pune linkul.' ),
			'en' => array( 'Put a link to your privacy and cookie policy on every page, usually in the footer.', 'BiscuitWP generates the privacy and cookie policies (RO, RU, EN), and the [cmp_privacy_link] shortcode adds the link.' ),
			'ru' => array( 'Разместите на каждой странице, обычно в подвале, ссылку на политику конфиденциальности и политику cookie.', 'BiscuitWP создаёт политику конфиденциальности и политику cookie (RO, RU, EN), а шорткод [cmp_privacy_link] добавляет ссылку.' ),
		),
		'cmp'             => array(
			'ro' => array( 'Verifică dacă bannerul ține trackerele oprite până la acord.', 'BiscuitWP blochează scripturile și embed-urile cunoscute până la acord și e gratuit.' ),
			'en' => array( 'Check that the banner keeps trackers off until consent.', 'BiscuitWP blocks known scripts and embeds until consent, and it is free.' ),
			'ru' => array( 'Проверьте, держит ли баннер трекеры выключенными до согласия.', 'BiscuitWP блокирует известные скрипты и встраивания до согласия, и он бесплатный.' ),
		),
		'ours'            => array(
			'ro' => array( 'Ține pluginul la ultima versiune.', $check['ro'] ),
			'en' => array( 'Keep the plugin on the latest version.', $check['en'] ),
			'ru' => array( 'Держите плагин на последней версии.', $check['ru'] ),
		),
		'ours-attention'  => array(
			'ro' => array( 'Verifică în setări blocarea scripturilor și lista de servicii, apoi actualizează pluginul.', $check['ro'] ),
			'en' => array( 'Check script blocking and the service list in the settings, then update the plugin.', $check['en'] ),
			'ru' => array( 'Проверьте в настройках блокировку скриптов и список сервисов, затем обновите плагин.', $check['ru'] ),
		),
		'empty'           => array(
			'ro' => array( 'Verifică dacă adresa e corectă și dacă pagina se deschide în browser.', $check['ro'] ),
			'en' => array( 'Check that the address is right and that the page opens in a browser.', $check['en'] ),
			'ru' => array( 'Проверьте, правильный ли адрес и открывается ли страница в браузере.', $check['ru'] ),
		),
		'challenge'       => array(
			'ro' => array( 'Verifică singur, din browser, cu DevTools.', $check['ro'] ),
			'en' => array( 'Check it yourself in your browser, with DevTools.', $check['en'] ),
			'ru' => array( 'Проверьте сами, в браузере, с помощью DevTools.', $check['ru'] ),
		),
		'truncated'       => array(
			'ro' => array( 'Verifică restul paginii din browser, cu DevTools.', $check['ro'] ),
			'en' => array( 'Check the rest of the page in your browser, with DevTools.', $check['en'] ),
			'ru' => array( 'Проверьте остальную часть страницы в браузере, с помощью DevTools.', $check['ru'] ),
		),
		'partial'         => array(
			'ro' => array( 'Verifică restul paginii din browser, cu DevTools.', $check['ro'] ),
			'en' => array( 'Check the rest of the page in your browser, with DevTools.', $check['en'] ),
			'ru' => array( 'Проверьте остальную часть страницы в браузере, с помощью DevTools.', $check['ru'] ),
		),
		'unread'          => array(
			'ro' => array( 'Deschide paginile care nu au putut fi citite și verifică-le singur, din browser.', $check['ro'] ),
			'en' => array( 'Open the pages that could not be read and check them yourself in your browser.', $check['en'] ),
			'ru' => array( 'Откройте страницы, которые не удалось прочитать, и проверьте их сами в браузере.', $check['ru'] ),
		),
		'transport'       => array(
			'ro' => array( 'Instalează un certificat TLS (de exemplu Let\'s Encrypt) și trimite tot traficul pe https.', 'BiscuitWP nu configurează HTTPS: asta se face la găzduire.' ),
			'en' => array( 'Install a TLS certificate (for example Let\'s Encrypt) and send all traffic to https.', 'BiscuitWP does not set up HTTPS: that is done at your hosting.' ),
			'ru' => array( 'Установите сертификат TLS (например, Let\'s Encrypt) и переведите весь трафик на https.', 'BiscuitWP не настраивает HTTPS: это делается на хостинге.' ),
		),
	);
	$lang  = cmp_check_lang( $lang );
	$pair  = isset( $t[ $key ][ $lang ] ) ? $t[ $key ][ $lang ] : array( '', '' );
	$map   = array();
	foreach ( $vars as $k => $v ) {
		$map[ '{' . $k . '}' ] = cmp_check_plain( $v, 200 );
	}
	return array(
		'fix'     => strtr( $pair[0], $map ),
		'cmp_fix' => strtr( $pair[1], $map ),
	);
}

/** The six checks of the "passed" list, in display order: id => array( ro => title, en => title, ru => title ). */
function cmp_check_passed_titles(): array {
	return array(
		'no-trackers'        => array(
			'ro' => 'Niciun tracker activ înainte de acord',
			'en' => 'No tracker active before consent',
			'ru' => 'Ни одного активного трекера до согласия',
		),
		'no-tracker-cookies' => array(
			'ro' => 'Niciun cookie de tracker trimis de server',
			'en' => 'No tracker cookie sent by the server',
			'ru' => 'Сервер не отправляет cookie трекеров',
		),
		'no-ip-resources'    => array(
			'ro' => 'Nicio resursă care trimite IP-ul vizitatorilor',
			'en' => 'No resource that sends visitors\' IP address',
			'ru' => 'Нет ресурсов, передающих IP-адрес посетителей',
		),
		'banner'             => array(
			'ro' => 'Bannerul de cookie-uri e pe pagină',
			'en' => 'The cookie banner is on the page',
			'ru' => 'Баннер cookie есть на странице',
		),
		'policy'             => array(
			'ro' => 'Linkul spre politica de confidențialitate e pe pagină',
			'en' => 'The privacy policy link is on the page',
			'ru' => 'Ссылка на политику конфиденциальности есть на странице',
		),
		'https'              => array(
			'ro' => 'HTTPS',
			'en' => 'HTTPS',
			'ru' => 'HTTPS',
		),
	);
}

/** Passed ids as a list of array( id, title ), in display order. */
function cmp_check_passed_list( array $ids, string $lang ): array {
	$out  = array();
	$lang = cmp_check_lang( $lang );
	foreach ( cmp_check_passed_titles() as $id => $titles ) {
		if ( in_array( $id, $ids, true ) ) {
			$out[] = array(
				'id'    => $id,
				'title' => $titles[ $lang ],
			);
		}
	}
	return $out;
}

/**
 * Ids of the checks a page passes, from its findings (id => severity) and what
 * was found on it. A page read only in part ($complete false) never passes the
 * tracker and resource checks; an info finding (a cookieless tool) does not
 * fail the tracker check.
 */
function cmp_check_passed_ids( array $severity_by_id, bool $complete, bool $banner, bool $policy, bool $https ): array {
	$pass = array(
		'no-trackers'        => $complete,
		'no-tracker-cookies' => true,
		'no-ip-resources'    => $complete,
		'banner'             => $banner,
		'policy'             => $policy && ! isset( $severity_by_id['policy:missing'] ),
		'https'              => $https,
	);
	foreach ( $severity_by_id as $id => $sev ) {
		$id = (string) $id;
		if ( 'info' !== $sev && 1 === preg_match( '/^(?:tracker|loader|held):/', $id ) ) {
			$pass['no-trackers'] = false;
		}
		if ( 0 === strpos( $id, 'cookie:' ) ) {
			$pass['no-tracker-cookies'] = false;
		}
		if ( 0 === strpos( $id, 'resource:' ) ) {
			$pass['no-ip-resources'] = false;
		}
	}
	return array_keys( array_filter( $pass ) );
}

/**
 * Up to 3 'issue' ids, by the design's fixed order: marketing tracker (1),
 * analytics tracker (2), tracker cookie (3), IP-sending resource (4), missing
 * policy (5), anything else (6). Ties keep the findings' order.
 *
 * @param array $findings List of findings (id, severity...).
 * @param array $rank     id => rank, for the issues the analysis ranked.
 */
function cmp_check_top( array $findings, array $rank ): array {
	$list = array();
	foreach ( array_values( $findings ) as $i => $f ) {
		if ( 'issue' === $f['severity'] ) {
			$list[] = array( isset( $rank[ $f['id'] ] ) ? (int) $rank[ $f['id'] ] : 6, $i, $f['id'] );
		}
	}
	sort( $list );
	return array_slice( array_column( $list, 2 ), 0, 3 );
}

/** Name of a resource type: 'title' (start of a sentence) or 'what' (inside one). */
function cmp_check_resource_label( string $type, string $lang, string $form = 'title' ): string {
	// type => lang => array( title, what ).
	$labels = array(
		'google-fonts' => array(
			'ro' => array( 'Google Fonts', 'Google Fonts' ),
			'en' => array( 'Google Fonts', 'Google Fonts' ),
			'ru' => array( 'Google Fonts', 'Google Fonts' ),
		),
		'adobe-fonts'  => array(
			'ro' => array( 'Adobe Fonts', 'Adobe Fonts' ),
			'en' => array( 'Adobe Fonts', 'Adobe Fonts' ),
			'ru' => array( 'Adobe Fonts', 'Adobe Fonts' ),
		),
		'font-awesome' => array(
			'ro' => array( 'Font Awesome', 'Font Awesome' ),
			'en' => array( 'Font Awesome', 'Font Awesome' ),
			'ru' => array( 'Font Awesome', 'Font Awesome' ),
		),
		'cdn'          => array(
			'ro' => array( 'Un CDN public', 'fișiere de pe un CDN public' ),
			'en' => array( 'A public CDN', 'files from a public CDN' ),
			'ru' => array( 'Публичный CDN', 'файлы с публичного CDN' ),
		),
		'gravatar'     => array(
			'ro' => array( 'Gravatar', 'avatare Gravatar' ),
			'en' => array( 'Gravatar', 'Gravatar avatars' ),
			'ru' => array( 'Gravatar', 'аватары Gravatar' ),
		),
		'emoji'        => array(
			'ro' => array( 'Emoji WordPress', 'emoji-urile WordPress' ),
			'en' => array( 'WordPress emoji', 'WordPress emoji' ),
			'ru' => array( 'Эмодзи WordPress', 'эмодзи WordPress' ),
		),
	);
	if ( ! isset( $labels[ $type ] ) ) {
		return $type;
	}
	return $labels[ $type ][ cmp_check_lang( $lang ) ][ 'what' === $form ? 1 : 0 ];
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
 * Does the page come from WordPress? Only signs WordPress itself prints: its
 * folders (wp-content, wp-includes), the REST API link, the generator tag, the
 * emoji loader. A site that hides all of them reads as not WordPress, so the
 * report hedges ("nu pare").
 */
function cmp_check_is_wordpress( string $html ): bool {
	return 1 === preg_match( '#/wp-(?:content|includes)/|/wp-json/|<meta[^>]+content=["\']WordPress[\s"\']|_wpemojiSettings|wp-emoji-release#i', $html );
}

/**
 * Analyse a homepage fetched without cookies, as a new visitor sees it.
 *
 * @param string $html     The response body (only the first 2 MB are read).
 * @param array  $headers  Response headers: name => value|values, or "Name: value" lines.
 * @param string $url      The checked URL (own-site resources are not third-party).
 * @param array  $services The service library (cmp_check_load_services()).
 * @param string $lang     'ro' (default), 'en' or 'ru'.
 * @return array array(
 *               'status'   => clean|issues|unsure|error,
 *               'findings' => list of array( id, severity => issue|unsure|info, title, explain, law,
 *                             evidence => list of at most 3 plain strings (120 chars), fix, cmp_fix ),
 *               'cmp'      => string|null,
 *               'passed'   => list of array( id, title ) (cmp_check_passed_titles),
 *               'top'      => up to 3 issue ids (cmp_check_top),
 *               'rank'     => id => rank of each issue. Internal: only cmp_check_aggregate()
 *                             reads it and its output drops it; never send it to the client ).
 *               The first three keys and the first five finding keys are the v1 shape.
 */
function cmp_check_analyze( string $html, array $headers, string $url, array $services, string $lang = 'ro' ): array {
	$lang     = cmp_check_lang( $lang );
	$lib_ids  = array_flip( array_column( cmp_check_norm_services( $services ), 'id' ) );
	$services = cmp_check_merge_extra( cmp_check_norm_services( $services ), $lang );
	$findings = array();
	$evidence = array();
	$rank     = array();
	$ctx      = array(
		'complete' => true,
		'banner'   => false,
		'policy'   => false,
		'https'    => 1 === preg_match( '#^https://#i', trim( $url ) ),
		'wordpress' => false,
	);
	$add      = function ( $id, $severity, $text_key, array $vars, $law_kind, $fix_key = '' ) use ( &$findings, $lang ) {
		if ( isset( $findings[ $id ] ) ) {
			return;
		}
		$text            = cmp_check_text( $text_key, $lang, $vars );
		$fix             = cmp_check_fix( '' === $fix_key ? $text_key : $fix_key, $lang, $vars );
		$findings[ $id ] = array(
			'id'       => $id,
			'severity' => $severity,
			'title'    => $text['title'],
			'explain'  => $text['explain'],
			'law'      => '' === $law_kind ? '' : cmp_check_law( $law_kind, $lang ),
			'fix'      => $fix['fix'],
			'cmp_fix'  => $fix['cmp_fix'],
		);
	};
	// Evidence for a finding id: plain strings, distinct, at most 3.
	$ev     = function ( $id, $s ) use ( &$evidence ) {
		$s = cmp_check_plain( (string) $s, 120 );
		if ( '' === $s ) {
			return;
		}
		if ( ! isset( $evidence[ $id ] ) ) {
			$evidence[ $id ] = array();
		}
		if ( count( $evidence[ $id ] ) < 3 && ! in_array( $s, $evidence[ $id ], true ) ) {
			$evidence[ $id ][] = $s;
		}
	};
	$inlines = array(
		'ro' => 'script inline în pagină',
		'en' => 'inline script in the page',
		'ru' => 'встроенный скрипт на странице',
	);
	$inline  = $inlines[ $lang ];
	$finish = function ( $status, $cmp ) use ( &$findings, &$evidence, &$rank, &$ctx, $lang ) {
		$list = array();
		$sev  = array();
		foreach ( $findings as $f ) {
			$id     = cmp_check_plain( $f['id'], 100 );
			$list[] = array(
				'id'       => $id,
				'severity' => $f['severity'],
				'title'    => cmp_check_plain( $f['title'] ),
				'explain'  => cmp_check_plain( $f['explain'], 600 ),
				'law'      => cmp_check_plain( $f['law'] ),
				'evidence' => isset( $evidence[ $f['id'] ] ) ? $evidence[ $f['id'] ] : array(),
				'fix'      => cmp_check_plain( $f['fix'] ),
				'cmp_fix'  => cmp_check_plain( $f['cmp_fix'] ),
			);
			$sev[ $id ] = $f['severity'];
		}
		$ranks = array();
		foreach ( $rank as $id => $r ) {
			if ( isset( $sev[ $id ] ) && 'issue' === $sev[ $id ] ) {
				$ranks[ $id ] = $r;
			}
		}
		$status = null === $status ? cmp_check_status( $list ) : $status;
		return array(
			'status'   => $status,
			'findings' => $list,
			'cmp'      => null === $cmp ? null : cmp_check_plain( $cmp, 100 ),
			'passed'   => 'error' === $status ? array() : cmp_check_passed_list( cmp_check_passed_ids( $sev, $ctx['complete'], $ctx['banner'], $ctx['policy'], $ctx['https'] ), $lang ),
			'top'      => cmp_check_top( $list, $ranks ),
			'rank'     => $ranks,
			'wordpress' => $ctx['wordpress'],
		);
	};

	if ( '' === trim( $html ) ) {
		$add( 'page:empty', 'info', 'empty', array(), '' );
		return $finish( 'error', null );
	}
	$ctx['wordpress'] = cmp_check_is_wordpress( $html );
	if ( strlen( $html ) > CMP_CHECK_MAX_BYTES ) {
		$html = substr( $html, 0, CMP_CHECK_MAX_BYTES );
		$add( 'page:truncated', 'unsure', 'truncated', array(), '' );
		$ctx['complete'] = false;
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
	$service_finding = function ( array $s, $embed, $evid, $pixel = false ) use ( $add, $ev, &$trackers, &$denied, &$rank, $lib_ids ) {
		if ( 'necessary' === $s['category'] ) {
			return;
		}
		if ( $s['gtm'] ) {
			$add( 'loader:gtm', 'unsure', 'loader', array( 'name' => 'Google Tag Manager' ), 'consent', 'loader-gtm' );
			$ev( 'loader:gtm', $evid );
			return;
		}
		$id = 'tracker:' . cmp_check_service_slug( $s['id'] );
		if ( $s['exempt_default'] ) {
			$add( $id, 'info', 'cookieless', array( 'name' => $s['name'] ), '' );
			$ev( $id, $evid );
			return;
		}
		$trackers = true;
		if ( $denied && 'google-analytics' === $s['id'] ) {
			$add( $id . '-consent-mode', CMP_CHECK_CONSENT_MODE_SEVERITY, 'consent-mode', array( 'name' => $s['name'] ), 'consent' );
			$ev( $id . '-consent-mode', $evid );
			$rank[ $id . '-consent-mode' ] = 'marketing' === $s['category'] ? 1 : 2;
			return;
		}
		// The blocker rewrites <script> and <iframe> only: a pixel <img> is never blocked by the plugin.
		$fix_key = $embed ? 'embed' : ( $pixel ? 'tracker-pixel' : ( isset( $lib_ids[ $s['id'] ] ) ? 'tracker' : 'tracker-custom' ) );
		$add(
			$id,
			'issue',
			$embed ? 'embed' : 'tracker',
			array(
				'name'     => $s['name'],
				'category' => 'statistics' === $s['category'] ? 'statistics' : 'marketing',
			),
			'consent',
			$fix_key
		);
		$ev( $id, $evid );
		$rank[ $id ] = 'marketing' === $s['category'] ? 1 : 2;
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
	// Report a live hit (a loader or a service) with its evidence.
	$apply_hit = function ( array $hit, $where ) use ( $add, $ev, $service_finding ) {
		if ( isset( $hit['loader'] ) ) {
			$id = 'loader:' . $hit['loader']['slug'];
			$add( $id, 'unsure', 'loader', array( 'name' => $hit['loader']['name'] ), 'consent', 'gtm' === $hit['loader']['slug'] ? 'loader-gtm' : 'loader' );
			$ev( $id, $where );
		} else {
			$service_finding( $hit['service'], false, $where );
		}
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
		$hits  = $hits_of( $src, $body );
		$where = '' !== $src ? cmp_check_evidence_url( $src ) : $inline;
		if ( 'unknown' === $kind || $marked ) {
			if ( 'live' === $kind && $auto_blocking ) {
				continue;
			}
			foreach ( $hits as $hit ) {
				$name = $hit_name( $hit );
				if ( '' !== $name ) {
					if ( 'unknown' === $kind ) {
						$held_type[ $name ] = true;
						$ev( 'held:unknown-type', $where );
					} else {
						$held_attr[ $name ] = true;
						$ev( 'held:attribute', $where );
					}
				}
			}
			continue;
		}
		foreach ( $hits as $hit ) {
			$apply_hit( $hit, $where );
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
				$ev( 'held:attribute', cmp_check_evidence_url( $src ) );
			}
			continue;
		}
		if ( null !== $s ) {
			$service_finding( $s, 'embed' === $s['type'], cmp_check_evidence_url( $src ) );
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
			$apply_hit( $hit, cmp_check_evidence_url( $href ) );
		}
	}
	// Tracking pixels (<noscript> ones are skipped: they only fire without JavaScript).
	foreach ( array_keys( $parsed['imgs'] ) as $src ) {
		$s = cmp_check_service_for_url( (string) $src, $services, array( 'script' ) );
		if ( null !== $s ) {
			$service_finding( $s, false, cmp_check_evidence_url( (string) $src ), true );
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
			'transfer',
			in_array( $type, array( 'google-fonts', 'emoji', 'gravatar' ), true ) ? 'resource-' . $type : 'resource'
		);
		foreach ( array_slice( $hosts, 0, 3 ) as $h ) {
			$ev( 'resource:' . $type, $h );
		}
		$rank[ 'resource:' . $type ] = 4;
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
		$ctx['complete'] = false;
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
			$ev( 'cookie:' . $name, $name );
			$rank[ 'cookie:' . $name ] = 3;
		}
	}
	if ( array() !== $other ) {
		$add( 'cookies:other', 'info', 'cookies-other', array( 'list' => implode( ', ', array_slice( $other, 0, 5 ) ) . ( count( $other ) > 5 ? '...' : '' ) ), '' );
		foreach ( array_slice( $other, 0, 3 ) as $name ) {
			$ev( 'cookies:other', $name );
		}
	}

	$ctx['policy'] = cmp_check_has_policy_link( $parsed );
	if ( $trackers && ! $ctx['policy'] ) {
		$add( 'policy:missing', 'issue', 'policy', array(), 'inform' );
		$rank['policy:missing'] = 5;
	}

	$cmp = cmp_check_detect_cmp( $html, $parsed, $url );
	if ( null !== $cmp ) {
		$ctx['banner'] = true;
		if ( 'consent-manager-pro' === $cmp['slug'] ) {
			$attention = 'clean' !== cmp_check_status( array_values( $findings ) );
			$add( 'cmp:consent-manager-pro', 'info', $attention ? 'ours-attention' : 'ours', array(), '' );
		} else {
			$add( 'cmp:' . $cmp['slug'], 'info', 'cmp', array( 'name' => $cmp['name'] ), '' );
		}
	}
	// After the CMP finding: plain http alone does not mean something starts before consent.
	if ( 1 === preg_match( '#^http://#i', trim( $url ) ) ) {
		$add( 'transport:http', 'unsure', 'transport', array(), '' );
		$host = @parse_url( trim( $url ), PHP_URL_HOST ); // phpcs:ignore
		$host = is_string( $host ) ? strtolower( $host ) : '';
		if ( 1 === preg_match( '/^[a-z0-9.-]{1,253}$/', $host ) ) {
			$ev( 'transport:http', 'http://' . $host );
		}
	}
	return $finish( null, null === $cmp ? null : $cmp['name'] );
}

/* ------------------------------------------------------------------
 * Several pages: one report.
 * ------------------------------------------------------------------ */

/**
 * Merge the results of several pages of one site into one report.
 *
 * @param array  $page_results List of cmp_check_analyze() results, each with
 *                             'url' (the page). The first one is the homepage.
 * @param string $lang         'ro' (default), 'en' or 'ru', as the page results.
 * @param int    $unread       Pages that could not be fetched at all (not in $page_results).
 * @return array array(
 *               'status'   => the cmp_check_status() rules over every finding of
 *                             every page, never better than the worst page status;
 *                             'error' when no page could be checked. A page in error
 *                             or $unread > 0 adds an unsure 'pages:unread' finding,
 *                             so the status is never 'clean' then,
 *               'findings' => one per id: array( id, severity, title, explain, law,
 *                             evidence (union, at most 3), pages (urls where seen,
 *                             homepage first), fix, cmp_fix ); the highest severity
 *                             seen wins, with that page's texts,
 *               'cmp'      => the homepage's CMP, else the first one found,
 *               'passed'   => checks passed on every checked page; 'banner' and
 *                             'policy' as found on the homepage (and no
 *                             policy:missing anywhere),
 *               'top'      => cmp_check_top() over the merged findings,
 *               'engine'   => 'html-v2' ).
 */
function cmp_check_aggregate( array $page_results, string $lang, int $unread = 0 ): array {
	$lang      = cmp_check_lang( $lang );
	$level     = array(
		'info'   => 0,
		'unsure' => 1,
		'issue'  => 2,
	);
	$known     = array_keys( cmp_check_passed_titles() );
	$merged    = array();
	$rank      = array();
	$cmp       = null;
	$checked   = 0;
	$every     = null; // Passed ids common to every checked page.
	$home_pass = array();
	$errors    = 0;
	$worst     = 'clean'; // Worst page status among the checked pages.
	$wordpress = false; // Any page that shows WordPress.
	foreach ( array_values( $page_results ) as $i => $p ) {
		if ( ! is_array( $p ) ) {
			$errors++;
			continue;
		}
		$url    = isset( $p['url'] ) && is_string( $p['url'] ) ? cmp_check_plain( $p['url'], 2048 ) : '';
		$status = isset( $p['status'] ) && is_string( $p['status'] ) ? $p['status'] : 'error';
		if ( isset( $p['wordpress'] ) && true === $p['wordpress'] ) {
			$wordpress = true;
		}
		if ( null === $cmp && isset( $p['cmp'] ) && is_string( $p['cmp'] ) && '' !== $p['cmp'] ) {
			$cmp = cmp_check_plain( $p['cmp'], 100 ); // Index 0 first: the homepage wins.
		}
		$ids = array();
		foreach ( isset( $p['passed'] ) && is_array( $p['passed'] ) ? $p['passed'] : array() as $x ) {
			if ( is_array( $x ) && isset( $x['id'] ) && in_array( $x['id'], $known, true ) ) {
				$ids[] = $x['id'];
			}
		}
		if ( ! in_array( $status, array( 'clean', 'issues', 'unsure' ), true ) ) {
			$errors++;
		} elseif ( 'issues' === $status || ( 'unsure' === $status && 'clean' === $worst ) ) {
			$worst = $status;
		}
		if ( in_array( $status, array( 'clean', 'issues', 'unsure' ), true ) ) {
			$checked++;
			$every = null === $every ? $ids : array_values( array_intersect( $every, $ids ) );
			if ( 0 === $i ) {
				$home_pass = $ids;
			}
		}
		foreach ( isset( $p['findings'] ) && is_array( $p['findings'] ) ? $p['findings'] : array() as $f ) {
			if ( ! is_array( $f ) || ! isset( $f['id'], $f['severity'] ) || ! is_string( $f['id'] ) || ! isset( $level[ $f['severity'] ] ) ) {
				continue;
			}
			$id   = cmp_check_plain( $f['id'], 100 );
			$text = array();
			foreach ( array( 'title', 'explain', 'law', 'fix', 'cmp_fix' ) as $k ) {
				$text[ $k ] = isset( $f[ $k ] ) && is_string( $f[ $k ] ) ? cmp_check_plain( $f[ $k ], 'explain' === $k ? 600 : 400 ) : '';
			}
			if ( ! isset( $merged[ $id ] ) ) {
				$merged[ $id ] = array(
					'id'       => $id,
					'severity' => $f['severity'],
					'title'    => $text['title'],
					'explain'  => $text['explain'],
					'law'      => $text['law'],
					'evidence' => array(),
					'pages'    => array(),
					'fix'      => $text['fix'],
					'cmp_fix'  => $text['cmp_fix'],
				);
			} elseif ( $level[ $f['severity'] ] > $level[ $merged[ $id ]['severity'] ] ) {
				$merged[ $id ] = array_merge( $merged[ $id ], $text, array( 'severity' => $f['severity'] ) );
			}
			if ( '' !== $url && ! in_array( $url, $merged[ $id ]['pages'], true ) ) {
				$merged[ $id ]['pages'][] = $url;
			}
			foreach ( isset( $f['evidence'] ) && is_array( $f['evidence'] ) ? $f['evidence'] : array() as $e ) {
				$e = is_string( $e ) ? cmp_check_plain( $e, 120 ) : '';
				if ( '' !== $e && count( $merged[ $id ]['evidence'] ) < 3 && ! in_array( $e, $merged[ $id ]['evidence'], true ) ) {
					$merged[ $id ]['evidence'][] = $e;
				}
			}
		}
		foreach ( isset( $p['rank'] ) && is_array( $p['rank'] ) ? $p['rank'] : array() as $id => $r ) {
			$id          = (string) $id;
			$rank[ $id ] = isset( $rank[ $id ] ) ? min( $rank[ $id ], (int) $r ) : (int) $r;
		}
	}
	$missed = $errors + max( 0, $unread );
	if ( $checked > 0 && $missed > 0 ) {
		$one              = 1 === $missed;
		$titles           = array(
			'ro' => $missed . ( $one ? ' pagină nu a putut fi citită, așa că nu putem spune că totul e în regulă' : ' pagini nu au putut fi citite, așa că nu putem spune că totul e în regulă' ),
			'en' => $missed . ( $one ? ' page could not be read, so we cannot say everything is fine' : ' pages could not be read, so we cannot say everything is fine' ),
			'ru' => $missed . ' ' . cmp_check_ru_plural( $missed, 'страницу', 'страницы', 'страниц' ) . ' не удалось прочитать, поэтому мы не можем сказать, что всё в порядке',
		);
		$explains         = array(
			'ro' => 'Am verificat celelalte pagini, dar ce e pe paginile pe care nu le-am putut citi nu apare în raport.',
			'en' => 'We checked the other pages, but whatever is on the pages we could not read is not in this report.',
			'ru' => 'Остальные страницы мы проверили, но то, что есть на страницах, которые мы не смогли прочитать, в отчёт не попало.',
		);
		$title            = $titles[ $lang ];
		$fix              = cmp_check_fix( 'unread', $lang );
		$merged['pages:unread'] = array(
			'id'       => 'pages:unread',
			'severity' => 'unsure',
			'title'    => $title,
			'explain'  => $explains[ $lang ],
			'law'      => '',
			'evidence' => array(),
			'pages'    => array(),
			'fix'      => $fix['fix'],
			'cmp_fix'  => $fix['cmp_fix'],
		);
	}
	$list = array_values( $merged );

	// Our plugin: the attention text when anything but plain http or unread pages is wrong on any page.
	if ( isset( $merged['cmp:consent-manager-pro'] ) ) {
		$rest = array_filter(
			$list,
			function ( $f ) {
				return 'transport:http' !== $f['id'] && 'pages:unread' !== $f['id'];
			}
		);
		if ( 'clean' !== cmp_check_status( $rest ) ) {
			$text = cmp_check_text( 'ours-attention', $lang );
			$fix  = cmp_check_fix( 'ours-attention', $lang );
			foreach ( $list as $i => $f ) {
				if ( 'cmp:consent-manager-pro' === $f['id'] ) {
					$list[ $i ] = array_merge( $f, $text, $fix );
				}
			}
		}
	}

	$passed = array();
	if ( $checked > 0 ) {
		$passed = array_values( array_intersect( (array) $every, array( 'no-trackers', 'no-tracker-cookies', 'no-ip-resources', 'https' ) ) );
		if ( in_array( 'banner', $home_pass, true ) ) {
			$passed[] = 'banner';
		}
		if ( in_array( 'policy', $home_pass, true ) && ! isset( $merged['policy:missing'] ) ) {
			$passed[] = 'policy';
		}
	}
	$status = cmp_check_status( $list );
	if ( 'issues' === $worst || ( 'unsure' === $worst && 'clean' === $status ) ) {
		$status = $worst; // Floor: findings dropped as malformed never make a page better.
	}
	return array(
		'status'   => 0 === $checked ? 'error' : $status,
		'findings' => $list,
		'cmp'      => $cmp,
		'passed'   => cmp_check_passed_list( $passed, $lang ),
		'top'      => cmp_check_top( $list, $rank ),
		'engine'   => 'html-v2',
		'wordpress' => $wordpress,
	);
}

/**
 * „Ce găsim cel mai des”: the kinds of problem counted per check, in display
 * order for equal counts.
 */
function cmp_check_found_kind_ids(): array {
	return array( 'tracker', 'google-fonts', 'ip-resources', 'no-policy', 'no-banner' );
}

/**
 * The kinds a check result shows (each at most once), from its issue findings:
 * a tracker or tracker cookie before consent; Google Fonts; another resource
 * that sends the IP; no privacy policy link; trackers with no CMP at all.
 *
 * @param array $result A check result (cmp_check_aggregate() / the endpoint body).
 * @return array Kind ids, in cmp_check_found_kind_ids() order.
 */
function cmp_check_found_kinds( array $result ): array {
	$on       = array();
	$findings = isset( $result['findings'] ) && is_array( $result['findings'] ) ? $result['findings'] : array();
	foreach ( $findings as $f ) {
		if ( ! is_array( $f ) || ! isset( $f['id'], $f['severity'] ) || 'issue' !== $f['severity'] || ! is_string( $f['id'] ) ) {
			continue;
		}
		$id = $f['id'];
		if ( 0 === strpos( $id, 'tracker:' ) || 0 === strpos( $id, 'cookie:' ) ) {
			$on['tracker'] = true;
		} elseif ( 'resource:google-fonts' === $id ) {
			$on['google-fonts'] = true;
		} elseif ( 0 === strpos( $id, 'resource:' ) ) {
			$on['ip-resources'] = true;
		} elseif ( 'policy:missing' === $id ) {
			$on['no-policy'] = true;
		}
	}
	$cmp = isset( $result['cmp'] ) && is_string( $result['cmp'] ) && '' !== $result['cmp'];
	if ( isset( $on['tracker'] ) && ! $cmp ) {
		$on['no-banner'] = true;
	}
	return array_values( array_filter( cmp_check_found_kind_ids(), function ( $k ) use ( $on ) {
		return isset( $on[ $k ] );
	} ) );
}
