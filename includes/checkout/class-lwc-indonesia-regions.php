<?php
/**
 * J&T-approved Indonesian address directory for WooCommerce.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keep customer-facing WooCommerce names and J&T-only route values together. */
class LWC_Indonesia_Regions {

	const SOURCE_VERSION = 'TEMPLATE MAPPING L3 - J&T (received 2026-09-02)';
	const SNAPSHOT_FILE   = 'data/indonesia-regions-jt-2026-09.csv';
	const CRON_HOOK       = 'lwc_indonesia_regions_weekly_sync';
	const FIELD_ID        = 'lwc/indonesia-district';
	const SCHEMA_VERSION  = '4';

	/** Register address, persistence, and lookup hooks. */
	public static function init() {
		// Manual file updates may reach checkout before admin_init runs the normal installer.
		if ( self::SCHEMA_VERSION !== (string) get_option( 'lwc_indonesia_regions_schema_version', '0' ) ) {
			self::install();
		}
		add_filter( 'woocommerce_states', array( __CLASS__, 'use_jt_province_labels' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_address_assets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_address_assets' ) );
		add_action( 'woocommerce_init', array( __CLASS__, 'register_block_checkout_field' ) );
		add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'add_billing_address_field' ), 20 );
		add_filter( 'woocommerce_shipping_fields', array( __CLASS__, 'add_shipping_address_field' ), 20 );
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'add_classic_checkout_fields' ), 20 );
		add_filter( 'woocommerce_customer_meta_fields', array( __CLASS__, 'add_admin_customer_fields' ) );
		add_filter( 'woocommerce_address_to_edit', array( __CLASS__, 'prepare_account_address_fields' ), 20, 2 );
		add_action( 'woocommerce_after_save_address_validation', array( __CLASS__, 'validate_account_address' ), 20, 4 );
		add_action( 'woocommerce_customer_save_address', array( __CLASS__, 'persist_customer_address' ), 20, 2 );
		add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'inject_shipping_package_district' ), 20 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_classic_checkout' ), 5, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'persist_classic_checkout_regions' ), 5, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'persist_block_checkout_regions' ), 5 );
		// Version 4 is J&T-owned and must never be overwritten by the old BIG cron.
		self::unschedule_sync();
	}

	/** Return the shared human/J&T mapping table name. */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'lwc_indonesia_regions';
	}

	/** Create/upgrade the local directory and reseed derived rows when required. */
	public static function install() {
		global $wpdb;
		if ( 'region-v4' !== (string) get_option( 'lwc_jt_area_map_schema_version', '' ) ) {
			$legacy_table = $wpdb->prefix . 'lwc_jt_area_map';
			$wpdb->query( "DROP TABLE IF EXISTS {$legacy_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			update_option( 'lwc_jt_area_map_schema_version', 'region-v4', false );
			delete_option( 'lwc_jt_area_mapping_meta' );
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table          = self::table_name();
		$schema_version = (string) get_option( 'lwc_indonesia_regions_schema_version', '0' );
		$sql            = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			province_name varchar(100) NOT NULL,
			wc_state_code varchar(4) NOT NULL,
			city_name varchar(120) NOT NULL,
			city_key varchar(120) NOT NULL,
			district_name varchar(120) NOT NULL,
			district_key varchar(120) NOT NULL,
			jt_province_name varchar(100) NOT NULL,
			jt_city_name varchar(120) NOT NULL,
			jt_city_code varchar(3) NOT NULL,
			jt_district_name varchar(120) NOT NULL,
			jt_area_code varchar(10) NOT NULL,
			PRIMARY KEY  (id),
			KEY state_city (wc_state_code,city_key),
			UNIQUE KEY address_lookup (wc_state_code,city_key,district_key)
		) {$wpdb->get_charset_collate()};";

		// This table is a reproducible cache. Rebuild it to prevent mixed BIG/J&T rows.
		if ( version_compare( $schema_version, self::SCHEMA_VERSION, '<' ) ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		dbDelta( $sql );
		update_option( 'lwc_indonesia_regions_schema_version', self::SCHEMA_VERSION );

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 7128 !== $count ) {
			$result = self::import_snapshot();
			if ( is_wp_error( $result ) && class_exists( 'LWC_Logger' ) ) {
				LWC_Logger::log( 'J&T Indonesia region snapshot import failed: ' . $result->get_error_message(), 'error' );
			}
		}
	}

	/** Import the bundled snapshot generated from the J&T workbook. */
	private static function import_snapshot() {
		$path = LWC_PLUGIN_DIR . self::SNAPSHOT_FILE;
		return self::import_csv(
			$path,
			array(
				'source'      => 'J&T Indonesia TEMPLATE MAPPING L3',
				'version'     => self::SOURCE_VERSION,
				'synced_at'   => gmdate( 'c' ),
				'checksum'    => is_readable( $path ) ? hash_file( 'sha256', $path ) : '',
				'is_snapshot' => true,
			)
		);
	}

	/** Import a J&T-confirmed CSV and atomically replace the shared directory. */
	private static function import_csv( $path, $meta = array() ) {
		if ( ! is_readable( $path ) || ! is_file( $path ) ) {
			return new WP_Error( 'lwc_regions_snapshot_missing', __( 'The J&T Indonesia region mapping could not be read.', 'lovecatz-wc' ) );
		}
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return new WP_Error( 'lwc_regions_snapshot_unreadable', __( 'The J&T Indonesia region mapping could not be opened.', 'lovecatz-wc' ) );
		}
		$first = fgets( $handle );
		if ( false === $first ) {
			fclose( $handle );
			return new WP_Error( 'lwc_regions_snapshot_empty', __( 'The J&T Indonesia region mapping is empty.', 'lovecatz-wc' ) );
		}
		$delimiter = self::detect_delimiter( $first );
		rewind( $handle );
		$headers = fgetcsv( $handle, 0, $delimiter );
		$headers = is_array( $headers ) ? array_map( array( __CLASS__, 'normalize_header' ), $headers ) : array();
		$required = array( 'province_name', 'city_name', 'district_name', 'jt_province_name', 'jt_city_name', 'jt_city_code', 'jt_district_name', 'jt_area_code' );
		foreach ( $required as $column ) {
			if ( ! in_array( $column, $headers, true ) ) {
				fclose( $handle );
				return new WP_Error( 'lwc_regions_snapshot_header', sprintf( __( 'The J&T mapping is missing the %s column.', 'lovecatz-wc' ), $column ) );
			}
		}
		$rows = array();
		while ( false !== ( $values = fgetcsv( $handle, 0, $delimiter ) ) ) {
			if ( 1 === count( $values ) && '' === trim( (string) $values[0] ) ) {
				continue;
			}
			$values = array_pad( $values, count( $headers ), '' );
			$rows[] = array_combine( $headers, array_slice( $values, 0, count( $headers ) ) );
		}
		fclose( $handle );

		$meta = wp_parse_args(
			(array) $meta,
			array(
				'source'      => 'J&T-confirmed CSV',
				'version'     => self::SOURCE_VERSION,
				'synced_at'   => gmdate( 'c' ),
				'checksum'    => hash_file( 'sha256', $path ),
				'is_snapshot' => false,
			)
		);
		return self::replace_rows( $rows, $meta );
	}

	/** Validate and atomically replace every mapping row. */
	private static function replace_rows( $rows, $meta ) {
		global $wpdb;
		$validated = self::validate_rows( $rows );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$table = self::table_name();
		$wpdb->query( 'START TRANSACTION' );
		if ( false === $wpdb->query( "DELETE FROM {$table}" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'lwc_regions_database_error', __( 'Could not clear the previous J&T region directory.', 'lovecatz-wc' ) );
		}
		foreach ( array_chunk( $validated, 200 ) as $chunk ) {
			$placeholders = array();
			$values       = array();
			foreach ( $chunk as $row ) {
				$placeholders[] = '(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)';
				$values[] = $row['province_name'];
				$values[] = $row['wc_state_code'];
				$values[] = $row['city_name'];
				$values[] = self::normalize_name( $row['city_name'] );
				$values[] = $row['district_name'];
				$values[] = self::normalize_name( $row['district_name'] );
				$values[] = $row['jt_province_name'];
				$values[] = $row['jt_city_name'];
				$values[] = $row['jt_city_code'];
				$values[] = $row['jt_district_name'];
				$values[] = $row['jt_area_code'];
			}
			$sql = "INSERT INTO {$table} (province_name,wc_state_code,city_name,city_key,district_name,district_key,jt_province_name,jt_city_name,jt_city_code,jt_district_name,jt_area_code) VALUES " . implode( ',', $placeholders ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false === $wpdb->query( $wpdb->prepare( $sql, $values ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'lwc_regions_database_error', __( 'Could not store the J&T region directory; the previous data was restored.', 'lovecatz-wc' ) );
			}
		}
		$wpdb->query( 'COMMIT' );
		$meta['row_count']      = count( $validated );
		$meta['province_count'] = 34;
		$meta['city_count']     = 514;
		update_option( 'lwc_indonesia_regions_meta', $meta, false );
		return $meta;
	}

	/** Enforce the exact, complete J&T mapping contract. */
	private static function validate_rows( $rows ) {
		$clean = array(); $districts = array(); $provinces = array(); $cities = array();
		$province_map = self::province_state_map();
		foreach ( (array) $rows as $row ) {
			$row = array_map( 'trim', (array) $row );
			$province_key = self::normalize_name( isset( $row['province_name'] ) ? $row['province_name'] : '' );
			if ( ! isset( $province_map[ $province_key ] ) ) {
				return new WP_Error( 'lwc_regions_invalid_province', __( 'The J&T mapping contains an unsupported province.', 'lovecatz-wc' ) );
			}
			$row['wc_state_code'] = isset( $row['wc_state_code'] ) && '' !== $row['wc_state_code'] ? strtoupper( $row['wc_state_code'] ) : $province_map[ $province_key ];
			if ( $province_map[ $province_key ] !== $row['wc_state_code'] ) {
				return new WP_Error( 'lwc_regions_invalid_state', __( 'A province is mapped to the wrong native WooCommerce state code.', 'lovecatz-wc' ) );
			}
			foreach ( array( 'province_name', 'city_name', 'district_name', 'jt_province_name', 'jt_city_name', 'jt_city_code', 'jt_district_name', 'jt_area_code' ) as $field ) {
				if ( empty( $row[ $field ] ) ) {
					return new WP_Error( 'lwc_regions_invalid_row', sprintf( __( 'The J&T mapping contains an empty %s value.', 'lovecatz-wc' ), $field ) );
				}
			}
			$row['jt_city_code'] = strtoupper( $row['jt_city_code'] );
			$row['jt_area_code'] = strtoupper( $row['jt_area_code'] );
			if ( 1 !== preg_match( '/^[A-Z0-9]{3}$/', $row['jt_city_code'] ) || 1 !== preg_match( '/^[A-Z0-9]{1,10}$/', $row['jt_area_code'] ) ) {
				return new WP_Error( 'lwc_regions_invalid_jt_code', __( 'The J&T mapping contains an invalid city or receiver-area code.', 'lovecatz-wc' ) );
			}
			$city_key = $row['wc_state_code'] . '|' . self::normalize_name( $row['city_name'] );
			$district_key = $city_key . '|' . self::normalize_name( $row['district_name'] );
			if ( isset( $districts[ $district_key ] ) ) {
				return new WP_Error( 'lwc_regions_duplicate_district', __( 'A province/city/district combination appears more than once in the J&T mapping.', 'lovecatz-wc' ) );
			}
			$districts[ $district_key ] = true; $provinces[ $province_key ] = true; $cities[ $city_key ] = true; $clean[] = $row;
		}
		if ( 7128 !== count( $clean ) || 34 !== count( $provinces ) || 514 !== count( $cities ) ) {
			return new WP_Error( 'lwc_regions_incomplete', __( 'The J&T mapping must contain exactly 34 provinces, 514 cities/regencies, and 7,128 districts.', 'lovecatz-wc' ) );
		}
		return $clean;
	}

	/** Register read-only dependent-dropdown endpoints. */
	public static function register_rest_routes() {
		register_rest_route( 'lwc/v1', '/indonesia-regions/cities', array(
			'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'rest_cities' ), 'permission_callback' => '__return_true',
			'args' => array( 'state' => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ) ),
		) );
		register_rest_route( 'lwc/v1', '/indonesia-regions/districts', array(
			'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'rest_districts' ), 'permission_callback' => '__return_true',
			'args' => array( 'state' => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ), 'city' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ) ),
		) );
	}

	/** Return green-column cities only; J&T internals never reach the browser. */
	public static function rest_cities( WP_REST_Request $request ) {
		global $wpdb;
		$state = strtoupper( sanitize_key( $request->get_param( 'state' ) ) );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT city_name FROM ' . self::table_name() . ' WHERE wc_state_code = %s GROUP BY city_name ORDER BY city_name ASC', $state ), ARRAY_A );
		return rest_ensure_response( $rows );
	}

	/** Return green-column districts only; J&T internals remain server-side. */
	public static function rest_districts( WP_REST_Request $request ) {
		global $wpdb;
		$state = strtoupper( sanitize_key( $request->get_param( 'state' ) ) );
		$city = self::normalize_name( sanitize_text_field( $request->get_param( 'city' ) ) );
		if ( '' === $state || '' === $city ) {
			return new WP_Error( 'lwc_regions_invalid_city', __( 'Invalid city.', 'lovecatz-wc' ), array( 'status' => 400 ) );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT district_name FROM ' . self::table_name() . ' WHERE wc_state_code = %s AND city_key = %s ORDER BY district_name ASC', $state, $city ), ARRAY_A );
		return rest_ensure_response( $rows );
	}

	/** Use green-column province labels while preserving native Woo codes. */
	public static function use_jt_province_labels( $states ) {
		if ( is_array( $states ) ) { $states['ID'] = self::province_labels(); }
		return $states;
	}

	/** Load dependent dropdowns on checkout and My Account address forms. */
	public static function enqueue_address_assets() {
		$is_checkout = function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page();
		$is_account = function_exists( 'is_account_page' ) && is_account_page() && function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'edit-address' );
		if ( $is_checkout || $is_account ) { self::enqueue_assets(); }
	}

	/** Load the same controls on WooCommerce customer profile screens. */
	public static function enqueue_admin_address_assets( $hook ) {
		if ( in_array( $hook, array( 'profile.php', 'user-edit.php' ), true ) && current_user_can( 'manage_woocommerce' ) ) { self::enqueue_assets(); }
	}

	/** Enqueue localized address controls once. */
	private static function enqueue_assets() {
		$labels = self::address_labels();
		wp_enqueue_style( 'lwc-indonesia-regions', LWC_PLUGIN_URL . 'assets/css/indonesia-regions.css', array(), LWC_VERSION );
		wp_enqueue_script( 'lwc-indonesia-regions', LWC_PLUGIN_URL . 'assets/js/indonesia-regions.js', array(), LWC_VERSION, true );
		wp_localize_script( 'lwc-indonesia-regions', 'lwcIndonesiaRegions', array(
			'citiesUrl' => rest_url( 'lwc/v1/indonesia-regions/cities' ), 'districtsUrl' => rest_url( 'lwc/v1/indonesia-regions/districts' ),
			'cityLabel' => $labels['city'], 'cityPlaceholder' => $labels['city_choose'], 'stateFirst' => $labels['province_first'],
			'districtLabel' => $labels['district'], 'districtFirst' => $labels['city_first'], 'districtChoose' => $labels['district_choose'],
			'cityReview' => $labels['city_review'], 'districtReview' => $labels['district_review'], 'loadError' => $labels['load_error'],
		) );
	}

	/** Register Checkout Block persistence for the human district name. */
	public static function register_block_checkout_field() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) { return; }
		$labels = self::address_labels();
		woocommerce_register_additional_checkout_field( array(
			'id' => self::FIELD_ID, 'label' => $labels['district'], 'location' => 'address', 'type' => 'text', 'required' => false,
			'show_in_order_confirmation' => false, 'attributes' => array( 'data-lwc-indonesia-district' => '1', 'autocomplete' => 'address-level3' ),
			'sanitize_callback' => 'sanitize_text_field',
		) );
	}

	public static function add_billing_address_field( $fields ) { $fields['billing_lwc_indonesia_district'] = self::district_field_definition(); return $fields; }
	public static function add_shipping_address_field( $fields ) { $fields['shipping_lwc_indonesia_district'] = self::district_field_definition(); return $fields; }

	/** Ensure both classic checkout groups contain the district field. */
	public static function add_classic_checkout_fields( $fields ) {
		foreach ( array( 'billing', 'shipping' ) as $group ) {
			if ( isset( $fields[ $group ] ) ) { $fields[ $group ][ $group . '_lwc_indonesia_district' ] = self::district_field_definition(); }
		}
		return $fields;
	}

	/** Add district storage to WooCommerce's wp-admin customer profile fields. */
	public static function add_admin_customer_fields( $fieldsets ) {
		$labels = self::address_labels();
		foreach ( array( 'billing', 'shipping' ) as $group ) {
			if ( isset( $fieldsets[ $group ]['fields'] ) ) {
				$fieldsets[ $group ]['fields'][ $group . '_lwc_indonesia_district' ] = array( 'label' => $labels['district'], 'description' => '', 'class' => 'regular-text lwc-indonesia-district-source' );
			}
		}
		return $fieldsets;
	}

	/** Populate the custom district when editing a saved My Account address. */
	public static function prepare_account_address_fields( $fields, $address_type ) {
		$key = $address_type . '_lwc_indonesia_district';
		if ( isset( $fields[ $key ] ) ) { $fields[ $key ]['value'] = get_user_meta( get_current_user_id(), $key, true ); }
		return $fields;
	}

	/** Validate Indonesian My Account addresses before WooCommerce saves them. */
	public static function validate_account_address( $user_id, $address_type, $address, $customer ) {
		unset( $user_id, $customer );
		$country = isset( $address[ $address_type . '_country' ] ) ? $address[ $address_type . '_country' ] : '';
		$state = isset( $address[ $address_type . '_state' ] ) ? $address[ $address_type . '_state' ] : '';
		$city = isset( $address[ $address_type . '_city' ] ) ? $address[ $address_type . '_city' ] : '';
		$postcode = isset( $address[ $address_type . '_postcode' ] ) ? $address[ $address_type . '_postcode' ] : '';
		$district = self::posted_district( $address_type, $address );
		if ( 'ID' === $country && ( ! self::is_valid_indonesian_postcode( $postcode ) || ! self::find_region( $state, $city, $district ) ) ) {
			wc_add_notice( __( 'Select a valid Indonesian province, city, district, and five-digit postal code.', 'lovecatz-wc' ), 'error' );
		}
	}

	/** Persist the human district on My Account and wp-admin customer profiles. */
	public static function persist_customer_address( $user_id, $address_type ) {
		if ( ! in_array( $address_type, array( 'billing', 'shipping' ), true ) ) { return; }
		$district = self::posted_district( $address_type );
		if ( '' !== $district ) { update_user_meta( $user_id, $address_type . '_lwc_indonesia_district', $district ); }
	}

	/** Validate classic checkout hierarchy and Indonesian postcodes. */
	public static function validate_classic_checkout( $data, $errors ) {
		$ship_to_different = ! empty( $data['ship_to_different_address'] );
		foreach ( array( 'billing', 'shipping' ) as $group ) {
			if ( 'shipping' === $group && ! $ship_to_different ) { continue; }
			$country = isset( $data[ $group . '_country' ] ) ? $data[ $group . '_country' ] : '';
			if ( 'ID' !== $country ) { continue; }
			$district = self::posted_district( $group, $data );
			if ( ! self::find_region( $data[ $group . '_state' ], $data[ $group . '_city' ], $district ) ) {
				$errors->add( 'lwc_indonesia_' . $group . '_region', __( 'Select the Indonesian city and district from the J&T-approved list.', 'lovecatz-wc' ) );
			}
			if ( ! self::is_valid_indonesian_postcode( isset( $data[ $group . '_postcode' ] ) ? $data[ $group . '_postcode' ] : '' ) ) {
				$errors->add( 'lwc_indonesia_' . $group . '_postcode', __( 'Indonesian postal codes must contain exactly five digits.', 'lovecatz-wc' ) );
			}
		}
	}

	/** Persist classic checkout district and resolved mapping snapshots. */
	public static function persist_classic_checkout_regions( $order, $data ) {
		unset( $data );
		if ( ! $order instanceof WC_Order ) { return; }
		foreach ( array( 'shipping', 'billing' ) as $group ) { $order->update_meta_data( '_wc_' . $group . '/' . self::FIELD_ID, self::posted_district( $group ) ); }
		self::persist_order_regions( $order, true );
	}

	public static function persist_block_checkout_regions( $order ) { if ( $order instanceof WC_Order ) { self::persist_order_regions( $order, true ); } }

	/** Resolve order addresses and store stable human plus J&T technical metadata. */
	private static function persist_order_regions( $order, $throw_on_invalid ) {
		$resolved = array();
		foreach ( array( 'shipping', 'billing' ) as $group ) {
			$country = 'shipping' === $group ? $order->get_shipping_country() : $order->get_billing_country();
			if ( 'ID' !== $country ) { continue; }
			$state = 'shipping' === $group ? $order->get_shipping_state() : $order->get_billing_state();
			$city = 'shipping' === $group ? $order->get_shipping_city() : $order->get_billing_city();
			$postcode = 'shipping' === $group ? $order->get_shipping_postcode() : $order->get_billing_postcode();
			$district = sanitize_text_field( (string) $order->get_meta( '_wc_' . $group . '/' . self::FIELD_ID, true ) );
			if ( '' === $district ) { $district = self::region_cookie_district( $state, $city, $group ); }
			if ( '' === $district && 'billing' === $group && isset( $resolved['shipping'] ) && $state === $order->get_shipping_state() && self::normalize_name( $city ) === self::normalize_name( $order->get_shipping_city() ) ) {
				$district = $resolved['shipping']['district_name']; $order->update_meta_data( '_wc_billing/' . self::FIELD_ID, $district );
			}
			$region = self::find_region( $state, $city, $district );
			if ( ! $region || ! self::is_valid_indonesian_postcode( $postcode ) ) {
				if ( $throw_on_invalid ) { throw new Exception( esc_html__( 'Select a valid Indonesian city, district, and five-digit postal code.', 'lovecatz-wc' ) ); }
				continue;
			}
			$resolved[ $group ] = $region;
			foreach ( array( 'province_name', 'wc_state_code', 'city_name', 'district_name', 'jt_province_name', 'jt_city_name', 'jt_city_code', 'jt_district_name', 'jt_area_code' ) as $key ) {
				$order->update_meta_data( '_lwc_' . $group . '_' . $key, $region[ $key ] );
			}
			if ( $order->get_customer_id() ) { update_user_meta( $order->get_customer_id(), $group . '_lwc_indonesia_district', $region['district_name'] ); }
		}
	}

	/** Find one exact green A+B+C hierarchy and return its J&T mapping. */
	public static function find_region( $state, $city, $district ) {
		global $wpdb;
		$state = strtoupper( sanitize_key( $state ) ); $city = self::normalize_name( $city ); $district = self::normalize_name( $district );
		if ( '' === $state || '' === $city || '' === $district ) { return false; }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE wc_state_code = %s AND city_key = %s AND district_key = %s LIMIT 1', $state, $city, $district ), ARRAY_A );
		return $row ? $row : false;
	}

	/** Return all districts for one green city, with safe prefix fallback for store settings. */
	public static function find_city_regions( $state, $city ) {
		global $wpdb;
		$state = strtoupper( sanitize_key( $state ) ); $city = self::normalize_name( $city );
		if ( '' === $state || '' === $city ) { return array(); }
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE wc_state_code = %s AND city_key = %s ORDER BY district_name ASC', $state, $city ), ARRAY_A );
		if ( ! empty( $rows ) ) { return $rows; }
		$loose = self::without_city_prefix( $city );
		$all = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE wc_state_code = %s ORDER BY city_name,district_name', $state ), ARRAY_A );
		$matched = array();
		foreach ( $all as $row ) { if ( self::without_city_prefix( $row['city_name'] ) === $loose ) { $matched[ $row['city_name'] ][] = $row; } }
		return 1 === count( $matched ) ? reset( $matched ) : array();
	}

	public static function count_rows() { global $wpdb; return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table_name() ); }

	/** Put the selected district into the package hash so Woo recalculates district-specific J&T rates. */
	public static function inject_shipping_package_district( $packages ) {
		foreach ( (array) $packages as $index => $package ) {
			if ( empty( $package['destination'] ) || ! is_array( $package['destination'] ) ) { continue; }
			$state = isset( $package['destination']['state'] ) ? $package['destination']['state'] : '';
			$city = isset( $package['destination']['city'] ) ? $package['destination']['city'] : '';
			$packages[ $index ]['destination']['lwc_indonesia_district'] = self::region_cookie_district( $state, $city, 'shipping' );
		}
		return $packages;
	}

	/** Read a trusted green district from the short-lived checkout cookie. */
	public static function region_cookie_district( $state, $city, $preferred_group = 'shipping' ) {
		$groups = 'billing' === $preferred_group ? array( 'billing', 'shipping' ) : array( 'shipping', 'billing' );
		foreach ( $groups as $group ) {
			$name = 'lwc_' . $group . '_region';
			if ( empty( $_COOKIE[ $name ] ) ) { continue; }
			$decoded = json_decode( rawurldecode( wp_unslash( $_COOKIE[ $name ] ) ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( is_array( $decoded ) && self::normalize_name( $state ) === self::normalize_name( isset( $decoded['state'] ) ? $decoded['state'] : '' ) && self::normalize_name( $city ) === self::normalize_name( isset( $decoded['city'] ) ? $decoded['city'] : '' ) ) {
				$district = sanitize_text_field( isset( $decoded['district'] ) ? $decoded['district'] : '' );
				if ( self::find_region( $state, $city, $district ) ) { return $district; }
			}
		}
		return '';
	}

	private static function is_valid_indonesian_postcode( $postcode ) { return 1 === preg_match( '/^\d{5}$/', trim( (string) $postcode ) ); }

	private static function posted_district( $group, $address = array() ) {
		$key = $group . '_lwc_indonesia_district';
		if ( isset( $address[ $key ] ) ) { return sanitize_text_field( $address[ $key ] ); }
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	private static function district_field_definition() {
		$labels = self::address_labels();
		return array( 'type' => 'text', 'label' => $labels['district'], 'required' => false, 'priority' => 75, 'class' => array( 'form-row-wide', 'lwc-indonesia-district-field' ), 'custom_attributes' => array( 'data-lwc-indonesia-district' => '1', 'autocomplete' => 'address-level3' ) );
	}

	/** Translate control labels to the active form locale. */
	private static function address_labels() {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		if ( 0 === strpos( strtolower( $locale ), 'id' ) ) {
			return array(
				'city' => 'Kabupaten/Kota', 'district' => 'Kecamatan', 'city_choose' => 'Pilih kabupaten/kota', 'province_first' => 'Pilih provinsi terlebih dahulu',
				'city_first' => 'Pilih kabupaten/kota terlebih dahulu', 'district_choose' => 'Pilih kecamatan',
				'city_review' => 'Kabupaten/kota pada alamat tersimpan tidak cocok atau ambigu. Pilih kembali dari daftar J&T.',
				'district_review' => 'Kecamatan pada alamat tersimpan tidak cocok. Pilih kembali dari daftar J&T.', 'load_error' => 'Data wilayah tidak dapat dimuat. Muat ulang halaman dan coba lagi.',
			);
		}
		return array(
			'city' => 'City', 'district' => 'District', 'city_choose' => 'Select a city', 'province_first' => 'Select a province first', 'city_first' => 'Select a city first',
			'district_choose' => 'Select a district', 'city_review' => 'The city in the saved address does not match or is ambiguous. Select it again from the J&T list.',
			'district_review' => 'The district in the saved address does not match. Select it again from the J&T list.', 'load_error' => 'Region data could not be loaded. Reload the page and try again.',
		);
	}

	public static function normalize_name( $value ) {
		$value = trim( wp_strip_all_tags( (string) $value ) ); $value = remove_accents( $value );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value ); $value = preg_replace( '/[^a-z0-9]+/', ' ', $value );
		return trim( preg_replace( '/\s+/', ' ', $value ) );
	}

	private static function without_city_prefix( $value ) { return trim( preg_replace( '/^(kabupaten|kab|kota)\s+/', '', self::normalize_name( $value ) ) ); }

	private static function normalize_header( $value ) {
		$value = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $value ); $value = strtolower( trim( $value ) ); $value = trim( preg_replace( '/[^a-z0-9]+/', '_', $value ), '_' );
		$aliases = array( 'provinsi' => 'province_name', 'kota' => 'city_name', 'kecamatan' => 'district_name', 'provinsi_jnt' => 'jt_province_name', 'kota_jnt' => 'jt_city_name', 'kode_kota_jnt_origin_destination_code' => 'jt_city_code', 'kecamatan_jnt' => 'jt_district_name', 'kode_jnt_receiver_area' => 'jt_area_code' );
		return isset( $aliases[ $value ] ) ? $aliases[ $value ] : $value;
	}

	private static function detect_delimiter( $line ) { $counts = array( ',' => substr_count( $line, ',' ), ';' => substr_count( $line, ';' ), "\t" => substr_count( $line, "\t" ) ); arsort( $counts ); return (string) key( $counts ); }

	private static function province_state_map() {
		return array(
			'aceh' => 'AC', 'sumatera utara' => 'SU', 'sumatera barat' => 'SB', 'riau' => 'RI', 'kepulauan riau' => 'KR', 'jambi' => 'JA', 'sumatera selatan' => 'SS',
			'kepulauan bangka belitung' => 'BB', 'bengkulu' => 'BE', 'lampung' => 'LA', 'dki jakarta' => 'JK', 'jawa barat' => 'JB', 'banten' => 'BT', 'jawa tengah' => 'JT',
			'jawa timur' => 'JI', 'diy' => 'YO', 'bali' => 'BA', 'nusa tenggara barat' => 'NB', 'nusa tenggara timur' => 'NT', 'kalimantan barat' => 'KB',
			'kalimantan tengah' => 'KT', 'kalimantan timur' => 'KI', 'kalimantan selatan' => 'KS', 'kalimantan utara' => 'KU', 'sulawesi utara' => 'SA',
			'sulawesi tengah' => 'ST', 'sulawesi tenggara' => 'SG', 'sulawesi barat' => 'SR', 'sulawesi selatan' => 'SN', 'gorontalo' => 'GO', 'maluku' => 'MA',
			'maluku utara' => 'MU', 'papua' => 'PA', 'papua barat' => 'PB',
		);
	}

	private static function province_labels() {
		return array(
			'AC' => 'ACEH', 'SU' => 'SUMATERA UTARA', 'SB' => 'SUMATERA BARAT', 'RI' => 'RIAU', 'KR' => 'KEPULAUAN RIAU', 'JA' => 'JAMBI', 'SS' => 'SUMATERA SELATAN',
			'BB' => 'KEPULAUAN BANGKA BELITUNG', 'BE' => 'BENGKULU', 'LA' => 'LAMPUNG', 'JK' => 'DKI JAKARTA', 'JB' => 'JAWA BARAT', 'BT' => 'BANTEN', 'JT' => 'JAWA TENGAH',
			'JI' => 'JAWA TIMUR', 'YO' => 'DIY', 'BA' => 'BALI', 'NB' => 'NUSA TENGGARA BARAT', 'NT' => 'NUSA TENGGARA TIMUR', 'KB' => 'KALIMANTAN BARAT',
			'KT' => 'KALIMANTAN TENGAH', 'KI' => 'KALIMANTAN TIMUR', 'KS' => 'KALIMANTAN SELATAN', 'KU' => 'KALIMANTAN UTARA', 'SA' => 'SULAWESI UTARA',
			'ST' => 'SULAWESI TENGAH', 'SG' => 'SULAWESI TENGGARA', 'SR' => 'SULAWESI BARAT', 'SN' => 'SULAWESI SELATAN', 'GO' => 'GORONTALO', 'MA' => 'MALUKU',
			'MU' => 'MALUKU UTARA', 'PA' => 'PAPUA', 'PB' => 'PAPUA BARAT',
		);
	}

	public static function unschedule_sync() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		while ( $timestamp ) { wp_unschedule_event( $timestamp, self::CRON_HOOK ); $timestamp = wp_next_scheduled( self::CRON_HOOK ); }
	}

}
