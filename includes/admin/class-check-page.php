<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Verificare" screen: the page list (with manual additions and exclusions),
 * the server scan per page, and the in-browser before/after consent check
 * (assets/js/check.js loads each page in a hidden frame in check mode).
 */
class CMP_Check_Page {

	const SLUG = 'cmp-check';

	/** Max size of the raw report check.js posts (bytes). */
	const MAX_REPORT_BYTES = 1048576;

	/** The mode for classify(): light | advanced (GA4/GTM with Consent Mode denied) | pro. */
	public static function mode() {
		$s = CMP_Consent::get_settings();
		$s = is_array( $s ) ? $s : array();
		if ( isset( $s['mode'] ) && 'light' === $s['mode'] ) {
			return 'light';
		}
		return CMP_Services::measurement_active( $s ) ? 'advanced' : 'pro';
	}

	/** Pro with script blocking off: services load without consent. */
	private static function blocking_off() {
		$s = CMP_Consent::get_settings();
		$s = is_array( $s ) ? $s : array();
		return 'light' !== self::mode() && empty( $s['block_scripts'] );
	}

	/** Readable source of a script or resource (array( kind, slug ) from CMP_Scanner::source_for). */
	private static function source_label( $src ) {
		static $plugins = null;
		$kind = is_array( $src ) && isset( $src['kind'] ) ? (string) $src['kind'] : '';
		$slug = is_array( $src ) && isset( $src['slug'] ) ? (string) $src['slug'] : '';
		switch ( $kind ) {
			case 'plugin':
				if ( null === $plugins ) {
					if ( ! function_exists( 'get_plugins' ) ) {
						require_once ABSPATH . 'wp-admin/includes/plugin.php';
					}
					$plugins = array();
					foreach ( get_plugins() as $file => $data ) {
						$dir = strtok( (string) $file, '/' );
						if ( ! isset( $plugins[ $dir ] ) && ! empty( $data['Name'] ) ) {
							$plugins[ $dir ] = (string) $data['Name'];
						}
					}
				}
				/* translators: %s: plugin name */
				return sprintf( __( 'plugin: %s', 'consent-manager-pro' ), isset( $plugins[ $slug ] ) ? $plugins[ $slug ] : $slug );
			case 'theme':
				$t = wp_get_theme( $slug );
				/* translators: %s: theme name */
				return sprintf( __( 'temă: %s', 'consent-manager-pro' ), $t->exists() ? $t->get( 'Name' ) : $slug );
			case 'core':
				return __( 'WordPress', 'consent-manager-pro' );
			case 'site':
				return __( 'fișier de pe site (mu-plugins, uploads sau alt folder din afara pluginurilor și temelor)', 'consent-manager-pro' );
			case 'external':
				/* translators: %s: host */
				return sprintf( __( 'extern: %s', 'consent-manager-pro' ), $slug );
			case 'inline':
				return __( 'cod în pagină (temă, plugin sau conținut)', 'consent-manager-pro' );
		}
		return '';
	}

	/** Comma-separated source labels. */
	private static function sources_text( $srcs ) {
		$out = array();
		foreach ( (array) $srcs as $src ) {
			$l = self::source_label( $src );
			if ( '' !== $l && ! in_array( $l, $out, true ) ) {
				$out[] = $l;
			}
		}
		return implode( '; ', $out );
	}

	/** id => name of the active services. */
	private static function names() {
		$out = array();
		foreach ( CMP_Services::active() as $s ) {
			if ( is_array( $s ) && ! empty( $s['id'] ) ) {
				$out[ (string) $s['id'] ] = isset( $s['name'] ) && '' !== $s['name'] ? (string) $s['name'] : (string) $s['id'];
			}
		}
		return $out;
	}

	/** Translatable names of the IP-leaking resource types (CMP_Scanner::IP_LABELS stays pure). */
	private static function ip_labels() {
		return array(
			'google_fonts' => __( 'Google Fonts', 'consent-manager-pro' ),
			'adobe_fonts'  => __( 'Adobe Fonts', 'consent-manager-pro' ),
			'font_awesome' => __( 'Font Awesome', 'consent-manager-pro' ),
			'cdn'          => __( 'CDN', 'consent-manager-pro' ),
			'gravatar'     => __( 'Gravatar', 'consent-manager-pro' ),
			'emoji'        => __( 'emoji WordPress', 'consent-manager-pro' ),
		);
	}

	private static function status_text( $status ) {
		$map = array(
			'red'    => __( 'roșu', 'consent-manager-pro' ),
			'yellow' => __( 'galben', 'consent-manager-pro' ),
			'green'  => __( 'verde', 'consent-manager-pro' ),
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : __( 'nu s-a putut verifica', 'consent-manager-pro' );
	}

	private static function reason_text( $reason ) {
		if ( 'nobanner' === $reason ) {
			return __( 'nu s-a putut verifica (bannerul lipsește pe pagină)', 'consent-manager-pro' );
		}
		if ( 'origin' === $reason ) {
			return __( 'nu s-a putut verifica (altă origine decât adminul: subdomeniu, www sau http/https)', 'consent-manager-pro' );
		}
		if ( 'nodata' === $reason ) {
			return __( 'nu s-a putut verifica (pagina nu a trimis date)', 'consent-manager-pro' );
		}
		return __( 'nu s-a putut verifica (pagina nu a răspuns în 20 s sau nu permite încărcarea într-un cadru)', 'consent-manager-pro' );
	}

	/** One classify() item as text. $after: listed after "Acceptă tot" (informational). */
	private static function item_text( array $it, $after, array $agg_sources ) {
		list( $level, $label, $value, $source ) = array_pad( $it, 4, '' );
		$value = (string) $value;
		$ipl   = self::ip_labels();
		if ( 'yellow' === $level && isset( $ipl[ $source ] ) ) {
			$label = $ipl[ $source ];
		}
		if ( '' === $label && 'preexisting' === $level ) {
			/* translators: %s: cookie name */
			$text = sprintf( __( 'cookie: %s', 'consent-manager-pro' ), $value );
		} elseif ( '' === $label ) {
			$text = 'red' === $level
				/* translators: %s: host */
				? sprintf( __( 'domeniu terț necunoscut: %s', 'consent-manager-pro' ), $value )
				/* translators: %s: cookie name */
				: sprintf( __( 'cookie necunoscut, verifică sursa: %s', 'consent-manager-pro' ), $value );
		} else {
			$text = $label . ' — ' . $value;
		}
		if ( ! $after ) {
			$notes = array(
				'red'       => __( 'încărcat înainte de acord', 'consent-manager-pro' ),
				'yellow'    => '' === $label ? '' : __( 'trimite IP-ul vizitatorului', 'consent-manager-pro' ),
				'exempt'    => __( 'excepție, se încarcă fără acord', 'consent-manager-pro' ),
				'necessary' => __( 'necesar', 'consent-manager-pro' ),
				'advanced'    => __( 'Advanced: se încarcă cu Consent Mode „refuzat”', 'consent-manager-pro' ),
				'consentmode' => __( 'cookie pus înainte de acord: verifică Consent Mode', 'consent-manager-pro' ),
				'preexisting' => __( 'era deja în browserul tău înainte de verificare', 'consent-manager-pro' ),
			);
			if ( ! empty( $notes[ $level ] ) ) {
				$text .= ' (' . $notes[ $level ] . ')';
			}
		}
		if ( '' !== $source && isset( $agg_sources[ $source ] ) ) {
			$st = self::sources_text( $agg_sources[ $source ] );
			if ( '' !== $st ) {
				/* translators: %s: where the script comes from */
				$text .= ' — ' . sprintf( __( 'sursa: %s', 'consent-manager-pro' ), $st );
			}
		}
		return $text;
	}

	private static function badge( $status ) {
		$cls = in_array( $status, array( 'red', 'yellow', 'green' ), true ) ? $status : 'none';
		return '<span class="cmp-check-badge cmp-check-badge--' . esc_attr( $cls ) . '">' . esc_html( self::status_text( $status ) ) . '</span>';
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		CMP_Scanner::maybe_run_inline(); // no working cron: this screen moves the scan on

		$mode     = self::mode();
		$manual   = CMP_Scanner::manual_urls();
		$list     = CMP_Scanner::current_list();
		$progress = CMP_Scanner::current_progress();
		$agg      = get_option( CMP_Scanner::AGGREGATE_OPTION, false );
		$agg      = is_array( $agg ) ? $agg : null;
		$pages    = get_option( CMP_Scanner::PAGES_OPTION, array() );
		$pages    = is_array( $pages ) ? $pages : array();
		$report   = get_option( CMP_Scanner::CHECK_OPTION, array() );
		$report   = is_array( $report ) ? $report : array();
		$names    = self::names();
		$agg_src  = $agg && isset( $agg['sources'] ) && is_array( $agg['sources'] ) ? $agg['sources'] : array();
		$settings = CMP_Consent::get_settings();
		$fonts_on = is_array( $settings ) && ! empty( $settings['localize_fonts'] );
		$has_gf   = false;
		foreach ( ( $agg && isset( $agg['resources'] ) ? (array) $agg['resources'] : array() ) as $r ) {
			if ( is_array( $r ) && isset( $r['type'] ) && 'google_fonts' === $r['type'] ) {
				$has_gf = true;
			}
		}
		$post_url = admin_url( 'admin-post.php' );
		$cmp_ipl  = self::ip_labels();
		?>
		<div class="wrap cmp-admin cmp-check">
			<h1><?php esc_html_e( 'Consent Manager — Verificare', 'consent-manager-pro' ); ?></h1>
			<?php if ( isset( $_GET['cmp_urls'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Lista de pagini a fost salvată. Scanarea pornește din nou.', 'consent-manager-pro' ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! empty( $_GET['cmp_rejected'] ) ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php echo esc_html( sprintf( /* translators: %d: number of addresses */ __( 'Am respins %d adrese: doar paginile de pe domeniul site-ului pot fi verificate.', 'consent-manager-pro' ), (int) $_GET['cmp_rejected'] ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['cmp_fonts'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( /* translators: %d — count */ __( 'Localizate %d fonturi.', 'consent-manager-pro' ), (int) $_GET['cmp_fonts'] ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['cmp_checked'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Verificarea în browser s-a încheiat. Raportul e mai jos.', 'consent-manager-pro' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Pagini verificate', 'consent-manager-pro' ); ?></h2>
			<p class="description"><?php echo esc_html( sprintf( /* translators: %d: max pages */ __( 'Cel mult %d pagini de pe domeniul site-ului: pagina principală, paginile legale, ultima pagină publicată de fiecare tip, adresele tale și sitemap-ul.', 'consent-manager-pro' ), CMP_Scanner::MAX_PAGES ) ); ?></p>
			<?php if ( $list ) : ?>
				<ol class="cmp-check-list">
					<?php foreach ( $list as $u ) : ?>
						<li><a href="<?php echo esc_url( $u ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $u ); ?></a></li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="cmp-check-urls">
				<input type="hidden" name="action" value="cmp_scan_urls">
				<?php wp_nonce_field( 'cmp_scan_urls' ); ?>
				<p class="cmp-cat-row__names">
					<label><?php esc_html_e( 'Adaugă pagini (una pe rând)', 'consent-manager-pro' ); ?><br>
						<textarea class="large-text code" rows="4" name="cmp_scan_urls[add]"><?php echo esc_textarea( implode( "\n", $manual['add'] ) ); ?></textarea></label>
					<label><?php esc_html_e( 'Exclude pagini (una pe rând)', 'consent-manager-pro' ); ?><br>
						<textarea class="large-text code" rows="4" name="cmp_scan_urls[exclude]"><?php echo esc_textarea( implode( "\n", $manual['exclude'] ) ); ?></textarea></label>
				</p>
				<p class="description"><?php esc_html_e( 'Doar adrese de pe domeniul site-ului (cu sau fără www, plus subdomeniile). Poți scrie și doar calea, de exemplu /contact/.', 'consent-manager-pro' ); ?></p>
				<?php submit_button( __( 'Salvează lista', 'consent-manager-pro' ), 'secondary' ); ?>
			</form>

			<h2>
				<?php esc_html_e( 'Scanarea pe server', 'consent-manager-pro' ); ?>
				<?php if ( null !== $progress ) : ?>
					<span class="description"><?php echo esc_html( CMP_Scanner::progress_text( $progress ) ); ?></span>
				<?php elseif ( $agg && ! empty( $agg['time'] ) ) : ?>
					<?php $cmp_np = isset( $agg['pages'] ) ? (int) $agg['pages'] : 0; ?>
					<span class="description">(<?php echo esc_html( sprintf( /* translators: 1: number of pages, 2: time span like "12 minute" */ _n( '%1$d pagină, acum %2$s', '%1$d pagini, acum %2$s', $cmp_np, 'consent-manager-pro' ), $cmp_np, human_time_diff( (int) $agg['time'] ) ) ); ?>)</span>
				<?php endif; ?>
				<button type="submit" class="button" form="cmp-check-rescan"><?php esc_html_e( 'Rescanează', 'consent-manager-pro' ); ?></button>
			</h2>
			<form id="cmp-check-rescan" method="post" action="<?php echo esc_url( $post_url ); ?>">
				<input type="hidden" name="action" value="cmp_rescan_services">
				<input type="hidden" name="cmp_back" value="<?php echo esc_attr( self::SLUG ); ?>">
				<?php wp_nonce_field( 'cmp_rescan_services' ); ?>
			</form>
			<p class="description"><?php esc_html_e( 'Serverul citește HTML-ul paginilor, ca un vizitator fără cookie-uri. Vede ce e în pagină, nu ce adaugă JavaScript-ul ulterior.', 'consent-manager-pro' ); ?></p>
			<?php if ( ! $pages ) : ?>
				<p><?php echo null !== $progress ? esc_html__( 'Scanăm paginile site-ului în fundal. Reîncarcă pagina mai târziu.', 'consent-manager-pro' ) : esc_html__( 'Nicio pagină scanată încă. Apasă „Rescanează”.', 'consent-manager-pro' ); ?></p>
			<?php else : ?>
				<table class="widefat striped cmp-check-table">
					<thead><tr>
						<th><?php esc_html_e( 'Pagina', 'consent-manager-pro' ); ?></th>
						<th><?php esc_html_e( 'Servicii și sursa lor', 'consent-manager-pro' ); ?></th>
						<th><?php esc_html_e( 'Resurse care trimit IP-ul', 'consent-manager-pro' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $pages as $u => $p ) : ?>
						<?php if ( ! is_array( $p ) ) { continue; } ?>
						<tr>
							<td class="cmp-check-url"><?php echo esc_html( isset( $p['url'] ) ? $p['url'] : $u ); ?>
								<?php if ( isset( $p['status'] ) && 'ok' !== $p['status'] ) : ?>
									<br><span class="cmp-check-badge cmp-check-badge--none"><?php echo esc_html( sprintf( /* translators: %s: HTTP code or "error" */ __( 'nu s-a putut citi (%s)', 'consent-manager-pro' ), $p['status'] ) ); ?></span>
								<?php elseif ( ! empty( $p['stale'] ) ) : ?>
									<br><span class="description"><?php esc_html_e( 'ultima citire a eșuat; se arată rezultatul anterior', 'consent-manager-pro' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php
								$cmp_found = isset( $p['services']['found'] ) ? (array) $p['services']['found'] : array();
								$cmp_raw   = isset( $p['services']['raw'] ) ? (array) $p['services']['raw'] : array();
								if ( ! $cmp_found ) {
									echo '<span class="description">' . esc_html__( 'niciun serviciu', 'consent-manager-pro' ) . '</span>';
								}
								echo '<ul>';
								foreach ( $cmp_found as $cmp_id ) {
									$cmp_id  = (string) $cmp_id;
									$cmp_st  = in_array( $cmp_id, $cmp_raw, true ) ? __( 'neblocat', 'consent-manager-pro' ) : __( 'blocat până la acord', 'consent-manager-pro' );
									$cmp_srs = isset( $p['sources'][ $cmp_id ] ) ? self::sources_text( $p['sources'][ $cmp_id ] ) : '';
									echo '<li><strong>' . esc_html( isset( $names[ $cmp_id ] ) ? $names[ $cmp_id ] : $cmp_id ) . '</strong> — ' . esc_html( $cmp_st )
										. ( '' !== $cmp_srs ? '<br><span class="description">' . esc_html( $cmp_srs ) . '</span>' : '' ) . '</li>';
								}
								echo '</ul>';
								?>
							</td>
							<td>
								<?php
								$cmp_res = isset( $p['resources'] ) ? (array) $p['resources'] : array();
								if ( ! $cmp_res ) {
									echo '<span class="description">' . esc_html__( 'niciuna', 'consent-manager-pro' ) . '</span>';
								}
								echo '<ul>';
								foreach ( $cmp_res as $r ) {
									if ( ! is_array( $r ) || ! isset( $r['type'], $r['host'] ) ) {
										continue;
									}
									$cmp_lbl = isset( $cmp_ipl[ $r['type'] ] ) ? $cmp_ipl[ $r['type'] ] : (string) $r['type'];
									$cmp_rs  = isset( $r['source'] ) && is_array( $r['source'] ) ? $r['source'] : array();
									// An external source equal to the resource's own host says nothing new.
									$cmp_rsl = isset( $cmp_rs['kind'], $cmp_rs['slug'] ) && 'external' === $cmp_rs['kind'] && $cmp_rs['slug'] === $r['host'] ? '' : self::source_label( $cmp_rs );
									echo '<li>' . esc_html( $cmp_lbl . ' — ' . $r['host'] ) . ( '' !== $cmp_rsl ? '<br><span class="description">' . esc_html( $cmp_rsl ) . '</span>' : '' ) . '</li>';
								}
								echo '</ul>';
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<?php if ( $has_gf ) : ?>
				<div class="cmp-check-fonts">
					<p><?php esc_html_e( 'Google Fonts trimite IP-ul vizitatorului la Google. Fonturile se pot servi de pe site, adunate din toate paginile scanate.', 'consent-manager-pro' ); ?></p>
					<?php if ( $fonts_on ) : ?>
						<form method="post" action="<?php echo esc_url( $post_url ); ?>">
							<input type="hidden" name="action" value="cmp_localize_fonts">
							<input type="hidden" name="cmp_back" value="<?php echo esc_attr( self::SLUG ); ?>">
							<?php wp_nonce_field( 'cmp_localize_fonts' ); ?>
							<button type="submit" class="button"><?php esc_html_e( 'Localizează', 'consent-manager-pro' ); ?></button>
						</form>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Pornește întâi „Localizare Google Fonts” în Setări (doar în modul Pro), apoi revino aici.', 'consent-manager-pro' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . CMP_Admin::SLUG ) ); ?>"><?php esc_html_e( 'Setări', 'consent-manager-pro' ); ?></a></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Adobe Fonts, Font Awesome și CDN-urile (jsDelivr, unpkg, cdnjs) trimit și ele IP-ul vizitatorului la un terț. Le poți găzdui pe site ca să nu mai fie nevoie de ele.', 'consent-manager-pro' ); ?></p>

			<h2><?php esc_html_e( 'Verificare în browser', 'consent-manager-pro' ); ?></h2>
			<?php if ( 'light' === $mode ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'Modul Light nu cere acord: verificarea măsoară doar ce se încarcă înainte de acord.', 'consent-manager-pro' ); ?></p></div>
			<?php elseif ( self::blocking_off() ) : ?>
				<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Blocarea scripturilor e oprită: serviciile se încarcă fără acord', 'consent-manager-pro' ); ?></strong></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Încarcă pe rând, invizibil, paginile de mai sus și notează ce se încarcă înainte de acord și ce apare în plus după un „Acceptă tot” simulat.', 'consent-manager-pro' ); ?></p>
			<div class="cmp-check-limits">
				<strong><?php esc_html_e( 'Limitele verificării', 'consent-manager-pro' ); ?></strong>
				<ul>
					<li><?php esc_html_e( 'Rulează în browserul tău, ca administrator logat. Pluginurile care schimbă pagina pentru utilizatorii logați pot da alt rezultat decât pentru vizitatori.', 'consent-manager-pro' ); ?></li>
					<li><?php esc_html_e( 'Paginile care nu permit încărcarea într-un cadru (X-Frame-Options sau CSP frame-ancestors) nu pot fi verificate: rândul lor apare „nu s-a putut verifica”, nu verde.', 'consent-manager-pro' ); ?></li>
					<li><?php esc_html_e( 'Cookie-urile puse de terți pe domeniile lor nu pot fi citite; se văd doar domeniile contactate.', 'consent-manager-pro' ); ?></li>
					<li><?php esc_html_e( 'Cookie-urile care erau deja în browserul tău înainte de verificare (de la un acord real sau de la o verificare anterioară) sunt arătate separat și nu schimbă rezultatul: pentru ele contează domeniile contactate. Pentru o verificare completă a cookie-urilor, folosește o fereastră privată în care te autentifici.', 'consent-manager-pro' ); ?></li>
					<li><?php esc_html_e( 'Se pot verifica doar paginile cu aceeași origine ca adminul (același www și http/https). Paginile de pe subdomenii apar „nu s-a putut verifica”.', 'consent-manager-pro' ); ?></li>
					<li><?php esc_html_e( 'Ce apare doar după interacțiune (clic, scroll) nu e prins.', 'consent-manager-pro' ); ?></li>
					<?php if ( 'light' !== $mode ) : ?>
						<li><?php esc_html_e( '„Acceptă tot” se apasă în browserul tău. Acordul tău real nu se schimbă, dar cookie-urile puse de servicii (de exemplu _ga) rămân în browser după verificare, chiar dacă alegerea ta e „refuz”.', 'consent-manager-pro' ); ?></li>
						<li><?php esc_html_e( 'După acord, fiecare pagină verificată poate apărea ca o vizită în statisticile tale (Google Analytics și altele).', 'consent-manager-pro' ); ?></li>
					<?php endif; ?>
				</ul>
			</div>
			<p>
				<button type="button" class="button button-primary" data-cmp-check-start <?php disabled( empty( $list ) ); ?>><?php esc_html_e( 'Verifică în browser', 'consent-manager-pro' ); ?></button>
				<span class="cmp-check-status" data-cmp-check-status aria-live="polite"></span>
			</p>

			<?php if ( ! empty( $report['pages'] ) && is_array( $report['pages'] ) ) : ?>
				<h3>
					<?php esc_html_e( 'Raport', 'consent-manager-pro' ); ?>
					<?php if ( ! empty( $report['time'] ) ) : ?>
						<span class="description">(<?php echo esc_html( sprintf( /* translators: %s: time span */ __( 'acum %s', 'consent-manager-pro' ), human_time_diff( (int) $report['time'] ) ) ); ?>)</span>
					<?php endif; ?>
				</h3>
				<?php $cmp_stale = isset( $report['mode'] ) && $report['mode'] !== $mode; ?>
				<?php if ( $cmp_stale ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'Raportul e făcut într-un alt mod decât cel de acum, așa că rezultatele nu mai sunt valabile. Verifică din nou.', 'consent-manager-pro' ); ?></p></div>
				<?php endif; ?>
				<table class="widefat striped cmp-check-table">
					<thead><tr>
						<th><?php esc_html_e( 'Pagina', 'consent-manager-pro' ); ?></th>
						<th><?php esc_html_e( 'Rezultat', 'consent-manager-pro' ); ?></th>
						<th><?php esc_html_e( 'Înainte de acord', 'consent-manager-pro' ); ?></th>
						<th><?php esc_html_e( 'După „Acceptă tot”', 'consent-manager-pro' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $report['pages'] as $rp ) : ?>
						<?php if ( ! is_array( $rp ) || empty( $rp['url'] ) ) { continue; } ?>
						<tr class="cmp-check-row cmp-check-row--<?php echo esc_attr( $cmp_stale ? 'stale' : ( isset( $rp['status'] ) ? $rp['status'] : 'none' ) ); ?>">
							<td class="cmp-check-url"><?php echo esc_html( $rp['url'] ); ?></td>
							<td><?php echo $cmp_stale ? '<span class="description">' . esc_html__( 'verifică din nou', 'consent-manager-pro' ) . '</span>' : self::badge( isset( $rp['status'] ) ? $rp['status'] : 'none' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge() ?></td>
							<td>
								<?php if ( ! isset( $rp['status'] ) || 'none' === $rp['status'] ) : ?>
									<?php echo esc_html( self::reason_text( isset( $rp['reason'] ) ? $rp['reason'] : '' ) ); ?>
								<?php elseif ( empty( $rp['items'] ) ) : ?>
									<span class="description"><?php esc_html_e( 'nimic de la terți, niciun cookie de urmărire', 'consent-manager-pro' ); ?></span>
								<?php else : ?>
									<ul>
									<?php foreach ( (array) $rp['items'] as $it ) : ?>
										<?php if ( is_array( $it ) ) : ?>
											<li class="cmp-check-item cmp-check-item--<?php echo esc_attr( $cmp_stale ? 'stale' : (string) $it[0] ); ?>"><?php echo esc_html( self::item_text( $it, false, $agg_src ) ); ?></li>
										<?php endif; ?>
									<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! empty( $rp['light'] ) ) : ?>
									<span class="description"><?php esc_html_e( 'modul Light: nu există acord', 'consent-manager-pro' ); ?></span>
								<?php elseif ( ! isset( $rp['status'] ) || 'none' === $rp['status'] ) : ?>
									—
								<?php elseif ( null === $rp['after'] ) : ?>
									<span class="description"><?php esc_html_e( 'nu s-a primit rezultatul după acord', 'consent-manager-pro' ); ?></span>
								<?php elseif ( empty( $rp['after'] ) ) : ?>
									<span class="description"><?php esc_html_e( 'nimic în plus', 'consent-manager-pro' ); ?></span>
								<?php else : ?>
									<ul>
									<?php foreach ( (array) $rp['after'] as $it ) : ?>
										<?php if ( is_array( $it ) ) : ?>
											<li><?php echo esc_html( self::item_text( $it, true, $agg_src ) ); ?></li>
										<?php endif; ?>
									<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Roșu: un serviciu care cere acord sau un domeniu terț necunoscut s-a încărcat înainte de acord. Galben: resurse care trimit IP-ul vizitatorului sau cookie-uri necunoscute. Excepțiile, serviciile necesare și, în Advanced, Google Analytics / Tag Manager apar separat și nu schimbă rezultatul. După acord, serviciile acceptate se încarcă normal: coloana e doar informativă.', 'consent-manager-pro' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Script data for check.js (only on this screen). */
	public static function script_data() {
		return array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'saveNonce'  => wp_create_nonce( 'cmp_check_save' ),
			'checkNonce' => wp_create_nonce( 'cmp_check' ),
			'pages'      => array_values( array_slice( CMP_Scanner::current_list(), 0, CMP_Scanner::MAX_PAGES ) ),
			'timeout'    => 20000,
			'doneUrl'    => add_query_arg( array( 'page' => self::SLUG, 'cmp_checked' => 1 ), admin_url( 'admin.php' ) ),
			'i18n'       => array(
				/* translators: 1: page number, 2: total pages */
				'progress' => __( 'Verific pagina %1$d din %2$d…', 'consent-manager-pro' ),
				'saving'   => __( 'Salvez raportul…', 'consent-manager-pro' ),
				'done'     => __( 'Gata.', 'consent-manager-pro' ),
				'error'    => __( 'Raportul nu a putut fi salvat. Încearcă din nou.', 'consent-manager-pro' ),
			),
		);
	}

	/** admin_post_cmp_scan_urls: save the manual additions and exclusions (own domain only). */
	public static function save_urls() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cmp_scan_urls' ) ) {
			wp_die( esc_html__( 'Acțiune neautorizată.', 'consent-manager-pro' ) );
		}
		$raw = isset( $_POST['cmp_scan_urls'] ) && is_array( $_POST['cmp_scan_urls'] ) ? wp_unslash( $_POST['cmp_scan_urls'] ) : array();
		$add = isset( $raw['add'] ) && is_string( $raw['add'] ) ? $raw['add'] : '';
		$exc = isset( $raw['exclude'] ) && is_string( $raw['exclude'] ) ? $raw['exclude'] : '';
		// Bounded input: at most ~100 lines of 2000 chars each are looked at.
		$clean = CMP_Scanner::clean_manual_urls( substr( $add, 0, 200000 ), substr( $exc, 0, 200000 ), home_url( '/' ) );
		$urls  = array();
		foreach ( array( 'add', 'exclude' ) as $k ) {
			$urls[ $k ] = array_map( 'esc_url_raw', $clean[ $k ] );
		}
		update_option( CMP_Scanner::URLS_OPTION, $urls, false );
		CMP_Scanner::enqueue_all(); // the list changed: scan it again
		$args = array( 'page' => self::SLUG, 'cmp_urls' => 1 );
		if ( $clean['rejected'] ) {
			$args['cmp_rejected'] = count( $clean['rejected'] );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/** wp_ajax_cmp_check_save: classify the raw browser results and store the report. */
	public static function ajax_save() {
		if ( ! check_ajax_referer( 'cmp_check_save', 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		$json = isset( $_POST['report'] ) && is_string( $_POST['report'] ) ? wp_unslash( $_POST['report'] ) : '';
		if ( '' === $json || strlen( $json ) > self::MAX_REPORT_BYTES ) {
			wp_send_json_error( null, 400 );
		}
		$raw = json_decode( $json, true );
		if ( ! is_array( $raw ) || ! isset( $raw['pages'] ) || ! is_array( $raw['pages'] ) ) {
			wp_send_json_error( null, 400 );
		}
		// Cookies already in the admin's browser before the run: shown apart (see classify).
		$baseline = isset( $raw['baseline'] ) && is_array( $raw['baseline'] ) ? $raw['baseline'] : array();
		$mode  = self::mode();
		$svcs  = CMP_Services::active();
		$home  = home_url( '/' );
		$pages = array();
		foreach ( array_slice( array_values( $raw['pages'] ), 0, CMP_Scanner::MAX_PAGES ) as $p ) {
			$r = is_array( $p ) ? CMP_Scanner::check_page( $p, $svcs, $home, $mode, $baseline ) : null;
			if ( null !== $r ) {
				$r['url'] = esc_url_raw( $r['url'] );
				$pages[]  = $r;
			}
		}
		$report = array( 'time' => time(), 'mode' => $mode, 'pages' => $pages );
		if ( false === get_option( CMP_Scanner::CHECK_OPTION, false ) ) {
			add_option( CMP_Scanner::CHECK_OPTION, $report, '', 'no' );
		} else {
			update_option( CMP_Scanner::CHECK_OPTION, $report, false );
		}
		wp_send_json_success( array( 'pages' => count( $pages ) ) );
	}
}
