<?php
/**
 * Custom FedEx shipper (origin) address storage and resolution.
 *
 * WooCommerce only exposes one store address, but a merchant may ship from a
 * different location than the one configured in WooCommerce > Settings >
 * General (a warehouse, a 3PL, or a branch). FedEx requires the true physical
 * origin on every rate, shipment, label, AWB, and pickup request: posting the
 * wrong origin produces invalid labels and failed pickups.
 *
 * This class owns the "custom shipper address" feature: a toggle plus a full
 * address block. When the toggle is on and the stored address is complete,
 * every FedEx request uses it. When it is off or incomplete, requests keep
 * using the WooCommerce store address so existing behaviour never breaks.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_FedEx_Shipper_Address {

	/** Option that turns the custom shipper address on or off. */
	const OPT_ENABLED = 'lwc_fedex_shipper_address_enabled';

	/** Prefix for every address-part option. */
	const OPT_PREFIX = 'lwc_fedex_shipper_address_';

	/**
	 * Address parts managed by this feature.
	 *
	 * `label` is the admin field label, `required` marks parts FedEx cannot
	 * do without, and `max_length` mirrors the FedEx API limits so values are
	 * truncated before they are sent rather than rejected by the API.
	 *
	 * @var array
	 */
	const FIELDS = array(
		'name'      => array( 'label' => 'Contact name', 'required' => true, 'max_length' => 70 ),
		'company'   => array( 'label' => 'Company', 'required' => false, 'max_length' => 70 ),
		'phone'     => array( 'label' => 'Phone', 'required' => true, 'max_length' => 30 ),
		'address_1' => array( 'label' => 'Address line 1', 'required' => true, 'max_length' => 35 ),
		'address_2' => array( 'label' => 'Address line 2', 'required' => false, 'max_length' => 35 ),
		'city'      => array( 'label' => 'City', 'required' => true, 'max_length' => 50 ),
		'state'     => array( 'label' => 'State / Province', 'required' => false, 'max_length' => 60 ),
		'postcode'  => array( 'label' => 'Postal code', 'required' => true, 'max_length' => 20 ),
		'country'   => array( 'label' => 'Country', 'required' => true, 'max_length' => 2 ),
	);

	/**
	 * Whether the custom shipper address feature is switched on.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return 'yes' === get_option( self::OPT_ENABLED, 'no' );
	}

	/**
	 * Option name for one address part.
	 *
	 * @param string $part Address part key from {@see self::FIELDS}.
	 * @return string
	 */
	public static function get_option_name( $part ) {
		return self::OPT_PREFIX . $part;
	}

	/**
	 * Read one address part.
	 *
	 * Country falls back to the WooCommerce base country so an empty field
	 * still yields a usable two-letter code instead of failing the request.
	 *
	 * @param string $part Address part key.
	 * @return string
	 */
	public static function get_part( $part ) {
		$value = (string) get_option( self::get_option_name( $part ), '' );

		if ( 'country' === $part && '' === $value ) {
			$value = self::get_store_country();
		}

		return trim( $value );
	}

	/**
	 * Read every address part as a keyed array.
	 *
	 * @return array
	 */
	public static function get_address() {
		$address = array();

		foreach ( array_keys( self::FIELDS ) as $part ) {
			$address[ $part ] = self::get_part( $part );
		}

		return $address;
	}

	/**
	 * Whether the stored custom address is complete enough for FedEx.
	 *
	 * @return bool
	 */
	public static function is_complete() {
		return ! is_wp_error( self::validate() );
	}

	/**
	 * Validate the stored custom address.
	 *
	 * Returns a WP_Error listing every missing or malformed part so the admin
	 * can fix the whole block in one pass instead of one error per request.
	 *
	 * @return array|WP_Error Address array on success, WP_Error otherwise.
	 */
	public static function validate() {
		$address = self::get_address();
		$errors  = array();

		foreach ( self::FIELDS as $part => $config ) {
			if ( $config['required'] && '' === $address[ $part ] ) {
				$errors[] = $config['label'];
			}
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'lwc_fedex_shipper_address_incomplete',
				sprintf(
					/* translators: %s: comma-separated list of missing address fields. */
					__( 'The custom FedEx shipper address is incomplete. Missing: %s.', 'lovecatz-wc' ),
					implode( ', ', $errors )
				),
				array( 'missing' => $errors )
			);
		}

		if ( ! preg_match( '/^[A-Za-z]{2}$/', $address['country'] ) ) {
			return new WP_Error(
				'lwc_fedex_shipper_address_country',
				__( 'The custom FedEx shipper country must be a two-letter ISO code such as ID or US.', 'lovecatz-wc' )
			);
		}

		return $address;
	}

	/**
	 * Human-readable message for an invalid or incomplete custom address.
	 *
	 * @return string
	 */
	public static function get_invalid_message() {
		$validated = self::validate();

		if ( is_wp_error( $validated ) ) {
			$message = $validated->get_error_message();

			return '' !== $message
				? $message
				: __( 'The custom FedEx shipper address is incomplete.', 'lovecatz-wc' );
		}

		return '';
	}

	/**
	 * Build the FedEx `address` object from the custom shipper address.
	 *
	 * @return array|WP_Error FedEx-formatted address, or WP_Error when invalid.
	 */
	public static function build_fedex_address() {
		$address = self::validate();
		if ( is_wp_error( $address ) ) {
			return $address;
		}

		$street = array_filter(
			array(
				substr( $address['address_1'], 0, self::FIELDS['address_1']['max_length'] ),
				substr( $address['address_2'], 0, self::FIELDS['address_2']['max_length'] ),
			)
		);

		return array(
			'streetLines'         => array_values( $street ),
			'city'                => substr( $address['city'], 0, self::FIELDS['city']['max_length'] ),
			'stateOrProvinceCode' => substr( $address['state'], 0, self::FIELDS['state']['max_length'] ),
			'postalCode'          => substr( $address['postcode'], 0, self::FIELDS['postcode']['max_length'] ),
			'countryCode'         => strtoupper( substr( $address['country'], 0, 2 ) ),
		);
	}

	/**
	 * Build the FedEx `contact` object from the custom shipper address.
	 *
	 * @return array|WP_Error FedEx-formatted contact, or WP_Error when invalid.
	 */
	public static function build_fedex_contact() {
		$address = self::validate();
		if ( is_wp_error( $address ) ) {
			return $address;
		}

		$contact = array(
			'personName'  => substr( $address['name'], 0, self::FIELDS['name']['max_length'] ),
			'phoneNumber' => substr( $address['phone'], 0, self::FIELDS['phone']['max_length'] ),
		);

		if ( '' !== $address['company'] ) {
			$contact['companyName'] = substr( $address['company'], 0, self::FIELDS['company']['max_length'] );
		}

		return $contact;
	}

	/**
	 * Whether the custom shipper address should be used for this request.
	 *
	 * True only when the toggle is on AND the stored address validates, so a
	 * half-filled address never silently breaks live shipping.
	 *
	 * @return bool
	 */
	public static function should_use_custom() {
		if ( ! self::is_enabled() ) {
			return false;
		}

		return self::is_complete();
	}

	/**
	 * The WooCommerce store country code.
	 *
	 * @return string
	 */
	public static function get_store_country() {
		if ( class_exists( 'WC_Countries' ) && WC()->countries ) {
			return (string) WC()->countries->get_base_country();
		}

		$country = (string) get_option( 'woocommerce_default_country', '' );

		return strtoupper( substr( $country, 0, 2 ) );
	}

	/**
	 * Build the default (WooCommerce store) FedEx `address` object.
	 *
	 * Mirrors the historical behaviour of LWC_FedEx_API so turning the custom
	 * toggle off restores the exact payload the plugin always produced.
	 *
	 * @return array
	 */
	public static function build_store_address() {
		$country = '';
		$state   = '';

		if ( class_exists( 'WC_Countries' ) && WC()->countries ) {
			$country = (string) WC()->countries->get_base_country();
			$state   = (string) WC()->countries->get_base_state();
		}

		if ( '' === $country ) {
			$country = self::get_store_country();
		}

		$street = array_values(
			array_filter(
				array(
					(string) get_option( 'woocommerce_store_address', '' ),
					(string) get_option( 'woocommerce_store_address_2', '' ),
				)
			)
		);

		return array(
			'streetLines'         => $street,
			'city'                => (string) get_option( 'woocommerce_store_city', '' ),
			'stateOrProvinceCode' => $state,
			'postalCode'          => (string) get_option( 'woocommerce_store_postcode', '' ),
			'countryCode'         => $country,
		);
	}

	/**
	 * Build the default (plugin settings) FedEx `contact` object.
	 *
	 * @return array
	 */
	public static function build_store_contact() {
		return array(
			'personName'  => (string) get_option( 'lwc_fedex_shipper_name', get_bloginfo( 'name' ) ),
			'phoneNumber' => (string) get_option( 'lwc_fedex_shipper_phone', '' ),
		);
	}

	/**
	 * Sanitize one submitted address part.
	 *
	 * Country is normalised to uppercase ISO-3166 alpha-2; postcode and phone
	 * keep the characters FedEx accepts; free-text parts are stripped of tags.
	 *
	 * @param string $part Address part key.
	 * @param mixed  $value Raw submitted value.
	 * @return string
	 */
	public static function sanitize_part( $part, $value ) {
		$value = is_string( $value ) ? $value : '';
		$value = wp_strip_all_tags( $value );
		$value = trim( preg_replace( '/\s+/', ' ', $value ) );

		if ( 'country' === $part ) {
			$value = strtoupper( preg_replace( '/[^A-Za-z]/', '', $value ) );

			return substr( $value, 0, 2 );
		}

		if ( 'phone' === $part ) {
			// FedEx rejects separators other than digits, "+", spaces and "-".
			$value = preg_replace( '/[^0-9+\s\-()]/', '', $value );

			return substr( trim( $value ), 0, self::FIELDS['phone']['max_length'] );
		}

		if ( 'postcode' === $part ) {
			$value = preg_replace( '/[^A-Za-z0-9\s\-]/', '', $value );

			return substr( trim( $value ), 0, self::FIELDS['postcode']['max_length'] );
		}

		$max_length = isset( self::FIELDS[ $part ]['max_length'] ) ? self::FIELDS[ $part ]['max_length'] : 255;

		return substr( $value, 0, $max_length );
	}
}
