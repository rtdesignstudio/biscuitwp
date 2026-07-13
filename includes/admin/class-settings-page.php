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
						<th scope="row"><?php esc_html_e( 'Mod afișare', 'consent-manager-pro' ); ?></th>
						<td>
							<?php $cmp_mode = isset( $s['mode'] ) ? $s['mode'] : 'pro'; ?>
							<select name="cmp[mode]">
								<option value="pro" <?php selected( $cmp_mode, 'pro' ); ?>><?php esc_html_e( 'Pro — banner cu consimțământ granular', 'consent-manager-pro' ); ?></option>
								<option value="light" <?php selected( $cmp_mode, 'light' ); ?>><?php esc_html_e( 'Light — bară informativă, fără cookie-uri', 'consent-manager-pro' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Light: site-ul e utilizabil imediat, se afișează doar un notice și nu se stochează niciun cookie.', 'consent-manager-pro' ); ?></p>
						</td>
					</tr>
					<tr data-cmp-mode="pro">
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
					<tr data-cmp-mode="pro">
						<th scope="row"><?php esc_html_e( 'Blocare scripturi tracking', 'consent-manager-pro' ); ?></th>
						<td>
							<label><input type="checkbox" name="cmp[block_scripts]" value="1" <?php checked( ! empty( $s['block_scripts'] ) ); ?>> <?php esc_html_e( 'Blochează Google Analytics / Tag Manager până la consimțământ', 'consent-manager-pro' ); ?></label>
							<p class="description"><?php esc_html_e( 'Împiedică încărcarea scripturilor GA/GTM înainte de Accept (blocare efectivă, nu doar Consent Mode). Testează pe site după activare. Se aplică doar în modul Pro.', 'consent-manager-pro' ); ?></p>
						</td>
					</tr>
					<tr data-cmp-mode="pro">
						<th scope="row"><?php esc_html_e( 'Localizare Google Fonts', 'consent-manager-pro' ); ?></th>
						<td>
							<label><input type="checkbox" name="cmp[localize_fonts]" value="1" <?php checked( ! empty( $s['localize_fonts'] ) ); ?>> <?php esc_html_e( 'Servește Google Fonts local (fără request la Google)', 'consent-manager-pro' ); ?></label>
							<p class="description">
								<?php esc_html_e( 'După activare (și Salvează), apasă „Localizează fonturile" de mai jos ca să descarci fonturile de pe homepage. Se aplică doar în modul Pro.', 'consent-manager-pro' ); ?>
								<?php
								$cmp_fmap = get_option( CMP_Fonts::MAP_KEY, array() );
								if ( is_array( $cmp_fmap ) && $cmp_fmap ) {
									echo ' ' . esc_html( sprintf( /* translators: %d — count */ __( '(%d fonturi localizate)', 'consent-manager-pro' ), count( $cmp_fmap ) ) );
								}
								?>
							</p>
						</td>
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
					<?php if ( '' === CMP_Updater::proxy_url() ) : // Token doar in modul direct-GitHub; ascuns cand exista proxy de update. ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Token GitHub (update-uri)', 'consent-manager-pro' ); ?></th>
						<td>
							<?php if ( defined( 'CMP_GITHUB_TOKEN' ) && CMP_GITHUB_TOKEN ) : ?>
								<p class="description"><?php esc_html_e( 'Tokenul este definit în wp-config.php (CMP_GITHUB_TOKEN) — câmpul de mai jos este ignorat.', 'consent-manager-pro' ); ?></p>
							<?php endif; ?>
							<?php $cmp_has_token = ! empty( $s['github_token'] ); ?>
							<input type="password" class="regular-text" name="cmp[github_token]" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $cmp_has_token ? '••••••••' : '' ); ?>">
							<?php if ( $cmp_has_token ) : ?>
								<label style="margin-left:8px"><input type="checkbox" name="cmp[github_token_clear]" value="1"> <?php esc_html_e( 'Șterge tokenul salvat', 'consent-manager-pro' ); ?></label>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Fine-grained token, read-only pe repo-ul plugin-ului. Recomandat: define( \'CMP_GITHUB_TOKEN\', \'...\' ) în wp-config.php în loc de acest câmp. Fără token, verificarea de update-uri e dezactivată.', 'consent-manager-pro' ); ?></p>
						</td>
					</tr>
					<?php endif; ?>
					<?php
					$cmp_texts = isset( $s['texts'] ) && is_array( $s['texts'] ) ? $s['texts'] : array();
					$cmp_field = function ( $key ) use ( $cmp_texts ) {
						return isset( $cmp_texts[ $key ] ) ? $cmp_texts[ $key ] : '';
					};
					$cmp_text_fields = array(
						'title'       => array( __( 'Titlu banner', 'consent-manager-pro' ), 'pro' ),
						'intro'       => array( __( 'Text introductiv', 'consent-manager-pro' ), 'pro' ),
						'light_intro' => array( __( 'Text notice (Light)', 'consent-manager-pro' ), 'light' ),
						'accept'      => array( __( 'Buton „Accept"', 'consent-manager-pro' ), '' ),
						'reject'      => array( __( 'Buton „Doar necesare"', 'consent-manager-pro' ), 'pro' ),
						'prefs'       => array( __( 'Buton „Gestionează"', 'consent-manager-pro' ), 'pro' ),
						'save'        => array( __( 'Buton „Confirmă selecția"', 'consent-manager-pro' ), 'pro' ),
						'prefs_title' => array( __( 'Titlu preferințe', 'consent-manager-pro' ), 'pro' ),
					);
					?>
					<?php foreach ( $cmp_text_fields as $tk => $tf ) : ?>
						<?php list( $tlabel, $tmode ) = $tf; ?>
						<tr<?php echo $tmode ? ' data-cmp-mode="' . esc_attr( $tmode ) . '"' : ''; ?>>
							<th scope="row"><?php echo esc_html( $tlabel ); ?></th>
							<td>
								<?php if ( 'intro' === $tk || 'light_intro' === $tk ) : ?>
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

			<h2><?php esc_html_e( 'Google Fonts', 'consent-manager-pro' ); ?></h2>
			<?php if ( isset( $_GET['cmp_fonts'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( /* translators: %d — count */ __( 'Localizate %d fonturi.', 'consent-manager-pro' ), (int) $_GET['cmp_fonts'] ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['cmp_fonts_cleared'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Fonturi localizate șterse.', 'consent-manager-pro' ); ?></p></div>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Descarcă fonturile Google folosite pe homepage și servește-le local. Activează întâi opțiunea „Localizare Google Fonts" de mai sus și salvează.', 'consent-manager-pro' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<input type="hidden" name="action" value="cmp_localize_fonts">
				<?php wp_nonce_field( 'cmp_localize_fonts' ); ?>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Localizează fonturile', 'consent-manager-pro' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<input type="hidden" name="action" value="cmp_clear_fonts">
				<?php wp_nonce_field( 'cmp_clear_fonts' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Șterge fonturile localizate', 'consent-manager-pro' ); ?></button>
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
		$old = CMP_Consent::get_settings();
		if ( ! empty( $raw['github_token_clear'] ) ) {
			$clean['github_token'] = '';
		} elseif ( '' === $clean['github_token'] && isset( $old['github_token'] ) ) {
			$clean['github_token'] = (string) $old['github_token'];
		}
		update_option( 'cmp_settings', $clean );
		wp_safe_redirect( add_query_arg( array( 'page' => CMP_Admin::SLUG, 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
