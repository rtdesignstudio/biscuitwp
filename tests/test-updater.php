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

// file(): without WordPress loaded, falls back to the slug default.
cmp_assert_eq( CMP_Updater::file(), 'consent-manager-pro/consent-manager-pro.php', 'file() falls back to slug default without WP' );

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

// Proxy URL/key resolution (baked-in constants; wp-config override tested below).
cmp_assert_eq( CMP_Updater::proxy_url(), 'https://rt.md/cmp-update', 'proxy_url from baked constant' );
cmp_assert_eq( CMP_Updater::proxy_key(), CMP_Updater::PROXY_KEY, 'proxy_key from baked constant' );

// release_from_proxy builds the download package URL from proxy_url + key.
$r = CMP_Updater::release_from_proxy( array( 'version' => '0.1.2' ), 'https://rt.md/u/cmp-update.php', 'secret' );
cmp_assert_eq( $r['tag_name'], '0.1.2', 'proxy version -> tag_name' );
cmp_assert_eq( $r['zipball_url'], 'https://rt.md/u/cmp-update.php?action=download&key=secret', 'package uses ? separator' );
$r2 = CMP_Updater::release_from_proxy( array( 'version' => '0.1.2' ), 'https://rt.md/u/?x=1', 'secret' );
cmp_assert_eq( $r2['zipball_url'], 'https://rt.md/u/?x=1&action=download&key=secret', 'package uses & separator when url already has query' );
cmp_assert_eq( CMP_Updater::release_from_proxy( array(), 'u', 'k' ), null, 'missing version -> null' );
cmp_assert_eq( CMP_Updater::release_from_proxy( 'x', 'u', 'k' ), null, 'non-array -> null' );

// build_update consumes the proxy-shaped release.
$u = CMP_Updater::build_update( CMP_Updater::release_from_proxy( array( 'version' => '0.1.2' ), 'https://rt.md/u/', 'k' ), '0.1.0' );
cmp_assert_eq( $u->package, 'https://rt.md/u/?action=download&key=k', 'proxy package flows into build_update' );

// wp-config override wins (define is plain PHP; keep this LAST — constants persist).
define( 'CMP_UPDATE_PROXY', 'https://rt.md/u/' );
define( 'CMP_UPDATE_KEY', 'k2' );
cmp_assert_eq( CMP_Updater::proxy_url(), 'https://rt.md/u/', 'proxy_url from wp-config override' );
cmp_assert_eq( CMP_Updater::proxy_key(), 'k2', 'proxy_key from wp-config override' );

// pick_assets: zip + detached signature picked by name, regardless of order.
$pa = CMP_Updater::pick_assets( array(
	array( 'name' => 'consent-manager-pro.zip.sig', 'url' => 'https://api.github.com/repos/rtgit003/consent-manager-pro/releases/assets/2' ),
	array( 'name' => 'consent-manager-pro.zip', 'url' => 'https://api.github.com/repos/rtgit003/consent-manager-pro/releases/assets/1' ),
) );
cmp_assert_eq( $pa['zip'], 'https://api.github.com/repos/rtgit003/consent-manager-pro/releases/assets/1', 'pick_assets: zip by name' );
cmp_assert_eq( $pa['sig'], 'https://api.github.com/repos/rtgit003/consent-manager-pro/releases/assets/2', 'pick_assets: sig by name' );
$pa2 = CMP_Updater::pick_assets( array( array( 'name' => 'other.zip', 'url' => 'u1' ) ) );
cmp_assert_eq( $pa2, array( 'zip' => 'u1', 'sig' => '' ), 'pick_assets: unsigned release -> no sig' );
cmp_assert_eq( CMP_Updater::pick_assets( 'x' ), array( 'zip' => '', 'sig' => '' ), 'pick_assets: non-array' );

// package_kind: only our relay / our repo are handled (and verified).
cmp_assert_eq( CMP_Updater::package_kind( 'https://rt.md/cmp-update?action=download&key=k', 'https://rt.md/cmp-update' ), 'proxy', 'package_kind: relay' );
cmp_assert_eq( CMP_Updater::package_kind( 'https://api.github.com/repos/rtgit003/consent-manager-pro/releases/assets/1', 'https://rt.md/cmp-update' ), 'github', 'package_kind: our repo' );
cmp_assert_eq( CMP_Updater::package_kind( 'https://api.github.com/repos/someone/else/releases/assets/1', 'https://rt.md/cmp-update' ), '', 'package_kind: other repo' );
cmp_assert_eq( CMP_Updater::package_kind( 'https://downloads.wordpress.org/plugin/akismet.zip', 'https://rt.md/cmp-update' ), '', 'package_kind: other plugin' );
cmp_assert_eq( CMP_Updater::package_kind( 'https://rt.md/cmp-update?action=version&key=k', 'https://rt.md/cmp-update' ), '', 'package_kind: relay non-download' );

// verify_signature: detached Ed25519 over the package bytes.
$kp  = sodium_crypto_sign_keypair();
$pk  = base64_encode( sodium_crypto_sign_publickey( $kp ) );
$zip = tempnam( sys_get_temp_dir(), 'cmpzip' );
file_put_contents( $zip, 'PK fake package bytes' );
$sig = base64_encode( sodium_crypto_sign_detached( 'PK fake package bytes', sodium_crypto_sign_secretkey( $kp ) ) );
cmp_assert( CMP_Updater::verify_signature( $zip, $sig, $pk ), 'verify_signature: valid signature accepted' );
cmp_assert( ! CMP_Updater::verify_signature( $zip, $sig ), 'verify_signature: signed by another key -> rejected' );
cmp_assert( ! CMP_Updater::verify_signature( $zip, '', $pk ), 'verify_signature: missing signature -> rejected' );
cmp_assert( ! CMP_Updater::verify_signature( $zip, 'not base64 !!', $pk ), 'verify_signature: garbage -> rejected' );
file_put_contents( $zip, 'PK tampered package bytes' );
cmp_assert( ! CMP_Updater::verify_signature( $zip, $sig, $pk ), 'verify_signature: tampered package -> rejected' );
unlink( $zip );
cmp_assert_eq( strlen( base64_decode( CMP_Updater::SIGN_PUBLIC_KEY ) ), 32, 'baked public key is 32 bytes' );
