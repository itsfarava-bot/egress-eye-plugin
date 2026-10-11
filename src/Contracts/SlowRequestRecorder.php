<?php
/**
 * Contract for recording a completed slow HTTP API request.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EgressEye\Http\RequestContext;

/**
 * Receives only requests that have crossed the inclusive slow threshold.
 */
interface SlowRequestRecorder {

	/**
	 * Record one completed slow request.
	 *
	 * @param RequestContext      $context Completed request context.
	 * @param mixed               $response HTTP response or WP_Error.
	 * @param string              $transport_class Transport class name.
	 * @param array<string,mixed> $args     WordPress HTTP arguments.
	 * @param string              $url      Request URL.
	 * @return void
	 */
	public function record( RequestContext $context, $response, string $transport_class, array $args, string $url ): void;
}
