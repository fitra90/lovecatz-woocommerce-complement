<?php
/**
 * Replace an order's shipping courier.
 *
 * Two modes are supported:
 *  - pre-fulfillment: the order is not processed yet (on hold / pending
 *    payment), so the courier can be swapped freely as long as the target
 *    courier plugin is really installed on this site.
 *  - cancellation:    a shipment already exists, so the previous AWB has to be
 *    cancelled with the carrier (or confirmed as cancelled) first.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_Order_Shipping_Switcher {
	const NONCE_ACTION  = 'lwc_order_shipping_switch';
	const MANUAL_PREFIX = 'lwc_courier:';

	public function init() {
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ), 20, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_lwc_get_order_shipping_options', array( $this, 'ajax_get_options' ) );
		add_action( 'wp_ajax_lwc_quote_order_shipping', array( $this, 'ajax_quote_shipping' ) );
		add_action( 'wp_ajax_lwc_change_order_shipping', array( $this, 'ajax_change_shipping' ) );
	}

	/**
	 * Order statuses that still allow the courier to be changed freely.
	 *
	 * @return array
	 */
	public static function pre_fulfillment_statuses() {
		$statuses = apply_filters( 'lwc_shipping_switch_pre_fulfillment_statuses', array( 'on-hold', 'pending' ) );

		return array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $statuses ) ) ) );
	}

	/**
	 * Register the metabox on both classic and HPOS order screens.
	 *
	 * @param string $post_type      Screen post type.
	 * @param mixed  $post_or_order  Post or order object.
	 */
	public function register_metabox( $post_type = '', $post_or_order = null ) {
		$order = $this->resolve_order( $post_or_order );
		if ( ! $this->should_render( $order ) ) {
			return;
		}

		add_meta_box(
			'lwc-change-order-shipping',
			__( 'Change Shipping', 'lovecatz-wc' ),
			array( $this, 'render_metabox' ),
			array( 'shop_order', 'woocommerce_page_wc-orders' ),
			'side',
			'default'
		);
	}

	/**
	 * Render the metabox.
	 *
	 * @param mixed $post_or_order Post or order object.
	 */
	public function render_metabox( $post_or_order ) {
		$order = $this->resolve_order( $post_or_order );
		if ( ! $order ) {
			return;
		}

		$provider    = $this->get_managed_provider( $order );
		$active      = $this->has_active_fulfillment( $order, $provider );
		$cancelled   = $this->is_fulfillment_cancelled( $order, $provider );
		$pre         = $this->is_pre_fulfillment( $order );
		$needs_cancel = $active && ! $cancelled;
		$blocked      = $needs_cancel && 'jt' === $provider;
		$current      = array();
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$current[] = $item->get_method_title();
		}

		$current_cost = 0.0;
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$current_cost += (float) $item->get_total();
		}

		$currency_code   = $order->get_currency();
		$currency_symbol = get_woocommerce_currency_symbol( $currency_code );
		?>
		<div class="lwc-order-shipping-switcher" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>" data-mode="<?php echo esc_attr( $pre ? 'pre_fulfillment' : 'cancellation' ); ?>" data-currency="<?php echo esc_attr( $currency_code ); ?>" data-currency-symbol="<?php echo esc_attr( $currency_symbol ); ?>">
			<p><strong><?php esc_html_e( 'Current:', 'lovecatz-wc' ); ?></strong> <?php echo esc_html( implode( ', ', array_filter( $current ) ) ); ?></p>
			<?php if ( $pre ) : ?>
				<p class="description"><?php esc_html_e( 'The order has not been processed yet, so the courier can still be changed. Only couriers whose plugin is installed and active are offered.', 'lovecatz-wc' ); ?></p>
			<?php endif; ?>
			<?php if ( $blocked ) : ?>
				<p class="notice notice-warning inline"><span><?php esc_html_e( 'Cancel the active J&T shipment first. A J&T tracking event with status 162 or 163 is also accepted as cancellation proof.', 'lovecatz-wc' ); ?></span></p>
			<?php else : ?>
				<?php if ( $needs_cancel ) : ?>
					<label class="lwc-shipping-cancel-confirm"><input type="checkbox" value="1"> <?php esc_html_e( 'I confirm the existing shipment/AWB was cancelled with the carrier.', 'lovecatz-wc' ); ?></label>
					<p class="description"><?php esc_html_e( 'Cancelling a pickup alone does not cancel a shipment. This confirmation is required because this carrier has no shipment-cancellation API in the plugin.', 'lovecatz-wc' ); ?></p>
				<?php endif; ?>
				<p><button type="button" class="button lwc-load-shipping-options"><?php esc_html_e( 'Load available couriers', 'lovecatz-wc' ); ?></button></p>
				<div class="lwc-shipping-options" hidden>
					<p class="lwc-destination-message" hidden></p>
					<select class="widefat lwc-new-shipping-rate" aria-label="<?php esc_attr_e( 'New shipping method', 'lovecatz-wc' ); ?>"></select>
					<?php if ( $pre ) : ?>
						<p class="lwc-manual-cost-field">
							<label for="lwc-shipping-manual-cost"><?php
								/* translators: %s: currency symbol */
								printf( esc_html__( 'Shipping cost (%s, optional)', 'lovecatz-wc' ), esc_html( $currency_symbol ) );
							?></label>
							<span class="lwc-currency-symbol"><?php echo esc_html( $currency_symbol ); ?></span>
							<input type="text" id="lwc-shipping-manual-cost" class="widefat lwc-shipping-manual-cost" inputmode="decimal" placeholder="<?php echo esc_attr( wc_format_localized_price( $current_cost ) ); ?>">
							<span class="description"><?php esc_html_e( 'Used when the selected courier has no live rate. Leave empty to keep the current cost.', 'lovecatz-wc' ); ?></span>
						</p>
					<?php endif; ?>
					<p><button type="button" class="button lwc-check-shipping-rate"><?php esc_html_e( 'Check rate', 'lovecatz-wc' ); ?></button></p>
					<div class="lwc-shipping-rate-quote" hidden>
						<strong><?php esc_html_e( 'Quoted rate:', 'lovecatz-wc' ); ?></strong>
						<span class="lwc-shipping-rate-quote__value"></span>
						<p class="description"><?php esc_html_e( 'Review this rate, then use Change Shipping to apply it to the order.', 'lovecatz-wc' ); ?></p>
					</div>
					<p><button type="button" class="button button-primary lwc-change-shipping" disabled><?php esc_html_e( 'Change Shipping', 'lovecatz-wc' ); ?></button></p>
				</div>
			<?php endif; ?>
			<div class="lwc-shipping-switch-status" aria-live="polite"></div>
		</div>
		<?php
	}

	/**
	 * Enqueue the metabox assets on order screens.
	 */
	public function enqueue_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) || ! $this->should_render( $this->resolve_order() ) ) {
			return;
		}

		wp_enqueue_style( 'lwc-order-shipping-switcher', LWC_PLUGIN_URL . 'shipping/order-shipping-switcher.css', array(), LWC_VERSION );
		wp_enqueue_script( 'lwc-order-shipping-switcher', LWC_PLUGIN_URL . 'shipping/order-shipping-switcher.js', array( 'jquery' ), LWC_VERSION, true );
		wp_localize_script(
			'lwc-order-shipping-switcher',
			'lwcOrderShippingSwitcher',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => array(
					'loading'    => __( 'Loading available couriers…', 'lovecatz-wc' ),
					'checkingRate' => __( 'Checking the selected shipping rate…', 'lovecatz-wc' ),
					'rateRequired' => __( 'Check the selected rate before changing shipping.', 'lovecatz-wc' ),
					'changing'   => __( 'Changing shipping…', 'lovecatz-wc' ),
					'confirm'    => __( 'Replace the current order shipping method and cost?', 'lovecatz-wc' ),
					'error'      => __( 'The shipping method could not be changed.', 'lovecatz-wc' ),
					'manualCost' => __( 'cost kept or entered manually', 'lovecatz-wc' ),
					'current'    => __( 'current', 'lovecatz-wc' ),
				),
			)
		);
	}

	/**
	 * AJAX: quote the selected replacement without changing the order.
	 */
	public function ajax_quote_shipping() {
		$order   = $this->get_ajax_order();
		$allowed = $this->can_change_shipping( $order, true );
		if ( is_wp_error( $allowed ) ) {
			wp_send_json_error( array( 'message' => $allowed->get_error_message() ) );
		}

		$selected = isset( $_POST['rate_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rate_id'] ) ) : '';
		$address  = $this->get_address_override_from_request();
		$rate     = $this->resolve_selection( $order, $selected, $address );
		if ( is_wp_error( $rate ) ) {
			wp_send_json_error( array( 'message' => $rate->get_error_message() ) );
		}

		$cost      = $this->rate_cost_for_order( $rate, $order );
		$label     = $this->decode_label( $rate->get_label() );
		$token     = wp_generate_uuid4();
		$quote     = array(
			'order_id'               => (int) $order->get_id(),
			'user_id'                => get_current_user_id(),
			'selected'               => $selected,
			'method_id'              => (string) $rate->get_method_id(),
			'label'                  => $label,
			'cost'                   => (float) $cost,
			'destination_fingerprint'=> $this->destination_fingerprint( $order, $address ),
			'created_at'             => time(),
		);
		set_transient( $this->quote_transient_key( $token ), $quote, 10 * MINUTE_IN_SECONDS );

		wp_send_json_success(
			array(
				'quote_token' => $token,
				'label'       => $label,
				'cost'        => $cost,
				'formatted'   => html_entity_decode( wp_strip_all_tags( wc_price( $cost, array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
				'manual'      => 0 === strpos( $selected, self::MANUAL_PREFIX ),
			)
		);
	}

	/**
	 * AJAX: list the couriers/rates that can replace the current one.
	 */
	public function ajax_get_options() {
		$order = $this->get_ajax_order();
		// Loading rates is read-only. A non-J&T external cancellation
		// confirmation is enforced only when the replacement is submitted.
		$allowed = $this->can_change_shipping( $order, true );
		if ( is_wp_error( $allowed ) ) {
			wp_send_json_error( array( 'message' => $allowed->get_error_message() ) );
		}

		$address = $this->get_address_override_from_request();
		$result  = $this->build_options( $order, $address );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		if ( ! $result['options'] && empty( $result['manual_options'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No installed courier service is currently available for this order address.', 'lovecatz-wc' ) ) );
		}

		wp_send_json_success(
			array(
				'options'               => $result['options'],
				'manual_options'        => $result['manual_options'],
				'destination_valid'     => $result['destination_valid'],
				'destination_message'   => $result['destination_message'],
				'rate_notice'           => $result['rate_notice'],
				'destination'           => $result['destination'],
				'currency'              => $result['currency'],
				'currency_symbol'       => $result['currency_symbol'],
			)
		);
	}

	/**
	 * AJAX: apply the courier change.
	 */
	public function ajax_change_shipping() {
		$order     = $this->get_ajax_order();
		$confirmed = isset( $_POST['cancel_confirmed'] ) && '1' === (string) wp_unslash( $_POST['cancel_confirmed'] );
		$allowed   = $this->can_change_shipping( $order, $confirmed );
		if ( is_wp_error( $allowed ) ) {
			wp_send_json_error( array( 'message' => $allowed->get_error_message() ) );
		}

		$selected = isset( $_POST['rate_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rate_id'] ) ) : '';
		$token    = isset( $_POST['quote_token'] ) ? sanitize_text_field( wp_unslash( $_POST['quote_token'] ) ) : '';
		$address  = $this->get_address_override_from_request();
		$quote    = $this->validate_quote( $order, $selected, $token, $address );
		if ( is_wp_error( $quote ) ) {
			wp_send_json_error( array( 'message' => $quote->get_error_message() ) );
		}

		$rate = $this->resolve_selection( $order, $selected, $address );
		if ( is_wp_error( $rate ) ) {
			wp_send_json_error( array( 'message' => $rate->get_error_message() ) );
		}
		$current_cost = $this->rate_cost_for_order( $rate, $order );
		$current_label = $this->decode_label( $rate->get_label() );
		if ( (string) $rate->get_method_id() !== (string) $quote['method_id'] || $current_label !== (string) $quote['label'] || abs( $current_cost - (float) $quote['cost'] ) > 0.01 ) {
			delete_transient( $this->quote_transient_key( $token ) );
			wp_send_json_error( array( 'message' => __( 'The selected shipping rate changed. Check the rate again before applying it.', 'lovecatz-wc' ) ) );
		}

		$this->apply_change( $order, $rate, $confirmed );
		delete_transient( $this->quote_transient_key( $token ) );

		$courier = LWC_Courier_Registry::resolve_method_id( $rate->get_method_id() );

		wp_send_json_success(
			array(
				'message'        => __( 'Shipping was changed successfully.', 'lovecatz-wc' ),
				'courier'        => $courier,
				'courier_label'  => $courier ? LWC_Courier_Registry::get( $courier )['label'] : $rate->get_label(),
				'currency'       => $order->get_currency(),
				'currency_symbol'=> get_woocommerce_currency_symbol( $order->get_currency() ),
				'next_step_html' => $this->render_next_step_html( $order, $courier ),
			)
		);
	}

	/**
	 * Build the inline panel shown after a successful courier switch.
	 *
	 * @param WC_Order $order        Order object.
	 * @param string   $courier_slug New courier slug.
	 * @return string
	 */
	private function render_next_step_html( $order, $courier_slug ) {
		$label = $courier_slug ? LWC_Courier_Registry::get( $courier_slug )['label'] : '';
		$order_id  = (int) $order->get_id();
		$providers_with_create = array( 'jt', 'fedex', 'rayspeed' );
		$provider_key = '';
		if ( 'jt_express' === $courier_slug ) {
			$provider_key = 'jt';
		} elseif ( in_array( $courier_slug, $providers_with_create, true ) ) {
			$provider_key = $courier_slug;
		}

		ob_start();
		?>
		<div class="lwc-shipping-next-step">
			<p class="lwc-shipping-next-step__summary">
				<?php
				/* translators: %s: courier label */
				printf( esc_html__( 'Switched to %s.', 'lovecatz-wc' ), '<strong>' . esc_html( $label ? $label : __( 'the selected courier', 'lovecatz-wc' ) ) . '</strong>' );
				?>
			</p>
			<?php if ( '' !== $provider_key ) : ?>
				<span class="lwc-shipping-actions" data-order-id="<?php echo esc_attr( $order_id ); ?>" data-provider="<?php echo esc_attr( $provider_key ); ?>">
					<button type="button" class="button button-primary lwc-shipping-action" data-action="create">
						<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
						<?php
						/* translators: %s: courier label */
						printf( esc_html__( 'Create AWB with %s', 'lovecatz-wc' ), esc_html( $label ) );
						?>
					</button>
				</span>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Reload the page to use the new courier’s admin tools.', 'lovecatz-wc' ); ?></p>
			<?php endif; ?>
			<p>
				<button type="button" class="button lwc-shipping-reload"><?php esc_html_e( 'Reload order', 'lovecatz-wc' ); ?></button>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Replace every shipping line on the order with the selected rate.
	 *
	 * Kept separate from the AJAX handler so it can be reused and tested.
	 *
	 * @param WC_Order          $order     Order object.
	 * @param WC_Shipping_Rate  $rate      Selected rate.
	 * @param bool              $confirmed Admin confirmed the previous AWB cancellation.
	 * @return true
	 */
	public function apply_change( $order, $rate, $confirmed = false ) {
		$new_label   = $this->decode_label( $rate->get_label() );
		$new_cost    = $this->rate_cost_for_order( $rate, $order );
		$old_provider = $this->get_managed_provider( $order );
		$old_titles   = array();
		$old_items    = array();
		foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
			$old_titles[] = $item->get_method_title();
			$old_items[]  = array(
				'method_id' => $item->get_method_id(),
				'title'     => $item->get_method_title(),
				'total'     => $item->get_total(),
				'meta'      => $this->item_meta_array( $item ),
			);
			$order->remove_item( $item_id );
		}

		$history   = $order->get_meta( '_lwc_shipping_change_history' );
		$history   = is_array( $history ) ? $history : array();
		$history[] = array(
			'changed_at'          => current_time( 'mysql' ),
			'changed_by'          => get_current_user_id(),
			'mode'                => $this->is_pre_fulfillment( $order ) ? 'pre_fulfillment' : 'cancellation',
			'order_status'        => $order->get_status(),
			'cancellation_source' => $confirmed ? 'admin_confirmation' : ( $this->has_active_fulfillment( $order, $old_provider ) ? 'carrier_api_or_tracking' : 'no_awb' ),
			'old_shipping'        => $old_items,
			'old_fulfillment'     => $this->fulfillment_snapshot( $order, $old_provider ),
			'new_shipping'        => array(
				'method_id'  => $rate->get_method_id(),
				'title'      => $new_label,
				'total'      => $new_cost,
				'courier'    => LWC_Courier_Registry::resolve_method_id( $rate->get_method_id() ),
			),
		);
		$order->update_meta_data( '_lwc_shipping_change_history', array_slice( $history, -20 ) );
		$order->update_meta_data( '_lwc_shipping_change_count', absint( $order->get_meta( '_lwc_shipping_change_count' ) ) + 1 );
		$this->clear_active_fulfillment( $order, $old_provider );

		$item = new WC_Order_Item_Shipping();
		$item->set_method_title( $new_label );
		$item->set_method_id( $rate->get_method_id() );
		$item->set_instance_id( $rate->get_instance_id() );
		$item->set_total( wc_format_decimal( $new_cost ) );
		$item->set_taxes( array( 'total' => (array) $rate->get_taxes() ) );
		foreach ( (array) $rate->get_meta_data() as $key => $value ) {
			$item->add_meta_data( $key, $value, true );
		}
		$order->add_item( $item );

		$courier = LWC_Courier_Registry::resolve_method_id( $rate->get_method_id() );
		if ( '' !== $courier ) {
			$order->update_meta_data( '_lwc_shipping_courier', $courier );
		}

		$order->calculate_totals( false );
		$order->save();

		$formatted = html_entity_decode( wp_strip_all_tags( wc_price( $new_cost, array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, get_bloginfo( 'charset' ) );
		$order->add_order_note(
			$this->is_pre_fulfillment( $order )
				? sprintf(
					/* translators: 1: old shipping title, 2: new shipping title, 3: new shipping cost */
					__( 'Shipping courier changed from %1$s to %2$s (%3$s) while the order was still on hold and not yet processed. Create the shipment/AWB for the new courier.', 'lovecatz-wc' ),
					implode( ', ', array_filter( $old_titles ) ),
					$new_label,
					$formatted
				)
				: sprintf(
					/* translators: 1: old shipping title, 2: new shipping title, 3: new shipping cost */
					__( 'Shipping changed from %1$s to %2$s (%3$s) after cancellation confirmation. Create the new shipment/AWB manually.', 'lovecatz-wc' ),
					implode( ', ', array_filter( $old_titles ) ),
					$new_label,
					$formatted
				)
		);

		return true;
	}

	/**
	 * Build the selectable options for an order.
	 *
	 * Live rates are offered per service when the buyer's shipping address is
	 * complete enough for the courier APIs to return a tariff. Couriers that
	 * are installed but return no live rate (for example the internal J&T
	 * Cargo method) are offered as a manual entry that keeps or overrides the
	 * current cost.
	 *
	 * @param WC_Order $order   Order object.
	 * @param array    $address Optional destination override from the edit screen.
	 * @return array {
	 *     @type array  $options               Live-rate options.
	 *     @type array  $manual_options        Fallback courier options without live rates.
	 *     @type bool   $destination_valid     Whether the destination is complete enough for tariffs.
	 *     @type string $destination_message   Notice shown when the destination is incomplete.
	 *     @type array  $destination           Resolved destination used for the tariff.
	 *     @type string $currency              Order currency code.
	 *     @type string $currency_symbol       Store/order currency symbol.
	 * }
	 */
	private function build_options( $order, $address = array() ) {
		$package = $this->build_order_package( $order, $address );
		if ( is_wp_error( $package ) ) {
			return $package;
		}

		$destination_valid   = $this->destination_is_complete( $package );
		$destination_message = $destination_valid
			? ''
			: __( 'The buyer’s shipping address is incomplete (country/state/city/postcode). Live courier tariffs are hidden until it is filled in.', 'lovecatz-wc' );

		$current_slug = LWC_Courier_Registry::courier_for_order( $order );
		$current_cost = 0.0;
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$current_cost += (float) $item->get_total();
		}

		$available = LWC_Courier_Registry::get_available();
		$seen      = array();
		$options   = array();

		if ( $destination_valid ) {
			$rates = $this->calculate_rates( $order, $package );
			if ( ! is_wp_error( $rates ) ) {
				foreach ( $rates as $rate ) {
					if ( ! $rate instanceof WC_Shipping_Rate ) {
						continue;
					}
					$slug = LWC_Courier_Registry::resolve_method_id( $rate->get_method_id() );
					if ( '' === $slug || ! isset( $available[ $slug ] ) ) {
						continue;
					}
					$seen[ $slug ] = true;
					$cost          = $this->rate_cost_for_order( $rate, $order );
					$options[]     = array(
						'id'        => $rate->get_id(),
						'courier'   => $slug,
						'label'     => $this->decode_label( $rate->get_label() ),
						'cost'      => $cost,
						'formatted' => html_entity_decode( wp_strip_all_tags( wc_price( $cost, array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
						'current'   => $slug === $current_slug,
					);
				}
			}
		}

		$manual_options = array();
		foreach ( $available as $slug => $courier ) {
			if ( isset( $seen[ $slug ] ) ) {
				continue;
			}
			$entry = array(
				'id'        => self::MANUAL_PREFIX . $slug,
				'courier'   => $slug,
				'label'     => $courier['label'],
				'cost'      => $current_cost,
				'formatted' => html_entity_decode( wp_strip_all_tags( wc_price( $current_cost, array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
				'manual'    => true,
				'current'   => $slug === $current_slug,
			);
			// A courier that prices by destination cannot be offered while the
			// destination is unknown — that is the only case where we would be
			// guessing at a tariff. Couriers without destination pricing (for
			// example the internal J&T Cargo method) stay selectable.
			if ( ! $destination_valid && ! empty( $courier['uses_tariff'] ) ) {
				$entry['unavailable']        = true;
				$entry['unavailable_reason'] = __( 'Destination address incomplete.', 'lovecatz-wc' );
			}
			$manual_options[] = $entry;
		}

		$currency_code   = $order->get_currency();
		$currency_symbol = get_woocommerce_currency_symbol( $currency_code );

		$rate_notice = '';
		if ( $destination_valid && ! $options ) {
			$rate_notice = __( 'No live tariff was returned for this destination. Choose a courier manually and enter the shipping cost.', 'lovecatz-wc' );
		}

		return array(
			'options'             => $options,
			'manual_options'      => $manual_options,
			'destination_valid'   => $destination_valid,
			'destination_message' => $destination_message,
			'rate_notice'         => $rate_notice,
			'destination'         => $package['destination'],
			'currency'            => $currency_code,
			'currency_symbol'     => $currency_symbol,
		);
	}

	/**
	 * Turn the submitted selection into a shipping rate.
	 *
	 * @param WC_Order $order    Order object.
	 * @param string   $selected Submitted rate id or manual courier id.
	 * @return WC_Shipping_Rate|WP_Error
	 */
	private function resolve_selection( $order, $selected, $address = array() ) {
		if ( 0 === strpos( $selected, self::MANUAL_PREFIX ) ) {
			return $this->build_manual_rate( $order, substr( $selected, strlen( self::MANUAL_PREFIX ) ) );
		}

		$package = $this->build_order_package( $order, $address );
		if ( is_wp_error( $package ) ) {
			return $package;
		}
		$rates = $this->calculate_rates( $order, $package );
		if ( is_wp_error( $rates ) ) {
			return $rates;
		}

		$rate = isset( $rates[ $selected ] ) ? $rates[ $selected ] : null;
		if ( ! $rate instanceof WC_Shipping_Rate ) {
			return new WP_Error( 'lwc_shipping_rate_gone', __( 'The selected rate is no longer available. Load the couriers again.', 'lovecatz-wc' ) );
		}

		$slug = LWC_Courier_Registry::resolve_method_id( $rate->get_method_id() );
		$installed = $this->validate_courier( $slug );
		if ( is_wp_error( $installed ) ) {
			return $installed;
		}

		return $rate;
	}

	/**
	 * Build a rate for an installed courier that publishes no live rate.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $slug  Courier slug.
	 * @return WC_Shipping_Rate|WP_Error
	 */
	private function build_manual_rate( $order, $slug ) {
		$installed = $this->validate_courier( $slug );
		if ( is_wp_error( $installed ) ) {
			return $installed;
		}

		$courier = LWC_Courier_Registry::get( $slug );
		$cost    = $this->posted_cost();
		if ( null === $cost ) {
			$cost = 0.0;
			foreach ( $order->get_items( 'shipping' ) as $item ) {
				$cost += (float) $item->get_total();
			}
		}

		$method_id = isset( $courier['method_ids'][0] ) ? $courier['method_ids'][0] : '';
		$rate      = new WC_Shipping_Rate( self::MANUAL_PREFIX . $slug, $courier['label'], $cost, array(), $method_id );

		return $rate;
	}

	/**
	 * Validate that the target courier is installed before it is applied.
	 *
	 * @param string $slug Courier slug.
	 * @return bool|WP_Error
	 */
	private function validate_courier( $slug ) {
		if ( '' === $slug || ! LWC_Courier_Registry::get( $slug ) ) {
			return new WP_Error( 'lwc_shipping_unknown_courier', __( 'The selected courier is not known to this plugin.', 'lovecatz-wc' ) );
		}

		if ( ! LWC_Courier_Registry::is_installed( $slug ) ) {
			$courier = LWC_Courier_Registry::get( $slug );
			return new WP_Error(
				'lwc_shipping_courier_not_installed',
				sprintf(
					/* translators: %s: courier name */
					__( '%s is not installed/active on this site, so the shipping method cannot be changed to it.', 'lovecatz-wc' ),
					$courier['label']
				)
			);
		}

		return true;
	}

	/**
	 * Read an optional manual shipping cost from the request.
	 *
	 * @return float|null
	 */
	private function posted_cost() {
		if ( ! isset( $_POST['manual_cost'] ) ) {
			return null;
		}

		$raw = trim( sanitize_text_field( wp_unslash( $_POST['manual_cost'] ) ) );
		if ( '' === $raw ) {
			return null;
		}

		$cost = wc_format_decimal( $raw );
		if ( ! is_numeric( $cost ) || (float) $cost < 0 ) {
			return null;
		}

		return (float) $cost;
	}

	/**
	 * Calculate the live rates offered for an order.
	 *
	 * Only couriers whose plugin is installed and selectable are kept. The
	 * tariffs are returned in the store base currency: the currency converter
	 * is detached for this call because it would otherwise rewrite the amounts
	 * into the admin's shopper currency while the order screen still formats
	 * them with the order currency (which produced values like "Rp1").
	 *
	 * @param WC_Order $order     Order object.
	 * @param array    $prebuilt  Optional pre-built shipping package.
	 * @return array|WP_Error
	 */
	private function calculate_rates( $order, $prebuilt = null ) {
		$package = is_array( $prebuilt ) ? $prebuilt : $this->build_order_package( $order );
		$rates   = array();
		try {
			$zone = WC_Shipping_Zones::get_zone_matching_package( $package );
			foreach ( $zone->get_shipping_methods( true ) as $method ) {
				if ( $method instanceof WC_Shipping_Method && $method->is_available( $package ) ) {
					$rates = $rates + $method->get_rates_for_package( $package );
				}
			}
			$rates = $this->apply_package_rates_filter( $rates, $package );
		} catch ( Throwable $error ) {
			return new WP_Error( 'lwc_shipping_rate_error', $error->getMessage() );
		}

		$allowed_methods = array();
		foreach ( LWC_Courier_Registry::get_available() as $courier ) {
			$allowed_methods = array_merge( $allowed_methods, $courier['method_ids'] );
		}
		$allowed_methods = array_values( array_unique( array_filter( $allowed_methods ) ) );

		foreach ( (array) $rates as $rate_id => $rate ) {
			if ( ! $rate instanceof WC_Shipping_Rate || ! in_array( $rate->get_method_id(), $allowed_methods, true ) ) {
				unset( $rates[ $rate_id ] );
			}
		}

		return $rates;
	}

	/**
	 * Run the package-rates filter with the currency converter detached.
	 *
	 * @param array $rates   Shipping rates.
	 * @param array $package Shipping package.
	 * @return array
	 */
	private function apply_package_rates_filter( $rates, $package ) {
		$detached = $this->detach_shipping_rate_converter();
		$rates    = apply_filters( 'woocommerce_package_rates', $rates, $package );
		$this->restore_shipping_rate_converter( $detached );

		return $rates;
	}

	/**
	 * Remove the LoveCatz currency converter from the package-rates filter.
	 *
	 * @return array Detached callbacks so they can be restored afterwards.
	 */
	private function detach_shipping_rate_converter() {
		$detached = array();

		if ( empty( $GLOBALS['wp_filter']['woocommerce_package_rates'] ) ) {
			return $detached;
		}

		$hook = $GLOBALS['wp_filter']['woocommerce_package_rates'];
		foreach ( $hook->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = isset( $callback['function'] ) ? $callback['function'] : null;
				if ( ! is_array( $function ) || ! is_object( $function[0] ) || ! isset( $function[1] ) ) {
					continue;
				}
				if ( 'convert_shipping_rates' !== $function[1] ) {
					continue;
				}
				remove_filter( 'woocommerce_package_rates', $function, $priority );
				$detached[] = array(
					'callback' => $function,
					'priority' => $priority,
					'args'     => isset( $callback['accepted_args'] ) ? (int) $callback['accepted_args'] : 2,
				);
			}
		}

		return $detached;
	}

	/**
	 * Re-attach callbacks removed by detach_shipping_rate_converter().
	 *
	 * @param array $detached Detached callback list.
	 */
	private function restore_shipping_rate_converter( $detached ) {
		foreach ( (array) $detached as $entry ) {
			add_filter( 'woocommerce_package_rates', $entry['callback'], $entry['priority'], $entry['args'] );
		}
	}

	/**
	 * Convert a base-currency amount into the order currency.
	 *
	 * Orders placed in the store base currency pass through untouched, which is
	 * the normal case on this store (IDR). Orders that were genuinely placed in
	 * another currency use the rate frozen on the order, falling back to the
	 * configured rate table.
	 *
	 * @param float    $amount Amount in the store base currency.
	 * @param WC_Order $order  Order object.
	 * @return float
	 */
	private function to_order_currency( $amount, $order ) {
		$amount = (float) $amount;
		$base   = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) get_option( 'woocommerce_currency', '' ) ) );
		$target = strtoupper( (string) $order->get_currency() );

		if ( $amount <= 0 || '' === $base || '' === $target || $base === $target ) {
			return $amount;
		}

		$rate = (float) $order->get_meta( '_lwc_currency_rate', true );
		if ( $rate <= 0 && class_exists( 'LWC_Currency_Converter' ) ) {
			$converter = LWC_Currency_Converter::instance();
			if ( method_exists( $converter, 'get_rate_for' ) ) {
				$rate = (float) $converter->get_rate_for( $target );
			}
		}
		if ( $rate <= 0 ) {
			return $amount;
		}

		return (float) ceil( $amount / $rate );
	}

	/**
	 * Rate cost expressed in the order currency.
	 *
	 * @param WC_Shipping_Rate $rate  Shipping rate.
	 * @param WC_Order         $order Order object.
	 * @return float
	 */
	private function rate_cost_for_order( $rate, $order ) {
		if ( method_exists( $rate, 'get_id' ) && 0 === strpos( (string) $rate->get_id(), self::MANUAL_PREFIX ) ) {
			return (float) $rate->get_cost();
		}
		return $this->to_order_currency( (float) $rate->get_cost(), $order );
	}

	/**
	 * Decode HTML entities some carrier plugins embed in their rate labels.
	 *
	 * JNE returns labels such as "JNE JTR (6 &#8211; 7 days)"; without this the
	 * entity is escaped again by the select element and shown verbatim.
	 *
	 * @param string $label Rate label.
	 * @return string
	 */
	private function decode_label( $label ) {
		return html_entity_decode( (string) $label, ENT_QUOTES, get_bloginfo( 'charset' ) );
	}

	/**
	 * Rebuild a shipping package from an existing order.
	 *
	 * @param WC_Order $order    Order object.
	 * @param array    $override Optional destination override (country/state/city/postcode/address/district).
	 * @return array
	 */
	private function build_order_package( $order, $override = array() ) {
		$contents      = array();
		$contents_cost = 0.0;
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$contents[ $item_id ] = array(
				'key'        => (string) $item_id,
				'data'       => $product,
				'quantity'   => max( 1, (int) $item->get_quantity() ),
				'line_total' => (float) $item->get_total(),
			);
			$contents_cost += (float) $item->get_total();
		}

		$override = is_array( $override ) ? array_change_key_case( $override, CASE_LOWER ) : array();
		$override = array_intersect_key(
			$override,
			array_flip( array( 'country', 'state', 'city', 'postcode', 'address', 'address_1', 'address_2', 'district' ) )
		);
		foreach ( $override as $key => $value ) {
			$override[ $key ] = is_string( $value ) ? trim( (string) $value ) : '';
		}

		// A submitted address is authoritative: when the edit screen supplies
		// one we never mix it with the saved order address, otherwise a partly
		// typed address would be quoted against the old city/postcode and show
		// a tariff for the wrong destination.
		$has_override = false;
		foreach ( $override as $value ) {
			if ( '' !== $value ) {
				$has_override = true;
				break;
			}
		}

		$use_shipping = $has_override || '' !== trim( (string) $order->get_shipping_country() );
		$prefix       = $use_shipping ? 'shipping' : 'billing';

		$get = static function ( $field, $override_key ) use ( $order, $prefix, $override, $has_override ) {
			if ( $has_override ) {
				return array_key_exists( $override_key, $override ) ? (string) $override[ $override_key ] : '';
			}
			$getter = "get_{$prefix}_{$field}";

			return method_exists( $order, $getter ) ? (string) $order->$getter() : '';
		};

		$country  = strtoupper( $get( 'country', 'country' ) );
		$state    = $get( 'state', 'state' );
		$city     = $get( 'city', 'city' );
		$postcode = $get( 'postcode', 'postcode' );
		$address1 = $get( 'address_1', 'address_1' );
		if ( '' === $address1 ) {
			$address1 = $get( 'address_1', 'address' );
		}
		$address2 = $get( 'address_2', 'address_2' );

		$district = (string) $order->get_meta( "_lwc_{$prefix}_district_name", true );
		if ( '' === $district ) {
			$district = (string) $order->get_meta( "_wc_{$prefix}/lwc/indonesia-district", true );
		}
		if ( $has_override ) {
			$district = isset( $override['district'] ) ? (string) $override['district'] : '';
		}

		return array(
			'contents'        => $contents,
			'contents_cost'   => $contents_cost,
			'applied_coupons' => $order->get_coupon_codes(),
			'user'            => array( 'ID' => $order->get_customer_id() ),
			'destination'     => array(
				'country'                => $country,
				'state'                  => $state,
				'postcode'               => $postcode,
				'city'                   => $city,
				'address'                => $address1,
				'address_1'              => $address1,
				'address_2'              => $address2,
				'lwc_indonesia_district' => $district,
			),
			'cart_subtotal' => (float) $order->get_subtotal(),
		);
	}

	/**
	 * Whether the destination is complete enough for a live courier tariff.
	 *
	 * @param array $package Built package array.
	 * @return bool
	 */
	private function destination_is_complete( $package ) {
		$d       = isset( $package['destination'] ) ? (array) $package['destination'] : array();
		$country = strtoupper( trim( (string) ( $d['country'] ?? '' ) ) );

		if ( '' === $country ) {
			return false;
		}

		if ( 'ID' === $country ) {
			$state    = trim( (string) ( $d['state'] ?? '' ) );
			$city     = trim( (string) ( $d['city'] ?? '' ) );
			$postcode = trim( (string) ( $d['postcode'] ?? '' ) );
			if ( '' === $state || '' === $city || '' === $postcode ) {
				return false;
			}
		} else {
			$postcode = trim( (string) ( $d['postcode'] ?? '' ) );
			if ( '' === $postcode ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Read the destination override submitted with the AJAX request.
	 *
	 * @return array
	 */
	private function get_address_override_from_request() {
		if ( empty( $_POST['destination'] ) ) {
			return array();
		}

		$raw = wp_unslash( $_POST['destination'] );
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		return $raw;
	}

	/**
	 * Validate the short-lived quote that authorizes the final change.
	 *
	 * @param WC_Order $order    Order being changed.
	 * @param string   $selected Selected rate id.
	 * @param string   $token    Quote token.
	 * @param array    $address  Destination used for the quote.
	 * @return array|WP_Error
	 */
	private function validate_quote( $order, $selected, $token, $address ) {
		if ( '' === $token ) {
			return new WP_Error( 'lwc_shipping_quote_required', __( 'Check the selected shipping rate before changing shipping.', 'lovecatz-wc' ) );
		}

		$quote = get_transient( $this->quote_transient_key( $token ) );
		if ( ! is_array( $quote ) ) {
			return new WP_Error( 'lwc_shipping_quote_expired', __( 'The shipping quote expired. Check the rate again.', 'lovecatz-wc' ) );
		}

		$matches = (int) $order->get_id() === (int) ( $quote['order_id'] ?? 0 )
			&& get_current_user_id() === (int) ( $quote['user_id'] ?? 0 )
			&& (string) $selected === (string) ( $quote['selected'] ?? '' );
		if ( ! $matches ) {
			return new WP_Error( 'lwc_shipping_quote_mismatch', __( 'The selected shipping method does not match the checked rate. Check it again.', 'lovecatz-wc' ) );
		}

		$current_fingerprint = $this->destination_fingerprint( $order, $address );
		$quoted_fingerprint  = (string) ( $quote['destination_fingerprint'] ?? '' );
		if ( '' === $quoted_fingerprint || ! hash_equals( $quoted_fingerprint, $current_fingerprint ) ) {
			delete_transient( $this->quote_transient_key( $token ) );
			return new WP_Error( 'lwc_shipping_destination_changed', __( 'The destination address changed. Check the shipping rate again.', 'lovecatz-wc' ) );
		}

		return $quote;
	}

	/** Build a stable fingerprint for the destination used by a quote. */
	private function destination_fingerprint( $order, $address ) {
		$package     = $this->build_order_package( $order, $address );
		$destination = is_array( $package ) && isset( $package['destination'] ) ? $package['destination'] : array();
		return hash( 'sha256', (string) wp_json_encode( $destination ) );
	}

	/** Return the private transient key for one opaque quote token. */
	private function quote_transient_key( $token ) {
		return 'lwc_shipping_quote_' . md5( (string) $token );
	}

	/**
	 * Whether the courier may be changed right now.
	 *
	 * @param WC_Order $order     Order object.
	 * @param bool     $confirmed Admin confirmed the previous AWB cancellation.
	 * @return bool|WP_Error
	 */
	private function can_change_shipping( $order, $confirmed ) {
		$provider = $this->get_managed_provider( $order );

		if ( ! $this->is_pre_fulfillment( $order ) && ! $provider ) {
			return new WP_Error( 'lwc_shipping_unmanaged', __( 'This order does not use a shipping method managed by this plugin.', 'lovecatz-wc' ) );
		}

		if ( ! $this->has_active_fulfillment( $order, $provider ) || $this->is_fulfillment_cancelled( $order, $provider ) ) {
			return true;
		}

		if ( 'jt' === $provider ) {
			return new WP_Error( 'lwc_shipping_not_cancelled', __( 'J&T still has an active shipment. Cancel it first or refresh tracking until status 162/163 is received.', 'lovecatz-wc' ) );
		}

		return $confirmed ? true : new WP_Error( 'lwc_shipping_confirmation_required', __( 'Confirm that the existing shipment/AWB was cancelled with the carrier before changing shipping.', 'lovecatz-wc' ) );
	}

	/**
	 * Whether the metabox should be rendered for an order.
	 *
	 * @param mixed $order Order object.
	 * @return bool
	 */
	private function should_render( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		if ( ! $order->get_items( 'shipping' ) ) {
			return false;
		}

		if ( ! LWC_Courier_Registry::get_available() ) {
			return false;
		}

		if ( $this->is_pre_fulfillment( $order ) ) {
			return true;
		}

		return (bool) $this->get_managed_provider( $order );
	}

	/**
	 * Whether the order is still waiting before processing.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	private function is_pre_fulfillment( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		return in_array( $order->get_status(), self::pre_fulfillment_statuses(), true );
	}

	/**
	 * Provider key of the plugin-managed courier used by an order.
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	private function get_managed_provider( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$id = (string) $item->get_method_id();
			if ( in_array( $id, array( 'lwc_jt_express', 'lwc_jt' ), true ) ) {
				return 'jt';
			}
			if ( 'lwc_jt_cargo' === $id ) {
				return 'jt_cargo';
			}
			if ( in_array( $id, array( 'lwc_fedex', 'lwc_shipping_fedex' ), true ) ) {
				return 'fedex';
			}
			if ( 'lwc_rayspeed' === $id ) {
				return 'rayspeed';
			}
		}

		return '';
	}

	/**
	 * Whether the order still has a live shipment with its carrier.
	 *
	 * @param WC_Order $order    Order object.
	 * @param string   $provider Provider key.
	 * @return bool
	 */
	private function has_active_fulfillment( $order, $provider ) {
		if ( 'jt' === $provider ) {
			return '' !== (string) $order->get_meta( '_lwc_jt_awb' );
		}
		if ( 'rayspeed' === $provider ) {
			return '' !== (string) $order->get_meta( '_lwc_rayspeed_awb' );
		}
		if ( 'jt_cargo' === $provider ) {
			return '' !== (string) $order->get_meta( '_lwc_jt_cargo_awb' ) || '' !== (string) $order->get_meta( '_lwc_jt_cargo_tracking_number' );
		}
		if ( 'fedex' === $provider ) {
			$shipments = $order->get_meta( '_lwc_fedex_shipments' );
			if ( is_array( $shipments ) ) {
				foreach ( $shipments as $shipment ) {
					if ( is_array( $shipment ) && 'cancelled' !== ( isset( $shipment['status'] ) ? $shipment['status'] : '' ) && ! empty( $shipment['tracking_number'] ) ) {
						return true;
					}
				}
			}
			return '' !== (string) $order->get_meta( '_lwc_fedex_tracking_number' );
		}

		return false;
	}

	/**
	 * Whether the carrier confirmed the previous shipment was cancelled.
	 *
	 * @param WC_Order $order    Order object.
	 * @param string   $provider Provider key.
	 * @return bool
	 */
	private function is_fulfillment_cancelled( $order, $provider ) {
		if ( 'jt' !== $provider ) {
			return false;
		}
		if ( $order->get_meta( '_lwc_jt_cancelled' ) ) {
			return true;
		}
		$tracking = $order->get_meta( '_lwc_jt_tracking' );

		return class_exists( 'LWC_JT_Express_API' ) && is_array( $tracking ) && LWC_JT_Express_API::tracking_confirms_cancellation( isset( $tracking['history'] ) ? $tracking['history'] : array() );
	}

	/**
	 * Snapshot the fulfilment meta that is about to be released.
	 *
	 * @param WC_Order $order    Order object.
	 * @param string   $provider Provider key.
	 * @return array
	 */
	private function fulfillment_snapshot( $order, $provider ) {
		$keys = array(
			'jt'       => array( '_lwc_jt_awb', '_lwc_jt_order_id', '_lwc_jt_etd', '_lwc_jt_tracking', '_lwc_jt_cancelled', '_lwc_jt_cancel_error', '_lwc_jt_declared_weight_kg' ),
			'fedex'    => array( '_lwc_fedex_tracking_number', '_lwc_fedex_shipments', '_lwc_fedex_tracking_data', '_lwc_fedex_pickup', '_lwc_fedex_label_path' ),
			'rayspeed' => array( '_lwc_rayspeed_awb', '_lwc_rayspeed_tracking' ),
			'jt_cargo' => array( '_lwc_jt_cargo_awb', '_lwc_jt_cargo_tracking_number' ),
		);

		$snapshot = array();
		foreach ( isset( $keys[ $provider ] ) ? $keys[ $provider ] : array() as $key ) {
			$value = $order->get_meta( $key );
			if ( '' !== $value && null !== $value && array() !== $value ) {
				$snapshot[ $key ] = $value;
			}
		}

		return $snapshot;
	}

	/**
	 * Drop the previous carrier's shipment data from the order.
	 *
	 * @param WC_Order $order    Order object.
	 * @param string   $provider Provider key.
	 */
	private function clear_active_fulfillment( $order, $provider ) {
		$keys = array(
			'jt'       => array( '_lwc_jt_awb', '_lwc_jt_order_id', '_lwc_jt_etd', '_lwc_jt_tracking', '_lwc_jt_tracking_error', '_lwc_jt_cancel_error', '_lwc_jt_cancelled', '_lwc_jt_create_error', '_lwc_jt_declared_weight_kg' ),
			'fedex'    => array( '_lwc_fedex_tracking_number', '_lwc_fedex_shipments', '_lwc_fedex_tracking_data', '_lwc_fedex_tracking_updated_at', '_lwc_fedex_pickup', '_lwc_fedex_pickup_availability', '_lwc_fedex_label_path' ),
			'rayspeed' => array( '_lwc_rayspeed_awb', '_lwc_rayspeed_tracking' ),
			'jt_cargo' => array( '_lwc_jt_cargo_awb', '_lwc_jt_cargo_tracking_number' ),
		);

		foreach ( isset( $keys[ $provider ] ) ? $keys[ $provider ] : array() as $key ) {
			$order->delete_meta_data( $key );
		}
	}

	/**
	 * Flatten an order item's meta.
	 *
	 * @param WC_Order_Item $item Order item.
	 * @return array
	 */
	private function item_meta_array( $item ) {
		$values = array();
		foreach ( $item->get_meta_data() as $meta ) {
			$values[ $meta->key ] = $meta->value;
		}

		return $values;
	}

	/**
	 * Authenticate the AJAX request and load its order.
	 *
	 * @return WC_Order
	 */
	private function get_ajax_order() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'lovecatz-wc' ) ), 403 );
		}

		$order = wc_get_order( isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0 );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'lovecatz-wc' ) ), 404 );
		}

		return $order;
	}

	/**
	 * Resolve the order shown on the current screen.
	 *
	 * @param mixed $post_or_order Post or order object.
	 * @return WC_Order|false
	 */
	private function resolve_order( $post_or_order = null ) {
		if ( $post_or_order instanceof WC_Order ) {
			return $post_or_order;
		}
		if ( $post_or_order instanceof WP_Post ) {
			return wc_get_order( $post_or_order->ID );
		}

		$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : ( isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return $id ? wc_get_order( $id ) : false;
	}
}
