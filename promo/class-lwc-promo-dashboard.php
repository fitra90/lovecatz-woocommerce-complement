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
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		// Shortcode fallback so admin can place promo dashboard on any page if endpoint rewrites fail.
		add_shortcode( 'lwc_promo_dashboard', array( $this, 'promo_dashboard_shortcode' ) );
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

		wp_enqueue_style( 'lwc-promo-dashboard', LWC_PLUGIN_URL . 'promo/promo-dashboard.css', array(), LWC_VERSION );
		wp_enqueue_script( 'lwc-promo-dashboard', LWC_PLUGIN_URL . 'promo/promo-dashboard.js', array( 'jquery' ), LWC_VERSION, true );
		wp_localize_script(
			'lwc-promo-dashboard',
			'lwcPromoDashboard',
			array(
				'couponInputSelector' => '.coupon input[name="coupon_code"], .wc-block-components-totals-coupon input',
				'applyButtonSelector' => '.coupon .button, .wc-block-components-totals-coupon button[type="submit"]',
				'checkoutUrl'         => esc_url_raw( wc_get_checkout_url() ),
				'accountUrl'          => esc_url_raw( add_query_arg( 'redirect_to', wc_get_checkout_url(), wc_get_page_permalink( 'myaccount' ) ) ),
				'isGuest'             => ! is_user_logged_in(),
				'hasAccountPromos'    => $this->has_account_only_checkout_promos(),
				'coupons'             => $this->get_checkout_coupon_data(),
			)
		);
	}

	/**
	 * Check whether a guest can be invited to unlock an active account promo.
	 * Coupon details stay private until the customer signs in.
	 *
	 * @return bool
	 */
	private function has_account_only_checkout_promos() {
		if ( is_user_logged_in() ) {
			return false;
		}

		foreach ( $this->get_promo_coupons() as $coupon ) {
			if ( $coupon->get_date_expires() && $coupon->get_date_expires()->getTimestamp() < current_time( 'timestamp' ) ) {
				continue;
			}

			if ( $coupon->get_usage_limit() && $coupon->get_usage_count() >= $coupon->get_usage_limit() ) {
				continue;
			}

			$eligible_users = array_filter( array_map( 'absint', (array) get_post_meta( $coupon->get_id(), '_lwc_promo_eligible_user_ids', true ) ) );
			if ( ! empty( $eligible_users ) || ! empty( $coupon->get_email_restrictions() ) ) {
				return true;
			}
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
			echo '<p>' . esc_html__( 'Please log in to see available coupons.', 'lovecatz-wc' ) . '</p>';
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

		if ( $rendered || ! is_user_logged_in() ) {
			return;
		}

		$rendered = true;

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
	 * Provide only coupons the current customer may select at checkout.
	 *
	 * This data is used by the Checkout Block modal; WooCommerce still validates
	 * the coupon when its native Apply button is triggered.
	 *
	 * @return array
	 */
	private function get_checkout_coupon_data() {
		$coupons = array();
		foreach ( $this->get_promo_coupons() as $coupon ) {
			if ( ! $this->is_coupon_available_at_checkout( $coupon ) ) {
				continue;
			}

			$amount = $coupon->get_amount();
			if ( class_exists( 'LWC_Promo_Discounts' ) && $coupon->is_type( LWC_Promo_Discounts::FREE_SHIPPING_TYPE ) ) {
				$maximum = (float) get_post_meta( $coupon->get_id(), '_lwc_promo_maximum_discount', true );
				$description = $maximum > 0
					? sprintf( __( 'Free shipping up to %s', 'lovecatz-wc' ), wp_strip_all_tags( wc_price( $maximum ) ) )
					: __( 'Free shipping', 'lovecatz-wc' );
			} else {
				$description = 'percent' === $coupon->get_discount_type()
					? sprintf( __( '%s%% discount', 'lovecatz-wc' ), $amount )
					: sprintf( __( '%s off', 'lovecatz-wc' ), wp_strip_all_tags( wc_price( $amount ) ) );
				$maximum = (float) get_post_meta( $coupon->get_id(), '_lwc_promo_maximum_discount', true );
				if ( 'percent' === $coupon->get_discount_type() && $maximum > 0 ) {
					$description .= ' · ' . sprintf( __( 'Maximum %s', 'lovecatz-wc' ), wp_strip_all_tags( wc_price( $maximum ) ) );
				}
			}
			$minimum = $coupon->get_minimum_amount();
			if ( $minimum ) {
				$description .= ' · ' . sprintf( __( 'Min. order %s', 'lovecatz-wc' ), wp_strip_all_tags( wc_price( $minimum ) ) );
			}
			$image_id = absint( get_post_meta( $coupon->get_id(), '_lwc_promo_active_image_id', true ) );
			$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : LWC_PLUGIN_URL . 'assets/2026_VOUCHER-REORDER_FINAL.webp';

			$coupons[] = array(
				'code'        => $coupon->get_code(),
				'description' => $description,
				'image'       => $image_url,
			);
		}

		return $coupons;
	}

	/**
	 * Check a promo can be shown in checkout for the current visitor.
	 * Guests may see only coupons without account/email restrictions; final
	 * validation remains WooCommerce's responsibility when applying the code.
	 *
	 * @param WC_Coupon $coupon Coupon instance.
	 * @return bool
	 */
	private function is_coupon_available_at_checkout( $coupon ) {
		if ( ! $coupon instanceof WC_Coupon ) {
			return false;
		}

		if ( is_user_logged_in() ) {
			return $this->is_coupon_available_for_user( $coupon, get_current_user_id() );
		}

		if ( $coupon->get_date_expires() && $coupon->get_date_expires()->getTimestamp() < current_time( 'timestamp' ) ) {
			return false;
		}

		if ( $coupon->get_usage_limit() && $coupon->get_usage_count() >= $coupon->get_usage_limit() ) {
			return false;
		}

		return empty( $coupon->get_email_restrictions() );
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
	 * @param WC_Coupon $coupon  Coupon instance.
	 * @param int       $user_id User ID.
	 * @return bool
	 */
	private function is_coupon_available_for_user( $coupon, $user_id ) {
		if ( ! $coupon instanceof WC_Coupon || ! $user_id ) {
			return false;
		}

		if ( $coupon->get_date_expires() && $coupon->get_date_expires()->getTimestamp() < current_time( 'timestamp' ) ) {
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
