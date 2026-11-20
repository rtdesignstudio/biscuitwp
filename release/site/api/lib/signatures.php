<?php
if ( ! defined( 'CMP_CHECK' ) ) {
	http_response_code( 404 );
	exit;
}
/**
 * Consent Manager Pro, "Verifică-ți site-ul": signatures of consent banners
 * (CMPs), tag loaders and the markers CMPs use to hold a script until consent.
 * Data only. Every needle is lowercase and matched case-insensitively.
 */

/**
 * Known CMPs, in detection order (ours first). Each needle is a plain substring
 * of the page HTML: plugin paths, CDN hosts and attributes specific to that
 * product, never generic words that could appear in an article.
 * Consent Manager Pro's standalone build (a same-site cmp.js / cmp.min.js) is
 * matched separately in check.php.
 */
function cmp_check_cmp_signatures() {
	return array(
		array(
			'slug'    => 'consent-manager-pro',
			'name'    => 'Consent Manager Pro',
			'needles' => array( '/wp-content/plugins/consent-manager-pro/', 'data-cmp-action', 'id="cmp-overlay"', 'data-cmp-block=', 'data-cmp-embed-src' ),
		),
		array(
			'slug'    => 'complianz',
			'name'    => 'Complianz',
			'needles' => array( '/wp-content/plugins/complianz-gdpr', 'cmplz-cookiebanner', 'cmplz-script', 'cmplz_banner' ),
		),
		array(
			'slug'    => 'cookieyes',
			'name'    => 'CookieYes',
			'needles' => array( 'cdn-cookieyes.com', 'cky-consent-container', 'data-cookieyes=' ),
		),
		array(
			'slug'    => 'cookiebot',
			'name'    => 'Cookiebot',
			'needles' => array( 'consent.cookiebot.com', 'consent.cookiebot.eu', 'data-cookieconsent=', 'data-cbid=' ),
		),
		array(
			'slug'    => 'cookielawinfo',
			'name'    => 'GDPR Cookie Consent (CookieLawInfo)',
			'needles' => array( '/wp-content/plugins/cookie-law-info/', 'cookie-law-info-bar', 'cookielawinfo-checkbox' ),
		),
		array(
			'slug'    => 'cookie-notice',
			'name'    => 'Cookie Notice',
			'needles' => array( '/wp-content/plugins/cookie-notice/', 'cookie-notice-front', 'cdn.hu-manity.co' ),
		),
		array(
			'slug'    => 'iubenda',
			'name'    => 'Iubenda',
			'needles' => array( 'cdn.iubenda.com', 'cs.iubenda.com', '_iub_cs_activate' ),
		),
		array(
			'slug'    => 'onetrust',
			'name'    => 'OneTrust',
			'needles' => array( 'cdn.cookielaw.org', 'optanon-category-', 'otsdkstub', 'cookie-cdn.cookiepro.com' ),
		),
		array(
			'slug'    => 'usercentrics',
			'name'    => 'Usercentrics',
			'needles' => array( 'app.usercentrics.eu', 'web.cmp.usercentrics.eu', 'data-usercentrics=' ),
		),
		array(
			'slug'    => 'borlabs',
			'name'    => 'Borlabs Cookie',
			'needles' => array( '/wp-content/plugins/borlabs-cookie/', 'borlabs-cookie-script-blocker', 'brlbs-cmpnt' ),
		),
		array(
			'slug'    => 'termly',
			'name'    => 'Termly',
			'needles' => array( 'app.termly.io' ),
		),
		array(
			'slug'    => 'osano',
			'name'    => 'Osano',
			'needles' => array( 'cmp.osano.com' ),
		),
		array(
			'slug'    => 'klaro',
			'name'    => 'Klaro',
			'needles' => array( 'klaro.js', 'klaro.min.js', 'klaro-no-css', 'cdn.kiprotect.com/klaro' ),
		),
		array(
			'slug'    => 'real-cookie-banner',
			'name'    => 'Real Cookie Banner',
			'needles' => array( '/wp-content/plugins/real-cookie-banner' ),
		),
		array(
			'slug'    => 'moove-gdpr',
			'name'    => 'GDPR Cookie Compliance (Moove)',
			'needles' => array( '/wp-content/plugins/gdpr-cookie-compliance/', 'moove_gdpr_cookie' ),
		),
		array(
			'slug'    => 'didomi',
			'name'    => 'Didomi',
			'needles' => array( 'sdk.privacy-center.org' ),
		),
		array(
			'slug'    => 'quantcast',
			'name'    => 'Quantcast Choice',
			'needles' => array( 'cmp.quantcast.com', 'quantcast.mgr.consensu.org' ),
		),
		array(
			'slug'    => 'trustarc',
			'name'    => 'TrustArc',
			'needles' => array( 'consent.trustarc.com' ),
		),
		array(
			'slug'    => 'cookiefirst',
			'name'    => 'CookieFirst',
			'needles' => array( 'consent.cookiefirst.com' ),
		),
		array(
			'slug'    => 'cookie-script',
			'name'    => 'Cookie-Script',
			'needles' => array( 'cdn.cookie-script.com' ),
		),
	);
}

/**
 * Tag loaders: scripts that load other scripts we cannot see from the HTML.
 * 'src': needles in a script URL (host + path + query); 'body': needles in an
 * inline script. Google Tag Manager's inline snippet is also caught by the
 * plugin's own rule (GTM- plus dataLayer, see check.php).
 */
function cmp_check_loader_signatures() {
	return array(
		array(
			'slug' => 'gtm',
			'name' => 'Google Tag Manager',
			'src'  => array( 'googletagmanager.com/gtm.js', 'googletagmanager.com/gtag/js?id=gtm-', '/gtm.js?id=' ),
			'body' => array( 'googletagmanager.com/gtm.js', '/gtm.js?id=' ),
		),
		array(
			'slug' => 'segment',
			'name' => 'Segment',
			'src'  => array( 'cdn.segment.com/analytics.js', 'cdn.segment.io/analytics.js' ),
			'body' => array( 'cdn.segment.com/analytics.js', 'cdn.segment.io/analytics.js' ),
		),
		array(
			'slug' => 'tealium',
			'name' => 'Tealium iQ',
			'src'  => array( 'tags.tiqcdn.com/utag/' ),
			'body' => array( 'tags.tiqcdn.com/utag/' ),
		),
		array(
			'slug' => 'adobe-launch',
			'name' => 'Adobe Experience Platform Tags',
			'src'  => array( 'assets.adobedtm.com/' ),
			'body' => array( 'assets.adobedtm.com/' ),
		),
		array(
			'slug' => 'matomo-tag-manager',
			'name' => 'Matomo Tag Manager',
			'src'  => array(),
			'body' => array( 'var _mtm', '_mtm.push(' ),
		),
		array(
			'slug' => 'piwik-pro',
			'name' => 'Piwik PRO Tag Manager',
			'src'  => array( '.containers.piwik.pro/', 'containers.piwik.pro/' ),
			'body' => array( '.containers.piwik.pro/', 'containers.piwik.pro/' ),
		),
		array(
			'slug' => 'ensighten',
			'name' => 'Ensighten',
			'src'  => array( 'nexus.ensighten.com/' ),
			'body' => array( 'nexus.ensighten.com/' ),
		),
		array(
			'slug' => 'commanders-act',
			'name' => 'Commanders Act',
			'src'  => array( 'cdn.tagcommander.com/' ),
			'body' => array( 'cdn.tagcommander.com/' ),
		),
		array(
			'slug' => 'rudderstack',
			'name' => 'RudderStack',
			'src'  => array( 'cdn.rudderlabs.com/' ),
			'body' => array( 'cdn.rudderlabs.com/' ),
		),
		array(
			'slug' => 'zaraz',
			'name' => 'Cloudflare Zaraz',
			'src'  => array( '/cdn-cgi/zaraz/' ),
			'body' => array( '/cdn-cgi/zaraz/' ),
		),
	);
}

/**
 * Markers a CMP puts on a <script> (or iframe) to hold it until consent, when
 * the type alone does not already make it inert. 'attrs': attribute names;
 * 'classes': class tokens (a trailing '*' is a prefix). A marker whose value
 * says "always run" (see cmp_check_run_values) does not hold anything.
 *
 * data-cmp-block and data-cmp-category (ours) are deliberately absent: our
 * banner only activates type="text/plain" + data-cmp-block scripts, and
 * data-cmp-category is an input hint for the plugin's server-side rewrite, so a
 * script that still carries it with a JavaScript type runs right away.
 */
function cmp_check_defer_markers() {
	return array(
		'attrs'   => array( 'data-cookieconsent', 'data-category', 'data-cookiecategory', 'data-cookie-consent', 'data-cookieyes', 'data-usercentrics' ),
		'classes' => array( 'cmplz-script', '_iub_cs_activate', 'optanon-category-*' ),
	);
}

/** Marker values meaning "run without consent" (Cookiebot "ignore", necessary categories). */
function cmp_check_run_values() {
	return array( 'ignore', 'necessary', 'essential', 'required', 'strictly-necessary', 'strictly_necessary', 'strictlynecessary', 'cookieyes-necessary', 'optanon-category-c0001' );
}

/**
 * Script types a browser never runs as code: data, templates, and the
 * text/plain that CMPs use to hold a script. Anything else that is not
 * JavaScript and not a known delay loader (cmp_check_live_types) is "unknown".
 */
function cmp_check_inert_types() {
	return array(
		'text/plain',
		'text/template',
		'text/html',
		'text/x-template',
		'text/x-handlebars-template',
		'text/x-handlebars',
		'text/x-mustache',
		'text/x-jquery-tmpl',
		'text/x-tmpl',
		'text/ng-template',
		'text/x-magento-template',
		'text/x-magento-init',
		'text/markdown',
		'text/css',
		'x-shader/x-vertex',
		'x-shader/x-fragment',
		'application/json',
		'application/ld+json',
		'application/xml',
		'importmap',
		'speculationrules',
	);
}

/**
 * Types that do run, besides the JavaScript MIME types: delay loaders start the
 * script on the first interaction or when the page is idle, never on consent.
 * - litespeed/javascript (LiteSpeed Cache), rocketlazyloadscript (WP Rocket);
 * - <hash>-text/javascript (Cloudflare Rocket Loader, matched as a suffix);
 * - text/partytown (Partytown, runs in a web worker);
 * - pmdelayedscript (Perfmatters), javascript/blocked (WP Meteor);
 * - module-shim (es-module-shims runs it as a module).
 */
function cmp_check_live_types() {
	return array( 'litespeed/javascript', 'rocketlazyloadscript', 'text/rocketlazyloadscript', 'text/partytown', 'pmdelayedscript', 'javascript/blocked', 'module-shim' );
}

/** Attributes that hold the URL of a script a delay loader (or a lazy loader) will load. */
function cmp_check_script_src_attrs() {
	return array( 'src', 'data-src', 'data-litespeed-src', 'data-rocket-src', 'data-wpmeteor-src', 'data-pmdelayedscript' );
}

/**
 * Trackers missing from data/services.json, kept here so the plugin's library
 * stays untouched. Same shape as a library service, plus:
 * - 'name' per language (ro / en);
 * - 'extend': the id of a library service to add hosts / inline markers /
 *   cookies to (the finding keeps that service's id), instead of a new service;
 * - 'src_re': regexes on a full script / image URL (checked before hosts);
 * - 'inline_re': regexes on an inline body, after cmp_check_norm_js().
 */
function cmp_check_extra_services() {
	return array(
		array(
			'id'        => 'google-ads',
			'name'      => array(
				'ro' => 'Google Ads',
				'en' => 'Google Ads',
			),
			'type'      => 'script',
			'category'  => 'marketing',
			'hosts'     => array( 'googleadservices.com', 'doubleclick.net' ),
			'src_re'    => array( '#googletagmanager\.com/gtag/js\?(?:[^\#]*&)?id=(?:AW|DC)-#i' ),
			'inline_re' => array( '#gtag\([\'"]config[\'"],[\'"](?:AW|DC)-#', '#googletagmanager\.com/gtag/js\?id=(?:AW|DC)-#i' ),
			'cookies'   => array( '_gcl_au', '_gcl_aw', 'IDE', 'test_cookie' ),
		),
		array(
			'id'        => 'yandex-metrica',
			'name'      => array(
				'ro' => 'Yandex Metrica',
				'en' => 'Yandex Metrica',
			),
			'type'      => 'script',
			'category'  => 'statistics',
			'hosts'     => array( 'mc.yandex.ru', 'mc.yandex.com', 'mc.yandex.by', 'mc.yandex.kz', 'mc.yandex.com.tr', 'mc.webvisor.org' ),
			'inline_re' => array( '#\bym\(\d+,[\'"]init[\'"]#' ),
			'cookies'   => array( '_ym_uid', '_ym_d', '_ym_isad', '_ym_visorc' ),
		),
		array(
			'extend' => 'meta-pixel',
			'hosts'  => array( 'facebook.com/tr' ),
		),
		array(
			'extend' => 'matomo',
			'inline' => array( '_paq.push(', 'var _paq' ),
		),
	);
}
