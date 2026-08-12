<?php
/**
 * Promo dashboard frontend integration for LoveCatz WooCommerce Complement.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_Promo_Dashboard {

	/**
	 * Initialize frontend hooks.
	 */
	public function init() {
		add_action( 'init', array( $this, 'register_coupon_endpoint' ) );
		add_filter( 'query_vars', array( $this, 'register_coupon_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_coupon_account_menu_item' ) );
		add_action( 'woocommerce_account_coupon_endpoint', array( $this, 'render_coupon_dashboard' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
	}

	/**
	 * Register coupon endpoint for My Account.
	 */
	public function register_coupon_endpoint() {
		if ( function_exists( 'add_rewrite_endpoint' ) ) {
			add_rewrite_endpoint( 'coupon', EP_ROOT | EP_PAGES );
		}
	}

	/**
	 * Register query var for coupon endpoint.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public function register_coupon_query_var( $vars ) {
		$vars[] = 'coupon';
		return $vars;
	}

	/**
	 * Enqueue frontend assets required for promo actions.
	 */
	public function enqueue_frontend_assets() {
		if ( ! function_exists( 'is_account_page' ) || ( ! is_account_page() && ! is_checkout() ) ) {
			return;
		}

		wp_enqueue_style( 'lwc-promo-dashboard', LWC_PLUGIN_URL . 'includes/promo-dashboard.css', array(), LWC_VERSION );
		wp_enqueue_script( 'lwc-promo-dashboard', LWC_PLUGIN_URL . 'includes/promo-dashboard.js', array( 'jquery' ), LWC_VERSION, true );
		wp_localize_script(
			'lwc-promo-dashboard',
			'lwcPromoDashboard',
			array(
				'couponInputSelector' => '.coupon input[name="coupon_code"]',
				'applyButtonSelector' => '.coupon .button',
				'checkoutUrl' => esc_url_raw( wc_get_checkout_url() ),
			)
		);
	}

	/**
	 * Add the coupon menu item to the customer My Account menu.
	 *
	 * @param array $items Existing menu items.
	 * @return array
	 */
	public function add_coupon_account_menu_item( $items ) {
		if ( ! is_user_logged_in() ) {
			return $items;
		}

		$items = array_slice( $items, 0, 1, true ) +
			array( 'coupon' => __( 'Coupon', 'lovecatz-wc' ) ) +
			array_slice( $items, 1, null, true );

		return $items;
	}

	/**
	 * Render the promo list in My Account > Coupon.
	 */
	public function render_coupon_dashboard() {
		if ( ! is_user_logged_in() ) {
			echo '<p>' . esc_html__( 'Please log in to see available coupons.', 'lovecatz-wc' ) . '</p>';
			return;
		}

		$promo_enabled = get_option( 'lwc_promo_enabled', 'yes' );
		if ( 'yes' !== $promo_enabled ) {
			echo '<p>' . esc_html__( 'Promo features are disabled.', 'lovecatz-wc' ) . '</p>';
			return;
		}

		$prefix = get_option( 'lwc_promo_prefix', 'PROMO' );
		$message = get_option( 'lwc_promo_message', __( 'Enjoy special promo offers today!', 'lovecatz-wc' ) );
		$image_id = get_option( 'lwc_promo_image_id', 0 );
		$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'large' ) : LWC_PLUGIN_URL . 'assets/2026_VOUCHER-REORDER_FINAL.webp';

		$coupon_code = $this->get_or_create_promo_coupon( $prefix );

		echo '<div class="lwc-promo-dashboard">';
		echo '<div class="lwc-promo-dashboard-header">';
		echo '<h2>' . esc_html__( 'Coupons & Promo', 'lovecatz-wc' ) . '</h2>';
		echo '<p>' . esc_html( $message ) . '</p>';
		echo '</div>';

		echo '<div class="lwc-promo-dashboard-grid">';
		echo '<button class="lwc-promo-card" type="button" data-coupon="' . esc_attr( $coupon_code ) . '">';
		echo '<img src="' . esc_url( $image_url ) . '" alt="' . esc_attr__( 'Promo coupon image', 'lovecatz-wc' ) . '" />';
		echo '<div class="lwc-promo-card-body">';
		echo '<strong>' . esc_html( $coupon_code ) . '</strong>';
		echo '<span>' . esc_html__( 'Click to copy and apply at checkout', 'lovecatz-wc' ) . '</span>';
		echo '</div>';
		echo '</button>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Get or create the promo coupon code.
	 *
	 * @param string $prefix Coupon prefix.
	 * @return string
	 */
	private function get_or_create_promo_coupon( $prefix ) {
		$coupon_code = get_option( 'lwc_promo_coupon_code', '' );
		$coupon_id = get_option( 'lwc_promo_coupon_id', 0 );

		if ( $coupon_code && $coupon_id ) {
			$coupon = wc_get_coupon( $coupon_id );
			if ( $coupon && $coupon->get_code() === $coupon_code ) {
				return $coupon_code;
			}
		}

		if ( $coupon_code ) {
			$coupon_id = wc_get_coupon_id_by_code( $coupon_code );
			if ( $coupon_id ) {
				update_option( 'lwc_promo_coupon_id', $coupon_id );
				return $coupon_code;
			}
		}

		$coupon_code = $this->generate_coupon_code( $prefix );
		$coupon_id = $this->create_promo_coupon( $coupon_code );

		if ( $coupon_id ) {
			update_option( 'lwc_promo_coupon_code', $coupon_code );
			update_option( 'lwc_promo_coupon_id', $coupon_id );
		}

		return $coupon_code;
	}

	/**
	 * Create a new WooCommerce promo coupon.
	 *
	 * @param string $coupon_code Coupon code.
	 * @return int|false
	 */
	private function create_promo_coupon( $coupon_code ) {
		if ( ! class_exists( 'WC_Coupon' ) ) {
			return false;
		}

		$coupon = new WC_Coupon();
		$coupon->set_code( sanitize_text_field( $coupon_code ) );
		$coupon->set_amount( 10 );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_description( __( 'Promo coupon generated by LoveCatz.', 'lovecatz-wc' ) );
		$coupon->set_individual_use( false );
		$coupon->set_usage_limit( 1 );
		$coupon->set_date_expires( date( 'Y-m-d', strtotime( '+30 days' ) ) );
		$coupon->set_free_shipping( false );
		$coupon->save();

		return $coupon->get_id();
	}

	/**
	 * Generate a coupon code from the configured prefix.
	 *
	 * @param string $prefix Coupon prefix.
	 * @return string
	 */
	private function generate_coupon_code( $prefix ) {
		$prefix = sanitize_text_field( strtoupper( $prefix ) );
		$random = wp_generate_password( 6, false, false );
		return $prefix . '-' . strtoupper( $random );
	}

}
