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
		$this->render_notice();

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
					<label><span><?php esc_html_e( 'Discount type', 'lovecatz-wc' ); ?></span><select name="discount_type" id="lwc_promo_discount_type"><option value="percent" <?php selected( $values['type'], 'percent' ); ?>><?php esc_html_e( 'Percentage discount', 'lovecatz-wc' ); ?></option><option value="fixed_cart" <?php selected( $values['type'], 'fixed_cart' ); ?>><?php esc_html_e( 'Fixed cart discount', 'lovecatz-wc' ); ?></option><option value="lwc_free_shipping" <?php selected( $values['type'], 'lwc_free_shipping' ); ?>><?php esc_html_e( 'Free shipping', 'lovecatz-wc' ); ?></option></select></label>
					<label class="lwc-percent-only"><span><?php esc_html_e( 'Discount percentage', 'lovecatz-wc' ); ?></span><input type="number" name="percentage_amount" min="1" max="100" step="1" value="<?php echo esc_attr( $values['percentage_amount'] ); ?>" /></label>
					<label class="lwc-fixed-only"><span><?php esc_html_e( 'Discount amount', 'lovecatz-wc' ); ?></span><input type="number" name="fixed_amount" min="1" step="1" value="<?php echo esc_attr( $values['fixed_amount'] ); ?>" /></label>
					<label class="lwc-maximum-only"><span><?php esc_html_e( 'Maximum discount', 'lovecatz-wc' ); ?></span><input type="number" name="maximum_discount" min="1" step="1" value="<?php echo esc_attr( $values['maximum_discount'] ); ?>" /><small><?php esc_html_e( 'Optional. This caps the total product discount for percentage promos, or the covered shipping cost for free-shipping promos. Leave empty for no cap.', 'lovecatz-wc' ); ?></small></label>
					<label><span><?php esc_html_e( 'Expiry date', 'lovecatz-wc' ); ?></span><input type="date" name="expiry_date" value="<?php echo esc_attr( $values['expiry_date'] ); ?>" /></label>
					<div class="lwc-promo-eligibility-field">
						<span class="lwc-promo-field-label"><?php esc_html_e( 'Eligible users', 'lovecatz-wc' ); ?></span>
						<label class="lwc-promo-all-users-option" for="lwc_promo_all_users"><input type="checkbox" name="eligible_all_users" id="lwc_promo_all_users" value="yes" <?php checked( $values['all_users'] ); ?> aria-controls="lwc_promo_selected_users" /> <span><?php esc_html_e( 'All users', 'lovecatz-wc' ); ?></span></label>
						<div class="lwc-promo-selected-users" id="lwc_promo_selected_users" <?php echo $values['all_users'] ? 'hidden' : ''; ?>>
							<?php $this->render_eligible_user_select( $values['eligible_user_ids'] ); ?>
							<small><?php esc_html_e( 'Search and select one or more users by name or email.', 'lovecatz-wc' ); ?></small>
						</div>
					</div>
					<label><span><?php esc_html_e( 'Total usage limit (all users)', 'lovecatz-wc' ); ?></span><input type="number" name="usage_limit" min="0" step="1" value="<?php echo esc_attr( $values['usage_limit'] ); ?>" /><small><?php esc_html_e( '0 or empty = unlimited. Set to 1 to let the coupon be used only once across the entire store.', 'lovecatz-wc' ); ?></small></label>
					<label><span><?php esc_html_e( 'Usage limit per user', 'lovecatz-wc' ); ?></span><input type="number" name="usage_limit_per_user" min="0" step="1" value="<?php echo esc_attr( $values['usage_limit_per_user'] ); ?>" /><small><?php esc_html_e( '0 or empty = unlimited. Set to 1 so every user can use this global coupon once.', 'lovecatz-wc' ); ?></small></label>
					<label class="lwc-promo-image-field"><span><?php esc_html_e( 'Active card image', 'lovecatz-wc' ); ?></span><input type="hidden" id="lwc_promo_active_image_id" name="active_image_id" value="<?php echo esc_attr( $values['active_image_id'] ); ?>" /><button type="button" class="button lwc-promo-image-select" data-target="#lwc_promo_active_image_id"><?php esc_html_e( 'Choose image', 'lovecatz-wc' ); ?></button><?php $this->render_image_preview( $values['active_image_id'] ); ?></label>
					<label class="lwc-promo-image-field"><span><?php esc_html_e( 'Disabled card image', 'lovecatz-wc' ); ?></span><input type="hidden" id="lwc_promo_disabled_image_id" name="disabled_image_id" value="<?php echo esc_attr( $values['disabled_image_id'] ); ?>" /><button type="button" class="button lwc-promo-image-select" data-target="#lwc_promo_disabled_image_id"><?php esc_html_e( 'Choose image', 'lovecatz-wc' ); ?></button><?php $this->render_image_preview( $values['disabled_image_id'] ); ?><small><?php esc_html_e( 'Shown for expired or fully used coupons.', 'lovecatz-wc' ); ?></small></label>
				</div>
				<p><label><input type="checkbox" name="coupon_active" value="yes" <?php checked( $values['active'] ); ?> /> <?php esc_html_e( 'Active coupon', 'lovecatz-wc' ); ?></label><br /><small><?php esc_html_e( 'Inactive coupons remain saved in the admin list but cannot be applied and are hidden from checkout.', 'lovecatz-wc' ); ?></small></p>
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
		$id      = isset( $_POST['coupon_id'] ) ? absint( $_POST['coupon_id'] ) : 0;
		$editing = $id > 0;

		if ( $editing && ! $this->is_valid_coupon_post( $id ) ) {
			$this->redirect_with_notice( 'invalid_coupon', $id );
		}

		$coupon = $editing ? new WC_Coupon( $id ) : new WC_Coupon();
		$code   = isset( $_POST['coupon_code'] ) ? wc_format_coupon_code( wp_unslash( $_POST['coupon_code'] ) ) : '';
		if ( '' === $code ) {
			$this->redirect_with_notice( 'code_required', $id );
		}
		if ( $this->coupon_code_exists( $code, $id ) ) {
			$this->redirect_with_notice( 'duplicate_code', $id );
		}

		$requested_type = isset( $_POST['discount_type'] ) ? sanitize_key( wp_unslash( $_POST['discount_type'] ) ) : 'percent';
		$type           = in_array( $requested_type, array( 'percent', 'fixed_cart', 'lwc_free_shipping' ), true ) ? $requested_type : 'percent';
		$amount         = 'lwc_free_shipping' === $type ? 0 : (float) wc_format_decimal( wp_unslash( 'fixed_cart' === $type ? ( $_POST['fixed_amount'] ?? 0 ) : ( $_POST['percentage_amount'] ?? 0 ) ) );

		if ( 'percent' === $type && ( $amount <= 0 || $amount > 100 ) ) {
			$this->redirect_with_notice( 'invalid_percentage', $id );
		}
		if ( 'fixed_cart' === $type && $amount <= 0 ) {
			$this->redirect_with_notice( 'invalid_amount', $id );
		}

		$all_users         = isset( $_POST['eligible_all_users'] );
		$submitted_user_ids = ! $all_users && isset( $_POST['eligible_user_ids'] ) && is_array( $_POST['eligible_user_ids'] ) ? $this->normalize_user_ids( wp_unslash( $_POST['eligible_user_ids'] ) ) : array();
		$user_ids           = array();
		$emails             = array();
		foreach ( $submitted_user_ids as $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$user_ids[] = (int) $user->ID;
				$emails[]   = $user->user_email;
			}
		}

		if ( ! $all_users && empty( $user_ids ) ) {
			$this->redirect_with_notice( 'eligible_users_required', $id );
		}

		$active         = isset( $_POST['coupon_active'] );
		$usage_limit    = isset( $_POST['usage_limit'] ) ? max( 0, (int) wp_unslash( $_POST['usage_limit'] ) ) : 0;
		$per_user_limit = isset( $_POST['usage_limit_per_user'] ) ? max( 0, (int) wp_unslash( $_POST['usage_limit_per_user'] ) ) : 0;
		$maximum        = isset( $_POST['maximum_discount'] ) ? max( 0, (int) wp_unslash( $_POST['maximum_discount'] ) ) : 0;

		try {
			$coupon->set_code( $code );
			$coupon->set_discount_type( $type );
			$coupon->set_amount( $amount );
			$coupon->set_status( $active ? 'publish' : 'draft' );
			$coupon->set_free_shipping( false );
			$coupon->set_individual_use( isset( $_POST['individual_use'] ) );
			$coupon->set_usage_limit( $usage_limit );
			$coupon->set_usage_limit_per_user( $per_user_limit );
			$coupon->set_email_restrictions( $emails );
			$coupon->set_date_expires( ! empty( $_POST['expiry_date'] ) ? sanitize_text_field( wp_unslash( $_POST['expiry_date'] ) ) : null );
			$coupon->save();
			$id = $coupon->get_id();

			update_post_meta( $id, '_lwc_promo_created', '1' );
			update_post_meta( $id, '_lwc_promo_eligible_user_ids', $user_ids );
			update_post_meta( $id, '_lwc_promo_maximum_discount', in_array( $type, array( 'percent', 'lwc_free_shipping' ), true ) && $maximum > 0 ? $maximum : '' );
			update_post_meta( $id, '_lwc_promo_active_image_id', isset( $_POST['active_image_id'] ) ? absint( $_POST['active_image_id'] ) : 0 );
			update_post_meta( $id, '_lwc_promo_disabled_image_id', isset( $_POST['disabled_image_id'] ) ? absint( $_POST['disabled_image_id'] ) : 0 );
		} catch ( Throwable $error ) {
			if ( class_exists( 'LWC_Logger' ) ) {
				LWC_Logger::log( 'Unable to save promo coupon: ' . $error->getMessage(), 'error' );
			}
			$this->redirect_with_notice( 'save_failed', $id );
		}

		$this->redirect_with_notice( $active ? ( $editing ? 'updated' : 'created' ) : 'saved_inactive', $id );
	}

	/** Delete a promo coupon. */
	public function delete_coupon() {
		$this->assert_permission( 'lwc_delete_promo_coupon' );
		$id = isset( $_GET['coupon_id'] ) ? absint( $_GET['coupon_id'] ) : 0;
		if ( ! $id || ! $this->is_valid_coupon_post( $id ) ) {
			$this->redirect_with_notice( 'delete_failed' );
		}

		$result = wp_trash_post( $id );
		$this->redirect_with_notice( $result ? 'deleted' : 'delete_failed' );
	}

	/** Render a whitelisted promo result notice after a form redirect. */
	private function render_notice() {
		$notice_code = isset( $_GET['lwc_promo_notice'] ) ? sanitize_key( wp_unslash( $_GET['lwc_promo_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notices     = array(
			'created'            => array( 'success', __( 'Coupon created successfully.', 'lovecatz-wc' ) ),
			'updated'            => array( 'success', __( 'Coupon updated successfully.', 'lovecatz-wc' ) ),
			'deleted'            => array( 'success', __( 'Coupon moved to Trash.', 'lovecatz-wc' ) ),
			'saved_inactive'     => array( 'warning', __( 'Coupon saved as inactive. It remains available in the admin list but is hidden from customers and checkout.', 'lovecatz-wc' ) ),
			'invalid_coupon'     => array( 'error', __( 'The requested promo coupon is invalid or is not managed by this plugin.', 'lovecatz-wc' ) ),
			'code_required'      => array( 'error', __( 'Coupon code is required. The coupon was not saved.', 'lovecatz-wc' ) ),
			'duplicate_code'     => array( 'error', __( 'That coupon code already exists. Use a unique code.', 'lovecatz-wc' ) ),
			'invalid_percentage' => array( 'error', __( 'Percentage discount must be greater than 0 and no more than 100.', 'lovecatz-wc' ) ),
			'invalid_amount'     => array( 'error', __( 'Fixed discount amount must be greater than 0.', 'lovecatz-wc' ) ),
			'eligible_users_required' => array( 'error', __( 'Select at least one eligible user, or enable All users.', 'lovecatz-wc' ) ),
			'save_failed'        => array( 'error', __( 'Coupon could not be saved. Check the values and try again.', 'lovecatz-wc' ) ),
			'delete_failed'      => array( 'error', __( 'Coupon could not be moved to Trash.', 'lovecatz-wc' ) ),
		);

		if ( ! isset( $notices[ $notice_code ] ) ) {
			return;
		}

		list( $type, $message ) = $notices[ $notice_code ];
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/** Redirect back to Promo with a safe result code. */
	private function redirect_with_notice( $notice_code, $coupon_id = 0 ) {
		$args = array(
			'page'             => 'lovecatz-wc',
			'tab'              => 'promo',
			'lwc_promo_notice' => sanitize_key( $notice_code ),
		);
		if ( $coupon_id && $this->is_valid_coupon_post( $coupon_id ) ) {
			$args['coupon_id'] = absint( $coupon_id );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function assert_permission( $action ) { if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You do not have permission to manage promo coupons.', 'lovecatz-wc' ) ); } check_admin_referer( $action, 'lwc_promo_nonce' ); }

	/** Check published and inactive coupons so an inactive duplicate cannot be created. */
	private function coupon_code_exists( $code, $exclude_id = 0 ) {
		$matches = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'title'          => $code,
				'exclude'        => $exclude_id ? array( absint( $exclude_id ) ) : array(),
			)
		);

		return ! empty( $matches );
	}

	private function is_valid_coupon_post( $coupon_id ) { if ( ! $coupon_id ) { return false; } $post = get_post( $coupon_id ); return $post instanceof WP_Post && 'shop_coupon' === $post->post_type && '1' === get_post_meta( $coupon_id, '_lwc_promo_created', true ); }
	private function get_requested_coupon() { $id = isset( $_GET['coupon_id'] ) ? absint( $_GET['coupon_id'] ) : 0; return $id && $this->is_valid_coupon_post( $id ) ? new WC_Coupon( $id ) : null; }
	private function get_form_values( $coupon ) {
		$id                = $coupon ? $coupon->get_id() : 0;
		$type              = $coupon ? $coupon->get_discount_type() : 'percent';
		$amount            = $coupon ? $coupon->get_amount() : '';
		$eligible_user_ids = $id ? $this->normalize_user_ids( get_post_meta( $id, '_lwc_promo_eligible_user_ids', true ) ) : array();

		return array(
			'id'                  => $id,
			'code'                => $coupon ? $coupon->get_code() : '',
			'type'                => $type,
			'percentage_amount'   => 'percent' === $type ? $amount : '',
			'fixed_amount'        => 'fixed_cart' === $type ? $amount : '',
			'maximum_discount'    => $id ? get_post_meta( $id, '_lwc_promo_maximum_discount', true ) : '',
			'expiry_date'         => $coupon && $coupon->get_date_expires() ? $coupon->get_date_expires()->date( 'Y-m-d' ) : '',
			'eligible_user_ids'   => $eligible_user_ids,
			'all_users'           => empty( $eligible_user_ids ),
			'usage_limit'         => $coupon ? $coupon->get_usage_limit() : 0,
			'usage_limit_per_user' => $coupon ? $coupon->get_usage_limit_per_user() : 0,
			'active'              => ! $coupon || 'publish' === $coupon->get_status(),
			'individual_use'      => $coupon && $coupon->get_individual_use() ? 'yes' : 'no',
			'active_image_id'     => $id ? absint( get_post_meta( $id, '_lwc_promo_active_image_id', true ) ) : 0,
			'disabled_image_id'   => $id ? absint( get_post_meta( $id, '_lwc_promo_disabled_image_id', true ) ) : 0,
		);
	}

	/** Convert submitted eligibility values to real, positive WordPress user IDs. */
	private function normalize_user_ids( $values ) {
		$user_ids = array();
		foreach ( (array) $values as $value ) {
			$user_id = (int) $value;
			if ( $user_id > 0 ) {
				$user_ids[] = $user_id;
			}
		}

		return array_values( array_unique( $user_ids ) );
	}

	/** Render WooCommerce's AJAX-powered customer multi-select. */
	private function render_eligible_user_select( $selected ) {
		$selected = $this->normalize_user_ids( $selected );

		echo '<select name="eligible_user_ids[]" id="lwc_promo_eligible_user_ids" class="wc-customer-search lwc-promo-user-select" multiple="multiple" style="width:100%" data-placeholder="' . esc_attr__( 'Search by name or email…', 'lovecatz-wc' ) . '" data-allow_clear="true" data-minimum_input_length="1">';
		foreach ( $selected as $user_id ) {
			$user = get_userdata( $user_id );
			if ( ! $user ) {
				continue;
			}
			$label = sprintf( '%1$s (%2$s)', $user->display_name, $user->user_email );
			echo '<option value="' . esc_attr( $user->ID ) . '" selected="selected">' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}
	/** List active and inactive managed coupons in the plugin dashboard. */
	private function render_coupon_list() {
		$posts = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => array( 'publish', 'draft' ),
				'numberposts'    => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_key'       => '_lwc_promo_created',
				'meta_value'     => '1',
			)
		);

		if ( ! $posts ) {
			echo '<p>' . esc_html__( 'No promo coupons have been created.', 'lovecatz-wc' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Code', 'lovecatz-wc' ) . '</th><th>' . esc_html__( 'Status', 'lovecatz-wc' ) . '</th><th>' . esc_html__( 'Discount', 'lovecatz-wc' ) . '</th><th>' . esc_html__( 'Expiry', 'lovecatz-wc' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $posts as $post ) {
			$coupon   = new WC_Coupon( $post->ID );
			$active   = 'publish' === $coupon->get_status();
			$discount = $this->format_coupon_discount( $coupon );
			$edit     = add_query_arg( array( 'page' => 'lovecatz-wc', 'tab' => 'promo', 'coupon_id' => $post->ID ), admin_url( 'admin.php' ) );
			$delete   = wp_nonce_url( add_query_arg( array( 'action' => 'lwc_delete_promo_coupon', 'coupon_id' => $post->ID ), admin_url( 'admin-post.php' ) ), 'lwc_delete_promo_coupon', 'lwc_promo_nonce' );
			echo '<tr><td><strong>' . esc_html( $coupon->get_code() ) . '</strong></td><td>' . esc_html( $active ? __( 'Active', 'lovecatz-wc' ) : __( 'Inactive', 'lovecatz-wc' ) ) . '</td><td>' . esc_html( $discount ) . '</td><td>' . esc_html( $coupon->get_date_expires() ? $coupon->get_date_expires()->date_i18n( get_option( 'date_format' ) ) : '—' ) . '</td><td><a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'lovecatz-wc' ) . '</a> · <a class="submitdelete" href="' . esc_url( $delete ) . '">' . esc_html__( 'Trash', 'lovecatz-wc' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
	}
	private function format_coupon_discount( $coupon ) { $maximum = (float) get_post_meta( $coupon->get_id(), '_lwc_promo_maximum_discount', true ); if ( 'lwc_free_shipping' === $coupon->get_discount_type() ) { return $maximum > 0 ? sprintf( __( 'Free shipping up to %s', 'lovecatz-wc' ), wp_strip_all_tags( wc_price( $maximum ) ) ) : __( 'Free shipping', 'lovecatz-wc' ); } $discount = $coupon->get_amount() . ( 'percent' === $coupon->get_discount_type() ? '%' : '' ); if ( 'percent' === $coupon->get_discount_type() && $maximum > 0 ) { $discount .= ' · ' . sprintf( __( 'Maximum %s', 'lovecatz-wc' ), wp_strip_all_tags( wc_price( $maximum ) ) ); } return $discount; }
	private function render_image_preview( $image_id ) { $url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : ''; echo '<span class="lwc-promo-image-preview">' . ( $url ? '<img src="' . esc_url( $url ) . '" alt="" />' : '' ) . '</span>'; }
}
