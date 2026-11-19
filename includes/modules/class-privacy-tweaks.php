<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone-loadable for tests; hooks_for() and avatar_data() use no WP.
}

/**
 * One-click privacy switches: stop WordPress emoji (s.w.org) and replace
 * Gravatar with a local avatar. Both are off by default.
 */
class CMP_Privacy_Tweaks {

	public static function register() {
		$s = function_exists( 'get_option' ) ? get_option( 'cmp_settings', array() ) : array();
		if ( ! is_array( $s ) ) {
			return;
		}
		foreach ( self::hooks_for( $s, function_exists( 'wp_enqueue_emoji_styles' ) ) as $h ) {
			if ( 'remove_action' === $h['op'] ) {
				remove_action( $h['hook'], $h['callback'], $h['priority'] );
			} elseif ( 'remove_filter' === $h['op'] ) {
				remove_filter( $h['hook'], $h['callback'], $h['priority'] );
			} else {
				add_filter( $h['hook'], $h['callback'], $h['priority'], $h['args'] );
			}
		}
	}

	/**
	 * Pure: the hooks to remove or add for these settings (same priorities core uses).
	 *
	 * @param array $settings            cmp_settings.
	 * @param bool  $has_enqueue_styles  True on WP 6.4+, where emoji styles moved to wp_enqueue_emoji_styles.
	 * @return array[] Each: op (remove_action|remove_filter|add_filter), hook, callback, priority, args.
	 */
	public static function hooks_for( array $settings, $has_enqueue_styles = true ) {
		$out = array();
		$rm  = function ( $op, $hook, $cb, $prio = 10 ) {
			return array( 'op' => $op, 'hook' => $hook, 'callback' => $cb, 'priority' => $prio, 'args' => 1 );
		};
		if ( ! empty( $settings['disable_emoji'] ) ) {
			$out[] = $rm( 'remove_action', 'wp_head', 'print_emoji_detection_script', 7 );
			$out[] = $rm( 'remove_action', 'embed_head', 'print_emoji_detection_script' );
			$out[] = $rm( 'remove_action', 'admin_print_scripts', 'print_emoji_detection_script' );
			if ( $has_enqueue_styles ) {
				$out[] = $rm( 'remove_action', 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
			} else {
				$out[] = $rm( 'remove_action', 'wp_print_styles', 'print_emoji_styles' );
			}
			$out[] = $rm( 'remove_action', 'admin_print_styles', 'print_emoji_styles' );
			$out[] = $rm( 'remove_action', 'the_content_feed', 'wp_staticize_emoji' );
			$out[] = $rm( 'remove_action', 'comment_text_rss', 'wp_staticize_emoji' );
			$out[] = $rm( 'remove_filter', 'wp_mail', 'wp_staticize_emoji_for_email' );
			$out[] = $rm( 'add_filter', 'emoji_svg_url', '__return_false' );
		}
		if ( ! empty( $settings['disable_gravatar'] ) ) {
			$out[] = array( 'op' => 'add_filter', 'hook' => 'pre_get_avatar_data', 'callback' => array( __CLASS__, 'filter_avatar' ), 'priority' => 10, 'args' => 2 );
		}
		return $out;
	}

	/** Pure: avatar data pointing at a local image, size respected. */
	public static function avatar_data( array $args, $url ) {
		$size = isset( $args['size'] ) ? (int) $args['size'] : 0;
		$args['size']         = $size > 0 ? $size : 96;
		$args['url']          = (string) $url;
		$args['found_avatar'] = true;
		return $args;
	}

	/** pre_get_avatar_data callback. */
	public static function filter_avatar( $args, $id_or_email = null ) {
		return self::avatar_data( is_array( $args ) ? $args : array(), CMP_URL . 'assets/img/avatar.svg' );
	}
}
