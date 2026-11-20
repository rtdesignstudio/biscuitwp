<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Ghid" screen: the user guide, one page with anchored sections, plus the
 * "Ghid" link each plugin screen shows in its header (link()).
 */
class CMP_Guide {

	const SLUG = 'cmp-guide';

	/** Section anchors, in page order. */
	const ANCHORS = array( 'start', 'modes', 'appearance', 'services', 'examples', 'verify', 'legal', 'update', 'register', 'developers' );

	/** Section titles, keyed by anchor. */
	public static function titles() {
		return array(
			'start'      => __( 'Pornire în 5 minute', 'consent-manager-pro' ),
			'modes'      => __( 'Ce mod alegi', 'consent-manager-pro' ),
			'appearance' => __( 'Aspectul bannerului', 'consent-manager-pro' ),
			'services'   => __( 'Servicii', 'consent-manager-pro' ),
			'examples'   => __( 'Exemple', 'consent-manager-pro' ),
			'verify'     => __( 'Cum verifici blocarea', 'consent-manager-pro' ),
			'legal'      => __( 'Pagini legale', 'consent-manager-pro' ),
			'update'     => __( 'După un update', 'consent-manager-pro' ),
			'register'   => __( 'Registru și date', 'consent-manager-pro' ),
			'developers' => __( 'Pentru developeri', 'consent-manager-pro' ),
		);
	}

	/**
	 * The --cmp-* custom properties a site's own CSS can set (on .cmp,
	 * .cmp-light, .cmp-reopen or .cmp-embed), with what each one does. Shown on
	 * the "Aspect" screen and in the guide's `appearance` section.
	 *
	 * @return array variable => description
	 */
	public static function variables() {
		return array(
			'--cmp-primary'       => __( 'culoarea principală: fundalul butoanelor „Accept” și „Refuz”, linkurile', 'consent-manager-pro' ),
			'--cmp-primary-hover' => __( 'culoarea principală la trecerea mouse-ului', 'consent-manager-pro' ),
			'--cmp-primary-text'  => __( 'textul de pe butoanele principale', 'consent-manager-pro' ),
			'--cmp-bg'            => __( 'fundalul bannerului', 'consent-manager-pro' ),
			'--cmp-dark'          => __( 'culoarea textului', 'consent-manager-pro' ),
			'--cmp-muted'         => __( 'textul secundar', 'consent-manager-pro' ),
			'--cmp-border'        => __( 'liniile și chenarele', 'consent-manager-pro' ),
			'--cmp-panel'         => __( 'fundalul blocurilor din preferințe', 'consent-manager-pro' ),
			'--cmp-radius'        => __( 'rotunjirea colțurilor (px)', 'consent-manager-pro' ),
			'--cmp-shadow'        => __( 'umbra ferestrei (box-shadow)', 'consent-manager-pro' ),
			'--cmp-maxw'          => __( 'lățimea maximă a ferestrei (px)', 'consent-manager-pro' ),
			'--cmp-gap'           => __( 'distanța față de marginea ecranului (px)', 'consent-manager-pro' ),
			'--cmp-dim'           => __( 'intensitatea întunecării fundalului paginii, de la 0 la 0.8', 'consent-manager-pro' ),
			'--cmp-blur'          => __( 'blurul fundalului paginii (px)', 'consent-manager-pro' ),
			'--cmp-font'          => __( 'fontul (font-family)', 'consent-manager-pro' ),
		);
	}

	/** URL of the guide, at $anchor when it is a known section. */
	public static function url( $anchor = '' ) {
		$url = admin_url( 'admin.php?page=' . self::SLUG );
		return in_array( $anchor, self::ANCHORS, true ) ? $url . '#' . $anchor : $url;
	}

	/**
	 * The "Ghid" link for a screen header, escaped HTML. An unknown anchor
	 * gives the link to the top of the guide.
	 */
	public static function link( $anchor ) {
		return '<a class="page-title-action cmp-guide-link" href="' . esc_url( self::url( (string) $anchor ) ) . '">' . esc_html__( 'Ghid', 'consent-manager-pro' ) . '</a>';
	}

	/** URL of a plugin screen (admin.php?page=<slug>). */
	public static function screen( $slug ) {
		return admin_url( 'admin.php?page=' . $slug );
	}

	/**
	 * A translated text with an opening and a closing link tag in place of
	 * %1$s and %2$s (and %3$s / %4$s for a second link). Not escaped: the
	 * template prints it through wp_kses_post().
	 *
	 * @param string $text  Translated text.
	 * @param string ...$slugs One plugin screen slug per link, or '#anchor' for a guide section.
	 */
	public static function with_links( $text, ...$slugs ) {
		$args = array();
		foreach ( $slugs as $slug ) {
			$href   = 0 === strpos( (string) $slug, '#' ) ? (string) $slug : self::screen( $slug );
			$args[] = '<a href="' . esc_url( $href ) . '">';
			$args[] = '</a>';
		}
		// A broken translation asking for more arguments than there are would
		// throw (PHP 8) or return false (PHP 7.4): show the text escaped instead.
		if ( self::placeholder_count( $text ) > count( $args ) ) {
			return esc_html( $text );
		}
		$out = vsprintf( $text, $args );
		return false === $out ? esc_html( $text ) : $out;
	}

	/**
	 * Pure: how many arguments a printf format needs (highest %n$ position,
	 * or the count of unnumbered placeholders). %% is not a placeholder.
	 */
	public static function placeholder_count( $text ) {
		$text = str_replace( '%%', '', (string) $text );
		if ( ! preg_match_all( '/%(?:(\d+)\$)?[-+ 0#]*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/', $text, $m ) ) {
			return 0;
		}
		$max  = 0;
		$free = 0;
		foreach ( $m[1] as $pos ) {
			if ( '' === $pos ) {
				$free++;
			} else {
				$max = max( $max, (int) $pos );
			}
		}
		return max( $max, $free );
	}

	/** Inline code for a placeholder: <code>escaped</code>. */
	public static function code( $text ) {
		return '<code>' . esc_html( $text ) . '</code>';
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$titles = self::titles();
		include dirname( __DIR__, 2 ) . '/templates/admin/guide.php';
	}
}
