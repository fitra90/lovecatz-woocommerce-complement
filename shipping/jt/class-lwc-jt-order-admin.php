<?php
/** J&T Express order creation, tracking, cancellation, and order UI. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_JT_Order_Admin {
	public function init() {
		add_action( 'woocommerce_order_status_processing', array( $this, 'create_on_processing' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_lwc_jt_create_order', array( $this, 'ajax_create_order' ) );
		add_action( 'wp_ajax_lwc_jt_refresh_tracking', array( $this, 'ajax_refresh_tracking' ) );
		add_action( 'wp_ajax_lwc_jt_cancel_order', array( $this, 'ajax_cancel_order' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_customer_tracking' ) );
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
		wp_localize_script( 'lwc-jt-order-admin', 'lwcJtOrder', array( 'ajax_url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'lwc_jt_order' ) ) );
	}

	public function render_metabox( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$awb      = (string) $order->get_meta( '_lwc_jt_awb' );
		$error    = (string) $order->get_meta( '_lwc_jt_create_error' );
		$tracking_error = (string) $order->get_meta( '_lwc_jt_tracking_error' );
		$tracking = (array) $order->get_meta( '_lwc_jt_tracking' );
		$env      = $this->get_order_environment( $order );
		?>
		<div class="lwc-jt-order-box" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
			<p><strong><?php esc_html_e( 'Environment:', 'lovecatz-wc' ); ?></strong> <?php echo esc_html( ucfirst( $env ) ); ?></p>
			<?php if ( '' === $awb ) : ?>
				<p><?php esc_html_e( 'The J&T order and AWB are created automatically when this WooCommerce order enters Processing.', 'lovecatz-wc' ); ?></p>
				<button type="button" class="button button-primary" id="lwc-jt-create-order"><?php esc_html_e( 'Create J&T Order / AWB', 'lovecatz-wc' ); ?></button>
			<?php else : ?>
				<p><strong><?php esc_html_e( 'AWB:', 'lovecatz-wc' ); ?></strong> <span id="lwc-jt-awb"><?php echo esc_html( $awb ); ?></span></p>
				<p><button type="button" class="button" id="lwc-jt-refresh-tracking"><?php esc_html_e( 'Refresh Tracking', 'lovecatz-wc' ); ?></button> <button type="button" class="button" id="lwc-jt-cancel-order"><?php esc_html_e( 'Cancel J&T Order', 'lovecatz-wc' ); ?></button></p>
			<?php endif; ?>
			<div id="lwc-jt-order-status" class="<?php echo ( $error || $tracking_error ) ? 'is-error' : ''; ?>" aria-live="polite"><?php echo esc_html( $error ? $error : $tracking_error ); ?></div>
			<div id="lwc-jt-tracking"><?php $this->render_tracking( $tracking ); ?></div>
		</div>
		<?php
	}

	public function ajax_create_order() {
		$order  = $this->get_ajax_order();
		$result = $this->create_shipment( $order );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => __( 'J&T order created successfully.', 'lovecatz-wc' ), 'awb' => $result['awb'] ) );
	}

	public function ajax_refresh_tracking() {
		$order = $this->get_ajax_order();
		$result = $this->refresh_tracking( $order );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		ob_start();
		$this->render_tracking( $result );
		wp_send_json_success( array( 'message' => __( 'J&T tracking refreshed.', 'lovecatz-wc' ), 'html' => ob_get_clean() ) );
	}

	public function ajax_cancel_order() {
		$order = $this->get_ajax_order();
		$environment = $this->get_order_environment( $order );
		$result = ( new LWC_JT_Express_API() )->cancel_order( $this->get_jt_order_id( $order ), __( 'Cancelled in WooCommerce', 'lovecatz-wc' ), LWC_JT_Account::get_credentials( 'express', $environment ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		$order->update_meta_data( '_lwc_jt_cancelled', current_time( 'mysql' ) );
		$order->add_order_note( __( 'J&T shipment cancelled through the API.', 'lovecatz-wc' ) );
		$order->save();
		wp_send_json_success( array( 'message' => __( 'J&T order cancelled.', 'lovecatz-wc' ) ) );
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
		$now = current_datetime()->format( 'Y-m-d H:i:s' );
		$receiver_name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		if ( '' === $receiver_name ) {
			$receiver_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}
		$receiver_address_parts = array_filter( array( $order->get_shipping_address_1(), $order->get_shipping_address_2(), $order->get_shipping_city(), $order->get_shipping_state() ) );
		if ( empty( $receiver_address_parts ) ) {
			$receiver_address_parts = array_filter( array( $order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_billing_city(), $order->get_billing_state() ) );
		}
		$receiver_phone = $this->normalize_phone( $order->get_billing_phone() );
		$receiver_zip   = preg_replace( '/\D/', '', $order->get_shipping_postcode() ? $order->get_shipping_postcode() : $order->get_billing_postcode() );
		if ( '' === $receiver_name || '' === $receiver_phone || empty( $receiver_address_parts ) || '' === $receiver_zip ) {
			return $this->save_create_error( $order, new WP_Error( 'lwc_jt_receiver_incomplete', __( 'Recipient name, phone, address, and postal code are required before creating a J&T AWB.', 'lovecatz-wc' ) ) );
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
			'receiver_zip'     => substr( $receiver_zip, 0, 5 ),
			'destination_code' => $route['destination_city_code'],
			'receiver_area'    => $route['destination_area_code'],
			'qty'              => max( 1, $qty ),
			'weight'           => max( 0.01, round( $weight, 2 ) ),
			'goodsdesc'        => substr( $this->sanitize_goods_text( implode( ' ', $names ) ), 0, 40 ),
			'servicetype'      => 1 === (int) $shipper['service_type'] ? 1 : 6,
			'insurance'        => 0,
			'orderdate'        => $now,
			'item_name'        => substr( $this->sanitize_goods_text( reset( $names ) ), 0, 50 ),
			'cod'              => 'cod' === $order->get_payment_method() ? min( 99999999, (int) round( $order->get_total() ) ) : 0,
			'sendstarttime'    => $now,
			'sendendtime'      => current_datetime()->modify( '+4 hours' )->format( 'Y-m-d H:i:s' ),
			'expresstype'      => '1',
			'goodsvalue'       => min( 99999999, max( 1, (int) round( $value ) ) ),
		);
		$result = ( new LWC_JT_Express_API() )->create_order( $data, LWC_JT_Account::get_credentials( 'express', $environment ) );
		if ( is_wp_error( $result ) ) {
			return $this->save_create_error( $order, $result );
		}
		$order->delete_meta_data( '_lwc_jt_create_error' );
		$order->update_meta_data( '_lwc_jt_awb', $result['awb'] );
		$order->update_meta_data( '_lwc_jt_order_id', $result['order_id'] );
		$order->update_meta_data( '_lwc_jt_etd', $result['etd'] );
		$order->add_order_note( sprintf( __( 'J&T %1$s order created. AWB: %2$s', 'lovecatz-wc' ), ucfirst( $environment ), $result['awb'] ) );
		$order->save();
		$this->refresh_tracking( $order );
		return $result;
	}

	private function refresh_tracking( $order ) {
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
		$order->update_meta_data( '_lwc_jt_tracking', $result );
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
					return array( 'origin_city_code' => $origin ? $origin : LWC_JT_Route_Mapper::get_origin_city_code( $environment ), 'destination_city_code' => $city, 'destination_area_code' => $area );
				}
			}
		}
		$route = LWC_JT_Route_Mapper::resolve( $order->get_shipping_postcode(), $environment );
		if ( is_wp_error( $route ) ) {
			return $route;
		}
		$route['origin_city_code'] = LWC_JT_Route_Mapper::get_origin_city_code( $environment );
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
