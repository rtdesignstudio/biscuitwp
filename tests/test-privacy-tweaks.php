<?php
require_once __DIR__ . '/../includes/modules/class-privacy-tweaks.php';
require_once __DIR__ . '/../includes/class-sanitizer.php';

// hooks_for: pure list of hooks to remove/add.
cmp_assert_eq( CMP_Privacy_Tweaks::hooks_for( array() ), array(), 'no settings -> no hooks' );
cmp_assert_eq( CMP_Privacy_Tweaks::hooks_for( array( 'disable_emoji' => false, 'disable_gravatar' => false ) ), array(), 'both off -> no hooks' );

$em = CMP_Privacy_Tweaks::hooks_for( array( 'disable_emoji' => true ), true );
$names = array_map( function ( $h ) { return $h['op'] . ':' . $h['hook'] . ':' . ( is_string( $h['callback'] ) ? $h['callback'] : 'cb' ) . ':' . $h['priority']; }, $em );
cmp_assert( in_array( 'remove_action:wp_head:print_emoji_detection_script:7', $names, true ), 'emoji: wp_head detection script @7 removed' );
cmp_assert( in_array( 'remove_action:admin_print_scripts:print_emoji_detection_script:10', $names, true ), 'emoji: admin scripts removed' );
cmp_assert( in_array( 'remove_action:admin_print_styles:print_emoji_styles:10', $names, true ), 'emoji: admin styles removed' );
cmp_assert( in_array( 'remove_action:the_content_feed:wp_staticize_emoji:10', $names, true ), 'emoji: feed content removed' );
cmp_assert( in_array( 'remove_action:comment_text_rss:wp_staticize_emoji:10', $names, true ), 'emoji: feed comments removed' );
cmp_assert( in_array( 'remove_filter:wp_mail:wp_staticize_emoji_for_email:10', $names, true ), 'emoji: mail removed' );
cmp_assert( in_array( 'remove_action:wp_enqueue_scripts:wp_enqueue_emoji_styles:10', $names, true ), 'emoji (WP 6.4+): enqueue styles removed' );
cmp_assert( ! in_array( 'remove_action:wp_print_styles:print_emoji_styles:10', $names, true ), 'emoji (WP 6.4+): no legacy wp_print_styles entry' );
cmp_assert( in_array( 'add_filter:emoji_svg_url:__return_false:10', $names, true ), 'emoji: svg url filter off' );
$old = array_map( function ( $h ) { return $h['hook'] . ':' . ( is_string( $h['callback'] ) ? $h['callback'] : 'cb' ); }, CMP_Privacy_Tweaks::hooks_for( array( 'disable_emoji' => true ), false ) );
cmp_assert( in_array( 'wp_print_styles:print_emoji_styles', $old, true ), 'emoji (WP < 6.4): wp_print_styles removed' );
cmp_assert( ! in_array( 'wp_enqueue_scripts:wp_enqueue_emoji_styles', $old, true ), 'emoji (WP < 6.4): no enqueue entry' );
foreach ( $em as $h ) {
	cmp_assert( 'pre_get_avatar_data' !== $h['hook'], 'emoji only: no avatar hook' );
}

$gr = CMP_Privacy_Tweaks::hooks_for( array( 'disable_gravatar' => true ) );
cmp_assert_eq( count( $gr ), 1, 'gravatar only: one hook' );
cmp_assert_eq( $gr[0]['hook'], 'pre_get_avatar_data', 'gravatar: pre_get_avatar_data' );
cmp_assert_eq( $gr[0]['op'], 'add_filter', 'gravatar: added filter' );

// avatar_data: local URL, found, size respected.
$ad = CMP_Privacy_Tweaks::avatar_data( array( 'size' => 48 ), 'https://site/p/avatar.svg' );
cmp_assert_eq( $ad['url'], 'https://site/p/avatar.svg', 'avatar url local' );
cmp_assert_eq( $ad['found_avatar'], true, 'avatar found' );
cmp_assert_eq( $ad['size'], 48, 'avatar size kept' );
$ad2 = CMP_Privacy_Tweaks::avatar_data( array(), 'u' );
cmp_assert_eq( $ad2['size'], 96, 'avatar default size' );

// Sanitizer + installer defaults.
$s0 = CMP_Sanitizer::settings( array( 'position' => 'bottom' ) );
cmp_assert_eq( $s0['disable_emoji'], false, 'disable_emoji missing -> false' );
cmp_assert_eq( $s0['disable_gravatar'], false, 'disable_gravatar missing -> false' );
$s1 = CMP_Sanitizer::settings( array( 'disable_emoji' => '1', 'disable_gravatar' => '1' ) );
cmp_assert_eq( $s1['disable_emoji'], true, 'disable_emoji truthy -> true' );
cmp_assert_eq( $s1['disable_gravatar'], true, 'disable_gravatar truthy -> true' );
require_once __DIR__ . '/../includes/class-installer.php';
$d = CMP_Installer::default_settings();
cmp_assert_eq( $d['disable_emoji'], false, 'installer default emoji false' );
cmp_assert_eq( $d['disable_gravatar'], false, 'installer default gravatar false' );
