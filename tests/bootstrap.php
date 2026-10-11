<?php
/**
 * PHPUnit bootstrap with optional WordPress test-suite loading.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$egress_eye_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );

if (
	is_string( $egress_eye_polyfills_path )
	&& '' !== $egress_eye_polyfills_path
	&& ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' )
) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $egress_eye_polyfills_path );
}

if ( '1' === getenv( 'EGRESS_EYE_INTEGRATION' ) ) {
	$GLOBALS['wp_tests_options'] = array(
		'active_plugins' => array( 'egress-eye/egress-eye.php' ),
	);
}

$egress_eye_wordpress_test_bootstrap = getenv( 'WP_TESTS_BOOTSTRAP' );

if ( false === $egress_eye_wordpress_test_bootstrap ) {
	$egress_eye_wordpress_tests_directory = getenv( 'WP_TESTS_DIR' );

	if ( false !== $egress_eye_wordpress_tests_directory ) {
		$egress_eye_wordpress_test_bootstrap = rtrim( $egress_eye_wordpress_tests_directory, '/\\' ) . '/includes/bootstrap.php';
	}
}

if ( is_string( $egress_eye_wordpress_test_bootstrap ) && is_file( $egress_eye_wordpress_test_bootstrap ) ) {
	require_once $egress_eye_wordpress_test_bootstrap;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	require_once __DIR__ . '/Support/standalone-wordpress.php';
}
