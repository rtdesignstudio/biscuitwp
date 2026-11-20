<?php
/**
 * B8a: bin/release.sh --dry-run and bin/keygen.sh, driven with temporary keys,
 * a temporary state directory and a temporary CHANGELOG. The produced
 * manifests are checked with the plugin's own evaluate_manifest() and
 * verify_package(). Never touches release/ or ~/.config.
 */
require_once __DIR__ . '/../includes/modules/class-updater.php';

// The baked manifest key (Task 3, Step 5).
cmp_assert_eq( strlen( (string) base64_decode( CMP_Updater::MANIFEST_PUBLIC_KEY, true ) ), 32, 'MANIFEST_PUBLIC_KEY decodes to 32 bytes' );
cmp_assert( CMP_Updater::MANIFEST_PUBLIC_KEY !== CMP_Updater::SIGN_PUBLIC_KEY, 'MANIFEST_PUBLIC_KEY differs from SIGN_PUBLIC_KEY' );

function cmp_rel_has( $bin ) {
	$out = array();
	$rc  = 1;
	exec( 'command -v ' . escapeshellarg( $bin ) . ' 2>/dev/null', $out, $rc );
	return 0 === $rc;
}

/** Run a command in the plugin root with extra env vars; returns [exit code, stdout+stderr]. */
function cmp_rel_run( $cmd, array $env ) {
	$full = getenv();
	foreach ( $env as $k => $v ) {
		if ( null === $v ) {
			unset( $full[ $k ] );
		} else {
			$full[ $k ] = $v;
		}
	}
	$proc = proc_open( $cmd . ' 2>&1', array( 1 => array( 'pipe', 'w' ) ), $pipes, dirname( __DIR__ ), $full );
	if ( ! is_resource( $proc ) ) {
		return array( 255, 'proc_open failed' );
	}
	$out = stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	return array( proc_close( $proc ), (string) $out );
}

function cmp_rel_rmdir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) as $f ) {
		if ( '.' === $f || '..' === $f ) {
			continue;
		}
		$p = $dir . '/' . $f;
		is_dir( $p ) ? cmp_rel_rmdir( $p ) : unlink( $p );
	}
	rmdir( $dir );
}

$cmp_rel_missing = array_filter( array( 'bash', 'zip', 'rsync' ), function ( $b ) {
	return ! cmp_rel_has( $b );
} );

if ( $cmp_rel_missing ) {
	echo 'SKIP: release.sh tests need ' . implode( ', ', $cmp_rel_missing ) . "\n";
	return;
}

$cmp_rel_root = dirname( __DIR__ );
$cmp_rel_tmp  = sys_get_temp_dir() . '/cmp-release-test-' . getmypid() . '-' . mt_rand();
mkdir( $cmp_rel_tmp . '/state', 0700, true );

// ---- keygen.sh ----------------------------------------------------------
$cmp_rel_kg_out             = $cmp_rel_tmp . '/kg/manifest.key';
list( $rc, $out )           = cmp_rel_run( 'bash bin/keygen.sh manifest --out ' . escapeshellarg( $cmp_rel_kg_out ), array() );
cmp_assert_eq( $rc, 0, 'keygen: exits 0' );
cmp_assert( is_file( $cmp_rel_kg_out ), 'keygen: writes the secret key file' );
cmp_assert_eq( @fileperms( $cmp_rel_kg_out ) & 0777, 0600, 'keygen: secret key file is chmod 600' );
$cmp_rel_kg_sk = base64_decode( trim( (string) @file_get_contents( $cmp_rel_kg_out ) ), true );
cmp_assert_eq( strlen( (string) $cmp_rel_kg_sk ), 64, 'keygen: file holds base64 of a 64-byte secret key' );
$cmp_rel_kg_pk = ( 64 === strlen( (string) $cmp_rel_kg_sk ) ) ? base64_encode( sodium_crypto_sign_publickey_from_secretkey( $cmp_rel_kg_sk ) ) : 'no-key';
cmp_assert( false !== strpos( $out, $cmp_rel_kg_pk ), 'keygen: prints the public key' );
cmp_assert( 'no-key' !== $cmp_rel_kg_pk && false === strpos( $out, trim( (string) file_get_contents( $cmp_rel_kg_out ) ) ), 'keygen: never prints the secret key' );
$cmp_rel_before     = (string) @file_get_contents( $cmp_rel_kg_out );
list( $rc, $out )   = cmp_rel_run( 'bash bin/keygen.sh manifest --out ' . escapeshellarg( $cmp_rel_kg_out ), array() );
cmp_assert( 0 !== $rc, 'keygen: refuses to overwrite an existing key' );
cmp_assert_eq( file_get_contents( $cmp_rel_kg_out ), $cmp_rel_before, 'keygen: existing key left untouched' );
list( $rc, $out )   = cmp_rel_run( 'bash bin/keygen.sh other --out ' . escapeshellarg( $cmp_rel_tmp . '/kg/x.key' ), array() );
cmp_assert( 0 !== $rc && ! file_exists( $cmp_rel_tmp . '/kg/x.key' ), 'keygen: unknown key type refused' );

// ---- release.sh --dry-run -----------------------------------------------
$cmp_rel_skp = sodium_crypto_sign_keypair(); // release (zip) key
$cmp_rel_mkp = sodium_crypto_sign_keypair(); // manifest key
file_put_contents( $cmp_rel_tmp . '/signing.key', base64_encode( sodium_crypto_sign_secretkey( $cmp_rel_skp ) ) . "\n" );
file_put_contents( $cmp_rel_tmp . '/manifest.key', base64_encode( sodium_crypto_sign_secretkey( $cmp_rel_mkp ) ) . "\n" );
$cmp_rel_spk = base64_encode( sodium_crypto_sign_publickey( $cmp_rel_skp ) );
$cmp_rel_mpk = base64_encode( sodium_crypto_sign_publickey( $cmp_rel_mkp ) );

$cmp_rel_log = $cmp_rel_tmp . '/CHANGELOG.md';
file_put_contents(
	$cmp_rel_log,
	"# Changelog\n\n## 9.9.10 – 2026-11-21\n\n- Fix after 9.9.9.\n\n## 9.9.9 - 2026-11-20\n\n- First **item**.\n- Second item with `code`.\n\n## 9.9.8 – 2026-11-01\n\n- Older.\n"
);

$cmp_rel_env = array(
	'CMP_SIGNING_KEY'   => $cmp_rel_tmp . '/signing.key',
	'CMP_MANIFEST_KEY'  => $cmp_rel_tmp . '/manifest.key',
	'CMP_RELEASE_STATE' => $cmp_rel_tmp . '/state',
	'CMP_CHANGELOG'     => $cmp_rel_log,
);
$cmp_rel_up = $cmp_rel_root . '/dist/upload';

/** Snapshot of the repo's release/ state: relative path => sha256. */
function cmp_rel_snapshot( $dir ) {
	$snap = array();
	if ( ! is_dir( $dir ) ) {
		return $snap;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		$snap[ substr( $f->getPathname(), strlen( $dir ) + 1 ) ] = hash_file( 'sha256', $f->getPathname() );
	}
	ksort( $snap );
	return $snap;
}
$cmp_rel_release_before = cmp_rel_snapshot( $cmp_rel_root . '/release' );

/** Read + evaluate dist/upload/manifest-<channel>.json with the temp manifest key. */
function cmp_rel_eval( $dir, $channel, $mpk ) {
	$json = @file_get_contents( $dir . '/manifest-' . $channel . '.json' );
	$sig  = @file_get_contents( $dir . '/manifest-' . $channel . '.json.sig' );
	return CMP_Updater::evaluate_manifest( (string) $json, (string) $sig, $channel, array( 'version' => '0.1.0', 'php' => PHP_VERSION, 'wp' => '6.5' ), array( $mpk ) );
}

// Release 9.9.9 -> beta.
list( $rc, $out ) = cmp_rel_run( 'bash bin/release.sh 9.9.9 --dry-run --skip-checks', $cmp_rel_env );
cmp_assert_eq( $rc, 0, 'release 9.9.9 --dry-run --skip-checks exits 0' . ( 0 !== $rc ? ': ' . substr( $out, -600 ) : '' ) );
cmp_assert( is_file( $cmp_rel_up . '/manifest-beta.json' ) && is_file( $cmp_rel_up . '/manifest-beta.json.sig' ), 'release: dist/upload/manifest-beta.json + .sig written' );
cmp_assert( ! file_exists( $cmp_rel_up . '/manifest-stable.json' ), 'release: no stable manifest written' );
$cmp_rel_beta = cmp_rel_eval( $cmp_rel_up, 'beta', $cmp_rel_mpk );
cmp_assert_eq( $cmp_rel_beta['status'], 'ok', 'release: beta manifest passes evaluate_manifest with the temp key' );
cmp_assert_eq( $cmp_rel_beta['offer'], true, 'release: beta manifest offers 9.9.9 over 0.1.0' );
$cmp_rel_m = (array) $cmp_rel_beta['manifest'];
cmp_assert_eq( isset( $cmp_rel_m['version'] ) ? $cmp_rel_m['version'] : null, '9.9.9', 'release: manifest version 9.9.9' );
cmp_assert_eq( isset( $cmp_rel_m['package'] ) ? $cmp_rel_m['package'] : null, 'https://github.com/rtdesignstudio/consent-manager-pro/releases/download/v9.9.9/consent-manager-pro.zip', 'release: package URL on GitHub Releases' );
cmp_assert( CMP_Updater::verify_package( $cmp_rel_root . '/dist/consent-manager-pro.zip', $cmp_rel_m, array( $cmp_rel_spk ) ), 'release: zip passes verify_package (sha256 + release-key signature)' );
cmp_assert( CMP_Updater::verify_signature( $cmp_rel_root . '/dist/consent-manager-pro.zip', (string) @file_get_contents( $cmp_rel_root . '/dist/consent-manager-pro.zip.sig' ), $cmp_rel_spk ), 'release: .zip.sig kept for the old relay' );
cmp_assert_eq( isset( $cmp_rel_m['notes'][0]['version'] ) ? $cmp_rel_m['notes'][0]['version'] : null, '9.9.9', 'release: notes[0].version = 9.9.9' );
cmp_assert_eq( isset( $cmp_rel_m['notes'][0]['items'] ) ? $cmp_rel_m['notes'][0]['items'] : null, array( 'First item.', 'Second item with code.' ), 'release: notes items are plain text bullets' );
cmp_assert_eq( isset( $cmp_rel_m['notes'][1]['version'] ) ? $cmp_rel_m['notes'][1]['version'] : null, '9.9.8', 'release: older versions follow, newer ones (9.9.10) left out' );
cmp_assert_eq( count( isset( $cmp_rel_m['notes'] ) ? $cmp_rel_m['notes'] : array() ), 2, 'release: only versions up to 9.9.9 in notes' );
cmp_assert_eq( array( $cmp_rel_m['requires_wp'], $cmp_rel_m['requires_php'], $cmp_rel_m['tested_wp'] ), array( '6.0', '7.4', '6.9' ), 'release: requirements' );
cmp_assert_eq( strtotime( $cmp_rel_m['expires'] ) - strtotime( $cmp_rel_m['issued'] ), 60 * 86400, 'release: expires = issued + 60 days' );
cmp_assert( is_file( $cmp_rel_tmp . '/state/manifest-beta.json' ), 'release --dry-run: state written to CMP_RELEASE_STATE' );
cmp_assert( false !== strpos( $out, 'DRY RUN' ) && false !== strpos( $out, 'nu urca' ), 'release --dry-run: says DRY RUN, do not upload' );
cmp_assert( false === strpos( $out, 'scp ' ) && false === strpos( $out, 'Rulează pe Mac' ), 'release --dry-run: prints no upload command' );
$cmp_rel_zip = new ZipArchive();
if ( true === $cmp_rel_zip->open( $cmp_rel_root . '/dist/consent-manager-pro.zip' ) ) {
	cmp_assert( false !== $cmp_rel_zip->locateName( 'consent-manager-pro/CHANGELOG.md' ), 'zip: CHANGELOG.md included' );
	cmp_assert( false !== $cmp_rel_zip->locateName( 'consent-manager-pro/consent-manager-pro.php' ), 'zip: plugin main file included' );
	$cmp_rel_bad = array();
	for ( $i = 0; $i < $cmp_rel_zip->numFiles; $i++ ) {
		$n = $cmp_rel_zip->getNameIndex( $i );
		if ( preg_match( '#^consent-manager-pro/(release|tests|bin|proxy|docs|dist|\.superpowers|\.git)(/|$)#', $n ) ) {
			$cmp_rel_bad[] = $n;
		}
	}
	cmp_assert_eq( $cmp_rel_bad, array(), 'zip: release/, tests, bin, proxy, docs excluded' );
	$cmp_rel_zip->close();
} else {
	cmp_assert( false, 'zip: opens' );
}

// B8b: the dry run also builds the public pages into dist/site (never uploaded) and the SBOM.
cmp_assert( is_file( $cmp_rel_root . '/dist/site/.well-known/security.txt' ), 'release --dry-run: dist/site/.well-known/security.txt built' );
cmp_assert( false === strpos( $out, 'release/site' ) && false === strpos( $out, 'dist/site/' ), 'release --dry-run: does not print release/site or dist/site as upload' );
$cmp_rel_sbom_f = $cmp_rel_root . '/dist/consent-manager-pro-9.9.9.cdx.json';
cmp_assert( is_file( $cmp_rel_sbom_f ), 'SBOM: dist/consent-manager-pro-9.9.9.cdx.json written' );
$cmp_rel_sbom_mtime = filemtime( $cmp_rel_sbom_f );
$cmp_rel_sbom = json_decode( (string) @file_get_contents( $cmp_rel_sbom_f ), true );
cmp_assert( is_array( $cmp_rel_sbom ), 'SBOM: valid JSON' );
if ( is_array( $cmp_rel_sbom ) ) {
	cmp_assert_eq( isset( $cmp_rel_sbom['bomFormat'] ) ? $cmp_rel_sbom['bomFormat'] : null, 'CycloneDX', 'SBOM: bomFormat CycloneDX' );
	cmp_assert_eq( isset( $cmp_rel_sbom['specVersion'] ) ? $cmp_rel_sbom['specVersion'] : null, '1.5', 'SBOM: specVersion 1.5' );
	cmp_assert( isset( $cmp_rel_sbom['serialNumber'] ) && 1 === preg_match( '/^urn:uuid:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $cmp_rel_sbom['serialNumber'] ), 'SBOM: serialNumber urn:uuid' );
	cmp_assert_eq( isset( $cmp_rel_sbom['version'] ) ? $cmp_rel_sbom['version'] : null, 1, 'SBOM: version 1' );
	$cmp_rel_mc = isset( $cmp_rel_sbom['metadata']['component'] ) ? $cmp_rel_sbom['metadata']['component'] : array();
	cmp_assert( ! empty( $cmp_rel_sbom['metadata']['timestamp'] ), 'SBOM: metadata.timestamp' );
	cmp_assert_eq( array( isset( $cmp_rel_mc['type'] ) ? $cmp_rel_mc['type'] : null, isset( $cmp_rel_mc['name'] ) ? $cmp_rel_mc['name'] : null, isset( $cmp_rel_mc['version'] ) ? $cmp_rel_mc['version'] : null ), array( 'application', 'consent-manager-pro', '9.9.9' ), 'SBOM: plugin component type, name, version' );
	cmp_assert_eq( isset( $cmp_rel_mc['licenses'][0]['license']['id'] ) ? $cmp_rel_mc['licenses'][0]['license']['id'] : null, 'GPL-2.0-or-later', 'SBOM: plugin license' );
	$cmp_rel_h = function ( $c ) {
		return isset( $c['hashes'][0]['alg'], $c['hashes'][0]['content'] ) && 'SHA-256' === $c['hashes'][0]['alg'] ? $c['hashes'][0]['content'] : null;
	};
	cmp_assert_eq( $cmp_rel_h( $cmp_rel_mc ), hash_file( 'sha256', $cmp_rel_root . '/dist/consent-manager-pro.zip' ), 'SBOM: plugin hash = SHA-256 of the zip' );
	$cmp_rel_by = array();
	foreach ( isset( $cmp_rel_sbom['components'] ) ? $cmp_rel_sbom['components'] : array() as $cmp_rel_c ) {
		$cmp_rel_by[ $cmp_rel_c['name'] ] = $cmp_rel_c;
	}
	cmp_assert( isset( $cmp_rel_by['standalone/cmp.js'] ) && isset( $cmp_rel_by['data/services.json'] ), 'SBOM: standalone and services library components' );
	cmp_assert_eq( isset( $cmp_rel_by['standalone/cmp.js'] ) ? $cmp_rel_h( $cmp_rel_by['standalone/cmp.js'] ) : null, hash_file( 'sha256', $cmp_rel_root . '/standalone/cmp.js' ), 'SBOM: standalone hash = SHA-256 of cmp.js' );
	cmp_assert_eq( isset( $cmp_rel_by['data/services.json'] ) ? $cmp_rel_h( $cmp_rel_by['data/services.json'] ) : null, hash_file( 'sha256', $cmp_rel_root . '/data/services.json' ), 'SBOM: services hash = SHA-256 of services.json' );
}

// Missing CHANGELOG section -> non-zero exit, manifest not rewritten.
$cmp_rel_beta_bytes = file_get_contents( $cmp_rel_tmp . '/state/manifest-beta.json' );
list( $rc, $out )   = cmp_rel_run( 'bash bin/release.sh 9.9.11 --dry-run --skip-checks', $cmp_rel_env );
cmp_assert( 0 !== $rc, 'release: missing CHANGELOG section -> non-zero exit' );
cmp_assert( false !== stripos( $out, 'CHANGELOG' ), 'release: the error names CHANGELOG' );
cmp_assert_eq( file_get_contents( $cmp_rel_tmp . '/state/manifest-beta.json' ), $cmp_rel_beta_bytes, 'release: failed run leaves the state untouched' );

// --revoke of the only (stable) version with no newer beta -> refused.
list( $rc, $out ) = cmp_rel_run( 'bash bin/release.sh --promote 9.9.9 --dry-run', $cmp_rel_env );
cmp_assert_eq( $rc, 0, '--promote 9.9.9 --dry-run exits 0' . ( 0 !== $rc ? ': ' . substr( $out, -600 ) : '' ) );
cmp_assert( is_file( $cmp_rel_root . '/dist/site/.well-known/security.txt' ) && false === strpos( $out, 'release/site' ), '--promote --dry-run: dist/site built, not offered for upload' );
cmp_assert( ! file_exists( $cmp_rel_root . '/dist/consent-manager-pro-9.9.9.cdx.json' ) || filemtime( $cmp_rel_root . '/dist/consent-manager-pro-9.9.9.cdx.json' ) <= $cmp_rel_sbom_mtime, '--promote: no new SBOM' );
cmp_assert( is_file( $cmp_rel_up . '/manifest-stable.json' ), '--promote: manifest-stable.json written' );
$cmp_rel_st = cmp_rel_eval( $cmp_rel_up, 'stable', $cmp_rel_mpk );
cmp_assert_eq( $cmp_rel_st['status'], 'ok', '--promote: stable manifest verifies' );
$cmp_rel_sm = (array) $cmp_rel_st['manifest'];
cmp_assert_eq( isset( $cmp_rel_sm['channel'] ) ? $cmp_rel_sm['channel'] : null, 'stable', '--promote: channel stable' );
cmp_assert_eq( array( $cmp_rel_sm['package'], $cmp_rel_sm['sha256'], $cmp_rel_sm['signature'] ), array( $cmp_rel_m['package'], $cmp_rel_m['sha256'], $cmp_rel_m['signature'] ), '--promote: same package, sha256 and signature as beta' );

list( $rc, $out ) = cmp_rel_run( 'bash bin/release.sh --revoke 9.9.9 --dry-run', $cmp_rel_env );
cmp_assert( 0 !== $rc, '--revoke: refused when 9.9.9 is stable and no newer beta exists' );

// Release 9.9.10 to beta, then --revoke 9.9.9.
list( $rc, $out ) = cmp_rel_run( 'bash bin/release.sh 9.9.10 --dry-run --skip-checks', $cmp_rel_env );
cmp_assert_eq( $rc, 0, 'release 9.9.10 --dry-run exits 0' . ( 0 !== $rc ? ': ' . substr( $out, -600 ) : '' ) );
$cmp_rel_b2 = cmp_rel_eval( $cmp_rel_up, 'beta', $cmp_rel_mpk );
cmp_assert_eq( $cmp_rel_b2['manifest']['version'], '9.9.10', 'release 9.9.10: beta now 9.9.10' );
cmp_assert_eq( $cmp_rel_b2['manifest']['notes'][0]['version'], '9.9.10', 'release 9.9.10: notes[0] = 9.9.10' );

list( $rc, $out ) = cmp_rel_run( 'bash bin/release.sh 9.9.10 --dry-run --skip-checks', $cmp_rel_env );
cmp_assert( 0 !== $rc, 'release: re-releasing the current beta version refused' );

$cmp_rel_issued_b = $cmp_rel_b2['issued'];
$cmp_rel_issued_s = $cmp_rel_st['issued'];
list( $rc, $out ) = cmp_rel_run( 'bash bin/release.sh --revoke 9.9.9 --dry-run', $cmp_rel_env );
cmp_assert_eq( $rc, 0, '--revoke 9.9.9 after a newer beta exits 0' . ( 0 !== $rc ? ': ' . substr( $out, -600 ) : '' ) );
cmp_assert( is_file( $cmp_rel_root . '/dist/site/.well-known/security.txt' ) && false === strpos( $out, 'release/site' ), '--revoke --dry-run: dist/site built, not offered for upload' );
$cmp_rel_rb = cmp_rel_eval( $cmp_rel_up, 'beta', $cmp_rel_mpk );
$cmp_rel_rs = cmp_rel_eval( $cmp_rel_up, 'stable', $cmp_rel_mpk );
cmp_assert( 'ok' === $cmp_rel_rb['status'] && 'ok' === $cmp_rel_rs['status'], '--revoke: both manifests re-signed and valid' );
cmp_assert( in_array( '9.9.9', (array) $cmp_rel_rb['manifest']['revoked'], true ), '--revoke: 9.9.9 in beta revoked' );
cmp_assert( in_array( '9.9.9', (array) $cmp_rel_rs['manifest']['revoked'], true ), '--revoke: 9.9.9 in stable revoked' );
cmp_assert( $cmp_rel_rb['issued'] > $cmp_rel_issued_b, '--revoke: beta issued increases' );
cmp_assert( $cmp_rel_rs['issued'] > $cmp_rel_issued_s, '--revoke: stable issued increases' );
$cmp_rel_rev_env = array( 'version' => '9.9.9', 'php' => PHP_VERSION, 'wp' => '6.5' );
$cmp_rel_rv      = CMP_Updater::evaluate_manifest( (string) file_get_contents( $cmp_rel_up . '/manifest-stable.json' ), (string) file_get_contents( $cmp_rel_up . '/manifest-stable.json.sig' ), 'stable', $cmp_rel_rev_env, array( $cmp_rel_mpk ) );
cmp_assert( true === $cmp_rel_rv['revoked_installed'] && false === $cmp_rel_rv['offer'], '--revoke: a 9.9.9 site sees revoked_installed, nothing offered' );

list( $rc, $out ) = cmp_rel_run( 'bash bin/release.sh --promote 9.9.9 --dry-run', $cmp_rel_env );
cmp_assert( 0 !== $rc, '--promote: a revoked version is refused' );

// --resign: new issued/expires, exactly 60 days apart.
cmp_rel_rmdir( $cmp_rel_root . '/dist/site' );
list( $rc, $out ) = cmp_rel_run( 'bash bin/release.sh --resign --dry-run', $cmp_rel_env );
cmp_assert_eq( $rc, 0, '--resign --dry-run exits 0' . ( 0 !== $rc ? ': ' . substr( $out, -600 ) : '' ) );
cmp_assert( is_file( $cmp_rel_root . '/dist/site/.well-known/security.txt' ) && false === strpos( $out, 'release/site' ), '--resign --dry-run: dist/site built, not offered for upload' );
foreach ( array( 'beta' => $cmp_rel_rb, 'stable' => $cmp_rel_rs ) as $cmp_rel_ch => $cmp_rel_prev ) {
	$cmp_rel_r = cmp_rel_eval( $cmp_rel_up, $cmp_rel_ch, $cmp_rel_mpk );
	cmp_assert_eq( $cmp_rel_r['status'], 'ok', "--resign: {$cmp_rel_ch} manifest valid" );
	cmp_assert( $cmp_rel_r['issued'] > $cmp_rel_prev['issued'], "--resign: {$cmp_rel_ch} issued is new" );
	cmp_assert( $cmp_rel_r['manifest']['expires'] !== $cmp_rel_prev['manifest']['expires'], "--resign: {$cmp_rel_ch} expires is new" );
	cmp_assert_eq( strtotime( $cmp_rel_r['manifest']['expires'] ) - strtotime( $cmp_rel_r['manifest']['issued'] ), 60 * 86400, "--resign: {$cmp_rel_ch} expires - issued = 60 days" );
	cmp_assert_eq( $cmp_rel_r['manifest']['version'], $cmp_rel_prev['manifest']['version'], "--resign: {$cmp_rel_ch} version unchanged" );
}

// --skip-checks only together with --dry-run; unknown command refused.
list( $rc, $out ) = cmp_rel_run( 'bash bin/release.sh 9.9.9 --skip-checks', $cmp_rel_env + array( 'CMP_RELEASE_PREFLIGHT_ONLY' => '1' ) );
cmp_assert( 0 !== $rc, '--skip-checks without --dry-run refused' );
list( $rc, $out ) = cmp_rel_run( 'bash bin/release.sh', $cmp_rel_env );
cmp_assert( 0 !== $rc, 'no arguments -> usage, non-zero' );

// --dry-run with the default (real) key paths needs --real-keys.
list( $rc, $out ) = cmp_rel_run( 'bash bin/release.sh --resign --dry-run', array( 'CMP_MANIFEST_KEY' => null ) + $cmp_rel_env );
cmp_assert( 0 !== $rc && false !== strpos( $out, '--real-keys' ), '--dry-run with the default manifest key path refused without --real-keys' );
list( $rc, $out ) = cmp_rel_run( 'bash bin/release.sh 9.9.12 --dry-run --skip-checks', array( 'CMP_SIGNING_KEY' => null ) + $cmp_rel_env );
cmp_assert( 0 !== $rc && false !== strpos( $out, '--real-keys' ), 'release --dry-run with the default signing key path refused without --real-keys' );
// --real-keys reads the default paths under $HOME; HOME points at a temp dir here, never the real keys.
mkdir( $cmp_rel_tmp . '/home/.config/cmp-release', 0700, true );
copy( $cmp_rel_tmp . '/manifest.key', $cmp_rel_tmp . '/home/.config/cmp-release/manifest.key' );
list( $rc, $out ) = cmp_rel_run( 'bash bin/release.sh --resign --dry-run --real-keys', array( 'CMP_MANIFEST_KEY' => null, 'HOME' => $cmp_rel_tmp . '/home' ) + $cmp_rel_env );
cmp_assert_eq( $rc, 0, '--dry-run --real-keys uses the default key path' . ( 0 !== $rc ? ': ' . substr( $out, -400 ) : '' ) );
list( $rc, $out ) = cmp_rel_run( 'bash bin/release.sh --resign --real-keys', $cmp_rel_env + array( 'CMP_RELEASE_PREFLIGHT_ONLY' => '1' ) );
cmp_assert( 0 !== $rc, '--real-keys without --dry-run refused' );
// --real-keys never together with --skip-checks (real keys only sign a fully checked build). Temp HOME, never the real keys.
list( $rc, $out ) = cmp_rel_run( 'bash bin/release.sh 9.9.13 --dry-run --skip-checks --real-keys', array( 'CMP_MANIFEST_KEY' => null, 'CMP_SIGNING_KEY' => null, 'HOME' => $cmp_rel_tmp . '/home' ) + $cmp_rel_env );
cmp_assert( 0 !== $rc && false !== strpos( $out, '--real-keys' ) && false !== strpos( $out, '--skip-checks' ), '--real-keys together with --skip-checks refused with a clear message' );
cmp_assert( false === strpos( $out, 'Dry run:' ), '--real-keys + --skip-checks: refused before building or signing anything' );
list( $rc, $out ) = cmp_rel_run( 'bash bin/release.sh 9.9.13 --real-keys --dry-run --skip-checks', array( 'CMP_MANIFEST_KEY' => null, 'CMP_SIGNING_KEY' => null, 'HOME' => $cmp_rel_tmp . '/home' ) + $cmp_rel_env );
cmp_assert( 0 !== $rc && false !== strpos( $out, '--skip-checks' ), '--real-keys + --skip-checks refused in any argument order' );

// The printed upload command: SSH host and server directory are both overridable placeholders.
$cmp_rel_src = (string) file_get_contents( $cmp_rel_root . '/bin/release.sh' );
cmp_assert( false !== strpos( $cmp_rel_src, 'SERVER_HOST="${CMP_SERVER_HOST:-<HOST-SSH>}"' ), 'release.sh: SERVER_HOST from CMP_SERVER_HOST, placeholder <HOST-SSH>' );
cmp_assert( false !== strpos( $cmp_rel_src, 'SERVER_DIR="${CMP_SERVER_DIR:-<DIRECTOR-CONSENT.RT.MD>}"' ), 'release.sh: SERVER_DIR from CMP_SERVER_DIR, placeholder kept' );
cmp_assert( false !== strpos( $cmp_rel_src, 'scp$FILES $SERVER_HOST:$SERVER_DIR/' ), 'release.sh: scp uses $SERVER_HOST:$SERVER_DIR' );
cmp_assert( false !== strpos( $cmp_rel_src, 'git add release && git commit -m "chore(release): vX.Y.Z (beta)" && git push' ), 'release.sh: recovery note for a failed chore(release) commit/push' );

// B8b: real path builds release/site after save_state and uploads it; SBOM attached to the release.
cmp_assert( 1 === preg_match( '#CMP_STABLE_MANIFEST=release/manifest-stable\.json bin/build-site\.sh --out release/site#', $cmp_rel_src ), 'release.sh: real path builds release/site with the committed stable manifest' );
cmp_assert( false !== strpos( $cmp_rel_src, 'scp -r release/site/. $SERVER_HOST:$SERVER_DIR/' ), 'release.sh: upload command includes release/site/' );
cmp_assert( 1 === preg_match( '#gh release create .*dist/\$SLUG\.zip\.sig" "dist/\$SLUG-\$VERSION\.cdx\.json"#', $cmp_rel_src ), 'release.sh: SBOM attached to gh release create' );
cmp_assert( 1 === preg_match( '#\nsave_state\nbuild_site\ngit add "\$STATE"#', $cmp_rel_src ), 'release.sh: site built right after save_state, before git add' );
// Preflight: the site is built (into dist/site-preflight) before the first irreversible step.
$cmp_rel_pre = strpos( $cmp_rel_src, 'bin/build-site.sh --out dist/site-preflight' );
$cmp_rel_tag = strpos( $cmp_rel_src, "\t\t\tgit tag \"v\$VERSION\"" );
$cmp_rel_gh  = strpos( $cmp_rel_src, 'gh release create "v$VERSION"' );
cmp_assert( false !== $cmp_rel_pre && false !== $cmp_rel_tag && $cmp_rel_pre < $cmp_rel_tag && $cmp_rel_pre < strpos( $cmp_rel_src, 'git push -q origin main --tags' ) && $cmp_rel_pre < $cmp_rel_gh, 'release.sh: preflight site build runs before tag, push and gh release' );
cmp_assert( false !== strpos( $cmp_rel_src, '|| die "site build failed — nothing was published"' ), 'release.sh: a failed preflight site build stops with a clear message' );
cmp_assert( 1 === preg_match( '#CMP_STABLE_MANIFEST="\$PREFLIGHT_MANIFEST" bin/build-site\.sh --out dist/site-preflight#', $cmp_rel_src ) && false !== strpos( $cmp_rel_src, 'PREFLIGHT_MANIFEST="$UPLOAD/manifest-stable.json"' ), 'release.sh: preflight uses this run\'s stable manifest, else release/manifest-stable.json' );
cmp_assert( false !== strpos( $cmp_rel_src, 'If the site build fails after `gh release create` succeeded' ), 'release.sh: recovery note for a site build failing after gh' );

// Default dry-run state (no CMP_RELEASE_STATE): dist/state is reseeded from release/ on every run.
@mkdir( $cmp_rel_root . '/dist/state', 0755, true );
file_put_contents( $cmp_rel_root . '/dist/state/manifest-beta.json', 'junk' );
list( $rc, $out ) = cmp_rel_run( 'bash bin/release.sh --resign --dry-run', array( 'CMP_RELEASE_STATE' => null ) + $cmp_rel_env );
cmp_assert( 'junk' !== (string) @file_get_contents( $cmp_rel_root . '/dist/state/manifest-beta.json' ) && false === strpos( $out, 'unreadable' ), 'default dry-run state reseeded from release/ (stale dist/state discarded)' );

// Rescue key preflight (CMP_TEST_RESCUE_KEY is honoured only with CMP_RELEASE_PREFLIGHT_ONLY=1).
$cmp_rel_pf = array( 'CMP_RELEASE_PREFLIGHT_ONLY' => '1', 'CMP_RELEASE_STATE' => null, 'CMP_CHANGELOG' => null ) + $cmp_rel_env;
foreach ( array(
	'empty'          => '',
	'garbage'        => 'not base64 !!',
	'16 bytes'       => base64_encode( str_repeat( 'a', 16 ) ),
	'= manifest key' => CMP_Updater::MANIFEST_PUBLIC_KEY,
	'= release key'  => CMP_Updater::SIGN_PUBLIC_KEY,
) as $cmp_rel_lbl => $cmp_rel_val ) {
	list( $rc, $out ) = cmp_rel_run( 'bash bin/release.sh --resign', array( 'CMP_TEST_RESCUE_KEY' => $cmp_rel_val ) + $cmp_rel_pf );
	cmp_assert( 0 !== $rc && false !== strpos( $out, 'RESCUE_PUBLIC_KEY' ), "preflight: rescue key {$cmp_rel_lbl} refused" );
}
$cmp_rel_rescue = base64_encode( sodium_crypto_sign_publickey( sodium_crypto_sign_keypair() ) );
list( $rc, $out ) = cmp_rel_run( 'bash bin/release.sh --resign', array( 'CMP_TEST_RESCUE_KEY' => $cmp_rel_rescue ) + $cmp_rel_pf );
cmp_assert( 0 === $rc && false !== strpos( $out, 'Preflight OK' ), 'preflight: a valid, distinct rescue key passes' );
list( $rc, $out ) = cmp_rel_run( 'bash bin/release.sh --resign', array( 'CMP_TEST_RESCUE_KEY' => $cmp_rel_rescue, 'CMP_RELEASE_PREFLIGHT_ONLY' => null ) + $cmp_rel_env );
if ( 'RESCUE_KEY_NOT_SET' === CMP_Updater::RESCUE_PUBLIC_KEY ) {
	cmp_assert( 0 !== $rc && false !== strpos( $out, 'RESCUE_PUBLIC_KEY' ), 'preflight: CMP_TEST_RESCUE_KEY ignored without CMP_RELEASE_PREFLIGHT_ONLY' );
}

// Real path: refuses while RESCUE_PUBLIC_KEY is the placeholder, before any build.
if ( 'RESCUE_KEY_NOT_SET' === CMP_Updater::RESCUE_PUBLIC_KEY ) {
	foreach ( array( '9.9.9', '--promote 9.9.9', '--revoke 9.9.9', '--resign' ) as $cmp_rel_cmd ) {
		list( $rc, $out ) = cmp_rel_run( 'bash bin/release.sh ' . $cmp_rel_cmd, $cmp_rel_env + array( 'CMP_RELEASE_PREFLIGHT_ONLY' => '1' ) );
		cmp_assert( 0 !== $rc && false !== strpos( $out, 'RESCUE_PUBLIC_KEY' ), "real path ({$cmp_rel_cmd}): refused while RESCUE_PUBLIC_KEY is a placeholder" );
	}
} else {
	echo "SKIP: RESCUE_PUBLIC_KEY is set; placeholder refusal not applicable\n";
}

list( $rc, $out ) = cmp_rel_run( 'bash bin/release.sh --resign --dry-run', array( 'CMP_RELEASE_STATE' => $cmp_rel_root . '/release' ) + $cmp_rel_env );
cmp_assert( 0 !== $rc && false !== strpos( $out, 'must not write the real release/' ), 'dry-run refuses CMP_RELEASE_STATE pointing at the real release/' );
cmp_assert_eq( cmp_rel_snapshot( $cmp_rel_root . '/release' ), $cmp_rel_release_before, 'dry-runs leave the repo release/ byte-identical' );

cmp_rel_rmdir( $cmp_rel_tmp );
