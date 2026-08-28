<?php
/**
 * Built-in lightweight currency converter.
 *
 * Lets the shop switch between the base currency (for example IDR) and a set
 * of manually configured target currencies (for example USD) without relying
 * on an external currency plugin. When an external converter (CURCY /
 * woo-multi-currency, WOOCS, Aelia, WPML, YayCurrency) is detected it steps
 * aside automatically so conversion never happens twice.
 *
 * Rate convention matches the FedEx adapter: one rate unit means "how many
 * units of the base currency equal one unit of the target currency"
 * (USD=16500 means 1 USD = 16,500 IDR). Conversion divides by the rate.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_Currency_Converter {

	const COOKIE_NAME = 'lwc_currency';
	const OPT_ENABLED = 'lwc_currency_enabled';
	const OPT_RATES   = 'lwc_currency_rates';
	const META_BASE_CURRENCY = '_lwc_currency_base';
	const META_ORDER_RATE    = '_lwc_currency_rate';
	const META_BASE_TOTAL    = '_lwc_currency_base_total';

	/**
	 * Shared instance.
	 *
	 * @var LWC_Currency_Converter|null
	 */
	private static $instance = null;

	/**
	 * Parsed rates cache.
	 *
	 * @var array|null
	 */
	private $rates = null;

	/**
	 * Resolved selected currency cache.
	 *
	 * @var string|null
	 */
	private $selected = null;

	/**
	 * External converter detection cache.
	 *
	 * @var bool|null
	 */
	private $external = null;

	/**
	 * Currencies with no decimal places.
	 *
	 * @var array
	 */
	private static $zero_decimal_currencies = array(
		'BIF', 'CLP', 'DJF', 'GNF', 'IDR', 'ISK', 'JPY', 'KMF', 'KRW',
		'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
	);

	/**
	 * Common currency symbols.
	 *
	 * @var array
	 */
	private static $symbols = array(
		'USD' => '$',
		'EUR' => '€',
		'GBP' => '£',
		'JPY' => '¥',
		'CNY' => '¥',
		'KRW' => '₩',
		'SGD' => 'S$',
		'MYR' => 'RM',
		'THB' => '฿',
		'PHP' => '₱',
		'VND' => '₫',
		'INR' => '₹',
		'AUD' => 'A$',
		'CAD' => 'C$',
		'NZD' => 'NZ$',
		'HKD' => 'HK$',
		'CHF' => 'CHF',
		'IDR' => 'Rp',
	);

	/**
	 * Retrieve the shared instance.
	 *
	 * @return LWC_Currency_Converter
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'register_admin_settings' ) );
		add_action( 'init', array( $this, 'capture_currency_switch' ), 1 );
		add_shortcode( 'lwc_currency_switcher', array( $this, 'render_currency_switcher' ) );
		add_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'render_admin_base_total' ) );

		// Keep cart totals consistent when the shopper switches currency.
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'maybe_recalculate_cart' ) );

		if ( ! $this->owns_currency_conversion() ) {
			return;
		}

		// Freeze the checkout rate on the order. Reporting must never depend on
		// a future rate change, otherwise historical revenue would move over time.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'snapshot_order_currency' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'snapshot_store_api_order_currency' ), 20, 1 );
		add_filter( 'woocommerce_analytics_update_order_stats_data', array( $this, 'normalize_order_stats' ), 20, 2 );

		add_filter( 'woocommerce_currency', array( $this, 'override_currency' ), 99 );
		add_filter( 'woocommerce_currency_symbol', array( $this, 'filter_currency_symbol' ), 10, 2 );
		add_filter( 'option_woocommerce_price_num_decimals', array( $this, 'filter_price_decimals_option' ) );

		// Product prices (simple products and variations).
		add_filter( 'woocommerce_product_get_price', array( $this, 'convert_price' ) );
		add_filter( 'woocommerce_product_get_regular_price', array( $this, 'convert_price' ) );
		add_filter( 'woocommerce_product_get_sale_price', array( $this, 'convert_price' ) );
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'convert_price' ) );
		add_filter( 'woocommerce_product_variation_get_regular_price', array( $this, 'convert_price' ) );
		add_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'convert_price' ) );

		// Shipping rates for every method, including the LoveCatz providers.
		add_filter( 'woocommerce_package_rates', array( $this, 'convert_shipping_rates' ), 10, 2 );
	}

	/**
	 * Whether the built-in converter currently owns currency switching.
	 *
	 * @return bool
	 */
	public function is_active() {
		if ( ! $this->owns_currency_conversion() ) {
			return false;
		}

		$selected = $this->get_selected_currency();
		if ( '' === $selected || $selected === $this->get_base_currency() ) {
			return false;
		}

		return $this->get_rate_for( $selected ) > 0;
	}

	/**
	 * Whether LoveCatz, rather than another plugin, owns currency conversion.
	 *
	 * @return bool
	 */
	public function owns_currency_conversion() {
		return $this->is_enabled() && ! $this->has_external_converter();
	}

	/**
	 * Whether the feature is enabled in settings.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return 'yes' === get_option( self::OPT_ENABLED, 'no' );
	}

	/**
	 * Whether an external currency plugin is running.
	 *
	 * @return bool
	 */
	public function has_external_converter() {
		if ( null !== $this->external ) {
			return $this->external;
		}

		$external = false;

		if ( function_exists( 'wmc_get_price' ) || class_exists( 'WOOMULTI_CURRENCY_F_Data' ) ) {
			$external = true; // CURCY / woo-multi-currency.
		} elseif ( class_exists( 'WOOCS' ) ) {
			$external = true;
		} elseif ( class_exists( 'WC_Aelia_CurrencySwitcher' ) ) {
			$external = true;
		} elseif ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$external = true; // WPML multilingual currency.
		} elseif ( class_exists( 'YayCurrency' ) ) {
			$external = true;
		}

		$this->external = apply_filters( 'lwc_currency_external_converter_active', $external );

		return $this->external;
	}

	/**
	 * The shop base currency.
	 *
	 * @return string
	 */
	public function get_base_currency() {
		// Read the stored option directly. get_woocommerce_currency() applies our
		// own filter and would incorrectly report the shopper currency as the base.
		$base = get_option( 'woocommerce_currency', '' );

		return strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $base ) );
	}

	/**
	 * The currency requested via query param or cookie.
	 *
	 * @return string Three-letter code or empty string.
	 */
	public function get_selected_currency() {
		if ( null !== $this->selected ) {
			return $this->selected;
		}

		$candidates = array();

		if ( isset( $_GET['currency'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$candidates[] = sanitize_text_field( wp_unslash( $_GET['currency'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			$candidates[] = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
		}

		foreach ( $candidates as $candidate ) {
			$code = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $candidate ) );
			if ( 3 === strlen( $code ) && ( $code === $this->get_base_currency() || isset( $this->get_rates()[ $code ] ) ) ) {
				$this->selected = $code;
				return $code;
			}
		}

		$this->selected = '';
		return '';
	}

	/**
	 * Persist a valid ?currency= switch into a cookie.
	 */
	public function capture_currency_switch() {
		if ( ! isset( $_GET['currency'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$code = strtoupper( preg_replace( '/[^A-Za-z]/', '', sanitize_text_field( wp_unslash( $_GET['currency'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 3 !== strlen( $code ) || ( $code !== $this->get_base_currency() && ! isset( $this->get_rates()[ $code ] ) ) ) {
			return;
		}

		$this->selected = $code;

		if ( ! headers_sent() ) {
			if ( function_exists( 'wc_setcookie' ) ) {
				wc_setcookie( self::COOKIE_NAME, $code, time() + 30 * DAY_IN_SECONDS );
			} else {
				setcookie( self::COOKIE_NAME, $code, time() + 30 * DAY_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN );
			}
		}
	}

	/**
	 * Parsed rate list: target code => base units per one target unit.
	 *
	 * @return array
	 */
	public function get_rates() {
		if ( null !== $this->rates ) {
			return $this->rates;
		}

		$rates = array();
		$raw = explode( "\n", (string) get_option( self::OPT_RATES, '' ) );

		foreach ( $raw as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			if ( ! preg_match( '/^([A-Z]{3})\s*[=:]\s*([0-9]+(?:\.[0-9]+)?)$/i', $line, $matches ) ) {
				continue;
			}

			$rate = (float) $matches[2];
			if ( $rate <= 0 ) {
				continue;
			}

			$rates[ strtoupper( $matches[1] ) ] = $rate;
		}

		$this->rates = $rates;

		return $rates;
	}

	/**
	 * Conversion rate for a target currency.
	 *
	 * @param string $currency Target currency code.
	 * @return float Zero when unknown.
	 */
	public function get_rate_for( $currency ) {
		$rates = $this->get_rates();
		$currency = strtoupper( (string) $currency );

		return isset( $rates[ $currency ] ) ? (float) $rates[ $currency ] : 0.0;
	}

	/**
	 * Report the active checkout currency to WooCommerce.
	 *
	 * @param string $currency Default currency.
	 * @return string
	 */
	public function override_currency( $currency ) {
		$selected = $this->get_selected_currency();

		return '' !== $selected ? $selected : $currency;
	}

	/**
	 * Swap the symbol for the selected currency.
	 *
	 * @param string $symbol   Default symbol.
	 * @param string $currency Currency code.
	 * @return string
	 */
	public function filter_currency_symbol( $symbol, $currency ) {
		$selected = $this->get_selected_currency();
		if ( '' === $selected || $selected !== strtoupper( (string) $currency ) ) {
			return $symbol;
		}

		$symbols = apply_filters( 'lwc_currency_symbols', self::$symbols );

		return isset( $symbols[ $selected ] ) ? $symbols[ $selected ] : $selected;
	}

	/**
	 * Display converted prices as whole units for predictable courier payloads.
	 *
	 * @param mixed $value Stored decimals option.
	 * @return int
	 */
	public function filter_price_decimals_option( $value ) {
		$selected = $this->get_selected_currency();

		// Returning through wc_get_price_decimals() here would read this same
		// option again and recurse until PHP exhausts its memory. With no shopper
		// currency selected, preserve WooCommerce's stored base-currency setting.
		if ( '' === $selected || $selected === $this->get_base_currency() ) {
			return (int) $value;
		}

		return 0;
	}

	/**
	 * Convert a product price into the selected currency.
	 *
	 * @param mixed $price Price in base currency.
	 * @return mixed
	 */
	public function convert_price( $price ) {
		if ( ! is_numeric( $price ) ) {
			return $price;
		}

		$converted = $this->convert_amount( (float) $price );

		return null === $converted ? $price : $converted;
	}

	/**
	 * Convert every shipping rate cost/tax into the selected currency.
	 *
	 * @param array $rates   Package rates.
	 * @param array $package Package data.
	 * @return array
	 */
	public function convert_shipping_rates( $rates, $package ) {
		if ( ! is_array( $rates ) ) {
			return $rates;
		}

		foreach ( $rates as $key => $rate ) {
			if ( ! $rate instanceof WC_Shipping_Rate ) {
				continue;
			}

			$cost = $this->convert_amount( (float) $rate->get_cost() );
			if ( null !== $cost ) {
				$rate->set_cost( $cost );
			}

			$taxes = $rate->get_taxes();
			if ( is_array( $taxes ) && ! empty( $taxes ) ) {
				$new_taxes = array();
				foreach ( $taxes as $tax_key => $tax_amount ) {
					$converted_tax = $this->convert_amount( (float) $tax_amount );
					$new_taxes[ $tax_key ] = null === $converted_tax ? $tax_amount : $converted_tax;
				}
				$rate->set_taxes( $new_taxes );
			}
		}

		return $rates;
	}

	/**
	 * Convert an amount from the base currency into the selected currency.
	 *
	 * @param float $amount Amount in base currency.
	 * @return float|null Null when conversion is not possible.
	 */
	public function convert_amount( $amount ) {
		$selected = $this->get_selected_currency();
		if ( '' === $selected ) {
			return null;
		}

		$rate = $this->get_rate_for( $selected );
		if ( $rate <= 0 || $amount <= 0 ) {
			return null;
		}

		return self::round_for_currency( $amount / $rate, $selected );
	}

	/**
	 * Save the exact checkout rate and IDR/base equivalent on a classic order.
	 *
	 * @param WC_Order $order Checkout order.
	 * @param array    $data  Checkout data.
	 */
	public function snapshot_order_currency( $order, $data = array() ) {
		$this->save_order_currency_snapshot( $order );
	}

	/**
	 * Save the checkout rate for a Checkout Block / Store API order.
	 *
	 * @param WC_Order $order Checkout order.
	 */
	public function snapshot_store_api_order_currency( $order ) {
		$this->save_order_currency_snapshot( $order );
	}

	/**
	 * Store an immutable conversion snapshot on an order.
	 *
	 * @param WC_Order $order Order object.
	 */
	private function save_order_currency_snapshot( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$base     = $this->get_base_currency();
		$currency = strtoupper( (string) $order->get_currency() );
		$rate     = $currency === $base ? 1.0 : $this->get_rate_for( $currency );

		if ( '' === $base || $rate <= 0 ) {
			return;
		}

		$order->update_meta_data( self::META_BASE_CURRENCY, $base );
		$order->update_meta_data( self::META_ORDER_RATE, (string) $rate );
		$order->update_meta_data(
			self::META_BASE_TOTAL,
			(string) self::round_for_currency( (float) $order->get_total() * $rate, $base )
		);
	}

	/**
	 * Normalize WooCommerce Analytics revenue into the store base currency.
	 *
	 * @param array    $order_data Lookup-table row being generated.
	 * @param WC_Order $order      Source order.
	 * @return array
	 */
	public function normalize_order_stats( $order_data, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return $order_data;
		}

		$rate = (float) $order->get_meta( self::META_ORDER_RATE, true );
		if ( $rate <= 0 ) {
			$currency = strtoupper( (string) $order->get_currency() );
			$rate     = $currency === $this->get_base_currency() ? 1.0 : $this->get_rate_for( $currency );
		}

		if ( $rate <= 0 || 1.0 === $rate ) {
			return $order_data;
		}

		$base = $this->get_base_currency();
		foreach ( array( 'total_sales', 'tax_total', 'shipping_total', 'net_total' ) as $field ) {
			if ( isset( $order_data[ $field ] ) && is_numeric( $order_data[ $field ] ) ) {
				$order_data[ $field ] = self::round_for_currency( (float) $order_data[ $field ] * $rate, $base );
			}
		}

		return $order_data;
	}

	/**
	 * Show the frozen base-currency value beside the original order total.
	 *
	 * @param int $order_id Order ID.
	 */
	public function render_admin_base_total( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$base  = strtoupper( (string) $order->get_meta( self::META_BASE_CURRENCY, true ) );
		$rate  = (float) $order->get_meta( self::META_ORDER_RATE, true );
		$total = $order->get_meta( self::META_BASE_TOTAL, true );

		if ( '' === $base || $rate <= 0 || ! is_numeric( $total ) || strtoupper( (string) $order->get_currency() ) === $base ) {
			return;
		}
		?>
		<tr>
			<td class="label"><?php esc_html_e( 'Base-currency total:', 'lovecatz-wc' ); ?></td>
			<td width="1%"></td>
			<td class="total">
				<?php echo wp_kses_post( wc_price( (float) $total, array( 'currency' => $base ) ) ); ?>
				<small style="display:block"><?php echo esc_html( sprintf( __( 'Checkout rate: 1 %1$s = %2$s %3$s', 'lovecatz-wc' ), $order->get_currency(), wc_format_decimal( $rate ), $base ) ); ?></small>
			</td>
		</tr>
		<?php
	}

	/**
	 * Currency dropdown for headers, footers, widgets, or page builders.
	 * Usage: [lwc_currency_switcher]
	 *
	 * @return string
	 */
	public function render_currency_switcher() {
		if ( ! $this->owns_currency_conversion() ) {
			return '';
		}

		$base       = $this->get_base_currency();
		$selected   = $this->get_selected_currency();
		$selected   = '' !== $selected ? $selected : $base;
		$currencies = array_merge( array( $base => 1.0 ), $this->get_rates() );
		$action     = remove_query_arg( 'currency' );

		ob_start();
		?>
		<form class="lwc-currency-switcher" method="get" action="<?php echo esc_url( $action ); ?>">
			<label>
				<span class="screen-reader-text"><?php esc_html_e( 'Choose currency', 'lovecatz-wc' ); ?></span>
				<select name="currency" onchange="this.form.submit()">
					<?php foreach ( $currencies as $code => $rate ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $selected, $code ); ?>><?php echo esc_html( $code ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<noscript><button type="submit"><?php esc_html_e( 'Apply', 'lovecatz-wc' ); ?></button></noscript>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Recalculate cart totals after a currency switch.
	 *
	 * @param WC_Cart $cart Cart object.
	 */
	public function maybe_recalculate_cart( $cart ) {
		if ( $this->is_active() && $cart ) {
			$cart->calculate_totals();
		}
	}

	/**
	 * Round every converted monetary amount upward to a whole unit.
	 *
	 * Courier APIs are deliberately given integer money values. Using ceil()
	 * also prevents conversion from understating a product or shipping charge.
	 *
	 * @param mixed  $amount   Amount to round.
	 * @param string $currency Currency code; empty means the active currency.
	 * @return float
	 */
	public static function round_for_currency( $amount, $currency = '' ) {
		if ( ! is_numeric( $amount ) ) {
			return $amount;
		}

		return (float) ceil( (float) $amount );
	}

	/**
	 * Decimal count for a currency.
	 *
	 * @param string $currency Currency code; empty means the active currency.
	 * @return int
	 */
	public static function get_currency_decimals( $currency = '' ) {
		if ( '' === $currency ) {
			return function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		}

		$currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $currency ) );

		return (int) apply_filters( 'lwc_currency_decimals', 0, $currency );
	}

	/**
	 * Register the Currency tab settings.
	 */
	public function register_admin_settings() {
		register_setting( 'lwc_currency_options', self::OPT_ENABLED, array( 'sanitize_callback' => array( $this, 'sanitize_yes_no' ) ) );
		register_setting( 'lwc_currency_options', self::OPT_RATES, array( 'sanitize_callback' => array( $this, 'sanitize_rates' ) ) );

		add_settings_section(
			'lwc_currency_section',
			__( 'Built-in Currency Converter', 'lovecatz-wc' ),
			array( $this, 'render_currency_section_intro' ),
			'lwc_currency_options'
		);

		add_settings_field(
			self::OPT_ENABLED,
			__( 'Enable Converter', 'lovecatz-wc' ),
			array( $this, 'render_enabled_field' ),
			'lwc_currency_options',
			'lwc_currency_section'
		);

		add_settings_field(
			self::OPT_RATES,
			__( 'Exchange Rates', 'lovecatz-wc' ),
			array( $this, 'render_rates_field' ),
			'lwc_currency_options',
			'lwc_currency_section'
		);
	}

	/**
	 * Render the section introduction with runtime status.
	 */
	public function render_currency_section_intro() {
		?>
		<p><?php esc_html_e( 'Let shoppers switch currencies while each order keeps its checkout currency, frozen exchange rate, and base-currency reporting total. Rates are manual: one line per currency.', 'lovecatz-wc' ); ?></p>
		<p class="description"><?php esc_html_e( 'Place [lwc_currency_switcher] in a page, header, footer, or shortcode block to show the selector.', 'lovecatz-wc' ); ?></p>
		<?php if ( $this->has_external_converter() ) : ?>
			<p class="description" style="color:#b32d2e;">
				<?php esc_html_e( 'An external currency plugin is active, so this built-in converter stays idle to avoid double conversion.', 'lovecatz-wc' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the enable field.
	 */
	public function render_enabled_field() {
		$value = $this->is_enabled() ? 'yes' : 'no';
		echo '<input type="checkbox" name="' . esc_attr( self::OPT_ENABLED ) . '" value="yes" ' . checked( 'yes', $value, false ) . ' /> ';
		esc_html_e( 'Enable the built-in currency converter.', 'lovecatz-wc' );
	}

	/**
	 * Render the rates field.
	 */
	public function render_rates_field() {
		$value = (string) get_option( self::OPT_RATES, '' );
		echo '<textarea name="' . esc_attr( self::OPT_RATES ) . '" rows="5" class="large-text code">' . esc_textarea( $value ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'One currency per line: CODE=rate where rate is how many base-currency units equal one unit of that currency. Example for an IDR store: USD=16500 on line one means 1 USD = 16,500 IDR. Shoppers switch with ?currency=USD.', 'lovecatz-wc' ) . '</p>';
	}

	/**
	 * Sanitize the enabled checkbox.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_yes_no( $value ) {
		return 'yes' === $value ? 'yes' : 'no';
	}

	/**
	 * Normalize the rates textarea.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_rates( $value ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $value );
		$out = array();

		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}

			if ( ! preg_match( '/^([A-Za-z]{3})\s*[=:]\s*([0-9]+(?:\.[0-9]+)?)$/', $line, $matches ) ) {
				continue;
			}

			$rate = (float) $matches[2];
			if ( $rate <= 0 ) {
				continue;
			}

			$out[] = strtoupper( $matches[1] ) . '=' . $rate;
		}

		return implode( "\n", $out );
	}
}
