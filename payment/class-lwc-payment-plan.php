<?php
/**
 * Purchase Order and partial payment (DP) plan model.
 *
 * WooCommerce has no concept of a partially paid order, so this class owns the
 * whole payment plan of an order: which plan it uses (full, purchase order or
 * partial payment), the expected deposit, and the ledger of payments actually
 * received. Every derived figure — amount paid, balance due and payment status
 * — is computed from the ledger rather than cached, so the order screen can
 * never disagree with the stored data.
 *
 * The plan is intentionally stored as order metadata rather than as a custom
 * order status. Payment state is orthogonal to fulfilment state: a partially
 * paid order must still be able to move through Processing, Completed and the
 * existing courier automation untouched.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_Payment_Plan {

	const PLAN_FULL = 'full';
	const PLAN_PO   = 'po';
	const PLAN_DP   = 'dp';

	const STATUS_UNPAID  = 'unpaid';
	const STATUS_PARTIAL = 'partial';
	const STATUS_PAID    = 'paid';

	const META_PLAN            = '_lwc_payment_plan';
	const META_DEPOSIT_PERCENT = '_lwc_deposit_percent';
	const META_DEPOSIT_AMOUNT  = '_lwc_deposit_amount';
	const META_PAYMENTS        = '_lwc_payments';
	const META_PO_NUMBER       = '_lwc_po_number';
	const META_PO_TERMS_DAYS   = '_lwc_po_terms_days';
	const META_PO_DUE_DATE     = '_lwc_po_due_date';

	const OPT_ENABLED          = 'lwc_po_dp_enabled';
	const OPT_DP_PERCENT       = 'lwc_dp_default_percent';
	const OPT_PO_STATUS        = 'lwc_po_default_status';
	const OPT_DP_STATUS        = 'lwc_dp_default_status';
	const OPT_PO_TERMS_DAYS    = 'lwc_po_terms_days';
	const OPT_PO_REQUIRE_LOGIN = 'lwc_po_require_login';

	const GATEWAY_PO = 'lwc_purchase_order';
	const GATEWAY_DP = 'lwc_partial_payment';

	/** Tolerance used when comparing money so rounding never flips a status. */
	const EPSILON = 0.0001;

	/**
	 * Singleton instance.
	 *
	 * @var LWC_Payment_Plan|null
	 */
	private static $instance = null;

	/**
	 * Get (and lazily create) the singleton instance.
	 *
	 * @return LWC_Payment_Plan
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register order-lifecycle and customer-facing hooks.
	 *
	 * Plan metadata is written by the gateways and the admin metabox; these
	 * hooks only cover the paths that bypass both: an order created from the
	 * admin whose payment method is already one of ours, and the summaries the
	 * customer sees on the thank-you page, My Account and transactional emails.
	 */
	public function init() {
		add_action( 'woocommerce_new_order', array( $this, 'sync_plan_from_payment_method' ), 20, 2 );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'sync_plan_from_payment_method' ), 20, 2 );

		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_customer_summary' ), 5 );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_email_summary' ), 5, 4 );
	}

	/**
	 * Whether the Purchase Order / partial payment feature is switched on.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return 'yes' === get_option( self::OPT_ENABLED, 'no' );
	}

	/**
	 * Turn the WooCommerce gateways on the first time the feature is enabled.
	 *
	 * The plugin switch and the WooCommerce gateway toggles would otherwise be
	 * two separate off switches, and enabling the feature in the Order tab
	 * would silently change nothing at checkout. Existing gateway settings are
	 * never overwritten, so a merchant who deliberately disabled one keeps it
	 * disabled.
	 */
	public static function sync_gateway_defaults() {
		foreach ( array( self::GATEWAY_PO, self::GATEWAY_DP ) as $gateway_id ) {
			$option = 'woocommerce_' . $gateway_id . '_settings';

			if ( false === get_option( $option, false ) ) {
				update_option( $option, array( 'enabled' => 'yes' ) );
			}
		}
	}

	/**
	 * All plans, keyed by slug.
	 *
	 * @return array<string,string>
	 */
	public static function get_plan_labels() {
		return array(
			self::PLAN_FULL => __( 'Full payment', 'lovecatz-wc' ),
			self::PLAN_PO   => __( 'Purchase Order (PO)', 'lovecatz-wc' ),
			self::PLAN_DP   => __( 'Partial payment (DP)', 'lovecatz-wc' ),
		);
	}

	/**
	 * All payment statuses, keyed by slug.
	 *
	 * @return array<string,string>
	 */
	public static function get_status_labels() {
		return array(
			self::STATUS_UNPAID  => __( 'Unpaid', 'lovecatz-wc' ),
			self::STATUS_PARTIAL => __( 'Partially paid', 'lovecatz-wc' ),
			self::STATUS_PAID    => __( 'Paid', 'lovecatz-wc' ),
		);
	}

	/**
	 * Payment methods an administrator can record against an order.
	 *
	 * @return array<string,string>
	 */
	public static function get_method_labels() {
		return array(
			'bank_transfer' => __( 'Bank transfer', 'lovecatz-wc' ),
			'cash'          => __( 'Cash', 'lovecatz-wc' ),
			'cheque'        => __( 'Cheque', 'lovecatz-wc' ),
			'gateway'       => __( 'Payment gateway', 'lovecatz-wc' ),
			'other'         => __( 'Other', 'lovecatz-wc' ),
		);
	}

	/**
	 * Order statuses offered after a PO or DP checkout.
	 *
	 * Restricted to the statuses WooCommerce reduces stock on (pending, on-hold,
	 * processing) so the choice can never leave stock unreserved.
	 *
	 * @return array<string,string>
	 */
	public static function get_available_order_statuses() {
		$statuses = array();

		foreach ( array( 'pending', 'on-hold', 'processing' ) as $slug ) {
			$label = wc_get_order_status_name( $slug );
			if ( '' !== (string) $label ) {
				$statuses[ $slug ] = $label;
			}
		}

		return $statuses;
	}

	/**
	 * Normalise any stored value into a known plan slug.
	 *
	 * @param mixed $plan Raw plan value.
	 * @return string
	 */
	public function normalize_plan( $plan ) {
		$plan = is_string( $plan ) ? strtolower( trim( $plan ) ) : '';

		return array_key_exists( $plan, self::get_plan_labels() ) ? $plan : self::PLAN_FULL;
	}

	/**
	 * The plan stored on an order. Reads never mutate the order.
	 *
	 * @param WC_Order $order Order being read.
	 * @return string
	 */
	public function get_plan( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return self::PLAN_FULL;
		}

		return $this->normalize_plan( $order->get_meta( self::META_PLAN ) );
	}

	/**
	 * Whether the order uses a plan that this feature manages.
	 *
	 * @param WC_Order $order Order being read.
	 * @return bool
	 */
	public function is_managed( $order ) {
		return in_array( $this->get_plan( $order ), array( self::PLAN_PO, self::PLAN_DP ), true );
	}

	/**
	 * Human label of an order's plan.
	 *
	 * @param WC_Order $order Order being read.
	 * @return string
	 */
	public function get_plan_label( $order ) {
		$labels = self::get_plan_labels();
		$plan   = $this->get_plan( $order );

		return isset( $labels[ $plan ] ) ? $labels[ $plan ] : $labels[ self::PLAN_FULL ];
	}

	/**
	 * The payment ledger of an order, oldest entry first.
	 *
	 * @param WC_Order $order Order being read.
	 * @return array<int,array>
	 */
	public function get_payments( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return array();
		}

		$entries = $order->get_meta( self::META_PAYMENTS );
		if ( ! is_array( $entries ) ) {
			return array();
		}

		$clean = array();
		foreach ( $entries as $entry ) {
			if ( is_array( $entry ) && isset( $entry['amount'] ) ) {
				$clean[] = $entry;
			}
		}

		return $clean;
	}

	/**
	 * Sum of every payment recorded against the order.
	 *
	 * @param WC_Order $order Order being read.
	 * @return float
	 */
	public function get_amount_paid( $order ) {
		$paid = 0.0;
		foreach ( $this->get_payments( $order ) as $entry ) {
			$paid += (float) $entry['amount'];
		}

		return $this->round( $paid );
	}

	/**
	 * Outstanding amount: order total minus everything already received.
	 *
	 * @param WC_Order $order Order being read.
	 * @return float
	 */
	public function get_balance_due( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return 0.0;
		}

		$balance = $this->round( (float) $order->get_total() ) - $this->get_amount_paid( $order );

		return $balance > 0 ? $this->round( $balance ) : 0.0;
	}

	/**
	 * Derived payment status: unpaid, partially paid or paid.
	 *
	 * @param WC_Order $order Order being read.
	 * @return string
	 */
	public function get_payment_status( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return self::STATUS_UNPAID;
		}

		$total = $this->round( (float) $order->get_total() );
		$paid  = $this->get_amount_paid( $order );

		if ( $total <= self::EPSILON ) {
			// Nothing is owed, so the order cannot be outstanding.
			return self::STATUS_PAID;
		}

		if ( $paid <= self::EPSILON ) {
			return self::STATUS_UNPAID;
		}

		return ( $paid + self::EPSILON < $total ) ? self::STATUS_PARTIAL : self::STATUS_PAID;
	}

	/**
	 * Human label of the derived payment status.
	 *
	 * @param WC_Order $order Order being read.
	 * @return string
	 */
	public function get_payment_status_label( $order ) {
		$labels = self::get_status_labels();
		$status = $this->get_payment_status( $order );

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $labels[ self::STATUS_UNPAID ];
	}

	/**
	 * Deposit percentage configured for the order.
	 *
	 * Falls back to the site-wide default when the order stores none.
	 *
	 * @param WC_Order $order Order being read.
	 * @return float
	 */
	public function get_deposit_percent( $order ) {
		$stored = $order instanceof WC_Order ? $order->get_meta( self::META_DEPOSIT_PERCENT ) : '';

		if ( '' === $stored || ! is_numeric( $stored ) ) {
			return $this->get_default_deposit_percent();
		}

		return max( 0, min( 100, (float) $stored ) );
	}

	/**
	 * Site-wide default deposit percentage.
	 *
	 * @return float
	 */
	public function get_default_deposit_percent() {
		$percent = (float) get_option( self::OPT_DP_PERCENT, 50 );

		return max( 0, min( 100, $percent ) );
	}

	/**
	 * Expected deposit amount for the order.
	 *
	 * Only a partial payment plan has a deposit. A Purchase Order settles the
	 * full invoice and an ordinary order is paid in one go, so both report zero
	 * rather than silently inheriting the site-wide percentage.
	 *
	 * An explicit per-order override always wins; otherwise the percentage is
	 * applied to the current order total so a later total change stays correct.
	 *
	 * @param WC_Order $order Order being read.
	 * @return float
	 */
	public function get_deposit_amount( $order ) {
		if ( ! $order instanceof WC_Order || self::PLAN_DP !== $this->get_plan( $order ) ) {
			return 0.0;
		}

		$stored = $order->get_meta( self::META_DEPOSIT_AMOUNT );
		if ( '' !== $stored && is_numeric( $stored ) ) {
			return max( 0, $this->round( (float) $stored ) );
		}

		return $this->round( (float) $order->get_total() * $this->get_deposit_percent( $order ) / 100 );
	}

	/**
	 * How much of the expected deposit is still unpaid.
	 *
	 * @param WC_Order $order Order being read.
	 * @return float
	 */
	public function get_deposit_outstanding( $order ) {
		$outstanding = $this->get_deposit_amount( $order ) - $this->get_amount_paid( $order );

		return $outstanding > 0 ? $this->round( $outstanding ) : 0.0;
	}

	/**
	 * Stored Purchase Order reference number.
	 *
	 * @param WC_Order $order Order being read.
	 * @return string
	 */
	public function get_po_number( $order ) {
		return $order instanceof WC_Order ? (string) $order->get_meta( self::META_PO_NUMBER ) : '';
	}

	/**
	 * Payment term in days for a Purchase Order.
	 *
	 * @param WC_Order $order Order being read.
	 * @return int
	 */
	public function get_po_terms_days( $order ) {
		$stored = $order instanceof WC_Order ? $order->get_meta( self::META_PO_TERMS_DAYS ) : '';

		if ( '' !== $stored && is_numeric( $stored ) ) {
			return max( 0, (int) $stored );
		}

		return max( 0, (int) get_option( self::OPT_PO_TERMS_DAYS, 30 ) );
	}

	/**
	 * Stored due date of a Purchase Order (Y-m-d), or an empty string.
	 *
	 * @param WC_Order $order Order being read.
	 * @return string
	 */
	public function get_po_due_date( $order ) {
		return $order instanceof WC_Order ? (string) $order->get_meta( self::META_PO_DUE_DATE ) : '';
	}

	/**
	 * Apply a plan to an order and persist the supporting metadata.
	 *
	 * @param WC_Order $order Order being changed.
	 * @param string   $plan  One of the PLAN_* slugs.
	 * @param array    $args  Optional deposit_percent, deposit_amount, po_number,
	 *                        terms_days and due_date overrides.
	 * @return true|WP_Error
	 */
	public function apply_plan( $order, $plan, $args = array() ) {
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'lwc_pp_invalid_order', __( 'Order not found.', 'lovecatz-wc' ) );
		}

		$plan = $this->normalize_plan( $plan );
		$args = wp_parse_args(
			$args,
			array(
				'deposit_percent' => null,
				'deposit_amount'  => null,
				'po_number'       => null,
				'terms_days'      => null,
				'due_date'        => null,
			)
		);

		$order->update_meta_data( self::META_PLAN, $plan );

		if ( self::PLAN_PO === $plan ) {
			$this->apply_purchase_order_terms( $order, $args );
		} elseif ( self::PLAN_DP === $plan ) {
			$this->apply_partial_payment_terms( $order, $args );
		} else {
			// Switching back to full payment clears every plan-specific field.
			$order->delete_meta_data( self::META_DEPOSIT_PERCENT );
			$order->delete_meta_data( self::META_DEPOSIT_AMOUNT );
			$order->delete_meta_data( self::META_PO_DUE_DATE );
		}

		$order->save_meta_data();
		$this->refresh_paid_state( $order );

		return true;
	}

	/**
	 * Write the Purchase Order fields, deriving the due date when none is given.
	 *
	 * @param WC_Order $order Order being changed.
	 * @param array    $args  Normalised arguments.
	 */
	private function apply_purchase_order_terms( $order, $args ) {
		if ( null !== $args['po_number'] ) {
			$order->update_meta_data( self::META_PO_NUMBER, sanitize_text_field( (string) $args['po_number'] ) );
		}

		$terms = null === $args['terms_days'] ? $this->get_po_terms_days( $order ) : max( 0, (int) $args['terms_days'] );
		$order->update_meta_data( self::META_PO_TERMS_DAYS, $terms );

		$due_date = null === $args['due_date'] ? '' : trim( (string) $args['due_date'] );
		if ( '' === $due_date ) {
			$due_date = $this->compute_due_date( $order, $terms );
		}
		$order->update_meta_data( self::META_PO_DUE_DATE, $due_date );

		// A purchase order never carries a deposit.
		$order->delete_meta_data( self::META_DEPOSIT_PERCENT );
		$order->delete_meta_data( self::META_DEPOSIT_AMOUNT );
	}

	/**
	 * Write the partial payment fields.
	 *
	 * @param WC_Order $order Order being changed.
	 * @param array    $args  Normalised arguments.
	 */
	private function apply_partial_payment_terms( $order, $args ) {
		$percent = null === $args['deposit_percent'] ? $this->get_default_deposit_percent() : (float) $args['deposit_percent'];
		$percent = max( 0, min( 100, $percent ) );
		$order->update_meta_data( self::META_DEPOSIT_PERCENT, $percent );

		$amount = $args['deposit_amount'];
		if ( null === $amount || '' === $amount ) {
			$amount = $this->round( (float) $order->get_total() * $percent / 100 );
		} else {
			$amount = max( 0, $this->round( (float) $amount ) );
		}
		$order->update_meta_data( self::META_DEPOSIT_AMOUNT, $amount );

		// A partial payment plan has no Purchase Order reference or due date.
		$order->delete_meta_data( self::META_PO_DUE_DATE );
	}

	/**
	 * Override the expected deposit without touching the rest of the plan.
	 *
	 * @param WC_Order $order   Order being changed.
	 * @param float    $percent Requested percentage (0–100).
	 * @param mixed    $amount  Explicit amount, or null/'' to derive it.
	 * @return true|WP_Error
	 */
	public function update_deposit( $order, $percent, $amount = null ) {
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'lwc_pp_invalid_order', __( 'Order not found.', 'lovecatz-wc' ) );
		}

		$percent = max( 0, min( 100, (float) $percent ) );
		$order->update_meta_data( self::META_PLAN, self::PLAN_DP );
		$order->update_meta_data( self::META_DEPOSIT_PERCENT, $percent );

		if ( null === $amount || '' === $amount ) {
			$order->update_meta_data( self::META_DEPOSIT_AMOUNT, $this->round( (float) $order->get_total() * $percent / 100 ) );
		} else {
			$order->update_meta_data( self::META_DEPOSIT_AMOUNT, max( 0, $this->round( (float) $amount ) ) );
		}

		$order->save_meta_data();

		return true;
	}

	/**
	 * Update the Purchase Order reference, terms and due date.
	 *
	 * @param WC_Order $order      Order being changed.
	 * @param string   $po_number  Purchase Order reference.
	 * @param int      $terms_days Payment term in days.
	 * @param string   $due_date   Explicit due date (Y-m-d), or '' to derive it.
	 * @return true|WP_Error
	 */
	public function update_purchase_order( $order, $po_number, $terms_days, $due_date = '' ) {
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'lwc_pp_invalid_order', __( 'Order not found.', 'lovecatz-wc' ) );
		}

		return $this->apply_plan(
			$order,
			self::PLAN_PO,
			array(
				'po_number'  => $po_number,
				'terms_days' => $terms_days,
				'due_date'   => $due_date,
			)
		);
	}

	/**
	 * Record one received payment against an order.
	 *
	 * Overpayment is rejected rather than silently accepted: the balance is the
	 * number the merchant invoices against, so letting it go negative would
	 * break that contract. Partial shipments and refunds are out of scope.
	 *
	 * @param WC_Order $order Order being changed.
	 * @param array    $args  amount, method, date, reference, note, user_id.
	 * @return array|WP_Error The stored entry, or a validation error.
	 */
	public function add_payment( $order, $args ) {
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'lwc_pp_invalid_order', __( 'Order not found.', 'lovecatz-wc' ) );
		}

		$args = wp_parse_args(
			$args,
			array(
				'amount'    => 0,
				'method'    => 'other',
				'date'      => '',
				'reference' => '',
				'note'      => '',
				'user_id'   => get_current_user_id(),
			)
		);

		$amount = $this->round( (float) wc_format_decimal( $args['amount'] ) );
		if ( $amount <= 0 ) {
			return new WP_Error( 'lwc_pp_invalid_amount', __( 'Enter a payment amount greater than zero.', 'lovecatz-wc' ) );
		}

		$balance = $this->get_balance_due( $order );
		if ( $balance <= self::EPSILON ) {
			return new WP_Error( 'lwc_pp_already_paid', __( 'This order is already fully paid.', 'lovecatz-wc' ) );
		}

		if ( $amount > $balance + self::EPSILON ) {
			return new WP_Error(
				'lwc_pp_overpayment',
				sprintf(
					/* translators: %s: outstanding balance. */
					__( 'The amount exceeds the outstanding balance of %s.', 'lovecatz-wc' ),
					$this->format_amount_plain( $order, $balance )
				)
			);
		}

		$method_labels = self::get_method_labels();
		$method        = sanitize_key( (string) $args['method'] );

		$entry = array(
			'id'        => $this->generate_payment_id(),
			'amount'    => $amount,
			'method'    => array_key_exists( $method, $method_labels ) ? $method : 'other',
			'date'      => $this->normalize_date( $args['date'] ),
			'reference' => sanitize_text_field( (string) $args['reference'] ),
			'note'      => sanitize_textarea_field( (string) $args['note'] ),
			'user_id'   => (int) $args['user_id'],
			'created'   => current_time( 'mysql' ),
		);

		$entries   = $this->get_payments( $order );
		$entries[] = $entry;

		$order->update_meta_data( self::META_PAYMENTS, $entries );
		$order->save_meta_data();
		$this->refresh_paid_state( $order );

		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: payment method, 3: payment status, 4: remaining balance. */
				__( 'Payment recorded: %1$s via %2$s. Status is now %3$s; remaining balance %4$s.', 'lovecatz-wc' ),
				$this->format_amount_plain( $order, $amount ),
				isset( $method_labels[ $entry['method'] ] ) ? $method_labels[ $entry['method'] ] : $method_labels['other'],
				$this->get_payment_status_label( $order ),
				$this->format_amount_plain( $order, $this->get_balance_due( $order ) )
			)
		);

		return $entry;
	}

	/**
	 * Remove one payment from the ledger.
	 *
	 * @param WC_Order $order      Order being changed.
	 * @param string   $payment_id Ledger entry identifier.
	 * @return true|WP_Error
	 */
	public function delete_payment( $order, $payment_id ) {
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'lwc_pp_invalid_order', __( 'Order not found.', 'lovecatz-wc' ) );
		}

		$payment_id = sanitize_text_field( (string) $payment_id );
		$entries    = $this->get_payments( $order );
		$remaining  = array();
		$removed    = null;

		foreach ( $entries as $entry ) {
			if ( isset( $entry['id'] ) && $entry['id'] === $payment_id && null === $removed ) {
				$removed = $entry;
				continue;
			}
			$remaining[] = $entry;
		}

		if ( null === $removed ) {
			return new WP_Error( 'lwc_pp_payment_not_found', __( 'That payment is no longer in the ledger.', 'lovecatz-wc' ) );
		}

		$order->update_meta_data( self::META_PAYMENTS, $remaining );
		$order->save_meta_data();
		$this->refresh_paid_state( $order );

		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: payment status, 3: remaining balance. */
				__( 'Payment removed: %1$s. Status is now %2$s; remaining balance %3$s.', 'lovecatz-wc' ),
				$this->format_amount_plain( $order, (float) $removed['amount'] ),
				$this->get_payment_status_label( $order ),
				$this->format_amount_plain( $order, $this->get_balance_due( $order ) )
			)
		);

		return true;
	}

	/**
	 * Initialise the plan when an order is created or saved with our gateway.
	 *
	 * Checkout writes the plan itself, so this only matters for orders an
	 * administrator creates from WooCommerce → Orders, where WooCommerce stores
	 * the chosen payment method without ever calling the gateway.
	 *
	 * @param mixed $arg1 Order id, or order object depending on the hook.
	 * @param mixed $arg2 Order or post, depending on the hook.
	 */
	public function sync_plan_from_payment_method( $arg1 = null, $arg2 = null ) {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$order = $this->resolve_order( $arg1, $arg2 );
		if ( ! $order || '' !== (string) $order->get_meta( self::META_PLAN ) ) {
			return;
		}

		$method = (string) $order->get_payment_method();

		if ( self::GATEWAY_PO === $method ) {
			$this->apply_plan( $order, self::PLAN_PO );
		} elseif ( self::GATEWAY_DP === $method ) {
			$this->apply_plan( $order, self::PLAN_DP );
		}
	}

	/**
	 * Keep WooCommerce's paid date in step with the derived payment status.
	 *
	 * WooCommerce prints "Paid" from `_date_paid`, so a managed order that is
	 * only partly paid must not claim one. A fully covered order gets the date
	 * stamped so the native reports and filters stay meaningful.
	 *
	 * @param WC_Order $order Order being synchronised.
	 */
	public function refresh_paid_state( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! $this->is_managed( $order ) && empty( $this->get_payments( $order ) ) ) {
			return;
		}

		$is_paid  = self::STATUS_PAID === $this->get_payment_status( $order );
		$has_date = (bool) $order->get_date_paid();

		// Only write when the stored value actually disagrees, so this never
		// re-enters a save hook that is already in progress.
		if ( $is_paid && ! $has_date ) {
			$order->set_date_paid( time() );
			$order->save();
		} elseif ( ! $is_paid && $has_date && $this->is_managed( $order ) ) {
			$order->set_date_paid( null );
			$order->save();
		}
	}

	/**
	 * Payment summary shown to the customer after checkout and in My Account.
	 *
	 * @param WC_Order $order Order being displayed.
	 */
	public function render_customer_summary( $order ) {
		if ( ! $order instanceof WC_Order || ! $this->should_display_summary( $order ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built and escaped in render_summary_markup().
		echo $this->render_summary_markup( $order, false );
	}

	/**
	 * Payment summary appended to transactional order emails.
	 *
	 * @param WC_Order $order         Order being emailed.
	 * @param bool     $sent_to_admin Whether the email targets an administrator.
	 * @param bool     $plain_text    Whether the email is plain text.
	 * @param mixed    $email         Email instance (unused).
	 */
	public function render_email_summary( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		if ( ! $order instanceof WC_Order || ! $this->should_display_summary( $order ) ) {
			return;
		}

		$plain = (bool) $plain_text || ( is_object( $email ) && method_exists( $email, 'get_email_type' ) && 'plain' === $email->get_email_type() );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built and escaped in render_summary_markup().
		echo $this->render_summary_markup( $order, $plain );
	}

	/**
	 * Whether a payment summary is worth showing for this order.
	 *
	 * @param WC_Order $order Order being displayed.
	 * @return bool
	 */
	private function should_display_summary( $order ) {
		if ( (float) $order->get_total() <= self::EPSILON ) {
			return false;
		}

		return $this->is_managed( $order ) || ! empty( $this->get_payments( $order ) );
	}

	/**
	 * Build the customer-facing payment summary.
	 *
	 * @param WC_Order $order Order being displayed.
	 * @param bool     $plain Whether to render plain text instead of markup.
	 * @return string
	 */
	private function render_summary_markup( $order, $plain ) {
		$rows = array(
			__( 'Payment plan', 'lovecatz-wc' )   => $this->get_plan_label( $order ),
			__( 'Payment status', 'lovecatz-wc' ) => $this->get_payment_status_label( $order ),
			__( 'Amount paid', 'lovecatz-wc' )    => $this->format_amount_plain( $order, $this->get_amount_paid( $order ) ),
			__( 'Balance due', 'lovecatz-wc' )    => $this->format_amount_plain( $order, $this->get_balance_due( $order ) ),
		);

		$po_number = $this->get_po_number( $order );
		if ( self::PLAN_PO === $this->get_plan( $order ) ) {
			if ( '' !== $po_number ) {
				$rows[ __( 'Purchase Order number', 'lovecatz-wc' ) ] = $po_number;
			}
			$due_date = $this->get_po_due_date( $order );
			if ( '' !== $due_date ) {
				$rows[ __( 'Payment due', 'lovecatz-wc' ) ] = $this->format_date( $due_date );
			}
		}

		if ( $plain ) {
			$lines = array();
			foreach ( $rows as $label => $value ) {
				$lines[] = $label . ': ' . $value;
			}

			return "\n" . implode( "\n", $lines ) . "\n";
		}

		$html  = '<section class="lwc-payment-summary">';
		$html .= '<h2>' . esc_html__( 'Payment summary', 'lovecatz-wc' ) . '</h2>';
		$html .= '<table class="woocommerce-table shop_table lwc-payment-summary-table"><tbody>';

		foreach ( $rows as $label => $value ) {
			$html .= '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}

		$html .= '</tbody></table></section>';

		return $html;
	}

	/**
	 * Format an amount in the order currency, as HTML.
	 *
	 * @param WC_Order $order  Order providing the currency.
	 * @param float    $amount Amount to format.
	 * @return string
	 */
	public function format_amount( $order, $amount ) {
		$currency = $order instanceof WC_Order ? $order->get_currency() : get_woocommerce_currency();

		return wc_price( (float) $amount, array( 'currency' => $currency ) );
	}

	/**
	 * Format an amount in the order currency, as plain text.
	 *
	 * @param WC_Order $order  Order providing the currency.
	 * @param float    $amount Amount to format.
	 * @return string
	 */
	public function format_amount_plain( $order, $amount ) {
		return wp_strip_all_tags( html_entity_decode( $this->format_amount( $order, $amount ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Format a stored Y-m-d date using the site date format.
	 *
	 * @param string $date Date in Y-m-d.
	 * @return string
	 */
	public function format_date( $date ) {
		$date = trim( (string) $date );
		if ( '' === $date ) {
			return '';
		}

		$timestamp = strtotime( $date );

		return false === $timestamp ? $date : date_i18n( get_option( 'date_format', 'Y-m-d' ), $timestamp );
	}

	/**
	 * Round a money value to the store's price decimals.
	 *
	 * @param float $amount Raw amount.
	 * @return float
	 */
	public function round( $amount ) {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;

		return (float) wc_format_decimal( (float) $amount, $decimals );
	}

	/**
	 * Derive a Purchase Order due date from the order date plus its terms.
	 *
	 * @param WC_Order $order Order providing the starting date.
	 * @param int      $terms Payment term in days.
	 * @return string Y-m-d
	 */
	private function compute_due_date( $order, $terms ) {
		$created = $order->get_date_created();
		$start   = $created ? $created->getTimestamp() : time();
		$terms   = max( 0, (int) $terms );

		return wp_date( 'Y-m-d', $start + ( $terms * DAY_IN_SECONDS ) );
	}

	/**
	 * Validate a posted date, defaulting to today in the site timezone.
	 *
	 * @param mixed $value Posted date.
	 * @return string Y-m-d
	 */
	private function normalize_date( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );

		if ( '' === $value ) {
			return wp_date( 'Y-m-d' );
		}

		$timezone = wp_timezone();
		$date     = DateTimeImmutable::createFromFormat( '!Y-m-d', substr( $value, 0, 10 ), $timezone );
		$errors   = DateTimeImmutable::getLastErrors();

		if ( ! $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			return wp_date( 'Y-m-d' );
		}

		return $date->format( 'Y-m-d' );
	}

	/**
	 * Build a collision-resistant ledger identifier.
	 *
	 * @return string
	 */
	private function generate_payment_id() {
		return 'p' . str_replace( '.', '', (string) microtime( true ) ) . wp_rand( 100, 999 );
	}

	/**
	 * Resolve a WC_Order from whatever the WooCommerce save hooks pass.
	 *
	 * @param mixed $arg1 Order id or order object.
	 * @param mixed $arg2 Order or post object.
	 * @return WC_Order|null
	 */
	private function resolve_order( $arg1, $arg2 = null ) {
		if ( $arg1 instanceof WC_Order ) {
			return $arg1;
		}

		if ( $arg2 instanceof WC_Order ) {
			return $arg2;
		}

		$id = 0;
		if ( is_numeric( $arg1 ) ) {
			$id = (int) $arg1;
		} elseif ( is_object( $arg2 ) && method_exists( $arg2, 'get_id' ) ) {
			$id = (int) $arg2->get_id();
		} elseif ( is_object( $arg1 ) && method_exists( $arg1, 'get_id' ) ) {
			$id = (int) $arg1->get_id();
		}

		$order = $id > 0 ? wc_get_order( $id ) : false;

		return $order instanceof WC_Order ? $order : null;
	}
}
