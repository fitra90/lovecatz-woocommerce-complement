<?php
/** Unified shipment columns and actions for WooCommerce order lists. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_Order_List_Shipping {
	const AWB_COLUMN    = 'lwc_shipping_awb';
	const ACTION_COLUMN = 'lwc_shipping_actions';

	private $awb_column = self::AWB_COLUMN;
	private $action_column = self::ACTION_COLUMN;

	public function init() {
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'register_columns' ), 90 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'register_columns' ), 90 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_legacy_column' ), 999, 2 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_hpos_column' ), 999, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/** Reuse JNE's columns when present; otherwise add neutral shipping columns. */
	public function register_columns( $columns ) {
		foreach ( $columns as $id => $label ) {
			$text = strtolower( wp_strip_all_tags( (string) $label ) );
			$key  = strtolower( (string) $id );
			if ( self::AWB_COLUMN !== $id && ( false !== strpos( $text, 'no. resi' ) || false !== strpos( $text, 'no resi' ) || false !== strpos( $key, 'resi' ) || false !== strpos( $key, 'awb' ) ) ) {
				$this->awb_column = $id;
			}
			if ( self::ACTION_COLUMN !== $id && ( false !== strpos( $text, 'jne action' ) || false !== strpos( $key, 'jne_action' ) || false !== strpos( $key, 'jne-action' ) ) ) {
				$this->action_column = $id;
			}
		}

		if ( ! isset( $columns[ $this->awb_column ] ) ) {
			$columns[ self::AWB_COLUMN ] = __( 'No. Resi', 'lovecatz-wc' );
			$this->awb_column = self::AWB_COLUMN;
		}
		if ( ! isset( $columns[ $this->action_column ] ) ) {
			$columns[ self::ACTION_COLUMN ] = __( 'Shipping Actions', 'lovecatz-wc' );
			$this->action_column = self::ACTION_COLUMN;
		}
		return $columns;
	}

	public function render_legacy_column( $column, $post_id ) {
		$this->render_column( $column, wc_get_order( $post_id ) );
	}

	public function render_hpos_column( $column, $order ) {
		$this->render_column( $column, $order instanceof WC_Order ? $order : wc_get_order( $order ) );
	}

	private function render_column( $column, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$provider = $this->get_provider( $order );
		if ( ! $provider || ! in_array( $column, array( $this->awb_column, $this->action_column ), true ) ) {
			return;
		}
		$replace = ( ( self::AWB_COLUMN !== $this->awb_column && $column === $this->awb_column ) || ( self::ACTION_COLUMN !== $this->action_column && $column === $this->action_column ) );
		echo '<span class="lwc-shipping-cell" data-lwc-replace-cell="' . esc_attr( $replace ? '1' : '0' ) . '">';
		if ( $column === $this->awb_column ) {
			$this->render_awb( $order, $provider );
		} else {
			$this->render_actions( $order, $provider );
		}
		echo '</span>';
	}

	private function render_awb( $order, $provider ) {
		$numbers = $this->get_tracking_numbers( $order, $provider );
		if ( ! $numbers ) {
			echo '<span aria-label="' . esc_attr__( 'No tracking number', 'lovecatz-wc' ) . '">—</span>';
			return;
		}
		foreach ( $numbers as $number ) {
			echo '<code class="lwc-order-awb">' . esc_html( $number ) . '</code>';
		}
	}

	private function render_actions( $order, $provider ) {
		$order_id = $order->get_id();
		$numbers  = $this->get_tracking_numbers( $order, $provider );
		echo '<span class="lwc-shipping-actions" data-order-id="' . esc_attr( $order_id ) . '" data-provider="' . esc_attr( $provider ) . '">';

		if ( ! $numbers && in_array( $provider, array( 'jt', 'fedex', 'rayspeed' ), true ) ) {
			$label = 'jt' === $provider ? __( 'Create AWB / request pickup', 'lovecatz-wc' ) : __( 'Create shipment / AWB', 'lovecatz-wc' );
			$this->action_button( 'create', 'dashicons-plus-alt2', $label );
		}
		if ( $numbers && 'jt' === $provider ) {
			$this->action_button( 'print', 'dashicons-printer', __( 'Print label', 'lovecatz-wc' ) );
		}
		if ( $numbers && 'fedex' === $provider ) {
			$url = wp_nonce_url( add_query_arg( array( 'action' => 'lwc_fedex_download_label', 'order_id' => $order_id ), admin_url( 'admin-ajax.php' ) ), 'lwc_fedex_connection_check', 'nonce' );
			echo '<a class="button lwc-shipping-action" href="' . esc_url( $url ) . '" title="' . esc_attr__( 'Download label', 'lovecatz-wc' ) . '"><span class="dashicons dashicons-download" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Download label', 'lovecatz-wc' ) . '</span></a>';
			$pickup = $order->get_meta( '_lwc_fedex_pickup' );
			if ( ! is_array( $pickup ) || 'scheduled' !== ( isset( $pickup['status'] ) ? $pickup['status'] : '' ) ) {
				$this->action_button( 'pickup', 'dashicons-car', __( 'Request pickup', 'lovecatz-wc' ) );
			}
		}
		if ( $numbers && in_array( $provider, array( 'jt', 'fedex', 'rayspeed' ), true ) ) {
			$this->action_button( 'track', 'dashicons-location-alt', __( 'Tracking', 'lovecatz-wc' ) );
		}
		if ( 'jt_cargo' === $provider ) {
			echo '<span class="lwc-action-unavailable" title="' . esc_attr__( 'J&T Cargo AWB and tracking API are not configured.', 'lovecatz-wc' ) . '">—</span>';
		}
		echo '</span>';
	}

	private function action_button( $action, $icon, $label ) {
		echo '<button type="button" class="button lwc-shipping-action" data-action="' . esc_attr( $action ) . '" title="' . esc_attr( $label ) . '"><span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html( $label ) . '</span></button>';
	}

	private function get_provider( $order ) {
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$id = strtolower( (string) $item->get_method_id() );
			if ( in_array( $id, array( 'lwc_jt_express', 'lwc_jt' ), true ) ) {
				return 'jt';
			}
			if ( 'lwc_jt_cargo' === $id ) {
				return 'jt_cargo';
			}
			if ( 'lwc_fedex' === $id ) {
				return 'fedex';
			}
			if ( 'lwc_rayspeed' === $id ) {
				return 'rayspeed';
			}
		}
		return '';
	}

	private function get_tracking_numbers( $order, $provider ) {
		$numbers = array();
		if ( 'jt' === $provider ) {
			$numbers[] = $order->get_meta( '_lwc_jt_awb' );
		} elseif ( 'rayspeed' === $provider ) {
			$numbers[] = $order->get_meta( '_lwc_rayspeed_awb' );
		} elseif ( 'jt_cargo' === $provider ) {
			$numbers[] = $order->get_meta( '_lwc_jt_cargo_awb' );
			$numbers[] = $order->get_meta( '_lwc_jt_cargo_tracking_number' );
		} elseif ( 'fedex' === $provider ) {
			$shipments = $order->get_meta( '_lwc_fedex_shipments' );
			if ( is_array( $shipments ) ) {
				foreach ( $shipments as $shipment ) {
					if ( 'cancelled' === ( isset( $shipment['status'] ) ? $shipment['status'] : '' ) ) {
						continue;
					}
					$numbers[] = isset( $shipment['tracking_number'] ) ? $shipment['tracking_number'] : '';
				}
			}
			$numbers[] = $order->get_meta( '_lwc_fedex_tracking_number' );
		}
		return array_values( array_unique( array_filter( array_map( 'strval', $numbers ) ) ) );
	}

	public function enqueue_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'edit-shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'lwc-order-list-shipping', LWC_PLUGIN_URL . 'shipping/order-list-shipping.css', array(), LWC_VERSION );
		wp_enqueue_script( 'lwc-order-list-shipping', LWC_PLUGIN_URL . 'shipping/order-list-shipping.js', array( 'jquery' ), LWC_VERSION, true );
		wp_localize_script( 'lwc-order-list-shipping', 'lwcOrderShipping', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonces'  => array( 'jt' => wp_create_nonce( 'lwc_jt_order' ), 'fedex' => wp_create_nonce( 'lwc_fedex_connection_check' ), 'rayspeed' => wp_create_nonce( 'lwc_rayspeed_order' ) ),
			'today'   => current_time( 'Y-m-d' ),
			'i18n'    => array(
				'working' => __( 'Processing…', 'lovecatz-wc' ),
				'error' => __( 'The shipping request failed.', 'lovecatz-wc' ),
				'tracking' => __( 'Shipment Tracking', 'lovecatz-wc' ),
				'pickup' => __( 'Request FedEx Pickup', 'lovecatz-wc' ),
				'check' => __( 'Check availability', 'lovecatz-wc' ),
				'request' => __( 'Request pickup', 'lovecatz-wc' ),
				'close' => __( 'Close', 'lovecatz-wc' ),
			),
		) );
	}
}
