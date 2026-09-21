<?php
/**
 * Registry of the courier integrations that can be assigned to an order.
 *
 * Detection is evidence based: a courier only counts as installed when its
 * plugin is active on this WordPress install *and* WooCommerce knows about the
 * shipping method (the class is loaded, the method is registered, or a shipping
 * zone already contains one of its instances).
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_Courier_Registry {

	/**
	 * Cached detection report for the current request.
	 *
	 * @var array|null
	 */
	private static $report = null;

	/**
	 * Courier definitions.
	 *
	 * `plugin_files` are candidate plugin basenames; the first one that exists
	 * on disk is treated as this courier's plugin. `classes` are the shipping
	 * method classes the courier may register; the first loaded one wins.
	 *
	 * @return array slug => definition
	 */
	public static function get_definitions() {
		$definitions = array(
			'jt_express' => array(
				'slug'          => 'jt_express',
				'label'         => __( 'J&T Express', 'lovecatz-wc' ),
				'source'        => 'internal',
				'plugin_name'   => __( 'LoveCatz WooCommerce Complement', 'lovecatz-wc' ),
				'plugin_files'  => array( 'lovecatz-woocommerce-complement/lovecatz-woocommerce-complement.php' ),
				'classes'       => array( 'LWC_Shipping_JT_Express' ),
				'method_ids'    => array( 'lwc_jt_express', 'lwc_jt' ),
				'live_rates'    => true,
				'global_rates'  => true,
			),
			'jt_cargo'   => array(
				'slug'          => 'jt_cargo',
				'label'         => __( 'J&T Cargo', 'lovecatz-wc' ),
				'source'        => 'internal',
				'plugin_name'   => __( 'LoveCatz WooCommerce Complement', 'lovecatz-wc' ),
				'plugin_files'  => array( 'lovecatz-woocommerce-complement/lovecatz-woocommerce-complement.php' ),
				'classes'       => array( 'LWC_Shipping_JTC' ),
				'method_ids'    => array( 'lwc_jt_cargo' ),
				'live_rates'    => false,
				'global_rates'  => false,
			),
			'jne'        => array(
				'slug'          => 'jne',
				'label'         => __( 'JNE', 'lovecatz-wc' ),
				'source'        => 'external',
				'plugin_name'   => __( 'JNE Shipping Official', 'lovecatz-wc' ),
				'plugin_files'  => array( 'jne-shipping-official/jne-shipping-official.php' ),
				'classes'       => array( 'Jneshof_Admin_Shipping_Method_View' ),
				'method_ids'    => array( 'jneshof_shipping' ),
				'live_rates'    => true,
				'global_rates'  => false,
			),
			'fedex'      => array(
				'slug'          => 'fedex',
				'label'         => __( 'FedEx', 'lovecatz-wc' ),
				'source'        => 'internal',
				'plugin_name'   => __( 'LoveCatz WooCommerce Complement', 'lovecatz-wc' ),
				'plugin_files'  => array( 'lovecatz-woocommerce-complement/lovecatz-woocommerce-complement.php' ),
				'classes'       => array( 'LWC_Shipping_FedEx' ),
				'method_ids'    => array( 'lwc_fedex', 'lwc_shipping_fedex' ),
				'live_rates'    => true,
				'global_rates'  => false,
			),
			'rayspeed'   => array(
				'slug'          => 'rayspeed',
				'label'         => __( 'RaySpeed', 'lovecatz-wc' ),
				'source'        => 'internal',
				'plugin_name'   => __( 'LoveCatz WooCommerce Complement', 'lovecatz-wc' ),
				'plugin_files'  => array( 'lovecatz-woocommerce-complement/lovecatz-woocommerce-complement.php' ),
				'classes'       => array( 'LWC_Shipping_RaySpeed' ),
				'method_ids'    => array( 'lwc_rayspeed' ),
				'live_rates'    => true,
				'global_rates'  => false,
			),
		);

		/**
		 * Filter the courier definitions known to the switcher.
		 *
		 * @param array $definitions Courier definitions keyed by slug.
		 */
		return apply_filters( 'lwc_courier_registry_definitions', $definitions );
	}

	/**
	 * Full detection report for every known courier.
	 *
	 * @return array slug => detection result
	 */
	public static function detect() {
		if ( null !== self::$report ) {
			return self::$report;
		}

		$registered    = self::registered_method_ids();
		$zone_methods  = self::zone_method_instances();
		$report        = array();

		foreach ( self::get_definitions() as $slug => $definition ) {
			$plugin_file   = self::first_existing_plugin( $definition );
			$present       = '' !== $plugin_file;
			$active        = $present && self::is_plugin_active( $plugin_file );
			$loaded_class  = self::first_loaded_class( $definition );
			$registered_hit = array_values( array_intersect( $definition['method_ids'], $registered ) );
			$zone_hits     = isset( $zone_methods[ $slug ] ) ? $zone_methods[ $slug ] : array();

			// A courier is installed when its plugin is active here and
			// WooCommerce can actually resolve the shipping method.
			$installed = $active && ( '' !== $loaded_class || ! empty( $registered_hit ) || ! empty( $zone_hits ) );

			$reasons = array();
			if ( ! $present ) {
				$reasons[] = sprintf(
					/* translators: %s: plugin file name */
					__( 'Plugin file %s is not present in wp-content/plugins.', 'lovecatz-wc' ),
					$definition['plugin_files'][0]
				);
			} elseif ( ! $active ) {
				$reasons[] = __( 'The plugin is installed but not activated.', 'lovecatz-wc' );
			} elseif ( ! $installed ) {
				$reasons[] = __( 'The plugin is active but WooCommerce has no shipping method for it.', 'lovecatz-wc' );
			}

			$enabled_in_zone = false;
			foreach ( $zone_hits as $hit ) {
				if ( ! empty( $hit['enabled'] ) ) {
					$enabled_in_zone = true;
					break;
				}
			}

			if ( $installed && ! $enabled_in_zone && empty( $definition['global_rates'] ) ) {
				$reasons[] = __( 'No enabled shipping-zone instance found; live rates may be unavailable.', 'lovecatz-wc' );
			}

			$report[ $slug ] = array(
				'slug'              => $slug,
				'label'             => $definition['label'],
				'source'            => $definition['source'],
				'plugin_name'       => $definition['plugin_name'],
				'plugin_file'       => '' !== $plugin_file ? $plugin_file : $definition['plugin_files'][0],
				'plugin_files'      => $definition['plugin_files'],
				'method_ids'        => $definition['method_ids'],
				'installed'         => $installed,
				'plugin_present'    => $present,
				'plugin_active'     => $active,
				'class'             => $loaded_class,
				'registered_methods'=> $registered_hit,
				'zone_instances'    => $zone_hits,
				'in_zone'           => $enabled_in_zone,
				'live_rates'        => ! empty( $definition['live_rates'] ) && ( $enabled_in_zone || ! empty( $definition['global_rates'] ) ),
				// Whether this courier derives its price from the destination
				// address at all (used to gate tariffs on an incomplete address).
				'uses_tariff'       => ! empty( $definition['live_rates'] ),
				'available'         => $installed,
				'reasons'           => $reasons,
			);
		}

		self::$report = $report;

		return $report;
	}

	/**
	 * Detection result for a single courier.
	 *
	 * @param string $slug Courier slug.
	 * @return array|null
	 */
	public static function get( $slug ) {
		$report = self::detect();

		return isset( $report[ $slug ] ) ? $report[ $slug ] : null;
	}

	/**
	 * Whether the courier plugin is installed and usable on this site.
	 *
	 * @param string $slug Courier slug.
	 * @return bool
	 */
	public static function is_installed( $slug ) {
		$courier = self::get( $slug );

		return $courier ? (bool) $courier['installed'] : false;
	}

	/**
	 * Whether the courier may be selected when switching an order.
	 *
	 * @param string $slug Courier slug.
	 * @return bool
	 */
	public static function is_available( $slug ) {
		$courier = self::get( $slug );

		return $courier ? (bool) $courier['available'] : false;
	}

	/**
	 * Couriers that can be selected when switching an order.
	 *
	 * @return array slug => detection result
	 */
	public static function get_available() {
		$available = array();
		foreach ( self::detect() as $slug => $courier ) {
			if ( $courier['available'] ) {
				$available[ $slug ] = $courier;
			}
		}

		return $available;
	}

	/**
	 * Resolve a WooCommerce shipping method id to a courier slug.
	 *
	 * Instance suffixes ("jneshof_shipping:CTC") are ignored.
	 *
	 * @param string $method_id WooCommerce shipping method id.
	 * @return string Courier slug or an empty string.
	 */
	public static function resolve_method_id( $method_id ) {
		$method_id = strtolower( trim( (string) $method_id ) );
		if ( '' === $method_id ) {
			return '';
		}

		$position = strpos( $method_id, ':' );
		if ( false !== $position ) {
			$method_id = substr( $method_id, 0, $position );
		}

		foreach ( self::get_definitions() as $slug => $definition ) {
			if ( in_array( $method_id, $definition['method_ids'], true ) ) {
				return $slug;
			}
		}

		return '';
	}

	/**
	 * Courier slug currently used by an order.
	 *
	 * @param WC_Order $order Order object.
	 * @return string Courier slug or an empty string.
	 */
	public static function courier_for_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$slug = self::resolve_method_id( $item->get_method_id() );
			if ( '' !== $slug ) {
				return $slug;
			}
		}

		return '';
	}

	/**
	 * Shipping method ids that WooCommerce has registered.
	 *
	 * @return array
	 */
	public static function registered_method_ids() {
		$ids = array();

		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->shipping() ) {
			return $ids;
		}

		$methods = WC()->shipping()->get_shipping_methods();
		if ( ! is_array( $methods ) ) {
			return $ids;
		}

		foreach ( $methods as $method_id => $method ) {
			$ids[] = is_object( $method ) && isset( $method->id ) ? (string) $method->id : (string) $method_id;
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Enabled courier instances per shipping zone.
	 *
	 * @return array slug => list of instance descriptions
	 */
	public static function zone_method_instances() {
		$map = array();

		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return $map;
		}

		$zone_ids = array( 0 );
		foreach ( (array) WC_Shipping_Zones::get_zones() as $zone_data ) {
			if ( isset( $zone_data['id'] ) ) {
				$zone_ids[] = absint( $zone_data['id'] );
			}
		}

		foreach ( array_unique( $zone_ids ) as $zone_id ) {
			$zone = WC_Shipping_Zones::get_zone( $zone_id );
			if ( ! $zone ) {
				continue;
			}

			foreach ( $zone->get_shipping_methods( false ) as $instance ) {
				$method_id = is_object( $instance ) && isset( $instance->id ) ? (string) $instance->id : '';
				$slug      = self::resolve_method_id( $method_id );
				if ( '' === $slug ) {
					continue;
				}

				$map[ $slug ][] = array(
					'zone_id'     => $zone_id,
					'zone_name'   => 0 === $zone_id ? __( 'Locations not covered by your other zones', 'lovecatz-wc' ) : $zone->get_zone_name(),
					'instance_id' => is_object( $instance ) && method_exists( $instance, 'get_instance_id' ) ? (int) $instance->get_instance_id() : 0,
					'enabled'     => is_object( $instance ) && method_exists( $instance, 'is_enabled' ) ? (bool) $instance->is_enabled() : true,
					'title'       => is_object( $instance ) && method_exists( $instance, 'get_title' ) ? (string) $instance->get_title() : '',
				);
			}
		}

		return $map;
	}

	/**
	 * Reset the per-request detection cache.
	 *
	 * Used after settings change so the next read is recomputed.
	 */
	public static function flush_cache() {
		self::$report = null;
	}

	/**
	 * First candidate plugin basename that exists on disk.
	 *
	 * @param array $definition Courier definition.
	 * @return string Plugin basename or an empty string.
	 */
	private static function first_existing_plugin( $definition ) {
		$files = isset( $definition['plugin_files'] ) ? (array) $definition['plugin_files'] : array();
		foreach ( $files as $file ) {
			if ( file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
				return $file;
			}
		}

		return '';
	}

	/**
	 * First courier class that is currently loaded.
	 *
	 * @param array $definition Courier definition.
	 * @return string Class name or an empty string.
	 */
	private static function first_loaded_class( $definition ) {
		$classes = isset( $definition['classes'] ) ? (array) $definition['classes'] : array();
		foreach ( $classes as $class ) {
			if ( class_exists( $class ) ) {
				return $class;
			}
		}

		return '';
	}

	/**
	 * Whether a plugin basename is active on this install.
	 *
	 * Works on front-end and admin requests and honours network activation.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return bool
	 */
	private static function is_plugin_active( $plugin_file ) {
		if ( '' === $plugin_file ) {
			return false;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			$plugin_admin = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( file_exists( $plugin_admin ) ) {
				require_once $plugin_admin;
			}
		}

		if ( function_exists( 'is_plugin_active' ) ) {
			return (bool) is_plugin_active( $plugin_file );
		}

		$active = (array) get_option( 'active_plugins', array() );
		if ( in_array( $plugin_file, $active, true ) ) {
			return true;
		}

		return is_multisite() && array_key_exists( $plugin_file, (array) get_site_option( 'active_sitewide_plugins', array() ) );
	}
}
