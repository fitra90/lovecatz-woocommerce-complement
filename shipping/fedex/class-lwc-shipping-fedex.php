<?php
/**
 * FedEx Shipping Method for LoveCatz WooCommerce Complement.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LWC_Shipping_FedEx class extends WC_Shipping_Method
 */
class LWC_Shipping_FedEx extends WC_Shipping_Method {

	/**
	 * Constructor for FedEx shipping method.
	 *
	 * @param int $instance_id Shipping method instance ID.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'lwc_fedex';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'FedEx', 'lovecatz-wc' );
		$this->method_description = __( 'FedEx shipping method for international delivery', 'lovecatz-wc' );
		$this->supports           = array( 'shipping-zones', 'instance-settings' );

		parent::__construct( $instance_id );
		$this->init();
	}

	/**
	 * Initialize shipping method settings.
	 */
	public function init() {
		$this->init_form_fields();
		$this->init_settings();
	}

	/**
	 * Define shipping method form fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'      => array(
				'title'   => __( 'Enable/Disable', 'lovecatz-wc' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable FedEx Shipping', 'lovecatz-wc' ),
				'default' => 'no',
			),
			'title'        => array(
				'title'       => __( 'Method Title', 'lovecatz-wc' ),
				'type'        => 'text',
				'description' => __( 'This controls the title displayed to the user during checkout.', 'lovecatz-wc' ),
				'default'     => __( 'FedEx', 'lovecatz-wc' ),
				'desc_tip'    => true,
			),
			'description'  => array(
				'title'       => __( 'Method Description', 'lovecatz-wc' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description displayed to the user during checkout.', 'lovecatz-wc' ),
				'default'     => __( 'Deliver via FedEx', 'lovecatz-wc' ),
				'desc_tip'    => true,
			),
			'fallback_enabled' => array(
				'title'   => __( 'Fallback flat rate', 'lovecatz-wc' ),
				'type'    => 'checkbox',
				'label'   => __( 'Offer a flat fallback rate when the FedEx live quote fails', 'lovecatz-wc' ),
				'default' => 'yes',
			),
			'fallback_cost' => array(
				'title'       => __( 'Fallback cost', 'lovecatz-wc' ),
				'type'        => 'price',
				'description' => __( 'Used when the live FedEx quote is unavailable.', 'lovecatz-wc' ),
				'default'     => 25,
				'desc_tip'    => true,
			),
			'services' => array(
				'title'             => __( 'FedEx services', 'lovecatz-wc' ),
				'type'              => 'multiselect',
				'class'             => 'wc-enhanced-select',
				'css'               => 'width: 400px;',
				'options'           => class_exists( 'LWC_FedEx_API' ) ? LWC_FedEx_API::get_available_services() : array(),
				'custom_attributes' => array( 'data-placeholder' => __( 'Select services…', 'lovecatz-wc' ) ),
				'description'       => __( 'Services offered at checkout. Every selected service that FedEx prices becomes its own shipping option; leave empty to use the defaults.', 'lovecatz-wc' ),
				'default'           => array( 'FEDEX_GROUND', 'FEDEX_EXPRESS_SAVER', 'INTERNATIONAL_ECONOMY', 'INTERNATIONAL_PRIORITY' ),
			),
			'max_package_weight_kg' => array(
				'title'       => __( 'Max package weight (kg)', 'lovecatz-wc' ),
				'type'        => 'number',
				'description' => __( 'Carts heavier than this are split into multiple packages for rating and labels. Capped at the FedEx limit of 68 kg. Set 0 to always quote one package.', 'lovecatz-wc' ),
				'default'     => 10,
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Calculate shipping rates using the FedEx API, with a flat fallback.
	 *
	 * Every enabled service returned by FedEx is added as its own checkout
	 * option so customers can choose between them.
	 *
	 * @param array $package Shipping package data.
	 */
	public function calculate_shipping( $package = array() ) {
		$max_package_weight_kg = (float) $this->get_option( 'max_package_weight_kg', 10 );
		$quotes = $this->get_live_quotes( $package, $max_package_weight_kg );

		if ( ! empty( $quotes ) ) {
			$enabled = $this->get_enabled_services();
			$added = 0;

			foreach ( $quotes as $quote ) {
				if ( ! empty( $enabled ) && ! in_array( $quote['service_type'], $enabled, true ) ) {
					continue;
				}

				$cost = $this->prepare_rate_cost( (float) $quote['rate'] );
				if ( null === $cost ) {
					continue;
				}

				$this->add_rate(
					array(
						'id'       => $this->get_rate_id() . ':' . strtolower( str_replace( '_', '', $quote['service_type'] ) ),
						'label'    => $quote['label'],
						'cost'     => $cost,
						'calc_tax' => 'per_order',
						// Transferred to the order so label creation reuses the
						// chosen service and package weight.
						'meta_data' => array(
							'lwc_fedex_service' => $quote['service_type'],
							'lwc_fedex_max_weight' => $max_package_weight_kg,
						),
					)
				);
				$added++;
			}

			if ( $added > 0 ) {
				return;
			}
		}

		if ( 'yes' !== $this->get_option( 'fallback_enabled', 'yes' ) ) {
			return;
		}

		$cost = $this->prepare_rate_cost( (float) $this->get_option( 'fallback_cost', 25 ) );
		if ( null === $cost ) {
			return;
		}

		$this->add_rate(
			array(
				'id'       => $this->get_rate_id(),
				'label'    => $this->title,
				'cost'     => $cost,
				'calc_tax' => 'per_order',
			)
		);
	}

	/**
	 * Round and convert a live quote into the active checkout currency.
	 *
	 * @param float $cost Rate cost in the FedEx base currency.
	 * @return float|null Null when the cost is not usable.
	 */
	private function prepare_rate_cost( $cost ) {
		if ( ! is_numeric( $cost ) || $cost <= 0 ) {
			return null;
		}

		// Providers such as J&T take whole IDR amounts; round to the active
		// currency's decimals so zero-decimal currencies stay integer-safe.
		if ( class_exists( 'LWC_Currency_Converter' ) ) {
			$cost = LWC_Currency_Converter::round_for_currency( $cost );
		}

		return $this->maybe_convert_rate_cost( $cost );
	}

	/**
	 * Request live rates from the FedEx API.
	 *
	 * @param array $package Shipping package data.
	 * @param float $max_package_weight_kg Split packages above this weight.
	 * @return array[] Quotes with service_type/label/rate keys.
	 */
	private function get_live_quotes( $package, $max_package_weight_kg ) {
		if ( ! class_exists( 'LWC_FedEx_API' ) ) {
			return array();
		}

		$api = new LWC_FedEx_API();
		$result = $api->get_rate_quotes( $package, $max_package_weight_kg );

		if ( empty( $result['success'] ) || empty( $result['quotes'] ) || ! is_array( $result['quotes'] ) ) {
			if ( class_exists( 'LWC_Logger' ) ) {
				$message = isset( $result['message'] ) ? $result['message'] : __( 'unknown error', 'lovecatz-wc' );
				LWC_Logger::log( 'FedEx live rate failed: ' . $message, 'warning', 'lovecatz-wc' );
			}
			return array();
		}

		return $result['quotes'];
	}

	/**
	 * Get the service types this instance offers at checkout.
	 *
	 * An empty selection falls back to the default set.
	 *
	 * @return string[]
	 */
	private function get_enabled_services() {
		$services = $this->get_option( 'services', array() );

		if ( is_string( $services ) ) {
			$services = array_filter( array_map( 'trim', explode( ',', $services ) ) );
		}

		$services = array_filter( array_map( 'strtoupper', (array) $services ) );

		if ( empty( $services ) ) {
			$services = array( 'FEDEX_GROUND', 'FEDEX_EXPRESS_SAVER', 'INTERNATIONAL_ECONOMY', 'INTERNATIONAL_PRIORITY' );
		}

		return $services;
	}

	/**
	 * Convert a rate from the FedEx base currency to the active checkout
	 * currency using the configured manual exchange rate.
	 *
	 * Skipped when a global converter owns conversion: the built-in
	 * LWC_Currency_Converter or woo-multi-currency converts every shipping
	 * method already, so converting here as well would double the amount.
	 *
	 * @param float $cost Rate cost in the FedEx base currency.
	 * @return float
	 */
	private function maybe_convert_rate_cost( $cost ) {
		if ( class_exists( 'LWC_Currency_Converter' ) && LWC_Currency_Converter::instance()->is_active() ) {
			return $cost;
		}

		if ( ! class_exists( 'LWC_FedEx_Currency_Adapter' ) || ! function_exists( 'get_woocommerce_currency' ) ) {
			return $cost;
		}

		$adapter = new LWC_FedEx_Currency_Adapter();

		if ( LWC_FedEx_Currency_Adapter::MODE_MANUAL !== $adapter->get_conversion_mode() ) {
			return $cost;
		}

		$base_currency = $adapter->get_base_currency();
		$active_currency = strtoupper( (string) get_woocommerce_currency() );

		if ( '' === $base_currency || $base_currency === $active_currency ) {
			return $cost;
		}

		$manual_rate = $adapter->get_manual_rate();
		if ( $manual_rate <= 0 || $cost <= 0 ) {
			return $cost;
		}

		$decimals = class_exists( 'LWC_Currency_Converter' )
			? LWC_Currency_Converter::get_currency_decimals( $active_currency )
			: ( function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2 );

		return round( $cost / $manual_rate, $decimals );
	}
}
