<?php
/**
 * B7.5 Task 1: the "Ghid" admin screen. Anchors on the page, contextual links
 * from every plugin screen, CMP_Guide::link() escaping and fallback, and the
 * paragraphs with links filtered through wp_kses_post.
 * Renders the real template with minimal stubs.
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Translations the test can override (only when this file defines __()).
$GLOBALS['cmp_guide_tr'] = array();
if ( ! function_exists( '__' ) ) {
	$GLOBALS['cmp_guide_own_gettext'] = true;
	function __( $t, $d = null ) {
		return isset( $GLOBALS['cmp_guide_tr'][ $t ] ) ? $GLOBALS['cmp_guide_tr'][ $t ] : $t;
	}
}
foreach ( array(
	'esc_html'   => 'return htmlspecialchars( (string) $t, ENT_QUOTES, "UTF-8" );',
	'esc_attr'   => 'return htmlspecialchars( (string) $t, ENT_QUOTES, "UTF-8" );',
	'esc_url'    => 'return htmlspecialchars( (string) $t, ENT_QUOTES, "UTF-8" );',
	'admin_url'  => 'return "http://example.test/wp-admin/" . $t;',
) as $guide_fn => $guide_body ) {
	if ( ! function_exists( $guide_fn ) ) {
		eval( "function $guide_fn( \$t ) { $guide_body }" );
	}
}
foreach ( array(
	'esc_html__' => 'return htmlspecialchars( __( $t, $d ), ENT_QUOTES, "UTF-8" );',
	'esc_attr__' => 'return htmlspecialchars( __( $t, $d ), ENT_QUOTES, "UTF-8" );',
	'esc_html_e' => 'echo htmlspecialchars( __( $t, $d ), ENT_QUOTES, "UTF-8" );',
	'esc_attr_e' => 'echo htmlspecialchars( __( $t, $d ), ENT_QUOTES, "UTF-8" );',
) as $guide_fn => $guide_body ) {
	if ( ! function_exists( $guide_fn ) ) {
		eval( "function $guide_fn( \$t, \$d = null ) { $guide_body }" );
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	$GLOBALS['cmp_guide_own_kses'] = true;
	/** Stand-in for wp_kses_post: keeps the inline tags the guide uses, drops the rest. */
	function wp_kses_post( $t ) {
		return strip_tags( (string) $t, '<a><code><strong><em><br>' );
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) {
		return true;
	}
}

$guide_class_file = __DIR__ . '/../includes/admin/class-guide.php';
cmp_assert( is_file( $guide_class_file ) && is_file( __DIR__ . '/../templates/admin/guide.php' ), 'guide: class-guide.php and templates/admin/guide.php exist' );
if ( ! is_file( $guide_class_file ) ) {
	return;
}
require_once $guide_class_file;

$guide_expected = array( 'start', 'modes', 'services', 'examples', 'verify', 'legal', 'update', 'register', 'developers' );
cmp_assert( class_exists( 'CMP_Guide' ), 'guide: CMP_Guide exists' );
cmp_assert_eq( CMP_Guide::ANCHORS, $guide_expected, 'guide: ANCHORS are the 9 sections, in order' );
cmp_assert_eq( CMP_Guide::SLUG, 'cmp-guide', 'guide: page slug is cmp-guide' );

// The rendered page: one id per anchor, a table of contents linking to each.
ob_start();
CMP_Guide::render();
$guide_html = (string) ob_get_clean();
foreach ( CMP_Guide::ANCHORS as $guide_a ) {
	cmp_assert( 1 === substr_count( $guide_html, 'id="' . $guide_a . '"' ), "guide: page has exactly one id=\"$guide_a\"" );
	cmp_assert( false !== strpos( $guide_html, 'href="#' . $guide_a . '"' ), "guide: table of contents links to #$guide_a" );
}
cmp_assert( false !== strpos( $guide_html, 'class="wrap cmp-admin cmp-guide"' ), 'guide: page uses the admin screens wrapper' );
cmp_assert( false !== strpos( $guide_html, 'Pornire în 5 minute' ) && false !== strpos( $guide_html, 'Pentru developeri' ), 'guide: section titles rendered' );
cmp_assert( false === strpos( $guide_html, '%1$s' ) && false === strpos( $guide_html, '%2$s' ) && false === strpos( $guide_html, '%s' ), 'guide: no placeholder left unfilled' );
cmp_assert( false !== strpos( $guide_html, 'href="http://example.test/wp-admin/admin.php?page=cmp-settings"' ), 'guide: links to the Setări screen' );
cmp_assert( false !== strpos( $guide_html, 'href="http://example.test/wp-admin/admin.php?page=cmp-check"' ), 'guide: links to the Verificare screen' );
cmp_assert( 1 === preg_match_all( '/<h1\b/', $guide_html ), 'guide: one h1' );
cmp_assert( false === strpos( $guide_html, '<script' ), 'guide: no script on the page' );

// A user without manage_options gets nothing (render() checks the capability).
$guide_src = (string) file_get_contents( __DIR__ . '/../includes/admin/class-guide.php' );
cmp_assert( false !== strpos( $guide_src, "current_user_can( 'manage_options' )" ), 'guide: render() checks manage_options' );

// link(): escaped HTML to admin.php?page=cmp-guide#<anchor>.
$guide_link = CMP_Guide::link( 'modes' );
cmp_assert( false !== strpos( $guide_link, 'href="http://example.test/wp-admin/admin.php?page=cmp-guide#modes"' ), 'guide: link(modes) points at the modes section' );
cmp_assert( 1 === preg_match( '/^<a [^>]*>Ghid<\/a>$/', $guide_link ), 'guide: link() is one <a> labelled "Ghid"' );
foreach ( CMP_Guide::ANCHORS as $guide_a ) {
	cmp_assert( false !== strpos( CMP_Guide::link( $guide_a ), 'page=cmp-guide#' . $guide_a . '"' ), "guide: link($guide_a) keeps its anchor" );
}
$guide_bad = CMP_Guide::link( 'x"><script>alert(1)</script>' );
cmp_assert( false !== strpos( $guide_bad, 'href="http://example.test/wp-admin/admin.php?page=cmp-guide"' ), 'guide: unknown anchor -> link without anchor' );
cmp_assert( false === strpos( $guide_bad, '<script' ) && false === strpos( $guide_bad, '#' ), 'guide: unknown anchor is dropped, not echoed' );
cmp_assert( false !== strpos( CMP_Guide::link( '' ), 'page=cmp-guide"' ), 'guide: empty anchor -> link without anchor' );
$GLOBALS['cmp_guide_tr']['Ghid'] = 'Guide <b>"x"</b>';
if ( ! empty( $GLOBALS['cmp_guide_own_gettext'] ) ) {
	cmp_assert( false !== strpos( CMP_Guide::link( 'start' ), '>Guide &lt;b&gt;&quot;x&quot;&lt;/b&gt;</a>' ), 'guide: link label is escaped' );
}
unset( $GLOBALS['cmp_guide_tr']['Ghid'] );

// Contextual links: each plugin screen links to its section, with a known anchor.
$guide_screens = array(
	'class-settings-page.php'   => 'modes',
	'class-services-page.php'   => 'services',
	'class-categories-page.php' => 'services',
	'class-check-page.php'      => 'verify',
	'class-audit-page.php'      => 'register',
	'class-legal-page.php'      => 'legal',
);
foreach ( $guide_screens as $guide_file => $guide_a ) {
	$guide_s = (string) file_get_contents( __DIR__ . '/../includes/admin/' . $guide_file );
	cmp_assert( false !== strpos( $guide_s, "CMP_Guide::link( '" . $guide_a . "' )" ), "guide: $guide_file links to #$guide_a" );
	// In the screen header: right after the <h1> title.
	cmp_assert( 1 === preg_match( "/<h1>[^\n]*<\/h1>\s*<\?php echo CMP_Guide::link\( '" . $guide_a . "' \);/", $guide_s ) || 1 === preg_match( "/<h1>.*CMP_Guide::link\( '" . $guide_a . "' \).*<\/h1>/", $guide_s ), "guide: $guide_file has the link in its header" );
}
$guide_all = array();
foreach ( array_merge( glob( __DIR__ . '/../includes/admin/*.php' ), glob( __DIR__ . '/../templates/admin/*.php' ) ) as $guide_f ) {
	if ( preg_match_all( "/CMP_Guide::link\(\s*'([^']*)'\s*\)/", (string) file_get_contents( $guide_f ), $guide_m ) ) {
		foreach ( $guide_m[1] as $guide_used ) {
			$guide_all[] = basename( $guide_f ) . ':' . $guide_used;
			cmp_assert( in_array( $guide_used, CMP_Guide::ANCHORS, true ), "guide: contextual link anchor '$guide_used' (" . basename( $guide_f ) . ') is in ANCHORS' );
		}
	}
}
cmp_assert( count( $guide_all ) >= 6, 'guide: at least 6 contextual links found (' . count( $guide_all ) . ')' );

// Menu: "Ghid" is registered last, manage_options, slug cmp-guide.
$guide_admin = (string) file_get_contents( __DIR__ . '/../includes/admin/class-admin.php' );
preg_match_all( '/add_submenu_page\((.*?)\);/s', $guide_admin, $guide_sub );
$guide_last = end( $guide_sub[1] );
cmp_assert( false !== strpos( (string) $guide_last, "'cmp-guide'" ) || false !== strpos( (string) $guide_last, 'CMP_Guide::SLUG' ), 'guide: submenu Ghid is the last one' );
cmp_assert( false !== strpos( (string) $guide_last, "'manage_options'" ) && false !== strpos( (string) $guide_last, "__( 'Ghid', 'consent-manager-pro' )" ), 'guide: submenu needs manage_options, label Ghid' );
cmp_assert( false !== strpos( $guide_admin, "require_once CMP_PATH . 'includes/admin/class-guide.php';" ), 'guide: class-admin loads class-guide.php' );

// Paragraphs with HTML go through wp_kses_post: every echo in the template is escaped.
$guide_tpl  = (string) file_get_contents( __DIR__ . '/../templates/admin/guide.php' );
$guide_toks = token_get_all( $guide_tpl );
$guide_raw  = array();
foreach ( $guide_toks as $guide_i => $guide_t ) {
	if ( ! is_array( $guide_t ) || T_ECHO !== $guide_t[0] ) {
		continue;
	}
	$guide_j = $guide_i + 1;
	while ( isset( $guide_toks[ $guide_j ] ) && is_array( $guide_toks[ $guide_j ] ) && T_WHITESPACE === $guide_toks[ $guide_j ][0] ) {
		$guide_j++;
	}
	$guide_next = isset( $guide_toks[ $guide_j ] ) && is_array( $guide_toks[ $guide_j ] ) ? $guide_toks[ $guide_j ][1] : '';
	if ( ! in_array( $guide_next, array( 'wp_kses_post', 'esc_html', 'esc_html__', 'esc_url', 'esc_attr' ), true ) ) {
		$guide_raw[] = 'line ' . $guide_t[2] . ': ' . $guide_next;
	}
}
cmp_assert( ! $guide_raw, 'guide: every echo in the template is escaped or wp_kses_post' . ( $guide_raw ? ' (' . implode( ', ', $guide_raw ) . ')' : '' ) );
cmp_assert( substr_count( $guide_tpl, 'wp_kses_post(' ) >= 10, 'guide: paragraphs with links use wp_kses_post (' . substr_count( $guide_tpl, 'wp_kses_post(' ) . ')' );
// No HTML inside the translatable texts: links and code come in through placeholders.
preg_match_all( "/(?:__|esc_html__|esc_html_e)\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*,\s*'consent-manager-pro'/", $guide_tpl, $guide_strs );
$guide_html_strs = array();
foreach ( $guide_strs[1] as $guide_str ) {
	if ( false !== strpos( $guide_str, '<' ) ) {
		$guide_html_strs[] = $guide_str;
	}
}
cmp_assert( count( $guide_strs[1] ) > 80, 'guide: the template has its texts in gettext calls (' . count( $guide_strs[1] ) . ')' );
cmp_assert( ! $guide_html_strs, 'guide: no HTML inside translatable texts' . ( $guide_html_strs ? ' (' . $guide_html_strs[0] . ')' : '' ) );

// A translation that smuggles a <script> in is filtered on output.
if ( ! empty( $GLOBALS['cmp_guide_own_gettext'] ) && ! empty( $GLOBALS['cmp_guide_own_kses'] ) ) {
	foreach ( $guide_strs[1] as $guide_str ) {
		if ( false !== strpos( $guide_str, '%1$s' ) ) {
			$GLOBALS['cmp_guide_tr'][ stripslashes( $guide_str ) ] = 'X %1$sY%2$s <script>alert(1)</script> <img src=x onerror=alert(1)>';
		}
	}
	ob_start();
	CMP_Guide::render();
	$guide_evil = (string) ob_get_clean();
	$GLOBALS['cmp_guide_tr'] = array();
	cmp_assert( false === strpos( $guide_evil, '<script' ) && false === strpos( $guide_evil, '<img' ), 'guide: HTML in a translated paragraph is filtered (wp_kses_post)' );
	cmp_assert( false !== strpos( $guide_evil, 'X <a href="http://example.test/wp-admin/admin.php?page=cmp-' ), 'guide: the link placeholders still work in a translation' );
}

// Minimal CSS for the table of contents.
$guide_css = (string) file_get_contents( __DIR__ . '/../assets/css/admin.css' );
cmp_assert( false !== strpos( $guide_css, '.cmp-guide-toc' ), 'guide: admin.css styles the table of contents' );

// with_links(): a translation asking for more arguments than given falls back to escaped text.
cmp_assert_eq( CMP_Guide::placeholder_count( 'a %1$sb%2$s c %3$s %%' ), 3, 'guide: placeholder_count reads the highest position' );
cmp_assert_eq( CMP_Guide::placeholder_count( '%s and %s' ), 2, 'guide: placeholder_count counts unnumbered placeholders' );
cmp_assert_eq( CMP_Guide::with_links( 'Go %1$shere%2$s', 'cmp-check' ), 'Go <a href="http://example.test/wp-admin/admin.php?page=cmp-check">here</a>', 'guide: with_links fills one link' );
cmp_assert_eq( CMP_Guide::with_links( 'Bad %1$sx%2$s %3$s <b>', 'cmp-check' ), 'Bad %1$sx%2$s %3$s &lt;b&gt;', 'guide: too many placeholders -> escaped text, no error' );

// Guide facts fixed in the lot review.
cmp_assert( false !== strpos( $guide_html, 'Global Privacy Control' ), 'guide: GPC explained' );
cmp_assert( false !== strpos( $guide_html, 'Permite tot' ) && false === strpos( $guide_html, 'Acceptă tot în banner' ), 'guide: banner accept button named by its default label' );
cmp_assert( false !== strpos( $guide_html, '[cmp_privacy_link]' ) && false !== strpos( $guide_html, '[cmp_terms_link]' ), 'guide: privacy/terms shortcodes mentioned' );
