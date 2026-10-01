<?php
/**
 * Schema-driven dummy payload generator for the J&T Cargo Sandbox console.
 *
 * Each interface a merchant wants to test from the console needs an accurate
 * field schema: the manual only documents `add_order` (3.4.1) and `orders`
 * (3.4.2) in full; the other 18 endpoints say "Dokumentasi: [Link]".
 *
 * This class owns a small registry of schemas. Schemas are not guessed: they
 * come from the official manual and from live call sites in the plugin. New
 * interfaces are added through the `lwc_jtc_payload_schemas` filter, so the
 * console button keeps working without editing this file when J&T publishes
 * a new endpoint or when a new schema is supplied.
 *
 * A schema is a flat map `key => field`. Each field carries:
 *
 *   type        string|number|enum|object|array
 *   required    bool
 *   max         int max length / max value
 *   min         int min length / min value
 *   enum        array allowed values (forces enum type)
 *   example     string override for the generated dummy
 *   fields      array map name => field  (object)
 *   items       field                     (array)
 *
 * The generator walks the schema, picks type-appropriate dummy data, obeys
 * every length and enum constraint, recurses through nested objects, and
 * produces exactly one array element for arrays. The output is guaranteed
 * to pass `LWC_JTC_Order::validate_payload()` when the schema was derived
 * from the manual table.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_JTC_Payload_Schema {

	const TYPE_STRING = 'string';
	const TYPE_NUMBER = 'number';
	const TYPE_ENUM   = 'enum';
	const TYPE_OBJECT = 'object';
	const TYPE_ARRAY  = 'array';

	/** Seeded from the manual. New schemas join via the filter below. */
	public static function default_schemas() {
		return array(
			'add_order' => array(
				'type'     => self::TYPE_OBJECT,
				'required' => true,
				'fields'   => self::add_order_fields(),
			),
			'orders' => array(
				// Manual 3.4.2 publishes the full Create Order table for Check
				// Order by copy/paste, but every live Check Order uses only
				// txlogisticId. Seed the minimum and let J&T's own response
				// reveal the real contract.
				'type'     => self::TYPE_OBJECT,
				'required' => true,
				'fields'   => array(
					'txlogisticId' => self::f( self::TYPE_STRING, true, 50, 'ID12345678' ),
				),
			),
			'shipment_track' => array(
				// Manual 3.5.1 has no field table. The only key the plugin
				// uses to query tracking is billCode.
				'type'     => self::TYPE_OBJECT,
				'required' => true,
				'fields'   => array(
					'billCode' => self::f( self::TYPE_STRING, true, 50, '200012345678' ),
				),
			),
		);
	}

	/**
	 * Field map for `order/addOrder` from manual 3.4.1.
	 *
	 * customerCode and digest are intentionally omitted: the J&T API injects
	 * them, so a dummy business payload must not carry fake credentials.
	 */
	private static function add_order_fields() {
		$party = array(
			'name'        => self::f( self::TYPE_STRING, true, 32, 'Kucing Oren' ),
			'company'     => self::f( self::TYPE_STRING, false, 100, 'PT Kucing Sejahtera' ),
			'postCode'    => self::f( self::TYPE_STRING, false, 32, '14450' ),
			'mobile'      => self::f( self::TYPE_STRING, true, 30, '0821987654321' ),
			'phone'       => self::f( self::TYPE_STRING, false, 30, '0211234567' ),
			'countryCode' => self::f( self::TYPE_ENUM, true, 20, null, array( 'IDN' ) ),
			'prov'        => self::f( self::TYPE_STRING, true, 32, 'DKI Jakarta' ),
			'city'        => self::f( self::TYPE_STRING, true, 32, 'Jakarta Utara' ),
			'area'        => self::f( self::TYPE_STRING, true, 32, 'Penjaringan' ),
			'town'        => self::f( self::TYPE_STRING, false, 32, 'Pluit' ),
			'address'     => self::f( self::TYPE_STRING, true, 150, 'Jl. ABC No. 123' ),
		);
		$receiver          = $party;
		$receiver['name']  = self::f( self::TYPE_STRING, true, 32, 'Kucing Hitam' );
		$receiver['company']   = self::f( self::TYPE_STRING, false, 100, 'PT Kucing Bahagia' );
		$receiver['address']   = self::f( self::TYPE_STRING, true, 150, 'Jl. DEF No. 45' );

		$item = array(
			'itemType'      => self::f( self::TYPE_ENUM, false, 30, null, self::goods_type_enum() ),
			'itemName'      => self::f( self::TYPE_STRING, false, 30, 'Whiskas' ),
			'number'        => self::f( self::TYPE_NUMBER, false, 4, 1, null, 1 ),
			'itemValue'     => self::f( self::TYPE_STRING, false, 20, '100000' ),
			'priceCurrency' => self::f( self::TYPE_ENUM, false, 20, null, array( 'IDR' ) ),
			'desc'          => self::f( self::TYPE_STRING, false, 100, 'Makanan kucing' ),
			'itemUrl'       => self::f( self::TYPE_STRING, false, 100, 'www.abc.com/whiskas' ),
		);

		return array(
			'txlogisticId'  => self::f( self::TYPE_STRING, true, 50, 'ID12345678' ),
			'expressType'   => self::f( self::TYPE_ENUM, true, 30, null, array( 'FT' ) ),
			'orderType'     => self::f( self::TYPE_ENUM, true, 30, null, array( '1', '2' ) ),
			'serviceType'   => self::f( self::TYPE_ENUM, true, 30, null, array( '01', '02' ) ),
			'deliveryType'  => self::f( self::TYPE_ENUM, true, 30, null, array( '101' ) ),
			'payType'       => self::f( self::TYPE_ENUM, true, 30, null, array( 'PP_PM', 'CC_CASH', 'PP_CASH' ) ),
			'sender'        => self::f( self::TYPE_OBJECT, true, null, null, null, null, $party ),
			'receiver'      => self::f( self::TYPE_OBJECT, true, null, null, null, null, $receiver ),
			'goodsType'     => self::f( self::TYPE_ENUM, true, 30, null, self::goods_type_enum() ),
			'length'        => self::f( self::TYPE_NUMBER, false, 6, 10 ),
			'width'         => self::f( self::TYPE_NUMBER, false, 6, 10 ),
			'height'        => self::f( self::TYPE_NUMBER, false, 6, 10 ),
			'weight'        => self::f( self::TYPE_STRING, true, 12, '11' ),
			'totalQuantity' => self::f( self::TYPE_NUMBER, false, 4, 1, null, 1 ),
			'offerFee'      => self::f( self::TYPE_STRING, true, 12, '2500000' ),
			'remark'        => self::f( self::TYPE_STRING, false, 200, 'Sandbox joint debugging test' ),
			'items'         => self::f( self::TYPE_ARRAY, false, null, null, null, null, null, $item ),
			'signReceipt'   => self::f( self::TYPE_NUMBER, false, 4, 0, array( 0, 1 ), 0 ),
			'waybillNo'     => self::f( self::TYPE_STRING, false, 50, '200012345678' ),
		);
	}

	/** Concise helper to build a field schema. */
	private static function f( $type, $required, $max = null, $example = null, $enum = null, $min = null, $fields = null, $items = null ) {
		$field = array(
			'type'     => $type,
			'required' => (bool) $required,
		);
		if ( null !== $max ) {
			$field['max'] = (int) $max;
		}
		if ( null !== $min ) {
			$field['min'] = (int) $min;
		}
		if ( null !== $example && '' !== (string) $example ) {
			$field['example'] = (string) $example;
		}
		if ( null !== $enum ) {
			$field['enum'] = array_values( (array) $enum );
		}
		if ( self::TYPE_OBJECT === $type && null !== $fields ) {
			$field['fields'] = (array) $fields;
		}
		if ( self::TYPE_ARRAY === $type && null !== $items ) {
			$field['items'] = self::wrap_object_items( $items );
		}
		return $field;
	}

	/**
	 * Normalise an array items schema.
	 *
	 * Callers pass either a full `{type: object, fields: {...}}` schema or a
	 * raw field map. A raw map is promoted to an object schema so the
	 * generator recurses through it instead of falling back to the string
	 * default.
	 */
	private static function wrap_object_items( $items ) {
		if ( ! is_array( $items ) ) {
			return array( 'type' => self::TYPE_OBJECT, 'required' => true, 'fields' => array() );
		}
		if ( isset( $items['type'] ) ) {
			return $items;
		}
		return array(
			'type'     => self::TYPE_OBJECT,
			'required' => true,
			'fields'   => $items,
		);
	}

	/** The bm000xxx goods type codes the manual lists. */
	private static function goods_type_enum() {
		return array( 'bm000001', 'bm000002', 'bm000003', 'bm000004', 'bm000005', 'bm000006', 'bm000007', 'bm000008', 'bm000009', 'bm000010', 'bm000011' );
	}

	/** Return the full schema registry (seed + filter overrides). */
	public static function get_schemas() {
		$schemas = self::default_schemas();

		/**
		 * Filter the schema registry. Add or replace any interface here to
		 * teach the Sandbox console a new endpoint without touching this file.
		 *
		 * @param array $schemas Interface slug => schema.
		 */
		return (array) apply_filters( 'lwc_jtc_payload_schemas', $schemas );
	}

	/** Look up a single interface schema, or null when unknown. */
	public static function get_schema( $interface ) {
		$schemas = self::get_schemas();
		return isset( $schemas[ $interface ] ) ? $schemas[ $interface ] : null;
	}

	/**
	 * Build a dummy payload that satisfies the schema.
	 *
	 * @param string $interface Interface slug.
	 * @return array|null Null when no schema is registered.
	 */
	public static function generate( $interface ) {
		$schema = self::get_schema( $interface );
		if ( null === $schema ) {
			return null;
		}
		return self::build( $schema, $interface );
	}

	/**
	 * Return every interface in the Sandbox catalog together with its dummy
	 * payload as a pretty JSON string (or null when the interface has no
	 * schema). The console uses this map to drive its "fill sample" button
	 * per endpoint.
	 *
	 * @return array slug => JSON string|null
	 */
	public static function get_samples_for_console() {
		$paths    = class_exists( 'LWC_JTC_API' ) ? LWC_JTC_API::get_interface_paths() : array();
		$samples  = array();
		foreach ( array_keys( (array) $paths ) as $slug ) {
			$payload = self::generate( $slug );
			if ( null === $payload ) {
				$samples[ $slug ] = null;
				continue;
			}
			$samples[ $slug ] = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		}
		return $samples;
	}

	/* ------------------------------------------------------------------ */
	/* Generator implementation
	/* ------------------------------------------------------------------ */

	/** Build one value for a field schema. */
	private static function build( $schema, $key_for_default = '' ) {
		if ( ! is_array( $schema ) ) {
			return null;
		}
		$type = isset( $schema['type'] ) ? (string) $schema['type'] : self::TYPE_STRING;

		switch ( $type ) {
			case self::TYPE_OBJECT:
				return self::build_object( $schema );

			case self::TYPE_ARRAY:
				return self::build_array( $schema );

			case self::TYPE_NUMBER:
				return self::build_number( $schema );

			case self::TYPE_ENUM:
				return self::build_enum( $schema, $key_for_default );

			default:
				return self::build_string( $schema, $key_for_default );
		}
	}

	private static function build_object( $schema ) {
		$out     = array();
		$fields  = isset( $schema['fields'] ) ? (array) $schema['fields'] : array();
		foreach ( $fields as $name => $field_schema ) {
			if ( ! self::should_emit( $field_schema ) ) {
				continue;
			}
			$out[ $name ] = self::build( $field_schema, (string) $name );
		}
		return $out;
	}

	private static function build_array( $schema ) {
		if ( ! isset( $schema['items'] ) || ! is_array( $schema['items'] ) ) {
			return array();
		}
		// Always produce exactly one array element so the schema is exercised
		// in full and the operator sees the structure the API expects.
		return array( self::build( $schema['items'], 'item' ) );
	}

	private static function build_number( $schema ) {
		// A numeric enum wins over min/max: it is an explicit whitelist.
		if ( ! empty( $schema['enum'] ) ) {
			$first = reset( $schema['enum'] );
			return (int) $first;
		}

		if ( isset( $schema['example'] ) && is_numeric( $schema['example'] ) ) {
			return (int) $schema['example'];
		}

		$min = isset( $schema['min'] ) ? (int) $schema['min'] : 1;
		$max = isset( $schema['max'] ) ? (int) $schema['max'] : PHP_INT_MAX;
		if ( $max < $min ) {
			$max = $min;
		}

		// Defaults that look real beat defaults that confuse (0 cm or 0 packages).
		return max( $min, 1 );
	}

	private static function build_enum( $schema, $key ) {
		$enum = isset( $schema['enum'] ) ? (array) $schema['enum'] : array();
		if ( empty( $enum ) ) {
			return '';
		}
		$value = reset( $enum );
		return self::clip( (string) $value, isset( $schema['max'] ) ? (int) $schema['max'] : 0 );
	}

	private static function build_string( $schema, $key ) {
		$max = isset( $schema['max'] ) ? (int) $schema['max'] : 0;

		if ( isset( $schema['example'] ) ) {
			return self::clip( (string) $schema['example'], $max );
		}

		// Field-name-aware defaults so the dummy reads as a real order, not
		// as "value value value". Each entry is bounded by its own limit.
		$known = self::string_defaults();
		if ( isset( $known[ $key ] ) ) {
			return self::clip( $known[ $key ], $max );
		}

		// Generic placeholder, short enough to fit in any sane limit and
		// recognisable when it lands in a real response.
		return self::clip( 'sample-' . $key, $max );
	}

	/** Per-key dummy strings that read like real address fields. */
	private static function string_defaults() {
		return array(
			'txlogisticId'   => 'ID12345678',
			'billCode'       => '200012345678',
			'waybillNo'      => '200012345678',
			'weight'         => '11',
			'offerFee'       => '2500000',
			'remark'         => 'Sandbox joint debugging test',
			'itemName'       => 'Whiskas',
			'itemValue'      => '100000',
			'priceCurrency'  => 'IDR',
			'desc'           => 'Makanan kucing',
			'itemUrl'        => 'www.abc.com/whiskas',
		);
	}

	/**
	 * The sample payload exercises every field the schema declares.
	 *
	 * Skipping optional fields would hide the schema the operator is supposed
	 * to learn, so every field is rendered. Required flags stay meaningful
	 * for documentation, but they do not shape the sample.
	 */
	private static function should_emit( $field_schema ) {
		return is_array( $field_schema );
	}

	/** Clip a string to a maximum length, taking multibyte chars into account. */
	private static function clip( $value, $max ) {
		$value = (string) $value;
		if ( $max <= 0 ) {
			return $value;
		}
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max );
		}
		return substr( $value, 0, $max );
	}
}