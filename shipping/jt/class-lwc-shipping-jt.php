<?php
/**
 * COMPATIBILITY WRAPPER FOR INCLUDES/shipping
 *
 * Include canonical shipping implementation from includes/shipping.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'LWC_Shipping_Provider' ) && file_exists( LWC_PLUGIN_DIR . 'includes/shipping/class-lwc-shipping-provider.php' ) ) {
    require_once LWC_PLUGIN_DIR . 'includes/shipping/class-lwc-shipping-provider.php';
}
if ( ! class_exists( 'LWC_Shipping_JT' ) && file_exists( LWC_PLUGIN_DIR . 'includes/shipping/jt/class-lwc-shipping-jt.php' ) ) {
    require_once LWC_PLUGIN_DIR . 'includes/shipping/jt/class-lwc-shipping-jt.php';
}
if ( ! class_exists( 'WC_Shipping_J_And_T', false ) ) {
    class_alias( 'LWC_Shipping_JT', 'WC_Shipping_J_And_T' );
}
?>


