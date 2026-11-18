<?php
/**
 * Local copy of a YouTube video thumbnail, shown behind the consent placeholder.
 * The visitor's browser never contacts YouTube before consent: the server fetches
 * the image once (no site identity sent) and the page only references the local file.
 */
class CMP_Thumbs {

	const MAX_BYTES = 307200; // 300 KB
	const DIR       = 'cmp-thumbs';

	/** Pure: is $id a YouTube video id (exactly 11 of [A-Za-z0-9_-], nothing else)? */
	public static function valid_id( $id ) {
		return is_string( $id ) && 1 === preg_match( '/\A[A-Za-z0-9_-]{11}\z/', $id );
	}

	/** Pure: is this HTTP response an acceptable JPEG thumbnail? */
	public static function valid_jpeg( $code, $type, $body ) {
		$code = (int) $code;
		$type = (string) $type;
		$body = (string) $body;
		return 200 === $code
			&& 0 === stripos( $type, 'image/jpeg' )
			&& strlen( $body ) <= self::MAX_BYTES
			&& 0 === strncmp( $body, "\xFF\xD8\xFF", 3 );
	}

	const QUEUE_OPTION = 'cmp_thumb_queue';
	const CRON_HOOK    = 'cmp_fetch_thumbs';
	const QUEUE_MAX    = 20;
	const PER_RUN      = 5;

	/** Hook the cron handler that downloads queued thumbnails. */
	public static function register() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_queue' ) );
	}

	/** Pure: add a valid, new id to the queue; the cap drops the newest. */
	public static function queue_add( array $queue, $id, $max ) {
		$id = (string) $id;
		if ( ! self::valid_id( $id ) || in_array( $id, $queue, true ) ) {
			return $queue;
		}
		$queue[] = $id;
		return array_slice( array_values( $queue ), 0, max( 0, (int) $max ) );
	}

	/** Pure: split the queue into the first $n ids and the rest. */
	public static function queue_take( array $queue, $n ) {
		$queue = array_values( $queue );
		$n     = max( 0, (int) $n );
		return array( array_slice( $queue, 0, $n ), array_slice( $queue, $n ) );
	}

	/** Local thumbnail URL when the file exists, else '' (and the id is queued). Never does network I/O. */
	public static function url( $id ) {
		$id = (string) $id;
		if ( ! self::valid_id( $id ) || ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}
		$up = wp_upload_dir();
		if ( ! is_array( $up ) || ! empty( $up['error'] ) ) {
			return '';
		}
		if ( is_file( trailingslashit( $up['basedir'] ) . self::DIR . '/' . $id . '.jpg' ) ) {
			return set_url_scheme( trailingslashit( $up['baseurl'] ) . self::DIR . '/' . $id . '.jpg' );
		}
		if ( ! get_transient( 'cmp_thumb_fail_' . $id ) ) {
			self::enqueue( $id );
		}
		return '';
	}

	/** Queue an id for the background download (writes only when the queue changes). */
	public static function enqueue( $id ) {
		$id = (string) $id;
		if ( ! self::valid_id( $id ) ) {
			return;
		}
		$queue = get_option( self::QUEUE_OPTION, array() );
		$queue = is_array( $queue ) ? $queue : array();
		$new   = self::queue_add( $queue, $id, self::QUEUE_MAX );
		if ( $new !== $queue ) {
			update_option( self::QUEUE_OPTION, $new, false );
		}
		if ( in_array( $id, $new, true ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}
	}

	/** Cron: download up to PER_RUN queued thumbnails; stop at the first network failure. */
	public static function run_queue() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		$queue = is_array( $queue ) ? $queue : array();
		$split = self::queue_take( $queue, self::PER_RUN );
		$done  = array();
		$fail  = false;
		foreach ( $split[0] as $id ) {
			$r = self::fetch( $id );
			if ( 'fail' === $r ) {
				$done[] = $id; // fail transient is set; do not retry for a day
				$fail   = true;
				break;
			}
			$done[] = $id;
		}
		$fresh = get_option( self::QUEUE_OPTION, array() );
		$fresh = is_array( $fresh ) ? array_values( array_diff( $fresh, $done ) ) : array();
		update_option( self::QUEUE_OPTION, $fresh, false );
		if ( ! empty( $fresh ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + ( $fail ? MINUTE_IN_SECONDS : 0 ), self::CRON_HOOK );
		}
	}

	/** Download one thumbnail: 'ok' (stored or already there / failed recently), or 'fail' (network or validation failure). */
	private static function fetch( $id ) {
		if ( ! self::valid_id( $id ) || ! function_exists( 'wp_upload_dir' ) ) {
			return 'ok';
		}
		$up = wp_upload_dir();
		if ( ! is_array( $up ) || ! empty( $up['error'] ) ) {
			return 'ok';
		}
		$dir  = trailingslashit( $up['basedir'] ) . self::DIR;
		$file = $dir . '/' . $id . '.jpg';
		if ( is_file( $file ) || get_transient( 'cmp_thumb_fail_' . $id ) || get_transient( 'cmp_thumb_lock_' . $id ) ) {
			return 'ok';
		}
		set_transient( 'cmp_thumb_lock_' . $id, 1, 30 );
		$ok = false;
		try {
			$res = wp_safe_remote_get(
				'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg',
				array(
					'timeout'             => 3,
					'redirection'         => 0,
					'limit_response_size' => self::MAX_BYTES + 1, // an oversize body exceeds MAX_BYTES and is rejected
					'user-agent'          => 'Mozilla/5.0 (compatible)',
				)
			);
			if ( ! is_wp_error( $res ) ) {
				$body = (string) wp_remote_retrieve_body( $res );
				if ( self::valid_jpeg( (int) wp_remote_retrieve_response_code( $res ), (string) wp_remote_retrieve_header( $res, 'content-type' ), $body ) ) {
					$ok = self::store( $dir, $file, $body );
				}
			}
		} catch ( \Throwable $e ) {
			$ok = false;
		}
		delete_transient( 'cmp_thumb_lock_' . $id );
		if ( ! $ok ) {
			set_transient( 'cmp_thumb_fail_' . $id, 1, DAY_IN_SECONDS );
			return 'fail';
		}
		return 'ok';
	}

	/** Write the file atomically (tmp + rename), creating the directory with a silent index.php. */
	private static function store( $dir, $file, $body ) {
		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return false;
			}
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		$tmp = $file . '.tmp';
		if ( false === @file_put_contents( $tmp, $body ) ) {
			@unlink( $tmp );
			return false;
		}
		if ( ! @rename( $tmp, $file ) ) {
			@unlink( $tmp );
			return false;
		}
		return true;
	}
}
