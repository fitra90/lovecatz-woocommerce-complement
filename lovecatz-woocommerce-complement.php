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

	// Include core class.
	require_once LWC_PLUGIN_DIR . 'includes/class-lwc-logger.php';
	require_once LWC_PLUGIN_DIR . 'includes/class-lwc-core.php';

	// Run the core class.
	$core = new LWC_Core();
	$core->init();
}
add_action( 'plugins_loaded', 'lwc_init', 20 );

register_uninstall_hook( __FILE__, 'lwc_uninstall' );

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
