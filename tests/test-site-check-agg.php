<?php
/**
 * "Verifică-ți site-ul", v2 report fields (site/api/lib/check.php): evidence,
 * fix / cmp_fix, passed, top, the HTTPS check and cmp_check_aggregate() over
 * several pages. Pure analysis, no network.
 */
$cmp_sca_lib = __DIR__ . '/../site/api/lib/check.php';
if ( ! is_file( $cmp_sca_lib ) ) {
	cmp_assert( false, 'site check v2: site/api/lib/check.php exists' );
	return;
}
if ( ! defined( 'CMP_CHECK' ) ) {
	define( 'CMP_CHECK', true );
}
require_once $cmp_sca_lib;

$cmp_sca_services = cmp_check_load_services( __DIR__ . '/../data/services.json' );

/** Analyse raw HTML at $url. */
function cmp_sca_run( $html, $url = 'https://exemplu.md/', array $headers = array(), $lang = 'ro' ) {
	global $cmp_sca_services;
	return cmp_check_analyze( $html, $headers, $url, $cmp_sca_services, $lang );
}

/** A page result for the aggregator: analysis plus 'url'. */
function cmp_sca_page( $html, $url, array $headers = array(), $lang = 'ro' ) {
	$r        = cmp_sca_run( $html, $url, $headers, $lang );
	$r['url'] = $url;
	return $r;
}

/** The finding with this id, or null. */
function cmp_sca_find( array $r, $id ) {
	foreach ( $r['findings'] as $f ) {
		if ( $f['id'] === $id ) {
			return $f;
		}
	}
	return null;
}

/** Ids of the passed checks. */
function cmp_sca_passed( array $r ) {
	return array_map(
		function ( $p ) {
			return $p['id'];
		},
		$r['passed']
	);
}

$cmp_sca_ga   = '<script async src="https://www.googletagmanager.com/gtag/js?id=G-ABC123&amp;l=dataLayer"></script>';
$cmp_sca_pol  = '<a href="/politica-de-confidentialitate/">Politica de confidențialitate</a>';
$cmp_sca_meta = '<script async src="https://connect.facebook.net/en_US/fbevents.js?v=2"></script>';
$cmp_sca_gf   = '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter&display=swap">';
$cmp_sca_ban  = '<script src="/wp-content/plugins/complianz-gdpr/cookiebanner/js/complianz.min.js"></script>';
$cmp_sca_all  = array( 'no-trackers', 'no-tracker-cookies', 'no-ip-resources', 'banner', 'policy', 'https' );

/* ------------------------------------------------------------------
 * Shape: the old keys stay, the new ones are added.
 * ------------------------------------------------------------------ */
$r = cmp_sca_run( $cmp_sca_ga . $cmp_sca_pol );
cmp_assert_eq( array_slice( array_keys( $r ), 0, 3 ), array( 'status', 'findings', 'cmp' ), 'v2 shape: status, findings, cmp first, as before' );
cmp_assert( isset( $r['passed'], $r['top'] ) && is_array( $r['passed'] ) && is_array( $r['top'] ), 'v2 shape: passed and top are lists' );
$f = cmp_sca_find( $r, 'tracker:ga4' );
cmp_assert_eq( array_keys( $f ), array( 'id', 'severity', 'title', 'explain', 'law', 'evidence', 'fix', 'cmp_fix' ), 'v2 shape: finding keys (old ones first)' );

/* ------------------------------------------------------------------
 * Evidence: plain host + path, no query, at most 3, at most 120 characters.
 * ------------------------------------------------------------------ */
cmp_assert_eq( $f['evidence'], array( 'www.googletagmanager.com/gtag/js' ), 'evidence: tracker script host + path, no query' );
$r = cmp_sca_run( $cmp_sca_gf . '<link rel="stylesheet" href="https://fonts.gstatic.com/s/x.woff2">' );
cmp_assert_eq( cmp_sca_find( $r, 'resource:google-fonts' )['evidence'], array( 'fonts.googleapis.com', 'fonts.gstatic.com' ), 'evidence: resource hosts' );
$r = cmp_sca_run( '<p>x</p>', 'https://exemplu.md/', array( 'Set-Cookie' => array( '_ga=GA1.1.1; Path=/', 'PHPSESSID=1', 'a1=1', 'a2=1', 'a3=1' ) ) );
cmp_assert_eq( cmp_sca_find( $r, 'cookie:_ga' )['evidence'], array( '_ga' ), 'evidence: cookie name' );
cmp_assert_eq( cmp_sca_find( $r, 'cookies:other' )['evidence'], array( 'PHPSESSID', 'a1', 'a2' ), 'evidence: other cookies, capped at 3' );
$r = cmp_sca_run( "<script>gtag('config','G-1');</script>" . $cmp_sca_pol );
$e = cmp_sca_find( $r, 'tracker:ga4' )['evidence'];
cmp_assert( 1 === count( $e ) && false === strpos( $e[0], 'gtag' ) && '' !== $e[0], 'evidence: an inline tracker is described, its code is not echoed' );
$r = cmp_sca_run( '<iframe src="https://www.youtube.com/embed/abc?autoplay=1"></iframe>' . $cmp_sca_pol );
cmp_assert_eq( cmp_sca_find( $r, 'tracker:youtube' )['evidence'], array( 'www.youtube.com/embed/abc' ), 'evidence: iframe host + path' );
$many = '';
for ( $i = 0; $i < 6; $i++ ) {
	$many .= '<script src="https://www.googletagmanager.com/gtag/js/' . $i . '?id=G-1"></script>';
}
cmp_assert_eq( count( cmp_sca_find( cmp_sca_run( $many . $cmp_sca_pol ), 'tracker:ga4' )['evidence'] ), 3, 'evidence: at most 3 per finding' );
$long = '<script src="https://www.googletagmanager.com/gtag/js/' . str_repeat( 'a', 400 ) . '?id=G-1"></script>';
$e    = cmp_sca_find( cmp_sca_run( $long . $cmp_sca_pol ), 'tracker:ga4' )['evidence'][0];
cmp_assert( strlen( $e ) <= 120, 'evidence: at most 120 characters' );
$r   = cmp_sca_run( '<script src="https://www.googletagmanager.com/gtag/js/%3Cscript%3E&quot;&lt;b&gt;x?id=G-1"></script><img src="https://www.facebook.com/tr/<x>?id=1">' );
$bad = false;
foreach ( $r['findings'] as $ff ) {
	foreach ( $ff['evidence'] as $ev ) {
		if ( ! is_string( $ev ) || false !== strpos( $ev, '<' ) || false !== strpos( $ev, '>' ) || false !== strpos( $ev, '"' ) || 1 === preg_match( '/[\x00-\x1F]/', $ev ) ) {
			$bad = true;
		}
	}
}
cmp_assert( ! $bad, 'evidence: plain text, no markup characters from the page' );

/* ------------------------------------------------------------------
 * fix / cmp_fix for every finding the analysis can emit, RO and EN.
 * ------------------------------------------------------------------ */
$cmp_sca_cases = array();
foreach ( glob( __DIR__ . '/fixtures/site-check/*.html' ) as $file ) {
	$cmp_sca_cases[] = array( (string) file_get_contents( $file ), 'https://exemplu.md/', array( 'Set-Cookie' => array( '_ga=1', '_fbp=1', 'PHPSESSID=1' ) ) );
}
foreach ( array(
	'',
	'<html><head><title>Just a moment...</title></head><body></body></html>',
	'<script async src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>' . str_repeat( '<p>x</p>', 300000 ),
	'<style>' . str_repeat( '//a', 666000 ) . '</style>',
	'<script type="text/x-unknown" src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>',
	'<script src="https://cloud.umami.is/script.js" data-website-id="1"></script>',
	'<iframe src="https://www.youtube.com/embed/x"></iframe><iframe src="https://player.vimeo.com/video/1"></iframe>',
	'<script async src="https://www.googletagmanager.com/gtag/js?id=AW-1"></script><script>ym(1,"init",{})</script>',
	'<link rel="stylesheet" href="https://use.typekit.net/x.css"><img src="https://secure.gravatar.com/avatar/x"><script src="https://s.w.org/x.js"></script><script src="https://cdn.jsdelivr.net/x.js"></script><link rel="stylesheet" href="https://kit.fontawesome.com/x.css">',
	'<script src="https://cdn.segment.com/analytics.js/v1/x/analytics.min.js"></script>',
) as $html ) {
	$cmp_sca_cases[] = array( $html, 'http://exemplu.md/', array() );
}
$cmp_sca_seen = array();
$bad          = array();
foreach ( array( 'ro', 'en' ) as $lang ) {
	foreach ( $cmp_sca_cases as $c ) {
		$r = cmp_sca_run( $c[0], $c[1], $c[2], $lang );
		foreach ( $r['findings'] as $ff ) {
			$cmp_sca_seen[ preg_replace( '/:.*$/', ':', $ff['id'] ) . ( 0 === strpos( $ff['id'], 'resource:' ) || 0 === strpos( $ff['id'], 'page:' ) || 0 === strpos( $ff['id'], 'held:' ) || 'cmp:consent-manager-pro' === $ff['id'] ? substr( $ff['id'], strpos( $ff['id'], ':' ) + 1 ) : '' ) ] = true;
			foreach ( array( 'fix', 'cmp_fix' ) as $k ) {
				if ( ! isset( $ff[ $k ] ) || ! is_string( $ff[ $k ] ) || strlen( $ff[ $k ] ) < 10 || false !== strpos( $ff[ $k ], '{' ) || false !== strpos( $ff[ $k ], '<' ) ) {
					$bad[] = "{$lang} {$ff['id']} {$k}";
				}
			}
			if ( 'ro' === $lang ) {
				$txt = $ff['title'] . ' ' . $ff['explain'] . ' ' . $ff['fix'] . ' ' . $ff['cmp_fix'];
				if ( false !== strpos( $txt, '—' ) || 1 === preg_match( '/[şţŞŢ]/u', $txt ) || false !== stripos( $txt, 'conform' ) ) {
					$bad[] = "ro style {$ff['id']}";
				}
			}
			if ( ! is_array( $ff['evidence'] ) || count( $ff['evidence'] ) > 3 ) {
				$bad[] = "{$lang} {$ff['id']} evidence";
			}
		}
	}
}
cmp_assert( array() === $bad, 'fix/cmp_fix: present, filled and plain for every finding, RO and EN (' . implode( '; ', array_slice( $bad, 0, 5 ) ) . ')' );
$want = array( 'tracker:', 'loader:', 'cookie:', 'cookies:', 'policy:', 'cmp:', 'cmp:consent-manager-pro', 'transport:', 'held:attribute', 'held:unknown-type', 'page:empty', 'page:blocked', 'page:truncated', 'page:partial', 'resource:google-fonts', 'resource:adobe-fonts', 'resource:font-awesome', 'resource:cdn', 'resource:gravatar', 'resource:emoji' );
cmp_assert_eq( array_values( array_diff( $want, array_keys( $cmp_sca_seen ) ) ), array(), 'fix/cmp_fix: the fixture set covers every kind of finding' );
$ro = cmp_sca_find( cmp_sca_run( $cmp_sca_ga . $cmp_sca_pol ), 'tracker:ga4' );
$en = cmp_sca_find( cmp_sca_run( $cmp_sca_ga . $cmp_sca_pol, 'https://exemplu.md/', array(), 'en' ), 'tracker:ga4' );
cmp_assert( $ro['fix'] !== $en['fix'] && $ro['cmp_fix'] !== $en['cmp_fix'], 'fix/cmp_fix: English texts differ from Romanian' );
cmp_assert( false !== strpos( $ro['cmp_fix'], 'Consent Manager Pro' ) && false !== strpos( $ro['fix'], 'Google Analytics' ), 'fix/cmp_fix: name the service and the plugin' );
$f = cmp_sca_find( cmp_sca_run( '<script async src="https://www.googletagmanager.com/gtag/js?id=AW-1"></script>' . $cmp_sca_pol ), 'tracker:google-ads' );
cmp_assert( false !== strpos( $f['cmp_fix'], 'personalizat' ), 'cmp_fix: a tracker outside the plugin library is added as a custom service' );
$f = cmp_sca_find( cmp_sca_run( $cmp_sca_gf ), 'resource:google-fonts' );
cmp_assert( false !== strpos( $f['cmp_fix'], 'serverul tău' ), 'cmp_fix: Google Fonts points at the local fonts option' );

/* ------------------------------------------------------------------
 * passed, per page.
 * ------------------------------------------------------------------ */
$clean = '<html><head>' . $cmp_sca_ban . '</head><body><p>x</p>' . $cmp_sca_pol . '</body></html>';
$r     = cmp_sca_run( $clean );
cmp_assert_eq( $r['status'], 'clean', 'passed: clean page with banner and policy is clean' );
cmp_assert_eq( cmp_sca_passed( $r ), $cmp_sca_all, 'passed: a clean page passes all six checks' );
foreach ( $r['passed'] as $p ) {
	cmp_assert( array( 'id', 'title' ) === array_keys( $p ) && '' !== $p['title'], "passed: {$p['id']} has id and title" );
}
$r = cmp_sca_run( '<p>x</p>' . $cmp_sca_ga );
cmp_assert_eq( cmp_sca_passed( $r ), array( 'no-tracker-cookies', 'no-ip-resources', 'https' ), 'passed: GA, no banner, no policy -> trackers, banner and policy are not passed' );
cmp_assert_eq( cmp_sca_passed( cmp_sca_run( '<p>x</p>' ) ), array( 'no-trackers', 'no-tracker-cookies', 'no-ip-resources', 'https' ), 'passed: no banner and no policy link are simply not passed' );
cmp_assert_eq( cmp_sca_passed( cmp_sca_run( '' ) ), array(), 'passed: nothing on an empty page' );
$r = cmp_sca_run( '<style>' . str_repeat( '//a', 666000 ) . '</style>' );
cmp_assert( ! in_array( 'no-trackers', cmp_sca_passed( $r ), true ) && ! in_array( 'no-ip-resources', cmp_sca_passed( $r ), true ), 'passed: a partly read page never passes the tracker and resource checks' );
$r = cmp_sca_run( '<p>x</p>', 'https://exemplu.md/', array( 'Set-Cookie' => '_fbp=1' ) );
cmp_assert( ! in_array( 'no-tracker-cookies', cmp_sca_passed( $r ), true ), 'passed: a tracker cookie fails the cookie check' );
$r = cmp_sca_run( '<script async src="https://www.googletagmanager.com/gtm.js?id=GTM-1"></script>' );
cmp_assert( ! in_array( 'no-trackers', cmp_sca_passed( $r ), true ), 'passed: an unsure tag loader fails the tracker check' );
$r = cmp_sca_run( '<script src="https://cloud.umami.is/script.js"></script>' . $cmp_sca_pol );
cmp_assert( in_array( 'no-trackers', cmp_sca_passed( $r ), true ), 'passed: a cookieless tool (info) keeps the tracker check' );

/* ------------------------------------------------------------------
 * HTTPS.
 * ------------------------------------------------------------------ */
$r = cmp_sca_run( $clean, 'http://exemplu.md/' );
$f = cmp_sca_find( $r, 'transport:http' );
cmp_assert( null !== $f, 'https: an http final URL gives transport:http' );
cmp_assert( null !== $f && 'unsure' === $f['severity'] && '' === $f['law'], 'https: transport:http is unsure, with no law (the legal doc does not cover HTTPS)' );
cmp_assert( null !== $f && array( 'http://exemplu.md' ) === $f['evidence'], 'https: evidence is the http origin' );
cmp_assert_eq( $r['status'], 'unsure', 'https: an otherwise clean http page is unsure, never clean' );
cmp_assert( ! in_array( 'https', cmp_sca_passed( $r ), true ), 'https: not passed over http' );
cmp_assert( null === cmp_sca_find( cmp_sca_run( $clean, 'HTTPS://exemplu.md/' ), 'transport:http' ), 'https: scheme is case-insensitive' );
$r = cmp_sca_run( '<script src="/wp-content/plugins/consent-manager-pro/assets/cmp.js"></script>', 'http://exemplu.md/' );
cmp_assert_eq( cmp_sca_find( $r, 'cmp:consent-manager-pro' )['explain'], cmp_sca_find( cmp_sca_run( '<script src="/wp-content/plugins/consent-manager-pro/assets/cmp.js"></script>' ), 'cmp:consent-manager-pro' )['explain'], 'https: http alone does not say that something starts before consent' );

/* ------------------------------------------------------------------
 * top, per page: issues only, in the fixed order.
 * ------------------------------------------------------------------ */
$r = cmp_sca_run( $cmp_sca_gf . $cmp_sca_ga . $cmp_sca_meta );
cmp_assert_eq( $r['top'], array( 'tracker:meta-pixel', 'tracker:ga4', 'resource:google-fonts' ), 'top: marketing tracker, analytics tracker, IP resource (max 3)' );
$r = cmp_sca_run( $cmp_sca_gf . $cmp_sca_ga, 'https://exemplu.md/', array( 'Set-Cookie' => '_ga=1' ) );
cmp_assert_eq( $r['top'], array( 'tracker:ga4', 'cookie:_ga', 'resource:google-fonts' ), 'top: tracker cookie before IP resource' );
$r = cmp_sca_run( $cmp_sca_gf . '<p>x</p>', 'https://exemplu.md/', array( 'Set-Cookie' => '_ga=1' ) );
cmp_assert_eq( $r['top'], array( 'cookie:_ga', 'resource:google-fonts', 'policy:missing' ), 'top: missing policy last' );
cmp_assert_eq( cmp_sca_run( '<script async src="https://www.googletagmanager.com/gtm.js?id=GTM-1"></script>', 'http://exemplu.md/' )['top'], array(), 'top: unsure findings never make the list' );

/* ------------------------------------------------------------------
 * Aggregation over pages.
 * ------------------------------------------------------------------ */
$home  = 'https://exemplu.md/';
$p2    = 'https://exemplu.md/despre/';
$p3    = 'https://exemplu.md/contact/';
$pages = array(
	cmp_sca_page( '<html><head>' . $cmp_sca_ban . '</head><body>' . $cmp_sca_pol . '</body></html>', $home ),
	cmp_sca_page( '<html><head>' . $cmp_sca_ga . '</head><body>' . $cmp_sca_pol . '</body></html>', $p2 ),
	cmp_sca_page( '<html><head><script async src="https://www.googletagmanager.com/gtag/js?id=G-ABC123"></script></head><body>' . $cmp_sca_pol . '</body></html>', $p3 ),
);
$a = cmp_check_aggregate( $pages, 'ro' );
cmp_assert_eq( array_keys( $a ), array( 'status', 'findings', 'cmp', 'passed', 'top', 'engine' ), 'aggregate: shape' );
cmp_assert_eq( $a['engine'], 'html-v2', 'aggregate: engine html-v2' );
$ga = array_values(
	array_filter(
		$a['findings'],
		function ( $x ) {
			return 'tracker:ga4' === $x['id'];
		}
	)
);
cmp_assert_eq( count( $ga ), 1, 'aggregate: GA on 2 pages is one finding' );
cmp_assert_eq( $ga[0]['pages'], array( $p2, $p3 ), 'aggregate: the finding lists the 2 pages where it was seen' );
cmp_assert_eq( $ga[0]['evidence'], array( 'www.googletagmanager.com/gtag/js' ), 'aggregate: evidence merged without duplicates' );
cmp_assert_eq( array_keys( $ga[0] ), array( 'id', 'severity', 'title', 'explain', 'law', 'evidence', 'pages', 'fix', 'cmp_fix' ), 'aggregate: finding keys as in the design' );
cmp_assert_eq( $a['status'], 'issues', 'aggregate: status issues' );
cmp_assert_eq( $a['cmp'], 'Complianz', 'aggregate: cmp from the homepage' );
cmp_assert( in_array( 'banner', cmp_sca_passed( $a ), true ), 'aggregate: banner only on the homepage -> passed "banner"' );
cmp_assert( in_array( 'policy', cmp_sca_passed( $a ), true ), 'aggregate: policy link on the homepage -> passed "policy"' );
cmp_assert( ! in_array( 'no-trackers', cmp_sca_passed( $a ), true ), 'aggregate: a tracker on any page fails the tracker check' );
cmp_assert( in_array( 'no-ip-resources', cmp_sca_passed( $a ), true ) && in_array( 'https', cmp_sca_passed( $a ), true ), 'aggregate: checks passed on every page stay passed' );
cmp_assert_eq( $a['top'], array( 'tracker:ga4' ), 'aggregate: top' );

// Homepage first in pages, even when the finding was seen on a later page first in the input.
$a = cmp_check_aggregate(
	array(
		cmp_sca_page( '<p>x</p>' . $cmp_sca_ga . $cmp_sca_pol, $home ),
		cmp_sca_page( '<p>x</p>' . $cmp_sca_ga . $cmp_sca_pol, $p2 ),
	),
	'ro'
);
cmp_assert_eq( cmp_sca_find( $a, 'tracker:ga4' )['pages'], array( $home, $p2 ), 'aggregate: homepage first in pages' );

// Banner on a later page only: not passed. No CMP on the homepage: cmp from the first page that has one.
$a = cmp_check_aggregate(
	array(
		cmp_sca_page( '<p>x</p>' . $cmp_sca_pol, $home ),
		cmp_sca_page( $cmp_sca_ban . $cmp_sca_pol, $p2 ),
	),
	'ro'
);
cmp_assert( ! in_array( 'banner', cmp_sca_passed( $a ), true ), 'aggregate: a banner found only off the homepage is not passed' );
cmp_assert_eq( $a['cmp'], 'Complianz', 'aggregate: cmp from the first page that has one' );
cmp_assert_eq( $a['status'], 'clean', 'aggregate: clean pages -> clean' );

// Policy on the homepage, but a later page has trackers and no policy link.
$a = cmp_check_aggregate(
	array(
		cmp_sca_page( '<p>x</p>' . $cmp_sca_pol, $home ),
		cmp_sca_page( '<p>x</p>' . $cmp_sca_ga, $p2 ),
	),
	'ro'
);
cmp_assert( null !== cmp_sca_find( $a, 'policy:missing' ) && ! in_array( 'policy', cmp_sca_passed( $a ), true ), 'aggregate: policy:missing on any page -> policy not passed' );

// Top order across pages: Meta Pixel before GA before Google Fonts before policy:missing.
$a = cmp_check_aggregate(
	array(
		cmp_sca_page( '<p>x</p>' . $cmp_sca_gf, $home ),
		cmp_sca_page( '<p>x</p>' . $cmp_sca_ga, $p2 ),
		cmp_sca_page( '<p>x</p>' . $cmp_sca_meta, $p3 ),
	),
	'ro'
);
cmp_assert_eq( $a['top'], array( 'tracker:meta-pixel', 'tracker:ga4', 'resource:google-fonts' ), 'aggregate top: Meta Pixel, GA, Google Fonts (max 3)' );
cmp_assert( null !== cmp_sca_find( $a, 'policy:missing' ), 'aggregate top: policy:missing exists but ranks after them' );
$a = cmp_check_aggregate(
	array(
		cmp_sca_page( '<p>x</p>' . $cmp_sca_gf, $home ),
		cmp_sca_page( '<p>x</p>' . $cmp_sca_ga, $p2 ),
	),
	'ro'
);
cmp_assert_eq( $a['top'], array( 'tracker:ga4', 'resource:google-fonts', 'policy:missing' ), 'aggregate top: GA, Google Fonts, then policy:missing' );

// Status rules over all pages.
$a = cmp_check_aggregate( array( cmp_sca_page( '<p>x</p>' . $cmp_sca_pol, $home ), cmp_sca_page( '<script src="https://www.googletagmanager.com/gtm.js?id=GTM-1"></script>', $p2 ) ), 'ro' );
cmp_assert_eq( $a['status'], 'unsure', 'aggregate: an unsure page makes the whole result unsure' );
$a = cmp_check_aggregate( array( cmp_sca_page( '', $home ), cmp_sca_page( '', $p2 ) ), 'ro' );
cmp_assert_eq( $a['status'], 'error', 'aggregate: every page in error -> error' );
cmp_assert_eq( $a['passed'], array(), 'aggregate: nothing passed when nothing was checked' );
$a = cmp_check_aggregate( array( cmp_sca_page( '<p>x</p>' . $cmp_sca_pol . $cmp_sca_ban, $home ), cmp_sca_page( '', $p2 ) ), 'ro' );
cmp_assert( 'error' !== $a['status'] && in_array( 'banner', cmp_sca_passed( $a ), true ), 'aggregate: one page in error does not hide the others' );
cmp_assert_eq( $a['status'], 'unsure', 'fix round 3: a page in error makes a clean site unsure, never clean' );
$f = cmp_sca_find( $a, 'pages:unread' );
cmp_assert( null !== $f && 'unsure' === $f['severity'] && false !== strpos( $f['title'], '1 pagină nu a putut fi citită' ), 'fix round 3: pages:unread, singular' );
cmp_assert_eq( cmp_check_aggregate( array(), 'ro' )['status'], 'error', 'aggregate: no pages -> error' );

// HTTPS across pages.
$a = cmp_check_aggregate( array( cmp_sca_page( $clean, 'http://exemplu.md/' ), cmp_sca_page( $clean, 'http://exemplu.md/a/' ) ), 'ro' );
$f = cmp_sca_find( $a, 'transport:http' );
cmp_assert( null !== $f && 2 === count( $f['pages'] ) && ! in_array( 'https', cmp_sca_passed( $a ), true ), 'aggregate: http pages -> one transport finding, https not passed' );

// Our plugin: the attention text when anything is wrong on any page.
$ours = '<script src="/wp-content/plugins/consent-manager-pro/assets/cmp.js"></script>' . $cmp_sca_pol;
$a    = cmp_check_aggregate( array( cmp_sca_page( $ours, $home ), cmp_sca_page( $ours . $cmp_sca_ga, $p2 ) ), 'ro' );
cmp_assert( $a['findings'] && cmp_sca_find( $a, 'cmp:consent-manager-pro' )['explain'] === cmp_sca_find( cmp_sca_run( $ours . $cmp_sca_ga ), 'cmp:consent-manager-pro' )['explain'], 'aggregate: our plugin with an issue on another page gets the attention text' );

// English titles for passed; hostile page urls are plain text; evidence capped across pages.
$a = cmp_check_aggregate( array( cmp_sca_page( $clean, $home, array(), 'en' ) ), 'en' );
cmp_assert( in_array( 'HTTPS', array_column( $a['passed'], 'title' ), true ) || false !== stripos( implode( ' ', array_column( $a['passed'], 'title' ) ), 'https' ), 'aggregate: English passed titles' );
$pp = array();
for ( $i = 0; $i < 5; $i++ ) {
	$pp[] = cmp_sca_page( '<script src="https://www.googletagmanager.com/gtag/js/p' . $i . '?id=G-1"></script>' . $cmp_sca_pol, $home . 'p' . $i . '/' );
}
$pp[] = array(
	'url'      => 'https://exemplu.md/<script>alert(1)</script>',
	'status'   => 'issues',
	'findings' => array( cmp_sca_find( $pp[0], 'tracker:ga4' ) ),
	'cmp'      => null,
);
$a = cmp_check_aggregate( $pp, 'ro' );
cmp_assert_eq( count( cmp_sca_find( $a, 'tracker:ga4' )['evidence'] ), 3, 'aggregate: evidence capped at 3 across pages' );
cmp_assert_eq( count( cmp_sca_find( $a, 'tracker:ga4' )['pages'] ), 6, 'aggregate: every page listed' );
cmp_assert( false === strpos( implode( ' ', cmp_sca_find( $a, 'tracker:ga4' )['pages'] ), '<' ), 'aggregate: a page url is never echoed as markup' );

/* ------------------------------------------------------------------
 * Fix round.
 * ------------------------------------------------------------------ */

// 1. Meta Pixel as <img>: the plugin does not block it, so cmp_fix must not say so.
$f = cmp_sca_find( cmp_sca_run( '<img src="https://www.facebook.com/tr?id=1&ev=PageView" alt="">' . $cmp_sca_pol ), 'tracker:meta-pixel' );
cmp_assert( null !== $f && false === strpos( $f['cmp_fix'], 'blochează pixelul' ) && false !== strpos( $f['cmp_fix'], "cmp_is_allowed('marketing')" ), 'fix round 1: pixel <img> -> tracker-pixel cmp_fix (move into the JS snippet or cmp_is_allowed)' );
cmp_assert( null !== $f && false === strpos( $f['cmp_fix'], 'îl blochează până la acord, fără cod' ), 'fix round 1: pixel cmp_fix does not claim automatic blocking' );
$f = cmp_sca_find( cmp_sca_run( '<img src="https://www.facebook.com/tr?id=1" alt="">' . $cmp_sca_pol, 'https://exemplu.md/', array(), 'en' ), 'tracker:meta-pixel' );
cmp_assert( null !== $f && false !== strpos( $f['cmp_fix'], "cmp_is_allowed('marketing')" ) && false !== strpos( $f['cmp_fix'], 'snippet' ), 'fix round 1: pixel cmp_fix in English' );
$f = cmp_sca_find( cmp_sca_run( $cmp_sca_meta . $cmp_sca_pol ), 'tracker:meta-pixel' );
cmp_assert( false !== strpos( $f['cmp_fix'], 'blochează până la acord' ), 'fix round 1: the script version keeps the blocking text' );

// 2. Google Fonts: what the font option really does.
$f = cmp_sca_find( cmp_sca_run( $cmp_sca_gf ), 'resource:google-fonts' );
cmp_assert( false !== strpos( $f['cmp_fix'], 'În modul Pro' ) && false !== strpos( $f['cmp_fix'], '@import' ), 'fix round 2: Google Fonts cmp_fix names Pro mode and the @import / JavaScript limit' );
$f = cmp_sca_find( cmp_sca_run( $cmp_sca_gf, 'https://exemplu.md/', array(), 'en' ), 'resource:google-fonts' );
cmp_assert( false !== strpos( $f['cmp_fix'], 'Pro mode' ) && false !== strpos( $f['cmp_fix'], '@import' ), 'fix round 2: English too' );

// 3. Unread pages, error pages, the status floor.
$ok = array( cmp_sca_page( $clean, $home ) );
cmp_assert_eq( cmp_check_aggregate( $ok, 'ro' )['status'], 'clean', 'fix round 3: one clean page, nothing unread -> clean' );
$a = cmp_check_aggregate( $ok, 'ro', 3 );
$f = cmp_sca_find( $a, 'pages:unread' );
cmp_assert( 'unsure' === $a['status'] && null !== $f && false !== strpos( $f['title'], '3 pagini nu au putut fi citite' ), 'fix round 3: $unread = 3 -> unsure, plural' );
cmp_assert( null !== $f && is_array( $f['evidence'] ) && is_array( $f['pages'] ) && '' !== $f['fix'] && '' !== $f['cmp_fix'] && '' === $f['law'], 'fix round 3: pages:unread has the full finding shape' );
$f = cmp_sca_find( cmp_check_aggregate( $ok, 'en', 1 ), 'pages:unread' );
cmp_assert( null !== $f && false !== strpos( $f['title'], '1 page could not be read' ), 'fix round 3: English singular' );
$f = cmp_sca_find( cmp_check_aggregate( $ok, 'en', 2 ), 'pages:unread' );
cmp_assert( null !== $f && false !== strpos( $f['title'], '2 pages could not be read' ), 'fix round 3: English plural' );
$f = cmp_sca_find( cmp_check_aggregate( array( cmp_sca_page( $clean, $home ), cmp_sca_page( '', $p2 ) ), 'ro', 2 ), 'pages:unread' );
cmp_assert( null !== $f && false !== strpos( $f['title'], '3 pagini' ), 'fix round 3: error pages and $unread add up' );
cmp_assert( null === cmp_sca_find( cmp_check_aggregate( $ok, 'ro', -4 ), 'pages:unread' ), 'fix round 3: a negative count is ignored' );
$forged = array(
	'url'      => $home,
	'status'   => 'issues',
	'findings' => array( array( 'id' => 'x', 'severity' => 'bogus' ) ),
	'cmp'      => null,
	'passed'   => array(),
);
cmp_assert_eq( cmp_check_aggregate( array( $forged ), 'ro' )['status'], 'issues', 'fix round 3: status floored by the worst page status' );
$forged['status'] = 'unsure';
cmp_assert_eq( cmp_check_aggregate( array( $forged, cmp_sca_page( $clean, $p2 ) ), 'ro' )['status'], 'unsure', 'fix round 3: an unsure page status is kept' );

// 4. No bidi, zero-width or control characters in any text, page URLs included.
cmp_assert_eq( cmp_check_plain( "a\u{202E}b\u{200B}c\u{0085}d\u{FEFF}e" ), 'abcde', 'fix round 4: cmp_check_plain strips bidi, zero-width, BOM and C1 controls' );
cmp_assert_eq( cmp_check_plain( "Brutăria din colț" ), 'Brutăria din colț', 'fix round 4: normal letters stay' );
$bad = cmp_sca_page( '<p>x</p>' . $cmp_sca_ga . $cmp_sca_pol, "https://exemplu.md/a\u{202E}b\u{200B}c/" );
$a   = cmp_check_aggregate( array( $bad ), 'ro' );
$pg  = cmp_sca_find( $a, 'tracker:ga4' )['pages'][0];
cmp_assert( 1 !== preg_match( '/[\p{Cf}\p{Cc}]/u', $pg ), 'fix round 4: page URLs carry no bidi / zero-width / control characters' );

// 5. Evidence: leading control characters do not hide a scheme.
cmp_assert_eq( cmp_check_evidence_url( "\x01javascript:alert(1)" ), '', 'fix round 5: "\\x01javascript:" gives no evidence' );
cmp_assert_eq( cmp_check_evidence_url( " \t\njavascript:alert(1)" ), '', 'fix round 5: whitespace before javascript: gives no evidence' );
cmp_assert_eq( cmp_check_evidence_url( "\x01https://www.googletagmanager.com/gtag/js?id=1" ), 'www.googletagmanager.com/gtag/js', 'fix round 5: a real URL after a control character still reads' );

// 7. Embed: the fix does not repeat the explanation.
$f = cmp_sca_find( cmp_sca_run( '<iframe src="https://www.youtube.com/embed/x"></iframe>' . $cmp_sca_pol ), 'tracker:youtube' );
cmp_assert( null !== $f && false === strpos( $f['explain'], $f['fix'] ) && false === strpos( $f['fix'], 'imagine care încarcă' ), 'fix round 7: the embed fix is not the explanation again' );

// 6. rank stays internal: never in the aggregate.
cmp_assert( ! array_key_exists( 'rank', cmp_check_aggregate( $ok, 'ro' ) ), 'fix round 6: the aggregate has no rank key' );
$a = cmp_check_aggregate( array( cmp_sca_page( $ours, $home ) ), 'ro', 2 );
cmp_assert_eq( cmp_sca_find( $a, 'cmp:consent-manager-pro' )['explain'], cmp_sca_find( cmp_sca_run( $ours ), 'cmp:consent-manager-pro' )['explain'], 'fix round 3: unread pages alone do not say that something starts before consent' );
