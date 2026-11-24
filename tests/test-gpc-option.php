<?php
/**
 * Global Privacy Control as a per-site option ("respect_gpc", default on).
 * - the sanitizer: missing = on (sites saved before the option behave as
 *   today), "0" = off, "1" = on, not posted = the stored value;
 * - CMP_DATA.respectGPC from the settings;
 * - the Settings row (hidden "0" before the checkbox, checked by default);
 * - banner.js honours it (behaviour in standalone/tests/run.js; the branch here).
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once __DIR__ . '/../includes/class-sanitizer.php';
require_once __DIR__ . '/../includes/class-contrast.php';
require_once __DIR__ . '/../includes/modules/class-lang.php';
require_once __DIR__ . '/../includes/modules/class-i18n.php';
require_once __DIR__ . '/../includes/modules/class-banner.php';

// ---- Sanitizer ----
cmp_assert_eq( CMP_Sanitizer::settings( array() )['respect_gpc'], true, 'gpc: default on (nothing posted, nothing stored)' );
cmp_assert_eq( CMP_Sanitizer::settings( array( 'respect_gpc' => '1' ) )['respect_gpc'], true, 'gpc: ticked -> on' );
cmp_assert_eq( CMP_Sanitizer::settings( array( 'respect_gpc' => '0' ) )['respect_gpc'], false, 'gpc: hidden "0" alone (unticked) -> off' );
cmp_assert_eq( CMP_Sanitizer::settings( array( 'respect_gpc' => '' ) )['respect_gpc'], false, 'gpc: empty -> off' );
cmp_assert_eq( CMP_Sanitizer::settings( array( 'respect_gpc' => array( 'x' ) ) )['respect_gpc'], true, 'gpc: not a scalar -> the default (on)' );
cmp_assert_eq( CMP_Sanitizer::settings( array(), array( 'respect_gpc' => false ) )['respect_gpc'], false, 'gpc: not posted -> the stored value (off) is kept' );
cmp_assert_eq( CMP_Sanitizer::settings( array(), array( 'respect_gpc' => true ) )['respect_gpc'], true, 'gpc: not posted -> the stored value (on) is kept' );
cmp_assert_eq( CMP_Sanitizer::settings( array( 'respect_gpc' => '1' ), array( 'respect_gpc' => false ) )['respect_gpc'], true, 'gpc: posted wins over the stored value' );

// ---- CMP_DATA.respectGPC ----
cmp_assert_eq( CMP_Banner::respect_gpc( array() ), true, 'gpc data: a site saved before the option -> on (as today)' );
cmp_assert_eq( CMP_Banner::respect_gpc( array( 'respect_gpc' => true ) ), true, 'gpc data: on' );
cmp_assert_eq( CMP_Banner::respect_gpc( array( 'respect_gpc' => false ) ), false, 'gpc data: off' );
$gpc_src = (string) file_get_contents( __DIR__ . '/../includes/modules/class-banner.php' );
cmp_assert( 1 === preg_match( "/'respectGPC'\s*=>\s*self::respect_gpc\(\s*\\\$cmp_settings\s*\)/", $gpc_src ), 'gpc data: enqueue() passes respectGPC to CMP_DATA' );

// ---- Settings row ----
$gpc_page = (string) file_get_contents( __DIR__ . '/../includes/admin/class-settings-page.php' );
cmp_assert( 1 === preg_match( '/<input type="hidden" name="cmp\[respect_gpc\]" value="0">\s*<label><input type="checkbox" name="cmp\[respect_gpc\]" value="1"/', $gpc_page ), 'gpc row: hidden "0" before the checkbox (unticking is saved)' );
cmp_assert( false !== strpos( $gpc_page, "checked( ! isset( \$s['respect_gpc'] ) || ! empty( \$s['respect_gpc'] ) )" ), 'gpc row: ticked when nothing is stored (default on)' );
cmp_assert( false !== strpos( $gpc_page, "esc_html_e( 'Respectă semnalul Global Privacy Control (GPC)', 'biscuitwp' )" ), 'gpc row: the label, translatable' );
cmp_assert( 1 === preg_match( "/esc_html_e\( 'Când browserul vizitatorului trimite semnalul GPC, pluginul înregistrează „Doar cele necesare” și nu mai arată bannerul[^']*Setări cookie[^']*\. [^']*Moldova[^']*implicit[^']*\.', 'biscuitwp' \)/u", $gpc_page ), 'gpc row: the help text (two sentences, translatable)' );
cmp_assert( 0 === preg_match( "/GPC[^\n]*\xE2\x80\x94/u", $gpc_page ), 'gpc row: no em dash in the new texts' );

// ---- banner.js: the GPC branch reads the option (missing = on) ----
$gpc_js = (string) file_get_contents( __DIR__ . '/../assets/js/banner.js' );
cmp_assert( 1 === preg_match( '/if \(gpcHonoured\(D\) && navigator\.globalPrivacyControl === true && !D\.check\)/', $gpc_js ), 'banner.js: the GPC refusal only when the option is on' );

// ---- Guide mentions the option ----
$gpc_guide = (string) file_get_contents( __DIR__ . '/../templates/admin/guide.php' );
cmp_assert( 1 === preg_match( '/Global Privacy Control \(GPC\)[^\n]*Respectă semnalul Global Privacy Control/u', $gpc_guide ), 'guide: the GPC paragraph names the option' );
