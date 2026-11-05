<?php
/**
 * Shipping → General settings and the checkout default delivery mode.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the provider-independent shipping settings.
 *
 * WooCommerce's Checkout Block shows a "Ship / Pickup" switch whenever local
 * pickup is enabled. A free pickup rate ends up first after rates are sorted
 * by price, and the shipping accordion script used to click that first radio,
 * so checkout opened on self pickup. The setting below makes the initial mode
 * configurable; customers can still change it during checkout.
 */
class LWC_Shipping_General_Settings {

	const OPT_DEFAULT_DELIVERY = 'lwc_shipping_default_delivery';
	const SETTINGS_GROUP       = 'lwc_shipping_general_options';
	const SETTINGS_SECTION     = 'lwc_shipping_general_section';

	/**
	 * Supported values for the default delivery option.
	 *
	 * @var string[]
	 */
	private static $modes = array( 'ship', 'pickup', 'woocommerce' );

	/**
	 * Register the runtime hooks. Runs on every request, not only in admin.
	 */
	public function init() {
		add_filter( 'woocommerce_shipping_chosen_method', array( $this, 'filter_default_shipping_method' ), 20, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'localize_checkout_preference' ), 20 );
	}

	/**
	 * Register the settings group used by the General shipping sub-tab.
	 * Called from LWC_Admin_Settings::register_settings().
	 */
	public static function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPT_DEFAULT_DELIVERY,
			array(
				'default'           => 'ship',
				'sanitize_callback' => array( __CLASS__, 'sanitize_mode' ),
			)
		);

		add_settings_section(
			self::SETTINGS_SECTION,
			__( 'Checkout Defaults', 'lovecatz-wc' ),
			array( __CLASS__, 'render_section_intro' ),
			self::SETTINGS_GROUP
		);

		add_settings_field(
			self::OPT_DEFAULT_DELIVERY,
			__( 'Default delivery option', 'lovecatz-wc' ),
			array( __CLASS__, 'render_default_delivery_field' ),
			self::SETTINGS_GROUP,
			self::SETTINGS_SECTION
		);
	}

	/**
	 * Normalize the saved mode. Unknown or missing values fall back to "ship".
	 *
	 * @param mixed $value Raw option value.
	 * @return string
	 */
	public static function sanitize_mode( $value ) {
		$value = sanitize_key( (string) $value );

		return in_array( $value, self::$modes, true ) ? $value : 'ship';
	}

	/**
	 * Resolve the configured checkout default.
	 *
	 * @return string ship|pickup|woocommerce
	 */
	public static function get_default_delivery() {
		return self::sanitize_mode( get_option( self::OPT_DEFAULT_DELIVERY, 'ship' ) );
	}

	/** Intro copy for the General settings section. */
	public static function render_section_intro() {
		echo '<p>' . esc_html__( 'Settings that apply to checkout regardless of the courier used. These control the initial selection only — customers can still choose a different option on the checkout page.', 'lovecatz-wc' ) . '</p>';
	}

	/** Render the Ship / Self pickup / WooCommerce default radios. */
	public static function render_default_delivery_field() {
		$value   = self::get_default_delivery();
		$options = array(
			'ship'        => array(
				__( 'Delivery / Ship', 'lovecatz-wc' ),
				__( 'Checkout opens with courier delivery selected instead of self pickup. The lowest-priced delivery rate is preselected.', 'lovecatz-wc' ),
			),
			'pickup'      => array(
				__( 'Self pickup', 'lovecatz-wc' ),
				__( 'Checkout opens with local pickup selected. Requires an enabled local pickup method in WooCommerce.', 'lovecatz-wc' ),
			),
			'woocommerce' => array(
				__( 'WooCommerce default (no override)', 'lovecatz-wc' ),
				__( 'Leave WooCommerce in charge of the initial shipping method. The plugin will not preselect a delivery or pickup rate.', 'lovecatz-wc' ),
			),
		);

		echo '<fieldset class="lwc-shipping-general-options">';
		foreach ( $options as $mode => $labels ) {
			printf(
				'<label><input type="radio" name="%1$s" value="%2$s" %3$s /> <strong>%4$s</strong><span class="description">%5$s</span></label>',
				esc_attr( self::OPT_DEFAULT_DELIVERY ),
				esc_attr( $mode ),
				checked( $value, $mode, false ),
				esc_html( $labels[0] ),
				esc_html( $labels[1] )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * WooCommerce shipping method ids that count as local/self pickup.
	 *
	 * The Store API helper is preferred because third-party pickup methods can
	 * register the "local-pickup" feature. The two built-in ids are kept as a
	 * fallback for classic checkout and older WooCommerce versions.
	 *
	 * @return string[]
	 */
	public static function get_pickup_method_ids() {
		$ids   = array( 'local_pickup', 'pickup_location' );
		$utils = 'Automattic\WooCommerce\StoreApi\Utilities\LocalPickupUtils';

		if ( class_exists( $utils ) && method_exists( $utils, 'get_local_pickup_method_ids' ) && function_exists( 'WC' ) && WC() ) {
			$ids = array_merge( $ids, (array) call_user_func( array( $utils, 'get_local_pickup_method_ids' ) ) );
		}

		return array_values( array_unique( array_filter( array_map( 'strval', $ids ) ) ) );
	}

	/**
	 * Tell whether one shipping rate is a self-pickup rate.
	 *
	 * @param string                $rate_id Rate id, e.g. "pickup_location:1".
	 * @param WC_Shipping_Rate|null $rate    Optional rate object.
	 * @return bool
	 */
	public static function is_pickup_rate( $rate_id, $rate = null ) {
		$ids       = self::get_pickup_method_ids();
		$method_id = (string) strtok( (string) $rate_id, ':' );

		if ( in_array( $method_id, $ids, true ) ) {
			return true;
		}

		return is_object( $rate ) && method_exists( $rate, 'get_method_id' ) && in_array( (string) $rate->get_method_id(), $ids, true );
	}

	/**
	 * Choose the default shipping rate by delivery mode.
	 *
	 * Only runs when WooCommerce has to compute a default. A still-valid
	 * explicit customer selection is left untouched, so switching to pickup
	 * during checkout is never undone by a later rate recalculation.
	 *
	 * @param string $default       WooCommerce's proposed default rate id.
	 * @param array  $rates         Available rates for the package.
	 * @param string $chosen_method Previously chosen rate id.
	 * @return string
	 */
	public function filter_default_shipping_method( $default, $rates, $chosen_method ) {
		$mode = self::get_default_delivery();

		if ( 'woocommerce' === $mode || ! is_array( $rates ) || empty( $rates ) ) {
			return $default;
		}

		if ( $chosen_method && isset( $rates[ $chosen_method ] ) ) {
			return $default;
		}

		$pickup   = array();
		$delivery = array();
		foreach ( $rates as $rate_id => $rate ) {
			if ( self::is_pickup_rate( $rate_id, $rate ) ) {
				$pickup[] = (string) $rate_id;
			} else {
				$delivery[] = (string) $rate_id;
			}
		}

		if ( 'ship' === $mode && ! empty( $delivery ) ) {
			return $delivery[0];
		}

		if ( 'pickup' === $mode && ! empty( $pickup ) ) {
			return $pickup[0];
		}

		return $default;
	}

	/**
	 * Pass the preference to the checkout shipping script.
	 *
	 * Hooked at priority 20 so the accordion script (priority 10) is already
	 * enqueued. wp_localize_script() attaches data to an enqueued handle.
	 */
	public function localize_checkout_preference() {
		wp_localize_script(
			'lwc-shipping-accordion',
			'lwcShippingDefault',
			array(
				'mode'            => self::get_default_delivery(),
				'pickupMethodIds' => self::get_pickup_method_ids(),
			)
		);
	}
}
