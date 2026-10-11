<?php
/**
 * Runtime zero outbound acceptance through installed plugin boundaries.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Integration;

use EgressEye\Tests\Support\AdminTestCase;
use EgressEye\Tests\Support\NetworkTrap;

/**
 * Network tripwires are scoped, restored, and have executable negative controls.
 */
final class NoOutboundLifecycleTest extends AdminTestCase {
	/**
	 * Entry, storage initialization and actual front bootstrap make no calls.
	 *
	 * @return void
	 */
	public function test_native_boot_and_front_request_have_no_outbound_attempts(): void {
		$result = $this->native_request( 'http://example.invalid/', 'no-outbound' );
		self::assertSame( 0, $result['outbound_http'], wp_json_encode( $result ) );
		self::assertSame( 0, $result['outbound_streams'] );
		self::assertSame( 1, $result['guards'] );
		self::assertSame( 1, $result['front_main'] );
		self::assertSame( 1, $result['shutdowns'] );
	}

	/**
	 * Activation, rendered admin, authenticated mutations and uninstall.
	 *
	 * @return void
	 */
	public function test_installed_lifecycle_has_no_outbound_attempts(): void {
		$trap = new NetworkTrap();
		$trap->install();
		try {
			$entry = dirname( __DIR__, 2 ) . '/egress-eye.php';
			self::assertNotFalse( has_action( 'activate_' . plugin_basename( $entry ) ) );
			do_action( 'activate_' . plugin_basename( $entry ), false );
			self::assertStringContainsString( 'egress-eye', $this->page() );
			$id = $this->domains->ensure_host( 'lifecycle.example' );
			$this->post_action( $this->fields( $id ) );
			self::assertSame( 1, $this->state( $id ) );
			$this->post_action( $this->fields( $id, 'unblock' ) );
			self::assertSame( 0, $this->state( $id ) );
			if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
				define( 'WP_UNINSTALL_PLUGIN', plugin_basename( $entry ) );
			}
			require dirname( __DIR__, 2 ) . '/uninstall.php';
			self::assertSame( 0, $trap->http );
			self::assertSame( 0, NetworkTrap::$streams );
			self::assertNull( $this->database->scalar( 'SHOW TABLES LIKE %s', array( $this->database->table( 'domains' ) ) ) );
		} finally {
			$trap->restore();
		}
	}

	/**
	 * The test catches attempts and refuses both transports without networking.
	 *
	 * @return void
	 */
	public function test_tripwires_detect_and_prevent_deliberate_attempts(): void {
		$trap = new NetworkTrap();
		$trap->install();
		try {
			$response = wp_remote_get( 'http://example.invalid/' );
			self::assertWPError( $response );
			self::assertSame( 'egress_eye_network_tripwire', $response->get_error_code() );
			self::assertSame( 1, $trap->http );
			$warnings = array();
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped expected refusal warning; restored in finally and asserted below.
			set_error_handler(
				static function ( int $severity, string $message ) use ( &$warnings ): bool {
					self::assertSame( E_WARNING, $severity );
					$warnings[] = $message;
					return true;
				}
			);
			try {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Deliberate blocked URL wrapper negative control.
				self::assertFalse( file_get_contents( 'https://example.invalid/' ) );
			} finally {
				restore_error_handler();
			}
			self::assertSame( 1, NetworkTrap::$streams );
			self::assertSame( 'https://example.invalid/', NetworkTrap::$attempts[0]['path'] );
			self::assertCount( 1, $warnings );
			self::assertStringContainsString( 'failed to open stream', strtolower( $warnings[0] ) );
		} finally {
			$trap->restore();
		}
	}
}
