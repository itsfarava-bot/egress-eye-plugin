<?php
/**
 * Slow event persistence port.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EgressEye\Diagnostics\SlowRequest;
use EgressEye\Diagnostics\ComponentGuess;

/**
 * Writes only sanitized slow data and a bounded probable component.
 */
interface DiagnosticEvents {
	/**
	 * Insert a sample after its aggregate.
	 *
	 * @param SlowRequest $sample    Sanitized sample.
	 * @param int         $domain_id Domain identity.
	 * @return int|null
	 */
	public function insert( SlowRequest $sample, int $domain_id ): ?int;

	/**
	 * Attribute the exact persisted sample.
	 *
	 * @param int            $event_id Event identity.
	 * @param ComponentGuess $guess    Bounded guess.
	 * @return bool
	 */
	public function attribute( int $event_id, ComponentGuess $guess ): bool;
}
