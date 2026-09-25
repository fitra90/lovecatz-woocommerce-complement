<?php
/**
 * Single policy for automatic courier AWB creation on order receipt.
 *
 * Every courier's automatic creation goes through here so the rule is stated
 * once instead of being spread over the individual integrations:
 *
 *  - FedEx is **manual only**. The AWB carries the scheduled ship date, the
 *    actual carton weight and dimensions, and the printed label description
 *    (DESC1). FedEx prints those values while the AWB is created and the Ship
 *    API cannot update an issued label, so the AWB has to wait until the
 *    merchant has filled the order screen. Checkout only captures the chosen
 *    service type and tariff, which are stored on the order's shipping line.
 *  - Every other courier keeps creating its AWB automatically when the order
 *    is received (i.e. when it reaches the Processing status).
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_AWB_Automation {

	/**
	 * Couriers that must never create an AWB without the merchant.
	 *
	 * @var string[]
	 */
	const MANUAL_ONLY = array( 'fedex' );

	/**
	 * Couriers whose automatic creation is implemented in this plugin.
	 *
	 * `jt_cargo` is deliberately absent: the Cargo provider has no order
	 * creation call yet (its rates are hidden from checkout too), so there is
	 * nothing to dispatch to. `jne` belongs to the JNE plugin.
	 *
	 * @var string[]
	 */
	const AUTOMATIC = array( 'jt_express', 'rayspeed' );

	/**
	 * Register the order-status hook.
	 */
	public function init() {
		add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_create_awb' ), 10, 2 );
	}

	/**
	 * Courier slugs that are manual-only, filterable for future couriers.
	 *
	 * @return string[]
	 */
	public static function get_manual_only_couriers() {
		return (array) apply_filters( 'lwc_awb_manual_only_couriers', self::MANUAL_ONLY );
	}

	/**
	 * Whether this order's courier creates its AWB on its own.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	public static function should_auto_create( $order ) {
		if ( ! $order instanceof WC_Order || ! class_exists( 'LWC_Courier_Registry' ) ) {
			return false;
		}

		$courier = LWC_Courier_Registry::courier_for_order( $order );
		if ( '' === $courier ) {
			return false;
		}

		// FedEx never qualifies, whatever the caller asks for.
		if ( in_array( $courier, self::get_manual_only_couriers(), true ) ) {
			return false;
		}

		/**
		 * Filter whether a courier may create its AWB automatically.
		 *
		 * @param bool     $auto    Whether the courier is automated.
		 * @param string   $courier Courier slug.
		 * @param WC_Order $order   Order object.
		 */
		return (bool) apply_filters( 'lwc_awb_should_auto_create', in_array( $courier, self::AUTOMATIC, true ), $courier, $order );
	}

	/**
	 * Create the courier AWB for an order that just started processing.
	 *
	 * @param int           $order_id Order ID.
	 * @param WC_Order|null $order    Order object supplied by WooCommerce.
	 */
	public function maybe_create_awb( $order_id, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! self::should_auto_create( $order ) ) {
			return;
		}

		switch ( LWC_Courier_Registry::courier_for_order( $order ) ) {
			case 'jt_express':
				if ( class_exists( 'LWC_JT_Order_Admin' ) ) {
					( new LWC_JT_Order_Admin() )->create_on_processing( $order->get_id(), $order );
				}
				break;
			case 'rayspeed':
				if ( class_exists( 'LWC_RaySpeed_Order_Admin' ) ) {
					( new LWC_RaySpeed_Order_Admin() )->auto_create_awb( $order );
				}
				break;
			default:
				// No automatic creation path for this courier.
				break;
		}
	}
}
