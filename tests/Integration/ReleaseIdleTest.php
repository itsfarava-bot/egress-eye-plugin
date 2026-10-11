<?php
/**
 * Release gate for a steady installed request without outgoing HTTP.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Integration;

use EgressEye\Persistence\RetentionService;
use EgressEye\Tests\Support\AdminTestCase;

/**
 * Eager repository reads or steady boot writes must fail this gate.
 */
final class ReleaseIdleTest extends AdminTestCase {
	/**
	 * Installed schema and existing cron need no plugin-owned SQL on the frontend.
	 *
	 * @return void
	 */
	public function test_no_http_front_request_has_zero_plugin_queries(): void {
		wp_schedule_event( time() + 3600, 'hourly', RetentionService::CRON_HOOK );
		$result = $this->native_request( 'http://unused.invalid/', 'idle' );
		self::assertSame( 1, $result['front_main'] );
		self::assertSame( 0, $result['owned_queries'] );
		self::assertSame( array(), $result['events'] );
		self::assertSame( 0, $result['contexts'] );
	}
}
