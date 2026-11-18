<?php
require_once __DIR__ . '/../includes/modules/class-blocker.php';

// Third-party iframes (YouTube, Vimeo, Google Maps) must not load before consent:
// the server swaps them for a placeholder that keeps the original iframe inert
// inside a <template> until the visitor accepts the category or clicks "load".

$labels = array(
	'embed_text' => 'Conținut de la %s. Se încarcă doar cu acordul tău.',
	'embed_load' => 'Încarcă conținutul',
	'settings'   => 'Setări cookie',
);

/** The HTML outside any <template>…</template>, i.e. what the browser renders live. */
function cmp_live_html( $html ) {
	return (string) preg_replace( '/<template\b[^>]*>.*?<\/template>/is', '', $html );
}

// YouTube embed → marketing placeholder, no live iframe, nocookie inside the template.
$yt  = '<iframe width="560" height="315" src="https://www.youtube.com/embed/abc123?si=x" title="Video" allowfullscreen></iframe>';
$out = CMP_Blocker::rewrite( $yt, $labels );
cmp_assert( false !== strpos( $out, 'data-cmp-embed="marketing"' ), 'YouTube iframe becomes a marketing embed placeholder' );
cmp_assert( false === stripos( cmp_live_html( $out ), '<iframe' ), 'no live iframe left outside the template' );
cmp_assert( false !== strpos( $out, 'https://www.youtube-nocookie.com/embed/abc123?si=x' ), 'YouTube src switched to youtube-nocookie inside the template' );
cmp_assert( false !== strpos( $out, 'title="Video"' ) && false !== strpos( $out, 'allowfullscreen' ), 'original iframe attributes kept in the template' );
cmp_assert( false !== strpos( $out, 'Conținut de la YouTube.' ), 'placeholder names the service' );
cmp_assert( false !== strpos( $out, 'data-cmp-embed-load' ) && false !== strpos( $out, 'Încarcă conținutul' ), 'placeholder has a load button' );
cmp_assert( false !== strpos( $out, 'data-cmp-settings' ), 'placeholder links to the cookie settings' );
cmp_assert( false !== strpos( $out, 'aspect-ratio:560/315' ), 'placeholder keeps the iframe aspect ratio' );

// youtu.be-style and youtube-nocookie hosts are covered too.
$ync = CMP_Blocker::rewrite( '<iframe src="https://www.youtube-nocookie.com/embed/z"></iframe>', $labels );
cmp_assert( false !== strpos( $ync, 'data-cmp-embed="marketing"' ), 'youtube-nocookie embed is still blocked before consent' );

// Vimeo → statistics.
$vm = CMP_Blocker::rewrite( '<iframe src="https://player.vimeo.com/video/42" width="640" height="360"></iframe>', $labels );
cmp_assert( false !== strpos( $vm, 'data-cmp-embed="statistics"' ), 'Vimeo iframe becomes a statistics embed' );
cmp_assert( false !== strpos( $vm, 'Conținut de la Vimeo.' ), 'Vimeo placeholder names the service' );

// Google Maps → marketing.
$gm = CMP_Blocker::rewrite( '<iframe src="https://www.google.com/maps/embed?pb=!1m18" loading="lazy"></iframe>', $labels );
cmp_assert( false !== strpos( $gm, 'data-cmp-embed="marketing"' ), 'Google Maps iframe becomes a marketing embed' );
cmp_assert( false !== strpos( $gm, 'Conținut de la Google Maps.' ), 'Maps placeholder names the service' );
cmp_assert( false === stripos( cmp_live_html( $gm ), '<iframe' ), 'no live Maps iframe' );

// Unknown iframes and our own marked ones are untouched.
$own = '<iframe src="https://example.com/widget"></iframe>';
cmp_assert_eq( CMP_Blocker::rewrite( $own, $labels ), $own, 'non-tracker iframe untouched' );
$kept = '<iframe data-cmp-keep src="https://www.youtube.com/embed/abc"></iframe>';
cmp_assert_eq( CMP_Blocker::rewrite( $kept, $labels ), $kept, 'data-cmp-keep iframe untouched' );

// A decoy host in a query string must not trigger blocking.
$decoy = '<iframe src="https://example.com/?u=youtube.com/embed/x"></iframe>';
cmp_assert_eq( CMP_Blocker::rewrite( $decoy, $labels ), $decoy, 'decoy host in query string not blocked' );

// Labels are HTML-escaped (they can come from admin-edited text).
$xss = CMP_Blocker::rewrite( $yt, array( 'embed_text' => '<b>%s</b>', 'embed_load' => '"x"', 'settings' => 'S' ) );
cmp_assert( false === strpos( $xss, '<b>YouTube</b>' ), 'embed text escaped' );

// Without labels (e.g. tests, early calls) a default text is used.
$def = CMP_Blocker::rewrite( $yt );
cmp_assert( false !== strpos( $def, 'data-cmp-embed-load' ), 'default labels used when none are given' );

// The placeholder follows the design preset chosen in the settings.
$pre = CMP_Blocker::rewrite( $yt, array( 'preset' => 'egov' ) );
cmp_assert( false !== strpos( $pre, 'class="cmp-embed cmp--egov"' ), 'placeholder carries the design preset class' );
$bad = CMP_Blocker::rewrite( $yt, array( 'preset' => 'x" onmouseover="y' ) );
cmp_assert( false === strpos( $bad, 'onmouseover' ), 'unknown preset value is not echoed' );

// The placeholder is never wider than the original iframe.
cmp_assert( false !== strpos( $out, 'max-width:560px' ), 'placeholder capped at the iframe pixel width' );

// Percentage widths are not pixel sizes: default ratio, no max-width.
$pct = CMP_Blocker::rewrite( '<iframe src="https://www.youtube.com/embed/p" width="100%" height="450"></iframe>', $labels );
cmp_assert( false !== strpos( $pct, 'aspect-ratio:16/9' ), 'percentage width falls back to 16/9' );
cmp_assert( false === strpos( $pct, 'max-width' ), 'percentage width sets no max-width' );
