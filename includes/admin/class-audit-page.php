<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMP_Audit_Page {

	private static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'cmp_consent_log';
	}

	/** Filter labels for every decision in CMP_Audit::DECISIONS (stored values stay the keys). */
	public static function decision_labels() {
		return array(
			'accepted_all'     => __( 'Acceptat tot', 'biscuitwp' ),
			'rejected_all'     => __( 'Doar necesare', 'biscuitwp' ),
			'partial'          => __( 'Parțial', 'biscuitwp' ),
			'withdrawn'        => __( 'Retras', 'biscuitwp' ),
			'service_allow'    => __( 'Serviciu permis („Încarcă mereu”)', 'biscuitwp' ),
			'service_withdraw' => __( 'Serviciu retras', 'biscuitwp' ),
			'custom'           => __( 'Prin butonul site-ului', 'biscuitwp' ),
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$table = self::table_name();
		$counts = $wpdb->get_results( "SELECT decision, COUNT(*) AS n FROM {$table} GROUP BY decision", ARRAY_A );
		$summary = array();
		foreach ( (array) $counts as $c ) {
			$summary[ $c['decision'] ] = (int) $c['n'];
		}

		require_once CMP_PATH . 'includes/admin/class-audit-list-table.php';
		$list = new CMP_Audit_List_Table();
		$list->prepare_items();
		?>
		<div class="wrap cmp-admin">
			<h1><?php esc_html_e( 'Registru consimțăminte', 'biscuitwp' ); ?> <?php echo CMP_Guide::link( 'register' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in link() ?></h1>
			<p>
				<?php
				printf(
					/* translators: 1: accepted, 2: rejected, 3: partial */
					esc_html__( 'Acceptat tot: %1$d · Doar necesare: %2$d · Parțial: %3$d', 'biscuitwp' ),
					isset( $summary['accepted_all'] ) ? (int) $summary['accepted_all'] : 0,
					isset( $summary['rejected_all'] ) ? (int) $summary['rejected_all'] : 0,
					isset( $summary['partial'] ) ? (int) $summary['partial'] : 0
				);
				?>
			</p>
			<?php
			$cmp_settings  = get_option( 'cmp_settings', array() );
			$cmp_retention = isset( $cmp_settings['retention_months'] ) ? (int) $cmp_settings['retention_months'] : 13;
			?>
			<p class="description">
				<?php
				printf(
					/* translators: %d — retention in months */
					esc_html__( 'Retenție: %d luni (înregistrările mai vechi sunt șterse automat).', 'biscuitwp' ),
					$cmp_retention
				);
				?>
			</p>

			<form method="get">
				<input type="hidden" name="page" value="cmp-audit">
				<select name="decision">
					<option value=""><?php esc_html_e( 'Toate deciziile', 'biscuitwp' ); ?></option>
					<?php
					$opts = self::decision_labels();
					$cur  = isset( $_GET['decision'] ) ? sanitize_text_field( wp_unslash( $_GET['decision'] ) ) : '';
					foreach ( $opts as $k => $lbl ) :
						?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $cur, $k ); ?>><?php echo esc_html( $lbl ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="cid" value="<?php echo esc_attr( isset( $_GET['cid'] ) ? sanitize_text_field( wp_unslash( $_GET['cid'] ) ) : '' ); ?>" placeholder="<?php esc_attr_e( 'ID consimțământ', 'biscuitwp' ); ?>" class="regular-text" style="max-width:22em">
				<?php submit_button( __( 'Filtrează', 'biscuitwp' ), '', '', false ); ?>
				<p class="description"><?php esc_html_e( 'Vizitatorul își vede ID-ul în „Setări cookie”, sub preferințe. Caută după el ca să găsești istoricul alegerilor lui.', 'biscuitwp' ); ?></p>
			</form>

			<?php $list->display(); ?>

			<hr>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<input type="hidden" name="action" value="cmp_export_log">
				<?php wp_nonce_field( 'cmp_export_log' ); ?>
				<button type="submit" name="format" value="csv" class="button"><?php esc_html_e( 'Export CSV', 'biscuitwp' ); ?></button>
				<button type="submit" name="format" value="json" class="button"><?php esc_html_e( 'Export JSON', 'biscuitwp' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Ștergi definitiv tot registrul?', 'biscuitwp' ) ); ?>');">
				<input type="hidden" name="action" value="cmp_purge_log">
				<?php wp_nonce_field( 'cmp_purge_log' ); ?>
				<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Golește registrul', 'biscuitwp' ); ?></button>
			</form>
		</div>
		<?php
	}

	public static function export() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cmp_export_log' ) ) {
			wp_die( esc_html__( 'Acțiune neautorizată.', 'biscuitwp' ) );
		}
		global $wpdb;
		$rows   = $wpdb->get_results( "SELECT * FROM " . self::table_name() . " ORDER BY ts DESC", ARRAY_A );
		$rows   = $rows ? $rows : array();
		$format = isset( $_POST['format'] ) && 'json' === $_POST['format'] ? 'json' : 'csv';

		if ( 'json' === $format ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="consent-log.json"' );
			echo CMP_Audit::to_json( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput
		} else {
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="consent-log.csv"' );
			echo CMP_Audit::to_csv( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		exit;
	}

	public static function purge() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cmp_purge_log' ) ) {
			wp_die( esc_html__( 'Acțiune neautorizată.', 'biscuitwp' ) );
		}
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE `' . self::table_name() . '`' );
		wp_safe_redirect( add_query_arg( array( 'page' => 'cmp-audit', 'purged' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
