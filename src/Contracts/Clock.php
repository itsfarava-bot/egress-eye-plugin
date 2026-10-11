<?php
/**
 * Contract for a monotonic nanosecond clock.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides monotonic timestamps for elapsed-time measurements.
 */
interface Clock {

	/**
	 * Return the current monotonic timestamp in nanoseconds.
	 *
	 * @return int
	 */
	public function nowNanoseconds(): int;
}
