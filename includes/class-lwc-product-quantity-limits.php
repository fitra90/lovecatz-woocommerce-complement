<?php
/**
 * Product quantity limit integration for LoveCatz WooCommerce Complement.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_Product_Quantity_Limits {

	/**
	 * Initialize quantity hooks.
	 */
	public function init() {
		add_action( 'woocommerce_product_options_stock_fields', array( $this, 'add_minimum_quantity_field' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_minimum_quantity_field' ) );

		add_filter( 'woocommerce_quantity_input_args', array( $this, 'set_product_minimum_quantity_input_args' ), 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart_minimum_quantity' ), 10, 5 );
		add_filter( 'woocommerce_update_cart_validation', array( $this, 'validate_cart_item_minimum_quantity' ), 10, 4 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_item_quantities' ) );
	}

	/**
	 * Add minimum quantity field to the product inventory tab.
	 */
	public function add_minimum_quantity_field() {
		global $product;

		if ( ! is_object( $product ) ) {
			return;
		}

		$min_qty = get_post_meta( $product->get_id(), '_lwc_minimum_quantity', true );
		$min_qty = $min_qty ? absint( $min_qty ) : '';

		woocommerce_wp_text_input(
			array(
				'id'                => '_lwc_minimum_quantity',
				'label'             => __( 'Minimum Quantity', 'lovecatz-wc' ),
				'description'       => __( 'Require this minimum quantity when customers add this product to cart and at checkout.', 'lovecatz-wc' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => 1,
					'step' => 1,
				),
				'value'             => $min_qty,
			)
		);
	}

	/**
	 * Save the minimum quantity field value.
	 *
	 * @param int $post_id Product ID.
	 */
	public function save_minimum_quantity_field( $post_id ) {
		if ( isset( $_POST['_lwc_minimum_quantity'] ) ) {
			$min_qty = intval( wp_unslash( $_POST['_lwc_minimum_quantity'] ) );

			if ( $min_qty > 1 ) {
				update_post_meta( $post_id, '_lwc_minimum_quantity', $min_qty );
			} else {
				delete_post_meta( $post_id, '_lwc_minimum_quantity' );
			}
		}
	}

	/**
	 * Set the minimum quantity on the product quantity selector.
	 *
	 * @param array        $args    Quantity input args.
	 * @param WC_Product   $product Product object.
	 * @return array
	 */
	public function set_product_minimum_quantity_input_args( $args, $product ) {
		if ( ! is_object( $product ) ) {
			return $args;
		}

		$min_qty = $this->get_product_minimum_quantity( $product );
		if ( $min_qty > 1 ) {
			$args['min_value'] = $min_qty;
			$args['input_value'] = max( isset( $args['input_value'] ) ? intval( $args['input_value'] ) : 0, $min_qty );
		}

		return $args;
	}

	/**
	 * Validate minimum quantity when adding a product to cart.
	 *
	 * @param bool   $passed       Whether validation passed.
	 * @param int    $product_id   Product ID.
	 * @param int    $quantity     Quantity being added.
	 * @param int    $variation_id Variation ID.
	 * @param array  $variations   Variation attributes.
	 * @return bool
	 */
	public function validate_add_to_cart_minimum_quantity( $passed, $product_id, $quantity, $variation_id = 0, $variations = array() ) {
		$product = $variation_id ? wc_get_product( $variation_id ) : wc_get_product( $product_id );
		if ( ! is_object( $product ) ) {
			return $passed;
		}

		$min_qty = $this->get_product_minimum_quantity( $product );
		if ( $min_qty > 1 && $quantity < $min_qty ) {
			wc_add_notice( sprintf( __( 'Minimum quantity for %s is %d.', 'lovecatz-wc' ), $product->get_name(), $min_qty ), 'error' );
			return false;
		}

		return $passed;
	}

	/**
	 * Validate minimum quantity when updating cart quantities.
	 *
	 * @param bool  $passed        Validation result.
	 * @param string $cart_item_key Cart item key.
	 * @param array  $values       Cart item values.
	 * @param int    $quantity     New quantity.
	 * @return bool
	 */
	public function validate_cart_item_minimum_quantity( $passed, $cart_item_key, $values, $quantity ) {
		$product = isset( $values['data'] ) ? $values['data'] : null;
		if ( ! is_object( $product ) ) {
			return $passed;
		}

		$min_qty = $this->get_product_minimum_quantity( $product );
		if ( $min_qty > 1 && $quantity < $min_qty ) {
			wc_add_notice( sprintf( __( 'Minimum quantity for %s is %d.', 'lovecatz-wc' ), $product->get_name(), $min_qty ), 'error' );
			return false;
		}

		return $passed;
	}

	/**
	 * Check cart items before checkout and enforce minimum quantities.
	 */
	public function check_cart_item_quantities() {
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
			if ( ! is_object( $product ) ) {
				continue;
			}

			$min_qty = $this->get_product_minimum_quantity( $product );
			if ( $min_qty > 1 && $cart_item['quantity'] < $min_qty ) {
				wc_add_notice( sprintf( __( 'Minimum quantity for %s is %d.', 'lovecatz-wc' ), $product->get_name(), $min_qty ), 'error' );
			}
		}
	}

	/**
	 * Get the minimum quantity for the product or variation.
	 *
	 * @param WC_Product $product Product object.
	 * @return int
	 */
	private function get_product_minimum_quantity( $product ) {
		if ( ! is_object( $product ) ) {
			return 0;
		}

		$min_qty = get_post_meta( $product->get_id(), '_lwc_minimum_quantity', true );
		$min_qty = $min_qty ? absint( $min_qty ) : 0;

		if ( $min_qty > 1 ) {
			return $min_qty;
		}

		if ( $product->is_type( 'variation' ) ) {
			$parent_id = $product->get_parent_id();
			if ( $parent_id ) {
				$parent_min_qty = get_post_meta( $parent_id, '_lwc_minimum_quantity', true );
				return $parent_min_qty ? absint( $parent_min_qty ) : 0;
			}
		}

		return 0;
	}
}
