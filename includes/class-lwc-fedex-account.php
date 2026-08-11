<?php
/**
 * FedEx account storage helper.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_FedEx_Account {

	/**
	 * Create the FedEx account storage table.
	 */
	public static function create_table() {
		global $wpdb;

		$table_name = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			account_number varchar(255) NOT NULL DEFAULT '',
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

	/**
	 * Save FedEx account values into the custom table.
	 *
	 * @param array $data Account data.
	 * @return bool
	 */
	public static function save_account( $data ) {
		global $wpdb;

		$table_name = self::get_table_name();
		$payload = array(
			'account_number' => isset( $data['account_number'] ) ? sanitize_text_field( wp_unslash( $data['account_number'] ) ) : '',
			'api_key'        => isset( $data['api_key'] ) ? sanitize_text_field( wp_unslash( $data['api_key'] ) ) : '',
			'api_secret'     => isset( $data['api_secret'] ) ? sanitize_text_field( wp_unslash( $data['api_secret'] ) ) : '',
			'test_mode'      => isset( $data['test_mode'] ) ? sanitize_text_field( wp_unslash( $data['test_mode'] ) ) : 'no',
			'updated_at'     => current_time( 'mysql' ),
		);

		$row = self::get_account();
		if ( $row ) {
			$wpdb->update(
				$table_name,
				$payload,
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert( $table_name, $payload, array( '%s', '%s', '%s', '%s', '%s' ) );
		}

		update_option( 'lwc_fedex_account_number', $payload['account_number'] );
		update_option( 'lwc_fedex_api_key', $payload['api_key'] );
		update_option( 'lwc_fedex_api_secret', $payload['api_secret'] );
		update_option( 'lwc_fedex_test_mode', $payload['test_mode'] );

		return true;
	}

	/**
	 * Retrieve the stored FedEx account data.
	 *
	 * @return array
	 */
	public static function get_account() {
		global $wpdb;

		$table_name = self::get_table_name();
		$row = $wpdb->get_row( "SELECT * FROM {$table_name} ORDER BY id DESC LIMIT 1", ARRAY_A );

		return is_array( $row ) ? $row : array();
	}

	/**
	 * Retrieve a single FedEx setting value from the database or fall back to options.
	 *
	 * @param string $option_name Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get_option_value( $option_name, $default = '' ) {
		$account = self::get_account();
		$field_map = array(
			'lwc_fedex_account_number' => 'account_number',
			'lwc_fedex_api_key'        => 'api_key',
			'lwc_fedex_api_secret'     => 'api_secret',
			'lwc_fedex_test_mode'      => 'test_mode',
		);

		if ( isset( $field_map[ $option_name ] ) && isset( $account[ $field_map[ $option_name ] ] ) ) {
			return $account[ $field_map[ $option_name ] ];
		}

		return get_option( $option_name, $default );
	}

	/**
	 * Sync existing WordPress options into the custom table.
	 */
	public static function sync_from_options() {
		$data = array(
			'account_number' => get_option( 'lwc_fedex_account_number', '' ),
			'api_key'        => get_option( 'lwc_fedex_api_key', '' ),
			'api_secret'     => get_option( 'lwc_fedex_api_secret', '' ),
			'test_mode'      => get_option( 'lwc_fedex_test_mode', 'no' ),
		);

		if ( '' !== $data['account_number'] || '' !== $data['api_key'] || '' !== $data['api_secret'] || 'no' !== $data['test_mode'] ) {
			self::save_account( $data );
		}
	}

	/**
	 * Get the full table name including the WordPress prefix.
	 *
	 * @return string
	 */
	private static function get_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'lwc_fedex_accounts';
	}
}
