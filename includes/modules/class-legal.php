<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone-loadable for tests; WP-only methods guarded at call time.
}

class CMP_Legal {

	const TYPES = array( 'privacy', 'terms', 'cookie' );

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

	public static function cookie_table_html( array $categories ) {
		$rows = '';
		foreach ( $categories as $cat ) {
			$cat_label = isset( $cat['label'] ) ? $cat['label'] : ( isset( $cat['slug'] ) ? $cat['slug'] : '' );
			foreach ( (array) ( isset( $cat['cookies'] ) ? $cat['cookies'] : array() ) as $ck ) {
				$rows .= '<tr>'
					. '<td>' . self::esc( isset( $ck['name'] ) ? $ck['name'] : '' ) . '</td>'
					. '<td>' . self::esc( isset( $ck['provider'] ) ? $ck['provider'] : '' ) . '</td>'
					. '<td>' . self::esc( isset( $ck['duration'] ) ? $ck['duration'] : '' ) . '</td>'
					. '<td>' . self::esc( isset( $ck['purpose'] ) ? $ck['purpose'] : '' ) . '</td>'
					. '<td>' . self::esc( $cat_label ) . '</td>'
					. '</tr>';
			}
		}
		if ( '' === $rows ) {
			return '<p>Nu au fost definite cookie-uri.</p>';
		}
		return '<table><thead><tr>'
			. '<th>Nume</th><th>Furnizor</th><th>Durată</th><th>Scop</th><th>Categorie</th>'
			. '</tr></thead><tbody>' . $rows . '</tbody></table>';
	}

	private static function esc( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}

	public static function register() {
		add_shortcode( 'cmp_privacy_link', array( __CLASS__, 'sc_privacy' ) );
		add_shortcode( 'cmp_terms_link', array( __CLASS__, 'sc_terms' ) );
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
		$label = esc_html__( 'Setări cookie-uri', 'consent-manager-pro' );
		return '<button type="button" class="cmp-cookie-settings-link" onclick="if(window.CMP){CMP.openPreferences();}return false;">' . $label . '</button>';
	}

	public static function privacy_guide() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$table = self::cookie_table_html( CMP_Consent::get_categories() );
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
		pll_set_post_language( $source_id, $default );

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
			) );
			if ( $copy_id && ! is_wp_error( $copy_id ) ) {
				pll_set_post_language( $copy_id, $lang );
				$translations[ $lang ] = (int) $copy_id;
			}
		}
		pll_save_post_translations( $translations );
	}

	private static function duplicate_wpml( $source_id ) {
		global $sitepress;
		$default = apply_filters( 'wpml_default_language', null );
		$info    = apply_filters( 'wpml_element_language_details', null, array( 'element_id' => $source_id, 'element_type' => 'post_page' ) );
		$trid    = ( $info && isset( $info->trid ) ) ? $info->trid : null;

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
		$trid   = $trid ? $trid : apply_filters( 'wpml_element_trid', null, $source_id, 'post_page' );
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
			) );
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
