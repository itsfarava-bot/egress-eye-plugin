<?php
/**
 * Isolated loopback HTTP fixture process for integration tests.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Support;

/**
 * Runs a local PHP server without depending on an external service.
 */
final class LocalHttpServer {

	/**
	 * Child process handle.
	 *
	 * @var resource|null
	 */
	private $process;

	/**
	 * Temporary output file.
	 *
	 * @var string|false
	 */
	private $log_path;

	/**
	 * Local fixture URL.
	 *
	 * @var string
	 */
	private $url;

	/**
	 * Counter file shared only with the local fixture process.
	 *
	 * @var string|false
	 */
	private $counter_path;

	/**
	 * Start the loopback fixture and wait for its socket.
	 *
	 * @throws \RuntimeException If the fixture cannot start.
	 */
	public function __construct() {
		$socket = stream_socket_server( 'tcp://127.0.0.1:0', $error_code, $error_message );

		if ( false === $socket ) {
			throw new \RuntimeException( 'Cannot reserve a local fixture port.' );
		}

		$address = stream_socket_get_name( $socket, false );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem cannot close socket resources.
		fclose( $socket );

		if ( false === $address ) {
			throw new \RuntimeException( 'Cannot read the local fixture address.' );
		}

		$this->url      = 'http://' . $address . '/';
		$this->log_path = tempnam( sys_get_temp_dir(), 'egress-eye-http-' );

		if ( false === $this->log_path ) {
			throw new \RuntimeException( 'Cannot create the local fixture log.' );
		}

		$this->counter_path = tempnam( sys_get_temp_dir(), 'egress-eye-count-' );

		if ( false === $this->counter_path ) {
			$this->close();
			throw new \RuntimeException( 'Cannot create the local request counter.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only counter initialization.
		file_put_contents( $this->counter_path, '0' );

		$command     = array( PHP_BINARY, '-S', $address, '-t', dirname( __DIR__ ) . '/Fixtures/http' );
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'file', $this->log_path, 'a' ),
			2 => array( 'file', $this->log_path, 'a' ),
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Test-only loopback fixture on the CI runner.
		$this->process = proc_open( $command, $descriptors, $pipes, null, array( 'EGRESS_EYE_FIXTURE_COUNTER_PATH' => $this->counter_path ) );

		if ( ! is_resource( $this->process ) ) {
			$this->close();
			throw new \RuntimeException( 'Cannot launch the local fixture process.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem cannot close child-process pipes.
		fclose( $pipes[0] );
		$deadline = hrtime( true ) + 3000000000;

		do {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Connection refusal is expected during bounded startup polling.
			$connection = @stream_socket_client( 'tcp://' . $address, $error_code, $error_message, 0.05 );

			if ( is_resource( $connection ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the readiness socket, not a filesystem file.
				fclose( $connection );
				return;
			}

			$status = proc_get_status( $this->process );

			if ( ! $status['running'] ) {
				break;
			}

			usleep( 20000 );
		} while ( hrtime( true ) < $deadline );

		$this->close();
		throw new \RuntimeException( 'The local fixture was not ready within three seconds.' );
	}

	/**
	 * Return the valid loopback URL.
	 *
	 * @return string
	 */
	public function get_url(): string {
		return $this->url;
	}

	/**
	 * Read the number of HTTP requests that reached the fixture.
	 *
	 * @return int
	 * @throws \RuntimeException If the test counter cannot be read.
	 */
	public function get_request_count(): int {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the test-only local request counter.
		$count = file_get_contents( $this->counter_path );

		if ( false === $count ) {
			throw new \RuntimeException( 'Cannot read the local request counter.' );
		}

		return (int) $count;
	}

	/**
	 * Release only this fixture's process and temporary file.
	 *
	 * @return void
	 */
	public function close(): void {
		if ( is_resource( $this->process ) ) {
			proc_terminate( $this->process );
			proc_close( $this->process );
			$this->process = null;
		}

		if ( is_string( $this->log_path ) && is_file( $this->log_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete the temporary test log without requiring WP_Filesystem credentials.
			unlink( $this->log_path );
		}
		if ( is_string( $this->counter_path ) && is_file( $this->counter_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the test-owned local counter.
			unlink( $this->counter_path );
		}
	}
}
