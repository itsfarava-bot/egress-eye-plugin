<?php
/**
 * Internal error logger for non-critical diagnostic failures.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps recorder failures from changing the WordPress HTTP response.
 */
final class ErrorLogger {

	/**
	 * Log a fixed failure marker without sensitive exception values or paths.
	 *
	 * @param \Throwable $exception Internal diagnostic exception.
	 * @return void
	 */
	public function log( \Throwable $exception ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Preserve the internal boundary signature without inspecting potentially sensitive Throwable data.
		if ( function_exists( 'error_log' ) ) {
			/* phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Internal diagnostics must be visible without altering the HTTP response. */
			error_log( 'Egress Eye diagnostic failure.' );
		}
	}
}
