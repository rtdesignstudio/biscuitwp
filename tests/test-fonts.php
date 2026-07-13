<?php
require_once __DIR__ . '/../includes/modules/class-fonts.php';

// extract_font_links: finds Google Fonts <link>, decodes entities, ignores others, dedups.
$html = '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto&amp;display=swap">'
	. '<link rel="stylesheet" href="https://example.com/style.css">'
	. "<link rel='stylesheet' href='https://fonts.googleapis.com/css2?family=Roboto&amp;display=swap'>";
$links = CMP_Fonts::extract_font_links( $html );
cmp_assert_eq( count( $links ), 1, 'one unique Google Fonts link' );
cmp_assert_eq( $links[0], 'https://fonts.googleapis.com/css2?family=Roboto&display=swap', 'entities decoded' );

// parse_woff2_urls: extracts woff2 URLs from @font-face src.
$css = "@font-face{font-family:'Roboto';src:url(https://fonts.gstatic.com/s/roboto/v30/a.woff2) format('woff2');}"
	. "@font-face{src:url('https://fonts.gstatic.com/s/roboto/v30/b.woff2') format('woff2');}";
$woff = CMP_Fonts::parse_woff2_urls( $css );
cmp_assert_eq( count( $woff ), 2, 'two woff2 urls' );
cmp_assert_eq( $woff[0], 'https://fonts.gstatic.com/s/roboto/v30/a.woff2', 'first woff2 url' );

// localize_css: replaces remote gstatic urls with local ones.
$map = array( 'https://fonts.gstatic.com/s/roboto/v30/a.woff2' => 'https://site/uploads/cmp-fonts/x.woff2' );
$out = CMP_Fonts::localize_css( $css, $map );
cmp_assert( strpos( $out, 'https://site/uploads/cmp-fonts/x.woff2' ) !== false, 'gstatic url replaced' );
cmp_assert( strpos( $out, 'a.woff2' ) === false, 'original gstatic a.woff2 gone' );

// rewrite_links: swap mapped Google link -> local; unmapped left; entity-encoded matched.
$map2 = array( 'https://fonts.googleapis.com/css2?family=Roboto&display=swap' => 'https://site/uploads/cmp-fonts/roboto.css' );
$page = '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto&amp;display=swap">'
	. '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Lato">';
$rw = CMP_Fonts::rewrite_links( $page, $map2 );
cmp_assert( strpos( $rw, 'uploads/cmp-fonts/roboto.css' ) !== false, 'mapped link rewritten to local' );
cmp_assert( strpos( $rw, 'family=Roboto&amp;display=swap' ) === false, 'original Roboto href gone' );
cmp_assert( strpos( $rw, 'family=Lato' ) !== false, 'unmapped Lato link left untouched' );
