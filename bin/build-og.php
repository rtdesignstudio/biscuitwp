<?php
/**
 * The link-preview pictures (Open Graph, 1200×630) of the landing pages, one per language:
 * site/assets/og/og-{ro,en}.png. Writes one HTML page per language (fonts inlined, nothing
 * loaded from the network) and prints its path; bin/build-og.sh screenshots it with Chrome.
 *
 *   php bin/build-og.php <lang> <out.html>
 */
if ( 3 !== $argc || ! in_array( $argv[1], array( 'ro', 'en' ), true ) ) {
	fwrite( STDERR, "Usage: php bin/build-og.php ro|en <out.html>\n" );
	exit( 2 );
}
$lang = $argv[1];
$root = dirname( __DIR__ );

$t = array(
	'ro' => array(
		'title'   => 'Cookie-uri cu acord,<br>nu cu noroc.',
		'sub'     => 'Plugin gratuit pentru WordPress, făcut pentru Legea 195/2024. Verifică-ți site-ul în câteva secunde.',
		'before'  => 'Înainte de acord',
		'after'   => 'După „Accept”',
		'blocked' => 'blocat',
		'on'      => 'pornit',
		'banner'  => 'Folosim cookie-uri pentru statistici și reclame.',
		'no'      => 'Refuz',
		'yes'     => 'Accept',
	),
	'en' => array(
		'title'   => 'Cookies by consent,<br>not by luck.',
		'sub'     => 'A free WordPress plugin, made for Moldova’s Law 195/2024. Check your site in seconds.',
		'before'  => 'Before consent',
		'after'   => 'After “Accept”',
		'blocked' => 'blocked',
		'on'      => 'loaded',
		'banner'  => 'We use cookies for statistics and ads.',
		'no'      => 'Reject',
		'yes'     => 'Accept',
	),
);
$t = $t[ $lang ];

$fonts = '';
foreach ( array( 'latin', 'latin-ext', 'cyrillic', 'cyrillic-ext' ) as $sub ) {
	$f = $root . '/site/fonts/inter-tight/inter-tight-normal-' . $sub . '.woff2';
	if ( ! is_readable( $f ) ) {
		fwrite( STDERR, "font not found: {$f}\n" );
		exit( 1 );
	}
	$fonts .= '@font-face{font-family:"Inter Tight";font-weight:100 900;src:url(data:font/woff2;base64,' . base64_encode( (string) file_get_contents( $f ) ) . ') format("woff2");}' . "\n";
}

$hosts = array( 'google-analytics.<wbr>com', 'connect.<wbr>facebook.<wbr>net', 'clarity.<wbr>ms' );
$col   = function ( $title, $cls, $state ) use ( $hosts ) {
	$rows = '';
	foreach ( $hosts as $h ) {
		$rows .= '<li class="' . $cls . '"><code>' . $h . '</code><span>' . $state . '</span></li>';
	}
	return '<div><p class="h">' . $title . '</p><ul>' . $rows . '</ul></div>';
};
$sym = '<svg class="sym" viewBox="168.695 162.277 46.734 60.156" aria-hidden="true"><path fill="currentColor" d="M 208.25 177.652344 C 207.855469 178.664062 206.433594 178.652344 206.03125 177.644531 C 204.433594 173.6875 200.550781 170.886719 196.023438 170.886719 L 179.152344 170.886719 C 178.136719 170.886719 177.316406 171.707031 177.316406 172.726562 L 177.296875 208.15625 C 177.296875 209.507812 176.757812 210.804688 175.800781 211.757812 C 174.273438 213.28125 173.027344 214.523438 172.210938 215.339844 C 172.203125 215.34375 172.199219 215.351562 172.191406 215.359375 C 170.90625 216.582031 168.722656 215.605469 168.695312 213.832031 C 168.695312 213.800781 168.695312 213.769531 168.695312 213.738281 L 168.695312 170.070312 C 168.695312 165.003906 172.839844 162.277344 176.738281 162.277344 L 196.023438 162.277344 C 198.601562 162.277344 201.148438 162.796875 203.527344 163.804688 C 204.792969 164.347656 205.933594 165.144531 206.824219 166.171875 C 206.832031 166.183594 206.84375 166.195312 206.84375 166.203125 C 206.933594 166.304688 207.011719 166.402344 207.082031 166.503906 C 208.914062 168.925781 209.992188 173.1875 208.25 177.652344 Z"/><path fill="currentColor" d="M 213.816406 220.914062 C 212.085938 222.433594 209.417969 222.195312 207.789062 220.566406 L 197.160156 209.9375 C 196.886719 209.664062 196.507812 209.507812 196.121094 209.507812 C 195.730469 209.507812 195.355469 209.664062 195.078125 209.941406 L 187.550781 217.480469 C 183.703125 221.328125 181.105469 221.976562 176.734375 221.976562 C 175.863281 221.976562 174.367188 221.84375 172.878906 221.074219 C 172.3125 220.785156 172.308594 219.964844 172.863281 219.652344 C 173.199219 219.46875 173.496094 219.246094 173.746094 219 C 174.441406 218.300781 204.328125 188.535156 204.402344 188.457031 C 204.425781 188.429688 204.457031 188.386719 204.488281 188.34375 L 207.921875 184.90625 C 211.191406 181.640625 212.515625 177.464844 212.359375 173.4375 C 212.335938 172.816406 213.175781 172.59375 213.445312 173.152344 C 215.214844 176.800781 215.429688 180.199219 215.429688 181.625 C 215.429688 186.304688 213.75 190.808594 210.765625 194.234375 L 203.246094 201.765625 C 202.671875 202.339844 202.671875 203.269531 203.246094 203.839844 L 213.894531 214.492188 C 214.628906 215.222656 215.15625 216.167969 215.265625 217.199219 C 215.414062 218.621094 214.882812 219.976562 213.816406 220.914062 Z"/></svg>';

$html = '<!doctype html><html lang="' . $lang . '"><head><meta charset="utf-8"><style>' . $fonts . '
*{box-sizing:border-box}
html,body{margin:0;width:1200px;height:630px;overflow:hidden;background:#fff;color:#0a0a0a;font-family:"Inter Tight",system-ui,sans-serif;-webkit-font-smoothing:antialiased}
.page{display:grid;grid-template-columns:1fr 380px;gap:56px;height:630px;padding:56px 64px}
.left{display:flex;flex-direction:column}
.brand{display:flex;align-items:center;gap:12px;font-size:26px;font-weight:600;letter-spacing:-.02em}
.sym{height:40px;width:auto}
h1{margin:auto 0 0 -.05em;font-size:76px;line-height:1;letter-spacing:-.045em;font-weight:600}
.sub{margin:28px 0 0;font-size:25px;line-height:1.32;letter-spacing:-.012em;max-width:640px}
.url{margin:28px 0 0;font-size:20px;color:#767676}
.card{align-self:center;padding:22px;border:1px solid #e6e6e6;border-radius:2px}
.banner{margin:0 0 18px;padding:16px;border:1px solid #0a0a0a;border-radius:2px}
.banner p{margin:0;font-size:16px;line-height:1.35}
.btns{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px!important}
.btns span{display:flex;align-items:center;justify-content:center;height:40px;border:1px solid #0a0a0a;border-radius:2px;font-size:16px;font-weight:500}
.cols{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.h{margin:0 0 8px;font-size:15px;font-weight:600}
ul{list-style:none;margin:0;padding:0;border-top:1px solid #e6e6e6}
li{display:flex;flex-direction:column;gap:3px;padding:9px 0;border-bottom:1px solid #e6e6e6}
code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px}
li span{display:flex;align-items:center;gap:7px;font-size:14px;color:#767676}
li span::before{content:"";width:9px;height:9px;flex:none}
.off span::before{background:#b3261e}
.on span{color:#0a0a0a}
.on span::before{border-radius:50%;background:#0a0a0a}
</style></head><body><div class="page"><div class="left">
<p class="brand" style="margin:0">' . $sym . 'BiscuitWP</p>
<h1>' . $t['title'] . '</h1>
<p class="sub">' . $t['sub'] . '</p>
<p class="url">biscuitwp.rt.md</p>
</div><div class="card">
<div class="banner"><p>' . $t['banner'] . '</p><p class="btns"><span>' . $t['no'] . '</span><span>' . $t['yes'] . '</span></p></div>
<div class="cols">' . $col( $t['before'], 'off', $t['blocked'] ) . $col( $t['after'], 'on', $t['on'] ) . '</div>
</div></div></body></html>';

if ( false === file_put_contents( $argv[2], $html ) ) {
	fwrite( STDERR, "cannot write {$argv[2]}\n" );
	exit( 1 );
}
echo $argv[2], "\n";
