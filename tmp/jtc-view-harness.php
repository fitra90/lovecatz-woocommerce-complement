<?php
/**
 * Offline harness for the J&T Cargo dynamic response renderer.
 *
 * Feeds representative payloads (success with Chinese text, missing fields,
 * business error, empty body, unknown structure, transport failure) through
 * LWC_JTC_Response_View and prints the rendered HTML. Development aid only —
 * the production plugin never loads this file.
 *
 * Usage: php tmp/jtc-view-harness.php > tmp/jtc-view-harness.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
define( 'LWC_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		if ( ! is_array( $args ) ) {
			$args = array();
		}
		return array_merge( (array) $defaults, $args );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $key ) );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = '' ) { return $text; }
}
if ( ! function_exists( '_x' ) ) {
	function _x( $text, $context = '', $domain = '' ) { return $text; }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = '' ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = '' ) { echo htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = '' ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { return $value; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}

require_once LWC_PLUGIN_DIR . 'shipping/jtc/class-lwc-jtc-text.php';
require_once LWC_PLUGIN_DIR . 'shipping/jtc/class-lwc-jtc-field-map.php';
require_once LWC_PLUGIN_DIR . 'shipping/jtc/class-lwc-jtc-response-view.php';

$track_success = array(
	'interface'        => 'shipment_track',
	'http_status'      => 200,
	'business_success' => true,
	'business_code'    => '1',
	'business_message' => 'success',
	'response'         => array(
		'code' => '1',
		'msg'  => 'success',
		'data' => array(
			'billCode'        => 'JT3123456789012',
			'statusName'      => '已签收',
			'statusCode'      => 'SIGNED',
			'serviceName'     => '标准快递',
			'receiverName'    => '张三',
			'receiverPhone'   => '18412345678',
			'receiverAddress' => '上海市青浦区华新镇新风中路57号',
			'senderName'      => '李四',
			'senderPhone'     => '021-8888-8888',
			'senderAddress'   => '广东省深圳市宝安区',
			'billingWeight'   => '2.5',
			'weightUnit'      => 'kg',
			'realWeight'      => '2.3',
			'quantity'        => 1,
			'freight'         => '25.00',
			'codAmount'       => '200000',
			'currency'        => 'IDR',
			'aging'           => '2天',
			'deliveryTime'    => '2026-09-20 15:42:11',
			'remark'          => '请轻拿轻放',
			'traces'          => array(
				array(
					'scanTime'    => '2026-09-18 09:12:00',
					'status'      => '已揽收',
					'desc'        => '快件已被上海青浦网点揽收',
					'areaName'    => '上海青浦',
					'operator'    => '王五',
					'courierPhone' => '13800000000',
				),
				array(
					'scanTime' => '2026-09-18 21:03:00',
					'status'   => '已到达',
					'desc'     => '快件已到达上海青浦中转部',
					'areaName' => '上海青浦',
				),
				array(
					'scanTime'  => '2026-09-19 02:40:00',
					'status'    => '运输中',
					'desc'      => '快件已离开上海青浦中转部，发往雅加达',
					'vehicleNo' => '沪A12345',
				),
				array(
					'scanTime' => '2026-09-20 10:15:00',
					'status'   => '派送中',
					'desc'     => '快递员正在派送，请保持电话畅通',
					'operator' => 'Budi',
				),
				array(
					'scanTime' => '2026-09-20 15:42:11',
					'status'   => '已签收',
					'desc'     => '签收人：本人',
					'areaName' => 'Jakarta Barat',
				),
			),
		),
	),
);

$track_missing = array(
	'interface'        => 'shipment_track',
	'http_status'      => 200,
	'business_success' => true,
	'business_code'    => '1',
	'business_message' => 'success',
	'response'         => array(
		'code' => '1',
		'msg'  => 'success',
		'data' => array(
			'billCode'      => null,
			'billingWeight' => '',
			'freight'       => null,
			'traces'        => array(),
			'statusName'    => '暂无',
		),
	),
);

$freight = array(
	'interface'        => 'freight_calculation',
	'http_status'      => 200,
	'business_success' => true,
	'business_code'    => '1',
	'business_message' => '成功',
	'response'         => array(
		'code' => '1',
		'msg'  => '成功',
		'data' => array(
			'freight'       => '128000',
			'totalCost'     => '145000',
			'insuranceFee'  => '17000',
			'currency'      => 'IDR',
			'billingWeight' => '3.2',
			'weightUnit'    => 'kg',
			'aging'         => '3',
			'estimateTime'  => '2026-09-24',
			'payType'       => '寄付',
		),
	),
);

$business_error = array(
	'interface'        => 'shipment_track',
	'http_status'      => 200,
	'business_success' => false,
	'business_code'    => '0',
	'business_message' => '运单不存在',
	'response'         => array(
		'code' => '0',
		'msg'  => '运单不存在',
	),
);

$empty_body = array(
	'interface'        => 'orders',
	'http_status'      => 200,
	'business_success' => true,
	'business_code'    => '1',
	'business_message' => 'success',
	'response'         => array( 'code' => '1', 'msg' => 'success' ),
);

$unknown_structure = array(
	'interface'        => 'orders',
	'http_status'      => 200,
	'business_success' => true,
	'business_code'    => '1',
	'business_message' => 'success',
	'response'         => array(
		'code' => '1',
		'data' => array(
			'fooBarBaz'  => '某个未知字段',
			'totalPages' => 3,
			'nested'     => array( 'deep' => '深层值' ),
		),
	),
);

$no_result_code = array(
	'interface'        => 'shipment_track',
	'http_status'      => 200,
	'business_success' => false,
	'business_code'    => '',
	'business_message' => 'Respons tidak menyertakan kode hasil bisnis.',
	'response'         => array(
		'data' => array(
			'billCode' => 'JT9876543210',
			'statusName' => '运输中',
		),
	),
);

$transport_error = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 30000 milliseconds' );

$scenarios = array(
	'Lacak pengiriman — sukses, riwayat 5 baris berbahasa China' => array( 'shipment_track', $track_success ),
	'Lacak pengiriman — field kosong/null (tidak dikarang)'      => array( 'shipment_track', $track_missing ),
	'Hitung tarif — biaya, berat, estimasi'                      => array( 'freight_calculation', $freight ),
	'Ditolak J&T — kode bisnis 0'                                => array( 'shipment_track', $business_error ),
	'Respons kosong'                                             => array( 'orders', $empty_body ),
	'Struktur tidak dikenali'                                    => array( 'orders', $unknown_structure ),
	'Struktur berubah — HTTP 200 tanpa kode hasil'               => array( 'shipment_track', $no_result_code ),
	'Gagal transport (WP_Error)'                                 => array( 'shipment_track', $transport_error ),
);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Harness renderer respons dinamis J&T Cargo</title>
<style>
	body { margin: 0; padding: 24px; background: #f0f0f1; color: #1d2327;
		font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; font-size: 14px; }
	h1 { font-size: 18px; margin: 0 0 4px; }
	p.lead { color: #50575e; margin: 0 0 24px; }
	.case { background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 16px; margin-bottom: 20px; }
	.case > h2 { font-size: 14px; margin: 0 0 12px; padding-bottom: 8px; border-bottom: 1px solid #f0f0f1; }
</style>
</head>
<body>
<h1>Renderer respons dinamis J&T Cargo</h1>
<p class="lead">Semua nilai berasal dari payload server melalui pemetaan field. Field kosong disembunyikan, teks non-Latin diterjemahkan/dialihaksarakan, dan data asli tidak diubah.</p>
<?php foreach ( $scenarios as $title => $scenario ) : ?>
	<div class="case">
		<h2><?php echo esc_html( $title ); ?></h2>
		<?php
		$view = LWC_JTC_Response_View::build( $scenario[0], $scenario[1] );
		echo LWC_JTC_Response_View::render( $view, array( 'show_raw' => true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template escapes.
		?>
	</div>
<?php endforeach; ?>
</body>
</html>
