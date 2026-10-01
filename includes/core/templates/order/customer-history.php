<?php
/**
 * Customer history metabox override that keeps totals in the customer's
 * checkout currency.
 *
 * WooCommerce's built-in {@see automattic/woocommerce/templates/order/customer-history.php}
 * sums every paid order's `total_amount` regardless of currency and then
 * renders the result with `wc_price()` using the store currency. When a
 * shopper paid in a foreign currency (USD) but the store base currency is
 * IDR, the metabox prints nonsense such as "Rp 1,476" for a USD order
 * total of $1,476.
 *
 * This override re-aggregates the customer's paid orders in the same
 * currency as the order currently being viewed, so the symbol, decimals,
 * and total all line up.
 *
 * @package LoveCatzWC
 *
 * @var int    $orders_count    Original WooCommerce count, used as a fallback.
 * @var float  $total_spend     Original WooCommerce total, used as a fallback.
 * @var float  $avg_order_value Original WooCommerce average, used as a fallback.
 * @var string $tooltip         Tooltip text for the "Total orders" heading.
 */

defined( 'ABSPATH' ) || exit;

$converter = class_exists( '\\LWC_Currency_Converter' ) ? LWC_Currency_Converter::instance() : null;
$current_order = $converter ? $converter->get_current_admin_order() : null;

// Fall back to the values WooCommerce already computed when the order is
// unavailable (auto-draft, new order, etc.) or when the helper class is
// missing. `get_customer_history()` already short-circuits in that case.
if ( ! $current_order instanceof WC_Order ) {
	?>
	<div class="customer-history order-attribution-metabox">
		<h4>
			<?php
			esc_html_e( 'Total orders', 'woocommerce' );
			echo wp_kses_post( wc_help_tip( $tooltip ) );
			?>
		</h4>
		<span class="order-attribution-total-orders">
			<?php echo esc_html( $orders_count ); ?>
		</span>

		<h4>
			<?php
			esc_html_e( 'Total revenue', 'woocommerce' );
			echo wp_kses_post(
				wc_help_tip(
					__( "This is the Customer Lifetime Value, or the total amount you have earned from this customer's orders.", 'woocommerce' )
				)
			);
			?>
		</h4>
		<span class="order-attribution-total-spend">
			<?php echo wp_kses_post( wc_price( $total_spend ) ); ?>
		</span>

		<h4><?php esc_html_e( 'Average order value', 'woocommerce' ); ?></h4>
		<span class="order-attribution-average-order-value">
			<?php echo wp_kses_post( wc_price( $avg_order_value ) ); ?>
		</span>
	</div>
	<?php
	return;
}

$order_currency = strtoupper( (string) $current_order->get_currency() );

// `wc_price()` formats with the shop's default currency unless we override it,
// which is exactly what causes the bug. Pass the current order's currency so
// the symbol, decimals, and amount stay in the buyer's currency.
$price_args = array( 'currency' => $order_currency );

$history = $converter->get_customer_history_for_currency( $current_order, $order_currency );

// The customer's other orders may be in the base currency: keep WooCommerce's
// own totals visible under the per-currency breakdown so the admin still sees
// the lifetime value when the customer has both IDR and USD orders.
$store_currency = strtoupper( (string) get_option( 'woocommerce_currency', 'IDR' ) );
$has_mixed_history = $order_currency !== $store_currency && $orders_count > $history['orders_count'];
?>
<div class="customer-history order-attribution-metabox">
	<h4>
		<?php
		esc_html_e( 'Total orders', 'woocommerce' );
		echo wp_kses_post( wc_help_tip( $tooltip ) );
		?>
	</h4>
	<span class="order-attribution-total-orders">
		<?php
		echo esc_html( $history['orders_count'] );
		if ( $has_mixed_history ) {
			/* translators: %s: total order count across all currencies. */
			echo ' <small>(' . esc_html( sprintf( __( '%s across all currencies', 'lovecatz-wc' ), (int) $orders_count ) ) . ')</small>';
		}
		?>
	</span>

	<h4>
		<?php
		esc_html_e( 'Total revenue', 'woocommerce' );
		echo wp_kses_post(
			wc_help_tip(
				__( "This is the Customer Lifetime Value, or the total amount you have earned from this customer's orders.", 'woocommerce' )
			)
		);
		?>
	</h4>
	<span class="order-attribution-total-spend">
		<?php echo wp_kses_post( wc_price( (float) $history['total_spend'], $price_args ) ); ?>
		<?php if ( $has_mixed_history ) : ?>
			<small class="lwc-customer-history-all-currencies">
				<?php
				/* translators: %s: lifetime total across all currencies. */
				echo esc_html( sprintf( __( 'Lifetime total across all currencies: %s', 'lovecatz-wc' ), wp_strip_all_tags( wc_price( (float) $total_spend ) ) ) );
				?>
			</small>
		<?php endif; ?>
	</span>

	<h4><?php esc_html_e( 'Average order value', 'woocommerce' ); ?></h4>
	<span class="order-attribution-average-order-value">
		<?php
		$avg = $history['orders_count'] > 0 ? (float) $history['total_spend'] / (int) $history['orders_count'] : 0.0;
		echo wp_kses_post( wc_price( $avg, $price_args ) );
		?>
	</span>
</div>