<?php
/**
 * Core class for LoveCatz WooCommerce Complement.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_Core {

	/**
	 * Initialize the core functionality.
	 */
	public function init() {
		LWC_Logger::log( 'Initializing LoveCatz WooCommerce Complement core.', 'info' );

		$this->includes();
		$this->init_hooks();
	}

	/**
	 * Include necessary files.
	 */
	private function includes() {
		LWC_Logger::log( 'Including plugin classes.', 'info' );

		if ( file_exists( LWC_PLUGIN_DIR . 'includes/admin/class-lwc-admin-settings.php' ) ) {
			require_once LWC_PLUGIN_DIR . 'includes/admin/class-lwc-admin-settings.php';
		}

		if ( file_exists( LWC_PLUGIN_DIR . 'promo/class-lwc-promo-dashboard.php' ) ) {
					require_once LWC_PLUGIN_DIR . 'promo/class-lwc-promo-dashboard.php';
		}

		if ( file_exists( LWC_PLUGIN_DIR . 'promo/class-lwc-promo-admin.php' ) ) {
			require_once LWC_PLUGIN_DIR . 'promo/class-lwc-promo-admin.php';
		}

		if ( file_exists( LWC_PLUGIN_DIR . 'membership/class-lwc-admin-members.php' ) ) {
			require_once LWC_PLUGIN_DIR . 'membership/class-lwc-admin-members.php';
		}

		if ( file_exists( LWC_PLUGIN_DIR . 'shipping/class-lwc-shipping-provider.php' ) ) {
			require_once LWC_PLUGIN_DIR . 'shipping/class-lwc-shipping-provider.php';
		}

		if ( file_exists( LWC_PLUGIN_DIR . 'shipping/jt/class-lwc-shipping-jt.php' ) ) {
					require_once LWC_PLUGIN_DIR . 'shipping/jt/class-lwc-shipping-jt.php';
		}

		if ( file_exists( LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-shipping-fedex.php' ) ) {
					require_once LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-shipping-fedex.php';
		}

		if ( file_exists( LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-fedex-account.php' ) ) {
					require_once LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-fedex-account.php';
		}

		if ( file_exists( LWC_PLUGIN_DIR . 'shipping/jt/class-lwc-jt-account.php' ) ) {
					require_once LWC_PLUGIN_DIR . 'shipping/jt/class-lwc-jt-account.php';
		}

		if ( file_exists( LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-fedex-api.php' ) ) {
					require_once LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-fedex-api.php';
		}

		if ( file_exists( LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-fedex-currency-adapter.php' ) ) {
					require_once LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-fedex-currency-adapter.php';
		}
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		if ( is_admin() ) {
			$admin_settings = new LWC_Admin_Settings();
			$admin_settings->init();
			$promo_admin = new LWC_Promo_Admin();
			$promo_admin->init();
		} else {
			$promo_dashboard = new LWC_Promo_Dashboard();
			$promo_dashboard->init();
		}

		// Product quantity limits — load from organized path.
		if ( file_exists( LWC_PLUGIN_DIR . 'products/class-lwc-product-quantity-limits.php' ) ) {
			require_once LWC_PLUGIN_DIR . 'products/class-lwc-product-quantity-limits.php';
			$quantity_limits = new LWC_Product_Quantity_Limits();
			$quantity_limits->init();
		}

		// FedEx currency adapter (engine selector + Octolize currency gate-fix).
		if ( class_exists( 'LWC_FedEx_Currency_Adapter' ) ) {
			$fedex_currency_adapter = new LWC_FedEx_Currency_Adapter();
			$fedex_currency_adapter->init();
		}

		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_shipping_methods' ) );
	}

	/**
	 * Register the available shipping methods.
	 *
	 * @param array $methods Array of shipping methods.
	 * @return array
	 */
	public function register_shipping_methods( $methods ) {
		LWC_Logger::log( 'Registering shipping methods.', 'info' );
		$methods['lwc_jt'] = 'LWC_Shipping_JT';
		$methods['lwc_fedex'] = 'LWC_Shipping_FedEx';
		return $methods;
	}
}


