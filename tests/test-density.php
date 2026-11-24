<?php
/**
 * Banner density (appearance "density"): compact / normal / comfortable ("Extins").
 * - normalisation and sanitisation (unknown / missing -> normal; a style switch keeps it);
 * - classes: cmp--density-compact / cmp--density-comfortable, none for normal;
 * - CSS: in the inline appearance block, only for compact / comfortable, so a
 *   normal banner (every existing site) is byte for byte today's
 *   (tests/fixtures/appearance-normal.json, recorded before density existed);
 * - buttons equal for every density, targets >= 24 px, phone band covered;
 * - PHP and JS agree (tests/fixtures/density-parity.json, checked by both suites).
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once __DIR__ . '/../includes/class-contrast.php';
require_once __DIR__ . '/../includes/class-appearance.php';

// ---- Values ----
cmp_assert_eq( CMP_Appearance::DENSITIES, array( 'compact', 'normal', 'comfortable' ), 'density: the three values' );
foreach ( CMP_Appearance::STYLES as $dn_s ) {
	cmp_assert_eq( CMP_Appearance::defaults( $dn_s )['density'], 'normal', "density: default normal ($dn_s)" );
}
cmp_assert( ! in_array( 'density', CMP_Appearance::STYLE_KEYS, true ), 'density: not a style value (a style switch keeps it)' );
$dn_base = CMP_Appearance::defaults( 'brand' );
foreach ( CMP_Appearance::DENSITIES as $dn_v ) {
	cmp_assert_eq( CMP_Appearance::sanitize( array( 'density' => $dn_v ), $dn_base )['density'], $dn_v, "density: sanitize keeps $dn_v" );
}
cmp_assert_eq( CMP_Appearance::sanitize( array( 'density' => 'huge' ), $dn_base )['density'], 'normal', 'density: unknown -> normal' );
cmp_assert_eq( CMP_Appearance::sanitize( array( 'density' => array( 'compact' ) ), $dn_base )['density'], 'normal', 'density: not a scalar -> normal' );
cmp_assert_eq( CMP_Appearance::sanitize( array( 'density' => 'huge' ), array_merge( $dn_base, array( 'density' => 'compact' ) ) )['density'], 'compact', 'density: unknown posted -> the current value' );
cmp_assert_eq( CMP_Appearance::sanitize( array( 'style' => 'friendly' ), array_merge( $dn_base, array( 'density' => 'comfortable' ) ) )['density'], 'comfortable', 'density: a style switch keeps the density' );
cmp_assert_eq( CMP_Appearance::from_settings( array( 'appearance' => array( 'density' => 'compact' ) ), 'ro' )['density'], 'compact', 'density: read from the stored appearance' );
cmp_assert_eq( CMP_Appearance::from_settings( array( 'appearance' => array() ), 'ro' )['density'], 'normal', 'density: stored appearance without it -> normal' );
cmp_assert_eq( CMP_Appearance::from_settings( array( 'preset' => 'egov' ), 'ro' )['density'], 'normal', 'density: legacy site (no appearance) -> normal' );

// ---- Classes ----
foreach ( array( 'pro', 'light' ) as $dn_m ) {
	$dn_n = CMP_Appearance::container_classes( $dn_base, $dn_m );
	cmp_assert( ! preg_grep( '/density/', $dn_n ), "density classes ($dn_m): normal adds none" );
	cmp_assert_eq( CMP_Appearance::container_classes( array_merge( $dn_base, array( 'density' => 'compact' ) ), $dn_m ), array_merge( $dn_n, array( 'cmp--density-compact' ) ), "density classes ($dn_m): compact -> cmp--density-compact, last" );
	cmp_assert_eq( CMP_Appearance::container_classes( array_merge( $dn_base, array( 'density' => 'comfortable' ) ), $dn_m ), array_merge( $dn_n, array( 'cmp--density-comfortable' ) ), "density classes ($dn_m): comfortable -> cmp--density-comfortable, last" );
	cmp_assert_eq( CMP_Appearance::container_classes( array_merge( $dn_base, array( 'density' => 'x"><b>' ) ), $dn_m ), $dn_n, "density classes ($dn_m): a bad value never reaches the markup" );
}

// ---- Normal = today, byte for byte (snapshot recorded before density) ----
$dn_snap = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/appearance-normal.json' ), true );
cmp_assert( is_array( $dn_snap ) && count( $dn_snap ) > 100, 'density normal: the snapshot was read (' . ( is_array( $dn_snap ) ? count( $dn_snap ) : 0 ) . ' cases)' );
$dn_bad = array();
foreach ( (array) $dn_snap as $dn_i => $dn_c ) {
	foreach ( array( null, 'normal', 'bogus' ) as $dn_d ) {
		$dn_in = (array) $dn_c['in'];
		if ( null !== $dn_d ) {
			$dn_in['density'] = $dn_d;
		}
		$dn_style = isset( $dn_in['style'] ) ? $dn_in['style'] : 'brand';
		$dn_a     = CMP_Appearance::sanitize( $dn_in, CMP_Appearance::defaults( $dn_style ) );
		if ( CMP_Appearance::container_classes( $dn_a, 'pro' ) !== $dn_c['pro'] || CMP_Appearance::container_classes( $dn_a, 'light' ) !== $dn_c['light'] || CMP_Appearance::inline_css( $dn_a ) !== $dn_c['css'] ) {
			$dn_bad[] = $dn_i . '/' . var_export( $dn_d, true );
		}
	}
}
cmp_assert( array() === $dn_bad, 'density normal: classes and inline CSS = today\'s, byte for byte, for every snapshot case' . ( $dn_bad ? ' - ' . implode( ', ', $dn_bad ) : '' ) );

// ---- CSS: only for compact / comfortable, appended after today's block ----
foreach ( array( 'compact', 'comfortable' ) as $dn_v ) {
	$dn_css = CMP_Appearance::DENSITY_CSS[ $dn_v ];
	cmp_assert_eq( CMP_Appearance::inline_css( array_merge( $dn_base, array( 'density' => $dn_v ) ) ), $dn_css, "density css: Brand + $dn_v = only the density rules" );
	$dn_fr = CMP_Appearance::sanitize( array( 'style' => 'friendly', 'accent' => '#e4572e', 'theme' => 'auto' ), $dn_base );
	cmp_assert_eq( CMP_Appearance::inline_css( array_merge( $dn_fr, array( 'density' => $dn_v ) ) ), CMP_Appearance::inline_css( $dn_fr ) . $dn_css, "density css: $dn_v is appended after the other appearance CSS" );
	cmp_assert( false === strpos( $dn_css, '<' ), "density css: $dn_v has no '<'" );
	// Every selector carries this density's class (nothing leaks to other banners).
	preg_match_all( '/(?:^|[{}])\s*([^{}@]+)\{/', preg_replace( '/@media[^{]*\{/', '', $dn_css ), $dn_sel );
	$dn_ok = count( $dn_sel[1] ) > 8;
	foreach ( $dn_sel[1] as $dn_group ) {
		foreach ( explode( ',', $dn_group ) as $dn_one ) {
			$dn_ok = $dn_ok && false !== strpos( $dn_one, '.cmp--density-' . $dn_v );
		}
	}
	cmp_assert( $dn_ok, "density css: every $dn_v selector needs .cmp--density-$dn_v" );
	// Equal weight: no rule styles one decision (or one button variant) apart.
	cmp_assert( 0 === preg_match( '/data-cmp-action|accept|reject|cmp__btn--/', $dn_css ), "density css: $dn_v styles all buttons alike (no per-button selector)" );
	// All formats and the phone band.
	cmp_assert( false !== strpos( $dn_css, '@media (max-width:575px){' ) && false !== strpos( $dn_css, '@media (min-width:576px){' ), "density css: $dn_v covers the phone band and wider screens" );
	cmp_assert( false !== strpos( $dn_css, '.cmp--density-' . $dn_v . '.cmp--fmt-bar .cmp__modal{padding-inline:max(' ), "density css: $dn_v keeps the bar's content centred" );
	cmp_assert( false !== strpos( $dn_css, '.cmp-light.cmp--density-' . $dn_v . '{' ), "density css: $dn_v reaches the Light card" );
}

// Exact values (see the report): compact ~2/3, comfortable ~4/3 of today's spacing.
$dn_rule = function ( $css, $ctx, $sel ) {
	if ( '' !== $ctx ) {
		$p = strpos( $css, $ctx . '{' );
		if ( false === $p ) {
			return null;
		}
		$end = $p + strlen( $ctx ) + 1;
		$d   = 1;
		for ( $i = $end; $i < strlen( $css ) && $d > 0; $i++ ) {
			$d += '{' === $css[ $i ] ? 1 : ( '}' === $css[ $i ] ? -1 : 0 );
		}
		$css = substr( $css, $end, $i - $end - 1 );
	} else {
		$css = preg_replace( '/@media[^{]*\{(?:[^{}]*\{[^}]*\})*[^{}]*\}/', '', $css );
	}
	return preg_match( '/(?:^|\})' . preg_quote( $sel, '/' ) . '\{([^}]*)\}/', $css, $m ) ? $m[1] : null;
};
$dn_desk  = '@media (min-width:576px)';
$dn_phone = '@media (max-width:575px)';
$dn_c     = CMP_Appearance::DENSITY_CSS['compact'];
$dn_x     = CMP_Appearance::DENSITY_CSS['comfortable'];
cmp_assert_eq( $dn_rule( $dn_c, $dn_desk, '.cmp--density-compact .cmp__modal' ), 'padding:19px 21px', 'density compact: modal padding 19/21 (today 28/32)' );
cmp_assert_eq( $dn_rule( $dn_x, $dn_desk, '.cmp--density-comfortable .cmp__modal' ), 'padding:37px 43px', 'density comfortable: modal padding 37/43 (today 28/32)' );
cmp_assert_eq( $dn_rule( $dn_c, $dn_phone, '.cmp--density-compact .cmp__modal' ), 'padding:16px 14px', 'density compact: phone sheet padding 16/14 (today 24/20)' );
cmp_assert_eq( $dn_rule( $dn_x, $dn_phone, '.cmp--density-comfortable .cmp__modal' ), 'padding:32px 27px', 'density comfortable: phone sheet padding 32/27 (today 24/20)' );
cmp_assert_eq( $dn_rule( $dn_c, $dn_desk, '.cmp--density-compact .cmp__btn' ), 'padding:8px 15px', 'density compact: buttons 8/15 (today 12/22)' );
cmp_assert_eq( $dn_rule( $dn_x, $dn_desk, '.cmp--density-comfortable .cmp__btn' ), 'padding:16px 29px', 'density comfortable: buttons 16/29 (today 12/22)' );
cmp_assert_eq( $dn_rule( $dn_x, '', '.cmp--density-comfortable .cmp__text' ), 'font-size:15px', 'density comfortable: body text +1px (15px)' );
cmp_assert( 0 === preg_match( '/font-size/', $dn_c ), 'density compact: text size unchanged' );

// Targets: every button keeps >= 24 x 24 px (14px text x 1.2 line height + padding + 1px borders).
foreach ( array( 'compact' => $dn_c, 'comfortable' => $dn_x ) as $dn_v => $dn_css ) {
	foreach ( array( array( $dn_desk, ".cmp--density-$dn_v .cmp__btn" ), array( $dn_phone, ".cmp--density-$dn_v .cmp__choices .cmp__btn" ), array( '', ".cmp--density-$dn_v .cmp-light__btn" ) ) as $dn_t ) {
		$dn_body = (string) $dn_rule( $dn_css, $dn_t[0], $dn_t[1] );
		$dn_pad  = preg_match( '/padding:(\d+)px (\d+)px/', $dn_body, $dn_pm ) ? array( (int) $dn_pm[1], (int) $dn_pm[2] ) : array( 0, 0 );
		$dn_minh = preg_match( '/min-height:(\d+)px/', $dn_body, $dn_mm ) ? (int) $dn_mm[1] : 0;
		$dn_h    = max( $dn_minh, 2 * $dn_pad[0] + 14 * 1.2 + 2 );
		$dn_w    = 2 * $dn_pad[1] + 2 + 14; // at least one character wide
		cmp_assert( '' !== $dn_body && $dn_h >= 24 && $dn_w >= 24, "density $dn_v: {$dn_t[1]} target >= 24x24 (h " . round( $dn_h, 1 ) . ')' );
	}
	$dn_pb = (string) $dn_rule( $dn_css, $dn_phone, ".cmp--density-$dn_v .cmp__choices .cmp__btn" );
	cmp_assert( 1 === preg_match( '/min-height:(\d+)px/', $dn_pb, $dn_mm ) && (int) $dn_mm[1] >= 24, "density $dn_v: both phone decisions share one rule with a min-height" );
}

// ---- PHP = JS: the parity fixture (standalone/tests/run.js checks cmp.js against it too) ----
$dn_par = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/density-parity.json' ), true );
cmp_assert( is_array( $dn_par ) && count( $dn_par ) >= 9, 'density parity: fixture read' );
foreach ( (array) $dn_par as $dn_i => $dn_c2 ) {
	$dn_in    = (array) $dn_c2['in'];
	$dn_style = isset( $dn_in['style'] ) ? $dn_in['style'] : 'brand';
	$dn_a     = CMP_Appearance::sanitize( $dn_in, CMP_Appearance::defaults( $dn_style ) );
	cmp_assert_eq( array( $dn_a['density'], CMP_Appearance::container_classes( $dn_a, 'pro' ), CMP_Appearance::container_classes( $dn_a, 'light' ), CMP_Appearance::inline_css( $dn_a ) ), array( $dn_c2['density'], $dn_c2['pro'], $dn_c2['light'], $dn_c2['css'] ), "density parity: case $dn_i = PHP" );
}
