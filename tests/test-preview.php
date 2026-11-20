<?php
/**
 * B6 Task 6: "Vezi pe site" — preview of the UNSAVED appearance on the real
 * site, admin only (CMP_Preview).
 *  - request validation: no nonce / wrong nonce / no rights -> ignored
 *    completely; valid -> active (Review Focus 5);
 *  - the transient: per user, overwritten, 30 minutes, sanitized like the save;
 *  - CMP_DATA in preview = the check-mode shape (check cookies, no reload, no
 *    register write); the real cmp_consent is never touched;
 *  - exclusions ignored; no cache; admin bar hidden; the admin-only bar;
 *  - zero bytes in banner.js / banner.css (test-assets keeps the budget).
 * The WordPress-wired request runs in its own process (tests/fixtures/preview-request.php).
 * Runs after test-page-appearance.php (alphabetical), which defines the
 * sanitizer / escaping stubs used here.
 */
require_once __DIR__ . '/../includes/class-consent.php';
require_once __DIR__ . '/../includes/class-contrast.php';
require_once __DIR__ . '/../includes/class-appearance.php';
require_once __DIR__ . '/../includes/class-sanitizer.php';
require_once __DIR__ . '/../includes/modules/class-scanner.php';
require_once __DIR__ . '/../includes/modules/class-banner.php';
require_once __DIR__ . '/../includes/modules/class-preview.php';
require_once __DIR__ . '/../includes/admin/class-appearance-page.php';

/** One front-end request in a separate PHP process; returns the decoded JSON. */
function cmp_pv_request( array $in ) {
	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/preview-request.php' ) . ' ' . escapeshellarg( json_encode( $in ) ) . ' 2>&1';
	$raw = shell_exec( $cmd );
	$out = json_decode( (string) $raw, true );
	if ( ! is_array( $out ) ) {
		echo "preview-request output: {$raw}\n";
		return array();
	}
	return $out;
}

$pv_overlay = array(
	'appearance'    => array_merge( CMP_Appearance::defaults( 'friendly' ), array( 'palette' => 'egov', 'exclude' => array( '/landing/*' ) ) ),
	'preset'        => 'egov',
	'reopen_button' => true,
);
$pv_stored = array( 'mode' => 'pro', 'preset' => 'neutral', 'block_scripts' => true, 'appearance' => CMP_Appearance::defaults( 'brand' ) );
// As it comes back from the separate process (JSON: 0.0 -> 0).
$pv_json = function ( $v ) {
	return json_decode( json_encode( $v ), true );
};
// Fix round 1 (I1): the nonce is in the HttpOnly cookie cmp_preview_t; the URL carries only the marker =1.
$pv_base   = array(
	'get'        => array( 'cmp_preview' => '1' ),
	'cap'        => true,
	'user'       => 7,
	'uri'        => '/landing/offer/?cmp_preview=1&utm=1',
	'settings'   => $pv_stored,
	'transients' => array( 'cmp_preview_7' => $pv_overlay ),
	'cookies'    => array( 'cmp_consent' => 'REAL', 'cmp_svc' => 'REALSVC', 'cmp_check_consent' => 'old', 'cmp_check_svc' => 'old', 'cmp_preview_t' => 'good-preview' ),
);
$pv_cookies = function ( array $c ) use ( $pv_base ) {
	return array_merge( $pv_base, array( 'cookies' => array_merge( array( 'cmp_consent' => 'REAL', 'cmp_svc' => 'REALSVC', 'cmp_check_consent' => 'old', 'cmp_check_svc' => 'old' ), $c ) ) );
};
$pv_stored_j = $pv_json( $pv_stored );
$pv_check_shape = CMP_Scanner::check_data( array( 'version' => '3', 'cookieName' => 'cmp_consent', 'reloadOnDecision' => true, 'cookieDays' => 180 ), true );

// --- Request validation (Review Focus 5): anything but a valid nonce + manage_options is ignored completely. ---
$pv_ignored = array(
	'no marker (cookie only)'                => array_merge( $pv_base, array( 'get' => array() ) ),
	'empty marker'                           => array_merge( $pv_base, array( 'get' => array( 'cmp_preview' => '' ) ) ),
	'visitor ?cmp_preview=x'                 => array_merge( $pv_cookies( array() ), array( 'get' => array( 'cmp_preview' => 'x' ), 'cap' => false ) ),
	'visitor marker, no cookie'              => array_merge( $pv_cookies( array() ), array( 'cap' => false ) ),
	'visitor marker + forged cookie'         => array_merge( $pv_cookies( array( 'cmp_preview_t' => 'forged' ) ), array( 'cap' => false ) ),
	'admin marker, no cookie (other admin)'  => $pv_cookies( array() ),
	'wrong nonce in the cookie (admin)'      => $pv_cookies( array( 'cmp_preview_t' => 'nope' ) ),
	'cmp_check nonce in the cookie'          => $pv_cookies( array( 'cmp_preview_t' => 'good-check' ) ),
	'old link ?cmp_preview=<nonce>'          => array_merge( $pv_cookies( array() ), array( 'get' => array( 'cmp_preview' => 'good-preview' ) ) ),
	'old link ?cmp_preview=<nonce> + cookie' => array_merge( $pv_base, array( 'get' => array( 'cmp_preview' => 'good-preview' ) ) ),
	'valid cookie, no manage_options'        => array_merge( $pv_base, array( 'cap' => false ) ),
	'array marker'                           => array_merge( $pv_base, array( 'get' => array( 'cmp_preview' => array( '1' ) ) ) ),
	'array cookie'                           => $pv_cookies( array( 'cmp_preview_t' => array( 'good-preview' ) ) ),
	'valid, in wp-admin'                     => array_merge( $pv_base, array( 'admin' => true ) ),
	'valid, AJAX'                            => array_merge( $pv_base, array( 'ajax' => true ) ),
	'valid, REST (?rest_route)'              => array_merge( $pv_base, array( 'get' => array( 'cmp_preview' => '1', 'rest_route' => '/wp/v2/posts' ) ) ),
);
foreach ( $pv_ignored as $pv_label => $pv_in ) {
	$r = cmp_pv_request( $pv_in );
	cmp_assert( isset( $r['preview'] ) && false === $r['preview'] && false === $r['isolated'], "preview ignored: {$pv_label} -> not active" );
	cmp_assert_eq( isset( $r['data'] ) ? $r['data'] : null, array( 'version' => '3', 'cookieName' => 'cmp_consent', 'reloadOnDecision' => true, 'cookieDays' => 180 ), "preview ignored: {$pv_label} -> CMP_DATA unchanged" );
	cmp_assert_eq( isset( $r['settings'] ) ? $r['settings'] : null, $pv_stored_j, "preview ignored: {$pv_label} -> stored settings, transient not used" );
	cmp_assert( isset( $r['bar'] ) && '' === $r['bar'], "preview ignored: {$pv_label} -> no preview bar" );
	cmp_assert( isset( $r['nocache'], $r['adminBar'], $r['excludedRule'] ) && 0 === $r['nocache'] && false === $r['donotcache'] && true === $r['adminBar'] && true === $r['excludedRule'], "preview ignored: {$pv_label} -> cache, admin bar and exclusions as usual" );
	cmp_assert_eq( isset( $r['cookies'] ) ? $r['cookies'] : null, $pv_in['cookies'], "preview ignored: {$pv_label} -> cookies untouched" );
	cmp_assert_eq( isset( $r['cookieName'] ) ? $r['cookieName'] : null, 'cmp_consent', "preview ignored: {$pv_label} -> server reads the real consent cookie" );
}

// --- A valid preview. ---
$pv = cmp_pv_request( $pv_base );
cmp_assert( ! empty( $pv['preview'] ) && ! empty( $pv['isolated'] ), 'preview valid: marker =1 + cookie nonce cmp_preview + manage_options -> active, isolated session' );
cmp_assert( isset( $pv['check'] ) && false === $pv['check'], 'preview valid: not the check mode (banner-check.js is not loaded)' );
cmp_assert_eq( isset( $pv['data'] ) ? $pv['data'] : null, $pv_check_shape, 'preview valid: CMP_DATA = the check-mode shape (check, cmp_check_* cookies, no reload)' );
cmp_assert_eq( isset( $pv['cookieName'] ) ? $pv['cookieName'] : null, 'cmp_check_consent', 'preview valid: the server reads the check cookie (blocking runs as for a first visit)' );
cmp_assert_eq( isset( $pv['cookies'] ) ? $pv['cookies'] : null, array( 'cmp_consent' => 'REAL', 'cmp_svc' => 'REALSVC', 'cmp_preview_t' => 'good-preview' ), 'preview valid: earlier check cookies cleared (banner always shows), real cmp_consent / cmp_svc untouched' );
cmp_assert( isset( $pv['nocache'] ) && 1 === $pv['nocache'] && true === $pv['donotcache'], 'preview valid: nocache_headers() + DONOTCACHEPAGE' );
cmp_assert( isset( $pv['adminBar'] ) && false === $pv['adminBar'], 'preview valid: WP admin bar hidden (as in check mode)' );
cmp_assert( isset( $pv['excludedRule'] ) && false === $pv['excludedRule'], 'preview valid: exclusions ignored (/landing/* still auto-opens)' );
cmp_assert_eq( isset( $pv['settings'] ) ? $pv['settings'] : null, $pv_json( array_merge( $pv_stored, $pv_overlay ) ), 'preview valid: rendering settings = stored + the transient\'s appearance keys' );
cmp_assert( isset( $pv['settings']['mode'], $pv['settings']['block_scripts'] ) && 'pro' === $pv['settings']['mode'] && true === $pv['settings']['block_scripts'], 'preview valid: non-appearance settings (mode, blocking) unchanged' );
cmp_assert_eq( isset( $pv['writes'] ) ? $pv['writes'] : null, array(), 'preview valid: no option / transient written by the front-end request' );
cmp_assert( isset( $pv['nonceCalls'] ) && 1 === $pv['nonceCalls'], 'preview valid: the nonce is verified once per request (memoised)' );
$pv_bar = isset( $pv['bar'] ) ? $pv['bar'] : '';
cmp_assert( 1 === substr_count( $pv_bar, 'id="cmp-preview-bar"' ), 'preview bar: printed once (wp_body_open, wp_footer fallback)' );
cmp_assert( false !== strpos( $pv_bar, 'Previzualizare: setările nu sunt salvate' ), 'preview bar: "Previzualizare: setările nu sunt salvate"' );
cmp_assert( false !== strpos( $pv_bar, '>Închide previzualizarea</a>' ), 'preview bar: link "Închide previzualizarea"' );
cmp_assert( false !== strpos( $pv_bar, 'href="/landing/offer/?utm=1"' ), 'preview bar: the close link is this page without cmp_preview (other params kept)' );
cmp_assert( false !== strpos( $pv_bar, 'role="region"' ) && false !== strpos( $pv_bar, 'aria-label="Previzualizare aspect"' ), 'preview bar: a labelled region (accessible)' );
cmp_assert( false !== strpos( $pv_bar, 'position:fixed;top:0' ), 'preview bar: modal/center banner -> bar fixed at the top' );
cmp_assert( 1 === preg_match( '/z-index:(\d+)/', $pv_bar, $pv_z ) && (int) $pv_z[1] > 2147483600 && 2147483647 === (int) $pv_z[1], 'preview bar (I2): z-index 2147483647, above the banner overlay (2147483600)' );
// I2: a top-anchored previewed banner -> the bar goes to the bottom edge.
$pv_top_ov = $pv_overlay;
$pv_top_ov['appearance']['format']   = 'bar';
$pv_top_ov['appearance']['position'] = 'top';
$pv_top = cmp_pv_request( array_merge( $pv_base, array( 'transients' => array( 'cmp_preview_7' => $pv_top_ov ) ) ) );
cmp_assert( isset( $pv_top['bar'] ) && false !== strpos( $pv_top['bar'], 'position:fixed;bottom:0' ), 'preview bar (I2): bar/top preview -> bar at the bottom (decided from the PREVIEWED appearance)' );
foreach ( array(
	array( array( 'format' => 'bar', 'position' => 'top' ), 'pro', 'bottom' ),
	array( array( 'format' => 'modal', 'position' => 'top' ), 'pro', 'bottom' ),
	array( array( 'format' => 'card', 'position' => 'top-left' ), 'pro', 'bottom' ),
	array( array( 'format' => 'card', 'position' => 'top-right' ), 'pro', 'bottom' ),
	array( array( 'format' => 'bar', 'position' => 'bottom' ), 'pro', 'top' ),
	array( array( 'format' => 'modal', 'position' => 'center' ), 'pro', 'top' ),
	array( array( 'format' => 'card', 'position' => 'bottom-right' ), 'pro', 'top' ),
	array( array( 'format' => 'bar', 'position' => 'top' ), 'light', 'top' ),
) as $pv_case ) {
	cmp_assert_eq( CMP_Preview::bar_side( $pv_case[0], $pv_case[1] ), $pv_case[2], 'bar_side: ' . $pv_case[0]['format'] . '/' . $pv_case[0]['position'] . ' (' . $pv_case[1] . ') -> ' . $pv_case[2] );
}
cmp_assert( false !== strpos( CMP_Preview::bar_html( '/', 'evil;top:0' ), 'position:fixed;top:0;left' ), 'bar_html: unknown side -> top (no injection into the style)' );
cmp_assert( 0 === preg_match( '/class="cmp[\s"_-]|data-cmp-/', $pv_bar ), 'preview bar: not part of the banner (no cmp classes / data-cmp hooks)' );

// Without a transient (expired): stored settings, still a preview.
$pv_exp = cmp_pv_request( array_merge( $pv_base, array( 'transients' => array() ) ) );
cmp_assert( ! empty( $pv_exp['preview'] ) && isset( $pv_exp['settings'] ) && $pv_exp['settings'] === $pv_stored_j, 'preview: transient expired -> stored appearance (fallback)' );
// Another admin's transient is never used.
$pv_other = cmp_pv_request( array_merge( $pv_base, array( 'user' => 8 ) ) );
cmp_assert( isset( $pv_other['settings'] ) && $pv_other['settings'] === $pv_stored_j, 'preview: per user (user 8 does not get user 7\'s transient)' );
// The check mode keeps its own nonce: a preview nonce does not start it.
$pv_chk = cmp_pv_request( array_merge( $pv_base, array( 'get' => array( 'cmp_check' => 'good-preview', 'cmp_preview' => '0' ) ) ) );
cmp_assert( isset( $pv_chk['check'] ) && false === $pv_chk['check'] && false === $pv_chk['isolated'], 'check mode: a cmp_preview nonce in ?cmp_check is ignored' );
$pv_chk2 = cmp_pv_request( array_merge( $pv_cookies( array() ), array( 'get' => array( 'cmp_check' => 'good-check' ) ) ) );
cmp_assert( ! empty( $pv_chk2['check'] ) && empty( $pv_chk2['preview'] ) && '' === $pv_chk2['bar'] && isset( $pv_chk2['data'] ) && $pv_chk2['data'] === $pv_check_shape, 'check mode: unchanged by the refactor (same CMP_DATA, no preview bar)' );

// --- Pure: apply() (the overlay) ---
cmp_assert_eq( CMP_Preview::apply( $pv_stored, false ), $pv_stored, 'apply: no transient -> stored settings' );
cmp_assert_eq( CMP_Preview::apply( $pv_stored, 'junk' ), $pv_stored, 'apply: non-array transient -> stored settings' );
cmp_assert_eq(
	CMP_Preview::apply( $pv_stored, array( 'appearance' => 'x', 'preset' => array(), 'mode' => 'light', 'block_scripts' => false, 'cookie_days' => 1 ) ),
	$pv_stored,
	'apply: only the Aspect keys with their types (mode, blocking, cookie days can never come from a preview)'
);

// --- Pure: build() — the same sanitizing as the save. ---
$pv_raw = array(
	'appearance' => array(
		'style'      => 'friendly',
		'radius'     => '999',
		'palette'    => 'evil',
		'custom_css' => '.cmp{color:red}[data-cmp-action="reject"]{display:none}@import url(https://evil.test/x.css);',
		'precheck'   => '1',
	),
	'mode'          => 'light',
	'block_scripts' => '0',
);
$pv_built = CMP_Preview::build( $pv_raw, $pv_stored, false );
cmp_assert_eq( array_keys( $pv_built ), array( 'appearance', 'preset', 'texts' ), 'build: only the keys an Aspect save changes (no mode, no blocking)' );
cmp_assert_eq( $pv_built['appearance'], CMP_Sanitizer::aspect( $pv_raw, $pv_stored, false )['appearance'], 'build: appearance = CMP_Sanitizer::aspect() (the save path)' );
cmp_assert( 24 === $pv_built['appearance']['radius'], 'build: radius clamped to 24' );
cmp_assert( 'neutral' === $pv_built['appearance']['palette'], 'build: unknown palette keeps the stored one' );
cmp_assert( ! isset( $pv_built['appearance']['precheck'] ), 'build: unknown keys dropped (B6.18)' );
cmp_assert( false === strpos( $pv_built['appearance']['custom_css'], 'reject' ) && false === strpos( $pv_built['appearance']['custom_css'], '@import' ) && false !== strpos( $pv_built['appearance']['custom_css'], 'color:red' ), 'build: custom CSS cleaned like the save' );
$pv_b2 = CMP_Preview::build( array( 'appearance' => array( 'reopen' => 'text' ) ), $pv_stored, false );
cmp_assert( true === $pv_b2['reopen_button'] && 'text' === $pv_b2['appearance']['reopen'], 'build: reopen kept in sync with reopen_button (as the save)' );

// --- The transient: per user, 30 minutes, overwritten. ---
$pv_store = array();
$pv_set   = function ( $k, $v, $ttl ) use ( &$pv_store ) {
	$pv_store[ $k ] = array( $v, $ttl );
	return true;
};
CMP_Preview::store( 7, array( 'preset' => 'warm' ), $pv_set );
CMP_Preview::store( 9, array( 'preset' => 'dark' ), $pv_set );
CMP_Preview::store( 7, array( 'preset' => 'egov' ), $pv_set );
cmp_assert_eq( array_keys( $pv_store ), array( 'cmp_preview_7', 'cmp_preview_9' ), 'store: transient cmp_preview_<user_id>, one per user' );
cmp_assert_eq( $pv_store['cmp_preview_7'], array( array( 'preset' => 'egov' ), 1800 ), 'store: overwritten at each click, 30 minutes' );
cmp_assert_eq( $pv_store['cmp_preview_9'][0], array( 'preset' => 'dark' ), 'store: another admin keeps their own' );
cmp_assert( 1800 === CMP_Preview::TTL && 'cmp_preview' === CMP_Preview::PARAM && 'cmp_preview_7' === CMP_Preview::key( '7' ), 'constants: 30 min, cmp_preview, key' );

// --- Pure: links. ---
cmp_assert_eq( CMP_Preview::url( 'http://example.test/' ), 'http://example.test/?cmp_preview=1', 'url (I1): home + the marker only, no nonce' );
cmp_assert_eq( CMP_Preview::url( 'http://example.test/?lang=ru#x' ), 'http://example.test/?lang=ru&cmp_preview=1', 'url: keeps the home query (WPML), drops the hash' );
cmp_assert_eq(
	CMP_Preview::cookie_options( 1000, true, '/sub/' ),
	array( 'expires' => 2800, 'path' => '/sub/', 'domain' => '', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict' ),
	'cookie_options (I1): HttpOnly, SameSite=Strict, Secure on HTTPS, 30 minutes, COOKIEPATH'
);
cmp_assert( false === CMP_Preview::cookie_options( 0, false, '' )['secure'] && '/' === CMP_Preview::cookie_options( 0, false, '' )['path'], 'cookie_options: not Secure on HTTP; empty path -> /' );
cmp_assert( 'cmp_preview_t' === CMP_Preview::COOKIE, 'cookie name cmp_preview_t' );
cmp_assert_eq( CMP_Scanner::token_from( array( 'cmp_check' => 'n' ), array(), 'cmp_check', null ), 'n', 'token_from: check mode reads the nonce from the URL (unchanged)' );
cmp_assert_eq( CMP_Scanner::token_from( array( 'cmp_preview' => 'n' ), array( 'cmp_preview_t' => 'c' ), 'cmp_preview', 'cmp_preview_t' ), null, 'token_from: preview never takes a nonce from the URL' );
cmp_assert_eq( CMP_Scanner::token_from( array( 'cmp_preview' => '1' ), array( 'cmp_preview_t' => 'c' ), 'cmp_preview', 'cmp_preview_t' ), 'c', 'token_from: marker =1 -> the cookie' );
cmp_assert_eq( CMP_Preview::close_url( '/?cmp_preview=ab' ), '/', 'close_url: only parameter' );
cmp_assert_eq( CMP_Preview::close_url( '/p/?a=1&cmp_preview=ab&b=2#top' ), '/p/?a=1&b=2#top', 'close_url: other parameters and hash kept' );
cmp_assert_eq( CMP_Preview::close_url( '/p/?cmp_previewx=1' ), '/p/?cmp_previewx=1', 'close_url: a similar name is kept' );
cmp_assert( false === strpos( CMP_Preview::bar_html( '"><script>' ), '<script>' ), 'bar_html: close link escaped' );

// --- Admin: the button and the AJAX endpoint. ---
$pv_btn = CMP_Appearance_Page::preview_button_html( 'n0nce', 'http://example.test/wp-admin/admin-ajax.php' );
cmp_assert( false !== strpos( $pv_btn, 'type="button"' ) && false !== strpos( $pv_btn, 'data-cmp-preview ' ) && false !== strpos( $pv_btn, ' hidden' ), 'button: a JS-only button (hidden until admin-appearance.js runs), not a submit' );
cmp_assert( false !== strpos( $pv_btn, 'Vezi pe site' ) && false !== strpos( $pv_btn, 'data-cmp-preview-nonce="n0nce"' ) && false !== strpos( $pv_btn, 'admin-ajax.php' ), 'button: label, AJAX nonce and URL' );
cmp_assert( false !== strpos( $pv_btn, 'role="status"' ), 'button: a status region for the error' );
$pv_page  = file_get_contents( __DIR__ . '/../includes/admin/class-appearance-page.php' );
$pv_admin = file_get_contents( __DIR__ . '/../includes/admin/class-admin.php' );
$pv_src   = file_get_contents( __DIR__ . '/../includes/modules/class-preview.php' );
cmp_assert( false !== strpos( $pv_admin, "add_action( 'cmp_appearance_actions', array( 'CMP_Appearance_Page', 'render_preview_button' ) )" ), 'button: rendered in the Task 4 slot (cmp_appearance_actions)' );
cmp_assert( 1 === preg_match( "/function render_preview_button\(\) \{\s*if \( ! current_user_can\( 'manage_options' \) \)/", $pv_page ), 'button: manage_options only' );
cmp_assert( 1 === preg_match( "/function ajax_store\(\) \{\s*if \( ! current_user_can\( 'manage_options' \) \|\| ! check_ajax_referer\( self::AJAX, 'nonce', false \) \) \{\s*wp_send_json_error\( 'forbidden', 403 \);/", $pv_src ), 'ajax_store: manage_options + nonce, else 403' );
cmp_assert( false !== strpos( $pv_src, "self::store( get_current_user_id(), self::build( \$raw, \$old, CMP_Banner::multilingual() ) )" ), 'ajax_store: stores build() (the save path) for the current user' );
cmp_assert( false !== strpos( $pv_src, "add_action( 'wp_ajax_' . self::AJAX" ) && false === strpos( $pv_src, 'wp_ajax_nopriv_' ), 'ajax_store: logged-in AJAX only' );
// M3: ajax_store() behaviour, in its own process.
$pv_ajax = function ( array $in ) {
	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/preview-ajax.php' ) . ' ' . escapeshellarg( json_encode( $in ) ) . ' 2>&1';
	$out = json_decode( (string) shell_exec( $cmd ), true );
	return is_array( $out ) ? $out : array();
};
$pv_post = array( 'nonce' => 'good-ajax', 'cmp' => $pv_raw );
foreach ( array(
	'visitor'           => array( 'cap' => false, 'post' => $pv_post ),
	'admin, no nonce'   => array( 'cap' => true, 'post' => array( 'cmp' => $pv_raw ) ),
	'admin, bad nonce'  => array( 'cap' => true, 'post' => array( 'nonce' => 'bad', 'cmp' => $pv_raw ) ),
) as $pv_l => $pv_in ) {
	$r = $pv_ajax( array_merge( array( 'user' => 7, 'settings' => $pv_stored ), $pv_in ) );
	cmp_assert( isset( $r['status'] ) && 403 === $r['status'] && array() === $r['transients'] && array() === $r['options'] && array() === $r['nonces'], "ajax_store: {$pv_l} -> 403, nothing stored, no nonce made" );
}
$r = $pv_ajax( array( 'cap' => true, 'user' => 7, 'settings' => $pv_stored, 'post' => $pv_post ) );
cmp_assert( isset( $r['status'] ) && 200 === $r['status'] && ! empty( $r['body']['success'] ), 'ajax_store: valid -> success' );
cmp_assert_eq( isset( $r['body']['data']['url'] ) ? $r['body']['data']['url'] : null, 'http://example.test/?cmp_preview=1', 'ajax_store (I1): answers the home page with the marker only (no nonce in the URL)' );
cmp_assert_eq( isset( $r['transients'] ) ? array_keys( $r['transients'] ) : null, array( 'cmp_preview_7' ), 'ajax_store: only the transient of the current user' );
cmp_assert( isset( $r['transients']['cmp_preview_7'] ) && 1800 === $r['transients']['cmp_preview_7']['ttl'] && $r['transients']['cmp_preview_7']['value'] === $pv_json( CMP_Preview::build( $pv_raw, $pv_stored, false ) ), 'ajax_store: the transient = build() (sanitized), 30 minutes' );
cmp_assert( isset( $r['options'], $r['nonces'] ) && array() === $r['options'] && array( 'cmp_preview' ) === $r['nonces'], 'ajax_store: no option written; one cmp_preview nonce (for the cookie)' );
cmp_assert( 1 === preg_match( '/self::send_cookie\( wp_create_nonce\( self::PARAM \) \);\s*wp_send_json_success\( array\( \'url\' => self::url\( home_url\( \'\/\' \) \) \) \);/', $pv_src ), 'ajax_store: the nonce goes into the cookie, not the answer' );
cmp_assert( false !== strpos( $pv_src, 'self::cookie_options( time(), is_ssl(), $path, $domain )' ) && false !== strpos( $pv_src, 'COOKIEPATH' ), 'send_cookie: cookie_options() with is_ssl() on COOKIEPATH / SITECOOKIEPATH' );

// No register write path: nothing in the preview logs or writes consent.
cmp_assert( 0 === preg_match( '/CMP_Audit|cmp_log|update_option|cmp_consent/', $pv_src ) && 1 === substr_count( $pv_src, 'setcookie(' ) && false !== strpos( $pv_src, 'setcookie( self::COOKIE,' ), 'no register write: CMP_Preview never logs, writes options or sets consent cookies (only its own nonce cookie)' );
$pv_bjs = file_get_contents( __DIR__ . '/../assets/js/banner.js' );
cmp_assert( false !== strpos( $pv_bjs, 'if (!D.ajaxUrl || D.check) { return; }' ), 'no register write: banner.js sends no log with CMP_DATA.check (set in preview)' );

// --- Wiring (source). ---
$pv_bn = file_get_contents( __DIR__ . '/../includes/modules/class-banner.php' );
$pv_sc = file_get_contents( __DIR__ . '/../includes/modules/class-scanner.php' );
cmp_assert( false !== strpos( $pv_bn, '$cmp_data = CMP_Scanner::check_data( $cmp_data, CMP_Scanner::isolated() );' ), 'banner: CMP_DATA overlay for check and preview' );
cmp_assert( false !== strpos( $pv_bn, 'self::excluded_now( self::appearance( $cmp_settings ), CMP_Scanner::isolated() )' ), 'banner: exclusions ignored in an isolated session' );
cmp_assert( 3 === substr_count( $pv_bn, '= self::settings();' ), 'banner: enqueue, render and the appearance CSS read the preview settings' );
cmp_assert( 1 === preg_match( '/function check_page_setup\(\) \{\s*if \( ! self::isolated\(\) \)/', $pv_sc ), 'scanner: no cache / check cookies cleared in check and preview' );
cmp_assert( 1 === preg_match( '/function check_admin_bar\( \$show \) \{\s*return self::isolated\(\) \? false : \$show;/', $pv_sc ), 'scanner: admin bar hidden in check and preview' );
cmp_assert( false !== strpos( $pv_sc, "return self::token_mode( 'cmp_check', 'cmp_check' );" ), 'scanner: check mode = the shared token mode' );
cmp_assert( false !== strpos( file_get_contents( __DIR__ . '/../includes/class-plugin.php' ), "CMP_Preview::register();" ), 'plugin: preview registered' );
// Zero bytes in the visitor banner: no preview code in banner.js / banner.css.
cmp_assert( false === strpos( $pv_bjs, 'cmp_preview' ) && false === strpos( file_get_contents( __DIR__ . '/../assets/css/banner.css' ), 'preview' ), 'budget: banner.js / banner.css carry no preview code' );
