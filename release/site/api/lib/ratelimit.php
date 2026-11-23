<?php
if ( ! defined( 'CMP_CHECK' ) ) {
	http_response_code( 404 );
	exit;
}

/**
 * Consent Manager Pro, "Verifică-ți site-ul": limits that protect the hosting
 * account the check runs on.
 *
 * - Per visitor: at most 5 checks in any 60 seconds and 50 in any 24 hours
 *   per IPv4 or IPv6 /64, and 15 / 150 per IPv6 /48 (both must allow). One
 *   check reads up to 10 pages, hence the low numbers. The IP
 *   is never stored: each bucket is a file named after HMAC-SHA256(key, salt),
 *   where the salt is a random secret created once in the counter folder
 *   (".salt", 0600). A counter file holds only the times of allowed checks.
 * - For everybody together: at most 30 checks a minute and 1000 a day
 *   (global.cnt).
 * - At most 3 checks running at once on the account (slot-N.lock files, held
 *   with a non-blocking flock for the duration of a whole multi-page check).
 * - Counter files untouched for a day are deleted, at most once an hour and a
 *   bounded number of directory entries per run.
 *
 * The counter folder is outside the web root: <home>/tmp/cmp-check. Nothing is
 * written anywhere else. Any storage problem fails closed.
 * PHP 7.4+.
 */

/** Per-visitor limits: window in seconds => checks. */
function cmp_check_rate_limits(): array {
	return array(
		60    => 5,
		86400 => 50,
	);
}

/** Limits of an IPv6 /48 (several /64s of one network). */
function cmp_check_rate_limits_48(): array {
	return array(
		60    => 15,
		86400 => 150,
	);
}

/** Limits for all visitors together. */
function cmp_check_rate_limits_global(): array {
	return array(
		60    => 30,
		86400 => 1000,
	);
}

/** Checks that may run at the same time on the account. */
function cmp_check_slot_count(): int {
	return 3;
}

/** Directory entries looked at by one cleanup run. */
function cmp_check_rate_cleanup_cap(): int {
	return 5000;
}

/**
 * The counter folder on the server: <home>/tmp/cmp-check. The home folder is
 * the process owner's (posix) when available, else it is computed from this
 * file's location (<home>/<web root>/<site>/api/lib).
 */
function cmp_check_rate_default_dir(): string {
	if ( function_exists( 'posix_getpwuid' ) && function_exists( 'posix_geteuid' ) ) {
		$pw = @posix_getpwuid( posix_geteuid() ); // phpcs:ignore
		if ( is_array( $pw ) && isset( $pw['dir'] ) && is_string( $pw['dir'] ) && '' !== $pw['dir'] && '/' !== $pw['dir'] ) {
			return rtrim( $pw['dir'], '/' ) . '/tmp/cmp-check';
		}
	}
	return dirname( __DIR__, 4 ) . '/tmp/cmp-check';
}

/** An absolute path with symlinks and ".." resolved as far as it exists; the rest appended. */
function cmp_check_real_path( string $path ): string {
	$path = rtrim( $path, '/' );
	$tail = array();
	$cur  = $path;
	while ( '' !== $cur && '/' !== $cur && '.' !== $cur && false === realpath( $cur ) ) {
		array_unshift( $tail, basename( $cur ) );
		$cur = dirname( $cur );
	}
	$base = realpath( '' === $cur ? '/' : $cur );
	$out  = false === $base ? $cur : $base;
	foreach ( $tail as $part ) {
		if ( '..' === $part ) {
			$out = dirname( $out );
		} elseif ( '.' !== $part && '' !== $part ) {
			$out = rtrim( $out, '/' ) . '/' . $part;
		}
	}
	return $out;
}

/** Is $dir the web root $docroot or inside it? False when $docroot is empty. */
function cmp_check_dir_inside( string $dir, string $docroot ): bool {
	if ( '' === trim( $docroot ) ) {
		return false;
	}
	$d = cmp_check_real_path( $dir );
	$r = rtrim( cmp_check_real_path( $docroot ), '/' );
	if ( '' === $r ) {
		return true; // A web root of "/" contains everything.
	}
	return $d === $r || 0 === strpos( $d, $r . '/' );
}

/**
 * The buckets of a visitor: key => limits. IPv4: the address; IPv6: its /64
 * and its /48. An IPv4-mapped IPv6 address (::ffff:a.b.c.d, as some servers
 * report IPv4 clients on a dual-stack socket) is the IPv4 address.
 */
function cmp_check_rate_keys( string $ip ): array {
	$bin = @inet_pton( $ip ); // phpcs:ignore
	if ( false === $bin ) {
		return array( 'raw:' . $ip => cmp_check_rate_limits() );
	}
	if ( 16 === strlen( $bin ) && str_repeat( "\0", 10 ) . "\xff\xff" === substr( $bin, 0, 12 ) ) {
		$bin = substr( $bin, 12 );
	}
	if ( 16 === strlen( $bin ) ) {
		return array(
			'v6/64:' . substr( $bin, 0, 8 ) => cmp_check_rate_limits(),
			'v6/48:' . substr( $bin, 0, 6 ) => cmp_check_rate_limits_48(),
		);
	}
	return array( 'v4:' . $bin => cmp_check_rate_limits() );
}

/** The counter folder, created (0700) when missing; false when unusable. */
function cmp_check_rate_prepare_dir( string $dir ) {
	$dir = rtrim( $dir, '/' );
	if ( '' === $dir || is_link( $dir ) ) {
		return false;
	}
	if ( ! is_dir( $dir ) ) {
		if ( file_exists( $dir ) ) {
			return false;
		}
		if ( ! @mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) { // phpcs:ignore
			return false;
		}
	}
	@chmod( $dir, 0700 ); // phpcs:ignore
	return is_dir( $dir ) && ! is_link( $dir ) && is_writable( $dir ) ? $dir : false;
}

/** The secret salt of the folder (64 hex characters), created on first use; false on failure. */
function cmp_check_rate_salt( string $dir ) {
	$path = $dir . '/.salt';
	if ( is_link( $path ) ) {
		return false;
	}
	$fh = @fopen( $path, 'c+' ); // phpcs:ignore
	if ( false === $fh ) {
		return false;
	}
	@chmod( $path, 0600 ); // phpcs:ignore
	$salt = false;
	if ( flock( $fh, LOCK_EX ) ) {
		$cur = trim( (string) stream_get_contents( $fh, 256 ) );
		if ( 1 === preg_match( '/^[0-9a-f]{64}$/', $cur ) ) {
			$salt = $cur;
		} else {
			try {
				$new = bin2hex( random_bytes( 32 ) );
			} catch ( \Throwable $e ) {
				$new = '';
			}
			if ( '' !== $new ) {
				ftruncate( $fh, 0 );
				rewind( $fh );
				if ( 64 === fwrite( $fh, $new ) && fflush( $fh ) ) {
					$salt = $new;
				}
			}
		}
		flock( $fh, LOCK_UN );
	}
	fclose( $fh );
	return $salt;
}

/**
 * Delete counter files untouched for a day. Runs at most once an hour
 * (".cleanup" timestamp file) and looks at no more than
 * cmp_check_rate_cleanup_cap() directory entries per run.
 */
function cmp_check_rate_cleanup( string $dir, int $now ): void {
	$marker = $dir . '/.cleanup';
	if ( is_link( $marker ) ) {
		return;
	}
	$last = is_file( $marker ) ? (int) @filemtime( $marker ) : 0; // phpcs:ignore
	if ( $last > $now - 3600 && $last <= $now ) {
		return;
	}
	$fh = @fopen( $marker, 'c' ); // phpcs:ignore
	if ( false !== $fh ) {
		fclose( $fh );
		@chmod( $marker, 0600 ); // phpcs:ignore
		@touch( $marker, $now ); // phpcs:ignore
	}
	$dh = @opendir( $dir ); // phpcs:ignore
	if ( false === $dh ) {
		return;
	}
	$seen = 0;
	$cap  = cmp_check_rate_cleanup_cap();
	while ( $seen < $cap && false !== ( $name = readdir( $dh ) ) ) { // phpcs:ignore
		$seen++;
		if ( 1 !== preg_match( '/^[0-9a-f]{64}\.cnt$/', $name ) ) {
			continue; // Only per-visitor counter files, never anything else.
		}
		$p  = $dir . '/' . $name;
		$mt = @filemtime( $p ); // phpcs:ignore
		if ( false !== $mt && $mt < $now - 86400 && ! is_link( $p ) ) {
			@unlink( $p ); // phpcs:ignore
		}
	}
	closedir( $dh );
}

/**
 * Count one event in several counter files at once: allowed only when every
 * file is under all its limits; then $now is added to each of them.
 *
 * A file that does not exist yet holds no times, so it is never the one over a
 * limit: the first pass locks and reads only the existing files. If they
 * refuse, nothing is created (a new IPv6 /64 inside a full /48 adds no file).
 * If they allow and some files are missing, every lock is dropped and a second
 * pass creates and locks all files, again in sorted order (no deadlock), and
 * decides again on what it reads.
 *
 * @param array $files path => limits (window => max).
 * @return string ok | limited | error
 */
function cmp_check_counter_apply( array $files, int $now ): string {
	ksort( $files ); // Lock in a fixed order: no deadlock between requests.
	$r = cmp_check_counter_pass( $files, $now, false );
	return 'retry' === $r ? cmp_check_counter_pass( $files, $now, true ) : $r;
}

/**
 * One locked pass of cmp_check_counter_apply(). With $create false, missing
 * files are skipped and 'retry' is returned (nothing written) when the event
 * would be allowed but a file is missing.
 */
function cmp_check_counter_pass( array $files, int $now, bool $create ): string {
	$open    = array();
	$missing = false;
	$release = function () use ( &$open ) {
		foreach ( $open as $o ) {
			flock( $o['fh'], LOCK_UN );
			fclose( $o['fh'] );
		}
	};
	foreach ( $files as $path => $limits ) {
		if ( is_link( $path ) ) {
			$release();
			return 'error';
		}
		if ( ! $create && ! file_exists( $path ) ) {
			$missing = true;
			continue;
		}
		$fh = @fopen( $path, $create ? 'c+' : 'r+' ); // phpcs:ignore
		if ( false === $fh ) {
			$release();
			return 'error';
		}
		@chmod( $path, 0600 ); // phpcs:ignore
		if ( ! flock( $fh, LOCK_EX ) ) {
			fclose( $fh );
			$release();
			return 'error';
		}
		$span  = max( array_keys( $limits ) );
		$times = array();
		foreach ( explode( "\n", (string) stream_get_contents( $fh, 65536 ) ) as $line ) {
			if ( 1 === preg_match( '/^\d{1,12}$/', $line ) ) {
				$t = (int) $line;
				if ( $t > $now - $span && $t <= $now ) {
					$times[] = $t;
				}
			}
		}
		$open[ $path ] = array(
			'fh'     => $fh,
			'limits' => $limits,
			'times'  => $times,
		);
	}
	$result = 'ok';
	foreach ( $open as $o ) {
		foreach ( $o['limits'] as $window => $max ) {
			$n = 0;
			foreach ( $o['times'] as $t ) {
				if ( $t > $now - $window ) {
					$n++;
				}
			}
			if ( $n >= $max ) {
				$result = 'limited';
			}
		}
	}
	if ( 'ok' === $result && $missing ) {
		$release(); // Allowed so far: the second pass creates the missing files.
		return 'retry';
	}
	foreach ( $open as $path => $o ) {
		$times = $o['times'];
		if ( 'ok' === $result ) {
			$times[] = $now;
		}
		$data = implode( "\n", $times ) . "\n";
		ftruncate( $o['fh'], 0 );
		rewind( $o['fh'] );
		$written = fwrite( $o['fh'], $data );
		fflush( $o['fh'] );
		flock( $o['fh'], LOCK_UN );
		fclose( $o['fh'] );
		@touch( $path, $now ); // phpcs:ignore
		if ( strlen( $data ) !== $written ) {
			$result = 'error';
		}
	}
	return $result;
}

/**
 * Count one check of $ip at time $now (all its buckets).
 *
 * @return string 'ok' (allowed and counted), 'limited' (over a limit, not
 *                counted) or 'error' (the counter folder is unusable).
 */
function cmp_check_rate_check( string $ip, string $dir, int $now ): string {
	$dir = cmp_check_rate_prepare_dir( $dir );
	if ( false === $dir ) {
		return 'error';
	}
	$salt = cmp_check_rate_salt( $dir );
	if ( false === $salt ) {
		return 'error';
	}
	cmp_check_rate_cleanup( $dir, $now );
	$files = array();
	foreach ( cmp_check_rate_keys( $ip ) as $key => $limits ) {
		$files[ $dir . '/' . hash_hmac( 'sha256', $key, $salt ) . '.cnt' ] = $limits;
	}
	return cmp_check_counter_apply( $files, $now );
}

/** Is one more check allowed for $ip now? Counts it when it is. Storage problems refuse. */
function cmp_check_rate_allow( string $ip, string $dir, int $now ): bool {
	return 'ok' === cmp_check_rate_check( $ip, $dir, $now );
}

/** Count one check for everybody together (global.cnt): ok | limited | error. */
function cmp_check_rate_global( string $dir, int $now ): string {
	$dir = cmp_check_rate_prepare_dir( $dir );
	if ( false === $dir ) {
		return 'error';
	}
	return cmp_check_counter_apply( array( $dir . '/global.cnt' => cmp_check_rate_limits_global() ), $now );
}

/**
 * Take one of the account's check slots without waiting: the open, locked
 * handle, or false when all are busy (or the folder is unusable). The lock is
 * released by cmp_check_slot_release(), and by the system when the request ends.
 *
 * @return resource|false
 */
function cmp_check_slot_acquire( string $dir ) {
	$dir = cmp_check_rate_prepare_dir( $dir );
	if ( false === $dir ) {
		return false;
	}
	for ( $i = 0; $i < cmp_check_slot_count(); $i++ ) {
		$path = $dir . '/slot-' . $i . '.lock';
		if ( is_link( $path ) ) {
			continue;
		}
		$fh = @fopen( $path, 'c' ); // phpcs:ignore
		if ( false === $fh ) {
			continue;
		}
		@chmod( $path, 0600 ); // phpcs:ignore
		if ( flock( $fh, LOCK_EX | LOCK_NB ) ) {
			return $fh;
		}
		fclose( $fh );
	}
	return false;
}

/** Give a slot back (safe to call twice). */
function cmp_check_slot_release( $fh ): void {
	if ( is_resource( $fh ) ) {
		flock( $fh, LOCK_UN );
		fclose( $fh );
	}
}

/**
 * The public total of successful checks: one number in total-checks.txt in the
 * counter folder (0600), nothing about the site or the visitor. Never deleted by
 * cmp_check_rate_cleanup(), which only touches the per-visitor *.cnt files.
 */
function cmp_check_total_path( string $dir ): string {
	return rtrim( $dir, '/' ) . '/total-checks.txt';
}

/** The number in an open total file (0 for anything but digits). */
function cmp_check_total_parse( string $raw ): int {
	$raw = trim( $raw );
	return 1 === preg_match( '/^[0-9]{1,15}$/', $raw ) ? (int) $raw : 0;
}

/**
 * Rewrite one small file of the counter folder (0600) under an exclusive lock:
 * $change gets the current content and returns the new one. Never follows a
 * symlink.
 */
function cmp_check_stat_update( string $dir, string $name, callable $change ): bool {
	$dir = cmp_check_rate_prepare_dir( $dir );
	if ( false === $dir ) {
		return false;
	}
	$path = $dir . '/' . $name;
	if ( is_link( $path ) ) {
		return false;
	}
	$fh = @fopen( $path, 'c+' ); // phpcs:ignore
	if ( false === $fh ) {
		return false;
	}
	$ok = false;
	if ( flock( $fh, LOCK_EX ) ) {
		$new = (string) $change( (string) stream_get_contents( $fh, 4096, 0 ) );
		$ok  = ftruncate( $fh, 0 ) && rewind( $fh ) && false !== fwrite( $fh, $new ) && fflush( $fh );
		flock( $fh, LOCK_UN );
	}
	fclose( $fh );
	@chmod( $path, 0600 ); // phpcs:ignore
	return $ok;
}

/** Read one small file of the counter folder under a shared lock; '' when missing. Creates nothing. */
function cmp_check_stat_read( string $dir, string $name ): string {
	$path = rtrim( $dir, '/' ) . '/' . $name;
	if ( is_link( $path ) || ! is_file( $path ) ) {
		return '';
	}
	$fh = @fopen( $path, 'r' ); // phpcs:ignore
	if ( false === $fh ) {
		return '';
	}
	$raw = '';
	if ( flock( $fh, LOCK_SH ) ) {
		$raw = (string) stream_get_contents( $fh, 4096 );
		flock( $fh, LOCK_UN );
	}
	fclose( $fh );
	return $raw;
}

/** Add one successful check to the total. */
function cmp_check_total_add( string $dir ): bool {
	return cmp_check_stat_update(
		$dir,
		'total-checks.txt',
		function ( $raw ) {
			return (string) ( cmp_check_total_parse( $raw ) + 1 );
		}
	);
}

/** The total of successful checks; 0 when missing or unreadable. */
function cmp_check_total_read( string $dir ): int {
	return cmp_check_total_parse( cmp_check_stat_read( $dir, 'total-checks.txt' ) );
}

/**
 * „Ce găsim cel mai des”: found-stats.json holds the checks counted ('of') and,
 * per kind (cmp_check_found_kind_ids()), how many of them found it. Only these
 * six numbers; a kind is never above 'of'.
 */
function cmp_check_found_parse( string $raw ): array {
	$d   = json_decode( $raw, true );
	$out = array( 'of' => 0 );
	foreach ( cmp_check_found_kind_ids() as $k ) {
		$out[ $k ] = 0;
	}
	if ( ! is_array( $d ) ) {
		return $out;
	}
	$num = function ( $v ) {
		return is_int( $v ) && $v >= 0 ? $v : 0;
	};
	$out['of'] = $num( isset( $d['of'] ) ? $d['of'] : 0 );
	foreach ( cmp_check_found_kind_ids() as $k ) {
		$out[ $k ] = min( $out['of'], $num( isset( $d[ $k ] ) ? $d[ $k ] : 0 ) );
	}
	return $out;
}

/** Count one successful check and the kinds it found (unknown kinds ignored). */
function cmp_check_found_add( string $dir, array $kinds ): bool {
	return cmp_check_stat_update(
		$dir,
		'found-stats.json',
		function ( $raw ) use ( $kinds ) {
			$s = cmp_check_found_parse( $raw );
			$s['of']++;
			foreach ( array_unique( $kinds ) as $k ) {
				if ( is_string( $k ) && in_array( $k, cmp_check_found_kind_ids(), true ) ) {
					$s[ $k ]++;
				}
			}
			return (string) json_encode( $s );
		}
	);
}

/** The stats of „Ce găsim cel mai des”; zeros when missing or unreadable. */
function cmp_check_found_read( string $dir ): array {
	return cmp_check_found_parse( cmp_check_stat_read( $dir, 'found-stats.json' ) );
}
