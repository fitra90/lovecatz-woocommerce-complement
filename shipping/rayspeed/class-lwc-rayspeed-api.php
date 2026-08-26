<?php
/**
 * RaySpeed Retail Sandbox API client.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_RaySpeed_API {
	const SANDBOX_BASE = 'https://rayspeed.com/speedship/sandbox/';

	/** @var string */
	private $api_key;

	public function __construct( $api_key = '' ) {
		$this->api_key = '' !== $api_key ? (string) $api_key : (string) get_option( 'lwc_rayspeed_api_key', '' );
	}

	/** Verify the key against the documented destination endpoint. */
	public function test_connection() {
		$result = $this->post( 'destination.php', array() );
		if ( empty( $result['success'] ) ) {
			return $result;
		}
		return array( 'success' => true, 'message' => __( 'Connected to RaySpeed Sandbox.', 'lovecatz-wc' ) );
	}

	/** Request a RETAIL international quote. */
	public function get_quote( $package ) {
		$destination = isset( $package['destination'] ) && is_array( $package['destination'] ) ? $package['destination'] : array();
		$country_code = isset( $destination['country'] ) ? strtoupper( (string) $destination['country'] ) : '';
		$country = $this->country_name( $country_code );
		if ( '' === $country || 'ID' === $country_code ) {
			return array( 'success' => false, 'message' => __( 'RaySpeed is available only for destinations outside Indonesia.', 'lovecatz-wc' ) );
		}

		$metrics = $this->package_metrics( isset( $package['contents'] ) ? $package['contents'] : array() );
		$payload = array(
			'originPrice'  => get_option( 'lwc_rayspeed_origin', 'JKT' ),
			'service'      => 'RETAIL',
			'country'      => $country,
			'postcode'     => isset( $destination['postcode'] ) ? (string) $destination['postcode'] : '',
			'city'         => isset( $destination['city'] ) ? (string) $destination['city'] : '',
			'weight'       => $metrics['weight'],
			'size'         => $metrics['size'],
			'category'     => get_option( 'lwc_rayspeed_category', 'General Package' ),
			'type'         => get_option( 'lwc_rayspeed_type', 'NOC' ),
			'shipmentType' => get_option( 'lwc_rayspeed_shipment_type', 'Regular' ),
		);
		$result = $this->post( 'pricing.php', $payload );
		if ( empty( $result['success'] ) || ! isset( $result['data']['price'] ) || ! is_numeric( $result['data']['price'] ) ) {
			return $result;
		}

		return array(
			'success'      => true,
			'price'        => (float) $result['data']['price'],
			'service'      => isset( $result['data']['service'] ) ? (string) $result['data']['service'] : 'RETAIL',
			'min_lead_time'=> isset( $result['data']['minLeadTime'] ) ? (string) $result['data']['minLeadTime'] : '',
			'max_lead_time'=> isset( $result['data']['maxLeadTime'] ) ? (string) $result['data']['maxLeadTime'] : '',
			'response'     => $result['data'],
		);
	}

	/** Create a RaySpeed airwaybill for an international WooCommerce order. */
	public function create_awb( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return array( 'success' => false, 'message' => __( 'Invalid WooCommerce order.', 'lovecatz-wc' ) );
		}

		$country_code = strtoupper( (string) $order->get_shipping_country() );
		if ( '' === $country_code ) {
			$country_code = strtoupper( (string) $order->get_billing_country() );
		}
		if ( '' === $country_code || 'ID' === $country_code ) {
			return array( 'success' => false, 'message' => __( 'RaySpeed AWB is only for destinations outside Indonesia.', 'lovecatz-wc' ) );
		}

		$contents = array();
		$quantity = 0;
		$description = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$qty = max( 1, (int) $item->get_quantity() );
			$quantity += $qty;
			$description[] = $item->get_name();
			$contents[] = array( 'data' => $product, 'quantity' => $qty );
		}
		$metrics = $this->package_metrics( $contents );
		$currency = strtoupper( (string) $order->get_currency() );
		if ( ! in_array( $currency, array( 'USD', 'SGD' ), true ) ) {
			$currency = 'USD';
		}
		$value = (float) apply_filters( 'lwc_rayspeed_invoice_value', $order->get_total(), $currency, $order );
		$name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		$company = $order->get_shipping_company() ? $order->get_shipping_company() : $name;
		$address = array_filter( array( $order->get_shipping_address_1(), $order->get_shipping_address_2(), $order->get_shipping_city(), $order->get_shipping_state() ) );
		$phone = $order->get_billing_phone();
		if ( method_exists( $order, 'get_shipping_phone' ) && $order->get_shipping_phone() ) {
			$phone = $order->get_shipping_phone();
		}

		$payload = array(
			'shipperReference'     => (string) $order->get_order_number(),
			'companyName'          => $company,
			'deliveryAddress'      => implode( ', ', $address ),
			'postcode'             => $order->get_shipping_postcode(),
			'country'              => $this->country_name( $country_code ),
			'contactPerson'        => $name,
			'telephone'            => $phone,
			'commodityName'        => implode( ', ', array_slice( $description, 0, 5 ) ),
			'quantity'             => max( 1, $quantity ),
			'totalNettWeight'      => $metrics['weight'],
			'totalGrossWeight'     => $metrics['weight'],
			'dimension'            => $metrics['dimension'],
			'invoiceValue'         => round( max( 0.01, $value ), 2 ),
			'coverByInsurance'     => get_option( 'lwc_rayspeed_insurance', 'No' ),
			'taxDutyAtDestination' => get_option( 'lwc_rayspeed_tax_duty', 'Receiver' ),
			'specialInstruction'   => $order->get_customer_note(),
			'service'              => 'RETAIL',
			'type'                 => get_option( 'lwc_rayspeed_type', 'NOC' ),
			'category'             => get_option( 'lwc_rayspeed_category', 'General Package' ),
			'transportCharges'     => 'Prepaid',
			'typeOfExport'         => 'Permanent Export',
			'shipmentType'         => get_option( 'lwc_rayspeed_shipment_type', 'Regular' ),
			'description'          => substr( implode( ', ', $description ), 0, 100 ),
			'qty'                  => max( 1, $quantity ),
			'unit'                 => 'Pcs',
			'currency'             => $currency,
			'value'                => round( max( 0.01, $value ), 2 ),
		);
		$result = $this->post( 'awb_post.php', $payload );
		if ( empty( $result['success'] ) || empty( $result['data']['airwaybill'] ) ) {
			return $result;
		}
		return array( 'success' => true, 'airwaybill' => sanitize_text_field( $result['data']['airwaybill'] ), 'response' => $result['data'] );
	}

	/** Retrieve tracking history. */
	public function track( $awb ) {
		$awb = preg_replace( '/[^A-Za-z0-9]/', '', (string) $awb );
		if ( '' === $this->api_key || '' === $awb ) {
			return array( 'success' => false, 'message' => __( 'RaySpeed API key or AWB is missing.', 'lovecatz-wc' ) );
		}
		$url = self::SANDBOX_BASE . 'current.php?' . http_build_query( array( 'key' => $this->api_key, 'awb' => $awb ), '', '&' );
		$response = wp_remote_get( $url, array( 'timeout' => 30 ) );
		return $this->parse( $response, 'sukses' );
	}

	private function post( $endpoint, $payload ) {
		if ( '' === $this->api_key ) {
			return array( 'success' => false, 'message' => __( 'RaySpeed development API key is missing.', 'lovecatz-wc' ) );
		}
		$payload['key'] = $this->api_key;
		$response = wp_remote_post( self::SANDBOX_BASE . $endpoint, array( 'timeout' => 45, 'body' => $payload ) );
		return $this->parse( $response, 'success' );
	}

	private function parse( $response, $success_key ) {
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return array( 'success' => false, 'message' => sprintf( __( 'RaySpeed returned invalid JSON (HTTP %d).', 'lovecatz-wc' ), $status ) );
		}
		$ok = ! empty( $body[ $success_key ] );
		if ( ! $ok ) {
			$message = isset( $body['reason'] ) ? $body['reason'] : ( isset( $body['message'] ) ? $body['message'] : __( 'RaySpeed rejected the request.', 'lovecatz-wc' ) );
			return array( 'success' => false, 'message' => sanitize_text_field( $message ), 'response' => $body );
		}
		return array( 'success' => true, 'data' => $body );
	}

	private function country_name( $code ) {
		$countries = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : array();
		return isset( $countries[ $code ] ) ? (string) $countries[ $code ] : (string) $code;
	}

	private function package_metrics( $contents ) {
		$weight = 0.0;
		$longest = 0.0;
		$dimensions = array();
		foreach ( (array) $contents as $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			$qty = isset( $item['quantity'] ) ? max( 1, (int) $item['quantity'] ) : 1;
			if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
				continue;
			}
			$item_weight = $product->get_weight() ? (float) wc_get_weight( $product->get_weight(), 'kg' ) : 0.5;
			$weight += $item_weight * $qty;
			$l = $product->get_length() ? (float) wc_get_dimension( $product->get_length(), 'cm' ) : 1;
			$w = $product->get_width() ? (float) wc_get_dimension( $product->get_width(), 'cm' ) : 1;
			$h = $product->get_height() ? (float) wc_get_dimension( $product->get_height(), 'cm' ) : 1;
			$longest = max( $longest, $l, $w, $h );
			$dimensions[] = round( $l, 2 ) . '*' . round( $w, 2 ) . '*' . round( $h, 2 );
		}
		return array(
			'weight'    => round( max( 0.1, $weight ), 2 ),
			'size'      => round( max( 1, $longest ), 2 ),
			'dimension' => substr( implode( '/', $dimensions ? $dimensions : array( '1*1*1' ) ), 0, 100 ),
		);
	}
}
