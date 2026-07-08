<?php
require_once __DIR__ . '/../includes/modules/class-legal.php';

$tpl = "Salut {{name}}, la {{site}}. {{missing}} rămâne.";
$out = CMP_Legal::fill_template( $tpl, array( 'name' => 'Ana', 'site' => 'PlataEgala' ) );
cmp_assert_eq( $out, 'Salut Ana, la PlataEgala. {{missing}} rămâne.', 'fill_template replaces known tokens, leaves unknown' );

$priv = CMP_Legal::load_template( 'privacy' );
cmp_assert( strpos( $priv, '{{site_name}}' ) !== false, 'privacy template has site_name placeholder' );
cmp_assert_eq( CMP_Legal::load_template( 'nope' ), '', 'missing template returns empty string' );
