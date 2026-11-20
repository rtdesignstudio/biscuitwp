<?php
/**
 * B4 final fix wave: server-side re-consent (I1), option reads (T1), 0.4.x
 * translations (I2), Light cookie table (T6), templates notice (M2/M3), texts
 * (I3/M6/M7). Runs after test-legal.php (alphabetical), which loads the admin class.
 */
require_once __DIR__ . '/../includes/class-consent.php';
require_once __DIR__ . '/../includes/class-services.php';
require_once __DIR__ . '/../includes/modules/class-legal.php';
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once __DIR__ . '/../includes/class-sanitizer.php';
require_once __DIR__ . '/../includes/admin/class-legal-page.php';

$fx_root = dirname( __DIR__ );
$fx_src  = function ( $rel ) use ( $fx_root ) {
	return (string) file_get_contents( $fx_root . '/' . $rel );
};

// ---------------------------------------------------------------------------
// I1: CMP_Consent::effective_categories mirrors the JS effectiveCategories.
cmp_assert( method_exists( 'CMP_Consent', 'effective_categories' ), 'I1: CMP_Consent::effective_categories exists' );
if ( method_exists( 'CMP_Consent', 'effective_categories' ) ) {
	$fx_ec  = function ( $cookie, $map, $req = array() ) {
		return CMP_Consent::effective_categories( $cookie, $map, $req );
	};
	$fx_map = array( 'marketing' => array( 'meta-pixel', 'tiktok-pixel' ) );
	$fx_ck  = array( 'categories' => array( 'necessary', 'marketing' ), 'svc' => array( 'marketing' => array( 'meta-pixel' ) ) );
	cmp_assert_eq( $fx_ec( $fx_ck, $fx_map ), array( 'necessary' ), 'I1 effective: drops a category that gained a service' );
	cmp_assert_eq( $fx_ec( array( 'categories' => array( 'marketing' ) ), array( 'marketing' => array( 'tiktok-pixel' ) ) ), array( 'marketing' ), 'I1 effective: old cookie (no svc) keeps its categories' );
	cmp_assert_eq( $fx_ec( $fx_ck, null ), array( 'necessary', 'marketing' ), 'I1 effective: unknown map keeps the categories' );
	cmp_assert_eq( $fx_ec( $fx_ck, array() ), array( 'necessary', 'marketing' ), 'I1 effective: known empty map keeps the categories' );
	cmp_assert_eq( $fx_ec( array(), $fx_map ), array(), 'I1 effective: cookie without categories -> []' );
	cmp_assert_eq( $fx_ec( array( 'categories' => 'marketing', 'svc' => array() ), $fx_map ), array(), 'I1 effective: categories not an array -> []' );
	cmp_assert_eq( $fx_ec( array( 'categories' => array( 'marketing' ), 'svc' => 'junk' ), $fx_map ), array( 'marketing' ), 'I1 effective: svc string keeps categories (like an old cookie)' );
	cmp_assert_eq( $fx_ec( array( 'categories' => array( 'marketing' ), 'svc' => array( 'meta-pixel' ) ), $fx_map ), array( 'marketing' ), 'I1 effective: svc list keeps categories (like an old cookie)' );
	cmp_assert_eq( $fx_ec( array( 'categories' => array( 'marketing' ), 'svc' => array( 'marketing' => 'x' ) ), $fx_map ), array(), 'I1 effective: svc value not an array -> every id new' );
	cmp_assert_eq( $fx_ec( array( 'categories' => array( 'marketing' ), 'svc' => array() ), $fx_map ), array(), 'I1 effective: empty svc ({} in the cookie) -> every id new' );
	cmp_assert_eq( $fx_ec( $fx_ck, array( 'marketing' => 'oops' ) ), array( 'necessary', 'marketing' ), 'I1 effective: map value not an array skipped' );
	cmp_assert_eq( $fx_ec( array( 'categories' => array( 'necessary', 'marketing' ), 'svc' => array( 'marketing' => array( 'meta-pixel', 'tiktok-pixel', 'gone' ) ) ), $fx_map ), array( 'necessary', 'marketing' ), 'I1 effective: removed service -> nothing new' );
	cmp_assert_eq( $fx_ec( array( 'categories' => array( 'necessary' ), 'svc' => array() ), $fx_map ), array( 'necessary' ), 'I1 effective: new service in a refused category ignored' );
	$fx_req_ck = array( 'categories' => array( 'necessary', 'functional', 'marketing' ), 'svc' => array( 'functional' => array( 'a' ), 'marketing' => array( 'meta-pixel' ) ) );
	$fx_req_mp = array( 'functional' => array( 'a', 'b' ), 'marketing' => array( 'meta-pixel' ) );
	cmp_assert_eq( $fx_ec( $fx_req_ck, $fx_req_mp, array( 'necessary', 'functional' ) ), array( 'necessary', 'functional', 'marketing' ), 'I1 effective: a required category is never removed' );
	cmp_assert_eq( $fx_ec( $fx_req_ck, $fx_req_mp ), array( 'necessary', 'marketing' ), 'I1 effective: without required slugs the category is dropped (shared semantics)' );
	$fx_two = array( 'categories' => array( 'necessary', 'statistics', 'marketing' ), 'svc' => array( 'statistics' => array(), 'marketing' => array( 'meta-pixel' ) ) );
	cmp_assert_eq( $fx_ec( $fx_two, array( 'statistics' => array( 'google-analytics' ), 'marketing' => array( 'meta-pixel', 'tiktok-pixel' ) ) ), array( 'necessary' ), 'I1 effective: every affected category dropped' );
}

// I1: is_allowed() goes through the effective categories (pure core).
cmp_assert( method_exists( 'CMP_Consent', 'allowed_from' ), 'I1: CMP_Consent::allowed_from exists' );
if ( method_exists( 'CMP_Consent', 'allowed_from' ) ) {
	$fx_cats = array(
		array( 'slug' => 'necessary', 'required' => true ),
		array( 'slug' => 'statistics' ),
		array( 'slug' => 'marketing' ),
	);
	$fx_cookie = array( 'v' => '2', 'categories' => array( 'necessary', 'statistics', 'marketing' ), 'svc' => array( 'statistics' => array( 'google-analytics' ), 'marketing' => array( 'meta-pixel' ) ) );
	$fx_new    = array( 'statistics' => array( 'google-analytics' ), 'marketing' => array( 'meta-pixel', 'custom-1' ) );
	cmp_assert_eq( CMP_Consent::allowed_from( 'marketing', $fx_cookie, $fx_cats, '2', $fx_new ), false, 'I1 is_allowed: a category that gained a service is refused server-side' );
	cmp_assert_eq( CMP_Consent::allowed_from( 'statistics', $fx_cookie, $fx_cats, '2', $fx_new ), true, 'I1 is_allowed: an unaffected category stays allowed' );
	cmp_assert_eq( CMP_Consent::allowed_from( 'necessary', $fx_cookie, $fx_cats, '2', $fx_new ), true, 'I1 is_allowed: required category always allowed' );
	cmp_assert_eq( CMP_Consent::allowed_from( 'marketing', $fx_cookie, $fx_cats, '2', null ), true, 'I1 is_allowed: unknown map -> stored categories' );
	cmp_assert_eq( CMP_Consent::allowed_from( 'marketing', $fx_cookie, $fx_cats, '3', null ), false, 'I1 is_allowed: other policy version -> refused' );
	cmp_assert_eq( CMP_Consent::allowed_from( 'marketing', null, $fx_cats, '2', $fx_new ), false, 'I1 is_allowed: no cookie -> refused' );
	cmp_assert_eq( CMP_Consent::allowed_from( 'necessary', null, $fx_cats, '2', $fx_new ), true, 'I1 is_allowed: no cookie, required -> allowed' );
	unset( $fx_cookie['svc'] );
	cmp_assert_eq( CMP_Consent::allowed_from( 'marketing', $fx_cookie, $fx_cats, '2', $fx_new ), true, 'I1 is_allowed: legacy cookie (no svc) keeps its categories' );
}
$fx_consent = $fx_src( 'includes/class-consent.php' );
cmp_assert( 1 === preg_match( '/function is_allowed\( \$slug \) \{.*?CMP_Services::current_svc_by_cat\(.*?self::allowed_from\(/s', $fx_consent ), 'I1: is_allowed() uses the shared svcByCat helper and allowed_from()' );

// ---------------------------------------------------------------------------
// T1: one shared helper; cmp_services_found read only when the history is absent.
cmp_assert( method_exists( 'CMP_Services', 'seen_lazy' ), 'T1: CMP_Services::seen_lazy exists' );
if ( method_exists( 'CMP_Services', 'seen_lazy' ) ) {
	$fx_calls = 0;
	$fx_found = function () use ( &$fx_calls ) {
		$fx_calls++;
		return array( 'b' );
	};
	cmp_assert_eq( CMP_Services::seen_lazy( array( 'a' ), $fx_found ), array( 'a' ), 'T1 seen_lazy: array history wins' );
	cmp_assert_eq( $fx_calls, 0, 'T1 seen_lazy: found not read when the history exists' );
	cmp_assert_eq( CMP_Services::seen_lazy( array(), $fx_found ), array(), 'T1 seen_lazy: empty history is a known list, found not read' );
	cmp_assert_eq( $fx_calls, 0, 'T1 seen_lazy: found still not read' );
	cmp_assert_eq( CMP_Services::seen_lazy( false, $fx_found ), array( 'b' ), 'T1 seen_lazy: no history -> found' );
	cmp_assert_eq( $fx_calls, 1, 'T1 seen_lazy: found read once when the history is absent' );
	cmp_assert_eq( CMP_Services::seen_lazy( false, function () {
		return false;
	} ), null, 'T1 seen_lazy: neither -> null (unknown map)' );
}
cmp_assert( method_exists( 'CMP_Services', 'current_svc_by_cat' ), 'T1: CMP_Services::current_svc_by_cat exists' );
$fx_banner = $fx_src( 'includes/modules/class-banner.php' );
cmp_assert( false !== strpos( $fx_banner, 'CMP_Services::current_svc_by_cat(' ), 'T1: the banner uses the shared svcByCat helper' );
cmp_assert( false === strpos( $fx_banner, "get_option( 'cmp_services_found'" ) && false === strpos( $fx_banner, "get_option( 'cmp_services_history'" ), 'T1: the banner reads no services option directly' );
$fx_services = $fx_src( 'includes/class-services.php' );
cmp_assert( 1 === preg_match( "/function current_svc_by_cat\(.*?get_option\( 'cmp_services_history', false \).*?function \(\) \{\s*return get_option\( 'cmp_services_found', false \);/s", $fx_services ), 'T1: current_svc_by_cat reads found lazily' );
cmp_assert( false !== strpos( $fx_src( 'includes/modules/class-blocker.php' ), "update_option( 'cmp_services_history', CMP_Services::merge_seen( is_array( \$history ) ? \$history : array(), (array) \$scan['services']['found'] ), true );" ), 'T1: the scan writes the history autoloaded' );
cmp_assert( false !== strpos( $fx_src( 'includes/admin/class-services-page.php' ), "update_option( 'cmp_services_history', CMP_Services::merge_seen( array(), is_array( \$found ) ? \$found : array() ), true );" ), 'T1: the reset writes the history autoloaded' );
$fx_plugin = $fx_src( 'includes/class-plugin.php' );
cmp_assert( false !== strpos( $fx_plugin, "update_option( 'cmp_services_history', \$seed, true );" ), 'T1: the seed writes the history autoloaded' );
cmp_assert( false !== strpos( $fx_plugin, "wp_set_option_autoload( 'cmp_services_history', true )" ), 'T1: the upgrade flips an existing history to autoload (WP 6.4+)' );

// ---------------------------------------------------------------------------
// I2: 0.4.x translations recognised by the generated-document header.
cmp_assert( method_exists( 'CMP_Legal', 'has_generated_header' ), 'I2: CMP_Legal::has_generated_header exists' );
cmp_assert( method_exists( 'CMP_Legal', 'refreshable_translation' ), 'I2: CMP_Legal::refreshable_translation exists' );
if ( method_exists( 'CMP_Legal', 'has_generated_header' ) && method_exists( 'CMP_Legal', 'refreshable_translation' ) ) {
	$fx_h042 = '<!-- wp:paragraph --><p><em>Document generat automat de Consent Manager Pro. Șablon informativ, nu constituie consultanță juridică — revizuiește-l cu un specialist.</em></p><!-- /wp:paragraph -->' . "\n" . '<!-- wp:heading --><h2>Politica de confidențialitate</h2><!-- /wp:heading -->';
	cmp_assert_eq( CMP_Legal::has_generated_header( $fx_h042 ), true, 'I2 header: 0.4.x RO header recognised' );
	foreach ( array( 'ro', 'ru', 'en' ) as $fx_l ) {
		foreach ( CMP_Legal::TYPES as $fx_t ) {
			cmp_assert_eq( CMP_Legal::has_generated_header( CMP_Legal::load_template( $fx_t, $fx_l ) ), true, "I2 header: current $fx_l/$fx_t template recognised" );
		}
	}
	cmp_assert_eq( CMP_Legal::has_generated_header( "\n  <p>Document generat automat de Consent Manager Pro.</p>" ), true, 'I2 header: leading whitespace and classic markup ok' );
	cmp_assert_eq( CMP_Legal::has_generated_header( '<p>Document generat automat de Consent Manager Pro</p>' ), true, 'I2 header: without the period' );
	cmp_assert_eq( CMP_Legal::has_generated_header( '<p>Политика</p><p>Документ автоматически создан Consent Manager Pro.</p>' ), false, 'I2 header: not at the start -> no' );
	cmp_assert_eq( CMP_Legal::has_generated_header( '<p>Our own privacy text. Document generat automat de Consent Manager Pro.</p>' ), false, 'I2 header: text before it -> no' );
	cmp_assert_eq( CMP_Legal::has_generated_header( '' ), false, 'I2 header: empty -> no' );
	cmp_assert_eq( CMP_Legal::has_generated_header( null ), false, 'I2 header: null -> no' );
	cmp_assert_eq( CMP_Legal::refreshable_translation( 'privacy', 'privacy', '<p>anything</p>' ), true, 'I2 refreshable: marked for this type' );
	cmp_assert_eq( CMP_Legal::refreshable_translation( '', 'privacy', $fx_h042 ), true, 'I2 refreshable: unmarked 0.4.x copy with the header' );
	cmp_assert_eq( CMP_Legal::refreshable_translation( '', 'privacy', '<p>Политика, написанная вручную</p>' ), false, 'I2 refreshable: unmarked page of the admin -> never' );
	cmp_assert_eq( CMP_Legal::refreshable_translation( 'cookie', 'privacy', $fx_h042 ), false, 'I2 refreshable: marked for another type -> never' );
}
$fx_legal = $fx_src( 'includes/modules/class-legal.php' );
cmp_assert( 1 === preg_match( "/function sync_translation\(.*?self::refreshable_translation\(.*?update_post_meta\( \\\$existing_id, '_cmp_legal_type', \\\$type \);/s", $fx_legal ), 'I2: sync_translation refreshes recognised translations and marks them' );
$fx_lp = $fx_src( 'includes/admin/class-legal-page.php' );
cmp_assert( false === strpos( $fx_lp, 'Regenerarea suprascrie și traducerile create de plugin; paginile create de tine nu sunt atinse.' ), 'I2: old hint replaced' );
cmp_assert( false !== strpos( $fx_lp, 'Document generat automat de Consent Manager Pro' ), 'I2: the hint names the header that identifies plugin pages' );

// ---------------------------------------------------------------------------
// T6: Light cookie table lists only what loads in Light.
cmp_assert( method_exists( 'CMP_Legal', 'table_for_mode' ), 'T6: CMP_Legal::table_for_mode exists' );
if ( method_exists( 'CMP_Legal', 'table_for_mode' ) ) {
	$fx_ck1 = array( array( 'name' => 'x', 'duration' => '1', 'purpose' => 'p' ) );
	$fx_cats6 = array(
		array( 'slug' => 'necessary', 'required' => true, 'label' => 'Necesare', 'cookies' => array( array( 'name' => 'sess', 'provider' => 'Me', 'duration' => '1 zi', 'purpose' => 'Sesiune' ) ) ),
		array( 'slug' => 'statistics', 'label' => 'Statistici', 'cookies' => array( array( 'name' => 'manual_stat', 'provider' => 'Me', 'duration' => '1 zi', 'purpose' => 'Stat' ) ) ),
	);
	$fx_svcs6 = array(
		array( 'id' => 'google-analytics', 'category' => 'statistics', 'cookies' => $fx_ck1, 'source' => 'library' ),
		array( 'id' => 'recaptcha', 'category' => 'necessary', 'exempt' => true, 'cookies' => $fx_ck1, 'source' => 'library' ),
		array( 'id' => 'hubspot', 'category' => 'marketing', 'exempt' => true, 'cookies' => $fx_ck1, 'source' => 'library' ),
		array( 'id' => 'custom-1', 'category' => 'necessary', 'cookies' => $fx_ck1, 'source' => 'custom' ),
		array( 'id' => 'custom-2', 'category' => 'marketing', 'cookies' => $fx_ck1, 'source' => 'custom' ),
		array( 'id' => 'youtube', 'category' => 'marketing', 'cookies' => $fx_ck1, 'source' => 'library', 'type' => 'embed' ),
	);
	$fx_ids6 = function ( $list ) {
		return array_map( function ( $s ) {
			return $s['id'];
		}, $list );
	};
	$fx_light = CMP_Legal::table_for_mode( $fx_cats6, $fx_svcs6, 'light' );
	cmp_assert_eq( $fx_ids6( $fx_light['services'] ), array( 'recaptcha', 'hubspot', 'custom-1' ), 'T6 light: only necessary and exempt services' );
	cmp_assert_eq( count( $fx_light['categories'] ), 2, 'T6 light: categories kept (labels)' );
	cmp_assert_eq( $fx_light['categories'][0]['cookies'], $fx_cats6[0]['cookies'], 'T6 light: manual cookies of a necessary category kept' );
	cmp_assert_eq( $fx_light['categories'][1]['cookies'], array(), 'T6 light: manual cookies of an optional category dropped' );
	$fx_pro = CMP_Legal::table_for_mode( $fx_cats6, $fx_svcs6, 'pro' );
	cmp_assert_eq( $fx_pro, array( 'categories' => $fx_cats6, 'services' => $fx_svcs6 ), 'T6 pro: unchanged' );
	cmp_assert_eq( CMP_Legal::table_for_mode( $fx_cats6, $fx_svcs6, '' ), $fx_pro, 'T6 unknown mode: unchanged (pro)' );
	$fx_html = CMP_Legal::cookie_table_html( $fx_light['categories'], $fx_light['services'] );
	cmp_assert( false === strpos( $fx_html, 'manual_stat' ) && false !== strpos( $fx_html, 'sess' ), 'T6 light: rendered table has no optional-category cookie' );
}
cmp_assert( 1 === preg_match( '/function current_table_html\(.*?self::table_for_mode\(/s', $fx_legal ) && 1 === preg_match( '/function sc_cookie_table\(\) \{\s*return self::current_table_html\(/', $fx_legal ) && 1 === preg_match( "/function privacy_guide\(\).*?self::current_table_html\( 'ro' \)/s", $fx_legal ), 'T6: shortcode and privacy guide both go through table_for_mode' );

// ---------------------------------------------------------------------------
// M2 / M3: templates notice cleared after all three documents, or on dismiss.
cmp_assert( method_exists( 'CMP_Legal_Page', 'regenerated_types' ), 'M3: CMP_Legal_Page::regenerated_types exists' );
cmp_assert( method_exists( 'CMP_Legal_Page', 'all_regenerated' ), 'M3: CMP_Legal_Page::all_regenerated exists' );
if ( method_exists( 'CMP_Legal_Page', 'regenerated_types' ) && method_exists( 'CMP_Legal_Page', 'all_regenerated' ) ) {
	cmp_assert_eq( CMP_Legal_Page::regenerated_types( false, 'privacy' ), array( 'privacy' ), 'M3: first regenerated type' );
	cmp_assert_eq( CMP_Legal_Page::regenerated_types( array( 'privacy' ), 'privacy' ), array( 'privacy' ), 'M3: no duplicates' );
	cmp_assert_eq( CMP_Legal_Page::regenerated_types( array( 'privacy', 'junk', 5 ), 'cookie' ), array( 'privacy', 'cookie' ), 'M3: unknown entries dropped' );
	cmp_assert_eq( CMP_Legal_Page::regenerated_types( 'x', 'nope' ), array(), 'M3: bad input -> empty' );
	cmp_assert_eq( CMP_Legal_Page::all_regenerated( array( 'privacy', 'cookie' ) ), false, 'M3: two of three -> notice stays' );
	cmp_assert_eq( CMP_Legal_Page::all_regenerated( array( 'cookie', 'terms', 'privacy' ) ), true, 'M3: all three -> notice cleared' );
}
cmp_assert( 1 === preg_match( "/function handle\(\).*?CMP_Legal_Page::all_regenerated\(|function handle\(\).*?self::all_regenerated\(/s", $fx_lp ), 'M3: handle() clears the notice only when all types are done' );
cmp_assert( 1 === preg_match( "/function dismiss_templates_notice\(\).*?delete_option\( 'cmp_templates_notice' \);\s*delete_option\( 'cmp_templates_regenerated' \);/s", $fx_lp ), 'M3: dismiss clears the notice and the tracking option' );
$fx_uninst = $fx_src( 'includes/class-uninstaller.php' );
cmp_assert( false !== strpos( $fx_uninst, "'cmp_templates_notice'" ), 'M2: uninstall removes cmp_templates_notice' );
cmp_assert( false !== strpos( $fx_uninst, "'cmp_templates_regenerated'" ), 'M3: uninstall removes cmp_templates_regenerated' );

// ---------------------------------------------------------------------------
// Texts: I3 (legacy re-consent qualifier), M6 (GPC on a policy change), M7.
$fx_txt = array(
	'ro' => array( 'gpc' => 'sau alegerea ta nu mai e valabilă', 'legacy' => 'Dacă alegerea ta e anterioară acestei verificări' ),
	'ru' => array( 'gpc' => 'или ваш выбор больше не действует', 'legacy' => 'Если ваш выбор был сделан до появления этой проверки' ),
	'en' => array( 'gpc' => 'or your choice is no longer valid', 'legacy' => 'If your choice predates this check' ),
);
foreach ( $fx_txt as $fx_l => $fx_e ) {
	$fx_c = CMP_Legal::load_template( 'cookie', $fx_l );
	cmp_assert( false !== strpos( $fx_c, $fx_e['gpc'] ), "M6 $fx_l/cookie: GPC also covers an invalid (expired / policy-changed) choice" );
	cmp_assert( false !== strpos( $fx_c, $fx_e['legacy'] ), "I3 $fx_l/cookie: re-consent baseline for earlier choices stated" );
}
// Internal review document: present only in the internal repo (skipped in the public one).
if ( is_readable( dirname( __DIR__ ) . '/docs/product/2026-11-18-texte-juridice-pentru-revizie.md' ) ) {
	$fx_pack = $fx_src( 'docs/product/2026-11-18-texte-juridice-pentru-revizie.md' );
	foreach ( $fx_txt as $fx_l => $fx_e ) {
		cmp_assert( false !== strpos( $fx_pack, $fx_e['gpc'] ) && false !== strpos( $fx_pack, $fx_e['legacy'] ), "M6/I3: lawyer pack carries the $fx_l wording" );
	}
	cmp_assert( false === strpos( $fx_pack, 'nu sunt re-întrebate până la următoarea decizie' ), 'I3: lawyer pack no longer says 0.4.x consents are never re-asked' );
} else {
	echo "SKIP: internal lawyer pack not present\n";
}
cmp_assert( false === strpos( $fx_lp, 'art. 30' ), 'M7: operator note cites no unverified article number' );
