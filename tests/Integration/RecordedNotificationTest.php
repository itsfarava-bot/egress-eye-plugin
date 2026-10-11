<?php
/**
 * Successful storage remains observable when optional attribution fails.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Integration;

use EgressEye\Tests\Support\AdminTestCase;
use EgressEye\Contracts\BacktraceProvider;
use EgressEye\Contracts\ComponentGuesser;
use EgressEye\Contracts\DiagnosticEvents;
use EgressEye\Diagnostics\SlowRequest;
use EgressEye\Diagnostics\SlowRequestRecorderService;
use EgressEye\Diagnostics\ComponentGuess;
use EgressEye\Persistence\EventRepository;
use EgressEye\Persistence\RetentionService;
use EgressEye\Http\RequestContext;

/**
 * Behavioral detectors for notifications after a real aggregate and event.
 */
final class RecordedNotificationTest extends AdminTestCase {

	/**
	 * RED to GREEN: optional attribution cannot hide a completed persisted sample.
	 *
	 * @dataProvider attribution_failures
	 * @param string $failure Attribution boundary failure.
	 * @return void
	 */
	public function test_saved_sample_notifies_when_optional_attribution_fails( string $failure ): void {
		$seen     = array();
		$listener = static function ( $payload ) use ( &$seen ) {
			$seen[] = $payload;
		};
		$trace    = $this->createMock( BacktraceProvider::class );
		$guesser  = $this->createMock( ComponentGuesser::class );
		if ( 'capture' === $failure ) {
			$trace->method( 'capture' )->willThrowException( new \Error( 'private capture detail' ) );
		} else {
			$trace->method( 'capture' )->willReturn( array() );
		}
		if ( 'guess' === $failure ) {
			$guesser->method( 'guess' )->willThrowException( new \Error( 'private guess detail' ) );
		} else {
			$guesser->method( 'guess' )->willReturn( new ComponentGuess( 'unknown', null ) );
		}
		$real_events = new EventRepository( $this->database, new RetentionService( $this->database ) );
		$events      = $this->createMock( DiagnosticEvents::class );
		$events->method( 'insert' )->willReturnCallback( array( $real_events, 'insert' ) );
		$events->method( 'attribute' )->willReturn( false );
		$recorder = new SlowRequestRecorderService( $this->domains, $events, $guesser, $trace );
		$context  = new RequestContext( 'notification', 'https://notify.example/private?secret=hidden', 0, true, 1 );
		$context->complete( 1000000000 );
		$response = array(
			'response' => array( 'code' => 200 ),
			'body'     => 'caller response',
		);
		$before   = $response;
		add_action( 'egress_eye_slow_request_recorded', $listener );
		try {
			$recorder->record( $context, $response, '', array( 'body' => 'private body' ), $context->getUrl() );
			self::assertSame( $before, $response );
			self::assertSame( 1, (int) $this->database->scalar( 'SELECT COUNT(*) FROM %i', array( $this->database->table( 'events' ) ) ) );
			self::assertSame( 'unknown', $this->database->scalar( 'SELECT component_kind FROM %i', array( $this->database->table( 'events' ) ) ) );
			self::assertSame(
				array(
					array(
						'host'        => 'notify.example',
						'duration_us' => 1000000,
						'is_loopback' => false,
					),
				),
				$seen
			);
		} finally {
			remove_action( 'egress_eye_slow_request_recorded', $listener );
		}
	}

	/**
	 * The false attribute result is initially passing new coverage.
	 *
	 * @return array<string,array{string}>
	 */
	public function attribution_failures(): array {
		return array(
			'capture throws'    => array( 'capture' ),
			'guess throws'      => array( 'guess' ),
			'attribute refuses' => array( 'attribute' ),
		);
	}

	/**
	 * Verify canonical small payload agrees with the real stored host.
	 *
	 * @return void
	 */
	public function test_direct_record_notification_uses_the_stored_canonical_host(): void {
		$seen     = array();
		$listener = static function ( $payload ) use ( &$seen ) {
			$seen[] = $payload;
		};
		$recorder = new SlowRequestRecorderService( $this->domains, new EventRepository( $this->database, new RetentionService( $this->database ) ) );
		add_action( 'egress_eye_slow_request_recorded', $listener );
		try {
			self::assertTrue( $recorder->record_request( new SlowRequest( 'CANONICAL.EXAMPLE.', 1000000, time() ) ) );
			self::assertSame( 'canonical.example', $this->database->scalar( 'SELECT host FROM %i', array( $this->database->table( 'domains' ) ) ) );
			self::assertSame( 'canonical.example', $seen[0]['host'] );
		} finally {
			remove_action( 'egress_eye_slow_request_recorded', $listener );
		}
	}
}
