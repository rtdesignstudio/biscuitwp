<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class CMP_Audit_List_Table extends WP_List_Table {

	public function get_columns() {
		return array(
			'ts'         => __( 'Dată', 'consent-manager-pro' ),
			'consent_id' => __( 'ID consimțământ', 'consent-manager-pro' ),
			'decision'   => __( 'Decizie', 'consent-manager-pro' ),
			'categories' => __( 'Categorii', 'consent-manager-pro' ),
			'lang'       => __( 'Limbă', 'consent-manager-pro' ),
			'version'    => __( 'Versiune', 'consent-manager-pro' ),
			'url'        => __( 'URL', 'consent-manager-pro' ),
		);
	}

	public function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}

	public function prepare_items() {
		global $wpdb;
		$table    = $wpdb->prefix . 'cmp_consent_log';
		$per_page = 30;
		$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$offset   = ( $paged - 1 ) * $per_page;

		$where  = '1=1';
		$params = array();
		$dec    = isset( $_GET['decision'] ) ? sanitize_text_field( wp_unslash( $_GET['decision'] ) ) : '';
		if ( in_array( $dec, array( 'accepted_all', 'rejected_all', 'partial', 'withdrawn' ), true ) ) {
			$where   .= ' AND decision = %s';
			$params[] = $dec;
		}

		$cid = isset( $_GET['cid'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_GET['cid'] ) ) ) ) : '';
		if ( preg_match( '/^[0-9a-f-]{8,36}$/', $cid ) ) {
			$where   .= ' AND consent_id LIKE %s';
			$params[] = $wpdb->esc_like( $cid ) . '%';
		}

		$total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total     = $params ? (int) $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) : (int) $wpdb->get_var( $total_sql );

		$sql          = "SELECT * FROM {$table} WHERE {$where} ORDER BY ts DESC LIMIT %d OFFSET %d";
		$query_params = array_merge( $params, array( $per_page, $offset ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $query_params ), ARRAY_A );

		$this->items           = $rows ? $rows : array();
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->set_pagination_args( array( 'total_items' => $total, 'per_page' => $per_page ) );
	}
}
