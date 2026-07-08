<?php
require __DIR__ . '/lib.php';
foreach ( glob( __DIR__ . '/test-*.php' ) as $file ) {
	require $file;
}
cmp_test_summary();
