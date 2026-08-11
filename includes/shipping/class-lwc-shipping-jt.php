<?php
/**
 * J&T shipping method.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_Shipping_JT extends LWC_Shipping_Provider {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'lwc_shipping_jt';
		$this->method_title       = __( 'J&T Express', 'lovecatz-wc' );
		$this->method_description = __( 'J&T shipping method for WooCommerce.', 'lovecatz-wc' );
		$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );
		$this->instance_id        = absint( $instance_id );
		$this->title              = __( 'J&T Express', 'lovecatz-wc' );
		$this->enabled            = 'yes';
		$this->init();
	}

	public function init() {
		$this->init_form_fields();
		$this->init_settings();
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Enable', 'lovecatz-wc' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this shipping method', 'lovecatz-wc' ),
				'default' => 'yes',
			),
		);
	}

	protected function get_rate_cost( $package ) {
		$rate = 10000;
		$country = isset( $package['destination']['country'] ) ? $package['destination']['country'] : '';
		if ( 'ID' === strtoupper( $country ) ) {
			$rate = 15000;
		}
		return (float) $rate;
	}
}
