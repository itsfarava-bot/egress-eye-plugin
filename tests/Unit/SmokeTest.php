<?php
/**
 * Task 1 bootstrap smoke tests.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the plugin entry file and Composer namespace without running hooks.
 */
final class SmokeTest extends TestCase {

	/**
	 * The standalone header check must not include the guarded entry file.
	 *
	 * @return void
	 */
	public function test_entry_header_can_be_read_without_including_guard(): void {
		$egress_eye_root  = dirname( __DIR__, 2 );
		$egress_eye_entry = $egress_eye_root . '/egress-eye.php';

		self::assertFileExists( $egress_eye_entry );

		if ( ! is_file( $egress_eye_entry ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the local plugin header without executing the direct-access guard.
		$egress_eye_contents = (string) file_get_contents( $egress_eye_entry );

		self::assertStringContainsString( 'Plugin Name: Egress Eye', $egress_eye_contents );
		self::assertStringContainsString( 'License: GPLv2 or later', $egress_eye_contents );
		self::assertStringContainsString( 'License URI:', $egress_eye_contents );
		self::assertStringContainsString( 'Text Domain: egress-eye', $egress_eye_contents );
		self::assertStringContainsString( 'Domain Path: /languages', $egress_eye_contents );
	}

	/**
	 * The entry file may load after WordPress has defined ABSPATH.
	 *
	 * @return void
	 */
	public function test_plugin_loads_when_wordpress_bootstrap_defines_abspath(): void {
		$egress_eye_root         = dirname( __DIR__, 2 );
		$egress_eye_entry        = $egress_eye_root . '/egress-eye.php';
		$egress_eye_plugin_class = $egress_eye_root . '/src/Plugin.php';

		self::assertFileExists( $egress_eye_entry );
		self::assertFileExists( $egress_eye_plugin_class );

		if ( ! is_file( $egress_eye_entry ) || ! is_file( $egress_eye_plugin_class ) ) {
			return;
		}

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', $egress_eye_root . '/' );
		}

		require_once $egress_eye_entry;

		$egress_eye_plugin = new \ReflectionClass( \EgressEye\Plugin::class );

		self::assertSame( 'EgressEye\\Plugin', $egress_eye_plugin->getName() );
		self::assertTrue( $egress_eye_plugin->getConstructor()->isPrivate() );
		self::assertTrue( $egress_eye_plugin->getMethod( 'boot' )->isStatic() );
		self::assertSame( '1.0.0', constant( 'EGRESS_EYE_VERSION' ) );
	}
}
