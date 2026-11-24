<?php
/**
 * B8b: public repo files (LICENSE, README.md, SECURITY.md, CONTRIBUTING.md,
 * readme.txt) and the Stable tag check in bin/release.sh.
 */
$cmp_rd_root = dirname( __DIR__ );
$cmp_rd_read = function ( $f ) use ( $cmp_rd_root ) {
	$p = $cmp_rd_root . '/' . $f;
	return is_file( $p ) ? (string) file_get_contents( $p ) : '';
};

// LICENSE.
$lic = $cmp_rd_read( 'LICENSE' );
cmp_assert( false !== strpos( $lic, 'GNU GENERAL PUBLIC LICENSE' ), 'LICENSE: GNU GENERAL PUBLIC LICENSE' );
cmp_assert( false !== strpos( $lic, 'Version 2, June 1991' ), 'LICENSE: Version 2, June 1991' );
// FSF's current text says "Lesser", the 1991 original "Library".
cmp_assert( 1 === preg_match( '/(Library|Lesser) General\s+Public License instead of this License\.\s*$/', $lic ), 'LICENSE: standard last sentence' );
cmp_assert( false === stripos( $lic, 'WordPress' ), 'LICENSE: no WordPress text' );

// readme.txt.
$rt = $cmp_rd_read( 'readme.txt' );
cmp_assert( 0 === strpos( $rt, '=== BiscuitWP ===' ), 'readme.txt: title' );
cmp_assert( false !== strpos( $rt, 'Requires PHP: 7.4' ), 'readme.txt: Requires PHP' );
cmp_assert( false !== strpos( $rt, 'License: GPLv2 or later' ), 'readme.txt: License' );
$main = $cmp_rd_read( 'biscuitwp.php' );
preg_match( '/^ \* Version:\s*(\S+)/m', $main, $mv );
preg_match( '/^Stable tag:\s*(\S+)/m', $rt, $ms );
cmp_assert( isset( $mv[1], $ms[1] ) && $mv[1] === $ms[1], 'readme.txt: Stable tag equals plugin header Version' );

// SECURITY.md.
$sec = $cmp_rd_read( 'SECURITY.md' );
cmp_assert( false !== strpos( $sec, 'security@rt.md' ), 'SECURITY.md: email' );
cmp_assert( false !== strpos( $sec, 'https://github.com/rtdesignstudio/biscuitwp/security/advisories/new' ), 'SECURITY.md: advisory link' );
cmp_assert( false !== strpos( $sec, '72' ), 'SECURITY.md: 72 hours' );
cmp_assert( false !== strpos( $sec, '30' ), 'SECURITY.md: 30 days' );

// Forbidden strings in public files.
foreach ( array( 'README.md', 'SECURITY.md', 'CONTRIBUTING.md', 'readme.txt' ) as $f ) {
	$c = $cmp_rd_read( $f );
	cmp_assert( '' !== $c, "{$f}: exists" );
	foreach ( array( 'rtgit003', 'PROXY_KEY', 'cmp-update', '~/config', 'public_html', 'rtmd' ) as $bad ) {
		cmp_assert( false === strpos( $c, $bad ), "{$f}: does not contain {$bad}" );
	}
}

// README links.
$rd = $cmp_rd_read( 'README.md' );
cmp_assert( false !== strpos( $rd, 'https://biscuitwp.rt.md/securitate/' ) || false !== strpos( $rd, 'https://biscuitwp.rt.md/en/security/' ), 'README: security page link' );
cmp_assert( false !== strpos( $rd, 'docs/api.md' ), 'README: docs/api.md' );
cmp_assert( false !== strpos( $rd, 'SECURITY.md' ), 'README: SECURITY.md' );

// Plugin header Description.
preg_match( '/^ \* Description:\s*(.+)$/m', $main, $md );
cmp_assert( isset( $md[1] ) && false !== stripos( $md[1], 'open-source' ), 'header Description mentions open-source' );

// release.sh checks Stable tag on the real path.
$rel = $cmp_rd_read( 'bin/release.sh' );
cmp_assert( 1 === preg_match( '/Stable tag.*readme\.txt|readme\.txt.*Stable tag/s', $rel ), 'release.sh: checks readme.txt Stable tag' );

// standalone/README.md is public: GPL licence, no internal roadmap.
$sa = $cmp_rd_read( 'standalone/README.md' );
cmp_assert( '' !== $sa, 'standalone/README.md: exists' );
cmp_assert( false === strpos( $sa, 'Proprietary' ), 'standalone/README.md: no "Proprietary"' );
cmp_assert( false === strpos( $sa, 'Phase 2' ), 'standalone/README.md: no "Phase 2"' );
cmp_assert( false !== strpos( $sa, 'GPL-2.0-or-later' ) && false !== strpos( $sa, '../LICENSE' ), 'standalone/README.md: GPL-2.0-or-later with link to ../LICENSE' );
cmp_assert( false !== strpos( $sa, 'https://rt.md/cmp/' ) && false !== strpos( $sa, 'https://biscuitwp.rt.md/en/privacy/' ), 'standalone/README.md: RT copy note and privacy link' );

// CONTRIBUTING mentions the site and the SBOM.
$ct = $cmp_rd_read( 'CONTRIBUTING.md' );
cmp_assert( false !== strpos( $ct, 'release/site' ) && false !== strpos( $ct, 'SBOM' ) && false !== strpos( $ct, 'Stable tag' ), 'CONTRIBUTING: site, SBOM and Stable tag in the release section' );
