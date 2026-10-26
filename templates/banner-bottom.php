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

$cmp_t = isset( $settings['texts'] ) && is_array( $settings['texts'] ) ? $settings['texts'] : array();
$cmp_text = function ( $key, $default ) use ( $cmp_t ) {
	$stored = isset( $cmp_t[ $key ] ) ? $cmp_t[ $key ] : '';
	return CMP_I18n::text( 'banner_' . $key, $stored, $default );
};

$cmp_privacy_id  = isset( $settings['privacy_page_id'] ) ? (int) $settings['privacy_page_id'] : 0;
$cmp_privacy_url = $cmp_privacy_id ? get_permalink( $cmp_privacy_id ) : '';
?>
<?php $cmp_position = isset( $settings['position'] ) ? $settings['position'] : 'modal-center'; ?>
<div id="cmp-overlay" class="cmp cmp--<?php echo esc_attr( $preset ); ?> cmp--pos-<?php echo esc_attr( $cmp_position ); ?>" data-cmp hidden>
	<div class="cmp__modal" role="dialog" aria-modal="true" aria-labelledby="cmp-title" tabindex="-1" data-cmp-modal>

		<!-- View 1: main message + actions -->
		<div class="cmp__main" data-cmp-view="main">
			<h2 id="cmp-title" class="cmp__title"><?php echo esc_html( $cmp_text( 'title', CMP_Lang::text( 'title' ) ) ); ?></h2>
			<p class="cmp__text">
				<?php echo esc_html( $cmp_text( 'intro', CMP_Lang::text( 'intro' ) ) ); ?>
				<?php if ( $cmp_privacy_url ) : ?>
					<a class="cmp__link" href="<?php echo esc_url( $cmp_privacy_url ); ?>"><?php echo esc_html( CMP_Lang::text( 'privacy' ) ); ?></a>.
				<?php endif; ?>
			</p>
			<div class="cmp__actions">
				<button type="button" class="cmp__btn cmp__btn--ghost" data-cmp-action="prefs"><?php echo esc_html( $cmp_text( 'prefs', CMP_Lang::text( 'prefs' ) ) ); ?></button>
				<span class="cmp__spacer" aria-hidden="true"></span>
				<button type="button" class="cmp__btn cmp__btn--outline" data-cmp-action="reject"><?php echo esc_html( $cmp_text( 'reject', CMP_Lang::text( 'reject' ) ) ); ?></button>
				<button type="button" class="cmp__btn cmp__btn--primary" data-cmp-action="accept"><?php echo esc_html( $cmp_text( 'accept', CMP_Lang::text( 'accept' ) ) ); ?></button>
			</div>
		</div>

		<!-- View 2: granular preferences -->
		<div class="cmp__prefs" data-cmp-view="prefs">
			<div class="cmp__prefs-header">
				<h2 class="cmp__title"><?php echo esc_html( $cmp_text( 'prefs_title', CMP_Lang::text( 'prefs_title' ) ) ); ?></h2>
				<button type="button" class="cmp__collapse" data-cmp-action="back" aria-label="<?php echo esc_attr( CMP_Lang::text( 'back' ) ); ?>">
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
								<span class="cmp__cat-label"><?php echo esc_html( CMP_I18n::translate( 'cmp_cat_' . $cmp_slug . '_label', CMP_Lang::localize_seed( $cmp_slug, 'label', $cmp_cat['label'] ) ) ); ?></span>
								<?php if ( $cmp_locked ) : ?>
									<span class="cmp__cat-badge"><?php echo esc_html( CMP_Lang::text( 'badge' ) ); ?></span>
								<?php endif; ?>
							</div>
							<div class="cmp__cat-desc"><?php echo esc_html( CMP_I18n::translate( 'cmp_cat_' . $cmp_slug . '_desc', CMP_Lang::localize_seed( $cmp_slug, 'description', $cmp_cat['description'] ) ) ); ?></div>
						</div>
						<?php if ( $cmp_locked ) : ?>
							<span class="cmp__cat-locked" aria-label="<?php echo esc_attr( CMP_Lang::text( 'locked' ) ); ?>">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
							</span>
						<?php else : ?>
							<label class="cmp__toggle" for="<?php echo esc_attr( $cmp_id ); ?>">
								<input type="checkbox" id="<?php echo esc_attr( $cmp_id ); ?>" value="<?php echo esc_attr( $cmp_slug ); ?>" data-cmp-cat>
								<span class="cmp__toggle-slider"></span>
							</label>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="cmp__prefs-actions">
				<button type="button" class="cmp__btn cmp__btn--outline" data-cmp-action="reject"><?php echo esc_html( $cmp_text( 'reject', CMP_Lang::text( 'reject' ) ) ); ?></button>
				<button type="button" class="cmp__btn cmp__btn--primary" data-cmp-action="save"><?php echo esc_html( $cmp_text( 'save', CMP_Lang::text( 'save' ) ) ); ?></button>
			</div>
		</div>

	</div>
</div>
<?php
