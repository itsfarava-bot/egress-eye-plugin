<?php
/**
 * Loopback indication tests; no automatic protection or blocking.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Unit\Http;

use EgressEye\Http\HostNormalizer;
use EgressEye\Http\LoopbackDetector;
use PHPUnit\Framework\TestCase;

/**
 * Detects only the specified site/local host forms.
 */
final class LoopbackDetectorTest extends TestCase {

	/**
	 * Local indication must not extend to unrelated subdomains.
	 *
	 * @dataProvider loopback_cases
	 * @param string $host     Canonical or literal host.
	 * @param bool   $expected Whether the host is local.
	 * @return void
	 */
	public function test_loopback_indication( string $host, bool $expected ): void {
		$detector = new LoopbackDetector( new HostNormalizer(), 'https://example.test:8443/' );
		self::assertSame( $expected, $detector->isLoopback( $host ) );
	}

	/**
	 * Independent expected values.
	 *
	 * @return array<string,array{string,bool}>
	 */
	public function loopback_cases(): array {
		return array(
			'home'          => array( 'example.test', true ),
			'localhost'     => array( 'localhost', true ),
			'IPv4 first'    => array( '127.0.0.0', true ),
			'IPv4 last'     => array( '127.255.255.255', true ),
			'IPv6 loopback' => array( '::1', true ),
			'expanded IPv6' => array( '0:0:0:0:0:0:0:1', true ),
			'bracket IPv6'  => array( '[::1]', true ),
			'subdomain'     => array( 'api.example.test', false ),
			'external'      => array( 'external.test', false ),
			'IPv4 outside'  => array( '128.0.0.1', false ),
			'IPv6 external' => array( '2001:db8::1', false ),
			'empty'         => array( '', false ),
		);
	}

	/**
	 * A self cron URL is labeled local regardless of its path.
	 *
	 * @return void
	 */
	public function test_self_cron_host_is_loopback(): void {
		$normalizer = new HostNormalizer();
		$detector   = new LoopbackDetector( $normalizer, 'https://example.test/' );
		self::assertTrue( $detector->isLoopback( $normalizer->fromUrl( 'https://example.test/wp-cron.php?doing_wp_cron=1' ) ) );
	}
}
