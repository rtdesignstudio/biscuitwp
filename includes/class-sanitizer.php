<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone-loadable for tests.
}

class CMP_Sanitizer {

	/** Longest a stored consent may last: 13 months (EU practice; CNIL: 6–13). */
	const MAX_COOKIE_DAYS = 396;

	/** Pure: consent duration in days, capped at 13 months; invalid -> 180. */
	public static function cap_cookie_days( $days ) {
		$days = (int) $days;
		if ( $days < 1 ) {
			return 180;
		}
		return min( $days, self::MAX_COOKIE_DAYS );
	}

	public static function settings( array $input ) {
		$positions = array(
			'top-center', 'middle-center', 'bottom-center',
			'top-left', 'bottom-left', 'top-right', 'bottom-right',
			'bottom', 'top', 'modal-center', // legacy aliases
		);
		$presets   = array( 'neutral', 'warm', 'dark', 'egov' );
		$modes = array( 'pro', 'light' );
		$mode  = isset( $input['mode'] ) ? (string) $input['mode'] : '';

		$pos = isset( $input['position'] ) ? (string) $input['position'] : '';
		$pre = isset( $input['preset'] ) ? (string) $input['preset'] : '';
		$ver = isset( $input['policy_version'] ) ? trim( (string) $input['policy_version'] ) : '';
		$ret = isset( $input['retention_months'] ) ? (int) $input['retention_months'] : 0;
		$cd  = isset( $input['cookie_days'] ) ? (int) $input['cookie_days'] : 0;

		$text_keys = array( 'title', 'intro', 'light_intro', 'accept', 'reject', 'prefs', 'save', 'prefs_title' );
		$texts_in  = isset( $input['texts'] ) && is_array( $input['texts'] ) ? $input['texts'] : array();
		$texts     = array();
		foreach ( $text_keys as $tk ) {
			$texts[ $tk ] = isset( $texts_in[ $tk ] ) ? trim( strip_tags( (string) $texts_in[ $tk ] ) ) : '';
		}

		return array(
			'position'         => in_array( $pos, $positions, true ) ? $pos : 'middle-center',
			'preset'           => in_array( $pre, $presets, true ) ? $pre : 'neutral',
			'mode'             => in_array( $mode, $modes, true ) ? $mode : 'pro',
			'policy_version'   => '' !== $ver ? $ver : '1',
			'retention_months' => $ret >= 1 ? $ret : 13,
			'cookie_days'      => self::cap_cookie_days( $cd ),
			'privacy_page_id'  => self::pos_int( $input, 'privacy_page_id' ),
			'terms_page_id'    => self::pos_int( $input, 'terms_page_id' ),
			'cookie_page_id'   => self::pos_int( $input, 'cookie_page_id' ),
			'block_scripts'    => ! empty( $input['block_scripts'] ),
			'localize_fonts'   => ! empty( $input['localize_fonts'] ),
			'reopen_button'    => ! empty( $input['reopen_button'] ),
			'reload_on_decision' => ! empty( $input['reload_on_decision'] ),
			'texts'            => $texts,
		);
	}

	/** Pure: the stored operator data for the legal pages (four fields, sanitized). */
	public static function legal_meta( array $input ) {
		$email = isset( $input['contact_email'] ) ? trim( (string) $input['contact_email'] ) : '';
		return array(
			'company'       => isset( $input['company'] ) ? self::text( $input['company'] ) : '',
			'contact_email' => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '',
			'dpo'           => isset( $input['dpo'] ) ? self::text( $input['dpo'] ) : '',
			'jurisdiction'  => isset( $input['jurisdiction'] ) ? self::text( $input['jurisdiction'] ) : '',
			'address'       => isset( $input['address'] ) ? trim( function_exists( 'mb_substr' ) ? mb_substr( self::text( $input['address'] ), 0, 200, 'UTF-8' ) : substr( self::text( $input['address'] ), 0, 200 ) ) : '',
		);
	}

	/**
	 * Pure: carry the legal page links and operator data over from the stored
	 * settings. They are managed on the "Pagini legale" screen, so the main
	 * settings form does not post them and must not reset them.
	 */
	public static function keep_legal( array $clean, array $old ) {
		foreach ( array( 'privacy_page_id', 'terms_page_id', 'cookie_page_id' ) as $k ) {
			$clean[ $k ] = isset( $old[ $k ] ) ? max( 0, (int) $old[ $k ] ) : 0;
		}
		if ( isset( $old['legal_meta'] ) && is_array( $old['legal_meta'] ) ) {
			$clean['legal_meta'] = $old['legal_meta'];
		}
		return $clean;
	}

	public static function categories( array $input ) {
		$allowed_signals = array(
			'analytics_storage', 'ad_storage', 'ad_user_data', 'ad_personalization',
			'functionality_storage', 'personalization_storage', 'security_storage',
		);
		$out = array();
		foreach ( $input as $cat ) {
			if ( ! is_array( $cat ) ) {
				continue;
			}
			$slug = isset( $cat['slug'] ) ? self::slug( $cat['slug'] ) : '';
			if ( '' === $slug ) {
				continue;
			}
			$signals = array();
			foreach ( (array) ( isset( $cat['gtag_signals'] ) ? $cat['gtag_signals'] : array() ) as $sig ) {
				$sig = (string) $sig;
				if ( in_array( $sig, $allowed_signals, true ) && ! in_array( $sig, $signals, true ) ) {
					$signals[] = $sig;
				}
			}
			$cookies = array();
			foreach ( (array) ( isset( $cat['cookies'] ) ? $cat['cookies'] : array() ) as $ck ) {
				if ( ! is_array( $ck ) || empty( $ck['name'] ) ) {
					continue;
				}
				$cookies[] = array(
					'name'     => self::text( isset( $ck['name'] ) ? $ck['name'] : '' ),
					'provider' => self::text( isset( $ck['provider'] ) ? $ck['provider'] : '' ),
					'duration' => self::text( isset( $ck['duration'] ) ? $ck['duration'] : '' ),
					'purpose'  => self::text( isset( $ck['purpose'] ) ? $ck['purpose'] : '' ),
				);
			}
			$out[] = array(
				'slug'         => $slug,
				'label'        => self::text( isset( $cat['label'] ) ? $cat['label'] : '' ),
				'description'  => self::text( isset( $cat['description'] ) ? $cat['description'] : '' ),
				'required'     => ! empty( $cat['required'] ),
				'gtag_signals' => $signals,
				'cookies'      => $cookies,
			);
		}
		return $out;
	}

	/** Public suffixes that must never be a custom host (they would match every site under them). */
	const PUBLIC_SUFFIXES = 'co.uk org.uk ac.uk gov.uk com.au net.au co.jp com.br com.tr co.nz co.za com.md org.md net.md gov.md com.ro org.ro com.ru org.ru com.ua';

	/**
	 * Pure: is $host the site's own host ($own_host, e.g. home_url's), its parent
	 * domain or its www. variant? Such a custom host would block the site's own
	 * scripts (and every inline script that mentions the site URL).
	 */
	public static function is_own_host( $host, $own_host ) {
		$host = strtolower( trim( (string) $host ) );
		$own  = strtolower( trim( (string) $own_host ) );
		$slash = strpos( $host, '/' );
		if ( false !== $slash ) {
			$host = substr( $host, 0, $slash );
		}
		if ( '' === $host || '' === $own ) {
			return false;
		}
		return $host === $own
			|| substr( $own, -( strlen( $host ) + 1 ) ) === '.' . $host
			|| $host === 'www.' . $own;
	}

	/**
	 * Pure: a host (plus optional path) from free text; '' when it is not
	 * plausible, or when it is the site's own host ($own_host, see is_own_host()).
	 */
	public static function normalize_host( $raw, $own_host = '' ) {
		$h = trim( (string) $raw );
		$h = preg_replace( '#^([a-z][a-z0-9+.\-]*:)?//#i', '', $h );
		$h = preg_split( '/[?#]/', $h, 2 );
		$h = rtrim( $h[0], '/' );
		if ( '' === $h || strlen( $h ) > 253 ) {
			return '';
		}
		$slash = strpos( $h, '/' );
		$host  = strtolower( false === $slash ? $h : substr( $h, 0, $slash ) );
		$path  = false === $slash ? '' : substr( $h, $slash );
		$label = '[a-z0-9](?:[a-z0-9-]*[a-z0-9])?';
		if ( ! preg_match( '#^' . $label . '(?:\.' . $label . ')+$#', $host ) ) {
			return '';
		}
		if ( '' !== $path && ! preg_match( '#^/[A-Za-z0-9._~%!$&\'()*+,;=:@/-]*$#', $path ) ) {
			return '';
		}
		if ( preg_match( '/^\d+\.\d+\.\d+\.\d+$/', $host ) || in_array( $host, explode( ' ', self::PUBLIC_SUFFIXES ), true ) ) {
			return '';
		}
		if ( self::is_own_host( $host, $own_host ) ) {
			return '';
		}
		return $host . $path;
	}

	/**
	 * Service overrides and custom services from the admin form.
	 *
	 * @param array $input          Posted data: overrides[id] and custom[].
	 * @param array $library_ids    Ids of the library services (other override ids are dropped).
	 * @param array $category_slugs Valid category slugs (empty = unknown, no remapping).
	 * @param bool  $can_script     Whether the user may store raw script (unfiltered_html).
	 * @param array $old            The stored cmp_services option.
	 * @param array $library_categories Optional id => default category; overrides equal to the default are skipped.
	 * @param string $own_host      The site's own host (home_url); it and its parent domain are dropped from custom hosts.
	 */
	public static function services( array $input, array $library_ids, array $category_slugs, $can_script, array $old, array $library_categories = array(), $own_host = '' ) {
		$overrides = array();
		$raw_over  = isset( $input['overrides'] ) && is_array( $input['overrides'] ) ? $input['overrides'] : array();
		foreach ( $raw_over as $id => $o ) {
			if ( ! is_string( $id ) || ! in_array( $id, $library_ids, true ) || ! is_array( $o ) ) {
				continue;
			}
			$row = array();
			$cat = self::slug( isset( $o['category'] ) ? $o['category'] : '' );
			if ( '' !== $cat ) {
				$row['category'] = self::known_category( $cat, $category_slugs );
			}
			$row['exempt'] = self::flag( isset( $o['exempt'] ) ? $o['exempt'] : false );
			if ( isset( $library_categories[ $id ] ) && ! $row['exempt']
				&& ( ! isset( $row['category'] ) || $row['category'] === $library_categories[ $id ] ) ) {
				continue;
			}
			$overrides[ $id ] = $row;
		}

		$old_custom = array();
		$max        = isset( $old['seq'] ) && is_numeric( $old['seq'] ) ? max( 0, (int) $old['seq'] ) : 0;
		if ( isset( $old['custom'] ) && is_array( $old['custom'] ) ) {
			foreach ( $old['custom'] as $c ) {
				if ( is_array( $c ) && isset( $c['id'] ) && preg_match( '/^custom-(\d+)$/', (string) $c['id'], $m ) ) {
					$old_custom[ $c['id'] ] = $c;
					$max                    = max( $max, (int) $m[1] );
				}
			}
		}
		$raw_custom = isset( $input['custom'] ) && is_array( $input['custom'] ) ? array_values( $input['custom'] ) : array();
		foreach ( $raw_custom as $c ) {
			if ( is_array( $c ) && isset( $c['id'] ) && preg_match( '/^custom-(\d+)$/', (string) $c['id'], $m ) ) {
				$max = max( $max, (int) $m[1] );
			}
		}

		$custom = array();
		$used   = array();
		foreach ( $raw_custom as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$name  = mb_substr( self::text( isset( $c['name'] ) ? $c['name'] : '' ), 0, 100 );
			$hosts = array();
			$raw_h = isset( $c['hosts'] ) ? $c['hosts'] : array();
			if ( is_string( $raw_h ) ) {
				$raw_h = preg_split( '/[\s,]+/', $raw_h );
			}
			foreach ( (array) $raw_h as $h ) {
				$n = self::normalize_host( is_string( $h ) ? $h : '', $own_host );
				if ( '' !== $n && ! in_array( $n, $hosts, true ) ) {
					$hosts[] = $n;
				}
				if ( count( $hosts ) >= 20 ) {
					break;
				}
			}
			if ( '' === $name && ! $hosts ) {
				continue;
			}
			if ( count( $custom ) >= 50 ) {
				break;
			}
			$id = isset( $c['id'] ) ? (string) $c['id'] : '';
			if ( ! isset( $old_custom[ $id ] ) || isset( $used[ $id ] ) ) {
				$max++;
				$id = 'custom-' . $max;
			}
			$used[ $id ] = true;

			$cookies = array();
			$raw_c   = isset( $c['cookies'] ) && is_array( $c['cookies'] ) ? $c['cookies'] : array();
			foreach ( $raw_c as $ck ) {
				if ( ! is_array( $ck ) ) {
					continue;
				}
				$cname = mb_substr( self::text( isset( $ck['name'] ) ? $ck['name'] : '' ), 0, 200 );
				if ( '' === $cname ) {
					continue;
				}
				$cookies[] = array(
					'name'     => $cname,
					'duration' => mb_substr( self::text( isset( $ck['duration'] ) ? $ck['duration'] : '' ), 0, 200 ),
					'purpose'  => mb_substr( self::text( isset( $ck['purpose'] ) ? $ck['purpose'] : '' ), 0, 200 ),
				);
				if ( count( $cookies ) >= 30 ) {
					break;
				}
			}

			$category = self::known_category( self::slug( isset( $c['category'] ) ? $c['category'] : '' ), $category_slugs );
			$exempt   = self::flag( isset( $c['exempt'] ) ? $c['exempt'] : false );
			$old_c    = isset( $old_custom[ $id ] ) ? $old_custom[ $id ] : array();
			if ( $can_script ) {
				$script = mb_substr( isset( $c['script'] ) ? (string) $c['script'] : '', 0, 65535 );
			} else {
				$script = isset( $old_c['script'] ) ? (string) $old_c['script'] : '';
				if ( '' !== $script ) {
					// A stored script keeps the behaviour it was approved with.
					$category = isset( $old_c['category'] ) ? (string) $old_c['category'] : 'marketing';
					$exempt   = ! empty( $old_c['exempt'] );
					$hosts    = isset( $old_c['hosts'] ) ? array_values( (array) $old_c['hosts'] ) : array();
				}
			}

			$custom[] = array(
				'id'       => $id,
				'name'     => $name,
				'category' => $category,
				'exempt'   => $exempt,
				'hosts'    => $hosts,
				'cookies'  => $cookies,
				'script'   => $script,
			);
		}

		return array( 'overrides' => $overrides, 'custom' => $custom, 'seq' => $max );
	}

	/** A slug that exists (or 'necessary'); otherwise 'marketing'. Empty list = unknown, kept. */
	private static function known_category( $slug, array $category_slugs ) {
		if ( '' === $slug ) {
			return 'marketing';
		}
		if ( ! $category_slugs || 'necessary' === $slug || in_array( $slug, $category_slugs, true ) ) {
			return $slug;
		}
		return 'marketing';
	}

	private static function flag( $value ) {
		return true === $value || 1 === $value || in_array( $value, array( '1', 'on', 'true', 'yes' ), true );
	}

	private static function slug( $value ) {
		$value = strtolower( (string) $value );
		return preg_replace( '/[^a-z0-9_]/', '', $value );
	}

	private static function text( $value ) {
		return trim( strip_tags( (string) $value ) );
	}

	private static function pos_int( array $input, $key ) {
		$v = isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ? (int) $input[ $key ] : 0;
		return $v >= 0 ? $v : 0;
	}
}
