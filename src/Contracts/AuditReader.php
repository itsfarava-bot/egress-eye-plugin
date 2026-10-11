<?php
/**
 * Bounded admin audit reading port.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads host-only action history for rendering.
 */
interface AuditReader {
	/**
	 * Read recent action snapshots.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function recent(): array;
}
