<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** "Servicii" screen: library overrides, custom services, scan results, post-update note. */
class CMP_Services_Page {

	const SLUG = 'cmp-services';

	/** Category slug => label (always includes "necessary"). */
	private static function category_options() {
		$out = array();
		foreach ( (array) CMP_Consent::get_categories() as $cat ) {
			if ( is_array( $cat ) && ! empty( $cat['slug'] ) ) {
				$out[ (string) $cat['slug'] ] = isset( $cat['label'] ) && '' !== $cat['label'] ? (string) $cat['label'] : (string) $cat['slug'];
			}
		}
		if ( ! isset( $out['necessary'] ) ) {
			$out = array( 'necessary' => __( 'Necesare', 'consent-manager-pro' ) ) + $out;
		}
		return $out;
	}

	private static function status_label( $svc ) {
		if ( isset( $svc['category'] ) && 'necessary' === $svc['category'] ) {
			return __( 'neblocat (necesar)', 'consent-manager-pro' );
		}
		if ( ! empty( $svc['exempt'] ) ) {
			return __( 'excepție', 'consent-manager-pro' );
		}
		return __( 'blocat până la acord', 'consent-manager-pro' );
	}

	/** One library service row with its controls. */
	private static function library_row( $svc, array $cats ) {
		$id  = (string) $svc['id'];
		$cat = isset( $svc['category'] ) ? (string) $svc['category'] : 'marketing';
		$n   = 'cmp_services[overrides][' . $id . ']';
		if ( ! isset( $cats[ $cat ] ) ) {
			$cats[ $cat ] = $cat;
		}
		?>
		<tr class="cmp-svc-row" data-cmp-service="<?php echo esc_attr( $id ); ?>">
			<td class="cmp-svc-name"><strong><?php echo esc_html( $svc['name'] ); ?></strong>
				<?php if ( ! empty( $svc['provider'] ) ) : ?><br><span class="description"><?php echo esc_html( $svc['provider'] ); ?></span><?php endif; ?>
				<?php if ( 'recaptcha' === $id ) : ?>
					<p class="description"><?php esc_html_e( 'Autoritățile din UE consideră că reCAPTCHA necesită acord. E lăsat neblocat ca să nu se strice formularele. Îl poți bloca dintr-un click.', 'consent-manager-pro' ); ?></p>
				<?php endif; ?>
			</td>
			<td>
				<select name="<?php echo esc_attr( $n ); ?>[category]">
					<?php foreach ( $cats as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $cat, $slug ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<?php if ( 'necessary' === $cat ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $n ); ?>[exempt]" value="<?php echo ! empty( $svc['exempt'] ) ? '1' : '0'; ?>">
				<?php elseif ( ! empty( $svc['mode_exempt'] ) ) : ?>
					<label><input type="checkbox" value="1" checked disabled> <?php esc_html_e( 'nu bloca (excepție)', 'consent-manager-pro' ); ?></label>
					<p class="description"><?php esc_html_e( 'În modul Advanced, Google Analytics și Tag Manager se încarcă mereu, cu Consent Mode pe «refuzat».', 'consent-manager-pro' ); ?></p>
					<p class="description"><?php echo esc_html( CMP_Services::consent_exempt( $svc ) ? __( 'Setarea ta: nu bloca (se aplică și în Strict).', 'consent-manager-pro' ) : __( 'Setarea ta: blocat până la acord (se aplică în Strict).', 'consent-manager-pro' ) ); ?></p>
				<?php else : ?>
					<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[exempt]" value="1" <?php checked( ! empty( $svc['exempt'] ) ); ?>> <?php esc_html_e( 'nu bloca (excepție)', 'consent-manager-pro' ); ?></label>
				<?php endif; ?>
			</td>
			<td class="cmp-svc-status"><?php echo esc_html( self::status_label( $svc ) ); ?></td>
		</tr>
		<?php
	}

	private static function custom_row( $c, array $cats, $can_script, $idx ) {
		$name    = isset( $c['name'] ) ? (string) $c['name'] : '';
		$cat     = isset( $c['category'] ) ? (string) $c['category'] : 'marketing';
		$hosts   = isset( $c['hosts'] ) ? implode( "\n", array_map( 'strval', (array) $c['hosts'] ) ) : '';
		$cookies = isset( $c['cookies'] ) && is_array( $c['cookies'] ) ? $c['cookies'] : array();
		$script  = isset( $c['script'] ) ? (string) $c['script'] : '';
		$n       = 'cmp_services[custom][' . $idx . ']';
		$locked  = ! $can_script && '' !== $script;
		$dis     = $locked ? ' disabled' : '';
		if ( ! isset( $cats[ $cat ] ) ) {
			$cats[ $cat ] = $cat;
		}
		?>
		<div class="cmp-cat-row cmp-custom-row" data-cmp-custom-row>
			<div class="cmp-cat-row__head">
				<strong><?php esc_html_e( 'Serviciu personalizat', 'consent-manager-pro' ); ?></strong>
				<button type="button" class="button-link button-link-delete" data-cmp-del-custom><?php esc_html_e( 'Șterge', 'consent-manager-pro' ); ?></button>
			</div>
			<?php if ( ! empty( $c['id'] ) ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $n ); ?>[id]" value="<?php echo esc_attr( $c['id'] ); ?>">
			<?php endif; ?>
			<p class="cmp-cat-row__names">
				<label><?php esc_html_e( 'Nume', 'consent-manager-pro' ); ?><br>
					<input type="text" name="<?php echo esc_attr( $n ); ?>[name]" value="<?php echo esc_attr( $name ); ?>"></label>
				<label><?php esc_html_e( 'Categorie', 'consent-manager-pro' ); ?><br>
					<select name="<?php echo esc_attr( $n ); ?>[category]"<?php echo $dis; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
						<?php foreach ( $cats as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $cat, $slug ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select></label>
			</p>
			<p>
				<label><?php esc_html_e( 'Domenii (unul pe rând)', 'consent-manager-pro' ); ?><br>
					<textarea class="large-text code" rows="3" name="<?php echo esc_attr( $n ); ?>[hosts]"<?php echo $dis; // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo esc_textarea( $hosts ); ?></textarea></label>
				<span class="description"><?php esc_html_e( 'Se blochează scripturile și iframe-urile (video, formulare, calendare) de pe aceste domenii.', 'consent-manager-pro' ); ?></span>
			</p>
			<div class="cmp-cookies" data-cmp-ck-prefix="<?php echo esc_attr( $n ); ?>[cookies]">
				<strong><?php esc_html_e( 'Cookie-uri', 'consent-manager-pro' ); ?></strong>
				<table>
					<thead><tr>
						<th><?php esc_html_e( 'Nume', 'consent-manager-pro' ); ?></th>
						<th><?php esc_html_e( 'Durată', 'consent-manager-pro' ); ?></th>
						<th><?php esc_html_e( 'Scop', 'consent-manager-pro' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody data-cmp-svc-cookies-body>
						<?php foreach ( array_values( $cookies ) as $ci => $ck ) : ?>
							<tr data-cmp-svc-cookie-row>
								<td><input type="text" name="<?php echo esc_attr( $n ); ?>[cookies][<?php echo (int) $ci; ?>][name]" value="<?php echo esc_attr( isset( $ck['name'] ) ? $ck['name'] : '' ); ?>"></td>
								<td><input type="text" name="<?php echo esc_attr( $n ); ?>[cookies][<?php echo (int) $ci; ?>][duration]" value="<?php echo esc_attr( isset( $ck['duration'] ) ? $ck['duration'] : '' ); ?>"></td>
								<td><input type="text" name="<?php echo esc_attr( $n ); ?>[cookies][<?php echo (int) $ci; ?>][purpose]" value="<?php echo esc_attr( isset( $ck['purpose'] ) ? $ck['purpose'] : '' ); ?>"></td>
								<td><button type="button" class="button-link button-link-delete" data-cmp-del-svc-cookie>&times;</button></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button" data-cmp-add-svc-cookie><?php esc_html_e( '+ Adaugă cookie', 'consent-manager-pro' ); ?></button></p>
			</div>
			<?php if ( $can_script ) : ?>
				<p>
					<label><?php esc_html_e( 'Script (opțional, rulează doar după acord)', 'consent-manager-pro' ); ?><br>
						<textarea class="large-text code" rows="4" name="<?php echo esc_attr( $n ); ?>[script]"><?php echo esc_textarea( $script ); ?></textarea></label>
				</p>
			<?php elseif ( $locked ) : ?>
				<p class="description"><?php esc_html_e( 'Are script (doar un administrator cu drept de cod îl poate modifica)', 'consent-manager-pro' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Opening this screen counts as having seen the post-update note.
		self::mark_seen();

		$cats       = self::category_options();
		$active     = CMP_Services::active();
		$library    = array();
		$by_id      = array();
		foreach ( $active as $s ) {
			if ( isset( $s['source'] ) && 'library' === $s['source'] ) {
				$library[]          = $s;
				$by_id[ $s['id'] ] = $s;
			}
		}
		$stored     = get_option( 'cmp_services', array() );
		$custom     = is_array( $stored ) && isset( $stored['custom'] ) && is_array( $stored['custom'] ) ? array_values( $stored['custom'] ) : array();
		$can_script = current_user_can( 'unfiltered_html' );

		$scan  = CMP_Blocker::scan_homepage( false );
		$found = array();
		if ( is_array( $scan ) && isset( $scan['services'] ) ) {
			$ids = isset( $scan['services']['found'] ) ? (array) $scan['services']['found'] : array_merge( (array) $scan['services']['raw'], (array) $scan['services']['blocked'] );
			foreach ( $active as $s ) {
				if ( in_array( $s['id'], $ids, true ) ) {
					$found[ $s['id'] ] = $s;
				}
			}
		}
		?>
		<div class="wrap cmp-admin">
			<h1><?php esc_html_e( 'Consent Manager — Servicii', 'consent-manager-pro' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Servicii salvate.', 'consent-manager-pro' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['cmp_reset'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Lista a fost resetată.', 'consent-manager-pro' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['cmp_noscript'] ) ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Scriptul nu a fost salvat: contul tău nu are dreptul de a adăuga cod (unfiltered_html). Restul modificărilor au fost salvate.', 'consent-manager-pro' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['cmp_ownhost'] ) ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Domeniul site-ului tău nu poate fi un domeniu de serviciu personalizat (ar bloca scripturile site-ului). L-am scos din listă.', 'consent-manager-pro' ); ?></p></div>
			<?php endif; ?>
			<?php foreach ( CMP_Services::errors() as $err ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( sprintf( /* translators: %s: error text */ __( 'Biblioteca de servicii: %s', 'consent-manager-pro' ), $err ) ); ?></p></div>
			<?php endforeach; ?>
			<?php if ( class_exists( 'WooCommerce' ) && ! CMP_Consent_API::active() ) : ?>
				<div class="notice notice-info"><p>
					<?php esc_html_e( 'WooCommerce este activ, dar pluginul WP Consent API lipsește: extensiile WooCommerce nu află alegerea vizitatorului.', 'consent-manager-pro' ); ?>
					<?php if ( current_user_can( 'install_plugins' ) ) : ?>
						<a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=wp-consent-api&tab=search&type=term' ) ); ?>"><?php esc_html_e( 'Instalează WP Consent API', 'consent-manager-pro' ); ?></a>
					<?php endif; ?>
				</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cmp_save_services">
				<?php wp_nonce_field( 'cmp_save_services' ); ?>

				<h2>
					<?php esc_html_e( 'Găsite pe site', 'consent-manager-pro' ); ?>
					<?php if ( is_array( $scan ) && ! empty( $scan['time'] ) ) : ?>
						<span class="description">(<?php echo esc_html( sprintf( /* translators: %s: time span like "12 minute" */ __( 'pagina principală, acum %s', 'consent-manager-pro' ), human_time_diff( (int) $scan['time'] ) ) ); ?>)</span>
					<?php endif; ?>
					<button type="submit" class="button" form="cmp-rescan-form"><?php esc_html_e( 'Rescanează', 'consent-manager-pro' ); ?></button>
				</h2>
				<?php if ( null === $scan ) : ?>
					<p class="description"><?php esc_html_e( 'Pagina principală nu a putut fi citită. Încearcă „Rescanează”.', 'consent-manager-pro' ); ?></p>
				<?php elseif ( ! isset( $scan['services'] ) ) : ?>
					<p class="description"><?php esc_html_e( 'Apasă „Rescanează” ca să afli ce servicii încarcă pagina principală.', 'consent-manager-pro' ); ?></p>
				<?php elseif ( ! $found ) : ?>
					<p class="description"><?php esc_html_e( 'Nu am găsit servicii care să aștepte acordul pe pagina principală.', 'consent-manager-pro' ); ?></p>
				<?php else : ?>
					<table class="widefat striped cmp-svc-table"><tbody>
						<?php foreach ( $found as $s ) : ?>
							<?php if ( isset( $s['source'] ) && 'custom' === $s['source'] ) : ?>
								<tr class="cmp-svc-row"><td class="cmp-svc-name"><strong><?php echo esc_html( $s['name'] ); ?></strong></td><td class="description"><?php esc_html_e( 'configurat mai jos', 'consent-manager-pro' ); ?></td><td></td><td class="cmp-svc-status"><?php echo esc_html( self::status_label( $s ) ); ?></td></tr>
							<?php else : ?>
								<?php self::library_row( $s, $cats ); ?>
							<?php endif; ?>
						<?php endforeach; ?>
					</tbody></table>
				<?php endif; ?>

				<details class="cmp-svc-library">
					<summary><h2 style="display:inline"><?php echo esc_html( sprintf( /* translators: %d: number of services */ __( 'Toată biblioteca (%d)', 'consent-manager-pro' ), count( $library ) ) ); ?></h2></summary>
					<table class="widefat striped cmp-svc-table"><tbody>
						<?php foreach ( $library as $s ) : ?>
							<?php if ( isset( $found[ $s['id'] ] ) ) : ?>
								<tr class="cmp-svc-row"><td class="cmp-svc-name"><strong><?php echo esc_html( $s['name'] ); ?></strong></td><td colspan="3" class="description"><?php esc_html_e( 'găsit pe site, se setează mai sus', 'consent-manager-pro' ); ?></td></tr>
							<?php else : ?>
								<?php self::library_row( $s, $cats ); ?>
							<?php endif; ?>
						<?php endforeach; ?>
					</tbody></table>
				</details>

				<h2><?php esc_html_e( 'Servicii personalizate', 'consent-manager-pro' ); ?></h2>
				<div data-cmp-customs>
					<?php foreach ( $custom as $i => $c ) : ?>
						<?php self::custom_row( $c, $cats, $can_script, 'c' . (int) $i ); ?>
					<?php endforeach; ?>
				</div>
				<p><button type="button" class="button" data-cmp-add-custom><?php esc_html_e( '+ Adaugă serviciu', 'consent-manager-pro' ); ?></button></p>

				<p class="description"><span class="dashicons dashicons-info"></span> <?php esc_html_e( 'Blocăm ce apare în HTML-ul paginii. Ce adaugă Google Tag Manager sau alt JavaScript ulterior trebuie controlat din GTM (Consent Mode).', 'consent-manager-pro' ); ?></p>

				<?php submit_button( __( 'Salvează serviciile', 'consent-manager-pro' ) ); ?>
			</form>

			<form id="cmp-rescan-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cmp_rescan_services">
				<?php wp_nonce_field( 'cmp_rescan_services' ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Lista serviciilor văzute va conține doar ce a găsit ultima scanare. Continui?', 'consent-manager-pro' ) ); ?>');">
				<input type="hidden" name="action" value="cmp_reset_seen">
				<?php wp_nonce_field( 'cmp_reset_seen' ); ?>
				<p><button type="submit" class="button"><?php esc_html_e( 'Resetează lista serviciilor văzute', 'consent-manager-pro' ); ?></button>
				<span class="description"><?php esc_html_e( 'Serviciile găsite vreodată pe site rămân în listă (pentru re-consimțământ) până o resetezi.', 'consent-manager-pro' ); ?></span></p>
			</form>

			<script type="text/template" id="cmp-custom-template"><?php self::custom_row( array(), $cats, $can_script, '__i__' ); ?></script>
		</div>
		<?php
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cmp_save_services' ) ) {
			wp_die( esc_html__( 'Acțiune neautorizată.', 'consent-manager-pro' ) );
		}
		$raw        = isset( $_POST['cmp_services'] ) && is_array( $_POST['cmp_services'] ) ? wp_unslash( $_POST['cmp_services'] ) : array();
		$can_script = current_user_can( 'unfiltered_html' );
		$old        = get_option( 'cmp_services', array() );
		$old        = is_array( $old ) ? $old : array();

		$cats = array();
		foreach ( (array) CMP_Consent::get_categories() as $cat ) {
			if ( is_array( $cat ) && ! empty( $cat['slug'] ) ) {
				$cats[] = (string) $cat['slug'];
			}
		}
		$ids        = array();
		$lib_cats   = array();
		$lib_exempt = array();
		foreach ( CMP_Services::library() as $s ) {
			$ids[]                = $s['id'];
			$lib_cats[ $s['id'] ] = $s['category'];
			if ( ! empty( $s['exempt_default'] ) ) {
				$lib_exempt[ $s['id'] ] = true;
			}
		}

		// Did the user try to save a script they have no right to save?
		$script_dropped = false;
		if ( ! $can_script && isset( $raw['custom'] ) && is_array( $raw['custom'] ) ) {
			$old_scripts = array();
			foreach ( ( isset( $old['custom'] ) && is_array( $old['custom'] ) ? $old['custom'] : array() ) as $oc ) {
				if ( is_array( $oc ) && isset( $oc['id'] ) ) {
					$old_scripts[ (string) $oc['id'] ] = isset( $oc['script'] ) ? (string) $oc['script'] : '';
				}
			}
			foreach ( $raw['custom'] as $c ) {
				if ( ! is_array( $c ) || ! isset( $c['script'] ) || ! is_string( $c['script'] ) || '' === trim( $c['script'] ) ) {
					continue;
				}
				$cid  = isset( $c['id'] ) ? (string) $c['id'] : '';
				$prev = isset( $old_scripts[ $cid ] ) ? $old_scripts[ $cid ] : '';
				if ( $c['script'] !== $prev ) {
					$script_dropped = true;
					break;
				}
			}
		}

		// The site's own domain is never a custom host (it would block the site itself).
		$own      = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$own_host = false;
		foreach ( ( isset( $raw['custom'] ) && is_array( $raw['custom'] ) ? $raw['custom'] : array() ) as $c ) {
			$rh = is_array( $c ) && isset( $c['hosts'] ) ? $c['hosts'] : array();
			foreach ( ( is_string( $rh ) ? preg_split( '/[\s,]+/', $rh ) : (array) $rh ) as $h ) {
				if ( is_string( $h ) && '' !== CMP_Sanitizer::normalize_host( $h ) && '' === CMP_Sanitizer::normalize_host( $h, $own ) ) {
					$own_host = true;
				}
			}
		}

		// In Advanced the GA/GTM "nu bloca" box is disabled (not posted): keep their stored exempt.
		$locked = CMP_Services::advanced_now() ? CMP_Services::MEASUREMENT_IDS : array();
		$clean  = CMP_Sanitizer::services( $raw, $ids, $cats, $can_script, $old, $lib_cats, $own, $lib_exempt, $locked );
		update_option( 'cmp_services', $clean );
		delete_transient( 'cmp_tracker_scan' );
		// The Cookie Policy page renders [cmp_cookie_table] live, so it is not
		// regenerated here (that would overwrite the client's own edits). The
		// explicit "Regenerează" in "Pagini legale" still does it.
		$args = array( 'page' => self::SLUG, 'updated' => 1 );
		if ( $script_dropped ) {
			$args['cmp_noscript'] = 1;
		}
		if ( $own_host ) {
			$args['cmp_ownhost'] = 1;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function rescan() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cmp_rescan_services' ) ) {
			wp_die( esc_html__( 'Acțiune neautorizată.', 'consent-manager-pro' ) );
		}
		CMP_Blocker::scan_homepage( true );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function reset_seen() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cmp_reset_seen' ) ) {
			wp_die( esc_html__( 'Acțiune neautorizată.', 'consent-manager-pro' ) );
		}
		$found = get_option( 'cmp_services_found', array() );
		update_option( 'cmp_services_history', CMP_Services::merge_seen( array(), is_array( $found ) ? $found : array() ), true );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'cmp_reset' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** The post-update flag is cleared for good. */
	private static function mark_seen() {
		if ( get_option( 'cmp_services_notice' ) ) {
			delete_option( 'cmp_services_notice' );
		}
		if ( ! get_option( 'cmp_services_seen' ) ) {
			update_option( 'cmp_services_seen', 1 );
		}
	}

	public static function dismiss() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cmp_dismiss_services_notice' ) ) {
			wp_die( esc_html__( 'Acțiune neautorizată.', 'consent-manager-pro' ) );
		}
		self::mark_seen();
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}

	/** One-time note after an update: services that now wait for consent. */
	public static function update_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! get_option( 'cmp_services_notice' ) ) {
			return;
		}
		if ( isset( $_GET['page'] ) && self::SLUG === $_GET['page'] ) {
			return;
		}
		$names = array();
		$scan  = get_transient( 'cmp_tracker_scan' ); // cached only: no HTTP request on every admin page.
		if ( is_array( $scan ) && isset( $scan['services'] ) ) {
			$found = array_merge( (array) $scan['services']['raw'], (array) $scan['services']['blocked'] );
			$active = CMP_Services::active();
			foreach ( CMP_Services::newly_blocked( $found, $active ) as $id ) {
				foreach ( $active as $s ) {
					if ( $s['id'] === $id ) {
						$names[] = $s['name'];
					}
				}
			}
		}
		$url     = admin_url( 'admin.php?page=' . self::SLUG );
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=cmp_dismiss_services_notice' ), 'cmp_dismiss_services_notice' );
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Consent Manager Pro:', 'consent-manager-pro' ) . '</strong> ';
		if ( $names ) {
			echo esc_html( sprintf( /* translators: %s: service names */ __( 'Am început să blocăm până la acord: %s —', 'consent-manager-pro' ), implode( ', ', $names ) ) );
		} else {
			echo esc_html__( 'Unele servicii de pe site așteaptă acum acordul vizitatorului —', 'consent-manager-pro' );
		}
		echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'verifică în „Servicii”', 'consent-manager-pro' ) . '</a>'
			. ' · <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Închide', 'consent-manager-pro' ) . '</a></p></div>';
	}
}
