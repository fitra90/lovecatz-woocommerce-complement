<?php
/**
 * Promo coupon administration for LoveCatz WooCommerce Complement.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_Promo_Admin {

	/** Register promo management hooks. */
	public function init() {
		add_action( 'admin_post_lwc_save_promo_coupon', array( $this, 'save_coupon' ) );
		add_action( 'admin_post_lwc_delete_promo_coupon', array( $this, 'delete_coupon' ) );
		add_action( 'lwc_render_promo_manager', array( $this, 'render_manager' ) );
	}

	/** Render the promo manager within the LoveCatz settings page. */
	public function render_manager() {
		if ( ! class_exists( 'WC_Coupon' ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'WooCommerce coupons are unavailable.', 'lovecatz-wc' ) . '</p></div>';
			return;
		}

		$coupon = $this->get_requested_coupon();
		$editing = $coupon instanceof WC_Coupon && $coupon->get_id();
		$values = $this->get_form_values( $coupon );
		?>
		<div class="lwc-promo-manager">
			<div class="lwc-promo-manager-heading">
				<div><h2><?php echo esc_html( $editing ? __( 'Edit Promo Coupon', 'lovecatz-wc' ) : __( 'Create Promo Coupon', 'lovecatz-wc' ) ); ?></h2><p><?php esc_html_e( 'Create native WooCommerce coupons with customer targeting and card visuals.', 'lovecatz-wc' ); ?></p></div>
				<?php if ( $editing ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=lovecatz-wc&tab=promo' ) ); ?>"><?php esc_html_e( 'Create new coupon', 'lovecatz-wc' ); ?></a><?php endif; ?>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lwc-promo-form">
				<?php wp_nonce_field( 'lwc_save_promo_coupon', 'lwc_promo_nonce' ); ?>
				<input type="hidden" name="action" value="lwc_save_promo_coupon" />
				<input type="hidden" name="coupon_id" value="<?php echo esc_attr( $values['id'] ); ?>" />
				<div class="lwc-promo-form-grid">
					<label><span><?php esc_html_e( 'Coupon code', 'lovecatz-wc' ); ?></span><input required name="coupon_code" value="<?php echo esc_attr( $values['code'] ); ?>" placeholder="WELCOME10" /></label>
					<label><span><?php esc_html_e( 'Discount type', 'lovecatz-wc' ); ?></span><select name="discount_type" id="lwc_promo_discount_type"><option value="percent" <?php selected( $values['type'], 'percent' ); ?>><?php esc_html_e( 'Percentage discount', 'lovecatz-wc' ); ?></option><option value="fixed_cart" <?php selected( $values['type'], 'fixed_cart' ); ?>><?php esc_html_e( 'Fixed cart discount', 'lovecatz-wc' ); ?></option></select></label>
					<label class="lwc-percent-only"><span><?php esc_html_e( 'Discount percentage', 'lovecatz-wc' ); ?></span><input type="number" name="percentage_amount" min="1" max="100" step="0.01" value="<?php echo esc_attr( $values['percentage_amount'] ); ?>" /></label>
					<label class="lwc-fixed-only"><span><?php esc_html_e( 'Discount amount', 'lovecatz-wc' ); ?></span><input type="number" name="fixed_amount" min="1" step="0.01" value="<?php echo esc_attr( $values['fixed_amount'] ); ?>" /></label>
					<label class="lwc-percent-only"><span><?php esc_html_e( 'Maximum discount', 'lovecatz-wc' ); ?></span><input type="number" name="maximum_discount" min="1" step="0.01" value="<?php echo esc_attr( $values['maximum_discount'] ); ?>" /><small><?php esc_html_e( 'Optional cap for percentage discounts.', 'lovecatz-wc' ); ?></small></label>
					<label><span><?php esc_html_e( 'Expiry date', 'lovecatz-wc' ); ?></span><input type="date" name="expiry_date" value="<?php echo esc_attr( $values['expiry_date'] ); ?>" /></label>
					<label><span><?php esc_html_e( 'Eligible users', 'lovecatz-wc' ); ?></span><?php $this->render_eligible_user_select( $values['eligible_user_ids'] ); ?><small><?php esc_html_e( 'Leave empty for all users.', 'lovecatz-wc' ); ?></small></label>
					<label><span><?php esc_html_e( 'Total usage limit', 'lovecatz-wc' ); ?></span><input type="number" name="usage_limit" min="1" step="1" value="<?php echo esc_attr( $values['usage_limit'] ); ?>" /><small><?php esc_html_e( 'Leave empty for unlimited uses.', 'lovecatz-wc' ); ?></small></label>
					<label><span><?php esc_html_e( 'Usage limit per user', 'lovecatz-wc' ); ?></span><input type="number" name="usage_limit_per_user" min="1" step="1" value="<?php echo esc_attr( $values['usage_limit_per_user'] ); ?>" /><small><?php esc_html_e( 'Leave empty for no per-user limit.', 'lovecatz-wc' ); ?></small></label>
					<label class="lwc-promo-image-field"><span><?php esc_html_e( 'Active card image', 'lovecatz-wc' ); ?></span><input type="hidden" id="lwc_promo_active_image_id" name="active_image_id" value="<?php echo esc_attr( $values['active_image_id'] ); ?>" /><button type="button" class="button lwc-promo-image-select" data-target="#lwc_promo_active_image_id"><?php esc_html_e( 'Choose image', 'lovecatz-wc' ); ?></button><?php $this->render_image_preview( $values['active_image_id'] ); ?></label>
					<label class="lwc-promo-image-field"><span><?php esc_html_e( 'Disabled card image', 'lovecatz-wc' ); ?></span><input type="hidden" id="lwc_promo_disabled_image_id" name="disabled_image_id" value="<?php echo esc_attr( $values['disabled_image_id'] ); ?>" /><button type="button" class="button lwc-promo-image-select" data-target="#lwc_promo_disabled_image_id"><?php esc_html_e( 'Choose image', 'lovecatz-wc' ); ?></button><?php $this->render_image_preview( $values['disabled_image_id'] ); ?><small><?php esc_html_e( 'Shown for expired or fully used coupons.', 'lovecatz-wc' ); ?></small></label>
				</div>
				<p><label><input type="checkbox" name="individual_use" value="yes" <?php checked( $values['individual_use'], 'yes' ); ?> /> <?php esc_html_e( 'Cannot be combined with other coupons', 'lovecatz-wc' ); ?></label></p>
				<?php submit_button( $editing ? __( 'Update coupon', 'lovecatz-wc' ) : __( 'Create coupon', 'lovecatz-wc' ), 'primary', 'submit', false ); ?>
			</form>
			<h2><?php esc_html_e( 'Promo coupons', 'lovecatz-wc' ); ?></h2>
			<?php $this->render_coupon_list(); ?>
		</div>
		<?php
	}

	/** Save an admin-created promo coupon. */
	public function save_coupon() {
		$this->assert_permission( 'lwc_save_promo_coupon' );
		$id = isset( $_POST['coupon_id'] ) ? absint( $_POST['coupon_id'] ) : 0;
		$coupon = $id ? new WC_Coupon( $id ) : new WC_Coupon();
		if ( $id && ( ! $coupon->get_id() || '1' !== get_post_meta( $id, '_lwc_promo_created', true ) ) ) { wp_die( esc_html__( 'Invalid promo coupon.', 'lovecatz-wc' ) ); }
		$code = isset( $_POST['coupon_code'] ) ? wc_format_coupon_code( wp_unslash( $_POST['coupon_code'] ) ) : '';
		if ( '' === $code ) { wp_die( esc_html__( 'Coupon code is required.', 'lovecatz-wc' ) ); }
		$type = isset( $_POST['discount_type'] ) && 'fixed_cart' === $_POST['discount_type'] ? 'fixed_cart' : 'percent';
		$amount = 'fixed_cart' === $type ? ( $_POST['fixed_amount'] ?? 1 ) : ( $_POST['percentage_amount'] ?? 1 );
		$user_ids = isset( $_POST['eligible_user_ids'] ) && is_array( $_POST['eligible_user_ids'] ) ? array_filter( array_map( 'absint', wp_unslash( $_POST['eligible_user_ids'] ) ) ) : array();
		$emails = array(); foreach ( $user_ids as $user_id ) { $user = get_userdata( $user_id ); if ( $user ) { $emails[] = $user->user_email; } }
		$coupon->set_code( $code );
		$coupon->set_discount_type( $type );
		$coupon->set_amount( max( 1, (float) $amount ) );
		$coupon->set_individual_use( isset( $_POST['individual_use'] ) );
		$coupon->set_usage_limit( ! empty( $_POST['usage_limit'] ) ? absint( $_POST['usage_limit'] ) : 0 );
		$coupon->set_usage_limit_per_user( ! empty( $_POST['usage_limit_per_user'] ) ? absint( $_POST['usage_limit_per_user'] ) : 0 );
		$coupon->set_email_restrictions( $emails );
		$coupon->set_date_expires( ! empty( $_POST['expiry_date'] ) ? sanitize_text_field( wp_unslash( $_POST['expiry_date'] ) ) : null );
		$coupon->save();
		$id = $coupon->get_id();
		update_post_meta( $id, '_lwc_promo_created', '1' );
		update_post_meta( $id, '_lwc_promo_eligible_user_ids', $user_ids );
		update_post_meta( $id, '_lwc_promo_maximum_discount', 'percent' === $type && ! empty( $_POST['maximum_discount'] ) ? wc_format_decimal( wp_unslash( $_POST['maximum_discount'] ) ) : '' );
		update_post_meta( $id, '_lwc_promo_active_image_id', isset( $_POST['active_image_id'] ) ? absint( $_POST['active_image_id'] ) : 0 );
		update_post_meta( $id, '_lwc_promo_disabled_image_id', isset( $_POST['disabled_image_id'] ) ? absint( $_POST['disabled_image_id'] ) : 0 );
		wp_safe_redirect( add_query_arg( array( 'page' => 'lovecatz-wc', 'tab' => 'promo', 'promo_updated' => '1' ), admin_url( 'admin.php' ) ) ); exit;
	}

	/** Delete a promo coupon. */
	public function delete_coupon() { $this->assert_permission( 'lwc_delete_promo_coupon' ); $id = isset( $_GET['coupon_id'] ) ? absint( $_GET['coupon_id'] ) : 0; if ( $id && '1' === get_post_meta( $id, '_lwc_promo_created', true ) ) { wp_trash_post( $id ); } wp_safe_redirect( add_query_arg( array( 'page' => 'lovecatz-wc', 'tab' => 'promo' ), admin_url( 'admin.php' ) ) ); exit; }

	private function assert_permission( $action ) { if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You do not have permission to manage promo coupons.', 'lovecatz-wc' ) ); } check_admin_referer( $action, 'lwc_promo_nonce' ); }
	private function get_requested_coupon() { $id = isset( $_GET['coupon_id'] ) ? absint( $_GET['coupon_id'] ) : 0; return $id && '1' === get_post_meta( $id, '_lwc_promo_created', true ) ? new WC_Coupon( $id ) : null; }
	private function get_form_values( $coupon ) { $id = $coupon ? $coupon->get_id() : 0; $type = $coupon ? $coupon->get_discount_type() : 'percent'; $amount = $coupon ? $coupon->get_amount() : ''; return array( 'id' => $id, 'code' => $coupon ? $coupon->get_code() : '', 'type' => $type, 'percentage_amount' => 'percent' === $type ? $amount : '', 'fixed_amount' => 'fixed_cart' === $type ? $amount : '', 'maximum_discount' => $id ? get_post_meta( $id, '_lwc_promo_maximum_discount', true ) : '', 'expiry_date' => $coupon && $coupon->get_date_expires() ? $coupon->get_date_expires()->date( 'Y-m-d' ) : '', 'eligible_user_ids' => $id ? array_map( 'absint', (array) get_post_meta( $id, '_lwc_promo_eligible_user_ids', true ) ) : array(), 'usage_limit' => $coupon ? $coupon->get_usage_limit() : '', 'usage_limit_per_user' => $coupon ? $coupon->get_usage_limit_per_user() : '', 'individual_use' => $coupon && $coupon->get_individual_use() ? 'yes' : 'no', 'active_image_id' => $id ? absint( get_post_meta( $id, '_lwc_promo_active_image_id', true ) ) : 0, 'disabled_image_id' => $id ? absint( get_post_meta( $id, '_lwc_promo_disabled_image_id', true ) ) : 0 ); }
	private function render_eligible_user_select( $selected ) { wp_dropdown_users( array( 'name' => 'eligible_user_ids[]', 'id' => 'lwc_promo_eligible_user_ids', 'selected' => $selected, 'multi' => true, 'show_option_none' => __( 'All users', 'lovecatz-wc' ), 'class' => 'lwc-promo-user-select' ) ); }
	private function render_coupon_list() { $posts = get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => 'publish', 'meta_key' => '_lwc_promo_created', 'meta_value' => '1', 'numberposts' => -1 ) ); if ( ! $posts ) { echo '<p>' . esc_html__( 'No promo coupons have been created.', 'lovecatz-wc' ) . '</p>'; return; } echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Code', 'lovecatz-wc' ) . '</th><th>' . esc_html__( 'Discount', 'lovecatz-wc' ) . '</th><th>' . esc_html__( 'Expiry', 'lovecatz-wc' ) . '</th><th></th></tr></thead><tbody>'; foreach ( $posts as $post ) { $coupon = new WC_Coupon( $post->ID ); $edit = add_query_arg( array( 'page' => 'lovecatz-wc', 'tab' => 'promo', 'coupon_id' => $post->ID ), admin_url( 'admin.php' ) ); $delete = wp_nonce_url( add_query_arg( array( 'action' => 'lwc_delete_promo_coupon', 'coupon_id' => $post->ID ), admin_url( 'admin-post.php' ) ), 'lwc_delete_promo_coupon', 'lwc_promo_nonce' ); echo '<tr><td><strong>' . esc_html( $coupon->get_code() ) . '</strong></td><td>' . esc_html( $coupon->get_amount() . ( 'percent' === $coupon->get_discount_type() ? '%' : '' ) ) . '</td><td>' . esc_html( $coupon->get_date_expires() ? $coupon->get_date_expires()->date_i18n( get_option( 'date_format' ) ) : '—' ) . '</td><td><a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'lovecatz-wc' ) . '</a> · <a class="submitdelete" href="' . esc_url( $delete ) . '">' . esc_html__( 'Trash', 'lovecatz-wc' ) . '</a></td></tr>'; } echo '</tbody></table>'; }
	private function render_image_preview( $image_id ) { $url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : ''; echo '<span class="lwc-promo-image-preview">' . ( $url ? '<img src="' . esc_url( $url ) . '" alt="" />' : '' ) . '</span>'; }
}
