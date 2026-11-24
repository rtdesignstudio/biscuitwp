<?php
if ( ! defined( 'CMP_CHECK' ) ) {
	http_response_code( 404 );
	exit;
}

/**
 * BiscuitWP, "Verifică-ți site-ul": fetching a visitor-given URL
 * without letting it reach anything but the public internet (SSRF).
 *
 * - Only http/https, only ports 80 and 443, no user:password@, no IP literals
 *   in decimal / octal / hex / short forms, no single-label or local names.
 * - The host is resolved here; every address (A and AAAA) must be public
 *   (cmp_check_ip_allowed), else the URL is refused. The connection is pinned
 *   to the first checked address with CURLOPT_RESOLVE, so curl never resolves
 *   the name again (no DNS rebinding window), and the address curl actually
 *   used is compared with it afterwards.
 * - Redirects are followed by hand, at most 3, each one validated again from
 *   scratch (URL, DNS, addresses, port).
 * - 2 MB body cap, no compression (Accept-Encoding: identity; an encoded
 *   answer is refused), 10 seconds in all, no cookies, no proxy, TLS
 *   verification on.
 * - A multi-page check (cmp_check_fetch_many, curl_multi, 3 at a time) uses
 *   the same handle setup and the same per-hop rules for every page: one
 *   resolution per host for the whole check (shared cache, addresses checked
 *   again on every use), each handle pinned with CURLOPT_RESOLVE and its
 *   PRIMARY_IP compared, and redirects kept on the site's own hosts (another
 *   host: 'other_host', not followed). robots.txt and sitemaps go through
 *   cmp_check_fetch() with the same options and a 1 MB cap.
 *
 * The allowed ports, the resolver and the loopback exception can be changed
 * only through function parameters, for the tests; the endpoint passes none.
 * Nothing here writes files or logs, and the URL is never logged.
 * PHP 7.4+, needs the curl extension.
 */

/** User-agent sent with every request. */
function cmp_check_user_agent(): string {
	return 'BiscuitWP-Check/1.0 (+https://biscuitwp.rt.md/)';
}

/** Hard limits of a fetch. */
function cmp_check_fetch_limits(): array {
	return array(
		'max_bytes'     => 2097152, // 2 MB of body (never decompressed).
		'max_redirects' => 3,
		'total_seconds' => 10,
		'connect'       => 5,
		'max_url'       => 2048,
		'max_header'    => 65536,
		'max_addresses' => 32,
	);
}

/* ------------------------------------------------------------------
 * Addresses.
 * ------------------------------------------------------------------ */

/** Is the packed address $bin inside $cidr ("a.b.c.d/n" or "x::/n")? */
function cmp_check_in_cidr( string $bin, string $cidr ): bool {
	list( $net, $bits ) = explode( '/', $cidr );
	$net                = inet_pton( $net );
	$bits               = (int) $bits;
	if ( false === $net || strlen( $net ) !== strlen( $bin ) ) {
		return false;
	}
	$bytes = intdiv( $bits, 8 );
	if ( $bytes > 0 && substr( $bin, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
		return false;
	}
	$rest = $bits % 8;
	if ( 0 === $rest ) {
		return true;
	}
	$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
	return ( ord( $bin[ $bytes ] ) & $mask ) === ( ord( $net[ $bytes ] ) & $mask );
}

/** IPv4 ranges that are never fetched. */
function cmp_check_blocked_v4(): array {
	return array(
		'0.0.0.0/8',          // "This network".
		'10.0.0.0/8',         // Private.
		'100.64.0.0/10',      // CGNAT.
		'127.0.0.0/8',        // Loopback.
		'169.254.0.0/16',     // Link-local, cloud metadata.
		'172.16.0.0/12',      // Private.
		'192.0.0.0/24',       // IETF protocol assignments.
		'192.0.2.0/24',       // TEST-NET-1.
		'192.88.99.0/24',     // 6to4 relay anycast (deprecated).
		'192.168.0.0/16',     // Private.
		'198.18.0.0/15',      // Benchmarking.
		'198.51.100.0/24',    // TEST-NET-2.
		'203.0.113.0/24',     // TEST-NET-3.
		'224.0.0.0/4',        // Multicast.
		'240.0.0.0/4',        // Reserved, includes 255.255.255.255.
	);
}

/** IPv6 ranges that are never fetched (beyond "must be in 2000::/3"). */
function cmp_check_blocked_v6(): array {
	return array(
		'::/96',              // Unspecified, loopback, IPv4-compatible (deprecated).
		'::ffff:0:0/96',      // IPv4-mapped (checked through the embedded IPv4 before this list).
		'64:ff9b:1::/48',     // Local-use NAT64.
		'100::/64',           // Discard-only.
		'2001::/23',          // IETF protocol assignments: Teredo, ORCHID, benchmarking...
		'2001:db8::/32',      // Documentation.
		'3fff::/20',          // Documentation (RFC 9637).
		'fc00::/7',           // Unique local.
		'fe80::/10',          // Link-local.
		'fec0::/10',          // Site-local (deprecated).
		'ff00::/8',           // Multicast.
	);
}

/** Is a packed IPv4 address public? */
function cmp_check_v4_bin_allowed( string $bin ): bool {
	if ( 4 !== strlen( $bin ) ) {
		return false;
	}
	foreach ( cmp_check_blocked_v4() as $cidr ) {
		if ( cmp_check_in_cidr( $bin, $cidr ) ) {
			return false;
		}
	}
	return true;
}

/**
 * May we connect to this address? Only clean textual IPv4 / IPv6 addresses of
 * the public internet. IPv6 that embeds an IPv4 (mapped ::ffff:0:0/96, NAT64
 * 64:ff9b::/96, 6to4 2002::/16) is judged by the embedded IPv4; every other
 * IPv6 must be global unicast (2000::/3) and outside the blocked ranges.
 */
function cmp_check_ip_allowed( string $ip ): bool {
	if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return false;
	}
	$bin = @inet_pton( $ip ); // phpcs:ignore
	if ( false === $bin ) {
		return false;
	}
	if ( 4 === strlen( $bin ) ) {
		// Refuse forms that some parsers read differently (leading zeros).
		return inet_ntop( $bin ) === $ip && cmp_check_v4_bin_allowed( $bin );
	}
	if ( 16 !== strlen( $bin ) ) {
		return false;
	}
	if ( cmp_check_in_cidr( $bin, '::ffff:0:0/96' ) || cmp_check_in_cidr( $bin, '64:ff9b::/96' ) ) {
		return cmp_check_v4_bin_allowed( substr( $bin, 12, 4 ) );
	}
	if ( cmp_check_in_cidr( $bin, '2002::/16' ) ) {
		return cmp_check_v4_bin_allowed( substr( $bin, 2, 4 ) );
	}
	if ( ! cmp_check_in_cidr( $bin, '2000::/3' ) ) {
		return false;
	}
	foreach ( cmp_check_blocked_v6() as $cidr ) {
		if ( cmp_check_in_cidr( $bin, $cidr ) ) {
			return false;
		}
	}
	return true;
}

/**
 * The addresses of a host name (A and AAAA), as text. Empty when it does not
 * resolve. CNAME chains are followed by the system resolver.
 */
function cmp_check_resolve( string $host ): array {
	$ips = array();
	$v4  = @gethostbynamel( $host ); // phpcs:ignore
	if ( is_array( $v4 ) ) {
		foreach ( $v4 as $ip ) {
			$ips[] = (string) $ip;
		}
	}
	if ( function_exists( 'dns_get_record' ) && defined( 'DNS_AAAA' ) ) {
		$v6 = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore
		if ( is_array( $v6 ) ) {
			foreach ( $v6 as $rec ) {
				if ( isset( $rec['type'], $rec['ipv6'] ) && 'AAAA' === $rec['type'] ) {
					$ips[] = (string) $rec['ipv6'];
				}
			}
		}
	}
	return array_values( array_unique( $ips ) );
}

/* ------------------------------------------------------------------
 * URLs.
 * ------------------------------------------------------------------ */

/** Punycode (RFC 3492) of one label, "xn--..." when it is not ASCII. Used when intl is missing. */
function cmp_check_punycode_label( string $label ): string {
	$label = function_exists( 'mb_strtolower' ) ? mb_strtolower( $label, 'UTF-8' ) : strtolower( $label );
	if ( 1 !== preg_match( '/[^\x00-\x7F]/', $label ) ) {
		return $label;
	}
	if ( ! preg_match_all( '/./su', $label, $m ) ) {
		return '';
	}
	$cps = array();
	foreach ( $m[0] as $ch ) {
		$b = array_values( unpack( 'C*', $ch ) );
		$n = count( $b );
		if ( 1 === $n ) {
			$cps[] = $b[0];
		} elseif ( 2 === $n ) {
			$cps[] = ( ( $b[0] & 0x1F ) << 6 ) | ( $b[1] & 0x3F );
		} elseif ( 3 === $n ) {
			$cps[] = ( ( $b[0] & 0x0F ) << 12 ) | ( ( $b[1] & 0x3F ) << 6 ) | ( $b[2] & 0x3F );
		} else {
			$cps[] = ( ( $b[0] & 0x07 ) << 18 ) | ( ( $b[1] & 0x3F ) << 12 ) | ( ( $b[2] & 0x3F ) << 6 ) | ( $b[3] & 0x3F );
		}
	}
	$base  = 36;
	$tmin  = 1;
	$tmax  = 26;
	$adapt = function ( $delta, $points, $first ) use ( $base, $tmin, $tmax ) {
		$delta  = $first ? intdiv( $delta, 700 ) : intdiv( $delta, 2 );
		$delta += intdiv( $delta, $points );
		$k      = 0;
		while ( $delta > intdiv( ( $base - $tmin ) * $tmax, 2 ) ) {
			$delta = intdiv( $delta, $base - $tmin );
			$k    += $base;
		}
		return $k + intdiv( ( $base - $tmin + 1 ) * $delta, $delta + 38 );
	};
	$digit = function ( $d ) {
		return chr( $d < 26 ? 97 + $d : 22 + $d );
	};
	$out = '';
	foreach ( $cps as $c ) {
		if ( $c < 0x80 ) {
			$out .= chr( $c );
		}
	}
	$b = strlen( $out );
	$h = $b;
	if ( $b > 0 ) {
		$out .= '-';
	}
	$n     = 128;
	$delta = 0;
	$bias  = 72;
	$total = count( $cps );
	while ( $h < $total ) {
		$mm = PHP_INT_MAX;
		foreach ( $cps as $c ) {
			if ( $c >= $n && $c < $mm ) {
				$mm = $c;
			}
		}
		$delta += ( $mm - $n ) * ( $h + 1 );
		$n      = $mm;
		foreach ( $cps as $c ) {
			if ( $c < $n ) {
				$delta++;
			}
			if ( $c === $n ) {
				$q = $delta;
				for ( $k = $base; ; $k += $base ) {
					$t = $k <= $bias ? $tmin : ( $k >= $bias + $tmax ? $tmax : $k - $bias );
					if ( $q < $t ) {
						break;
					}
					$out .= $digit( $t + ( $q - $t ) % ( $base - $t ) );
					$q    = intdiv( $q - $t, $base - $t );
				}
				$out  .= $digit( $q );
				$bias  = $adapt( $delta, $h + 1, $h === $b );
				$delta = 0;
				$h++;
			}
		}
		$delta++;
		$n++;
	}
	return 'xn--' . $out;
}

/** ASCII form of a host name (IDN to punycode), or false. */
function cmp_check_idn_host( string $host ) {
	if ( 1 !== preg_match( '/[^\x00-\x7F]/', $host ) ) {
		return strtolower( $host );
	}
	if ( 1 !== preg_match( '//u', $host ) ) {
		return false;
	}
	if ( function_exists( 'idn_to_ascii' ) && defined( 'INTL_IDNA_VARIANT_UTS46' ) ) {
		$a = @idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 ); // phpcs:ignore
		return is_string( $a ) && '' !== $a ? strtolower( $a ) : false;
	}
	$host   = str_replace( array( "\xE3\x80\x82", "\xEF\xBC\x8E", "\xEF\xBD\xA1" ), '.', $host );
	$labels = array();
	foreach ( explode( '.', $host ) as $l ) {
		$labels[] = cmp_check_punycode_label( $l );
	}
	return implode( '.', $labels );
}

/** Names that are never public: single labels and local-only suffixes. */
function cmp_check_local_name( string $host ): bool {
	if ( false === strpos( $host, '.' ) ) {
		return true;
	}
	foreach ( array( 'localhost', 'local', 'localdomain', 'internal', 'intranet', 'lan', 'home', 'corp', 'private', 'arpa', 'onion' ) as $tld ) {
		if ( $host === $tld || substr( $host, -( strlen( $tld ) + 1 ) ) === '.' . $tld ) {
			return true;
		}
	}
	return false;
}

/**
 * A visitor-given address as a clean absolute URL, or false.
 *
 * Accepts "exemplu.md" (https:// is added), http:// and https:// URLs.
 * Refuses other schemes, user:password@, ports outside $ports (80 and 443),
 * IP literals in any form but canonical dotted IPv4 / bracketed IPv6,
 * single-label and local names, control characters, over 2048 characters.
 * The result has a lowercase scheme and host (IDN as punycode), no default
 * port, no fragment, and a path / query with unsafe bytes percent-encoded.
 * Whether the host's addresses are public is checked by cmp_check_fetch().
 *
 * @param string     $in    The address.
 * @param array|null $ports Allowed ports (tests only); default 80 and 443.
 * @return string|false
 */
function cmp_check_normalize_url( string $in, ?array $ports = null ) {
	$ports  = null === $ports ? array( 80, 443 ) : array_map( 'intval', $ports );
	$limits = cmp_check_fetch_limits();
	$url    = trim( $in, " \t\n\r" );
	if ( '' === $url || strlen( $url ) > $limits['max_url'] || 1 === preg_match( '/[\x00-\x1F\x7F]/', $url ) ) {
		return false;
	}
	if ( 0 === strpos( $url, '//' ) ) {
		$url = 'https:' . $url;
	} elseif ( 1 === preg_match( '/^([a-z][a-z0-9+.\-]*):(?!\d)/i', $url, $m ) ) {
		$scheme = strtolower( $m[1] );
		if ( ( 'http' !== $scheme && 'https' !== $scheme ) || 1 !== preg_match( '#^https?://#i', $url ) ) {
			return false;
		}
	} else {
		$url = 'https://' . $url;
	}
	if ( 1 !== preg_match( '#^(https?)://([^/?\#\\\\]*)(.*)$#is', $url, $m ) ) {
		return false;
	}
	$scheme    = strtolower( $m[1] );
	$authority = $m[2];
	$rest      = $m[3];
	if ( '' !== $rest && '\\' === $rest[0] ) {
		return false; // Browsers read "\" as "/": an authority we would not parse alike.
	}
	if ( '' === $authority || false !== strpos( $authority, '@' ) || false !== strpos( $authority, '%' ) ) {
		return false;
	}
	// Host and port.
	$port = null;
	if ( '[' === $authority[0] ) {
		if ( 1 !== preg_match( '/^\[([0-9a-fA-F:.]+)\](?::(\d{1,5}))?$/', $authority, $am ) ) {
			return false;
		}
		if ( false === filter_var( $am[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return false;
		}
		$host = '[' . inet_ntop( inet_pton( $am[1] ) ) . ']';
		if ( isset( $am[2] ) && '' !== $am[2] ) {
			$port = (int) $am[2];
		}
	} else {
		if ( 1 !== preg_match( '/^([^:]+)(?::(\d{1,5}))?$/', $authority, $am ) ) {
			return false;
		}
		if ( isset( $am[2] ) && '' !== $am[2] ) {
			$port = (int) $am[2];
		}
		$host = cmp_check_idn_host( $am[1] );
		if ( false === $host ) {
			return false;
		}
		if ( '.' === substr( $host, -1 ) ) {
			$host = substr( $host, 0, -1 );
		}
		if ( '' === $host || strlen( $host ) > 253 || 1 !== preg_match( '/^[a-z0-9_-]+(?:\.[a-z0-9_-]+)*$/', $host ) ) {
			return false;
		}
		$labels = explode( '.', $host );
		foreach ( $labels as $l ) {
			if ( strlen( $l ) > 63 || '-' === $l[0] || '-' === substr( $l, -1 ) ) {
				return false;
			}
		}
		// A numeric or hex last label means an IP literal: only canonical dotted IPv4.
		if ( 1 === preg_match( '/^(?:0x[0-9a-f]*|[0-9]+)$/', end( $labels ) ) ) {
			$bin = @inet_pton( $host ); // phpcs:ignore
			if ( false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) || false === $bin || inet_ntop( $bin ) !== $host ) {
				return false;
			}
		} elseif ( cmp_check_local_name( $host ) ) {
			return false;
		}
	}
	$default = 'https' === $scheme ? 443 : 80;
	$eff     = null === $port ? $default : $port;
	if ( $eff < 1 || $eff > 65535 || ! in_array( $eff, $ports, true ) ) {
		return false;
	}
	// Path and query, without the fragment.
	$hash = strpos( $rest, '#' );
	if ( false !== $hash ) {
		$rest = substr( $rest, 0, $hash );
	}
	$rest = (string) preg_replace_callback(
		'/[^\x21-\x7E]|["<>\\\\^`{|}]/',
		function ( $c ) {
			return '%' . strtoupper( bin2hex( $c[0] ) );
		},
		$rest
	);
	if ( '' === $rest || '?' === $rest[0] ) {
		$rest = '/' . $rest;
	}
	if ( '?' === substr( $rest, -1 ) ) {
		$rest = substr( $rest, 0, -1 );
	}
	$out = $scheme . '://' . $host . ( $eff === $default ? '' : ':' . $eff ) . $rest;
	return strlen( $out ) > $limits['max_url'] ? false : $out;
}

/**
 * The visitor's field: a bare site address only, as 'https://<host>/', or false.
 *
 * Accepts "exemplu.md", "www.exemplu.md", "http(s)://exemplu.md" and the same
 * with one trailing "/"; an IDN becomes punycode. Refuses any IP literal (in
 * any form, public ones too), any path beyond "/", query, fragment, port,
 * user:password@, single-label and local names, labels that are not
 * letters / digits / hyphens, a top-level label that is not alphabetic (2+
 * letters) or punycode ("xn--"), and names over 253 characters. The check
 * always starts on https (the scheme the visitor typed is not kept); the
 * pages found later go through cmp_check_normalize_url() instead.
 *
 * @return string|false
 */
function cmp_check_normalize_input( string $in ) {
	$s = trim( $in, " \t\n\r" );
	if ( '' === $s || strlen( $s ) > 300 || 1 === preg_match( '/[\x00-\x20\x7F]/', $s ) ) {
		return false;
	}
	if ( 1 === preg_match( '#^https?://#i', $s ) ) {
		$s = (string) preg_replace( '#^https?://#i', '', $s );
	}
	if ( '/' === substr( $s, -1 ) ) {
		$s = substr( $s, 0, -1 );
	}
	// Only the host is left: no "/", "?", "#", ":", "@", "\", "[", "%" anywhere.
	if ( '' === $s || 1 === preg_match( '#[/?\#:@\\\\\[\]%]#', $s ) ) {
		return false;
	}
	$host = cmp_check_idn_host( $s );
	if ( false === $host || '' === $host || strlen( $host ) > 253 ) {
		return false;
	}
	$labels = explode( '.', $host );
	if ( count( $labels ) < 2 ) {
		return false;
	}
	foreach ( $labels as $l ) {
		if ( 1 !== preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $l ) ) {
			return false;
		}
	}
	$tld = end( $labels );
	if ( 1 !== preg_match( '/^(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', $tld ) || cmp_check_local_name( $host ) ) {
		return false;
	}
	$out = cmp_check_normalize_url( 'https://' . $host . '/' );
	return false === $out || 'https://' . $host . '/' !== $out ? false : $out;
}

/** An absolute URL for a Location header seen at $base, or false (scheme other than http/https). */
function cmp_check_resolve_location( string $base, string $loc ) {
	$loc = trim( $loc, " \t" );
	if ( '' === $loc || 1 === preg_match( '/[\x00-\x1F\x7F]/', $loc ) ) {
		return false;
	}
	if ( 1 !== preg_match( '#^(https?)://([^/?\#]*)([^?\#]*)(\?[^\#]*)?#i', $base, $b ) ) {
		return false;
	}
	$origin = strtolower( $b[1] ) . '://' . $b[2];
	$path   = '' === $b[3] ? '/' : $b[3];
	if ( 0 === strpos( $loc, '//' ) ) {
		return strtolower( $b[1] ) . ':' . $loc;
	}
	if ( 1 === preg_match( '/^[a-z][a-z0-9+.\-]*:/i', $loc ) ) {
		return 1 === preg_match( '#^https?://#i', $loc ) ? $loc : false;
	}
	if ( '/' === $loc[0] ) {
		return $origin . $loc;
	}
	if ( '?' === $loc[0] ) {
		return $origin . $path . $loc;
	}
	if ( '#' === $loc[0] ) {
		return $origin . $path . ( isset( $b[4] ) ? $b[4] : '' );
	}
	$dir = substr( $path, 0, (int) strrpos( $path, '/' ) + 1 );
	return $origin . $dir . $loc;
}

/* ------------------------------------------------------------------
 * The fetch.
 * ------------------------------------------------------------------ */

/** The result array of cmp_check_fetch(). $detail is internal (tests, never shown to the visitor). */
function cmp_check_fetch_result( bool $ok, string $error, string $url, int $status = 0, array $headers = array(), string $body = '', string $detail = '' ): array {
	return array(
		'ok'        => $ok,
		'status'    => $status,
		'headers'   => $headers,
		'body'      => $ok ? $body : '',
		'final_url' => $url,
		'error'     => $ok ? '' : $error,
		'detail'    => $ok ? '' : $detail,
	);
}

/** Same address, whatever the textual form? */
function cmp_check_same_ip( string $a, string $b ): bool {
	$x = @inet_pton( $a ); // phpcs:ignore
	$y = @inet_pton( $b ); // phpcs:ignore
	return false !== $x && false !== $y && $x === $y;
}

/**
 * The options of a fetch, with the limits that can only be lowered.
 *
 * - max_bytes: body cap (at most 2 MB; robots.txt and sitemaps use 1 MB);
 * - hosts:     null, or the only hosts the fetch may visit: a redirect to any
 *              other host stops it with 'other_host' (pages and sitemaps of
 *              a multi-page check);
 * - cache:     an object shared by the fetches of one check, so each host is
 *              resolved once (->ips: host => what the resolver returned); with
 *              ->requests_left set, every HTTP request (redirect hops too) takes
 *              one from it, and none is made at 0 ('limit');
 * - max_redirects: redirects followed (at most 3);
 * - html_only: a 2xx answer whose Content-Type is set and is not HTML is cut
 *              right after its headers, before any body byte is kept
 *              ('not_page'): images, PDFs, archives listed as pages;
 * - accept:    the Accept header;
 * - deadline:  absolute microtime() the fetch must end by.
 */
function cmp_check_fetch_opts( array $opts ): array {
	$limits = cmp_check_fetch_limits();
	$max    = isset( $opts['max_bytes'] ) ? (int) $opts['max_bytes'] : $limits['max_bytes'];
	$hosts  = null;
	if ( isset( $opts['hosts'] ) && is_array( $opts['hosts'] ) ) {
		$hosts = array_values( array_filter( $opts['hosts'], 'is_string' ) );
	}
	$cache = isset( $opts['cache'] ) && is_object( $opts['cache'] ) ? $opts['cache'] : null;
	if ( null !== $cache && ( ! isset( $cache->ips ) || ! is_array( $cache->ips ) ) ) {
		$cache->ips = array();
	}
	$redir = isset( $opts['max_redirects'] ) ? (int) $opts['max_redirects'] : $limits['max_redirects'];
	return array(
		'html_only'     => ! empty( $opts['html_only'] ),
		'max_redirects' => max( 0, min( $redir, $limits['max_redirects'] ) ),
		'max_bytes' => max( 1, min( $max, $limits['max_bytes'] ) ),
		'hosts'     => $hosts,
		'cache'     => $cache,
		'accept'    => isset( $opts['accept'] ) && is_string( $opts['accept'] ) && 1 === preg_match( '#^[a-z0-9/+*.;=, -]{1,200}$#i', $opts['accept'] ) ? $opts['accept'] : 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1',
		'deadline'  => isset( $opts['deadline'] ) ? (float) $opts['deadline'] : null,
	);
}

/** File extensions that are never pages. */
function cmp_check_file_extensions(): array {
	return array(
		'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'tif', 'tiff', 'heic',
		'zip', 'rar', '7z', 'gz', 'tgz', 'tar', 'bz2', 'xz',
		'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt', 'csv', 'epub',
		'xml', 'json', 'rss', 'atom', 'xsl',
		'mp3', 'mp4', 'm4a', 'm4v', 'wav', 'ogg', 'oga', 'webm', 'mov', 'avi', 'mkv', 'flac',
		'exe', 'dmg', 'apk', 'msi', 'iso', 'bin',
		'css', 'js', 'mjs', 'map', 'woff', 'woff2', 'ttf', 'otf', 'eot',
	);
}

/**
 * Is a URL path a file rather than a page: a file extension of
 * cmp_check_file_extensions(), or anything under /wp-content/uploads/?
 */
function cmp_check_is_file_path( string $path ): bool {
	$path = strtolower( (string) preg_replace( '#[?\#].*$#s', '', $path ) );
	if ( false !== strpos( $path, '/wp-content/uploads/' ) ) {
		return true;
	}
	$last = (string) substr( $path, (int) strrpos( $path, '/' ) + 1 );
	$dot  = strrpos( $last, '.' );
	return false !== $dot && in_array( substr( $last, $dot + 1 ), cmp_check_file_extensions(), true );
}

/** Is a Content-Type an HTML page (or missing, as browsers would sniff it)? */
function cmp_check_type_is_page( string $ct ): bool {
	$ct = strtolower( trim( $ct ) );
	return '' === $ct || 0 === strpos( $ct, 'text/html' ) || 0 === strpos( $ct, 'application/xhtml+xml' );
}

/** Take one HTTP request from the check's shared budget (when it has one): false when none is left. */
function cmp_check_fetch_take( $cache ): bool {
	if ( ! is_object( $cache ) || ! isset( $cache->requests_left ) ) {
		return true;
	}
	if ( (int) $cache->requests_left <= 0 ) {
		return false;
	}
	$cache->requests_left = (int) $cache->requests_left - 1;
	return true;
}

/** Is the normalised URL on one of the allowed hosts (or are all hosts allowed)? */
function cmp_check_fetch_host_ok( string $url, ?array $hosts ): bool {
	if ( null === $hosts ) {
		return true;
	}
	$h = 1 === preg_match( '#^https?://(\[[0-9a-f:.]+\]|[a-z0-9_.-]+)(?::\d+)?(?:[/?]|$)#', $url, $m ) ? $m[1] : '';
	return '' !== $h && in_array( $h, $hosts, true );
}

/**
 * Fetch a page as a new visitor would (GET, no cookies, no compression), safely.
 *
 * Time: the whole fetch has $budget seconds (10 at most), and never runs past
 * $opts['deadline']. Before each name resolution (one per hop, unless the
 * host is already in $opts['cache']) at least 1 s must be left, else the fetch
 * stops with "timeout". A resolution already started cannot be interrupted from
 * PHP (the system resolver has its own timeouts): the real bound on what slow
 * DNS can cost the account is the 3-slot concurrency limit of the endpoint
 * (cmp_check_slot_acquire()) and the one-resolution-per-host cache.
 *
 * @param string        $url            The address (normalised here first).
 * @param callable|null $resolver       host => list of IPs (tests); default cmp_check_resolve().
 * @param array|null    $ports          Allowed ports (tests); default 80 and 443.
 * @param bool          $allow_loopback Tests only: also allow exactly 127.0.0.1 (the local
 *                                      fixture server). Never passed by the endpoint.
 * @param int           $budget         Seconds for the whole fetch; can only lower the 10 s limit.
 * @param array         $opts           cmp_check_fetch_opts(): max_bytes, hosts, cache, accept, deadline.
 * @return array array( ok, status, headers (lowercase name => string, set-cookie => list),
 *               body, final_url, error: ''|unavailable|bad_url|blocked_address|fetch_failed|
 *               timeout|too_large|too_many_redirects|other_host|limit|not_page, detail: internal reason ).
 *               blocked_address is only for IP literals; a host name that resolves to
 *               a refused address is fetch_failed (detail private_address), like a
 *               name that does not resolve (detail dns).
 */
function cmp_check_fetch( string $url, ?callable $resolver = null, ?array $ports = null, bool $allow_loopback = false, int $budget = 10, array $opts = array() ): array {
	$limits = cmp_check_fetch_limits();
	if ( ! function_exists( 'curl_init' ) || ! defined( 'CURLOPT_RESOLVE' ) ) {
		return cmp_check_fetch_result( false, 'unavailable', '' );
	}
	$o        = cmp_check_fetch_opts( $opts );
	$budget   = max( 1, min( $budget, $limits['total_seconds'] ) );
	$deadline = microtime( true ) + $budget;
	if ( null !== $o['deadline'] ) {
		$deadline = min( $deadline, $o['deadline'] );
	}
	$current = cmp_check_normalize_url( $url, $ports );
	if ( false === $current ) {
		return cmp_check_fetch_result( false, 'bad_url', '' );
	}
	if ( ! cmp_check_fetch_host_ok( $current, $o['hosts'] ) ) {
		return cmp_check_fetch_result( false, 'other_host', $current );
	}
	for ( $hop = 0; ; $hop++ ) {
		$pin = cmp_check_pick_address( $current, $resolver, $allow_loopback, $deadline, $o['cache'] );
		if ( ! isset( $pin['ip'] ) ) {
			return cmp_check_fetch_result( false, $pin[0], $current, 0, array(), '', $pin[1] );
		}
		$left = $deadline - microtime( true );
		if ( $left < 0.05 ) {
			return cmp_check_fetch_result( false, 'timeout', $current );
		}
		if ( ! cmp_check_fetch_take( $o['cache'] ) ) {
			return cmp_check_fetch_result( false, 'limit', $current, 0, array(), '', 'requests' );
		}
		$r = cmp_check_curl_get( $current, $pin, $left, $o );
		if ( '' !== $r['error'] ) {
			return cmp_check_fetch_result( false, $r['error'], $current, 0, array(), '', $r['detail'] );
		}
		$step = cmp_check_fetch_step( $current, $r, $hop, $ports, $o['hosts'], $o['max_redirects'], $o['html_only'] );
		if ( isset( $step['next'] ) ) {
			$current = $step['next'];
			continue;
		}
		return $step['result'];
	}
}

/**
 * What to do with one answer: array( 'next' => the next URL ) for a redirect
 * that may be followed (validated from scratch, at most 3, on the allowed
 * hosts), else array( 'result' => the cmp_check_fetch() result ).
 */
function cmp_check_fetch_step( string $current, array $r, int $hop, ?array $ports, ?array $hosts, ?int $max_redirects = null, bool $pages_only = false ): array {
	$limits = cmp_check_fetch_limits();
	$max    = null === $max_redirects ? $limits['max_redirects'] : max( 0, min( $max_redirects, $limits['max_redirects'] ) );
	$status = $r['status'];
	$loc    = isset( $r['headers']['location'] ) ? (string) $r['headers']['location'] : '';
	if ( in_array( $status, array( 301, 302, 303, 307, 308 ), true ) && '' !== $loc ) {
		if ( $hop >= $max ) {
			return array( 'result' => cmp_check_fetch_result( false, 'too_many_redirects', $current ) );
		}
		$next = cmp_check_resolve_location( $current, $loc );
		$next = false === $next ? false : cmp_check_normalize_url( $next, $ports );
		if ( false === $next ) {
			return array( 'result' => cmp_check_fetch_result( false, 'bad_url', $current, 0, array(), '', 'redirect' ) );
		}
		if ( ! cmp_check_fetch_host_ok( $next, $hosts ) ) {
			// Never followed: a sub-page or a sitemap may not lead anywhere else.
			return array( 'result' => cmp_check_fetch_result( false, 'other_host', $current, 0, array(), '', 'redirect' ) );
		}
		if ( $pages_only && 1 === preg_match( '#^https?://[^/?]+([^?]*)#', $next, $pm ) && cmp_check_is_file_path( $pm[1] ) ) {
			// A page that redirects to a file (an image under /wp-content/uploads/...): not a page, not followed.
			return array( 'result' => cmp_check_fetch_result( false, 'not_page', $current, 0, array(), '', 'file_redirect' ) );
		}
		return array( 'next' => $next );
	}
	// We asked for identity: compressed bytes are never analysed.
	$enc = isset( $r['headers']['content-encoding'] ) ? strtolower( trim( (string) $r['headers']['content-encoding'] ) ) : '';
	if ( '' !== $enc && 'identity' !== $enc ) {
		return array( 'result' => cmp_check_fetch_result( false, 'fetch_failed', $current, 0, array(), '', 'encoded' ) );
	}
	// Compressed bytes sent without saying so (gzip or zstd magic): never analysed either.
	if ( 0 === strpos( $r['body'], "\x1f\x8b" ) || 0 === strpos( $r['body'], "\x28\xb5\x2f\xfd" ) ) {
		return array( 'result' => cmp_check_fetch_result( false, 'fetch_failed', $current, 0, array(), '', 'compressed_body' ) );
	}
	return array( 'result' => cmp_check_fetch_result( true, '', $current, $status, $r['headers'], $r['body'] ) );
}

/**
 * The checked address to connect to for a normalised URL:
 * array( host, port, ip, literal ), or array( error code, detail ).
 * With $cache, a host name is resolved at most once per check; the addresses
 * are checked again on every call all the same.
 */
function cmp_check_pick_address( string $url, ?callable $resolver, bool $allow_loopback, float $deadline, $cache = null ): array {
	$limits = cmp_check_fetch_limits();
	if ( 1 !== preg_match( '#^(https?)://(\[[0-9a-f:.]+\]|[a-z0-9_.-]+)(?::(\d+))?(?:[/?]|$)#', $url, $m ) ) {
		return array( 'bad_url', '' );
	}
	$host    = $m[2];
	$port    = isset( $m[3] ) && '' !== $m[3] ? (int) $m[3] : ( 'https' === $m[1] ? 443 : 80 );
	$literal = true;
	if ( '[' === $host[0] ) {
		$ips = array( substr( $host, 1, -1 ) );
	} elseif ( false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
		$ips = array( $host );
	} else {
		$literal = false;
		if ( is_object( $cache ) && isset( $cache->ips ) && is_array( $cache->ips ) && array_key_exists( $host, $cache->ips ) ) {
			$ips = $cache->ips[ $host ];
		} else {
			// Never start a resolution with less than 1 s of the budget left.
			if ( $deadline - microtime( true ) < 1.0 ) {
				return array( 'timeout', 'dns_budget' );
			}
			$ips = null === $resolver ? cmp_check_resolve( $host ) : call_user_func( $resolver, $host );
			if ( is_object( $cache ) && isset( $cache->ips ) && is_array( $cache->ips ) ) {
				$cache->ips[ $host ] = $ips;
			}
		}
		if ( ! is_array( $ips ) || ! $ips ) {
			return array( 'fetch_failed', 'dns' );
		}
	}
	// A literal is the visitor's own choice: say why. A name pointing inside looks like any failure.
	$refuse = $literal ? array( 'blocked_address', 'private_address' ) : array( 'fetch_failed', 'private_address' );
	if ( count( $ips ) > $limits['max_addresses'] ) {
		return $refuse;
	}
	// Every address must be allowed: a name that also points inside is refused.
	foreach ( $ips as $ip ) {
		if ( ! is_string( $ip ) ) {
			return $refuse;
		}
		$ok = cmp_check_ip_allowed( $ip ) || ( $allow_loopback && '127.0.0.1' === $ip );
		if ( ! $ok ) {
			return $refuse;
		}
	}
	$first = reset( $ips );
	return array(
		'host'    => $host,
		'port'    => $port,
		'ip'      => (string) $first,
		'literal' => $literal,
	);
}

/**
 * A curl handle for one GET pinned to $pin['ip'], not started:
 * array( ch, st (body / headers collected by the callbacks), pin ), or
 * array( error, detail, status, headers, body ) when curl cannot be set up.
 */
function cmp_check_curl_prepare( string $url, array $pin, float $seconds, array $o = array() ): array {
	$limits = cmp_check_fetch_limits();
	$o      = isset( $o['max_bytes'], $o['accept'] ) ? $o : cmp_check_fetch_opts( $o );
	$max    = $o['max_bytes'];
	$st     = (object) array(
		'body'      => '',
		'too_big'   => false,
		'headers'   => array(),
		'hbytes'    => 0,
		'h_too_big' => false,
		'code'      => 0,
		'not_page'  => false,
		'received'  => 0,
	);
	$html_only = ! empty( $o['html_only'] );
	$ch     = curl_init();
	if ( false === $ch ) {
		return array( 'error' => 'unavailable', 'detail' => '', 'status' => 0, 'headers' => array(), 'body' => '' );
	}
	$is6   = false !== strpos( $pin['ip'], ':' );
	$proto = CURLPROTO_HTTP | CURLPROTO_HTTPS;
	$ms    = (int) max( 50, floor( $seconds * 1000 ) );
	$opts  = array(
		CURLOPT_URL               => $url,
		CURLOPT_HTTPGET           => true,
		CURLOPT_PROTOCOLS         => $proto,
		CURLOPT_REDIR_PROTOCOLS   => $proto,
		CURLOPT_FOLLOWLOCATION    => false,
		CURLOPT_MAXREDIRS         => 0,
		CURLOPT_NOSIGNAL          => true,
		CURLOPT_CONNECTTIMEOUT_MS => min( $ms, $limits['connect'] * 1000 ),
		CURLOPT_TIMEOUT_MS        => $ms,
		CURLOPT_LOW_SPEED_LIMIT   => 512,
		CURLOPT_LOW_SPEED_TIME    => 5,
		CURLOPT_MAXFILESIZE       => $max,
		CURLOPT_PROXY             => '',
		CURLOPT_NOPROXY           => '*',
		CURLOPT_FRESH_CONNECT     => true,
		CURLOPT_FORBID_REUSE      => true,
		CURLOPT_IPRESOLVE         => $is6 ? CURL_IPRESOLVE_V6 : CURL_IPRESOLVE_V4,
		CURLOPT_SSL_VERIFYPEER    => true,
		CURLOPT_SSL_VERIFYHOST    => 2,
		CURLOPT_USERAGENT         => cmp_check_user_agent(),
		// No automatic decompression: the cap counts the bytes on the wire.
		CURLOPT_HTTPHEADER        => array(
			'Accept: ' . $o['accept'],
			'Accept-Language: ro,en;q=0.8',
			'Accept-Encoding: identity',
		),
		CURLOPT_HEADERFUNCTION    => function ( $ch, $line ) use ( $st, $limits, $html_only, $max ) {
			$len         = strlen( $line );
			$st->hbytes += $len;
			if ( $st->hbytes > $limits['max_header'] ) {
				$st->h_too_big = true;
				return 0;
			}
			if ( 1 === preg_match( '#^HTTP/\S+\s+(\d{3})#', $line, $hm ) ) {
				$st->headers = array(); // A new response (after 100 Continue): start over.
				$st->code    = (int) $hm[1];
				return $len;
			}
			if ( '' === trim( $line ) ) {
				// End of this response's headers: a 2xx that is not an HTML page is cut here, no body read.
				$ct = isset( $st->headers['content-type'] ) ? (string) $st->headers['content-type'] : '';
				if ( $html_only && $st->code >= 200 && $st->code <= 299 ) {
					if ( ! cmp_check_type_is_page( $ct ) ) {
						$st->not_page = true;
						return 0;
					}
					// The Content-Length cap, checked here after the type (CURLOPT_MAXFILESIZE is off
					// for pages: some curl versions apply it before the type is known).
					$cl = isset( $st->headers['content-length'] ) ? trim( (string) $st->headers['content-length'] ) : '';
					if ( 1 === preg_match( '/^\d{1,18}$/', $cl ) && (int) $cl > $max ) {
						$st->too_big = true;
						return 0;
					}
				}
				return $len;
			}
			$p = strpos( $line, ':' );
			if ( false === $p ) {
				return $len;
			}
			$name  = strtolower( trim( substr( $line, 0, $p ) ) );
			$value = trim( substr( $line, $p + 1 ) );
			if ( 1 !== preg_match( '/^[a-z0-9!#$%&\'*+.^_`|~-]+$/', $name ) ) {
				return $len;
			}
			if ( 'set-cookie' === $name ) {
				$st->headers['set-cookie'][] = $value;
			} elseif ( isset( $st->headers[ $name ] ) ) {
				$st->headers[ $name ] .= ', ' . $value;
			} else {
				$st->headers[ $name ] = $value;
			}
			return $len;
		},
		CURLOPT_WRITEFUNCTION     => function ( $ch, $chunk ) use ( $st, $max ) {
			$len           = strlen( $chunk );
			$st->received += $len;
			if ( $st->not_page ) {
				return 0;
			}
			if ( strlen( $st->body ) + $len > $max ) {
				$st->too_big = true;
				return 0; // Short count: curl stops reading.
			}
			$st->body .= $chunk;
			return $len;
		},
	);
	if ( $html_only ) {
		unset( $opts[ CURLOPT_MAXFILESIZE ] ); // The header callback applies the cap after the type check.
	}
	if ( ! $pin['literal'] ) {
		// Pin the name to the address checked above: curl does no DNS of its own.
		$opts[ CURLOPT_RESOLVE ] = array( $pin['host'] . ':' . $pin['port'] . ':' . ( $is6 ? '[' . $pin['ip'] . ']' : $pin['ip'] ) );
	}
	if ( ! curl_setopt_array( $ch, $opts ) ) {
		curl_close( $ch );
		return array( 'error' => 'unavailable', 'detail' => '', 'status' => 0, 'headers' => array(), 'body' => '' );
	}
	return array(
		'ch'  => $ch,
		'st'  => $st,
		'pin' => $pin,
	);
}

/**
 * The outcome of a finished handle (closed here): array( error, detail,
 * status, headers, body ). $errno: the curl result code of the transfer.
 */
function cmp_check_curl_finish( array $h, int $errno ): array {
	$ch     = $h['ch'];
	$st     = $h['st'];
	$pin    = $h['pin'];
	$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	$used   = (string) curl_getinfo( $ch, CURLINFO_PRIMARY_IP );
	curl_close( $ch );

	$fail = function ( $code, $detail = '' ) {
		return array( 'error' => $code, 'detail' => $detail, 'status' => 0, 'headers' => array(), 'body' => '' );
	};
	if ( $st->not_page ) {
		return $fail( 'not_page', 'type' ); // Not an HTML page: cut after the headers.
	}
	if ( $st->too_big || 63 === $errno ) { // 63: CURLE_FILESIZE_EXCEEDED (Content-Length over the cap).
		return $fail( 'too_large' );
	}
	if ( 28 === $errno ) { // CURLE_OPERATION_TIMEDOUT.
		return $fail( 'timeout' );
	}
	if ( 0 !== $errno || $st->h_too_big || $status < 100 ) {
		return $fail( 'fetch_failed' );
	}
	// Belt and braces: the connection really went to the checked address.
	if ( '' === $used || ! cmp_check_same_ip( $used, $pin['ip'] ) ) {
		return $fail( $pin['literal'] ? 'blocked_address' : 'fetch_failed', 'pin_mismatch' );
	}
	return array( 'error' => '', 'detail' => '', 'status' => $status, 'headers' => $st->headers, 'body' => $st->body );
}

/** One GET with curl, pinned to $pin['ip']. Returns array( error, detail, status, headers, body ). */
function cmp_check_curl_get( string $url, array $pin, float $seconds, array $o = array() ): array {
	$h = cmp_check_curl_prepare( $url, $pin, $seconds, $o );
	if ( ! isset( $h['ch'] ) ) {
		return $h;
	}
	curl_exec( $h['ch'] );
	return cmp_check_curl_finish( $h, curl_errno( $h['ch'] ) );
}

/**
 * Fetch several pages of one site, at most $o['concurrency'] (3) at a time
 * with curl_multi, each exactly as cmp_check_fetch() would: normalised,
 * pinned to its checked address (resolved once per host through the shared
 * cache), redirects followed by hand (at most 3, each re-validated and kept on
 * $o['hosts']), the body cap, identity encoding, compressed bodies refused.
 *
 * Time: each page has at most $o['per_page'] seconds (10), from the moment it
 * starts, and nothing runs past $o['deadline']; a page not started or not
 * finished by then is 'timeout'.
 *
 * @param array $urls key => URL.
 * @param array $o    resolver, ports, loopback (tests only), deadline (required),
 *                    per_page, concurrency, and cmp_check_fetch_opts() keys.
 * @return array key => cmp_check_fetch() result, in the order of $urls.
 */
function cmp_check_fetch_many( array $urls, array $o ): array {
	$limits   = cmp_check_fetch_limits();
	$resolver = isset( $o['resolver'] ) && is_callable( $o['resolver'] ) ? $o['resolver'] : null;
	$ports    = isset( $o['ports'] ) && is_array( $o['ports'] ) ? $o['ports'] : null;
	$loop     = ! empty( $o['loopback'] );
	$per      = isset( $o['per_page'] ) ? max( 0.1, min( (float) $o['per_page'], $limits['total_seconds'] ) ) : $limits['total_seconds'];
	$conc     = isset( $o['concurrency'] ) ? max( 1, min( 3, (int) $o['concurrency'] ) ) : 3;
	$fo       = cmp_check_fetch_opts( $o );
	$deadline = null === $fo['deadline'] ? microtime( true ) + $per : $fo['deadline'];
	$results  = array();
	$queue    = array();
	foreach ( $urls as $k => $u ) {
		$n = is_string( $u ) ? cmp_check_normalize_url( $u, $ports ) : false;
		if ( false === $n ) {
			$results[ $k ] = cmp_check_fetch_result( false, 'bad_url', '' );
		} elseif ( ! cmp_check_fetch_host_ok( $n, $fo['hosts'] ) ) {
			$results[ $k ] = cmp_check_fetch_result( false, 'other_host', $n );
		} else {
			$queue[] = array(
				'k'     => $k,
				'url'   => $n,
				'hop'   => 0,
				'until' => null,
			);
		}
	}
	if ( $queue && ( ! function_exists( 'curl_multi_init' ) || ! defined( 'CURLOPT_RESOLVE' ) ) ) {
		foreach ( $queue as $job ) {
			$results[ $job['k'] ] = cmp_check_fetch_result( false, 'unavailable', '' );
		}
		$queue = array();
	}
	$mh     = $queue ? curl_multi_init() : null;
	$active = array();
	while ( $queue || $active ) {
		// Start pages while there is room.
		while ( count( $active ) < $conc && $queue ) {
			$job = array_shift( $queue );
			$now = microtime( true );
			if ( null === $job['until'] ) {
				$job['until'] = min( $deadline, $now + $per );
			}
			if ( $job['until'] - $now < 0.05 ) {
				$results[ $job['k'] ] = cmp_check_fetch_result( false, 'timeout', $job['url'], 0, array(), '', 0 === $job['hop'] ? 'not_started' : '' );
				continue;
			}
			$pin = cmp_check_pick_address( $job['url'], $resolver, $loop, $job['until'], $fo['cache'] );
			if ( ! isset( $pin['ip'] ) ) {
				$results[ $job['k'] ] = cmp_check_fetch_result( false, $pin[0], $job['url'], 0, array(), '', $pin[1] );
				continue;
			}
			$left = $job['until'] - microtime( true );
			if ( $left < 0.05 ) {
				$results[ $job['k'] ] = cmp_check_fetch_result( false, 'timeout', $job['url'], 0, array(), '', 0 === $job['hop'] ? 'not_started' : '' );
				continue;
			}
			if ( ! cmp_check_fetch_take( $fo['cache'] ) ) {
				$results[ $job['k'] ] = cmp_check_fetch_result( false, 'limit', $job['url'], 0, array(), '', 'requests' );
				continue;
			}
			$h = cmp_check_curl_prepare( $job['url'], $pin, $left, $fo );
			if ( ! isset( $h['ch'] ) ) {
				$results[ $job['k'] ] = cmp_check_fetch_result( false, $h['error'], $job['url'] );
				continue;
			}
			curl_multi_add_handle( $mh, $h['ch'] );
			$active[] = array(
				'job' => $job,
				'h'   => $h,
			);
		}
		if ( ! $active ) {
			continue;
		}
		do {
			$mrc = curl_multi_exec( $mh, $running );
		} while ( CURLM_CALL_MULTI_PERFORM === $mrc );
		while ( false !== ( $info = curl_multi_info_read( $mh ) ) ) { // phpcs:ignore
			if ( ! is_array( $info ) || CURLMSG_DONE !== $info['msg'] ) {
				continue;
			}
			foreach ( $active as $i => $a ) {
				if ( $a['h']['ch'] !== $info['handle'] ) {
					continue;
				}
				unset( $active[ $i ] );
				curl_multi_remove_handle( $mh, $a['h']['ch'] );
				$job = $a['job'];
				$r   = cmp_check_curl_finish( $a['h'], (int) $info['result'] );
				if ( '' !== $r['error'] ) {
					$results[ $job['k'] ] = cmp_check_fetch_result( false, $r['error'], $job['url'], 0, array(), '', $r['detail'] );
					break;
				}
				$step = cmp_check_fetch_step( $job['url'], $r, $job['hop'], $ports, $fo['hosts'], $fo['max_redirects'], $fo['html_only'] );
				if ( isset( $step['next'] ) ) {
					$job['url'] = $step['next'];
					$job['hop']++;
					array_unshift( $queue, $job ); // Same page, same time budget.
				} else {
					$results[ $job['k'] ] = $step['result'];
				}
				break;
			}
		}
		$active = array_values( $active );
		if ( $active ) {
			// Curl enforces each handle's timeout; this is only a safety net.
			if ( microtime( true ) > $deadline + 2 ) {
				foreach ( $active as $a ) {
					curl_multi_remove_handle( $mh, $a['h']['ch'] );
					curl_close( $a['h']['ch'] );
					$results[ $a['job']['k'] ] = cmp_check_fetch_result( false, 'timeout', $a['job']['url'] );
				}
				$active = array();
				continue;
			}
			if ( -1 === curl_multi_select( $mh, 0.1 ) ) {
				usleep( 10000 );
			}
		}
	}
	if ( null !== $mh ) {
		curl_multi_close( $mh );
	}
	$out = array();
	foreach ( array_keys( $urls ) as $k ) {
		$out[ $k ] = isset( $results[ $k ] ) ? $results[ $k ] : cmp_check_fetch_result( false, 'timeout', '' );
	}
	return $out;
}
