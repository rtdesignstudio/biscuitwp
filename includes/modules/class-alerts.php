<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone-loadable for tests; WordPress calls are in the lower part only.
}

/**
 * The "new domain" email. The scan collects the third-party hosts that load a
 * script or an iframe and belong to no service (CMP_Scanner::unknown_hosts());
 * this class keeps what was already seen and tells the admin, at most once every
 * 7 days and only about hosts it has not announced before.
 *
 * State (option cmp_unknown_hosts, not autoloaded):
 *   known     hosts seen, never announced (again); the MAX_KNOWN seen last are kept
 *   told      hosts a mail named (the last MAX_KNOWN); DOMAIN_MAX and the order in Servicii count them
 *   ignored   hosts the admin chose to ignore (also in known)
 *   pending   host => paths, new and not yet mailed
 *   sending   host => paths, taken out of pending for a mail in flight (a crash leaves them here; the next run puts them back)
 *   mailed_at time of the last successful mail
 *   failed_at time of the last failed mail (retry after RETRY seconds, not on every scan)
 *   base      false only for a state created by ignore() before the first scan: that scan is still the baseline
 *   silent    page paths that were in the baseline run's list but could not be read then; the first time one is read, the hosts that only silent pages show join known silently (they were there before the baseline). A host any other page shows, a brand-new page included, is announced.
 *
 * What one site can announce is bounded: at most MAX_NEW hosts per scan, MAX_PENDING
 * waiting, one mail per INTERVAL and, for good, DOMAIN_MAX hosts of one domain
 * announced or waiting (rotating subdomains; the ones at the baseline do not
 * count). What is above that is not announced and only listed in Servicii, where
 * the hosts never announced come first (at most MAX_UNTOLD).
 *
 * Limits:
 *  - what CMP_Scanner::unknown_hosts() does not see is not announced (its limits are there);
 *  - plain permalinks: every page has the path "/", so the mail cannot say which page it was and no page is silent once another one was read;
 *  - the baseline waits for a run that read the sitemap (cron): where no cron runs at all there is no baseline, so no mail; a sitemap that could not be fetched counts as read, and the old hosts of its pages are announced once, later;
 *  - a host that is new since the baseline and shown only by pages not read until now joins known without a mail;
 *  - a service with host/path, added after the host was queued, does not take it off the queue: it is mailed once;
 *  - the mail is written in the site's language through switch_to_locale(): on WordPress 6.0 the plugin's own translations may not reload in an admin request of a user with another language, and the mail then keeps that language or Romanian.
 *
 * The upper part is pure (data in, data out). process() is the WordPress side
 * driven through callbacks, so the whole flow is testable; on_scan_finished()
 * builds those callbacks from WordPress.
 */
class CMP_Alerts {

	const OPTION      = 'cmp_unknown_hosts';
	const LOCK_OPTION = 'cmp_unknown_hosts_lock';
	const HOOK        = 'cmp_scan_finished';
	/** At most one email per 7 days. */
	const INTERVAL    = 604800;
	/** After a failed mail, the next scan tries again only after 12 hours. */
	const RETRY       = 43200;
	const LOCK_TTL    = 300;
	const MAX_KNOWN   = 1000;
	const MAX_PENDING = 200;
	const MAX_PATHS   = 3;
	const MAX_SILENT  = 100;
	/** New hosts queued by one scan; the rest is left for the next ones. */
	const MAX_NEW     = 50;
	/** Hosts of one domain (domain()) that are ever announced. */
	const DOMAIN_MAX  = 10;
	/** Up to this many rows in Servicii, the ones already announced are listed too. */
	const MAX_LISTED  = 50;
	/** Never-announced hosts listed in Servicii. */
	const MAX_UNTOLD  = 200;
	/** Hosts printed in one email (the rest is counted). */
	const MAIL_HOSTS  = 20;

	/* ------------------------------------------------------------------
	 * Pure: state and decisions.
	 * ------------------------------------------------------------------ */

	/** Pure: a host name worth keeping, lowercased, or '' (letters, digits, dots, hyphens; at least one dot; at most 100 characters). */
	private static function clean_host( $host ) {
		if ( ! is_string( $host ) ) {
			return '';
		}
		$host = rtrim( strtolower( trim( $host ) ), '.' );
		return strlen( $host ) <= 100 && 1 === preg_match( '/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $host ) && false !== strpos( $host, '.' ) && false === strpos( $host, '..' ) ? $host : '';
	}

	/** Pure: a page path without query, fragment, whitespace or control characters, at most 200 characters ('/' when nothing is left). */
	private static function clean_path( $path ) {
		$p = preg_split( '/[\s?#\x00-\x1f\x7f]/', (string) $path );
		$p = (string) preg_replace( '/[<>"\\\\]/', '', (string) $p[0] );
		if ( '' === $p || '/' !== $p[0] ) {
			$p = '/' . ltrim( $p, '/' );
		}
		return substr( $p, 0, 200 );
	}

	/** Pure: host => paths with valid hosts and at most MAX_PATHS clean, distinct paths each. */
	private static function clean_found( $found ) {
		$out = array();
		foreach ( is_array( $found ) ? $found : array() as $host => $paths ) {
			$h = self::clean_host( (string) $host );
			if ( '' === $h || ! is_array( $paths ) ) {
				continue;
			}
			$list = isset( $out[ $h ] ) ? $out[ $h ] : array();
			foreach ( $paths as $p ) {
				$p = self::clean_path( $p );
				if ( count( $list ) < self::MAX_PATHS && ! in_array( $p, $list, true ) ) {
					$list[] = $p;
				}
			}
			$out[ $h ] = $list ? $list : array( '/' );
		}
		return $out;
	}

	/** Pure: a stored list of hosts: valid, unique, in order. */
	private static function host_list( $list ) {
		$out = array();
		foreach ( is_array( $list ) ? $list : array() as $h ) {
			$h = self::clean_host( $h );
			if ( '' !== $h && ! in_array( $h, $out, true ) ) {
				$out[] = $h;
			}
		}
		return $out;
	}

	/** Pure: the state with every field present and clean, or null when there is none (missing, corrupt). */
	public static function norm_state( $raw ) {
		if ( ! is_array( $raw ) || ! isset( $raw['known'] ) || ! is_array( $raw['known'] ) ) {
			return null;
		}
		return array(
			'known'     => self::host_list( $raw['known'] ),
			'ignored'   => self::host_list( isset( $raw['ignored'] ) ? $raw['ignored'] : array() ),
			'pending'   => self::clean_found( isset( $raw['pending'] ) ? $raw['pending'] : array() ),
			'told'      => self::host_list( isset( $raw['told'] ) ? $raw['told'] : array() ),
			'sending'   => self::clean_found( isset( $raw['sending'] ) ? $raw['sending'] : array() ),
			'mailed_at' => isset( $raw['mailed_at'] ) ? max( 0, (int) $raw['mailed_at'] ) : 0,
			'failed_at' => isset( $raw['failed_at'] ) ? max( 0, (int) $raw['failed_at'] ) : 0,
			'base'      => ! array_key_exists( 'base', $raw ) || ! empty( $raw['base'] ),
			'silent'    => self::path_list( isset( $raw['silent'] ) ? $raw['silent'] : array() ),
		);
	}

	/** Pure: a list of clean page paths: unique, in order, at most MAX_SILENT. */
	private static function path_list( $list ) {
		$out = array();
		foreach ( is_array( $list ) ? $list : array() as $p ) {
			if ( ! is_string( $p ) || '' === $p ) {
				continue;
			}
			$p = self::clean_path( $p );
			if ( ! in_array( $p, $out, true ) && count( $out ) < self::MAX_SILENT ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	private static function blank_state( $base ) {
		return array( 'known' => array(), 'ignored' => array(), 'told' => array(), 'pending' => array(), 'sending' => array(), 'mailed_at' => 0, 'failed_at' => 0, 'base' => (bool) $base, 'silent' => array() );
	}

	/** Pure: the domain a host counts under: its last two labels, three under a public suffix (CMP_Sanitizer::PUBLIC_SUFFIXES). */
	private static function domain( $host ) {
		$l    = explode( '.', (string) $host );
		$last = implode( '.', array_slice( $l, -2 ) );
		return in_array( $last, explode( ' ', CMP_Sanitizer::PUBLIC_SUFFIXES ), true ) ? implode( '.', array_slice( $l, -3 ) ) : $last;
	}

	/** Pure: $list plus the hosts of $add, which go last (also the ones already there); the last MAX_KNOWN are kept. */
	private static function add_last( array $list, array $add ) {
		return array_slice( array_merge( array_values( array_diff( $list, $add ) ), $add ), -self::MAX_KNOWN );
	}

	/** Pure: path => hosts (the aggregate's unknown_pages) turned into host => paths, cleaned (clean_found()). */
	public static function by_host( $pages ) {
		$out = array();
		foreach ( is_array( $pages ) ? $pages : array() as $path => $hosts ) {
			foreach ( is_array( $hosts ) ? $hosts : array() as $h ) {
				if ( is_string( $h ) ) {
					$out[ $h ][] = (string) $path;
				}
			}
		}
		return self::clean_found( $out );
	}

	/**
	 * Pure: fold what a scan found into the state and decide what to mail.
	 * No state: the baseline, everything found is known and nothing is sent
	 * (an update, a fresh install). A new host (never seen, not ignored) becomes
	 * known and, when alerts are on, pending; known is recorded even when they
	 * are off, so a host seen while off is never announced later. A host that
	 * does not fit (MAX_NEW, MAX_PENDING, DOMAIN_MAX) is neither, so a later scan may take it.
	 * The pending hosts go out together once INTERVAL has passed since the last
	 * mail (and RETRY since a failed one). What goes out is moved to "sending"
	 * and the caller reports the outcome with mailed().
	 *
	 * The page info says which pages the scan really read: 'read' (path => hosts)
	 * and 'unread' (paths of the listed pages that could not be read), for "silent"
	 * (see the class comment).
	 *
	 * @param mixed $state   The stored option (false/anything else = none).
	 * @param array $found   host => paths (by_host()), without the covered ones.
	 * @param array $pages   array( 'read' => path => hosts, 'unread' => paths ), optional.
	 * @return array{state: array, send: array} send: host => paths to mail now.
	 */
	public static function step( $state, array $found, $now, $enabled, array $pages = array() ) {
		$now    = (int) $now;
		$found  = self::clean_found( $found );
		$unread = isset( $pages['unread'] ) ? self::path_list( $pages['unread'] ) : array();
		$s      = self::norm_state( $state );
		if ( null === $s || ! $s['base'] ) {
			$s            = null === $s ? self::blank_state( true ) : $s;
			$s['known']   = self::add_last( $s['known'], array_keys( $found ) );
			$s['base']    = true;
			$s['pending'] = array();
			$s['sending'] = array();
			$s['silent']  = $unread;
			return array( 'state' => $s, 'send' => array() );
		}
		// First read of a page the baseline could not read: what only such pages show was already there.
		$quiet = array();
		$loud  = array();
		$done  = array();
		foreach ( isset( $pages['read'] ) && is_array( $pages['read'] ) ? $pages['read'] : array() as $p => $hosts ) {
			$p     = self::clean_path( $p );
			$hosts = self::host_list( $hosts );
			if ( in_array( $p, $s['silent'], true ) ) {
				$quiet  = array_merge( $quiet, $hosts );
				$done[] = $p;
			} else {
				$loud = array_merge( $loud, $hosts );
			}
		}
		$quiet       = array_diff( $quiet, $loud );
		$s['silent'] = array_values( array_diff( $s['silent'], $done ) );
		// A run that died between writing "sending" and learning the outcome: those hosts were never confirmed, send them again.
		foreach ( $s['sending'] as $h => $paths ) {
			if ( ! in_array( $h, $s['ignored'], true ) && ! isset( $s['pending'][ $h ] ) ) {
				$s['pending'][ $h ] = $paths;
			}
		}
		$s['sending'] = array();
		$known        = array_fill_keys( $s['known'], true );
		$count        = array_count_values( array_map( array( __CLASS__, 'domain' ), array_unique( array_merge( $s['told'], array_keys( $s['pending'] ) ) ) ) );
		$seen         = array();
		$new          = 0;
		foreach ( $found as $h => $paths ) {
			if ( ! isset( $known[ $h ] ) && ! isset( $s['pending'][ $h ] ) && ! in_array( $h, $s['ignored'], true ) ) {
				$d = self::domain( $h );
				$n = isset( $count[ $d ] ) ? $count[ $d ] : 0;
				if ( $n >= self::DOMAIN_MAX ) {
					continue;
				}
				if ( $enabled && ! in_array( $h, $quiet, true ) ) {
					if ( $new >= self::MAX_NEW || count( $s['pending'] ) >= self::MAX_PENDING ) {
						continue;
					}
					$s['pending'][ $h ] = $paths;
					$count[ $d ]        = $n + 1;
					$new++;
				}
			}
			$seen[] = $h;
		}
		$s['known'] = self::add_last( $s['known'], $seen );
		$send       = array();
		$due        = ( $s['mailed_at'] <= 0 || $now - $s['mailed_at'] >= self::INTERVAL )
			&& ( $s['failed_at'] <= 0 || $now - $s['failed_at'] >= self::RETRY );
		if ( $enabled && $s['pending'] && $due ) {
			$send         = $s['pending'];
			$s['sending'] = $send;
			$s['pending'] = array();
		}
		return array( 'state' => $s, 'send' => $send );
	}

	/**
	 * Pure: the outcome of a mail. Success: nothing left to send, the time is
	 * recorded. Failure: the hosts are pending again (never lost), mailed_at is
	 * not advanced and the failure time delays the next try.
	 */
	public static function mailed( $state, $ok, $now ) {
		$s = self::norm_state( $state );
		if ( null === $s ) {
			$s = self::blank_state( true );
		}
		if ( $ok ) {
			$s['told']      = self::add_last( $s['told'], array_slice( array_keys( $s['sending'] ), 0, self::MAIL_HOSTS ) );
			$s['sending']   = array();
			$s['mailed_at'] = (int) $now;
			$s['failed_at'] = 0;
			return $s;
		}
		foreach ( $s['sending'] as $h => $paths ) {
			if ( ! in_array( $h, $s['ignored'], true ) && ! isset( $s['pending'][ $h ] ) ) {
				$s['pending'][ $h ] = $paths;
			}
		}
		$s['sending']   = array();
		$s['failed_at'] = (int) $now;
		return $s;
	}

	/** Pure: ignore a host (known, never announced, off the pending list). No state yet: the next scan is still the baseline. */
	public static function ignore( $state, $host ) {
		$s = self::norm_state( $state );
		$s = null === $s ? self::blank_state( false ) : $s;
		$h = self::clean_host( $host );
		if ( '' === $h ) {
			return $s;
		}
		if ( ! in_array( $h, $s['ignored'], true ) ) {
			$s['ignored'][] = $h;
		}
		$s['known'] = self::add_last( $s['known'], array( $h ) );
		unset( $s['pending'][ $h ], $s['sending'][ $h ] );
		return $s;
	}

	/** Pure: take a host off the ignored list. It stays known: it shows on the list again but is not announced. */
	public static function unignore( $state, $host ) {
		$s = self::norm_state( $state );
		$s = null === $s ? self::blank_state( true ) : $s;
		$h = self::clean_host( $host );
		$s['ignored'] = array_values( array_diff( $s['ignored'], array( $h ) ) );
		return $s;
	}

	/** Pure: the ignored hosts of a stored state. */
	public static function ignored_list( $state ) {
		$s = self::norm_state( $state );
		return null === $s ? array() : $s['ignored'];
	}

	/**
	 * Pure: what the Servicii screen lists: the hosts of the last scan (by_host())
	 * plus the pending ones, without the ignored and the ones a service covers now.
	 * The ones never announced come first, at most MAX_UNTOLD; the announced ones
	 * follow up to MAX_LISTED rows.
	 *
	 * @param callable $covered fn( string $host ): bool
	 */
	public static function visible( $found, $state, callable $covered ) {
		$s       = self::norm_state( $state );
		$s       = null === $s ? self::blank_state( true ) : $s;
		$told    = array();
		$out     = array();
		foreach ( self::clean_found( $found ) + $s['pending'] as $h => $paths ) {
			if ( in_array( $h, $s['ignored'], true ) || $covered( $h ) ) {
				continue;
			}
			if ( in_array( $h, $s['told'], true ) ) {
				$told[ $h ] = $paths;
			} else {
				$out[ $h ] = $paths;
			}
		}
		$out = array_slice( $out, 0, self::MAX_UNTOLD, true );
		return $out + array_slice( $told, 0, max( 0, self::MAX_LISTED - count( $out ) ), true );
	}

	/** Pure: may the admin act on this host (it is on the list the screen showed)? */
	public static function may_act( $host, array $list ) {
		$h = self::clean_host( $host );
		return '' !== $h && isset( $list[ $h ] );
	}

	/** Pure: is the alert on in these settings? A missing key (an install updated from before this setting) is off. */
	public static function enabled( $settings ) {
		return is_array( $settings ) && ! empty( $settings['alert_email_on'] );
	}

	/** Pure: a usable email address or ''. */
	private static function valid_email( $v ) {
		if ( ! is_string( $v ) ) {
			return '';
		}
		$v = trim( $v );
		return strlen( $v ) <= 254 && false !== filter_var( $v, FILTER_VALIDATE_EMAIL ) ? $v : '';
	}

	/** Pure: who gets the mail: the configured address, the admin email when none is configured, '' (nobody) when the configured one is not valid. */
	public static function recipient( $settings, $admin_email ) {
		$own = is_array( $settings ) && isset( $settings['alert_email'] ) ? $settings['alert_email'] : '';
		return is_string( $own ) && '' === trim( $own ) ? self::valid_email( $admin_email ) : self::valid_email( $own );
	}

	/** Pure: the site name as one short plain line (first line only, no tags, at most 80 characters). */
	private static function clean_site( $name ) {
		$line = preg_split( '/[\r\n]/', (string) $name );
		$line = trim( (string) preg_replace( '/\s+/', ' ', strip_tags( (string) $line[0] ) ) );
		$line = (string) preg_replace( '/[\x00-\x1f\x7f]/', '', $line );
		return function_exists( 'mb_substr' ) ? mb_substr( $line, 0, 80, 'UTF-8' ) : substr( $line, 0, 80 );
	}

	/**
	 * Pure: the email. Plain text, in the language of the current locale (the
	 * caller switches to the site's), host names and paths cleaned and capped
	 * (no header injection, no markup), paths without query or fragment.
	 *
	 * @param array  $hosts host => paths.
	 * @return array{subject: string, body: string}
	 */
	public static function message( array $hosts, $site, $url ) {
		$hosts = self::clean_found( $hosts );
		$site  = self::clean_site( $site );
		$url   = (string) preg_replace( '/[\x00-\x20\x7f<>"]/', '', (string) $url );
		// Each text goes through gettext (text domain biscuitwp); the source (Romanian) is the fallback outside WordPress, as in the unit tests.
		$gt = function_exists( '__' );
		/* translators: 1: domain, 2: page paths */
		$row = $gt ? __( '- %1$s (pagini: %2$s)', 'biscuitwp' ) : '- %1$s (pagini: %2$s)';
		/* translators: %d: number of domains not listed */
		$more = $gt ? __( 'Și încă %d domenii.', 'biscuitwp' ) : 'Și încă %d domenii.';
		/* translators: %s: site name */
		$intro = $gt ? __( 'BiscuitWP a găsit pe %s domenii terțe noi, care încarcă scripturi sau iframe-uri și nu sunt încă tratate ca servicii:', 'biscuitwp' ) : 'BiscuitWP a găsit pe %s domenii terțe noi, care încarcă scripturi sau iframe-uri și nu sunt încă tratate ca servicii:';
		/* translators: %s: site name */
		$subject = $gt ? __( 'BiscuitWP: domenii noi pe %s', 'biscuitwp' ) : 'BiscuitWP: domenii noi pe %s';
		$hello   = $gt ? __( 'Bună ziua,', 'biscuitwp' ) : 'Bună ziua,';
		$action  = $gt ? __( 'Deschide „Servicii” ca să le adaugi ca serviciu sau să le ignori:', 'biscuitwp' ) : 'Deschide „Servicii” ca să le adaugi ca serviciu sau să le ignori:';
		$footer  = $gt ? __( 'Primești acest mesaj pentru că notificarea e pornită în Setările BiscuitWP. O poți opri acolo.', 'biscuitwp' ) : 'Primești acest mesaj pentru că notificarea e pornită în Setările BiscuitWP. O poți opri acolo.';

		$body = array( $hello, '', sprintf( $intro, $site ), '' );
		$n    = 0;
		foreach ( $hosts as $h => $paths ) {
			if ( $n >= self::MAIL_HOSTS ) {
				break;
			}
			$body[] = sprintf( $row, $h, implode( ', ', $paths ) );
			$n++;
		}
		if ( count( $hosts ) > $n ) {
			$body[] = sprintf( $more, count( $hosts ) - $n );
		}
		array_push( $body, '', $action, $url, '', $footer );
		return array(
			'subject' => sprintf( $subject, $site ),
			'body'    => implode( "\n", $body ),
		);
	}

	/* ------------------------------------------------------------------
	 * The flow, with its WordPress calls injected.
	 * ------------------------------------------------------------------ */

	/**
	 * Run one scan result through the alert. $io: lock(): bool, unlock(), read(),
	 * write( array ), mail( subject, body ): bool, covered( host ): bool,
	 * enabled (bool), now (int), site (string), url (string).
	 *
	 * Safe against two finishes at once (the lock; the state is written BEFORE the
	 * mail and the outcome after, so a crash loses nothing and a failure keeps the
	 * hosts pending). A scan that read no page changes nothing, not even the lock.
	 *
	 * The baseline waits for a run that read the sitemap ($agg['sitemap'] not false).
	 *
	 * @return string skipped | locked | waiting | baseline | noop | sent | failed
	 */
	public static function process( array $agg, array $io ) {
		if ( empty( $agg['pages'] ) ) {
			return 'skipped';
		}
		if ( ! $io['lock']() ) {
			return 'locked';
		}
		$result = 'noop';
		try {
			$now    = (int) $io['now'];
			$raw    = $io['read']();
			$cur    = self::norm_state( $raw );
			$base   = null === $cur || ! $cur['base'];
			if ( $base && isset( $agg['sitemap'] ) && ! $agg['sitemap'] ) {
				return 'waiting';
			}
			$read   = isset( $agg['unknown_pages'] ) && is_array( $agg['unknown_pages'] ) ? $agg['unknown_pages'] : array();
			$covers = $io['covered'];
			$found  = array();
			foreach ( self::by_host( $read ) as $h => $paths ) {
				if ( ! $covers( $h ) ) {
					$found[ $h ] = $paths;
				}
			}
			if ( null !== $cur ) {
				foreach ( array( 'pending', 'sending' ) as $k ) {
					foreach ( array_keys( $cur[ $k ] ) as $h ) {
						if ( $covers( $h ) ) {
							unset( $cur[ $k ][ $h ] ); // the admin made it a service meanwhile
						}
					}
				}
			}
			$pages = array(
				'read'   => $read,
				'unread' => isset( $agg['unread'] ) && is_array( $agg['unread'] ) ? $agg['unread'] : array(),
			);
			$step = self::step( null === $cur ? $raw : $cur, $found, $now, ! empty( $io['enabled'] ), $pages );
			$io['write']( $step['state'] );
			$result = $base ? 'baseline' : 'noop';
			if ( $step['send'] ) {
				$msg = self::message( $step['send'], $io['site'], $io['url'] );
				try {
					$ok = (bool) $io['mail']( $msg['subject'], $msg['body'] );
				} catch ( \Throwable $e ) {
					$ok = false;
				}
				$io['write']( self::mailed( $io['read'](), $ok, $now ) );
				$result = $ok ? 'sent' : 'failed';
			}
		} finally {
			$io['unlock']();
		}
		return $result;
	}

	/**
	 * "Ignoră" / "Nu mai ignora": the change runs under the same lock as the scan
	 * step, on the state read INSIDE the lock, so a click is never overwritten by a
	 * scan finishing at the same time (and never overwrites its result). One try:
	 * a busy lock is reported and the screen asks to press again.
	 * $io: lock(): bool, unlock(), read(), write( array ).
	 *
	 * @param string $op ignore | unignore
	 * @return string done | busy (nothing written) | invalid (unknown operation)
	 */
	public static function change( $op, $host, array $io ) {
		if ( 'ignore' !== $op && 'unignore' !== $op ) {
			return 'invalid';
		}
		if ( ! $io['lock']() ) {
			return 'busy';
		}
		try {
			$state = $io['read']();
			$io['write']( 'ignore' === $op ? self::ignore( $state, $host ) : self::unignore( $state, $host ) );
		} finally {
			$io['unlock']();
		}
		return 'done';
	}

	/* ------------------------------------------------------------------
	 * WordPress side.
	 * ------------------------------------------------------------------ */

	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'on_scan_finished' ), 10, 1 );
	}

	/** Drop the cached copies of an option (its value, and "there is no such option"): another process may have changed it since this request read it. */
	private static function forget( $option ) {
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/** Take the lock (an option added atomically); one older than LOCK_TTL is taken over. add_option() trusts the cache, so the cached copies go first. */
	private static function lock() {
		self::forget( self::LOCK_OPTION );
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return true;
		}
		if ( time() - (int) get_option( self::LOCK_OPTION, 0 ) > self::LOCK_TTL ) {
			update_option( self::LOCK_OPTION, time(), false );
			return true;
		}
		return false;
	}

	/** The lock and the state, for process() and change(): read() is always a fresh read. */
	private static function store_io() {
		return array(
			'lock'   => function () {
				return self::lock();
			},
			'unlock' => function () {
				delete_option( self::LOCK_OPTION );
			},
			'read'   => function () {
				self::forget( self::OPTION );
				return get_option( self::OPTION, false );
			},
			'write'  => function ( $v ) {
				update_option( self::OPTION, $v, false );
			},
		);
	}

	/** WordPress side of change(). */
	public static function change_wp( $op, $host ) {
		return self::change( $op, $host, self::store_io() );
	}

	/** The state, as stored (false when there is none). */
	public static function state() {
		return get_option( self::OPTION, false );
	}

	/** Action cmp_scan_finished: cron or an admin screen, after the result was stored. */
	public static function on_scan_finished( $agg ) {
		if ( ! is_array( $agg ) ) {
			return;
		}
		$settings = get_option( 'cmp_settings', array() );
		$to       = self::recipient( $settings, (string) get_option( 'admin_email', '' ) );
		$services = CMP_Services::active();
		$home     = home_url( '/' );
		$io       = self::store_io() + array(
			'mail'    => function ( $subject, $body ) use ( $to ) {
				return '' !== $to && (bool) wp_mail( $to, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
			},
			'covered' => function ( $host ) use ( $services, $home ) {
				return CMP_Scanner::host_known( $host, $services, $home );
			},
			'enabled' => self::enabled( $settings ),
			'now'     => time(),
			'site'    => wp_specialchars_decode( (string) get_option( 'blogname', '' ), ENT_QUOTES ), // stored with entities
			'url'     => admin_url( 'admin.php?page=cmp-services' ),
		);
		// The mail is written in the site's language, whoever triggered the scan (cron, or an admin with another language).
		$switched = false;
		if ( function_exists( 'switch_to_locale' ) && function_exists( 'restore_previous_locale' ) ) {
			$switched = (bool) switch_to_locale( get_locale() );
		}
		try {
			self::process( $agg, $io );
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}
}
