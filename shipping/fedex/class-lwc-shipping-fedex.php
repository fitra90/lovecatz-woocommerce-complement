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

		// Offer rates worldwide from the plugin settings page even when the
		// method was never added to a WooCommerce shipping zone.
		add_filter( 'woocommerce_shipping_packages', array( $this, 'inject_global_rates' ) );

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
				'default' => 'yes',
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
		return 'yes' === get_option( 'lwc_fedex_enabled', 'yes' );
	}

	/**
	 * Availability follows the plugin settings toggle instead of the
	 * WooCommerce per-zone enable checkbox.
	 *
	 * @param array $package Shipping package data.
	 * @return bool
	 */
	public function is_available( $package = array() ) {
		return $this->is_plugin_enabled();
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
		if ( ! $this->is_plugin_enabled() ) {
			return;
		}

		foreach ( $this->build_rates_for_package( $package ) as $rate ) {
			$this->add_rate( $rate );
		}
	}

	/**
	 * Append FedEx rates to every cart package when the method is not
	 * managed through a WooCommerce shipping zone.
	 *
	 * @param array $packages Cart packages.
	 * @return array
	 */
	public function inject_global_rates( $packages ) {
		if ( ! $this->is_plugin_enabled() || ! is_array( $packages ) ) {
			return $packages;
		}

		foreach ( $packages as $key => $package ) {
			if ( ! empty( $package['rates'] ) ) {
				foreach ( $package['rates'] as $rate ) {
					if ( $rate instanceof WC_Shipping_Rate && 'lwc_fedex' === $rate->get_method_id() ) {
						// A zone instance already provides FedEx rates.
						continue 2;
					}
				}
			}

			$rates = $this->build_rates_for_package( $package );
			if ( empty( $rates ) ) {
				continue;
			}

			if ( ! isset( $packages[ $key ]['rates'] ) || ! is_array( $packages[ $key ]['rates'] ) ) {
				$packages[ $key ]['rates'] = array();
			}

			foreach ( $rates as $rate_args ) {
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

				$packages[ $key ]['rates'][ $rate_args['id'] ] = $rate;
			}
		}

		return $packages;
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
		$quotes = $this->get_live_quotes( $package, $max_package_weight_kg );

		if ( ! empty( $quotes ) ) {
			$enabled = $this->get_enabled_services();
			$rates = array();

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
				return $rates;
			}
		}

		if ( 'yes' !== $this->get_option( 'fallback_enabled', 'yes' ) ) {
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
	 * Get the service types offered at checkout.
	 *
	 * Reads the plugin settings page selection; an empty selection falls
	 * back to the default set.
	 *
	 * @return string[]
	 */
	private function get_enabled_services() {
		$services = (array) get_option( 'lwc_fedex_services', array() );

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
