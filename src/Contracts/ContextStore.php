<?php
/**
 * Contract for in-request HTTP lifecycle context storage.
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
 * Stores active contexts by token without using persistence or network I/O.
 */
interface ContextStore {

	/**
	 * Start a blocking context when capacity allows it.
	 *
	 * @param string               $url  Original request URL.
	 * @param array<string, mixed> $args WordPress HTTP arguments.
	 * @return string|null
	 */
	public function start( string $url, array $args ): ?string;

	/**
	 * Find an active context by its exact token.
	 *
	 * @param string $token Context token.
	 * @return RequestContext|null
	 */
	public function find( string $token ): ?RequestContext;

	/**
	 * Discard an active context without matching by URL or age.
	 *
	 * @param string $token  Context token.
	 * @param string $reason Internal lifecycle reason.
	 * @return void
	 */
	public function discard( string $token, string $reason ): void;

	/**
	 * Complete an active context by exact token.
	 *
	 * @param string $token           Context token.
	 * @param int    $end_nanoseconds Monotonic end timestamp.
	 * @return RequestContext|null
	 */
	public function complete( string $token, int $end_nanoseconds ): ?RequestContext;

	/**
	 * Remove all active contexts at a request lifecycle boundary.
	 *
	 * @return void
	 */
	public function reset(): void;

	/**
	 * Return the number of active contexts.
	 *
	 * @return int
	 */
	public function count(): int;
}
