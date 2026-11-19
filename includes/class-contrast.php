<?php
/**
 * WCAG contrast helpers for the banner styles. Pure: no WordPress functions.
 * Used by tests/test-contrast.php to check every --cmp-* colour pair of the
 * four styles (neutral, warm, dark, egov); not loaded on the front end.
 */
class CMP_Contrast {

	/**
	 * WCAG 2.x contrast ratio between two #rgb / #rrggbb colours (1..21).
	 * 0.0 when either is not a hex colour (a missing token, a name, var(), rgb()):
	 * it fails every threshold instead of passing as some stand-in colour.
	 */
	public static function ratio( $hex1, $hex2 ) {
		if ( ! self::is_hex( $hex1 ) || ! self::is_hex( $hex2 ) ) {
			return 0.0;
		}
		$l1 = self::luminance( $hex1 );
		$l2 = self::luminance( $hex2 );
		return ( max( $l1, $l2 ) + 0.05 ) / ( min( $l1, $l2 ) + 0.05 );
	}

	/** True for #rgb / #rrggbb. */
	public static function is_hex( $v ) {
		return is_string( $v ) && 1 === preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', trim( $v ) );
	}

	/**
	 * The --cmp-* custom properties of a style, without the prefix:
	 * neutral = the base block (`.cmp, .cmp-light, .cmp-embed` or :root/.cmp),
	 * any other preset = `.cmp--<preset>` over the base.
	 */
	public static function tokens( $css, $preset ) {
		$css  = (string) preg_replace( '#/\*.*?\*/#s', '', (string) $css );
		$base = array();
		$over = array();
		if ( preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $rule ) {
				$sels = array_map( 'trim', explode( ',', $rule[1] ) );
				if ( in_array( ':root', $sels, true ) || in_array( '.cmp', $sels, true ) ) {
					$base = array_merge( $base, self::vars( $rule[2] ) );
				} elseif ( 'neutral' !== $preset && in_array( '.cmp--' . $preset, $sels, true ) ) {
					$over = array_merge( $over, self::vars( $rule[2] ) );
				}
			}
		}
		return array_merge( $base, $over );
	}

	/** An rgba(r, g, b, a) colour composited over an opaque background, as #rrggbb. */
	public static function blend( $rgba, $bg_hex ) {
		$rgba = trim( (string) $rgba );
		if ( ! preg_match( '/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+)\s*)?\)$/i', $rgba, $m ) ) {
			return strtolower( $rgba );
		}
		if ( ! self::is_hex( $bg_hex ) ) {
			return '';
		}
		$a  = isset( $m[4] ) && '' !== $m[4] ? max( 0.0, min( 1.0, (float) $m[4] ) ) : 1.0;
		$bg = self::rgb( $bg_hex );
		$out = '#';
		for ( $i = 0; $i < 3; $i++ ) {
			$out .= sprintf( '%02x', (int) round( $a * (int) $m[ $i + 1 ] + ( 1 - $a ) * $bg[ $i ] ) );
		}
		return $out;
	}

	/** --cmp-name: value pairs of a declaration block. */
	private static function vars( $block ) {
		$out = array();
		if ( preg_match_all( '/--cmp-([a-z0-9-]+)\s*:\s*([^;]+);?/i', $block, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $d ) {
				$out[ strtolower( $d[1] ) ] = strtolower( trim( $d[2] ) );
			}
		}
		return $out;
	}

	/** #rgb / #rrggbb -> array( r, g, b ) in 0..255. */
	private static function rgb( $hex ) {
		$hex = ltrim( trim( (string) $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
			return array( 0, 0, 0 );
		}
		return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}

	/** WCAG relative luminance (sRGB). */
	private static function luminance( $hex ) {
		$c = self::rgb( $hex );
		$l = array();
		foreach ( $c as $v ) {
			$s   = $v / 255;
			$l[] = $s <= 0.03928 ? $s / 12.92 : pow( ( $s + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * $l[0] + 0.7152 * $l[1] + 0.0722 * $l[2];
	}
}
