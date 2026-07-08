<?php
$GLOBALS['cmp_tests'] = array( 'pass' => 0, 'fail' => 0 );

function cmp_assert( $cond, $msg ) {
	if ( $cond ) {
		$GLOBALS['cmp_tests']['pass']++;
		echo "PASS: {$msg}\n";
	} else {
		$GLOBALS['cmp_tests']['fail']++;
		echo "FAIL: {$msg}\n";
	}
}

function cmp_assert_eq( $actual, $expected, $msg ) {
	$ok = ( $actual === $expected );
	if ( ! $ok ) {
		$msg .= ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')';
	}
	cmp_assert( $ok, $msg );
}

function cmp_test_summary() {
	$t = $GLOBALS['cmp_tests'];
	echo "\n{$t['pass']} passed, {$t['fail']} failed\n";
	exit( $t['fail'] > 0 ? 1 : 0 );
}
