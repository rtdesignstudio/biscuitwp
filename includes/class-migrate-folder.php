<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Moving the plugin folder from plugins/consent-manager-pro to plugins/biscuitwp,
 * on demand (BiscuitWP 1.1.0, spec "varianta B"): nothing moves by itself.
 *
 * An administrator (super admin in the network admin on multisite) presses
 * "Mută pluginul în folderul biscuitwp" on the plugin's move screen (linked from
 * the Plugins row) and confirms. The move then runs at the very end of that
 * request (shutdown, PHP_INT_MAX), when every PHP file of the request is already
 * loaded, and the next page shows the result.
 *
 * The move:
 *  1. a stub folder (both entry file names, each a headered file that loads the
 *     plugin from biscuitwp/) is built at a hidden temporary path;
 *  2. rename( consent-manager-pro, biscuitwp ), then the stub folder is renamed
 *     into consent-manager-pro/, so both old paths stay valid and loadable;
 *  3. every option holding an old path (either entry file of the old folder) is
 *     rewritten, read and written raw (no option_* filters), on every site;
 *  4. caches are refreshed and the known page caches purged.
 * The stub stays for at least STUB_KEEP seconds (requests already running keep
 * working); cleanup_leftover() removes it later, from a load through biscuitwp/.
 * If an option write fails, the options are restored and checked, then the
 * folder goes back; if anything of that fails, everything points forward to
 * biscuitwp/ instead (the stub keeps the old paths valid). The plugin is never
 * deactivated or reactivated.
 *
 * Deletes only our own stub folders (exact content check). Refuses when the
 * folder is not exactly WP_PLUGIN_DIR/consent-manager-pro (symlinks, mu-plugins),
 * during any update, while another move holds the lock, or on a multi-network.
 */
class CMP_Migrate_Folder {

	const OLD     = 'consent-manager-pro/consent-manager-pro.php';
	const OLD_SIB = 'consent-manager-pro/biscuitwp.php';
	const NEW     = 'biscuitwp/biscuitwp.php';

	const OLD_DIR = 'consent-manager-pro';
	const NEW_DIR = 'biscuitwp';
	/** Hidden (dot) folder in WP_PLUGIN_DIR where the stub is built: get_plugins() skips it. */
	const STUB_TMP = '.biscuitwp-move-stub';

	/** Network option (plain option on single site): array( state => done|failed, reason, ts ). */
	const STATE = 'cmp_folder_move';
	/** Network option: timestamp of the run holding the lock. */
	const LOCK     = 'cmp_folder_move_lock';
	const LOCK_TTL = 300;
	/** Multisite: active_plugins is rewritten on at most this many sites; more means no move. */
	const MAX_SITES = 1000;
	/** WordPress core update locks older than this are stale (WP_Upgrader::create_lock default). */
	const CORE_LOCK_TTL = 3600;
	/** The stub stays at least this long after the move (requests already running). */
	const STUB_KEEP = 3600;
	/** Marks the stub left at the old path (see stub_source()). */
	const STUB_MARKER = 'BiscuitWP folder-move stub v1';

	/** The move screen (admin.php?page=…) and its admin-post action / nonce. */
	const PAGE   = 'cmp-folder-move';
	const ACTION = 'cmp_folder_move';

	/** The options that hold plugin paths: key => list|map. */
	const OPTIONS = array(
		'active_plugins'          => 'list',
		'uninstall_plugins'       => 'map',
		'auto_update_plugins'     => 'list',
		'active_sitewide_plugins' => 'map',
	);

	/**
	 * Test-only seam, set exclusively through Reflection by tests/test-migrate-folder.php:
	 * fail_stub (bool: writing a stub folder fails), fail_place (bool: renaming the
	 * stub into place fails), fail_rename_back (bool), on_step (callable, called with
	 * the step name). Always empty in production; no constant, option or filter fills it.
	 *
	 * @var array
	 */
	private static $seam = array();

	/** Set by the confirmed admin-post request; run() does nothing without it. */
	private static $queued = false;

	/** Token of this attempt, stored with the result and carried by the redirect. */
	private static $attempt = '';

	/** The old folder this run works on (current_old_dir(), or a stub candidate in the cleanup). */
	private static $from = '';

	// ---------------------------------------------------------------------
	// Registration (from CMP_Plugin).
	// ---------------------------------------------------------------------

	/** Admin: the move screen, its admin-post handler and the Plugins row link. Hooks only. */
	public static function register_admin() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'network_admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_init', array( __CLASS__, 'purge_after_move' ) );
		add_filter( 'plugin_action_links_' . CMP_Plugin::basename(), array( __CLASS__, 'row_link' ) );
		add_filter( 'network_admin_plugin_action_links_' . CMP_Plugin::basename(), array( __CLASS__, 'row_link' ) );
	}

	/** Admin or cron, loaded through biscuitwp/ while plugins/consent-manager-pro exists. */
	public static function register_cleanup() {
		add_action( 'shutdown', array( __CLASS__, 'cleanup_leftover' ), PHP_INT_MAX );
	}

	/**
	 * The folder the plugin runs from, when the move applies to it, else ''. It must
	 * be a direct child of WP_PLUGIN_DIR (one name, no dot folder), not biscuitwp,
	 * not a symlink, the very folder of the loaded entry file (realpath), and hold
	 * both entry files with our header. Usually consent-manager-pro; sites installed
	 * from a GitHub zip may have another name (e.g. consent-manager-pro-main).
	 */
	public static function current_old_dir() {
		if ( ! defined( 'WP_PLUGIN_DIR' ) || ! defined( 'CMP_ENTRY' ) || ! class_exists( 'CMP_Plugin' ) ) {
			return '';
		}
		$dir = dirname( CMP_Plugin::basename() );
		if ( ! self::valid_dir_name( $dir ) ) {
			return '';
		}
		$path = WP_PLUGIN_DIR . '/' . $dir;
		if ( is_link( $path ) || ! is_dir( $path ) ) {
			return '';
		}
		$here = realpath( dirname( CMP_ENTRY ) );
		if ( false === $here || realpath( $path ) !== $here ) {
			return '';
		}
		foreach ( array( 'biscuitwp.php', basename( self::OLD ) ) as $file ) {
			if ( is_link( $path . '/' . $file ) || ! is_file( $path . '/' . $file ) ) {
				return '';
			}
			$hdr = self::header_block( (string) @file_get_contents( $path . '/' . $file, false, null, 0, 8192 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === strpos( $hdr, "\n * Plugin Name: BiscuitWP\n" ) || false === strpos( $hdr, "\n * Text Domain: biscuitwp\n" ) ) {
				return '';
			}
		}
		return $dir;
	}

	/** A plain folder name directly in WP_PLUGIN_DIR that may be an old folder. Pure. */
	public static function valid_dir_name( $dir ) {
		return is_string( $dir ) && '' !== $dir && self::NEW_DIR !== $dir && 1 === preg_match( '#^[^./\\\\][^/\\\\]*$#', $dir );
	}

	/** The plugin runs from a folder the move applies to (see current_old_dir()). */
	public static function runs_from_old_folder() {
		return '' !== self::current_old_dir();
	}

	/**
	 * Folders that may hold our stub after a move: the one recorded by the move
	 * (`from`) and consent-manager-pro. Names only, validated.
	 */
	public static function stub_candidates() {
		$out   = array( self::OLD_DIR );
		$state = function_exists( 'get_site_option' ) ? get_site_option( self::STATE, array() ) : array();
		if ( is_array( $state ) && isset( $state['from'] ) && self::valid_dir_name( $state['from'] ) && ! in_array( $state['from'], $out, true ) ) {
			array_unshift( $out, (string) $state['from'] );
		}
		return $out;
	}

	/** Does any stub candidate folder exist (so the cleanup is worth registering)? */
	public static function stub_candidate_exists() {
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			return false;
		}
		foreach ( self::stub_candidates() as $dir ) {
			if ( is_dir( WP_PLUGIN_DIR . '/' . $dir ) ) {
				return true;
			}
		}
		return false;
	}

	/** Who may move: manage_options on single site; a super admin on multisite. */
	public static function user_may_move() {
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			return function_exists( 'is_super_admin' ) && is_super_admin();
		}
		return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
	}

	/** The button / link is offered here: old folder, allowed user, and on multisite the network admin only. */
	public static function offer_here() {
		if ( ! self::runs_from_old_folder() || ! self::user_may_move() ) {
			return false;
		}
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			return function_exists( 'is_network_admin' ) && is_network_admin();
		}
		return true;
	}

	/** URL of the move screen (network admin on multisite). */
	public static function page_url( array $args = array() ) {
		$multisite = function_exists( 'is_multisite' ) && is_multisite();
		$url       = $multisite ? network_admin_url( 'admin.php?page=' . self::PAGE ) : admin_url( 'admin.php?page=' . self::PAGE );
		foreach ( $args as $k => $v ) {
			$url .= '&' . rawurlencode( $k ) . '=' . rawurlencode( (string) $v );
		}
		return $url;
	}

	/** admin_menu / network_admin_menu: the move screen (hidden from the menu). */
	public static function add_page() {
		$multisite = function_exists( 'is_multisite' ) && is_multisite();
		if ( $multisite && ! ( function_exists( 'is_network_admin' ) && is_network_admin() ) ) {
			return;
		}
		add_submenu_page( 'options.php', __( 'Mută pluginul în folderul biscuitwp', 'biscuitwp' ), __( 'Mută pluginul în folderul biscuitwp', 'biscuitwp' ), $multisite ? 'manage_network_options' : 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	/** plugin_action_links: "Mută pluginul în folderul biscuitwp" on the active row, where offered. */
	public static function row_link( $links ) {
		if ( ! is_array( $links ) || ! self::offer_here() ) {
			return $links;
		}
		$links[] = '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Mută pluginul în folderul biscuitwp', 'biscuitwp' ) . '</a>';
		return $links;
	}

	/** The move screen: the result of the last move, then the confirmation form where offered. */
	public static function render() {
		if ( ! self::user_may_move() ) {
			return;
		}
		echo '<div class="wrap cmp-admin"><h1>' . esc_html__( 'Mută pluginul în folderul biscuitwp', 'biscuitwp' ) . '</h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$has_result = isset( $_GET['cmp_moved'] );
		if ( $has_result ) {
			$state = get_site_option( self::STATE, array() );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			$token = sanitize_key( wp_unslash( $_GET['cmp_moved'] ) );
			if ( self::result_applies( $state, $token ) ) {
				echo self::result_html( $state ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in result_html().
			} else {
				echo '<div class="notice notice-info"><p>' . esc_html__( 'Rezultatul mutării nu e încă disponibil. Reîncarcă pagina peste câteva secunde.', 'biscuitwp' ) . '</p></div>';
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['cmp_confirm_missing'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Bifează confirmarea ca să muți pluginul.', 'biscuitwp' ) . '</p></div>';
		}
		if ( self::offer_here() ) {
			$from = 'plugins/' . self::current_old_dir();
			/* translators: %s: the current folder, e.g. plugins/consent-manager-pro */
			echo '<p>' . esc_html( sprintf( __( 'Pluginul rulează încă din folderul vechi, %s. Butonul de mai jos îl mută în plugins/biscuitwp, fără să-l dezactiveze: setările, registrul și paginile legale rămân la fel. După mutare, golește cache-ul paginilor și verifică bannerul pe site.', 'biscuitwp' ), $from ) ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
			wp_nonce_field( self::ACTION );
			// Explicit <label for>: the checkbox's accessible name is the sentence, not its value.
			echo '<p><input type="checkbox" id="cmp-confirm" name="cmp_confirm" value="1" required> <label for="cmp-confirm">' . esc_html( sprintf( /* translators: %s: the current folder, e.g. plugins/consent-manager-pro */ __( 'Am înțeles: pluginul își mută folderul din %s în plugins/biscuitwp, fără să se dezactiveze.', 'biscuitwp' ), $from ) ) . '</label></p>';
			echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Mută pluginul în folderul biscuitwp', 'biscuitwp' ) . '</button></p></form>';
		} elseif ( ! $has_result && ! self::runs_from_old_folder() ) {
			// Not under a move result: right after a move the plugin no longer runs from the old folder by design.
			echo '<p>' . esc_html__( 'Pluginul nu rulează dintr-un folder vechi care poate fi mutat (de exemplu, rulează deja din plugins/biscuitwp). Nu e nimic de mutat.', 'biscuitwp' ) . '</p>';
		}
		echo '</div>';
	}

	/** The result of the last move, escaped HTML ('' when none). */
	public static function result_html( $state ) {
		if ( ! is_array( $state ) || ! isset( $state['state'] ) ) {
			return '';
		}
		if ( self::is_moved( $state ) ) {
			$home = function_exists( 'home_url' ) ? home_url( '/' ) : '/';
			$out  = '<div class="notice notice-success"><p>'
				. esc_html__( 'BiscuitWP rulează acum din folderul plugins/biscuitwp. Golește cache-ul paginilor (pluginul de cache, serverul sau CDN-ul), apoi deschide prima pagină a site-ului și verifică dacă apare bannerul:', 'biscuitwp' )
				. ' <a href="' . esc_url( $home ) . '" target="_blank" rel="noopener">' . esc_html( $home ) . '</a></p></div>';
			if ( 'done' !== $state['state'] ) {
				// The forward fallback: moved, but the failure detail stays visible.
				$out .= '<div class="notice notice-warning"><p>' . esc_html__( 'Mutarea a întâmpinat o eroare la scrierea setărilor, iar revenirea la folderul vechi nu a fost posibilă, așa că setările au fost îndreptate spre folderul nou. Verifică pe ecranul Plugins că BiscuitWP e activ.', 'biscuitwp' ) . '</p></div>';
			}
			return $out;
		}
		return '<div class="notice notice-error"><p>'
			. esc_html( sprintf( /* translators: %s: the reason */ __( 'BiscuitWP nu și-a putut muta folderul și merge în continuare din cel vechi. Motiv: %s', 'biscuitwp' ), self::reason_text( isset( $state['reason'] ) ? (string) $state['reason'] : '' ) ) )
			. '</p></div>';
	}

	/** Human text for a failure reason. */
	public static function reason_text( $reason ) {
		switch ( $reason ) {
			case 'new_exists':
				return __( 'există deja un folder plugins/biscuitwp. Șterge-l sau redenumește-l, apoi încearcă din nou.', 'biscuitwp' );
			case 'not_writable':
				return __( 'serverul nu permite scrierea în folderul plugins. Cere-i celui care administrează serverul să o permită, apoi încearcă din nou.', 'biscuitwp' );
			case 'busy':
				return __( 'chiar acum rulează un update sau o altă mutare. Încearcă din nou peste câteva minute.', 'biscuitwp' );
			case 'sites':
			case 'networks':
				return __( 'rețeaua are prea multe site-uri sau mai multe rețele, iar mutarea nu le poate acoperi pe toate în siguranță.', 'biscuitwp' );
			case 'not_active':
				return __( 'pluginul nu e activ din folderul vechi pe niciun site.', 'biscuitwp' );
			default:
				/* translators: %s: internal error code */
				return sprintf( __( 'o eroare la mutare (cod: %s). Nimic nu s-a pierdut; încearcă din nou mai târziu.', 'biscuitwp' ), $reason );
		}
	}

	/**
	 * The confirmed form, pure: what to do. 'denied' (not allowed), 'confirm'
	 * (checkbox missing), 'not_eligible' (not in the old folder), 'queued'.
	 */
	public static function check_request( array $post, $allowed ) {
		if ( ! $allowed ) {
			return 'denied';
		}
		if ( empty( $post['cmp_confirm'] ) ) {
			return 'confirm';
		}
		if ( ! self::runs_from_old_folder() ) {
			return 'not_eligible';
		}
		return 'queued';
	}

	/** admin_post_cmp_folder_move: nonce + confirmation, then the move at shutdown; redirect to the result. */
	public static function handle() {
		if ( ! self::user_may_move() ) {
			wp_die( esc_html__( 'Nu ai voie să muți pluginul.', 'biscuitwp' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$what = self::check_request( wp_unslash( $_POST ), true );
		if ( 'confirm' === $what ) {
			wp_safe_redirect( self::page_url( array( 'cmp_confirm_missing' => 1 ) ) );
			exit;
		}
		$token = function_exists( 'wp_generate_password' ) ? strtolower( wp_generate_password( 12, false ) ) : substr( md5( uniqid( '', true ) ), 0, 12 );
		if ( 'queued' === $what ) {
			self::queue( $token );
		}
		wp_safe_redirect( self::page_url( array( 'cmp_moved' => $token ) ) );
		exit; // The move runs at shutdown of this request; the browser follows the redirect after it.
	}

	/** Queue the move for the end of this request; $token marks this attempt's result. */
	public static function queue( $token = '' ) {
		self::$queued  = true;
		self::$attempt = (string) $token;
		add_action( 'shutdown', array( __CLASS__, 'run' ), PHP_INT_MAX );
	}

	// ---------------------------------------------------------------------
	// Pure helpers.
	// ---------------------------------------------------------------------

	/** The two old-folder paths, both moved to NEW. */
	public static function old_paths( $dir = null ) {
		if ( null === $dir ) {
			$dir = '' !== self::$from ? self::$from : self::OLD_DIR;
		}
		return array( $dir . '/' . basename( self::OLD ), $dir . '/' . basename( self::OLD_SIB ) );
	}

	/**
	 * List option (active_plugins, auto_update_plugins): either old path becomes
	 * NEW (at the first old position), order kept, never a duplicate. Pure.
	 */
	public static function replace_in_list( array $plugins, ?array $old = null ) {
		$old = null === $old ? self::old_paths() : $old;
		$has_new = in_array( self::NEW, $plugins, true );
		$out     = array();
		foreach ( $plugins as $p ) {
			if ( in_array( $p, $old, true ) ) {
				if ( $has_new ) {
					continue;
				}
				$p       = self::NEW;
				$has_new = true;
			}
			$out[] = $p;
		}
		return $out;
	}

	/**
	 * Map option (active_sitewide_plugins, uninstall_plugins): an old-path key
	 * becomes NEW, value and position kept (the first one wins); an existing NEW
	 * key keeps its own value. Pure.
	 */
	public static function replace_in_map( array $map, ?array $old = null ) {
		$old = null === $old ? self::old_paths() : $old;
		$has_new = array_key_exists( self::NEW, $map );
		$out     = array();
		foreach ( $map as $k => $v ) {
			if ( in_array( $k, $old, true ) ) {
				if ( $has_new ) {
					continue;
				}
				$k       = self::NEW;
				$has_new = true;
			}
			$out[ $k ] = $v;
		}
		return $out;
	}

	/**
	 * Move or not. Pure. Order: not in the old folder or old folder gone → skip;
	 * locked or an update running → busy; plugins/biscuitwp exists →
	 * fail:new_exists; plugins/ not writable → fail:not_writable; else move.
	 *
	 * @param array $s legacy (runs from the old folder), old_dir_exists, new_dir_exists,
	 *                 plugins_writable, locked, upgrading (bool).
	 * @return string 'move', 'skip', 'busy', 'fail:new_exists' or 'fail:not_writable'.
	 */
	public static function decide( array $s ) {
		$s += array(
			'legacy'           => false,
			'old_dir_exists'   => false,
			'new_dir_exists'   => false,
			'plugins_writable' => false,
			'locked'           => false,
			'upgrading'        => false,
		);
		if ( ! $s['legacy'] || ! $s['old_dir_exists'] ) {
			return 'skip';
		}
		if ( $s['locked'] || $s['upgrading'] ) {
			return 'busy';
		}
		if ( $s['new_dir_exists'] ) {
			return 'fail:new_exists';
		}
		if ( ! $s['plugins_writable'] ) {
			return 'fail:not_writable';
		}
		return 'move';
	}

	/**
	 * The plugin header docblock opening $php (the one right after "<?php"), or ''
	 * when it carries no Plugin Name. Pure.
	 */
	public static function header_block( $php ) {
		if ( is_string( $php ) && 1 === preg_match( '#\A<\?php\s*\n(/\*\*\n.*?\*/)#s', $php, $m ) && false !== strpos( $m[1], ' * Plugin Name: ' ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * The stub written under both entry file names in plugins/consent-manager-pro:
	 * a request that still reads an old path loads the plugin through the legacy
	 * loader in the new folder. It carries the plugin header ($header: biscuitwp.php's,
	 * read before the rename), because WordPress deactivates an active plugin file
	 * without one (validate_active_plugins()). '' when there is no header.
	 *
	 * @param string|null $header Header docblock; null reads it from this plugin's biscuitwp.php.
	 */
	public static function stub_source( $header = null ) {
		if ( null === $header ) {
			$header = self::header_block( (string) @file_get_contents( dirname( __DIR__ ) . '/biscuitwp.php' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( '' === self::header_block( "<?php\n" . $header ) ) {
			return '';
		}
		return "<?php\n" . $header . "\n\n"
			. '// ' . self::STUB_MARKER . ". Temporary: BiscuitWP removes it after its folder move.\n"
			. "if ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n"
			. "\$cmp_stub_target = dirname( __DIR__ ) . '/biscuitwp/consent-manager-pro.php';\n"
			. "if ( is_readable( \$cmp_stub_target ) ) {\n\trequire_once \$cmp_stub_target;\n}\n"
			. "unset( \$cmp_stub_target );\n";
	}

	/** File names of a stub folder: both entry files of the old folder. */
	private static function stub_files() {
		return array( basename( self::OLD_SIB ), basename( self::OLD ) );
	}

	/**
	 * Does $dir hold exactly our stub, and nothing else? Exactly the two entry
	 * files, each exactly what stub_source() writes for the header it carries
	 * (any version).
	 */
	public static function is_our_stub_dir( $dir ) {
		if ( is_link( $dir ) || ! is_dir( $dir ) ) {
			return false;
		}
		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! is_array( $entries ) ) {
			return false;
		}
		$entries = array_values( array_diff( $entries, array( '.', '..' ) ) );
		$want    = self::stub_files();
		sort( $entries );
		sort( $want );
		if ( $want !== $entries ) {
			return false;
		}
		foreach ( $want as $name ) {
			$file = $dir . '/' . $name;
			if ( is_link( $file ) || ! is_file( $file ) || filesize( $file ) > 16384 ) {
				return false;
			}
			$src = (string) @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$hdr = self::header_block( $src );
			if ( '' === $hdr || self::stub_source( $hdr ) !== $src ) {
				return false;
			}
		}
		return true;
	}

	// ---------------------------------------------------------------------
	// Filesystem (the only deletions: remove_stub_dir() and make_stub()).
	// ---------------------------------------------------------------------

	/** Remove $dir only when it holds exactly our stub. True when the folder is gone. */
	private static function remove_stub_dir( $dir ) {
		if ( ! self::is_our_stub_dir( $dir ) ) {
			return false;
		}
		foreach ( self::stub_files() as $name ) {
			@unlink( $dir . '/' . $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		clearstatcache();
		return ! file_exists( $dir );
	}

	/**
	 * Uninstall (CMP_Uninstaller): remove the stub folder left at the old path and
	 * the hidden build folder, each only when it holds exactly our stub files.
	 *
	 * @param string $plugins_dir WP_PLUGIN_DIR.
	 */
	public static function remove_stub_folders( $plugins_dir ) {
		$base    = rtrim( (string) $plugins_dir, '/' );
		$entries = is_dir( $base ) ? @scandir( $base ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$names   = array( self::STUB_TMP );
		foreach ( is_array( $entries ) ? $entries : array() as $name ) {
			if ( self::valid_dir_name( $name ) ) {
				$names[] = $name; // Any old folder name; removed only when it is exactly our stub.
			}
		}
		foreach ( $names as $name ) {
			self::remove_stub_dir( $base . '/' . $name );
		}
	}

	/** Create $dir (must not exist) with only the stub files. On failure, nothing of it is left. */
	private static function make_stub( $dir, $src ) {
		if ( '' === $src || file_exists( $dir ) || is_link( $dir ) ) {
			return false;
		}
		if ( ! @mkdir( $dir, defined( 'FS_CHMOD_DIR' ) ? FS_CHMOD_DIR : 0755 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false;
		}
		$ok = empty( self::$seam['fail_stub'] );
		foreach ( self::stub_files() as $name ) {
			$ok = $ok && strlen( $src ) === @file_put_contents( $dir . '/' . $name, $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( ! $ok ) {
			// Only what this call just created, in the fresh folder.
			foreach ( self::stub_files() as $name ) {
				if ( is_file( $dir . '/' . $name ) ) {
					@unlink( $dir . '/' . $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			clearstatcache();
			return false;
		}
		return true;
	}

	private static function rename_back( $new, $old ) {
		return empty( self::$seam['fail_rename_back'] ) && @rename( $new, $old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	private static function place_stub( $tmp, $old ) {
		return empty( self::$seam['fail_place'] ) && @rename( $tmp, $old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	private static function step( $name ) {
		if ( isset( self::$seam['on_step'] ) && is_callable( self::$seam['on_step'] ) ) {
			call_user_func( self::$seam['on_step'], $name );
		}
	}

	// ---------------------------------------------------------------------
	// Raw option access (I5).
	// ---------------------------------------------------------------------

	/**
	 * Read an option bypassing every option filter: the filters on it are taken
	 * off $wp_filter for the read and put back right after. Chosen over a direct
	 * $wpdb query so the read still goes through WordPress's own storage (object
	 * cache, alloptions, the multisite sitemeta table, any db drop-in) and needs no
	 * hand-written SQL per table; only the filters (plugin-loading managers that
	 * trim active_plugins per page) are bypassed, so the stored value is what gets
	 * rewritten and restored.
	 *
	 * @param string $scope 'site' (network option; plain option on single site) or 'blog'.
	 */
	private static function raw_get( $scope, $key ) {
		global $wp_filter;
		$hooks = array(
			'pre_option_' . $key, 'option_' . $key, 'default_option_' . $key, 'pre_option',
			'pre_site_option_' . $key, 'site_option_' . $key, 'default_site_option_' . $key, 'pre_site_option',
		);
		$saved = array();
		if ( is_array( $wp_filter ) ) {
			foreach ( $hooks as $h ) {
				if ( array_key_exists( $h, $wp_filter ) ) {
					$saved[ $h ] = $wp_filter[ $h ];
					unset( $wp_filter[ $h ] );
				}
			}
		}
		try {
			$v = 'site' === $scope ? get_site_option( $key, false ) : get_option( $key, false );
		} finally {
			foreach ( $saved as $h => $hook ) {
				$wp_filter[ $h ] = $hook; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}
		}
		return $v;
	}

	/** Read one planned option raw, on its site. */
	private static function read( array $c ) {
		if ( 'blog' === $c['scope'] && $c['blog'] > 0 ) {
			switch_to_blog( $c['blog'] );
			$v = self::raw_get( 'blog', $c['key'] );
			restore_current_blog();
			return $v;
		}
		return self::raw_get( $c['scope'], $c['key'] );
	}

	/** Write one planned option to $value; true only when the raw value read back is $value. */
	private static function write( array $c, $value ) {
		if ( 'site' === $c['scope'] ) {
			$ok = update_site_option( $c['key'], $value );
		} elseif ( $c['blog'] > 0 ) {
			switch_to_blog( $c['blog'] );
			$ok = update_option( $c['key'], $value );
			restore_current_blog();
		} else {
			$ok = update_option( $c['key'], $value );
		}
		return (bool) $ok && self::same( $c['key'], self::read( $c ), $value );
	}

	/**
	 * The stored value equals $want: lists as sets (a pre_update filter may reorder
	 * active_plugins), maps regardless of key order, anything else exactly.
	 */
	private static function same( $key, $got, $want ) {
		if ( ! is_array( $got ) || ! is_array( $want ) || ! isset( self::OPTIONS[ $key ] ) ) {
			return $got === $want;
		}
		if ( 'list' === self::OPTIONS[ $key ] ) {
			$got  = array_values( $got );
			$want = array_values( $want );
			sort( $got, SORT_STRING );
			sort( $want, SORT_STRING );
		} else {
			ksort( $got, SORT_STRING );
			ksort( $want, SORT_STRING );
		}
		return $got === $want;
	}

	/**
	 * Every option write the move needs, from the raw stored values, with the
	 * original kept for the rollback. Blog options first (per site on multisite),
	 * network options last.
	 *
	 * @return array active (bool: the plugin is active through an old path somewhere), changes (list).
	 */
	private static function plan( $multisite ) {
		$changes = array();
		$active  = false;
		$add     = function ( $scope, $blog, $key ) use ( &$changes, &$active ) {
			$c    = array( 'scope' => $scope, 'blog' => $blog, 'key' => $key );
			$orig = self::read( $c );
			if ( ! is_array( $orig ) ) {
				return;
			}
			$next = 'map' === self::OPTIONS[ $key ] ? self::replace_in_map( $orig ) : self::replace_in_list( $orig );
			if ( $next === $orig ) {
				return;
			}
			if ( 'active_plugins' === $key || 'active_sitewide_plugins' === $key ) {
				$active = true;
			}
			$changes[] = $c + array( 'orig' => $orig, 'new' => $next );
		};
		if ( $multisite ) {
			foreach ( array_map( 'intval', (array) get_sites( array( 'fields' => 'ids', 'number' => self::MAX_SITES ) ) ) as $id ) {
				$add( 'blog', $id, 'active_plugins' );
				$add( 'blog', $id, 'uninstall_plugins' );
				// Core keeps auto_update_plugins as a network option; a per-site copy is unusual but fixed too.
				$add( 'blog', $id, 'auto_update_plugins' );
			}
			$add( 'site', 0, 'active_sitewide_plugins' );
		} else {
			$add( 'blog', 0, 'active_plugins' );
			$add( 'blog', 0, 'uninstall_plugins' );
		}
		// get_site_option on single site reads the plain option, as core does.
		$add( 'site', 0, 'auto_update_plugins' );
		return array( 'active' => $active, 'changes' => $changes );
	}

	// ---------------------------------------------------------------------
	// The move.
	// ---------------------------------------------------------------------

	/** shutdown (PHP_INT_MAX) of the confirmed request: the move. Never throws. */
	public static function run() {
		if ( ! self::$queued ) {
			return;
		}
		self::$queued = false;
		try {
			self::do_run();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	private static function do_run() {
		$dir = self::current_old_dir();
		if ( '' === $dir ) {
			return;
		}
		self::$from = $dir;
		$now        = time();
		$old        = WP_PLUGIN_DIR . '/' . $dir;
		if ( self::is_our_stub_dir( $old ) ) {
			return; // Already moved, only the stub is left: a request that started before the move.
		}
		$new = WP_PLUGIN_DIR . '/' . self::NEW_DIR;
		$tmp = WP_PLUGIN_DIR . '/' . self::STUB_TMP;

		if ( function_exists( 'get_networks' ) && (int) get_networks( array( 'count' => true ) ) > 1 ) {
			self::record( 'failed', 'networks', $now );
			return;
		}
		$lock     = get_site_option( self::LOCK, false );
		$decision = self::decide(
			array(
				'legacy'           => true,
				'old_dir_exists'   => true,
				'new_dir_exists'   => file_exists( $new ) || is_link( $new ),
				'plugins_writable' => is_writable( WP_PLUGIN_DIR ),
				'locked'           => false !== $lock && (int) $lock > $now - self::LOCK_TTL,
				'upgrading'        => self::upgrading( $now ),
			)
		);
		if ( 'skip' === $decision ) {
			return;
		}
		if ( 'busy' === $decision ) {
			self::record( 'failed', 'busy', $now );
			return;
		}
		if ( 0 === strpos( $decision, 'fail:' ) ) {
			self::record( 'failed', substr( $decision, 5 ), $now );
			return;
		}

		// Multisite: every site's active_plugins must be covered, or a site beyond the
		// loop would lose the plugin. Too many sites: stay in the old folder.
		$multisite = function_exists( 'is_multisite' ) && is_multisite();
		if ( $multisite && (int) get_sites( array( 'count' => true ) ) > self::MAX_SITES ) {
			self::record( 'failed', 'sites', $now );
			return;
		}
		$plan = self::plan( $multisite );
		if ( ! $plan['active'] ) {
			self::record( 'failed', 'not_active', $now );
			return;
		}

		// Read before the rename: afterwards this file's own folder is gone.
		$stub = self::stub_source();
		if ( '' === $stub ) {
			self::record( 'failed', 'stub', $now );
			return;
		}
		if ( ! self::lock( $lock, $now ) ) {
			self::record( 'failed', 'busy', $now );
			return;
		}
		// The stub is built aside first, so the old path is replaced by one rename.
		if ( file_exists( $tmp ) && ! self::remove_stub_dir( $tmp ) ) {
			self::record( 'failed', 'stub', $now );
			self::unlock();
			return;
		}
		if ( ! self::make_stub( $tmp, $stub ) ) {
			self::record( 'failed', 'stub', $now );
			self::unlock();
			return;
		}

		if ( ! @rename( $old, $new ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			self::remove_stub_dir( $tmp );
			self::record( 'failed', 'rename', $now );
			self::unlock();
			return;
		}
		if ( ! self::place_stub( $tmp, $old ) ) {
			if ( self::rename_back( $new, $old ) ) {
				self::remove_stub_dir( $tmp );
				self::step( 'renamed_back' );
				self::record( 'failed', 'stub', $now );
			} else {
				self::remove_stub_dir( $tmp );
				self::forward( $plan['changes'], $old, $stub, $now );
			}
			self::unlock();
			return;
		}
		self::step( 'stub' );

		$done = array();
		foreach ( $plan['changes'] as $c ) {
			if ( ! self::write( $c, $c['new'] ) ) {
				self::rollback( $done, $plan['changes'], $old, $new, $stub, $now );
				return;
			}
			$done[] = $c;
			self::step( 'option' );
		}

		// The stub stays (requests already running may still read an old path);
		// cleanup_leftover() removes it after STUB_KEEP seconds.
		self::record( 'done', '', $now, true );
		self::refresh_caches();
		self::purge_page_caches();
		if ( function_exists( 'do_action' ) ) {
			do_action( 'biscuitwp_folder_moved', $old, $new );
		}
		self::step( 'done' );
		self::unlock();
	}

	/**
	 * The folder could not go back to plugins/consent-manager-pro: keep the stub at
	 * the old path (recreated if needed) and point the options to biscuitwp/ (best
	 * effort). A later load through biscuitwp/ removes the stub (cleanup_leftover()).
	 */
	private static function forward( array $all, $old, $stub, $now ) {
		if ( ! file_exists( $old ) ) {
			self::make_stub( $old, $stub );
		}
		self::step( 'forward_stub' );
		foreach ( $all as $c ) {
			self::write( $c, $c['new'] );
		}
		self::step( 'forward' );
		self::record( 'failed', 'rollback', $now, true );
		self::refresh_caches();
		self::purge_page_caches();
	}

	/**
	 * An option write failed after the rename: restore every option already
	 * written (newest first; the stub keeps the old paths loadable meanwhile) and
	 * read each one back. Only when every option holds its original again, remove
	 * the stub and rename the folder back; otherwise forward().
	 */
	private static function rollback( array $done, array $all, $old, $new, $stub, $now ) {
		foreach ( array_reverse( $done ) as $c ) {
			self::write( $c, $c['orig'] );
		}
		$restored = true;
		foreach ( $all as $c ) {
			if ( ! self::same( $c['key'], self::read( $c ), $c['orig'] ) ) {
				$restored = false;
			}
		}
		self::step( 'restored' );
		if ( $restored && self::remove_stub_dir( $old ) && self::rename_back( $new, $old ) ) {
			self::step( 'renamed_back' );
			self::record( 'failed', 'options', $now );
		} else {
			self::forward( $all, $old, $stub, $now );
		}
		self::unlock();
	}

	/**
	 * An update in progress, in this request or another: installing, core or
	 * auto-update lock held (read on the main site, where WP_Upgrader keeps them),
	 * maintenance mode, or an upgrader ran in this request.
	 */
	private static function upgrading( $now ) {
		if ( function_exists( 'wp_installing' ) && wp_installing() ) {
			return true;
		}
		$switched = false;
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_main_site_id' ) ) {
			switch_to_blog( (int) get_main_site_id() );
			$switched = true;
		}
		$locked = false;
		foreach ( array( 'core_updater.lock', 'auto_updater.lock' ) as $opt ) {
			$t = get_option( $opt, false );
			if ( false !== $t && (int) $t > $now - self::CORE_LOCK_TTL ) {
				$locked = true;
			}
		}
		if ( $switched ) {
			restore_current_blog();
		}
		if ( $locked ) {
			return true;
		}
		if ( defined( 'ABSPATH' ) && file_exists( ABSPATH . '.maintenance' ) ) {
			return true;
		}
		if ( function_exists( 'did_action' ) && did_action( 'upgrader_process_complete' ) ) {
			return true;
		}
		if ( function_exists( 'doing_action' ) && doing_action( 'upgrader_process_complete' ) ) {
			return true;
		}
		return class_exists( 'WP_Upgrader', false );
	}

	/**
	 * shutdown, on an admin or cron load through biscuitwp/: the stub folder at
	 * plugins/consent-manager-pro is removed once it is older than STUB_KEEP and no
	 * option points to an old path any more; a stub left at the temporary path is
	 * removed too. Only folders holding exactly our stub. Never throws.
	 */
	public static function cleanup_leftover() {
		try {
			if ( ! defined( 'WP_PLUGIN_DIR' ) || ! defined( 'CMP_ENTRY' ) || ! class_exists( 'CMP_Plugin' ) ) {
				return;
			}
			if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				return;
			}
			$new = WP_PLUGIN_DIR . '/' . self::NEW_DIR;
			if ( self::NEW_DIR !== dirname( CMP_Plugin::basename() ) || realpath( dirname( CMP_ENTRY ) ) !== realpath( $new ) ) {
				return;
			}
			if ( self::is_our_stub_dir( WP_PLUGIN_DIR . '/' . self::STUB_TMP ) ) {
				self::remove_stub_dir( WP_PLUGIN_DIR . '/' . self::STUB_TMP );
			}
			$state = get_site_option( self::STATE, array() );
			$ts    = is_array( $state ) && isset( $state['ts'] ) ? (int) $state['ts'] : 0;
			if ( time() - $ts < self::STUB_KEEP ) {
				return;
			}
			$multisite = function_exists( 'is_multisite' ) && is_multisite();
			if ( $multisite && (int) get_sites( array( 'count' => true ) ) > self::MAX_SITES ) {
				return;
			}
			foreach ( self::stub_candidates() as $dir ) {
				$old = WP_PLUGIN_DIR . '/' . $dir;
				if ( ! self::is_our_stub_dir( $old ) ) {
					continue;
				}
				self::$from = $dir;
				$plan       = self::plan( $multisite );
				if ( ! $plan['active'] ) { // Otherwise some site still activates an old path there: the stub keeps it loadable.
					self::remove_stub_dir( $old );
				}
			}
			self::$from = '';
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/** Take the lock (a stale one, older than LOCK_TTL, is replaced). */
	private static function lock( $current, $now ) {
		if ( false !== $current ) {
			delete_site_option( self::LOCK ); // Stale: decide() already refused a fresh one.
		}
		return (bool) add_site_option( self::LOCK, $now );
	}

	private static function unlock() {
		delete_site_option( self::LOCK );
	}

	/**
	 * Record the outcome. `moved`: the folder is now plugins/biscuitwp (a done move,
	 * or the forward fallback after a failed rollback), whatever `state` says.
	 */
	private static function record( $state, $reason, $now, $moved = false ) {
		update_site_option(
			self::STATE,
			array(
				'state'   => $state,
				'reason'  => (string) $reason,
				'ts'      => (int) $now,
				'attempt' => self::$attempt,
				'moved'   => (bool) $moved,
				'from'    => self::$from,
			)
		);
	}

	/** The recorded outcome left the plugin in plugins/biscuitwp (done, or forward). */
	public static function is_moved( $state ) {
		return is_array( $state ) && isset( $state['state'] ) && ( 'done' === $state['state'] || ! empty( $state['moved'] ) );
	}

	/**
	 * Does the result page show / act on $state for the token in the URL? Its own
	 * attempt, or (double submit, a stale form: the token is another attempt's) any
	 * moved outcome once the plugin no longer runs from the old folder.
	 */
	private static function result_applies( $state, $token ) {
		if ( ! is_array( $state ) || ! isset( $state['state'] ) || '' === $token ) {
			return false;
		}
		if ( isset( $state['attempt'] ) && $token === $state['attempt'] ) {
			return true;
		}
		return self::is_moved( $state ) && ! self::runs_from_old_folder();
	}

	/**
	 * admin_init on the result page (page=cmp-folder-move&cmp_moved=<token>, see
	 * result_applies()): purge the page caches once more after a move (done, or
	 * the forward fallback). Some cache plugins
	 * send their purge in response headers from their own early shutdown hook, which
	 * the purge at the end of the move request may miss; here they run in a normal
	 * request. Once per move (flag `purged` in the state).
	 *
	 * @return string[] What was purged (for tests).
	 */
	public static function purge_after_move() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- no state change beyond a cache purge, gated by capability and the attempt token.
		if ( ! isset( $_GET['page'], $_GET['cmp_moved'] ) || self::PAGE !== $_GET['page'] || ! self::user_may_move() ) {
			return array();
		}
		$token = sanitize_key( wp_unslash( $_GET['cmp_moved'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$state = get_site_option( self::STATE, array() );
		if ( ! self::is_moved( $state ) || ! self::result_applies( $state, $token ) || ! empty( $state['purged'] ) ) {
			return array();
		}
		$state['purged'] = true;
		update_site_option( self::STATE, $state );
		return self::purge_page_caches();
	}

	/**
	 * After the move: the plugins list cache, and the update_plugins entries
	 * filed under the old folder (both entry files), so WordPress checks
	 * biscuitwp/biscuitwp.php afresh. Other plugins' entries stay.
	 */
	private static function refresh_caches() {
		if ( ! function_exists( 'wp_clean_plugins_cache' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( false );
		}
		$t = get_site_transient( 'update_plugins' );
		if ( ! is_object( $t ) ) {
			return;
		}
		$changed = false;
		foreach ( array( 'response', 'no_update', 'checked' ) as $list ) {
			if ( ! isset( $t->$list ) || ! is_array( $t->$list ) ) {
				continue;
			}
			foreach ( self::old_paths() as $key ) {
				if ( array_key_exists( $key, $t->$list ) ) {
					$l = $t->$list;
					unset( $l[ $key ] );
					$t->$list = $l;
					$changed  = true;
				}
			}
		}
		if ( $changed ) {
			set_site_transient( 'update_plugins', $t );
		}
	}

	/**
	 * Purge the page caches of common caching plugins, each only when present:
	 * pages cached before the move load the banner assets from the old folder.
	 *
	 * @return string[] What was called (for tests).
	 */
	public static function purge_page_caches() {
		$done  = array();
		$calls = array(
			'rocket_clean_domain'      => array( 'function', 'rocket_clean_domain' ),          // WP Rocket.
			'w3tc_flush_all'           => array( 'function', 'w3tc_flush_all' ),               // W3 Total Cache.
			'litespeed_purge_all'      => array( 'action', 'litespeed_purge_all' ),            // LiteSpeed Cache.
			'wp_cache_clear_cache'     => array( 'function', 'wp_cache_clear_cache' ),         // WP Super Cache.
			'wpfc_clear_all_cache'     => array( 'action', 'wpfc_clear_all_cache' ),           // WP Fastest Cache.
			'autoptimizeCache'         => array( 'static', array( 'autoptimizeCache', 'clearall' ) ), // Autoptimize.
			'sg_cachepress_purge_cache' => array( 'function', 'sg_cachepress_purge_cache' ),   // SiteGround Optimizer.
			'breeze_clear_all_cache'   => array( 'action', 'breeze_clear_all_cache' ),         // Breeze.
			'wpo_cache_flush'          => array( 'function', 'wpo_cache_flush' ),              // WP-Optimize.
			'cache_enabler_clear_complete_cache' => array( 'action', 'cache_enabler_clear_complete_cache' ), // Cache Enabler.
			'rt_nginx_helper_purge_all' => array( 'action', 'rt_nginx_helper_purge_all' ),     // Nginx Helper.
		);
		foreach ( $calls as $name => $c ) {
			try {
				if ( 'function' === $c[0] && function_exists( $c[1] ) ) {
					call_user_func( $c[1] );
					$done[] = $name;
				} elseif ( 'action' === $c[0] && function_exists( 'has_action' ) && has_action( $c[1] ) ) {
					do_action( $c[1] );
					$done[] = $name;
				} elseif ( 'static' === $c[0] && class_exists( $c[1][0], false ) && method_exists( $c[1][0], $c[1][1] ) ) {
					call_user_func( $c[1] );
					$done[] = $name;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		return $done;
	}
}
