<?php
// "Accept" and "Reject" must carry the same visual weight (EDPB cookie banner
// taskforce 2023, CNIL): same button class, inside the same equal-width group,
// in both views, in the WordPress template and in the standalone bundle.

$cmp_sources = array(
	'template'   => (string) file_get_contents( __DIR__ . '/../templates/banner-bottom.php' ),
	'standalone' => (string) file_get_contents( __DIR__ . '/../standalone/cmp.js' ),
);

/** Class attribute of every button with this data-cmp-action, in source order. */
function cmp_button_classes( $src, $action ) {
	preg_match_all( '/<button\b[^>]*class="([^"]*)"[^>]*data-cmp-action="' . preg_quote( $action, '/' ) . '"/', $src, $m );
	return $m[1];
}

foreach ( $cmp_sources as $cmp_name => $cmp_src ) {
	$reject = cmp_button_classes( $cmp_src, 'reject' );
	$accept = cmp_button_classes( $cmp_src, 'accept' );
	$save   = cmp_button_classes( $cmp_src, 'save' );

	cmp_assert_eq( count( $reject ), 2, "$cmp_name: reject button in both views" );
	cmp_assert( 1 === count( $accept ) && $reject[0] === $accept[0], "$cmp_name: first view — reject styled exactly like accept" );
	cmp_assert( 1 === count( $save ) && isset( $reject[1] ) && $reject[1] === $save[0], "$cmp_name: preferences — reject styled exactly like save" );
	// The markup's class attribute (cmp.js also carries .cmp__choices in its density CSS strings).
	cmp_assert_eq( substr_count( $cmp_src, 'class="cmp__choices"' ), 2, "$cmp_name: both decision pairs sit in an equal-width group" );
}

$cmp_css = (string) file_get_contents( __DIR__ . '/../assets/css/banner.css' );
cmp_assert( (bool) preg_match( '/\.cmp__choices\s*\{[^}]*grid-template-columns:\s*1fr 1fr/', $cmp_css ), 'CSS: decision group has two equal columns' );
// B5 Task 5 (phone band): no rule styles one decision apart from the other, and
// on phones both buttons share one rule (two equal columns, 44 px high).
cmp_assert( 0 === preg_match( '/data-cmp-action="?(reject|accept|save)/', $cmp_css ), 'CSS: no rule targets reject / accept / save alone' );
cmp_assert( (bool) preg_match( '/@media \(max-width: 575px\)\s*\{[^@]*\.cmp__choices \.cmp__btn\s*\{[^}]*flex:\s*1 1 0;[^}]*min-height:\s*44px/', $cmp_css ), 'CSS: on phones both decisions share one rule (flex: 1 1 0, min-height: 44px)' );
