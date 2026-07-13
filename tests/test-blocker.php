<?php
require_once __DIR__ . '/../includes/modules/class-blocker.php';

// External GA script is neutralized.
$ext = '<script src="https://www.googletagmanager.com/gtag/js?id=G-X" async></script>';
$out = CMP_Blocker::rewrite( $ext );
cmp_assert( strpos( $out, 'type="text/plain"' ) !== false, 'external GA becomes text/plain' );
cmp_assert( strpos( $out, 'data-cmp-src=' ) !== false, 'src moved to data-cmp-src' );
cmp_assert( strpos( $out, 'data-cmp-block="statistics"' ) !== false, 'data-cmp-block added' );
cmp_assert( ! preg_match( '/\ssrc\s*=/', $out ), 'no executable src remains' );

// Inline gtag config is neutralized, body preserved.
$inl = "<script>gtag('config','G-X');</script>";
$out2 = CMP_Blocker::rewrite( $inl );
cmp_assert( strpos( $out2, 'type="text/plain"' ) !== false, 'inline config becomes text/plain' );
cmp_assert( strpos( $out2, "gtag('config','G-X')" ) !== false, 'inline body preserved' );

// GTM inline container init is neutralized.
$gtm = "<script>(function(w,d,s,l,i){})(window,'dataLayer','GTM-ABCD');</script>";
cmp_assert( strpos( CMP_Blocker::rewrite( $gtm ), 'type="text/plain"' ) !== false, 'GTM inline blocked' );

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
