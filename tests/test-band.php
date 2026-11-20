<?php
/**
 * B5 Task 5: the two-row cookie band on phones (<= 575 px). "Details" on demand
 * (texts, markup), the phone CSS (2-line clamp, 50vh, 44 px choices, no column
 * stack), scrollbar compensation while the page is locked.
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once __DIR__ . '/../includes/modules/class-lang.php';

// Texts: the exact values, in every language.
cmp_assert_eq( CMP_Lang::text( 'details', 'ro' ), 'Detalii', 'band: details text ro' );
cmp_assert_eq( CMP_Lang::text( 'details', 'ru' ), 'Подробнее', 'band: details text ru' );
cmp_assert_eq( CMP_Lang::text( 'details', 'en' ), 'Details', 'band: details text en' );

// Markup (fix wave M1): the button is the first item of the action row, so the
// DOM order is the phone's visual order (Details, Settings, Reject, Accept).
$band_tpl = (string) file_get_contents( __DIR__ . '/../templates/banner-bottom.php' );
cmp_assert(
	1 === preg_match( '#<div class="cmp__actions">\s*(?:<\?php //[^\n]*\?>\s*)?<button type="button" class="cmp__more" data-cmp-action="details" aria-expanded="false" aria-controls="cmp-intro"><\?php echo esc_html\( CMP_Lang::text\( \'details\' \) \); \?></button>\s*<button type="button" class="cmp__btn cmp__btn--ghost" data-cmp-action="prefs">#s', $band_tpl ),
	'band M1: Details is the first item of .cmp__actions, right before Settings (aria-expanded=false, aria-controls=cmp-intro, text from CMP_Lang)'
);
cmp_assert( 1 === preg_match( '#</p>\s*(?:<\?php //[^\n]*\?>\s*)?<div class="cmp__actions">#', $band_tpl ), 'band M1: nothing between the intro and the action row' );
cmp_assert( 1 === substr_count( $band_tpl, 'data-cmp-action="details"' ), 'band M1: a single Details button (no duplicate)' );
cmp_assert( false !== strpos( $band_tpl, 'data-cmp-action="details"' ), 'band: template data-cmp-action="details"' );
cmp_assert( false !== strpos( $band_tpl, 'aria-controls="cmp-intro"' ), 'band: template aria-controls="cmp-intro"' );
// The title stays the dialog's name (only hidden visually on phones).
cmp_assert( 1 === preg_match( '/<div class="cmp__modal" role="dialog".*?aria-labelledby="cmp-title"/', $band_tpl ), 'band: dialog still named by #cmp-title' );

// ---- CSS ----
$band_css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( __DIR__ . '/../assets/css/banner.css' ) );

/** The bodies of every `@media (max-width: 575px)` block, joined. */
function cmp_band_phone_css( $css ) {
	$out = '';
	$off = 0;
	while ( false !== ( $i = strpos( $css, '@media (max-width: 575px)', $off ) ) ) {
		$j     = strpos( $css, '{', $i );
		$depth = 0;
		for ( $k = $j; $k < strlen( $css ); $k++ ) {
			if ( '{' === $css[ $k ] ) {
				$depth++;
			} elseif ( '}' === $css[ $k ] ) {
				$depth--;
				if ( 0 === $depth ) {
					break;
				}
			}
		}
		$out .= substr( $css, $j + 1, $k - $j - 1 ) . "\n";
		$off  = $k;
	}
	return $out;
}
/** Declarations of the rules whose selector list contains $sel (exact item), merged. */
function cmp_band_decls( $css, $sel ) {
	$out = array();
	preg_match_all( '/([^{}]+)\{([^}]*)\}/', $css, $m, PREG_SET_ORDER );
	foreach ( $m as $rule ) {
		if ( ! in_array( $sel, array_map( 'trim', explode( ',', $rule[1] ) ), true ) ) {
			continue;
		}
		foreach ( explode( ';', $rule[2] ) as $d ) {
			$kv = explode( ':', $d, 2 );
			if ( 2 === count( $kv ) ) {
				$out[ strtolower( trim( $kv[0] ) ) ] = trim( $kv[1] );
			}
		}
	}
	return $out;
}
$band_phone = cmp_band_phone_css( $band_css );
$band_base  = (string) preg_replace( '/@media[^{]*\{(?:[^{}]*\{[^}]*\})*[^{}]*\}/', '', $band_css );
cmp_assert( '' !== trim( $band_phone ), 'band: banner.css has the phone media query' );

$band_d = cmp_band_decls( $band_phone, '.cmp__main .cmp__text' );
cmp_assert( isset( $band_d['-webkit-line-clamp'] ) && '2' === $band_d['-webkit-line-clamp'], 'band: phone intro clamped to 2 lines (-webkit-line-clamp: 2)' );
cmp_assert( isset( $band_d['overflow'] ) && 'hidden' === $band_d['overflow'], 'band: clamped intro hides the overflow' );
$band_d = cmp_band_decls( $band_phone, '.cmp__main.is-expanded .cmp__text' );
cmp_assert( isset( $band_d['max-height'] ) && '50vh' === $band_d['max-height'], 'band: expanded intro max-height: 50vh' );
cmp_assert( isset( $band_d['overflow-y'] ) && 'auto' === $band_d['overflow-y'], 'band: expanded intro scrolls inside (overflow-y: auto)' );
cmp_assert( isset( $band_d['-webkit-line-clamp'] ) && 'none' === $band_d['-webkit-line-clamp'], 'band: expanded intro is not clamped' );

$band_d = cmp_band_decls( $band_phone, '.cmp__choices .cmp__btn' );
cmp_assert( isset( $band_d['min-height'] ) && '44px' === $band_d['min-height'], 'band: phone choice buttons min-height: 44px' );
cmp_assert( isset( $band_d['flex'] ) && '1 1 0' === $band_d['flex'], 'band: phone choice buttons in two equal columns (flex: 1 1 0)' );
cmp_assert( isset( $band_d['min-width'] ) && '0' === $band_d['min-width'], 'band: phone choice buttons stay equal with a long word (min-width: 0)' );

// No column stack any more: Reject / Accept side by side, Settings wraps.
$band_col = false;
preg_match_all( '/([^{}]+)\{([^}]*)\}/', $band_phone, $band_m, PREG_SET_ORDER );
foreach ( $band_m as $band_rule ) {
	if ( false !== strpos( $band_rule[1], '.cmp__actions' ) && preg_match( '/flex-direction:\s*column/', $band_rule[2] ) ) {
		$band_col = true;
	}
}
cmp_assert( ! $band_col, 'band: no flex-direction: column on .cmp__actions on phones' );
$band_d = cmp_band_decls( $band_phone, '.cmp__actions' );
cmp_assert( isset( $band_d['flex-wrap'] ) && 'wrap' === $band_d['flex-wrap'], 'band: phone action row wraps' );
$band_d = cmp_band_decls( $band_phone, '.cmp__actions .cmp__choices' );
cmp_assert( ! isset( $band_d['order'] ), 'band M1: no order trick, the DOM order is the visual order' );
cmp_assert( isset( $band_d['flex'] ) && '1 1 100%' === $band_d['flex'], 'band M1: Reject / Accept on their own full-width row, below Details / Settings' );
cmp_assert( 0 === preg_match( '/(?<![-a-z])order\s*:/i', $band_phone ), 'band M1: no `order` anywhere in the phone CSS' );
$band_d = cmp_band_decls( $band_phone, '.cmp__more' );
cmp_assert( isset( $band_d['margin'] ) && '0' === $band_d['margin'], 'band M1: Details sits on the row with Settings (margin: 0)' );
$band_d = cmp_band_decls( $band_phone, '.cmp__actions [data-cmp-action="prefs"]' );
cmp_assert( isset( $band_d['margin-left'] ) && 'auto' === $band_d['margin-left'], 'band: Settings pushed to the right (and onto its own row when it does not fit)' );
cmp_assert( isset( $band_d['min-height'] ) && '24px' === $band_d['min-height'], 'band: Settings as text keeps a 24 px target' );

// The title: visually hidden on phones, still in the page.
$band_d = cmp_band_decls( $band_phone, '.cmp__main .cmp__title' );
cmp_assert( isset( $band_d['position'], $band_d['clip'], $band_d['width'] ) && 'absolute' === $band_d['position'] && '1px' === $band_d['width'], 'band: phone title visually hidden (.cmp__sr style)' );

// "Details": hidden above 575 px, shown on phones.
$band_d = cmp_band_decls( $band_base, '.cmp__more' );
cmp_assert( isset( $band_d['display'] ) && 'none' === $band_d['display'], 'band: Details hidden above 575 px' );
$band_d = cmp_band_decls( $band_phone, '.cmp__more' );
cmp_assert( isset( $band_d['display'] ) && 'none' !== $band_d['display'], 'band: Details shown on phones' );

// Scrollbar compensation while the page is locked (CLS).
$band_d = cmp_band_decls( $band_base, 'body.cmp-has-banner' );
cmp_assert( isset( $band_d['overflow'] ) && 'hidden' === $band_d['overflow'], 'band: body.cmp-has-banner overflow: hidden' );
cmp_assert( isset( $band_d['padding-right'] ) && 1 === preg_match( '/^var\(--cmp-sbw, ?0px\)$/', $band_d['padding-right'] ), 'band: body.cmp-has-banner padding-right: var(--cmp-sbw, 0px)' );

// The standalone copy carries the same band.
$band_sa = (string) @file_get_contents( __DIR__ . '/../standalone/cmp.css' );
cmp_assert( false !== strpos( $band_sa, '.cmp__more' ) && false !== strpos( $band_sa, 'var(--cmp-sbw,0px)' ), 'band: standalone/cmp.css has the band' );

// Fix round 1: RU at 200% zoom hyphenates instead of cutting words anywhere.
$band_d = cmp_band_decls( $band_phone, '.cmp__choices .cmp__btn' );
cmp_assert( isset( $band_d['hyphens'] ) && 'auto' === $band_d['hyphens'], 'band fix: phone choice buttons hyphens: auto' );
$band_d = cmp_band_decls( $band_phone, '.cmp__main .cmp__text' );
cmp_assert( isset( $band_d['hyphens'] ) && 'auto' === $band_d['hyphens'], 'band fix: phone intro hyphens: auto' );

// Fix round 1: the minified CSS is pure ASCII (non-ASCII in content strings re-escaped by the build).
foreach ( array( 'assets/css/banner.min.css', 'standalone/cmp.min.css' ) as $band_f ) {
	$band_min = (string) @file_get_contents( __DIR__ . '/../' . $band_f );
	cmp_assert( '' !== $band_min && 0 === preg_match( '/[^\x00-\x7F]/', $band_min ), "band fix: $band_f is pure ASCII" );
	// Fix wave add-on: the Settings chevron is markup now (aria-hidden span), not CSS content.
	cmp_assert( false === stripos( $band_min, '\\203a' ) && false !== strpos( $band_min, '.cmp__chev' ), "band fix: $band_f has no chevron content, only the .cmp__chev display rules" );
}
