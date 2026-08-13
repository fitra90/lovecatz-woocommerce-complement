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
		if ( ! $this->is_enabled() ) {
			return;
		}

		// This hook is outside WooCommerce's show_if_manage_stock container,
		// so quantity limits remain available for backorder products.
		add_action( 'woocommerce_product_options_sku', array( $this, 'add_quantity_limit_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_quantity_limit_fields' ) );
		add_action( 'quick_edit_custom_box', array( $this, 'add_quick_edit_fields' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'add_quick_edit_product_data' ), 10, 2 );
		add_action( 'admin_head-edit.php', array( $this, 'render_quick_edit_styles' ) );
		add_action( 'admin_footer-edit.php', array( $this, 'render_quick_edit_script' ) );
		add_action( 'save_post_product', array( $this, 'save_quick_edit_fields' ), 10, 2 );
		add_action( 'wp_footer', array( $this, 'render_frontend_quantity_control' ) );

		add_filter( 'woocommerce_quantity_input_args', array( $this, 'set_product_quantity_input_args' ), 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart_quantities' ), 10, 5 );
		add_filter( 'woocommerce_update_cart_validation', array( $this, 'validate_cart_item_quantities' ), 10, 4 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_item_quantities' ) );
	}

	/**
	 * Add quantity limit fields to the product Inventory tab.
	 *
	 * The fields are deliberately independent from WooCommerce stock management.
	 */
	public function add_quantity_limit_fields() {
		global $post, $thepostid;

		// A product object is not always available on the new-product screen.
		// The Inventory fields must still render there, so use the post ID when it exists.
		$product_id = absint( $thepostid );
		if ( ! $product_id && isset( $post->ID ) ) {
			$product_id = absint( $post->ID );
		}

		$min_qty = $product_id ? get_post_meta( $product_id, '_lwc_minimum_quantity', true ) : '';
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

		$max_qty = $product_id ? get_post_meta( $product_id, '_lwc_maximum_quantity', true ) : '';
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
			$this->save_quantity_limit_meta( $post_id, '_lwc_minimum_quantity', '_lwc_minimum_quantity' );
		}

		if ( isset( $_POST['_lwc_maximum_quantity'] ) ) {
			$this->save_quantity_limit_meta( $post_id, '_lwc_maximum_quantity', '_lwc_maximum_quantity' );
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
	 * Keep the product-page quantity input within its configured limits while typing.
	 */
	public function render_frontend_quantity_control() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		?>
		<script>
		(function () {
			'use strict';

			function normalizeQuantity(input) {
				var quantity = Number(input.value);
				var minimum = Number(input.getAttribute('min'));
				var maximum = Number(input.getAttribute('max'));

				if (!Number.isFinite(quantity)) {
					return;
				}

				if (Number.isFinite(minimum) && minimum > 0 && quantity < minimum) {
					input.value = minimum;
					return;
				}

				if (Number.isFinite(maximum) && maximum > 0 && quantity > maximum) {
					input.value = maximum;
				}
			}

			function handleQuantityEvent(event) {
				if (event.target.matches('.single-product .quantity input.qty')) {
					normalizeQuantity(event.target);
				}
			}

			document.addEventListener('keyup', handleQuantityEvent);
			document.addEventListener('input', handleQuantityEvent);
		}());
		</script>
		<?php
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
	 * Per-product setting ONLY — no fallback to global defaults.
	 * Returns 0 (unlimited) if not explicitly set on the product.
	 *
	 * @param WC_Product $product Product object.
	 * @return int Maximum quantity, or 0 for unlimited.
	 */
	private function get_product_maximum_quantity( $product ) {
		if ( ! is_object( $product ) ) {
			return 0;
		}

		$max_qty = get_post_meta( $product->get_id(), '_lwc_maximum_quantity', true );
		$max_qty = $max_qty ? absint( $max_qty ) : 0;

		// For variations, check parent product if variation has no custom max
		if ( $max_qty === 0 && $product->is_type( 'variation' ) ) {
			$parent_id = $product->get_parent_id();
			if ( $parent_id ) {
				$parent_max_qty = get_post_meta( $parent_id, '_lwc_maximum_quantity', true );
				$parent_max_qty = $parent_max_qty ? absint( $parent_max_qty ) : 0;
				if ( $parent_max_qty > 0 ) {
					$max_qty = $parent_max_qty;
				}
			}
		}

		// Ensure max_qty >= min_qty when both are set
		if ( $max_qty > 0 ) {
			$min_qty = $this->get_product_minimum_quantity( $product );
			if ( $min_qty > 0 && $max_qty < $min_qty ) {
				$max_qty = $min_qty;
			}
		}

		return $max_qty;
	}

	/**
	 * Get the minimum quantity for the product or variation.
	 *
	 * Per-product setting ONLY — no fallback to global defaults.
	 * Returns 0 (no minimum / default to 1) if not explicitly set on the product.
	 *
	 * @param WC_Product $product Product object.
	 * @return int Minimum quantity, or 0 if not set (defaults to 1).
	 */
	private function get_product_minimum_quantity( $product ) {
		if ( ! is_object( $product ) ) {
			return 0;
		}

		$min_qty = get_post_meta( $product->get_id(), '_lwc_minimum_quantity', true );
		$min_qty = $min_qty ? absint( $min_qty ) : 0;

		// If product has explicit minimum set, use it (even 1+)
		if ( $min_qty > 1 ) {
			return $min_qty;
		}

		// For variations, check parent product if variation has no custom minimum
		if ( $min_qty === 0 && $product->is_type( 'variation' ) ) {
			$parent_id = $product->get_parent_id();
			if ( $parent_id ) {
				$parent_min_qty = get_post_meta( $parent_id, '_lwc_minimum_quantity', true );
				$parent_min_qty = $parent_min_qty ? absint( $parent_min_qty ) : 0;
				if ( $parent_min_qty > 1 ) {
					return $parent_min_qty;
				}
			}
		}

		// No custom minimum set → return 0 (means use WooCommerce default of 1)
		return 0;
	}

	/**
	 * Add product quantity fields to the WooCommerce Quick Edit panel.
	 *
	 * @param string $column_name Current product-list column.
	 * @param string $post_type   Current post type.
	 */
	public function add_quick_edit_fields( $column_name, $post_type ) {
		if ( 'product' !== $post_type || 'name' !== $column_name ) {
			return;
		}
		?>
		<fieldset class="inline-edit-col-left lwc-quantity-limits-quick-edit">
			<div class="inline-edit-col">
				<span class="lwc-quantity-limits-heading"><?php esc_html_e( 'Quantity Limits', 'lovecatz-wc' ); ?></span>
				<label>
					<span class="title"><?php esc_html_e( 'Minimum', 'lovecatz-wc' ); ?></span>
					<span class="input-text-wrap"><input type="number" name="lwc_quick_edit_minimum_quantity" min="1" step="1" /></span>
				</label>
				<label>
					<span class="title"><?php esc_html_e( 'Maximum', 'lovecatz-wc' ); ?></span>
					<span class="input-text-wrap"><input type="number" name="lwc_quick_edit_maximum_quantity" min="1" step="1" /></span>
				</label>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Render Quick Edit styles on the Products list table.
	 */
	public function render_quick_edit_styles() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-product' !== $screen->id ) {
			return;
		}
		?>
		<style>
			.inline-edit-row .lwc-quantity-limits-quick-edit {
				clear: left;
				padding-top: 8px;
			}

			.inline-edit-row .lwc-quantity-limits-quick-edit .inline-edit-col {
				padding: 0;
			}

			.inline-edit-row .lwc-quantity-limits-heading {
				display: block;
				margin-bottom: 6px;
				font-weight: 600;
			}

			.inline-edit-row .lwc-quantity-limits-quick-edit label {
				display: flex;
				align-items: center;
				margin: 0 0 6px;
			}

			.inline-edit-row .lwc-quantity-limits-quick-edit label .title {
				float: none;
				width: 85px;
				margin: 0;
				white-space: nowrap;
			}

			.inline-edit-row .lwc-quantity-limits-quick-edit label .input-text-wrap {
				float: none;
				margin: 0;
			}

			.inline-edit-row .lwc-quantity-limits-quick-edit input[type="number"] {
				width: 120px;
			}
		</style>
		<?php
	}

	/**
	 * Store product-specific limit values in the row for the Quick Edit script.
	 *
	 * @param array   $actions Product row actions.
	 * @param WP_Post $post    Current post.
	 * @return array
	 */
	public function add_quick_edit_product_data( $actions, $post ) {
		if ( 'product' !== $post->post_type ) {
			return $actions;
		}

		$minimum = get_post_meta( $post->ID, '_lwc_minimum_quantity', true );
		$maximum = get_post_meta( $post->ID, '_lwc_maximum_quantity', true );
		$actions['lwc_quantity_limit_data'] = sprintf(
			'<span class="lwc-quantity-limit-data" data-minimum="%1$s" data-maximum="%2$s" aria-hidden="true" style="display:none"></span>',
			esc_attr( $minimum ),
			esc_attr( $maximum )
		);

		return $actions;
	}

	/**
	 * Populate the Quick Edit fields from the selected product row.
	 */
	public function render_quick_edit_script() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-product' !== $screen->id ) {
			return;
		}
		?>
		<script>
		jQuery( function( $ ) {
			var originalEdit = inlineEditPost.edit;

			inlineEditPost.edit = function( postId ) {
				originalEdit.apply( this, arguments );

				var id = typeof postId === 'object' ? this.getId( postId ) : postId;
				var data = $( '#post-' + id + ' .lwc-quantity-limit-data' );
				var quickEdit = $( '#edit-' + id );

				quickEdit.find( '[name="lwc_quick_edit_minimum_quantity"]' ).val( data.data( 'minimum' ) || '' );
				quickEdit.find( '[name="lwc_quick_edit_maximum_quantity"]' ).val( data.data( 'maximum' ) || '' );
			};
		} );
		</script>
		<?php
	}

	/**
	 * Save quantity limits submitted from WooCommerce Quick Edit.
	 *
	 * @param int     $post_id Product ID.
	 * @param WP_Post $post    Product post.
	 */
	public function save_quick_edit_fields( $post_id, $post ) {
		if ( ! isset( $_POST['_inline_edit'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_inline_edit'] ) ), 'inlineeditnonce' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) || 'product' !== $post->post_type ) {
			return;
		}

		$this->save_quantity_limit_meta( $post_id, '_lwc_minimum_quantity', 'lwc_quick_edit_minimum_quantity' );
		$this->save_quantity_limit_meta( $post_id, '_lwc_maximum_quantity', 'lwc_quick_edit_maximum_quantity' );
	}

	/**
	 * Determine whether per-product quantity limits are enabled.
	 *
	 * @return bool
	 */
	private function is_enabled() {
		return 'yes' === get_option( 'lwc_enable_product_quantity_limits', 'no' );
	}

	/**
	 * Sanitize an optional quantity limit.
	 *
	 * An empty value means no product-specific limit. Any supplied value below
	 * one is normalized to one so direct form submissions cannot bypass the UI.
	 *
	 * @param mixed $value Submitted quantity limit.
	 * @return int Limit value, or 0 when empty.
	 */
	private function sanitize_quantity_limit( $value ) {
		$value = trim( (string) wp_unslash( $value ) );
		if ( '' === $value ) {
			return 0;
		}

		return max( 1, intval( $value ) );
	}

	/**
	 * Save or remove one optional quantity-limit meta value from the request.
	 *
	 * @param int    $post_id   Product ID.
	 * @param string $meta_key  Product meta key.
	 * @param string $field_key Submitted request field name.
	 */
	private function save_quantity_limit_meta( $post_id, $meta_key, $field_key ) {
		if ( ! isset( $_POST[ $field_key ] ) ) {
			return;
		}

		$quantity = $this->sanitize_quantity_limit( $_POST[ $field_key ] );
		if ( $quantity > 0 ) {
			update_post_meta( $post_id, $meta_key, $quantity );
		} else {
			delete_post_meta( $post_id, $meta_key );
		}
	}
}
