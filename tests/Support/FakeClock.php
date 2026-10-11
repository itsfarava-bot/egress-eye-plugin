<?php
/**
 * Mutable clock for deterministic unit tests.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Support;

use EgressEye\Contracts\Clock;

/**
 * Supplies explicit integer nanosecond values without sleeping.
 */
final class FakeClock implements Clock {

	/**
	 * Current test timestamp.
	 *
	 * @var int
	 */
	private $now;

	/**
	 * Create a fake clock.
	 *
	 * @param int $initial Initial nanosecond value.
	 */
	public function __construct( int $initial = 0 ) {
		$this->now = $initial;
	}

	/**
	 * Return the configured timestamp.
	 *
	 * @return int
	 */
	public function nowNanoseconds(): int {
		return $this->now;
	}

	/**
	 * Set the configured timestamp.
	 *
	 * @param int $now New nanosecond value.
	 * @return void
	 */
	public function set( int $now ): void {
		$this->now = $now;
	}
}
