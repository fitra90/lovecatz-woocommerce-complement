<?php
/**
 * FedEx shipping method.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_Shipping_FedEx extends LWC_Shipping_Provider {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'lwc_shipping_fedex';
		$this->method_title       = __( 'FedEx', 'lovecatz-wc' );
		$this->method_description = __( 'FedEx international shipping method.', 'lovecatz-wc' );
		$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );
		$this->instance_id        = absint( $instance_id );
		$this->title              = __( 'FedEx', 'lovecatz-wc' );
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

	public function is_available( $package ) {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		if ( ! $this->is_configured() ) {
			return false;
		}

		if ( ! $this->should_activate_for_package( $package ) ) {
			return false;
		}

		return parent::is_available( $package );
	}

	protected function should_activate_for_package( $package ) {
		$dest_country = isset( $package['destination']['country'] ) ? strtoupper( (string) $package['destination']['country'] ) : '';
		$dest_postcode = isset( $package['destination']['postcode'] ) ? trim( (string) $package['destination']['postcode'] ) : '';

		if ( 'ID' === $dest_country ) {
			return false;
		}

		if ( '' === $dest_postcode ) {
			return false;
		}

		return true;
	}

	protected function get_rate_cost( $package ) {
		$country = isset( $package['destination']['country'] ) ? $package['destination']['country'] : '';
		$rate = 25000;
		if ( 'ID' === strtoupper( $country ) ) {
			$rate = 0;
		}
		return (float) $rate;
	}

	private function is_configured() {
		$account_number = LWC_FedEx_Account::get_option_value( 'lwc_fedex_account_number', '' );
		$api_key        = LWC_FedEx_Account::get_option_value( 'lwc_fedex_api_key', '' );
		$api_secret     = LWC_FedEx_Account::get_option_value( 'lwc_fedex_api_secret', '' );
		$validation     = get_option( 'lwc_fedex_validation_status', 'validated' );

		if ( '' === $account_number || '' === $api_key || '' === $api_secret ) {
			return false;
		}

		return 'invalid' !== $validation;
	}

	public function get_api_base_url() {
		$test_mode = LWC_FedEx_Account::get_option_value( 'lwc_fedex_test_mode', 'no' );
		if ( 'yes' === $test_mode ) {
			return 'https://apis-sandbox.fedex.com';
		}

		return 'https://apis.fedex.com';
	}
}
