<?php
/**
 * B6: the banner's appearance (style, colours, theme, format, details,
 * density, buttons, animation, reopen, exclusions, custom CSS, texts per language).
 *
 * Pure: no WordPress functions, so it loads in tests and standalone.
 *
 * Storage: cmp_settings['appearance'] (one array, keys as in defaults()).
 * Migration is lazy, on read (from_settings()): while 'appearance' is missing
 * it is built from the old keys (preset, position, reopen_button, texts) with
 * the Brand style, which is exactly today's look. Nothing is written at install.
 *
 * The front end gets:
 *  - container classes (container_classes()) for what variables cannot do;
 *  - a small inline <style> (inline_css()) with only the --cmp-* values that
 *    differ from Brand + the chosen palette, so Brand + any palette = ''
 *    (plus the density rules, DENSITY_CSS, only for compact / comfortable).
 */
require_once __DIR__ . '/class-contrast.php';

class CMP_Appearance {

	const STYLES    = array( 'minimal', 'brand', 'friendly' );
	const PALETTES  = array( 'neutral', 'warm', 'dark', 'egov' );
	const THEMES    = array( 'light', 'dark', 'auto', 'site' );
	const FONTS     = array( 'system', 'inherit' );
	const BACKDROPS = array( 'blur', 'dim', 'none' );
	const BUTTONS   = array( 'solid', 'outline', 'text' );
	const ANIMS     = array( 'none', 'fade', 'slide' );
	const REOPEN    = array( 'none', 'icon', 'text', 'both' );
	const DENSITIES = array( 'compact', 'normal', 'comfortable' );
	const LANGS     = array( 'ro', 'ru', 'en' );
	/** The fields a style sets (spec table); a style switch reloads them. */
	const STYLE_KEYS = array( 'radius', 'shadow', 'buttons', 'anim', 'maxw' );

	/**
	 * Allowlist for rules that can reach the choice buttons or their containers:
	 * only these properties stay (they cannot hide, shrink, move or recolour one
	 * button away); every other declaration in such a rule is stripped and reported.
	 */
	const BUTTON_SAFE_PROPS = '(?:border(?:-(?:top|right|bottom|left|block|inline|block-start|block-end|inline-start|inline-end))?(?:-(?:color|width|style))?|border-radius|border-(?:top|bottom)-(?:left|right)-radius|border-(?:start|end)-(?:start|end)-radius|outline(?:-(?:color|width|style|offset))?|font-family|font-weight|letter-spacing|text-transform|box-shadow|transition(?:-(?:property|duration|timing-function|delay|behavior))?)';

	const TEXT_KEYS = array( 'title', 'intro', 'light_intro', 'accept', 'reject', 'prefs', 'save', 'prefs_title' );

	/** Format => its positions (the first one is the fallback). */
	const FORMATS = array(
		'bar'   => array( 'bottom', 'top' ),
		'card'  => array( 'bottom-right', 'bottom-left', 'top-right', 'top-left' ),
		'modal' => array( 'center', 'top', 'bottom' ),
	);

	/** Format => position => today's cmp--pos-* suffix (the CSS that already exists). */
	const POS_CLASS = array(
		'bar'   => array( 'bottom' => 'bottom', 'top' => 'top' ),
		'card'  => array( 'bottom-right' => 'bottom-right', 'bottom-left' => 'bottom-left', 'top-right' => 'top-right', 'top-left' => 'top-left' ),
		'modal' => array( 'center' => 'middle-center', 'top' => 'top-center', 'bottom' => 'bottom-center' ),
	);

	/**
	 * Old cmp_settings['position'] => array( format, position ). Controller ruling
	 * (overrides the spec's "bottom/top -> bar"): legacy bottom/top were aliases of
	 * bottom-center/top-center (the centred window), so they stay that window; the
	 * full-width bar is a new format, reachable only by choosing it.
	 */
	const LEGACY_POSITIONS = array(
		'bottom'        => array( 'modal', 'bottom' ),
		'top'           => array( 'modal', 'top' ),
		'top-left'      => array( 'card', 'top-left' ),
		'top-right'     => array( 'card', 'top-right' ),
		'bottom-left'   => array( 'card', 'bottom-left' ),
		'bottom-right'  => array( 'card', 'bottom-right' ),
		'middle-center' => array( 'modal', 'center' ),
		'modal-center'  => array( 'modal', 'center' ),
		'top-center'    => array( 'modal', 'top' ),
		'bottom-center' => array( 'modal', 'bottom' ),
	);

	/** Numeric ranges: key => array( min, max ). dim in %, blur/radius/maxw/gap in px, delay in s. */
	const RANGES = array(
		'radius' => array( 0, 24 ),
		'maxw'   => array( 360, 960 ),
		'gap'    => array( 0, 40 ),
		'dim'    => array( 0, 80 ),
		'blur'   => array( 0, 10 ),
		'delay'  => array( 0, 2 ),
		'image_size' => array( 48, 160 ),
	);

	/** Shadow name => CSS value. 'medium' is today's .cmp__modal shadow. */
	const SHADOWS = array(
		'none'   => 'none',
		'small'  => '0 4px 14px rgba(0,0,0,.12)',
		'medium' => '0 20px 50px rgba(0,0,0,.25)',
		'large'  => '0 28px 70px rgba(0,0,0,.28)',
	);

	/**
	 * The CSS custom properties, in one place (Task 2's CSS uses these names).
	 * radius px, shadow box-shadow, maxw px, gap px (overlay padding / distance
	 * from the edge), dim = scrim alpha 0..0.8, blur px, font font-family.
	 */
	const VARS = array(
		'radius'        => '--cmp-radius',
		'shadow'        => '--cmp-shadow',
		'maxw'          => '--cmp-maxw',
		'gap'           => '--cmp-gap',
		'dim'           => '--cmp-dim',
		'blur'          => '--cmp-blur',
		'font'          => '--cmp-font',
		'primary'       => '--cmp-primary',
		'primary_hover' => '--cmp-primary-hover',
		'primary_text'  => '--cmp-primary-text',
		'bg'            => '--cmp-bg',
		'text'          => '--cmp-dark',
	);

	/** Where the inline variables go (same elements as banner.css's base block, plus the reopen button). */
	const SCOPE = '.cmp,.cmp-light,.cmp-reopen,.cmp-embed';

	/** Default "like the site" dark selectors (matched on <html> or <body> by the banner JS). */
	const DARK_SELECTOR = '.dark, [data-theme="dark"], .is-dark-theme';

	/**
	 * Density (compact / comfortable): spacing about 2/3 or 4/3 of today's
	 * (modal 28/32 px, phone sheet 24/20, buttons 12/22, gaps 12, categories
	 * 8/24 and 16), comfortable text +1 px. Appended to the inline block only
	 * when chosen, so banner.css (and its gzip budget) and every "normal"
	 * banner stay as they are. Same rule for every button (equal weight); the
	 * bar keeps its centred content; the phone band gets its own values.
	 * Mirrored as AP_DENSITY in standalone/cmp.js (density-parity.json).
	 */
	const DENSITY_CSS = array(
		'compact'     => '@media (min-width:576px){.cmp--density-compact .cmp__modal{padding:19px 21px}.cmp--density-compact.cmp--fmt-bar .cmp__modal{padding-inline:max(21px,calc(50% - 600px))}.cmp--density-compact .cmp__title{margin-bottom:8px}.cmp--density-compact .cmp__main .cmp__text{margin-bottom:16px}.cmp--density-compact .cmp__actions,.cmp--density-compact .cmp__prefs-actions,.cmp--density-compact .cmp__choices{gap:8px}.cmp--density-compact .cmp__btn{padding:8px 15px}}'
			. '@media (max-width:575px){.cmp--density-compact .cmp__modal{padding:16px 14px}.cmp--density-compact .cmp__actions{gap:5px 8px}.cmp--density-compact .cmp__choices{gap:5px}.cmp--density-compact .cmp__choices .cmp__btn{min-height:36px;padding:7px 8px}}'
			. '.cmp--density-compact .cmp__prefs-header{gap:11px;margin-bottom:13px}.cmp--density-compact .cmp__cats{padding:5px 16px;margin-bottom:13px}.cmp--density-compact .cmp__cat{gap:11px;padding:11px 0}'
			. '.cmp-light.cmp--density-compact{gap:9px;padding:12px 13px}.cmp--density-compact .cmp-light__btn{padding:7px 17px}',
		'comfortable' => '@media (min-width:576px){.cmp--density-comfortable .cmp__modal{padding:37px 43px}.cmp--density-comfortable.cmp--fmt-bar .cmp__modal{padding-inline:max(43px,calc(50% - 600px))}.cmp--density-comfortable .cmp__title{margin-bottom:16px}.cmp--density-comfortable .cmp__main .cmp__text{margin-bottom:32px}.cmp--density-comfortable .cmp__actions,.cmp--density-comfortable .cmp__prefs-actions,.cmp--density-comfortable .cmp__choices{gap:16px}.cmp--density-comfortable .cmp__btn{padding:16px 29px}}'
			. '@media (max-width:575px){.cmp--density-comfortable .cmp__modal{padding:32px 27px}.cmp--density-comfortable .cmp__actions{gap:11px 16px}.cmp--density-comfortable .cmp__choices{gap:11px}.cmp--density-comfortable .cmp__choices .cmp__btn{min-height:48px;padding:13px 16px}}'
			. '.cmp--density-comfortable .cmp__text{font-size:15px}.cmp--density-comfortable .cmp__cat-desc{font-size:14px}.cmp--density-comfortable .cmp__prefs-header{gap:21px;margin-bottom:27px}.cmp--density-comfortable .cmp__cats{padding:11px 32px;margin-bottom:27px}.cmp--density-comfortable .cmp__cat{gap:21px;padding:21px 0}'
			. '.cmp-light.cmp--density-comfortable{gap:19px;padding:24px 27px;font-size:15px}.cmp--density-comfortable .cmp-light__btn{padding:13px 35px}',
	);

	/** Each palette's scrim alpha in % (banner.css --cmp-scrim). dim = null means "the palette's". */
	const PALETTE_DIM = array( 'neutral' => 55, 'warm' => 50, 'dark' => 60, 'egov' => 60 );

	/** Each palette's --cmp-bg / --cmp-primary / --cmp-dark (banner.css). */
	const PALETTE_BG      = array( 'neutral' => '#ffffff', 'warm' => '#fbf9f4', 'dark' => '#1e242e', 'egov' => '#ffffff' );
	const PALETTE_PRIMARY = array( 'neutral' => '#111827', 'warm' => '#2e794d', 'dark' => '#5b9bff', 'egov' => '#003e96' );
	const PALETTE_TEXT    = array( 'neutral' => '#111827', 'warm' => '#2c2417', 'dark' => '#f2f4f7', 'egov' => '#001b35' );

	/** WCAG AA for normal text: button label on the accent, and accent links on the background. */
	const MIN_RATIO = 4.5;

	/** Lightness step (HSL percentage points) for the hover shade of a custom accent. */
	const HOVER_STEP = 8;

	/**
	 * The full appearance array for a style: the style's values (spec table)
	 * plus the shared defaults. Brand = today's banner. Unknown style -> Brand.
	 */
	public static function defaults( $style ) {
		$style = in_array( $style, self::STYLES, true ) ? $style : 'brand';
		$by    = array(
			'minimal'  => array( 'radius' => 4, 'shadow' => 'none', 'buttons' => 'outline', 'anim' => 'fade', 'maxw' => 640 ),
			'brand'    => array( 'radius' => 12, 'shadow' => 'medium', 'buttons' => 'solid', 'anim' => 'none', 'maxw' => 720 ),
			'friendly' => array( 'radius' => 20, 'shadow' => 'large', 'buttons' => 'solid', 'anim' => 'slide', 'maxw' => 680 ),
		);
		$s     = $by[ $style ];
		$texts = array();
		foreach ( self::LANGS as $l ) {
			$texts[ $l ] = array_fill_keys( self::TEXT_KEYS, '' );
		}
		return array(
			'style'         => $style,
			'palette'       => 'neutral',
			'theme_colors'  => false,
			'accent'        => '',
			'font'          => 'system',
			'theme'         => 'light',
			'dark_selector' => self::DARK_SELECTOR,
			'format'        => 'modal',
			'position'      => 'center',
			'backdrop'      => 'blur',
			'dim'           => null,
			'blur'          => 5,
			'radius'        => $s['radius'],
			'shadow'        => $s['shadow'],
			'maxw'          => $s['maxw'],
			'gap'           => 24,
			'density'       => 'normal',
			'buttons'       => $s['buttons'],
			'image'         => 'none',
			'image_id'      => 0,
			'image_alt'     => '',
			'image_side'    => 'right',
			'image_size'    => 96,
			'image_out'     => true,
			'image_phone'   => 'small',
			'anim'          => $s['anim'],
			'delay'         => 0.0,
			'reopen'        => 'none',
			'exclude'       => array(),
			'custom_css'    => '',
			'texts'         => $texts,
		);
	}

	/**
	 * The normalized appearance of the stored settings. When
	 * $settings['appearance'] is missing it is built from the old keys
	 * (lazy migration): preset -> palette, position -> format + position,
	 * reopen_button -> reopen, texts[key] -> texts[$lang][key], style Brand.
	 *
	 * @param array  $settings cmp_settings.
	 * @param string $lang     The site's current language (ro|ru|en) for the old texts;
	 *                         '' = CMP_Lang::current() when loaded, else 'ro'.
	 */
	public static function from_settings( array $settings, $lang = '' ) {
		if ( isset( $settings['appearance'] ) && is_array( $settings['appearance'] ) ) {
			$stored = $settings['appearance'];
			$style  = isset( $stored['style'] ) ? (string) $stored['style'] : 'brand';
			return self::sanitize( $stored, self::defaults( $style ) );
		}

		$a      = self::defaults( 'brand' );
		$preset = isset( $settings['preset'] ) ? (string) $settings['preset'] : '';
		$pos    = isset( $settings['position'] ) ? (string) $settings['position'] : '';
		$a['palette'] = in_array( $preset, self::PALETTES, true ) ? $preset : 'neutral';
		if ( isset( self::LEGACY_POSITIONS[ $pos ] ) ) {
			$a['format']   = self::LEGACY_POSITIONS[ $pos ][0];
			$a['position'] = self::LEGACY_POSITIONS[ $pos ][1];
		}
		$a['reopen'] = ! empty( $settings['reopen_button'] ) ? 'icon' : 'none';

		if ( '' === $lang && class_exists( 'CMP_Lang' ) ) {
			$lang = CMP_Lang::current();
		}
		$lang = in_array( $lang, self::LANGS, true ) ? $lang : 'ro';
		$old  = isset( $settings['texts'] ) && is_array( $settings['texts'] ) ? $settings['texts'] : array();
		foreach ( self::TEXT_KEYS as $k ) {
			$a['texts'][ $lang ][ $k ] = isset( $old[ $k ] ) && is_scalar( $old[ $k ] ) ? self::text( $old[ $k ] ) : '';
		}
		return $a;
	}

	/**
	 * Sanitize a posted appearance over the current one. Keys missing from
	 * $input keep the current value (fields disabled in Light are not posted);
	 * unknown enum values keep the current value; numbers are clamped to
	 * RANGES; unknown keys are dropped (so there is no per-button style, no
	 * pre-ticked category and no scroll consent to set: guarantees B6.18).
	 * Booleans (theme_colors) are read only when posted: the form posts a
	 * hidden "0" before the checkbox.
	 */
	public static function sanitize( array $input, array $current ) {
		$style = isset( $current['style'] ) ? (string) $current['style'] : 'brand';
		$out   = self::apply( self::defaults( $style ), $current );
		$new   = isset( $input['style'] ) && is_scalar( $input['style'] ) ? (string) $input['style'] : '';
		if ( in_array( $new, self::STYLES, true ) && $new !== $out['style'] ) {
			// A new style reloads its own values; posted ones still win below.
			$nd = self::defaults( $new );
			foreach ( self::STYLE_KEYS as $k ) {
				$out[ $k ] = $nd[ $k ];
			}
		}
		return self::apply( $out, $input );
	}

	/** Normalize without re-cleaning custom_css (for the readers that do not use it). */
	private static function normalize( array $a ) {
		$style = isset( $a['style'] ) ? (string) $a['style'] : 'brand';
		return self::apply( self::defaults( $style ), $a, false );
	}

	/**
	 * Classes for the banner container. Light ($mode 'light') skips format,
	 * position, buttons and backdrop (they do not apply to the Light card).
	 * The palette class (cmp--<palette>, today's) comes first; the pos class is
	 * today's cmp--pos-* so existing CSS keeps working. Theme 'dark' adds the
	 * Dark palette class cmp--dark ('site' gets it from the JS, 'auto' from CSS).
	 *
	 * @return string[]
	 */
	public static function container_classes( array $a, $mode ) {
		$a   = self::normalize( $a );
		$pro = 'light' !== $mode;
		$c   = array( 'cmp--' . $a['palette'], 'cmp--style-' . $a['style'] );
		if ( $pro ) {
			$c[] = 'cmp--fmt-' . $a['format'];
			$c[] = 'cmp--pos-' . self::POS_CLASS[ $a['format'] ][ $a['position'] ];
			$c[] = 'cmp--btn-' . $a['buttons'];
		}
		$c[] = 'cmp--anim-' . $a['anim'];
		$c[] = 'cmp--theme-' . $a['theme'];
		if ( $pro ) {
			$c[] = 'cmp--backdrop-' . $a['backdrop'];
		}
		if ( 'dark' === $a['theme'] && 'dark' !== $a['palette'] ) {
			$c[] = 'cmp--dark';
		}
		if ( 'normal' !== $a['density'] ) {
			$c[] = 'cmp--density-' . $a['density'];
		}
		return $c;
	}

	/**
	 * The inline <style> body: only what differs from Brand + the palette
	 * (Brand + any palette = ''). A custom accent overrides --cmp-primary,
	 * -hover and -text on SCOPE; in the dark, auto and "like the site" themes
	 * (and on the Dark palette) a dark-background variant of the accent goes
	 * under .cmp--dark, and for auto also under
	 * @media (prefers-color-scheme:dark) .cmp--theme-auto.
	 * Never contains "<": the values are all generated here.
	 *
	 * @param array $theme_palette The theme's colour palette (theme_colors()), used
	 *                             only with "Preia din temă"; the caller reads it.
	 */
	public static function inline_css( array $a, array $theme_palette = array() ) {
		$a     = self::normalize( $a );
		$brand = self::defaults( 'brand' );
		$v     = array();

		// Theme colours only when the banner is always light: under .cmp--dark they would
		// override the dark bg/text while muted/panel/border stay dark (review M4).
		if ( $a['theme_colors'] && 'light' === $a['theme'] && 'dark' !== $a['palette'] ) {
			$tc = self::theme_colors( $theme_palette, $a['palette'] );
			$v  = $tc['vars'];
		}
		if ( '' !== $a['accent'] ) {
			$v = array_merge( $v, self::accent_vars( $a['accent'], self::text_for( $a['accent'] ) ) );
		}
		foreach ( array( 'radius', 'maxw', 'gap', 'blur' ) as $k ) {
			if ( $a[ $k ] !== $brand[ $k ] ) {
				$v[ $k ] = $a[ $k ] . 'px';
			}
		}
		if ( $a['shadow'] !== $brand['shadow'] ) {
			$v['shadow'] = self::SHADOWS[ $a['shadow'] ];
		}
		if ( null !== $a['dim'] && $a['dim'] !== self::PALETTE_DIM[ $a['palette'] ] ) {
			$v['dim'] = self::num( $a['dim'] / 100 );
		}
		if ( 'inherit' === $a['font'] ) {
			// A real declaration: "--cmp-font:inherit" would be the CSS-wide keyword (the
			// variable inherits its own undefined value) and var() would fall back to the
			// system stack. This rule comes after banner.css, same specificity, so it wins.
			$v['font-family'] = 'inherit';
		}

		$css = $v ? self::SCOPE . '{' . self::decls( $v ) . '}' : '';

		$dark_ui = in_array( $a['theme'], array( 'dark', 'auto', 'site' ), true ) || 'dark' === $a['palette'];
		if ( '' !== $a['accent'] && $dark_ui ) {
			$s     = self::accent_suggestion( $a['accent'], self::PALETTE_BG['dark'], '' );
			$dvars = self::decls( self::accent_vars( $s['suggest'], $s['text'] ) );
			$css  .= '.cmp--dark{' . $dvars . '}';
			if ( 'auto' === $a['theme'] ) {
				$css .= '@media (prefers-color-scheme:dark){.cmp--theme-auto{' . $dvars . '}}';
			}
		}
		if ( isset( self::DENSITY_CSS[ $a['density'] ] ) ) {
			$css .= self::DENSITY_CSS[ $a['density'] ];
		}
		if ( 'minimal' === $a['style'] ) {
			$css .= self::MINIMAL_CSS;
		}
		return $css . self::image_css( $a );
	}

	/**
	 * Minimal (#752 feedback): its buttons stay on one row on screens wider than a
	 * phone (the settings button is the short "Setări" there, so they fit in 640px).
	 * Printed only for Minimal: banner.css and every other banner stay as they are.
	 * Mirrored in standalone/cmp.js (AP_MINIMAL).
	 */
	const MINIMAL_CSS = '@media (min-width:576px){.cmp--style-minimal .cmp__actions,.cmp--style-minimal .cmp__choices{flex-wrap:nowrap}.cmp--style-minimal .cmp__btn{white-space:nowrap}}';

	/** The picture of the banner (#727): none, one of ours, or the site's own (image_id, from the media library). */
	const IMAGES = array( 'none', 'shield', 'wp', 'own' );

	/**
	 * Pure: the rules of the banner picture, '' without one (so no other site gets a
	 * byte). It sits on a top corner of the banner, outside it by default (the part
	 * inside is at most 56px, beside the title, which makes room for it), inside at a
	 * top position (nothing above the screen): there the title and the text make room
	 * and the text reaches below the picture, so the buttons start under it. Never on the preferences view, under
	 * 360px, or on a low screen. Where the banner scrolls by itself (no overlay) the
	 * main view lets it out. Mirrored in standalone/cmp.js (image-parity.json).
	 */
	public static function image_css( array $a ) {
		if ( 'none' === $a['image'] ) {
			return '';
		}
		$z    = (int) $a['image_size'];
		$e    = 'left' === $a['image_side'] ? 'left' : 'right';
		$in   = ! $a['image_out'] || 0 === strpos( $a['position'], 'top' );
		$p    = min( $z, 64 );
		$css  = '.cmp__modal{position:relative}.cmp__img{position:absolute;z-index:1;top:' . ( $in ? 20 : min( 0, 56 - $z ) ) . 'px;' . $e . ':24px;width:' . $z . 'px;height:' . $z . 'px;object-fit:contain;pointer-events:none}.cmp__modal.is-prefs-open .cmp__img{display:none}';
		$css .= '@media (min-width:576px){.cmp--fmt-bar .cmp__img{' . $e . ':max(32px,calc(50% - 600px))}.cmp__main .cmp__title' . ( $in ? ',.cmp__main .cmp__text' : '' ) . '{padding-' . $e . ':' . ( $z + 12 ) . 'px}' . ( $in ? '.cmp__main .cmp__text{min-height:' . max( 0, $z - 40 ) . 'px}' : '' ) . '}';
		$css .= '@media (max-width:575px){.cmp__img{' . ( 'none' === $a['image_phone'] ? 'display:none' : 'top:' . ( 14 - $p ) . 'px;' . $e . ':16px;width:' . $p . 'px;height:' . $p . 'px' ) . '}}';
		$css .= '@media (max-width:359px),(max-height:480px){.cmp__img{display:none}}';
		$css .= '@media (min-height:481px){.cmp.cmp--no-overlay .cmp__modal:not(.is-prefs-open),.cmp.cmp--backdrop-none .cmp__modal:not(.is-prefs-open){overflow:visible}}';
		return $css;
	}

	/** theme.json palette slugs, in order, for each banner colour (Twenty Twenty-Three/-Four/-Five and common names). */
	const THEME_SLUGS = array(
		'bg'      => array( 'background', 'base' ),
		'text'    => array( 'foreground', 'contrast', 'text' ),
		'primary' => array( 'primary', 'accent', 'accent-1', 'secondary' ),
	);

	/**
	 * "Preia din temă" (B6.1): the banner colours from the theme's palette, checked.
	 * $palette = the theme.json palette (wp_get_global_settings( array( 'color',
	 * 'palette', 'theme' ) ), also filled by classic themes' editor-color-palette):
	 * a list of { slug, color }; non-hex colours are ignored.
	 *  - background + text: kept only when the background is light (the banner is
	 *    light here: muted/panel/border stay the palette's) and text on it >= 4.5:1;
	 *  - primary: through the accent check (accent_suggestion(), auto #fff/#000
	 *    button text, >= 4.5:1 with its text and on the background); failing, the
	 *    chosen palette's primary stays and the screen shows the warning.
	 * Only values that differ from the chosen palette go in 'vars'.
	 *
	 * @return array { found: bool (a known slug resolved), vars: array (VARS keys
	 *               => hex), primary: '#rrggbb'|'' (the theme's), primary_ok: bool,
	 *               ratio, suggest, suggest_ratio (accent_suggestion of the primary) }
	 */
	public static function theme_colors( array $palette, $chosen ) {
		$chosen = isset( self::PALETTE_BG[ $chosen ] ) ? $chosen : 'neutral';
		if ( isset( $palette['theme'] ) && is_array( $palette['theme'] ) ) {
			$palette = $palette['theme']; // The whole per-origin array was passed.
		}
		$by = array();
		foreach ( $palette as $e ) {
			if ( is_array( $e ) && isset( $e['slug'], $e['color'] ) && is_string( $e['slug'] ) && '' !== self::hex6( $e['color'] ) ) {
				$by[ strtolower( $e['slug'] ) ] = self::hex6( $e['color'] );
			}
		}
		$got = array();
		foreach ( self::THEME_SLUGS as $k => $slugs ) {
			foreach ( $slugs as $slug ) {
				if ( isset( $by[ $slug ] ) ) {
					$got[ $k ] = $by[ $slug ];
					break;
				}
			}
		}
		$out = array( 'found' => (bool) $got, 'vars' => array(), 'primary' => isset( $got['primary'] ) ? $got['primary'] : '', 'primary_ok' => false, 'ratio' => 0.0, 'suggest' => '', 'suggest_ratio' => 0.0 );
		$bg  = self::PALETTE_BG[ $chosen ];
		$txt = self::PALETTE_TEXT[ $chosen ];
		if ( isset( $got['bg'] ) && '#000000' === self::text_for( $got['bg'] ) ) {
			$bg = $got['bg'];
			$t  = isset( $got['text'] ) ? $got['text'] : $txt;
			// The theme's own text when it reads on its background, else the palette's when that does.
			$txt = CMP_Contrast::ratio( $t, $bg ) >= self::MIN_RATIO ? $t : ( CMP_Contrast::ratio( $txt, $bg ) >= self::MIN_RATIO ? $txt : '' );
			if ( '' === $txt ) {
				$bg  = self::PALETTE_BG[ $chosen ];
				$txt = self::PALETTE_TEXT[ $chosen ];
			}
		} elseif ( isset( $got['text'] ) && CMP_Contrast::ratio( $got['text'], $bg ) >= self::MIN_RATIO ) {
			$txt = $got['text'];
		}
		if ( $bg !== self::PALETTE_BG[ $chosen ] ) {
			$out['vars']['bg'] = $bg;
		}
		if ( $txt !== self::PALETTE_TEXT[ $chosen ] ) {
			$out['vars']['text'] = $txt;
		}
		if ( '' !== $out['primary'] ) {
			$s = self::accent_suggestion( $out['primary'], $bg, '' );
			$out['primary_ok']    = $s['ok'];
			$out['ratio']         = $s['ratio'];
			$out['suggest']       = $s['suggest'];
			$out['suggest_ratio'] = $s['suggest_ratio'];
			if ( $s['ok'] ) {
				$out['vars'] = array_merge( $out['vars'], self::accent_vars( $out['primary'], $s['text'] ) );
			}
		}
		// The palette's own primary must still pass on a theme background (else the vars go).
		if ( ! $out['primary_ok'] && isset( $out['vars']['bg'] ) && CMP_Contrast::ratio( self::PALETTE_PRIMARY[ $chosen ], $bg ) < self::MIN_RATIO ) {
			unset( $out['vars']['bg'] );
			if ( isset( $out['vars']['text'] ) && CMP_Contrast::ratio( $out['vars']['text'], self::PALETTE_BG[ $chosen ] ) < self::MIN_RATIO ) {
				unset( $out['vars']['text'] );
			}
		}
		return $out;
	}

	/**
	 * Contrast check of a custom accent, with the closest passing shade.
	 *
	 * Passing = ratio(accent, button text) >= 4.5 AND ratio(accent, bg) >= 4.5
	 * (the accent is the button background and also the link colour on bg).
	 * $text_hex = the button text colour; '' = chosen automatically: #ffffff or
	 * #000000, whichever contrasts more with the accent.
	 *
	 * Formula for the suggestion: keep the accent's HSL hue and saturation and
	 * move only the lightness, in 0.5-point steps, alternately darker then
	 * lighter (darker first on a tie); the first shade that passes (with its own
	 * auto text colour, or the given one) is the suggestion. If no lightness
	 * passes, #000000 or #ffffff (the better one).
	 *
	 * @return array { ok: bool, ratio: float (of the accent as given, floored
	 *               to 2 decimals), suggest: '#rrggbb' (the accent itself when ok),
	 *               text: '#rrggbb' button text for the suggestion,
	 *               suggest_ratio: float }
	 */
	public static function accent_suggestion( $hex, $bg_hex, $text_hex ) {
		$hex   = self::hex6( $hex );
		$bg    = self::hex6( $bg_hex );
		$fixed = self::hex6( $text_hex );
		if ( '' === $hex || '' === $bg ) {
			return array( 'ok' => false, 'ratio' => 0.0, 'suggest' => '', 'text' => '', 'suggest_ratio' => 0.0 );
		}
		list( $r0, $t0 ) = self::score( $hex, $bg, $fixed );
		if ( $r0 >= self::MIN_RATIO ) {
			return array( 'ok' => true, 'ratio' => self::floor2( $r0 ), 'suggest' => $hex, 'text' => $t0, 'suggest_ratio' => self::floor2( $r0 ) );
		}
		list( $h, $s, $l ) = self::hsl( $hex );
		for ( $i = 1; $i <= 200; $i++ ) {
			foreach ( array( -1, 1 ) as $dir ) {
				$l2 = $l + $dir * $i * 0.5;
				if ( $l2 < 0 || $l2 > 100 ) {
					continue;
				}
				$c = self::from_hsl( $h, $s, $l2 );
				list( $r, $t ) = self::score( $c, $bg, $fixed );
				if ( $r >= self::MIN_RATIO ) {
					return array( 'ok' => false, 'ratio' => self::floor2( $r0 ), 'suggest' => $c, 'text' => $t, 'suggest_ratio' => self::floor2( $r ) );
				}
			}
		}
		list( $rb, $tb ) = self::score( '#000000', $bg, $fixed );
		list( $rw, $tw ) = self::score( '#ffffff', $bg, $fixed );
		$best = $rb >= $rw ? array( '#000000', $tb, $rb ) : array( '#ffffff', $tw, $rw );
		return array( 'ok' => false, 'ratio' => self::floor2( $r0 ), 'suggest' => $best[0], 'text' => $best[1], 'suggest_ratio' => self::floor2( $best[2] ) );
	}

	/**
	 * Is this request path excluded (the banner does not open by itself there)?
	 * A pattern is an exact path, or a prefix when it ends with "*". Compared
	 * without query / fragment, percent-decoded, trailing slash insensitive.
	 * $base = the site's own path (subdirectory install): the path is tried as
	 * is and with that base stripped, so "/landing/*" works on /app/landing/.
	 */
	public static function excluded( array $patterns, $path, $base = '' ) {
		$path = self::path_norm( $path );
		if ( '' === $path ) {
			return false;
		}
		$paths = array( $path );
		$base  = rtrim( self::path_norm( $base ), '/' );
		if ( '' !== $base && 0 === strpos( $path, $base . '/' ) ) {
			$paths[] = substr( $path, strlen( $base ) );
		}
		foreach ( $patterns as $p ) {
			if ( ! is_string( $p ) || '' === $p || '/' !== $p[0] ) {
				continue;
			}
			$prefix = '*' === substr( $p, -1 );
			$p      = $prefix ? rawurldecode( substr( $p, 0, -1 ) ) : self::path_norm( $p );
			foreach ( $paths as $x ) {
				if ( $prefix ? 0 === strpos( $x, $p ) : $x === $p ) {
					return true;
				}
			}
		}
		return false;
	}

	/** A path without query / fragment, percent-decoded, always ending with "/" ('' when not a path). */
	private static function path_norm( $path ) {
		$path = is_string( $path ) ? preg_replace( '/[?#].*$/s', '', $path ) : '';
		if ( '' === $path || '/' !== $path[0] ) {
			return '';
		}
		return rtrim( rawurldecode( $path ), '/' ) . '/';
	}

	/**
	 * Clean the admin's custom CSS. Removes: HTML tags and any "</style",
	 * comments, @import, url()/image-set() that are not relative or
	 * data:image/, expression() and -moz-binding, and every rule whose selector
	 * targets the Accept or Reject button on its own
	 * ([data-cmp-action="accept"|"reject"] in any quoting, or with a ^= *= $= |= ~=
	 * operator) or .cmp__btn--primary. Everything else is kept as written
	 * (CSS escapes of letters/digits are decoded first so they cannot hide a name).
	 *
	 * @return array { css: string, removed: string[] (snippets, for the admin message) }
	 */
	public static function clean_custom_css( $css ) {
		$css     = (string) $css;
		$removed = array();

		// HTML tags, then any leftover "</style" (also unclosed / with spaces).
		if ( preg_match_all( '#<[^>]*>?#', $css, $m ) ) {
			foreach ( $m[0] as $tag ) {
				$removed[] = $tag;
			}
			$css = strip_tags( $css );
		}
		while ( preg_match( '#<\s*/?\s*style#i', $css, $m ) ) {
			$removed[] = $m[0];
			$css       = (string) preg_replace( '#<\s*/?\s*style#i', '', $css );
		}
		if ( false !== strpos( $css, '<' ) ) {
			$removed[] = '<';
			$css       = str_replace( '<', '', $css );
		}

		// Comments (they could split a name); an unclosed one runs to the end. Listed
		// in the notice, so the admin knows they were not kept (lot review M6).
		if ( preg_match_all( '#/\*.*?(\*/|$)#s', $css, $m ) ) {
			foreach ( $m[0] as $c ) {
				$removed[] = strlen( $c ) <= 60 ? $c : ( function_exists( 'mb_substr' ) ? mb_substr( $c, 0, 57, 'UTF-8' ) : substr( $c, 0, 57 ) ) . '...';
			}
			$css = (string) preg_replace( '#/\*.*?(\*/|$)#s', '', $css );
		}
		// An unclosed last block is auto-closed by the browser: close it here too.
		$open = substr_count( $css, '{' ) - substr_count( $css, '}' );
		if ( $open > 0 ) {
			$css .= str_repeat( '}', $open );
		}

		// Decode escapes of letters, digits, - and _ (u\72l( -> url().
		$css = (string) preg_replace_callback(
			'/\\\\([0-9a-fA-F]{1,6})\s?|\\\\([A-Za-z0-9_-])/',
			function ( $m ) {
				if ( isset( $m[2] ) && '' !== $m[2] ) {
					return $m[2];
				}
				$cp = hexdec( $m[1] );
				$ch = $cp < 128 ? chr( (int) $cp ) : '';
				return ( '' !== $ch && preg_match( '/^[A-Za-z0-9_-]$/', $ch ) ) ? $ch : $m[0];
			},
			$css
		);

		// @import (to the ; or the end of the line).
		$css = (string) preg_replace_callback(
			'/@import\b[^;\n]*;?/i',
			function ( $m ) use ( &$removed ) {
				$removed[] = trim( $m[0] );
				return '';
			},
			$css
		);

		// url(): only relative addresses and data:image/.
		$css = (string) preg_replace_callback(
			'/url\(\s*(["\']?)(.*?)\1\s*\)/i',
			function ( $m ) use ( &$removed ) {
				if ( self::safe_url( $m[2] ) ) {
					return $m[0];
				}
				$removed[] = $m[0];
				return 'none';
			},
			$css
		);
		// image-set() takes bare strings as addresses; expression() / -moz-binding run code (old browsers).
		$css = (string) preg_replace_callback(
			'/(?:-webkit-)?image-set\([^;{}]*?\)(?=\s*[;}!]|\s*$)|expression\s*\([^;{}]*\)|-moz-binding\s*:[^;{}]*/i',
			function ( $m ) use ( &$removed ) {
				if ( 0 === stripos( $m[0], 'expression' ) || 0 === stripos( $m[0], '-moz-binding' ) ) {
					$removed[] = $m[0];
					return '';
				}
				if ( preg_match( '#["\']\s*(?:[a-z][a-z0-9+.\-]*:|//)#i', $m[0] ) && ! preg_match( '#["\']\s*data:image/#i', $m[0] ) ) {
					$removed[] = $m[0];
					return 'none';
				}
				return $m[0];
			},
			$css
		);

		// Rules (innermost blocks, so also inside @media) that single out a button.
		$css = (string) preg_replace_callback(
			'/([^{}]*)\{([^{}]*)\}/',
			function ( $m ) use ( &$removed ) {
				if ( ! self::targets_button( $m[1] ) ) {
					if ( ! self::reaches_choices( $m[1] ) ) {
						return $m[0];
					}
					// Keep the rule, with only the allowlisted declarations (split on ';' outside parentheses).
					$kept = array();
					foreach ( preg_split( '/;(?![^()]*\))/', $m[2] ) as $d ) {
						if ( '' !== trim( $d ) && ! preg_match( '/^\s*' . self::BUTTON_SAFE_PROPS . '\s*:/i', $d ) ) {
							$removed[] = trim( $m[1] ) . '{' . trim( $d ) . '}';
							continue;
						}
						$kept[] = $d;
					}
					return $m[1] . '{' . implode( ';', $kept ) . '}';
				}
				$removed[] = trim( $m[0] );
				// Keep the leading whitespace (and anything after an at-rule's ';').
				return preg_match( '/^(.*;)?\s*/s', $m[1], $lead ) && isset( $lead[1] ) ? $lead[1] : '';
			},
			$css
		);

		return array( 'css' => trim( $css ), 'removed' => array_values( array_filter( array_map( 'trim', $removed ), 'strlen' ) ) );
	}

	// ---------------------------------------------------------------------
	// Internals.

	/** Apply $src over $out, field by field, in the defaults' key order. */
	private static function apply( array $out, array $src, $clean_css = true ) {
		foreach ( $out as $k => $fallback ) {
			if ( array_key_exists( $k, $src ) ) {
				if ( 'custom_css' === $k && ! $clean_css ) {
					$out[ $k ] = is_string( $src[ $k ] ) ? $src[ $k ] : $fallback;
					continue;
				}
				$out[ $k ] = self::field( $k, $src[ $k ], $fallback, $out );
			}
		}
		if ( ! in_array( $out['position'], self::FORMATS[ $out['format'] ], true ) ) {
			$out['position'] = self::FORMATS[ $out['format'] ][0];
		}
		return $out;
	}

	/** One sanitized field; $fallback when the value is not acceptable. */
	private static function field( $k, $v, $fallback, array $out ) {
		$enums = array(
			'style'    => self::STYLES,
			'palette'  => self::PALETTES,
			'font'     => self::FONTS,
			'theme'    => self::THEMES,
			'format'   => array_keys( self::FORMATS ),
			'backdrop' => self::BACKDROPS,
			'shadow'   => array_keys( self::SHADOWS ),
			'buttons'  => self::BUTTONS,
			'anim'     => self::ANIMS,
			'reopen'   => self::REOPEN,
			'density'  => self::DENSITIES,
			'image'    => self::IMAGES,
			'image_side'  => array( 'right', 'left' ),
			'image_phone' => array( 'small', 'none' ),
		);
		if ( isset( $enums[ $k ] ) ) {
			return is_scalar( $v ) && in_array( (string) $v, $enums[ $k ], true ) ? (string) $v : $fallback;
		}
		switch ( $k ) {
			case 'position':
				return is_scalar( $v ) && in_array( (string) $v, self::FORMATS[ $out['format'] ], true ) ? (string) $v : $fallback;
			case 'image_id':
				return is_numeric( $v ) ? max( 0, (int) $v ) : $fallback;
			case 'image_alt':
				return is_scalar( $v ) ? ( function_exists( 'mb_substr' ) ? mb_substr( self::text( $v ), 0, 300, 'UTF-8' ) : self::text( $v ) ) : $fallback;
			case 'image_out':
			case 'theme_colors':
				return true === $v || 1 === $v || in_array( $v, array( '1', 'on', 'true', 'yes' ), true );
			case 'accent':
				return self::hex6( $v );
			case 'dark_selector':
				$sel = is_scalar( $v ) ? trim( (string) $v ) : '';
				return ( '' !== $sel && strlen( $sel ) <= 200 && preg_match( '/^[A-Za-z0-9_\-.#\[\]="\' ,:()>~+*]+$/', $sel ) ) ? $sel : self::DARK_SELECTOR;
			case 'dim':
				if ( null === $v || '' === $v ) {
					return null;
				}
				return is_numeric( $v ) ? self::clamp( (int) round( (float) $v ), 'dim' ) : $fallback;
			case 'blur':
			case 'radius':
			case 'maxw':
			case 'gap':
			case 'image_size':
				return is_numeric( $v ) ? self::clamp( (int) round( (float) $v ), $k ) : $fallback;
			case 'delay':
				return is_numeric( $v ) ? round( (float) self::clamp( (float) $v, 'delay' ), 1 ) : $fallback;
			case 'exclude':
				return self::exclude( $v );
			case 'custom_css':
				if ( ! is_scalar( $v ) ) {
					return $fallback;
				}
				$c = self::clean_custom_css( substr( (string) $v, 0, 20000 ) );
				return $c['css'];
			case 'texts':
				if ( ! is_array( $v ) ) {
					return $fallback;
				}
				$t = $fallback;
				foreach ( self::LANGS as $l ) {
					foreach ( self::TEXT_KEYS as $tk ) {
						if ( isset( $v[ $l ] ) && is_array( $v[ $l ] ) && isset( $v[ $l ][ $tk ] ) && is_scalar( $v[ $l ][ $tk ] ) ) {
							$t[ $l ][ $tk ] = self::text( $v[ $l ][ $tk ] );
						}
					}
				}
				return $t;
		}
		return $fallback;
	}

	/** Exclusions: one path per line (or an array); full URLs -> their path; '*' only at the end. Max 100. */
	private static function exclude( $v ) {
		$lines = is_array( $v ) ? $v : preg_split( '/[\r\n]+/', is_scalar( $v ) ? (string) $v : '' );
		$out   = array();
		foreach ( $lines as $line ) {
			$p = is_scalar( $line ) ? trim( (string) $line ) : '';
			if ( preg_match( '#^https?://#i', $p ) ) {
				$path = parse_url( $p, PHP_URL_PATH );
				$p    = is_string( $path ) && '' !== $path ? $path : '/';
			}
			$p = preg_split( '/[?#]/', $p, 2 );
			$p = $p[0];
			if ( strlen( $p ) > 200 || ! preg_match( '#^/[A-Za-z0-9._~%!$&\'()+,;=:@/-]*\*?$#', $p ) ) {
				continue;
			}
			if ( ! in_array( $p, $out, true ) ) {
				$out[] = $p;
			}
			if ( count( $out ) >= 100 ) {
				break;
			}
		}
		return $out;
	}

	/** Does a selector list single out the Accept/Reject button (or the primary button class)? */
	private static function targets_button( $selector ) {
		$s = strtolower( $selector );
		if ( false !== strpos( $s, 'cmp__btn--primary' ) ) {
			return true;
		}
		// Positional selectors near the buttons can single one out (review M2).
		if ( self::reaches_choices( $s ) && preg_match( '/:(?:first|last|nth|only)-/', $s ) ) {
			return true;
		}
		if ( ! preg_match_all( '/\[\s*data-cmp-action\s*(?:([~|^$*]?=)\s*("[^"]*"|\'[^\']*\'|[^\]\s]*))?\s*(?:[is]\s*)?\]/', $s, $mm, PREG_SET_ORDER ) ) {
			return false !== strpos( $s, 'data-cmp-action' ) && false !== strpos( $s, '=' );
		}
		foreach ( $mm as $m ) {
			$op  = isset( $m[1] ) ? $m[1] : '';
			$val = isset( $m[2] ) ? trim( $m[2], '"\' ' ) : '';
			if ( '' === $op ) {
				continue; // [data-cmp-action]: every action button alike.
			}
			if ( '=' !== $op || in_array( $val, array( 'accept', 'reject' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/** Can a selector list reach the choice buttons or their containers? */
	private static function reaches_choices( $selector ) {
		return (bool) preg_match( '/cmp__btn|cmp__choices|cmp__actions|cmp__main|data-cmp-action|(?<![\w-])button(?![\w-])/i', $selector );
	}

	/**
	 * Relative address or data:image/ only. Any backslash is refused (a CSS escape
	 * like /\/host or /\2f host unescapes to //host); after trimming quotes and
	 * whitespace a scheme or a leading // is refused.
	 */
	private static function safe_url( $u ) {
		$u = trim( (string) $u, " \t\r\n\f\"'" );
		if ( false !== strpos( $u, '\\' ) ) {
			return false;
		}
		if ( preg_match( '#^data:image/#i', $u ) ) {
			return true;
		}
		return ! preg_match( '#^(?:[a-z][a-z0-9+.\-]*:|//)#i', $u );
	}

	/** --cmp-primary / -hover / -text for an accent and its text colour. */
	private static function accent_vars( $accent, $text ) {
		list( $h, $s, $l ) = self::hsl( $accent );
		// Hover moves away from the text colour, so the label keeps its contrast.
		$l2 = '#ffffff' === $text ? max( 0, $l - self::HOVER_STEP ) : min( 100, $l + self::HOVER_STEP );
		return array(
			'primary'       => $accent,
			'primary_hover' => self::from_hsl( $h, $s, $l2 ),
			'primary_text'  => $text,
		);
	}

	private static function decls( array $v ) {
		$out = array();
		foreach ( $v as $k => $val ) {
			$out[] = ( isset( self::VARS[ $k ] ) ? self::VARS[ $k ] : $k ) . ':' . $val;
		}
		return implode( ';', $out );
	}

	/** array( min ratio of text-on-accent and accent-on-bg, text colour ). */
	private static function score( $c, $bg, $fixed ) {
		$t = '' !== $fixed ? $fixed : self::text_for( $c );
		return array( min( CMP_Contrast::ratio( $c, $t ), CMP_Contrast::ratio( $c, $bg ) ), $t );
	}

	/** #ffffff or #000000, whichever contrasts more with $c. */
	private static function text_for( $c ) {
		return CMP_Contrast::ratio( $c, '#ffffff' ) >= CMP_Contrast::ratio( $c, '#000000' ) ? '#ffffff' : '#000000';
	}

	/** #rgb / #rrggbb -> lowercase #rrggbb; anything else -> ''. */
	private static function hex6( $v ) {
		if ( ! is_string( $v ) || ! CMP_Contrast::is_hex( $v ) ) {
			return '';
		}
		$h = strtolower( ltrim( trim( $v ), '#' ) );
		if ( 3 === strlen( $h ) ) {
			$h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
		}
		return '#' . $h;
	}

	/** #rrggbb -> array( h 0..360, s 0..100, l 0..100 ). */
	private static function hsl( $hex ) {
		$hex = self::hex6( $hex );
		$r   = hexdec( substr( $hex, 1, 2 ) ) / 255;
		$g   = hexdec( substr( $hex, 3, 2 ) ) / 255;
		$b   = hexdec( substr( $hex, 5, 2 ) ) / 255;
		$max = max( $r, $g, $b );
		$min = min( $r, $g, $b );
		$l   = ( $max + $min ) / 2;
		$h   = 0.0;
		$s   = 0.0;
		$d   = $max - $min;
		if ( $d > 0 ) {
			$s = $l > 0.5 ? $d / ( 2 - $max - $min ) : $d / ( $max + $min );
			if ( $max === $r ) {
				$h = ( $g - $b ) / $d + ( $g < $b ? 6 : 0 );
			} elseif ( $max === $g ) {
				$h = ( $b - $r ) / $d + 2;
			} else {
				$h = ( $r - $g ) / $d + 4;
			}
			$h *= 60;
		}
		return array( $h, $s * 100, $l * 100 );
	}

	/** HSL -> #rrggbb. */
	private static function from_hsl( $h, $s, $l ) {
		$s /= 100;
		$l /= 100;
		$c  = ( 1 - abs( 2 * $l - 1 ) ) * $s;
		$x  = $c * ( 1 - abs( fmod( $h / 60, 2 ) - 1 ) );
		$m  = $l - $c / 2;
		$seg = (int) floor( $h / 60 ) % 6;
		$rgb = array(
			array( $c, $x, 0 ),
			array( $x, $c, 0 ),
			array( 0, $c, $x ),
			array( 0, $x, $c ),
			array( $x, 0, $c ),
			array( $c, 0, $x ),
		);
		$out = '#';
		foreach ( $rgb[ $seg ] as $v ) {
			$out .= sprintf( '%02x', (int) max( 0, min( 255, round( ( $v + $m ) * 255 ) ) ) );
		}
		return $out;
	}

	private static function clamp( $v, $k ) {
		return max( self::RANGES[ $k ][0], min( self::RANGES[ $k ][1], $v ) );
	}

	/** 0.3 -> ".3", 0.55 -> ".55", 0 -> "0". */
	private static function num( $f ) {
		$s = rtrim( rtrim( sprintf( '%.2F', $f ), '0' ), '.' );
		return 0 === strpos( $s, '0.' ) ? substr( $s, 1 ) : $s;
	}

	private static function floor2( $r ) {
		return floor( $r * 100 ) / 100;
	}

	private static function text( $v ) {
		$t = trim( strip_tags( (string) $v ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $t, 0, 500, 'UTF-8' ) : substr( $t, 0, 500 );
	}
}
