<?php
/**
 * Custom order ID (order number) generator.
 *
 * Builds a human readable order ID such as SBI-DD_20260923_Local_0001 from a
 * prefix, a date, a scope label and an automatic running number. The scope is
 * derived from the shipping country: Indonesia is "local", everywhere else is
 * "global". Each scope owns its own format and its own counter.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_Order_Number {

	const OPT_ENABLED     = 'lwc_order_number_enabled';
	const OPT_RESET_DAILY = 'lwc_order_number_reset_daily';
	const OPT_SEPARATOR   = 'lwc_order_number_separator';
	const OPT_ENABLED_AT  = 'lwc_order_number_enabled_at';

	const META_NUMBER = '_lwc_order_number';
	const META_SCOPE  = '_lwc_order_number_scope';

	const SCOPE_LOCAL  = 'local';
	const SCOPE_GLOBAL = 'global';

	/**
	 * Singleton instance.
	 *
	 * @var LWC_Order_Number|null
	 */
	private static $instance = null;

	/**
	 * Per-scope field defaults.
	 *
	 * @var array
	 */
	private $defaults = array(
		self::SCOPE_LOCAL  => array(
			'prefix'      => 'SBI-DD',
			'label'       => 'Local',
			'date_format' => 'Ymd',
			'suffix'      => '',
			'padding'     => 4,
			'start'       => 1,
		),
		self::SCOPE_GLOBAL => array(
			'prefix'      => 'SBI-DD',
			'label'       => 'Global',
			'date_format' => 'Ymd',
			'suffix'      => '',
			'padding'     => 4,
			'start'       => 1,
		),
	);

	/**
	 * Get (and lazily create) the singleton instance.
	 *
	 * @return LWC_Order_Number
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 *
	 * Every order-creation path eventually calls WC_Order::save(), which fires
	 * `woocommerce_before_order_object_save`. Hooking that one place covers the
	 * admin "Add new order" auto-draft, the checkout flow, programmatic
	 * `wc_create_order()`, and any 3rd-party importer that persists through
	 * WooCommerce. `woocommerce_new_order` and `woocommerce_process_shop_order_meta`
	 * stay as defensive duplicates. The `woocommerce_order_number` filter only
	 * displays stored custom IDs; reads must never mutate historical orders.
	 */
	public function init() {
		// Self-heal: the enable timestamp gates auto-assignment so old orders
		// keep their original number. It is normally stamped the first time the
		// switch goes on, but installs that enabled the feature before that
		// stamp existed never receive one — everything stays "eligible" and
		// every old order would be renumbered. Stamp it (with a one-day grace
		// window so orders created moments ago, like an open Add-order draft,
		// remain eligible) the moment we notice it is missing.
		if ( $this->is_enabled() && ! get_option( self::OPT_ENABLED_AT ) ) {
			update_option( self::OPT_ENABLED_AT, time() - DAY_IN_SECONDS, false );
		}

		// Display the generated ID wherever WooCommerce prints an order number.
		add_filter( 'woocommerce_order_number', array( $this, 'filter_order_number' ), 10, 2 );

		// Fires for every WC_Order::save() call (create + update) in both HPOS and CPT.
		add_action( 'woocommerce_before_order_object_save', array( $this, 'ensure_order_number' ), 10, 2 );

		// Fired by WooCommerce right after an order row is inserted.
		add_action( 'woocommerce_new_order', array( $this, 'ensure_order_number_on_creation' ), 10, 2 );

		// Fired by WC_Meta_Box_Order_Data::save when an admin saves the order edit screen.
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'ensure_order_number' ), 10, 2 );
	}

	/**
	 * Whether the feature is active.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return 'yes' === get_option( self::OPT_ENABLED, 'no' );
	}

	/**
	 * Display-only: return the custom ID when one is stored, else the raw number.
	 *
	 * This filter must never mutate the order. An earlier version assigned IDs
	 * lazily from here, which silently numbered old and trashed orders the
	 * moment an admin browsed the order list — consuming running numbers and
	 * stamping IDs on orders that predate the feature. Orders that somehow
	 * bypass every save hook keep their raw number until an administrator
	 * sets one manually from the Order Number metabox.
	 *
	 * Filter signature kept stable: ($number, $order).
	 */
	public function filter_order_number( $number, $order ) {
		if ( ! $this->is_enabled() || ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return $number;
		}

		$custom = (string) $order->get_meta( self::META_NUMBER );

		return '' !== $custom ? $custom : $number;
	}

	/**
	 * Generic entry point used by the save hooks.
	 *
	 * Accepts either ($order) or ($id, $order). If only an id is supplied the
	 * order is reloaded so we always work against a fresh WC_Order instance.
	 */
	public function ensure_order_number( $arg1 = null, $arg2 = null ) {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$order = $this->resolve_order( $arg1, $arg2 );
		if ( ! $order ) {
			return;
		}

		$this->assign_if_eligible( $order );
	}

	/**
	 * Entry point for `woocommerce_new_order`, which fires the moment the row
	 * exists — often before the caller attaches an address (`wc_create_order()`
	 * persists first, then the address is set, then save() runs again). Skipping
	 * the address-less creation save lets that second save pick the right scope.
	 */
	public function ensure_order_number_on_creation( $arg1 = null, $arg2 = null ) {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$order = $this->resolve_order( $arg1, $arg2 );
		if ( ! $order || ! $this->has_known_destination( $order ) ) {
			return;
		}

		$this->assign_if_eligible( $order );
	}

	/**
	 * Pull a WC_Order out of any (id, order, post) combo the hooks may pass.
	 *
	 * Refunds and other non-order objects resolve to nothing: they have no
	 * order number and must never consume a running number.
	 *
	 * @param mixed $arg1 Order, id, or WP_Post.
	 * @param mixed $arg2 Order or WP_Post, depending on the hook.
	 * @return WC_Order|null
	 */
	private function resolve_order( $arg1, $arg2 = null ) {
		if ( $arg1 instanceof WC_Order ) {
			return $arg1;
		}

		if ( $arg2 instanceof WC_Order ) {
			return $arg2;
		}

		$candidate = null;

		if ( is_numeric( $arg1 ) ) {
			$candidate = wc_get_order( (int) $arg1 );
		} elseif ( is_object( $arg1 ) && method_exists( $arg1, 'get_id' ) ) {
			$candidate = wc_get_order( (int) $arg1->get_id() );
		}

		return $candidate instanceof WC_Order ? $candidate : null;
	}

	/**
	 * Assign the custom ID when the order is eligible and not already numbered.
	 *
	 * Guards, in order:
	 * 1. The order already carries an ID — never renumber.
	 * 2. The order predates the feature — old records keep their original number.
	 * 3. No destination is known yet. The scope would be a guess: WooCommerce's
	 *    admin "Add new order" screen creates the auto-draft on page load, and
	 *    `wc_create_order()` persists before the caller can attach an address.
	 *    While the order is being created (id 0) or is still a draft, the
	 *    assignment waits for the address. Once the order is real, the store
	 *    base country is the fallback.
	 *
	 * @param WC_Order $order Order being considered.
	 */
	private function assign_if_eligible( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( '' !== (string) $order->get_meta( self::META_NUMBER ) ) {
			return;
		}

		if ( ! $this->is_eligible_for_number( $order ) ) {
			return;
		}

		if ( ! $this->has_known_destination( $order ) && ( 0 === $order->get_id() || $this->is_draft_status( $order ) ) ) {
			return;
		}

		$this->assign( $order );
	}

	/**
	 * Whether the order already carries a destination we can derive a scope from.
	 *
	 * @param WC_Order $order Order being considered.
	 * @return bool
	 */
	private function has_known_destination( $order ) {
		return '' !== (string) $order->get_shipping_country() || '' !== (string) $order->get_billing_country();
	}

	/**
	 * Whether the order is still a WooCommerce draft rather than a real order.
	 *
	 * @param WC_Order $order Order being considered.
	 * @return bool
	 */
	private function is_draft_status( $order ) {
		return in_array( $order->get_status(), array( 'auto-draft', 'draft', 'checkout-draft', 'new' ), true );
	}

	/**
	 * Build the custom ID and write it back to the order's meta.
	 *
	 * The order object's in-memory meta list is updated and `save_meta_data()`
	 * persists it without re-entering any save hook. Safe to call from
	 * `woocommerce_before_order_object_save` (the data store flushes the meta
	 * again as part of its own `save_meta_data()` call later in the save).
	 *
	 * A freshly built ID is checked against existing orders: when a collision is
	 * found (most commonly after the daily-reset switch was toggled, which
	 * restarts the sequence under a new counter key), the next running number is
	 * consumed until a unique ID comes out. Duplicates such as two orders
	 * sharing `SBI-DD_20260924_Local_0001` cannot occur again.
	 */
	private function assign( $order ) {
		$scope = $this->scope_for_order( $order );
		$stamp = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();

		$exclude = $order->get_id();
		$number  = $this->next_number( $scope );
		$custom  = $this->build( $scope, $number, $stamp );

		$attempts = 0;
		while ( $this->order_id_exists( $custom, $exclude ) ) {
			if ( ++$attempts >= 1000 ) {
				// Fail closed: never write a duplicate, even if a corrupted counter
				// or hostile filter produces an unreasonable collision chain.
				return;
			}

			$number = $this->next_number( $scope );
			$custom = $this->build( $scope, $number, $stamp );
		}

		$order->update_meta_data( self::META_NUMBER, $custom );
		$order->update_meta_data( self::META_SCOPE, $scope );
		$order->save_meta_data();
	}

	/**
	 * Whether the order was created on or after the feature was enabled.
	 *
	 * Orders created earlier keep their original number — opening them in the
	 * admin later never assigns a custom ID. A null date_created (brand-new
	 * order mid-save) is treated as eligible.
	 *
	 * @param WC_Order $order Order being considered.
	 * @return bool
	 */
	public function is_eligible_for_number( $order ) {
		$enabled_at = (int) get_option( self::OPT_ENABLED_AT, 0 );
		if ( $enabled_at <= 0 ) {
			return true;
		}

		$created = $order->get_date_created();
		if ( ! $created ) {
			return true;
		}

		return $created->getTimestamp() >= $enabled_at;
	}

	/**
	 * Check whether a custom order ID is already assigned to another order.
	 *
	 * Used to prevent duplicate custom IDs — both when an admin manually
	 * corrects an order number and when the generator picks the next ID.
	 * Searches the HPOS `wc_orders_meta` table first (note the plural table
	 * name — there is no `wc_order_meta`), then falls back to the classic
	 * `postmeta` table so CPT installations and HPOS backfills are covered.
	 * Refunds never match because of the `type = 'shop_order'` join.
	 *
	 * @param string $custom_id       Custom order ID to check.
	 * @param int    $exclude_order_id Exclude this order's own ID (its own meta
	 *                                 will of course match — skip it).
	 * @return bool True if another order already carries this custom ID.
	 */
	public function order_id_exists( $custom_id, $exclude_order_id = 0 ) {
		global $wpdb;

		$custom_id = trim( (string) $custom_id );
		if ( '' === $custom_id ) {
			return false;
		}

		$ex_id = (int) $exclude_order_id;

		// HPOS orders meta table (plural: wc_orders_meta).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Duplicate lookup needs one exact-match query, not a meta_query round-trip.
		$hpos_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT om.order_id
			 FROM {$wpdb->prefix}wc_orders_meta om
			 INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = om.order_id
			 WHERE om.meta_key = %s AND om.meta_value = %s
			   AND o.type = 'shop_order' AND om.order_id != %d
			 LIMIT 1",
			self::META_NUMBER,
			$custom_id,
			$ex_id
		) );

		if ( null !== $hpos_id && (int) $hpos_id > 0 ) {
			return true;
		}

		// CPT / postmeta fallback.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$post_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key = %s AND meta_value = %s AND post_id != %d
			 LIMIT 1",
			self::META_NUMBER,
			$custom_id,
			$ex_id
		) );

		return null !== $post_id && (int) $post_id > 0;
	}

	/**
	 * Resolve the scope from the destination country.
	 *
	 * Shipping country wins; billing country is the fallback so orders without
	 * a shipping address still land in a scope. An unknown country falls back
	 * to the store base country.
	 *
	 * @param WC_Order $order Order object.
	 * @return string local|global
	 */
	public function scope_for_order( $order ) {
		$country = strtoupper( (string) $order->get_shipping_country() );

		if ( '' === $country ) {
			$country = strtoupper( (string) $order->get_billing_country() );
		}

		if ( '' === $country && function_exists( 'WC' ) && WC() && WC()->countries ) {
			$country = strtoupper( (string) WC()->countries->get_base_country() );
		}

		return 'ID' === $country ? self::SCOPE_LOCAL : self::SCOPE_GLOBAL;
	}

	/**
	 * Build the option name of a per-scope field.
	 *
	 * @param string $scope Scope slug.
	 * @param string $field Field slug.
	 * @return string
	 */
	public function option_name( $scope, $field ) {
		return 'lwc_order_number_' . $scope . '_' . $field;
	}

	/**
	 * Read one per-scope setting.
	 *
	 * @param string $scope Scope slug.
	 * @param string $field Field slug.
	 * @return string|int
	 */
	public function get_scope_setting( $scope, $field ) {
		$default = isset( $this->defaults[ $scope ][ $field ] ) ? $this->defaults[ $scope ][ $field ] : '';

		return get_option( $this->option_name( $scope, $field ), $default );
	}

	/**
	 * Separator placed between the parts of the ID.
	 *
	 * @return string
	 */
	public function get_separator() {
		$separator = (string) get_option( self::OPT_SEPARATOR, '_' );

		return '' === $separator ? '_' : $separator;
	}

	/**
	 * Assemble the ID for a scope and a running number.
	 *
	 * Empty parts are dropped so an unused suffix or an empty date never
	 * leaves a dangling separator behind.
	 *
	 * @param string $scope  Scope slug.
	 * @param int    $number Running number.
	 * @param int    $stamp  Timestamp used for the date part.
	 * @return string
	 */
	public function build( $scope, $number, $stamp = null ) {
		$stamp    = null === $stamp ? time() : (int) $stamp;
		$format   = trim( (string) $this->get_scope_setting( $scope, 'date_format' ) );
		$padding  = max( 0, (int) $this->get_scope_setting( $scope, 'padding' ) );
		$sequence = (string) $number;

		if ( $padding > 0 ) {
			$sequence = str_pad( $sequence, $padding, '0', STR_PAD_LEFT );
		}

		$parts = array(
			trim( (string) $this->get_scope_setting( $scope, 'prefix' ) ),
			'' === $format ? '' : wp_date( $format, $stamp ),
			trim( (string) $this->get_scope_setting( $scope, 'label' ) ),
			$sequence,
			trim( (string) $this->get_scope_setting( $scope, 'suffix' ) ),
		);

		return implode( $this->get_separator(), array_filter( $parts, 'strlen' ) );
	}

	/**
	 * Preview the next ID a scope would issue. Never consumes a number.
	 *
	 * @param string $scope Scope slug.
	 * @return string
	 */
	public function preview( $scope ) {
		return $this->build( $scope, $this->peek_number( $scope ) );
	}

	/**
	 * Read the next running number without incrementing the counter.
	 *
	 * @param string $scope Scope slug.
	 * @return int
	 */
	public function peek_number( $scope ) {
		$start    = max( 1, (int) $this->get_scope_setting( $scope, 'start' ) );
		$current  = (int) get_option( $this->counter_option( $scope ), 0 );

		return max( $start, $current + 1 );
	}

	/**
	 * Reserve and return the next running number for a scope.
	 *
	 * The increment runs as a single SQL statement so two simultaneous
	 * checkouts can never receive the same number.
	 *
	 * @param string $scope Scope slug.
	 * @return int
	 */
	public function next_number( $scope ) {
		global $wpdb;

		$start = max( 1, (int) $this->get_scope_setting( $scope, 'start' ) );
		$key   = $this->counter_option( $scope );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic counter: a read-modify-write in PHP could collide.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(option_value + 1) WHERE option_name = %s", $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from $wpdb.

		if ( $wpdb->rows_affected ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$number = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
		} else {
			$number = $this->start_counter( $key, $start );
		}

		// A raised start value must win over an older, smaller counter.
		if ( $number < $start ) {
			$number = $start;
			update_option( $key, $number );
		}

		return $number;
	}

	/**
	 * Create the counter option for a scope and return its first number.
	 *
	 * @param string $key   Counter option name.
	 * @param int    $start First number to issue.
	 * @return int
	 */
	private function start_counter( $key, $start ) {
		global $wpdb;

		if ( add_option( $key, $start, '', 'no' ) ) {
			return $start;
		}

		// Another request created it first: fall back to incrementing it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(option_value + 1) WHERE option_name = %s", $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from $wpdb.

		if ( $wpdb->rows_affected ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
		}

		return max( $start, (int) get_option( $key, 0 ) );
	}

	/**
	 * Counter option name for a scope. Daily counters carry the date so each
	 * day starts again from the configured start value.
	 *
	 * @param string $scope Scope slug.
	 * @return string
	 */
	private function counter_option( $scope ) {
		$key = 'lwc_order_number_seq_' . $scope;

		if ( 'yes' === get_option( self::OPT_RESET_DAILY, 'no' ) ) {
			$key .= '_' . wp_date( 'Ymd' );
		}

		return $key;
	}
}
