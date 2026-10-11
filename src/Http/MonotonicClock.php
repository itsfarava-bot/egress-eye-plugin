<?php
/**
 * Production monotonic clock implementation.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EgressEye\Contracts\Clock;

/**
 * Reads PHP's monotonic high-resolution timer.
 */
final class MonotonicClock implements Clock {

	/**
	 * Return the current monotonic timestamp as an integer nanosecond value.
	 *
	 * @return int
	 * @throws \RuntimeException If the runtime cannot provide an integer timer.
	 */
	public function nowNanoseconds(): int {
		$now = hrtime( true );

		if ( ! is_int( $now ) ) {
			throw new \RuntimeException( 'The monotonic timer did not return an integer.' );
		}

		return $now;
	}
}
