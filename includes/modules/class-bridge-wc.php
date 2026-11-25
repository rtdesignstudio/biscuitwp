<?php
if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) {
	exit; // The CLI exception keeps the class loadable by tests/run.php; the pure methods use no WP.
}

/**
 * Bridge to WooCommerce order attribution (read at 11.3.1): its sbjs_* cookies
 * and the source it stores with an order. The scripts are local, so the blocker
 * does not know them, and they are never blocked: WooCommerce has its own switch.
 *
 * In the page (the same for every visitor, so it is safe in a page cache):
 * allowTracking is always false, and two inline parts of the wc-order-attribution
 * handle decide in the browser. before_js() makes WooCommerce's own start follow
 * the visitor's choice: started with false it deletes its cookies, so a visitor
 * who agreed would lose the source of the visit on every page. js() follows the
 * choice from then on, through wc_order_attribution.setOrderTracking().
 *
 * The category is "marketing", the one WooCommerce uses. No such category, Light,
 * no banner script, no decision: off. With the WP Consent API plugin WooCommerce
 * reads the consent by itself and nothing here runs. Orders, cart, checkout and
 * every WooCommerce script are left as they are.
 *
 * Limits, all on the closed side (no attribution): a WooCommerce release without
 * setOrderTracking() still shows as bridged; a "no" from the allow filter in this
 * request, whoever asked, counts as the site's own; sourcebuster loaded after
 * order-attribution.js by an optimizer is not started on that page; another
 * plugin that calls setOrderTracking() itself meets WooCommerce's own shadowing of
 * the classic checkout inputs (js() removes it only around its own call), so a
 * withdrawal made that way leaves the stamped source in the form.
 */
class CMP_Bridge_WC {

	/** The handle of order-attribution(.min).js. */
	const HANDLE = 'wc-order-attribution';

	/** The cookies WooCommerce itself removes (order-attribution.js, removeTrackingCookies()). */
	const COOKIES = array( 'sbjs_current', 'sbjs_current_add', 'sbjs_first', 'sbjs_first_add', 'sbjs_session', 'sbjs_udata', 'sbjs_migrations', 'sbjs_promo' );

	/** The site's own answer, when it is a no: nothing is ever switched on. */
	private static $site_refuses = false;

	/**
	 * On plugins_loaded. Decided at init, right after WooCommerce looks for the WP
	 * Consent API (init 20), so both give the same answer, network activation included.
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'boot' ), 21 );
	}

	/** init: hook in, when needed. */
	public static function boot() {
		if ( ! self::in_effect() ) {
			return;
		}
		// Last, so a filter of the site cannot put a yes into the HTML.
		add_filter( 'wc_order_attribution_allow_tracking', array( __CLASS__, 'filter_allow' ), PHP_INT_MAX );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'attach' ), PHP_INT_MAX ); // WooCommerce enqueues at 10
	}

	/** Pure: is the bridge needed? With the WP Consent API, WooCommerce does it by itself. */
	public static function needed( bool $wc, bool $consent_api, bool $blocking ): bool {
		return $wc && ! $consent_api && $blocking;
	}

	/**
	 * Is the bridge in effect? The class is what WooCommerce looks for (not
	 * wp_set_consent(), which another plugin may define).
	 */
	public static function in_effect(): bool {
		if ( ! class_exists( 'WooCommerce', false ) || ! function_exists( 'get_option' ) ) {
			return false;
		}
		$s = CMP_Consent::get_settings();
		return self::needed( true, class_exists( 'WP_CONSENT_API' ), CMP_Blocker::blocks_trackers( is_array( $s ) ? $s : array() ) );
	}

	/** In effect, and order attribution is on in WooCommerce (for the Servicii screen). */
	public static function shown(): bool {
		$util = '\Automattic\WooCommerce\Utilities\FeaturesUtil';
		return self::in_effect() && ( ! class_exists( $util ) || $util::feature_is_enabled( 'order_attribution' ) );
	}

	/** wc_order_attribution_allow_tracking: the page says no, for everyone. WooCommerce also takes 'yes' / 'no'. */
	public static function filter_allow( $allow ) {
		if ( true !== $allow && ! ( function_exists( 'wc_string_to_bool' ) && wc_string_to_bool( $allow ) ) ) {
			self::$site_refuses = true;
		}
		return false;
	}

	/** wp_enqueue_scripts: the inline parts (nothing is printed when the handle is not there). */
	public static function attach() {
		if ( self::$site_refuses ) {
			return;
		}
		wp_add_inline_script( self::HANDLE, self::before_js(), 'before' );
		wp_add_inline_script( self::HANDLE, self::js(), 'after' );
	}

	/** The visitor's answer now. Light cannot collect consent, whatever cookie is left from Pro; a deleted category counts for nobody. */
	private static function may_js(): string {
		return 'function may(){try{return w.CMP_DATA.mode!=="light"'
			. '&&w.CMP_DATA.categories.some(function(k){return k.slug==="marketing";})'
			. '&&w.CMP.isAllowed("marketing")===true;}catch(e){return false;}}';
	}

	/**
	 * Pure: before order-attribution.js. Until WooCommerce sets allowTracking, it
	 * reads as the visitor's answer at that moment (the file may be deferred, after
	 * banner.js). When banner.js comes later than that, WooCommerce starts with false
	 * and js() switches it on: allowed, but the source kept from earlier pages is gone.
	 */
	public static function before_js(): string {
		return '(function(w){var o=w.wc_order_attribution,p=o&&o.params,v,set=false;'
			. self::may_js()
			. 'if(!p||typeof o.setOrderTracking==="function"){return;}'
			. 'try{Object.defineProperty(p,"allowTracking",{configurable:true,enumerable:true,get:function(){return set?v:may();},set:function(z){set=true;v=z;}});}catch(e){}'
			. '})(window);';
	}

	/**
	 * Pure: after order-attribution.js. Calls setOrderTracking() when the state
	 * differs from the answer, and always at a decision that says no. The file and
	 * banner.js may each come later (deferred, delayed by an optimizer): looked for
	 * again when the page and its scripts load. A choice made in another tab, or
	 * before a back / forward restore, counts when the page is seen again. What other
	 * code switched off is not switched back on without a new decision.
	 *
	 * The classic forms: WooCommerce sets `values` on the checkout elements before it
	 * defines them, and its repair looks for another name, so that own property hides
	 * the setter and the hidden inputs never change again. It is removed right before
	 * the call: WooCommerce's own assignment then reaches its own setter.
	 *
	 * The cookies: WooCommerce expires them on .hostname, but they are written without
	 * a leading www., where they would stay. Once the answer is known to be no, what is
	 * left of them is expired there too.
	 */
	public static function js(): string {
		return '(function(w,d){var last=null;'
			. self::may_js()
			. 'function sync(e){var o=w.wc_order_attribution,want=may(),no=!!e&&e.type==="cmp:consent";'
			. 'if(no){last=null;no=!want;}'
			. 'if(o&&typeof o.setOrderTracking==="function"){'
			. 'if(w.CMP&&typeof w.CMP.isAllowed==="function"){d.removeEventListener("load",sync,true);}'
			. 'if(no||(!!(o.params&&o.params.allowTracking)!==want&&!(want&&last===true))){'
			. 'try{d.querySelectorAll("wc-order-attribution-inputs").forEach(function(x){delete x.values;});}catch(a){}'
			. 'try{o.setOrderTracking(want);}catch(b){}}'
			. 'last=want;}'
			. 'if(w.CMP&&typeof w.CMP.isAllowed==="function"&&!want){clean();}}'
			. 'function clean(){try{' . json_encode( self::COOKIES ) . '.forEach(function(n){'
			. 'if(("; "+d.cookie).indexOf("; "+n+"=")!==-1){d.cookie=n+"=; path=/; max-age=-999; domain=."+w.location.hostname.replace(/^www\./,"");}});}catch(c){}}'
			. 'd.addEventListener("load",sync,true);' // capture: every script that loads
			. 'd.addEventListener("cmp:consent",sync);'
			. 'd.addEventListener("DOMContentLoaded",sync);'
			. 'd.addEventListener("visibilitychange",sync);'
			. 'w.addEventListener("load",sync);'
			. 'w.addEventListener("pageshow",sync);'
			. 'sync();'
			. '})(window,document);';
	}
}
