<?php
/**
 * Minimal standalone boundary doubles; never loaded with real WordPress.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Supply native parsing to database-free unit tests.
	 *
	 * Real wp_parse_url is exercised by the integration jobs.
	 *
	 * @param string $url       URL to parse.
	 * @param int    $component Requested component.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Native parser only in the standalone boundary double; real WordPress is used in Integration.
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Return the English source string in standalone tests.
	 *
	 * @param string $text   Source string.
	 * @param string $domain Translation domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Preserve the WordPress translation API signature.
		return $text;
	}
}

require_once __DIR__ . '/standalone-wp-error.php';

if ( ! function_exists( 'wp_normalize_path' ) ) {
	/**
	 * Normalize literal paths for standalone fixtures only.
	 *
	 * @param string $path Path fixture.
	 * @return string
	 */
	function wp_normalize_path( $path ) {
		return str_replace( '\\', '/', $path );
	}
}
