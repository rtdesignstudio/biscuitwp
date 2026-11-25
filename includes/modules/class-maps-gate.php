<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone-loadable for tests; the WordPress side is current() alone.
}
require_once dirname( __DIR__ ) . '/class-services.php';

/**
 * The maps gate: what stands in the page in place of the tag that loads the Google
 * Maps JavaScript API of a theme (service CMP_Services::GATE_ID) while the setting
 * maps_gate is on. The decision is taken in the browser (assets/js/maps-gate.js,
 * printed inline), so the page can sit in a cache: with consent the original tag
 * runs at its place, without it google.maps is a stand-in and the map gets a box.
 *
 * Cached HTML keeps the config of the day it was made, next to the CMP_DATA of the
 * same page: the gate then answers what the banner of that page answers, and a
 * decision for another policy version, or without one of the services listed
 * there, is a no. It never reads anything the banner of the page does not.
 *
 * Limits: a tag added by JavaScript, hidden in a bundle, held by another consent
 * tool (text/plain), on maps.google.com or maps.googleapis.cn, or an inline loader
 * other than Google's own snippet stays as it is; the onload of the tag is not run
 * without consent.
 */
class CMP_Maps_Gate {

	/** The pattern of the library entry (a test keeps them in step). */
	const HOST = 'maps.googleapis.com/maps/api/js';

	/** Serves only Maps: a resource hint to it gives the visitor's IP to Google all the same. */
	const HINT_HOST = 'maps.gstatic.com';

	/** The free name in maps-gate.js that the config replaces. */
	const TOKEN = 'CMP_MAPS_GATE';

	const JS = 'assets/js/maps-gate.js';

	/** Both must be in an inline script for it to be Google's own loader (its host is built from pieces). */
	const MARKERS = array( 'The Google Maps JavaScript API', 'importLibrary' );

	/** Types a browser does not run (data, templates, a tag another consent tool holds). */
	const INERT = '~^(?:text/(?:plain|html|template|x-(?!javascript|ecmascript).*)|application/(?:ld\+)?json|importmap|speculationrules)$~';

	/** @var string|null */
	private static $js = null;

	/**
	 * Pure: the whole tag behind a match of the blocker: array( attributes, body ).
	 * Its pattern ends the tag at the first ">", also inside a quoted value
	 * (onload="()=>…"); what follows is then in the body.
	 */
	private static function split( string $attrs, string $body ): array {
		if ( 1 !== preg_match( '~^(?:[^"\']++|"[^"]*"|\'[^\']*\')*+$~', $attrs )
			&& 1 === preg_match( '~^((?:[^>"\']++|"[^"]*"|\'[^\']*\')*+)>(.*)$~s', $attrs . '>' . $body, $m ) ) {
			return array( $m[1], $m[2] );
		}
		return array( $attrs, $body );
	}

	/** Pure: name (lower case) => value (decoded, null = none) of an attribute string; the first of a name wins, as in a browser. */
	private static function attrs( string $s ): array {
		$out = array();
		preg_match_all( '~([^\s"\'<>/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>][^\s>]*)))?~', $s, $m, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL );
		foreach ( $m as $a ) {
			$name  = strtolower( $a[1] );
			$value = null;
			for ( $i = 2; $i <= 4; $i++ ) {
				$value = isset( $a[ $i ] ) ? $a[ $i ] : $value;
			}
			if ( ! array_key_exists( $name, $out ) ) {
				$out[ $name ] = null === $value ? null : html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}
		return $out;
	}

	/** Pure: an attribute an optimizer moves src to (data-src, data-rocket-src, data-litespeed-src, …). */
	private static function is_moved_src( string $name ): bool {
		return 'data-cmp-src' !== $name && 1 === preg_match( '/^data-(?:[a-z0-9]+-)*src$/', $name );
	}

	/** Pure: a URL as a browser reads it from an attribute (no line breaks or tabs, "\" is "/"). */
	private static function clean( string $url ): string {
		return strtr( trim( str_replace( array( "\t", "\r", "\n" ), '', $url ) ), '\\', '/' );
	}

	/**
	 * Pure: the URL a tag loads, '' for an inline script: src, or where an optimizer
	 * keeps it (no src, an empty one or a data: one).
	 */
	private static function source( array $a ): string {
		$src = isset( $a['src'] ) ? self::clean( $a['src'] ) : '';
		if ( '' !== $src && 0 !== stripos( $src, 'data:' ) ) {
			return $src;
		}
		foreach ( $a as $name => $value ) {
			if ( null !== $value && self::is_moved_src( $name ) && '' !== self::clean( $value ) ) {
				return self::clean( $value );
			}
		}
		return $src;
	}

	/** Pure: the URL of a tag of the blocker ('' = inline). */
	public static function url( string $attrs, string $body = '' ): string {
		return self::source( self::attrs( self::split( $attrs, $body )[0] ) );
	}

	/** Pure: does this tag load the Maps JavaScript API (by its URL, or Google's own inline loader)? */
	public static function is_tag( string $attrs, string $body ): bool {
		list( $attrs, $body ) = self::split( $attrs, $body );
		$a = self::attrs( $attrs );
		if ( isset( $a['type'] ) && 1 === preg_match( self::INERT, strtolower( trim( $a['type'] ) ) ) ) {
			return false;
		}
		$url = self::source( $a );
		if ( '' !== $url ) {
			return CMP_Services::host_matches( $url, self::HOST );
		}
		return false !== strpos( $body, self::MARKERS[0] ) && false !== strpos( $body, self::MARKERS[1] );
	}

	/**
	 * Pure: the theme's own code round Google's loader in the same inline script (it
	 * runs on the stand-in), '' when there is none or the loader cannot be told apart.
	 * "(0)" stands in the loader's place, an expression too, so the code stays valid.
	 */
	public static function rest( string $body ): string {
		$out = preg_replace( '~\(\s*(?:\w+|\(\s*\w+\s*\))\s*=>\s*\{(?:(?!=>\s*\{).)*?' . preg_quote( self::MARKERS[0], '~' ) . '.*?only loads once.*?\}\s*\)\s*\(\s*\{[^{}]*\}\s*\)~s', '(0)', $body, 1, $n );
		if ( ! is_string( $out ) || 1 !== $n || false !== strpos( $out, self::MARKERS[0] ) ) {
			return '';
		}
		return 1 === preg_match( '~^[\s;]*\(0\)[\s;]*$~', $out ) ? '' : $out;
	}

	/**
	 * Pure: what the gate needs to answer as CMP.isAllowed() does, and the texts of
	 * the box: n cookie name, v policy version, c category, r required, s the service
	 * ids of the category as the banner gets them (null = unknown), m mode, l language.
	 *
	 * @param array|null $svc_by_cat {slug: [ids]} (CMP_Services::current_svc_by_cat()) or null.
	 * @param string|int $version    Policy version.
	 */
	public static function config( array $settings, array $categories, $svc_by_cat, string $cookie_name, $version, string $slug = 'marketing', string $lang = 'ro' ): array {
		$light    = isset( $settings['mode'] ) && 'light' === $settings['mode'];
		$required = false;
		$exists   = false;
		foreach ( $categories as $cat ) {
			if ( is_array( $cat ) && isset( $cat['slug'] ) && (string) $cat['slug'] === $slug ) {
				$required = ! empty( $cat['required'] );
				$exists   = true;
				break;
			}
		}
		// Nothing the visitor could accept (Light, a deleted category): a text, no button.
		$texts = $light || ! $exists ? array( 'text' => CMP_Lang::text( $light ? 'map_light' : 'map_off', $lang ) ) : array(
			'text'   => CMP_Lang::text( 'map_text', $lang ),
			'load'   => CMP_Lang::text( 'map_load', $lang ),
			'reload' => CMP_Lang::text( 'map_reload', $lang ),
			'help'   => CMP_Lang::text( 'map_help', $lang ),
		);
		return array(
			'n' => $cookie_name,
			'v' => (string) $version,
			'c' => $slug,
			'r' => $required,
			's' => is_array( $svc_by_cat ) && isset( $svc_by_cat[ $slug ] ) && is_array( $svc_by_cat[ $slug ] ) ? array_values( $svc_by_cat[ $slug ] ) : null,
			'm' => $light ? 'light' : 'pro',
			'l' => $lang,
			't' => $texts,
		);
	}

	/** WordPress: the config of this request: the cookie, version, categories and services the banner gets, the page language. */
	public static function current(): array {
		$settings   = CMP_Consent::get_settings();
		$categories = CMP_Consent::get_categories();
		$categories = is_array( $categories ) ? $categories : array();
		$slug       = 'marketing';
		foreach ( CMP_Services::active() as $s ) {
			if ( is_array( $s ) && isset( $s['id'], $s['category'] ) && CMP_Services::GATE_ID === $s['id'] ) {
				$slug = (string) $s['category'];
			}
		}
		return self::config(
			is_array( $settings ) ? $settings : array(),
			$categories,
			CMP_Services::current_svc_by_cat( $categories ),
			CMP_Consent::cookie_name(),
			CMP_Consent::current_version(),
			$slug,
			CMP_Lang::current()
		);
	}

	/** The gate's script (the .min, or the source when it is missing), without the hash header; '' when neither is readable. */
	public static function js(): string {
		if ( null === self::$js ) {
			$base = dirname( __DIR__, 2 ) . '/';
			require_once $base . 'includes/class-assets.php';
			$file     = $base . CMP_Assets::src_or_min( self::JS, defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG, $base );
			$js       = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
			self::$js = trim( (string) preg_replace( '~^/\*! cmp-src-sha256:[0-9a-f]+ \*/\s*~', '', $js ) );
		}
		return self::$js;
	}

	/** Pure: JSON that can stand inside an inline script (ASCII; no <, >, &, quotes inside values). */
	private static function json( $data ): string {
		return (string) json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE );
	}

	/** Pure: the callback named in the URL of the API (a plain dotted name), or ''. */
	private static function callback( string $url ): string {
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
		return isset( $q['callback'] ) && is_string( $q['callback'] ) && 1 === preg_match( '/^[\w$]+(?:\.[\w$]+)*\z/', $q['callback'] ) ? $q['callback'] : '';
	}

	/**
	 * Pure, but for the script file: the gate that replaces a tag (is_tag()), '' when
	 * the script cannot be read. The tag goes inside as data (base64 of ASCII JSON, so
	 * neither the URL nor any markup is in the page), never as text.
	 */
	public static function gate( string $attrs, string $body, array $config ): string {
		$js = self::js();
		if ( '' === $js ) {
			return '';
		}
		list( $attrs, $body ) = self::split( $attrs, $body );
		$a     = self::attrs( $attrs );
		$url   = self::source( $a );
		$nonce = isset( $a['nonce'] ) ? $a['nonce'] : '';
		$type  = isset( $a['type'] ) ? strtolower( trim( $a['type'] ) ) : '';
		// An optimizer's own type holds the tag back: the original had none, or a module's.
		$run  = 'module' === $type || 1 === preg_match( '~^(?:text|application)/(?:x-)?(?:java|ecma)script$~', $type );
		$list = array();
		$done = false;
		foreach ( $a as $name => $value ) {
			$is_src = 'src' === $name || ( self::is_moved_src( $name ) && null !== $value && self::clean( $value ) === $url );
			if ( '' !== $url && $is_src ) {
				if ( ! $done ) {
					$list[] = array( 'src', $url );
					$done   = true;
				}
				continue;
			}
			if ( 'nonce' === $name || 'src' === $name || ( 'type' === $name && ! $run ) || 0 === strpos( $name, 'data-cmp-' ) || 1 !== preg_match( '/^[a-z_:][a-z0-9_:.-]*\z/', $name ) ) {
				continue;
			}
			$list[] = array( $name, $value );
		}
		$async       = array_key_exists( 'async', $a );
		$config['k'] = base64_encode( self::json( array(
			'a' => $list,
			'b' => '' === $url ? $body : null,
			'r' => '' === $url ? self::rest( $body ) : '',
			'u' => '' === $url ? '' : self::callback( $url ),
			'n' => $nonce,
			'w' => '' !== $url && ! $async,
			'y' => $async,
			'm' => '' === $url && ! $async && 'module' === $type,
		) ) );
		$slug = isset( $config['c'] ) ? (string) $config['c'] : '';
		// data-cmp-keep: no second rewrite. The rest asks optimizers to leave it in place.
		return '<script data-cmp-keep data-cmp-service="' . CMP_Services::GATE_ID . '" data-cmp-maps-gate="' . htmlspecialchars( $slug, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"'
			. ' data-no-optimize="1" data-cfasync="false" nowprocket data-no-defer="1" data-no-minify="1"'
			. ( '' === $nonce ? '' : ' nonce="' . htmlspecialchars( $nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"' )
			. '>' . str_replace( self::TOKEN, self::json( $config ), $js ) . '</script>';
	}
}
