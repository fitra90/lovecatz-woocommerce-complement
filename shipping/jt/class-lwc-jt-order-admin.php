<?php
/** J&T Express order creation, label printing, tracking, cancellation, and order UI. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_JT_Order_Admin {
	public function init() {
		add_action( 'woocommerce_order_status_processing', array( $this, 'create_on_processing' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_lwc_jt_create_order', array( $this, 'ajax_create_order' ) );
		add_action( 'wp_ajax_lwc_jt_print_label', array( $this, 'ajax_print_label' ) );
		add_action( 'wp_ajax_lwc_jt_refresh_tracking', array( $this, 'ajax_refresh_tracking' ) );
		add_action( 'wp_ajax_lwc_jt_cancel_order', array( $this, 'ajax_cancel_order' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_customer_tracking' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_insurance' ), 10 );
	}

	public function create_on_processing( $order_id, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( $this->order_uses_jt( $order ) && ! $order->get_meta( '_lwc_jt_awb' ) ) {
			$this->create_shipment( $order );
		}
	}

	public function register_metabox( $post_type = '', $post_or_order = null ) {
		$order = $this->resolve_admin_order( $post_or_order );
		if ( ! $this->order_uses_jt( $order ) ) {
			return;
		}
		add_meta_box( 'lwc-jt-shipping', __( 'J&T Express Shipping', 'lovecatz-wc' ), array( $this, 'render_metabox' ), array( 'shop_order', 'woocommerce_page_wc-orders' ), 'side', 'default' );
	}

	public function enqueue_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) || ! $this->order_uses_jt( $this->resolve_admin_order() ) ) {
			return;
		}
		wp_enqueue_style( 'lwc-jt-order-admin', LWC_PLUGIN_URL . 'shipping/jt/jt-order-admin.css', array(), LWC_VERSION );
		wp_enqueue_script( 'lwc-jt-order-admin', LWC_PLUGIN_URL . 'shipping/jt/jt-order-admin.js', array( 'jquery' ), LWC_VERSION, true );
		wp_localize_script(
			'lwc-jt-order-admin',
			'lwcJtOrder',
			array(
				'ajax_url'        => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'lwc_jt_order' ),
				'preparing_label' => __( 'Preparing J&T label…', 'lovecatz-wc' ),
			)
		);
	}

	public function render_metabox( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$awb      = (string) $order->get_meta( '_lwc_jt_awb' );
		$error    = (string) $order->get_meta( '_lwc_jt_create_error' );
		$tracking_error = (string) $order->get_meta( '_lwc_jt_tracking_error' );
		$cancel_error = (string) $order->get_meta( '_lwc_jt_cancel_error' );
		$tracking = (array) $order->get_meta( '_lwc_jt_tracking' );
		$env      = $this->get_order_environment( $order );
		?>
		<div class="lwc-jt-order-box" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
			<p><strong><?php esc_html_e( 'Environment:', 'lovecatz-wc' ); ?></strong> <?php echo esc_html( ucfirst( $env ) ); ?></p>
			<?php if ( '' === $awb ) : ?>
				<?php wp_nonce_field( 'lwc_jt_insurance', 'lwc_jt_insurance_nonce' ); ?>
				<p><label for="lwc-jt-insurance"><?php esc_html_e( 'Insurance value (IDR)', 'lovecatz-wc' ); ?></label><br><input type="number" min="0" step="1" id="lwc-jt-insurance" name="lwc_jt_insurance" value="<?php echo esc_attr( $order->get_meta( '_lwc_jt_insurance_value' ) ?: 0 ); ?>"></p>
				<p class="description"><?php esc_html_e( 'Use the amount agreed with J&T; enter 0 for no insurance. Save before changing the order to Processing.', 'lovecatz-wc' ); ?></p>
				<p><?php esc_html_e( 'The J&T order and AWB are created automatically when this WooCommerce order enters Processing.', 'lovecatz-wc' ); ?></p>
				<button type="button" class="button button-primary" id="lwc-jt-create-order"><?php esc_html_e( 'Create J&T Order / AWB', 'lovecatz-wc' ); ?></button>
			<?php else : ?>
				<p><strong><?php esc_html_e( 'AWB:', 'lovecatz-wc' ); ?></strong> <span id="lwc-jt-awb"><?php echo esc_html( $awb ); ?></span></p>
				<p><button type="button" class="button button-primary" id="lwc-jt-print-label"><?php esc_html_e( 'Print J&T Label', 'lovecatz-wc' ); ?></button> <a class="button" id="lwc-jt-open-label" href="#" target="_blank" rel="noopener noreferrer" hidden><?php esc_html_e( 'Open Label', 'lovecatz-wc' ); ?></a></p>
				<p><button type="button" class="button" id="lwc-jt-refresh-tracking"><?php esc_html_e( 'Refresh Tracking', 'lovecatz-wc' ); ?></button> <button type="button" class="button" id="lwc-jt-cancel-order"><?php esc_html_e( 'Cancel J&T Order', 'lovecatz-wc' ); ?></button></p>
			<?php endif; ?>
			<?php if ( $cancel_error ) : ?><p class="is-error"><?php echo esc_html( $cancel_error ); ?></p><?php endif; ?>
			<div id="lwc-jt-order-status" class="<?php echo ( $error || $tracking_error ) ? 'is-error' : ''; ?>" aria-live="polite"><?php echo esc_html( $error ? $error : $tracking_error ); ?></div>
			<div id="lwc-jt-tracking"><?php $this->render_tracking( $tracking ); ?></div>
		</div>
		<?php
	}

	public function ajax_create_order() {
		$order  = $this->get_ajax_order();
		if ( isset( $_POST['insurance'] ) && ! $order->get_meta( '_lwc_jt_awb' ) ) {
			$insurance = $this->set_insurance( $order, wp_unslash( $_POST['insurance'] ) );
			if ( is_wp_error( $insurance ) ) {
				wp_send_json_error( array( 'message' => $insurance->get_error_message() ) );
			}
		}
		$result = $this->create_shipment( $order );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'exchange' => $result->get_error_data() ) );
		}
		wp_send_json_success( array( 'message' => __( 'J&T order created successfully.', 'lovecatz-wc' ), 'awb' => $result['awb'], 'exchange' => isset( $result['exchange'] ) ? $result['exchange'] : null ) );
	}

	public function ajax_refresh_tracking() {
		$order = $this->get_ajax_order();
		$result = $this->refresh_tracking( $order );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'exchange' => $result->get_error_data() ) );
		}
		ob_start();
		$this->render_tracking( $result );
		wp_send_json_success( array( 'message' => __( 'J&T tracking refreshed.', 'lovecatz-wc' ), 'html' => ob_get_clean(), 'exchange' => isset( $result['exchange'] ) ? $result['exchange'] : null ) );
	}

	public function ajax_print_label() {
		$order = $this->get_ajax_order();
		$awb   = (string) $order->get_meta( '_lwc_jt_awb' );
		if ( '' === $awb ) {
			wp_send_json_error( array( 'message' => __( 'Create the J&T order before printing its label.', 'lovecatz-wc' ) ) );
		}

		$environment = $this->get_order_environment( $order );
		$result      = ( new LWC_JT_Express_API() )->get_print_url( $awb, LWC_JT_Account::get_credentials( 'express', $environment ) );
		if ( is_wp_error( $result ) ) {
			if ( class_exists( 'LWC_Logger' ) ) {
				LWC_Logger::log(
					sprintf( 'J&T %1$s Print rejected for WooCommerce order %2$d: %3$s', $environment, $order->get_id(), $result->get_error_message() ),
					'error'
				);
			}
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'exchange' => $result->get_error_data() ) );
		}

		wp_send_json_success(
			array(
				'message'   => __( 'J&T label is ready.', 'lovecatz-wc' ),
				'label_url' => $result['label_url'],
				'exchange'  => isset( $result['exchange'] ) ? $result['exchange'] : null,
			)
		);
	}

	public function ajax_cancel_order() {
		$order = $this->get_ajax_order();
		$result = $this->cancel_shipment( $order );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code(), 'exchange' => $result->get_error_data() ) );
		}
		wp_send_json_success( array( 'message' => __( 'J&T shipment cancelled.', 'lovecatz-wc' ), 'exchange' => isset( $result['exchange'] ) ? $result['exchange'] : null ) );
	}

	/** Persist cancellation only after the carrier accepts this exact order. */
	public function cancel_shipment( $order ) {
		if ( ! $this->order_uses_jt( $order ) || ! $order->get_meta( '_lwc_jt_awb' ) ) {
			return new WP_Error( 'lwc_jt_missing_awb', __( 'Create the J&T order before cancelling its shipment.', 'lovecatz-wc' ) );
		}
		if ( $order->get_meta( '_lwc_jt_cancelled' ) ) {
			return array( 'success' => true, 'already_cancelled' => true );
		}
		$environment = $this->get_order_environment( $order );
		$result = ( new LWC_JT_Express_API() )->cancel_order( $this->get_jt_order_id( $order ), __( 'Cancelled in WooCommerce', 'lovecatz-wc' ), LWC_JT_Account::get_credentials( 'express', $environment ) );
		if ( is_wp_error( $result ) ) {
			$order->update_meta_data( '_lwc_jt_cancel_error', $result->get_error_message() );
			$order->save();
			return $result;
		}
		$order->delete_meta_data( '_lwc_jt_cancel_error' );
		$order->update_meta_data( '_lwc_jt_cancelled', current_time( 'mysql' ) );
		$order->add_order_note( __( 'J&T shipment cancelled through the API.', 'lovecatz-wc' ) );
		$order->save();
		return $result;
	}

	public function save_insurance( $order_id ) {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! isset( $_POST['lwc_jt_insurance_nonce'], $_POST['lwc_jt_insurance'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lwc_jt_insurance_nonce'] ) ), 'lwc_jt_insurance' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $this->order_uses_jt( $order ) || $order->get_meta( '_lwc_jt_awb' ) ) {
			return;
		}
		$result = $this->set_insurance( $order, wp_unslash( $_POST['lwc_jt_insurance'] ) );
		if ( is_wp_error( $result ) && class_exists( 'WC_Admin_Meta_Boxes' ) ) {
			WC_Admin_Meta_Boxes::add_error( $result->get_error_message() );
		}
	}

	private function set_insurance( $order, $value ) {
		$valid = LWC_JT_Request_Validator::validate_insurance( $value );
		if ( is_wp_error( $valid ) ) {
			// Prevent a Processing transition from silently using an old/zero value.
			$order->update_meta_data( '_lwc_jt_insurance_error', $valid->get_error_message() );
			$order->save();
			return $valid;
		}
		$order->delete_meta_data( '_lwc_jt_insurance_error' );
		$order->update_meta_data( '_lwc_jt_insurance_value', (int) $value );
		$order->save();
		return true;
	}

	private function create_shipment( $order ) {
		if ( ! $order instanceof WC_Order || ! $this->order_uses_jt( $order ) ) {
			return new WP_Error( 'lwc_jt_wrong_method', __( 'This order does not use J&T Express.', 'lovecatz-wc' ) );
		}
		$existing_awb = (string) $order->get_meta( '_lwc_jt_awb' );
		if ( '' !== $existing_awb ) {
			return array( 'success' => true, 'awb' => $existing_awb, 'order_id' => $this->get_jt_order_id( $order ) );
		}

		$environment = $this->get_order_environment( $order );
		$route       = $this->get_order_route( $order, $environment );
		if ( $order->get_meta( '_lwc_jt_insurance_error' ) ) {
			return $this->save_create_error( $order, new WP_Error( 'lwc_jt_invalid_insurance', $order->get_meta( '_lwc_jt_insurance_error' ) ) );
		}
		if ( is_wp_error( $route ) ) {
			return $this->save_create_error( $order, $route );
		}
		$default_shipper_address = trim( implode( ', ', array_filter( array( get_option( 'woocommerce_store_address', '' ), get_option( 'woocommerce_store_address_2', '' ), get_option( 'woocommerce_store_city', '' ) ) ) ) );
		$shipper = wp_parse_args(
			(array) apply_filters(
				'lwc_jt_express_shipper',
				array(
					'name'         => get_bloginfo( 'name' ),
					'phone'        => get_option( 'woocommerce_store_phone', get_option( 'lwc_fedex_shipper_phone', '' ) ),
					'address'      => $default_shipper_address,
					'service_type' => 6,
				),
				$order,
				$environment
			),
			array( 'name' => '', 'phone' => '', 'address' => '', 'service_type' => 6 )
		);
		$shipper_name    = (string) $shipper['name'];
		$shipper_phone   = $this->normalize_phone( $shipper['phone'] );
		$shipper_address = (string) $shipper['address'];
		if ( '' === trim( $shipper_phone ) || '' === trim( $shipper_address ) ) {
			return $this->save_create_error( $order, new WP_Error( 'lwc_jt_shipper_incomplete', __( 'J&T shipper phone and address must be configured before creating an AWB.', 'lovecatz-wc' ) ) );
		}

		$items = $order->get_items();
		$names = array();
		$qty   = 0;
		$value = 0.0;
		$weight = 0.0;
		foreach ( $items as $item ) {
			$names[] = $item->get_name();
			$qty += max( 1, (int) $item->get_quantity() );
			$value += (float) $item->get_total();
			$product = $item->get_product();
			if ( $product ) {
				$weight += (float) wc_get_weight( (float) $product->get_weight() * max( 1, (float) $item->get_quantity() ), 'kg' );
			}
		}
		$now = LWC_JT_Request_Validator::jakarta_timestamp();
		$receiver_name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		if ( '' === $receiver_name ) {
			$receiver_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}
		$shipping_district = $order->get_meta( '_lwc_shipping_district_name', true );
		if ( '' === $shipping_district ) {
			$shipping_district = $order->get_meta( '_wc_shipping/lwc/indonesia-district', true );
		}
		$billing_district = $order->get_meta( '_lwc_billing_district_name', true );
		if ( '' === $billing_district ) {
			$billing_district = $order->get_meta( '_wc_billing/lwc/indonesia-district', true );
		}
		$receiver_address_parts = array_filter( array( $order->get_shipping_address_1(), $order->get_shipping_address_2(), $shipping_district, $order->get_shipping_city(), $order->get_shipping_state() ) );
		if ( empty( $receiver_address_parts ) ) {
			$receiver_address_parts = array_filter( array( $order->get_billing_address_1(), $order->get_billing_address_2(), $billing_district, $order->get_billing_city(), $order->get_billing_state() ) );
		}
		$receiver_phone = $this->normalize_phone( $order->get_billing_phone() );
		$receiver_zip   = trim( (string) ( $order->get_shipping_postcode() ? $order->get_shipping_postcode() : $order->get_billing_postcode() ) );
		if ( '' === $receiver_name || ! LWC_JT_Request_Validator::is_valid_phone( $receiver_phone ) || empty( $receiver_address_parts ) || ! LWC_JT_Request_Validator::is_valid_postcode( $receiver_zip ) ) {
			return $this->save_create_error( $order, new WP_Error( 'lwc_jt_receiver_incomplete', __( 'Recipient name, phone, address, and postal code are required before creating a J&T AWB.', 'lovecatz-wc' ) ) );
		}
		$weight_validation = LWC_JT_Request_Validator::validate_weight( $weight );
		if ( is_wp_error( $weight_validation ) ) {
			return $this->save_create_error( $order, $weight_validation );
		}
		$data = array(
			'orderid'         => $this->get_jt_order_id( $order ),
			'shipper_name'     => substr( sanitize_text_field( $shipper_name ), 0, 30 ),
			'shipper_contact'  => substr( sanitize_text_field( $shipper_name ), 0, 30 ),
			'shipper_phone'    => substr( $shipper_phone, 0, 15 ),
			'shipper_addr'     => substr( sanitize_text_field( $shipper_address ), 0, 200 ),
			'origin_code'      => $route['origin_city_code'],
			'receiver_name'    => substr( sanitize_text_field( $receiver_name ), 0, 30 ),
			'receiver_phone'   => substr( $receiver_phone, 0, 15 ),
			'receiver_addr'    => substr( sanitize_text_field( implode( ', ', $receiver_address_parts ) ), 0, 200 ),
			'receiver_zip'     => $receiver_zip,
			'destination_code' => $route['destination_city_code'],
			'receiver_area'    => $route['destination_area_code'],
			'qty'              => max( 1, $qty ),
			'weight'           => max( 0.01, round( $weight, 2 ) ),
			'goodsdesc'        => substr( $this->sanitize_goods_text( implode( ' ', $names ) ), 0, 40 ),
			'servicetype'      => 1 === (int) $shipper['service_type'] ? 1 : 6,
			'insurance'        => $order->get_meta( '_lwc_jt_insurance_value' ) ?: 0,
			'orderdate'        => $now,
			'item_name'        => substr( $this->sanitize_goods_text( reset( $names ) ), 0, 50 ),
			'cod'              => 'cod' === $order->get_payment_method() ? min( 99999999, (int) ceil( $order->get_total() ) ) : 0,
			'sendstarttime'    => $now,
			'sendendtime'      => LWC_JT_Request_Validator::jakarta_timestamp( '+4 hours' ),
			'expresstype'      => '1',
			'goodsvalue'       => min( 99999999, max( 1, (int) ceil( $value ) ) ),
		);
		$result = ( new LWC_JT_Express_API() )->create_order( $data, LWC_JT_Account::get_credentials( 'express', $environment ) );
		if ( is_wp_error( $result ) ) {
			return $this->save_create_error( $order, $result );
		}
		$order->delete_meta_data( '_lwc_jt_create_error' );
		$order->update_meta_data( '_lwc_jt_awb', $result['awb'] );
		$order->update_meta_data( '_lwc_jt_order_id', $result['order_id'] );
		$order->update_meta_data( '_lwc_jt_etd', $result['etd'] );
		$order->update_meta_data( '_lwc_jt_declared_weight_kg', $data['weight'] );
		$order->add_order_note( sprintf( __( 'J&T %1$s order created. AWB: %2$s', 'lovecatz-wc' ), ucfirst( $environment ), $result['awb'] ) );
		$order->save();
		$this->refresh_tracking( $order );
		return $result;
	}

	public function refresh_tracking( $order ) {
		$awb = (string) $order->get_meta( '_lwc_jt_awb' );
		if ( '' === $awb ) {
			return new WP_Error( 'lwc_jt_missing_awb', __( 'Create the J&T order before requesting tracking.', 'lovecatz-wc' ) );
		}
		$result = ( new LWC_JT_Express_API() )->track( $awb, LWC_JT_Account::get_credentials( 'express', $this->get_order_environment( $order ) ) );
		if ( is_wp_error( $result ) ) {
			$order->update_meta_data( '_lwc_jt_tracking_error', $result->get_error_message() );
			$order->save();
			return $result;
		}
		$order->delete_meta_data( '_lwc_jt_tracking_error' );
		$weight = isset( $result['detail']['weight'] ) ? $result['detail']['weight'] : null;
		if ( is_numeric( $weight ) && is_finite( (float) $weight ) && (float) $weight > 0 ) {
			$previous = $order->get_meta( '_lwc_jt_tracking_weight_raw' );
			if ( is_numeric( $previous ) && (float) $previous > 0 && (float) $previous !== (float) $weight ) {
				$order->add_order_note( sprintf( __( 'J&T reported a weight change from %1$s to %2$s (carrier units). Declared shipment weight is unchanged.', 'lovecatz-wc' ), $previous, $weight ) );
			}
			$order->update_meta_data( '_lwc_jt_tracking_weight_raw', $weight );
		}
		$stored_result = $result;
		unset( $stored_result['exchange'] );
		$order->update_meta_data( '_lwc_jt_tracking', $stored_result );
		$order->save();
		return $result;
	}

	public function render_customer_tracking( $order ) {
		if ( ! $this->order_uses_jt( $order ) || ! $order->get_meta( '_lwc_jt_awb' ) ) {
			return;
		}
		echo '<section class="lwc-jt-customer-tracking"><h2>' . esc_html__( 'J&T Express tracking', 'lovecatz-wc' ) . '</h2><p><strong>' . esc_html__( 'AWB:', 'lovecatz-wc' ) . '</strong> ' . esc_html( $order->get_meta( '_lwc_jt_awb' ) ) . '</p>';
		$this->render_tracking( (array) $order->get_meta( '_lwc_jt_tracking' ) );
		echo '</section>';
	}

	private function render_tracking( $tracking ) {
		$weight = isset( $tracking['detail']['weight'] ) ? $tracking['detail']['weight'] : null;
		if ( is_numeric( $weight ) && is_finite( (float) $weight ) && (float) $weight > 0 ) {
			echo '<p>' . esc_html( sprintf( __( 'Weight reported by J&T: %s (carrier units)', 'lovecatz-wc' ), $weight ) ) . '</p>';
		}
		$history = isset( $tracking['history'] ) && is_array( $tracking['history'] ) ? $tracking['history'] : array();
		if ( empty( $history ) ) {
			echo '<p class="description">' . esc_html__( 'No tracking events are available yet.', 'lovecatz-wc' ) . '</p>';
			return;
		}
		echo '<ol class="lwc-jt-tracking-events">';
		foreach ( array_reverse( $history ) as $event ) {
			echo '<li><strong>' . esc_html( isset( $event['status'] ) ? $event['status'] : '' ) . '</strong><br><small>' . esc_html( isset( $event['date_time'] ) ? $event['date_time'] : '' ) . ' — ' . esc_html( isset( $event['city_name'] ) ? $event['city_name'] : '' ) . '</small></li>';
		}
		echo '</ol>';
	}

	private function get_order_route( $order, $environment ) {
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			if ( in_array( $item->get_method_id(), array( 'lwc_jt_express', 'lwc_jt' ), true ) ) {
				$city = $item->get_meta( '_lwc_jt_destination_city_code', true );
				$area = $item->get_meta( '_lwc_jt_destination_area_code', true );
				$origin = $item->get_meta( '_lwc_jt_origin_city_code', true );
				if ( $city && $area ) {
					$origin_route = LWC_JT_Route_Mapper::get_origin_route( $environment );
					if ( is_wp_error( $origin_route ) && ! $origin ) {
						return $origin_route;
					}
					return array( 'origin_city_code' => $origin ? $origin : $origin_route['city_code'], 'destination_city_code' => $city, 'destination_area_code' => $area );
				}
			}
		}
		$use_shipping = 'ID' === $order->get_shipping_country() && '' !== trim( (string) $order->get_shipping_city() );
		$state = $use_shipping ? $order->get_shipping_state() : $order->get_billing_state();
		$city = $use_shipping ? $order->get_shipping_city() : $order->get_billing_city();
		$postcode = $use_shipping ? $order->get_shipping_postcode() : $order->get_billing_postcode();
		$district = (string) $order->get_meta( '_lwc_' . ( $use_shipping ? 'shipping' : 'billing' ) . '_district_name', true );
		if ( '' === $district ) {
			$district = (string) $order->get_meta( '_wc_' . ( $use_shipping ? 'shipping' : 'billing' ) . '/lwc/indonesia-district', true );
		}
		$route = LWC_JT_Route_Mapper::resolve( $postcode, $environment, $state, $city, $district );
		if ( is_wp_error( $route ) ) {
			return $route;
		}
		$origin_route = LWC_JT_Route_Mapper::get_origin_route( $environment );
		if ( is_wp_error( $origin_route ) ) {
			return $origin_route;
		}
		$route['origin_city_code'] = $origin_route['city_code'];
		return $route;
	}

	private function get_order_environment( $order ) {
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			if ( in_array( $item->get_method_id(), array( 'lwc_jt_express', 'lwc_jt' ), true ) ) {
				$value = $item->get_meta( '_lwc_jt_environment', true );
				if ( ! $value ) {
					$value = $item->get_meta( 'lwc_jt_environment', true );
				}
				return 'production' === $value ? 'production' : 'sandbox';
			}
		}
		return 'sandbox';
	}

	private function get_jt_order_id( $order ) {
		$stored = (string) $order->get_meta( '_lwc_jt_order_id' );
		return $stored ? $stored : substr( 'LWC-' . $order->get_id(), 0, 20 );
	}

	private function save_create_error( $order, $error ) {
		$order->update_meta_data( '_lwc_jt_create_error', $error->get_error_message() );
		$order->add_order_note( sprintf( __( 'J&T order creation failed: %s', 'lovecatz-wc' ), $error->get_error_message() ) );
		$order->save();
		return $error;
	}

	private function get_ajax_order() {
		check_ajax_referer( 'lwc_jt_order', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'lovecatz-wc' ) ), 403 );
		}
		$order = wc_get_order( isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0 );
		if ( ! $this->order_uses_jt( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'J&T order not found.', 'lovecatz-wc' ) ), 404 );
		}
		return $order;
	}

	private function order_uses_jt( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			if ( in_array( $item->get_method_id(), array( 'lwc_jt_express', 'lwc_jt' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	private function resolve_admin_order( $post_or_order = null ) {
		if ( $post_or_order instanceof WC_Order ) {
			return $post_or_order;
		}
		if ( $post_or_order instanceof WP_Post ) {
			return wc_get_order( $post_or_order->ID );
		}
		$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : ( isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $id ? wc_get_order( $id ) : false;
	}

	private function normalize_phone( $phone ) {
		$phone = preg_replace( '/[^0-9+]/', '', (string) $phone );
		if ( 0 === strpos( $phone, '0' ) ) {
			$phone = '+62' . substr( $phone, 1 );
		} elseif ( 0 === strpos( $phone, '62' ) ) {
			$phone = '+' . $phone;
		}
		return $phone;
	}

	/** J&T disallows special characters in item names and descriptions. */
	private function sanitize_goods_text( $value ) {
		$value = preg_replace( '/[^\p{L}\p{N} ]+/u', ' ', sanitize_text_field( (string) $value ) );
		return trim( preg_replace( '/\s+/', ' ', $value ) );
	}
}
