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
