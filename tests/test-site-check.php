<?php
/**
 * The page analysis behind "Verifică-ți site-ul" (site/api/lib/check.php):
 * pure functions, HTML + headers in, findings out. Fixtures are small pages
 * built by hand in tests/fixtures/site-check/.
 */
$cmp_sc_lib = __DIR__ . '/../site/api/lib/check.php';
if ( ! is_file( $cmp_sc_lib ) ) {
	cmp_assert( false, 'site check: site/api/lib/check.php exists' );
	return;
}
if ( ! defined( 'CMP_CHECK' ) ) {
	define( 'CMP_CHECK', true );
}
require_once $cmp_sc_lib;

$cmp_sc_services = cmp_check_load_services( __DIR__ . '/../data/services.json' );
$cmp_sc_url      = 'https://example.md/';

/** Fixture HTML. */
function cmp_sc_fx( $name ) {
	return (string) file_get_contents( __DIR__ . '/fixtures/site-check/' . $name . '.html' );
}

/** Analyse a fixture (or raw HTML when $html is given). */
function cmp_sc_run( $name, array $headers = array(), $lang = 'ro', $html = null ) {
	global $cmp_sc_services, $cmp_sc_url;
	return cmp_check_analyze( null === $html ? cmp_sc_fx( $name ) : $html, $headers, $cmp_sc_url, $cmp_sc_services, $lang );
}

/** Finding ids of a result. */
function cmp_sc_ids( array $r ) {
	return array_map(
		function ( $f ) {
			return $f['id'];
		},
		$r['findings']
	);
}

/** The finding with this id, or null. */
function cmp_sc_find( array $r, $id ) {
	foreach ( $r['findings'] as $f ) {
		if ( $f['id'] === $id ) {
			return $f;
		}
	}
	return null;
}

/** Findings of one severity. */
function cmp_sc_sev( array $r, $sev ) {
	return array_values(
		array_filter(
			$r['findings'],
			function ( $f ) use ( $sev ) {
				return $f['severity'] === $sev;
			}
		)
	);
}

/** The result has the documented shape. */
function cmp_sc_valid( $r ) {
	if ( ! is_array( $r ) || array_keys( $r ) !== array( 'status', 'findings', 'cmp' ) ) {
		return false;
	}
	if ( ! in_array( $r['status'], array( 'clean', 'issues', 'unsure', 'error' ), true ) || ! is_array( $r['findings'] ) ) {
		return false;
	}
	if ( null !== $r['cmp'] && ! is_string( $r['cmp'] ) ) {
		return false;
	}
	foreach ( $r['findings'] as $f ) {
		if ( ! is_array( $f ) || array_keys( $f ) !== array( 'id', 'severity', 'title', 'explain', 'law' ) ) {
			return false;
		}
		if ( ! in_array( $f['severity'], array( 'issue', 'unsure', 'info' ), true ) ) {
			return false;
		}
		foreach ( $f as $v ) {
			if ( ! is_string( $v ) ) {
				return false;
			}
		}
		if ( 'issue' === $f['severity'] && '' === $f['law'] ) {
			return false;
		}
	}
	return true;
}

// Services library.
cmp_assert( count( $cmp_sc_services ) >= 10, 'site check: services library loads' );
cmp_assert_eq( cmp_check_load_services( __DIR__ . '/fixtures/site-check/missing.json' ), array(), 'site check: missing library gives an empty list' );
cmp_assert_eq( cmp_check_load_services( __DIR__ . '/fixtures/site-check/clean.html' ), array(), 'site check: invalid JSON gives an empty list' );

// Clean page: own scripts, JSON-LD with a gravatar URL, anchors to CDNs, a commented-out tracker.
$r = cmp_sc_run( 'clean' );
cmp_assert( cmp_sc_valid( $r ), 'clean: valid result' );
cmp_assert_eq( $r['status'], 'clean', 'clean: status clean' );
cmp_assert_eq( count( cmp_sc_sev( $r, 'issue' ) ) + count( cmp_sc_sev( $r, 'unsure' ) ), 0, 'clean: no issue or unsure finding' );
cmp_assert_eq( $r['cmp'], null, 'clean: no CMP' );

// GA4 via gtag.js.
$r = cmp_sc_run( 'ga4' );
cmp_assert( cmp_sc_valid( $r ), 'ga4: valid result' );
cmp_assert_eq( $r['status'], 'issues', 'ga4: status issues' );
$f = cmp_sc_find( $r, 'tracker:ga4' );
cmp_assert( null !== $f && 'issue' === $f['severity'], 'ga4: tracker:ga4 is an issue' );
cmp_assert( null !== $f && false !== strpos( $f['law'], 'Legea nr. 72/2025, art. 116' ), 'ga4: law cites Law 72/2025 art. 116' );
cmp_assert( null !== $f && false !== strpos( $f['title'], 'Google Analytics' ), 'ga4: title names the service' );
cmp_assert( in_array( 'policy:missing', cmp_sc_ids( $r ), true ), 'ga4: no policy link with trackers gives policy:missing' );
cmp_assert( ! in_array( 'loader:gtm', cmp_sc_ids( $r ), true ), 'ga4: gtag.js with a G- id is not GTM' );
$r = cmp_sc_run( 'ga4-inline' );
cmp_assert( in_array( 'tracker:ga4', cmp_sc_ids( $r ), true ), 'ga4 inline: gtag("config") marker found' );

// English.
$r = cmp_sc_run( 'ga4', array(), 'en' );
$f = cmp_sc_find( $r, 'tracker:ga4' );
cmp_assert( null !== $f && false !== strpos( $f['title'], 'before consent' ), 'ga4 en: English title' );
cmp_assert( null !== $f && false !== strpos( $f['law'], 'Law No. 72/2025' ), 'ga4 en: English law' );
$r2 = cmp_sc_run( 'ga4', array(), 'xx' );
cmp_assert_eq( cmp_sc_find( $r2, 'tracker:ga4' )['title'], cmp_sc_find( cmp_sc_run( 'ga4' ), 'tracker:ga4' )['title'], 'unknown lang falls back to ro' );

// GTM: unsure, never clean.
foreach ( array( 'gtm', 'gtm-src', 'gtag-gtm-id' ) as $fx ) {
	$r = cmp_sc_run( $fx );
	cmp_assert( cmp_sc_valid( $r ), "{$fx}: valid result" );
	cmp_assert_eq( $r['status'], 'unsure', "{$fx}: status unsure" );
	$f = cmp_sc_find( $r, 'loader:gtm' );
	cmp_assert( null !== $f && 'unsure' === $f['severity'], "{$fx}: loader:gtm is unsure" );
	cmp_assert( ! in_array( 'tracker:ga4', cmp_sc_ids( $r ), true ), "{$fx}: GTM alone is not reported as GA" );
}
$r = cmp_sc_run( 'segment' );
cmp_assert( null !== cmp_sc_find( $r, 'loader:segment' ), 'segment: loader:segment' );
cmp_assert_eq( $r['status'], 'unsure', 'segment: status unsure' );

// IP-leaking resources.
$r = cmp_sc_run( 'google-fonts' );
cmp_assert_eq( $r['status'], 'issues', 'google fonts: status issues' );
$f = cmp_sc_find( $r, 'resource:google-fonts' );
cmp_assert( null !== $f && 'issue' === $f['severity'] && '' !== $f['law'], 'google fonts: resource:google-fonts issue with law' );
cmp_assert( false !== strpos( $f['law'], 'Legea nr. 195/2024' ), 'google fonts: law cites Law 195/2024' );
cmp_assert( ! in_array( 'policy:missing', cmp_sc_ids( $r ), true ), 'google fonts: has a policy link' );
$r   = cmp_sc_run( 'ip-resources' );
$ids = cmp_sc_ids( $r );
foreach ( array( 'resource:adobe-fonts', 'resource:cdn', 'resource:emoji', 'resource:font-awesome', 'resource:gravatar' ) as $id ) {
	cmp_assert( in_array( $id, $ids, true ), "ip resources: {$id}" );
}
cmp_assert( ! in_array( 'policy:missing', $ids, true ), 'ip resources: no tracker, so no policy:missing' );

// Blocked correctly by a CMP.
$r = cmp_sc_run( 'ga-blocked-textplain' );
cmp_assert( cmp_sc_valid( $r ), 'blocked text/plain: valid result' );
cmp_assert( ! in_array( 'tracker:ga4', cmp_sc_ids( $r ), true ), 'blocked text/plain: GA not reported' );
cmp_assert( ! in_array( 'policy:missing', cmp_sc_ids( $r ), true ), 'blocked text/plain: no active tracker, so no policy:missing' );
cmp_assert_eq( $r['status'] === 'issues', false, 'blocked text/plain: not issues' );
$r = cmp_sc_run( 'ga-cookiebot' );
cmp_assert( ! in_array( 'tracker:ga4', cmp_sc_ids( $r ), true ), 'cookiebot: GA with data-cookieconsent not reported' );
cmp_assert_eq( $r['cmp'], 'Cookiebot', 'cookiebot: cmp is Cookiebot' );
$f = cmp_sc_find( $r, 'cmp:cookiebot' );
cmp_assert( null !== $f && 'info' === $f['severity'], 'cookiebot: info finding for the CMP' );
cmp_assert_eq( $r['status'], 'clean', 'cookiebot: blocked GA and a CMP is clean' );
$r = cmp_sc_run( 'ga-cookiebot-ignore' );
cmp_assert( in_array( 'tracker:ga4', cmp_sc_ids( $r ), true ), 'cookiebot ignore: data-cookieconsent="ignore" still runs' );
$r = cmp_sc_run( 'x', array(), 'ro', '<script data-cmp-category="statistics" src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>' );
cmp_assert( in_array( 'tracker:ga4', cmp_sc_ids( $r ), true ), 'data-cmp-category alone on a live script: still reported (only the blocker rewrite makes it inert)' );

// Consent Manager Pro.
$r = cmp_sc_run( 'cmp-pro-js' );
cmp_assert_eq( $r['cmp'], 'Consent Manager Pro', 'cmp.js: Consent Manager Pro' );
$f = cmp_sc_find( $r, 'cmp:consent-manager-pro' );
cmp_assert( null !== $f && 'info' === $f['severity'], 'cmp.js: info finding for our plugin' );
cmp_assert_eq( $r['status'], 'clean', 'cmp.js: clean' );
$r = cmp_sc_run( 'cmp-pro-markup' );
cmp_assert_eq( $r['cmp'], 'Consent Manager Pro', 'data-cmp-action: Consent Manager Pro' );
$r = cmp_sc_run( 'x', array(), 'ro', '<script src="https://cdn.other.example/cmp.js"></script>' );
cmp_assert_eq( $r['cmp'], null, 'cmp.js on another host is not ours' );
$clean_text = cmp_sc_find( cmp_sc_run( 'cmp-pro-markup' ), 'cmp:consent-manager-pro' )['explain'];
$r          = cmp_sc_run( 'cmp-pro-with-ga' );
$f          = cmp_sc_find( $r, 'cmp:consent-manager-pro' );
cmp_assert_eq( $r['status'], 'issues', 'ours + live GA: issues' );
cmp_assert( null !== $f && $f['explain'] !== $clean_text, 'ours + live GA: the info text points at the settings' );
$r = cmp_sc_run( 'other-cmp' );
cmp_assert_eq( $r['cmp'], 'Complianz', 'complianz detected' );

// Set-Cookie headers.
$r = cmp_sc_run( 'clean', array( 'Set-Cookie' => array( '_ga=GA1.1.123.456; Path=/; Max-Age=63072000' ) ) );
cmp_assert_eq( $r['status'], 'issues', 'Set-Cookie _ga: issues' );
$f = cmp_sc_find( $r, 'cookie:_ga' );
cmp_assert( null !== $f && 'issue' === $f['severity'] && '' !== $f['law'], 'Set-Cookie _ga: cookie:_ga issue' );
$r = cmp_sc_run( 'clean', array( 'set-cookie: _ga_ABC123=GS1.1; Path=/', 'Content-Type: text/html' ) );
cmp_assert( null !== cmp_sc_find( $r, 'cookie:_ga_ABC123' ), 'Set-Cookie header lines, wildcard pattern _ga_*' );
$r = cmp_sc_run( 'clean', array( 'Set-Cookie' => 'PHPSESSID=abc; path=/; HttpOnly' ) );
cmp_assert_eq( $r['status'], 'clean', 'Set-Cookie PHPSESSID: still clean' );
cmp_assert_eq( count( cmp_sc_sev( $r, 'issue' ) ) + count( cmp_sc_sev( $r, 'unsure' ) ), 0, 'Set-Cookie PHPSESSID: nothing or info' );
$r = cmp_sc_run( 'clean', array( 'Set-Cookie' => '_GRECAPTCHA=1; path=/' ) );
cmp_assert_eq( $r['status'], 'clean', 'Set-Cookie of a necessary service: not an issue' );

// Policy link.
$r = cmp_sc_run( 'policy-present' );
cmp_assert( in_array( 'tracker:ga4', cmp_sc_ids( $r ), true ), 'policy present: GA reported' );
cmp_assert( ! in_array( 'policy:missing', cmp_sc_ids( $r ), true ), 'policy present: "Politica de confidențialitate" text counts' );
$r = cmp_sc_run( 'policy-ru' );
cmp_assert( in_array( 'tracker:meta-pixel', cmp_sc_ids( $r ), true ), 'policy ru: Meta Pixel reported' );
cmp_assert( ! in_array( 'policy:missing', cmp_sc_ids( $r ), true ), 'policy ru: Russian link text counts' );
$f = cmp_sc_find( cmp_sc_run( 'ga4' ), 'policy:missing' );
cmp_assert( null !== $f && 'issue' === $f['severity'] && false !== strpos( $f['law'], 'art. 116' ), 'policy:missing is an issue with law' );

// Iframes.
$r = cmp_sc_run( 'iframes' );
cmp_assert_eq( $r['status'], 'clean', 'iframes: data-src and <template> embeds are not live' );
$r = cmp_sc_run( 'iframe-active' );
cmp_assert( in_array( 'tracker:youtube', cmp_sc_ids( $r ), true ), 'iframe with src: tracker:youtube' );

// Hostile page: no markup in any text field, RCDATA is not parsed as tags.
$r = cmp_sc_run( 'hostile', array( 'Set-Cookie' => array( '<script>alert(5)</script>=1', '_fbp<img>=1' ) ) );
cmp_assert( cmp_sc_valid( $r ), 'hostile: valid result' );
$bad = false;
foreach ( $r['findings'] as $f ) {
	foreach ( array( 'title', 'explain', 'law' ) as $k ) {
		if ( false !== strpos( $f[ $k ], '<' ) || false !== strpos( $f[ $k ], '>' ) || false !== strpos( $f[ $k ], 'alert(' ) ) {
			$bad = true;
		}
	}
}
cmp_assert( ! $bad, 'hostile: no "<", ">" or "alert(" in any text field' );
cmp_assert( in_array( 'tracker:ga4', cmp_sc_ids( $r ), true ), 'hostile: GA still detected' );
cmp_assert( in_array( 'resource:google-fonts', cmp_sc_ids( $r ), true ), 'hostile: Google Fonts still detected' );
cmp_assert( ! in_array( 'tracker:meta-pixel', cmp_sc_ids( $r ), true ), 'hostile: a script inside <textarea> is text' );

// Romanian style.
$all = '';
foreach ( array( 'ga4', 'gtm', 'ip-resources', 'cmp-pro-with-ga', 'ga-cookiebot' ) as $fx ) {
	foreach ( cmp_sc_run( $fx, array( 'Set-Cookie' => array( '_ga=1', 'PHPSESSID=1' ) ) )['findings'] as $f ) {
		$all .= $f['title'] . ' ' . $f['explain'] . ' ' . $f['law'] . "\n";
	}
}
cmp_assert( false === strpos( $all, '—' ), 'ro texts: no em dash' );
cmp_assert( false === strpos( $all, 'ş' ) && false === strpos( $all, 'ţ' ) && false === strpos( $all, 'Ş' ) && false === strpos( $all, 'Ţ' ), 'ro texts: comma-below ș/ț only' );

// Empty, huge and malformed input.
$r = cmp_sc_run( 'x', array(), 'ro', '' );
cmp_assert( cmp_sc_valid( $r ), 'empty: valid result' );
cmp_assert_eq( $r['status'], 'error', 'empty: status error' );
$big = '<html><head><script src="https://www.googletagmanager.com/gtag/js?id=G-1"></script></head><body>' . str_repeat( '<div class="x">salut</div>', 120000 ) . '</body></html>';
cmp_assert( strlen( $big ) > 2 * 1024 * 1024, 'huge: fixture is over 2 MB' );
$t0 = microtime( true );
$r  = cmp_sc_run( 'x', array(), 'ro', $big );
cmp_assert( cmp_sc_valid( $r ), 'huge: valid result' );
cmp_assert( in_array( 'tracker:ga4', cmp_sc_ids( $r ), true ), 'huge: tracker in the first 2 MB still found' );
cmp_assert( microtime( true ) - $t0 < 5, 'huge: analysed in under 5 s' );
$r = cmp_sc_run( 'x', array(), 'ro', '<html><body>' . str_repeat( '<p>', 400000 ) . 'x' );
cmp_assert( cmp_sc_valid( $r ), 'huge flat tag soup: valid result' );
foreach ( array(
	'<script src="https://www.google-analytics.com/a.js',
	'<a href="',
	'<<<>>>"\'<!--',
	"\x00\xff\xfe<script>\x80",
	'<template><template></template><script src="https://connect.facebook.net/x.js"></script>',
	str_repeat( '<a "', 50000 ),
	'<div ' . str_repeat( 'a', 300000 ) . '>',
) as $i => $bad_html ) {
	$t0 = microtime( true );
	$r  = cmp_sc_run( 'x', array(), 'ro', $bad_html );
	cmp_assert( cmp_sc_valid( $r ) && microtime( true ) - $t0 < 5, "malformed #{$i}: valid result, fast" );
}
// Dense 2 MB pages stay within a modest memory limit (separate process, 64 MB).
if ( function_exists( 'exec' ) && defined( 'PHP_BINARY' ) && '' !== PHP_BINARY ) {
	$code = 'define( "CMP_CHECK", true ); require ' . var_export( $cmp_sc_lib, true ) . '; $s = cmp_check_load_services( ' . var_export( __DIR__ . '/../data/services.json', true ) . ' ); $ok = 0;'
		. ' foreach ( array( str_repeat( "<a>", 690000 ), str_repeat( "<img src=\"https://ex.md/a.png\" alt=x>", 55000 ), str_repeat( "<script>x</script>", 120000 ), str_repeat( "<a href=\"/p\">Politica</a>", 80000 ), str_repeat( "<link rel=\"stylesheet\" href=\"https://fonts.googleapis.com/x\">", 34000 ) ) as $h ) {'
		. ' $r = cmp_check_analyze( $h, array(), "https://e.md/", $s ); if ( in_array( $r["status"], array( "clean", "issues", "unsure", "error" ), true ) ) { $ok++; } }'
		. ' echo $ok;';
	$o    = array();
	exec( escapeshellarg( PHP_BINARY ) . ' -d memory_limit=64M -r ' . escapeshellarg( $code ) . ' 2>&1', $o, $rc );
	cmp_assert( 0 === $rc && '5' === trim( implode( '', $o ) ), 'dense 2 MB pages: analysed within 64 MB (' . trim( implode( ' ', $o ) ) . ')' );
}

$r = cmp_sc_run( 'x', array(), 'ro', '<template><template></template><script src="https://connect.facebook.net/x.js"></script></template>' );
cmp_assert( ! in_array( 'tracker:meta-pixel', cmp_sc_ids( $r ), true ), 'nested <template>: content stays inert' );
$r = cmp_sc_run( 'x', array( 'Set-Cookie' => array( 'x' ) ), 'ro', '<p>' );
cmp_assert( cmp_sc_valid( $r ), 'malformed Set-Cookie: valid result' );
$r = cmp_sc_run( 'x', array( 'Set-Cookie' => array( array( 'nested' ) ), 'X' => null ), 'ro', '<p>' );
cmp_assert( cmp_sc_valid( $r ), 'odd header values: valid result' );

// Anti-bot challenge page: cannot check.
$r = cmp_sc_run( 'x', array(), 'ro', '<html><head><title>Just a moment...</title></head><body><script>window._cf_chl_opt={}</script></body></html>' );
cmp_assert_eq( $r['status'], 'error', 'anti-bot page: status error' );
cmp_assert( cmp_sc_valid( $r ), 'anti-bot page: valid result' );

/* ------------------------------------------------------------------
 * Fix round 1.
 * ------------------------------------------------------------------ */

/** Ids and severity map of a raw HTML run. */
function cmp_sc_html( $html, array $headers = array(), $lang = 'ro' ) {
	return cmp_sc_run( 'x', $headers, $lang, $html );
}
$cmp_sc_gtag = 'https://www.googletagmanager.com/gtag/js?id=G-1';
$cmp_sc_pol  = '<a href="/politica-de-confidentialitate/">Politica de confidențialitate</a>';

// 1. Hostile HTML stays within 64 MB (child process) and a capped URL scan means "partial".
if ( function_exists( 'exec' ) && defined( 'PHP_BINARY' ) && '' !== PHP_BINARY ) {
	$code = 'define( "CMP_CHECK", true ); require ' . var_export( $cmp_sc_lib, true ) . '; $s = cmp_check_load_services( ' . var_export( __DIR__ . '/../data/services.json', true ) . ' ); $ok = 0;'
		. ' foreach ( array( "<div " . str_repeat( "a ", 999000 ) . ">", str_repeat( "<a b=\'", 333000 ) . ">", "<style>" . str_repeat( "//a", 666000 ), "<img src=\"" . str_repeat( "//a", 666000 ) . "\">", "<script>" . str_repeat( "//a", 666000 ) . "</script>" ) as $h ) {'
		. ' $r = cmp_check_analyze( $h, array(), "https://e.md/", $s ); if ( is_array( $r ) && in_array( $r["status"], array( "clean", "issues", "unsure", "error" ), true ) ) { $ok++; } }'
		. ' echo $ok;';
	$o    = array();
	exec( escapeshellarg( PHP_BINARY ) . ' -d memory_limit=64M -r ' . escapeshellarg( $code ) . ' 2>&1', $o, $rc );
	cmp_assert( 0 === $rc && '5' === trim( implode( '', $o ) ), 'fix1: hostile attribute and URL floods stay within 64 MB (' . substr( trim( implode( ' ', $o ) ), 0, 200 ) . ')' );
}
$r = cmp_sc_html( '<style>' . str_repeat( '//a', 666000 ) . '</style>' );
cmp_assert( 'unsure' === $r['status'] && null !== cmp_sc_find( $r, 'page:partial' ), 'fix1: a capped URL scan is reported as partial (unsure)' );

// 2. Script types: explicit inert list, delay loaders live, unknown types unsure.
foreach ( array(
	'<script type="1a2b3c4d-text/javascript" src="' . $cmp_sc_gtag . '"></script>'                        => 'Rocket Loader',
	'<script type="text/partytown" src="' . $cmp_sc_gtag . '"></script>'                                   => 'Partytown',
	'<script type="pmdelayedscript" data-pmdelayedscript="' . $cmp_sc_gtag . '"></script>'                 => 'Perfmatters',
	'<script type="javascript/blocked" data-wpmeteor-src="' . $cmp_sc_gtag . '"></script>'                 => 'WP Meteor',
	'<script type="text/javascript" data-src="' . $cmp_sc_gtag . '"></script>'                             => 'data-src',
	'<script type="module-shim" src="' . $cmp_sc_gtag . '"></script>'                                      => 'module-shim',
) as $h => $label ) {
	cmp_assert( null !== cmp_sc_find( cmp_sc_html( $h . $cmp_sc_pol ), 'tracker:ga4' ), "fix2: {$label} script is live" );
}
foreach ( array( 'text/template', 'text/html', 'text/x-template', 'text/x-handlebars-template', 'application/json', 'application/ld+json', 'importmap', 'speculationrules', 'text/plain' ) as $type ) {
	$r = cmp_sc_html( '<script type="' . $type . '" src="' . $cmp_sc_gtag . '">gtag("config","G-1")</script>' );
	cmp_assert_eq( $r['status'], 'clean', "fix2: {$type} is inert" );
}
$r = cmp_sc_html( '<script type="text/x-unknown" src="' . $cmp_sc_gtag . '"></script>' . $cmp_sc_pol );
$f = cmp_sc_find( $r, 'held:unknown-type' );
cmp_assert( null !== $f && 'unsure' === $f['severity'] && 'unsure' === $r['status'], 'fix2: unknown type with a tracker -> held:unknown-type (unsure)' );
cmp_assert( null === cmp_sc_find( $r, 'tracker:ga4' ), 'fix2: unknown type is not reported as a live tracker' );
cmp_assert_eq( cmp_sc_html( '<script type="text/x-unknown">var x = 1;</script>' )['status'], 'clean', 'fix2: unknown type without a tracker -> nothing' );

// 3. Trackers injected by inline code, markers with spaces.
cmp_assert( null !== cmp_sc_find( cmp_sc_run( 'ga-inline-loader' ), 'tracker:ga4' ), 'fix3: createElement GA loader (WP style) found' );
cmp_assert( null !== cmp_sc_find( cmp_sc_html( "<script>\n\tfbq( 'init', '123' );\n\tfbq( 'track', 'PageView' );\n</script>" ), 'tracker:meta-pixel' ), 'fix3: fbq( \'init\' ) with spaces found' );
cmp_assert( null !== cmp_sc_find( cmp_sc_html( "<script>gtag( 'config' , 'G-1' );</script>" ), 'tracker:ga4' ), 'fix3: gtag( \'config\' ) with spaces found' );
cmp_assert( null !== cmp_sc_find( cmp_sc_html( '<script>var u="https://connect.facebook.net/en_US/fbevents.js";</script>' ), 'tracker:meta-pixel' ), 'fix3: tracker host as a URL in an inline body' );
$r = cmp_sc_run( 'gtm' );
cmp_assert( null === cmp_sc_find( $r, 'tracker:ga4' ) && 'unsure' === $r['status'], 'fix3: the GTM snippet is still only GTM' );

// 4. Marker attributes on scripts that still run as JavaScript.
$r = cmp_sc_run( 'ga-cookiebot' );
cmp_assert( 'clean' === $r['status'] && null === cmp_sc_find( $r, 'held:attribute' ), 'fix4: Cookiebot auto-blocking holds marked scripts' );
$r = cmp_sc_run( 'ga-cookiebot-manual' );
$f = cmp_sc_find( $r, 'held:attribute' );
cmp_assert( null !== $f && 'unsure' === $f['severity'] && 'unsure' === $r['status'], 'fix4: Cookiebot without auto-blocking -> held:attribute (unsure)' );
cmp_assert( null === cmp_sc_find( $r, 'tracker:ga4' ), 'fix4: marked script is not an issue' );
$r = cmp_sc_html( '<script data-category="blog" src="' . $cmp_sc_gtag . '"></script>' . $cmp_sc_pol );
cmp_assert( 'clean' !== $r['status'] && null !== cmp_sc_find( $r, 'held:attribute' ), 'fix4: data-category="blog" on a live script is not clean' );
cmp_assert_eq( cmp_sc_html( '<script data-category="statistics">var x = 1;</script>' )['status'], 'clean', 'fix4: a marked script without a tracker -> nothing' );

// 5. Tool-local library additions.
$r = cmp_sc_run( 'yandex' );
$f = cmp_sc_find( $r, 'tracker:yandex-metrica' );
cmp_assert( null !== $f && 'issue' === $f['severity'] && false !== strpos( $f['title'], 'Yandex' ), 'fix5: Yandex Metrica snippet' );
cmp_assert( null !== cmp_sc_find( cmp_sc_html( "<script>ym(12345678, 'init', {});</script>" ), 'tracker:yandex-metrica' ), 'fix5: ym(..., \'init\') alone' );
cmp_assert( null !== cmp_sc_find( cmp_sc_html( '<p>x</p>', array( 'Set-Cookie' => '_ym_uid=1' ) ), 'cookie:_ym_uid' ), 'fix5: _ym_uid cookie is a tracker cookie' );
cmp_assert( null !== cmp_sc_find( cmp_sc_html( '<img src="https://www.facebook.com/tr?id=1&ev=PageView" alt="">' ), 'tracker:meta-pixel' ), 'fix5: live Meta pixel <img>' );
$r = cmp_sc_html( '<noscript><img src="https://www.facebook.com/tr?id=1&ev=PageView" alt=""></noscript>' );
cmp_assert( 0 === count( cmp_sc_sev( $r, 'issue' ) ), 'fix5: Meta pixel <img> inside <noscript> is not an issue' );
cmp_assert( null !== cmp_sc_find( cmp_sc_html( "<script>var _paq = window._paq = window._paq || []; _paq.push(['trackPageView']);</script>" ), 'tracker:matomo' ), 'fix5: self-hosted Matomo (_paq.push)' );
foreach ( array( 'AW-123', 'DC-456' ) as $gid ) {
	$r = cmp_sc_html( '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $gid . '"></script>' );
	$f = cmp_sc_find( $r, 'tracker:google-ads' );
	cmp_assert( null !== $f && 'issue' === $f['severity'] && false !== strpos( $f['title'], 'Google Ads' ) && null === cmp_sc_find( $r, 'tracker:ga4' ), "fix5: gtag/js?id={$gid} is Google Ads" );
}
$f = cmp_sc_find( cmp_sc_run( 'yandex', array(), 'en' ), 'tracker:yandex-metrica' );
cmp_assert( null !== $f && false !== strpos( $f['title'], 'before consent' ), 'fix5: extra trackers have English texts' );

// 6. Policy link heuristic.
$ga = '<script async src="' . $cmp_sc_gtag . '"></script>';
cmp_assert( null !== cmp_sc_find( cmp_sc_html( $ga . '<a href="#">Setări cookie</a>' ), 'policy:missing' ), 'fix6: "Setări cookie" is not a policy link' );
cmp_assert( null !== cmp_sc_find( cmp_sc_html( $ga . '<link rel="stylesheet" href="/privacy.css">' ), 'policy:missing' ), 'fix6: a stylesheet named privacy is not a policy link' );
cmp_assert( null === cmp_sc_find( cmp_sc_html( $ga . '<link rel="privacy-policy" href="/p/1">' ), 'policy:missing' ), 'fix6: <link rel="privacy-policy"> counts' );
cmp_assert( null === cmp_sc_find( cmp_sc_html( $ga . '<a href="/x/">Politica de cookie-uri</a>' ), 'policy:missing' ), 'fix6: "Politica de cookie-uri" counts' );
cmp_assert( null === cmp_sc_find( cmp_sc_html( $ga . '<a href="/x/">Cookie Policy</a>' ), 'policy:missing' ), 'fix6: "Cookie Policy" counts' );
cmp_assert( null === cmp_sc_find( cmp_sc_html( $ga . '<a href="/x/">Политика использования куки</a>' ), 'policy:missing' ), 'fix6: Russian cookie policy counts' );
cmp_assert( null === cmp_sc_find( cmp_sc_html( $ga . '<a href="/cookie-policy/">Detalii</a>' ), 'policy:missing' ), 'fix6: /cookie-policy/ path counts' );

// 7. The <img> cap means "partial".
$imgs = '';
for ( $i = 0; $i < 3001; $i++ ) {
	$imgs .= '<img src="https://ex.md/' . $i . '.png">';
}
$r = cmp_sc_html( $imgs );
cmp_assert( 'unsure' === $r['status'] && null !== cmp_sc_find( $r, 'page:partial' ), 'fix7: over 3000 images -> partial (unsure)' );

// 8. Law texts, as the legal doc's table words them.
cmp_assert_eq( cmp_sc_find( cmp_sc_run( 'ga4' ), 'tracker:ga4' )['law'], 'Legea nr. 195/2024, art. 6–7; Legea nr. 72/2025, art. 116', 'fix8: consent law as in the doc table' );
$f = cmp_sc_find( cmp_sc_run( 'google-fonts' ), 'resource:google-fonts' );
cmp_assert_eq( $f['law'], 'Legea nr. 195/2024, art. 44–49 (transferuri în afara SEE, de ex. SUA)', 'fix8: transfer law' );
cmp_assert( false !== strpos( $f['explain'], 'dată personală' ), 'fix8: "dată personală" moved into the explanation' );

// 9. CMP signatures inside comments do not count.
cmp_assert_eq( cmp_sc_html( '<!-- <script src="https://cdn.cookielaw.org/x.js"></script> --><p>x</p>' )['cmp'], null, 'fix9: a CMP in a comment is ignored' );

// 10. Consent Mode (advanced): GA after a denied default is unsure, under its own id.
$r = cmp_sc_run( 'consent-mode' );
$f = cmp_sc_find( $r, 'tracker:ga4-consent-mode' );
cmp_assert( null !== $f && CMP_CHECK_CONSENT_MODE_SEVERITY === $f['severity'] && 'unsure' === $f['severity'], 'fix10: GA after a denied consent default -> tracker:ga4-consent-mode (unsure)' );
cmp_assert( null === cmp_sc_find( $r, 'tracker:ga4' ) && 'unsure' === $r['status'], 'fix10: no plain tracker:ga4, status unsure' );
cmp_assert( null !== $f && false !== strpos( $f['explain'], 'cookie' ) && '' !== $f['law'], 'fix10: explanation and law' );
$late = '<script async src="' . $cmp_sc_gtag . '"></script><script>gtag("consent","default",{"analytics_storage":"denied"});</script>' . $cmp_sc_pol;
cmp_assert( null !== cmp_sc_find( cmp_sc_html( $late ), 'tracker:ga4' ), 'fix10: a default after GA does not count' );
$granted = '<script>gtag("consent","default",{"analytics_storage":"granted"});</script><script async src="' . $cmp_sc_gtag . '"></script>' . $cmp_sc_pol;
cmp_assert( null !== cmp_sc_find( cmp_sc_html( $granted ), 'tracker:ga4' ), 'fix10: a granted default does not count' );

/* ------------------------------------------------------------------
 * Fix round 2.
 * ------------------------------------------------------------------ */
$pad = str_repeat( 'a', 20000 );
$r   = cmp_sc_html( '<script data-x="' . $pad . '" src="' . $cmp_sc_gtag . '"></script>' . $cmp_sc_pol );
cmp_assert( null !== cmp_sc_find( $r, 'tracker:ga4' ), 'fix2.1: src after a 20 KB attribute is still read (script)' );
$r = cmp_sc_html( '<img data-x="' . $pad . '" src="https://www.facebook.com/tr?id=1" alt="">' );
cmp_assert( null !== cmp_sc_find( $r, 'tracker:meta-pixel' ), 'fix2.1: src after a 20 KB attribute is still read (img)' );
$r = cmp_sc_html( '<script data-x="' . str_repeat( 'a', 70000 ) . '" src="' . $cmp_sc_gtag . '"></script>' . $cmp_sc_pol );
cmp_assert( 'unsure' === $r['status'] && null !== cmp_sc_find( $r, 'page:partial' ), 'fix2.1: src past the 64 KB window -> partial (unsure), never clean' );
$r = cmp_sc_html( '<img src="data:image/png;base64,' . str_repeat( 'QUFB', 10000 ) . '" alt="">' );
cmp_assert( 'clean' === $r['status'] && null === cmp_sc_find( $r, 'page:partial' ), 'fix2.1: a big data: URI image is not partial' );
$r = cmp_sc_html( '<img src="data:image/png;base64,' . str_repeat( 'QUFB', 30000 ) . '" alt="">' );
cmp_assert( 'clean' === $r['status'], 'fix2.1: a data: URI past the window is not partial either' );
$r = cmp_sc_html( '<div data-x="' . $pad . '" class="y">x</div>' );
cmp_assert_eq( $r['status'], 'clean', 'fix2.1: a long attribute on a <div> is not partial' );
foreach ( array( '<link rel="preload" as="script" href="' . $cmp_sc_gtag . '">' => 'preload as=script', '<link rel="modulepreload" href="' . $cmp_sc_gtag . '">' => 'modulepreload' ) as $h => $label ) {
	cmp_assert( null !== cmp_sc_find( cmp_sc_html( $h . $cmp_sc_pol ), 'tracker:ga4' ), "fix2.2: <link {$label}> fetches the tracker" );
}
cmp_assert_eq( cmp_sc_html( '<link rel="preload" as="image" href="https://www.facebook.com/x.png">' . $cmp_sc_pol )['status'], 'clean', 'fix2.2: preload of a non-script is not a script load' );
cmp_assert_eq( cmp_sc_html( '<link rel="dns-prefetch" href="//www.googletagmanager.com">' )['status'], 'clean', 'fix2.2: dns-prefetch is not a script load' );
