<?php
/**
 * Compare the plugin's digest functions against the official J&T Cargo manual.
 * Manual = "Panduan Integrasi OpenAPI", PT. Global Jet Cargo, Jan 2025, section 3.3.
 */

$SALT = 'jadada236t2';

/** Manual 3.3.1, verbatim from the PDF sample. */
function manual_header_digest( $bizContent, $privateKey ) {
	$md5Hash = md5( $bizContent . $privateKey );
	return base64_encode( hex2bin( $md5Hash ) );
}

/** Manual 3.3.2, verbatim from the PDF sample. */
function manual_body_digest( $customerCode, $plaintextPwd, $privateKey ) {
	$salt          = 'jadada236t2';
	$md5Plaintext  = strtoupper( md5( $plaintextPwd . $salt ) );
	$combined      = $customerCode . $md5Plaintext . $privateKey;
	return base64_encode( hex2bin( md5( $combined ) ) );
}

/** Plugin, current (after fix). */
function plugin_header_digest( $biz_content, $private_key ) {
	return base64_encode( md5( (string) $biz_content . (string) $private_key, true ) );
}
function plugin_body_digest( $customer_code, $customer_password, $private_key ) {
	$password_hash = strtoupper( md5( (string) $customer_password . 'jadada236t2' ) );
	$source        = (string) $customer_code . $password_hash . (string) $private_key;
	return base64_encode( md5( $source, true ) );
}

/** Plugin, the ORIGINAL implementation (uppercased the concatenation). */
function old_body_digest( $customer_code, $customer_password, $private_key ) {
	$password_hash = md5( (string) $customer_password . 'jadada236t2' );
	$source        = strtoupper( (string) $customer_code . $password_hash ) . (string) $private_key;
	return base64_encode( md5( $source, true ) );
}

$cases = array(
	array( 'J0086024191', 'JRz1xzo9', '226a2abc', '{"customerCode":"J0086024191"}' ),
	array( 'j0086024191', 'JRz1xzo9', '226a2abc', '{"customerCode":"j0086024191"}' ),
	array( 'J0086024191', 'JRz1xzo9', 'MiXeDkEy123', '{"txlogisticId":"ID12345678"}' ),
);

$fail = 0;
foreach ( $cases as $i => $c ) {
	list( $code, $pwd, $key, $biz ) = $c;

	$mh = manual_header_digest( $biz, $key );
	$ph = plugin_header_digest( $biz, $key );
	$mb = manual_body_digest( $code, $pwd, $key );
	$pb = plugin_body_digest( $code, $pwd, $key );
	$ob = old_body_digest( $code, $pwd, $key );

	$hok = hash_equals( $mh, $ph );
	$bok = hash_equals( $mb, $pb );
	$oold_differs = ! hash_equals( $mb, $ob );

	printf( "case %d  customerCode=%s\n", $i + 1, $code );
	printf( "  header  manual=%s\n          plugin=%s  %s\n", $mh, $ph, $hok ? 'MATCH' : 'MISMATCH' );
	printf( "  body    manual=%s\n          plugin=%s  %s\n", $mb, $pb, $bok ? 'MATCH' : 'MISMATCH' );
	printf( "          old   =%s  %s\n", $ob, $oold_differs ? '(old code DIFFERED here)' : '(old code agreed here)' );

	if ( ! $hok || ! $bok ) {
		$fail++;
	}
}

echo "\n" . ( 0 === $fail ? "ALL MATCH the official manual\n" : "{$fail} case(s) still mismatch\n" );
