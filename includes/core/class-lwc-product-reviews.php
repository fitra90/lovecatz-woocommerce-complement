<?php
/**
 * Customer review links for completed WooCommerce orders.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_Product_Reviews {

	/** Register My Account hooks when the feature is enabled. */
	public function init() {
		if ( 'yes' !== get_option( 'lwc_product_review_enabled', 'no' ) ) {
			return;
		}

		add_filter( 'woocommerce_my_account_my_orders_columns', array( $this, 'add_review_column' ) );
		add_action( 'woocommerce_my_account_my_orders_column_lwc-write-review', array( $this, 'render_review_column' ) );
	}

	/** Add the review column immediately before the standard order actions. */
	public function add_review_column( $columns ) {
		$updated = array();

		foreach ( $columns as $key => $label ) {
			if ( 'order-actions' === $key ) {
				$updated['lwc-write-review'] = __( 'Write Review', 'lovecatz-wc' );
			}
			$updated[ $key ] = $label;
		}

		if ( ! isset( $updated['lwc-write-review'] ) ) {
			$updated['lwc-write-review'] = __( 'Write Review', 'lovecatz-wc' );
		}

		return $updated;
	}

	/** Render one product review link per unique reviewable product. */
	public function render_review_column( $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}

		if ( ! $order || ! $order->has_status( 'completed' ) || (int) $order->get_user_id() !== get_current_user_id() ) {
			echo '&mdash;';
			return;
		}

		$links       = array();
		$product_ids = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
			$product_id = absint( $product_id );
			if ( ! $product_id || isset( $product_ids[ $product_id ] ) ) {
				continue;
			}
			$product_ids[ $product_id ] = true;

			$review_product = wc_get_product( $product_id );
			if ( ! $review_product || ! $review_product->get_reviews_allowed() ) {
				continue;
			}

			$url     = get_permalink( $product_id ) . '#reviews';
			$label   = sprintf( __( 'Review %s', 'lovecatz-wc' ), $review_product->get_name() );
			$links[] = '<a class="woocommerce-button button lwc-write-review" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}

		echo empty( $links ) ? '&mdash;' : wp_kses_post( implode( '<br />', $links ) );
	}
}
