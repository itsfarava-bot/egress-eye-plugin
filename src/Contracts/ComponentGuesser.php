<?php
/**
 * Best-effort source attribution boundary.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EgressEye\Diagnostics\ComponentGuess;

/**
 * Resolve safe relative component keys, never request values.
 */
interface ComponentGuesser {

	/**
	 * Attribute only recognized file metadata.
	 *
	 * @param array<int,array<string,mixed>> $trace Argument-free frames.
	 * @return ComponentGuess
	 */
	public function guess( array $trace ): ComponentGuess;
}
