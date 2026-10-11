<?php
/**
 * Minimal real WordPress integration environment smoke test.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Integration;

/**
 * Confirms that WordPress, Egress Eye and the MariaDB/MySQL connection are live.
 */
final class EnvironmentSmokeTest extends \WP_UnitTestCase {

	/**
	 * Verify the integration environment and print its concrete versions.
	 *
	 * @return void
	 */
	public function test_wordpress_plugin_and_database_are_available(): void {
		global $wpdb;

		$egress_eye_active_plugins   = (array) get_option( 'active_plugins', array() );
		$egress_eye_database_version = $wpdb->db_server_info();

		self::assertContains( 'egress-eye/egress-eye.php', $egress_eye_active_plugins );
		self::assertTrue( defined( 'EGRESS_EYE_VERSION' ) );
		self::assertInstanceOf( \wpdb::class, $wpdb );
		self::assertIsString( $egress_eye_database_version );
		self::assertNotSame( '', $egress_eye_database_version );

		printf(
			"Integration environment: WordPress %s | PHP %s | MariaDB/MySQL %s\n",
			esc_html( get_bloginfo( 'version' ) ),
			esc_html( PHP_VERSION ),
			esc_html( $egress_eye_database_version )
		);
	}
}
