<?php
/**
 * Reproducible local HTTP fast path measurements for one CI environment.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Performance;

use EgressEye\Tests\Support\AdminTestCase;
use EgressEye\Tests\Support\LocalHttpServer;

/**
 * Uses installed callbacks and writes raw measurements alongside summary data.
 */
final class FastPathBenchmarkTest extends AdminTestCase {
	/**
	 * Relative budget includes an absolute CI noise allowance.
	 *
	 * @return void
	 */
	public function test_warmed_real_http_path_has_bounded_cost_and_no_queries(): void {
		$server = new LocalHttpServer();
		try {
			$result = $this->native_request( $server->get_url(), 'benchmark' );
			$report = $result['benchmark'];

			$report['environment'] = array(
				'wordpress' => get_bloginfo( 'version' ),
				'php'       => PHP_VERSION,
				'database'  => $GLOBALS['wpdb']->db_version(),
			);

			$report['transport_calls'] = $server->get_request_count();
			$report['contexts_after']  = $result['contexts'];

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CI artifact in the runner temporary directory only.
			self::assertNotFalse( file_put_contents( getenv( 'EGRESS_EYE_BENCHMARK_JSON' ), wp_json_encode( $report, JSON_PRETTY_PRINT ) . "\n" ) );
			self::assertSame( 100, $report['samples'] );
			self::assertSame( 20, $report['warmup'] );
			self::assertSame( 0, $report['warm_queries'] );
			self::assertSame( 0, $report['writes'] );
			self::assertSame( 0, $result['contexts'] );
			self::assertSame( 1, $result['guards'] );
			self::assertSame( 2, $result['snapshot_reads'] + $result['host_reads'] );
			self::assertLessThanOrEqual( $report['baseline_p95_us'] * 3 + 5000, $report['active_p95_us'] );
			self::assertSame( 241, $report['transport_calls'] );
		} finally {
			$server->close();
		}
	}
}
