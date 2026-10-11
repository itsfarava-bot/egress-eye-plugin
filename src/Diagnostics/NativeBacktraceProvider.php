<?php
/**
 * Native bounded stack capture without payloads.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EgressEye\Contracts\BacktraceProvider;

/**
 * No argument or object metadata leaves the capture boundary.
 */
final class NativeBacktraceProvider implements BacktraceProvider {

	/**
	 * Capture at most thirty argument-free frames.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function capture(): array {
		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 30 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Bounded stack metadata is the diagnostic feature; ignore arguments and discard any PHP-supplied payload keys.
		foreach ( $trace as &$frame ) {
			unset( $frame['args'], $frame['object'] );
		}
		unset( $frame );
		return $trace;
	}
}
