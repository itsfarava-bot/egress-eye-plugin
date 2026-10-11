<?php
/**
 * Independent PHP request evidence for installed guard, recorder and package.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Integration;

use EgressEye\Tests\Support\AdminTestCase;
use EgressEye\Tests\Support\LocalHttpServer;

/**
 * Proves actual fixture transport rather than refreshing a guard mid-request.
 */
final class InstalledRuntimeTest extends AdminTestCase {
	/**
	 * New coverage: next PHP request immediately observes committed block/unblock.
	 *
	 * @return void
	 */
	public function test_next_php_request_blocks_transport_and_unblock_permits_it(): void {
		$server                     = new LocalHttpServer();
		$id                         = $this->domains->ensure_host( '127.0.0.1' );
		$fields                     = $this->fields( $id );
		$fields['confirm_loopback'] = '1';
		try {
			$this->post_action( $fields );
			$result = $this->native_request( $server->get_url() );
			self::assertSame( 'egress_eye_host_blocked', $result['response'] );
			self::assertSame( 1, $result['guards'] );
			self::assertSame( 0, $server->get_request_count() );
			$this->post_action( $this->fields( $id, 'unblock' ) );
			$result = $this->native_request( $server->get_url() );
			self::assertSame( 200, $result['response'] );
			self::assertSame( 1, $result['guards'] );
			self::assertSame( 1, $server->get_request_count() );
		} finally {
			$server->close();
		}
	}

	/**
	 * New coverage: early protection avoids premature translation loading.
	 *
	 * @return void
	 */
	public function test_early_block_has_no_premature_i18n_load(): void {
		$server = new LocalHttpServer();
		self::assertTrue( $this->domains->set_blocked( '127.0.0.1', true, $this->actor ) );
		try {
			$result = $this->native_request( $server->get_url(), 'early' );
			self::assertSame( 'egress_eye_host_blocked', $result['response'] );
			self::assertSame( 'This host is blocked by Egress Eye.', $result['message'] );
			self::assertSame( array(), $result['wrong'] );
			self::assertSame( 0, $server->get_request_count() );
		} finally {
			$server->close();
		}
	}

	/**
	 * New coverage: native slow record agrees with loopback UI and safe notification.
	 *
	 * @return void
	 */
	public function test_native_slow_record_marks_loopback_and_contains_throwing_listener(): void {
		$server = new LocalHttpServer();
		try {
			$fast = $this->native_request( $server->get_url() );
			self::assertSame( array(), $fast['events'] );
			self::assertSame( 0, (int) $this->database->scalar( 'SELECT COUNT(*) FROM %i', array( $this->database->table( 'events' ) ) ) );
			$result = $this->native_request( $server->get_url() . '?egress_eye_slow_fixture=1&secret=private-query', 'throw' );
			self::assertSame( 200, $result['response'] );
			self::assertFalse( $result['stack'] );
			self::assertFalse( $result['debug_priority'] );
			self::assertCount( 1, $result['events'] );
			self::assertSame( array( 'host', 'duration_us', 'is_loopback' ), array_keys( $result['events'][0] ) );
			self::assertSame( '127.0.0.1', $result['events'][0]['host'] );
			self::assertTrue( $result['events'][0]['is_loopback'] );
			self::assertGreaterThanOrEqual( 1000000, $result['events'][0]['duration_us'] );
			self::assertSame( 1, (int) $this->database->scalar( 'SELECT is_loopback FROM %i', array( $this->database->table( 'domains' ) ) ) );
			self::assertSame( 1, (int) $this->database->scalar( 'SELECT is_loopback FROM %i', array( $this->database->table( 'events' ) ) ) );
			foreach ( array( 'private-query', 'private-header', 'private-body', 'sample_key', 'http://' ) as $secret ) {
				self::assertStringNotContainsString( $secret, wp_json_encode( $result['events'] ) );
			}
		} finally {
			$server->close();
		}
	}

	/**
	 * New coverage: extracted ZIP boots and protects transport without vendor.
	 *
	 * @return void
	 */
	public function test_production_zip_boots_without_development_dependencies(): void {
		$directory = sys_get_temp_dir() . '/egress-eye-package-' . bin2hex( random_bytes( 8 ) );
		$server    = new LocalHttpServer();
		$root      = dirname( __DIR__, 2 );
		$command   = array( 'bash', $root . '/build/package-plugin.sh', $directory );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Test-only execution of sole package builder.
		$process = proc_open( $command, array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Test process resource boundary.
		fclose( $pipes[0] );
		stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Test process resource boundary.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Test process resource boundary.
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $error );
		$archive = new \ZipArchive();
		self::assertTrue( $archive->open( $directory . '/egress-eye.zip' ) );
		try {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native ZipArchive API property.
			for ( $index = 0; $index < $archive->numFiles; ++$index ) {
				self::assertDoesNotMatchRegularExpression( '#/(vendor|tests|docs|build|\.github)/#', $archive->getNameIndex( $index ) );
			}
			self::assertTrue( $archive->extractTo( $directory ) );
			self::assertTrue( $this->domains->set_blocked( '127.0.0.1', true, $this->actor ) );
			$result = $this->native_request( $server->get_url(), 'normal', $directory . '/egress-eye/egress-eye.php' );
			self::assertSame( 'egress_eye_host_blocked', $result['response'] );
			self::assertSame( 1, $result['guards'] );
			self::assertSame( 0, $server->get_request_count() );
			self::assertFileExists( $directory . '/egress-eye/languages/egress-eye-fa_IR.mo' );
		} finally {
			$archive->close();
			$server->close();
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Native test fixture cleanup only.
			$this->remove_directory( $directory );
		}
	}

	/**
	 * Remove only a randomly allocated test package directory.
	 *
	 * @param string $directory Fixture directory.
	 * @return void
	 */
	private function remove_directory( string $directory ): void {
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST ) as $file ) {
			if ( $file->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test-only package extraction cleanup.
				rmdir( $file->getPathname() );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only package extraction cleanup.
				unlink( $file->getPathname() );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test-only package extraction cleanup.
		rmdir( $directory );
	}
}
