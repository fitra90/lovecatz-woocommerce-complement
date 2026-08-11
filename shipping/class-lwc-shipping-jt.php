<?php
/**
 * Compatibility wrapper for the modular J&T shipping class.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'LWC_Shipping_Provider' ) ) {
	require_once dirname( __FILE__ ) . '/../includes/shipping/class-lwc-shipping-provider.php';
}

if ( ! class_exists( 'LWC_Shipping_JT' ) ) {
	require_once dirname( __FILE__ ) . '/../includes/shipping/class-lwc-shipping-jt.php';
}

if ( ! class_exists( 'WC_Shipping_J_And_T', false ) ) {
	class_alias( 'LWC_Shipping_JT', 'WC_Shipping_J_And_T' );
}
