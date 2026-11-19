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

$cmp_t = isset( $settings['texts'] ) && is_array( $settings['texts'] ) ? $settings['texts'] : array();
$cmp_text = function ( $key, $default ) use ( $cmp_t ) {
	$stored = isset( $cmp_t[ $key ] ) ? $cmp_t[ $key ] : '';
	return CMP_I18n::text( 'banner_' . $key, $stored, $default );
};

$cmp_privacy_id  = isset( $settings['privacy_page_id'] ) ? (int) $settings['privacy_page_id'] : 0;
// Link only a published page: a draft would be a 404 for visitors.
$cmp_privacy_url = ( $cmp_privacy_id && 'publish' === get_post_status( $cmp_privacy_id ) ) ? get_permalink( $cmp_privacy_id ) : '';
?>
<div class="cmp-light cmp--<?php echo esc_attr( $preset ); ?>" lang="<?php echo esc_attr( CMP_Lang::current() ); ?>" data-cmp-light hidden role="region" aria-label="<?php echo esc_attr( CMP_Lang::text( 'title' ) ); ?>">
	<p class="cmp-light__text">
		<?php echo esc_html( $cmp_text( 'light_intro', CMP_Lang::text( 'light_intro' ) ) ); ?>
		<?php if ( $cmp_privacy_url ) : ?>
			<a class="cmp-light__link" href="<?php echo esc_url( $cmp_privacy_url ); ?>"><?php echo esc_html( CMP_Lang::text( 'privacy' ) ); ?></a>.
		<?php endif; ?>
	</p>
	<button type="button" class="cmp-light__btn" data-cmp-light-dismiss><?php echo esc_html( $cmp_text( 'accept', CMP_Lang::text( 'accept' ) ) ); ?></button>
</div>
