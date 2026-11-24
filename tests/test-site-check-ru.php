<?php
/**
 * "Verifică-ți site-ul" in Russian: every text of the analysis (check.php), the
 * endpoint's error messages (endpoint.php) and the renderer (verifica.js, run in
 * node on the fake DOM of tests/fixtures/site-verifica/run.js) has a Russian
 * version; Russian strings carry the same placeholders as the English ones, no
 * Romanian diacritics and no em dash; an unknown language still reads as Romanian.
 */
$cmp_ru_api = __DIR__ . '/../site/api';
if ( ! defined( 'CMP_CHECK' ) ) {
	define( 'CMP_CHECK', true );
}
foreach ( array( 'check.php', 'fetch.php', 'discover.php', 'ratelimit.php', 'endpoint.php' ) as $f ) {
	require_once $cmp_ru_api . '/lib/' . $f;
}
if ( ! function_exists( 'cmp_check_lang' ) ) {
	cmp_assert( false, 'ru: cmp_check_lang() exists' );
	return;
}

/** Problems of a Russian string: empty, Romanian diacritics, em dash, no Cyrillic. '' when fine. */
function cmp_ru_bad( $s, $need_cyrillic = true ) {
	if ( ! is_string( $s ) || '' === trim( $s ) ) {
		return 'empty';
	}
	if ( 1 === preg_match( '/[ăâîșțşţĂÂÎȘȚŞŢ]/u', $s ) ) {
		return 'Romanian diacritics';
	}
	if ( false !== strpos( $s, "\xe2\x80\x94" ) ) {
		return 'em dash';
	}
	if ( $need_cyrillic && 1 !== preg_match( '/\p{Cyrillic}/u', $s ) ) {
		return 'no Cyrillic';
	}
	return '';
}

/** The placeholders of a string ({name}, %d...), sorted, duplicates kept once. */
function cmp_ru_ph( $s ) {
	preg_match_all( '/\{[a-z_]+\}|%[a-z]/', (string) $s, $m );
	$p = array_values( array_unique( $m[0] ) );
	sort( $p );
	return $p;
}

/** The keys of the table in a function's source: lines "\t\t'key' => array(" (first level only). */
function cmp_ru_keys( $func ) {
	$rf  = new ReflectionFunction( $func );
	$src = implode( '', array_slice( file( $rf->getFileName() ), $rf->getStartLine() - 1, $rf->getEndLine() - $rf->getStartLine() + 1 ) );
	preg_match_all( "/^\t\t'([a-z0-9:_-]+)'\s*=> (?:array\(|')/m", $src, $m );
	return array_values( array_diff( array_unique( $m[1] ), array( 'ro', 'en', 'ru' ) ) );
}

/* ------------------------------------------------------------------
 * The language helper.
 * ------------------------------------------------------------------ */
cmp_assert_eq( array( cmp_check_lang( 'ro' ), cmp_check_lang( 'en' ), cmp_check_lang( 'ru' ) ), array( 'ro', 'en', 'ru' ), 'ru: cmp_check_lang keeps ro, en, ru' );
cmp_assert_eq( array( cmp_check_lang( 'de' ), cmp_check_lang( '' ), cmp_check_lang( 'RU' ), cmp_check_lang( null ), cmp_check_lang( array( 'ru' ) ) ), array( 'ro', 'ro', 'ro', 'ro', 'ro' ), 'ru: cmp_check_lang falls back to ro' );
$pl = array();
foreach ( array( 0, 1, 2, 4, 5, 11, 12, 14, 20, 21, 22, 25, 101, 111, 112, 122 ) as $n ) {
	$pl[] = $n . ' ' . cmp_check_ru_plural( $n, 'страница', 'страницы', 'страниц' );
}
cmp_assert_eq( $pl, array( '0 страниц', '1 страница', '2 страницы', '4 страницы', '5 страниц', '11 страниц', '12 страниц', '14 страниц', '20 страниц', '21 страница', '22 страницы', '25 страниц', '101 страница', '111 страниц', '112 страниц', '122 страницы' ), 'ru: Russian plural forms (one / few / many)' );

/* ------------------------------------------------------------------
 * Every text table: a Russian value, clean, with the English placeholders.
 * ------------------------------------------------------------------ */
$cmp_ru_text_keys = cmp_ru_keys( 'cmp_check_text' );
$cmp_ru_fix_keys  = cmp_ru_keys( 'cmp_check_fix' );
cmp_assert( count( $cmp_ru_text_keys ) >= 19 && in_array( 'tracker', $cmp_ru_text_keys, true ) && in_array( 'transport', $cmp_ru_text_keys, true ), 'ru: the finding texts are enumerated (' . count( $cmp_ru_text_keys ) . ')' );
cmp_assert( count( $cmp_ru_fix_keys ) >= 26 && in_array( 'unread', $cmp_ru_fix_keys, true ) && in_array( 'resource-google-fonts', $cmp_ru_fix_keys, true ), 'ru: the fix texts are enumerated (' . count( $cmp_ru_fix_keys ) . ')' );
$bad = array();
foreach ( $cmp_ru_text_keys as $key ) {
	$ru = cmp_check_text( $key, 'ru' );
	$en = cmp_check_text( $key, 'en' );
	foreach ( array( 'title', 'explain' ) as $k ) {
		$why = cmp_ru_bad( $ru[ $k ], 'title' !== $k );
		if ( '' !== $why ) {
			$bad[] = "text {$key} {$k}: {$why}";
		}
		if ( cmp_ru_ph( $ru[ $k ] ) !== cmp_ru_ph( $en[ $k ] ) ) {
			$bad[] = "text {$key} {$k}: placeholders " . implode( ',', cmp_ru_ph( $ru[ $k ] ) ) . ' vs ' . implode( ',', cmp_ru_ph( $en[ $k ] ) );
		}
	}
}
foreach ( $cmp_ru_fix_keys as $key ) {
	$ru = cmp_check_fix( $key, 'ru' );
	$en = cmp_check_fix( $key, 'en' );
	foreach ( array( 'fix', 'cmp_fix' ) as $k ) {
		$why = cmp_ru_bad( $ru[ $k ] );
		if ( '' !== $why ) {
			$bad[] = "fix {$key} {$k}: {$why}";
		}
		if ( cmp_ru_ph( $ru[ $k ] ) !== cmp_ru_ph( $en[ $k ] ) ) {
			$bad[] = "fix {$key} {$k}: placeholders " . implode( ',', cmp_ru_ph( $ru[ $k ] ) ) . ' vs ' . implode( ',', cmp_ru_ph( $en[ $k ] ) );
		}
	}
}
cmp_assert_eq( $bad, array(), 'ru: every finding text and fix has a clean Russian value with the English placeholders' );

$bad = array();
foreach ( cmp_ru_keys( 'cmp_check_law' ) as $kind ) {
	$ru = cmp_check_law( $kind, 'ru' );
	if ( '' !== cmp_ru_bad( $ru ) || false === strpos( $ru, 'Закон №' ) || false !== strpos( $ru, 'Legea' ) ) {
		$bad[] = $kind;
	}
}
cmp_assert( array() === $bad && 3 === count( cmp_ru_keys( 'cmp_check_law' ) ), 'ru: the three law citations in Russian (Закон №...)' . ( $bad ? ': ' . implode( ', ', $bad ) : '' ) );
cmp_assert_eq( cmp_check_law( 'inform', 'ru' ), 'Закон № 72/2025, ст. 116 ч. (5); Закон № 195/2024, ст. 12–13', 'ru: law references keep their numbers (ст., ч.)' );

$bad = array();
foreach ( cmp_check_passed_titles() as $id => $titles ) {
	if ( ! isset( $titles['ru'] ) || '' !== cmp_ru_bad( $titles['ru'], 'https' !== $id ) ) {
		$bad[] = $id;
	}
}
cmp_assert_eq( $bad, array(), 'ru: every "passed" title has a Russian value' );
cmp_assert_eq( count( cmp_check_passed_list( array_keys( cmp_check_passed_titles() ), 'ru' ) ), 6, 'ru: the passed list in Russian has the six checks' );
cmp_assert_eq( cmp_check_passed_list( array( 'banner' ), 'en' ), array( array( 'id' => 'banner', 'title' => 'The cookie banner is on the page' ) ), 'ru: the English passed title is unchanged' );
cmp_assert_eq( cmp_check_passed_list( array( 'banner' ), 'ro' ), array( array( 'id' => 'banner', 'title' => 'Bannerul de cookie-uri e pe pagină' ) ), 'ru: the Romanian passed title is unchanged' );

$bad = array();
foreach ( cmp_ru_keys( 'cmp_check_resource_label' ) as $type ) {
	foreach ( array( 'title', 'what' ) as $form ) {
		$ru = cmp_check_resource_label( $type, 'ru', $form );
		if ( '' !== cmp_ru_bad( $ru, false ) || $type === $ru ) {
			$bad[] = "{$type} {$form}";
		}
	}
}
cmp_assert( array() === $bad && count( cmp_ru_keys( 'cmp_check_resource_label' ) ) >= 6, 'ru: every resource label (title and what) has a Russian value' . ( $bad ? ': ' . implode( ', ', $bad ) : '' ) );
cmp_assert_eq( array( cmp_check_resource_label( 'cdn', 'ru' ), cmp_check_resource_label( 'cdn', 'ru', 'what' ), cmp_check_resource_label( 'cdn', 'en', 'what' ), cmp_check_resource_label( 'cdn', 'ro' ) ), array( 'Публичный CDN', 'файлы с публичного CDN', 'files from a public CDN', 'Un CDN public' ), 'ru: resource labels by language' );

$bad = array();
foreach ( cmp_check_extra_services() as $x ) {
	if ( isset( $x['id'] ) && ! isset( $x['extend'] ) ) {
		$merged = cmp_check_merge_extra( array(), 'ru' );
		foreach ( $merged as $s ) {
			if ( $s['id'] === $x['id'] && ( $s['name'] === $s['id'] || '' !== cmp_ru_bad( $s['name'], false ) ) ) {
				$bad[] = $x['id'];
			}
		}
	}
}
cmp_assert_eq( $bad, array(), 'ru: the extra services have a name in Russian (their brand name)' );

/* ------------------------------------------------------------------
 * cmp_check_analyze() and cmp_check_aggregate() in Russian.
 * ------------------------------------------------------------------ */
$cmp_ru_services = cmp_check_load_services( __DIR__ . '/../data/services.json' );
$cmp_ru_cases    = array();
foreach ( glob( __DIR__ . '/fixtures/site-check/*.html' ) as $file ) {
	$cmp_ru_cases[] = array( (string) file_get_contents( $file ), 'https://exemplu.md/' );
}
foreach ( array(
	'',
	'<html><head><title>Just a moment...</title></head><body></body></html>',
	'<script async src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>' . str_repeat( '<p>x</p>', 300000 ),
	'<script type="text/x-unknown" src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>',
	'<script src="https://cloud.umami.is/script.js" data-website-id="1"></script>',
	'<iframe src="https://www.youtube.com/embed/x"></iframe><img src="https://www.facebook.com/tr?id=1&ev=PageView">',
	'<script async src="https://www.googletagmanager.com/gtag/js?id=AW-1"></script><script>ym(1,"init",{})</script>',
	'<link rel="stylesheet" href="https://use.typekit.net/x.css"><img src="https://secure.gravatar.com/avatar/x"><script src="https://s.w.org/x.js"></script><script src="https://cdn.jsdelivr.net/x.js"></script><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter">',
	"<script>gtag('config','G-1');</script>",
	'<script src="https://www.googletagmanager.com/gtm.js?id=GTM-1"></script>',
) as $html ) {
	$cmp_ru_cases[] = array( $html, 'http://exemplu.md/' );
}
$bad   = array();
$seen  = array();
$texts = 0;
foreach ( $cmp_ru_cases as $c ) {
	$headers = array( 'Set-Cookie' => array( '_ga=1', '_fbp=1', 'PHPSESSID=1' ) );
	$r       = cmp_check_analyze( $c[0], $headers, $c[1], $cmp_ru_services, 'ru' );
	foreach ( $r['findings'] as $f ) {
		$seen[ preg_replace( '/:.*$/', '', $f['id'] ) ] = true;
		foreach ( array( 'title', 'explain', 'fix', 'cmp_fix' ) as $k ) {
			$why = cmp_ru_bad( $f[ $k ] );
			if ( '' !== $why || false !== strpos( $f[ $k ], '{' ) ) {
				$bad[] = "{$f['id']} {$k}: " . ( '' !== $why ? $why : 'placeholder left' );
			}
			$texts++;
		}
		if ( '' !== $f['law'] && ( '' !== cmp_ru_bad( $f['law'] ) || false === strpos( $f['law'], 'Закон №' ) ) ) {
			$bad[] = "{$f['id']} law";
		}
	}
	foreach ( $r['passed'] as $p ) {
		if ( '' !== cmp_ru_bad( $p['title'], 'https' !== $p['id'] ) ) {
			$bad[] = "passed {$p['id']}";
		}
	}
	// An unknown language reads exactly as Romanian.
	if ( strlen( $c[0] ) < 100000 && cmp_check_analyze( $c[0], $headers, $c[1], $cmp_ru_services, 'fr' ) !== cmp_check_analyze( $c[0], $headers, $c[1], $cmp_ru_services, 'ro' ) ) {
		$bad[] = 'fr differs from ro: ' . substr( $c[0], 0, 60 );
	}
}
cmp_assert_eq( $bad, array(), "ru: cmp_check_analyze('ru') returns Russian titles, explanations, fixes, laws and passed titles ({$texts} texts)" );
foreach ( array( 'tracker', 'cookie', 'cookies', 'resource', 'policy', 'transport', 'held', 'page' ) as $kind ) {
	cmp_assert( isset( $seen[ $kind ] ), "ru: the Russian run covers {$kind}: findings" );
}

$r = cmp_check_analyze( "<script>gtag('config','G-1');</script>", array(), 'https://exemplu.md/', $cmp_ru_services, 'ru' );
$e = array();
foreach ( $r['findings'] as $f ) {
	$e = array_merge( $e, $f['evidence'] );
}
cmp_assert( in_array( 'встроенный скрипт на странице', $e, true ), 'ru: an inline script is described in Russian in the evidence' );
$r = cmp_check_analyze( '<link rel="stylesheet" href="https://cdn.jsdelivr.net/x.css">', array(), 'https://exemplu.md/', $cmp_ru_services, 'ru' );
$f = null;
foreach ( $r['findings'] as $ff ) {
	if ( 'resource:cdn' === $ff['id'] ) {
		$f = $ff;
	}
}
cmp_assert( null !== $f && 'Публичный CDN: передача IP-адреса посетителей' === $f['title'] && false !== strpos( $f['explain'], 'Страница загружает файлы с публичного CDN (cdn.jsdelivr.net)' ), 'ru: a resource finding is filled with the Russian labels' );
cmp_assert( null !== $f && false !== strpos( $f['cmp_fix'], 'загружаются файлы с публичного CDN' ), 'ru: the resource fix uses the Russian "what" label' );

$page        = cmp_check_analyze( '<p>x</p><a href="/politica/">Политика конфиденциальности</a>', array(), 'https://exemplu.md/', $cmp_ru_services, 'ru' );
$page['url'] = 'https://exemplu.md/';
$unread      = function ( $n ) use ( $page ) {
	$agg = cmp_check_aggregate( array( $page ), 'ru', $n );
	foreach ( $agg['findings'] as $f ) {
		if ( 'pages:unread' === $f['id'] ) {
			return $f;
		}
	}
	return null;
};
$titles = array();
foreach ( array( 1, 2, 5, 21 ) as $n ) {
	$f        = $unread( $n );
	$titles[] = null === $f ? '' : $f['title'];
}
cmp_assert_eq(
	$titles,
	array(
		'1 страницу не удалось прочитать, поэтому мы не можем сказать, что всё в порядке',
		'2 страницы не удалось прочитать, поэтому мы не можем сказать, что всё в порядке',
		'5 страниц не удалось прочитать, поэтому мы не можем сказать, что всё в порядке',
		'21 страницу не удалось прочитать, поэтому мы не можем сказать, что всё в порядке',
	),
	'ru: the aggregate\'s unread pages title, with Russian plurals'
);
$f = $unread( 2 );
cmp_assert( null !== $f && '' === cmp_ru_bad( $f['explain'] ) && '' === cmp_ru_bad( $f['fix'] ) && '' === cmp_ru_bad( $f['cmp_fix'] ), 'ru: the aggregate\'s unread pages explanation and fix in Russian' );
$agg = cmp_check_aggregate( array( $page ), 'ru', 0 );
$bad = array();
foreach ( $agg['passed'] as $p ) {
	if ( '' !== cmp_ru_bad( $p['title'], 'https' !== $p['id'] ) ) {
		$bad[] = $p['id'];
	}
}
cmp_assert( array() === $bad && count( $agg['passed'] ) > 0, 'ru: the aggregate\'s passed titles in Russian' );
$ro  = cmp_check_aggregate( array( $page ), 'ro', 2 );
$xx  = cmp_check_aggregate( array( $page ), 'xx', 2 );
cmp_assert( $ro === $xx, 'ru: the aggregate falls back to Romanian for an unknown language' );
$en = cmp_check_aggregate( array( $page ), 'en', 2 );
$t  = '';
foreach ( $en['findings'] as $f ) {
	if ( 'pages:unread' === $f['id'] ) {
		$t = $f['title'];
	}
}
cmp_assert_eq( $t, '2 pages could not be read, so we cannot say everything is fine', 'ru: the English unread title is unchanged' );

// An ours-attention text in Russian (our plugin on the page, a tracker before consent).
$ours = cmp_check_analyze( '<script src="/wp-content/plugins/consent-manager-pro/assets/cmp.js"></script><script async src="https://www.googletagmanager.com/gtag/js?id=G-1"></script><a href="/politica/">Политика</a>', array(), 'https://exemplu.md/', $cmp_ru_services, 'ru' );
$ours['url'] = 'https://exemplu.md/';
$agg         = cmp_check_aggregate( array( $ours ), 'ru', 0 );
$t           = null;
foreach ( $agg['findings'] as $f ) {
	if ( 'cmp:consent-manager-pro' === $f['id'] ) {
		$t = $f;
	}
}
cmp_assert( null !== $t && 'Вы уже используете BiscuitWP' === $t['title'] && false !== strpos( $t['explain'], 'что-то всё равно запускается до согласия' ) && '' === cmp_ru_bad( $t['fix'] ), 'ru: our own plugin\'s attention finding in Russian' );

/* ------------------------------------------------------------------
 * The endpoint: lang=ru accepted, Russian error messages.
 * ------------------------------------------------------------------ */
cmp_assert_eq( cmp_check_read_request( 'application/json', '{"url":"exemplu.md","lang":"ru","owner":1}', array() )['lang'], 'ru', 'ru: endpoint reads lang=ru from JSON' );
cmp_assert_eq( cmp_check_read_request( 'application/x-www-form-urlencoded', '', array( 'url' => 'exemplu.md', 'lang' => 'ru', 'owner' => '1' ) )['lang'], 'ru', 'ru: endpoint reads lang=ru from a form' );
cmp_assert_eq( cmp_check_read_request( 'application/json', '{"url":"exemplu.md","lang":"de","owner":1}', array() )['lang'], 'ro', 'ru: endpoint maps an unknown lang to ro' );
cmp_assert_eq( cmp_check_read_request( 'application/json', '{"url":"exemplu.md","lang":["ru"],"owner":1}', array() )['lang'], 'ro', 'ru: endpoint maps a non-string lang to ro' );
cmp_assert_eq( cmp_check_read_request( 'application/json', '{"url":"exemplu.md","lang":"en","owner":1}', array() )['lang'], 'en', 'ru: endpoint still reads lang=en' );

$rf  = new ReflectionFunction( 'cmp_check_error_message' );
$src = implode( '', array_slice( file( $rf->getFileName() ), $rf->getStartLine() - 1, $rf->getEndLine() - $rf->getStartLine() + 1 ) );
preg_match_all( "/^\t\t\t'([a-z_]+)'\s*=> /m", $src, $m );
$codes = array_values( array_unique( $m[1] ) );
cmp_assert( count( $codes ) >= 15 && in_array( 'http_status', $codes, true ), 'ru: the error codes are enumerated (' . count( $codes ) . ')' );
$bad = array();
foreach ( $codes as $code ) {
	$ru = cmp_check_error_message( $code, 'ru', 418 );
	$en = cmp_check_error_message( $code, 'en', 418 );
	$ro = cmp_check_error_message( $code, 'ro', 418 );
	if ( '' !== cmp_ru_bad( $ru ) || $ru === $ro || $ru === cmp_check_error_message( 'fetch_failed', 'ru' ) && 'fetch_failed' !== $code ) {
		$bad[] = "{$code}: " . cmp_ru_bad( $ru );
	}
	if ( ( false !== strpos( $en, '418' ) ) !== ( false !== strpos( $ru, '418' ) ) || false !== strpos( $ru, '%' ) ) {
		$bad[] = "{$code}: status placeholder";
	}
	if ( cmp_check_error_message( $code, 'xx', 418 ) !== $ro ) {
		$bad[] = "{$code}: unknown lang is not ro";
	}
}
cmp_assert_eq( $bad, array(), 'ru: every error code has its own clean Russian message, %d filled like in English, unknown lang as ro' );
cmp_assert_eq( cmp_check_error_message( 'http_status', 'ru', 503 ), 'Сайт ответил ошибкой HTTP 503, поэтому мы не смогли прочитать страницу.', 'ru: http_status message with the status' );

$handle = function ( $body ) {
	return cmp_check_handle( array( 'method' => 'POST', 'content_type' => 'application/json', 'body' => $body, 'ip' => '192.0.2.7' ), sys_get_temp_dir() . '/cmp-ru-unused', __DIR__ . '/../data/services.json', 1700000000, function () {
		return array( 'error' => 'fetch_failed' );
	} );
};
$r = $handle( '{"url":"","lang":"ru","owner":1}' );
cmp_assert( 'bad_request' === $r['body']['code'] && 'Введите адрес сайта, который хотите проверить.' === $r['body']['message'], 'ru: the endpoint answers bad_request in Russian' );
$r = $handle( '{"url":"exemplu.md","lang":"ru"}' );
cmp_assert( 'owner_required' === $r['body']['code'] && '' === cmp_ru_bad( $r['body']['message'] ), 'ru: the endpoint answers owner_required in Russian' );
$r = $handle( '{"url":"","lang":"zz","owner":1}' );
cmp_assert( 'bad_request' === $r['body']['code'] && 'Scrie adresa site-ului pe care vrei să-l verifici.' === $r['body']['message'], 'ru: the endpoint answers in Romanian for an unknown lang' );

/* ------------------------------------------------------------------
 * verifica.js in Russian, on the fake DOM.
 * ------------------------------------------------------------------ */
$cmp_ru_node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
if ( '' === $cmp_ru_node ) {
	echo "  (skip: node not found, the Russian verifica.js render is not checked)\n";
	return;
}
$cmp_ru_dom = function ( $lang, $fixture, $owner = true, $checks = 'fail' ) {
	$cmd = 'node ' . escapeshellarg( __DIR__ . '/fixtures/site-verifica/run.js' ) . ' ' . escapeshellarg( $lang ) . ' ' . escapeshellarg( $fixture ) . ' ' . ( $owner ? '1' : '0' ) . ' ' . escapeshellarg( (string) $checks );
	$out = (string) shell_exec( $cmd . ' 2>&1' );
	$r   = json_decode( $out, true );
	return is_array( $r ) ? $r : array( 'error' => 'no JSON: ' . substr( $out, 0, 300 ) );
};

/** All strings of a fixture (server data: titles, explanations, hosts, paths...). */
function cmp_ru_strings( $v, array &$out ) {
	if ( is_string( $v ) ) {
		$out[] = $v;
	} elseif ( is_array( $v ) ) {
		foreach ( $v as $x ) {
			cmp_ru_strings( $x, $out );
		}
	}
}

/**
 * Text the script wrote itself: the report's text with the fixture's strings, paths, hosts and
 * the names that stay as they are taken out. What is left must be Russian only.
 */
$cmp_ru_own = function ( $text, $fixture ) {
	$strings = array();
	cmp_ru_strings( json_decode( (string) file_get_contents( __DIR__ . '/fixtures/site-verifica/' . $fixture . '.json' ), true ), $strings );
	foreach ( $strings as $s ) {
		if ( 1 === preg_match( '#^https?://#', $s ) ) {
			$p         = parse_url( $s );
			$strings[] = isset( $p['host'] ) ? $p['host'] : '';
			$strings[] = ( isset( $p['path'] ) ? $p['path'] : '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
		}
	}
	$strings = array_merge( $strings, array( 'BiscuitWP', 'Google Fonts', 'consent.rt.md', 'https://consent.rt.md/', 'PDF', 'DevTools', 'HTTPS', 'HTTP', 'WordPress', 'standalone' ) );
	usort(
		$strings,
		function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		}
	);
	$lines = array();
	foreach ( explode( "\n", (string) $text ) as $line ) {
		foreach ( $strings as $s ) {
			if ( '' !== $s ) {
				$line = str_replace( $s, ' ', $line );
			}
		}
		$lines[] = $line;
	}
	return implode( "\n", $lines );
};
$cmp_ru_ui_leftovers = function ( $own ) {
	$bad = array();
	if ( 1 === preg_match( '/[A-Za-zăâîșțĂÂÎȘȚ]{2,}/u', $own, $m ) ) {
		$bad[] = 'Latin word left: ' . $m[0];
	}
	if ( false !== strpos( $own, "\xe2\x80\x94" ) ) {
		$bad[] = 'em dash';
	}
	return $bad;
};
$months = '(?:января|февраля|марта|апреля|мая|июня|июля|августа|сентября|октября|ноября|декабря)';

// issues-sitemap: three issues, 10 of 84 pages, a page that could not be read.
$r = $cmp_ru_dom( 'ru', 'issues-sitemap', true, '1239' );
cmp_assert( empty( $r['error'] ), 'render (ru): issues-sitemap runs without errors' . ( empty( $r['error'] ) ? '' : ': ' . $r['error'] ) );
cmp_assert( isset( $r['body']['lang'] ) && 'ru' === $r['body']['lang'] && 1 === $r['body']['owner'], 'render (ru): the request body sends lang=ru' );
$text = isset( $r['text'] ) ? $r['text'] : '';
foreach ( array( 'Мы нашли 3 проблемы', 'С чего начать', 'Проблемы', 'Нужно проверить', 'Что мы нашли', 'На каких страницах (7)', 'Почему это важно', 'Как исправить', 'Правовая основа: ', 'С BiscuitWP: ', 'В порядке', 'Полезно знать', 'Мы проверили 10 страниц. На сайте 84 страницы; мы проверили первые 10. Полная проверка сейчас недоступна.', 'Не удалось прочитать: /lent/ (не ответила вовремя).', 'BiscuitWP это исправляет', 'Скачать', 'Что он умеет' ) as $need ) {
	cmp_assert( false !== strpos( $text, $need ), "render (ru): issues-sitemap shows „{$need}”" );
}
cmp_assert_eq( isset( $r['facts'] ) ? $r['facts'] : '', '3|проблемы,1|нужно проверить,4|в порядке', 'render (ru): the counters with Russian plurals' );
cmp_assert_eq( isset( $r['buttons'] ) ? $r['buttons'] : array(), array( 'Сохранить как PDF', 'Скопировать отчёт' ), 'render (ru): the share buttons' );
cmp_assert( isset( $r['counter']['text'] ) && "1\xc2\xa0240 проверок с момента запуска" === $r['counter']['text'], 'render (ru): the counter groups thousands with a no-break space and uses the genitive plural' );
cmp_assert( 1 === preg_match( '/Отчёт «Проверьте свой сайт» с consent\.rt\.md, \d{1,2} ' . $months . ' \d{4}/u', $text ), 'render (ru): the print header with a Russian date' );
cmp_assert( isset( $r['copied'] ) && 1 === preg_match( '/Отчёт сделан с помощью «Проверьте свой сайт», https:\/\/consent\.rt\.md\/, \d{1,2} ' . $months . ' \d{4}\.$/u', (string) $r['copied'] ), 'render (ru): the copied report ends with the Russian footer' );
cmp_assert( isset( $r['shareStatus'] ) && 'Мы скопировали отчёт. Вставьте его в письмо или сообщение.' === $r['shareStatus'], 'render (ru): the copy status' );
cmp_assert_eq( $cmp_ru_ui_leftovers( $cmp_ru_own( $text, 'issues-sitemap' ) ), array(), 'render (ru): issues-sitemap has no Romanian or English UI text' );
cmp_assert_eq( $cmp_ru_ui_leftovers( $cmp_ru_own( isset( $r['copied'] ) ? $r['copied'] : '', 'issues-sitemap' ) ), array(), 'render (ru): the copied report has no Romanian or English UI text' );

// clean: nothing found, one page.
$r    = $cmp_ru_dom( 'ru', 'clean', true, '100' );
$text = isset( $r['text'] ) ? $r['text'] : '';
cmp_assert( empty( $r['error'] ), 'render (ru): clean runs without errors' . ( empty( $r['error'] ) ? '' : ': ' . $r['error'] ) );
foreach ( array( 'На проверенных страницах мы не нашли проблем.', 'Мы проверили одну страницу.', 'В порядке', 'Чего мы не видим отсюда:' ) as $need ) {
	cmp_assert( false !== strpos( $text, $need ), "render (ru): clean shows „{$need}”" );
}
cmp_assert_eq( isset( $r['facts'] ) ? $r['facts'] : '', '0|проблем,0|нужно проверить,2|в порядке', 'render (ru): clean counters (0 проблем)' );
cmp_assert( isset( $r['counter']['text'] ) && '101 проверка с момента запуска' === $r['counter']['text'], 'render (ru): 101 проверка (one form)' );
cmp_assert_eq( $cmp_ru_ui_leftovers( $cmp_ru_own( $text, 'clean' ) ), array(), 'render (ru): clean has no Romanian or English UI text' );

// unsure: one thing to check, three pages.
$r    = $cmp_ru_dom( 'ru', 'unsure', true, '121' );
$text = isset( $r['text'] ) ? $r['text'] : '';
cmp_assert( empty( $r['error'] ), 'render (ru): unsure runs without errors' . ( empty( $r['error'] ) ? '' : ': ' . $r['error'] ) );
foreach ( array( 'Мы не можем быть уверены', 'Мы проверили 3 страницы.', 'На сайте есть что-то, что может загружать трекеры', 'Нужно проверить', 'Как проверить самостоятельно' ) as $need ) {
	cmp_assert( false !== strpos( $text, $need ), "render (ru): unsure shows „{$need}”" );
}
cmp_assert_eq( isset( $r['facts'] ) ? $r['facts'] : '', '0|проблем,1|нужно проверить,1|в порядке', 'render (ru): unsure counters' );
cmp_assert( isset( $r['counter']['text'] ) && '122 проверки с момента запуска' === $r['counter']['text'], 'render (ru): 122 проверки (few form)' );
cmp_assert_eq( $cmp_ru_ui_leftovers( $cmp_ru_own( $text, 'unsure' ) ), array(), 'render (ru): unsure has no Romanian or English UI text' );

// The owner box unticked: the client-side message in Russian; "found" stats in Russian.
$found = json_encode( array( 'checks' => 160, 'found' => array( 'of' => 151, 'tracker' => 101, 'google-fonts' => 92, 'ip-resources' => 55, 'no-policy' => 28, 'no-banner' => 36 ) ) );
$r     = $cmp_ru_dom( 'ru', 'owner-required', false, $found );
cmp_assert( isset( $r['ownerError'] ) && 'Отметьте, что сайт принадлежит вам или у вас есть разрешение владельца.' === $r['ownerError'], 'render (ru): the owner error in Russian' );
cmp_assert( isset( $r['found']['text'][0] ) && 'Что мы находим чаще всего' === $r['found']['text'][0] && 'Трекер запущен до согласия' === $r['found']['text'][1], 'render (ru): the "found" block title and first row' );
cmp_assert( isset( $r['found']['text'] ) && 'Из 151 проверки, сделанной с помощью инструмента. Сайт, проверенный несколько раз, учитывается каждый раз.' === end( $r['found']['text'] ), 'render (ru): the "found" note with the genitive singular (151 проверки)' );
cmp_assert( isset( $r['counter']['text'] ) && '160 проверок с момента запуска' === $r['counter']['text'], 'render (ru): 160 проверок (many form)' );

// Russian plurals of the counter around the edges.
$bad = array();
foreach ( array( 110 => '111 проверок', 111 => '112 проверок', 113 => '114 проверок', 120 => '121 проверка', 1000 => "1\xc2\xa0001 проверка", 1003 => "1\xc2\xa0004 проверки" ) as $n => $want ) {
	$r = $cmp_ru_dom( 'ru', 'clean', true, (string) $n );
	if ( ! isset( $r['counter']['text'] ) || $want . ' с момента запуска' !== $r['counter']['text'] ) {
		$bad[] = "{$n}: " . ( isset( $r['counter']['text'] ) ? $r['counter']['text'] : '?' );
	}
}
cmp_assert_eq( $bad, array(), 'render (ru): counter plurals (11-14 many, 21 one, 4 few) and thousands' );

// Other languages unchanged by the Russian branch.
$r = $cmp_ru_dom( 'en', 'clean', true, '1239' );
cmp_assert( isset( $r['body']['lang'], $r['counter']['text'] ) && 'en' === $r['body']['lang'] && '1,240 checks so far' === $r['counter']['text'], 'render (en): still English, lang=en' );
$r = $cmp_ru_dom( 'ro', 'clean', true, '1239' );
cmp_assert( isset( $r['body']['lang'], $r['counter']['text'] ) && 'ro' === $r['body']['lang'] && '1.240 de verificări făcute până acum' === $r['counter']['text'], 'render (ro): still Romanian, lang=ro' );

// The script still names no outside address and never uses innerHTML.
$js = (string) file_get_contents( __DIR__ . '/../site/assets/verifica.js' );
cmp_assert( false === strpos( $js, 'innerHTML' ) && false === strpos( $js, 'insertAdjacentHTML' ) && false === strpos( $js, "\xe2\x80\x94" ), 'verifica.js (ru): no innerHTML, no em dash' );
cmp_assert( 0 === preg_match( '#["\'](?:https?:)?//[a-z0-9]#i', $js ), 'verifica.js (ru): no outside address in a string' );
