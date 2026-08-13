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
		add_action( 'woocommerce_product_options_stock_fields', array( $this, 'add_quantity_limit_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_quantity_limit_fields' ) );

		add_filter( 'woocommerce_quantity_input_args', array( $this, 'set_product_quantity_input_args' ), 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart_quantities' ), 10, 5 );
		add_filter( 'woocommerce_update_cart_validation', array( $this, 'validate_cart_item_quantities' ), 10, 4 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_item_quantities' ) );
	}

	/**
	 * Add quantity limit fields to the product inventory tab.
	 */
	public function add_quantity_limit_fields() {
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

		$max_qty = get_post_meta( $product->get_id(), '_lwc_maximum_quantity', true );
		$max_qty = $max_qty ? absint( $max_qty ) : '';

		woocommerce_wp_text_input(
			array(
				'id'                => '_lwc_maximum_quantity',
				'label'             => __( 'Maximum Quantity', 'lovecatz-wc' ),
				'description'       => __( 'Limit the maximum quantity customers can choose on the product page. Leave blank to allow stock-based maximums.', 'lovecatz-wc' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => 1,
					'step' => 1,
				),
				'value'             => $max_qty,
			)
		);
	}

	/**
	 * Save the quantity limit field values.
	 *
	 * @param int $post_id Product ID.
	 */
	public function save_quantity_limit_fields( $post_id ) {
		// Ensure the current user can edit this product.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['_lwc_minimum_quantity'] ) ) {
			$min_qty = intval( wp_unslash( $_POST['_lwc_minimum_quantity'] ) );

			if ( $min_qty > 1 ) {
				update_post_meta( $post_id, '_lwc_minimum_quantity', $min_qty );
			} else {
				delete_post_meta( $post_id, '_lwc_minimum_quantity' );
			}
		}

		if ( isset( $_POST['_lwc_maximum_quantity'] ) ) {
			$max_qty = intval( wp_unslash( $_POST['_lwc_maximum_quantity'] ) );

			if ( $max_qty > 0 ) {
				update_post_meta( $post_id, '_lwc_maximum_quantity', $max_qty );
			} else {
				delete_post_meta( $post_id, '_lwc_maximum_quantity' );
			}
		}
	}

	/**
	 * Set product quantity limits on the quantity selector.
	 *
	 * This filter is applied by:
	 * 1. Classic forms: wc_get_quantity_input_args() for product pages and classic cart
	 * 2. Store API (Blocks): QuantityLimits::get_add_to_cart_limits() → wc_get_quantity_input_args()
	 *
	 * This single filter handles quantity limits for BOTH classic and modern (Blocks) implementations.
	 *
	 * @param array      $args    Quantity input args.
	 * @param WC_Product $product Product object.
	 * @return array
	 */
	public function set_product_quantity_input_args( $args, $product ) {
		if ( ! is_object( $product ) ) {
			return $args;
		}

		$min_qty = $this->get_product_minimum_quantity( $product );
		$max_qty = $this->get_product_maximum_quantity( $product );

		// Resolve final minimum quantity: use custom minimum if set, otherwise keep WooCommerce default.
		$final_min = $min_qty > 1 ? $min_qty : ( isset( $args['min_value'] ) ? intval( $args['min_value'] ) : 1 );

		// Resolve final maximum quantity: use custom maximum if set, otherwise normalize WooCommerce value.
		$final_max = 0;
		if ( $max_qty > 0 ) {
			// Custom maximum from product meta or default option.
			$final_max = $max_qty;
		} elseif ( isset( $args['max_value'] ) && intval( $args['max_value'] ) > 0 ) {
			// WooCommerce native max (if positive).
			$final_max = intval( $args['max_value'] );
		}
		// Note: if $max_qty is 0 and $args['max_value'] is -1 or ≤ 0, $final_max remains 0 (unlimited).

		// Ensure max_value >= min_value when both are set.
		if ( $final_max > 0 && $final_max < $final_min ) {
			$final_max = $final_min;
		}

		// Apply normalized min/max to args.
		$args['min_value'] = $final_min;
		if ( $final_max > 0 ) {
			$args['max_value'] = $final_max;
		} else {
			// Remove max_value to prevent negative values from reaching the HTML output.
			unset( $args['max_value'] );
		}

		// Calculate default input_value based on normalized min/max constraints.
		$default_input = isset( $args['input_value'] ) ? intval( $args['input_value'] ) : 1;

		// Clamp input_value to [min_value, max_value].
		if ( $default_input < $final_min ) {
			$default_input = $final_min;
		}
		if ( $final_max > 0 && $default_input > $final_max ) {
			$default_input = $final_max;
		}

		$args['input_value'] = $default_input;

		return $args;
	}

	/**
	 * Validate product quantities when adding to cart.
	 *
	 * @param bool   $passed       Whether validation passed.
	 * @param int    $product_id   Product ID.
	 * @param int    $quantity     Quantity being added.
	 * @param int    $variation_id Variation ID.
	 * @param array  $variations   Variation attributes.
	 * @return bool
	 */
	public function validate_add_to_cart_quantities( $passed, $product_id, $quantity, $variation_id = 0, $variations = array() ) {
		$product = $variation_id ? wc_get_product( $variation_id ) : wc_get_product( $product_id );
		if ( ! is_object( $product ) ) {
			return $passed;
		}

		$min_qty = $this->get_product_minimum_quantity( $product );
		if ( $min_qty > 1 && $quantity < $min_qty ) {
			wc_add_notice( sprintf( __( 'Minimum quantity for %s is %d.', 'lovecatz-wc' ), $product->get_name(), $min_qty ), 'error' );
			return false;
		}

		$max_qty = $this->get_product_maximum_quantity( $product );
		if ( $max_qty > 0 && $quantity > $max_qty ) {
			wc_add_notice( sprintf( __( 'Maximum quantity for %s is %d.', 'lovecatz-wc' ), $product->get_name(), $max_qty ), 'error' );
			return false;
		}

		return $passed;
	}

	/**
	 * Validate product quantities when updating cart quantities.
	 *
	 * @param bool   $passed        Validation result.
	 * @param string $cart_item_key Cart item key.
	 * @param array  $values       Cart item values.
	 * @param int    $quantity     New quantity.
	 * @return bool
	 */
	public function validate_cart_item_quantities( $passed, $cart_item_key, $values, $quantity ) {
		$product = isset( $values['data'] ) ? $values['data'] : null;
		if ( ! is_object( $product ) ) {
			return $passed;
		}

		$min_qty = $this->get_product_minimum_quantity( $product );
		if ( $min_qty > 1 && $quantity < $min_qty ) {
			wc_add_notice( sprintf( __( 'Minimum quantity for %s is %d.', 'lovecatz-wc' ), $product->get_name(), $min_qty ), 'error' );
			return false;
		}

		$max_qty = $this->get_product_maximum_quantity( $product );
		if ( $max_qty > 0 && $quantity > $max_qty ) {
			wc_add_notice( sprintf( __( 'Maximum quantity for %s is %d.', 'lovecatz-wc' ), $product->get_name(), $max_qty ), 'error' );
			return false;
		}

		return $passed;
	}

	/**
	 * Check cart items before checkout and enforce minimum and maximum quantities.
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

			$max_qty = $this->get_product_maximum_quantity( $product );
			if ( $max_qty > 0 && $cart_item['quantity'] > $max_qty ) {
				wc_add_notice( sprintf( __( 'Maximum quantity for %s is %d.', 'lovecatz-wc' ), $product->get_name(), $max_qty ), 'error' );
			}
		}
	}

	/**
	 * Get the maximum quantity for the product or variation.
	 *
	 * @param WC_Product $product Product object.
	 * @return int
	 */
	private function get_product_maximum_quantity( $product ) {
		if ( ! is_object( $product ) ) {
			return 0;
		}

		$max_qty = get_post_meta( $product->get_id(), '_lwc_maximum_quantity', true );
		$max_qty = $max_qty ? absint( $max_qty ) : 0;

		if ( $product->is_type( 'variation' ) ) {
			$parent_id = $product->get_parent_id();
			if ( $parent_id ) {
				$parent_max_qty = get_post_meta( $parent_id, '_lwc_maximum_quantity', true );
				$parent_max_qty = $parent_max_qty ? absint( $parent_max_qty ) : 0;
				if ( $parent_max_qty > $max_qty ) {
					$max_qty = $parent_max_qty;
				}
			}
		}

		if ( $max_qty > 0 ) {
			$min_qty = $this->get_product_minimum_quantity( $product );
			if ( $min_qty > 0 && $max_qty < $min_qty ) {
				$max_qty = $min_qty;
			}
		} else {
			$default_max_qty = absint( get_option( 'lwc_product_default_maximum_quantity', 0 ) );
			if ( $default_max_qty > 0 ) {
				$max_qty = $default_max_qty;
			}
		}

		return $max_qty;
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
				$parent_min_qty = $parent_min_qty ? absint( $parent_min_qty ) : 0;
				if ( $parent_min_qty > 1 ) {
					return $parent_min_qty;
				}
			}
		}

		$default_min_qty = absint( get_option( 'lwc_product_default_minimum_quantity', 0 ) );
		if ( $default_min_qty > 1 ) {
			return $default_min_qty;
		}

		return 0;
	}
}
