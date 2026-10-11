<?php
/**
 * Missing contract rows exercised at the real WordPress HTTP boundary.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Integration;

use EgressEye\Http\BlockedHostGuard;
use EgressEye\Http\HookRegistrar;
use EgressEye\Http\HostNormalizer;
use EgressEye\Http\HttpObserver;
use EgressEye\Http\InMemoryContextStore;
use EgressEye\Http\MonotonicClock;
use EgressEye\Diagnostics\SlowRequestRecorderService;
use EgressEye\Persistence\EventRepository;
use EgressEye\Persistence\RetentionService;
use EgressEye\Tests\Support\AdminTestCase;
use EgressEye\Tests\Support\LocalHttpServer;

/**
 * Catches skipped error completion, redirect duplication and dropped slow events.
 */
final class FullContractMatrixTest extends AdminTestCase {
	/**
	 * Controlled Requests exceptions go through WP_Error and real terminal debug.
	 * This is error-boundary injection, not a claim of exercising DNS or TLS stacks.
	 *
	 * @dataProvider transport_errors
	 * @param string $type Controlled Requests failure type.
	 * @return void
	 */
	public function test_transport_exception_preserves_error_and_records_actual_elapsed_time( string $type ): void {
		$clock     = new MonotonicClock();
		$store     = new InMemoryContextStore( $clock );
		$events    = new EventRepository( $this->database, new RetentionService( $this->database ) );
		$recorder  = new SlowRequestRecorderService( $this->domains, $events );
		$registrar = new HookRegistrar( new HttpObserver( $clock, $store, new BlockedHostGuard( new HostNormalizer(), $this->domains ), $recorder ) );
		$failure   = static function () use ( $type ): void {
			usleep( 1100000 );
			throw new \WpOrg\Requests\Exception( esc_html( 'controlled ' . $type ), esc_html( $type ) );
		};
		$debug     = null;
		$listen    = static function ( $response ) use ( &$debug ): void {
			$debug = $response;
		};
		$registrar->register();
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Native WordPress bridge for Requests hook.
		add_action( 'requests-requests.before_request', $failure );
		add_action( 'http_api_debug', $listen, 1 );
		try {
			$response = wp_remote_get( 'http://127.0.0.1:9/controlled-error', array( 'timeout' => 3 ) );
			self::assertWPError( $response );
			self::assertSame( 'http_request_failed', $response->get_error_code() );
			self::assertSame( 'controlled ' . $type, $response->get_error_message() );
			self::assertSame( $response, $debug );
			self::assertSame( 0, $store->count() );
			self::assertSame( 1, (int) $this->database->scalar( 'SELECT COUNT(*) FROM %i', array( $this->database->table( 'events' ) ) ) );
			self::assertGreaterThanOrEqual( 1000000, (int) $this->database->scalar( 'SELECT duration_us FROM %i', array( $this->database->table( 'events' ) ) ) );
		} finally {
			// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Remove exactly the test-only bridged hook.
			remove_action( 'requests-requests.before_request', $failure );
			remove_action( 'http_api_debug', $listen, 1 );
			$registrar->unregister();
		}
	}

	/**
	 * Boundary error variants, independent of public DNS or certificate services.
	 *
	 * @return array<string,array{string}>
	 */
	public function transport_errors(): array {
		return array(
			'timeout' => array( 'curlerror' ),
			'DNS'     => array( 'dns' ),
			'SSL'     => array( 'ssl' ),
		);
	}

	/**
	 * A real redirect across host spellings produces one original-host event.
	 *
	 * @return void
	 */
	public function test_real_cross_host_redirect_has_one_original_host_record(): void {
		$server = new LocalHttpServer();
		try {
			$result = $this->native_request( $server->get_url() . '?egress_eye_redirect=1' );
			self::assertSame( 200, $result['response'] );
			self::assertSame( 2, $server->get_request_count() );
			self::assertCount( 1, $result['events'] );
			self::assertSame( '127.0.0.1', $result['events'][0]['host'] );
		} finally {
			$server->close();
		}
	}

	/**
	 * Ten real slow requests must produce exactly ten persisted events and sums.
	 *
	 * @return void
	 */
	public function test_ten_real_slow_calls_have_exact_events_and_aggregate(): void {
		$server = new LocalHttpServer();
		try {
			$result = $this->native_request( $server->get_url() . '?egress_eye_slow_fixture=1', 'ten' );
			self::assertSame( 200, $result['response'] );
			self::assertSame( 10, $server->get_request_count() );
			self::assertCount( 10, $result['events'] );
			self::assertSame( 10, (int) $this->database->scalar( 'SELECT COUNT(*) FROM %i', array( $this->database->table( 'events' ) ) ) );
			self::assertSame( 10, (int) $this->database->scalar( 'SELECT slow_count FROM %i', array( $this->database->table( 'domains' ) ) ) );
			self::assertSame( (int) $this->database->scalar( 'SELECT SUM(duration_us) FROM %i', array( $this->database->table( 'events' ) ) ), (int) $this->database->scalar( 'SELECT slow_duration_sum_us FROM %i', array( $this->database->table( 'domains' ) ) ) );
		} finally {
			$server->close();
		}
	}
}
