<?php
/**
 * B6 Task 2: the banner CSS behind the appearance classes and variables.
 * - every class CMP_Appearance::container_classes() emits has CSS (or is a
 *   documented default that must have none: today's look);
 * - a legacy site (Brand, empty inline block) gets today's declarations on
 *   every selector of the B5 stylesheet (tests/fixtures/banner-b5.css);
 * - bar only with its class, phone band untouched, blur fallback, button
 *   styles on both decisions, text style keeps focus + targets, dark auto,
 *   animations off under prefers-reduced-motion.
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once __DIR__ . '/../includes/class-contrast.php';
require_once __DIR__ . '/../includes/class-appearance.php';

$apc_root = dirname( __DIR__ ) . '/';
$apc_css  = (string) file_get_contents( $apc_root . 'assets/css/banner.css' );
$apc_old  = (string) file_get_contents( __DIR__ . '/fixtures/banner-b5.css' );

/**
 * Normalised value: one space, ", " after commas, no space inside parentheses;
 * same-value literals spelled alike (fix wave budget reclaim: #fff = #ffffff,
 * .55 = 0.55), so a shorter spelling of today's value compares equal.
 */
function cmp_apc_norm( $v ) {
	$v = trim( (string) preg_replace( '/\s+/', ' ', (string) $v ) );
	$v = (string) preg_replace( '/\s*,\s*/', ', ', $v );
	$v = (string) preg_replace( '/\(\s+/', '(', $v );
	$v = (string) preg_replace( '/\s+\)/', ')', $v );
	$v = (string) preg_replace( '/(?<![\w.])\.(\d)/', '0.$1', $v );
	return (string) preg_replace_callback(
		'/#([0-9a-f])([0-9a-f])([0-9a-f])(?![0-9a-f])/i',
		function ( $m ) {
			return strtolower( '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3] );
		},
		$v
	);
}

/** Declarations of a rule body, split on ";" outside parentheses: list of [prop, value]. */
function cmp_apc_decls( $body ) {
	$out   = array();
	$depth = 0;
	$cur   = '';
	$len   = strlen( $body );
	for ( $i = 0; $i <= $len; $i++ ) {
		$ch = $i < $len ? $body[ $i ] : ';';
		if ( '(' === $ch ) {
			$depth++;
		} elseif ( ')' === $ch ) {
			$depth--;
		}
		if ( ';' === $ch && 0 === $depth ) {
			$kv = explode( ':', $cur, 2 );
			if ( 2 === count( $kv ) && '' !== trim( $kv[0] ) ) {
				$out[] = array( strtolower( trim( $kv[0] ) ), cmp_apc_norm( $kv[1] ) );
			}
			$cur = '';
			continue;
		}
		$cur .= $ch;
	}
	return $out;
}

/**
 * Every style rule: array( ctx, selector, decls[], order ). ctx = the chain of
 * @media / @supports preludes ('' at top level). @keyframes are collected apart.
 */
function cmp_apc_parse( $css, &$keyframes = null ) {
	$css       = (string) preg_replace( '#/\*.*?\*/#s', '', (string) $css );
	$rules     = array();
	$keyframes = array();
	$stack     = array();
	$i         = 0;
	$n         = strlen( $css );
	$order     = 0;
	while ( $i < $n ) {
		$open  = strpos( $css, '{', $i );
		$close = strpos( $css, '}', $i );
		if ( false === $close && false === $open ) {
			break;
		}
		if ( false === $open || ( false !== $close && $close < $open ) ) {
			array_pop( $stack ); // end of an @-block
			$i = $close + 1;
			continue;
		}
		$prelude = cmp_apc_norm( substr( $css, $i, $open - $i ) );
		if ( 0 === strpos( $prelude, '@keyframes' ) ) {
			$depth = 0;
			for ( $k = $open; $k < $n; $k++ ) {
				if ( '{' === $css[ $k ] ) {
					$depth++;
				} elseif ( '}' === $css[ $k ] ) {
					$depth--;
					if ( 0 === $depth ) {
						break;
					}
				}
			}
			$keyframes[ trim( substr( $prelude, 10 ) ) ] = substr( $css, $open + 1, $k - $open - 1 );
			$i = $k + 1;
			continue;
		}
		if ( '@' === substr( $prelude, 0, 1 ) ) {
			$stack[] = $prelude;
			$i       = $open + 1;
			continue;
		}
		$end  = strpos( $css, '}', $open );
		$body = substr( $css, $open + 1, $end - $open - 1 );
		$ctx  = implode( ' ', $stack );
		foreach ( explode( ',', $prelude ) as $sel ) {
			$rules[] = array( $ctx, cmp_apc_norm( $sel ), cmp_apc_decls( $body ), $order );
		}
		$order++;
		$i = $end + 1;
	}
	return $rules;
}

/** ctx|selector => merged declarations (source order). */
function cmp_apc_merge( $rules ) {
	$out = array();
	foreach ( $rules as $r ) {
		$key = $r[0] . '|' . $r[1];
		if ( ! isset( $out[ $key ] ) ) {
			$out[ $key ] = array();
		}
		foreach ( $r[2] as $d ) {
			$out[ $key ][ $d[0] ] = $d[1];
		}
	}
	return $out;
}

/**
 * Resolve var() like the browser would on a legacy site: names in $inline_only
 * are never set (empty inline block), names in $locals take their local value
 * (a value that resolves to invalid makes the var() take its fallback). Any other
 * var() stays as written. null = invalid at computed-value time.
 */
function cmp_apc_resolve( $v, $locals, $inline_only, $depth = 0 ) {
	$out = '';
	$i   = 0;
	$n   = strlen( $v );
	while ( false !== ( $p = strpos( $v, 'var(', $i ) ) ) {
		$out .= substr( $v, $i, $p - $i );
		$d    = 0;
		for ( $k = $p + 3; $k < $n; $k++ ) {
			if ( '(' === $v[ $k ] ) {
				$d++;
			} elseif ( ')' === $v[ $k ] ) {
				$d--;
				if ( 0 === $d ) {
					break;
				}
			}
		}
		$inner = substr( $v, $p + 4, $k - $p - 4 );
		$c     = -1;
		$d     = 0;
		for ( $j = 0, $m = strlen( $inner ); $j < $m; $j++ ) {
			if ( '(' === $inner[ $j ] ) {
				$d++;
			} elseif ( ')' === $inner[ $j ] ) {
				$d--;
			} elseif ( ',' === $inner[ $j ] && 0 === $d ) {
				$c = $j;
				break;
			}
		}
		$name  = trim( $c < 0 ? $inner : substr( $inner, 0, $c ) );
		$fb    = $c < 0 ? null : trim( substr( $inner, $c + 1 ) );
		$known = false;
		$val   = null;
		if ( in_array( $name, $inline_only, true ) ) {
			$known = true;
		} elseif ( isset( $locals[ $name ] ) && $depth < 10 ) {
			$known = true;
			$val   = cmp_apc_resolve( $locals[ $name ], $locals, $inline_only, $depth + 1 );
		}
		if ( ! $known ) {
			$rep = 'var(' . $inner . ')';
		} elseif ( null !== $val ) {
			$rep = $val;
		} elseif ( null !== $fb ) {
			$rep = cmp_apc_resolve( $fb, $locals, $inline_only, $depth + 1 );
			if ( null === $rep ) {
				return null;
			}
		} else {
			return null;
		}
		$out .= $rep;
		$i    = $k + 1;
	}
	return cmp_apc_norm( $out . substr( $v, $i ) );
}

/** Custom properties a rule's element gets: top-level rules for the selector, then the same ctx. */
function cmp_apc_locals( $merged, $ctx, $sel ) {
	$l = array();
	foreach ( array( '|' . $sel, $ctx . '|' . $sel ) as $key ) {
		if ( isset( $merged[ $key ] ) ) {
			foreach ( $merged[ $key ] as $p => $v ) {
				if ( 0 === strpos( $p, '--' ) ) {
					$l[ $p ] = $v;
				}
			}
		}
	}
	return $l;
}

/** True when a selector uses the class (whole class name). */
function cmp_apc_has_class( $sel, $class ) {
	return 1 === preg_match( '/\.' . preg_quote( $class, '/' ) . '(?![\w-])/', $sel );
}

$apc_rules  = cmp_apc_parse( $apc_css, $apc_kf );
$apc_orules = cmp_apc_parse( $apc_old );
$apc_new    = cmp_apc_merge( $apc_rules );
$apc_prev   = cmp_apc_merge( $apc_orules );

// ---- Every emitted class has CSS (or is a default with none) ----
$apc_classes = array();
$apc_vary    = array(
	'style'    => CMP_Appearance::STYLES,
	'palette'  => CMP_Appearance::PALETTES,
	'theme'    => CMP_Appearance::THEMES,
	'buttons'  => CMP_Appearance::BUTTONS,
	'anim'     => CMP_Appearance::ANIMS,
	'backdrop' => CMP_Appearance::BACKDROPS,
);
foreach ( array( 'pro', 'light' ) as $apc_mode ) {
	foreach ( $apc_vary as $apc_k => $apc_vals ) {
		foreach ( $apc_vals as $apc_v ) {
			$apc_a = CMP_Appearance::defaults( 'brand' );
			$apc_a[ $apc_k ] = $apc_v;
			$apc_classes = array_merge( $apc_classes, CMP_Appearance::container_classes( $apc_a, $apc_mode ) );
		}
	}
	foreach ( CMP_Appearance::FORMATS as $apc_f => $apc_positions ) {
		foreach ( $apc_positions as $apc_p ) {
			$apc_a = array_merge( CMP_Appearance::defaults( 'brand' ), array( 'format' => $apc_f, 'position' => $apc_p ) );
			$apc_classes = array_merge( $apc_classes, CMP_Appearance::container_classes( $apc_a, $apc_mode ) );
		}
	}
}
$apc_classes = array_values( array_unique( $apc_classes ) );
// Today's look (Brand, legacy positions): these classes are on every migrated site, so
// they must carry no rule at all; the look comes from the B5 rules + variable fallbacks.
$apc_defaults = array( 'cmp--neutral', 'cmp--style-brand', 'cmp--fmt-modal', 'cmp--fmt-card', 'cmp--btn-solid', 'cmp--anim-none', 'cmp--theme-light', 'cmp--backdrop-blur' );
// "Like the site": the banner JS (Task 3) adds .cmp--dark when the site's selector matches.
// "Dark": the server adds .cmp--dark (or the palette is Dark), so .cmp--theme-dark needs no rule.
$apc_js = array( 'cmp--theme-site', 'cmp--theme-dark' );
// Centred window: .cmp itself centres (align-items:center), so the class needs no rule
// (fix wave budget reclaim: the redundant rule was removed, computed value identical).
$apc_js[] = 'cmp--pos-middle-center';
foreach ( CMP_Appearance::PALETTES as $apc_p ) {
	$apc_cl = CMP_Appearance::container_classes( array_merge( CMP_Appearance::defaults( 'brand' ), array( 'theme' => 'dark', 'palette' => $apc_p ) ), 'pro' );
	cmp_assert( in_array( 'cmp--dark', $apc_cl, true ), "appearance css: theme dark + {$apc_p} carries .cmp--dark" );
}
$apc_all_sels = array();
foreach ( $apc_rules as $apc_r ) {
	$apc_all_sels[] = $apc_r[1];
}
$apc_sel_text = implode( "\n", $apc_all_sels );
cmp_assert( count( $apc_classes ) >= 30, 'appearance css: class set collected (' . count( $apc_classes ) . ')' );
foreach ( $apc_classes as $apc_c ) {
	$apc_used = cmp_apc_has_class( $apc_sel_text, $apc_c );
	if ( in_array( $apc_c, $apc_defaults, true ) || in_array( $apc_c, $apc_js, true ) ) {
		cmp_assert( ! $apc_used, "appearance css: default class .{$apc_c} has no rule (today's look)" );
	} else {
		cmp_assert( $apc_used, "appearance css: class .{$apc_c} has CSS" );
	}
}
cmp_assert( cmp_apc_has_class( $apc_sel_text, 'cmp--dark' ), 'appearance css: .cmp--dark (theme dark / like the site) has CSS' );
cmp_assert( 1 === preg_match( '/\n\.cmp \{[^}]*align-items: center;/', $apc_css ), 'appearance css: .cmp centres (modal-center / middle-center need no rule)' );

// ---- The variables are wired, with today's values as fallbacks ----
foreach ( array( 'radius', 'shadow', 'maxw', 'gap', 'dim', 'blur', 'font' ) as $apc_k ) {
	cmp_assert( false !== strpos( $apc_css, 'var(' . CMP_Appearance::VARS[ $apc_k ] ), "appearance css: banner.css uses var({$apc_k} = " . CMP_Appearance::VARS[ $apc_k ] . ')' );
}
// Variables that only the inline block sets (never banner.css): their fallback = today.
$apc_inline_only = array();
foreach ( CMP_Appearance::VARS as $apc_v ) {
	if ( 0 === preg_match( '/' . preg_quote( $apc_v, '/' ) . '\s*:/', $apc_css ) ) {
		$apc_inline_only[] = $apc_v;
	}
}
sort( $apc_inline_only );
cmp_assert_eq( $apc_inline_only, array( '--cmp-blur', '--cmp-dim', '--cmp-font', '--cmp-gap', '--cmp-maxw', '--cmp-shadow' ), 'appearance css: shape variables are set only by the inline block' );

// ---- Legacy = today: every B5 selector keeps its computed declarations ----
// The one intended change (spec B6.5 + Task 2 brief): no backdrop blur on phones.
$apc_allowed_new = array(
	'@media (max-width: 575px)|.cmp' => array( '-webkit-backdrop-filter' => 'none', 'backdrop-filter' => 'none' ),
	// The Light card's own corner default (16px, as today) replaces the shared 12px token
	// on the card; nothing inside the card reads --cmp-radius (asserted below).
	'|.cmp-light' => array( '--cmp-radius' => '16px' ),
);
// Rules removed on purpose (fix wave budget reclaim), with why the look is unchanged.
$apc_removed_ok = array(
	'|.cmp--pos-modal-center'  => 'align-items:center = .cmp\'s own value',
	'|.cmp--pos-middle-center' => 'align-items:center = .cmp\'s own value',
	// The chevron moved from ::after content to markup (<span class="cmp__chev" aria-hidden="true"> ›</span>,
	// shown on phones only): same glyph and spacing, never read by screen readers.
	'@supports (content: "" / "") @media (max-width: 575px)|.cmp__actions [data-cmp-action="prefs"]::after' => 'chevron is markup now',
	'@media (max-width: 575px)|.cmp__actions [data-cmp-action="prefs"]::after' => 'chevron is markup now',
);
// Classes a legacy (Brand, migrated) site never has.
$apc_new_only = array( 'cmp--fmt-bar', 'cmp--btn-outline', 'cmp--btn-text', 'cmp--style-minimal', 'cmp--style-friendly', 'cmp--anim-fade', 'cmp--anim-slide', 'cmp--theme-auto', 'cmp--backdrop-dim', 'cmp--backdrop-none', 'cmp-reopen--text', 'cmp--guard' ); // cmp--guard: added only by the JS guard on a failure (B6.18).
$apc_diff = array();
foreach ( $apc_prev as $apc_key => $apc_od ) {
	list( $apc_ctx, $apc_sel ) = explode( '|', $apc_key, 2 );
	if ( ! isset( $apc_new[ $apc_key ] ) ) {
		if ( ! isset( $apc_removed_ok[ $apc_key ] ) ) {
			$apc_diff[] = "{$apc_key}: rule removed";
		}
		continue;
	}
	$apc_ol = cmp_apc_locals( $apc_prev, $apc_ctx, $apc_sel );
	$apc_nl = cmp_apc_locals( $apc_new, $apc_ctx, $apc_sel );
	foreach ( $apc_od as $apc_p => $apc_v ) {
		if ( 0 === strpos( $apc_p, '--cmp-' ) ) {
			continue; // tokens: checked below (they only matter where they are used)
		}
		$apc_was = cmp_apc_resolve( $apc_v, $apc_ol, array() );
		$apc_is  = isset( $apc_new[ $apc_key ][ $apc_p ] ) ? cmp_apc_resolve( $apc_new[ $apc_key ][ $apc_p ], $apc_nl, $apc_inline_only ) : '(missing)';
		if ( $apc_was !== $apc_is ) {
			$apc_diff[] = "{$apc_key} {$apc_p}: '{$apc_was}' -> '" . var_export( $apc_is, true ) . "'";
		}
	}
	foreach ( $apc_new[ $apc_key ] as $apc_p => $apc_v ) {
		if ( 0 === strpos( $apc_p, '--cmp-' ) && isset( $apc_od[ $apc_p ] ) && cmp_apc_resolve( $apc_od[ $apc_p ], $apc_ol, array() ) !== cmp_apc_resolve( $apc_v, $apc_nl, $apc_inline_only ) && ! isset( $apc_allowed_new[ $apc_key ][ $apc_p ] ) ) {
			$apc_diff[] = "{$apc_key}: token {$apc_p} changed";
		}
		if ( isset( $apc_od[ $apc_p ] ) || 0 === strpos( $apc_p, '--cmp-' ) ) {
			continue;
		}
		if ( isset( $apc_allowed_new[ $apc_key ][ $apc_p ] ) && $apc_allowed_new[ $apc_key ][ $apc_p ] === $apc_v ) {
			continue;
		}
		$apc_diff[] = "{$apc_key}: new declaration {$apc_p}: {$apc_v}";
	}
}
cmp_assert( array() === $apc_diff, 'appearance css: legacy = today on every B5 selector' . ( $apc_diff ? ' — ' . implode( ' | ', $apc_diff ) : '' ) );
cmp_assert( count( $apc_prev ) > 150, 'appearance css: the B5 snapshot was parsed (' . count( $apc_prev ) . ' selectors)' );
foreach ( $apc_rules as $apc_r ) {
	if ( 0 === strpos( $apc_r[1], '.cmp-light ' ) || 0 === strpos( $apc_r[1], '.cmp-light__' ) ) {
		foreach ( $apc_r[2] as $apc_dd ) {
			cmp_assert( false === strpos( $apc_dd[1], 'var(--cmp-radius' ) || false !== strpos( $apc_r[1], 'cmp--style-' ), "appearance css: inside the Light card only a style rule reads --cmp-radius ({$apc_r[1]})" );
		}
	}
}
// The phone blur exception is really there (and only that).
cmp_assert( isset( $apc_new['@media (max-width: 575px)|.cmp']['backdrop-filter'] ) && 'none' === $apc_new['@media (max-width: 575px)|.cmp']['backdrop-filter'], 'appearance css: no backdrop blur on phones (dim only)' );
cmp_assert( isset( $apc_new['@media (max-width: 575px)|.cmp']['-webkit-backdrop-filter'] ) && 'none' === $apc_new['@media (max-width: 575px)|.cmp']['-webkit-backdrop-filter'], 'appearance css: no -webkit- backdrop blur on phones' );

// New selectors only apply with a class a legacy site does not have (or only switch motion off).
$apc_stray = array();
foreach ( $apc_new as $apc_key => $apc_nd ) {
	if ( isset( $apc_prev[ $apc_key ] ) ) {
		continue;
	}
	list( $apc_ctx, $apc_sel ) = explode( '|', $apc_key, 2 );
	$apc_ok = false;
	foreach ( $apc_new_only as $apc_c ) {
		$apc_ok = $apc_ok || cmp_apc_has_class( $apc_sel, $apc_c );
	}
	// The "Settings ›" chevron span (fix wave add-on): hidden above 575 px, inline on phones,
	// i.e. the old ::after glyph moved to markup; nothing else.
	if ( ! $apc_ok && '.cmp__chev' === $apc_sel ) {
		$apc_ok = array( 'display' => '' === $apc_ctx ? 'none' : 'inline' ) === $apc_nd && in_array( $apc_ctx, array( '', '@media (max-width: 575px)' ), true );
	}
	if ( ! $apc_ok && false !== strpos( $apc_ctx, 'prefers-reduced-motion' ) ) {
		$apc_ok = array( 'transition', 'animation' ) === array_keys( $apc_nd ) && 'none !important' === $apc_nd['transition'] && 'none !important' === $apc_nd['animation'];
	}
	if ( ! $apc_ok ) {
		$apc_stray[] = $apc_key;
	}
}
cmp_assert( array() === $apc_stray, 'appearance css: every new selector needs a new-only class' . ( $apc_stray ? ' — ' . implode( ' | ', $apc_stray ) : '' ) );

// Resolver sanity: a custom --cmp-dim recolours the scrim with the palette's colour.
$apc_eg = cmp_apc_locals( $apc_new, '', '.cmp' );
$apc_eg = array_merge( $apc_eg, cmp_apc_locals( $apc_new, '', '.cmp--egov' ), array( '--cmp-dim' => '.3' ) );
cmp_assert_eq( cmp_apc_resolve( $apc_new['|.cmp']['background'], $apc_eg, array() ), 'rgba(0, 27, 53, 0.3)', 'appearance css: --cmp-dim .3 on eGov -> rgba(0, 27, 53, .3)' );
cmp_assert_eq( cmp_apc_resolve( $apc_new['|.cmp']['background'], cmp_apc_locals( $apc_new, '', '.cmp' ), $apc_inline_only ), 'rgba(17, 24, 39, 0.55)', 'appearance css: no --cmp-dim -> the palette scrim of today' );
// Every palette's scrim rgb = its --cmp-scrim colour (so a custom dim keeps the hue).
foreach ( array( 'neutral', 'warm', 'dark', 'egov' ) as $apc_p ) {
	$apc_t = CMP_Contrast::tokens( $apc_css, $apc_p );
	$apc_rgb = preg_match( '/^rgba\((\d+), ?(\d+), ?(\d+),/', isset( $apc_t['scrim'] ) ? $apc_t['scrim'] : '', $apc_m ) ? "{$apc_m[1]}, {$apc_m[2]}, {$apc_m[3]}" : '?';
	cmp_assert_eq( isset( $apc_t['scrim-rgb'] ) ? cmp_apc_norm( $apc_t['scrim-rgb'] ) : '', $apc_rgb, "appearance css: {$apc_p} --cmp-scrim-rgb = the rgb of --cmp-scrim" );
}

// ---- Format: bar is full width only with its class, never on phones ----
$apc_bar = array();
foreach ( $apc_rules as $apc_r ) {
	if ( cmp_apc_has_class( $apc_r[1], 'cmp--fmt-bar' ) ) {
		$apc_bar[] = $apc_r;
		cmp_assert_eq( $apc_r[0], '@media (min-width: 576px)', "appearance css: {$apc_r[1]} only above the phone band" );
	}
	foreach ( $apc_r[2] as $apc_d ) {
		if ( 'max-width' === $apc_d[0] && 'none' === $apc_d[1] && false === strpos( $apc_r[0], 'max-width' ) ) {
			cmp_assert( cmp_apc_has_class( $apc_r[1], 'cmp--fmt-bar' ) || 0 === strpos( $apc_r[1], '.cmp-light' ), "appearance css: max-width none on {$apc_r[1]} only for the bar" );
		}
	}
}
cmp_assert( count( $apc_bar ) >= 2, 'appearance css: the bar has rules' );
$apc_d = isset( $apc_new['@media (min-width: 576px)|.cmp--fmt-bar .cmp__modal'] ) ? $apc_new['@media (min-width: 576px)|.cmp--fmt-bar .cmp__modal'] : array();
cmp_assert( isset( $apc_d['max-width'] ) && 'none' === $apc_d['max-width'], 'appearance css: bar spans the full width' );
cmp_assert( isset( $apc_d['border-radius'] ) && '0' === $apc_d['border-radius'], 'appearance css: bar has square corners' );
cmp_assert( isset( $apc_d['box-sizing'] ) && 'border-box' === $apc_d['box-sizing'], 'appearance css: bar padding stays inside the width (border-box)' );
$apc_d = isset( $apc_new['@media (min-width: 576px)|.cmp--fmt-bar'] ) ? $apc_new['@media (min-width: 576px)|.cmp--fmt-bar'] : array();
cmp_assert( isset( $apc_d['padding'] ) && '0' === $apc_d['padding'], 'appearance css: bar touches the screen edges (no overlay padding)' );
foreach ( CMP_Appearance::LEGACY_POSITIONS as $apc_old_pos => $apc_fp ) {
	$apc_cl = CMP_Appearance::container_classes( CMP_Appearance::from_settings( array( 'position' => $apc_old_pos ), 'ro' ), 'pro' );
	cmp_assert( ! in_array( 'cmp--fmt-bar', $apc_cl, true ), "appearance css: legacy position {$apc_old_pos} never gets the bar" );
}
// Phone band: no appearance class reaches the phone media query (B5 band whatever the format).
$apc_phone_bad = array();
foreach ( $apc_rules as $apc_r ) {
	if ( false !== strpos( $apc_r[0], 'max-width: 575px' ) && preg_match( '/\.cmp--(?:fmt|btn|style|anim|backdrop|theme)-/', $apc_r[1] ) ) {
		$apc_phone_bad[] = $apc_r[1];
	}
}
cmp_assert( array() === $apc_phone_bad, 'appearance css: the phone band has no format / style rules' );

// ---- Backdrop: dim, blur (with fallbacks), none ----
$apc_d = $apc_new['|.cmp'];
cmp_assert( isset( $apc_d['backdrop-filter'], $apc_d['-webkit-backdrop-filter'] ) && 'blur(var(--cmp-blur, 5px))' === $apc_d['backdrop-filter'] && $apc_d['backdrop-filter'] === $apc_d['-webkit-backdrop-filter'], 'appearance css: blur from --cmp-blur (5px today), prefixed too' );
cmp_assert( isset( $apc_d['background'] ) && false !== strpos( $apc_d['background'], 'var(--cmp-scrim)' ), 'appearance css: the scrim is a plain background, so without backdrop-filter the page is still dimmed' );
cmp_assert( 0 === preg_match( '/@supports[^{]*backdrop/', $apc_css ), 'appearance css: the dim does not depend on @supports (backdrop-filter)' );
$apc_d = isset( $apc_new['|.cmp--backdrop-dim'] ) ? $apc_new['|.cmp--backdrop-dim'] : array();
cmp_assert( isset( $apc_d['backdrop-filter'], $apc_d['-webkit-backdrop-filter'] ) && 'none' === $apc_d['backdrop-filter'] && 'none' === $apc_d['-webkit-backdrop-filter'], 'appearance css: dim = no blur' );
$apc_d = isset( $apc_new['|.cmp.cmp--backdrop-none'] ) ? $apc_new['|.cmp.cmp--backdrop-none'] : array();
cmp_assert( isset( $apc_d['background'], $apc_d['pointer-events'], $apc_d['backdrop-filter'] ) && 'transparent' === $apc_d['background'] && 'none' === $apc_d['pointer-events'] && 'none' === $apc_d['backdrop-filter'], 'appearance css: no backdrop = no scrim, no blur, page clickable' );
cmp_assert( ! isset( $apc_d['align-items'] ), 'appearance css: no backdrop keeps the chosen position' );
$apc_d = isset( $apc_new['|.cmp.cmp--backdrop-none .cmp__modal'] ) ? $apc_new['|.cmp.cmp--backdrop-none .cmp__modal'] : array();
cmp_assert( isset( $apc_d['pointer-events'], $apc_d['overflow-y'] ) && 'auto' === $apc_d['pointer-events'] && 'auto' === $apc_d['overflow-y'], 'appearance css: no backdrop -> the banner itself stays clickable and scrolls' );

// ---- Buttons: one style for both decisions; text keeps focus and targets ----
$apc_btn_props = array( 'color', 'background', 'background-color', 'border-color', 'border-radius', 'text-decoration', 'text-underline-offset' );
foreach ( $apc_rules as $apc_r ) {
	if ( ! preg_match( '/\.cmp--(?:btn|style)-/', $apc_r[1] ) ) {
		continue;
	}
	cmp_assert( 0 === preg_match( '/data-cmp-action|accept|reject/', $apc_r[1] ), "appearance css: {$apc_r[1]} styles the decisions together (no per-button selector)" );
	foreach ( $apc_r[2] as $apc_dd ) {
		cmp_assert( in_array( $apc_dd[0], $apc_btn_props, true ), "appearance css: {$apc_r[1]} changes only colours / corners / underline ({$apc_dd[0]})" );
	}
}
foreach ( array( 'outline', 'text' ) as $apc_b ) {
	cmp_assert( isset( $apc_new[ '|.cmp--btn-' . $apc_b . ' .cmp__btn--primary' ] ), "appearance css: .cmp--btn-{$apc_b} styles .cmp__btn--primary (Reject, Accept and Save alike)" );
	cmp_assert( isset( $apc_new[ '|.cmp--btn-' . $apc_b . ' .cmp__btn--primary:hover' ] ), "appearance css: .cmp--btn-{$apc_b} has a hover state" );
}
$apc_d = isset( $apc_new['|.cmp--btn-text .cmp__btn--primary'] ) ? $apc_new['|.cmp--btn-text .cmp__btn--primary'] : array( 'color' => '', 'border-color' => '', 'text-decoration' => '' );
cmp_assert( 'var(--cmp-primary)' === $apc_d['color'] && 'transparent' === $apc_d['border-color'] && 0 === strpos( $apc_d['text-decoration'], 'underline' ), 'appearance css: text style = primary colour, underlined, transparent 1px border (same box)' );
$apc_d = isset( $apc_new['|.cmp--btn-outline .cmp__btn--primary'] ) ? $apc_new['|.cmp--btn-outline .cmp__btn--primary'] : array( 'color' => '', 'background' => '', 'border-color' => '' );
cmp_assert( 'var(--cmp-primary)' === $apc_d['color'] && 'var(--cmp-bg)' === $apc_d['background'] && 'var(--cmp-primary)' === $apc_d['border-color'], 'appearance css: outline style = primary text and border on the banner background' );
// Focus ring and target size come from .cmp__btn and are not touched by the styles.
$apc_f = $apc_new['|.cmp__btn:focus-visible'];
cmp_assert( isset( $apc_f['outline'] ) && '2px solid var(--cmp-primary)' === $apc_f['outline'], 'appearance css: every button style keeps the 2px focus ring' );
$apc_bb = $apc_new['|.cmp__btn'];
cmp_assert( '12px 22px' === $apc_bb['padding'] && 0 === strpos( $apc_bb['border'], '1px' ), 'appearance css: buttons keep 12px padding + 1px border (>= 24px) in every style' );
cmp_assert( isset( $apc_new['@media (max-width: 575px)|.cmp__choices .cmp__btn']['min-height'] ) && '44px' === $apc_new['@media (max-width: 575px)|.cmp__choices .cmp__btn']['min-height'], 'appearance css: phone decisions stay >= 44px high in every style' );
// Colours of the outline / text styles pass on every palette (light) and in the dark variant.
foreach ( array( 'neutral', 'warm', 'dark', 'egov' ) as $apc_p ) {
	$apc_t = CMP_Contrast::tokens( $apc_css, $apc_p );
	foreach ( array( 'bg', 'outline-hover' ) as $apc_u ) {
		$apc_ratio = CMP_Contrast::ratio( $apc_t['primary'], $apc_t[ $apc_u ] );
		cmp_assert( $apc_ratio >= 4.5, sprintf( 'appearance css: outline/text label on %s, %s: %.2f >= 4.5', $apc_u, $apc_p, $apc_ratio ) );
	}
	$apc_ratio = CMP_Contrast::ratio( $apc_t['primary'], $apc_t['bg'] );
	cmp_assert( $apc_ratio >= 3, sprintf( 'appearance css: text style focus ring on %s %.2f >= 3', $apc_p, $apc_ratio ) );
}
// Friendly = pill buttons.
cmp_assert( isset( $apc_new['|.cmp--style-friendly .cmp__btn']['border-radius'] ) && '999px' === $apc_new['|.cmp--style-friendly .cmp__btn']['border-radius'], 'appearance css: Friendly buttons are pills' );

// ---- Theme: auto = the Dark tokens under prefers-color-scheme: dark ----
$apc_dark_vars = array();
$apc_auto_vars = array();
foreach ( $apc_new as $apc_key => $apc_dd ) {
	foreach ( $apc_dd as $apc_p => $apc_v ) {
		if ( 0 !== strpos( $apc_p, '--cmp-' ) ) {
			continue;
		}
		if ( '|.cmp--dark' === $apc_key ) {
			$apc_dark_vars[ $apc_p ] = $apc_v;
		} elseif ( '@media (prefers-color-scheme: dark)|.cmp--theme-auto' === $apc_key ) {
			$apc_auto_vars[ $apc_p ] = $apc_v;
		}
	}
}
cmp_assert( count( $apc_dark_vars ) >= 10 && $apc_dark_vars === $apc_auto_vars, 'appearance css: auto theme in the dark = exactly the Dark tokens' );
cmp_assert_eq( isset( $apc_new['@media (prefers-color-scheme: dark)|.cmp--theme-auto .cmp__modal'] ) ? $apc_new['@media (prefers-color-scheme: dark)|.cmp--theme-auto .cmp__modal'] : null, $apc_new['|.cmp--dark .cmp__modal'], 'appearance css: auto dark modal shadow = Dark' );
$apc_last_palette = 0;
$apc_auto_order   = -1;
foreach ( $apc_rules as $apc_r ) {
	if ( '' === $apc_r[0] && preg_match( '/^\.cmp--(?:egov|warm|dark)$/', $apc_r[1] ) ) {
		$apc_last_palette = max( $apc_last_palette, $apc_r[3] );
	}
	if ( '.cmp--theme-auto' === $apc_r[1] ) {
		$apc_auto_order = $apc_r[3];
	}
}
cmp_assert( $apc_auto_order > $apc_last_palette, 'appearance css: the auto dark rule comes after the palettes (wins over eGov / Warm)' );
// The Dark modal shadow follows --cmp-shadow (Minimal = none) with today's value as fallback.
cmp_assert_eq( $apc_new['|.cmp--dark .cmp__modal']['box-shadow'], 'var(--cmp-shadow, 0 20px 50px rgba(0, 0, 0, 0.5))', 'appearance css: Dark modal shadow = --cmp-shadow, today as fallback' );

// ---- Light card: own 16px corners and shadow unless the style changes them ----
$apc_d = $apc_new['|.cmp-light'];
cmp_assert( '16px' === $apc_d['--cmp-radius'] && 'var(--cmp-radius)' === $apc_d['border-radius'], 'appearance css: Light card radius = --cmp-radius, own default 16px' );
cmp_assert_eq( $apc_d['box-shadow'], 'var(--cmp-shadow, 0 12px 32px rgba(0, 0, 0, 0.18))', 'appearance css: Light card shadow = --cmp-shadow, own default' );

// ---- Animation: fade / slide, and nothing under prefers-reduced-motion ----
cmp_assert( isset( $apc_kf['cmp-fade'], $apc_kf['cmp-slide'] ), 'appearance css: @keyframes cmp-fade and cmp-slide' );
$apc_rm = array();
foreach ( $apc_rules as $apc_r ) {
	if ( false !== strpos( $apc_r[0], 'prefers-reduced-motion: reduce' ) ) {
		$apc_rm[] = $apc_r[1];
	}
}
foreach ( array( '.cmp', '.cmp *', '.cmp *::before', '.cmp *::after', '.cmp-light', '.cmp-light *', '.cmp-light *::before', '.cmp-light *::after', '.cmp-reopen' ) as $apc_s ) {
	cmp_assert( in_array( $apc_s, $apc_rm, true ), "appearance css: reduced motion covers {$apc_s}" );
}
$apc_animated = 0;
foreach ( $apc_rules as $apc_r ) {
	if ( false !== strpos( $apc_r[0], 'prefers-reduced-motion' ) ) {
		continue;
	}
	foreach ( $apc_r[2] as $apc_dd ) {
		if ( 'animation' !== $apc_dd[0] && 'animation-name' !== $apc_dd[0] ) {
			continue;
		}
		$apc_animated++;
		// The animated element is .cmp / .cmp-light itself or a descendant: the reduced-motion rule reaches it.
		cmp_assert( 1 === preg_match( '/^\.cmp(?:-light)?(?:\.[\w-]+)+(?: .+)?$/', $apc_r[1] ), "appearance css: animated {$apc_r[1]} is .cmp / .cmp-light or inside them" );
		cmp_assert( 1 === preg_match( '/\.cmp--anim-(?:fade|slide)/', $apc_r[1] ), "appearance css: animation only with an anim class ({$apc_r[1]})" );
	}
}
cmp_assert( $apc_animated >= 3, 'appearance css: fade and slide are animated (' . $apc_animated . ' rules)' );
foreach ( array( 'fade', 'slide' ) as $apc_an ) {
	$apc_has_light = false;
	foreach ( $apc_rules as $apc_r ) {
		$apc_has_light = $apc_has_light || ( 0 === strpos( $apc_r[1], '.cmp-light.cmp--anim-' . $apc_an ) );
	}
	cmp_assert( $apc_has_light, "appearance css: the Light card animates too ({$apc_an})" );
}

// ---- Standalone copy is the same file ----
cmp_assert( (string) @file_get_contents( $apc_root . 'standalone/cmp.css' ) === $apc_css, 'appearance css: standalone/cmp.css = banner.css (run bin/build-assets.sh)' );
