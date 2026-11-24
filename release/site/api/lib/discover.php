<?php
if ( ! defined( 'CMP_CHECK' ) ) {
	http_response_code( 404 );
	exit;
}

/**
 * BiscuitWP, "Verifică-ți site-ul": which pages of the site to check
 * (up to 10, the homepage first).
 *
 * Sources, in order: the Sitemap: lines of robots.txt, then /sitemap.xml,
 * /sitemap_index.xml and /wp-sitemap.xml; one level of sitemap index; at most
 * 3 sitemaps (urlsets) read. Without a usable sitemap, the <a href> links of
 * the homepage.
 *
 * Everything found in robots.txt, a sitemap or a link is hostile input. Every
 * address (sitemaps and pages) goes through cmp_check_normalize_url() and must
 * stay on the homepage's host or its www. variant: a sitemap or a link that
 * names another host, an IP address or another scheme is dropped here, before
 * any request. The requests themselves are made by the caller's $fetch_small,
 * which is the same guarded fetch as the pages (pinned address, public
 * addresses only, redirects re-validated and kept on the same host, size cap).
 *
 * Sitemaps are read with a bounded regex reader only (the regex fallback of
 * CMP_Scanner::parse_sitemap(), no require from the plugin), never libxml:
 * no entity is ever expanded or loaded, whatever the encoding, and the cost
 * is linear. Only the first 200 <loc> of a sitemap are looked at; the rest
 * are only counted (for the total). Discovery stops at $until.
 * PHP 7.4+. Needs lib/check.php and lib/fetch.php.
 */

/** Limits of the discovery. */
function cmp_check_discover_limits(): array {
	return array(
		'max_pages'      => 10,      // Homepage included.
		'max_urlsets'    => 3,       // Sitemaps (lists of pages) read.
		'max_top_tries'  => 4,       // robots.txt sitemaps and well-known names tried.
		'max_children'   => 3,       // Sitemaps of an index tried (one level only).
		'max_map_bytes'  => 1048576, // Parsed bytes of one sitemap (the fetch caps it too).
		'max_map_urls'   => 200,     // <loc> per sitemap looked at (the others are only counted).
		'max_loc_bytes'  => 2048,    // Characters of one <loc>.
		'max_robots'     => 50,      // Sitemap: lines read from robots.txt.
		'max_candidates' => 200,     // Pages kept in order for the check (10 checked, the rest as backfill).
		'max_links'      => 3000,    // Homepage links looked at.
	);
}

/** Query parameters that only track a visit: dropped from page addresses. */
function cmp_check_tracking_param( string $name ): bool {
	$name = strtolower( rawurldecode( $name ) );
	if ( 0 === strpos( $name, 'utm_' ) ) {
		return true;
	}
	return in_array( $name, array( 'fbclid', 'gclid', 'gclsrc', 'dclid', 'gbraid', 'wbraid', 'msclkid', 'yclid', 'ysclid', 'twclid', 'ttclid', 'li_fat_id', 'mc_cid', 'mc_eid', '_ga', '_gl', 'igshid', 'srsltid', '_hsenc', '_hsmi', 'mkt_tok', 'oly_anon_id', 'oly_enc_id', 'vero_id', 'rb_clickid', 's_cid' ), true );
}

/** Host of a normalised URL (IPv6 in brackets), '' when none. */
function cmp_check_url_host( string $url ): string {
	return 1 === preg_match( '#^https?://(\[[0-9a-f:.]+\]|[a-z0-9_.-]+)(?::\d+)?(?:[/?]|$)#', $url, $m ) ? $m[1] : '';
}

/**
 * The hosts a check may visit: the homepage's host and its www. variant (an
 * IP address has no variant).
 */
function cmp_check_site_hosts( string $home_url ): array {
	$host = cmp_check_url_host( $home_url );
	if ( '' === $host ) {
		return array();
	}
	if ( '[' === $host[0] || false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return array( $host );
	}
	if ( 0 === strpos( $host, 'www.' ) ) {
		$bare = substr( $host, 4 );
		return false === strpos( $bare, '.' ) ? array( $host ) : array( $host, $bare );
	}
	return array( $host, 'www.' . $host );
}

/** Is a normalised URL on one of $hosts? */
function cmp_check_host_allowed( string $url, array $hosts ): bool {
	$h = cmp_check_url_host( $url );
	return '' !== $h && in_array( $h, $hosts, true );
}

/**
 * An address found in robots.txt, a sitemap or a link, made safe: normalised
 * (cmp_check_normalize_url), on one of $hosts, and for a page ($page true):
 * not a file, not an admin / feed address, without tracking parameters.
 * False when it must be dropped.
 */
function cmp_check_site_url( string $url, array $hosts, ?array $ports = null, bool $page = true ) {
	$url = cmp_check_normalize_url( $url, $ports );
	if ( false === $url || ! cmp_check_host_allowed( $url, $hosts ) ) {
		return false;
	}
	if ( ! $page ) {
		return $url;
	}
	if ( 1 !== preg_match( '#^(https?://[^/?]+)([^?]*)(?:\?(.*))?$#s', $url, $m ) ) {
		return false;
	}
	$path  = $m[2];
	$query = isset( $m[3] ) ? $m[3] : '';
	if ( cmp_check_is_file_path( $path ) ) {
		return false; // A file (by extension, or a WordPress upload), never a page: not even requested.
	}
	if ( 1 === preg_match( '#^/(?:wp-admin(?:/|$)|wp-login\.php|xmlrpc\.php|wp-json(?:/|$)|wp-cron\.php|(?:.*/)?feed/?$|cdn-cgi/)#i', $path ) ) {
		return false;
	}
	$keep = array();
	if ( '' !== $query ) {
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$name = (string) strstr( $pair . '=', '=', true );
			if ( ! cmp_check_tracking_param( $name ) && 'replytocom' !== strtolower( $name ) ) {
				$keep[] = $pair;
			}
		}
	}
	return $m[1] . ( '' === $path ? '/' : $path ) . ( $keep ? '?' . implode( '&', $keep ) : '' );
}

/** Comparison key of a page URL (never output): no scheme, no www., no trailing slash. */
function cmp_check_page_key( string $url ): string {
	$u = (string) preg_replace( '#^https?://#', '', $url );
	if ( 0 === strpos( $u, 'www.' ) ) {
		$u = substr( $u, 4 );
	}
	if ( 1 === preg_match( '#^([^/?]*)([^?]*)(\?.*)?$#s', $u, $m ) ) {
		$u = $m[1] . rtrim( $m[2], '/' ) . ( isset( $m[3] ) ? $m[3] : '' );
	}
	return $u;
}

/* ------------------------------------------------------------------
 * Sitemap reader (regex only, bounded).
 * ------------------------------------------------------------------ */

/**
 * Read a sitemap without libxml: array( 'index' => sub-sitemap URLs,
 * 'urls' => page URLs, 'seen' => <loc> looked at, 'count' => <loc> in the
 * document ). Only the first 1 MB is read and only the first $max <loc>
 * (200) are looked at, each at most 2048 characters; the rest are counted.
 * Entities: only the XML / HTML named and numeric ones are decoded; nothing
 * is ever loaded. Linear in the size of the document. Never throws.
 */
function cmp_check_parse_sitemap( string $xml, ?int $max = null ): array {
	$lim = cmp_check_discover_limits();
	$max = null === $max ? $lim['max_map_urls'] : max( 0, $max );
	$res = array(
		'index' => array(),
		'urls'  => array(),
		'seen'  => 0,
		'count' => 0,
	);
	$xml = substr( $xml, 0, $lim['max_map_bytes'] );
	if ( '' === trim( $xml ) ) {
		return $res;
	}
	if ( 1 === preg_match( '/<(?:[a-z0-9_-]++:)?sitemapindex\b/i', $xml ) ) {
		$key = 'index';
	} elseif ( 1 === preg_match( '/<(?:[a-z0-9_-]++:)?urlset\b/i', $xml ) ) {
		$key = 'urls';
	} else {
		return $res;
	}
	$count        = preg_match_all( '/<(?:[a-z0-9_-]++:)?loc>/i', $xml );
	$res['count'] = is_int( $count ) ? $count : 0;
	$n            = (int) $lim['max_loc_bytes'];
	// Possessive everywhere and no overlap between the whitespace and the value: no backtracking,
	// whatever pcre.backtrack_limit is (an unclosed <loc> full of spaces costs one linear scan).
	$re           = '#<(?:[a-z0-9_-]++:)?loc>(?:\s*+<!\[CDATA\[([^\]<]{0,' . $n . '}+)\]\]>\s*+|([^<]{0,' . $n . '}+))</(?:[a-z0-9_-]++:)?loc>#i';
	$pos          = 0;
	$locs         = array();
	while ( $res['seen'] < $max ) {
		$ok = preg_match( $re, $xml, $m, PREG_OFFSET_CAPTURE, $pos );
		if ( 1 !== $ok ) {
			break; // No more <loc>, or a PCRE error (false): this sitemap ends here, never a retry loop.
		}
		$pos = $m[0][1] + strlen( $m[0][0] );
		$res['seen']++;
		$v = isset( $m[2] ) && '' !== $m[2][0] ? $m[2][0] : ( isset( $m[1] ) ? $m[1][0] : '' );
		$v = trim( html_entity_decode( $v, ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
		if ( 1 === preg_match( '#^https?://#i', $v ) ) {
			$locs[] = $v;
		}
	}
	$res[ $key ] = $locs;
	return $res;
}

/* ------------------------------------------------------------------
 * Discovery.
 * ------------------------------------------------------------------ */

/**
 * Is a sitemap URL a media / attachment sitemap by its file name (WordPress,
 * Yoast, Rank Math...: attachment-sitemap.xml, image-sitemap1.xml,
 * wp-sitemap-posts-attachment-1.xml, video-sitemap.xml)? Its <loc> are files.
 */
function cmp_check_media_sitemap( string $url ): bool {
	$path = (string) preg_replace( '#[?\#].*$#s', '', $url );
	$file = strtolower( (string) substr( $path, (int) strrpos( $path, '/' ) + 1 ) );
	return 1 === preg_match( '/attachment|image|media|video/', $file );
}

/** Sitemaps of an index in reading order: page sitemaps, then posts, then the rest; media ones dropped. */
function cmp_check_order_sitemaps( array $locs ): array {
	$rank = array();
	foreach ( array_values( $locs ) as $i => $u ) {
		if ( ! is_string( $u ) || cmp_check_media_sitemap( $u ) ) {
			continue;
		}
		$file  = strtolower( (string) substr( $u, (int) strrpos( $u, '/' ) + 1 ) );
		$r     = 1 === preg_match( '/(?:^|[^a-z])pages?(?:[^a-z]|$)/', $file ) ? 0 : ( 1 === preg_match( '/(?:^|[^a-z])posts?(?:[^a-z]|$)/', $file ) ? 1 : 2 );
		$rank[] = array( $r, $i, $u );
	}
	sort( $rank );
	return array_column( $rank, 2 );
}

/**
 * Sitemap pages in checking order: fewer path segments first (stable), and
 * at most 3 in a row from one first path segment while another is waiting, so
 * that 40 entries under /toolkit/ do not crowd out /despre/ or /contact/.
 * Quadratic in the worst case, on at most 600 entries (3 sitemaps x 200).
 */
function cmp_check_order_pages( array $urls ): array {
	$rows = array();
	foreach ( array_values( $urls ) as $i => $u ) {
		$path   = 1 === preg_match( '#^https?://[^/?]+([^?]*)#', (string) $u, $m ) ? $m[1] : '/';
		$segs   = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
		$rows[] = array( count( $segs ), $i, isset( $segs[0] ) ? strtolower( $segs[0] ) : '', $u );
	}
	usort(
		$rows,
		function ( $a, $b ) {
			return $a[0] === $b[0] ? $a[1] - $b[1] : $a[0] - $b[0];
		}
	);
	// Greedy pass over the depth order: after 3 in a row from one first segment, the next
	// entry from another segment goes first (if there is one), then the order resumes.
	$out  = array();
	$run  = '';
	$runs = 0;
	while ( $rows ) {
		$pick = 0;
		if ( $runs >= 3 ) {
			foreach ( $rows as $i => $r ) {
				if ( $r[2] !== $run ) {
					$pick = $i;
					break;
				}
			}
		}
		$r = $rows[ $pick ];
		array_splice( $rows, $pick, 1 );
		$out[] = $r[3];
		$runs  = $r[2] === $run ? $runs + 1 : 1;
		$run   = $r[2];
	}
	return $out;
}

/** The body of a successful (2xx) small fetch, or null. */
function cmp_check_discover_body( $r ): ?string {
	if ( ! is_array( $r ) || empty( $r['ok'] ) || ! isset( $r['status'], $r['body'] ) || ! is_string( $r['body'] ) ) {
		return null;
	}
	$st = (int) $r['status'];
	return $st >= 200 && $st <= 299 ? $r['body'] : null;
}

/**
 * The pages to check.
 *
 * @param string     $home_url    The homepage as fetched (final URL after redirects).
 * @param string     $home_html   Its HTML (for the link fallback; the first 512 KB are read).
 * @param callable   $fetch_small url => cmp_check_fetch() result, for robots.txt and
 *                                sitemaps. It must be the guarded fetch (it is called
 *                                only with addresses already on the site's hosts).
 * @param array|null $ports       Allowed ports (tests only); default 80 and 443.
 * @param float|null $until       microtime() after which no request or parse is started.
 * @return array array( 'urls' => list of at most 10 URLs, the homepage first,
 *               'candidates' => up to 200 in checking order: the homepage, its own
 *               links (document order), then the sitemap by depth, round-robin by
 *               first path segment (backfill when a page turns out not to be one),
 *               'parse_seconds' => time spent parsing,
 *               'total' => pages found (homepage included; the <loc> of a sitemap
 *               beyond the first 200 are counted, not checked) or null when nothing
 *               was found, 'source' => 'sitemap'|'links'|'none' ).
 */
function cmp_check_discover( string $home_url, string $home_html, callable $fetch_small, ?array $ports = null, ?float $until = null ): array {
	$lim     = cmp_check_discover_limits();
	$parse_t = 0.0; // Time spent parsing (sitemaps, homepage links): the caller counts it as analysis time.
	$out     = function ( array $list, $total, string $source ) use ( &$parse_t, $lim ) {
		$list = array_values( $list );
		return array(
			'urls'          => array_slice( $list, 0, $lim['max_pages'] ),
			'candidates'    => array_slice( $list, 0, $lim['max_candidates'] ),
			'total'         => $total,
			'source'        => $source,
			'parse_seconds' => $parse_t,
		);
	};
	$home = cmp_check_normalize_url( $home_url, $ports );
	if ( false === $home ) {
		return $out( array(), null, 'none' );
	}
	$late         = function () use ( $until ) {
		return null !== $until && microtime( true ) >= $until;
	};
	$hosts = cmp_check_site_hosts( $home );
	if ( 1 !== preg_match( '#^https?://[^/?]+#', $home, $om ) ) {
		$hosts = array();
	}
	$origin = isset( $om[0] ) ? $om[0] : '';

	$pages = array( cmp_check_page_key( $home ) => $home ); // Unique pages in order; the homepage first.
	$extra = 0; // <loc> counted but not looked at.
	$add   = function ( $raw, $base ) use ( &$pages, $hosts, $ports ) {
		if ( ! is_string( $raw ) ) {
			return;
		}
		$abs = cmp_check_resolve_location( $base, $raw );
		$u   = false === $abs ? false : cmp_check_site_url( $abs, $hosts, $ports, true );
		if ( false !== $u ) {
			$k = cmp_check_page_key( $u );
			if ( ! isset( $pages[ $k ] ) ) {
				$pages[ $k ] = $u;
			}
		}
	};
	$read = function ( array $map, $base ) use ( &$pages, &$extra, $add ) {
		$before = count( $pages );
		foreach ( $map['urls'] as $loc ) {
			$add( $loc, $base );
		}
		$extra += max( 0, $map['count'] - $map['seen'] );
		return count( $pages ) > $before;
	};

	// 1. Sitemaps: robots.txt first, then the well-known names.
	$tops     = array();
	$robots_n = 0;
	if ( $hosts && ! $late() ) {
		$robots = cmp_check_discover_body( call_user_func( $fetch_small, $origin . '/robots.txt' ) );
		if ( null !== $robots && preg_match_all( '/^[ \t]*+sitemap[ \t]*+:[ \t]*+(\S{1,2048}+)/im', substr( $robots, 0, $lim['max_map_bytes'] ), $rm ) ) {
			foreach ( array_slice( $rm[1], 0, $lim['max_robots'] ) as $loc ) {
				$abs = cmp_check_resolve_location( $origin . '/robots.txt', $loc );
				$u   = false === $abs ? false : cmp_check_site_url( $abs, $hosts, $ports, false );
				if ( false !== $u && ! in_array( $u, $tops, true ) && ! cmp_check_media_sitemap( $u ) ) {
					$tops[] = $u;
					$robots_n++;
				}
			}
		}
		foreach ( array( '/sitemap.xml', '/sitemap_index.xml', '/wp-sitemap.xml' ) as $p ) {
			if ( ! in_array( $origin . $p, $tops, true ) ) {
				$tops[] = $origin . $p;
			}
		}
	}
	$urlsets = 0;
	$tries   = 0;
	$indexed = false;
	$seen    = array();
	$found   = false; // A sitemap gave at least one page.
	foreach ( $tops as $i => $map_url ) {
		if ( $urlsets >= $lim['max_urlsets'] || $tries >= $lim['max_top_tries'] || $late() ) {
			break;
		}
		if ( $i >= $robots_n && ( $found || $indexed ) ) {
			break; // The well-known names are alternatives of one sitemap.
		}
		$tries++;
		$seen[ $map_url ] = true;
		$body             = cmp_check_discover_body( call_user_func( $fetch_small, $map_url ) );
		if ( null === $body || $late() ) {
			continue;
		}
		$t0       = microtime( true );
		$map      = cmp_check_parse_sitemap( $body );
		$parse_t += microtime( true ) - $t0;
		if ( $map['urls'] ) {
			$urlsets++;
			$found = $read( $map, $map_url ) || $found;
		} elseif ( $map['index'] && ! $indexed ) {
			$indexed  = true; // One level of index, once.
			$children = 0;
			foreach ( cmp_check_order_sitemaps( $map['index'] ) as $loc ) {
				if ( $urlsets >= $lim['max_urlsets'] || $children >= $lim['max_children'] || $late() ) {
					break;
				}
				$child = cmp_check_site_url( $loc, $hosts, $ports, false );
				if ( false === $child || isset( $seen[ $child ] ) ) {
					continue; // Another host, an IP, another scheme: never requested.
				}
				$seen[ $child ] = true;
				$children++;
				$cbody = cmp_check_discover_body( call_user_func( $fetch_small, $child ) );
				if ( null === $cbody || $late() ) {
					continue;
				}
				$t0       = microtime( true );
				$cmap     = cmp_check_parse_sitemap( $cbody );
				$parse_t += microtime( true ) - $t0;
				if ( $cmap['urls'] ) { // A nested index is not followed.
					$urlsets++;
					$found = $read( $cmap, $child ) || $found;
				}
			}
		}
	}
	$from_map = $pages; // Sitemap pages (homepage first), for the total.

	// 2. The homepage's links (its first 512 KB), in document order: nav, header and footer
	// links are almost always real pages, so they come first among the candidates.
	$pages = array( cmp_check_page_key( $home ) => $home );
	if ( ! $late() ) {
		$t0       = microtime( true );
		$parsed   = cmp_check_parse( substr( $home_html, 0, 524288 ) );
		$parse_t += microtime( true ) - $t0;
		$n        = 0;
		foreach ( isset( $parsed['anchors'] ) && is_array( $parsed['anchors'] ) ? $parsed['anchors'] : array() as $a ) {
			if ( ++$n > $lim['max_links'] ) {
				break;
			}
			if ( is_array( $a ) && isset( $a['href'] ) && is_string( $a['href'] ) && '' !== trim( $a['href'] ) ) {
				$add( trim( $a['href'] ), $home );
			}
		}
	}
	$from_links = $pages;

	// 3. Candidates: the homepage, its links, then the sitemap by path depth (round-robin by
	// first path segment), deduplicated. The total and its source come from the sitemap if any.
	$cands = $from_links;
	if ( $found ) {
		foreach ( cmp_check_order_pages( array_slice( array_values( $from_map ), 1 ) ) as $u ) {
			$k = cmp_check_page_key( $u );
			if ( ! isset( $cands[ $k ] ) ) {
				$cands[ $k ] = $u;
			}
		}
		return $out( $cands, count( $from_map ) + $extra, 'sitemap' );
	}
	if ( count( $from_links ) > 1 ) {
		return $out( $from_links, count( $from_links ), 'links' );
	}
	return $out( array( $home ), null, 'none' );
}
