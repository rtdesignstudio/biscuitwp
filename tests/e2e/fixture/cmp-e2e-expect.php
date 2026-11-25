<?php
/**
 * Plugin Name: CMP e2e expectations (B7.1)
 * Description: Test-only mu-plugin (Playground only, loopback requests only).
 *
 * GET /?cmp_e2e_expect=1 → JSON with the services the plugin itself lets load
 * before consent, so the browser test follows the plugin's real logic instead
 * of a copy of it:
 *   - before:         Strict (the library + stored overrides, CMP_Services::all());
 *   - advancedBefore: the same after CMP_Services::apply_measurement( …, true ).
 * A service loads before consent when the blocker leaves it alone: category
 * "necessary" or `exempt` (CMP_Blocker::rewritable(); in Advanced, GA4/GTM get
 * exempt from the mode). Required non-necessary categories still wait for the
 * visitor's decision in banner.js, so they are not listed.
 * The Maps API loader of a theme (google-maps-js) follows the stored maps_gate
 * (apply_gate()): off = it loads before consent; on = 'gated' names it: it waits for
 * consent and comes back on the next page, not on the one where it was given.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'init',
	function () {
		if ( ! isset( $_GET['cmp_e2e_expect'] ) ) {
			return;
		}
		if ( ! defined( 'CMP_E2E' ) || ! function_exists( 'cmp_e2e_local_request' ) || ! cmp_e2e_local_request() ) {
			status_header( 404 );
			exit;
		}
		if ( ! class_exists( 'CMP_Services' ) || ! class_exists( 'CMP_Consent' ) ) {
			wp_send_json( array( 'error' => 'BiscuitWP is not active' ), 500 );
		}
		$slugs = array();
		foreach ( (array) CMP_Consent::get_categories() as $cat ) {
			if ( is_array( $cat ) && ! empty( $cat['slug'] ) ) {
				$slugs[] = (string) $cat['slug'];
			}
		}
		$stored = get_option( 'cmp_services', array() );
		$gate   = CMP_Services::gate_now();
		$all    = CMP_Services::apply_gate( CMP_Services::all( CMP_Services::library(), is_array( $stored ) ? $stored : array(), $slugs ), $gate );
		$before = function ( array $services ) {
			$out = array();
			foreach ( $services as $s ) {
				if ( ! is_array( $s ) || empty( $s['id'] ) || ( isset( $s['source'] ) && 'library' !== $s['source'] ) ) {
					continue;
				}
				if ( ( isset( $s['category'] ) && 'necessary' === $s['category'] ) || ! empty( $s['exempt'] ) ) {
					$out[] = (string) $s['id'];
				}
			}
			return $out;
		};
		$library = array();
		foreach ( $all as $s ) {
			if ( is_array( $s ) && isset( $s['source'] ) && 'library' === $s['source'] ) {
				$library[] = array(
					'id'             => (string) $s['id'],
					'category'       => (string) $s['category'],
					'consent_exempt' => CMP_Services::consent_exempt( $s ),
				);
			}
		}
		wp_send_json(
			array(
				'before'         => $before( $all ),
				'gated'          => $gate ? array( CMP_Services::GATE_ID ) : array(),
				'advancedBefore' => $before( CMP_Services::apply_measurement( $all, true ) ),
				'required'       => CMP_Services::required_slugs( (array) CMP_Consent::get_categories() ),
				'library'        => $library,
				'usingFallback'  => CMP_Services::using_fallback(),
			)
		);
	},
	1
);
