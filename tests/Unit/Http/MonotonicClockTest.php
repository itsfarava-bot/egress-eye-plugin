<?php
/**
 * Clock and nanosecond threshold tests.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Unit\Http;

use EgressEye\Http\MonotonicClock;
use EgressEye\Http\RequestContext;
use EgressEye\Tests\Support\FakeClock;
use PHPUnit\Framework\TestCase;

/**
 * Verifies monotonic time access and integer threshold comparisons.
 */
final class MonotonicClockTest extends TestCase {

	/**
	 * The production clock returns an integer from the monotonic timer.
	 *
	 * @return void
	 */
	public function test_monotonic_clock_returns_integer(): void {
		$clock  = new MonotonicClock();
		$first  = $clock->nowNanoseconds();
		$second = $clock->nowNanoseconds();

		self::assertSame( 'integer', gettype( $first ) );
		self::assertSame( 'integer', gettype( $second ) );
		self::assertGreaterThanOrEqual( $first, $second );
	}

	/**
	 * The exact one-second boundary is inclusive and uses nanoseconds.
	 *
	 * @return void
	 */
	public function test_fake_clock_can_cross_exact_threshold(): void {
		$below_clock = new FakeClock();
		$below       = new RequestContext( 'below', 'https://example.test/below', $below_clock->nowNanoseconds(), true, 1 );
		$below_clock->set( 999999999 );
		$below->complete( $below_clock->nowNanoseconds() );

		$exact_clock = new FakeClock();
		$exact       = new RequestContext( 'exact', 'https://example.test/exact', $exact_clock->nowNanoseconds(), true, 1 );
		$exact_clock->set( 1000000000 );
		$exact->complete( $exact_clock->nowNanoseconds() );

		$above_clock = new FakeClock();
		$above       = new RequestContext( 'above', 'https://example.test/above', $above_clock->nowNanoseconds(), true, 1 );
		$above_clock->set( 1000000001 );
		$above->complete( $above_clock->nowNanoseconds() );

		self::assertFalse( $below->isSlow() );
		self::assertTrue( $exact->isSlow() );
		self::assertTrue( $above->isSlow() );
	}

	/**
	 * Elapsed time stays an integer and is not rounded through floating point.
	 *
	 * @return void
	 */
	public function test_elapsed_comparison_uses_integer_nanoseconds(): void {
		$context = new RequestContext(
			'integer-threshold',
			'https://example.test/integer-threshold',
			10000000000,
			true,
			1
		);
		$context->complete( 11000000001 );

		self::assertIsInt( $context->getElapsedNanoseconds() );
		self::assertSame( 1000000001, $context->getElapsedNanoseconds() );
		self::assertTrue( $context->isSlow( 1000000000 ) );
	}
}
