<?php
// B6 Task 5 — compliance guarantees (spec B6.18), whatever the settings and the
// custom CSS: Accept and Reject share one class / style / size, category toggles
// are never pre-ticked, no consent by scroll or navigation, closing = refusing
// (there is no close control), Reject is visible. Enforced by the sanitizer,
// the custom-CSS cleaner and the JS guard (.cmp--guard, banner.css).

// 1. Sanitizer: no setting can style one button apart, nor pre-tick a category.
$g_def  = CMP_Appearance::defaults( 'brand' );
$g_keys = array_keys( $g_def );
$g_evil = array(
	'buttons'          => 'solid',
	'buttons_reject'   => 'text',
	'buttons_accept'   => 'solid',
	'reject_buttons'   => 'text',
	'reject_style'     => 'display:none',
	'accept_style'     => 'big',
	'accept_color'     => '#ff0000',
	'reject_color'     => '#ffffff',
	'reject_hidden'    => '1',
	'reject_size'      => '8',
	'button_order'     => 'accept-only',
	'preselect'        => '1',
	'preticked'        => array( 'statistics', 'marketing' ),
	'default_on'       => array( 'marketing' ),
	'categories'       => array( array( 'slug' => 'marketing', 'checked' => true ) ),
	'consent_on_scroll' => '1',
	'implied_consent'  => '1',
	'close_button'     => 'accept',
	'close'            => 'accept',
);
$g_out = CMP_Appearance::sanitize( $g_evil, $g_def );
cmp_assert_eq( array_keys( $g_out ), $g_keys, 'B6.18 sanitize: unknown keys dropped (no per-button style, preselect, scroll consent or close action)' );
cmp_assert_eq( $g_out['buttons'], 'solid', 'B6.18 sanitize: one button style for both' );
foreach ( array( array( 'reject' => 'text' ), array( 'accept' => 'solid' ), array( 'text', 'solid' ) ) as $g_i => $g_bad ) {
	cmp_assert_eq( CMP_Appearance::sanitize( array( 'buttons' => $g_bad ), CMP_Appearance::defaults( 'minimal' ) )['buttons'], 'outline', "B6.18 sanitize: buttons must be one scalar enum (case $g_i)" );
}
cmp_assert_eq( CMP_Appearance::sanitize( array( 'buttons' => 'ghost' ), $g_def )['buttons'], 'solid', 'B6.18 sanitize: unknown button style -> current' );
cmp_assert_eq( CMP_Appearance::sanitize( array( 'buttons' => 'solid; display:none' ), $g_def )['buttons'], 'solid', 'B6.18 sanitize: no CSS through the button style' );
// Through the settings save paths too (Settings and "Aspect").
$g_set = CMP_Sanitizer::settings( array( 'appearance' => $g_evil, 'preselect' => '1', 'default_on' => array( 'marketing' ), 'consent_on_scroll' => '1' ) );
cmp_assert( ! isset( $g_set['preselect'] ) && ! isset( $g_set['default_on'] ) && ! isset( $g_set['consent_on_scroll'] ), 'B6.18 settings: no preselect / default-on / scroll-consent key survives' );
cmp_assert_eq( array_keys( $g_set['appearance'] ), $g_keys, 'B6.18 settings: appearance keeps only known keys' );
$g_asp = CMP_Sanitizer::aspect( array( 'appearance' => $g_evil ), array(), false );
cmp_assert_eq( array_keys( $g_asp['appearance'] ), $g_keys, 'B6.18 aspect save: appearance keeps only known keys' );
cmp_assert( ! isset( $g_asp['appearance']['reject_style'] ) && ! isset( $g_asp['appearance']['preselect'] ), 'B6.18 aspect save: no per-button or preselect field' );

// Categories: any "default on" attempt is rejected (the stored shape has no such field).
$g_cats = CMP_Sanitizer::categories(
	array(
		array( 'slug' => 'necessary', 'label' => 'N', 'required' => '1' ),
		array( 'slug' => 'marketing', 'label' => 'M', 'default' => true, 'checked' => '1', 'default_on' => 1, 'enabled' => true, 'preselected' => 'yes', 'on' => true, 'value' => 'on' ),
	)
);
foreach ( $g_cats as $g_c ) {
	cmp_assert_eq( array_keys( $g_c ), array( 'slug', 'label', 'description', 'required', 'gtag_signals', 'cookies' ), 'B6.18 categories: ' . $g_c['slug'] . ' has no default-on field' );
}
cmp_assert_eq( $g_cats[1]['required'], false, 'B6.18 categories: "default on" does not turn into required' );

// 2. Every style x button style: one cmp--btn-* class on the banner, and
// Reject / Accept (and Reject / Save) with the very same class attribute.
foreach ( CMP_Appearance::STYLES as $g_style ) {
	foreach ( CMP_Appearance::BUTTONS as $g_btn ) {
		$g_n   = "B6.18 $g_style x $g_btn";
		$g_a   = CMP_Appearance::sanitize( array( 'style' => $g_style, 'buttons' => $g_btn ), CMP_Appearance::defaults( $g_style ) );
		$g_cls = CMP_Appearance::container_classes( $g_a, 'pro' );
		cmp_assert_eq( array_values( preg_grep( '/^cmp--btn-/', $g_cls ) ), array( 'cmp--btn-' . $g_btn ), "$g_n: exactly one button-style class on the banner" );
		$g_html = cmp_a11y_render( 'banner-bottom.php', false, array( 'appearance' => array( 'style' => $g_style, 'buttons' => $g_btn ) ) );
		cmp_assert( 1 === preg_match( '/<div id="cmp-overlay" class="[^"]*\bcmp--btn-' . $g_btn . '\b/', $g_html ), "$g_n: rendered banner carries cmp--btn-$g_btn" );
		$g_rej = cmp_button_classes( $g_html, 'reject' );
		$g_acc = cmp_button_classes( $g_html, 'accept' );
		$g_sav = cmp_button_classes( $g_html, 'save' );
		cmp_assert( 2 === count( $g_rej ) && 1 === count( $g_acc ) && $g_rej[0] === $g_acc[0] && $g_rej[1] === $g_sav[0], "$g_n: Reject and Accept / Save have the same class" );
		cmp_assert( 0 === preg_match( '/<input[^>]*data-cmp-cat[^>]*\bchecked/', $g_html ), "$g_n: no category toggle rendered checked" );
		cmp_assert( 0 === preg_match( '/data-cmp-action="(close|dismiss)"|cmp__close/', $g_html ), "$g_n: no close (X) control" );
		cmp_assert( false === strpos( CMP_Appearance::inline_css( $g_a ), 'data-cmp-action' ), "$g_n: inline CSS never targets one button" );
	}
}

// 3. Custom CSS aimed at one button (or hiding / shrinking the choices) is removed.
$g_attacks = array(
	'[data-cmp-action="reject"]{display:none}',
	'[data-cmp-action=reject]{opacity:0}',
	".cmp [data-cmp-action='accept']{font-size:30px;padding:30px}",
	'.cmp__choices .cmp__btn:first-child{visibility:hidden}',
	'.cmp__choices{display:block} .cmp__btn{width:10px}',
	'.cmp__btn{color:transparent}',
);
foreach ( $g_attacks as $g_css ) {
	$g_c = CMP_Appearance::clean_custom_css( $g_css );
	cmp_assert( 0 === preg_match( '/display\s*:\s*none|opacity\s*:\s*0|visibility\s*:\s*hidden|font-size|width\s*:\s*10px|transparent|data-cmp-action/', $g_c['css'] ) && ! empty( $g_c['removed'] ), 'B6.18 custom CSS: removed and reported: ' . $g_css );
}
cmp_assert_eq( CMP_Appearance::sanitize( array( 'custom_css' => '[data-cmp-action="reject"]{display:none}' ), $g_def )['custom_css'], '', 'B6.18 sanitize: targeted custom CSS never stored' );

// 4. Templates: no close control, Escape-free markup, toggles never checked.
foreach ( array( 'banner-bottom.php', 'banner-light.php' ) as $g_tpl ) {
	$g_src = (string) file_get_contents( __DIR__ . '/../templates/' . $g_tpl );
	cmp_assert( 0 === preg_match( '/\bchecked\b/', preg_replace( '/<\?php.*?\?>/s', '', $g_src ) ) && false === strpos( $g_src, 'checked(' ), "B6.18 $g_tpl: no checked attribute / checked() helper" );
	cmp_assert( 0 === preg_match( '/data-cmp-action="(close|dismiss)"|cmp__close|&times;|×/', $g_src ), "B6.18 $g_tpl: no close (X) control" );
}

// 5. The JS guard's reset (fix I2): inline !important on the banner, the dialog,
// the choices and both buttons (beats id / @layer / !important stylesheet rules);
// same for both decisions; no stylesheet rule for it (budget) and none singling
// out one decision.
foreach ( array( 'assets/js/banner.js', 'standalone/cmp.js' ) as $g_f ) {
	$g_js = (string) file_get_contents( __DIR__ . '/../' . $g_f );
	cmp_assert( 1 === preg_match( "/var GB = GV \+ '([^']*)'/", $g_js, $g_m ), "B6.18 $g_f: one inline reset string for both buttons" );
	$g_rule = isset( $g_m[1] ) ? $g_m[1] : '';
	cmp_assert( 1 === preg_match( "/var GV = '([^']*)'/", $g_js, $g_v ) && 'visibility:visible;opacity:1;transform:none;clip-path:none;' === $g_v[1], "B6.18 $g_f: shared reset keeps visible, opaque, untransformed, unclipped" );
	foreach ( array( 'display:block;', 'position:static;', 'width:100%;', 'min-height:44px;', 'font-size:14px;', 'color:#fff;', 'background:#111827;', 'pointer-events:auto;' ) as $g_p ) {
		cmp_assert( 1 === preg_match( '/' . $g_p . '/', $g_rule ), "B6.18 $g_f: guard reset has $g_p" );
	}
	// Lot review I4: the reset re-applies the safe default (Neutral) colours, never the
	// --cmp-primary* values that may be the failing ones; the pair passes 4.5:1.
	cmp_assert( false === strpos( $g_rule, 'var(--cmp-primary' ), "B6.18 $g_f: guard reset does not reuse the (possibly failing) --cmp-primary colours" );
	cmp_assert( CMP_Contrast::ratio( '#ffffff', '#111827' ) >= 4.5, "B6.18 $g_f: guard reset colours pass 4.5:1" );
	cmp_assert( false !== strpos( $g_js, "e.style.cssText += ';' + css.replace(/;/g, '!important;');" ), "B6.18 $g_f: every reset declaration is inline !important" );
	cmp_assert( false !== strpos( $g_js, "c(root, 'display:flex;' + GV);" ) && false !== strpos( $g_js, "c(root.querySelector('.cmp__modal'), GV);" ), "B6.18 $g_f: the banner and the dialog kept on screen" );
	cmp_assert( false !== strpos( $g_js, "root.querySelectorAll('.cmp__btn--primary'), function (b) { c(b, GB); c(b.parentNode, GV + 'display:grid;grid-template-columns:1fr 1fr;" ), "B6.18 $g_f: both buttons and their group reset together" );
	cmp_assert( false === strpos( $g_rule, 'data-cmp-action' ), "B6.18 $g_f: guard never styles one decision apart" );
}
foreach ( array( 'assets/css/banner.css', 'standalone/cmp.css' ) as $g_f ) {
	$g_css = (string) file_get_contents( __DIR__ . '/../' . $g_f );
	cmp_assert( false === strpos( $g_css, 'cmp--guard' ), "B6.18 $g_f: no stylesheet guard rule (the reset is inline, it outweighs any stylesheet)" );
}
