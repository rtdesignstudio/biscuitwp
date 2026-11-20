<?php
/**
 * B5 Task 3: described dialog, focusable settings title, `lang` on the banner
 * containers, reduced motion. Renders the real templates with minimal stubs.
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once __DIR__ . '/../includes/modules/class-lang.php';
require_once __DIR__ . '/../includes/modules/class-i18n.php';
// B6: the templates read the appearance (CMP_Appearance) and the texts / classes (CMP_Banner).
require_once __DIR__ . '/../includes/class-contrast.php';
require_once __DIR__ . '/../includes/class-appearance.php';
require_once __DIR__ . '/../includes/modules/class-banner.php';
foreach ( array( 'esc_attr', 'esc_html', 'esc_url' ) as $a11y_fn ) {
	if ( ! function_exists( $a11y_fn ) ) {
		eval( "function $a11y_fn( \$t ) { return htmlspecialchars( (string) \$t, ENT_QUOTES, 'UTF-8' ); }" );
	}
}
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $id ) {
		return false;
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $id ) {
		return '';
	}
}

/** Render a banner template the way CMP_Banner::render() includes it. */
function cmp_a11y_render( $file, $no_overlay = false, $extra = array() ) {
	$settings   = array_merge( array( 'texts' => array(), 'position' => 'bottom-bar' ), $extra );
	$categories = array(
		array( 'slug' => 'necessary', 'label' => 'N', 'description' => 'n', 'required' => true ),
		array( 'slug' => 'statistics', 'label' => 'S', 'description' => 's', 'required' => false ),
	);
	$preset = 'neutral';
	ob_start();
	include __DIR__ . '/../templates/' . $file;
	return (string) ob_get_clean();
}

$a11y_lang = CMP_Lang::current();
cmp_assert_eq( $a11y_lang, 'ro', 'a11y: test environment resolves the language to ro' );

$a11y_pro = cmp_a11y_render( 'banner-bottom.php' );
cmp_assert( 1 === preg_match( '/<div id="cmp-overlay" class="cmp [^"]*"[^>]*\blang="ro"/', $a11y_pro ), 'a11y: #cmp-overlay.cmp carries lang="ro"' );
cmp_assert( 1 === preg_match( '/<div class="cmp__modal" role="dialog"[^>]*aria-labelledby="cmp-title"[^>]*aria-describedby="cmp-intro"/', $a11y_pro ), 'a11y: dialog described by the intro on the main view' );
cmp_assert( 1 === preg_match( '/<p id="cmp-intro" class="cmp__text">/', $a11y_pro ), 'a11y: intro text has id="cmp-intro"' );
cmp_assert( 1 === substr_count( $a11y_pro, 'id="cmp-intro"' ), 'a11y: cmp-intro id is unique' );
cmp_assert( 1 === preg_match( '/<h2 id="cmp-prefs-title"[^>]*\btabindex="-1"/', $a11y_pro ), 'a11y: settings title is focusable by script (tabindex="-1")' );
cmp_assert( 1 === preg_match( '/<div id="cmp-overlay"[^>]*\blang="ro"/', cmp_a11y_render( 'banner-bottom.php', true ) ), 'a11y: lang also on the no-overlay banner' );

$a11y_light = cmp_a11y_render( 'banner-light.php' );
cmp_assert( 1 === preg_match( '/<div class="cmp-light [^"]*"[^>]*\blang="ro"/', $a11y_light ), 'a11y: .cmp-light carries lang="ro"' );

// The language comes from CMP_Lang (WPML / Polylang / locale), not a fixed value.
foreach ( array( 'banner-bottom.php', 'banner-light.php' ) as $a11y_tpl ) {
	$a11y_src = (string) file_get_contents( __DIR__ . '/../templates/' . $a11y_tpl );
	cmp_assert( false !== strpos( $a11y_src, 'lang="<?php echo esc_attr( CMP_Lang::current() ); ?>"' ), "a11y: $a11y_tpl takes lang from CMP_Lang::current()" );
}

// Reduced motion: no slide / fade for visitors who asked for less motion.
$a11y_css = (string) file_get_contents( __DIR__ . '/../assets/css/banner.css' );
cmp_assert(
	1 === preg_match( '/@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{\s*\.cmp,\s*\.cmp \*,\s*\.cmp \*::before,\s*\.cmp \*::after,\s*\.cmp-light,\s*\.cmp-light \*,\s*\.cmp-light \*::before,\s*\.cmp-light \*::after,\s*\.cmp-reopen\s*\{\s*transition:\s*none !important;\s*animation:\s*none !important;\s*\}\s*\}/', $a11y_css ),
	'a11y: banner.css has the prefers-reduced-motion rule (incl. ::before/::after, e.g. the toggle knob, and the B6 fade/slide on .cmp / .cmp-light themselves)'
);
$a11y_min = (string) file_get_contents( __DIR__ . '/../assets/css/banner.min.css' );
cmp_assert( false !== strpos( $a11y_min, 'prefers-reduced-motion' ), 'a11y: banner.min.css keeps the reduced-motion rule' );
$a11y_sa = (string) @file_get_contents( __DIR__ . '/../standalone/cmp.css' );
cmp_assert( false !== strpos( $a11y_sa, 'prefers-reduced-motion' ), 'a11y: standalone/cmp.css keeps the reduced-motion rule' );
