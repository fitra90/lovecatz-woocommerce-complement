<?php
/** Internal-only J&T Cargo shipping method. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** J&T Cargo is independent from the J&T Express shipping hierarchy. */
class LWC_Shipping_JTC extends WC_Shipping_Method {
	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'lwc_jt_cargo';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'J&T Cargo', 'lovecatz-wc' );
		$this->method_description = __( 'Internal-only J&T Cargo integration under development.', 'lovecatz-wc' );
		$this->supports           = array( 'shipping-zones', 'instance-settings' );
		parent::__construct( $instance_id );
		$this->init_form_fields();
		$this->init_settings();
		$this->title = $this->get_option( 'title', $this->method_title );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array( 'title' => __( 'Enable/Disable', 'lovecatz-wc' ), 'type' => 'checkbox', 'default' => 'no' ),
			'title'   => array( 'title' => __( 'Method Title', 'lovecatz-wc' ), 'type' => 'text', 'default' => __( 'J&T Cargo', 'lovecatz-wc' ) ),
		);
	}

	/** No rates are emitted while Cargo remains internal-testing only. */
	public function calculate_shipping( $package = array() ) {}
}
