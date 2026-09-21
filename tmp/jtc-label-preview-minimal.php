<?php
/**
 * Label preview with an empty carrier response.
 *
 * Proves the "no invented defaults" rule: every field the server did not send
 * is hidden instead of being replaced by a guessed value. Development aid only.
 *
 * Usage: php tmp/jtc-label-preview-minimal.php > tmp/jtc-label-preview-minimal.html
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
	function get_locale() { return 'id_ID'; }
}

// Everything the carrier would omit: no waybill, no service type, no weight,
// no COD, no notes, no configured hotline / contact / terms.
$data = array(
	'order_id'             => 12346,
	'company_name'         => 'LoveCatz Distillers',
	'company_logo_text'    => 'J&T Cargo',
	'company_logo_url'     => ( function () {
		$path = __DIR__ . '/../shipping/jtc/assets/logo-jnt-cargo.png';
		if ( ! file_exists( $path ) ) {
			return '';
		}
		return 'data:image/png;base64,' . base64_encode( (string) file_get_contents( $path ) );
	} )(),
	'hotline'              => '',
	'waybill'              => '',
	'product_type'         => '',
	'three_segment'        => '',
	'three_segment_wm'     => '',
	'print_time'           => date( 'Y/m/d H:i:s' ),
	'sheet_seq'            => '',
	'consolidated'         => false,
	'origin_code'          => '',
	'recipient'            => array(
		'name'        => 'Budi Santoso',
		'phone'       => '0812-3456-7890',
		'company'     => '',
		'state'       => 'DKI Jakarta',
		'city'        => 'Jakarta Barat',
		'district'    => 'Kalideres',
		'street'      => 'Jl. Daan Mogot Km. 12 No. 57',
		'address'     => 'DKI Jakarta Jakarta Barat Kalideres Jl. Daan Mogot Km. 12 No. 57',
		'has_company' => false,
	),
	'sender'               => array(
		'name'       => 'LoveCatz Distillers',
		'phone'      => '021-8066-1888',
		'company'    => '',
		'country'    => 'Indonesia',
		'state'      => 'DKI Jakarta',
		'state_code' => 'JK',
		'city'       => 'Jakarta Barat',
		'district'   => '',
		'address_1'  => 'Jl. Contoh No. 1',
		'address_2'  => '',
		'postcode'   => '11840',
		'address'    => 'DKI Jakarta Jakarta Barat Jl. Contoh No. 1',
		'code'       => 'JK',
	),
	'cod_amount'             => '',
	'cod_currency_symbol'    => 'Rp',
	'freight_collect'        => '',
	'freight_currency_symbol' => 'Rp',
	'has_insurance'          => false,
	'currency_symbol'        => 'Rp',
	'billing_weight'         => null,
	'billing_weight_unit'    => '',
	'remarks'                => '',
	'wechat_label'           => '',
	'signature_terms'        => '',
	'qr_data'                => 'https://example.com/order/12346',
	'api_values'             => array(),
	'is_preview'             => true,
);

include __DIR__ . '/../shipping/jtc/templates/jtc-label.php';
