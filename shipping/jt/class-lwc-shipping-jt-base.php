<?php
/**
 * J&T shipping method base for LoveCatz WooCommerce Complement.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared behavior for the J&T Express and J&T Cargo methods.
 *
 * J&T Express and J&T Cargo are separate API providers with individual
 * weight rules; concrete classes declare their provider identity and
 * package weight limits.
 */
abstract class LWC_Shipping_JT_Base extends WC_Shipping_Method {

	/**
	 * Provider slug: express or cargo.
	 *
	 * @var string
	 */
	protected $provider = '';

	/**
	 * Default auto-split threshold in kilograms (0 disables auto-splitting).
	 *
	 * @var float
	 */
	protected $default_threshold_kg = 0;

	/**
	 * Constructor.
	 *
	 * @param int $instance_id Shipping method instance ID.
	 */
	public function __construct( $instance_id = 0 ) {
		$config = $this->get_provider_config();

		$this->id                 = 'lwc_jt_' . $this->provider;
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = $config['title'];
		$this->method_description = $config['description'];

		$this->supports = array( 'shipping-zones', 'instance-settings' );

		parent::__construct( $instance_id );
		$this->init();
	}

	/**
	 * Provider identity and copy.
	 *
	 * @return array
	 */
	abstract protected function get_provider_config();

	/**
	 * Carrier-specific maximum weight per package in kilograms.
	 *
	 * @return float
	 */
	abstract protected function get_provider_weight_ceiling();

	/**
	 * Initialize shipping method settings.
	 */
	public function init() {
		$this->init_form_fields();
		$this->init_settings();
	}

	/**
	 * Define shipping method form fields.
	 */
	public function init_form_fields() {
		$config = $this->get_provider_config();
		$ceiling = $this->get_provider_weight_ceiling();

		$this->form_fields = array(
			'enabled'      => array(
				'title'   => __( 'Enable/Disable', 'lovecatz-wc' ),
				'type'    => 'checkbox',
				'label'   => sprintf(
					/* translators: %s: provider name */
					__( 'Enable %s Shipping', 'lovecatz-wc' ),
					$config['title']
				),
				'default' => 'no',
			),
			'title'        => array(
				'title'       => __( 'Method Title', 'lovecatz-wc' ),
				'type'        => 'text',
				'description' => __( 'This controls the title displayed to the user during checkout.', 'lovecatz-wc' ),
				'default'     => $config['title'],
				'desc_tip'    => true,
			),
			'description'  => array(
				'title'       => __( 'Method Description', 'lovecatz-wc' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description displayed to the user during checkout.', 'lovecatz-wc' ),
				'default'     => $config['checkout_description'],
				'desc_tip'    => true,
			),
			'flat_cost'    => array(
				'title'       => __( 'Flat cost', 'lovecatz-wc' ),
				'type'        => 'price',
				'description' => __( 'Provisional flat rate used until the live J&T API integration is enabled.', 'lovecatz-wc' ),
				'default'     => 10,
				'desc_tip'    => true,
			),
			'max_package_weight_kg' => array(
				'title'             => __( 'Max package weight (kg)', 'lovecatz-wc' ),
				'type'              => 'number',
				'custom_attributes' => array( 'max' => (string) max( 0, $ceiling ) ),
				'description'       => $config['weight_description'],
				'default'           => $this->default_threshold_kg,
				'desc_tip'          => true,
			),
		);
	}

	/**
	 * Calculate shipping rates.
	 *
	 * Provisional flat rate until the live provider API lands. The cost is
	 * rounded through the currency helper because J&T quotes whole rupiah
	 * amounts. The configured split threshold travels with the rate so the
	 * future live integration reuses the same cartonization per provider.
	 *
	 * @param array $package Shipping package data.
	 */
	public function calculate_shipping( $package = array() ) {
		$cost = (float) $this->get_option( 'flat_cost', 10 );

		if ( class_exists( 'LWC_Currency_Converter' ) ) {
			$cost = LWC_Currency_Converter::round_for_currency( $cost );
		}

		$this->add_rate(
			array(
				'id'       => $this->get_rate_id(),
				'label'    => $this->title,
				'cost'     => $cost,
				'calc_tax' => 'per_order',
				'meta_data' => array(
					'lwc_jt_provider' => $this->provider,
					'lwc_jt_max_weight' => $this->get_max_package_weight(),
				),
			)
		);
	}

	/**
	 * Get the configured split threshold capped at the provider ceiling.
	 *
	 * @return float
	 */
	public function get_max_package_weight() {
		$max = (float) $this->get_option( 'max_package_weight_kg', $this->default_threshold_kg );
		$ceiling = (float) $this->get_provider_weight_ceiling();

		if ( $ceiling > 0 && $max > $ceiling ) {
			$max = $ceiling;
		}

		return $max;
	}
}
