<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMP_Legal_Page {

	private static function labels() {
		return array(
			'privacy' => __( 'Politica de confidențialitate', 'consent-manager-pro' ),
			'terms'   => __( 'Termeni și condiții', 'consent-manager-pro' ),
			'cookie'  => __( 'Politica de cookie-uri', 'consent-manager-pro' ),
		);
	}

	private static function settings_key( $type ) {
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
			'cookie_table'   => 'cookie' === $type ? CMP_Legal::cookie_table_html( CMP_Consent::get_categories() ) : '',
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

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = CMP_Consent::get_settings();
		?>
		<div class="wrap cmp-admin">
			<h1><?php esc_html_e( 'Consent Manager — Pagini legale', 'consent-manager-pro' ); ?></h1>
			<?php if ( isset( $_GET['generated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					$pid = isset( $_GET['pid'] ) ? (int) $_GET['pid'] : 0;
					printf(
						/* translators: %s — edit link */
						esc_html__( 'Pagină generată ca ciornă. %s', 'consent-manager-pro' ),
						$pid ? '<a href="' . esc_url( get_edit_post_link( $pid ) ) . '">' . esc_html__( 'Editează pagina', 'consent-manager-pro' ) . '</a>' : ''
					);
					?>
				</p></div>
			<?php endif; ?>

			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Șabloanele sunt un punct de plecare informativ, NU consultanță juridică. Revizuiește textul cu un specialist înainte de publicare.', 'consent-manager-pro' ); ?></p></div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cmp_generate_legal">
				<?php wp_nonce_field( 'cmp_generate_legal' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'Denumire operator', 'consent-manager-pro' ); ?></th>
						<td><input type="text" name="company" class="regular-text" value="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Email contact', 'consent-manager-pro' ); ?></th>
						<td><input type="email" name="contact_email" class="regular-text" value="<?php echo esc_attr( get_bloginfo( 'admin_email' ) ); ?>"></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Responsabil date (DPO)', 'consent-manager-pro' ); ?></th>
						<td><input type="text" name="dpo" class="regular-text" value=""></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Jurisdicție', 'consent-manager-pro' ); ?></th>
						<td><input type="text" name="jurisdiction" class="regular-text" value="Republica Moldova"></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Tip document', 'consent-manager-pro' ); ?></th>
						<td><select name="type">
							<?php foreach ( self::labels() as $k => $lbl ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $lbl ); ?></option>
							<?php endforeach; ?>
						</select></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Confidențialitate oficială', 'consent-manager-pro' ); ?></th>
						<td><label><input type="checkbox" name="set_official" value="1" checked> <?php esc_html_e( 'Setează ca pagina oficială de confidențialitate WordPress (doar pentru tipul „Confidențialitate").', 'consent-manager-pro' ); ?></label></td></tr>
				</table>
				<?php submit_button( __( 'Generează pagina', 'consent-manager-pro' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Pagini configurate', 'consent-manager-pro' ); ?></h2>
			<ul>
				<?php foreach ( self::labels() as $k => $lbl ) :
					$pid = (int) ( isset( $s[ self::settings_key( $k ) ] ) ? $s[ self::settings_key( $k ) ] : 0 );
					?>
					<li><strong><?php echo esc_html( $lbl ); ?>:</strong>
						<?php if ( $pid && get_post( $pid ) ) : ?>
							<a href="<?php echo esc_url( (string) get_edit_post_link( $pid ) ); ?>"><?php echo esc_html( get_the_title( $pid ) ); ?></a>
						<?php else : ?>
							<?php esc_html_e( '— neconfigurată —', 'consent-manager-pro' ); ?>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	public static function generate() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cmp_generate_legal' ) ) {
			wp_die( esc_html__( 'Acțiune neautorizată.', 'consent-manager-pro' ) );
		}
		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		if ( ! in_array( $type, CMP_Legal::TYPES, true ) ) {
			wp_die( esc_html__( 'Tip document invalid.', 'consent-manager-pro' ) );
		}

		$meta = array(
			'company'       => isset( $_POST['company'] ) ? sanitize_text_field( wp_unslash( $_POST['company'] ) ) : get_bloginfo( 'name' ),
			'contact_email' => isset( $_POST['contact_email'] ) ? sanitize_email( wp_unslash( $_POST['contact_email'] ) ) : get_bloginfo( 'admin_email' ),
			'dpo'           => isset( $_POST['dpo'] ) ? sanitize_text_field( wp_unslash( $_POST['dpo'] ) ) : '',
			'jurisdiction'  => isset( $_POST['jurisdiction'] ) ? sanitize_text_field( wp_unslash( $_POST['jurisdiction'] ) ) : '',
		);
		$content = self::build_content( $type, $meta );
		$labels  = self::labels();
		$title   = $labels[ $type ];

		$settings = CMP_Consent::get_settings();
		$key      = self::settings_key( $type );
		$existing = (int) ( isset( $settings[ $key ] ) ? $settings[ $key ] : 0 );

		$existing_ok = false;
		if ( $existing ) {
			$existing_post = get_post( $existing );
			$existing_ok   = ( $existing_post && 'page' === $existing_post->post_type && 'trash' !== $existing_post->post_status );
		}
		if ( $existing_ok ) {
			$post_id = wp_update_post( array(
				'ID'           => $existing,
				'post_content' => $content,
			), true );
		} else {
			$post_id = wp_insert_post( array(
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => 'draft',
				'post_type'    => 'page',
			), true );
		}

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			wp_die( esc_html__( 'Nu am putut crea pagina.', 'consent-manager-pro' ) );
		}

		$settings[ $key ] = (int) $post_id;
		$settings['legal_meta'] = $meta;
		update_option( 'cmp_settings', $settings );

		if ( 'privacy' === $type && ! empty( $_POST['set_official'] ) ) {
			update_option( 'wp_page_for_privacy_policy', (int) $post_id );
		}

		/**
		 * Fires after a legal page is created/updated. Multilingual duplication
		 * (WPML/Polylang) hooks here — implemented in a later plan.
		 */
		do_action( 'cmp_legal_page_created', (int) $post_id, $type );

		wp_safe_redirect( add_query_arg( array( 'page' => 'cmp-legal', 'generated' => 1, 'pid' => (int) $post_id ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
