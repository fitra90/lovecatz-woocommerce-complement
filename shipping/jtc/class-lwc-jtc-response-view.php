<?php
/**
 * Turns a raw J&T Cargo response into a render-ready view model.
 *
 * Contract:
 *  - The raw payload is never modified. A deep copy is kept for the audit block.
 *  - Values are resolved exclusively through LWC_JTC_Field_Map.
 *  - Missing / null / empty values keep has_value = false; the renderer then
 *    hides the element instead of inventing a fallback.
 *  - Repeated blocks (tracking history, order lists, cost rows) are rendered
 *    with the row count and columns the server actually sent.
 *  - Non-Latin text is converted to Latin before it reaches the screen.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_JTC_Response_View {

	/** Guard against absurd payloads; never a substitute for real pagination. */
	const MAX_ROWS = 500;

	/**
	 * Build the view model.
	 *
	 * @param string       $interface Interface slug (shipment_track, orders, ...).
	 * @param array|WP_Error|mixed $response Envelope from LWC_JTC_API or a raw payload.
	 * @param array        $args {
	 *     Optional.
	 *     @type bool $show_empty      Render empty fields with the safe placeholder. Default false (hide).
	 *     @type bool $include_raw     Keep a printable copy of the raw payload. Default true.
	 *     @type bool $include_unmapped Render server keys the map does not know. Default true.
	 * }
	 * @return array View model.
	 */
	public static function build( $interface, $response, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'show_empty'      => false,
				'include_raw'     => true,
				'include_unmapped' => true,
			)
		);

		$interface = sanitize_key( (string) $interface );
		$view      = array(
			'interface'     => $interface,
			'status'        => 'ok',
			'status_label'  => __( 'Respons diterima', 'lovecatz-wc' ),
			'notices'       => array(),
			'groups'        => array(),
			'collections'   => array(),
			'raw'           => '',
			'raw_json'      => '',
			'field_count'   => 0,
			'row_count'     => 0,
			'show_empty'    => (bool) $args['show_empty'],
		);

		// 1. Transport-level failure.
		if ( is_wp_error( $response ) ) {
			$view['status']       = 'transport_error';
			$view['status_label'] = __( 'Permintaan gagal', 'lovecatz-wc' );
			$view['notices'][]    = array(
				'type' => 'error',
				'text' => LWC_JTC_Text::text( $response->get_error_message() ),
			);
			return $view;
		}

		if ( null === $response || '' === $response || array() === $response ) {
			$view['status']       = 'empty';
			$view['status_label'] = __( 'Respons kosong', 'lovecatz-wc' );
			$view['notices'][]    = array(
				'type' => 'warning',
				'text' => __( 'Server tidak mengirimkan data. Tidak ada nilai yang ditampilkan agar tidak mengarang data.', 'lovecatz-wc' ),
			);
			return $view;
		}

		if ( ! is_array( $response ) ) {
			$view['status']       = 'unknown_structure';
			$view['status_label'] = __( 'Format respons tidak dikenali', 'lovecatz-wc' );
			$view['notices'][]    = array(
				'type' => 'warning',
				'text' => __( 'Respons bukan objek JSON. Data asli ditampilkan apa adanya di bawah.', 'lovecatz-wc' ),
			);
			$view['raw_json'] = self::encode( (string) $response );
			return $view;
		}

		// 2. Split the client envelope from the carrier payload.
		$envelope = array();
		$payload  = $response;
		if ( isset( $response['response'] ) || isset( $response['http_status'] ) ) {
			$envelope = $response;
			$payload  = isset( $response['response'] ) ? $response['response'] : array();
		}

		$view['raw_json'] = $args['include_raw'] ? self::encode( $response ) : '';

		// 3. Transport / business errors.
		$http_status = isset( $envelope['http_status'] ) ? (int) $envelope['http_status'] : 0;
		if ( $http_status > 0 && ( $http_status < 200 || $http_status >= 300 ) ) {
			$view['status']       = 'error';
			$view['status_label'] = sprintf( __( 'Server menjawab HTTP %d', 'lovecatz-wc' ), $http_status );
			$view['notices'][]    = array(
				'type' => 'error',
				'text' => __( 'Permintaan ditolak di tingkat transport. Data di bawah adalah apa yang server kirimkan.', 'lovecatz-wc' ),
			);
		} elseif ( isset( $envelope['business_success'] ) && ! $envelope['business_success'] ) {
			$code    = isset( $envelope['business_code'] ) ? (string) $envelope['business_code'] : '';
			$message = isset( $envelope['business_message'] ) ? (string) $envelope['business_message'] : '';
			$view['status']       = 'business_error';
			$view['status_label'] = __( 'Permintaan ditolak oleh J&T', 'lovecatz-wc' );
			$text_parts           = array();
			if ( '' !== $code ) {
				/* translators: %s: business error code returned by J&T. */
				$text_parts[] = sprintf( __( 'Kode: %s', 'lovecatz-wc' ), LWC_JTC_Text::text( $code ) );
			}
			if ( '' !== $message ) {
				$text_parts[] = LWC_JTC_Text::text( $message );
			}
			$view['notices'][] = array(
				'type' => 'error',
				'text' => implode( ' · ', $text_parts ),
			);
		}

		// 4. Payload sanity.
		if ( ! is_array( $payload ) || empty( $payload ) ) {
			if ( 'error' !== $view['status'] && 'business_error' !== $view['status'] ) {
				$view['status']       = 'empty';
				$view['status_label'] = __( 'Tidak ada data', 'lovecatz-wc' );
			}
			$view['notices'][] = array(
				'type' => 'warning',
				'text' => __( 'Bagian data kosong. Tidak ada nilai yang ditampilkan agar tidak mengarang data.', 'lovecatz-wc' ),
			);
			return $view;
		}

		// 4b. The envelope answered, but the carrier sent no usable data block.
		$protocol_keys = array( 'code', 'msg', 'message', 'success' );
		$has_data      = false;
		foreach ( $payload as $key => $value ) {
			if ( ! in_array( (string) $key, $protocol_keys, true ) && LWC_JTC_Text::has_value( $value ) ) {
				$has_data = true;
				break;
			}
		}
		if ( ! $has_data && 'error' !== $view['status'] && 'business_error' !== $view['status'] ) {
			$view['status']       = 'empty';
			$view['status_label'] = __( 'Tidak ada data', 'lovecatz-wc' );
			$view['notices'][]    = array(
				'type' => 'warning',
				'text' => __( 'Bagian data kosong. Tidak ada nilai yang ditampilkan agar tidak mengarang data.', 'lovecatz-wc' ),
			);
		}

		// 5. Single fields.
		$view['groups']      = self::build_groups( $interface, $payload, $args );
		$view['field_count'] = self::count_items( $view['groups'] );

		// 6. Repeated blocks.
		$view['collections'] = self::build_collections( $interface, $payload, $args );
		foreach ( $view['collections'] as $collection ) {
			$view['row_count'] += count( $collection['rows'] );
		}

		// 7. Structure drift: no mapped field matched, so surface the payload as-is.
		$mapped_count = 0;
		foreach ( $view['groups'] as $group ) {
			if ( 'extra' !== $group['id'] && 'flat' !== $group['id'] ) {
				$mapped_count += count( $group['items'] );
			}
		}
		if ( 0 === $mapped_count && 0 === $view['row_count'] ) {
			$view['status']       = 'unknown_structure';
			$view['status_label'] = __( 'Struktur respons belum dikenali', 'lovecatz-wc' );
			$view['notices'][]    = array(
				'type' => 'info',
				'text' => __( 'Tidak ada field yang cocok dengan pemetaan. Nilai mentah ditampilkan apa adanya — tidak diubah dan tidak ditambahkan.', 'lovecatz-wc' ),
			);
			if ( empty( $view['groups'] ) ) {
				$view['groups'] = self::build_flat_group( $payload );
			}
			$view['field_count'] = self::count_items( $view['groups'] );
		}

		return $view;
	}

	/** Render a view model to HTML. */
	public static function render( $view, $args = array() ) {
		$path = LWC_PLUGIN_DIR . 'shipping/jtc/templates/jtc-response-view.php';
		if ( ! file_exists( $path ) ) {
			return '';
		}
		$args = wp_parse_args( $args, array( 'heading' => '', 'show_raw' => true ) );
		ob_start();
		include $path;
		return (string) ob_get_clean();
	}

	/** Convenience: build then render in one call. */
	public static function render_response( $interface, $response, $args = array() ) {
		return self::render( self::build( $interface, $response, $args ), $args );
	}

	/** Build one item definition into a display-ready row. */
	private static function make_item( $definition, $payload, $context ) {
		$value = null;
		$used  = '';
		foreach ( $definition['paths'] as $path ) {
			$found = self::resolve_path( $payload, $path );
			if ( LWC_JTC_Text::has_value( $found ) ) {
				$value = $found;
				$used  = $path;
				break;
			}
		}

		$type = isset( $definition['type'] ) ? $definition['type'] : 'text';
		$item = array(
			'key'       => $definition['key'],
			'label'     => LWC_JTC_Field_Map::get_label( $definition['key'] ),
			'type'      => $type,
			'path'      => $used,
			'value'     => self::format_value( $value, $type, $context ),
			'has_value' => LWC_JTC_Text::has_value( $value ),
		);

		if ( '' === $item['label'] ) {
			$item['label'] = LWC_JTC_Field_Map::get_column_label( $definition['key'] );
		}

		return $item;
	}

	/**
	 * Apply type-specific rendering without inventing units or symbols.
	 *
	 * A unit is only appended when the server supplied it in the same payload.
	 */
	private static function format_value( $value, $type, $context ) {
		if ( ! LWC_JTC_Text::has_value( $value ) ) {
			return LWC_JTC_Text::display( null );
		}

		switch ( $type ) {
			case 'bool':
				$flag = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
				if ( null === $flag ) {
					// Unknown encoding (e.g. "Y"): show the raw text, do not guess.
					return LWC_JTC_Text::display( $value );
				}
				return array(
					'text'        => $flag ? __( 'Ya', 'lovecatz-wc' ) : __( 'Tidak', 'lovecatz-wc' ),
					'original'    => wp_json_encode( $value ),
					'has_value'   => true,
					'placeholder' => false,
					'method'      => 'none',
					'converted'   => false,
				);

			case 'weight':
				$display = LWC_JTC_Text::display( $value );
				if ( ! empty( $context['weight_unit'] ) ) {
					$display['text'] .= ' ' . LWC_JTC_Text::text( $context['weight_unit'] );
				}
				return $display;

			case 'money':
				$display = LWC_JTC_Text::display( $value );
				if ( ! empty( $context['currency'] ) ) {
					$display['text'] .= ' ' . LWC_JTC_Text::text( $context['currency'] );
				}
				return $display;

			case 'number':
				$display = LWC_JTC_Text::display( $value );
				if ( is_numeric( $value ) ) {
					// Server formatting stays untouched; only stray whitespace goes.
					$display['text'] = trim( (string) $value );
				}
				return $display;

			default:
				return LWC_JTC_Text::display( $value );
		}
	}

	/** Resolve a dot path against a nested array. */
	private static function resolve_path( $payload, $path ) {
		if ( '' === (string) $path ) {
			return null;
		}
		$cursor   = $payload;
		$segments = explode( '.', (string) $path );
		foreach ( $segments as $segment ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
				return null;
			}
			$cursor = $cursor[ $segment ];
		}
		return $cursor;
	}

	/** Group the mapped fields by section and drop empty groups. */
	private static function build_groups( $interface, $payload, $args ) {
		$definitions = LWC_JTC_Field_Map::get_field_map( $interface );
		$context     = array(
			'currency'    => self::first_value( $payload, array( 'data.currency', 'data.currencyCode', 'currency' ) ),
			'weight_unit' => self::first_value( $payload, array( 'data.weightUnit', 'weightUnit' ) ),
		);

		$order  = array( 'summary', 'party', 'cost', 'estimate', 'meta' );
		$titles = array(
			'summary'  => __( 'Ringkasan', 'lovecatz-wc' ),
			'party'    => __( 'Pihak terkait', 'lovecatz-wc' ),
			'cost'     => __( 'Berat & biaya', 'lovecatz-wc' ),
			'estimate' => __( 'Estimasi', 'lovecatz-wc' ),
			'meta'     => __( 'Informasi respons', 'lovecatz-wc' ),
		);

		$buckets = array();
		$seen    = array();
		foreach ( $definitions as $definition ) {
			$item = self::make_item( $definition, $payload, $context );
			if ( ! $item['has_value'] && ! $args['show_empty'] ) {
				continue;
			}
			$group         = isset( $definition['group'] ) ? $definition['group'] : 'meta';
			$buckets[ $group ][] = $item;
			$seen[ $item['key'] ] = true;
			if ( '' !== $item['path'] ) {
				$seen[ self::leaf_key( $item['path'] ) ] = true;
			}
		}

		// Server keys the map does not know are still shown, never invented.
		if ( $args['include_unmapped'] ) {
			$extra = self::collect_unmapped( $payload, $seen );
			if ( ! empty( $extra ) ) {
				$buckets['extra'] = $extra;
				$titles['extra']  = __( 'Field lain dari server', 'lovecatz-wc' );
				$order[]          = 'extra';
			}
		}

		$groups = array();
		foreach ( $order as $group ) {
			if ( empty( $buckets[ $group ] ) ) {
				continue;
			}
			$groups[] = array(
				'id'    => $group,
				'title' => isset( $titles[ $group ] ) ? $titles[ $group ] : ucfirst( $group ),
				'items' => $buckets[ $group ],
			);
		}

		return $groups;
	}

	/** Scalars present in the payload that no mapping consumed. */
	private static function collect_unmapped( $payload, $seen ) {
		$sources = array();
		if ( isset( $payload['data'] ) && is_array( $payload['data'] ) ) {
			$sources[] = $payload['data'];
		}
		$sources[] = $payload;

		$items = array();
		foreach ( $sources as $source ) {
			foreach ( $source as $key => $value ) {
				if ( isset( $seen[ $key ] ) || is_array( $value ) || is_object( $value ) ) {
					continue;
				}
				if ( in_array( (string) $key, array( 'code', 'msg', 'message' ), true ) ) {
					continue; // Protocol fields, already covered by shared mapping.
				}
				if ( ! LWC_JTC_Text::has_value( $value ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$items[]      = array(
					'key'       => (string) $key,
					'label'     => LWC_JTC_Field_Map::get_column_label( $key ),
					'type'      => is_numeric( $value ) ? 'number' : 'text',
					'path'      => '',
					'value'     => LWC_JTC_Text::display( $value ),
					'has_value' => true,
				);
			}
		}
		return $items;
	}

	/** Fallback for unknown structures: flatten everything scalar. */
	private static function build_flat_group( $payload ) {
		$items = array();
		foreach ( self::flatten( $payload ) as $path => $value ) {
			if ( ! LWC_JTC_Text::has_value( $value ) ) {
				continue;
			}
			$items[] = array(
				'key'       => $path,
				'label'     => LWC_JTC_Field_Map::get_column_label( self::leaf_key( $path ) ),
				'type'      => is_numeric( $value ) ? 'number' : 'text',
				'path'      => $path,
				'value'     => LWC_JTC_Text::display( $value ),
				'has_value' => true,
			);
		}
		if ( empty( $items ) ) {
			return array();
		}
		return array(
			array(
				'id'    => 'flat',
				'title' => __( 'Isi respons', 'lovecatz-wc' ),
				'items' => $items,
			),
		);
	}

	/** Build every repeated block the payload contains. */
	private static function build_collections( $interface, $payload, $args ) {
		$definitions = LWC_JTC_Field_Map::get_collection_map( $interface );
		$collections = array();
		$rendered    = array();

		foreach ( $definitions as $definition ) {
			$rows = null;
			$path = '';
			foreach ( $definition['paths'] as $candidate ) {
				$found = self::resolve_path( $payload, $candidate );
				if ( self::is_row_list( $found ) ) {
					$rows = $found;
					$path = $candidate;
					break;
				}
			}
			if ( null === $rows ) {
				continue;
			}
			// Do not render the same array twice (explicit map + auto map).
			$fingerprint = md5( wp_json_encode( $rows ) );
			if ( isset( $rendered[ $fingerprint ] ) ) {
				continue;
			}
			$rendered[ $fingerprint ] = true;

			$collection = self::build_collection( $definition, $rows, $path );
			if ( empty( $collection['rows'] ) ) {
				continue;
			}
			$collections[] = $collection;
		}

		return $collections;
	}

	/** Normalise one repeated block into columns + rows. */
	private static function build_collection( $definition, $rows, $path ) {
		$max_rows = (int) apply_filters( 'lwc_jtc_view_max_rows', self::MAX_ROWS );
		$total    = count( $rows );
		$truncated = $total > $max_rows;
		if ( $truncated ) {
			$rows = array_slice( $rows, 0, $max_rows );
		}

		// Columns follow the payload: union of keys in first-seen order.
		$columns = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			foreach ( array_keys( $row ) as $key ) {
				if ( ! isset( $columns[ $key ] ) ) {
					$columns[ $key ] = array(
						'key'   => (string) $key,
						'label' => LWC_JTC_Field_Map::get_column_label( $key ),
					);
				}
			}
		}

		$prepared = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				// Scalar rows (e.g. a list of waybill numbers) get one column.
				$row = array( _x( 'Nilai', 'single-column list value', 'lovecatz-wc' ) => $row );
				if ( ! isset( $columns[ _x( 'Nilai', 'single-column list value', 'lovecatz-wc' ) ] ) ) {
					$columns[ _x( 'Nilai', 'single-column list value', 'lovecatz-wc' ) ] = array(
						'key'   => _x( 'Nilai', 'single-column list value', 'lovecatz-wc' ),
						'label' => _x( 'Nilai', 'single-column list value', 'lovecatz-wc' ),
					);
				}
			}
			$cells = array();
			foreach ( array_keys( $columns ) as $key ) {
				$raw     = isset( $row[ $key ] ) ? $row[ $key ] : null;
				$display = LWC_JTC_Text::display( $raw, array( 'placeholder' => true ) );
				if ( is_array( $raw ) ) {
					$display = LWC_JTC_Text::display( wp_json_encode( $raw ), array( 'placeholder' => true ) );
				}
				$cells[ $key ] = $display;
			}
			$prepared[] = array( 'cells' => $cells );
		}

		return array(
			'id'        => isset( $definition['id'] ) ? $definition['id'] : 'rows',
			'title'     => isset( $definition['title'] ) ? $definition['title'] : __( 'Data', 'lovecatz-wc' ),
			'path'      => $path,
			'columns'   => array_values( $columns ),
			'rows'      => $prepared,
			'total'     => $total,
			'truncated' => $truncated,
		);
	}

	/** A sequential array of rows (associative arrays or scalars). */
	private static function is_row_list( $value ) {
		if ( ! is_array( $value ) || empty( $value ) ) {
			return false;
		}
		if ( ! array_key_exists( 0, $value ) ) {
			return false;
		}
		foreach ( $value as $row ) {
			if ( is_array( $row ) || is_scalar( $row ) ) {
				continue;
			}
			return false;
		}
		return true;
	}

	private static function first_value( $payload, $paths ) {
		foreach ( $paths as $path ) {
			$found = self::resolve_path( $payload, $path );
			if ( LWC_JTC_Text::has_value( $found ) ) {
				return $found;
			}
		}
		return null;
	}

	private static function leaf_key( $path ) {
		$segments = explode( '.', (string) $path );
		return (string) end( $segments );
	}

	private static function count_items( $groups ) {
		$count = 0;
		foreach ( $groups as $group ) {
			$count += count( $group['items'] );
		}
		return $count;
	}

	/** Flatten a nested array into dot-path => scalar. */
	private static function flatten( $array, $prefix = '' ) {
		$out = array();
		foreach ( (array) $array as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			if ( is_array( $value ) ) {
				$out = array_merge( $out, self::flatten( $value, $path ) );
				continue;
			}
			$out[ $path ] = $value;
		}
		return $out;
	}

	private static function encode( $value ) {
		$json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		return false === $json ? '' : $json;
	}
}
