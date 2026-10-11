<?php
/**
 * Deterministic local HTTP response for integration tests only.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- Test-only resource-based counter locking.
$egress_eye_counter_path = getenv( 'EGRESS_EYE_FIXTURE_COUNTER_PATH' );

if ( is_string( $egress_eye_counter_path ) && '' !== $egress_eye_counter_path ) {
	$egress_eye_counter_handle = fopen( $egress_eye_counter_path, 'r+' );

	if ( false === $egress_eye_counter_handle || ! flock( $egress_eye_counter_handle, LOCK_EX ) ) {
		http_response_code( 500 );
		exit( 'fixture counter failed' );
	}

	$egress_eye_request_count = (int) stream_get_contents( $egress_eye_counter_handle );
	rewind( $egress_eye_counter_handle );
	ftruncate( $egress_eye_counter_handle, 0 );
	fwrite( $egress_eye_counter_handle, (string) ( $egress_eye_request_count + 1 ) );
	fflush( $egress_eye_counter_handle );
	flock( $egress_eye_counter_handle, LOCK_UN );
	fclose( $egress_eye_counter_handle );
}
// phpcs:enable WordPress.WP.AlternativeFunctions

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test-only local latency fixture; no production state mutation.
if ( isset( $_GET['egress_eye_slow_fixture'] ) ) {
	usleep( 1100000 );
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Local test fixture redirect only.
if ( isset( $_GET['egress_eye_redirect'], $_SERVER['SERVER_PORT'] ) ) {
	header( 'Location: http://localhost:' . (int) $_SERVER['SERVER_PORT'] . '/?egress_eye_slow_fixture=1', true, 302 );
	exit;
}

header( 'Content-Type: text/plain; charset=utf-8' );
echo 'egress-eye fixture';
