<?php
/**
 * Declarative mapping between J&T Cargo API payloads and display fields.
 *
 * Every displayed value is resolved from the server response through this map.
 * The map never invents a value: it only declares where to look (ordered
 * candidate paths) and how to render what was found. When no candidate path
 * yields a value the caller hides the element or renders the safe placeholder.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_JTC_Field_Map {

	/**
	 * Indonesian labels for logical field keys.
	 *
	 * Keys are stable identifiers owned by this plugin, not raw API property
	 * names, so the UI keeps working when J&T renames a response property —
	 * only the candidate paths below need an extra entry.
	 *
	 * @return array key => label.
	 */
	public static function get_labels() {
		return (array) apply_filters(
			'lwc_jtc_field_labels',
			array(
				'waybill'           => __( 'Nomor resi', 'lovecatz-wc' ),
				'order_no'          => __( 'Nomor pesanan', 'lovecatz-wc' ),
				'reference_no'      => __( 'Nomor referensi', 'lovecatz-wc' ),
				'three_segment'     => __( 'Kode tiga segmen', 'lovecatz-wc' ),
				'destination_code'  => __( 'Kode tujuan', 'lovecatz-wc' ),
				'status'            => __( 'Status', 'lovecatz-wc' ),
				'status_code'       => __( 'Kode status', 'lovecatz-wc' ),
				'service_type'      => __( 'Jenis layanan', 'lovecatz-wc' ),
				'product_type'      => __( 'Jenis produk', 'lovecatz-wc' ),
				'consolidated'      => __( 'Konsolidasi', 'lovecatz-wc' ),
				'origin_code'       => __( 'Kode asal', 'lovecatz-wc' ),
				'recipient_name'    => __( 'Nama penerima', 'lovecatz-wc' ),
				'recipient_phone'   => __( 'Telepon penerima', 'lovecatz-wc' ),
				'recipient_address' => __( 'Alamat penerima', 'lovecatz-wc' ),
				'sender_name'       => __( 'Nama pengirim', 'lovecatz-wc' ),
				'sender_phone'      => __( 'Telepon pengirim', 'lovecatz-wc' ),
				'sender_address'    => __( 'Alamat pengirim', 'lovecatz-wc' ),
				'billing_weight'    => __( 'Berat tagihan', 'lovecatz-wc' ),
				'actual_weight'     => __( 'Berat aktual', 'lovecatz-wc' ),
				'volumetric_weight' => __( 'Berat volumetrik', 'lovecatz-wc' ),
				'weight_unit'       => __( 'Satuan berat', 'lovecatz-wc' ),
				'quantity'          => __( 'Jumlah koli', 'lovecatz-wc' ),
				'freight'           => __( 'Ongkos kirim', 'lovecatz-wc' ),
				'total_cost'        => __( 'Total biaya', 'lovecatz-wc' ),
				'insurance_fee'     => __( 'Biaya asuransi', 'lovecatz-wc' ),
				'insurance_value'   => __( 'Nilai asuransi', 'lovecatz-wc' ),
				'cod_amount'        => __( 'COD', 'lovecatz-wc' ),
				'currency'          => __( 'Mata uang', 'lovecatz-wc' ),
				'payment_method'    => __( 'Metode bayar', 'lovecatz-wc' ),
				'response_code'     => __( 'Kode respons', 'lovecatz-wc' ),
				'response_message'  => __( 'Pesan respons', 'lovecatz-wc' ),
				'estimate'          => __( 'Estimasi', 'lovecatz-wc' ),
				'estimate_days'     => __( 'Estimasi (hari)', 'lovecatz-wc' ),
				'pickup_time'       => __( 'Waktu penjemputan', 'lovecatz-wc' ),
				'delivery_time'     => __( 'Waktu pengantaran', 'lovecatz-wc' ),
				'created_at'        => __( 'Dibuat', 'lovecatz-wc' ),
				'updated_at'        => __( 'Diperbarui', 'lovecatz-wc' ),
				'network_name'      => __( 'Nama gerai', 'lovecatz-wc' ),
				'network_code'      => __( 'Kode gerai', 'lovecatz-wc' ),
				'balance'           => __( 'Saldo', 'lovecatz-wc' ),
				'remarks'           => __( 'Catatan', 'lovecatz-wc' ),
			)
		);
	}

	/** Label for one logical key; empty string when unknown. */
	public static function get_label( $key ) {
		$labels = self::get_labels();
		return isset( $labels[ $key ] ) ? $labels[ $key ] : '';
	}

	/**
	 * Field definitions per interface.
	 *
	 * Each definition:
	 *   key    - logical field id (see get_labels()).
	 *   paths  - ordered dot-path candidates; first non-empty wins.
	 *   type   - code|text|money|weight|number|datetime|bool.
	 *   group  - summary|party|cost|estimate|meta.
	 *
	 * @param string $interface Interface slug.
	 * @param bool   $with_shared Include fields shared by every interface.
	 * @return array
	 */
	public static function get_field_map( $interface, $with_shared = true ) {
		$maps = array(
			'shipment_track' => array(
				array( 'key' => 'waybill', 'type' => 'code', 'group' => 'summary', 'paths' => array( 'data.billCode', 'data.bill_code', 'data.waybillNo', 'data.mailNo', 'billCode' ) ),
				array( 'key' => 'status', 'type' => 'text', 'group' => 'summary', 'paths' => array( 'data.statusName', 'data.statusDesc', 'data.status', 'data.lastStatus', 'statusName' ) ),
				array( 'key' => 'status_code', 'type' => 'code', 'group' => 'summary', 'paths' => array( 'data.statusCode', 'data.status', 'statusCode' ) ),
				array( 'key' => 'service_type', 'type' => 'text', 'group' => 'summary', 'paths' => array( 'data.serviceName', 'data.productType', 'data.goodsType', 'serviceName' ) ),
				array( 'key' => 'recipient_name', 'type' => 'text', 'group' => 'party', 'paths' => array( 'data.receiverName', 'data.recipientName', 'receiverName' ) ),
				array( 'key' => 'recipient_phone', 'type' => 'text', 'group' => 'party', 'paths' => array( 'data.receiverPhone', 'data.recipientPhone', 'receiverPhone' ) ),
				array( 'key' => 'recipient_address', 'type' => 'text', 'group' => 'party', 'paths' => array( 'data.receiverAddress', 'data.recipientAddress', 'receiverAddress' ) ),
				array( 'key' => 'sender_name', 'type' => 'text', 'group' => 'party', 'paths' => array( 'data.senderName', 'senderName' ) ),
				array( 'key' => 'sender_phone', 'type' => 'text', 'group' => 'party', 'paths' => array( 'data.senderPhone', 'senderPhone' ) ),
				array( 'key' => 'sender_address', 'type' => 'text', 'group' => 'party', 'paths' => array( 'data.senderAddress', 'senderAddress' ) ),
				array( 'key' => 'billing_weight', 'type' => 'weight', 'group' => 'cost', 'paths' => array( 'data.billingWeight', 'data.weight', 'data.chargeWeight', 'weight' ) ),
				array( 'key' => 'actual_weight', 'type' => 'weight', 'group' => 'cost', 'paths' => array( 'data.realWeight', 'data.actualWeight', 'realWeight' ) ),
				array( 'key' => 'weight_unit', 'type' => 'text', 'group' => 'cost', 'paths' => array( 'data.weightUnit', 'weightUnit' ) ),
				array( 'key' => 'quantity', 'type' => 'number', 'group' => 'cost', 'paths' => array( 'data.quantity', 'data.pieceCount', 'data.count', 'quantity' ) ),
				array( 'key' => 'freight', 'type' => 'money', 'group' => 'cost', 'paths' => array( 'data.freight', 'data.shippingFee', 'data.totalFee', 'freight' ) ),
				array( 'key' => 'cod_amount', 'type' => 'money', 'group' => 'cost', 'paths' => array( 'data.codAmount', 'data.cod', 'codAmount' ) ),
				array( 'key' => 'insurance_fee', 'type' => 'money', 'group' => 'cost', 'paths' => array( 'data.insuranceFee', 'data.insuredFee', 'insuranceFee' ) ),
				array( 'key' => 'currency', 'type' => 'text', 'group' => 'cost', 'paths' => array( 'data.currency', 'data.currencyCode', 'currency' ) ),
				array( 'key' => 'delivery_time', 'type' => 'datetime', 'group' => 'estimate', 'paths' => array( 'data.deliveryTime', 'data.signTime', 'deliveryTime' ) ),
				array( 'key' => 'estimate', 'type' => 'text', 'group' => 'estimate', 'paths' => array( 'data.aging', 'data.estimateTime', 'data.predictTime', 'aging' ) ),
				array( 'key' => 'remarks', 'type' => 'text', 'group' => 'meta', 'paths' => array( 'data.remark', 'data.remarks', 'data.note', 'remark' ) ),
			),
			'orders' => array(
				array( 'key' => 'waybill', 'type' => 'code', 'group' => 'summary', 'paths' => array( 'data.billCode', 'data.waybillNo', 'billCode' ) ),
				array( 'key' => 'order_no', 'type' => 'code', 'group' => 'summary', 'paths' => array( 'data.orderNo', 'data.orderCode', 'orderNo' ) ),
				array( 'key' => 'reference_no', 'type' => 'code', 'group' => 'summary', 'paths' => array( 'data.customerOrderNo', 'data.referenceNo', 'customerOrderNo' ) ),
				array( 'key' => 'status', 'type' => 'text', 'group' => 'summary', 'paths' => array( 'data.statusName', 'data.status', 'statusName' ) ),
				array( 'key' => 'service_type', 'type' => 'text', 'group' => 'summary', 'paths' => array( 'data.serviceName', 'data.productType', 'serviceName' ) ),
				array( 'key' => 'three_segment', 'type' => 'code', 'group' => 'summary', 'paths' => array( 'data.threeSegmentCode', 'data.threeCode', 'threeSegmentCode' ) ),
				array( 'key' => 'destination_code', 'type' => 'code', 'group' => 'summary', 'paths' => array( 'data.destinationCode', 'data.bigPen', 'destinationCode' ) ),
				array( 'key' => 'consolidated', 'type' => 'bool', 'group' => 'summary', 'paths' => array( 'data.consolidated', 'data.isConsolidated', 'consolidated' ) ),
				array( 'key' => 'billing_weight', 'type' => 'weight', 'group' => 'cost', 'paths' => array( 'data.billingWeight', 'data.weight', 'weight' ) ),
				array( 'key' => 'freight', 'type' => 'money', 'group' => 'cost', 'paths' => array( 'data.freight', 'data.totalFee', 'freight' ) ),
				array( 'key' => 'cod_amount', 'type' => 'money', 'group' => 'cost', 'paths' => array( 'data.codAmount', 'codAmount' ) ),
				array( 'key' => 'created_at', 'type' => 'datetime', 'group' => 'meta', 'paths' => array( 'data.createTime', 'data.createdAt', 'createTime' ) ),
				array( 'key' => 'updated_at', 'type' => 'datetime', 'group' => 'meta', 'paths' => array( 'data.updateTime', 'data.updatedAt', 'updateTime' ) ),
			),
			'freight_calculation' => array(
				array( 'key' => 'freight', 'type' => 'money', 'group' => 'cost', 'paths' => array( 'data.freight', 'data.cost', 'data.totalFee', 'freight' ) ),
				array( 'key' => 'total_cost', 'type' => 'money', 'group' => 'cost', 'paths' => array( 'data.totalCost', 'data.totalFee', 'data.totalAmount', 'totalCost' ) ),
				array( 'key' => 'insurance_fee', 'type' => 'money', 'group' => 'cost', 'paths' => array( 'data.insuranceFee', 'data.insuredFee', 'insuranceFee' ) ),
				array( 'key' => 'currency', 'type' => 'text', 'group' => 'cost', 'paths' => array( 'data.currency', 'data.currencyCode', 'currency' ) ),
				array( 'key' => 'billing_weight', 'type' => 'weight', 'group' => 'cost', 'paths' => array( 'data.billingWeight', 'data.weight', 'weight' ) ),
				array( 'key' => 'actual_weight', 'type' => 'weight', 'group' => 'cost', 'paths' => array( 'data.realWeight', 'realWeight' ) ),
				array( 'key' => 'volumetric_weight', 'type' => 'weight', 'group' => 'cost', 'paths' => array( 'data.volumeWeight', 'data.volumetricWeight', 'volumeWeight' ) ),
				array( 'key' => 'weight_unit', 'type' => 'text', 'group' => 'cost', 'paths' => array( 'data.weightUnit', 'weightUnit' ) ),
				array( 'key' => 'estimate_days', 'type' => 'number', 'group' => 'estimate', 'paths' => array( 'data.aging', 'data.agingDays', 'data.estimateDays', 'aging' ) ),
				array( 'key' => 'estimate', 'type' => 'text', 'group' => 'estimate', 'paths' => array( 'data.estimateTime', 'data.agingDesc', 'estimateTime' ) ),
				array( 'key' => 'payment_method', 'type' => 'text', 'group' => 'cost', 'paths' => array( 'data.payType', 'data.paymentType', 'payType' ) ),
			),
			'delivery_sla' => array(
				array( 'key' => 'estimate', 'type' => 'text', 'group' => 'estimate', 'paths' => array( 'data.aging', 'data.agingDesc', 'data.estimateTime', 'aging' ) ),
				array( 'key' => 'estimate_days', 'type' => 'number', 'group' => 'estimate', 'paths' => array( 'data.agingDays', 'data.days', 'agingDays' ) ),
				array( 'key' => 'freight', 'type' => 'money', 'group' => 'cost', 'paths' => array( 'data.freight', 'data.cost', 'freight' ) ),
			),
			'add_order' => array(
				array( 'key' => 'waybill', 'type' => 'code', 'group' => 'summary', 'paths' => array( 'data.billCode', 'data.waybillNo', 'billCode' ) ),
				array( 'key' => 'three_segment', 'type' => 'code', 'group' => 'summary', 'paths' => array( 'data.threeSegmentCode', 'threeSegmentCode' ) ),
				array( 'key' => 'destination_code', 'type' => 'code', 'group' => 'summary', 'paths' => array( 'data.destinationCode', 'data.bigPen', 'destinationCode' ) ),
				array( 'key' => 'service_type', 'type' => 'text', 'group' => 'summary', 'paths' => array( 'data.serviceName', 'data.productType', 'serviceName' ) ),
				array( 'key' => 'freight', 'type' => 'money', 'group' => 'cost', 'paths' => array( 'data.freight', 'data.totalFee', 'freight' ) ),
				array( 'key' => 'billing_weight', 'type' => 'weight', 'group' => 'cost', 'paths' => array( 'data.billingWeight', 'data.weight', 'weight' ) ),
			),
			'print_order' => array(
				array( 'key' => 'waybill', 'type' => 'code', 'group' => 'summary', 'paths' => array( 'data.billCode', 'billCode' ) ),
				array( 'key' => 'three_segment', 'type' => 'code', 'group' => 'summary', 'paths' => array( 'data.threeSegmentCode', 'threeSegmentCode' ) ),
				array( 'key' => 'destination_code', 'type' => 'code', 'group' => 'summary', 'paths' => array( 'data.destinationCode', 'destinationCode' ) ),
				array( 'key' => 'origin_code', 'type' => 'code', 'group' => 'summary', 'paths' => array( 'data.originCode', 'data.sourceCode', 'originCode' ) ),
				array( 'key' => 'service_type', 'type' => 'text', 'group' => 'summary', 'paths' => array( 'data.serviceName', 'data.productType', 'serviceName' ) ),
				array( 'key' => 'consolidated', 'type' => 'bool', 'group' => 'summary', 'paths' => array( 'data.consolidated', 'consolidated' ) ),
				array( 'key' => 'cod_amount', 'type' => 'money', 'group' => 'cost', 'paths' => array( 'data.codAmount', 'codAmount' ) ),
				array( 'key' => 'billing_weight', 'type' => 'weight', 'group' => 'cost', 'paths' => array( 'data.billingWeight', 'data.weight', 'weight' ) ),
				array( 'key' => 'remarks', 'type' => 'text', 'group' => 'meta', 'paths' => array( 'data.remark', 'remark' ) ),
			),
			'three_segment_code' => array(
				array( 'key' => 'three_segment', 'type' => 'code', 'group' => 'summary', 'paths' => array( 'data.threeSegmentCode', 'data.threeCode', 'threeSegmentCode' ) ),
				array( 'key' => 'destination_code', 'type' => 'code', 'group' => 'summary', 'paths' => array( 'data.destinationCode', 'destinationCode' ) ),
			),
			'batch_waybill_number' => array(
				array( 'key' => 'waybill', 'type' => 'code', 'group' => 'summary', 'paths' => array( 'data.billCode', 'data.billCodes', 'billCode' ) ),
				array( 'key' => 'quantity', 'type' => 'number', 'group' => 'summary', 'paths' => array( 'data.count', 'data.quantity', 'count' ) ),
			),
			'waybill_balance' => array(
				array( 'key' => 'balance', 'type' => 'money', 'group' => 'cost', 'paths' => array( 'data.balance', 'data.amount', 'balance' ) ),
				array( 'key' => 'currency', 'type' => 'text', 'group' => 'cost', 'paths' => array( 'data.currency', 'currency' ) ),
			),
			'network_info' => array(
				array( 'key' => 'network_name', 'type' => 'text', 'group' => 'summary', 'paths' => array( 'data.networkName', 'data.name', 'networkName' ) ),
				array( 'key' => 'network_code', 'type' => 'code', 'group' => 'summary', 'paths' => array( 'data.networkCode', 'data.code', 'networkCode' ) ),
				array( 'key' => 'sender_address', 'type' => 'text', 'group' => 'party', 'paths' => array( 'data.address', 'address' ) ),
				array( 'key' => 'sender_phone', 'type' => 'text', 'group' => 'party', 'paths' => array( 'data.phone', 'data.tel', 'phone' ) ),
			),
			'address' => array(
				array( 'key' => 'network_name', 'type' => 'text', 'group' => 'summary', 'paths' => array( 'data.name', 'name' ) ),
				array( 'key' => 'network_code', 'type' => 'code', 'group' => 'summary', 'paths' => array( 'data.code', 'code' ) ),
			),
			'dispatch_code' => array(
				array( 'key' => 'destination_code', 'type' => 'code', 'group' => 'summary', 'paths' => array( 'data.dispatchCode', 'data.code', 'dispatchCode' ) ),
			),
			'out_of_area' => array(
				array( 'key' => 'status', 'type' => 'text', 'group' => 'summary', 'paths' => array( 'data.cover', 'data.result', 'cover' ) ),
			),
			'delivery_range' => array(
				array( 'key' => 'network_name', 'type' => 'text', 'group' => 'summary', 'paths' => array( 'data.areaName', 'data.name', 'areaName' ) ),
			),
		);

		$interface = sanitize_key( (string) $interface );
		$fields    = isset( $maps[ $interface ] ) ? $maps[ $interface ] : array();

		if ( $with_shared ) {
			$fields = array_merge( self::shared_fields(), $fields );
		}

		return (array) apply_filters( 'lwc_jtc_field_map', $fields, $interface );
	}

	/** Fields that every interface can expose (transport/protocol level). */
	private static function shared_fields() {
		return array(
			array( 'key' => 'response_code', 'type' => 'code', 'group' => 'meta', 'paths' => array( 'code' ) ),
			array( 'key' => 'response_message', 'type' => 'text', 'group' => 'meta', 'paths' => array( 'msg', 'message' ) ),
		);
	}

	/**
	 * Repeated blocks (lists) per interface.
	 *
	 * The renderer walks the candidate paths and uses the first one that yields
	 * a non-empty sequential array. Row count and columns follow the payload:
	 * nothing is truncated to a fixed number and no column is invented.
	 *
	 * @param string $interface Interface slug.
	 * @return array
	 */
	public static function get_collection_map( $interface ) {
		$maps = array(
			'shipment_track' => array(
				array(
					'id'    => 'tracking',
					'title' => __( 'Riwayat pengiriman', 'lovecatz-wc' ),
					'paths' => array( 'data.traces', 'data.traceList', 'data.details', 'data.trace', 'data.list', 'data.records', 'traces', 'details' ),
				),
			),
			'orders' => array(
				array(
					'id'    => 'orders',
					'title' => __( 'Daftar pengiriman', 'lovecatz-wc' ),
					'paths' => array( 'data.orders', 'data.list', 'data.records', 'data', 'orders' ),
				),
			),
			'delivery_range' => array(
				array(
					'id'    => 'areas',
					'title' => __( 'Wilayah layanan', 'lovecatz-wc' ),
					'paths' => array( 'data.areas', 'data.list', 'data', 'areas' ),
				),
			),
			'network_info' => array(
				array(
					'id'    => 'networks',
					'title' => __( 'Gerai', 'lovecatz-wc' ),
					'paths' => array( 'data.list', 'data.networks', 'data', 'networks' ),
				),
			),
			'address' => array(
				array(
					'id'    => 'regions',
					'title' => __( 'Provinsi, kota, kecamatan', 'lovecatz-wc' ),
					'paths' => array( 'data.list', 'data.areas', 'data', 'list' ),
				),
			),
			'batch_waybill_number' => array(
				array(
					'id'    => 'waybills',
					'title' => __( 'Nomor resi', 'lovecatz-wc' ),
					'paths' => array( 'data.billCodes', 'data.list', 'billCodes' ),
				),
			),
		);

		$interface  = sanitize_key( (string) $interface );
		$collection = isset( $maps[ $interface ] ) ? $maps[ $interface ] : array();

		// Any list of rows the server sends is worth showing, even for
		// interfaces this plugin does not model explicitly.
		$collection[] = array(
			'id'    => 'auto',
			'title' => __( 'Data baris dari server', 'lovecatz-wc' ),
			'paths' => array( 'data' ),
			'auto'  => true,
		);

		return (array) apply_filters( 'lwc_jtc_collection_map', $collection, $interface );
	}

	/**
	 * Column ordering hints for repeated rows.
	 *
	 * Only used to order and label columns that are actually present. Unknown
	 * keys are still rendered, appended after the known ones.
	 *
	 * @return array raw property name (lowercased) => logical key.
	 */
	public static function get_row_hints() {
		return (array) apply_filters(
			'lwc_jtc_row_hints',
			array(
				'scantime'        => 'updated_at',
				'scandate'        => 'updated_at',
				'time'            => 'updated_at',
				'createdate'      => 'created_at',
				'createtime'      => 'created_at',
				'status'          => 'status',
				'statusname'      => 'status',
				'statusdesc'      => 'status',
				'scantype'        => 'status_code',
				'desc'            => 'remarks',
				'description'     => 'remarks',
				'remark'          => 'remarks',
				'content'         => 'remarks',
				'areaname'        => 'network_name',
				'location'        => 'network_name',
				'site'            => 'network_name',
				'sitename'        => 'network_name',
				'city'            => 'network_name',
				'operator'        => 'sender_name',
				'courier'         => 'sender_name',
				'couriername'     => 'sender_name',
				'phone'           => 'sender_phone',
				'courierphone'    => 'sender_phone',
				'mobile'          => 'sender_phone',
				'billcode'        => 'waybill',
				'waybillno'       => 'waybill',
				'orderno'         => 'order_no',
				'weight'          => 'billing_weight',
				'freight'         => 'freight',
				'aging'           => 'estimate',
			)
		);
	}

	/** Human label for a raw property name that the map does not know. */
	public static function get_column_label( $raw_key ) {
		$raw_key = (string) $raw_key;
		$hints   = self::get_row_hints();
		$lookup  = strtolower( preg_replace( '/[^A-Za-z0-9]/', '', $raw_key ) );

		if ( isset( $hints[ $lookup ] ) ) {
			$label = self::get_label( $hints[ $lookup ] );
			if ( '' !== $label ) {
				return $label;
			}
		}

		if ( LWC_JTC_Text::has_cjk( $raw_key ) ) {
			$converted = LWC_JTC_Text::to_latin( $raw_key );
			if ( '' !== $converted['text'] ) {
				return ucfirst( $converted['text'] );
			}
			return $raw_key;
		}

		// camelCase / snake_case -> Capitalised Words.
		$spaced = trim( preg_replace( '/[_\-]+/', ' ', preg_replace( '/(?<!^)([A-Z])/', ' $1', $raw_key ) ) );
		$spaced = preg_replace( '/\s+/', ' ', (string) $spaced );

		return '' === $spaced ? $raw_key : ucfirst( $spaced );
	}
}
