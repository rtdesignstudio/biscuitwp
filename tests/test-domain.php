<?php
/**
 * Domain move (1.1.0): the plugin, its pages and the docs point at
 * https://biscuitwp.rt.md/ and at github.com/rtdesignstudio/biscuitwp.
 *
 * consent.rt.md stays only as a manifest mirror for 1.0.0 installs (everything
 * else there redirects 301 to the same path on biscuitwp.rt.md), and the old
 * repo path only where 1.0.0 sites depend on it (the manifest package URL base
 * and the updater's accepted prefixes). Any other mention is a leftover.
 */

$cmp_dm_root = dirname( __DIR__ );

/** Files to scan: relative paths of the listed files and of every text file under the listed folders. */
$cmp_dm_files = function ( array $paths ) use ( $cmp_dm_root ) {
	$out = array();
	foreach ( $paths as $p ) {
		$abs = $cmp_dm_root . '/' . $p;
		if ( is_file( $abs ) ) {
			$out[] = $p;
			continue;
		}
		if ( ! is_dir( $abs ) ) {
			continue;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $abs, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( preg_match( '/\.(php|html|js|css|txt|in|md|json|xml|po|pot)$/', $f->getFilename() ) || '.htaccess' === $f->getFilename() ) {
				$out[] = substr( $f->getPathname(), strlen( $cmp_dm_root ) + 1 );
			}
		}
	}
	sort( $out );
	return $out;
};

$cmp_dm_scan = $cmp_dm_files( array( 'includes', 'templates', 'site', 'languages', 'biscuitwp.php', 'consent-manager-pro.php', 'uninstall.php', 'README.md', 'readme.txt', 'SECURITY.md', 'CONTRIBUTING.md', 'standalone/README.md', 'bin/release.sh', 'bin/build-site.sh' ) );
cmp_assert( count( $cmp_dm_scan ) > 50, 'domain: the scan covers the plugin, the site and the docs (' . count( $cmp_dm_scan ) . ' files)' );

/*
 * Allowed mentions, per file: a line may name the old host / old repo path only
 * when it matches the file's pattern.
 */
$cmp_dm_old_host_ok = array(
	// The release script mirrors the manifests to the old document root: only comment
	// lines about the mirror / 1.0.0 and the printed mirror note, never code.
	'bin/release.sh'                    => '/^(#.*(mirror|1\.0\.0).*|\techo "  # mirror for 1\.0\.0 installs \(consent\.rt\.md\): the same manifests \+ \.sig")$/',
	// Privacy pages: 1.0.0 installs still ask consent.rt.md for the manifest.
	'site/confidentialitate/index.html' => '/1\.0\.0/',
	'site/en/privacy/index.html'        => '/1\.0\.0/',
	'site/ru/privacy/index.html'        => '/1\.0\.0/',
);
$cmp_dm_old_repo_ok = array(
	'includes/modules/class-updater.php' => '#^\s*(const PACKAGE_PREFIX = \'https://github\.com/rtdesignstudio/consent-manager-pro/releases/download/\';|\'https://github\.com/rtdesignstudio/consent-manager-pro/releases/download/\',)$#',
	'bin/release.sh'                     => '#^(PACKAGE_BASE="https://github\.com/rtdesignstudio/consent-manager-pro/releases/download"|\# GitHub repo \(renamed from rtdesignstudio/consent-manager-pro\) for gh release\.)$#',
);

$cmp_dm_bad_host = array();
$cmp_dm_bad_repo = array();
foreach ( $cmp_dm_scan as $rel ) {
	$lines = preg_split( '/\r\n|\n|\r/', (string) file_get_contents( $cmp_dm_root . '/' . $rel ) );
	foreach ( $lines as $n => $line ) {
		if ( false !== strpos( $line, 'consent.rt.md' ) && ! ( isset( $cmp_dm_old_host_ok[ $rel ] ) && preg_match( $cmp_dm_old_host_ok[ $rel ], $line ) ) ) {
			$cmp_dm_bad_host[] = $rel . ':' . ( $n + 1 );
		}
		if ( false !== strpos( $line, 'rtdesignstudio/consent-manager-pro' ) && ! ( isset( $cmp_dm_old_repo_ok[ $rel ] ) && preg_match( $cmp_dm_old_repo_ok[ $rel ], $line ) ) ) {
			$cmp_dm_bad_repo[] = $rel . ':' . ( $n + 1 );
		}
	}
}
cmp_assert( array() === $cmp_dm_bad_host, 'domain: no consent.rt.md outside the allowed places' . ( $cmp_dm_bad_host ? ' — ' . implode( ', ', array_slice( $cmp_dm_bad_host, 0, 15 ) ) : '' ) );
cmp_assert( array() === $cmp_dm_bad_repo, 'domain: no rtdesignstudio/consent-manager-pro outside PACKAGE_BASE / PACKAGE_PREFIX(ES)' . ( $cmp_dm_bad_repo ? ' — ' . implode( ', ', array_slice( $cmp_dm_bad_repo, 0, 15 ) ) : '' ) );

// The allowed places really carry what they are allowed for.
$cmp_dm_upd = (string) file_get_contents( $cmp_dm_root . '/includes/modules/class-updater.php' );
cmp_assert( false !== strpos( $cmp_dm_upd, "const BASE = 'https://biscuitwp.rt.md/';" ), 'domain: CMP_Updater::BASE is https://biscuitwp.rt.md/' );
cmp_assert( false !== strpos( $cmp_dm_upd, "const SECURITY_URL = 'https://biscuitwp.rt.md/securitate/';" ), 'domain: SECURITY_URL is the RO security page on biscuitwp.rt.md' );
cmp_assert( false !== strpos( $cmp_dm_upd, "add_filter( 'update_plugins_biscuitwp.rt.md'," ), 'domain: update_plugins_<host> filter on biscuitwp.rt.md (matches the Update URI)' );
cmp_assert( false !== strpos( $cmp_dm_upd, "'id'           => 'biscuitwp.rt.md/' . self::SLUG," ), 'domain: update response id on biscuitwp.rt.md' );
foreach ( array( 'biscuitwp.php', 'consent-manager-pro.php' ) as $cmp_dm_f ) {
	$cmp_dm_src = (string) file_get_contents( $cmp_dm_root . '/' . $cmp_dm_f );
	cmp_assert( 1 === preg_match( '#^ \* Update URI: https://biscuitwp\.rt\.md/$#m', $cmp_dm_src ), "domain: {$cmp_dm_f} Update URI is https://biscuitwp.rt.md/" );
	cmp_assert( 1 === preg_match( '#^ \* Plugin URI: https://biscuitwp\.rt\.md/$#m', $cmp_dm_src ), "domain: {$cmp_dm_f} Plugin URI is https://biscuitwp.rt.md/" );
}
// The Update URI host and the filter name must agree (WordPress builds the hook name from the host).
cmp_assert( 1 === preg_match( '#^ \* Update URI: https://([^/]+)/$#m', (string) file_get_contents( $cmp_dm_root . '/biscuitwp.php' ), $cmp_dm_m ) && false !== strpos( $cmp_dm_upd, "'update_plugins_" . $cmp_dm_m[1] . "'" ), 'domain: update_plugins_<host> uses the Update URI host' );

// Old host (consent.rt.md) .htaccess template, deployed by hand to its document root:
// certificate validation and the 1.0.0 manifests stay served, everything else 301s.
$cmp_dm_ht_file = $cmp_dm_root . '/docs/product/consent-rt-md.htaccess';
cmp_assert( is_file( $cmp_dm_ht_file ), 'old host: docs/product/consent-rt-md.htaccess exists' );
$cmp_dm_ht = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\n|\r/', (string) @file_get_contents( $cmp_dm_ht_file ) ) ), function ( $l ) {
	return '' !== $l && '#' !== $l[0];
} ) );
cmp_assert_eq( $cmp_dm_ht, array(
	'RewriteEngine On',
	'RewriteRule ^\.well-known/(acme-challenge|pki-validation)/ - [L]',
	'RewriteRule ^manifest-(stable|beta)\.json(\.sig)?$ - [L]',
	'RewriteRule ^(.*)$ https://biscuitwp.rt.md/$1 [R=301,L]',
), 'old host: .htaccess rules (ACME/PKI validation and manifests served, then 301 to the same path on biscuitwp.rt.md), in this order' );
// The rules behave as intended on sample paths (per-directory paths, no leading slash).
$cmp_dm_route = function ( $path ) use ( $cmp_dm_ht ) {
	foreach ( array_slice( $cmp_dm_ht, 1 ) as $rule ) {
		$parts = preg_split( '/\s+/', $rule );
		if ( preg_match( '#' . $parts[1] . '#', $path ) ) {
			return '-' === $parts[2] ? 'serve' : preg_replace( '#' . $parts[1] . '#', $parts[2], $path );
		}
	}
	return 'serve';
};
foreach ( array(
	'manifest-stable.json'                    => 'serve',
	'manifest-beta.json.sig'                  => 'serve',
	'.well-known/acme-challenge/x'            => 'serve',
	'.well-known/pki-validation/x.txt'        => 'serve',
	'manifest-foo.json'                       => 'https://biscuitwp.rt.md/manifest-foo.json',
	'securitate/'                             => 'https://biscuitwp.rt.md/securitate/',
	'.well-known/security.txt'                => 'https://biscuitwp.rt.md/.well-known/security.txt',
	''                                        => 'https://biscuitwp.rt.md/',
) as $cmp_dm_p => $cmp_dm_want ) {
	cmp_assert_eq( $cmp_dm_route( $cmp_dm_p ), $cmp_dm_want, "old host: /{$cmp_dm_p}" );
}
