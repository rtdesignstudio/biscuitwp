<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone-loadable for tests.
}

class CMP_Sanitizer {

	/** Longest a stored consent may last: 13 months (EU practice; CNIL: 6–13). */
	const MAX_COOKIE_DAYS = 396;

	/** Pure: consent duration in days, capped at 13 months; invalid -> 180. */
	public static function cap_cookie_days( $days ) {
		$days = (int) $days;
		if ( $days < 1 ) {
			return 180;
		}
		return min( $days, self::MAX_COOKIE_DAYS );
	}

	public static function settings( array $input ) {
		$positions = array(
			'top-center', 'middle-center', 'bottom-center',
			'top-left', 'bottom-left', 'top-right', 'bottom-right',
			'bottom', 'top', 'modal-center', // legacy aliases
		);
		$layouts   = array( 'compact', 'detailed' );
		$presets   = array( 'neutral', 'warm', 'dark', 'egov' );
		$modes = array( 'pro', 'light' );
		$mode  = isset( $input['mode'] ) ? (string) $input['mode'] : '';

		$pos = isset( $input['position'] ) ? (string) $input['position'] : '';
		$lay = isset( $input['layout'] ) ? (string) $input['layout'] : '';
		$pre = isset( $input['preset'] ) ? (string) $input['preset'] : '';
		$ver = isset( $input['policy_version'] ) ? trim( (string) $input['policy_version'] ) : '';
		$ret = isset( $input['retention_months'] ) ? (int) $input['retention_months'] : 0;
		$cd  = isset( $input['cookie_days'] ) ? (int) $input['cookie_days'] : 0;

		$text_keys = array( 'title', 'intro', 'light_intro', 'accept', 'reject', 'prefs', 'save', 'prefs_title' );
		$texts_in  = isset( $input['texts'] ) && is_array( $input['texts'] ) ? $input['texts'] : array();
		$texts     = array();
		foreach ( $text_keys as $tk ) {
			$texts[ $tk ] = isset( $texts_in[ $tk ] ) ? trim( strip_tags( (string) $texts_in[ $tk ] ) ) : '';
		}

		return array(
			'position'         => in_array( $pos, $positions, true ) ? $pos : 'middle-center',
			'layout'           => in_array( $lay, $layouts, true ) ? $lay : 'detailed',
			'preset'           => in_array( $pre, $presets, true ) ? $pre : 'neutral',
			'mode'             => in_array( $mode, $modes, true ) ? $mode : 'pro',
			'policy_version'   => '' !== $ver ? $ver : '1',
			'retention_months' => $ret >= 1 ? $ret : 13,
			'cookie_days'      => self::cap_cookie_days( $cd ),
			'privacy_page_id'  => self::pos_int( $input, 'privacy_page_id' ),
			'terms_page_id'    => self::pos_int( $input, 'terms_page_id' ),
			'cookie_page_id'   => self::pos_int( $input, 'cookie_page_id' ),
			'github_token'     => isset( $input['github_token'] ) ? trim( strip_tags( (string) $input['github_token'] ) ) : '',
			'block_scripts'    => ! empty( $input['block_scripts'] ),
			'localize_fonts'   => ! empty( $input['localize_fonts'] ),
			'reopen_button'    => ! empty( $input['reopen_button'] ),
			'reload_on_decision' => ! empty( $input['reload_on_decision'] ),
			'texts'            => $texts,
		);
	}

	public static function categories( array $input ) {
		$allowed_signals = array(
			'analytics_storage', 'ad_storage', 'ad_user_data', 'ad_personalization',
			'functionality_storage', 'personalization_storage', 'security_storage',
		);
		$out = array();
		foreach ( $input as $cat ) {
			if ( ! is_array( $cat ) ) {
				continue;
			}
			$slug = isset( $cat['slug'] ) ? self::slug( $cat['slug'] ) : '';
			if ( '' === $slug ) {
				continue;
			}
			$signals = array();
			foreach ( (array) ( isset( $cat['gtag_signals'] ) ? $cat['gtag_signals'] : array() ) as $sig ) {
				$sig = (string) $sig;
				if ( in_array( $sig, $allowed_signals, true ) && ! in_array( $sig, $signals, true ) ) {
					$signals[] = $sig;
				}
			}
			$cookies = array();
			foreach ( (array) ( isset( $cat['cookies'] ) ? $cat['cookies'] : array() ) as $ck ) {
				if ( ! is_array( $ck ) || empty( $ck['name'] ) ) {
					continue;
				}
				$cookies[] = array(
					'name'     => self::text( isset( $ck['name'] ) ? $ck['name'] : '' ),
					'provider' => self::text( isset( $ck['provider'] ) ? $ck['provider'] : '' ),
					'duration' => self::text( isset( $ck['duration'] ) ? $ck['duration'] : '' ),
					'purpose'  => self::text( isset( $ck['purpose'] ) ? $ck['purpose'] : '' ),
				);
			}
			$out[] = array(
				'slug'         => $slug,
				'label'        => self::text( isset( $cat['label'] ) ? $cat['label'] : '' ),
				'description'  => self::text( isset( $cat['description'] ) ? $cat['description'] : '' ),
				'required'     => ! empty( $cat['required'] ),
				'gtag_signals' => $signals,
				'cookies'      => $cookies,
			);
		}
		return $out;
	}

	private static function slug( $value ) {
		$value = strtolower( (string) $value );
		return preg_replace( '/[^a-z0-9_]/', '', $value );
	}

	private static function text( $value ) {
		return trim( strip_tags( (string) $value ) );
	}

	private static function pos_int( array $input, $key ) {
		$v = isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ? (int) $input[ $key ] : 0;
		return $v >= 0 ? $v : 0;
	}
}
