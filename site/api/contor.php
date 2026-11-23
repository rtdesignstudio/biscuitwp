<?php
/**
 * Consent Manager Pro, "Verifică-ți site-ul": GET /api/contor.php.
 *
 * The public total of successful checks and how many found each kind of
 * problem, {"checks": N, "found": {...}}, shown under the form from 100 on. One number kept in the counter folder outside the web root
 * (lib/ratelimit.php); nothing about any site or visitor. PHP 7.4+.
 */
ini_set( 'display_errors', '0' );
if ( ! defined( 'CMP_CHECK' ) ) {
	define( 'CMP_CHECK', true );
}
require_once __DIR__ . '/lib/check.php'; // The kinds of „Ce găsim cel mai des”.
require_once __DIR__ . '/lib/ratelimit.php';
require_once __DIR__ . '/lib/endpoint.php';

cmp_check_counter_main( defined( 'CMP_CHECK_RATE_DIR' ) ? (string) CMP_CHECK_RATE_DIR : cmp_check_rate_default_dir() );
