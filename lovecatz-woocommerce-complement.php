<?php
/**
 * Plugin Name: LoveCatz WooCommerce Complement
 * Plugin URI:  https://github.com/fitra90/lovecatz-woocommerce-complement
 * Description: A comprehensive complement for WooCommerce including currency conversion and courier integrations (starting with J&T Express).
 * Version:     1.0.33
 * Author:      Fitra Fadilana
 * Author URI:  https://fitrafadilana.my.id
 * Text Domain: lovecatz-wc
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define plugin constants.
define( 'LWC_VERSION', '1.0.33' );
define( 'LWC_PLUGIN_FILE', __FILE__ );
define( 'LWC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LWC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Encrypt a shipping credential before it is stored in the database.
 *
 * A key derived from the site secret is used so the value cannot be read from
 * a plain database dump. Values that are already encrypted or empty pass through.
 *
 * @param mixed $value Raw credential value.
 * @return string
 */
function lwc_encrypt_secret( $value ) {
	if ( ! is_string( $value ) || '' === $value || ! function_exists( 'openssl_encrypt' ) ) {
		return $value;
	}

	if ( 0 === strpos( $value, 'lwc1:' ) || 0 === strpos( $value, 'lwc2:' ) ) {
		return $value;
	}

	$key = hash( 'sha256', wp_salt( 'auth' ), true );
	if ( function_exists( 'random_bytes' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
		try {
			$iv     = random_bytes( 12 );
			$tag    = '';
			$cipher = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'lovecatz-wc' );
			if ( false !== $cipher && 16 === strlen( $tag ) ) {
				return 'lwc2:' . base64_encode( $iv . $tag . $cipher );
			}
		} catch ( Exception $exception ) {
			// Fall through to the backwards-compatible cipher below.
		}
	}

	$iv     = substr( hash( 'sha256', $key ), 0, 16 );
	$cipher = openssl_encrypt( $value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

	return ( false === $cipher ) ? $value : 'lwc1:' . base64_encode( $cipher );
}

/**
 * Decrypt a shipping credential that was stored with lwc_encrypt_secret().
 *
 * Plain values are returned unchanged, which keeps existing installs working.
 *
 * @param mixed $value Stored credential value.
 * @return string
 */
function lwc_decrypt_secret( $value ) {
	if ( ! is_string( $value ) || ! function_exists( 'openssl_decrypt' ) ) {
		return $value;
	}

	$key = hash( 'sha256', wp_salt( 'auth' ), true );
	if ( 0 === strpos( $value, 'lwc2:' ) ) {
		$raw = base64_decode( substr( $value, 5 ), true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return $value;
		}
		$iv     = substr( $raw, 0, 12 );
		$tag    = substr( $raw, 12, 16 );
		$cipher = substr( $raw, 28 );
		$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'lovecatz-wc' );

		return ( false === $plain ) ? $value : $plain;
	}

	if ( 0 !== strpos( $value, 'lwc1:' ) ) {
		return $value;
	}

	$iv     = substr( hash( 'sha256', $key ), 0, 16 );
	$raw    = base64_decode( substr( $value, 5 ), true );
	$plain  = ( false === $raw ) ? false : openssl_decrypt( $raw, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

	return ( false === $plain ) ? $value : $plain;
}

/**
 * Check if WooCommerce is active.
 * We need WC active to run this plugin.
 */
function lwc_is_woocommerce_active() {
	return in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true ) ||
		( is_multisite() && array_key_exists( 'woocommerce/woocommerce.php', get_site_option( 'active_sitewide_plugins' ) ) );
}

/**
 * Initialize the plugin.
 */
function lwc_init() {
	if ( ! lwc_is_woocommerce_active() ) {
		add_action( 'admin_notices', 'lwc_woocommerce_missing_notice' );
		return;
	}

	load_plugin_textdomain( 'lovecatz-wc', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// Decrypt stored shipping credentials transparently on read.
	add_filter( 'option_lwc_fedex_api_key', 'lwc_decrypt_secret' );
	add_filter( 'option_lwc_fedex_api_secret', 'lwc_decrypt_secret' );
	foreach ( array( 'sandbox', 'production' ) as $lwc_fedex_environment ) {
		add_filter( "option_lwc_fedex_{$lwc_fedex_environment}_api_key", 'lwc_decrypt_secret' );
		add_filter( "option_lwc_fedex_{$lwc_fedex_environment}_api_secret", 'lwc_decrypt_secret' );
	}
	add_filter( 'option_lwc_jt_api_key', 'lwc_decrypt_secret' );
	add_filter( 'option_lwc_jt_api_secret', 'lwc_decrypt_secret' );
	foreach ( array( 'express', 'cargo' ) as $lwc_jt_provider ) {
		add_filter( "option_lwc_jt_{$lwc_jt_provider}_api_key", 'lwc_decrypt_secret' );
		add_filter( "option_lwc_jt_{$lwc_jt_provider}_api_secret", 'lwc_decrypt_secret' );
		foreach ( array( 'sandbox', 'production' ) as $lwc_jt_environment ) {
			add_filter( "option_lwc_jt_{$lwc_jt_provider}_{$lwc_jt_environment}_api_key", 'lwc_decrypt_secret' );
			foreach ( array( 'order_key', 'order_api_key', 'tariff_check_key', 'tracking_password', 'cancel_key', 'cancel_api_key', 'api_secret' ) as $lwc_jt_secret_field ) {
				add_filter( "option_lwc_jt_{$lwc_jt_provider}_{$lwc_jt_environment}_{$lwc_jt_secret_field}", 'lwc_decrypt_secret' );
			}
		}
	}
	add_filter( 'option_lwc_rayspeed_api_key', 'lwc_decrypt_secret' );

	// Include core plugin classes.
	require_once LWC_PLUGIN_DIR . 'includes/core/class-lwc-logger.php';
	require_once LWC_PLUGIN_DIR . 'includes/admin/class-lwc-admin-settings.php';
	require_once LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-fedex-account.php';
	require_once LWC_PLUGIN_DIR . 'shipping/jt/class-lwc-jt-account.php';
	require_once LWC_PLUGIN_DIR . 'shipping/jt/class-lwc-jt-express-api.php';
	require_once LWC_PLUGIN_DIR . 'shipping/jt/class-lwc-jt-route-mapper.php';
	require_once LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-fedex-api.php';
	require_once LWC_PLUGIN_DIR . 'shipping/class-lwc-shipping-provider.php';
	require_once LWC_PLUGIN_DIR . 'shipping/jt/class-lwc-shipping-jt-base.php';
	require_once LWC_PLUGIN_DIR . 'shipping/jt/class-lwc-shipping-jt-express.php';
	require_once LWC_PLUGIN_DIR . 'shipping/jt/class-lwc-shipping-jt-cargo.php';
	require_once LWC_PLUGIN_DIR . 'shipping/jt/class-lwc-jt-order-admin.php';
	require_once LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-shipping-fedex.php';
	require_once LWC_PLUGIN_DIR . 'shipping/rayspeed/class-lwc-rayspeed-api.php';
	require_once LWC_PLUGIN_DIR . 'shipping/rayspeed/class-lwc-shipping-rayspeed.php';
	require_once LWC_PLUGIN_DIR . 'shipping/rayspeed/class-lwc-rayspeed-order-admin.php';
	require_once LWC_PLUGIN_DIR . 'includes/core/class-lwc-core.php';

	// Carry pre-split J&T credentials into the Express provider once.
	LWC_JT_Account::migrate_legacy_credentials();

	add_filter( 'woocommerce_shipping_methods', 'lwc_register_shipping_methods' );

	// Run the core class.
	$core = new LWC_Core();
	$core->init();
}
add_action( 'plugins_loaded', 'lwc_init', 20 );
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'lwc_plugin_action_links' );
add_action( 'wp_ajax_lwc_check_fedex_connection', 'lwc_check_fedex_connection' );
add_action( 'wp_ajax_lwc_check_jt_connection', 'lwc_check_jt_connection' );
add_action( 'wp_ajax_lwc_fedex_get_rate_quote', 'lwc_fedex_get_rate_quote' );
add_action( 'wp_ajax_lwc_fedex_create_shipment', 'lwc_fedex_create_shipment' );
add_action( 'wp_ajax_lwc_fedex_download_label', 'lwc_fedex_download_label' );
add_action( 'wp_ajax_lwc_check_rayspeed_connection', 'lwc_check_rayspeed_connection' );
add_action( 'wp_ajax_lwc_fedex_checkout_debug', 'lwc_fedex_checkout_debug_response' );
add_action( 'wp_ajax_lwc_fedex_checkout_debug_quote', 'lwc_fedex_checkout_debug_quote' );
add_action( 'wp_enqueue_scripts', 'lwc_enqueue_fedex_checkout_debug' );
add_action( 'wp_enqueue_scripts', 'lwc_enqueue_shipping_accordion' );
add_action( 'woocommerce_after_checkout_validation', 'lwc_validate_jt_checkout_contact', 10, 2 );
add_action( 'woocommerce_store_api_checkout_update_order_meta', 'lwc_validate_jt_store_api_order' );
add_filter( 'woocommerce_hidden_order_itemmeta', 'lwc_hide_shipping_technical_meta' );
register_activation_hook( __FILE__, 'lwc_activate' );
register_deactivation_hook( __FILE__, 'lwc_deactivate' );
register_uninstall_hook( __FILE__, 'lwc_uninstall' );
add_action( 'init', 'lwc_maybe_flush_rewrite_rules' );

/**
 * Store a credential-free diagnostic event in the current checkout session.
 *
 * @param string $stage   Short diagnostic stage.
 * @param array  $context Safe fields only; never credentials or tokens.
 */
function lwc_fedex_checkout_debug_log( $stage, $context = array() ) {
	if ( ! function_exists( 'WC' ) || ! WC()->session || 'yes' !== WC()->session->get( 'lwc_fedex_debug_enabled' ) ) {
		return;
	}

	$events   = (array) WC()->session->get( 'lwc_fedex_debug_events', array() );
	$events[] = array(
		'id'      => wp_generate_uuid4(),
		'time'    => gmdate( 'H:i:s' ),
		'stage'   => sanitize_key( $stage ),
		'context' => map_deep( (array) $context, 'sanitize_text_field' ),
	);

	WC()->session->set( 'lwc_fedex_debug_events', array_slice( $events, -60 ) );
}

/**
 * Load the opt-in checkout debug client. It activates only when the checkout
 * URL contains ?lwc_fedex_debug=1.
 */
function lwc_enqueue_fedex_checkout_debug() {
	$debug_requested = isset( $_GET['lwc_fedex_debug'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['lwc_fedex_debug'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! $debug_requested || ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
		return;
	}

	wp_enqueue_script( 'lwc-fedex-checkout-debug', LWC_PLUGIN_URL . 'shipping/fedex/fedex-checkout-debug.js', array(), LWC_VERSION, true );
	wp_localize_script(
		'lwc-fedex-checkout-debug',
		'lwcFedexCheckoutDebug',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'lwc_fedex_checkout_debug' ),
		)
	);
}

/**
 * Enable/poll diagnostics for the current WooCommerce session.
 */
function lwc_fedex_checkout_debug_response() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
	}
	check_ajax_referer( 'lwc_fedex_checkout_debug', 'nonce' );

	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		wp_send_json_error( array( 'message' => 'WooCommerce session is unavailable.' ) );
	}

	if ( isset( $_POST['enable'] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['enable'] ) ) ) {
		WC()->session->set( 'lwc_fedex_debug_enabled', 'yes' );
	}
	if ( isset( $_POST['clear'] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['clear'] ) ) ) {
		WC()->session->set( 'lwc_fedex_debug_events', array() );
	}

	wp_send_json_success( array( 'events' => (array) WC()->session->get( 'lwc_fedex_debug_events', array() ) ) );
}

/**
 * Run an explicit, user-triggered FedEx quote from the checkout debug panel.
 */
function lwc_fedex_checkout_debug_quote() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
	}
	check_ajax_referer( 'lwc_fedex_checkout_debug', 'nonce' );

	if ( ! function_exists( 'WC' ) ) {
		wp_send_json_error( array( 'message' => 'WooCommerce is unavailable.' ) );
	}

	if ( function_exists( 'wc_load_cart' ) && ( ! WC()->cart || ! WC()->session ) ) {
		wc_load_cart();
	}

	$country  = isset( $_POST['country'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['country'] ) ) ) : '';
	$state    = isset( $_POST['state'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['state'] ) ) ) : '';
	$city     = isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '';
	$postcode = isset( $_POST['postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['postcode'] ) ) : '';

	if ( ! preg_match( '/^[A-Z]{2}$/', $country ) || '' === $postcode ) {
		wp_send_json_error( array( 'message' => 'Country and postcode are required for the direct test.' ) );
	}

	if ( WC()->session ) {
		WC()->session->set( 'lwc_fedex_debug_enabled', 'yes' );
		WC()->session->set( 'lwc_fedex_debug_events', array() );
	}

	$package = array(
		'destination' => array(
			'country'  => $country,
			'state'    => $state,
			'city'     => $city,
			'postcode' => $postcode,
		),
		'contents' => WC()->cart ? WC()->cart->get_cart() : array(),
	);

	lwc_fedex_checkout_debug_log( 'direct_test_started', array( 'country' => $country, 'state' => $state, 'city' => $city, 'postcode' => $postcode ) );
	$result = ( new LWC_FedEx_API() )->get_rate_quotes( $package, (float) get_option( 'lwc_fedex_max_package_weight_kg', 10 ) );

	if ( empty( $result['success'] ) ) {
		wp_send_json_error(
			array(
				'message' => isset( $result['message'] ) ? $result['message'] : 'FedEx did not return a rate.',
				'events'  => WC()->session ? (array) WC()->session->get( 'lwc_fedex_debug_events', array() ) : array(),
			)
		);
	}

	$quotes = array();
	foreach ( (array) $result['quotes'] as $quote ) {
		$quotes[] = array(
			'service' => isset( $quote['service_type'] ) ? $quote['service_type'] : '',
			'label'   => isset( $quote['label'] ) ? $quote['label'] : '',
			'rate'    => isset( $quote['rate'] ) ? $quote['rate'] : '',
			'currency' => isset( $quote['currency'] ) ? $quote['currency'] : '',
		);
	}

	wp_send_json_success(
		array(
			'message' => sprintf( 'FedEx returned %d quote(s).', count( $quotes ) ),
			'quotes'  => $quotes,
			'events'  => WC()->session ? (array) WC()->session->get( 'lwc_fedex_debug_events', array() ) : array(),
		)
	);
}

/**
 * Flush rewrite rules whenever the coupon endpoint rule is missing.
 *
 * The coupon endpoint is only persisted when flush_rewrite_rules() runs after
 * add_rewrite_endpoint() has been called. Theme or permalink changes can
 * regenerate the rules without this plugin's endpoint, so a single
 * activation-time flush is not enough. Compare the stored plugin version and
 * the actual saved rules, and re-flush when stale.
 */
function lwc_maybe_flush_rewrite_rules() {
	if ( ! function_exists( 'add_rewrite_endpoint' ) || ! function_exists( 'flush_rewrite_rules' ) ) {
		return;
	}

	add_rewrite_endpoint( 'coupon', EP_ROOT | EP_PAGES );

	$version = get_option( 'lwc_coupon_endpoint_rewrite_version', '' );
	$rules   = get_option( 'rewrite_rules' );

	if ( LWC_VERSION === $version && lwc_rewrite_rules_have_coupon_endpoint( $rules ) ) {
		return;
	}

	flush_rewrite_rules();
	update_option( 'lwc_coupon_endpoint_rewrite_version', LWC_VERSION );
}

/**
 * Check whether the saved rewrite rules contain the coupon endpoint.
 *
 * @param mixed $rules Stored rewrite rules.
 * @return bool
 */
function lwc_rewrite_rules_have_coupon_endpoint( $rules ) {
	if ( ! is_array( $rules ) ) {
		return false;
	}

	foreach ( $rules as $regex => $query ) {
		if ( false !== strpos( (string) $regex, 'coupon' ) || false !== strpos( (string) $query, 'coupon=' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Add a Settings link on the Plugins page.
 *
 * @param array $links Existing plugin action links.
 * @return array
 */
function lwc_plugin_action_links( $links ) {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'admin.php?page=lovecatz-wc' ) ),
		esc_html__( 'Settings', 'lovecatz-wc' )
	);

	array_unshift( $links, $settings_link );

	return $links;
}

/**
 * Handle the FedEx connection status check via AJAX.
 *
 * Performs a real OAuth handshake against FedEx using the submitted
 * credentials so the reported status reflects an actual working connection.
 */
function lwc_check_fedex_connection() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'lovecatz-wc' ) ) );
	}

	check_ajax_referer( 'lwc_fedex_connection_check', 'nonce' );

	$account_number = isset( $_POST['account_number'] ) ? sanitize_text_field( wp_unslash( $_POST['account_number'] ) ) : '';
	$api_key        = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
	$api_secret     = isset( $_POST['api_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['api_secret'] ) ) : '';
	$environment    = isset( $_POST['environment'] ) && 'production' === sanitize_key( wp_unslash( $_POST['environment'] ) ) ? 'production' : 'sandbox';
	$test_mode      = 'sandbox' === $environment ? 'yes' : 'no';

	if ( $account_number && $api_key && $api_secret ) {
		if ( ! class_exists( 'LWC_FedEx_API' ) ) {
			require_once LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-fedex-api.php';
		}

		$api = new LWC_FedEx_API(
			array(
				'account_number' => $account_number,
				'api_key'        => $api_key,
				'api_secret'     => $api_secret,
				'test_mode'      => $test_mode,
			)
		);

		$result = $api->test_connection();

		if ( ! empty( $result['success'] ) ) {
			update_option( 'lwc_fedex_validation_status_' . $environment, 'validated' );
			wp_send_json_success(
				array(
					'status' => 'connected',
					'label'  => __( 'Connected (REST API ready)', 'lovecatz-wc' ),
				)
			);
		}

		update_option( 'lwc_fedex_validation_status_' . $environment, 'failed' );
		wp_send_json_success(
			array(
				'status' => 'auth_failed',
				'label'  => isset( $result['message'] ) ? $result['message'] : __( 'FedEx rejected the credentials.', 'lovecatz-wc' ),
			)
		);
	}

	if ( $account_number || $api_key || $api_secret ) {
		update_option( 'lwc_fedex_validation_status_' . $environment, 'pending' );
		wp_send_json_success(
			array(
				'status' => 'partial',
				'label'  => __( 'Incomplete credentials', 'lovecatz-wc' ),
			)
		);
	}

	update_option( 'lwc_fedex_validation_status_' . $environment, 'pending' );
	wp_send_json_success(
		array(
			'status' => 'idle',
			'label'  => __( 'Waiting for credentials', 'lovecatz-wc' ),
		)
	);
}

/**
 * Fetch a FedEx shipping rate quote via AJAX.
 */
function lwc_fedex_get_rate_quote() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'lovecatz-wc' ) ) );
	}

	check_ajax_referer( 'lwc_fedex_connection_check', 'nonce' );

	if ( ! class_exists( 'LWC_FedEx_API' ) ) {
		require_once LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-fedex-api.php';
	}

	$package = array(
		'destination' => array(
			'country'  => isset( $_POST['country'] ) ? sanitize_text_field( wp_unslash( $_POST['country'] ) ) : '',
			'state'    => isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '',
			'postcode' => isset( $_POST['postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['postcode'] ) ) : '',
			'city'     => isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '',
		),
		'contents' => array(),
	);

	$api = new LWC_FedEx_API();
	$result = $api->get_rate_quotes( $package );

	// Keep a single "rate" field for backward compatibility.
	if ( ! empty( $result['success'] ) && ! empty( $result['quotes'] ) ) {
		$cheapest = null;
		foreach ( $result['quotes'] as $quote ) {
			if ( null === $cheapest || $quote['rate'] < $cheapest['rate'] ) {
				$cheapest = $quote;
			}
		}
		$result['rate'] = $cheapest['rate'];
	}

	wp_send_json( $result );
}

/**
 * Create a FedEx shipment and generate a label via AJAX.
 */
function lwc_fedex_create_shipment() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'lovecatz-wc' ) ) );
	}

	check_ajax_referer( 'lwc_fedex_connection_check', 'nonce' );

	if ( ! class_exists( 'LWC_FedEx_API' ) ) {
		require_once LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-fedex-api.php';
	}

	$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
	if ( ! $order_id ) {
		wp_send_json_error( array( 'message' => __( 'Order ID is required.', 'lovecatz-wc' ) ) );
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		wp_send_json_error( array( 'message' => __( 'Order not found.', 'lovecatz-wc' ) ) );
	}

	// Manual partial shipping: only the selected line items go on this AWB.
	$item_ids = array();
	if ( isset( $_POST['item_ids'] ) && is_array( $_POST['item_ids'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$item_ids = array_map( 'absint', wp_unslash( $_POST['item_ids'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$item_ids = array_values( array_filter( $item_ids ) );
	}

	$api = new LWC_FedEx_API();
	$result = $api->create_shipment( $order, 0, $item_ids );

	if ( ! empty( $result['success'] ) ) {
		// The raw response embeds the base64 label; keep it out of the AJAX payload.
		unset( $result['response'] );

		$tracking = $order->get_meta( '_lwc_fedex_tracking_number' );
		$result['tracking_number'] = is_string( $tracking ) ? $tracking : '';

		$result['label_url'] = '';
		if ( ! empty( $result['label_path'] ) ) {
			$result['label_url'] = wp_nonce_url(
				add_query_arg(
					array(
						'action'   => 'lwc_fedex_download_label',
						'order_id' => $order->get_id(),
					),
					admin_url( 'admin-ajax.php' )
				),
				'lwc_fedex_connection_check',
				'nonce'
			);
		}
	}

	wp_send_json( $result );
}

/**
 * Download a previously generated FedEx label.
 */
function lwc_fedex_download_label() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( __( 'You do not have permission to perform this action.', 'lovecatz-wc' ) );
	}

	check_ajax_referer( 'lwc_fedex_connection_check', 'nonce' );

	$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
	if ( ! $order_id ) {
		wp_die( __( 'Order ID is required.', 'lovecatz-wc' ) );
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		wp_die( __( 'Order not found.', 'lovecatz-wc' ) );
	}

	$filename = '';
	$shipment_index = isset( $_GET['shipment'] ) ? absint( wp_unslash( $_GET['shipment'] ) ) : -1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	// Partial shipments keep their own label files in the shipments array.
	if ( $shipment_index >= 0 ) {
		$shipments = $order->get_meta( '_lwc_fedex_shipments' );
		if ( is_array( $shipments ) && isset( $shipments[ $shipment_index ]['label_file'] ) ) {
			$filename = (string) $shipments[ $shipment_index ]['label_file'];
		}
	}

	if ( '' === $filename ) {
		$filename = $order->get_meta( '_lwc_fedex_label_path' );
		$filename = is_string( $filename ) ? basename( $filename ) : '';
	}

	if ( '' === $filename ) {
		wp_die( __( 'No label found for this order.', 'lovecatz-wc' ) );
	}

	// Prevent directory traversal and ensure file is inside the uploads directory.
	$upload_dir = wp_upload_dir();
	$filepath = wp_normalize_path( $upload_dir['basedir'] . '/' . $filename );
	$basedir = wp_normalize_path( $upload_dir['basedir'] );
	if ( strpos( $filepath, $basedir ) !== 0 || ! file_exists( $filepath ) ) {
		wp_die( __( 'Label file not found.', 'lovecatz-wc' ) );
	}

	nocache_headers();
	header( 'Content-Type: application/pdf' );
	header( 'Content-Disposition: attachment; filename="' . esc_attr( basename( $filepath ) ) . '"' );
	readfile( $filepath );
	exit;
}

/**
 * Register available WooCommerce shipping methods.
 *
 * @param array $methods Existing shipping methods.
 * @return array
 */
function lwc_register_shipping_methods( $methods ) {
	$methods['lwc_jt_express'] = 'LWC_Shipping_JT_Express';
	$methods['lwc_jt_cargo'] = 'LWC_Shipping_JT_Cargo';
	// Legacy alias: pre-split J&T zone instances keep working as Express.
	$methods['lwc_jt'] = 'LWC_Shipping_JT_Express';
	$methods['lwc_shipping_fedex'] = 'LWC_Shipping_FedEx';
	$methods['lwc_fedex'] = 'LWC_Shipping_FedEx';
	$methods['lwc_rayspeed'] = 'LWC_Shipping_RaySpeed';

	return $methods;
}

/**
 * Check one J&T provider/environment credential set without creating an AWB.
 * Express authenticates through the read-only Tariff API. Cargo reports that
 * validation is unavailable until its separate API contract is configured.
 */
function lwc_check_jt_connection() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'lovecatz-wc' ) ), 403 );
	}

	check_ajax_referer( 'lwc_fedex_connection_check', 'nonce' );
	$provider    = isset( $_POST['provider'] ) && 'cargo' === sanitize_key( wp_unslash( $_POST['provider'] ) ) ? 'cargo' : 'express';
	$environment = isset( $_POST['environment'] ) && 'production' === sanitize_key( wp_unslash( $_POST['environment'] ) ) ? 'production' : 'sandbox';
	$posted      = isset( $_POST['credentials'] ) && is_array( $_POST['credentials'] ) ? wp_unslash( $_POST['credentials'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$fields      = 'express' === $provider
		? array( 'order_username', 'order_api_key', 'order_key', 'tariff_customer_name', 'tariff_check_key', 'tracking_password', 'tracking_company_id', 'cancel_username', 'cancel_api_key', 'cancel_key' )
		: array( 'username', 'api_key', 'api_secret' );
	$credentials = array( 'provider' => $provider, 'environment' => $environment );
	$filled      = 0;
	foreach ( $fields as $field ) {
		$value = isset( $posted[ $field ] ) ? sanitize_text_field( $posted[ $field ] ) : '';
		$credentials[ $field ] = $value;
		$filled += '' !== trim( $value ) ? 1 : 0;
	}

	if ( 0 === $filled ) {
		wp_send_json_success( array( 'status' => 'idle', 'label' => __( 'Waiting for credentials', 'lovecatz-wc' ) ) );
	}
	if ( $filled < count( $fields ) ) {
		wp_send_json_success( array( 'status' => 'partial', 'label' => sprintf( __( 'Credentials incomplete (%1$d of %2$d fields filled)', 'lovecatz-wc' ), $filled, count( $fields ) ) ) );
	}
	if ( 'cargo' === $provider ) {
		wp_send_json_success( array( 'status' => 'unavailable', 'label' => __( 'Fields complete; Cargo API validation is not available yet', 'lovecatz-wc' ) ) );
	}

	$endpoints = LWC_JT_Express_API::get_endpoints( $environment );
	if ( empty( $endpoints['tariff'] ) ) {
		wp_send_json_success( array( 'status' => 'unavailable', 'label' => __( 'Credentials complete; this environment endpoint is not configured', 'lovecatz-wc' ) ) );
	}
	$route = LWC_JT_Route_Mapper::resolve( '', $environment );
	if ( is_wp_error( $route ) || '' === LWC_JT_Route_Mapper::get_origin_tariff_code( $environment ) ) {
		wp_send_json_success( array( 'status' => 'unavailable', 'label' => __( 'Credentials complete; backend route is not configured', 'lovecatz-wc' ) ) );
	}

	$result = ( new LWC_JT_Express_API() )->get_tariff( 1, LWC_JT_Route_Mapper::get_origin_tariff_code( $environment ), $route['tariff_area'], $credentials );
	if ( is_wp_error( $result ) ) {
		$transport_error = in_array( $result->get_error_code(), array( 'http_request_failed', 'lwc_jt_invalid_response' ), true );
		update_option( "lwc_jt_{$provider}_validation_status_{$environment}", $transport_error ? 'unavailable' : 'failed' );
		wp_send_json_success(
			array(
				'status' => $transport_error ? 'unavailable' : 'auth_failed',
				'label'  => $transport_error
					? sprintf( __( 'API unavailable; credentials could not be verified: %s', 'lovecatz-wc' ), $result->get_error_message() )
					: $result->get_error_message(),
			)
		);
	}

	update_option( "lwc_jt_{$provider}_validation_status_{$environment}", 'validated' );
	wp_send_json_success( array( 'status' => 'connected', 'label' => __( 'Tariff API connected; all credential fields are complete', 'lovecatz-wc' ) ) );
}

/** Load the compact shipping-method accordion on checkout. */
function lwc_enqueue_shipping_accordion() {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
		return;
	}

	wp_enqueue_style( 'lwc-shipping-accordion', LWC_PLUGIN_URL . 'shipping/checkout/shipping-accordion.css', array(), LWC_VERSION );
	wp_enqueue_script( 'lwc-shipping-accordion', LWC_PLUGIN_URL . 'shipping/checkout/shipping-accordion.js', array( 'jquery' ), LWC_VERSION, true );
	wp_localize_script(
		'lwc-shipping-accordion',
		'lwcShippingAccordion',
		array(
			'caption'      => __( 'Shipping method', 'lovecatz-wc' ),
			'noneSelected' => __( 'Select a shipping method', 'lovecatz-wc' ),
		)
	);
}

/** Require the recipient fields needed by J&T before checkout can finish. */
function lwc_validate_jt_checkout_contact( $data, $errors ) {
	$chosen = function_exists( 'WC' ) && WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods', array() ) : array();
	$uses_jt = false;
	foreach ( $chosen as $method ) {
		if ( 0 === strpos( (string) $method, 'lwc_jt_express' ) || 0 === strpos( (string) $method, 'lwc_jt:' ) ) {
			$uses_jt = true;
			break;
		}
	}
	if ( ! $uses_jt ) {
		return;
	}
	if ( empty( $data['billing_phone'] ) ) {
		$errors->add( 'lwc_jt_phone_required', __( 'A recipient phone number is required for J&T Express shipping.', 'lovecatz-wc' ) );
	}
	$postcode = ! empty( $data['shipping_postcode'] ) ? $data['shipping_postcode'] : ( isset( $data['billing_postcode'] ) ? $data['billing_postcode'] : '' );
	if ( '' === trim( (string) $postcode ) ) {
		$errors->add( 'lwc_jt_postcode_required', __( 'A recipient postal code is required for J&T Express shipping.', 'lovecatz-wc' ) );
	}
}

/**
 * Apply the same required recipient checks to Checkout Block / Store API.
 * WooCommerce explicitly supports aborting this hook with an exception.
 *
 * @param WC_Order $order Draft checkout order.
 * @throws Exception When J&T receiver details are incomplete.
 */
function lwc_validate_jt_store_api_order( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return;
	}

	$uses_jt = false;
	foreach ( $order->get_items( 'shipping' ) as $item ) {
		if ( in_array( $item->get_method_id(), array( 'lwc_jt_express', 'lwc_jt' ), true ) ) {
			$uses_jt = true;
			break;
		}
	}
	if ( ! $uses_jt ) {
		return;
	}

	$postcode = $order->get_shipping_postcode() ? $order->get_shipping_postcode() : $order->get_billing_postcode();
	if ( '' === trim( (string) $order->get_billing_phone() ) || '' === trim( (string) $postcode ) ) {
		throw new Exception( esc_html__( 'A recipient phone number and postal code are required for J&T Express shipping.', 'lovecatz-wc' ) );
	}
}

/** Hide internal courier routing data from order line-item tables. */
function lwc_hide_shipping_technical_meta( $hidden ) {
	return array_values(
		array_unique(
			array_merge(
				(array) $hidden,
				array(
					'lwc_jt_provider',
					'lwc_jt_max_weight',
					'lwc_jt_environment',
				)
			)
		)
	);
}

/** Test a RaySpeed development key without storing it. */
function lwc_check_rayspeed_connection() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'lovecatz-wc' ) ), 403 );
	}
	check_ajax_referer( 'lwc_fedex_connection_check', 'nonce' );
	$key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
	if ( '' === trim( $key ) ) {
		wp_send_json_success( array( 'status' => 'idle', 'label' => __( 'Waiting for credentials', 'lovecatz-wc' ) ) );
	}
	$result = ( new LWC_RaySpeed_API( $key ) )->test_connection();
	if ( empty( $result['success'] ) ) {
		$transport_error = isset( $result['error_type'] ) && 'transport' === $result['error_type'];
		update_option( 'lwc_rayspeed_validation_status', $transport_error ? 'unavailable' : 'failed' );
		wp_send_json_success( array( 'status' => $transport_error ? 'unavailable' : 'auth_failed', 'label' => isset( $result['message'] ) ? $result['message'] : __( 'RaySpeed connection failed.', 'lovecatz-wc' ) ) );
	}
	update_option( 'lwc_rayspeed_validation_status', 'validated' );
	wp_send_json_success( array( 'status' => 'connected', 'label' => $result['message'] ) );
}

/**
 * Initialize plugin data on activation.
 *
 * Idempotent: every step checks before it creates, so re-activation or
 * activation over an existing install never duplicates data.
 */
function lwc_activate() {
	if ( ! defined( 'ABSPATH' ) ) {
		return;
	}

	lwc_install();

	if ( function_exists( 'add_rewrite_endpoint' ) ) {
		add_rewrite_endpoint( 'coupon', EP_ROOT | EP_PAGES );
	}

	if ( function_exists( 'flush_rewrite_rules' ) ) {
		flush_rewrite_rules();
		/* Record the flushed version so init-time helper knows the rule is current. */
		update_option( 'lwc_coupon_endpoint_rewrite_version', LWC_VERSION );
	}
}

/**
 * Deactivate the plugin without touching any stored data.
 *
 * Tables, options, order meta, and label files are all preserved so a
 * later reactivation continues exactly where things left off.
 */
function lwc_deactivate() {
	if ( ! defined( 'ABSPATH' ) ) {
		return;
	}

	if ( function_exists( 'flush_rewrite_rules' ) ) {
		flush_rewrite_rules();
	}
}

/**
 * Install or upgrade the plugin schema.
 *
 * Safe to run repeatedly: tables are created only when missing, dbDelta
 * adds any columns introduced by newer versions, and legacy credentials
 * migrate once. Runs on activation and self-heals on version bumps even
 * when the activation hook was skipped (e.g. manual file updates).
 */
function lwc_install() {
	if ( ! defined( 'LWC_VERSION' ) ) {
		return;
	}

	if ( get_option( 'lwc_schema_version' ) === LWC_VERSION ) {
		return;
	}

	if ( file_exists( LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-fedex-account.php' ) ) {
		require_once LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-fedex-account.php';
	}

	if ( file_exists( LWC_PLUGIN_DIR . 'shipping/jt/class-lwc-jt-account.php' ) ) {
		require_once LWC_PLUGIN_DIR . 'shipping/jt/class-lwc-jt-account.php';
	}

	if ( class_exists( 'LWC_FedEx_Account' ) ) {
		LWC_FedEx_Account::create_table();
	}

	if ( class_exists( 'LWC_JT_Account' ) ) {
		foreach ( LWC_JT_Account::get_providers() as $provider ) {
			LWC_JT_Account::create_table( $provider );
		}
		LWC_JT_Account::migrate_legacy_credentials();
	}

	// Octolize integration was removed in 1.0.22. Its settings no longer
	// control the native FedEx engine and should not linger in the database.
	foreach ( array( 'lwc_fedex_engine', 'lwc_fedex_currency_adapter_enabled', 'lwc_fedex_base_currency', 'lwc_fedex_conversion_mode', 'lwc_fedex_manual_rate' ) as $obsolete_option ) {
		delete_option( $obsolete_option );
	}

	update_option( 'lwc_schema_version', LWC_VERSION );
}
add_action( 'admin_init', 'lwc_install' );

/**
 * Clean up all plugin data on uninstall.
 *
 * Removes every table, option, transient, post/order meta, user meta, and
 * generated label file the plugin created. Deactivation alone never runs
 * this; only actual deletion through the Plugins screen does.
 */
function lwc_uninstall() {
	if ( ! defined( 'ABSPATH' ) ) {
		return;
	}

	global $wpdb;

	// 1. Custom tables (current and legacy).
	$tables = array(
		$wpdb->prefix . 'lwc_fedex_accounts',
		$wpdb->prefix . 'lwc_jt_accounts',
		$wpdb->prefix . 'lwc_jt_express_accounts',
		$wpdb->prefix . 'lwc_jt_cargo_accounts',
	);

	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	// 2. Options: every setting this plugin stored uses the lwc_ prefix.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'lwc\\_%'" );

	// 3. Transients (OAuth tokens, rate caches) plus their timeouts.
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '\\_transient\\_lwc\\_%'
		    OR option_name LIKE '\\_transient\\_timeout\\_lwc\\_%'"
	);

	// 4. Post meta: product quantity limits, promo coupon markers, FedEx
	// tracking/label/shipment records (classic post storage).
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_lwc\\_%'" );

	// 5. HPOS order meta when WooCommerce high-performance order storage
	// is active (order meta lives in its own table there).
	$orders_meta_table = $wpdb->prefix . 'wc_orders_meta';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_meta_table ) ) === $orders_meta_table ) {
		$wpdb->query( "DELETE FROM {$orders_meta_table} WHERE meta_key LIKE '\\_lwc\\_%'" );
	}

	// 6. User meta added by member import. WooCommerce's own billing_*
	// and shipping_* fields are left untouched.
	$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'lwc_customer_id'" );

	// 7. Generated AWB label PDFs in the uploads directory.
	$upload_dir = wp_upload_dir();
	$label_files = glob( wp_normalize_path( $upload_dir['basedir'] . '/fedex-label-*.pdf' ) );
	if ( is_array( $label_files ) ) {
		foreach ( $label_files as $label_file ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $label_file );
		}
	}

	// 8. Drop any cached values so deleted options are not resurrected
	// from an object cache during the same request.
	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}
}

/**
 * Admin notice if WooCommerce is not active.
 */
function lwc_woocommerce_missing_notice() {
	?>
	<div class="notice notice-error is-dismissible">
		<p><?php esc_html_e( 'LoveCatz WooCommerce Complement requires WooCommerce to be installed and active.', 'lovecatz-wc' ); ?></p>
	</div>
	<?php
}


