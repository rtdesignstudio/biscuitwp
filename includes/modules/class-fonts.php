<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone-loadable for tests; pure helpers use no WP. WP methods added in Task 2.
}

/**
 * Google Fonts localization: download the fonts once (admin button) and rewrite
 * <link> tags to a local CSS so visitors never contact Google. Opt-in, Pro mode.
 */
class CMP_Fonts {

	const HOST     = 'fonts.googleapis.com';
	const MAP_KEY  = 'cmp_fonts_map';
	const SUBDIR   = 'cmp-fonts';

	/** Pure: Google Fonts <link> hrefs found in the HTML (entities decoded, deduped). */
	public static function extract_font_links( $html ) {
		$out = array();
		if ( preg_match_all( '/<link\b[^>]*>/i', (string) $html, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				if ( preg_match( '/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $hm ) ) {
					$url = html_entity_decode( $hm[1], ENT_QUOTES );
					if ( false !== stripos( $url, self::HOST ) && ! in_array( $url, $out, true ) ) {
						$out[] = $url;
					}
				}
			}
		}
		return $out;
	}

	/** Pure: absolute .woff2 URLs referenced in url(...) inside CSS (deduped). */
	public static function parse_woff2_urls( $css ) {
		$out = array();
		if ( preg_match_all( '/url\(\s*[\'"]?([^\'")]+\.woff2)[\'"]?\s*\)/i', (string) $css, $ms ) ) {
			foreach ( $ms[1] as $u ) {
				if ( ! in_array( $u, $out, true ) ) {
					$out[] = $u;
				}
			}
		}
		return $out;
	}

	/** Pure: replace each remote URL with its local one in the CSS. */
	public static function localize_css( $css, array $url_map ) {
		$css = (string) $css;
		foreach ( $url_map as $remote => $local ) {
			$css = str_replace( $remote, (string) $local, $css );
		}
		return $css;
	}

	/** Pure: swap Google Fonts <link> hrefs to their local CSS for mapped URLs. */
	public static function rewrite_links( $html, array $map ) {
		if ( empty( $map ) ) {
			return (string) $html;
		}
		return (string) preg_replace_callback(
			'/<link\b[^>]*>/i',
			function ( $m ) use ( $map ) {
				$tag = $m[0];
				if ( ! preg_match( '/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $hm ) ) {
					return $tag;
				}
				$url = html_entity_decode( $hm[1], ENT_QUOTES );
				if ( ! isset( $map[ $url ] ) ) {
					return $tag;
				}
				return str_replace( $hm[1], (string) $map[ $url ], $tag );
			},
			(string) $html
		);
	}
}
