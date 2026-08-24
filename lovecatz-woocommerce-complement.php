<?php
/**
 * Plugin Name: LoveCatz WooCommerce Complement
 * Plugin URI:  https://github.com/fitra90/lovecatz-woocommerce-complement
 * Description: A comprehensive complement for WooCommerce including currency conversion and courier integrations (starting with J&T Express).
 * Version:     1.0.21
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
define( 'LWC_VERSION', '1.0.21' );
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

	if ( 0 === strpos( $value, 'lwc1:' ) ) {
		return $value;
	}

	$key    = hash( 'sha256', wp_salt( 'auth' ), true );
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
	if ( ! is_string( $value ) || 0 !== strpos( $value, 'lwc1:' ) || ! function_exists( 'openssl_decrypt' ) ) {
		return $value;
	}

	$key    = hash( 'sha256', wp_salt( 'auth' ), true );
	$iv     = substr( hash( 'sha256', $key ), 0, 16 );
	$raw    = base64_decode( substr( $value, 5 ) );
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
	}

	// Include core plugin classes.
	require_once LWC_PLUGIN_DIR . 'includes/core/class-lwc-logger.php';
	require_once LWC_PLUGIN_DIR . 'includes/admin/class-lwc-admin-settings.php';
	require_once LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-fedex-account.php';
	require_once LWC_PLUGIN_DIR . 'shipping/jt/class-lwc-jt-account.php';
	require_once LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-fedex-api.php';
	require_once LWC_PLUGIN_DIR . 'shipping/class-lwc-shipping-provider.php';
	require_once LWC_PLUGIN_DIR . 'shipping/jt/class-lwc-shipping-jt-base.php';
	require_once LWC_PLUGIN_DIR . 'shipping/jt/class-lwc-shipping-jt-express.php';
	require_once LWC_PLUGIN_DIR . 'shipping/jt/class-lwc-shipping-jt-cargo.php';
	require_once LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-shipping-fedex.php';
	require_once LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-fedex-currency-adapter.php';
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
add_action( 'wp_ajax_lwc_fedex_get_rate_quote', 'lwc_fedex_get_rate_quote' );
add_action( 'wp_ajax_lwc_fedex_create_shipment', 'lwc_fedex_create_shipment' );
add_action( 'wp_ajax_lwc_fedex_download_label', 'lwc_fedex_download_label' );
add_action( 'wp_ajax_lwc_fedex_checkout_debug', 'lwc_fedex_checkout_debug_response' );
add_action( 'wp_ajax_nopriv_lwc_fedex_checkout_debug', 'lwc_fedex_checkout_debug_response' );
add_action( 'wp_ajax_lwc_fedex_checkout_debug_quote', 'lwc_fedex_checkout_debug_quote' );
add_action( 'wp_ajax_nopriv_lwc_fedex_checkout_debug_quote', 'lwc_fedex_checkout_debug_quote' );
add_action( 'wp_enqueue_scripts', 'lwc_enqueue_fedex_checkout_debug' );
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
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
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

	return $methods;
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


