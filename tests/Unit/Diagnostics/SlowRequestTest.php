<?php
/**
 * Tests for explicit recorder values and privacy-safe error identifiers.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Unit\Diagnostics;

use EgressEye\Diagnostics\SlowRequest;
use PHPUnit\Framework\TestCase;

/**
 * Storage does not infer or silently rewrite the recorder's component key.
 */
final class SlowRequestTest extends TestCase {

	/**
	 * A provided relative plugin-file key is retained verbatim, without detection.
	 *
	 * @return void
	 */
	public function test_explicit_component_values_are_preserved(): void {
		$sample = new SlowRequest( 'example.test', 1000000, 1710000000, 200, null, 'plugin', 'vendor/plugin.php' );
		self::assertSame( 'plugin', $sample->component_kind );
		self::assertSame( 'vendor/plugin.php', $sample->component_key );
	}

	/**
	 * An arbitrary error message or URL cannot enter the error-code column.
	 *
	 * @return void
	 */
	public function test_error_code_rejects_payload_but_keeps_safe_identifiers(): void {
		$sample = new SlowRequest( 'example.test', 1000000, 1710000000, null, 'https://example.test/?secret=value' );
		self::assertNull( $sample->error_code );
		$sample = new SlowRequest( 'example.test', 1000000, 1710000000, null, 'http_request_failed' );
		self::assertSame( 'http_request_failed', $sample->error_code );
	}
}
