<?php
/**
 * Test-only router for the endpoint tests: `php -S 127.0.0.1:<port> -t site/api
 * endpoint-router.php`. Never deployed. It points the rate-limit counters at the
 * test's temporary folder (CMP_TEST_RATE_DIR, set by the test for this server
 * process only) and then runs the real site/api/verifica.php. Every other path
 * is left to the built-in server, so site/api/lib/*.php is requested as a
 * browser would request it.
 */
$path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( '/verifica.php' === $path ) {
	define( 'CMP_CHECK_RATE_DIR', (string) getenv( 'CMP_TEST_RATE_DIR' ) );
	require __DIR__ . '/../../../site/api/verifica.php';
	return true;
}
return false;
