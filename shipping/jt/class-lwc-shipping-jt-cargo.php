<?php
/**
 * J&T Cargo shipping method for LoveCatz WooCommerce Complement.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * J&T Cargo heavy/bulk courier. Separate API provider from J&T Express.
 *
 * Cargo bills a 10 kg minimum and accepts up to 500 kg per shipment
 * (tiers H50/H100/H300/H500), so auto-splitting is off by default.
 */
class LWC_Shipping_JT_Cargo extends LWC_Shipping_JT_Base {

	/**
	 * Provider slug.
	 *
	 * @var string
	 */
	protected $provider = 'cargo';

	/**
	 * No automatic splitting for cargo by default.
	 *
	 * @var float
	 */
	protected $default_threshold_kg = 0;

	/**
	 * Provider identity and copy.
	 *
	 * @return array
	 */
	protected function get_provider_config() {
		return array(
			'title'                => __( 'J&T Cargo', 'lovecatz-wc' ),
			'description'          => __( 'J&T Cargo shipping method for large and heavy shipments', 'lovecatz-wc' ),
			'checkout_description' => __( 'Deliver via J&T Cargo', 'lovecatz-wc' ),
			'weight_description'   => sprintf(
				/* translators: %s: weight limit */
				__( 'Split threshold for heavy carts. Capped at the J&T Cargo limit of %s kg (10 kg minimum billable weight). Set 0 to disable splitting.', 'lovecatz-wc' ),
				'500'
			),
		);
	}

	/**
	 * Carrier-specific maximum weight per package in kilograms.
	 *
	 * @return float
	 */
	protected function get_provider_weight_ceiling() {
		return (float) apply_filters( 'lwc_jt_cargo_package_weight_ceiling_kg', 500 );
	}
}
