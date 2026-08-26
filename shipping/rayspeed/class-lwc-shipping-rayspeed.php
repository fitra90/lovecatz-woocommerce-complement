<?php
/** RaySpeed international WooCommerce shipping method. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_Shipping_RaySpeed extends WC_Shipping_Method {
	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'lwc_rayspeed';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'RaySpeed', 'lovecatz-wc' );
		$this->method_description = __( 'RaySpeed RETAIL shipping for destinations outside Indonesia.', 'lovecatz-wc' );
		$this->supports           = array( 'shipping-zones', 'instance-settings' );
		parent::__construct( $instance_id );
		$this->init_form_fields();
		$this->init_settings();
		$this->title = $this->get_option( 'title', __( 'RaySpeed', 'lovecatz-wc' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'title' => array(
				'title'   => __( 'Method title', 'lovecatz-wc' ),
				'type'    => 'text',
				'default' => __( 'RaySpeed', 'lovecatz-wc' ),
			),
		);
	}

	public function is_available( $package = array() ) {
		$country = isset( $package['destination']['country'] ) ? strtoupper( trim( (string) $package['destination']['country'] ) ) : '';
		return 'yes' === get_option( 'lwc_rayspeed_enabled', 'no' ) && '' !== $country && 'ID' !== $country;
	}

	public function calculate_shipping( $package = array() ) {
		if ( ! $this->is_available( $package ) ) {
			return;
		}
		foreach ( $this->build_rates( $package ) as $rate ) {
			$this->add_rate( $rate );
		}
	}

	public function inject_global_rates( $rates, $package ) {
		if ( ! is_array( $rates ) || ! $this->is_available( $package ) ) {
			return $rates;
		}
		foreach ( $rates as $rate ) {
			if ( $rate instanceof WC_Shipping_Rate && 'lwc_rayspeed' === $rate->get_method_id() ) {
				return $rates;
			}
		}
		foreach ( $this->build_rates( $package ) as $args ) {
			$rate = new WC_Shipping_Rate( $args['id'], $args['label'], $args['cost'], array(), 'lwc_rayspeed' );
			foreach ( $args['meta_data'] as $key => $value ) {
				$rate->add_meta_data( $key, $value );
			}
			$rates[ $args['id'] ] = $rate;
		}
		return $rates;
	}

	private function build_rates( $package ) {
		$result = ( new LWC_RaySpeed_API() )->get_quote( $package );
		if ( empty( $result['success'] ) ) {
			if ( class_exists( 'LWC_Logger' ) ) {
				LWC_Logger::log( 'RaySpeed pricing failed: ' . ( isset( $result['message'] ) ? $result['message'] : 'Unknown error' ), 'warning', 'lovecatz-wc' );
			}
			return array();
		}
		$cost = (float) $result['price'];
		if ( class_exists( 'LWC_Currency_Converter' ) ) {
			$cost = LWC_Currency_Converter::round_for_currency( $cost );
		}
		$lead = '';
		if ( '' !== $result['min_lead_time'] && '' !== $result['max_lead_time'] ) {
			$lead = sprintf( ' (%s-%s days)', $result['min_lead_time'], $result['max_lead_time'] );
		}
		return array(
			array(
				'id'        => $this->get_rate_id() . ':' . strtolower( get_option( 'lwc_rayspeed_shipment_type', 'Regular' ) ),
				'label'     => $this->title . $lead,
				'cost'      => $cost,
				'calc_tax'  => 'per_order',
				'meta_data' => array(
					'lwc_rayspeed_service'       => $result['service'],
					'lwc_rayspeed_shipment_type' => get_option( 'lwc_rayspeed_shipment_type', 'Regular' ),
				),
			),
		);
	}
}
