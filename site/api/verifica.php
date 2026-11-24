<?php
/**
 * BiscuitWP, "Verifică-ți site-ul": POST /api/verifica.php.
 *
 * Checks up to 10 pages of a visitor-given public site, after the visitor
 * confirms owning it: the homepage, then pages from robots.txt / the sitemap
 * or the homepage's links (lib/discover.php), all fetched through the SSRF
 * protections of lib/fetch.php (pinned addresses, same host only, 30 s in
 * all). Counts the check (lib/ratelimit.php: 5 a minute, 50 a day per IP) and
 * returns the aggregate analysis of lib/check.php as JSON. The checked
 * address is neither stored nor logged. PHP 7.4+ with curl.
 */
ini_set( 'display_errors', '0' ); // Never print a warning (and the paths in it) into the JSON.
if ( ! defined( 'CMP_CHECK' ) ) {
	define( 'CMP_CHECK', true );
}
require_once __DIR__ . '/lib/check.php';
require_once __DIR__ . '/lib/fetch.php';
require_once __DIR__ . '/lib/discover.php';
require_once __DIR__ . '/lib/ratelimit.php';
require_once __DIR__ . '/lib/endpoint.php';

cmp_check_main( defined( 'CMP_CHECK_RATE_DIR' ) ? (string) CMP_CHECK_RATE_DIR : cmp_check_rate_default_dir() );
