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
	const SANDBOX_PRINT_URL  = 'https://demo-general.inuat-jntexpress.id/jandt_order_web/labels/labelsAction!getPrintUrl.action';
	const SANDBOX_CANCEL_URL = 'https://demo-ecommerce.inuat-jntexpress.id/jts-idn-ecommerce-api/api/order/cancel';
	const PRODUCTION_ORDER_URL  = 'https://ecommerce.jntexpress.id/jts-idn-ecommerce-api/api/order/create';
	const PRODUCTION_TARIFF_URL = 'https://partner-track.jet.co.id/jandt_track/inquiry.action';
	const PRODUCTION_TRACK_URL  = 'https://secure-jk.jet.co.id/jandt-order-web/track/trackAction!tracking.action';
	const PRODUCTION_PRINT_URL  = 'https://general.jntexpress.id/jandt_order_web/labels/labelsAction!getPrintUrl.action';
	const PRODUCTION_CANCEL_URL = 'https://api.jet.co.id/jts-idn-ecommerce-api/api/order/cancel';

	/** Return the official fixed J&T Indonesia endpoints for one environment. */
	public static function get_endpoints( $environment = 'sandbox' ) {
		$environment = 'production' === $environment ? 'production' : 'sandbox';
		$defaults = 'sandbox' === $environment ? array(
			'order'  => self::SANDBOX_ORDER_URL,
			'tariff' => self::SANDBOX_TARIFF_URL,
			'track'  => self::SANDBOX_TRACK_URL,
			'print'  => self::SANDBOX_PRINT_URL,
			'cancel' => self::SANDBOX_CANCEL_URL,
		) : array(
			'order'  => self::PRODUCTION_ORDER_URL,
			'tariff' => self::PRODUCTION_TARIFF_URL,
			'track'  => self::PRODUCTION_TRACK_URL,
			'print'  => self::PRODUCTION_PRINT_URL,
			'cancel' => self::PRODUCTION_CANCEL_URL,
		);

		$endpoints = (array) apply_filters( "lwc_jt_express_{$environment}_endpoints", $defaults );
		foreach ( array( 'order', 'tariff', 'track', 'print', 'cancel' ) as $type ) {
			$url = isset( $endpoints[ $type ] ) ? esc_url_raw( trim( (string) $endpoints[ $type ] ) ) : '';
			$endpoints[ $type ] = in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ? $url : '';
		}
		return $endpoints;
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
		$exchange = $this->take_exchange( $result );
		$detail = isset( $result['detail'][0] ) && is_array( $result['detail'][0] ) ? $result['detail'][0] : array();
		if ( ! $this->is_success_flag( isset( $result['success'] ) ? $result['success'] : false ) || 'sukses' !== strtolower( (string) ( isset( $detail['status'] ) ? $detail['status'] : '' ) ) ) {
			$message = isset( $detail['reason'] ) && '' !== $detail['reason'] ? $detail['reason'] : ( isset( $result['desc'] ) ? $result['desc'] : ( isset( $exchange['response'] ) ? $exchange['response'] : '' ) );
			return new WP_Error( 'lwc_jt_order_failed', (string) $message, $exchange );
		}
		if ( empty( $detail['awb_no'] ) ) {
			return new WP_Error( 'lwc_jt_missing_awb', isset( $exchange['response'] ) ? $exchange['response'] : '', $exchange );
		}
		if ( ! isset( $detail['orderid'] ) || (string) $order['orderid'] !== (string) $detail['orderid'] ) {
			return new WP_Error( 'lwc_jt_invalid_order_response', isset( $exchange['response'] ) ? $exchange['response'] : '', $exchange );
		}
		LWC_JT_Account::set_service_status( $environment, 'order', 'connected', __( 'Order API verified by a successful AWB creation.', 'lovecatz-wc' ), $credentials, array( 'order_username', 'order_api_key', 'order_key' ) );

		return array(
			'success' => true,
			'order_id' => isset( $detail['orderid'] ) ? sanitize_text_field( $detail['orderid'] ) : '',
			'awb' => isset( $detail['awb_no'] ) ? sanitize_text_field( $detail['awb_no'] ) : '',
			'etd' => isset( $detail['etd'] ) ? sanitize_text_field( $detail['etd'] ) : '',
			'exchange' => $exchange,
		);
	}

	/** Track one AWB using J&T Basic Authorization. */
	public function track( $awb, $credentials = array() ) {
		$awb = trim( sanitize_text_field( (string) $awb ) );
		if ( '' === $awb ) {
			return new WP_Error( 'lwc_jt_missing_awb', __( 'An AWB is required for tracking.', 'lovecatz-wc' ) );
		}
		$credentials = empty( $credentials ) ? LWC_JT_Account::get_active_credentials( 'express' ) : $credentials;
		$environment = $this->get_environment( $credentials );
		$endpoints   = self::get_endpoints( $environment );
		if ( ! $this->has_credentials( $credentials, array( 'tracking_company_id', 'tracking_password' ) ) || empty( $endpoints['track'] ) ) {
			return new WP_Error( 'lwc_jt_incomplete_credentials', __( 'J&T tracking credentials or endpoint are incomplete.', 'lovecatz-wc' ) );
		}

		$request_headers = array(
			'Authorization' => 'Basic ' . base64_encode( $credentials['tracking_company_id'] . ':' . $credentials['tracking_password'] ),
			'Content-Type'  => 'application/json',
		);
		$request_body = wp_json_encode( array( 'awb' => sanitize_text_field( $awb ), 'eccompanyid' => $credentials['tracking_company_id'] ) );
		$response = wp_remote_post(
			$endpoints['track'],
			array(
				'timeout' => 20,
				'headers' => $request_headers,
				'body'    => $request_body,
			)
		);
		$result = $this->decode_response(
			$response,
			array(
				'endpoint' => $endpoints['track'],
				'headers'  => $request_headers,
				'body'     => $request_body,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$exchange = $this->take_exchange( $result );
		if ( isset( $result['error_id'] ) ) {
			$message = isset( $result['error_message'] ) ? (string) $result['error_message'] : (string) $result['error_id'];
			return new WP_Error( 'lwc_jt_tracking_failed', $message, $exchange );
		}
		if ( ! isset( $result['awb'], $result['detail'], $result['history'] ) || $awb !== $result['awb'] || ! is_array( $result['detail'] ) || ! is_array( $result['history'] ) ) {
			return new WP_Error( 'lwc_jt_invalid_tracking_response', isset( $exchange['response'] ) ? $exchange['response'] : '', $exchange );
		}
		foreach ( $result['history'] as $event ) {
			if ( ! is_array( $event ) ) {
				return new WP_Error( 'lwc_jt_invalid_tracking_response', isset( $exchange['response'] ) ? $exchange['response'] : '', $exchange );
			}
		}
		LWC_JT_Account::set_service_status( $environment, 'track', 'connected', __( 'Tracking API connected.', 'lovecatz-wc' ), $credentials, array( 'tracking_company_id', 'tracking_password' ) );
		$result['exchange'] = $exchange;
		return $result;
	}

	/** Request a short-lived J&T label URL for one AWB. */
	public function get_print_url( $awb, $credentials = array() ) {
		$credentials = empty( $credentials ) ? LWC_JT_Account::get_active_credentials( 'express' ) : $credentials;
		$environment = $this->get_environment( $credentials );
		$endpoints   = self::get_endpoints( $environment );
		$awb         = trim( sanitize_text_field( (string) $awb ) );

		if ( '' === $awb ) {
			return new WP_Error( 'lwc_jt_missing_awb', __( 'Create the J&T order before printing its label.', 'lovecatz-wc' ) );
		}
		if ( ! $this->has_credentials( $credentials, array( 'print_company_id', 'print_key' ) ) || empty( $endpoints['print'] ) ) {
			return new WP_Error( 'lwc_jt_incomplete_print_credentials', __( 'J&T Print Key, E-company ID, or Print endpoint is incomplete.', 'lovecatz-wc' ) );
		}

		$payload  = wp_json_encode( array( 'billcode' => $awb ), JSON_UNESCAPED_SLASHES );
		$msg_type = (string) apply_filters( 'lwc_jt_express_print_message_type', 'GETPRINTURL', $environment, $awb );
		$request_headers = array( 'Content-Type' => 'application/x-www-form-urlencoded' );
		$request_body = array(
			'logistics_interface' => $payload,
			'data_digest'          => self::sign( $payload, $credentials['print_key'] ),
			'msg_type'             => sanitize_text_field( $msg_type ),
			'eccompanyid'          => sanitize_text_field( $credentials['print_company_id'] ),
		);
		$response = wp_remote_post(
			$endpoints['print'],
			array(
				'timeout' => 25,
				'headers' => $request_headers,
				'body'    => $request_body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$response->add_data( $this->build_exchange( $endpoints['print'], $request_headers, $request_body, $response ) );
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$result = json_decode( $raw, true );
		$exchange = $this->build_exchange( $endpoints['print'], $request_headers, $request_body, $response );
		if ( $status < 200 || $status >= 300 || ! is_array( $result ) ) {
			return new WP_Error( 'lwc_jt_invalid_print_response', '' !== $raw ? $raw : sprintf( 'HTTP %d', $status ), $exchange );
		}

		$item    = isset( $result['responseitems'][0] ) && is_array( $result['responseitems'][0] ) ? $result['responseitems'][0] : array();
		$success = isset( $item['success'] ) && 'true' === strtolower( (string) $item['success'] );
		if ( ! $success ) {
			$reason = isset( $item['reason'] ) ? (string) $item['reason'] : '';
			$message = '' !== $reason ? $reason : $raw;
			LWC_JT_Account::set_service_status( $environment, 'print', 'partial', $message, $credentials, array( 'print_company_id', 'print_key' ) );
			return new WP_Error( 'lwc_jt_print_failed', $message, $exchange );
		}

		$url = $this->find_print_url( $item );
		if ( '' === $url ) {
			$url = $this->find_print_url( $result );
		}
		if ( '' === $url ) {
			return new WP_Error( 'lwc_jt_missing_print_url', $raw, $exchange );
		}

		LWC_JT_Account::set_service_status( $environment, 'print', 'connected', __( 'Print API connected.', 'lovecatz-wc' ), $credentials, array( 'print_company_id', 'print_key' ) );
		return array( 'success' => true, 'label_url' => $url, 'awb' => $awb, 'exchange' => $exchange );
	}

	/** Cancel an order which J&T has not processed yet. */
	public function cancel_order( $order_id, $remark, $credentials = array() ) {
		if ( '' === trim( (string) $order_id ) || '' === trim( (string) $remark ) ) {
			return new WP_Error( 'lwc_jt_invalid_cancellation', __( 'An order ID and reason are required for cancellation.', 'lovecatz-wc' ) );
		}
		$credentials = empty( $credentials ) ? LWC_JT_Account::get_active_credentials( 'express' ) : $credentials;
		$environment = $this->get_environment( $credentials );
		$endpoints   = self::get_endpoints( $environment );
		if ( ! $this->has_credentials( $credentials, array( 'cancel_key', 'cancel_username', 'cancel_api_key' ) ) || empty( $endpoints['cancel'] ) ) {
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
			LWC_JT_Account::set_service_status( $environment, 'cancellation', 'partial', $result->get_error_message(), $credentials, array( 'cancel_key', 'cancel_username', 'cancel_api_key' ) );
			return $result;
		}
		$exchange = $this->take_exchange( $result );
		$detail_value = isset( $result['detail'] ) && is_array( $result['detail'] ) ? $result['detail'] : array();
		$detail = isset( $detail_value[0] ) && is_array( $detail_value[0] ) ? $detail_value[0] : $detail_value;
		$status = isset( $detail['status'] ) ? strtolower( trim( (string) $detail['status'] ) ) : '';
		if ( ! $this->is_success_flag( isset( $result['success'] ) ? $result['success'] : false ) || 'sukses' !== $status ) {
			$reason = '';
			foreach ( array(
				isset( $detail['reason'] ) ? $detail['reason'] : '',
				isset( $result['reason'] ) ? $result['reason'] : '',
				isset( $result['desc'] ) ? $result['desc'] : '',
				isset( $result['message'] ) ? $result['message'] : '',
				isset( $result['error_message'] ) ? $result['error_message'] : '',
			) as $candidate ) {
				if ( '' !== trim( (string) $candidate ) ) {
					$reason = (string) $candidate;
					break;
				}
			}
			if ( '' === $reason ) {
				$reason = isset( $exchange['response'] ) ? (string) $exchange['response'] : '';
			}
			$matches_order = isset( $detail['orderid'] ) && (string) $data['orderid'] === (string) $detail['orderid'];
			$code = 'lwc_jt_cancel_failed';
			if ( preg_match( '/Status\s+pesanan\s+adalah\s*:\s*GOT\s*$/i', $reason ) ) {
				$code = 'lwc_jt_already_picked_up';
			} elseif ( false !== stripos( $reason, 'signature' ) || false !== stripos( $reason, 'tanda tangan' ) ) {
				$code = 'lwc_jt_cancel_signature_failed';
			} elseif ( false !== stripos( $reason, 'username' ) || false !== stripos( $reason, 'api key' ) || false !== stripos( $reason, 'authorization' ) ) {
				$code = 'lwc_jt_cancel_credentials_failed';
			}
			if ( class_exists( 'LWC_Logger' ) ) {
				LWC_Logger::log(
					sprintf(
						'J&T %1$s cancellation rejected for order %2$s (matched=%3$s, status=%4$s, code=%5$s): %6$s',
						$environment,
						sanitize_text_field( $order_id ),
						$matches_order ? 'yes' : 'no',
						sanitize_text_field( $status ),
						$code,
						$reason
					),
					'error'
				);
			}
			$connection_status = in_array( $code, array( 'lwc_jt_cancel_signature_failed', 'lwc_jt_cancel_credentials_failed' ), true ) ? 'partial' : 'connected';
			LWC_JT_Account::set_service_status( $environment, 'cancellation', $connection_status, $reason, $credentials, array( 'cancel_key', 'cancel_username', 'cancel_api_key' ) );
			return new WP_Error( $code, $reason, $exchange );
		}
		if ( ! isset( $detail['orderid'] ) || (string) $data['orderid'] !== (string) $detail['orderid'] ) {
			return new WP_Error( 'lwc_jt_invalid_cancel_response', isset( $exchange['response'] ) ? $exchange['response'] : '', $exchange );
		}
		LWC_JT_Account::set_service_status( $environment, 'cancellation', 'connected', __( 'Cancellation API verified by a successful cancellation.', 'lovecatz-wc' ), $credentials, array( 'cancel_key', 'cancel_username', 'cancel_api_key' ) );
		return array( 'success' => true, 'order_id' => sanitize_text_field( $order_id ), 'status' => sanitize_text_field( $detail['status'] ), 'exchange' => $exchange );
	}

	/** Tariff uses city/district names, not Order API codes (JAKARTA / KALIDERES). */
	public function get_tariff( $weight, $origin_code, $destination_area, $credentials = array() ) {
		$origin_code = strtoupper( trim( sanitize_text_field( $origin_code ) ) );
		$destination_area = strtoupper( trim( sanitize_text_field( $destination_area ) ) );
		if ( '' === $origin_code || '' === $destination_area ) {
			return new WP_Error( 'lwc_jt_invalid_tariff_route', __( 'J&T tariff requires mapped city and district names.', 'lovecatz-wc' ) );
		}
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

		$request_body = array( 'data' => $data, 'sign' => self::sign( $data, $key ) );
		$request_headers = array( 'Content-Type' => 'application/x-www-form-urlencoded' );
		$response = wp_remote_post(
			$endpoints['tariff'],
			array(
				'timeout' => 20,
				'headers' => $request_headers,
				'body'    => $request_body,
			)
		);
		$body = $this->decode_response( $response, array( 'endpoint' => $endpoints['tariff'], 'headers' => $request_headers, 'body' => $request_body ) );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$exchange = $this->take_exchange( $body );
		if ( ! $this->is_success_flag( isset( $body['is_success'] ) ? $body['is_success'] : false ) ) {
			$message = isset( $body['message'] ) ? (string) $body['message'] : ( isset( $exchange['response'] ) ? (string) $exchange['response'] : '' );
			return new WP_Error( 'lwc_jt_tariff_failed', $message, $exchange );
		}

		$services = isset( $body['content'] ) ? $body['content'] : null;
		if ( is_string( $services ) ) {
			$services = json_decode( $services, true );
		}
		if ( ! is_array( $services ) ) {
			return new WP_Error( 'lwc_jt_invalid_tariff_response', isset( $exchange['response'] ) ? $exchange['response'] : '', $exchange );
		}
		if ( empty( $services ) ) {
			return new WP_Error( 'lwc_jt_no_tariff', isset( $exchange['response'] ) ? $exchange['response'] : '', $exchange );
		}
		foreach ( $services as $service ) {
			if ( ! is_array( $service ) || empty( $service['name'] ) || ! is_string( $service['name'] ) || ! isset( $service['cost'] ) || ! is_numeric( $service['cost'] ) || ! is_finite( (float) $service['cost'] ) || (float) $service['cost'] <= 0 ) {
				return new WP_Error( 'lwc_jt_invalid_tariff_response', isset( $exchange['response'] ) ? $exchange['response'] : '', $exchange );
			}
		}
		return array( 'success' => true, 'services' => array_values( $services ), 'message' => sprintf( __( 'J&T %s tariff API connected.', 'lovecatz-wc' ), ucfirst( $environment ) ), 'exchange' => $exchange );
	}

	/** The API returns both JSON booleans and the strings "true" / "false". */
	private function is_success_flag( $value ) {
		return true === $value || ( is_string( $value ) && 'true' === strtolower( trim( $value ) ) );
	}

	private function post_signed_form( $url, $json, $key, $data_field, $sign_field ) {
		$body = array( $data_field => $json, $sign_field => self::sign( $json, $key ) );
		$headers = array( 'Content-Type' => 'application/x-www-form-urlencoded' );
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => $headers,
				'body' => $body,
			)
		);
		return $this->decode_response( $response, array( 'endpoint' => $url, 'headers' => $headers, 'body' => $body ) );
	}

	private function decode_response( $response, $request = array() ) {
		$exchange = $this->build_exchange(
			isset( $request['endpoint'] ) ? (string) $request['endpoint'] : '',
			isset( $request['headers'] ) && is_array( $request['headers'] ) ? $request['headers'] : array(),
			isset( $request['body'] ) ? $request['body'] : array(),
			$response
		);
		if ( is_wp_error( $response ) ) {
			$response->add_data( $exchange );
			return $response;
		}
		$status = $exchange['http_status'];
		$body   = json_decode( $exchange['response'], true );
		if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
			$message = '' !== trim( $exchange['response'] ) ? $exchange['response'] : sprintf( 'HTTP %d', $status );
			return new WP_Error( 'lwc_jt_invalid_response', $message, $exchange );
		}
		$body['_lwc_exchange'] = $exchange;
		return $body;
	}

	/** Build an unmasked, non-persisted copy of the exact carrier exchange for admin troubleshooting. */
	private function build_exchange( $endpoint, $headers, $body, $response ) {
		return array(
			'endpoint' => (string) $endpoint,
			'request'  => array(
				'method'  => 'POST',
				'headers' => $headers,
				'body'    => $body,
			),
			'http_status' => is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response ),
			'response'    => is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response ),
		);
	}

	/** Remove internal exchange data before carrier data is persisted. */
	private function take_exchange( &$result ) {
		$exchange = isset( $result['_lwc_exchange'] ) && is_array( $result['_lwc_exchange'] ) ? $result['_lwc_exchange'] : array();
		unset( $result['_lwc_exchange'] );
		return $exchange;
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

	/** Find the first HTTP(S) URL in a successful Print response. */
	private function find_print_url( $value ) {
		if ( is_string( $value ) ) {
			$url = esc_url_raw( trim( html_entity_decode( $value, ENT_QUOTES, 'UTF-8' ) ) );
			return in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ? $url : '';
		}
		if ( ! is_array( $value ) ) {
			return '';
		}

		foreach ( array( 'url', 'print_url', 'printUrl', 'printurl', 'label_url', 'labelUrl' ) as $key ) {
			if ( isset( $value[ $key ] ) ) {
				$url = $this->find_print_url( $value[ $key ] );
				if ( '' !== $url ) {
					return $url;
				}
			}
		}
		foreach ( $value as $child ) {
			$url = $this->find_print_url( $child );
			if ( '' !== $url ) {
				return $url;
			}
		}
		return '';
	}

}
