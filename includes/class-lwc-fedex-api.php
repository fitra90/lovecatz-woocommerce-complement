<?php
/**
 * FedEx API helper for rates, shipment creation, and label generation.
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
	 * Get shipping rates for a WooCommerce package.
	 *
	 * @param array $package WooCommerce package data.
	 * @return array
	 */
	public function get_rate_quote( $package ) {
		$token = $this->get_access_token();
		if ( '' === $token ) {
			return array(
				'success' => false,
				'message' => __( 'Unable to authenticate with FedEx.', 'lovecatz-wc' ),
			);
		}

		$payload = $this->build_rate_payload( $package );
		$response = $this->request( '/rate/v1/rates/quotes', $payload, $token );
		$body = $this->parse_response_body( $response );

		if ( ! $body ) {
			return array(
				'success' => false,
				'message' => __( 'FedEx rate request returned no response.', 'lovecatz-wc' ),
			);
		}

		if ( isset( $body['errors'] ) ) {
			return array(
				'success' => false,
				'message' => $this->extract_error_message( $body ),
				'response' => $body,
			);
		}

		$rate = $this->extract_rate_from_body( $body );
		if ( false === $rate ) {
			return array(
				'success' => false,
				'message' => __( 'FedEx did not return a valid rate.', 'lovecatz-wc' ),
				'response' => $body,
			);
		}

		return array(
			'success' => true,
			'rate'    => (float) $rate,
			'response' => $body,
		);
	}

	/**
	 * Create a shipment and fetch a label PDF.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @return array
	 */
	public function create_shipment( $order ) {
		$token = $this->get_access_token();
		if ( '' === $token ) {
			return array(
				'success' => false,
				'message' => __( 'Unable to authenticate with FedEx.', 'lovecatz-wc' ),
			);
		}

		$payload = $this->build_shipment_payload( $order );
		$response = $this->request( '/ship/v1/shipments', $payload, $token );
		$body = $this->parse_response_body( $response );

		if ( ! $body ) {
			return array(
				'success' => false,
				'message' => __( 'FedEx shipment request returned no response.', 'lovecatz-wc' ),
			);
		}

		if ( isset( $body['errors'] ) ) {
			return array(
				'success' => false,
				'message' => $this->extract_error_message( $body ),
				'response' => $body,
			);
		}

		$label_path = $this->save_label_from_response( $order, $body );
		return array(
			'success' => true,
			'label_path' => $label_path,
			'response' => $body,
		);
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
		$url = rtrim( $this->get_api_base_url(), '/' ) . '/oauth/token';
		$headers = array(
			'Authorization' => 'Basic ' . base64_encode( $this->settings['api_key'] . ':' . $this->settings['api_secret'] ),
			'Content-Type'  => 'application/x-www-form-urlencoded',
		);
		$args = array(
			'headers' => $headers,
			'body'    => 'grant_type=client_credentials',
			'timeout' => 45,
			'sslverify' => false,
		);

		$response = wp_remote_post( $url, $args );
		if ( is_wp_error( $response ) ) {
			return '';
		}

		$body = $this->parse_response_body( $response );
		if ( ! is_array( $body ) || empty( $body['access_token'] ) ) {
			return '';
		}

		return (string) $body['access_token'];
	}

	/**
	 * Send a request to the FedEx API.
	 *
	 * @param string $path API path.
	 * @param array $payload Request payload.
	 * @param string $token Access token.
	 * @return array|WP_Error
	 */
	private function request( $path, $payload, $token ) {
		$url = rtrim( $this->get_api_base_url(), '/' ) . $path;
		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( $payload ),
			'timeout' => 60,
			'sslverify' => false,
		);

		return wp_remote_post( $url, $args );
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
	 * Build a request payload for rate quotes.
	 *
	 * @param array $package Package data.
	 * @return array
	 */
	private function build_rate_payload( $package ) {
		$weight_kg = $this->get_package_weight_kg( $package );
		$origin = $this->build_origin_address();
		$destination = $this->build_destination_address( $package );
		$service_type = $this->get_service_type( $destination['country'] );

		return array(
			'accountNumber' => array(
				'value' => $this->settings['account_number'],
			),
			'requestedShipment' => array(
				'shipper' => array(
					'address' => $origin,
				),
				'recipient' => array(
					'address' => $destination,
				),
				'pickupType' => 'USE_SCHEDULED_PICKUP',
				'serviceType' => $service_type,
				'packagingType' => 'YOUR_PACKAGING',
				'requestedPackageLineItems' => array(
					array(
						'weight' => array(
							'units' => 'KG',
							'value' => round( $weight_kg, 2 ),
						),
					),
				),
			),
		);
	}

	/**
	 * Build a request payload for shipment creation.
	 *
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	private function build_shipment_payload( $order ) {
		$origin = $this->build_origin_address();
		$destination = $this->build_destination_address_from_order( $order );
		$service_type = $this->get_service_type( $destination['country'] );
		$weight_kg = $this->get_order_weight_kg( $order );

		return array(
			'accountNumber' => array(
				'value' => $this->settings['account_number'],
			),
			'requestedShipment' => array(
				'shipper' => array(
					'address' => $origin,
				),
				'recipient' => array(
					'address' => $destination,
				),
				'pickupType' => 'USE_SCHEDULED_PICKUP',
				'serviceType' => $service_type,
				'packagingType' => 'YOUR_PACKAGING',
				'labelSpecification' => array(
					'labelFormatType' => 'COMMON2D',
					'labelStockType' => 'PAPER_4X6',
					'labelPrintingOrientation' => 'BOTTOM_EDGE_OF_LABEL',
				),
				'requestedPackageLineItems' => array(
					array(
						'weight' => array(
							'units' => 'KG',
							'value' => round( $weight_kg, 2 ),
						),
					),
				),
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

		return array(
			'streetLines' => array(),
			'city' => $city,
			'stateOrProvinceCode' => $state,
			'postalCode' => $postcode,
			'countryCode' => $country,
		);
	}

	/**
	 * Get the total package weight in kilograms.
	 *
	 * @param array $package Package data.
	 * @return float
	 */
	private function get_package_weight_kg( $package ) {
		$weight = 0;
		if ( ! empty( $package['contents'] ) ) {
			foreach ( $package['contents'] as $item ) {
				if ( ! empty( $item['data'] ) ) {
					$weight += floatval( wc_get_weight( $item['data']->get_weight(), 'kg' ) );
				}
			}
		}

		return max( $weight, 0.5 );
	}

	/**
	 * Get the total order weight in kilograms.
	 *
	 * @param WC_Order $order Order object.
	 * @return float
	 */
	private function get_order_weight_kg( $order ) {
		$weight = 0;
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product ) {
				$weight += floatval( wc_get_weight( $product->get_weight(), 'kg' ) ) * floatval( $item->get_quantity() );
			}
		}

		return max( $weight, 0.5 );
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
	 * Extract the rate from the API response.
	 *
	 * @param array $body Response body.
	 * @return bool|float
	 */
	private function extract_rate_from_body( $body ) {
		if ( isset( $body['rateReply']['rateReplyDetails'] ) ) {
			$details = $body['rateReply']['rateReplyDetails'];
			if ( ! empty( $details ) ) {
				foreach ( $details as $detail ) {
					if ( isset( $detail['ratedShipmentDetails'][0]['totalNetCharge']['amount'] ) ) {
						return (float) $detail['ratedShipmentDetails'][0]['totalNetCharge']['amount'];
					}
				}
			}
		}

		if ( isset( $body['output']['rateReply']['rateReplyDetails'] ) ) {
			$details = $body['output']['rateReply']['rateReplyDetails'];
			if ( ! empty( $details ) ) {
				foreach ( $details as $detail ) {
					if ( isset( $detail['ratedShipmentDetails'][0]['totalNetCharge']['amount'] ) ) {
						return (float) $detail['ratedShipmentDetails'][0]['totalNetCharge']['amount'];
					}
				}
			}
		}

		return false;
	}

	/**
	 * Save a label file from the FedEx response.
	 *
	 * @param WC_Order $order Order object.
	 * @param array $body Response body.
	 * @return string|false
	 */
	private function save_label_from_response( $order, $body ) {
		$label_data = '';
		if ( isset( $body['output']['transactionShipments'][0]['label']['parts'][0]['image'] ) ) {
			$label_data = (string) $body['output']['transactionShipments'][0]['label']['parts'][0]['image'];
		} elseif ( isset( $body['output']['transactionShipments'][0]['label']['parts'][0]['content'] ) ) {
			$label_data = (string) $body['output']['transactionShipments'][0]['label']['parts'][0]['content'];
		} elseif ( isset( $body['labelResponse']['parts'][0]['image'] ) ) {
			$label_data = (string) $body['labelResponse']['parts'][0]['image'];
		}

		if ( '' === $label_data ) {
			return false;
		}

		$upload_dir = wp_upload_dir();
		$filename = 'fedex-label-' . $order->get_id() . '-' . wp_generate_uuid4() . '.pdf';
		$filepath = $upload_dir['basedir'] . '/' . $filename;

		$data = base64_decode( $label_data, true );
		if ( false === $data ) {
			$data = $label_data;
		}

		$file_saved = file_put_contents( $filepath, $data );
		if ( false === $file_saved ) {
			return false;
		}

		$order->update_meta_data( '_lwc_fedex_label_path', $filename );
		$order->save();

		return $filename;
	}
}
