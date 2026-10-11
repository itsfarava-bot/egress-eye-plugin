<?php
/**
 * Privacy boundary between the HTTP observer and bounded persistence.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EgressEye\Contracts\SlowRequestRecorder;
use EgressEye\Contracts\ComponentGuesser;
use EgressEye\Contracts\BacktraceProvider;
use EgressEye\Http\HostNormalizer;
use EgressEye\Http\RequestContext;
use EgressEye\Persistence\DomainRepository;
use EgressEye\Contracts\DiagnosticDomains;
use EgressEye\Contracts\DiagnosticEvents;
use EgressEye\Http\LoopbackDetector;
use EgressEye\Support\Notifications;

/**
 * Receives the established observer contract but never persists its raw inputs.
 */
final class SlowRequestRecorderService implements SlowRequestRecorder {

	/**
	 * Host and aggregate storage.
	 *
	 * @var DiagnosticDomains
	 */
	private $domains;
	/**
	 * Sanitized slow-event storage.
	 *
	 * @var DiagnosticEvents
	 */
	private $events;

	/**
	 * Safe probable-component mapper.
	 *
	 * @var ComponentGuesser
	 */
	private $guesser;
	/**
	 * Deferred stack metadata capture.
	 *
	 * @var BacktraceProvider
	 */
	private $backtrace;
	/**
	 * Real site marker when supplied by production composition.
	 *
	 * @var LoopbackDetector|null
	 */
	private $loopback;

	/**
	 * Construct without writes, hooks or external requests.
	 *
	 * @param DiagnosticDomains      $domains   Domain storage.
	 * @param DiagnosticEvents       $events    Event storage.
	 * @param ComponentGuesser|null  $guesser   Probable-component mapper.
	 * @param BacktraceProvider|null $backtrace Stack capture boundary.
	 * @param LoopbackDetector|null  $loopback  Site marker.
	 */
	public function __construct( DiagnosticDomains $domains, DiagnosticEvents $events, ?ComponentGuesser $guesser = null, ?BacktraceProvider $backtrace = null, ?LoopbackDetector $loopback = null ) {
		$this->domains   = $domains;
		$this->events    = $events;
		$this->guesser   = $guesser ?? new StackComponentGuesser();
		$this->backtrace = $backtrace ?? new NativeBacktraceProvider();
		$this->loopback  = $loopback;
	}

	/**
	 * Adapt a completed slow observer result to host-only storage.
	 *
	 * @param RequestContext      $context         Completed request context.
	 * @param mixed               $response        HTTP response or WP_Error.
	 * @param string              $transport_class Transport name; not persisted.
	 * @param array<string,mixed> $args            HTTP args; not persisted.
	 * @param string              $url             Original URL; only its host is retained.
	 * @return void
	 */
	public function record( RequestContext $context, $response, string $transport_class, array $args, string $url ): void {
		if ( ! $context->isBlocking() || ! $context->isSlow() ) {
			return;
		}
		$elapsed = $context->getElapsedNanoseconds();
		$host    = ( new HostNormalizer() )->fromUrl( $url );
		if ( null === $host || null === $elapsed ) {
			return;
		}
		$code  = null;
		$error = null;
		if ( $response instanceof \WP_Error ) {
			$error = (string) $response->get_error_code();
		} elseif ( is_array( $response ) && isset( $response['response']['code'] ) ) {
			$code = (int) $response['response']['code'];
		}
		try {
			$sample = new SlowRequest( $host, intdiv( $elapsed, 1000 ), time(), $code, $error, 'unknown', null, null !== $this->loopback && $this->loopback->isLoopback( $host ) );
			$id     = $this->domains->upsert_slow_aggregate( $sample );
			if ( null === $id ) {
				return;
			}
			$event_id = $this->events->insert( $sample, $id );
			if ( null === $event_id ) {
				return;
			}
			try {
				$guess = $this->guesser->guess( $this->backtrace->capture() );
				$this->events->attribute( $event_id, $guess );
			} catch ( \Throwable $exception ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Attribution is optional after a successful unknown sample write.
				// A stored unknown sample remains observable when guessing fails.
			}
			Notifications::slow_recorded( $host, $sample->duration_us, $sample->is_loopback );
		} catch ( \Throwable $exception ) {
			// Diagnostics never change the caller's HTTP result.
			return;
		}
	}

	/**
	 * Store only slow valid samples; database failure cannot alter HTTP results.
	 *
	 * @param SlowRequest $sample Explicit sanitized sample.
	 * @return bool Whether aggregate and event were written.
	 */
	public function record_request( SlowRequest $sample ): bool {
		$host = DomainRepository::canonical_host( $sample->host );
		if ( $sample->duration_us < 1000000 || null === $host ) {
			return false;
		}
		try {
			$id = $this->domains->upsert_slow_aggregate( $sample );
			if ( null === $id || null === $this->events->insert( $sample, $id ) ) {
				return false;
			}
			Notifications::slow_recorded( $host, $sample->duration_us, $sample->is_loopback );
			return true;
		} catch ( \Throwable $exception ) {
			return false;
		}
	}
}
