<?php
/**
 * Apply an optional buyer-paid fee to PayPal checkouts.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_PayPal_Admin_Fee {
	const OFFICIAL_PLUGIN = 'woocommerce-paypal-payments/woocommerce-paypal-payments.php';

	/** Register checkout hooks. */
	public function init() {
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_fee' ), 20 );
	}

	/**
	 * Whether the official WooCommerce PayPal Payments plugin is active.
	 *
	 * @return bool
	 */
	public static function is_official_plugin_active() {
		if ( defined( 'PPCP_PLUGIN_FILE' ) || defined( 'PPCP_VERSION' ) ) {
			return true;
		}

		$active_plugins = (array) get_option( 'active_plugins', array() );
		if ( in_array( self::OFFICIAL_PLUGIN, $active_plugins, true ) ) {
			return true;
		}

		return is_multisite() && array_key_exists( self::OFFICIAL_PLUGIN, (array) get_site_option( 'active_sitewide_plugins', array() ) );
	}

	/**
	 * Whether the official plugin files exist, even if the plugin is inactive.
	 *
	 * @return bool
	 */
	public static function is_official_plugin_installed() {
		return defined( 'WP_PLUGIN_DIR' ) && is_file( WP_PLUGIN_DIR . '/' . self::OFFICIAL_PLUGIN );
	}

	/**
	 * Add the configured fee when a PayPal gateway is selected.
	 *
	 * @param WC_Cart $cart Current cart.
	 */
	public function add_fee( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! self::is_official_plugin_active() || 'yes' !== get_option( 'lwc_paypal_fee_enabled', 'no' ) || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$gateway_id = (string) WC()->session->get( 'chosen_payment_method', '' );
		if ( ! $this->is_paypal_gateway( $gateway_id ) ) {
			return;
		}

		$configured_amount = max( 0, (float) get_option( 'lwc_paypal_fee_amount', 0 ) );
		if ( $configured_amount <= 0 ) {
			return;
		}

		if ( 'fixed' === get_option( 'lwc_paypal_fee_type', 'percentage' ) ) {
			$fee = $configured_amount;
			if ( class_exists( 'LWC_Currency_Converter' ) && LWC_Currency_Converter::instance()->is_active() ) {
				$converted_fee = LWC_Currency_Converter::instance()->convert_amount( $fee );
				$fee           = null === $converted_fee ? $fee : $converted_fee;
			}
		} else {
			$base = (float) $cart->get_cart_contents_total()
				+ (float) $cart->get_cart_contents_tax()
				+ (float) $cart->get_shipping_total()
				+ (float) $cart->get_shipping_tax();
			$fee = $base * min( 100, $configured_amount ) / 100;
		}

		$fee = (float) wc_format_decimal( $fee, wc_get_price_decimals() );
		if ( $fee <= 0 ) {
			return;
		}

		$label = trim( (string) get_option( 'lwc_paypal_fee_label', __( 'PayPal admin fee', 'lovecatz-wc' ) ) );
		$cart->add_fee( '' !== $label ? $label : __( 'PayPal admin fee', 'lovecatz-wc' ), $fee, false );
	}

	/**
	 * Recognize WooCommerce core and PayPal Payments gateway identifiers.
	 *
	 * @param string $gateway_id Selected gateway ID.
	 * @return bool
	 */
	private function is_paypal_gateway( $gateway_id ) {
		$gateway_id = strtolower( sanitize_key( $gateway_id ) );

		return 'paypal' === $gateway_id
			|| 0 === strpos( $gateway_id, 'ppcp-' )
			|| false !== strpos( $gateway_id, 'paypal' );
	}
}
