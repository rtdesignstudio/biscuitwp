<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * B6: the "Aspect" screen (submenu right after "Setări"). Everything about how
 * the banner looks: style, colours, theme, format and position, backdrop,
 * details, buttons, texts, animation and delay, reopen button, exclusions and
 * custom CSS. Stored in cmp_settings['appearance'] (CMP_Appearance); the save
 * goes through CMP_Sanitizer::aspect() with the stored settings.
 *
 * In Light mode the fields that do not apply to the Light card are disabled
 * (so they are not posted and keep their stored values).
 */
class CMP_Appearance_Page {

	const SLUG = 'cmp-appearance';

	/** Transient prefix (+ user id): the custom CSS parts removed at the last save. */
	const REMOVED_KEY = 'cmp_css_removed_';

	/** At most this many removed parts are listed (the rest are counted). */
	const REMOVED_MAX = 20;

	/** Name of the upload field of the import form. */
	const IMPORT_FIELD = 'cmp_appearance_file';

	/** Test seams: is_uploaded_file and header() (the tests run without a web request). */
	public static $upload_check = 'is_uploaded_file';
	public static $header_fn    = 'header';

	/** Appearance keys that do not apply in Light (disabled there). */
	const LIGHT_OFF = array( 'format', 'position', 'backdrop', 'dim', 'blur', 'maxw', 'buttons', 'reopen', 'image', 'image_id', 'image_alt', 'image_side', 'image_size', 'image_out', 'image_phone', 'logo', 'logo_id' );

	/** Texts per mode: the Light card shows only light_intro and accept. */
	const TEXT_MODES = array(
		'title'       => 'pro',
		'intro'       => 'pro',
		'light_intro' => 'light',
		'accept'      => '',
		'reject'      => 'pro',
		'prefs'       => 'pro',
		'save'        => 'pro',
		'prefs_title' => 'pro',
	);

	/**
	 * Pure: the appearance the form shows. The reopen field shows what the
	 * banner does: the old reopen_button checkbox off = "Deloc", whatever the
	 * stored shape (the banner needs both).
	 *
	 * @param string $lang Current language for the migrated old texts ('' = detect).
	 */
	public static function form_values( array $settings, $lang = '' ) {
		$a = CMP_Appearance::from_settings( $settings, $lang );
		if ( empty( $settings['reopen_button'] ) ) {
			$a['reopen'] = 'none';
		}
		return $a;
	}

	/** Pure: each style's values (CMP_Appearance::STYLE_KEYS), from CMP_Appearance::defaults(). */
	public static function style_defaults() {
		$out = array();
		foreach ( CMP_Appearance::STYLES as $style ) {
			$d             = CMP_Appearance::defaults( $style );
			$out[ $style ] = array();
			foreach ( CMP_Appearance::STYLE_KEYS as $k ) {
				$out[ $style ][ $k ] = $d[ $k ];
			}
		}
		return $out;
	}

	/** CMP_APPEARANCE for assets/js/admin-appearance.js. */
	public static function script_data() {
		return array(
			'styles'   => self::style_defaults(),
			'bg'       => CMP_Appearance::PALETTE_BG,
			'minRatio' => CMP_Appearance::MIN_RATIO,
			'i18n'     => array(
				'confirmStyle' => __( 'Schimbi stilul? Colțurile, umbra, lățimea, butoanele și animația revin la valorile noului stil, iar tu îți pierzi ajustările.', 'biscuitwp' ),
				/* translators: %s: contrast ratio, e.g. 5.2 */
				'ok'           => __( 'Contrast %s:1, trece.', 'biscuitwp' ),
				/* translators: 1: contrast ratio, 2: proposed colour (#rrggbb), 3: its contrast ratio */
				'fail'         => __( 'Contrast %1$s:1, sub 4,5:1. Nuanța propusă: %2$s (%3$s:1).', 'biscuitwp' ),
				'invalid'      => __( 'Scrie o culoare în forma #rrggbb.', 'biscuitwp' ),
				'imageTitle'   => __( 'Imaginea din banner', 'biscuitwp' ),
				'imageUse'     => __( 'Folosește imaginea', 'biscuitwp' ),
				'logoTitle'    => __( 'Logo-ul din setări', 'biscuitwp' ),
			),
		);
	}

	/** The thumbnail of a chosen media library image ('' when none). */
	public static function image_thumb( $id ) {
		$src = $id > 0 && function_exists( 'wp_get_attachment_image_src' ) ? wp_get_attachment_image_src( $id, 'thumbnail' ) : false;
		return is_array( $src ) && isset( $src[0] ) ? (string) $src[0] : '';
	}

	/** Pure: the notice listing the custom CSS parts removed at save ('' when none). Escaped HTML. */
	public static function removed_notice( array $removed, $saved = true ) {
		if ( ! $removed ) {
			return '';
		}
		$items = '';
		foreach ( array_slice( $removed, 0, self::REMOVED_MAX ) as $r ) {
			$items .= '<li><code>' . esc_html( (string) $r ) . '</code></li>';
		}
		$more = count( $removed ) - self::REMOVED_MAX;
		if ( $more > 0 ) {
			/* translators: %d: how many more removed parts are not listed */
			$items .= '<li>' . esc_html( sprintf( __( '… și încă %d.', 'biscuitwp' ), $more ) ) . '</li>';
		}
		return '<div class="notice notice-warning cmp-ap-removed"><p><strong>' . esc_html__( 'Am eliminat din CSS-ul propriu:', 'biscuitwp' ) . '</strong> '
			. ( $saved
				? esc_html__( 'regulile care ar putea ascunde, micșora sau deosebi butoanele „Accept” și „Refuz”, adresele spre alte site-uri și @import. Restul CSS-ului e salvat.', 'biscuitwp' )
				: esc_html__( 'regulile care ar putea ascunde, micșora sau deosebi butoanele „Accept” și „Refuz”, adresele spre alte site-uri și @import. Restul CSS-ului nu e salvat încă: apasă „Salvează”.', 'biscuitwp' ) )
			. '</p><ul>' . $items . '</ul></div>';
	}

	/** URL of the translation plugin's strings screen (WPML or Polylang), filtered to this plugin. */
	public static function translation_url() {
		if ( defined( 'ICL_SITEPRESS_VERSION' ) || function_exists( 'icl_t' ) ) {
			return admin_url( 'admin.php?page=wpml-string-translation/menu/string-translation.php&context=consent-manager-pro' );
		}
		return admin_url( 'admin.php?page=mlang_strings&group=consent-manager-pro' );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = CMP_Consent::get_settings();
		$s = is_array( $s ) ? $s : array();
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Aspect salvat.', 'biscuitwp' ) . '</p></div>';
		}
		$key     = self::REMOVED_KEY . get_current_user_id();
		$removed = get_transient( $key );
		if ( false !== $removed ) {
			delete_transient( $key );
			echo self::removed_notice( is_array( $removed ) ? $removed : array() ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in removed_notice().
		}
		$ml   = CMP_Banner::multilingual();
		$form = $s;
		// Over post_max_size PHP drops the whole request body: nothing to check or to read, only to explain.
		if ( isset( $_SERVER['REQUEST_METHOD'], $_SERVER['CONTENT_LENGTH'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && ! $_POST && (int) $_SERVER['CONTENT_LENGTH'] > 0 ) { // phpcs:ignore WordPress.Security.NonceVerification -- shows a message, changes nothing.
			echo self::import_notice( array( 'status' => 'error', 'error' => 'size' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in import_notice().
		}
		// An import (POST to this screen): the form opens with the imported values, unsaved; nothing is written.
		if ( isset( $_SERVER['REQUEST_METHOD'], $_POST['cmp_import_appearance'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) { // phpcs:ignore WordPress.Security.NonceVerification -- checked in handle_import() (check_admin_referer below).
			$nonce_ok = (bool) check_admin_referer( 'cmp_import_appearance' );
			$files    = isset( $_FILES ) && is_array( $_FILES ) ? $_FILES : array(); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- the file is validated in CMP_Appearance_IO::read_upload().
			$import   = self::handle_import( current_user_can( 'manage_options' ), $nonce_ok, $files, $s, $ml, self::$upload_check );
			if ( 'denied' === $import['status'] ) {
				wp_die( esc_html__( 'Acțiune neautorizată.', 'biscuitwp' ) );
			}
			echo self::import_notice( $import ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in import_notice().
			if ( 'ok' === $import['status'] ) {
				$form = $import['settings'];
			}
		}
		self::render_form( $form, $ml );
	}

	/**
	 * An import request: the capability and the nonce are checked first and the
	 * uploaded file is not touched without them. Nothing is written: the result
	 * carries the settings the form is rendered with (unsaved).
	 *
	 * @param bool          $can          current_user_can( 'manage_options' ).
	 * @param bool          $nonce_ok     The nonce check.
	 * @param array         $files        $_FILES.
	 * @param array         $s            Stored cmp_settings.
	 * @param bool          $multilingual WPML or Polylang active.
	 * @param callable|null $is_uploaded  is_uploaded_file.
	 * @return array { status: denied|error|ok, error: string, settings: ?array, merge: array }
	 */
	public static function handle_import( $can, $nonce_ok, array $files, array $s, $multilingual, $is_uploaded = null ) {
		$out = array( 'status' => 'denied', 'error' => '', 'settings' => null, 'merge' => array() );
		if ( ! $can || ! $nonce_ok ) {
			return $out;
		}
		$up = CMP_Appearance_IO::read_upload( isset( $files[ self::IMPORT_FIELD ] ) ? $files[ self::IMPORT_FIELD ] : null, $is_uploaded );
		if ( '' !== $up['error'] ) {
			return array_merge( $out, array( 'status' => 'error', 'error' => $up['error'] ) );
		}
		$parsed = CMP_Appearance_IO::parse( $up['json'] );
		if ( '' !== $parsed['error'] ) {
			return array_merge( $out, array( 'status' => 'error', 'error' => $parsed['error'] ) );
		}
		$merge = CMP_Appearance_IO::merge( $parsed['data'], $s, $multilingual );
		return array( 'status' => 'ok', 'error' => '', 'settings' => $merge['settings'], 'merge' => $merge );
	}

	/** Pure: why an import was refused (RO source text). */
	public static function import_error_text( $code ) {
		switch ( $code ) {
			case 'none':
				return __( 'Alege un fișier .json de importat.', 'biscuitwp' );
			case 'partial':
				return __( 'Fișierul nu s-a încărcat complet. Încearcă din nou.', 'biscuitwp' );
			case 'size':
				return __( 'Fișierul e prea mare: cel mult 256 KB.', 'biscuitwp' );
			case 'json':
				return __( 'Fișierul nu e un JSON valid.', 'biscuitwp' );
			case 'format':
				return __( 'Nu e un fișier de aspect BiscuitWP.', 'biscuitwp' );
			case 'version':
				return __( 'Fișierul vine dintr-o versiune mai nouă de BiscuitWP. Actualizează pluginul și încearcă din nou.', 'biscuitwp' );
		}
		return __( 'Fișierul nu a putut fi încărcat. Încearcă din nou.', 'biscuitwp' );
	}

	/** Pure: the notice(s) after an import request. Escaped HTML. */
	public static function import_notice( array $import ) {
		if ( 'error' === $import['status'] ) {
			return '<div class="notice notice-error cmp-ap-import-error"><p>' . esc_html( self::import_error_text( $import['error'] ) ) . '</p></div>';
		}
		if ( 'ok' !== $import['status'] ) {
			return '';
		}
		$m    = $import['merge'];
		$html = '<div class="notice notice-warning cmp-ap-import-notice"><p><strong>' . esc_html__( 'Aspectul din fișier e încărcat în formular, dar nu e salvat.', 'biscuitwp' ) . '</strong> '
			. esc_html__( 'Apasă „Vezi pe site” ca să-l vezi întâi, apoi „Salvează aspectul” ca să-l aplici. Dacă reîncarci pagina fără să retrimiți formularul, revii la aspectul salvat.', 'biscuitwp' ) . '</p>';
		$more = '';
		if ( ! empty( $m['ignored'] ) ) {
			/* translators: %d: how many keys of the file were not used */
			$more .= '<li class="cmp-ap-import-ignored">' . esc_html( sprintf( __( 'Chei ignorate (necunoscute sau care nu se importă, ca excluderile): %d.', 'biscuitwp' ), (int) $m['ignored'] ) ) . '</li>';
		}
		if ( ! empty( $m['texts_skipped'] ) ) {
			$more .= '<li class="cmp-ap-import-texts">' . esc_html__( 'Textele din fișier nu s-au importat: site-ul folosește WPML sau Polylang, iar textele se traduc acolo.', 'biscuitwp' ) . '</li>';
		}
		if ( ! empty( $m['light_skipped'] ) ) {
			/* translators: %d: how many values of the file were not used because the current mode does not show them */
			$more .= '<li class="cmp-ap-import-mode">' . esc_html( sprintf( __( 'Valori care nu se aplică modului curent (Light sau Pro): %d. Au rămas cele salvate sau, dacă fișierul aduce alt stil, cele ale stilului.', 'biscuitwp' ), (int) $m['light_skipped'] ) ) . '</li>';
		}
		if ( '' !== $more ) {
			$html .= '<ul>' . $more . '</ul>';
		}
		$html .= '</div>';
		return $html . self::removed_notice( isset( $m['removed_css'] ) && is_array( $m['removed_css'] ) ? $m['removed_css'] : array(), false ); // an import saves nothing
	}

	/** Pure: the "Export / Import" section (below the form, never inside it). Escaped HTML. */
	public static function io_section_html( $import_action, $export_action ) {
		ob_start();
		?>
		<div class="cmp-ap-io">
			<h2><?php esc_html_e( 'Export și import', 'biscuitwp' ); ?></h2>
			<form method="post" action="<?php echo esc_url( $export_action ); ?>" class="cmp-ap-export">
				<input type="hidden" name="action" value="cmp_export_appearance">
				<?php wp_nonce_field( 'cmp_export_appearance' ); ?>
				<p><button type="submit" class="button"><?php esc_html_e( 'Exportă aspectul salvat', 'biscuitwp' ); ?></button></p>
				<p class="description"><?php esc_html_e( 'Descarcă un fișier .json cu aspectul salvat, textele bannerului și CSS-ul propriu, ca să-l folosești pe alt site. Cu WPML sau Polylang, textele bannerului nu intră în fișier: ele se traduc acolo. Modificările nesalvate din formular nu intră. Excluderile, setările, serviciile și datele operatorului nu se exportă.', 'biscuitwp' ); ?></p>
			</form>
			<form method="post" action="<?php echo esc_url( $import_action ); ?>" enctype="multipart/form-data" class="cmp-ap-import">
				<input type="hidden" name="cmp_import_appearance" value="1">
				<?php wp_nonce_field( 'cmp_import_appearance' ); ?>
				<p><label for="cmp-ap-import-file"><?php esc_html_e( 'Importă dintr-un fișier', 'biscuitwp' ); ?></label><br>
					<input type="file" id="cmp-ap-import-file" name="<?php echo esc_attr( self::IMPORT_FIELD ); ?>" accept=".json,application/json">
					<button type="submit" class="button"><?php esc_html_e( 'Încarcă în formular', 'biscuitwp' ); ?></button></p>
				<p class="description"><?php esc_html_e( 'Un fișier exportat din BiscuitWP, cel mult 256 KB. Valorile apar în formularul de mai sus, nesalvate: nu se schimbă nimic pe site până nu apeși „Salvează aspectul”. Fișierul nu se păstrează pe server.', 'biscuitwp' ); ?></p>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/** admin-post: download the saved appearance as a JSON file. */
	public static function export() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cmp_export_appearance' ) ) {
			wp_die( esc_html__( 'Acțiune neautorizată.', 'biscuitwp' ) );
		}
		$s    = CMP_Consent::get_settings();
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$r    = CMP_Appearance_IO::response( is_array( $s ) ? $s : array(), CMP_VERSION, is_string( $host ) ? $host : '', gmdate( 'Y-m-d' ) );
		nocache_headers();
		foreach ( $r['headers'] as $h ) {
			call_user_func( self::$header_fn, $h );
		}
		echo $r['body']; // phpcs:ignore WordPress.Security.EscapeOutput -- a JSON download, not HTML.
		exit;
	}

	/**
	 * The screen (header and form) for the stored settings $s.
	 *
	 * @param array $s            cmp_settings.
	 * @param bool  $multilingual WPML or Polylang active: the single texts (as before) instead of the per-language tabs.
	 * @param array $theme_palette The theme palette for "Preia din temă"; null = read it (CMP_Banner::theme_palette()).
	 */
	public static function render_form( array $s, $multilingual, $theme_palette = null ) {
		$a     = self::form_values( $s );
		$mode  = isset( $s['mode'] ) && 'light' === $s['mode'] ? 'light' : 'pro';
		$light = 'light' === $mode;
		$off   = function ( $k ) use ( $light ) {
			return $light && in_array( $k, self::LIGHT_OFF, true ) ? ' disabled' : '';
		};
		$n     = function ( $k ) {
			return 'cmp[appearance][' . $k . ']';
		};
		$light_note = $light ? '<p class="description cmp-ap-na">' . esc_html__( 'Nu se aplică în modul Light.', 'biscuitwp' ) . '</p>' : '';

		$accent_check = '' !== $a['accent'] ? CMP_Appearance::accent_suggestion( $a['accent'], CMP_Appearance::PALETTE_BG[ $a['palette'] ], '' ) : null;
		$dim_default  = CMP_Appearance::PALETTE_DIM[ $a['palette'] ];
		// "Preia din temă" (applies with the Light theme and a light palette): what the theme gave.
		$theme_check = null;
		if ( $a['theme_colors'] && 'light' === $a['theme'] && 'dark' !== $a['palette'] ) {
			$theme_check = CMP_Appearance::theme_colors( is_array( $theme_palette ) ? $theme_palette : CMP_Banner::theme_palette( $a ), $a['palette'] );
		}
		?>
		<div class="wrap cmp-admin cmp-ap" data-cmp-ap data-cmp-ap-mode="<?php echo esc_attr( $mode ); ?>">
			<h1><?php esc_html_e( 'BiscuitWP — Aspect', 'biscuitwp' ); ?> <?php echo CMP_Guide::link( 'appearance' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in link() ?></h1>
			<p><?php esc_html_e( 'Cum arată bannerul pe site. Ce alegi aici nu poate face bannerul neconform: „Accept” și „Refuz” rămân la fel de vizibile.', 'biscuitwp' ); ?></p>
			<?php if ( $light ) : ?>
				<div class="notice notice-info inline cmp-ap-light-note"><p><?php esc_html_e( 'Ești în modul Light. Câmpurile care nu se aplică cardului Light sunt dezactivate: formatul și poziția, fundalul, lățimea, butoanele, redeschiderea și textele butoanelor din Pro. Valorile lor salvate rămân.', 'biscuitwp' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $accent_check && ! $accent_check['ok'] ) : ?>
				<div class="notice notice-warning inline cmp-ap-accent-warning"><p><?php echo esc_html( sprintf( /* translators: 1: contrast ratio, 2: proposed colour (#rrggbb), 3: its contrast ratio */ __( 'Contrast %1$s:1, sub 4,5:1. Nuanța propusă: %2$s (%3$s:1).', 'biscuitwp' ), $accent_check['ratio'], $accent_check['suggest'], $accent_check['suggest_ratio'] ) ); ?> <?php esc_html_e( 'Accentul ales e greu de citit. Bannerul îl folosește oricum, dar îți recomandăm nuanța propusă.', 'biscuitwp' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $theme_check && ! $theme_check['found'] ) : ?>
				<div class="notice notice-info inline cmp-ap-theme-none"><p><?php esc_html_e( 'Tema nu declară culori cu nume cunoscute (background sau base, foreground sau contrast, primary sau accent). Bannerul folosește paleta aleasă.', 'biscuitwp' ); ?></p></div>
			<?php elseif ( $theme_check && '' !== $theme_check['primary'] && ! $theme_check['primary_ok'] ) : ?>
				<div class="notice notice-warning inline cmp-ap-theme-warning"><p><?php echo esc_html( sprintf( /* translators: 1: the theme's colour (#rrggbb), 2: contrast ratio, 3: proposed colour (#rrggbb), 4: its contrast ratio */ __( 'Culoarea principală a temei (%1$s) are contrast %2$s:1, sub 4,5:1, așa că bannerul folosește culoarea paletei. Pentru o culoare apropiată de a temei, pune ca accent nuanța propusă: %3$s (%4$s:1).', 'biscuitwp' ), $theme_check['primary'], $theme_check['ratio'], $theme_check['suggest'], $theme_check['suggest_ratio'] ) ); ?></p></div>
			<?php endif; ?>
			<div class="cmp-ap-layout">
			<?php echo self::live_preview_html( wp_create_nonce( CMP_Preview::RENDER ), admin_url( 'admin-ajax.php' ), $multilingual ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in live_preview_html(). ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cmp-ap-form">
				<input type="hidden" name="action" value="cmp_save_appearance">
				<?php wp_nonce_field( 'cmp_save_appearance' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Stil', 'biscuitwp' ); ?></th>
						<td>
							<fieldset data-cmp-ap-style data-cmp-ap-style-current="<?php echo esc_attr( $a['style'] ); ?>">
								<?php
								foreach ( array(
									'minimal'  => __( 'Minimal', 'biscuitwp' ),
									'brand'    => __( 'Brand', 'biscuitwp' ),
									'friendly' => __( 'Prietenos', 'biscuitwp' ),
								) as $k => $lbl ) :
									?>
									<label class="cmp-ap-choice"><input type="radio" name="<?php echo esc_attr( $n( 'style' ) ); ?>" value="<?php echo esc_attr( $k ); ?>"<?php checked( $a['style'], $k ); ?>> <?php echo esc_html( $lbl ); ?></label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Stilul dă forma: colțurile, umbra, lățimea, butoanele și animația. Brand e aspectul de până acum. Schimbarea stilului reîncarcă aceste valori; le poți ajusta apoi mai jos.', 'biscuitwp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Culori', 'biscuitwp' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Paletă', 'biscuitwp' ); ?>
								<select name="<?php echo esc_attr( $n( 'palette' ) ); ?>" data-cmp-ap-palette>
									<?php foreach ( array( 'neutral' => 'Neutral', 'warm' => 'Warm', 'dark' => 'Dark', 'egov' => 'eGov' ) as $k => $lbl ) : ?>
										<option value="<?php echo esc_attr( $k ); ?>"<?php selected( $a['palette'], $k ); ?>><?php echo esc_html( $lbl ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
							<input type="hidden" name="<?php echo esc_attr( $n( 'theme_colors' ) ); ?>" value="0">
							<label class="cmp-ap-inline"><input type="checkbox" name="<?php echo esc_attr( $n( 'theme_colors' ) ); ?>" value="1"<?php checked( $a['theme_colors'] ); ?>> <?php esc_html_e( 'Preia din temă', 'biscuitwp' ); ?></label>
							<p class="description"><?php esc_html_e( '„Preia din temă” citește paleta temei (theme.json): fundalul, textul și culoarea principală. Folosește doar culorile care trec verificarea de contrast (4,5:1); restul rămân din paleta aleasă. Se aplică doar cu tema „Luminos”.', 'biscuitwp' ); ?></p>
							<p class="cmp-ap-accent">
								<label for="cmp-ap-accent"><?php esc_html_e( 'Accent', 'biscuitwp' ); ?></label>
								<input type="text" name="<?php echo esc_attr( $n( 'accent' ) ); ?>" id="cmp-ap-accent" class="cmp-ap-accent__input" value="<?php echo esc_attr( $a['accent'] ); ?>" placeholder="#rrggbb" maxlength="7" pattern="#?[0-9a-fA-F]{3}([0-9a-fA-F]{3})?" aria-describedby="cmp-ap-accent-status" data-cmp-accent>
								<span class="cmp-ap-swatch" data-cmp-accent-swatch aria-hidden="true"<?php echo '' !== $a['accent'] ? ' style="background:' . esc_attr( $a['accent'] ) . '"' : ''; ?>></span>
								<span id="cmp-ap-accent-status" class="cmp-ap-accent__status" data-cmp-accent-status aria-live="polite"></span>
								<button type="button" class="button button-small" data-cmp-accent-use hidden><?php esc_html_e( 'Folosește nuanța propusă', 'biscuitwp' ); ?></button>
							</p>
							<p class="description"><?php esc_html_e( 'Opțional: culoarea butoanelor și a linkurilor. Verificăm contrastul cu textul butonului și cu fundalul (minimum 4,5:1). Gol = culoarea paletei.', 'biscuitwp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Font', 'biscuitwp' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( $n( 'font' ) ); ?>">
								<option value="system"<?php selected( $a['font'], 'system' ); ?>><?php esc_html_e( 'Fontul sistemului (ca până acum)', 'biscuitwp' ); ?></option>
								<option value="inherit"<?php selected( $a['font'], 'inherit' ); ?>><?php esc_html_e( 'Fontul temei', 'biscuitwp' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Temă', 'biscuitwp' ); ?></th>
						<td>
							<fieldset>
								<?php
								foreach ( array(
									'light' => __( 'Luminos', 'biscuitwp' ),
									'dark'  => __( 'Întunecat', 'biscuitwp' ),
									'auto'  => __( 'Automat', 'biscuitwp' ),
									'site'  => __( 'Ca site-ul', 'biscuitwp' ),
								) as $k => $lbl ) :
									?>
									<label class="cmp-ap-choice"><input type="radio" name="<?php echo esc_attr( $n( 'theme' ) ); ?>" value="<?php echo esc_attr( $k ); ?>" data-cmp-ap-theme<?php checked( $a['theme'], $k ); ?>> <?php echo esc_html( $lbl ); ?></label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Automat urmează setarea de culoare a dispozitivului vizitatorului. „Ca site-ul” face bannerul întunecat când pagina are clasa sau atributul de mai jos pe html sau body; altfel rămâne luminos.', 'biscuitwp' ); ?></p>
							<p data-cmp-ap-selector<?php echo 'site' === $a['theme'] ? '' : ' hidden'; ?>>
								<label for="cmp-ap-dark-selector"><?php esc_html_e( 'Selector pentru tema întunecată a site-ului', 'biscuitwp' ); ?></label><br>
								<input type="text" class="regular-text code" id="cmp-ap-dark-selector" name="<?php echo esc_attr( $n( 'dark_selector' ) ); ?>" value="<?php echo esc_attr( $a['dark_selector'] ); ?>" maxlength="200">
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Format și poziție', 'biscuitwp' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Format', 'biscuitwp' ); ?>
								<select name="<?php echo esc_attr( $n( 'format' ) ); ?>" data-cmp-ap-format<?php echo esc_attr( $off( 'format' ) ); ?>>
									<option value="bar"<?php selected( $a['format'], 'bar' ); ?>><?php esc_html_e( 'Bară pe toată lățimea', 'biscuitwp' ); ?></option>
									<option value="card"<?php selected( $a['format'], 'card' ); ?>><?php esc_html_e( 'Card într-un colț', 'biscuitwp' ); ?></option>
									<option value="modal"<?php selected( $a['format'], 'modal' ); ?>><?php esc_html_e( 'Fereastră', 'biscuitwp' ); ?></option>
								</select>
							</label>
							<?php
							$positions = array(
								'bar'   => array(
									'bottom' => __( 'Jos', 'biscuitwp' ),
									'top'    => __( 'Sus', 'biscuitwp' ),
								),
								'card'  => array(
									'bottom-right' => __( 'Dreapta jos', 'biscuitwp' ),
									'bottom-left'  => __( 'Stânga jos', 'biscuitwp' ),
									'top-right'    => __( 'Dreapta sus', 'biscuitwp' ),
									'top-left'     => __( 'Stânga sus', 'biscuitwp' ),
								),
								'modal' => array(
									'center' => __( 'Mijloc centru', 'biscuitwp' ),
									'top'    => __( 'Sus centru', 'biscuitwp' ),
									'bottom' => __( 'Jos centru', 'biscuitwp' ),
								),
							);
							foreach ( $positions as $fmt => $opts ) :
								$mine = $fmt === $a['format'];
								?>
								<label class="cmp-ap-inline" data-cmp-ap-pos="<?php echo esc_attr( $fmt ); ?>"<?php echo $mine ? '' : ' hidden'; ?>><?php esc_html_e( 'Poziție', 'biscuitwp' ); ?>
									<select name="<?php echo esc_attr( $n( 'position' ) ); ?>"<?php echo esc_attr( $mine ? $off( 'position' ) : ' disabled' ); ?>>
										<?php foreach ( $opts as $k => $lbl ) : ?>
											<option value="<?php echo esc_attr( $k ); ?>"<?php selected( $mine ? $a['position'] : '', $k ); ?>><?php echo esc_html( $lbl ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Pe telefon, bannerul apare ca bandă jos, indiferent de format.', 'biscuitwp' ); ?></p>
							<?php echo wp_kses_post( $light_note ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Fundal', 'biscuitwp' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( $n( 'backdrop' ) ); ?>" data-cmp-ap-backdrop<?php echo esc_attr( $off( 'backdrop' ) ); ?>>
								<option value="blur"<?php selected( $a['backdrop'], 'blur' ); ?>><?php esc_html_e( 'Întunecare și blur', 'biscuitwp' ); ?></option>
								<option value="dim"<?php selected( $a['backdrop'], 'dim' ); ?>><?php esc_html_e( 'Întunecare', 'biscuitwp' ); ?></option>
								<option value="none"<?php selected( $a['backdrop'], 'none' ); ?>><?php esc_html_e( 'Fără fundal (pagina rămâne folosibilă)', 'biscuitwp' ); ?></option>
							</select>
							<label class="cmp-ap-inline" data-cmp-ap-show-backdrop="blur dim"><?php esc_html_e( 'Întunecare', 'biscuitwp' ); ?>
								<input type="number" name="<?php echo esc_attr( $n( 'dim' ) ); ?>" min="0" max="80" step="1" class="small-text" value="<?php echo esc_attr( null === $a['dim'] ? '' : (string) $a['dim'] ); ?>" placeholder="<?php echo esc_attr( (string) $dim_default ); ?>"<?php echo esc_attr( $off( 'dim' ) ); ?>> %
							</label>
							<label class="cmp-ap-inline" data-cmp-ap-show-backdrop="blur"><?php esc_html_e( 'Blur', 'biscuitwp' ); ?>
								<input type="number" name="<?php echo esc_attr( $n( 'blur' ) ); ?>" min="0" max="10" step="1" class="small-text" value="<?php echo esc_attr( (string) $a['blur'] ); ?>"<?php echo esc_attr( $off( 'blur' ) ); ?>> px
							</label>
							<p class="description"><?php esc_html_e( 'Întunecarea: 0–80%; gol = valoarea paletei. Blurul: 0–10 px; pe telefoane rămâne doar întunecarea.', 'biscuitwp' ); ?></p>
							<?php echo wp_kses_post( $light_note ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Detalii', 'biscuitwp' ); ?></th>
						<td class="cmp-ap-details">
							<label><?php esc_html_e( 'Colțuri', 'biscuitwp' ); ?>
								<input type="range" name="<?php echo esc_attr( $n( 'radius' ) ); ?>" min="0" max="24" step="1" value="<?php echo esc_attr( (string) $a['radius'] ); ?>" data-cmp-ap-key="radius" data-cmp-ap-out="cmp-ap-radius-out">
								<output id="cmp-ap-radius-out"><?php echo esc_html( (string) $a['radius'] ); ?></output> px
							</label>
							<label><?php esc_html_e( 'Umbră', 'biscuitwp' ); ?>
								<select name="<?php echo esc_attr( $n( 'shadow' ) ); ?>" data-cmp-ap-key="shadow">
									<option value="none"<?php selected( $a['shadow'], 'none' ); ?>><?php esc_html_e( 'Fără', 'biscuitwp' ); ?></option>
									<option value="small"<?php selected( $a['shadow'], 'small' ); ?>><?php esc_html_e( 'Mică', 'biscuitwp' ); ?></option>
									<option value="medium"<?php selected( $a['shadow'], 'medium' ); ?>><?php esc_html_e( 'Medie', 'biscuitwp' ); ?></option>
									<option value="large"<?php selected( $a['shadow'], 'large' ); ?>><?php esc_html_e( 'Mare', 'biscuitwp' ); ?></option>
								</select>
							</label>
							<label><?php esc_html_e( 'Lățime max', 'biscuitwp' ); ?>
								<input type="number" name="<?php echo esc_attr( $n( 'maxw' ) ); ?>" min="360" max="960" step="10" class="small-text" value="<?php echo esc_attr( (string) $a['maxw'] ); ?>" data-cmp-ap-key="maxw"<?php echo esc_attr( $off( 'maxw' ) ); ?>> px
							</label>
							<label><?php esc_html_e( 'Margine', 'biscuitwp' ); ?>
								<input type="number" name="<?php echo esc_attr( $n( 'gap' ) ); ?>" min="0" max="40" step="1" class="small-text" value="<?php echo esc_attr( (string) $a['gap'] ); ?>"> px
							</label>
							<label><?php esc_html_e( 'Densitate', 'biscuitwp' ); ?>
								<select name="<?php echo esc_attr( $n( 'density' ) ); ?>">
									<option value="compact"<?php selected( $a['density'], 'compact' ); ?>><?php esc_html_e( 'Compact', 'biscuitwp' ); ?></option>
									<option value="normal"<?php selected( $a['density'], 'normal' ); ?>><?php esc_html_e( 'Normal', 'biscuitwp' ); ?></option>
									<option value="comfortable"<?php selected( $a['density'], 'comfortable' ); ?>><?php esc_html_e( 'Extins', 'biscuitwp' ); ?></option>
								</select>
							</label>
							<p class="description"><?php esc_html_e( 'Colțuri 0–24 px, lățimea maximă 360–960 px (doar în Pro), distanța față de marginea ecranului 0–40 px.', 'biscuitwp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Butoane', 'biscuitwp' ); ?></th>
						<td>
							<fieldset>
								<?php
								foreach ( array(
									'solid'   => __( 'Plin', 'biscuitwp' ),
									'outline' => __( 'Contur', 'biscuitwp' ),
									'text'    => __( 'Text', 'biscuitwp' ),
								) as $k => $lbl ) :
									?>
									<label class="cmp-ap-choice"><input type="radio" name="<?php echo esc_attr( $n( 'buttons' ) ); ?>" value="<?php echo esc_attr( $k ); ?>" data-cmp-ap-key="buttons"<?php checked( $a['buttons'], $k ); ?><?php echo esc_attr( $off( 'buttons' ) ); ?>> <?php echo esc_html( $lbl ); ?></label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php esc_html_e( '„Accept” și „Refuz” arată mereu la fel: același stil, aceeași mărime. Nu există setare separată pe buton.', 'biscuitwp' ); ?></p>
							<?php echo wp_kses_post( $light_note ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Imagine', 'biscuitwp' ); ?></th>
						<td data-cmp-ap-image="<?php echo esc_attr( $a['image'] ); ?>">
							<fieldset<?php echo esc_attr( $off( 'image' ) ); ?>>
								<?php
								foreach ( array(
									'none'   => __( 'Fără', 'biscuitwp' ),
									'shield' => __( 'Biscuit cu scut', 'biscuitwp' ),
									'wp'     => __( 'Biscuit WP', 'biscuitwp' ),
									'own'    => __( 'Imaginea mea', 'biscuitwp' ),
								) as $k => $lbl ) :
									?>
									<label class="cmp-ap-choice"><input type="radio" name="<?php echo esc_attr( $n( 'image' ) ); ?>" value="<?php echo esc_attr( $k ); ?>" data-cmp-ap-key="image"<?php checked( $a['image'], $k ); ?>> <?php echo esc_html( $lbl ); ?></label>
								<?php endforeach; ?>
								<p data-cmp-ap-image-own<?php echo 'own' === $a['image'] ? '' : ' hidden'; ?>>
									<input type="hidden" name="<?php echo esc_attr( $n( 'image_id' ) ); ?>" value="<?php echo esc_attr( (string) $a['image_id'] ); ?>" data-cmp-ap-image-id>
									<button type="button" class="button" data-cmp-ap-image-pick><?php esc_html_e( 'Alege din biblioteca media', 'biscuitwp' ); ?></button>
									<img src="<?php echo esc_url( self::image_thumb( (int) $a['image_id'] ) ); ?>" alt="" width="48" height="48" class="cmp-ap-image__thumb" data-cmp-ap-image-thumb<?php echo '' === self::image_thumb( (int) $a['image_id'] ) ? ' hidden' : ''; ?>>
									<span class="description"><?php esc_html_e( 'PNG sau WebP cu fundal transparent. Doar imagini de pe acest site.', 'biscuitwp' ); ?></span>
								</p>
								<p data-cmp-ap-image-more<?php echo 'none' === $a['image'] ? ' hidden' : ''; ?>>
									<label class="cmp-ap-inline"><?php esc_html_e( 'Poziție', 'biscuitwp' ); ?>
										<select name="<?php echo esc_attr( $n( 'image_side' ) ); ?>">
											<option value="right"<?php selected( $a['image_side'], 'right' ); ?>><?php esc_html_e( 'Dreapta sus', 'biscuitwp' ); ?></option>
											<option value="left"<?php selected( $a['image_side'], 'left' ); ?>><?php esc_html_e( 'Stânga sus', 'biscuitwp' ); ?></option>
										</select>
									</label>
									<label class="cmp-ap-inline"><?php esc_html_e( 'Mărime', 'biscuitwp' ); ?> <input type="number" name="<?php echo esc_attr( $n( 'image_size' ) ); ?>" min="48" max="160" step="4" class="small-text" value="<?php echo esc_attr( (string) $a['image_size'] ); ?>"> px</label>
									<input type="hidden" name="<?php echo esc_attr( $n( 'image_out' ) ); ?>" value="0">
									<label class="cmp-ap-inline"><input type="checkbox" name="<?php echo esc_attr( $n( 'image_out' ) ); ?>" value="1"<?php checked( $a['image_out'] ); ?>> <?php esc_html_e( 'Iese din banner', 'biscuitwp' ); ?></label>
									<label class="cmp-ap-inline"><?php esc_html_e( 'Pe telefon', 'biscuitwp' ); ?>
										<select name="<?php echo esc_attr( $n( 'image_phone' ) ); ?>">
											<option value="small"<?php selected( $a['image_phone'], 'small' ); ?>><?php esc_html_e( 'Mai mică', 'biscuitwp' ); ?></option>
											<option value="none"<?php selected( $a['image_phone'], 'none' ); ?>><?php esc_html_e( 'Ascunsă', 'biscuitwp' ); ?></option>
										</select>
									</label>
									<br>
									<label class="cmp-ap-inline"><?php esc_html_e( 'Text alternativ', 'biscuitwp' ); ?> <input type="text" name="<?php echo esc_attr( $n( 'image_alt' ) ); ?>" class="regular-text" maxlength="300" value="<?php echo esc_attr( $a['image_alt'] ); ?>"></label>
								</p>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Stă într-un colț de sus al bannerului și nu acoperă textul sau butoanele. Text alternativ gol = imagine decorativă. Nu apare în preferințe și se descarcă doar când apare bannerul.', 'biscuitwp' ); ?></p>
							<?php echo wp_kses_post( $light_note ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Logo în setări', 'biscuitwp' ); ?></th>
						<td data-cmp-ap-logo="<?php echo esc_attr( $a['logo'] ); ?>">
							<fieldset<?php echo esc_attr( $off( 'logo' ) ); ?>>
								<?php
								foreach ( array(
									'none'  => __( 'Fără', 'biscuitwp' ),
									'theme' => __( 'Logo-ul temei', 'biscuitwp' ),
									'own'   => __( 'Altă imagine', 'biscuitwp' ),
								) as $k => $lbl ) :
									?>
									<label class="cmp-ap-choice"><input type="radio" name="<?php echo esc_attr( $n( 'logo' ) ); ?>" value="<?php echo esc_attr( $k ); ?>" data-cmp-ap-key="logo"<?php checked( $a['logo'], $k ); ?>> <?php echo esc_html( $lbl ); ?></label>
								<?php endforeach; ?>
								<p data-cmp-ap-logo-own<?php echo 'own' === $a['logo'] ? '' : ' hidden'; ?>>
									<input type="hidden" name="<?php echo esc_attr( $n( 'logo_id' ) ); ?>" value="<?php echo esc_attr( (string) $a['logo_id'] ); ?>" data-cmp-ap-logo-id>
									<button type="button" class="button" data-cmp-ap-logo-pick><?php esc_html_e( 'Alege din biblioteca media', 'biscuitwp' ); ?></button>
									<img src="<?php echo esc_url( self::image_thumb( (int) $a['logo_id'] ) ); ?>" alt="" width="48" height="48" class="cmp-ap-image__thumb" data-cmp-ap-logo-thumb<?php echo '' === self::image_thumb( (int) $a['logo_id'] ) ? ' hidden' : ''; ?>>
								</p>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Apare deasupra titlului din fereastra de setări, cu înălțimea de 32 px. „Logo-ul temei” e cel din Personalizare → Identitatea site-ului. Doar imagini de pe acest site; se descarcă doar când vizitatorul deschide setările.', 'biscuitwp' ); ?></p>
							<?php echo wp_kses_post( $light_note ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Texte', 'biscuitwp' ); ?></th>
						<td>
							<?php self::render_texts( $s, $a, $mode, $multilingual ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Animație', 'biscuitwp' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( $n( 'anim' ) ); ?>" data-cmp-ap-key="anim">
								<option value="none"<?php selected( $a['anim'], 'none' ); ?>><?php esc_html_e( 'Fără', 'biscuitwp' ); ?></option>
								<option value="fade"<?php selected( $a['anim'], 'fade' ); ?>><?php esc_html_e( 'Apariție', 'biscuitwp' ); ?></option>
								<option value="slide"<?php selected( $a['anim'], 'slide' ); ?>><?php esc_html_e( 'Alunecare', 'biscuitwp' ); ?></option>
							</select>
							<label class="cmp-ap-inline"><?php esc_html_e( 'Întârziere', 'biscuitwp' ); ?>
								<input type="number" name="<?php echo esc_attr( $n( 'delay' ) ); ?>" min="0" max="2" step="0.1" class="small-text" value="<?php echo esc_attr( (string) $a['delay'] ); ?>"> s
							</label>
							<p class="description"><?php esc_html_e( 'Vizitatorii care cer mișcare redusă nu văd animația. Întârzierea (0–2 s) amână doar afișarea: trackerele rămân blocate de la început.', 'biscuitwp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Redeschidere', 'biscuitwp' ); ?></th>
						<td>
							<fieldset>
								<?php
								foreach ( array(
									'icon' => __( 'Iconiță', 'biscuitwp' ),
									'text' => __( 'Text', 'biscuitwp' ),
									'both' => __( 'Ambele', 'biscuitwp' ),
									'none' => __( 'Deloc', 'biscuitwp' ),
								) as $k => $lbl ) :
									?>
									<label class="cmp-ap-choice"><input type="radio" name="<?php echo esc_attr( $n( 'reopen' ) ); ?>" value="<?php echo esc_attr( $k ); ?>"<?php checked( $a['reopen'], $k ); ?><?php echo esc_attr( $off( 'reopen' ) ); ?>> <?php echo esc_html( $lbl ); ?></label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Un buton plutitor (jos-stânga) care redeschide preferințele, după ce vizitatorul a ales. Recomandat: „Deloc”, cu un link „Setări cookie” în footer: shortcode-ul [cmp_cookie_settings_link] sau un link de meniu cu URL-ul #cmp-settings.', 'biscuitwp' ); ?></p>
							<?php echo wp_kses_post( $light_note ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cmp-ap-exclude"><?php esc_html_e( 'Excluderi', 'biscuitwp' ); ?></label></th>
						<td>
							<textarea id="cmp-ap-exclude" name="<?php echo esc_attr( $n( 'exclude' ) ); ?>" rows="4" class="large-text code" placeholder="/landing/*"><?php echo esc_textarea( implode( "\n", $a['exclude'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Paginile pe care bannerul nu se deschide singur: o adresă pe rând, de exemplu /contact/. Pune * la final pentru toate paginile care încep așa, de exemplu /landing/*. Pe un site cu limba în adresă, scrie câte un rând pentru fiecare limbă (/ru/landing/*).', 'biscuitwp' ); ?></p>
							<p class="description"><?php esc_html_e( 'Blocarea rămâne activă și acolo: fără acord, trackerele nu se încarcă. Vizitatorul își poate deschide setările din linkul din footer sau din butonul de redeschidere.', 'biscuitwp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cmp-ap-css"><?php esc_html_e( 'CSS propriu', 'biscuitwp' ); ?></label></th>
						<td>
							<textarea id="cmp-ap-css" name="<?php echo esc_attr( $n( 'custom_css' ) ); ?>" rows="8" class="large-text code" spellcheck="false"><?php echo esc_textarea( $a['custom_css'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Se adaugă după CSS-ul bannerului, în ambele moduri. La salvare eliminăm @import, adresele spre alte site-uri și regulile care ar putea ascunde, micșora sau deosebi butoanele „Accept” și „Refuz” (de exemplu regulile pe .cmp__btn--primary sau [data-cmp-action="reject"]). Îți arătăm ce am eliminat.', 'biscuitwp' ); ?></p>
							<details class="cmp-ap-vars">
								<summary><?php esc_html_e( 'Variabile disponibile', 'biscuitwp' ); ?></summary>
								<p><?php echo wp_kses_post( sprintf( /* translators: %s: example CSS rule */ __( 'Le setezi pe .cmp (sau .cmp-light pentru modul Light), de exemplu %s.', 'biscuitwp' ), '<code>.cmp{--cmp-radius:6px}</code>' ) ); ?></p>
								<ul>
									<?php foreach ( CMP_Guide::variables() as $var => $desc ) : ?>
										<li><code><?php echo esc_html( $var ); ?></code> — <?php echo esc_html( $desc ); ?></li>
									<?php endforeach; ?>
								</ul>
							</details>
						</td>
					</tr>
				</table>
				<p class="submit cmp-ap-actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Salvează aspectul', 'biscuitwp' ); ?></button>
					<?php /* "Vezi pe site" (CMP_Preview), through the cmp_appearance_actions hook. */ ?>
					<span class="cmp-ap-preview" data-cmp-preview-slot><?php do_action( 'cmp_appearance_actions', $s ); ?></span>
				</p>
			</form>
			</div>
			<?php echo self::io_section_html( admin_url( 'admin.php?page=' . self::SLUG ), admin_url( 'admin-post.php' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in io_section_html(). ?>
		</div>
		<?php
	}

	/** The "Texte" row: per-language tabs, or (WPML / Polylang) the single texts and a link to the translation plugin. */
	private static function render_texts( array $s, array $a, $mode, $multilingual ) {
		$labels = array(
			'title'       => __( 'Titlu banner', 'biscuitwp' ),
			'intro'       => __( 'Text introductiv', 'biscuitwp' ),
			'light_intro' => __( 'Text notice (Light)', 'biscuitwp' ),
			'accept'      => __( 'Buton „Accept"', 'biscuitwp' ),
			'reject'      => __( 'Buton „Doar necesare"', 'biscuitwp' ),
			'prefs'       => __( 'Buton „Gestionează"', 'biscuitwp' ),
			'save'        => __( 'Buton „Confirmă selecția"', 'biscuitwp' ),
			'prefs_title' => __( 'Titlu preferințe', 'biscuitwp' ),
		);
		$field = function ( $name, $key, $value, $placeholder, $id ) use ( $mode, $labels ) {
			$tm  = self::TEXT_MODES[ $key ];
			$dis = '' !== $tm && $tm !== $mode ? ' disabled' : '';
			echo '<p class="cmp-ap-text"><label for="' . esc_attr( $id ) . '">' . esc_html( $labels[ $key ] ) . '</label><br>';
			if ( 'intro' === $key || 'light_intro' === $key ) {
				echo '<textarea name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" class="large-text" rows="2" placeholder="' . esc_attr( $placeholder ) . '"' . esc_attr( $dis ) . '>' . esc_textarea( $value ) . '</textarea>';
			} else {
				echo '<input type="text" name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" class="regular-text" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '"' . esc_attr( $dis ) . '>';
			}
			if ( '' !== $dis ) {
				echo ' <span class="description">' . esc_html( 'light' === $tm ? __( 'Doar în modul Light.', 'biscuitwp' ) : __( 'Nu se aplică în modul Light.', 'biscuitwp' ) ) . '</span>';
			}
			echo '</p>';
		};

		if ( $multilingual ) {
			$old = isset( $s['texts'] ) && is_array( $s['texts'] ) ? $s['texts'] : array();
			echo '<div class="notice notice-info inline cmp-ap-ml-note"><p>' . esc_html__( 'Site-ul folosește WPML sau Polylang. Textele de mai jos sunt cele de bază; le traduci în pluginul de traduceri, ca până acum.', 'biscuitwp' )
				. ' <a href="' . esc_url( self::translation_url() ) . '">' . esc_html__( 'Deschide traducerile textelor', 'biscuitwp' ) . '</a></p></div>';
			foreach ( CMP_Appearance::TEXT_KEYS as $k ) {
				$value = isset( $old[ $k ] ) && is_scalar( $old[ $k ] ) ? (string) $old[ $k ] : '';
				$field( 'cmp[texts][' . $k . ']', $k, $value, __( '(implicit)', 'biscuitwp' ), 'cmp-ap-t-' . $k );
			}
			return;
		}

		$langs = array( 'ro' => 'RO', 'ru' => 'RU', 'en' => 'EN' );
		echo '<p class="description">' . esc_html__( 'Câmp gol = textul implicit al limbii (arătat în câmp, estompat).', 'biscuitwp' ) . '</p>';
		echo '<div class="cmp-ap-tabs" role="tablist" aria-label="' . esc_attr__( 'Limba textelor', 'biscuitwp' ) . '">';
		$first = true;
		foreach ( $langs as $l => $lbl ) {
			echo '<button type="button" class="cmp-ap-tab" role="tab" id="cmp-ap-tab-' . esc_attr( $l ) . '" aria-controls="cmp-ap-panel-' . esc_attr( $l ) . '" aria-selected="' . ( $first ? 'true' : 'false' ) . '" data-cmp-ap-tab="' . esc_attr( $l ) . '">' . esc_html( $lbl ) . '</button>';
			$first = false;
		}
		echo '</div>';
		foreach ( $langs as $l => $lbl ) {
			echo '<div class="cmp-ap-panel" role="tabpanel" id="cmp-ap-panel-' . esc_attr( $l ) . '" aria-labelledby="cmp-ap-tab-' . esc_attr( $l ) . '" data-cmp-ap-panel="' . esc_attr( $l ) . '">';
			foreach ( CMP_Appearance::TEXT_KEYS as $k ) {
				$placeholder = class_exists( 'CMP_Lang' ) ? CMP_Lang::text( $k, $l ) : '';
				$field( 'cmp[appearance][texts][' . $l . '][' . $k . ']', $k, $a['texts'][ $l ][ $k ], $placeholder, 'cmp-ap-t-' . $l . '-' . $k );
			}
			echo '</div>';
		}
	}

	/**
	 * Pure: the "Vezi pe site" button (B6, CMP_Preview), in the actions slot.
	 * Hidden until admin-appearance.js runs (it needs JS: the form is posted
	 * via AJAX, then the preview opens in a new tab).
	 */
	public static function preview_button_html( $nonce, $ajax_url ) {
		return '<button type="button" class="button" data-cmp-preview hidden'
			. ' data-cmp-preview-nonce="' . esc_attr( $nonce ) . '"'
			. ' data-cmp-preview-ajax="' . esc_url( $ajax_url ) . '"'
			. ' data-cmp-preview-error="' . esc_attr__( 'Previzualizarea nu a putut fi pregătită. Reîncarcă pagina și încearcă din nou.', 'biscuitwp' ) . '"'
			. ' data-cmp-preview-open="' . esc_attr__( 'Deschide previzualizarea', 'biscuitwp' ) . '">'
			. esc_html__( 'Vezi pe site', 'biscuitwp' ) . ' <span aria-hidden="true">↗</span>'
			. '<span class="screen-reader-text"> ' . esc_html__( '(se deschide într-o filă nouă, cu setările nesalvate)', 'biscuitwp' ) . '</span>'
			. '</button> <span class="cmp-ap-preview__status" role="status" data-cmp-preview-status></span>';
	}

	/**
	 * Pure: the live preview panel (#727 step 4). The banner of the unsaved form,
	 * rendered by the server (CMP_Preview::ajax_render()) into a frame without
	 * scripts; assets/js/admin-appearance.js shows the panel and drives it.
	 * Without WPML / Polylang a language select (RO / RU / EN).
	 */
	public static function live_preview_html( $nonce, $ajax_url, $multilingual ) {
		$sw = function ( $key, array $opts ) {
			$h = '<span class="cmp-live__group" role="group">';
			$i = 0;
			foreach ( $opts as $v => $lbl ) {
				$h .= '<button type="button" class="button cmp-live__sw" data-cmp-live-' . $key . '="' . esc_attr( $v ) . '" aria-pressed="' . ( 0 === $i++ ? 'true' : 'false' ) . '">' . esc_html( $lbl ) . '</button>';
			}
			return $h . '</span>';
		};
		$h  = '<details class="cmp-live" data-cmp-live open hidden'
			. ' data-cmp-live-nonce="' . esc_attr( $nonce ) . '"'
			. ' data-cmp-live-ajax="' . esc_url( $ajax_url ) . '"'
			. ' data-cmp-live-error="' . esc_attr__( 'Previzualizarea nu s-a putut actualiza. Ultima variantă rămâne afișată.', 'biscuitwp' ) . '"'
			. ' data-cmp-live-busy="' . esc_attr__( 'Se actualizează…', 'biscuitwp' ) . '">';
		$h .= '<summary>' . esc_html__( 'Previzualizare', 'biscuitwp' ) . '</summary>';
		$h .= '<div class="cmp-live__bar">';
		$h .= $sw( 'view', array( 'desktop' => __( 'Desktop', 'biscuitwp' ), 'phone' => __( 'Telefon', 'biscuitwp' ) ) );
		$h .= $sw( 'theme', array( 'light' => __( 'Deschisă', 'biscuitwp' ), 'dark' => __( 'Întunecată', 'biscuitwp' ) ) );
		$h .= $sw( 'pane', array( 'banner' => __( 'Banner', 'biscuitwp' ), 'prefs' => __( 'Setări', 'biscuitwp' ) ) );
		if ( ! $multilingual ) {
			$h .= '<label class="cmp-live__lang">' . esc_html__( 'Limba', 'biscuitwp' ) . ' <select data-cmp-live-lang><option value="">' . esc_html__( 'a site-ului', 'biscuitwp' ) . '</option><option value="ro">RO</option><option value="ru">RU</option><option value="en">EN</option></select></label>';
		}
		$h .= '</div>';
		$h .= '<div class="cmp-live__stage" data-cmp-live-stage><iframe class="cmp-live__frame" data-cmp-live-frame sandbox="allow-same-origin" title="' . esc_attr__( 'Previzualizarea bannerului', 'biscuitwp' ) . '"></iframe></div>';
		$h .= '<p class="cmp-live__status" role="status" data-cmp-live-status></p>';
		return $h . '</details>';
	}

	/** cmp_appearance_actions: the "Vezi pe site" button. */
	public static function render_preview_button() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo self::preview_button_html( wp_create_nonce( CMP_Preview::AJAX ), admin_url( 'admin-ajax.php' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in preview_button_html().
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cmp_save_appearance' ) ) {
			wp_die( esc_html__( 'Acțiune neautorizată.', 'biscuitwp' ) );
		}
		$raw = isset( $_POST['cmp'] ) && is_array( $_POST['cmp'] ) ? wp_unslash( $_POST['cmp'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in CMP_Sanitizer::aspect().
		$old = CMP_Consent::get_settings();
		$old = is_array( $old ) ? $old : array();

		// What the cleaner removes from the custom CSS is shown on the next screen.
		$removed = array();
		if ( isset( $raw['appearance']['custom_css'] ) && is_scalar( $raw['appearance']['custom_css'] ) ) {
			$clean   = CMP_Appearance::clean_custom_css( substr( (string) $raw['appearance']['custom_css'], 0, 20000 ) );
			$removed = $clean['removed'];
		}

		update_option( 'cmp_settings', CMP_Sanitizer::aspect( $raw, $old, CMP_Banner::multilingual() ) );
		if ( $removed ) {
			set_transient( self::REMOVED_KEY . get_current_user_id(), array_values( $removed ), 300 );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
