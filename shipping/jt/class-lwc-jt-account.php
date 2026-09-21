<?php
/** J&T Express account storage helper. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** This class belongs exclusively to J&T Express. */
class LWC_JT_Account {
	public static function create_table() {
		global $wpdb;
		$table_name = self::get_table_name();
		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			api_key varchar(255) NOT NULL DEFAULT '',
			api_secret varchar(255) NOT NULL DEFAULT '',
			test_mode varchar(10) NOT NULL DEFAULT 'no',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (id)
		) " . $wpdb->get_charset_collate() . ';';
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		self::sync_from_options();
	}

	public static function save_account( $data ) {
		global $wpdb;
		$payload = array(
			'api_key' => isset( $data['api_key'] ) ? self::encrypt_credential( sanitize_text_field( wp_unslash( $data['api_key'] ) ) ) : '',
			'api_secret' => isset( $data['api_secret'] ) ? self::encrypt_credential( sanitize_text_field( wp_unslash( $data['api_secret'] ) ) ) : '',
			'test_mode' => isset( $data['test_mode'] ) ? sanitize_text_field( wp_unslash( $data['test_mode'] ) ) : 'no',
			'updated_at' => current_time( 'mysql' ),
		);
		$row = self::get_account();
		if ( $row ) {
			$wpdb->update( self::get_table_name(), $payload, array( 'id' => (int) $row['id'] ), array( '%s', '%s', '%s', '%s' ), array( '%d' ) );
		} else {
			$wpdb->insert( self::get_table_name(), $payload, array( '%s', '%s', '%s', '%s' ) );
		}
		update_option( 'lwc_jt_express_api_key', $payload['api_key'] );
		update_option( 'lwc_jt_express_api_secret', $payload['api_secret'] );
		update_option( 'lwc_jt_express_test_mode', $payload['test_mode'] );
		return true;
	}

	public static function get_account() {
		global $wpdb;
		$row = $wpdb->get_row( 'SELECT * FROM ' . self::get_table_name() . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
		return is_array( $row ) ? $row : array();
	}

	public static function get_active_credentials() {
		$legacy = 'yes' === get_option( 'lwc_jt_express_test_mode', 'no' ) ? 'sandbox' : 'production';
		$environment = 'production' === get_option( 'lwc_jt_express_environment', $legacy ) ? 'production' : 'sandbox';
		return self::get_credentials( $environment );
	}

	public static function get_credentials( $environment = 'sandbox' ) {
		$environment = 'production' === $environment ? 'production' : 'sandbox';
		$prefix = "lwc_jt_express_{$environment}";
		$credentials = array( 'provider' => 'express', 'environment' => $environment );
		$fields = array( 'order_username', 'order_api_key', 'order_key', 'tariff_customer_name', 'tariff_check_key', 'tracking_password', 'tracking_company_id', 'print_key', 'print_company_id', 'cancel_key', 'cancel_username', 'cancel_api_key' );
		foreach ( $fields as $field ) {
			$credentials[ $field ] = get_option( "{$prefix}_{$field}", '' );
		}
		return $credentials;
	}

	public static function set_service_status( $environment, $service, $status, $message, $credentials, $fields ) {
		$environment = 'production' === $environment ? 'production' : 'sandbox';
		update_option( "lwc_jt_express_{$environment}_service_" . sanitize_key( $service ), array(
			'status' => 'connected' === $status ? 'connected' : 'partial',
			'message' => sanitize_text_field( $message ),
			'fingerprint' => self::credential_fingerprint( $credentials, $fields ),
			'checked_at' => time(),
		), false );
	}

	public static function get_service_status( $environment, $service, $credentials, $fields ) {
		$environment = 'production' === $environment ? 'production' : 'sandbox';
		$record = get_option( "lwc_jt_express_{$environment}_service_" . sanitize_key( $service ), array() );
		if ( ! is_array( $record ) || empty( $record['fingerprint'] ) || ! hash_equals( $record['fingerprint'], self::credential_fingerprint( $credentials, $fields ) ) ) {
			return array();
		}
		return $record;
	}

	private static function credential_fingerprint( $credentials, $fields ) {
		$values = array();
		foreach ( $fields as $field ) {
			$values[] = isset( $credentials[ $field ] ) ? (string) $credentials[ $field ] : '';
		}
		return hash_hmac( 'sha256', implode( "\0", $values ), wp_salt( 'auth' ) );
	}

	public static function sync_from_options() {
		$data = array( 'api_key' => get_option( 'lwc_jt_express_api_key', '' ), 'api_secret' => get_option( 'lwc_jt_express_api_secret', '' ), 'test_mode' => get_option( 'lwc_jt_express_test_mode', 'no' ) );
		if ( '' !== $data['api_key'] || '' !== $data['api_secret'] || 'no' !== $data['test_mode'] ) {
			self::save_account( $data );
		}
	}

	public static function migrate_legacy_credentials() {
		if ( get_option( 'lwc_jt_legacy_migrated' ) ) {
			return;
		}
		$legacy_key = get_option( 'lwc_jt_api_key', '' );
		$legacy_secret = get_option( 'lwc_jt_api_secret', '' );
		if ( ( '' !== $legacy_key || '' !== $legacy_secret ) && '' === get_option( 'lwc_jt_express_api_key', '' ) && '' === get_option( 'lwc_jt_express_api_secret', '' ) ) {
			update_option( 'lwc_jt_express_api_key', $legacy_key );
			update_option( 'lwc_jt_express_api_secret', $legacy_secret );
			update_option( 'lwc_jt_express_test_mode', get_option( 'lwc_jt_test_mode', 'no' ) );
		}
		update_option( 'lwc_jt_legacy_migrated', 'yes' );
	}

	private static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'lwc_jt_express_accounts';
	}

	private static function encrypt_credential( $value ) {
		return '' !== $value && function_exists( 'lwc_encrypt_secret' ) ? lwc_encrypt_secret( $value ) : $value;
	}
}
