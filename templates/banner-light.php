<?php
/**
 * Light-mode banner — non-blocking bottom bar. Stores no cookie; the dismissal
 * is remembered in localStorage (handled by banner.js). No preferences, no
 * categories, no logging, no Consent Mode update.
 *
 * @var array  $settings Plugin settings.
 * @var array  $cmp_a    The appearance (CMP_Appearance::from_settings), optional.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// B6: the appearance (CMP_Banner::render() passes it; computed here otherwise).
$cmp_a    = isset( $cmp_a ) && is_array( $cmp_a ) ? $cmp_a : CMP_Appearance::from_settings( $settings );
$cmp_lang = CMP_Lang::current();
// Texts: per language without WPML / Polylang, today's path with them (CMP_Banner::text).
$cmp_text = function ( $key, $default ) use ( $settings, $cmp_a, $cmp_lang ) {
	return CMP_Banner::text( $key, $settings, $cmp_a, $cmp_lang, $default );
};
$cmp_classes = implode( ' ', CMP_Banner::classes( $settings, $cmp_a, 'light' ) );

$cmp_privacy_id  = isset( $settings['privacy_page_id'] ) ? (int) $settings['privacy_page_id'] : 0;
// Link only a published page: a draft would be a 404 for visitors.
$cmp_privacy_url = ( $cmp_privacy_id && 'publish' === get_post_status( $cmp_privacy_id ) ) ? get_permalink( $cmp_privacy_id ) : '';
?>
<div class="cmp-light <?php echo esc_attr( $cmp_classes ); ?>" lang="<?php echo esc_attr( CMP_Lang::current() ); ?>" data-cmp-light hidden role="region" aria-label="<?php echo esc_attr( CMP_Lang::text( 'title' ) ); ?>">
	<p class="cmp-light__text">
		<?php echo esc_html( $cmp_text( 'light_intro', CMP_Lang::text( 'light_intro' ) ) ); ?>
		<?php if ( $cmp_privacy_url ) : ?>
			<a class="cmp-light__link" href="<?php echo esc_url( $cmp_privacy_url ); ?>"><?php echo esc_html( CMP_Lang::text( 'privacy' ) ); ?></a>.
		<?php endif; ?>
	</p>
	<button type="button" class="cmp-light__btn" data-cmp-light-dismiss><?php echo esc_html( $cmp_text( 'accept', CMP_Lang::text( 'accept' ) ) ); ?></button>
</div>
