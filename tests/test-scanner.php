<?php
require_once __DIR__ . '/../includes/modules/class-blocker.php';
require_once __DIR__ . '/../includes/modules/class-scanner.php';

$h = 'https://example.md';

// is_own_host.
cmp_assert( CMP_Scanner::is_own_host( 'https://example.md/x', $h ), 'own host: exact' );
cmp_assert( CMP_Scanner::is_own_host( 'https://www.example.md/x', $h ), 'own host: www accepted' );
cmp_assert( CMP_Scanner::is_own_host( 'https://shop.example.md/', $h ), 'own host: subdomain accepted' );
cmp_assert( ! CMP_Scanner::is_own_host( 'https://evil-example.md/', $h ), 'own host: lookalike rejected' );
cmp_assert( ! CMP_Scanner::is_own_host( 'https://other.com/', $h ), 'own host: external rejected' );
cmp_assert( CMP_Scanner::is_own_host( 'https://example.md/', 'https://www.example.md' ), 'own host: www home, bare url' );
cmp_assert( ! CMP_Scanner::is_own_host( '/relative', $h ), 'own host: relative is not a host match' );

// page_list.
$l = CMP_Scanner::page_list( $h, array( 'https://example.md/privacy/' ), array( 'https://example.md/news/' ), array( 'https://example.md/s1/' ), array( 'add' => array( 'https://example.md/manual/' ), 'exclude' => array() ) );
cmp_assert_eq( $l, array( $h, 'https://example.md/privacy/', 'https://example.md/news/', 'https://example.md/manual/', 'https://example.md/s1/' ), 'page_list: order home, legal, latest, manual, sitemap' );
$sm = array();
for ( $i = 1; $i <= 40; $i++ ) {
	$sm[] = "https://example.md/p{$i}/";
}
cmp_assert_eq( count( CMP_Scanner::page_list( $h, array(), array(), $sm, array() ) ), 20, 'page_list: capped at 20' );
$l = CMP_Scanner::page_list( $h, array( 'https://example.md/a' ), array( 'https://example.md/a/', 'https://example.md' ), array(), array() );
cmp_assert_eq( $l, array( $h, 'https://example.md/a' ), 'page_list: dedupes with and without trailing slash, keeps first original' );
$l = CMP_Scanner::page_list( $h, array( 'https://other.com/x' ), array( 'https://www.example.md/y/' ), array(), array( 'add' => array( 'https://evil.com/' ) ) );
cmp_assert_eq( $l, array( $h, 'https://www.example.md/y/' ), 'page_list: external rejected, www kept' );
$l = CMP_Scanner::page_list( $h, array( 'https://example.md/privacy/' ), array( 'https://example.md/news/' ), array(), array( 'exclude' => array( 'https://example.md/news' ) ) );
cmp_assert_eq( $l, array( $h, 'https://example.md/privacy/' ), 'page_list: exclusion applied' );
$l = CMP_Scanner::page_list( $h, array( 'https://example.md/privacy/' ), array( 'https://example.md/news/' ), array(), array( 'add' => array(), 'exclude' => array() ) );
cmp_assert_eq( count( $l ), 3, 'page_list: empty sitemap leaves first three groups' );

// parse_sitemap.
$idx = '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://example.md/wp-sitemap-posts-post-1.xml</loc></sitemap><sitemap><loc>https://example.md/wp-sitemap-posts-page-1.xml</loc></sitemap></sitemapindex>';
$r   = CMP_Scanner::parse_sitemap( $idx );
cmp_assert_eq( $r, array( 'index' => array( 'https://example.md/wp-sitemap-posts-post-1.xml', 'https://example.md/wp-sitemap-posts-page-1.xml' ), 'urls' => array() ), 'parse_sitemap: index' );
$us = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.md/a/</loc></url><url><loc>https://example.md/b/</loc></url></urlset>';
cmp_assert_eq( CMP_Scanner::parse_sitemap( $us ), array( 'index' => array(), 'urls' => array( 'https://example.md/a/', 'https://example.md/b/' ) ), 'parse_sitemap: urlset' );
cmp_assert_eq( CMP_Scanner::parse_sitemap( '<urlset><url><loc>x' ), array( 'index' => array(), 'urls' => array() ), 'parse_sitemap: broken xml gives empty lists' );
cmp_assert_eq( CMP_Scanner::parse_sitemap( '' ), array( 'index' => array(), 'urls' => array() ), 'parse_sitemap: empty string' );

// source_for.
cmp_assert_eq( CMP_Scanner::source_for( 'https://example.md/wp-content/plugins/pixelyoursite/x.js', $h ), array( 'kind' => 'plugin', 'slug' => 'pixelyoursite' ), 'source_for: plugin' );
cmp_assert_eq( CMP_Scanner::source_for( 'https://example.md/wp-content/themes/twentytwenty/a.js?ver=1', $h ), array( 'kind' => 'theme', 'slug' => 'twentytwenty' ), 'source_for: theme' );
cmp_assert_eq( CMP_Scanner::source_for( 'https://example.md/wp-includes/js/jquery.js', $h ), array( 'kind' => 'core', 'slug' => '' ), 'source_for: core' );
cmp_assert_eq( CMP_Scanner::source_for( 'https://www.googletagmanager.com/gtag/js?id=G-1', $h )['kind'], 'external', 'source_for: external host' );
cmp_assert_eq( CMP_Scanner::source_for( '/wp-content/plugins/foo/bar.js', $h ), array( 'kind' => 'plugin', 'slug' => 'foo' ), 'source_for: relative plugin url' );
cmp_assert_eq( CMP_Scanner::source_for( 'https://cdn.other.com/wp-content/plugins/foo/b.js', $h )['kind'], 'external', 'source_for: plugin path on foreign host is external' );

// script_sources.
$svcs = CMP_Services::library();
$ga   = '<script src="https://example.md/wp-content/plugins/site-kit/x.js"></script>'
	. CMP_Blocker::rewrite( '<script src="https://www.googletagmanager.com/gtag/js?id=G-ABC123"></script>', array(), $svcs )
	. CMP_Blocker::rewrite( "<script>fbq('init','1');</script>", array(), $svcs );
$ss = CMP_Scanner::script_sources( $ga, $svcs, $h );
cmp_assert( isset( $ss['google-analytics'] ) && $ss['google-analytics'][0] === array( 'kind' => 'external', 'slug' => 'www.googletagmanager.com' ), 'script_sources: rewritten GA tag decoded from data-cmp-src (kind and slug)' );
cmp_assert( isset( $ss['meta-pixel'] ) && $ss['meta-pixel'] === array( array( 'kind' => 'inline', 'slug' => '' ) ), 'script_sources: inline script from data-cmp-body' );
$raw = CMP_Scanner::script_sources( '<script src="https://www.google-analytics.com/analytics.js"></script><script>fbq(\'init\',\'1\');</script>', $svcs, $h );
cmp_assert( isset( $raw['google-analytics'], $raw['meta-pixel'] ), 'script_sources: unrewritten tags use src and body' );
cmp_assert_eq( CMP_Scanner::script_sources( '<script>var a=1;</script>', $svcs, $h ), array(), 'script_sources: unrelated script ignored' );
cmp_assert_eq( CMP_Scanner::script_sources( '<script type="text/plain" data-cmp-enc="1" data-cmp-src="!!!"></script>', $svcs, $h ), array(), 'script_sources: corrupt base64 does not throw' );

// ip_resources.
$page = '<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Open+Sans">'
	. '<link href="https://use.typekit.net/abc.css">'
	. '<script src="https://kit.fontawesome.com/k.js"></script>'
	. '<script src="https://cdn.jsdelivr.net/npm/x.js"></script>'
	. '<img src="https://secure.gravatar.com/avatar/1">'
	. '<img src="https://2.gravatar.com/avatar/2">'
	. '<script>window._wpemojiSettings = {"svgUrl":"https:\/\/s.w.org\/images\/core\/emoji\/15\/svg\/"};</script>'
	. '<link href="https://example.md/wp-content/themes/t/s.css"><link href="https://www.example.md/a.css">';
$ip    = CMP_Scanner::ip_resources( $page, $h );
$types = array();
foreach ( $ip as $r ) {
	$types[ $r['type'] ][] = $r['host'];
}
$tk = array_keys( $types );
sort( $tk );
cmp_assert_eq( $tk, array( 'adobe_fonts', 'cdn', 'emoji', 'font_awesome', 'google_fonts', 'gravatar' ), 'ip_resources: every type found, nothing else' );
cmp_assert_eq( $types['gravatar'], array( 'secure.gravatar.com', '2.gravatar.com' ), 'ip_resources: gravatar subdomains' );
$by = array();
foreach ( $ip as $r ) {
	$by[ $r['type'] ] = $r['source'];
}
cmp_assert( $by['emoji'] === array( 'kind' => 'inline', 'slug' => '' ), 'ip_resources: emoji from inline script has inline source' );
cmp_assert( $by['google_fonts'] === array( 'kind' => 'external', 'slug' => 'fonts.googleapis.com' ), 'ip_resources: tag resource has a source' );
$none = CMP_Scanner::ip_resources( '<link href="https://example.md/x.css"><link href="https://www.example.md/y.css"><link href="https://cdn.example.md/z.css">', $h );
cmp_assert_eq( $none, array(), 'ip_resources: own domain not reported' );
cmp_assert_eq( count( CMP_Scanner::ip_resources( '<link href="https://fonts.googleapis.com/a"><link href="https://fonts.googleapis.com/b">', $h ) ), 1, 'ip_resources: one entry per type+host' );

// ---- Fix round 1 ----
// I1: parse_sitemap fallback, caps, XXE.
$rx = CMP_Scanner::parse_sitemap_regex( $idx );
cmp_assert_eq( $rx['index'], array( 'https://example.md/wp-sitemap-posts-post-1.xml', 'https://example.md/wp-sitemap-posts-page-1.xml' ), 'sitemap regex: index' );
cmp_assert_eq( CMP_Scanner::parse_sitemap_regex( $us )['urls'], array( 'https://example.md/a/', 'https://example.md/b/' ), 'sitemap regex: urlset' );
cmp_assert_eq( CMP_Scanner::parse_sitemap_regex( '<urlset><url><loc><![CDATA[https://example.md/c/]]></loc><image:image><image:loc>https://example.md/i.jpg</image:loc></image:image></url></urlset>' )['urls'], array( 'https://example.md/c/' ), 'sitemap regex: CDATA, ignores image:loc' );
cmp_assert_eq( CMP_Scanner::parse_sitemap_regex( 'garbage <loc>x' ), array( 'index' => array(), 'urls' => array() ), 'sitemap regex: garbage gives empty lists' );
$xxe = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><urlset><url><loc>&e;</loc></url><url><loc>https://example.md/ok/</loc></url></urlset>';
foreach ( array( CMP_Scanner::parse_sitemap( $xxe ), CMP_Scanner::parse_sitemap_regex( $xxe ) ) as $i => $r ) {
	cmp_assert( false === strpos( json_encode( $r ), 'root:' ) && ! in_array( '&e;', $r['urls'], true ), "sitemap XXE payload not expanded (path $i)" );
}
$many = '<urlset>';
for ( $i = 0; $i < 1500; $i++ ) {
	$many .= "<url><loc>https://example.md/u{$i}/</loc></url>";
}
$many .= '</urlset>';
cmp_assert_eq( count( CMP_Scanner::parse_sitemap( $many )['urls'] ), 1000, 'parse_sitemap: capped at 1000 urls' );
cmp_assert_eq( count( CMP_Scanner::parse_sitemap_regex( $many )['urls'] ), 1000, 'sitemap regex: capped at 1000 urls' );
cmp_assert_eq( CMP_Scanner::parse_sitemap( str_repeat( ' ', 6 * 1024 * 1024 ) . $us ), array( 'index' => array(), 'urls' => array() ), 'parse_sitemap: input over 5 MB is cut' );

// I2: page_list keeps real URLs.
$l = CMP_Scanner::page_list( $h, array( 'https://example.md/page.html', 'https://example.md/index.php?p=1' ), array(), array(), array() );
cmp_assert_eq( $l, array( $h, 'https://example.md/page.html', 'https://example.md/index.php?p=1' ), 'page_list: .html and .php urls untouched' );
$l = CMP_Scanner::page_list( $h, array( 'http://example.md/a/' ), array( 'https://example.md/a/', 'HTTPS://Example.MD/b/', 'https://example.md/b#x' ), array(), array() );
cmp_assert_eq( $l, array( $h, 'http://example.md/a/', 'HTTPS://Example.MD/b/' ), 'page_list: http/https, host case and fragment dedupe, first original kept' );
$l = CMP_Scanner::page_list( $h, array( 'https://example.md/c#frag' ), array(), array(), array() );
cmp_assert_eq( $l[1], 'https://example.md/c', 'page_list: fragment stripped from kept url' );
$l = CMP_Scanner::page_list( $h, array( 'https://example.md/x.html' ), array(), array(), array( 'exclude' => array( 'http://EXAMPLE.md/x.html#a' ) ) );
cmp_assert_eq( $l, array( $h ), 'page_list: exclusion compares normalized keys' );
$l = CMP_Scanner::page_list( $h, array( 'https://example.md/privacy/', 'https://example.md/terms/' ), array( 'https://example.md/news/' ), $sm, array() );
cmp_assert_eq( array_slice( $l, 0, 4 ), array( $h, 'https://example.md/privacy/', 'https://example.md/terms/', 'https://example.md/news/' ), 'page_list: cap keeps home, legal, latest first' );
cmp_assert_eq( $l[19], 'https://example.md/p16/', 'page_list: cap drops the sitemap tail' );

// I3: source_for site kind and looser matching.
cmp_assert_eq( CMP_Scanner::source_for( 'https://example.md/wp-content/uploads/a.js', $h ), array( 'kind' => 'site', 'slug' => '' ), 'source_for: uploads is site' );
cmp_assert_eq( CMP_Scanner::source_for( 'https://example.md/assets/app.js', $h ), array( 'kind' => 'site', 'slug' => '' ), 'source_for: /assets/ is site' );
cmp_assert_eq( CMP_Scanner::source_for( 'wp-content/plugins/foo/a.js', $h ), array( 'kind' => 'plugin', 'slug' => 'foo' ), 'source_for: relative without leading slash' );
cmp_assert_eq( CMP_Scanner::source_for( 'wp-content/themes/bar/a.js', $h ), array( 'kind' => 'theme', 'slug' => 'bar' ), 'source_for: relative theme' );
cmp_assert_eq( CMP_Scanner::source_for( '//example.md/wp-content/plugins/foo/a.js', $h ), array( 'kind' => 'plugin', 'slug' => 'foo' ), 'source_for: protocol-relative own plugin' );
cmp_assert_eq( CMP_Scanner::source_for( '//cdn.other.com/x.js', $h ), array( 'kind' => 'external', 'slug' => 'cdn.other.com' ), 'source_for: protocol-relative external' );
cmp_assert_eq( CMP_Scanner::source_for( '/wp-content/mu-plugins/x/a.js', $h ), array( 'kind' => 'site', 'slug' => '' ), 'source_for: mu-plugins is site' );
cmp_assert_eq( CMP_Scanner::source_for( '', $h ), array( 'kind' => 'inline', 'slug' => '' ), 'source_for: empty is inline' );

// I4: ip_resources patterns, order-independent.
$has = function ( $html, $type ) use ( $h ) {
	foreach ( CMP_Scanner::ip_resources( $html, $h ) as $r ) {
		if ( $r['type'] === $type ) {
			return true;
		}
	}
	return false;
};
cmp_assert( $has( '<style>@import url("https://fonts.googleapis.com/css?family=A");</style>', 'google_fonts' ), 'ip_resources: <style> @import url()' );
cmp_assert( $has( '<style>@import "//fonts.googleapis.com/css";</style>', 'google_fonts' ), 'ip_resources: <style> @import protocol-relative string' );
cmp_assert( $has( '<style>@font-face{src:url(https://use.typekit.net/f.woff)}</style>', 'adobe_fonts' ), 'ip_resources: <style> url() unquoted' );
cmp_assert( $has( '<div style="background:url(https://secure.gravatar.com/avatar/1)"></div>', 'gravatar' ), 'ip_resources: inline style attribute url()' );
cmp_assert( $has( '<link rel=stylesheet href=https://use.typekit.net/a.css>', 'adobe_fonts' ), 'ip_resources: unquoted href' );
cmp_assert( $has( '<IMG SRC=https://secure.gravatar.com/a.png>', 'gravatar' ), 'ip_resources: uppercase unquoted attribute' );
cmp_assert( $has( '<img srcset="https://a.example.md/x.png 1x, https://1.gravatar.com/a.png 2x">', 'gravatar' ), 'ip_resources: srcset with several urls' );
cmp_assert( $has( '<script src=https://cdn.jsdelivr.net/npm/x.js></script>', 'cdn' ), 'ip_resources: unquoted script src' );
cmp_assert( ! $has( '<link href="https://fonts.googleapis.com.evil.com/css">', 'google_fonts' ), 'ip_resources: lookalike google host rejected' );
cmp_assert( ! $has( '<img src="https://notgravatar.com/a.png">', 'gravatar' ), 'ip_resources: lookalike gravatar rejected' );
cmp_assert_eq( CMP_Scanner::script_sources( '<script src=https://www.google-analytics.com/analytics.js></script>', $svcs, $h )['google-analytics'][0]['kind'], 'external', 'script_sources: unquoted src' );
