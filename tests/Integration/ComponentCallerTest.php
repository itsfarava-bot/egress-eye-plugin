<?php
/**
 * Real HTTP API caller stacks, using a local pre-request response.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Integration;

use EgressEye\Diagnostics\NativeBacktraceProvider;
use EgressEye\Diagnostics\StackComponentGuesser;

/**
 * Fixture source files have registered logical component identities.
 */
final class ComponentCallerTest extends \WP_UnitTestCase {

	/**
	 * Direct callers survive unrelated component dispatch frames below them.
	 *
	 * @dataProvider caller_cases
	 * @param string $caller Fixture callback.
	 * @param string $kind   Expected component category.
	 * @param string $key    Expected component identifier.
	 * @return void
	 */
	public function test_actual_http_api_caller_stacks( string $caller, string $kind, string $key ): void {
		global $wp_plugin_paths;
		$original = $wp_plugin_paths;
		$fixtures = dirname( __DIR__ ) . '/Fixtures/components';
		foreach ( array( 'direct-plugin', 'cron-wrapper', 'theme-caller', 'mu-caller' ) as $fixture ) {
			require_once $fixtures . '/' . $fixture . '/caller.php';
		}
		$wp_plugin_paths = array_merge(
			is_array( $original ) ? $original : array(),
			array(
				WP_PLUGIN_DIR . '/direct-plugin'   => $fixtures . '/direct-plugin',
				WP_PLUGIN_DIR . '/cron-wrapper'    => $fixtures . '/cron-wrapper',
				get_theme_root() . '/theme-caller' => $fixtures . '/theme-caller',
				WPMU_PLUGIN_DIR . '/mu-caller'     => $fixtures . '/mu-caller',
			)
		);
		$captured        = null;
		$probe           = static function () use ( &$captured ) {
			$captured = ( new NativeBacktraceProvider() )->capture();
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $probe );
		try {
			$caller();
			self::assertIsArray( $captured );
			self::assertLessThanOrEqual( 30, count( $captured ) );
			$guess = ( new StackComponentGuesser() )->guess( $captured );
			self::assertSame( $kind, $guess->kind );
			self::assertSame( $key, $guess->key );
			self::assertTrue( $guess->is_guess );
			self::assertStringNotContainsString( '/', $guess->key );
			foreach ( $captured as $frame ) {
				self::assertArrayNotHasKey( 'args', $frame );
				self::assertArrayNotHasKey( 'object', $frame );
			}
		} finally {
			remove_filter( 'pre_http_request', $probe );
			$wp_plugin_paths = $original;
		}
	}

	/**
	 * Callback paths exercise direct, core-hook and separate-plugin dispatch.
	 *
	 * @return array<string,array{string,string,string}>
	 */
	public function caller_cases(): array {
		return array(
			'direct plugin'            => array( 'EgressEye\\Tests\\Fixtures\\direct_plugin_request', 'plugin', 'direct-plugin' ),
			'WP-Cron plugin wrapper'   => array( 'EgressEye\\Tests\\Fixtures\\cron_wrapper_request', 'plugin', 'direct-plugin' ),
			'theme via plugin wrapper' => array( 'EgressEye\\Tests\\Fixtures\\wrapped_theme_request', 'theme', 'theme-caller' ),
			'MU via plugin wrapper'    => array( 'EgressEye\\Tests\\Fixtures\\wrapped_mu_request', 'mu-plugin', 'mu-caller' ),
		);
	}
}
