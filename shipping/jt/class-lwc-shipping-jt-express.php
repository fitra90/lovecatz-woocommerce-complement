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
}
