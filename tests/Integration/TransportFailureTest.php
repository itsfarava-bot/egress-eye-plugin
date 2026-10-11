<?php
/**
 * Failures from a transport object, caught by native WordPress completion.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Integration;

use EgressEye\Diagnostics\SlowRequestRecorderService;
use EgressEye\Http\BlockedHostGuard;
use EgressEye\Http\HookRegistrar;
use EgressEye\Http\HostNormalizer;
use EgressEye\Http\HttpObserver;
use EgressEye\Http\InMemoryContextStore;
use EgressEye\Http\MonotonicClock;
use EgressEye\Persistence\EventRepository;
use EgressEye\Persistence\RetentionService;
use EgressEye\Tests\Support\AdminTestCase;

/**
 * No preempted response or test-emitted debug action can satisfy this test.
 */
final class TransportFailureTest extends AdminTestCase {
	/**
	 * A fake transport throws; WordPress itself creates WP_Error and debug.
	 *
	 * @dataProvider failures
	 * @param string $type Transport failure category.
	 * @return void
	 */
	public function test_transport_failure_completes_naturally( string $type ): void {
		$clock     = new MonotonicClock();
		$store     = new InMemoryContextStore( $clock );
		$events    = new EventRepository( $this->database, new RetentionService( $this->database ) );
		$recorder  = new SlowRequestRecorderService( $this->domains, $events );
		$registrar = new HookRegistrar( new HttpObserver( $clock, $store, new BlockedHostGuard( new HostNormalizer(), $this->domains ), $recorder ) );
		$transport = new class( $type ) {
			/**
			 * Actual transport invocations.
			 *
			 * @var int
			 */
			public $calls = 0;
			/**
			 * Failure category.
			 *
			 * @var string
			 */
			private $type;
			/**
			 * Set the failure category.
			 *
			 * @param string $type Category.
			 */
			public function __construct( string $type ) {
				$this->type = $type;
			}
			/**
			 * Requests calls this transport; no public network is contacted.
			 *
			 * @return void
			 * @throws \WpOrg\Requests\Exception For the controlled transport failure.
			 */
			public function request(): void {
				++$this->calls;
				usleep( 1100000 );
				throw new \WpOrg\Requests\Exception( esc_html( 'transport ' . $this->type ), esc_html( $this->type ) );
			}
		};
		$inject    = static function ( &$url, &$headers, &$data, &$method, &$options ) use ( $transport ): void {
			$options['transport'] = $transport;
		};
		$debug     = array();
		$listen    = static function ( $response, $context, $transport_class, $args, $url ) use ( &$debug ): void {
			$debug[] = array( $response, $context, $transport_class, $args, $url );
		};
		$registrar->register();
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Core bridge; only transport selection is changed, not completion.
		add_action( 'requests-requests.before_request', $inject, 1, 5 );
		add_action( 'http_api_debug', $listen, 1, 5 );
		try {
			$response = wp_remote_get( 'https://transport.invalid/', array( 'timeout' => 3 ) );
			self::assertSame( 1, $transport->calls );
			self::assertWPError( $response );
			self::assertSame( 'http_request_failed', $response->get_error_code() );
			self::assertSame( 'transport ' . $type, $response->get_error_message() );
			self::assertCount( 1, $debug );
			self::assertSame( $response, $debug[0][0] );
			self::assertSame( 'response', $debug[0][1] );
			self::assertSame( 'WpOrg\\Requests\\Requests', $debug[0][2] );
			self::assertArrayHasKey( HttpObserver::CONTEXT_TOKEN_ARGUMENT, $debug[0][3] );
			self::assertSame( 0, $store->count() );
			self::assertSame( 1, (int) $this->database->scalar( 'SELECT COUNT(*) FROM %i', array( $this->database->table( 'events' ) ) ) );
			self::assertSame( 'http_request_failed', $this->database->scalar( 'SELECT error_code FROM %i', array( $this->database->table( 'events' ) ) ) );
			self::assertGreaterThanOrEqual( 1000000, (int) $this->database->scalar( 'SELECT duration_us FROM %i', array( $this->database->table( 'events' ) ) ) );
		} finally {
			// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Remove the test-only core bridge.
			remove_action( 'requests-requests.before_request', $inject, 1 );
			remove_action( 'http_api_debug', $listen, 1 );
			$registrar->unregister();
		}
	}

	/**
	 * Transport-level negative results, not native resolver/certificate claims.
	 *
	 * @return array<string,array{string}>
	 */
	public function failures(): array {
		return array(
			'timeout' => array( 'curlerror' ),
			'DNS'     => array( 'dns' ),
			'SSL'     => array( 'ssl' ),
		);
	}
}
