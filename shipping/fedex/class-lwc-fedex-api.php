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
	 * Get shipping rates for every applicable FedEx service.
	 *
	 * @param array $package WooCommerce package data.
	 * @param float $max_package_weight_kg Split packages above this weight (0 disables splitting).
	 * @return array
	 */
	public function get_rate_quotes( $package, $max_package_weight_kg = 0 ) {
		$payload = $this->build_rate_payload( $package, $max_package_weight_kg );

		// Reuse a recent quote for an identical request so checkout/cart
		// recalculations do not hit the FedEx API every time.
		$cache_key = 'lwc_fedex_rates_' . md5( (string) wp_json_encode( $payload ) );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['success'], $cached['quotes'] ) ) {
			return $cached;
		}

		$token = $this->get_access_token();
		if ( '' === $token ) {
			$this->log( 'Rate quote failed: unable to authenticate.', 'error' );
			return array(
				'success' => false,
				'message' => __( 'Unable to authenticate with FedEx.', 'lovecatz-wc' ),
			);
		}

		$response = $this->request( '/rate/v1/rates/quotes', $payload, $token );
		$body = $this->parse_response_body( $response );

		if ( ! $body ) {
			$this->log( 'Rate quote failed: empty or invalid response.', 'error' );
			return array(
				'success' => false,
				'message' => __( 'FedEx rate request returned no response.', 'lovecatz-wc' ),
			);
		}

		if ( isset( $body['errors'] ) ) {
			$message = $this->extract_error_message( $body );
			$this->log( 'Rate quote failed: ' . $message, 'error' );
			return array(
				'success' => false,
				'message' => $message,
				'response' => $body,
			);
		}

		$quotes = $this->extract_quotes_from_body( $body );
		if ( empty( $quotes ) ) {
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
			$this->log( 'Shipment request failed: empty or invalid response.', 'error' );
			return array(
				'success' => false,
				'message' => __( 'FedEx shipment request returned no response.', 'lovecatz-wc' ),
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
			return $cached;
		}

		$url = rtrim( $this->get_api_base_url(), '/' ) . '/oauth/token';
		$headers = array(
			'Authorization' => 'Basic ' . base64_encode( $this->settings['api_key'] . ':' . $this->settings['api_secret'] ),
			'Content-Type'  => 'application/x-www-form-urlencoded',
		);
		$args = array(
			'headers' => $headers,
			'body'    => 'grant_type=client_credentials',
			'timeout' => 20,
		);

		$response = wp_remote_post( $url, $args );
		if ( is_wp_error( $response ) ) {
			return '';
		}

		$body = $this->parse_response_body( $response );
		if ( ! is_array( $body ) || empty( $body['access_token'] ) ) {
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
	private function request( $path, $payload, $token ) {
		$url = rtrim( $this->get_api_base_url(), '/' ) . $path;
		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( $payload ),
			'timeout' => '/rate/' === substr( $path, 0, 6 ) ? 20 : 60,
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
					'labelPrintingOrientation' => 'BOTTOM_EDGE_OF_LABEL',
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
				'FEDEX_STANDARD_OVERNIGHT' => __( 'FedEx Standard Overnight', 'lovecatz-wc' ),
				'FEDEX_PRIORITY_OVERNIGHT' => __( 'FedEx Priority Overnight', 'lovecatz-wc' ),
				'FEDEX_FIRST_OVERNIGHT' => __( 'FedEx First Overnight', 'lovecatz-wc' ),
				'INTERNATIONAL_ECONOMY' => __( 'FedEx International Economy', 'lovecatz-wc' ),
				'INTERNATIONAL_PRIORITY' => __( 'FedEx International Priority', 'lovecatz-wc' ),
				'INTERNATIONAL_PRIORITY_EXPRESS' => __( 'FedEx International Priority Express', 'lovecatz-wc' ),
				'INTERNATIONAL_FIRST' => __( 'FedEx International First', 'lovecatz-wc' ),
				'INTERNATIONAL_CONNECT_PLUS' => __( 'FedEx International Connect Plus', 'lovecatz-wc' ),
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
		} elseif ( isset( $body['output']['rateReply']['rateReplyDetails'] ) && is_array( $body['output']['rateReply']['rateReplyDetails'] ) ) {
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

			if ( ! isset( $detail['ratedShipmentDetails'][0]['totalNetCharge']['amount'] ) ) {
				continue;
			}

			$service_type = strtoupper( (string) $detail['serviceType'] );
			$amount = (float) $detail['ratedShipmentDetails'][0]['totalNetCharge']['amount'];
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
			);
		}

		return array_values( $quotes );
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
}
