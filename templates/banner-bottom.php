<?php
/**
 * Banner markup — full-page overlay + centered modal (egov style).
 * The overlay blocks interaction (scroll locked via body class in JS) until
 * the user makes a choice. Two views: main message and granular preferences.
 *
 * @var array  $categories  List of category arrays (slug/label/description/required/...).
 * @var array  $settings    Plugin settings.
 * @var string $preset      Active design preset.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cmp_privacy_id  = isset( $settings['privacy_page_id'] ) ? (int) $settings['privacy_page_id'] : 0;
$cmp_privacy_url = $cmp_privacy_id ? get_permalink( $cmp_privacy_id ) : '';
?>
<div id="cmp-overlay" class="cmp cmp--<?php echo esc_attr( $preset ); ?>" data-cmp hidden>
	<div class="cmp__modal" role="dialog" aria-modal="true" aria-labelledby="cmp-title" data-cmp-modal>

		<!-- View 1: main message + actions -->
		<div class="cmp__main" data-cmp-view="main">
			<h2 id="cmp-title" class="cmp__title"><?php esc_html_e( 'Informații despre cookie-uri', 'consent-manager-pro' ); ?></h2>
			<p class="cmp__text">
				<?php esc_html_e( 'Folosim cookie-uri esențiale pentru funcționarea site-ului și, cu acordul dumneavoastră, cookie-uri de analiză pentru a-l îmbunătăți. Puteți accepta sau refuza cookie-urile opționale.', 'consent-manager-pro' ); ?>
				<?php if ( $cmp_privacy_url ) : ?>
					<a class="cmp__link" href="<?php echo esc_url( $cmp_privacy_url ); ?>"><?php esc_html_e( 'Politica de confidențialitate', 'consent-manager-pro' ); ?></a>.
				<?php endif; ?>
			</p>
			<div class="cmp__actions">
				<button type="button" class="cmp__btn cmp__btn--ghost" data-cmp-action="prefs"><?php esc_html_e( 'Gestionează cookie-urile', 'consent-manager-pro' ); ?></button>
				<span class="cmp__spacer" aria-hidden="true"></span>
				<button type="button" class="cmp__btn cmp__btn--outline" data-cmp-action="reject"><?php esc_html_e( 'Doar cele necesare', 'consent-manager-pro' ); ?></button>
				<button type="button" class="cmp__btn cmp__btn--primary" data-cmp-action="accept"><?php esc_html_e( 'Permite tot', 'consent-manager-pro' ); ?></button>
			</div>
		</div>

		<!-- View 2: granular preferences -->
		<div class="cmp__prefs" data-cmp-view="prefs">
			<div class="cmp__prefs-header">
				<h2 class="cmp__title"><?php esc_html_e( 'Alege ce cookie-uri accepți', 'consent-manager-pro' ); ?></h2>
				<button type="button" class="cmp__collapse" data-cmp-action="back" aria-label="<?php esc_attr_e( 'Înapoi', 'consent-manager-pro' ); ?>">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 15 12 9 18 15"></polyline></svg>
				</button>
			</div>

			<div class="cmp__cats">
				<?php foreach ( $categories as $cmp_cat ) :
					$cmp_slug   = $cmp_cat['slug'];
					$cmp_locked = ! empty( $cmp_cat['required'] );
					$cmp_id     = 'cmp-cat-' . $cmp_slug;
					?>
					<div class="cmp__cat">
						<div class="cmp__cat-body">
							<div class="cmp__cat-head">
								<span class="cmp__cat-label"><?php echo esc_html( $cmp_cat['label'] ); ?></span>
								<?php if ( $cmp_locked ) : ?>
									<span class="cmp__cat-badge"><?php esc_html_e( 'Obligatoriu', 'consent-manager-pro' ); ?></span>
								<?php endif; ?>
							</div>
							<div class="cmp__cat-desc"><?php echo esc_html( $cmp_cat['description'] ); ?></div>
						</div>
						<?php if ( $cmp_locked ) : ?>
							<span class="cmp__cat-locked" aria-label="<?php esc_attr_e( 'Activat permanent', 'consent-manager-pro' ); ?>">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
							</span>
						<?php else : ?>
							<label class="cmp__toggle" for="<?php echo esc_attr( $cmp_id ); ?>">
								<input type="checkbox" id="<?php echo esc_attr( $cmp_id ); ?>" value="<?php echo esc_attr( $cmp_slug ); ?>" data-cmp-cat checked>
								<span class="cmp__toggle-slider"></span>
							</label>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="cmp__prefs-actions">
				<button type="button" class="cmp__btn cmp__btn--outline" data-cmp-action="reject"><?php esc_html_e( 'Doar cele necesare', 'consent-manager-pro' ); ?></button>
				<button type="button" class="cmp__btn cmp__btn--primary" data-cmp-action="save"><?php esc_html_e( 'Confirmă selecția', 'consent-manager-pro' ); ?></button>
			</div>
		</div>

	</div>
</div>
<?php
