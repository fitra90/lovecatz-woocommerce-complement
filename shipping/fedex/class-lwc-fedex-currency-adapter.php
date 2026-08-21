<?php
/**
 * FedEx currency adapter for the Octolize Flexible Shipping FedEx plugin.
 *
 * When the WooCommerce checkout currency (for example USD, switched by the
 * woo-multi-currency plugin) differs from the base shop currency (IDR), the
 * Octolize plugin refuses to calculate rates at all: FedexShippingService::rate_shipment()
 * calls verify_currency() and throws a CurrencySwitcherException. This adapter
 * does not modify any Octolize file. Instead it:
 *
 * 1. Temporarily makes get_woocommerce_currency() return the base currency only
 *    while Octolize calculates FedEx rates (scoped via Octolize's own
 *    "flexible_shipping_fedex_settings_before_rate" / "flexible_shipping_fedex_rates"
 *    filters), so the currency gate passes and the base-currency rate exists.
 * 2. Lets the installed currency plugin (woo-multi-currency) perform the actual
 *    IDR -> active currency conversion of the shipping cost, exactly as it does
 *    for every other shipping method. No duplicate currency system is created.
 * 3. Optionally converts the FedEx rate itself using a manual exchange rate when
 *    no currency plugin is active/available (manual conversion mode), while
 *    excluding the Octolize method from the currency plugin conversion to avoid
 *    double conversion.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the engine selector, currency gate-fix and FedEx rate conversion.
 */
class LWC_FedEx_Currency_Adapter {

	const OCTOLIZE_METHOD_ID = 'flexible_shipping_fedex';
	const OCTOLIZE_PLUGIN_FILE = 'flexible-shipping-fedex/flexible-shipping-fedex.php';

	const OPTION_ENGINE          = 'lwc_fedex_engine';
	const OPTION_ADAPTER_ENABLED = 'lwc_fedex_currency_adapter_enabled';
	const OPTION_BASE_CURRENCY   = 'lwc_fedex_base_currency';
	const OPTION_CONVERSION_MODE = 'lwc_fedex_conversion_mode';
	const OPTION_MANUAL_RATE     = 'lwc_fedex_manual_rate';

	const MODE_OCTOLIZE        = 'octolize';
	const MODE_CURRENCY_PLUGIN = 'currency';
	const MODE_MANUAL          = 'manual';

	/**
	 * Whether the base-currency override is currently active.
	 *
	 * @var bool
	 */
	private $currency_override_active = false;

	/**
	 * Converted costs/taxes per package hash + rate key for the current request.
	 *
	 * woocommerce_package_rates can fire several times on the same package
	 * during one request. The converted value is cached per package+rate key
	 * (the same strategy woo-multi-currency uses) so an already-converted rate
	 * is never divided by the manual rate a second time, while different
	 * packages always convert from their own base cost.
	 *
	 * @var array
	 */
	private $converted_rates = array();

	/**
	 * Initialize the adapter hooks.
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'register_admin_settings' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_admin_notice' ) );

		// Avoid double conversion: in manual mode both engines convert their
		// own FedEx rate, so the currency plugin must skip them.
		if ( self::MODE_MANUAL === $this->get_conversion_mode() ) {
			add_filter( 'wmc_excluded_shipping_methods_from_converting', array( $this, 'exclude_from_wmc_conversion' ) );
		}

		if ( ! $this->is_active() ) {
			return;
		}

		// Gate-fix: make Octolize calculate the FedEx rate in the base currency.
		add_filter( 'flexible_shipping_fedex_settings_before_rate', array( $this, 'currency_override_begin' ), 10, 2 );
		add_filter( 'flexible_shipping_fedex_rates', array( $this, 'currency_override_end' ), 10, 2 );
		add_filter( 'woocommerce_currency', array( $this, 'override_currency' ), 100 );

		// Manual conversion (and fallback when no currency plugin is available).
		add_filter( 'woocommerce_package_rates', array( $this, 'convert_fedex_rates' ), 20, 2 );
	}

	/**
	 * Whether the adapter should be active on the frontend.
	 *
	 * The adapter auto-activates when the Octolize engine is selected and the
	 * Octolize Flexible Shipping FedEx plugin is installed/active.  The
	 * per-method enabled checkbox is no longer required for activation — the
	 * adapter is always active in Octolize mode so that currency switching
	 * (CURCY / woo-multi-currency / manual) does not break live-rate quotes.
	 *
	 * @return bool
	 */
	public function is_active() {
		return self::MODE_OCTOLIZE === $this->get_engine()
			&& $this->is_octolize_active();
	}

	/**
	 * Whether a conversion between the configured base currency and the active
	 * checkout currency is actually required.
	 *
	 * @return bool
	 */
	public function is_conversion_required() {
		if ( ! function_exists( 'get_woocommerce_currency' ) ) {
			return false;
		}

		$base_currency    = $this->get_base_currency();
		$active_currency  = strtoupper( (string) get_woocommerce_currency() );

		return '' !== $base_currency && $base_currency !== $active_currency;
	}

	/**
	 * Begin the currency override window (fires before Octolize rate_shipment).
	 *
	 * @param array $settings Service settings.
	 * @param mixed $method   Shipping method.
	 * @return array
	 */
	public function currency_override_begin( $settings, $method ) {
		$this->currency_override_active = true;
		return $settings;
	}

	/**
	 * End the currency override window (fires after Octolize rate_shipment).
	 *
	 * @param mixed $rates   ShipmentRating from the service.
	 * @param mixed $method  Shipping method.
	 * @return mixed
	 */
	public function currency_override_end( $rates, $method ) {
		$this->currency_override_active = false;
		return $rates;
	}

	/**
	 * Return the base currency while Octolize calculates FedEx rates.
	 *
	 * Registered at priority 100 so it runs after woo-multi-currency's
	 * own "woocommerce_currency" filter (priority 99) and overrides it only
	 * during the Octolize rate calculation window.
	 *
	 * @param string $currency Active currency.
	 * @return string
	 */
	public function override_currency( $currency ) {
		if ( $this->currency_override_active ) {
			return get_option( 'woocommerce_currency', $currency );
		}

		return $currency;
	}

	/**
	 * Convert Octolize FedEx shipping rates from the base currency to the active
	 * checkout currency when the adapter is in manual mode (or the currency
	 * plugin is not available and a manual rate is configured).
	 *
	 * @param array $rates   Package rates (keyed WC_Shipping_Rate instances).
	 * @param array $package Shipping package.
	 * @return array
	 */
	public function convert_fedex_rates( $rates, $package ) {
		if ( ! $this->is_active() || ! $this->is_conversion_required() ) {
			return $rates;
		}

		// The built-in converter already converts every shipping rate.
		if ( class_exists( 'LWC_Currency_Converter' ) && LWC_Currency_Converter::instance()->is_active() ) {
			return $rates;
		}

		if ( ! is_array( $rates ) ) {
			return $rates;
		}

		$manual_rate = (float) $this->get_manual_rate();

		if ( self::MODE_CURRENCY_PLUGIN === $this->get_conversion_mode() ) {
			if ( $this->is_wmc_active() ) {
				// The currency plugin already converts every shipping method.
				return $rates;
			}

			if ( $manual_rate <= 0 ) {
				$this->log_warning( 'Currency plugin (woo-multi-currency) is not active and no manual exchange rate is set. The FedEx rate was left unchanged.' );
				return $rates;
			}
		}

		if ( $manual_rate <= 0 ) {
			$this->log_warning( 'Manual conversion mode is active but the manual exchange rate is invalid (must be greater than zero). The FedEx rate was left unchanged.' );
			return $rates;
		}

		$package_hash = $this->get_package_hash( $package );

		foreach ( $rates as $rate_key => $rate ) {
			if ( ! $rate instanceof WC_Shipping_Rate ) {
				continue;
			}

			if ( self::OCTOLIZE_METHOD_ID !== $rate->get_method_id() ) {
				continue;
			}

			$cache_key = $package_hash . '|' . $rate_key;

			if ( isset( $this->converted_rates[ $cache_key ] ) ) {
				$cached = $this->converted_rates[ $cache_key ];
				$rate->set_cost( $cached['cost'] );
				if ( ! empty( $cached['taxes'] ) ) {
					$rate->set_taxes( $cached['taxes'] );
				}
				continue;
			}

			$converted_cost = $this->convert_amount( $rate->get_cost(), $manual_rate );
			if ( null !== $converted_cost ) {
				$rate->set_cost( $converted_cost );
				$cached_taxes = $this->convert_rate_taxes( $rate, $manual_rate );

				$this->converted_rates[ $cache_key ] = array(
					'cost'  => $converted_cost,
					'taxes' => $cached_taxes,
				);
			}
		}

		return $rates;
	}

	/**
	 * Add the Octolize FedEx method to the list of shipping methods that the
	 * woo-multi-currency plugin must not convert (we convert it ourselves).
	 *
	 * @param array $methods Excluded method ids.
	 * @return array
	 */
	public function exclude_from_wmc_conversion( $methods ) {
		$methods[] = self::OCTOLIZE_METHOD_ID;

		if ( ! in_array( 'lwc_fedex', $methods, true ) ) {
			$methods[] = 'lwc_fedex';
		}

		return $methods;
	}

	/**
	 * Get the selected shipping engine.
	 *
	 * @return string 'octolize' or 'lovecatz'.
	 */
	public function get_engine() {
		return self::MODE_OCTOLIZE === get_option( self::OPTION_ENGINE, 'lovecatz' ) ? self::MODE_OCTOLIZE : 'lovecatz';
	}

	/**
	 * Get the adapter enabled state.
	 *
	 * @return string 'yes' or 'no'.
	 */
	public function get_adapter_enabled() {
		return 'yes' === get_option( self::OPTION_ADAPTER_ENABLED, 'no' ) ? 'yes' : 'no';
	}

	/**
	 * Get the FedEx base currency (the currency Octolize quotes the rate in).
	 *
	 * @return string
	 */
	public function get_base_currency() {
		$value = sanitize_text_field( (string) get_option( self::OPTION_BASE_CURRENCY, 'IDR' ) );
		$value = preg_replace( '/[^A-Z]/', '', strtoupper( $value ) );

		return ( 3 === strlen( $value ) ) ? $value : 'IDR';
	}

	/**
	 * Get the conversion mode.
	 *
	 * @return string 'currency' or 'manual'.
	 */
	public function get_conversion_mode() {
		return self::MODE_MANUAL === get_option( self::OPTION_CONVERSION_MODE, self::MODE_CURRENCY_PLUGIN )
			? self::MODE_MANUAL
			: self::MODE_CURRENCY_PLUGIN;
	}

	/**
	 * Get the manual exchange rate: how many units of the base currency equal
	 * one unit of the target currency. Example: 16500 means 1 USD = 16,500 IDR.
	 *
	 * @return float
	 */
	public function get_manual_rate() {
		return (float) get_option( self::OPTION_MANUAL_RATE, 16500 );
	}

	/**
	 * Register the admin settings for the engine selector and the adapter.
	 */
	public function register_admin_settings() {
		register_setting(
			'lwc_shipping_fedex_options',
			self::OPTION_ENGINE,
			array(
				'capability'        => 'manage_woocommerce',
				'sanitize_callback' => array( $this, 'sanitize_engine' ),
			)
		);
		register_setting(
			'lwc_shipping_fedex_options',
			self::OPTION_ADAPTER_ENABLED,
			array(
				'capability'        => 'manage_woocommerce',
				'sanitize_callback' => array( $this, 'sanitize_yes_no' ),
			)
		);
		register_setting(
			'lwc_shipping_fedex_options',
			self::OPTION_BASE_CURRENCY,
			array(
				'capability'        => 'manage_woocommerce',
				'sanitize_callback' => array( $this, 'sanitize_base_currency' ),
			)
		);
		register_setting(
			'lwc_shipping_fedex_options',
			self::OPTION_CONVERSION_MODE,
			array(
				'capability'        => 'manage_woocommerce',
				'sanitize_callback' => array( $this, 'sanitize_conversion_mode' ),
			)
		);
		register_setting(
			'lwc_shipping_fedex_options',
			self::OPTION_MANUAL_RATE,
			array(
				'capability'        => 'manage_woocommerce',
				'sanitize_callback' => array( $this, 'sanitize_manual_rate' ),
			)
		);

		add_settings_section(
			'lwc_shipping_fedex_adapter_section',
			__( 'FedEx Shipping Engine & Currency Adapter', 'lovecatz-wc' ),
			array( $this, 'render_adapter_section_intro' ),
			'lwc_shipping_fedex_options'
		);

		add_settings_field(
			self::OPTION_ENGINE,
			__( 'Shipping Engine', 'lovecatz-wc' ),
			array( $this, 'render_engine_field' ),
			'lwc_shipping_fedex_options',
			'lwc_shipping_fedex_adapter_section'
		);

		add_settings_field(
			self::OPTION_ADAPTER_ENABLED,
			__( 'Enable Currency Adapter', 'lovecatz-wc' ),
			array( $this, 'render_adapter_enabled_field' ),
			'lwc_shipping_fedex_options',
			'lwc_shipping_fedex_adapter_section'
		);

		add_settings_field(
			self::OPTION_BASE_CURRENCY,
			__( 'FedEx Base Currency', 'lovecatz-wc' ),
			array( $this, 'render_base_currency_field' ),
			'lwc_shipping_fedex_options',
			'lwc_shipping_fedex_adapter_section'
		);

		add_settings_field(
			self::OPTION_CONVERSION_MODE,
			__( 'Conversion Mode', 'lovecatz-wc' ),
			array( $this, 'render_conversion_mode_field' ),
			'lwc_shipping_fedex_options',
			'lwc_shipping_fedex_adapter_section'
		);

		add_settings_field(
			self::OPTION_MANUAL_RATE,
			__( 'Manual Exchange Rate', 'lovecatz-wc' ),
			array( $this, 'render_manual_rate_field' ),
			'lwc_shipping_fedex_options',
			'lwc_shipping_fedex_adapter_section'
		);
	}

	/**
	 * Render the section introduction.
	 */
	public function render_adapter_section_intro() {
		$engine = $this->get_engine();
		?>
		<div class="lwc-fedex-adapter-settings">
			<p>
				<?php esc_html_e( 'Select which FedEx integration drives the shipping rates, and configure the currency adapter that makes Octolize live rates work when the checkout currency differs from the base currency.', 'lovecatz-wc' ); ?>
			</p>
			<?php if ( self::MODE_OCTOLIZE === $engine && ! $this->is_octolize_active() ) : ?>
				<p class="description" style="color:#b32d2e;">
					<?php esc_html_e( 'Warning: the Octolize "Flexible Shipping FedEx" plugin is not installed or not active. The adapter cannot act until it is.', 'lovecatz-wc' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the engine selector field.
	 */
	public function render_engine_field() {
		$engine = $this->get_engine();
		?>
		<div class="lwc-fedex-engine-choices">
			<label class="lwc-fedex-engine-choice">
				<input type="radio" name="<?php echo esc_attr( self::OPTION_ENGINE ); ?>" id="lwc_fedex_engine_lovecatz" value="lovecatz" <?php checked( 'lovecatz', $engine ); ?> />
				<?php esc_html_e( 'LoveCatz FedEx API', 'lovecatz-wc' ); ?>
			</label>
			<label class="lwc-fedex-engine-choice">
				<input type="radio" name="<?php echo esc_attr( self::OPTION_ENGINE ); ?>" id="lwc_fedex_engine_octolize" value="octolize" <?php checked( self::MODE_OCTOLIZE, $engine ); ?> />
				<?php esc_html_e( 'Octolize FedEx Live Rates', 'lovecatz-wc' ); ?>
			</label>
		</div>
		<p class="description"><?php esc_html_e( 'Use the Octolize plugin (flexible_shipping_fedex) for live checkout rates, or the built-in LoveCatz FedEx API integration.', 'lovecatz-wc' ); ?></p>
		<?php
	}

	/**
	 * Render the adapter enabled field.
	 */
	public function render_adapter_enabled_field() {
		$enabled = $this->get_adapter_enabled();
		?>
		<div class="lwc-fedex-adapter-settings">
			<label>
				<input type="checkbox" name="<?php echo esc_attr( self::OPTION_ADAPTER_ENABLED ); ?>" id="lwc_fedex_currency_adapter_enabled" value="yes" <?php checked( 'yes', $enabled ); ?> />
				<?php esc_html_e( 'Let Octolize FedEx rates appear and be converted when the checkout currency differs from the base currency.', 'lovecatz-wc' ); ?>
			</label>
		</div>
		<?php
	}

	/**
	 * Render the base currency field.
	 */
	public function render_base_currency_field() {
		$value = $this->get_base_currency();
		?>
		<div class="lwc-fedex-adapter-settings">
			<input type="text" name="<?php echo esc_attr( self::OPTION_BASE_CURRENCY ); ?>" id="lwc_fedex_base_currency" value="<?php echo esc_attr( $value ); ?>" class="small-text" maxlength="3" />
			<p class="description"><?php esc_html_e( 'ISO code of the currency the FedEx account quotes rates in (usually the WooCommerce base currency, IDR).', 'lovecatz-wc' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render the conversion mode field.
	 */
	public function render_conversion_mode_field() {
		$mode = $this->get_conversion_mode();
		?>
		<div class="lwc-fedex-adapter-settings">
			<label class="lwc-fedex-engine-choice">
				<input type="radio" name="<?php echo esc_attr( self::OPTION_CONVERSION_MODE ); ?>" id="lwc_fedex_conversion_mode_currency" value="<?php echo esc_attr( self::MODE_CURRENCY_PLUGIN ); ?>" <?php checked( self::MODE_CURRENCY_PLUGIN, $mode ); ?> />
				<?php esc_html_e( 'Use the currency plugin (woo-multi-currency)', 'lovecatz-wc' ); ?>
			</label>
			<label class="lwc-fedex-engine-choice">
				<input type="radio" name="<?php echo esc_attr( self::OPTION_CONVERSION_MODE ); ?>" id="lwc_fedex_conversion_mode_manual" value="<?php echo esc_attr( self::MODE_MANUAL ); ?>" <?php checked( self::MODE_MANUAL, $mode ); ?> />
				<?php esc_html_e( 'Use a manual exchange rate', 'lovecatz-wc' ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'Recommended: let woo-multi-currency convert the FedEx rate like every other shipping method.', 'lovecatz-wc' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render the manual exchange rate field.
	 */
	public function render_manual_rate_field() {
		$value = $this->get_manual_rate();
		?>
		<div class="lwc-fedex-adapter-settings lwc-fedex-manual-rate-field">
			<input type="number" min="0.0001" step="0.0001" name="<?php echo esc_attr( self::OPTION_MANUAL_RATE ); ?>" id="lwc_fedex_manual_rate" value="<?php echo esc_attr( $value ); ?>" class="small-text" />
			<p class="description"><?php esc_html_e( 'How many units of the base currency equal one unit of the target currency. Example: 16500 means 1 USD = 16,500 IDR (IDR 750,000 / 16,500 = 45.45 USD).', 'lovecatz-wc' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Show an admin notice when the Octolize engine is selected but the
	 * Octolize plugin is not available.
	 */
	public function maybe_render_admin_notice() {
		if ( self::MODE_OCTOLIZE !== $this->get_engine() || $this->is_octolize_active() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'toplevel_page_lovecatz-wc' !== $screen->id ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<?php esc_html_e( 'LoveCatz: the FedEx engine is set to "Octolize FedEx Live Rates" but the "Flexible Shipping FedEx" plugin is not installed or not active. Install and activate it to use live FedEx rates.', 'lovecatz-wc' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Sanitize the engine setting.
	 *
	 * @param string $value Submitted value.
	 * @return string
	 */
	public function sanitize_engine( $value ) {
		return self::MODE_OCTOLIZE === $value ? self::MODE_OCTOLIZE : 'lovecatz';
	}

	/**
	 * Sanitize a yes/no option.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_yes_no( $value ) {
		return 'yes' === $value ? 'yes' : 'no';
	}

	/**
	 * Sanitize the base currency setting.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_base_currency( $value ) {
		$value = preg_replace( '/[^A-Z]/', '', strtoupper( sanitize_text_field( (string) $value ) ) );

		return ( 3 === strlen( $value ) ) ? $value : 'IDR';
	}

	/**
	 * Sanitize the conversion mode setting.
	 *
	 * @param string $value Submitted value.
	 * @return string
	 */
	public function sanitize_conversion_mode( $value ) {
		return self::MODE_MANUAL === $value ? self::MODE_MANUAL : self::MODE_CURRENCY_PLUGIN;
	}

	/**
	 * Sanitize the manual exchange rate setting.
	 *
	 * @param mixed $value Submitted value.
	 * @return float
	 */
	public function sanitize_manual_rate( $value ) {
		$value = (float) $value;

		return ( $value > 0 ) ? $value : 0.0;
	}

	/**
	 * Convert an amount from the base currency using the manual rate.
	 *
	 * Returns null when the amount should be left untouched (zero or invalid).
	 *
	 * @param mixed $amount Amount in base currency.
	 * @param float $rate   Manual exchange rate.
	 * @return float|null
	 */
	private function convert_amount( $amount, $rate ) {
		if ( ! is_numeric( $amount ) ) {
			return null;
		}

		$amount = (float) $amount;
		if ( $amount <= 0 || $rate <= 0 ) {
			return null;
		}

		$converted = $amount / $rate;

		// Round to the active currency's decimals (integers for IDR etc.).
		if ( class_exists( 'LWC_Currency_Converter' ) && function_exists( 'get_woocommerce_currency' ) ) {
			return LWC_Currency_Converter::round_for_currency( $converted, strtoupper( (string) get_woocommerce_currency() ) );
		}

		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;

		return round( $converted, $decimals );
	}

	/**
	 * Convert the shipping rate taxes using the manual rate.
	 *
	 * @param WC_Shipping_Rate $rate Shipping rate.
	 * @param float            $rate_manual Manual exchange rate.
	 * @return array Converted taxes.
	 */
	private function convert_rate_taxes( $rate, $rate_manual ) {
		$taxes = $rate->get_taxes();
		if ( ! is_array( $taxes ) || empty( $taxes ) ) {
			return array();
		}

		$new_taxes = array();
		foreach ( $taxes as $tax_key => $tax_amount ) {
			$converted = $this->convert_amount( $tax_amount, $rate_manual );

			$new_taxes[ $tax_key ] = ( null !== $converted ) ? $converted : $tax_amount;
		}

		$rate->set_taxes( $new_taxes );

		return $new_taxes;
	}

	/**
	 * Build a stable per-package hash used as part of the conversion cache key.
	 *
	 * @param array $package Shipping package.
	 * @return string
	 */
	private function get_package_hash( $package ) {
		$serialized = @serialize( $package );
		if ( false === $serialized ) {
			$serialized = @json_encode( $package );
		}

		return md5( (string) $serialized );
	}

	/**
	 * Whether the Octolize Flexible Shipping FedEx plugin is installed/active.
	 *
	 * @return bool
	 */
	private function is_octolize_active() {
		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( self::OCTOLIZE_PLUGIN_FILE ) ) {
			return true;
		}

		$active = (array) get_option( 'active_plugins', array() );
		if ( in_array( self::OCTOLIZE_PLUGIN_FILE, $active, true ) ) {
			return true;
		}

		if ( is_multisite() ) {
			$sitewide = (array) get_site_option( 'active_sitewide_plugins', array() );
			if ( isset( $sitewide[ self::OCTOLIZE_PLUGIN_FILE ] ) ) {
				return true;
			}
		}

		return class_exists( 'WPDesk\FlexibleShippingFedex\Plugin' );
	}

	/**
	 * Whether the woo-multi-currency plugin is active and enabled.
	 *
	 * @return bool
	 */
	private function is_wmc_active() {
		if ( ! function_exists( 'wmc_get_price' ) || ! class_exists( 'WOOMULTI_CURRENCY_F_Data' ) ) {
			return false;
		}

		$data = WOOMULTI_CURRENCY_F_Data::get_ins();

		return $data && method_exists( $data, 'get_enable' ) && $data->get_enable();
	}

	/**
	 * Log a warning using the existing LoveCatz logger.
	 *
	 * @param string $message Message (no credentials ever).
	 */
	private function log_warning( $message ) {
		if ( class_exists( 'LWC_Logger' ) ) {
			LWC_Logger::log( 'FedEx currency adapter: ' . $message, 'warning', 'lovecatz-wc' );
		}
	}
}
