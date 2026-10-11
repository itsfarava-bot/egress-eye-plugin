<?php
/**
 * Deterministic slow-request recorder double.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Support;

use EgressEye\Contracts\SlowRequestRecorder;
use EgressEye\Http\RequestContext;

/**
 * Keeps recorder calls in memory for assertions.
 */
final class FakeSlowRequestRecorder implements SlowRequestRecorder {

	/**
	 * Recorded events.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public $records = array();

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
	public function record( RequestContext $context, $response, string $transport_class, array $args, string $url ): void {
		$this->records[] = array(
			'context'  => $context,
			'response' => $response,
			'class'    => $transport_class,
			'args'     => $args,
			'url'      => $url,
		);
	}
}
