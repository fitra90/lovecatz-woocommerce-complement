<?php
/** J&T Cargo Open Platform interface registry. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Independent API boundary for J&T Cargo. No J&T Express code is reused. */
class LWC_JTC_API {
	const SANDBOX_BASE_URL    = 'https://demoopenapi.jtcargo.co.id/webopenplatformapi/api';
	const PRODUCTION_BASE_URL = 'https://openapi.jtcargo.co.id/webopenplatformapi/api';
	const SANDBOX_DEFAULT_UUID = '9ca214d9660342d289dd5ec8d4a1412a';
	const PASSWORD_SALT        = 'jadada236t2';

	public static function get_interface_paths() {
		return array(
			'shipment_track' => 'logistics/trace', 'add_order' => 'order/addOrder',
			'cancel_order' => 'order/cancelOrder', 'print_order' => 'order/printOrder',
			'dispatch_code' => 'order/getDispatchCode', 'address' => 'order/getAddress',
			'orders' => 'order/getOrders', 'location' => 'location/getLocation',
			'verify_waybill_account' => 'vip/checkCusPwd', 'out_of_area' => 'online/cover',
			'delivery_range' => 'online/pca', 'network_info' => 'network/getInfo',
			'delivery_sla' => 'order/agingCost/get', 'add_loose_order' => 'order/addLooseOrder',
			'subscribe_tracking' => 'trace/subscribe', 'waybill_balance' => 'ess/balance',
			'save_real_name' => 'realName/saveOrUpdate', 'freight_calculation' => 'spmComCost/getComCost',
			'batch_waybill_number' => 'order/getBatchBillCode', 'three_segment_code' => 'threeCode/getThreeSegmentCode',
		);
	}

	/** Human-readable names for the complete Sandbox interface catalog. */
	public static function get_interface_labels() {
		return array(
			'shipment_track' => __( 'Shipment Track Search', 'lovecatz-wc' ),
			'add_order' => __( 'Add New Order', 'lovecatz-wc' ),
			'cancel_order' => __( 'Cancel Order', 'lovecatz-wc' ),
			'print_order' => __( 'Waybill Template Inquiry', 'lovecatz-wc' ),
			'dispatch_code' => __( '10-character Code Inquiry', 'lovecatz-wc' ),
			'address' => __( 'Province, City, District Inquiry', 'lovecatz-wc' ),
			'orders' => __( 'Check Order', 'lovecatz-wc' ),
			'location' => __( 'Obtain Province, City, District', 'lovecatz-wc' ),
			'verify_waybill_account' => __( 'E-waybill Account Verification', 'lovecatz-wc' ),
			'out_of_area' => __( 'Out-of-area Inquiry', 'lovecatz-wc' ),
			'delivery_range' => __( 'Delivery Range Inquiry', 'lovecatz-wc' ),
			'network_info' => __( 'Outlet and Address Information', 'lovecatz-wc' ),
			'delivery_sla' => __( 'Delivery SLA Query', 'lovecatz-wc' ),
			'add_loose_order' => __( 'LTL Shipments', 'lovecatz-wc' ),
			'subscribe_tracking' => __( 'Logistics Tracks Subscription', 'lovecatz-wc' ),
			'waybill_balance' => __( 'E-waybill Balance Inquiry', 'lovecatz-wc' ),
			'save_real_name' => __( 'Real-name Information Uploading', 'lovecatz-wc' ),
			'freight_calculation' => __( 'Freight Calculation', 'lovecatz-wc' ),
			'batch_waybill_number' => __( 'Obtain Waybill Number', 'lovecatz-wc' ),
			'three_segment_code' => __( 'Three Address Level Code Query', 'lovecatz-wc' ),
		);
	}

	public static function get_endpoints( $environment = 'sandbox', $uuid = '' ) {
		$environment = 'production' === sanitize_key( $environment ) ? 'production' : 'sandbox';
		$base_url    = 'sandbox' === $environment ? self::SANDBOX_BASE_URL : self::PRODUCTION_BASE_URL;
		$base_url    = self::sanitize_base_url( (string) apply_filters( "lwc_jtc_{$environment}_base_url", $base_url ) );
		$uuid        = self::normalize_uuid( $uuid );
		if ( '' === $uuid && 'sandbox' === $environment ) {
			$uuid = self::normalize_uuid( (string) apply_filters( 'lwc_jtc_sandbox_default_uuid', self::SANDBOX_DEFAULT_UUID ) );
		}
		$endpoints = array();
		foreach ( self::get_interface_paths() as $name => $path ) {
			$endpoint = '' === $base_url ? '' : $base_url . '/' . ltrim( $path, '/' );
			$endpoints[ $name ] = '' !== $endpoint && '' !== $uuid ? add_query_arg( 'uuid', $uuid, $endpoint ) : $endpoint;
		}
		$endpoints = (array) apply_filters( "lwc_jtc_{$environment}_endpoints", $endpoints, $uuid );
		foreach ( self::get_interface_paths() as $name => $path ) {
			$url = isset( $endpoints[ $name ] ) ? esc_url_raw( trim( (string) $endpoints[ $name ] ) ) : '';
			$endpoints[ $name ] = 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) ? $url : '';
		}
		return $endpoints;
	}

	public static function get_endpoint( $name, $environment = 'sandbox', $uuid = '' ) {
		$endpoints = self::get_endpoints( $environment, $uuid );
		$name      = sanitize_key( $name );
		return isset( $endpoints[ $name ] ) ? $endpoints[ $name ] : '';
	}

	public static function get_active_endpoints() {
		$credentials = LWC_JTC_Account::get_active_credentials();
		return self::get_endpoints( $credentials['environment'], $credentials['uuid'] );
	}

	/**
	 * Perform a non-destructive reachability check against the location API.
	 *
	 * A successful HTTP response proves endpoint health only. It does not claim
	 * that Cargo credentials are authenticated before J&T supplies its signing contract.
	 */
	public static function check_health( $credentials = array() ) {
		$environment = isset( $credentials['environment'] ) && 'production' === $credentials['environment'] ? 'production' : 'sandbox';
		$uuid        = isset( $credentials['uuid'] ) ? $credentials['uuid'] : '';
		$endpoint    = self::get_endpoint( 'location', $environment, $uuid );
		if ( '' === $endpoint ) {
			return array( 'status' => 'partial', 'label' => __( 'API endpoint is not configured for this environment.', 'lovecatz-wc' ) );
		}

		$response = wp_remote_get( $endpoint, array( 'timeout' => 12, 'redirection' => 2 ) );
		if ( is_wp_error( $response ) ) {
			return array( 'status' => 'request_failed', 'label' => $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 200 && $status < 300 ) {
			return array( 'status' => 'connected', 'label' => sprintf( __( 'API endpoint reachable (HTTP %d).', 'lovecatz-wc' ), $status ) );
		}
		if ( $status >= 400 && $status < 500 ) {
			return array( 'status' => 'partial', 'label' => sprintf( __( 'API endpoint responded (HTTP %d); authentication is not yet verified.', 'lovecatz-wc' ), $status ) );
		}

		return array( 'status' => 'request_failed', 'label' => sprintf( __( 'API endpoint returned HTTP %d.', 'lovecatz-wc' ), $status ) );
	}

	/**
	 * Execute one whitelisted Sandbox request for the admin test console.
	 *
	 * @return array|WP_Error
	 */
	public static function sandbox_request( $interface, $method = 'POST', $format = 'json', $payload = '', $custom_headers = '' ) {
		return self::signed_request( $interface, $method, $format, $payload, $custom_headers, 'sandbox' );
	}

	/**
	 * Call one interface with a plain business-content array.
	 *
	 * Used by order flows (tracking, waybill lookups) that need the signed
	 * transport without going through the Sandbox console.
	 *
	 * @param string $interface   Interface slug.
	 * @param array  $data        Business content.
	 * @param string $environment sandbox|production. Empty = active environment.
	 * @return array|WP_Error
	 */
	public static function request( $interface, $data = array(), $environment = '' ) {
		return self::send( $interface, (array) $data, $environment );
	}

	/**
	 * Send one business-content array as the single canonical payload.
	 *
	 * Contract: the array that leaves this method is encoded exactly once. The
	 * resulting JSON string is the string the header digest is calculated from
	 * AND the string placed in the bizContent form field. There is no second
	 * build for the signature and no second build for the body.
	 *
	 * customerCode and the body signature (digest, manual 3.3.2) are added here
	 * when the caller did not supply them, so no credential has to live in the
	 * mapping layer.
	 *
	 * @param string $interface   Interface slug.
	 * @param array  $data        Business content (no credentials required).
	 * @param string $environment sandbox|production. Empty = active environment.
	 * @return array|WP_Error
	 */
	public static function send( $interface, $data = array(), $environment = '' ) {
		$interface = sanitize_key( (string) $interface );
		$paths     = self::get_interface_paths();
		if ( ! isset( $paths[ $interface ] ) ) {
			return new WP_Error( 'lwc_jtc_unknown_interface', __( 'Unknown J&T Cargo interface.', 'lovecatz-wc' ) );
		}
		if ( '' === (string) $environment ) {
			$credentials = LWC_JTC_Account::get_active_credentials();
			$environment = isset( $credentials['environment'] ) ? $credentials['environment'] : 'sandbox';
		}
		$environment = 'production' === $environment ? 'production' : 'sandbox';
		$credentials = LWC_JTC_Account::get_credentials( $environment );

		$missing = self::get_missing_signing_credentials( $credentials );
		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'lwc_jtc_missing_signing_credentials',
				sprintf(
					/* translators: %s: comma-separated credential labels. */
					__( 'Complete the J&T Cargo signing credentials first: %s.', 'lovecatz-wc' ),
					implode( ', ', $missing )
				)
			);
		}

		// FINAL PAYLOAD: credentials first, exactly as the manual lists them.
		$final = array();
		if ( ! isset( $data['customerCode'] ) ) {
			$final['customerCode'] = (string) $credentials['customer_code'];
		}
		if ( ! isset( $data['digest'] ) ) {
			$final['digest'] = self::build_content_digest(
				$credentials['customer_code'],
				$credentials['customer_password'],
				$credentials['private_key']
			);
		}
		$final = array_merge( $final, (array) $data );

		$biz_content = wp_json_encode( $final, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $biz_content ) {
			return new WP_Error( 'lwc_jtc_payload_encoding_failed', __( 'The Sandbox payload could not be encoded.', 'lovecatz-wc' ) );
		}
		if ( strlen( $biz_content ) > 1048576 ) {
			return new WP_Error( 'lwc_jtc_request_too_large', __( 'Sandbox test payload or headers are too large.', 'lovecatz-wc' ) );
		}

		$endpoint = self::get_endpoint( $interface, $environment, $credentials['uuid'] );
		if ( '' === $endpoint ) {
			return new WP_Error( 'lwc_jtc_missing_endpoint', __( 'The selected J&T Cargo endpoint is unavailable.', 'lovecatz-wc' ) );
		}

		$result = self::transmit( $interface, $endpoint, 'POST', $final, $biz_content, array(), $environment, $credentials );
		if ( is_array( $result ) ) {
			// Fingerprint only: the body itself carries customerCode and digest,
			// so it must never travel into a rendered response view or a log.
			$result['biz_content_length'] = strlen( $biz_content );
			$result['biz_content_sha256'] = hash( 'sha256', $biz_content );
		}
		return $result;
	}

	/**
	 * Execute one whitelisted request against a J&T Cargo interface.
	 *
	 * The environment is explicit so the same signing code serves the Sandbox
	 * test console and production order flows.
	 *
	 * @param string $interface     Interface slug.
	 * @param string $method        GET or POST.
	 * @param string $format        json|form (payload encoding hint).
	 * @param string $payload       JSON string with the business content.
	 * @param string $custom_headers JSON string with extra headers.
	 * @param string $environment   sandbox|production.
	 * @return array|WP_Error
	 */
	public static function signed_request( $interface, $method = 'POST', $format = 'json', $payload = '', $custom_headers = '', $environment = 'sandbox' ) {
		$interface   = sanitize_key( $interface );
		$environment = 'production' === $environment ? 'production' : 'sandbox';
		$paths       = self::get_interface_paths();
		if ( ! isset( $paths[ $interface ] ) ) {
			return new WP_Error( 'lwc_jtc_unknown_interface', __( 'Unknown J&T Cargo interface.', 'lovecatz-wc' ) );
		}

		$credentials = LWC_JTC_Account::get_credentials( $environment );
		$endpoint    = self::get_endpoint( $interface, $environment, $credentials['uuid'] );
		if ( '' === $endpoint ) {
			return new WP_Error( 'lwc_jtc_missing_endpoint', __( 'The selected J&T Cargo endpoint is unavailable.', 'lovecatz-wc' ) );
		}

		$method = 'GET' === strtoupper( (string) $method ) ? 'GET' : 'POST';
		$format = 'form' === sanitize_key( $format ) ? 'form' : 'json';
		$payload = trim( (string) $payload );
		if ( strlen( $payload ) > 1048576 || strlen( (string) $custom_headers ) > 65536 ) {
			return new WP_Error( 'lwc_jtc_request_too_large', __( 'Sandbox test payload or headers are too large.', 'lovecatz-wc' ) );
		}
		$data = array();
		if ( '' !== $payload ) {
			$data = json_decode( $payload, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
				return new WP_Error( 'lwc_jtc_invalid_payload', __( 'Request payload must be a valid JSON object or array.', 'lovecatz-wc' ) );
			}
		}

		$headers = self::sanitize_test_headers( $custom_headers );
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$missing = self::get_missing_signing_credentials( $credentials );
		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'lwc_jtc_missing_signing_credentials',
				sprintf(
					/* translators: %s: comma-separated credential labels. */
					__( 'Complete the J&T Cargo signing credentials first: %s.', 'lovecatz-wc' ),
					implode( ', ', $missing )
				)
			);
		}

		// The Open Platform signs the exact bizContent JSON string that is sent.
		if ( ! isset( $data['customerCode'] ) ) {
			$data['customerCode'] = (string) $credentials['customer_code'];
		}
		// Manual 3.4.1 / 3.4.2: every business payload that carries customerCode
		// also requires the body signature "digest" — not only freight calculation.
		if ( ! isset( $data['digest'] ) ) {
			$data['digest'] = self::build_content_digest(
				$credentials['customer_code'],
				$credentials['customer_password'],
				$credentials['private_key']
			);
		}
		$biz_content = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $biz_content ) {
			return new WP_Error( 'lwc_jtc_payload_encoding_failed', __( 'The Sandbox payload could not be encoded.', 'lovecatz-wc' ) );
		}
		return self::transmit( $interface, $endpoint, $method, $data, $biz_content, $headers, $environment, $credentials );
	}

	/**
	 * Sign and send one already-encoded bizContent string.
	 *
	 * The header digest is calculated from the exact string that is placed in
	 * the bizContent form field, so the signature can never drift from the body.
	 *
	 * @param string $interface    Interface slug.
	 * @param string $endpoint     Fully built endpoint URL.
	 * @param string $method       GET or POST.
	 * @param array  $data         Decoded business content (used for GET query args).
	 * @param string $biz_content  The JSON string that is signed and sent.
	 * @param array  $headers      Extra headers already sanitised.
	 * @param string $environment  sandbox|production.
	 * @param array  $credentials  Credential set for the environment.
	 * @return array|WP_Error
	 */
	private static function transmit( $interface, $endpoint, $method, $data, $biz_content, $headers, $environment, $credentials ) {
		$headers['apiAccount'] = (string) $credentials['api_account'];
		$headers['digest']     = self::build_header_digest( $biz_content, $credentials['private_key'] );
		$headers['timestamp']  = (string) round( microtime( true ) * 1000 );

		$args = array(
			'method' => $method,
			'timeout' => 30,
			'redirection' => 2,
			'reject_unsafe_urls' => true,
			'headers' => $headers,
		);
		if ( 'GET' === $method ) {
			foreach ( (array) $data as $key => $value ) {
				$key = sanitize_key( $key );
				if ( '' !== $key && is_scalar( $value ) ) {
					$endpoint = add_query_arg( $key, (string) $value, $endpoint );
				}
			}
		} else {
			// The SDK contract transports the signed JSON in the bizContent form field.
			$args['headers']['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
			$args['body'] = array( 'bizContent' => $biz_content );
		}

		$started  = microtime( true );
		$response = wp_safe_remote_request( $endpoint, $args );
		$elapsed  = (int) round( ( microtime( true ) - $started ) * 1000 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw = wp_check_invalid_utf8( (string) wp_remote_retrieve_body( $response ), true );
		if ( strlen( $raw ) > 1048576 ) {
			$raw = substr( $raw, 0, 1048576 ) . "\n[Response truncated at 1 MB]";
		}
		$decoded = self::decode_body( $raw );
		$business = self::evaluate_business_response( (int) wp_remote_retrieve_response_code( $response ), $decoded );

		return array(
			'interface' => $interface,
			'environment' => $environment,
			'endpoint' => $endpoint,
			'method' => $method,
			'http_status' => (int) wp_remote_retrieve_response_code( $response ),
			'elapsed_ms' => $elapsed,
			'content_type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
			'business_success' => $business['success'],
			'business_code' => $business['code'],
			'business_message' => $business['message'],
			'authentication' => array(
				'mode' => 'open_platform_signed',
				'api_account_sent' => true,
				'header_digest_sent' => true,
				'content_digest_sent' => isset( $data['digest'] ) && '' !== (string) $data['digest'],
			),
			'response' => is_array( $decoded ) ? $decoded : $raw,
		);
	}

	/**
	 * Decode a J&T response body.
	 *
	 * The manual declares the response type as "JSON (URL-encoded)", so a body
	 * that is still percent-encoded is decoded before it is parsed. Neither
	 * attempt ever throws: undecodable bodies are returned untouched.
	 *
	 * @param string $raw Raw response body.
	 * @return array|null
	 */
	private static function decode_body( $raw ) {
		$decoded = json_decode( $raw, true );
		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
			return $decoded;
		}

		// Retry once after URL-decoding — the documented response encoding.
		$unwrapped = rawurldecode( (string) $raw );
		if ( $unwrapped !== $raw ) {
			$decoded = json_decode( $unwrapped, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return null;
	}

	/** Header signature from the supplied J&T Open Platform PHP example. */
	public static function build_header_digest( $biz_content, $private_key ) {
		return base64_encode( md5( (string) $biz_content . (string) $private_key, true ) );
	}

	/** Business-content signature from the supplied J&T Open Platform PHP example. */
	public static function build_content_digest( $customer_code, $customer_password, $private_key ) {
		$password_hash = strtoupper( md5( (string) $customer_password . self::PASSWORD_SALT ) );
		$source        = (string) $customer_code . $password_hash . (string) $private_key;
		return base64_encode( md5( $source, true ) );
	}

	/** Require the four independent Open Platform signing values. */
	private static function get_missing_signing_credentials( $credentials ) {
		$labels = array(
			'customer_code' => __( 'Customer Code', 'lovecatz-wc' ),
			'api_account' => __( 'API Account', 'lovecatz-wc' ),
			'private_key' => __( 'Private Key', 'lovecatz-wc' ),
			'customer_password' => __( 'Customer Password', 'lovecatz-wc' ),
		);
		$missing = array();
		foreach ( $labels as $field => $label ) {
			if ( '' === trim( (string) ( isset( $credentials[ $field ] ) ? $credentials[ $field ] : '' ) ) ) {
				$missing[] = $label;
			}
		}
		return $missing;
	}

	/**
	 * Separate transport success from J&T's business-level result.
	 *
	 * A 2xx response without a recognised result code is reported as a failed
	 * business call: treating an unrecognised payload as success would hide a
	 * contract change or an error body.
	 */
	private static function evaluate_business_response( $http_status, $decoded ) {
		$transport_ok = $http_status >= 200 && $http_status < 300;
		$result       = array( 'success' => false, 'code' => '', 'message' => '' );

		if ( ! is_array( $decoded ) ) {
			$result['message'] = __( 'Response body is not a JSON object.', 'lovecatz-wc' );
			return $result;
		}

		$code = '';
		$msg  = '';
		if ( isset( $decoded['code'] ) && is_scalar( $decoded['code'] ) ) {
			$code = sanitize_text_field( (string) $decoded['code'] );
		}
		if ( isset( $decoded['msg'] ) && is_scalar( $decoded['msg'] ) ) {
			$msg = sanitize_text_field( (string) $decoded['msg'] );
		} elseif ( isset( $decoded['message'] ) && is_scalar( $decoded['message'] ) ) {
			$msg = sanitize_text_field( (string) $decoded['message'] );
		}

		$result['code']    = $code;
		$result['message'] = $msg;

		if ( '' === $code && '' === $msg ) {
			$result['message'] = __( 'Respons tidak menyertakan kode hasil bisnis.', 'lovecatz-wc' );
			return $result;
		}

		// The manual documents "msg" as the request status (success / fail) and
		// never specifies which "code" value means success, so accept either
		// signal rather than betting on code === '1'.
		$result['success'] = $transport_ok && ( '1' === $code || 'success' === strtolower( $msg ) );

		// Sandbox errors arrive with a bare status word in "msg" and the real
		// reason in a sibling key (e.g. 145003082 + "fail"). Showing only
		// "fail" would leave the admin with a useless message, so the first
		// sibling that actually carries text wins for display. Only keys the
		// server really sent are considered - nothing is invented.
		if ( ! $result['success'] && in_array( strtolower( $msg ), array( 'fail', 'failed', 'error' ), true ) ) {
			foreach ( array( 'message', 'errorMsg', 'errorMessage', 'subMsg' ) as $candidate ) {
				if ( isset( $decoded[ $candidate ] ) && is_scalar( $decoded[ $candidate ] ) ) {
					$detail = sanitize_text_field( (string) $decoded[ $candidate ] );
					if ( '' !== $detail && ! in_array( strtolower( $detail ), array( 'fail', 'failed', 'error' ), true ) ) {
						$result['message'] = $detail;
						break;
					}
				}
			}
		}

		return $result;
	}

	/** Validate optional admin-supplied headers without permitting transport overrides. */
	private static function sanitize_test_headers( $custom_headers ) {
		$custom_headers = trim( (string) $custom_headers );
		if ( '' === $custom_headers ) {
			return array();
		}
		$decoded = json_decode( $custom_headers, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return new WP_Error( 'lwc_jtc_invalid_headers', __( 'Custom headers must be a valid JSON object.', 'lovecatz-wc' ) );
		}
		$blocked = array( 'host', 'content-length', 'cookie', 'apiaccount', 'digest', 'timestamp', 'content-type' );
		$headers = array();
		foreach ( $decoded as $name => $value ) {
			$name = trim( (string) $name );
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = trim( (string) $value );
			if ( ! preg_match( '/^[A-Za-z0-9-]+$/', $name ) || in_array( strtolower( $name ), $blocked, true ) || preg_match( '/[\r\n]/', $value ) ) {
				continue;
			}
			$headers[ $name ] = $value;
		}
		return $headers;
	}

	private static function normalize_uuid( $uuid ) {
		$uuid = strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) $uuid ) );
		return 32 === strlen( $uuid ) ? $uuid : '';
	}

	private static function sanitize_base_url( $base_url ) {
		$base_url = untrailingslashit( esc_url_raw( trim( (string) $base_url ) ) );
		return 'https' === wp_parse_url( $base_url, PHP_URL_SCHEME ) ? $base_url : '';
	}
}
