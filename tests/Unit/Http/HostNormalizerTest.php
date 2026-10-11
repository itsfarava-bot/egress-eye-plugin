<?php
/**
 * Exact host normalization tests.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Unit\Http;

use EgressEye\Http\HostNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Checks canonical host keys without wildcard matching.
 */
final class HostNormalizerTest extends TestCase {

	/**
	 * Normalize a URL or fail open for invalid input.
	 *
	 * @dataProvider normalization_cases
	 * @param string      $url      Original URL.
	 * @param string|null $expected Exact expected host.
	 * @return void
	 */
	public function test_exact_host_normalization( string $url, ?string $expected ): void {
		self::assertSame( $expected, ( new HostNormalizer() )->fromUrl( $url ) );
	}

	/**
	 * Hand-derived canonical hosts and malformed inputs.
	 *
	 * @return array<string,array{string,string|null}>
	 */
	public function normalization_cases(): array {
		return array(
			'lowercase'       => array( 'https://EXAMPLE.TEST/path', 'example.test' ),
			'trailing dot'    => array( 'http://example.test./', 'example.test' ),
			'credentials'     => array( 'https://user:pass@EXAMPLE.test:8443/path', 'example.test' ),
			'subdomain'       => array( 'https://api.example.test/', 'api.example.test' ),
			'IPv4'            => array( 'http://127.0.0.2:8080/', '127.0.0.2' ),
			'IPv6 brackets'   => array( 'http://[::1]:8080/', '::1' ),
			'IPv6 canonical'  => array( 'https://[0:0:0:0:0:0:0:1]/', '::1' ),
			'IPv6 lowercase'  => array( 'https://[2001:DB8::A]/', '2001:db8::a' ),
			'localhost'       => array( 'http://LOCALHOST/', 'localhost' ),
			'punycode'        => array( 'https://xn--bcher-kva.example/', 'xn--bcher-kva.example' ),
			'empty'           => array( '', null ),
			'no scheme'       => array( 'example.test/path', null ),
			'scheme relative' => array( '//example.test/path', null ),
			'unsupported'     => array( 'ftp://example.test/file', null ),
			'empty host'      => array( 'http:///path', null ),
			'wildcard'        => array( 'https://*.example.test/', null ),
			'empty label'     => array( 'https://a..example.test/', null ),
			'leading hyphen'  => array( 'https://-bad.example/', null ),
			'underscore'      => array( 'https://bad_host.example/', null ),
			'bad IPv6'        => array( 'http://[not-ipv6]/', null ),
			'bad port'        => array( 'http://example.test:99999/', null ),
			'numeric short'   => array( 'http://127.1/', null ),
			'control byte'    => array( "https://example.test\n/", null ),
			'long label'      => array( 'https://' . str_repeat( 'a', 64 ) . '.example/', null ),
		);
	}

	/**
	 * Unicode IDNs require intl and never cause a fatal when unavailable.
	 *
	 * @return void
	 */
	public function test_idn_requires_available_intl_converter(): void {
		$expected = function_exists( 'idn_to_ascii' ) ? 'xn--bcher-kva.example' : null;
		self::assertSame( $expected, ( new HostNormalizer() )->fromUrl( 'https://bücher.example/' ) );
	}
	/**
	 * A fresh PHP process with no extensions handles IDNs safely.
	 *
	 * @return void
	 */
	public function test_idn_without_intl_in_real_php_process(): void {
		$command     = array( PHP_BINARY, '-n', dirname( __DIR__, 2 ) . '/Fixtures/without-intl.php' );
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Test-only native PHP process with intl disabled.
		$process = proc_open( $command, $descriptors, $pipes );
		self::assertIsResource( $process );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing a child-process pipe.
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error  = stream_get_contents( $pipes[2] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing a child-process pipe.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing a child-process pipe.
		fclose( $pipes[2] );
		$exit_code = proc_close( $process );

		self::assertSame( 0, $exit_code, $error );
		self::assertSame( "without-intl probe passed\n", $output );
	}
}
