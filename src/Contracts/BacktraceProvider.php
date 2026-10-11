<?php
/**
 * Argument-free bounded stack capture boundary.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capture is explicitly deferred until a slow sample is admitted.
 */
interface BacktraceProvider {

	/**
	 * Capture file metadata without function arguments.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function capture(): array;
}
