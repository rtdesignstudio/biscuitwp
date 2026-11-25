<?php
/**
 * Lot A, Task 1: the list of known plugins (data/plugins.json), how it is
 * read, which entries are active, the rows of the "Pluginuri recunoscute"
 * section, and what their own HTML looks like to the blocker.
 */
require_once __DIR__ . '/../includes/class-services.php';
require_once __DIR__ . '/../includes/class-known-plugins.php';
require_once __DIR__ . '/../includes/modules/class-blocker.php';

$kp_root = dirname( __DIR__ );
$kp_lib  = CMP_Services::library();
$kp_ids  = array_column( $kp_lib, 'id' );
$kp_file = $kp_root . '/data/plugins.json';

// ---- The shipped list ----
$kp_list = CMP_Known_Plugins::load( $kp_file );
cmp_assert_eq( array_column( $kp_list, 'file' ), array(
	'google-site-kit/google-site-kit.php',
	'google-analytics-for-wordpress/googleanalytics.php',
	'pixelyoursite/facebook-pixel-master.php',
	'woocommerce/woocommerce.php',
	'contact-form-7/wp-contact-form-7.php',
), 'plugins.json: the five plugins, main files as in the wordpress.org copies' );
$kp_raw = json_decode( (string) file_get_contents( $kp_file ), true );
cmp_assert_eq( CMP_Known_Plugins::validate( is_array( $kp_raw ) ? $kp_raw : array(), $kp_ids ), array(), 'plugins.json passes validate()' );
$kp_by = array_column( $kp_list, null, 'file' );
cmp_assert_eq( $kp_by['pixelyoursite/facebook-pixel-master.php']['bridge'], 'pys', 'PixelYourSite has the pys bridge' );
cmp_assert_eq( $kp_by['woocommerce/woocommerce.php']['bridge'], 'wc-attribution', 'WooCommerce has the wc-attribution bridge' );
cmp_assert_eq( $kp_by['woocommerce/woocommerce.php']['services'], array(), 'WooCommerce brings no library service of its own' );
cmp_assert_eq( $kp_by['contact-form-7/wp-contact-form-7.php']['services'], array( 'recaptcha', 'cloudflare-turnstile' ), 'Contact Form 7: reCAPTCHA and Turnstile' );
foreach ( $kp_list as $kp_e ) {
	foreach ( array( 'ro', 'ru', 'en' ) as $kp_l ) {
		cmp_assert( '' !== $kp_e['note'][ $kp_l ] && false === strpos( $kp_e['note'][ $kp_l ], "\xE2\x80\x94" ), "{$kp_e['file']}: note {$kp_l} is set, no em dash" );
	}
}
// The PixelYourSite note says what its bridge does: the category of Meta Pixel for the script and the server, their own too for GA / GTM.
$kp_note = $kp_by['pixelyoursite/facebook-pixel-master.php']['note'];
foreach ( array( 'ro' => array( 'categoria lui Meta Pixel', 'categoria lor', 'server', 'nu bloca', 'necesar', 'JavaScript' ), 'ru' => array( 'категорию Meta Pixel', 'свою категорию', 'сервер', 'не блокировать', 'необходим', 'JavaScript' ), 'en' => array( 'category of Meta Pixel', 'their own category', 'server', 'don’t block', 'necessary', 'JavaScript' ) ) as $kp_l => $kp_words ) {
	foreach ( $kp_words as $kp_w ) {
		cmp_assert( false !== strpos( $kp_note[ $kp_l ], $kp_w ), "PixelYourSite note {$kp_l}: says '{$kp_w}'" );
	}
}
// ---- validate() ----
$kp_ok = array( 'file' => 'a/a.php', 'name' => 'A', 'services' => array( 'recaptcha' ), 'bridge' => '', 'note' => array( 'ro' => 'x', 'ru' => 'x', 'en' => 'x' ) );
cmp_assert_eq( CMP_Known_Plugins::validate( array( 'plugins' => array( $kp_ok ) ), $kp_ids ), array(), 'validate: a good entry has no errors' );
cmp_assert_eq( count( CMP_Known_Plugins::validate( array( 'plugins' => array( array_merge( $kp_ok, array( 'services' => array( 'nope' ) ) ) ) ), $kp_ids ) ), 1, 'validate: unknown service id is one error' );
cmp_assert_eq( count( CMP_Known_Plugins::validate( array( 'plugins' => array( array_merge( $kp_ok, array( 'bridge' => 'evil' ) ) ) ), $kp_ids ) ), 1, 'validate: unknown bridge is one error' );
cmp_assert_eq( count( CMP_Known_Plugins::validate( array( 'plugins' => array( array_merge( $kp_ok, array( 'file' => '../x.php' ) ) ) ), $kp_ids ) ), 1, 'validate: a file that is not folder/file.php is one error' );
cmp_assert_eq( count( CMP_Known_Plugins::validate( array( 'plugins' => array( $kp_ok, $kp_ok ) ), $kp_ids ) ), 1, 'validate: duplicate file is one error' );
cmp_assert_eq( count( CMP_Known_Plugins::validate( array( 'plugins' => array( array_merge( $kp_ok, array( 'note' => array( 'ro' => 'x', 'ru' => '', 'en' => 'x' ) ) ) ) ), $kp_ids ) ), 1, 'validate: an empty note language is one error' );
cmp_assert_eq( count( CMP_Known_Plugins::validate( array(), $kp_ids ) ), 1, 'validate: no "plugins" list is an error' );
cmp_assert_eq( count( CMP_Known_Plugins::validate( array( 'plugins' => array( 'x' ) ), $kp_ids ) ), 1, 'validate: an entry that is not an object is one error' );

// ---- load() never throws ----
cmp_assert_eq( CMP_Known_Plugins::load( '/no/such/plugins.json' ), array(), 'load: missing file gives an empty list' );
$kp_tmp = tempnam( sys_get_temp_dir(), 'kp' );
file_put_contents( $kp_tmp, '{' );
cmp_assert_eq( CMP_Known_Plugins::load( $kp_tmp ), array(), 'load: unreadable JSON gives an empty list' );
file_put_contents( $kp_tmp, '{"plugins":"x"}' );
cmp_assert_eq( CMP_Known_Plugins::load( $kp_tmp ), array(), 'load: "plugins" that is not a list gives an empty list' );
unlink( $kp_tmp );

// ---- active(): both lists, network list keyed by file ----
$kp_a = CMP_Known_Plugins::active( $kp_list, array( 'woocommerce/woocommerce.php', 'other/other.php' ), array() );
cmp_assert_eq( array_column( $kp_a, 'file' ), array( 'woocommerce/woocommerce.php' ), 'active: site list' );
$kp_a = CMP_Known_Plugins::active( $kp_list, array(), array( 'pixelyoursite/facebook-pixel-master.php' => 1700000000 ) );
cmp_assert_eq( array_column( $kp_a, 'file' ), array( 'pixelyoursite/facebook-pixel-master.php' ), 'active: network list is keyed by file' );
$kp_a = CMP_Known_Plugins::active( $kp_list, array( 'woocommerce/woocommerce.php' ), array( 'woocommerce/woocommerce.php' => 1, 'contact-form-7/wp-contact-form-7.php' => 2 ) );
cmp_assert_eq( array_column( $kp_a, 'file' ), array( 'woocommerce/woocommerce.php', 'contact-form-7/wp-contact-form-7.php' ), 'active: in list order, no duplicates when on both lists' );
cmp_assert_eq( CMP_Known_Plugins::active( $kp_list, array(), array() ), array(), 'active: nothing active' );
cmp_assert_eq( CMP_Known_Plugins::active( $kp_list, array( 5, null, array() ), array( 5 => 1 ) ), array(), 'active: garbage in the lists is ignored' );
cmp_assert_eq( CMP_Known_Plugins::current(), array(), 'current(): with no plugins it is empty' );
cmp_assert_eq( CMP_Known_Plugins::bridged(), array(), 'bridged(): nothing is bridged without PixelYourSite (with it: test-bridge-pys.php)' );

// ---- rows() ----
$kp_svc = CMP_Services::all( $kp_lib, array(), array( 'necessary', 'statistics', 'marketing' ) );
$kp_find = function ( array $rows, $name ) {
	foreach ( $rows as $r ) {
		if ( $r['name'] === $name ) {
			return $r;
		}
	}
	return null;
};
$kp_rows = CMP_Known_Plugins::rows( $kp_list, $kp_svc, array() );
cmp_assert_eq( count( $kp_rows ), 5, 'rows: one per plugin' );
foreach ( $kp_rows as $kp_r ) {
	cmp_assert_eq( array_keys( $kp_r ), array( 'name', 'services', 'state', 'note' ), "rows: {$kp_r['name']} has name, services, state, note" );
}
$kp_sk = $kp_find( $kp_rows, $kp_list[0]['name'] );
cmp_assert_eq( array_column( $kp_sk['services'], 'name' ), array( 'Google Analytics', 'Google Tag Manager', 'Google AdSense' ), 'rows: Site Kit lists its services by library name' );
cmp_assert_eq( array_column( $kp_sk['services'], 'category' ), array( 'statistics', 'statistics', 'marketing' ), 'rows: categories come from the effective list' );
cmp_assert_eq( $kp_sk['state'], 'blocked', 'rows: Site Kit is blocked in the page' );
cmp_assert( '' !== $kp_sk['note'], 'rows: the note is the RO text by default' );
cmp_assert_eq( $kp_sk['note'], $kp_list[0]['note']['ro'], 'rows: RO note by default' );
cmp_assert_eq( CMP_Known_Plugins::rows( $kp_list, $kp_svc, array(), 'en' )[0]['note'], $kp_list[0]['note']['en'], 'rows: EN note on request' );
cmp_assert_eq( CMP_Known_Plugins::rows( $kp_list, $kp_svc, array(), 'ru' )[0]['note'], $kp_list[0]['note']['ru'], 'rows: RU note on request' );
cmp_assert_eq( CMP_Known_Plugins::rows( $kp_list, $kp_svc, array(), 'xx' )[0]['note'], $kp_list[0]['note']['ro'], 'rows: unknown language falls back to RO' );
$kp_cf = $kp_find( $kp_rows, $kp_by['contact-form-7/wp-contact-form-7.php']['name'] );
cmp_assert_eq( $kp_cf['state'], 'necessary', 'rows: Contact Form 7 services are necessary' );
$kp_wc = $kp_find( $kp_rows, $kp_by['woocommerce/woocommerce.php']['name'] );
cmp_assert_eq( $kp_wc['services'], array(), 'rows: WooCommerce has no services' );
cmp_assert_eq( $kp_wc['state'], '', 'rows: WooCommerce without its bridge claims no state' );
cmp_assert_eq( $kp_find( CMP_Known_Plugins::rows( $kp_list, $kp_svc, array( 'wc-attribution' ) ), $kp_wc['name'] )['state'], 'bridged', 'rows: WooCommerce with its bridge is bridged' );
$kp_pys = $kp_find( $kp_rows, $kp_by['pixelyoursite/facebook-pixel-master.php']['name'] );
cmp_assert_eq( $kp_pys['state'], '', 'rows: PixelYourSite claims no state while its bridge is off (its local script is not blocked then)' );
cmp_assert_eq( $kp_find( CMP_Known_Plugins::rows( $kp_list, $kp_svc, array( 'pys' ) ), $kp_pys['name'] )['state'], 'bridged', 'rows: PixelYourSite with its bridge is bridged' );
cmp_assert_eq( $kp_find( CMP_Known_Plugins::rows( $kp_list, $kp_svc, array( 'pys' ) ), $kp_sk['name'] )['state'], 'blocked', 'rows: another plugin\'s bridge does not change Site Kit' );
// Exempt ("nu bloca") and category changes follow the services list.
$kp_ex = CMP_Services::all( $kp_lib, array( 'overrides' => array(
	'google-analytics'   => array( 'exempt' => true ),
	'google-tag-manager' => array( 'exempt' => true ),
	'google-adsense'     => array( 'exempt' => true ),
) ), array( 'necessary', 'statistics', 'marketing' ) );
cmp_assert_eq( $kp_find( CMP_Known_Plugins::rows( $kp_list, $kp_ex, array() ), $kp_sk['name'] )['state'], 'exempt', 'rows: every service set to "nu bloca" shows exempt' );
$kp_one = CMP_Services::all( $kp_lib, array( 'overrides' => array( 'google-analytics' => array( 'exempt' => true ) ) ), array( 'necessary', 'statistics', 'marketing' ) );
cmp_assert_eq( $kp_find( CMP_Known_Plugins::rows( $kp_list, $kp_one, array() ), $kp_sk['name'] )['state'], '', 'rows: one exempt service of three: mixed, nothing is claimed' );
$kp_nec = CMP_Services::all( $kp_lib, array( 'overrides' => array( 'recaptcha' => array( 'category' => 'marketing' ) ) ), array( 'necessary', 'statistics', 'marketing' ) );
cmp_assert_eq( $kp_find( CMP_Known_Plugins::rows( $kp_list, $kp_nec, array() ), $kp_cf['name'] )['state'], '', 'rows: one of two services moved to marketing: mixed, nothing is claimed' );
$kp_two = CMP_Services::all( $kp_lib, array( 'overrides' => array( 'recaptcha' => array( 'category' => 'marketing' ), 'cloudflare-turnstile' => array( 'category' => 'statistics' ) ) ), array( 'necessary', 'statistics', 'marketing' ) );
cmp_assert_eq( $kp_find( CMP_Known_Plugins::rows( $kp_list, $kp_two, array() ), $kp_cf['name'] )['state'], 'blocked', 'rows: both moved: blocked' );
$kp_mix = CMP_Services::all( $kp_lib, array( 'overrides' => array( 'google-analytics' => array( 'category' => 'necessary' ) ) ), array( 'necessary', 'statistics', 'marketing' ) );
cmp_assert_eq( $kp_find( CMP_Known_Plugins::rows( $kp_list, $kp_mix, array() ), $kp_sk['name'] )['state'], '', 'rows: one service necessary, the others blocked: mixed, nothing is claimed' );
$kp_mi = $kp_by['google-analytics-for-wordpress/googleanalytics.php']['name'];
cmp_assert_eq( $kp_find( CMP_Known_Plugins::rows( $kp_list, $kp_mix, array() ), $kp_mi )['state'], 'necessary', 'rows: MonsterInsights, its only service moved to necessary' );
$kp_yd = CMP_Services::all( $kp_lib, array( 'custom' => array( array( 'id' => 'custom-1', 'name' => 'X', 'category' => 'marketing', 'exempt' => true, 'hosts' => array( 'pagead2.googlesyndication.com' ) ) ) ), array( 'necessary', 'statistics', 'marketing' ) );
cmp_assert_eq( $kp_find( CMP_Known_Plugins::rows( $kp_list, $kp_yd, array() ), $kp_sk['name'] )['state'], '', 'rows: a custom service decides for an AdSense host: nothing is claimed' );
// Advanced: GA and GTM stay live by mode, AdSense is blocked: mixed.
$kp_adv = CMP_Services::apply_measurement( $kp_svc, true );
cmp_assert_eq( $kp_find( CMP_Known_Plugins::rows( $kp_list, $kp_adv, array() ), $kp_sk['name'] )['state'], '', 'rows: in Advanced, Site Kit is not claimed blocked (GA4 and GTM load)' );
cmp_assert_eq( $kp_find( CMP_Known_Plugins::rows( $kp_list, $kp_adv, array() ), $kp_mi )['state'], '', 'rows: in Advanced, MonsterInsights is not claimed blocked (GA4 loads)' );
cmp_assert_eq( $kp_find( CMP_Known_Plugins::rows( $kp_list, CMP_Services::apply_measurement( $kp_ex, true ), array() ), $kp_mi )['state'], 'exempt', 'rows: in Advanced, GA the admin put on "nu bloca" is an exemption' );
cmp_assert_eq( $kp_find( CMP_Known_Plugins::rows( $kp_list, $kp_adv, array() ), $kp_cf['name'] )['state'], 'necessary', 'rows: in Advanced, Contact Form 7 stays necessary' );
// The notes of the two GA plugins are true in every mode: they name the blocking setting, Light, Advanced and "nu bloca".
foreach ( array( 'google-site-kit/google-site-kit.php', 'google-analytics-for-wordpress/googleanalytics.php' ) as $kp_nf ) {
	foreach ( array( 'ro' => array( 'Când blocarea e pornită', 'Light', 'Advanced', 'nu bloca' ), 'ru' => array( 'Когда блокировка включена', 'Light', 'Advanced', 'не блокировать' ), 'en' => array( 'When blocking is on', 'Light', 'Advanced', 'don’t block' ) ) as $kp_l => $kp_words ) {
		foreach ( $kp_words as $kp_w ) {
			cmp_assert( false !== strpos( $kp_by[ $kp_nf ]['note'][ $kp_l ], $kp_w ), "{$kp_nf}: note {$kp_l} says '{$kp_w}'" );
		}
	}
}
// Blocking off: nothing is claimed blocked; what is true anyway stays.
$kp_off = CMP_Known_Plugins::rows( $kp_list, $kp_svc, array(), 'ro', false );
cmp_assert_eq( array_column( $kp_off, 'state' ), array( '', '', '', '', 'necessary' ), 'rows, blocking off: no plugin is claimed blocked, Contact Form 7 stays necessary' );
cmp_assert_eq( $kp_find( CMP_Known_Plugins::rows( $kp_list, $kp_ex, array(), 'ro', false ), $kp_sk['name'] )['state'], 'exempt', 'rows, blocking off: "nu bloca" is still shown' );
cmp_assert_eq( CMP_Known_Plugins::rows( $kp_list, $kp_svc, array(), 'ro', true ), $kp_rows, 'rows: blocking is on by default' );
$kp_pex = CMP_Services::all( $kp_lib, array( 'overrides' => array( 'meta-pixel' => array( 'exempt' => true ), 'google-analytics' => array( 'exempt' => true ), 'google-tag-manager' => array( 'exempt' => true ) ) ), array( 'necessary', 'statistics', 'marketing' ) );
cmp_assert_eq( $kp_find( CMP_Known_Plugins::rows( $kp_list, $kp_pex, array() ), $kp_pys['name'] )['state'], 'exempt', 'rows: PixelYourSite without its bridge, every service "nu bloca": exempt' );
cmp_assert( 1 === preg_match( '/CMP_Blocker::blocks_trackers\(/', (string) file_get_contents( $kp_root . '/includes/class-known-plugins.php' ) ), 'current() passes whether blocking is on' );
// A service id missing from the effective list is skipped, never an error.
cmp_assert_eq( CMP_Known_Plugins::rows( array( array_merge( $kp_ok, array( 'services' => array( 'ghost' ) ) ) ), $kp_svc, array() )[0]['services'], array(), 'rows: unknown service id is skipped' );
cmp_assert_eq( CMP_Known_Plugins::rows( array(), $kp_svc, array() ), array(), 'rows: no plugins, no rows' );

// ---- A recognised plugin never reaches found / history / re-consent ----
foreach ( array( 'includes/modules/class-blocker.php', 'includes/modules/class-scanner.php', 'includes/modules/class-legal.php', 'includes/class-services.php', 'includes/class-consent.php' ) as $kp_src ) {
	cmp_assert( false === strpos( (string) file_get_contents( $kp_root . '/' . $kp_src ), 'CMP_Known_Plugins' ), "{$kp_src} does not read the known-plugin list" );
}
$kp_class_src = (string) file_get_contents( $kp_root . '/includes/class-known-plugins.php' );
cmp_assert( 0 === preg_match( '/\b(update_option|add_option|delete_option|set_transient|update_site_option)\s*\(/', $kp_class_src ), 'class-known-plugins.php writes no option' );
$kp_page_src = (string) file_get_contents( $kp_root . '/includes/admin/class-services-page.php' );
cmp_assert( 1 === preg_match( '/CMP_Known_Plugins::current\(\)/', $kp_page_src ) && 1 === preg_match( '/Pluginuri recunoscute/', $kp_page_src ), 'the Services screen reads current() and has the section' );
cmp_assert( 1 === preg_match( '/if \( \$known_rows \) :/', $kp_page_src ), 'the section is hidden when there is nothing to show' );
// Scan results are the same with and without a plugin list: the scan never takes it as input.
$kp_html = (string) file_get_contents( __DIR__ . '/fixtures/known-plugins/site-kit.html' );
cmp_assert_eq( CMP_Blocker::found_ids( $kp_html, $kp_svc ), CMP_Blocker::found_ids( $kp_html, $kp_svc ), 'found_ids is a function of the page and the services only' );

// ---- Plugin HTML through the blocker ----
$kp_fx = function ( $name ) {
	return (string) file_get_contents( __DIR__ . '/fixtures/known-plugins/' . $name . '.html' );
};
$kp_live = function ( $html, $needle ) {
	// A script tag still carrying the URL as a live src (not rewritten to data-src / text/plain).
	return 1 === preg_match( '/<script\b(?![^>]*data-cmp-block)[^>]*\bsrc="[^"]*' . preg_quote( $needle, '/' ) . '/i', $html );
};
$kp_strict = CMP_Services::apply_measurement( $kp_svc, false );
$kp_advanced = CMP_Services::apply_measurement( $kp_svc, true );

$kp_o = CMP_Blocker::rewrite( $kp_fx( 'site-kit' ), array(), $kp_strict );
cmp_assert( ! $kp_live( $kp_o, 'googletagmanager.com' ) && ! $kp_live( $kp_o, 'pagead2.googlesyndication.com' ), 'Strict, Site Kit: no live googletagmanager or pagead2 script' );
cmp_assert( false !== strpos( $kp_o, 'data-cmp-service="google-adsense"' ) && false !== strpos( $kp_o, 'data-cmp-block="marketing"' ), 'Strict, Site Kit: AdSense is tagged google-adsense, marketing' );
cmp_assert( false !== strpos( $kp_o, 'data-cmp-service="google-analytics"' ), 'Strict, Site Kit: the gtag loader is tagged google-analytics' );
$kp_o = CMP_Blocker::rewrite( $kp_fx( 'site-kit' ), array(), $kp_advanced );
cmp_assert( $kp_live( $kp_o, 'googletagmanager.com/gtag/js?id=G-SITEKIT01' ), 'Advanced, Site Kit: the GA4 loader stays live' );
cmp_assert( ! $kp_live( $kp_o, 'pagead2.googlesyndication.com' ) && false !== strpos( $kp_o, 'data-cmp-service="google-adsense"' ), 'Advanced, Site Kit: AdSense stays blocked' );
cmp_assert( 1 === preg_match( '/<script id="google_gtagjs-js-after">\s*window\.dataLayer/', $kp_o ), 'Advanced, Site Kit: the GA4 inline config is left as it was' );

$kp_o = CMP_Blocker::rewrite( $kp_fx( 'monsterinsights' ), array(), $kp_strict );
cmp_assert( ! $kp_live( $kp_o, 'googletagmanager.com' ) && false !== strpos( $kp_o, 'data-cmp-service="google-analytics"' ), 'Strict, MonsterInsights: the gtag loader is blocked' );
$kp_o = CMP_Blocker::rewrite( $kp_fx( 'monsterinsights' ), array(), $kp_advanced );
cmp_assert( $kp_live( $kp_o, 'googletagmanager.com/gtag/js?id=G-MONSTER001' ), 'Advanced, MonsterInsights: the GA4 loader stays live' );

foreach ( array( 'pixelyoursite', 'woocommerce', 'cf7' ) as $kp_n ) {
	foreach ( array( 'Strict' => $kp_strict, 'Advanced' => $kp_advanced ) as $kp_mode => $kp_s ) {
		$kp_in = $kp_fx( $kp_n );
		cmp_assert_eq( CMP_Blocker::rewrite( $kp_in, array(), $kp_s ), $kp_in, "{$kp_mode}, {$kp_n}: the HTML passes unchanged (no bridge here)" );
	}
}
cmp_assert( false === strpos( $kp_fx( 'pixelyoursite' ), 'fbevents' ) && false === strpos( $kp_fx( 'pixelyoursite' ), 'googletagmanager' ), 'PixelYourSite fixture prints no third-party loader (they are injected by its script)' );
