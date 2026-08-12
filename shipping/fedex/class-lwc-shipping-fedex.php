<?php
/**
 * COMPATIBILITY WRAPPER FOR INCLUDES/shipping
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'LWC_Shipping_Provider' ) && file_exists( LWC_PLUGIN_DIR . 'includes/shipping/class-lwc-shipping-provider.php' ) ) {
    require_once LWC_PLUGIN_DIR . 'includes/shipping/class-lwc-shipping-provider.php';
}
if ( ! class_exists( 'LWC_Shipping_FedEx' ) && file_exists( LWC_PLUGIN_DIR . 'includes/shipping/fedex/class-lwc-shipping-fedex.php' ) ) {
    require_once LWC_PLUGIN_DIR . 'includes/shipping/fedex/class-lwc-shipping-fedex.php';
}
if ( ! class_exists( 'WC_Shipping_FedEx', false ) ) {
    class_alias( 'LWC_Shipping_FedEx', 'WC_Shipping_FedEx' );
}
?>


