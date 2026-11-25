<?php
/**
 * Export and import of the banner appearance (Aspect screen).
 *
 * The file holds the saved appearance (CMP_Appearance::from_settings(), so the
 * banner texts per language and custom_css included) and nothing about the
 * site: no exclusions (they are pages of this site), no other setting, no
 * operator data, no URL, no page id. Other settings can never change on an
 * import: merge() goes through CMP_Sanitizer::aspect(), the same path as the
 * Aspect save, so it only touches what that save touches (CMP_Preview::KEYS).
 *
 * Pure: no WordPress functions, no database. The Aspect screen reads the
 * upload (read_upload()), calls parse() and merge(), renders the form with the
 * result and writes nothing; "Salvează" is the normal Aspect save.
 */
if ( ! defined( 'ABSPATH' ) && ! isset( $GLOBALS['cmp_tests'] ) ) {
	exit;
}

class CMP_Appearance_IO {

	const FORMAT    = 'biscuitwp-appearance';
	const VERSION   = 1;
	const MAX_BYTES = 262144;

	/** json_decode depth: our own file is 4 levels deep. */
	const MAX_DEPTH = 8;

	/** Appearance keys that belong to the site, not to the look: never exported, never imported. */
	const SITE_KEYS = array( 'exclude', 'image_id' );

	/**
	 * The file content for the saved settings.
	 *
	 * @param array  $settings       cmp_settings.
	 * @param string $plugin_version CMP_VERSION.
	 */
	public static function export( array $settings, $plugin_version ) {
		$a = CMP_Appearance::from_settings( $settings );
		// Reopen button off in the old key = the banner shows none, whatever the stored shape (as the form shows it).
		if ( array_key_exists( 'reopen_button', $settings ) && empty( $settings['reopen_button'] ) ) {
			$a['reopen'] = 'none';
		}
		// The site's own picture stays on its site.
		if ( 'own' === $a['image'] ) {
			$a['image'] = 'none';
		}
		foreach ( self::SITE_KEYS as $k ) {
			unset( $a[ $k ] );
		}
		$version = preg_replace( '/[^0-9A-Za-z.+-]/', '', (string) $plugin_version );
		return array(
			'format'     => self::FORMAT,
			'version'    => self::VERSION,
			'plugin'     => trim( 'BiscuitWP ' . $version ),
			'appearance' => $a,
		);
	}

	/**
	 * Read an uploaded file ($_FILES entry): the error is checked first, then
	 * is_uploaded_file, and at most MAX_BYTES + 1 bytes are read (enough to see
	 * "too big"). The file is never moved or copied; its client name is never read.
	 *
	 * @param mixed         $file        The $_FILES entry (or null).
	 * @param callable|null $is_uploaded is_uploaded_file (injectable for the tests).
	 * @return array { error: ''|'none'|'partial'|'size'|'upload', json: string }
	 */
	public static function read_upload( $file, $is_uploaded = null ) {
		$fail = function ( $e ) {
			return array( 'error' => $e, 'json' => '' );
		};
		if ( ! is_array( $file ) || ! isset( $file['error'] ) ) {
			return $fail( 'none' );
		}
		if ( ! is_int( $file['error'] ) ) {
			return $fail( 'upload' );
		}
		switch ( $file['error'] ) {
			case UPLOAD_ERR_OK:
				break;
			case UPLOAD_ERR_NO_FILE:
				return $fail( 'none' );
			case UPLOAD_ERR_PARTIAL:
				return $fail( 'partial' );
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return $fail( 'size' );
			default:
				return $fail( 'upload' );
		}
		$tmp = isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';
		$is  = $is_uploaded ? $is_uploaded : 'is_uploaded_file';
		if ( '' === $tmp || ! call_user_func( $is, $tmp ) ) {
			return $fail( 'upload' );
		}
		$raw = is_readable( $tmp ) ? @file_get_contents( $tmp, false, null, 0, self::MAX_BYTES + 1 ) : false;
		if ( ! is_string( $raw ) ) {
			return $fail( 'upload' );
		}
		return array( 'error' => strlen( $raw ) > self::MAX_BYTES ? 'size' : '', 'json' => $raw );
	}

	/**
	 * Check and decode a file's text. Size first (bytes), then UTF-8, then JSON
	 * (depth 8); it must be an object with the exact format and an integer
	 * version: a newer one is refused as 'version', a missing or older one as 'format'.
	 *
	 * @return array { error: ''|'size'|'json'|'format'|'version', data: array }
	 */
	public static function parse( $json ) {
		$fail = function ( $e ) {
			return array( 'error' => $e, 'data' => array() );
		};
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_BYTES ) {
			return $fail( 'size' );
		}
		if ( 0 === strpos( $json, "\xEF\xBB\xBF" ) ) {
			$json = substr( $json, 3 );
		}
		if ( '' === trim( $json ) || ! preg_match( '//u', $json ) ) {
			return $fail( 'json' );
		}
		$data = json_decode( $json, true, self::MAX_DEPTH );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return $fail( 'json' );
		}
		if ( ! is_array( $data ) || '{' !== substr( ltrim( $json ), 0, 1 ) ) {
			return $fail( 'format' );
		}
		if ( ! isset( $data['format'] ) || self::FORMAT !== $data['format'] || ! isset( $data['version'] ) || ! is_int( $data['version'] ) ) {
			return $fail( 'format' );
		}
		if ( $data['version'] > self::VERSION ) {
			return $fail( 'version' );
		}
		if ( $data['version'] < 1 || ! isset( $data['appearance'] ) || ! is_array( $data['appearance'] ) ) {
			return $fail( 'format' );
		}
		return array( 'error' => '', 'data' => $data );
	}

	/**
	 * The settings an import leads to, through CMP_Sanitizer::aspect() (so the
	 * values, the CSS and the texts are cleaned exactly as a save cleans them,
	 * keys not in the file keep the current value, and preset / reopen_button
	 * follow as aspect() makes them). Nothing is written.
	 *
	 * Counted, not stored: unknown keys (in the appearance, the texts, the
	 * languages) and the site keys (exclude). With WPML / Polylang the texts are
	 * skipped (they are translated there). The fields the current mode does not
	 * show (Light disables some, the texts have a mode) get what the save stores
	 * for them, as the form does not post them: the stored values or, for the
	 * shape keys of another style, that style's.
	 *
	 * @param array $data         parse()['data'].
	 * @param array $current      Stored cmp_settings.
	 * @param bool  $multilingual WPML or Polylang active.
	 * @return array { settings, ignored: int, texts_skipped: int, light_skipped: int, removed_css: string[] }
	 */
	public static function merge( array $data, array $current, $multilingual ) {
		$in      = isset( $data['appearance'] ) && is_array( $data['appearance'] ) ? $data['appearance'] : array();
		$known   = array_keys( CMP_Appearance::defaults( 'brand' ) );
		$light   = isset( $current['mode'] ) && 'light' === $current['mode'];
		$mode    = $light ? 'light' : 'pro';
		$ap      = array();
		$ignored = 0;
		$skipped = 0;
		$off     = 0;
		$removed = array();

		foreach ( $in as $k => $v ) {
			if ( ! is_string( $k ) || ! in_array( $k, $known, true ) || in_array( $k, self::SITE_KEYS, true ) ) {
				$ignored++;
				continue;
			}
			if ( 'texts' === $k ) {
				continue;
			}
			if ( $light && in_array( $k, CMP_Appearance_Page::LIGHT_OFF, true ) ) {
				$off++;
				continue;
			}
			$ap[ $k ] = $v;
		}

		if ( isset( $in['texts'] ) ) {
			$texts = array();
			if ( is_array( $in['texts'] ) ) {
				foreach ( $in['texts'] as $lang => $block ) {
					if ( ! is_string( $lang ) || ! in_array( $lang, CMP_Appearance::LANGS, true ) || ! is_array( $block ) ) {
						$ignored++;
						continue;
					}
					foreach ( $block as $tk => $tv ) {
						if ( ! is_string( $tk ) || ! in_array( $tk, CMP_Appearance::TEXT_KEYS, true ) ) {
							$ignored++;
							continue;
						}
						if ( $multilingual ) {
							if ( is_scalar( $tv ) && '' !== trim( (string) $tv ) ) {
								$skipped++;
							}
							continue;
						}
						$tm = CMP_Appearance_Page::TEXT_MODES[ $tk ];
						if ( '' !== $tm && $tm !== $mode ) {
							$off++;
							continue;
						}
						$texts[ $lang ][ $tk ] = $tv;
					}
				}
			} else {
				$ignored++;
			}
			if ( $texts ) {
				$ap['texts'] = $texts;
			}
		}

		// A new style reloads its shape keys; keys the file does not carry keep the current value.
		// Not the ones Light disables: the save posts none of them, so it stores the style's values.
		$cur = CMP_Appearance::from_settings( $current );
		if ( isset( $ap['style'] ) && is_scalar( $ap['style'] ) && (string) $ap['style'] !== $cur['style'] ) {
			foreach ( CMP_Appearance::STYLE_KEYS as $k ) {
				if ( ! array_key_exists( $k, $ap ) && ! ( $light && in_array( $k, CMP_Appearance_Page::LIGHT_OFF, true ) ) ) {
					$ap[ $k ] = $cur[ $k ];
				}
			}
		}

		if ( isset( $ap['custom_css'] ) && is_scalar( $ap['custom_css'] ) ) {
			$clean   = CMP_Appearance::clean_custom_css( substr( (string) $ap['custom_css'], 0, 20000 ) );
			$removed = $clean['removed'];
		}

		return array(
			'settings'      => CMP_Sanitizer::aspect( array( 'appearance' => $ap ), $current, (bool) $multilingual ),
			'ignored'       => $ignored,
			'texts_skipped' => $skipped,
			'light_skipped' => $off,
			'removed_css'   => array_values( $removed ),
		);
	}

	/** Pure: the download's file name: biscuitwp-aspect-<host>-<date>.json (host sanitized, nothing from the client). */
	public static function filename( $host, $date ) {
		$host = strtolower( trim( (string) $host ) );
		$host = (string) preg_replace( '/\.{2,}/', '.', (string) preg_replace( '/[^a-z0-9.]+/', '-', $host ) );
		$host = trim( $host, '-.' );
		$host = '' === $host ? 'site' : substr( $host, 0, 80 );
		$date = is_string( $date ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '0000-00-00';
		return 'biscuitwp-aspect-' . $host . '-' . $date . '.json';
	}

	/**
	 * The download: file name, headers and body, for the saved settings.
	 *
	 * @return array { filename: string, headers: string[], body: string }
	 */
	public static function response( array $settings, $plugin_version, $host, $date ) {
		$name = self::filename( $host, $date );
		$body = json_encode( self::export( $settings, $plugin_version ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE );
		return array(
			'filename' => $name,
			'headers'  => array(
				'Content-Type: application/json; charset=utf-8',
				'Content-Disposition: attachment; filename="' . $name . '"',
				'X-Content-Type-Options: nosniff',
			),
			'body'     => is_string( $body ) ? $body : '{}',
		);
	}
}
