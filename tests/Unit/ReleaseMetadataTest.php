<?php
/**
 * Release identity alignment; schema version intentionally stays independent.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Unit;

use EgressEye\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * Any stale release version or missing source catalog fails this gate.
 */
final class ReleaseMetadataTest extends TestCase {
	/**
	 * Public release metadata must describe the exact candidate version.
	 *
	 * @return void
	 */
	public function test_release_version_and_catalog_are_aligned(): void {
		$root = dirname( __DIR__, 2 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local metadata inspection, no URL.
		$entry = (string) file_get_contents( $root . '/egress-eye.php' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release readme.
		$readme = (string) file_get_contents( $root . '/readme.txt' );
		self::assertSame( '1.0.0', Plugin::VERSION );
		self::assertStringContainsString( 'Version: 1.0.0', $entry );
		self::assertStringContainsString( "define( 'EGRESS_EYE_VERSION', '1.0.0' )", $entry );
		self::assertStringContainsString( 'Stable tag: 1.0.0', $readme );
		self::assertStringContainsString( 'Requires at least: 6.6', $readme );
		self::assertStringContainsString( 'Requires PHP: 7.4', $readme );
		self::assertStringContainsString( 'License: GPLv2 or later', $readme );
		self::assertFileExists( $root . '/CHANGELOG.md' );
		self::assertFileExists( $root . '/languages/egress-eye.pot' );
	}
}
