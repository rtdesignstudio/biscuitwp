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
			$out = array( 'necessary' => __( 'Necesare', 'biscuitwp' ) ) + $out;
		}
		return $out;
	}

	/** State of a recognised plugin => label (RO source). Empty state: no label. */
	private static function plugin_state_label( $state ) {
		$labels = array(
			'bridged'   => __( 'legat', 'biscuitwp' ),
			'blocked'   => __( 'blocat în pagină', 'biscuitwp' ),
			'necessary' => __( 'necesar', 'biscuitwp' ),
			'exempt'    => __( 'excepție', 'biscuitwp' ),
		);
		return isset( $labels[ $state ] ) ? $labels[ $state ] : '';
	}

	private static function status_label( $svc ) {
		if ( CMP_Services::replaced( $svc ) ) {
			return __( 'decide serviciul tău personalizat', 'biscuitwp' );
		}
		if ( isset( $svc['category'] ) && 'necessary' === $svc['category'] ) {
			return __( 'neblocat (necesar)', 'biscuitwp' );
		}
		// Maps gate off: the loader runs before consent, as it always did (the setting, not an "excepție" the admin chose).
		if ( ! empty( $svc['gate_off'] ) && empty( $svc['admin_exempt'] ) ) {
			return __( 'se încarcă înainte de acord', 'biscuitwp' );
		}
		if ( ! empty( $svc['exempt'] ) ) {
			return __( 'excepție', 'biscuitwp' );
		}
		if ( ! empty( $svc['except'] ) ) {
			return __( 'blocat până la acord, în afară de ce decide serviciul tău personalizat', 'biscuitwp' );
		}
		return __( 'blocat până la acord', 'biscuitwp' );
	}

	/**
	 * One library service row with its controls (the Maps API loader also has its
	 * switch, the stored setting maps_gate).
	 */
	private static function library_row( $svc, array $cats ) {
		$id  = (string) $svc['id'];
		$cat = isset( $svc['category'] ) ? (string) $svc['category'] : 'marketing';
		$n   = 'cmp_services[overrides][' . $id . ']';
		// The admin's own "nu bloca": with the maps gate off the service is exempt through the setting, which the box must not show (saving would store it).
		$own = ! empty( $svc['gate_off'] ) ? ! empty( $svc['admin_exempt'] ) : ! empty( $svc['exempt'] );
		if ( ! isset( $cats[ $cat ] ) ) {
			$cats[ $cat ] = $cat;
		}
		?>
		<tr class="cmp-svc-row" data-cmp-service="<?php echo esc_attr( $id ); ?>">
			<td class="cmp-svc-name"><strong><?php echo esc_html( $svc['name'] ); ?></strong>
				<?php if ( ! empty( $svc['provider'] ) ) : ?><br><span class="description"><?php echo esc_html( $svc['provider'] ); ?></span><?php endif; ?>
				<?php foreach ( isset( $svc['except'] ) ? $svc['except'] : array() as $exc ) : ?>
					<p class="description"><?php echo esc_html( sprintf( /* translators: 1: domain or address, 2: name of the custom service */ __( 'Pentru %1$s decide serviciul tău personalizat „%2$s”.', 'biscuitwp' ), $exc['pattern'], $exc['name'] ) ); ?></p>
				<?php endforeach; ?>
				<?php if ( 'recaptcha' === $id ) : ?>
					<p class="description"><?php esc_html_e( 'Autoritățile din UE consideră că reCAPTCHA necesită acord. E lăsat neblocat ca să nu se strice formularele. Îl poți bloca dintr-un click.', 'biscuitwp' ); ?></p>
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
					<input type="hidden" name="<?php echo esc_attr( $n ); ?>[exempt]" value="<?php echo $own ? '1' : '0'; ?>">
				<?php elseif ( ! empty( $svc['mode_exempt'] ) ) : ?>
					<label><input type="checkbox" value="1" checked disabled> <?php esc_html_e( 'nu bloca (excepție)', 'biscuitwp' ); ?></label>
					<p class="description"><?php esc_html_e( 'În modul Advanced, Google Analytics și Tag Manager se încarcă mereu, cu Consent Mode pe «refuzat».', 'biscuitwp' ); ?></p>
					<p class="description"><?php echo esc_html( CMP_Services::consent_exempt( $svc ) ? __( 'Setarea ta: nu bloca (se aplică și în Strict).', 'biscuitwp' ) : __( 'Setarea ta: blocat până la acord (se aplică în Strict).', 'biscuitwp' ) ); ?></p>
				<?php else : ?>
					<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[exempt]" value="1" <?php checked( $own ); ?>> <?php esc_html_e( 'nu bloca (excepție)', 'biscuitwp' ); ?></label>
				<?php endif; ?>
				<?php if ( CMP_Services::GATE_ID === $id ) : // the stored setting, not gate_now(): with blocking off, saving must not turn it off ?>
					<?php $stored = get_option( 'cmp_settings', array() ); ?>
					<input type="hidden" name="cmp_services[maps_gate]" value="0">
					<p><label><input type="checkbox" name="cmp_services[maps_gate]" value="1" <?php checked( is_array( $stored ) && ! empty( $stored['maps_gate'] ) ); ?>> <?php esc_html_e( 'ține harta până la acord', 'biscuitwp' ); ?></label></p>
					<p class="description"><?php esc_html_e( 'Probează harta după pornire.', 'biscuitwp' ); ?></p>
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
				<strong><?php esc_html_e( 'Serviciu personalizat', 'biscuitwp' ); ?></strong>
				<button type="button" class="button-link button-link-delete" data-cmp-del-custom><?php esc_html_e( 'Șterge', 'biscuitwp' ); ?></button>
			</div>
			<?php if ( ! empty( $c['id'] ) ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $n ); ?>[id]" value="<?php echo esc_attr( $c['id'] ); ?>">
			<?php endif; ?>
			<p class="cmp-cat-row__names">
				<label><?php esc_html_e( 'Nume', 'biscuitwp' ); ?><br>
					<input type="text" name="<?php echo esc_attr( $n ); ?>[name]" value="<?php echo esc_attr( $name ); ?>"></label>
				<label><?php esc_html_e( 'Categorie', 'biscuitwp' ); ?><br>
					<select name="<?php echo esc_attr( $n ); ?>[category]"<?php echo $dis; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
						<?php foreach ( $cats as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $cat, $slug ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select></label>
			</p>
			<p>
				<label><?php esc_html_e( 'Domenii (unul pe rând)', 'biscuitwp' ); ?><br>
					<textarea class="large-text code" rows="3" name="<?php echo esc_attr( $n ); ?>[hosts]"<?php echo $dis; // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo esc_textarea( $hosts ); ?></textarea></label>
				<span class="description"><?php esc_html_e( 'Se blochează scripturile și iframe-urile (video, formulare, calendare) de pe aceste domenii.', 'biscuitwp' ); ?></span>
			</p>
			<div class="cmp-cookies" data-cmp-ck-prefix="<?php echo esc_attr( $n ); ?>[cookies]">
				<strong><?php esc_html_e( 'Cookie-uri', 'biscuitwp' ); ?></strong>
				<table>
					<thead><tr>
						<th><?php esc_html_e( 'Nume', 'biscuitwp' ); ?></th>
						<th><?php esc_html_e( 'Durată', 'biscuitwp' ); ?></th>
						<th><?php esc_html_e( 'Scop', 'biscuitwp' ); ?></th>
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
				<p><button type="button" class="button" data-cmp-add-svc-cookie><?php esc_html_e( '+ Adaugă cookie', 'biscuitwp' ); ?></button></p>
			</div>
			<?php if ( $can_script ) : ?>
				<p>
					<label><?php esc_html_e( 'Script (opțional, rulează doar după acord)', 'biscuitwp' ); ?><br>
						<textarea class="large-text code" rows="4" name="<?php echo esc_attr( $n ); ?>[script]"><?php echo esc_textarea( $script ); ?></textarea></label>
				</p>
			<?php elseif ( $locked ) : ?>
				<p class="description"><?php esc_html_e( 'Are script (doar un administrator cu drept de cod îl poate modifica)', 'biscuitwp' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Opening this screen counts as having seen the post-update note; the one that names services is shown here first.
		$note = get_option( 'cmp_services_notice' );
		$note = is_array( $note ) ? self::notice_names( $note ) : array();
		self::mark_seen();
		// Maps gate: the one-time note is shown here first, then it is cleared (shown once).
		$maps_note = self::maps_note_due();
		if ( $maps_note ) {
			delete_option( 'cmp_maps_notice' );
		}

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

		$known_rows = CMP_Known_Plugins::current(); // active plugins we recognise: shown only, never stored

		CMP_Scanner::maybe_run_inline(); // no working cron: this screen moves the scan on
		$scan     = CMP_Blocker::scan_homepage( false );
		$progress = CMP_Scanner::current_progress();
		$found    = array();
		if ( is_array( $scan ) && isset( $scan['services'] ) ) {
			$ids = isset( $scan['services']['found'] ) ? (array) $scan['services']['found'] : array_merge( (array) $scan['services']['raw'], (array) $scan['services']['blocked'] );
			foreach ( $active as $s ) {
				if ( in_array( $s['id'], $ids, true ) ) {
					$found[ $s['id'] ] = $s;
				}
			}
		}
		$unknown_rows = array();
		$ignored_rows = array();
		if ( is_array( $scan ) && isset( $scan['unknown_pages'] ) ) { // absent: a result from before the update, nothing to show yet
			$unknown_rows = self::unknown_list( $scan, $active );
			$ignored_rows = CMP_Alerts::ignored_list( CMP_Alerts::state() );
		}
		?>
		<div class="wrap cmp-admin">
			<h1><?php esc_html_e( 'BiscuitWP — Servicii', 'biscuitwp' ); ?> <?php echo CMP_Guide::link( 'services' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in link() ?></h1>
			<?php if ( $note ) : ?>
				<div class="notice notice-warning"><p><strong><?php esc_html_e( 'BiscuitWP:', 'biscuitwp' ); ?></strong> <?php echo esc_html( sprintf( /* translators: %s: service names */ __( 'Am început să blocăm până la acord: %s. Le vezi mai jos, în listă.', 'biscuitwp' ), implode( ', ', $note ) ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( $maps_note ) : ?>
				<div class="notice notice-warning"><p><strong><?php esc_html_e( 'BiscuitWP:', 'biscuitwp' ); ?></strong> <?php echo esc_html( self::maps_note_text() ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Servicii salvate.', 'biscuitwp' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['cmp_reset'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Lista a fost resetată.', 'biscuitwp' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['cmp_domain'] ) && 'ignored' === $_GET['cmp_domain'] ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Domeniul e ignorat: nu mai primești email despre el.', 'biscuitwp' ); ?></p></div>
			<?php elseif ( isset( $_GET['cmp_domain'] ) && 'restored' === $_GET['cmp_domain'] ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Domeniul nu mai e ignorat.', 'biscuitwp' ); ?></p></div>
			<?php elseif ( isset( $_GET['cmp_domain'] ) && 'refused' === $_GET['cmp_domain'] ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Domeniul nu mai e în lista de pe acest ecran, deci nu s-a schimbat nimic.', 'biscuitwp' ); ?></p></div>
			<?php elseif ( isset( $_GET['cmp_domain'] ) && 'busy' === $_GET['cmp_domain'] ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'O scanare termina chiar atunci și schimbarea nu s-a făcut. Apasă din nou.', 'biscuitwp' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['cmp_noscript'] ) ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Scriptul nu a fost salvat: contul tău nu are dreptul de a adăuga cod (unfiltered_html). Restul modificărilor au fost salvate.', 'biscuitwp' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['cmp_ownhost'] ) ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Domeniul site-ului tău nu poate fi un domeniu de serviciu personalizat (ar bloca scripturile site-ului). L-am scos din listă.', 'biscuitwp' ); ?></p></div>
			<?php endif; ?>
			<?php foreach ( CMP_Services::errors() as $err ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( sprintf( /* translators: %s: error text */ __( 'Biblioteca de servicii: %s', 'biscuitwp' ), $err ) ); ?></p></div>
			<?php endforeach; ?>
			<?php if ( class_exists( 'WooCommerce' ) && ! CMP_Consent_API::active() ) : ?>
				<div class="notice notice-info"><p>
					<?php esc_html_e( 'WooCommerce este activ, fără WP Consent API. Ai nevoie de el doar dacă folosești extensii WooCommerce care citesc acordul. Cu cache de pagină, legătura BiscuitWP e mai sigură pentru sursa comenzilor.', 'biscuitwp' ); ?>
					<?php if ( current_user_can( 'install_plugins' ) ) : ?>
						<a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=wp-consent-api&tab=search&type=term' ) ); ?>"><?php esc_html_e( 'Instalează WP Consent API', 'biscuitwp' ); ?></a>
					<?php endif; ?>
				</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cmp_save_services">
				<?php wp_nonce_field( 'cmp_save_services' ); ?>

				<h2>
					<?php esc_html_e( 'Găsite pe site', 'biscuitwp' ); ?>
					<?php if ( is_array( $scan ) && ! empty( $scan['time'] ) ) : ?>
						<?php $cmp_np = isset( $scan['pages'] ) ? (int) $scan['pages'] : 1; ?>
						<span class="description">(<?php echo esc_html( sprintf( /* translators: 1: number of pages, 2: time span like "12 minute" */ _n( '%1$d pagină, acum %2$s', '%1$d pagini, acum %2$s', $cmp_np, 'biscuitwp' ), $cmp_np, human_time_diff( (int) $scan['time'] ) ) ); ?>)</span>
					<?php endif; ?>
					<?php if ( null !== $progress ) : ?>
						<span class="description"><?php echo esc_html( CMP_Scanner::progress_text( $progress ) ); ?></span>
					<?php endif; ?>
					<button type="submit" class="button" form="cmp-rescan-form"><?php esc_html_e( 'Rescanează', 'biscuitwp' ); ?></button>
				</h2>
				<?php if ( null === $scan && null !== $progress ) : ?>
					<p class="description"><?php esc_html_e( 'Scanăm paginile site-ului în fundal. Reîncarcă pagina mai târziu.', 'biscuitwp' ); ?></p>
				<?php elseif ( null === $scan ) : ?>
					<p class="description"><?php esc_html_e( 'Paginile site-ului nu au putut fi citite. Încearcă „Rescanează”.', 'biscuitwp' ); ?></p>
				<?php elseif ( ! isset( $scan['services'] ) ) : ?>
					<p class="description"><?php esc_html_e( 'Apasă „Rescanează” ca să afli ce servicii încarcă site-ul.', 'biscuitwp' ); ?></p>
				<?php elseif ( ! $found ) : ?>
					<p class="description"><?php esc_html_e( 'Nu am găsit pe site servicii care să aștepte acordul.', 'biscuitwp' ); ?></p>
				<?php else : ?>
					<table class="widefat striped cmp-svc-table"><tbody>
						<?php foreach ( $found as $s ) : ?>
							<?php if ( isset( $s['source'] ) && 'custom' === $s['source'] ) : ?>
								<tr class="cmp-svc-row"><td class="cmp-svc-name"><strong><?php echo esc_html( $s['name'] ); ?></strong></td><td class="description"><?php esc_html_e( 'configurat mai jos', 'biscuitwp' ); ?></td><td></td><td class="cmp-svc-status"><?php echo esc_html( self::status_label( $s ) ); ?></td></tr>
							<?php else : ?>
								<?php self::library_row( $s, $cats ); ?>
							<?php endif; ?>
						<?php endforeach; ?>
					</tbody></table>
				<?php endif; ?>

				<?php if ( $unknown_rows || $ignored_rows ) : ?>
					<h3><?php esc_html_e( 'Domenii necunoscute', 'biscuitwp' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Scripturi sau iframe-uri de pe domenii care nu sunt ale site-ului și nu aparțin niciunui serviciu din listă. Ele se încarcă acum fără acord. „Adaugă ca serviciu” completează formularul de mai jos, dar nu salvează nimic până nu apeși „Salvează serviciile”.', 'biscuitwp' ); ?></p>
					<?php if ( $unknown_rows ) : ?>
						<table class="widefat striped cmp-svc-table cmp-unknown-hosts"><tbody>
							<?php foreach ( $unknown_rows as $u_host => $u_paths ) : ?>
								<tr class="cmp-svc-row">
									<td class="cmp-svc-name"><strong><?php echo esc_html( $u_host ); ?></strong></td>
									<td class="description"><?php echo esc_html( implode( ', ', $u_paths ) ); ?></td>
									<td>
										<?php if ( 'maps.googleapis.com' === $u_host ) : // the Maps API loader has its own switch: no form to fill ?>
												<button type="button" class="button" data-cmp-use-host="<?php echo esc_attr( $u_host ); ?>" data-cmp-use-hint="<?php echo esc_attr( sprintf( /* translators: %s: name of the service */ __( 'Pentru harta din temă folosește comutatorul de la serviciul „%s”, din lista de servicii.', 'biscuitwp' ), isset( $by_id[ CMP_Services::GATE_ID ] ) ? $by_id[ CMP_Services::GATE_ID ]['name'] : CMP_Services::GATE_ID ) ); ?>"><?php esc_html_e( 'Adaugă ca serviciu', 'biscuitwp' ); ?></button>
											<?php else : ?>
												<button type="button" class="button" data-cmp-use-host="<?php echo esc_attr( $u_host ); ?>"><?php esc_html_e( 'Adaugă ca serviciu', 'biscuitwp' ); ?></button>
											<?php endif; ?>
										<button type="submit" class="button-link" name="host" value="<?php echo esc_attr( $u_host ); ?>" form="cmp-ignore-form"><?php esc_html_e( 'Ignoră', 'biscuitwp' ); ?></button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody></table>
					<?php endif; ?>
					<?php if ( $ignored_rows ) : ?>
						<details class="cmp-unknown-ignored">
							<summary><?php echo esc_html( sprintf( /* translators: %d: number of ignored domains */ __( 'Domenii ignorate (%d)', 'biscuitwp' ), count( $ignored_rows ) ) ); ?></summary>
							<table class="widefat striped cmp-svc-table"><tbody>
								<?php foreach ( $ignored_rows as $i_host ) : ?>
									<tr class="cmp-svc-row">
										<td class="cmp-svc-name"><strong><?php echo esc_html( $i_host ); ?></strong></td>
										<td><button type="submit" class="button-link" name="host" value="<?php echo esc_attr( $i_host ); ?>" form="cmp-unignore-form"><?php esc_html_e( 'Nu mai ignora', 'biscuitwp' ); ?></button></td>
									</tr>
								<?php endforeach; ?>
							</tbody></table>
						</details>
					<?php endif; ?>
				<?php endif; ?>

				<?php if ( $known_rows ) : ?>
					<h3><?php esc_html_e( 'Pluginuri recunoscute', 'biscuitwp' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Pluginuri active pe care le cunoaștem. Categoria serviciilor lor se schimbă în lista de servicii.', 'biscuitwp' ); ?></p>
					<table class="widefat striped cmp-svc-table cmp-known-plugins"><tbody>
						<?php foreach ( $known_rows as $kp ) : ?>
							<tr class="cmp-svc-row">
								<td class="cmp-svc-name"><strong><?php echo esc_html( $kp['name'] ); ?></strong>
									<?php if ( '' !== $kp['note'] ) : ?><p class="description"><?php echo esc_html( $kp['note'] ); ?></p><?php endif; ?>
								</td>
								<td>
									<?php
									$kp_list = array();
									foreach ( $kp['services'] as $kp_s ) {
										$kp_list[] = $kp_s['name'] . ( isset( $cats[ $kp_s['category'] ] ) ? ' (' . $cats[ $kp_s['category'] ] . ')' : '' );
									}
									echo esc_html( implode( ', ', $kp_list ) );
									?>
								</td>
								<td class="cmp-svc-status"><?php echo esc_html( self::plugin_state_label( $kp['state'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody></table>
				<?php endif; ?>

				<details class="cmp-svc-library">
					<summary><h2 style="display:inline"><?php echo esc_html( sprintf( /* translators: %d: number of services */ __( 'Toată biblioteca (%d)', 'biscuitwp' ), count( $library ) ) ); ?></h2></summary>
					<table class="widefat striped cmp-svc-table"><tbody>
						<?php foreach ( $library as $s ) : ?>
							<?php if ( isset( $found[ $s['id'] ] ) ) : ?>
								<tr class="cmp-svc-row"><td class="cmp-svc-name"><strong><?php echo esc_html( $s['name'] ); ?></strong></td><td colspan="3" class="description"><?php esc_html_e( 'găsit pe site, se setează mai sus', 'biscuitwp' ); ?></td></tr>
							<?php else : ?>
								<?php self::library_row( $s, $cats ); ?>
							<?php endif; ?>
						<?php endforeach; ?>
					</tbody></table>
				</details>

				<h2><?php esc_html_e( 'Servicii personalizate', 'biscuitwp' ); ?></h2>
				<div data-cmp-customs>
					<?php foreach ( $custom as $i => $c ) : ?>
						<?php self::custom_row( $c, $cats, $can_script, 'c' . (int) $i ); ?>
					<?php endforeach; ?>
				</div>
				<p><button type="button" class="button" data-cmp-add-custom><?php esc_html_e( '+ Adaugă serviciu', 'biscuitwp' ); ?></button></p>

				<p class="description"><span class="dashicons dashicons-info"></span> <?php esc_html_e( 'Blocăm ce apare în HTML-ul paginii. Ce adaugă Google Tag Manager sau alt JavaScript ulterior trebuie controlat din GTM (Consent Mode).', 'biscuitwp' ); ?></p>

				<?php submit_button( __( 'Salvează serviciile', 'biscuitwp' ) ); ?>
			</form>

			<form id="cmp-ignore-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cmp_ignore_domain">
				<?php wp_nonce_field( 'cmp_ignore_domain' ); ?>
			</form>
			<form id="cmp-unignore-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cmp_unignore_domain">
				<?php wp_nonce_field( 'cmp_unignore_domain' ); ?>
			</form>

			<form id="cmp-rescan-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cmp_rescan_services">
				<?php wp_nonce_field( 'cmp_rescan_services' ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Lista serviciilor văzute va conține doar ce a găsit ultima scanare. Continui?', 'biscuitwp' ) ); ?>');">
				<input type="hidden" name="action" value="cmp_reset_seen">
				<?php wp_nonce_field( 'cmp_reset_seen' ); ?>
				<p><button type="submit" class="button"><?php esc_html_e( 'Resetează lista serviciilor văzute', 'biscuitwp' ); ?></button>
				<span class="description"><?php esc_html_e( 'Serviciile găsite vreodată pe site rămân în listă (pentru re-consimțământ) până o resetezi.', 'biscuitwp' ); ?></span></p>
			</form>

			<script type="text/template" id="cmp-custom-template"><?php self::custom_row( array(), $cats, $can_script, '__i__' ); ?></script>
		</div>
		<?php
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cmp_save_services' ) ) {
			wp_die( esc_html__( 'Acțiune neautorizată.', 'biscuitwp' ) );
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
		// Maps gate: the switch of the library row, posted with the form (a hidden 0, then the checkbox).
		if ( array_key_exists( 'maps_gate', $raw ) ) {
			$settings = CMP_Consent::get_settings();
			$settings = is_array( $settings ) ? $settings : array();
			$gate     = CMP_Sanitizer::maps_gate( $raw, $settings );
			if ( ! array_key_exists( 'maps_gate', $settings ) || $gate !== (bool) $settings['maps_gate'] ) {
				$settings['maps_gate'] = $gate;
				update_option( 'cmp_settings', $settings );
			}
			if ( $gate ) {
				delete_option( 'cmp_maps_notice' ); // decided: the note has nothing left to say
			}
		}
		CMP_Scanner::mark_saved();
		CMP_Scanner::enqueue_all(); // the next result follows the new services; the last one stays meanwhile
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

	/** The unknown domains to show: host => paths from the last scan, minus the ignored and what a service covers now. */
	private static function unknown_list( $scan, array $active ) {
		$home = home_url( '/' );
		return CMP_Alerts::visible(
			CMP_Alerts::by_host( is_array( $scan ) && isset( $scan['unknown_pages'] ) ? $scan['unknown_pages'] : array() ),
			CMP_Alerts::state(),
			function ( $host ) use ( $active, $home ) {
				return CMP_Scanner::host_known( $host, $active, $home );
			}
		);
	}

	/** admin-post: stop telling about (and listing) a domain. Only a host the screen listed. */
	public static function ignore_domain() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cmp_ignore_domain' ) ) {
			wp_die( esc_html__( 'Acțiune neautorizată.', 'biscuitwp' ) );
		}
		$host = isset( $_POST['host'] ) && is_string( $_POST['host'] ) ? strtolower( trim( wp_unslash( $_POST['host'] ) ) ) : '';
		$agg  = get_option( CMP_Scanner::AGGREGATE_OPTION, array() );
		$done = 'refused';
		if ( CMP_Alerts::may_act( $host, self::unknown_list( $agg, CMP_Services::active() ) ) ) {
			$done = 'busy' === CMP_Alerts::change_wp( 'ignore', $host ) ? 'busy' : 'ignored';
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'cmp_domain' => $done ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** admin-post: bring an ignored domain back onto the list (it is not announced by email). */
	public static function unignore_domain() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cmp_unignore_domain' ) ) {
			wp_die( esc_html__( 'Acțiune neautorizată.', 'biscuitwp' ) );
		}
		$host = isset( $_POST['host'] ) && is_string( $_POST['host'] ) ? strtolower( trim( wp_unslash( $_POST['host'] ) ) ) : '';
		$done = 'refused';
		if ( in_array( $host, CMP_Alerts::ignored_list( CMP_Alerts::state() ), true ) ) {
			$done = 'busy' === CMP_Alerts::change_wp( 'unignore', $host ) ? 'busy' : 'restored';
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'cmp_domain' => $done ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function rescan() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cmp_rescan_services' ) ) {
			wp_die( esc_html__( 'Acțiune neautorizată.', 'biscuitwp' ) );
		}
		CMP_Blocker::scan_homepage( true ); // queues every page; the screen shows "Scanare în curs (X/Y)"
		// The "Verificare" screen posts here too and gets back to itself.
		$back = isset( $_POST['cmp_back'] ) && 'cmp-check' === $_POST['cmp_back'] ? 'cmp-check' : self::SLUG;
		wp_safe_redirect( add_query_arg( array( 'page' => $back ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function reset_seen() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cmp_reset_seen' ) ) {
			wp_die( esc_html__( 'Acțiune neautorizată.', 'biscuitwp' ) );
		}
		$found = get_option( 'cmp_services_found', array() );
		update_option( 'cmp_services_history', CMP_Services::merge_seen( array(), is_array( $found ) ? $found : array() ), true );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'cmp_reset' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** The names the post-update note lists now (CMP_Services::notice_ids()), from the cached scan only: no HTTP request on an admin page. */
	private static function notice_names( $notice ) {
		$scan = get_transient( 'cmp_tracker_scan' );
		if ( ! is_array( $scan ) || ! isset( $scan['services'] ) ) {
			return array();
		}
		$active = CMP_Services::active();
		$found  = array_merge( (array) $scan['services']['raw'], (array) $scan['services']['blocked'] );
		$s      = CMP_Consent::get_settings();
		$ids    = CMP_Services::notice_ids( $notice, $found, $active, CMP_Blocker::blocks_trackers( is_array( $s ) ? $s : array() ) );
		$names  = array();
		foreach ( $active as $svc ) {
			if ( in_array( $svc['id'], $ids, true ) ) {
				$names[] = $svc['name'];
			}
		}
		return $names;
	}

	/** The post-update flag is cleared for good. A note still waiting for its service to be found stays, unless it is closed ($force). */
	private static function mark_seen( $force = false ) {
		$notice = get_option( 'cmp_services_notice' );
		if ( $notice && ( $force || ! is_array( $notice ) || self::notice_names( $notice ) ) ) {
			delete_option( 'cmp_services_notice' );
		}
		if ( ! get_option( 'cmp_services_seen' ) ) {
			update_option( 'cmp_services_seen', 1 );
		}
	}

	public static function dismiss() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cmp_dismiss_services_notice' ) ) {
			wp_die( esc_html__( 'Acțiune neautorizată.', 'biscuitwp' ) );
		}
		self::mark_seen( true );
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}

	/** The one-time maps gate note is due now (flag, gate off, the cached scan found the loader). From the cached scan only: no HTTP request on an admin page. */
	private static function maps_note_due() {
		$flag = current_user_can( 'manage_options' ) ? get_option( 'cmp_maps_notice' ) : false;
		if ( ! $flag ) {
			return false;
		}
		$scan = get_transient( 'cmp_tracker_scan' );
		if ( ! is_array( $scan ) || ! isset( $scan['services'] ) ) {
			return false;
		}
		$found = isset( $scan['services']['found'] ) ? (array) $scan['services']['found'] : array_merge( (array) $scan['services']['raw'], (array) $scan['services']['blocked'] );
		$s     = CMP_Consent::get_settings();
		return CMP_Services::maps_notice_shown( $flag, is_array( $s ) ? $s : array(), $found );
	}

	/** What the maps gate note says (RO source; no em dash). */
	private static function maps_note_text() {
		return __( 'Harta Google din temă se încarcă înainte de acordul vizitatorului, ca până acum. O poți ține până la acord din „Servicii”, la rândul „Google Maps JavaScript API”.', 'biscuitwp' );
	}

	/** One-time note on existing installs: the map of the theme loads before consent until the gate is turned on. */
	public static function maps_notice() {
		if ( isset( $_GET['page'] ) && self::SLUG === $_GET['page'] ) {
			return; // the Servicii screen prints it itself, then clears it
		}
		if ( ! self::maps_note_due() ) {
			return;
		}
		$url     = admin_url( 'admin.php?page=' . self::SLUG );
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=cmp_dismiss_maps_notice' ), 'cmp_dismiss_maps_notice' );
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'BiscuitWP:', 'biscuitwp' ) . '</strong> ' . esc_html( self::maps_note_text() )
			. ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Deschide „Servicii”', 'biscuitwp' ) . '</a>'
			. ' · <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Închide', 'biscuitwp' ) . '</a></p></div>';
	}

	/** admin-post: the maps gate note is closed for good. */
	public static function dismiss_maps() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cmp_dismiss_maps_notice' ) ) {
			wp_die( esc_html__( 'Acțiune neautorizată.', 'biscuitwp' ) );
		}
		delete_option( 'cmp_maps_notice' );
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}

	/** One-time note after an update: services that now wait for consent. */
	public static function update_notice() {
		$notice = current_user_can( 'manage_options' ) ? get_option( 'cmp_services_notice' ) : false;
		if ( ! $notice ) {
			return;
		}
		if ( isset( $_GET['page'] ) && self::SLUG === $_GET['page'] ) {
			return;
		}
		$names = self::notice_names( $notice );
		if ( is_array( $notice ) && ! $names ) {
			return; // what the update started blocking is not found on this site (yet)
		}
		$url     = admin_url( 'admin.php?page=' . self::SLUG );
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=cmp_dismiss_services_notice' ), 'cmp_dismiss_services_notice' );
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'BiscuitWP:', 'biscuitwp' ) . '</strong> ';
		if ( $names ) {
			echo esc_html( sprintf( /* translators: %s: service names */ __( 'Am început să blocăm până la acord: %s —', 'biscuitwp' ), implode( ', ', $names ) ) );
		} else {
			echo esc_html__( 'Unele servicii de pe site așteaptă acum acordul vizitatorului —', 'biscuitwp' );
		}
		echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'verifică în „Servicii”', 'biscuitwp' ) . '</a>'
			. ' · <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Închide', 'biscuitwp' ) . '</a></p></div>';
	}
}
