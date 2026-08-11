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

		if ( file_exists( LWC_PLUGIN_DIR . 'includes/admin/class-lwc-admin-members.php' ) ) {
			require_once LWC_PLUGIN_DIR . 'includes/admin/class-lwc-admin-members.php';
		}

		if ( file_exists( LWC_PLUGIN_DIR . 'includes/shipping/class-lwc-shipping-provider.php' ) ) {
			require_once LWC_PLUGIN_DIR . 'includes/shipping/class-lwc-shipping-provider.php';
		}

		if ( file_exists( LWC_PLUGIN_DIR . 'includes/shipping/class-lwc-shipping-jt.php' ) ) {
			require_once LWC_PLUGIN_DIR . 'includes/shipping/class-lwc-shipping-jt.php';
		}

		if ( file_exists( LWC_PLUGIN_DIR . 'includes/shipping/class-lwc-shipping-fedex.php' ) ) {
			require_once LWC_PLUGIN_DIR . 'includes/shipping/class-lwc-shipping-fedex.php';
		}
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		// Initialize admin settings.
		if ( is_admin() ) {
			$admin_settings = new LWC_Admin_Settings();
			$admin_settings->init();
		}

		// Register shipping methods.
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
