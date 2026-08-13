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
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'      => array(
				'title'   => __( 'Enable/Disable', 'lovecatz-wc' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable FedEx Shipping', 'lovecatz-wc' ),
				'default' => 'yes',
			),
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
		);
	}

	/**
	 * Calculate shipping rates.
	 *
	 * @param array $package Shipping package data.
	 */
	public function calculate_shipping( $package = array() ) {
		$rate = array(
			'id'       => $this->get_rate_id(),
			'label'    => $this->title,
			'cost'     => '25.00',
			'calc_tax' => 'per_order',
		);

		$this->add_rate( $rate );
	}
}
?>


