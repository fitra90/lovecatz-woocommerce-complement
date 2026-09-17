<?php
/**
 * FedEx order fulfillment controls and customer shipment tracking.
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
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_customer_assets' ) );
		add_action( 'wp_ajax_lwc_fedex_cancel_shipment', array( $this, 'ajax_cancel_shipment' ) );
		add_action( 'wp_ajax_lwc_fedex_refresh_tracking', array( $this, 'ajax_refresh_tracking' ) );
		add_action( 'wp_ajax_lwc_fedex_pickup_availability', array( $this, 'ajax_pickup_availability' ) );
		add_action( 'wp_ajax_lwc_fedex_schedule_pickup', array( $this, 'ajax_schedule_pickup' ) );
		add_action( 'wp_ajax_lwc_fedex_cancel_pickup', array( $this, 'ajax_cancel_pickup' ) );
		add_filter( 'woocommerce_account_orders_columns', array( $this, 'add_account_tracking_column' ) );
		add_action( 'woocommerce_my_account_my_orders_column_lwc-fedex-tracking', array( $this, 'render_account_tracking_column' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_customer_tracking' ) );
	}

	/**
	 * Register the side metabox on the order edit screen.
	 *
	 * Both the classic shop_order screen and the HPOS orders screen are
	 * registered; unknown screens are ignored by WordPress.
	 */
	public function register_metabox( $post_type = '', $post_or_order = null ) {
		$order = $this->resolve_admin_order( $post_or_order );
		if ( ! $this->order_uses_fedex( $order ) ) {
			return;
		}

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
		if ( ! $this->order_uses_fedex( $this->resolve_admin_order() ) ) {
			return;
		}

		$style_path     = LWC_PLUGIN_DIR . 'shipping/fedex/fedex-order-admin.css';
		$script_path    = LWC_PLUGIN_DIR . 'shipping/fedex/fedex-order-admin.js';
		$style_version  = file_exists( $style_path ) ? (string) filemtime( $style_path ) : LWC_VERSION;
		$script_version = file_exists( $script_path ) ? (string) filemtime( $script_path ) : LWC_VERSION;
		wp_enqueue_style( 'lwc-fedex-order-admin', LWC_PLUGIN_URL . 'shipping/fedex/fedex-order-admin.css', array(), $style_version );
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_script( 'lwc-fedex-order-admin', LWC_PLUGIN_URL . 'shipping/fedex/fedex-order-admin.js', array( 'jquery', 'wc-enhanced-select' ), $script_version, true );
		wp_localize_script( 'lwc-fedex-order-admin', 'lwcFedexOrder', $this->get_script_config() );
	}

	/** Resolve an order on both classic and HPOS admin screens. */
	private function resolve_admin_order( $post_or_order = null ) {
		if ( $post_or_order instanceof WC_Order ) {
			return $post_or_order;
		}
		if ( $post_or_order instanceof WP_Post ) {
			return wc_get_order( $post_or_order->ID );
		}
		$order_id = 0;
		if ( isset( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order_id = absint( wp_unslash( $_GET['id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order_id = absint( wp_unslash( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return $order_id ? wc_get_order( $order_id ) : false;
	}

	/** Whether the customer selected FedEx for this order. */
	private function order_uses_fedex( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		foreach ( $order->get_items( 'shipping' ) as $shipping_item ) {
			if ( 'lwc_fedex' === $shipping_item->get_method_id() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Load the lightweight tracking presentation on customer account pages.
	 */
	public function enqueue_customer_assets() {
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			wp_enqueue_style( 'lwc-fedex-tracking', LWC_PLUGIN_URL . 'shipping/fedex/fedex-tracking.css', array(), LWC_VERSION );
		}
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
		$is_sandbox = $this->is_sandbox_mode();

		$tracking   = $order->get_meta( '_lwc_fedex_tracking_number' );
		$label_path = $order->get_meta( '_lwc_fedex_label_path' );
		$has_label  = is_string( $label_path ) && '' !== $label_path;

		$shipments = $order->get_meta( '_lwc_fedex_shipments' );
		$shipments = is_array( $shipments ) ? $shipments : array();
		$tracking_data = $order->get_meta( '_lwc_fedex_tracking_data' );
		$tracking_data = is_array( $tracking_data ) ? $tracking_data : array();
		$pickup = $order->get_meta( '_lwc_fedex_pickup' );
		$pickup = is_array( $pickup ) ? $pickup : array();

		// Items already covered by a previous (partial) shipment.
		$shipped_item_ids = array();
		$has_cancelled_shipments = false;
		foreach ( $shipments as $shipment ) {
			if ( 'cancelled' === ( isset( $shipment['status'] ) ? $shipment['status'] : '' ) ) {
				$has_cancelled_shipments = true;
				continue;
			}
			if ( ! empty( $shipment['item_ids'] ) && is_array( $shipment['item_ids'] ) ) {
				$shipped_item_ids = array_merge( $shipped_item_ids, array_map( 'intval', $shipment['item_ids'] ) );
			}
			if ( ! empty( $shipment['replaced_item_ids'] ) && is_array( $shipment['replaced_item_ids'] ) ) {
				$shipped_item_ids = array_merge( $shipped_item_ids, array_map( 'intval', $shipment['replaced_item_ids'] ) );
			}
		}
		$shipped_item_ids = array_unique( $shipped_item_ids );

		$order_items = array();
		$has_available_items = false;
		foreach ( $order->get_items() as $item ) {
			$item_shipped = in_array( (int) $item->get_id(), $shipped_item_ids, true );
			$product = $item->get_product();
			$order_items[] = array(
				'id' => (int) $item->get_id(),
				'product_id' => $product ? (int) $product->get_id() : 0,
				'name' => wp_strip_all_tags( $item->get_name() ),
				'quantity' => (float) $item->get_quantity(),
				'shipped' => $item_shipped,
			);
			$has_available_items = $has_available_items || ! $item_shipped;
		}
		$is_replacement = $has_cancelled_shipments && $has_available_items;
		$selected_service_type = 'INTERNATIONAL_ECONOMY';
		foreach ( $order->get_items( 'shipping' ) as $shipping_item ) {
			$stored_service_type = strtoupper( trim( (string) $shipping_item->get_meta( 'lwc_fedex_service' ) ) );
			if ( in_array( $stored_service_type, array( 'FEDEX_INTERNATIONAL_PRIORITY', 'INTERNATIONAL_ECONOMY' ), true ) ) {
				$selected_service_type = $stored_service_type;
				break;
			}
		}
		if ( $is_replacement ) {
			$create_label_text = $is_sandbox ? __( 'Create replacement test AWB', 'lovecatz-wc' ) : __( 'Create replacement AWB', 'lovecatz-wc' );
		} else {
			$create_label_text = $is_sandbox ? __( 'Create test label', 'lovecatz-wc' ) : __( 'Create FedEx label', 'lovecatz-wc' );
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
				<label class="lwc-fedex-service-field" for="lwc_fedex_service_type">
					<span><?php esc_html_e( 'FedEx service', 'lovecatz-wc' ); ?></span>
					<select id="lwc_fedex_service_type">
						<option value="FEDEX_INTERNATIONAL_PRIORITY" <?php selected( $selected_service_type, 'FEDEX_INTERNATIONAL_PRIORITY' ); ?>><?php esc_html_e( 'FedEx International Priority', 'lovecatz-wc' ); ?></option>
						<option value="INTERNATIONAL_ECONOMY" <?php selected( $selected_service_type, 'INTERNATIONAL_ECONOMY' ); ?>><?php esc_html_e( 'FedEx International Economy', 'lovecatz-wc' ); ?></option>
					</select>
				</label>
				<p class="lwc-fedex-order-actions">
					<button type="button" class="button" id="lwc_fedex_quote_btn"><?php esc_html_e( 'Test rate quote', 'lovecatz-wc' ); ?></button>
					<button type="button" class="button button-primary" id="lwc_fedex_create_label_btn" <?php disabled( ! $has_available_items ); ?>><?php echo esc_html( $create_label_text ); ?></button>
				</p>
				<?php if ( $is_replacement ) : ?>
					<div class="notice notice-info inline"><p><?php esc_html_e( 'The previous FedEx AWB is cancelled. Confirm the selected items and actual carton measurements, then create the replacement AWB.', 'lovecatz-wc' ); ?></p></div>
				<?php endif; ?>
				<?php if ( $is_sandbox ) : ?>
					<div class="notice notice-warning inline lwc-fedex-sandbox-notice"><p><?php esc_html_e( 'Sandbox mode: rates and labels are for testing only. Live tracking and courier pickup are disabled.', 'lovecatz-wc' ); ?></p></div>
				<?php endif; ?>

				<fieldset class="lwc-fedex-package-fields">
					<legend><?php esc_html_e( 'Actual packed carton (optional)', 'lovecatz-wc' ); ?></legend>
					<p class="description"><?php esc_html_e( 'Enter the actual packed weight as a fully custom value. Dimensions are optional; leave them blank to use product-derived estimates.', 'lovecatz-wc' ); ?></p>
					<label>
						<?php esc_html_e( 'Weight (kg)', 'lovecatz-wc' ); ?>
						<input type="number" id="lwc_fedex_package_weight" min="0.01" max="68" step="0.01" inputmode="decimal" />
					</label>
					<div class="lwc-fedex-dimension-fields">
						<label><?php esc_html_e( 'Length (cm)', 'lovecatz-wc' ); ?><input type="number" id="lwc_fedex_package_length" min="1" step="1" inputmode="numeric" /></label>
						<label><?php esc_html_e( 'Width (cm)', 'lovecatz-wc' ); ?><input type="number" id="lwc_fedex_package_width" min="1" step="1" inputmode="numeric" /></label>
						<label><?php esc_html_e( 'Height (cm)', 'lovecatz-wc' ); ?><input type="number" id="lwc_fedex_package_height" min="1" step="1" inputmode="numeric" /></label>
					</div>
				</fieldset>

				<?php if ( ! empty( $order_items ) ) : ?>
					<div class="lwc-fedex-items">
						<p class="description">
							<?php esc_html_e( 'Check the items included in this FedEx package. Item selection affects the manifest and customs only; a custom carton weight is never recalculated from the checked items.', 'lovecatz-wc' ); ?>
						</p>
						<div id="lwc-fedex-package-items" class="lwc-fedex-package-items">
							<?php foreach ( $order_items as $item ) : ?>
								<div class="lwc-fedex-item <?php echo $item['shipped'] ? 'is-shipped' : ''; ?>" data-product-id="<?php echo esc_attr( (string) $item['product_id'] ); ?>">
									<input
										type="checkbox"
										id="lwc_fedex_item_<?php echo esc_attr( (string) $item['id'] ); ?>"
										class="lwc-fedex-item-checkbox"
										value="<?php echo esc_attr( (string) $item['id'] ); ?>"
										<?php checked( ! $item['shipped'] ); ?>
										<?php disabled( $item['shipped'] ); ?>
									/>
									<label for="lwc_fedex_item_<?php echo esc_attr( (string) $item['id'] ); ?>">
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
									<?php if ( ! $item['shipped'] ) : ?>
										<button type="button" class="button-link-delete lwc-fedex-remove-package-item"><?php esc_html_e( 'Remove', 'lovecatz-wc' ); ?></button>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</div>
						<div class="lwc-fedex-add-item">
							<div class="lwc-fedex-product-search-wrap">
								<select
									id="lwc_fedex_product_search"
									class="wc-product-search"
									style="width:100%;max-width:100%"
									data-width="100%"
									data-placeholder="<?php esc_attr_e( 'Search an in-stock product…', 'lovecatz-wc' ); ?>"
									data-action="woocommerce_json_search_products_and_variations"
									data-allow_clear="true"
								></select>
							</div>
							<button type="button" class="button" id="lwc_fedex_add_package_item"><?php esc_html_e( 'Add item', 'lovecatz-wc' ); ?></button>
						</div>
					</div>
				<?php endif; ?>

				<p id="lwc-fedex-order-status" class="lwc-fedex-order-status" hidden></p>

				<?php if ( ! empty( $shipments ) ) : ?>
					<div class="lwc-fedex-shipments">
						<strong><?php esc_html_e( 'Shipments', 'lovecatz-wc' ); ?></strong>
						<ul>
							<?php foreach ( $shipments as $index => $shipment ) : ?>
								<?php $shipment_cancelled = 'cancelled' === ( isset( $shipment['status'] ) ? $shipment['status'] : '' ); ?>
								<li class="<?php echo $shipment_cancelled ? 'is-cancelled' : ''; ?>">
									<?php
									$shipment_tracking = isset( $shipment['tracking_number'] ) ? (string) $shipment['tracking_number'] : '';
									echo esc_html( '#' . ( $index + 1 ) . ' ' . ( '' !== $shipment_tracking ? $shipment_tracking : __( '(no tracking)', 'lovecatz-wc' ) ) );
									if ( $shipment_cancelled ) {
										echo ' — <strong>' . esc_html__( 'Cancelled', 'lovecatz-wc' ) . '</strong>';
									}
									?>
									<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'shipment', (string) $index, $download_base ), 'lwc_fedex_connection_check', 'nonce' ) ); ?>" target="_blank" rel="noopener">
										<?php esc_html_e( 'Label', 'lovecatz-wc' ); ?>
									</a>
									<?php if ( ! $shipment_cancelled && '' !== $shipment_tracking ) : ?>
										<button type="button" class="button-link-delete lwc-fedex-cancel-shipment" data-shipment="<?php echo esc_attr( (string) $index ); ?>" data-tracking="<?php echo esc_attr( $shipment_tracking ); ?>">
											<?php esc_html_e( 'Cancel AWB', 'lovecatz-wc' ); ?>
										</button>
									<?php endif; ?>
									<?php if ( ! empty( $shipment['package'] ) && is_array( $shipment['package'] ) && isset( $shipment['package']['weight'] ) ) : ?>
										<small class="lwc-fedex-shipment-package">
											<?php
											echo esc_html( $shipment['package']['weight'] . ' kg' );
											if ( isset( $shipment['package']['length'], $shipment['package']['width'], $shipment['package']['height'] ) ) {
												echo esc_html( ' · ' . $shipment['package']['length'] . ' × ' . $shipment['package']['width'] . ' × ' . $shipment['package']['height'] . ' cm' );
											} else {
												echo ' · ' . esc_html__( 'estimated dimensions', 'lovecatz-wc' );
											}
											?>
										</small>
									<?php endif; ?>
									<?php if ( ! empty( $shipment['service_type'] ) ) : ?>
										<small class="lwc-fedex-shipment-service">
											<?php echo esc_html( ( new LWC_FedEx_API() )->get_service_label( $shipment['service_type'] ) ); ?>
										</small>
									<?php endif; ?>
									<?php if ( ! empty( $shipment['contents'] ) && is_array( $shipment['contents'] ) ) : ?>
										<small class="lwc-fedex-shipment-contents">
											<?php esc_html_e( 'Essential Oils', 'lovecatz-wc' ); ?>
										</small>
									<?php endif; ?>
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

				<?php if ( ! $is_sandbox && $this->get_order_tracking_numbers( $order ) ) : ?>
					<hr />
					<div class="lwc-fedex-tracking-admin">
						<p><strong><?php esc_html_e( 'Live tracking', 'lovecatz-wc' ); ?></strong></p>
						<p><button type="button" class="button" id="lwc_fedex_refresh_tracking_btn"><?php esc_html_e( 'Refresh tracking', 'lovecatz-wc' ); ?></button></p>
						<div id="lwc-fedex-tracking-content">
							<?php $this->render_tracking_cards( $tracking_data, true ); ?>
						</div>
					</div>

					<hr />
					<div class="lwc-fedex-pickup-admin">
						<p><strong><?php esc_html_e( 'FedEx pickup', 'lovecatz-wc' ); ?></strong></p>
						<?php if ( 'scheduled' === ( isset( $pickup['status'] ) ? $pickup['status'] : '' ) ) : ?>
							<div class="lwc-fedex-pickup-summary">
								<?php $pickup_id = isset( $pickup['pickup_id'] ) ? $pickup['pickup_id'] : ( isset( $pickup['confirmation_number'] ) ? $pickup['confirmation_number'] : '' ); ?>
								<span class="lwc-fedex-pickup-summary__id">
									<strong><?php esc_html_e( 'Pickup ID (confirmation):', 'lovecatz-wc' ); ?></strong>
									<?php echo esc_html( $pickup_id ); ?>
								</span>
								<?php if ( ! empty( $pickup['location'] ) ) : ?>
									<span class="lwc-fedex-pickup-summary__location">
										<strong><?php esc_html_e( 'FedEx location code:', 'lovecatz-wc' ); ?></strong>
										<?php echo esc_html( $pickup['location'] ); ?>
									</span>
								<?php endif; ?>
								<span class="lwc-fedex-pickup-summary__schedule">
								<?php
								printf(
									/* translators: 1: pickup date, 2: ready time */
									esc_html__( 'Scheduled for %1$s, ready at %2$s', 'lovecatz-wc' ),
									esc_html( isset( $pickup['date'] ) ? $pickup['date'] : '' ),
									esc_html( isset( $pickup['ready_time'] ) ? $pickup['ready_time'] : '' )
								);
								?>
								</span>
							</div>
							<p><button type="button" class="button button-link-delete" id="lwc_fedex_cancel_pickup_btn"><?php esc_html_e( 'Cancel pickup', 'lovecatz-wc' ); ?></button></p>
						<?php else : ?>
							<div class="lwc-fedex-pickup-fields">
								<label><?php esc_html_e( 'Pickup date', 'lovecatz-wc' ); ?><input type="date" id="lwc_fedex_pickup_date" min="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" /></label>
								<label><?php esc_html_e( 'Package ready', 'lovecatz-wc' ); ?><input type="time" id="lwc_fedex_pickup_ready" value="09:00" /></label>
								<label><?php esc_html_e( 'Store closes', 'lovecatz-wc' ); ?><input type="time" id="lwc_fedex_pickup_close" value="17:00" /></label>
								<label><?php esc_html_e( 'Carrier', 'lovecatz-wc' ); ?><select id="lwc_fedex_pickup_carrier"><option value="FDXE">FedEx Express</option><option value="FDXG">FedEx Ground</option></select></label>
							</div>
							<p class="lwc-fedex-order-actions">
								<button type="button" class="button" id="lwc_fedex_check_pickup_btn"><?php esc_html_e( 'Check availability', 'lovecatz-wc' ); ?></button>
								<button type="button" class="button button-primary" id="lwc_fedex_schedule_pickup_btn" disabled><?php esc_html_e( 'Schedule pickup', 'lovecatz-wc' ); ?></button>
							</p>
						<?php endif; ?>
					</div>
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
			'currency' => get_option( 'woocommerce_currency', 'IDR' ),
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
				'cancel_awb'    => __( 'Cancelling the FedEx AWB…', 'lovecatz-wc' ),
				'confirm_awb'   => __( 'Cancel AWB %s with FedEx? Its items will become available for a replacement label only after FedEx confirms cancellation.', 'lovecatz-wc' ),
				'request_failed'=> __( 'The request could not be completed.', 'lovecatz-wc' ),
				'no_items'      => __( 'Select at least one item to ship.', 'lovecatz-wc' ),
				'package_incomplete' => __( 'Enter a custom weight. Dimensions may be blank, but length, width, and height must be entered together.', 'lovecatz-wc' ),
				'package_invalid' => __( 'Carton weight and dimensions must be positive numbers.', 'lovecatz-wc' ),
				'estimated_weight' => __( 'Product-derived weight', 'lovecatz-wc' ),
				'select_product' => __( 'Choose a product before adding it.', 'lovecatz-wc' ),
				'catalog_item' => __( '(catalog replacement)', 'lovecatz-wc' ),
				'remove_item' => __( 'Remove', 'lovecatz-wc' ),
				'tracking'      => __( 'Refreshing FedEx tracking…', 'lovecatz-wc' ),
				'tracking_ok'   => __( 'Tracking updated.', 'lovecatz-wc' ),
				'pickup_check'  => __( 'Checking FedEx pickup availability…', 'lovecatz-wc' ),
				'pickup_ok'     => __( 'Pickup is available. You can schedule it now.', 'lovecatz-wc' ),
				'pickup_create' => __( 'Scheduling FedEx pickup…', 'lovecatz-wc' ),
				'pickup_cancel' => __( 'Cancelling FedEx pickup…', 'lovecatz-wc' ),
				'confirm_pickup'=> __( 'Schedule this FedEx pickup? Pickup charges may apply.', 'lovecatz-wc' ),
				'confirm_cancel'=> __( 'Cancel this scheduled FedEx pickup?', 'lovecatz-wc' ),
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

	/**
	 * Add a compact shipment status to the customer's order list.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_account_tracking_column( $columns ) {
		$actions = isset( $columns['order-actions'] ) ? $columns['order-actions'] : null;
		unset( $columns['order-actions'] );
		$columns['lwc-fedex-tracking'] = __( 'Tracking', 'lovecatz-wc' );
		if ( null !== $actions ) {
			$columns['order-actions'] = $actions;
		}
		return $columns;
	}

	/**
	 * Render tracking status in My Account > Orders.
	 *
	 * @param WC_Order|int $order Order object or ID.
	 */
	public function render_account_tracking_column( $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order ) {
			echo '&mdash;';
			return;
		}
		if ( $this->is_sandbox_mode() ) {
			echo esc_html( $this->get_order_tracking_numbers( $order ) ? __( 'Test shipment', 'lovecatz-wc' ) : '—' );
			return;
		}
		$data = $order->get_meta( '_lwc_fedex_tracking_data' );
		if ( ! is_array( $data ) || empty( $data ) ) {
			echo esc_html( $this->get_order_tracking_numbers( $order ) ? __( 'Shipment created', 'lovecatz-wc' ) : '—' );
			return;
		}
		$latest = reset( $data );
		echo esc_html( ! empty( $latest['status'] ) ? $latest['status'] : __( 'In transit', 'lovecatz-wc' ) );
	}

	/**
	 * Show current status, ETA and scan events below the customer order table.
	 *
	 * @param WC_Order $order Order being viewed.
	 */
	public function render_customer_tracking( $order ) {
		if ( $this->is_sandbox_mode() || ! $order instanceof WC_Order || ! $this->get_order_tracking_numbers( $order ) ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( ! current_user_can( 'manage_woocommerce' ) && ( ! $user_id || (int) $order->get_customer_id() !== $user_id ) ) {
			return;
		}

		$updated = (int) $order->get_meta( '_lwc_fedex_tracking_updated_at' );
		if ( ! $updated || ( time() - $updated ) > (int) apply_filters( 'lwc_fedex_customer_tracking_ttl', 15 * MINUTE_IN_SECONDS ) ) {
			$this->refresh_order_tracking( $order );
		}
		$data = $order->get_meta( '_lwc_fedex_tracking_data' );
		?>
		<section class="woocommerce-order-details lwc-fedex-customer-tracking">
			<h2 class="woocommerce-order-details__title"><?php esc_html_e( 'FedEx shipment tracking', 'lovecatz-wc' ); ?></h2>
			<?php $this->render_tracking_cards( is_array( $data ) ? $data : array(), false ); ?>
		</section>
		<?php
	}

	/**
	 * Refresh all tracking numbers attached to an order and persist a snapshot.
	 *
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	private function refresh_order_tracking( $order ) {
		$numbers = $this->get_order_tracking_numbers( $order );
		$result = ( new LWC_FedEx_API() )->track_shipments( $numbers );
		if ( empty( $result['success'] ) ) {
			return $result;
		}

		$old = $order->get_meta( '_lwc_fedex_tracking_data' );
		$old = is_array( $old ) ? $old : array();
		$tracks = isset( $result['tracks'] ) && is_array( $result['tracks'] ) ? $result['tracks'] : array();
		foreach ( $tracks as $number => $track ) {
			if ( ! empty( $old[ $number ]['status'] ) && $old[ $number ]['status'] !== $track['status'] ) {
				$order->add_order_note( sprintf( __( 'FedEx tracking %1$s: %2$s', 'lovecatz-wc' ), $number, $track['status'] ) );
			}
			$old[ $number ] = $track;
		}

		$shipments = $order->get_meta( '_lwc_fedex_shipments' );
		if ( is_array( $shipments ) ) {
			foreach ( $shipments as &$shipment ) {
				if ( 'cancelled' === ( isset( $shipment['status'] ) ? $shipment['status'] : '' ) ) {
					continue;
				}
				$number = isset( $shipment['tracking_number'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $shipment['tracking_number'] ) : '';
				if ( isset( $old[ $number ] ) ) {
					$shipment['tracking'] = $old[ $number ];
				}
			}
			unset( $shipment );
			$order->update_meta_data( '_lwc_fedex_shipments', $shipments );
		}

		$order->update_meta_data( '_lwc_fedex_tracking_data', $old );
		$order->update_meta_data( '_lwc_fedex_tracking_updated_at', time() );
		$order->save();
		$result['tracks'] = $old;
		return $result;
	}

	/**
	 * AJAX: refresh tracking from the admin order screen.
	 */
	public function ajax_refresh_tracking() {
		$order = $this->get_ajax_order();
		$this->reject_sandbox_action();
		$result = $this->refresh_order_tracking( $order );
		if ( ! empty( $result['success'] ) ) {
			$result['html'] = $this->capture_tracking_cards( $result['tracks'], true );
		}
		unset( $result['response'] );
		wp_send_json( $result );
	}

	/**
	 * AJAX: cancel an active FedEx AWB and release its items for replacement.
	 */
	public function ajax_cancel_shipment() {
		$order = $this->get_ajax_order();
		if ( ! isset( $_POST['shipment'] ) ) {
			wp_send_json( array( 'success' => false, 'message' => __( 'Shipment selection is required.', 'lovecatz-wc' ) ) );
		}

		$pickup = $order->get_meta( '_lwc_fedex_pickup' );
		if ( is_array( $pickup ) && 'scheduled' === ( isset( $pickup['status'] ) ? $pickup['status'] : '' ) ) {
			wp_send_json( array( 'success' => false, 'message' => __( 'Cancel the scheduled FedEx pickup before cancelling this AWB.', 'lovecatz-wc' ) ) );
		}

		$index = absint( wp_unslash( $_POST['shipment'] ) );
		$shipments = $order->get_meta( '_lwc_fedex_shipments' );
		$shipments = is_array( $shipments ) ? $shipments : array();
		if ( ! isset( $shipments[ $index ] ) || ! is_array( $shipments[ $index ] ) ) {
			wp_send_json( array( 'success' => false, 'message' => __( 'The selected FedEx shipment was not found.', 'lovecatz-wc' ) ) );
		}
		if ( 'cancelled' === ( isset( $shipments[ $index ]['status'] ) ? $shipments[ $index ]['status'] : '' ) ) {
			wp_send_json( array( 'success' => false, 'message' => __( 'This FedEx AWB is already cancelled.', 'lovecatz-wc' ) ) );
		}

		$tracking_number = isset( $shipments[ $index ]['tracking_number'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $shipments[ $index ]['tracking_number'] ) : '';
		if ( '' === $tracking_number ) {
			wp_send_json( array( 'success' => false, 'message' => __( 'This shipment has no valid FedEx tracking number to cancel.', 'lovecatz-wc' ) ) );
		}

		$result = ( new LWC_FedEx_API() )->cancel_shipment( $tracking_number );
		if ( ! empty( $result['success'] ) ) {
			$shipments[ $index ]['status'] = 'cancelled';
			$shipments[ $index ]['cancelled_at'] = current_time( 'mysql' );
			$order->update_meta_data( '_lwc_fedex_shipments', $shipments );
			$this->remove_cancelled_tracking_data( $order, $tracking_number );
			$this->sync_latest_active_shipment( $order, $shipments );
			$order->add_order_note(
				sprintf(
					/* translators: %s: FedEx tracking number. */
					__( 'FedEx AWB cancelled through the carrier API: %s. Its items are available for a replacement label.', 'lovecatz-wc' ),
					$tracking_number
				)
			);
			$order->save();
		}
		unset( $result['response'] );
		wp_send_json( $result );
	}

	/**
	 * AJAX: check whether the requested pickup window is available.
	 */
	public function ajax_pickup_availability() {
		$order = $this->get_ajax_order();
		$this->reject_sandbox_action();
		$pickup = $this->get_pickup_request_fields();
		$result = ( new LWC_FedEx_API() )->check_pickup_availability( $order, $pickup );
		if ( ! empty( $result['success'] ) ) {
			$order->update_meta_data(
				'_lwc_fedex_pickup_availability',
				array( 'request' => $pickup, 'checked_at' => time(), 'options' => isset( $result['options'] ) ? $result['options'] : array() )
			);
			$order->save();
		}
		unset( $result['response'] );
		wp_send_json( $result );
	}

	/**
	 * AJAX: schedule a pickup after a recent availability check.
	 */
	public function ajax_schedule_pickup() {
		$order = $this->get_ajax_order();
		$this->reject_sandbox_action();
		if ( ! $this->get_order_tracking_numbers( $order ) ) {
			wp_send_json( array( 'success' => false, 'message' => __( 'Create a FedEx shipment before scheduling pickup.', 'lovecatz-wc' ) ) );
		}
		$current = $order->get_meta( '_lwc_fedex_pickup' );
		if ( is_array( $current ) && 'scheduled' === ( isset( $current['status'] ) ? $current['status'] : '' ) ) {
			wp_send_json( array( 'success' => false, 'message' => __( 'This order already has an active FedEx pickup.', 'lovecatz-wc' ) ) );
		}

		$pickup = $this->get_pickup_request_fields();
		$availability = $order->get_meta( '_lwc_fedex_pickup_availability' );
		if ( ! is_array( $availability ) || empty( $availability['checked_at'] ) || empty( $availability['request'] ) || ( time() - (int) $availability['checked_at'] ) > 15 * MINUTE_IN_SECONDS || $availability['request'] !== $pickup ) {
			wp_send_json( array( 'success' => false, 'message' => __( 'Check pickup availability again before scheduling.', 'lovecatz-wc' ) ) );
		}

		$result = ( new LWC_FedEx_API() )->create_pickup( $order, $pickup );
		if ( ! empty( $result['success'] ) ) {
			$record = array_merge(
				$pickup,
				array(
					'status'              => 'scheduled',
					'pickup_id'           => isset( $result['pickup_id'] ) ? $result['pickup_id'] : $result['confirmation_number'],
					'confirmation_number' => $result['confirmation_number'],
					'location'            => isset( $result['location'] ) ? $result['location'] : '',
					'created_at'          => current_time( 'mysql' ),
				)
			);
			$order->update_meta_data( '_lwc_fedex_pickup', $record );
			$order->delete_meta_data( '_lwc_fedex_pickup_availability' );
			$order->add_order_note(
				sprintf(
					/* translators: 1: pickup confirmation number, 2: FedEx location code. */
					__( 'FedEx pickup scheduled. Pickup ID (confirmation): %1$s. Location code: %2$s', 'lovecatz-wc' ),
					$record['pickup_id'],
					'' !== $record['location'] ? $record['location'] : '-'
				)
			);
			$order->save();
		}
		unset( $result['response'] );
		wp_send_json( $result );
	}

	/**
	 * AJAX: cancel the order's active pickup.
	 */
	public function ajax_cancel_pickup() {
		$order = $this->get_ajax_order();
		$this->reject_sandbox_action();
		$pickup = $order->get_meta( '_lwc_fedex_pickup' );
		if ( ! is_array( $pickup ) || 'scheduled' !== ( isset( $pickup['status'] ) ? $pickup['status'] : '' ) ) {
			wp_send_json( array( 'success' => false, 'message' => __( 'No active FedEx pickup was found.', 'lovecatz-wc' ) ) );
		}

		$result = ( new LWC_FedEx_API() )->cancel_pickup( $pickup );
		if ( ! empty( $result['success'] ) ) {
			$pickup['status'] = 'cancelled';
			$pickup['cancelled_at'] = current_time( 'mysql' );
			$order->update_meta_data( '_lwc_fedex_pickup', $pickup );
			$pickup_id = isset( $pickup['pickup_id'] ) ? $pickup['pickup_id'] : ( isset( $pickup['confirmation_number'] ) ? $pickup['confirmation_number'] : '' );
			$order->add_order_note( sprintf( __( 'FedEx pickup cancelled. Pickup ID (confirmation): %s', 'lovecatz-wc' ), $pickup_id ) );
			$order->save();
		}
		unset( $result['response'] );
		wp_send_json( $result );
	}

	/**
	 * Resolve and authorize an order from an admin AJAX request.
	 *
	 * @return WC_Order
	 */
	private function get_ajax_order() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json( array( 'success' => false, 'message' => __( 'You do not have permission to perform this action.', 'lovecatz-wc' ) ) );
		}
		check_ajax_referer( 'lwc_fedex_connection_check', 'nonce' );
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			wp_send_json( array( 'success' => false, 'message' => __( 'Order not found.', 'lovecatz-wc' ) ) );
		}
		return $order;
	}

	/**
	 * Whether the configured FedEx connection points to the test environment.
	 *
	 * @return bool
	 */
	private function is_sandbox_mode() {
		return class_exists( 'LWC_FedEx_Account' ) && 'yes' === LWC_FedEx_Account::get_option_value( 'lwc_fedex_test_mode', 'no' );
	}

	/**
	 * Prevent production-only fulfillment actions from being forged in Sandbox.
	 */
	private function reject_sandbox_action() {
		if ( $this->is_sandbox_mode() ) {
			wp_send_json(
				array(
					'success' => false,
					'message' => __( 'Live tracking and courier pickup are available only in FedEx Production mode.', 'lovecatz-wc' ),
				)
			);
		}
	}

	/**
	 * Validate pickup fields posted from the order metabox.
	 *
	 * @return array
	 */
	private function get_pickup_request_fields() {
		$date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		$ready = isset( $_POST['ready_time'] ) ? sanitize_text_field( wp_unslash( $_POST['ready_time'] ) ) : '';
		$close = isset( $_POST['close_time'] ) ? sanitize_text_field( wp_unslash( $_POST['close_time'] ) ) : '';
		$carrier = isset( $_POST['carrier'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['carrier'] ) ) ) : 'FDXE';
		$date_value = DateTime::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		if ( ! $date_value || $date_value->format( 'Y-m-d' ) !== $date || $date < current_time( 'Y-m-d' ) ) {
			wp_send_json( array( 'success' => false, 'message' => __( 'Choose a valid pickup date.', 'lovecatz-wc' ) ) );
		}
		$ready_value = DateTime::createFromFormat( '!H:i', $ready, wp_timezone() );
		$close_value = DateTime::createFromFormat( '!H:i', $close, wp_timezone() );
		if ( ! $ready_value || ! $close_value || $ready_value->format( 'H:i' ) !== $ready || $close_value->format( 'H:i' ) !== $close || $ready >= $close ) {
			wp_send_json( array( 'success' => false, 'message' => __( 'Pickup ready time must be earlier than the store closing time.', 'lovecatz-wc' ) ) );
		}
		if ( ! in_array( $carrier, array( 'FDXE', 'FDXG' ), true ) ) {
			$carrier = 'FDXE';
		}
		return array( 'date' => $date, 'ready_time' => $ready, 'close_time' => $close, 'carrier' => $carrier );
	}

	/**
	 * Collect tracking numbers from both modern shipment records and legacy meta.
	 *
	 * @param WC_Order $order Order object.
	 * @return string[]
	 */
	private function get_order_tracking_numbers( $order ) {
		$numbers = array();
		$shipments = $order->get_meta( '_lwc_fedex_shipments' );
		if ( is_array( $shipments ) ) {
			foreach ( $shipments as $shipment ) {
				if ( 'cancelled' === ( isset( $shipment['status'] ) ? $shipment['status'] : '' ) ) {
					continue;
				}
				if ( ! empty( $shipment['tracking_number'] ) ) {
					$numbers[] = preg_replace( '/[^A-Za-z0-9]/', '', (string) $shipment['tracking_number'] );
				}
			}
		}
		$legacy = $order->get_meta( '_lwc_fedex_tracking_number' );
		if ( is_string( $legacy ) && '' !== $legacy ) {
			$numbers[] = preg_replace( '/[^A-Za-z0-9]/', '', $legacy );
		}
		return array_values( array_unique( array_filter( $numbers ) ) );
	}

	/** Remove a cancelled AWB from the current tracking snapshot. */
	private function remove_cancelled_tracking_data( $order, $tracking_number ) {
		$data = $order->get_meta( '_lwc_fedex_tracking_data' );
		if ( ! is_array( $data ) ) {
			return;
		}
		foreach ( array_keys( $data ) as $key ) {
			if ( $tracking_number === preg_replace( '/[^A-Za-z0-9]/', '', (string) $key ) ) {
				unset( $data[ $key ] );
			}
		}
		if ( empty( $data ) ) {
			$order->delete_meta_data( '_lwc_fedex_tracking_data' );
			$order->delete_meta_data( '_lwc_fedex_tracking_updated_at' );
		} else {
			$order->update_meta_data( '_lwc_fedex_tracking_data', $data );
		}
	}

	/** Keep legacy single-shipment fields pointed at the newest active AWB. */
	private function sync_latest_active_shipment( $order, $shipments ) {
		$active = null;
		for ( $index = count( $shipments ) - 1; $index >= 0; $index-- ) {
			if ( is_array( $shipments[ $index ] ) && 'cancelled' !== ( isset( $shipments[ $index ]['status'] ) ? $shipments[ $index ]['status'] : '' ) ) {
				$active = $shipments[ $index ];
				break;
			}
		}

		if ( null === $active ) {
			$order->delete_meta_data( '_lwc_fedex_tracking_number' );
			$order->delete_meta_data( '_lwc_fedex_label_path' );
			return;
		}
		if ( ! empty( $active['tracking_number'] ) ) {
			$order->update_meta_data( '_lwc_fedex_tracking_number', (string) $active['tracking_number'] );
		} else {
			$order->delete_meta_data( '_lwc_fedex_tracking_number' );
		}
		if ( ! empty( $active['label_file'] ) ) {
			$order->update_meta_data( '_lwc_fedex_label_path', (string) $active['label_file'] );
		} else {
			$order->delete_meta_data( '_lwc_fedex_label_path' );
		}
	}

	/**
	 * Render normalized tracking cards.
	 *
	 * @param array $data Tracking map.
	 * @param bool  $compact Whether this is the narrow admin metabox.
	 */
	private function render_tracking_cards( $data, $compact = false ) {
		if ( empty( $data ) ) {
			echo '<p class="description">' . esc_html__( 'Tracking has not been refreshed yet.', 'lovecatz-wc' ) . '</p>';
			return;
		}
		foreach ( $data as $track ) {
			$number = isset( $track['tracking_number'] ) ? $track['tracking_number'] : '';
			$eta = ! empty( $track['actual_delivery'] ) ? $track['actual_delivery'] : ( isset( $track['estimated_delivery'] ) ? $track['estimated_delivery'] : '' );
			?>
			<article class="lwc-fedex-track-card">
				<div class="lwc-fedex-track-heading">
					<strong><?php echo esc_html( isset( $track['status'] ) && $track['status'] ? $track['status'] : __( 'Shipment information sent', 'lovecatz-wc' ) ); ?></strong>
					<a href="<?php echo esc_url( 'https://www.fedex.com/fedextrack/?trknbr=' . rawurlencode( $number ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $number ); ?></a>
				</div>
				<?php if ( ! empty( $track['location'] ) ) : ?><p><?php echo esc_html( $track['location'] ); ?></p><?php endif; ?>
				<?php if ( $eta ) : ?><p><strong><?php echo esc_html( ! empty( $track['actual_delivery'] ) ? __( 'Delivered:', 'lovecatz-wc' ) : __( 'Estimated delivery:', 'lovecatz-wc' ) ); ?></strong> <?php echo esc_html( $this->format_tracking_date( $eta ) ); ?></p><?php endif; ?>
				<?php if ( ! empty( $track['events'] ) ) : ?>
					<details <?php echo $compact ? '' : 'open'; ?>>
						<summary><?php esc_html_e( 'Tracking history', 'lovecatz-wc' ); ?></summary>
						<ol class="lwc-fedex-track-events">
							<?php foreach ( $track['events'] as $event ) : ?>
								<li><time><?php echo esc_html( $this->format_tracking_date( isset( $event['date'] ) ? $event['date'] : '' ) ); ?></time><strong><?php echo esc_html( isset( $event['description'] ) ? $event['description'] : '' ); ?></strong><?php if ( ! empty( $event['location'] ) ) : ?><span><?php echo esc_html( $event['location'] ); ?></span><?php endif; ?><?php if ( ! empty( $event['exception'] ) ) : ?><span class="lwc-fedex-track-exception"><?php echo esc_html( $event['exception'] ); ?></span><?php endif; ?></li>
							<?php endforeach; ?>
						</ol>
					</details>
				<?php endif; ?>
			</article>
			<?php
		}
	}

	/** Capture tracking HTML for AJAX replacement. */
	private function capture_tracking_cards( $data, $compact ) {
		ob_start();
		$this->render_tracking_cards( $data, $compact );
		return (string) ob_get_clean();
	}

	/** Format an ISO FedEx timestamp using the WordPress locale. */
	private function format_tracking_date( $value ) {
		$timestamp = strtotime( (string) $value );
		return $timestamp ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) : (string) $value;
	}
}
