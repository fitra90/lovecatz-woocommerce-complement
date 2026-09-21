<?php
/**
 * J&T Cargo printable waybill renderer.
 *
 * Generates the one-part thermal label that mirrors the official J&T Cargo
 * layout (76 mm × 175 mm). The renderer is environment-agnostic — it works
 * in Sandbox before the 3/3 certification is finished and keeps working once
 * Production credentials are issued, because the carrier never returns a
 * finished PDF for this template.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_JTC_Label {

	const PAPER_WIDTH_MM  = 76;
	const PAPER_HEIGHT_MM = 175;

	/** Register the AJAX handler used by the print button. */
	public static function init() {
		add_action( 'wp_ajax_lwc_jtc_print_label', array( __CLASS__, 'handle_print_request' ) );
	}

	/** Build the URL that the order-list / metabox buttons point at. */
	public static function get_print_url( $order_id ) {
		return add_query_arg(
			array(
				'action'   => 'lwc_jtc_print_label',
				'order_id' => (int) $order_id,
				'_wpnonce' => wp_create_nonce( 'lwc_jtc_print_label' ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/** Build the URL that opens the server-driven tracking view. */
	public static function get_track_url( $order_id ) {
		return add_query_arg(
			array(
				'action'   => 'lwc_jtc_track_order',
				'order_id' => (int) $order_id,
				'render'   => 'html',
				'nonce'    => wp_create_nonce( 'lwc_jtc_track_order' ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/** True when the order is shipped through the J&T Cargo method. */
	public static function order_uses_cargo( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			if ( 'lwc_jt_cargo' === (string) $item->get_method_id() ) {
				return true;
			}
		}
		return false;
	}

	/** Resolve the J&T Cargo logo image URL. Filterable per store. */
	public static function logo_url() {
		$default = LWC_PLUGIN_URL . 'shipping/jtc/assets/logo-jnt-cargo.png';
		return apply_filters( 'lwc_jtc_label_logo_url', $default );
	}

	/** Render the full label HTML for the given order. */
	public static function render_html( WC_Order $order ) {
		$data  = self::collect( $order );
		$path  = LWC_PLUGIN_DIR . 'shipping/jtc/templates/jtc-label.php';
		if ( ! file_exists( $path ) ) {
			return '';
		}
		ob_start();
		$order = $order; // silence phpcs
		include $path;
		return (string) ob_get_clean();
	}

	/** AJAX handler that prints the label directly to the response. */
	public static function handle_print_request() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'lovecatz-wc' ) ), 403 );
		}

		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'lwc_jtc_print_label' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid print request token.', 'lovecatz-wc' ) ), 403 );
		}

		$order_id = isset( $_REQUEST['order_id'] ) ? absint( wp_unslash( $_REQUEST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'lovecatz-wc' ) ), 404 );
		}
		if ( ! self::order_uses_cargo( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'This order is not shipped with J&T Cargo.', 'lovecatz-wc' ) ), 400 );
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo self::render_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template handles escaping.
		exit;
	}

	/**
	 * Raw carrier response stored for this order.
	 *
	 * The payload is kept byte-for-byte as J&T sent it. Nothing here rewrites
	 * or repairs server data — presentation happens at render time.
	 *
	 * @param WC_Order $order Order.
	 * @return array|null
	 */
	public static function get_stored_response( WC_Order $order ) {
		$raw = (string) $order->get_meta( '_lwc_jt_cargo_response', true );
		if ( '' === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/** Persist a raw response verbatim, together with its interface tag. */
	public static function store_response( WC_Order $order, $interface, $response ) {
		$order->update_meta_data( '_lwc_jt_cargo_response', (string) wp_json_encode( $response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		$order->update_meta_data( '_lwc_jt_cargo_response_interface', sanitize_key( (string) $interface ) );
		$order->update_meta_data( '_lwc_jt_cargo_response_at', current_time( 'mysql' ) );
		$order->save();
		return true;
	}

	/**
	 * Resolve label values from the stored API response through the field map.
	 *
	 * Only keys the server actually returned are present. Missing keys stay
	 * absent so the template hides the element instead of inventing a value.
	 * Values come back already converted to Latin script.
	 *
	 * @param WC_Order $order Order.
	 * @return array logical key => display text.
	 */
	public static function api_values( WC_Order $order ) {
		$response = self::get_stored_response( $order );
		if ( null === $response ) {
			return array();
		}

		$interface = (string) $order->get_meta( '_lwc_jt_cargo_response_interface', true );
		if ( '' === $interface ) {
			$interface = 'print_order';
		}

		$view  = LWC_JTC_Response_View::build( $interface, $response );
		$values = array();
		foreach ( $view['groups'] as $group ) {
			foreach ( $group['items'] as $item ) {
				if ( empty( $item['value']['has_value'] ) ) {
					continue;
				}
				if ( ! isset( $values[ $item['key'] ] ) ) {
					$values[ $item['key'] ] = $item['value']['text'];
				}
			}
		}
		return (array) apply_filters( 'lwc_jtc_label_api_values', $values, $order, $view );
	}

	/**
	 * Aggregate every variable the template needs.
	 *
	 * Source of truth, in order:
	 *   1. the carrier response stored for this order (mapped by field key),
	 *   2. real order data (WooCommerce),
	 *   3. local store configuration.
	 *
	 * Nothing falls back to a made-up constant: when a value is unavailable the
	 * key is set to an empty string and the template hides the element.
	 */
	public static function collect( WC_Order $order ) {
		$api  = self::api_values( $order );
		$meta = array(
			'awb'             => (string) $order->get_meta( '_lwc_jt_cargo_awb', true ),
			'three_segment'   => (string) $order->get_meta( '_lwc_jt_cargo_three_segment', true ),
			'consolidated'    => 'yes' === (string) $order->get_meta( '_lwc_jt_cargo_consolidated', true ),
			'cod'             => (string) $order->get_meta( '_lwc_jt_cargo_cod', true ),
			'freight_collect' => (string) $order->get_meta( '_lwc_jt_cargo_freight_collect', true ),
			'insurance'       => (string) $order->get_meta( '_lwc_jt_cargo_insurance', true ),
			'origin_code'     => (string) $order->get_meta( '_lwc_jt_cargo_origin_code', true ),
		);

		$store = self::store_origin();

		// First non-empty source wins: server response, then order meta.
		$waybill       = self::first_non_empty( array( isset( $api['waybill'] ) ? $api['waybill'] : '', $meta['awb'] ) );
		$three_segment = self::first_non_empty( array( isset( $api['three_segment'] ) ? $api['three_segment'] : '', $meta['three_segment'] ) );
		$origin_code   = self::first_non_empty( array( isset( $api['origin_code'] ) ? $api['origin_code'] : '', $meta['origin_code'], $store['code'] ) );
		$cod_amount    = self::first_non_empty( array( isset( $api['cod_amount'] ) ? $api['cod_amount'] : '', $meta['cod'] ) );
		$product_type  = self::first_non_empty( array( isset( $api['service_type'] ) ? $api['service_type'] : '', isset( $api['product_type'] ) ? $api['product_type'] : '' ) );
		$remarks       = self::first_non_empty( array( isset( $api['remarks'] ) ? $api['remarks'] : '', (string) $order->get_customer_note() ) );

		// Free text can arrive in any script; convert it before it is printed.
		$remarks = LWC_JTC_Text::text( $remarks );

		$segments   = preg_split( '/\s+/', trim( $three_segment ) );
		$wm_segment = isset( $segments[1] ) ? $segments[1] : '';

		$weight_kg = self::compute_billing_weight( $order );
		if ( null === $weight_kg && isset( $api['billing_weight'] ) ) {
			$weight_kg = (float) $api['billing_weight'];
		}
		$weight_unit = isset( $api['weight_unit'] ) ? $api['weight_unit'] : '';

		$currency = $order->get_currency();
		$symbol   = html_entity_decode( get_woocommerce_currency_symbol( $currency ) );

		$consolidated = $meta['consolidated'];
		if ( isset( $api['consolidated'] ) ) {
			$consolidated = in_array( strtolower( (string) $api['consolidated'] ), array( 'ya', 'yes', 'true', '1' ), true );
		}

		return array(
			'order_id'             => (int) $order->get_id(),
			'company_name'         => $store['name'],
			'company_logo_text'    => apply_filters( 'lwc_jtc_label_brand_text', __( 'J&T Cargo', 'lovecatz-wc' ) ),
			'company_logo_url'     => self::logo_url(),
			'hotline'              => (string) apply_filters( 'lwc_jtc_label_hotline', (string) get_option( 'lwc_jtc_label_hotline', '' ) ),
			'waybill'              => $waybill,
			'product_type'         => $product_type,
			'three_segment'        => $three_segment,
			'three_segment_wm'     => $wm_segment,
			'print_time'           => current_time( 'Y/m/d H:i:s' ),
			'sheet_seq'            => (string) apply_filters( 'lwc_jtc_label_sheet_seq', '' ),
			'consolidated'         => $consolidated,
			'origin_code'          => $origin_code,
			'recipient'            => self::party_for_order( $order, 'shipping' ),
			'sender'               => $store,
			'cod_amount'           => $cod_amount,
			'cod_currency_symbol'  => $symbol,
			'freight_collect'      => $meta['freight_collect'],
			'freight_currency_symbol' => $symbol,
			'has_insurance'        => '' !== $meta['insurance'],
			'currency_symbol'      => $symbol,
			'billing_weight'       => $weight_kg,
			'billing_weight_unit'  => '' !== $weight_unit ? $weight_unit : (string) get_option( 'woocommerce_weight_unit', '' ),
			'remarks'              => $remarks,
			'wechat_label'         => (string) apply_filters( 'lwc_jtc_label_contact', (string) get_option( 'lwc_jtc_label_contact', '' ) ),
			'signature_terms'      => (string) apply_filters( 'lwc_jtc_label_terms', (string) get_option( 'lwc_jtc_label_terms', '' ) ),
			'qr_data'              => self::build_qr_data( $order ),
			'api_values'           => $api,
			'is_preview'           => '' === $waybill,
		);
	}

	/** First non-empty string among the candidates; '' when none has a value. */
	private static function first_non_empty( $candidates ) {
		foreach ( (array) $candidates as $candidate ) {
			$value = trim( (string) $candidate );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/** Store origin block (sender). Reads WooCommerce general settings. */
	private static function store_origin() {
		$country_state = (string) get_option( 'woocommerce_default_country', 'ID' );
		$parts         = explode( ':', $country_state, 2 );
		$country       = strtoupper( isset( $parts[0] ) ? $parts[0] : 'ID' );
		$state         = strtoupper( isset( $parts[1] ) ? $parts[1] : '' );
		$city          = (string) get_option( 'woocommerce_store_city', '' );
		$address_1     = (string) get_option( 'woocommerce_store_address', '' );
		$address_2     = (string) get_option( 'woocommerce_store_address_2', '' );
		$postcode      = (string) get_option( 'woocommerce_store_postcode', '' );

		$country_label = isset( WC()->countries->countries[ $country ] ) ? WC()->countries->countries[ $country ] : $country;
		$state_label   = '';
		if ( $state && isset( WC()->countries->states[ $country ][ $state ] ) ) {
			$state_label = WC()->countries->states[ $country ][ $state ];
		}

		$district = '';
		if ( function_exists( 'LWC_Indonesia_Regions' ) && class_exists( 'LWC_Indonesia_Regions' ) ) {
			$district = (string) get_option( 'lwc_jt_origin_district', '' );
		}

		return array(
			'name'      => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'phone'     => (string) get_option( 'woocommerce_store_phone', '' ),
			'company'   => '',
			'country'   => $country_label,
			'state'     => $state_label,
			'state_code' => $state,
			'city'      => $city,
			'district'  => $district,
			'address_1' => $address_1,
			'address_2' => $address_2,
			'postcode'  => $postcode,
			'address'   => self::compose_address( $state_label, $city, $district, $address_1 . ' ' . $address_2, $state ),
			'code'      => $state,
		);
	}

	/** Build the recipient or sender address string with the single-level region rule. */
	private static function party_for_order( WC_Order $order, $type ) {
		$country = $order->{"get_{$type}_country"}();
		if ( '' === $country ) {
			$country = $order->get_billing_country();
		}
		$state = $order->{"get_{$type}_state"}();
		$city  = $order->{"get_{$type}_city"}();
		$addr1 = $order->{"get_{$type}_address_1"}();
		$addr2 = $order->{"get_{$type}_address_2"}();

		$states_map = isset( WC()->countries->states[ $country ] ) ? WC()->countries->states[ $country ] : array();
		$state_lbl  = isset( $states_map[ $state ] ) ? $states_map[ $state ] : $state;
		$city_lbl   = isset( $states_map[ $city ] ) ? $states_map[ $city ] : $city;
		$district   = (string) $order->get_meta( "_lwc_{$type}_district_name", true );
		$street     = trim( $addr1 . ' ' . $addr2 );

		$phone = $order->get_billing_phone();
		if ( 'shipping' === $type && method_exists( $order, 'get_shipping_phone' ) ) {
			$shipping_phone = $order->get_shipping_phone();
			if ( ! empty( $shipping_phone ) ) {
				$phone = $shipping_phone;
			}
		}

		$name = trim( $order->{"get_{$type}_first_name"}() . ' ' . $order->{"get_{$type}_last_name"}() );

		return array(
			'name'      => $name,
			'phone'     => $phone,
			'company'   => $order->{"get_{$type}_company"}(),
			'state'     => $state_lbl,
			'city'      => $city_lbl,
			'district'  => $district,
			'street'    => $street,
			'address'   => self::compose_address( $state_lbl, $city_lbl, $district, $street, $state ),
			'has_company' => '' !== $order->{"get_{$type}_company"}(),
		);
	}

	/**
	 * Province → city → district → street, joined with spaces.
	 *
	 * Regions listed in the filter are single-level (province and city are the
	 * same entity), so the duplicated city is collapsed.
	 */
	private static function compose_address( $state_label, $city_label, $district, $street, $state_code ) {
		$single_level = (array) apply_filters( 'lwc_jtc_label_single_level_regions', array( 'BJ', 'SH', 'TJ', 'CQ' ) );
		$parts        = array( $state_label );

		if ( ! ( in_array( $state_code, $single_level, true ) && $state_label === $city_label ) ) {
			$parts[] = $city_label;
		}

		$parts[] = $district;
		$parts[] = $street;

		$parts = array_filter( array_map( 'trim', $parts ), 'strlen' );

		return implode( ' ', $parts );
	}

	/**
	 * Billing weight in kg derived from real product weights.
	 *
	 * Returns null when neither the order nor its products carry a weight:
	 * the label then hides the weight row instead of printing a guessed number.
	 *
	 * @return float|null
	 */
	private static function compute_billing_weight( WC_Order $order ) {
		$override = (string) $order->get_meta( '_lwc_jt_cargo_billing_weight_kg', true );
		if ( '' !== $override && is_numeric( $override ) ) {
			return (float) $override;
		}

		$weight = 0.0;
		$found  = false;
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$w = (float) $product->get_weight();
			if ( $w <= 0 ) {
				continue; // No weight on this product: contribute nothing.
			}
			$found    = true;
			$qty      = max( 1, (int) $item->get_quantity() );
			$weight  += (float) wc_get_weight( $w * $qty, 'kg' );
		}

		return $found ? round( $weight, 2 ) : null;
	}

	/** Data encoded into the recipient QR — defaults to the order URL. */
	private static function build_qr_data( WC_Order $order ) {
		$url = $order->get_checkout_order_received_url();
		if ( ! $url ) {
			$url = admin_url( 'post.php?post=' . (int) $order->get_id() . '&action=edit' );
		}
		return apply_filters( 'lwc_jtc_label_qr_data', $url, $order );
	}
}