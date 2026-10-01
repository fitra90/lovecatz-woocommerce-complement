<?php
/**
 * J&T Cargo controls on the WooCommerce order screen.
 *
 * Mirrors the J&T Express order admin: a side metabox on every order shipped
 * with `lwc_jt_cargo`, one explicit "create order" action, and a readable
 * error. J&T Cargo is never created automatically — LWC_AWB_Automation owns
 * that policy and keeps Cargo out of the automatic list.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Order-screen UI for the J&T Cargo provider. */
class LWC_JTC_Order_Admin {

	/** Register hooks. */
	public function init() {
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_lwc_jtc_create_order', array( $this, 'ajax_create_order' ) );
	}

	/** Add the metabox only for orders that actually use J&T Cargo. */
	public function register_metabox( $post_type = '', $post_or_order = null ) {
		$order = $this->resolve_admin_order( $post_or_order );
		if ( ! $this->order_uses_cargo( $order ) ) {
			return;
		}
		add_meta_box(
			'lwc-jtc-shipping',
			__( 'J&T Cargo Shipping', 'lovecatz-wc' ),
			array( $this, 'render_metabox' ),
			array( 'shop_order', 'woocommerce_page_wc-orders' ),
			'side',
			'default'
		);
	}

	/** Load the small script that drives the create button. */
	public function enqueue_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}
		if ( ! $this->order_uses_cargo( $this->resolve_admin_order() ) ) {
			return;
		}

		wp_enqueue_style( 'lwc-jtc-order-admin', LWC_PLUGIN_URL . 'shipping/jtc/jtc-order-admin.css', array(), LWC_VERSION );
		wp_enqueue_script( 'lwc-jtc-order-admin', LWC_PLUGIN_URL . 'shipping/jtc/jtc-order-admin.js', array( 'jquery' ), LWC_VERSION, true );
		wp_localize_script(
			'lwc-jtc-order-admin',
			'lwcJtcOrder',
			array(
				'ajax_url'  => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'lwc_jtc_order' ),
				'creating'  => __( 'Creating J&T Cargo order…', 'lovecatz-wc' ),
			)
		);
	}

	/** Render the metabox. */
	public function render_metabox( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : ( isset( $post_or_order->ID ) ? wc_get_order( $post_or_order->ID ) : null );
		if ( ! $order ) {
			return;
		}

		$awb       = (string) $order->get_meta( '_lwc_jt_cargo_awb', true );
		$error     = (string) $order->get_meta( '_lwc_jt_cargo_create_error', true );
		$number    = class_exists( 'LWC_JTC_Order' ) ? LWC_JTC_Order::build_customer_order_number( $order ) : '';
		$env       = 'production' === (string) get_option( 'lwc_jt_cargo_environment', 'sandbox' ) ? 'production' : 'sandbox';
		?>
		<div class="lwc-jtc-order-box" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
			<p><strong><?php esc_html_e( 'Environment:', 'lovecatz-wc' ); ?></strong> <?php echo esc_html( ucfirst( $env ) ); ?></p>
			<p><strong><?php esc_html_e( 'Customer order number:', 'lovecatz-wc' ); ?></strong> <span id="lwc-jtc-txlogistic-id"><?php echo esc_html( $number ); ?></span></p>
			<?php if ( '' === $awb ) : ?>
				<p><?php esc_html_e( 'J&T Cargo orders are created manually from this screen.', 'lovecatz-wc' ); ?></p>
				<p><button type="button" class="button button-primary" id="lwc-jtc-create-order"><?php esc_html_e( 'Create J&T Cargo Order', 'lovecatz-wc' ); ?></button></p>
			<?php else : ?>
				<p><strong><?php esc_html_e( 'AWB:', 'lovecatz-wc' ); ?></strong> <span id="lwc-jtc-awb"><?php echo esc_html( $awb ); ?></span></p>
				<?php if ( class_exists( 'LWC_JTC_Label' ) ) : ?>
					<p>
						<a class="button" href="<?php echo esc_url( LWC_JTC_Label::get_print_url( $order->get_id() ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Print Label', 'lovecatz-wc' ); ?></a>
						<a class="button" href="<?php echo esc_url( LWC_JTC_Label::get_track_url( $order->get_id() ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Track', 'lovecatz-wc' ); ?></a>
					</p>
				<?php endif; ?>
			<?php endif; ?>
			<div id="lwc-jtc-order-status" class="<?php echo '' !== $error ? 'is-error' : ''; ?>" aria-live="polite"><?php echo esc_html( $error ); ?></div>
		</div>
		<?php
	}

	/** AJAX: create the J&T Cargo order for one WooCommerce order. */
	public function ajax_create_order() {
		$order = $this->get_ajax_order();

		$result = LWC_JTC_Order::create_order( $order );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'J&T Cargo order created successfully.', 'lovecatz-wc' ),
				'awb'     => isset( $result['awb'] ) ? $result['awb'] : '',
			)
		);
	}

	/** Permission-checked AJAX order lookup. */
	private function get_ajax_order() {
		check_ajax_referer( 'lwc_jtc_order', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'lovecatz-wc' ) ), 403 );
		}
		$order = wc_get_order( isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0 );
		if ( ! $this->order_uses_cargo( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'J&T Cargo order not found.', 'lovecatz-wc' ) ), 404 );
		}
		return $order;
	}

	/** True when the order ships with the internal J&T Cargo method. */
	private function order_uses_cargo( $order ) {
		if ( ! class_exists( 'LWC_JTC_Label' ) ) {
			return false;
		}
		return LWC_JTC_Label::order_uses_cargo( $order );
	}

	/** Resolve the order from either admin screen signature. */
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
}
