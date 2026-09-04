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
	 * Lazily-created global FedEx method.
	 *
	 * @var LWC_Shipping_FedEx|null
	 */
	private $fedex_global_method = null;

	/** @var LWC_Shipping_JT_Express|null */
	private $jt_express_global_method = null;

	/** @var LWC_Shipping_RaySpeed|null */
	private $rayspeed_global_method = null;

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

		if ( file_exists( LWC_PLUGIN_DIR . 'promo/class-lwc-promo-discounts.php' ) ) {
			require_once LWC_PLUGIN_DIR . 'promo/class-lwc-promo-discounts.php';
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

		if ( file_exists( LWC_PLUGIN_DIR . 'includes/core/class-lwc-currency-converter.php' ) ) {
					require_once LWC_PLUGIN_DIR . 'includes/core/class-lwc-currency-converter.php';
		}

		if ( file_exists( LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-fedex-order-admin.php' ) ) {
					require_once LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-fedex-order-admin.php';
		}
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		if ( class_exists( 'LWC_Promo_Discounts' ) ) {
			( new LWC_Promo_Discounts() )->init();
		}

		if ( is_admin() ) {
			$admin_settings = new LWC_Admin_Settings();
			$admin_settings->init();
			$promo_admin = new LWC_Promo_Admin();
			$promo_admin->init();

		} else {
			$promo_dashboard = new LWC_Promo_Dashboard();
			$promo_dashboard->init();
		}

		// Order fulfillment hooks include both the admin metabox and customer
		// My Account tracking, so they must be registered on every request.
		if ( class_exists( 'LWC_FedEx_Order_Admin' ) ) {
			$fedex_order_admin = new LWC_FedEx_Order_Admin();
			$fedex_order_admin->init();
		}
		if ( class_exists( 'LWC_RaySpeed_Order_Admin' ) ) {
			( new LWC_RaySpeed_Order_Admin() )->init();
		}
		if ( class_exists( 'LWC_JT_Order_Admin' ) ) {
			( new LWC_JT_Order_Admin() )->init();
		}

		// Product quantity limits — load from organized path.
		if ( file_exists( LWC_PLUGIN_DIR . 'products/class-lwc-product-quantity-limits.php' ) ) {
			require_once LWC_PLUGIN_DIR . 'products/class-lwc-product-quantity-limits.php';
			$quantity_limits = new LWC_Product_Quantity_Limits();
			$quantity_limits->init();
		}

		// Publish built-in FedEx rates for international packages even when the
		// merchant has not added FedEx to a WooCommerce shipping zone.
		if ( class_exists( 'LWC_Shipping_FedEx' ) ) {
			add_filter( 'woocommerce_package_rates', array( $this, 'add_global_fedex_rates' ), 5, 2 );
		}
		if ( class_exists( 'LWC_Shipping_RaySpeed' ) ) {
			add_filter( 'woocommerce_package_rates', array( $this, 'add_global_rayspeed_rates' ), 6, 2 );
		}
		if ( class_exists( 'LWC_Shipping_JT_Express' ) ) {
			add_filter( 'woocommerce_package_rates', array( $this, 'add_global_jt_express_rates' ), 7, 2 );
		}

		// Built-in currency converter (idle when an external one is active).
		if ( class_exists( 'LWC_Currency_Converter' ) ) {
			LWC_Currency_Converter::instance()->init();
		}

		// Include the plugin version in WooCommerce's package hash. This clears
		// stale session results (including a previously cached empty rate list)
		// whenever the shipping implementation changes in a plugin update.
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'add_shipping_cache_version' ) );

		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_shipping_methods' ) );
	}

	/**
	 * Add a harmless cache marker to each cart shipping package.
	 *
	 * @param array $packages Cart shipping packages.
	 * @return array
	 */
	public function add_shipping_cache_version( $packages ) {
		if ( ! is_array( $packages ) ) {
			return $packages;
		}

		foreach ( $packages as $key => $package ) {
			$packages[ $key ]['lwc_shipping_version'] = LWC_VERSION;
		}

		return $packages;
	}

	/**
	 * Add international FedEx rates without requiring a WooCommerce zone.
	 * The method is constructed only when WooCommerce is calculating rates.
	 *
	 * @param array $rates   Existing package rates.
	 * @param array $package Current shipping package.
	 * @return array
	 */
	public function add_global_fedex_rates( $rates, $package ) {
		if ( null === $this->fedex_global_method ) {
			$this->fedex_global_method = new LWC_Shipping_FedEx();
		}

		return $this->fedex_global_method->inject_global_rates( $rates, $package );
	}

	/** Add RaySpeed rates globally for international packages. */
	public function add_global_rayspeed_rates( $rates, $package ) {
		if ( null === $this->rayspeed_global_method ) {
			$this->rayspeed_global_method = new LWC_Shipping_RaySpeed();
		}
		return $this->rayspeed_global_method->inject_global_rates( $rates, $package );
	}

	/** Add J&T Express globally for domestic Indonesian packages. */
	public function add_global_jt_express_rates( $rates, $package ) {
		if ( null === $this->jt_express_global_method ) {
			$this->jt_express_global_method = new LWC_Shipping_JT_Express();
		}
		return $this->jt_express_global_method->inject_global_rates( $rates, $package );
	}

	/**
	 * Register the available shipping methods.
	 *
	 * @param array $methods Array of shipping methods.
	 * @return array
	 */
	public function register_shipping_methods( $methods ) {
		LWC_Logger::log( 'Registering shipping methods.', 'info' );
		$methods['lwc_jt_express'] = 'LWC_Shipping_JT_Express';
		$methods['lwc_jt_cargo'] = 'LWC_Shipping_JT_Cargo';
		// Legacy alias: pre-split J&T zone instances keep working as Express.
		$methods['lwc_jt'] = 'LWC_Shipping_JT_Express';
		$methods['lwc_fedex'] = 'LWC_Shipping_FedEx';
		$methods['lwc_rayspeed'] = 'LWC_Shipping_RaySpeed';
		return $methods;
	}
}


