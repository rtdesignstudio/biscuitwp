<?php
require_once __DIR__ . '/../includes/modules/class-legal.php';

$tpl = "Salut {{name}}, la {{site}}. {{missing}} rămâne.";
$out = CMP_Legal::fill_template( $tpl, array( 'name' => 'Ana', 'site' => 'PlataEgala' ) );
cmp_assert_eq( $out, 'Salut Ana, la PlataEgala. {{missing}} rămâne.', 'fill_template replaces known tokens, leaves unknown' );

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
