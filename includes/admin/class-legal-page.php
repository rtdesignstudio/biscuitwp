<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMP_Legal_Page {

	public static function labels() {
		return array(
			'privacy' => __( 'Politica de confidențialitate', 'consent-manager-pro' ),
			'terms'   => __( 'Termeni și condiții', 'consent-manager-pro' ),
			'cookie'  => __( 'Politica de cookie-uri', 'consent-manager-pro' ),
		);
	}

	public static function settings_key( $type ) {
		$map = array( 'privacy' => 'privacy_page_id', 'terms' => 'terms_page_id', 'cookie' => 'cookie_page_id' );
		return isset( $map[ $type ] ) ? $map[ $type ] : '';
	}

	public static function build_content( $type, array $meta ) {
		$vars = array(
			'site_name'      => get_bloginfo( 'name' ),
			'company'        => isset( $meta['company'] ) ? $meta['company'] : get_bloginfo( 'name' ),
			'contact_email'  => isset( $meta['contact_email'] ) ? $meta['contact_email'] : get_bloginfo( 'admin_email' ),
			'dpo'            => isset( $meta['dpo'] ) ? $meta['dpo'] : '',
			'jurisdiction'   => isset( $meta['jurisdiction'] ) ? $meta['jurisdiction'] : '',
			'effective_date' => date_i18n( get_option( 'date_format' ) ),
		);
		return CMP_Legal::fill_template( CMP_Legal::load_template( $type ), $vars );
	}

	public static function regenerate_cookie_page() {
		$settings = CMP_Consent::get_settings();
		$pid      = (int) ( isset( $settings['cookie_page_id'] ) ? $settings['cookie_page_id'] : 0 );
		if ( ! $pid ) {
			return;
		}
		$post = get_post( $pid );
		if ( ! $post || 'page' !== $post->post_type || 'trash' === $post->post_status ) {
			return;
		}
		$meta = isset( $settings['legal_meta'] ) && is_array( $settings['legal_meta'] ) ? $settings['legal_meta'] : array();
		wp_update_post( array( 'ID' => $pid, 'post_content' => self::build_content( 'cookie', $meta ) ) );
	}

	/** Stored operator data, with defaults for a first visit. */
	private static function meta() {
		$s    = CMP_Consent::get_settings();
		$meta = isset( $s['legal_meta'] ) && is_array( $s['legal_meta'] ) ? $s['legal_meta'] : array();
		return array_merge(
			array(
				'company'       => get_bloginfo( 'name' ),
				'contact_email' => get_bloginfo( 'admin_email' ),
				'dpo'           => '',
				'jurisdiction'  => 'Republica Moldova',
			),
			array_filter( $meta, 'strlen' )
		);
	}

	/**
	 * Each legal document: linked page id and its status ('published', 'draft',
	 * 'missing'). The privacy row falls back to WordPress' own privacy page as a
	 * suggestion when none is linked yet.
	 */
	public static function documents() {
		$s   = CMP_Consent::get_settings();
		$out = array();
		foreach ( self::labels() as $type => $label ) {
			$key       = self::settings_key( $type );
			$pid       = (int) ( isset( $s[ $key ] ) ? $s[ $key ] : 0 );
			$suggested = false;
			if ( ! $pid && 'privacy' === $type ) {
				$wp_pid = (int) get_option( 'wp_page_for_privacy_policy' );
				if ( $wp_pid && 'page' === get_post_type( $wp_pid ) && 'trash' !== get_post_status( $wp_pid ) ) {
					$pid       = $wp_pid;
					$suggested = true;
				}
			}
			$post   = $pid ? get_post( $pid ) : null;
			$status = ( $post && 'page' === $post->post_type ) ? CMP_Legal::page_status( $post->post_status ) : 'missing';
			$out[ $type ] = array(
				'label'     => $label,
				'id'        => 'missing' === $status ? 0 : $pid,
				'status'    => $suggested ? 'missing' : $status,
				'suggested' => $suggested,
			);
		}
		return $out;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$meta   = self::meta();
		$docs   = self::documents();
		$wp_pid = (int) get_option( 'wp_page_for_privacy_policy' );
		$badges = array(
			'published' => array( __( 'Publicată', 'consent-manager-pro' ), '#00a32a' ),
			'draft'     => array( __( 'Ciornă (nu e vizibilă vizitatorilor)', 'consent-manager-pro' ), '#dba617' ),
			'missing'   => array( __( 'Lipsește', 'consent-manager-pro' ), '#d63638' ),
		);
		?>
		<div class="wrap cmp-admin">
			<h1><?php esc_html_e( 'Consent Manager — Pagini legale', 'consent-manager-pro' ); ?></h1>
			<?php if ( isset( $_GET['generated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Pagina a fost generată. Dacă e nouă, e salvată ca ciornă: verific-o și public-o din editor.', 'consent-manager-pro' ); ?></p></div>
			<?php elseif ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Salvat.', 'consent-manager-pro' ); ?></p></div>
			<?php endif; ?>

			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Șabloanele sunt un punct de plecare informativ, NU consultanță juridică. Revizuiește textul cu un specialist înainte de publicare.', 'consent-manager-pro' ); ?></p></div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cmp_legal">
				<?php wp_nonce_field( 'cmp_legal' ); ?>

				<h2><?php esc_html_e( 'Datele operatorului', 'consent-manager-pro' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Apar în paginile generate. Se salvează, nu trebuie completate din nou.', 'consent-manager-pro' ); ?></p>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="cmp-company"><?php esc_html_e( 'Denumire operator', 'consent-manager-pro' ); ?></label></th>
						<td><input type="text" id="cmp-company" name="meta[company]" class="regular-text" value="<?php echo esc_attr( $meta['company'] ); ?>"></td></tr>
					<tr><th scope="row"><label for="cmp-email"><?php esc_html_e( 'Email contact', 'consent-manager-pro' ); ?></label></th>
						<td><input type="email" id="cmp-email" name="meta[contact_email]" class="regular-text" value="<?php echo esc_attr( $meta['contact_email'] ); ?>"></td></tr>
					<tr><th scope="row"><label for="cmp-dpo"><?php esc_html_e( 'Responsabil date (DPO)', 'consent-manager-pro' ); ?></label></th>
						<td><input type="text" id="cmp-dpo" name="meta[dpo]" class="regular-text" value="<?php echo esc_attr( $meta['dpo'] ); ?>"></td></tr>
					<tr><th scope="row"><label for="cmp-jur"><?php esc_html_e( 'Jurisdicție', 'consent-manager-pro' ); ?></label></th>
						<td><input type="text" id="cmp-jur" name="meta[jurisdiction]" class="regular-text" value="<?php echo esc_attr( $meta['jurisdiction'] ); ?>"></td></tr>
				</table>

				<h2><?php esc_html_e( 'Documente', 'consent-manager-pro' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Alege o pagină existentă (inclusiv ciorne) sau generează una din șablon. Linkul „Politica de confidențialitate” din banner apare doar când pagina e publicată.', 'consent-manager-pro' ); ?></p>
				<table class="widefat striped" style="max-width:1100px;margin-top:12px">
					<thead><tr>
						<th><?php esc_html_e( 'Document', 'consent-manager-pro' ); ?></th>
						<th><?php esc_html_e( 'Pagina', 'consent-manager-pro' ); ?></th>
						<th><?php esc_html_e( 'Stare', 'consent-manager-pro' ); ?></th>
						<th><?php esc_html_e( 'Șablon', 'consent-manager-pro' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $docs as $type => $doc ) :
						list( $badge, $color ) = $badges[ $doc['status'] ];
						?>
						<tr>
							<td><strong><?php echo esc_html( $doc['label'] ); ?></strong></td>
							<td>
								<?php
								wp_dropdown_pages( array(
									'name'              => 'page[' . $type . ']',
									'id'                => 'cmp-page-' . $type,
									'selected'          => $doc['id'],
									'show_option_none'  => __( '— niciuna —', 'consent-manager-pro' ),
									'option_none_value' => 0,
									'post_status'       => array( 'publish', 'draft', 'pending', 'private' ),
								) );
								?>
								<?php if ( $doc['suggested'] ) : ?>
									<p class="description"><?php esc_html_e( 'Propusă: pagina de confidențialitate a WordPress. Salvează ca s-o folosești.', 'consent-manager-pro' ); ?></p>
								<?php endif; ?>
								<?php if ( 'privacy' === $type ) : ?>
									<p><label><input type="checkbox" name="wp_privacy" value="1" <?php checked( ! $wp_pid || $wp_pid === (int) $doc['id'] ); ?>> <?php esc_html_e( 'Folosește și ca pagina de confidențialitate a WordPress', 'consent-manager-pro' ); ?></label></p>
								<?php endif; ?>
							</td>
							<td>
								<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?php echo esc_attr( $color ); ?>;margin-right:6px"></span><?php echo esc_html( $badge ); ?>
								<?php if ( $doc['id'] && ! $doc['suggested'] ) : ?>
									<br><a href="<?php echo esc_url( (string) get_edit_post_link( $doc['id'] ) ); ?>"><?php esc_html_e( 'Editează', 'consent-manager-pro' ); ?></a>
									· <a href="<?php echo esc_url( (string) get_permalink( $doc['id'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Vezi', 'consent-manager-pro' ); ?></a>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $doc['id'] && ! $doc['suggested'] ) : ?>
									<button type="submit" name="generate" value="<?php echo esc_attr( $type ); ?>" class="button" onclick="return confirm('<?php echo esc_js( __( 'Textul paginii va fi înlocuit cu șablonul. Modificările făcute de mână în pagină se pierd. Continui?', 'consent-manager-pro' ) ); ?>');"><?php esc_html_e( 'Regenerează', 'consent-manager-pro' ); ?></button>
								<?php else : ?>
									<button type="submit" name="generate" value="<?php echo esc_attr( $type ); ?>" class="button"><?php esc_html_e( 'Generează', 'consent-manager-pro' ); ?></button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button( __( 'Salvează', 'consent-manager-pro' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * One handler for the screen: save the operator data and the page links,
	 * then, when a "Generate" button was used, fill that page from its template
	 * (a new page is created as a draft).
	 */
	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cmp_legal' ) ) {
			wp_die( esc_html__( 'Acțiune neautorizată.', 'consent-manager-pro' ) );
		}
		$settings = CMP_Consent::get_settings();
		$settings = is_array( $settings ) ? $settings : array();
		$settings['legal_meta'] = CMP_Sanitizer::legal_meta( isset( $_POST['meta'] ) && is_array( $_POST['meta'] ) ? wp_unslash( $_POST['meta'] ) : array() );

		$pages = isset( $_POST['page'] ) && is_array( $_POST['page'] ) ? wp_unslash( $_POST['page'] ) : array();
		foreach ( CMP_Legal::TYPES as $type ) {
			$pid = isset( $pages[ $type ] ) ? (int) $pages[ $type ] : 0;
			if ( $pid && 'page' !== get_post_type( $pid ) ) {
				$pid = 0;
			}
			$settings[ self::settings_key( $type ) ] = max( 0, $pid );
		}
		if ( ! empty( $_POST['wp_privacy'] ) && ! empty( $settings['privacy_page_id'] ) ) {
			update_option( 'wp_page_for_privacy_policy', (int) $settings['privacy_page_id'] );
		}

		$type = isset( $_POST['generate'] ) ? sanitize_key( wp_unslash( $_POST['generate'] ) ) : '';
		$args = array( 'page' => 'cmp-legal', 'saved' => 1 );
		if ( in_array( $type, CMP_Legal::TYPES, true ) ) {
			$post_id = self::generate_page( $type, $settings );
			if ( is_wp_error( $post_id ) ) {
				wp_die( esc_html__( 'Nu am putut crea pagina.', 'consent-manager-pro' ) );
			}
			$settings[ self::settings_key( $type ) ] = (int) $post_id;
			if ( 'privacy' === $type && ! empty( $_POST['wp_privacy'] ) ) {
				update_option( 'wp_page_for_privacy_policy', (int) $post_id );
			}
			$args = array( 'page' => 'cmp-legal', 'generated' => $type );
		}

		update_option( 'cmp_settings', $settings );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Fill the linked page from the template, or create it as a draft. Returns the page id or WP_Error. */
	private static function generate_page( $type, array $settings ) {
		$meta     = isset( $settings['legal_meta'] ) && is_array( $settings['legal_meta'] ) ? $settings['legal_meta'] : array();
		$content  = self::build_content( $type, $meta );
		$labels   = self::labels();
		$existing = (int) ( isset( $settings[ self::settings_key( $type ) ] ) ? $settings[ self::settings_key( $type ) ] : 0 );
		$post     = $existing ? get_post( $existing ) : null;

		if ( $post && 'page' === $post->post_type && 'trash' !== $post->post_status ) {
			$post_id = wp_update_post( array( 'ID' => $existing, 'post_content' => $content ), true );
		} else {
			$post_id = wp_insert_post( array(
				'post_title'   => $labels[ $type ],
				'post_content' => $content,
				'post_status'  => 'draft',
				'post_type'    => 'page',
			), true );
		}
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return is_wp_error( $post_id ) ? $post_id : new WP_Error( 'cmp_legal_failed', 'failed' );
		}

		/** Fires after a legal page is created/updated (WPML/Polylang duplication hooks here). */
		do_action( 'cmp_legal_page_created', (int) $post_id, $type );
		return (int) $post_id;
	}
}
