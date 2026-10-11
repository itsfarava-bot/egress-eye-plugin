<?php
/**
 * Deterministic manual block decision double for HTTP observer tests.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Support;

use EgressEye\Contracts\ManualBlocker;

/**
 * Records calls and returns a caller-configured response.
 */
final class FakeManualBlocker implements ManualBlocker {

	/**
	 * Response returned by the fake.
	 *
	 * @var mixed
	 */
	public $result = false;

	/**
	 * Calls received by the fake.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public $calls = array();

	/**
	 * Optional callback executed after a call is recorded.
	 *
	 * @var callable|null
	 */
	public $callback;

	/**
	 * Return the configured manual decision.
	 *
	 * @param mixed                $preempt Existing preemptive response.
	 * @param array<string, mixed> $args    WordPress HTTP arguments.
	 * @param mixed                $url     Raw original request URL.
	 * @return mixed
	 */
	public function filter( $preempt, array $args, $url ) {
		$this->calls[] = array(
			'preempt' => $preempt,
			'args'    => $args,
			'url'     => $url,
		);

		if ( is_callable( $this->callback ) ) {
			call_user_func( $this->callback, $preempt, $args, $url );
		}

		return $this->result;
	}
}
