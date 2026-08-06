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
		if ( ! in_array( $active_tab, array( 'settings', 'couriers', 'currency', 'store-members' ), true ) ) {
			$active_tab = 'settings';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'LoveCatz WooCommerce Complement Settings', 'lovecatz-wc' ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<a href="?page=lovecatz-wc&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Setting', 'lovecatz-wc' ); ?></a>
				<a href="?page=lovecatz-wc&tab=store-members" class="nav-tab <?php echo $active_tab === 'store-members' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Members', 'lovecatz-wc' ); ?></a>
				<a href="?page=lovecatz-wc&tab=couriers" class="nav-tab <?php echo $active_tab === 'couriers' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Couriers', 'lovecatz-wc' ); ?></a>
				<a href="?page=lovecatz-wc&tab=currency" class="nav-tab <?php echo $active_tab === 'currency' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Currency', 'lovecatz-wc' ); ?></a>
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
			<?php elseif ( 'couriers' === $active_tab ) : ?>
				<form method="post" action="options.php">
					<?php
					settings_fields( 'lwc_couriers_options' );
					do_settings_sections( 'lwc_couriers_options' );
					submit_button();
					?>
				</form>
			<?php elseif ( 'currency' === $active_tab ) : ?>
				<p><?php esc_html_e( 'Currency settings coming soon.', 'lovecatz-wc' ); ?></p>
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
	}

	/**
	 * Render introductory text for the J&T section.
	 */
	public function render_jt_section_intro() {
		echo '<p>' . esc_html__( 'Enter your J&T Express API credentials below.', 'lovecatz-wc' ) . '</p>';
	}

	/**
	 * Render the API key field.
	 */
	public function render_jt_api_key_field() {
		$value = get_option( 'lwc_jt_api_key', '' );
		echo '<input type="text" name="lwc_jt_api_key" value="' . esc_attr( $value ) . '" class="regular-text" />';
	}

	/**
	 * Render the API secret field.
	 */
	public function render_jt_api_secret_field() {
		$value = get_option( 'lwc_jt_api_secret', '' );
		echo '<input type="password" name="lwc_jt_api_secret" value="' . esc_attr( $value ) . '" class="regular-text" />';
	}

	/**
	 * Render the test mode field.
	 */
	public function render_jt_test_mode_field() {
		$value = get_option( 'lwc_jt_test_mode', 'no' );
		$checked = 'yes' === $value ? 'checked' : '';
		echo '<input type="checkbox" name="lwc_jt_test_mode" value="yes" ' . $checked . ' /> ' . esc_html__( 'Check to enable testing mode (uses sandbox API).', 'lovecatz-wc' );
	}

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
		if ( ! in_array( $extension, array( 'csv', 'xlsx' ), true ) ) {
			$this->last_import_result = array(
				'success' => false,
				'message' => __( 'Only CSV and .xlsx files are supported.', 'lovecatz-wc' ),
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
		} else {
			if ( ! class_exists( 'ZipArchive' ) ) {
				$this->last_import_result = array(
					'success' => false,
					'message' => __( 'The PHP ZIP extension is required to read .xlsx files.', 'lovecatz-wc' ),
				);
				@unlink( $file['tmp_name'] );
				return;
			}
			$rows = $this->parse_xlsx_file( $file['tmp_name'] );
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
			<p><?php esc_html_e( 'Upload an Excel (.xlsx) or CSV file. All imported users will receive the Customer role.', 'lovecatz-wc' ); ?></p>
			<input type="file" name="lwc_import_file" accept=".csv,.xlsx" required />
			<p class="description"><?php esc_html_e( 'Supported columns: ID_PELANGGAN, First Name, Last Name, Email or E-Mail Address, Phone, Street Address/Street Adress, City, State/Region, Postal Code, and Country.', 'lovecatz-wc' ); ?></p>
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
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<title><?php esc_html_e( 'Member Card', 'lovecatz-wc' ); ?></title>
			<style>
				body { margin: 0; font-family: Arial, sans-serif; background: #f0f0f1; }
				.card { box-sizing: border-box; width: 86mm; min-height: 54mm; margin: 24px auto; padding: 16px; color: #fff; border-radius: 10px; background: linear-gradient(135deg, #3a186e, #a33bab); }
				.card h1 { margin: 0 0 22px; font-size: 22px; } .label { font-size: 11px; opacity: .8; text-transform: uppercase; } .value { margin: 3px 0 13px; font-size: 15px; font-weight: 700; }
				@media print { body { background: transparent; } .card { margin: 0; } }
			</style>
		</head>
		<body onload="window.print()">
			<div class="card">
				<h1><?php esc_html_e( 'MEMBER CARD', 'lovecatz-wc' ); ?></h1>
				<div class="label"><?php esc_html_e( 'Name', 'lovecatz-wc' ); ?></div><div class="value"><?php echo esc_html( $member->display_name ); ?></div>
				<div class="label"><?php esc_html_e( 'Customer ID', 'lovecatz-wc' ); ?></div><div class="value"><?php echo esc_html( $customer_id ); ?></div>
				<div class="label"><?php esc_html_e( 'Phone Number', 'lovecatz-wc' ); ?></div><div class="value"><?php echo esc_html( $phone ); ?></div>
			</div>
		</body>
		</html>
		<?php
		exit;
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
	 * Build the minimal XLSX package used by the download template.
	 *
	 * @return array
	 */
	private function get_member_import_template_files() {
		$headers   = array(
			'ID_PELANGGAN', 'First Name', 'Last Name', 'E-Mail Address', 'Phone', 'Street Address', 'City',
			'State/Region', 'Postal Code', 'Country',
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
				$shared_strings_items = $shared_strings_dom->xpath( '//main:si' );
				if ( $shared_strings_items ) {
					foreach ( $shared_strings_items as $string_item ) {
						$text = '';
						$text_nodes = $string_item->xpath( './main:t' );
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
			return array();
		}

		foreach ( $sheet_rows as $row ) {
			$values = array();
			$cell_nodes = $row->xpath( './main:c' );
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
			// The last duplicate "ID" column in the legacy CSV export contains
			// the customer ID, so it remains supported as a safe fallback.
			$main_id = sanitize_text_field( $this->get_row_value( $row, array( 'id_pelanggan', 'customer_id', 'id_customer', 'id' ) ) );
			$email   = $this->get_row_value( $row, array( 'email', 'e_mail_address', 'email_address', 'user_email' ) );
			if ( '' === $main_id ) {
				$skipped++;
				$errors[] = __( 'Skipped a row because ID_PELANGGAN is required.', 'lovecatz-wc' );
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

			$first_name = isset( $row['first_name'] ) ? sanitize_text_field( $row['first_name'] ) : '';
			$last_name  = isset( $row['last_name'] ) ? sanitize_text_field( $row['last_name'] ) : '';
			if ( '' === $first_name ) {
				$first_name = $this->get_row_value( $row, array( 'first_name', 'first', 'nama_depan' ) );
			}
			if ( '' === $last_name ) {
				$last_name = $this->get_row_value( $row, array( 'last_name', 'last', 'nama_belakang' ) );
			}
			$first_name = sanitize_text_field( $first_name );
			$last_name  = sanitize_text_field( $last_name );

			// This import belongs to the Store Members feature, so imported users
			// always receive WooCommerce's customer role regardless of file contents.
			$role = 'customer';

			// Imported members use their customer ID as the initial password so
			// they can sign in with ID_PELANGGAN for both login fields.
			$password = $main_id;

			$street   = sanitize_text_field( $this->get_row_value( $row, array( 'street_adress', 'street_address', 'address', 'address_1' ) ) );
			$city     = sanitize_text_field( $this->get_row_value( $row, array( 'city' ) ) );
			$state    = sanitize_text_field( $this->get_row_value( $row, array( 'state_region', 'state', 'region' ) ) );
			$postcode = sanitize_text_field( $this->get_row_value( $row, array( 'postal_code', 'postcode', 'zip' ) ) );
			$country  = sanitize_text_field( $this->get_row_value( $row, array( 'country' ) ) );
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
