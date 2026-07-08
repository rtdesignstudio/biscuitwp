<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMP_Admin {

	const SLUG = 'cmp-settings';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
	}

	public function menu() {
		// Populated in Task 2.
	}
}
