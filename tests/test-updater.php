<?php
require_once __DIR__ . '/../includes/modules/class-updater.php';

// is_newer: tag normalization + comparison.
cmp_assert_eq( CMP_Updater::is_newer( 'v0.1.1', '0.1.0' ), true, 'v0.1.1 newer than 0.1.0' );
cmp_assert_eq( CMP_Updater::is_newer( '0.1.1', '0.1.0' ), true, 'tag without v prefix works' );
cmp_assert_eq( CMP_Updater::is_newer( 'v0.1.0', '0.1.0' ), false, 'equal versions => not newer' );
cmp_assert_eq( CMP_Updater::is_newer( 'v0.0.9', '0.1.0' ), false, 'older tag => not newer' );

// build_update: full release with asset.
$release = array(
	'tag_name'    => 'v0.2.0',
	'html_url'    => 'https://github.com/rtgit003/consent-manager-pro/releases/tag/v0.2.0',
	'zipball_url' => 'https://api.github.com/repos/rtgit003/consent-manager-pro/zipball/v0.2.0',
	'assets'      => array(
		array( 'url' => 'https://api.github.com/repos/rtgit003/consent-manager-pro/releases/assets/123' ),
	),
);
$u = CMP_Updater::build_update( $release, '0.1.0' );
cmp_assert( is_object( $u ), 'build_update returns object for newer release' );
cmp_assert_eq( $u->new_version, '0.2.0', 'new_version has v stripped' );
cmp_assert_eq( $u->package, 'https://api.github.com/repos/rtgit003/consent-manager-pro/releases/assets/123', 'asset url preferred as package' );
cmp_assert_eq( $u->plugin, 'consent-manager-pro/consent-manager-pro.php', 'plugin basename set' );
cmp_assert_eq( $u->slug, 'consent-manager-pro', 'slug set' );

// build_update: no asset => zipball fallback.
$release2 = array(
	'tag_name'    => 'v0.2.0',
	'html_url'    => 'https://github.com/rtgit003/consent-manager-pro/releases/tag/v0.2.0',
	'zipball_url' => 'https://api.github.com/repos/rtgit003/consent-manager-pro/zipball/v0.2.0',
	'assets'      => array(),
);
$u2 = CMP_Updater::build_update( $release2, '0.1.0' );
cmp_assert_eq( $u2->package, 'https://api.github.com/repos/rtgit003/consent-manager-pro/zipball/v0.2.0', 'zipball fallback when no asset' );

// build_update: not newer / malformed => null.
cmp_assert_eq( CMP_Updater::build_update( $release, '0.2.0' ), null, 'equal version => null' );
cmp_assert_eq( CMP_Updater::build_update( array( 'tag_name' => 'v9.9.9' ), '0.1.0' ), null, 'missing urls => null' );
cmp_assert_eq( CMP_Updater::build_update( array(), '0.1.0' ), null, 'empty release => null' );
