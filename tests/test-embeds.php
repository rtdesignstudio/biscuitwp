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

// B1: "Always load" second button, only for library services and not in Light mode.
$al = CMP_Blocker::rewrite( $yt, array_merge( $labels, array( 'embed_always' => 'Încarcă mereu' ) ) );
cmp_assert( false !== strpos( $al, '<button type="button" class="cmp-embed__always" data-cmp-embed-always aria-label="Încarcă mereu: YouTube">Încarcă mereu</button>' ), 'library embed has an "always load" button' );
cmp_assert( false !== strpos( $al, 'data-cmp-service-name="YouTube"' ), 'placeholder carries the service name' );
$al_light = CMP_Blocker::rewrite( $yt, array_merge( $labels, array( 'embed_always' => 'Încarcă mereu', 'light' => true ) ) );
cmp_assert( false === strpos( $al_light, 'data-cmp-embed-always' ), 'no "always load" button in Light mode' );
cmp_assert( false !== strpos( $al_light, 'data-cmp-embed-load' ), 'Light mode keeps the per-click load button' );
$al_anon = CMP_Blocker::rewrite( '<iframe data-cmp-category="marketing" src="https://example.com/w"></iframe>', array_merge( $labels, array( 'embed_always' => 'Încarcă mereu' ) ) );
cmp_assert( false !== strpos( $al_anon, 'data-cmp-embed="marketing"' ) && false === strpos( $al_anon, 'data-cmp-embed-always' ), 'anonymous embed has no "always load" button' );
$al_def = CMP_Blocker::rewrite( $yt );
cmp_assert( false !== strpos( $al_def, 'data-cmp-embed-always' ), 'default labels give the always button too' );

// ---- Task 9: local YouTube thumbnail behind the placeholder ----
cmp_assert_eq( CMP_Blocker::youtube_id( 'https://www.youtube.com/embed/dQw4w9WgXcQ?si=1' ), 'dQw4w9WgXcQ', 'youtube_id: plain embed' );
cmp_assert_eq( CMP_Blocker::youtube_id( 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ' ), 'dQw4w9WgXcQ', 'youtube_id: nocookie embed' );
cmp_assert_eq( CMP_Blocker::youtube_id( 'https://youtube-nocookie.com/embed/dQw4w9WgXcQ' ), 'dQw4w9WgXcQ', 'youtube_id: bare nocookie host' );
cmp_assert_eq( CMP_Blocker::youtube_id( 'https://m.youtube.com/embed/dQw4w9WgXcQ' ), 'dQw4w9WgXcQ', 'youtube_id: m.youtube.com' );
cmp_assert_eq( CMP_Blocker::youtube_id( 'https://YouTube.com/embed/dQw4w9WgXcQ' ), 'dQw4w9WgXcQ', 'youtube_id: host is case-insensitive' );
cmp_assert_eq( CMP_Blocker::youtube_id( 'https://youtube.com/embed/dQw4w9WgXcQ#t=1' ), 'dQw4w9WgXcQ', 'youtube_id: bare host with fragment' );
cmp_assert_eq( CMP_Blocker::youtube_id( 'https://www.youtube.com/embed/../../x' ), '', 'youtube_id: path traversal rejected' );
cmp_assert_eq( CMP_Blocker::youtube_id( 'https://www.youtube.com/embed/dQw4w9WgXcQ/extra' ), '', 'youtube_id: extra path segment rejected' );
cmp_assert_eq( CMP_Blocker::youtube_id( 'https://www.youtube.com/embed/dQw4w9WgXcQ/' ), '', 'youtube_id: trailing slash rejected' );
cmp_assert_eq( CMP_Blocker::youtube_id( 'https://www.youtube.com/embed/dQw4w9WgXc' ), '', 'youtube_id: 10 chars rejected' );
cmp_assert_eq( CMP_Blocker::youtube_id( 'https://www.youtube.com/embed/dQw4w9WgXcQx' ), '', 'youtube_id: 12 chars rejected' );
cmp_assert_eq( CMP_Blocker::youtube_id( 'https://www.youtube.com/embed/dQw4w9Wg.cQ' ), '', 'youtube_id: bad char rejected' );
cmp_assert_eq( CMP_Blocker::youtube_id( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ), '', 'youtube_id: non-embed path rejected' );
cmp_assert_eq( CMP_Blocker::youtube_id( 'https://evil.com/embed/dQw4w9WgXcQ' ), '', 'youtube_id: foreign host rejected' );
cmp_assert_eq( CMP_Blocker::youtube_id( 'https://youtube.com.evil.com/embed/dQw4w9WgXcQ' ), '', 'youtube_id: lookalike host rejected' );
cmp_assert_eq( CMP_Blocker::youtube_id( 'https://evil.com/youtube.com/embed/dQw4w9WgXcQ' ), '', 'youtube_id: host in path rejected' );
cmp_assert_eq( CMP_Blocker::youtube_id( 'https://player.youtube.com/embed/dQw4w9WgXcQ' ), '', 'youtube_id: unlisted subdomain rejected' );
cmp_assert_eq( CMP_Blocker::youtube_id( '' ), '', 'youtube_id: empty' );

$ytid = '<iframe width="560" height="315" src="https://www.youtube.com/embed/dQw4w9WgXcQ?si=1"></iframe>';
$calls = array();
$cb    = function ( $id ) use ( &$calls ) {
	$calls[] = $id;
	return 'https://site.md/up/cmp-thumbs/' . $id . '.jpg';
};
$out = CMP_Blocker::rewrite( $ytid, $labels, null, $cb );
cmp_assert_eq( $calls, array( 'dQw4w9WgXcQ' ), 'thumb callback called once with the YouTube id' );
cmp_assert( false !== strpos( $out, "background-image:url('https://site.md/up/cmp-thumbs/dQw4w9WgXcQ.jpg')" ), 'placeholder carries the local thumbnail as background-image' );
cmp_assert( false !== strpos( $out, 'cmp-embed--thumb' ), 'placeholder gets the cmp-embed--thumb class' );
cmp_assert( false !== strpos( $out, 'aspect-ratio:560/315' ), 'aspect ratio kept next to the thumbnail' );
cmp_assert( 0 === preg_match( '/ytimg|i\.ytimg/i', cmp_live_html( $out ) ), 'no ytimg reference in the live markup' );

$out = CMP_Blocker::rewrite( $ytid, $labels, null, function () { return ''; } );
cmp_assert( false === strpos( $out, 'background-image' ) && false === strpos( $out, 'cmp-embed--thumb' ), 'empty thumb URL: plain placeholder' );
$out = CMP_Blocker::rewrite( $ytid, $labels );
cmp_assert( false === strpos( $out, 'background-image' ), 'no callback: no background-image' );

$calls = array();
$out   = CMP_Blocker::rewrite( '<iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"></iframe>', $labels, null, $cb );
cmp_assert_eq( $calls, array( 'dQw4w9WgXcQ' ), 'nocookie embed also asks for the thumbnail' );

$calls = array();
CMP_Blocker::rewrite( '<iframe src="https://www.youtube.com/embed/abc123"></iframe>', $labels, null, $cb );
cmp_assert_eq( $calls, array(), 'invalid YouTube id: thumb callback not called' );

$calls = array();
$out   = CMP_Blocker::rewrite(
	'<iframe src="https://player.vimeo.com/video/76979871"></iframe><iframe src="https://www.google.com/maps/embed?pb=1"></iframe>',
	$labels,
	null,
	$cb
);
cmp_assert_eq( $calls, array(), 'thumb callback not called for Vimeo or Maps' );
cmp_assert( false === strpos( $out, 'background-image' ), 'Vimeo / Maps placeholders have no background-image' );

// A hostile URL cannot break out of the attribute or the CSS url().
$out = CMP_Blocker::rewrite( $ytid, $labels, null, function () { return "https://x.md/a.jpg') ;background:red;\" onload=\"x"; } );
cmp_assert( 1 === preg_match( '/<div class="cmp-embed[^"]*"[^>]*>/', $out, $dm ), 'placeholder div found' );
$div = $dm[0];
cmp_assert( 1 === preg_match( '/^<div class="cmp-embed[^"]*" data-cmp-embed="marketing"[^>]* style="([^"]*)">$/', $div, $sm2 ), 'placeholder div tag stays well-formed (no attribute injected)' );
cmp_assert( 1 === preg_match( "/^aspect-ratio:560\\/315;max-width:560px;background-image:url\\('([^'()\\s\"]*)'\\)\$/", html_entity_decode( $sm2[1], ENT_QUOTES ), $um ), 'hostile URL stays inside a single quoted url() with no quote, paren or space' );
