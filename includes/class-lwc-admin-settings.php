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
	 * Initialize admin hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add the settings page to the admin menu.
	 */
	public function add_settings_page() {
		add_menu_page(
			__( 'LoveCatz WC', 'lovecatz-wc' ),
			__( 'LoveCatz WC', 'lovecatz-wc' ),
			'manage_woocommerce',
			'lovecatz-wc',
			array( $this, 'render_settings_page' ),
			'dashicons-admin-generic',
			56
		);
	}

	/**
	 * Render the settings page with tabs.
	 */
	public function render_settings_page() {
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'couriers';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'LoveCatz WooCommerce Complement Settings', 'lovecatz-wc' ); ?></h1>
			
			<h2 class="nav-tab-wrapper">
				<a href="?page=lovecatz-wc&tab=couriers" class="nav-tab <?php echo $active_tab === 'couriers' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Couriers', 'lovecatz-wc' ); ?></a>
				<a href="?page=lovecatz-wc&tab=currency" class="nav-tab <?php echo $active_tab === 'currency' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Currency', 'lovecatz-wc' ); ?></a>
				<a href="?page=lovecatz-wc&tab=language" class="nav-tab <?php echo $active_tab === 'language' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Language', 'lovecatz-wc' ); ?></a>
			</h2>

			<form method="post" action="options.php">
				<?php
				if ( 'couriers' === $active_tab ) {
					settings_fields( 'lwc_couriers_options' );
					do_settings_sections( 'lwc_couriers_options' );
				} elseif ( 'currency' === $active_tab ) {
					// Future currency settings
					echo '<p>' . esc_html__( 'Currency settings coming soon.', 'lovecatz-wc' ) . '</p>';
				} elseif ( 'language' === $active_tab ) {
					// Future language settings
					echo '<p>' . esc_html__( 'Language settings coming soon.', 'lovecatz-wc' ) . '</p>';
				}
				
				if ( 'couriers' === $active_tab ) {
					submit_button();
				}
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings() {
		LWC_Logger::log( 'Registering admin settings.', 'info' );

		// Couriers Settings
		register_setting( 'lwc_couriers_options', 'lwc_jt_api_key' );
		register_setting( 'lwc_couriers_options', 'lwc_jt_api_secret' );
		register_setting( 'lwc_couriers_options', 'lwc_jt_test_mode' );

		add_settings_section(
			'lwc_couriers_section_jt',
			__( 'J&T Express Settings', 'lovecatz-wc' ),
			array( $this, 'render_jt_section_intro' ),
			'lwc_couriers_options'
		);

		add_settings_field(
			'lwc_jt_api_key',
			__( 'API Key', 'lovecatz-wc' ),
			array( $this, 'render_jt_api_key_field' ),
			'lwc_couriers_options',
			'lwc_couriers_section_jt'
		);

		add_settings_field(
			'lwc_jt_api_secret',
			__( 'API Secret', 'lovecatz-wc' ),
			array( $this, 'render_jt_api_secret_field' ),
			'lwc_couriers_options',
			'lwc_couriers_section_jt'
		);

		add_settings_field(
			'lwc_jt_test_mode',
			__( 'Enable Test Mode', 'lovecatz-wc' ),
			array( $this, 'render_jt_test_mode_field' ),
			'lwc_couriers_options',
			'lwc_couriers_section_jt'
		);
	}

	public function render_jt_section_intro() {
		echo '<p>' . esc_html__( 'Enter your J&T Express API credentials below.', 'lovecatz-wc' ) . '</p>';
	}

	public function render_jt_api_key_field() {
		$value = get_option( 'lwc_jt_api_key', '' );
		echo '<input type="text" name="lwc_jt_api_key" value="' . esc_attr( $value ) . '" class="regular-text" />';
	}

	public function render_jt_api_secret_field() {
		$value = get_option( 'lwc_jt_api_secret', '' );
		echo '<input type="password" name="lwc_jt_api_secret" value="' . esc_attr( $value ) . '" class="regular-text" />';
	}

	public function render_jt_test_mode_field() {
		$value = get_option( 'lwc_jt_test_mode', 'no' );
		$checked = 'yes' === $value ? 'checked' : '';
		echo '<input type="checkbox" name="lwc_jt_test_mode" value="yes" ' . $checked . ' /> ' . esc_html__( 'Check to enable testing mode (uses sandbox API).', 'lovecatz-wc' );
	}
}
