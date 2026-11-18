<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone-loadable for tests; WP-only methods guarded at call time.
}

require_once __DIR__ . '/class-lang.php';

class CMP_Legal {

	const TYPES = array( 'privacy', 'terms', 'cookie' );

	/** Pure: 'published', 'draft' (not visible to visitors) or 'missing', from a post status. */
	public static function page_status( $post_status ) {
		if ( 'publish' === $post_status ) {
			return 'published';
		}
		if ( in_array( $post_status, array( 'draft', 'pending', 'private', 'future', 'auto-draft' ), true ) ) {
			return 'draft';
		}
		return 'missing';
	}

	/** Pure: how many legal pages are published / drafts / missing. */
	public static function status_summary( array $statuses ) {
		$out = array( 'published' => 0, 'draft' => 0, 'missing' => 0 );
		foreach ( $statuses as $st ) {
			if ( isset( $out[ $st ] ) ) {
				$out[ $st ]++;
			}
		}
		return $out;
	}

	public static function fill_template( $tpl, array $vars ) {
		$out = (string) $tpl;
		foreach ( $vars as $k => $v ) {
			$out = str_replace( '{{' . $k . '}}', (string) $v, $out );
		}
		return $out;
	}

	public static function load_template( $type ) {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return '';
		}
		$path = dirname( __DIR__, 2 ) . '/templates/legal/' . $type . '.html';
		return is_readable( $path ) ? (string) file_get_contents( $path ) : '';
	}

	/** Pure: a per-language value ({ro,ru,en}) in $lang, falling back to ro. */
	private static function loc( $value, $lang ) {
		if ( ! is_array( $value ) ) {
			return (string) $value;
		}
		if ( isset( $value[ $lang ] ) && '' !== (string) $value[ $lang ] ) {
			return (string) $value[ $lang ];
		}
		return isset( $value['ro'] ) ? (string) $value['ro'] : '';
	}

	/**
	 * Pure: the services to list in the cookie table: the ones found on the site
	 * (in found order, any category), then the custom services not found. Each
	 * once; services without cookies and unknown ids are skipped.
	 */
	public static function table_services( array $found_ids, array $services ) {
		$by_id = array();
		foreach ( $services as $s ) {
			if ( is_array( $s ) && isset( $s['id'] ) && is_string( $s['id'] ) && ! isset( $by_id[ $s['id'] ] ) ) {
				$by_id[ $s['id'] ] = $s;
			}
		}
		$out  = array();
		$seen = array();
		$add  = function ( $s ) use ( &$out, &$seen ) {
			if ( isset( $seen[ $s['id'] ] ) || empty( $s['cookies'] ) || ! is_array( $s['cookies'] ) ) {
				return;
			}
			$seen[ $s['id'] ] = true;
			$out[]            = $s;
		};
		foreach ( $found_ids as $id ) {
			if ( is_string( $id ) && isset( $by_id[ $id ] ) ) {
				$add( $by_id[ $id ] );
			}
		}
		foreach ( $by_id as $s ) {
			if ( isset( $s['source'] ) && 'custom' === $s['source'] ) {
				$add( $s );
			}
		}
		return $out;
	}

	/** WP: table services from the last scan (stored ids, never scans) and the active services. */
	public static function current_table_services() {
		$found = get_option( 'cmp_services_found', array() );
		return self::table_services( is_array( $found ) ? $found : array(), CMP_Services::active() );
	}

	public static function cookie_table_html( array $categories, array $services = array(), $lang = 'ro' ) {
		$lang  = in_array( $lang, array( 'ro', 'ru', 'en' ), true ) ? $lang : 'ro';
		$none  = CMP_Lang::text( 'tbl_none', $lang );
		$labels = array();
		foreach ( $categories as $cat ) {
			if ( isset( $cat['slug'] ) ) {
				$labels[ $cat['slug'] ] = CMP_Lang::localize_seed( $cat['slug'], 'label', isset( $cat['label'] ) ? $cat['label'] : $cat['slug'], $lang );
			}
		}
		$rows = '';
		foreach ( $services as $svc ) {
			$slug  = isset( $svc['category'] ) ? $svc['category'] : '';
			$label = isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug;
			$tr    = isset( $svc['transfer'] ) && '' !== (string) $svc['transfer'] ? $svc['transfer'] : $none;
			foreach ( (array) ( isset( $svc['cookies'] ) ? $svc['cookies'] : array() ) as $ck ) {
				$rows .= '<tr>'
					. '<td>' . self::esc( isset( $ck['name'] ) ? $ck['name'] : '' ) . '</td>'
					. '<td>' . self::esc( isset( $svc['provider'] ) ? $svc['provider'] : '' ) . '</td>'
					. '<td>' . self::esc( self::loc( isset( $ck['duration'] ) ? $ck['duration'] : '', $lang ) ) . '</td>'
					. '<td>' . self::esc( self::loc( isset( $ck['purpose'] ) ? $ck['purpose'] : '', $lang ) ) . '</td>'
					. '<td>' . self::esc( $label ) . '</td>'
					. '<td>' . self::esc( $tr ) . '</td>'
					. '</tr>';
			}
		}
		foreach ( $categories as $cat ) {
			$cat_label = isset( $cat['slug'] ) && isset( $labels[ $cat['slug'] ] ) ? $labels[ $cat['slug'] ] : ( isset( $cat['label'] ) ? $cat['label'] : '' );
			foreach ( (array) ( isset( $cat['cookies'] ) ? $cat['cookies'] : array() ) as $ck ) {
				$rows .= '<tr>'
					. '<td>' . self::esc( isset( $ck['name'] ) ? $ck['name'] : '' ) . '</td>'
					. '<td>' . self::esc( isset( $ck['provider'] ) ? $ck['provider'] : '' ) . '</td>'
					. '<td>' . self::esc( isset( $ck['duration'] ) ? $ck['duration'] : '' ) . '</td>'
					. '<td>' . self::esc( isset( $ck['purpose'] ) ? $ck['purpose'] : '' ) . '</td>'
					. '<td>' . self::esc( $cat_label ) . '</td>'
					. '<td>' . self::esc( $none ) . '</td>'
					. '</tr>';
			}
		}
		if ( '' === $rows ) {
			return '<p>' . self::esc( CMP_Lang::text( 'tbl_empty', $lang ) ) . '</p>';
		}
		return '<table><thead><tr>'
			. '<th>' . self::esc( CMP_Lang::text( 'tbl_name', $lang ) ) . '</th><th>' . self::esc( CMP_Lang::text( 'tbl_provider', $lang ) ) . '</th><th>' . self::esc( CMP_Lang::text( 'tbl_duration', $lang ) ) . '</th><th>' . self::esc( CMP_Lang::text( 'tbl_purpose', $lang ) ) . '</th><th>' . self::esc( CMP_Lang::text( 'tbl_category', $lang ) ) . '</th><th>' . self::esc( CMP_Lang::text( 'tbl_transfer', $lang ) ) . '</th>'
			. '</tr></thead><tbody>' . $rows . '</tbody></table>';
	}

	public static function sc_cookie_table() {
		return self::cookie_table_html( CMP_Consent::get_categories(), self::current_table_services(), CMP_Lang::current() );
	}

	private static function esc( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}

	public static function register() {
		add_shortcode( 'cmp_privacy_link', array( __CLASS__, 'sc_privacy' ) );
		add_shortcode( 'cmp_terms_link', array( __CLASS__, 'sc_terms' ) );
		add_shortcode( 'cmp_cookie_table', array( __CLASS__, 'sc_cookie_table' ) );
		add_shortcode( 'cmp_cookie_settings_link', array( __CLASS__, 'sc_cookie_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_guide' ) );
		add_action( 'cmp_legal_page_created', array( __CLASS__, 'on_page_created' ), 10, 2 );
	}

	private static function page_link( $key, $fallback_label ) {
		$s   = CMP_Consent::get_settings();
		$pid = (int) ( isset( $s[ $key ] ) ? $s[ $key ] : 0 );
		if ( $pid && 'publish' === get_post_status( $pid ) ) {
			return '<a href="' . esc_url( (string) get_permalink( $pid ) ) . '">' . esc_html( get_the_title( $pid ) ) . '</a>';
		}
		return esc_html( $fallback_label );
	}

	public static function sc_privacy() {
		return self::page_link( 'privacy_page_id', __( 'Politica de confidențialitate', 'consent-manager-pro' ) );
	}

	public static function sc_terms() {
		return self::page_link( 'terms_page_id', __( 'Termeni și condiții', 'consent-manager-pro' ) );
	}

	public static function sc_cookie_settings() {
		$label = esc_html( CMP_Lang::text( 'reopen' ) );
		// Opened by the banner script's delegated click handler (no inline JS: CSP-safe).
		return '<a href="#cmp-settings" class="cmp-settings-link cmp-cookie-settings-link" data-cmp-settings>' . $label . '</a>';
	}

	public static function privacy_guide() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$table = self::cookie_table_html( CMP_Consent::get_categories(), self::current_table_services(), 'ro' );
		wp_add_privacy_policy_content(
			'Consent Manager Pro',
			wp_kses_post( '<p>' . __( 'Acest site folosește cookie-uri gestionate prin Consent Manager Pro. Cookie-urile utilizate:', 'consent-manager-pro' ) . '</p>' . $table )
		);
	}

	public static function on_page_created( $post_id, $type ) {
		$post_id = (int) $post_id;
		if ( ! $post_id ) {
			return;
		}

		// Polylang.
		if ( function_exists( 'pll_set_post_language' ) && function_exists( 'pll_save_post_translations' ) && function_exists( 'pll_languages_list' ) && function_exists( 'pll_default_language' ) ) {
			self::duplicate_polylang( $post_id );
			return;
		}

		// WPML.
		if ( defined( 'ICL_SITEPRESS_VERSION' ) && function_exists( 'icl_object_id' ) ) {
			self::duplicate_wpml( $post_id );
		}
	}

	private static function duplicate_polylang( $source_id ) {
		$default = pll_default_language();
		if ( ! $default ) {
			return;
		}
		if ( ! ( function_exists( 'pll_get_post_language' ) && pll_get_post_language( $source_id ) ) ) {
			pll_set_post_language( $source_id, $default );
		}

		$translations = array( $default => $source_id );
		$source       = get_post( $source_id );
		foreach ( (array) pll_languages_list() as $lang ) {
			if ( $lang === $default ) {
				continue;
			}
			// Skip if a translation already exists.
			if ( function_exists( 'pll_get_post' ) && pll_get_post( $source_id, $lang ) ) {
				$translations[ $lang ] = (int) pll_get_post( $source_id, $lang );
				continue;
			}
			$copy_id = wp_insert_post( array(
				'post_title'   => $source->post_title,
				'post_content' => $source->post_content,
				'post_status'  => 'draft',
				'post_type'    => 'page',
			), true );
			if ( $copy_id && ! is_wp_error( $copy_id ) ) {
				pll_set_post_language( $copy_id, $lang );
				$translations[ $lang ] = (int) $copy_id;
			}
		}
		pll_save_post_translations( $translations );
	}

	private static function duplicate_wpml( $source_id ) {
		$default = apply_filters( 'wpml_default_language', null );
		$trid    = apply_filters( 'wpml_element_trid', null, $source_id, 'post_page' );

		// Ensure the source is registered in the default language.
		do_action( 'wpml_set_element_language_details', array(
			'element_id'           => $source_id,
			'element_type'         => 'post_page',
			'trid'                 => $trid,
			'language_code'        => $default,
			'source_language_code' => null,
		) );

		$langs = apply_filters( 'wpml_active_languages', null );
		if ( ! is_array( $langs ) ) {
			return;
		}
		$source = get_post( $source_id );
		foreach ( $langs as $code => $lang ) {
			if ( $code === $default ) {
				continue;
			}
			$existing = apply_filters( 'wpml_object_id', $source_id, 'page', false, $code );
			if ( $existing && (int) $existing !== $source_id ) {
				continue; // translation already exists
			}
			$copy_id = wp_insert_post( array(
				'post_title'   => $source->post_title,
				'post_content' => $source->post_content,
				'post_status'  => 'draft',
				'post_type'    => 'page',
			), true );
			if ( $copy_id && ! is_wp_error( $copy_id ) ) {
				do_action( 'wpml_set_element_language_details', array(
					'element_id'           => $copy_id,
					'element_type'         => 'post_page',
					'trid'                 => $trid,
					'language_code'        => $code,
					'source_language_code' => $default,
				) );
			}
		}
	}
}
