<?php
/** Safely replace an order's shipping method after the previous shipment is cancelled. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_Order_Shipping_Switcher {
	const NONCE_ACTION = 'lwc_order_shipping_switch';

	public function init() {
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ), 20, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_lwc_get_order_shipping_options', array( $this, 'ajax_get_options' ) );
		add_action( 'wp_ajax_lwc_change_order_shipping', array( $this, 'ajax_change_shipping' ) );
	}

	public function register_metabox( $post_type = '', $post_or_order = null ) {
		$order = $this->resolve_order( $post_or_order );
		if ( ! $this->get_managed_provider( $order ) ) {
			return;
		}
		add_meta_box(
			'lwc-change-order-shipping',
			__( 'Change Shipping', 'lovecatz-wc' ),
			array( $this, 'render_metabox' ),
			array( 'shop_order', 'woocommerce_page_wc-orders' ),
			'side',
			'default'
		);
	}

	public function render_metabox( $post_or_order ) {
		$order = $this->resolve_order( $post_or_order );
		if ( ! $order ) {
			return;
		}
		$provider = $this->get_managed_provider( $order );
		$active   = $this->has_active_fulfillment( $order, $provider );
		$cancelled = $this->is_fulfillment_cancelled( $order, $provider );
		$requires_confirmation = $active && ! $cancelled && 'jt' !== $provider;
		$blocked = $active && ! $cancelled && 'jt' === $provider;
		$current = array();
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$current[] = $item->get_method_title();
		}
		?>
		<div class="lwc-order-shipping-switcher" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
			<p><strong><?php esc_html_e( 'Current:', 'lovecatz-wc' ); ?></strong> <?php echo esc_html( implode( ', ', array_filter( $current ) ) ); ?></p>
			<?php if ( $blocked ) : ?>
				<p class="notice notice-warning inline"><span><?php esc_html_e( 'Cancel the active J&T shipment first. A J&T tracking event with status 162 or 163 is also accepted as cancellation proof.', 'lovecatz-wc' ); ?></span></p>
			<?php else : ?>
				<?php if ( $requires_confirmation ) : ?>
					<label class="lwc-shipping-cancel-confirm"><input type="checkbox" value="1"> <?php esc_html_e( 'I confirm the existing shipment/AWB was cancelled with the carrier.', 'lovecatz-wc' ); ?></label>
					<p class="description"><?php esc_html_e( 'Cancelling a pickup alone does not cancel a shipment. This confirmation is required because this carrier has no shipment-cancellation API in the plugin.', 'lovecatz-wc' ); ?></p>
				<?php endif; ?>
				<p><button type="button" class="button lwc-load-shipping-options"><?php esc_html_e( 'Load available shipping', 'lovecatz-wc' ); ?></button></p>
				<div class="lwc-shipping-options" hidden>
					<select class="widefat lwc-new-shipping-rate" aria-label="<?php esc_attr_e( 'New shipping method', 'lovecatz-wc' ); ?>"></select>
					<p><button type="button" class="button button-primary lwc-change-shipping"><?php esc_html_e( 'Change shipping', 'lovecatz-wc' ); ?></button></p>
				</div>
			<?php endif; ?>
			<div class="lwc-shipping-switch-status" aria-live="polite"></div>
		</div>
		<?php
	}

	public function enqueue_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) || ! $this->get_managed_provider( $this->resolve_order() ) ) {
			return;
		}
		wp_enqueue_style( 'lwc-order-shipping-switcher', LWC_PLUGIN_URL . 'shipping/order-shipping-switcher.css', array(), LWC_VERSION );
		wp_enqueue_script( 'lwc-order-shipping-switcher', LWC_PLUGIN_URL . 'shipping/order-shipping-switcher.js', array( 'jquery' ), LWC_VERSION, true );
		wp_localize_script(
			'lwc-order-shipping-switcher',
			'lwcOrderShippingSwitcher',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => array(
					'loading' => __( 'Loading live shipping options…', 'lovecatz-wc' ),
					'changing' => __( 'Changing shipping…', 'lovecatz-wc' ),
					'confirm' => __( 'Replace the current order shipping method and cost?', 'lovecatz-wc' ),
					'error' => __( 'The shipping method could not be changed.', 'lovecatz-wc' ),
				),
			)
		);
	}

	public function ajax_get_options() {
		$order = $this->get_ajax_order();
		// Loading rates is read-only. A non-J&T external cancellation
		// confirmation is enforced only when the replacement is submitted.
		$allowed = $this->can_change_shipping( $order, true );
		if ( is_wp_error( $allowed ) ) {
			wp_send_json_error( array( 'message' => $allowed->get_error_message() ) );
		}
		$rates = $this->calculate_rates( $order );
		if ( is_wp_error( $rates ) ) {
			wp_send_json_error( array( 'message' => $rates->get_error_message() ) );
		}
		$options = array();
		foreach ( $rates as $rate ) {
			$options[] = array(
				'id'       => $rate->get_id(),
				'label'    => $rate->get_label(),
				'cost'     => (float) $rate->get_cost(),
				'formatted'=> html_entity_decode( wp_strip_all_tags( wc_price( (float) $rate->get_cost(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
			);
		}
		if ( ! $options ) {
			wp_send_json_error( array( 'message' => __( 'No managed or JNE shipping service is currently available for this order address.', 'lovecatz-wc' ) ) );
		}
		wp_send_json_success( array( 'options' => $options ) );
	}

	public function ajax_change_shipping() {
		$order = $this->get_ajax_order();
		$confirmed = isset( $_POST['cancel_confirmed'] ) && '1' === (string) wp_unslash( $_POST['cancel_confirmed'] );
		$allowed = $this->can_change_shipping( $order, $confirmed );
		if ( is_wp_error( $allowed ) ) {
			wp_send_json_error( array( 'message' => $allowed->get_error_message() ) );
		}

		$selected = isset( $_POST['rate_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rate_id'] ) ) : '';
		$rates = $this->calculate_rates( $order );
		if ( is_wp_error( $rates ) ) {
			wp_send_json_error( array( 'message' => $rates->get_error_message() ) );
		}
		$rate = isset( $rates[ $selected ] ) ? $rates[ $selected ] : null;
		if ( ! $rate instanceof WC_Shipping_Rate ) {
			wp_send_json_error( array( 'message' => __( 'The selected rate is no longer available. Load the shipping options again.', 'lovecatz-wc' ) ) );
		}

		$old_provider = $this->get_managed_provider( $order );
		$old_titles = array();
		$old_items  = array();
		foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
			$old_titles[] = $item->get_method_title();
			$old_items[] = array(
				'method_id' => $item->get_method_id(),
				'title'     => $item->get_method_title(),
				'total'     => $item->get_total(),
				'meta'      => $this->item_meta_array( $item ),
			);
			$order->remove_item( $item_id );
		}

		$history = $order->get_meta( '_lwc_shipping_change_history' );
		$history = is_array( $history ) ? $history : array();
		$history[] = array(
			'changed_at'           => current_time( 'mysql' ),
			'changed_by'           => get_current_user_id(),
			'cancellation_source'  => $confirmed ? 'admin_confirmation' : ( $this->has_active_fulfillment( $order, $old_provider ) ? 'carrier_api_or_tracking' : 'no_awb' ),
			'old_shipping'         => $old_items,
			'old_fulfillment'      => $this->fulfillment_snapshot( $order, $old_provider ),
		);
		$order->update_meta_data( '_lwc_shipping_change_history', array_slice( $history, -20 ) );
		$order->update_meta_data( '_lwc_shipping_change_count', absint( $order->get_meta( '_lwc_shipping_change_count' ) ) + 1 );
		$this->clear_active_fulfillment( $order, $old_provider );

		$item = new WC_Order_Item_Shipping();
		$item->set_method_title( $rate->get_label() );
		$item->set_method_id( $rate->get_method_id() );
		$item->set_instance_id( $rate->get_instance_id() );
		$item->set_total( wc_format_decimal( $rate->get_cost() ) );
		$item->set_taxes( array( 'total' => (array) $rate->get_taxes() ) );
		foreach ( (array) $rate->get_meta_data() as $key => $value ) {
			$item->add_meta_data( $key, $value, true );
		}
		$order->add_item( $item );
		$order->calculate_totals( false );
		$order->save();
		$order->add_order_note(
			sprintf(
				/* translators: 1: old shipping title, 2: new shipping title, 3: new shipping cost */
				__( 'Shipping changed from %1$s to %2$s (%3$s) after cancellation confirmation. Create the new shipment/AWB manually.', 'lovecatz-wc' ),
				implode( ', ', array_filter( $old_titles ) ),
				$rate->get_label(),
				html_entity_decode( wp_strip_all_tags( wc_price( $rate->get_cost(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, get_bloginfo( 'charset' ) )
			)
		);

		wp_send_json_success( array( 'message' => __( 'Shipping was changed successfully. Reloading the order…', 'lovecatz-wc' ) ) );
	}

	private function calculate_rates( $order ) {
		$package = $this->build_order_package( $order );
		$rates = array();
		try {
			$zone = WC_Shipping_Zones::get_zone_matching_package( $package );
			foreach ( $zone->get_shipping_methods( true ) as $method ) {
				if ( $method instanceof WC_Shipping_Method && $method->is_available( $package ) ) {
					$rates = $rates + $method->get_rates_for_package( $package );
				}
			}
			$rates = apply_filters( 'woocommerce_package_rates', $rates, $package );
		} catch ( Throwable $error ) {
			return new WP_Error( 'lwc_shipping_rate_error', $error->getMessage() );
		}

		$allowed_methods = array( 'lwc_jt_express', 'lwc_jt', 'lwc_jt_cargo', 'lwc_fedex', 'lwc_shipping_fedex', 'lwc_rayspeed', 'jneshof_shipping' );
		foreach ( (array) $rates as $rate_id => $rate ) {
			if ( ! $rate instanceof WC_Shipping_Rate || ! in_array( $rate->get_method_id(), $allowed_methods, true ) ) {
				unset( $rates[ $rate_id ] );
			}
		}
		return $rates;
	}

	private function build_order_package( $order ) {
		$contents = array();
		$contents_cost = 0.0;
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$contents[ $item_id ] = array(
				'key'       => (string) $item_id,
				'data'      => $product,
				'quantity'  => max( 1, (int) $item->get_quantity() ),
				'line_total'=> (float) $item->get_total(),
			);
			$contents_cost += (float) $item->get_total();
		}
		$use_shipping = '' !== trim( (string) $order->get_shipping_country() );
		$prefix = $use_shipping ? 'shipping' : 'billing';
		$district = (string) $order->get_meta( "_lwc_{$prefix}_district_name", true );
		if ( '' === $district ) {
			$district = (string) $order->get_meta( "_wc_{$prefix}/lwc/indonesia-district", true );
		}
		return array(
			'contents'        => $contents,
			'contents_cost'   => $contents_cost,
			'applied_coupons' => $order->get_coupon_codes(),
			'user'            => array( 'ID' => $order->get_customer_id() ),
			'destination'     => array(
				'country'   => $use_shipping ? $order->get_shipping_country() : $order->get_billing_country(),
				'state'     => $use_shipping ? $order->get_shipping_state() : $order->get_billing_state(),
				'postcode'  => $use_shipping ? $order->get_shipping_postcode() : $order->get_billing_postcode(),
				'city'      => $use_shipping ? $order->get_shipping_city() : $order->get_billing_city(),
				'address'   => $use_shipping ? $order->get_shipping_address_1() : $order->get_billing_address_1(),
				'address_1' => $use_shipping ? $order->get_shipping_address_1() : $order->get_billing_address_1(),
				'address_2' => $use_shipping ? $order->get_shipping_address_2() : $order->get_billing_address_2(),
				'lwc_indonesia_district' => $district,
			),
			'cart_subtotal' => (float) $order->get_subtotal(),
		);
	}

	private function can_change_shipping( $order, $confirmed ) {
		$provider = $this->get_managed_provider( $order );
		if ( ! $provider ) {
			return new WP_Error( 'lwc_shipping_unmanaged', __( 'This order does not use a shipping method managed by this plugin.', 'lovecatz-wc' ) );
		}
		if ( ! $this->has_active_fulfillment( $order, $provider ) || $this->is_fulfillment_cancelled( $order, $provider ) ) {
			return true;
		}
		if ( 'jt' === $provider ) {
			return new WP_Error( 'lwc_shipping_not_cancelled', __( 'J&T still has an active shipment. Cancel it first or refresh tracking until status 162/163 is received.', 'lovecatz-wc' ) );
		}
		return $confirmed ? true : new WP_Error( 'lwc_shipping_confirmation_required', __( 'Confirm that the existing shipment/AWB was cancelled with the carrier before changing shipping.', 'lovecatz-wc' ) );
	}

	private function get_managed_provider( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$id = (string) $item->get_method_id();
			if ( in_array( $id, array( 'lwc_jt_express', 'lwc_jt' ), true ) ) { return 'jt'; }
			if ( 'lwc_jt_cargo' === $id ) { return 'jt_cargo'; }
			if ( in_array( $id, array( 'lwc_fedex', 'lwc_shipping_fedex' ), true ) ) { return 'fedex'; }
			if ( 'lwc_rayspeed' === $id ) { return 'rayspeed'; }
		}
		return '';
	}

	private function has_active_fulfillment( $order, $provider ) {
		if ( 'jt' === $provider ) { return '' !== (string) $order->get_meta( '_lwc_jt_awb' ); }
		if ( 'rayspeed' === $provider ) { return '' !== (string) $order->get_meta( '_lwc_rayspeed_awb' ); }
		if ( 'jt_cargo' === $provider ) { return '' !== (string) $order->get_meta( '_lwc_jt_cargo_awb' ) || '' !== (string) $order->get_meta( '_lwc_jt_cargo_tracking_number' ); }
		if ( 'fedex' === $provider ) {
			return '' !== (string) $order->get_meta( '_lwc_fedex_tracking_number' ) || ! empty( $order->get_meta( '_lwc_fedex_shipments' ) );
		}
		return false;
	}

	private function is_fulfillment_cancelled( $order, $provider ) {
		if ( 'jt' !== $provider ) {
			return false;
		}
		if ( $order->get_meta( '_lwc_jt_cancelled' ) ) {
			return true;
		}
		$tracking = $order->get_meta( '_lwc_jt_tracking' );
		return class_exists( 'LWC_JT_Express_API' ) && is_array( $tracking ) && LWC_JT_Express_API::tracking_confirms_cancellation( isset( $tracking['history'] ) ? $tracking['history'] : array() );
	}

	private function fulfillment_snapshot( $order, $provider ) {
		$keys = array(
			'jt' => array( '_lwc_jt_awb', '_lwc_jt_order_id', '_lwc_jt_etd', '_lwc_jt_tracking', '_lwc_jt_cancelled', '_lwc_jt_cancel_error', '_lwc_jt_declared_weight_kg' ),
			'fedex' => array( '_lwc_fedex_tracking_number', '_lwc_fedex_shipments', '_lwc_fedex_tracking_data', '_lwc_fedex_pickup', '_lwc_fedex_label_path' ),
			'rayspeed' => array( '_lwc_rayspeed_awb', '_lwc_rayspeed_tracking' ),
			'jt_cargo' => array( '_lwc_jt_cargo_awb', '_lwc_jt_cargo_tracking_number' ),
		);
		$snapshot = array();
		foreach ( isset( $keys[ $provider ] ) ? $keys[ $provider ] : array() as $key ) {
			$value = $order->get_meta( $key );
			if ( '' !== $value && null !== $value && array() !== $value ) {
				$snapshot[ $key ] = $value;
			}
		}
		return $snapshot;
	}

	private function clear_active_fulfillment( $order, $provider ) {
		$keys = array(
			'jt' => array( '_lwc_jt_awb', '_lwc_jt_order_id', '_lwc_jt_etd', '_lwc_jt_tracking', '_lwc_jt_tracking_error', '_lwc_jt_cancel_error', '_lwc_jt_cancelled', '_lwc_jt_create_error', '_lwc_jt_declared_weight_kg' ),
			'fedex' => array( '_lwc_fedex_tracking_number', '_lwc_fedex_shipments', '_lwc_fedex_tracking_data', '_lwc_fedex_tracking_updated_at', '_lwc_fedex_pickup', '_lwc_fedex_pickup_availability', '_lwc_fedex_label_path' ),
			'rayspeed' => array( '_lwc_rayspeed_awb', '_lwc_rayspeed_tracking' ),
			'jt_cargo' => array( '_lwc_jt_cargo_awb', '_lwc_jt_cargo_tracking_number' ),
		);
		foreach ( isset( $keys[ $provider ] ) ? $keys[ $provider ] : array() as $key ) {
			$order->delete_meta_data( $key );
		}
	}

	private function item_meta_array( $item ) {
		$values = array();
		foreach ( $item->get_meta_data() as $meta ) {
			$values[ $meta->key ] = $meta->value;
		}
		return $values;
	}

	private function get_ajax_order() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'lovecatz-wc' ) ), 403 );
		}
		$order = wc_get_order( isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0 );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'lovecatz-wc' ) ), 404 );
		}
		return $order;
	}

	private function resolve_order( $post_or_order = null ) {
		if ( $post_or_order instanceof WC_Order ) { return $post_or_order; }
		if ( $post_or_order instanceof WP_Post ) { return wc_get_order( $post_or_order->ID ); }
		$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : ( isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $id ? wc_get_order( $id ) : false;
	}
}
