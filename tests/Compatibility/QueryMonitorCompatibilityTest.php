<?php
/**
 * Real Query Monitor collection evidence on the reference environment only.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Compatibility;

use EgressEye\Tests\Support\AdminTestCase;
use EgressEye\Tests\Support\LocalHttpServer;

/**
 * Disabling the collector must fail; loading only its file cannot pass.
 */
final class QueryMonitorCompatibilityTest extends AdminTestCase {
	/**
	 * Actual collector sees both real permitted calls with no duplicate slow event.
	 *
	 * @dataProvider modes
	 * @param string $mode    Explicit CLI collection test mode.
	 * @param bool   $enabled Expected collector state.
	 * @return void
	 */
	public function test_real_collector_on_and_off_preserves_requests( string $mode, bool $enabled ): void {
		$server = new LocalHttpServer();
		try {
			$result = $this->native_request( $server->get_url(), $mode );
			self::assertSame( array( 200, 200 ), $result['responses'] );
			self::assertSame( 2, $server->get_request_count() );
			self::assertSame( $enabled, $result['qm_tests'] );
			self::assertSame( $enabled, $result['qm_authorized'] );
			self::assertSame( $enabled ? 1 : 0, $result['qm_debug_callbacks'] );
			self::assertSame( 0, $result['qm_diagnostics']['ceases'] );
			self::assertSame( $enabled ? 9999 : false, $result['qm_priority'], wp_json_encode( $result ) );
			self::assertSame( $enabled ? 2 : 0, $result['http_requests'] );
			self::assertSame( $enabled ? 2 : 0, $result['http_responses'] );
			self::assertSame( 1, $result['guards'] );
			self::assertSame( 0, $result['contexts'] );
			self::assertCount( 1, $result['events'] );
			self::assertSame( 1, (int) $this->database->scalar( 'SELECT COUNT(*) FROM %i', array( $this->database->table( 'events' ) ) ) );
		} finally {
			$server->close();
		}
	}

	/**
	 * CLI flag uses Query Monitor's own supported test bootstrap.
	 *
	 * @return array<string,array{string,bool}>
	 */
	public function modes(): array {
		return array(
			'collection ON'  => array( 'qm-on', true ),
			'collection OFF' => array( 'qm-off', false ),
		);
	}

	/**
	 * Later preemption has no completed QM response and no actual connection.
	 *
	 * @return void
	 */
	public function test_manual_block_with_active_qm_has_no_completed_response_or_transport(): void {
		self::assertTrue( $this->domains->set_blocked( '127.0.0.1', true, $this->actor ) );
		$server = new LocalHttpServer();
		try {
			$result = $this->native_request( $server->get_url(), 'qm-block' );
			self::assertSame( 'egress_eye_host_blocked', $result['response'] );
			self::assertTrue( $result['qm_tests'] );
			self::assertTrue( $result['qm_authorized'] );
			self::assertSame( 1, $result['qm_debug_callbacks'] );
			self::assertSame( 0, $result['qm_diagnostics']['ceases'] );
			self::assertSame( 9999, $result['qm_priority'], wp_json_encode( $result ) );
			self::assertSame( 1, $result['http_requests'] );
			self::assertSame( 0, $result['http_responses'] );
			self::assertSame( 0, $server->get_request_count() );
			self::assertSame( 0, $result['contexts'] );
			self::assertSame( array(), $result['events'] );
		} finally {
			$server->close();
		}
	}
}
