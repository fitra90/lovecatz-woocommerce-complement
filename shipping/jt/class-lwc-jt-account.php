<?php
/**
 * J&T account storage helper (per provider: express, cargo).
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_JT_Account {

	/**
	 * Supported J&T providers.
	 *
	 * @return string[]
	 */
	public static function get_providers() {
		return array( 'express', 'cargo' );
	}

	/**
	 * Normalize a provider slug.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	public static function normalize_provider( $provider ) {
		return in_array( (string) $provider, self::get_providers(), true ) ? (string) $provider : 'express';
	}

	public static function create_table( $provider = 'express' ) {
		global $wpdb;

		$provider = self::normalize_provider( $provider );
		$table_name = self::get_table_name( $provider );
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

		self::sync_from_options( $provider );
	}

	public static function drop_table( $provider = 'express' ) {
		global $wpdb;

		$provider = self::normalize_provider( $provider );
		$table_name = self::get_table_name( $provider );
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
	}

	public static function save_account( $data, $provider = 'express' ) {
		global $wpdb;

		$provider = self::normalize_provider( $provider );
		$table_name = self::get_table_name( $provider );
		$payload = array(
			'api_key'    => isset( $data['api_key'] ) ? sanitize_text_field( wp_unslash( $data['api_key'] ) ) : '',
			'api_secret' => isset( $data['api_secret'] ) ? sanitize_text_field( wp_unslash( $data['api_secret'] ) ) : '',
			'test_mode'  => isset( $data['test_mode'] ) ? sanitize_text_field( wp_unslash( $data['test_mode'] ) ) : 'no',
			'updated_at' => current_time( 'mysql' ),
		);

		$row = self::get_account( $provider );
		if ( $row ) {
			$wpdb->update(
				$table_name,
				$payload,
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert( $table_name, $payload, array( '%s', '%s', '%s', '%s' ) );
		}

		update_option( "lwc_jt_{$provider}_api_key", $payload['api_key'] );
		update_option( "lwc_jt_{$provider}_api_secret", $payload['api_secret'] );
		update_option( "lwc_jt_{$provider}_test_mode", $payload['test_mode'] );

		return true;
	}

	public static function get_account( $provider = 'express' ) {
		global $wpdb;

		$provider = self::normalize_provider( $provider );
		$table_name = self::get_table_name( $provider );
		$row = $wpdb->get_row( "SELECT * FROM {$table_name} ORDER BY id DESC LIMIT 1", ARRAY_A );

		return is_array( $row ) ? $row : array();
	}

	public static function get_option_value( $option_name, $default = '', $provider = 'express' ) {
		$provider = self::normalize_provider( $provider );
		$credential_fields = array(
			"lwc_jt_{$provider}_api_key" => 'api_key',
		);
		if ( isset( $credential_fields[ $option_name ] ) ) {
			$legacy      = 'yes' === get_option( "lwc_jt_{$provider}_test_mode", 'no' ) ? 'sandbox' : 'production';
			$environment = 'production' === get_option( "lwc_jt_{$provider}_environment", $legacy ) ? 'production' : 'sandbox';
			$value       = get_option( "lwc_jt_{$provider}_{$environment}_{$credential_fields[ $option_name ]}", null );
			if ( null !== $value ) {
				return $value;
			}
		}
		if ( "lwc_jt_{$provider}_test_mode" === $option_name && null !== get_option( "lwc_jt_{$provider}_environment", null ) ) {
			return 'sandbox' === get_option( "lwc_jt_{$provider}_environment" ) ? 'yes' : 'no';
		}
		$account = self::get_account( $provider );
		$field_map = array(
			"lwc_jt_{$provider}_api_key"    => 'api_key',
			"lwc_jt_{$provider}_api_secret" => 'api_secret',
			"lwc_jt_{$provider}_test_mode"  => 'test_mode',
		);

		if ( isset( $field_map[ $option_name ] ) && isset( $account[ $field_map[ $option_name ] ] ) ) {
			return $account[ $field_map[ $option_name ] ];
		}

		return get_option( $option_name, $default );
	}

	/** Return the active credential set for a provider. */
	public static function get_active_credentials( $provider = 'express' ) {
		$provider    = self::normalize_provider( $provider );
		$legacy      = 'yes' === get_option( "lwc_jt_{$provider}_test_mode", 'no' ) ? 'sandbox' : 'production';
		$environment = 'production' === get_option( "lwc_jt_{$provider}_environment", $legacy ) ? 'production' : 'sandbox';
		return self::get_credentials( $provider, $environment );
	}

	/** Return one provider/environment credential set. */
	public static function get_credentials( $provider = 'express', $environment = 'sandbox' ) {
		$provider    = self::normalize_provider( $provider );
		$environment = 'production' === $environment ? 'production' : 'sandbox';
		$prefix      = "lwc_jt_{$provider}_{$environment}";

		$credentials = array(
			'provider'    => $provider,
			'environment' => $environment,
		);
		$fields = 'express' === $provider
			? array( 'order_username', 'order_api_key', 'order_key', 'tariff_customer_name', 'tariff_check_key', 'tracking_password', 'tracking_company_id', 'cancel_username', 'cancel_api_key', 'cancel_key' )
			: array( 'username', 'api_key', 'api_secret' );
		foreach ( $fields as $field ) {
			$credentials[ $field ] = get_option( "{$prefix}_{$field}", '' );
		}

		return $credentials;
	}

	public static function sync_from_options( $provider = 'express' ) {
		$provider = self::normalize_provider( $provider );
		$data = array(
			'api_key'    => get_option( "lwc_jt_{$provider}_api_key", '' ),
			'api_secret' => get_option( "lwc_jt_{$provider}_api_secret", '' ),
			'test_mode'  => get_option( "lwc_jt_{$provider}_test_mode", 'no' ),
		);

		if ( '' !== $data['api_key'] || '' !== $data['api_secret'] || 'no' !== $data['test_mode'] ) {
			self::save_account( $data, $provider );
		}
	}

	/**
	 * Copy pre-split legacy J&T credentials to the Express provider once.
	 *
	 * @return void
	 */
	public static function migrate_legacy_credentials() {
		if ( get_option( 'lwc_jt_legacy_migrated' ) ) {
			return;
		}

		$legacy_key = get_option( 'lwc_jt_api_key', '' );
		$legacy_secret = get_option( 'lwc_jt_api_secret', '' );

		if ( '' !== $legacy_key || '' !== $legacy_secret ) {
			if ( '' === get_option( 'lwc_jt_express_api_key', '' ) && '' === get_option( 'lwc_jt_express_api_secret', '' ) ) {
				update_option( 'lwc_jt_express_api_key', $legacy_key );
				update_option( 'lwc_jt_express_api_secret', $legacy_secret );
				update_option( 'lwc_jt_express_test_mode', get_option( 'lwc_jt_test_mode', 'no' ) );
			}
		}

		update_option( 'lwc_jt_legacy_migrated', 'yes' );
	}

	private static function get_table_name( $provider ) {
		global $wpdb;

		$provider = self::normalize_provider( $provider );
		return $wpdb->prefix . 'lwc_jt_' . $provider . '_accounts';
	}
}
