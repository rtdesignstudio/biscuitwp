<?php
/**
 * B5 final-review fix wave: I1 (category toggles named and described, the
 * "always active" mark announced), I2 (the embed box stays when it loads: no
 * layout shift), M3 (forced colours), M5 (the chevron fallback survives csso).
 * Uses cmp_a11y_render() from tests/test-a11y.php (loaded before this file).
 */
$b5f_root = dirname( __DIR__ ) . '/';

/** Declarations of an exact selector outside @media / @supports (comments stripped), merged. */
function cmp_b5f_decls( $css, $sel ) {
	$css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) $css );
	$css = (string) preg_replace( '/@(?:media|supports)[^{]*\{(?:[^{}]*\{[^}]*\})*[^{}]*\}/', '', $css );
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
/** The body of the first `@media <query>` block (brace-matched), or ''. */
function cmp_b5f_media( $css, $query ) {
	$i = strpos( (string) $css, '@media ' . $query );
	if ( false === $i ) {
		return '';
	}
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
	return substr( $css, $j + 1, $k - $j - 1 );
}

// ---- I1: each toggle is named by its category and described by its text ----
$b5f_html = cmp_a11y_render( 'banner-bottom.php' );
cmp_assert( 1 === preg_match( '#<span class="cmp__cat-label" id="cmp-cat-statistics-label">S</span>#', $b5f_html ), 'I1: category name has id="cmp-cat-<slug>-label"' );
cmp_assert( 1 === preg_match( '#<div class="cmp__cat-desc" id="cmp-cat-statistics-desc">s</div>#', $b5f_html ), 'I1: category description has id="cmp-cat-<slug>-desc"' );
cmp_assert( 1 === preg_match( '#<input type="checkbox" role="switch" id="cmp-cat-statistics" value="statistics" aria-labelledby="cmp-cat-statistics-label" aria-describedby="cmp-cat-statistics-desc" data-cmp-cat>#', $b5f_html ), 'I1: toggle = native checkbox, role="switch", aria-labelledby the name, aria-describedby the description' );
cmp_assert( 1 === preg_match( '#<span class="cmp__cat-locked" role="img" aria-label="[^"]+">#', $b5f_html ), 'I1: the "always active" mark has role="img" (its aria-label is announced)' );
cmp_assert( 1 === preg_match( '#<span class="cmp__cat-label" id="cmp-cat-necessary-label">N</span>#', $b5f_html ) && 1 === preg_match( '#<div class="cmp__cat-desc" id="cmp-cat-necessary-desc">n</div>#', $b5f_html ), 'I1: required category gets the same ids' );
foreach ( array( 'cmp-cat-statistics-label', 'cmp-cat-statistics-desc', 'cmp-cat-necessary-label' ) as $b5f_id ) {
	cmp_assert( 1 === substr_count( $b5f_html, 'id="' . $b5f_id . '"' ), "I1: id {$b5f_id} is unique" );
}
// The hidden re-consent marker is never part of the description (hidden IDREFs are still read).
cmp_assert( 0 === preg_match( '#aria-describedby="[^"]*new#', $b5f_html ), 'I1: aria-describedby does not point at the hidden "new" marker' );

// ---- I2: the loaded embed keeps its box (same size), the iframe fills it ----
$b5f_css = (string) file_get_contents( $b5f_root . 'assets/css/banner.css' );
$b5f_d   = cmp_b5f_decls( $b5f_css, '.cmp-embed--loaded' );
cmp_assert( isset( $b5f_d['padding'] ) && '0' === $b5f_d['padding'], 'I2: .cmp-embed--loaded padding: 0' );
cmp_assert( isset( $b5f_d['border'] ) && '0' === $b5f_d['border'], 'I2: .cmp-embed--loaded border: 0' );
cmp_assert( isset( $b5f_d['background'] ) && 'none' === $b5f_d['background'], 'I2: .cmp-embed--loaded background: none' );
cmp_assert( isset( $b5f_d['display'] ) && 'block' === $b5f_d['display'], 'I2: .cmp-embed--loaded display: block' );
cmp_assert( ! isset( $b5f_d['aspect-ratio'] ) && ! isset( $b5f_d['height'] ) && ! isset( $b5f_d['margin'] ) && ! isset( $b5f_d['max-width'] ), 'I2: .cmp-embed--loaded keeps the placeholder size (aspect-ratio, margin, max-width untouched)' );
$b5f_d = cmp_b5f_decls( $b5f_css, '.cmp-embed--loaded > iframe' );
cmp_assert( isset( $b5f_d['display'], $b5f_d['width'], $b5f_d['height'], $b5f_d['border'] ) && 'block' === $b5f_d['display'] && '100%' === $b5f_d['width'] && '100%' === $b5f_d['height'] && '0' === $b5f_d['border'], 'I2: the iframe fills the box (display:block; width/height 100%; border 0)' );
cmp_assert( isset( $b5f_d['position'] ) && 'absolute' === $b5f_d['position'] && 'relative' === ( cmp_b5f_decls( $b5f_css, '.cmp-embed--loaded' )['position'] ?? '' ), 'I2: the iframe is positioned inside the box, so its width / height attributes never resize it' );

// ---- M3: forced colours keep the toggle state and focus visible ----
$b5f_fc = cmp_b5f_media( (string) preg_replace( '#/\*.*?\*/#s', '', $b5f_css ), '(forced-colors: active)' );
cmp_assert( '' !== $b5f_fc, 'M3: banner.css has @media (forced-colors: active)' );
cmp_assert( 1 === preg_match( '/\.cmp__toggle-slider\s*\{[^}]*border:\s*2px solid CanvasText/', $b5f_fc ), 'M3: forced colours: the track has a CanvasText border' );
cmp_assert( 1 === preg_match( '/\.cmp__toggle-slider::before\s*\{[^}]*background:\s*CanvasText/', $b5f_fc ), 'M3: forced colours: the knob is CanvasText' );
cmp_assert( 1 === preg_match( '/\.cmp__toggle input:checked \+ \.cmp__toggle-slider\s*\{[^}]*background:\s*Highlight/', $b5f_fc ), 'M3: forced colours: the "on" track is Highlight' );
cmp_assert( 1 === preg_match( '/\.cmp__toggle input:focus-visible \+ \.cmp__toggle-slider\s*\{[^}]*outline:\s*2px solid CanvasText/', $b5f_fc ), 'M3: forced colours: the toggle focus ring is CanvasText' );
cmp_assert( false !== strpos( (string) file_get_contents( $b5f_root . 'assets/css/banner.min.css' ), 'forced-colors:active' ), 'M3: banner.min.css keeps the forced-colors rules' );

// ---- M5 (fix wave add-on): the "Settings ›" chevron is markup, hidden from screen readers ----
// <span class="cmp__chev" aria-hidden="true"> &#8250;</span> (" ›") in the prefs button (template + standalone);
// no generated content for it in CSS; shown on phones only.
foreach ( array( 'assets/css/banner.min.css', 'standalone/cmp.min.css' ) as $b5f_f ) {
	$b5f_min = (string) file_get_contents( $b5f_root . $b5f_f );
	cmp_assert( false === stripos( $b5f_min, '203A' ) && false === strpos( $b5f_min, '@supports (content:""/"")' ), "M5: {$b5f_f} has no ::after chevron content" );
	cmp_assert( 1 === preg_match( '#\.cmp__chev(?:,[^{}]*)?\{display:none\}#', $b5f_min ), "M5: {$b5f_f} hides the chevron by default (desktop)" );
	cmp_assert( 1 === preg_match( '#@media \(max-width:575px\)\{[^@]*\.cmp__chev\{display:inline\}#', $b5f_min ), "M5: {$b5f_f} shows the chevron on phones" );
}
$b5f_chev = '<span class="cmp__chev" aria-hidden="true"> &#8250;</span>';
cmp_assert( 1 === substr_count( (string) file_get_contents( $b5f_root . 'templates/banner-bottom.php' ), "'prefs' ) ) ); ?>" . $b5f_chev . '</button>' ), 'M5: banner-bottom.php prefs button ends with the aria-hidden chevron span' );
cmp_assert( 1 === substr_count( (string) file_get_contents( $b5f_root . 'standalone/cmp.js' ), "escapeHtml(cfg.texts.prefs) + '" . $b5f_chev . "</button>'" ), 'M5: standalone renderPro prefs button ends with the aria-hidden chevron span' );
