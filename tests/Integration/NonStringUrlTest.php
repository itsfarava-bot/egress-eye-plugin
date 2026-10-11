<?php
/**
 * Native HTTP API malformed input parity with the plugin absent.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Integration;

use EgressEye\Http\HttpObserver;

/**
 * A plugin-origin TypeError or orphan context violates core parity.
 */
final class NonStringUrlTest extends \WP_UnitTestCase {

	/**
	 * Compare actual wp_remote_get outcomes, including native core exceptions.
	 *
	 * @dataProvider urls
	 * @param mixed $url Raw URL.
	 * @return void
	 */
	public function test_native_request_preserves_core_outcome( $url ): void {
		$callbacks = array();
		$stack     = $GLOBALS['wp_current_filter'];
		foreach ( array( 'http_request_args', 'pre_http_request', 'http_api_debug' ) as $hook ) {
			foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks[ PHP_INT_MAX ] ?? array() as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof HttpObserver ) {
					$callbacks[] = array( $hook, $callback['function'], $callback['accepted_args'] );
					$callback['function'][0]->reset();
					remove_filter( $hook, $callback['function'], PHP_INT_MAX );
				}
			}
		}
		try {
			$baseline = $this->outcome( $url );
		} finally {
			foreach ( $callbacks as $callback ) {
				add_filter( $callback[0], $callback[1], PHP_INT_MAX, $callback[2] );
			}
		}
		try {
			self::assertNotEmpty( $callbacks, 'Installed production observer must be present.' );
			self::assertSame( $baseline, $this->outcome( $url ), 'The plugin must not introduce an exception or change core warnings/errors.' );
			$reflection = new \ReflectionProperty( HttpObserver::class, 'context_store' );
			$reflection->setAccessible( true );
			foreach ( $callbacks as $callback ) {
				self::assertSame( 0, $reflection->getValue( $callback[1][0] )->count() );
			}
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore test fixture stack after deliberately exercising callback exceptions.
			$GLOBALS['wp_current_filter'] = $stack;
		}
	}

	/**
	 * Capture native warnings explicitly instead of treating them as passes.
	 *
	 * @param mixed $url Raw caller value.
	 * @return array<string,mixed>
	 */
	private function outcome( $url ): array {
		$warnings = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Test-only native warnings are captured and strictly compared, not hidden from the parity assertion.
		set_error_handler(
			static function ( $severity, $message ) use ( &$warnings ) {
				$warnings[] = array( $severity, $message );
				return true;
			}
		);
		try {
			$response = wp_remote_get( $url );
			return array(
				'codes'    => $response->get_error_codes(),
				'messages' => $response->get_error_messages(),
				'warnings' => $warnings,
			);
		} catch ( \Throwable $exception ) {
			return array(
				'exception' => get_class( $exception ),
				'message'   => $exception->getMessage(),
				'warnings'  => $warnings,
			);
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Representative invalid callers; no transport can be reached.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function urls(): array {
		return array(
			'null'   => array( null ),
			'array'  => array( array( 'not-a-url' ) ),
			'false'  => array( false ),
			'object' => array( new \stdClass() ),
		);
	}
}
