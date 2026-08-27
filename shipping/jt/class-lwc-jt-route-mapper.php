<?php
/** Resolve WooCommerce destinations to J&T Indonesia route codes. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_JT_Route_Mapper {
	/**
	 * Resolve one package/order destination.
	 * Mapping format: postcode|city_code|area_code|tariff_area
	 */
	public static function resolve( $postcode, $environment = 'sandbox' ) {
		$postcode   = preg_replace( '/\D/', '', (string) $postcode );
		$environment = 'production' === $environment ? 'production' : 'sandbox';
		$raw        = (string) get_option( 'lwc_jt_express_area_mapping', '' );
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( 4 === count( $parts ) && $postcode === preg_replace( '/\D/', '', $parts[0] ) ) {
				return array(
					'destination_city_code' => strtoupper( sanitize_text_field( $parts[1] ) ),
					'destination_area_code' => strtoupper( sanitize_text_field( $parts[2] ) ),
					'tariff_area'            => strtoupper( sanitize_text_field( $parts[3] ) ),
					'source'                 => 'postcode_mapping',
				);
			}
		}

		if ( 'sandbox' === $environment ) {
			return array(
				'destination_city_code' => strtoupper( (string) get_option( 'lwc_jt_express_sandbox_destination_city_code', 'JKT' ) ),
				'destination_area_code' => strtoupper( (string) get_option( 'lwc_jt_express_sandbox_destination_area_code', 'JKT001' ) ),
				'tariff_area'            => strtoupper( (string) get_option( 'lwc_jt_express_sandbox_tariff_area', 'KALIDERES' ) ),
				'source'                 => 'sandbox_fallback',
			);
		}

		return new WP_Error( 'lwc_jt_route_not_mapped', __( 'The destination postcode is not mapped to J&T area codes.', 'lovecatz-wc' ) );
	}
}
