<?php
require_once __DIR__ . '/../includes/modules/class-blocker.php';

// data-cmp-category: any script/iframe can be blocked as a given category.
$o = CMP_Blocker::rewrite( '<script data-cmp-category="statistics" src="https://x.md/a.js" async></script>' );
cmp_assert( false !== strpos( $o, 'data-cmp-block="statistics"' ) && false !== strpos( $o, 'type="text/plain"' ), 'category script is blocked as statistics' );
cmp_assert( false !== strpos( $o, 'data-cmp-src="' . base64_encode( 'https://x.md/a.js' ) . '"' ) && false === strpos( $o, 'x.md/a.js"' ), 'category script src is base64' );
cmp_assert( false === strpos( $o, 'data-cmp-service' ), 'anonymous category script has no data-cmp-service' );
cmp_assert( false !== strpos( $o, ' async' ), 'category script keeps other attributes' );

$o = CMP_Blocker::rewrite( '<script data-cmp-category="marketing">var a=1;</script>' );
cmp_assert( false !== strpos( $o, 'data-cmp-body="' . base64_encode( 'var a=1;' ) . '"' ), 'category inline script body is base64' );

$o = CMP_Blocker::rewrite( '<iframe data-cmp-category="marketing" src="https://x.md/w"></iframe>' );
cmp_assert( false !== strpos( $o, 'data-cmp-embed="marketing"' ), 'category iframe becomes a marketing placeholder' );
cmp_assert( false !== strpos( $o, 'x.md' ) && false === strpos( $o, 'data-cmp-service' ), 'placeholder names the host, no service id' );
cmp_assert( false !== strpos( $o, '<template data-cmp-embed-src><iframe' ), 'original iframe kept inside the template' );

// Empty / necessary / keep: unchanged.
foreach ( array( '', 'necessary' ) as $bad ) {
	$h = '<script data-cmp-category="' . $bad . '" src="https://x.md/a.js"></script>';
	cmp_assert_eq( CMP_Blocker::rewrite( $h ), $h, 'script category "' . $bad . '" leaves tag unchanged' );
	$h = '<iframe data-cmp-category="' . $bad . '" src="https://x.md/w"></iframe>';
	cmp_assert_eq( CMP_Blocker::rewrite( $h ), $h, 'iframe category "' . $bad . '" leaves tag unchanged' );
}
// Final fix wave I6: an invalid value fails closed (blocked as marketing, or as
// the slug before a stray quote), it no longer leaves a non-library tag running.
foreach ( array( 'Bad Cat' => 'marketing', 'a"b' => 'a', str_repeat( 'a', 41 ) => 'marketing' ) as $bad => $as ) {
	$h = '<script data-cmp-category="' . $bad . '" src="https://x.md/a.js"></script>';
	cmp_assert( false !== strpos( CMP_Blocker::rewrite( $h ), 'data-cmp-block="' . $as . '"' ), 'script category "' . $bad . '" is blocked as ' . $as );
	$h = '<iframe data-cmp-category="' . $bad . '" src="https://x.md/w"></iframe>';
	cmp_assert( false !== strpos( CMP_Blocker::rewrite( $h ), 'data-cmp-embed="' . $as . '"' ), 'iframe category "' . $bad . '" is blocked as ' . $as );
}
$h = '<script data-cmp-keep data-cmp-category="marketing" src="https://x.md/a.js"></script>';
cmp_assert_eq( CMP_Blocker::rewrite( $h ), $h, 'data-cmp-keep wins on script' );
$h = '<iframe data-cmp-keep data-cmp-category="marketing" src="https://x.md/w"></iframe>';
cmp_assert_eq( CMP_Blocker::rewrite( $h ), $h, 'data-cmp-keep wins on iframe' );

// Unknown but valid slug is blocked (fail-safe); category beats the library.
$o = CMP_Blocker::rewrite( '<script data-cmp-category="my-custom_cat" src="https://x.md/a.js"></script>' );
cmp_assert( false !== strpos( $o, 'data-cmp-block="my-custom_cat"' ), 'unknown valid slug is blocked' );
$o = CMP_Blocker::rewrite( '<script data-cmp-category="functional" src="https://www.google-analytics.com/analytics.js"></script>' );
cmp_assert( false !== strpos( $o, 'data-cmp-block="functional"' ) && false === strpos( $o, 'data-cmp-service' ), 'attribute is checked before the library' );

// Idempotence.
$page = '<script src="https://www.google-analytics.com/analytics.js"></script>'
	. '<iframe src="https://www.youtube.com/embed/abc" width="560" height="315"></iframe>'
	. '<script data-cmp-category="statistics" src="https://x.md/a.js"></script>'
	. '<iframe data-cmp-category="marketing" src="https://x.md/w"></iframe>'
	. '<script type="text/plain" data-cmp-block="marketing" data-cmp-service="custom-1" data-cmp-enc="1" data-cmp-body="' . base64_encode( "fbq('init','1');" ) . '"></script>';
$once = CMP_Blocker::rewrite( $page );
cmp_assert_eq( CMP_Blocker::rewrite( $once ), $once, 'rewrite is idempotent' );
cmp_assert( false !== strpos( $once, 'data-cmp-embed="marketing"' ) && false !== strpos( $once, 'data-cmp-service="google-analytics"' ), 'idempotence page was actually rewritten' );

// custom_script_html.
$svc = function ( $cat, $script, $extra = array() ) {
	return array_merge( array( 'id' => 'custom-3', 'source' => 'custom', 'category' => $cat, 'exempt' => false, 'hosts' => array(), 'script' => $script ), $extra );
};
$o = CMP_Blocker::custom_script_html( array( $svc( 'marketing', '<script src="https://x.md/p.js" async></script>' ) ), 'pro' );
cmp_assert( false !== strpos( $o, 'type="text/plain"' ) && false !== strpos( $o, 'data-cmp-block="marketing"' ) && false !== strpos( $o, 'data-cmp-service="custom-3"' ), 'pro marketing src script is blocked with service id' );
cmp_assert( false !== strpos( $o, 'data-cmp-src="' . base64_encode( 'https://x.md/p.js' ) . '"' ) && false !== strpos( $o, ' async' ), 'blocked custom script has base64 src and kept attrs' );

$o = CMP_Blocker::custom_script_html( array( $svc( 'marketing', "fbq('x');" ) ), 'pro' );
cmp_assert( false !== strpos( $o, 'data-cmp-body="' . base64_encode( "fbq('x');" ) . '"' ) && false !== strpos( $o, 'data-cmp-service="custom-3"' ), 'pro bare body is blocked' );

$o = CMP_Blocker::custom_script_html( array( $svc( 'necessary', "var a=1;" ) ), 'pro' );
cmp_assert_eq( $o, '<script data-cmp-keep>var a=1;</script>' . "\n", 'necessary bare body runs normally' );
$o = CMP_Blocker::custom_script_html( array( $svc( 'necessary', '<script src="https://x.md/n.js"></script>' ) ), 'light' );
cmp_assert( false !== strpos( $o, 'src="https://x.md/n.js"' ) && false === strpos( $o, 'text/plain' ), 'necessary runs in light mode too' );
$o = CMP_Blocker::custom_script_html( array( $svc( 'marketing', 'var e=1;', array( 'exempt' => true ) ) ), 'light' );
cmp_assert( false !== strpos( $o, '<script data-cmp-keep>var e=1;</script>' ), 'exempt runs normally' );

cmp_assert_eq( CMP_Blocker::custom_script_html( array( $svc( 'marketing', 'var a=1;' ) ), 'light' ), '', 'light + marketing emits nothing' );

$o = CMP_Blocker::custom_script_html( array( $svc( 'marketing', '<div>hi</div><script>var z=1;</script><img src=x onerror=alert(1)>' ) ), 'pro' );
cmp_assert( false === strpos( $o, '<div' ) && false === strpos( $o, 'onerror' ) && false !== strpos( $o, base64_encode( 'var z=1;' ) ), 'non-script markup is dropped' );

$lib = CMP_Services::library();
cmp_assert_eq( CMP_Blocker::custom_script_html( array( $lib[0], $svc( 'marketing', '' ) ), 'pro' ), '', 'library and empty-script services are skipped' );

// Output of the necessary path survives the blocker untouched (GA host inside a kept script).
$o = CMP_Blocker::custom_script_html( array( $svc( 'necessary', '<script src="https://www.google-analytics.com/analytics.js"></script>' ) ), 'pro' );
cmp_assert_eq( CMP_Blocker::rewrite( $o ), $o, 'runnable custom script is not re-blocked by rewrite()' );
$o = CMP_Blocker::custom_script_html( array( $svc( 'marketing', '<script src="https://www.google-analytics.com/analytics.js"></script>' ) ), 'pro' );
cmp_assert_eq( CMP_Blocker::rewrite( $o ), $o, 'blocked custom script is not re-processed by rewrite()' );

// Fix round 1.
// M1: unquoted / single-quoted / slash-preceded category attribute.
$o = CMP_Blocker::rewrite( '<script data-cmp-category=marketing src="https://x.md/a.js"></script>' );
cmp_assert( false !== strpos( $o, 'data-cmp-block="marketing"' ), 'M1 unquoted script category is blocked' );
$o = CMP_Blocker::rewrite( "<script data-cmp-category='marketing' src=\"https://x.md/a.js\"></script>" );
cmp_assert( false !== strpos( $o, 'data-cmp-block="marketing"' ), 'M1 single-quoted script category is blocked' );
$o = CMP_Blocker::rewrite( '<iframe data-cmp-category=marketing src="https://x.md/w"></iframe>' );
cmp_assert( false !== strpos( $o, 'data-cmp-embed="marketing"' ), 'M1 unquoted iframe category is blocked' );
$o = CMP_Blocker::rewrite( '<script/data-cmp-category=marketing src="https://x.md/a.js"></script>' );
cmp_assert( false !== strpos( $o, 'data-cmp-block="marketing"' ), 'M1 slash-preceded category is blocked' );

// M2: invalid slug falls through to the library; necessary stays unchanged.
$ga = '<script data-cmp-category="Bad Cat" src="https://www.google-analytics.com/analytics.js"></script>';
$o  = CMP_Blocker::rewrite( $ga );
cmp_assert( false !== strpos( $o, 'data-cmp-service="google-analytics"' ) && false !== strpos( $o, 'data-cmp-block="statistics"' ), 'M2 invalid slug on GA is blocked as GA' );
$h = '<script data-cmp-category="necessary" src="https://www.google-analytics.com/analytics.js"></script>';
cmp_assert_eq( CMP_Blocker::rewrite( $h ), $h, 'M2 necessary on GA stays unchanged' );
$o = CMP_Blocker::rewrite( '<iframe data-cmp-category="Bad Cat" src="https://www.youtube.com/embed/abc"></iframe>' );
cmp_assert( false !== strpos( $o, 'data-cmp-service="youtube"' ), 'M2 invalid slug on YouTube iframe is blocked as YouTube' );

// M3: already-blocked needs type=text/plain AND an exact data-cmp-block attribute.
$o = CMP_Blocker::rewrite( '<script data-cmp-block="x" src="https://www.google-analytics.com/a.js"></script>' );
cmp_assert( false !== strpos( $o, 'type="text/plain"' ) && false !== strpos( $o, 'data-cmp-service="google-analytics"' ), 'M3 data-cmp-block without type=text/plain is still blocked' );
$o = CMP_Blocker::rewrite( '<script type="text/plain" data-cmp-block-foo="x" src="https://www.google-analytics.com/a.js"></script>' );
cmp_assert( false !== strpos( $o, 'data-cmp-service="google-analytics"' ), 'M3 data-cmp-block-foo does not count as blocked' );

// M6: keep marker detected as an attribute only.
$o = CMP_Blocker::custom_script_html( array( $svc( 'necessary', '<script src="https://x.md/data-cmp-keep.js"></script>' ) ), 'pro' );
cmp_assert( false !== strpos( $o, '<script data-cmp-keep src=' ), 'M6 necessary script with data-cmp-keep in its URL still gets the marker' );
$h = '<script src="https://www.google-analytics.com/a.js?data-cmp-keep"></script>';
cmp_assert( false !== strpos( CMP_Blocker::rewrite( $h ), 'data-cmp-block' ), 'M6 data-cmp-keep inside a URL does not exempt a tracker' );

// M8: no host -> name is the path without query/fragment.
$o = CMP_Blocker::rewrite( '<iframe data-cmp-category="marketing" src="/widget?token=abc#frag"></iframe>' );
cmp_assert( false !== strpos( $o, 'Conținut de la /widget.' ) && false === strpos( substr( $o, 0, strpos( $o, '<template' ) ), 'token' ), 'M8 placeholder name hides query string' );
