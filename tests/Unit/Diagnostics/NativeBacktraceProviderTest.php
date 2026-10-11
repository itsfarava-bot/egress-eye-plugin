<?php
/**
 * Real bounded backtrace privacy contract.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Unit\Diagnostics;

use EgressEye\Diagnostics\NativeBacktraceProvider;
use PHPUnit\Framework\TestCase;

/**
 * Executes native PHP capture rather than checking source text.
 */
final class NativeBacktraceProviderTest extends TestCase {

	/**
	 * Deep calls are capped at thirty frames and arguments are never captured.
	 *
	 * @return void
	 */
	public function test_capture_ignores_arguments_and_limits_depth(): void {
		$provider = new NativeBacktraceProvider();
		$recurse  = static function ( $depth, $secret ) use ( &$recurse, $provider ) {
			return $depth > 0 ? $recurse( $depth - 1, $secret ) : $provider->capture();
		};
		$trace    = $recurse( 40, 'request-secret' );
		self::assertCount( 30, $trace );
		foreach ( $trace as $frame ) {
			self::assertArrayNotHasKey( 'args', $frame );
		}
	}
}
