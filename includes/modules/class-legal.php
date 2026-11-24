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

	/**
	 * Fill a template. {{#key}}...{{/key}} blocks (may span lines, not nested) are
	 * kept only when $vars[key] is non-empty; then {{key}} is replaced (values
	 * as given, no escaping: the operator fields are stripped on save); any
	 * placeholder still left is removed.
	 */
	public static function fill_template( $tpl, array $vars ) {
		$out = (string) $tpl;
		$out = preg_replace_callback(
			'/\{\{#([a-z0-9_]+)\}\}(.*?)\{\{\/\1\}\}/s',
			function ( $m ) use ( $vars ) {
				return isset( $vars[ $m[1] ] ) && '' !== trim( (string) $vars[ $m[1] ] ) ? $m[2] : '';
			},
			$out
		);
		foreach ( $vars as $k => $v ) {
			$out = str_replace( '{{' . $k . '}}', (string) $v, $out );
		}
		return preg_replace( '/\{\{[^{}]*\}\}/', '', $out );
	}

	/** Template for $type in $lang (ro/ru/en), falling back to the ro one. '' for an unknown type. */
	public static function load_template( $type, $lang = 'ro' ) {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return '';
		}
		$base  = dirname( __DIR__, 2 ) . '/templates/legal/';
		$lang  = in_array( $lang, array( 'ro', 'ru', 'en' ), true ) ? $lang : 'ro';
		foreach ( array_unique( array( $lang, 'ro' ) ) as $l ) {
			$path = $base . $l . '/' . $type . '.html';
			if ( is_readable( $path ) ) {
				return (string) file_get_contents( $path );
			}
		}
		return '';
	}

	/** Pure: a WPML/Polylang language code ("ru", "ru_RU", "en-US", "de") as ro|ru|en (ro by default). */
	public static function page_lang_for( $code ) {
		return CMP_Lang::normalize( $code );
	}

	/** Pure: WordPress locale for a ro|ru|en page language. */
	public static function locale_for( $lang ) {
		$map = array( 'ro' => 'ro_RO', 'ru' => 'ru_RU', 'en' => 'en_US' );
		$l   = self::page_lang_for( $lang );
		return $map[ $l ];
	}

	/** WP: the site's primary language as ro|ru|en (WPML, then Polylang, then the locale). */
	public static function primary_lang() {
		$code = '';
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$code = (string) apply_filters( 'wpml_default_language', null );
		}
		if ( '' === $code && function_exists( 'pll_default_language' ) ) {
			$code = (string) pll_default_language();
		}
		if ( '' === $code && function_exists( 'get_locale' ) ) {
			$code = (string) get_locale();
		}
		return self::page_lang_for( $code );
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
	 * once; unknown ids are skipped. Services without cookies are listed too:
	 * cookie_table_html() gives them one row, so a cookieless tool that loads
	 * without consent (and its transfer country) is still disclosed.
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
			if ( isset( $seen[ $s['id'] ] ) || ( isset( $s['cookies'] ) && ! is_array( $s['cookies'] ) ) ) {
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

	/**
	 * Pure: what the cookie table lists in a mode. Light loads only necessary
	 * and exempt services (library or custom) and blocks the rest for good, so
	 * only those are listed, and the manually declared cookies only of the
	 * required categories (the optional ones' are dropped; labels stay). Pro
	 * (or any other mode): unchanged.
	 *
	 * @return array{categories: array, services: array}
	 */
	public static function table_for_mode( array $categories, array $services, $mode ) {
		if ( 'light' !== $mode ) {
			return array( 'categories' => $categories, 'services' => $services );
		}
		$svcs = array();
		foreach ( $services as $s ) {
			if ( is_array( $s ) && ( ( isset( $s['category'] ) && 'necessary' === $s['category'] ) || ! empty( $s['exempt'] ) ) ) {
				$svcs[] = $s;
			}
		}
		$cats = array();
		foreach ( $categories as $cat ) {
			if ( is_array( $cat ) && empty( $cat['required'] ) && ! ( isset( $cat['slug'] ) && 'necessary' === $cat['slug'] ) ) {
				$cat['cookies'] = array();
			}
			$cats[] = $cat;
		}
		return array( 'categories' => $cats, 'services' => $svcs );
	}

	/** WP: the cookie table for the current mode, in $lang. */
	private static function current_table_html( $lang ) {
		$s     = CMP_Consent::get_settings();
		$table = self::table_for_mode( (array) CMP_Consent::get_categories(), self::current_table_services(), isset( $s['mode'] ) ? $s['mode'] : 'pro' );
		return self::cookie_table_html( $table['categories'], $table['services'], $lang );
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
			if ( empty( $svc['cookies'] ) ) {
				// Cookieless service: one row naming the service itself.
				if ( isset( $svc['nocookie_purpose'] ) && '' !== self::loc( $svc['nocookie_purpose'], $lang ) ) {
					$purpose = self::loc( $svc['nocookie_purpose'], $lang );
				} else {
					$purpose = CMP_Lang::text( 'statistics' === $slug ? 'tbl_nocookie_stats' : 'tbl_nocookie', $lang );
				}
				$rows .= '<tr>'
					. '<td>' . self::esc( isset( $svc['name'] ) && '' !== (string) $svc['name'] ? $svc['name'] : ( isset( $svc['id'] ) ? $svc['id'] : '' ) ) . '</td>'
					. '<td>' . self::esc( isset( $svc['provider'] ) ? $svc['provider'] : '' ) . '</td>'
					. '<td>' . self::esc( $none ) . '</td>'
					. '<td>' . self::esc( $purpose ) . '</td>'
					. '<td>' . self::esc( $label ) . '</td>'
					. '<td>' . self::esc( $tr ) . '</td>'
					. '</tr>';
				continue;
			}
			foreach ( (array) $svc['cookies'] as $ck ) {
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
		return self::current_table_html( CMP_Lang::current() );
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
		return self::page_link( 'privacy_page_id', __( 'Politica de confidențialitate', 'biscuitwp' ) );
	}

	public static function sc_terms() {
		return self::page_link( 'terms_page_id', __( 'Termeni și condiții', 'biscuitwp' ) );
	}

	public static function sc_cookie_settings() {
		$label = esc_html( CMP_Lang::text( 'reopen' ) );
		// Opened by the banner script's delegated click handler (no inline JS: CSP-safe).
		return '<a href="#cmp-settings" class="cmp-settings-link cmp-cookie-settings-link" data-cmp-settings>' . $label . '</a>';
	}

	public static function privacy_guide() {
		// Real admin page loads only: admin_init also fires on admin-ajax.php,
		// including every visitor's public consent-log call.
		if ( ! self::guide_wanted( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax(), function_exists( 'is_admin' ) && is_admin() ) ) {
			return;
		}
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$table = self::current_table_html( 'ro' );
		wp_add_privacy_policy_content(
			'BiscuitWP',
			wp_kses_post( '<p>' . __( 'Acest site folosește cookie-uri gestionate prin BiscuitWP. Cookie-urile utilizate:', 'biscuitwp' ) . '</p>' . $table )
		);
	}

	/** Pure: build the privacy-guide text on this request? Admin page loads only, never AJAX. */
	public static function guide_wanted( $doing_ajax, $is_admin ) {
		return ! $doing_ajax && $is_admin;
	}

	public static function on_page_created( $post_id, $type ) {
		$post_id = (int) $post_id;
		if ( ! $post_id || ! in_array( $type, self::TYPES, true ) ) {
			return;
		}

		// Polylang.
		if ( function_exists( 'pll_set_post_language' ) && function_exists( 'pll_save_post_translations' ) && function_exists( 'pll_languages_list' ) && function_exists( 'pll_default_language' ) ) {
			self::duplicate_polylang( $post_id, $type );
			return;
		}

		// WPML.
		if ( defined( 'ICL_SITEPRESS_VERSION' ) && function_exists( 'icl_object_id' ) ) {
			self::duplicate_wpml( $post_id, $type );
		}
	}

	/** WP: the stored plugin settings, as an array. */
	private static function stored_settings() {
		$s = CMP_Consent::get_settings();
		return is_array( $s ) ? $s : array();
	}

	/** WP: operator data stored on the "Pagini legale" screen. */
	private static function stored_meta() {
		$s = CMP_Consent::get_settings();
		return isset( $s['legal_meta'] ) && is_array( $s['legal_meta'] ) ? $s['legal_meta'] : array();
	}

	/**
	 * Opening words of the generated-document header (0.3.x-0.4.x and RO/RU/EN
	 * since 0.5.0). The product was renamed to BiscuitWP after 1.0.0; pages
	 * generated before carry the former name, so both are recognised.
	 */
	const GENERATED_HEADERS = array(
		'Document generat automat de BiscuitWP',
		'Документ автоматически создан BiscuitWP',
		'Document generated automatically by BiscuitWP',
		'Document generat automat de Consent Manager Pro',
		'Документ автоматически создан Consent Manager Pro',
		'Document generated automatically by Consent Manager Pro',
	);

	/** Pure: does the page text start with the plugin's generated-document header? */
	public static function has_generated_header( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return false;
		}
		// Block comments and tags out, then the first visible words.
		$text = preg_replace( '/<!--.*?-->/s', '', substr( $content, 0, 2000 ) );
		$text = html_entity_decode( strip_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
		$text = ltrim( $text, " \t\n\r\0\x0B\xC2\xA0" );
		foreach ( self::GENERATED_HEADERS as $h ) {
			if ( 0 === strpos( $text, $h ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Pure: may regeneration overwrite this linked translation? Yes when it
	 * carries the mark for this type, or when it has no mark and starts with
	 * the generated-document header (a copy 0.4.x made, before the mark
	 * existed). Never a page marked for another type, never an admin's page.
	 */
	public static function refreshable_translation( $mark, $type, $content ) {
		if ( $mark === $type ) {
			return true;
		}
		return ( '' === $mark || false === $mark || null === $mark ) && self::has_generated_header( $content );
	}

	/**
	 * WP: one translation of a legal page, built from its own language's
	 * template. A new page is a draft marked with _cmp_legal_type; an existing
	 * linked translation is refreshed only when the plugin made it (the mark,
	 * or the generated-document header of a 0.4.x copy, which then gets the
	 * mark), never otherwise. Returns the page id, or 0.
	 */
	private static function sync_translation( $existing_id, $type, $code ) {
		if ( ! class_exists( 'CMP_Legal_Page' ) ) {
			require_once dirname( __DIR__ ) . '/admin/class-legal-page.php';
		}
		$lang = self::page_lang_for( $code );
		$meta = self::stored_meta();
		if ( $existing_id ) {
			$post = get_post( $existing_id );
			if ( $post && self::refreshable_translation( get_post_meta( $existing_id, '_cmp_legal_type', true ), $type, (string) $post->post_content ) ) {
				wp_update_post( array( 'ID' => $existing_id, 'post_content' => CMP_Legal_Page::build_content( $type, $meta, $lang ) ) );
				update_post_meta( $existing_id, '_cmp_legal_type', $type );
				update_post_meta( $existing_id, '_cmp_legal_mode', CMP_Legal_Page::page_mode( self::stored_settings(), CMP_Legal_Page::services_history() ) );
			}
			return (int) $existing_id;
		}
		$id = wp_insert_post( array(
			'post_title'   => CMP_Lang::text( 'title_' . $type, $lang ),
			'post_content' => CMP_Legal_Page::build_content( $type, $meta, $lang ),
			'post_status'  => 'draft',
			'post_type'    => 'page',
		), true );
		if ( ! $id || is_wp_error( $id ) ) {
			return 0;
		}
		update_post_meta( $id, '_cmp_legal_type', $type );
		update_post_meta( $id, '_cmp_legal_mode', CMP_Legal_Page::page_mode( self::stored_settings(), CMP_Legal_Page::services_history() ) );
		return (int) $id;
	}

	private static function duplicate_polylang( $source_id, $type ) {
		$default = pll_default_language();
		if ( ! $default ) {
			return;
		}
		if ( ! ( function_exists( 'pll_get_post_language' ) && pll_get_post_language( $source_id ) ) ) {
			pll_set_post_language( $source_id, $default );
		}

		$src_lang     = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $source_id ) : '';
		$default      = $src_lang ? $src_lang : $default;
		$translations = array( $default => $source_id );
		foreach ( (array) pll_languages_list() as $lang ) {
			if ( $lang === $default ) {
				continue;
			}
			$existing = function_exists( 'pll_get_post' ) ? (int) pll_get_post( $source_id, $lang ) : 0;
			$id       = self::sync_translation( $existing, $type, $lang );
			if ( $id ) {
				if ( ! $existing ) {
					pll_set_post_language( $id, $lang );
				}
				$translations[ $lang ] = $id;
			}
		}
		pll_save_post_translations( $translations );
	}

	private static function duplicate_wpml( $source_id, $type ) {
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
		// Re-read: a source not yet registered had no trid before the call above.
		$trid = apply_filters( 'wpml_element_trid', null, $source_id, 'post_page' );

		$langs = apply_filters( 'wpml_active_languages', null );
		if ( ! is_array( $langs ) ) {
			return;
		}
		foreach ( $langs as $code => $lang ) {
			if ( $code === $default ) {
				continue;
			}
			$existing = (int) apply_filters( 'wpml_object_id', $source_id, 'page', false, $code );
			if ( $existing === (int) $source_id ) {
				$existing = 0;
			}
			$id = self::sync_translation( $existing, $type, $code );
			if ( $id && ! $existing ) {
				do_action( 'wpml_set_element_language_details', array(
					'element_id'           => $id,
					'element_type'         => 'post_page',
					'trid'                 => $trid,
					'language_code'        => $code,
					'source_language_code' => $default,
				) );
			}
		}
	}
}
