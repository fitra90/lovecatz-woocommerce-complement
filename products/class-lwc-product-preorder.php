<?php
/**
 * Lightweight product pre-order support for out-of-stock WooCommerce products.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_Product_Preorder {

	/** @var bool|null */
	private $all_products = null;

	/** @var array|null */
	private $selected_products = null;

	/** Register pre-order availability, cart, and order hooks. */
	public function init() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		add_filter( 'woocommerce_product_is_in_stock', array( $this, 'allow_preorder_stock' ), 20, 2 );
		add_filter( 'woocommerce_product_backorders_allowed', array( $this, 'allow_preorder_backorders' ), 20, 3 );
		add_filter( 'woocommerce_product_backorders_require_notification', array( $this, 'require_preorder_notification' ), 20, 2 );
		add_filter( 'woocommerce_get_availability_text', array( $this, 'filter_availability_text' ), 20, 2 );
		add_filter( 'woocommerce_get_availability_class', array( $this, 'filter_availability_class' ), 20, 2 );
		add_filter( 'woocommerce_product_single_add_to_cart_text', array( $this, 'filter_add_to_cart_text' ), 20, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( $this, 'filter_loop_add_to_cart_text' ), 20, 2 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'mark_preorder_cart_item' ), 20, 4 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'render_preorder_cart_item' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'mark_preorder_order_item' ), 20, 4 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'add_preorder_order_note' ), 20 );
	}

	/** Make an eligible out-of-stock product purchasable. */
	public function allow_preorder_stock( $in_stock, $product ) {
		return $this->requires_preorder( $product ) ? true : $in_stock;
	}

	/** Enable WooCommerce's stock-safe backorder path for an eligible product. */
	public function allow_preorder_backorders( $allowed, $product_id, $product ) {
		return $this->requires_preorder( $product ) ? true : $allowed;
	}

	/** Keep WooCommerce's customer-facing backorder notification enabled. */
	public function require_preorder_notification( $required, $product ) {
		return $this->requires_preorder( $product ) ? true : $required;
	}

	/** Replace the generic backorder wording on eligible products. */
	public function filter_availability_text( $text, $product ) {
		return $this->requires_preorder( $product ) ? __( 'Available for pre-order', 'lovecatz-wc' ) : $text;
	}

	/** Add a stable class which themes may style without being required to do so. */
	public function filter_availability_class( $class, $product ) {
		return $this->requires_preorder( $product ) ? 'available-on-preorder' : $class;
	}

	/** Change the single-product purchase button for a pre-order item. */
	public function filter_add_to_cart_text( $text, $product ) {
		return $this->requires_preorder( $product ) ? __( 'Pre-order', 'lovecatz-wc' ) : $text;
	}

	/** Change shop-loop buttons for simple pre-order products only. */
	public function filter_loop_add_to_cart_text( $text, $product ) {
		if ( $this->requires_preorder( $product ) && is_object( $product ) && $product->is_type( 'simple' ) ) {
			return __( 'Pre-order', 'lovecatz-wc' );
		}

		return $text;
	}

	/** Preserve pre-order status at the moment the product enters the cart. */
	public function mark_preorder_cart_item( $cart_item_data, $product_id, $variation_id, $quantity ) {
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( $this->requires_preorder( $product ) ) {
			$cart_item_data['_lwc_preorder'] = 'yes';
		}

		return $cart_item_data;
	}

	/** Show the pre-order marker in classic cart/checkout and Checkout Blocks. */
	public function render_preorder_cart_item( $item_data, $cart_item ) {
		if ( isset( $cart_item['_lwc_preorder'] ) && 'yes' === $cart_item['_lwc_preorder'] ) {
			$item_data[] = array(
				'key'   => __( 'Pre-order', 'lovecatz-wc' ),
				'value' => __( 'Awaiting stock availability', 'lovecatz-wc' ),
			);
		}

		return $item_data;
	}

	/** Copy the immutable pre-order marker to the WooCommerce order line. */
	public function mark_preorder_order_item( $item, $cart_item_key, $values, $order ) {
		if ( isset( $values['_lwc_preorder'] ) && 'yes' === $values['_lwc_preorder'] ) {
			$item->add_meta_data( '_lwc_preorder', 'yes', true );
			$item->add_meta_data( __( 'Pre-order', 'lovecatz-wc' ), __( 'Awaiting stock availability', 'lovecatz-wc' ), true );
		}
	}

	/** Add one fulfillment warning to orders containing one or more pre-order lines. */
	public function add_preorder_order_note( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			if ( 'yes' === $item->get_meta( '_lwc_preorder', true ) ) {
				$order->add_order_note( __( 'This order contains pre-order product(s) awaiting stock availability.', 'lovecatz-wc' ) );
				return;
			}
		}
	}

	/** Determine whether this product should use the pre-order path right now. */
	private function requires_preorder( $product ) {
		if ( ! $product instanceof WC_Product || ! $this->is_product_eligible( $product ) ) {
			return false;
		}

		return in_array( $product->get_stock_status( 'edit' ), array( 'outofstock', 'onbackorder' ), true );
	}

	/** Resolve global or selective eligibility; variations inherit their parent. */
	private function is_product_eligible( $product ) {
		if ( null === $this->all_products ) {
			$this->all_products = 'yes' === get_option( 'lwc_preorder_all_products', 'yes' );
		}
		if ( $this->all_products ) {
			return true;
		}

		if ( null === $this->selected_products ) {
			$ids = array_filter( array_map( 'absint', (array) get_option( 'lwc_preorder_product_ids', array() ) ) );
			$this->selected_products = array_fill_keys( $ids, true );
		}

		$product_id = $product->get_id();
		$parent_id  = $product->is_type( 'variation' ) ? $product->get_parent_id() : 0;

		return isset( $this->selected_products[ $product_id ] ) || ( $parent_id && isset( $this->selected_products[ $parent_id ] ) );
	}

	/** Feature switch. */
	private function is_enabled() {
		return 'yes' === get_option( 'lwc_enable_product_preorder', 'no' );
	}
}
