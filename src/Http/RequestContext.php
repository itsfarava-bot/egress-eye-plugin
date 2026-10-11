<?php
/**
 * Value object for one active WordPress HTTP API request.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds lifecycle data without storing request data in persistence.
 */
final class RequestContext {

	/**
	 * Inclusive slow-request threshold in nanoseconds.
	 */
	public const SLOW_THRESHOLD_NANOSECONDS = 1000000000;

	/**
	 * Context token.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Original request URL.
	 *
	 * @var string
	 */
	private $url;

	/**
	 * Monotonic start timestamp.
	 *
	 * @var int
	 */
	private $started_at_nanoseconds;

	/**
	 * Whether the request is blocking.
	 *
	 * @var bool
	 */
	private $blocking;

	/**
	 * Nested request depth.
	 *
	 * @var int
	 */
	private $depth;

	/**
	 * Lifecycle state.
	 *
	 * @var string
	 */
	private $state = 'started';

	/**
	 * Monotonic end timestamp.
	 *
	 * @var int|null
	 */
	private $ended_at_nanoseconds;

	/**
	 * Elapsed duration in nanoseconds.
	 *
	 * @var int|null
	 */
	private $elapsed_nanoseconds;

	/**
	 * Discard lifecycle reason.
	 *
	 * @var string|null
	 */
	private $discard_reason;

	/**
	 * Create an active request context.
	 *
	 * @param string $token                   Unique context token.
	 * @param string $url                     Original request URL.
	 * @param int    $started_at_nanoseconds Monotonic start timestamp.
	 * @param bool   $blocking               Whether the HTTP call is blocking.
	 * @param int    $depth                  Nested request depth.
	 */
	public function __construct(
		string $token,
		string $url,
		int $started_at_nanoseconds,
		bool $blocking,
		int $depth
	) {
		$this->token                  = $token;
		$this->url                    = $url;
		$this->started_at_nanoseconds = $started_at_nanoseconds;
		$this->blocking               = $blocking;
		$this->depth                  = $depth;
		$this->ended_at_nanoseconds   = null;
		$this->elapsed_nanoseconds    = null;
		$this->discard_reason         = null;
	}

	/**
	 * Mark the context completed and calculate integer elapsed nanoseconds.
	 *
	 * @param int $end_nanoseconds Monotonic end timestamp.
	 * @return void
	 * @throws \InvalidArgumentException If the end precedes the start.
	 * @throws \LogicException If the context is no longer active.
	 */
	public function complete( int $end_nanoseconds ): void {
		if ( 'started' !== $this->state ) {
			throw new \LogicException( 'Only an active context can be completed.' );
		}

		if ( $end_nanoseconds < $this->started_at_nanoseconds ) {
			throw new \InvalidArgumentException( 'The monotonic end timestamp precedes the start timestamp.' );
		}

		$this->ended_at_nanoseconds = $end_nanoseconds;
		$this->elapsed_nanoseconds  = $end_nanoseconds - $this->started_at_nanoseconds;
		$this->state                = 'completed';
	}

	/**
	 * Mark the context discarded without producing a timing event.
	 *
	 * @param string $reason Internal lifecycle reason.
	 * @return void
	 * @throws \LogicException If the context is no longer active.
	 */
	public function discard( string $reason ): void {
		if ( 'started' !== $this->state ) {
			throw new \LogicException( 'Only an active context can be discarded.' );
		}

		$this->discard_reason = $reason;
		$this->state          = 'discarded';
	}

	/**
	 * Return the token.
	 *
	 * @return string
	 */
	public function getToken(): string {
		return $this->token;
	}

	/**
	 * Return the original URL kept in request memory.
	 *
	 * @return string
	 */
	public function getUrl(): string {
		return $this->url;
	}

	/**
	 * Return the monotonic start timestamp.
	 *
	 * @return int
	 */
	public function getStartedAtNanoseconds(): int {
		return $this->started_at_nanoseconds;
	}

	/**
	 * Return whether this context belongs to a blocking request.
	 *
	 * @return bool
	 */
	public function isBlocking(): bool {
		return $this->blocking;
	}

	/**
	 * Return nested request depth, starting at one.
	 *
	 * @return int
	 */
	public function getDepth(): int {
		return $this->depth;
	}

	/**
	 * Return lifecycle state.
	 *
	 * @return string
	 */
	public function getState(): string {
		return $this->state;
	}

	/**
	 * Return the end timestamp after completion.
	 *
	 * @return int|null
	 */
	public function getEndedAtNanoseconds(): ?int {
		return $this->ended_at_nanoseconds;
	}

	/**
	 * Return elapsed nanoseconds after completion.
	 *
	 * @return int|null
	 */
	public function getElapsedNanoseconds(): ?int {
		return $this->elapsed_nanoseconds;
	}

	/**
	 * Return the discard reason when the context was preempted or abandoned.
	 *
	 * @return string|null
	 */
	public function getDiscardReason(): ?string {
		return $this->discard_reason;
	}

	/**
	 * Compare completed elapsed time against an inclusive integer threshold.
	 *
	 * @param int $threshold_nanoseconds Threshold in nanoseconds.
	 * @return bool
	 * @throws \InvalidArgumentException If the threshold is negative.
	 */
	public function isSlow( int $threshold_nanoseconds = self::SLOW_THRESHOLD_NANOSECONDS ): bool {
		if ( $threshold_nanoseconds < 0 ) {
			throw new \InvalidArgumentException( 'The nanosecond threshold cannot be negative.' );
		}

		return null !== $this->elapsed_nanoseconds && $this->elapsed_nanoseconds >= $threshold_nanoseconds;
	}
}
