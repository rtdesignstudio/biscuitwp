<?php
require_once __DIR__ . '/../includes/modules/class-blocker.php';

// The bundled library: 18 services, valid, with the vendors' official loaders blocked by category.

$cmp_all = CMP_Services::library();
cmp_assert_eq( count( $cmp_all ), 18, 'library lists 18 services' );
$cmp_file = CMP_Services::load_file( __DIR__ . '/../data/services.json' );
cmp_assert_eq( $cmp_file['errors'], array(), 'library passes validate()' );

$cmp_ids = array();
foreach ( $cmp_all as $cmp_s ) {
	$cmp_ids[ $cmp_s['id'] ] = $cmp_s;
}
foreach ( $cmp_ids as $cmp_id => $cmp_s ) {
	cmp_assert( '' !== $cmp_s['provider'] && '' !== $cmp_s['privacy_url'] && '' !== $cmp_s['transfer'], "{$cmp_id}: provider, transfer and privacy_url are set" );
	foreach ( $cmp_s['cookies'] as $cmp_c ) {
		$ok = true;
		foreach ( array( 'duration', 'purpose' ) as $cmp_k ) {
			foreach ( array( 'ro', 'ru', 'en' ) as $cmp_l ) {
				$ok = $ok && ! empty( $cmp_c[ $cmp_k ][ $cmp_l ] );
			}
		}
		cmp_assert( $ok && '' !== $cmp_c['name'], "{$cmp_id}: cookie {$cmp_c['name']} has ro/ru/en duration and purpose" );
	}
}

// id => category, snippet(s) that must be tagged.
$cmp_cases = array(
	'clarity'          => array( 'statistics', array(
		'<script src="https://www.clarity.ms/tag/abc123"></script>',
		'<script type="text/javascript">(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){};t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window, document, "clarity", "script", "abc123");</script>',
	) ),
	'hotjar'           => array( 'statistics', array(
		'<script src="https://static.hotjar.com/c/hotjar-123.js?sv=6"></script>',
		'<script>(function(h,o,t,j,a,r){h.hj=h.hj||function(){};h._hjSettings={hjid:123,hjsv:6};a=o.getElementsByTagName("head")[0];r=o.createElement("script");r.async=1;r.src=t+h._hjSettings.hjid+j+h._hjSettings.hjsv;a.appendChild(r);})(window,document,"https://static.hotjar.com/c/hotjar-",".js?sv=");</script>',
	) ),
	'tiktok-pixel'     => array( 'marketing', array(
		'<script src="https://analytics.tiktok.com/i18n/pixel/events.js?sdkid=X"></script>',
		"<script>!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.load('XYZ');ttq.page();}(window,document,'ttq');</script>",
	) ),
	'linkedin-insight' => array( 'marketing', array(
		'<script src="https://snap.licdn.com/li.lms-analytics/insight.min.js"></script>',
		'<script type="text/javascript">_linkedin_partner_id = "12345"; window._linkedin_data_partner_ids = window._linkedin_data_partner_ids || []; window._linkedin_data_partner_ids.push(_linkedin_partner_id);</script>',
	) ),
	'hubspot'          => array( 'marketing', array(
		'<script type="text/javascript" id="hs-script-loader" async defer src="//js.hs-scripts.com/12345.js"></script>',
		'<script src="https://js.hs-analytics.net/analytics/1/12345.js"></script>',
		'<script src="https://js.hsadspixel.net/fb.js"></script>',
		'<script src="https://js.hs-banner.com/12345.js"></script>',
		'<script>var _hsq = window._hsq = window._hsq || []; _hsq.push(["setPath", "/"]);</script>',
	) ),
	'tawk-to'          => array( 'marketing', array(
		'<script src="https://embed.tawk.to/abc/default"></script>',
		"<script>var Tawk_API=Tawk_API||{}, Tawk_LoadStart=new Date();(function(){var s1=document.createElement('script');s1.src='https://embed.tawk.to/abc/default';})();</script>",
	) ),
	'crisp'            => array( 'marketing', array(
		'<script src="https://client.crisp.chat/l.js"></script>',
		'<script>window.$crisp=[];window.CRISP_WEBSITE_ID="abc";(function(){d=document;s=d.createElement("script");s.src="https://client.crisp.chat/l.js";})();</script>',
	) ),
);
foreach ( $cmp_cases as $cmp_id => $cmp_case ) {
	cmp_assert( isset( $cmp_ids[ $cmp_id ] ) && $cmp_case[0] === $cmp_ids[ $cmp_id ]['category'], "{$cmp_id}: category is {$cmp_case[0]}" );
	foreach ( $cmp_case[1] as $cmp_n => $cmp_html ) {
		$cmp_o = CMP_Blocker::rewrite( $cmp_html );
		cmp_assert( false !== strpos( $cmp_o, 'data-cmp-service="' . $cmp_id . '"' ), "{$cmp_id}: official snippet #{$cmp_n} is tagged" );
		cmp_assert( false !== strpos( $cmp_o, 'data-cmp-block="' . $cmp_case[0] . '"' ), "{$cmp_id}: official snippet #{$cmp_n} blocked as {$cmp_case[0]}" );
	}
}

// reCAPTCHA is necessary: never rewritten, and matched by path, not by all of google.com.
cmp_assert( isset( $cmp_ids['recaptcha'] ) && 'necessary' === $cmp_ids['recaptcha']['category'], 'recaptcha: category is necessary' );
foreach ( array(
	'<script src="https://www.google.com/recaptcha/api.js" async defer></script>',
	'<script src="https://www.google.com/recaptcha/api.js?render=KEY"></script>',
	'<script src="https://www.gstatic.com/recaptcha/releases/x/recaptcha__en.js"></script>',
) as $cmp_html ) {
	cmp_assert_eq( CMP_Blocker::rewrite( $cmp_html ), $cmp_html, 'recaptcha stays unchanged: ' . $cmp_html );
}
$cmp_rs = CMP_Services::script_service( 'https://www.google.com/recaptcha/api.js', '', $cmp_all );
cmp_assert( null !== $cmp_rs && 'recaptcha' === $cmp_rs['id'], 'recaptcha script is recognised as recaptcha' );
cmp_assert_eq( CMP_Services::script_service( 'https://www.google.com/other.js', '', $cmp_all ), null, 'other google.com scripts do not match any service' );

// Specific markers: loose words never match.
$cmp_loose = '<script>// clarity' . "\n" . 'var hotjar = 1;</script>';
cmp_assert_eq( CMP_Blocker::rewrite( $cmp_loose ), $cmp_loose, 'bare words clarity/hotjar do not trigger blocking' );

// New services do not steal the existing ones' snippets.
$cmp_own = array(
	'google-analytics'   => '<script src="https://www.google-analytics.com/analytics.js"></script>',
	'google-tag-manager' => '<script src="https://www.googletagmanager.com/gtm.js?id=GTM-X"></script>',
	'meta-pixel'         => '<script src="https://connect.facebook.net/en_US/fbevents.js"></script>',
);
foreach ( $cmp_own as $cmp_id => $cmp_html ) {
	cmp_assert( false !== strpos( CMP_Blocker::rewrite( $cmp_html ), 'data-cmp-service="' . $cmp_id . '"' ), "{$cmp_id} is still tagged {$cmp_id}" );
}

// Regional and alternate loaders.
foreach ( array(
	'hubspot'          => array( 'https://js-eu1.hs-scripts.com/123.js', 'https://js-na2.hs-analytics.net/analytics/1/123.js', 'https://js-eu1.hs-banner.com/123.js' ),
	'hotjar'           => array( 'https://t.contentsquare.net/uxa/abc123.js', 'https://tags.contentsquare.net/x.js' ),
	'tiktok-pixel'     => array( 'https://analytics-sg.tiktok.com/i18n/pixel/events.js?sdkid=X' ),
) as $cmp_id => $cmp_urls ) {
	foreach ( $cmp_urls as $cmp_u ) {
		$cmp_o = CMP_Blocker::rewrite( '<script src="' . $cmp_u . '"></script>' );
		cmp_assert( false !== strpos( $cmp_o, 'data-cmp-service="' . $cmp_id . '"' ), "{$cmp_id}: regional/alternate loader {$cmp_u} is tagged" );
	}
}
$cmp_ev = '<script src="https://evil-hs-scripts.com/x.js"></script><script src="https://notcontentsquare.net/x.js"></script>';
cmp_assert_eq( CMP_Blocker::rewrite( $cmp_ev ), $cmp_ev, 'look-alike hosts are not matched' );
