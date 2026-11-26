<?php
/**
 * #727 step 4: the live preview of the Aspect screen. The render action
 * (CMP_Preview::ajax_render()) runs in its own process (tests/fixtures/preview-render.php).
 */
$pr_cats = array(
	array( 'slug' => 'necessary', 'label' => 'Necesare', 'description' => 'Esențiale.', 'required' => true ),
	array( 'slug' => 'statistics', 'label' => 'Statistici', 'description' => 'Analiză.', 'required' => false ),
);
$pr_run = function ( array $in ) use ( $pr_cats ) {
	$in  = array_merge( array( 'settings' => array( 'mode' => 'pro' ), 'categories' => $pr_cats ), $in );
	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/preview-render.php' ) . ' ' . escapeshellarg( json_encode( $in ) ) . ' 2>&1';
	$out = json_decode( (string) shell_exec( $cmd ), true );
	return is_array( $out ) ? $out : array( 'raw' => shell_exec( $cmd ) );
};
$pr_nothing = function ( $r ) {
	return isset( $r['transients'], $r['options'], $r['cookies'] ) && array() === $r['transients'] && array() === $r['options'] && array() === $r['cookies'];
};
$pr_form = array( 'appearance' => array( 'style' => 'brand', 'format' => 'bar', 'position' => 'bottom', 'accent' => '#b3261e' ) );

foreach ( array(
	'visitor'          => array( 'cap' => false, 'post' => array( 'nonce' => 'good-render', 'cmp' => $pr_form ) ),
	'admin, no nonce'  => array( 'cap' => true, 'post' => array( 'cmp' => $pr_form ) ),
	'admin, bad nonce' => array( 'cap' => true, 'post' => array( 'nonce' => 'good-ajax', 'cmp' => $pr_form ) ),
) as $pr_l => $pr_in ) {
	$r = $pr_run( $pr_in );
	cmp_assert( isset( $r['status'] ) && 403 === $r['status'] && $pr_nothing( $r ), "preview render: {$pr_l} -> 403, nothing written" );
}

$r  = $pr_run( array( 'cap' => true, 'post' => array( 'nonce' => 'good-render', 'cmp' => $pr_form ) ) );
$d  = isset( $r['body']['data'] ) ? $r['body']['data'] : array();
$pr_html = isset( $d['html'] ) ? (string) $d['html'] : '';
$pr_css  = isset( $d['css'] ) ? (string) $d['css'] : '';
cmp_assert( 200 === ( isset( $r['status'] ) ? $r['status'] : 0 ) && ! empty( $r['body']['success'] ), 'preview render: valid -> success' . ( isset( $r['raw'] ) ? ' ' . substr( (string) $r['raw'], 0, 300 ) : '' ) );
cmp_assert( false !== strpos( $pr_html, 'data-cmp ' ) && false !== strpos( $pr_html, 'cmp--fmt-bar' ) && false !== strpos( $pr_html, 'cmp__prefs' ), 'preview render: the real banner with the posted format' );
cmp_assert( false !== stripos( $pr_css, '#b3261e' ), 'preview render: the generated CSS with the posted accent' );
cmp_assert( isset( $d['bannerCss'] ) && 0 === strpos( $d['bannerCss'], 'http://example.test/wp-content/plugins/biscuitwp/' ) && false !== strpos( $d['bannerCss'], 'banner' ), 'preview render: the address of banner.css' );
cmp_assert( $pr_nothing( $r ), 'preview render: no option, transient or cookie written' );
cmp_assert( false === stripos( $pr_html . $pr_css, '<script' ) && false === stripos( $pr_css, '<link' ), 'preview render: no script, no link in the parts' );

$r = $pr_run( array( 'cap' => true, 'post' => array( 'nonce' => 'good-render', 'cmp' => array( 'appearance' => array( 'custom_css' => '.x{}</style><script>alert(1)</script>' ), 'texts' => array( 'ro' => array( 'title' => '<b>T</b><script>x</script>' ) ) ) ) ) );
$d = isset( $r['body']['data'] ) ? $r['body']['data'] : array();
cmp_assert( ! empty( $r['body']['success'] ) && false === stripos( ( isset( $d['html'] ) ? $d['html'] : '' ) . ( isset( $d['css'] ) ? $d['css'] : '' ), '<script' ), 'preview render: hostile CSS and texts come out cleaned, as after a save' );

$r = $pr_run( array( 'cap' => true, 'settings' => array( 'mode' => 'light' ), 'post' => array( 'nonce' => 'good-render', 'cmp' => $pr_form ) ) );
$pr_html = isset( $r['body']['data']['html'] ) ? $r['body']['data']['html'] : '';
cmp_assert( false !== strpos( $pr_html, 'cmp-light' ) && false === strpos( $pr_html, 'cmp__prefs' ), 'preview render: Light renders the Light bar' );

$r = $pr_run( array( 'cap' => true, 'post' => array( 'nonce' => 'good-render', 'lang' => 'ru', 'cmp' => $pr_form ) ) );
$pr_html = isset( $r['body']['data']['html'] ) ? $r['body']['data']['html'] : '';
cmp_assert( false !== strpos( $pr_html, 'lang="ru"' ) && false !== strpos( $pr_html, htmlspecialchars( CMP_Lang::text( 'accept', 'ru' ), ENT_QUOTES, 'UTF-8' ) ) && 'ru' === ( isset( $r['body']['data']['lang'] ) ? $r['body']['data']['lang'] : '' ), 'preview render: the language asked in the panel' );
$r = $pr_run( array( 'cap' => true, 'site_locale' => 'ro_RO', 'user_locale' => 'en_US', 'post' => array( 'nonce' => 'good-render', 'lang' => '', 'cmp' => $pr_form ) ) );
cmp_assert( false !== strpos( isset( $r['body']['data']['html'] ) ? $r['body']['data']['html'] : '', 'lang="ro"' ), 'preview render: by default the site language, not the admin user\'s' );

$pr_src = (string) file_get_contents( __DIR__ . '/../includes/modules/class-preview.php' );
cmp_assert( false !== strpos( $pr_src, "add_action( 'wp_ajax_' . self::RENDER" ), 'preview render: a logged-in AJAX action' );
cmp_assert( false === strpos( $pr_src, 'wp_ajax_nopriv_' ), 'preview render: never for visitors' );
$pr_fn = preg_match( '/function ajax_render\(\) \{.*?\n\t\}/s', $pr_src, $pr_m ) ? $pr_m[0] : '';
cmp_assert( '' !== $pr_fn && false === stripos( $pr_fn, 'cookie' ) && false === strpos( $pr_fn, 'self::store(' ), 'preview render: sets no cookie and stores nothing' );

// The panel of the Aspect screen (CMP_Appearance_Page::live_preview_html()).
if ( class_exists( 'CMP_Appearance_Page' ) ) {
	$pr_p = CMP_Appearance_Page::live_preview_html( 'n0nce', 'http://example.test/wp-admin/admin-ajax.php', false );
	cmp_assert( 1 === substr_count( $pr_p, 'data-cmp-live ' ) && 1 === preg_match( '#<details class="cmp-live" data-cmp-live [^>]*\bopen\b[^>]*\bhidden\b#', $pr_p ), 'live preview: one panel, open, hidden until the script shows it' );
	cmp_assert( false !== strpos( $pr_p, 'data-cmp-live-nonce="n0nce"' ) && false !== strpos( $pr_p, 'data-cmp-live-ajax="http://example.test/wp-admin/admin-ajax.php"' ), 'live preview: its nonce and AJAX address' );
	cmp_assert( 1 === preg_match( '#<iframe [^>]*sandbox="allow-same-origin"[^>]*title="[^"]+"#', $pr_p ) && false === strpos( $pr_p, 'allow-scripts' ), 'live preview: a script-less frame with a title' );
	foreach ( array( 'view' => array( 'desktop', 'phone' ), 'theme' => array( 'light', 'dark' ), 'pane' => array( 'banner', 'prefs' ) ) as $pr_k => $pr_vs ) {
		foreach ( $pr_vs as $pr_i => $pr_v ) {
			cmp_assert( 1 === preg_match( '#<button type="button" [^>]*data-cmp-live-' . $pr_k . '="' . $pr_v . '"[^>]*aria-pressed="' . ( 0 === $pr_i ? 'true' : 'false' ) . '"#', $pr_p ), "live preview: switch {$pr_k}={$pr_v} with aria-pressed" );
		}
	}
	cmp_assert( false !== strpos( $pr_p, '<select data-cmp-live-lang' ) && false !== strpos( $pr_p, 'role="status" data-cmp-live-status' ), 'live preview: language and status' );
	cmp_assert( false === strpos( CMP_Appearance_Page::live_preview_html( 'n', 'u', true ), '<select data-cmp-live-lang' ), 'live preview: no language select with WPML / Polylang' );
	$pr_ap = (string) file_get_contents( __DIR__ . '/../includes/admin/class-appearance-page.php' );
	cmp_assert( false !== strpos( $pr_ap, 'wp_create_nonce( CMP_Preview::RENDER )' ), 'live preview: the nonce is the render action\'s' );
}

// Review fixes (2026-11-26).
$r = $pr_run( array( 'cap' => true, 'wpml' => true, 'post' => array( 'nonce' => 'good-render', 'cmp' => $pr_form ) ) );
cmp_assert( ! empty( $r['body']['success'] ) && isset( $r['registered'] ) && array() === $r['registered'], 'preview render: with WPML, no string is registered (no database write on each refresh)' );
$pr_js = (string) file_get_contents( __DIR__ . '/../assets/js/admin-appearance.js' );
cmp_assert( 1 === preg_match( "/id\\.value = m\\.id;\\s*id\\.dispatchEvent\\(new Event\\('change', \\{ bubbles: true \\}\\)\\);/", $pr_js ), 'live preview: choosing a picture or logo refreshes it' );
cmp_assert( 1 === preg_match( "/accent\\.value = proposed;[^\\n]*accent\\.dispatchEvent\\(new Event\\('input', \\{ bubbles: true \\}\\)\\)/", $pr_js ), 'live preview: the proposed accent refreshes it' );
cmp_assert( false !== strpos( $pr_js, "form.querySelector('[name*=\"[theme]\"]:checked')" ) && false !== strpos( $pr_js, "base === 'light'" ) && false !== strpos( $pr_js, "classList.remove('cmp--theme-auto')" ), 'live preview: the dark switch follows the theme chosen in the form' );

// 1.2.1: the details left from the review.
$pr_js = (string) file_get_contents( __DIR__ . '/../assets/js/admin-appearance.js' );
cmp_assert( false !== strpos( $pr_js, '.cmp a,.cmp-light a{pointer-events:none}' ), 'live preview 1.2.1: links in the frame do nothing (the privacy link no longer loads a page in it)' );
cmp_assert( 1 === preg_match( '/if \(key === sent\) \{ return; \}/', $pr_js ), 'live preview 1.2.1: the same form is never sent twice (input then change on blur)' );
cmp_assert( false !== strpos( $pr_js, "langSel.addEventListener('change', later)" ) && false === strpos( $pr_js, "langSel.addEventListener('change', request)" ), 'live preview 1.2.1: the language select waits like the rest' );
cmp_assert( false !== strpos( $pr_js, 'if (!root.open) { return; }' ) && false !== strpos( $pr_js, "root.addEventListener('toggle'" ), 'live preview 1.2.1: no request while the panel is folded; one when it opens' );

// With WPML, by default the site's default language, not the admin's WPML language.
$r = $pr_run( array( 'cap' => true, 'wpml' => true, 'wpml_current' => 'en', 'wpml_default' => 'ru', 'post' => array( 'nonce' => 'good-render', 'lang' => '', 'cmp' => $pr_form ) ) );
cmp_assert( false !== strpos( isset( $r['body']['data']['html'] ) ? $r['body']['data']['html'] : '', 'lang="ru"' ), 'live preview 1.2.1: with WPML, the default language of the site' );
cmp_assert( isset( $r['wpml_after'] ) && 'en' === $r['wpml_after'], 'live preview 1.2.1: the WPML language is switched back after the render' );

// 1.2.1 review fixes.
cmp_assert( false !== strpos( $pr_js, ".replace(/\\shref=\"[^\"]*\"/g, '')" ), 'live preview 1.2.1: links in the frame have no address (not even the keyboard opens them)' );
$r = $pr_run( array( 'cap' => true, 'wpml' => true, 'pll_compat' => true, 'wpml_current' => 'en', 'wpml_default' => 'ru', 'post' => array( 'nonce' => 'good-render', 'lang' => '', 'cmp' => $pr_form ) ) );
cmp_assert( ! empty( $r['body']['success'] ) && array() === ( isset( $r['switches'] ) ? $r['switches'] : null ) && false === strpos( isset( $r['body']['data']['html'] ) ? $r['body']['data']['html'] : '', 'lang="ru"' ), 'live preview 1.2.1: Polylang with its WPML layer is left alone' );
$r = $pr_run( array( 'cap' => true, 'wpml' => true, 'wpml_current' => null, 'wpml_default' => 'ru', 'post' => array( 'nonce' => 'good-render', 'lang' => '', 'cmp' => $pr_form ) ) );
cmp_assert_eq( isset( $r['switches'] ) ? $r['switches'] : null, array( 'ru', null ), 'live preview 1.2.1: with no WPML language before, it is reset with null (not an empty code)' );
