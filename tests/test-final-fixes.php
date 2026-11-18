<?php
// B1 final fix wave: I1..I7 and the minors from the whole-branch review.
require_once __DIR__ . '/../includes/class-installer.php';
require_once __DIR__ . '/../includes/class-sanitizer.php';
require_once __DIR__ . '/../includes/modules/class-blocker.php';
require_once __DIR__ . '/../includes/modules/class-audit.php';

$ff_lib = CMP_Services::library();
$ff_by  = array();
foreach ( $ff_lib as $ff_s ) {
	$ff_by[ $ff_s['id'] ] = $ff_s;
}

// ---------------------------------------------------------------------------
// I1: schema marker independent of CMP_VERSION; resilient consent-log insert.
// ---------------------------------------------------------------------------
cmp_assert_eq( CMP_Installer::SCHEMA, 2, 'I1 schema is 2 (services column)' );
cmp_assert( CMP_Installer::needs_schema( false ), 'I1 no stored schema -> upgrade' );
cmp_assert( CMP_Installer::needs_schema( '1' ), 'I1 schema 1 -> upgrade' );
cmp_assert( ! CMP_Installer::needs_schema( 2 ), 'I1 schema 2 -> nothing to do' );
cmp_assert( ! CMP_Installer::needs_schema( '2' ), 'I1 schema "2" (stored as string) -> nothing to do' );
cmp_assert( ! CMP_Installer::needs_schema( 3 ), 'I1 newer schema -> nothing to do' );

$ff_row = array( 'consent_id' => 'x', 'decision' => 'accepted_all', 'services' => '' );

// Insert works first time: one call, repair never runs.
$ff_calls  = array();
$ff_repair = 0;
$ff_ok     = CMP_Audit::insert_resilient(
	$ff_row,
	function ( $d ) use ( &$ff_calls ) {
		$ff_calls[] = $d;
		return 1;
	},
	function () {
		return '';
	},
	function () use ( &$ff_repair ) {
		$ff_repair++;
	}
);
cmp_assert( $ff_ok && 1 === count( $ff_calls ) && 0 === $ff_repair, 'I1 working insert: one call, no repair' );

// Missing column: repair (dbDelta) once, then the retry succeeds with services.
$ff_calls  = array();
$ff_repair = 0;
$ff_fixed  = false;
$ff_ok     = CMP_Audit::insert_resilient(
	$ff_row,
	function ( $d ) use ( &$ff_calls, &$ff_fixed ) {
		$ff_calls[] = $d;
		return $ff_fixed ? 1 : false;
	},
	function () {
		return "Unknown column 'services' in 'field list'";
	},
	function () use ( &$ff_repair, &$ff_fixed ) {
		$ff_repair++;
		$ff_fixed = true;
	}
);
cmp_assert( $ff_ok && 1 === $ff_repair && 2 === count( $ff_calls ) && array_key_exists( 'services', $ff_calls[1] ), 'I1 missing column: repair once, retry with services' );

// Repair cannot add the column (no ALTER privilege): the row is still written without services.
$ff_calls  = array();
$ff_repair = 0;
$ff_ok     = CMP_Audit::insert_resilient(
	$ff_row,
	function ( $d ) use ( &$ff_calls ) {
		$ff_calls[] = $d;
		return array_key_exists( 'services', $d ) ? false : 1;
	},
	function () {
		return "Unknown column 'services' in 'field list'";
	},
	function () use ( &$ff_repair ) {
		$ff_repair++;
	}
);
cmp_assert( $ff_ok && 1 === $ff_repair && 3 === count( $ff_calls ) && ! array_key_exists( 'services', $ff_calls[2] ), 'I1 repair fails: the record is kept without services' );
cmp_assert_eq( $ff_calls[2]['decision'], 'accepted_all', 'I1 fallback row keeps the decision' );

// Any other DB error: no repair, no retry, reported as failure.
$ff_calls  = array();
$ff_repair = 0;
$ff_ok     = CMP_Audit::insert_resilient(
	$ff_row,
	function ( $d ) use ( &$ff_calls ) {
		$ff_calls[] = $d;
		return false;
	},
	function () {
		return 'Deadlock found';
	},
	function () use ( &$ff_repair ) {
		$ff_repair++;
	}
);
cmp_assert( ! $ff_ok && 0 === $ff_repair && 1 === count( $ff_calls ), 'I1 unrelated DB error: no repair, reported' );

// ---------------------------------------------------------------------------
// I3: detection uses the same (active) services as blocking.
// ---------------------------------------------------------------------------
$ff_clarity_page = '<script type="text/javascript">(function(c,l,a,r,i,t,y){t=l.createElement(r);t.src="https://www.clarity.ms/tag/"+i;})(window, document, "clarity", "script", "abc123");</script>';
$ff_exempt       = array();
foreach ( $ff_lib as $ff_s ) {
	if ( 'clarity' === $ff_s['id'] ) {
		$ff_s['exempt'] = true;
	}
	$ff_exempt[] = $ff_s;
}
cmp_assert_eq( CMP_Blocker::detect_trackers( $ff_clarity_page, $ff_exempt )['raw'], array(), 'I3 exempt Clarity is not "tracking without consent"' );
cmp_assert_eq( CMP_Blocker::detect_trackers( $ff_clarity_page, $ff_lib )['raw'], array( 'statistics' ), 'I3 non-exempt Clarity still reported raw' );
$ff_custom = array(
	array_merge(
		$ff_by['crisp'],
		array( 'id' => 'custom-1', 'name' => 'Calendly', 'type' => 'script', 'category' => 'marketing', 'hosts' => array( 'calendly.com' ), 'inline' => array(), 'source' => 'custom' )
	),
);
cmp_assert_eq( CMP_Blocker::detect_trackers( '<script src="https://assets.calendly.com/x.js"></script>', $ff_custom )['raw'], array( 'marketing' ), 'I3 raw custom service is reported' );

// ---------------------------------------------------------------------------
// I4: found_ids lists live embeds too (exempt, unrewritten, necessary).
// ---------------------------------------------------------------------------
$ff_yt_live = '<p>x</p><iframe width="560" height="315" src="https://www.youtube.com/embed/abcdefghijk"></iframe>';
cmp_assert( in_array( 'youtube', CMP_Blocker::found_ids( $ff_yt_live, $ff_lib ), true ), 'I4 live (unrewritten) YouTube iframe is found' );
$ff_yt_ex = array();
foreach ( $ff_lib as $ff_s ) {
	if ( 'youtube' === $ff_s['id'] ) {
		$ff_s['exempt'] = true;
	}
	$ff_yt_ex[] = $ff_s;
}
cmp_assert( in_array( 'youtube', CMP_Blocker::found_ids( $ff_yt_live, $ff_yt_ex ), true ), 'I4 exempt YouTube iframe is found' );
$ff_vm_nec = array();
foreach ( $ff_lib as $ff_s ) {
	if ( 'vimeo' === $ff_s['id'] ) {
		$ff_s['category'] = 'necessary';
	}
	$ff_vm_nec[] = $ff_s;
}
cmp_assert( in_array( 'vimeo', CMP_Blocker::found_ids( '<iframe src="https://player.vimeo.com/video/1"></iframe>', $ff_vm_nec ), true ), 'I4 necessary Vimeo iframe is found' );
cmp_assert( in_array( 'google-maps', CMP_Blocker::found_ids( "<iframe src='https://www.google.com/maps/embed?pb=1'></iframe>", $ff_lib ), true ), 'I4 live Maps iframe is found (single quotes)' );
cmp_assert( ! in_array( 'google-maps', CMP_Blocker::found_ids( '<iframe src="https://www.google.com/search?q=1"></iframe>', $ff_lib ), true ), 'I4 Maps path still honoured' );
cmp_assert( in_array( 'custom-1', CMP_Blocker::found_ids( '<iframe src="https://calendly.com/me"></iframe>', $ff_custom ), true ), 'I4 custom service iframe is found' );
// Blocking is unchanged: the exempt embed stays live.
cmp_assert_eq( CMP_Blocker::rewrite( $ff_yt_live, array(), $ff_yt_ex ), $ff_yt_live, 'I4 exempt YouTube still not rewritten' );

// ---------------------------------------------------------------------------
// I5: custom services also match iframes.
// ---------------------------------------------------------------------------
$ff_cal = '<iframe src="https://calendly.com/me/30min" width="600" height="400"></iframe>';
$ff_o   = CMP_Blocker::rewrite( $ff_cal, array(), $ff_custom );
cmp_assert( false !== strpos( $ff_o, 'class="cmp-embed' ) && false !== strpos( $ff_o, 'data-cmp-embed="marketing"' ), 'I5 custom-host iframe becomes a placeholder in its category' );
cmp_assert( false !== strpos( $ff_o, 'data-cmp-service="custom-1"' ) && false !== strpos( $ff_o, 'data-cmp-service-name="Calendly"' ), 'I5 placeholder names the custom service' );
cmp_assert( false !== strpos( $ff_o, 'data-cmp-embed-always' ), 'I5 custom placeholder has the always button' );
cmp_assert( false !== strpos( $ff_o, '<template data-cmp-embed-src>' . $ff_cal . '</template>' ), 'I5 original iframe kept in the template' );
$ff_o = CMP_Blocker::rewrite( $ff_cal, array( 'light' => true ), $ff_custom );
cmp_assert( false !== strpos( $ff_o, 'data-cmp-service="custom-1"' ) && false === strpos( $ff_o, 'data-cmp-embed-always' ), 'I5 no always button in Light' );
$ff_o = CMP_Blocker::rewrite( '<iframe src="https://sub.calendly.com/x"></iframe>', array(), $ff_custom );
cmp_assert( false !== strpos( $ff_o, 'data-cmp-service="custom-1"' ), 'I5 subdomain of a custom host matches' );
cmp_assert_eq( CMP_Blocker::rewrite( '<iframe src="https://notcalendly.com/x"></iframe>', array(), $ff_custom ), '<iframe src="https://notcalendly.com/x"></iframe>', 'I5 look-alike host does not match' );
$ff_cx = $ff_custom;
$ff_cx[0]['exempt'] = true;
cmp_assert_eq( CMP_Blocker::rewrite( $ff_cal, array(), $ff_cx ), $ff_cal, 'I5 exempt custom service iframe not blocked' );
$ff_cn = $ff_custom;
$ff_cn[0]['category'] = 'necessary';
cmp_assert_eq( CMP_Blocker::rewrite( $ff_cal, array(), $ff_cn ), $ff_cal, 'I5 necessary custom service iframe not blocked' );
// Library embeds keep priority over a custom service with the same host.
$ff_cy = array_merge( $ff_lib, array( array_merge( $ff_custom[0], array( 'hosts' => array( 'youtube.com' ) ) ) ) );
$ff_o  = CMP_Blocker::rewrite( '<iframe src="https://www.youtube.com/embed/abcdefghijk"></iframe>', array(), $ff_cy );
cmp_assert( false !== strpos( $ff_o, 'data-cmp-service="youtube"' ) && false === strpos( $ff_o, 'custom-1' ), 'I5 library embed wins over a custom host' );
// A custom service without a name is named after the iframe host.
$ff_cu = $ff_custom;
$ff_cu[0]['name'] = '';
$ff_o = CMP_Blocker::rewrite( $ff_cal, array(), $ff_cu );
cmp_assert( false !== strpos( $ff_o, 'data-cmp-service-name="calendly.com"' ), 'I5 unnamed custom service shows the host' );
cmp_assert_eq( CMP_Services::custom_embed_service( 'https://calendly.com/x', $ff_lib ), null, 'I5 library script services never match iframes as custom' );
cmp_assert_eq( CMP_Services::svc_names( $ff_custom ), array( 'custom-1' => 'Calendly' ), 'I5 svcNames includes custom services with hosts' );
$ff_names = CMP_Services::svc_names( $ff_lib );
cmp_assert( isset( $ff_names['youtube'] ) && ! isset( $ff_names['google-analytics'] ), 'I5 svcNames: library embeds only, not library scripts' );

// ---------------------------------------------------------------------------
// I6: data-cmp-category fails closed.
// ---------------------------------------------------------------------------
$ff_o = CMP_Blocker::rewrite( '<script data-cmp-category="Marketing" src="https://unknown.io/x.js"></script>' );
cmp_assert( false !== strpos( $ff_o, 'data-cmp-block="marketing"' ), 'I6 capitalised category is lowercased and blocked' );
$ff_o = CMP_Blocker::rewrite( '<script data-cmp-category=" Statistics " src="https://unknown.io/x.js"></script>' );
cmp_assert( false !== strpos( $ff_o, 'data-cmp-block="statistics"' ), 'I6 category is trimmed' );
$ff_o = CMP_Blocker::rewrite( '<script data-cmp-category="Bad Cat" src="https://unknown.io/x.js"></script>' );
cmp_assert( false !== strpos( $ff_o, 'data-cmp-block="marketing"' ) && false === strpos( $ff_o, 'unknown.io' ), 'I6 invalid category on a non-library script is blocked as marketing' );
$ff_o = CMP_Blocker::rewrite( '<iframe data-cmp-category="Bad Cat" src="https://unknown.io/w"></iframe>' );
cmp_assert( false !== strpos( $ff_o, 'data-cmp-embed="marketing"' ), 'I6 invalid category on a non-library iframe is blocked as marketing' );
$ff_h = '<script data-cmp-category="NECESSARY" src="https://unknown.io/x.js"></script>';
cmp_assert_eq( CMP_Blocker::rewrite( $ff_h ), $ff_h, 'I6 NECESSARY (any case) leaves the tag alone' );
$ff_h = '<script data-cmp-category=" necessary " src="https://unknown.io/x.js"></script>';
cmp_assert_eq( CMP_Blocker::rewrite( $ff_h ), $ff_h, 'I6 padded necessary leaves the tag alone' );
$ff_h = '<script data-cmp-category="  " src="https://unknown.io/x.js"></script>';
cmp_assert_eq( CMP_Blocker::rewrite( $ff_h ), $ff_h, 'I6 blank value counts as absent' );
$ff_o = CMP_Blocker::rewrite( '<script data-cmp-category="statistics"src="https://unknown.io/x.js"></script>' );
cmp_assert( false !== strpos( $ff_o, 'data-cmp-block="statistics"' ), 'I6 no whitespace after the category value' );
$ff_o = CMP_Blocker::rewrite( '<script src="https://unknown.io/x.js"data-cmp-category="statistics"></script>' );
cmp_assert( false !== strpos( $ff_o, 'data-cmp-block="statistics"' ), 'I6 no whitespace after the previous attribute' );
$ff_o = CMP_Blocker::rewrite( '<script data-cmp-category="Bad Cat" src="https://www.google-analytics.com/analytics.js"></script>' );
cmp_assert( false !== strpos( $ff_o, 'data-cmp-service="google-analytics"' ), 'I6 invalid category on a library script still uses the library rule' );

// ---------------------------------------------------------------------------
// I7: a missing / corrupt library falls back to the built-in legacy rules.
// ---------------------------------------------------------------------------
$ff_missing = CMP_Services::library_from( __DIR__ . '/no-such-services.json' );
$ff_ids     = array();
foreach ( $ff_missing['services'] as $ff_s ) {
	$ff_ids[] = $ff_s['id'];
}
cmp_assert_eq( $ff_ids, CMP_Services::LEGACY_IDS, 'I7 missing file -> the 6 legacy services' );
cmp_assert( $ff_missing['fallback'] && $ff_missing['errors'], 'I7 missing file is flagged (fallback + error)' );
$ff_o = CMP_Blocker::rewrite( '<script src="https://www.google-analytics.com/analytics.js" async></script>', array(), $ff_missing['services'] );
cmp_assert( false !== strpos( $ff_o, 'data-cmp-block="statistics"' ) && false !== strpos( $ff_o, 'data-cmp-service="google-analytics"' ), 'I7 missing file still blocks GA' );
$ff_o = CMP_Blocker::rewrite( '<iframe src="https://www.youtube.com/embed/abcdefghijk"></iframe>', array(), $ff_missing['services'] );
cmp_assert( false !== strpos( $ff_o, 'data-cmp-service="youtube"' ), 'I7 missing file still blocks YouTube' );
$ff_tmp = tempnam( sys_get_temp_dir(), 'cmpsvc' );
file_put_contents( $ff_tmp, '{"services": [ broken' );
$ff_bad = CMP_Services::library_from( $ff_tmp );
unlink( $ff_tmp );
cmp_assert( $ff_bad['fallback'] && 6 === count( $ff_bad['services'] ), 'I7 corrupt JSON -> fallback' );
$ff_ok_lib = CMP_Services::library_from( dirname( __DIR__ ) . '/data/services.json' );
cmp_assert( ! $ff_ok_lib['fallback'] && ! $ff_ok_lib['errors'] && count( $ff_ok_lib['services'] ) > 6, 'I7 the real file loads without fallback' );
// The fallback mirrors today's JSON rules for the legacy services.
foreach ( CMP_Services::fallback() as $ff_f ) {
	$ff_j = $ff_by[ $ff_f['id'] ];
	foreach ( array( 'name', 'provider', 'type', 'category', 'hosts', 'inline', 'gtm', 'path', 'nocookie' ) as $ff_k ) {
		cmp_assert_eq( $ff_f[ $ff_k ], $ff_j[ $ff_k ], 'I7 fallback ' . $ff_f['id'] . ' ' . $ff_k . ' matches data/services.json' );
	}
}
cmp_assert( ! CMP_Services::using_fallback(), 'I7 the plugin library is not on the fallback' );

// ---------------------------------------------------------------------------
// Minor: per-request cache of the library.
// ---------------------------------------------------------------------------
cmp_assert( CMP_Services::library() === CMP_Services::library(), 'minor library is cached per request' );

// ---------------------------------------------------------------------------
// Minor: the site's own host is never a custom host.
// ---------------------------------------------------------------------------
cmp_assert_eq( CMP_Sanitizer::normalize_host( 'example.md', 'example.md' ), '', 'minor own host rejected' );
cmp_assert_eq( CMP_Sanitizer::normalize_host( 'https://Example.md/wp-content/x.js', 'www.example.md' ), '', 'minor parent of own host rejected' );
cmp_assert_eq( CMP_Sanitizer::normalize_host( 'www.example.md', 'example.md' ), '', 'minor www of own host rejected' );
cmp_assert_eq( CMP_Sanitizer::normalize_host( 'calendly.com', 'example.md' ), 'calendly.com', 'minor other host accepted' );
cmp_assert_eq( CMP_Sanitizer::normalize_host( 'notexample.md', 'example.md' ), 'notexample.md', 'minor look-alike host accepted' );
cmp_assert_eq( CMP_Sanitizer::normalize_host( 'example.md' ), 'example.md', 'minor no own host given -> unchanged behaviour' );
cmp_assert( CMP_Sanitizer::is_own_host( 'example.md', 'www.example.md' ) && ! CMP_Sanitizer::is_own_host( 'cdn.other.md', 'example.md' ), 'minor is_own_host' );
$ff_sv = CMP_Sanitizer::services(
	array( 'custom' => array( array( 'name' => 'X', 'hosts' => "example.md\ncalendly.com", 'category' => 'marketing' ) ) ),
	array(),
	array( 'necessary', 'marketing' ),
	false,
	array(),
	array(),
	'example.md'
);
cmp_assert_eq( $ff_sv['custom'][0]['hosts'], array( 'calendly.com' ), 'minor services() drops the own host' );

// ---------------------------------------------------------------------------
// Minor: a blocked reCAPTCHA matches inline scripts by host+path only.
// ---------------------------------------------------------------------------
$ff_rc = array();
foreach ( $ff_lib as $ff_s ) {
	if ( 'recaptcha' === $ff_s['id'] ) {
		$ff_s['category'] = 'marketing';
	}
	$ff_rc[] = $ff_s;
}
$ff_h = '<script>var u="https://www.google.com/maps/place/x";</script>';
cmp_assert_eq( CMP_Blocker::rewrite( $ff_h, array(), $ff_rc ), $ff_h, 'minor blocked reCAPTCHA leaves other www.google.com inline scripts alone' );
$ff_o = CMP_Blocker::rewrite( '<script>var s=document.createElement("script");s.src="https://www.google.com/recaptcha/api.js";</script>', array(), $ff_rc );
cmp_assert( false !== strpos( $ff_o, 'data-cmp-service="recaptcha"' ), 'minor blocked reCAPTCHA still catches its inline loader' );
$ff_o = CMP_Blocker::rewrite( '<script src="https://www.google.com/recaptcha/api.js"></script>', array(), $ff_rc );
cmp_assert( false !== strpos( $ff_o, 'data-cmp-service="recaptcha"' ), 'minor blocked reCAPTCHA src still blocked' );
// Host-only rules keep the body fallback (an SDK injected through js.src).
$ff_o = CMP_Blocker::rewrite( '<script>(function(d){var js=d.createElement("script");js.src="https://connect.facebook.net/en_US/sdk.js";})(document);</script>' );
cmp_assert( false !== strpos( $ff_o, 'data-cmp-service="meta-pixel"' ), 'minor host-only body fallback unchanged' );

// ---------------------------------------------------------------------------
// Minor: the privacy-guide text is built on real admin page loads only.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../includes/modules/class-legal.php';
cmp_assert( CMP_Legal::guide_wanted( false, true ), 'minor privacy guide on an admin page load' );
cmp_assert( ! CMP_Legal::guide_wanted( true, true ), 'minor privacy guide skipped on admin-ajax' );
cmp_assert( ! CMP_Legal::guide_wanted( false, false ), 'minor privacy guide skipped on the front end' );
