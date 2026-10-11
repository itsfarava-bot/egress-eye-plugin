<?php
/**
 * Bounded in-memory request context store.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EgressEye\Contracts\Clock;
use EgressEye\Contracts\ContextStore;

/**
 * Tracks active contexts by exact token and a separate nesting stack.
 */
final class InMemoryContextStore implements ContextStore {

	/**
	 * Monotonic clock.
	 *
	 * @var Clock
	 */
	private $clock;

	/**
	 * Maximum active context count.
	 *
	 * @var int
	 */
	private $capacity;

	/**
	 * Active contexts indexed by exact token.
	 *
	 * @var array<string, RequestContext>
	 */
	private $contexts = array();

	/**
	 * Active tokens in nesting order.
	 *
	 * @var array<int, string>
	 */
	private $stack = array();

	/**
	 * Monotonic token sequence.
	 *
	 * @var int
	 */
	private $sequence = 0;

	/**
	 * Create a bounded context store.
	 *
	 * @param Clock $clock    Monotonic clock.
	 * @param int   $capacity Maximum number of active contexts.
	 * @throws \InvalidArgumentException If capacity is not positive.
	 */
	public function __construct( Clock $clock, int $capacity = 64 ) {
		if ( $capacity < 1 ) {
			throw new \InvalidArgumentException( 'Context capacity must be positive.' );
		}

		$this->clock    = $clock;
		$this->capacity = $capacity;
	}

	/**
	 * Start a blocking context unless measurement capacity is full.
	 *
	 * @param string               $url  Original request URL.
	 * @param array<string, mixed> $args WordPress HTTP arguments.
	 * @return string|null
	 */
	public function start( string $url, array $args ): ?string {
		if ( isset( $args['blocking'] ) && false === $args['blocking'] ) {
			return null;
		}

		if ( count( $this->contexts ) >= $this->capacity ) {
			return null;
		}

		$token   = $this->createToken();
		$context = new RequestContext(
			$token,
			$url,
			$this->clock->nowNanoseconds(),
			true,
			count( $this->stack ) + 1
		);

		$this->contexts[ $token ] = $context;
		$this->stack[]            = $token;

		return $token;
	}

	/**
	 * Find an active context by exact token.
	 *
	 * @param string $token Context token.
	 * @return RequestContext|null
	 */
	public function find( string $token ): ?RequestContext {
		return isset( $this->contexts[ $token ] ) ? $this->contexts[ $token ] : null;
	}

	/**
	 * Discard one active context and remove its stack token.
	 *
	 * @param string $token  Context token.
	 * @param string $reason Internal lifecycle reason.
	 * @return void
	 */
	public function discard( string $token, string $reason ): void {
		if ( ! isset( $this->contexts[ $token ] ) ) {
			return;
		}

		$this->contexts[ $token ]->discard( $reason );
		unset( $this->contexts[ $token ] );
		$this->removeFromStack( $token );
	}

	/**
	 * Complete one active context by exact token.
	 *
	 * @param string $token          Context token.
	 * @param int    $end_nanoseconds Monotonic end timestamp.
	 * @return RequestContext|null
	 */
	public function complete( string $token, int $end_nanoseconds ): ?RequestContext {
		if ( ! isset( $this->contexts[ $token ] ) ) {
			return null;
		}

		$context = $this->contexts[ $token ];
		$context->complete( $end_nanoseconds );
		unset( $this->contexts[ $token ] );
		$this->removeFromStack( $token );

		return $context;
	}

	/**
	 * Discard all active contexts without age-based eviction.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->contexts = array();
		$this->stack    = array();
	}

	/**
	 * Return active context count.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->contexts );
	}

	/**
	 * Create a unique private token without URL or time-based matching.
	 *
	 * @return string
	 */
	private function createToken(): string {
		++$this->sequence;

		try {
			$entropy = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $exception ) {
			$entropy = hash( 'sha256', (string) $this->sequence . ':' . spl_object_hash( $this ) );
		}

		return 'egress_eye_' . $this->sequence . '_' . $entropy;
	}

	/**
	 * Remove an exact token from the nesting stack.
	 *
	 * @param string $token Context token.
	 * @return void
	 */
	private function removeFromStack( string $token ): void {
		$position = array_search( $token, $this->stack, true );

		if ( false === $position ) {
			return;
		}

		unset( $this->stack[ $position ] );
		$this->stack = array_values( $this->stack );
	}
}
