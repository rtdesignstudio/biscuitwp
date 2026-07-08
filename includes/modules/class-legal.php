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
}
