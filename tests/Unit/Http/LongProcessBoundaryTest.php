<?php
/**
 * Explicit operation boundaries for a long lived PHP worker.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Unit\Http;

use EgressEye\Http\HttpObserver;
use EgressEye\Http\InMemoryContextStore;
use EgressEye\Tests\Support\FakeClock;
use EgressEye\Tests\Support\FakeManualBlocker;
use EgressEye\Tests\Support\FakeSlowRequestRecorder;
use PHPUnit\Framework\TestCase;

/**
 * Capacity limits measurement, while manual blocking remains authoritative.
 */
final class LongProcessBoundaryTest extends TestCase {
	/**
	 * Sixty four abandoned contexts persist until an explicit safe boundary.
	 *
	 * @return void
	 */
	public function test_default_capacity_preserves_active_contexts_and_reset_restores_measurement(): void {
		$clock    = new FakeClock();
		$store    = new InMemoryContextStore( $clock );
		$blocker  = new FakeManualBlocker();
		$recorder = new FakeSlowRequestRecorder();
		$observer = new HttpObserver( $clock, $store, $blocker, $recorder );
		$url      = 'https://worker.example/';
		$first    = null;
		for ( $index = 0; $index < 64; ++$index ) {
			$args = $observer->filterRequestArgs( array( 'blocking' => true ), $url );
			self::assertArrayHasKey( HttpObserver::CONTEXT_TOKEN_ARGUMENT, $args );
			if ( 0 === $index ) {
				$first = $args[ HttpObserver::CONTEXT_TOKEN_ARGUMENT ];
			}
		}
		$clock->set( 999999999999 );
		$overflow = $observer->filterRequestArgs( array( 'blocking' => true ), $url );
		self::assertArrayNotHasKey( HttpObserver::CONTEXT_TOKEN_ARGUMENT, $overflow );
		self::assertSame( 64, $store->count() );
		self::assertNotNull( $store->find( $first ) );
		self::assertFalse( $observer->filterPreHttpRequest( false, $overflow, $url ) );
		$blocker->result = array( 'blocked' => true );
		self::assertSame( $blocker->result, $observer->filterPreHttpRequest( false, $overflow, $url ) );
		self::assertCount( 2, $blocker->calls );
		$observer->reset();
		self::assertSame( 0, $store->count() );
		self::assertNull( $store->find( $first ) );
		$blocker->result = false;
		for ( $index = 0; $index < 1000; ++$index ) {
			$args = $observer->filterRequestArgs( array( 'blocking' => true ), $url );
			self::assertArrayHasKey( HttpObserver::CONTEXT_TOKEN_ARGUMENT, $args );
			$observer->actionHttpApiDebug( array(), 'response', 'fixture', $args, $url );
			self::assertSame( 0, $store->count() );
		}
		self::assertSame( array(), $recorder->records );
	}
}
