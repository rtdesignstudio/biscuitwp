<?php
/** The banner picture (#727): model, rules, tag, parity with the standalone bundle. */
require_once __DIR__ . '/../includes/class-appearance.php';
require_once __DIR__ . '/../includes/modules/class-banner.php';

$bi = function ( array $in ) {
	return CMP_Appearance::sanitize( $in, CMP_Appearance::defaults( 'brand' ) );
};
$bi_d = CMP_Appearance::defaults( 'friendly' );
cmp_assert( 'none' === $bi_d['image'] && '' === CMP_Appearance::image_css( $bi_d ), 'picture: off by default in every style, no rule' );
$bi_a = $bi( array( 'image' => 'shield', 'image_size' => '500', 'image_side' => 'up', 'image_alt' => '<b>x</b>', 'image_id' => '-3', 'image_out' => '0' ) );
cmp_assert( 160 === $bi_a['image_size'] && 'right' === $bi_a['image_side'] && false === strpos( $bi_a['image_alt'], '<' ) && 0 === $bi_a['image_id'] && false === $bi_a['image_out'], 'picture: values are clamped and cleaned' );
cmp_assert( 'none' === $bi( array( 'image' => 'https://evil.example/x.png' ) )['image'], 'picture: only the known choices, never an address' );

// Equal weight: no rule of the picture names a button.
foreach ( array( array(), array( 'image_out' => '0' ), array( 'image_side' => 'left', 'image_phone' => 'none' ), array( 'format' => 'bar', 'position' => 'top' ) ) as $bi_x ) {
	$css = CMP_Appearance::image_css( $bi( array_merge( array( 'image' => 'wp' ), $bi_x ) ) );
	cmp_assert( '' !== $css && false === strpos( $css, 'cmp__btn' ) && false === strpos( $css, 'cmp__choices' ) && false === strpos( $css, '<' ), 'picture: its rules touch no button ' . json_encode( $bi_x ) );
}
cmp_assert( false !== strpos( CMP_Appearance::image_css( $bi( array( 'image' => 'wp', 'image_size' => 120 ) ) ), 'top:-64px;right:24px;width:120px' ), 'picture: outside, 56px of it inside, beside the title' );
cmp_assert( false !== strpos( CMP_Appearance::image_css( $bi( array( 'image' => 'wp', 'format' => 'card', 'position' => 'top-left' ) ) ), '.cmp__img{position:absolute;z-index:1;top:20px' ), 'picture: inside at a top position' );
cmp_assert( false !== strpos( CMP_Appearance::inline_css( $bi( array( 'image' => 'wp' ) ) ), '.cmp__img{' ) && '' === CMP_Appearance::inline_css( CMP_Appearance::defaults( 'brand' ) ), 'picture: its rules are in the inline block, Brand alone still prints nothing' );

// Only from this site.
$bi_b = array( 'https://exemplu.md', 'https://exemplu.md/wp' );
foreach ( array( 'https://exemplu.md/u/a.png' => true, '/wp-content/a.png' => true, 'https://cdn.exemplu.md/a.png' => false, '//evil.example/a.png' => false, 'data:image/png;base64,x' => false, 'javascript:alert(1)' => false, 'https://exemplu.md/a"b.png' => false, '' => false ) as $bi_u => $bi_w ) {
	cmp_assert_eq( CMP_Banner::is_local_url( $bi_u, $bi_b ), $bi_w, "picture: local address check {$bi_u}" );
}
cmp_assert_eq( CMP_Banner::image_html( $bi_d ), '', 'picture: no tag without it' );

// Parity with the standalone bundle.
$bi_cases = array(
	array( 'image' => 'wp' ),
	array( 'image' => 'wp', 'image_size' => 48, 'image_side' => 'left' ),
	array( 'image' => 'wp', 'image_size' => 160, 'image_out' => '0', 'image_phone' => 'none' ),
	array( 'image' => 'wp', 'format' => 'bar', 'position' => 'top' ),
);
$bi_js = trim( (string) shell_exec( 'command -v node' ) );
if ( '' !== $bi_js ) {
	$bi_in = array();
	foreach ( $bi_cases as $c ) {
		$a       = $bi( $c );
		$bi_in[] = array( 'style' => 'brand', 'format' => $a['format'], 'position' => $a['position'], 'image' => array( 'src' => '/x.webp', 'size' => $a['image_size'], 'side' => $a['image_side'], 'out' => $a['image_out'], 'phone' => $a['image_phone'] ) );
	}
	$bi_o = json_decode( (string) shell_exec( escapeshellarg( $bi_js ) . ' -e ' . escapeshellarg( 'const m=require(process.argv[1]);console.log(JSON.stringify(JSON.parse(process.argv[2]).map(x=>m.appearanceCss(m.normAppearance(x)))))' ) . ' ' . escapeshellarg( __DIR__ . '/../standalone/cmp.js' ) . ' ' . escapeshellarg( json_encode( $bi_in ) ) ), true );
	foreach ( $bi_cases as $i => $c ) {
		cmp_assert_eq( isset( $bi_o[ $i ] ) ? $bi_o[ $i ] : null, CMP_Appearance::inline_css( $bi( $c ) ), 'picture: standalone gives the same CSS ' . json_encode( $c ) );
	}
	$bi_bad = json_decode( (string) shell_exec( escapeshellarg( $bi_js ) . ' -e ' . escapeshellarg( 'const m=require(process.argv[1]);console.log(JSON.stringify(["javascript:x","data:image/png,x","a b.png","\"x",""].map(s=>m.normAppearance({image:{src:s}}).image)))' ) . ' ' . escapeshellarg( __DIR__ . '/../standalone/cmp.js' ) ), true );
	cmp_assert_eq( $bi_bad, array( null, null, null, null, null ), 'picture: standalone refuses unsafe addresses' );
}
