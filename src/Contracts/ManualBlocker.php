<?php
/**
 * Contract for the manual pre-http-request decision.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supplies the user-controlled blocking result for one HTTP API call.
 */
interface ManualBlocker {

	/**
	 * Return false to allow transport or a WordPress preemptive response.
	 *
	 * @param mixed                $preempt Existing preemptive response.
	 * @param array<string, mixed> $args    WordPress HTTP arguments.
	 * @param mixed                $url     Raw original request URL; non-strings pass through.
	 * @return mixed
	 */
	public function filter( $preempt, array $args, $url );
}
