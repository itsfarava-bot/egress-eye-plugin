<?php
/**
 * Independent WordPress runtime for the atomic database test only.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Isolated test process uses WordPress config names.
$egress_eye_test_config = getenv( 'WP_TESTS_DIR' ) . '/wp-tests-config.php';
require $egress_eye_test_config;
if ( $table_prefix !== $argv[4] ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Test process stderr, not a filesystem write.
	fwrite( STDERR, "Worker database prefix does not match the fixture.\n" );
	exit( 1 );
}
$_SERVER['HTTP_HOST']       = 'example.org';
$_SERVER['SERVER_NAME']     = 'example.org';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
require ABSPATH . 'wp-settings.php';
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$egress_eye_database   = new \EgressEye\Persistence\Database( $wpdb );
$egress_eye_retention  = new \EgressEye\Persistence\RetentionService( $egress_eye_database );
$egress_eye_repository = new \EgressEye\Persistence\DomainRepository( $egress_eye_database, $egress_eye_retention );
$egress_eye_sample     = new \EgressEye\Diagnostics\SlowRequest( $argv[2], 2000000, time() );
$egress_eye_ready      = $egress_eye_database->scalar( 'SELECT CONNECTION_ID() + %d', array( 0 ) );
echo 'READY ' . (int) $egress_eye_ready . "\n";
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fflush -- Flush the worker readiness signal.
fflush( STDOUT );
$egress_eye_deadline = time() + 40;
while ( ! is_file( $argv[1] ) && time() < $egress_eye_deadline ) {
	usleep( 1000 );
}
if ( ! is_file( $argv[1] ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Test process stderr.
	fwrite( STDERR, "Worker barrier timed out.\n" );
	exit( 2 );
}
for ( $egress_eye_index = 0; $egress_eye_index < (int) $argv[3]; ++$egress_eye_index ) {
	if ( null === $egress_eye_repository->upsert_slow_aggregate( $egress_eye_sample ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Test process stderr with fixture-only SQL diagnostics.
		fwrite( STDERR, 'Worker upsert failed: ' . $wpdb->last_error . "\n" );
		exit( 3 );
	}
}
echo 'DONE ' . (int) $argv[3] . "\n";
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals
