<?php
/**
 * Lot A, Task 4: unknown third-party domains (scanner) and the "new domain" email (CMP_Alerts).
 * Pure functions only: the WordPress side is driven through injected callbacks.
 * No gettext stub on purpose (other tests define __ themselves): untranslated source text is asserted.
 */
require_once __DIR__ . '/../includes/class-services.php';
require_once __DIR__ . '/../includes/modules/class-blocker.php';
require_once __DIR__ . '/../includes/modules/class-scanner.php';
require_once __DIR__ . '/../includes/modules/class-alerts.php';
require_once __DIR__ . '/../includes/class-sanitizer.php';
require_once __DIR__ . '/../includes/class-installer.php';

$al_root = dirname( __DIR__ ) . '/';
$al_home = 'https://example.md';
$al_lib  = CMP_Services::library_from( $al_root . 'data/services.json' );
$al_svcs = CMP_Services::all(
	$al_lib['services'],
	array(
		'custom' => array(
			array( 'id' => 'c-partner', 'name' => 'Partner', 'category' => 'marketing', 'hosts' => array( 'cdn.partner.md' ) ),
			array( 'id' => 'c-exempt', 'name' => 'Exempt', 'category' => 'statistics', 'exempt' => true, 'hosts' => array( 'stats.exempt.md' ) ),
			array( 'id' => 'c-nec', 'name' => 'Necessary', 'category' => 'necessary', 'hosts' => array( 'pay.secure.md' ) ),
		),
	),
	array( 'necessary', 'statistics', 'marketing' )
);
$al_unknown = function ( $html ) use ( $al_svcs, $al_home ) {
	return CMP_Scanner::unknown_hosts( $html, $al_svcs, $al_home );
};

// ---- unknown_hosts(): what counts ----
cmp_assert_eq( $al_unknown( '<script src="https://track.vendor.com/t.js"></script>' ), array( 'track.vendor.com' ), 'unknown: an external script host' );
cmp_assert_eq( $al_unknown( '<script src=//Track.Vendor.com/t.js async></script>' ), array( 'track.vendor.com' ), 'unknown: protocol-relative, unquoted, lowercased' );
cmp_assert_eq( $al_unknown( '<iframe src="https://widgets.other.net/f?x=1"></iframe>' ), array( 'widgets.other.net' ), 'unknown: an iframe host' );
cmp_assert_eq( $al_unknown( '<script src="https://a.vendor.com/1.js"></script><script src="https://a.vendor.com/2.js"></script><iframe src="https://A.VENDOR.com/x"></iframe>' ), array( 'a.vendor.com' ), 'unknown: unique' );
cmp_assert_eq( $al_unknown( '<script src="https://a.vendor.com/1.js"></script><iframe src="//b.vendor.com/x"></iframe><script src="https://c.vendor.com/2.js"></script>' ), array( 'a.vendor.com', 'b.vendor.com', 'c.vendor.com' ), 'unknown: order of first appearance' );
cmp_assert_eq( $al_unknown( '<script src="https://a.vendor.com/1.js?x=1&amp;y=2"></script>' ), array( 'a.vendor.com' ), 'unknown: entities in the url' );
cmp_assert_eq( $al_unknown( '<img src="https://img.vendor.com/a.png"><link href="https://css.vendor.com/a.css"><a href="https://x.vendor.com/">x</a>' ), array(), 'unknown: only script[src] and iframe[src]' );

// ---- unknown_hosts(): what does not ----
cmp_assert_eq( $al_unknown( '<script src="https://example.md/a.js"></script><script src="https://www.example.md/b.js"></script><script src="https://shop.example.md/c.js"></script><iframe src="https://cdn.example.md/x"></iframe>' ), array(), 'unknown: own host, www and subdomains are not' );
cmp_assert_eq( $al_unknown( '<script src="https://evil-example.md/a.js"></script>' ), array( 'evil-example.md' ), 'unknown: a lookalike of the own host is' );
cmp_assert_eq( $al_unknown( '<script src="/wp-content/a.js"></script><script src="a.js"></script><script src="data:text/javascript,1"></script><script src="blob:https://x.com/1"></script><script src="javascript:1"></script><script src=""></script><iframe src="about:blank"></iframe><iframe src="/embed"></iframe>' ), array(), 'unknown: relative, data:, blob:, javascript:, empty, about: are not' );
cmp_assert_eq( $al_unknown( '<script src="https://www.google-analytics.com/analytics.js"></script><script src="https://connect.facebook.net/en/fbevents.js"></script>' ), array(), 'unknown: library services are not' );
cmp_assert_eq( $al_unknown( '<script src="https://www.youtube.com/iframe_api"></script><iframe src="https://player.vimeo.com/video/1"></iframe><iframe src="https://www.google.com/maps/embed?pb=1"></iframe>' ), array(), 'unknown: embed services (YouTube, Vimeo, Maps with its path) are not' );
cmp_assert_eq( $al_unknown( '<script src="https://plausible.io/js/script.js"></script><script src="https://challenges.cloudflare.com/turnstile/v0/api.js"></script>' ), array(), 'unknown: an exempt (nu bloca) and a necessary library service are not' );
cmp_assert_eq( $al_unknown( '<script src="https://cdn.partner.md/a.js"></script><iframe src="https://cdn.partner.md/x"></iframe><script src="https://stats.exempt.md/a.js"></script><script src="https://pay.secure.md/a.js"></script>' ), array(), 'unknown: custom services (blocked, exempt, necessary) are not, scripts and iframes' );
cmp_assert_eq( $al_unknown( '<script src="https://www.google.com/other/x.js"></script>' ), array( 'www.google.com' ), 'unknown: an embed with a path does not cover the whole host' );
cmp_assert_eq( $al_unknown( '<link rel="stylesheet" href="https://fonts.googleapis.com/css"><script src="https://cdn.jsdelivr.net/npm/x.js"></script><script src="https://unpkg.com/x.js"></script><script src="https://s.w.org/x.js"></script><script src="https://secure.gravatar.com/x.js"></script><script src="https://2.gravatar.com/x.js"></script>' ), array(), 'unknown: the IP_HOSTS (fonts, CDNs, Gravatar, emoji) are not' );
cmp_assert_eq( $al_unknown( '<script src="https://x.vendor.com/a.js" data-cmp-category="marketing"></script><iframe src="https://y.vendor.com/a" data-cmp-category=statistics></iframe><script data-cmp-category="necessary" src="https://z.vendor.com/a.js"></script>' ), array(), 'unknown: a tag with a valid data-cmp-category is already decided' );
cmp_assert_eq( $al_unknown( '<script src="https://x.vendor.com/a.js" data-cmp-category=""></script>' ), array( 'x.vendor.com' ), 'unknown: a blank category does not decide' );
cmp_assert_eq( $al_unknown( '<script src="https://x.vendor.com/a.js" data-cmp-keep></script>' ), array(), 'unknown: our own tagged (data-cmp-keep) scripts are not' );

// What the blocker already rewrote is known by definition.
$al_blocked = CMP_Blocker::rewrite( '<script src="https://connect.facebook.net/en/fbevents.js"></script><script src="https://x.vendor.com/a.js" data-cmp-category="marketing"></script><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe><iframe src="https://forms.unknown.com/f" data-cmp-category="marketing"></iframe>', array(), $al_svcs );
cmp_assert( false !== strpos( $al_blocked, 'data-cmp-enc' ) && false !== strpos( $al_blocked, '<template' ), 'fixture: the blocker rewrote scripts and iframes' );
cmp_assert_eq( $al_unknown( $al_blocked ), array(), 'unknown: rewritten scripts (data-cmp-src) and embeds (template) are known by definition' );
cmp_assert_eq( $al_unknown( $al_blocked . '<script src="https://new.vendor.com/a.js"></script>' ), array( 'new.vendor.com' ), 'unknown: next to rewritten tags a new host is still found' );
$al_unk_rw = CMP_Blocker::rewrite( '<script src="https://new.vendor.com/a.js"></script><iframe src="https://forms.vendor.com/f"></iframe>', array(), $al_svcs );
cmp_assert_eq( $al_unknown( $al_unk_rw ), array( 'new.vendor.com', 'forms.vendor.com' ), 'unknown: an unknown host stays visible after our rewrite (it is not blocked)' );

// Not elements: comments, noscript, templates, script bodies.
cmp_assert_eq( $al_unknown( '<!-- <script src="https://c.vendor.com/a.js"></script> -->' ), array(), 'unknown: HTML comments are ignored' );
cmp_assert_eq( $al_unknown( '<noscript><iframe src="https://ns.vendor.com/x"></iframe></noscript>' ), array(), 'unknown: noscript content is ignored' );
cmp_assert_eq( $al_unknown( '<template><iframe src="https://t.vendor.com/x"></iframe></template>' ), array(), 'unknown: inert template content is ignored' );
cmp_assert_eq( $al_unknown( '<script>document.write(\'<iframe src="https://w.vendor.com/x"></iframe>\');</script>' ), array(), 'unknown: an iframe string inside a script body is not an element' );
// Markers inside script strings hide nothing: the page is read left to right (I5).
foreach ( array( '"<!--"', '"<noscript>"', '"<template>"', '"<template id=x>"', "'<iframe src=\"'", '"<script src=https://str.vendor.com/x.js>"' ) as $al_mk ) {
	cmp_assert_eq( $al_unknown( '<script>var a=' . $al_mk . ';</script><script src="https://a.t.com/x.js"></script><!-- c --><noscript>n</noscript><template>t</template><script src="https://b.t.com/x.js"></script>' ), array( 'a.t.com', 'b.t.com' ), "unknown: $al_mk in a script string hides no later tag" );
}
cmp_assert_eq( $al_unknown( '<!-- <script> --><script src="https://a.t.com/x.js"></script><!-- </script> -->' ), array( 'a.t.com' ), 'unknown: a script tag written in a comment does not swallow the page' );
foreach ( array( '<style>/* <!-- */</style>', '<textarea><!--</textarea>', '<title><!-- t</title>', '<style>a:before{content:"<noscript>"}</style>', '<textarea><script></textarea>', '<textarea><iframe src="https://no.t.com/x"></textarea>', '<svg><title/><style/></svg>' ) as $al_mk ) {
	cmp_assert_eq( $al_unknown( $al_mk . '<script src="https://a.t.com/x.js"></script><!-- c --><noscript>n</noscript><title>t</title><style>s</style>' ), array( 'a.t.com' ), "unknown: $al_mk is text, hides nothing and shows nothing (R3)" );
}
cmp_assert_eq( $al_unknown( '<!--[if !IE]><!--><script src="https://a.t.com/x.js"></script><!--<![endif]-->' ), array( 'a.t.com' ), 'unknown: a revealed conditional comment shows its script' );
cmp_assert_eq( $al_unknown( '<!--><script src="https://a.t.com/x.js"></script><!-- x -->' ), array( 'a.t.com' ), 'unknown: an empty comment ends at once' );
cmp_assert_eq( $al_unknown( '<noscript><script src="https://n.t.com/x.js"></script></noscript><template><script src="https://n2.t.com/x.js"></script></template><script src="https://a.t.com/x.js"></script>' ), array( 'a.t.com' ), 'unknown: noscript and template content stay out' );
cmp_assert_eq( $al_unknown( '<template-part><script src="https://a.t.com/x.js"></script></template-part><script-loader src="https://no.t.com/x"></script-loader><script src="https://b.t.com/x.js"></script>' ), array( 'a.t.com', 'b.t.com' ), 'unknown: custom elements are not template or script' );
cmp_assert_eq( $al_unknown( '<!-- never closed <script src="https://a.t.com/x.js"></script>' ), array( 'a.t.com' ), 'unknown: a comment never closed hides nothing (read as more, never as less)' );
cmp_assert_eq( $al_unknown( '<script src="https://a.t.com/x.js"></script >x<script src="https://b.t.com/x.js"></script>' ), array( 'a.t.com', 'b.t.com' ), 'unknown: a closing tag with a space' );
cmp_assert_eq( $al_unknown( '<script>' . str_repeat( 'var a="<!--";', 300000 ) . '</script><script src="https://a.t.com/x.js"></script>' ), array( 'a.t.com' ), 'unknown: a 4 MB inline script does not stop the reading' );
// When the regex engine gives up, the page counts as not read, never as "read, nothing unknown".
$al_give = 'require ' . var_export( $al_root . 'includes/modules/class-scanner.php', true ) . '; $h = "<p>" . str_repeat( "<b ", 5000 ) . "><script src=https://a.t.com/x.js></script>"; ini_set( "pcre.jit", "0" ); ini_set( "pcre.backtrack_limit", "1" ); $r = CMP_Scanner::page_result( "https://example.md/", "ok", $h, array(), "https://example.md", 1 ); ini_set( "pcre.backtrack_limit", "1000000" ); $g = CMP_Scanner::aggregate( array( $r ) ); echo json_encode( array( array_key_exists( "unknown", $r ), $g["unknown_pages"], $g["unread"] ) );';
$al_give_out = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -d pcre.jit=0 -d display_errors=0 -r ' . escapeshellarg( $al_give ) . ' 2>/dev/null' );
cmp_assert_eq( $al_give_out, '[false,[],["\\/"]]', 'unknown: a PCRE failure leaves the page unread for unknown hosts (no key, listed in unread)' );
cmp_assert_eq( $al_unknown( '' ), array(), 'unknown: empty html' );
cmp_assert_eq( $al_unknown( '<script src="https://' . str_repeat( 'a', 300 ) . '.com/x.js"></script>' ), array(), 'unknown: an over-long host is dropped' );
cmp_assert_eq( $al_unknown( '<script src="https://ex ample.com/x.js"></script>' ), array(), 'unknown: an invalid host is dropped' );

// ---- page_result and aggregate ----
$al_p1 = CMP_Scanner::page_result( 'https://example.md/', 'ok', '<script src="https://a.vendor.com/1.js"></script><script src="https://b.vendor.com/1.js"></script>', $al_svcs, $al_home, 100 );
$al_p2 = CMP_Scanner::page_result( 'https://example.md/blog/post-1/?utm=1#x', 'ok', '<iframe src="https://a.vendor.com/f"></iframe>', $al_svcs, $al_home, 101 );
$al_pe = CMP_Scanner::page_result( 'https://example.md/broken/', '500', '<script src="https://z.vendor.com/1.js"></script>', $al_svcs, $al_home, 102 );
cmp_assert_eq( $al_p1['unknown'], array( 'a.vendor.com', 'b.vendor.com' ), 'page_result: unknown hosts of an ok page' );
cmp_assert( ! array_key_exists( 'unknown', $al_pe ), 'page_result: an error page has no unknown' );
$al_ag = CMP_Scanner::aggregate( array( $al_p1, $al_p2, $al_pe ) );
$al_hosts_of = function ( array $pages ) {
	return CMP_Alerts::by_host( CMP_Scanner::aggregate( $pages )['unknown_pages'] );
};
cmp_assert_eq( $al_hosts_of( array( $al_p1, $al_p2, $al_pe ) ), array( 'a.vendor.com' => array( '/', '/blog/post-1/' ), 'b.vendor.com' => array( '/' ) ), 'by_host: host => page paths, without query or fragment' );
cmp_assert_eq( $al_hosts_of( array( $al_pe ) ), array(), 'aggregate: no pages, no unknown' );
cmp_assert( ! array_key_exists( 'unknown', $al_ag ), 'aggregate: one list only (unknown_pages)' );
$al_many = array();
for ( $i = 1; $i <= 5; $i++ ) {
	$al_many[] = CMP_Scanner::page_result( 'https://example.md/p' . $i . '/', 'ok', '<script src="https://one.vendor.com/1.js"></script>', $al_svcs, $al_home, 100 + $i );
}
cmp_assert_eq( count( $al_hosts_of( $al_many )['one.vendor.com'] ), 3, 'by_host: at most 3 paths per host' );
$al_html = '';
for ( $i = 1; $i <= 260; $i++ ) {
	$al_html .= '<script src="https://h' . $i . '.vendor.com/1.js"></script>';
}
$al_big = CMP_Scanner::page_result( 'https://example.md/', 'ok', $al_html, $al_svcs, $al_home, 1 );
cmp_assert_eq( count( $al_big['unknown'] ), 200, 'page_result: at most 200 hosts per page are stored (M14)' );
cmp_assert_eq( $al_big['unknown'][0], 'h1.vendor.com', 'page_result: the first ones are kept' );
// Two addresses with the same path (plain permalinks: /?p=1, /?p=2): the hosts of both are kept, and a path read once is not "unread".
$al_q1 = CMP_Scanner::page_result( 'https://example.md/', 'ok', $al_html, $al_svcs, $al_home, 1 );
$al_q2 = CMP_Scanner::page_result( 'https://example.md/?p=2', 'ok', '<script src="https://two.other.com/1.js"></script>', $al_svcs, $al_home, 2 );
$al_q3 = CMP_Scanner::page_result( 'https://example.md/?p=3', '500', '', $al_svcs, $al_home, 3 );
$al_q  = CMP_Scanner::aggregate( array( $al_q2, $al_q1, $al_q3 ) );
cmp_assert( in_array( 'two.other.com', $al_q['unknown_pages']['/'], true ) && in_array( 'h1.vendor.com', $al_q['unknown_pages']['/'], true ), 'aggregate: two pages with one path, both lists kept' );
cmp_assert_eq( count( $al_q['unknown_pages']['/'] ), 200, 'aggregate: the list of a path is capped (M14)' );
cmp_assert_eq( $al_q['unread'], array(), 'aggregate: a path read once is not unread' );
// A stale page (its last fetch failed) keeps its old good result: that must not produce news.
$al_stale = CMP_Scanner::merge_page_result( $al_p1, array( 'url' => 'https://example.md/', 'time' => 200, 'status' => 'error' ) );
cmp_assert( ! empty( $al_stale['stale'] ) && isset( $al_stale['unknown'] ), 'fixture: a stale page keeps its result' );
cmp_assert_eq( $al_hosts_of( array( $al_stale ) ), array(), 'aggregate: a stale page does not report unknown hosts' );
cmp_assert_eq( $al_hosts_of( array( $al_stale, $al_p2 ) ), array( 'a.vendor.com' => array( '/blog/post-1/' ) ), 'aggregate: only the fresh pages report' );
$al_old = $al_p1;
unset( $al_old['unknown'] );
cmp_assert_eq( $al_hosts_of( array( $al_old ) ), array(), 'aggregate: a result saved before the update (no unknown key) is fine' );

// Which pages were really read: unknown_pages (path => hosts, fresh pages that carry the unknown key), unread (the rest of the list).
$al_ag2 = CMP_Scanner::aggregate( array( $al_p1, $al_p2, $al_pe ) );
cmp_assert_eq( $al_ag2['unknown_pages'], array( '/' => array( 'a.vendor.com', 'b.vendor.com' ), '/blog/post-1/' => array( 'a.vendor.com' ) ), 'aggregate: unknown_pages = path => hosts of the pages read now' );
cmp_assert_eq( $al_ag2['unread'], array( '/broken/' ), 'aggregate: unread = pages of the list that were not read' );
cmp_assert_eq( CMP_Scanner::aggregate( array( $al_stale ) )['unread'], array( '/' ), 'aggregate: a stale page is unread (its old result says nothing new)' );
cmp_assert_eq( CMP_Scanner::aggregate( array( $al_stale ) )['unknown_pages'], array(), 'aggregate: ... and reports no hosts' );
cmp_assert_eq( CMP_Scanner::aggregate( array( $al_old ) )['unread'], array( '/' ), 'aggregate: a result saved before the update (no unknown key) is unread' );
cmp_assert_eq( CMP_Scanner::aggregate( array( $al_old ) )['unknown_pages'], array(), 'aggregate: ... and has no hosts' );
cmp_assert_eq( CMP_Scanner::aggregate( array( CMP_Scanner::page_result( 'https://example.md/empty/', 'ok', '<p>x</p>', $al_svcs, $al_home, 1 ) ) )['unknown_pages'], array( '/empty/' => array() ), 'aggregate: a page read with no unknown host is still listed as read' );
cmp_assert( ! array_key_exists( 'unknown_pages', CMP_Scanner::legacy_copy( $al_ag2 ) ) && ! array_key_exists( 'unread', CMP_Scanner::legacy_copy( $al_ag2 ) ), 'legacy_copy: neither key reaches the autoloaded copy' );

// ---- CMP_Alerts::step() ----
$al_now = 1800000000;
$al_day = 86400;
$al_f   = function ( array $hosts ) {
	$out = array();
	foreach ( $hosts as $h ) {
		$out[ $h ] = array( '/' );
	}
	return $out;
};
// Baseline: no state.
$s = CMP_Alerts::step( false, $al_f( array( 'a.com', 'b.com' ) ), $al_now, true );
cmp_assert_eq( $s['send'], array(), 'step: baseline sends nothing' );
cmp_assert_eq( $s['state']['known'], array( 'a.com', 'b.com' ), 'step: baseline makes everything found known' );
cmp_assert_eq( $s['state']['pending'], array(), 'step: baseline leaves nothing pending' );
foreach ( array( null, '', 'x', 5, array() ) as $al_bad ) {
	$s = CMP_Alerts::step( $al_bad, $al_f( array( 'a.com' ) ), $al_now, true );
	cmp_assert( array() === $s['send'] && array( 'a.com' ) === $s['state']['known'], 'step: a missing or corrupt state is a baseline (' . gettype( $al_bad ) . ')' );
}
$al_s0 = CMP_Alerts::step( false, $al_f( array( 'a.com' ) ), $al_now, true )['state'];
// New host: pending and sent.
$s = CMP_Alerts::step( $al_s0, $al_f( array( 'a.com', 'n.com' ) ), $al_now + $al_day, true );
cmp_assert_eq( array_keys( $s['send'] ), array( 'n.com' ), 'step: a new host is sent' );
cmp_assert_eq( $s['send']['n.com'], array( '/' ), 'step: the send list carries the paths' );
cmp_assert( in_array( 'n.com', $s['state']['known'], true ), 'step: the new host becomes known' );
cmp_assert_eq( $s['state']['pending'], array(), 'step: what is being sent leaves pending' );
cmp_assert_eq( array_keys( $s['state']['sending'] ), array( 'n.com' ), 'step: what is being sent is marked sending (recoverable)' );
$al_sent = $s['state'];
// mailed().
$m = CMP_Alerts::mailed( $al_sent, true, $al_now + $al_day );
cmp_assert( array() === $m['pending'] && array() === $m['sending'] && $al_now + $al_day === $m['mailed_at'], 'mailed(true): done, time recorded' );
$m = CMP_Alerts::mailed( $al_sent, false, $al_now + $al_day );
cmp_assert_eq( array_keys( $m['pending'] ), array( 'n.com' ), 'mailed(false): the host is pending again' );
cmp_assert_eq( $m['sending'], array(), 'mailed(false): nothing left sending' );
cmp_assert_eq( $m['mailed_at'], 0, 'mailed(false): mailed_at is not advanced' );
cmp_assert( in_array( 'n.com', $m['known'], true ), 'mailed(false): the host stays known' );
$s = CMP_Alerts::step( $m, $al_f( array( 'a.com', 'n.com' ) ), $al_now + $al_day + 3600, true );
cmp_assert_eq( $s['send'], array(), 'step: right after a failure nothing is retried (no retry on every scan trigger)' );
cmp_assert_eq( array_keys( $s['state']['pending'] ), array( 'n.com' ), 'step: the failed host waits pending' );
$s = CMP_Alerts::step( $m, $al_f( array( 'a.com', 'n.com' ) ), $al_now + $al_day + 13 * 3600, true );
cmp_assert_eq( array_keys( $s['send'] ), array( 'n.com' ), 'step: the next scan after the retry delay sends it again' );
cmp_assert_eq( CMP_Alerts::mailed( array_merge( $al_sent, array( 'failed_at' => 5 ) ), true, $al_now + 5 )['failed_at'], 0, 'mailed(true): clears the failure time' );
// 7 days.
$al_ok = CMP_Alerts::mailed( $al_sent, true, $al_now + $al_day );
$s     = CMP_Alerts::step( $al_ok, $al_f( array( 'a.com', 'n.com', 'm.com' ) ), $al_now + 3 * $al_day, true );
cmp_assert_eq( $s['send'], array(), 'step: a second new host within 7 days is not sent yet' );
cmp_assert_eq( array_keys( $s['state']['pending'] ), array( 'm.com' ), 'step: it stays pending' );
$s = CMP_Alerts::step( $s['state'], $al_f( array( 'a.com', 'n.com', 'm.com', 'o.com' ) ), $al_now + 4 * $al_day, true );
cmp_assert_eq( $s['send'], array(), 'step: still within 7 days' );
cmp_assert_eq( array_keys( $s['state']['pending'] ), array( 'm.com', 'o.com' ), 'step: pending hosts collect' );
$s = CMP_Alerts::step( $s['state'], array(), $al_now + $al_day + 7 * $al_day - 1, true );
cmp_assert_eq( $s['send'], array(), 'step: one second before 7 days: nothing' );
$s = CMP_Alerts::step( $s['state'], array(), $al_now + $al_day + 7 * $al_day, true );
cmp_assert_eq( array_keys( $s['send'] ), array( 'm.com', 'o.com' ), 'step: at 7 days all pending hosts go together (even when no longer found today)' );
// Disappears and returns.
$al_after = CMP_Alerts::mailed( $s['state'], true, $al_now + 8 * $al_day );
$s        = CMP_Alerts::step( $al_after, array(), $al_now + 20 * $al_day, true );
$s        = CMP_Alerts::step( $s['state'], $al_f( array( 'n.com', 'm.com' ) ), $al_now + 40 * $al_day, true );
cmp_assert_eq( $s['send'], array(), 'step: a host that disappeared and returned is not announced again' );
cmp_assert_eq( $s['state']['pending'], array(), 'step: nor pending' );
// Ignored.
$al_ig = CMP_Alerts::ignore( $al_s0, 'i.com' );
cmp_assert_eq( $al_ig['ignored'], array( 'i.com' ), 'ignore: recorded' );
$s = CMP_Alerts::step( $al_ig, $al_f( array( 'a.com', 'i.com' ) ), $al_now + 30 * $al_day, true );
cmp_assert_eq( $s['send'], array(), 'step: an ignored host is never sent' );
cmp_assert( in_array( 'i.com', $s['state']['known'], true ) && ! isset( $s['state']['pending']['i.com'] ), 'step: ignored: known, not pending' );
$al_pend = CMP_Alerts::step( $al_ok, $al_f( array( 'p.com' ) ), $al_now + 2 * $al_day, true )['state'];
cmp_assert( isset( $al_pend['pending']['p.com'] ), 'fixture: p.com pending' );
$al_ig2 = CMP_Alerts::ignore( $al_pend, 'p.com' );
cmp_assert( ! isset( $al_ig2['pending']['p.com'] ) && in_array( 'p.com', $al_ig2['ignored'], true ), 'ignore: leaves pending' );
cmp_assert_eq( CMP_Alerts::step( $al_ig2, array(), $al_now + 30 * $al_day, true )['send'], array(), 'ignore: a pending host that was ignored is not sent later' );
cmp_assert_eq( CMP_Alerts::ignore( CMP_Alerts::ignore( $al_s0, 'i.com' ), 'i.com' )['ignored'], array( 'i.com' ), 'ignore: twice is once' );
$al_un = CMP_Alerts::unignore( $al_ig, 'i.com' );
cmp_assert_eq( $al_un['ignored'], array(), 'unignore: back on the list' );
cmp_assert( in_array( 'i.com', $al_un['known'], true ), 'unignore: the host stays known (no email for it)' );
cmp_assert_eq( CMP_Alerts::step( $al_un, $al_f( array( 'i.com' ) ), $al_now + 30 * $al_day, true )['send'], array(), 'unignore: not announced' );
// ignore on no state must not turn the next scan into a mass announcement.
$al_ign0 = CMP_Alerts::ignore( false, 'i.com' );
$s       = CMP_Alerts::step( $al_ign0, $al_f( array( 'i.com', 'x.com', 'y.com' ) ), $al_now, true );
cmp_assert_eq( $s['send'], array(), 'ignore: with no state yet, the next scan is still the baseline' );
cmp_assert( in_array( 'x.com', $s['state']['known'], true ) && array() === $s['state']['pending'], 'ignore: ... and takes everything as known' );
// Disabled.
$s = CMP_Alerts::step( $al_s0, $al_f( array( 'a.com', 'off.com' ) ), $al_now + 30 * $al_day, false );
cmp_assert_eq( $s['send'], array(), 'step: disabled sends nothing' );
cmp_assert( in_array( 'off.com', $s['state']['known'], true ), 'step: disabled still records the host as known' );
cmp_assert_eq( $s['state']['pending'], array(), 'step: disabled does not queue it' );
$s = CMP_Alerts::step( $s['state'], $al_f( array( 'a.com', 'off.com' ) ), $al_now + 60 * $al_day, true );
cmp_assert_eq( $s['send'], array(), 'step: switched on later, a host seen while off is not sent' );
$s = CMP_Alerts::step( $s['state'], $al_f( array( 'a.com', 'off.com', 'fresh.com' ) ), $al_now + 61 * $al_day, true );
cmp_assert_eq( array_keys( $s['send'] ), array( 'fresh.com' ), 'step: a host first seen after switching on is sent' );
$s = CMP_Alerts::step( false, $al_f( array( 'a.com' ) ), $al_now, false );
cmp_assert( array( 'a.com' ) === $s['state']['known'] && array() === $s['send'], 'step: disabled with no state: baseline too' );
$al_pend_off = CMP_Alerts::step( $al_s0, $al_f( array( 'a.com', 'q.com' ) ), $al_now + 2 * $al_day, true )['state'];
$al_pend_off = CMP_Alerts::mailed( $al_pend_off, false, $al_now + 2 * $al_day );
cmp_assert_eq( CMP_Alerts::step( $al_pend_off, array(), $al_now + 99 * $al_day, false )['send'], array(), 'step: disabled does not send what was already pending either' );
// A crash between the state write and the mail leaves "sending": it is recovered, not lost.
$s = CMP_Alerts::step( $al_sent, array(), $al_now + 2 * $al_day, true );
cmp_assert( isset( $s['state']['pending']['n.com'] ) || isset( $s['send']['n.com'] ), 'step: hosts left in "sending" by a crashed run are not lost' );
// Caps.
$al_kn = array( 'known' => array(), 'ignored' => array(), 'pending' => array(), 'sending' => array(), 'mailed_at' => 0, 'failed_at' => 0, 'base' => true );
for ( $i = 0; $i < 1500; $i++ ) {
	$al_kn['known'][] = 'k' . $i . '.com';
}
$s = CMP_Alerts::step( $al_kn, $al_f( array( 'z.com' ) ), $al_now, false );
cmp_assert( count( $s['state']['known'] ) <= 1000 && in_array( 'z.com', $s['state']['known'], true ), 'step: known is capped, the newest kept' );
// M3: known keeps what is seen now; the caps never turn a host known without announcing it.
$al_cl   = array_merge( $al_kn, array( 'known' => array() ) );
$al_perm = array_merge( $al_cl, array( 'known' => array( 'perm.com' ) ) );
for ( $i = 0; $i < 999; $i++ ) {
	$al_perm['known'][] = 'k' . $i . '.com';
}
$s = CMP_Alerts::step( $al_perm, $al_f( array( 'perm.com', 'x1.com' ) ), $al_now, false );
cmp_assert( in_array( 'perm.com', $s['state']['known'], true ) && ! in_array( 'k0.com', $s['state']['known'], true ) && 1000 === count( $s['state']['known'] ), 'step: known drops the host seen longest ago, not the one still on the site' );
$al_full = $al_cl;
for ( $i = 0; $i < 200; $i++ ) {
	$al_full['pending'][ 'p' . $i . '.com' ] = array( '/' );
}
$al_full['known']     = array_keys( $al_full['pending'] );
$al_full['mailed_at'] = $al_now - 10;
$s = CMP_Alerts::step( $al_full, $al_f( array( 'late.com' ) ), $al_now, true );
cmp_assert( ! in_array( 'late.com', $s['state']['known'], true ) && ! isset( $s['state']['pending']['late.com'] ) && 200 === count( $s['state']['pending'] ), 'step: pending full: the host is neither queued nor known' );
$al_full2 = CMP_Alerts::mailed( CMP_Alerts::step( $s['state'], array(), $al_now + 8 * $al_day, true )['state'], true, $al_now + 8 * $al_day );
$s = CMP_Alerts::step( $al_full2, $al_f( array( 'late.com' ) ), $al_now + 9 * $al_day, true );
cmp_assert( isset( $s['state']['pending']['late.com'] ), 'step: ... and is queued once there is room' );
// M2: the cap of new hosts per scan comes after the known and the ignored ones.
$al_h60 = array();
for ( $i = 0; $i < 60; $i++ ) {
	$al_h60[] = 'site' . $i . '.com';
}
$al_60k = array_merge( $al_cl, array( 'known' => $al_h60 ) );
$s = CMP_Alerts::step( $al_60k, $al_f( array_merge( $al_h60, array( 'new.com' ) ) ), $al_now, true );
cmp_assert_eq( array_keys( $s['send'] ), array( 'new.com' ), 'step: 60 known hosts before it do not hide a new one' );
$s = CMP_Alerts::step( array_merge( $al_cl, array( 'ignored' => $al_h60 ) ), $al_f( array_merge( $al_h60, array( 'new.com' ) ) ), $al_now, true );
cmp_assert_eq( array_keys( $s['send'] ), array( 'new.com' ), 'step: nor do 60 ignored ones' );
$s = CMP_Alerts::step( $al_cl, $al_f( $al_h60 ), $al_now, true );
cmp_assert( 50 === count( $s['send'] ) && 50 === count( $s['state']['known'] ), 'step: at most 50 new hosts per scan; the others are not known yet' );
$s = CMP_Alerts::step( CMP_Alerts::mailed( $s['state'], true, $al_now ), $al_f( $al_h60 ), $al_now + 8 * $al_day, true );
cmp_assert_eq( count( $s['send'] ), 10, 'step: the rest comes at a later scan' );
$s = CMP_Alerts::step( $al_cl, $al_f( $al_h60 ), $al_now, false );
cmp_assert_eq( count( $s['state']['known'] ), 60, 'step: off: all are recorded at once' );
// M3: rotating subdomains: at most 10 hosts of one domain are ever announced, and they do not fill "known".
foreach ( array( 'rot.ads.com', 'adx.io', 't.co', 'fb.me', 'abc.md', 'x.com.md', 'ads.co.uk' ) as $al_dom ) {
	$al_rot  = array_merge( $al_cl, array( 'known' => array( 'perm.com', 'www.perm2.com' ) ) );
	$al_sent_n = 0;
	$al_mails  = 0;
	for ( $d = 0; $d < 120; $d++ ) {
		$al_today = array( 'perm.com', 'www.perm2.com' );
		for ( $i = 0; $i < 49; $i++ ) {
			$al_today[] = 'r' . $d . 'x' . $i . '.' . $al_dom;
		}
		if ( 100 === $d ) {
			$al_today[] = 'real.tracker.net';
		}
		$s = CMP_Alerts::step( $al_rot, $al_f( $al_today ), $al_now + $d * $al_day, true );
		$al_rot = $s['state'];
		if ( $s['send'] ) {
			$al_mails++;
			$al_sent_n += count( $s['send'] );
			$al_rot  = CMP_Alerts::mailed( $al_rot, true, $al_now + $d * $al_day );
			$al_last = array_keys( $s['send'] );
		}
	}
	cmp_assert( 2 === $al_mails && 11 === $al_sent_n && array( 'real.tracker.net' ) === $al_last, "step: 49 random *.$al_dom a day for 4 months: one mail about 10 of them, then only the real new host (mails $al_mails, hosts $al_sent_n)" );
	cmp_assert( in_array( 'perm.com', $al_rot['known'], true ) && count( $al_rot['known'] ) < 20 && 11 === count( $al_rot['told'] ), "step: *.$al_dom: the random hosts do not push the others out of known" );
}
// The cap counts what was announced, not what the site had at the baseline.
$al_b10 = array();
for ( $i = 0; $i < 10; $i++ ) {
	$al_b10[] = 'b' . $i . '.cdn.net';
}
$al_base10 = CMP_Alerts::step( false, $al_f( $al_b10 ), $al_now, true )['state'];
cmp_assert_eq( count( $al_base10['known'] ), 10, 'baseline: 10 hosts of one domain, all known' );
cmp_assert_eq( array_keys( CMP_Alerts::step( $al_base10, $al_f( array_merge( $al_b10, array( 'new.cdn.net' ) ) ), $al_now + $al_day, true )['send'] ), array( 'new.cdn.net' ), 'step: the 11th host of a domain with 10 at the baseline is announced' );
$al_base30 = CMP_Alerts::step( false, $al_f( array_map( function ( $n ) { return 'c' . $n . '.cdn.net'; }, range( 1, 30 ) ) ), $al_now, true )['state'];
cmp_assert_eq( count( $al_base30['known'] ), 30, 'baseline: every host is known, none is announced later' );
$al_failed = CMP_Alerts::mailed( CMP_Alerts::step( $al_cl, $al_f( $al_b10 ), $al_now, true )['state'], false, $al_now );
cmp_assert( array() === $al_failed['told'] && 10 === count( $al_failed['pending'] ), 'mailed(false): nothing counts as announced' );
cmp_assert_eq( count( CMP_Alerts::step( $al_failed, $al_f( array( 'n1.cdn.net', 'n2.other.org' ) ), $al_now + $al_day, true )['send'] ), 11, 'step: what waits counts towards the 10 of its domain, another domain is free' );
$al_many_p = CMP_Alerts::step( $al_cl, $al_f( array_map( function ( $n ) { return 'site' . $n . '.org'; }, range( 1, 30 ) ) ), $al_now, true )['state'];
cmp_assert_eq( count( CMP_Alerts::mailed( $al_many_p, true, $al_now )['told'] ), 20, 'mailed(true): only the hosts the mail prints (20) count as announced' );
cmp_assert( array( 'a.com' ) === CMP_Alerts::norm_state( array( 'known' => array(), 'told' => array( 'A.com', 5, 'bad host' ) ) )['told'], 'norm_state: told is cleaned' );
$s = CMP_Alerts::step( $al_cl, $al_f( array( 'a.one.co.uk', 'a.two.co.uk', 'b.three.com.md', 'four.com.md' ) ), $al_now, true );
cmp_assert_eq( count( $s['send'] ), 4, 'step: domains under co.uk or com.md are told apart' );
$al_uk = array();
for ( $i = 0; $i < 15; $i++ ) {
	$al_uk[] = 'shop' . $i . '.co.uk';
}
cmp_assert_eq( count( CMP_Alerts::step( $al_cl, $al_f( $al_uk ), $al_now, true )['send'] ), 15, 'step: 15 different .co.uk domains are 15 domains' );
// Input hygiene: hosts and paths from the aggregate are normalised.
$s = CMP_Alerts::step( $al_s0, array( 'Bad Host' => array( '/' ), 'ok.com' => array( "/a\r\nBcc: x@y.z?x=1#f", '/b', '/c', '/d', '/e' ), 'x' => 'notarray' ), $al_now + 30 * $al_day, true );
cmp_assert_eq( array_keys( $s['send'] ), array( 'ok.com' ), 'step: an invalid host name is dropped' );
cmp_assert( count( $s['send']['ok.com'] ) <= 3, 'step: at most 3 paths per host' );
cmp_assert( 1 !== preg_match( '/[\r\n?#]/', implode( '', $s['send']['ok.com'] ) ), 'step: paths carry no line break, query or fragment' );

// A page that could not be read at the baseline must not announce its old hosts when it is first read later.
$al_bp  = array( 'read' => array( '/' => array( 'a.com' ) ), 'unread' => array( '/late/' ) );
$al_b1  = CMP_Alerts::step( false, $al_f( array( 'a.com' ) ), $al_now, true, $al_bp );
cmp_assert_eq( $al_b1['state']['silent'], array( '/late/' ), 'baseline: the pages not read are remembered' );
cmp_assert_eq( $al_b1['send'], array(), 'baseline: still nothing sent' );
$al_b2  = CMP_Alerts::step( $al_b1['state'], $al_f( array( 'a.com', 'old.com' ) ), $al_now + $al_day, true, array( 'read' => array( '/' => array( 'a.com' ), '/late/' => array( 'old.com' ) ), 'unread' => array() ) );
cmp_assert_eq( $al_b2['send'], array(), 'late page: its old hosts are not announced' );
cmp_assert( in_array( 'old.com', $al_b2['state']['known'], true ) && array() === $al_b2['state']['pending'], 'late page: they join known silently' );
cmp_assert_eq( $al_b2['state']['silent'], array(), 'late page: it is no longer silent after its first read' );
$al_b3  = CMP_Alerts::step( $al_b2['state'], $al_f( array( 'a.com', 'old.com', 'fresh.com' ) ), $al_now + 9 * $al_day, true, array( 'read' => array( '/' => array( 'a.com', 'fresh.com' ), '/late/' => array( 'old.com' ) ), 'unread' => array() ) );
cmp_assert_eq( array_keys( $al_b3['send'] ), array( 'fresh.com' ), 'late page: once read, a really new host on any page is announced' );
// I4: the same host on a page that is not silent is news, whatever the silent page shows.
$al_b6 = CMP_Alerts::step( $al_b1['state'], $al_f( array( 'a.com', 'tracker.com', 'old.com' ) ), $al_now + $al_day, true, array( 'read' => array( '/' => array( 'a.com', 'tracker.com' ), '/late/' => array( 'tracker.com', 'old.com' ) ), 'unread' => array() ) );
cmp_assert_eq( array_keys( $al_b6['send'] ), array( 'tracker.com' ), 'late page: a host that a page read at the baseline shows now is announced' );
cmp_assert( in_array( 'old.com', $al_b6['state']['known'], true ), 'late page: the host only it shows joins known silently' );
$al_b7 = CMP_Alerts::step( array_merge( $al_b1['state'], array( 'silent' => array( '/late/', '/late2/' ) ) ), $al_f( array( 'a.com', 'old.com' ) ), $al_now + $al_day, true, array( 'read' => array( '/' => array( 'a.com' ), '/late/' => array( 'old.com' ), '/late2/' => array( 'old.com' ) ), 'unread' => array() ) );
cmp_assert_eq( $al_b7['send'], array(), 'late page: two silent pages with the same old host stay silent' );
$al_b8 = CMP_Alerts::step( $al_b1['state'], $al_f( array( 'a.com', 'new.com' ) ), $al_now + $al_day, true, array( 'read' => array( '/brandnew/' => array( 'new.com' ), '/late/' => array( 'new.com' ) ), 'unread' => array( '/' ) ) );
cmp_assert_eq( array_keys( $al_b8['send'] ), array( 'new.com' ), 'late page: nor does it hide what a brand-new page shows' );
// Still unread: nothing changes. A brand-new page (not in the baseline list) announces its hosts.
$al_b4  = CMP_Alerts::step( $al_b1['state'], $al_f( array( 'a.com', 'new.com' ) ), $al_now + $al_day, true, array( 'read' => array( '/' => array( 'a.com' ), '/brandnew/' => array( 'new.com' ) ), 'unread' => array( '/late/' ) ) );
cmp_assert_eq( array_keys( $al_b4['send'] ), array( 'new.com' ), 'brand-new page: its hosts are still announced' );
cmp_assert_eq( $al_b4['state']['silent'], array( '/late/' ), 'unread page: stays silent until its first read' );
// A late page that shows nothing new: silent is cleared too.
$al_b5  = CMP_Alerts::step( $al_b1['state'], $al_f( array( 'a.com' ) ), $al_now + $al_day, true, array( 'read' => array( '/' => array( 'a.com' ), '/late/' => array() ), 'unread' => array() ) );
cmp_assert_eq( $al_b5['state']['silent'], array(), 'late page with no unknown host: no longer silent' );
// Old callers (no page info) and an old stored state without the key.
cmp_assert_eq( CMP_Alerts::step( $al_s0, $al_f( array( 'a.com', 'n.com' ) ), $al_now + $al_day, true )['state']['silent'], array(), 'step: no page info, nothing silent' );
$al_nosilent = $al_s0;
unset( $al_nosilent['silent'] );
cmp_assert_eq( array_keys( CMP_Alerts::step( $al_nosilent, $al_f( array( 'n.com' ) ), $al_now + $al_day, true, array( 'read' => array( '/x/' => array( 'n.com' ) ), 'unread' => array() ) )['send'] ), array( 'n.com' ), 'step: a state saved without the key behaves as before' );
$al_hostile = CMP_Alerts::norm_state( array( 'known' => array(), 'silent' => array( "/a\r\nx?q=1", 5, array(), str_repeat( '/z', 300 ) ) ) );
cmp_assert( array( '/a' ) === array_slice( $al_hostile['silent'], 0, 1 ) && 1 !== preg_match( '/[\r\n?]/', implode( '', $al_hostile['silent'] ) ) && strlen( $al_hostile['silent'][ count( $al_hostile['silent'] ) - 1 ] ) <= 200, 'norm_state: silent paths are cleaned' );
$al_manysilent = array();
for ( $i = 0; $i < 150; $i++ ) {
	$al_manysilent[] = '/p' . $i . '/';
}
cmp_assert( count( CMP_Alerts::step( false, array(), $al_now, true, array( 'read' => array(), 'unread' => $al_manysilent ) )['state']['silent'] ) <= 100, 'baseline: the silent list is capped' );
// ---- CMP_Alerts::process(): the WordPress side through callbacks ----
$al_io = function ( &$store, &$log, array $over = array() ) use ( $al_now ) {
	return array_merge(
		array(
			'lock'    => function () use ( &$log ) { $log[] = 'lock'; return true; },
			'unlock'  => function () use ( &$log ) { $log[] = 'unlock'; },
			'read'    => function () use ( &$store ) { return $store; },
			'write'   => function ( $v ) use ( &$store, &$log ) { $store = $v; $log[] = 'write'; },
			'mail'    => function ( $subject, $body ) use ( &$log ) { $log[] = 'mail'; return true; },
			'covered' => function ( $host ) { return false; },
			'enabled' => true,
			'now'     => $al_now,
			'site'    => 'Example',
			'url'     => 'https://example.md/wp-admin/admin.php?page=cmp-services',
		),
		$over
	);
};
$al_agg = function ( array $hosts ) {
	return array( 'pages' => 2, 'unknown_pages' => array( '/' => $hosts ), 'unread' => array() );
};
$store = false;
$log   = array();
cmp_assert_eq( CMP_Alerts::process( $al_agg( array( 'a.com' ) ), $al_io( $store, $log ) ), 'baseline', 'process: first scan is the baseline' );
cmp_assert( ! in_array( 'mail', $log, true ) && is_array( $store ) && array( 'a.com' ) === $store['known'], 'process: baseline writes the state and sends nothing' );
$log = array();
$al_io1 = $al_io( $store, $log, array( 'now' => $al_now + $al_day ) );
cmp_assert_eq( CMP_Alerts::process( $al_agg( array( 'a.com', 'n.com' ) ), $al_io1 ), 'sent', 'process: a new host is mailed' );
cmp_assert_eq( $log, array( 'lock', 'write', 'mail', 'write', 'unlock' ), 'process: lock, state written BEFORE the mail, mail, result written, unlock' );
cmp_assert( array() === $store['pending'] && array() === $store['sending'] && $al_now + $al_day === $store['mailed_at'], 'process: after a successful mail nothing is pending' );
// Failure.
$store = CMP_Alerts::step( $al_s0, array(), $al_now, true )['state'];
$log   = array();
$al_io2 = $al_io( $store, $log, array( 'now' => $al_now + $al_day, 'mail' => function () use ( &$log ) { $log[] = 'mail'; return false; } ) );
cmp_assert_eq( CMP_Alerts::process( $al_agg( array( 'a.com', 'n.com' ) ), $al_io2 ), 'failed', 'process: a failed wp_mail is reported' );
cmp_assert( isset( $store['pending']['n.com'] ) && array() === $store['sending'] && 0 === $store['mailed_at'], 'process: after a failure the host is pending again, mailed_at unchanged' );
$log = array();
$al_io2b = $al_io( $store, $log, array( 'now' => $al_now + $al_day + 100, 'mail' => function () use ( &$log ) { $log[] = 'mail'; return true; } ) );
CMP_Alerts::process( $al_agg( array( 'a.com', 'n.com' ) ), $al_io2b );
cmp_assert( ! in_array( 'mail', $log, true ), 'process: a scan finishing soon after a failure does not retry' );
$log = array();
$al_io2c = $al_io( $store, $log, array( 'now' => $al_now + 2 * $al_day, 'mail' => function () use ( &$log ) { $log[] = 'mail'; return true; } ) );
CMP_Alerts::process( $al_agg( array( 'a.com', 'n.com' ) ), $al_io2c );
cmp_assert( in_array( 'mail', $log, true ) && array() === $store['pending'], 'process: the next daily scan retries and succeeds' );
// A mail that throws counts as failed.
$store = CMP_Alerts::step( $al_s0, array(), $al_now, true )['state'];
$log   = array();
$al_io3 = $al_io( $store, $log, array( 'now' => $al_now + $al_day, 'mail' => function () { throw new RuntimeException( 'smtp' ); } ) );
cmp_assert_eq( CMP_Alerts::process( $al_agg( array( 'a.com', 'n.com' ) ), $al_io3 ), 'failed', 'process: a throwing mailer is a failure' );
cmp_assert( isset( $store['pending']['n.com'] ) && in_array( 'unlock', $log, true ), 'process: ... the host is not lost and the lock is released' );
// Concurrency: a second finish while the first holds the lock does nothing.
$store = CMP_Alerts::step( $al_s0, array(), $al_now, true )['state'];
$log   = array();
$al_io4 = $al_io( $store, $log, array( 'now' => $al_now + $al_day, 'lock' => function () use ( &$log ) { $log[] = 'lock-denied'; return false; } ) );
cmp_assert_eq( CMP_Alerts::process( $al_agg( array( 'a.com', 'n.com' ) ), $al_io4 ), 'locked', 'process: no lock, no work' );
cmp_assert( array( 'lock-denied' ) === $log, 'process: a locked run touches nothing and does not unlock' );
// Sequential double finish (the second runs after the first finished): only one mail.
$store = CMP_Alerts::step( $al_s0, array(), $al_now, true )['state'];
$log   = array();
CMP_Alerts::process( $al_agg( array( 'a.com', 'n.com' ) ), $al_io( $store, $log, array( 'now' => $al_now + $al_day ) ) );
CMP_Alerts::process( $al_agg( array( 'a.com', 'n.com' ) ), $al_io( $store, $log, array( 'now' => $al_now + $al_day + 5 ) ) );
cmp_assert_eq( count( array_keys( $log, 'mail', true ) ), 1, 'process: two finishes in a row send one email' );
// Nothing read: state untouched.
$store = CMP_Alerts::step( $al_s0, array(), $al_now, true )['state'];
$keep  = $store;
$log   = array();
cmp_assert_eq( CMP_Alerts::process( array( 'pages' => 0, 'unknown_pages' => array() ), $al_io( $store, $log ) ), 'skipped', 'process: all pages failed (no pages)' );
cmp_assert( $store === $keep && array() === $log, 'process: all pages failed: state untouched, not even the lock' );
cmp_assert_eq( CMP_Alerts::process( array(), $al_io( $store, $log ) ), 'skipped', 'process: an empty aggregate is skipped' );
// Off then on, through process().
$store = false;
$log   = array();
CMP_Alerts::process( $al_agg( array( 'a.com' ) ), $al_io( $store, $log ) );
CMP_Alerts::process( $al_agg( array( 'a.com', 'seen-off.com' ) ), $al_io( $store, $log, array( 'enabled' => false, 'now' => $al_now + 30 * $al_day ) ) );
$log = array();
CMP_Alerts::process( $al_agg( array( 'a.com', 'seen-off.com' ) ), $al_io( $store, $log, array( 'now' => $al_now + 40 * $al_day ) ) );
cmp_assert( ! in_array( 'mail', $log, true ), 'process: off then on: the host seen while off is not mailed' );
// Pending host that became a service meanwhile is dropped.
$store = CMP_Alerts::step( $al_s0, $al_f( array( 'a.com', 'svc.com' ) ), $al_now + 2 * $al_day, true );
$store = CMP_Alerts::mailed( $store['state'], false, $al_now + 2 * $al_day );
$log   = array();
CMP_Alerts::process( $al_agg( array( 'a.com' ) ), $al_io( $store, $log, array( 'now' => $al_now + 5 * $al_day, 'covered' => function ( $h ) { return 'svc.com' === $h; } ) ) );
cmp_assert( ! in_array( 'mail', $log, true ), 'process: a pending host the admin turned into a service is not mailed' );
// Hosts the services now cover are not recorded as pending from the aggregate.
$store = CMP_Alerts::step( $al_s0, array(), $al_now, true )['state'];
$log   = array();
CMP_Alerts::process( $al_agg( array( 'a.com', 'cov.com' ) ), $al_io( $store, $log, array( 'now' => $al_now + $al_day, 'covered' => function ( $h ) { return 'cov.com' === $h; } ) ) );
cmp_assert( ! in_array( 'mail', $log, true ), 'process: a found host that a service already covers is not mailed' );

// Through process(): the agg of the scanner carries it.
$store = false;
$log   = array();
$al_agg_b = array( 'pages' => 1, 'unknown_pages' => array( '/' => array( 'a.com' ) ), 'unread' => array( '/late/' ) );
CMP_Alerts::process( $al_agg_b, $al_io( $store, $log ) );
cmp_assert_eq( $store['silent'], array( '/late/' ), 'process: the baseline remembers the unread pages' );
$log = array();
$al_agg_l = array( 'pages' => 2, 'unknown_pages' => array( '/' => array( 'a.com' ), '/late/' => array( 'old.com' ) ), 'unread' => array() );
cmp_assert_eq( CMP_Alerts::process( $al_agg_l, $al_io( $store, $log, array( 'now' => $al_now + $al_day ) ) ), 'noop', 'process: the late page announces nothing' );
cmp_assert( ! in_array( 'mail', $log, true ) && in_array( 'old.com', $store['known'], true ) && array() === $store['silent'], 'process: no mail, host known, silent cleared' );

// M4: the baseline waits for a run that read the sitemap; later runs do not.
$store = false;
$log   = array();
cmp_assert_eq( CMP_Alerts::process( array_merge( $al_agg( array( 'a.com' ) ), array( 'sitemap' => false ) ), $al_io( $store, $log ) ), 'waiting', 'process: no baseline from a run without the sitemap' );
cmp_assert( false === $store && array( 'lock', 'unlock' ) === $log, 'process: ... nothing written, the lock released' );
cmp_assert_eq( CMP_Alerts::process( array_merge( $al_agg( array( 'a.com' ) ), array( 'sitemap' => true ) ), $al_io( $store, $log ) ), 'baseline', 'process: the first run with the sitemap is the baseline' );
cmp_assert_eq( CMP_Alerts::process( array_merge( $al_agg( array( 'a.com', 'n.com' ) ), array( 'sitemap' => false ) ), $al_io( $store, $log, array( 'now' => $al_now + $al_day ) ) ), 'sent', 'process: after the baseline a run without the sitemap announces as usual' );
// M2, through process(): more than 50 covered hosts on the home page do not hide a new one.
$store = CMP_Alerts::step( false, array(), $al_now, true )['state'];
$log   = array();
$al_body = '';
CMP_Alerts::process( $al_agg( array_merge( $al_h60, array( 'new.com' ) ) ), $al_io( $store, $log, array( 'now' => $al_now + $al_day, 'covered' => function ( $h ) { return 0 === strpos( $h, 'site' ); }, 'mail' => function ( $su, $bo ) use ( &$al_body ) { $al_body = $bo; return true; } ) ) );
cmp_assert( false !== strpos( $al_body, 'new.com' ), 'process: 60 covered hosts before it do not hide a new one' );

// ---- visible() for the Servicii screen ----
$al_vh = array();
for ( $i = 0; $i < 80; $i++ ) {
	$al_vh[ 'v' . $i . '.com' ] = array( '/' );
}
$al_vt = array_map( function ( $n ) { return 'v' . $n . '.com'; }, range( 0, 69 ) );
$al_vl = CMP_Alerts::visible( $al_vh, array( 'known' => array_keys( $al_vh ), 'told' => $al_vt, 'ignored' => array( 'v75.com' ), 'pending' => array( 'w.com' => array( '/' ) ) ), function ( $h ) { return 'v76.com' === $h; } );
cmp_assert( 50 === count( $al_vl ) && array( 'v70.com', 'v71.com' ) === array_slice( array_keys( $al_vl ), 0, 2 ) && isset( $al_vl['v79.com'], $al_vl['w.com'] ) && ! isset( $al_vl['v75.com'] ) && ! isset( $al_vl['v76.com'] ), 'visible: the hosts never announced come first, the announced ones fill up to 50' );
cmp_assert_eq( count( CMP_Alerts::visible( $al_vh, array( 'known' => array_keys( $al_vh ) ), function () { return false; } ) ), 80, 'visible: a host never announced is listed beyond the 50 rows of the announced ones' );
$al_vb = $al_f( array_map( function ( $n ) { return 'big' . $n . '.com'; }, range( 1, 400 ) ) );
cmp_assert_eq( array( count( CMP_Alerts::visible( $al_vb, false, function () { return false; } ) ), array_keys( CMP_Alerts::visible( $al_vb, array( 'known' => array(), 'told' => array( 'big1.com' ) ), function () { return false; } ) )[0] ), array( 200, 'big2.com' ), 'visible: at most 200 hosts never announced (S4)' );
$al_vr = array_merge( $al_rot, array( 'ignored' => array() ) );
cmp_assert( isset( CMP_Alerts::visible( $al_f( array( 'r119x48.ads.co.uk' ) ), $al_vr, function () { return false; } )['r119x48.ads.co.uk'] ), 'visible: a host above the 10 of its domain is listed' );

$al_vis = CMP_Alerts::visible( array( 'a.com' => array( '/x' ), 'b.com' => array( '/' ), 'c.com' => array( '/' ) ), array( 'ignored' => array( 'b.com' ), 'pending' => array( 'd.com' => array( '/p' ) ), 'known' => array() ), function ( $h ) { return 'c.com' === $h; } );
cmp_assert_eq( $al_vis, array( 'a.com' => array( '/x' ), 'd.com' => array( '/p' ) ), 'visible: found plus pending, minus ignored and covered' );
cmp_assert_eq( CMP_Alerts::visible( array( 'a.com' => array( '/' ) ), false, function () { return false; } ), array( 'a.com' => array( '/' ) ), 'visible: no state yet' );
cmp_assert_eq( CMP_Alerts::ignored_list( array( 'known' => array(), 'ignored' => array( 'b.com', 'a.com' ) ) ), array( 'b.com', 'a.com' ), 'ignored_list: the stored list' );
cmp_assert_eq( CMP_Alerts::ignored_list( false ), array(), 'ignored_list: no state' );
cmp_assert( CMP_Alerts::may_act( 'a.com', array( 'a.com' => array( '/' ) ) ) && ! CMP_Alerts::may_act( 'zzz.com', array( 'a.com' => array( '/' ) ) ) && ! CMP_Alerts::may_act( '', array( '' => array() ) ), 'may_act: only a host on the list' );

// ---- message() ----
$al_hosts = array( 'track.vendor.com' => array( '/', '/blog/' ), 'forms.other.net' => array( '/contact/' ) );
$al_url   = 'https://example.md/wp-admin/admin.php?page=cmp-services';
$al_msg   = CMP_Alerts::message( $al_hosts, 'Example Site', $al_url );
cmp_assert( isset( $al_msg['subject'], $al_msg['body'] ) && is_string( $al_msg['subject'] ) && is_string( $al_msg['body'] ), 'message: subject and body' );
cmp_assert( false !== strpos( $al_msg['subject'], 'Example Site' ), 'message: the subject names the site' );
cmp_assert( 1 !== preg_match( '/[\r\n]/', $al_msg['subject'] ), 'message: no line break in the subject' );
cmp_assert( false !== strpos( $al_msg['body'], 'track.vendor.com' ) && false !== strpos( $al_msg['body'], 'forms.other.net' ), 'message: the hosts are listed' );
cmp_assert( false !== strpos( $al_msg['body'], '/blog/' ) && false !== strpos( $al_msg['body'], '/contact/' ), 'message: the page paths are listed' );
cmp_assert( false !== strpos( $al_msg['body'], $al_url ), 'message: the link to Servicii' );
cmp_assert( 1 !== preg_match( '/<[a-z]/i', $al_msg['body'] ), 'message: plain text, no HTML' );
cmp_assert( false === strpos( $al_msg['subject'] . $al_msg['body'], "\xE2\x80\x94" ), 'message: no em dash' );
$al_evil = CMP_Alerts::message( array( "evil.com\r\nBcc: a@b.c" => array( "/x\r\nBcc: a@b.c?token=1#f" ), 'ok.com' => array( '/p?secret=1#frag' ) ), "Site\r\nBcc: attacker@evil.com\n", $al_url );
cmp_assert( 1 !== preg_match( '/[\r\n]/', $al_evil['subject'] ), 'message: a site name with line breaks cannot add headers' );
cmp_assert( false === strpos( $al_evil['body'], 'Bcc:' ) && false === strpos( $al_evil['subject'], 'Bcc:' ), 'message: no injected text through host or site name' );
cmp_assert( false === strpos( $al_evil['body'], 'secret' ) && false === strpos( $al_evil['body'], 'frag' ) && false === strpos( $al_evil['body'], 'token' ), 'message: paths carry no query or fragment' );
cmp_assert( false !== strpos( $al_evil['body'], 'ok.com' ) && false !== strpos( $al_evil['body'], '/p' ), 'message: valid entries still listed' );
$al_many_h = array();
for ( $i = 1; $i <= 80; $i++ ) {
	$al_many_h[ 'h' . $i . '.vendor.com' ] = array( '/' );
}
$al_long = CMP_Alerts::message( $al_many_h, str_repeat( 'S', 500 ), $al_url );
cmp_assert( strlen( $al_long['subject'] ) < 200, 'message: the site name is capped in the subject' );
cmp_assert( substr_count( $al_long['body'], '.vendor.com' ) <= 25, 'message: the number of hosts printed is capped' );
cmp_assert( false === strpos( $al_long['body'], 'h80.vendor' ), 'message: the capped hosts are not printed' );
cmp_assert( strlen( $al_long['body'] ) < 4000, 'message: the body stays small' );
$al_src = (string) file_get_contents( $al_root . 'includes/modules/class-alerts.php' );
cmp_assert( 1 === preg_match( "/__\(\s*'[^']+',\s*'biscuitwp'\s*\)/", $al_src ), 'message: strings go through gettext in the biscuitwp domain' );
cmp_assert( 0 === preg_match( "/__\(\s*'[^']*',\s*+(?!'biscuitwp')/", $al_src ), 'message: no gettext call with another domain' );

// ---- settings ----
cmp_assert_eq( CMP_Installer::default_settings()['alert_email_on'], true, 'installer: a fresh install has the alert on' );
cmp_assert_eq( CMP_Installer::default_settings()['alert_email'], '', 'installer: the address is empty (= admin email)' );
cmp_assert_eq( CMP_Alerts::enabled( array() ), false, 'enabled: a settings array without the key (updated install) is off' );
cmp_assert_eq( CMP_Alerts::enabled( array( 'alert_email_on' => true ) ), true, 'enabled: true' );
cmp_assert_eq( CMP_Alerts::enabled( array( 'alert_email_on' => false ) ), false, 'enabled: false' );
cmp_assert_eq( CMP_Alerts::enabled( array( 'alert_email_on' => '0' ) ), false, 'enabled: "0"' );
cmp_assert_eq( CMP_Alerts::recipient( array( 'alert_email' => 'me@example.com' ), 'admin@site.md' ), 'me@example.com', 'recipient: the configured address' );
cmp_assert_eq( CMP_Alerts::recipient( array( 'alert_email' => '' ), 'admin@site.md' ), 'admin@site.md', 'recipient: empty -> the admin email' );
cmp_assert_eq( CMP_Alerts::recipient( array(), 'admin@site.md' ), 'admin@site.md', 'recipient: absent -> the admin email' );
cmp_assert_eq( CMP_Alerts::recipient( array( 'alert_email' => "x@y.com\nBcc: a@b.c" ), 'admin@site.md' ), '', 'recipient: an invalid stored address is nobody, never the admin' );
cmp_assert_eq( CMP_Alerts::recipient( array( 'alert_email' => '  ' ), 'admin@site.md' ), 'admin@site.md', 'recipient: blanks are an empty field' );
cmp_assert_eq( CMP_Alerts::recipient( array( 'alert_email' => array( 'x' ) ), 'admin@site.md' ), '', 'recipient: a stored non-string is nobody' );
cmp_assert_eq( CMP_Alerts::recipient( array(), 'not-an-email' ), '', 'recipient: nothing valid at all -> empty (no mail)' );
$san = function ( array $in, array $old = array() ) {
	return CMP_Sanitizer::settings( $in, $old );
};
cmp_assert_eq( $san( array( 'alert_email_on' => '1' ) )['alert_email_on'], true, 'sanitizer: ticked -> on' );
cmp_assert_eq( $san( array( 'alert_email_on' => '0' ), array( 'alert_email_on' => true ) )['alert_email_on'], false, 'sanitizer: the hidden 0 (unticked) -> off' );
cmp_assert_eq( $san( array( 'alert_email_on' => '1' ), array( 'alert_email_on' => false ) )['alert_email_on'], true, 'sanitizer: posted wins over stored' );
cmp_assert_eq( $san( array() )['alert_email_on'], false, 'sanitizer: not posted, nothing stored -> off (an updated install stays off)' );
cmp_assert_eq( $san( array(), array( 'alert_email_on' => true ) )['alert_email_on'], true, 'sanitizer: not posted -> the stored value is kept (on)' );
cmp_assert_eq( $san( array(), array( 'alert_email_on' => false ) )['alert_email_on'], false, 'sanitizer: not posted -> the stored value is kept (off)' );
cmp_assert_eq( $san( array( 'alert_email_on' => array( 'x' ) ), array( 'alert_email_on' => true ) )['alert_email_on'], true, 'sanitizer: a non-scalar post keeps the stored value' );
// I3: a typo never turns the alert on towards the admin.
cmp_assert_eq( $san( array( 'alert_email_on' => '1', 'alert_email' => 'me@' ), array( 'alert_email_on' => false ) )['alert_email_on'], false, 'sanitizer: ticked + a mistyped first address -> stays off' );
cmp_assert_eq( $san( array( 'alert_email_on' => '1', 'alert_email' => 'me@' ) )['alert_email_on'], false, 'sanitizer: ... also on an updated install with no key' );
cmp_assert_eq( $san( array( 'alert_email_on' => '1', 'alert_email' => 'me@' ), array( 'alert_email_on' => false, 'alert_email' => 'bad' ) )['alert_email_on'], false, 'sanitizer: ... also over a stored address that is not valid' );
cmp_assert_eq( $san( array( 'alert_email_on' => '1', 'alert_email' => 'me@' ), array( 'alert_email_on' => true ) )['alert_email_on'], true, 'sanitizer: ... already on: stays on' );
cmp_assert_eq( $san( array( 'alert_email_on' => '0', 'alert_email' => 'me@' ), array( 'alert_email_on' => true ) )['alert_email_on'], false, 'sanitizer: unticking is always honoured' );
cmp_assert_eq( $san( array( 'alert_email_on' => '1', 'alert_email' => 'me@' ), array( 'alert_email_on' => false, 'alert_email' => 'keep@me.md' ) )['alert_email_on'], true, 'sanitizer: with a stored address the tick is saved (mails go to that one)' );
cmp_assert_eq( $san( array( 'alert_email_on' => '1', 'alert_email' => '' ), array( 'alert_email_on' => false ) )['alert_email_on'], true, 'sanitizer: an empty field is the admin address, on purpose' );
cmp_assert_eq( $san( array( 'alert_email' => ' Me@Example.com ' ) )['alert_email'], 'Me@Example.com', 'sanitizer: a valid address is trimmed and kept' );
cmp_assert_eq( $san( array( 'alert_email' => 'nope' ) )['alert_email'], '', 'sanitizer: an invalid address becomes empty' );
cmp_assert_eq( $san( array( 'alert_email' => "a@b.com\nBcc: x@y.z" ) )['alert_email'], '', 'sanitizer: a header-injection attempt becomes empty' );
cmp_assert_eq( $san( array( 'alert_email' => array( 'a@b.com' ) ) )['alert_email'], '', 'sanitizer: an array becomes empty' );
cmp_assert_eq( $san( array(), array( 'alert_email' => 'keep@me.md' ) )['alert_email'], 'keep@me.md', 'sanitizer: not posted -> the stored address is kept' );
cmp_assert_eq( $san( array( 'alert_email' => '' ), array( 'alert_email' => 'keep@me.md' ) )['alert_email'], '', 'sanitizer: posted empty clears it' );
cmp_assert_eq( $san( array( 'alert_email' => 'nope' ), array( 'alert_email' => 'keep@me.md' ) )['alert_email'], 'keep@me.md', 'sanitizer: an invalid address keeps the stored one (never falls back to the admin address)' );
cmp_assert_eq( $san( array( 'alert_email' => "a@b.com\nBcc: x@y.z" ), array( 'alert_email' => 'keep@me.md' ) )['alert_email'], 'keep@me.md', 'sanitizer: a header-injection attempt keeps the stored address' );
cmp_assert_eq( $san( array( 'alert_email' => array( 'a@b.com' ) ), array( 'alert_email' => 'keep@me.md' ) )['alert_email'], 'keep@me.md', 'sanitizer: an array keeps the stored address' );
cmp_assert_eq( $san( array( 'alert_email' => 'nope' ), array( 'alert_email' => 'also bad' ) )['alert_email'], '', 'sanitizer: invalid over an invalid stored one gives empty' );
cmp_assert_eq( CMP_Sanitizer::alert_email_error( array( 'alert_email' => 'nope' ) ), true, 'alert_email_error: an invalid address is an error' );
cmp_assert_eq( CMP_Sanitizer::alert_email_error( array( 'alert_email' => '   ' ) ), false, 'alert_email_error: an empty field is not an error (= admin address)' );
cmp_assert_eq( CMP_Sanitizer::alert_email_error( array( 'alert_email' => '' ) ), false, 'alert_email_error: empty is fine' );
cmp_assert_eq( CMP_Sanitizer::alert_email_error( array( 'alert_email' => 'me@example.com' ) ), false, 'alert_email_error: a valid address is fine' );
cmp_assert_eq( CMP_Sanitizer::alert_email_error( array() ), false, 'alert_email_error: not posted is fine' );
cmp_assert_eq( CMP_Sanitizer::alert_email_error( array( 'alert_email' => array( 'x' ) ) ), true, 'alert_email_error: a non-string is an error' );
// Saving another screen never loses or flips the keys.
$al_old = array( 'alert_email_on' => true, 'alert_email' => 'keep@me.md', 'appearance' => array( 'palette' => 'neutral' ), 'position' => 'bottom', 'preset' => 'neutral', 'reopen_button' => false, 'texts' => array() );
$al_asp = CMP_Sanitizer::aspect( array( 'appearance' => array( 'palette' => 'warm' ) ), $al_old, false );
cmp_assert( true === $al_asp['alert_email_on'] && 'keep@me.md' === $al_asp['alert_email'], 'aspect save keeps the alert settings' );
$al_keep = CMP_Sanitizer::keep_appearance( CMP_Sanitizer::keep_legal( $san( array(), $al_old ), $al_old ), $al_old );
cmp_assert( true === $al_keep['alert_email_on'] && 'keep@me.md' === $al_keep['alert_email'], 'a save that posts neither field keeps the alert settings' );
$al_page = (string) file_get_contents( $al_root . 'includes/admin/class-settings-page.php' );
cmp_assert( 1 === preg_match( '/<input type="hidden" name="cmp\[alert_email_on\]" value="0">\s*<label><input type="checkbox" name="cmp\[alert_email_on\]" value="1"/', $al_page ), 'settings page: hidden 0 before the checkbox (unticking is saved)' );
cmp_assert( 1 === preg_match( '/checked\(\s*CMP_Alerts::enabled\(\s*\$s\s*\)\s*\)/', $al_page ), 'settings page: ticked only when the stored setting is on (absent = off)' );
cmp_assert( false !== strpos( $al_page, 'name="cmp[alert_email]"' ) && 1 === preg_match( '/name="cmp\[alert_email\]"[^>]*type="email"|type="email"[^>]*name="cmp\[alert_email\]"/', $al_page ), 'settings page: the address field' );
cmp_assert( 1 === preg_match( '/name="cmp\[alert_email\]"[^>]*value="<\?php echo esc_attr\(/', $al_page ), 'settings page: the stored address is escaped' );
cmp_assert( 1 === preg_match( '/alert_email_error\(\s*\$raw\s*\)[^;]*;?[\s\S]*?cmp_alert_email/', $al_page ), 'settings page: save() flags an invalid address in the redirect' );
cmp_assert( 1 === preg_match( "/\\\$_GET\['cmp_alert_email'\][\s\S]*?notice-error/", $al_page ), 'settings page: the flag shows an error notice' );

// ---- ignore / unignore run under the same lock as the scan step ----
$al_chg_io = function ( &$store, &$log, array $over = array() ) {
	return array_merge( array(
		'lock'    => function () use ( &$log ) { $log[] = 'lock'; return true; },
		'unlock'  => function () use ( &$log ) { $log[] = 'unlock'; },
		'read'    => function () use ( &$store, &$log ) { $log[] = 'read'; return $store; },
		'write'   => function ( $v ) use ( &$store, &$log ) { $log[] = 'write'; $store = $v; },
	), $over );
};
$al_cs = CMP_Alerts::step( false, $al_f( array( 'a.com' ) ), $al_now, true )['state'];
$store = $al_cs;
$log   = array();
cmp_assert_eq( CMP_Alerts::change( 'ignore', 'a.com', $al_chg_io( $store, $log ) ), 'done', 'change: ignore under the lock -> done' );
cmp_assert_eq( $log, array( 'lock', 'read', 'write', 'unlock' ), 'change: lock, read INSIDE the lock, write, unlock' );
cmp_assert_eq( $store['ignored'], array( 'a.com' ), 'change: the host is ignored' );
$log = array();
cmp_assert_eq( CMP_Alerts::change( 'unignore', 'a.com', $al_chg_io( $store, $log ) ), 'done', 'change: unignore -> done' );
cmp_assert_eq( $store['ignored'], array(), 'change: the host is back on the list' );
// A busy lock: one try, a clear "busy", nothing written, no unlock (it is not ours).
$store = $al_cs;
$log   = array();
$al_io_b = $al_chg_io( $store, $log, array( 'lock' => function () use ( &$log ) { $log[] = 'lock?'; return false; } ) );
cmp_assert_eq( CMP_Alerts::change( 'ignore', 'a.com', $al_io_b ), 'busy', 'change: a busy lock -> busy (the screen asks to try again)' );
cmp_assert( $store === $al_cs && ! in_array( 'write', $log, true ) && ! in_array( 'unlock', $log, true ), 'change: busy writes nothing and does not unlock' );
cmp_assert_eq( $log, array( 'lock?' ), 'change: one try, no waiting in the admin request' );
cmp_assert( 0 === preg_match( '/\b(?:u?sleep)\s*\(/', $al_src ), 'alerts: no sleep anywhere' );
// A failing write still releases the lock.
$store = $al_cs;
$log   = array();
$al_io_e = $al_chg_io( $store, $log, array( 'write' => function () { throw new RuntimeException( 'db' ); } ) );
try {
	CMP_Alerts::change( 'ignore', 'a.com', $al_io_e );
} catch ( RuntimeException $e ) {
	$log[] = 'threw';
}
cmp_assert( in_array( 'unlock', $log, true ), 'change: the lock is released when the write throws' );
$log = array();
cmp_assert_eq( CMP_Alerts::change( 'bogus', 'a.com', $al_chg_io( $store, $log ) ), 'invalid', 'change: an unknown operation does nothing, not even the lock' );
cmp_assert_eq( $log, array(), 'change: ... no call at all' );
$al_sv0 = (string) file_get_contents( $al_root . 'includes/admin/class-services-page.php' );
cmp_assert( 1 === preg_match( '/function ignore_domain\(\).*?CMP_Alerts::change_wp\(\s*\'ignore\'/s', $al_sv0 ) && 1 === preg_match( '/function unignore_domain\(\).*?CMP_Alerts::change_wp\(\s*\'unignore\'/s', $al_sv0 ), 'services page: both actions go through the lock (change_wp)' );
cmp_assert( 0 === preg_match( '/function (ignore|unignore)_domain\(\)[^}]*?update_option\(\s*CMP_Alerts::OPTION/s', $al_sv0 ), 'services page: no plain update_option of the state in the two actions' );
cmp_assert( 1 === preg_match( "/'busy'\s*===\s*\\\$_GET\['cmp_domain'\][\s\S]*?notice-error/", $al_sv0 ), 'services page: a busy lock shows a "try again" notice' );

// ---- wiring ----
$al_sc = (string) file_get_contents( $al_root . 'includes/modules/class-scanner.php' );
cmp_assert( 1 === preg_match( "/do_action\(\s*'cmp_scan_finished',\s*\\\$fire\s*\)/", $al_sc ), 'scanner: finish() fires cmp_scan_finished with the aggregate' );
cmp_assert( 1 === preg_match( '/store_scan\(\s*\$agg\s*\);.*?do_action\(\s*\'cmp_scan_finished\'/s', $al_sc ), 'scanner: the action comes after store_scan()' );
cmp_assert( 1 === preg_match( '/if \(\s*\$agg\[\'pages\'\]\s*>\s*0\s*\)\s*\{[^}]*store_scan[^}]*\}\s*else\s*\{/s', $al_sc ) && 1 === preg_match( '/\$fire\s*=\s*\$agg;/', $al_sc ), 'scanner: only an aggregate with pages is passed on' );
$al_plugin = (string) file_get_contents( $al_root . 'includes/class-plugin.php' );
cmp_assert( false !== strpos( $al_plugin, "includes/modules/class-alerts.php" ) && false !== strpos( $al_plugin, 'CMP_Alerts::register()' ), 'plugin: the alerts module is loaded and registered' );
cmp_assert( 1 === preg_match( "/const HOOK\s*=\s*'cmp_scan_finished'/", $al_src ) && 1 === preg_match( '/add_action\(\s*self::HOOK,/', $al_src ), 'alerts: hooks cmp_scan_finished' );
cmp_assert( 1 === preg_match( '/wp_mail\(/', $al_src ) && 0 === preg_match( '/wp_remote_|curl_|file_get_contents\(\s*[\'"]http/', $al_src ), 'alerts: wp_mail is the only way out (no HTTP call)' );
cmp_assert( false !== strpos( $al_src, 'switch_to_locale' ) && false !== strpos( $al_src, 'restore_previous_locale' ), 'alerts: the mail is written in the site language (locale switched and restored)' );
cmp_assert( false !== strpos( $al_src, "'cmp_unknown_hosts'" ) && 1 === preg_match( '/update_option\(\s*self::OPTION,\s*\$[a-z_]+,\s*false\s*\)/', $al_src ), 'alerts: the option is not autoloaded' );
$al_sv = (string) file_get_contents( $al_root . 'includes/admin/class-services-page.php' );
$al_ad = (string) file_get_contents( $al_root . 'includes/admin/class-admin.php' );
cmp_assert( false !== strpos( $al_ad, "admin_post_cmp_ignore_domain" ) && false !== strpos( $al_ad, "admin_post_cmp_unignore_domain" ), 'admin: the ignore actions are registered' );
cmp_assert( false !== strpos( $al_sv, 'Domenii necunoscute' ) && false !== strpos( $al_sv, 'Adaugă ca serviciu' ) && false !== strpos( $al_sv, 'Ignoră' ) && false !== strpos( $al_sv, 'Nu mai ignora' ), 'services page: the section and its actions' );
cmp_assert( 1 === preg_match( "/function ignore_domain\(\)\s*\{\s*if \( ! current_user_can\( 'manage_options' \) \|\| ! check_admin_referer\( 'cmp_ignore_domain' \)/", $al_sv ), 'services page: ignore needs manage_options and a nonce' );
cmp_assert( 1 === preg_match( "/function unignore_domain\(\)\s*\{\s*if \( ! current_user_can\( 'manage_options' \) \|\| ! check_admin_referer\( 'cmp_unignore_domain' \)/", $al_sv ), 'services page: unignore needs manage_options and a nonce' );
cmp_assert( false !== strpos( $al_sv, 'CMP_Alerts::may_act(' ), 'services page: the host is validated against the list' );
cmp_assert( false !== strpos( $al_sv, 'data-cmp-use-host' ), 'services page: "Adaugă ca serviciu" fills the custom form (client side)' );
$al_js = (string) file_get_contents( $al_root . 'assets/js/admin.js' );
cmp_assert( false !== strpos( $al_js, 'data-cmp-use-host' ), 'admin.js: handles data-cmp-use-host' );
cmp_assert( false !== strpos( (string) file_get_contents( $al_root . 'includes/class-uninstaller.php' ), "'cmp_unknown_hosts'" ) && false !== strpos( (string) file_get_contents( $al_root . 'includes/class-uninstaller.php' ), "'cmp_unknown_hosts_lock'" ), 'uninstaller: the state and the lock are removed' );
