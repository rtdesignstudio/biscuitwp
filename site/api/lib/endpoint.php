<?php
if ( ! defined( 'CMP_CHECK' ) ) {
	http_response_code( 404 );
	exit;
}

/**
 * Consent Manager Pro, "Verifică-ți site-ul": the request / response logic of
 * POST /api/verifica.php, kept apart from the superglobals so it can be tested
 * without a web server (cmp_check_handle) and run as is by verifica.php
 * (cmp_check_main).
 *
 * Request: POST, form-encoded or JSON, fields url (required, at most 2048
 * characters), lang (ro|en, default ro) and owner (required: the visitor
 * confirms owning the site or having the owner's permission; JSON 1, "1" or
 * true, form "1"). Response: JSON
 * { status, findings[], cmp, checked_url, engine: "html-v2", pages{}, passed[], top[] },
 * and for errors { status: "error", code, message, findings: [], cmp: null,
 * checked_url, engine }. The visitor's input is never echoed back; the URL is
 * neither stored nor logged.
 * PHP 7.4+.
 */

/** Engine id of the results. */
function cmp_check_engine(): string {
	return 'html-v2';
}

/**
 * Limits of one multi-page check (seconds, pages). The wall-clock budget is
 * kept by deadlines passed to every fetch, not by set_time_limit().
 */
function cmp_check_site_limits(): array {
	return array(
		'total_seconds'    => 30,      // Homepage, robots.txt, sitemaps and pages together.
		'page_seconds'     => 10,      // One page (homepage included), redirects included.
		'discover_seconds' => 10,      // robots.txt and sitemaps together.
		'small_seconds'    => 5,       // One robots.txt or sitemap.
		'small_bytes'      => 1048576, // One robots.txt or sitemap.
		'pages_reserve'    => 5,       // Seconds discovery leaves for the pages at least.
		'concurrency'      => 3,       // Pages fetched at once.
		'max_requests'     => 25,      // HTTP requests per check, redirect hops included.
		'small_redirects'  => 1,       // Redirects of robots.txt / a sitemap.
		'page_redirects'   => 1,       // Redirects of a sub-page (the homepage keeps 3).
		'sub_page_bytes'   => 1048576, // A sub-page is read and analysed up to 1 MB (the homepage 2 MB).
		'analyse_after'    => 25,      // No new page analysed after this many seconds.
		'analyse_seconds'  => 15,      // Analysis time of the whole check (CPU bound).
		'grace_seconds'    => 1,       // Past the deadline, no new analysis at all.
		'resolve_min_seconds' => 3,    // A second host (www.) is resolved only with this much left.
		'max_pages'        => 10,      // Pages counted (checked or unread), the homepage included.
		'analyse_rate'     => 5.5,     // Worst analysis seconds per MB without PCRE JIT (PHP 7.4 measured).
		'analyse_rate_jit' => 1.5,     // The same with PCRE JIT (worst measured about 0.7).
	);
}

/** HTTP status of each error code (checks that ran but could not read the site answer 200). */
function cmp_check_error_status( string $code ): int {
	$map = array(
		'method_not_allowed' => 405,
		'bad_request'        => 400,
		'owner_required'     => 400,
		'bad_url'            => 400,
		'blocked_address'    => 400,
		'rate_limited'       => 429,
		'unavailable'        => 503,
		'busy'               => 503,
		'forbidden'          => 403,
	);
	return isset( $map[ $code ] ) ? $map[ $code ] : 200;
}

/** A short, human message for an error code. $status: the site's HTTP status, for http_status. */
function cmp_check_error_message( string $code, string $lang, int $status = 0 ): string {
	$t = array(
		'ro' => array(
			'method_not_allowed' => 'Verificarea se pornește din formularul de pe pagină.',
			'bad_request'        => 'Scrie adresa site-ului pe care vrei să-l verifici.',
			'owner_required'     => 'Bifează că deții site-ul sau ai permisiunea proprietarului.',
			'bad_url'            => 'Scrie doar adresa site-ului, de exemplu exemplu.md. Verificăm singuri pagina principală și celelalte pagini.',
			'blocked_address'    => 'Adresa duce spre o rețea privată sau rezervată. Putem verifica doar site-uri publice.',
			'fetch_failed'       => 'Nu am putut deschide site-ul. Verifică adresa sau încearcă din nou mai târziu.',
			'timeout'            => 'Site-ul a răspuns prea încet (peste 10 secunde). Încearcă din nou mai târziu.',
			'too_large'          => 'Pagina principală are peste 2 MB, prea mult pentru o verificare automată.',
			'too_many_redirects' => 'Site-ul redirecționează de prea multe ori (peste 3).',
			'http_status'        => 'Site-ul a răspuns cu eroarea HTTP %d, așa că nu am putut citi pagina.',
			'not_html'           => 'Adresa nu duce la o pagină web (HTML).',
			'rate_limited'       => 'Ai făcut multe verificări într-un timp scurt. Mai încearcă peste un minut.',
			'unavailable'        => 'Verificarea nu e disponibilă acum. Încearcă din nou mai târziu.',
			'busy'               => 'Sunt foarte multe verificări în acest moment. Încearcă din nou peste câteva minute.',
			'forbidden'          => 'Verificarea se pornește doar din pagina consent.rt.md.',
		),
		'en' => array(
			'method_not_allowed' => 'Start the check from the form on the page.',
			'bad_request'        => 'Enter the address of the site you want to check.',
			'owner_required'     => "Tick that you own the site or have the owner's permission.",
			'bad_url'            => 'Enter only the site address, for example example.md. We check the homepage and the other pages ourselves.',
			'blocked_address'    => 'This address leads to a private or reserved network. We can only check public sites.',
			'fetch_failed'       => 'We could not open the site. Check the address or try again later.',
			'timeout'            => 'The site answered too slowly (over 10 seconds). Try again later.',
			'too_large'          => 'The homepage is over 2 MB, too much for an automatic check.',
			'too_many_redirects' => 'The site redirects too many times (over 3).',
			'http_status'        => 'The site answered with HTTP error %d, so we could not read the page.',
			'not_html'           => 'This address does not lead to a web page (HTML).',
			'rate_limited'       => 'You have run many checks in a short time. Try again in a minute.',
			'unavailable'        => 'The check is not available right now. Try again later.',
			'busy'               => 'There are a lot of checks right now. Try again in a few minutes.',
			'forbidden'          => 'The check can only be started from the consent.rt.md page.',
		),
	);
	$lang = 'en' === $lang ? 'en' : 'ro';
	$msg  = isset( $t[ $lang ][ $code ] ) ? $t[ $lang ][ $code ] : $t[ $lang ]['fetch_failed'];
	return 'http_status' === $code ? sprintf( $msg, max( 0, min( 999, $status ) ) ) : $msg;
}

/** An error response. */
function cmp_check_error( string $code, string $lang, string $checked_url = '', int $site_status = 0, array $headers = array() ): array {
	return array(
		'code'    => cmp_check_error_status( $code ),
		'headers' => $headers,
		'body'    => array(
			'status'      => 'error',
			'code'        => $code,
			'message'     => cmp_check_error_message( $code, $lang, $site_status ),
			'findings'    => array(),
			'cmp'         => null,
			'checked_url' => '' === $checked_url ? '' : cmp_check_plain( $checked_url, 2048 ),
			'engine'      => cmp_check_engine(),
		),
	);
}

/** Is the response an HTML page (or untyped)? */
function cmp_check_is_html( array $headers ): bool {
	$ct = isset( $headers['content-type'] ) ? strtolower( trim( (string) $headers['content-type'] ) ) : '';
	return '' === $ct || 0 === strpos( $ct, 'text/html' ) || 0 === strpos( $ct, 'application/xhtml+xml' );
}

/**
 * The request fields: array( url, lang, owner ) or null for a malformed
 * request. url is '' when missing. owner is true only for JSON 1, "1" or true
 * and form "1"; anything else (missing, 0, "on", "yes"...) is false.
 */
function cmp_check_read_request( string $content_type, string $raw, array $post ) {
	$json = 0 === stripos( ltrim( $content_type ), 'application/json' );
	if ( $json ) {
		if ( strlen( $raw ) > 16384 ) {
			return null;
		}
		$data = json_decode( $raw, true, 4 );
		if ( ! is_array( $data ) ) {
			return null;
		}
	} else {
		$data = $post;
	}
	$url  = isset( $data['url'] ) ? $data['url'] : '';
	$lang  = isset( $data['lang'] ) ? $data['lang'] : 'ro';
	$owner = isset( $data['owner'] ) ? $data['owner'] : null;
	if ( ! is_string( $url ) ) {
		return null;
	}
	return array(
		'url'   => trim( $url ),
		'lang'  => 'en' === $lang ? 'en' : 'ro',
		'owner' => '1' === $owner || ( $json && ( 1 === $owner || true === $owner ) ),
	);
}

/** Origins allowed to call the endpoint from a browser. */
function cmp_check_allowed_origins(): array {
	return array( 'https://consent.rt.md' );
}

/**
 * Handle one request.
 *
 * Order (cheapest and most restrictive first; nothing is written for a request
 * refused before its step):
 *  1. method (405), browser cross-site requests (403);
 *  2. request fields (400 bad_request), the owner confirmation (400
 *     owner_required), counter folder not inside the web root (503);
 *  3. URL validation (400 bad_url): a bare site address only
 *     (cmp_check_normalize_input), checked on https; invalid input creates no
 *     counter file at all;
 *  4. per-visitor limits (429): every valid attempt of a visitor counts;
 *  5. global volume for all visitors, counting only requests that passed 4:
 *     30 a minute, 1000 a day (503 busy);
 *  6. one of 3 account-wide slots (503 unavailable, Retry-After 10), held for
 *     the whole multi-page check;
 *  7. the check (cmp_check_site): homepage, page discovery, up to 9 more pages,
 *     the analysis of each page and the aggregate.
 *
 * @param array         $req           method, content_type, body (raw), post ($_POST), ip (REMOTE_ADDR),
 *                                     origin (Origin header), sec_fetch_site (Sec-Fetch-Site header),
 *                                     docroot (DOCUMENT_ROOT); missing headers are ''.
 * @param string        $rate_dir      The counter folder.
 * @param string        $services_path data/services.json as copied next to the API.
 * @param int           $now           Current time.
 * @param callable|null $fetcher       Tests: url => cmp_check_fetch()-like result, used for every
 *                                     request of the check (no network); default the guarded fetch.
 * @param array|null    $origins       Allowed origins (tests); default cmp_check_allowed_origins().
 * @param array|null    $net           Tests only: resolver, ports, loopback for the guarded fetch
 *                                     (the local fixture), and total_seconds / page_seconds /
 *                                     small_seconds, which can only lower the limits. Never
 *                                     passed by the endpoint.
 * @return array array( code => HTTP status, headers => extra headers, body => response array ).
 */
function cmp_check_handle( array $req, string $rate_dir, string $services_path, int $now, ?callable $fetcher = null, ?array $origins = null, ?array $net = null ): array {
	$lang = 'ro';
	$get  = function ( $k ) use ( $req ) {
		return isset( $req[ $k ] ) && is_string( $req[ $k ] ) ? $req[ $k ] : '';
	};
	if ( 'POST' !== $get( 'method' ) ) {
		return cmp_check_error( 'method_not_allowed', $lang, '', 0, array( 'Allow' => 'POST' ) );
	}
	$in = cmp_check_read_request( $get( 'content_type' ), $get( 'body' ), isset( $req['post'] ) && is_array( $req['post'] ) ? $req['post'] : array() );
	if ( null !== $in ) {
		$lang = $in['lang'];
	}
	// Browsers say where a request comes from; only our own page may start a check.
	$site = strtolower( trim( $get( 'sec_fetch_site' ) ) );
	if ( '' !== $site && 'same-origin' !== $site ) {
		return cmp_check_error( 'forbidden', $lang );
	}
	$origin = trim( $get( 'origin' ) );
	if ( '' !== $origin && ! in_array( $origin, null === $origins ? cmp_check_allowed_origins() : $origins, true ) ) {
		return cmp_check_error( 'forbidden', $lang );
	}
	if ( null === $in || '' === $in['url'] ) {
		return cmp_check_error( 'bad_request', $lang );
	}
	// The visitor must confirm owning the site (or the owner's permission): before any counter.
	if ( true !== $in['owner'] ) {
		return cmp_check_error( 'owner_required', $lang );
	}
	if ( cmp_check_dir_inside( $rate_dir, $get( 'docroot' ) ) ) {
		return cmp_check_error( 'unavailable', $lang ); // Counters must never be web-reachable.
	}

	// The visitor gives a bare site address only; the pages are found by the check.
	$url = strlen( $in['url'] ) > 2048 ? false : cmp_check_normalize_input( $in['url'] );
	if ( false === $url ) {
		return cmp_check_error( 'bad_url', $lang );
	}

	$rate = cmp_check_rate_check( $get( 'ip' ), $rate_dir, $now );
	if ( 'limited' === $rate ) {
		return cmp_check_error( 'rate_limited', $lang, '', 0, array( 'Retry-After' => '60' ) );
	}
	if ( 'ok' !== $rate ) {
		return cmp_check_error( 'unavailable', $lang );
	}

	// Counted only after the per-IP limit: one client cannot use up everybody's budget.
	$global = cmp_check_rate_global( $rate_dir, $now );
	if ( 'limited' === $global ) {
		return cmp_check_error( 'busy', $lang, '', 0, array( 'Retry-After' => '60' ) );
	}
	if ( 'ok' !== $global ) {
		return cmp_check_error( 'unavailable', $lang );
	}

	$slot = cmp_check_slot_acquire( $rate_dir );
	if ( false === $slot ) {
		return cmp_check_error( 'unavailable', $lang, '', 0, array( 'Retry-After' => '10' ) );
	}
	register_shutdown_function( 'cmp_check_slot_release', $slot ); // Also on a fatal error or a time limit.
	$res = cmp_check_site( $url, $lang, $services_path, $fetcher, null === $net ? array() : $net );
	cmp_check_slot_release( $slot ); // One slot for the whole multi-page check.
	return $res;
}

/** A fetch result with the expected shape (an injected fetcher's answer is checked too). */
function cmp_check_norm_fetch( $r, string $url ): array {
	if ( ! is_array( $r ) || empty( $r['ok'] ) ) {
		$code   = is_array( $r ) && isset( $r['error'] ) && is_string( $r['error'] ) && '' !== $r['error'] ? $r['error'] : 'fetch_failed';
		$detail = is_array( $r ) && isset( $r['detail'] ) && is_string( $r['detail'] ) ? $r['detail'] : '';
		return cmp_check_fetch_result( false, $code, $url, 0, array(), '', $detail ); // detail stays internal.
	}
	return cmp_check_fetch_result(
		true,
		'',
		isset( $r['final_url'] ) && is_string( $r['final_url'] ) && '' !== $r['final_url'] ? $r['final_url'] : $url,
		isset( $r['status'] ) ? (int) $r['status'] : 0,
		isset( $r['headers'] ) && is_array( $r['headers'] ) ? $r['headers'] : array(),
		isset( $r['body'] ) && is_string( $r['body'] ) ? $r['body'] : ''
	);
}

/**
 * Run one check of a validated URL: the homepage, then the pages found by
 * cmp_check_discover() (at most 10 with the homepage), the analysis of each
 * and the aggregate. Every request goes through the guarded fetch with the
 * connection pinned to the checked address of its host, resolved once per
 * host for the whole check; robots.txt, sitemaps and pages may only be on the
 * homepage's host or its www. variant (a redirect elsewhere is not followed).
 *
 * Bounds (cmp_check_site_limits):
 * - wall time: 30 s in all, 10 s per page, 3 pages at once; a page not read
 *   in time is listed in pages.failed with 'timeout';
 * - requests: 25 HTTP requests per check, redirect hops included ('limit');
 *   the homepage follows up to 3 redirects, everything else 1;
 * - CPU: a sub-page is read up to 1 MB (bigger: 'too_large'). Its analysis
 *   cost is projected as max(slowest page so far, worst seconds per MB x its
 *   size); it is analysed only if elapsed + projection stays within 25 s and
 *   the analysis time so far (homepage, sitemap and link parsing included) +
 *   projection within 15 s; else the check stops there (pages.stopped
 *   'time'): our limit, so the page is not unread and nothing more is fetched.
 *   Sub-pages are fetched only until 25 s.
 * - Not pages: a candidate whose answer is not HTML (cut after its headers:
 *   'not_page'), that redirects to a file (an extension such as .png / .pdf,
 *   or /wp-content/uploads/: not followed, one request) or that leads to
 *   another host is not a page of the site: it is not counted, not reported,
 *   and the next candidate of the sitemap / links (up to 200) takes its place.
 * - Stopped early: pages never attempted because the 25 requests or the time
 *   ran out are not unread (like the 10-page cap); pages.stopped is
 *   'requests' or 'time' and pages.capped is true. Only attempted pages that
 *   failed (transfer timeout, HTTP error, too large...) are unread.
 *
 * @return array The cmp_check_handle() response.
 */
function cmp_check_site( string $url, string $lang, string $services_path, ?callable $fetcher, array $net ): array {
	$lim = cmp_check_site_limits();
	foreach ( array( 'total_seconds', 'page_seconds', 'small_seconds', 'analyse_after', 'analyse_seconds', 'max_requests' ) as $k ) {
		if ( isset( $net[ $k ] ) && is_numeric( $net[ $k ] ) ) {
			$lim[ $k ] = max( 0.1, min( (float) $net[ $k ], $lim[ $k ] ) ); // Tests: only lower.
		}
	}
	$start    = microtime( true );
	$deadline = $start + $lim['total_seconds'];
	$resolver = isset( $net['resolver'] ) && is_callable( $net['resolver'] ) ? $net['resolver'] : null;
	$ports    = isset( $net['ports'] ) && is_array( $net['ports'] ) ? $net['ports'] : null;
	$loopback = ! empty( $net['loopback'] );
	// Shared by every request of the check: one resolution per host, one request budget.
	$cache = (object) array(
		'ips'           => array(),
		'requests_left' => (int) $lim['max_requests'],
	);

	// One request. $small: robots.txt or a sitemap (1 MB, 5 s, 1 redirect).
	$one = function ( $u, $small, $hosts, $until ) use ( $fetcher, $resolver, $ports, $loopback, $cache, $lim ) {
		if ( microtime( true ) >= $until ) {
			return cmp_check_fetch_result( false, 'timeout', $u, 0, array(), '', 'not_started' );
		}
		if ( null !== $fetcher ) {
			if ( ! cmp_check_fetch_take( $cache ) ) {
				return cmp_check_fetch_result( false, 'limit', $u );
			}
			return cmp_check_norm_fetch( call_user_func( $fetcher, $u ), $u );
		}
		$opts = array(
			'deadline' => $until,
			'cache'    => $cache,
			'hosts'    => $hosts,
		);
		if ( ! $small ) {
			$opts['html_only'] = true; // An image or a PDF is cut after its headers.
		}
		if ( $small ) {
			$opts['max_bytes']     = $lim['small_bytes'];
			$opts['max_redirects'] = $lim['small_redirects'];
			$opts['accept']        = 'application/xml,text/xml;q=0.9,text/plain;q=0.8,*/*;q=0.1';
		}
		return cmp_check_fetch( $u, $resolver, $ports, $loopback, (int) ceil( $small ? $lim['small_seconds'] : $lim['page_seconds'] ), $opts );
	};

	// 1. The homepage: up to 3 redirects (each re-validated), to any public host, as in v1.
	$res = $one( $url, false, null, min( $deadline, $start + $lim['page_seconds'] ) );
	if ( empty( $res['ok'] ) ) {
		$code = isset( $res['error'] ) && is_string( $res['error'] ) && '' !== $res['error'] ? $res['error'] : 'fetch_failed';
		if ( 'not_page' === $code ) {
			return cmp_check_error( 'not_html', $lang ); // The address is a file (image, PDF...), not a web page.
		}
		// The visitor's input was valid: a bad URL here comes from a redirect of the site.
		if ( ! in_array( $code, array( 'blocked_address', 'fetch_failed', 'timeout', 'too_large', 'too_many_redirects', 'unavailable' ), true ) ) {
			$code = 'fetch_failed';
		}
		return cmp_check_error( $code, $lang );
	}
	$final = cmp_check_normalize_url( $res['final_url'], $ports );
	$fhost = false === $final ? '' : cmp_check_url_host( $final );
	if ( '' === $fhost || '[' === $fhost[0] || false !== filter_var( $fhost, FILTER_VALIDATE_IP ) ) {
		// The site redirected to a bare IP address: not a site we check (and nothing is discovered there).
		return cmp_check_error( 'fetch_failed', $lang );
	}
	$status  = (int) $res['status'];
	$headers = $res['headers'];
	$body    = $res['body'];
	if ( $status < 200 || $status > 299 ) {
		// An anti-bot page is explained by the analysis; any other error status is just reported.
		if ( ! ( cmp_check_is_html( $headers ) && cmp_check_is_challenge( $body ) ) ) {
			return cmp_check_error( 'http_status', $lang, $final, $status );
		}
	} elseif ( ! cmp_check_is_html( $headers ) ) {
		return cmp_check_error( 'not_html', $lang, $final );
	}
	$services = cmp_check_load_services( $services_path );
	if ( ! $services ) {
		return cmp_check_error( 'unavailable', $lang ); // Never a "clean" result without the library.
	}
	$t0          = microtime( true );
	$home        = cmp_check_analyze( $body, $headers, $final, $services, $lang );
	$spent       = microtime( true ) - $t0; // Time spent analysing.
	$slowest     = $spent;
	$home['url'] = $final;
	$pages       = array(
		'checked'      => array( cmp_check_plain( $final, 2048 ) ),
		'failed'       => array(),
		'total'        => null,
		'total_source' => 'none',
		'capped'       => false,
		'stopped'      => null,
	);
	if ( 'error' === $home['status'] ) {
		// The homepage itself could not be read (anti-bot page, empty page): no other page is tried.
		return cmp_check_site_response( array( $home ), 0, $final, $pages, $lang );
	}

	// 2. Which pages: robots.txt, sitemaps, else the homepage's links (same host only).
	$hosts = cmp_check_site_hosts( $final );
	$until = min( microtime( true ) + $lim['discover_seconds'], $deadline - min( $lim['pages_reserve'], $lim['total_seconds'] / 3 ) );
	$found = array(
		'candidates'    => array( $final ),
		'total'         => null,
		'source'        => 'none',
		'parse_seconds' => 0.0,
	);
	if ( $hosts && $until > microtime( true ) ) {
		$found = cmp_check_discover(
			$final,
			$body,
			function ( $u ) use ( $one, $hosts, $until ) {
				return $one( $u, true, $hosts, $until );
			},
			$ports,
			$until
		);
	}
	$spent                += (float) $found['parse_seconds']; // Parsing counts like analysis.
	$pages['total']        = $found['total'];
	$pages['total_source'] = $found['source'];
	$queue                 = array_values( array_slice( $found['candidates'], 1 ) );

	// Sub-pages are only fetched while they can still be analysed.
	$page_deadline = min( $deadline, $start + $lim['analyse_after'] );
	if ( null === $fetcher ) {
		// Resolve the site's hosts (bare and www.) before curl_multi: a lookup inside the loop would
		// stall the running transfers. A host not resolved yet with under 3 s left is not resolved.
		foreach ( $hosts as $h ) {
			if ( ! array_key_exists( $h, $cache->ips ) && false === filter_var( $h, FILTER_VALIDATE_IP ) && $page_deadline - microtime( true ) >= $lim['resolve_min_seconds'] ) {
				cmp_check_pick_address( 'http://' . $h . '/', $resolver, $loopback, $page_deadline, $cache );
			}
		}
	}

	// 3. The other pages, 3 at a time, in rounds: a candidate that is not an HTML page (an
	// image listed in the sitemap) or that leads to another host is not a page of the site;
	// it is skipped and the next candidate takes its place, until 10 pages, the request
	// budget or the time runs out.
	$results = array( $home );
	$seen    = array( cmp_check_page_key( $final ) => true );
	$rate    = ini_get( 'pcre.jit' ) ? $lim['analyse_rate_jit'] : $lim['analyse_rate']; // Seconds per MB, worst case seen.
	$real    = 1; // Pages counted: the homepage, then each checked or failed page.
	$stopped = null; // 'requests' | 'time' when pages were left unattempted or unanalysed.
	$no_more = false; // The analysis budget is used up: fetch nothing more.
	while ( ! $no_more && $queue && $real < $lim['max_pages'] && $cache->requests_left > 0 && microtime( true ) < $page_deadline - 0.05 ) {
		$batch = array_splice( $queue, 0, $lim['max_pages'] - $real );
		$got   = array();
		if ( null === $fetcher ) {
			$fetch = array();
			foreach ( $batch as $i => $u ) {
				$h = cmp_check_url_host( $u );
				if ( ! array_key_exists( $h, $cache->ips ) && false === filter_var( $h, FILTER_VALIDATE_IP ) ) {
					$got[ $i ] = cmp_check_fetch_result( false, 'timeout', $u, 0, array(), '', 'not_started' ); // Its host was not resolved in time.
					continue;
				}
				$fetch[ $i ] = $u;
			}
			$got += $fetch ? cmp_check_fetch_many(
				$fetch,
				array(
					'resolver'      => $resolver,
					'ports'         => $ports,
					'loopback'      => $loopback,
					'deadline'      => $page_deadline,
					'per_page'      => $lim['page_seconds'],
					'concurrency'   => $lim['concurrency'],
					'cache'         => $cache,
					'hosts'         => $hosts,
					'max_bytes'     => $lim['sub_page_bytes'],
					'max_redirects' => $lim['page_redirects'],
					'html_only'     => true,
				)
			) : array();
		} else {
			foreach ( $batch as $i => $u ) {
				$got[ $i ] = $one( $u, false, $hosts, $page_deadline );
			}
		}

		// 4. Analyse what was read, within the time and CPU budget; the rest is listed with a short reason.
		foreach ( $batch as $i => $u ) {
			$r    = isset( $got[ $i ] ) ? cmp_check_norm_fetch( $got[ $i ], $u ) : cmp_check_fetch_result( false, 'timeout', $u );
			$code = '';
			$at   = $u;
			if ( empty( $r['ok'] ) ) {
				$code = in_array( $r['error'], array( 'timeout', 'too_large', 'too_many_redirects', 'other_host', 'not_page', 'blocked_address', 'bad_url', 'unavailable', 'limit' ), true ) ? $r['error'] : 'fetch_failed';
			} else {
				$fu = cmp_check_normalize_url( $r['final_url'], $ports );
				if ( false === $fu || ! cmp_check_host_allowed( $fu, $hosts ) ) {
					$code = 'other_host'; // Never analysed as this site's page.
				} else {
					$at = $fu;
					$st = (int) $r['status'];
					if ( $st < 200 || $st > 299 ) {
						$code = (string) max( 0, min( 999, $st ) );
					} elseif ( ! cmp_check_is_html( $r['headers'] ) ) {
						$code = 'not_page'; // An injected fetcher's answer: the same rule as the header check.
					} elseif ( strlen( $r['body'] ) > $lim['sub_page_bytes'] ) {
						$code = 'too_large'; // A sub-page is analysed up to 1 MB only.
					}
				}
			}
			if ( 'not_page' === $code || 'other_host' === $code ) {
				continue; // Not a page of this site: not counted, the next candidate replaces it.
			}
			// Never attempted (request budget or time used up): like the 10-page cap, not unread.
			if ( 'limit' === $code ) {
				$stopped = 'requests';
				continue;
			}
			if ( 'timeout' === $code && 'not_started' === $r['detail'] ) {
				$stopped = null === $stopped ? 'time' : $stopped;
				continue;
			}
			if ( '' === $code ) {
				$key = cmp_check_page_key( $at );
				if ( isset( $seen[ $key ] ) ) {
					continue; // Redirected to a page already checked: replaced too.
				}
				// Projected cost: the slowest page so far, or the worst rate seen per MB for this size.
				$now  = microtime( true );
				$proj = max( $slowest, $rate * strlen( $r['body'] ) / 1048576 );
				if ( $now > $deadline + $lim['grace_seconds'] || $now - $start + $proj > $lim['analyse_after'] || $spent + $proj > $lim['analyse_seconds'] ) {
					// Read, but our analysis budget is used up: our limit, not the site's. Like an
					// unattempted page: not unread, the check stopped early; no more pages are fetched.
					$stopped = 'time';
					$no_more = true;
					continue;
				} else {
					$t0      = microtime( true );
					$a       = cmp_check_analyze( $r['body'], $r['headers'], $at, $services, $lang );
					$took    = microtime( true ) - $t0;
					$spent  += $took;
					$slowest = max( $slowest, $took );
					if ( 'error' === $a['status'] ) {
						$code = 'unreadable';
					} else {
						$seen[ $key ]       = true;
						$a['url']           = $at;
						$results[]          = $a;
						$pages['checked'][] = cmp_check_plain( $at, 2048 );
						$real++;
						continue;
					}
				}
			}
			$pages['failed'][] = array(
				'url'  => cmp_check_plain( $u, 2048 ),
				'code' => $code,
			);
			$real++;
		}
	}
	// Pages left when the request budget or the time ran out were never attempted: like the 10-page
	// cap, they are not unread; pages.stopped says why the check stopped early.
	if ( $queue && $real < $lim['max_pages'] && null === $stopped ) {
		$stopped = $cache->requests_left > 0 ? 'time' : 'requests';
	}
	$pages['stopped'] = $real < $lim['max_pages'] ? $stopped : null;
	$pages['capped']  = null !== $pages['stopped'] || ( null !== $pages['total'] && $pages['total'] > $real );
	return cmp_check_site_response( $results, count( $pages['failed'] ), $final, $pages, $lang );
}

/** The 200 response of a check: the aggregate, without anything internal. */
function cmp_check_site_response( array $results, int $unread, string $final, array $pages, string $lang ): array {
	$agg = cmp_check_aggregate( $results, $lang, $unread );
	return array(
		'code'    => 200,
		'headers' => array(),
		'body'    => array(
			'status'      => isset( $agg['status'] ) ? $agg['status'] : 'error',
			'findings'    => isset( $agg['findings'] ) && is_array( $agg['findings'] ) ? $agg['findings'] : array(),
			'cmp'         => isset( $agg['cmp'] ) ? $agg['cmp'] : null,
			'checked_url' => cmp_check_plain( $final, 2048 ),
			'engine'      => cmp_check_engine(),
			'pages'       => $pages,
			'passed'      => isset( $agg['passed'] ) && is_array( $agg['passed'] ) ? $agg['passed'] : array(),
			'top'         => isset( $agg['top'] ) && is_array( $agg['top'] ) ? $agg['top'] : array(),
			'wordpress'   => isset( $agg['wordpress'] ) && true === $agg['wordpress'],
		),
	);
}

/** Send a handled response. */
function cmp_check_send( array $res ): void {
	if ( ! headers_sent() ) {
		http_response_code( (int) $res['code'] );
		header_remove( 'X-Powered-By' );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		foreach ( $res['headers'] as $name => $value ) { // Only fixed names and our own values.
			if ( in_array( $name, array( 'Allow', 'Retry-After' ), true ) ) {
				header( $name . ': ' . $value );
			}
		}
	}
	$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE;
	$json  = json_encode( $res['body'], $flags );
	echo false === $json ? '{"status":"error","code":"unavailable","findings":[],"cmp":null,"checked_url":"","engine":"html-v2"}' : $json; // phpcs:ignore
}

/** Run the endpoint for the current request. */
function cmp_check_main( string $rate_dir ): void {
	// The wall-clock budget (30 s for the whole multi-page check) is kept by the
	// deadlines given to every fetch (cmp_check_site_limits). set_time_limit()
	// is only a backstop: on Linux it counts CPU time, not waiting on DNS or the
	// network. A DNS lookup already started cannot be interrupted from PHP; the
	// one-lookup-per-host cache and the 3 slots bound what slow DNS can cost.
	@set_time_limit( 45 ); // phpcs:ignore
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '';
	$ctype  = isset( $_SERVER['CONTENT_TYPE'] ) ? (string) $_SERVER['CONTENT_TYPE'] : '';
	$raw    = '';
	if ( 'POST' === $method && 0 === stripos( ltrim( $ctype ), 'application/json' ) ) {
		$raw = (string) file_get_contents( 'php://input', false, null, 0, 16385 );
	}
	$res = cmp_check_handle(
		array(
			'method'         => $method,
			'content_type'   => $ctype,
			'body'           => $raw,
			'post'           => $_POST, // phpcs:ignore
			'ip'             => isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '', // Never X-Forwarded-For.
			'origin'         => isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) $_SERVER['HTTP_ORIGIN'] : '',
			'sec_fetch_site' => isset( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ? (string) $_SERVER['HTTP_SEC_FETCH_SITE'] : '',
			'docroot'        => isset( $_SERVER['DOCUMENT_ROOT'] ) ? (string) $_SERVER['DOCUMENT_ROOT'] : '',
		),
		$rate_dir,
		dirname( __DIR__ ) . '/services.json',
		time()
	);
	cmp_check_send( $res );
}
