<?php
/**
 * Bootstrap header for a LoveCatz CLI verification harness.
 *
 * Copy the top block into tmp/<harness>.php. Never load a harness through the
 * web server; it must run from the CLI so is_admin() stays false and no
 * customer session is created.
 *
 * Run: "C:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe" tmp/<harness>.php
 */

define( 'WP_USE_THEMES', false );
require 'C:/laragon/www/ddistillers/wp-load.php';

// Needed only when the code under test calls admin template functions
// (submit_button(), add_meta_box(), ...). Harness limitation, not a bug.
require_once ABSPATH . 'wp-admin/includes/template.php';

// ---------------------------------------------------------------------------
// Assertion helpers. Print a PASS/FAIL line per check and a final count.
// ---------------------------------------------------------------------------

$GLOBALS['lwc_failures'] = 0;

/**
 * Assert a labelled condition.
 *
 * @param string $label Human-readable expectation.
 * @param bool   $ok    Result.
 * @param mixed  $actual Optional value to print when the check fails.
 */
function lwc_assert( $label, $ok, $actual = null ) {
	printf( "[%s] %s\n", $ok ? 'PASS' : 'FAIL', $label );
	if ( ! $ok ) {
		$GLOBALS['lwc_failures']++;
		if ( null !== $actual ) {
			printf( "       actual: %s\n", is_scalar( $actual ) ? var_export( $actual, true ) : wp_json_encode( $actual ) );
		}
	}
}

/**
 * Assert a float with a tolerance. Never compare floats with ===.
 */
function lwc_assert_money( $label, $expected, $actual ) {
	$ok = abs( (float) $expected - (float) $actual ) < 0.01;
	printf( "[%s] %s (expected %s, got %s)\n", $ok ? 'PASS' : 'FAIL', $label, number_format( (float) $expected, 2, '.', '' ), number_format( (float) $actual, 2, '.', '' ) );
	if ( ! $ok ) {
		$GLOBALS['lwc_failures']++;
	}
}

/** Print the final summary. Always call this last, even on the failure path. */
function lwc_summary() {
	printf( "\n%s\n", $GLOBALS['lwc_failures'] ? "{$GLOBALS['lwc_failures']} check(s) failed" : 'All checks passed' );
}

// ---------------------------------------------------------------------------
// Optional: wrap database-mutating work so nothing survives.
// ---------------------------------------------------------------------------

/** Open a transaction that can be discarded. */
function lwc_begin_rollback() {
	global $wpdb;
	$wpdb->query( 'START TRANSACTION' );
}

/** Discard everything written since lwc_begin_rollback(). */
function lwc_rollback() {
	global $wpdb;
	$wpdb->query( 'ROLLBACK' );
}
