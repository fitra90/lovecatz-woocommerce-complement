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
		$this->title = $this->get_option( 'title', __( 'FedEx', 'lovecatz-wc' ) );
	}

	/**
	 * Define shipping method form fields.
	 *
	 * Enable state, service selection, and the package weight threshold are
	 * managed on the LoveCatz settings page; only display and fallback
	 * options remain here.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
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
				'default' => 'no',
			),
			'fallback_cost' => array(
				'title'       => __( 'Fallback cost', 'lovecatz-wc' ),
				'type'        => 'price',
				'description' => __( 'Used when the live FedEx quote is unavailable.', 'lovecatz-wc' ),
				'default'     => 25,
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Whether FedEx checkout rates are switched on in the plugin settings.
	 *
	 * @return bool
	 */
	public function is_plugin_enabled() {
		if ( 'yes' !== get_option( 'lwc_fedex_enabled', 'yes' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Availability follows the plugin settings toggle instead of the
	 * WooCommerce per-zone enable checkbox.
	 *
	 * @param array $package Shipping package data.
	 * @return bool
	 */
	public function is_available( $package = array() ) {
		return $this->is_plugin_enabled() && $this->is_international_package( $package );
	}

	/**
	 * Package weight threshold configured on the plugin settings page.
	 *
	 * @return float
	 */
	public function resolve_max_package_weight() {
		return (float) get_option( 'lwc_fedex_max_package_weight_kg', 10 );
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
		if ( ! $this->is_available( $package ) ) {
			return;
		}

		foreach ( $this->build_rates_for_package( $package ) as $rate ) {
			$this->add_rate( $rate );
		}
	}

	/**
	 * Append FedEx rates after WooCommerce has calculated the matching zone.
	 * This makes FedEx the international default without requiring a zone.
	 *
	 * @param array $rates   Calculated package rates.
	 * @param array $package Current shipping package.
	 * @return array
	 */
	public function inject_global_rates( $rates, $package ) {
		if ( ! $this->is_available( $package ) || ! is_array( $rates ) ) {
			return $rates;
		}

		foreach ( $rates as $rate ) {
			if ( $rate instanceof WC_Shipping_Rate && 'lwc_fedex' === $rate->get_method_id() ) {
				// A zone instance already provided the built-in FedEx rates.
				return $rates;
			}
		}

		foreach ( $this->build_rates_for_package( $package ) as $rate_args ) {
			$rate = new WC_Shipping_Rate(
				$rate_args['id'],
				$rate_args['label'],
				$rate_args['cost'],
				array(),
				'lwc_fedex'
			);

			if ( ! empty( $rate_args['meta_data'] ) && is_array( $rate_args['meta_data'] ) ) {
				foreach ( $rate_args['meta_data'] as $meta_key => $meta_value ) {
					$rate->add_meta_data( $meta_key, $meta_value );
				}
			}

			$rates[ $rate_args['id'] ] = $rate;
		}

		return $rates;
	}

	/**
	 * FedEx is the default carrier outside Indonesia. Domestic packages are
	 * intentionally left to J&T and shipping-zone methods such as JNE.
	 *
	 * @param array $package Shipping package data.
	 * @return bool
	 */
	private function is_international_package( $package ) {
		$country = isset( $package['destination']['country'] )
			? strtoupper( trim( (string) $package['destination']['country'] ) )
			: '';

		return '' !== $country && 'ID' !== $country;
	}

	/**
	 * Build the rate definitions for one package: one per priced service,
	 * plus the flat fallback when the live quote fails.
	 *
	 * @param array $package Shipping package data.
	 * @return array[] add_rate argument arrays.
	 */
	private function build_rates_for_package( $package = array() ) {
		$max_package_weight_kg = $this->resolve_max_package_weight();
		$this->debug( 'shipping_method_start', array( 'instance_id' => $this->instance_id, 'country' => isset( $package['destination']['country'] ) ? $package['destination']['country'] : '' ) );
		$quotes = $this->get_live_quotes( $package, $max_package_weight_kg );

		if ( ! empty( $quotes ) ) {
			$enabled = $this->get_enabled_services();
			$rates = array();
			$this->debug( 'service_filter', array( 'enabled' => implode( ', ', $enabled ), 'returned' => implode( ', ', wp_list_pluck( $quotes, 'service_type' ) ) ) );

			foreach ( $quotes as $quote ) {
				if ( ! empty( $enabled ) && ! in_array( $quote['service_type'], $enabled, true ) ) {
					continue;
				}

				$cost = $this->prepare_rate_cost( (float) $quote['rate'] );
				if ( null === $cost ) {
					continue;
				}

				$rates[] = array(
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
				);
			}

			if ( ! empty( $rates ) ) {
				$this->debug( 'rates_published', array( 'count' => count( $rates ), 'services' => implode( ', ', wp_list_pluck( $rates, 'label' ) ) ) );
				return $rates;
			}

			$this->debug( 'services_filtered_out', array( 'reason' => 'Returned quotes did not match the selected service types.' ) );
		}

		if ( 'yes' !== $this->get_option( 'fallback_enabled', 'no' ) ) {
			$this->debug( 'no_rate_published', array( 'reason' => 'Live quote unavailable and fallback is disabled.' ) );
			return array();
		}

		$cost = $this->prepare_rate_cost( (float) $this->get_option( 'fallback_cost', 25 ) );
		if ( null === $cost ) {
			return array();
		}

		return array(
			array(
				'id'       => $this->get_rate_id(),
				'label'    => $this->title,
				'cost'     => $cost,
				'calc_tax' => 'per_order',
			),
		);
	}

	/**
	 * Validate and round a live quote for the active checkout currency.
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

		// A small base-currency fallback can round to zero after conversion;
		// never turn a failed FedEx quote into a misleading "Free" method.
		return $cost > 0 ? $cost : null;
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
	 * Get the service types offered at checkout.
	 *
	 * Reads the plugin settings page selection; an empty selection falls
	 * back to the default set.
	 *
	 * @return string[]
	 */
	private function get_enabled_services() {
		$services = (array) get_option( 'lwc_fedex_services', array() );
		$legacy_aliases = array(
			'FEDEX_STANDARD_OVERNIGHT'       => 'STANDARD_OVERNIGHT',
			'FEDEX_PRIORITY_OVERNIGHT'       => 'PRIORITY_OVERNIGHT',
			'FEDEX_FIRST_OVERNIGHT'          => 'FIRST_OVERNIGHT',
			'INTERNATIONAL_PRIORITY'         => 'FEDEX_INTERNATIONAL_PRIORITY',
			'INTERNATIONAL_PRIORITY_EXPRESS' => 'FEDEX_INTERNATIONAL_PRIORITY_EXPRESS',
			'INTERNATIONAL_CONNECT_PLUS'     => 'FEDEX_INTERNATIONAL_CONNECT_PLUS',
		);

		if ( is_string( $services ) ) {
			$services = array_filter( array_map( 'trim', explode( ',', $services ) ) );
		}

		$services = array_filter( array_map( 'strtoupper', (array) $services ) );
		$services = array_map(
			function ( $service ) use ( $legacy_aliases ) {
				return isset( $legacy_aliases[ $service ] ) ? $legacy_aliases[ $service ] : $service;
			},
			$services
		);

		if ( empty( $services ) ) {
			$services = array( 'FEDEX_GROUND', 'FEDEX_EXPRESS_SAVER', 'INTERNATIONAL_ECONOMY', 'FEDEX_INTERNATIONAL_PRIORITY' );
		}

		return $services;
	}

	/**
	 * Write a safe event to the opt-in checkout diagnostics.
	 *
	 * @param string $stage   Diagnostic stage.
	 * @param array  $context Safe diagnostic values.
	 */
	private function debug( $stage, $context = array() ) {
		if ( function_exists( 'lwc_fedex_checkout_debug_log' ) ) {
			lwc_fedex_checkout_debug_log( $stage, $context );
		}
	}
}
