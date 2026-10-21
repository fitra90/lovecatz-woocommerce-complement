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

	/** Promo coupon objects cached for one request. */
	private $promo_coupons_cache = null;

	/**
	 * Initialize frontend hooks.
	 */
	public function init() {
		add_action( 'init', array( $this, 'register_coupon_endpoint' ), 1 );
		add_filter( 'query_vars', array( $this, 'register_coupon_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_coupon_account_menu_item' ) );
		add_action( 'woocommerce_account_coupon_endpoint', array( $this, 'render_coupon_dashboard' ) );
		add_action( 'woocommerce_before_cart_totals', array( $this, 'render_checkout_coupons' ), 15 );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'render_checkout_coupons' ), 5 );
		add_action( 'woocommerce_review_order_before_payment', array( $this, 'render_checkout_coupons' ), 10 );
		// Cart, checkout, cart block and checkout block all hide their coupon
		// field when this reports false.
		add_filter( 'woocommerce_coupons_enabled', array( $this, 'hide_native_coupon_ui_for_guests' ) );
		// A guest who logs in through the promo invitation returns to the
		// cart/checkout they came from instead of the account dashboard.
		add_filter( 'woocommerce_login_redirect', array( $this, 'promo_login_redirect' ), 10, 2 );
		add_filter( 'woocommerce_registration_redirect', array( $this, 'promo_registration_redirect' ) );
		// WooCommerce's My Account forms carry no redirect field, so the
		// destination is repeated in a hidden input for shoppers who arrive
		// through the promo invitation.
		add_action( 'woocommerce_login_form', array( $this, 'render_promo_redirect_field' ) );
		add_action( 'woocommerce_register_form', array( $this, 'render_promo_redirect_field' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		// Shortcode fallback so admin can place promo dashboard on any page if endpoint rewrites fail.
		add_shortcode( 'lwc_promo_dashboard', array( $this, 'promo_dashboard_shortcode' ) );
	}

	/**
	 * Hide WooCommerce's own coupon form from visitors without an account.
	 *
	 * This dashboard class only runs on the frontend, so staff working in
	 * wp-admin keep the native coupon field everywhere.
	 *
	 * @param bool $enabled Whether WooCommerce treats coupons as enabled.
	 * @return bool
	 */
	public function hide_native_coupon_ui_for_guests( $enabled ) {
		if ( $enabled && ! is_user_logged_in() ) {
			return false;
		}

		return $enabled;
	}

	/**
	 * Read the destination a guest asked for through the promo invitation.
	 *
	 * Only a URL on this site is accepted: wp_validate_redirect() returns an
	 * empty string for a foreign host or a non-http scheme, which keeps the
	 * parameter from turning into an open redirect.
	 *
	 * @return string Internal URL, or an empty string when nothing valid was requested.
	 */
	private function get_requested_promo_redirect() {
		$requested = '';

		if ( ! empty( $_GET['redirect_to'] ) && is_string( $_GET['redirect_to'] ) ) {
			$requested = wp_unslash( $_GET['redirect_to'] );
		} elseif ( ! empty( $_POST['redirect_to'] ) && is_string( $_POST['redirect_to'] ) ) {
			$requested = wp_unslash( $_POST['redirect_to'] );
		}

		if ( ! $requested ) {
			return '';
		}

		$validated = wp_validate_redirect( wp_sanitize_redirect( $requested ), '' );

		return $validated ? $validated : '';
	}

	/**
	 * Send a guest back to the page they were shopping on after logging in.
	 *
	 * WooCommerce's own form honours a POST redirect and the referer, so a
	 * promo invitation that only passes a query argument would otherwise land
	 * the new customer on the account dashboard and lose the cart. The
	 * destination is only accepted when it is a sanitized URL on this site.
	 *
	 * @param string  $redirect Destination WooCommerce calculated.
	 * @param WP_User $user     User that has just logged in.
	 * @return string
	 */
	public function promo_login_redirect( $redirect, $user ) {
		unset( $user );

		$requested = $this->get_requested_promo_redirect();
		if ( $requested ) {
			return $requested;
		}

		return $redirect;
	}

	/**
	 * Send a new customer back to the page they were shopping on after registering.
	 *
	 * Registration is the path most promo shoppers take, so it has to honour
	 * the same destination as the login form.
	 *
	 * @param string $redirect Destination WooCommerce calculated.
	 * @return string
	 */
	public function promo_registration_redirect( $redirect ) {
		$requested = $this->get_requested_promo_redirect();
		if ( $requested ) {
			return $requested;
		}

		return $redirect;
	}

	/**
	 * Repeat the requested destination as a hidden form field.
	 *
	 * WooCommerce's My Account templates post to the current URL without a
	 * redirect field, so the query argument would be lost between the form
	 * and the handler. Only shoppers who actually arrived through the promo
	 * invitation get the field, so everyone else keeps the stock behaviour.
	 */
	public function render_promo_redirect_field() {
		$requested = $this->get_requested_promo_redirect();
		if ( ! $requested ) {
			return;
		}

		printf(
			'<input type="hidden" name="redirect" value="%s" />',
			esc_url( $requested )
		);
	}

	/**
	 * Build the My Account URL shared by every guest promo invitation.
	 *
	 * Guests land on the My Account page (login plus registration) with a
	 * redirect back to the cart or checkout they were shopping on, so signing
	 * up costs them no progress.
	 *
	 * @param string $redirect Page to return to after login; defaults to the current page.
	 * @return string
	 */
	private function get_promo_login_url( $redirect = '' ) {
		$account = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '';
		if ( ! $account ) {
			$account = wp_login_url();
		}

		if ( ! $redirect ) {
			$redirect = is_cart() ? wc_get_cart_url() : wc_get_checkout_url();
		}

		// add_query_arg() encodes the value itself; pre-encoding here would
		// double-encode the URL and break the redirect after login.
		return add_query_arg( 'redirect_to', $redirect, $account );
	}

	/**
	 * Render the login invitation for a visitor without an account.
	 *
	 * WooCommerce's coupon field is hidden for these shoppers, so the
	 * invitation stands exactly where they would have typed a code: it says
	 * that promos exist for members and links to My Account to log in or
	 * register. It is only shown while at least one promo is actually live.
	 */
	private function render_guest_promo_login_prompt() {
		if ( is_user_logged_in() || ! $this->has_account_only_checkout_promos() ) {
			return;
		}

		printf(
			'<div class="lwc-checkout-account-promo lwc-guest-promo-login"><span class="lwc-checkout-account-promo__icon" aria-hidden="true">%%</span><span class="lwc-checkout-account-promo__content"><strong>%1$s</strong><span>%2$s</span></span><a class="lwc-checkout-account-promo__link" href="%3$s">%4$s</a></div>',
			esc_html__( 'Promo khusus menantimu!', 'lovecatz-wc' ),
			esc_html__( 'Login atau buat akun gratis untuk melihat dan memakai kupon promo.', 'lovecatz-wc' ),
			esc_url( $this->get_promo_login_url() ),
			esc_html__( 'Login / Daftar', 'lovecatz-wc' )
		);
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
		if ( ! function_exists( 'is_account_page' ) || ( ! is_account_page() && ! is_checkout() && ! is_cart() ) ) {
			return;
		}

		$style_path    = LWC_PLUGIN_DIR . 'promo/promo-dashboard.css';
		$script_path   = LWC_PLUGIN_DIR . 'promo/promo-dashboard.js';
		$style_version = file_exists( $style_path ) ? (string) filemtime( $style_path ) : LWC_VERSION;
		$script_version = file_exists( $script_path ) ? (string) filemtime( $script_path ) : LWC_VERSION;

		wp_enqueue_style( 'lwc-promo-dashboard', LWC_PLUGIN_URL . 'promo/promo-dashboard.css', array(), $style_version );
		wp_enqueue_script( 'lwc-promo-dashboard', LWC_PLUGIN_URL . 'promo/promo-dashboard.js', array( 'jquery' ), $script_version, true );
		wp_localize_script(
			'lwc-promo-dashboard',
			'lwcPromoDashboard',
			array(
				'couponInputSelector' => '.coupon input[name="coupon_code"], .wc-block-components-totals-coupon input',
				'applyButtonSelector' => '.coupon .button, .wc-block-components-totals-coupon button[type="submit"]',
				'checkoutUrl'         => esc_url_raw( wc_get_checkout_url() ),
				'accountUrl'          => esc_url_raw( $this->get_promo_login_url() ),
				'isGuest'             => ! is_user_logged_in(),
				'hasAccountPromos'    => $this->has_account_only_checkout_promos(),
				'coupons'             => $this->get_checkout_coupon_data(),
				'appliedCouponRules'  => $this->get_applied_coupon_rules(),
			)
		);
	}

	/** Combination rules for every applied coupon, including coupons outside this plugin. */
	private function get_applied_coupon_rules() {
		$rules = array();
		if ( ! WC()->cart ) {
			return $rules;
		}

		foreach ( WC()->cart->get_applied_coupons() as $code ) {
			$coupon = new WC_Coupon( $code );
			if ( ! $coupon->get_id() ) {
				continue;
			}
			$rules[] = array(
				'id'                   => $coupon->get_id(),
				'code'                 => $coupon->get_code(),
				'allowCombination'     => class_exists( 'LWC_Promo_Discounts' ) ? LWC_Promo_Discounts::allows_combination( $coupon ) : ! $coupon->get_individual_use(),
				'combinationCouponIds' => class_exists( 'LWC_Promo_Discounts' ) ? LWC_Promo_Discounts::get_combination_coupon_ids( $coupon ) : null,
			);
		}

		return $rules;
	}

	/**
	 * Check whether a guest should be invited to unlock an active promo.
	 * Since every promo now requires an account, any live coupon counts —
	 * the details stay private until the customer signs in.
	 *
	 * @return bool
	 */
	private function has_account_only_checkout_promos() {
		if ( is_user_logged_in() ) {
			return false;
		}

		foreach ( $this->get_promo_coupons() as $coupon ) {
			if ( 'publish' !== $coupon->get_status() ) {
				continue;
			}

			if ( $this->is_coupon_expired( $coupon ) ) {
				continue;
			}

			if ( $coupon->get_usage_limit() && $coupon->get_usage_count() >= $coupon->get_usage_limit() ) {
				continue;
			}

			return true;
		}

		return false;
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
	public function promo_dashboard_shortcode( $atts = array() ) {
		// Return output so shortcode can be used. Ensure user sees same content as account endpoint.
		ob_start();
		$this->render_coupon_dashboard();
		return ob_get_clean();
	}

	public function render_coupon_dashboard() {
		if ( ! is_user_logged_in() ) {
			echo '<div class="lwc-promo-dashboard lwc-promo-dashboard--guest">';
			echo '<div class="lwc-promo-dashboard-header">';
			echo '<h2>' . esc_html__( 'Coupons & Promo', 'lovecatz-wc' ) . '</h2>';
			echo '<p>' . esc_html__( 'Promo coupons are exclusive for registered customers.', 'lovecatz-wc' ) . '</p>';
			echo '</div>';
			echo '<p>' . esc_html__( 'Log in or create a free account to see and redeem the promos that are waiting for you.', 'lovecatz-wc' ) . '</p>';
			echo '<p class="lwc-promo-dashboard-actions"><a class="button" href="' . esc_url( $this->get_promo_login_url() ) . '">' . esc_html__( 'Login / Register', 'lovecatz-wc' ) . '</a></p>';
			echo '</div>';
			return;
		}

		$shop_url = wc_get_checkout_url();

		$coupons = $this->get_promo_coupons();
		echo '<div class="lwc-promo-dashboard">';
		echo '<div class="lwc-promo-dashboard-header">';
		echo '<h2>' . esc_html__( 'Coupons & Promo', 'lovecatz-wc' ) . '</h2>';
		echo '<p>' . esc_html__( 'Choose an available offer and it will be applied at checkout.', 'lovecatz-wc' ) . '</p>';
		echo '</div>';

		if ( empty( $coupons ) ) {
			echo '<p>' . esc_html__( 'No promo coupons are currently available. Check back later for new offers.', 'lovecatz-wc' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<div class="lwc-promo-dashboard-grid lwc-promo-dashboard-grid--account" role="list">';
		foreach ( $coupons as $coupon ) {
			$coupon_code = $coupon->get_code();
			$available = $this->is_coupon_available_for_user( $coupon, get_current_user_id() );
			$image_id = absint( get_post_meta( $coupon->get_id(), $available ? '_lwc_promo_active_image_id' : '_lwc_promo_disabled_image_id', true ) );
			$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'large' ) : LWC_PLUGIN_URL . 'assets/2026_VOUCHER-REORDER_FINAL.webp';
			$tag = $available ? 'a' : 'div';
			echo '<' . $tag . ' class="lwc-promo-card lwc-promo-card--account' . ( $available ? '' : ' is-disabled' ) . '" role="listitem"' . ( $available ? ' href="' . esc_url( $shop_url ) . '" data-coupon="' . esc_attr( $coupon_code ) . '"' : ' aria-disabled="true"' ) . '>';
			echo '<img src="' . esc_url( $image_url ) . '" alt="' . esc_attr__( 'Promo coupon image', 'lovecatz-wc' ) . '" loading="lazy" decoding="async" />';
			echo '<div class="lwc-promo-card-body">';
			echo '<strong>' . esc_html( $coupon_code ) . '</strong>';
			echo '<span>' . esc_html( $available ? __( 'Click to use this coupon at checkout.', 'lovecatz-wc' ) : __( 'This coupon is no longer available.', 'lovecatz-wc' ) ) . '</span>';
			echo '</div>';
			echo '</' . $tag . '>';
		}
		echo '</div>';
		echo '</div>';
	}

	/** Render eligible promo cards above the checkout form. */
	public function render_checkout_coupons() {
		static $rendered = false;

		if ( $rendered ) {
			return;
		}

		$rendered = true;

		if ( ! is_user_logged_in() ) {
			$this->render_guest_promo_login_prompt();
			return;
		}

		$coupons = array_filter(
			$this->get_promo_coupons(),
			function( $coupon ) {
				return $this->is_coupon_available_for_user( $coupon, get_current_user_id() );
			}
		);

		if ( empty( $coupons ) ) {
			return;
		}

		$context = is_cart() ? __( 'Cart coupons', 'lovecatz-wc' ) : __( 'Available coupons', 'lovecatz-wc' );
		echo '<section class="lwc-checkout-promos"><h3>' . esc_html( $context ) . '</h3><div class="lwc-promo-dashboard-grid">';
		foreach ( $coupons as $coupon ) {
			$image_id = absint( get_post_meta( $coupon->get_id(), '_lwc_promo_active_image_id', true ) );
			$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'large' ) : LWC_PLUGIN_URL . 'assets/2026_VOUCHER-REORDER_FINAL.webp';
			$target_url = is_cart() ? wc_get_cart_url() : wc_get_checkout_url();
			echo '<a class="lwc-promo-card" href="' . esc_url( $target_url ) . '" data-coupon="' . esc_attr( $coupon->get_code() ) . '"><img src="' . esc_url( $image_url ) . '" alt="' . esc_attr__( 'Promo coupon image', 'lovecatz-wc' ) . '" /><div class="lwc-promo-card-body"><strong>' . esc_html( $coupon->get_code() ) . '</strong><span>' . esc_html__( 'Click to apply this coupon.', 'lovecatz-wc' ) . '</span></div></a>';
		}
		echo '</div></section>';
	}

	/**
	 * Provide selectable coupons plus expired coupons for the current customer.
	 *
	 * This data is used by the Checkout Block modal; WooCommerce still validates
	 * the coupon when its native Apply button is triggered. Guests get nothing:
	 * the picker never opens for them and the login invitation is driven by
	 * hasAccountPromos instead.
	 *
	 * @return array
	 */
	private function get_checkout_coupon_data() {
		if ( ! is_user_logged_in() ) {
			return array();
		}

		$coupons = array();
		foreach ( $this->get_promo_coupons() as $coupon ) {
			$expired = $this->is_coupon_expired( $coupon );
			if ( ! $this->is_coupon_available_at_checkout( $coupon, $expired ) ) {
				continue;
			}

			$amount = $coupon->get_amount();
			// Resolve the cap in the shopper currency so the card states the same
			// ceiling the cart will actually enforce.
			$maximum = class_exists( 'LWC_Promo_Discounts' )
				? LWC_Promo_Discounts::get_maximum_discount( $coupon )
				: (float) get_post_meta( $coupon->get_id(), '_lwc_promo_maximum_discount', true );

			if ( class_exists( 'LWC_Promo_Discounts' ) && $coupon->is_type( LWC_Promo_Discounts::FREE_SHIPPING_TYPE ) ) {
				$description = $maximum > 0
					? sprintf( __( 'Free shipping up to %s', 'lovecatz-wc' ), $this->format_money( $maximum ) )
					: __( 'Free shipping', 'lovecatz-wc' );
			} else {
				$description = 'percent' === $coupon->get_discount_type()
					? sprintf( __( '%s%% discount', 'lovecatz-wc' ), $amount )
					: sprintf( __( '%s off', 'lovecatz-wc' ), $this->format_money( $amount ) );
				if ( 'percent' === $coupon->get_discount_type() && $maximum > 0 ) {
					$description .= ' · ' . sprintf( __( 'Maximum %s', 'lovecatz-wc' ), $this->format_money( $maximum ) );
				}
			}
			$minimum = $coupon->get_minimum_amount();
			if ( $minimum ) {
				$description .= ' · ' . sprintf( __( 'Min. order %s', 'lovecatz-wc' ), wp_strip_all_tags( wc_price( $minimum ) ) );
			}
			$image_meta_key = $expired ? '_lwc_promo_disabled_image_id' : '_lwc_promo_active_image_id';
			$image_id = absint( get_post_meta( $coupon->get_id(), $image_meta_key, true ) );
			$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : LWC_PLUGIN_URL . 'assets/2026_VOUCHER-REORDER_FINAL.webp';

			$coupons[] = array(
				'id'            => $coupon->get_id(),
				'code'          => $coupon->get_code(),
				'description'   => $description,
				'image'         => $image_url,
				'applied'       => ! $expired && WC()->cart && WC()->cart->has_discount( $coupon->get_code() ),
				'expired'       => $expired,
				'allowCombination' => class_exists( 'LWC_Promo_Discounts' ) ? LWC_Promo_Discounts::allows_combination( $coupon ) : ! $coupon->get_individual_use(),
				'combinationCouponIds' => class_exists( 'LWC_Promo_Discounts' ) ? LWC_Promo_Discounts::get_combination_coupon_ids( $coupon ) : null,
			);
		}

		return $coupons;
	}

	/**
	 * Plain-text money string in the shopper currency.
	 *
	 * The checkout card escapes this description before injecting it, so the
	 * currency entity wc_price() emits for symbols such as USD must be decoded
	 * first or the customer would read "&#36;9" instead of "$9".
	 *
	 * @param float $amount Amount in the active currency.
	 * @return string
	 */
	private function format_money( $amount ) {
		if ( class_exists( 'LWC_Promo_Discounts' ) ) {
			return LWC_Promo_Discounts::format_money( $amount );
		}

		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Check a promo can be shown in checkout for the current visitor.
	 * Discounts are a registered-customer benefit, so a guest is never offered
	 * one; final validation remains WooCommerce's responsibility when applying
	 * the code.
	 *
	 * @param WC_Coupon $coupon        Coupon instance.
	 * @param bool      $ignore_expiry Whether an expired coupon may remain visible as disabled.
	 * @return bool
	 */
	private function is_coupon_available_at_checkout( $coupon, $ignore_expiry = false ) {
		if ( ! $coupon instanceof WC_Coupon || 'publish' !== $coupon->get_status() ) {
			return false;
		}

		if ( ! is_user_logged_in() ) {
			return false;
		}

		return $this->is_coupon_available_for_user( $coupon, get_current_user_id(), $ignore_expiry );
	}

	/** Determine whether a coupon has passed its configured expiry date. */
	private function is_coupon_expired( $coupon ) {
		return $coupon instanceof WC_Coupon
			&& $coupon->get_date_expires()
			&& $coupon->get_date_expires()->getTimestamp() < current_time( 'timestamp' );
	}

	/**
	 * Get the current shop URL for product browsing.
	 *
	 * @return string
	 */
	private function get_shop_url() {
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$shop_url = wc_get_page_permalink( 'shop' );
		} else {
			$shop_url = get_post_type_archive_link( 'product' );
		}

		if ( ! $shop_url ) {
			$shop_url = home_url( '/shop/' );
		}

		return $shop_url;
	}

	/**
	 * Return promo coupons created by this plugin.
	 *
	 * @return array
	 */
	private function get_promo_coupons() {
		if ( is_array( $this->promo_coupons_cache ) ) {
			return $this->promo_coupons_cache;
		}

		if ( ! class_exists( 'WC_Coupon' ) ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'   => '_lwc_promo_created',
						'value' => '1',
					),
				),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$coupons = array();
		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				if ( class_exists( 'WC_Coupon' ) ) {
					$coupon_obj = new WC_Coupon( $post->ID );
					if ( is_object( $coupon_obj ) && $coupon_obj->get_id() > 0 ) {
						$coupons[] = $coupon_obj;
					}
				}
			}
			wp_reset_postdata();
		}

		$this->promo_coupons_cache = $coupons;
		return $this->promo_coupons_cache;
	}

	/**
	 * Determine whether the coupon may be selected by the current user.
	 *
	 * @param WC_Coupon $coupon        Coupon instance.
	 * @param int       $user_id       User ID.
	 * @param bool      $ignore_expiry Whether expiry may be ignored for disabled presentation.
	 * @return bool
	 */
	private function is_coupon_available_for_user( $coupon, $user_id, $ignore_expiry = false ) {
		if ( ! $coupon instanceof WC_Coupon || 'publish' !== $coupon->get_status() || ! $user_id ) {
			return false;
		}

		if ( ! $ignore_expiry && $this->is_coupon_expired( $coupon ) ) {
			return false;
		}

		if ( $coupon->get_usage_limit() && $coupon->get_usage_count() >= $coupon->get_usage_limit() ) {
			return false;
		}

		$emails = $coupon->get_email_restrictions();
		$user = get_userdata( $user_id );
		if ( ! empty( $emails ) && ( ! $user || ! in_array( $user->user_email, $emails, true ) ) ) {
			return false;
		}

		if ( $coupon->get_usage_limit_per_user() ) {
			$used_by = $coupon->get_used_by();
			$user_uses = 0;
			foreach ( $used_by as $used ) {
				if ( (string) $user_id === (string) $used || ( $user && $user->user_email === $used ) ) {
					++$user_uses;
				}
			}
			if ( $user_uses >= $coupon->get_usage_limit_per_user() ) {
				return false;
			}
		}

		return true;
	}

}
