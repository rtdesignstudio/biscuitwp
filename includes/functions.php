<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'cmp_is_allowed' ) ) {
	/**
	 * Whether the visitor's stored consent allows a category (server side).
	 * A required category is always allowed; a missing or outdated decision
	 * allows nothing else. Mirrors window.CMP.isAllowed() in the browser.
	 *
	 * @param string $category Category slug, e.g. 'statistics' or 'marketing'.
	 */
	function cmp_is_allowed( string $category ): bool {
		return (bool) CMP_Consent::is_allowed( $category );
	}
}
