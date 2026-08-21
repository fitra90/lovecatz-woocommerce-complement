<?php
/**
 * Order-screen FedEx controls: rate quote, AWB label creation, and download.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_FedEx_Order_Admin {

	/**
	 * Initialize hooks.
	 */
	public function init() {
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the side metabox on the order edit screen.
	 *
	 * Both the classic shop_order screen and the HPOS orders screen are
	 * registered; unknown screens are ignored by WordPress.
	 */
	public function register_metabox() {
		add_meta_box(
			'lwc_fedex_shipping',
			__( 'FedEx Shipping', 'lovecatz-wc' ),
			array( $this, 'render_metabox' ),
			array( 'shop_order', 'woocommerce_page_wc-orders' ),
			'side',
			'default'
		);
	}

	/**
	 * Enqueue assets on the order edit screen.
	 */
	public function enqueue_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}

		wp_enqueue_style( 'lwc-fedex-order-admin', LWC_PLUGIN_URL . 'shipping/fedex/fedex-order-admin.css', array(), LWC_VERSION );
		wp_enqueue_script( 'lwc-fedex-order-admin', LWC_PLUGIN_URL . 'shipping/fedex/fedex-order-admin.js', array( 'jquery' ), LWC_VERSION, true );
		wp_localize_script( 'lwc-fedex-order-admin', 'lwcFedexOrder', $this->get_script_config() );
	}

	/**
	 * Render the FedEx metabox.
	 *
	 * @param WC_Order|WP_Post $post_or_order Order object or post.
	 */
	public function render_metabox( $post_or_order ) {
		$order = ( $post_or_order instanceof WP_Post ) ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! class_exists( 'LWC_FedEx_Account' ) ) {
			return;
		}

		$account   = LWC_FedEx_Account::get_option_value( 'lwc_fedex_account_number', '' );
		$api_key   = LWC_FedEx_Account::get_option_value( 'lwc_fedex_api_key', '' );
		$api_secret = LWC_FedEx_Account::get_option_value( 'lwc_fedex_api_secret', '' );
		$ready     = '' !== $account && '' !== $api_key && '' !== $api_secret;

		$tracking   = $order->get_meta( '_lwc_fedex_tracking_number' );
		$label_path = $order->get_meta( '_lwc_fedex_label_path' );
		$has_label  = is_string( $label_path ) && '' !== $label_path;

		$shipments = $order->get_meta( '_lwc_fedex_shipments' );
		$shipments = is_array( $shipments ) ? $shipments : array();

		// Items already covered by a previous (partial) shipment.
		$shipped_item_ids = array();
		foreach ( $shipments as $shipment ) {
			if ( ! empty( $shipment['item_ids'] ) && is_array( $shipment['item_ids'] ) ) {
				$shipped_item_ids = array_merge( $shipped_item_ids, array_map( 'intval', $shipment['item_ids'] ) );
			}
		}
		$shipped_item_ids = array_unique( $shipped_item_ids );

		$order_items = array();
		foreach ( $order->get_items() as $item ) {
			$order_items[] = array(
				'id' => (int) $item->get_id(),
				'name' => wp_strip_all_tags( $item->get_name() ),
				'quantity' => (float) $item->get_quantity(),
				'shipped' => in_array( (int) $item->get_id(), $shipped_item_ids, true ),
			);
		}

		$download_url = '';
		if ( $has_label ) {
			$download_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'   => 'lwc_fedex_download_label',
						'order_id' => $order->get_id(),
					),
					admin_url( 'admin-ajax.php' )
				),
				'lwc_fedex_connection_check',
				'nonce'
			);
		}

		$download_base = add_query_arg(
			array(
				'action'   => 'lwc_fedex_download_label',
				'order_id' => $order->get_id(),
			),
			admin_url( 'admin-ajax.php' )
		);
		?>
		<div class="lwc-fedex-order-box" id="lwc-fedex-order-box">
			<?php if ( ! $ready ) : ?>
				<p class="description">
					<?php esc_html_e( 'Configure your FedEx credentials under LoveCatz → Shipping → FedEx to create labels.', 'lovecatz-wc' ); ?>
				</p>
			<?php else : ?>
				<p class="lwc-fedex-order-actions">
					<button type="button" class="button" id="lwc_fedex_quote_btn"><?php esc_html_e( 'Test rate quote', 'lovecatz-wc' ); ?></button>
					<button type="button" class="button button-primary" id="lwc_fedex_create_label_btn"><?php esc_html_e( 'Create FedEx label', 'lovecatz-wc' ); ?></button>
				</p>

				<?php if ( ! empty( $order_items ) ) : ?>
					<div class="lwc-fedex-items">
						<p class="description">
							<?php esc_html_e( 'Select the items for this AWB. Leave everything checked to ship the whole order; uncheck items to split the shipment manually.', 'lovecatz-wc' ); ?>
						</p>
						<?php foreach ( $order_items as $item ) : ?>
							<label class="lwc-fedex-item <?php echo $item['shipped'] ? 'is-shipped' : ''; ?>">
								<input
									type="checkbox"
									class="lwc-fedex-item-checkbox"
									value="<?php echo esc_attr( (string) $item['id'] ); ?>"
									<?php checked( ! $item['shipped'] ); ?>
									<?php disabled( $item['shipped'] ); ?>
								/>
								<?php
								printf(
									/* translators: 1: product name, 2: quantity */
									esc_html__( '%1$s × %2$s', 'lovecatz-wc' ),
									esc_html( $item['name'] ),
									esc_html( $item['quantity'] )
								);
								if ( $item['shipped'] ) {
									echo ' — <em>' . esc_html__( 'already shipped', 'lovecatz-wc' ) . '</em>';
								}
								?>
							</label>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<p id="lwc-fedex-order-status" class="lwc-fedex-order-status" hidden></p>

				<?php if ( ! empty( $shipments ) ) : ?>
					<div class="lwc-fedex-shipments">
						<strong><?php esc_html_e( 'Shipments', 'lovecatz-wc' ); ?></strong>
						<ul>
							<?php foreach ( $shipments as $index => $shipment ) : ?>
								<li>
									<?php
									$shipment_tracking = isset( $shipment['tracking_number'] ) ? (string) $shipment['tracking_number'] : '';
									echo esc_html( '#' . ( $index + 1 ) . ' ' . ( '' !== $shipment_tracking ? $shipment_tracking : __( '(no tracking)', 'lovecatz-wc' ) ) );
									?>
									<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'shipment', (string) $index, $download_base ), 'lwc_fedex_connection_check', 'nonce' ) ); ?>" target="_blank" rel="noopener">
										<?php esc_html_e( 'Label', 'lovecatz-wc' ); ?>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php else : ?>
					<p class="lwc-fedex-tracking-row">
						<strong><?php esc_html_e( 'Tracking:', 'lovecatz-wc' ); ?></strong>
						<span id="lwc-fedex-tracking"><?php echo esc_html( is_string( $tracking ) ? $tracking : '' ); ?></span>
					</p>
					<p>
						<a id="lwc-fedex-download-link" class="button" href="<?php echo esc_url( $download_url ); ?>" target="_blank" rel="noopener" <?php echo $has_label ? '' : 'hidden'; ?>>
							<?php esc_html_e( 'Download label (AWB)', 'lovecatz-wc' ); ?>
						</a>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Build the localized script configuration for the current order.
	 *
	 * @return array
	 */
	private function get_script_config() {
		$order = $this->get_current_order();

		$config = array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'lwc_fedex_connection_check' ),
			'order_id' => 0,
			'address'  => array(
				'country'  => '',
				'state'    => '',
				'postcode' => '',
				'city'     => '',
			),
			'i18n'     => array(
				'checking'      => __( 'Requesting a quote from FedEx…', 'lovecatz-wc' ),
				'creating'      => __( 'Creating the FedEx shipment…', 'lovecatz-wc' ),
				'quote_prefix'  => __( 'Live rate: ', 'lovecatz-wc' ),
				'quote_failed'  => __( 'FedEx did not return a rate.', 'lovecatz-wc' ),
				'created'       => __( 'Label created successfully.', 'lovecatz-wc' ),
				'create_failed' => __( 'FedEx could not create the shipment.', 'lovecatz-wc' ),
				'request_failed'=> __( 'The request could not be completed.', 'lovecatz-wc' ),
				'no_items'      => __( 'Select at least one item to ship.', 'lovecatz-wc' ),
			),
		);

		if ( $order instanceof WC_Order ) {
			$config['order_id']          = $order->get_id();
			$config['address']['country']  = $order->get_shipping_country() ? $order->get_shipping_country() : $order->get_billing_country();
			$config['address']['state']    = $order->get_shipping_state() ? $order->get_shipping_state() : $order->get_billing_state();
			$config['address']['postcode'] = $order->get_shipping_postcode() ? $order->get_shipping_postcode() : $order->get_billing_postcode();
			$config['address']['city']     = $order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city();
		}

		return $config;
	}

	/**
	 * Resolve the order being edited from the request.
	 *
	 * @return WC_Order|null
	 */
	private function get_current_order() {
		$order_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $order_id && isset( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order_id = absint( $_GET['id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		return $order_id ? wc_get_order( $order_id ) : null;
	}
}
