<?php
/**
 * Apply an optional handling fee to eligible international orders.
 *
 * Eligibility is based on the checkout currency and/or shipping destination,
 * never on the payment method selected by the shopper.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_International_Order_Fee {
	const OFFICIAL_PLUGIN = 'woocommerce-paypal-payments/woocommerce-paypal-payments.php';

	const OPT_ENABLED       = 'lwc_international_fee_enabled';
	const OPT_MATCH_MODE    = 'lwc_international_fee_match_mode';
	const OPT_TRIGGER_USD   = 'lwc_international_fee_trigger_usd';
	const OPT_TRIGGER_ABROAD = 'lwc_international_fee_trigger_abroad';
	const OPT_TYPE          = 'lwc_international_fee_type';
	const OPT_AMOUNT        = 'lwc_international_fee_amount';
	const OPT_LABEL         = 'lwc_international_fee_label';

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
	 * Whether the main PayPal gateway is enabled in WooCommerce.
	 *
	 * @return bool
	 */
	public static function is_paypal_gateway_enabled() {
		if ( ! self::is_official_plugin_active() ) {
			return false;
		}

		if ( function_exists( 'WC' ) && WC() && WC()->payment_gateways() ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
			if ( isset( $gateways['ppcp-gateway'] ) ) {
				return 'yes' === $gateways['ppcp-gateway']->enabled;
			}
		}

		$settings = (array) get_option( 'woocommerce_ppcp-gateway_settings', array() );
		return 'yes' === ( isset( $settings['enabled'] ) ? $settings['enabled'] : 'no' );
	}

	/**
	 * Add the configured fee to an eligible international order.
	 *
	 * @param WC_Cart $cart Current cart.
	 */
	public function add_fee( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! self::is_paypal_gateway_enabled() || 'yes' !== get_option( self::OPT_ENABLED, 'no' ) || ! function_exists( 'WC' ) || ! WC()->customer ) {
			return;
		}

		if ( ! $this->order_matches_rule() ) {
			return;
		}

		$configured_amount = max( 0, (float) get_option( self::OPT_AMOUNT, 0 ) );
		if ( $configured_amount <= 0 ) {
			return;
		}

		if ( 'fixed' === get_option( self::OPT_TYPE, 'percentage' ) ) {
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

		$label = trim( (string) get_option( self::OPT_LABEL, __( 'International order handling', 'lovecatz-wc' ) ) );
		$cart->add_fee( '' !== $label ? $label : __( 'International order handling', 'lovecatz-wc' ), $fee, false );
	}

	/**
	 * Evaluate the configured currency and destination triggers.
	 *
	 * @return bool
	 */
	private function order_matches_rule() {
		$checks = array();

		if ( 'yes' === get_option( self::OPT_TRIGGER_USD, 'yes' ) ) {
			$checks[] = 'USD' === strtoupper( (string) get_woocommerce_currency() );
		}

		if ( 'yes' === get_option( self::OPT_TRIGGER_ABROAD, 'yes' ) ) {
			$country  = strtoupper( (string) WC()->customer->get_shipping_country() );
			$checks[] = '' !== $country && 'ID' !== $country;
		}

		if ( empty( $checks ) ) {
			return false;
		}

		if ( 'all' === get_option( self::OPT_MATCH_MODE, 'any' ) ) {
			return ! in_array( false, $checks, true );
		}

		return in_array( true, $checks, true );
	}
}
