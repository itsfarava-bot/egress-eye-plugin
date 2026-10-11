<?php
/**
 * Plugin Name: Egress Eye
 * Description: Measures slow server-side WordPress HTTP API requests and provides manual domain blocking.
 * Version: 1.0.0
 * Author: Sam
 * Plugin URI:
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Text Domain: egress-eye
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Egress_Eye
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'EGRESS_EYE_VERSION' ) ) {
	define( 'EGRESS_EYE_VERSION', '1.0.0' );
}

// Production has no Composer runtime dependency. Development may already have
// loaded Composer; this bounded fallback only resolves plugin-owned classes.
spl_autoload_register(
	static function ( $egress_eye_class ) {
		$egress_eye_prefix = 'EgressEye\\';
		if ( 0 !== strpos( $egress_eye_class, $egress_eye_prefix ) ) {
			return;
		}
		$egress_eye_relative = substr( $egress_eye_class, strlen( $egress_eye_prefix ) );
		if ( ! preg_match( '/^[A-Za-z0-9_\\\\]+$/D', $egress_eye_relative ) ) {
			return;
		}
		$egress_eye_path = __DIR__ . '/src/' . str_replace( '\\', '/', $egress_eye_relative ) . '.php';
		if ( is_readable( $egress_eye_path ) ) {
			require_once $egress_eye_path;
		}
	}
);

\EgressEye\Plugin::boot( __FILE__ );
