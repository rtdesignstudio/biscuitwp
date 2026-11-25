<?php
/**
 * Lot A fix wave: the WordPress side of the new-domain email, of ignore /
 * unignore, of the post-update note and of the Servicii row, each in its own
 * PHP process with WordPress-like options and object cache
 * (tests/fixtures/lot-a-request.php). No mail leaves: wp_mail is recorded.
 */
require_once __DIR__ . '/../includes/class-services.php';
require_once __DIR__ . '/../includes/modules/class-scanner.php';
$lr_run = function ( array $in ) {
	$cmd = escapeshellarg( PHP_BINARY ) . ' -d error_reporting=-1 -d display_errors=1 ' . escapeshellarg( __DIR__ . '/fixtures/lot-a-request.php' ) . ' ' . escapeshellarg( json_encode( $in ) ) . ' 2>&1';
	$raw = (string) shell_exec( $cmd );
	$r   = json_decode( $raw, true );
	return is_array( $r ) ? $r : array( 'log' => array(), 'db' => array(), 'autoload' => array(), 'out' => '', 'result' => 'BROKEN: ' . substr( $raw, 0, 400 ) );
};
$lr_of = function ( array $r, $kind ) {
	return array_values( array_filter( $r['log'], function ( $e ) use ( $kind ) {
		return $kind === $e[0];
	} ) );
};
$lr_home  = 'https://example.md/';
$lr_cats  = array(
	array( 'slug' => 'necessary', 'label' => 'Necesare', 'required' => true ),
	array( 'slug' => 'statistics', 'label' => 'Statistici' ),
	array( 'slug' => 'marketing', 'label' => 'Marketing' ),
);
$lr_state = array( 'known' => array( 'old.com' ), 'ignored' => array(), 'pending' => array(), 'sending' => array(), 'mailed_at' => 0, 'failed_at' => 0, 'base' => true, 'silent' => array() );
$lr_opts  = array(
	'cmp_settings'      => array( 'mode' => 'pro', 'block_scripts' => true, 'alert_email_on' => true, 'alert_email' => 'owner@agency.md' ),
	'cmp_categories'    => $lr_cats,
	'admin_email'       => 'client@site.md',
	'blogname'          => 'Smith &amp; Sons',
	'cmp_unknown_hosts' => $lr_state,
);
$lr_page = '<html><body><script src="https://old.com/a.js"></script><script src="https://new.vendor.com/t.js"></script><script src="https://cdn.partner.md/p.js"></script></body></html>';
$lr_base = array( 'scenario' => 'scan', 'options' => $lr_opts, 'pages' => array( $lr_home => $lr_page ) );
$lr_with = function ( array $over, array $opts = array() ) use ( $lr_base ) {
	$in            = array_merge( $lr_base, $over );
	$in['options'] = array_merge( $in['options'], $opts );
	return $in;
};

// ---- A whole scan through cron: who gets the mail, in what language, with what left behind (I7, M1) ----
foreach ( array( 'no object cache' => false, 'persistent object cache' => true ) as $lr_n => $lr_p ) {
	$r = $lr_run( $lr_with( array( 'persistent' => $lr_p ) ) );
	$m = $lr_of( $r, 'mail' );
	cmp_assert_eq( $r['result'], 'idle', "scan ($lr_n): the run ends" );
	cmp_assert_eq( count( $m ), 1, "scan ($lr_n): one mail" );
	cmp_assert_eq( $m ? $m[0][1] : '', 'owner@agency.md', "scan ($lr_n): to the configured address, not to the admin" );
	cmp_assert( $m && false !== strpos( $m[0][3], 'new.vendor.com' ) && false === strpos( $m[0][3], 'old.com' ), "scan ($lr_n): names the new host only" );
	cmp_assert( $m && false !== strpos( $m[0][2], 'Smith & Sons' ) && false === strpos( $m[0][2] . $m[0][3], '&amp;' ), "scan ($lr_n): the site name without entities (M1)" );
	cmp_assert( $m && true === $m[0][4], "scan ($lr_n): the lock is held while the mail goes out" );
	cmp_assert( ! isset( $r['db']['cmp_unknown_hosts_lock'] ), "scan ($lr_n): the lock is removed afterwards" );
	cmp_assert_eq( isset( $r['autoload']['cmp_unknown_hosts'] ) ? $r['autoload']['cmp_unknown_hosts'] : null, false, "scan ($lr_n): the state is not autoloaded" );
	cmp_assert( isset( $r['autoload']['cmp_unknown_hosts_lock'] ) && false === $r['autoload']['cmp_unknown_hosts_lock'], "scan ($lr_n): the lock is not autoloaded" );
	cmp_assert( $r['db']['cmp_unknown_hosts']['mailed_at'] > 0 && array() === $r['db']['cmp_unknown_hosts']['pending'], "scan ($lr_n): the outcome is stored" );
	cmp_assert_eq( $lr_of( $r, 'locale' ), array( array( 'locale', 'ru_RU' ) ), "scan ($lr_n): the mail is written in the site language" );
	cmp_assert_eq( count( $lr_of( $r, 'restore' ) ), 1, "scan ($lr_n): the language is restored" );
	cmp_assert_eq( $lr_of( $r, 'action' ), array( array( 'action', 'cmp_scan_finished', 'array' ) ), "scan ($lr_n): the action fires once, with the result" );
}
$r = $lr_run( $lr_with( array( 'locale' => 'en_US' ) ) );
cmp_assert_eq( $lr_of( $r, 'locale' ), array( array( 'locale', 'en_US' ) ), 'scan: another site language' );
$r = $lr_run( $lr_with( array(), array( 'cmp_settings' => array( 'mode' => 'pro', 'block_scripts' => true, 'alert_email_on' => true, 'alert_email' => '' ) ) ) );
$m = $lr_of( $r, 'mail' );
cmp_assert_eq( $m ? $m[0][1] : '', 'client@site.md', 'scan: an empty address is the admin one' );
foreach ( array( 'off' => array( 'alert_email_on' => false, 'alert_email' => 'owner@agency.md' ), 'an updated install (no key)' => array() ) as $lr_n => $lr_s ) {
	$r = $lr_run( $lr_with( array(), array( 'cmp_settings' => array_merge( array( 'mode' => 'pro', 'block_scripts' => true ), $lr_s ) ) ) );
	cmp_assert_eq( $lr_of( $r, 'mail' ), array(), "scan, $lr_n: no mail" );
	cmp_assert( in_array( 'new.vendor.com', $r['db']['cmp_unknown_hosts']['known'], true ), "scan, $lr_n: the host is recorded as known" );
}
// A typo stored by hand (or by another tool) never turns into a mail to the admin.
$r = $lr_run( $lr_with( array(), array( 'cmp_settings' => array( 'mode' => 'pro', 'block_scripts' => true, 'alert_email_on' => true, 'alert_email' => 'owner@' ) ) ) );
cmp_assert_eq( $lr_of( $r, 'mail' ), array(), 'scan: a stored address that is not valid sends nothing, not to the admin either (I3)' );
// A service that covers the host (custom, or an IP resource) is no news.
$r = $lr_run( $lr_with( array(), array( 'cmp_services' => array( 'custom' => array( array( 'id' => 'custom-1', 'name' => 'V', 'category' => 'marketing', 'hosts' => array( 'vendor.com', 'cdn.partner.md' ) ) ) ) ) ) );
cmp_assert_eq( $lr_of( $r, 'mail' ), array(), 'scan: a host of a custom service is not mailed' );
$lr_pend = array_merge( $lr_state, array( 'pending' => array( 'cdn.jsdelivr.net' => array( '/' ), 'vendor.com' => array( '/' ) ) ) );
$r = $lr_run( $lr_with( array( 'pages' => array( $lr_home => '<p>x</p>' ) ), array( 'cmp_unknown_hosts' => $lr_pend, 'cmp_services' => array( 'custom' => array( array( 'id' => 'custom-1', 'name' => 'V', 'category' => 'marketing', 'hosts' => array( 'vendor.com' ) ) ) ) ) ) );
cmp_assert_eq( $lr_of( $r, 'mail' ), array(), 'scan: pending hosts covered since (a service, a CDN resource) are dropped, not mailed' );
cmp_assert( CMP_Scanner::host_known( 'cdn.jsdelivr.net', array(), $lr_home ) && CMP_Scanner::host_known( '2.gravatar.com', array(), $lr_home ) && ! CMP_Scanner::host_known( 'x.vendor.com', array(), $lr_home ), 'host_known: the IP resources are known without any service' );
// Failure, nothing read, a busy lock.
$r = $lr_run( $lr_with( array( 'mail' => false ) ) );
cmp_assert( isset( $r['db']['cmp_unknown_hosts']['pending']['new.vendor.com'] ) && 0 === $r['db']['cmp_unknown_hosts']['mailed_at'] && $r['db']['cmp_unknown_hosts']['failed_at'] > 0, 'scan: a refused mail keeps the host pending' );
cmp_assert( ! isset( $r['db']['cmp_unknown_hosts_lock'] ), 'scan: ... and releases the lock' );
$r = $lr_run( $lr_with( array( 'pages' => array() ) ) );
cmp_assert_eq( $lr_of( $r, 'action' ), array(), 'scan: nothing could be read, the action does not fire' );
cmp_assert( $r['db']['cmp_unknown_hosts'] === $lr_state, 'scan: ... and the state is untouched' );
$r = $lr_run( $lr_with( array(), array( 'cmp_unknown_hosts_lock' => time() - 10 ) ) );
cmp_assert( array() === $lr_of( $r, 'mail' ) && $r['db']['cmp_unknown_hosts'] === $lr_state && isset( $r['db']['cmp_unknown_hosts_lock'] ), 'scan: a lock taken 10 seconds ago is respected' );
$r = $lr_run( $lr_with( array(), array( 'cmp_unknown_hosts_lock' => time() - 400 ) ) );
cmp_assert( 1 === count( $lr_of( $r, 'mail' ) ) && ! isset( $r['db']['cmp_unknown_hosts_lock'] ), 'scan: a lock left by a run that died is taken over' );
// The baseline waits for a run that read the sitemap (M4).
$lr_nostate = $lr_opts;
unset( $lr_nostate['cmp_unknown_hosts'] );
$r = $lr_run( array( 'scenario' => 'inline', 'no_cron' => true, 'options' => $lr_nostate, 'pages' => array( $lr_home => $lr_page ) ) );
cmp_assert_eq( $r['result'], 'idle', 'inline run: ends' );
cmp_assert( ! isset( $r['db']['cmp_unknown_hosts'] ) && array() === $lr_of( $r, 'mail' ) && ! isset( $r['db']['cmp_unknown_hosts_lock'] ), 'inline run without the sitemap: no baseline yet, no mail, no lock left' );
$r = $lr_run( array( 'scenario' => 'scan', 'options' => $lr_nostate, 'pages' => array( $lr_home => $lr_page ) ) );
cmp_assert( isset( $r['db']['cmp_unknown_hosts'] ) && in_array( 'new.vendor.com', $r['db']['cmp_unknown_hosts']['known'], true ) && array() === $lr_of( $r, 'mail' ), 'cron run: the baseline, nothing mailed' );
$r = $lr_run( array( 'scenario' => 'inline', 'no_cron' => true, 'options' => $lr_opts, 'pages' => array( $lr_home => $lr_page ) ) );
cmp_assert_eq( count( $lr_of( $r, 'mail' ) ), 1, 'inline run after the baseline: a new host is mailed' );

// ---- ignore / unignore on cached copies (I6) ----
foreach ( array( 'no object cache' => false, 'persistent object cache' => true ) as $lr_n => $lr_p ) {
	$lr_c = array( 'scenario' => 'change', 'op' => 'ignore', 'host' => 'old.com', 'persistent' => $lr_p, 'options' => $lr_opts );
	// Another process finished a scan after this request had read the state.
	$lr_new = array_merge( $lr_state, array( 'known' => array( 'old.com', 'n.com' ), 'mailed_at' => 1700000000 ) );
	$r = $lr_run( array_merge( $lr_c, array( 'steps' => array( array( 'set', 'cmp_unknown_hosts', $lr_new ) ) ) ) );
	cmp_assert_eq( $r['result'], array( 'done', true ), "change ($lr_n): done" );
	cmp_assert_eq( $r['db']['cmp_unknown_hosts']['mailed_at'], 1700000000, "change ($lr_n): the result of the scan that finished meanwhile is kept" );
	cmp_assert_eq( $r['db']['cmp_unknown_hosts']['ignored'], array( 'old.com' ), "change ($lr_n): and the click is applied" );
	cmp_assert( ! isset( $r['db']['cmp_unknown_hosts_lock'] ), "change ($lr_n): the lock is released" );
	// "No such option" was cached for the lock; then another process took it.
	$r = $lr_run( array_merge( $lr_c, array( 'read_lock' => true, 'steps' => array( array( 'set', 'cmp_unknown_hosts_lock', time() ) ) ) ) );
	cmp_assert_eq( $r['result'], array( 'busy', true ), "change ($lr_n): a lock taken after this request cached its absence is seen, at once" );
	cmp_assert( $r['db']['cmp_unknown_hosts'] === $lr_state && isset( $r['db']['cmp_unknown_hosts_lock'] ), "change ($lr_n): busy writes nothing and leaves the lock" );
	// The lock was cached as held; the other process released it since.
	$r = $lr_run( array_merge( $lr_c, array( 'read_lock' => true, 'options' => array_merge( $lr_opts, array( 'cmp_unknown_hosts_lock' => time() ) ), 'steps' => array( array( 'delete', 'cmp_unknown_hosts_lock' ) ) ) ) );
	cmp_assert_eq( $r['result'], array( 'done', true ), "change ($lr_n): a lock released after this request cached it is not seen as held" );
	// Held for real: one try, no waiting.
	$r = $lr_run( array_merge( $lr_c, array( 'options' => array_merge( $lr_opts, array( 'cmp_unknown_hosts_lock' => time() ) ) ) ) );
	cmp_assert_eq( $r['result'], array( 'busy', true ), "change ($lr_n): a held lock is busy at once, no waiting" );
	$r = $lr_run( array_merge( $lr_c, array( 'op' => 'unignore', 'options' => array_merge( $lr_opts, array( 'cmp_unknown_hosts' => array_merge( $lr_state, array( 'ignored' => array( 'old.com' ) ) ) ) ) ) ) );
	cmp_assert_eq( $r['db']['cmp_unknown_hosts']['ignored'], array(), "change ($lr_n): unignore" );
	// The scan listener reads fresh too: an ignore clicked while the pages were fetched is kept.
	$r = $lr_run( array( 'scenario' => 'fire', 'persistent' => $lr_p, 'options' => $lr_opts, 'agg' => array( 'pages' => 1, 'unknown_pages' => array( '/' => array( 'new.vendor.com' ) ), 'unread' => array(), 'sitemap' => true ), 'steps' => array( array( 'set', 'cmp_unknown_hosts', array_merge( $lr_state, array( 'ignored' => array( 'new.vendor.com' ) ) ) ) ) ) );
	cmp_assert( array() === $lr_of( $r, 'mail' ) && array( 'new.vendor.com' ) === $r['db']['cmp_unknown_hosts']['ignored'], "scan listener ($lr_n): works on a fresh read" );
}
$r = $lr_run( array( 'scenario' => 'scan', 'options' => array_merge( $lr_opts, array( 'cmp_unknown_hosts' => array_merge( $lr_state, array( 'ignored' => array( 'new.vendor.com' ) ) ) ) ), 'pages' => array( $lr_home => $lr_page ) ) );
cmp_assert( array( 'new.vendor.com' ) === $r['db']['cmp_unknown_hosts']['ignored'] && 1 === count( $lr_of( $r, 'mail' ) ) && false === strpos( $lr_of( $r, 'mail' )[0][3], 'new.vendor.com' ), 'scan: an ignored host is not in the mail' );

// ---- The notice after ignore / unignore says what happened (M5) ----
$lr_agg = array( 'pages' => 1, 'unknown_pages' => array( '/' => array( 'new.vendor.com' ) ) );
$lr_act = function ( $sc, $host, array $opts = array() ) use ( $lr_run, $lr_of, $lr_opts, $lr_agg ) {
	$r = $lr_run( array( 'scenario' => $sc, 'host' => $host, 'options' => array_merge( $lr_opts, array( 'cmp_scan_aggregate' => $lr_agg ), $opts ) ) );
	$d = $lr_of( $r, 'redirect' );
	return array( $d && preg_match( '/cmp_domain=([a-z]+)/', $d[0][1], $mm ) ? $mm[1] : '?', $r['db']['cmp_unknown_hosts'] );
};
$x = $lr_act( 'ignore', 'new.vendor.com' );
cmp_assert( 'ignored' === $x[0] && array( 'new.vendor.com' ) === $x[1]['ignored'], 'ignore: a listed host' );
$x = $lr_act( 'ignore', 'nope.com' );
cmp_assert( 'refused' === $x[0] && $x[1] === $lr_state, 'ignore: a host that is not listed is refused and the notice says so' );
$x = $lr_act( 'ignore', 'new.vendor.com', array( 'cmp_unknown_hosts_lock' => time() ) );
cmp_assert( 'busy' === $x[0] && $x[1] === $lr_state, 'ignore: busy' );
$x = $lr_act( 'unignore', 'old.com', array( 'cmp_unknown_hosts' => array_merge( $lr_state, array( 'ignored' => array( 'old.com' ) ) ) ) );
cmp_assert( 'restored' === $x[0] && array() === $x[1]['ignored'], 'unignore: an ignored host' );
$x = $lr_act( 'unignore', 'nope.com' );
cmp_assert( 'refused' === $x[0] && $x[1] === $lr_state, 'unignore: a host that is not ignored is refused and the notice says so' );
$x = $lr_act( 'unignore', 'old.com', array( 'cmp_unknown_hosts_lock' => time(), 'cmp_unknown_hosts' => array_merge( $lr_state, array( 'ignored' => array( 'old.com' ) ) ) ) );
cmp_assert_eq( $x[0], 'busy', 'unignore: busy' );
$lr_sv = (string) file_get_contents( dirname( __DIR__ ) . '/includes/admin/class-services-page.php' );
cmp_assert( 1 === preg_match( "/'refused'\s*===\s*\\\$_GET\['cmp_domain'\][\s\S]{0,60}notice-warning/", $lr_sv ), 'services page: the refused notice' );

// ---- The one-time note after the update to 1.2.0 (I1) ----
$lr_up = function ( $old, array $opts = array(), $version = '1.2.0' ) use ( $lr_run ) {
	$o = $opts;
	if ( false !== $old ) {
		$o['cmp_db_version'] = $old;
	}
	$r = $lr_run( array( 'scenario' => 'upgrade', 'options' => $o, 'version' => $version ) );
	cmp_assert_eq( isset( $r['db']['cmp_db_version'] ) ? $r['db']['cmp_db_version'] : $r['result'], $version, 'upgrade from ' . var_export( $old, true ) . ': stamped' );
	return isset( $r['db']['cmp_services_notice'] ) ? $r['db']['cmp_services_notice'] : false;
};
$lr_seen = array( 'cmp_services_seen' => 1 );
cmp_assert_eq( $lr_up( '1.1.1', $lr_seen ), array( 'google-adsense' ), 'upgrade 1.1.1 -> 1.2.0: the note waits for AdSense only' );
cmp_assert_eq( $lr_up( '0.9.0', $lr_seen ), array( 'google-adsense' ), 'upgrade 0.9.0 -> 1.2.0: the same' );
cmp_assert_eq( $lr_up( '1.1.1', array() ), array( 'google-adsense' ), 'upgrade: also when the Servicii screen was never opened' );
cmp_assert_eq( $lr_up( false ), false, 'fresh install: no note' );
cmp_assert_eq( $lr_up( '1.2.0', $lr_seen, '1.2.1' ), false, 'upgrade 1.2.0 -> 1.2.1: no note' );
cmp_assert_eq( $lr_up( '1.2.0', array( 'cmp_services_notice' => array( 'google-adsense' ) ), '1.2.1' ), array( 'google-adsense' ), 'upgrade 1.2.0 -> 1.2.1: a note still waiting stays' );
cmp_assert_eq( $lr_up( '0.4.2' ), 1, 'upgrade from before 0.5.0, never seen: today\'s general note' );
cmp_assert_eq( $lr_up( '0.4.2', $lr_seen ), array( 'google-adsense' ), 'upgrade from before 0.5.0, already seen: only what 1.2.0 adds' );
cmp_assert_eq( $lr_up( '1.1.1', array( 'cmp_services_notice' => 1 ) ), 1, 'upgrade: a general note not closed yet stays (it names AdSense too)' );

$lr_set  = array( 'cmp_settings' => array( 'mode' => 'pro', 'block_scripts' => true ), 'cmp_categories' => $lr_cats, 'cmp_services_notice' => array( 'google-adsense' ) );
$lr_note = function ( array $found, array $opts = array(), $page = '' ) use ( $lr_run, $lr_set ) {
	return $lr_run( array( 'scenario' => 'notice', 'page' => $page, 'options' => array_merge( $lr_set, $opts ), 'scan' => array( 'services' => array( 'raw' => array(), 'blocked' => $found, 'found' => $found ) ) ) );
};
cmp_assert_eq( $lr_note( array( 'hotjar', 'google-analytics' ) )['out'], '', 'note: nothing while AdSense is not found' );
$r = $lr_run( array( 'scenario' => 'notice', 'options' => $lr_set ) );
cmp_assert_eq( $r['out'], '', 'note: nothing without a scan' );
$r = $lr_note( array( 'hotjar', 'google-adsense', 'clarity' ) );
cmp_assert( false !== strpos( $r['out'], 'Google AdSense' ) && false === strpos( $r['out'], 'Hotjar' ) && false === strpos( $r['out'], 'Clarity' ), 'note: names AdSense, never the services blocked before' );
cmp_assert( false !== strpos( $r['out'], 'cmp_dismiss_services_notice' ), 'note: closed as today' );
cmp_assert_eq( $lr_note( array( 'google-adsense' ), array( 'cmp_settings' => array( 'mode' => 'pro', 'block_scripts' => false ) ) )['out'], '', 'note: not while blocking is off' );
cmp_assert_eq( $lr_note( array( 'google-adsense' ), array( 'cmp_services' => array( 'overrides' => array( 'google-adsense' => array( 'exempt' => true ) ) ) ) )['out'], '', 'note: not for AdSense on "nu bloca"' );
// R5: the Servicii screen shows the note itself, once, then it is cleared; a screen that does not show it never clears it.
$r = $lr_note( array( 'google-adsense', 'hotjar' ), array(), 'services' );
cmp_assert( 1 === preg_match( '#<div class="wrap cmp-admin">.*Am început să blocăm până la acord: Google AdSense#s', $r['out'] ) && 0 === preg_match( '#să blocăm până la acord:[^<]*Hotjar#', $r['out'] ), 'note: the Servicii screen shows it' );
cmp_assert( ! isset( $r['db']['cmp_services_notice'] ), 'note: ... and then it is cleared' );
$r = $lr_note( array( 'hotjar' ), array(), 'services' );
cmp_assert( false === strpos( $r['out'], 'Am început să blocăm' ) && isset( $r['db']['cmp_services_notice'] ), 'note: opening Servicii before AdSense is found shows nothing and keeps it for later' );
$r = $lr_note( array( 'google-adsense' ), array( 'cmp_settings' => array( 'mode' => 'pro', 'block_scripts' => false ) ), 'services' );
cmp_assert( false === strpos( $r['out'], 'Am început să blocăm' ) && isset( $r['db']['cmp_services_notice'] ), 'note: the same while blocking is off' );
cmp_assert( isset( $lr_note( array( 'google-adsense' ) )['db']['cmp_services_notice'] ), 'note: showing it on another screen does not clear it' );
$r = $lr_run( array( 'scenario' => 'notice', 'get' => array( 'page' => 'cmp-services' ), 'options' => $lr_set, 'scan' => array( 'services' => array( 'raw' => array(), 'blocked' => array( 'google-adsense' ), 'found' => array( 'google-adsense' ) ) ) ) );
cmp_assert( '' === $r['out'] && isset( $r['db']['cmp_services_notice'] ), 'note: admin_notices on the Servicii screen prints nothing (the screen itself does) and clears nothing' );
$r = $lr_note( array( 'google-adsense' ), array( 'cmp_services_notice' => 1 ), 'services' );
cmp_assert( false === strpos( $r['out'], 'Am început să blocăm' ), 'note: the general note is not shown on Servicii, as before' );
cmp_assert( ! isset( $lr_note( array( 'hotjar' ), array( 'cmp_services_notice' => 1 ), 'services' )['db']['cmp_services_notice'] ), 'note: the general note is cleared by opening Servicii, as before' );
cmp_assert( ! isset( $lr_note( array( 'google-adsense' ), array(), 'dismiss' )['db']['cmp_services_notice'] ), 'note: "Închide" clears it for good' );
cmp_assert( ! isset( $lr_note( array( 'hotjar' ), array(), 'dismiss' )['db']['cmp_services_notice'] ), 'note: ... also when the scan changed since it was shown' );
$r = $lr_note( array( 'hotjar', 'google-adsense', 'google-analytics' ), array( 'cmp_services_notice' => 1 ) );
cmp_assert( false !== strpos( $r['out'], 'Hotjar' ) && false !== strpos( $r['out'], 'Google AdSense' ) && false === strpos( $r['out'], 'Google Analytics' ), 'note: the general note lists what it listed before' );
$r = $lr_run( array( 'scenario' => 'notice', 'options' => array_merge( $lr_set, array( 'cmp_services_notice' => 1 ) ) ) );
cmp_assert( false !== strpos( $r['out'], 'Unele servicii de pe site' ), 'note: the general fallback text stays for the general note' );

// ---- The Servicii row of a library service a custom one replaces (M9) ----
$lr_row = function ( array $custom ) use ( $lr_run, $lr_set ) {
	return $lr_run( array( 'scenario' => 'row', 'host' => 'google-adsense', 'options' => array_merge( $lr_set, array( 'cmp_services' => array( 'custom' => array( $custom ) ) ) ) ) )['out'];
};
$r = $lr_row( array( 'id' => 'custom-1', 'name' => 'Reclame', 'category' => 'marketing', 'exempt' => true, 'hosts' => array( 'pagead2.googlesyndication.com', 'googleads.g.doubleclick.net' ) ) );
cmp_assert( false !== strpos( $r, 'Reclame' ) && false === strpos( $r, 'blocat până la acord' ), 'row: fully replaced by a custom service: names it, does not claim blocking' );
foreach ( array( 'pagead2.googlesyndication.com', 'pagead2.googlesyndication.com/pagead/js/adsbygoogle.js' ) as $lr_h ) {
	$r = $lr_row( array( 'id' => 'custom-1', 'name' => 'Reclame', 'category' => 'marketing', 'exempt' => true, 'hosts' => array( $lr_h ) ) );
	cmp_assert( false !== strpos( $r, $lr_h ) && false !== strpos( $r, 'Reclame' ), "row, $lr_h: names the pattern and the custom service" );
	cmp_assert( 1 === preg_match( '#cmp-svc-status">blocat până la acord, în afară de ce decide serviciul tău personalizat<#', $r ), "row, $lr_h: the status says what it leaves out" );
}
$r = $lr_row( array( 'id' => 'custom-1', 'name' => 'Altceva', 'category' => 'marketing', 'hosts' => array( 'vendor.com' ) ) );
cmp_assert( false === strpos( $r, 'Altceva' ) && false !== strpos( $r, 'blocat până la acord' ), 'row: an unrelated custom service changes nothing' );
cmp_assert_eq( CMP_Services::added_to_blocking( '1.1.1' ), array( 'google-adsense' ), 'added_to_blocking: 1.1.1' );
cmp_assert_eq( CMP_Services::added_to_blocking( '1.2.0' ), array(), 'added_to_blocking: 1.2.0' );
cmp_assert_eq( CMP_Services::added_to_blocking( '1.2.0-beta.1' ), array( 'google-adsense' ), 'added_to_blocking: a pre-release of 1.2.0' );
cmp_assert_eq( CMP_Services::added_to_blocking( false ), array(), 'added_to_blocking: fresh install' );
