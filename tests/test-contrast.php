<?php
/**
 * B5 Task 4: WCAG 2.2 AA on all four styles — colour contrast (1.4.3 / 1.4.11),
 * visible focus (2.4.7), stable links (no underline jump on hover) and
 * 24 x 24 px targets (2.5.8). Reads the real assets/css/banner.css.
 */
require_once __DIR__ . '/../includes/class-contrast.php';

$ct_css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/banner.css' );

// ---- The formula itself ----
cmp_assert( abs( CMP_Contrast::ratio( '#000000', '#ffffff' ) - 21.0 ) < 0.01, 'contrast: black/white = 21' );
cmp_assert( abs( CMP_Contrast::ratio( '#777777', '#ffffff' ) - 4.48 ) < 0.01, 'contrast: #777/#fff = 4.48' );
cmp_assert( abs( CMP_Contrast::ratio( '#fff', '#000' ) - 21.0 ) < 0.01, 'contrast: short #rgb form, order-independent' );
cmp_assert_eq( CMP_Contrast::blend( 'rgba(0, 0, 0, 0.5)', '#ffffff' ), '#808080', 'contrast: blend rgba over a background' );
cmp_assert_eq( CMP_Contrast::blend( '#123456', '#ffffff' ), '#123456', 'contrast: blend of an opaque hex is the hex' );

// ---- Tokens per style ----
$ct_neutral = CMP_Contrast::tokens( $ct_css, 'neutral' );
$ct_dark    = CMP_Contrast::tokens( $ct_css, 'dark' );
cmp_assert( isset( $ct_neutral['bg'], $ct_neutral['primary'] ), 'contrast: neutral tokens read from the base block' );
cmp_assert( isset( $ct_dark['bg'] ) && $ct_dark['bg'] !== $ct_neutral['bg'], 'contrast: dark preset overrides the base tokens' );
cmp_assert( isset( $ct_dark['radius'] ), 'contrast: preset inherits tokens it does not override' );

// The effective edge colour of the "off" toggle: its inset border ring when it has
// one, else the track background.
$ct_slider     = preg_match( '/\.cmp__toggle-slider\s*\{([^}]*)\}/', $ct_css, $ct_m ) ? $ct_m[1] : '';
$ct_toggle_var = preg_match( '/box-shadow:\s*inset 0 0 0 \d+(?:\.\d+)?px var\(--cmp-([a-z-]+)\)/', $ct_slider, $ct_m )
	? $ct_m[1]
	: ( preg_match( '/background:\s*var\(--cmp-([a-z-]+)\)/', $ct_slider, $ct_m ) ? $ct_m[1] : '' );
cmp_assert( '' !== $ct_toggle_var, 'contrast: toggle edge colour is a --cmp-* token' );
// The focus ring colour used on .cmp__btn.
$ct_ring_var = preg_match( '/\.cmp__btn:focus-visible\s*\{[^}]*outline:\s*2px solid var\(--cmp-([a-z-]+)\)/', $ct_css, $ct_m ) ? $ct_m[1] : '';
cmp_assert( '' !== $ct_ring_var, 'contrast: focus ring colour is a --cmp-* token' );

foreach ( array( 'neutral', 'warm', 'dark', 'egov' ) as $ct_style ) {
	$t = CMP_Contrast::tokens( $ct_css, $ct_style );
	$c = function ( $name ) use ( $t ) {
		return isset( $t[ $name ] ) ? $t[ $name ] : '#888888';
	};
	$text_pairs = array(
		array( 'dark', 'bg' ),
		array( 'dark', 'panel' ),
		array( 'muted', 'bg' ),
		array( 'muted', 'panel' ),
		array( 'primary-text', 'primary' ),
		array( 'primary-text', 'primary-hover' ),
		array( 'primary', 'bg' ),     // links + outline button
		array( 'primary', 'panel' ),  // "always load" embed button, badges in the category list
		array( 'primary', 'outline-hover' ), // outline button on hover
		array( 'dark', 'hover' ),     // ghost button on hover
	);
	foreach ( $text_pairs as $p ) {
		$r = CMP_Contrast::ratio( $c( $p[0] ), $c( $p[1] ) );
		cmp_assert( $r >= 4.5, sprintf( 'contrast %s: %s/%s %.2f >= 4.5', $ct_style, $p[0], $p[1], $r ) );
	}
	// Non-text (1.4.11): the off toggle and the focus ring, on the modal and on the category panel.
	foreach ( array( 'bg', 'panel' ) as $under ) {
		$r = CMP_Contrast::ratio( $c( $ct_toggle_var ), $c( $under ) );
		cmp_assert( $r >= 3, sprintf( 'contrast %s: toggle off (%s)/%s %.2f >= 3', $ct_style, $ct_toggle_var, $under, $r ) );
		$r = CMP_Contrast::ratio( $c( $ct_ring_var ), $c( $under ) );
		cmp_assert( $r >= 3, sprintf( 'contrast %s: focus ring (%s)/%s %.2f >= 3', $ct_style, $ct_ring_var, $under, $r ) );
	}
	// The white toggle knob stays distinguishable on the checked (primary) track.
	$r = CMP_Contrast::ratio( '#ffffff', $c( 'primary' ) );
	cmp_assert( $r >= 1.5, sprintf( 'contrast %s: knob/primary %.2f visible', $ct_style, $r ) );
	// rgba tokens (the scrim) resolve to an opaque colour once composited over the page.
	cmp_assert( 0 === strpos( CMP_Contrast::blend( $c( 'scrim' ), '#ffffff' ), '#' ), sprintf( 'contrast %s: scrim blends to a hex colour', $ct_style ) );
}

// ---- CSS rules: links, focus, targets ----
/** All declarations (merged, in source order) for an exact selector, outside @media. */
function cmp_ct_decls( $css, $selector ) {
	$css   = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
	$css   = (string) preg_replace( '/@media[^{]*\{(?:[^{}]*\{[^}]*\})*[^{}]*\}/', '', $css );
	$out   = array();
	if ( ! preg_match_all( '/([^{}]+)\{([^}]*)\}/', $css, $m, PREG_SET_ORDER ) ) {
		return $out;
	}
	foreach ( $m as $rule ) {
		$sels = array_map( 'trim', explode( ',', $rule[1] ) );
		if ( ! in_array( $selector, $sels, true ) ) {
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

// Links: underlined in the normal state; hover keeps the underline, size and box.
foreach ( array( '.cmp .cmp__link', '.cmp-light .cmp-light__link', '.cmp-embed__link' ) as $ct_link ) {
	$n = cmp_ct_decls( $ct_css, $ct_link );
	cmp_assert( isset( $n['text-decoration'] ) && 0 === strpos( $n['text-decoration'], 'underline' ), "links: {$ct_link} underlined in the normal state" );
	$h = cmp_ct_decls( $ct_css, $ct_link . ':hover' );
	foreach ( array( 'text-decoration', 'text-decoration-line', 'font-size', 'font-weight', 'padding', 'padding-bottom', 'border', 'border-bottom', 'line-height' ) as $prop ) {
		$same = ! isset( $h[ $prop ] ) || ( isset( $n[ $prop ] ) && $n[ $prop ] === $h[ $prop ] );
		cmp_assert( $same, "links: {$ct_link}:hover does not change {$prop}" );
	}
}
// No hover rule anywhere adds or removes the underline (the jump Radu saw).
cmp_assert( 0 === preg_match( '/:hover[^{]*\{[^}]*text-decoration:\s*none/', $ct_css ), 'links: no :hover rule removes the underline' );

// Focus: a visible :focus-visible ring on every interactive element.
$ct_focus = array( '.cmp__btn', '.cmp__link', '.cmp__collapse', '.cmp__toggle input', '.cmp-reopen', '.cmp-light__btn', '.cmp-light__link', '.cmp-embed__btn', '.cmp-embed__always', '.cmp-embed__link', '.cmp__always-item', '.cmp__more' );
foreach ( $ct_focus as $ct_sel ) {
	$ok = (bool) preg_match( '/' . preg_quote( $ct_sel, '/' ) . '(?: \+ [.a-z_-]+)?:focus-visible[^{]*\{[^}]*outline:\s*[23]px solid/', $ct_css )
		|| (bool) preg_match( '/' . preg_quote( $ct_sel, '/' ) . ':focus-visible(?: \+ [.a-z_-]+)?[^{]*\{[^}]*outline:\s*[23]px solid/', $ct_css );
	cmp_assert( $ok, "focus: {$ct_sel}:focus-visible draws a solid 2-3px outline" );
}
cmp_assert( false === strpos( $ct_css, 'rgba(0, 88, 210, 0.25)' ), 'focus: the faint 25% toggle halo is gone' );
// Embeds with a thumbnail: the ring turns white over the dark overlay.
cmp_assert( (bool) preg_match( '/\.cmp-embed--thumb \.cmp-embed__btn:focus-visible[^{]*\{[^}]*outline-color:\s*#fff/', $ct_css ), 'focus: thumbnail embeds use a white ring' );

/**
 * Target size (2.5.8): explicit width/height (or min-*) >= 24, or, when sized by
 * content, vertical padding + border + the smallest line box (13px * 1.2) >= 24.
 * Inline links inside a sentence (.cmp__link, .cmp-light__link) are exempt per
 * WCAG 2.5.8 ("Inline" exception), so they are not listed here.
 */
function cmp_ct_px( $v ) {
	return preg_match( '/^(\d+(?:\.\d+)?)px/', trim( (string) $v ), $m ) ? (float) $m[1] : 0.0;
}
function cmp_ct_pad( $d, $axis ) {
	if ( ! isset( $d['padding'] ) ) {
		return 0.0;
	}
	$p = preg_split( '/\s+/', trim( $d['padding'] ) );
	$v = cmp_ct_px( $p[0] );
	$h = isset( $p[1] ) ? cmp_ct_px( $p[1] ) : $v;
	return 'y' === $axis ? 2 * $v : 2 * $h;
}
$ct_targets = array( '.cmp__btn', '.cmp__collapse', '.cmp__toggle', '.cmp-reopen', '.cmp-light__btn', '.cmp-embed__btn', '.cmp-embed__always', '.cmp-embed__link', '.cmp__always-item', '.cmp__more' );
foreach ( $ct_targets as $ct_sel ) {
	$d      = cmp_ct_decls( $ct_css, $ct_sel );
	$border = ( isset( $d['border'] ) && preg_match( '/^1px/', $d['border'] ) ) ? 2.0 : 0.0;
	$line   = 13 * 1.2;
	$h      = max( cmp_ct_px( isset( $d['height'] ) ? $d['height'] : '' ), cmp_ct_px( isset( $d['min-height'] ) ? $d['min-height'] : '' ), cmp_ct_pad( $d, 'y' ) + $border + $line );
	$w      = max( cmp_ct_px( isset( $d['width'] ) ? $d['width'] : '' ), cmp_ct_px( isset( $d['min-width'] ) ? $d['min-width'] : '' ), cmp_ct_pad( $d, 'x' ) + $border );
	cmp_assert( $h >= 24 && $w >= 24, sprintf( 'targets: %s at least 24x24 (%.1f x %.1f)', $ct_sel, $w, $h ) );
}
