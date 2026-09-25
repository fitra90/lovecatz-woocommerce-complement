<?php
/**
 * Order Number admin metabox and manual correction.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_Order_Number_Admin {

	const AJAX_ACTION = 'lwc_correct_order_number';

	/**
	 * Initialize hooks.
	 */
	public function init() {
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_correct_order_number' ) );
	}

	/**
	 * Register the metabox on the order edit screen.
	 *
	 * @param string $post_type Current post type (unused).
	 * @param mixed  $post_or_order Post or order object.
	 */
	public function register_metabox( $post_type = '', $post_or_order = null ) {
		if ( ! class_exists( 'LWC_Order_Number' ) ) {
			return;
		}

		$order = $this->resolve_admin_order( $post_or_order );
		if ( ! $order ) {
			return;
		}

		$number = LWC_Order_Number::instance();
		if ( ! $number->is_enabled() ) {
			return;
		}

		add_meta_box(
			'lwc_order_number',
			__( 'Order Number', 'lovecatz-wc' ),
			array( $this, 'render_metabox' ),
			array( 'shop_order', 'woocommerce_page_wc-orders' ),
			'side',
			'default'
		);
	}

	/**
	 * Enqueue CSS and JS only on the order edit screen.
	 */
	public function enqueue_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}

		$plugin_url = plugin_dir_url( LWC_PLUGIN_FILE );
		$suffix     = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_enqueue_style(
			'lwc-order-number-admin',
			$plugin_url . 'includes/admin/lwc-order-number-admin.css',
			array(),
			LWC_VERSION
		);

		wp_enqueue_script(
			'lwc-order-number-admin',
			$plugin_url . 'includes/admin/lwc-order-number-admin.js',
			array( 'jquery' ),
			LWC_VERSION,
			true
		);

		wp_localize_script(
			'lwc-order-number-admin',
			'lwcOrderNumberAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'ajaxNonce' => wp_create_nonce( self::AJAX_ACTION ),
				'action'    => self::AJAX_ACTION,
				'i18n'      => array(
					'saving' => __( 'Saving…', 'lovecatz-wc' ),
					'saved'  => __( 'Saved', 'lovecatz-wc' ),
					'error'  => __( 'Error', 'lovecatz-wc' ),
					'save'   => __( 'Save', 'lovecatz-wc' ),
				),
			)
		);
	}

	/**
	 * Render the metabox content.
	 *
	 * Every order can be manually corrected — the eligibility timestamp only
	 * gates *automatic* assignment, never an explicit administrator edit.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post or order object.
	 */
	public function render_metabox( $post_or_order ) {
		$order = $this->resolve_admin_order( $post_or_order );
		if ( ! $order ) {
			return;
		}

		$number = LWC_Order_Number::instance();
		if ( ! $number->is_enabled() ) {
			echo '<p>' . esc_html__( 'Custom order IDs are currently disabled.', 'lovecatz-wc' ) . '</p>';
			return;
		}

		$custom_id = (string) $order->get_meta( LWC_Order_Number::META_NUMBER );
		$scope     = (string) $order->get_meta( LWC_Order_Number::META_SCOPE );
		$order_id  = $order->get_id();

		// Show the WooCommerce internal ID for reference.
		echo '<div class="lwc-ona-row">';
		echo '<label>' . esc_html__( 'WooCommerce #', 'lovecatz-wc' ) . '</label>';
		echo '<span class="lwc-ona-mono">' . esc_html( $order_id ) . '</span>';
		echo '</div>';

		// Show the current custom order ID.
		echo '<div class="lwc-ona-row">';
		echo '<label>' . esc_html__( 'Custom ID', 'lovecatz-wc' ) . '</label>';
		if ( '' !== $custom_id ) {
			echo '<span class="lwc-ona-mono lwc-ona-custom-id">' . esc_html( $custom_id ) . '</span>';
		} else {
			echo '<span class="lwc-ona-mono lwc-ona-custom-id lwc-ona-no-id">&mdash;</span>';
		}
		echo '</div>';

		// Scope label.
		if ( '' !== $scope ) {
			echo '<div class="lwc-ona-row">';
			echo '<label>' . esc_html__( 'Scope', 'lovecatz-wc' ) . '</label>';
			echo '<span>' . esc_html( $scope ) . '</span>';
			echo '</div>';
		}

		// Guidance for orders that have no custom ID yet.
		if ( '' === $custom_id ) {
			if ( $this->is_new_order_screen( $order ) ) {
				echo '<p class="lwc-ona-notice">' . esc_html__( 'The custom ID is assigned automatically when you save this order with a shipping address — Indonesia uses the Local format, every other country uses Global.', 'lovecatz-wc' ) . '</p>';
			} else {
				echo '<p class="lwc-ona-notice">' . esc_html__( 'This order has no custom ID (it predates the feature or was never numbered). You can set one manually below.', 'lovecatz-wc' ) . '</p>';
			}

			echo '<p class="description lwc-ona-preview"><strong>' . esc_html__( 'Next Local ID:', 'lovecatz-wc' ) . '</strong> <code>' . esc_html( $number->preview( LWC_Order_Number::SCOPE_LOCAL ) ) . '</code></p>';
			echo '<p class="description lwc-ona-preview"><strong>' . esc_html__( 'Next Global ID:', 'lovecatz-wc' ) . '</strong> <code>' . esc_html( $number->preview( LWC_Order_Number::SCOPE_GLOBAL ) ) . '</code></p>';
		}

		// Correction field — available on every order.
		echo '<div class="lwc-ona-row lwc-ona-correction-row">';
		echo '<label for="lwc_ona_custom_id">' . esc_html__( 'Corrected ID', 'lovecatz-wc' ) . '</label>';
		echo '<input type="text" id="lwc_ona_custom_id" class="widefat" value="' . esc_attr( $custom_id ) . '" maxlength="60" autocomplete="off" />';
		if ( '' === $custom_id ) {
			echo '<p class="description">' . esc_html__( 'Leave empty to assign automatically on save.', 'lovecatz-wc' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Leave empty to keep the current ID. A duplicate of another order is rejected.', 'lovecatz-wc' ) . '</p>';
		}
		echo '</div>';

		// Save button.
		echo '<div class="lwc-ona-actions">';
		echo '<button type="button" id="lwc_ona_save" class="button button-primary button-small" data-order-id="' . esc_attr( $order_id ) . '">';
		echo esc_html__( 'Save', 'lovecatz-wc' );
		echo '</button>';
		echo '<span id="lwc_ona_status" class="lwc-ona-status"></span>';
		echo '</div>';
	}

	/**
	 * Whether this is the fresh "Add new order" draft rather than a real order.
	 *
	 * @param WC_Order $order Order being considered.
	 * @return bool
	 */
	private function is_new_order_screen( $order ) {
		return in_array( $order->get_status(), array( 'auto-draft', 'draft', 'new' ), true );
	}

	/**
	 * AJAX handler for manual order number correction.
	 *
	 * Expects:
	 *   - order_id   (int)
	 *   - custom_id  (string, optional — empty keeps the current ID)
	 *
	 * Manual correction is an explicit administrator action, so it is allowed
	 * on every order — including orders that predate the feature, which the
	 * eligibility timestamp only guards against *automatic* assignment. The
	 * only hard rejection is a duplicate of another order's ID.
	 *
	 * Responds with:
	 *   - success: bool
	 *   - custom_id: string (the ID now stored, or empty)
	 *   - error: string (on failure)
	 */
	public function ajax_correct_order_number() {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'error' => __( 'Permission denied.', 'lovecatz-wc' ) ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
		if ( $order_id <= 0 ) {
			wp_send_json_error( array( 'error' => __( 'Invalid order ID.', 'lovecatz-wc' ) ) );
		}

		$raw_id    = isset( $_POST['custom_id'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_id'] ) ) : '';
		$custom_id = trim( (string) $raw_id );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'error' => __( 'Order not found.', 'lovecatz-wc' ) ) );
		}

		$result = $this->correct_order_number( $order, $custom_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'error' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'custom_id' => $result ) );
	}

	/**
	 * Validate and persist an administrator's manual correction.
	 *
	 * Kept separate from the AJAX transport so the write path can be verified
	 * transactionally without terminating the PHP process through
	 * `wp_send_json_*()`.
	 *
	 * @param WC_Order $order     Order being corrected.
	 * @param string   $custom_id Requested custom ID. Empty keeps the current ID.
	 * @return string|WP_Error Saved/current ID, or a validation error.
	 */
	public function correct_order_number( $order, $custom_id ) {
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'lwc_invalid_order', __( 'Order not found.', 'lovecatz-wc' ) );
		}

		$custom_id = trim( sanitize_text_field( (string) $custom_id ) );
		$current_id = (string) $order->get_meta( LWC_Order_Number::META_NUMBER );

		// Empty or unchanged submissions are intentional no-ops. In particular,
		// a blank field must never silently clear an existing custom ID.
		if ( '' === $custom_id || $custom_id === $current_id ) {
			return $current_id;
		}

		$number = LWC_Order_Number::instance();
		if ( $number->order_id_exists( $custom_id, $order->get_id() ) ) {
			return new WP_Error( 'lwc_duplicate_order_number', __( 'This order number is already assigned to another order.', 'lovecatz-wc' ) );
		}

		$order->update_meta_data( LWC_Order_Number::META_NUMBER, $custom_id );
		$order->update_meta_data( LWC_Order_Number::META_SCOPE, $number->scope_for_order( $order ) );
		$order->save_meta_data();

		return $custom_id;
	}

	/**
	 * Resolve a WC_Order from any post / order object the admin screen passes.
	 *
	 * @param mixed $post_or_order Post, order, or order ID.
	 * @return WC_Order|null
	 */
	private function resolve_admin_order( $post_or_order ) {
		if ( $post_or_order instanceof WC_Order ) {
			return $post_or_order;
		}

		if ( is_object( $post_or_order ) && method_exists( $post_or_order, 'get_id' ) ) {
			$id = (int) $post_or_order->get_id();
		} elseif ( is_numeric( $post_or_order ) ) {
			$id = (int) $post_or_order;
		} else {
			return null;
		}

		$order = wc_get_order( $id );
		return $order instanceof WC_Order ? $order : null;
	}
}
