<?php
/**
 * Installed composition runtime evidence, separate from a manually reused guard.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Integration;

use EgressEye\Tests\Support\AdminTestCase;
use EgressEye\Tests\Support\LocalHttpServer;

/**
 * Per-call guard construction and fast-path database writes must fail this test.
 */
final class InstalledCompositionTest extends AdminTestCase {
	/**
	 * Native boot owns one root, one repository snapshot and zero fast writes.
	 *
	 * @return void
	 */
	public function test_installed_root_reuses_one_snapshot_for_three_real_fast_calls(): void {
		$server = new LocalHttpServer();
		try {
			$result = $this->native_request( $server->get_url(), 'multi' );
			self::assertSame( array( 200, 200, 200 ), $result['responses'] );
			self::assertSame( 0, $result['contexts'] );
			self::assertSame( 3, $server->get_request_count() );
			self::assertSame( 1, $result['guards'] );
			self::assertSame( 1, $result['snapshot_reads'], 'Exactly one repository invocation, including cache validation.' );
			self::assertSame( 1, $result['host_reads'], 'Fresh PHP request cold cache reads one host list.' );
			self::assertSame( 0, $result['writes'], 'No SQL mutation during any of the fast requests.' );
			self::assertSame( array(), $result['events'] );
			self::assertSame( 0, (int) $this->database->scalar( 'SELECT COUNT(*) FROM %i', array( $this->database->table( 'events' ) ) ) );
			self::assertSame( 0, (int) $this->database->scalar( 'SELECT COUNT(*) FROM %i', array( $this->database->table( 'domains' ) ) ) );
		} finally {
			$server->close();
		}
	}
}
