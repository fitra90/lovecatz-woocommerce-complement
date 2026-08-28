<?php
/** Resolve WooCommerce destinations to J&T Indonesia route codes. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_JT_Route_Mapper {
	const SANDBOX_ORIGIN_TARIFF          = 'JAKARTA';
	const SANDBOX_ORIGIN_CITY            = 'JKT';
	const SANDBOX_DESTINATION_CITY       = 'JKT';
	const SANDBOX_DESTINATION_AREA       = 'JKT001';
	const SANDBOX_DESTINATION_TARIFF_AREA = 'KALIDERES';

	/**
	 * Resolve one package/order destination. Sandbox codes are backend-owned;
	 * production can provide its official mapped route through the filter.
	 */
	public static function resolve( $postcode, $environment = 'sandbox' ) {
		$postcode    = preg_replace( '/\D/', '', (string) $postcode );
		$environment = 'production' === $environment ? 'production' : 'sandbox';
		$route       = apply_filters( 'lwc_jt_express_destination_route', null, $postcode, $environment );
		if ( is_array( $route ) && ! empty( $route['destination_city_code'] ) && ! empty( $route['destination_area_code'] ) && ! empty( $route['tariff_area'] ) ) {
			return array(
				'destination_city_code' => strtoupper( sanitize_text_field( $route['destination_city_code'] ) ),
				'destination_area_code' => strtoupper( sanitize_text_field( $route['destination_area_code'] ) ),
				'tariff_area'            => strtoupper( sanitize_text_field( $route['tariff_area'] ) ),
				'source'                 => 'backend_route',
			);
		}

		if ( 'sandbox' === $environment ) {
			return array(
				'destination_city_code' => self::SANDBOX_DESTINATION_CITY,
				'destination_area_code' => self::SANDBOX_DESTINATION_AREA,
				'tariff_area'            => self::SANDBOX_DESTINATION_TARIFF_AREA,
				'source'                 => 'sandbox_backend',
			);
		}

		return new WP_Error( 'lwc_jt_route_not_mapped', __( 'No backend J&T route is available for this destination.', 'lovecatz-wc' ) );
	}

	/** Get the tariff origin code for the selected environment. */
	public static function get_origin_tariff_code( $environment = 'sandbox' ) {
		$default = 'production' === $environment ? '' : self::SANDBOX_ORIGIN_TARIFF;
		return strtoupper( sanitize_text_field( apply_filters( 'lwc_jt_express_origin_tariff_code', $default, $environment ) ) );
	}

	/** Get the order origin city code for the selected environment. */
	public static function get_origin_city_code( $environment = 'sandbox' ) {
		$default = 'production' === $environment ? '' : self::SANDBOX_ORIGIN_CITY;
		return strtoupper( sanitize_text_field( apply_filters( 'lwc_jt_express_origin_city_code', $default, $environment ) ) );
	}
}
