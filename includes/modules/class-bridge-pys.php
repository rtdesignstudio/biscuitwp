<?php
if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) {
	exit; // The CLI exception keeps the class loadable by tests/run.php; the pure methods use no WP.
}

/**
 * Bridge to PixelYourSite (free, read at 11.4.7). Its pixels are injected by a
 * local script (handle `pys`) that the blocker cannot recognise by host, and
 * its Conversion API events leave from the server.
 *
 * In the page (never depends on the visitor, so it is safe in a page cache):
 * the script waits for the category of Meta Pixel (as set on the Servicii screen),
 * and a small inline gate before it makes the flags PixelYourSite reads
 * (pysOptions.gdpr) follow the consent in the browser; Google Analytics and GTM
 * also need their own category. On the server: its own filters, after the
 * visitor's cookie. Every answer can only refuse more than PixelYourSite or the
 * site already does. A service the admin set to "nu bloca" (or made necessary)
 * stays exempt here too.
 *
 * Limits of the gate: pysOptions printed before it (the usual order) is a global
 * `var`, which cannot be redefined, so only that first value is held; a second
 * `var pysOptions = …` after the gate (an optimizer that prints it twice) would
 * replace it raw. `let` / `const pysOptions` is never held; PixelYourSite prints
 * `var` only.
 *
 * Not used on purpose: pys_disable_*_by_gdpr. PixelYourSite prints their
 * values into the page, where a page cache would keep them.
 */
class CMP_Bridge_PYS {

	/** The script handle of PixelYourSite's loader (dist/scripts/public(.min).js). */
	const HANDLE = 'pys';

	/** The id of the inline gate. */
	const GATE_ID = 'cmp-pys-gate';

	/** Library service => the PixelYourSite pixel it stands for. */
	const EXEMPT_MAP = array(
		'meta-pixel'         => 'facebook',
		'google-analytics'   => 'ga',
		'google-tag-manager' => 'gtm',
	);

	/** Pixels with a flag of their own, "<pixel>_disabled_by_api", in pysOptions.gdpr (GA and GTM share one). */
	const FLAGS = array( 'facebook', 'google_ads', 'pinterest', 'bing', 'reddit', 'openai' );

	/** On plugins_loaded, when every plugin file is in. */
	public static function register() {
		if ( ! self::in_effect() ) {
			return;
		}
		add_filter( 'script_loader_tag', array( __CLASS__, 'filter_tag' ), 99, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'unhook_output' ), 2 ); // PixelYourSite sets its output up at 1

		add_filter( 'pys_disable_server_event_filter', array( __CLASS__, 'filter_server_event' ) );
		add_filter( 'pys_check_consent_by_gdpr', array( __CLASS__, 'filter_consent' ), 10, 2 );
		add_filter( 'pys_pixel_restricted_mode', array( __CLASS__, 'filter_consent' ), 10, 2 );
		add_filter( 'pys_disable_advanced_form_data_cookie', array( __CLASS__, 'filter_form_data_cookie' ) );
		add_filter( 'pys_skip_session', array( __CLASS__, 'filter_server_event' ) );

		add_action( 'admin_post_wp_async_pys_send_server_event', array( __CLASS__, 'fix_forwarded_cookie' ), 1 );
		add_action( 'admin_post_nopriv_wp_async_pys_send_server_event', array( __CLASS__, 'fix_forwarded_cookie' ), 1 );
	}

	/** Pure: is the bridge needed? */
	public static function needed( bool $active, bool $blocking ): bool {
		return $active && $blocking;
	}

	/** Is the bridge in effect: PixelYourSite loaded, and trackers blocked (always in Light). */
	public static function in_effect(): bool {
		if ( ! defined( 'PYS_FREE_VERSION' ) || ! function_exists( 'get_option' ) ) {
			return false;
		}
		$s = CMP_Consent::get_settings();
		return self::needed( true, CMP_Blocker::blocks_trackers( is_array( $s ) ? $s : array() ) );
	}

	/** Pure: the category a PixelYourSite pixel waits for when its service is not in the list. */
	public static function category( string $pixel ): string {
		return in_array( $pixel, array( 'ga', 'gtm' ), true ) ? 'statistics' : 'marketing';
	}

	/**
	 * Pure: the pixels that wait for nothing: their service is set to "nu bloca" by
	 * the admin (never the Advanced mode's own exemption), or sits in "necessary"
	 * or in a required category ($required slugs).
	 */
	public static function exempt( array $services, array $required = array() ): array {
		$out = array();
		foreach ( self::mapped( $services ) as $pixel => $s ) {
			if ( CMP_Services::consent_exempt( $s ) || self::always_on( isset( $s['category'] ) ? $s['category'] : '', $required ) ) {
				$out[] = $pixel;
			}
		}
		return $out;
	}

	/**
	 * Pure: the categories in effect: 'hold' (the script and every pixel without a
	 * library service) and one per mapped pixel. 'hold' is Meta Pixel's category;
	 * when that one is always on, Meta alone is exempt and the rest keep waiting
	 * for the default.
	 */
	public static function categories( array $services, array $required = array() ): array {
		$out = array( 'hold' => self::category( 'facebook' ) );
		foreach ( self::EXEMPT_MAP as $pixel ) {
			$out[ $pixel ] = self::category( $pixel );
		}
		foreach ( self::mapped( $services ) as $pixel => $s ) {
			if ( isset( $s['category'] ) && is_string( $s['category'] ) && '' !== $s['category'] ) {
				$out[ $pixel ] = $s['category'];
			}
		}
		if ( ! self::always_on( $out['facebook'], $required ) ) {
			$out['hold'] = $out['facebook'];
		}
		return $out;
	}

	/** Pure: pixel => its library service, for the services present (first wins). */
	private static function mapped( array $services ): array {
		$out = array();
		foreach ( $services as $s ) {
			$id = is_array( $s ) && isset( $s['id'] ) ? (string) $s['id'] : '';
			if ( isset( self::EXEMPT_MAP[ $id ] ) && ! isset( $out[ self::EXEMPT_MAP[ $id ] ] ) ) {
				$out[ self::EXEMPT_MAP[ $id ] ] = $s;
			}
		}
		return $out;
	}

	/** Pure: a category that never waits. */
	private static function always_on( $category, array $required ): bool {
		return 'necessary' === $category || in_array( $category, $required, true );
	}

	/**
	 * array( exempt pixels, categories ) for this site. Settings only, never the
	 * visitor. In Light the required categories do not count: the blocker spares
	 * only "necessary" and "nu bloca" there.
	 */
	private static function rules(): array {
		$s        = CMP_Consent::get_settings();
		$light    = is_array( $s ) && isset( $s['mode'] ) && 'light' === $s['mode'];
		$cats     = CMP_Consent::get_categories();
		$required = $light || ! is_array( $cats ) ? array() : CMP_Services::required_slugs( $cats );
		$services = CMP_Services::active();
		return array( self::exempt( $services, $required ), self::categories( $services, $required ) );
	}

	/**
	 * Pure: the printed tag(s) of a handle. For `pys`, its src tag gets
	 * data-cmp-category and the gate goes right before it, in the same category,
	 * so both are released together and in order. Inline parts of the handle are
	 * not touched. With anything exempt the src tag stays live and the gate runs
	 * in place (data-cmp-keep): it then holds back the other pixels by itself.
	 * When no src tag is recognised, every script of the handle is held.
	 */
	public static function tag( string $tag, string $handle, array $exempt, array $categories = array() ): string {
		if ( self::HANDLE !== $handle || false !== strpos( $tag, 'id="' . self::GATE_ID . '"' ) ) {
			return $tag;
		}
		$open = self::open_tags( $tag );
		if ( ! $open ) {
			return $tag;
		}
		$hit = null;
		foreach ( $open as $t ) {
			if ( 1 === preg_match( '/(?:^|[\s"\'])src\s*=/i', $t['attrs'] ) ) {
				$own = 1 === preg_match( '/(?:^|[\s"\'])id\s*=\s*["\']?' . self::HANDLE . '-js["\'\s]/i', $t['attrs'] . ' ' );
				if ( null === $hit || $own ) {
					$hit = $t;
				}
				if ( $own ) {
					break;
				}
			}
		}
		$hold  = isset( $categories['hold'] ) ? (string) $categories['hold'] : self::category( 'facebook' );
		$at    = null === $hit ? $open[0]['at'] : $hit['at'];
		$attrs = null === $hit ? '' : $hit['attrs'];
		$nonce = preg_match( '/(?:^|\s)nonce\s*=\s*["\']([A-Za-z0-9+\/=_-]+)["\']/i', null === $hit ? $open[0]['attrs'] : $attrs, $n ) ? ' nonce="' . $n[1] . '"' : '';

		if ( $exempt || 1 === preg_match( '/(?:^|\s)data-cmp-keep(?=[\s=\/]|$)/i', $attrs ) ) {
			$mark = ' data-cmp-keep';
		} else {
			// The gate follows a category already on the src tag, so both are released together.
			$mark = self::category_attr( self::own_category( $attrs, $hold ) );
			foreach ( array_reverse( null === $hit ? $open : array( $hit ) ) as $t ) {
				$new = self::held_attrs( $t['attrs'], $hold );
				$tag = substr_replace( $tag, $new, $t['at'] + 7, strlen( $t['attrs'] ) ); // 7: "<script"
			}
		}
		$gate = '<script id="' . self::GATE_ID . '"' . $mark . $nonce . '>' . self::gate_js( $exempt, $categories ) . "</script>\n";
		return substr_replace( $tag, $gate, $at, 0 );
	}

	/** Matches data-cmp-category in an attribute string (as the blocker reads it). */
	const CATEGORY_RE = '/(?:^|(?<=[\s\/"\']))data-cmp-category\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>\/]+))/i';

	/** Pure: the category on a tag, or $hold when there is none or a blank one. */
	private static function own_category( string $attrs, string $hold ): string {
		$c = preg_match( self::CATEGORY_RE, $attrs, $m ) ? trim( html_entity_decode( implode( '', array_slice( $m, 1 ) ), ENT_QUOTES ) ) : '';
		return '' === $c ? $hold : $c;
	}

	/** Pure: a data-cmp-category attribute. */
	private static function category_attr( string $category ): string {
		return ' data-cmp-category="' . htmlspecialchars( $category, ENT_QUOTES, 'UTF-8' ) . '"';
	}

	/** Pure: attributes that hold a tag; a category already there is kept, a blank one is replaced. */
	private static function held_attrs( string $attrs, string $hold ): string {
		if ( preg_match( self::CATEGORY_RE, $attrs, $m ) && '' !== trim( implode( '', array_slice( $m, 1 ) ) ) ) {
			return $attrs;
		}
		return self::category_attr( $hold ) . preg_replace( self::CATEGORY_RE, '', $attrs );
	}

	/**
	 * Pure: the opening <script> tags of a piece of markup, in order, each
	 * array( at, attrs ). Text inside a script is skipped, and with $comments also
	 * what sits in an HTML comment. Written without a pattern over the bodies,
	 * which can be large.
	 */
	private static function open_tags( string $html, bool $comments = false ): array {
		$out = array();
		$pos = 0;
		while ( false !== ( $at = stripos( $html, '<script', $pos ) ) ) {
			if ( $comments ) {
				$c = strpos( $html, '<!--', $pos );
				if ( false !== $c && $c < $at ) {
					$end = strpos( $html, '-->', $c + 4 );
					if ( false === $end ) {
						break;
					}
					$pos = $end + 3;
					continue;
				}
			}
			$pos = $at + 7;
			if ( ! preg_match( '/\G<script(?=[\s>\/])((?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+)>/i', $html, $m, 0, $at )
				&& ! preg_match( '/\G<script(?=[\s>\/])([^>]*+)>/i', $html, $m, 0, $at ) ) {
				continue;
			}
			$out[] = array( 'at' => $at, 'attrs' => $m[1] );
			$end   = stripos( $html, '</script', $at + strlen( $m[0] ) );
			if ( false === $end ) {
				break;
			}
			$pos = $end;
		}
		return $out;
	}

	/** Pure: an attribute of a tag, or null. Names inside another attribute's value do not count. */
	private static function attr( string $attrs, string $name ) {
		preg_match_all( '/([^\s=>"\'\/]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/', $attrs, $all, PREG_SET_ORDER );
		foreach ( (array) $all as $m ) {
			if ( 0 === strcasecmp( $m[1], $name ) ) {
				return implode( '', array_slice( $m, 2 ) );
			}
		}
		return null;
	}

	/**
	 * May the printed pysOptions of this page be left alone by the blocker? They
	 * are data, but with a GTM container in them (a GTM- id, "dataLayer",
	 * googletagmanager.com) they look like a GTM snippet, and public.js cannot run
	 * without them. Only with the bridge in effect and on a gated page: elsewhere
	 * (public.js in an optimizer's bundle) holding them is what keeps it still.
	 */
	public static function keeps_options( string $html, callable $state ): bool {
		return false !== strpos( $html, self::HANDLE . '-js-extra' ) && self::in_effect() && self::page_gated( $html, $state );
	}

	/**
	 * Pure: does the gate hold PixelYourSite on this page? The gate, then the src
	 * script with id pys-js, held for the same category (or the gate live, which
	 * holds the pixels by itself), and no other copy of public.js that runs.
	 * $state( attrs ): array( 'held', category ), array( 'live', '' ) = live on
	 * purpose (data-cmp-keep, "necessary"), or null.
	 */
	public static function page_gated( string $html, callable $state ): bool {
		$gate  = null;
		$found = false;
		foreach ( self::open_tags( $html, true ) as $t ) {
			$id = self::attr( $t['attrs'], 'id' );
			if ( self::GATE_ID === $id && null === $gate ) {
				$gate = call_user_func( $state, $t['attrs'] );
				if ( null === $gate ) {
					return false;
				}
			} elseif ( self::HANDLE . '-js' === $id ) {
				if ( null === $gate || ( ! self::attr( $t['attrs'], 'src' ) && ! self::attr( $t['attrs'], 'data-cmp-src' ) ) ) {
					return false;
				}
				$found = true;
			}
		}
		if ( ! $found ) {
			return false;
		}
		if ( 'live' === $gate[0] ) {
			return true;
		}
		// Comments included: what an old browser or an optimizer may bring back counts as running.
		// The tags of both scans: a comment with an unclosed "<script>" swallows the next real tag in the
		// scan that reads comments, so a live copy right after it is found by the other one.
		$tags = array();
		foreach ( array_merge( self::open_tags( $html ), self::open_tags( $html, true ) ) as $t ) {
			$tags[ $t['at'] ] = $t;
		}
		foreach ( $tags as $t ) {
			// The path anywhere in the attributes: a second copy an optimizer moved to data-src, data-litespeed-src or data-rocket-src is a copy that runs.
			$own = self::HANDLE . '-js' === self::attr( $t['attrs'], 'id' )
				|| 1 === preg_match( '#/pixelyoursite/dist/scripts/public(?:\.min)?\.js#i', $t['attrs'] );
			if ( $own && call_user_func( $state, $t['attrs'] ) !== $gate ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Pure: the script with id exactly pys-js-extra, no src, whose body is nothing
	 * but `var pysOptions = {…};` (JSON, on its own line, as wp_localize_script
	 * prints it; WordPress adds a CDATA wrapper or a sourceURL comment). Any other
	 * code under that id stays subject to the blocker.
	 */
	public static function is_options( string $attrs, string $body ): bool {
		if ( self::HANDLE . '-js-extra' !== self::attr( $attrs, 'id' ) || null !== self::attr( $attrs, 'src' ) ) {
			return false;
		}
		$found = false;
		foreach ( preg_split( '/\r\n|\r|\n/', trim( $body ) ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '/* <![CDATA[ */' === $line || '/* ]]> */' === $line || 1 === preg_match( '/^\/\/# sourceURL=[A-Za-z0-9._%-]+$/', $line ) ) {
				continue;
			}
			if ( 1 !== preg_match( '/^var pysOptions\s*=\s*(?=[{\[])/', $line, $m ) || ';' !== substr( $line, -1 ) ) {
				return false;
			}
			json_decode( substr( $line, strlen( $m[0] ), -1 ) );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return false;
			}
			$found = true;
		}
		return $found;
	}

	/**
	 * Pure: the gate. The flags become accessors, not values: public.js reads
	 * them when it loads the pixels, copies a REST answer over them when
	 * "fetch_user_data_via_rest" is on (Object.assign), and the live gate runs
	 * before banner.js. A value already "disabled" (the site's own filter) stays
	 * so. pysOptions that come after the gate are held when they are assigned;
	 * objects that cannot be redefined are replaced by a held copy; a second run
	 * changes nothing. ASCII and without "<", so it survives inline printing and
	 * atob().
	 */
	public static function gate_js( array $exempt, array $categories = array() ): string {
		$safe  = JSON_HEX_TAG | JSON_HEX_AMP;
		$keep  = json_encode( array_values( array_intersect( array_values( self::EXEMPT_MAP ), $exempt ) ) );
		$cats  = json_encode( array_merge( self::categories( array() ), $categories ), $safe );
		$flags = json_encode( self::FLAGS );
		return '(function(w){'
			. 'var x=' . $keep . ',c=' . $cats . ',cur;'
			// The visitor's choice now; Light cannot collect consent, whatever cookie is left from Pro.
			. 'function ok(s){try{return !(w.CMP_DATA&&w.CMP_DATA.mode==="light")&&!!w.CMP&&w.CMP.isAllowed(s)===true;}catch(e){return false;}}'
			. 'function ex(p){return x.indexOf(p)!==-1;}'
			. 'function may(p){return ex(p)||(ok(c.hold)&&ok(c[p]||c.hold));}'
			. 'function hold(t,n,get){var v=t[n];Object.defineProperty(t,n,{configurable:true,enumerable:true,get:function(){return get(v);},set:function(z){v=z;}});}'
			// GA and GTM share one flag: free only when every one of the two that loads here may run.
			. 'function loads(o,p){var t=o[p];return !!t&&(p!=="gtm"||!!t.gtm_just_data_layer||!!(t.trackingIds&&t.trackingIds.length));}'
			. 'function google(o){var b=["ga","gtm"],l=b.filter(function(p){return loads(o,p);});return (l.length?l:b).every(may);}'
			// The signals each category grants on this site, as banner.js sends them.
			// One that no category lists follows the category of GA, or of the script.
			. 'function sig(s){try{var l=w.CMP_DATA.categories.filter(function(k){return (k.gtag_signals||[]).indexOf(s)!==-1;});'
			. 'return l.length?l.some(function(k){return ok(k.slug);}):ok(s==="analytics_storage"?c.ga:c.hold);}catch(e){return false;}}'
			// fn( o[k] ); when that object refuses, fn on a copy put in its place.
			. 'function part(o,k,fn){try{fn(o[k]);}catch(e){var n=Object.assign({},o[k]);fn(n);o[k]=n;if(o[k]!==n){throw e;}}}'
			. 'function set(o){'
			. 'part(o,"gdpr",function(g){'
			. 'g.ajax_enabled=false;'
			. $flags . '.forEach(function(p){hold(g,p+"_disabled_by_api",function(v){return !!v||!may(p);});});'
			. 'hold(g,"analytics_disabled_by_api",function(v){return !!v||!google(o);});'
			// What it tells Google Consent Mode ("granted" in the page) follows the visitor too.
			. '["analytics_storage","ad_storage","ad_user_data","ad_personalization"].forEach(function(s){'
			. 'if(g[s]&&typeof g[s]==="object"){part(g,s,function(t){hold(t,"value",function(v){return sig(s)?v:"denied";});});}});'
			. '});'
			// Its own cookies (traffic source, landing page, external id), when the script is live for GA / GTM only.
			. 'if(!ex("facebook")&&o.cookie&&typeof o.cookie==="object"){part(o,"cookie",function(k){hold(k,"disabled_all_cookie",function(v){return !!v||!ok(c.hold);});});}'
			. 'Object.defineProperty(o,"__cmp",{value:1});'
			. '}'
			. 'function arm(o){if(!o||typeof o!=="object"||o.__cmp){return o;}try{set(o);}catch(e){o=Object.assign({},o);set(o);}return o;}'
			. 'cur=w.pysOptions;'
			. 'if(cur&&typeof cur==="object"){cur=arm(cur);if(w.pysOptions!==cur){w.pysOptions=cur;}}'
			// Not there yet: held when a later script assigns it (a global var cannot be redefined: then it is already held).
			. 'try{Object.defineProperty(w,"pysOptions",{configurable:true,enumerable:true,get:function(){return cur;},set:function(v){cur=arm(v);}});}catch(e){}'
			. '})(window);';
	}

	/**
	 * Pure: may a pixel send from the server? An exempt one, or one whose own
	 * category and the script's are both allowed. $is_allowed: callable( category ): bool.
	 */
	public static function server_allowed( string $pixel, array $exempt, callable $is_allowed, array $categories = array() ): bool {
		if ( in_array( $pixel, $exempt, true ) ) {
			return true;
		}
		$c   = array_merge( self::categories( array() ), $categories );
		$own = isset( $c[ $pixel ] ) ? $c[ $pixel ] : $c['hold'];
		return (bool) call_user_func( $is_allowed, $c['hold'] ) && (bool) call_user_func( $is_allowed, $own );
	}

	/**
	 * The visitor's answer on the server. No cookie (cron included) is a no, and
	 * so is Light, where a cookie left from Pro does not count.
	 */
	public static function visitor_allows( $category ): bool {
		$s = CMP_Consent::get_settings();
		if ( is_array( $s ) && isset( $s['mode'] ) && 'light' === $s['mode'] ) {
			return false;
		}
		return cmp_is_allowed( (string) $category );
	}

	/** server_allowed() for this site and this request. */
	private static function allowed_now( $pixel ): bool {
		list( $exempt, $categories ) = self::rules();
		return self::server_allowed( (string) $pixel, $exempt, array( __CLASS__, 'visitor_allows' ), $categories );
	}

	/** script_loader_tag: see tag(). */
	public static function filter_tag( $tag, $handle = '' ) {
		if ( self::HANDLE !== $handle || ! is_string( $tag ) ) {
			return $tag;
		}
		list( $exempt, $categories ) = self::rules();
		return self::tag( $tag, $handle, $exempt, $categories );
	}

	/**
	 * pys_disable_server_event_filter (Meta Conversion API) and pys_skip_session
	 * (the PHP session in which it keeps the landing page and campaign): true
	 * without consent.
	 */
	public static function filter_server_event( $disabled ) {
		return (bool) $disabled || ! self::allowed_now( 'facebook' );
	}

	/**
	 * pys_check_consent_by_gdpr (server events, _fbp / _fbc / __oppref written by
	 * PHP) and pys_pixel_restricted_mode (PixelYourSite sends with consent OR in
	 * restricted mode): never a yes without the visitor's.
	 */
	public static function filter_consent( $yes, $pixel = '' ) {
		return (bool) $yes && self::allowed_now( $pixel );
	}

	/**
	 * pys_disable_advanced_form_data_cookie (e-mail, phone and name, written by PHP
	 * at login and checkout). The value is also printed into the page: there the
	 * site's own answer is kept, so a page cache holds nobody's choice.
	 */
	public static function filter_form_data_cookie( $disabled ) {
		if ( $disabled ) {
			return true;
		}
		if ( function_exists( '\PixelYourSite\pys_html_render_context' ) && \PixelYourSite\pys_html_render_context() ) {
			return false;
		}
		return ! self::allowed_now( 'facebook' );
	}

	/**
	 * Output that must not be in a page before consent, for anyone: the noscript
	 * pixels (a visitor without JavaScript cannot consent, and the blocker knows
	 * only library hosts; an exempt pixel loses its no-JS image too) and, unless
	 * Meta is exempt, the early request for the external id (its script makes the
	 * request itself once it runs).
	 */
	public static function unhook_output() {
		$manager = self::manager();
		if ( null === $manager ) {
			return;
		}
		remove_action( 'wp_footer', array( $manager, 'outputNoScriptData' ), 10 );
		if ( ! in_array( 'facebook', self::rules()[0], true ) ) {
			remove_action( 'wp_head', array( $manager, 'outputPbidPrefetch' ), 1 );
		}
	}

	/** PixelYourSite's main object, or null. */
	private static function core() {
		$core = function_exists( '\PixelYourSite\PYS' ) ? \PixelYourSite\PYS() : null;
		return is_object( $core ) ? $core : null;
	}

	/** Its events manager on this page, or null. */
	private static function manager() {
		$core    = self::core();
		$manager = null !== $core && method_exists( $core, 'getEventsManager' ) ? $core->getEventsManager() : null;
		return is_object( $manager ) ? $manager : null;
	}

	/**
	 * PixelYourSite sends its page events from a loopback request, with the
	 * visitor's cookies copied from $_COOKIE, where WordPress has slashed them.
	 * The JSON consent cookie would arrive unreadable, that is, as a no.
	 */
	public static function fix_forwarded_cookie() {
		$name = CMP_Consent::cookie_name();
		if ( isset( $_COOKIE[ $name ] ) && is_string( $_COOKIE[ $name ] ) ) {
			$_COOKIE[ $name ] = self::forwarded_cookie( $_COOKIE[ $name ] );
		}
	}

	/** Pure: a slashed consent cookie with one extra level of slashes taken off; anything else unchanged. */
	public static function forwarded_cookie( string $raw ): string {
		$once = stripslashes( $raw );
		if ( is_array( json_decode( $once, true ) ) || ! is_array( json_decode( stripslashes( $once ), true ) ) ) {
			return $raw;
		}
		return $once;
	}
}
