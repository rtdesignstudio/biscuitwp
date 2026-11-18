<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMP_Settings_Page {

	/** Warn when the homepage loads trackers without consent, or runs Light mode with trackers. */
	private static function render_tracker_warnings( $s ) {
		$scan = CMP_Blocker::scan_homepage( isset( $_GET['updated'] ) );
		if ( null === $scan ) {
			return;
		}
		$names = array( 'statistics' => 'Google Analytics / Tag Manager', 'marketing' => 'Meta Pixel' );
		$label = function ( $cats ) use ( $names ) {
			$out = array();
			foreach ( $cats as $c ) {
				$out[] = isset( $names[ $c ] ) ? $names[ $c ] : $c;
			}
			return implode( ', ', array_unique( $out ) );
		};
		// Prefer service names (from the scan by id) over category labels.
		$svc_names = function ( $key ) use ( $scan ) {
			if ( ! isset( $scan['services'][ $key ] ) ) {
				return array();
			}
			$out = array();
			foreach ( CMP_Services::active() as $svc ) {
				if ( in_array( $svc['id'], (array) $scan['services'][ $key ], true ) ) {
					$out[] = $svc['name'];
				}
			}
			return $out;
		};
		$label_raw = function () use ( $scan, $label, $svc_names ) {
			$n = $svc_names( 'raw' );
			return $n ? implode( ', ', $n ) : $label( $scan['raw'] );
		};
		$label_all = function () use ( $scan, $label, $svc_names ) {
			$n = array_unique( array_merge( $svc_names( 'raw' ), $svc_names( 'blocked' ) ) );
			return $n ? implode( ', ', $n ) : $label( array_merge( $scan['raw'], $scan['blocked'] ) );
		};
		$mode = isset( $s['mode'] ) ? $s['mode'] : 'pro';
		if ( 'light' === $mode && ( $scan['raw'] || $scan['blocked'] ) ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Modul Light nu poate cere acord.', 'consent-manager-pro' ) . '</strong> '
				/* translators: %s: tracker names */
				. esc_html( sprintf( __( 'Pe pagina principală am găsit: %s. Aceste scripturi sunt blocate permanent în Light și nu rulează. Dacă ai nevoie de ele, treci pe modul Pro.', 'consent-manager-pro' ), $label_all() ) )
				. '</p></div>';
			if ( $scan['raw'] ) {
				echo '<div class="notice notice-error"><p>'
					/* translators: %s: tracker names */
					. esc_html( sprintf( __( 'Atenție: %s pare încărcat de un script pe care pluginul nu îl poate bloca (injectat ulterior din JavaScript). Verifică sursa lui.', 'consent-manager-pro' ), $label_raw() ) )
					. '</p></div>';
			}
			return;
		}
		if ( 'pro' === $mode && isset( $scan['settings_link'] ) && false === $scan['settings_link'] ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Lipsește linkul „Setări cookie”.', 'consent-manager-pro' ) . '</strong> '
				. esc_html__( 'Pe pagina principală nu am găsit niciun link care redeschide preferințele. Vizitatorii trebuie să-și poată retrage acordul la fel de ușor cum l-au dat (Legea 195/2024 și GDPR, art. 7). Pune în footer shortcode-ul [cmp_cookie_settings_link] sau un link de meniu cu URL-ul #cmp-settings.', 'consent-manager-pro' )
				. '</p></div>';
		}
		if ( 'pro' === $mode && $scan['raw'] ) {
			$msg = empty( $s['block_scripts'] )
				/* translators: %s: tracker names */
				? __( '%s se încarcă pe pagina principală înainte de acord. Bifează „Blochează scripturile de tracking până la consimțământ” mai jos.', 'consent-manager-pro' )
				/* translators: %s: tracker names */
				: __( '%s se încarcă pe pagina principală înainte de acord, deși blocarea e activă: probabil e injectat ulterior din JavaScript. Verifică sursa lui.', 'consent-manager-pro' );
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Tracking fără acord.', 'consent-manager-pro' ) . '</strong> '
				. esc_html( sprintf( $msg, $label_raw() ) ) . '</p></div>';
		}
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = CMP_Consent::get_settings();
		$positions = array(
			'top-left'      => 'Stânga sus',
			'top-center'    => 'Sus centru',
			'top-right'     => 'Dreapta sus',
			'middle-center' => 'Mijloc centru',
			'bottom-left'   => 'Stânga jos',
			'bottom-center' => 'Jos centru',
			'bottom-right'  => 'Dreapta jos',
		);
		// Map legacy stored values onto the new set for the selected() display.
		$cmp_pos = isset( $s['position'] ) ? $s['position'] : 'middle-center';
		$cmp_pos_alias = array( 'bottom' => 'bottom-center', 'top' => 'top-center', 'modal-center' => 'middle-center' );
		if ( isset( $cmp_pos_alias[ $cmp_pos ] ) ) {
			$cmp_pos = $cmp_pos_alias[ $cmp_pos ];
		}
		$presets   = array( 'neutral' => 'Neutral', 'warm' => 'Warm', 'dark' => 'Dark', 'egov' => 'eGov' );
		?>
		<div class="wrap cmp-admin">
			<h1><?php esc_html_e( 'Consent Manager — Setări', 'consent-manager-pro' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Setări salvate.', 'consent-manager-pro' ); ?></p></div>
			<?php endif; ?>
			<?php self::render_tracker_warnings( $s ); ?>
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
							<p class="description"><?php esc_html_e( 'Light: site-ul e utilizabil imediat, se afișează doar un notice și nu se stochează niciun cookie. Light nu poate cere acord, așa că Google Analytics, Tag Manager și Meta Pixel sunt blocate permanent; dacă ai nevoie de ele, folosește Pro.', 'consent-manager-pro' ); ?></p>
						</td>
					</tr>
					<tr data-cmp-mode="pro">
						<th scope="row"><?php esc_html_e( 'Poziție banner', 'consent-manager-pro' ); ?></th>
						<td><select name="cmp[position]">
							<?php foreach ( $positions as $k => $lbl ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $cmp_pos, $k ); ?>><?php echo esc_html( $lbl ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
					<tr data-cmp-mode="pro">
						<th scope="row"><?php esc_html_e( 'Memorarea consimțământului (zile)', 'consent-manager-pro' ); ?></th>
						<td>
							<input type="number" name="cmp[cookie_days]" min="1" max="396" value="<?php echo esc_attr( isset( $s['cookie_days'] ) ? (int) $s['cookie_days'] : 180 ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'Câte zile ține minte browserul alegerea vizitatorului înainte să reapară bannerul (implicit 180, maximum 396 = 13 luni, practica din UE). Diferit de retenția registrului de consimțăminte.', 'consent-manager-pro' ); ?></p>
						</td>
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
							<label><input type="checkbox" name="cmp[block_scripts]" value="1" <?php checked( ! empty( $s['block_scripts'] ) ); ?>> <?php esc_html_e( 'Blochează scripturile de tracking până la consimțământ', 'consent-manager-pro' ); ?></label>
							<p class="description"><?php esc_html_e( 'Google Analytics, Tag Manager și Meta Pixel nu se încarcă deloc înainte de Accept: Google și Meta nu primesc nimic, nici măcar IP-ul (Consent Mode „basic”). Video-urile YouTube și Vimeo și hărțile Google Maps apar ca un bloc cu butonul „Încarcă conținutul” până la acord. Recomandat: pornit. În modul Light, blocarea e mereu activă.', 'consent-manager-pro' ); ?></p>
						</td>
					</tr>
					<tr data-cmp-mode="pro">
						<th scope="row"><?php esc_html_e( 'Buton flotant redeschidere', 'consent-manager-pro' ); ?></th>
						<td>
							<label><input type="checkbox" name="cmp[reopen_button]" value="1" <?php checked( ! empty( $s['reopen_button'] ) ); ?>> <?php esc_html_e( 'Afișează un buton rotund (jos-stânga) pentru redeschiderea preferințelor de cookie-uri', 'consent-manager-pro' ); ?></label>
							<p class="description"><?php esc_html_e( 'Recomandat: oprit, cu un link „Setări cookie-uri” în footer — shortcode [cmp_cookie_settings_link] sau un link de meniu cu URL-ul #cmp-settings.', 'consent-manager-pro' ); ?></p>
							<p class="description"><?php esc_html_e( 'Apare după ce vizitatorul face o alegere de consimțământ. Se aplică doar în modul Pro.', 'consent-manager-pro' ); ?></p>
						</td>
					</tr>
					<tr data-cmp-mode="pro">
						<th scope="row"><?php esc_html_e( 'Reîncărcare după alegere', 'consent-manager-pro' ); ?></th>
						<td>
							<label><input type="checkbox" name="cmp[reload_on_decision]" value="1" <?php checked( ! empty( $s['reload_on_decision'] ) ); ?>> <?php esc_html_e( 'Reîncarcă pagina după alegerea din banner', 'consent-manager-pro' ); ?></label>
							<p class="description"><?php esc_html_e( 'Pentru site-uri cu animații la încărcare, ascunse de overlay: după reîncărcare se văd de la început. Se aplică doar la prima alegere, din bannerul apărut la încărcare — nu și la schimbările făcute ulterior din linkul „Setări cookie-uri”.', 'consent-manager-pro' ); ?></p>
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
						<th scope="row"><?php esc_html_e( 'Pagini legale', 'consent-manager-pro' ); ?></th>
						<td>
							<?php
							$cmp_docs = CMP_Legal_Page::documents();
							$cmp_sum  = CMP_Legal::status_summary( wp_list_pluck( $cmp_docs, 'status' ) );
							printf(
								/* translators: 1: published count, 2: total, 3: drafts */
								esc_html__( 'Publicate: %1$d din %2$d · ciorne: %3$d', 'consent-manager-pro' ),
								(int) $cmp_sum['published'],
								count( $cmp_docs ),
								(int) $cmp_sum['draft']
							);
							?>
							· <a href="<?php echo esc_url( admin_url( 'admin.php?page=cmp-legal' ) ); ?>"><?php esc_html_e( 'Gestionează paginile legale', 'consent-manager-pro' ); ?></a>
						</td>
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
		$old   = CMP_Consent::get_settings();
		// Legal page links and operator data are managed on the "Pagini legale" screen.
		$clean = CMP_Sanitizer::keep_legal( CMP_Sanitizer::settings( $raw ), is_array( $old ) ? $old : array() );
		update_option( 'cmp_settings', $clean );
		wp_safe_redirect( add_query_arg( array( 'page' => CMP_Admin::SLUG, 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
