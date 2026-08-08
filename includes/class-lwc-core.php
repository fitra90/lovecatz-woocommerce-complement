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

		// Prefer feature folders; fall back to legacy includes/ paths for safety.
		if ( file_exists( LWC_PLUGIN_DIR . 'membership/class-lwc-admin-settings.php' ) ) {
			require_once LWC_PLUGIN_DIR . 'membership/class-lwc-admin-settings.php';
		} else {
			require_once LWC_PLUGIN_DIR . 'includes/class-lwc-admin-settings.php';
		}

		if ( file_exists( LWC_PLUGIN_DIR . 'shipping/class-lwc-shipping-jt.php' ) ) {
			require_once LWC_PLUGIN_DIR . 'shipping/class-lwc-shipping-jt.php';
		} else {
			require_once LWC_PLUGIN_DIR . 'includes/class-lwc-shipping-jt.php';
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

		// Register shipping method.
		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_jt_shipping_method' ) );
	}

	/**
	 * Register the J&T Express shipping method.
	 *
	 * @param array $methods Array of shipping methods.
	 * @return array
	 */
	public function register_jt_shipping_method( $methods ) {
		LWC_Logger::log( 'Registering J&T Express shipping method.', 'info' );
		$methods['lwc_jt'] = 'WC_Shipping_J_And_T';
		return $methods;
	}
}
