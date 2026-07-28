<?php
/**
 * Logger class for LoveCatz WooCommerce Complement.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_Logger {

	/**
	 * Log a message using WooCommerce Logger.
	 *
	 * @param string $message The message to log.
	 * @param string $level   The log level (e.g., 'info', 'error', 'warning', 'debug'). Default 'info'.
	 * @param string $context The context/source of the log. Default 'lovecatz-wc'.
	 */
	public static function log( $message, $level = 'info', $context = 'lovecatz-wc' ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			$logger->log( $level, $message, array( 'source' => $context ) );
		}
	}
}
