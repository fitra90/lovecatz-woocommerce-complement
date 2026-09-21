<?php
/**
 * Offline renderer that produces a standalone HTML preview of the J&T Cargo
 * label using a fabricated order. Development aid only — never loaded by
 * the production plugin.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_html_x' ) ) {
	function esc_html_x( $s, $ctx = '', $domain = '' ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $s, $domain = '' ) { return $s; }
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $domain = '' ) { return $s; }
}
if ( ! function_exists( 'wp_trim_words' ) ) {
	function wp_trim_words( $text, $num_words, $more ) {
		$words = preg_split( '/\s+/', trim( (string) $text ) );
		if ( count( $words ) <= $num_words ) {
			return implode( ' ', $words );
		}
		return implode( ' ', array_slice( $words, 0, $num_words ) ) . $more;
	}
}
if ( ! function_exists( 'get_locale' ) ) {
	function get_locale() { return 'zh_CN'; }
}

$data = array(
	'order_id'             => 12345,
	'company_name'         => 'LoveCatz Distillers',
	'company_logo_text'    => 'J&T Cargo',
	'company_logo_url'     => ( function () {
		$path = __DIR__ . '/../assets/logo-jnt-cargo.png';
		if ( ! file_exists( $path ) ) {
			return '';
		}
		return 'data:image/png;base64,' . base64_encode( (string) file_get_contents( $path ) );
	} )(),
	'hotline'              => '021-8066-1888',
	'waybill'              => '380 100-01 01',
	'product_type'         => 'Reguler',
	'three_segment'        => 'YT4139448519291',
	'three_segment_wm'     => '4139448519291',
	'print_time'           => date( 'Y/m/d H:i:s' ),
	'sheet_seq'            => '1/1',
	'consolidated'         => false,
	'origin_code'          => 'JKT',
	'recipient'            => array(
		'name'        => 'Budi Santoso',
		'phone'       => '0812-3456-7890',
		'company'     => 'PT Cahaya Bahagia Sejahtera',
		'state'       => 'DKI Jakarta',
		'city'        => 'Jakarta Barat',
		'district'    => 'Kalideres',
		'street'      => 'Jl. Daan Mogot Km. 12 No. 57, RT/RW 003/005',
		'address'     => 'DKI Jakarta Jakarta Barat Kalideres Jl. Daan Mogot Km. 12 No. 57, RT/RW 003/005',
		'has_company' => true,
	),
	'sender'               => array(
		'name'       => 'Siti Rahmawati',
		'phone'      => '0811-9876-5432',
		'company'    => 'LoveCatz Distillers',
		'country'    => 'Indonesia',
		'state'      => 'DKI Jakarta',
		'state_code' => 'JK',
		'city'       => 'Jakarta Barat',
		'district'   => 'Kalideres',
		'address_1'  => 'Jl. Contoh No. 1',
		'address_2'  => '',
		'postcode'   => '11840',
		'address'    => 'DKI Jakarta Jakarta Barat Kalideres Jl. Contoh No. 1',
		'code'       => 'JK',
	),
	'cod_amount'             => '350000',
	'cod_currency_symbol'    => 'Rp',
	'freight_collect'        => '45000',
	'freight_currency_symbol' => 'Rp',
	'has_insurance'          => true,
	'currency_symbol'        => 'Rp',
	'billing_weight'         => 2.0,
	'billing_weight_unit'    => 'kg',
	'remarks'                => 'Mohon ditangani dengan hati-hati, hindari sinar matahari langsung. Kemasan sudah diperkuat, silakan terima dengan aman.',
	'wechat_label'           => 'WhatsApp: 0812-0000-0000',
	'signature_terms'        => 'Tanda tangan Anda menyatakan bahwa paket telah diterima, isi sesuai pesanan, dan kemasan utuh tanpa kerusakan pada permukaannya.',
	'qr_data'                => 'https://example.com/order/12345',
	'api_values'             => array(),
	'is_preview'             => false,
);

$template = __DIR__ . '/jtc-label.php';
if ( ! file_exists( $template ) ) {
	fwrite( STDERR, "Template not found: $template\n" );
	exit( 1 );
}
include $template;