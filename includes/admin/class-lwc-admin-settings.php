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
	private $members_handler;

	/**
	 * Initialize admin hooks.
	 */
	public function init() {
		$this->members_handler = new LWC_Admin_Members();
		$this->members_handler->init();

		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
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
	}

	/**
	 * Enqueue admin scripts and styles for the plugin settings page.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( get_current_screen() && 'toplevel_page_lovecatz-wc' !== get_current_screen()->id ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'lwc-admin-settings', LWC_PLUGIN_URL . 'includes/admin-settings.css', array(), LWC_VERSION );
		wp_enqueue_script( 'lwc-admin-settings', LWC_PLUGIN_URL . 'includes/admin-settings.js', array( 'jquery' ), LWC_VERSION, true );
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
		if ( ! in_array( $active_tab, array( 'settings', 'shipping', 'currency', 'store-members' ), true ) ) {
			$active_tab = 'settings';
		}

		$provider = isset( $_GET['provider'] ) ? sanitize_text_field( wp_unslash( $_GET['provider'] ) ) : 'jt';
		if ( ! in_array( $provider, array( 'jt', 'fedex' ), true ) ) {
			$provider = 'jt';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'LoveCatz WooCommerce Complement Settings', 'lovecatz-wc' ); ?></h1>
			<h2 class="nav-tab-wrapper">
				<a href="?page=lovecatz-wc&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Setting', 'lovecatz-wc' ); ?></a>
				<a href="?page=lovecatz-wc&tab=store-members" class="nav-tab <?php echo $active_tab === 'store-members' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Members', 'lovecatz-wc' ); ?></a>
				<a href="?page=lovecatz-wc&tab=shipping" class="nav-tab <?php echo $active_tab === 'shipping' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Shipping', 'lovecatz-wc' ); ?></a>
				<a href="?page=lovecatz-wc&tab=currency" class="nav-tab <?php echo $active_tab === 'currency' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Currency', 'lovecatz-wc' ); ?></a>
			</h2>

			<?php if ( $this->members_handler instanceof LWC_Admin_Members ) {
				$this->members_handler->render_import_result_notice();
			} ?>

			<?php if ( 'settings' === $active_tab ) : ?>
				<form method="post" action="options.php">
					<?php settings_fields( 'lwc_general_options' ); do_settings_sections( 'lwc_general_options' ); submit_button(); ?>
				</form>
			<?php elseif ( 'shipping' === $active_tab ) : ?>
				<div style="margin: 15px 0;">
					<a href="?page=lovecatz-wc&tab=shipping&provider=jt" class="button <?php echo 'jt' === $provider ? 'button-primary' : 'button-secondary'; ?>"><?php esc_html_e( 'J&T Express', 'lovecatz-wc' ); ?></a>
					<a href="?page=lovecatz-wc&tab=shipping&provider=fedex" class="button <?php echo 'fedex' === $provider ? 'button-primary' : 'button-secondary'; ?>"><?php esc_html_e( 'FedEx', 'lovecatz-wc' ); ?></a>
				</div>
				<?php if ( 'jt' === $provider ) : ?>
					<form method="post" action="options.php">
						<?php settings_fields( 'lwc_shipping_jt_options' ); do_settings_sections( 'lwc_shipping_jt_options' ); submit_button(); ?>
					</form>
				<?php else : ?>
					<div class="notice notice-info inline"><p><?php esc_html_e( 'FedEx will be available for international shipments where the destination country is outside Indonesia.', 'lovecatz-wc' ); ?></p></div>
					<form method="post" action="options.php">
						<?php settings_fields( 'lwc_shipping_fedex_options' ); do_settings_sections( 'lwc_shipping_fedex_options' ); submit_button(); ?>
					</form>
				<?php endif; ?>
			<?php elseif ( 'currency' === $active_tab ) : ?>
				<p><?php esc_html_e( 'Currency settings coming soon.', 'lovecatz-wc' ); ?></p>
			<?php else : ?>
				<?php $this->members_handler->render_store_members(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings() {
		LWC_Logger::log( 'Registering admin settings.', 'info' );

		register_setting( 'lwc_shipping_jt_options', 'lwc_jt_api_key' );
		register_setting( 'lwc_shipping_jt_options', 'lwc_jt_api_secret' );
		register_setting( 'lwc_shipping_jt_options', 'lwc_jt_test_mode' );

		add_settings_section( 'lwc_shipping_jt_section', __( 'J&T Express Settings', 'lovecatz-wc' ), array( $this, 'render_jt_section_intro' ), 'lwc_shipping_jt_options' );
		add_settings_field( 'lwc_jt_api_key', __( 'API Key', 'lovecatz-wc' ), array( $this, 'render_jt_api_key_field' ), 'lwc_shipping_jt_options', 'lwc_shipping_jt_section' );
		add_settings_field( 'lwc_jt_api_secret', __( 'API Secret', 'lovecatz-wc' ), array( $this, 'render_jt_api_secret_field' ), 'lwc_shipping_jt_options', 'lwc_shipping_jt_section' );
		add_settings_field( 'lwc_jt_test_mode', __( 'Enable Test Mode', 'lovecatz-wc' ), array( $this, 'render_jt_test_mode_field' ), 'lwc_shipping_jt_options', 'lwc_shipping_jt_section' );

		register_setting( 'lwc_shipping_fedex_options', 'lwc_fedex_account_number' );
		register_setting( 'lwc_shipping_fedex_options', 'lwc_fedex_api_key' );
		register_setting( 'lwc_shipping_fedex_options', 'lwc_fedex_api_secret' );
		register_setting( 'lwc_shipping_fedex_options', 'lwc_fedex_test_mode' );

		add_settings_section( 'lwc_shipping_fedex_section', __( 'FedEx Settings', 'lovecatz-wc' ), array( $this, 'render_fedex_section_intro' ), 'lwc_shipping_fedex_options' );
		add_settings_field( 'lwc_fedex_account_number', __( 'Account Number', 'lovecatz-wc' ), array( $this, 'render_fedex_account_number_field' ), 'lwc_shipping_fedex_options', 'lwc_shipping_fedex_section' );
		add_settings_field( 'lwc_fedex_api_key', __( 'API Key', 'lovecatz-wc' ), array( $this, 'render_fedex_api_key_field' ), 'lwc_shipping_fedex_options', 'lwc_shipping_fedex_section' );
		add_settings_field( 'lwc_fedex_api_secret', __( 'API Secret', 'lovecatz-wc' ), array( $this, 'render_fedex_api_secret_field' ), 'lwc_shipping_fedex_options', 'lwc_shipping_fedex_section' );
		add_settings_field( 'lwc_fedex_test_mode', __( 'Enable Test Mode', 'lovecatz-wc' ), array( $this, 'render_fedex_test_mode_field' ), 'lwc_shipping_fedex_options', 'lwc_shipping_fedex_section' );

		register_setting( 'lwc_general_options', 'lwc_menu_title', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'lwc_general_options', 'lwc_menu_icon', array( 'sanitize_callback' => 'sanitize_text_field' ) );

		add_settings_section( 'lwc_general_section_menu', __( 'Admin Menu Appearance', 'lovecatz-wc' ), array( $this, 'render_menu_section_intro' ), 'lwc_general_options' );
		add_settings_field( 'lwc_menu_title', __( 'Sidebar Name', 'lovecatz-wc' ), array( $this, 'render_menu_title_field' ), 'lwc_general_options', 'lwc_general_section_menu' );
		add_settings_field( 'lwc_menu_icon', __( 'Sidebar Icon', 'lovecatz-wc' ), array( $this, 'render_menu_icon_field' ), 'lwc_general_options', 'lwc_general_section_menu' );
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

	public function render_fedex_section_intro() {
		echo '<p>' . esc_html__( 'Enter your FedEx credentials below. FedEx will appear for international shipments outside Indonesia.', 'lovecatz-wc' ) . '</p>';
		echo '<div id="lwc-fedex-connection-status" class="lwc-fedex-connection-status" data-status="idle"><span class="lwc-fedex-status-dot"></span><span class="lwc-fedex-status-label">' . esc_html__( 'Waiting for credentials', 'lovecatz-wc' ) . '</span></div>';
		echo '<p class="description">' . esc_html__( 'FedEx is automatically configured to use REST API.', 'lovecatz-wc' ) . '</p>';
	}

	public function render_fedex_account_number_field() {
		$value = get_option( 'lwc_fedex_account_number', '' );
		echo '<input type="text" id="lwc_fedex_account_number" name="lwc_fedex_account_number" value="' . esc_attr( $value ) . '" class="regular-text lwc-fedex-credential-field" data-fedex-field="account_number" />';
	}

	public function render_fedex_api_key_field() {
		$value = get_option( 'lwc_fedex_api_key', '' );
		echo '<input type="text" id="lwc_fedex_api_key" name="lwc_fedex_api_key" value="' . esc_attr( $value ) . '" class="regular-text lwc-fedex-credential-field" data-fedex-field="api_key" />';
	}

	public function render_fedex_api_secret_field() {
		$value = get_option( 'lwc_fedex_api_secret', '' );
		echo '<input type="password" id="lwc_fedex_api_secret" name="lwc_fedex_api_secret" value="' . esc_attr( $value ) . '" class="regular-text lwc-fedex-credential-field" data-fedex-field="api_secret" />';
	}

	public function render_fedex_test_mode_field() {
		$value = get_option( 'lwc_fedex_test_mode', 'no' );
		$checked = 'yes' === $value ? 'checked' : '';
		echo '<input type="checkbox" name="lwc_fedex_test_mode" value="yes" ' . $checked . ' /> ' . esc_html__( 'Check to enable testing mode (sandbox). Leave unchecked for production.', 'lovecatz-wc' );
	}

	public function render_menu_section_intro() {
		echo '<p>' . esc_html__( 'Customize the sidebar name and icon shown for the LoveCatz admin menu. Choose a Dashicon from the official WordPress reference below.', 'lovecatz-wc' ) . '</p>';
	}

	public function render_menu_title_field() {
		$value = get_option( 'lwc_menu_title', __( 'LoveCatz', 'lovecatz-wc' ) );
		echo '<input type="text" name="lwc_menu_title" value="' . esc_attr( $value ) . '" class="regular-text" />';
	}

	public function render_menu_icon_field() {
		$value = get_option( 'lwc_menu_icon', 'dashicons-pets' );
		echo '<input type="text" id="lwc_menu_icon_class" name="lwc_menu_icon" value="' . esc_attr( $value ) . '" class="regular-text" />';
		echo '<p class="description">' . esc_html__( 'Enter a Dashicons class such as dashicons-admin-home, dashicons-store, or dashicons-heart. See the full list at the official WordPress Dashicons page.', 'lovecatz-wc' ) . ' <a href="https://developer.wordpress.org/resource/dashicons/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View Dashicons', 'lovecatz-wc' ) . '</a></p>';
		echo '<div class="lwc-dashicon-choices">';
		$icons = array( 'dashicons-admin-home', 'dashicons-store', 'dashicons-heart', 'dashicons-cart', 'dashicons-groups', 'dashicons-universal-access', 'dashicons-star-filled', 'dashicons-awards', 'dashicons-admin-site', 'dashicons-admin-tools' );
		foreach ( $icons as $icon ) {
			$selected = $value === $icon ? ' selected' : '';
			echo '<button type="button" class="lwc-dashicon-choice' . $selected . '" data-icon="' . esc_attr( $icon ) . '"><span class="dashicons ' . esc_attr( $icon ) . '"></span><span class="dashicon-label">' . esc_html( str_replace( 'dashicons-', '', $icon ) ) . '</span></button>';
		}
		echo '</div>';
	}
}
