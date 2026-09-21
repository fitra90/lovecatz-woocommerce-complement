<?php
/**
 * CLI probe: which J&T Cargo sandbox credentials are actually stored?
 * Prints filled/empty only — never prints secret values.
 */

define( 'WP_USE_THEMES', false );
require 'C:/laragon/www/ddistillers/wp-load.php';

$fields = array( 'customer_code', 'uuid', 'api_account', 'private_key', 'customer_password' );
$envs   = array( 'sandbox', 'production' );

foreach ( $envs as $env ) {
	$prefix = "lwc_jt_cargo_{$env}";
	echo "=== {$env} ===\n";
	foreach ( $fields as $field ) {
		$raw = get_option( "{$prefix}_{$field}", null );
		$state = ( null === $raw ) ? 'UNSET' : ( ( '' === trim( (string) $raw ) ) ? 'EMPTY' : 'FILLED' );
		$len   = is_string( $raw ) ? strlen( $raw ) : 0;
		// show a masked peek so we can tell real values from accidental junk
		$peek  = ( 'FILLED' === $state ) ? substr( (string) $raw, 0, 2 ) . str_repeat( '*', max( 0, min( $len, 20 ) - 2 ) ) : '';
		printf( "  %-18s %-7s len=%-3d %s\n", $field, $state, $len, $peek );
	}

	// legacy fallbacks (only matter when the canonical field is empty)
	$cc = get_option( "{$prefix}_customer_code", '' );
	if ( '' === trim( (string) $cc ) ) {
		$fb = get_option( "{$prefix}_username", '' );
		printf( "  %-18s %-7s len=%-3d (fallback _username)\n", 'customer_code', '' === trim( (string) $fb ) ? 'EMPTY' : 'FILLED', strlen( (string) $fb ) );
	}
	$aa = get_option( "{$prefix}_api_account", '' );
	if ( '' === trim( (string) $aa ) ) {
		$fb = get_option( "{$prefix}_api_key", '' );
		printf( "  %-18s %-7s len=%-3d (fallback _api_key)\n", 'api_account', '' === trim( (string) $fb ) ? 'EMPTY' : 'FILLED', strlen( (string) $fb ) );
	}
	$pk = get_option( "{$prefix}_private_key", '' );
	if ( '' === trim( (string) $pk ) ) {
		$fb = get_option( "{$prefix}_api_secret", '' );
		printf( "  %-18s %-7s len=%-3d (fallback _api_secret)\n", 'private_key', '' === trim( (string) $fb ) ? 'EMPTY' : 'FILLED', strlen( (string) $fb ) );
	}
	echo "\n";
}

echo "=== effective sandbox credentials (what the API will use) ===\n";
if ( class_exists( 'LWC_JTC_Account' ) ) {
	$creds = LWC_JTC_Account::get_credentials( 'sandbox' );
	foreach ( $creds as $k => $v ) {
		printf( "  %-18s %-7s len=%d\n", $k, '' === trim( (string) $v ) ? 'EMPTY' : 'FILLED', strlen( (string) $v ) );
	}
	if ( class_exists( 'LWC_JTC_API' ) ) {
		$r = new ReflectionClass( 'LWC_JTC_API' );
		$m = $r->getMethod( 'get_missing_signing_credentials' );
		$m->setAccessible( true );
		$missing = $m->invoke( null, $creds );
		echo "\n  MISSING (blocks the request): " . ( empty( $missing ) ? '(none — retry will go through)' : implode( ', ', $missing ) ) . "\n";
	}
} else {
	echo "  LWC_JTC_Account class not loaded\n";
}

echo "\n=== other relevant options ===\n";
foreach ( array( 'lwc_jt_cargo_environment', 'lwc_jt_cargo_test_mode', 'lwc_jtc_sandbox_test_progress' ) as $opt ) {
	$v = get_option( $opt, null );
	if ( is_array( $v ) ) {
		printf( "  %-32s array(%d)\n", $opt, count( $v ) );
	} else {
		printf( "  %-32s %s\n", $opt, null === $v ? 'UNSET' : (string) $v );
	}
}
