<?php
/**
 * WooCommerce order -> J&T Cargo "Add New Order" (order/addOrder) mapping.
 *
 * The field names and the maximum lengths in this file come from the official
 * J&T Cargo OpenAPI manual, section 3.4.1 "Create Order". They are NOT guessed:
 *
 *   txlogisticId  String(50)  Y  "Nomor order pelanggan"  <- customer order number
 *   customerCode  String(30)  Y  injected by LWC_JTC_API
 *   digest        String(30)  Y  injected by LWC_JTC_API (body signature, 3.3.2)
 *   expressType   String(30)  Y  FT : FastTrack
 *   orderType     String(30)  Y  1 : Non-VIP / 2 : VIP
 *   serviceType   String(30)  Y  01 : Pick-Up / 02 : Drop Off
 *   deliveryType  String(30)  Y  101 : Pengiriman sampai tujuan
 *   payType       String(30)  Y  PP_PM / CC_CASH / PP_CASH
 *   sender        Object      Y  see manual [7]
 *   receiver      Object      Y  see manual [8]
 *   goodsType     String(30)  Y  bm000001 .. bm000011
 *   length        Int(6)      N  cm
 *   width         Int(6)      N  cm
 *   height        Int(6)      N  cm
 *   weight        String(12)  Y  KG, min 1 / max 9999.99
 *   totalQuantity Int(4)      N  jumlah KOLI (bukan jumlah barang)
 *   offerFee      String(12)  N  nilai barang (IDR)
 *   remark        String(200) N
 *   items         Object[]    N  see manual [5]
 *   signReceipt   Int(4)      N  0 : Tidak / 1 : Ya
 *   waybillNo     String(50)  N  pre-booked AWB
 *
 * Flow: build -> validate -> one canonical payload -> JSON -> digest -> POST.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Maps a WooCommerce order onto the J&T Cargo addOrder contract. */
class LWC_JTC_Order {

	/** Manual 3.4.1: txlogisticId is String(50) and required. */
	const TXLOGISTIC_ID_MAX = 50;

	/** Manual 3.4.1: weight is String(12), min 1 KG, max 9999.99 KG. */
	const WEIGHT_MIN_KG = 1.0;
	const WEIGHT_MAX_KG = 9999.99;

	/** Field limits declared by the manual. Values longer than this are rejected. */
	const LIMITS = array(
		'txlogisticId' => 50,
		'expressType'  => 30,
		'orderType'    => 30,
		'serviceType'  => 30,
		'deliveryType' => 30,
		'payType'      => 30,
		'goodsType'    => 30,
		'weight'       => 12,
		'offerFee'     => 12,
		'remark'       => 200,
		'waybillNo'    => 50,
	);

	/** Sender / receiver attribute limits from manual [7] and [8]. */
	const PARTY_LIMITS = array(
		'name'        => 32,
		'company'     => 100,
		'postCode'    => 32,
		'mobile'      => 30,
		'phone'       => 30,
		'countryCode' => 20,
		'prov'        => 32,
		'city'        => 32,
		'area'        => 32,
		'town'        => 32,
		'address'     => 150,
	);

	/** Item attribute limits from manual [5]. */
	const ITEM_LIMITS = array(
		'itemType'      => 30,
		'itemName'      => 30,
		'itemValue'     => 20,
		'priceCurrency' => 20,
		'desc'          => 100,
		'itemUrl'       => 100,
	);

	/**
	 * Build the J&T Cargo customer order number (txlogisticId) for an order.
	 *
	 * Rules, in order:
	 *  1. A number already sent to J&T for this order is reused, so a retry or
	 *     a re-shipment never silently renumber an existing shipment.
	 *  2. Otherwise the store's own order number is used - the plugin custom
	 *     order ID when the custom-ID feature assigned one, else the plain
	 *     WooCommerce order number.
	 *  3. Characters J&T does not accept are removed; the result is never empty.
	 *  4. The result never exceeds 50 characters. Shortening keeps uniqueness:
	 *     instead of a blind truncation, a short hash of the full value is
	 *     appended, so two long order numbers cannot collapse into one.
	 *
	 * @param WC_Order $order Order.
	 * @return string Never empty, at most 50 characters.
	 */
	public static function build_customer_order_number( WC_Order $order ) {
		$stored = trim( (string) $order->get_meta( '_lwc_jtc_txlogistic_id', true ) );
		if ( '' !== $stored ) {
			return self::enforce_txlogistic_id_length( $stored );
		}

		$base = self::order_number_source( $order );
		$base = self::sanitize_order_number( $base );

		if ( '' === $base ) {
			// Last resort: the internal ID is always present and always unique.
			$base = 'LWC' . (string) $order->get_id();
		}

		// Re-shipments (courier switched away and back) need a distinct number.
		$generation = absint( $order->get_meta( '_lwc_shipping_change_count', true ) );
		if ( $generation > 0 ) {
			$base .= '-R' . $generation;
		}

		$number = self::enforce_txlogistic_id_length( $base );

		/**
		 * Filter the customer order number sent as J&T Cargo txlogisticId.
		 *
		 * The returned value is still length-checked: a value longer than 50
		 * characters is rejected by validate_payload() before any HTTP call.
		 *
		 * @param string   $number Customer order number.
		 * @param WC_Order $order  Order.
		 */
		return (string) apply_filters( 'lwc_jtc_customer_order_number', $number, $order );
	}

	/**
	 * Build the canonical bizContent for order/addOrder.
	 *
	 * customerCode and digest are deliberately absent: LWC_JTC_API owns every
	 * credential and injects them into the exact JSON string that is signed and
	 * sent, so no credential can leak into this mapping layer.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function build_payload( WC_Order $order ) {
		$sender   = self::sender_block( $order );
		$receiver = self::receiver_block( $order );
		$weights  = self::goods_weight_and_size( $order );
		$items    = self::items_block( $order );
		$quantity = self::package_count( $order );
		$value    = self::goods_value( $order );

		$payload = array(
			'txlogisticId'  => self::build_customer_order_number( $order ),
			'expressType'   => self::express_type( $order ),
			'orderType'     => self::order_type( $order ),
			'serviceType'   => self::service_type( $order ),
			'deliveryType'  => self::delivery_type( $order ),
			'payType'       => self::pay_type( $order ),
			'sender'        => $sender,
			'receiver'      => $receiver,
			'goodsType'     => self::goods_type( $order ),
			'weight'        => $weights['weight'],
			'totalQuantity' => $quantity,
			'offerFee'      => $value,
			'signReceipt'   => 0,
		);

		foreach ( array( 'length', 'width', 'height' ) as $dimension ) {
			if ( isset( $weights[ $dimension ] ) && (int) $weights[ $dimension ] > 0 ) {
				$payload[ $dimension ] = (int) $weights[ $dimension ];
			}
		}

		$remark = trim( (string) $order->get_customer_note() );
		if ( '' !== $remark ) {
			$payload['remark'] = self::clip( $remark, self::LIMITS['remark'] );
		}

		if ( ! empty( $items ) ) {
			$payload['items'] = $items;
		}

		$waybill = trim( (string) $order->get_meta( '_lwc_jtc_waybill_no', true ) );
		if ( '' !== $waybill ) {
			$payload['waybillNo'] = self::clip( $waybill, self::LIMITS['waybillNo'] );
		}

		/**
		 * Filter the complete J&T Cargo addOrder business payload.
		 *
		 * @param array    $payload Business parameters (no credentials).
		 * @param WC_Order $order   Order.
		 */
		return (array) apply_filters( 'lwc_jtc_add_order_payload', $payload, $order );
	}

	/**
	 * Dummy add_order payload for the Sandbox Test console.
	 *
	 * Only interfaces the manual documents in full get a sample: inventing
	 * fields for the others would send J&T a guessed schema. Values are
	 * obviously fictional and carry no credential - customerCode and digest are
	 * added by LWC_JTC_API at send time.
	 *
	 * @return array
	 */
	public static function sample_payload() {
		$sender = array(
			'name'        => 'Kucing Oren',
			'company'     => 'PT Kucing Sejahtera',
			'postCode'    => '14450',
			'mobile'      => '0821987654321',
			'countryCode' => 'IDN',
			'prov'        => 'DKI Jakarta',
			'city'        => 'Jakarta Utara',
			'area'        => 'Penjaringan',
			'address'     => 'Jl. ABC No. 123',
		);
		$receiver             = $sender;
		$receiver['name']     = 'Kucing Hitam';
		$receiver['company']  = 'PT Kucing Bahagia';
		$receiver['address']  = 'Jl. DEF No. 45';

		return array(
			'txlogisticId'  => 'ID12345678',
			'expressType'   => 'FT',
			'orderType'     => '1',
			'serviceType'   => '01',
			'deliveryType'  => '101',
			'payType'       => 'PP_PM',
			'sender'        => $sender,
			'receiver'      => $receiver,
			'goodsType'     => 'bm000007',
			'length'        => 10,
			'width'         => 10,
			'height'        => 10,
			'weight'        => '11',
			'totalQuantity' => 1,
			'offerFee'      => '2500000',
			'remark'        => 'Sandbox joint debugging test',
			'items'         => array(
				array(
					'itemType'      => 'bm000007',
					'itemName'      => 'Whiskas',
					'number'        => 1,
					'itemValue'     => '100000',
					'priceCurrency' => 'IDR',
					'desc'          => 'Makanan kucing',
				),
			),
		);
	}

	/**
	 * Validate a payload before it leaves WordPress.
	 *
	 * Returns WP_Error with a specific, actionable message so the admin never
	 * sees a bare "API Error". Nothing is sent to J&T when this fails.
	 *
	 * @param array $payload Payload from build_payload().
	 * @return true|WP_Error
	 */
	public static function validate_payload( $payload ) {
		if ( ! is_array( $payload ) || empty( $payload ) ) {
			return new WP_Error( 'lwc_jtc_empty_payload', __( 'J&T Cargo: payload order kosong.', 'lovecatz-wc' ) );
		}

		// 1. Customer order number - the field behind error 145003082.
		$txlogistic_id = isset( $payload['txlogisticId'] ) ? trim( (string) $payload['txlogisticId'] ) : '';
		if ( '' === $txlogistic_id ) {
			return new WP_Error( 'lwc_jtc_empty_txlogistic_id', __( 'J&T Cargo: Customer order number kosong.', 'lovecatz-wc' ) );
		}
		if ( strlen( $txlogistic_id ) > self::TXLOGISTIC_ID_MAX ) {
			return new WP_Error(
				'lwc_jtc_long_txlogistic_id',
				sprintf(
					/* translators: %d: maximum number of characters allowed by J&T. */
					__( 'J&T Cargo: Customer order number melebihi batas maksimum %d karakter.', 'lovecatz-wc' ),
					self::TXLOGISTIC_ID_MAX
				)
			);
		}

		// 2. Required scalar business parameters.
		$required = array(
			'expressType'  => __( 'J&T Cargo: Jenis layanan (expressType) wajib diisi.', 'lovecatz-wc' ),
			'orderType'    => __( 'J&T Cargo: Jenis pelanggan (orderType) wajib diisi.', 'lovecatz-wc' ),
			'serviceType'  => __( 'J&T Cargo: Metode pickup (serviceType) wajib diisi.', 'lovecatz-wc' ),
			'deliveryType' => __( 'J&T Cargo: Metode penerimaan (deliveryType) wajib diisi.', 'lovecatz-wc' ),
			'payType'      => __( 'J&T Cargo: Metode penyelesaian (payType) wajib diisi.', 'lovecatz-wc' ),
			'goodsType'    => __( 'J&T Cargo: Jenis barang (goodsType) wajib diisi.', 'lovecatz-wc' ),
			'weight'       => __( 'J&T Cargo: Berat total wajib diisi.', 'lovecatz-wc' ),
			'offerFee'     => __( 'J&T Cargo: Nilai barang wajib diisi.', 'lovecatz-wc' ),
		);
		foreach ( $required as $field => $message ) {
			if ( ! isset( $payload[ $field ] ) || '' === trim( (string) $payload[ $field ] ) ) {
				return new WP_Error( "lwc_jtc_missing_{$field}", $message );
			}
		}

		// 3. Declared maximum lengths.
		foreach ( self::LIMITS as $field => $limit ) {
			if ( isset( $payload[ $field ] ) && strlen( (string) $payload[ $field ] ) > $limit ) {
				return new WP_Error(
					"lwc_jtc_long_{$field}",
					sprintf(
						/* translators: 1: J&T field name, 2: maximum number of characters. */
						__( 'J&T Cargo: field %1$s melebihi batas maksimum %2$d karakter.', 'lovecatz-wc' ),
						$field,
						$limit
					)
				);
			}
		}

		// 4. Numeric sanity: the manual sets a hard 1 KG floor.
		if ( ! is_numeric( $payload['weight'] ) || (float) $payload['weight'] < self::WEIGHT_MIN_KG || (float) $payload['weight'] > self::WEIGHT_MAX_KG ) {
			return new WP_Error(
				'lwc_jtc_invalid_weight',
				sprintf(
					/* translators: 1: minimum weight, 2: maximum weight. */
					__( 'J&T Cargo: berat total harus antara %1$s kg dan %2$s kg.', 'lovecatz-wc' ),
					'1',
					'9999.99'
				)
			);
		}
		if ( ! is_numeric( $payload['offerFee'] ) || (float) $payload['offerFee'] <= 0 ) {
			return new WP_Error( 'lwc_jtc_invalid_offer_fee', __( 'J&T Cargo: nilai barang harus lebih dari 0.', 'lovecatz-wc' ) );
		}
		if ( isset( $payload['totalQuantity'] ) && ( ! is_numeric( $payload['totalQuantity'] ) || (int) $payload['totalQuantity'] < 1 ) ) {
			return new WP_Error( 'lwc_jtc_invalid_quantity', __( 'J&T Cargo: jumlah koli minimal 1.', 'lovecatz-wc' ) );
		}

		// 5. Sender and receiver.
		foreach ( array( 'sender', 'receiver' ) as $party ) {
			$check = self::validate_party( $party, isset( $payload[ $party ] ) ? $payload[ $party ] : null );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}

		// 6. Goods list, when present, must not be an empty array.
		if ( array_key_exists( 'items', $payload ) && empty( $payload['items'] ) ) {
			return new WP_Error( 'lwc_jtc_empty_items', __( 'J&T Cargo: daftar barang kosong.', 'lovecatz-wc' ) );
		}

		return true;
	}

	/**
	 * Create the J&T Cargo order for a WooCommerce order.
	 *
	 * @param WC_Order $order       Order.
	 * @param string   $environment sandbox|production. Empty = active environment.
	 * @return array|WP_Error
	 */
	public static function create_order( WC_Order $order, $environment = '' ) {
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'lwc_jtc_invalid_order', __( 'J&T Cargo: order tidak ditemukan.', 'lovecatz-wc' ) );
		}

		$existing = trim( (string) $order->get_meta( '_lwc_jt_cargo_awb', true ) );
		if ( '' !== $existing ) {
			return array(
				'success'        => true,
				'already_exist'  => true,
				'awb'            => $existing,
				'txlogistic_id'  => (string) $order->get_meta( '_lwc_jtc_txlogistic_id', true ),
			);
		}

		$credentials = LWC_JTC_Account::get_active_credentials();
		if ( '' === (string) $environment ) {
			$environment = isset( $credentials['environment'] ) ? $credentials['environment'] : 'sandbox';
		}

		$payload  = self::build_payload( $order );
		$valid    = self::validate_payload( $payload );
		$order_id = (int) $order->get_id();
		$number   = isset( $payload['txlogisticId'] ) ? (string) $payload['txlogisticId'] : '';

		// Never touch the network when local validation fails.
		if ( is_wp_error( $valid ) ) {
			self::log(
				array(
					'[J&T VALIDATION FAILED]',
					'Interface: add_order',
					'Order ID: ' . $order_id,
					'Customer Order Number: ' . $number,
					'Environment: ' . $environment,
					'Reason: ' . $valid->get_error_message(),
				)
			);
			return self::record_error( $order, $valid );
		}

		self::log(
			array(
				'[J&T REQUEST]',
				'Interface: add_order',
				'Order ID: ' . $order_id,
				'Customer Order Number: ' . $number,
				'Environment: ' . $environment,
				'Payload: ' . self::redact_for_log( $payload ),
			)
		);

		$response = LWC_JTC_API::send( 'add_order', $payload, $environment );

		if ( is_wp_error( $response ) ) {
			self::log(
				array(
					'[J&T TRANSPORT ERROR]',
					'Interface: add_order',
					'Order ID: ' . $order_id,
					'Customer Order Number: ' . $number,
					'Environment: ' . $environment,
					'Error: ' . $response->get_error_message(),
				)
			);
			return self::record_error( $order, $response );
		}

		$business = self::evaluate( $response );
		self::log(
			array(
				'[J&T RESPONSE]',
				'Interface: add_order',
				'Order ID: ' . $order_id,
				'Customer Order Number: ' . $number,
				'HTTP Status: ' . (int) $response['http_status'],
				'Business Code: ' . $business['code'],
				'Business Message: ' . $business['message'],
				'Business Success: ' . ( $business['success'] ? 'yes' : 'no' ),
			)
		);

		if ( ! $business['success'] ) {
			return self::record_error( $order, new WP_Error( 'lwc_jtc_business_error', $business['message'], $business ) );
		}

		$awb = $business['waybill'];
		if ( '' === $awb ) {
			// HTTP 200 + success flag but no AWB is NOT a created shipment.
			return self::record_error(
				$order,
				new WP_Error( 'lwc_jtc_missing_waybill', __( 'J&T Cargo: respons sukses tidak menyertakan nomor resi.', 'lovecatz-wc' ), $business )
			);
		}

		$order->update_meta_data( '_lwc_jt_cargo_awb', $awb );
		$order->update_meta_data( '_lwc_jtc_txlogistic_id', $number );
		if ( '' !== $business['sorting_code'] ) {
			$order->update_meta_data( '_lwc_jt_cargo_three_segment', $business['sorting_code'] );
		}
		$order->delete_meta_data( '_lwc_jt_cargo_create_error' );
		$order->add_order_note(
			sprintf(
				/* translators: 1: environment, 2: AWB number, 3: customer order number. */
				__( 'J&T Cargo %1$s order created. AWB: %2$s (customer order number %3$s).', 'lovecatz-wc' ),
				ucfirst( $environment ),
				$awb,
				$number
			)
		);
		$order->save();

		if ( class_exists( 'LWC_JTC_Label' ) ) {
			LWC_JTC_Label::store_response( $order, 'add_order', $response );
		}

		return array(
			'success'       => true,
			'awb'           => $awb,
			'txlogistic_id' => $number,
			'business_code' => $business['code'],
			'response'      => $response,
		);
	}

	/**
	 * Classify a J&T response.
	 *
	 * HTTP 2xx is transport success only. A shipment is created when J&T also
	 * reports business success AND returns a waybill number.
	 *
	 * @param array $response Envelope from LWC_JTC_API.
	 * @return array success, code, message, waybill, sorting_code.
	 */
	public static function evaluate( $response ) {
		$result = array(
			'success'      => false,
			'code'         => '',
			'message'      => __( 'J&T Cargo: respons tidak dikenali.', 'lovecatz-wc' ),
			'waybill'      => '',
			'sorting_code' => '',
		);

		if ( ! is_array( $response ) ) {
			return $result;
		}

		if ( isset( $response['business_code'] ) ) {
			$result['code'] = (string) $response['business_code'];
		}
		if ( isset( $response['business_message'] ) && '' !== (string) $response['business_message'] ) {
			$result['message'] = (string) $response['business_message'];
		}

		$http_ok = isset( $response['http_status'] ) && (int) $response['http_status'] >= 200 && (int) $response['http_status'] < 300;
		if ( ! $http_ok ) {
			$result['message'] = sprintf(
				/* translators: %d: HTTP status code. */
				__( 'J&T Cargo: HTTP %d — permintaan gagal di tingkat transport.', 'lovecatz-wc' ),
				(int) $response['http_status']
			);
			return $result;
		}

		if ( empty( $response['business_success'] ) ) {
			if ( '' === $result['message'] ) {
				$result['message'] = __( 'J&T Cargo: permintaan ditolak oleh J&T tanpa pesan.', 'lovecatz-wc' );
			}
			return $result;
		}

		$result['success'] = true;
		$body              = isset( $response['response'] ) && is_array( $response['response'] ) ? $response['response'] : array();
		$data              = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();

		foreach ( array( 'billCode', 'bill_code', 'waybillNo' ) as $key ) {
			if ( isset( $data[ $key ] ) && '' !== (string) $data[ $key ] ) {
				$result['waybill'] = (string) $data[ $key ];
				break;
			}
		}
		foreach ( array( 'sortingCode', 'sorting_code', 'threeSegmentCode' ) as $key ) {
			if ( isset( $data[ $key ] ) && '' !== (string) $data[ $key ] ) {
				$result['sorting_code'] = (string) $data[ $key ];
				break;
			}
		}

		return $result;
	}

	/* ------------------------------------------------------------------ *
	 * Customer order number helpers
	 * ------------------------------------------------------------------ */

	/** The store-facing order number used as the source for txlogisticId. */
	private static function order_number_source( WC_Order $order ) {
		$custom = trim( (string) $order->get_meta( '_lwc_order_number', true ) );
		if ( '' !== $custom ) {
			return $custom;
		}

		$display = trim( (string) $order->get_order_number() );
		if ( '' !== $display ) {
			return $display;
		}

		return 'LWC' . (string) $order->get_id();
	}

	/** Keep only characters J&T accepts in an order number. */
	private static function sanitize_order_number( $value ) {
		$value = preg_replace( '/[^A-Za-z0-9_-]+/', '', (string) $value );
		return trim( (string) $value, '-_ ' );
	}

	/**
	 * Shorten without losing uniqueness.
	 *
	 * A plain truncation could turn two different long order numbers into the
	 * same value. When shortening is unavoidable the visible head is kept and
	 * an 8-character hash of the FULL value is appended, so distinct order
	 * numbers stay distinct while the total stays within 50 characters.
	 */
	private static function enforce_txlogistic_id_length( $value ) {
		$value = trim( (string) $value );
		if ( strlen( $value ) <= self::TXLOGISTIC_ID_MAX ) {
			return $value;
		}

		$hash = substr( md5( $value ), 0, 8 );
		$head = substr( $value, 0, self::TXLOGISTIC_ID_MAX - strlen( $hash ) - 1 );

		return rtrim( $head, '-_' ) . '-' . $hash;
	}

	/* ------------------------------------------------------------------ *
	 * Payload blocks
	 * ------------------------------------------------------------------ */

	/** Sender block: the store origin. */
	private static function sender_block( WC_Order $order ) {
		$country_state = (string) get_option( 'woocommerce_default_country', 'ID' );
		$parts         = explode( ':', $country_state, 2 );
		$country       = strtoupper( isset( $parts[0] ) ? $parts[0] : 'ID' );
		$state         = isset( $parts[1] ) ? $parts[1] : '';

		$province = $state;
		$city     = (string) get_option( 'woocommerce_store_city', '' );
		if ( function_exists( 'WC' ) && WC() && isset( WC()->countries->states[ $country ][ $state ] ) ) {
			$province = WC()->countries->states[ $country ][ $state ];
		}

		$area = (string) get_option( 'lwc_jt_origin_district', '' );
		if ( '' === $area ) {
			$area = $city;
		}

		$address = trim( (string) get_option( 'woocommerce_store_address', '' ) . ' ' . (string) get_option( 'woocommerce_store_address_2', '' ) );

		$block = array(
			'name'        => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'mobile'      => self::phone( (string) get_option( 'woocommerce_store_phone', '' ) ),
			'countryCode' => self::country_code( $country ),
			'prov'        => $province,
			'city'        => $city,
			'area'        => $area,
			'address'     => $address,
			'postCode'    => (string) get_option( 'woocommerce_store_postcode', '' ),
		);

		return self::clip_party( (array) apply_filters( 'lwc_jtc_sender', $block, $order ) );
	}

	/** Receiver block: the buyer, shipping address first, billing as fallback. */
	private static function receiver_block( WC_Order $order ) {
		$country = (string) $order->get_shipping_country();
		if ( '' === $country ) {
			$country = (string) $order->get_billing_country();
		}

		$state = (string) $order->get_shipping_state();
		$city  = (string) $order->get_shipping_city();
		if ( '' === $city ) {
			$city = (string) $order->get_billing_city();
		}

		$province = $state;
		if ( function_exists( 'WC' ) && WC() && '' !== $country && isset( WC()->countries->states[ $country ][ $state ] ) ) {
			$province = WC()->countries->states[ $country ][ $state ];
		}

		$area = (string) $order->get_meta( '_lwc_shipping_district_name', true );
		if ( '' === $area ) {
			$area = (string) $order->get_meta( '_wc_shipping/lwc/indonesia-district', true );
		}
		if ( '' === $area ) {
			$area = $city;
		}

		$address = trim( (string) $order->get_shipping_address_1() . ' ' . (string) $order->get_shipping_address_2() );
		if ( '' === $address ) {
			$address = trim( (string) $order->get_billing_address_1() . ' ' . (string) $order->get_billing_address_2() );
		}

		$name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		if ( '' === $name ) {
			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}

		$phone = (string) $order->get_billing_phone();
		if ( method_exists( $order, 'get_shipping_phone' ) ) {
			$shipping_phone = (string) $order->get_shipping_phone();
			if ( '' !== $shipping_phone ) {
				$phone = $shipping_phone;
			}
		}

		$postcode = (string) $order->get_shipping_postcode();
		if ( '' === $postcode ) {
			$postcode = (string) $order->get_billing_postcode();
		}

		$block = array(
			'name'        => $name,
			'mobile'      => self::phone( $phone ),
			'countryCode' => self::country_code( $country ),
			'prov'        => $province,
			'city'        => $city,
			'area'        => $area,
			'address'     => $address,
			'postCode'    => $postcode,
		);

		return self::clip_party( (array) apply_filters( 'lwc_jtc_receiver', $block, $order ) );
	}

	/** Optional items block from the real order lines. */
	private static function items_block( WC_Order $order ) {
		$items    = array();
		$currency = (string) $order->get_currency();

		foreach ( $order->get_items() as $item ) {
			if ( ! is_callable( array( $item, 'get_quantity' ) ) ) {
				continue;
			}
			$line_total = (float) $item->get_total();
			$items[]    = self::clip_item(
				array(
					'itemName'      => wp_strip_all_tags( (string) $item->get_name() ),
					'number'        => max( 1, (int) $item->get_quantity() ),
					'itemValue'     => (string) (int) round( $line_total ),
					'priceCurrency' => $currency,
					'desc'          => wp_strip_all_tags( (string) $item->get_name() ),
				)
			);
		}

		return $items;
	}

	/** Total weight (string, KG) and optional carton dimensions. */
	private static function goods_weight_and_size( WC_Order $order ) {
		$override = (string) $order->get_meta( '_lwc_jt_cargo_billing_weight_kg', true );
		$weight   = ( '' !== $override && is_numeric( $override ) ) ? (float) $override : null;

		$length = null;
		$width  = null;
		$height = null;

		if ( null === $weight ) {
			$weight = 0.0;
			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();
				if ( ! $product ) {
					continue;
				}
				$line = (float) $product->get_weight();
				if ( $line > 0 ) {
					$weight += (float) wc_get_weight( $line * max( 1, (float) $item->get_quantity() ), 'kg' );
				}
				if ( null === $length && (float) $product->get_length() > 0 ) {
					$length = (int) round( (float) wc_get_dimension( (float) $product->get_length(), 'cm' ) );
				}
				if ( null === $width && (float) $product->get_width() > 0 ) {
					$width = (int) round( (float) wc_get_dimension( (float) $product->get_width(), 'cm' ) );
				}
				if ( null === $height && (float) $product->get_height() > 0 ) {
					$height = (int) round( (float) wc_get_dimension( (float) $product->get_height(), 'cm' ) );
				}
			}
		}

		// The manual sets a hard 1 KG floor; anything below is not shippable.
		$weight = max( self::WEIGHT_MIN_KG, round( $weight, 2 ) );
		$weight = min( $weight, self::WEIGHT_MAX_KG );

		$out = array( 'weight' => self::number_to_string( $weight ) );
		foreach ( array( 'length' => $length, 'width' => $width, 'height' => $height ) as $dimension => $value ) {
			if ( null !== $value && $value > 0 ) {
				$out[ $dimension ] = $value;
			}
		}

		return $out;
	}

	/** Declared goods value in whole IDR. */
	private static function goods_value( WC_Order $order ) {
		$total = (float) $order->get_total();
		if ( $total <= 0 ) {
			// Fall back to the sum of the line totals when discounts zeroed it.
			foreach ( $order->get_items() as $item ) {
				$total += (float) $item->get_total();
			}
		}

		return (string) max( 1, (int) round( $total ) );
	}

	/** Number of packages (koli), never the number of items. */
	private static function package_count( WC_Order $order ) {
		$override = (int) $order->get_meta( '_lwc_jtc_total_quantity', true );
		if ( $override > 0 ) {
			return $override;
		}

		return (int) apply_filters( 'lwc_jtc_total_quantity', 1, $order );
	}

	private static function express_type( WC_Order $order ) {
		return (string) apply_filters( 'lwc_jtc_express_type', 'FT', $order );
	}

	private static function order_type( WC_Order $order ) {
		return (string) apply_filters( 'lwc_jtc_order_type', '1', $order );
	}

	private static function service_type( WC_Order $order ) {
		return (string) apply_filters( 'lwc_jtc_service_type', '01', $order );
	}

	private static function delivery_type( WC_Order $order ) {
		return (string) apply_filters( 'lwc_jtc_delivery_type', '101', $order );
	}

	private static function pay_type( WC_Order $order ) {
		$stored = trim( (string) $order->get_meta( '_lwc_jtc_pay_type', true ) );
		if ( '' !== $stored ) {
			return $stored;
		}
		return (string) apply_filters( 'lwc_jtc_pay_type', 'PP_PM', $order );
	}

	private static function goods_type( WC_Order $order ) {
		$stored = trim( (string) $order->get_meta( '_lwc_jtc_goods_type', true ) );
		if ( '' !== $stored ) {
			return $stored;
		}
		return (string) apply_filters( 'lwc_jtc_goods_type', 'bm000007', $order );
	}

	/* ------------------------------------------------------------------ *
	 * Sanitising helpers
	 * ------------------------------------------------------------------ */

	/** J&T party fields accept the phone as entered; only noise is removed. */
	private static function phone( $phone ) {
		$phone = preg_replace( '/[^0-9+]/', '', (string) $phone );
		return self::clip( (string) $phone, self::PARTY_LIMITS['mobile'] );
	}

	/** ISO-3166 alpha-2 -> J&T three letter country code. */
	private static function country_code( $country ) {
		$map = array(
			'ID' => 'IDN',
		);
		$country = strtoupper( (string) $country );
		$code    = isset( $map[ $country ] ) ? $map[ $country ] : $country;
		return self::clip( (string) apply_filters( 'lwc_jtc_country_code', $code, $country ), self::PARTY_LIMITS['countryCode'] );
	}

	/** Trim one value to a J&T maximum length. */
	private static function clip( $value, $limit ) {
		$value = trim( (string) $value );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, (int) $limit );
		}
		return substr( $value, 0, (int) $limit );
	}

	/** Apply every sender/receiver limit declared by the manual. */
	private static function clip_party( $block ) {
		return self::clip_known( $block, self::PARTY_LIMITS );
	}

	/** Apply every item limit declared by the manual. */
	private static function clip_item( $block ) {
		return self::clip_known( $block, self::ITEM_LIMITS );
	}

	private static function clip_known( $block, $limits ) {
		if ( ! is_array( $block ) ) {
			return array();
		}
		foreach ( $block as $key => $value ) {
			if ( isset( $limits[ $key ] ) ) {
				$block[ $key ] = self::clip( $value, $limits[ $key ] );
			}
		}
		return $block;
	}

	/** 1.0 -> "1", 1.5 -> "1.5" so the field stays a short numeric string. */
	private static function number_to_string( $value ) {
		$value = round( (float) $value, 2 );
		if ( 0.0 === fmod( $value, 1.0 ) ) {
			return (string) (int) $value;
		}
		return rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
	}

	/* ------------------------------------------------------------------ *
	 * Validation helpers
	 * ------------------------------------------------------------------ */

	/** Validate one sender/receiver block. */
	private static function validate_party( $label, $block ) {
		$party = 'sender' === $label ? __( 'pengirim', 'lovecatz-wc' ) : __( 'penerima', 'lovecatz-wc' );

		if ( ! is_array( $block ) || empty( $block ) ) {
			return new WP_Error(
				"lwc_jtc_missing_{$label}",
				/* translators: %s: sender or receiver, in Indonesian. */
				sprintf( __( 'J&T Cargo: data %s wajib diisi.', 'lovecatz-wc' ), $party )
			);
		}

		$required = array(
			'name'        => 'nama',
			'mobile'      => 'telepon',
			'countryCode' => 'kode negara',
			'prov'        => 'provinsi',
			'city'        => 'kota',
			'area'        => 'kecamatan',
			'address'     => 'alamat',
		);
		foreach ( $required as $field => $field_label ) {
			if ( ! isset( $block[ $field ] ) || '' === trim( (string) $block[ $field ] ) ) {
				return new WP_Error(
					"lwc_jtc_missing_{$label}_{$field}",
					/* translators: 1: sender or receiver, 2: field name, both Indonesian. */
					sprintf( __( 'J&T Cargo: %1$s %2$s wajib diisi.', 'lovecatz-wc' ), $field_label, $party )
				);
			}
		}

		foreach ( self::PARTY_LIMITS as $field => $limit ) {
			if ( isset( $block[ $field ] ) && strlen( (string) $block[ $field ] ) > $limit ) {
				return new WP_Error(
					"lwc_jtc_long_{$label}_{$field}",
					sprintf(
						/* translators: 1: sender or receiver, 2: J&T field name, 3: maximum characters. */
						__( 'J&T Cargo: %1$s %2$s melebihi batas maksimum %3$d karakter.', 'lovecatz-wc' ),
						$party,
						$field,
						$limit
					)
				);
			}
		}

		return true;
	}

	/* ------------------------------------------------------------------ *
	 * Logging
	 * ------------------------------------------------------------------ */

	/** Persist the error on the order so the admin sees it after a reload. */
	private static function record_error( WC_Order $order, WP_Error $error ) {
		$message = $error->get_error_message();
		$order->update_meta_data( '_lwc_jt_cargo_create_error', $message );
		$order->add_order_note(
			sprintf(
				/* translators: %s: error message returned by J&T or by local validation. */
				__( 'J&T Cargo order creation failed: %s', 'lovecatz-wc' ),
				$message
			)
		);
		$order->save();
		return $error;
	}

	/** Structured, credential-free debug logging. */
	private static function log( $lines ) {
		if ( ! self::debug_enabled() ) {
			return;
		}
		if ( ! class_exists( 'LWC_Logger' ) ) {
			return;
		}
		LWC_Logger::log( implode( "\n", (array) $lines ), 'debug', 'lwc-jtc' );
	}

	/** Debug switch: option `lwc_jtc_debug`, overridable per site. */
	public static function debug_enabled() {
		$enabled = 'yes' === (string) get_option( 'lwc_jtc_debug', 'no' );
		return (bool) apply_filters( 'lwc_jtc_debug_enabled', $enabled );
	}

	/**
	 * Render a payload for the log with every credential masked.
	 *
	 * customerCode and digest are removed; any nested key that looks like a
	 * secret is replaced too, so a future field can never leak by accident.
	 */
	public static function redact_for_log( $payload ) {
		$payload = is_array( $payload ) ? $payload : array();
		$json    = wp_json_encode( self::redact( $payload ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		return false === $json ? '' : $json;
	}

	/** Recursively mask credential-looking values. */
	private static function redact( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$secret_keys = array( 'customercode', 'digest', 'privatekey', 'password', 'pwd', 'apiaccount', 'secret', 'token', 'authorization' );
		$out         = array();
		foreach ( $value as $key => $item ) {
			$normalised = strtolower( preg_replace( '/[^A-Za-z]/', '', (string) $key ) );
			if ( in_array( $normalised, $secret_keys, true ) ) {
				$out[ $key ] = '***';
				continue;
			}
			$out[ $key ] = is_array( $item ) ? self::redact( $item ) : $item;
		}
		return $out;
	}
}
