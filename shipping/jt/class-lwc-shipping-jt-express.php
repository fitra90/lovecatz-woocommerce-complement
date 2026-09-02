<?php
/**
 * J&T Express shipping method for LoveCatz WooCommerce Complement.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * J&T Express parcel courier. Separate API provider from J&T Cargo.
 */
class LWC_Shipping_JT_Express extends LWC_Shipping_JT_Base {

	/**
	 * Provider slug.
	 *
	 * @var string
	 */
	protected $provider = 'express';

	/**
	 * Express parcels auto-split above this weight by default.
	 *
	 * @var float
	 */
	protected $default_threshold_kg = 10;

	/**
	 * Provider identity and copy.
	 *
	 * @return array
	 */
	protected function get_provider_config() {
		return array(
			'title'                => __( 'J&T Express', 'lovecatz-wc' ),
			'description'          => __( 'J&T Express shipping method for regular parcel delivery', 'lovecatz-wc' ),
			'checkout_description' => __( 'Deliver via J&T Express', 'lovecatz-wc' ),
			'weight_description'   => sprintf(
				/* translators: %s: weight limit */
				__( 'Parcels heavier than this are split into multiple packages. Capped at the J&T Express limit of %s kg.', 'lovecatz-wc' ),
				'100'
			),
		);
	}

	/**
	 * Carrier-specific maximum weight per package in kilograms.
	 *
	 * @return float
	 */
	protected function get_provider_weight_ceiling() {
		return (float) apply_filters( 'lwc_jt_express_package_weight_ceiling_kg', 100 );
	}

	/** Express price and parcel rules come from the live provider settings. */
	public function init_form_fields() {
		parent::init_form_fields();
		unset( $this->form_fields['description'], $this->form_fields['flat_cost'], $this->form_fields['max_package_weight_kg'] );
	}

	/** Calculate live J&T rates for a zone instance. */
	public function calculate_shipping( $package = array() ) {
		if ( ! $this->is_available( $package ) ) {
			return;
		}
		foreach ( $this->build_checkout_rates( $package ) as $rate ) {
			$this->add_rate( $rate );
		}
	}

	/** Append J&T Express to domestic checkout without requiring a zone. */
	public function inject_global_rates( $rates, $package ) {
		if ( ! is_array( $rates ) || ! $this->is_available( $package ) ) {
			return $rates;
		}
		foreach ( $rates as $rate ) {
			if ( $rate instanceof WC_Shipping_Rate && 'lwc_jt_express' === $rate->get_method_id() ) {
				return $rates;
			}
		}

		foreach ( $this->build_checkout_rates( $package ) as $args ) {
			$rate = new WC_Shipping_Rate( $args['id'], $args['label'], $args['cost'], array(), 'lwc_jt_express' );
			foreach ( $args['meta_data'] as $key => $value ) {
				$rate->add_meta_data( $key, $value );
			}
			$rates[ $rate->get_id() ] = $rate;
		}
		return $rates;
	}

	/** Build live tariff rates, optionally falling back when the API is unavailable. */
	private function build_checkout_rates( $package ) {
		$environment = 'production' === get_option( 'lwc_jt_express_environment', 'sandbox' ) ? 'production' : 'sandbox';
		$destination = isset( $package['destination'] ) ? (array) $package['destination'] : array();
		$postcode    = isset( $destination['postcode'] ) ? trim( (string) $destination['postcode'] ) : '';
		$state       = isset( $destination['state'] ) ? $destination['state'] : '';
		$city        = isset( $destination['city'] ) ? $destination['city'] : '';
		$district    = isset( $destination['lwc_indonesia_district'] ) ? $destination['lwc_indonesia_district'] : '';
		if ( '' === $district && class_exists( 'LWC_Indonesia_Regions' ) ) {
			$district = LWC_Indonesia_Regions::region_cookie_district( $state, $city, 'shipping' );
		}
		$route       = LWC_JT_Route_Mapper::resolve( $postcode, $environment, $state, $city, $district );
		$origin      = LWC_JT_Route_Mapper::get_origin_route( $environment );
		$weight      = $this->get_package_weight_kg( $package );
		if ( ! LWC_JT_Request_Validator::is_valid_postcode( $postcode ) || is_wp_error( $route ) || is_wp_error( $origin ) ) {
			return array();
		}

		$result = ( new LWC_JT_Express_API() )->get_tariff(
			$weight,
			$origin['tariff_city'],
			$route['tariff_area']
		);
		if ( is_wp_error( $result ) || empty( $result['services'] ) ) {
			return array();
		}
		$quotes = $result['services'];

		$rates = array();
		foreach ( $quotes as $quote ) {
			$service = ! empty( $quote['name'] ) ? strtoupper( sanitize_text_field( $quote['name'] ) ) : 'EZ';
			$cost    = isset( $quote['cost'] ) ? (float) $quote['cost'] : 0;
			if ( class_exists( 'LWC_Currency_Converter' ) ) {
				$cost = LWC_Currency_Converter::round_for_currency( $cost );
			}
			$meta = array(
				'_lwc_jt_provider'              => 'express',
				'_lwc_jt_environment'           => $environment,
				'_lwc_jt_service'               => $service,
				'_lwc_jt_weight_kg'             => $weight,
				'_lwc_jt_rate_source'           => 'live_tariff',
				'_lwc_jt_route_source'          => $route['source'],
				'_lwc_jt_origin_city_code'      => $origin['city_code'],
				'_lwc_jt_destination_city_code' => $route['destination_city_code'],
				'_lwc_jt_destination_area_code' => $route['destination_area_code'],
			);
			$rates[] = array(
				'id'        => $this->get_rate_id() . ':' . strtolower( $service ),
				'label'     => sprintf( '%1$s %2$s', $this->title, $service ),
				'cost'      => $cost,
				'calc_tax'  => 'per_order',
				'meta_data' => $meta,
			);
		}
		return $rates;
	}

	private function get_package_weight_kg( $package ) {
		$weight = 0.0;
		foreach ( isset( $package['contents'] ) ? (array) $package['contents'] : array() as $item ) {
			if ( empty( $item['data'] ) || ! is_object( $item['data'] ) ) {
				continue;
			}
			$quantity = isset( $item['quantity'] ) ? max( 1, (float) $item['quantity'] ) : 1;
			$item_weight = (float) $item['data']->get_weight();
			$weight += (float) wc_get_weight( $item_weight * $quantity, 'kg' );
		}
		return max( 0.01, round( $weight, 2 ) );
	}
}
