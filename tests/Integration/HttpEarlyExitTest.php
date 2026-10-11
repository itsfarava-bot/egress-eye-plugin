<?php
/**
 * Real-clock coverage of WordPress early HTTP exits.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Integration;

use EgressEye\Http\HookRegistrar;
use EgressEye\Http\HttpObserver;
use EgressEye\Http\InMemoryContextStore;
use EgressEye\Http\MonotonicClock;
use EgressEye\Tests\Support\FakeManualBlocker;
use EgressEye\Tests\Support\FakeSlowRequestRecorder;

/**
 * Verifies that fast failures close contexts without slow-request records.
 */
final class HttpEarlyExitTest extends \WP_UnitTestCase {

	/**
	 * Invalid URLs finish through debug without leaving a context.
	 *
	 * @return void
	 */
	public function test_invalid_url_completes_below_threshold(): void {
		$this->assert_fast_failure( 'not-a-valid-url', array(), 'http_request_failed' );
	}

	/**
	 * Core safe-URL validation replaces a private-address URL with false.
	 *
	 * @return void
	 */
	public function test_safe_url_rejection_closes_the_original_context(): void {
		$clock     = new MonotonicClock();
		$store     = new InMemoryContextStore( $clock );
		$recorder  = new FakeSlowRequestRecorder();
		$observer  = new HttpObserver( $clock, $store, new FakeManualBlocker(), $recorder );
		$registrar = new HookRegistrar( $observer );
		$debug_url = null;
		$listener  = static function ( $response, $context, $transport, $args, $url ) use ( &$debug_url ): void {
			$debug_url = $url;
		};
		$registrar->register();
		add_action( 'http_api_debug', $listener, 1, 5 );
		try {
			$response = wp_remote_get( 'http://127.0.0.1/', array( 'reject_unsafe_urls' => true ) );
			self::assertWPError( $response );
			self::assertSame( 'http_request_failed', $response->get_error_code() );
			self::assertFalse( $debug_url );
			self::assertSame( 0, $store->count() );
			self::assertSame( array(), $recorder->records );
		} finally {
			remove_action( 'http_api_debug', $listener, 1 );
			$registrar->unregister();
		}
	}

	/**
	 * WordPress's external-host policy finishes through debug.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_core_external_block_completes_below_threshold(): void {
		if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) {
			define( 'WP_HTTP_BLOCK_EXTERNAL', true );
		}

		self::assertTrue( WP_HTTP_BLOCK_EXTERNAL );
		$this->assert_fast_failure( 'https://example.test/blocked-by-core', array(), 'http_request_not_executed' );
	}

	/**
	 * A nonexistent stream directory fails before transport.
	 *
	 * @return void
	 */
	public function test_stream_file_error_completes_below_threshold(): void {
		$directory = sys_get_temp_dir() . '/egress-eye-missing-' . bin2hex( random_bytes( 16 ) );
		self::assertDirectoryDoesNotExist( $directory );

		$this->assert_fast_failure(
			'http://127.0.0.1/stream',
			array(
				'stream'   => true,
				'filename' => $directory . '/response.txt',
			),
			'http_request_failed'
		);
	}

	/**
	 * Core can force streaming to blocking after our non-blocking filter.
	 *
	 * @return void
	 */
	public function test_stream_forced_blocking_debug_without_token_is_ignored(): void {
		$clock     = new MonotonicClock();
		$store     = new InMemoryContextStore( $clock );
		$recorder  = new FakeSlowRequestRecorder();
		$observer  = new HttpObserver( $clock, $store, new FakeManualBlocker(), $recorder );
		$registrar = new HookRegistrar( $observer );
		$events    = array();
		$directory = sys_get_temp_dir() . '/egress-eye-missing-' . bin2hex( random_bytes( 16 ) );
		$listener  = static function ( $response, $context, $transport_class, $args ) use ( &$events ): void {
			$events[] = $args;
		};

		self::assertDirectoryDoesNotExist( $directory );
		$registrar->register();
		add_action( 'http_api_debug', $listener, PHP_INT_MAX, 5 );

		try {
			$response = wp_remote_request(
				'http://127.0.0.1/stream',
				array(
					'blocking' => false,
					'stream'   => true,
					'filename' => $directory . '/response.txt',
				)
			);
		} finally {
			remove_action( 'http_api_debug', $listener, PHP_INT_MAX );
			$registrar->unregister();
		}

		self::assertWPError( $response );
		self::assertCount( 1, $events );
		self::assertTrue( $events[0]['blocking'] );
		self::assertArrayNotHasKey( HttpObserver::CONTEXT_TOKEN_ARGUMENT, $events[0] );
		self::assertSame( 0, $store->count() );
		self::assertCount( 0, $recorder->records );
	}

	/**
	 * Exercise the real observer, clock and WordPress failure path.
	 *
	 * @param string              $url        Request URL.
	 * @param array<string,mixed> $args       HTTP arguments.
	 * @param string              $error_code Expected core error.
	 * @return void
	 */
	private function assert_fast_failure( string $url, array $args, string $error_code ): void {
		$clock           = new MonotonicClock();
		$store           = new InMemoryContextStore( $clock );
		$recorder        = new FakeSlowRequestRecorder();
		$observer        = new HttpObserver( $clock, $store, new FakeManualBlocker(), $recorder );
		$registrar       = new HookRegistrar( $observer );
		$events          = array();
		$starts          = array();
		$before_debug    = array();
		$start_listener  = static function ( array $request_args ) use ( &$starts, $store ): array {
			$token    = $request_args[ HttpObserver::CONTEXT_TOKEN_ARGUMENT ] ?? null;
			$starts[] = array( $store->count(), is_string( $token ) ? $store->find( $token ) : null );

			return $request_args;
		};
		$before_listener = static function () use ( &$before_debug, $store ): void {
			$before_debug[] = $store->count();
		};
		$listener        = static function ( $response, $context, $transport_class, $request_args ) use ( &$events ): void {
			$events[] = array( $response, $context, $request_args );
		};

		$registrar->register();
		add_filter( 'http_request_args', $start_listener, PHP_INT_MAX, 2 );
		add_action( 'http_api_debug', $before_listener, 1, 5 );
		add_action( 'http_api_debug', $listener, PHP_INT_MAX, 5 );

		try {
			$started  = $clock->nowNanoseconds();
			$response = wp_remote_request( $url, $args );
			$elapsed  = $clock->nowNanoseconds() - $started;
		} finally {
			remove_filter( 'http_request_args', $start_listener, PHP_INT_MAX );
			remove_action( 'http_api_debug', $before_listener, 1 );
			remove_action( 'http_api_debug', $listener, PHP_INT_MAX );
			$registrar->unregister();
		}

		self::assertWPError( $response );
		self::assertSame( $error_code, $response->get_error_code() );
		self::assertLessThan( 1000000000, $elapsed, 'Fixture must complete below the recording threshold.' );
		self::assertCount( 1, $starts );
		self::assertSame( 1, $starts[0][0] );
		self::assertInstanceOf( \EgressEye\Http\RequestContext::class, $starts[0][1] );
		self::assertSame( array( 1 ), $before_debug );
		self::assertLessThan( 1000000000, $starts[0][1]->getElapsedNanoseconds() );
		self::assertCount( 1, $events );
		self::assertSame( $response, $events[0][0] );
		self::assertSame( 'response', $events[0][1] );
		self::assertArrayHasKey( HttpObserver::CONTEXT_TOKEN_ARGUMENT, $events[0][2] );
		self::assertSame( 0, $store->count() );
		self::assertCount( 0, $recorder->records );
	}
}
