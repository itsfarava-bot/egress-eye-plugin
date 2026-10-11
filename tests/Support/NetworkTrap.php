<?php
/**
 * Isolated HTTP API and URL stream tripwires for plugin lifecycle tests.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Support;

/**
 * Replaces only URL transports in a scoped test; never changes CI networking.
 */
final class NetworkTrap {
	/**
	 * Stream wrapper context supplied by PHP.
	 *
	 * @var resource|null
	 */
	public $context;
	/**
	 * Number of URL stream attempts.
	 *
	 * @var int
	 */
	public static $streams = 0;
	/**
	 * Refused URL stream arguments for negative control assertions.
	 *
	 * @var array<array<string,int|string>>
	 */
	public static $attempts = array();
	/**
	 * Number of WordPress HTTP attempts.
	 *
	 * @var int
	 */
	public $http = 0;
	/**
	 * Registered wrappers to restore.
	 *
	 * @var array<string>
	 */
	private $wrappers = array();

	/**
	 * Install a tripwire before any plugin HTTP callback.
	 *
	 * @return void
	 */
	public function install(): void {
		self::$streams  = 0;
		self::$attempts = array();
		add_filter( 'pre_http_request', array( $this, 'intercept' ), -1000, 0 );
		foreach ( array( 'http', 'https', 'ftp', 'ftps' ) as $protocol ) {
			if ( in_array( $protocol, stream_get_wrappers(), true ) ) {
				stream_wrapper_unregister( $protocol );
				stream_wrapper_register( $protocol, self::class );
				$this->wrappers[] = $protocol;
			}
		}
	}

	/**
	 * Count and prevent an HTTP API attempt.
	 *
	 * @return \WP_Error
	 */
	public function intercept(): \WP_Error {
		++$this->http;
		return new \WP_Error( 'egress_eye_network_tripwire', 'Lifecycle attempted HTTP.' );
	}

	/**
	 * PHP URL wrapper entry; count and refuse without opening a socket.
	 *
	 * @param string $path        Attempted URL.
	 * @param string $mode        Open mode.
	 * @param int    $options     Wrapper flags.
	 * @param mixed  $opened_path Resolved path output.
	 * @return bool
	 */
	public function stream_open( string $path, string $mode, int $options, &$opened_path ): bool {
		++self::$streams;
		self::$attempts[] = array(
			'path'    => $path,
			'mode'    => $mode,
			'options' => $options,
		);

		$opened_path = $path;
		return false;
	}

	/**
	 * Restore the exact transports and remove the tripwire.
	 *
	 * @return void
	 */
	public function restore(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), -1000 );
		foreach ( $this->wrappers as $protocol ) {
			stream_wrapper_unregister( $protocol );
			stream_wrapper_restore( $protocol );
		}
		$this->wrappers = array();
	}
}
