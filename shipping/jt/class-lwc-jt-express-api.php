<?php
/**
 * J&T Express Indonesia REST/form API client.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_JT_Express_API {

	const SANDBOX_ORDER_URL  = 'https://demo-ecommerce.inuat-jntexpress.id/jts-idn-ecommerce-api/api/order/create';
	const SANDBOX_TARIFF_URL = 'https://demo-general.inuat-jntexpress.id/jandt_track/inquiry.action';
	const SANDBOX_TRACK_URL  = 'https://demo-general.inuat-jntexpress.id/jandt_track/track/trackAction!tracking.action';
	const SANDBOX_CANCEL_URL = 'https://demo-ecommerce.inuat-jntexpress.id/jts-idn-ecommerce-api/api/order/cancel';

	/** Return backend-managed endpoint URLs for an environment. */
	public static function get_endpoints( $environment = 'sandbox' ) {
		$environment = 'production' === $environment ? 'production' : 'sandbox';
		$defaults = 'sandbox' === $environment ? array(
			'order'  => self::SANDBOX_ORDER_URL,
			'tariff' => self::SANDBOX_TARIFF_URL,
			'track'  => self::SANDBOX_TRACK_URL,
			'cancel' => self::SANDBOX_CANCEL_URL,
		) : array( 'order' => '', 'tariff' => '', 'track' => '', 'cancel' => '' );

		return apply_filters( "lwc_jt_express_{$environment}_endpoints", $defaults );
	}

	/** J&T signs JSON + key with MD5, then Base64-encodes the hex digest. */
	public static function sign( $json, $key ) {
		return base64_encode( md5( $json . $key ) );
	}

	/** Create an order and AWB in the selected J&T environment. */
	public function create_order( $order, $credentials = array() ) {
		$credentials = empty( $credentials ) ? LWC_JT_Account::get_active_credentials( 'express' ) : $credentials;
		$environment = $this->get_environment( $credentials );
		$endpoints   = self::get_endpoints( $environment );
		$required    = array( 'order_username', 'order_api_key', 'order_key' );
		if ( ! $this->has_credentials( $credentials, $required ) || empty( $endpoints['order'] ) ) {
			return new WP_Error( 'lwc_jt_incomplete_credentials', __( 'J&T order credentials or endpoint are incomplete.', 'lovecatz-wc' ) );
		}
		$validation = LWC_JT_Request_Validator::validate_order( $order );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$order['username'] = $credentials['order_username'];
		$order['api_key']  = $credentials['order_api_key'];
		$json = wp_json_encode( array( 'detail' => array( $order ) ), JSON_UNESCAPED_SLASHES );
		$result = $this->post_signed_form( $endpoints['order'], $json, $credentials['order_key'], 'data_param', 'data_sign' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$detail = isset( $result['detail'][0] ) && is_array( $result['detail'][0] ) ? $result['detail'][0] : array();
		if ( empty( $result['success'] ) || 'sukses' !== strtolower( (string) ( isset( $detail['status'] ) ? $detail['status'] : '' ) ) ) {
			$message = isset( $detail['reason'] ) && '' !== $detail['reason'] ? $detail['reason'] : ( isset( $result['desc'] ) ? $result['desc'] : __( 'J&T rejected the order.', 'lovecatz-wc' ) );
			return new WP_Error( 'lwc_jt_order_failed', sanitize_text_field( $message ) );
		}
		if ( empty( $detail['awb_no'] ) ) {
			return new WP_Error( 'lwc_jt_missing_awb', __( 'J&T accepted the request but did not return an AWB number.', 'lovecatz-wc' ) );
		}

		return array(
			'success' => true,
			'order_id' => isset( $detail['orderid'] ) ? sanitize_text_field( $detail['orderid'] ) : '',
			'awb' => isset( $detail['awb_no'] ) ? sanitize_text_field( $detail['awb_no'] ) : '',
			'etd' => isset( $detail['etd'] ) ? sanitize_text_field( $detail['etd'] ) : '',
		);
	}

	/** Track one AWB using J&T Basic Authorization. */
	public function track( $awb, $credentials = array() ) {
		$credentials = empty( $credentials ) ? LWC_JT_Account::get_active_credentials( 'express' ) : $credentials;
		$environment = $this->get_environment( $credentials );
		$endpoints   = self::get_endpoints( $environment );
		if ( ! $this->has_credentials( $credentials, array( 'tracking_company_id', 'tracking_password' ) ) || empty( $endpoints['track'] ) ) {
			return new WP_Error( 'lwc_jt_incomplete_credentials', __( 'J&T tracking credentials or endpoint are incomplete.', 'lovecatz-wc' ) );
		}

		$response = wp_remote_post(
			$endpoints['track'],
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $credentials['tracking_company_id'] . ':' . $credentials['tracking_password'] ),
					'Content-Type'  => 'application/json',
				),
				'body' => wp_json_encode( array( 'awb' => sanitize_text_field( $awb ), 'eccompanyid' => $credentials['tracking_company_id'] ) ),
			)
		);
		$result = $this->decode_response( $response );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( isset( $result['error_id'] ) ) {
			return new WP_Error( 'lwc_jt_tracking_failed', sanitize_text_field( isset( $result['error_message'] ) ? $result['error_message'] : __( 'Tracking failed.', 'lovecatz-wc' ) ) );
		}
		return $result;
	}

	/** Cancel an order which J&T has not processed yet. */
	public function cancel_order( $order_id, $remark, $credentials = array() ) {
		$credentials = empty( $credentials ) ? LWC_JT_Account::get_active_credentials( 'express' ) : $credentials;
		$environment = $this->get_environment( $credentials );
		$endpoints   = self::get_endpoints( $environment );
		if ( ! $this->has_credentials( $credentials, array( 'cancel_username', 'cancel_api_key', 'cancel_key' ) ) || empty( $endpoints['cancel'] ) ) {
			return new WP_Error( 'lwc_jt_incomplete_credentials', __( 'J&T cancellation credentials or endpoint are incomplete.', 'lovecatz-wc' ) );
		}
		$data = array(
			'username' => $credentials['cancel_username'],
			'api_key'  => $credentials['cancel_api_key'],
			'orderid'  => sanitize_text_field( $order_id ),
			'remark'   => substr( sanitize_text_field( $remark ), 0, 30 ),
		);
		$json   = wp_json_encode( array( 'detail' => array( $data ) ), JSON_UNESCAPED_SLASHES );
		$result = $this->post_signed_form( $endpoints['cancel'], $json, $credentials['cancel_key'], 'data_param', 'data_sign' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$detail = isset( $result['detail'][0] ) && is_array( $result['detail'][0] ) ? $result['detail'][0] : array();
		if ( 'sukses' !== strtolower( (string) ( isset( $detail['status'] ) ? $detail['status'] : '' ) ) ) {
			return new WP_Error( 'lwc_jt_cancel_failed', sanitize_text_field( isset( $detail['reason'] ) ? $detail['reason'] : __( 'Cancellation failed.', 'lovecatz-wc' ) ) );
		}
		return array( 'success' => true, 'order_id' => sanitize_text_field( $order_id ), 'status' => sanitize_text_field( $detail['status'] ) );
	}

	/** Execute a tariff-check request. */
	public function get_tariff( $weight, $origin_code, $destination_area, $credentials = array() ) {
		$validation = LWC_JT_Request_Validator::validate_weight( $weight );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		if ( empty( $credentials ) ) {
			$credentials = LWC_JT_Account::get_active_credentials( 'express' );
		}
		$environment = isset( $credentials['environment'] ) && 'production' === $credentials['environment'] ? 'production' : 'sandbox';
		$endpoints   = self::get_endpoints( $environment );
		$customer    = isset( $credentials['tariff_customer_name'] ) ? trim( (string) $credentials['tariff_customer_name'] ) : '';
		$key         = isset( $credentials['tariff_check_key'] ) ? trim( (string) $credentials['tariff_check_key'] ) : '';

		if ( '' === $customer || '' === $key || empty( $endpoints['tariff'] ) ) {
			return new WP_Error( 'lwc_jt_incomplete_credentials', __( 'J&T tariff credentials or endpoint are incomplete.', 'lovecatz-wc' ) );
		}

		$data = wp_json_encode(
			array(
				'weight'       => max( 0.01, (float) $weight ),
				'sendSiteCode' => strtoupper( sanitize_text_field( $origin_code ) ),
				'destAreaCode' => strtoupper( sanitize_text_field( $destination_area ) ),
				'cusName'      => $customer,
				'productType'  => 'EZ',
			),
			JSON_UNESCAPED_SLASHES
		);

		$response = wp_remote_post(
			$endpoints['tariff'],
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => array( 'data' => $data, 'sign' => self::sign( $data, $key ) ),
			)
		);
		$body = $this->decode_response( $response );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		if ( 'true' !== strtolower( (string) ( isset( $body['is_success'] ) ? $body['is_success'] : '' ) ) ) {
			$message = isset( $body['message'] ) ? sanitize_text_field( $body['message'] ) : __( 'J&T rejected the tariff request.', 'lovecatz-wc' );
			return new WP_Error( 'lwc_jt_tariff_failed', $message );
		}

		$services = isset( $body['content'] ) && is_string( $body['content'] ) ? json_decode( $body['content'], true ) : array();
		return array( 'success' => true, 'services' => is_array( $services ) ? $services : array(), 'message' => sprintf( __( 'J&T %s tariff API connected.', 'lovecatz-wc' ), ucfirst( $environment ) ) );
	}

	private function post_signed_form( $url, $json, $key, $data_field, $sign_field ) {
		return $this->decode_response(
			wp_remote_post(
				$url,
				array(
					'timeout' => 20,
					'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
					'body' => array( $data_field => $json, $sign_field => self::sign( $json, $key ) ),
				)
			)
		);
	}

	private function decode_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
			return new WP_Error( 'lwc_jt_invalid_response', sprintf( __( 'J&T returned an invalid response (HTTP %d).', 'lovecatz-wc' ), $status ) );
		}
		return $body;
	}

	private function get_environment( $credentials ) {
		return isset( $credentials['environment'] ) && 'production' === $credentials['environment'] ? 'production' : 'sandbox';
	}

	private function has_credentials( $credentials, $fields ) {
		foreach ( $fields as $field ) {
			if ( empty( $credentials[ $field ] ) ) {
				return false;
			}
		}
		return true;
	}
}
