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

	/** Coupon meta key holding the IDR maximum discount. Kept for backward compatibility. */
	const META_MAXIMUM = '_lwc_promo_maximum_discount';

	/** Coupon meta key holding the USD maximum discount. */
	const META_MAXIMUM_USD = '_lwc_promo_maximum_discount_usd';

	const CURRENCY_IDR = 'IDR';
	const CURRENCY_USD = 'USD';

	/** Cached proportional cap factors for the current cart calculation. */
	private $percentage_cap_factors = array();

	/**
	 * The currency the current cart is priced in.
	 *
	 * get_woocommerce_currency() is filtered by the currency converter, so it
	 * reports the shopper currency rather than the stored base currency.
	 *
	 * @return string
	 */
	public static function active_currency() {
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		$currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $currency ) );

		if ( '' !== $currency ) {
			return $currency;
		}

		return strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) get_option( 'woocommerce_currency', '' ) ) );
	}

	/**
	 * The maximum discount that applies to a currency, or 0 when there is no cap.
	 *
	 * The value is always whatever the shop owner typed into the matching field
	 * for this promo type and currency. Nothing is defaulted, derived, or
	 * converted: an empty field means the promo is genuinely uncapped in that
	 * currency. Comparing a shopper-currency discount against another currency's
	 * cap silently removed the ceiling, which is why the caller must resolve the
	 * cap in the currency actually being charged.
	 *
	 * @param WC_Coupon $coupon   Coupon instance.
	 * @param string    $currency Currency code; empty means the active currency.
	 * @return float
	 */
	public static function get_maximum_discount( $coupon, $currency = '' ) {
		if ( ! $coupon instanceof WC_Coupon || ! $coupon->get_id() ) {
			return 0.0;
		}

		$currency = '' !== (string) $currency ? strtoupper( (string) $currency ) : self::active_currency();
		if ( ! in_array( $currency, array( self::CURRENCY_IDR, self::CURRENCY_USD ), true ) ) {
			return 0.0;
		}

		$key = self::CURRENCY_USD === $currency ? self::META_MAXIMUM_USD : self::META_MAXIMUM;

		return max( 0, (float) get_post_meta( $coupon->get_id(), $key, true ) );
	}

	/**
	 * Plain-text money string for a specific currency.
	 *
	 * wc_price() returns HTML entities for many symbols (WooCommerce stores USD
	 * as "&#36;"). Both the admin coupon list and the checkout coupon card escape
	 * the string again before output, so an undecoded entity would be shown
	 * verbatim as "&#36;9" instead of "$9".
	 *
	 * @param float  $amount   Amount to format.
	 * @param string $currency Currency code; empty means the active currency.
	 * @return string
	 */
	public static function format_money( $amount, $currency = '' ) {
		if ( ! function_exists( 'wc_price' ) ) {
			return (string) $amount;
		}

		$args  = '' !== (string) $currency ? array( 'currency' => strtoupper( (string) $currency ) ) : array();
		$price = wc_price( (float) $amount, $args );

		return html_entity_decode( wp_strip_all_tags( $price ), ENT_QUOTES, 'UTF-8' );
	}

	/** Register coupon types and cart calculations on every request context. */
	public function init() {
		add_filter( 'woocommerce_coupon_discount_types', array( $this, 'register_coupon_type' ) );
		add_filter( 'woocommerce_cart_coupon_types', array( $this, 'register_cart_coupon_type' ) );
		add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'limit_percentage_discount' ), 10, 5 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'reset_calculation_cache' ), PHP_INT_MAX );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_selected_shipping_discount' ), 20 );
		add_action( 'woocommerce_checkout_create_order_fee_item', array( $this, 'mark_shipping_discount_order_item' ), 10, 4 );
		add_filter( 'gettext', array( $this, 'rename_admin_shipping_discount_total' ), 20, 3 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate_coupon_combination' ), 20, 3 );
		add_filter( 'woocommerce_coupon_error', array( $this, 'combination_error_message' ), 20, 3 );
	}

	/** Whether a coupon is configured to support combinations. */
	public static function allows_combination( $coupon ) {
		if ( ! $coupon instanceof WC_Coupon ) {
			return false;
		}

		if ( metadata_exists( 'post', $coupon->get_id(), '_lwc_promo_allow_combination' ) ) {
			return 'yes' === get_post_meta( $coupon->get_id(), '_lwc_promo_allow_combination', true );
		}

		return ! $coupon->get_individual_use();
	}

	/** Return null for all coupons, or the explicitly allowed coupon IDs. */
	public static function get_combination_coupon_ids( $coupon ) {
		if ( ! $coupon instanceof WC_Coupon || ! metadata_exists( 'post', $coupon->get_id(), '_lwc_promo_combination_coupon_ids' ) ) {
			return null;
		}

		$stored = get_post_meta( $coupon->get_id(), '_lwc_promo_combination_coupon_ids', true );
		return 'all' === $stored ? null : array_values( array_unique( array_filter( array_map( 'absint', (array) $stored ) ) ) );
	}

	/** Check the pair in both directions so application order cannot bypass a rule. */
	public static function coupons_can_combine( $first, $second ) {
		if ( ! self::allows_combination( $first ) || ! self::allows_combination( $second ) ) {
			return false;
		}

		$first_ids  = self::get_combination_coupon_ids( $first );
		$second_ids = self::get_combination_coupon_ids( $second );
		return ( null === $first_ids || in_array( $second->get_id(), $first_ids, true ) )
			&& ( null === $second_ids || in_array( $first->get_id(), $second_ids, true ) );
	}

	/** Reject a whitelist mismatch against coupons already applied to the cart/order. */
	public function validate_coupon_combination( $valid, $coupon, $discounts ) {
		if ( ! $valid || ! $coupon instanceof WC_Coupon || ! self::allows_combination( $coupon ) ) {
			return $valid;
		}

		return $this->has_combination_conflict( $coupon, $discounts ) ? false : $valid;
	}

	/** Give the customer an actionable message for a combination whitelist failure. */
	public function combination_error_message( $message, $error_code, $coupon ) {
		if ( WC_Coupon::E_WC_COUPON_INVALID_FILTERED !== (int) $error_code || ! $coupon instanceof WC_Coupon || ! WC()->cart ) {
			return $message;
		}

		$discounts = class_exists( 'WC_Discounts' ) ? new WC_Discounts( WC()->cart ) : null;
		if ( $discounts && $this->has_combination_conflict( $coupon, $discounts ) ) {
			return sprintf( __( 'Coupon "%s" cannot be combined with one or more coupons already applied.', 'lovecatz-wc' ), $coupon->get_code() );
		}

		return $message;
	}

	/** Determine whether an applied coupon conflicts with a candidate coupon. */
	private function has_combination_conflict( $coupon, $discounts ) {
		if ( ! $discounts instanceof WC_Discounts || ! method_exists( $discounts, 'get_object' ) ) {
			return false;
		}

		$object = $discounts->get_object();
		$codes  = $object instanceof WC_Cart ? $object->get_applied_coupons() : ( $object instanceof WC_Order ? $object->get_coupon_codes() : array() );
		foreach ( $codes as $code ) {
			$applied = new WC_Coupon( $code );
			if ( $applied->get_id() && $applied->get_id() !== $coupon->get_id() && self::allows_combination( $applied ) && ! self::coupons_can_combine( $coupon, $applied ) ) {
				return true;
			}
		}

		return false;
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

			// The cap comes from the shipping promo's own per-currency field, while
			// the remaining shipping cost is already in the shopper currency.
			// Resolving both in the same currency keeps the ceiling meaningful
			// after a currency switch.
			$maximum  = self::get_maximum_discount( $coupon );
			$discount = $maximum > 0 ? min( $remaining_shipping, $maximum ) : $remaining_shipping;
			if ( $discount <= 0 ) {
				continue;
			}

			$cart->add_fee(
				sprintf( __( 'Shipping Discount (%s)', 'lovecatz-wc' ), strtoupper( $coupon->get_code() ) ),
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
	 * Mark shipping-discount fee items so their purpose survives on the order.
	 *
	 * WooCommerce has no native coupon type that reduces a selected paid shipping
	 * rate. The discount therefore has to be stored as a negative fee, but this
	 * private marker lets reporting and presentation distinguish it from a fee.
	 *
	 * @param WC_Order_Item_Fee $item    Order fee item.
	 * @param string            $fee_key Cart fee key.
	 * @param object            $fee     Cart fee data.
	 * @param WC_Order          $order   Order being created.
	 */
	public function mark_shipping_discount_order_item( $item, $fee_key, $fee, $order ) {
		unset( $fee_key, $order );
		$name = isset( $fee->name ) ? (string) $fee->name : '';
		if ( $item instanceof WC_Order_Item_Fee && 0 === stripos( $name, 'Shipping Discount (' ) ) {
			$item->add_meta_data( '_lwc_shipping_discount', 'yes', true );
		}
	}

	/**
	 * Rename WooCommerce's aggregate admin total when it contains only shipping discounts.
	 *
	 * The order editor groups every WC_Order_Item_Fee below a hard-coded "Fees:"
	 * heading. Limit the replacement to LoveCatz shipping-discount rows so genuine
	 * order fees keep WooCommerce's original label.
	 *
	 * @param string $translation Translated text.
	 * @param string $text        Original text.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function rename_admin_shipping_discount_total( $translation, $text, $domain ) {
		if ( ! is_admin() || 'woocommerce' !== $domain || 'Fees:' !== $text ) {
			return $translation;
		}

		$order_id = $this->get_current_admin_order_id();
		$order    = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order ) {
			return $translation;
		}

		$fees = $order->get_items( 'fee' );
		if ( empty( $fees ) ) {
			return $translation;
		}

		foreach ( $fees as $fee ) {
			$is_shipping_discount = 'yes' === $fee->get_meta( '_lwc_shipping_discount', true )
				|| 0 === stripos( (string) $fee->get_name(), 'Shipping discount (' );
			if ( ! $is_shipping_discount ) {
				return $translation;
			}
		}

		return __( 'Shipping Discount:', 'lovecatz-wc' );
	}

	/** Resolve the edited order ID for both classic and HPOS order screens. */
	private function get_current_admin_order_id() {
		foreach ( array( 'id', 'post', 'order_id' ) as $key ) {
			if ( isset( $_REQUEST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
				$order_id = absint( wp_unslash( $_REQUEST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( $order_id ) {
					return $order_id;
				}
			}
		}

		global $post;
		return $post instanceof WP_Post && 'shop_order' === $post->post_type ? (int) $post->ID : 0;
	}

	/**
	 * Cap a percentage discount by proportionally scaling its item discounts.
	 *
	 * The cart line subtotals and the discount are already in the shopper
	 * currency, so the cap has to be resolved in that same currency. Reading the
	 * stored base-currency number directly made the ceiling disappear for every
	 * converted currency.
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

		if ( ! WC()->cart ) {
			return $discount;
		}

		$currency = self::active_currency();
		$maximum  = self::get_maximum_discount( $coupon, $currency );
		if ( $maximum <= 0 ) {
			return $discount;
		}

		$cache_key = ( $coupon->get_id() ? (string) $coupon->get_id() : $coupon->get_code() ) . '|' . $currency;
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
