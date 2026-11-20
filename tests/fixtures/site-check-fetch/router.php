<?php
/**
 * Test-only fixture server for tests/test-site-check-fetch.php, run with
 * `php -S 127.0.0.1:<port> router.php`. Never deployed. Serves redirects,
 * large bodies and slow answers so cmp_check_fetch() can be tested without
 * touching any real site. The port of this server is in $_SERVER['SERVER_PORT'].
 */
$path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$port = (int) $_SERVER['SERVER_PORT'];
$self = 'http://fixture.cmp-check.test:' . $port;

function fx_redirect( $to, $code = 302 ) {
	http_response_code( $code );
	header( 'Location: ' . $to );
	header( 'Content-Type: text/html; charset=utf-8' );
	echo 'moved';
	exit;
}

if ( '/ok' === $path ) {
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'Set-Cookie: _ga=GA1.1.1; Path=/', false );
	header( 'Set-Cookie: session=abc; Path=/; HttpOnly', false );
	$seen = array(
		'ua'     => isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '',
		'cookie' => isset( $_SERVER['HTTP_COOKIE'] ) ? $_SERVER['HTTP_COOKIE'] : '',
		'accept' => isset( $_SERVER['HTTP_ACCEPT'] ) ? $_SERVER['HTTP_ACCEPT'] : '',
		'lang'   => isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '',
		'method' => $_SERVER['REQUEST_METHOD'],
		'enc'    => isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ? $_SERVER['HTTP_ACCEPT_ENCODING'] : '',
	);
	echo '<!doctype html><html><head><title>ok</title></head><body><pre id="seen">' . htmlspecialchars( json_encode( $seen ) ) . '</pre></body></html>';
	exit;
}
if ( '/image' === $path ) {
	header( 'Content-Type: image/png' );
	echo "\x89PNG\r\n\x1a\n";
	exit;
}
if ( '/gzip' === $path || '/gzip-chain' === $path || '/br' === $path ) {
	// Sent whatever Accept-Encoding says: the fetcher must refuse it, not analyse it.
	header( 'Content-Type: text/html' );
	$html = '<html><body>compressed</body></html>';
	if ( '/gzip' === $path ) {
		header( 'Content-Encoding: gzip' );
		echo gzencode( $html );
	} elseif ( '/gzip-chain' === $path ) {
		header( 'Content-Encoding: gzip, gzip' );
		echo gzencode( gzencode( $html ) );
	} else {
		header( 'Content-Encoding: br' );
		echo 'not really brotli';
	}
	exit;
}
if ( '/gzip-raw' === $path || '/zstd-raw' === $path ) {
	// Compressed bytes with no Content-Encoding header.
	header( 'Content-Type: text/html' );
	echo '/gzip-raw' === $path ? gzencode( '<html><body>sneaky</body></html>' ) : "\x28\xb5\x2f\xfd" . str_repeat( "\x00", 32 );
	exit;
}
if ( '/identity' === $path ) {
	header( 'Content-Type: text/html' );
	header( 'Content-Encoding: identity' );
	echo '<html><body>plain</body></html>';
	exit;
}
if ( '/missing' === $path ) {
	http_response_code( 404 );
	header( 'Content-Type: text/html' );
	echo '<html><body>not found</body></html>';
	exit;
}
if ( '/to-metadata' === $path ) {
	fx_redirect( 'http://169.254.169.254:' . $port . '/latest/meta-data/' ); // Port allowed in the test: only the address refuses it.
}
if ( '/to-loopback-mapped' === $path ) {
	fx_redirect( 'http://[::ffff:127.0.0.1]:' . $port . '/ok' );
}
if ( '/to-decimal' === $path ) {
	fx_redirect( 'http://2130706433:' . $port . '/ok' );
}
if ( '/to-octal' === $path ) {
	fx_redirect( 'http://0177.0.0.1:' . $port . '/ok' );
}
if ( '/to-userinfo' === $path ) {
	fx_redirect( 'http://user:pass@fixture.cmp-check.test:' . $port . '/ok' );
}
if ( '/to-file' === $path ) {
	fx_redirect( 'file:///etc/passwd' );
}
if ( '/to-gopher' === $path ) {
	fx_redirect( 'gopher://fixture.cmp-check.test:' . $port . '/_x' );
}
if ( '/to-port' === $path ) {
	fx_redirect( 'http://fixture.cmp-check.test:22/' );
}
if ( '/to-private-name' === $path ) {
	fx_redirect( 'http://internal.cmp-check.test:' . $port . '/ok' );
}
if ( '/relative' === $path ) {
	fx_redirect( 'ok', 301 );
}
if ( '/to-relative-root' === $path ) {
	fx_redirect( '/ok', 303 );
}
if ( preg_match( '#^/chain/(\d+)$#', $path, $m ) ) {
	$n = (int) $m[1];
	if ( $n >= 4 ) {
		header( 'Content-Type: text/html' );
		echo '<html><body>end of chain ' . $n . '</body></html>';
		exit;
	}
	fx_redirect( '/chain/' . ( $n + 1 ), 307 );
}
if ( '/big' === $path ) {
	// 3 MB, streamed without Content-Length.
	header( 'Content-Type: text/html' );
	$chunk = str_repeat( '<p>aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa</p>', 1000 );
	for ( $i = 0; $i < 90; $i++ ) {
		echo $chunk;
		flush();
	}
	exit;
}
if ( '/big-cl' === $path ) {
	header( 'Content-Type: text/html' );
	header( 'Content-Length: 3000000' );
	echo str_repeat( 'a', 3000000 );
	exit;
}
if ( '/slow' === $path ) {
	header( 'Content-Type: text/html' );
	sleep( 4 );
	echo '<html>late</html>';
	exit;
}
http_response_code( 404 );
echo 'no route';
