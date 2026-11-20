<?php
/**
 * Consent Manager Pro, "Verifică-ți site-ul": POST /api/verifica.php.
 *
 * Fetches the homepage of a visitor-given public site (lib/fetch.php: SSRF
 * protections), counts the check (lib/ratelimit.php: 10 a minute, 100 a day
 * per IP) and returns the analysis of lib/check.php as JSON. The checked
 * address is neither stored nor logged. PHP 7.4+ with curl.
 */
ini_set( 'display_errors', '0' ); // Never print a warning (and the paths in it) into the JSON.
if ( ! defined( 'CMP_CHECK' ) ) {
	define( 'CMP_CHECK', true );
}
require_once __DIR__ . '/lib/check.php';
require_once __DIR__ . '/lib/fetch.php';
require_once __DIR__ . '/lib/ratelimit.php';
require_once __DIR__ . '/lib/endpoint.php';

cmp_check_main( defined( 'CMP_CHECK_RATE_DIR' ) ? (string) CMP_CHECK_RATE_DIR : cmp_check_rate_default_dir() );
