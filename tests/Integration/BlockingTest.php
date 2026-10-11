<?php
/**
 * Real WordPress blocking tests with a counted loopback fixture.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Integration;

use EgressEye\Contracts\ManualBlocker;
use EgressEye\Http\BlockedHostGuard;
use EgressEye\Http\HookRegistrar;
use EgressEye\Http\HostNormalizer;
use EgressEye\Http\HttpObserver;
use EgressEye\Http\InMemoryContextStore;
use EgressEye\Http\MonotonicClock;
use EgressEye\Tests\Support\FakeSlowRequestRecorder;
use EgressEye\Tests\Support\InMemoryDomainRepository;
use EgressEye\Tests\Support\LocalHttpServer;

/**
 * Proves transport prevention by server arrivals, not elapsed time.
 */
final class BlockingTest extends \WP_UnitTestCase {

	/**
	 * A manually blocked host never reaches transport; another host works.
	 *
	 * @return void
	 */
	public function test_blocked_host_has_zero_arrivals_and_allowed_host_works(): void {
		$repository                = new InMemoryDomainRepository();
		$repository->blocked_hosts = array( '127.0.0.1' );
		$guard                     = new BlockedHostGuard( new HostNormalizer(), $repository );
		$clock                     = new MonotonicClock();
		$store                     = new InMemoryContextStore( $clock );
		$recorder                  = new FakeSlowRequestRecorder();
		$observer                  = new HttpObserver( $clock, $store, $guard, $recorder );
		$registrar                 = new HookRegistrar( $observer );
		$server                    = new LocalHttpServer();
		$open_after_start          = array();
		$open_after_preempt        = array();
		$start_listener            = static function ( $args ) use ( &$open_after_start, $store ) {
			$open_after_start[] = $store->count();

			return $args;
		};
		$listener                  = static function ( $preempt ) use ( &$open_after_preempt, $store ) {
			$open_after_preempt[] = $store->count();

			return $preempt;
		};

		$registrar->register();
		add_filter( 'http_request_args', $start_listener, PHP_INT_MAX, 2 );
		add_filter( 'pre_http_request', $listener, PHP_INT_MAX, 3 );

		try {
			self::assertSame( 0, $server->get_request_count() );
			$response = wp_remote_request( $server->get_url() );
			self::assertWPError( $response );
			self::assertSame( 'egress_eye_host_blocked', $response->get_error_code() );
			self::assertSame( 'This host is blocked by Egress Eye.', $response->get_error_message() );
			self::assertSame( 0, $server->get_request_count() );
			self::assertSame( array( 1 ), $open_after_start );
			self::assertSame( array( 0 ), $open_after_preempt );
			self::assertSame( 0, $store->count() );
			self::assertCount( 0, $recorder->records );

			$allowed = wp_remote_request( str_replace( '127.0.0.1', 'localhost', $server->get_url() ) );
			self::assertSame( 200, wp_remote_retrieve_response_code( $allowed ) );
			self::assertSame( 'egress-eye fixture', wp_remote_retrieve_body( $allowed ) );
			self::assertSame( 1, $server->get_request_count() );
			self::assertSame( 0, $store->count() );
			self::assertSame( 1, $repository->read_calls );
		} finally {
			remove_filter( 'http_request_args', $start_listener, PHP_INT_MAX );
			remove_filter( 'pre_http_request', $listener, PHP_INT_MAX );
			$registrar->unregister();
			$server->close();
		}
	}

	/**
	 * Earlier preemption bypasses even the guard's lazy repository lookup.
	 *
	 * @return void
	 */
	public function test_earlier_preempt_never_invokes_guard_repository(): void {
		$repository                = new InMemoryDomainRepository();
		$repository->blocked_hosts = array( '127.0.0.1' );
		$guard                     = new BlockedHostGuard( new HostNormalizer(), $repository );
		$blocker                   = $this->createMock( ManualBlocker::class );
		$clock                     = new MonotonicClock();
		$store                     = new InMemoryContextStore( $clock );
		$observer                  = new HttpObserver( $clock, $store, $blocker, new FakeSlowRequestRecorder() );
		$registrar                 = new HookRegistrar( $observer );
		$preempt                   = new \WP_Error( 'earlier_plugin', 'Earlier plugin response.' );
		$earlier                   = static function () use ( $preempt ) {
			return $preempt;
		};

		$blocker->expects( self::never() )->method( 'filter' )->willReturnCallback( array( $guard, 'filter' ) );
		$registrar->register();
		add_filter( 'pre_http_request', $earlier, 10, 3 );

		try {
			$response = wp_remote_request( 'http://127.0.0.1/preempted' );
		} finally {
			remove_filter( 'pre_http_request', $earlier, 10 );
			$registrar->unregister();
		}

		self::assertSame( $preempt, $response );
		self::assertSame( 0, $repository->read_calls );
		self::assertSame( 0, $store->count() );
	}

	/**
	 * Construction and direct decisions add no hooks or HTTP API calls.
	 *
	 * @return void
	 */
	public function test_guard_implements_port_without_hooks_or_own_http_calls(): void {
		$repository                = new InMemoryDomainRepository();
		$repository->blocked_hosts = array( '127.0.0.1' );
		$server                    = new LocalHttpServer();
		$http_calls                = 0;
		$listener                  = static function ( $preempt ) use ( &$http_calls ) {
			++$http_calls;
			return $preempt;
		};

		add_filter( 'pre_http_request', $listener, 1, 3 );

		try {
			$before = $this->snapshot_hook_callbacks();
			$guard  = new BlockedHostGuard( new HostNormalizer(), $repository );

			self::assertInstanceOf( ManualBlocker::class, $guard );
			self::assertSame( 0, $repository->read_calls );
			self::assertWPError( $guard->filter( false, array(), $server->get_url() ) );
			self::assertFalse( $guard->filter( false, array(), 'https://allowed.example/' ) );
			self::assertSame( $before, $this->snapshot_hook_callbacks() );
			self::assertSame( 0, $http_calls );
			self::assertSame( 0, $server->get_request_count() );
			self::assertSame( 1, $repository->read_calls );
		} finally {
			remove_filter( 'pre_http_request', $listener, 1 );
			$server->close();
		}
	}

	/**
	 * WordPress receives the English source and exact plugin text domain.
	 *
	 * @return void
	 */
	public function test_blocked_message_uses_english_source_and_plugin_text_domain(): void {
		$repository                = new InMemoryDomainRepository();
		$repository->blocked_hosts = array( 'example.com' );
		$guard                     = new BlockedHostGuard( new HostNormalizer(), $repository );
		$seen                      = array();
		$translator                = static function ( $translation, $text, $domain ) use ( &$seen ) {
			if ( 'egress-eye' === $domain ) {
				$seen[] = array( $text, $domain );
				return 'Translated fixture message.';
			}

			return $translation;
		};

		add_filter( 'gettext', $translator, 10, 3 );

		try {
			$response = $guard->filter( false, array(), 'https://example.com/' );
		} finally {
			remove_filter( 'gettext', $translator, 10 );
		}

		self::assertWPError( $response );
		self::assertSame( 'egress_eye_host_blocked', $response->get_error_code() );
		self::assertSame( 'Translated fixture message.', $response->get_error_message() );
		self::assertSame( array( array( 'This host is blocked by Egress Eye.', 'egress-eye' ) ), $seen );
	}

	/**
	 * The real integration process must exercise the available intl path.
	 *
	 * @return void
	 */
	public function test_idn_with_real_intl_converter(): void {
		self::assertTrue( extension_loaded( 'intl' ), 'The intl-present coverage requires ext-intl in this integration process.' );
		self::assertTrue( function_exists( 'idn_to_ascii' ) );
		self::assertSame( 'xn--bcher-kva.example', ( new HostNormalizer() )->fromUrl( 'https://bücher.example/' ) );
	}


	/**
	 * Blocking errors must not expose request secrets or full URLs.
	 *
	 * @return void
	 */
	public function test_blocked_error_contains_no_request_url_or_sensitive_arguments(): void {
		$repository                = new InMemoryDomainRepository();
		$repository->blocked_hosts = array( 'example.com' );
		$guard                     = new BlockedHostGuard( new HostNormalizer(), $repository );
		$url                       = 'https://fixture-user:fixture-password@example.com/private-fixture-path?token=fixture-query-secret';
		$args                      = array(
			'headers' => array( 'Authorization' => 'Bearer fixture-header-secret' ),
			'body'    => array( 'password' => 'fixture-body-secret' ),
			'timeout' => 17,
		);
		$response                  = $guard->filter( false, $args, $url );

		self::assertWPError( $response );
		self::assertSame( 'egress_eye_host_blocked', $response->get_error_code() );
		self::assertSame( array( 'This host is blocked by Egress Eye.' ), $response->get_error_messages() );
		self::assertNull( $response->get_error_data() );
		self::assertSame( array(), $response->get_all_error_data() );

		$payload = wp_json_encode( array( $response->get_error_messages(), $response->get_all_error_data() ) );
		self::assertIsString( $payload );

		foreach ( array( $url, 'fixture-user', 'fixture-password', 'private-fixture-path', 'fixture-query-secret', 'Authorization', 'fixture-header-secret', 'fixture-body-secret' ) as $secret ) {
			self::assertStringNotContainsString( $secret, $payload );
		}
	}

	/**
	 * Copy callback arrays so later hook mutations cannot alter the baseline.
	 *
	 * @return array<string,array<mixed>>
	 */
	private function snapshot_hook_callbacks(): array {
		$snapshot = array();

		foreach ( $GLOBALS['wp_filter'] as $name => $hook ) {
			$snapshot[ $name ] = $hook->callbacks;
		}

		return $snapshot;
	}
}
