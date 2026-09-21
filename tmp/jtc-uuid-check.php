<?php
/**
 * Does the plugin actually append ?uuid= to sandbox endpoints?
 * Replicates LWC_JTC_API::normalize_uuid() against the shipped default
 * and against a real-looking UUID.
 */

$src = file_get_contents( __DIR__ . '/../shipping/jtc/class-lwc-jtc-api.php' );
preg_match( "/SANDBOX_DEFAULT_UUID\s*=\s*'([^']*)'/", $src, $m );
$default = isset( $m[1] ) ? $m[1] : '(not found)';

function normalize_uuid( $uuid ) {
	$uuid = strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) $uuid ) );
	return 32 === strlen( $uuid ) ? $uuid : '';
}

$real = '9ca214d96680342d289dd5ec8d4a1412a';

echo "SANDBOX_DEFAULT_UUID (from source) = {$default}\n";
echo "  length            : " . strlen( $default ) . "\n";
echo "  after normalize   : '" . normalize_uuid( $default ) . "'\n";
echo "  -> uuid appended to endpoint? " . ( '' === normalize_uuid( $default ) ? 'NO  <-- BUG' : 'yes' ) . "\n\n";

echo "example real UUID                  = {$real}\n";
echo "  length            : " . strlen( $real ) . "\n";
echo "  after normalize   : '" . normalize_uuid( $real ) . "'\n";
echo "  -> uuid appended to endpoint? " . ( '' === normalize_uuid( $real ) ? 'NO' : 'yes' ) . "\n";
