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

	const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

	public static function register() {
		add_action( 'admin_post_cmp_localize_fonts', array( __CLASS__, 'localize' ) );
		add_action( 'admin_post_cmp_clear_fonts', array( __CLASS__, 'clear' ) );
	}

	public static function map() {
		$m = get_option( self::MAP_KEY, array() );
		return is_array( $m ) ? $m : array();
	}

	public static function dir() {
		$up = wp_upload_dir();
		return trailingslashit( $up['basedir'] ) . self::SUBDIR;
	}

	public static function url() {
		$up = wp_upload_dir();
		return trailingslashit( $up['baseurl'] ) . self::SUBDIR;
	}

	/** GET a URL with a modern browser UA (so Google serves woff2). Returns body or ''. */
	private static function fetch( $url ) {
		$res = wp_remote_get( $url, array( 'timeout' => 20, 'headers' => array( 'User-Agent' => self::UA ) ) );
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			return '';
		}
		return (string) wp_remote_retrieve_body( $res );
	}

	/** Admin action: scan the homepage, download Google Fonts locally, build the map. */
	public static function localize() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cmp_localize_fonts' ) ) {
			wp_die( esc_html__( 'Acțiune neautorizată.', 'consent-manager-pro' ) );
		}

		$home = self::fetch( home_url( '/' ) );
		$links = self::extract_font_links( $home );

		$dir = self::dir();
		wp_mkdir_p( $dir );
		$map   = array();
		$count = 0;

		foreach ( $links as $google_url ) {
			$css = self::fetch( $google_url );
			if ( '' === $css ) {
				continue;
			}
			$woff2   = self::parse_woff2_urls( $css );
			$url_map = array();
			foreach ( $woff2 as $remote ) {
				if ( false === stripos( $remote, 'fonts.gstatic.com' ) ) {
					continue;
				}
				$body = self::fetch( $remote );
				if ( '' === $body ) {
					continue;
				}
				$fname = md5( $remote ) . '.woff2';
				if ( false !== file_put_contents( $dir . '/' . $fname, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					$url_map[ $remote ] = self::url() . '/' . $fname;
				}
			}
			if ( empty( $url_map ) ) {
				continue;
			}
			$local_css = self::localize_css( $css, $url_map );
			$css_name  = md5( $google_url ) . '.css';
			if ( false !== file_put_contents( $dir . '/' . $css_name, $local_css ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$map[ $google_url ] = self::url() . '/' . $css_name;
				$count++;
			}
		}

		update_option( self::MAP_KEY, array_merge( self::map(), $map ) );
		wp_safe_redirect( add_query_arg( array( 'page' => CMP_Admin::SLUG, 'cmp_fonts' => (int) $count ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Admin action: delete the localized files and clear the map. */
	public static function clear() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cmp_clear_fonts' ) ) {
			wp_die( esc_html__( 'Acțiune neautorizată.', 'consent-manager-pro' ) );
		}
		$dir = self::dir();
		if ( is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '/*' ) as $f ) {
				if ( is_file( $f ) ) {
					@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		}
		delete_option( self::MAP_KEY );
		wp_safe_redirect( add_query_arg( array( 'page' => CMP_Admin::SLUG, 'cmp_fonts_cleared' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
