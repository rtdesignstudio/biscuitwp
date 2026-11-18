<?php
require_once __DIR__ . '/../includes/class-services.php';
require_once __DIR__ . '/../includes/modules/class-blocker.php';

$cmp_tco = array( array( 'id' => 'custom-1', 'name' => 'T', 'type' => 'script', 'category' => 'marketing', 'hosts' => array( 't.co' ), 'inline' => array(), 'gtm' => false, 'exempt' => false ) );
$cmp_h1  = "<script>document.cookie='x=1'</script>";
cmp_assert_eq( CMP_Blocker::rewrite( $cmp_h1, array(), $cmp_tco ), $cmp_h1, 'I2b: t.co does not match document.cookie in a body' );
cmp_assert( false !== strpos( CMP_Blocker::rewrite( '<script src="https://t.co/x.js"></script>', array(), $cmp_tco ), 'text/plain' ), 'I2b: t.co src still rewritten' );
cmp_assert( false !== strpos( CMP_Blocker::rewrite( "<script>var s='//t.co/x.js';</script>", array(), $cmp_tco ), 'text/plain' ), 'I2b: t.co in loader body still rewritten' );
cmp_assert_eq( CMP_Blocker::strip_hints( array( 'https://document.co/x' ), array( 't.co' ) ), array( 'https://document.co/x' ), 'I2b: hint boundary' );
cmp_assert_eq( CMP_Blocker::strip_hints( array( 'https://cdn.t.co/x' ), array( 't.co' ) ), array(), 'I2b: hint subdomain matches' );
$cmp_ns = '<noscript>document.cookie</noscript>';
cmp_assert_eq( CMP_Blocker::strip_tracker_noscript( $cmp_ns, array( 't.co' ) ), $cmp_ns, 'I2b: noscript boundary' );
$cmp_ln = '<link rel="dns-prefetch" href="//document.co">';
cmp_assert_eq( CMP_Blocker::strip_hint_links( $cmp_ln, array( 't.co' ) ), $cmp_ln, 'I2b: link boundary' );
