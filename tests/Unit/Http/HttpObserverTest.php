<?php
/**
 * HTTP observer lifecycle unit tests.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Unit\Http;

use EgressEye\Contracts\SlowRequestRecorder;
use EgressEye\Http\HttpObserver;
use EgressEye\Http\InMemoryContextStore;
use EgressEye\Http\RequestContext;
use EgressEye\Tests\Support\FakeClock;
use EgressEye\Tests\Support\FakeManualBlocker;
use EgressEye\Tests\Support\FakeSlowRequestRecorder;
use PHPUnit\Framework\TestCase;

/**
 * Verifies token-based HTTP lifecycle behavior without WordPress or network I/O.
 */
final class HttpObserverTest extends TestCase {

	/**
	 * Test clock.
	 *
	 * @var FakeClock
	 */
	private $clock;

	/**
	 * Test context store.
	 *
	 * @var InMemoryContextStore
	 */
	private $store;

	/**
	 * Manual block decision double.
	 *
	 * @var FakeManualBlocker
	 */
	private $blocker;

	/**
	 * Slow request recorder double.
	 *
	 * @var FakeSlowRequestRecorder
	 */
	private $recorder;

	/**
	 * Observer under test.
	 *
	 * @var HttpObserver
	 */
	private $observer;

	/**
	 * Initialize observer dependencies.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->clock    = new FakeClock( 123 );
		$this->store    = new InMemoryContextStore( $this->clock );
		$this->blocker  = new FakeManualBlocker();
		$this->recorder = new FakeSlowRequestRecorder();
		$this->observer = new HttpObserver( $this->clock, $this->store, $this->blocker, $this->recorder );
	}

	/**
	 * A blocking request receives a private token and monotonic start time.
	 *
	 * @return void
	 */
	public function test_blocking_request_gets_token_and_start_time(): void {
		$url  = 'https://example.test/slow';
		$args = $this->observer->filterRequestArgs( array( 'blocking' => true ), $url );

		self::assertArrayHasKey( HttpObserver::CONTEXT_TOKEN_ARGUMENT, $args );
		self::assertIsString( $args[ HttpObserver::CONTEXT_TOKEN_ARGUMENT ] );

		$context = $this->store->find( $args[ HttpObserver::CONTEXT_TOKEN_ARGUMENT ] );

		self::assertNotNull( $context );
		self::assertSame( 123, $context->getStartedAtNanoseconds() );
		self::assertTrue( $context->isBlocking() );
	}

	/**
	 * A non-blocking request does not create timing state.
	 *
	 * @return void
	 */
	public function test_non_blocking_request_gets_no_context(): void {
		$args = $this->observer->filterRequestArgs(
			array( 'blocking' => false ),
			'https://example.test/background'
		);

		self::assertArrayNotHasKey( HttpObserver::CONTEXT_TOKEN_ARGUMENT, $args );
		self::assertSame( 0, $this->store->count() );
	}

	/**
	 * An earlier preemptive response is preserved and its context is discarded.
	 *
	 * @return void
	 */
	public function test_previous_preempt_discards_context(): void {
		$url     = 'https://example.test/already-preempted';
		$args    = $this->observer->filterRequestArgs( array( 'blocking' => true ), $url );
		$preempt = array( 'body' => 'another plugin response' );
		$result  = $this->observer->filterPreHttpRequest( $preempt, $args, $url );

		self::assertSame( $preempt, $result );
		self::assertSame( 0, count( $this->blocker->calls ) );
		self::assertSame( 0, $this->store->count() );
	}

	/**
	 * A manual block discards context and returns the exact blocker response.
	 *
	 * @return void
	 */
	public function test_manual_block_discards_context_without_debug(): void {
		$url                   = 'https://example.test/blocked';
		$this->blocker->result = array( 'blocked' => true );
		$args                  = $this->observer->filterRequestArgs( array( 'blocking' => true ), $url );
		$result                = $this->observer->filterPreHttpRequest( false, $args, $url );

		self::assertSame( $this->blocker->result, $result );
		self::assertSame( 1, count( $this->blocker->calls ) );
		self::assertSame( 0, $this->store->count() );

		$this->observer->actionHttpApiDebug( $result, 'response', 'test', $args, $url );

		self::assertSame( 0, count( $this->recorder->records ) );
	}

	/**
	 * A response event completes the exact token context.
	 *
	 * @return void
	 */
	public function test_debug_matches_token_and_computes_duration(): void {
		$url      = 'https://example.test/slow';
		$args     = $this->observer->filterRequestArgs( array( 'blocking' => true ), $url );
		$token    = $args[ HttpObserver::CONTEXT_TOKEN_ARGUMENT ];
		$response = array( 'response' => array( 'code' => 200 ) );
		$this->clock->set( 123 + 1000000001 );

		$this->observer->actionHttpApiDebug( $response, 'response', 'Requests', $args, $url );

		self::assertNull( $this->store->find( $token ) );
		self::assertCount( 1, $this->recorder->records );
		self::assertSame( $token, $this->recorder->records[0]['context']->getToken() );
		self::assertSame( 1000000001, $this->recorder->records[0]['context']->getElapsedNanoseconds() );
		self::assertSame( $response, $this->recorder->records[0]['response'] );
		self::assertSame( 'Requests', $this->recorder->records[0]['class'] );
		self::assertSame( $url, $this->recorder->records[0]['url'] );
	}

	/**
	 * A response below one second is completed but not recorded.
	 *
	 * @return void
	 */
	public function test_fast_response_is_not_sent_to_recorder(): void {
		$url  = 'https://example.test/fast';
		$args = $this->observer->filterRequestArgs( array( 'blocking' => true ), $url );
		$this->clock->set( 123 + 999999999 );

		$this->observer->actionHttpApiDebug( array(), 'response', 'Requests', $args, $url );

		self::assertSame( 0, $this->store->count() );
		self::assertCount( 0, $this->recorder->records );
	}

	/**
	 * A response at or above one second is sent to the recorder.
	 *
	 * @return void
	 */
	public function test_slow_response_is_sent_to_recorder(): void {
		$url  = 'https://example.test/slow';
		$args = $this->observer->filterRequestArgs( array( 'blocking' => true ), $url );
		$this->clock->set( 123 + 1000000000 );

		$this->observer->actionHttpApiDebug( array(), 'response', 'Requests', $args, $url );

		self::assertCount( 1, $this->recorder->records );
		self::assertSame( 1000000000, $this->recorder->records[0]['context']->getElapsedNanoseconds() );
	}

	/**
	 * A non-response debug context does not complete a request.
	 *
	 * @return void
	 */
	public function test_debug_for_non_response_context_is_ignored(): void {
		$url  = 'https://example.test/other-context';
		$args = $this->observer->filterRequestArgs( array( 'blocking' => true ), $url );
		$this->clock->set( 123 + 1000000000 );

		$this->observer->actionHttpApiDebug( array(), 'request', 'Requests', $args, $url );

		self::assertSame( 1, $this->store->count() );
		self::assertCount( 0, $this->recorder->records );
	}

	/**
	 * A missing token is ignored even when the URL is identical.
	 *
	 * @return void
	 */
	public function test_missing_token_is_ignored_without_url_guessing(): void {
		$url  = 'https://example.test/repeated';
		$args = $this->observer->filterRequestArgs( array( 'blocking' => true ), $url );

		$this->observer->actionHttpApiDebug(
			array(),
			'response',
			'Requests',
			array( 'blocking' => true ),
			$url
		);

		self::assertSame( 1, $this->store->count() );
		self::assertCount( 0, $this->recorder->records );
	}
	/**
	 * A recorder Error cannot escape the observer or corrupt its response.
	 *
	 * @return void
	 */
	public function test_recorder_error_is_caught_as_throwable_and_response_is_preserved(): void {
		$log_file = sys_get_temp_dir() . '/egress-eye-private-error-' . bin2hex( random_bytes( 8 ) ) . '.log';
		$old_log  = ini_get( 'error_log' );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- Isolate the native test-only error log and restore it in finally.
		ini_set( 'error_log', $log_file );

		$url      = 'https://example.test/recorder-error';
		$args     = $this->observer->filterRequestArgs( array( 'blocking' => true ), $url );
		$response = array( 'body' => 'original response' );
		$recorder = $this->createMock( SlowRequestRecorder::class );
		$recorder->expects( self::once() )
			->method( 'record' )
			->with(
				self::isInstanceOf( RequestContext::class ),
				self::identicalTo( $response ),
				'Requests',
				$args,
				$url
			)
			->willThrowException( new \Error( 'https://private.example/path?token=private-query private-header private-body /srv/private/file.php C:\\private\\file.php' ) );

		$observer = new HttpObserver( $this->clock, $this->store, $this->blocker, $recorder );
		$this->clock->set( 123 + 1000000000 );
		try {
			$observer->actionHttpApiDebug( $response, 'response', 'Requests', $args, $url );
			self::assertSame( array( 'body' => 'original response' ), $response );
			self::assertSame( 0, $this->store->count() );
			self::assertFileExists( $log_file );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only the isolated native test log.
			$logged = (string) file_get_contents( $log_file );
			self::assertStringContainsString( 'Egress Eye diagnostic failure', $logged );
			foreach ( array( 'private.example', 'private-query', 'private-header', 'private-body', '/srv/private', 'C:\\private' ) as $secret ) {
				self::assertStringNotContainsString( $secret, $logged );
			}
		} finally {
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- Restore the original native test log destination.
			ini_set( 'error_log', (string) $old_log );
			if ( is_file( $log_file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this random test log fixture.
				unlink( $log_file );
			}
		}
	}
}
