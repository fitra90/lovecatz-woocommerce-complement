<?php
/**
 * Base class for shipping providers.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class LWC_Shipping_Provider extends WC_Shipping_Method {

	/**
	 * Provider slug.
	 *
	 * @var string
	 */
	protected $provider_id = '';

	/**
	 * Provider title.
	 *
	 * @var string
	 */
	protected $provider_title = '';

	/**
	 * Whether this provider should be available for the current package.
	 *
	 * @param array $package Package data.
	 * @return bool
	 */
	public function is_available( $package ) {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		if ( ! $this->should_activate_for_package( $package ) ) {
			return false;
		}

		return parent::is_available( $package );
	}

	/**
	 * Determine whether this provider should apply to the given package.
	 *
	 * @param array $package Package data.
	 * @return bool
	 */
	protected function should_activate_for_package( $package ) {
		return true;
	}

	/**
	 * Calculate shipping cost for the current package.
	 *
	 * @param array $package Package data.
	 * @return array
	 */
	public function calculate_shipping( $package = array() ) {
		$rate = array(
			'id'      => $this->id,
			'label'   => $this->title,
			'cost'    => $this->get_rate_cost( $package ),
			'package' => $package,
		);

		$this->add_rate( $rate );
	}

	/**
	 * Get provider-specific rate cost.
	 *
	 * @param array $package Package data.
	 * @return float
	 */
	protected function get_rate_cost( $package ) {
		return 0;
	}
}
