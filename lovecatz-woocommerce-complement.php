<?php
/**
 * Plugin Name: LoveCatz WooCommerce Complement
 * Plugin URI:  https://github.com/fitra90/lovecatz-woocommerce-complement
 * Description: A comprehensive complement for WooCommerce including currency conversion and courier integrations (starting with J&T Express).
 * Version:     1.0.0
 * Author:      Fitra Fadilana
 * Author URI:  https://fitrafadilana.my.id
 * Text Domain: lovecatz-woocommerce-complement
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
define( 'LWC_VERSION', '1.0.0' );
define( 'LWC_PLUGIN_FILE', __FILE__ );
define( 'LWC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LWC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

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

	// Include core plugin classes.
	require_once LWC_PLUGIN_DIR . 'includes/core/class-lwc-logger.php';
	require_once LWC_PLUGIN_DIR . 'includes/admin/class-lwc-admin-settings.php';
	require_once LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-fedex-account.php';
	require_once LWC_PLUGIN_DIR . 'shipping/jt/class-lwc-jt-account.php';
	require_once LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-fedex-api.php';
	require_once LWC_PLUGIN_DIR . 'shipping/class-lwc-shipping-provider.php';
	require_once LWC_PLUGIN_DIR . 'shipping/jt/class-lwc-shipping-jt.php';
	require_once LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-shipping-fedex.php';
	require_once LWC_PLUGIN_DIR . 'includes/core/class-lwc-core.php';

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
register_activation_hook( __FILE__, 'lwc_activate' );
register_uninstall_hook( __FILE__, 'lwc_uninstall' );
add_action( 'init', 'lwc_maybe_flush_rewrite_rules' );

/**
 * Flush rewrite rules once when the coupon endpoint is newly registered.
 */
function lwc_maybe_flush_rewrite_rules() {
	if ( ! function_exists( 'add_rewrite_endpoint' ) || ! function_exists( 'flush_rewrite_rules' ) ) {
		return;
	}

	if ( get_option( 'lwc_coupon_endpoint_rewrite_flushed', 'no' ) === 'yes' ) {
		return;
	}

	add_rewrite_endpoint( 'coupon', EP_ROOT | EP_PAGES );
	flush_rewrite_rules();
	update_option( 'lwc_coupon_endpoint_rewrite_flushed', 'yes' );
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
 */
function lwc_check_fedex_connection() {
	check_ajax_referer( 'lwc_fedex_connection_check', 'nonce' );

	$account_number = isset( $_POST['account_number'] ) ? sanitize_text_field( wp_unslash( $_POST['account_number'] ) ) : '';
	$api_key        = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
	$api_secret     = isset( $_POST['api_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['api_secret'] ) ) : '';

	if ( $account_number && $api_key && $api_secret ) {
		update_option( 'lwc_fedex_validation_status', 'validated' );
		wp_send_json_success(
			array(
				'status' => 'connected',
				'label'  => __( 'Connected (REST API ready)', 'lovecatz-wc' ),
			)
		);
	}

	if ( $account_number || $api_key || $api_secret ) {
		update_option( 'lwc_fedex_validation_status', 'pending' );
		wp_send_json_success(
			array(
				'status' => 'partial',
				'label'  => __( 'Incomplete credentials', 'lovecatz-wc' ),
			)
		);
	}

	update_option( 'lwc_fedex_validation_status', 'pending' );
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
	$result = $api->get_rate_quote( $package );
	wp_send_json( $result );
}

/**
 * Create a FedEx shipment and generate a label via AJAX.
 */
function lwc_fedex_create_shipment() {
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

	$api = new LWC_FedEx_API();
	$result = $api->create_shipment( $order );
	wp_send_json( $result );
}

/**
 * Download a previously generated FedEx label.
 */
function lwc_fedex_download_label() {
	check_ajax_referer( 'lwc_fedex_connection_check', 'nonce' );

	$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
	if ( ! $order_id ) {
		wp_die( __( 'Order ID is required.', 'lovecatz-wc' ) );
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		wp_die( __( 'Order not found.', 'lovecatz-wc' ) );
	}

	$filename = $order->get_meta( '_lwc_fedex_label_path' );
	$filename = is_string( $filename ) ? basename( $filename ) : '';
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
	$methods['lwc_shipping_jt'] = 'LWC_Shipping_JT';
	$methods['lwc_jt'] = 'LWC_Shipping_JT';
	$methods['lwc_shipping_fedex'] = 'LWC_Shipping_FedEx';
	$methods['lwc_fedex'] = 'LWC_Shipping_FedEx';

	return $methods;
}

/**
 * Initialize plugin data on activation.
 */
function lwc_activate() {
	if ( ! defined( 'ABSPATH' ) ) {
		return;
	}

	if ( ! function_exists( 'lwc_is_woocommerce_active' ) ) {
		return;
	}

	if ( ! lwc_is_woocommerce_active() ) {
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
		LWC_JT_Account::create_table();
	}

	if ( function_exists( 'add_rewrite_endpoint' ) ) {
		add_rewrite_endpoint( 'coupon', EP_ROOT | EP_PAGES );
	}

	if ( function_exists( 'flush_rewrite_rules' ) ) {
		flush_rewrite_rules();
		/* Mark that the coupon endpoint has been flushed so init-time helper doesn't run unnecessarily. */
		update_option( 'lwc_coupon_endpoint_rewrite_flushed', 'yes' );
	}
}

/**
 * Clean up plugin data on uninstall.
 */
function lwc_uninstall() {
	if ( ! defined( 'ABSPATH' ) ) {
		return;
	}

	global $wpdb;

	$tables = array(
		$wpdb->prefix . 'lwc_fedex_accounts',
		$wpdb->prefix . 'lwc_jt_accounts',
	);

	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
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


