<?php
/** The site logo in the settings window (#727 step 7): model, rule, tag, export, parity with the standalone bundle. */
require_once __DIR__ . '/../includes/class-appearance.php';
require_once __DIR__ . '/../includes/modules/class-banner.php';
require_once __DIR__ . '/../includes/class-appearance-io.php';

$lg = function ( array $in ) {
	return CMP_Appearance::sanitize( $in, CMP_Appearance::defaults( 'brand' ) );
};
foreach ( array( 'brand', 'minimal', 'friendly' ) as $lg_st ) {
	$lg_d = CMP_Appearance::defaults( $lg_st );
	cmp_assert( 'none' === $lg_d['logo'] && 0 === $lg_d['logo_id'] && '' === CMP_Appearance::logo_css( $lg_d ), "logo: off by default ({$lg_st}), no rule" );
}
cmp_assert( '' === CMP_Appearance::inline_css( CMP_Appearance::defaults( 'brand' ) ), 'logo: Brand alone still prints nothing' );
cmp_assert( 'theme' === $lg( array( 'logo' => 'theme' ) )['logo'] && 'own' === $lg( array( 'logo' => 'own' ) )['logo'], 'logo: the theme logo or an own image' );
cmp_assert( 'none' === $lg( array( 'logo' => 'https://evil.example/l.png' ) )['logo'], 'logo: only the known choices, never an address' );
cmp_assert( 0 === $lg( array( 'logo_id' => '-4' ) )['logo_id'] && 12 === $lg( array( 'logo_id' => '12' ) )['logo_id'], 'logo: its media id is a positive number' );

$lg_css = CMP_Appearance::logo_css( $lg( array( 'logo' => 'theme' ) ) );
cmp_assert( false !== strpos( $lg_css, '.cmp__logo{' ) && false !== strpos( $lg_css, 'height:32px' ) && false !== strpos( $lg_css, 'max-width:160px' ), 'logo: 32px high, at most 160px wide' );
cmp_assert( false === strpos( $lg_css, 'cmp__btn' ) && false === strpos( $lg_css, 'cmp__choices' ) && false === strpos( $lg_css, '<' ), 'logo: its rule touches no button' );
cmp_assert( false !== strpos( CMP_Appearance::inline_css( $lg( array( 'logo' => 'own', 'logo_id' => 3 ) ) ), $lg_css ), 'logo: its rule is in the inline block' );

// The tag: lazy (the settings window is hidden until opened), escaped, '' without an address.
$lg_t = CMP_Banner::logo_tag( '/wp-content/uploads/l.png', 'Ana & "Co"' );
cmp_assert( 0 === strpos( $lg_t, '<img class="cmp__logo" src="/wp-content/uploads/l.png"' ) && false !== strpos( $lg_t, 'alt="Ana &amp; &quot;Co&quot;"' ) && false !== strpos( $lg_t, 'loading="lazy"' ), 'logo: lazy tag with the site name as alt' );
cmp_assert_eq( CMP_Banner::logo_tag( '', 'x' ), '', 'logo: no tag without an address' );
cmp_assert_eq( CMP_Banner::logo_html( CMP_Appearance::defaults( 'brand' ) ), '', 'logo: no tag when off' );

// Export: the theme choice travels, the site's own image does not.
$lg_e = CMP_Appearance_IO::export( array( 'appearance' => array( 'style' => 'brand', 'logo' => 'own', 'logo_id' => 9 ) ), '1.2.0' );
cmp_assert( 'none' === $lg_e['appearance']['logo'] && ! array_key_exists( 'logo_id', $lg_e['appearance'] ), 'logo: export turns the own image into none, without its id' );
$lg_e = CMP_Appearance_IO::export( array( 'appearance' => array( 'style' => 'brand', 'logo' => 'theme' ) ), '1.2.0' );
cmp_assert_eq( $lg_e['appearance']['logo'], 'theme', 'logo: export keeps the theme logo' );

// Parity with the standalone bundle.
$lg_js = trim( (string) shell_exec( 'command -v node' ) );
if ( '' !== $lg_js ) {
	$lg_o = json_decode( (string) shell_exec( escapeshellarg( $lg_js ) . ' -e ' . escapeshellarg( 'const m=require(process.argv[1]);const n=x=>m.normAppearance(x);console.log(JSON.stringify([m.appearanceCss(n({style:"brand",logo:{src:"/l.png"}})),n({logo:{src:"/l.png",alt:"S"}}).logo,["javascript:x","\"x","a b.png",""].map(s=>n({logo:{src:s}}).logo)]))' ) . ' ' . escapeshellarg( __DIR__ . '/../standalone/cmp.js' ) ), true );
	cmp_assert_eq( isset( $lg_o[0] ) ? $lg_o[0] : null, CMP_Appearance::inline_css( $lg( array( 'logo' => 'theme' ) ) ), 'logo: standalone gives the same CSS' );
	cmp_assert_eq( isset( $lg_o[1] ) ? $lg_o[1] : null, array( 'src' => '/l.png', 'alt' => 'S' ), 'logo: standalone keeps a path and its alt' );
	cmp_assert_eq( isset( $lg_o[2] ) ? $lg_o[2] : null, array( null, null, null, null ), 'logo: standalone refuses unsafe addresses' );
}
