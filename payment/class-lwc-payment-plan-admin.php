<?php
/**
 * Purchase Order / partial payment administration.
 *
 * Everything the merchant needs to run a PO or DP order lives on the
 * WooCommerce order screen: the current plan and payment status, the figures
 * behind them, the Purchase Order reference and terms, and the ledger of
 * payments actually received. A matching column on the Orders list shows the
 * payment status at a glance.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_Payment_Plan_Admin {

	const AJAX_RECORD = 'lwc_payment_plan_record';
	const AJAX_DELETE = 'lwc_payment_plan_delete';
	const AJAX_PLAN   = 'lwc_payment_plan_apply';
	const NONCE       = 'lwc_payment_plan_admin';

	const COLUMN_KEY = 'lwc_payment';

	/**
	 * Register admin hooks.
	 */
	public function init() {
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'wp_ajax_' . self::AJAX_RECORD, array( $this, 'ajax_record_payment' ) );
		add_action( 'wp_ajax_' . self::AJAX_DELETE, array( $this, 'ajax_delete_payment' ) );
		add_action( 'wp_ajax_' . self::AJAX_PLAN, array( $this, 'ajax_apply_plan' ) );

		// HPOS (WooCommerce → Orders with high-performance order storage).
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_hpos_column' ), 20, 2 );

		// Legacy CPT order list, kept for installations with HPOS disabled.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_legacy_column' ), 20, 2 );
	}

	/**
	 * Register the payment plan metabox on the order edit screen.
	 *
	 * @param string $post_type     Current post type (unused).
	 * @param mixed  $post_or_order Post or order object.
	 */
	public function register_metabox( $post_type = '', $post_or_order = null ) {
		if ( ! LWC_Payment_Plan::instance()->is_enabled() ) {
			return;
		}

		$order = $this->resolve_admin_order( $post_or_order );
		if ( ! $order ) {
			return;
		}

		add_meta_box(
			'lwc_payment_plan',
			__( 'Payment plan — PO / Partial payment', 'lovecatz-wc' ),
			array( $this, 'render_metabox' ),
			array( 'shop_order', 'woocommerce_page_wc-orders' ),
			'normal',
			'default'
		);
	}

	/**
	 * Enqueue the order-screen and order-list assets.
	 */
	public function enqueue_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}

		$order_screens = array( 'shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders' );
		if ( ! in_array( $screen->id, $order_screens, true ) ) {
			return;
		}

		$plugin_url = plugin_dir_url( LWC_PLUGIN_FILE );

		wp_enqueue_style(
			'lwc-payment-plan-admin',
			$plugin_url . 'payment/payment-plan-admin.css',
			array(),
			LWC_VERSION
		);

		// The script only drives the order screen metabox; the list column is
		// static markup and needs the stylesheet alone.
		if ( ! in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}

		wp_enqueue_script(
			'lwc-payment-plan-admin',
			$plugin_url . 'payment/payment-plan-admin.js',
			array( 'jquery' ),
			LWC_VERSION,
			true
		);

		wp_localize_script(
			'lwc-payment-plan-admin',
			'lwcPaymentPlan',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE ),
				'actions'   => array(
					'record' => self::AJAX_RECORD,
					'delete' => self::AJAX_DELETE,
					'plan'   => self::AJAX_PLAN,
				),
				'i18n'      => array(
					'working'     => __( 'Saving…', 'lovecatz-wc' ),
					'confirm'     => __( 'Remove this payment from the ledger?', 'lovecatz-wc' ),
					'failed'      => __( 'The request failed. Please try again.', 'lovecatz-wc' ),
					'fullPayment' => __( 'Mark this order as fully paid?', 'lovecatz-wc' ),
				),
			)
		);
	}

	/**
	 * Render the metabox shell. The body is replaced in place after each AJAX
	 * action so the administrator never loses their place on the screen.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post or order object.
	 */
	public function render_metabox( $post_or_order ) {
		$order = $this->resolve_admin_order( $post_or_order );
		if ( ! $order ) {
			return;
		}

		printf(
			'<div id="lwc-pp-root" class="lwc-pp-root" data-order-id="%1$d" data-nonce="%2$s">%3$s</div>',
			(int) $order->get_id(),
			esc_attr( wp_create_nonce( self::NONCE ) ),
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built and escaped in render_panel_body().
			$this->render_panel_body( $order )
		);
	}

	/**
	 * Build the metabox body: summary, plan controls, ledger and record form.
	 *
	 * @param WC_Order $order Order being displayed.
	 * @return string
	 */
	public function render_panel_body( $order ) {
		$plan   = LWC_Payment_Plan::instance();
		$total  = (float) $order->get_total();
		$status = $plan->get_payment_status( $order );
		$paid   = $plan->get_amount_paid( $order );
		$balance = $plan->get_balance_due( $order );

		ob_start();
		?>
		<div class="lwc-pp-panel">
			<div class="lwc-pp-summary">
				<div class="lwc-pp-badges">
					<span class="lwc-pp-badge lwc-pp-plan lwc-pp-plan--<?php echo esc_attr( $plan->get_plan( $order ) ); ?>">
						<?php echo esc_html( $plan->get_plan_label( $order ) ); ?>
					</span>
					<?php if ( $total > 0 ) : ?>
						<span class="lwc-pp-badge lwc-pp-status lwc-pp-status--<?php echo esc_attr( $status ); ?>">
							<?php echo esc_html( $plan->get_payment_status_label( $order ) ); ?>
						</span>
					<?php else : ?>
						<span class="lwc-pp-badge lwc-pp-status lwc-pp-muted">
							<?php esc_html_e( 'No amount due yet', 'lovecatz-wc' ); ?>
						</span>
					<?php endif; ?>
				</div>

				<table class="lwc-pp-figures">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Order total', 'lovecatz-wc' ); ?></th>
							<td><?php echo wp_kses_post( $plan->format_amount( $order, $total ) ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Amount paid', 'lovecatz-wc' ); ?></th>
							<td class="lwc-pp-paid"><?php echo wp_kses_post( $plan->format_amount( $order, $paid ) ); ?></td>
						</tr>
						<tr class="lwc-pp-balance-row">
							<th><?php esc_html_e( 'Balance due', 'lovecatz-wc' ); ?></th>
							<td class="lwc-pp-balance"><?php echo wp_kses_post( $plan->format_amount( $order, $balance ) ); ?></td>
						</tr>

						<?php if ( LWC_Payment_Plan::PLAN_DP === $plan->get_plan( $order ) ) : ?>
							<tr>
								<th>
									<?php
									printf(
										/* translators: %s: deposit percentage. */
										esc_html__( 'Deposit (%s%%)', 'lovecatz-wc' ),
										esc_html( rtrim( rtrim( number_format( $plan->get_deposit_percent( $order ), 2, '.', '' ), '0' ), '.' ) )
									);
									?>
								</th>
								<td><?php echo wp_kses_post( $plan->format_amount( $order, $plan->get_deposit_amount( $order ) ) ); ?></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Deposit outstanding', 'lovecatz-wc' ); ?></th>
								<td><?php echo wp_kses_post( $plan->format_amount( $order, $plan->get_deposit_outstanding( $order ) ) ); ?></td>
							</tr>
						<?php endif; ?>

						<?php if ( LWC_Payment_Plan::PLAN_PO === $plan->get_plan( $order ) ) : ?>
							<tr>
								<th><?php esc_html_e( 'PO number', 'lovecatz-wc' ); ?></th>
								<td class="lwc-pp-po-number">
									<?php
									$po_number = $plan->get_po_number( $order );
									echo '' !== $po_number ? esc_html( $po_number ) : '&mdash;';
									?>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Payment due', 'lovecatz-wc' ); ?></th>
								<td>
									<?php
									$due_date = $plan->get_po_due_date( $order );
									echo '' !== $due_date ? esc_html( $plan->format_date( $due_date ) ) : '&mdash;';
									?>
								</td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>

				<?php if ( $order->get_date_paid() ) : ?>
					<p class="lwc-pp-paid-date description">
						<?php
						printf(
							/* translators: %s: date the order was marked paid. */
							esc_html__( 'WooCommerce marks this order paid since %s.', 'lovecatz-wc' ),
							esc_html( wc_format_datetime( $order->get_date_paid() ) )
						);
						?>
					</p>
				<?php endif; ?>
			</div>

			<div class="lwc-pp-plans">
				<p class="lwc-pp-plans-label"><?php esc_html_e( 'Payment plan', 'lovecatz-wc' ); ?></p>
				<div class="lwc-pp-plan-buttons">
					<?php foreach ( LWC_Payment_Plan::get_plan_labels() as $slug => $label ) : ?>
						<button
							type="button"
							class="button lwc-pp-plan-button<?php echo $slug === $plan->get_plan( $order ) ? ' is-active' : ''; ?>"
							data-plan="<?php echo esc_attr( $slug ); ?>"
							data-order-id="<?php echo (int) $order->get_id(); ?>"
						><?php echo esc_html( $label ); ?></button>
					<?php endforeach; ?>
				</div>
			</div>

			<form class="lwc-pp-plan-form lwc-pp-plan-form--po<?php echo LWC_Payment_Plan::PLAN_PO === $plan->get_plan( $order ) ? ' is-visible' : ''; ?>" data-plan="po">
				<h4><?php esc_html_e( 'Purchase Order details', 'lovecatz-wc' ); ?></h4>
				<p class="lwc-pp-field">
					<label for="lwc-pp-po-number"><?php esc_html_e( 'PO number', 'lovecatz-wc' ); ?></label>
					<input type="text" id="lwc-pp-po-number" name="po_number" value="<?php echo esc_attr( $plan->get_po_number( $order ) ); ?>" maxlength="60" autocomplete="off" />
				</p>
				<p class="lwc-pp-field">
					<label for="lwc-pp-po-terms"><?php esc_html_e( 'Payment term (days)', 'lovecatz-wc' ); ?></label>
					<input type="number" id="lwc-pp-po-terms" name="terms_days" value="<?php echo esc_attr( $plan->get_po_terms_days( $order ) ); ?>" min="0" max="365" step="1" />
				</p>
				<p class="lwc-pp-field">
					<label for="lwc-pp-po-due"><?php esc_html_e( 'Due date', 'lovecatz-wc' ); ?></label>
					<input type="date" id="lwc-pp-po-due" name="due_date" value="<?php echo esc_attr( $plan->get_po_due_date( $order ) ); ?>" />
					<span class="description"><?php esc_html_e( 'Leave empty to derive it from the order date plus the payment term.', 'lovecatz-wc' ); ?></span>
				</p>
				<p class="lwc-pp-actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Purchase Order details', 'lovecatz-wc' ); ?></button>
					<span class="lwc-pp-spinner spinner"></span>
				</p>
			</form>

			<form class="lwc-pp-plan-form lwc-pp-plan-form--dp<?php echo LWC_Payment_Plan::PLAN_DP === $plan->get_plan( $order ) ? ' is-visible' : ''; ?>" data-plan="dp">
				<h4><?php esc_html_e( 'Partial payment details', 'lovecatz-wc' ); ?></h4>
				<p class="lwc-pp-field">
					<label for="lwc-pp-dp-percent"><?php esc_html_e( 'Deposit percentage', 'lovecatz-wc' ); ?></label>
					<input type="number" id="lwc-pp-dp-percent" name="deposit_percent" value="<?php echo esc_attr( $plan->get_deposit_percent( $order ) ); ?>" min="0" max="100" step="1" />
					<span class="description"><?php esc_html_e( 'Changing the percentage recalculates the deposit.', 'lovecatz-wc' ); ?></span>
				</p>
				<p class="lwc-pp-field">
					<label for="lwc-pp-dp-amount"><?php esc_html_e( 'Deposit amount', 'lovecatz-wc' ); ?></label>
					<input type="text" id="lwc-pp-dp-amount" name="deposit_amount" value="<?php echo esc_attr( $plan->get_deposit_amount( $order ) ); ?>" autocomplete="off" />
					<span class="description"><?php esc_html_e( 'Leave empty to derive the deposit from the percentage and the order total.', 'lovecatz-wc' ); ?></span>
				</p>
				<p class="lwc-pp-actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save deposit', 'lovecatz-wc' ); ?></button>
					<span class="lwc-pp-spinner spinner"></span>
				</p>
			</form>

			<div class="lwc-pp-ledger">
				<h4>
					<?php esc_html_e( 'Payments received', 'lovecatz-wc' ); ?>
					<span class="lwc-pp-ledger-count"><?php echo (int) count( $plan->get_payments( $order ) ); ?></span>
				</h4>

				<?php $payments = $plan->get_payments( $order ); ?>
				<?php if ( empty( $payments ) ) : ?>
					<p class="lwc-pp-empty"><?php esc_html_e( 'No payments recorded yet.', 'lovecatz-wc' ); ?></p>
				<?php else : ?>
					<table class="lwc-pp-ledger-table widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date', 'lovecatz-wc' ); ?></th>
								<th><?php esc_html_e( 'Amount', 'lovecatz-wc' ); ?></th>
								<th><?php esc_html_e( 'Method', 'lovecatz-wc' ); ?></th>
								<th><?php esc_html_e( 'Reference', 'lovecatz-wc' ); ?></th>
								<th><?php esc_html_e( 'Note', 'lovecatz-wc' ); ?></th>
								<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'lovecatz-wc' ); ?></span></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $payments as $entry ) : ?>
								<tr>
									<td><?php echo esc_html( $plan->format_date( isset( $entry['date'] ) ? $entry['date'] : '' ) ); ?></td>
									<td class="lwc-pp-ledger-amount"><?php echo wp_kses_post( $plan->format_amount( $order, (float) $entry['amount'] ) ); ?></td>
									<td><?php echo esc_html( $this->payment_method_label( $entry ) ); ?></td>
									<td><?php echo isset( $entry['reference'] ) && '' !== $entry['reference'] ? esc_html( $entry['reference'] ) : '&mdash;'; ?></td>
									<td><?php echo isset( $entry['note'] ) && '' !== $entry['note'] ? esc_html( $entry['note'] ) : '&mdash;'; ?></td>
									<td>
										<button
											type="button"
											class="button-link lwc-pp-delete-payment"
											data-order-id="<?php echo (int) $order->get_id(); ?>"
											data-payment-id="<?php echo esc_attr( isset( $entry['id'] ) ? $entry['id'] : '' ); ?>"
										><?php esc_html_e( 'Remove', 'lovecatz-wc' ); ?></button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<?php if ( $balance > 0 ) : ?>
					<form class="lwc-pp-record">
						<h4><?php esc_html_e( 'Record a payment', 'lovecatz-wc' ); ?></h4>
						<div class="lwc-pp-record-grid">
							<p class="lwc-pp-field">
								<label for="lwc-pp-amount"><?php esc_html_e( 'Amount', 'lovecatz-wc' ); ?></label>
								<input
									type="text"
									id="lwc-pp-amount"
									name="amount"
									value="<?php echo esc_attr( $this->suggested_amount( $order ) ); ?>"
									autocomplete="off"
								/>
							</p>
							<p class="lwc-pp-field">
								<label for="lwc-pp-method"><?php esc_html_e( 'Method', 'lovecatz-wc' ); ?></label>
								<select id="lwc-pp-method" name="method">
									<?php foreach ( LWC_Payment_Plan::get_method_labels() as $slug => $label ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>"<?php selected( 'bank_transfer', $slug ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
							<p class="lwc-pp-field">
								<label for="lwc-pp-date"><?php esc_html_e( 'Date received', 'lovecatz-wc' ); ?></label>
								<input type="date" id="lwc-pp-date" name="date" value="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" />
							</p>
							<p class="lwc-pp-field">
								<label for="lwc-pp-reference"><?php esc_html_e( 'Reference', 'lovecatz-wc' ); ?></label>
								<input type="text" id="lwc-pp-reference" name="reference" value="" maxlength="80" autocomplete="off" placeholder="<?php esc_attr_e( 'Transfer ID, receipt number…', 'lovecatz-wc' ); ?>" />
							</p>
							<p class="lwc-pp-field lwc-pp-field--wide">
								<label for="lwc-pp-note"><?php esc_html_e( 'Note', 'lovecatz-wc' ); ?></label>
								<input type="text" id="lwc-pp-note" name="note" value="" maxlength="200" autocomplete="off" />
							</p>
						</div>
						<p class="lwc-pp-actions">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Record payment', 'lovecatz-wc' ); ?></button>
							<button type="button" class="button lwc-pp-fill-balance" data-amount="<?php echo esc_attr( $balance ); ?>"><?php esc_html_e( 'Use balance due', 'lovecatz-wc' ); ?></button>
							<?php if ( LWC_Payment_Plan::PLAN_DP === $plan->get_plan( $order ) && $plan->get_deposit_outstanding( $order ) > 0 ) : ?>
								<button type="button" class="button lwc-pp-fill-balance" data-amount="<?php echo esc_attr( $plan->get_deposit_outstanding( $order ) ); ?>"><?php esc_html_e( 'Use deposit outstanding', 'lovecatz-wc' ); ?></button>
							<?php endif; ?>
							<span class="lwc-pp-spinner spinner"></span>
						</p>
					</form>
				<?php else : ?>
					<?php if ( $total > 0 ) : ?>
						<p class="lwc-pp-settled"><?php esc_html_e( 'This order is fully paid.', 'lovecatz-wc' ); ?></p>
					<?php else : ?>
						<p class="lwc-pp-empty"><?php esc_html_e( 'Add items to the order before recording a payment.', 'lovecatz-wc' ); ?></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<div class="lwc-pp-feedback" role="status" aria-live="polite"></div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Amount prefilled into the record form.
	 *
	 * A DP order is normally settled deposit-first, so the outstanding deposit
	 * is the most likely figure; every other order defaults to the balance.
	 *
	 * @param WC_Order $order Order being displayed.
	 * @return float
	 */
	private function suggested_amount( $order ) {
		$plan = LWC_Payment_Plan::instance();

		if ( LWC_Payment_Plan::PLAN_DP === $plan->get_plan( $order ) ) {
			$outstanding = $plan->get_deposit_outstanding( $order );
			if ( $outstanding > 0 ) {
				return $outstanding;
			}
		}

		return $plan->get_balance_due( $order );
	}

	/**
	 * Label of a ledger entry's payment method.
	 *
	 * @param array $entry Ledger entry.
	 * @return string
	 */
	private function payment_method_label( $entry ) {
		$labels = LWC_Payment_Plan::get_method_labels();
		$method = isset( $entry['method'] ) ? $entry['method'] : 'other';

		return isset( $labels[ $method ] ) ? $labels[ $method ] : $labels['other'];
	}

	/**
	 * Add the payment column to the Orders list.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		if ( ! LWC_Payment_Plan::instance()->is_enabled() || ! is_array( $columns ) ) {
			return $columns;
		}

		$positioned = array();

		foreach ( $columns as $key => $label ) {
			$positioned[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$positioned[ self::COLUMN_KEY ] = __( 'Payment', 'lovecatz-wc' );
			}
		}

		if ( ! isset( $positioned[ self::COLUMN_KEY ] ) ) {
			$positioned[ self::COLUMN_KEY ] = __( 'Payment', 'lovecatz-wc' );
		}

		return $positioned;
	}

	/**
	 * Render the column on the HPOS order list, which passes a WC_Order.
	 *
	 * @param string   $column Column key.
	 * @param WC_Order $order  Order for this row.
	 */
	public function render_hpos_column( $column, $order ) {
		if ( self::COLUMN_KEY !== $column ) {
			return;
		}

		$order = $order instanceof WC_Order ? $order : wc_get_order( (int) $order );
		if ( ! $order ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built and escaped in render_column_content().
		echo $this->render_column_content( $order );
	}

	/**
	 * Render the column on the legacy CPT order list, which passes a post ID.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Order post ID.
	 */
	public function render_legacy_column( $column, $post_id ) {
		if ( self::COLUMN_KEY !== $column ) {
			return;
		}

		$order = wc_get_order( (int) $post_id );
		if ( ! $order ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built and escaped in render_column_content().
		echo $this->render_column_content( $order );
	}

	/**
	 * Build the payment column markup for one order.
	 *
	 * Orders that this feature does not manage stay quiet: a plain dash, or a
	 * paid badge when WooCommerce itself recorded a payment date. That keeps
	 * the column informative without turning every normal order into noise.
	 *
	 * @param WC_Order $order Order for this row.
	 * @return string
	 */
	private function render_column_content( $order ) {
		$plan = LWC_Payment_Plan::instance();

		if ( ! $plan->is_managed( $order ) ) {
			if ( $order->get_date_paid() ) {
				return '<span class="lwc-pp-badge lwc-pp-status--paid">' . esc_html__( 'Paid', 'lovecatz-wc' ) . '</span>';
			}

			return '<span class="lwc-pp-muted">&mdash;</span>';
		}

		$status  = $plan->get_payment_status( $order );
		$balance = $plan->get_balance_due( $order );

		$html  = '<span class="lwc-pp-badge lwc-pp-status--' . esc_attr( $status ) . '">' . esc_html( $plan->get_payment_status_label( $order ) ) . '</span>';
		$html .= '<span class="lwc-pp-column-plan">' . esc_html( $plan->get_plan_label( $order ) ) . '</span>';

		if ( $balance > 0 ) {
			$html .= '<span class="lwc-pp-column-balance">' . wp_kses_post( $plan->format_amount( $order, $balance ) ) . '</span>';
		}

		return $html;
	}

	/**
	 * AJAX: record one received payment.
	 */
	public function ajax_record_payment() {
		$order = $this->authorize_request();
		if ( is_wp_error( $order ) ) {
			wp_send_json_error( array( 'message' => $order->get_error_message() ) );
		}

		$result = LWC_Payment_Plan::instance()->add_payment(
			$order,
			array(
				'amount'    => isset( $_POST['amount'] ) ? wp_unslash( $_POST['amount'] ) : 0, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed as a decimal by the model.
				'method'    => isset( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : 'other',
				'date'      => isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '',
				'reference' => isset( $_POST['reference'] ) ? sanitize_text_field( wp_unslash( $_POST['reference'] ) ) : '',
				'note'      => isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '',
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Payment recorded.', 'lovecatz-wc' ),
				'html'    => $this->render_panel_body( $order ),
			)
		);
	}

	/**
	 * AJAX: remove one payment from the ledger.
	 */
	public function ajax_delete_payment() {
		$order = $this->authorize_request();
		if ( is_wp_error( $order ) ) {
			wp_send_json_error( array( 'message' => $order->get_error_message() ) );
		}

		$payment_id = isset( $_POST['payment_id'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_id'] ) ) : '';
		$result     = LWC_Payment_Plan::instance()->delete_payment( $order, $payment_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Payment removed.', 'lovecatz-wc' ),
				'html'    => $this->render_panel_body( $order ),
			)
		);
	}

	/**
	 * AJAX: switch the payment plan or save its plan-specific fields.
	 */
	public function ajax_apply_plan() {
		$order = $this->authorize_request();
		if ( is_wp_error( $order ) ) {
			wp_send_json_error( array( 'message' => $order->get_error_message() ) );
		}

		$plan   = isset( $_POST['plan'] ) ? sanitize_key( wp_unslash( $_POST['plan'] ) ) : '';
		$result = null;

		if ( LWC_Payment_Plan::PLAN_PO === $plan ) {
			$result = LWC_Payment_Plan::instance()->update_purchase_order(
				$order,
				isset( $_POST['po_number'] ) ? sanitize_text_field( wp_unslash( $_POST['po_number'] ) ) : '',
				isset( $_POST['terms_days'] ) ? (int) wp_unslash( $_POST['terms_days'] ) : 0,
				isset( $_POST['due_date'] ) ? sanitize_text_field( wp_unslash( $_POST['due_date'] ) ) : ''
			);
		} elseif ( LWC_Payment_Plan::PLAN_DP === $plan ) {
			$result = LWC_Payment_Plan::instance()->update_deposit(
				$order,
				isset( $_POST['deposit_percent'] ) ? (float) wp_unslash( $_POST['deposit_percent'] ) : LWC_Payment_Plan::instance()->get_default_deposit_percent(),
				isset( $_POST['deposit_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['deposit_amount'] ) ) : ''
			);
		} else {
			$result = LWC_Payment_Plan::instance()->apply_plan( $order, $plan );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Payment plan updated.', 'lovecatz-wc' ),
				'html'    => $this->render_panel_body( $order ),
			)
		);
	}

	/**
	 * Shared AJAX guard: verify the nonce, the capability and the order.
	 *
	 * @return WC_Order|WP_Error
	 */
	private function authorize_request() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return new WP_Error( 'lwc_pp_forbidden', __( 'You do not have permission to manage this order.', 'lovecatz-wc' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'lwc_pp_order_not_found', __( 'Order not found.', 'lovecatz-wc' ) );
		}

		return $order;
	}

	/**
	 * Resolve a WC_Order from any post / order object the admin screen passes.
	 *
	 * @param mixed $post_or_order Post, order, or order ID.
	 * @return WC_Order|null
	 */
	private function resolve_admin_order( $post_or_order ) {
		if ( $post_or_order instanceof WC_Order ) {
			return $post_or_order;
		}

		if ( is_object( $post_or_order ) && method_exists( $post_or_order, 'get_id' ) ) {
			$id = (int) $post_or_order->get_id();
		} elseif ( is_numeric( $post_or_order ) ) {
			$id = (int) $post_or_order;
		} else {
			return null;
		}

		$order = $id > 0 ? wc_get_order( $id ) : false;

		return $order instanceof WC_Order ? $order : null;
	}
}
