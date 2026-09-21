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
class LWC_Shipping_JT_Express extends WC_Shipping_Method {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'lwc_jt_express';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'J&T Express', 'lovecatz-wc' );
		$this->method_description = __( 'J&T Express shipping method for regular parcel delivery', 'lovecatz-wc' );
		$this->supports           = array( 'shipping-zones', 'instance-settings' );
		parent::__construct( $instance_id );
		$this->init_form_fields();
		$this->init_settings();
		$this->title = $this->get_option( 'title', $this->method_title );
	}

	/** Express price and parcel rules come from the live provider settings. */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array( 'title' => __( 'Enable/Disable', 'lovecatz-wc' ), 'type' => 'checkbox', 'label' => __( 'Enable J&T Express Shipping', 'lovecatz-wc' ), 'default' => 'no' ),
			'title' => array( 'title' => __( 'Method Title', 'lovecatz-wc' ), 'type' => 'text', 'default' => __( 'J&T Express', 'lovecatz-wc' ) ),
		);
	}

	/** Restrict J&T Express to its configured Indonesian service area. */
	public function is_available( $package = array() ) {
		$country = isset( $package['destination']['country'] ) ? strtoupper( trim( (string) $package['destination']['country'] ) ) : '';
		if ( 'ID' !== $country || 'yes' !== get_option( 'lwc_jt_express_enabled', 'no' ) ) {
			return false;
		}
		if ( 'java' === get_option( 'lwc_jt_express_service_area', 'indonesia' ) && ! self::is_java_destination( $package ) ) {
			return false;
		}
		return 0 === $this->instance_id ? true : parent::is_available( $package );
	}

	/** Determine whether a destination belongs to one of Java's six provinces. */
	public static function is_java_destination( $package ) {
		$destination = isset( $package['destination'] ) ? (array) $package['destination'] : array();
		$country = isset( $destination['country'] ) ? strtoupper( trim( (string) $destination['country'] ) ) : '';
		if ( 'ID' !== $country ) {
			return false;
		}
		$state = isset( $destination['state'] ) ? strtoupper( trim( (string) $destination['state'] ) ) : '';
		$state = trim( preg_replace( '/[^A-Z0-9]+/', ' ', preg_replace( '/^ID[-_]/', '', $state ) ) );
		return in_array( $state, array( 'BT', 'JK', 'JB', 'JT', 'YO', 'JI', 'BANTEN', 'DKI JAKARTA', 'JAKARTA', 'JAWA BARAT', 'JAWA TENGAH', 'JAWA TIMUR', 'DI YOGYAKARTA', 'DAERAH ISTIMEWA YOGYAKARTA', 'DIY', 'YOGYAKARTA' ), true );
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
