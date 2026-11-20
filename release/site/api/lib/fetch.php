<?php
if ( ! defined( 'CMP_CHECK' ) ) {
	http_response_code( 404 );
	exit;
}

/**
 * Consent Manager Pro, "Verifică-ți site-ul": fetching a visitor-given URL
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
 *
 * The allowed ports, the resolver and the loopback exception can be changed
 * only through function parameters, for the tests; the endpoint passes none.
 * Nothing here writes files or logs, and the URL is never logged.
 * PHP 7.4+, needs the curl extension.
 */

/** User-agent sent with every request. */
function cmp_check_user_agent(): string {
	return 'ConsentManagerPro-Check/1.0 (+https://consent.rt.md/)';
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
 * Fetch a page as a new visitor would (GET, no cookies, no compression), safely.
 *
 * Time: the whole fetch has $budget seconds (10 at most). Before each name
 * resolution (one per hop) at least 1 s must be left, else the fetch stops
 * with "timeout". A resolution already started cannot be interrupted from PHP
 * (the system resolver has its own timeouts): the real bound on what slow DNS
 * can cost the account is the 3-slot concurrency limit of the endpoint
 * (cmp_check_slot_acquire()).
 *
 * @param string        $url            The address (normalised here first).
 * @param callable|null $resolver       host => list of IPs (tests); default cmp_check_resolve().
 * @param array|null    $ports          Allowed ports (tests); default 80 and 443.
 * @param bool          $allow_loopback Tests only: also allow exactly 127.0.0.1 (the local
 *                                      fixture server). Never passed by the endpoint.
 * @param int           $budget         Seconds for the whole fetch; can only lower the 10 s limit.
 * @return array array( ok, status, headers (lowercase name => string, set-cookie => list),
 *               body, final_url, error: ''|unavailable|bad_url|blocked_address|fetch_failed|
 *               timeout|too_large|too_many_redirects, detail: internal reason ).
 *               blocked_address is only for IP literals; a host name that resolves to
 *               a refused address is fetch_failed (detail private_address), like a
 *               name that does not resolve (detail dns).
 */
function cmp_check_fetch( string $url, ?callable $resolver = null, ?array $ports = null, bool $allow_loopback = false, int $budget = 10 ): array {
	$limits = cmp_check_fetch_limits();
	if ( ! function_exists( 'curl_init' ) || ! defined( 'CURLOPT_RESOLVE' ) ) {
		return cmp_check_fetch_result( false, 'unavailable', '' );
	}
	$budget   = max( 1, min( $budget, $limits['total_seconds'] ) );
	$deadline = microtime( true ) + $budget;
	$current  = cmp_check_normalize_url( $url, $ports );
	if ( false === $current ) {
		return cmp_check_fetch_result( false, 'bad_url', '' );
	}
	for ( $hop = 0; ; $hop++ ) {
		$pin = cmp_check_pick_address( $current, $resolver, $allow_loopback, $deadline );
		if ( ! isset( $pin['ip'] ) ) {
			return cmp_check_fetch_result( false, $pin[0], $current, 0, array(), '', $pin[1] );
		}
		$left = $deadline - microtime( true );
		if ( $left < 0.05 ) {
			return cmp_check_fetch_result( false, 'timeout', $current );
		}
		$r = cmp_check_curl_get( $current, $pin, $left );
		if ( '' !== $r['error'] ) {
			return cmp_check_fetch_result( false, $r['error'], $current, 0, array(), '', $r['detail'] );
		}
		$status = $r['status'];
		$loc    = isset( $r['headers']['location'] ) ? (string) $r['headers']['location'] : '';
		if ( in_array( $status, array( 301, 302, 303, 307, 308 ), true ) && '' !== $loc ) {
			if ( $hop >= $limits['max_redirects'] ) {
				return cmp_check_fetch_result( false, 'too_many_redirects', $current );
			}
			$next = cmp_check_resolve_location( $current, $loc );
			$next = false === $next ? false : cmp_check_normalize_url( $next, $ports );
			if ( false === $next ) {
				return cmp_check_fetch_result( false, 'bad_url', $current, 0, array(), '', 'redirect' );
			}
			$current = $next;
			continue;
		}
		// We asked for identity: compressed bytes are never analysed.
		$enc = isset( $r['headers']['content-encoding'] ) ? strtolower( trim( (string) $r['headers']['content-encoding'] ) ) : '';
		if ( '' !== $enc && 'identity' !== $enc ) {
			return cmp_check_fetch_result( false, 'fetch_failed', $current, 0, array(), '', 'encoded' );
		}
		// Compressed bytes sent without saying so (gzip or zstd magic): never analysed either.
		if ( 0 === strpos( $r['body'], "\x1f\x8b" ) || 0 === strpos( $r['body'], "\x28\xb5\x2f\xfd" ) ) {
			return cmp_check_fetch_result( false, 'fetch_failed', $current, 0, array(), '', 'compressed_body' );
		}
		return cmp_check_fetch_result( true, '', $current, $status, $r['headers'], $r['body'] );
	}
}

/**
 * The checked address to connect to for a normalised URL:
 * array( host, port, ip, literal ), or array( error code, detail ).
 */
function cmp_check_pick_address( string $url, ?callable $resolver, bool $allow_loopback, float $deadline ): array {
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
		// Never start a resolution with less than 1 s of the budget left.
		if ( $deadline - microtime( true ) < 1.0 ) {
			return array( 'timeout', 'dns_budget' );
		}
		$ips = null === $resolver ? cmp_check_resolve( $host ) : call_user_func( $resolver, $host );
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
	return array(
		'host'    => $host,
		'port'    => $port,
		'ip'      => (string) $ips[0],
		'literal' => $literal,
	);
}

/** One GET with curl, pinned to $pin['ip']. Returns array( error, detail, status, headers, body ). */
function cmp_check_curl_get( string $url, array $pin, float $seconds ): array {
	$limits    = cmp_check_fetch_limits();
	$body      = '';
	$too_big   = false;
	$headers   = array();
	$hbytes    = 0;
	$h_too_big = false;
	$ch        = curl_init();
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
		CURLOPT_MAXFILESIZE       => $limits['max_bytes'],
		CURLOPT_PROXY             => '',
		CURLOPT_NOPROXY           => '*',
		CURLOPT_FRESH_CONNECT     => true,
		CURLOPT_FORBID_REUSE      => true,
		CURLOPT_IPRESOLVE         => $is6 ? CURL_IPRESOLVE_V6 : CURL_IPRESOLVE_V4,
		CURLOPT_SSL_VERIFYPEER    => true,
		CURLOPT_SSL_VERIFYHOST    => 2,
		CURLOPT_USERAGENT         => cmp_check_user_agent(),
		// No automatic decompression: the 2 MB cap counts the bytes on the wire.
		CURLOPT_HTTPHEADER        => array(
			'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.1',
			'Accept-Language: ro,en;q=0.8',
			'Accept-Encoding: identity',
		),
		CURLOPT_HEADERFUNCTION    => function ( $ch, $line ) use ( &$headers, &$hbytes, &$h_too_big, $limits ) {
			$len     = strlen( $line );
			$hbytes += $len;
			if ( $hbytes > $limits['max_header'] ) {
				$h_too_big = true;
				return 0;
			}
			if ( 1 === preg_match( '#^HTTP/\S+\s+\d{3}#', $line ) ) {
				$headers = array(); // A new response (after 100 Continue): start over.
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
				$headers['set-cookie'][] = $value;
			} elseif ( isset( $headers[ $name ] ) ) {
				$headers[ $name ] .= ', ' . $value;
			} else {
				$headers[ $name ] = $value;
			}
			return $len;
		},
		CURLOPT_WRITEFUNCTION     => function ( $ch, $chunk ) use ( &$body, &$too_big, $limits ) {
			$len = strlen( $chunk );
			if ( strlen( $body ) + $len > $limits['max_bytes'] ) {
				$too_big = true;
				return 0; // Short count: curl stops reading.
			}
			$body .= $chunk;
			return $len;
		},
	);
	if ( ! $pin['literal'] ) {
		// Pin the name to the address checked above: curl does no DNS of its own.
		$opts[ CURLOPT_RESOLVE ] = array( $pin['host'] . ':' . $pin['port'] . ':' . ( $is6 ? '[' . $pin['ip'] . ']' : $pin['ip'] ) );
	}
	if ( ! curl_setopt_array( $ch, $opts ) ) {
		curl_close( $ch );
		return array( 'error' => 'unavailable', 'detail' => '', 'status' => 0, 'headers' => array(), 'body' => '' );
	}
	curl_exec( $ch );
	$errno  = curl_errno( $ch );
	$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	$used   = (string) curl_getinfo( $ch, CURLINFO_PRIMARY_IP );
	curl_close( $ch );

	$fail = function ( $code, $detail = '' ) {
		return array( 'error' => $code, 'detail' => $detail, 'status' => 0, 'headers' => array(), 'body' => '' );
	};
	if ( $too_big || 63 === $errno ) { // 63: CURLE_FILESIZE_EXCEEDED (Content-Length over the cap).
		return $fail( 'too_large' );
	}
	if ( 28 === $errno ) { // CURLE_OPERATION_TIMEDOUT.
		return $fail( 'timeout' );
	}
	if ( 0 !== $errno || $h_too_big || $status < 100 ) {
		return $fail( 'fetch_failed' );
	}
	// Belt and braces: the connection really went to the checked address.
	if ( '' === $used || ! cmp_check_same_ip( $used, $pin['ip'] ) ) {
		return $fail( $pin['literal'] ? 'blocked_address' : 'fetch_failed', 'pin_mismatch' );
	}
	return array( 'error' => '', 'detail' => '', 'status' => $status, 'headers' => $headers, 'body' => $body );
}
