<?php
/**
 * Partial payment / down payment (DP) gateway.
 *
 * The customer commits to the order and pays a deposit; the remaining balance
 * is invoiced afterwards. WooCommerce itself has no notion of a partly paid
 * order, so the deposit expectation and the money actually received are both
 * held by LWC_Payment_Plan and managed from the order screen ledger.
 *
 * As with the Purchase Order gateway, no card data or deposit reference is
 * collected at checkout — the store settles deposits offline, so checkout only
 * records the plan and the expected deposit amount.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_Gateway_Partial_Payment extends WC_Payment_Gateway {

	/**
	 * Configure the gateway and its WooCommerce Payments settings screen.
	 */
	public function __construct() {
		$this->id                 = LWC_Payment_Plan::GATEWAY_DP;
		$this->has_fields         = false;
		$this->method_title       = __( 'Partial payment / DP', 'lovecatz-wc' );
		$this->method_description = __( 'Let the customer pay a deposit now and settle the remaining balance later. The deposit, amount paid and balance are tracked on the order screen.', 'lovecatz-wc' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Partial payment (DP)', 'lovecatz-wc' ) );
		$this->description = $this->get_option( 'description', __( 'Pay a deposit now and settle the remaining balance before dispatch.', 'lovecatz-wc' ) );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Fields shown on WooCommerce → Settings → Payments → Partial payment.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'lovecatz-wc' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable partial payment (DP) at checkout', 'lovecatz-wc' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __( 'Title', 'lovecatz-wc' ),
				'type'        => 'text',
				'description' => __( 'Payment method name shown to the customer at checkout.', 'lovecatz-wc' ),
				'default'     => __( 'Partial payment (DP)', 'lovecatz-wc' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'lovecatz-wc' ),
				'type'        => 'textarea',
				'description' => __( 'Explanation shown under the method name at checkout.', 'lovecatz-wc' ),
				'default'     => __( 'Pay a deposit now and settle the remaining balance before dispatch.', 'lovecatz-wc' ),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Whether the method may be offered at checkout.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! LWC_Payment_Plan::instance()->is_enabled() ) {
			return false;
		}

		// A deposit is meaningless when nothing is owed, or when the configured
		// percentage would leave no balance to collect later.
		$percent = LWC_Payment_Plan::instance()->get_default_deposit_percent();
		if ( $percent <= 0 || $percent >= 100 ) {
			return false;
		}

		return parent::is_available();
	}

	/**
	 * Show the exact deposit the customer will be asked to pay.
	 *
	 * The amount is quoted from the live cart so the customer is never asked to
	 * approve a figure they cannot see. Checkout recalculates it against the
	 * stored order total, which is authoritative.
	 */
	public function payment_fields() {
		parent::payment_fields();

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$percent = LWC_Payment_Plan::instance()->get_default_deposit_percent();
		$total   = (float) WC()->cart->get_total( 'edit' );
		$deposit = LWC_Payment_Plan::instance()->round( $total * $percent / 100 );
		$balance = LWC_Payment_Plan::instance()->round( max( 0, $total - $deposit ) );

		printf(
			'<p class="lwc-dp-checkout-note"><strong>%1$s</strong> %2$s<br /><strong>%3$s</strong> %4$s</p>',
			esc_html__( 'Deposit due now:', 'lovecatz-wc' ),
			wp_kses_post( wc_price( $deposit ) ),
			esc_html__( 'Balance after deposit:', 'lovecatz-wc' ),
			wp_kses_post( wc_price( $balance ) )
		);
	}

	/**
	 * Place the order on the DP plan and move it to the configured status.
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

		// Derive the deposit from the stored order total so the figure recorded
		// matches the invoice even if the cart changed during checkout.
		LWC_Payment_Plan::instance()->apply_plan(
			$order,
			LWC_Payment_Plan::PLAN_DP,
			array( 'deposit_percent' => LWC_Payment_Plan::instance()->get_default_deposit_percent() )
		);

		if ( (float) $order->get_total() > 0 ) {
			$order->update_status(
				$this->get_process_payment_status( $order ),
				sprintf(
					/* translators: %s: deposit amount. */
					__( 'Partial payment plan: deposit of %s awaited.', 'lovecatz-wc' ),
					LWC_Payment_Plan::instance()->format_amount_plain( $order, LWC_Payment_Plan::instance()->get_deposit_amount( $order ) )
				)
			);
		} else {
			$order->payment_complete();
		}

		if ( WC()->cart && $order->has_cart_hash( WC()->cart->get_cart_hash() ) ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Order status applied once a DP order is placed.
	 *
	 * @param WC_Order $order Order being processed.
	 * @return string Status slug without the `wc-` prefix.
	 */
	private function get_process_payment_status( $order ) {
		$configured = (string) get_option( LWC_Payment_Plan::OPT_DP_STATUS, 'on-hold' );
		$available  = LWC_Payment_Plan::get_available_order_statuses();

		$status = array_key_exists( $configured, $available ) ? $configured : 'on-hold';

		/**
		 * Filter the order status a partial payment checkout produces.
		 *
		 * @param string   $status Default status slug.
		 * @param WC_Order $order  Order being processed.
		 */
		return (string) apply_filters( 'lwc_dp_process_payment_order_status', $status, $order );
	}
}
