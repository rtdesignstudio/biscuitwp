<?php
/**
 * Test-only fixture server for tests/test-site-check-discover.php, run with
 * `php -S 127.0.0.1:<port> router.php`. Never deployed. The slow pages are
 * listed on a second server (CMP_FX_SLOW_PORT, same router, same host name,
 * another port the test allows), so they never hold the quick ones: the
 * built-in server's workers are not reliable on every PHP build. One server plays several
 * small sites, told apart by the Host header (<site>.cmp-check.test or
 * www.<site>.cmp-check.test); the test's injected resolver maps those names
 * to 127.0.0.1. Every request is appended to the CMP_FX_LOG file as
 * "host path", so the test can prove what was (not) requested.
 */
$path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$port = (int) $_SERVER['SERVER_PORT'];
$host = strtolower( (string) preg_replace( '/:\d+$/', '', isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '' ) );
$log  = (string) getenv( 'CMP_FX_LOG' );
if ( '' !== $log ) {
	file_put_contents( $log, $host . ' ' . $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND | LOCK_EX );
}
$site = (string) preg_replace( '/^www\./', '', $host );
$name = (string) strstr( $site . '.', '.', true );
$base = 'http://' . $site . ':' . $port;
$www  = 'http://www.' . $site . ':' . $port;
$slow = 'http://' . $site . ':' . (int) getenv( 'CMP_FX_SLOW_PORT' );

function fx_html( $body, $code = 200 ) {
	http_response_code( $code );
	header( 'Content-Type: text/html; charset=utf-8' );
	// The policy is announced with rel="privacy-policy" (a <link>, not a link to follow), so the
	// homepage-links-first discovery does not pick a /privacy page the fixture does not serve.
	echo '<!doctype html><html><head><title>t</title><link rel="privacy-policy" href="/privacy"></head><body>' . $body . '</body></html>';
	exit;
}
function fx_xml( $xml ) {
	header( 'Content-Type: application/xml' );
	echo '<?xml version="1.0" encoding="UTF-8"?>' . $xml;
	exit;
}
function fx_urlset( array $locs ) {
	$x = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
	foreach ( $locs as $l ) {
		$x .= '<url><loc>' . htmlspecialchars( $l, ENT_XML1 ) . '</loc></url>';
	}
	fx_xml( $x . '</urlset>' );
}
function fx_index( array $locs ) {
	$x = '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
	foreach ( $locs as $l ) {
		$x .= '<sitemap><loc>' . htmlspecialchars( $l, ENT_XML1 ) . '</loc></sitemap>';
	}
	fx_xml( $x . '</sitemapindex>' );
}
function fx_404() {
	http_response_code( 404 );
	header( 'Content-Type: text/html' );
	echo '<html><body>not found</body></html>';
	exit;
}
function fx_redirect( $to ) {
	http_response_code( 302 );
	header( 'Location: ' . $to );
	echo 'moved';
	exit;
}

if ( 's50' === $name ) {
	// A sitemap of 50 pages (the homepage first); /p/3 loads a tracker.
	if ( '/sitemap.xml' === $path ) {
		$l = array( $base . '/' );
		for ( $i = 1; $i <= 49; $i++ ) {
			$l[] = $base . '/p/' . $i;
		}
		fx_urlset( $l );
	}
	if ( '/' === $path || preg_match( '#^/p/\d+$#', $path ) ) {
		fx_html( '/p/3' === $path ? '<script async src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>' : '<p>page</p>' );
	}
	fx_404();
}

if ( 'idx' === $name ) {
	// No /sitemap.xml; an index of 5 sitemaps (and one on another host).
	if ( '/sitemap_index.xml' === $path ) {
		fx_index( array( 'http://evil.cmp-check.test:' . $port . '/map.xml', $base . '/map-1.xml', $base . '/map-2.xml', $base . '/map-3.xml', $base . '/map-4.xml', $base . '/map-5.xml' ) );
	}
	if ( preg_match( '#^/map-(\d)\.xml$#', $path, $m ) ) {
		fx_urlset( array( $base . '/n/' . $m[1] . '-a', $base . '/n/' . $m[1] . '-b' ) );
	}
	if ( '/' === $path || 0 === strpos( $path, '/n/' ) ) {
		fx_html( '<p>page</p>' );
	}
	fx_404();
}

if ( 'robots' === $name ) {
	if ( '/robots.txt' === $path ) {
		header( 'Content-Type: text/plain' );
		echo "User-agent: *\nDisallow: /wp-admin/\nSitemap: http://evil.cmp-check.test:" . $port . "/x.xml\nSitemap: http://127.0.0.1:" . $port . "/y.xml\nsitemap:   /custom-map.xml\n";
		exit;
	}
	if ( '/custom-map.xml' === $path ) {
		fx_urlset( array( $base . '/r/1', $base . '/r/2' ) );
	}
	if ( '/' === $path || 0 === strpos( $path, '/r/' ) ) {
		fx_html( '<p>page</p>' );
	}
	fx_404();
}

if ( 'hostile' === $name ) {
	// A sitemap that names other hosts, addresses and schemes: only ok-1 may be used.
	if ( '/sitemap.xml' === $path ) {
		fx_urlset(
			array(
				'http://127.0.0.1:' . $port . '/secret',
				'http://evil.cmp-check.test:' . $port . '/a',
				'http://169.254.169.254/latest/meta-data/',
				'http://[::1]:' . $port . '/v6',
				'http://[::ffff:127.0.0.1]:' . $port . '/mapped',
				'http://2130706433:' . $port . '/decimal',
				'file:///etc/passwd',
				'javascript:alert(1)',
				'gopher://hostile.cmp-check.test:' . $port . '/_x',
				'http://user:pass@hostile.cmp-check.test:' . $port . '/userinfo',
				'http://hostile.cmp-check.test:22/port',
				'http://sub.hostile.cmp-check.test:' . $port . '/subdomain',
				'http://hostile.cmp-check.test.evil.test:' . $port . '/suffix',
				$base . '/ok-1',
			)
		);
	}
	if ( '/' === $path || '/ok-1' === $path ) {
		fx_html( '<p>page</p>' );
	}
	fx_404();
}

if ( 'links' === $name ) {
	// No sitemap at all: the homepage links.
	if ( '/' === $path ) {
		fx_html(
			'<a href="/a">a</a><a href="/b/">b</a><a href="/a#x">a again</a><a href="/a?utm_source=x&amp;fbclid=1">a tracked</a>'
			. '<a href="/doc.pdf">pdf</a><a href="/img.JPG">img</a><a href="/files/x.zip?v=1">zip</a>'
			. '<a href="http://evil.cmp-check.test:' . $port . '/e">evil</a><a href="http://127.0.0.1:' . $port . '/loop">ip</a>'
			. '<a href="mailto:x@y.md">mail</a><a href="javascript:void(0)">js</a><a href="tel:+37300">tel</a><a href="#top">top</a>'
			. '<a href="//www.links.cmp-check.test:' . $port . '/c">www</a><a href="/wp-admin/">admin</a><a href="/feed/">feed</a>'
			. '<!-- <a href="/commented">c</a> --><a href="/d?id=7&amp;utm_medium=m">d</a>'
		);
	}
	if ( in_array( $path, array( '/a', '/b/', '/c', '/d' ), true ) ) {
		fx_html( '<p>page</p>' );
	}
	fx_404();
}

if ( 'redir' === $name ) {
	if ( '/sitemap.xml' === $path ) {
		fx_urlset( array( $base . '/ok-1', $base . '/r-off', $base . '/r-priv', $base . '/r-meta', $base . '/r-www', $base . '/big', $base . '/gz', $base . '/missing', $base . '/img', $base . '/r-home' ) );
	}
	if ( '/r-off' === $path ) {
		fx_redirect( 'http://evil.cmp-check.test:' . $port . '/landing' );
	}
	if ( '/r-priv' === $path ) {
		fx_redirect( 'http://10.0.0.1:' . $port . '/admin' );
	}
	if ( '/r-meta' === $path ) {
		fx_redirect( 'http://169.254.169.254/latest/meta-data/' );
	}
	if ( '/r-www' === $path ) {
		fx_redirect( $www . '/ok-2' );
	}
	if ( '/r-home' === $path ) {
		fx_redirect( '/' );
	}
	if ( '/big' === $path ) {
		header( 'Content-Type: text/html' );
		$chunk = str_repeat( '<p>aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa</p>', 1000 );
		for ( $i = 0; $i < 90; $i++ ) {
			echo $chunk;
			flush();
		}
		exit;
	}
	if ( '/gz' === $path ) {
		header( 'Content-Type: text/html' );
		echo gzencode( '<html><body>sneaky</body></html>' );
		exit;
	}
	if ( '/img' === $path ) {
		header( 'Content-Type: image/png' );
		echo "\x89PNG\r\n\x1a\n";
		exit;
	}
	if ( '/' === $path || '/ok-1' === $path || '/ok-2' === $path ) {
		fx_html( '<p>page</p>' );
	}
	fx_404();
}

if ( 'bigmap' === $name ) {
	// A sitemap over 1 MB is ignored: the links are used.
	if ( '/sitemap.xml' === $path ) {
		$l = array();
		for ( $i = 0; $i < 30000; $i++ ) {
			$l[] = $base . '/m/' . $i;
		}
		fx_urlset( $l );
	}
	if ( '/' === $path ) {
		fx_html( '<a href="/from-links">x</a>' );
	}
	if ( '/from-links' === $path ) {
		fx_html( '<p>page</p>' );
	}
	fx_404();
}

if ( 'slow' === $name ) {
	// One slow page among quick ones.
	if ( '/sitemap.xml' === $path ) {
		fx_urlset( array( $slow . '/slow-1', $base . '/f1', $base . '/f2', $base . '/f3', $base . '/f4' ) );
	}
	if ( '/slow-1' === $path ) {
		sleep( 4 );
		fx_html( '<p>late</p>' );
	}
	if ( '/' === $path || preg_match( '#^/f\d$#', $path ) ) {
		fx_html( '<p>page</p>' );
	}
	fx_404();
}

if ( 'allslow' === $name ) {
	// Six slow pages: more than the budget allows.
	if ( '/sitemap.xml' === $path ) {
		$l = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			$l[] = $slow . '/s/' . $i;
		}
		fx_urlset( $l );
	}
	if ( 0 === strpos( $path, '/s/' ) ) {
		sleep( 4 );
		fx_html( '<p>late</p>' );
	}
	if ( '/' === $path ) {
		fx_html( '<p>page</p>' );
	}
	fx_404();
}

if ( 'heavy' === $name ) {
	// The reviewer's worst case: a ~1 MB sitemap and script-heavy pages of 2 MB (and 0.95 MB).
	$heavy = function ( $bytes ) {
		$chunk = '<script>var a="https://www.googletagmanager.com/gtag/js?id=G-1 https://connect.facebook.net/";</script>';
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<html><head><link rel="privacy-policy" href="/privacy"></head><body>' . str_repeat( $chunk, intdiv( $bytes - 80, strlen( $chunk ) ) ) . '</body></html>';
		exit;
	};
	if ( '/sitemap.xml' === $path ) {
		header( 'Content-Type: application/xml' );
		$x = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
		for ( $i = 1; strlen( $x ) < 1040000; $i++ ) {
			$x .= '<url><loc>' . $base . '/h/' . $i . '</loc><lastmod>2026-11-20</lastmod></url>';
		}
		echo $x . '</urlset>';
		exit;
	}
	if ( '/' === $path ) {
		$heavy( 2090000 );
	}
	if ( preg_match( '#^/h/(\d+)$#', $path, $m ) ) {
		$heavy( 0 === ( (int) $m[1] % 3 ) ? 2090000 : 990000 );
	}
	fx_404();
}

if ( 'hops' === $name ) {
	// Redirects everywhere: the request budget (25) and one redirect for everything but the homepage.
	if ( '/robots.txt' === $path ) {
		fx_redirect( '/robots-real.txt' );
	}
	if ( '/robots-real.txt' === $path ) {
		header( 'Content-Type: text/plain' );
		echo "Sitemap: /sm-1.xml\nSitemap: /sm-2.xml\nSitemap: /sm-3.xml\nSitemap: /sm-4.xml\n";
		exit;
	}
	if ( preg_match( '#^/sm-(\d)\.xml$#', $path, $m ) ) {
		fx_redirect( '/sm-' . $m[1] . '-b.xml' );
	}
	if ( preg_match( '#^/sm-(\d)-b\.xml$#', $path, $m ) ) {
		fx_redirect( '/sm-' . $m[1] . '-c.xml' );
	}
	if ( preg_match( '#^/l/(\d)$#', $path, $m ) ) {
		fx_redirect( '/l/' . $m[1] . '-b' );
	}
	if ( preg_match( '#^/l/(\d)-b$#', $path, $m ) ) {
		fx_redirect( '/l/' . $m[1] . '-c' );
	}
	if ( '/' === $path ) {
		$links = '';
		for ( $i = 1; $i <= 9; $i++ ) {
			$links .= '<a href="/l/' . $i . '">' . $i . '</a>';
		}
		fx_html( $links );
	}
	fx_html( '<p>end</p>' );
}

if ( 'home3' === $name ) {
	// The homepage still follows 3 redirects.
	$next = array( '/' => '/x1', '/x1' => '/x2', '/x2' => '/x3' );
	if ( isset( $next[ $path ] ) ) {
		fx_redirect( $next[ $path ] );
	}
	if ( '/x3' === $path ) {
		fx_html( '<p>home</p>' );
	}
	fx_404();
}

/**
 * Stream $bytes bytes with headers already sent; the bytes the client really
 * took are logged as "sent <path> <n>" when the script ends (a client that
 * hangs up stops the script at the next flush).
 */
function fx_stream( $path, $bytes ) {
	$log  = (string) getenv( 'CMP_FX_LOG' );
	$sent = 0;
	register_shutdown_function(
		function () use ( $log, $path, &$sent ) {
			if ( '' !== $log ) {
				file_put_contents( $log, 'sent ' . $path . ' ' . $sent . "\n", FILE_APPEND | LOCK_EX );
			}
		}
	);
	ignore_user_abort( false );
	$chunk = str_repeat( "\x89", 65536 );
	while ( $sent < $bytes ) {
		echo $chunk;
		flush();
		$sent += 65536;
		if ( connection_aborted() ) {
			exit;
		}
	}
	exit;
}

if ( 'media' === $name ) {
	// A WordPress sitemap whose first 8 entries are 26 MB images without a file extension.
	if ( '/sitemap.xml' === $path ) {
		$l = array( $base . '/' );
		for ( $i = 1; $i <= 8; $i++ ) {
			$l[] = $base . '/toolkit/img-' . $i . '/';
		}
		for ( $i = 1; $i <= 12; $i++ ) {
			$l[] = $base . '/p/' . $i;
		}
		fx_urlset( $l );
	}
	if ( 0 === strpos( $path, '/toolkit/' ) ) {
		header( 'Content-Type: image/png' );
		header( 'Content-Length: 27262976' );
		fx_stream( $path, 27262976 );
	}
	if ( '/' === $path || preg_match( '#^/p/\d+$#', $path ) ) {
		fx_html( '<p>page</p>' );
	}
	fx_404();
}

if ( 'clcap' === $name ) {
	// An HTML page announcing 27 MB: cut on its Content-Length, nothing downloaded.
	if ( '/sitemap.xml' === $path ) {
		fx_urlset( array( $base . '/big-cl' ) );
	}
	if ( '/big-cl' === $path ) {
		header( 'Content-Type: text/html' );
		header( 'Content-Length: 27000000' );
		fx_stream( $path, 27000000 );
	}
	if ( '/' === $path ) {
		fx_html( '<p>home</p>' );
	}
	fx_404();
}

if ( 'mediaidx' === $name ) {
	// A Yoast-like index: media sitemaps are skipped, pages first, then posts.
	if ( '/sitemap_index.xml' === $path ) {
		fx_index( array( $base . '/attachment-sitemap.xml', $base . '/image-sitemap1.xml', $base . '/post-sitemap.xml', $base . '/wp-sitemap-posts-attachment-1.xml', $base . '/page-sitemap.xml', $base . '/video-sitemap.xml' ) );
	}
	if ( preg_match( '#^/([a-z0-9-]+)\.xml$#', $path, $m ) && 'sitemap' !== $m[1] && 'wp-sitemap' !== $m[1] ) {
		fx_urlset( array( $base . '/from/' . $m[1] ) );
	}
	if ( '/' === $path || 0 === strpos( $path, '/from/' ) ) {
		fx_html( '<p>page</p>' );
	}
	fx_404();
}

// The reviewer's composite CPU sites (fix round 2), ported from the review fixture.
if ( 0 === strpos( $name, 'rv' ) ) {
	$rv_fill    = function ( $chunk, $bytes, $pre = '<html><body><a href="/privacy">Privacy policy</a>', $post = '</body></html>' ) {
		return $pre . str_repeat( $chunk, intdiv( $bytes - strlen( $pre ) - strlen( $post ), strlen( $chunk ) ) ) . $post;
	};
	$rv_hostile = function ( $root ) {
		return '<?xml version="1.0"?><' . $root . '>' . str_repeat( '<loc>' . str_repeat( ' ', 2000 ) . 'x', 450 );
	};
	$rv_heavy1  = function () use ( $rv_fill ) {
		$per = intdiv( 1000000, 2990 ) - 20;
		return $rv_fill( '<script>' . substr( str_repeat( '//a ', 1000 ), 0, $per ) . '</script>', 1000000 );
	};
	$rv_mid     = function () use ( $rv_fill ) {
		return $rv_fill( '<script>' . str_repeat( '//a ', 4900 ) . '</script>', 1040000 );
	};
	$rv_heavy2  = function ( $pre = '<html><body><a href="/privacy">Privacy policy</a>' ) use ( $rv_fill ) {
		$per = intdiv( 2090000, 2990 ) - 20;
		return $rv_fill( '<script>' . substr( str_repeat( '//a ', 1000 ), 0, $per ) . '</script>', 2090000, $pre );
	};
	$rv_html    = function ( $b ) {
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $b;
		exit;
	};
	$rv_xml     = function ( $b ) {
		header( 'Content-Type: application/xml' );
		echo $b;
		exit;
	};
	$links = '';
	for ( $i = 1; $i <= 9; $i++ ) {
		$links .= '<a href="/p/' . $i . '">x</a>';
	}
	if ( '/robots.txt' === $path ) {
		header( 'Content-Type: text/plain' );
		echo 'rvlinkp' === $name ? "Sitemap: $base/h1.xml\nSitemap: $base/h2.xml\nSitemap: $base/h3.xml\nSitemap: $base/h4.xml\n" : "Sitemap: $base/idx.xml\nSitemap: $base/h2.xml\nSitemap: $base/h3.xml\nSitemap: $base/h4.xml\n";
		exit;
	}
	if ( '/idx.xml' === $path ) {
		$third = 'rvcpu' === $name ? 'c4' : 'c3';
		$rv_xml( '<?xml version="1.0"?><sitemapindex><sitemap><loc>' . $base . '/c1.xml</loc></sitemap><sitemap><loc>' . $base . '/c2.xml</loc></sitemap><sitemap><loc>' . $base . '/' . $third . '.xml</loc></sitemap>' . str_repeat( '<loc>' . str_repeat( ' ', 2000 ) . 'x', 450 ) );
	}
	if ( '/c3.xml' === $path ) {
		$x = '<?xml version="1.0"?><urlset>';
		for ( $i = 1; $i <= 9; $i++ ) {
			$x .= '<url><loc>' . $base . '/p/' . $i . '</loc></url>';
		}
		$rv_xml( $x . '</urlset>' );
	}
	if ( preg_match( '#^/(c[124]|h\d)\.xml$#', $path ) ) {
		$rv_xml( $rv_hostile( 'urlset' ) );
	}
	if ( '/' === $path ) {
		if ( 'rvcpu' === $name ) {
			$per = intdiv( 524288, 1500 ) - 20;
			$rv_html( $rv_fill( '<script>' . substr( str_repeat( '//a ', 1000 ), 0, $per ) . '</script>', 524288, '<html><body>' . $links ) );
		}
		if ( 'rvmixh' === $name ) {
			$rv_html( $rv_heavy2() );
		}
		if ( 'rvlinkp' === $name ) {
			$rv_html( $rv_heavy2( '<html><body>' . $links ) );
		}
	}
	if ( preg_match( '#^/p/(\d)$#', $path, $m ) ) {
		$rv_html( 9 === (int) $m[1] ? $rv_heavy1() : $rv_mid() );
	}
	fx_404();
}

if ( 'uploads' === $name || 'uploads30' === $name ) {
	// moldova.md: /toolkit/<slug>/ answers 302 to an image under /wp-content/uploads/.
	$n = 'uploads' === $name ? 9 : 30;
	if ( '/sitemap.xml' === $path ) {
		$l = array( $base . '/' );
		for ( $i = 1; $i <= $n; $i++ ) {
			$l[] = $base . '/toolkit/s-' . $i . '/';
		}
		for ( $i = 1; $i <= 5; $i++ ) {
			$l[] = $base . '/p/' . $i;
		}
		$l[] = $base . '/wp-content/uploads/2024/listed.png';
		$l[] = $base . '/files/report.PDF';
		fx_urlset( $l );
	}
	if ( preg_match( '#^/toolkit/s-(\d+)/$#', $path, $m ) ) {
		fx_redirect( $base . '/wp-content/uploads/2024/11/x-' . $m[1] . '.png' );
	}
	if ( 0 === strpos( $path, '/wp-content/uploads/' ) ) {
		header( 'Content-Type: image/png' );
		echo "\x89PNG\r\n\x1a\n";
		exit;
	}
	if ( '/' === $path || preg_match( '#^/p/\d+$#', $path ) ) {
		fx_html( '<p>page</p>' );
	}
	fx_404();
}

if ( 'nav' === $name ) {
	// moldova.md, fix round 4: 40 /toolkit/ entries (302 to images) first in the sitemap,
	// the real pages later; the homepage nav links two of them.
	$real = array( '/despre/', '/contact/', '/servicii/', '/echipa/', '/preturi/', '/blog/', '/termeni/', '/blog/a/', '/blog/b/', '/blog/c/' );
	if ( '/sitemap.xml' === $path ) {
		$l = array();
		for ( $i = 1; $i <= 40; $i++ ) {
			$l[] = $base . '/toolkit/x-' . $i . '/';
		}
		foreach ( $real as $r ) {
			$l[] = $base . $r;
		}
		fx_urlset( $l );
	}
	if ( preg_match( '#^/toolkit/x-(\d+)/$#', $path, $m ) ) {
		fx_redirect( $base . '/wp-content/uploads/2024/11/x-' . $m[1] . '.png' );
	}
	if ( '/' === $path ) {
		fx_html( '<nav><a href="/despre/">Despre</a><a href="/contact/">Contact</a></nav><p>home</p>' );
	}
	if ( in_array( $path, $real, true ) ) {
		fx_html( '<p>page</p>' );
	}
	fx_404();
}

fx_404();
