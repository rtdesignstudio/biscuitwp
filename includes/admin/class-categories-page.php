<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMP_Categories_Page {

	const SIGNALS = array(
		'analytics_storage', 'ad_storage', 'ad_user_data', 'ad_personalization',
		'functionality_storage', 'personalization_storage', 'security_storage',
	);

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$cats = CMP_Consent::get_categories();
		?>
		<div class="wrap cmp-admin">
			<h1><?php esc_html_e( 'Consent Manager — Categorii', 'consent-manager-pro' ); ?> <?php echo CMP_Guide::link( 'services' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in link() ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Categorii salvate.', 'consent-manager-pro' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cmp_save_categories">
				<?php wp_nonce_field( 'cmp_save_categories' ); ?>
				<div id="cmp-cats" data-cmp-cats>
					<?php foreach ( $cats as $i => $cat ) : ?>
						<?php self::row( (int) $i, $cat ); ?>
					<?php endforeach; ?>
				</div>
				<p><button type="button" class="button" data-cmp-add-cat><?php esc_html_e( '+ Adaugă categorie', 'consent-manager-pro' ); ?></button></p>
				<?php submit_button( __( 'Salvează categoriile', 'consent-manager-pro' ) ); ?>
			</form>

			<script type="text/template" id="cmp-cat-template"><?php self::row( 0, array(), true ); ?></script>
		</div>
		<?php
	}

	private static function row( $i, $cat, $is_template = false ) {
		$idx      = $is_template ? '__i__' : (string) $i;
		$slug     = isset( $cat['slug'] ) ? $cat['slug'] : '';
		$label    = isset( $cat['label'] ) ? $cat['label'] : '';
		$desc     = isset( $cat['description'] ) ? $cat['description'] : '';
		$required = ! empty( $cat['required'] );
		$signals  = isset( $cat['gtag_signals'] ) ? (array) $cat['gtag_signals'] : array();
		$cookies  = isset( $cat['cookies'] ) ? (array) $cat['cookies'] : array();
		$n        = 'cmp_cats[' . $idx . ']';
		?>
		<div class="cmp-cat-row" data-cmp-cat-row>
			<div class="cmp-cat-row__head">
				<strong><?php esc_html_e( 'Categorie', 'consent-manager-pro' ); ?></strong>
				<button type="button" class="button-link button-link-delete" data-cmp-del-cat><?php esc_html_e( 'Șterge', 'consent-manager-pro' ); ?></button>
			</div>
			<p class="cmp-cat-row__names">
				<label class="cmp-cat-row__label"><?php esc_html_e( 'Etichetă', 'consent-manager-pro' ); ?><br>
					<input type="text" name="<?php echo esc_attr( $n ); ?>[label]" value="<?php echo esc_attr( $label ); ?>"></label>
				<label class="cmp-cat-row__slug"><?php esc_html_e( 'Slug', 'consent-manager-pro' ); ?><br>
					<input type="text" name="<?php echo esc_attr( $n ); ?>[slug]" value="<?php echo esc_attr( $slug ); ?>" required></label>
			</p>
			<p>
				<label><?php esc_html_e( 'Descriere', 'consent-manager-pro' ); ?><br>
					<textarea class="large-text" rows="2" name="<?php echo esc_attr( $n ); ?>[description]"><?php echo esc_textarea( $desc ); ?></textarea></label>
			</p>
			<p>
				<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[required]" value="1" <?php checked( $required ); ?>> <?php esc_html_e( 'Obligatorie (mereu activă)', 'consent-manager-pro' ); ?></label>
			</p>
			<div class="cmp-signals">
				<strong><?php esc_html_e( 'Google Consent Mode signals', 'consent-manager-pro' ); ?></strong><br>
				<?php foreach ( self::SIGNALS as $sig ) : ?>
					<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[gtag_signals][]" value="<?php echo esc_attr( $sig ); ?>" <?php checked( in_array( $sig, $signals, true ) ); ?>> <?php echo esc_html( $sig ); ?></label>
				<?php endforeach; ?>
			</div>
			<div class="cmp-cookies">
				<strong><?php esc_html_e( 'Cookies', 'consent-manager-pro' ); ?></strong>
				<table data-cmp-cookies>
					<thead><tr>
						<th><?php esc_html_e( 'Nume', 'consent-manager-pro' ); ?></th>
						<th><?php esc_html_e( 'Furnizor', 'consent-manager-pro' ); ?></th>
						<th><?php esc_html_e( 'Durată', 'consent-manager-pro' ); ?></th>
						<th><?php esc_html_e( 'Scop', 'consent-manager-pro' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody data-cmp-cookies-body>
						<?php foreach ( $cookies as $ci => $ck ) : ?>
							<?php self::cookie_row( $n, (int) $ci, $ck ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button" data-cmp-add-cookie><?php esc_html_e( '+ Adaugă cookie', 'consent-manager-pro' ); ?></button></p>
			</div>
		</div>
		<?php
	}

	private static function cookie_row( $cat_name, $ci, $ck ) {
		$cn = $cat_name . '[cookies][' . $ci . ']';
		?>
		<tr data-cmp-cookie-row>
			<td><input type="text" name="<?php echo esc_attr( $cn ); ?>[name]" value="<?php echo esc_attr( isset( $ck['name'] ) ? $ck['name'] : '' ); ?>"></td>
			<td><input type="text" name="<?php echo esc_attr( $cn ); ?>[provider]" value="<?php echo esc_attr( isset( $ck['provider'] ) ? $ck['provider'] : '' ); ?>"></td>
			<td><input type="text" name="<?php echo esc_attr( $cn ); ?>[duration]" value="<?php echo esc_attr( isset( $ck['duration'] ) ? $ck['duration'] : '' ); ?>"></td>
			<td><input type="text" name="<?php echo esc_attr( $cn ); ?>[purpose]" value="<?php echo esc_attr( isset( $ck['purpose'] ) ? $ck['purpose'] : '' ); ?>"></td>
			<td><button type="button" class="button-link button-link-delete" data-cmp-del-cookie>&times;</button></td>
		</tr>
		<?php
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cmp_save_categories' ) ) {
			wp_die( esc_html__( 'Acțiune neautorizată.', 'consent-manager-pro' ) );
		}
		$raw   = isset( $_POST['cmp_cats'] ) && is_array( $_POST['cmp_cats'] ) ? wp_unslash( $_POST['cmp_cats'] ) : array();
		$clean = CMP_Sanitizer::categories( array_values( $raw ) );
		update_option( 'cmp_categories', $clean );
		wp_safe_redirect( add_query_arg( array( 'page' => 'cmp-categories', 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
