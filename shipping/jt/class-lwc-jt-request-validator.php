<?php
/** Validate J&T Express Indonesia request payloads before they leave WordPress. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_JT_Request_Validator {

	const MAX_WEIGHT_KG = 100.0;

	/** Return a Jakarta/WIB timestamp in the exact format required by J&T. */
	public static function jakarta_timestamp( $modifier = '' ) {
		$now = new DateTimeImmutable( 'now', new DateTimeZone( 'Asia/Jakarta' ) );
		if ( '' !== (string) $modifier ) {
			$modified = $now->modify( (string) $modifier );
			if ( false !== $modified ) {
				$now = $modified;
			}
		}
		return $now->format( 'Y-m-d H:i:s' );
	}

	/** Indonesian J&T postcodes must contain exactly five digits. */
	public static function is_valid_postcode( $postcode ) {
		return 1 === preg_match( '/^\d{5}$/', trim( (string) $postcode ) );
	}

	/** J&T Indonesia requires +62 international phone notation, max 15 chars. */
	public static function is_valid_phone( $phone ) {
		$phone = trim( (string) $phone );
		return strlen( $phone ) <= 15 && 1 === preg_match( '/^\+62\d{8,12}$/', $phone );
	}

	/** Validate the API's strict 0 < weight <= 100 kg boundary. */
	public static function validate_weight( $weight ) {
		$weight = (float) $weight;
		if ( $weight <= 0 || $weight > self::MAX_WEIGHT_KG ) {
			return new WP_Error(
				'lwc_jt_invalid_weight',
				sprintf( __( 'J&T Express package weight must be greater than 0 and no more than %s kg.', 'lovecatz-wc' ), '100' )
			);
		}
		return true;
	}

	/** Validate a complete Basic Order detail object against J&T's contract. */
	public static function validate_order( $data ) {
		$required = array(
			'orderid', 'shipper_name', 'shipper_contact', 'shipper_phone', 'shipper_addr',
			'origin_code', 'receiver_name', 'receiver_phone', 'receiver_addr', 'receiver_zip',
			'destination_code', 'receiver_area', 'qty', 'weight', 'goodsdesc', 'servicetype',
			'orderdate', 'item_name', 'sendstarttime', 'sendendtime', 'expresstype', 'goodsvalue',
		);
		foreach ( $required as $field ) {
			if ( ! isset( $data[ $field ] ) || '' === trim( (string) $data[ $field ] ) ) {
				return new WP_Error( 'lwc_jt_missing_parameter', sprintf( __( 'J&T parameter %s cannot be empty.', 'lovecatz-wc' ), $field ) );
			}
		}

		$limits = array(
			'orderid' => 20, 'shipper_name' => 30, 'shipper_contact' => 30, 'shipper_phone' => 15,
			'shipper_addr' => 200, 'origin_code' => 3, 'receiver_name' => 30, 'receiver_phone' => 15,
			'receiver_addr' => 200, 'receiver_zip' => 5, 'destination_code' => 3,
			'receiver_area' => 10, 'goodsdesc' => 40, 'item_name' => 50,
		);
		foreach ( $limits as $field => $limit ) {
			if ( strlen( (string) $data[ $field ] ) > $limit ) {
				return new WP_Error( 'lwc_jt_parameter_too_long', sprintf( __( 'J&T parameter %1$s cannot exceed %2$d characters.', 'lovecatz-wc' ), $field, $limit ) );
			}
		}

		if ( ! self::is_valid_postcode( $data['receiver_zip'] ) ) {
			return new WP_Error( 'lwc_jt_invalid_postcode', __( 'J&T recipient postal code must contain exactly five digits.', 'lovecatz-wc' ) );
		}
		if ( ! self::is_valid_phone( $data['shipper_phone'] ) || ! self::is_valid_phone( $data['receiver_phone'] ) ) {
			return new WP_Error( 'lwc_jt_invalid_phone', __( 'J&T phone numbers must use +62 format and contain no more than 15 characters.', 'lovecatz-wc' ) );
		}
		$weight = self::validate_weight( $data['weight'] );
		if ( is_wp_error( $weight ) ) {
			return $weight;
		}
		if ( ! in_array( (int) $data['servicetype'], array( 1, 6 ), true ) ) {
			return new WP_Error( 'lwc_jt_invalid_service_type', __( 'J&T service type must be 1 (pickup) or 6 (drop off).', 'lovecatz-wc' ) );
		}
		if ( '1' !== (string) $data['expresstype'] ) {
			return new WP_Error( 'lwc_jt_invalid_express_type', __( 'J&T Express regular service requires expresstype 1.', 'lovecatz-wc' ) );
		}
		if ( 1 !== preg_match( '/^[A-Z0-9]{3}$/', (string) $data['origin_code'] ) || 1 !== preg_match( '/^[A-Z0-9]{3}$/', (string) $data['destination_code'] ) ) {
			return new WP_Error( 'lwc_jt_invalid_city_code', __( 'J&T origin and destination city codes must be three uppercase letters or digits.', 'lovecatz-wc' ) );
		}
		if ( 1 !== preg_match( '/^[A-Z0-9]{1,10}$/', (string) $data['receiver_area'] ) ) {
			return new WP_Error( 'lwc_jt_invalid_area_code', __( 'J&T recipient area code must be uppercase letters or digits and no more than 10 characters.', 'lovecatz-wc' ) );
		}
		foreach ( array( 'goodsdesc', 'item_name' ) as $field ) {
			if ( 1 !== preg_match( '/^[\p{L}\p{N} ]+$/u', (string) $data[ $field ] ) ) {
				return new WP_Error( 'lwc_jt_invalid_goods_text', sprintf( __( 'J&T parameter %s cannot contain special characters.', 'lovecatz-wc' ), $field ) );
			}
		}
		foreach ( array( 'orderdate', 'sendstarttime', 'sendendtime' ) as $field ) {
			if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $data[ $field ] ) ) {
				return new WP_Error( 'lwc_jt_invalid_datetime', sprintf( __( 'J&T parameter %s must use YYYY-MM-DD hh:mm:ss in UTC+7.', 'lovecatz-wc' ), $field ) );
			}
		}
		return true;
	}
}
