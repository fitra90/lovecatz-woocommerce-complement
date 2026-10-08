<?php
/**
 * Purchase Order (PO) payment gateway.
 *
 * Lets a customer place an order now and settle it against an invoice later.
 * No money is collected at checkout: the gateway records the PO plan on the
 * order, moves it to the configured status (on-hold by default, matching how
 * WooCommerce treats BACS and cheque), and leaves the balance outstanding for
 * the administrator to settle from the order screen ledger.
 *
 * The PO reference is deliberately not collected at checkout. This store uses
 * the Checkout Block, which does not render legacy gateway fields, so the
 * reference, the payment term and the due date are all managed from the order
 * screen where the administrator has the signed document to hand.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_Gateway_Purchase_Order extends WC_Payment_Gateway {

	/**
	 * Configure the gateway and its WooCommerce Payments settings screen.
	 */
	public function __construct() {
		$this->id                 = LWC_Payment_Plan::GATEWAY_PO;
		$this->has_fields         = false;
		$this->method_title       = __( 'Purchase Order (PO)', 'lovecatz-wc' );
		$this->method_description = __( 'Accept an order now and collect payment later against a Purchase Order. The balance is tracked on the order screen.', 'lovecatz-wc' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Purchase Order (PO)', 'lovecatz-wc' ) );
		$this->description = $this->get_option( 'description', __( 'Place your order now and pay against the invoice we send you.', 'lovecatz-wc' ) );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Fields shown on WooCommerce → Settings → Payments → Purchase Order.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'lovecatz-wc' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Purchase Order payments', 'lovecatz-wc' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __( 'Title', 'lovecatz-wc' ),
				'type'        => 'text',
				'description' => __( 'Payment method name shown to the customer at checkout.', 'lovecatz-wc' ),
				'default'     => __( 'Purchase Order (PO)', 'lovecatz-wc' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'lovecatz-wc' ),
				'type'        => 'textarea',
				'description' => __( 'Explanation shown under the method name at checkout.', 'lovecatz-wc' ),
				'default'     => __( 'Place your order now and pay against the invoice we send you.', 'lovecatz-wc' ),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Whether the method may be offered at checkout.
	 *
	 * The plugin-level switch is authoritative: turning the feature off in the
	 * Order tab hides both this method and partial payment immediately, even if
	 * the WooCommerce gateway toggle is still on.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! LWC_Payment_Plan::instance()->is_enabled() ) {
			return false;
		}

		if ( 'yes' === get_option( LWC_Payment_Plan::OPT_PO_REQUIRE_LOGIN, 'yes' ) && ! is_user_logged_in() ) {
			return false;
		}

		return parent::is_available();
	}

	/**
	 * Extra reassurance printed under the gateway description.
	 */
	public function payment_fields() {
		parent::payment_fields();

		$terms = max( 0, (int) get_option( LWC_Payment_Plan::OPT_PO_TERMS_DAYS, 30 ) );

		if ( $terms > 0 ) {
			printf(
				'<p class="lwc-po-checkout-note">%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: payment term in days. */
						_n( 'Payment is due within %d day of the invoice.', 'Payment is due within %d days of the invoice.', $terms, 'lovecatz-wc' ),
						$terms
					)
				)
			);
		}
	}

	/**
	 * Place the order on the PO plan and move it to the configured status.
	 *
	 * @param int $order_id Order being paid for.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			wc_add_notice( __( 'Order not found.', 'lovecatz-wc' ), 'error' );

			return array( 'result' => 'failure' );
		}

		LWC_Payment_Plan::instance()->apply_plan( $order, LWC_Payment_Plan::PLAN_PO );

		if ( (float) $order->get_total() > 0 ) {
			$order->update_status(
				$this->get_process_payment_status( $order ),
				__( 'Awaiting Purchase Order payment.', 'lovecatz-wc' )
			);
		} else {
			$order->payment_complete();
		}

		// Remove the cart only when it still matches the order being processed.
		if ( WC()->cart && $order->has_cart_hash( WC()->cart->get_cart_hash() ) ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Order status applied once a Purchase Order is placed.
	 *
	 * Constrained to the statuses WooCommerce reduces stock on, so a PO can
	 * never be placed without reserving inventory.
	 *
	 * @param WC_Order $order Order being processed.
	 * @return string Status slug without the `wc-` prefix.
	 */
	private function get_process_payment_status( $order ) {
		$configured = (string) get_option( LWC_Payment_Plan::OPT_PO_STATUS, 'on-hold' );
		$available  = LWC_Payment_Plan::get_available_order_statuses();

		$status = array_key_exists( $configured, $available ) ? $configured : 'on-hold';

		/**
		 * Filter the order status a Purchase Order checkout produces.
		 *
		 * @param string   $status Default status slug.
		 * @param WC_Order $order  Order being processed.
		 */
		return (string) apply_filters( 'lwc_po_process_payment_order_status', $status, $order );
	}
}
