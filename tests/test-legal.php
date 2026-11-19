<?php
require_once __DIR__ . '/../includes/modules/class-legal.php';

$tpl = "Salut {{name}}, la {{site}}. {{missing}} rămâne.";
$out = CMP_Legal::fill_template( $tpl, array( 'name' => 'Ana', 'site' => 'PlataEgala' ) );
cmp_assert_eq( $out, 'Salut Ana, la PlataEgala.  rămâne.', 'fill_template replaces known tokens, drops unknown' );

$priv = CMP_Legal::load_template( 'privacy' );
cmp_assert( strpos( $priv, '{{site_name}}' ) !== false, 'privacy template has site_name placeholder' );
cmp_assert_eq( CMP_Legal::load_template( 'nope' ), '', 'missing template returns empty string' );

$cats = array(
	array( 'slug' => 'necessary', 'label' => 'Necesare', 'cookies' => array() ),
	array( 'slug' => 'statistics', 'label' => 'Statistici', 'cookies' => array(
		array( 'name' => '_ga', 'provider' => 'Google', 'duration' => '2 ani', 'purpose' => 'Analytics <x>' ),
	) ),
);
$html = CMP_Legal::cookie_table_html( $cats );
cmp_assert( strpos( $html, '<table' ) !== false, 'cookie table renders a table' );
cmp_assert( strpos( $html, '_ga' ) !== false, 'cookie table lists cookie name' );
cmp_assert( strpos( $html, 'Analytics &lt;x&gt;' ) !== false, 'cookie purpose is html-escaped' );

$empty = CMP_Legal::cookie_table_html( array( array( 'slug' => 'necessary', 'cookies' => array() ) ) );
cmp_assert( strpos( $empty, '<table' ) === false, 'no cookies => no table' );

// 0.4.2: status of each legal page, from its WordPress post status.
cmp_assert_eq( CMP_Legal::page_status( 'publish' ), 'published', 'publish -> published' );
cmp_assert_eq( CMP_Legal::page_status( 'draft' ), 'draft', 'draft -> draft' );
cmp_assert_eq( CMP_Legal::page_status( 'pending' ), 'draft', 'pending -> draft' );
cmp_assert_eq( CMP_Legal::page_status( 'private' ), 'draft', 'private -> draft (visitors cannot see it)' );
cmp_assert_eq( CMP_Legal::page_status( 'trash' ), 'missing', 'trash -> missing' );
cmp_assert_eq( CMP_Legal::page_status( false ), 'missing', 'no post -> missing' );

// Summary line for the settings screen.
cmp_assert_eq( CMP_Legal::status_summary( array( 'published', 'draft', 'missing' ) ), array( 'published' => 1, 'draft' => 1, 'missing' => 1 ), 'counts by status' );
cmp_assert_eq( CMP_Legal::status_summary( array() ), array( 'published' => 0, 'draft' => 0, 'missing' => 0 ), 'empty -> zeros' );

// B1 Task 7: cookie table from services.
$t7_cats = array(
	array( 'slug' => 'necessary', 'label' => 'Necesare', 'cookies' => array() ),
	array( 'slug' => 'statistics', 'label' => 'Statistici', 'cookies' => array(
		array( 'name' => 'manual_ck', 'provider' => 'Me', 'duration' => '1 zi', 'purpose' => 'Man' ),
	) ),
);
$t7_ga = array(
	'id' => 'ga', 'name' => 'Google Analytics', 'provider' => 'Google Ireland Ltd.', 'category' => 'statistics',
	'transfer' => 'US',
	'cookies' => array( array( 'name' => '_ga', 'duration' => array( 'ro' => '2 ani', 'ru' => '2 года', 'en' => '2 years' ), 'purpose' => array( 'ro' => 'Statistici RO', 'ru' => 'Статистика', 'en' => 'Stats EN' ) ) ),
);
$t7_rc = array(
	'id' => 'recaptcha', 'name' => 'reCAPTCHA', 'provider' => 'Google', 'category' => 'necessary', 'exempt' => true,
	'transfer' => '',
	'cookies' => array( array( 'name' => '_GRECAPTCHA', 'duration' => array( 'ro' => '6 luni', 'en' => '6 months' ), 'purpose' => array( 'ro' => 'Anti-spam' ) ) ),
);
$t7_nock  = array( 'id' => 'nock', 'name' => 'NoCk', 'provider' => 'X', 'category' => 'marketing', 'cookies' => array() );
$t7_cust  = array( 'id' => 'custom_1', 'source' => 'custom', 'name' => 'Custom', 'provider' => 'Acme <b>', 'category' => 'marketing', 'transfer' => '',
	'cookies' => array( array( 'name' => 'c<b>k', 'duration' => array( 'ro' => '1h', 'ru' => '1h', 'en' => '1h' ), 'purpose' => array( 'ro' => 'p', 'ru' => 'p', 'en' => 'p' ) ) ) );
$t7_cust2 = array( 'id' => 'custom_2', 'source' => 'custom', 'name' => 'Custom2', 'provider' => 'Beta', 'category' => 'marketing', 'transfer' => '',
	'cookies' => array( array( 'name' => 'c2', 'duration' => array( 'ro' => '1h' ), 'purpose' => array( 'ro' => 'p' ) ) ) );
$t7_lib   = array( $t7_cust, $t7_ga, $t7_rc, $t7_nock, $t7_cust2 );

$ids = function ( $list ) { return array_map( function ( $s ) { return $s['id']; }, $list ); };
cmp_assert_eq( $ids( CMP_Legal::table_services( array( 'recaptcha', 'ga' ), $t7_lib ) ), array( 'recaptcha', 'ga', 'custom_1', 'custom_2' ), 'table_services: found in found order, then unfound custom' );
cmp_assert_eq( $ids( CMP_Legal::table_services( array( 'ga', 'ga', 'custom_2' ), $t7_lib ) ), array( 'ga', 'custom_2', 'custom_1' ), 'table_services: dedupe, found custom not repeated' );
cmp_assert_eq( $ids( CMP_Legal::table_services( array( 'nock', 'zzz_unknown' ), $t7_lib ) ), array( 'custom_1', 'custom_2' ), 'table_services: no-cookie and unknown ids skipped' );
cmp_assert_eq( $ids( CMP_Legal::table_services( array( 'recaptcha' ), $t7_lib ) )[0], 'recaptcha', 'table_services: necessary service included when found' );

$h = CMP_Legal::cookie_table_html( $t7_cats, array( $t7_ga ), 'ro' );
cmp_assert( strpos( $h, '<td>_ga</td>' ) !== false && strpos( $h, 'Google Ireland Ltd.' ) !== false && strpos( $h, '2 ani' ) !== false && strpos( $h, '<td>US</td>' ) !== false, 'service row: name, provider, RO duration, transfer' );
cmp_assert( strpos( $h, '<th>Transfer</th>' ) !== false && strpos( $h, '<th>Durată</th>' ) !== false, 'RO headings incl. Transfer' );
cmp_assert( strpos( $h, '<td>Statistici</td>' ) !== false, 'service category label from categories' );
cmp_assert( strpos( $h, 'manual_ck' ) !== false && strpos( $h, '<td>—</td></tr>' ) !== false, 'manual cookie row has — in Transfer' );
cmp_assert( strpos( $h, '_ga' ) < strpos( $h, 'manual_ck' ), 'service rows precede manual rows' );
$en = CMP_Legal::cookie_table_html( $t7_cats, array( $t7_ga ), 'en' );
cmp_assert( strpos( $en, '2 years' ) !== false && strpos( $en, 'Stats EN' ) !== false && strpos( $en, '<th>Name</th>' ) !== false, 'EN lang: texts and headings' );
$ru = CMP_Legal::cookie_table_html( $t7_cats, array( $t7_ga ), 'ru' );
cmp_assert( strpos( $ru, '<th>Название</th>' ) !== false && strpos( $ru, '2 года' ) !== false, 'RU headings and texts' );
$ruf = CMP_Legal::cookie_table_html( $t7_cats, array( $t7_rc ), 'ru' );
cmp_assert( strpos( $ruf, 'Anti-spam' ) !== false && strpos( $ruf, '6 luni' ) !== false, 'missing RU text falls back to RO' );
$esc = CMP_Legal::cookie_table_html( $t7_cats, array( $t7_cust ), 'ro' );
cmp_assert( strpos( $esc, 'c&lt;b&gt;k' ) !== false && strpos( $esc, 'Acme &lt;b&gt;' ) !== false && strpos( $esc, '<b>' ) === false, 'service cookie name and provider escaped' );
$cat_fb = CMP_Legal::cookie_table_html( array(), array( $t7_ga ), 'ro' );
cmp_assert( strpos( $cat_fb, '<td>statistics</td>' ) !== false, 'category label falls back to slug' );
cmp_assert( strpos( CMP_Legal::cookie_table_html( array(), array(), 'ru' ), 'Файлы cookie не определены' ) !== false, 'RU empty-state' );
cmp_assert( strpos( CMP_Legal::cookie_table_html( array(), array(), 'en' ), 'No cookies have been defined.' ) !== false, 'EN empty-state' );
cmp_assert_eq( CMP_Legal::cookie_table_html( array() ), '<p>Nu au fost definite cookie-uri.</p>', 'RO empty-state unchanged' );

// B1 Task 7 fix round 1.
$t7_tpl = CMP_Legal::load_template( 'cookie' );
cmp_assert( strpos( $t7_tpl, '[cmp_cookie_table]' ) !== false, 'cookie template uses the [cmp_cookie_table] shortcode' );
cmp_assert( strpos( $t7_tpl, '{{cookie_table}}' ) === false, 'cookie template has no {{cookie_table}} placeholder' );
$t7_seed = array( array( 'slug' => 'statistics', 'label' => 'Statistici', 'cookies' => array() ), array( 'slug' => 'x', 'label' => 'Custom cat', 'cookies' => array() ) );
$t7_ru = CMP_Legal::cookie_table_html( $t7_seed, array( $t7_ga, array_merge( $t7_ga, array( 'id' => 'g2', 'category' => 'x' ) ) ), 'ru' );
cmp_assert( strpos( $t7_ru, '<td>Статистика</td>' ) !== false, 'RU table localizes the seed category label' );
cmp_assert( strpos( $t7_ru, '<td>Custom cat</td>' ) !== false, 'custom category label stays as stored' );
cmp_assert( strpos( CMP_Legal::cookie_table_html( $t7_seed, array( $t7_ga ), 'ru' ), '<th>Срок хранения</th>' ) !== false, 'RU heading Срок хранения' );
cmp_assert_eq( count( CMP_Legal::table_services( array( array( 'x' ) ), array( array( 'id' => array( 'x' ), 'cookies' => array( 1 ) ) ) ) ), 0, 'non-scalar ids are ignored' );
cmp_assert_eq( CMP_Lang::text( 'tbl_name', 'ro' ), 'Nume', 'tbl_name RO in CMP_Lang' );

// B4 Task 5: per-language templates, conditional blocks, operator address.
cmp_assert_eq( CMP_Legal::fill_template( '<p>A</p>{{#address}}<p>{{address}}</p>{{/address}}', array( 'address' => '' ) ), '<p>A</p>', 'empty address drops the block' );
cmp_assert( strpos( CMP_Legal::fill_template( '<p>A</p>{{#address}}<p>{{address}}</p>{{/address}}', array( 'address' => 'str. X 1' ) ), '<p>str. X 1</p>' ) !== false, 'address block kept and filled' );
cmp_assert_eq( CMP_Legal::fill_template( 'a {{necunoscut}} b', array() ), 'a  b', 'unknown placeholder disappears' );
cmp_assert_eq( CMP_Legal::fill_template( "A\n{{#address}}\n<p>\n{{address}}\n</p>\n{{/address}}\nB", array( 'address' => '' ) ), "A\n\nB", 'multi-line block dropped when empty' );
cmp_assert( strpos( CMP_Legal::fill_template( "A\n{{#address}}\n<p>\n{{address}}\n</p>\n{{/address}}\nB", array( 'address' => 'X' ) ), "<p>\nX\n</p>" ) !== false, 'multi-line block kept when set' );
cmp_assert_eq( CMP_Legal::fill_template( '{{#dpo}}x{{/dpo}}', array() ), '', 'block with an absent key is dropped' );
cmp_assert( CMP_Legal::load_template( 'privacy', 'ru' ) !== '', 'ru template loads' );
cmp_assert_eq( CMP_Legal::load_template( 'privacy', 'de' ), CMP_Legal::load_template( 'privacy', 'ro' ), 'unknown language falls back to ro' );
cmp_assert_eq( CMP_Legal::load_template( 'nope', 'ro' ), '', 'unknown type is empty in any language' );
cmp_assert_eq( CMP_Legal::page_lang_for( 'ru_RU' ), 'ru', 'ru_RU -> ru' );
cmp_assert_eq( CMP_Legal::page_lang_for( 'en-US' ), 'en', 'en-US -> en' );
cmp_assert_eq( CMP_Legal::page_lang_for( 'de' ), 'ro', 'de -> ro' );
foreach ( array( 'ro', 'ru', 'en' ) as $cmp_l ) {
	foreach ( array( 'title_privacy', 'title_terms', 'title_cookie' ) as $cmp_k ) {
		cmp_assert( CMP_Lang::text( $cmp_k, $cmp_l ) !== '', "$cmp_k exists in $cmp_l" );
	}
}
cmp_assert_eq( CMP_Lang::text( 'title_terms', 'en' ), 'Terms and Conditions', 'EN terms title' );
cmp_assert_eq( CMP_Legal::locale_for( 'ru' ), 'ru_RU', 'locale_for ru' );
cmp_assert_eq( CMP_Legal::locale_for( 'en-US' ), 'en_US', 'locale_for en-US' );
cmp_assert_eq( CMP_Legal::locale_for( 'de' ), 'ro_RO', 'locale_for unknown -> ro_RO' );

// B4 Task 6: the template texts (RO/RU/EN) and the new settings variables.
$t6_expect = array(
	'ro' => array(
		'disclaimer' => 'nu constituie consultanță juridică', 'authority' => 'CNPDCP', 'register' => 'Registrul de consimțăminte',
		'before' => 'nu se încarcă până nu îți dai acordul', 'gpc_cond' => 'nu ai ales încă', 'light' => 'bară informativă',
		'rights' => 'Drepturile tale ca persoană vizată', 'remove' => 'Nu mai încărca',
	),
	'ru' => array(
		'disclaimer' => 'не является юридической консультацией', 'authority' => 'НЦЗПД', 'register' => 'Реестр согласий',
		'before' => 'не загружаются, пока вы не дадите согласие', 'gpc_cond' => 'ещё не сделали выбор', 'light' => 'информационная панель',
		'rights' => 'Ваши права как субъекта данных', 'remove' => 'Больше не загружать',
	),
	'en' => array(
		'disclaimer' => 'not legal advice', 'authority' => 'NCPDP', 'register' => 'The consent register',
		'before' => 'do not load until you give your consent', 'gpc_cond' => 'not chosen yet', 'light' => 'information bar',
		'rights' => 'Your rights as a data subject', 'remove' => 'Stop loading',
	),
);
$t6_known = array( 'company', 'site_name', 'contact_email', 'dpo', 'jurisdiction', 'address', 'effective_date', 'retention_months', 'cookie_days', 'pro', 'light', 'blocking', 'advanced' );
$t6_base  = array(
	'company' => 'Acme SRL', 'site_name' => 'Acme', 'contact_email' => 'a@b.md', 'dpo' => 'Ion DPO',
	'jurisdiction' => 'Republica Moldova', 'address' => 'str. X 1, Chișinău', 'effective_date' => '18.11.2026',
	'retention_months' => '13', 'cookie_days' => '180',
);
$t6_modes = array(
	'blocking' => array( 'pro' => '1', 'light' => '', 'blocking' => '1' ),
	'pro'      => array( 'pro' => '1', 'light' => '', 'blocking' => '' ),
	'light'    => array( 'pro' => '', 'light' => '1', 'blocking' => '' ),
);
$t6_sections = array();
$t6_render   = array();
foreach ( $t6_expect as $l => $exp ) {
	foreach ( array( 'privacy', 'cookie', 'terms' ) as $type ) {
		$tpl = CMP_Legal::load_template( $type, $l );
		cmp_assert( false !== strpos( $tpl, $exp['disclaimer'] ), "$l/$type: disclaimer present" );
		preg_match_all( '/\{\{([#\/]?)([a-z0-9_]+)\}\}/', $tpl, $mm, PREG_SET_ORDER );
		$unknown = array();
		$stack   = array();
		$bad     = array();
		foreach ( $mm as $m ) {
			if ( ! in_array( $m[2], $t6_known, true ) ) {
				$unknown[] = $m[2];
			}
			if ( '#' === $m[1] ) {
				if ( $stack ) {
					$bad[] = 'nested ' . $m[2];
				}
				$stack[] = $m[2];
			} elseif ( '/' === $m[1] ) {
				if ( array_pop( $stack ) !== $m[2] ) {
					$bad[] = 'unbalanced ' . $m[2];
				}
			}
		}
		cmp_assert_eq( $unknown, array(), "$l/$type: only known placeholders" );
		cmp_assert_eq( array_merge( $bad, $stack ), array(), "$l/$type: conditional blocks balanced, not nested" );
		cmp_assert( false === strpos( $tpl, '{{address}}.' ) && false === strpos( $tpl, '{{dpo}}.' ), "$l/$type: no period after address/dpo" );
		preg_match_all( '/<h3>/', $tpl, $hh );
		$t6_sections[ $type ][ $l ] = count( $hh[0] );
		foreach ( $t6_modes as $mode => $mv ) {
			foreach ( array( 'full' => array(), 'bare' => array( 'address' => '', 'dpo' => '', 'jurisdiction' => '' ) ) as $fill => $over ) {
				$out = CMP_Legal::fill_template( $tpl, array_merge( $t6_base, $mv, $over ) );
				cmp_assert( false === strpos( $out, '{{' ) && false === strpos( $out, '}}' ), "$l/$type/$mode/$fill: no {{ left" );
				cmp_assert( ! preg_match( '/<p>\s*<\/p>|<li>\s*<\/li>|<ul>\s*<\/ul>/u', $out ), "$l/$type/$mode/$fill: no empty p/li/ul" );
				cmp_assert( ! preg_match( '/<!-- wp:(paragraph|list|list-item) -->\s*<!-- \/wp:\1 -->/', $out ), "$l/$type/$mode/$fill: no empty block" );
				cmp_assert( ! preg_match( '/<p>[^<]*\s\.<\/p>|\s\.\s/u', strip_tags( $out, '<p>' ) ), "$l/$type/$mode/$fill: no dangling ' .'" );
			}
			$t6_render[ $l ][ $type ][ $mode ] = CMP_Legal::fill_template( $tpl, array_merge( $t6_base, $mv ) );
			preg_match_all( '/<h3>/', $t6_render[ $l ][ $type ][ $mode ], $rh );
			$t6_sections[ "$type/$mode" ][ $l ] = count( $rh[0] );
		}
	}
	$priv = CMP_Legal::load_template( 'privacy', $l );
	foreach ( array( '195/2024', $exp['authority'], 'datepersonale.md', '{{#address}}', '{{#dpo}}', '{{retention_months}}', '{{cookie_days}}', '2016/679', $exp['rights'] ) as $needle ) {
		cmp_assert( false !== strpos( $priv, $needle ), "$l/privacy contains $needle" );
	}
	cmp_assert( false !== strpos( $priv, CMP_Lang::text( 'title_cookie', $l ) ), "$l/privacy names the cookie policy" );
	$ck = CMP_Legal::load_template( 'cookie', $l );
	foreach ( array( '72/2025', '116', '[cmp_cookie_table]', '{{cookie_days}}', '[cmp_cookie_settings_link]', '195/2024', 'cmp_consent', 'cmp_svc', CMP_Lang::text( 'embed_always', $l ), 'Google Analytics' ) as $needle ) {
		cmp_assert( false !== strpos( $ck, $needle ), "$l/cookie contains $needle" );
	}
	cmp_assert( false !== strpos( $ck, "<!-- wp:shortcode -->[cmp_cookie_table]<!-- /wp:shortcode -->\n" ), "$l/cookie: table shortcode on its own line" );
	cmp_assert( false !== strpos( $ck, CMP_Lang::text( 'title_privacy', $l ) ), "$l/cookie names the privacy policy" );
	$tm = CMP_Legal::load_template( 'terms', $l );
	cmp_assert( false !== strpos( $tm, CMP_Lang::text( 'title_privacy', $l ) ), "$l/terms names the privacy policy" );
	cmp_assert( false !== strpos( $tm, CMP_Lang::text( 'title_cookie', $l ) ), "$l/terms names the cookie policy" );

	// Mode-dependent facts.
	$r = $t6_render[ $l ];
	foreach ( array( 'blocking', 'pro' ) as $m ) {
		cmp_assert( false !== strpos( $r['privacy'][ $m ], $exp['register'] ), "$l/$m: register section present" );
		cmp_assert( false !== strpos( $r['privacy'][ $m ], 'cmp_consent' ) || false !== strpos( $r['cookie'][ $m ], 'cmp_consent' ), "$l/$m: cmp_consent named" );
		cmp_assert( false !== strpos( $r['cookie'][ $m ], '[cmp_cookie_settings_link]' ), "$l/$m: settings link present" );
		cmp_assert( false !== strpos( $r['cookie'][ $m ], 'Global Privacy Control' ) && false !== strpos( $r['cookie'][ $m ], $exp['gpc_cond'] ), "$l/$m: GPC only before a choice" );
		cmp_assert( false === strpos( $r['cookie'][ $m ], $exp['light'] ), "$l/$m: no light-bar text" );
		cmp_assert( false !== strpos( $r['cookie'][ $m ], '{{cookie_days}}' ) || false !== strpos( $r['cookie'][ $m ], '180' ), "$l/$m: consent duration stated" );
	}
	foreach ( array( 'privacy', 'cookie' ) as $type ) {
		cmp_assert( false !== strpos( $r[ $type ]['blocking'], $exp['before'] ), "$l/$type/blocking: before-consent claim present" );
		cmp_assert( false === strpos( $r[ $type ]['pro'], $exp['before'] ), "$l/$type/pro without blocking: no before-consent claim" );
		cmp_assert( false === strpos( $r[ $type ]['light'], $exp['before'] ), "$l/$type/light: no before-consent claim" );
	}
	cmp_assert( false !== strpos( $r['cookie']['blocking'], CMP_Lang::text( 'embed_always', $l ) ) && false !== strpos( $r['cookie']['blocking'], '×' ), "$l/blocking: always-load section with ×" );
	cmp_assert( false === strpos( $r['cookie']['pro'], CMP_Lang::text( 'embed_always', $l ) ), "$l/pro without blocking: no always-load section" );
	cmp_assert( false === strpos( $ck, $exp['remove'] ), "$l/cookie: no invisible 'stop loading' label" );
	foreach ( array( 'privacy', 'cookie', 'terms' ) as $type ) {
		$lt = $r[ $type ]['light'];
		cmp_assert( false === strpos( $lt, 'cmp_consent' ) && false === strpos( $lt, 'cmp_svc' ), "$l/$type/light: no cmp_consent/cmp_svc" );
		cmp_assert( false === strpos( $lt, '[cmp_cookie_settings_link]' ), "$l/$type/light: no settings link" );
		cmp_assert( false === strpos( $lt, $exp['register'] ), "$l/$type/light: no consent register" );
		cmp_assert( false === strpos( $lt, 'Global Privacy Control' ), "$l/$type/light: no GPC" );
		cmp_assert( false === strpos( $lt, CMP_Lang::text( 'embed_always', $l ) ), "$l/$type/light: no always-load" );
		cmp_assert( false === strpos( $lt, 'Consent Mode' ), "$l/$type/light: no Consent Mode" );
	}
	cmp_assert( false !== strpos( $r['cookie']['light'], $exp['light'] ) && false !== strpos( $r['cookie']['light'], 'cmp_light_dismissed' ), "$l/light: information bar described" );
	cmp_assert( false !== strpos( $r['cookie']['light'], CMP_Lang::text( 'embed_load', $l ) ), "$l/light: load-once embed button named" );
}
cmp_assert( false === strpos( CMP_Legal::load_template( 'privacy', 'en' ), 'in clear' ) && false !== strpos( CMP_Legal::load_template( 'privacy', 'en' ), 'plain text' ), 'en/privacy: plain text wording' );
foreach ( $t6_sections as $type => $counts ) {
	cmp_assert( $counts['ro'] >= 5 && $counts['ro'] === $counts['ru'] && $counts['ro'] === $counts['en'], "$type: same number of sections in ro/ru/en" );
}

// Owner decision: "tu" in the RO banner intro (plugin and standalone).
$t6_intro = 'Folosim cookie-uri esențiale pentru funcționarea site-ului și, cu acordul tău, cookie-uri de analiză pentru a-l îmbunătăți. Poți accepta sau refuza cookie-urile opționale.';
cmp_assert_eq( CMP_Lang::text( 'intro', 'ro' ), $t6_intro, 'RO banner intro uses "tu"' );
$t6_cmpjs = (string) file_get_contents( __DIR__ . '/../standalone/cmp.js' );
cmp_assert( false !== strpos( $t6_cmpjs, $t6_intro ) && false === strpos( $t6_cmpjs, 'dumneavoastră' ), 'standalone RO intro uses "tu"' );
cmp_assert( false === strpos( (string) file_get_contents( __DIR__ . '/../includes/modules/class-lang.php' ), 'dumneavoastră' ), 'class-lang has no "dumneavoastră"' );

// build_content gets retention_months, cookie_days and the mode flags from the settings.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once __DIR__ . '/../includes/class-sanitizer.php';
require_once __DIR__ . '/../includes/admin/class-legal-page.php';
cmp_assert( method_exists( 'CMP_Legal_Page', 'settings_vars' ), 'CMP_Legal_Page::settings_vars exists' );
if ( method_exists( 'CMP_Legal_Page', 'settings_vars' ) ) {
	$t6_sv = function ( $s ) {
		return CMP_Legal_Page::settings_vars( $s );
	};
	cmp_assert_eq( $t6_sv( array() ), array( 'retention_months' => '13', 'cookie_days' => '180', 'pro' => '1', 'light' => '', 'blocking' => '' ), 'settings_vars defaults (pro, blocking off as in CMP_Blocker)' );
	cmp_assert_eq( $t6_sv( array( 'retention_months' => 24, 'cookie_days' => 90, 'mode' => 'pro', 'block_scripts' => true ) ), array( 'retention_months' => '24', 'cookie_days' => '90', 'pro' => '1', 'light' => '', 'blocking' => '1' ), 'settings_vars: pro with blocking' );
	cmp_assert_eq( $t6_sv( array( 'retention_months' => 0, 'cookie_days' => 9999, 'mode' => 'pro', 'block_scripts' => false ) ), array( 'retention_months' => '13', 'cookie_days' => '396', 'pro' => '1', 'light' => '', 'blocking' => '' ), 'settings_vars: invalid retention -> 13, days capped, pro without blocking' );
	cmp_assert_eq( $t6_sv( array( 'mode' => 'light', 'block_scripts' => true ) ), array( 'retention_months' => '13', 'cookie_days' => '180', 'pro' => '', 'light' => '1', 'blocking' => '' ), 'settings_vars: light (never "blocking")' );
	cmp_assert_eq( $t6_sv( array( 'mode' => 'weird' ) )['pro'], '1', 'settings_vars: unknown mode -> pro' );
}
$t6_src = (string) file_get_contents( __DIR__ . '/../includes/admin/class-legal-page.php' );
preg_match( '/function build_content\(.*?\n\t}\n/s', $t6_src, $t6_bc );
cmp_assert( isset( $t6_bc[0] ) && false !== strpos( $t6_bc[0], 'self::settings_vars( CMP_Consent::get_settings() )' ), 'build_content merges settings_vars from the settings' );
cmp_assert( false !== strpos( $t6_src, 'După ce schimbi durata acordului sau a registrului în Setări, regenerează paginile legale.' ), 'legal screen: regenerate note for frozen durations' );

// B4 Task 6, fix round 2: re-consent wording without blocking; Light names exempt services.
cmp_assert( false === strpos( $t6_render['ro']['cookie']['pro'], 'rămâne blocată' ), 'ro/cookie/pro without blocking: no "rămâne blocată"' );
cmp_assert( false !== strpos( $t6_render['ro']['cookie']['blocking'], 'rămâne blocată' ), 'ro/cookie/blocking: "rămâne blocată" kept' );
cmp_assert( false === strpos( $t6_render['ru']['cookie']['pro'], 'заблокированной' ) && false === strpos( $t6_render['en']['cookie']['pro'], 'stays blocked' ), 'ru/en cookie/pro without blocking: no "blocked" re-consent claim' );
foreach ( array( 'ro' => 'tratată ca refuzată', 'ru' => 'считается отклонённой', 'en' => 'treated as refused' ) as $l => $needle ) {
	cmp_assert( false !== strpos( $t6_render[ $l ]['cookie']['pro'], $needle ), "$l/cookie/pro: re-consent category treated as refused" );
}
foreach ( array( 'ro' => 'marcat ca excepție', 'ru' => 'отметил как исключение', 'en' => 'marked as exempt' ) as $l => $needle ) {
	cmp_assert( false !== strpos( $t6_render[ $l ]['cookie']['light'], $needle ) && false !== strpos( $t6_render[ $l ]['privacy']['light'], $needle ), "$l/light: exempt services mentioned" );
}

// B4 Task 7: operator responsibilities note.
foreach ( array(
	'__'         => 'return $t;',
	'esc_html'   => 'return htmlspecialchars( $t, ENT_QUOTES, "UTF-8" );',
	'esc_html__' => 'return htmlspecialchars( $t, ENT_QUOTES, "UTF-8" );',
	'esc_url'    => 'return htmlspecialchars( $t, ENT_QUOTES, "UTF-8" );',
	'admin_url'  => 'return "http://example.test/wp-admin/" . $t;',
) as $t7_fn => $t7_body ) {
	if ( ! function_exists( $t7_fn ) ) {
		eval( "function $t7_fn( \$t ) { $t7_body }" );
	}
}
if ( ! function_exists( 'wp_nonce_url' ) ) {
	function wp_nonce_url( $url, $action ) {
		return $url . '&_wpnonce=test';
	}
}
cmp_assert( method_exists( 'CMP_Legal_Page', 'operator_note_html' ), 'CMP_Legal_Page::operator_note_html exists' );
if ( method_exists( 'CMP_Legal_Page', 'operator_note_html' ) ) {
	$t7_open   = CMP_Legal_Page::operator_note_html( true );
	$t7_closed = CMP_Legal_Page::operator_note_html( false );
	foreach ( array( 'Ce face pluginul', 'Ce rămâne la tine', 'Ce face pluginul și ce rămâne la tine',
		'bannerul de cookie-uri', 'blocarea serviciilor până la acord', 'registrul de consimțăminte', 'șabloanele paginilor legale', 'tabelul de cookie-uri', 're-consimțământul la servicii noi',
		'registrul activităților de prelucrare', 'responsabil cu protecția datelor (DPO)', 'temeiul legal pentru transferurile de date în afara țării', 'informarea pentru alte prelucrări (formulare, newsletter, magazin)', 'verificarea textelor de către un jurist', 'notificarea CNPDCP', 'serviciile pe care pluginul nu le recunoaște' ) as $t7_needle ) {
		cmp_assert( false !== strpos( $t7_open, $t7_needle ), "operator note contains: $t7_needle" );
	}
	cmp_assert( 1 === preg_match( '/<details class="cmp-operator-note" open>/', $t7_open ), 'operator note: $open=true -> <details ... open>' );
	cmp_assert( 1 === preg_match( '/<details class="cmp-operator-note">/', $t7_closed ) && ! preg_match( '/<details[^>]*\bopen\b/', $t7_closed ), 'operator note: $open=false -> no open attribute' );
	cmp_assert( 1 === substr_count( $t7_open, '<summary>' ) && 2 === substr_count( $t7_open, '<ul' ) && 1 === substr_count( $t7_open, '</details>' ), 'operator note: one summary, two lists' );
	cmp_assert( false === strpos( $t7_open, '<script' ) && false === strpos( $t7_open, 'onclick' ), 'operator note: no script / inline handlers' );
	cmp_assert( false !== strpos( $t7_open, 'cmp_dismiss_operator_note' ) && false !== strpos( $t7_open, '_wpnonce' ), 'operator note: nonce dismiss link' );
}
$t7_src = (string) file_get_contents( __DIR__ . '/../includes/admin/class-legal-page.php' );
cmp_assert( false !== strpos( $t7_src, "get_option( 'cmp_operator_note_seen' )" ) && false !== strpos( $t7_src, "update_option( 'cmp_operator_note_seen'" ), 'legal screen: note seen option read and set' );
cmp_assert( false !== strpos( (string) file_get_contents( __DIR__ . '/../uninstall.php' ), "'cmp_operator_note_seen'" ), 'uninstall removes cmp_operator_note_seen' );

// B2 Task 4: legal texts for the Advanced measurement mode, per-page mode notice.
require_once __DIR__ . '/../includes/class-services.php';
$b2_adv    = array( 'mode' => 'pro', 'block_scripts' => true, 'measurement' => 'advanced' );
$b2_strict = array( 'mode' => 'pro', 'block_scripts' => true, 'measurement' => 'strict' );
$b2_light  = array( 'mode' => 'light', 'block_scripts' => true, 'measurement' => 'advanced' );
cmp_assert( method_exists( 'CMP_Legal_Page', 'measurement_vars' ), 'CMP_Legal_Page::measurement_vars exists' );
cmp_assert( method_exists( 'CMP_Legal_Page', 'page_mode' ), 'CMP_Legal_Page::page_mode exists' );
cmp_assert( method_exists( 'CMP_Legal_Page', 'mode_notices' ), 'CMP_Legal_Page::mode_notices exists' );
if ( method_exists( 'CMP_Legal_Page', 'measurement_vars' ) && method_exists( 'CMP_Legal_Page', 'page_mode' ) && method_exists( 'CMP_Legal_Page', 'mode_notices' ) ) {
	$b2_mv = function ( $s, $h ) {
		return CMP_Legal_Page::measurement_vars( $s, $h );
	};
	cmp_assert_eq( $b2_mv( $b2_adv, array( 'youtube', 'google-analytics' ) ), array( 'advanced' => '1' ), 'advanced var: Advanced with GA in history' );
	cmp_assert_eq( $b2_mv( $b2_adv, array( 'google-tag-manager' ) ), array( 'advanced' => '1' ), 'advanced var: Advanced with GTM in history' );
	cmp_assert_eq( $b2_mv( $b2_adv, array( 'youtube', 'meta-pixel' ) ), array( 'advanced' => '' ), 'advanced var: Advanced without GA/GTM in history' );
	cmp_assert_eq( $b2_mv( $b2_adv, false ), array( 'advanced' => '' ), 'advanced var: no history' );
	cmp_assert_eq( $b2_mv( $b2_strict, array( 'google-analytics' ) ), array( 'advanced' => '' ), 'advanced var: Strict' );
	cmp_assert_eq( $b2_mv( array( 'mode' => 'pro', 'block_scripts' => false, 'measurement' => 'advanced' ), array( 'google-analytics' ) ), array( 'advanced' => '' ), 'advanced var: Advanced without blocking has no effect' );
	cmp_assert_eq( $b2_mv( $b2_light, array( 'google-analytics' ) ), array( 'advanced' => '' ), 'advanced var: Light' );
	cmp_assert_eq( $b2_mv( $b2_adv, array( array( 'google-analytics' ), 7 ) ), array( 'advanced' => '' ), 'advanced var: non-string history entries ignored' );

	$b2_ga = array( 'google-analytics' );
	cmp_assert_eq( CMP_Legal_Page::page_mode( $b2_adv, $b2_ga ), 'advanced', 'page_mode: Advanced active with GA in history' );
	cmp_assert_eq( CMP_Legal_Page::page_mode( $b2_adv, array( 'google-tag-manager' ) ), 'advanced', 'page_mode: Advanced active with GTM in history' );
	cmp_assert_eq( CMP_Legal_Page::page_mode( $b2_adv, array() ), 'strict', 'page_mode (fix 1): Advanced without GA/GTM history -> strict (the variant rendered)' );
	cmp_assert_eq( CMP_Legal_Page::page_mode( $b2_adv, array( 'youtube' ) ), 'strict', 'page_mode (fix 1): Advanced with unrelated history -> strict' );
	cmp_assert_eq( CMP_Legal_Page::page_mode( $b2_strict, $b2_ga ), 'strict', 'page_mode: Strict' );
	cmp_assert_eq( CMP_Legal_Page::page_mode( $b2_light, $b2_ga ), 'light', 'page_mode: Light (whatever measurement says)' );
	cmp_assert_eq( CMP_Legal_Page::page_mode( array( 'mode' => 'pro', 'measurement' => 'advanced' ), $b2_ga ), 'strict', 'page_mode: Advanced without blocking -> strict' );
	cmp_assert_eq( CMP_Legal_Page::page_mode( array(), array() ), 'strict', 'page_mode: defaults -> strict' );
	foreach ( array( array( $b2_adv, $b2_ga ), array( $b2_adv, array() ), array( $b2_strict, $b2_ga ), array( $b2_light, $b2_ga ) ) as $b2_c ) {
		cmp_assert_eq( 'advanced' === CMP_Legal_Page::page_mode( $b2_c[0], $b2_c[1] ), '1' === CMP_Legal_Page::measurement_vars( $b2_c[0], $b2_c[1] )['advanced'], 'page_mode and the advanced variable agree' );
	}
	// Scenario: page generated in Advanced before GA was seen -> stamped strict; GA appears -> notice.
	$b2_stamp = CMP_Legal_Page::page_mode( $b2_adv, array() );
	cmp_assert_eq( CMP_Legal_Page::mode_notices( array( array( 'type' => 'cookie', 'title' => 'C', 'mode' => $b2_stamp ) ), CMP_Legal_Page::page_mode( $b2_adv, $b2_ga ) ), array( '„C” a fost generată pentru alt mod de măsurare. Regenerează pagina.' ), 'mode_notices (fix 1): page stamped strict gets the notice once GA is in history' );
	cmp_assert_eq( CMP_Legal_Page::mode_notices( array( array( 'type' => 'terms', 'title' => 'T', 'mode' => 'strict' ) ), 'advanced' ), array(), 'mode_notices (fix 1, M-1): terms never gets the notice' );
	cmp_assert_eq( CMP_Legal_Page::mode_notices( array( array( 'type' => 'privacy', 'title' => 'P', 'mode' => 'advanced' ) ), 'strict' ), array( '„P” a fost generată pentru alt mod de măsurare. Regenerează pagina.' ), 'mode_notices: privacy Advanced -> Strict notice' );

	$b2_n = CMP_Legal_Page::mode_notices( array(
		array( 'title' => 'Politica de cookie-uri', 'mode' => 'strict' ),
		array( 'title' => 'Politica de confidențialitate', 'mode' => 'advanced' ),
		array( 'title' => 'Termeni', 'mode' => '' ),
	), 'advanced' );
	cmp_assert_eq( $b2_n, array( '„Politica de cookie-uri” a fost generată pentru alt mod de măsurare. Regenerează pagina.' ), 'mode_notices: only pages with a different stored mode; none without meta' );
	cmp_assert_eq( CMP_Legal_Page::mode_notices( array( array( 'title' => 'X', 'mode' => 'light' ) ), 'light' ), array(), 'mode_notices: same mode -> none' );

	// Rendering: four cases x cookie/privacy x RO/RU/EN.
	$b2_cases = array(
		'adv_ga'   => array( $b2_adv, array( 'google-analytics' ) ),
		'adv_none' => array( $b2_adv, array( 'youtube' ) ),
		'strict'   => array( $b2_strict, array( 'google-analytics', 'google-tag-manager' ) ),
		'light'    => array( $b2_light, array( 'google-analytics' ) ),
	);
	$b2_pin = array(
		'ro' => array(
			'cookie'  => array( 'Ne arată cum este folosit site-ul, ca să îl putem îmbunătăți. Îți cerem acordul pentru ele.', '(de exemplu video sau hărți), nu se încarcă până nu îți dai acordul.', 'Serviciile refuzate nu se mai încarcă de la următoarea pagină.', 'e tratată ca refuzată și rămâne blocată.' ),
			'privacy' => array( 'Temei: consimțământul tău.', 'Serviciile configurate în instrumentul nostru de cookie-uri nu se încarcă până nu îți dai acordul.' ),
		),
		'ru' => array(
			'cookie'  => array( 'Показывают нам, как используется сайт, чтобы мы могли его улучшить. Мы запрашиваем ваше согласие на их использование.', '(например, видео или карты), не загружаются, пока вы не дадите согласие.', 'Отклонённые сервисы перестают загружаться со следующей страницы.', 'считается отклонённой и остаётся заблокированной.' ),
			'privacy' => array( 'Основание: ваше согласие.', 'Сервисы, настроенные в нашем инструменте cookie, не загружаются, пока вы не дадите согласие.' ),
		),
		'en' => array(
			'cookie'  => array( 'They show us how the website is used, so we can improve it. We ask for your consent to use them.', '(for example videos or maps), do not load until you give your consent.', 'Rejected services stop loading from the next page.', 'is treated as refused and stays blocked.' ),
			'privacy' => array( 'Legal basis: your consent.', 'The services configured in our cookie tool do not load until you give your consent.' ),
		),
	);
	$b2_mark = array(
		'ro' => array( 'cookie' => 'Pe acest site, Google Analytics și Google Tag Manager se încarcă înainte de alegerea ta, fără cookie-uri până îți dai acordul.', 'privacy' => '<strong>Măsurarea fără cookie-uri</strong>' ),
		'ru' => array( 'cookie' => 'На этом сайте Google Analytics и Google Tag Manager загружаются до того, как вы сделаете выбор, без файлов cookie, пока вы не дадите согласие.', 'privacy' => '<strong>Измерение без файлов cookie</strong>' ),
		'en' => array( 'cookie' => 'On this website, Google Analytics and Google Tag Manager load before you make your choice, without cookies until you give your consent.', 'privacy' => '<strong>Measurement without cookies</strong>' ),
	);
	$b2_gtm = array(
		'ro' => 'Google Tag Manager poate încărca și alte etichete configurate de site; acestea trebuie să respecte alegerea ta, iar site-ul răspunde de configurarea lor.',
		'ru' => 'Google Tag Manager может загружать и другие теги, настроенные сайтом; они должны соблюдать ваш выбор, а за их настройку отвечает сайт.',
		'en' => 'Google Tag Manager can also load other tags that the website configures; those must respect your choice, and the website is responsible for configuring them.',
	);
	$b2_exempt = array(
		'ro' => 'Se pot încărca fără acord serviciile pe care site-ul le-a marcat ca excepție, de exemplu unelte de statistică fără cookie-uri.',
		'ru' => 'Без согласия могут загружаться сервисы, которые сайт отметил как исключение, например инструменты статистики без файлов cookie.',
		'en' => 'Services the website has marked as exempt, for example cookieless statistics tools, may load without consent.',
	);
	$b2_light_ex = array(
		'ro' => '(de exemplu, pentru securitatea formularelor sau pentru statistici fără cookie-uri)',
		'ru' => '(например, для безопасности форм или для статистики без файлов cookie)',
		'en' => '(for example, for form security or for cookieless statistics)',
	);
	$b2_ip = array( 'ro' => 'adresa IP', 'ru' => 'IP-адрес', 'en' => 'IP address' );
	foreach ( $b2_pin as $l => $by_type ) {
		foreach ( $by_type as $type => $pins ) {
			$tpl = CMP_Legal::load_template( $type, $l );
			$out = array();
			foreach ( $b2_cases as $case => $c ) {
				$vars = array_merge( $t6_base, CMP_Legal_Page::settings_vars( $c[0] ), CMP_Legal_Page::measurement_vars( $c[0], $c[1] ) );
				foreach ( array( 'full' => array(), 'bare' => array( 'address' => '', 'dpo' => '', 'jurisdiction' => '' ) ) as $fill => $over ) {
					$o = CMP_Legal::fill_template( $tpl, array_merge( $vars, $over ) );
					cmp_assert( false === strpos( $o, '{{' ) && false === strpos( $o, '}}' ), "b2 $l/$type/$case/$fill: no {{ left" );
					cmp_assert( ! preg_match( '/<p>\s*<\/p>|<li>\s*<\/li>|<ul>\s*<\/ul>/u', $o ), "b2 $l/$type/$case/$fill: no empty p/li/ul" );
					cmp_assert( ! preg_match( '/<!-- wp:(paragraph|list|list-item) -->\s*<!-- \/wp:\1 -->/', $o ), "b2 $l/$type/$case/$fill: no empty block" );
					cmp_assert( ! preg_match( '/<p>[^<]*\s\.<\/p>|\s\.\s|\s,|,,|\.\./u', strip_tags( $o, '<p>' ) ), "b2 $l/$type/$case/$fill: no dangling punctuation" );
				}
				$out[ $case ] = CMP_Legal::fill_template( $tpl, $vars );
			}
			$mark = $b2_mark[ $l ][ $type ];
			cmp_assert( false !== strpos( $out['adv_ga'], $mark ), "b2 $l/$type/adv_ga: Advanced paragraph present" );
			cmp_assert( false !== strpos( $out['adv_ga'], 'Google Analytics' ) && false !== strpos( $out['adv_ga'], 'Google Tag Manager' ), "b2 $l/$type/adv_ga: names GA and GTM" );
			cmp_assert( false !== strpos( $out['adv_ga'], $b2_ip[ $l ] ), "b2 $l/$type/adv_ga: IP address stated" );
			cmp_assert( false !== strpos( $out['adv_ga'], $b2_gtm[ $l ] ), "b2 $l/$type/adv_ga (fix 1, M-3): GTM other-tags caveat" );
			foreach ( array( 'adv_none', 'strict', 'light' ) as $case ) {
				cmp_assert( false === strpos( $out[ $case ], $b2_gtm[ $l ] ), "b2 $l/$type/$case: no GTM caveat" );
			}
			foreach ( array( 'adv_ga', 'strict' ) as $case ) {
				cmp_assert( false !== strpos( $out[ $case ], $b2_exempt[ $l ] ), "b2 $l/$type/$case (fix 1, I-2): exempt cookieless tools may load without consent" );
			}
			cmp_assert( false !== strpos( $out['light'], $b2_light_ex[ $l ] ), "b2 $l/$type/light (fix 1, M-5): same exempt examples in cookie and privacy" );
			foreach ( array( 'adv_none', 'strict', 'light' ) as $case ) {
				cmp_assert( false === strpos( $out[ $case ], $mark ), "b2 $l/$type/$case: no Advanced paragraph" );
			}
			cmp_assert_eq( $out['adv_none'], $out['strict'], "b2 $l/$type: Advanced without GA/GTM renders as Strict" );
			foreach ( $pins as $i => $pin ) {
				cmp_assert( false !== strpos( $out['strict'], $pin ), "b2 $l/$type/strict: sentence $i kept" );
				cmp_assert( false === strpos( $out['adv_ga'], $pin ), "b2 $l/$type/adv_ga: sentence $i (false in Advanced) absent" );
			}
		}
	}
}
$b2_src  = (string) file_get_contents( __DIR__ . '/../includes/admin/class-legal-page.php' );
$b2_lsrc = (string) file_get_contents( __DIR__ . '/../includes/modules/class-legal.php' );
preg_match( '/function build_content\(.*?\n\t}\n/s', $b2_src, $b2_bc );
cmp_assert( isset( $b2_bc[0] ) && false !== strpos( $b2_bc[0], 'self::measurement_vars(' ) && false !== strpos( $b2_bc[0], "'cmp_services_history'" ), 'build_content merges measurement_vars from the settings and cmp_services_history' );
preg_match( '/function generate_page\(.*?\n\t}\n/s', $b2_src, $b2_gp );
cmp_assert( isset( $b2_gp[0] ) && false !== strpos( $b2_gp[0], "'_cmp_legal_mode', self::page_mode(" ), 'generate_page writes _cmp_legal_mode' );
preg_match( '/function regenerate_cookie_page\(.*?\n\t}\n/s', $b2_src, $b2_rc );
cmp_assert( isset( $b2_rc[0] ) && false !== strpos( $b2_rc[0], "'_cmp_legal_mode', self::page_mode(" ), 'regenerate_cookie_page writes _cmp_legal_mode' );
preg_match( '/function sync_translation\(.*?\n\t}\n/s', $b2_lsrc, $b2_st );
cmp_assert( isset( $b2_st[0] ) && 2 === substr_count( $b2_st[0], "'_cmp_legal_mode', CMP_Legal_Page::page_mode(" ), 'sync_translation writes _cmp_legal_mode on refresh/adoption and on create' );
cmp_assert( false !== strpos( $b2_src, "get_post_meta( (int) \$doc['id'], '_cmp_legal_mode', true )" ) && false !== strpos( $b2_src, 'self::mode_notices(' ), 'legal screen reads _cmp_legal_mode and shows mode_notices' );
cmp_assert_eq( preg_match_all( '/page_mode\(\s*[^;]*?,\s*(self|CMP_Legal_Page)::services_history\(\)\s*\)/', $b2_src . $b2_lsrc ), 5, 'fix 1: every page_mode call passes services_history() (generate, cookie refresh, notice, sync refresh + create)' );
