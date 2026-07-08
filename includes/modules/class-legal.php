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
		// Shortcodes + privacy guide added in Task 4.
	}
}
