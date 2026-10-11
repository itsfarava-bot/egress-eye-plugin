<?php
/**
 * Tokenized source gate for plugin owned outbound calls.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Function references in stack attribution strings are not executable calls.
 */
final class NoOutboundSourceTest extends TestCase {
	/**
	 * All production source is scanned, rather than a handpicked allowlist.
	 *
	 * @return void
	 */
	public function test_production_source_has_no_direct_outbound_calls(): void {
		$files   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/src' ) );
		$scanned = 0;
		foreach ( $files as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			++$scanned;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source inspection, never a URL.
			self::assertSame( array(), $this->calls( (string) file_get_contents( $file->getPathname() ) ), $file->getPathname() );
		}
		self::assertGreaterThan( 20, $scanned );
	}

	/**
	 * Scanner negative controls cover named calls, comments and literals.
	 *
	 * @return void
	 */
	public function test_scanner_detects_outbound_primitives_and_ignores_non_calls(): void {
		foreach ( array( 'wp_remote_get', 'wp_safe_remote_post', 'curl_exec', 'fsockopen', 'pfsockopen', 'stream_socket_client', 'socket_connect', 'ftp_connect', 'stream_socket_sendto', 'file_get_contents', 'file_put_contents', 'fopen', 'get_headers', 'download_url' ) as $name ) {
			self::assertSame( array( $name ), $this->calls( '<?php ' . $name . '( "https://example.invalid/" );' ) );
		}
		self::assertSame( array(), $this->calls( '<?php $names = array( "wp_remote_get", "curl_exec" ); /* fsockopen(); */' ) );
	}

	/**
	 * Find direct calls, conservatively disallowing filesystem helpers in src.
	 *
	 * @param string $source PHP source.
	 * @return array<string>
	 */
	private function calls( string $source ): array {
		$tokens = array_values(
			array_filter(
				token_get_all( $source ),
				static function ( $token ): bool {
					return ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
				}
			)
		);

		$found = array();
		foreach ( $tokens as $index => $token ) {
			if ( ! is_array( $token ) || '(' !== ( $tokens[ $index + 1 ] ?? null ) ) {
				continue;
			}
			$name = strtolower( ltrim( $token[1], '\\' ) );
			if ( preg_match( '/^(wp_(safe_)?remote_|curl_|socket_|ftp_|stream_socket_)/', $name ) || in_array( $name, array( 'fsockopen', 'pfsockopen', 'stream_socket_client', 'file_get_contents', 'file_put_contents', 'fopen', 'file', 'readfile', 'copy', 'get_headers', 'download_url', 'gethostbyname', 'gethostbynamel', 'dns_get_record' ), true ) ) {
				$found[] = $name;
			}
		}
		return $found;
	}
}
