<?php
/**
 * In-memory request context lifecycle tests.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Unit\Http;

use EgressEye\Http\InMemoryContextStore;
use EgressEye\Http\RequestContext;
use EgressEye\Tests\Support\FakeClock;
use PHPUnit\Framework\TestCase;

/**
 * Verifies token-based context tracking without a database or network.
 */
final class ContextStoreTest extends TestCase {

	/**
	 * A new blocking request receives a unique context token.
	 *
	 * @return void
	 */
	public function test_start_creates_unique_token(): void {
		$store = $this->createStore();
		$token = $store->start( 'https://example.test/one', array( 'blocking' => true ) );

		self::assertIsString( $token );
		self::assertInstanceOf( RequestContext::class, $store->find( $token ) );
		self::assertSame( 1, $store->count() );
	}

	/**
	 * Two requests to the same URL receive different tokens.
	 *
	 * @return void
	 */
	public function test_same_url_gets_distinct_tokens(): void {
		$store  = $this->createStore();
		$url    = 'https://example.test/same';
		$first  = $store->start( $url, array( 'blocking' => true ) );
		$second = $store->start( $url, array( 'blocking' => true ) );

		self::assertNotSame( $first, $second );
		self::assertSame( 2, $store->count() );
	}

	/**
	 * Nested contexts retain their depth and remain independently addressable.
	 *
	 * @return void
	 */
	public function test_nested_contexts_are_tracked(): void {
		$store = $this->createStore();
		$outer = $store->start( 'https://example.test/outer', array( 'blocking' => true ) );
		$inner = $store->start( 'https://example.test/inner', array( 'blocking' => true ) );

		self::assertSame( 1, $store->find( $outer )->getDepth() );
		self::assertSame( 2, $store->find( $inner )->getDepth() );

		$completed_outer = $store->complete( $outer, 1000000000 );

		self::assertSame( $outer, $completed_outer->getToken() );
		self::assertNotNull( $store->find( $inner ) );
		self::assertSame( 1, $store->count() );

		$store->complete( $inner, 1000000000 );
		self::assertSame( 0, $store->count() );
	}

	/**
	 * Completion uses the token and never guesses from the URL.
	 *
	 * @return void
	 */
	public function test_complete_matches_token_not_url(): void {
		$store  = $this->createStore();
		$url    = 'https://example.test/repeated';
		$first  = $store->start( $url, array( 'blocking' => true ) );
		$second = $store->start( $url, array( 'blocking' => true ) );

		$completed = $store->complete( $first, 1000000000 );

		self::assertSame( $first, $completed->getToken() );
		self::assertNull( $store->find( $first ) );
		self::assertNotNull( $store->find( $second ) );
		self::assertSame( 1, $store->count() );
	}

	/**
	 * Discard removes only the requested context.
	 *
	 * @return void
	 */
	public function test_discard_removes_context(): void {
		$store = $this->createStore();
		$token = $store->start( 'https://example.test/discard', array( 'blocking' => true ) );

		$store->discard( $token, 'preempted' );

		self::assertNull( $store->find( $token ) );
		self::assertSame( 0, $store->count() );
	}

	/**
	 * Reset removes abandoned contexts at a lifecycle boundary.
	 *
	 * @return void
	 */
	public function test_reset_removes_abandoned_contexts(): void {
		$store  = $this->createStore();
		$first  = $store->start( 'https://example.test/reset-one', array( 'blocking' => true ) );
		$second = $store->start( 'https://example.test/reset-two', array( 'blocking' => true ) );

		$store->reset();

		self::assertNull( $store->find( $first ) );
		self::assertNull( $store->find( $second ) );
		self::assertSame( 0, $store->count() );
	}

	/**
	 * Capacity returns null for measurement only and does not block the caller.
	 *
	 * @return void
	 */
	public function test_context_capacity_does_not_block_request(): void {
		$store  = $this->createStore( 1 );
		$first  = $store->start( 'https://example.test/capacity-one', array( 'blocking' => true ) );
		$second = $store->start( 'https://example.test/capacity-two', array( 'blocking' => true ) );

		self::assertNotNull( $first );
		self::assertNull( $second );
		self::assertSame( 1, $store->count() );
		self::assertNotNull( $store->find( $first ) );
	}

	/**
	 * Non-blocking calls are outside timing statistics.
	 *
	 * @return void
	 */
	public function test_non_blocking_context_is_not_tracked(): void {
		$store = $this->createStore();

		$token = $store->start( 'https://example.test/non-blocking', array( 'blocking' => false ) );

		self::assertNull( $token );
		self::assertSame( 0, $store->count() );
	}

	/**
	 * Contexts are not evicted merely because the clock advances.
	 *
	 * @return void
	 */
	public function test_old_context_is_not_evicted_by_time(): void {
		$clock = $this->createClock( 0 );
		$store = new InMemoryContextStore( $clock, 2 );
		$first = $store->start( 'https://example.test/old', array( 'blocking' => true ) );

		$clock->set( 999999999999 );
		$second = $store->start( 'https://example.test/new', array( 'blocking' => true ) );

		self::assertNotNull( $first );
		self::assertNotNull( $second );
		self::assertNotNull( $store->find( $first ) );
		self::assertSame( 2, $store->count() );
	}

	/**
	 * Unknown tokens are not resolved by a matching URL or another context.
	 *
	 * @return void
	 */
	public function test_unknown_token_does_not_match_another_context(): void {
		$store = $this->createStore();
		$token = $store->start( 'https://example.test/unknown', array( 'blocking' => true ) );

		self::assertNull( $store->complete( 'not-the-token', 1000000000 ) );
		self::assertNotNull( $store->find( $token ) );
		self::assertSame( 1, $store->count() );
	}

	/**
	 * Build a store with the smallest useful test clock.
	 *
	 * @param int $capacity Maximum number of active contexts.
	 * @return InMemoryContextStore
	 */
	private function createStore( int $capacity = 64 ): InMemoryContextStore {
		return new InMemoryContextStore( $this->createClock(), $capacity );
	}

	/**
	 * Build a mutable test clock only after the production interface exists.
	 *
	 * @param int $initial Initial nanosecond value.
	 * @return FakeClock
	 */
	private function createClock( int $initial = 0 ): FakeClock {
		return new FakeClock( $initial );
	}
}
