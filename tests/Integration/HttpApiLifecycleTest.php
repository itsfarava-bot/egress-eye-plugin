<?php
/**
 * Real WordPress HTTP API lifecycle tests.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Integration;

use EgressEye\Contracts\SlowRequestRecorder;
use EgressEye\Http\HookRegistrar;
use EgressEye\Http\HttpObserver;
use EgressEye\Http\InMemoryContextStore;
use EgressEye\Tests\Support\FakeManualBlocker;
use EgressEye\Tests\Support\FakeSlowRequestRecorder;
use EgressEye\Tests\Support\LocalHttpServer;
use EgressEye\Tests\Support\SequenceClock;

/**
 * Exercises the public WordPress HTTP API hooks with deterministic responses.
 */
final class HttpApiLifecycleTest extends \WP_UnitTestCase {

	/**
	 * Test clock.
	 *
	 * @var SequenceClock
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
	 * Hook registrar under test.
	 *
	 * @var HookRegistrar
	 */
	private $registrar;

	/**
	 * Register the observer after WordPress resets its hooks.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->clock     = new SequenceClock( array( 0, 1000000000, 2000000000, 3000000000 ) );
		$this->store     = new InMemoryContextStore( $this->clock );
		$this->blocker   = new FakeManualBlocker();
		$this->recorder  = new FakeSlowRequestRecorder();
		$this->observer  = new HttpObserver( $this->clock, $this->store, $this->blocker, $this->recorder );
		$this->registrar = new HookRegistrar( $this->observer );
		$this->registrar->register();
	}

	/**
	 * Unregister plugin hooks before WordPress restores its fixture state.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->registrar->unregister();
		parent::tear_down();
	}

	/**
	 * The request-args filter runs before the preempt filter and carries a token.
	 *
	 * @return void
	 */
	public function test_http_request_args_runs_before_pre_http_request(): void {
		$url                   = 'not-a-valid-url';
		$this->blocker->result = array( 'body' => 'manual response' );
		$response              = wp_remote_request( $url, array( 'blocking' => true ) );

		self::assertSame( $this->blocker->result, $response );
		self::assertCount( 1, $this->blocker->calls );
		self::assertArrayHasKey(
			HttpObserver::CONTEXT_TOKEN_ARGUMENT,
			$this->blocker->calls[0]['args']
		);
		self::assertSame( 0, $this->store->count() );
	}

	/**
	 * A preemptive result does not emit a debug action.
	 *
	 * @return void
	 */
	public function test_preempt_does_not_fire_http_api_debug(): void {
		$debug_count           = 0;
		$debug_listener        = static function () use ( &$debug_count ): void {
			++$debug_count;
		};
		$this->blocker->result = array( 'body' => 'blocked before transport' );

		add_action( 'http_api_debug', $debug_listener, PHP_INT_MAX, 5 );

		try {
			$response = wp_remote_request( 'not-a-valid-url', array( 'blocking' => true ) );
		} finally {
			remove_action( 'http_api_debug', $debug_listener, PHP_INT_MAX );
		}

		self::assertSame( $this->blocker->result, $response );
		self::assertSame( 0, $debug_count );
		self::assertSame( 0, $this->store->count() );
	}

	/**
	 * A WordPress error response still completes timing through http_api_debug.
	 *
	 * @return void
	 */
	public function test_wp_error_response_still_finishes_timing(): void {
		$response = wp_remote_request( 'not-a-valid-url', array( 'blocking' => true ) );

		self::assertTrue( is_wp_error( $response ) );
		self::assertSame( 0, $this->store->count() );
	}

	/**
	 * Nested requests to the same URL keep distinct tokens.
	 *
	 * @return void
	 */
	public function test_same_url_nested_request_does_not_cross_match(): void {
		$server = new LocalHttpServer();
		$url    = $server->get_url();
		$this->clock->replace( array( 0, 0, 1000000000, 2000000000 ) );
		$nested = false;

		$this->blocker->callback = static function () use ( &$nested, $url ): void {
			if ( $nested ) {
				return;
			}

			$nested          = true;
			$nested_response = wp_remote_request( $url, array( 'blocking' => true ) );

			self::assertSame( 200, wp_remote_retrieve_response_code( $nested_response ) );
			self::assertSame( 'egress-eye fixture', wp_remote_retrieve_body( $nested_response ) );
		};

		try {
			$outer_response = wp_remote_request( $url, array( 'blocking' => true ) );
		} finally {
			$server->close();
		}

		self::assertSame( 200, wp_remote_retrieve_response_code( $outer_response ) );
		self::assertCount( 2, $this->recorder->records );
		self::assertNotSame(
			$this->recorder->records[0]['context']->getToken(),
			$this->recorder->records[1]['context']->getToken()
		);
		self::assertSame(
			$this->blocker->calls[1]['args'][ HttpObserver::CONTEXT_TOKEN_ARGUMENT ],
			$this->recorder->records[0]['context']->getToken()
		);
		self::assertSame(
			$this->blocker->calls[0]['args'][ HttpObserver::CONTEXT_TOKEN_ARGUMENT ],
			$this->recorder->records[1]['context']->getToken()
		);
		self::assertSame( 1000000000, $this->recorder->records[0]['context']->getElapsedNanoseconds() );
		self::assertSame( 2000000000, $this->recorder->records[1]['context']->getElapsedNanoseconds() );
		self::assertSame( $url, $this->recorder->records[0]['url'] );
		self::assertSame( $url, $this->recorder->records[1]['url'] );
		self::assertSame( 0, $this->store->count() );
	}

	/**
	 * Shutdown cleanup removes an abandoned context.
	 *
	 * @return void
	 */
	public function test_shutdown_clears_abandoned_contexts(): void {
		$args = apply_filters(
			'http_request_args',
			array( 'blocking' => true ),
			'https://example.test/abandoned'
		);

		self::assertArrayHasKey( HttpObserver::CONTEXT_TOKEN_ARGUMENT, $args );
		self::assertSame( 1, $this->store->count() );

		self::assertSame( PHP_INT_MAX, has_action( 'shutdown', array( $this->observer, 'reset' ) ) );
		$this->observer->reset();

		self::assertSame( 0, $this->store->count() );
	}

	/**
	 * An earlier plugin response is preserved without calling our blocker.
	 *
	 * @return void
	 */
	public function test_earlier_plugin_preempt_preserves_response_and_discards_context(): void {
		$preempt = new \WP_Error( 'fixture_preempt', 'Fixture response.' );
		$earlier = static function () use ( $preempt ) {
			return $preempt;
		};

		add_filter( 'pre_http_request', $earlier, 10, 3 );

		try {
			$response = wp_remote_request( 'https://example.test/preempted' );
		} finally {
			remove_filter( 'pre_http_request', $earlier, 10 );
		}

		self::assertSame( $preempt, $response );
		self::assertCount( 0, $this->blocker->calls );
		self::assertSame( 0, $this->store->count() );
		self::assertCount( 0, $this->recorder->records );
	}
	/**
	 * Late same-priority preemption leaves bounded orphans until reset.
	 *
	 * @return void
	 */
	public function test_late_preempt_orphans_fill_capacity_without_evicting_old_contexts(): void {
		$preempt     = array( 'body' => 'late fixture response' );
		$late        = static function () use ( $preempt ) {
			return $preempt;
		};
		$debug_count = 0;
		$listener    = static function () use ( &$debug_count ): void {
			++$debug_count;
		};

		add_filter( 'pre_http_request', $late, PHP_INT_MAX, 3 );
		add_action( 'http_api_debug', $listener, PHP_INT_MAX, 5 );

		try {
			for ( $index = 0; $index < 64; ++$index ) {
				self::assertSame( $preempt, wp_remote_request( 'https://example.test/orphan' ) );
			}

			$first_token = $this->blocker->calls[0]['args'][ HttpObserver::CONTEXT_TOKEN_ARGUMENT ];
			$this->clock->replace( array( 999999999999 ) );
			self::assertSame( $preempt, wp_remote_request( 'https://example.test/capacity-full' ) );
		} finally {
			remove_filter( 'pre_http_request', $late, PHP_INT_MAX );
			remove_action( 'http_api_debug', $listener, PHP_INT_MAX );
		}

		self::assertSame( 0, $debug_count );
		self::assertSame( 64, $this->store->count() );
		self::assertNotNull( $this->store->find( $first_token ) );
		self::assertCount( 65, $this->blocker->calls );
		self::assertArrayNotHasKey( HttpObserver::CONTEXT_TOKEN_ARGUMENT, $this->blocker->calls[64]['args'] );
		self::assertCount( 0, $this->recorder->records );

		$this->observer->reset();
		self::assertSame( 0, $this->store->count() );
		$args = apply_filters( 'http_request_args', array( 'blocking' => true ), 'https://example.test/after-reset' );
		self::assertArrayHasKey( HttpObserver::CONTEXT_TOKEN_ARGUMENT, $args );
		self::assertSame( 1, $this->store->count() );
		$this->observer->reset();
	}

	/**
	 * Registration is idempotent and passes complete hook argument lists.
	 *
	 * @return void
	 */
	public function test_hook_registration_is_unique_with_final_priority_and_accepted_args(): void {
		global $wp_filter;

		$this->registrar->register();
		$expected = array(
			'http_request_args' => array( 'filterRequestArgs', 2 ),
			'pre_http_request'  => array( 'filterPreHttpRequest', 3 ),
			'http_api_debug'    => array( 'actionHttpApiDebug', 5 ),
		);

		foreach ( $expected as $hook => $contract ) {
			$found = array();

			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) && $this->observer === $callback['function'][0] ) {
						$found[] = array( $priority, $callback['function'][1], $callback['accepted_args'] );
					}
				}
			}

			self::assertSame( array( array( PHP_INT_MAX, $contract[0], $contract[1] ) ), $found, $hook );
		}
	}

	/**
	 * A recorder Error cannot break a successful real HTTP response.
	 *
	 * @return void
	 */
	public function test_recorder_error_preserves_real_http_response(): void {
		$this->registrar->unregister();
		$recorder = $this->createMock( SlowRequestRecorder::class );
		$recorder->expects( self::once() )->method( 'record' )->willThrowException( new \Error( 'Fixture recorder Error.' ) );
		$observer  = new HttpObserver( $this->clock, $this->store, $this->blocker, $recorder );
		$registrar = new HookRegistrar( $observer );
		$server    = new LocalHttpServer();

		$registrar->register();

		try {
			$response = wp_remote_request( $server->get_url() );
		} finally {
			$registrar->unregister();
			$server->close();
		}

		self::assertSame( 200, wp_remote_retrieve_response_code( $response ) );
		self::assertSame( 'egress-eye fixture', wp_remote_retrieve_body( $response ) );
		self::assertSame( 0, $this->store->count() );
	}
}
