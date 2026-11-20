<?php
/**
 * B6 Task 3: the banner uses the appearance (classes, inline <style>, custom
 * CSS, exclusions, reopen as icon / text / both, CMP_DATA.appearance,
 * per-language texts). Pure helpers of CMP_Banner / CMP_Appearance, plus the
 * real templates rendered with minimal stubs (cmp_a11y_render, test-a11y.php).
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once __DIR__ . '/../includes/class-contrast.php';
require_once __DIR__ . '/../includes/class-appearance.php';
require_once __DIR__ . '/../includes/modules/class-lang.php';
require_once __DIR__ . '/../includes/modules/class-i18n.php';
require_once __DIR__ . '/../includes/modules/class-banner.php';

// ---- Exclusions: exact path or prefix with a trailing *, normalised ----
$ba_ex = array( '/landing/*', '/about/', '/blog*', '/' );
cmp_assert( CMP_Appearance::excluded( array( '/landing/*' ), '/landing/' ), 'exclude: /landing/* matches /landing/' );
cmp_assert( CMP_Appearance::excluded( array( '/landing/*' ), '/landing/offer/x' ), 'exclude: /landing/* matches a deeper page' );
cmp_assert( CMP_Appearance::excluded( array( '/landing/*' ), '/landing' ), 'exclude: /landing/* matches /landing (trailing slash insensitive)' );
cmp_assert( CMP_Appearance::excluded( array( '/landing/*' ), '/landing/?utm=1#x' ), 'exclude: the query and the fragment are ignored' );
cmp_assert( ! CMP_Appearance::excluded( array( '/landing/*' ), '/landingx/' ), 'exclude: /landing/* does not match /landingx/' );
cmp_assert( ! CMP_Appearance::excluded( array( '/landing/*' ), '/other/landing/' ), 'exclude: prefix means the start of the path' );
cmp_assert( CMP_Appearance::excluded( array( '/about/' ), '/about' ), 'exclude: exact /about/ matches /about' );
cmp_assert( CMP_Appearance::excluded( array( '/about' ), '/about/?x=1' ), 'exclude: exact /about matches /about/?x=1' );
cmp_assert( ! CMP_Appearance::excluded( array( '/about/' ), '/about/team/' ), 'exclude: exact does not match a sub-page' );
cmp_assert( CMP_Appearance::excluded( array( '/blog*' ), '/blog-post/' ), 'exclude: /blog* is a plain prefix' );
cmp_assert( CMP_Appearance::excluded( array( '/' ), '/' ) && ! CMP_Appearance::excluded( array( '/' ), '/x/' ), 'exclude: / matches only the home page' );
cmp_assert( ! CMP_Appearance::excluded( array(), '/landing/' ), 'exclude: no patterns -> never' );
cmp_assert( CMP_Appearance::excluded( array( '/landing/*' ), '/app/landing/x/', '/app/' ), 'exclude: the site base path (subdirectory install) is stripped' );
cmp_assert( CMP_Appearance::excluded( array( '/app/landing/*' ), '/app/landing/x/', '/app' ), 'exclude: the full request path matches too' );
cmp_assert( ! CMP_Appearance::excluded( array( '/landing/*' ), '/application/landing/', '/app' ), 'exclude: the base is stripped only as a whole segment' );
cmp_assert( CMP_Appearance::excluded( array( '/despre/*' ), '/despre/%C8%99/' ) && CMP_Appearance::excluded( array( '/%C8%99*' ), '/ș/' ), 'exclude: percent-encoding compared decoded' );
cmp_assert( ! CMP_Appearance::excluded( array( '/landing/*' ), '' ) && ! CMP_Appearance::excluded( array( 'x', 42, null ), '/x' ), 'exclude: empty path / bad patterns -> false' );

// ---- Container classes ----
$ba_legacy = array( 'preset' => 'egov', 'position' => 'bottom-right', 'reopen_button' => 1 );
$ba_cls    = CMP_Banner::classes( $ba_legacy, CMP_Appearance::from_settings( $ba_legacy, 'ro' ), 'pro' );
cmp_assert_eq( array_slice( $ba_cls, 0, 2 ), array( 'cmp--egov', 'cmp--pos-bottom-right' ), 'classes: legacy keeps today\'s classes first (cmp--<preset> cmp--pos-<position>)' );
foreach ( CMP_Appearance::container_classes( CMP_Appearance::from_settings( $ba_legacy, 'ro' ), 'pro' ) as $ba_c ) {
	cmp_assert( in_array( $ba_c, $ba_cls, true ), "classes: legacy also gets $ba_c" );
}
cmp_assert_eq( count( $ba_cls ), count( array_unique( $ba_cls ) ), 'classes: no duplicates' );
$ba_l2 = array( 'position' => 'bottom' );
$ba_c2 = CMP_Banner::classes( $ba_l2, CMP_Appearance::from_settings( $ba_l2, 'ro' ), 'pro' );
cmp_assert( in_array( 'cmp--pos-bottom', $ba_c2, true ) && in_array( 'cmp--pos-bottom-center', $ba_c2, true ) && ! in_array( 'cmp--fmt-bar', $ba_c2, true ), 'classes: legacy bottom keeps cmp--pos-bottom (+ its alias), never the bar' );
$ba_c3 = CMP_Banner::classes( array(), CMP_Appearance::from_settings( array(), 'ro' ), 'pro' );
cmp_assert_eq( array_slice( $ba_c3, 0, 2 ), array( 'cmp--neutral', 'cmp--pos-modal-center' ), 'classes: legacy defaults = today\'s (neutral, modal-center)' );
$ba_c4 = CMP_Banner::classes( array( 'preset' => 'x"><script>', 'position' => 'a b' ), CMP_Appearance::from_settings( array(), 'ro' ), 'pro' );
cmp_assert( ! preg_grep( '/[^a-z0-9-]/', $ba_c4 ), 'classes: odd stored preset / position never reach the markup' );
$ba_new  = array( 'preset' => 'egov', 'position' => 'bottom-right', 'appearance' => array( 'style' => 'friendly', 'palette' => 'warm', 'format' => 'bar', 'position' => 'top', 'theme' => 'dark' ) );
$ba_newa = CMP_Appearance::from_settings( $ba_new, 'ro' );
cmp_assert_eq( CMP_Banner::classes( $ba_new, $ba_newa, 'pro' ), CMP_Appearance::container_classes( $ba_newa, 'pro' ), 'classes: stored appearance -> exactly container_classes (no legacy keys)' );
cmp_assert_eq( CMP_Banner::classes( $ba_legacy, CMP_Appearance::from_settings( $ba_legacy, 'ro' ), 'light' )[0], 'cmp--egov', 'classes: Light legacy keeps cmp--<preset> first' );
cmp_assert( ! in_array( 'cmp--pos-bottom-right', CMP_Banner::classes( $ba_legacy, CMP_Appearance::from_settings( $ba_legacy, 'ro' ), 'light' ), true ), 'classes: Light has no position class (as today)' );

// ---- Inline <style>: only when non-empty, custom CSS after it ----
cmp_assert_eq( CMP_Banner::style_html( CMP_Appearance::from_settings( $ba_legacy, 'ro' ) ), '', 'style: legacy site -> nothing printed (no empty <style>)' );
cmp_assert_eq( CMP_Banner::style_html( CMP_Appearance::defaults( 'brand' ) ), '', 'style: Brand + palette -> nothing printed' );
$ba_fr  = CMP_Appearance::sanitize( array( 'style' => 'friendly', 'accent' => '#e4572e', 'theme' => 'auto' ), CMP_Appearance::defaults( 'brand' ) );
$ba_fs  = CMP_Banner::style_html( $ba_fr );
cmp_assert_eq( $ba_fs, '<style id="cmp-appearance-css">' . CMP_Appearance::inline_css( $ba_fr ) . "</style>\n", 'style: the inline block in <style id="cmp-appearance-css">' );
$ba_cc  = CMP_Appearance::defaults( 'brand' );
$ba_cc['custom_css'] = '.cmp__title{letter-spacing:1px}';
cmp_assert_eq( CMP_Banner::style_html( $ba_cc ), '<style id="cmp-custom-css">.cmp__title{letter-spacing:1px}</style>' . "\n", 'style: custom CSS alone -> only <style id="cmp-custom-css">' );
$ba_both = $ba_fr;
$ba_both['custom_css'] = '.cmp__title{color:red}';
$ba_bs   = CMP_Banner::style_html( $ba_both );
cmp_assert( false !== strpos( $ba_bs, 'cmp-appearance-css' ) && strpos( $ba_bs, 'cmp-appearance-css' ) < strpos( $ba_bs, 'cmp-custom-css' ), 'style: custom CSS comes after the inline block' );
$ba_ev = CMP_Appearance::defaults( 'brand' );
$ba_ev['custom_css'] = '.a{color:red}</style><script>alert(1)</script><style>[data-cmp-action="reject"]{display:none}';
$ba_es = CMP_Banner::style_html( $ba_ev );
cmp_assert_eq( substr_count( strtolower( $ba_es ), '</style' ), 1, 'style: a stored "</style" cannot close the block early (cleaned again at print)' );
cmp_assert( false === strpos( $ba_es, '<script' ) && false === strpos( $ba_es, 'reject' ), 'style: script and reject-hiding rules removed at print too' );
cmp_assert( false !== strpos( $ba_es, '.a{color:red}' ), 'style: the harmless rule stays' );
// Lot review I4: "Preia din temă" gets the theme.json palette (read only when the option is on).
$ba_tp = json_decode( file_get_contents( __DIR__ . '/fixtures/theme-palettes.json' ), true );
$ba_tc = CMP_Appearance::defaults( 'brand' );
$ba_tc['theme_colors'] = true;
cmp_assert_eq( CMP_Banner::style_html( $ba_tc, $ba_tp['passing'] ), '<style id="cmp-appearance-css">' . CMP_Appearance::inline_css( $ba_tc, $ba_tp['passing'] ) . "</style>\n", 'style I4: the theme palette reaches inline_css' );
cmp_assert_eq( CMP_Banner::theme_palette( CMP_Appearance::defaults( 'brand' ) ), array(), 'style I4: option off -> the theme palette is not read' );
cmp_assert( is_array( CMP_Banner::theme_palette( $ba_tc ) ), 'style I4: without wp_get_global_settings -> empty palette' );
$ba_src0 = (string) file_get_contents( __DIR__ . '/../includes/modules/class-banner.php' );
cmp_assert( false !== strpos( $ba_src0, "wp_get_global_settings( array( 'color', 'palette', 'theme' ) )" ), 'style I4: the palette comes from wp_get_global_settings (theme.json)' );
cmp_assert( 1 === preg_match( '/self::style_html\(\s*\$a,\s*self::theme_palette\(\s*\$a\s*\)\s*\)/', $ba_src0 ), 'style I4: print_appearance_css passes the theme palette' );
cmp_assert( CMP_Banner::STYLE_PRIORITY > 8, 'style: printed in wp_head after wp_print_styles (8), i.e. after banner.css' );
$ba_src = (string) file_get_contents( __DIR__ . '/../includes/modules/class-banner.php' );
cmp_assert( false !== strpos( $ba_src, "add_action( 'wp_head', array( \$this, 'print_appearance_css' ), self::STYLE_PRIORITY );" ), 'style: hooked on wp_head at STYLE_PRIORITY' );

// ---- Reopen: icon (today), text, both; the on/off checkbox kept ----
$ba_icon_today = '<button type="button" class="cmp-reopen" data-cmp-reopen hidden aria-label="Setări cookie" title="Setări cookie">'
	. '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5Z"/><circle cx="9.5" cy="10.5" r="1"/><circle cx="14.5" cy="14.5" r="1"/><circle cx="9" cy="15" r="1"/></svg>'
	. '</button>';
cmp_assert_eq( CMP_Banner::reopen_html( 'icon', 'Setări cookie' ), $ba_icon_today, 'reopen: icon = today\'s markup, byte for byte' );
cmp_assert_eq( CMP_Banner::reopen_html( 'none', 'x' ), '', 'reopen: none -> nothing' );
$ba_rt = CMP_Banner::reopen_html( 'text', 'Setări <cookie>' );
cmp_assert( 1 === preg_match( '#^<button type="button" class="cmp-reopen cmp-reopen--text" data-cmp-reopen hidden><span class="cmp-reopen__label">Setări &lt;cookie&gt;</span></button>$#', $ba_rt ), 'reopen: text = visible label (escaped), no icon, no redundant aria-label' );
$ba_rb = CMP_Banner::reopen_html( 'both', 'Setări cookie' );
cmp_assert( false !== strpos( $ba_rb, 'class="cmp-reopen cmp-reopen--text"' ) && false !== strpos( $ba_rb, '<svg' ) && false !== strpos( $ba_rb, '<span class="cmp-reopen__label">Setări cookie</span>' ), 'reopen: both = icon + label' );
cmp_assert_eq( CMP_Banner::reopen_shape( array(), CMP_Appearance::from_settings( array(), 'ro' ) ), 'none', 'reopen: legacy checkbox off -> none' );
cmp_assert_eq( CMP_Banner::reopen_shape( $ba_legacy, CMP_Appearance::from_settings( $ba_legacy, 'ro' ) ), 'icon', 'reopen: legacy checkbox on -> icon (today)' );
$ba_rs = array( 'reopen_button' => 1, 'appearance' => array( 'reopen' => 'text' ) );
cmp_assert_eq( CMP_Banner::reopen_shape( $ba_rs, CMP_Appearance::from_settings( $ba_rs, 'ro' ) ), 'text', 'reopen: checkbox on + shape text -> text' );
$ba_rs['appearance']['reopen'] = 'both';
cmp_assert_eq( CMP_Banner::reopen_shape( $ba_rs, CMP_Appearance::from_settings( $ba_rs, 'ro' ) ), 'both', 'reopen: shape both' );
$ba_rs['reopen_button'] = 0;
cmp_assert_eq( CMP_Banner::reopen_shape( $ba_rs, CMP_Appearance::from_settings( $ba_rs, 'ro' ) ), 'none', 'reopen: checkbox off -> none whatever the shape' );
$ba_rs = array( 'reopen_button' => 1, 'appearance' => array( 'reopen' => 'none' ) );
cmp_assert_eq( CMP_Banner::reopen_shape( $ba_rs, CMP_Appearance::from_settings( $ba_rs, 'ro' ) ), 'none', 'reopen: shape none -> none' );
$ba_css = (string) file_get_contents( __DIR__ . '/../assets/css/banner.css' );
cmp_assert( 1 === preg_match( '/\.cmp-reopen--text\s*\{[^}]*width:\s*auto/', $ba_css ), 'reopen: CSS sizes the text button to its label' );

// ---- CMP_DATA.appearance ----
$ba_d = CMP_Banner::appearance_data( CMP_Appearance::from_settings( $ba_legacy, 'ro' ), false );
cmp_assert_eq( $ba_d, array( 'delay' => 0.0, 'theme' => 'light', 'noAutoOpen' => false ), 'data: legacy -> no delay, light, auto-open' );
$ba_sa = CMP_Appearance::sanitize( array( 'theme' => 'site', 'delay' => '1.25', 'dark_selector' => 'body.night, html[data-mode="dark"]' ), CMP_Appearance::defaults( 'brand' ) );
cmp_assert_eq( CMP_Banner::appearance_data( $ba_sa, true ), array( 'delay' => 1.3, 'theme' => 'site', 'noAutoOpen' => true, 'darkSelector' => 'body.night, html[data-mode="dark"]' ), 'data: site theme carries its selector; excluded page -> noAutoOpen' );
$ba_sb = CMP_Appearance::sanitize( array( 'theme' => 'site', 'dark_selector' => 'x{}</script><b' ), CMP_Appearance::defaults( 'brand' ) );
cmp_assert_eq( CMP_Banner::appearance_data( $ba_sb, false )['darkSelector'], CMP_Appearance::DARK_SELECTOR, 'data: an unsafe selector never reaches the page (default instead)' );
$ba_sc = CMP_Appearance::sanitize( array( 'delay' => 9 ), CMP_Appearance::defaults( 'brand' ) );
cmp_assert_eq( CMP_Banner::appearance_data( $ba_sc, false )['delay'], 2.0, 'data: delay capped at 2 s' );

// ---- Per-language texts ----
$ba_ts = array(
	'texts'      => array( 'title' => 'Vechi', 'accept' => '' ),
	'appearance' => array( 'texts' => array( 'en' => array( 'title' => 'EN title', 'intro' => '', 'light_intro' => 'EN light' ), 'ro' => array( 'title' => 'RO title' ) ) ),
);
$ba_ta = CMP_Appearance::from_settings( $ba_ts, 'ro' );
cmp_assert_eq( CMP_Banner::text( 'title', $ba_ts, $ba_ta, 'en', 'Def', false ), 'EN title', 'texts: the language\'s own text wins' );
cmp_assert_eq( CMP_Banner::text( 'title', $ba_ts, $ba_ta, 'ru', 'Def', false ), 'Vechi', 'texts: empty for the language -> the old (single) text' );
cmp_assert_eq( CMP_Banner::text( 'accept', $ba_ts, $ba_ta, 'ru', 'Def', false ), 'Def', 'texts: neither -> the CMP_Lang default' );
cmp_assert_eq( CMP_Banner::text( 'light_intro', $ba_ts, $ba_ta, 'en', 'Def', false ), 'EN light', 'texts: the Light intro too' );
cmp_assert_eq( CMP_Banner::text( 'title', $ba_ts, $ba_ta, 'en', 'Def', true ), 'Vechi', 'texts: WPML / Polylang active -> today\'s path (CMP_I18n over the old text)' );
cmp_assert_eq( CMP_Banner::text( 'accept', $ba_ts, $ba_ta, 'en', 'Def', true ), 'Def', 'texts: WPML / Polylang, empty old text -> default' );
$ba_tl = array( 'texts' => array( 'title' => 'Titlul meu' ) );
cmp_assert_eq( CMP_Banner::text( 'title', $ba_tl, CMP_Appearance::from_settings( $ba_tl, 'ro' ), 'ro', 'Def', false ), 'Titlul meu', 'texts: legacy site -> today\'s text' );
cmp_assert( false === CMP_Banner::multilingual(), 'texts: no WPML / Polylang in the test environment' );

// ---- Rendered templates ----
$ba_html = cmp_a11y_render( 'banner-bottom.php' );
cmp_assert( 1 === preg_match( '/<div id="cmp-overlay" class="cmp cmp--neutral cmp--pos-bottom-bar cmp--style-brand cmp--fmt-modal cmp--pos-middle-center cmp--btn-solid cmp--anim-none cmp--theme-light cmp--backdrop-blur" lang="ro" data-cmp hidden>/', $ba_html ), 'render: legacy Pro banner = today\'s classes + the default (rule-less) ones' );
cmp_assert( 1 === preg_match( '/class="cmp [^"]*cmp--no-overlay"/', cmp_a11y_render( 'banner-bottom.php', true ) ), 'render: policy page keeps cmp--no-overlay' );
// Lot review I2: one signal for "does not block the page" = aria-modal="false" on the dialog,
// for the policy pages AND the backdrop "none" (banner.js / cmp.js blocking() read it).
cmp_assert( false !== strpos( $ba_html, 'aria-modal="true"' ), 'I2: default backdrop -> aria-modal="true" (blocking)' );
cmp_assert( false !== strpos( cmp_a11y_render( 'banner-bottom.php', true ), 'aria-modal="false"' ), 'I2: policy page -> aria-modal="false"' );
$ba_none = cmp_a11y_render( 'banner-bottom.php', false, array( 'appearance' => array( 'format' => 'card', 'position' => 'bottom-right', 'backdrop' => 'none' ) ) );
cmp_assert( false !== strpos( $ba_none, 'aria-modal="false"' ) && false === strpos( $ba_none, 'aria-modal="true"' ), 'I2: backdrop none -> aria-modal="false" (no lock, no trap)' );
cmp_assert( false === strpos( $ba_none, 'cmp--no-overlay' ) && false !== strpos( $ba_none, 'cmp--backdrop-none' ), 'I2: backdrop none keeps its position (no cmp--no-overlay, which pins to the bottom)' );
cmp_assert( false !== strpos( cmp_a11y_render( 'banner-bottom.php', false, array( 'appearance' => array( 'backdrop' => 'dim' ) ) ), 'aria-modal="true"' ), 'I2: backdrop dim -> aria-modal="true"' );
cmp_assert( false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/standalone/cmp.js' ), "aria-modal=\"' + (noOverlay || (cfg.appearance && cfg.appearance.backdrop === 'none') ? 'false' : 'true')" ), 'I2: standalone renderPro sets aria-modal="false" for backdrop none too' );
foreach ( array( 'assets/js/banner.js', 'standalone/cmp.js' ) as $ba_js ) {
	$ba_src = file_get_contents( dirname( __DIR__ ) . '/' . $ba_js );
	cmp_assert( 1 === preg_match( '/function blocking\(\) \{ return !!banner\.querySelector\(\'\[aria-modal="true"\]\'\); \}/', $ba_src ), "I2: $ba_js blocking() reads aria-modal (one signal)" );
}
$ba_light = cmp_a11y_render( 'banner-light.php' );
cmp_assert( 1 === preg_match( '/<div class="cmp-light cmp--neutral cmp--style-brand cmp--anim-none cmp--theme-light" lang="ro"/', $ba_light ), 'render: legacy Light card classes' );
$ba_rh = cmp_a11y_render( 'banner-bottom.php', false, array( 'appearance' => array( 'style' => 'minimal', 'format' => 'bar', 'position' => 'top', 'buttons' => 'text', 'texts' => array( 'ro' => array( 'title' => 'Titlu RO', 'reject' => 'Nu' ) ) ) ) );
cmp_assert( 1 === preg_match( '/<div id="cmp-overlay" class="cmp cmp--neutral cmp--style-minimal cmp--fmt-bar cmp--pos-top cmp--btn-text cmp--anim-fade cmp--theme-light cmp--backdrop-blur"/', $ba_rh ), 'render: new appearance -> its classes' );
cmp_assert( false !== strpos( $ba_rh, '>Titlu RO</h2>' ), 'render: per-language title' );
cmp_assert_eq( substr_count( $ba_rh, '>Nu</button>' ), 2, 'render: per-language Reject in both views' );
cmp_assert( false !== strpos( cmp_a11y_render( 'banner-light.php', false, array( 'appearance' => array( 'theme' => 'dark', 'texts' => array( 'ro' => array( 'light_intro' => 'Intro L' ) ) ) ) ), 'Intro L' ), 'render: Light per-language intro' );
cmp_assert( 1 === preg_match( '/<div class="cmp-light [^"]*\bcmp--dark\b/', cmp_a11y_render( 'banner-light.php', false, array( 'appearance' => array( 'theme' => 'dark' ) ) ) ), 'render: Light dark theme -> cmp--dark' );
