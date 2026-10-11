<?php
/**
 * Sequential clock for deterministic integration lifecycle tests.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Support;

use EgressEye\Contracts\Clock;

/**
 * Returns supplied nanosecond values in order.
 */
final class SequenceClock implements Clock {

	/**
	 * Remaining timestamps.
	 *
	 * @var array<int, int>
	 */
	private $timestamps = array();

	/**
	 * Last returned timestamp.
	 *
	 * @var int
	 */
	private $last = 0;

	/**
	 * Create a sequential clock.
	 *
	 * @param array<int, int> $timestamps Timestamps returned in order.
	 */
	public function __construct( array $timestamps ) {
		$this->replace( $timestamps );
	}

	/**
	 * Return the next configured timestamp.
	 *
	 * @return int
	 */
	public function nowNanoseconds(): int {
		if ( empty( $this->timestamps ) ) {
			return $this->last;
		}

		$this->last = (int) array_shift( $this->timestamps );

		return $this->last;
	}

	/**
	 * Replace the remaining timestamp sequence.
	 *
	 * @param array<int, int> $timestamps Timestamps returned in order.
	 * @return void
	 */
	public function replace( array $timestamps ): void {
		$this->timestamps = array_values( $timestamps );
		$this->last       = 0;
	}
}
