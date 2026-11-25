<?php
/**
 * Lot A Task 5: export and import of the banner appearance (CMP_Appearance_IO).
 *  - export: the saved appearance, no exclusions, no other setting, nothing about the site;
 *  - parse(): size, UTF-8, depth, object, exact format, integer version;
 *  - merge(): through CMP_Sanitizer::aspect(); missing keys keep the current value,
 *    unknown keys are counted, other settings never change, WPML / Polylang skips texts;
 *  - the Aspect screen: the form opens with the imported values, unsaved; the
 *    request is checked (capability, nonce) before the file is read; nothing is written.
 * The WordPress-wired request runs in its own process (tests/fixtures/appearance-io-request.php).
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once __DIR__ . '/../includes/class-contrast.php';
require_once __DIR__ . '/../includes/class-appearance.php';
require_once __DIR__ . '/../includes/class-sanitizer.php';
require_once __DIR__ . '/../includes/modules/class-preview.php';
require_once __DIR__ . '/../includes/admin/class-appearance-page.php';
$io_file = __DIR__ . '/../includes/class-appearance-io.php';
cmp_assert( is_file( $io_file ), 'io: class-appearance-io.php exists' );
if ( ! is_file( $io_file ) ) {
	return;
}
require_once $io_file;

cmp_assert_eq( CMP_Appearance_IO::FORMAT, 'biscuitwp-appearance', 'io: format name' );
cmp_assert_eq( CMP_Appearance_IO::VERSION, 1, 'io: file version 1' );
cmp_assert_eq( CMP_Appearance_IO::MAX_BYTES, 262144, 'io: 256 KB' );

// A site with a customised appearance, other settings, and data that must not leave the site.
$io_src = array(
	'mode'            => 'pro',
	'block_scripts'   => true,
	'measurement'     => 'advanced',
	'policy_version'  => '7',
	'reopen_button'   => true,
	'preset'          => 'egov',
	'legal_meta'      => array( 'company' => 'ACME SRL', 'contact_email' => 'dpo@acme.test' ),
	'privacy_page_id' => 12,
	'texts'           => array(),
	'appearance'      => array_merge( CMP_Appearance::defaults( 'friendly' ), array(
		'palette'       => 'egov',
		'accent'        => '#0a5c36',
		'theme'         => 'site',
		'dark_selector' => '.night',
		'format'        => 'card',
		'position'      => 'top-left',
		'dim'           => 33,
		'radius'        => 9,
		'delay'         => 1.5,
		'reopen'        => 'both',
		'theme_colors'  => false,
		'exclude'       => array( '/pagina-secreta/', '/checkout/*' ),
		'custom_css'    => '.cmp{--cmp-radius:6px}',
		'texts'         => array_merge( CMP_Appearance::defaults( 'brand' )['texts'], array(
			'ro' => array_merge( array_fill_keys( CMP_Appearance::TEXT_KEYS, '' ), array( 'title' => 'Cookie-uri ă', 'accept' => 'Da' ) ),
			'ru' => array_merge( array_fill_keys( CMP_Appearance::TEXT_KEYS, '' ), array( 'title' => 'Куки' ) ),
		) ),
	) ),
);
$io_norm = CMP_Appearance::from_settings( $io_src );

// ---------------------------------------------------------------------------
// export()
// ---------------------------------------------------------------------------
$io_exp = CMP_Appearance_IO::export( $io_src, '1.1.1' );
cmp_assert_eq( array_keys( $io_exp ), array( 'format', 'version', 'plugin', 'appearance' ), 'export: only format, version, plugin, appearance at the top' );
cmp_assert_eq( $io_exp['format'], 'biscuitwp-appearance', 'export: format' );
cmp_assert_eq( $io_exp['version'], 1, 'export: version' );
cmp_assert( is_string( $io_exp['plugin'] ) && false !== strpos( $io_exp['plugin'], '1.1.1' ), 'export: plugin and its version' );
cmp_assert( ! array_key_exists( 'exclude', $io_exp['appearance'] ), 'export: exclusions (they belong to the site) are never exported' );
$io_want = $io_norm;
unset( $io_want['exclude'] );
cmp_assert_eq( $io_exp['appearance'], $io_want, 'export: the normalized saved appearance, texts included' );
cmp_assert_eq( $io_exp['appearance']['dark_selector'], '.night', 'export: dark_selector exported' );
cmp_assert_eq( $io_exp['appearance']['custom_css'], '.cmp{--cmp-radius:6px}', 'export: custom_css exported' );
$io_defaults = array_keys( CMP_Appearance::defaults( 'brand' ) );
cmp_assert_eq( array_values( array_diff( $io_defaults, array_keys( $io_exp['appearance'] ) ) ), array( 'exclude' ), 'export: every appearance key but exclude is there (no other key carries site data)' );
$io_json = json_encode( $io_exp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
foreach ( array( 'pagina-secreta', 'checkout', 'dpo@acme.test', 'ACME', 'example', 'http', '"12"', 'privacy_page_id', 'legal_meta', 'block_scripts' ) as $io_leak ) {
	cmp_assert( false === strpos( $io_json, $io_leak ), "export: nothing about the site ($io_leak)" );
}
// The old shape (no 'appearance' yet) exports what the banner shows.
$io_legacy = CMP_Appearance_IO::export( array( 'preset' => 'warm', 'position' => 'top-left', 'reopen_button' => false ), '1.1.1' );
cmp_assert_eq( $io_legacy['appearance']['palette'], 'warm', 'export: a legacy site exports its migrated appearance' );
// The reopen button off in the old key = nothing, whatever the stored shape.
$io_off = CMP_Appearance_IO::export( array_merge( $io_src, array( 'reopen_button' => false ) ), '1.1.1' );
cmp_assert_eq( $io_off['appearance']['reopen'], 'none', 'export: reopen_button off exports "none" (what the banner does)' );

// ---------------------------------------------------------------------------
// parse()
// ---------------------------------------------------------------------------
$io_ok = json_encode( $io_exp );
$io_p  = CMP_Appearance_IO::parse( $io_ok );
cmp_assert_eq( $io_p['error'], '', 'parse: an exported file is accepted' );
cmp_assert_eq( $io_p['data']['appearance']['palette'], 'egov', 'parse: data is the decoded file' );
$io_pretty = CMP_Appearance_IO::parse( json_encode( $io_exp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
cmp_assert_eq( $io_pretty['error'], '', 'parse: the pretty-printed export (as downloaded) is accepted' );
cmp_assert_eq( CMP_Appearance_IO::parse( "\xEF\xBB\xBF" . $io_ok )['error'], '', 'parse: a UTF-8 BOM (some editors) is tolerated' );
$io_err = function ( $raw ) {
	$r = CMP_Appearance_IO::parse( $raw );
	return $r['error'];
};
cmp_assert_eq( $io_err( str_repeat( ' ', CMP_Appearance_IO::MAX_BYTES + 1 ) ), 'size', 'parse: one byte over 256 KB -> size (checked first)' );
cmp_assert_eq( $io_err( '{"format":"biscuitwp-appearance","version":1,"appearance":{"custom_css":"' . str_repeat( 'a', 400000 ) . '"}}' ), 'size', 'parse: a big file -> size' );
$io_at = '{"format":"biscuitwp-appearance","version":1,"appearance":{"custom_css":"';
$io_at = $io_at . str_repeat( 'a', CMP_Appearance_IO::MAX_BYTES - strlen( $io_at ) - 3 ) . '"}}';
cmp_assert_eq( strlen( $io_at ), CMP_Appearance_IO::MAX_BYTES, 'parse: (fixture is exactly 256 KB)' );
cmp_assert_eq( $io_err( $io_at ), '', 'parse: exactly 256 KB is accepted' );
cmp_assert_eq( $io_err( '' ), 'json', 'parse: empty -> json' );
cmp_assert_eq( $io_err( '{"format":' ), 'json', 'parse: broken JSON -> json' );
cmp_assert_eq( $io_err( "{\"format\":\"biscuitwp-appearance\",\"version\":1,\"appearance\":{\"custom_css\":\"\xff\xfe\"}}" ), 'json', 'parse: invalid UTF-8 -> json' );
$io_deep = '{"format":"biscuitwp-appearance","version":1,"appearance":' . str_repeat( '[', 12 ) . str_repeat( ']', 12 ) . '}';
cmp_assert_eq( $io_err( $io_deep ), 'json', 'parse: nesting deeper than 8 -> json' );
$io_deep7 = '{"format":"biscuitwp-appearance","version":1,"appearance":{"texts":{"ro":{"title":"x"}},"x":[[1]]}}';
cmp_assert_eq( $io_err( $io_deep7 ), '', 'parse: normal nesting is fine' );
foreach ( array( '[]', '[1,2]', '"text"', '5', 'null', 'true' ) as $io_not_obj ) {
	cmp_assert_eq( $io_err( $io_not_obj ), 'format', "parse: $io_not_obj is not an object -> format" );
}
cmp_assert_eq( $io_err( '{}' ), 'format', 'parse: an empty object has no format -> format' );
cmp_assert_eq( $io_err( '{"format":"other","version":1,"appearance":{}}' ), 'format', 'parse: another format -> format' );
cmp_assert_eq( $io_err( '{"format":"BiscuitWP-Appearance","version":1,"appearance":{}}' ), 'format', 'parse: the format is exact (case)' );
cmp_assert_eq( $io_err( '{"version":1,"appearance":{}}' ), 'format', 'parse: no format -> format' );
cmp_assert_eq( $io_err( '{"format":"biscuitwp-appearance","appearance":{}}' ), 'format', 'parse: no version -> format' );
cmp_assert_eq( $io_err( '{"format":"biscuitwp-appearance","version":2,"appearance":{}}' ), 'version', 'parse: newer version -> version' );
cmp_assert_eq( $io_err( '{"format":"biscuitwp-appearance","version":99,"appearance":{}}' ), 'version', 'parse: much newer version -> version' );
cmp_assert_eq( $io_err( '{"format":"biscuitwp-appearance","version":0,"appearance":{}}' ), 'format', 'parse: version 0 -> format' );
cmp_assert_eq( $io_err( '{"format":"biscuitwp-appearance","version":-1,"appearance":{}}' ), 'format', 'parse: negative version -> format' );
cmp_assert_eq( $io_err( '{"format":"biscuitwp-appearance","version":"1","appearance":{}}' ), 'format', 'parse: version as text -> format' );
cmp_assert_eq( $io_err( '{"format":"biscuitwp-appearance","version":1.0,"appearance":{}}' ), 'format', 'parse: version as a float -> format' );
cmp_assert_eq( $io_err( '{"format":"biscuitwp-appearance","version":1,"appearance":"x"}' ), 'format', 'parse: appearance that is not an object -> format' );
cmp_assert_eq( $io_err( '{"format":"biscuitwp-appearance","version":1}' ), 'format', 'parse: no appearance -> format' );
$io_bad = CMP_Appearance_IO::parse( '{"format":"x"}' );
cmp_assert_eq( $io_bad['data'], array(), 'parse: on an error the data is empty' );

// ---------------------------------------------------------------------------
// merge()
// ---------------------------------------------------------------------------
$io_cur = array(
	'mode'            => 'pro',
	'block_scripts'   => true,
	'measurement'     => 'advanced',
	'policy_version'  => '9',
	'reopen_button'   => false,
	'preset'          => 'neutral',
	'legal_meta'      => array( 'company' => 'Alt SRL' ),
	'privacy_page_id' => 44,
	'services'        => array( 'ga4' => true ),
	'texts'           => array(),
	'appearance'      => array_merge( CMP_Appearance::defaults( 'brand' ), array( 'exclude' => array( '/doar-aici/' ), 'radius' => 3 ) ),
);
// Export then merge over another site: the appearance is the source's, the exclusions the target's.
$io_m = CMP_Appearance_IO::merge( CMP_Appearance_IO::parse( $io_ok )['data'], $io_cur, false );
$io_got = $io_m['settings']['appearance'];
cmp_assert_eq( $io_got['exclude'], array( '/doar-aici/' ), 'merge: the exclusions of this site are never changed' );
$io_a = $io_got;
$io_b = $io_norm;
unset( $io_a['exclude'], $io_b['exclude'] );
cmp_assert_eq( $io_a, $io_b, 'merge: export then merge = the source appearance (except exclude)' );
cmp_assert_eq( $io_m['ignored'], 0, 'merge: nothing ignored on a clean file' );
cmp_assert_eq( $io_m['texts_skipped'], 0, 'merge: no texts skipped without WPML' );
cmp_assert_eq( $io_m['removed_css'], array(), 'merge: nothing removed from a clean CSS' );
cmp_assert_eq( $io_m['settings']['preset'], 'egov', 'merge: preset follows the palette (as aspect() does)' );
cmp_assert_eq( $io_m['settings']['reopen_button'], true, 'merge: reopen_button follows reopen (as aspect() does)' );
// Same result as a normal save of the same values.
$io_save = CMP_Sanitizer::aspect( array( 'appearance' => $io_exp['appearance'] ), $io_cur, false );
cmp_assert_eq( $io_m['settings'], $io_save, 'merge: identical to what the Aspect save stores for the same values' );
// Everything outside the keys an Aspect save changes is untouched.
foreach ( array( $io_m['settings'] ) as $io_s ) {
	$io_outside = array_diff_key( $io_s, array_flip( CMP_Preview::KEYS ) );
	$io_before  = array_diff_key( $io_cur, array_flip( CMP_Preview::KEYS ) );
	cmp_assert_eq( $io_outside, $io_before, 'merge: every setting outside appearance / preset / reopen_button / texts is identical' );
}

// Missing keys keep the current value.
$io_only = CMP_Appearance_IO::merge( array( 'appearance' => array( 'palette' => 'warm' ) ), $io_cur, false );
$io_exp_cur = CMP_Appearance::from_settings( $io_cur );
$io_exp_cur['palette'] = 'warm';
cmp_assert_eq( $io_only['settings']['appearance'], $io_exp_cur, 'merge: keys missing from the file keep the current value' );
cmp_assert_eq( $io_only['settings']['appearance']['radius'], 3, 'merge: an adjusted radius stays when the file has none' );
$io_empty = CMP_Appearance_IO::merge( array( 'appearance' => array() ), $io_cur, false );
cmp_assert_eq( $io_empty['settings']['appearance'], CMP_Appearance::from_settings( $io_cur ), 'merge: an empty appearance changes nothing' );
// A new style in the file, without the shape keys: the current ones stay.
$io_style = CMP_Appearance_IO::merge( array( 'appearance' => array( 'style' => 'minimal' ) ), $io_cur, false );
cmp_assert_eq( $io_style['settings']['appearance']['style'], 'minimal', 'merge: style imported' );
cmp_assert_eq( $io_style['settings']['appearance']['radius'], 3, 'merge: a style switch does not reload keys the file does not carry' );

// Unknown keys are counted and not stored.
$io_unk = CMP_Appearance_IO::merge( array(
	'mode'       => 'light',
	'operator'   => array( 'x' ),
	'appearance' => array(
		'palette'   => 'warm',
		'mode'      => 'light',
		'services'  => array( 'ga4' => false ),
		'block_scripts' => false,
		'evil'      => '<script>',
		'texts'     => array( 'ro' => array( 'title' => 'Salut', 'nu_exista' => 'x' ), 'fr' => array( 'title' => 'Bonjour' ) ),
	),
), $io_cur, false );
cmp_assert_eq( $io_unk['ignored'], 6, 'merge: unknown keys counted (4 appearance keys, 1 text key, 1 language)' );
cmp_assert_eq( $io_unk['settings']['mode'], 'pro', 'merge: mode never changes' );
cmp_assert_eq( $io_unk['settings']['services'], array( 'ga4' => true ), 'merge: services never change' );
cmp_assert_eq( $io_unk['settings']['block_scripts'], true, 'merge: block_scripts never changes' );
cmp_assert( ! isset( $io_unk['settings']['operator'] ) && ! isset( $io_unk['settings']['appearance']['evil'] ) && ! isset( $io_unk['settings']['appearance']['mode'] ), 'merge: unknown keys not stored anywhere' );
cmp_assert_eq( $io_unk['settings']['appearance']['texts']['ro']['title'], 'Salut', 'merge: the known text is imported next to unknown ones' );
cmp_assert_eq( array_diff_key( $io_unk['settings'], array_flip( CMP_Preview::KEYS ) ), array_diff_key( $io_cur, array_flip( CMP_Preview::KEYS ) ), 'merge: hostile top-level and appearance keys leave the other settings identical' );
// The exclusions in a hand-made file are not imported (and are counted).
$io_ex = CMP_Appearance_IO::merge( array( 'appearance' => array( 'exclude' => array( '/hack/' ) ) ), $io_cur, false );
cmp_assert_eq( $io_ex['settings']['appearance']['exclude'], array( '/doar-aici/' ), 'merge: an "exclude" in the file is not imported' );
cmp_assert_eq( $io_ex['ignored'], 1, 'merge: ... and counted as ignored' );

// Wrong types fall back to the current value, as the sanitizer does.
$io_wt = CMP_Appearance_IO::merge( array( 'appearance' => array(
	'palette' => 5, 'theme' => array( 'x' ), 'radius' => 'mare', 'blur' => array(), 'accent' => 'rosu', 'custom_css' => array( 'a' ), 'texts' => 'text', 'format' => null,
) ), $io_cur, false );
$io_keep = CMP_Appearance::from_settings( $io_cur );
foreach ( array( 'palette', 'theme', 'radius', 'blur', 'accent', 'custom_css', 'texts', 'format' ) as $io_k ) {
	cmp_assert_eq( $io_wt['settings']['appearance'][ $io_k ], $io_keep[ $io_k ], "merge: wrong type for $io_k keeps the current value" );
}
$io_range = CMP_Appearance_IO::merge( array( 'appearance' => array( 'radius' => 9999, 'maxw' => -5, 'dim' => 500, 'delay' => 99 ) ), $io_cur, false );
cmp_assert_eq( $io_range['settings']['appearance']['radius'], 24, 'merge: numbers are clamped (radius)' );
cmp_assert_eq( $io_range['settings']['appearance']['maxw'], 360, 'merge: numbers are clamped (maxw)' );
cmp_assert_eq( $io_range['settings']['appearance']['dim'], 80, 'merge: numbers are clamped (dim)' );

// Hostile CSS and texts come out as a normal save leaves them.
$io_css  = '.cmp__btn--primary{display:none}[data-cmp-action="reject"]{opacity:0}@import url(http://evil.test/x.css);.a{background:url(http://evil.test/p.png)}</style><script>alert(1)</script>.ok{color:red}';
$io_host = array( 'appearance' => array( 'custom_css' => $io_css, 'texts' => array( 'ro' => array( 'title' => '<b>Salut</b><script>x()</script>', 'accept' => 'Da <img src=x onerror=1>' ) ) ) );
$io_h    = CMP_Appearance_IO::merge( $io_host, $io_cur, false );
$io_hn   = CMP_Sanitizer::aspect( $io_host, $io_cur, false );
cmp_assert_eq( $io_h['settings']['appearance']['custom_css'], $io_hn['appearance']['custom_css'], 'merge: custom_css cleaned exactly as the save cleans it' );
cmp_assert_eq( $io_h['settings']['appearance']['texts'], $io_hn['appearance']['texts'], 'merge: texts cleaned exactly as the save cleans them' );
$io_c = $io_h['settings']['appearance']['custom_css'];
cmp_assert( false === strpos( $io_c, 'cmp__btn--primary' ) && false === strpos( $io_c, 'reject' ) && false === strpos( $io_c, '@import' ) && false === strpos( $io_c, 'evil.test' ) && false === strpos( $io_c, '<' ), 'merge: hostile CSS (hiding "Refuz", @import, remote url, </style>) is gone' );
cmp_assert( false !== strpos( $io_c, '.ok{color:red}' ), 'merge: the harmless rule stays' );
cmp_assert( count( $io_h['removed_css'] ) >= 4, 'merge: the removed parts are reported' );
cmp_assert( false === strpos( json_encode( $io_h['settings']['appearance']['texts'] ), '<' ), 'merge: no HTML left in the texts' );
$io_big = CMP_Appearance_IO::merge( array( 'appearance' => array( 'custom_css' => str_repeat( '.a{color:red}', 3000 ) ) ), $io_cur, false );
cmp_assert_eq( $io_big['settings']['appearance']['custom_css'], CMP_Sanitizer::aspect( array( 'appearance' => array( 'custom_css' => str_repeat( '.a{color:red}', 3000 ) ) ), $io_cur, false )['appearance']['custom_css'], 'merge: custom_css is capped exactly like the save caps it' );
cmp_assert( strlen( $io_big['settings']['appearance']['custom_css'] ) < 20100, 'merge: ... (about 20000 characters)' );

// WPML / Polylang: the texts of the file are skipped and reported.
$io_ml_cur = array_merge( $io_cur, array( 'texts' => array( 'title' => 'Titlu WPML' ) ) );
$io_ml     = CMP_Appearance_IO::merge( CMP_Appearance_IO::parse( $io_ok )['data'], $io_ml_cur, true );
cmp_assert( $io_ml['texts_skipped'] > 0, 'merge (WPML): the texts of the file are skipped and counted' );
cmp_assert_eq( $io_ml['settings']['texts'], array( 'title' => 'Titlu WPML' ), 'merge (WPML): the single texts stay' );
cmp_assert_eq( $io_ml['settings']['appearance']['texts'], CMP_Appearance::from_settings( $io_ml_cur )['texts'], 'merge (WPML): the per-language texts do not change' );
cmp_assert_eq( $io_ml['settings']['appearance']['palette'], 'egov', 'merge (WPML): the rest is imported' );
cmp_assert_eq( $io_ml['ignored'], 0, 'merge (WPML): skipped texts are not "ignored keys"' );
$io_ml0 = CMP_Appearance_IO::merge( array( 'appearance' => array( 'palette' => 'warm' ) ), $io_ml_cur, true );
cmp_assert_eq( $io_ml0['texts_skipped'], 0, 'merge (WPML): no texts in the file = nothing skipped' );

// Light: the fields that do not apply keep the stored values (they are not posted).
$io_light = array_merge( $io_cur, array( 'mode' => 'light', 'appearance' => array_merge( $io_cur['appearance'], array( 'format' => 'bar', 'position' => 'top', 'buttons' => 'text', 'reopen' => 'none' ) ) ) );
$io_lm    = CMP_Appearance_IO::merge( array( 'appearance' => array(
	'palette' => 'warm', 'format' => 'card', 'position' => 'top-left', 'buttons' => 'outline', 'reopen' => 'icon', 'maxw' => 900, 'backdrop' => 'none',
	'texts' => array( 'ro' => array( 'light_intro' => 'Folosim cookie-uri', 'reject' => 'Refuz tot' ) ),
) ), $io_light, false );
$io_la = $io_lm['settings']['appearance'];
cmp_assert_eq( $io_la['palette'], 'warm', 'merge (Light): a field that applies is imported' );
cmp_assert_eq( array( $io_la['format'], $io_la['position'], $io_la['buttons'], $io_la['reopen'] ), array( 'bar', 'top', 'text', 'none' ), 'merge (Light): format, position, buttons, reopen keep the stored values' );
cmp_assert_eq( array( $io_la['maxw'], $io_la['backdrop'] ), array( 720, 'blur' ), 'merge (Light): maxw and backdrop keep the stored values' );
cmp_assert_eq( $io_la['texts']['ro']['light_intro'], 'Folosim cookie-uri', 'merge (Light): the Light text is imported' );
cmp_assert_eq( $io_la['texts']['ro']['reject'], '', 'merge (Light): a Pro-only text keeps the stored value' );
cmp_assert( $io_lm['light_skipped'] >= 6, 'merge (Light): what was not applied is counted' );
cmp_assert_eq( $io_lm['settings']['reopen_button'], false, 'merge (Light): reopen_button not touched' );
cmp_assert_eq( $io_lm['ignored'], 0, 'merge (Light): not applied is not "unknown"' );
// M6: Light + another style: the form must show what "Salvează" stores. The save posts no field Light
// disables, and a new style reloads its own values for them.
foreach ( CMP_Appearance::STYLES as $io_st ) {
	$io_lst  = CMP_Appearance_IO::merge( array( 'appearance' => array( 'style' => $io_st, 'radius' => 9, 'maxw' => 500, 'buttons' => 'text' ) ), $io_light, false );
	$io_shown = $io_lst['settings']['appearance'];
	$io_saved = CMP_Sanitizer::aspect( array( 'appearance' => array_diff_key( $io_shown, array_flip( CMP_Appearance_Page::LIGHT_OFF ) ) ), $io_light, false );
	cmp_assert_eq( $io_saved['appearance'], $io_shown, "merge (Light, style $io_st): the form shows what the save stores" );
}
$io_lnk = CMP_Appearance_IO::merge( array( 'appearance' => array( 'style' => 'minimal' ) ), $io_light, false );
cmp_assert_eq( $io_lnk['settings']['appearance']['radius'], $io_light['appearance']['radius'], 'merge (Light): a key Light shows and the file lacks keeps the current value' );
// Pro: the Light text is not shown on the form, so it keeps the stored value.
$io_pro = CMP_Appearance_IO::merge( array( 'appearance' => array( 'texts' => array( 'ro' => array( 'light_intro' => 'x', 'accept' => 'Ok' ) ) ) ), $io_cur, false );
cmp_assert_eq( $io_pro['settings']['appearance']['texts']['ro']['light_intro'], '', 'merge (Pro): the Light-only text keeps the stored value' );
cmp_assert_eq( $io_pro['settings']['appearance']['texts']['ro']['accept'], 'Ok', 'merge (Pro): the shared text is imported' );

// reopen / preset in sync, as aspect() does.
$io_r1 = CMP_Appearance_IO::merge( array( 'appearance' => array( 'reopen' => 'text', 'palette' => 'dark' ) ), $io_cur, false );
cmp_assert_eq( array( $io_r1['settings']['reopen_button'], $io_r1['settings']['preset'] ), array( true, 'dark' ), 'merge: reopen text -> reopen_button on, preset = palette' );
$io_r2 = CMP_Appearance_IO::merge( array( 'appearance' => array( 'reopen' => 'none' ) ), array_merge( $io_cur, array( 'reopen_button' => true ) ), false );
cmp_assert_eq( $io_r2['settings']['reopen_button'], false, 'merge: reopen none -> reopen_button off' );
$io_r3 = CMP_Appearance_IO::merge( array( 'appearance' => array( 'palette' => 'warm' ) ), array_merge( $io_cur, array( 'reopen_button' => true ) ), false );
cmp_assert_eq( $io_r3['settings']['reopen_button'], true, 'merge: no reopen in the file -> reopen_button as it was' );

// ---------------------------------------------------------------------------
// The upload (read_upload) and the answer (response, filename)
// ---------------------------------------------------------------------------
$io_tmp = tempnam( sys_get_temp_dir(), 'cmpio' );
file_put_contents( $io_tmp, $io_ok );
$io_yes = function () {
	return true;
};
$io_no = function () {
	return false;
};
$io_up = CMP_Appearance_IO::read_upload( array( 'name' => '../../evil.php', 'tmp_name' => $io_tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen( $io_ok ) ), $io_yes );
cmp_assert_eq( array( $io_up['error'], $io_up['json'] ), array( '', $io_ok ), 'upload: a valid upload is read (the client file name is not used)' );
cmp_assert_eq( CMP_Appearance_IO::read_upload( array(), $io_yes )['error'], 'none', 'upload: no file -> none' );
cmp_assert_eq( CMP_Appearance_IO::read_upload( null, $io_yes )['error'], 'none', 'upload: no field -> none' );
cmp_assert_eq( CMP_Appearance_IO::read_upload( array( 'tmp_name' => $io_tmp, 'error' => UPLOAD_ERR_NO_FILE ), $io_yes )['error'], 'none', 'upload: UPLOAD_ERR_NO_FILE -> none' );
cmp_assert_eq( CMP_Appearance_IO::read_upload( array( 'tmp_name' => $io_tmp, 'error' => UPLOAD_ERR_PARTIAL ), $io_yes )['error'], 'partial', 'upload: partial -> partial' );
cmp_assert_eq( CMP_Appearance_IO::read_upload( array( 'tmp_name' => $io_tmp, 'error' => UPLOAD_ERR_INI_SIZE ), $io_yes )['error'], 'size', 'upload: over the PHP limit -> size' );
cmp_assert_eq( CMP_Appearance_IO::read_upload( array( 'tmp_name' => $io_tmp, 'error' => UPLOAD_ERR_FORM_SIZE ), $io_yes )['error'], 'size', 'upload: over the form limit -> size' );
cmp_assert_eq( CMP_Appearance_IO::read_upload( array( 'tmp_name' => $io_tmp, 'error' => UPLOAD_ERR_NO_TMP_DIR ), $io_yes )['error'], 'upload', 'upload: no temp dir -> upload' );
cmp_assert_eq( CMP_Appearance_IO::read_upload( array( 'tmp_name' => $io_tmp, 'error' => UPLOAD_ERR_OK ), $io_no )['error'], 'upload', 'upload: not an uploaded file (is_uploaded_file) -> upload' );
cmp_assert_eq( CMP_Appearance_IO::read_upload( array( 'tmp_name' => $io_tmp . '-nu-exista', 'error' => UPLOAD_ERR_OK ), $io_yes )['error'], 'upload', 'upload: unreadable file -> upload' );
cmp_assert_eq( CMP_Appearance_IO::read_upload( array( 'tmp_name' => array( 'x' ), 'error' => UPLOAD_ERR_OK ), $io_yes )['error'], 'upload', 'upload: a tampered tmp_name -> upload' );
cmp_assert_eq( CMP_Appearance_IO::read_upload( array( 'tmp_name' => $io_tmp, 'error' => array( 0 ) ), $io_yes )['error'], 'upload', 'upload: a tampered error -> upload' );
$io_bigf = tempnam( sys_get_temp_dir(), 'cmpio' );
file_put_contents( $io_bigf, str_repeat( 'a', 3 * 1024 * 1024 ) );
$io_bu = CMP_Appearance_IO::read_upload( array( 'tmp_name' => $io_bigf, 'error' => UPLOAD_ERR_OK, 'size' => 3 * 1024 * 1024 ), $io_yes );
cmp_assert_eq( $io_bu['error'], 'size', 'upload: a 3 MB file -> size' );
cmp_assert( strlen( $io_bu['json'] ) <= CMP_Appearance_IO::MAX_BYTES + 1, 'upload: at most MAX_BYTES + 1 bytes are read' );
// The file is neither moved nor copied nor deleted by the plugin.
cmp_assert( is_file( $io_tmp ) && is_file( $io_bigf ), 'upload: the temp file is left to PHP (no move, no copy)' );
@unlink( $io_tmp );
@unlink( $io_bigf );

cmp_assert_eq( CMP_Appearance_IO::filename( 'plataegala.md', '2026-11-25' ), 'biscuitwp-aspect-plataegala.md-2026-11-25.json', 'filename: host and date' );
cmp_assert_eq( CMP_Appearance_IO::filename( 'Sub.Example.MD:8888', '2026-11-25' ), 'biscuitwp-aspect-sub.example.md-8888-2026-11-25.json', 'filename: lowercase, port made safe' );
cmp_assert_eq( CMP_Appearance_IO::filename( "a/../b\"\r\n;c d", '2026-11-25' ), 'biscuitwp-aspect-a-.-b-c-d-2026-11-25.json', 'filename: no slash, quote, CR/LF, ; or ".." (sanitized host)' );
cmp_assert_eq( CMP_Appearance_IO::filename( '', '2026-11-25' ), 'biscuitwp-aspect-site-2026-11-25.json', 'filename: no host -> site' );
cmp_assert_eq( CMP_Appearance_IO::filename( null, '../x' ), 'biscuitwp-aspect-site-0000-00-00.json', 'filename: a bad date is replaced' );
$io_r = CMP_Appearance_IO::response( $io_src, '1.1.1', 'plataegala.md', '2026-11-25' );
cmp_assert_eq( $io_r['filename'], 'biscuitwp-aspect-plataegala.md-2026-11-25.json', 'response: file name' );
cmp_assert( in_array( 'Content-Type: application/json; charset=utf-8', $io_r['headers'], true ), 'response: JSON content type' );
cmp_assert( in_array( 'Content-Disposition: attachment; filename="biscuitwp-aspect-plataegala.md-2026-11-25.json"', $io_r['headers'], true ), 'response: download with the file name' );
cmp_assert( in_array( 'X-Content-Type-Options: nosniff', $io_r['headers'], true ), 'response: nosniff' );
cmp_assert( false !== strpos( $io_r['body'], 'Cookie-uri ă' ) && false !== strpos( $io_r['body'], 'Куки' ), 'response: Unicode written as is' );
cmp_assert( false !== strpos( $io_r['body'], "\n    \"" ), 'response: pretty printed' );
cmp_assert_eq( CMP_Appearance_IO::parse( $io_r['body'] )['error'], '', 'response: the body is a file the import accepts' );

// ---------------------------------------------------------------------------
// Static guarantees on the sources.
// ---------------------------------------------------------------------------
$io_code = (string) file_get_contents( $io_file );
cmp_assert( false === stripos( $io_code, 'unserialize' ), 'source: never unserialize' );
cmp_assert( false === strpos( $io_code, "['name']" ) && false === strpos( $io_code, '["name"]' ), 'source: the uploaded file name is never read' );
cmp_assert( false === strpos( $io_code, 'move_uploaded_file' ) && false === strpos( $io_code, 'copy(' ) && false === strpos( $io_code, 'update_option' ) && false === strpos( $io_code, 'set_transient' ), 'source: no move, copy or write' );
cmp_assert( false === strpos( $io_code, "\u{2014}" ), 'source: no em dash' );

// ---------------------------------------------------------------------------
// The screen: the request is checked before the file is read.
// ---------------------------------------------------------------------------
$io_reads = 0;
$io_spy   = function () use ( &$io_reads ) {
	$io_reads++;
	return true;
};
$io_tmp2 = tempnam( sys_get_temp_dir(), 'cmpio' );
file_put_contents( $io_tmp2, $io_ok );
$io_files = array( CMP_Appearance_Page::IMPORT_FIELD => array( 'name' => 'x.json', 'tmp_name' => $io_tmp2, 'error' => UPLOAD_ERR_OK, 'size' => strlen( $io_ok ) ) );
$io_d1    = CMP_Appearance_Page::handle_import( false, true, $io_files, $io_cur, false, $io_spy );
cmp_assert_eq( $io_d1['status'], 'denied', 'import: without manage_options -> denied' );
$io_d2 = CMP_Appearance_Page::handle_import( true, false, $io_files, $io_cur, false, $io_spy );
cmp_assert_eq( $io_d2['status'], 'denied', 'import: without a valid nonce -> denied' );
cmp_assert_eq( $io_reads, 0, 'import: a denied request never touches the uploaded file' );
$io_okr = CMP_Appearance_Page::handle_import( true, true, $io_files, $io_cur, false, $io_spy );
cmp_assert_eq( $io_okr['status'], 'ok', 'import: allowed request -> ok' );
cmp_assert_eq( $io_okr['settings']['appearance']['palette'], 'egov', 'import: the settings carry the imported values' );
cmp_assert_eq( $io_reads, 1, 'import: is_uploaded_file is asked once' );
foreach ( array(
	'{"format":"x","version":1,"appearance":{}}'                                   => 'format',
	'{"format":"biscuitwp-appearance","version":3,"appearance":{}}'               => 'version',
	'nu e json'                                                                    => 'json',
) as $io_raw => $io_code_want ) {
	file_put_contents( $io_tmp2, $io_raw );
	$io_e = CMP_Appearance_Page::handle_import( true, true, $io_files, $io_cur, false, $io_yes );
	cmp_assert_eq( array( $io_e['status'], $io_e['error'], $io_e['settings'] ), array( 'error', $io_code_want, null ), "import: $io_code_want -> an error and no settings" );
}
@unlink( $io_tmp2 );
$io_none = CMP_Appearance_Page::handle_import( true, true, array(), $io_cur, false, $io_yes );
cmp_assert_eq( array( $io_none['status'], $io_none['error'] ), array( 'error', 'none' ), 'import: no file -> error none' );

// ---------------------------------------------------------------------------
// The WordPress-wired requests, each in its own process.
// ---------------------------------------------------------------------------
function cmp_io_request( array $in ) {
	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/appearance-io-request.php' ) . ' ' . escapeshellarg( json_encode( $in ) ) . ' 2>&1';
	$raw = shell_exec( $cmd );
	$out = json_decode( (string) $raw, true );
	if ( ! is_array( $out ) ) {
		echo "appearance-io-request output: {$raw}\n";
		return array();
	}
	return $out;
}
$io_in = array( 'cap' => true, 'nonce' => true, 'settings' => $io_cur, 'file' => $io_ok );

$io_get = cmp_io_request( array_merge( $io_in, array( 'scenario' => 'get' ) ) );
cmp_assert( false !== strpos( $io_get['html'], 'cmp_export_appearance' ), 'screen: the export button posts to admin-post (cmp_export_appearance)' );
cmp_assert( false !== strpos( $io_get['html'], 'cmp_export_appearance' ) && false !== strpos( $io_get['html'], 'name="_wpnonce"' ), 'screen: the export form has a nonce' );
cmp_assert( false !== strpos( $io_get['html'], 'enctype="multipart/form-data"' ) && false !== strpos( $io_get['html'], 'type="file"' ), 'screen: the import form uploads a file' );
cmp_assert( false !== strpos( $io_get['html'], 'admin.php?page=cmp-appearance' ), 'screen: the import posts to the Aspect screen itself' );
cmp_assert( false !== strpos( $io_get['html'], 'cmp_import_appearance' ), 'screen: the import form carries its marker' );
cmp_assert( false !== strpos( $io_get['html'], 'Exportă aspectul salvat' ) || false !== strpos( $io_get['html'], 'aspectul salvat' ), 'screen: the page says the export is the SAVED appearance' );
cmp_assert( false === strpos( $io_get['html'], 'cmp-ap-import-notice' ), 'screen: no import notice on a plain visit' );
cmp_assert( preg_match( '#<option value="neutral" selected#', $io_get['html'] ) === 1, 'screen: a plain visit shows the saved palette' );
cmp_assert( strpos( $io_get['html'], '<form' ) < strpos( $io_get['html'], 'enctype="multipart/form-data"' ) && 3 === substr_count( $io_get['html'], '<form' ) && strpos( $io_get['html'], 'cmp_save_appearance' ) < strpos( $io_get['html'], '</form>' ) && strpos( $io_get['html'], '</form>' ) < strpos( $io_get['html'], 'enctype="multipart/form-data"' ), 'screen: the main form stays first (admin-appearance.js reads the first form) and the forms are not nested' );
cmp_assert_eq( $io_get['writes'], array(), 'screen: a plain visit writes nothing' );

$io_post = cmp_io_request( array_merge( $io_in, array( 'scenario' => 'import' ) ) );
cmp_assert( preg_match( '#<option value="egov" selected#', $io_post['html'] ) === 1, 'screen: after an import the form shows the imported palette' );
cmp_assert( false !== strpos( $io_post['html'], 'value="#0a5c36"' ), 'screen: ... the imported accent' );
cmp_assert( false !== strpos( $io_post['html'], 'Cookie-uri ă' ), 'screen: ... the imported texts' );
cmp_assert( false !== strpos( $io_post['html'], 'cmp-ap-import-notice' ) && false !== strpos( $io_post['html'], 'nu e salvat' ), 'screen: a notice says it is not saved yet' );
cmp_assert( false !== strpos( $io_post['html'], '/doar-aici/' ) && false === strpos( $io_post['html'], '/pagina-secreta/' ), 'screen: the exclusions shown are this site\'s' );
cmp_assert_eq( $io_post['writes'], array(), 'screen: an import writes nothing (no update_option, no transient)' );
cmp_assert_eq( $io_post['uploaded_checks'], 1, 'screen: is_uploaded_file asked' );
cmp_assert( false !== strpos( $io_post['html'], 'data-cmp-ap-style-current="friendly"' ), 'screen: the style script starts from the imported style (no overwrite on load)' );
$io_after = cmp_io_request( array_merge( $io_in, array( 'scenario' => 'get' ) ) );
cmp_assert( preg_match( '#<option value="neutral" selected#', $io_after['html'] ) === 1, 'screen: a reload without resubmission shows the saved appearance again' );

$io_hostile = json_encode( array( 'format' => 'biscuitwp-appearance', 'version' => 1, 'appearance' => array(
	'custom_css' => '.cmp__btn--primary{display:none}@import url(http://evil.test/x.css);.ok{color:red}',
	'zzz' => 1,
	'texts' => array( 'ro' => array( 'title' => '<script>alert(1)</script>Salut' ) ),
) ) );
$io_hp = cmp_io_request( array_merge( $io_in, array( 'scenario' => 'import', 'file' => $io_hostile ) ) );
cmp_assert( false !== strpos( $io_hp['html'], 'cmp-ap-removed' ) && false !== strpos( $io_hp['html'], 'cmp__btn--primary' ), 'screen: the removed CSS is listed (existing notice)' );
preg_match( '#<div class="notice notice-warning cmp-ap-removed">.*?</div>#s', $io_hp['html'], $io_rm );
cmp_assert( isset( $io_rm[0] ) && false === strpos( $io_rm[0], 'Restul CSS-ului e salvat' ), 'screen: after an import the removed-CSS notice does not say the CSS was saved' );
cmp_assert( isset( $io_rm[0] ) && false !== strpos( $io_rm[0], 'nu e salvat încă' ), 'screen: ... it says the rest is not saved yet' );
preg_match( '#<textarea[^>]*id="cmp-ap-css"[^>]*>(.*?)</textarea>#s', $io_hp['html'], $io_ta );
cmp_assert( false === strpos( $io_hp['html'], '<script>alert' ), 'screen: hostile texts are escaped in the page' );
cmp_assert( isset( $io_ta[1] ) && false === strpos( $io_ta[1], 'evil.test' ) && false === strpos( $io_ta[1], 'cmp__btn--primary' ), 'screen: the CSS in the form is the cleaned one' );
cmp_assert( false !== strpos( $io_hp['html'], '.ok{color:red}' ), 'screen: the clean part of the CSS is in the form' );
cmp_assert( false !== strpos( $io_hp['html'], 'cmp-ap-import-ignored' ), 'screen: ignored keys are reported' );
cmp_assert_eq( $io_hp['writes'], array(), 'screen: a hostile import writes nothing either' );

$io_ml_s = cmp_io_request( array_merge( $io_in, array( 'scenario' => 'import', 'ml' => true, 'settings' => $io_ml_cur ) ) );
cmp_assert( false !== strpos( $io_ml_s['html'], 'cmp-ap-import-texts' ), 'screen (WPML): the notice says the texts were skipped' );
cmp_assert( false !== strpos( $io_ml_s['html'], 'Titlu WPML' ), 'screen (WPML): the single texts stay in the form' );

foreach ( array( 'format' => '{"format":"x"}', 'version' => '{"format":"biscuitwp-appearance","version":2,"appearance":{}}', 'json' => '{nu', 'size' => str_repeat( 'x', 300000 ) ) as $io_ec => $io_ef ) {
	$io_er = cmp_io_request( array_merge( $io_in, array( 'scenario' => 'import', 'file' => $io_ef ) ) );
	cmp_assert( false !== strpos( $io_er['html'], 'cmp-ap-import-error' ) && false === strpos( $io_er['html'], 'cmp-ap-import-notice' ), "screen: $io_ec -> an error and the saved form" );
	cmp_assert( preg_match( '#<option value="neutral" selected#', $io_er['html'] ) === 1, "screen: $io_ec -> the form keeps the saved values" );
	cmp_assert_eq( $io_er['writes'], array(), "screen: $io_ec writes nothing" );
}
$io_nf = cmp_io_request( array_merge( $io_in, array( 'scenario' => 'import', 'file' => null ) ) );
cmp_assert( false !== strpos( $io_nf['html'], 'cmp-ap-import-error' ), 'screen: no file chosen -> an error' );
$io_up_err = cmp_io_request( array_merge( $io_in, array( 'scenario' => 'import', 'upload_error' => UPLOAD_ERR_INI_SIZE ) ) );
cmp_assert( false !== strpos( $io_up_err['html'], 'cmp-ap-import-error' ) && false !== strpos( $io_up_err['html'], '256 KB' ), 'screen: over the PHP limit -> the error names 256 KB' );

// M8: over post_max_size PHP empties $_POST and $_FILES: the screen says why nothing happened.
$io_big = cmp_io_request( array_merge( $io_in, array( 'scenario' => 'toobig' ) ) );
cmp_assert( false !== strpos( $io_big['html'], 'cmp-ap-import-error' ) && false !== strpos( $io_big['html'], '256 KB' ), 'screen: over post_max_size (empty POST, CONTENT_LENGTH set) -> the size error' );
cmp_assert( preg_match( '#<option value="neutral" selected#', $io_big['html'] ) === 1 && array() === $io_big['writes'] && 0 === $io_big['uploaded_checks'], 'screen: ... the saved form, nothing read or written' );
$io_big0 = cmp_io_request( array_merge( $io_in, array( 'scenario' => 'emptypost' ) ) );
cmp_assert( false === strpos( $io_big0['html'], 'cmp-ap-import-error' ), 'screen: an empty POST with no length is a plain visit' );
// M7: the export text does not promise the texts WPML / Polylang hold.
cmp_assert( 1 === preg_match( '#cmp-ap-export.*?(Cu WPML sau Polylang[^<]*nu intră în fișier)#s', $io_get['html'] ), 'screen: the export text says the WPML / Polylang texts are not in the file' );

$io_nonce = cmp_io_request( array_merge( $io_in, array( 'scenario' => 'import', 'nonce' => false ) ) );
cmp_assert( ! empty( $io_nonce['died'] ), 'screen: a bad nonce -> wp_die' );
cmp_assert_eq( $io_nonce['uploaded_checks'], 0, 'screen: a bad nonce -> the file is never examined' );
cmp_assert_eq( $io_nonce['writes'], array(), 'screen: a bad nonce -> nothing written' );
$io_cap = cmp_io_request( array_merge( $io_in, array( 'scenario' => 'import', 'cap' => false ) ) );
cmp_assert_eq( trim( $io_cap['html'] ), '', 'screen: without manage_options the screen is empty' );
cmp_assert_eq( $io_cap['uploaded_checks'], 0, 'screen: without manage_options -> the file is never examined' );
cmp_assert_eq( $io_cap['writes'], array(), 'screen: without manage_options -> nothing written' );

// The export action.
$io_x = cmp_io_request( array_merge( $io_in, array( 'scenario' => 'export' ) ) );
cmp_assert( empty( $io_x['died'] ) && 1 === $io_x['nocache'], 'export: nocache_headers() called' );
$io_xb = json_decode( $io_x['body'], true );
cmp_assert( is_array( $io_xb ) && 'biscuitwp-appearance' === $io_xb['format'] && ! isset( $io_xb['appearance']['exclude'] ), 'export: the body is the file, without exclusions' );
cmp_assert( false !== strpos( $io_x['filename'], 'biscuitwp-aspect-example.test-' ) && 1 === preg_match( '#-\d{4}-\d{2}-\d{2}\.json$#', $io_x['filename'] ), 'export: file name biscuitwp-aspect-<host>-<Y-m-d>.json' );
cmp_assert( $io_x['exited'], 'export: the action ends the request' );
cmp_assert_eq( $io_x['writes'], array(), 'export: writes nothing' );
$io_xn = cmp_io_request( array_merge( $io_in, array( 'scenario' => 'export', 'nonce' => false ) ) );
cmp_assert( ! empty( $io_xn['died'] ) && '' === $io_xn['body'], 'export: a bad nonce -> wp_die and no file' );
$io_xc = cmp_io_request( array_merge( $io_in, array( 'scenario' => 'export', 'cap' => false ) ) );
cmp_assert( ! empty( $io_xc['died'] ) && '' === $io_xc['body'], 'export: without manage_options -> wp_die and no file' );
$io_hook = (string) file_get_contents( __DIR__ . '/../includes/admin/class-admin.php' );
cmp_assert( false !== strpos( $io_hook, "admin_post_cmp_export_appearance', array( 'CMP_Appearance_Page', 'export' )" ), 'export: wired as an admin-post action' );
cmp_assert( false !== strpos( (string) file_get_contents( __DIR__ . '/../includes/class-plugin.php' ), 'class-appearance-io.php' ), 'plugin: class-appearance-io.php is required' );
