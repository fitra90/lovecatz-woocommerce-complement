<?php
/** RaySpeed AWB and tracking controls on WooCommerce orders. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_RaySpeed_Order_Admin {
	public function init() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_lwc_rayspeed_create_awb', array( $this, 'ajax_create_awb' ) );
		add_action( 'wp_ajax_lwc_rayspeed_track', array( $this, 'ajax_track' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_customer_tracking' ) );
	}

	public function add_meta_box( $post_type = '', $post_or_order = null ) {
		$order = $this->resolve_admin_order( $post_or_order );
		if ( ! $this->order_uses_rayspeed( $order ) ) {
			return;
		}
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'lwc-rayspeed-shipping', __( 'RaySpeed Shipping', 'lovecatz-wc' ), array( $this, 'render_meta_box' ), $screen, 'side', 'default' );
	}

	public function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$awb = (string) $order->get_meta( '_lwc_rayspeed_awb' );
		$tracking = $order->get_meta( '_lwc_rayspeed_tracking' );
		?>
		<div class="lwc-rayspeed-order" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
			<?php if ( 'ID' === strtoupper( (string) $order->get_shipping_country() ) ) : ?>
				<p><?php esc_html_e( 'RaySpeed is configured for non-Indonesian destinations.', 'lovecatz-wc' ); ?></p>
			<?php else : ?>
				<p><strong><?php esc_html_e( 'Environment:', 'lovecatz-wc' ); ?></strong> <?php esc_html_e( 'Development Sandbox', 'lovecatz-wc' ); ?></p>
				<p><button type="button" class="button button-primary" id="lwc-rayspeed-create-awb" <?php disabled( '' !== $awb ); ?>><?php esc_html_e( 'Create test AWB', 'lovecatz-wc' ); ?></button></p>
				<p><strong><?php esc_html_e( 'AWB:', 'lovecatz-wc' ); ?></strong> <span id="lwc-rayspeed-awb"><?php echo esc_html( $awb ? $awb : '—' ); ?></span></p>
				<p><button type="button" class="button" id="lwc-rayspeed-track" <?php disabled( '' === $awb ); ?>><?php esc_html_e( 'Refresh tracking', 'lovecatz-wc' ); ?></button></p>
				<div id="lwc-rayspeed-status" aria-live="polite"></div>
				<div id="lwc-rayspeed-tracking"><?php $this->render_tracking( is_array( $tracking ) ? $tracking : array() ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	public function enqueue_assets() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}
		if ( ! $this->order_uses_rayspeed( $this->resolve_admin_order() ) ) {
			return;
		}
		wp_enqueue_script( 'lwc-rayspeed-order', LWC_PLUGIN_URL . 'shipping/rayspeed/rayspeed-order-admin.js', array( 'jquery' ), LWC_VERSION, true );
		wp_localize_script( 'lwc-rayspeed-order', 'lwcRaySpeedOrder', array( 'ajax_url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'lwc_rayspeed_order' ) ) );
	}

	private function resolve_admin_order( $post_or_order = null ) {
		if ( $post_or_order instanceof WC_Order ) {
			return $post_or_order;
		}
		if ( $post_or_order instanceof WP_Post ) {
			return wc_get_order( $post_or_order->ID );
		}
		$order_id = 0;
		if ( isset( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order_id = absint( wp_unslash( $_GET['id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order_id = absint( wp_unslash( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return $order_id ? wc_get_order( $order_id ) : false;
	}

	private function order_uses_rayspeed( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		foreach ( $order->get_items( 'shipping' ) as $shipping_item ) {
			if ( 'lwc_rayspeed' === $shipping_item->get_method_id() ) {
				return true;
			}
		}
		return false;
	}

	public function ajax_create_awb() {
		$order = $this->get_ajax_order();
		if ( $order->get_meta( '_lwc_rayspeed_awb' ) ) {
			wp_send_json_error( array( 'message' => __( 'This order already has a RaySpeed AWB.', 'lovecatz-wc' ) ) );
		}
		$result = ( new LWC_RaySpeed_API() )->create_awb( $order );
		if ( empty( $result['success'] ) ) {
			wp_send_json_error( array( 'message' => isset( $result['message'] ) ? $result['message'] : __( 'RaySpeed AWB creation failed.', 'lovecatz-wc' ) ) );
		}
		$order->update_meta_data( '_lwc_rayspeed_awb', $result['airwaybill'] );
		$order->add_order_note( sprintf( __( 'RaySpeed test AWB created: %s', 'lovecatz-wc' ), $result['airwaybill'] ) );
		$order->save();
		wp_send_json_success( array( 'message' => __( 'RaySpeed test AWB created.', 'lovecatz-wc' ), 'awb' => $result['airwaybill'] ) );
	}

	public function ajax_track() {
		$order = $this->get_ajax_order();
		$awb = (string) $order->get_meta( '_lwc_rayspeed_awb' );
		$result = ( new LWC_RaySpeed_API() )->track( $awb );
		if ( empty( $result['success'] ) ) {
			wp_send_json_error( array( 'message' => isset( $result['message'] ) ? $result['message'] : __( 'RaySpeed tracking failed.', 'lovecatz-wc' ) ) );
		}
		$events = isset( $result['data']['data'] ) && is_array( $result['data']['data'] ) ? $result['data']['data'] : array();
		$order->update_meta_data( '_lwc_rayspeed_tracking', $events );
		$order->save();
		ob_start();
		$this->render_tracking( $events );
		wp_send_json_success( array( 'message' => __( 'RaySpeed tracking refreshed.', 'lovecatz-wc' ), 'html' => ob_get_clean() ) );
	}

	public function render_customer_tracking( $order ) {
		$awb = (string) $order->get_meta( '_lwc_rayspeed_awb' );
		if ( '' === $awb ) {
			return;
		}
		echo '<section class="lwc-rayspeed-customer-tracking"><h2>' . esc_html__( 'RaySpeed shipment tracking', 'lovecatz-wc' ) . '</h2><p><strong>' . esc_html__( 'AWB:', 'lovecatz-wc' ) . '</strong> ' . esc_html( $awb ) . '</p>';
		$this->render_tracking( (array) $order->get_meta( '_lwc_rayspeed_tracking' ) );
		echo '</section>';
	}

	private function get_ajax_order() {
		check_ajax_referer( 'lwc_rayspeed_order', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'lovecatz-wc' ) ), 403 );
		}
		$order = wc_get_order( isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0 );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'lovecatz-wc' ) ), 404 );
		}
		return $order;
	}

	private function render_tracking( $events ) {
		if ( empty( $events ) ) {
			return;
		}
		echo '<ol class="lwc-rayspeed-events">';
		foreach ( $events as $event ) {
			echo '<li><strong>' . esc_html( isset( $event['date'] ) ? $event['date'] : '' ) . '</strong><br>' . esc_html( isset( $event['details'] ) ? $event['details'] : '' ) . '</li>';
		}
		echo '</ol>';
	}
}
