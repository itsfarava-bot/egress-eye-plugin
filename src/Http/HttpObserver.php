<?php
/**
 * WordPress HTTP API lifecycle observer.
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
use EgressEye\Contracts\ManualBlocker;
use EgressEye\Contracts\SlowRequestRecorder;
use EgressEye\Support\ErrorLogger;

/**
 * Connects the public WordPress HTTP API hooks without initiating requests.
 */
final class HttpObserver {

	/**
	 * Private argument key used only to correlate hook calls.
	 */
	public const CONTEXT_TOKEN_ARGUMENT = '_egress_eye_context_token';

	/**
	 * Monotonic clock.
	 *
	 * @var Clock
	 */
	private $clock;

	/**
	 * In-request context store.
	 *
	 * @var ContextStore
	 */
	private $context_store;

	/**
	 * Manual block decision provider.
	 *
	 * @var ManualBlocker
	 */
	private $manual_blocker;

	/**
	 * Slow-request recorder.
	 *
	 * @var SlowRequestRecorder
	 */
	private $recorder;

	/**
	 * Non-critical diagnostic error logger.
	 *
	 * @var ErrorLogger
	 */
	private $error_logger;

	/**
	 * Create an HTTP lifecycle observer.
	 *
	 * @param Clock               $clock          Monotonic clock.
	 * @param ContextStore        $context_store  In-request context store.
	 * @param ManualBlocker       $manual_blocker  Manual block provider.
	 * @param SlowRequestRecorder $recorder       Slow request recorder.
	 * @param ErrorLogger|null    $error_logger   Diagnostic error logger.
	 */
	public function __construct(
		Clock $clock,
		ContextStore $context_store,
		ManualBlocker $manual_blocker,
		SlowRequestRecorder $recorder,
		?ErrorLogger $error_logger = null
	) {
		$this->clock          = $clock;
		$this->context_store  = $context_store;
		$this->manual_blocker = $manual_blocker;
		$this->recorder       = $recorder;
		$this->error_logger   = null !== $error_logger ? $error_logger : new ErrorLogger();
	}

	/**
	 * Start timing before WordPress evaluates preemptive responses.
	 *
	 * @param array<string, mixed> $args Request arguments.
	 * @param mixed                $url  Raw request URL supplied by WordPress.
	 * @return array<string, mixed>
	 */
	public function filterRequestArgs( array $args, $url ): array {
		if ( ! is_string( $url ) ) {
			return $args;
		}

		$token = $this->context_store->start( $url, $args );

		if ( null === $token ) {
			return $args;
		}

		$args[ self::CONTEXT_TOKEN_ARGUMENT ] = $token;

		return $args;
	}

	/**
	 * Preserve earlier preemption or apply the single manual blocker.
	 *
	 * @param mixed                $preempt Existing preemptive response.
	 * @param array<string, mixed> $args    Request arguments.
	 * @param mixed                $url     Raw request URL supplied by WordPress.
	 * @return mixed
	 */
	public function filterPreHttpRequest( $preempt, array $args, $url ) {
		$token = $this->extractToken( $args );

		if ( ! is_string( $url ) ) {
			$this->discardContext( $token, 'non-string-url' );
			return $preempt;
		}

		if ( false !== $preempt ) {
			$this->discardContext( $token, 'preempted-before-observer' );

			return $preempt;
		}

		$result = $this->manual_blocker->filter( $preempt, $args, $url );

		if ( false !== $result ) {
			$this->discardContext( $token, 'manually-blocked' );
		}

		return $result;
	}

	/**
	 * Complete one response or WP_Error lifecycle event.
	 *
	 * @param mixed                $response HTTP response or WP_Error.
	 * @param string               $context  WordPress debug context.
	 * @param string               $transport_class Transport class name.
	 * @param array<string, mixed> $args     Request arguments.
	 * @param mixed                $url      Raw or core-rejected request URL.
	 * @return void
	 */
	public function actionHttpApiDebug( $response, string $context, string $transport_class, array $args, $url ): void {
		if ( 'response' !== $context ) {
			return;
		}

		$token = $this->extractToken( $args );

		if ( ! is_string( $url ) ) {
			$this->discardContext( $token, 'non-string-url' );
			return;
		}

		if ( null === $token ) {
			return;
		}

		$completed = $this->context_store->complete( $token, $this->clock->nowNanoseconds() );

		if ( null === $completed || ! $completed->isSlow() ) {
			return;
		}

		try {
				$this->recorder->record( $completed, $response, $transport_class, $args, $url );
		} catch ( \Throwable $exception ) {
			$this->error_logger->log( $exception );
		}
	}

	/**
	 * Clear abandoned contexts at a PHP request lifecycle boundary.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->context_store->reset();
	}

	/**
	 * Read only a valid exact token from WordPress arguments.
	 *
	 * @param array<string, mixed> $args Request arguments.
	 * @return string|null
	 */
	private function extractToken( array $args ): ?string {
		if (
			! isset( $args[ self::CONTEXT_TOKEN_ARGUMENT ] )
			|| ! is_string( $args[ self::CONTEXT_TOKEN_ARGUMENT ] )
			|| '' === $args[ self::CONTEXT_TOKEN_ARGUMENT ]
		) {
			return null;
		}

		return $args[ self::CONTEXT_TOKEN_ARGUMENT ];
	}

	/**
	 * Discard one exact token when present.
	 *
	 * @param string|null $token  Exact token.
	 * @param string      $reason Internal lifecycle reason.
	 * @return void
	 */
	private function discardContext( ?string $token, string $reason ): void {
		if ( null !== $token ) {
			$this->context_store->discard( $token, $reason );
		}
	}
}
