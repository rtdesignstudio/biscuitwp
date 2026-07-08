<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMP_Settings_Page {

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = CMP_Consent::get_settings();
		$positions = array( 'bottom' => 'Jos', 'top' => 'Sus', 'modal-center' => 'Modal centrat' );
		$presets   = array( 'neutral' => 'Neutral', 'warm' => 'Warm', 'dark' => 'Dark', 'egov' => 'eGov' );
		?>
		<div class="wrap cmp-admin">
			<h1><?php esc_html_e( 'Consent Manager — Setări', 'consent-manager-pro' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Setări salvate.', 'consent-manager-pro' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cmp_save_settings">
				<?php wp_nonce_field( 'cmp_save_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Poziție banner', 'consent-manager-pro' ); ?></th>
						<td><select name="cmp[position]">
							<?php foreach ( $positions as $k => $lbl ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['position'], $k ); ?>><?php echo esc_html( $lbl ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Preset design', 'consent-manager-pro' ); ?></th>
						<td><select name="cmp[preset]">
							<?php foreach ( $presets as $k => $lbl ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['preset'], $k ); ?>><?php echo esc_html( $lbl ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Versiune politică', 'consent-manager-pro' ); ?></th>
						<td>
							<input type="text" name="cmp[policy_version]" value="<?php echo esc_attr( $s['policy_version'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'Incrementează pentru a re-afișa banner-ul tuturor vizitatorilor.', 'consent-manager-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Retenție log (luni)', 'consent-manager-pro' ); ?></th>
						<td><input type="number" min="1" name="cmp[retention_months]" value="<?php echo esc_attr( $s['retention_months'] ); ?>" class="small-text"></td>
					</tr>
					<?php
					$cmp_texts = isset( $s['texts'] ) && is_array( $s['texts'] ) ? $s['texts'] : array();
					$cmp_field = function ( $key ) use ( $cmp_texts ) {
						return isset( $cmp_texts[ $key ] ) ? $cmp_texts[ $key ] : '';
					};
					$cmp_text_fields = array(
						'title'       => __( 'Titlu banner', 'consent-manager-pro' ),
						'intro'       => __( 'Text introductiv', 'consent-manager-pro' ),
						'accept'      => __( 'Buton „Acceptă tot"', 'consent-manager-pro' ),
						'reject'      => __( 'Buton „Doar necesare"', 'consent-manager-pro' ),
						'prefs'       => __( 'Buton „Gestionează"', 'consent-manager-pro' ),
						'save'        => __( 'Buton „Confirmă selecția"', 'consent-manager-pro' ),
						'prefs_title' => __( 'Titlu preferințe', 'consent-manager-pro' ),
					);
					?>
					<?php foreach ( $cmp_text_fields as $tk => $tlabel ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $tlabel ); ?></th>
							<td>
								<?php if ( 'intro' === $tk ) : ?>
									<textarea name="cmp[texts][<?php echo esc_attr( $tk ); ?>]" class="large-text" rows="2" placeholder="<?php esc_attr_e( '(implicit)', 'consent-manager-pro' ); ?>"><?php echo esc_textarea( $cmp_field( $tk ) ); ?></textarea>
								<?php else : ?>
									<input type="text" class="regular-text" name="cmp[texts][<?php echo esc_attr( $tk ); ?>]" value="<?php echo esc_attr( $cmp_field( $tk ) ); ?>" placeholder="<?php esc_attr_e( '(implicit)', 'consent-manager-pro' ); ?>">
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Pagină Confidențialitate', 'consent-manager-pro' ); ?></th>
						<td><?php wp_dropdown_pages( array( 'name' => 'cmp[privacy_page_id]', 'selected' => $s['privacy_page_id'], 'show_option_none' => __( '— niciuna —', 'consent-manager-pro' ), 'option_none_value' => 0 ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Pagină Termeni', 'consent-manager-pro' ); ?></th>
						<td><?php wp_dropdown_pages( array( 'name' => 'cmp[terms_page_id]', 'selected' => $s['terms_page_id'], 'show_option_none' => __( '— niciuna —', 'consent-manager-pro' ), 'option_none_value' => 0 ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Pagină Cookies', 'consent-manager-pro' ); ?></th>
						<td><?php wp_dropdown_pages( array( 'name' => 'cmp[cookie_page_id]', 'selected' => $s['cookie_page_id'], 'show_option_none' => __( '— niciuna —', 'consent-manager-pro' ), 'option_none_value' => 0 ) ); ?></td>
					</tr>
				</table>
				<?php submit_button( __( 'Salvează setările', 'consent-manager-pro' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cmp_save_settings' ) ) {
			wp_die( esc_html__( 'Acțiune neautorizată.', 'consent-manager-pro' ) );
		}
		$raw   = isset( $_POST['cmp'] ) && is_array( $_POST['cmp'] ) ? wp_unslash( $_POST['cmp'] ) : array();
		$clean = CMP_Sanitizer::settings( $raw );
		update_option( 'cmp_settings', $clean );
		wp_safe_redirect( add_query_arg( array( 'page' => CMP_Admin::SLUG, 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
