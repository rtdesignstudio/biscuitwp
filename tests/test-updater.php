<?php
require_once __DIR__ . '/../includes/modules/class-updater.php';

// is_newer: tag normalization + comparison.
cmp_assert_eq( CMP_Updater::is_newer( 'v0.1.1', '0.1.0' ), true, 'v0.1.1 newer than 0.1.0' );
cmp_assert_eq( CMP_Updater::is_newer( '0.1.1', '0.1.0' ), true, 'tag without v prefix works' );
cmp_assert_eq( CMP_Updater::is_newer( 'v0.1.0', '0.1.0' ), false, 'equal versions => not newer' );
cmp_assert_eq( CMP_Updater::is_newer( 'v0.0.9', '0.1.0' ), false, 'older tag => not newer' );

// file(): without WordPress loaded, falls back to the slug default.
// Without WordPress: the slug default. With the plugin_basename stub some earlier
// test defines (and no CMP_ENTRY): the checkout's own folder name (a clone may be
// named anything) and the real main file biscuitwp.php.
cmp_assert_eq(
	CMP_Updater::file(),
	( function_exists( 'plugin_basename' ) && defined( 'CMP_PATH' ) && class_exists( 'CMP_Plugin' ) ? basename( rtrim( CMP_PATH, '/' ) ) . '/biscuitwp.php' : 'consent-manager-pro/consent-manager-pro.php' ),
	'file(): slug default without WP, the active basename (CMP_Plugin::basename()) with it'
);

// 0.4.1: the direct GitHub path (token on the site) is gone.
cmp_assert( ! method_exists( 'CMP_Updater', 'pick_assets' ), 'pick_assets removed with the token path' );
cmp_assert( ! method_exists( 'CMP_Updater', 'token' ), 'token() removed: no GitHub token on sites' );
cmp_assert( ! method_exists( 'CMP_Updater', 'authenticated_download' ), 'authenticated_download removed' );

// B8a: the relay code is gone.
cmp_assert( ! method_exists( 'CMP_Updater', 'release_from_proxy' ), 'release_from_proxy removed with the relay' );
cmp_assert( ! method_exists( 'CMP_Updater', 'proxy_url' ), 'proxy_url removed with the relay' );
cmp_assert( ! method_exists( 'CMP_Updater', 'proxy_key' ), 'proxy_key removed with the relay' );
cmp_assert( ! defined( 'CMP_Updater::PROXY_KEY' ), 'PROXY_KEY constant removed with the relay' );
cmp_assert( ! defined( 'CMP_Updater::PROXY_URL' ), 'PROXY_URL constant removed with the relay' );
cmp_assert( ! method_exists( 'CMP_Updater', 'package_kind' ), 'package_kind removed with the relay' );
cmp_assert( ! method_exists( 'CMP_Updater', 'build_update' ), 'build_update (GitHub release shape) removed' );

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

// ---------------------------------------------------------------------------
// B8a: signed manifest + package core.
// ---------------------------------------------------------------------------

// Constants.
cmp_assert_eq( CMP_Updater::BASE, 'https://biscuitwp.rt.md/', 'BASE is the static manifest host' );
cmp_assert_eq( CMP_Updater::PACKAGE_PREFIX, 'https://github.com/rtdesignstudio/consent-manager-pro/releases/download/', 'PACKAGE_PREFIX is the public GitHub releases path' );
cmp_assert_eq( CMP_Updater::EXPIRY_DAYS, 60, 'EXPIRY_DAYS = 60' );
cmp_assert_eq( CMP_Updater::MAX_MANIFEST, 65536, 'MAX_MANIFEST = 64 KB' );
cmp_assert_eq( strlen( (string) base64_decode( CMP_Updater::RESCUE_PUBLIC_KEY, true ) ), 32, 'RESCUE_PUBLIC_KEY is a real 32-byte key' );
cmp_assert( CMP_Updater::RESCUE_PUBLIC_KEY !== CMP_Updater::SIGN_PUBLIC_KEY && CMP_Updater::RESCUE_PUBLIC_KEY !== CMP_Updater::MANIFEST_PUBLIC_KEY, 'RESCUE_PUBLIC_KEY differs from the release and manifest keys' );

// Manifest URLs.
cmp_assert_eq( CMP_Updater::manifest_url( 'stable' ), 'https://biscuitwp.rt.md/manifest-stable.json', 'manifest_url: stable' );
cmp_assert_eq( CMP_Updater::manifest_url( 'beta' ), 'https://biscuitwp.rt.md/manifest-beta.json', 'manifest_url: beta' );
cmp_assert_eq( CMP_Updater::manifest_url( '../x' ), 'https://biscuitwp.rt.md/manifest-stable.json', 'manifest_url: unknown channel -> stable' );

// Temp keys: M (manifest), R (rescue), X (foreign). Secrets never leave this process.
$cmp_kp_m = sodium_crypto_sign_keypair();
$cmp_kp_r = sodium_crypto_sign_keypair();
$cmp_kp_x = sodium_crypto_sign_keypair();
$cmp_pk_m = base64_encode( sodium_crypto_sign_publickey( $cmp_kp_m ) );
$cmp_pk_r = base64_encode( sodium_crypto_sign_publickey( $cmp_kp_r ) );
$cmp_keys = array( $cmp_pk_m, $cmp_pk_r );

function cmp_t_sign( $data, $kp ) {
	return base64_encode( sodium_crypto_sign_detached( $data, sodium_crypto_sign_secretkey( $kp ) ) );
}

$cmp_now = 1800000000; // fixed "now" for deterministic tests.

function cmp_t_manifest( array $over = array() ) {
	$now  = $GLOBALS['cmp_now'];
	$base = array(
		'format'       => 1,
		'slug'         => 'consent-manager-pro',
		'channel'      => 'stable',
		'version'      => '1.0.0',
		'package'      => 'https://github.com/rtdesignstudio/consent-manager-pro/releases/download/v1.0.0/consent-manager-pro.zip',
		'sha256'       => str_repeat( 'a', 64 ),
		'signature'    => base64_encode( str_repeat( "\0", 64 ) ),
		'requires_wp'  => '6.0',
		'requires_php' => '7.4',
		'tested_wp'    => '6.9',
		'released'     => gmdate( 'Y-m-d\TH:i:s\Z', $now - 86400 ),
		'issued'       => gmdate( 'Y-m-d\TH:i:s\Z', $now - 86400 ),
		'expires'      => gmdate( 'Y-m-d\TH:i:s\Z', $now + 59 * 86400 ),
		'revoked'      => array(),
		'notes'        => array( array( 'version' => '1.0.0', 'date' => '2027-03-01', 'items' => array( 'x' ) ) ),
	);
	foreach ( $over as $k => $v ) {
		if ( null === $v ) {
			unset( $base[ $k ] );
		} else {
			$base[ $k ] = $v;
		}
	}
	return json_encode( $base, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
}

function cmp_t_env( array $over = array() ) {
	return array_merge(
		array(
			'version'     => '0.9.0',
			'php'         => '8.1.0',
			'wp'          => '6.5',
			'now'         => $GLOBALS['cmp_now'],
			'issued_seen' => 0,
		),
		$over
	);
}

function cmp_t_eval( $json, $kp = null, array $env = array(), $channel = 'stable' ) {
	$kp = $kp ? $kp : $GLOBALS['cmp_kp_m'];
	return CMP_Updater::evaluate_manifest( $json, cmp_t_sign( $json, $kp ), $channel, cmp_t_env( $env ), $GLOBALS['cmp_keys'] );
}

// Signature.
$j = cmp_t_manifest();
$r = cmp_t_eval( $j );
cmp_assert_eq( $r['status'], 'ok', 'manifest signed with M -> ok' );
cmp_assert_eq( $r['offer'], true, 'manifest 1.0.0 vs installed 0.9.0 -> offer' );
cmp_assert( is_array( $r['manifest'] ) && '1.0.0' === $r['manifest']['version'], 'ok result carries the decoded manifest' );
cmp_assert_eq( $r['issued'], $cmp_now - 86400, 'ok result returns issued as a timestamp' );
cmp_assert_eq( $r['revoked_installed'], false, 'installed version not revoked -> revoked_installed false' );
cmp_assert_eq( cmp_t_eval( $j, $cmp_kp_r )['status'], 'ok', 'manifest signed with R (rescue) -> ok' );
$rx = cmp_t_eval( $j, $cmp_kp_x );
cmp_assert_eq( $rx['status'], 'invalid', 'manifest signed with X (foreign) -> invalid' );
cmp_assert( false === $rx['offer'] && null === $rx['manifest'] && 0 === $rx['issued'] && false === $rx['revoked_installed'], 'invalid result: no offer, no manifest, issued 0' );
$sig_ok   = cmp_t_sign( $j, $cmp_kp_m );
$tampered = str_replace( '"1.0.0"', '"1.0.1"', $j );
cmp_assert_eq( CMP_Updater::evaluate_manifest( $tampered, $sig_ok, 'stable', cmp_t_env(), $cmp_keys )['status'], 'invalid', 'one byte changed in JSON -> invalid' );
cmp_assert_eq( CMP_Updater::evaluate_manifest( $j, '', 'stable', cmp_t_env(), $cmp_keys )['status'], 'invalid', 'missing signature -> invalid' );
$big = cmp_t_manifest( array( 'notes' => array( array( 'version' => '1.0.0', 'date' => '2027-03-01', 'items' => array( str_repeat( 'x', 70000 ) ) ) ) ) );
cmp_assert_eq( cmp_t_eval( $big )['status'], 'invalid', 'JSON larger than 64 KB -> invalid (even if signed)' );
cmp_assert_eq( cmp_t_eval( 'not json' )['status'], 'invalid', 'signed non-JSON -> invalid' );

// Fields.
cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'format' => 2 ) ) )['status'], 'invalid', 'format != 1 -> invalid' );
cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'format' => null ) ) )['status'], 'invalid', 'missing format -> invalid' );
cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'slug' => 'other-plugin' ) ) )['status'], 'invalid', 'wrong slug -> invalid' );
cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'channel' => 'beta' ) ) )['status'], 'invalid', 'channel beta when stable requested -> invalid' );
cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'channel' => 'beta' ) ), null, array(), 'beta' )['status'], 'ok', 'channel beta when beta requested -> ok' );
cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'package' => 'https://github.com/evil/consent-manager-pro/releases/download/v1.0.0/x.zip' ) ) )['status'], 'invalid', 'package with foreign prefix -> invalid' );
cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'package' => 'http://github.com/rtdesignstudio/consent-manager-pro/releases/download/v1.0.0/x.zip' ) ) )['status'], 'invalid', 'package over http -> invalid' );
cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'sha256' => 'abc' ) ) )['status'], 'invalid', 'malformed sha256 -> invalid' );
cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'version' => '' ) ) )['status'], 'invalid', 'empty version -> invalid' );
cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'issued' => 'yesterday-ish' ) ) )['status'], 'invalid', 'unreadable issued -> invalid' );
cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'expires' => 'now' ) ) )['status'], 'invalid', 'unreadable expires -> invalid' );
cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'revoked' => '0.9.0' ) ) )['status'], 'invalid', 'revoked not a list -> invalid' );

// Rollback (per-channel counter passed in by the caller).
$iss = $cmp_now - 86400;
cmp_assert_eq( cmp_t_eval( $j, null, array( 'issued_seen' => $iss + 1 ) )['status'], 'invalid', 'issued < issued_seen -> invalid (anti-rollback)' );
cmp_assert_eq( cmp_t_eval( $j, null, array( 'issued_seen' => $iss ) )['status'], 'ok', 'issued == issued_seen -> accepted' );

// Expiry.
$je = cmp_t_manifest( array( 'expires' => gmdate( 'Y-m-d\TH:i:s\Z', $cmp_now - 1 ) ) );
$re = cmp_t_eval( $je );
cmp_assert_eq( $re['status'], 'expired', 'expires < now -> expired' );
cmp_assert_eq( $re['offer'], false, 'expired -> no offer' );
cmp_assert_eq( $re['issued'], $iss, 'expired -> issued still returned (counter update)' );
cmp_assert_eq( cmp_t_eval( $je, null, array( 'issued_seen' => $iss + 1 ) )['status'], 'invalid', 'expired and rolled back -> invalid' );

// Revoked versions.
$jr = cmp_t_manifest( array( 'revoked' => array( '0.9.0' ) ) );
$rr = cmp_t_eval( $jr );
cmp_assert( 'ok' === $rr['status'] && true === $rr['revoked_installed'], 'installed version in revoked -> revoked_installed (ok status)' );
$jre = cmp_t_manifest( array( 'revoked' => array( '0.9.0' ), 'expires' => gmdate( 'Y-m-d\TH:i:s\Z', $cmp_now - 1 ) ) );
$rre = cmp_t_eval( $jre );
cmp_assert( 'expired' === $rre['status'] && true === $rre['revoked_installed'], 'installed version in revoked -> revoked_installed (expired status)' );
cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'revoked' => array( '1.0.0' ) ) ) )['offer'], false, 'manifest version in revoked -> no offer' );
cmp_assert_eq( cmp_t_eval( $j, $cmp_kp_x, array( 'version' => '0.9.0' ) )['revoked_installed'], false, 'invalid signature -> revoked list ignored' );

// What is not offered.
cmp_assert_eq( cmp_t_eval( $j, null, array( 'version' => '1.0.0' ) )['offer'], false, 'manifest version == installed -> no offer' );
cmp_assert_eq( cmp_t_eval( $j, null, array( 'version' => '1.1.0' ) )['offer'], false, 'manifest version < installed -> no offer' );
cmp_assert_eq( cmp_t_eval( $j, null, array( 'version' => '1.1.0' ) )['status'], 'ok', 'older manifest version is still a valid manifest' );
cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'requires_php' => '8.2' ) ) )['offer'], false, 'requires_php above env php -> no offer' );
cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'requires_wp' => '6.6' ) ) )['offer'], false, 'requires_wp above env wp -> no offer' );
cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'requires_wp' => '6.5' ) ) )['offer'], true, 'requires_wp equal to env wp -> offer' );
cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'requires_wp' => '9.9' ) ), null, array( 'wp' => '' ) )['offer'], true, 'empty env wp -> requires_wp treated as satisfied' );
cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'requires_wp' => null, 'requires_php' => null ) ) )['offer'], true, 'no requires_* in manifest -> offer' );

// Defence in depth (Task 1 review): validity window, future issued, package URL shape, strict dates.
$t_iso = function ( $ts ) { return gmdate( 'Y-m-d\TH:i:s\Z', $ts ); };
cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'issued' => $t_iso( $cmp_now - 86400 ), 'expires' => $t_iso( $cmp_now - 86400 + 60 * 86400 ) ) ) )['status'], 'ok', 'expires - issued == 60 days -> ok' );
cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'issued' => $t_iso( $cmp_now - 86400 ), 'expires' => $t_iso( $cmp_now - 86400 + 60 * 86400 + 1 ) ) ) )['status'], 'invalid', 'expires - issued > 60 days -> invalid' );
cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'issued' => $t_iso( $cmp_now ), 'expires' => $t_iso( $cmp_now + 365 * 86400 ) ) ) )['status'], 'invalid', 'manifest valid for a year -> invalid' );
cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'issued' => $t_iso( $cmp_now + 300 ), 'expires' => $t_iso( $cmp_now + 300 + 59 * 86400 ) ) ) )['status'], 'ok', 'issued 300 s in the future (clock skew) -> ok' );
cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'issued' => $t_iso( $cmp_now + 301 ), 'expires' => $t_iso( $cmp_now + 301 + 59 * 86400 ) ) ) )['status'], 'invalid', 'issued more than 300 s in the future -> invalid' );
cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'issued' => '2027-02-30T00:00:00Z' ) ) )['status'], 'invalid', 'impossible date 2027-02-30 -> invalid (strict round trip)' );
cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'expires' => '2027-01-01T24:00:00Z' ) ) )['status'], 'invalid', 'hour 24 -> invalid (strict round trip)' );
$t_pre = CMP_Updater::PACKAGE_PREFIX;
foreach ( array(
	'..'         => $t_pre . 'v1.0.0/../../x.zip',
	'?'          => $t_pre . 'v1.0.0/x.zip?a=1',
	'#'          => $t_pre . 'v1.0.0/x.zip#frag',
	'backslash'  => $t_pre . 'v1.0.0\\x.zip',
	'space'      => $t_pre . 'v1.0.0/x y.zip',
	'newline'    => $t_pre . "v1.0.0/x.zip\n",
	'tab'        => $t_pre . "v1.0.0/\tx.zip",
) as $t_what => $t_url ) {
	cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'package' => $t_url ) ) )['status'], 'invalid', "package URL containing {$t_what} -> invalid" );
}

// Two accepted package prefixes (fix round 1): the old repo path (what the
// manifests use today; 1.0.0 sites only know it) and the renamed repo, so a
// later release can switch the manifest without stranding 1.1.0+ sites.
// Same host, exact path start; nothing else loosened.
$t_old_pre = 'https://github.com/rtdesignstudio/consent-manager-pro/releases/download/';
$t_new_pre = 'https://github.com/rtdesignstudio/biscuitwp/releases/download/';
cmp_assert_eq( defined( 'CMP_Updater::PACKAGE_PREFIXES' ) ? CMP_Updater::PACKAGE_PREFIXES : null, array( $t_old_pre, $t_new_pre ), 'PACKAGE_PREFIXES: old repo path first, renamed repo second, nothing else' );
cmp_assert_eq( CMP_Updater::PACKAGE_PREFIX, $t_old_pre, 'PACKAGE_PREFIX stays the old repo path (what manifests use)' );
cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'package' => $t_old_pre . 'v1.0.0/consent-manager-pro.zip' ) ) )['status'], 'ok', 'package on the old repo path -> ok' );
cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'package' => $t_new_pre . 'v1.0.0/consent-manager-pro.zip' ) ) )['status'], 'ok', 'package on the renamed repo path -> ok' );
foreach ( array(
	'other owner'          => 'https://github.com/evil/biscuitwp/releases/download/v1.0.0/consent-manager-pro.zip',
	'other owner (old)'    => 'https://github.com/evil/consent-manager-pro/releases/download/v1.0.0/consent-manager-pro.zip',
	'other repo'           => 'https://github.com/rtdesignstudio/biscuitwp-fork/releases/download/v1.0.0/consent-manager-pro.zip',
	'repo name as prefix'  => 'https://github.com/rtdesignstudio/biscuitwpx/releases/download/v1.0.0/consent-manager-pro.zip',
	'other host'           => 'https://github.com.evil.example/rtdesignstudio/biscuitwp/releases/download/v1.0.0/consent-manager-pro.zip',
	'cdn host'             => 'https://objects.githubusercontent.com/rtdesignstudio/biscuitwp/releases/download/v1.0.0/consent-manager-pro.zip',
	'http'                 => 'http://github.com/rtdesignstudio/biscuitwp/releases/download/v1.0.0/consent-manager-pro.zip',
	'case variant'         => 'https://GitHub.com/rtdesignstudio/biscuitwp/releases/download/v1.0.0/consent-manager-pro.zip',
	'not releases/download'=> 'https://github.com/rtdesignstudio/biscuitwp/archive/v1.0.0.zip',
	'userinfo'             => 'https://github.com@evil.example/rtdesignstudio/biscuitwp/releases/download/v1.0.0/consent-manager-pro.zip',
) as $t_what => $t_url ) {
	cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'package' => $t_url ) ) )['status'], 'invalid', "package URL ({$t_what}) -> invalid" );
}
foreach ( array(
	'..'        => $t_new_pre . 'v1.0.0/../../x.zip',
	'?'         => $t_new_pre . 'v1.0.0/x.zip?a=1',
	'#'         => $t_new_pre . 'v1.0.0/x.zip#frag',
	'backslash' => $t_new_pre . 'v1.0.0\\x.zip',
	'space'     => $t_new_pre . 'v1.0.0/x y.zip',
) as $t_what => $t_url ) {
	cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'package' => $t_url ) ) )['status'], 'invalid', "renamed-repo package URL containing {$t_what} -> invalid" );
}
// Fix round 2: percent-escapes (dot segments Requests would decode and remove),
// query, fragment, backslash, whitespace and control characters are refused
// by is_package_url itself, before the prefix comparison, for both prefixes.
foreach ( array( $t_old_pre, $t_new_pre ) as $t_p ) {
	foreach ( array(
		'%2e%2e/'   => $t_p . '%2e%2e/%2e%2e/%2e%2e/%2e%2e/evil/repo/releases/download/v1/x.zip',
		'%2E%2E/'   => $t_p . '%2E%2E/%2E%2E/%2E%2E/%2E%2E/evil/repo/releases/download/v1/x.zip',
		'%2f'       => $t_p . 'v1.0.0%2f..%2fx.zip',
		'%'         => $t_p . 'v1.0.0/consent-manager-pro%20.zip',
		'NUL'       => $t_p . "v1.0.0/consent-manager-pro.zip\x00",
		'DEL'       => $t_p . "v1.0.0/consent-manager-pro.zip\x7f",
		'?'         => $t_p . 'v1.0.0/consent-manager-pro.zip?x=1',
		'#'         => $t_p . 'v1.0.0/consent-manager-pro.zip#x',
		'backslash' => $t_p . 'v1.0.0\\consent-manager-pro.zip',
		'tab'       => $t_p . "v1.0.0/\tconsent-manager-pro.zip",
	) as $t_what => $t_url ) {
		cmp_assert( method_exists( 'CMP_Updater', 'is_package_url' ) && ! CMP_Updater::is_package_url( $t_url ), "is_package_url: {$t_what} refused ({$t_p})" );
		cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'package' => $t_url ) ) )['status'], 'invalid', "package URL with {$t_what} -> invalid ({$t_p})" );
	}
	cmp_assert( method_exists( 'CMP_Updater', 'is_package_url' ) && CMP_Updater::is_package_url( $t_p . 'v1.1.0/consent-manager-pro.zip' ), "is_package_url: normal vX.Y.Z/consent-manager-pro.zip tail accepted ({$t_p})" );
	cmp_assert_eq( cmp_t_eval( cmp_t_manifest( array( 'package' => $t_p . 'v1.1.0/consent-manager-pro.zip' ) ) )['status'], 'ok', "package URL with a normal tail -> ok ({$t_p})" );
}
$t_e = CMP_Updater::verified_download( false, $t_new_pre . '%2e%2e/%2e%2e/%2e%2e/%2e%2e/evil/repo/releases/download/v1/x.zip', null, array() );
cmp_assert_eq( $t_e, false, 'verified_download: an encoded-dot URL is not ours by URL (passes through, never downloaded by us)' );
cmp_assert( method_exists( 'CMP_Updater', 'is_package_url' ) && CMP_Updater::is_package_url( $t_new_pre . 'v1/x.zip' ) && CMP_Updater::is_package_url( $t_old_pre . 'v1/x.zip' ) && ! CMP_Updater::is_package_url( 'https://github.com/evil/biscuitwp/releases/download/v1/x.zip' ) && ! CMP_Updater::is_package_url( null ), 'is_package_url: either prefix, nothing else' );

// Default keys (null): placeholders only -> nothing verifies, no exception.
cmp_assert_eq( CMP_Updater::evaluate_manifest( $j, cmp_t_sign( $j, $cmp_kp_m ), 'stable', cmp_t_env() )['status'], 'invalid', 'default keys are placeholders -> invalid' );

// Package.
$cmp_kp_rel = sodium_crypto_sign_keypair();
$cmp_pkg    = tempnam( sys_get_temp_dir(), 'cmppkg' );
file_put_contents( $cmp_pkg, 'PK real package bytes' );
$cmp_pkg_keys = array( base64_encode( sodium_crypto_sign_publickey( $cmp_kp_rel ) ), $cmp_pk_r );
$cmp_pm       = array(
	'sha256'    => hash( 'sha256', 'PK real package bytes' ),
	'signature' => cmp_t_sign( 'PK real package bytes', $cmp_kp_rel ),
);
cmp_assert( CMP_Updater::verify_package( $cmp_pkg, $cmp_pm, $cmp_pkg_keys ), 'verify_package: correct sha256 + release signature -> true' );
cmp_assert( CMP_Updater::verify_package( $cmp_pkg, array( 'sha256' => strtoupper( $cmp_pm['sha256'] ) ) + $cmp_pm, $cmp_pkg_keys ), 'verify_package: uppercase sha256 hex accepted' );
cmp_assert( ! CMP_Updater::verify_package( $cmp_pkg, array( 'sha256' => str_repeat( 'b', 64 ) ) + $cmp_pm, $cmp_pkg_keys ), 'verify_package: wrong sha256 -> false' );
cmp_assert( ! CMP_Updater::verify_package( $cmp_pkg, array( 'signature' => cmp_t_sign( 'PK real package bytes', $cmp_kp_x ) ) + $cmp_pm, $cmp_pkg_keys ), 'verify_package: signature with X -> false' );
cmp_assert( CMP_Updater::verify_package( $cmp_pkg, array( 'signature' => cmp_t_sign( 'PK real package bytes', $cmp_kp_r ) ) + $cmp_pm, $cmp_pkg_keys ), 'verify_package: signature with R (rescue) -> true' );
cmp_assert( ! CMP_Updater::verify_package( $cmp_pkg, array(), $cmp_pkg_keys ), 'verify_package: empty manifest -> false' );
cmp_assert( ! CMP_Updater::verify_package( $cmp_pkg . '-missing', $cmp_pm, $cmp_pkg_keys ), 'verify_package: missing file -> false' );
file_put_contents( $cmp_pkg, 'PK real package bytes!' );
cmp_assert( ! CMP_Updater::verify_package( $cmp_pkg, $cmp_pm, $cmp_pkg_keys ), 'verify_package: file modified after signing -> false' );
unlink( $cmp_pkg );

// Placeholder keys never verify and never throw.
cmp_assert_eq( CMP_Updater::verify_detached( 'data', cmp_t_sign( 'data', $cmp_kp_m ), array( 'RESCUE_KEY_NOT_SET' ) ), false, 'verify_detached: placeholder as only key -> false, no exception' );
cmp_assert_eq( CMP_Updater::verify_detached( 'data', cmp_t_sign( 'data', $cmp_kp_m ), array( 'RESCUE_KEY_NOT_SET', 'MANIFEST_KEY_NOT_SET', $cmp_pk_m ) ), true, 'verify_detached: invalid keys skipped, valid one verifies' );
cmp_assert_eq( CMP_Updater::verify_detached( 'data', cmp_t_sign( 'data', $cmp_kp_m ), array() ), false, 'verify_detached: no keys -> false' );
cmp_assert_eq( CMP_Updater::verify_detached( 'data', 'not base64 !!', array( $cmp_pk_m ) ), false, 'verify_detached: garbage signature -> false' );

// Channel. Keep the define LAST: constants persist.
cmp_assert_eq( CMP_Updater::channel(), 'stable', 'channel(): no constant -> stable' );
define( 'CMP_UPDATE_CHANNEL', 'beta' );
cmp_assert_eq( CMP_Updater::channel(), 'beta', 'channel(): CMP_UPDATE_CHANNEL beta -> beta' );

// ---------------------------------------------------------------------------
// B8a Task 2: WordPress integration (filters, cache, options, notices).
// Runs after the channel define above, so the channel here is 'beta'.
// Option/transient stubs share $GLOBALS['cmp_un']['options'] (per site) and ['site'] (network) with test-uninstall.php.
// ---------------------------------------------------------------------------

if ( ! defined( 'CMP_VERSION' ) ) {
	define( 'CMP_VERSION', '0.4.2' );
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['cmp_un']['options'] ) ? $GLOBALS['cmp_un']['options'][ $k ] : $d; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['cmp_un']['options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { unset( $GLOBALS['cmp_un']['options'][ $k ] ); return true; }
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) { return array_key_exists( '_transient_' . $k, $GLOBALS['cmp_un']['options'] ) ? $GLOBALS['cmp_un']['options'][ '_transient_' . $k ] : false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $ttl = 0 ) {
		$GLOBALS['cmp_un']['options'][ '_transient_' . $k ]         = $v;
		$GLOBALS['cmp_un']['options'][ '_transient_timeout_' . $k ] = $ttl;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $k ) { unset( $GLOBALS['cmp_un']['options'][ '_transient_' . $k ] ); return true; }
}
if ( ! function_exists( 'get_site_option' ) ) {
	function get_site_option( $k, $d = false ) { return isset( $GLOBALS['cmp_un']['site'] ) && array_key_exists( $k, $GLOBALS['cmp_un']['site'] ) ? $GLOBALS['cmp_un']['site'][ $k ] : $d; }
}
if ( ! function_exists( 'update_site_option' ) ) {
	function update_site_option( $k, $v ) { $GLOBALS['cmp_un']['site'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_site_option' ) ) {
	function delete_site_option( $k ) { unset( $GLOBALS['cmp_un']['site'][ $k ] ); return true; }
}
if ( ! function_exists( 'get_site_transient' ) ) {
	function get_site_transient( $k ) { return isset( $GLOBALS['cmp_un']['site'] ) && array_key_exists( '_site_transient_' . $k, $GLOBALS['cmp_un']['site'] ) ? $GLOBALS['cmp_un']['site'][ '_site_transient_' . $k ] : false; }
}
if ( ! function_exists( 'set_site_transient' ) ) {
	function set_site_transient( $k, $v, $ttl = 0 ) {
		$GLOBALS['cmp_un']['site'][ '_site_transient_' . $k ]         = $v;
		$GLOBALS['cmp_un']['site'][ '_site_transient_timeout_' . $k ] = $ttl;
		return true;
	}
}
if ( ! function_exists( 'delete_site_transient' ) ) {
	function delete_site_transient( $k ) { unset( $GLOBALS['cmp_un']['site'][ '_site_transient_' . $k ], $GLOBALS['cmp_un']['site'][ '_site_transient_timeout_' . $k ] ); return true; }
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $p = '' ) { return 'https://client-site.example/' . ltrim( $p, '/' ); }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( '__' ) ) {
	function __( $t, $d = null ) { return $t; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $t, $d = null ) { return esc_html( __( $t, $d ) ); }
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $x ) { return $x instanceof WP_Error; }
}
if ( ! function_exists( 'wp_safe_remote_get' ) ) {
	function wp_safe_remote_get( $url, $args = array() ) {
		$GLOBALS['cmp_upd_http']['calls'][] = array( 'url' => $url, 'args' => $args );
		$h = $GLOBALS['cmp_upd_http']['handler'];
		return $h( $url, $args );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) && isset( $r['response']['code'] ) ? $r['response']['code'] : ''; }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $r ) { return is_array( $r ) && isset( $r['body'] ) ? $r['body'] : ''; }
}
if ( ! function_exists( 'wp_tempnam' ) ) {
	function wp_tempnam( $f = '' ) { return tempnam( sys_get_temp_dir(), 'cmpdl' ); }
}

/** Test seam: private static CMP_Updater::$seam (keys, sodium, now), set only through Reflection. */
function cmp_t_seam( array $seam ) {
	$p = new ReflectionProperty( 'CMP_Updater', 'seam' );
	$p->setAccessible( true );
	$p->setValue( null, $seam );
}

/** Reset the stores. $site = network-level (get_site_option / site transients); per-site options start empty. */
function cmp_t_reset_wp( array $site = array() ) {
	$GLOBALS['cmp_un']       = array( 'options' => array(), 'site' => $site, 'cron' => array(), 'meta' => array(), 'basedir' => '' );
	$GLOBALS['cmp_upd_http'] = array(
		'calls'   => array(),
		'handler' => function () {
			return new WP_Error( 'http_request_failed', 'offline' );
		},
	);
}

/** A signed beta manifest put straight into the update cache. */
function cmp_t_cache_manifest( array $over = array(), $kp = null ) {
	$kp   = $kp ? $kp : $GLOBALS['cmp_kp_m'];
	$json = cmp_t_manifest( array_merge( array( 'channel' => 'beta' ), $over ) );
	set_site_transient( CMP_Updater::CACHE_KEY, array( 'channel' => 'beta', 'json' => $json, 'sig' => cmp_t_sign( $json, $kp ) ), 21600 );
	return $json;
}

$cmp_kp_pkg = sodium_crypto_sign_keypair();
$cmp_seam   = array(
	'manifest_keys' => $cmp_keys,
	'package_keys'  => array( base64_encode( sodium_crypto_sign_publickey( $cmp_kp_pkg ) ) ),
	'now'           => $cmp_now,
);
cmp_t_seam( $cmp_seam );
$cmp_pkg_url = CMP_Updater::PACKAGE_PREFIX . 'v1.0.0/consent-manager-pro.zip';

// Header.
$t_main = file_get_contents( __DIR__ . '/../biscuitwp.php' );
cmp_assert( 1 === preg_match( '/^ \* Update URI: https:\/\/biscuitwp\.rt\.md\/$/m', $t_main ), 'header: Update URI: https://biscuitwp.rt.md/' );
cmp_assert( 1 === preg_match( '/^ \* Plugin URI: https:\/\/biscuitwp\.rt\.md\/$/m', $t_main ), 'header: Plugin URI: https://biscuitwp.rt.md/' );

// register(): the filters.
$t_src = file_get_contents( __DIR__ . '/../includes/modules/class-updater.php' );
cmp_assert( false !== strpos( $t_src, "'update_plugins_biscuitwp.rt.md'" ), 'register: update_plugins_biscuitwp.rt.md filter' );
cmp_assert( false !== strpos( $t_src, "'plugins_api'" ), 'register: plugins_api filter' );
cmp_assert( false === strpos( $t_src, 'pre_set_site_transient_update_plugins' ), 'register: no pre_set_site_transient_update_plugins filter' );

// request_args: anonymous user-agent, nothing site-identifying.
$t_args = CMP_Updater::request_args( 15 );
cmp_assert_eq( $t_args['user-agent'], 'Consent-Manager-Pro/' . CMP_VERSION, 'request_args: user-agent is exactly Consent-Manager-Pro/<CMP_VERSION>' );
cmp_assert_eq( $t_args['timeout'], 15, 'request_args: timeout passed through' );
cmp_assert( false === strpos( serialize( $t_args ), home_url() ) && false === strpos( serialize( $t_args ), 'client-site' ), 'request_args: no home_url() anywhere' );
$t_headers = isset( $t_args['headers'] ) ? array_change_key_case( (array) $t_args['headers'], CASE_LOWER ) : array();
cmp_assert( ! isset( $t_headers['referer'] ), 'request_args: no Referer header' );

// manifest_url: CMP_UPDATE_BASE overrides the base (https only).
cmp_assert_eq( CMP_Updater::manifest_url( 'beta' ), 'https://biscuitwp.rt.md/manifest-beta.json', 'manifest_url: default base without CMP_UPDATE_BASE' );

// fetch_manifest from the network: limits, anti-rollback counter, state.
cmp_t_reset_wp( array( 'cmp_update_state' => array( 'expired' => true, 'revoked_installed' => false, 'no_sodium' => false ) ) );
$r = CMP_Updater::fetch_manifest();
cmp_assert_eq( $r['status'], 'invalid', 'fetch_manifest: network failure -> invalid' );
cmp_assert_eq( get_site_option( 'cmp_update_state' ), array( 'expired' => true, 'revoked_installed' => false, 'no_sodium' => false ), 'fetch_manifest: network failure keeps the saved state' );
cmp_assert( false !== get_site_transient( CMP_Updater::CACHE_KEY ), 'fetch_manifest: failure is cached' );
cmp_assert_eq( $GLOBALS['cmp_un']['site'][ '_site_transient_timeout_' . CMP_Updater::CACHE_KEY ], 3600, 'fetch_manifest: failure cached 1 hour' );
$t_calls = count( $GLOBALS['cmp_upd_http']['calls'] );
CMP_Updater::fetch_manifest();
cmp_assert_eq( count( $GLOBALS['cmp_upd_http']['calls'] ), $t_calls, 'fetch_manifest: cached failure -> no new request' );

$t_issued = $cmp_now - 86400;
$t_json   = cmp_t_manifest( array( 'channel' => 'beta', 'revoked' => array( CMP_VERSION ) ) );
cmp_t_reset_wp( array( 'cmp_manifest_issued' => array( 'stable' => 5, 'beta' => $t_issued - 10 ) ) );
$GLOBALS['cmp_upd_http']['handler'] = function ( $url, $args ) use ( $t_json ) {
	if ( 'https://biscuitwp.rt.md/manifest-beta.json' === $url ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => $t_json );
	}
	if ( 'https://biscuitwp.rt.md/manifest-beta.json.sig' === $url ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => cmp_t_sign( $t_json, $GLOBALS['cmp_kp_m'] ) . "\n" );
	}
	return array( 'response' => array( 'code' => 404 ), 'body' => '' );
};
$r = CMP_Updater::fetch_manifest();
cmp_assert_eq( $r['status'], 'ok', 'fetch_manifest: signed manifest from the network -> ok' );
$t_urls = array_column( $GLOBALS['cmp_upd_http']['calls'], 'url' );
cmp_assert_eq( $t_urls, array( 'https://biscuitwp.rt.md/manifest-beta.json', 'https://biscuitwp.rt.md/manifest-beta.json.sig' ), 'fetch_manifest: GET manifest + .sig of the channel, no query string' );
foreach ( $GLOBALS['cmp_upd_http']['calls'] as $t_c ) {
	cmp_assert_eq( isset( $t_c['args']['limit_response_size'] ) ? $t_c['args']['limit_response_size'] : null, CMP_Updater::MAX_MANIFEST, 'fetch_manifest: limit_response_size = MAX_MANIFEST on ' . basename( $t_c['url'] ) );
	cmp_assert_eq( $t_c['args']['user-agent'], 'Consent-Manager-Pro/' . CMP_VERSION, 'fetch_manifest: anonymous user-agent on ' . basename( $t_c['url'] ) );
	cmp_assert_eq( $t_c['args']['timeout'], 15, 'fetch_manifest: 15 s timeout on ' . basename( $t_c['url'] ) );
}
cmp_assert_eq( get_site_option( 'cmp_manifest_issued' ), array( 'stable' => 5, 'beta' => $t_issued ), 'fetch_manifest: cmp_manifest_issued[beta] raised, stable untouched' );
cmp_assert_eq( get_site_option( 'cmp_update_state' ), array( 'expired' => false, 'revoked_installed' => true, 'no_sodium' => false ), 'fetch_manifest: state saved (revoked_installed)' );
cmp_assert_eq( $GLOBALS['cmp_un']['site'][ '_site_transient_timeout_' . CMP_Updater::CACHE_KEY ], 21600, 'fetch_manifest: success cached 6 hours' );
cmp_assert_eq( $GLOBALS['cmp_un']['options'], array(), 'fetch_manifest: nothing written to the per-site store (counter, state and cache are network-wide)' );
cmp_assert( isset( $GLOBALS['cmp_un']['site']['cmp_manifest_issued'], $GLOBALS['cmp_un']['site']['cmp_update_state'], $GLOBALS['cmp_un']['site'][ '_site_transient_' . CMP_Updater::CACHE_KEY ] ), 'fetch_manifest: counter, state and cache live in the site (network) store' );
$t_calls = count( $GLOBALS['cmp_upd_http']['calls'] );
cmp_assert_eq( CMP_Updater::fetch_manifest()['status'], 'ok', 'fetch_manifest: second call served from cache' );
cmp_assert_eq( count( $GLOBALS['cmp_upd_http']['calls'] ), $t_calls, 'fetch_manifest: cache hit -> no new request' );
CMP_Updater::fetch_manifest( true );
cmp_assert_eq( count( $GLOBALS['cmp_upd_http']['calls'] ), $t_calls + 2, 'fetch_manifest(true) bypasses the cache' );

// Counter never goes down; a cached manifest older than the counter is refused.
update_site_option( 'cmp_manifest_issued', array( 'beta' => $t_issued + 100 ) );
cmp_assert_eq( CMP_Updater::fetch_manifest()['status'], 'invalid', 'fetch_manifest: cached manifest older than cmp_manifest_issued -> invalid (cache never skips anti-rollback)' );
cmp_assert_eq( get_site_option( 'cmp_manifest_issued' ), array( 'beta' => $t_issued + 100 ), 'fetch_manifest: rollback does not lower the counter' );

// Invalid signature -> treated as unavailable: state kept, counter kept.
cmp_t_reset_wp( array( 'cmp_update_state' => array( 'expired' => false, 'revoked_installed' => true, 'no_sodium' => false ) ) );
cmp_t_cache_manifest( array(), $cmp_kp_x );
cmp_assert_eq( CMP_Updater::fetch_manifest()['status'], 'invalid', 'fetch_manifest: foreign signature -> invalid' );
cmp_assert_eq( get_site_option( 'cmp_update_state' ), array( 'expired' => false, 'revoked_installed' => true, 'no_sodium' => false ), 'fetch_manifest: invalid signature keeps the saved state' );
cmp_assert_eq( get_site_option( 'cmp_manifest_issued', 'none' ), 'none', 'fetch_manifest: invalid manifest does not touch the counter' );

// Expired manifest -> state expired, counter still updated.
cmp_t_reset_wp();
cmp_t_cache_manifest( array( 'expires' => gmdate( 'Y-m-d\TH:i:s\Z', $cmp_now - 1 ) ) );
cmp_assert_eq( CMP_Updater::fetch_manifest()['status'], 'expired', 'fetch_manifest: expired manifest -> expired' );
cmp_assert_eq( get_site_option( 'cmp_update_state' ), array( 'expired' => true, 'revoked_installed' => false, 'no_sodium' => false ), 'fetch_manifest: state expired saved' );
cmp_assert_eq( get_site_option( 'cmp_manifest_issued' ), array( 'beta' => $t_issued ), 'fetch_manifest: expired but signed manifest still updates the counter' );

// Cache of another channel is ignored.
cmp_t_reset_wp();
set_site_transient( CMP_Updater::CACHE_KEY, array( 'channel' => 'stable', 'json' => 'x', 'sig' => 'y' ), 21600 );
CMP_Updater::fetch_manifest();
cmp_assert_eq( array_column( $GLOBALS['cmp_upd_http']['calls'], 'url' ), array( 'https://biscuitwp.rt.md/manifest-beta.json' ), 'fetch_manifest: cache of another channel -> refetched (manifest failed, so no .sig request)' );

// No sodium -> no_sodium saved, no request.
cmp_t_reset_wp( array( 'cmp_update_state' => array( 'expired' => true, 'revoked_installed' => true, 'no_sodium' => false ) ) );
cmp_t_seam( array( 'sodium' => false ) + $cmp_seam );
$r = CMP_Updater::fetch_manifest();
cmp_assert_eq( $r['status'], 'invalid', 'fetch_manifest: no sodium -> invalid' );
cmp_assert_eq( get_site_option( 'cmp_update_state' ), array( 'expired' => true, 'revoked_installed' => true, 'no_sodium' => true ), 'fetch_manifest: no sodium -> no_sodium saved, previous expired/revoked flags kept' );
cmp_assert_eq( count( $GLOBALS['cmp_upd_http']['calls'] ), 0, 'fetch_manifest: no sodium -> no request' );
cmp_t_reset_wp();
cmp_t_seam( array( 'sodium' => false ) + $cmp_seam );
CMP_Updater::fetch_manifest();
cmp_assert_eq( get_site_option( 'cmp_update_state' ), array( 'expired' => false, 'revoked_installed' => false, 'no_sodium' => true ), 'fetch_manifest: no sodium, no saved state -> only no_sodium' );
cmp_t_seam( $cmp_seam );

// Bodies at or above MAX_MANIFEST are treated as truncated (limit_response_size cut them): unavailable.
$t_pad  = cmp_t_manifest( array( 'channel' => 'beta', 'notes' => array( array( 'version' => '1.0.0', 'items' => array( '' ) ) ) ) );
$t_full = cmp_t_manifest( array( 'channel' => 'beta', 'notes' => array( array( 'version' => '1.0.0', 'items' => array( str_repeat( 'x', CMP_Updater::MAX_MANIFEST - strlen( $t_pad ) ) ) ) ) ) );
cmp_assert_eq( strlen( $t_full ), CMP_Updater::MAX_MANIFEST, 'fixture: manifest exactly MAX_MANIFEST bytes' );
cmp_assert_eq( cmp_t_eval( $t_full, null, array(), 'beta' )['status'], 'ok', 'fixture: that manifest alone is a valid signed manifest' );
foreach ( array( 'manifest' => array( $t_full, cmp_t_sign( $t_full, $cmp_kp_m ) ), 'sig' => array( $t_json, str_repeat( 'A', CMP_Updater::MAX_MANIFEST ) ) ) as $t_which => $t_bodies ) {
	cmp_t_reset_wp( array( 'cmp_update_state' => array( 'expired' => true, 'revoked_installed' => false, 'no_sodium' => false ) ) );
	$GLOBALS['cmp_upd_http']['handler'] = function ( $url, $args ) use ( $t_bodies ) {
		$is_sig = ( '.sig' === substr( $url, -4 ) );
		return array( 'response' => array( 'code' => 200 ), 'body' => $is_sig ? $t_bodies[1] : $t_bodies[0] );
	};
	cmp_assert_eq( CMP_Updater::fetch_manifest()['status'], 'invalid', "fetch_manifest: {$t_which} body of MAX_MANIFEST bytes -> unavailable" );
	cmp_assert_eq( get_site_option( 'cmp_update_state' ), array( 'expired' => true, 'revoked_installed' => false, 'no_sodium' => false ), "fetch_manifest: truncated {$t_which} keeps the saved state" );
	cmp_assert( ! empty( $GLOBALS['cmp_un']['site'][ '_site_transient_' . CMP_Updater::CACHE_KEY ]['fail'] ), "fetch_manifest: truncated {$t_which} cached as a failure" );
}

// Silent freeze: when the manifest is unavailable and the newest verified one
// (cmp_manifest_issued[channel]) is older than EXPIRY_DAYS, the state turns expired;
// the other flags are kept.
$t_old    = $cmp_now - 61 * 86400;
$t_recent = $cmp_now - 10 * 86400;
$t_flags  = array( 'expired' => false, 'revoked_installed' => true, 'no_sodium' => false );
$t_expd   = array( 'expired' => true, 'revoked_installed' => true, 'no_sodium' => false );
cmp_t_reset_wp( array( 'cmp_manifest_issued' => array( 'stable' => $cmp_now, 'beta' => $t_old ), 'cmp_update_state' => $t_flags ) );
cmp_assert_eq( CMP_Updater::fetch_manifest()['status'], 'invalid', 'freeze: network failure -> invalid' );
cmp_assert_eq( get_site_option( 'cmp_update_state' ), $t_expd, 'freeze: seen 61 days ago + network failure -> expired, other flags kept' );
update_site_option( 'cmp_update_state', $t_flags );
CMP_Updater::fetch_manifest();
cmp_assert_eq( get_site_option( 'cmp_update_state' ), $t_expd, 'freeze: seen 61 days ago + cached failure -> expired' );
cmp_t_reset_wp( array( 'cmp_manifest_issued' => array( 'beta' => $t_recent ), 'cmp_update_state' => $t_flags ) );
CMP_Updater::fetch_manifest();
cmp_assert_eq( get_site_option( 'cmp_update_state' ), $t_flags, 'freeze: seen 10 days ago + network failure -> expired unchanged' );
CMP_Updater::fetch_manifest();
cmp_assert_eq( get_site_option( 'cmp_update_state' ), $t_flags, 'freeze: seen 10 days ago + cached failure -> expired unchanged' );
cmp_t_reset_wp( array( 'cmp_manifest_issued' => array( 'stable' => $t_old ), 'cmp_update_state' => $t_flags ) );
CMP_Updater::fetch_manifest();
cmp_assert_eq( get_site_option( 'cmp_update_state' ), $t_flags, 'freeze: an old manifest seen on another channel does not count' );
cmp_t_reset_wp( array( 'cmp_update_state' => $t_flags ) );
CMP_Updater::fetch_manifest();
cmp_assert_eq( get_site_option( 'cmp_update_state' ), $t_flags, 'freeze: nothing ever seen -> expired unchanged' );
cmp_t_reset_wp( array( 'cmp_manifest_issued' => array( 'beta' => $cmp_now - 60 * 86400 ), 'cmp_update_state' => $t_flags ) );
CMP_Updater::fetch_manifest();
cmp_assert_eq( get_site_option( 'cmp_update_state' ), $t_flags, 'freeze: exactly EXPIRY_DAYS old -> not yet expired' );
// Non-200.
cmp_t_reset_wp( array( 'cmp_manifest_issued' => array( 'beta' => $t_old ), 'cmp_update_state' => $t_flags ) );
$GLOBALS['cmp_upd_http']['handler'] = function () {
	return array( 'response' => array( 'code' => 503 ), 'body' => 'down' );
};
CMP_Updater::fetch_manifest();
cmp_assert_eq( get_site_option( 'cmp_update_state' ), $t_expd, 'freeze: seen 61 days ago + HTTP 503 -> expired' );
// Truncated body.
cmp_t_reset_wp( array( 'cmp_manifest_issued' => array( 'beta' => $t_old ), 'cmp_update_state' => $t_flags ) );
$GLOBALS['cmp_upd_http']['handler'] = function () {
	return array( 'response' => array( 'code' => 200 ), 'body' => str_repeat( 'x', CMP_Updater::MAX_MANIFEST ) );
};
CMP_Updater::fetch_manifest();
cmp_assert_eq( get_site_option( 'cmp_update_state' ), $t_expd, 'freeze: seen 61 days ago + truncated body -> expired' );
// Invalid signature (fetched from the network).
cmp_t_reset_wp( array( 'cmp_manifest_issued' => array( 'beta' => $t_old ), 'cmp_update_state' => $t_flags ) );
$t_json_x = cmp_t_manifest( array( 'channel' => 'beta' ) );
$GLOBALS['cmp_upd_http']['handler'] = function ( $url ) use ( $t_json_x ) {
	return array( 'response' => array( 'code' => 200 ), 'body' => ( '.sig' === substr( $url, -4 ) ) ? cmp_t_sign( $t_json_x, $GLOBALS['cmp_kp_x'] ) : $t_json_x );
};
cmp_assert_eq( CMP_Updater::fetch_manifest()['status'], 'invalid', 'freeze: foreign signature -> invalid' );
cmp_assert_eq( get_site_option( 'cmp_update_state' ), $t_expd, 'freeze: seen 61 days ago + invalid signature -> expired' );
cmp_assert_eq( get_site_option( 'cmp_manifest_issued' ), array( 'beta' => $t_old ), 'freeze: the counter is never touched by an unavailable manifest' );
// Invalid fields (cached, validly signed).
cmp_t_reset_wp( array( 'cmp_manifest_issued' => array( 'beta' => $t_old ), 'cmp_update_state' => $t_flags ) );
cmp_t_cache_manifest( array( 'slug' => 'other-plugin' ) );
CMP_Updater::fetch_manifest();
cmp_assert_eq( get_site_option( 'cmp_update_state' ), $t_expd, 'freeze: seen 61 days ago + invalid fields -> expired' );
// Rollback: a replayed manifest older than the newest one seen.
cmp_t_reset_wp( array( 'cmp_manifest_issued' => array( 'beta' => $t_old ), 'cmp_update_state' => $t_flags ) );
cmp_t_cache_manifest( array( 'issued' => gmdate( 'Y-m-d\TH:i:s\Z', $t_old - 86400 ), 'expires' => gmdate( 'Y-m-d\TH:i:s\Z', $t_old + 50 * 86400 ) ) );
cmp_assert_eq( CMP_Updater::fetch_manifest()['status'], 'invalid', 'freeze: replayed older manifest -> invalid' );
cmp_assert_eq( get_site_option( 'cmp_update_state' ), $t_expd, 'freeze: seen 61 days ago + rollback -> expired' );
// The no_sodium flag is kept too.
cmp_t_reset_wp( array( 'cmp_manifest_issued' => array( 'beta' => $t_old ), 'cmp_update_state' => array( 'expired' => false, 'revoked_installed' => false, 'no_sodium' => true ) ) );
CMP_Updater::fetch_manifest();
cmp_assert_eq( get_site_option( 'cmp_update_state' ), array( 'expired' => true, 'revoked_installed' => false, 'no_sodium' => true ), 'freeze: no_sodium flag kept' );
// A fresh verified manifest clears it again.
cmp_t_reset_wp( array( 'cmp_manifest_issued' => array( 'beta' => $t_old ), 'cmp_update_state' => $t_expd ) );
cmp_t_cache_manifest();
cmp_assert_eq( CMP_Updater::fetch_manifest()['status'], 'ok', 'freeze: fresh manifest -> ok' );
cmp_assert_eq( get_site_option( 'cmp_update_state' ), array( 'expired' => false, 'revoked_installed' => false, 'no_sodium' => false ), 'freeze: fresh verified manifest clears expired' );

// Every request goes through wp_safe_remote_get (no redirects to private addresses).
cmp_assert( false === strpos( $t_src, 'wp_remote_get(' ) && substr_count( $t_src, 'wp_safe_remote_get(' ) >= 2, 'updater: only wp_safe_remote_get, never wp_remote_get' );

// check_result: what "Caută actualizări" reports.
cmp_assert_eq( CMP_Updater::check_result( array( 'status' => 'ok', 'offer' => true, 'manifest' => array( 'version' => '1.2.3' ) ) ), '1.2.3', 'check_result: ok + offer -> the version' );
cmp_assert_eq( CMP_Updater::check_result( array( 'status' => 'ok', 'offer' => false, 'manifest' => array( 'version' => '0.1' ) ) ), 'latest', 'check_result: ok without offer -> latest' );
cmp_assert_eq( CMP_Updater::check_result( array( 'status' => 'expired', 'offer' => false, 'manifest' => array( 'version' => '9' ) ) ), 'unavailable', 'check_result: expired -> unavailable' );
cmp_assert_eq( CMP_Updater::check_result( array( 'status' => 'invalid', 'offer' => false, 'manifest' => null ) ), 'unavailable', 'check_result: invalid/unavailable -> unavailable' );
cmp_assert_eq( CMP_Updater::check_result( array() ), 'unavailable', 'check_result: malformed -> unavailable' );

// update_check.
cmp_t_reset_wp();
cmp_t_cache_manifest();
$t_sentinel = array( 'version' => '9.9.9' );
cmp_assert_eq( CMP_Updater::update_check( $t_sentinel, array(), 'other-plugin/other.php', array() ), $t_sentinel, 'update_check: foreign plugin file -> $update unchanged' );
cmp_assert_eq( CMP_Updater::update_check( false, array(), 'other-plugin/other.php', array() ), false, 'update_check: foreign plugin file (false) -> unchanged' );
$u = CMP_Updater::update_check( false, array(), CMP_Updater::file(), array() );
cmp_assert( is_array( $u ), 'update_check: our plugin + ok manifest with offer -> array' );
cmp_assert_eq( $u['version'], '1.0.0', 'update_check: version' );
cmp_assert_eq( $u['package'], $cmp_pkg_url, 'update_check: package' );
cmp_assert_eq( $u['slug'], 'consent-manager-pro', 'update_check: slug' );
cmp_assert_eq( $u['requires_php'], '7.4', 'update_check: requires_php' );
cmp_assert_eq( $u['tested'], '6.9', 'update_check: tested (from tested_wp)' );
cmp_assert_eq( $u['requires'], '6.0', 'update_check: requires (from requires_wp)' );
cmp_assert( isset( $u['id'], $u['url'] ), 'update_check: id and url present' );
cmp_assert_eq( $u['id'], 'biscuitwp.rt.md/consent-manager-pro', 'update_check: id on the update host' );
cmp_assert_eq( $u['url'], 'https://biscuitwp.rt.md/', 'update_check: url is the public site' );
cmp_assert_eq( CMP_Updater::SECURITY_URL, 'https://biscuitwp.rt.md/securitate/', 'SECURITY_URL on the new site' );

// Two entry files with the same header in one folder (<folder>/biscuitwp.php and
// <folder>/consent-manager-pro.php): get_plugins() lists both, so wp_update_plugins()
// asks about each and the upgrader may install through either. Both are ours, in
// either folder, whichever one is active; nothing else is.
$t_legacy_file = 'consent-manager-pro/consent-manager-pro.php';
$t_legacy_sib  = 'consent-manager-pro/biscuitwp.php';
cmp_assert_eq( CMP_Updater::sibling( 'consent-manager-pro/consent-manager-pro.php' ), 'consent-manager-pro/biscuitwp.php', 'sibling: consent-manager-pro.php -> biscuitwp.php in the same folder' );
cmp_assert_eq( CMP_Updater::sibling( 'consent-manager-pro/biscuitwp.php' ), 'consent-manager-pro/consent-manager-pro.php', 'sibling: biscuitwp.php -> consent-manager-pro.php (old folder)' );
cmp_assert_eq( CMP_Updater::sibling( 'biscuitwp/biscuitwp.php' ), 'biscuitwp/consent-manager-pro.php', 'sibling: biscuitwp/biscuitwp.php -> biscuitwp/consent-manager-pro.php' );
cmp_assert_eq( CMP_Updater::sibling( 'biscuitwp/consent-manager-pro.php' ), 'biscuitwp/biscuitwp.php', 'sibling: biscuitwp/consent-manager-pro.php -> biscuitwp/biscuitwp.php' );
cmp_assert_eq( CMP_Updater::sibling( 'akismet/akismet.php' ), '', 'sibling: any other file name has none' );
cmp_assert_eq( CMP_Updater::sibling( 'biscuitwp.php' ), '', 'sibling: a file without a folder has none' );
foreach ( array( $t_legacy_file, $t_legacy_sib, 'biscuitwp/biscuitwp.php', 'biscuitwp/consent-manager-pro.php' ) as $t_active ) {
	cmp_t_seam( array( 'file' => $t_active ) + $cmp_seam );
	$t_sib = CMP_Updater::sibling( $t_active );
	cmp_assert( CMP_Updater::owns( $t_active ) && CMP_Updater::owns( $t_sib ), "owns ($t_active active): the active file and its sibling" );
	cmp_assert( ! CMP_Updater::owns( 'akismet/akismet.php' ) && ! CMP_Updater::owns( '' ) && ! CMP_Updater::owns( null ), "owns ($t_active active): not other plugins" );
	$t_other_dir = 0 === strpos( $t_active, 'biscuitwp/' ) ? 'consent-manager-pro' : 'biscuitwp';
	cmp_assert( ! CMP_Updater::owns( $t_other_dir . '/biscuitwp.php' ) && ! CMP_Updater::owns( $t_other_dir . '/consent-manager-pro.php' ), "owns ($t_active active): not the entry files of another folder" );
	$u1 = CMP_Updater::update_check( false, array(), $t_active, array() );
	$u2 = CMP_Updater::update_check( false, array(), $t_sib, array() );
	cmp_assert( is_array( $u1 ) && is_array( $u2 ) && '1.0.0' === $u1['version'] && $u1 === $u2, "update_check ($t_active active): the active file and its sibling get the same update" );
	cmp_assert_eq( CMP_Updater::update_check( false, array(), $t_other_dir . '/biscuitwp.php', array() ), false, "update_check ($t_active active): an entry file in another folder is not ours" );
}
cmp_t_seam( array( 'file' => $t_legacy_file ) + $cmp_seam );
cmp_assert_eq( CMP_Updater::file(), $t_legacy_file, 'not moved: file() is the active legacy path' );

// One update entry offered: the inactive sibling's entry is dropped on read
// (WordPress files one under each sibling), so nobody updates through it.
$t_src_upd = file_get_contents( __DIR__ . '/../includes/modules/class-updater.php' );
cmp_assert( false !== strpos( $t_src_upd, "add_filter( 'site_transient_update_plugins', array( __CLASS__, 'filter_update_transient' ) );" ) && false === strpos( $t_src_upd, 'rekey_update_transient' ), 'register: update_plugins read filter (single entry), no manual copy' );
$t_ut_mk = function ( array $resp, array $nu = array() ) {
	$t            = new stdClass();
	$t->checked   = array( 'consent-manager-pro/consent-manager-pro.php' => '1.1.0', 'consent-manager-pro/biscuitwp.php' => '1.1.0', 'akismet/akismet.php' => '5.0' );
	$t->response  = array();
	$t->no_update = array();
	foreach ( $resp as $k ) {
		$t->response[ $k ] = (object) array( 'plugin' => $k, 'new_version' => '1.2.0' );
	}
	foreach ( $nu as $k ) {
		$t->no_update[ $k ] = (object) array( 'plugin' => $k, 'new_version' => '1.1.0' );
	}
	return $t;
};
foreach ( array( $t_legacy_file, $t_legacy_sib, 'biscuitwp/biscuitwp.php', 'biscuitwp/consent-manager-pro.php' ) as $t_active ) {
	$t_sib = CMP_Updater::sibling( $t_active );
	$t_in  = $t_ut_mk( array( 'akismet/akismet.php', $t_sib, $t_active ), array( $t_sib, $t_active ) );
	$t_out = CMP_Updater::single_entry( $t_in, $t_active );
	cmp_assert_eq( array_keys( $t_out->response ), array( 'akismet/akismet.php', $t_active ), "update entries ($t_active active): the sibling's response entry dropped, others kept" );
	cmp_assert_eq( array_keys( $t_out->no_update ), array( $t_active ), "update entries ($t_active active): the sibling's no_update entry dropped" );
	cmp_assert( isset( $t_in->response[ $t_sib ] ) && $t_out->checked === $t_in->checked, "update entries ($t_active active): input object untouched, checked kept" );
	$t_out = CMP_Updater::single_entry( $t_ut_mk( array( 'akismet/akismet.php', $t_sib, 'hello.php' ) ), $t_active );
	cmp_assert( array( 'akismet/akismet.php', $t_active, 'hello.php' ) === array_keys( $t_out->response ) && $t_active === $t_out->response[ $t_active ]->plugin && '1.2.0' === $t_out->response[ $t_active ]->new_version, "update entries ($t_active active): only the sibling has one -> moved to the active key, in place" );
}
$t_arr                                   = $t_ut_mk( array() );
$t_arr->response[ $t_legacy_sib ]        = array( 'plugin' => $t_legacy_sib, 'new_version' => '1.2.0' );
$t_out                                   = CMP_Updater::single_entry( $t_arr, $t_legacy_file );
cmp_assert( isset( $t_out->response[ $t_legacy_file ]['plugin'] ) && $t_legacy_file === $t_out->response[ $t_legacy_file ]['plugin'] && ! isset( $t_out->response[ $t_legacy_sib ] ), 'update entries: array-shaped entry moved too' );
cmp_assert_eq( CMP_Updater::single_entry( false, $t_legacy_file ), false, 'update entries: no transient passes through' );
$t_plain = $t_ut_mk( array( 'akismet/akismet.php' ) );
cmp_assert( CMP_Updater::single_entry( $t_plain, $t_legacy_file ) == $t_plain && CMP_Updater::single_entry( $t_plain, 'weird.php' ) == $t_plain, 'update entries: nothing of ours, or no sibling name -> unchanged' );
cmp_t_seam( array( 'file' => $t_legacy_file, 'core_check' => true ) + $cmp_seam );
$t_both = $t_ut_mk( array( $t_legacy_file, $t_legacy_sib ) );
cmp_assert( CMP_Updater::filter_update_transient( $t_both ) == $t_both, 'filter: inside core update check, the transient is read as stored' );
cmp_t_seam( array( 'file' => $t_legacy_file, 'core_check' => false ) + $cmp_seam );
cmp_assert_eq( array_keys( CMP_Updater::filter_update_transient( $t_both )->response ), array( $t_legacy_file ), 'filter: elsewhere, one entry (the active file)' );
cmp_t_seam( $cmp_seam );
cmp_assert_eq( CMP_Updater::in_core_update_check(), false, 'in_core_update_check: false outside WordPress actions' );
foreach ( array( 'load-plugins.php', 'load-update.php', 'load-update-core.php', 'wp_update_plugins' ) as $t_hook ) {
	cmp_assert( false !== strpos( $t_src_upd, "'" . $t_hook . "'" ), "in_core_update_check: covers {$t_hook}" );
}
cmp_assert( false === strpos( $t_src_upd, 'is_legacy' ) && false === strpos( $t_src_upd, 'CMP_LEGACY_LOAD' ), 'updater: nothing depends on how the plugin was loaded' );

// fix_source_dir: hook_extra['plugin'] is either entry file; the extracted
// biscuitwp/ (Task 3 zip) is renamed to the plugin's existing folder.
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
}
$t_fs_prev = isset( $GLOBALS['wp_filesystem'] ) ? $GLOBALS['wp_filesystem'] : null;
$GLOBALS['wp_filesystem'] = new class() {
	public function exists( $p ) { return file_exists( $p ); }
	public function delete( $p, $r = false ) { return false; }
	public function move( $a, $b ) { return rename( $a, $b ); }
};
$t_fsd = function ( $seam, $plugin ) use ( $cmp_seam ) {
	$dir = realpath( sys_get_temp_dir() ) . '/cmp-fsd-' . getmypid() . '-' . mt_rand();
	@mkdir( $dir . '/upgrade/biscuitwp', 0777, true );
	file_put_contents( $dir . '/upgrade/biscuitwp/biscuitwp.php', '<?php' );
	cmp_t_seam( $seam + $cmp_seam );
	$ret   = CMP_Updater::fix_source_dir( $dir . '/upgrade/biscuitwp/', $dir . '/upgrade', null, array( 'plugin' => $plugin ) );
	$moved = is_file( $dir . '/upgrade/consent-manager-pro/biscuitwp.php' ) && ! is_dir( $dir . '/upgrade/biscuitwp' );
	$ret   = str_replace( $dir, '<dir>', (string) $ret );
	foreach ( array( 'consent-manager-pro', 'biscuitwp' ) as $d ) {
		@unlink( $dir . '/upgrade/' . $d . '/biscuitwp.php' );
		@rmdir( $dir . '/upgrade/' . $d );
	}
	@rmdir( $dir . '/upgrade' );
	@rmdir( $dir );
	return array( $ret, $moved );
};
$t_legacy_seam = array( 'file' => $t_legacy_file );
list( $t_r, $t_m ) = $t_fsd( $t_legacy_seam, $t_legacy_file );
cmp_assert( '<dir>/upgrade/consent-manager-pro/' === $t_r && $t_m, 'fix_source_dir (not moved, active key): extracted biscuitwp/ becomes consent-manager-pro/' );
list( $t_r, $t_m ) = $t_fsd( $t_legacy_seam, $t_legacy_sib );
cmp_assert( '<dir>/upgrade/consent-manager-pro/' === $t_r && $t_m, 'fix_source_dir (not moved, sibling key): extracted biscuitwp/ becomes consent-manager-pro/' );
list( $t_r, $t_m ) = $t_fsd( array( 'file' => $t_legacy_sib ), $t_legacy_file );
cmp_assert( '<dir>/upgrade/consent-manager-pro/' === $t_r && $t_m, 'fix_source_dir (biscuitwp.php active in the old folder, sibling key): installs in place' );
list( $t_r, $t_m ) = $t_fsd( $t_legacy_seam, 'akismet/akismet.php' );
cmp_assert( '<dir>/upgrade/biscuitwp/' === $t_r && ! $t_m, 'fix_source_dir: a plugin we do not own is ignored' );
list( $t_r, $t_m ) = $t_fsd( $t_legacy_seam, 'biscuitwp/biscuitwp.php' );
cmp_assert( '<dir>/upgrade/biscuitwp/' === $t_r && ! $t_m, 'fix_source_dir (not moved): biscuitwp.php in another folder is not ours' );
// Moved site (biscuitwp/biscuitwp.php active): an extracted folder with any
// other name (e.g. a GitHub source zip consent-manager-pro-1.1.0/) becomes biscuitwp/,
// through either entry file.
foreach ( array( 'biscuitwp/biscuitwp.php', 'biscuitwp/consent-manager-pro.php' ) as $t_key ) {
	$t_dir = realpath( sys_get_temp_dir() ) . '/cmp-fsd2-' . getmypid() . '-' . mt_rand();
	@mkdir( $t_dir . '/upgrade/consent-manager-pro-1.1.0', 0777, true );
	file_put_contents( $t_dir . '/upgrade/consent-manager-pro-1.1.0/biscuitwp.php', '<?php' );
	cmp_t_seam( array( 'file' => 'biscuitwp/biscuitwp.php' ) + $cmp_seam );
	$t_r = str_replace( $t_dir, '<dir>', (string) CMP_Updater::fix_source_dir( $t_dir . '/upgrade/consent-manager-pro-1.1.0/', $t_dir . '/upgrade', null, array( 'plugin' => $t_key ) ) );
	$t_m = is_file( $t_dir . '/upgrade/biscuitwp/biscuitwp.php' ) && ! is_dir( $t_dir . '/upgrade/consent-manager-pro-1.1.0' );
	cmp_assert( '<dir>/upgrade/biscuitwp/' === $t_r && $t_m, "fix_source_dir (moved site, $t_key): extracted consent-manager-pro-1.1.0/ becomes biscuitwp/" );
	$t_r = str_replace( $t_dir, '<dir>', (string) CMP_Updater::fix_source_dir( $t_dir . '/upgrade/biscuitwp/', $t_dir . '/upgrade', null, array( 'plugin' => $t_key ) ) );
	cmp_assert( '<dir>/upgrade/biscuitwp/' === $t_r && is_file( $t_dir . '/upgrade/biscuitwp/biscuitwp.php' ), "fix_source_dir (moved site, $t_key): extracted biscuitwp/ is already in place" );
	foreach ( array( 'consent-manager-pro-1.1.0', 'biscuitwp' ) as $t_d ) {
		@unlink( $t_dir . '/upgrade/' . $t_d . '/biscuitwp.php' );
		@rmdir( $t_dir . '/upgrade/' . $t_d );
	}
	@rmdir( $t_dir . '/upgrade' );
	@rmdir( $t_dir );
}
// R1-I1: the rename to the installed folder fails (or there is no filesystem):
// our plugin gets a WP_Error, so core aborts before touching the installed plugin.
$t_fsfail = function ( $fs, $plugin, $active = 'biscuitwp/biscuitwp.php' ) use ( $cmp_seam ) {
	$dir = realpath( sys_get_temp_dir() ) . '/cmp-fsd3-' . getmypid() . '-' . mt_rand();
	@mkdir( $dir . '/upgrade/consent-manager-pro', 0777, true );
	file_put_contents( $dir . '/upgrade/consent-manager-pro/biscuitwp.php', '<?php' );
	$GLOBALS['wp_filesystem'] = $fs;
	cmp_t_seam( array( 'file' => $active ) + $cmp_seam );
	$ret = CMP_Updater::fix_source_dir( $dir . '/upgrade/consent-manager-pro/', $dir . '/upgrade', null, array( 'plugin' => $plugin ) );
	$kept = is_file( $dir . '/upgrade/consent-manager-pro/biscuitwp.php' );
	@unlink( $dir . '/upgrade/consent-manager-pro/biscuitwp.php' );
	@rmdir( $dir . '/upgrade/consent-manager-pro' );
	@rmdir( $dir . '/upgrade' );
	@rmdir( $dir );
	return array( is_string( $ret ) ? str_replace( $dir, '<dir>', $ret ) : $ret, $kept );
};
$t_nomove = new class() {
	public function exists( $p ) { return false; }
	public function delete( $p, $r = false ) { return false; }
	public function move( $a, $b ) { return false; }
};
foreach ( array( 'biscuitwp/biscuitwp.php', 'biscuitwp/consent-manager-pro.php' ) as $t_key ) {
	list( $t_r, $t_k ) = $t_fsfail( $t_nomove, $t_key );
	cmp_assert( is_wp_error( $t_r ) && 'cmp_source_dir' === $t_r->get_error_code() && $t_k, "fix_source_dir (R1-I1, $t_key): rename fails -> WP_Error cmp_source_dir, extracted folder left alone" );
}
list( $t_r, $t_k ) = $t_fsfail( null, 'biscuitwp/biscuitwp.php' );
cmp_assert( is_wp_error( $t_r ), 'fix_source_dir (R1-I1): no filesystem object -> WP_Error' );
list( $t_r, $t_k ) = $t_fsfail( $t_nomove, 'akismet/akismet.php' );
cmp_assert( '<dir>/upgrade/consent-manager-pro/' === $t_r, 'fix_source_dir (R1-I1): another plugin is returned untouched even when a move would fail' );
list( $t_r, $t_k ) = $t_fsfail( $t_nomove, 'consent-manager-pro/consent-manager-pro.php', 'consent-manager-pro/consent-manager-pro.php' );
cmp_assert( '<dir>/upgrade/consent-manager-pro/' === $t_r, 'fix_source_dir (R1-I1): no rename needed (folder already matches) -> source returned, no error' );
$GLOBALS['wp_filesystem'] = $t_fs_prev;

// verified_download: both entry files count as ours ($by_hook): a foreign URL is
// refused for either, a plugin we do not own is left alone.
foreach ( array( $t_legacy_file, 'biscuitwp/biscuitwp.php' ) as $t_active ) {
	cmp_t_seam( array( 'file' => $t_active ) + $cmp_seam );
	cmp_assert( is_wp_error( CMP_Updater::verified_download( false, 'https://evil.example/x.zip', null, array( 'plugin' => CMP_Updater::sibling( $t_active ) ) ) ), "verified_download ($t_active active): the sibling is ours, foreign URL refused" );
	cmp_assert( is_wp_error( CMP_Updater::verified_download( false, 'https://evil.example/x.zip', null, array( 'plugin' => $t_active ) ) ), "verified_download ($t_active active): the active file is ours, foreign URL refused" );
	cmp_assert_eq( CMP_Updater::verified_download( false, 'https://example.com/a.zip', null, array( 'plugin' => 'akismet/akismet.php' ) ), false, "verified_download ($t_active active): a plugin we do not own is left alone" );
}

// auto_update_plugin (PHP_INT_MAX): the sibling that is not active is never
// auto-updated; the active file and every other plugin keep the decision.
foreach ( array( $t_legacy_file, $t_legacy_sib, 'biscuitwp/biscuitwp.php', 'biscuitwp/consent-manager-pro.php' ) as $t_active ) {
	cmp_t_seam( array( 'file' => $t_active ) + $cmp_seam );
	$t_sib = CMP_Updater::sibling( $t_active );
	cmp_assert( false === CMP_Updater::skip_duplicate_auto_update( true, (object) array( 'plugin' => $t_sib ) ) && false === CMP_Updater::skip_duplicate_auto_update( null, array( 'plugin' => $t_sib ) ), "auto_update_plugin ($t_active active): the inactive sibling -> false (object and array items)" );
	cmp_assert( true === CMP_Updater::skip_duplicate_auto_update( true, (object) array( 'plugin' => $t_active ) ) && false === CMP_Updater::skip_duplicate_auto_update( false, (object) array( 'plugin' => $t_active ) ) && null === CMP_Updater::skip_duplicate_auto_update( null, (object) array( 'plugin' => $t_active ) ), "auto_update_plugin ($t_active active): the active file passes the decision through" );
}
cmp_t_seam( array( 'file' => $t_legacy_file ) + $cmp_seam );
cmp_assert_eq( CMP_Updater::skip_duplicate_auto_update( true, (object) array( 'plugin' => 'akismet/akismet.php' ) ), true, 'auto_update_plugin: other plugin passes through' );
cmp_assert_eq( CMP_Updater::skip_duplicate_auto_update( true, (object) array( 'plugin' => 'biscuitwp/biscuitwp.php' ) ), true, 'auto_update_plugin: biscuitwp.php in another folder passes through' );
cmp_assert_eq( CMP_Updater::skip_duplicate_auto_update( true, (object) array() ), true, 'auto_update_plugin: item without plugin passes through' );
cmp_t_seam( $cmp_seam );
cmp_assert( false !== strpos( $t_src_upd, "add_filter( 'auto_update_plugin', array( __CLASS__, 'skip_duplicate_auto_update' ), PHP_INT_MAX, 2 );" ) && false !== strpos( $t_src_upd, "add_filter( 'wp_get_update_data', array( __CLASS__, 'filter_update_data' ), 10, 2 );" ), 'register: auto_update_plugin (PHP_INT_MAX) and wp_get_update_data filters, on every load' );
cmp_assert_eq( substr_count( $t_src_upd, "'auto_update_plugin'" ), 1, 'register: auto_update_plugin registered in one place' );

// wp_get_update_data: our plugin counted once.
$t_ud = array(
	'counts' => array( 'plugins' => 3, 'themes' => 1, 'wordpress' => 0, 'translations' => 0, 'total' => 4 ),
	'title'  => '3 Plugin Updates, 1 Theme Update',
);
$t_ut = array( 'plugins' => '3 Plugin Updates', 'themes' => '1 Theme Update' );
$t_dd = CMP_Updater::dedupe_update_data( $t_ud, $t_ut, true );
cmp_assert( 2 === $t_dd['counts']['plugins'] && 3 === $t_dd['counts']['total'] && 1 === $t_dd['counts']['themes'], 'update data (duplicate): plugins and total minus 1, others kept' );
cmp_assert_eq( $t_dd['title'], '2 Plugin Updates, 1 Theme Update', 'update data (duplicate): title rebuilt with the new plugin count' );
cmp_assert_eq( CMP_Updater::dedupe_update_data( $t_ud, $t_ut, false ), $t_ud, 'update data (no duplicate): unchanged' );
$t_ud1 = array( 'counts' => array( 'plugins' => 2, 'themes' => 0, 'wordpress' => 0, 'translations' => 0, 'total' => 2 ), 'title' => '2 Plugin Updates' );
$t_dd  = CMP_Updater::dedupe_update_data( $t_ud1, array( 'plugins' => '2 Plugin Updates' ), true );
cmp_assert( 1 === $t_dd['counts']['plugins'] && 1 === $t_dd['counts']['total'] && '1 Plugin Update' === $t_dd['title'], 'update data (duplicate): 2 -> 1, singular title' );
$t_ud0 = array( 'counts' => array( 'plugins' => 0, 'themes' => 0, 'wordpress' => 0, 'translations' => 0, 'total' => 0 ), 'title' => '' );
cmp_assert_eq( CMP_Updater::dedupe_update_data( $t_ud0, array(), true ), $t_ud0, 'update data (duplicate claimed, counts already 0): never below 0, unchanged' );
cmp_assert_eq( CMP_Updater::dedupe_update_data( array( 'title' => 'x' ), array(), true ), array( 'title' => 'x' ), 'update data: malformed input passes through' );

// filter_update_data reads the update_plugins transient: both siblings -> counted once.
$t_mk = function ( array $keys ) {
	$t           = new stdClass();
	$t->checked  = array();
	$t->response = array( 'akismet/akismet.php' => (object) array( 'plugin' => 'akismet/akismet.php' ) );
	foreach ( $keys as $k ) {
		$t->response[ $k ] = (object) array( 'plugin' => $k, 'new_version' => '1.1.0' );
	}
	return $t;
};
$t_un_prev = $GLOBALS['cmp_un'];
foreach ( array( $t_legacy_file, 'biscuitwp/biscuitwp.php' ) as $t_active ) {
	cmp_t_seam( array( 'file' => $t_active ) + $cmp_seam );
	$t_sib = CMP_Updater::sibling( $t_active );
	$GLOBALS['cmp_un']['site']['_site_transient_update_plugins'] = $t_mk( array( $t_active, $t_sib ) );
	$t_dd = CMP_Updater::filter_update_data( $t_ud, $t_ut );
	cmp_assert( 2 === $t_dd['counts']['plugins'] && 3 === $t_dd['counts']['total'], "filter_update_data ($t_active active, both siblings pending): counted once" );
	$GLOBALS['cmp_un']['site']['_site_transient_update_plugins'] = $t_mk( array( $t_active ) );
	cmp_assert_eq( CMP_Updater::filter_update_data( $t_ud, $t_ut ), $t_ud, "filter_update_data ($t_active active, one key only): unchanged" );
	$GLOBALS['cmp_un']['site']['_site_transient_update_plugins'] = $t_mk( array() );
	cmp_assert_eq( CMP_Updater::filter_update_data( $t_ud, $t_ut ), $t_ud, "filter_update_data ($t_active active, no update of ours): unchanged" );
}
cmp_t_seam( $cmp_seam );
$GLOBALS['cmp_un'] = $t_un_prev;
cmp_t_seam( $cmp_seam );
cmp_t_reset_wp();
cmp_t_cache_manifest( array( 'version' => CMP_VERSION ) );
// ok without an offer: an entry WordPress files under no_update (enables the auto-update toggle).
$u = CMP_Updater::update_check( false, array(), CMP_Updater::file(), array() );
cmp_assert( is_array( $u ), 'update_check: ok without offer -> array (no_update entry)' );
cmp_assert_eq( $u['version'], CMP_VERSION, 'update_check: no offer -> version === CMP_VERSION' );
cmp_assert_eq( $u['package'], '', 'update_check: no offer -> empty package' );
cmp_assert_eq( $u['slug'], 'consent-manager-pro', 'update_check: no offer -> slug' );
cmp_assert_eq( $u['tested'], '6.9', 'update_check: no offer -> descriptive fields kept (tested)' );
cmp_assert( isset( $u['id'], $u['url'], $u['requires_php'], $u['requires'] ), 'update_check: no offer -> id, url, requires_php, requires present' );
cmp_t_reset_wp();
cmp_t_cache_manifest( array( 'revoked' => array( '1.0.0' ) ) );
$u = CMP_Updater::update_check( false, array(), CMP_Updater::file(), array() );
cmp_assert( is_array( $u ) && CMP_VERSION === $u['version'] && '' === $u['package'], 'update_check: revoked manifest version -> still CMP_VERSION, no package' );
cmp_t_reset_wp();
cmp_t_cache_manifest( array( 'requires_php' => '99.0' ) );
$u = CMP_Updater::update_check( false, array(), CMP_Updater::file(), array() );
cmp_assert( is_array( $u ) && CMP_VERSION === $u['version'] && '' === $u['package'], 'update_check: incompatible manifest version -> still CMP_VERSION, no package' );
cmp_t_reset_wp();
cmp_t_cache_manifest( array(), $cmp_kp_x );
cmp_assert_eq( CMP_Updater::update_check( false, array(), CMP_Updater::file(), array() ), false, 'update_check: invalid manifest -> false' );
cmp_t_reset_wp();
cmp_t_cache_manifest( array( 'expires' => gmdate( 'Y-m-d\TH:i:s\Z', $cmp_now - 1 ) ) );
cmp_assert_eq( CMP_Updater::update_check( false, array(), CMP_Updater::file(), array() ), false, 'update_check: expired manifest -> false' );
cmp_t_reset_wp();
cmp_assert_eq( CMP_Updater::update_check( false, array(), CMP_Updater::file(), array() ), false, 'update_check: network failure -> false' );

// plugin_info + changelog_html.
cmp_t_reset_wp();
cmp_t_cache_manifest( array( 'notes' => array( array( 'version' => '1.0.0', 'date' => '2027-03-01', 'items' => array( 'Fix <script>alert(1)</script>', 'B & C' ) ) ) ) );
$t_res  = (object) array( 'sentinel' => 1 );
$t_args = (object) array( 'slug' => 'consent-manager-pro' );
cmp_assert( CMP_Updater::plugin_info( $t_res, 'plugin_information', (object) array( 'slug' => 'akismet' ) ) === $t_res, 'plugin_info: foreign slug -> $res unchanged' );
cmp_assert( CMP_Updater::plugin_info( $t_res, 'query_plugins', $t_args ) === $t_res, 'plugin_info: other action -> $res unchanged' );
cmp_assert( CMP_Updater::plugin_info( false, 'plugin_information', null ) === false, 'plugin_info: no args -> unchanged' );
$t_info = CMP_Updater::plugin_info( false, 'plugin_information', $t_args );
cmp_assert( is_object( $t_info ), 'plugin_info: our slug -> object' );
cmp_assert_eq( $t_info->slug, 'consent-manager-pro', 'plugin_info: slug' );
cmp_assert_eq( $t_info->version, '1.0.0', 'plugin_info: version' );
cmp_assert_eq( $t_info->name, 'BiscuitWP', 'plugin_info: name' );
cmp_assert_eq( $t_info->homepage, 'https://biscuitwp.rt.md/', 'plugin_info: homepage' );
cmp_assert_eq( $t_info->requires, '6.0', 'plugin_info: requires' );
cmp_assert_eq( $t_info->requires_php, '7.4', 'plugin_info: requires_php' );
cmp_assert_eq( $t_info->tested, '6.9', 'plugin_info: tested' );
cmp_assert( isset( $t_info->author ) && '' !== $t_info->author, 'plugin_info: author' );
$t_cl = $t_info->sections['changelog'];
cmp_assert( false === strpos( $t_cl, '<script>' ), 'plugin_info: no raw <script> in changelog' );
cmp_assert( false !== strpos( $t_cl, '&lt;script&gt;alert(1)&lt;/script&gt;' ), 'plugin_info: notes text escaped in changelog' );
cmp_assert_eq(
	CMP_Updater::changelog_html( array( array( 'version' => '1.0.0', 'date' => '2027-03-01', 'items' => array( 'Fix <b>', 'B & C' ) ) ) ),
	'<h4>1.0.0 – 2027-03-01</h4><ul><li>Fix &lt;b&gt;</li><li>B &amp; C</li></ul>',
	'changelog_html: <h4>version – date</h4><ul><li>…</li></ul>, escaped'
);
cmp_assert_eq(
	CMP_Updater::changelog_html( array( 'junk', array( 'version' => '<i>2</i>', 'items' => array( array( 'x' ), 'ok' ) ) ) ),
	'<h4>&lt;i&gt;2&lt;/i&gt;</h4><ul><li>ok</li></ul>',
	'changelog_html: non-array notes and non-string items skipped, version escaped'
);
cmp_assert_eq( CMP_Updater::changelog_html( array() ), '', 'changelog_html: no notes -> empty' );
cmp_t_reset_wp();
$t_err = CMP_Updater::plugin_info( false, 'plugin_information', $t_args );
cmp_assert( is_wp_error( $t_err ), 'plugin_info: our slug without a valid manifest -> WP_Error (never falls back to wordpress.org)' );

// verified_download: fail closed for our packages, foreign ones untouched.
cmp_t_reset_wp();
cmp_t_cache_manifest( array(
	'sha256'    => hash( 'sha256', 'PK good zip' ),
	'signature' => cmp_t_sign( 'PK good zip', $cmp_kp_pkg ),
) );
cmp_assert_eq( CMP_Updater::verified_download( false, 'https://downloads.wordpress.org/plugin/akismet.zip', null, array( 'plugin' => 'akismet/akismet.php' ) ), false, 'verified_download: foreign package -> $reply unchanged (false)' );
cmp_assert_eq( CMP_Updater::verified_download( '/tmp/other.zip', 'https://example.com/x.zip', null, array() ), '/tmp/other.zip', 'verified_download: foreign package -> $reply unchanged (string)' );
$t_e = CMP_Updater::verified_download( false, CMP_Updater::PACKAGE_PREFIX . 'v0.0.1/consent-manager-pro.zip', null, array() );
cmp_assert( is_wp_error( $t_e ), 'verified_download: our prefix but not the manifest package -> WP_Error' );
cmp_assert( false !== strpos( $t_e->get_error_message(), '„Caută actualizări”' ), 'verified_download: refusal hints to run „Caută actualizări” again' );
$t_e = CMP_Updater::verified_download( false, 'https://evil.example/consent-manager-pro.zip', null, array( 'plugin' => CMP_Updater::file() ) );
cmp_assert( is_wp_error( $t_e ), 'verified_download: our plugin (hook_extra) with a foreign URL -> WP_Error' );
$t_e = CMP_Updater::verified_download( '/tmp/someone-else.zip', CMP_Updater::PACKAGE_PREFIX . 'v0.0.1/x.zip', null, array() );
cmp_assert( is_wp_error( $t_e ), 'verified_download: our prefix, $reply already set by someone else -> still WP_Error' );
$t_e = CMP_Updater::verified_download( false, 'https://github.com/rtdesignstudio/biscuitwp/releases/download/v0.0.1/consent-manager-pro.zip', null, array() );
cmp_assert( is_wp_error( $t_e ), 'verified_download: renamed-repo prefix is ours too (by URL) but not the manifest package -> WP_Error' );
cmp_assert_eq( count( $GLOBALS['cmp_upd_http']['calls'] ), 0, 'verified_download: refused packages are never downloaded' );

$t_zip_bytes = 'PK good zip';
$GLOBALS['cmp_upd_http']['handler'] = function ( $url, $args ) use ( &$t_zip_bytes ) {
	file_put_contents( $args['filename'], $t_zip_bytes );
	return array( 'response' => array( 'code' => 200 ), 'body' => '' );
};
$t_ok = CMP_Updater::verified_download( false, $cmp_pkg_url, null, array( 'plugin' => CMP_Updater::file() ) );
cmp_assert( is_string( $t_ok ) && is_file( $t_ok ) && 'PK good zip' === file_get_contents( $t_ok ), 'verified_download: manifest package with good sha256 + signature -> temp file path' );
$t_dl = end( $GLOBALS['cmp_upd_http']['calls'] );
cmp_assert_eq( $t_dl['url'], $cmp_pkg_url, 'verified_download: downloads exactly the manifest package' );
cmp_assert_eq( isset( $t_dl['args']['limit_response_size'] ) ? $t_dl['args']['limit_response_size'] : null, CMP_Updater::MAX_DOWNLOAD, 'verified_download: limit_response_size = MAX_DOWNLOAD' );
cmp_assert_eq( $t_dl['args']['user-agent'], 'Consent-Manager-Pro/' . CMP_VERSION, 'verified_download: anonymous user-agent' );
cmp_assert( ! empty( $t_dl['args']['stream'] ), 'verified_download: streamed to a file' );
if ( is_string( $t_ok ) && is_file( $t_ok ) ) {
	unlink( $t_ok );
}
$t_zip_bytes = 'PK tampered zip';
$t_bad       = CMP_Updater::verified_download( false, $cmp_pkg_url, null, array() );
cmp_assert( is_wp_error( $t_bad ), 'verified_download: tampered package -> WP_Error' );
$t_dl = end( $GLOBALS['cmp_upd_http']['calls'] );
cmp_assert( ! file_exists( $t_dl['args']['filename'] ), 'verified_download: tampered package file deleted' );

// The verified package is refused when the manifest no longer offers it (expired).
cmp_t_reset_wp();
cmp_t_cache_manifest( array(
	'sha256'    => hash( 'sha256', 'PK good zip' ),
	'signature' => cmp_t_sign( 'PK good zip', $cmp_kp_pkg ),
	'expires'   => gmdate( 'Y-m-d\TH:i:s\Z', $cmp_now - 1 ),
) );
cmp_assert( is_wp_error( CMP_Updater::verified_download( false, $cmp_pkg_url, null, array() ) ), 'verified_download: expired manifest -> WP_Error' );

// Notices (pure): which ones show where.
$t_up   = 'https://site.example/wp-admin/update-core.php';
$t_none = array( 'expired' => false, 'revoked_installed' => false, 'no_sodium' => false );
cmp_assert_eq( CMP_Updater::notices( $t_none, 'plugins.php', $t_up ), array(), 'notices: clean state -> none' );
$t_n = CMP_Updater::notices( array( 'revoked_installed' => true ) + $t_none, 'index.php', $t_up );
cmp_assert_eq( count( $t_n ), 1, 'notices: revoked shows on every admin screen (dashboard)' );
cmp_assert( false !== strpos( $t_n[0], 'Versiunea ' . CMP_VERSION . ' a fost retrasă din motive de siguranță și trebuie actualizată.' ), 'notices: revoked text names the installed version' );
cmp_assert( false !== strpos( $t_n[0], 'https://biscuitwp.rt.md/securitate/' ) && false !== strpos( $t_n[0], $t_up ), 'notices: revoked links to updates and the security page' );
cmp_assert( false !== strpos( $t_n[0], 'notice-error' ), 'notices: revoked is an error notice' );
cmp_assert_eq( CMP_Updater::notices( array( 'expired' => true ) + $t_none, 'index.php', $t_up ), array(), 'notices: expired hidden on the dashboard' );
$t_n = CMP_Updater::notices( array( 'expired' => true ) + $t_none, 'plugins.php', $t_up );
cmp_assert( 1 === count( $t_n ) && false !== strpos( $t_n[0], 'Update-urile nu pot fi verificate acum. Dacă mesajul persistă câteva zile, scrie-ne.' ) && false !== strpos( $t_n[0], 'https://biscuitwp.rt.md/securitate/' ), 'notices: expired on plugins.php with the security link' );
cmp_assert_eq( count( CMP_Updater::notices( array( 'expired' => true ) + $t_none, 'consent-manager_page_cmp-services', $t_up ) ), 1, 'notices: expired on the plugin screens (hook contains cmp-)' );
cmp_assert_eq( count( CMP_Updater::notices( array( 'expired' => true ) + $t_none, 'toplevel_page_cmp-settings', $t_up ) ), 1, 'notices: expired on the settings screen' );
cmp_assert_eq( CMP_Updater::notices( array( 'no_sodium' => true ) + $t_none, 'consent-manager_page_cmp-services', $t_up ), array(), 'notices: no_sodium hidden outside plugins.php' );
$t_n = CMP_Updater::notices( array( 'no_sodium' => true ) + $t_none, 'plugins.php', $t_up );
cmp_assert( 1 === count( $t_n ) && false !== strpos( $t_n[0], 'sodium' ), 'notices: no_sodium on plugins.php' );
cmp_assert_eq( count( CMP_Updater::notices( array( 'expired' => true, 'revoked_installed' => true, 'no_sodium' => false ), 'plugins.php', $t_up ) ), 2, 'notices: revoked + expired both shown on plugins.php' );
cmp_assert_eq( CMP_Updater::notices( array( 'revoked_installed' => true ) + $t_none, 'index.php', 'javascript:alert(1)"><x' ) !== array(), true, 'notices: render with an odd URL' );
cmp_assert( false === strpos( implode( '', CMP_Updater::notices( array( 'revoked_installed' => true ) + $t_none, 'index.php', '"><x' ) ), '"><x' ), 'notices: updates URL escaped' );
cmp_assert_eq( CMP_Updater::notices( array( 'garbage' ), 'plugins.php', $t_up ), array(), 'notices: malformed state -> none' );

// Admin wiring: notices only for update_plugins, check_updates uses CACHE_KEY.
$t_admin = file_get_contents( __DIR__ . '/../includes/admin/class-admin.php' );
cmp_assert( 1 === preg_match( "/function update_state_notices\(\)\s*\{\s*if \( ! current_user_can\( 'update_plugins' \) \)/", $t_admin ), 'admin: update_state_notices gated on update_plugins' );
cmp_assert( false !== strpos( $t_admin, "add_action( 'admin_notices', array( \$this, 'update_state_notices' ) )" ), 'admin: update_state_notices hooked on admin_notices' );
cmp_assert( false !== strpos( $t_admin, "add_action( 'network_admin_notices', array( \$this, 'update_state_notices' ) )" ), 'admin: update_state_notices hooked on network_admin_notices' );
cmp_assert( false !== strpos( $t_admin, 'CMP_Updater::check_result( CMP_Updater::fetch_manifest() )' ), 'admin: check_updates reports through check_result' );
cmp_assert( false !== strpos( $t_admin, "'unavailable' === \$v" ) && false !== strpos( $t_admin, 'BiscuitWP: nu am putut verifica update-urile acum. Încearcă din nou mai târziu.' ), 'admin: unavailable notice' );
cmp_assert( false !== strpos( $t_admin, 'delete_site_transient( CMP_Updater::CACHE_KEY )' ), 'admin: check_updates clears the manifest cache (site transient)' );

cmp_t_seam( array() );

// CMP_UPDATE_BASE (staging) overrides the manifest base only; keep LAST (constants persist).
define( 'CMP_UPDATE_BASE', 'https://staging.example/cmp' );
cmp_assert_eq( CMP_Updater::manifest_url( 'stable' ), 'https://staging.example/cmp/manifest-stable.json', 'manifest_url: CMP_UPDATE_BASE (https) overrides the base' );
cmp_assert_eq( CMP_Updater::PACKAGE_PREFIX, 'https://github.com/rtdesignstudio/consent-manager-pro/releases/download/', 'CMP_UPDATE_BASE does not change PACKAGE_PREFIX' );
$t_base = new ReflectionMethod( 'CMP_Updater', 'base' );
$t_base->setAccessible( true );
cmp_assert_eq( $t_base->invoke( null, 'http://staging.example/' ), 'https://biscuitwp.rt.md/', 'base: http override ignored' );
cmp_assert_eq( $t_base->invoke( null, '' ), 'https://biscuitwp.rt.md/', 'base: empty override ignored' );
cmp_assert_eq( $t_base->invoke( null, array( 'https://x/' ) ), 'https://biscuitwp.rt.md/', 'base: non-string override ignored' );
cmp_assert_eq( $t_base->invoke( null, 'https://staging.example/a/' ), 'https://staging.example/a/', 'base: https override kept (one trailing slash)' );

// Multisite: "Caută actualizări" on the network Plugins screen, and back to it.
$t_admin = file_get_contents( __DIR__ . '/../includes/admin/class-admin.php' );
cmp_assert( false !== strpos( $t_admin, "add_filter( 'network_admin_plugin_action_links_' . CMP_Plugin::basename(), array( \$this, 'network_action_links' ) )" ), 'admin: check-updates link on the network Plugins screen' );
cmp_assert( 1 === preg_match( '/function network_action_links\( \$links \) \{.*cmp_check_updates.*\}/sU', $t_admin ), 'admin: network_action_links adds the check link' );
cmp_assert( false !== strpos( $t_admin, "network_admin_url( 'plugins.php' )" ) && false !== strpos( $t_admin, "'/wp-admin/network/'" ), 'admin: check_updates returns to the network Plugins screen when started there' );
cmp_assert( false !== strpos( $t_admin, "add_action( 'network_admin_notices', array( \$this, 'update_check_notice' ) )" ), 'admin: update_check_notice also on network_admin_notices' );
