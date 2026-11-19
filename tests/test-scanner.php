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

// ---- Task 2: multi-page aggregate and queue (pure) ----
$t2_home = CMP_Scanner::page_result( 'https://example.md/', 'ok', '<a href="#cmp-settings">Setări cookie</a>' . CMP_Blocker::rewrite( '<script src="https://www.googletagmanager.com/gtag/js?id=G-ABC123"></script>', array(), $svcs ) . '<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=A">', $svcs, $h, 100 );
$t2_post = CMP_Scanner::page_result( 'https://example.md/news/', 'ok', '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe><script src="https://www.google-analytics.com/analytics.js"></script><img src="https://secure.gravatar.com/avatar/1"><link rel="stylesheet" href="https://fonts.googleapis.com/css?family=B">', $svcs, $h, 101 );
$t2_err  = CMP_Scanner::page_result( 'https://example.md/broken/', '500', '<script src="https://connect.facebook.net/en_US/fbevents.js"></script>', $svcs, $h, 102 );
cmp_assert_eq( $t2_err, array( 'url' => 'https://example.md/broken/', 'time' => 102, 'status' => '500' ), 'page_result: an error page keeps only url, time and status' );
cmp_assert( true === $t2_home['home'] && false === $t2_post['home'], 'page_result: the home page is marked' );
cmp_assert( true === $t2_home['settings_link'] && false === $t2_post['settings_link'], 'page_result: settings_link per page' );
cmp_assert_eq( $t2_post['fonts'], array( 'https://fonts.googleapis.com/css?family=B' ), 'page_result: Google Fonts links kept per page' );

$t2_agg = CMP_Scanner::aggregate( array( $t2_home, $t2_post, $t2_err ) );
foreach ( array( 'raw', 'blocked', 'settings_link', 'services', 'time', 'sources', 'resources' ) as $k ) {
	cmp_assert( array_key_exists( $k, $t2_agg ), "aggregate: has key $k (consumers)" );
}
foreach ( array( 'raw', 'blocked', 'found' ) as $k ) {
	cmp_assert( isset( $t2_agg['services'][ $k ] ) && is_array( $t2_agg['services'][ $k ] ), "aggregate: services.$k is a list" );
}
cmp_assert( in_array( 'youtube', $t2_agg['services']['found'], true ), 'aggregate: a service found only on an inner page ends up in found' );
cmp_assert( in_array( 'google-analytics', $t2_agg['services']['found'], true ), 'aggregate: home page services in found' );
cmp_assert( ! in_array( 'meta-pixel', $t2_agg['services']['found'], true ) && ! in_array( 'meta-pixel', $t2_agg['services']['raw'], true ), 'aggregate: an error page is ignored' );
cmp_assert( in_array( 'statistics', $t2_agg['raw'], true ), 'aggregate: raw categories are a union over pages' );
cmp_assert( in_array( 'google-analytics', $t2_agg['services']['raw'], true ) && in_array( 'google-analytics', $t2_agg['services']['blocked'], true ), 'aggregate: services raw and blocked are unions' );
cmp_assert( true === $t2_agg['settings_link'], 'aggregate: settings_link comes from the home page' );
cmp_assert( true === CMP_Scanner::aggregate( array( $t2_post, $t2_home ) )['settings_link'], 'aggregate: settings_link from home even when home is not first' );
$t2_nohome = CMP_Scanner::aggregate( array( $t2_post ) );
cmp_assert( ! array_key_exists( 'settings_link', $t2_nohome ), 'aggregate: no home result, no settings_link (no false warning)' );
cmp_assert_eq( $t2_agg['time'], 101, 'aggregate: time is the newest ok page' );
cmp_assert_eq( $t2_agg['pages'], 2, 'aggregate: counts ok pages' );
cmp_assert_eq( $t2_agg['failed'], array( 'https://example.md/broken/' ), 'aggregate: lists failed pages' );
cmp_assert( isset( $t2_agg['sources']['google-analytics'] ) && in_array( array( 'kind' => 'external', 'slug' => 'www.googletagmanager.com' ), $t2_agg['sources']['google-analytics'], true ) && in_array( array( 'kind' => 'external', 'slug' => 'www.google-analytics.com' ), $t2_agg['sources']['google-analytics'], true ), 'aggregate: sources merged across pages' );
$t2_rt = array();
foreach ( $t2_agg['resources'] as $r ) {
	$t2_rt[] = $r['type'] . '|' . $r['host'];
}
cmp_assert_eq( $t2_rt, array( 'google_fonts|fonts.googleapis.com', 'gravatar|secure.gravatar.com' ), 'aggregate: resources merged, one per type+host' );
$t2_empty = CMP_Scanner::aggregate( array( $t2_err ) );
cmp_assert( 0 === $t2_empty['pages'] && array() === $t2_empty['services']['found'] && array() === $t2_empty['raw'], 'aggregate: only error pages gives an empty result with pages 0' );
cmp_assert_eq( CMP_Scanner::aggregate( array( $t2_home, $t2_home ) )['sources'], CMP_Scanner::aggregate( array( $t2_home ) )['sources'], 'aggregate: sources deduped' );
cmp_assert_eq( CMP_Scanner::font_links( array( $t2_home, $t2_post, $t2_err, $t2_home ) ), array( 'https://fonts.googleapis.com/css?family=A', 'https://fonts.googleapis.com/css?family=B' ), 'font_links: union over pages, deduped' );

// Queue helpers.
$t2_q = array( 'https://example.md/', 'https://example.md/a/', 'https://example.md/b/', 'https://example.md/c/', 'https://example.md/d/', 'https://example.md/e/' );
$t2_s = CMP_Scanner::queue_take( $t2_q, 4 );
cmp_assert_eq( $t2_s['take'], array_slice( $t2_q, 0, 4 ), 'queue_take: first n' );
cmp_assert_eq( $t2_s['rest'], array_slice( $t2_q, 4 ), 'queue_take: rest' );
cmp_assert_eq( CMP_Scanner::queue_take( array(), 4 ), array( 'take' => array(), 'rest' => array() ), 'queue_take: empty queue' );
cmp_assert_eq( CMP_Scanner::queue_add( array( 'https://example.md/a/' ), array( 'https://example.md/a', 'https://example.md/b/', 'https://example.md/b/' ) ), array( 'https://example.md/a/', 'https://example.md/b/' ), 'queue_add: dedupes (trailing slash too)' );
cmp_assert_eq( CMP_Scanner::queue_remove( $t2_q, array( 'https://example.md/a', 'https://example.md/' ) ), array( 'https://example.md/b/', 'https://example.md/c/', 'https://example.md/d/', 'https://example.md/e/' ), 'queue_remove: removes done urls by key' );
cmp_assert_eq( CMP_Scanner::progress( array( 'total' => 6 ), array( 'x', 'y' ) ), array( 4, 6 ), 'progress: done of total' );
cmp_assert( CMP_Scanner::in_backoff( array( 'status' => '500', 'time' => 1000 ), 1100 ) && ! CMP_Scanner::in_backoff( array( 'status' => '500', 'time' => 1000 ), 1301 ) && ! CMP_Scanner::in_backoff( array( 'status' => 'ok', 'time' => 1000 ), 1100 ) && ! CMP_Scanner::in_backoff( null, 1100 ), 'in_backoff: a failed page waits 5 minutes' );

// ---- Task 2, fix round 1 ----
// I-1: run one inline batch on an admin screen when cron is disabled or the run is stuck 15 min.
cmp_assert( CMP_Scanner::should_run_inline( true, 1000, 1001 ), 'should_run_inline: cron disabled runs inline at once' );
cmp_assert( ! CMP_Scanner::should_run_inline( false, 1000, 1000 + 900 ), 'should_run_inline: cron on, 15 min without progress is not yet stuck' );
cmp_assert( CMP_Scanner::should_run_inline( false, 1000, 1000 + 901 ), 'should_run_inline: cron on, over 15 min without progress runs inline' );
cmp_assert( ! CMP_Scanner::should_run_inline( false, 1000, 1100 ), 'should_run_inline: cron on and recent progress waits for cron' );

// I-2: stale runs (over 1 h without progress).
$t2_run = array( 'status' => 'running', 'started' => 1000, 'progress' => 5000, 'total' => 6 );
cmp_assert( ! CMP_Scanner::is_stale( $t2_run, 5000 + 3600 ) && CMP_Scanner::is_stale( $t2_run, 5000 + 3601 ), 'is_stale: over 1 h since the last progress' );
cmp_assert( CMP_Scanner::is_stale( array( 'status' => 'running', 'started' => 1000 ), 4601 + 1 ), 'is_stale: an old state without progress falls back to started' );
cmp_assert( ! CMP_Scanner::is_stale( array( 'status' => 'idle', 'started' => 1, 'progress' => 1 ), 99999 ), 'is_stale: an idle state is never stale' );
cmp_assert_eq( CMP_Scanner::resume_action( $t2_run, array( 'x' ), 5000 + 3601 ), 'reschedule', 'resume_action: stale with a queue is rescheduled' );
cmp_assert_eq( CMP_Scanner::resume_action( $t2_run, array(), 5000 + 3601 ), 'reenqueue', 'resume_action: stale with an empty queue is re-enqueued' );
cmp_assert_eq( CMP_Scanner::resume_action( $t2_run, array( 'x' ), 5100 ), 'none', 'resume_action: a live run is left alone' );
cmp_assert_eq( CMP_Scanner::progress_for( $t2_run, array( 'x', 'y' ), 5100 ), array( 4, 6 ), 'progress_for: a live run reports done/total' );
cmp_assert_eq( CMP_Scanner::progress_for( $t2_run, array( 'x', 'y' ), 5000 + 3601 ), null, 'progress_for: a stale run reports nothing' );
cmp_assert_eq( CMP_Scanner::progress_for( array( 'status' => 'idle' ), array(), 1 ), null, 'progress_for: idle reports nothing' );

// I-3: a failed fetch keeps the previous good result, marked stale.
$t2_yt_ok  = CMP_Scanner::page_result( 'https://example.md/yt/', 'ok', '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>', $svcs, $h, 200 );
$t2_yt_err = CMP_Scanner::page_result( 'https://example.md/yt/', '500', '', $svcs, $h, 300 );
$t2_kept   = CMP_Scanner::merge_page_result( $t2_yt_ok, $t2_yt_err );
cmp_assert( true === $t2_kept['stale'] && '500' === $t2_kept['error'] && 300 === $t2_kept['error_time'] && 'ok' === $t2_kept['status'] && 200 === $t2_kept['time'], 'merge_page_result: failure keeps the old ok result, stale with error and its time' );
$t2_agg2 = CMP_Scanner::aggregate( array( $t2_home, $t2_kept ) );
cmp_assert( in_array( 'youtube', $t2_agg2['services']['found'], true ), 'aggregate: a page that had YouTube and then failed still keeps youtube in found' );
cmp_assert( in_array( 'https://example.md/yt/', $t2_agg2['failed'], true ), 'aggregate: a stale page is still listed as failed' );
cmp_assert_eq( CMP_Scanner::merge_page_result( $t2_kept, CMP_Scanner::page_result( 'https://example.md/yt/', 'ok', '<p>no embed</p>', $svcs, $h, 400 ) )['services']['found'], array(), 'merge_page_result: only a successful fetch replaces the result' );
cmp_assert( ! isset( CMP_Scanner::merge_page_result( $t2_kept, CMP_Scanner::page_result( 'https://example.md/yt/', 'ok', '', $svcs, $h, 400 ) )['stale'] ), 'merge_page_result: a successful fetch clears stale' );
cmp_assert_eq( CMP_Scanner::merge_page_result( null, $t2_yt_err ), $t2_yt_err, 'merge_page_result: no previous result keeps the error' );
cmp_assert_eq( CMP_Scanner::merge_page_result( $t2_yt_err, $t2_yt_err ), $t2_yt_err, 'merge_page_result: previous error is not a good result' );
cmp_assert( CMP_Scanner::in_backoff( $t2_kept, 400 ) && ! CMP_Scanner::in_backoff( $t2_kept, 300 + 301 ), 'in_backoff: a stale page waits 5 minutes after its error' );

// I-4: warnings computed before a save are hidden until a newer aggregate exists.
cmp_assert( CMP_Scanner::outdated_after_save( 500, array( 'time' => 400, 'run' => 450 ) ), 'outdated_after_save: aggregate from a run started before the save' );
cmp_assert( ! CMP_Scanner::outdated_after_save( 500, array( 'time' => 400, 'run' => 500 ) ), 'outdated_after_save: aggregate from a run started at or after the save' );
cmp_assert( CMP_Scanner::outdated_after_save( 500, null ), 'outdated_after_save: no aggregate after a save' );
cmp_assert( ! CMP_Scanner::outdated_after_save( 0, array( 'time' => 1 ) ), 'outdated_after_save: no save recorded' );
cmp_assert( CMP_Scanner::outdated_after_save( 500, array( 'time' => 499 ) ), 'outdated_after_save: falls back to time when run is missing' );

// M-1: the autoloaded copy keeps the legacy keys only.
$t2_copy = CMP_Scanner::legacy_copy( $t2_agg );
$t2_ck   = array_keys( $t2_copy );
sort( $t2_ck );
cmp_assert_eq( $t2_ck, array( 'blocked', 'raw', 'services', 'settings_link', 'time' ), 'legacy_copy: legacy keys only' );
cmp_assert_eq( array_keys( CMP_Scanner::legacy_copy( $t2_nohome ) ), array( 'raw', 'blocked', 'services', 'time' ), 'legacy_copy: no settings_link when the aggregate has none' );

// M-2: lock.
cmp_assert( CMP_Scanner::lock_expired( 1000, 1000 + 181 ) && ! CMP_Scanner::lock_expired( 1000, 1100 ), 'lock_expired: a lock older than 3 minutes is taken over' );

// B3 Task 4: nonce-gated check mode. $verify models wp_verify_nonce( $t, 'cmp_check' ) && current_user_can( 'manage_options' ).
require_once __DIR__ . '/../includes/class-consent.php';
$t4_calls  = 0;
$t4_nonce  = function ( $t ) {
	return 'good-nonce' === $t;
};
$t4_verify = function ( $can ) use ( &$t4_calls, $t4_nonce ) {
	return function ( $t ) use ( &$t4_calls, $t4_nonce, $can ) {
		$t4_calls++;
		return $t4_nonce( $t ) && $can;
	};
};
cmp_assert( ! CMP_Scanner::check_active( 'bad-nonce', $t4_verify( true ) ), 'check_active: invalid nonce -> false' );
$t4_calls = 0;
cmp_assert( ! CMP_Scanner::check_active( null, $t4_verify( true ) ) && 0 === $t4_calls, 'check_active: no parameter -> false, verify not called' );
cmp_assert( ! CMP_Scanner::check_active( '', $t4_verify( true ) ) && 0 === $t4_calls, 'check_active: empty parameter -> false, verify not called' );
cmp_assert( ! CMP_Scanner::check_active( 'good-nonce', $t4_verify( false ) ), 'check_active: valid nonce without manage_options -> false' );
cmp_assert( CMP_Scanner::check_active( 'good-nonce', $t4_verify( true ) ), 'check_active: valid nonce with manage_options -> true' );
cmp_assert( true === CMP_Scanner::check_active( 'good-nonce', function () { return 1; } ), 'check_active: a truthy verify (wp_verify_nonce returns 1|2) gives a real bool' );

// Server cookie names follow the mode.
cmp_assert_eq( CMP_Consent::cookie_names( false ), array( 'consent' => 'cmp_consent', 'svc' => 'cmp_svc' ), 'cookie_names: normal mode uses the real cookies' );
cmp_assert_eq( CMP_Consent::cookie_names( true ), array( 'consent' => 'cmp_check_consent', 'svc' => 'cmp_check_svc' ), 'cookie_names: check mode uses the session check cookies' );
cmp_assert_eq( CMP_Consent::COOKIE, 'cmp_consent', 'cookie_names: COOKIE constant unchanged' );

// CMP_DATA overlay.
$t4_data = array( 'version' => '3', 'cookieName' => 'cmp_consent', 'reloadOnDecision' => true, 'cookieDays' => 180 );
cmp_assert_eq( CMP_Scanner::check_data( $t4_data, false ), $t4_data, 'check_data: inactive -> CMP_DATA unchanged' );
cmp_assert_eq(
	CMP_Scanner::check_data( $t4_data, true ),
	array( 'version' => '3', 'cookieName' => 'cmp_check_consent', 'reloadOnDecision' => false, 'cookieDays' => 180, 'check' => true, 'svcCookie' => 'cmp_check_svc' ),
	'check_data: active -> check flag, check cookies, no reload'
);

// B3 Task 4 fix round 1 (M2/M4): the request side of check_mode(), pure.
$t4_ok   = function () { return true; };
$t4_ctx  = array( 'init' => true, 'parsed' => true, 'admin' => false, 'ajax' => false, 'rest' => false );
cmp_assert_eq( CMP_Scanner::check_request( $t4_ctx, 'good-nonce', $t4_ok ), array( 'active' => true, 'final' => true ), 'check_request: front end after parse_request -> active, memoised' );
cmp_assert_eq( CMP_Scanner::check_request( array_merge( $t4_ctx, array( 'init' => false ) ), 'good-nonce', $t4_ok ), array( 'active' => false, 'final' => false ), 'check_request: before init -> false, not memoised' );
cmp_assert_eq( CMP_Scanner::check_request( array_merge( $t4_ctx, array( 'parsed' => false ) ), 'good-nonce', $t4_ok ), array( 'active' => true, 'final' => false ), 'check_request: between init and parse_request -> answered, not memoised' );
cmp_assert_eq( CMP_Scanner::check_request( array_merge( $t4_ctx, array( 'admin' => true ) ), 'good-nonce', $t4_ok ), array( 'active' => false, 'final' => true ), 'check_request: admin -> false' );
cmp_assert_eq( CMP_Scanner::check_request( array_merge( $t4_ctx, array( 'ajax' => true ) ), 'good-nonce', $t4_ok ), array( 'active' => false, 'final' => true ), 'check_request: AJAX -> false' );
cmp_assert_eq( CMP_Scanner::check_request( array_merge( $t4_ctx, array( 'rest' => true ) ), 'good-nonce', $t4_ok ), array( 'active' => false, 'final' => true ), 'check_request: REST/JSON -> false' );
cmp_assert_eq( CMP_Scanner::check_request( array_merge( $t4_ctx, array( 'rest' => true, 'parsed' => false ) ), 'good-nonce', $t4_ok ), array( 'active' => false, 'final' => false ), 'check_request: REST URI before parse_request -> false, not memoised' );
cmp_assert_eq( CMP_Scanner::check_request( $t4_ctx, null, $t4_ok ), array( 'active' => false, 'final' => true ), 'check_request: no token -> false' );
cmp_assert( CMP_Scanner::is_rest_uri( '/wp-json/wp/v2/posts?cmp_check=x', array(), 'wp-json' ), 'is_rest_uri: /wp-json/ path' );
cmp_assert( CMP_Scanner::is_rest_uri( '/sub/wp-json/x', array(), 'wp-json' ), 'is_rest_uri: subdirectory install' );
cmp_assert( CMP_Scanner::is_rest_uri( '/?cmp_check=x', array( 'rest_route' => '/wp/v2' ), 'wp-json' ), 'is_rest_uri: ?rest_route' );
cmp_assert( ! CMP_Scanner::is_rest_uri( '/blog/post/?cmp_check=x', array(), 'wp-json' ), 'is_rest_uri: normal page' );
cmp_assert( ! CMP_Scanner::is_rest_uri( '/?q=wp-json/', array(), 'wp-json' ), 'is_rest_uri: prefix in the query only is not REST' );
$t4_src = file_get_contents( __DIR__ . '/../includes/modules/class-scanner.php' );
cmp_assert( false !== strpos( $t4_src, "header( 'Referrer-Policy: same-origin' )" ), 'check_page_setup: Referrer-Policy same-origin (M1)' );

// B3 Task 5: classify (before consent) and the manual addresses.
$t5_lib = json_decode( file_get_contents( __DIR__ . '/../data/services.json' ), true );
$t5_svc = CMP_Services::all( $t5_lib['services'], array(), array() );
$t5_h   = 'https://example.md/';
$t5_lvl = function ( $r, $value ) {
	foreach ( $r['items'] as $it ) {
		if ( $it[2] === $value ) {
			return $it[0];
		}
	}
	return null;
};
$t5 = CMP_Scanner::classify( array( 'https://www.google-analytics.com/g/collect' ), array(), $t5_svc, $t5_h, 'pro' );
cmp_assert( 'red' === $t5['status'] && 'red' === $t5_lvl( $t5, 'www.google-analytics.com' ), 'classify: GA host before consent -> red' );
cmp_assert_eq( $t5['items'][0][1], 'Google Analytics', 'classify: the item carries the library name' );
cmp_assert_eq( $t5['items'][0][3], 'google-analytics', 'classify: the item source is the service id' );
$t5 = CMP_Scanner::classify( array(), array( '_ga' ), $t5_svc, $t5_h, 'pro' );
cmp_assert( 'red' === $t5['status'] && 'red' === $t5_lvl( $t5, '_ga' ), 'classify: a _ga cookie without its host -> red' );
$t5 = CMP_Scanner::classify( array(), array( '_ga_ABC123' ), $t5_svc, $t5_h, 'pro' );
cmp_assert( 'red' === $t5['status'], 'classify: wildcard library cookie (_ga_*) -> red' );
$t5 = CMP_Scanner::classify( array( 'https://plausible.io/js/script.js', 'https://example.md/wp-content/themes/t/style.css' ), array(), $t5_svc, $t5_h, 'pro' );
cmp_assert( 'green' === $t5['status'] && 'exempt' === $t5_lvl( $t5, 'plausible.io' ), 'classify: Plausible (exempt) -> shown separately, green' );
$t5 = CMP_Scanner::classify( array( 'https://fonts.googleapis.com/css2?family=Inter' ), array(), $t5_svc, $t5_h, 'pro' );
cmp_assert( 'yellow' === $t5['status'] && 'yellow' === $t5_lvl( $t5, 'fonts.googleapis.com' ), 'classify: fonts.googleapis.com -> yellow' );
$t5 = CMP_Scanner::classify( array( 'https://example.md/x.js' ), array( 'mystery_id' ), $t5_svc, $t5_h, 'pro' );
cmp_assert( 'yellow' === $t5['status'] && 'yellow' === $t5_lvl( $t5, 'mystery_id' ), 'classify: an unknown cookie -> yellow' );
$t5 = CMP_Scanner::classify( array( 'https://example.md/x.js' ), array( 'wordpress_logged_in_abc', 'wordpress_test_cookie', 'wp-settings-1', 'wp-settings-time-1', 'wp_lang', 'cmp_check_consent', 'wp_consent_marketing' ), $t5_svc, $t5_h, 'pro' );
cmp_assert( 'green' === $t5['status'] && array() === $t5['items'], 'classify: WordPress, cmp_* and wp_consent_* cookies are ignored' );
$t5 = CMP_Scanner::classify( array( 'https://www.googletagmanager.com/gtm.js?id=GTM-X' ), array(), $t5_svc, $t5_h, 'advanced' );
cmp_assert( 'green' === $t5['status'] && 'advanced' === $t5_lvl( $t5, 'www.googletagmanager.com' ), 'classify: Advanced, googletagmanager.com -> shown separately' );
$t5 = CMP_Scanner::classify( array( 'https://www.googletagmanager.com/gtm.js?id=GTM-X' ), array(), $t5_svc, $t5_h, 'pro' );
cmp_assert( 'red' === $t5['status'], 'classify: Strict, googletagmanager.com -> red' );
$t5 = CMP_Scanner::classify( array(), array(), $t5_svc, $t5_h, 'pro' );
cmp_assert( 'none' === $t5['status'] && array() === $t5['items'], 'classify: no data -> none' );
$t5 = CMP_Scanner::classify( array( 'https://www.google.com/recaptcha/api.js' ), array( '_GRECAPTCHA' ), $t5_svc, $t5_h, 'pro' );
cmp_assert( 'green' === $t5['status'] && 'necessary' === $t5_lvl( $t5, 'www.google.com' ), 'classify: reCAPTCHA (necessary) by its path, not Google Maps -> separate' );
$t5 = CMP_Scanner::classify( array( 'https://tracker.unknown-cdn.net/a.js', 'https://cdn.example.md/x.js', 'https://www.example.md/y.css' ), array(), $t5_svc, $t5_h, 'pro' );
cmp_assert( 'red' === $t5['status'] && 'red' === $t5_lvl( $t5, 'tracker.unknown-cdn.net' ) && 1 === count( $t5['items'] ), 'classify: unknown third-party host -> red; own host and subdomains ignored' );
$t5 = CMP_Scanner::classify( array( 'https://www.google-analytics.com/a', 'https://www.google-analytics.com/b' ), array( '_ga' ), $t5_svc, $t5_h, 'pro' );
cmp_assert_eq( count( $t5['items'] ), 2, 'classify: one item per host, plus the cookie' );

// Manual addresses (cmp_scan_urls).
$t5m = CMP_Scanner::clean_manual_urls( "https://example.md/contact/\nhttps://evil.com/x\n/despre/\njavascript:alert(1)", array( 'https://www.example.md/old/', 'https://other.org/' ), $t5_h );
cmp_assert_eq( $t5m['add'], array( 'https://example.md/contact/', 'https://example.md/despre/' ), 'manual urls: own addresses kept, a path is resolved on the home host' );
cmp_assert( in_array( 'https://evil.com/x', $t5m['rejected'], true ) && in_array( 'https://other.org/', $t5m['rejected'], true ), 'manual urls: an external address is rejected' );
cmp_assert_eq( $t5m['exclude'], array( 'https://www.example.md/old/' ), 'manual urls: external exclusion rejected too' );
$t5many = array();
for ( $i = 0; $i < 40; $i++ ) {
	$t5many[] = "https://example.md/p{$i}/";
}
$t5m = CMP_Scanner::clean_manual_urls( $t5many, array( 'https://example.md/p1/', 'https://example.md/p1' ), $t5_h );
cmp_assert( 20 === count( $t5m['add'] ) && 1 === count( $t5m['exclude'] ), 'manual urls: capped at 20, duplicates dropped' );

// One page of the browser check, from the raw data of check.js.
$t5p = CMP_Scanner::check_page( array( 'url' => 'https://example.md/a/', 'result' => 'timeout' ), $t5_svc, $t5_h, 'pro' );
cmp_assert( 'none' === $t5p['status'] && 'timeout' === $t5p['reason'], 'check_page: no message -> none (could not check), never green' );
$t5p = CMP_Scanner::check_page( array( 'url' => 'https://example.md/a/', 'result' => 'nobanner', 'before' => array( 'resources' => array( 'https://example.md/x.js' ), 'cookies' => array() ) ), $t5_svc, $t5_h, 'pro' );
cmp_assert( 'none' === $t5p['status'] && 'nobanner' === $t5p['reason'], 'check_page: banner missing -> none' );
$t5p = CMP_Scanner::check_page( array( 'url' => 'https://evil.com/', 'result' => 'ok', 'before' => array( 'resources' => array( 'https://example.md/x.js' ) ) ), $t5_svc, $t5_h, 'pro' );
cmp_assert( null === $t5p, 'check_page: a page off the site is dropped' );
$t5p = CMP_Scanner::check_page(
	array(
		'url'    => 'https://example.md/a/',
		'result' => 'ok',
		'before' => array( 'resources' => array( 'https://example.md/x.js', 'https://fonts.gstatic.com/s/a.woff2' ), 'cookies' => array() ),
		'added'  => array( 'resources' => array( 'https://www.google-analytics.com/g/collect' ), 'cookies' => array( '_ga' ) ),
	),
	$t5_svc,
	$t5_h,
	'pro'
);
cmp_assert( 'yellow' === $t5p['status'] && is_array( $t5p['after'] ) && 2 === count( $t5p['after'] ), 'check_page: before classified, after-consent additions listed' );
$t5p = CMP_Scanner::check_page( array( 'url' => 'https://example.md/a/', 'result' => 'ok', 'light' => true, 'before' => array( 'resources' => array( 'https://example.md/x.js' ) ), 'added' => array( 'resources' => array( 'https://www.google-analytics.com/' ) ) ), $t5_svc, $t5_h, 'light' );
cmp_assert( true === $t5p['light'] && null === $t5p['after'], 'check_page: Light keeps only "before"' );

// B3 Task 5, fix round 1.
// I1: one host can carry several services (path patterns): no host-only dedupe.
$t5 = CMP_Scanner::classify( array( 'https://www.google.com/recaptcha/api.js', 'https://www.google.com/maps/embed?pb=x' ), array(), $t5_svc, $t5_h, 'pro' );
cmp_assert( 'red' === $t5['status'] && 2 === count( $t5['items'] ), 'classify I1: reCAPTCHA (necessary) + Google Maps on www.google.com -> red, both listed' );
$t5_ga_ex = $t5_svc;
foreach ( $t5_ga_ex as $i => $s ) {
	if ( 'google-analytics' === $s['id'] ) {
		$t5_ga_ex[ $i ]['exempt'] = true;
	}
}
$t5 = CMP_Scanner::classify( array( 'https://www.googletagmanager.com/gtag/js?id=G-1', 'https://www.googletagmanager.com/gtm.js?id=GTM-1' ), array(), $t5_ga_ex, $t5_h, 'pro' );
cmp_assert( 'red' === $t5['status'] && 2 === count( $t5['items'] ), 'classify I1: exempt GA (gtag/js) does not hide a non-exempt GTM on the same host' );
$t5 = CMP_Scanner::classify( array( 'https://www.googletagmanager.com/gtag/js?id=G-1', 'https://www.googletagmanager.com/gtm.js?id=GTM-1' ), array(), $t5_ga_ex, $t5_h, 'advanced' );
cmp_assert( 'green' === $t5['status'] && 2 === count( $t5['items'] ), 'classify I1: Advanced, exempt GA + GTM -> both separate, green' );
$t5 = CMP_Scanner::classify( array( 'https://www.gstatic.com/recaptcha/r.js', 'https://www.gstatic.com/other/x.js' ), array(), $t5_svc, $t5_h, 'pro' );
cmp_assert( 'red' === $t5['status'] && 'unknown' === ( $t5['items'][1][0] === 'red' && '' === $t5['items'][1][3] ? 'unknown' : '' ), 'classify I1: reCAPTCHA on www.gstatic.com does not hide another gstatic resource' );
// I2: cookies already in the admin's browser before the run.
$t5 = CMP_Scanner::classify( array( 'https://example.md/x.js' ), array( '_ga' ), $t5_svc, $t5_h, 'pro', array( '_ga', 'other' ) );
cmp_assert( 'green' === $t5['status'] && 'preexisting' === $t5_lvl( $t5, '_ga' ), 'classify I2: _ga already in the browser (baseline) -> separate, not red' );
$t5 = CMP_Scanner::classify( array( 'https://example.md/x.js' ), array( '_ga' ), $t5_svc, $t5_h, 'pro', array( 'other' ) );
cmp_assert( 'red' === $t5['status'], 'classify I2: _ga not in the baseline -> red' );
$t5 = CMP_Scanner::classify( array( 'https://example.md/x.js' ), array( 'foo' ), $t5_svc, $t5_h, 'pro', array( 'foo' ) );
cmp_assert( 'green' === $t5['status'] && 'preexisting' === $t5_lvl( $t5, 'foo' ), 'classify I2: unknown cookie in the baseline -> separate, not yellow' );
$t5p = CMP_Scanner::check_page( array( 'url' => 'https://example.md/a/', 'result' => 'ok', 'before' => array( 'resources' => array( 'https://example.md/x.js' ), 'cookies' => array( '_ga' ) ) ), $t5_svc, $t5_h, 'pro', array( '_ga' ) );
cmp_assert( 'green' === $t5p['status'], 'check_page I2: the baseline reaches classify' );
// M3: Advanced, GA cookie before consent (not in the baseline) -> Consent Mode note, separate.
$t5 = CMP_Scanner::classify( array( 'https://example.md/x.js' ), array( '_ga' ), $t5_svc, $t5_h, 'advanced' );
cmp_assert( 'green' === $t5['status'] && 'consentmode' === $t5_lvl( $t5, '_ga' ), 'classify M3: Advanced, _ga before consent -> consentmode note, separate' );
$t5 = CMP_Scanner::classify( array( 'https://example.md/x.js' ), array( '_ga' ), $t5_svc, $t5_h, 'advanced', array( '_ga' ) );
cmp_assert( 'preexisting' === $t5_lvl( $t5, '_ga' ), 'classify M3: Advanced, _ga in the baseline -> preexisting' );
// M1: another origin than the admin.
$t5p = CMP_Scanner::check_page( array( 'url' => 'https://shop.example.md/', 'result' => 'origin' ), $t5_svc, $t5_h, 'pro' );
cmp_assert( 'none' === $t5p['status'] && 'origin' === $t5p['reason'], 'check_page M1: other origin -> none with reason origin' );
// M2: a path is resolved against the home path (subdirectory install).
$t5m = CMP_Scanner::clean_manual_urls( "/contact/\n/blog/despre/", '', 'https://ex.md/blog/' );
cmp_assert_eq( $t5m['add'], array( 'https://ex.md/blog/contact/', 'https://ex.md/blog/despre/' ), 'manual urls M2: /path resolved under the home path' );

// B3 final review fix wave.
$fw_find = function ( array $svcs, $id ) {
	foreach ( $svcs as $s ) {
		if ( isset( $s['id'] ) && $id === $s['id'] ) {
			return $s;
		}
	}
	return null;
};
$fw_ref = new ReflectionMethod( 'CMP_Scanner', 'service_level' );
cmp_assert( $fw_ref->isPublic(), 'I1: CMP_Scanner::service_level is public (one rule for the label and the verdict)' );
cmp_assert( method_exists( 'CMP_Scanner', 'service_state' ), 'I1: CMP_Scanner::service_state exists' );
if ( method_exists( 'CMP_Scanner', 'service_state' ) ) {
	$fw_pl = $fw_find( $t5_svc, 'plausible' );
	$fw_pl['exempt'] = true;
	cmp_assert_eq( CMP_Scanner::service_state( 'plausible', array( 'raw' => array(), 'blocked' => array(), 'found' => array( 'plausible' ) ), $fw_pl, 'pro', false ), 'exempt', 'I1 label: Plausible marked exempt -> exempt, not "blocat"' );
	cmp_assert_eq( CMP_Scanner::service_state( 'recaptcha', array( 'raw' => array(), 'blocked' => array(), 'found' => array( 'recaptcha' ) ), $fw_find( $t5_svc, 'recaptcha' ), 'pro', false ), 'necessary', 'I1 label: reCAPTCHA (necessary) -> necessary' );
	cmp_assert_eq( CMP_Scanner::service_state( 'google-analytics', array( 'raw' => array(), 'blocked' => array(), 'found' => array( 'google-analytics' ) ), $fw_find( $t5_svc, 'google-analytics' ), 'advanced', false ), 'advanced', 'I1 label: GA4 in Advanced -> advanced (Consent Mode)' );
	cmp_assert_eq( CMP_Scanner::service_state( 'youtube', array( 'raw' => array(), 'blocked' => array(), 'found' => array( 'youtube' ) ), $fw_find( $t5_svc, 'youtube' ), 'pro', true ), 'unblocked_off', 'I1 label: YouTube iframe with blocking off -> unblocked, blocking off' );
	cmp_assert_eq( CMP_Scanner::service_state( 'google-analytics', array( 'raw' => array(), 'blocked' => array( 'google-analytics' ), 'found' => array( 'google-analytics' ) ), $fw_find( $t5_svc, 'google-analytics' ), 'pro', false ), 'blocked', 'I1 label: GA with a data-cmp-service marker -> blocked until consent' );
	cmp_assert_eq( CMP_Scanner::service_state( 'google-analytics', array( 'raw' => array(), 'blocked' => array( 'google-analytics' ), 'found' => array( 'google-analytics' ) ), $fw_find( $t5_svc, 'google-analytics' ), 'light', false ), 'blocked_light', 'I1 label: Light, blocked -> blocked permanently' );
	cmp_assert_eq( CMP_Scanner::service_state( 'google-analytics', array( 'raw' => array( 'google-analytics' ), 'blocked' => array(), 'found' => array( 'google-analytics' ) ), $fw_find( $t5_svc, 'google-analytics' ), 'pro', true ), 'unblocked_off', 'I1 label: raw GA, blocking off -> unblocked, blocking off' );
	cmp_assert_eq( CMP_Scanner::service_state( 'youtube', array( 'raw' => array(), 'blocked' => array(), 'found' => array( 'youtube' ) ), $fw_find( $t5_svc, 'youtube' ), 'pro', false ), 'unblocked', 'I1 label: live YouTube iframe, blocking on -> unblocked (never "blocat" by default)' );
	cmp_assert_eq( CMP_Scanner::service_state( 'gone', array( 'found' => array( 'gone' ) ), null, 'pro', false ), 'unblocked', 'I1 label: a service no longer active -> unblocked' );
}
// I2: cookies already in the browser just before this page's frame (set by "Acceptă tot" on an earlier page).
$fw_p = CMP_Scanner::check_page( array( 'url' => 'https://example.md/b/', 'result' => 'ok', 'pre' => array( '_ga', '_ga_ABC' ), 'before' => array( 'resources' => array( 'https://example.md/x.js' ), 'cookies' => array( '_ga', '_ga_ABC' ) ) ), $t5_svc, $t5_h, 'pro', array() );
cmp_assert( 'green' === $fw_p['status'] && 'preexisting' === $t5_lvl( $fw_p, '_ga' ) && 'preexisting' === $t5_lvl( $fw_p, '_ga_ABC' ), 'check_page I2 (final): a cookie in the per-page baseline is preexisting, not red' );
$fw_p = CMP_Scanner::check_page( array( 'url' => 'https://example.md/b/', 'result' => 'ok', 'pre' => array( 'other' ), 'before' => array( 'resources' => array( 'https://example.md/x.js' ), 'cookies' => array( '_ga', 'k' ) ) ), $t5_svc, $t5_h, 'pro', array( 'k' ) );
cmp_assert( 'red' === $fw_p['status'] && 'preexisting' === $t5_lvl( $fw_p, 'k' ), 'check_page I2 (final): run baseline and page baseline are united; a new _ga stays red' );
$fw_p = CMP_Scanner::check_page( array( 'url' => 'https://example.md/b/', 'result' => 'ok', 'pre' => array( 5, array( '_ga' ), str_repeat( 'x', 300 ) ), 'before' => array( 'resources' => array( 'https://example.md/x.js' ), 'cookies' => array( '_ga' ) ) ), $t5_svc, $t5_h, 'pro' );
cmp_assert( 'red' === $fw_p['status'], 'check_page I2 (final): non-string or oversized per-page baseline entries are dropped' );
$fw_p = CMP_Scanner::check_page( array( 'url' => 'https://example.md/b/', 'result' => 'ok', 'pre' => 'not-a-list-_ga', 'before' => array( 'resources' => array( 'https://example.md/x.js' ), 'cookies' => array( '_ga' ) ) ), $t5_svc, $t5_h, 'advanced' );
cmp_assert( 'consentmode' === $t5_lvl( $fw_p, '_ga' ), 'check_page I2 (final): a string per-page baseline is not a list of _ga' );
// M5: a check page can never be framed by another origin.
cmp_assert( false !== strpos( $t4_src, "header( 'X-Frame-Options: SAMEORIGIN' )" ), 'check_page_setup M5: X-Frame-Options SAMEORIGIN' );
// M1 / M2: texts.
$fw_set = file_get_contents( __DIR__ . '/../includes/admin/class-settings-page.php' );
cmp_assert( false === strpos( $fw_set, 'fonturile de pe homepage' ) && false === strpos( $fw_set, 'folosite pe homepage' ), 'M1: font texts no longer say "homepage"' );
cmp_assert( 2 === substr_count( $fw_set, 'paginile scanate ale site-ului' ), 'M1: both font texts say "paginile scanate ale site-ului"' );
$fw_chk = file_get_contents( __DIR__ . '/../includes/admin/class-check-page.php' );
cmp_assert( 1 === preg_match( "/_n\\(\\s*'Am respins o adresă/u", $fw_chk ) && false === strpos( $fw_chk, "__( 'Am respins %d adrese" ), 'M2: rejected-addresses notice uses _n()' );
cmp_assert( false !== strpos( $fw_chk, 'CMP_Scanner::service_state(' ) && false === strpos( $fw_chk, "in_array( \$cmp_id, \$cmp_raw, true ) ? __( 'neblocat'" ), 'I1: the server report label comes from CMP_Scanner::service_state' );
