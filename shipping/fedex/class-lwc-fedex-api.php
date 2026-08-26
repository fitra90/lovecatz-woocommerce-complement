<?php
/**
 * FedEx API helper for rates, shipments, labels, tracking, and pickup requests.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_FedEx_API {

	/**
	 * FedEx credentials.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param array $settings Optional settings override.
	 */
	public function __construct( $settings = array() ) {
		$this->settings = wp_parse_args(
			$settings,
			array(
				'account_number' => LWC_FedEx_Account::get_option_value( 'lwc_fedex_account_number', '' ),
				'api_key'        => LWC_FedEx_Account::get_option_value( 'lwc_fedex_api_key', '' ),
				'api_secret'     => LWC_FedEx_Account::get_option_value( 'lwc_fedex_api_secret', '' ),
				'test_mode'      => LWC_FedEx_Account::get_option_value( 'lwc_fedex_test_mode', 'no' ),
			)
		);
	}

	/**
	 * Get shipping rates for every applicable FedEx service.
	 *
	 * @param array $package WooCommerce package data.
	 * @param float $max_package_weight_kg Split packages above this weight (0 disables splitting).
	 * @return array
	 */
	public function get_rate_quotes( $package, $max_package_weight_kg = 0 ) {
		$payload = $this->build_rate_payload( $package, $max_package_weight_kg );
		$origin  = isset( $payload['requestedShipment']['shipper']['address'] )
			? $payload['requestedShipment']['shipper']['address']
			: array();
		$destination = isset( $payload['requestedShipment']['recipient']['address'] )
			? $payload['requestedShipment']['recipient']['address']
			: array();

		$this->debug(
			'rate_prepare',
			array(
				'environment'          => 'yes' === $this->settings['test_mode'] ? 'sandbox' : 'production',
				'destination_country'  => isset( $destination['countryCode'] ) ? $destination['countryCode'] : '',
				'destination_state'    => isset( $destination['stateOrProvinceCode'] ) ? $destination['stateOrProvinceCode'] : '',
				'destination_postcode' => isset( $destination['postalCode'] ) ? $destination['postalCode'] : '',
				'origin_country'       => isset( $origin['countryCode'] ) ? $origin['countryCode'] : '',
				'origin_city_present'  => empty( $origin['city'] ) ? 'no' : 'yes',
				'origin_postcode'      => isset( $origin['postalCode'] ) ? $origin['postalCode'] : '',
				'package_count'        => isset( $payload['requestedShipment']['requestedPackageLineItems'] ) ? count( $payload['requestedShipment']['requestedPackageLineItems'] ) : 0,
			)
		);

		if ( empty( $origin['countryCode'] ) || empty( $origin['postalCode'] ) ) {
			$this->debug( 'rate_blocked', array( 'reason' => 'WooCommerce store country/postcode is incomplete.' ) );
			$this->log( 'Rate quote failed: WooCommerce store country/postcode is incomplete.', 'error' );
			return array(
				'success' => false,
				'message' => __( 'Complete the WooCommerce store address and postal code before requesting FedEx rates.', 'lovecatz-wc' ),
			);
		}

		// Reuse a recent quote for an identical request so checkout/cart
		// recalculations do not hit the FedEx API every time.
		$cache_key = 'lwc_fedex_rates_' . md5( $this->settings['test_mode'] . '|' . $this->settings['account_number'] . '|' . (string) wp_json_encode( $payload ) );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['success'], $cached['quotes'] ) ) {
			$this->debug( 'rate_cache_hit', array( 'quote_count' => count( $cached['quotes'] ) ) );
			return $cached;
		}

		$this->debug( 'oauth_start', array( 'environment' => 'yes' === $this->settings['test_mode'] ? 'sandbox' : 'production' ) );
		$token = $this->get_access_token();
		if ( '' === $token ) {
			$this->debug( 'oauth_failed', array( 'reason' => 'FedEx did not return an access token.' ) );
			$this->log( 'Rate quote failed: unable to authenticate.', 'error' );
			return array(
				'success' => false,
				'message' => __( 'Unable to authenticate with FedEx.', 'lovecatz-wc' ),
			);
		}
		$this->debug( 'oauth_success' );

		$this->debug( 'rate_request_sent', array( 'endpoint' => '/rate/v1/rates/quotes' ) );
		$response = $this->request( '/rate/v1/rates/quotes', $payload, $token );
		$body = $this->parse_response_body( $response );

		if ( ! $body ) {
			$this->debug(
				'rate_response_failed',
				array(
					'http_status' => is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response ),
					'reason'      => is_wp_error( $response ) ? $response->get_error_message() : 'Empty or invalid JSON response.',
				)
			);
			$this->log( 'Rate quote failed: empty or invalid response.', 'error' );
			return array(
				'success' => false,
				'message' => __( 'FedEx rate request returned no response.', 'lovecatz-wc' ),
			);
		}

		if ( isset( $body['errors'] ) ) {
			$message = $this->extract_error_message( $body );
			$this->debug( 'rate_response_rejected', array( 'http_status' => wp_remote_retrieve_response_code( $response ), 'message' => $message ) );
			$this->log( 'Rate quote failed: ' . $message, 'error' );
			return array(
				'success' => false,
				'message' => $message,
				'response' => $body,
			);
		}

		$quotes = $this->extract_quotes_from_body( $body );
		if ( empty( $quotes ) ) {
			$this->debug( 'rate_response_empty', $this->summarize_rate_response( $body ) );
			$this->log( 'Rate quote failed: no usable rate in response.', 'error' );
			return array(
				'success' => false,
				'message' => __( 'FedEx did not return a valid rate.', 'lovecatz-wc' ),
				'response' => $body,
			);
		}

		$result = array(
			'success' => true,
			'quotes'  => $quotes,
		);
		$this->debug( 'rate_response_success', array( 'quote_count' => count( $quotes ), 'services' => implode( ', ', wp_list_pluck( $quotes, 'service_type' ) ) ) );

		$ttl = (int) apply_filters( 'lwc_fedex_rate_cache_ttl', 30 * MINUTE_IN_SECONDS );
		if ( $ttl > 0 ) {
			set_transient( $cache_key, $result, $ttl );
		}

		return $result;
	}

	/**
	 * Get the cheapest shipping rate for a WooCommerce package.
	 *
	 * @param array $package WooCommerce package data.
	 * @param float $max_package_weight_kg Split packages above this weight (0 disables splitting).
	 * @return array
	 */
	public function get_rate_quote( $package, $max_package_weight_kg = 0 ) {
		$result = $this->get_rate_quotes( $package, $max_package_weight_kg );

		if ( empty( $result['success'] ) ) {
			return $result;
		}

		$best = null;
		foreach ( $result['quotes'] as $quote ) {
			if ( null === $best || $quote['rate'] < $best['rate'] ) {
				$best = $quote;
			}
		}

		return array(
			'success'      => true,
			'rate'         => $best['rate'],
			'service_type' => $best['service_type'],
		);
	}

	/**
	 * Perform a real OAuth handshake to verify the configured credentials.
	 *
	 * @return array
	 */
	public function test_connection() {
		$token = $this->get_access_token();
		if ( '' === $token ) {
			$this->log( 'Connection test failed: OAuth request rejected.', 'error' );
			return array(
				'success' => false,
				'message' => __( 'FedEx rejected the credentials (OAuth authentication failed).', 'lovecatz-wc' ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Authenticated with FedEx successfully.', 'lovecatz-wc' ),
		);
	}

	/**
	 * Create a shipment and fetch a label PDF.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @param float    $fallback_max_package_weight_kg Used when the order has no stored package weight.
	 * @param int[]    $item_ids Restrict the shipment to these order item IDs (manual partial shipping).
	 * @return array
	 */
	public function create_shipment( $order, $fallback_max_package_weight_kg = 0, $item_ids = array() ) {
		$token = $this->get_access_token();
		if ( '' === $token ) {
			return array(
				'success' => false,
				'message' => __( 'Unable to authenticate with FedEx.', 'lovecatz-wc' ),
			);
		}

		$payload = $this->build_shipment_payload( $order, $fallback_max_package_weight_kg, $item_ids );
		$response = $this->request( '/ship/v1/shipments', $payload, $token );
		$body = $this->parse_response_body( $response );

		if ( ! $body ) {
			$message = $this->describe_invalid_response( $response );
			$this->log( 'Shipment request failed: ' . $message, 'error' );
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: safe HTTP/transport error detail. */
					__( 'FedEx shipment request failed: %s', 'lovecatz-wc' ),
					$message
				),
			);
		}

		if ( isset( $body['errors'] ) ) {
			$message = $this->extract_error_message( $body );
			$this->log( 'Shipment request failed: ' . $message, 'error' );
			return array(
				'success' => false,
				'message' => $message,
				'response' => $body,
			);
		}

		$label_path = $this->save_label_from_response( $order, $body, $item_ids );
		return array(
			'success' => true,
			'label_path' => $label_path,
			'response' => $body,
		);
	}

	/**
	 * Retrieve current status and detailed scan history for tracking numbers.
	 *
	 * @param string[] $tracking_numbers FedEx tracking numbers (maximum 30).
	 * @return array
	 */
	public function track_shipments( $tracking_numbers ) {
		if ( 'yes' === $this->settings['test_mode'] ) {
			return array( 'success' => false, 'message' => __( 'Live tracking is available only in FedEx Production mode.', 'lovecatz-wc' ) );
		}
		$tracking_numbers = array_values( array_unique( array_filter( array_map( static function ( $number ) {
			return preg_replace( '/[^A-Za-z0-9]/', '', (string) $number );
		}, (array) $tracking_numbers ) ) ) );
		$tracking_numbers = array_slice( $tracking_numbers, 0, 30 );

		if ( empty( $tracking_numbers ) ) {
			return array( 'success' => false, 'message' => __( 'No FedEx tracking number is available.', 'lovecatz-wc' ) );
		}

		$token = $this->get_access_token();
		if ( '' === $token ) {
			return array( 'success' => false, 'message' => __( 'Unable to authenticate with FedEx.', 'lovecatz-wc' ) );
		}

		$tracking_info = array();
		foreach ( $tracking_numbers as $tracking_number ) {
			$tracking_info[] = array( 'trackingNumberInfo' => array( 'trackingNumber' => $tracking_number ) );
		}

		$response = $this->request(
			'/track/v1/trackingnumbers',
			array( 'includeDetailedScans' => true, 'trackingInfo' => $tracking_info ),
			$token
		);
		$body = $this->parse_response_body( $response );
		if ( ! $body ) {
			return array( 'success' => false, 'message' => __( 'FedEx tracking request returned no response.', 'lovecatz-wc' ) );
		}
		if ( isset( $body['errors'] ) ) {
			return array( 'success' => false, 'message' => $this->extract_error_message( $body ), 'response' => $body );
		}

		$tracks = array();
		$groups = isset( $body['output']['completeTrackResults'] ) && is_array( $body['output']['completeTrackResults'] ) ? $body['output']['completeTrackResults'] : array();
		foreach ( $groups as $group ) {
			$requested_number = isset( $group['trackingNumber'] ) ? (string) $group['trackingNumber'] : '';
			$results = isset( $group['trackResults'] ) && is_array( $group['trackResults'] ) ? $group['trackResults'] : array();
			foreach ( $results as $result ) {
				$normalized = $this->normalize_tracking_result( $result, $requested_number );
				if ( '' !== $normalized['tracking_number'] ) {
					$tracks[ $normalized['tracking_number'] ] = $normalized;
				}
			}
		}

		if ( empty( $tracks ) ) {
			return array( 'success' => false, 'message' => __( 'FedEx has not published tracking details for this shipment yet.', 'lovecatz-wc' ), 'response' => $body );
		}

		return array( 'success' => true, 'tracks' => $tracks );
	}

	/**
	 * Check available FedEx pickup windows for an order.
	 *
	 * @param WC_Order $order Order being collected.
	 * @param array    $pickup Pickup date/time and carrier fields.
	 * @return array
	 */
	public function check_pickup_availability( $order, $pickup ) {
		if ( 'yes' === $this->settings['test_mode'] ) {
			return array( 'success' => false, 'message' => __( 'Courier pickup is available only in FedEx Production mode.', 'lovecatz-wc' ) );
		}
		$token = $this->get_access_token();
		if ( '' === $token ) {
			return array( 'success' => false, 'message' => __( 'Unable to authenticate with FedEx.', 'lovecatz-wc' ) );
		}

		$response = $this->request( '/pickup/v1/pickups/availabilities', $this->build_pickup_availability_payload( $order, $pickup ), $token );
		$body = $this->parse_response_body( $response );
		if ( ! $body ) {
			return array( 'success' => false, 'message' => __( 'FedEx pickup availability returned no response.', 'lovecatz-wc' ) );
		}
		if ( isset( $body['errors'] ) ) {
			return array( 'success' => false, 'message' => $this->extract_error_message( $body ), 'response' => $body );
		}

		$options = $this->normalize_pickup_options( $body );
		if ( ! empty( $options ) && ! wp_list_filter( $options, array( 'available' => true ) ) ) {
			return array( 'success' => false, 'message' => __( 'FedEx pickup is not available for the requested window.', 'lovecatz-wc' ), 'options' => $options );
		}

		return array(
			'success' => true,
			'message' => __( 'FedEx pickup is available for the requested window.', 'lovecatz-wc' ),
			'options' => $options,
			'response' => $body,
		);
	}

	/**
	 * Schedule a FedEx courier pickup for an order.
	 *
	 * @param WC_Order $order Order being collected.
	 * @param array    $pickup Pickup date/time and carrier fields.
	 * @return array
	 */
	public function create_pickup( $order, $pickup ) {
		if ( 'yes' === $this->settings['test_mode'] ) {
			return array( 'success' => false, 'message' => __( 'Courier pickup is available only in FedEx Production mode.', 'lovecatz-wc' ) );
		}
		$token = $this->get_access_token();
		if ( '' === $token ) {
			return array( 'success' => false, 'message' => __( 'Unable to authenticate with FedEx.', 'lovecatz-wc' ) );
		}

		$response = $this->request( '/pickup/v1/pickups', $this->build_create_pickup_payload( $order, $pickup ), $token );
		$body = $this->parse_response_body( $response );
		if ( ! $body ) {
			return array( 'success' => false, 'message' => __( 'FedEx pickup request returned no response.', 'lovecatz-wc' ) );
		}
		if ( isset( $body['errors'] ) ) {
			return array( 'success' => false, 'message' => $this->extract_error_message( $body ), 'response' => $body );
		}

		$output = isset( $body['output'] ) && is_array( $body['output'] ) ? $body['output'] : array();
		$confirmation = '';
		foreach ( array( 'pickupConfirmationCode', 'confirmationNumber', 'dispatchConfirmationNumber' ) as $key ) {
			if ( ! empty( $output[ $key ] ) ) {
				$confirmation = (string) $output[ $key ];
				break;
			}
		}
		$location = isset( $output['location'] ) ? (string) $output['location'] : ( isset( $output['locationCode'] ) ? (string) $output['locationCode'] : '' );

		if ( '' === $confirmation ) {
			return array( 'success' => false, 'message' => __( 'FedEx accepted the request but returned no pickup confirmation number.', 'lovecatz-wc' ), 'response' => $body );
		}

		return array(
			'success'             => true,
			'message'             => __( 'FedEx pickup scheduled successfully.', 'lovecatz-wc' ),
			'confirmation_number' => $confirmation,
			'location'            => $location,
			'response'            => $body,
		);
	}

	/**
	 * Cancel a previously scheduled FedEx pickup.
	 *
	 * @param array $pickup Stored pickup record.
	 * @return array
	 */
	public function cancel_pickup( $pickup ) {
		if ( 'yes' === $this->settings['test_mode'] ) {
			return array( 'success' => false, 'message' => __( 'Courier pickup is available only in FedEx Production mode.', 'lovecatz-wc' ) );
		}
		$token = $this->get_access_token();
		if ( '' === $token ) {
			return array( 'success' => false, 'message' => __( 'Unable to authenticate with FedEx.', 'lovecatz-wc' ) );
		}

		$payload = array(
			'associatedAccountNumber' => array( 'value' => $this->settings['account_number'] ),
			'confirmationNumber'      => isset( $pickup['confirmation_number'] ) ? (string) $pickup['confirmation_number'] : '',
			'location'                => isset( $pickup['location'] ) ? (string) $pickup['location'] : '',
			'scheduledDate'           => isset( $pickup['date'] ) ? (string) $pickup['date'] : '',
			'carrierCode'             => isset( $pickup['carrier'] ) ? (string) $pickup['carrier'] : 'FDXE',
		);
		$response = $this->request( '/pickup/v1/pickups/cancel', $payload, $token, 'PUT' );
		$body = $this->parse_response_body( $response );
		if ( ! $body && ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) >= 200 && wp_remote_retrieve_response_code( $response ) < 300 ) {
			return array( 'success' => true, 'message' => __( 'FedEx pickup cancelled successfully.', 'lovecatz-wc' ) );
		}
		if ( ! $body ) {
			return array( 'success' => false, 'message' => __( 'FedEx pickup cancellation returned no response.', 'lovecatz-wc' ) );
		}
		if ( isset( $body['errors'] ) ) {
			return array( 'success' => false, 'message' => $this->extract_error_message( $body ), 'response' => $body );
		}

		return array( 'success' => true, 'message' => __( 'FedEx pickup cancelled successfully.', 'lovecatz-wc' ), 'response' => $body );
	}

	/**
	 * Resolve the FedEx base URL from the selected mode.
	 *
	 * @return string
	 */
	public function get_api_base_url() {
		if ( 'yes' === $this->settings['test_mode'] ) {
			return 'https://apis-sandbox.fedex.com';
		}

		return 'https://apis.fedex.com';
	}

	/**
	 * Build the OAuth access token.
	 *
	 * @return string
	 */
	private function get_access_token() {
		$cache_key = 'lwc_fedex_token_' . md5( $this->get_api_base_url() . '|' . $this->settings['api_key'] . '|' . $this->settings['api_secret'] );
		$cached = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			$this->debug( 'oauth_cache_hit' );
			return $cached;
		}

		$url = rtrim( $this->get_api_base_url(), '/' ) . '/oauth/token';
		$args = array(
			'headers' => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
			// FedEx requires the project key and secret as form fields. HTTP
			// Basic authentication is not accepted by the FedEx OAuth endpoint.
			'body'    => array(
				'grant_type'    => 'client_credentials',
				'client_id'     => $this->settings['api_key'],
				'client_secret' => $this->settings['api_secret'],
			),
			'timeout' => 20,
		);
		$response = wp_remote_post( $url, $args );
		if ( is_wp_error( $response ) ) {
			$this->debug( 'oauth_http_error', array( 'message' => $response->get_error_message() ) );
			$this->log( 'OAuth request failed: ' . sanitize_text_field( $response->get_error_message() ), 'error' );
			return '';
		}

		$body = $this->parse_response_body( $response );
		if ( ! is_array( $body ) || empty( $body['access_token'] ) ) {
			$error_message = isset( $body['error_description'] ) ? $body['error_description'] : ( isset( $body['error'] ) ? $body['error'] : $this->describe_invalid_response( $response ) );
			$this->debug(
				'oauth_rejected',
				array(
					'http_status' => wp_remote_retrieve_response_code( $response ),
					'message'     => $error_message,
				)
			);
			$this->log( 'OAuth request rejected: ' . sanitize_text_field( $error_message ), 'error' );
			return '';
		}

		$token = (string) $body['access_token'];
		$expires = isset( $body['expires_in'] ) ? absint( $body['expires_in'] ) : HOUR_IN_SECONDS;
		set_transient( $cache_key, $token, max( MINUTE_IN_SECONDS, $expires - MINUTE_IN_SECONDS ) );

		return $token;
	}

	/**
	 * Send a request to the FedEx API.
	 *
	 * @param string $path API path.
	 * @param array $payload Request payload.
	 * @param string $token Access token.
	 * @return array|WP_Error
	 */
	private function request( $path, $payload, $token, $method = 'POST' ) {
		$url = rtrim( $this->get_api_base_url(), '/' ) . $path;
		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( $payload ),
			'timeout' => '/rate/' === substr( $path, 0, 6 ) ? 20 : 60,
			'method' => strtoupper( $method ),
		);

		return wp_remote_request( $url, $args );
	}

	/**
	 * Parse the response body.
	 *
	 * @param array|WP_Error $response WP_HTTP response.
	 * @return array|false
	 */
	private function parse_response_body( $response ) {
		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return false;
		}

		$decoded = json_decode( $body, true );
		return is_array( $decoded ) ? $decoded : false;
	}

	/**
	 * Describe a failed response without exposing credentials or label data.
	 *
	 * @param array|WP_Error $response WordPress HTTP response.
	 * @return string
	 */
	private function describe_invalid_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return sanitize_text_field( $response->get_error_message() );
		}

		$status       = (int) wp_remote_retrieve_response_code( $response );
		$content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$body         = trim( (string) wp_remote_retrieve_body( $response ) );

		if ( '' === $body ) {
			return sprintf( 'HTTP %d returned an empty response.', $status );
		}

		// FedEx errors are JSON. For HTML/proxy failures, record only a short,
		// tag-free excerpt so logs never contain credentials or label content.
		$excerpt = sanitize_text_field( wp_strip_all_tags( substr( $body, 0, 300 ) ) );
		return sprintf(
			'HTTP %1$d returned invalid JSON%2$s%3$s',
			$status,
			'' !== $content_type ? ' (' . sanitize_text_field( $content_type ) . ')' : '',
			'' !== $excerpt ? ': ' . $excerpt : '.'
		);
	}

	/**
	 * Extract a user-friendly error.
	 *
	 * @param array $body API body.
	 * @return string
	 */
	private function extract_error_message( $body ) {
		if ( isset( $body['errors'][0]['message'] ) ) {
			return (string) $body['errors'][0]['message'];
		}

		if ( isset( $body['error_description'] ) ) {
			return (string) $body['error_description'];
		}

		return __( 'FedEx returned an unexpected error.', 'lovecatz-wc' );
	}

	/**
	 * Convert a FedEx Track API result into a stable order-meta structure.
	 *
	 * @param array  $result Track result.
	 * @param string $fallback_number Requested tracking number.
	 * @return array
	 */
	private function normalize_tracking_result( $result, $fallback_number = '' ) {
		$number = isset( $result['trackingNumberInfo']['trackingNumber'] ) ? (string) $result['trackingNumberInfo']['trackingNumber'] : $fallback_number;
		$status = isset( $result['latestStatusDetail'] ) && is_array( $result['latestStatusDetail'] ) ? $result['latestStatusDetail'] : array();
		$status_label = '';
		foreach ( array( 'statusByLocale', 'description', 'scanLocation' ) as $key ) {
			if ( isset( $status[ $key ] ) && is_scalar( $status[ $key ] ) && '' !== trim( (string) $status[ $key ] ) ) {
				$status_label = trim( (string) $status[ $key ] );
				break;
			}
		}

		$estimated_delivery = '';
		$actual_delivery = '';
		$date_times = isset( $result['dateAndTimes'] ) && is_array( $result['dateAndTimes'] ) ? $result['dateAndTimes'] : array();
		foreach ( $date_times as $date_time ) {
			$type = isset( $date_time['type'] ) ? strtoupper( (string) $date_time['type'] ) : '';
			$value = isset( $date_time['dateTime'] ) ? (string) $date_time['dateTime'] : '';
			if ( 'ACTUAL_DELIVERY' === $type ) {
				$actual_delivery = $value;
			} elseif ( in_array( $type, array( 'ESTIMATED_DELIVERY', 'COMMITMENT', 'APPOINTMENT_DELIVERY' ), true ) && '' === $estimated_delivery ) {
				$estimated_delivery = $value;
			}
		}
		if ( '' === $estimated_delivery && isset( $result['estimatedDeliveryTimeWindow']['window']['ends'] ) ) {
			$estimated_delivery = (string) $result['estimatedDeliveryTimeWindow']['window']['ends'];
		}

		$events = array();
		$scan_events = isset( $result['scanEvents'] ) && is_array( $result['scanEvents'] ) ? $result['scanEvents'] : array();
		foreach ( $scan_events as $event ) {
			$description = isset( $event['eventDescription'] ) ? (string) $event['eventDescription'] : '';
			if ( '' === $description && isset( $event['derivedStatus'] ) ) {
				$description = (string) $event['derivedStatus'];
			}
			$events[] = array(
				'date'        => isset( $event['date'] ) ? (string) $event['date'] : '',
				'type'        => isset( $event['eventType'] ) ? (string) $event['eventType'] : '',
				'description' => $description,
				'exception'   => isset( $event['exceptionDescription'] ) ? (string) $event['exceptionDescription'] : '',
				'location'    => isset( $event['scanLocation'] ) && is_array( $event['scanLocation'] ) ? $this->format_tracking_location( $event['scanLocation'] ) : '',
			);
		}

		return array(
			'tracking_number'   => preg_replace( '/[^A-Za-z0-9]/', '', $number ),
			'status_code'       => isset( $status['derivedCode'] ) ? (string) $status['derivedCode'] : ( isset( $status['code'] ) ? (string) $status['code'] : '' ),
			'status'            => $status_label,
			'location'          => isset( $status['scanLocation'] ) && is_array( $status['scanLocation'] ) ? $this->format_tracking_location( $status['scanLocation'] ) : '',
			'estimated_delivery'=> $estimated_delivery,
			'actual_delivery'   => $actual_delivery,
			'service'           => isset( $result['serviceDetail']['description'] ) ? (string) $result['serviceDetail']['description'] : '',
			'events'            => $events,
			'updated_at'        => current_time( 'mysql' ),
		);
	}

	/**
	 * Format a FedEx scan location without exposing recipient details.
	 *
	 * @param array $location Location fields.
	 * @return string
	 */
	private function format_tracking_location( $location ) {
		$parts = array();
		foreach ( array( 'city', 'stateOrProvinceCode', 'countryName', 'countryCode' ) as $key ) {
			if ( ! empty( $location[ $key ] ) && ! in_array( (string) $location[ $key ], $parts, true ) ) {
				$parts[] = (string) $location[ $key ];
			}
		}
		return implode( ', ', $parts );
	}

	/**
	 * Build the Pickup Availability API request.
	 *
	 * @param WC_Order $order Order being collected.
	 * @param array    $pickup Pickup fields.
	 * @return array
	 */
	private function build_pickup_availability_payload( $order, $pickup ) {
		$date = isset( $pickup['date'] ) ? (string) $pickup['date'] : current_time( 'Y-m-d' );
		$origin = $this->build_origin_address();
		$destination = $this->build_destination_address_from_order( $order );
		$relationship = isset( $destination['countryCode'] ) && $destination['countryCode'] === $origin['countryCode'] ? 'DOMESTIC' : 'INTERNATIONAL';

		return array(
			'associatedAccountNumber' => array( 'value' => $this->settings['account_number'] ),
			'pickupAddress'            => $origin,
			'pickupRequestType'        => array( $date === current_time( 'Y-m-d' ) ? 'SAME_DAY' : 'FUTURE_DAY' ),
			'dispatchDate'             => $date,
			'packageReadyTime'         => isset( $pickup['ready_time'] ) ? (string) $pickup['ready_time'] . ':00' : '09:00:00',
			'customerCloseTime'        => isset( $pickup['close_time'] ) ? (string) $pickup['close_time'] . ':00' : '17:00:00',
			'carriers'                 => array( isset( $pickup['carrier'] ) ? (string) $pickup['carrier'] : 'FDXE' ),
			'countryRelationship'      => $relationship,
		);
	}

	/**
	 * Build the Create Pickup API request from store and order data.
	 *
	 * @param WC_Order $order Order being collected.
	 * @param array    $pickup Pickup fields.
	 * @return array
	 */
	private function build_create_pickup_payload( $order, $pickup ) {
		$context = $this->get_order_shipping_context( $order );
		$packages = $this->build_packages_from_order( $order, $context['max_package_weight_kg'] );
		$total_weight = 0.0;
		foreach ( $packages as $package ) {
			$total_weight += isset( $package['weight'] ) ? (float) $package['weight'] : 0.0;
		}
		$date = isset( $pickup['date'] ) ? (string) $pickup['date'] : current_time( 'Y-m-d' );
		$ready = isset( $pickup['ready_time'] ) ? (string) $pickup['ready_time'] : '09:00';
		$origin = $this->build_origin_address();
		$contact = $this->build_shipper_contact();
		$contact['companyName'] = get_bloginfo( 'name' );

		return array(
			'associatedAccountNumber' => array( 'value' => $this->settings['account_number'] ),
			'originDetail' => array(
				'pickupLocation' => array(
					'contact'       => $contact,
					'address'       => $origin,
					'accountNumber' => array( 'value' => $this->settings['account_number'] ),
				),
				'readyDateTimestamp' => $date . 'T' . $ready . ':00',
				'customerCloseTime'  => isset( $pickup['close_time'] ) ? (string) $pickup['close_time'] . ':00' : '17:00:00',
			),
			'accountAddressOfRecord' => $origin,
			'carrierCode'           => isset( $pickup['carrier'] ) ? (string) $pickup['carrier'] : 'FDXE',
			'packageCount'          => max( 1, count( $packages ) ),
			'totalWeight'           => array( 'units' => 'KG', 'value' => max( 0.5, round( $total_weight, 2 ) ) ),
			'packageLocation'       => 'NONE',
			'remarks'               => sprintf( 'WooCommerce order %s', $order->get_order_number() ),
		);
	}

	/**
	 * Reduce the availability response to fields useful on the order screen.
	 *
	 * @param array $body FedEx response.
	 * @return array
	 */
	private function normalize_pickup_options( $body ) {
		$output = isset( $body['output'] ) && is_array( $body['output'] ) ? $body['output'] : array();
		$options = isset( $output['options'] ) && is_array( $output['options'] ) ? $output['options'] : array();
		if ( empty( $options ) && isset( $output['pickupOptions'] ) && is_array( $output['pickupOptions'] ) ) {
			$options = $output['pickupOptions'];
		}
		if ( empty( $options ) && ! empty( $output ) ) {
			$options = array( $output );
		}

		$normalized = array();
		foreach ( $options as $option ) {
			$available = ! isset( $option['available'] ) || true === $option['available'] || 1 === $option['available'] || 'true' === strtolower( (string) $option['available'] );
			$normalized[] = array(
				'carrier'      => isset( $option['carrier'] ) ? (string) $option['carrier'] : ( isset( $option['carrierCode'] ) ? (string) $option['carrierCode'] : '' ),
				'available'    => $available,
				'pickup_date'  => isset( $option['pickupDate'] ) ? (string) $option['pickupDate'] : '',
				'cutoff_time'  => isset( $option['cutOffTime'] ) ? (string) $option['cutOffTime'] : ( isset( $option['cutoffTime'] ) ? (string) $option['cutoffTime'] : '' ),
				'access_time'  => isset( $option['accessTime'] ) ? (string) $option['accessTime'] : '',
			);
		}
		return $normalized;
	}

	/**
	 * Build a request payload for rate quotes.
	 *
	 * No serviceType is sent so FedEx returns every applicable service.
	 *
	 * @param array $package Package data.
	 * @param float $max_package_weight_kg Split packages above this weight (0 disables splitting).
	 * @return array
	 */
	private function build_rate_payload( $package, $max_package_weight_kg = 0 ) {
		$origin = $this->build_origin_address();
		$destination = $this->build_destination_address( $package );
		$packages = $this->build_packages_from_cart( $package, $max_package_weight_kg );
		$preferred_currency = strtoupper( (string) get_option( 'woocommerce_currency', 'IDR' ) );
		if ( ! preg_match( '/^[A-Z]{3}$/', $preferred_currency ) ) {
			$preferred_currency = 'IDR';
		}

		return array(
			'accountNumber' => array(
				'value' => $this->settings['account_number'],
			),
			'requestedShipment' => array(
				// Required by the REST Rates API. ACCOUNT returns the merchant's
				// negotiated FedEx prices rather than an unrelated list estimate.
				'rateRequestType' => array( 'ACCOUNT', 'PREFERRED' ),
				'preferredCurrency' => $preferred_currency,
				'shipper' => array(
					'address' => $origin,
				),
				'recipient' => array(
					'address' => $destination,
				),
				'pickupType' => 'USE_SCHEDULED_PICKUP',
				'packagingType' => 'YOUR_PACKAGING',
				'requestedPackageLineItems' => $this->format_package_line_items( $packages ),
			),
		);
	}

	/**
	 * Build a request payload for shipment creation.
	 *
	 * The service type and package weight come from the shipping method the
	 * customer selected at checkout (stored as rate meta) and fall back to
	 * the country defaults when unavailable.
	 *
	 * @param WC_Order $order Order object.
	 * @param float    $fallback_max_package_weight_kg Used when the order has no stored package weight.
	 * @param int[]    $item_ids Restrict the shipment to these order item IDs.
	 * @return array
	 */
	private function build_shipment_payload( $order, $fallback_max_package_weight_kg = 0, $item_ids = array() ) {
		$origin = $this->build_origin_address();
		$destination = $this->build_destination_address_from_order( $order );
		$context = $this->get_order_shipping_context( $order );

		$service_type = '' !== $context['service_type']
			? $context['service_type']
			: $this->get_service_type( isset( $destination['countryCode'] ) ? $destination['countryCode'] : '' );

		$max_package_weight_kg = $context['max_package_weight_kg'] > 0
			? $context['max_package_weight_kg']
			: (float) $fallback_max_package_weight_kg;

		$packages = $this->build_packages_from_order( $order, $max_package_weight_kg, $item_ids );

		$payload = array(
			'accountNumber' => array(
				'value' => $this->settings['account_number'],
			),
			'labelResponseOptions' => 'LABEL',
			'requestedShipment' => array(
				'shipper' => array(
					'contact' => $this->build_shipper_contact(),
					'address' => $origin,
				),
				'recipients' => array(
					array(
						'contact' => $this->build_recipient_contact( $order ),
						'address' => $destination,
					),
				),
				'shipDatestamp' => current_time( 'Y-m-d' ),
				'pickupType' => 'USE_SCHEDULED_PICKUP',
				'serviceType' => $service_type,
				'packagingType' => 'YOUR_PACKAGING',
				'shippingChargesPayment' => array(
					'paymentType' => 'SENDER',
					'accountNumber' => array(
						'value' => $this->settings['account_number'],
					),
				),
				'labelSpecification' => array(
					'labelFormatType' => 'COMMON2D',
					'labelStockType' => 'PAPER_4X6',
					'imageType' => 'PDF',
				),
				'requestedPackageLineItems' => $this->format_package_line_items( $packages ),
			),
		);

		if ( class_exists( 'WC_Countries' ) ) {
			$base_country = WC()->countries ? WC()->countries->get_base_country() : '';
		} else {
			$base_country = get_option( 'woocommerce_default_country', '' );
			$base_country = is_string( $base_country ) ? substr( $base_country, 0, 2 ) : '';
		}

		if ( '' !== $destination['countryCode'] && 0 !== strcasecmp( $destination['countryCode'], (string) $base_country ) ) {
			$payload['requestedShipment']['customsClearanceDetail'] = $this->build_customs_detail( $order, $item_ids );
		}

		return $payload;
	}

	/**
	 * Build the shipper contact from plugin settings.
	 *
	 * @return array
	 */
	private function build_shipper_contact() {
		return array(
			'personName'  => get_option( 'lwc_fedex_shipper_name', get_bloginfo( 'name' ) ),
			'phoneNumber' => get_option( 'lwc_fedex_shipper_phone', '' ),
		);
	}

	/**
	 * Build the recipient contact from an order.
	 *
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	private function build_recipient_contact( $order ) {
		$name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		if ( '' === $name ) {
			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}

		$phone = (string) $order->get_billing_phone();
		if ( '' === $phone && method_exists( $order, 'get_shipping_phone' ) ) {
			$phone = (string) $order->get_shipping_phone();
		}

		return array(
			'personName'  => $name,
			'phoneNumber' => $phone,
		);
	}

	/**
	 * Build the customs clearance detail required for international shipments.
	 *
	 * @param WC_Order $order Order object.
	 * @param int[]    $item_ids Restrict commodities to these order item IDs.
	 * @return array
	 */
	private function build_customs_detail( $order, $item_ids = array() ) {
		$currency = $order->get_currency();
		$base_country = $this->get_base_country();
		$commodities = array();
		$total = 0.0;

		foreach ( $order->get_items() as $item ) {
			if ( ! empty( $item_ids ) && ! in_array( (int) $item->get_id(), array_map( 'intval', $item_ids ), true ) ) {
				continue;
			}

			$product = $item->get_product();
			$quantity = max( 1, (float) $item->get_quantity() );
			$unit_price = (float) $order->get_item_subtotal( $item, false, true );
			$line_total = $unit_price * $quantity;
			$total += $line_total;

			$commodity = array(
				'description' => wp_strip_all_tags( $item->get_name() ),
				'countryOfManufacture' => apply_filters(
					'lwc_fedex_commodity_country_of_manufacture',
					$base_country,
					$product,
					$item
				),
				'quantity' => $quantity,
				'quantityUnits' => 'PCS',
				'unitPrice' => array(
					'amount' => round( $unit_price, 2 ),
					'currency' => $currency,
				),
				'customsValue' => array(
					'amount' => round( $line_total, 2 ),
					'currency' => $currency,
				),
				'weight' => array(
					'units' => 'KG',
					'value' => 0.5,
				),
			);

			if ( $product && $product->get_weight() ) {
				$commodity['weight']['value'] = round( floatval( wc_get_weight( $product->get_weight(), 'kg' ) ) * $quantity, 2 );
			}

			$commodities[] = $commodity;
		}

		if ( empty( $commodities ) ) {
			$total = (float) $order->get_total();
			$commodities[] = array(
				'description' => __( 'Merchandise', 'lovecatz-wc' ),
				'countryOfManufacture' => $base_country,
				'quantity' => 1,
				'quantityUnits' => 'PCS',
				'unitPrice' => array(
					'amount' => round( $total, 2 ),
					'currency' => $currency,
				),
				'customsValue' => array(
					'amount' => round( $total, 2 ),
					'currency' => $currency,
				),
				'weight' => array(
					'units' => 'KG',
					'value' => 0.5,
				),
			);
		}

		return array(
			'dutiesPayment' => array(
				'paymentType' => apply_filters( 'lwc_fedex_duties_payment_type', 'RECIPIENT', $order ),
			),
			'termsOfSale' => apply_filters( 'lwc_fedex_terms_of_sale', 'DAP', $order ),
			'insuranceProvider' => 'NONE',
			'invoiceNumber' => (string) $order->get_order_number(),
			'invoiceDate' => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : current_time( 'Y-m-d' ),
			'commodities' => $commodities,
			'totalCustomsValue' => array(
				'amount' => round( $total, 2 ),
				'currency' => $currency,
			),
		);
	}

	/**
	 * Build a store-origin address using WooCommerce settings.
	 *
	 * @return array
	 */
	private function build_origin_address() {
		$country = WC()->countries->get_base_country();
		$state = WC()->countries->get_base_state();
		$address = array(
			'streetLines' => array(
				get_option( 'woocommerce_store_address', '' ),
				get_option( 'woocommerce_store_address_2', '' ),
			),
			'city' => get_option( 'woocommerce_store_city', '' ),
			'stateOrProvinceCode' => $state,
			'postalCode' => get_option( 'woocommerce_store_postcode', '' ),
			'countryCode' => $country,
		);

		$address['streetLines'] = array_values( array_filter( $address['streetLines'] ) );
		return $address;
	}

	/**
	 * Build a destination address from a package.
	 *
	 * @param array $package Package data.
	 * @return array
	 */
	private function build_destination_address( $package ) {
		$country = isset( $package['destination']['country'] ) ? (string) $package['destination']['country'] : '';
		$state = isset( $package['destination']['state'] ) ? (string) $package['destination']['state'] : '';
		$postcode = isset( $package['destination']['postcode'] ) ? (string) $package['destination']['postcode'] : '';
		$city = isset( $package['destination']['city'] ) ? (string) $package['destination']['city'] : '';

		return array(
			'streetLines' => array(),
			'city' => $city,
			'stateOrProvinceCode' => $state,
			'postalCode' => $postcode,
			'countryCode' => $country,
		);
	}

	/**
	 * Build a destination address from an order.
	 *
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	private function build_destination_address_from_order( $order ) {
		$country = $order->get_shipping_country() ? $order->get_shipping_country() : $order->get_billing_country();
		$state = $order->get_shipping_state() ? $order->get_shipping_state() : $order->get_billing_state();
		$postcode = $order->get_shipping_postcode() ? $order->get_shipping_postcode() : $order->get_billing_postcode();
		$city = $order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city();

		$street = array_filter( array( $order->get_shipping_address_1(), $order->get_shipping_address_2() ) );
		if ( empty( $street ) ) {
			$street = array_filter( array( $order->get_billing_address_1(), $order->get_billing_address_2() ) );
		}

		return array(
			'streetLines' => array_values( $street ),
			'city' => $city,
			'stateOrProvinceCode' => $state,
			'postalCode' => $postcode,
			'countryCode' => $country,
		);
	}

	/**
	 * Build package definitions from a WooCommerce cart package.
	 *
	 * @param array $package Package data.
	 * @param float $max_package_weight_kg Split packages above this weight (0 disables splitting).
	 * @return array
	 */
	private function build_packages_from_cart( $package, $max_package_weight_kg = 0 ) {
		$entries = array();

		if ( ! empty( $package['contents'] ) && is_array( $package['contents'] ) ) {
			foreach ( $package['contents'] as $item ) {
				if ( empty( $item['data'] ) || ! is_a( $item['data'], 'WC_Product' ) ) {
					continue;
				}

				$quantity = isset( $item['quantity'] ) ? max( 1, (float) $item['quantity'] ) : 1;
				$product = $item['data'];

				$entries[] = array(
					'weight' => floatval( wc_get_weight( $product->get_weight(), 'kg' ) ) * $quantity,
					'volume' => $this->get_product_volume_cm3( $product ) * $quantity,
				);
			}
		}

		return $this->split_into_packages( $entries, $max_package_weight_kg );
	}

	/**
	 * Build package definitions from an order.
	 *
	 * @param WC_Order $order Order object.
	 * @param float    $max_package_weight_kg Split packages above this weight (0 disables splitting).
	 * @param int[]    $item_ids Restrict packages to these order item IDs.
	 * @return array
	 */
	private function build_packages_from_order( $order, $max_package_weight_kg = 0, $item_ids = array() ) {
		$entries = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! empty( $item_ids ) && ! in_array( (int) $item->get_id(), array_map( 'intval', $item_ids ), true ) ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$quantity = max( 1, (float) $item->get_quantity() );

			$entries[] = array(
				'weight' => floatval( wc_get_weight( $product->get_weight(), 'kg' ) ) * $quantity,
				'volume' => $this->get_product_volume_cm3( $product ) * $quantity,
			);
		}

		return $this->split_into_packages( $entries, $max_package_weight_kg );
	}

	/**
	 * Group line entries into packages, starting a new package whenever the
	 * configured maximum weight would be exceeded.
	 *
	 * @param array $entries Array of arrays with weight/volume keys.
	 * @param float $max_package_weight_kg Maximum weight per package (0 disables splitting).
	 * @return array
	 */
	private function split_into_packages( $entries, $max_package_weight_kg = 0 ) {
		$max_package_weight_kg = (float) $max_package_weight_kg;

		// FedEx rejects parcels above 68 kg (150 lbs) on every parcel service;
		// heavier loads require freight. Cap the configured split threshold.
		$ceiling = (float) apply_filters( 'lwc_fedex_package_weight_ceiling_kg', 68 );
		if ( $ceiling > 0 && $max_package_weight_kg > $ceiling ) {
			$max_package_weight_kg = $ceiling;
		}

		$packages = array();
		$current = array(
			'weight' => 0.0,
			'volume' => 0.0,
		);

		foreach ( $entries as $entry ) {
			$weight = (float) $entry['weight'];
			// Items without a weight still occupy space; use a small floor.
			if ( $weight <= 0 ) {
				$weight = 0.1;
			}

			if ( $max_package_weight_kg > 0 && $current['weight'] > 0
				&& ( $current['weight'] + $weight ) > $max_package_weight_kg ) {
				$packages[] = $current;
				$current = array(
					'weight' => 0.0,
					'volume' => 0.0,
				);
			}

			$current['weight'] += $weight;
			$current['volume'] += isset( $entry['volume'] ) ? (float) $entry['volume'] : 0.0;
		}

		if ( $current['weight'] > 0 ) {
			$packages[] = $current;
		}

		if ( empty( $packages ) ) {
			$packages[] = array(
				'weight' => 0.5,
				'volume' => 0.0,
			);
		}

		$formatted = array();
		foreach ( $packages as $package ) {
			$formatted[] = array(
				'weight' => max( round( $package['weight'], 2 ), 0.5 ),
				'dimensions' => $this->cube_dimensions( $package['volume'] ),
			);
		}

		return $formatted;
	}

	/**
	 * Derive box dimensions from a volume using its cube root.
	 *
	 * @param float $volume_cm3 Volume in cubic centimeters.
	 * @return array|null
	 */
	private function cube_dimensions( $volume_cm3 ) {
		$volume_cm3 = (float) $volume_cm3;
		if ( $volume_cm3 <= 0 ) {
			return null;
		}

		$side = (int) ceil( pow( $volume_cm3, 1 / 3 ) );
		$side = max( 1, $side );

		return array(
			'units' => 'CM',
			'length' => $side,
			'width' => $side,
			'height' => $side,
		);
	}

	/**
	 * Get a product's volume in cubic centimeters.
	 *
	 * @param WC_Product $product Product object.
	 * @return float
	 */
	private function get_product_volume_cm3( $product ) {
		$length = (float) wc_get_dimension( $product->get_length(), 'cm' );
		$width = (float) wc_get_dimension( $product->get_width(), 'cm' );
		$height = (float) wc_get_dimension( $product->get_height(), 'cm' );

		if ( $length <= 0 || $width <= 0 || $height <= 0 ) {
			return 0.0;
		}

		return $length * $width * $height;
	}

	/**
	 * Format packages as FedEx requestedPackageLineItems.
	 *
	 * @param array $packages Packages from split_into_packages().
	 * @return array
	 */
	private function format_package_line_items( $packages ) {
		$line_items = array();

		foreach ( $packages as $package ) {
			$line = array(
				'weight' => array(
					'units' => 'KG',
					'value' => round( $package['weight'], 2 ),
				),
			);

			if ( ! empty( $package['dimensions'] ) && is_array( $package['dimensions'] ) ) {
				$line['dimensions'] = $package['dimensions'];
			}

			$line_items[] = $line;
		}

		return $line_items;
	}

	/**
	 * Read the FedEx service and package weight chosen at checkout.
	 *
	 * Rate meta added by the shipping method is transferred to the order's
	 * shipping items, so it can drive shipment creation later.
	 *
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	private function get_order_shipping_context( $order ) {
		$context = array(
			'service_type' => '',
			'max_package_weight_kg' => 0.0,
		);

		foreach ( $order->get_items( 'shipping' ) as $shipping_item ) {
			$service_type = $shipping_item->get_meta( 'lwc_fedex_service' );
			if ( is_string( $service_type ) && '' !== trim( $service_type ) ) {
				$context['service_type'] = strtoupper( trim( $service_type ) );
			}

			$max_weight = $shipping_item->get_meta( 'lwc_fedex_max_weight' );
			if ( is_numeric( $max_weight ) && (float) $max_weight > 0 ) {
				$context['max_package_weight_kg'] = (float) $max_weight;
			}

			if ( '' !== $context['service_type'] && $context['max_package_weight_kg'] > 0 ) {
				break;
			}
		}

		return $context;
	}

	/**
	 * Get the store base country code.
	 *
	 * @return string
	 */
	private function get_base_country() {
		if ( class_exists( 'WC_Countries' ) && WC()->countries ) {
			return (string) WC()->countries->get_base_country();
		}

		$base_country = get_option( 'woocommerce_default_country', '' );
		$base_country = is_string( $base_country ) ? substr( $base_country, 0, 2 ) : '';

		return $base_country;
	}

	/**
	 * Determine a FedEx service type.
	 *
	 * @param string $country Country code.
	 * @return string
	 */
	private function get_service_type( $country ) {
		if ( 'US' === strtoupper( $country ) ) {
			return 'FEDEX_GROUND';
		}

		return 'INTERNATIONAL_ECONOMY';
	}

	/**
	 * Catalog of FedEx services offered at checkout.
	 *
	 * @return array service_type => label
	 */
	public static function get_available_services() {
		return apply_filters(
			'lwc_fedex_available_services',
			array(
				'FEDEX_GROUND' => __( 'FedEx Ground', 'lovecatz-wc' ),
				'FEDEX_GROUND_ECONOMY' => __( 'FedEx Ground Economy', 'lovecatz-wc' ),
				'FEDEX_HOME_DELIVERY' => __( 'FedEx Home Delivery', 'lovecatz-wc' ),
				'FEDEX_EXPRESS_SAVER' => __( 'FedEx Express Saver', 'lovecatz-wc' ),
				'FEDEX_2_DAY' => __( 'FedEx 2Day', 'lovecatz-wc' ),
				'FEDEX_2_DAY_AM' => __( 'FedEx 2Day A.M.', 'lovecatz-wc' ),
				'STANDARD_OVERNIGHT' => __( 'FedEx Standard Overnight', 'lovecatz-wc' ),
				'PRIORITY_OVERNIGHT' => __( 'FedEx Priority Overnight', 'lovecatz-wc' ),
				'FIRST_OVERNIGHT' => __( 'FedEx First Overnight', 'lovecatz-wc' ),
				'INTERNATIONAL_ECONOMY' => __( 'FedEx International Economy', 'lovecatz-wc' ),
				'FEDEX_INTERNATIONAL_PRIORITY' => __( 'FedEx International Priority', 'lovecatz-wc' ),
				'FEDEX_INTERNATIONAL_PRIORITY_EXPRESS' => __( 'FedEx International Priority Express', 'lovecatz-wc' ),
				'INTERNATIONAL_FIRST' => __( 'FedEx International First', 'lovecatz-wc' ),
				'FEDEX_INTERNATIONAL_CONNECT_PLUS' => __( 'FedEx International Connect Plus', 'lovecatz-wc' ),
			)
		);
	}

	/**
	 * Resolve a human-readable label for a FedEx service type.
	 *
	 * @param string $service_type FedEx service type code.
	 * @return string
	 */
	public function get_service_label( $service_type ) {
		$services = self::get_available_services();
		$service_type = strtoupper( (string) $service_type );

		if ( isset( $services[ $service_type ] ) ) {
			return $services[ $service_type ];
		}

		return ucwords( strtolower( str_replace( '_', ' ', $service_type ) ) );
	}

	/**
	 * Extract one quote per returned service from the API response.
	 *
	 * @param array $body Response body.
	 * @return array
	 */
	private function extract_quotes_from_body( $body ) {
		$details = array();

		if ( isset( $body['rateReply']['rateReplyDetails'] ) && is_array( $body['rateReply']['rateReplyDetails'] ) ) {
			$details = $body['rateReply']['rateReplyDetails'];
		} elseif ( isset( $body['output']['rateReplyDetails'] ) && is_array( $body['output']['rateReplyDetails'] ) ) {
			// Current FedEx REST Rates API response shape.
			$details = $body['output']['rateReplyDetails'];
		} elseif ( isset( $body['output']['rateReply']['rateReplyDetails'] ) && is_array( $body['output']['rateReply']['rateReplyDetails'] ) ) {
			// Older/alternate wrapper shape.
			$details = $body['output']['rateReply']['rateReplyDetails'];
		}

		if ( empty( $details ) ) {
			return array();
		}

		$quotes = array();
		foreach ( $details as $detail ) {
			if ( ! is_array( $detail ) || empty( $detail['serviceType'] ) ) {
				continue;
			}

			$rated_detail = $this->select_preferred_rated_detail(
				isset( $detail['ratedShipmentDetails'] ) ? $detail['ratedShipmentDetails'] : array()
			);
			$amount = null;

			if ( isset( $rated_detail['totalNetCharge'] ) ) {
				$amount = $this->extract_money_amount( $rated_detail['totalNetCharge'] );
			} elseif ( isset( $rated_detail['totalNetFedExCharge'] ) ) {
				$amount = $this->extract_money_amount( $rated_detail['totalNetFedExCharge'] );
			} elseif ( isset( $rated_detail['shipmentRateDetail']['totalNetCharge'] ) ) {
				$amount = $this->extract_money_amount( $rated_detail['shipmentRateDetail']['totalNetCharge'] );
			}

			if ( null === $amount ) {
				continue;
			}

			$service_type = strtoupper( (string) $detail['serviceType'] );
			$amount = (float) $amount;
			if ( $amount <= 0 ) {
				continue;
			}

			$label = '';
			if ( ! empty( $detail['serviceName'] ) && is_string( $detail['serviceName'] ) ) {
				$label = trim( $detail['serviceName'] );
			}
			if ( '' === $label ) {
				$label = $this->get_service_label( $service_type );
			}

			// Keep the cheapest quote when a service appears more than once.
			if ( isset( $quotes[ $service_type ] ) && $quotes[ $service_type ]['rate'] <= $amount ) {
				continue;
			}

			$quotes[ $service_type ] = array(
				'service_type' => $service_type,
				'label' => $label,
				'rate' => $amount,
				'currency' => isset( $rated_detail['currency'] ) ? strtoupper( (string) $rated_detail['currency'] ) : '',
			);
		}

		return array_values( $quotes );
	}

	/**
	 * Read a FedEx money value returned as a scalar or an amount object.
	 *
	 * @param mixed $value FedEx money field.
	 * @return float|null
	 */
	private function extract_money_amount( $value ) {
		if ( is_numeric( $value ) ) {
			return (float) $value;
		}

		if ( is_array( $value ) && isset( $value['amount'] ) && is_numeric( $value['amount'] ) ) {
			return (float) $value['amount'];
		}

		return null;
	}

	/**
	 * Prefer the rate expressed in the WooCommerce store currency.
	 *
	 * @param mixed $details FedEx ratedShipmentDetails list.
	 * @return array
	 */
	private function select_preferred_rated_detail( $details ) {
		if ( ! is_array( $details ) || empty( $details ) ) {
			return array();
		}

		$preferred_currency = strtoupper( (string) get_option( 'woocommerce_currency', 'IDR' ) );
		foreach ( $details as $detail ) {
			if ( is_array( $detail ) && isset( $detail['currency'] ) && $preferred_currency === strtoupper( (string) $detail['currency'] ) ) {
				return $detail;
			}
		}

		foreach ( $details as $detail ) {
			if ( is_array( $detail ) && isset( $detail['rateType'] ) && false !== strpos( strtoupper( (string) $detail['rateType'] ), 'PREFERRED' ) ) {
				return $detail;
			}
		}

		return is_array( reset( $details ) ) ? reset( $details ) : array();
	}

	/**
	 * Build a credential-free structural summary when no quote can be parsed.
	 *
	 * @param array $body FedEx response body.
	 * @return array
	 */
	private function summarize_rate_response( $body ) {
		$summary = array(
			'message'        => 'FedEx returned no usable service price.',
			'top_level_keys' => implode( ', ', array_keys( (array) $body ) ),
			'output_keys'    => isset( $body['output'] ) && is_array( $body['output'] ) ? implode( ', ', array_keys( $body['output'] ) ) : '',
			'details_path'   => 'not_found',
			'detail_count'   => 0,
		);
		$details = array();

		if ( isset( $body['rateReply']['rateReplyDetails'] ) && is_array( $body['rateReply']['rateReplyDetails'] ) ) {
			$summary['details_path'] = 'rateReply.rateReplyDetails';
			$details = $body['rateReply']['rateReplyDetails'];
		} elseif ( isset( $body['output']['rateReplyDetails'] ) && is_array( $body['output']['rateReplyDetails'] ) ) {
			$summary['details_path'] = 'output.rateReplyDetails';
			$details = $body['output']['rateReplyDetails'];
		} elseif ( isset( $body['output']['rateReply']['rateReplyDetails'] ) && is_array( $body['output']['rateReply']['rateReplyDetails'] ) ) {
			$summary['details_path'] = 'output.rateReply.rateReplyDetails';
			$details = $body['output']['rateReply']['rateReplyDetails'];
		}

		$summary['detail_count'] = count( $details );
		if ( ! empty( $details[0] ) && is_array( $details[0] ) ) {
			$first = $details[0];
			$summary['first_service_type'] = isset( $first['serviceType'] ) ? $first['serviceType'] : '';
			$summary['first_detail_keys'] = implode( ', ', array_keys( $first ) );
			$rated = isset( $first['ratedShipmentDetails'] ) && is_array( $first['ratedShipmentDetails'] ) ? $first['ratedShipmentDetails'] : array();
			$summary['rated_detail_count'] = count( $rated );
			$summary['first_rated_detail_keys'] = ! empty( $rated[0] ) && is_array( $rated[0] ) ? implode( ', ', array_keys( $rated[0] ) ) : '';
		}

		$alerts = array();
		if ( isset( $body['output']['alerts'] ) && is_array( $body['output']['alerts'] ) ) {
			foreach ( array_slice( $body['output']['alerts'], 0, 5 ) as $alert ) {
				if ( is_array( $alert ) ) {
					$alerts[] = trim( ( isset( $alert['code'] ) ? $alert['code'] . ': ' : '' ) . ( isset( $alert['message'] ) ? $alert['message'] : '' ) );
				}
			}
		}
		$summary['alerts'] = implode( ' | ', array_filter( $alerts ) );

		return $summary;
	}

	/**
	 * Save a label file from the FedEx response.
	 *
	 * Every shipment (including manual partial ones) is appended to the
	 * order's `_lwc_fedex_shipments` array; the latest tracking number and
	 * label also update the legacy single-shipment meta for compatibility.
	 *
	 * @param WC_Order $order Order object.
	 * @param array $body Response body.
	 * @param int[] $item_ids Order item IDs included in this shipment.
	 * @return string|false
	 */
	private function save_label_from_response( $order, $body, $item_ids = array() ) {
		$label_data = '';
		$tracking_number = '';

		// Current REST ship API response shape.
		if ( isset( $body['output']['transactionShipments'][0] ) && is_array( $body['output']['transactionShipments'][0] ) ) {
			$shipment = $body['output']['transactionShipments'][0];

			if ( isset( $shipment['pieceResponses'][0]['packageDocuments'][0]['content'] ) ) {
				$label_data = (string) $shipment['pieceResponses'][0]['packageDocuments'][0]['content'];
			}

			if ( isset( $shipment['pieceResponses'][0]['trackingNumber'] ) ) {
				$tracking_number = (string) $shipment['pieceResponses'][0]['trackingNumber'];
			} elseif ( isset( $shipment['masterTrackingNumber']['trackingNumber'] ) ) {
				$tracking_number = (string) $shipment['masterTrackingNumber']['trackingNumber'];
			}
		}

		// Legacy/alternate response shapes kept as fallbacks.
		if ( '' === $label_data && isset( $body['output']['transactionShipments'][0]['label']['parts'][0]['image'] ) ) {
			$label_data = (string) $body['output']['transactionShipments'][0]['label']['parts'][0]['image'];
		} elseif ( '' === $label_data && isset( $body['labelResponse']['parts'][0]['image'] ) ) {
			$label_data = (string) $body['labelResponse']['parts'][0]['image'];
		}

		if ( '' === $label_data ) {
			$this->log( 'Shipment created but no label document was found in the response.', 'warning' );
			return false;
		}

		$data = base64_decode( $label_data, true );
		if ( false === $data ) {
			$data = $label_data;
		}

		$upload_dir = wp_upload_dir();
		$filename = 'fedex-label-' . $order->get_id() . '-' . wp_generate_uuid4() . '.pdf';
		$filepath = $upload_dir['basedir'] . '/' . $filename;

		$file_saved = file_put_contents( $filepath, $data );
		if ( false === $file_saved ) {
			$this->log( 'Unable to write the label file to the uploads directory.', 'error' );
			return false;
		}

		$order->update_meta_data( '_lwc_fedex_label_path', $filename );
		if ( '' !== $tracking_number ) {
			$order->update_meta_data( '_lwc_fedex_tracking_number', $tracking_number );
			$order->add_order_note(
				sprintf(
					/* translators: %s: FedEx tracking number */
					__( 'FedEx shipment created. Tracking number: %s', 'lovecatz-wc' ),
					$tracking_number
				)
			);
		}

		// Track every shipment so partial/manual splits keep a full history.
		$shipments = $order->get_meta( '_lwc_fedex_shipments' );
		$shipments = is_array( $shipments ) ? $shipments : array();
		$shipments[] = array(
			'tracking_number' => $tracking_number,
			'label_file' => $filename,
			'item_ids' => array_values( array_map( 'intval', (array) $item_ids ) ),
			'created_at' => current_time( 'mysql' ),
		);
		$order->update_meta_data( '_lwc_fedex_shipments', $shipments );

		$order->save();

		return $filename;
	}

	/**
	 * Log a message using the shared LoveCatz logger.
	 *
	 * @param string $message Message (never includes credentials).
	 * @param string $level   Log level.
	 */
	private function log( $message, $level = 'info' ) {
		if ( class_exists( 'LWC_Logger' ) ) {
			LWC_Logger::log( 'FedEx API: ' . $message, $level, 'lovecatz-wc' );
		}
	}

	/**
	 * Write a safe event to the opt-in checkout diagnostics.
	 *
	 * @param string $stage   Diagnostic stage.
	 * @param array  $context Safe diagnostic values.
	 */
	private function debug( $stage, $context = array() ) {
		if ( function_exists( 'lwc_fedex_checkout_debug_log' ) ) {
			lwc_fedex_checkout_debug_log( $stage, $context );
		}
	}
}
