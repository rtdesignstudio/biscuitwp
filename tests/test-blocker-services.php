<?php
require_once __DIR__ . '/../includes/modules/class-blocker.php';

// The blocker reads its rules from the services library, and tags what it rewrites
// with data-cmp-service so the banner and audit can tell services apart.

$cmp_ga = '<script src="https://www.google-analytics.com/analytics.js" async></script>';
$cmp_o  = CMP_Blocker::rewrite( $cmp_ga );
cmp_assert( false !== strpos( $cmp_o, 'data-cmp-service="google-analytics"' ), 'blocked GA script carries data-cmp-service' );
cmp_assert( false !== strpos( $cmp_o, 'data-cmp-block="statistics"' ), 'blocked GA script keeps its category' );

$cmp_gtm = CMP_Blocker::rewrite( "<script>(function(w){w.dataLayer=w.dataLayer||[];})(window);var id='GTM-ABC123';</script>" );
cmp_assert( false !== strpos( $cmp_gtm, 'data-cmp-service="google-tag-manager"' ), 'GTM container is tagged google-tag-manager' );

$cmp_px = CMP_Blocker::rewrite( "<script>fbq('init','1');</script>" );
cmp_assert( false !== strpos( $cmp_px, 'data-cmp-service="meta-pixel"' ), 'Meta Pixel is tagged meta-pixel' );

$cmp_yt = CMP_Blocker::rewrite( '<iframe src="https://www.youtube.com/embed/abc"></iframe>' );
cmp_assert( false !== strpos( $cmp_yt, 'class="cmp-embed' ) && false !== strpos( $cmp_yt, 'data-cmp-service="youtube"' ), 'YouTube embed placeholder carries data-cmp-service' );

// An empty services list rewrites nothing.
cmp_assert_eq( CMP_Blocker::rewrite( $cmp_ga, array(), array() ), $cmp_ga, 'empty services list leaves GA untouched' );
$cmp_ifr = '<iframe src="https://www.youtube.com/embed/abc"></iframe>';
cmp_assert_eq( CMP_Blocker::rewrite( $cmp_ifr, array(), array() ), $cmp_ifr, 'empty services list leaves embeds untouched' );

// Necessary and exempt services are never rewritten.
$cmp_lib = CMP_Services::library();
$cmp_ga_s = $cmp_lib[0];
$cmp_nec = array_merge( $cmp_ga_s, array( 'category' => 'necessary' ) );
cmp_assert_eq( CMP_Blocker::rewrite( $cmp_ga, array(), array( $cmp_nec ) ), $cmp_ga, 'necessary service is not rewritten' );
$cmp_ex = array_merge( $cmp_ga_s, array( 'exempt' => true ) );
cmp_assert_eq( CMP_Blocker::rewrite( $cmp_ga, array(), array( $cmp_ex ) ), $cmp_ga, 'exempt service is not rewritten' );
cmp_assert( false !== strpos( CMP_Blocker::rewrite( $cmp_ga, array(), array( $cmp_ga_s ) ), 'data-cmp-service="google-analytics"' ), 'the same service without the flags is rewritten' );

// Only listed services are touched.
cmp_assert_eq( CMP_Blocker::rewrite( $cmp_ga, array(), array( $cmp_lib[2] ) ), $cmp_ga, 'GA left alone when only Meta Pixel is listed' );

// tracker_hosts: hosts of script services only, without paths, unique.
$cmp_hosts = CMP_Blocker::tracker_hosts( array_slice( $cmp_lib, 0, 6 ) );
cmp_assert_eq( $cmp_hosts, array( 'google-analytics.com', 'googletagmanager.com', 'connect.facebook.net' ), 'tracker_hosts lists script hosts, no paths, no embeds' );
$cmp_hint = '<link rel="preconnect" href="https://www.google.com"><link rel="dns-prefetch" href="https://www.gstatic.com">';
cmp_assert_eq( CMP_Blocker::rewrite( $cmp_hint ), $cmp_hint, 'reCAPTCHA resource hints survive rewrite() on the full library' );
cmp_assert_eq( CMP_Blocker::tracker_hosts( array() ), array(), 'tracker_hosts of nothing is empty' );

// noscript stripping follows the services list too.
$cmp_ns = '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-X"></iframe></noscript>';
cmp_assert_eq( CMP_Blocker::rewrite( $cmp_ns, array(), array() ), $cmp_ns, 'noscript kept when no service covers it' );
cmp_assert_eq( CMP_Blocker::rewrite( $cmp_ns ), '', 'noscript stripped with the library' );

// detect_services: live tags are raw, neutralized ones are blocked, by service id.
$cmp_page = '<script src="https://www.google-analytics.com/analytics.js"></script>'
	. CMP_Blocker::rewrite( "<script>fbq('init','1');</script>" );
$cmp_d = CMP_Blocker::detect_services( $cmp_page, $cmp_lib );
cmp_assert_eq( $cmp_d['raw'], array( 'google-analytics' ), 'detect_services: live GA is raw' );
cmp_assert_eq( $cmp_d['blocked'], array( 'meta-pixel' ), 'detect_services: rewritten Pixel is blocked' );

// Detection looks page-wide, so lazy-loaded trackers an optimizer rewrote still count as raw.
$cmp_lz = CMP_Blocker::detect_services( '<script type="litespeed/javascript" data-litespeed-src="https://www.googletagmanager.com/gtm.js?id=GTM-X"></script>', $cmp_lib );
cmp_assert_eq( $cmp_lz['raw'], array( 'google-tag-manager' ), 'detect_services: data-litespeed-src GTM is raw' );
$cmp_lz2 = CMP_Blocker::detect_trackers( '<script data-src="https://connect.facebook.net/en_US/fbevents.js"></script>' );
cmp_assert_eq( $cmp_lz2['raw'], array( 'marketing' ), 'detect_trackers: data-src Pixel is raw' );
cmp_assert_eq( CMP_Blocker::detect_services( '<script data-src="https://connect.facebook.net/en_US/fbevents.js"></script>', $cmp_lib )['raw'], array( 'meta-pixel' ), 'detect_services: data-src Pixel is raw' );
$cmp_nsd = CMP_Blocker::detect_trackers( $cmp_ns );
cmp_assert_eq( $cmp_nsd['raw'], array( 'statistics' ), 'detect_trackers: noscript-only GTM iframe is raw' );
cmp_assert_eq( CMP_Blocker::detect_services( $cmp_ns, $cmp_lib )['raw'], array( 'google-tag-manager' ), 'detect_services: noscript-only GTM is raw' );
cmp_assert_eq( CMP_Blocker::detect_services( CMP_Blocker::rewrite( $cmp_ga ), $cmp_lib )['raw'], array(), 'detect_services: already-rewritten GA is not raw' );
cmp_assert_eq( CMP_Blocker::detect_trackers( CMP_Blocker::rewrite( $cmp_ga ) )['raw'], array(), 'detect_trackers: already-rewritten GA is not raw' );
cmp_assert_eq( CMP_Blocker::detect_services( '<script src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>', $cmp_lib )['raw'], array( 'google-analytics' ), 'detect_services: gtag/js is GA, not also GTM' );

// A stray space around the src URL does not hide a tracker (browsers trim it).
$cmp_sp = CMP_Blocker::rewrite( '<script src=" https://www.googletagmanager.com/gtm.js?id=GTM-X "></script>' );
cmp_assert( false !== strpos( $cmp_sp, 'data-cmp-service="google-tag-manager"' ), 'src with surrounding spaces is still blocked' );

// ---- B2 Task 2: Advanced leaves GA/GTM alone, still blocks Meta and YouTube ----
$cmp_adv = CMP_Services::apply_measurement( CMP_Services::library(), true );
$cmp_ga_tag = '<script src="https://www.googletagmanager.com/gtag/js?id=G-1" async></script>';
cmp_assert_eq( CMP_Blocker::rewrite( $cmp_ga_tag, array(), $cmp_adv ), $cmp_ga_tag, 'Advanced: GA script stays unchanged' );
cmp_assert_eq( CMP_Blocker::rewrite( $cmp_ga, array(), $cmp_adv ), $cmp_ga, 'Advanced: analytics.js stays unchanged' );
$cmp_gtm_tag = "<script>(function(w){w.dataLayer=w.dataLayer||[];})(window);var id='GTM-ABC123';</script>";
cmp_assert_eq( CMP_Blocker::rewrite( $cmp_gtm_tag, array(), $cmp_adv ), $cmp_gtm_tag, 'Advanced: GTM container stays unchanged' );
cmp_assert( false !== strpos( CMP_Blocker::rewrite( "<script>fbq('init','1');</script>", array(), $cmp_adv ), 'data-cmp-service="meta-pixel"' ), 'Advanced: Meta Pixel is still rewritten' );
cmp_assert( false !== strpos( CMP_Blocker::rewrite( '<iframe src="https://www.youtube.com/embed/abc"></iframe>', array(), $cmp_adv ), 'class="cmp-embed' ), 'Advanced: YouTube is still rewritten' );
cmp_assert( false !== strpos( CMP_Blocker::rewrite( $cmp_ga_tag, array(), CMP_Services::apply_measurement( CMP_Services::library(), false ) ), 'data-cmp-service="google-analytics"' ), 'Strict: GA is still rewritten' );
