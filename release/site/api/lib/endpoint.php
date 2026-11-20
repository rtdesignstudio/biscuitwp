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
 * characters) and lang (ro|en, default ro). Response: JSON
 * { status, findings[], cmp, checked_url, engine: "html-v1" }, and for errors
 * also { code, message }. The visitor's input is never echoed back; the URL is
 * neither stored nor logged.
 * PHP 7.4+.
 */

/** Engine id of the results. */
function cmp_check_engine(): string {
	return 'html-v1';
}

/** HTTP status of each error code (checks that ran but could not read the site answer 200). */
function cmp_check_error_status( string $code ): int {
	$map = array(
		'method_not_allowed' => 405,
		'bad_request'        => 400,
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
			'bad_url'            => 'Adresa nu e validă. Folosește o adresă publică, de exemplu exemplu.md, fără port, utilizator sau parolă.',
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
			'bad_url'            => 'This address is not valid. Use a public address such as example.com, without a port, user name or password.',
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
 * The request fields: array( url, lang ) or null for a malformed request.
 * url is '' when missing.
 */
function cmp_check_read_request( string $content_type, string $raw, array $post ) {
	if ( 0 === stripos( ltrim( $content_type ), 'application/json' ) ) {
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
	$lang = isset( $data['lang'] ) ? $data['lang'] : 'ro';
	if ( ! is_string( $url ) ) {
		return null;
	}
	return array(
		'url'  => trim( $url ),
		'lang' => 'en' === $lang ? 'en' : 'ro',
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
 *  2. request fields (400), counter folder not inside the web root (503);
 *  3. URL validation (400): invalid input creates no counter file at all;
 *  4. per-visitor limits (429): every valid attempt of a visitor counts;
 *  5. global volume for all visitors, counting only requests that passed 4:
 *     60 a minute, 2000 a day (503 busy);
 *  6. one of 3 account-wide slots (503 unavailable, Retry-After 10);
 *  7. the fetch, then the analysis.
 *
 * @param array         $req           method, content_type, body (raw), post ($_POST), ip (REMOTE_ADDR),
 *                                     origin (Origin header), sec_fetch_site (Sec-Fetch-Site header),
 *                                     docroot (DOCUMENT_ROOT); missing headers are ''.
 * @param string        $rate_dir      The counter folder.
 * @param string        $services_path data/services.json as copied next to the API.
 * @param int           $now           Current time.
 * @param callable|null $fetcher       url => cmp_check_fetch() result (tests); default cmp_check_fetch().
 * @param array|null    $origins       Allowed origins (tests); default cmp_check_allowed_origins().
 * @return array array( code => HTTP status, headers => extra headers, body => response array ).
 */
function cmp_check_handle( array $req, string $rate_dir, string $services_path, int $now, ?callable $fetcher = null, ?array $origins = null ): array {
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
	if ( cmp_check_dir_inside( $rate_dir, $get( 'docroot' ) ) ) {
		return cmp_check_error( 'unavailable', $lang ); // Counters must never be web-reachable.
	}

	$url = strlen( $in['url'] ) > 2048 ? false : cmp_check_normalize_url( $in['url'] );
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
	$res = null === $fetcher ? cmp_check_fetch( $url ) : call_user_func( $fetcher, $url );
	cmp_check_slot_release( $slot );

	if ( ! is_array( $res ) || empty( $res['ok'] ) ) {
		$code = is_array( $res ) && isset( $res['error'] ) && is_string( $res['error'] ) && '' !== $res['error'] ? $res['error'] : 'fetch_failed';
		if ( ! in_array( $code, array( 'bad_url', 'blocked_address', 'fetch_failed', 'timeout', 'too_large', 'too_many_redirects', 'unavailable' ), true ) ) {
			$code = 'fetch_failed';
		}
		return cmp_check_error( $code, $lang );
	}
	$final   = isset( $res['final_url'] ) && is_string( $res['final_url'] ) && '' !== $res['final_url'] ? $res['final_url'] : $url;
	$status  = isset( $res['status'] ) ? (int) $res['status'] : 0;
	$headers = isset( $res['headers'] ) && is_array( $res['headers'] ) ? $res['headers'] : array();
	$body    = isset( $res['body'] ) && is_string( $res['body'] ) ? $res['body'] : '';

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
	$out                = cmp_check_analyze( $body, $headers, $final, $services, $lang );
	$out['checked_url'] = cmp_check_plain( $final, 2048 );
	$out['engine']      = cmp_check_engine();
	return array(
		'code'    => 200,
		'headers' => array(),
		'body'    => $out,
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
	echo false === $json ? '{"status":"error","code":"unavailable","findings":[],"cmp":null,"checked_url":"","engine":"html-v1"}' : $json; // phpcs:ignore
}

/** Run the endpoint for the current request. */
function cmp_check_main( string $rate_dir ): void {
	// Note: on Linux set_time_limit() counts CPU time, not waiting on DNS or the
	// network; the wall-clock bounds are the fetch budget and the 3 slots.
	@set_time_limit( 30 ); // phpcs:ignore
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
