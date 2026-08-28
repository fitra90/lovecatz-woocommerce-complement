<?php
/**
 * Admin settings class for LoveCatz WooCommerce Complement.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_Admin_Settings {

	/**
	 * Store the latest import result for display on the page.
	 *
	 * @var array
	 */
	private $last_import_result = array();

	/**
	 * Initialize admin hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_user_import' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_post_lwc_print_member_card', array( $this, 'print_member_card' ) );
		add_action( 'admin_post_lwc_print_own_member_card', array( $this, 'print_own_member_card' ) );
		add_action( 'admin_post_lwc_delete_store_member', array( $this, 'delete_store_member' ) );
		add_action( 'admin_post_lwc_download_member_import_template', array( $this, 'download_member_import_template' ) );
		add_action( 'woocommerce_account_dashboard', array( $this, 'render_customer_member_card_button' ) );
	}

	/**
	 * Add the settings page to the admin menu.
	 */
	public function add_settings_page() {
		$menu_title = get_option( 'lwc_menu_title', __( 'LoveCatz', 'lovecatz-wc' ) );
		$menu_icon  = get_option( 'lwc_menu_icon', 'dashicons-pets' );

		add_menu_page(
			__( 'LoveCatz', 'lovecatz-wc' ),
			$menu_title,
			'manage_woocommerce',
			'lovecatz-wc',
			array( $this, 'render_settings_page' ),
			$menu_icon,
			56
		);

		// Sidebar shortcuts: one entry per main tab. Each slug carries the
		// tab query so the links land directly on that tab. The first entry
		// reuses the parent slug, replacing WordPress's automatic duplicate
		// label with "Setting".
		foreach ( $this->get_main_tabs() as $tab => $label ) {
			add_submenu_page(
				'lovecatz-wc',
				$label,
				$label,
				'manage_woocommerce',
				'settings' === $tab ? 'lovecatz-wc' : 'lovecatz-wc&tab=' . $tab,
				array( $this, 'render_settings_page' )
			);
		}

		// Highlight the matching sidebar entry while a tab is open.
		add_filter( 'submenu_file', array( $this, 'filter_submenu_file' ) );
	}

	/**
	 * Main plugin tabs shown as sidebar submenu entries.
	 *
	 * @return array tab slug => label
	 */
	public function get_main_tabs() {
		return array(
			'settings'      => __( 'Setting', 'lovecatz-wc' ),
			'products'      => __( 'Products', 'lovecatz-wc' ),
			'store-members' => __( 'Members', 'lovecatz-wc' ),
			'shipping'      => __( 'Shipping', 'lovecatz-wc' ),
			'promo'         => __( 'Promo', 'lovecatz-wc' ),
			'currency'      => __( 'Currency', 'lovecatz-wc' ),
		);
	}

	/**
	 * Point WordPress at the submenu entry matching the active tab.
	 *
	 * @param string $submenu_file Current submenu slug.
	 * @return string
	 */
	public function filter_submenu_file( $submenu_file ) {
		if ( isset( $_GET['page'], $_GET['tab'] ) && 'lovecatz-wc' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$tab = sanitize_key( wp_unslash( $_GET['tab'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $this->get_main_tabs()[ $tab ] ) ) {
				return 'lovecatz-wc&tab=' . $tab;
			}
		}

		return $submenu_file;
	}

	/**
	 * Enqueue admin scripts and styles for the plugin settings page.
	 */
	public function enqueue_admin_assets( $hook ) {
		// Menu styling must load on every admin screen (the sidebar is global).
		wp_enqueue_style( 'lwc-admin-menu', LWC_PLUGIN_URL . 'includes/admin/admin-menu.css', array(), LWC_VERSION );

		if ( get_current_screen() && 'toplevel_page_lovecatz-wc' !== get_current_screen()->id ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );
		wp_enqueue_media();
		wp_enqueue_style( 'lwc-admin-settings', LWC_PLUGIN_URL . 'includes/admin/admin-settings.css', array(), LWC_VERSION );
		wp_enqueue_script( 'lwc-admin-settings', LWC_PLUGIN_URL . 'includes/admin/admin-settings.js', array( 'jquery' ), LWC_VERSION, true );

		wp_localize_script(
			'lwc-admin-settings',
			'lwcShippingSettings',
			array(
				'ajax_url'         => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( 'lwc_fedex_connection_check' ),
				'checking'         => __( 'Checking credentials…', 'lovecatz-wc' ),
				'requestFailed'    => __( 'Connection request failed.', 'lovecatz-wc' ),
				'waiting'          => __( 'Waiting for credentials', 'lovecatz-wc' ),
			)
		);
	}

	/**
	 * Render the settings page with tabs.
	 */
	public function render_settings_page() {
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'settings';
		if ( 'import-users' === $active_tab ) {
			$active_tab = 'store-members';
		}
		if ( 'couriers' === $active_tab ) {
			$active_tab = 'shipping';
		}
		if ( ! in_array( $active_tab, array( 'settings', 'products', 'shipping', 'promo', 'currency', 'store-members' ), true ) ) {
			$active_tab = 'settings';
		}

		$provider = isset( $_GET['provider'] ) ? sanitize_text_field( wp_unslash( $_GET['provider'] ) ) : 'fedex';
		// Keep old bookmarked J&T URLs working while presenting both carriers as
		// independent providers in the settings navigation.
		if ( 'jt' === $provider ) {
			$provider = 'jt_express';
		}
		if ( ! in_array( $provider, array( 'jt_express', 'jt_cargo', 'fedex', 'rayspeed' ), true ) ) {
			$provider = 'fedex';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'LoveCatz WooCommerce Complement Settings', 'lovecatz-wc' ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<a href="?page=lovecatz-wc&tab=settings" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Setting', 'lovecatz-wc' ); ?></a>
				<a href="?page=lovecatz-wc&tab=products" class="nav-tab <?php echo 'products' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Products', 'lovecatz-wc' ); ?></a>
				<a href="?page=lovecatz-wc&tab=store-members" class="nav-tab <?php echo 'store-members' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Members', 'lovecatz-wc' ); ?></a>
				<a href="?page=lovecatz-wc&tab=shipping" class="nav-tab <?php echo 'shipping' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Shipping', 'lovecatz-wc' ); ?></a>
				<a href="?page=lovecatz-wc&tab=promo" class="nav-tab <?php echo 'promo' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Promo', 'lovecatz-wc' ); ?></a>
				<a href="?page=lovecatz-wc&tab=currency" class="nav-tab <?php echo 'currency' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Currency', 'lovecatz-wc' ); ?></a>
			</h2>

			<?php $this->render_import_result_notice(); ?>

			<?php if ( 'settings' === $active_tab ) : ?>
				<form method="post" action="options.php">
					<?php
					settings_fields( 'lwc_general_options' );
					do_settings_sections( 'lwc_general_options' );
					submit_button();
					?>
				</form>
			<?php elseif ( 'shipping' === $active_tab ) : ?>
				<h2 class="nav-tab-wrapper lwc-shipping-provider-tabs">
					<a href="?page=lovecatz-wc&tab=shipping&provider=fedex" class="nav-tab <?php echo 'fedex' === $provider ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'FedEx', 'lovecatz-wc' ); ?></a>
					<a href="?page=lovecatz-wc&tab=shipping&provider=jt_express" class="nav-tab <?php echo 'jt_express' === $provider ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'J&T Express', 'lovecatz-wc' ); ?></a>
					<a href="?page=lovecatz-wc&tab=shipping&provider=jt_cargo" class="nav-tab <?php echo 'jt_cargo' === $provider ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'J&T Cargo', 'lovecatz-wc' ); ?></a>
					<a href="?page=lovecatz-wc&tab=shipping&provider=rayspeed" class="nav-tab <?php echo 'rayspeed' === $provider ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'RaySpeed', 'lovecatz-wc' ); ?></a>
				</h2>

				<?php if ( in_array( $provider, array( 'jt_express', 'jt_cargo' ), true ) ) : ?>
					<?php $jt_provider = 'jt_cargo' === $provider ? 'cargo' : 'express'; ?>
					<div class="notice notice-info inline"><p><?php echo esc_html( 'express' === $jt_provider ? __( 'J&T Express is available only for domestic shipments within Indonesia.', 'lovecatz-wc' ) : __( 'J&T Cargo is an independent domestic provider. Its credentials and future API workflow are not shared with J&T Express.', 'lovecatz-wc' ) ); ?></p></div>
					<form method="post" action="options.php">
						<?php
						settings_fields( "lwc_shipping_jt_{$jt_provider}_options" );
						do_settings_sections( "lwc_shipping_jt_{$jt_provider}_options" );
						submit_button();
						?>
					</form>
				<?php elseif ( 'fedex' === $provider ) : ?>
					<div class="notice notice-info inline">
						<p><?php esc_html_e( 'FedEx will be available for international shipments where the destination country is outside Indonesia.', 'lovecatz-wc' ); ?></p>
					</div>
					<form method="post" action="options.php">
						<?php
						settings_fields( 'lwc_shipping_fedex_options' );
						do_settings_sections( 'lwc_shipping_fedex_options' );
						submit_button();
						?>
					</form>
				<?php else : ?>
					<div class="notice notice-info inline"><p><?php esc_html_e( 'RaySpeed RETAIL Sandbox is offered only for shipping destinations outside Indonesia.', 'lovecatz-wc' ); ?></p></div>
					<form method="post" action="options.php">
						<?php settings_fields( 'lwc_shipping_rayspeed_options' ); do_settings_sections( 'lwc_shipping_rayspeed_options' ); submit_button(); ?>
					</form>
				<?php endif; ?>
			<?php elseif ( 'promo' === $active_tab ) : ?>
				<?php do_action( 'lwc_render_promo_manager' ); ?>
			<?php elseif ( 'products' === $active_tab ) : ?>
				<form method="post" action="options.php">
					<?php settings_fields( 'lwc_products_options' ); do_settings_sections( 'lwc_products_options' ); submit_button(); ?>
				</form>
			<?php elseif ( 'currency' === $active_tab ) : ?>
				<form method="post" action="options.php">
					<?php
					settings_fields( 'lwc_currency_options' );
					do_settings_sections( 'lwc_currency_options' );
					submit_button();
					?>
				</form>
			<?php else : ?>
				<?php $this->render_store_members(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings() {
		LWC_Logger::log( 'Registering admin settings.', 'info' );

		// Shipping provider settings: J&T Express and J&T Cargo are separate
		// API providers with their own credentials.
		foreach ( array( 'express', 'cargo' ) as $jt_provider ) {
			$group = "lwc_shipping_jt_{$jt_provider}_options";
			$section = "lwc_shipping_jt_{$jt_provider}_section";

			register_setting( $group, "lwc_jt_{$jt_provider}_environment", array( 'sanitize_callback' => array( $this, 'sanitize_jt_environment' ) ) );
			foreach ( array( 'sandbox', 'production' ) as $jt_environment ) {
				$prefix = "lwc_jt_{$jt_provider}_{$jt_environment}";
				$text_fields = 'express' === $jt_provider ? array( 'order_username', 'tariff_customer_name', 'tracking_company_id', 'cancel_username' ) : array( 'username' );
				$secret_fields = 'express' === $jt_provider ? array( 'order_key', 'order_api_key', 'tariff_check_key', 'tracking_password', 'cancel_key', 'cancel_api_key' ) : array( 'api_key', 'api_secret' );
				foreach ( $text_fields as $field ) {
					register_setting( $group, "{$prefix}_{$field}", array( 'sanitize_callback' => 'sanitize_text_field' ) );
				}
				foreach ( $secret_fields as $field ) {
					register_setting( $group, "{$prefix}_{$field}", array( 'sanitize_callback' => 'lwc_encrypt_secret' ) );
				}
			}
			if ( 'express' === $jt_provider ) {
				register_setting( $group, 'lwc_jt_express_enabled', array( 'default' => 'no', 'sanitize_callback' => array( $this, 'sanitize_yes_no_option' ) ) );
			}

			add_settings_section(
				$section,
				sprintf(
					/* translators: %s: provider name */
					__( '%s Settings', 'lovecatz-wc' ),
					'express' === $jt_provider ? __( 'J&T Express', 'lovecatz-wc' ) : __( 'J&T Cargo', 'lovecatz-wc' )
				),
				array( $this, 'render_jt_section_intro' ),
				$group
			);

			add_settings_field( "lwc_jt_{$jt_provider}_environment", __( 'Active API Environment', 'lovecatz-wc' ), array( $this, 'render_jt_environment_field' ), $group, $section, array( 'provider' => $jt_provider ) );
			if ( 'express' === $jt_provider ) {
				add_settings_field( 'lwc_jt_express_enabled', __( 'Enable J&T Express', 'lovecatz-wc' ), array( $this, 'render_jt_express_enabled_field' ), $group, $section );
			}
			foreach ( array( 'sandbox' => __( 'Sandbox Credentials', 'lovecatz-wc' ), 'production' => __( 'Production Credentials', 'lovecatz-wc' ) ) as $jt_environment => $label ) {
				add_settings_field( "lwc_jt_{$jt_provider}_{$jt_environment}_credentials", $label, array( $this, 'render_jt_credentials_field' ), $group, $section, array( 'provider' => $jt_provider, 'environment' => $jt_environment ) );
			}
		}

		register_setting(
			'lwc_shipping_fedex_options',
			'lwc_fedex_enabled',
			array(
				'default'           => 'yes',
				'sanitize_callback' => function ( $value ) {
					return ( true === $value || 'yes' === $value || '1' === $value || 1 === $value ) ? 'yes' : 'no';
				},
			)
		);
		register_setting(
			'lwc_shipping_fedex_options',
			'lwc_fedex_max_package_weight_kg',
			array(
				'default'           => 10,
				'sanitize_callback' => function ( $value ) {
					$value = (float) str_replace( ',', '.', (string) $value );
					// FedEx rejects packages above 68 kg on every parcel service.
					return max( 0, min( 68, $value ) );
				},
			)
		);
		register_setting(
			'lwc_shipping_fedex_options',
			'lwc_fedex_services',
			array(
				'default'           => array(),
				'sanitize_callback' => function ( $value ) {
					if ( ! is_array( $value ) ) {
						$value = array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );
					}

					$clean = array();
					foreach ( $value as $service ) {
						$service = strtoupper( sanitize_text_field( (string) $service ) );
						if ( '' !== $service ) {
							$clean[] = $service;
						}
					}

					return array_values( array_unique( $clean ) );
				},
			)
		);
		register_setting( 'lwc_shipping_fedex_options', 'lwc_fedex_environment', array( 'sanitize_callback' => array( $this, 'sanitize_fedex_environment' ) ) );
		foreach ( array( 'sandbox', 'production' ) as $fedex_environment ) {
			register_setting( 'lwc_shipping_fedex_options', "lwc_fedex_{$fedex_environment}_account_number", array( 'sanitize_callback' => 'sanitize_text_field' ) );
			register_setting( 'lwc_shipping_fedex_options', "lwc_fedex_{$fedex_environment}_api_key", array( 'sanitize_callback' => 'lwc_encrypt_secret' ) );
			register_setting( 'lwc_shipping_fedex_options', "lwc_fedex_{$fedex_environment}_api_secret", array( 'sanitize_callback' => 'lwc_encrypt_secret' ) );
		}
		register_setting( 'lwc_shipping_fedex_options', 'lwc_fedex_shipper_name', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'lwc_shipping_fedex_options', 'lwc_fedex_shipper_phone', array( 'sanitize_callback' => 'sanitize_text_field' ) );

		add_settings_section(
			'lwc_shipping_fedex_section',
			__( 'FedEx Settings', 'lovecatz-wc' ),
			array( $this, 'render_fedex_section_intro' ),
			'lwc_shipping_fedex_options'
		);

		add_settings_field(
			'lwc_fedex_enabled',
			__( 'Enable FedEx', 'lovecatz-wc' ),
			array( $this, 'render_fedex_enabled_field' ),
			'lwc_shipping_fedex_options',
			'lwc_shipping_fedex_section'
		);

		add_settings_field(
			'lwc_fedex_max_package_weight_kg',
			__( 'Max package weight (kg)', 'lovecatz-wc' ),
			array( $this, 'render_fedex_max_weight_field' ),
			'lwc_shipping_fedex_options',
			'lwc_shipping_fedex_section'
		);

		add_settings_field(
			'lwc_fedex_services',
			__( 'FedEx service types', 'lovecatz-wc' ),
			array( $this, 'render_fedex_services_field' ),
			'lwc_shipping_fedex_options',
			'lwc_shipping_fedex_section'
		);

		add_settings_field(
			'lwc_fedex_environment',
			__( 'Active API Environment', 'lovecatz-wc' ),
			array( $this, 'render_fedex_environment_field' ),
			'lwc_shipping_fedex_options',
			'lwc_shipping_fedex_section'
		);

		foreach ( array( 'sandbox' => __( 'Sandbox Credentials', 'lovecatz-wc' ), 'production' => __( 'Production Credentials', 'lovecatz-wc' ) ) as $environment => $label ) {
			add_settings_field( "lwc_fedex_{$environment}_credentials", $label, array( $this, 'render_fedex_credentials_field' ), 'lwc_shipping_fedex_options', 'lwc_shipping_fedex_section', array( 'environment' => $environment ) );
		}

		add_settings_field(
			'lwc_fedex_shipper_name',
			__( 'Shipper Name', 'lovecatz-wc' ),
			array( $this, 'render_fedex_shipper_name_field' ),
			'lwc_shipping_fedex_options',
			'lwc_shipping_fedex_section'
		);

		add_settings_field(
			'lwc_fedex_shipper_phone',
			__( 'Shipper Phone', 'lovecatz-wc' ),
			array( $this, 'render_fedex_shipper_phone_field' ),
			'lwc_shipping_fedex_options',
			'lwc_shipping_fedex_section'
		);

		// RaySpeed RETAIL Sandbox settings. The development API exposes a
		// single key rather than OAuth client credentials.
		register_setting( 'lwc_shipping_rayspeed_options', 'lwc_rayspeed_enabled', array( 'default' => 'no', 'sanitize_callback' => array( $this, 'sanitize_yes_no_option' ) ) );
		register_setting( 'lwc_shipping_rayspeed_options', 'lwc_rayspeed_api_key', array( 'sanitize_callback' => 'lwc_encrypt_secret' ) );
		register_setting( 'lwc_shipping_rayspeed_options', 'lwc_rayspeed_origin', array( 'default' => 'JKT', 'sanitize_callback' => array( $this, 'sanitize_rayspeed_origin' ) ) );
		register_setting( 'lwc_shipping_rayspeed_options', 'lwc_rayspeed_category', array( 'default' => 'General Package', 'sanitize_callback' => array( $this, 'sanitize_rayspeed_category' ) ) );
		register_setting( 'lwc_shipping_rayspeed_options', 'lwc_rayspeed_type', array( 'default' => 'NOC', 'sanitize_callback' => array( $this, 'sanitize_rayspeed_type' ) ) );
		register_setting( 'lwc_shipping_rayspeed_options', 'lwc_rayspeed_shipment_type', array( 'default' => 'Regular', 'sanitize_callback' => array( $this, 'sanitize_rayspeed_shipment_type' ) ) );
		register_setting( 'lwc_shipping_rayspeed_options', 'lwc_rayspeed_insurance', array( 'default' => 'No', 'sanitize_callback' => array( $this, 'sanitize_rayspeed_insurance' ) ) );
		register_setting( 'lwc_shipping_rayspeed_options', 'lwc_rayspeed_tax_duty', array( 'default' => 'Receiver', 'sanitize_callback' => array( $this, 'sanitize_rayspeed_tax_duty' ) ) );
		add_settings_section( 'lwc_shipping_rayspeed_section', __( 'RaySpeed Settings', 'lovecatz-wc' ), array( $this, 'render_rayspeed_section_intro' ), 'lwc_shipping_rayspeed_options' );
		add_settings_field( 'lwc_rayspeed_enabled', __( 'Enable RaySpeed', 'lovecatz-wc' ), array( $this, 'render_rayspeed_enabled_field' ), 'lwc_shipping_rayspeed_options', 'lwc_shipping_rayspeed_section' );
		add_settings_field( 'lwc_rayspeed_api_key', __( 'Development API Key', 'lovecatz-wc' ), array( $this, 'render_rayspeed_api_key_field' ), 'lwc_shipping_rayspeed_options', 'lwc_shipping_rayspeed_section' );
		add_settings_field( 'lwc_rayspeed_origin', __( 'Pricing Origin', 'lovecatz-wc' ), array( $this, 'render_rayspeed_origin_field' ), 'lwc_shipping_rayspeed_options', 'lwc_shipping_rayspeed_section' );
		add_settings_field( 'lwc_rayspeed_category', __( 'Package Category', 'lovecatz-wc' ), array( $this, 'render_rayspeed_category_field' ), 'lwc_shipping_rayspeed_options', 'lwc_shipping_rayspeed_section' );
		add_settings_field( 'lwc_rayspeed_type', __( 'Shipment Contents', 'lovecatz-wc' ), array( $this, 'render_rayspeed_type_field' ), 'lwc_shipping_rayspeed_options', 'lwc_shipping_rayspeed_section' );
		add_settings_field( 'lwc_rayspeed_shipment_type', __( 'Shipment Type', 'lovecatz-wc' ), array( $this, 'render_rayspeed_shipment_type_field' ), 'lwc_shipping_rayspeed_options', 'lwc_shipping_rayspeed_section' );
		add_settings_field( 'lwc_rayspeed_insurance', __( 'Insurance', 'lovecatz-wc' ), array( $this, 'render_rayspeed_insurance_field' ), 'lwc_shipping_rayspeed_options', 'lwc_shipping_rayspeed_section' );
		add_settings_field( 'lwc_rayspeed_tax_duty', __( 'Destination Tax & Duty', 'lovecatz-wc' ), array( $this, 'render_rayspeed_tax_duty_field' ), 'lwc_shipping_rayspeed_options', 'lwc_shipping_rayspeed_section' );

		// General Settings for admin menu customizations.
		register_setting( 'lwc_general_options', 'lwc_menu_title', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'lwc_general_options', 'lwc_menu_icon', array( 'sanitize_callback' => 'sanitize_text_field' ) );

		add_settings_section(
			'lwc_general_section_menu',
			__( 'Admin Menu Appearance', 'lovecatz-wc' ),
			array( $this, 'render_menu_section_intro' ),
			'lwc_general_options'
		);

		add_settings_field(
			'lwc_menu_title',
			__( 'Sidebar Name', 'lovecatz-wc' ),
			array( $this, 'render_menu_title_field' ),
			'lwc_general_options',
			'lwc_general_section_menu'
		);

		add_settings_field(
			'lwc_menu_icon',
			__( 'Sidebar Icon', 'lovecatz-wc' ),
			array( $this, 'render_menu_icon_field' ),
			'lwc_general_options',
			'lwc_general_section_menu'
		);

		register_setting(
			'lwc_products_options',
			'lwc_enable_product_quantity_limits',
			array( 'sanitize_callback' => array( $this, 'sanitize_yes_no_option' ) )
		);

		add_settings_section(
			'lwc_products_section_quantity_limits',
			__( 'Product Quantity Limits', 'lovecatz-wc' ),
			array( $this, 'render_products_section_intro' ),
			'lwc_products_options'
		);

		add_settings_field(
			'lwc_enable_product_quantity_limits',
			__( 'Enable quantity limits per product', 'lovecatz-wc' ),
			array( $this, 'render_product_quantity_limits_enabled_field' ),
			'lwc_products_options',
			'lwc_products_section_quantity_limits'
		);
	}

	/** Render provider/environment connection indicators for J&T. */
	public function render_jt_section_intro( $section = array() ) {
		$section_id = isset( $section['id'] ) ? (string) $section['id'] : '';
		$provider   = false !== strpos( $section_id, 'cargo' ) ? 'cargo' : 'express';
		if ( 'express' === $provider ) {
			echo '<p>' . esc_html__( 'Enter your J&T Express API credentials below. Tariff authentication is checked automatically without creating a test order.', 'lovecatz-wc' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'J&T Cargo is isolated from J&T Express. Its credentials cannot be authenticated until the Cargo API contract and endpoint are supplied.', 'lovecatz-wc' ) . '</p>';
		}

		echo '<div class="lwc-provider-status-list lwc-jt-connection-status" data-provider="' . esc_attr( $provider ) . '" aria-live="polite">';
		foreach ( array( 'sandbox' => __( 'Sandbox', 'lovecatz-wc' ), 'production' => __( 'Production', 'lovecatz-wc' ) ) as $environment => $label ) {
			echo '<div class="lwc-provider-status" data-environment="' . esc_attr( $environment ) . '" data-status="checking"><span class="lwc-provider-status-dot"></span><strong>' . esc_html( $label ) . ':</strong> <span class="lwc-provider-status-label">' . esc_html__( 'Checking saved credentials…', 'lovecatz-wc' ) . '</span></div>';
		}
		echo '</div>';
	}

	/**
	 * Render introductory text for the FedEx section.
	 */
	public function render_fedex_section_intro() {
		echo '<p>' . esc_html__( 'Save Sandbox and Production credentials separately, then select the environment to use. FedEx will appear for international shipments outside Indonesia.', 'lovecatz-wc' ) . '</p>';

		$origin_fields = array(
			get_option( 'woocommerce_store_address', '' ),
			get_option( 'woocommerce_store_city', '' ),
			get_option( 'woocommerce_store_postcode', '' ),
		);
		if ( in_array( '', array_map( 'trim', $origin_fields ), true ) ) {
			printf(
				'<div class="notice notice-error inline"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'FedEx cannot calculate live rates because the WooCommerce store address, city, or postal code is incomplete.', 'lovecatz-wc' ),
				esc_url( admin_url( 'admin.php?page=wc-settings' ) ),
				esc_html__( 'Complete the store address', 'lovecatz-wc' )
			);
		}

		$legacy_environment = 'yes' === get_option( 'lwc_fedex_test_mode', 'no' ) ? 'sandbox' : 'production';
		$legacy_status      = get_option( 'lwc_fedex_validation_status', 'pending' );
		$statuses           = array();
		foreach ( array( 'sandbox', 'production' ) as $environment ) {
			$fallback_status          = $environment === $legacy_environment ? $legacy_status : 'pending';
			$statuses[ $environment ] = get_option( 'lwc_fedex_validation_status_' . $environment, $fallback_status );
		}

		if ( 'validated' === $statuses['sandbox'] && 'validated' === $statuses['production'] ) {
			$current = array( 'connected', __( 'Sandbox & Production connected (REST API ready)', 'lovecatz-wc' ) );
		} elseif ( 'failed' === $statuses['sandbox'] || 'failed' === $statuses['production'] ) {
			$failed  = array();
			$failed[] = 'failed' === $statuses['sandbox'] ? __( 'Sandbox', 'lovecatz-wc' ) : '';
			$failed[] = 'failed' === $statuses['production'] ? __( 'Production', 'lovecatz-wc' ) : '';
			$failed   = array_filter( $failed );
			$current  = array( 'auth_failed', sprintf( __( '%s connection failed. Both environments must be connected.', 'lovecatz-wc' ), implode( ' & ', $failed ) ) );
		} else {
			$current = array( 'partial', __( 'Sandbox or Production credentials are incomplete.', 'lovecatz-wc' ) );
		}
		?>
		<div id="lwc-fedex-connection-status" class="lwc-fedex-connection-status" data-status="<?php echo esc_attr( $current[0] ); ?>">
			<span class="lwc-fedex-status-dot"></span>
			<span class="lwc-fedex-status-label"><?php echo esc_html( $current[1] ); ?></span>
		</div>
		<?php
	}

	/**
	 * Render the FedEx enable toggle.
	 */
	public function render_fedex_enabled_field() {
		$value = get_option( 'lwc_fedex_enabled', 'yes' );
		$checked = 'yes' === $value ? 'checked' : '';
		echo '<input type="hidden" name="lwc_fedex_enabled" value="no" />';
		echo '<input type="checkbox" name="lwc_fedex_enabled" value="yes" ' . $checked . ' /> ';
		echo esc_html__( 'Offer FedEx rates at checkout for destinations outside Indonesia — no WooCommerce shipping zone setup needed.', 'lovecatz-wc' );
	}

	/**
	 * Render the FedEx max package weight field.
	 */
	public function render_fedex_max_weight_field() {
		$value = get_option( 'lwc_fedex_max_package_weight_kg', 10 );
		echo '<input type="number" name="lwc_fedex_max_package_weight_kg" value="' . esc_attr( $value ) . '" class="small-text" min="0" max="68" step="0.5" />';
		echo '<p class="description">' . esc_html__( 'Carts heavier than this are split into multiple packages for rating and labels. Capped at the FedEx limit of 68 kg. Set 0 to always quote one package.', 'lovecatz-wc' ) . '</p>';
	}

	/**
	 * Render the FedEx service types multiselect.
	 */
	public function render_fedex_services_field() {
		if ( ! class_exists( 'LWC_FedEx_API' ) && file_exists( LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-fedex-api.php' ) ) {
			require_once LWC_PLUGIN_DIR . 'shipping/fedex/class-lwc-fedex-api.php';
		}

		$selected = (array) get_option( 'lwc_fedex_services', array() );
		$selected = array_map( 'strtoupper', array_map( 'strval', $selected ) );
		$legacy_aliases = array(
			'FEDEX_STANDARD_OVERNIGHT'       => 'STANDARD_OVERNIGHT',
			'FEDEX_PRIORITY_OVERNIGHT'       => 'PRIORITY_OVERNIGHT',
			'FEDEX_FIRST_OVERNIGHT'          => 'FIRST_OVERNIGHT',
			'INTERNATIONAL_PRIORITY'         => 'FEDEX_INTERNATIONAL_PRIORITY',
			'INTERNATIONAL_PRIORITY_EXPRESS' => 'FEDEX_INTERNATIONAL_PRIORITY_EXPRESS',
			'INTERNATIONAL_CONNECT_PLUS'     => 'FEDEX_INTERNATIONAL_CONNECT_PLUS',
		);
		$selected = array_map(
			function ( $service ) use ( $legacy_aliases ) {
				return isset( $legacy_aliases[ $service ] ) ? $legacy_aliases[ $service ] : $service;
			},
			$selected
		);
		$options  = class_exists( 'LWC_FedEx_API' ) ? LWC_FedEx_API::get_available_services() : array();

		echo '<select name="lwc_fedex_services[]" multiple size="8" style="min-width:320px;">';
		foreach ( $options as $service_type => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s (%1$s)</option>',
				esc_attr( $service_type ),
				in_array( $service_type, $selected, true ) ? ' selected' : '',
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Every selected service that FedEx prices becomes its own checkout option. Leave empty to use the defaults (Ground, Express Saver, International Economy, International Priority).', 'lovecatz-wc' ) . '</p>';
	}

	/**
	 * Render the active FedEx API environment selector.
	 */
	public function render_fedex_environment_field() {
		$value = $this->get_fedex_environment();
		echo '<select id="lwc_fedex_environment" name="lwc_fedex_environment" class="lwc-fedex-credential-field">';
		echo '<option value="sandbox"' . selected( $value, 'sandbox', false ) . '>' . esc_html__( 'Sandbox (testing)', 'lovecatz-wc' ) . '</option>';
		echo '<option value="production"' . selected( $value, 'production', false ) . '>' . esc_html__( 'Production (live)', 'lovecatz-wc' ) . '</option></select>';
		echo '<p class="description">' . esc_html__( 'All rates, labels, and shipment requests use the selected account.', 'lovecatz-wc' ) . '</p>';
	}

	/** Render the active API environment for one J&T provider. */
	public function render_jt_environment_field( $args = array() ) {
		$provider = isset( $args['provider'] ) && 'cargo' === $args['provider'] ? 'cargo' : 'express';
		$value    = $this->get_jt_environment( $provider );
		$name     = "lwc_jt_{$provider}_environment";
		echo '<select name="' . esc_attr( $name ) . '">';
		echo '<option value="sandbox"' . selected( $value, 'sandbox', false ) . '>' . esc_html__( 'Sandbox (testing)', 'lovecatz-wc' ) . '</option>';
		echo '<option value="production"' . selected( $value, 'production', false ) . '>' . esc_html__( 'Production (live)', 'lovecatz-wc' ) . '</option>';
		echo '</select>';
	}

	/** Render a complete, environment-specific J&T credential set. */
	public function render_jt_credentials_field( $args = array() ) {
		$provider    = isset( $args['provider'] ) && 'cargo' === $args['provider'] ? 'cargo' : 'express';
		$environment = isset( $args['environment'] ) && 'production' === $args['environment'] ? 'production' : 'sandbox';
		$prefix      = "lwc_jt_{$provider}_{$environment}";
		$fields = 'express' === $provider ? array(
			'order_username'       => array( __( 'Order Username', 'lovecatz-wc' ), 'text' ),
			'order_api_key'        => array( __( 'Order API Key', 'lovecatz-wc' ), 'password' ),
			'order_key'            => array( __( 'Order Signing Key', 'lovecatz-wc' ), 'password' ),
			'tariff_customer_name' => array( __( 'Tariff Customer Name', 'lovecatz-wc' ), 'text' ),
			'tariff_check_key'     => array( __( 'Tariff Check Key', 'lovecatz-wc' ), 'password' ),
			'tracking_password'    => array( __( 'Tracking Authorization Password', 'lovecatz-wc' ), 'password' ),
			'tracking_company_id'  => array( __( 'Tracking Authorization Username / E-company ID', 'lovecatz-wc' ), 'text' ),
			'cancel_username'      => array( __( 'Cancellation Username', 'lovecatz-wc' ), 'text' ),
			'cancel_api_key'       => array( __( 'Cancellation API Key', 'lovecatz-wc' ), 'password' ),
			'cancel_key'           => array( __( 'Cancellation Signing Key', 'lovecatz-wc' ), 'password' ),
		) : array(
			'username'   => array( __( 'Username / Account ID', 'lovecatz-wc' ), 'text' ),
			'api_key'    => array( __( 'API Key', 'lovecatz-wc' ), 'password' ),
			'api_secret' => array( __( 'API Secret', 'lovecatz-wc' ), 'password' ),
		);

		echo '<div class="lwc-jt-credential-group" data-provider="' . esc_attr( $provider ) . '" data-environment="' . esc_attr( $environment ) . '">';
		foreach ( $fields as $field => $definition ) {
			$name  = "{$prefix}_{$field}";
			$value = get_option( $name, '' );
			echo '<p><label>' . esc_html( $definition[0] ) . '<br><input type="' . esc_attr( $definition[1] ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text lwc-jt-credential-field" data-credential="' . esc_attr( $field ) . '" autocomplete="off"></label></p>';
		}
		echo '<p class="description">' . esc_html__( 'API URLs are selected automatically in the backend according to the active environment and are not stored with credentials.', 'lovecatz-wc' ) . '</p></div>';
	}

	public function render_jt_express_enabled_field() {
		echo '<input type="hidden" name="lwc_jt_express_enabled" value="no">';
		echo '<label><input type="checkbox" name="lwc_jt_express_enabled" value="yes" ' . checked( get_option( 'lwc_jt_express_enabled', 'no' ), 'yes', false ) . '> ' . esc_html__( 'Offer J&T Express at checkout for Indonesian destinations without requiring a WooCommerce Shipping Zone.', 'lovecatz-wc' ) . '</label>';
	}

	/** Normalize a J&T environment value. */
	public function sanitize_jt_environment( $value ) {
		return 'production' === sanitize_key( $value ) ? 'production' : 'sandbox';
	}

	/** Resolve the current environment, including the legacy test-mode fallback. */
	private function get_jt_environment( $provider ) {
		$legacy = 'yes' === get_option( "lwc_jt_{$provider}_test_mode", 'no' ) ? 'sandbox' : 'production';
		return 'production' === get_option( "lwc_jt_{$provider}_environment", $legacy ) ? 'production' : 'sandbox';
	}

	public function render_fedex_credentials_field( $args = array() ) {
		$environment = isset( $args['environment'] ) && 'production' === $args['environment'] ? 'production' : 'sandbox';
		$legacy_mode = 'yes' === get_option( 'lwc_fedex_test_mode', 'no' ) ? 'sandbox' : 'production';
		$fallback     = $environment === $legacy_mode;
		$account      = get_option( "lwc_fedex_{$environment}_account_number", $fallback ? get_option( 'lwc_fedex_account_number', '' ) : '' );
		$api_key      = get_option( "lwc_fedex_{$environment}_api_key", $fallback ? get_option( 'lwc_fedex_api_key', '' ) : '' );
		$api_secret   = get_option( "lwc_fedex_{$environment}_api_secret", $fallback ? get_option( 'lwc_fedex_api_secret', '' ) : '' );
		printf( '<div class="lwc-fedex-credential-group" data-environment="%1$s"><p><label>%2$s<br><input type="text" name="lwc_fedex_%1$s_account_number" value="%3$s" class="regular-text lwc-fedex-credential-field" data-credential="account_number" autocomplete="off"></label></p><p><label>%4$s<br><input type="text" name="lwc_fedex_%1$s_api_key" value="%5$s" class="regular-text lwc-fedex-credential-field" data-credential="api_key" autocomplete="off"></label></p><p><label>%6$s<br><input type="password" name="lwc_fedex_%1$s_api_secret" value="%7$s" class="regular-text lwc-fedex-credential-field" data-credential="api_secret" autocomplete="new-password"></label></p></div>', esc_attr( $environment ), esc_html__( 'Account Number', 'lovecatz-wc' ), esc_attr( $account ), esc_html__( 'API Key', 'lovecatz-wc' ), esc_attr( $api_key ), esc_html__( 'API Secret', 'lovecatz-wc' ), esc_attr( $api_secret ) );
	}

	public function sanitize_fedex_environment( $value ) {
		return 'production' === sanitize_key( $value ) ? 'production' : 'sandbox';
	}

	private function get_fedex_environment() {
		$legacy_default = 'yes' === get_option( 'lwc_fedex_test_mode', 'no' ) ? 'sandbox' : 'production';
		return 'production' === get_option( 'lwc_fedex_environment', $legacy_default ) ? 'production' : 'sandbox';
	}

	/**
	 * Render the FedEx shipper name field.
	 */
	public function render_fedex_shipper_name_field() {
		$value = get_option( 'lwc_fedex_shipper_name', get_bloginfo( 'name' ) );
		echo '<input type="text" name="lwc_fedex_shipper_name" value="' . esc_attr( $value ) . '" class="regular-text" />';
		echo '<p class="description">' . esc_html__( 'Contact name printed on FedEx shipping labels.', 'lovecatz-wc' ) . '</p>';
	}

	/**
	 * Render the FedEx shipper phone field.
	 */
	public function render_fedex_shipper_phone_field() {
		$value = get_option( 'lwc_fedex_shipper_phone', '' );
		echo '<input type="text" name="lwc_fedex_shipper_phone" value="' . esc_attr( $value ) . '" class="regular-text" />';
		echo '<p class="description">' . esc_html__( 'Required by FedEx when creating shipments and printing labels.', 'lovecatz-wc' ) . '</p>';
	}

	public function render_rayspeed_section_intro() {
		echo '<p>' . esc_html__( 'RaySpeed RETAIL development integration for pricing, test AWB creation, and tracking. Production endpoints and credentials must be supplied by RaySpeed before going live.', 'lovecatz-wc' ) . '</p>';
		echo '<div class="lwc-provider-status-list"><div id="lwc-rayspeed-connection-status" class="lwc-provider-status" data-status="checking" aria-live="polite"><span class="lwc-provider-status-dot"></span><strong>' . esc_html__( 'Sandbox:', 'lovecatz-wc' ) . '</strong> <span class="lwc-provider-status-label">' . esc_html__( 'Checking saved credentials…', 'lovecatz-wc' ) . '</span></div></div>';
	}

	public function render_rayspeed_enabled_field() {
		echo '<label><input type="checkbox" name="lwc_rayspeed_enabled" value="yes" ' . checked( get_option( 'lwc_rayspeed_enabled', 'no' ), 'yes', false ) . '> ' . esc_html__( 'Offer RaySpeed rates for destinations outside Indonesia', 'lovecatz-wc' ) . '</label>';
	}

	public function render_rayspeed_api_key_field() {
		echo '<input id="lwc_rayspeed_api_key" type="password" name="lwc_rayspeed_api_key" value="' . esc_attr( get_option( 'lwc_rayspeed_api_key', '' ) ) . '" class="regular-text lwc-rayspeed-credential-field" autocomplete="new-password">';
	}

	public function render_rayspeed_origin_field() {
		$this->render_select( 'lwc_rayspeed_origin', array( 'JKT' => 'Jakarta (JKT)', 'SBY' => 'Surabaya (SBY)', 'MES' => 'Medan (MES)' ), 'JKT' );
	}

	public function render_rayspeed_category_field() {
		$this->render_select( 'lwc_rayspeed_category', array( 'General Package' => __( 'General Package', 'lovecatz-wc' ), 'Food' => __( 'Food', 'lovecatz-wc' ) ), 'General Package' );
	}

	public function render_rayspeed_type_field() {
		$this->render_select( 'lwc_rayspeed_type', array( 'NOC' => __( 'Non-document (NOC)', 'lovecatz-wc' ), 'DOC' => __( 'Document (DOC)', 'lovecatz-wc' ) ), 'NOC' );
	}

	public function render_rayspeed_shipment_type_field() {
		$this->render_select( 'lwc_rayspeed_shipment_type', array( 'Regular' => __( 'Regular', 'lovecatz-wc' ), 'Express' => __( 'Express', 'lovecatz-wc' ) ), 'Regular' );
	}

	public function render_rayspeed_insurance_field() {
		$this->render_select( 'lwc_rayspeed_insurance', array( 'No' => __( 'No', 'lovecatz-wc' ), 'Yes' => __( 'Yes', 'lovecatz-wc' ) ), 'No' );
	}

	public function render_rayspeed_tax_duty_field() {
		$this->render_select( 'lwc_rayspeed_tax_duty', array( 'Receiver' => __( 'Receiver', 'lovecatz-wc' ), 'Shipper' => __( 'Shipper', 'lovecatz-wc' ) ), 'Receiver' );
	}

	private function render_select( $name, $options, $default ) {
		$current = get_option( $name, $default );
		echo '<select name="' . esc_attr( $name ) . '">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	public function sanitize_rayspeed_origin( $value ) { return in_array( $value, array( 'JKT', 'SBY', 'MES' ), true ) ? $value : 'JKT'; }
	public function sanitize_rayspeed_category( $value ) { return 'Food' === $value ? 'Food' : 'General Package'; }
	public function sanitize_rayspeed_type( $value ) { return 'DOC' === $value ? 'DOC' : 'NOC'; }
	public function sanitize_rayspeed_shipment_type( $value ) { return 'Express' === $value ? 'Express' : 'Regular'; }
	public function sanitize_rayspeed_insurance( $value ) { return 'Yes' === $value ? 'Yes' : 'No'; }
	public function sanitize_rayspeed_tax_duty( $value ) { return 'Shipper' === $value ? 'Shipper' : 'Receiver'; }

	/**
	 * Render introductory text for the general settings section.
	 */
	public function render_menu_section_intro() {
		echo '<p>' . esc_html__( 'Customize the sidebar name and icon shown for the LoveCatz admin menu. Choose a Dashicon from the official WordPress reference below.', 'lovecatz-wc' ) . '</p>';
	}

	/**
	 * Render the menu title field.
	 */
	public function render_menu_title_field() {
		$value = get_option( 'lwc_menu_title', __( 'LoveCatz', 'lovecatz-wc' ) );
		echo '<input type="text" name="lwc_menu_title" value="' . esc_attr( $value ) . '" class="regular-text" />';
	}

	/**
	 * Render the menu icon field.
	 */
	public function render_menu_icon_field() {
		$value = get_option( 'lwc_menu_icon', 'dashicons-pets' );
		echo '<input type="text" id="lwc_menu_icon_class" name="lwc_menu_icon" value="' . esc_attr( $value ) . '" class="regular-text" />';
		echo '<p class="description">' . esc_html__( 'Enter a Dashicons class such as dashicons-admin-home, dashicons-store, or dashicons-heart. See the full list at the official WordPress Dashicons page.', 'lovecatz-wc' ) . ' <a href="https://developer.wordpress.org/resource/dashicons/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View Dashicons', 'lovecatz-wc' ) . '</a></p>';
		echo '<div class="lwc-dashicon-choices">';
		$icons = array(
			'dashicons-admin-home',
			'dashicons-store',
			'dashicons-heart',
			'dashicons-cart',
			'dashicons-groups',
			'dashicons-universal-access',
			'dashicons-star-filled',
			'dashicons-awards',
			'dashicons-admin-site',
			'dashicons-admin-tools',
		);
		foreach ( $icons as $icon ) {
			$selected = $value === $icon ? ' selected' : '';
			echo '<button type="button" class="lwc-dashicon-choice' . $selected . '" data-icon="' . esc_attr( $icon ) . '"><span class="dashicons ' . esc_attr( $icon ) . '"></span><span class="dashicon-label">' . esc_html( str_replace( 'dashicons-', '', $icon ) ) . '</span></button>';
		}
		echo '</div>';
	}

	/**
	 * Render intro text for the Products settings section.
	 */
	public function render_products_section_intro() {
		echo '<p>' . esc_html__( 'Enable this feature to add Minimum Quantity and Maximum Quantity fields to the Inventory tab for each product.', 'lovecatz-wc' ) . '</p>';
	}

	/**
	 * Render the per-product quantity limits toggle.
	 */
	public function render_product_quantity_limits_enabled_field() {
		$enabled = 'yes' === get_option( 'lwc_enable_product_quantity_limits', 'no' );
		echo '<input type="hidden" name="lwc_enable_product_quantity_limits" value="no" />';
		echo '<label><input type="checkbox" name="lwc_enable_product_quantity_limits" value="yes" ' . checked( $enabled, true, false ) . ' /> ' . esc_html__( 'Show and enforce minimum/maximum quantity limits for individual products.', 'lovecatz-wc' ) . '</label>';
	}

	/**
	 * Sanitize a checkbox setting stored as a yes/no value.
	 *
	 * @param string $value Submitted option value.
	 * @return string
	 */
	public function sanitize_yes_no_option( $value ) {
		return 'yes' === $value ? 'yes' : 'no';
	}

	/**
	 * Handle the user import form submission.
	 */
	public function handle_user_import() {
		if ( ! isset( $_POST['lwc_import_users_submit'] ) ) {
			return;
		}

		check_admin_referer( 'lwc_import_users_action', 'lwc_import_users_nonce' );

		if ( ! current_user_can( 'create_users' ) ) {
			$this->last_import_result = array(
				'success' => false,
				'message' => __( 'You do not have permission to import users.', 'lovecatz-wc' ),
			);
			return;
		}

		if ( empty( $_FILES['lwc_import_file']['name'] ) ) {
			$this->last_import_result = array(
				'success' => false,
				'message' => __( 'Please select a file to import.', 'lovecatz-wc' ),
			);
			return;
		}

		$file = wp_unslash( $_FILES['lwc_import_file'] );
		if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$this->last_import_result = array(
				'success' => false,
				'message' => __( 'There was an error uploading the file.', 'lovecatz-wc' ),
			);
			return;
		}

		$extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'csv', 'xlsx', 'xls' ), true ) ) {
			$this->last_import_result = array(
				'success' => false,
				'message' => __( 'Only CSV, .xls and .xlsx files are supported.', 'lovecatz-wc' ),
			);
			return;
		}

		$file_size = filesize( $file['tmp_name'] );
		if ( false === $file_size || $file_size > min( wp_max_upload_size(), 25 * MB_IN_BYTES ) ) {
			$this->last_import_result = array(
				'success' => false,
				'message' => __( 'The import file is too large. The maximum allowed size is 25 MB.', 'lovecatz-wc' ),
			);
			return;
		}

		$rows = array();
		if ( 'csv' === $extension ) {
			$rows = $this->parse_csv_file( $file['tmp_name'] );
		} elseif ( 'xlsx' === $extension ) {
			if ( ! class_exists( 'ZipArchive' ) ) {
				$this->last_import_result = array(
					'success' => false,
					'message' => __( 'The PHP ZIP extension is required to read .xlsx files.', 'lovecatz-wc' ),
				);
				@unlink( $file['tmp_name'] );
				return;
			}
			$rows = $this->parse_xlsx_file( $file['tmp_name'] );
		} else {
			$rows = $this->parse_xls_file( $file['tmp_name'] );
			if ( empty( $rows ) ) {
				$this->last_import_result = array(
					'success' => false,
					'message' => __( 'The uploaded .xls file could not be parsed. Please save it as .xlsx or CSV if the file is not in a supported format.', 'lovecatz-wc' ),
				);
				@unlink( $file['tmp_name'] );
				return;
			}
		}

		if ( empty( $rows ) ) {
			$this->last_import_result = array(
				'success' => false,
				'message' => __( 'No usable rows were found in the uploaded file. Please make sure the file has a header row and at least one data row.', 'lovecatz-wc' ),
			);
			return;
		}

		$this->last_import_result = $this->import_users_from_rows( $rows );
		@unlink( $file['tmp_name'] );
	}

	/**
	 * Render an import result notice.
	 */
	public function render_import_result_notice() {
		if ( empty( $this->last_import_result ) ) {
			return;
		}

		$success = ! empty( $this->last_import_result['success'] );
		$class   = $success ? 'notice notice-success' : 'notice notice-error';
		$message = esc_html( $this->last_import_result['message'] );

		if ( ! $success && empty( $this->last_import_result['message'] ) ) {
			$message = __( 'Import failed.', 'lovecatz-wc' );
		}

		echo '<div class="' . esc_attr( $class ) . '"><p>' . $message . '</p></div>';
	}

	/**
	 * Render registered store members and the member import form.
	 */
	public function render_store_members() {
		$per_page = 25;
		$page     = isset( $_GET['member_page'] ) ? max( 1, absint( $_GET['member_page'] ) ) : 1;
		$query    = new WP_User_Query(
			array(
				'role'    => 'customer',
				'number'  => $per_page,
				'offset'  => ( $page - 1 ) * $per_page,
				'orderby' => 'registered',
				'order'   => 'DESC',
			)
		);
		$members  = $query->get_results();
		$total    = (int) $query->get_total();
		?>
		<h2><?php esc_html_e( 'Members', 'lovecatz-wc' ); ?></h2>
		<p><?php esc_html_e( 'This list displays only users with the Customer role.', 'lovecatz-wc' ); ?></p>
		<table class="wp-list-table widefat fixed striped">
			<thead><tr>
				<th><?php esc_html_e( 'Customer ID', 'lovecatz-wc' ); ?></th>
				<th><?php esc_html_e( 'Name', 'lovecatz-wc' ); ?></th>
				<th><?php esc_html_e( 'Email', 'lovecatz-wc' ); ?></th>
				<th><?php esc_html_e( 'Phone Number', 'lovecatz-wc' ); ?></th>
				<th><?php esc_html_e( 'Member Card', 'lovecatz-wc' ); ?></th>
				<th><?php esc_html_e( 'Settings', 'lovecatz-wc' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( empty( $members ) ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No members found.', 'lovecatz-wc' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $members as $member ) : ?>
					<?php if ( ! $this->is_store_member( $member ) ) { continue; } ?>
					<?php
					$phone     = get_user_meta( $member->ID, 'billing_phone', true );
					$print_url = wp_nonce_url( admin_url( 'admin-post.php?action=lwc_print_member_card&user_id=' . $member->ID ), 'lwc_print_member_card_' . $member->ID );
					$delete_url = wp_nonce_url( admin_url( 'admin-post.php?action=lwc_delete_store_member&user_id=' . $member->ID ), 'lwc_delete_store_member_' . $member->ID );
					?>
					<tr>
						<td><?php echo esc_html( get_user_meta( $member->ID, 'lwc_customer_id', true ) ); ?></td>
						<td><a href="<?php echo esc_url( get_edit_user_link( $member->ID ) ); ?>"><?php echo esc_html( $member->display_name ); ?></a></td>
						<td><?php echo esc_html( $member->user_email ); ?></td>
						<td><?php echo esc_html( $phone ); ?></td>
						<td><a class="button button-secondary" href="<?php echo esc_url( $print_url ); ?>" target="_blank"><?php esc_html_e( 'Print Member Card', 'lovecatz-wc' ); ?></a></td>
						<td><a href="<?php echo esc_url( get_edit_user_link( $member->ID ) ); ?>"><?php esc_html_e( 'Update', 'lovecatz-wc' ); ?></a> | <a href="<?php echo esc_url( $delete_url ); ?>" class="submitdelete" onclick="return confirm('<?php echo esc_js( __( 'Permanently delete this member?', 'lovecatz-wc' ) ); ?>');"><?php esc_html_e( 'Delete', 'lovecatz-wc' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
		$total_pages = (int) ceil( $total / $per_page );
		if ( $total_pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'member_page', '%#%' ),
						'format'  => '',
						'current' => $page,
						'total'   => $total_pages,
					)
				)
			) . '</div></div>';
		}
		?>
		<hr />
		<h2><?php esc_html_e( 'Import Members', 'lovecatz-wc' ); ?></h2>
		<p><a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lwc_download_member_import_template' ), 'lwc_download_member_import_template' ) ); ?>"><?php esc_html_e( 'Download Excel Template', 'lovecatz-wc' ); ?></a></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=lovecatz-wc&tab=store-members' ) ); ?>" enctype="multipart/form-data">
			<?php wp_nonce_field( 'lwc_import_users_action', 'lwc_import_users_nonce' ); ?>
			<p><?php esc_html_e( 'Upload an Excel (.xls/.xlsx) or CSV file. All imported users will receive the Customer role.', 'lovecatz-wc' ); ?></p>
			<input type="file" name="lwc_import_file" accept=".csv,.xls,.xlsx" required />
			<?php submit_button( __( 'Import Members', 'lovecatz-wc' ), 'primary', 'lwc_import_users_submit' ); ?>
		</form>
		<?php
	}

	/**
	 * Determine whether a user is exclusively a WooCommerce customer.
	 *
	 * @param WP_User|int $user User object or ID.
	 * @return bool
	 */
	private function is_store_member( $user ) {
		if ( is_numeric( $user ) ) {
			$user = get_userdata( (int) $user );
		}

		return $user instanceof WP_User && array( 'customer' ) === array_values( $user->roles );
	}

	/**
	 * Render a print-ready member card in a new browser tab.
	 */
	public function print_member_card() {
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		check_admin_referer( 'lwc_print_member_card_' . $user_id );

		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_user', $user_id ) || ! $this->is_store_member( $user_id ) ) {
			wp_die( esc_html__( 'You do not have permission to print this member card.', 'lovecatz-wc' ) );
		}

		$this->render_printable_member_card( $user_id );
	}

	/**
	 * Let a customer print only their own member card from My Account.
	 */
	public function print_own_member_card() {
		check_admin_referer( 'lwc_print_own_member_card' );
		$user_id = get_current_user_id();

		if ( ! $this->is_store_member( $user_id ) ) {
			wp_die( esc_html__( 'You do not have permission to print this member card.', 'lovecatz-wc' ) );
		}

		$this->render_printable_member_card( $user_id );
	}

	/**
	 * Display a self-service print button on the customer account dashboard.
	 */
	public function render_customer_member_card_button() {
		$user_id = get_current_user_id();
		if ( ! $this->is_store_member( $user_id ) ) {
			return;
		}

		$url = wp_nonce_url( admin_url( 'admin-post.php?action=lwc_print_own_member_card' ), 'lwc_print_own_member_card' );
		echo '<p><a class="button" href="' . esc_url( $url ) . '" target="_blank">' . esc_html__( 'Print Membership Card', 'lovecatz-wc' ) . '</a></p>';
	}

	/**
	 * Render a print-ready member card in a new browser tab.
	 *
	 * @param int $user_id Member user ID.
	 */
	private function render_printable_member_card( $user_id ) {

		$member      = get_userdata( $user_id );
		$customer_id = get_user_meta( $user_id, 'lwc_customer_id', true );
		$phone       = get_user_meta( $user_id, 'billing_phone', true );
		$logo_url    = LWC_PLUGIN_URL . 'assets/logo.webp';
		$product_url = LWC_PLUGIN_URL . 'assets/product.png';
		$my_account_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '';
		if ( ! $my_account_url ) {
			$my_account_url = home_url( '/my-account/' );
		}
		$qr_url = $this->get_qr_code_url( $my_account_url );
		?>
		<!DOCTYPE html>
		<html lang="en">
		<head>
		<meta charset="<?php bloginfo( 'charset' ); ?>">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<title><?php esc_html_e( 'Member Card', 'lovecatz-wc' ); ?></title>
		<style>
		@import url('https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Manrope:wght@400;500;600;700&display=swap');
		*{margin:0;padding:0;box-sizing:border-box;}
		body{background:#111;display:flex;justify-content:center;align-items:center;min-height:100vh;font-family:'Manrope',sans-serif;color:#1d5d63;}
		.card{width:1120px;height:740px;background:linear-gradient(135deg,#fbfaf6,#f5eddc,#fffaf1);border-radius:36px;overflow:hidden;position:relative;padding:40px;box-shadow:0 20px 45px rgba(0,0,0,.18);}
		.card::after{content:"";position:absolute;top:0;right:0;width:48%;height:48%;background:radial-gradient(circle at top right, rgba(0,88,104,.14), transparent 50%);opacity:.18;}
		.header{display:flex;justify-content:space-between;align-items:center;gap:20px;}
		.logo{width:240px;height:72px;background:transparent;border-radius:22px;display:flex;align-items:center;justify-content:flex-start;padding-left:12px;}
		.logo img{max-width:100%;max-height:100%;object-fit:contain;}
		.club{font-family:'Cormorant Garamond',serif;font-size:34px;color:#005868;font-weight:700;letter-spacing:1px;line-height:1.1;}
		.content{display:flex;margin-top:30px;height:590px;gap:30px;}
		.left{flex:0.6;display:flex;flex-direction:column;justify-content:space-between;}
		.name{color:#005868;font-family:'Cormorant Garamond',serif;font-size:62px;font-weight:700;line-height:1.05;max-width:100%;}
		.member{width:100%;max-width:560px;height:72px;border:3px solid #c9a45b;border-radius:36px;display:flex;align-items:center;padding-left:30px;font-size:34px;color:#295e65;font-family:'Manrope',sans-serif;}
		.member-title{margin-top:18px;font-family:'Cormorant Garamond',serif;font-size:28px;color:#1d5d63;font-weight:500;}
		.bottom{display:flex;align-items:flex-start;gap:24px;}
		.qr{width:190px;height:190px;background:#fff;border-radius:28px;display:flex;align-items:center;justify-content:center;overflow:hidden;box-shadow:0 12px 28px rgba(0,0,0,.08);}
		.qr img{width:100%;height:100%;object-fit:cover;display:block;}
		.gift{color:#005868;font-family:'Manrope',sans-serif;max-width:320px;}
		.gift h2{font-family:'Cormorant Garamond',serif;font-size:30px;font-weight:700;margin-bottom:10px;}
		.gift p{font-size:20px;line-height:1.35;margin:0;}
		.icon{font-size:60px;color:#c9a45b;margin-left:10px;}
		.right{flex:0.4;display:flex;justify-content:center;align-items:center;}
		.product{width:100%;height:100%;background:rgba(0,88,104,.08);border-radius:32px;display:flex;align-items:center;justify-content:center;overflow:hidden;}
		.product img{width:100%;height:100%;object-fit:cover;display:block;}
		@media(max-width:900px){.card{width:95%;height:auto;padding:24px;}.content{flex-direction:column;}.member{width:100%;}.right{width:100%;justify-content:center;margin-top:30px;}.product{width:100%;height:320px;}.name{font-size:42px;}}
		@media print { body {background:#fff;} .card { box-shadow:none; margin:0; width:auto; height:auto; } }
		</style>
		</head>
		<body>
		<div class="card">
			<div class="header">
				<div class="logo"><img src="<?php echo esc_url( $logo_url ); ?>" alt="DD Distillers" /></div>
				<div class="club"><?php esc_html_e( 'MEMBERSHIP CLUB', 'lovecatz-wc' ); ?></div>
			</div>
			<div class="content">
				<div class="left">
					<div>
						<div class="name"><?php echo esc_html( $member->display_name ); ?></div>
						<div class="member"><?php echo esc_html( $customer_id ); ?></div>
						<div class="member-title"><?php esc_html_e( 'ID PELANGGAN (Nomor Anggota)', 'lovecatz-wc' ); ?></div>
					</div>
					<div class="bottom">
						<div class="qr"><img src="<?php echo esc_url( $qr_url ); ?>" alt="QR Code" /></div>
						<div class="gift">
							<h2><?php esc_html_e( 'DAPATKAN GIFT DI:', 'lovecatz-wc' ); ?></h2>
							<p><?php esc_html_e( 'www.ddistillers.com', 'lovecatz-wc' ); ?></p>
						</div>
						<div class="icon">🎁</div>
					</div>
				</div>
				<div class="right">
					<div class="product"><img src="<?php echo esc_url( $product_url ); ?>" alt="Product" /></div>
				</div>
			</div>
		</div>
		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * Get a QR code image URL for the supplied data.
	 *
	 * @param string $data QR payload.
	 * @return string
	 */
	private function get_qr_code_url( $data ) {
		$encoded = rawurlencode( $data );
		return 'https://api.qrserver.com/v1/create-qr-code/?size=190x190&data=' . $encoded . '&margin=10';
	}

	/**
	 * Permanently delete a customer from the Members list.
	 */
	public function delete_store_member() {
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		check_admin_referer( 'lwc_delete_store_member_' . $user_id );

		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'delete_user', $user_id ) || ! $this->is_store_member( $user_id ) ) {
			wp_die( esc_html__( 'You do not have permission to delete this member.', 'lovecatz-wc' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );
		wp_safe_redirect( admin_url( 'admin.php?page=lovecatz-wc&tab=store-members' ) );
		exit;
	}

	/**
	 * Download an XLSX import template using the supplied contact-export headers.
	 */
	public function download_member_import_template() {
		check_admin_referer( 'lwc_download_member_import_template' );

		if ( ! current_user_can( 'create_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to download this template.', 'lovecatz-wc' ) );
		}

		$template_path = $this->get_member_import_template_source_path();
		if ( $template_path ) {
			$template_name = basename( $template_path );
			$extension     = strtolower( pathinfo( $template_name, PATHINFO_EXTENSION ) );
			$mime_type     = 'application/vnd.ms-excel';
			if ( 'xlsx' === $extension ) {
				$mime_type = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
			}

			nocache_headers();
			header( 'Content-Type: ' . $mime_type );
			header( 'Content-Disposition: attachment; filename="' . $template_name . '"' );
			header( 'Content-Length: ' . filesize( $template_path ) );
			readfile( $template_path );
			exit;
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'The PHP ZIP extension is required to generate the Excel template.', 'lovecatz-wc' ) );
		}

		$template_file = wp_tempnam( 'members-import-template.xlsx' );
		if ( ! $template_file ) {
			wp_die( esc_html__( 'Unable to create the template file.', 'lovecatz-wc' ) );
		}
		@unlink( $template_file );

		$archive = new ZipArchive();
		$opened = $archive->open( $template_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		if ( true !== $opened ) {
			@unlink( $template_file );
			wp_die( esc_html__( 'Unable to generate the Excel template.', 'lovecatz-wc' ) );
		}

		foreach ( $this->get_member_import_template_files() as $entry ) {
			$archive->addFromString( $entry['name'], $entry['content'] );
		}
		$archive->close();

		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="members-import-template.xlsx"' );
		header( 'Content-Length: ' . filesize( $template_file ) );
		readfile( $template_file );
		@unlink( $template_file );
		exit;
	}

	/**
	 * Resolve a project-root CRM workbook to use as the template when available.
	 *
	 * @return string
	 */
	private function get_member_import_template_source_path() {
		return '';
	}

	/**
	 * Build the minimal XLSX package used by the download template.
	 *
	 * @return array
	 */
	private function get_member_import_template_files() {
		$headers   = array(
			'External ID', 'Name', 'Company Name', 'Contact Name', 'Email', 'Job Position', 'Phone', 'Mobile',
			'Street', 'Street2', 'City', 'State', 'Zip', 'Country', 'Website', 'Notes',
		);
		$cells     = array();
		foreach ( $headers as $index => $header ) {
			$cells[] = '<c r="' . $this->get_excel_column_name( $index + 1 ) . '1" t="inlineStr"><is><t>' . htmlspecialchars( $header, ENT_XML1 | ENT_COMPAT, 'UTF-8' ) . '</t></is></c>';
		}
		$sheet_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1">'
			. implode( '', $cells )
			. '</row><row r="2"/></sheetData></worksheet>';

		return array(
			array( 'name' => '[Content_Types].xml', 'content' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>' ),
			array( 'name' => '_rels/.rels', 'content' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>' ),
			array( 'name' => 'xl/workbook.xml', 'content' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Members Import" sheetId="1" r:id="rId1"/></sheets></workbook>' ),
			array( 'name' => 'xl/_rels/workbook.xml.rels', 'content' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>' ),
			array( 'name' => 'xl/worksheets/sheet1.xml', 'content' => $sheet_xml ),
		);
	}

	/**
	 * Convert a 1-based column index into an Excel column name.
	 *
	 * @param int $index Column index.
	 * @return string
	 */
	private function get_excel_column_name( $index ) {
		$column = '';
		while ( $index > 0 ) {
			$index--;
			$column = chr( 65 + ( $index % 26 ) ) . $column;
			$index  = (int) floor( $index / 26 );
		}

		return $column;
	}


	/**
	 * Parse a CSV file into rows.
	 *
	 * @param string $file_path File path.
	 * @return array
	 */
	private function parse_csv_file( $file_path ) {
		$rows = array();
		if ( ! is_readable( $file_path ) ) {
			return $rows;
		}

		$handle = fopen( $file_path, 'rb' );
		if ( false === $handle ) {
			return $rows;
		}

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			$rows[] = $row;
		}
		fclose( $handle );

		return $this->normalize_rows( $rows );
	}

	/**
	 * Parse an Excel .xlsx file into rows.
	 *
	 * @param string $file_path File path.
	 * @return array
	 */
	private function parse_xlsx_file( $file_path ) {
		$shared_strings = array();
		$shared_strings_xml = $this->get_xlsx_archive_file_contents( $file_path, 'xl/sharedStrings.xml' );
		if ( $shared_strings_xml ) {
			$shared_strings_dom = $this->load_xlsx_xml( $shared_strings_xml );
			if ( $shared_strings_dom ) {
				$shared_strings_dom->registerXPathNamespace( 'main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
				$shared_strings_items = $shared_strings_dom->xpath( '//main:si' );
				if ( $shared_strings_items ) {
					foreach ( $shared_strings_items as $string_item ) {
						$text = '';
						$text_nodes = $string_item->xpath( './/main:t' );
						foreach ( $text_nodes as $text_node ) {
							$text .= (string) $text_node;
						}
						$shared_strings[] = $text;
					}
				}
			}
		}

		$workbook_xml = $this->get_xlsx_archive_file_contents( $file_path, 'xl/workbook.xml' );
		if ( ! $workbook_xml ) {
			return array();
		}

		$workbook = $this->load_xlsx_xml( $workbook_xml );
		if ( ! $workbook ) {
			return array();
		}
		$sheet_target = '';
		if ( isset( $workbook->sheets->sheet[0] ) ) {
			$sheet_id = (string) $workbook->sheets->sheet[0]->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' )->id;
			$relationships = $this->load_xlsx_xml( $this->get_xlsx_archive_file_contents( $file_path, 'xl/_rels/workbook.xml.rels' ) );
			if ( $relationships ) {
				foreach ( $relationships->Relationship as $relationship ) {
					if ( (string) $relationship['Id'] === $sheet_id ) {
						$sheet_target = (string) $relationship['Target'];
						break;
					}
				}
			}
		}

		if ( '' === $sheet_target ) {
			return array();
		}

		$sheet_path = 'xl/' . ltrim( $sheet_target, '/' );
		if ( strpos( $sheet_target, 'worksheets/' ) === 0 ) {
			$sheet_path = 'xl/' . $sheet_target;
		}

		$sheet_xml = $this->get_xlsx_archive_file_contents( $file_path, $sheet_path );
		if ( ! $sheet_xml ) {
			return array();
		}

		$sheet = $this->load_xlsx_xml( $sheet_xml );
		$rows = array();
		if ( ! $sheet ) {
			return array();
		}

		$sheet->registerXPathNamespace( 'main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
		$sheet_rows = $sheet->xpath( '//main:sheetData/main:row' );
		if ( empty( $sheet_rows ) ) {
			$sheet_rows = $sheet->xpath( '//row' );
		}
		if ( empty( $sheet_rows ) ) {
			return array();
		}

		foreach ( $sheet_rows as $row ) {
			$values = array();
			$cell_nodes = $row->xpath( './main:c' );
			if ( empty( $cell_nodes ) ) {
				$cell_nodes = $row->xpath( './c' );
			}
			foreach ( $cell_nodes as $cell ) {
				$column_index = $this->get_xlsx_column_index( (string) $cell['r'] );
				while ( count( $values ) <= $column_index ) {
					$values[] = '';
				}

				$cell_type = (string) $cell['t'];
				$cell_value = '';
				if ( 's' === $cell_type ) {
					$shared_string_index = (int) $cell->v;
					$cell_value = isset( $shared_strings[ $shared_string_index ] ) ? $shared_strings[ $shared_string_index ] : '';
				} elseif ( 'inlineStr' === $cell_type ) {
					$inline_text_nodes = $cell->xpath( './main:is/main:t' );
					foreach ( $inline_text_nodes as $inline_text_node ) {
						$cell_value .= (string) $inline_text_node;
					}
				} else {
					$cell_value = (string) $cell->v;
				}
				$values[ $column_index ] = $cell_value;
			}
			$rows[] = $values;
		}

		return $this->normalize_rows( $rows );
	}

	/**
	 * Parse an Excel .xls file into rows.
	 *
	 * @param string $file_path File path.
	 * @return array
	 */
	private function parse_xls_file( $file_path ) {
		if ( ! is_readable( $file_path ) ) {
			return array();
		}

		$file_contents = file_get_contents( $file_path );
		if ( false === $file_contents ) {
			return array();
		}

		$trimmed = ltrim( $file_contents );
		if ( 0 === stripos( $trimmed, '<?xml' ) ) {
			return $this->parse_xml_spreadsheet( $file_contents );
		}

		if ( class_exists( 'COM' ) ) {
			return $this->parse_xls_file_with_com( $file_path );
		}

		return array();
	}

	/**
	 * Parse an XML Spreadsheet (.xls saved as XML) into rows.
	 *
	 * @param string $xml XML content.
	 * @return array
	 */
	private function parse_xml_spreadsheet( $xml ) {
		$document = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		if ( ! $document ) {
			return array();
		}

		$worksheets = array();
		if ( isset( $document->Worksheet ) ) {
			$worksheets = $document->Worksheet;
		} elseif ( isset( $document->Workbook ) && isset( $document->Workbook->Worksheet ) ) {
			$worksheets = $document->Workbook->Worksheet;
		}

		if ( empty( $worksheets ) ) {
			return array();
		}

		$rows = array();
		foreach ( $worksheets as $worksheet ) {
			if ( ! isset( $worksheet->Table ) ) {
				continue;
			}

			foreach ( $worksheet->Table->Row as $row ) {
				$values = array();
				$column_index = 0;
				foreach ( $row->Cell as $cell ) {
					$cell_index = $column_index;
					$cell_attrs = $cell->attributes();
					if ( isset( $cell_attrs->Index ) ) {
						$cell_index = (int) $cell_attrs->Index - 1;
					}

					while ( count( $values ) < $cell_index ) {
						$values[] = '';
					}

					$data = $cell->Data;
					$values[ $cell_index ] = isset( $data ) ? (string) $data : '';
					$column_index = $cell_index + 1;
				}

				$rows[] = $values;
			}
			break;
		}

		return $this->normalize_rows( $rows );
	}

	/**
	 * Parse a .xls file with COM if available on Windows.
	 *
	 * @param string $file_path File path.
	 * @return array
	 */
	private function parse_xls_file_with_com( $file_path ) {
		$excel = @new COM( 'Excel.Application' );
		if ( ! $excel ) {
			return array();
		}

		$excel->Visible = false;
		$excel->DisplayAlerts = false;

		$file_path = realpath( $file_path );
		if ( false === $file_path ) {
			$excel->Quit();
			return array();
		}

		try {
			$workbook = $excel->Workbooks->Open( $file_path, 0, true );
			$sheet = $workbook->Worksheets(1);
			$used_range = $sheet->UsedRange;
			$row_count = (int) $used_range->Rows->Count;
			$col_count = (int) $used_range->Columns->Count;

			$rows = array();
			for ( $row_index = 1; $row_index <= $row_count; $row_index++ ) {
				$row_values = array();
				for ( $col_index = 1; $col_index <= $col_count; $col_index++ ) {
					$cell = $used_range->Item( $row_index, $col_index );
					$row_values[] = is_object( $cell ) ? (string) $cell->Value2 : '';
				}
				$rows[] = $row_values;
			}

			$workbook->Close( false );
			$excel->Quit();
		} catch ( Exception $e ) {
			$rows = array();
		}

		unset( $sheet, $used_range, $workbook, $excel );
		return $this->normalize_rows( $rows );
	}

	/**
	 * Convert an Excel cell reference such as A1 or BC12 into a zero-based index.
	 *
	 * @param string $reference Cell reference.
	 * @return int
	 */
	private function get_xlsx_column_index( $reference ) {
		$reference = preg_replace( '/[0-9]+/', '', strtoupper( (string) $reference ) );
		if ( '' === $reference ) {
			return 0;
		}

		$index = 0;
		for ( $i = 0, $length = strlen( $reference ); $i < $length; $i++ ) {
			$index = ( $index * 26 ) + ( ord( $reference[ $i ] ) - 64 );
		}

		return max( 0, $index - 1 );
	}

	/**
	 * Load internal XLSX XML safely without external entity processing.
	 *
	 * @param string|false $xml XML content.
	 * @return SimpleXMLElement|false
	 */
	private function load_xlsx_xml( $xml ) {
		if ( ! is_string( $xml ) || false !== stripos( $xml, '<!DOCTYPE' ) ) {
			return false;
		}

		return simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
	}

	/**
	 * Read a file from an XLSX archive using the PHP ZIP extension.
	 *
	 * @param string $file_path Archive path.
	 * @param string $archive_file File path inside the archive.
	 * @return string|false
	 */
	private function get_xlsx_archive_file_contents( $file_path, $archive_file ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $file_path ) ) {
			return false;
		}

		$stat = $zip->statName( $archive_file );
		if ( ! is_array( $stat ) || empty( $stat['size'] ) || $stat['size'] > 10 * MB_IN_BYTES || ( ! empty( $stat['comp_size'] ) && $stat['size'] / $stat['comp_size'] > 100 ) ) {
			$zip->close();
			return false;
		}

		$contents = $zip->getFromName( $archive_file );
		$zip->close();
		return $contents;
	}

	/**
	 * Normalize rows into associative arrays using the first row as headers.
	 *
	 * @param array $rows Raw rows.
	 * @return array
	 */
	private function normalize_rows( $rows ) {
		if ( empty( $rows ) ) {
			return array();
		}

		$headers = array_shift( $rows );
		if ( empty( $headers ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $headers as $index => $header ) {
			$headers[ $index ] = $this->normalize_header( $header );
		}

		foreach ( $rows as $row ) {
			$normalized_row = array();
			$has_values      = false;
			foreach ( $headers as $index => $header ) {
				$value                     = isset( $row[ $index ] ) ? $row[ $index ] : '';
				$normalized_row[ $header ] = $value;
				if ( '' !== trim( (string) $value ) ) {
					$has_values = true;
				}
			}

			if ( ! $has_values ) {
				continue;
			}

			$normalized[] = $normalized_row;
		}

		return $normalized;
	}

	/**
	 * Get a normalized value from a row by trying several possible column names.
	 *
	 * @param array $row Row data.
	 * @param array $keys Candidate keys.
	 * @return string
	 */
	private function get_row_value( $row, $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) ) {
				return trim( (string) $row[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Normalize a header name to a stable key.
	 *
	 * @param string $header Header name.
	 * @return string
	 */
	private function normalize_header( $header ) {
		$normalized = strtolower( trim( (string) $header ) );
		$normalized = preg_replace( '/[^a-z0-9]+/', '_', $normalized );
		$normalized = preg_replace( '/_+/', '_', $normalized );
		return trim( $normalized, '_' );
	}

	/**
	 * Import users from normalized rows.
	 *
	 * @param array $rows Rows to import.
	 * @return array
	 */
	private function import_users_from_rows( $rows ) {
		$imported = 0;
		$skipped   = 0;
		$errors    = array();
 
		foreach ( $rows as $row ) {
			// FIX: added 'external_id' so it matches the "External ID" column
			// used by the current .xlsx template (Odoo-style CRM export),
			// while still supporting the legacy CSV column names.
			$main_id = sanitize_text_field( $this->get_row_value( $row, array( 'id_pelanggan', 'customer_id', 'id_customer', 'external_id', 'id' ) ) );
			$email   = $this->get_row_value( $row, array( 'email', 'e_mail_address', 'email_address', 'user_email' ) );
			if ( '' === $main_id ) {
				$skipped++;
				$errors[] = __( 'Skipped a row because an ID (External ID / ID_PELANGGAN) is required.', 'lovecatz-wc' );
				continue;
			}
 
			$username = sanitize_user( $main_id, true );
			if ( '' === $username ) {
				$skipped++;
				$errors[] = sprintf( __( 'Skipped customer ID %s because it cannot be used as a login.', 'lovecatz-wc' ), $main_id );
				continue;
			}
 
			if ( '' === $email || ! is_email( $email ) ) {
				$email = $this->get_placeholder_email( '' !== $main_id ? $main_id : $username );
			}
 
			if ( username_exists( $username ) || email_exists( $email ) ) {
				$skipped++;
				$errors[] = sprintf( __( 'Skipped %s because the user already exists.', 'lovecatz-wc' ), $username );
				continue;
			}
 
			// FIX: the current template only has a single "Name" (or "Contact
			// Name") column instead of separate First/Last Name columns.
			// Try explicit first/last name columns first (legacy support),
			// then fall back to splitting a single full-name column.
			$first_name = isset( $row['first_name'] ) ? sanitize_text_field( $row['first_name'] ) : '';
			$last_name  = isset( $row['last_name'] ) ? sanitize_text_field( $row['last_name'] ) : '';
			if ( '' === $first_name ) {
				$first_name = $this->get_row_value( $row, array( 'first_name', 'first', 'nama_depan' ) );
			}
			if ( '' === $last_name ) {
				$last_name = $this->get_row_value( $row, array( 'last_name', 'last', 'nama_belakang' ) );
			}
 
			if ( '' === $first_name && '' === $last_name ) {
				$full_name = $this->get_row_value( $row, array( 'name', 'contact_name' ) );
				if ( '' !== $full_name ) {
					$name_parts = preg_split( '/\s+/', trim( $full_name ), 2 );
					$first_name = $name_parts[0];
					$last_name  = isset( $name_parts[1] ) ? $name_parts[1] : '';
				}
			}
			$company_name = sanitize_text_field( $this->get_row_value( $row, array( 'company_name' ) ) );
			if ( '' === $first_name && '' === $last_name && '' !== $company_name ) {
				$first_name = $company_name;
			}
			$first_name = sanitize_text_field( $first_name );
			$last_name  = sanitize_text_field( $last_name );
 
			// This import belongs to the Store Members feature, so imported users
			// always receive WooCommerce's customer role regardless of file contents.
			$role = 'customer';
 
			// Imported members get a random password plus a password-reset email,
			// so credentials are never predictable from the customer ID.
			$password = wp_generate_password( 16, true, false );
 
			// FIX: added 'street' so it matches the "Street" column used by
			// the current .xlsx template, while still supporting legacy names.
			$street   = sanitize_text_field( $this->get_row_value( $row, array( 'street_adress', 'street_address', 'street', 'address', 'address_1' ) ) );
			$street2  = sanitize_text_field( $this->get_row_value( $row, array( 'street2' ) ) );
			if ( '' !== $street2 ) {
				$street = trim( $street . ' ' . $street2 );
			}
			$city     = sanitize_text_field( $this->get_row_value( $row, array( 'city' ) ) );
			$state    = sanitize_text_field( $this->get_row_value( $row, array( 'state_region', 'state', 'region' ) ) );
			$postcode = sanitize_text_field( $this->get_row_value( $row, array( 'postal_code', 'postcode', 'zip' ) ) );
			$country  = sanitize_text_field( $this->get_row_value( $row, array( 'country' ) ) );
			// FIX: fall back to "mobile" if "phone" is empty, since the
			// template provides both columns and either may be filled in.
			$phone    = sanitize_text_field( $this->get_row_value( $row, array( 'phone', 'phone_number', 'mobile', 'nomor_hp' ) ) );
 
			$user_data = array(
				'user_login'   => $username,
				'user_email'   => $email,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'user_pass'    => $password,
				'role'         => $role,
				'display_name' => trim( $first_name . ' ' . $last_name ),
			);
 
			$user_id = wp_insert_user( $user_data );
			if ( is_wp_error( $user_id ) ) {
				$skipped++;
				$errors[] = $user_id->get_error_message();
			} else {
				if ( '' !== $main_id ) {
					update_user_meta( $user_id, 'lwc_customer_id', $main_id );
				}
 
				if ( '' !== $first_name ) {
					update_user_meta( $user_id, 'billing_first_name', $first_name );
					update_user_meta( $user_id, 'shipping_first_name', $first_name );
				}
				if ( '' !== $last_name ) {
					update_user_meta( $user_id, 'billing_last_name', $last_name );
					update_user_meta( $user_id, 'shipping_last_name', $last_name );
				}
				if ( '' !== $company_name ) {
					update_user_meta( $user_id, 'billing_company', $company_name );
					update_user_meta( $user_id, 'shipping_company', $company_name );
				}
 
				if ( '' !== $street ) {
					update_user_meta( $user_id, 'billing_address_1', $street );
					update_user_meta( $user_id, 'shipping_address_1', $street );
				}
				if ( '' !== $city ) {
					update_user_meta( $user_id, 'billing_city', $city );
					update_user_meta( $user_id, 'shipping_city', $city );
				}
				if ( '' !== $state ) {
					update_user_meta( $user_id, 'billing_state', $state );
					update_user_meta( $user_id, 'shipping_state', $state );
				}
				if ( '' !== $postcode ) {
					update_user_meta( $user_id, 'billing_postcode', $postcode );
					update_user_meta( $user_id, 'shipping_postcode', $postcode );
				}
				if ( '' !== $country ) {
					update_user_meta( $user_id, 'billing_country', $country );
					update_user_meta( $user_id, 'shipping_country', $country );
				}
				if ( '' !== $phone ) {
					update_user_meta( $user_id, 'billing_phone', $phone );
				}

				wp_new_user_notification( $user_id, null, 'user' );

				$imported++;
			}
		}
 
		$message = sprintf( __( 'Imported %d user(s). Skipped %d row(s).', 'lovecatz-wc' ), $imported, $skipped );
		if ( ! empty( $errors ) ) {
			$message .= ' ' . implode( ' ', array_slice( $errors, 0, 5 ) );
		}
 
		return array(
			'success' => $imported > 0,
			'message' => $message,
		);
	}

	/**
	 * Generate a unique, syntactically valid placeholder email for imported users
	 * whose source email is empty or invalid.
	 *
	 * @param string $identifier Customer ID or username.
	 * @return string
	 */
	private function get_placeholder_email( $identifier ) {
		$local_part = sanitize_title( $identifier );
		if ( '' === $local_part ) {
			$local_part = 'customer';
		}

		$email   = $local_part . '@no-email.local';
		$counter = 1;
		while ( email_exists( $email ) ) {
			$email = $local_part . '+' . $counter . '@no-email.local';
			$counter++;
		}

		return $email;
	}
}
