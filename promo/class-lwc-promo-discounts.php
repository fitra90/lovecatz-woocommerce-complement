<?php
/**
 * WooCommerce-native promo discount calculations.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keep discount calculations available to classic AJAX and the Store API. */
class LWC_Promo_Discounts {

	/** Coupon type used for a discount against the currently selected shipping rate. */
	const FREE_SHIPPING_TYPE = 'lwc_free_shipping';

	/** Cached proportional cap factors for the current cart calculation. */
	private $percentage_cap_factors = array();

	/** Register coupon types and cart calculations on every request context. */
	public function init() {
		add_filter( 'woocommerce_coupon_discount_types', array( $this, 'register_coupon_type' ) );
		add_filter( 'woocommerce_cart_coupon_types', array( $this, 'register_cart_coupon_type' ) );
		add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'limit_percentage_discount' ), 10, 5 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'reset_calculation_cache' ), PHP_INT_MAX );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_selected_shipping_discount' ), 20 );
	}

	/** Rebuild cap factors whenever WooCommerce starts a new totals calculation. */
	public function reset_calculation_cache() {
		$this->percentage_cap_factors = array();
	}

	/** Expose the type to WC_Coupon so it can be stored and validated normally. */
	public function register_coupon_type( $types ) {
		$types[ self::FREE_SHIPPING_TYPE ] = __( 'Free shipping', 'lovecatz-wc' );
		return $types;
	}

	/** Treat the type as a cart coupon rather than a product coupon. */
	public function register_cart_coupon_type( $types ) {
		if ( ! in_array( self::FREE_SHIPPING_TYPE, $types, true ) ) {
			$types[] = self::FREE_SHIPPING_TYPE;
		}
		return $types;
	}

	/**
	 * Offset the selected shipping tariff, optionally capped per coupon.
	 *
	 * WooCommerce calculates shipping before fees, so this always follows the
	 * customer's current rate selection. A negative fee is persisted as its own
	 * order line while the native coupon remains responsible for validation and
	 * usage limits.
	 *
	 * @param WC_Cart $cart Current cart.
	 */
	public function apply_selected_shipping_discount( $cart ) {
		if ( ! $cart instanceof WC_Cart || ( is_admin() && ! wp_doing_ajax() ) ) {
			return;
		}

		$remaining_shipping = max( 0, (float) $cart->get_shipping_total() );
		if ( $remaining_shipping <= 0 ) {
			return;
		}

		foreach ( $cart->get_coupons() as $coupon ) {
			if ( ! $coupon instanceof WC_Coupon || ! $coupon->is_type( self::FREE_SHIPPING_TYPE ) ) {
				continue;
			}

			$maximum  = max( 0, (float) get_post_meta( $coupon->get_id(), '_lwc_promo_maximum_discount', true ) );
			$discount = $maximum > 0 ? min( $remaining_shipping, $maximum ) : $remaining_shipping;
			if ( $discount <= 0 ) {
				continue;
			}

			$cart->add_fee(
				sprintf( __( 'Shipping discount (%s)', 'lovecatz-wc' ), strtoupper( $coupon->get_code() ) ),
				-$discount,
				false
			);
			$remaining_shipping -= $discount;
			if ( $remaining_shipping <= 0 ) {
				break;
			}
		}
	}

	/**
	 * Cap a percentage discount by proportionally scaling its item discounts.
	 *
	 * @param float     $discount    Calculated discount amount.
	 * @param float     $discounting Discounting amount.
	 * @param array     $cart_item   Cart item.
	 * @param bool      $single      Whether only one item is discounted.
	 * @param WC_Coupon $coupon      Coupon instance.
	 * @return float
	 */
	public function limit_percentage_discount( $discount, $discounting, $cart_item, $single, $coupon ) {
		unset( $discounting, $cart_item, $single );
		if ( ! $coupon instanceof WC_Coupon || 'percent' !== $coupon->get_discount_type() ) {
			return $discount;
		}

		$maximum = (float) get_post_meta( $coupon->get_id(), '_lwc_promo_maximum_discount', true );
		if ( $maximum <= 0 || ! WC()->cart ) {
			return $discount;
		}

		$cache_key = $coupon->get_id() ? (string) $coupon->get_id() : $coupon->get_code();
		if ( ! isset( $this->percentage_cap_factors[ $cache_key ] ) ) {
			$discountable_total = 0;
			foreach ( WC()->cart->get_cart() as $item ) {
				if ( ! isset( $item['line_subtotal'] ) ) {
					continue;
				}
				if ( isset( $item['data'] ) && $item['data'] instanceof WC_Product && ! $coupon->is_valid_for_product( $item['data'], $item ) ) {
					continue;
				}
				$discountable_total += (float) $item['line_subtotal'];
			}

			$uncapped_total = $discountable_total * ( (float) $coupon->get_amount() / 100 );
			$this->percentage_cap_factors[ $cache_key ] = $uncapped_total > $maximum ? $maximum / $uncapped_total : 1.0;
		}

		return $discount * $this->percentage_cap_factors[ $cache_key ];
	}
}
