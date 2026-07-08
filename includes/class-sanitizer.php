<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone-loadable for tests.
}

class CMP_Sanitizer {

	public static function settings( array $input ) {
		$positions = array( 'bottom', 'top', 'modal-center' );
		$layouts   = array( 'compact', 'detailed' );
		$presets   = array( 'neutral', 'warm', 'dark', 'egov' );

		$pos = isset( $input['position'] ) ? (string) $input['position'] : '';
		$lay = isset( $input['layout'] ) ? (string) $input['layout'] : '';
		$pre = isset( $input['preset'] ) ? (string) $input['preset'] : '';
		$ver = isset( $input['policy_version'] ) ? trim( (string) $input['policy_version'] ) : '';
		$ret = isset( $input['retention_months'] ) ? (int) $input['retention_months'] : 0;

		return array(
			'position'         => in_array( $pos, $positions, true ) ? $pos : 'bottom',
			'layout'           => in_array( $lay, $layouts, true ) ? $lay : 'detailed',
			'preset'           => in_array( $pre, $presets, true ) ? $pre : 'neutral',
			'policy_version'   => '' !== $ver ? $ver : '1',
			'retention_months' => $ret >= 1 ? $ret : 13,
			'privacy_page_id'  => self::pos_int( $input, 'privacy_page_id' ),
			'terms_page_id'    => self::pos_int( $input, 'terms_page_id' ),
			'cookie_page_id'   => self::pos_int( $input, 'cookie_page_id' ),
		);
	}

	private static function pos_int( array $input, $key ) {
		$v = isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ? (int) $input[ $key ] : 0;
		return $v >= 0 ? $v : 0;
	}
}
