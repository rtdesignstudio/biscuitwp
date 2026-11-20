<?php
/**
 * B6 Task 1: CMP_Appearance, the pure part: settings shape, lazy migration
 * from the old keys, container classes, the inline variable block (delta
 * only), the accent suggestion and the custom-CSS cleaner.
 */
require_once __DIR__ . '/../includes/class-contrast.php';
require_once __DIR__ . '/../includes/class-appearance.php';
require_once __DIR__ . '/../includes/class-sanitizer.php';

// ---- Style defaults (spec table) ----
$ap_min = CMP_Appearance::defaults( 'minimal' );
$ap_brd = CMP_Appearance::defaults( 'brand' );
$ap_fr  = CMP_Appearance::defaults( 'friendly' );
cmp_assert_eq( array( $ap_min['radius'], $ap_min['shadow'], $ap_min['buttons'], $ap_min['anim'], $ap_min['maxw'] ), array( 4, 'none', 'outline', 'fade', 640 ), 'appearance: Minimal defaults' );
cmp_assert_eq( array( $ap_brd['radius'], $ap_brd['shadow'], $ap_brd['buttons'], $ap_brd['anim'], $ap_brd['maxw'] ), array( 12, 'medium', 'solid', 'none', 720 ), 'appearance: Brand defaults = today' );
cmp_assert_eq( array( $ap_fr['radius'], $ap_fr['shadow'], $ap_fr['buttons'], $ap_fr['anim'], $ap_fr['maxw'] ), array( 20, 'large', 'solid', 'slide', 680 ), 'appearance: Friendly defaults' );
cmp_assert_eq( CMP_Appearance::defaults( 'nope' ), $ap_brd, 'appearance: unknown style -> Brand' );
cmp_assert_eq( array( $ap_brd['gap'], $ap_brd['backdrop'], $ap_brd['blur'], $ap_brd['dim'], $ap_brd['theme'], $ap_brd['font'] ), array( 24, 'blur', 5, null, 'light', 'system' ), 'appearance: Brand keeps today\'s gap, blur, palette scrim, light theme and system font' );

// ---- Lazy migration: every legacy position ----
$ap_pos = array(
	'bottom'        => array( 'modal', 'bottom', 'bottom-center' ),
	'top'           => array( 'modal', 'top', 'top-center' ),
	'top-left'      => array( 'card', 'top-left', 'top-left' ),
	'top-right'     => array( 'card', 'top-right', 'top-right' ),
	'bottom-left'   => array( 'card', 'bottom-left', 'bottom-left' ),
	'bottom-right'  => array( 'card', 'bottom-right', 'bottom-right' ),
	'middle-center' => array( 'modal', 'center', 'middle-center' ),
	'modal-center'  => array( 'modal', 'center', 'middle-center' ),
	'top-center'    => array( 'modal', 'top', 'top-center' ),
	'bottom-center' => array( 'modal', 'bottom', 'bottom-center' ),
	'garbage'       => array( 'modal', 'center', 'middle-center' ),
);
foreach ( $ap_pos as $ap_old => $ap_exp ) {
	$ap_a = CMP_Appearance::from_settings( array( 'position' => $ap_old, 'preset' => 'neutral' ), 'ro' );
	cmp_assert_eq( array( $ap_a['format'], $ap_a['position'] ), array( $ap_exp[0], $ap_exp[1] ), "appearance: legacy position {$ap_old} -> {$ap_exp[0]}/{$ap_exp[1]}" );
	cmp_assert( in_array( 'cmp--pos-' . $ap_exp[2], CMP_Appearance::container_classes( $ap_a, 'pro' ), true ), "appearance: legacy position {$ap_old} keeps a today's pos class (cmp--pos-{$ap_exp[2]})" );
	cmp_assert_eq( CMP_Appearance::inline_css( $ap_a ), '', "appearance: legacy position {$ap_old} adds no inline variables" );
}
foreach ( array_keys( $ap_pos ) as $ap_old ) {
	cmp_assert( ! in_array( 'cmp--fmt-bar', CMP_Appearance::container_classes( CMP_Appearance::from_settings( array( 'position' => $ap_old ), 'ro' ), 'pro' ), true ), "appearance C1: legacy {$ap_old} never becomes the new bar" );
}
$ap_inst = CMP_Appearance::container_classes( CMP_Appearance::from_settings( array( 'position' => 'bottom', 'preset' => 'neutral' ), 'ro' ), 'pro' );
cmp_assert( ! in_array( 'cmp--fmt-bar', $ap_inst, true ) && in_array( 'cmp--pos-bottom-center', $ap_inst, true ), 'appearance C1: installer default bottom -> centred window at the bottom, no cmp--fmt-bar' );
cmp_assert_eq( CMP_Appearance::from_settings( array(), 'ro' )['format'], 'modal', 'appearance: no position at all -> centre window (template default)' );

// Review focus 1: egov + bottom-right + reopen = identical, nothing saved.
$ap_old_site = array( 'preset' => 'egov', 'position' => 'bottom-right', 'reopen_button' => true, 'texts' => array( 'title' => 'Cookie-uri', 'accept' => 'Da' ) );
$ap_m = CMP_Appearance::from_settings( $ap_old_site, 'ru' );
cmp_assert_eq( array( $ap_m['style'], $ap_m['palette'], $ap_m['reopen'] ), array( 'brand', 'egov', 'icon' ), 'appearance: migration -> Brand, palette from preset, reopen icon' );
cmp_assert_eq( CMP_Appearance::inline_css( $ap_m ), '', 'appearance: migrated egov site has an empty inline block' );
cmp_assert_eq( CMP_Appearance::container_classes( $ap_m, 'pro' ), array( 'cmp--egov', 'cmp--style-brand', 'cmp--fmt-card', 'cmp--pos-bottom-right', 'cmp--btn-solid', 'cmp--anim-none', 'cmp--theme-light', 'cmp--backdrop-blur' ), 'appearance: migrated egov site classes' );
cmp_assert_eq( $ap_m['texts']['ru']['title'], 'Cookie-uri', 'appearance: old texts[key] -> the current language' );
cmp_assert_eq( $ap_m['texts']['ro']['title'], '', 'appearance: other languages stay empty' );
cmp_assert_eq( CMP_Appearance::from_settings( array( 'reopen_button' => false ), 'ro' )['reopen'], 'none', 'appearance: reopen off -> none' );
cmp_assert_eq( CMP_Appearance::from_settings( array( 'preset' => 'hacker' ), 'ro' )['palette'], 'neutral', 'appearance: unknown preset -> neutral' );
cmp_assert_eq( CMP_Appearance::from_settings( array( 'texts' => array( 'title' => 'X' ) ), 'xx' )['texts']['ro']['title'], 'X', 'appearance: unknown language -> ro' );

// Stored appearance wins over the old keys.
$ap_st = CMP_Appearance::from_settings( array( 'preset' => 'egov', 'position' => 'bottom', 'appearance' => array( 'style' => 'friendly', 'palette' => 'warm' ) ), 'ro' );
cmp_assert_eq( array( $ap_st['style'], $ap_st['palette'], $ap_st['radius'], $ap_st['format'] ), array( 'friendly', 'warm', 20, 'modal' ), 'appearance: stored appearance wins; missing keys come from its style' );

// ---- Brand + every palette of today = empty block ----
foreach ( array( 'neutral', 'warm', 'dark', 'egov' ) as $ap_p ) {
	cmp_assert_eq( CMP_Appearance::inline_css( CMP_Appearance::from_settings( array( 'preset' => $ap_p ), 'ro' ) ), '', "appearance: Brand + {$ap_p} -> empty inline block" );
}
cmp_assert_eq( CMP_Appearance::inline_css( CMP_Appearance::defaults( 'brand' ) ), '', 'appearance: Brand defaults -> empty inline block' );

// Brand's defaults equal the values in today's banner.css.
$ap_css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/banner.css' );
$ap_tok = CMP_Contrast::tokens( $ap_css, 'neutral' );
cmp_assert_eq( $ap_tok['radius'], $ap_brd['radius'] . 'px', 'appearance: Brand radius = banner.css --cmp-radius' );
// B6 Task 2: the values are the fallbacks of the variables (today's look when the inline block is empty).
// Fix wave budget reclaim: fallback literals are written short (no space after the comma, .25 for 0.25).
cmp_assert( false !== strpos( $ap_css, 'max-width: var(--cmp-maxw,' . $ap_brd['maxw'] . 'px)' ), 'appearance: Brand max width = banner.css modal max-width' );
cmp_assert( false !== strpos( $ap_css, 'padding: var(--cmp-gap,' . $ap_brd['gap'] . 'px);' ), 'appearance: Brand gap = banner.css overlay padding' );
cmp_assert( false !== strpos( $ap_css, 'blur(var(--cmp-blur,' . $ap_brd['blur'] . 'px))' ), 'appearance: Brand blur = banner.css backdrop blur' );
cmp_assert( false !== strpos( $ap_css, 'box-shadow: var(--cmp-shadow,' . CMP_Appearance::SHADOWS['medium'] . ')' ), 'appearance: medium shadow = banner.css modal shadow' );
foreach ( CMP_Appearance::PALETTE_DIM as $ap_p => $ap_dim ) {
	$ap_pt = CMP_Contrast::tokens( $ap_css, $ap_p );
	$ap_alpha = preg_match( '/,\s*([\d.]+)\s*\)$/', $ap_pt['scrim'], $ap_am ) ? (int) round( 100 * (float) $ap_am[1] ) : -1;
	cmp_assert_eq( $ap_dim, $ap_alpha, "appearance: PALETTE_DIM[{$ap_p}] = banner.css scrim alpha ({$ap_pt['scrim']})" );
	$ap_bg = preg_replace( '/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i', '#$1$1$2$2$3$3', $ap_pt['bg'] ); // #fff = #ffffff (short spelling in banner.css)
	cmp_assert_eq( CMP_Appearance::PALETTE_BG[ $ap_p ], $ap_bg, "appearance: PALETTE_BG[{$ap_p}] = banner.css --cmp-bg" );
}

// ---- Variable map (one place; Task 2 uses it) ----
foreach ( array( 'radius', 'shadow', 'maxw', 'gap', 'dim', 'blur', 'font', 'primary', 'primary_hover', 'primary_text' ) as $ap_k ) {
	cmp_assert( isset( CMP_Appearance::VARS[ $ap_k ] ) && 0 === strpos( CMP_Appearance::VARS[ $ap_k ], '--cmp-' ), "appearance: VARS has {$ap_k}" );
}

// ---- Delta block ----
$ap_mi = CMP_Appearance::inline_css( CMP_Appearance::defaults( 'minimal' ) );
cmp_assert( false !== strpos( $ap_mi, '--cmp-radius:4px' ) && false !== strpos( $ap_mi, '--cmp-maxw:640px' ) && false !== strpos( $ap_mi, '--cmp-shadow:none' ), 'appearance: Minimal block carries radius, width, shadow' );
cmp_assert( false === strpos( $ap_mi, '--cmp-gap' ) && false === strpos( $ap_mi, '--cmp-blur' ), 'appearance: unchanged values are left out' );
cmp_assert( 0 === strpos( $ap_mi, '.cmp,.cmp-light,.cmp-reopen,.cmp-embed{' ), 'appearance: block targets .cmp, .cmp-light, .cmp-reopen, .cmp-embed' );
$ap_d = CMP_Appearance::defaults( 'brand' );
$ap_d['dim'] = 55;
cmp_assert_eq( CMP_Appearance::inline_css( $ap_d ), '', 'appearance: dim equal to the neutral palette scrim -> no variable' );
$ap_d['dim'] = 30;
cmp_assert( false !== strpos( CMP_Appearance::inline_css( $ap_d ), '--cmp-dim:.3' ), 'appearance: dim 30% -> --cmp-dim:.3' );
$ap_d = CMP_Appearance::defaults( 'brand' );
$ap_d['font'] = 'inherit';
// Lot review I1: `--cmp-font:inherit` is the CSS-wide keyword (the variable inherits its own,
// undefined, value, so var() takes the system stack): a real font-family declaration instead.
cmp_assert( false !== strpos( CMP_Appearance::inline_css( $ap_d ), 'font-family:inherit' ), 'appearance I1: theme font -> a real font-family:inherit declaration' );
cmp_assert( false === strpos( CMP_Appearance::inline_css( $ap_d ), '--cmp-font' ), 'appearance I1: theme font -> no --cmp-font:inherit (a no-op)' );
cmp_assert_eq( CMP_Appearance::inline_css( $ap_d ), '.cmp,.cmp-light,.cmp-reopen,.cmp-embed{font-family:inherit}', 'appearance I1: theme font alone -> the scope rule with only font-family:inherit' );

// Accent: base override, and .cmp--dark only for dark/auto/site.
$ap_ac = CMP_Appearance::defaults( 'brand' );
$ap_ac['accent'] = '#0a7d33';
$ap_acc = CMP_Appearance::inline_css( $ap_ac );
cmp_assert( false !== strpos( $ap_acc, '--cmp-primary:#0a7d33' ) && false !== strpos( $ap_acc, '--cmp-primary-text:#ffffff' ) && false !== strpos( $ap_acc, '--cmp-primary-hover:#' ), 'appearance: accent overrides primary, hover and text' );
cmp_assert( false === strpos( $ap_acc, '.cmp--dark' ), 'appearance: light theme -> no dark accent block' );
foreach ( array( 'dark', 'auto', 'site' ) as $ap_th ) {
	$ap_ac['theme'] = $ap_th;
	$ap_x = CMP_Appearance::inline_css( $ap_ac );
	cmp_assert( false !== strpos( $ap_x, '.cmp--dark{--cmp-primary:#' ), "appearance: theme {$ap_th} -> .cmp--dark accent block" );
	cmp_assert_eq( false !== strpos( $ap_x, '@media (prefers-color-scheme:dark){.cmp--theme-auto{' ), 'auto' === $ap_th, "appearance: theme {$ap_th} media block only for auto" );
	if ( preg_match( '/\.cmp--dark\{--cmp-primary:(#[0-9a-f]{6});--cmp-primary-hover:#[0-9a-f]{6};--cmp-primary-text:(#[0-9a-f]{6})/', $ap_x, $ap_dm ) ) {
		cmp_assert( CMP_Contrast::ratio( $ap_dm[1], CMP_Appearance::PALETTE_BG['dark'] ) >= 4.5 && CMP_Contrast::ratio( $ap_dm[1], $ap_dm[2] ) >= 4.5, "appearance: theme {$ap_th} dark accent passes 4.5:1 on the dark background" );
	} else {
		cmp_assert( false, "appearance: theme {$ap_th} dark accent block parsable" );
	}
}
$ap_ac['theme'] = 'light';
$ap_ac['palette'] = 'dark';
cmp_assert( false !== strpos( CMP_Appearance::inline_css( $ap_ac ), '.cmp--dark{' ), 'appearance: Dark palette + accent -> dark accent block' );

// Typical case: Friendly + accent + auto <= 1 KB.
$ap_typ = CMP_Appearance::defaults( 'friendly' );
$ap_typ['accent'] = '#e4572e';
$ap_typ['theme']  = 'auto';
$ap_typ_css = CMP_Appearance::inline_css( $ap_typ );
cmp_assert( strlen( $ap_typ_css ) > 0 && strlen( $ap_typ_css ) <= 1024, 'appearance: typical inline block <= 1024 bytes (' . strlen( $ap_typ_css ) . ')' );
$ap_big = CMP_Appearance::sanitize( array( 'style' => 'minimal', 'accent' => '#123456', 'theme' => 'site', 'dim' => 10, 'blur' => 9, 'gap' => 3, 'font' => 'inherit', 'theme_colors' => '1' ), CMP_Appearance::defaults( 'brand' ) );
cmp_assert( strlen( CMP_Appearance::inline_css( $ap_big ) ) <= 1024, 'appearance: everything changed still <= 1024 bytes (' . strlen( CMP_Appearance::inline_css( $ap_big ) ) . ')' );
cmp_assert( false === strpos( CMP_Appearance::inline_css( $ap_big ), '</' ), 'appearance: inline block never closes the style tag' );
// Lot review I4: "Preia din temă" reads the theme.json palette (resolved server side, passed
// in), by slug fallbacks, emits concrete checked hex values (never an unchecked var()).
$ap_tc  = CMP_Appearance::defaults( 'brand' );
$ap_tc['theme_colors'] = true;
$ap_tp  = json_decode( file_get_contents( __DIR__ . '/fixtures/theme-palettes.json' ), true );
cmp_assert_eq( CMP_Appearance::inline_css( $ap_tc ), '', 'appearance I4: theme colours without a theme palette -> nothing (the chosen palette)' );
cmp_assert( false === strpos( CMP_Appearance::inline_css( $ap_tc, $ap_tp['twentytwentythree'] ), 'var(' ), 'appearance I4: theme colours -> concrete values, no var()' );
// TT3: primary #9DFF20 fails (white or black text, and on the white background): the palette's primary stays.
$ap_t3 = CMP_Appearance::theme_colors( $ap_tp['twentytwentythree'], 'neutral' );
cmp_assert_eq( array( $ap_t3['found'], $ap_t3['primary'], $ap_t3['primary_ok'] ), array( true, '#9dff20', false ), 'appearance I4 TT3: primary #9dff20 found and refused (< 4.5:1)' );
cmp_assert( $ap_t3['ratio'] < 4.5 && '' !== $ap_t3['suggest'] && $ap_t3['suggest_ratio'] >= 4.5, 'appearance I4 TT3: ratio and a passing suggestion for the screen' );
$ap_t3c = CMP_Appearance::inline_css( $ap_tc, $ap_tp['twentytwentythree'] );
cmp_assert( false === strpos( $ap_t3c, '--cmp-primary' ), 'appearance I4 TT3: failing primary not emitted (palette primary + its text kept)' );
cmp_assert( false !== strpos( $ap_t3c, '--cmp-dark:#000000' ), 'appearance I4 TT3: text = contrast (#000000)' );
cmp_assert( false === strpos( $ap_t3c, '--cmp-bg' ), 'appearance I4 TT3: background = base #ffffff = the palette\'s (not repeated)' );
// TT4: base / contrast / accent.
$ap_t4 = CMP_Appearance::theme_colors( $ap_tp['twentytwentyfour'], 'neutral' );
cmp_assert_eq( array( $ap_t4['vars']['bg'], $ap_t4['vars']['text'], $ap_t4['primary'], $ap_t4['primary_ok'] ), array( '#f9f9f9', '#111111', '#cfcabe', false ), 'appearance I4 TT4: base/contrast read, accent #cfcabe refused' );
// TT5: base / contrast / accent-1 (non-hex accent-6 ignored).
$ap_t5 = CMP_Appearance::theme_colors( $ap_tp['twentytwentyfive'], 'warm' );
cmp_assert_eq( array( $ap_t5['vars']['bg'], $ap_t5['vars']['text'], $ap_t5['primary'], $ap_t5['primary_ok'] ), array( '#ffffff', '#111111', '#ffee58', false ), 'appearance I4 TT5: base/contrast read, accent-1 #ffee58 refused' );
// A passing primary: emitted with its own auto text colour and hover.
$ap_tg = CMP_Appearance::inline_css( $ap_tc, $ap_tp['passing'] );
cmp_assert( false !== strpos( $ap_tg, '--cmp-primary:#0a5c36' ) && false !== strpos( $ap_tg, '--cmp-primary-text:#ffffff' ) && false !== strpos( $ap_tg, '--cmp-primary-hover:#' ), 'appearance I4: passing primary -> primary, hover and auto text' );
cmp_assert( false !== strpos( $ap_tg, '--cmp-bg:#fffdf8' ) && false !== strpos( $ap_tg, '--cmp-dark:#222222' ), 'appearance I4: background / foreground slugs' );
// A dark theme background on the light banner: bg/text refused (muted/panel/border stay light).
$ap_td = CMP_Appearance::theme_colors( $ap_tp['dark-background'], 'neutral' );
cmp_assert( ! isset( $ap_td['vars']['bg'] ) && ! isset( $ap_td['vars']['text'] ), 'appearance I4: dark theme background refused on the light banner' );
// The B5 guarantee holds on every fixture: the emitted (or palette) primary passes 4.5:1
// with its text and on the emitted (or palette) background; text on background too.
foreach ( $ap_tp as $ap_tn => $ap_pal ) {
	if ( '_comment' === $ap_tn ) {
		continue;
	}
	foreach ( CMP_Appearance::PALETTES as $ap_pn ) {
		if ( 'dark' === $ap_pn ) {
			continue;
		}
		$ap_r = CMP_Appearance::theme_colors( $ap_pal, $ap_pn );
		$ap_e = array_merge( array( 'bg' => CMP_Appearance::PALETTE_BG[ $ap_pn ], 'text' => CMP_Appearance::PALETTE_TEXT[ $ap_pn ], 'primary' => CMP_Appearance::PALETTE_PRIMARY[ $ap_pn ], 'primary_text' => '#ffffff' ), $ap_r['vars'] );
		cmp_assert( CMP_Contrast::ratio( $ap_e['primary'], $ap_e['primary_text'] ) >= 4.5 && CMP_Contrast::ratio( $ap_e['primary'], $ap_e['bg'] ) >= 4.5 && CMP_Contrast::ratio( $ap_e['text'], $ap_e['bg'] ) >= 4.5, "appearance I4: {$ap_tn} on {$ap_pn} keeps every pair >= 4.5:1" );
	}
}

// ---- Ranges and guarantees ----
$ap_cur = CMP_Appearance::defaults( 'brand' );
$ap_s = CMP_Appearance::sanitize( array( 'radius' => 99, 'maxw' => 100, 'gap' => -5, 'dim' => 95, 'blur' => 50, 'delay' => 7 ), $ap_cur );
cmp_assert_eq( array( $ap_s['radius'], $ap_s['maxw'], $ap_s['gap'], $ap_s['dim'], $ap_s['blur'], $ap_s['delay'] ), array( 24, 360, 0, 80, 10, 2.0 ), 'appearance: values clamped to their ranges (high)' );
$ap_s = CMP_Appearance::sanitize( array( 'radius' => -1, 'maxw' => 5000, 'gap' => 99, 'dim' => -1, 'blur' => -3, 'delay' => -1 ), $ap_cur );
cmp_assert_eq( array( $ap_s['radius'], $ap_s['maxw'], $ap_s['gap'], $ap_s['dim'], $ap_s['blur'], $ap_s['delay'] ), array( 0, 960, 40, 0, 0, 0.0 ), 'appearance: values clamped to their ranges (low/other end)' );
cmp_assert_eq( CMP_Appearance::sanitize( array( 'delay' => '1.26' ), $ap_cur )['delay'], 1.3, 'appearance: delay rounded to 0.1 s' );
cmp_assert_eq( CMP_Appearance::sanitize( array( 'dim' => '' ), $ap_cur )['dim'], null, 'appearance: empty dim -> palette scrim' );
$ap_s = CMP_Appearance::sanitize( array( 'style' => 'evil', 'palette' => 'pink', 'format' => 'popup', 'buttons' => 'hidden', 'anim' => 'spin', 'theme' => 'neon', 'shadow' => 'huge', 'backdrop' => 'x', 'reopen' => 'y', 'font' => 'comic' ), $ap_cur );
cmp_assert_eq( array( $ap_s['style'], $ap_s['palette'], $ap_s['format'], $ap_s['buttons'], $ap_s['anim'], $ap_s['theme'], $ap_s['shadow'], $ap_s['backdrop'], $ap_s['reopen'], $ap_s['font'] ), array( 'brand', 'neutral', 'modal', 'solid', 'none', 'light', 'medium', 'blur', 'none', 'system' ), 'appearance: unknown enum values -> current' );
$ap_s = CMP_Appearance::sanitize( array( 'format' => 'bar', 'position' => 'bottom-left' ), $ap_cur );
cmp_assert_eq( array( $ap_s['format'], $ap_s['position'] ), array( 'bar', 'bottom' ), 'appearance: a position the format does not have -> the format\'s first' );
$ap_s = CMP_Appearance::sanitize( array( 'format' => 'card', 'position' => 'top-right' ), $ap_cur );
cmp_assert_eq( $ap_s['position'], 'top-right', 'appearance: card corner kept' );
$ap_s = CMP_Appearance::sanitize( array( 'buttons_accept' => 'solid', 'buttons_reject' => 'text', 'accept_class' => 'x', 'reject_hidden' => '1', 'precheck' => '1', 'scroll_consent' => '1' ), $ap_cur );
cmp_assert_eq( array_keys( $ap_s ), array_keys( $ap_cur ), 'appearance guarantee: no per-button / precheck / scroll keys survive' );
cmp_assert_eq( CMP_Appearance::sanitize( array( 'accent' => '#ABC' ), $ap_cur )['accent'], '#aabbcc', 'appearance: accent #rgb -> #rrggbb lowercase' );
cmp_assert_eq( CMP_Appearance::sanitize( array( 'accent' => 'red;}body{' ), $ap_cur )['accent'], '', 'appearance: non-hex accent dropped' );
cmp_assert_eq( CMP_Appearance::sanitize( array( 'dark_selector' => '.x{}</style>' ), $ap_cur )['dark_selector'], CMP_Appearance::DARK_SELECTOR, 'appearance: unsafe dark selector -> default' );
cmp_assert_eq( CMP_Appearance::sanitize( array( 'dark_selector' => 'html.night, [data-mode="dark"]' ), $ap_cur )['dark_selector'], 'html.night, [data-mode="dark"]', 'appearance: plain dark selector kept' );
$ap_s = CMP_Appearance::sanitize( array( 'exclude' => "/landing/*\nhttps://example.com/promo/?a=1\n  \nnot a path\n/a*b\n/landing/*" ), $ap_cur );
cmp_assert_eq( $ap_s['exclude'], array( '/landing/*', '/promo/' ), 'appearance: exclusions = paths, * only at the end, unique' );
$ap_s = CMP_Appearance::sanitize( array( 'texts' => array( 'ro' => array( 'title' => ' <b>Hei</b> ', 'evil' => 'x' ), 'de' => array( 'title' => 'Hallo' ) ) ), $ap_cur );
cmp_assert_eq( $ap_s['texts']['ro']['title'], 'Hei', 'appearance: texts stripped' );
cmp_assert( ! isset( $ap_s['texts']['de'] ) && ! isset( $ap_s['texts']['ro']['evil'] ) && 8 === count( $ap_s['texts']['en'] ), 'appearance: texts only ro/ru/en x 8 keys' );
$ap_s = CMP_Appearance::sanitize( array( 'radius' => 3 ), array_merge( $ap_cur, array( 'radius' => 7, 'palette' => 'egov' ) ) );
cmp_assert_eq( array( $ap_s['radius'], $ap_s['palette'] ), array( 3, 'egov' ), 'appearance: missing keys keep the current values' );
cmp_assert_eq( CMP_Appearance::sanitize( array( 'theme_colors' => '0' ), array_merge( $ap_cur, array( 'theme_colors' => true ) ) )['theme_colors'], false, 'appearance: theme_colors posted 0 -> off' );
$ap_s = CMP_Appearance::sanitize( array( 'custom_css' => '.cmp__modal{border:0}[data-cmp-action="reject"]{display:none}' ), $ap_cur );
cmp_assert_eq( $ap_s['custom_css'], '.cmp__modal{border:0}', 'appearance: custom CSS cleaned on save' );

// ---- Container classes ----
$ap_c = CMP_Appearance::sanitize( array( 'style' => 'friendly', 'format' => 'bar', 'position' => 'top', 'buttons' => 'text', 'anim' => 'slide', 'theme' => 'site', 'backdrop' => 'none' ), $ap_cur );
cmp_assert_eq( CMP_Appearance::container_classes( $ap_c, 'pro' ), array( 'cmp--neutral', 'cmp--style-friendly', 'cmp--fmt-bar', 'cmp--pos-top', 'cmp--btn-text', 'cmp--anim-slide', 'cmp--theme-site', 'cmp--backdrop-none' ), 'appearance: Pro classes' );
cmp_assert_eq( CMP_Appearance::container_classes( $ap_c, 'light' ), array( 'cmp--neutral', 'cmp--style-friendly', 'cmp--anim-slide', 'cmp--theme-site' ), 'appearance: Light skips format, position, buttons, backdrop' );
$ap_c['theme'] = 'dark';
cmp_assert( in_array( 'cmp--dark', CMP_Appearance::container_classes( $ap_c, 'pro' ), true ), 'appearance: dark theme adds the Dark palette class' );
foreach ( array( 'minimal', 'brand', 'friendly' ) as $ap_sty ) {
	foreach ( array( 'solid', 'outline', 'text' ) as $ap_b ) {
		$ap_cl = CMP_Appearance::container_classes( CMP_Appearance::sanitize( array( 'style' => $ap_sty, 'buttons' => $ap_b ), $ap_cur ), 'pro' );
		cmp_assert( 1 === count( preg_grep( '/^cmp--btn-/', $ap_cl ) ), "appearance guarantee: {$ap_sty} x {$ap_b} has one button class (both buttons)" );
	}
}

// ---- Accent suggestion (review focus 3) ----
$ap_y = CMP_Appearance::accent_suggestion( '#ffd400', '#ffffff', '' );
cmp_assert( false === $ap_y['ok'], 'appearance: #ffd400 on white -> not ok' );
cmp_assert( $ap_y['ratio'] < 4.5, 'appearance: #ffd400 on white ratio < 4.5 (' . $ap_y['ratio'] . ')' );
cmp_assert( CMP_Contrast::is_hex( $ap_y['suggest'] ) && $ap_y['suggest'] !== '#ffd400', 'appearance: a different shade is suggested (' . $ap_y['suggest'] . ')' );
cmp_assert( CMP_Contrast::ratio( $ap_y['suggest'], '#ffffff' ) >= 4.5 && CMP_Contrast::ratio( $ap_y['suggest'], $ap_y['text'] ) >= 4.5, 'appearance: suggestion passes 4.5:1 for the button text and on the background' );
cmp_assert( $ap_y['suggest_ratio'] >= 4.5, 'appearance: suggest_ratio >= 4.5' );
$ap_ok = CMP_Appearance::accent_suggestion( '#003e96', '#ffffff', '' );
cmp_assert( true === $ap_ok['ok'] && '#003e96' === $ap_ok['suggest'] && '#ffffff' === $ap_ok['text'], 'appearance: a passing accent is its own suggestion, white text' );
$ap_fx = CMP_Appearance::accent_suggestion( '#003e96', '#ffffff', '#000000' );
cmp_assert( false === $ap_fx['ok'], 'appearance: a given text colour is checked (black on navy fails)' );
$ap_dk = CMP_Appearance::accent_suggestion( '#003e96', '#1e242e', '' );
cmp_assert( false === $ap_dk['ok'] && CMP_Contrast::ratio( $ap_dk['suggest'], '#1e242e' ) >= 4.5, 'appearance: navy on the dark background -> a lighter shade that passes' );
cmp_assert( false === CMP_Appearance::accent_suggestion( 'nope', '#ffffff', '' )['ok'], 'appearance: invalid accent -> not ok' );

// ---- Custom CSS ----
$ap_in = ".cmp__modal{border:2px solid red}\n@import url(https://evil.example/x.css);\n.cmp__title{background:url(https://evil.example/t.png)}\n.cmp__intro{background:url('/img/ok.png')}\n.cmp__x{background:url(data:image/png;base64,AAAA)}\n[data-cmp-action=\"reject\"]{display:none}\n.cmp [data-cmp-action='accept']{font-size:30px}\n.cmp__btn[data-cmp-action=accept], .cmp__title{color:red}\n.cmp__btn--primary{transform:scale(2)}\n</style><script>alert(1)</script>";
$ap_cc = CMP_Appearance::clean_custom_css( $ap_in );
cmp_assert( false !== strpos( $ap_cc['css'], '.cmp__modal{border:2px solid red}' ), 'appearance css: .cmp__modal rule kept' );
cmp_assert( false === stripos( $ap_cc['css'], '</style' ) && false === stripos( $ap_cc['css'], '<script' ), 'appearance css: </style and tags removed' );
cmp_assert( false === stripos( $ap_cc['css'], '@import' ), 'appearance css: @import removed' );
cmp_assert( false === strpos( $ap_cc['css'], 'evil.example' ), 'appearance css: url() to another host removed' );
cmp_assert( false !== strpos( $ap_cc['css'], "url('/img/ok.png')" ) && false !== strpos( $ap_cc['css'], 'url(data:image/png;base64,AAAA)' ), 'appearance css: relative and data:image urls kept' );
cmp_assert( false === strpos( $ap_cc['css'], 'data-cmp-action' ), 'appearance css: rules targeting accept/reject removed (any quoting)' );
cmp_assert( false === strpos( $ap_cc['css'], 'cmp__btn--primary' ), 'appearance css: rule targeting the primary button alone removed' );
cmp_assert( count( $ap_cc['removed'] ) >= 6, 'appearance css: removed snippets listed for the admin (' . count( $ap_cc['removed'] ) . ')' );
$ap_cc2 = CMP_Appearance::clean_custom_css( '@IMPORT "x.css"; .cmp__modal{padding:0} </STYLE >' );
cmp_assert( false === stripos( $ap_cc2['css'], 'import' ) && false === stripos( $ap_cc2['css'], 'style' ) && false !== strpos( $ap_cc2['css'], '.cmp__modal{padding:0}' ), 'appearance css: case-insensitive' );
cmp_assert_eq( CMP_Appearance::clean_custom_css( '.cmp__modal{color:red}' ), array( 'css' => '.cmp__modal{color:red}', 'removed' => array() ), 'appearance css: clean CSS untouched' );
$ap_cc3 = CMP_Appearance::clean_custom_css( '@media (max-width:600px){.cmp__modal{padding:0}[data-cmp-action="reject"]{opacity:0}}' );
cmp_assert( false !== strpos( $ap_cc3['css'], '.cmp__modal{padding:0}' ) && false === strpos( $ap_cc3['css'], 'reject' ), 'appearance css: targeted rule inside @media removed, the rest kept' );
cmp_assert( false === strpos( CMP_Appearance::clean_custom_css( '.a{background:url(//evil.example/x)}' )['css'], 'evil' ), 'appearance css: protocol-relative url removed' );
cmp_assert( false === strpos( CMP_Appearance::clean_custom_css( '.a{background:url(javascript:alert(1))}' )['css'], 'javascript' ), 'appearance css: javascript: url removed' );
cmp_assert( false === stripos( CMP_Appearance::clean_custom_css( '.a{background:u\\72l(https://evil.example/x)}' )['css'], 'evil' ), 'appearance css: escaped url( removed' );

// ---- Sanitizer integration ----
$ap_ss = CMP_Sanitizer::settings( array( 'position' => 'bottom', 'appearance' => array( 'style' => 'minimal', 'radius' => 200 ) ) );
cmp_assert( isset( $ap_ss['appearance'] ) && 'minimal' === $ap_ss['appearance']['style'] && 24 === $ap_ss['appearance']['radius'], 'appearance: CMP_Sanitizer::settings runs appearance through CMP_Appearance::sanitize' );
cmp_assert( ! array_key_exists( 'appearance', CMP_Sanitizer::settings( array( 'position' => 'bottom' ) ) ), 'appearance: settings without appearance stay as before (old keys)' );
$ap_kept = CMP_Sanitizer::keep_appearance( CMP_Sanitizer::settings( array( 'position' => 'bottom' ) ), array( 'appearance' => array( 'style' => 'friendly' ) ) );
cmp_assert_eq( $ap_kept['appearance']['style'], 'friendly', 'appearance: keep_appearance carries the stored appearance over a Settings save' );

// ---- Fix round 1 ----
// I1: url() escape bypasses.
foreach ( array( '.a{background:url(/\/evil.com/x)}', '.a{background:url(/\2f evil.com)}', '.a{background:url(//evil.com)}', '.a{background:url("\\2f\\2f evil.com")}', '.a{background:URL(https://evil.com/x)}', ".a{background:url( '//evil.com' )}" ) as $ap_u ) {
	$ap_r = CMP_Appearance::clean_custom_css( $ap_u );
	cmp_assert( false === stripos( $ap_r['css'], 'evil' ) && count( $ap_r['removed'] ) > 0, 'appearance I1: url bypass removed: ' . $ap_u );
}
cmp_assert( false !== strpos( CMP_Appearance::clean_custom_css( '.a{background:url(img/x.png)}' )['css'], 'url(img/x.png)' ), 'appearance I1: plain relative url kept' );
// I2: rules that hide or shrink both buttons.
foreach ( array( '.cmp__choices{display:none}', '[data-cmp-action]{display:none}', '.cmp__btn{visibility:hidden}', '.cmp__choices .cmp__btn{width:0;opacity:0}', '.cmp button{font-size:0 !important}', '.cmp__actions{transform:scale(0)}', '.cmp__main{position:absolute;left:-9999px}' ) as $ap_h ) {
	$ap_r = CMP_Appearance::clean_custom_css( $ap_h );
	cmp_assert( ! preg_match( '/display|visibility|width|opacity|font-size|transform|position|left/', $ap_r['css'] ) && count( $ap_r['removed'] ) > 0, 'appearance I2: hiding declarations stripped: ' . $ap_h . ' -> ' . $ap_r['css'] );
}
$ap_r = CMP_Appearance::clean_custom_css( '.cmp__btn{display:none;border-radius:0;background:url(data:image/png;base64,AA)}' );
cmp_assert( false !== strpos( $ap_r['css'], 'border-radius:0' ) && false === strpos( $ap_r['css'], 'base64' ) && false === strpos( $ap_r['css'], 'display' ) && 2 === count( $ap_r['removed'] ), 'appearance I2/N1: allowlisted radius kept; display and background (one declaration, ; inside url) stripped (' . $ap_r['css'] . ')' );
cmp_assert( false !== strpos( CMP_Appearance::clean_custom_css( '.cmp__modal{display:block;width:600px}' )['css'], 'width:600px' ), 'appearance I2: rules away from the buttons untouched' );
// M2: positional selectors on the buttons.
foreach ( array( '.cmp__choices>:first-child{color:red}', '.cmp__btn:nth-child(1){color:red}', '[data-cmp-action]:last-of-type{color:red}' ) as $ap_h ) {
	cmp_assert_eq( CMP_Appearance::clean_custom_css( $ap_h )['css'], '', 'appearance M2: positional button rule removed: ' . $ap_h );
}
// M1: an unclosed last block is closed, then cleaned.
cmp_assert( false === strpos( CMP_Appearance::clean_custom_css( '.x{} [data-cmp-action="reject"]{display:none' )['css'], 'reject' ), 'appearance M1: unclosed targeted rule removed' );
// M5: a stray < is reported.
cmp_assert( count( CMP_Appearance::clean_custom_css( '.a{b:c} a < b' )['removed'] ) > 0, 'appearance M5: stray < reported' );
// Lot review M6: removed comments are listed in the notice too (not dropped silently).
$ap_cm = CMP_Appearance::clean_custom_css( '/* brand */ .a{color:red}' );
cmp_assert( in_array( '/* brand */', $ap_cm['removed'], true ) && false !== strpos( $ap_cm['css'], '.a{color:red}' ), 'appearance M6: a CSS comment is reported as removed' );
cmp_assert_eq( CMP_Appearance::clean_custom_css( $ap_cm['css'] )['removed'], array(), 'appearance M6: cleaning again reports nothing (stored CSS has no comments)' );
// M4: theme colours only in the light case.
$ap_tc2 = CMP_Appearance::defaults( 'brand' );
$ap_tc2['theme_colors'] = true;
$ap_tc2['theme'] = 'auto';
cmp_assert_eq( CMP_Appearance::inline_css( $ap_tc2, $ap_tp['passing'] ), '', 'appearance M4: theme colours not emitted when the banner can be dark' );
// I3: merge over the stored appearance.
$ap_stored = array( 'mode' => 'light', 'appearance' => array( 'style' => 'brand', 'format' => 'card', 'position' => 'top-left', 'backdrop' => 'dim', 'buttons' => 'outline', 'radius' => 7 ) );
$ap_l = CMP_Appearance::sanitize( array( 'style' => 'brand', 'palette' => 'warm', 'radius' => 9 ), CMP_Appearance::from_settings( $ap_stored, 'ro' ) );
cmp_assert_eq( array( $ap_l['format'], $ap_l['position'], $ap_l['backdrop'], $ap_l['buttons'], $ap_l['palette'], $ap_l['radius'] ), array( 'card', 'top-left', 'dim', 'outline', 'warm', 9 ), 'appearance I3: Light save keeps format/backdrop/buttons it does not post' );
$ap_f = CMP_Appearance::sanitize( array( 'style' => 'friendly' ), CMP_Appearance::from_settings( $ap_stored, 'ro' ) );
cmp_assert_eq( array( $ap_f['radius'], $ap_f['maxw'], $ap_f['anim'], $ap_f['shadow'], $ap_f['buttons'], $ap_f['format'] ), array( 20, 680, 'slide', 'large', 'solid', 'card' ), 'appearance I3: style switch loads the new style\'s values, other fields kept' );
$ap_f2 = CMP_Appearance::sanitize( array( 'style' => 'friendly', 'radius' => 3 ), CMP_Appearance::from_settings( $ap_stored, 'ro' ) );
cmp_assert_eq( $ap_f2['radius'], 3, 'appearance I3: a posted value wins over the new style' );
$ap_ss2 = CMP_Sanitizer::settings( array( 'position' => 'bottom', 'appearance' => array( 'style' => 'brand', 'radius' => 5 ) ), $ap_stored );
cmp_assert_eq( array( $ap_ss2['appearance']['format'], $ap_ss2['appearance']['radius'] ), array( 'card', 5 ), 'appearance I3: CMP_Sanitizer::settings merges over the stored appearance' );

// ---- Fix round 2: allowlist on rules that reach the buttons ----
foreach ( array( '.cmp__btn{inline-size:0}', '.cmp__btn{block-size:0}', '.cmp__btn{max-inline-size:0}', '.cmp__btn{translate:-9999px}', '.cmp__choices{contain:size}', '.cmp__btn{aspect-ratio:100}', '.cmp__btn{min-width:0;flex:0 0 0}', '.cmp__btn{text-indent:-9999px}', '.cmp__btn{color:transparent}', '.cmp__btn{color:#fff;background:#fff}', '.cmp__choices{z-index:-1}' ) as $ap_n ) {
	$ap_r = CMP_Appearance::clean_custom_css( $ap_n );
	cmp_assert( (bool) preg_match( '/^[^{]*\{\s*\}$/', $ap_r['css'] ) && count( $ap_r['removed'] ) > 0, 'appearance N1: stripped: ' . $ap_n . ' -> ' . $ap_r['css'] );
}
cmp_assert_eq( CMP_Appearance::clean_custom_css( '.cmp__btn{border-radius:2px;font-weight:600}' ), array( 'css' => '.cmp__btn{border-radius:2px;font-weight:600}', 'removed' => array() ), 'appearance N1: allowed declarations kept intact' );
$ap_r = CMP_Appearance::clean_custom_css( '.cmp__btn{border-radius:2px;color:transparent}' );
cmp_assert( false !== strpos( $ap_r['css'], 'border-radius:2px' ) && false === strpos( $ap_r['css'], 'color' ) && 1 === count( $ap_r['removed'] ), 'appearance N1: radius kept, color stripped (' . $ap_r['css'] . ')' );
cmp_assert_eq( CMP_Appearance::clean_custom_css( '.cmp__title{color:red}' )['css'], '.cmp__title{color:red}', 'appearance N1: non-button rule untouched' );
cmp_assert_eq( CMP_Appearance::clean_custom_css( '.cmp__btn{border-top-left-radius:4px;border-color:red;border-bottom-width:2px;outline-offset:3px;font-family:serif;letter-spacing:1px;text-transform:none;box-shadow:none;transition-duration:1s}' )['removed'], array(), 'appearance N1: every allowed property kept' );
