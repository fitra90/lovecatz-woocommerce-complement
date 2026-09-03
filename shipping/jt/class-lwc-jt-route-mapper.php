<?php
/** Resolve WooCommerce addresses to the red J&T Indonesia mapping columns. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_JT_Route_Mapper {
	/**
	 * Resolve one destination. Postcode is validated but A+B+C is the route key.
	 *
	 * @param string $postcode Indonesian five-digit postcode.
	 * @param string $environment sandbox|production.
	 * @param string $state Native WooCommerce state code.
	 * @param string $city Green-column city.
	 * @param string $district Green-column district.
	 * @return array|WP_Error
	 */
	public static function resolve( $postcode, $environment = 'sandbox', $state = '', $city = '', $district = '' ) {
		$postcode    = trim( (string) $postcode );
		$environment = 'production' === $environment ? 'production' : 'sandbox';
		if ( class_exists( 'LWC_JT_Request_Validator' ) && ! LWC_JT_Request_Validator::is_valid_postcode( $postcode ) ) {
			return new WP_Error( 'lwc_jt_invalid_postcode', __( 'J&T recipient postal code must contain exactly five digits.', 'lovecatz-wc' ) );
		}
		if ( '' === trim( (string) $district ) && class_exists( 'LWC_Indonesia_Regions' ) ) {
			$district = LWC_Indonesia_Regions::region_cookie_district( $state, $city, 'shipping' );
		}
		if ( ! class_exists( 'LWC_Indonesia_Regions' ) ) {
			return new WP_Error( 'lwc_jt_route_not_mapped', __( 'No J&T route directory is available.', 'lovecatz-wc' ) );
		}
		$mapped = LWC_Indonesia_Regions::find_region( $state, $city, $district );
		if ( ! $mapped ) {
			return new WP_Error( 'lwc_jt_route_not_mapped', __( 'The selected province, city, and district are not present in the official J&T mapping.', 'lovecatz-wc' ) );
		}
		$route = array(
			'destination_city_code' => $mapped['jt_city_code'], 'destination_area_code' => $mapped['jt_area_code'],
			'tariff_area' => $mapped['jt_district_name'], 'source' => 'official_jt_region_mapping',
		);
		$route = apply_filters( 'lwc_jt_express_destination_route', $route, $postcode, $environment, $state, $city, $district, $mapped );
		if ( ! is_array( $route ) || empty( $route['destination_city_code'] ) || empty( $route['destination_area_code'] ) || empty( $route['tariff_area'] ) ) {
			return new WP_Error( 'lwc_jt_route_not_mapped', __( 'The J&T route mapping is incomplete.', 'lovecatz-wc' ) );
		}
		return array(
			'destination_city_code' => strtoupper( sanitize_text_field( $route['destination_city_code'] ) ),
			'destination_area_code' => strtoupper( sanitize_text_field( $route['destination_area_code'] ) ),
			'tariff_area' => strtoupper( sanitize_text_field( $route['tariff_area'] ) ),
			'source' => isset( $route['source'] ) ? sanitize_key( $route['source'] ) : 'official_jt_region_mapping',
		);
	}

	/** Resolve the store origin, requiring a district only when J&T city codes differ. */
	public static function get_origin_route( $environment = 'sandbox' ) {
		$environment = 'production' === $environment ? 'production' : 'sandbox';
		$location = self::store_location();
		if ( 'ID' !== $location['country'] ) {
			return new WP_Error( 'lwc_jt_origin_country', __( 'The WooCommerce store country must be Indonesia for J&T Express.', 'lovecatz-wc' ) );
		}
		$rows = class_exists( 'LWC_Indonesia_Regions' ) ? LWC_Indonesia_Regions::find_city_regions( $location['state'], $location['city'] ) : array();
		if ( empty( $rows ) ) {
			return new WP_Error( 'lwc_jt_origin_not_mapped', __( 'The WooCommerce store city is not present in the official J&T region mapping.', 'lovecatz-wc' ) );
		}

		$district = trim( (string) apply_filters( 'lwc_jt_express_origin_district', get_option( 'lwc_jt_origin_district', '' ), $location, $rows ) );
		if ( '' !== $district ) {
			foreach ( $rows as $row ) {
				if ( LWC_Indonesia_Regions::normalize_name( $row['district_name'] ) === LWC_Indonesia_Regions::normalize_name( $district ) ) {
					return array( 'tariff_city' => $row['jt_city_name'], 'city_code' => $row['jt_city_code'], 'source' => 'official_jt_origin_district' );
				}
			}
		}

		$routes = array();
		foreach ( $rows as $row ) {
			$routes[ $row['jt_city_name'] . '|' . $row['jt_city_code'] ] = $row;
		}
		if ( 1 === count( $routes ) ) {
			$row = reset( $routes );
			return array( 'tariff_city' => $row['jt_city_name'], 'city_code' => $row['jt_city_code'], 'source' => 'official_jt_origin_city' );
		}
		return new WP_Error( 'lwc_jt_origin_district_required', __( 'The WooCommerce store city maps to multiple J&T origin codes. Configure the store district through the backend origin filter.', 'lovecatz-wc' ) );
	}

	/** Get the tariff sendSiteCode for the selected environment. */
	public static function get_origin_tariff_code( $environment = 'sandbox' ) {
		$route = self::get_origin_route( $environment );
		$default = is_wp_error( $route ) ? '' : $route['tariff_city'];
		return strtoupper( sanitize_text_field( apply_filters( 'lwc_jt_express_origin_tariff_code', $default, $environment ) ) );
	}

	/** Get the order origin_code for the selected environment. */
	public static function get_origin_city_code( $environment = 'sandbox' ) {
		$route = self::get_origin_route( $environment );
		$default = is_wp_error( $route ) ? '' : $route['city_code'];
		return strtoupper( sanitize_text_field( apply_filters( 'lwc_jt_express_origin_city_code', $default, $environment ) ) );
	}

	/** Read the base country/state and city from WooCommerce settings. */
	private static function store_location() {
		$country_state = (string) get_option( 'woocommerce_default_country', 'ID' );
		$parts = array_pad( explode( ':', $country_state, 2 ), 2, '' );
		return array( 'country' => strtoupper( $parts[0] ), 'state' => strtoupper( $parts[1] ), 'city' => (string) get_option( 'woocommerce_store_city', '' ) );
	}
}
