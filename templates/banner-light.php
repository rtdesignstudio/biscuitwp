<?php
/**
 * Light-mode banner — non-blocking bottom bar. Stores no cookie; the dismissal
 * is remembered in localStorage (handled by banner.js). No preferences, no
 * categories, no logging, no Consent Mode update.
 *
 * @var array  $settings Plugin settings.
 * @var string $preset   Active design preset.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cmp_privacy_id  = isset( $settings['privacy_page_id'] ) ? (int) $settings['privacy_page_id'] : 0;
$cmp_privacy_url = $cmp_privacy_id ? get_permalink( $cmp_privacy_id ) : '';
?>
<div class="cmp-light cmp--<?php echo esc_attr( $preset ); ?>" data-cmp-light hidden role="region" aria-label="<?php esc_attr_e( 'Informații cookie', 'consent-manager-pro' ); ?>">
	<p class="cmp-light__text">
		<?php echo esc_html( CMP_I18n::translate( 'cmp_light_intro', __( 'Acest site folosește doar cookie-uri esențiale pentru funcționare.', 'consent-manager-pro' ) ) ); ?>
		<?php if ( $cmp_privacy_url ) : ?>
			<a class="cmp-light__link" href="<?php echo esc_url( $cmp_privacy_url ); ?>"><?php esc_html_e( 'Politica de confidențialitate', 'consent-manager-pro' ); ?></a>.
		<?php endif; ?>
	</p>
	<button type="button" class="cmp-light__btn" data-cmp-light-dismiss><?php echo esc_html( CMP_I18n::translate( 'cmp_light_ok', __( 'Am înțeles', 'consent-manager-pro' ) ) ); ?></button>
</div>
