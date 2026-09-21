<?php
/**
 * Plugin Name: LoveCatz WooCommerce Complement
 * Plugin URI:  https://github.com/fitra90/lovecatz-woocommerce-complement
 * Description: A comprehensive complement for WooCommerce including currency conversion and courier integrations (starting with J&T Express).
 * Version:     1.0.81
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
define( 'LWC_VERSION', '1.0.81' );
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
	add_filter( 'option_lwc_fedex_tracking_production_api_key', 'lwc_decrypt_secret' );
	add_filter( 'option_lwc_fedex_tracking_production_api_secret', 'lwc_decrypt_secret' );
	add_filter( 'option_lwc_jt_api_key', 'lwc_decrypt_secret' );
	add_filter( 'option_lwc_jt_api_secret', 'lwc_decrypt_secret' );
	// J&T Express credentials and J&T Cargo credentials use independent option sets.
	add_filter( 'option_lwc_jt_express_api_key', 'lwc_decrypt_secret' );
	add_filter( 'option_lwc_jt_express_api_secret', 'lwc_decrypt_secret' );
	foreach ( array( 'sandbox', 'production' ) as $lwc_jt_environment ) {
		foreach ( array( 'order_key', 'order_api_key', 'tariff_check_key', 'tracking_password', 'print_key', 'cancel_key', 'cancel_api_key' ) as $lwc_jt_secret_field ) {
			add_filter( "option_lwc_jt_express_{$lwc_jt_environment}_{$lwc_jt_secret_field}", 'lwc_decrypt_secret' );
		}
	}
	add_filter( 'option_lwc_jt_cargo_api_key', 'lwc_decrypt_secret' );
	add_filter( 'option_lwc_jt_cargo_api_secret', 'lwc_decrypt_secret' );
	foreach ( array( 'sandbox', 'production' ) as $lwc_jtc_environment ) {
		add_filter( "option_lwc_jt_cargo_{$lwc_jtc_environment}_api_key", 'lwc_decrypt_secret' );
		add_filter( "option_lwc_jt_cargo_{$lwc_jtc_environment}_api_secret", 'lwc_decrypt_secret' );
		add_filter( "option_lwc_jt_cargo_{$lwc_jtc_environment}_private_key", 'lwc_decrypt_secret' );
		add_filter( "option_lwc_jt_cargo_{$lwc_jtc_environment}_customer_password", 'lwc_decrypt_secret' );
	}
	add_filter( 'option_lwc_rayspeed_api_key', 'lwc_decrypt_secret' );

	// Include core plugin classes.
	require_once LWC_PLUGIN_DIR . 'includes/core/class-lwc-logger.php';
	require_once LWC_PLUGIN_DIR . 'includes/admin/class-lwc-admin-settings.php';
	require_once LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-fedex-account.php';
	require_once LWC_PLUGIN_DIR . 'shipping/jt/class-lwc-jt-account.php';
	require_once LWC_PLUGIN_DIR . 'shipping/jtc/class-lwc-jtc-account.php';
	require_once LWC_PLUGIN_DIR . 'shipping/jt/class-lwc-jt-request-validator.php';
	require_once LWC_PLUGIN_DIR . 'includes/checkout/class-lwc-indonesia-regions.php';
	require_once LWC_PLUGIN_DIR . 'shipping/jt/class-lwc-jt-express-api.php';
	require_once LWC_PLUGIN_DIR . 'shipping/jtc/class-lwc-jtc-api.php';
	require_once LWC_PLUGIN_DIR . 'shipping/jtc/class-lwc-jtc-text.php';
	require_once LWC_PLUGIN_DIR . 'shipping/jtc/class-lwc-jtc-field-map.php';
	require_once LWC_PLUGIN_DIR . 'shipping/jtc/class-lwc-jtc-response-view.php';
	require_once LWC_PLUGIN_DIR . 'shipping/jt/class-lwc-jt-route-mapper.php';
	require_once LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-fedex-api.php';
	require_once LWC_PLUGIN_DIR . 'shipping/class-lwc-shipping-provider.php';
	require_once LWC_PLUGIN_DIR . 'shipping/class-lwc-courier-registry.php';
	require_once LWC_PLUGIN_DIR . 'shipping/class-lwc-order-list-shipping.php';
	require_once LWC_PLUGIN_DIR . 'shipping/class-lwc-order-shipping-switcher.php';
	require_once LWC_PLUGIN_DIR . 'shipping/jt/class-lwc-shipping-jt-express.php';
	require_once LWC_PLUGIN_DIR . 'shipping/jtc/class-lwc-shipping-jtc.php';
	require_once LWC_PLUGIN_DIR . 'shipping/jtc/class-lwc-jtc-label.php';
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
	( new LWC_Order_List_Shipping() )->init();
	( new LWC_Order_Shipping_Switcher() )->init();
	LWC_Indonesia_Regions::init();
	if ( class_exists( 'LWC_JTC_Label' ) ) {
		LWC_JTC_Label::init();
	}
}
add_action( 'plugins_loaded', 'lwc_init', 20 );
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'lwc_plugin_action_links' );
add_action( 'wp_ajax_lwc_check_fedex_connection', 'lwc_check_fedex_connection' );
add_action( 'wp_ajax_lwc_check_jt_connection', 'lwc_check_jt_connection' );
add_action( 'wp_ajax_lwc_check_jtc_connection', 'lwc_check_jtc_connection' );
add_action( 'wp_ajax_lwc_jtc_sandbox_request', 'lwc_jtc_sandbox_request' );
add_action( 'wp_ajax_lwc_jtc_print_label', array( 'LWC_JTC_Label', 'handle_print_request' ) );
add_action( 'wp_ajax_lwc_jtc_track_order', 'lwc_jtc_track_order' );
add_action( 'wp_ajax_lwc_fedex_get_rate_quote', 'lwc_fedex_get_rate_quote' );
add_action( 'wp_ajax_lwc_fedex_create_shipment', 'lwc_fedex_create_shipment' );
add_action( 'wp_ajax_lwc_fedex_download_label', 'lwc_fedex_download_label' );
add_action( 'wp_ajax_lwc_check_rayspeed_connection', 'lwc_check_rayspeed_connection' );
add_action( 'wp_ajax_lwc_fedex_checkout_debug', 'lwc_fedex_checkout_debug_response' );
add_action( 'wp_ajax_lwc_fedex_checkout_debug_quote', 'lwc_fedex_checkout_debug_quote' );
add_action( 'wp_enqueue_scripts', 'lwc_enqueue_fedex_checkout_debug' );
add_action( 'wp_enqueue_scripts', 'lwc_enqueue_shipping_accordion' );
add_filter( 'woocommerce_package_rates', 'lwc_hide_internal_jt_cargo_rates', 997, 2 );
add_filter( 'woocommerce_package_rates', 'lwc_filter_jt_express_service_area', 998, 2 );
add_filter( 'woocommerce_package_rates', 'lwc_sort_checkout_shipping_rates', 999, 2 );
add_action( 'woocommerce_after_checkout_validation', 'lwc_validate_jt_checkout_contact', 10, 2 );
add_action( 'woocommerce_store_api_checkout_update_order_meta', 'lwc_validate_jt_store_api_order' );
add_filter( 'woocommerce_hidden_order_itemmeta', 'lwc_hide_shipping_technical_meta' );
register_activation_hook( __FILE__, 'lwc_activate' );
register_deactivation_hook( __FILE__, 'lwc_deactivate' );
register_uninstall_hook( __FILE__, 'lwc_uninstall' );
add_action( 'init', 'lwc_maybe_flush_rewrite_rules' );

/**
 * Keep the unfinished J&T Cargo provider out of every customer/admin quote.
 *
 * This also removes stale rates left by an older zone configuration or cached
 * session. J&T Express is unaffected because it is a separate provider.
 *
 * @param array $rates   Calculated WooCommerce rates.
 * @param array $package Shipping package (unused).
 * @return array
 */
function lwc_hide_internal_jt_cargo_rates( $rates, $package = array() ) {
	foreach ( (array) $rates as $rate_id => $rate ) {
		$method_id = is_object( $rate ) && method_exists( $rate, 'get_method_id' ) ? (string) $rate->get_method_id() : '';
		if ( 'lwc_jt_cargo' === $method_id || 0 === strpos( (string) $rate_id, 'lwc_jt_cargo:' ) ) {
			unset( $rates[ $rate_id ] );
		}
	}

	return $rates;
}

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
	$service        = isset( $_POST['service'] ) ? sanitize_key( wp_unslash( $_POST['service'] ) ) : 'shipping';

	if ( 'tracking' === $service ) {
		if ( $api_key && $api_secret ) {
			if ( ! class_exists( 'LWC_FedEx_API' ) ) {
				require_once LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-fedex-api.php';
			}

			$api = new LWC_FedEx_API(
				array(
					'tracking_account_number' => $account_number,
					'tracking_api_key'        => $api_key,
					'tracking_api_secret'     => $api_secret,
					'test_mode'               => 'no',
				)
			);
			$result = $api->test_tracking_connection();
			$status = ! empty( $result['success'] ) ? 'connected' : 'auth_failed';
			update_option( 'lwc_fedex_tracking_validation_status', ! empty( $result['success'] ) ? 'validated' : 'failed' );
			wp_send_json_success(
				array(
					'status' => $status,
					'label'  => isset( $result['message'] ) ? $result['message'] : __( 'FedEx rejected the tracking credentials.', 'lovecatz-wc' ),
				)
			);
		}

		update_option( 'lwc_fedex_tracking_validation_status', 'pending' );
		wp_send_json_success(
			array(
				'status' => $account_number || $api_key || $api_secret ? 'partial' : 'idle',
				'label'  => $account_number || $api_key || $api_secret ? __( 'Incomplete tracking credentials', 'lovecatz-wc' ) : __( 'Waiting for tracking credentials', 'lovecatz-wc' ),
			)
		);
	}

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

/** Parse the actual packed-carton override shared by rate and label requests. */
function lwc_fedex_get_posted_package_override() {
	$measurements = array();
	foreach ( array( 'weight', 'length', 'width', 'height' ) as $key ) {
		$post_key = 'package_' . $key;
		$measurements[ $key ] = isset( $_POST[ $post_key ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ) ) : '';
	}
	$dimension_keys = array( 'length', 'width', 'height' );
	$supplied_dimensions = array_filter( $dimension_keys, static function ( $key ) use ( $measurements ) { return '' !== $measurements[ $key ]; } );
	$has_custom_weight = '' !== $measurements['weight'];
	if ( ! empty( $supplied_dimensions ) && ( ! $has_custom_weight || count( $supplied_dimensions ) !== count( $dimension_keys ) ) ) {
		return new WP_Error( 'lwc_fedex_incomplete_package', __( 'Enter a custom weight. Dimensions may be blank, but length, width, and height must be entered together.', 'lovecatz-wc' ) );
	}
	if ( ! $has_custom_weight ) {
		return array();
	}

	$weight = wc_format_decimal( $measurements['weight'] );
	if ( ! is_numeric( $weight ) || (float) $weight <= 0 ) {
		return new WP_Error( 'lwc_fedex_invalid_weight', __( 'Carton weight must be a positive number.', 'lovecatz-wc' ) );
	}
	$override = array( 'weight' => round( (float) $weight, 2 ) );
	foreach ( $supplied_dimensions as $key ) {
		$value = wc_format_decimal( $measurements[ $key ] );
		if ( ! is_numeric( $value ) || (float) $value <= 0 ) {
			return new WP_Error( 'lwc_fedex_invalid_dimensions', __( 'Carton dimensions must be positive numbers.', 'lovecatz-wc' ) );
		}
		$override[ $key ] = (int) ceil( (float) $value );
	}
	$weight_ceiling = (float) apply_filters( 'lwc_fedex_package_weight_ceiling_kg', 68 );
	if ( $weight_ceiling > 0 && $override['weight'] > $weight_ceiling ) {
		return new WP_Error( 'lwc_fedex_package_too_heavy', sprintf( __( 'The packed carton exceeds the FedEx parcel weight limit of %s kg.', 'lovecatz-wc' ), wc_format_localized_decimal( $weight_ceiling ) ) );
	}
	return $override;
}

/** Read a unique positive-ID array from the current admin request. */
function lwc_fedex_get_posted_ids( $key ) {
	if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return array();
	}
	return array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST[ $key ] ) ) ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
}

/** Read positive catalog product IDs while preserving repeated additions. */
function lwc_fedex_get_posted_product_ids( $key ) {
	if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return array();
	}
	return array_values( array_filter( array_map( 'absint', wp_unslash( $_POST[ $key ] ) ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
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
	$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
	$order = $order_id ? wc_get_order( $order_id ) : false;
	$item_ids = lwc_fedex_get_posted_ids( 'item_ids' );
	$extra_product_ids = lwc_fedex_get_posted_product_ids( 'extra_product_ids' );
	if ( $order ) {
		foreach ( $order->get_items() as $item ) {
			if ( ! in_array( (int) $item->get_id(), $item_ids, true ) || ! $item->get_product() ) {
				continue;
			}
			$package['contents'][] = array( 'data' => $item->get_product(), 'quantity' => max( 1, (float) $item->get_quantity() ) );
		}
	}
	foreach ( $extra_product_ids as $product_id ) {
		$product = wc_get_product( $product_id );
		if ( $product ) {
			$package['contents'][] = array( 'data' => $product, 'quantity' => 1 );
		}
	}
	$package_override = lwc_fedex_get_posted_package_override();
	if ( is_wp_error( $package_override ) ) {
		wp_send_json( array( 'success' => false, 'message' => $package_override->get_error_message() ) );
	}
	if ( empty( $package['contents'] ) && empty( $package_override ) ) {
		wp_send_json( array( 'success' => false, 'message' => __( 'Add at least one package item or enter a custom carton weight before requesting rates.', 'lovecatz-wc' ) ) );
	}

	$api = new LWC_FedEx_API();
	$result = $api->get_rate_quotes( $package, 0, $package_override );
	if ( ! empty( $result['success'] ) ) {
		$result['quote_context'] = array(
			'weight' => isset( $package_override['weight'] ) ? $package_override['weight'] : null,
			'length' => isset( $package_override['length'] ) ? $package_override['length'] : null,
			'width' => isset( $package_override['width'] ) ? $package_override['width'] : null,
			'height' => isset( $package_override['height'] ) ? $package_override['height'] : null,
			'item_count' => count( $package['contents'] ),
		);
	}

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
	$item_ids = lwc_fedex_get_posted_ids( 'item_ids' );
	$extra_product_ids = lwc_fedex_get_posted_product_ids( 'extra_product_ids' );
	$replaced_item_ids = lwc_fedex_get_posted_ids( 'replaced_item_ids' );
	$service_type = isset( $_POST['service_type'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['service_type'] ) ) ) : '';
	if ( ! in_array( $service_type, array( 'FEDEX_INTERNATIONAL_PRIORITY', 'INTERNATIONAL_ECONOMY' ), true ) ) {
		wp_send_json( array( 'success' => false, 'message' => __( 'Select FedEx International Priority or FedEx International Economy.', 'lovecatz-wc' ) ) );
	}
	$manifest_mode = isset( $_POST['manifest_mode'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['manifest_mode'] ) );

	// A cancelled shipment releases its items, but an active AWB must never be
	// duplicated. Empty item_ids are used by the order-list action and resolve to
	// all line items that are not already covered by an active shipment.
	$order_item_ids = array_map(
		static function ( $item ) {
			return (int) $item->get_id();
		},
		array_values( $order->get_items() )
	);
	$active_item_ids = array();
	$has_unscoped_active_shipment = false;
	$shipments = $order->get_meta( '_lwc_fedex_shipments' );
	foreach ( is_array( $shipments ) ? $shipments : array() as $shipment ) {
		if ( ! is_array( $shipment ) || 'cancelled' === ( isset( $shipment['status'] ) ? $shipment['status'] : '' ) ) {
			continue;
		}
		if ( ! empty( $shipment['replaced_item_ids'] ) && is_array( $shipment['replaced_item_ids'] ) ) {
			$active_item_ids = array_merge( $active_item_ids, array_map( 'intval', $shipment['replaced_item_ids'] ) );
		}
		if ( empty( $shipment['item_ids'] ) || ! is_array( $shipment['item_ids'] ) ) {
			if ( empty( $shipment['extra_product_ids'] ) ) {
				$has_unscoped_active_shipment = true;
			}
			continue;
		}
		$active_item_ids = array_merge( $active_item_ids, array_map( 'intval', $shipment['item_ids'] ) );
	}
	$active_item_ids = array_values( array_unique( $active_item_ids ) );

	if ( $has_unscoped_active_shipment ) {
		wp_send_json( array( 'success' => false, 'message' => __( 'An active FedEx AWB does not identify its items. Cancel that AWB before creating another one.', 'lovecatz-wc' ) ) );
	}
	$available_order_item_ids = array_values( array_diff( $order_item_ids, $active_item_ids ) );
	if ( empty( $item_ids ) && ! $manifest_mode ) {
		$item_ids = array_values( array_diff( $order_item_ids, $active_item_ids ) );
	} elseif ( array_diff( $item_ids, $order_item_ids ) ) {
		wp_send_json( array( 'success' => false, 'message' => __( 'One or more selected order items are invalid.', 'lovecatz-wc' ) ) );
	}
	if ( array_intersect( $item_ids, $active_item_ids ) ) {
		wp_send_json( array( 'success' => false, 'message' => __( 'One or more selected items are already covered by an active FedEx AWB.', 'lovecatz-wc' ) ) );
	}
	if ( array_diff( $replaced_item_ids, $order_item_ids ) || array_intersect( $replaced_item_ids, $active_item_ids ) ) {
		wp_send_json( array( 'success' => false, 'message' => __( 'One or more replaced order items are invalid or already shipped.', 'lovecatz-wc' ) ) );
	}
	if ( empty( $extra_product_ids ) ) {
		$replaced_item_ids = array();
	}
	if ( $manifest_mode && empty( $available_order_item_ids ) && ! empty( $extra_product_ids ) ) {
		wp_send_json( array( 'success' => false, 'message' => __( 'All original order items are already covered by active FedEx AWBs. Cancel the relevant AWB before replacing its contents.', 'lovecatz-wc' ) ) );
	}
	foreach ( $extra_product_ids as $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->exists() ) {
			wp_send_json( array( 'success' => false, 'message' => __( 'One or more added catalog products are invalid.', 'lovecatz-wc' ) ) );
		}
		if ( ! $product->is_in_stock() ) {
			wp_send_json( array( 'success' => false, 'message' => sprintf( __( '%s is out of stock and cannot be added to the FedEx package.', 'lovecatz-wc' ), wp_strip_all_tags( $product->get_name() ) ) ) );
		}
	}
	if ( empty( $item_ids ) && empty( $extra_product_ids ) ) {
		wp_send_json( array( 'success' => false, 'message' => __( 'All order items are already covered by active FedEx AWBs.', 'lovecatz-wc' ) ) );
	}

	$package_override = lwc_fedex_get_posted_package_override();
	if ( is_wp_error( $package_override ) ) {
		wp_send_json( array( 'success' => false, 'message' => $package_override->get_error_message() ) );
	}

	$api = new LWC_FedEx_API();
	$result = $api->create_shipment( $order, 0, $item_ids, $package_override, $extra_product_ids, $replaced_item_ids, $service_type );

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
	// Legacy alias: pre-split J&T zone instances keep working as Express.
	$methods['lwc_jt'] = 'LWC_Shipping_JT_Express';
	$methods['lwc_shipping_fedex'] = 'LWC_Shipping_FedEx';
	$methods['lwc_fedex'] = 'LWC_Shipping_FedEx';
	$methods['lwc_rayspeed'] = 'LWC_Shipping_RaySpeed';

	return $methods;
}

/** Check J&T Cargo API reachability without creating or changing a shipment. */
function lwc_check_jtc_connection() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'lovecatz-wc' ) ), 403 );
	}
	check_ajax_referer( 'lwc_fedex_connection_check', 'nonce' );
	$environment = isset( $_POST['environment'] ) && 'production' === sanitize_key( wp_unslash( $_POST['environment'] ) ) ? 'production' : 'sandbox';
	$uuid = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
	$result = LWC_JTC_API::check_health( array( 'environment' => $environment, 'uuid' => $uuid ) );
	wp_send_json_success( $result );
}

/** Proxy one explicitly selected, whitelisted J&T Cargo Sandbox test request. */
function lwc_jtc_sandbox_request() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'lovecatz-wc' ) ), 403 );
	}
	check_ajax_referer( 'lwc_fedex_connection_check', 'nonce' );
	$interface = isset( $_POST['interface'] ) ? sanitize_key( wp_unslash( $_POST['interface'] ) ) : '';
	$method    = isset( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : 'post';
	$format    = isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : 'json';
	$payload   = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as JSON by the API client.
	$headers   = isset( $_POST['headers'] ) ? wp_unslash( $_POST['headers'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as JSON and sanitized by the API client.
	$result    = LWC_JTC_API::sandbox_request( $interface, strtoupper( $method ), $format, $payload, $headers );
	if ( is_wp_error( $result ) ) {
		$is_local_validation = 0 === strpos( (string) $result->get_error_code(), 'lwc_jtc_' );
		$progress = $is_local_validation ? LWC_JTC_Account::get_sandbox_test_progress() : LWC_JTC_Account::record_sandbox_test_result( $interface, 0 );
		wp_send_json_error( array( 'message' => $result->get_error_message(), 'progress' => lwc_jtc_format_sandbox_progress( $progress ) ), 400 );
	}
	$progress = LWC_JTC_Account::record_sandbox_test_result( $interface, $result['http_status'], $result['business_success'], $result['business_code'] );
	$result['progress'] = lwc_jtc_format_sandbox_progress( $progress );

	// Structured, dynamic rendering of whatever the server returned. The raw
	// payload stays untouched; only the presentation layer reads it.
	if ( class_exists( 'LWC_JTC_Response_View' ) ) {
		$labels = LWC_JTC_API::get_interface_labels();
		$label  = isset( $labels[ $interface ] ) ? $labels[ $interface ] : $interface;
		$result['view_html'] = LWC_JTC_Response_View::render_response(
			$interface,
			$result,
			array(
				'heading' => sprintf(
					/* translators: %s: interface name. */
					__( 'Tampilan terstruktur — %s', 'lovecatz-wc' ),
					$label
				),
			)
		);
	}

	wp_send_json_success( $result );
}

/** Fetch the live tracking history for one J&T Cargo order and render it. */
function lwc_jtc_track_order() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'lovecatz-wc' ) ), 403 );
	}
	check_ajax_referer( 'lwc_jtc_track_order', 'nonce' );

	$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
	$order    = $order_id ? wc_get_order( $order_id ) : null;
	if ( ! $order instanceof WC_Order ) {
		wp_send_json_error( array( 'message' => __( 'Order not found.', 'lovecatz-wc' ) ), 404 );
	}
	if ( ! LWC_JTC_Label::order_uses_cargo( $order ) ) {
		wp_send_json_error( array( 'message' => __( 'This order is not shipped with J&T Cargo.', 'lovecatz-wc' ) ), 400 );
	}

	$awb = (string) $order->get_meta( '_lwc_jt_cargo_awb', true );
	if ( '' === $awb ) {
		wp_send_json_error( array( 'message' => __( 'J&T Cargo AWB is not assigned yet.', 'lovecatz-wc' ) ), 400 );
	}

	$result = LWC_JTC_API::request( 'shipment_track', array( 'billCode' => $awb ) );

	// Store the payload verbatim so the label and later views reuse it.
	if ( ! is_wp_error( $result ) ) {
		LWC_JTC_Label::store_response( $order, 'shipment_track', $result );
	}

	$view_args = array( 'heading' => __( 'Riwayat pengiriman J&T Cargo', 'lovecatz-wc' ) );
	$html      = class_exists( 'LWC_JTC_Response_View' )
		? LWC_JTC_Response_View::render_response( 'shipment_track', $result, $view_args )
		: '';

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message(), 'view_html' => $html ), 400 );
	}

	// ?render=html serves a standalone page (used by the order-list button).
	if ( isset( $_REQUEST['render'] ) && 'html' === sanitize_key( wp_unslash( $_REQUEST['render'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above.
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title>';
		echo esc_html__( 'Riwayat pengiriman J&T Cargo', 'lovecatz-wc' );
		echo '</title><style>body{margin:0;padding:20px;background:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#1d2327}</style></head><body>';
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template escapes.
		echo '<p><button type="button" onclick="window.print()">' . esc_html__( 'Cetak', 'lovecatz-wc' ) . '</button></p>';
		echo '</body></html>';
		exit;
	}

	wp_send_json_success( array( 'view_html' => $html ) );
}

/** Build the public, credential-free progress payload used by the Sandbox UI. */
function lwc_jtc_format_sandbox_progress( $progress ) {
	$interfaces = array();
	$completed  = 0;
	$total_hits = 0;
	foreach ( LWC_JTC_API::get_interface_paths() as $name => $path ) {
		$item = isset( $progress['interfaces'][ $name ] ) && is_array( $progress['interfaces'][ $name ] ) ? $progress['interfaces'][ $name ] : array();
		$successes = min( 3, isset( $item['successes'] ) ? absint( $item['successes'] ) : 0 );
		$total_hits += $successes;
		if ( 3 === $successes ) {
			++$completed;
		}
		$interfaces[ $name ] = array(
			'attempts' => isset( $item['attempts'] ) ? absint( $item['attempts'] ) : 0,
			'successes' => $successes,
			'last_http' => isset( $item['last_http'] ) ? absint( $item['last_http'] ) : 0,
			'last_business_code' => isset( $item['last_business_code'] ) ? sanitize_text_field( $item['last_business_code'] ) : '',
			'last_tested_at' => isset( $item['last_tested_at'] ) ? sanitize_text_field( $item['last_tested_at'] ) : '',
		);
	}
	return array(
		'interfaces' => $interfaces,
		'completed_endpoints' => $completed,
		'total_endpoints' => count( $interfaces ),
		'successful_hits' => $total_hits,
		'required_hits' => count( $interfaces ) * 3,
		'local_complete' => count( $interfaces ) === $completed,
	);
}

/**
 * Check each J&T Express service for the active environment.
 */
function lwc_check_jt_connection() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'lovecatz-wc' ) ), 403 );
	}

	check_ajax_referer( 'lwc_fedex_connection_check', 'nonce' );
	$environment = isset( $_POST['environment'] ) && 'production' === sanitize_key( wp_unslash( $_POST['environment'] ) ) ? 'production' : 'sandbox';
	$posted      = isset( $_POST['credentials'] ) && is_array( $_POST['credentials'] ) ? wp_unslash( $_POST['credentials'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$fields      = array( 'order_username', 'order_api_key', 'order_key', 'tariff_customer_name', 'tariff_check_key', 'tracking_password', 'tracking_company_id', 'print_key', 'print_company_id', 'cancel_key', 'cancel_username', 'cancel_api_key' );
	$credentials = array( 'provider' => 'express', 'environment' => $environment );
	foreach ( $fields as $field ) {
		$value                 = isset( $posted[ $field ] ) ? sanitize_text_field( $posted[ $field ] ) : '';
		$credentials[ $field ] = $value;
	}

	$requirements = array(
		'order'        => array( 'order_username', 'order_api_key', 'order_key' ),
		'tariff'       => array( 'tariff_customer_name', 'tariff_check_key' ),
		'track'        => array( 'tracking_company_id', 'tracking_password' ),
		'cancellation' => array( 'cancel_key', 'cancel_username', 'cancel_api_key' ),
		'print'        => array( 'print_company_id', 'print_key' ),
	);
	$field_labels = array(
		'order_username' => __( 'Order Username', 'lovecatz-wc' ), 'order_api_key' => __( 'Order API Key', 'lovecatz-wc' ), 'order_key' => __( 'Order Key', 'lovecatz-wc' ),
		'tariff_customer_name' => __( 'Tariff Customer Name', 'lovecatz-wc' ), 'tariff_check_key' => __( 'Tariff Key', 'lovecatz-wc' ),
		'tracking_company_id' => __( 'Track E-company ID', 'lovecatz-wc' ), 'tracking_password' => __( 'Password Track', 'lovecatz-wc' ),
		'cancel_key' => __( 'Cancellation Key', 'lovecatz-wc' ), 'cancel_username' => __( 'Cancellation Username', 'lovecatz-wc' ), 'cancel_api_key' => __( 'Cancellation API Key', 'lovecatz-wc' ),
		'print_company_id' => __( 'Print E-company ID', 'lovecatz-wc' ), 'print_key' => __( 'Print Key', 'lovecatz-wc' ),
	);
	$services = array();
	foreach ( $requirements as $service => $required ) {
		$missing = array_filter( $required, function ( $field ) use ( $credentials ) { return '' === trim( (string) $credentials[ $field ] ); } );
		$missing_labels = array_map( function ( $field ) use ( $field_labels ) { return isset( $field_labels[ $field ] ) ? $field_labels[ $field ] : $field; }, $missing );
		$services[ $service ] = $missing
			? array( 'status' => 'partial', 'label' => sprintf( __( 'Credentials incomplete: %s', 'lovecatz-wc' ), implode( ', ', $missing_labels ) ) )
			: array( 'status' => 'partial', 'label' => __( 'Credentials complete; waiting for verification.', 'lovecatz-wc' ) );
	}

	$endpoints = LWC_JT_Express_API::get_endpoints( $environment );
	$api = new LWC_JT_Express_API();
	if ( empty( array_filter( $requirements['tariff'], function ( $field ) use ( $credentials ) { return '' === trim( (string) $credentials[ $field ] ); } ) ) ) {
		$country_state = (string) get_option( 'woocommerce_default_country', 'ID' );
		$parts = array_pad( explode( ':', $country_state, 2 ), 2, '' );
		$rows = LWC_Indonesia_Regions::find_city_regions( strtoupper( $parts[1] ), (string) get_option( 'woocommerce_store_city', '' ) );
		$origin = LWC_JT_Route_Mapper::get_origin_tariff_code( $environment );
		if ( empty( $rows ) || '' === $origin || empty( $endpoints['tariff'] ) ) {
			$services['tariff'] = array( 'status' => 'partial', 'label' => __( 'Store origin mapping is incomplete; Tariff API was not called.', 'lovecatz-wc' ) );
		} else {
			$result = $api->get_tariff( 1, $origin, $rows[0]['jt_district_name'], $credentials );
			$services['tariff'] = is_wp_error( $result )
				? array( 'status' => 'partial', 'label' => $result->get_error_message(), 'exchange' => $result->get_error_data() )
				: array( 'status' => 'connected', 'label' => __( 'Tariff API connected.', 'lovecatz-wc' ), 'exchange' => isset( $result['exchange'] ) ? $result['exchange'] : null );
		}
	}

	$recent_awb = lwc_find_recent_jt_awb( $environment );
	foreach ( array( 'track', 'print' ) as $service ) {
		$required = $requirements[ $service ];
		$complete = empty( array_filter( $required, function ( $field ) use ( $credentials ) { return '' === trim( (string) $credentials[ $field ] ); } ) );
		if ( ! $complete ) {
			continue;
		}
		if ( '' === $recent_awb ) {
			$services[ $service ] = array( 'status' => 'partial', 'label' => __( 'Credentials complete; create an AWB in this environment to verify this service.', 'lovecatz-wc' ) );
			continue;
		}
		$result = 'track' === $service ? $api->track( $recent_awb, $credentials ) : $api->get_print_url( $recent_awb, $credentials );
		$services[ $service ] = is_wp_error( $result )
			? array( 'status' => 'partial', 'label' => $result->get_error_message(), 'exchange' => $result->get_error_data() )
			: array( 'status' => 'connected', 'label' => 'track' === $service ? __( 'Tracking API connected.', 'lovecatz-wc' ) : __( 'Print API connected.', 'lovecatz-wc' ), 'exchange' => isset( $result['exchange'] ) ? $result['exchange'] : null );
	}

	foreach ( array( 'order', 'cancellation' ) as $service ) {
		$required = $requirements[ $service ];
		$complete = empty( array_filter( $required, function ( $field ) use ( $credentials ) { return '' === trim( (string) $credentials[ $field ] ); } ) );
		if ( ! $complete ) {
			continue;
		}
		$record = LWC_JT_Account::get_service_status( $environment, $service, $credentials, $required );
		if ( $record ) {
			$services[ $service ] = array( 'status' => $record['status'], 'label' => $record['message'] );
		} elseif ( 'order' === $service && '' !== $recent_awb ) {
			$saved_credentials = LWC_JT_Account::get_credentials( $environment );
			$matches_saved = ! array_filter( $required, function ( $field ) use ( $credentials, $saved_credentials ) {
				return ! isset( $saved_credentials[ $field ] ) || (string) $saved_credentials[ $field ] !== (string) $credentials[ $field ];
			} );
			if ( $matches_saved ) {
				$services[ $service ] = array( 'status' => 'connected', 'label' => __( 'Order API verified by an existing AWB created with the saved credentials.', 'lovecatz-wc' ) );
				LWC_JT_Account::set_service_status( $environment, 'order', 'connected', $services[ $service ]['label'], $credentials, $required );
			} else {
				$services[ $service ] = array( 'status' => 'partial', 'label' => __( 'Order credentials have changed; verification requires a successful real AWB creation.', 'lovecatz-wc' ) );
			}
		} else {
			$services[ $service ] = array(
				'status' => 'partial',
				'label'  => 'order' === $service
					? __( 'Credentials complete; verification requires a successful real AWB creation.', 'lovecatz-wc' )
					: __( 'Credentials complete; verification requires a real cancellation and will not be simulated.', 'lovecatz-wc' ),
			);
		}
	}

	$all_connected = ! array_filter( $services, function ( $service ) { return 'connected' !== $service['status']; } );
	$status = $all_connected ? 'connected' : 'partial';
	$label = $all_connected
		? __( 'All services are connected & ready to use', 'lovecatz-wc' )
		: __( 'Some J&T services need attention. See the service details below.', 'lovecatz-wc' );
	update_option( "lwc_jt_express_validation_status_{$environment}", $all_connected ? 'validated' : 'partial' );
	wp_send_json_success( array( 'status' => $status, 'label' => $label, 'services' => $services ) );
}

/** Find a recent J&T AWB that belongs to the selected API environment. */
function lwc_find_recent_jt_awb( $environment ) {
	$orders = wc_get_orders( array( 'limit' => 50, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects' ) );
	$fallback_awb = '';
	foreach ( $orders as $order ) {
		if ( ! $order->get_meta( '_lwc_jt_awb' ) ) {
			continue;
		}
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			if ( ! in_array( $item->get_method_id(), array( 'lwc_jt_express', 'lwc_jt' ), true ) ) {
				continue;
			}
			$item_environment = $item->get_meta( '_lwc_jt_environment', true );
			if ( ! $item_environment ) {
				$item_environment = $item->get_meta( 'lwc_jt_environment', true );
			}
			if ( $environment === ( 'production' === $item_environment ? 'production' : 'sandbox' ) ) {
				$awb = sanitize_text_field( (string) $order->get_meta( '_lwc_jt_awb' ) );
				$tracking = $order->get_meta( '_lwc_jt_tracking' );
				$has_cancel_evidence = (bool) $order->get_meta( '_lwc_jt_cancelled' );
				if ( ! $has_cancel_evidence && class_exists( 'LWC_JT_Express_API' ) && is_array( $tracking ) ) {
					$has_cancel_evidence = LWC_JT_Express_API::tracking_confirms_cancellation( isset( $tracking['history'] ) ? $tracking['history'] : array() );
				}
				if ( $has_cancel_evidence ) {
					return $awb;
				}
				if ( '' === $fallback_awb ) {
					$fallback_awb = $awb;
				}
			}
		}
	}
	return $fallback_awb;
}

/**
 * Order shipping options by the customer-visible charge, lowest first.
 * Associative rate IDs are preserved because WooCommerce uses them as values.
 *
 * @param array $rates   Available shipping rates.
 * @param array $package Shipping package.
 * @return array
 */
function lwc_sort_checkout_shipping_rates( $rates, $package = array() ) {
	if ( count( $rates ) < 2 ) {
		return $rates;
	}

	$position = 0;
	$indexed  = array();
	foreach ( $rates as $key => $rate ) {
		$taxes = is_object( $rate ) && method_exists( $rate, 'get_taxes' ) ? array_sum( array_map( 'floatval', (array) $rate->get_taxes() ) ) : 0;
		$cost  = is_object( $rate ) && method_exists( $rate, 'get_cost' ) ? (float) $rate->get_cost() : 0;
		$indexed[] = array( 'key' => $key, 'rate' => $rate, 'total' => $cost + $taxes, 'position' => $position++ );
	}

	usort( $indexed, function ( $left, $right ) {
		if ( $left['total'] === $right['total'] ) {
			return $left['position'] <=> $right['position'];
		}
		return $left['total'] <=> $right['total'];
	} );

	$sorted = array();
	foreach ( $indexed as $entry ) {
		$sorted[ $entry['key'] ] = $entry['rate'];
	}
	return $sorted;
}

/** Load the always-visible shipping-method list on checkout. */
function lwc_enqueue_shipping_accordion() {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
		return;
	}

	wp_enqueue_style( 'lwc-shipping-accordion', LWC_PLUGIN_URL . 'shipping/checkout/shipping-accordion.css', array(), LWC_VERSION );
	wp_enqueue_script( 'lwc-shipping-accordion', LWC_PLUGIN_URL . 'shipping/checkout/shipping-accordion.js', array( 'jquery' ), LWC_VERSION, true );
}

/** Remove cached or zone-provided J&T Express rates outside the configured coverage. */
function lwc_filter_jt_express_service_area( $rates, $package ) {
	if ( 'java' !== get_option( 'lwc_jt_express_service_area', 'indonesia' ) || ! class_exists( 'LWC_Shipping_JT_Express' ) || LWC_Shipping_JT_Express::is_java_destination( $package ) ) {
		return $rates;
	}

	foreach ( (array) $rates as $rate_id => $rate ) {
		$method_id = is_object( $rate ) && method_exists( $rate, 'get_method_id' ) ? (string) $rate->get_method_id() : '';
		if ( in_array( $method_id, array( 'lwc_jt_express', 'lwc_jt' ), true ) || 0 === strpos( (string) $rate_id, 'lwc_jt_express' ) ) {
			unset( $rates[ $rate_id ] );
		}
	}

	return $rates;
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
	$phone = isset( $data['billing_phone'] ) ? preg_replace( '/[^0-9+]/', '', (string) $data['billing_phone'] ) : '';
	if ( 0 === strpos( $phone, '0' ) ) {
		$phone = '+62' . substr( $phone, 1 );
	} elseif ( 0 === strpos( $phone, '62' ) ) {
		$phone = '+' . $phone;
	}
	if ( ! LWC_JT_Request_Validator::is_valid_phone( $phone ) ) {
		$errors->add( 'lwc_jt_phone_invalid', __( 'The J&T recipient phone must use a valid Indonesian +62 format.', 'lovecatz-wc' ) );
	}
	$postcode = ! empty( $data['shipping_postcode'] ) ? $data['shipping_postcode'] : ( isset( $data['billing_postcode'] ) ? $data['billing_postcode'] : '' );
	if ( ! LWC_JT_Request_Validator::is_valid_postcode( $postcode ) ) {
		$errors->add( 'lwc_jt_postcode_invalid', __( 'The J&T recipient postal code must contain exactly five digits.', 'lovecatz-wc' ) );
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
	$phone = preg_replace( '/[^0-9+]/', '', (string) $order->get_billing_phone() );
	if ( 0 === strpos( $phone, '0' ) ) {
		$phone = '+62' . substr( $phone, 1 );
	} elseif ( 0 === strpos( $phone, '62' ) ) {
		$phone = '+' . $phone;
	}
	if ( ! LWC_JT_Request_Validator::is_valid_phone( $phone ) || ! LWC_JT_Request_Validator::is_valid_postcode( $postcode ) ) {
		throw new Exception( esc_html__( 'A valid +62 recipient phone and an exact five-digit Indonesian postal code are required for J&T Express shipping.', 'lovecatz-wc' ) );
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

	if ( ! class_exists( 'LWC_Indonesia_Regions' ) && file_exists( LWC_PLUGIN_DIR . 'includes/checkout/class-lwc-indonesia-regions.php' ) ) {
		require_once LWC_PLUGIN_DIR . 'includes/checkout/class-lwc-indonesia-regions.php';
	}
	if ( class_exists( 'LWC_Indonesia_Regions' ) ) {
		LWC_Indonesia_Regions::unschedule_sync();
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
	if ( file_exists( LWC_PLUGIN_DIR . 'shipping/jtc/class-lwc-jtc-account.php' ) ) {
		require_once LWC_PLUGIN_DIR . 'shipping/jtc/class-lwc-jtc-account.php';
	}
	if ( file_exists( LWC_PLUGIN_DIR . 'shipping/jt/class-lwc-jt-request-validator.php' ) ) {
		require_once LWC_PLUGIN_DIR . 'shipping/jt/class-lwc-jt-request-validator.php';
	}
	if ( file_exists( LWC_PLUGIN_DIR . 'includes/checkout/class-lwc-indonesia-regions.php' ) ) {
		require_once LWC_PLUGIN_DIR . 'includes/checkout/class-lwc-indonesia-regions.php';
	}

	if ( class_exists( 'LWC_FedEx_Account' ) ) {
		LWC_FedEx_Account::create_table();
	}

	if ( class_exists( 'LWC_JT_Account' ) ) {
		LWC_JT_Account::create_table();
		LWC_JT_Account::migrate_legacy_credentials();
	}
	if ( class_exists( 'LWC_JTC_Account' ) ) {
		LWC_JTC_Account::create_table();
	}
	if ( class_exists( 'LWC_Indonesia_Regions' ) ) {
		LWC_Indonesia_Regions::install();
	}

	// Octolize integration was removed in 1.0.22. Its settings no longer
	// control the native FedEx engine and should not linger in the database.
	foreach ( array( 'lwc_fedex_engine', 'lwc_fedex_currency_adapter_enabled', 'lwc_fedex_base_currency', 'lwc_fedex_conversion_mode', 'lwc_fedex_manual_rate', 'lwc_jt_area_mapping_meta', 'lwc_jt_sandbox_certification_last_result', 'lwc_jt_express_production_order_url', 'lwc_jt_express_production_tariff_url', 'lwc_jt_express_production_tracking_url', 'lwc_jt_express_production_print_url', 'lwc_jt_express_production_cancel_url' ) as $obsolete_option ) {
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
		$wpdb->prefix . 'lwc_jt_area_map',
		$wpdb->prefix . 'lwc_indonesia_regions',
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
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_lwc\\_%' OR meta_key LIKE '\\_wc\\_billing/lwc/%' OR meta_key LIKE '\\_wc\\_shipping/lwc/%'" );

	// 5. HPOS order meta when WooCommerce high-performance order storage
	// is active (order meta lives in its own table there).
	$orders_meta_table = $wpdb->prefix . 'wc_orders_meta';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_meta_table ) ) === $orders_meta_table ) {
		$wpdb->query( "DELETE FROM {$orders_meta_table} WHERE meta_key LIKE '\\_lwc\\_%' OR meta_key LIKE '\\_wc\\_billing/lwc/%' OR meta_key LIKE '\\_wc\\_shipping/lwc/%'" );
	}

	// 6. User meta added by member import. WooCommerce's own billing_*
	// and shipping_* fields are left untouched.
	$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'lwc_customer_id'" );
	$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '\\_wc\\_billing/lwc/%' OR meta_key LIKE '\\_wc\\_shipping/lwc/%'" );

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

