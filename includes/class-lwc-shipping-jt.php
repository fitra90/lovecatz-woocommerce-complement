<?php
/**
 * J&T Express Shipping Method Class.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'WC_Shipping_Method' ) ) {

	class WC_Shipping_J_And_T extends WC_Shipping_Method {

		/**
		 * Constructor for your shipping class
		 *
		 * @access public
		 * @return void
		 */
		public function __construct( $instance_id = 0 ) {
			$this->id                 = 'lwc_jt';
			$this->instance_id        = absint( $instance_id );
			$this->method_title       = __( 'J&T Express', 'lovecatz-wc' );
			$this->method_description = __( 'J&T Express Shipping Method integration via API.', 'lovecatz-wc' );

			$this->supports           = array(
				'shipping-zones',
				'instance-settings',
				'instance-settings-modal',
			);

			$this->init();
		}

		/**
		 * Init your settings
		 *
		 * @access public
		 * @return void
		 */
		public function init() {
			// Load the settings API
			$this->init_form_fields();
			$this->init_settings();

			// Define user set variables
			$this->title = $this->get_option( 'title', $this->method_title );

			// Save settings in admin if you have any defined
			add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
		}

		/**
		 * Init form fields for this shipping method instance.
		 */
		public function init_form_fields() {
			$this->instance_form_fields = array(
				'title' => array(
					'title'       => __( 'Method Title', 'lovecatz-wc' ),
					'type'        => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'lovecatz-wc' ),
					'default'     => __( 'J&T Express', 'lovecatz-wc' ),
					'desc_tip'    => true,
				),
				// Specific settings like origin city/district could go here per zone instance
				'markup' => array(
					'title'       => __( 'Price Markup', 'lovecatz-wc' ),
					'type'        => 'text',
					'description' => __( 'Optional extra fee to add to the shipping cost.', 'lovecatz-wc' ),
					'default'     => '0',
					'desc_tip'    => true,
				)
			);
		}

		/**
		 * Calculate_shipping function.
		 *
		 * @access public
		 * @param array $package Package information.
		 * @return void
		 */
		public function calculate_shipping( $package = array() ) {
			
			// Get API credentials from global settings
			$api_key    = get_option( 'lwc_jt_api_key' );
			$api_secret = get_option( 'lwc_jt_api_secret' );
			$test_mode  = get_option( 'lwc_jt_test_mode', 'no' );

			LWC_Logger::log( 'Calculating J&T Shipping rates. Test Mode: ' . $test_mode, 'info' );

			if ( empty( $api_key ) || empty( $api_secret ) ) {
				LWC_Logger::log( 'J&T API Key or Secret is missing.', 'error' );
				return;
			}

			// TODO: Implement actual API call to J&T to get rate based on $package info.
			// Example placeholder logic:
			$cost = 15.00; // Placeholder fixed cost for now
			$markup = floatval( $this->get_option( 'markup', 0 ) );
			$total_cost = $cost + $markup;

			$rate = array(
				'id'    => $this->get_rate_id(),
				'label' => $this->title,
				'cost'  => $total_cost,
				'calc_tax' => 'per_item'
			);

			// Register the rate
			$this->add_rate( $rate );
			
			LWC_Logger::log( 'Added J&T rate: ' . $total_cost, 'info' );
		}
	}
}
