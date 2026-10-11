<?php
/**
 * Concurrent aggregate test with independent native PHP processes/connections.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Integration;

use EgressEye\Persistence\Database;
use EgressEye\Persistence\DomainRepository;
use EgressEye\Persistence\RetentionService;
use EgressEye\Persistence\SchemaManager;

/**
 * Detects lost updates rather than merely checking SQL text.
 */
final class AtomicUpsertTest extends \WP_UnitTestCase {

	/**
	 * Twenty processes each perform 25 upserts with separate live connections.
	 *
	 * @return void
	 */
	public function test_twenty_processes_have_exact_atomic_count_and_sum(): void {
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$database = new Database( $wpdb );
		$database->execute( 'COMMIT' );
		$database->execute( 'SET autocommit = %d', array( 1 ) );
		$schema     = new SchemaManager( $database );
		$retention  = new RetentionService( $database );
		$repository = new DomainRepository( $database, $retention );
		$host       = 'atomic-' . bin2hex( random_bytes( 8 ) ) . '.example';
		$workers    = array();
		$ids        = array();
		$barrier    = tempnam( sys_get_temp_dir(), 'egress-eye-barrier-' );
		self::assertTrue( $schema->install() );
		self::assertGreaterThan( 0, $repository->ensure_host( $host ) );
		update_option( RetentionService::LAST_OPTION, time(), true );
		// WP_UnitTestCase may wrap setup in a transaction; workers need committed fixtures.
		$database->execute( 'COMMIT' );
		self::assertIsString( $barrier );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only process synchronization file.
		unlink( $barrier );
		try {
			for ( $i = 0; $i < 20; ++$i ) {
				$command = array( PHP_BINARY, dirname( __DIR__ ) . '/Fixtures/upsert-worker.php', $barrier, $host, '25', $wpdb->prefix );
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Independent native test worker, not production process execution.
				$process = proc_open(
					$command,
					array(
						0 => array( 'pipe', 'r' ),
						1 => array( 'pipe', 'w' ),
						2 => array( 'pipe', 'w' ),
					),
					$pipes
				);
				self::assertIsResource( $process );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing test process input.
				fclose( $pipes[0] );
				stream_set_timeout( $pipes[1], 30 );
				$workers[] = array( $process, $pipes );
			}
			foreach ( $workers as $worker ) {
				$ready = stream_get_line( $worker[1][1], 4096, "\n" );
				self::assertMatchesRegularExpression( '/^READY [0-9]+$/', $ready );
				$ids[] = substr( $ready, 6 );
			}
			self::assertCount( 20, array_unique( $ids ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Releasing the test worker barrier.
			file_put_contents( $barrier, 'go' );
			foreach ( $workers as &$worker ) {
				$output = stream_get_contents( $worker[1][1] );
				$error  = stream_get_contents( $worker[1][2] );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing test process output.
				fclose( $worker[1][1] );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing test process error output.
				fclose( $worker[1][2] );
				$status    = proc_close( $worker[0] );
				$worker[0] = null;
				self::assertSame( 0, $status, $error );
				self::assertSame( "DONE 25\n", $output );
			}
			unset( $worker );
			$row = $database->row( 'SELECT slow_count, slow_duration_sum_us FROM %i WHERE host = %s', array( $database->table( 'domains' ), $host ) );
			self::assertSame( 500, (int) $row['slow_count'] );
			self::assertSame( 1000000000, (int) $row['slow_duration_sum_us'] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CI proof to process stderr.
			fwrite( STDERR, "Atomic proof: 20 distinct connections, 500 upserts, count=500, sum_us=1000000000\n" );
		} finally {
			foreach ( $workers as $worker ) {
				if ( is_resource( $worker[0] ) ) {
					proc_terminate( $worker[0] );
					proc_close( $worker[0] );
				}
			}
			if ( is_file( $barrier ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the test barrier.
				unlink( $barrier );
			}
			remove_action( 'shutdown', array( $retention, 'run_maintenance' ), 20 );
		}
	}
}
