<?php
/**
 * Compatibility wrapper for the modular FedEx shipping class.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'LWC_Shipping_Provider' ) ) {
	require_once dirname( __FILE__ ) . '/../includes/shipping/class-lwc-shipping-provider.php';
}

if ( ! class_exists( 'LWC_Shipping_FedEx' ) ) {
	require_once dirname( __FILE__ ) . '/../includes/shipping/class-lwc-shipping-fedex.php';
}

if ( ! class_exists( 'WC_Shipping_FedEx', false ) ) {
	class_alias( 'LWC_Shipping_FedEx', 'WC_Shipping_FedEx' );
}
