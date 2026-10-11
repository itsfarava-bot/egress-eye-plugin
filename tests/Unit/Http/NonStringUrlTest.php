<?php
/**
 * Malformed URL hook boundaries must remain inert.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Unit\Http;

use EgressEye\Http\BlockedHostGuard;
use EgressEye\Http\HostNormalizer;
use EgressEye\Http\HttpObserver;
use EgressEye\Http\InMemoryContextStore;
use EgressEye\Tests\Support\FakeClock;
use EgressEye\Tests\Support\FakeSlowRequestRecorder;
use EgressEye\Tests\Support\InMemoryDomainRepository;
use PHPUnit\Framework\TestCase;

/**
 * Restoring string hints or allocating malformed contexts breaks these tests.
 */
final class NonStringUrlTest extends TestCase {

	/**
	 * Each hook accepts raw input without changing it or reading persistence.
	 *
	 * @dataProvider urls
	 * @param mixed $url Raw caller value.
	 * @return void
	 */
	public function test_raw_url_is_ignored_at_every_boundary( $url ): void {
		$clock      = new FakeClock( 1 );
		$store      = new InMemoryContextStore( $clock );
		$repository = new InMemoryDomainRepository();
		$guard      = new BlockedHostGuard( new HostNormalizer(), $repository );
		$recorder   = new FakeSlowRequestRecorder();
		$observer   = new HttpObserver( $clock, $store, $guard, $recorder );
		$args       = array( 'blocking' => true );
		$preempt    = new \stdClass();

		self::assertSame( $args, $observer->filterRequestArgs( $args, $url ) );
		self::assertSame( 0, $store->count() );
		self::assertFalse( $observer->filterPreHttpRequest( false, $args, $url ) );
		self::assertSame( $preempt, $observer->filterPreHttpRequest( $preempt, $args, $url ) );
		self::assertFalse( $guard->filter( false, $args, $url ) );
		self::assertSame( $preempt, $guard->filter( $preempt, $args, $url ) );
		$observer->actionHttpApiDebug( $preempt, 'response', 'fixture', $args, $url );
		self::assertSame( 0, $store->count() );
		self::assertSame( 0, $repository->read_calls );
		self::assertSame( array(), $recorder->records );
	}

	/**
	 * Core URL validation can replace a previously valid string with false.
	 *
	 * @return void
	 */
	public function test_debug_rejection_closes_an_existing_string_context(): void {
		$clock      = new FakeClock( 1 );
		$store      = new InMemoryContextStore( $clock );
		$repository = new InMemoryDomainRepository();
		$guard      = new BlockedHostGuard( new HostNormalizer(), $repository );
		$recorder   = new FakeSlowRequestRecorder();
		$observer   = new HttpObserver( $clock, $store, $guard, $recorder );
		$args       = $observer->filterRequestArgs( array( 'blocking' => true ), 'http://127.0.0.1/' );
		self::assertSame( 1, $store->count() );
		$observer->actionHttpApiDebug( new \stdClass(), 'response', 'fixture', $args, false );
		self::assertSame( 0, $store->count() );
		self::assertSame( array(), $recorder->records );
	}

	/**
	 * Inputs that must not be coerced into strings by plugin callbacks.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function urls(): array {
		return array(
			'null'   => array( null ),
			'array'  => array( array( 'not-a-url' ) ),
			'false'  => array( false ),
			'object' => array( new \stdClass() ),
		);
	}
}
