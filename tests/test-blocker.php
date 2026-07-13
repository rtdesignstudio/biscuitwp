<?php
require_once __DIR__ . '/../includes/modules/class-blocker.php';

// External GA script is neutralized.
$ext = '<script src="https://www.googletagmanager.com/gtag/js?id=G-X" async></script>';
$out = CMP_Blocker::rewrite( $ext );
cmp_assert( strpos( $out, 'type="text/plain"' ) !== false, 'external GA becomes text/plain' );
cmp_assert( strpos( $out, 'data-cmp-src=' ) !== false, 'src moved to data-cmp-src' );
cmp_assert( strpos( $out, 'data-cmp-block="statistics"' ) !== false, 'data-cmp-block added' );
cmp_assert( ! preg_match( '/\ssrc\s*=/', $out ), 'no executable src remains' );
cmp_assert( strpos( $out, 'data-cmp-enc' ) !== false, 'data-cmp-enc marker present' );
cmp_assert( strpos( $out, 'googletagmanager.com' ) === false, 'raw tracker domain hidden from source' );
cmp_assert( preg_match( '/data-cmp-src="([^"]+)"/', $out, $em ) && base64_decode( $em[1] ) === 'https://www.googletagmanager.com/gtag/js?id=G-X', 'data-cmp-src base64-decodes to the URL' );

// Inline gtag config is neutralized, body base64-hidden (not in cleartext).
$inl = "<script>gtag('config','G-X');</script>";
$out2 = CMP_Blocker::rewrite( $inl );
cmp_assert( strpos( $out2, 'type="text/plain"' ) !== false, 'inline config becomes text/plain' );
cmp_assert( strpos( $out2, "gtag('config'" ) === false, 'inline body not left in cleartext' );
cmp_assert( preg_match( '/data-cmp-body="([^"]+)"/', $out2, $bm ) && base64_decode( $bm[1] ) === "gtag('config','G-X');", 'inline body base64-decodes to the original' );

// GTM inline container init is neutralized and its id hidden.
$gtm = "<script>(function(w,d,s,l,i){})(window,'dataLayer','GTM-ABCD');</script>";
$outg = CMP_Blocker::rewrite( $gtm );
cmp_assert( strpos( $outg, 'type="text/plain"' ) !== false, 'GTM inline blocked' );
cmp_assert( strpos( $outg, 'GTM-ABCD' ) === false, 'GTM id hidden from source' );

// Our own Consent Mode script (data-cmp-keep) is untouched.
$keep = '<script data-cmp-keep>gtag("consent","default",{})</script>';
cmp_assert_eq( CMP_Blocker::rewrite( $keep ), $keep, 'data-cmp-keep left untouched' );

// A gtag consent (not config) inline is NOT blocked even without the mark.
$consent = '<script>gtag("consent","update",{})</script>';
cmp_assert_eq( CMP_Blocker::rewrite( $consent ), $consent, 'gtag consent not blocked' );

// A neutral script is untouched.
$neutral = '<script>console.log(1)</script>';
cmp_assert_eq( CMP_Blocker::rewrite( $neutral ), $neutral, 'neutral script untouched' );

// A non-Google external script is untouched.
$other = '<script src="https://example.com/app.js"></script>';
cmp_assert_eq( CMP_Blocker::rewrite( $other ), $other, 'non-Google external untouched' );

// data-src decoy before the real GTM src must NOT hide it (still blocked).
$decoy = '<script data-src="foo" src="https://www.googletagmanager.com/gtag/js"></script>';
$outd = CMP_Blocker::rewrite( $decoy );
cmp_assert( strpos( $outd, 'type="text/plain"' ) !== false, 'decoy data-src does not hide real GTM src' );
cmp_assert( strpos( $outd, 'data-data-cmp-src' ) === false, 'no malformed double attribute' );
cmp_assert( strpos( $outd, 'data-src="foo"' ) !== false, 'unrelated data-src preserved' );

// A benign inline script containing "GTM-" but not dataLayer is NOT blocked.
$promo = '<script>var code = "GTM-2026 promo";</script>';
cmp_assert_eq( CMP_Blocker::rewrite( $promo ), $promo, 'GTM- substring without dataLayer not blocked' );

// strip_hints removes GA/GTM resource hints (string + array forms) but keeps others.
$hints = array(
	'//www.googletagmanager.com',
	'https://fonts.gstatic.com',
	array( 'href' => 'https://www.google-analytics.com', 'crossorigin' ),
);
$kept = CMP_Blocker::strip_hints( $hints, CMP_Blocker::HOSTS );
cmp_assert_eq( count( $kept ), 1, 'only the non-Google hint remains' );
cmp_assert_eq( $kept[0], 'https://fonts.gstatic.com', 'non-Google hint preserved' );

// rewrite() removes a directly-emitted GA dns-prefetch <link>, keeps other links.
$links = "<link rel='dns-prefetch' href='//www.googletagmanager.com' />\n<link rel='dns-prefetch' href='//fonts.googleapis.com' />\n<link rel='stylesheet' href='//www.googletagmanager.com/x.css' />";
$outl = CMP_Blocker::rewrite( $links );
cmp_assert( strpos( $outl, 'googletagmanager.com' ) === false || strpos( $outl, 'x.css' ) !== false, 'GA dns-prefetch hint removed' );
cmp_assert( strpos( $outl, "href='//www.googletagmanager.com' /" ) === false, 'the GA dns-prefetch link is gone' );
cmp_assert( strpos( $outl, 'fonts.googleapis.com' ) !== false, 'non-Google dns-prefetch kept' );
cmp_assert( strpos( $outl, 'x.css' ) !== false, 'non-hint link (stylesheet) kept even if Google host' );
