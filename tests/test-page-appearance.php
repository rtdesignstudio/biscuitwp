<?php
/**
 * B6 Task 4: the "Aspect" admin screen.
 *  - the Aspect save goes through CMP_Sanitizer with the stored settings ($old);
 *  - the Settings save keeps the appearance (and the moved legacy keys);
 *  - reopen_button and appearance.reopen stay in sync, both ways;
 *  - per-language texts clear the legacy texts (only without WPML / Polylang);
 *  - the custom CSS removed-list message;
 *  - Light disables the fields that do not apply;
 *  - guide anchor `appearance` and the contextual link;
 *  - JS/PHP accent contrast parity (fixture shared with standalone/tests/run.js).
 * Runs after test-guide.php (alphabetical), which defines the gettext stubs.
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! function_exists( '__' ) ) {
	function __( $t, $d = null ) {
		return $t;
	}
}
foreach ( array(
	'esc_html'     => 'return htmlspecialchars( (string) $t, ENT_QUOTES, "UTF-8" );',
	'esc_attr'     => 'return htmlspecialchars( (string) $t, ENT_QUOTES, "UTF-8" );',
	'esc_url'      => 'return htmlspecialchars( (string) $t, ENT_QUOTES, "UTF-8" );',
	'esc_textarea' => 'return htmlspecialchars( (string) $t, ENT_QUOTES, "UTF-8" );',
	'admin_url'    => 'return "http://example.test/wp-admin/" . $t;',
	'wp_kses_post' => 'return strip_tags( (string) $t, "<a><code><strong><em><br>" );',
	'do_action'    => 'return null;',
) as $pa_fn => $pa_body ) {
	if ( ! function_exists( $pa_fn ) ) {
		eval( "function $pa_fn( \$t = null ) { $pa_body }" );
	}
}
foreach ( array(
	'esc_html__' => 'return htmlspecialchars( __( $t, $d ), ENT_QUOTES, "UTF-8" );',
	'esc_attr__' => 'return htmlspecialchars( __( $t, $d ), ENT_QUOTES, "UTF-8" );',
	'esc_html_e' => 'echo htmlspecialchars( __( $t, $d ), ENT_QUOTES, "UTF-8" );',
	'esc_attr_e' => 'echo htmlspecialchars( __( $t, $d ), ENT_QUOTES, "UTF-8" );',
) as $pa_fn => $pa_body ) {
	if ( ! function_exists( $pa_fn ) ) {
		eval( "function $pa_fn( \$t, \$d = null ) { $pa_body }" );
	}
}
if ( ! function_exists( 'checked' ) ) {
	function checked( $a, $b = true, $echo = true ) {
		$r = (string) $a === (string) $b ? ' checked=\'checked\'' : '';
		if ( $echo ) {
			echo $r; // phpcs:ignore
		}
		return $r;
	}
}
if ( ! function_exists( 'selected' ) ) {
	function selected( $a, $b = true, $echo = true ) {
		$r = (string) $a === (string) $b ? ' selected=\'selected\'' : '';
		if ( $echo ) {
			echo $r; // phpcs:ignore
		}
		return $r;
	}
}
if ( ! function_exists( 'disabled' ) ) {
	function disabled( $a, $b = true, $echo = true ) {
		$r = (string) $a === (string) $b ? ' disabled=\'disabled\'' : '';
		if ( $echo ) {
			echo $r; // phpcs:ignore
		}
		return $r;
	}
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $a = -1 ) {
		echo '<input type="hidden" name="_wpnonce" value="x">';
	}
}
if ( ! function_exists( 'submit_button' ) ) {
	function submit_button( $t = '' ) {
		echo '<p class="submit"><input type="submit" class="button button-primary" value="' . htmlspecialchars( (string) $t ) . '"></p>';
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) {
		return true;
	}
}

require_once __DIR__ . '/../includes/class-sanitizer.php';
require_once __DIR__ . '/../includes/modules/class-lang.php';
require_once __DIR__ . '/../includes/admin/class-guide.php';
$pa_file = __DIR__ . '/../includes/admin/class-appearance-page.php';
cmp_assert( is_file( $pa_file ), 'aspect: class-appearance-page.php exists' );
if ( ! is_file( $pa_file ) ) {
	return;
}
require_once $pa_file;
cmp_assert( class_exists( 'CMP_Appearance_Page' ), 'aspect: CMP_Appearance_Page exists' );
cmp_assert_eq( CMP_Appearance_Page::SLUG, 'cmp-appearance', 'aspect: page slug cmp-appearance' );

// ---------------------------------------------------------------------------
// The Aspect save: CMP_Sanitizer::aspect( $posted, $old, $multilingual ).
// ---------------------------------------------------------------------------
cmp_assert( method_exists( 'CMP_Sanitizer', 'aspect' ), 'aspect: CMP_Sanitizer::aspect exists' );
$pa_old = array(
	'mode'           => 'pro',
	'position'       => 'bottom-left',
	'preset'         => 'warm',
	'block_scripts'  => true,
	'measurement'    => 'advanced',
	'policy_version' => '7',
	'reopen_button'  => true,
	'legal_meta'     => array( 'company' => 'ACME' ),
	'privacy_page_id' => 12,
	'texts'          => array( 'title' => 'Titlul vechi', 'accept' => 'Da' ),
	'appearance'     => array(
		'style'    => 'friendly',
		'palette'  => 'warm',
		'format'   => 'card',
		'position' => 'top-left',
		'backdrop' => 'dim',
		'radius'   => 7,
		'buttons'  => 'outline',
		'reopen'   => 'both',
		'exclude'  => array( '/x/' ),
	),
);
// Light posts no format/backdrop/buttons/reopen (disabled fields): they keep the stored values.
$pa_new = CMP_Sanitizer::aspect( array( 'appearance' => array( 'style' => 'friendly', 'palette' => 'egov', 'theme' => 'auto' ) ), $pa_old, false );
cmp_assert_eq( $pa_new['appearance']['palette'], 'egov', 'aspect save: posted palette stored' );
cmp_assert_eq( $pa_new['appearance']['theme'], 'auto', 'aspect save: posted theme stored' );
cmp_assert_eq( $pa_new['appearance']['format'], 'card', 'aspect save: unposted format keeps the stored value ($old passed)' );
cmp_assert_eq( $pa_new['appearance']['position'], 'top-left', 'aspect save: unposted position keeps the stored value' );
cmp_assert_eq( $pa_new['appearance']['backdrop'], 'dim', 'aspect save: unposted backdrop keeps the stored value' );
cmp_assert_eq( $pa_new['appearance']['radius'], 7, 'aspect save: unposted radius keeps the stored adjustment' );
cmp_assert_eq( $pa_new['appearance']['buttons'], 'outline', 'aspect save: unposted buttons keep the stored value' );
cmp_assert_eq( $pa_new['appearance']['exclude'], array( '/x/' ), 'aspect save: unposted exclusions kept' );
foreach ( array( 'mode', 'block_scripts', 'measurement', 'policy_version', 'legal_meta', 'privacy_page_id', 'position' ) as $pa_k ) {
	cmp_assert_eq( $pa_new[ $pa_k ], $pa_old[ $pa_k ], "aspect save: $pa_k (managed elsewhere) unchanged" );
}
cmp_assert_eq( $pa_new['preset'], 'egov', 'aspect save: legacy preset follows the palette (embed placeholders read it)' );
// Unknown keys and per-button styles never reach the stored appearance (B6.18).
$pa_bad = CMP_Sanitizer::aspect( array( 'appearance' => array( 'accept_style' => 'big', 'precheck' => 1, 'buttons' => 'huge' ) ), $pa_old, false );
cmp_assert( ! isset( $pa_bad['appearance']['accept_style'] ) && ! isset( $pa_bad['appearance']['precheck'] ), 'aspect save: unknown keys dropped (through CMP_Appearance::sanitize)' );
cmp_assert_eq( $pa_bad['appearance']['buttons'], 'outline', 'aspect save: unknown buttons value keeps the stored one' );
// A style switch reloads the style's values, posted ones win.
$pa_sw = CMP_Sanitizer::aspect( array( 'appearance' => array( 'style' => 'minimal' ) ), $pa_old, false );
cmp_assert_eq( $pa_sw['appearance']['radius'], 4, 'aspect save: style switch without posted radius -> the new style radius' );
// A legacy site (no appearance yet): the stored legacy keys are the base.
$pa_leg = CMP_Sanitizer::aspect( array( 'appearance' => array( 'theme' => 'dark' ) ), array( 'preset' => 'egov', 'position' => 'bottom-right' ), false );
cmp_assert_eq( array( $pa_leg['appearance']['palette'], $pa_leg['appearance']['format'], $pa_leg['appearance']['position'] ), array( 'egov', 'card', 'bottom-right' ), 'aspect save: first save on a legacy site starts from the migrated legacy keys' );
// Custom CSS is cleaned.
$pa_css = CMP_Sanitizer::aspect( array( 'appearance' => array( 'custom_css' => '.cmp__title{color:red} [data-cmp-action="reject"]{display:none} @import url(//evil.test/x.css);' ) ), $pa_old, false );
cmp_assert( false !== strpos( $pa_css['appearance']['custom_css'], '.cmp__title{color:red}' ), 'aspect save: harmless custom CSS kept' );
cmp_assert( false === strpos( $pa_css['appearance']['custom_css'], 'reject' ) && false === strpos( $pa_css['appearance']['custom_css'], '@import' ), 'aspect save: custom CSS cleaned' );
// Not an array -> stored settings unchanged.
$pa_none = CMP_Sanitizer::aspect( array(), $pa_old, false );
cmp_assert_eq( $pa_none['appearance']['style'], 'friendly', 'aspect save: nothing posted -> the stored appearance' );

// The Aspect save handler passes the stored settings.
$pa_src = (string) file_get_contents( $pa_file );
cmp_assert( 1 === preg_match( '/CMP_Sanitizer::aspect\(\s*\$raw,\s*\$old,/', $pa_src ), 'aspect: save() calls CMP_Sanitizer::aspect( $raw, $old, … )' );
cmp_assert( false !== strpos( $pa_src, "check_admin_referer( 'cmp_save_appearance' )" ) && false !== strpos( $pa_src, "current_user_can( 'manage_options' )" ), 'aspect: save() checks the nonce and manage_options' );
cmp_assert( false !== strpos( $pa_src, "wp_nonce_field( 'cmp_save_appearance' )" ), 'aspect: the form has the nonce' );

// ---------------------------------------------------------------------------
// Reopen: legacy reopen_button <-> appearance.reopen, both ways.
// ---------------------------------------------------------------------------
foreach ( array( 'icon' => true, 'text' => true, 'both' => true, 'none' => false ) as $pa_shape => $pa_on ) {
	$pa_r = CMP_Sanitizer::aspect( array( 'appearance' => array( 'reopen' => $pa_shape ) ), array_merge( $pa_old, array( 'reopen_button' => ! $pa_on ) ), false );
	cmp_assert_eq( $pa_r['reopen_button'], $pa_on, "reopen sync: Aspect '$pa_shape' -> reopen_button " . var_export( $pa_on, true ) );
	cmp_assert_eq( $pa_r['appearance']['reopen'], $pa_shape, "reopen sync: Aspect '$pa_shape' stored" );
}
$pa_r = CMP_Sanitizer::aspect( array( 'appearance' => array( 'palette' => 'dark' ) ), array_merge( $pa_old, array( 'reopen_button' => false ) ), false );
cmp_assert_eq( $pa_r['reopen_button'], false, 'reopen sync: reopen not posted (Light) -> reopen_button kept' );
// The other way: the form shows what the banner does (both must allow it).
$pa_fv = CMP_Appearance_Page::form_values( array_merge( $pa_old, array( 'reopen_button' => false ) ) );
cmp_assert_eq( $pa_fv['reopen'], 'none', 'reopen sync: reopen_button off -> the form shows "Deloc"' );
$pa_fv = CMP_Appearance_Page::form_values( $pa_old );
cmp_assert_eq( $pa_fv['reopen'], 'both', 'reopen sync: reopen_button on -> the stored shape' );
$pa_fv = CMP_Appearance_Page::form_values( array( 'reopen_button' => true ) );
cmp_assert_eq( $pa_fv['reopen'], 'icon', 'reopen sync: legacy site with the checkbox on -> Iconiță' );
$pa_fv = CMP_Appearance_Page::form_values( array() );
cmp_assert_eq( $pa_fv['reopen'], 'none', 'reopen sync: legacy site with the checkbox off -> Deloc' );

// ---------------------------------------------------------------------------
// Texts per language: legacy texts cleared only without WPML / Polylang.
// ---------------------------------------------------------------------------
$pa_t = CMP_Sanitizer::aspect(
	array( 'appearance' => array( 'texts' => array( 'ro' => array( 'title' => '', 'accept' => 'Accept <b>tot</b>' ), 'en' => array( 'title' => 'Cookies' ) ) ) ),
	$pa_old,
	false
);
cmp_assert_eq( $pa_t['appearance']['texts']['ro']['accept'], 'Accept tot', 'texts: per-language text stored (tags stripped)' );
cmp_assert_eq( $pa_t['appearance']['texts']['en']['title'], 'Cookies', 'texts: another language stored' );
cmp_assert_eq( $pa_t['appearance']['texts']['ro']['title'], '', 'texts: an emptied field stays empty' );
cmp_assert_eq( array_filter( $pa_t['texts'] ), array(), 'texts: legacy texts[key] cleared, so an emptied field falls back to the default' );
cmp_assert_eq( array_keys( $pa_t['texts'] ), CMP_Appearance::TEXT_KEYS, 'texts: legacy texts keep their keys (empty)' );
// With WPML / Polylang: the single texts stay the source (CMP_I18n), the per-language ones are untouched.
$pa_m = CMP_Sanitizer::aspect(
	array( 'texts' => array( 'title' => ' Titlu <i>nou</i> ', 'accept' => '' ), 'appearance' => array( 'palette' => 'warm' ) ),
	$pa_old,
	true
);
cmp_assert_eq( $pa_m['texts']['title'], 'Titlu nou', 'texts (WPML/Polylang): legacy texts saved from the form' );
cmp_assert_eq( $pa_m['texts']['accept'], '', 'texts (WPML/Polylang): an emptied legacy text stays empty' );
$pa_m2 = CMP_Sanitizer::aspect( array( 'appearance' => array( 'palette' => 'warm' ) ), $pa_old, true );
cmp_assert_eq( $pa_m2['texts'], $pa_old['texts'], 'texts (WPML/Polylang): texts not posted -> legacy texts kept' );
$pa_m3 = CMP_Sanitizer::aspect( array( 'texts' => array( 'title' => 'X' ) ), $pa_old, false );
cmp_assert_eq( array_filter( $pa_m3['texts'] ), array(), 'texts (no WPML): posted legacy texts ignored, legacy cleared' );
// Lot review I3: only the POSTED text keys are merged over the stored ones, so the fields
// disabled in the other mode (not posted) and the preview keep their texts.
$pa_full = array( 'title' => 'T', 'intro' => 'I', 'light_intro' => 'LI', 'accept' => 'A', 'reject' => 'R', 'prefs' => 'P', 'save' => 'S', 'prefs_title' => 'PT' );
$pa_i3   = array_merge( $pa_old, array( 'mode' => 'light', 'texts' => $pa_full ) );
$pa_l    = CMP_Sanitizer::aspect( array( 'texts' => array( 'light_intro' => 'LI2', 'accept' => 'A2' ), 'appearance' => array( 'palette' => 'warm' ) ), $pa_i3, true );
cmp_assert_eq( $pa_l['texts'], array_merge( $pa_full, array( 'light_intro' => 'LI2', 'accept' => 'A2' ) ), 'texts I3 (WPML/Polylang, Light save): the Pro texts not posted are kept' );
$pa_pr = CMP_Sanitizer::aspect( array( 'texts' => array( 'title' => 'T2', 'intro' => 'I2', 'accept' => 'A2', 'reject' => 'R2', 'prefs' => 'P2', 'save' => 'S2', 'prefs_title' => 'PT2' ) ), array_merge( $pa_i3, array( 'mode' => 'pro' ) ), true );
cmp_assert_eq( $pa_pr['texts']['light_intro'], 'LI', 'texts I3 (WPML/Polylang, Pro save): light_intro not posted is kept' );
cmp_assert_eq( $pa_pr['texts']['title'], 'T2', 'texts I3 (WPML/Polylang, Pro save): posted title stored' );
cmp_assert_eq( array_keys( $pa_pr['texts'] ), CMP_Appearance::TEXT_KEYS, 'texts I3: the legacy texts keep all their keys' );
$pa_pl = CMP_Sanitizer::aspect( array( 'texts' => array( 'evil' => 'x', 'title' => 'T3' ) ), $pa_i3, true );
cmp_assert( ! isset( $pa_pl['texts']['evil'] ), 'texts I3: unknown posted text keys are not stored' );
// Per-language texts: the same rule (a Light save posts only light_intro + accept for each language).
$pa_i3['appearance']['texts'] = array( 'ro' => $pa_full, 'en' => $pa_full );
$pa_pl = CMP_Sanitizer::aspect( array( 'appearance' => array( 'texts' => array( 'ro' => array( 'light_intro' => 'X', 'accept' => 'Y' ), 'en' => array( 'light_intro' => 'Z' ) ) ) ), $pa_i3, false );
cmp_assert_eq( $pa_pl['appearance']['texts']['ro'], array_merge( $pa_full, array( 'light_intro' => 'X', 'accept' => 'Y' ) ), 'texts I3 (per language, Light save): the Pro texts not posted are kept' );
cmp_assert_eq( $pa_pl['appearance']['texts']['en']['title'], 'T', 'texts I3 (per language): another language keeps its texts not posted' );
// First form display on a legacy site: the current language tab carries the old texts.
$pa_fv = CMP_Appearance_Page::form_values( array( 'texts' => array( 'title' => 'Vechi' ) ), 'ru' );
cmp_assert_eq( $pa_fv['texts']['ru']['title'], 'Vechi', 'texts: legacy text shown in the current language tab (migration)' );

// ---------------------------------------------------------------------------
// The Settings save keeps the appearance and the keys moved to Aspect.
// ---------------------------------------------------------------------------
$pa_set = CMP_Sanitizer::keep_appearance( CMP_Sanitizer::settings( array( 'mode' => 'light', 'block_scripts' => '1' ), $pa_old ), $pa_old );
cmp_assert_eq( $pa_set['appearance'], $pa_old['appearance'], 'settings save: the stored appearance is kept' );
cmp_assert_eq( $pa_set['mode'], 'light', 'settings save: its own fields saved' );
foreach ( array( 'position', 'preset', 'reopen_button', 'texts' ) as $pa_k ) {
	cmp_assert_eq( $pa_set[ $pa_k ], $pa_old[ $pa_k ], "settings save: moved key $pa_k kept from the stored settings" );
}
$pa_set2 = CMP_Sanitizer::keep_appearance( CMP_Sanitizer::settings( array() ), array() );
cmp_assert( ! isset( $pa_set2['appearance'] ), 'settings save: nothing stored -> no appearance invented' );
$pa_sp = (string) file_get_contents( __DIR__ . '/../includes/admin/class-settings-page.php' );
cmp_assert( 1 === preg_match( '/CMP_Sanitizer::keep_appearance\(.*CMP_Sanitizer::settings\(\s*\$raw,\s*\$old\s*\)/', $pa_sp ), 'settings save: keep_appearance( settings( $raw, $old ) )' );
foreach ( array( 'cmp[position]', 'cmp[preset]', 'cmp[reopen_button]', 'cmp[texts]' ) as $pa_field ) {
	cmp_assert( false === strpos( $pa_sp, $pa_field ), "settings page: $pa_field moved to Aspect" );
}
cmp_assert( false !== strpos( $pa_sp, 'cmp[mode]' ) && false !== strpos( $pa_sp, 'cmp[block_scripts]' ) && false !== strpos( $pa_sp, 'cmp[delete_data_on_uninstall]' ) && false !== strpos( $pa_sp, 'cmp_localize_fonts' ), 'settings page: keeps mode, blocking, uninstall, fonts' );
cmp_assert( false !== strpos( $pa_sp, "CMP_Appearance_Page::SLUG" ), 'settings page: points to the Aspect screen' );

// ---------------------------------------------------------------------------
// Custom CSS: the removed-list message.
// ---------------------------------------------------------------------------
cmp_assert_eq( CMP_Appearance_Page::removed_notice( array() ), '', 'css message: nothing removed -> no notice' );
$pa_rm = CMP_Appearance::clean_custom_css( '[data-cmp-action="reject"]{display:none} .cmp__btn{opacity:0} <script>x</script>' );
$pa_nt = CMP_Appearance_Page::removed_notice( $pa_rm['removed'] );
cmp_assert( false !== strpos( $pa_nt, 'notice-warning' ) && substr_count( $pa_nt, '<li>' ) === count( $pa_rm['removed'] ), 'css message: one list item per removed declaration/rule (' . count( $pa_rm['removed'] ) . ')' );
cmp_assert( false !== strpos( $pa_nt, '<code>[data-cmp-action=&quot;reject&quot;]{display:none}</code>' ), 'css message: the removed rule is shown, escaped' );
cmp_assert( false === strpos( $pa_nt, '<script' ), 'css message: no raw HTML from the CSS' );
$pa_many = CMP_Appearance_Page::removed_notice( array_fill( 0, 40, 'a{b:c}' ) );
cmp_assert( substr_count( $pa_many, '<li>' ) <= 21, 'css message: a long list is capped' );
cmp_assert( false !== strpos( $pa_src, 'CMP_Appearance::clean_custom_css(' ) && false !== strpos( $pa_src, 'set_transient( self::REMOVED_KEY' ), 'aspect: save() keeps the removed list for the next screen' );

// ---------------------------------------------------------------------------
// Script data: style defaults from the single source (CMP_Appearance::defaults).
// ---------------------------------------------------------------------------
$pa_sd = CMP_Appearance_Page::style_defaults();
foreach ( CMP_Appearance::STYLES as $pa_style ) {
	$pa_d = CMP_Appearance::defaults( $pa_style );
	$pa_want = array();
	foreach ( CMP_Appearance::STYLE_KEYS as $pa_k ) {
		$pa_want[ $pa_k ] = $pa_d[ $pa_k ];
	}
	cmp_assert_eq( $pa_sd[ $pa_style ], $pa_want, "script data: $pa_style defaults = CMP_Appearance::defaults" );
}
$pa_js = CMP_Appearance_Page::script_data();
cmp_assert_eq( $pa_js['bg'], CMP_Appearance::PALETTE_BG, 'script data: palette backgrounds for the contrast check' );
cmp_assert_eq( $pa_js['minRatio'], CMP_Appearance::MIN_RATIO, 'script data: the WCAG threshold' );
cmp_assert( isset( $pa_js['i18n']['confirmStyle'] ) && false !== strpos( $pa_js['i18n']['confirmStyle'], 'pierzi ajustările' ), 'script data: style switch warning text' );

// ---------------------------------------------------------------------------
// The form: Light disables what does not apply; texts tabs vs WPML note.
// ---------------------------------------------------------------------------
$pa_form = function ( $s, $ml ) {
	ob_start();
	CMP_Appearance_Page::render_form( $s, $ml );
	return (string) ob_get_clean();
};
// Lot review I4: the theme primary goes through the accent check; the screen says what happened.
$pa_tp  = json_decode( file_get_contents( __DIR__ . '/fixtures/theme-palettes.json' ), true );
$pa_tcs = array_merge( $pa_old, array( 'mode' => 'pro', 'appearance' => array_merge( $pa_old['appearance'], array( 'theme_colors' => true, 'theme' => 'light' ) ) ) );
$pa_tf  = function ( $s, $pal ) {
	ob_start();
	CMP_Appearance_Page::render_form( $s, false, $pal );
	return (string) ob_get_clean();
};
$pa_t3h = $pa_tf( $pa_tcs, $pa_tp['twentytwentythree'] );
cmp_assert( false !== strpos( $pa_t3h, 'cmp-ap-theme-warning' ) && false !== strpos( $pa_t3h, '#9dff20' ), 'form I4 (TT3): failing theme primary -> warning with the colour' );
cmp_assert( false === strpos( $pa_tf( $pa_tcs, $pa_tp['passing'] ), 'cmp-ap-theme-warning' ), 'form I4: passing theme primary -> no warning' );
cmp_assert( false !== strpos( $pa_tf( $pa_tcs, array() ), 'cmp-ap-theme-none' ), 'form I4: no known theme palette -> note (the palette is used)' );
cmp_assert( false === strpos( $pa_tf( $pa_old, $pa_tp['twentytwentythree'] ), 'cmp-ap-theme-' ), 'form I4: option off -> nothing about the theme' );
$pa_pro = $pa_form( array_merge( $pa_old, array( 'mode' => 'pro' ) ), false );
foreach ( array( 'style', 'palette', 'accent', 'theme', 'dark_selector', 'format', 'backdrop', 'dim', 'blur', 'radius', 'shadow', 'maxw', 'gap', 'density', 'buttons', 'anim', 'delay', 'reopen', 'exclude', 'custom_css', 'font', 'theme_colors' ) as $pa_k ) {
	cmp_assert( false !== strpos( $pa_pro, 'name="cmp[appearance][' . $pa_k . ']' ), "form: field $pa_k" );
}
cmp_assert( 1 === preg_match( '#<select name="cmp\[appearance\]\[density\]"[^>]*>\s*<option value="compact"[^>]*>Compact</option>\s*<option value="normal"[^>]*>Normal</option>\s*<option value="comfortable"[^>]*>Extins</option>\s*</select>#', $pa_pro ), 'form: density select (Compact / Normal / Extins)' );
cmp_assert( 1 === preg_match( '#<option value="normal" selected=\'selected\'>Normal</option>#', $pa_pro ), 'form: density normal selected by default' );
cmp_assert( false !== strpos( $pa_pro, 'name="cmp[appearance][texts][ru][title]"' ) && false !== strpos( $pa_pro, 'name="cmp[appearance][texts][en][accept]"' ), 'form: per-language texts (no WPML)' );
cmp_assert( 3 === substr_count( $pa_pro, 'role="tab"' ), 'form: three language tabs RO/RU/EN' );
cmp_assert( false === strpos( $pa_pro, 'name="cmp[texts]' ), 'form: no single texts without WPML/Polylang' );
cmp_assert( 1 === preg_match( '/name="cmp\[appearance\]\[format\]"(?![^>]*disabled)/', $pa_pro ), 'form (Pro): format enabled' );
cmp_assert( false !== strpos( $pa_pro, 'value="both"' ) && 1 === preg_match( '/value="both"[^>]*checked/', $pa_pro ), 'form: stored reopen shape checked' );
cmp_assert( false !== strpos( $pa_pro, 'Accept' ) && false !== strpos( $pa_pro, 'mereu la fel' ), 'form: buttons note (Accept and Refuz always identical)' );
cmp_assert( false !== strpos( $pa_pro, '--cmp-primary' ) && false !== strpos( $pa_pro, '--cmp-radius' ), 'form: documented --cmp-* variables' );
cmp_assert( false !== strpos( $pa_pro, "CMP_Guide::link" ) || false !== strpos( $pa_pro, 'page=cmp-guide#appearance' ), 'form: Ghid link to #appearance' );
cmp_assert( false !== strpos( $pa_pro, 'data-cmp-preview-slot' ), 'form: placeholder area for "Vezi pe site" (Task 6)' );
cmp_assert( false === strpos( $pa_pro, 'Vezi pe site' ), 'form: no "Vezi pe site" button yet' );
cmp_assert( false !== strpos( $pa_pro, 'data-cmp-accent-status' ) && false !== strpos( $pa_pro, 'data-cmp-accent-use' ), 'form: accent contrast status and "use the proposed shade"' );
cmp_assert( 1 === preg_match( '/name="cmp\[appearance\]\[texts\]\[ro\]\[light_intro\]"[^>]*disabled/', $pa_pro ), 'form (Pro): the Light text is disabled' );

$pa_light = $pa_form( array_merge( $pa_old, array( 'mode' => 'light' ) ), false );
foreach ( array( 'format', 'backdrop', 'dim', 'blur', 'maxw', 'buttons', 'reopen' ) as $pa_k ) {
	cmp_assert( 1 === preg_match( '/name="cmp\[appearance\]\[' . $pa_k . '\]"[^>]*disabled/', $pa_light ), "form (Light): $pa_k disabled" );
}
foreach ( array( 'palette', 'accent', 'theme', 'radius', 'shadow', 'gap', 'density', 'anim', 'delay', 'exclude', 'font' ) as $pa_k ) {
	cmp_assert( 1 === preg_match( '/name="cmp\[appearance\]\[' . $pa_k . '\]"(?![^>]*disabled)/', $pa_light ), "form (Light): $pa_k enabled" );
}
cmp_assert( 1 === preg_match( '/name="cmp\[appearance\]\[texts\]\[ro\]\[reject\]"[^>]*disabled/', $pa_light ), 'form (Light): button texts disabled' );
cmp_assert( 1 === preg_match( '/name="cmp\[appearance\]\[texts\]\[ro\]\[light_intro\]"(?![^>]*disabled)/', $pa_light ), 'form (Light): the Light text enabled' );
cmp_assert( false !== strpos( $pa_light, 'cmp-ap-light-note' ), 'form (Light): note on the disabled fields' );

$pa_ml = $pa_form( $pa_old, true );
cmp_assert( false !== strpos( $pa_ml, 'name="cmp[texts][title]"' ) && false !== strpos( $pa_ml, 'value="Titlul vechi"' ), 'form (WPML/Polylang): the single texts, as today' );
cmp_assert( false === strpos( $pa_ml, 'cmp[appearance][texts]' ) && false === strpos( $pa_ml, 'role="tab"' ), 'form (WPML/Polylang): no per-language tabs' );
cmp_assert( false !== strpos( $pa_ml, 'cmp-ap-ml-note' ), 'form (WPML/Polylang): note + link to the translation plugin' );

// Escaping: a stored value cannot break out of its attribute.
$pa_xss = $pa_form( array( 'appearance' => array( 'dark_selector' => '"><script>' ), 'texts' => array( 'title' => '"><script>' ) ), true );
cmp_assert( false === strpos( $pa_xss, '<script' ), 'form: stored values escaped' );

// ---------------------------------------------------------------------------
// Menu, assets, guide.
// ---------------------------------------------------------------------------
$pa_admin = (string) file_get_contents( __DIR__ . '/../includes/admin/class-admin.php' );
preg_match_all( '/add_submenu_page\((.*?)\);/s', $pa_admin, $pa_sub );
$pa_slugs = array();
foreach ( $pa_sub[1] as $pa_args ) {
	$pa_slugs[] = false !== strpos( $pa_args, 'CMP_Appearance_Page::SLUG' ) ? 'cmp-appearance' : ( preg_match( "/'manage_options',\s*([^,]+),/", $pa_args, $pa_mm ) ? trim( $pa_mm[1] ) : '' );
}
cmp_assert_eq( isset( $pa_slugs[1] ) ? $pa_slugs[1] : '', 'cmp-appearance', 'menu: Aspect right after Setări' );
cmp_assert( 1 === preg_match( "/__\( 'Aspect', 'consent-manager-pro' \).*'manage_options',\s*CMP_Appearance_Page::SLUG/s", $pa_admin ), 'menu: Aspect needs manage_options' );
cmp_assert( false !== strpos( $pa_admin, "admin_post_cmp_save_appearance" ), 'menu: save handler registered' );
cmp_assert( 1 === preg_match( "/CMP_Appearance_Page::SLUG\s*\)\s*\)\s*\{[^}]*admin-appearance\.js/s", $pa_admin ), 'assets: admin-appearance.js only on the Aspect screen' );
cmp_assert( false !== strpos( $pa_admin, "wp_localize_script( 'cmp-appearance', 'CMP_APPEARANCE', CMP_Appearance_Page::script_data() )" ), 'assets: style defaults passed via wp_localize_script' );
cmp_assert( in_array( 'appearance', CMP_Guide::ANCHORS, true ), 'guide: anchor appearance' );
cmp_assert( false !== strpos( $pa_src, "CMP_Guide::link( 'appearance' )" ), 'guide: the Aspect screen links to #appearance' );
$pa_guide = (string) file_get_contents( __DIR__ . '/../templates/admin/guide.php' );
cmp_assert( false !== strpos( $pa_guide, 'id="appearance"' ) && false !== strpos( $pa_guide, 'CMP_Guide::variables()' ), 'guide: appearance section lists the --cmp-* variables' );
$pa_vars = CMP_Guide::variables();
foreach ( array( '--cmp-primary', '--cmp-primary-text', '--cmp-bg', '--cmp-dark', '--cmp-radius', '--cmp-shadow', '--cmp-maxw', '--cmp-gap', '--cmp-dim', '--cmp-blur', '--cmp-font' ) as $pa_v ) {
	cmp_assert( isset( $pa_vars[ $pa_v ] ) && '' !== $pa_vars[ $pa_v ], "guide: variable $pa_v documented" );
}
$pa_css_src = (string) file_get_contents( __DIR__ . '/../assets/css/banner.css' );
foreach ( array_keys( $pa_vars ) as $pa_v ) {
	cmp_assert( false !== strpos( $pa_css_src, 'var(' . $pa_v ), "guide: documented $pa_v is used by banner.css" );
}

// ---------------------------------------------------------------------------
// Accent contrast parity: PHP side of the shared fixture (JS side in standalone/tests/run.js).
// ---------------------------------------------------------------------------
$pa_fx = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/accent-parity.json' ), true );
cmp_assert( is_array( $pa_fx ) && count( $pa_fx ) >= 30, 'parity: fixture loaded' );
$pa_bad_rows = array();
foreach ( (array) $pa_fx as $pa_row ) {
	$pa_got = CMP_Appearance::accent_suggestion( $pa_row['accent'], $pa_row['bg'], '' );
	$pa_exp = $pa_row['result'];
	if ( $pa_got['ok'] !== $pa_exp['ok'] || $pa_got['suggest'] !== $pa_exp['suggest'] || $pa_got['text'] !== $pa_exp['text']
		|| abs( $pa_got['ratio'] - $pa_exp['ratio'] ) > 1e-9 || abs( $pa_got['suggest_ratio'] - $pa_exp['suggest_ratio'] ) > 1e-9 ) {
		$pa_bad_rows[] = $pa_row['accent'] . '/' . $pa_row['bg'];
	}
}
cmp_assert( ! $pa_bad_rows, 'parity: CMP_Appearance::accent_suggestion matches the fixture' . ( $pa_bad_rows ? ' (' . implode( ', ', $pa_bad_rows ) . ')' : '' ) );
