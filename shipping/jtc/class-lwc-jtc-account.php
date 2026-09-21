<?php
/**
 * J&T Cargo account storage and environment credentials.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Independent account model for the J&T Cargo provider. */
class LWC_JTC_Account {

	/** Create the dedicated Cargo legacy account table. */
	public static function create_table() {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			api_key varchar(255) NOT NULL DEFAULT '',
			api_secret varchar(255) NOT NULL DEFAULT '',
			test_mode varchar(10) NOT NULL DEFAULT 'no',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		self::sync_from_options();
	}

	/** Save legacy Cargo account fields without involving J&T Express. */
	public static function save_account( $data ) {
		global $wpdb;

		$payload = array(
			'api_key'    => isset( $data['api_key'] ) ? self::encrypt_credential( sanitize_text_field( wp_unslash( $data['api_key'] ) ) ) : '',
			'api_secret' => isset( $data['api_secret'] ) ? self::encrypt_credential( sanitize_text_field( wp_unslash( $data['api_secret'] ) ) ) : '',
			'test_mode'  => isset( $data['test_mode'] ) ? sanitize_text_field( wp_unslash( $data['test_mode'] ) ) : 'no',
			'updated_at' => current_time( 'mysql' ),
		);
		$row = self::get_account();
		if ( $row ) {
			$wpdb->update( self::get_table_name(), $payload, array( 'id' => (int) $row['id'] ), array( '%s', '%s', '%s', '%s' ), array( '%d' ) );
		} else {
			$wpdb->insert( self::get_table_name(), $payload, array( '%s', '%s', '%s', '%s' ) );
		}

		update_option( 'lwc_jt_cargo_api_key', $payload['api_key'] );
		update_option( 'lwc_jt_cargo_api_secret', $payload['api_secret'] );
		update_option( 'lwc_jt_cargo_test_mode', $payload['test_mode'] );
		return true;
	}

	/** Return the latest Cargo legacy account row. */
	public static function get_account() {
		global $wpdb;
		$row = $wpdb->get_row( 'SELECT * FROM ' . self::get_table_name() . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
		return is_array( $row ) ? $row : array();
	}

	/** Return the credential set for the selected Cargo environment. */
	public static function get_active_credentials() {
		$legacy      = 'yes' === get_option( 'lwc_jt_cargo_test_mode', 'no' ) ? 'sandbox' : 'production';
		$environment = 'production' === get_option( 'lwc_jt_cargo_environment', $legacy ) ? 'production' : 'sandbox';
		return self::get_credentials( $environment );
	}

	/** Return one independent Cargo environment credential set. */
	public static function get_credentials( $environment = 'sandbox' ) {
		$environment = 'production' === $environment ? 'production' : 'sandbox';
		$prefix      = "lwc_jt_cargo_{$environment}";
		$credentials = array(
			'provider'    => 'cargo',
			'environment' => $environment,
		);
		foreach ( array( 'customer_code', 'uuid', 'api_account', 'private_key', 'customer_password' ) as $field ) {
			$default = ( 'sandbox' === $environment && 'uuid' === $field && class_exists( 'LWC_JTC_API' ) ) ? LWC_JTC_API::SANDBOX_DEFAULT_UUID : '';
			$credentials[ $field ] = get_option( "{$prefix}_{$field}", $default );
		}

		// Preserve values saved by the first internal Cargo settings draft.
		if ( '' === (string) $credentials['customer_code'] ) {
			$credentials['customer_code'] = get_option( "{$prefix}_username", '' );
		}
		if ( '' === (string) $credentials['api_account'] ) {
			$credentials['api_account'] = get_option( "{$prefix}_api_key", '' );
		}
		if ( '' === (string) $credentials['private_key'] ) {
			$credentials['private_key'] = get_option( "{$prefix}_api_secret", '' );
		}
		return $credentials;
	}

	/** Return local three-hit test progress bound to the current Sandbox UUID. */
	public static function get_sandbox_test_progress() {
		$credentials = self::get_credentials( 'sandbox' );
		$fingerprint = hash( 'sha256', strtolower( (string) $credentials['uuid'] ) );
		$progress    = get_option( 'lwc_jtc_sandbox_test_progress', array() );
		if ( ! is_array( $progress ) || ! isset( $progress['uuid_fingerprint'] ) || ! hash_equals( $fingerprint, (string) $progress['uuid_fingerprint'] ) ) {
			return array( 'uuid_fingerprint' => $fingerprint, 'interfaces' => array() );
		}
		$progress['interfaces'] = isset( $progress['interfaces'] ) && is_array( $progress['interfaces'] ) ? $progress['interfaces'] : array();
		return $progress;
	}

	/** Record an actual Sandbox attempt and cap successful HTTP hits at three. */
	public static function record_sandbox_test_result( $interface, $http_status = 0, $business_success = null, $business_code = '' ) {
		$interface = sanitize_key( $interface );
		$progress  = self::get_sandbox_test_progress();
		$item      = isset( $progress['interfaces'][ $interface ] ) && is_array( $progress['interfaces'][ $interface ] ) ? $progress['interfaces'][ $interface ] : array();
		$attempts  = isset( $item['attempts'] ) ? absint( $item['attempts'] ) : 0;
		$successes = isset( $item['successes'] ) ? absint( $item['successes'] ) : 0;
		$http_status = absint( $http_status );
		$is_success = null === $business_success ? ( $http_status >= 200 && $http_status < 300 ) : (bool) $business_success;
		$progress['interfaces'][ $interface ] = array(
			'attempts' => $attempts + 1,
			'successes' => $is_success ? min( 3, $successes + 1 ) : min( 3, $successes ),
			'last_http' => $http_status,
			'last_business_code' => sanitize_text_field( (string) $business_code ),
			'last_tested_at' => current_time( 'mysql' ),
		);
		update_option( 'lwc_jtc_sandbox_test_progress', $progress, false );
		return $progress;
	}

	/** Copy old Cargo options into its dedicated legacy table when present. */
	public static function sync_from_options() {
		$data = array(
			'api_key'    => get_option( 'lwc_jt_cargo_api_key', '' ),
			'api_secret' => get_option( 'lwc_jt_cargo_api_secret', '' ),
			'test_mode'  => get_option( 'lwc_jt_cargo_test_mode', 'no' ),
		);
		if ( '' !== $data['api_key'] || '' !== $data['api_secret'] || 'no' !== $data['test_mode'] ) {
			self::save_account( $data );
		}
	}

	/** Dedicated Cargo table name. */
	private static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'lwc_jt_cargo_accounts';
	}

	private static function encrypt_credential( $value ) {
		return '' !== $value && function_exists( 'lwc_encrypt_secret' ) ? lwc_encrypt_secret( $value ) : $value;
	}
}
