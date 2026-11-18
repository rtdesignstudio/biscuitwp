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
