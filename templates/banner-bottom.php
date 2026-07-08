<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** @var array $categories */
/** @var string $preset */
?>
<div id="cmp-banner" class="cmp cmp--<?php echo esc_attr( $preset ); ?>" data-cmp hidden role="dialog" aria-modal="false" aria-label="<?php esc_attr_e( 'Setări cookie', 'consent-manager-pro' ); ?>">
	<div class="cmp__body">
		<p class="cmp__text"><?php esc_html_e( 'Folosim cookie-uri pentru a îmbunătăți experiența ta. Alege ce categorii accepți.', 'consent-manager-pro' ); ?></p>

		<div class="cmp__cats" hidden data-cmp-prefs>
			<?php foreach ( $categories as $cat ) : ?>
				<label class="cmp__cat">
					<input type="checkbox" value="<?php echo esc_attr( $cat['slug'] ); ?>"
						<?php echo ! empty( $cat['required'] ) ? 'checked disabled' : ''; ?>
						data-cmp-cat>
					<span class="cmp__cat-label"><?php echo esc_html( $cat['label'] ); ?></span>
					<span class="cmp__cat-desc"><?php echo esc_html( $cat['description'] ); ?></span>
				</label>
			<?php endforeach; ?>
		</div>

		<div class="cmp__actions">
			<button type="button" class="cmp__btn cmp__btn--ghost" data-cmp-action="prefs"><?php esc_html_e( 'Preferințe', 'consent-manager-pro' ); ?></button>
			<button type="button" class="cmp__btn cmp__btn--ghost" data-cmp-action="reject"><?php esc_html_e( 'Respinge', 'consent-manager-pro' ); ?></button>
			<button type="button" class="cmp__btn cmp__btn--save" data-cmp-action="save" hidden><?php esc_html_e( 'Salvează', 'consent-manager-pro' ); ?></button>
			<button type="button" class="cmp__btn cmp__btn--primary" data-cmp-action="accept"><?php esc_html_e( 'Acceptă tot', 'consent-manager-pro' ); ?></button>
		</div>
	</div>
</div>
