<?php
/**
 * Native WordPress recorder failures never disclose response/request details.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Integration;

use EgressEye\Tests\Support\AdminTestCase;
use EgressEye\Tests\Support\FakeClock;
use EgressEye\Tests\Support\FakeManualBlocker;
use EgressEye\Diagnostics\SlowRequestRecorderService;
use EgressEye\Persistence\EventRepository;
use EgressEye\Persistence\RetentionService;
use EgressEye\Http\HttpObserver;
use EgressEye\Http\InMemoryContextStore;
use EgressEye\Http\HookRegistrar;

/**
 * Real WP_Error extension can throw before the optional recorder's storage try.
 */
final class DiagnosticLogPrivacyTest extends AdminTestCase {
	/**
	 * RED to GREEN: original error/context survive and sensitive log values do not.
	 *
	 * @return void
	 */
	public function test_real_wp_debug_boundary_logs_only_a_static_failure_marker(): void {
		$log_file = sys_get_temp_dir() . '/egress-eye-wp-private-error-' . bin2hex( random_bytes( 8 ) ) . '.log';
		$old_log  = ini_get( 'error_log' );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- Isolate native test logging only; restore in finally.
		ini_set( 'error_log', $log_file );

		$clock     = new FakeClock();
		$store     = new InMemoryContextStore( $clock );
		$recorder  = new SlowRequestRecorderService( $this->domains, new EventRepository( $this->database, new RetentionService( $this->database ) ) );
		$observer  = new HttpObserver( $clock, $store, new FakeManualBlocker(), $recorder );
		$registrar = new HookRegistrar( $observer );
		$url       = 'https://private.example/path?token=private-query';
		$args      = $observer->filterRequestArgs(
			array(
				'blocking' => true,
				'headers'  => array( 'Secret' => 'private-header' ),
				'body'     => 'private-body',
			),
			$url
		);
		$response  = new class( 'fixture_error', 'original caller error' ) extends \WP_Error {
			/**
			 * Third-party response failure can contain sensitive diagnostic details.
			 *
			 * @return void
			 * @throws \Error For the deliberate diagnostic boundary fixture.
			 */
			public function get_error_code() {
				throw new \Error( 'https://private.example/path?token=private-query private-header private-body /srv/private/file.php C:\\private\\file.php' );
			}
		};
		$before    = $response;
		$clock->set( 1000000000 );
		$registrar->register();
		try {
			do_action( 'http_api_debug', $response, 'response', 'Requests', $args, $url );
			self::assertSame( $before, $response );
			self::assertSame( 'original caller error', $response->get_error_message( 'fixture_error' ) );
			self::assertSame( 0, $store->count() );
			self::assertFalse( current_filter() );
			self::assertSame( 0, (int) $this->database->scalar( 'SELECT COUNT(*) FROM %i', array( $this->database->table( 'events' ) ) ) );
			self::assertFileExists( $log_file );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the isolated native test log only.
			$logged = (string) file_get_contents( $log_file );
			self::assertStringContainsString( 'Egress Eye diagnostic failure', $logged );
			foreach ( array( 'private.example', 'private-query', 'private-header', 'private-body', '/srv/private', 'C:\\private' ) as $secret ) {
				self::assertStringNotContainsString( $secret, $logged );
			}
		} finally {
			$registrar->unregister();
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- Restore the original native test log destination.
			ini_set( 'error_log', (string) $old_log );
			if ( is_file( $log_file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this random fixture log.
				unlink( $log_file );
			}
		}
	}
}
