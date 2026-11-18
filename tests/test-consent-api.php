<?php
require_once __DIR__ . '/../includes/modules/class-consent-api.php';

// WP Consent API: CMP category slugs -> WP Consent API categories.
cmp_assert_eq( CMP_Consent_API::map( array( 'necessary' ) ), array( 'necessary' => array( 'functional' ) ), 'necessary -> functional' );
cmp_assert_eq( CMP_Consent_API::map( array( 'statistics' ) ), array( 'statistics' => array( 'statistics', 'statistics-anonymous' ) ), 'statistics -> statistics + statistics-anonymous' );
cmp_assert_eq( CMP_Consent_API::map( array( 'marketing' ) ), array( 'marketing' => array( 'marketing' ) ), 'marketing -> marketing' );
cmp_assert_eq( CMP_Consent_API::map( array( 'preferences' ) ), array( 'preferences' => array( 'preferences' ) ), 'preferences -> preferences' );
cmp_assert_eq(
	CMP_Consent_API::map( array( 'necessary', 'statistics', 'marketing', 'preferences' ) ),
	array(
		'necessary'   => array( 'functional' ),
		'statistics'  => array( 'statistics', 'statistics-anonymous' ),
		'marketing'   => array( 'marketing' ),
		'preferences' => array( 'preferences' ),
	),
	'full map in the given order'
);
cmp_assert_eq( CMP_Consent_API::map( array( 'necessary', 'custom_x', 'bogus' ) ), array( 'necessary' => array( 'functional' ) ), 'unknown / custom slugs are omitted' );
cmp_assert_eq( CMP_Consent_API::map( array() ), array(), 'empty input -> empty map' );
cmp_assert_eq( CMP_Consent_API::active(), function_exists( 'wp_set_consent' ), 'active() mirrors wp_set_consent' );
