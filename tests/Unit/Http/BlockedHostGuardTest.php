<?php
/**
 * Manual host guard tests against a fake repository.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Unit\Http;

use EgressEye\Http\BlockedHostGuard;
use EgressEye\Http\HostNormalizer;
use EgressEye\Tests\Support\InMemoryDomainRepository;
use PHPUnit\Framework\TestCase;

/**
 * Checks exact manual decisions and a single lazy read per guard lifetime.
 */
final class BlockedHostGuardTest extends TestCase {

	/**
	 * Construction and earlier preemption must not fetch blocked hosts.
	 *
	 * @return void
	 */
	public function test_prior_preempt_is_preserved_without_loading_repository(): void {
		$repository = new InMemoryDomainRepository();
		$guard      = new BlockedHostGuard( new HostNormalizer(), $repository );
		$response   = new \WP_Error( 'other_plugin', 'Previous response.' );

		self::assertSame( 0, $repository->read_calls );
		self::assertSame( $response, $guard->filter( $response, array(), 'https://example.test/' ) );
		self::assertSame( 0, $repository->read_calls );
	}

	/**
	 * Invalid hosts fail open before any repository work.
	 *
	 * @return void
	 */
	public function test_invalid_host_does_not_load_repository(): void {
		$repository = new InMemoryDomainRepository();
		$guard      = new BlockedHostGuard( new HostNormalizer(), $repository );

		foreach ( array( '', 'not-a-url', 'http:///path', 'http://[not-ipv6]/' ) as $url ) {
			self::assertFalse( $guard->filter( false, array(), $url ) );
		}
		self::assertSame( 0, $repository->read_calls );
	}

	/**
	 * One lazy snapshot handles different hosts without a second read.
	 *
	 * @return void
	 */
	public function test_blocked_list_is_lazy_and_read_once_for_all_hosts(): void {
		$repository                = new InMemoryDomainRepository();
		$repository->blocked_hosts = array( 'blocked.test' );
		$guard                     = new BlockedHostGuard( new HostNormalizer(), $repository );

		self::assertSame( 0, $repository->read_calls );
		self::assertFalse( $guard->filter( false, array(), 'https://allowed.test/' ) );
		$blocked = $guard->filter( false, array(), 'https://blocked.test/' );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'egress_eye_host_blocked', $blocked->get_error_code() );
		self::assertFalse( $guard->filter( false, array(), 'https://api.blocked.test/' ) );
		self::assertSame( 1, $repository->read_calls );

		$repository->blocked_hosts = array();
		self::assertInstanceOf( \WP_Error::class, $guard->filter( false, array(), 'https://blocked.test/' ) );
		self::assertSame( 1, $repository->read_calls );
	}

	/**
	 * Both Exception and Error fail open and must never retry in this lifetime.
	 *
	 * @return void
	 */
	public function test_repository_and_cache_throwables_fail_open_without_retry(): void {
		foreach ( array( new \RuntimeException( 'Repository failure.' ), new \Error( 'Cache failure.' ) ) as $failure ) {
			$repository                = new InMemoryDomainRepository();
			$repository->blocked_hosts = array( 'blocked.test' );
			$repository->failure       = $failure;
			$guard                     = new BlockedHostGuard( new HostNormalizer(), $repository );

			self::assertFalse( $guard->filter( false, array(), 'https://blocked.test/' ) );
			$repository->failure = null;
			self::assertFalse( $guard->filter( false, array(), 'https://blocked.test/' ) );
			self::assertSame( 1, $repository->read_calls );
		}
	}

	/**
	 * Manual blocking includes non-blocking and loopback calls.
	 *
	 * @return void
	 */
	public function test_non_blocking_loopback_can_be_blocked_manually(): void {
		$repository                = new InMemoryDomainRepository();
		$repository->blocked_hosts = array( '127.0.0.1', '::1', 'example.com', 'localhost' );
		$guard                     = new BlockedHostGuard( new HostNormalizer(), $repository );

		self::assertInstanceOf( \WP_Error::class, $guard->filter( false, array( 'blocking' => false ), 'http://127.0.0.1/' ) );
		self::assertInstanceOf( \WP_Error::class, $guard->filter( false, array(), 'http://[::1]/' ) );
		self::assertInstanceOf( \WP_Error::class, $guard->filter( false, array(), 'https://example.com/wp-cron.php' ) );
		self::assertInstanceOf( \WP_Error::class, $guard->filter( false, array(), 'http://localhost/' ) );
		self::assertSame( 1, $repository->read_calls );
	}

	/**
	 * DNS names, subdomains and IP literals are separate manual keys.
	 *
	 * @return void
	 */
	public function test_exact_host_does_not_block_subdomain_or_ip_literal(): void {
		$repository                = new InMemoryDomainRepository();
		$repository->blocked_hosts = array( 'example.com' );
		$guard                     = new BlockedHostGuard( new HostNormalizer(), $repository );

		self::assertInstanceOf( \WP_Error::class, $guard->filter( false, array(), 'https://user:pass@EXAMPLE.COM.:8443/' ) );
		self::assertFalse( $guard->filter( false, array(), 'https://api.example.com/' ) );
		self::assertFalse( $guard->filter( false, array(), 'http://192.0.2.1/' ) );
		self::assertFalse( $guard->filter( false, array(), 'http://[2001:db8::1]/' ) );
		self::assertSame( 1, $repository->read_calls );
	}

	/**
	 * A new request-scoped instance loads a new snapshot.
	 *
	 * @return void
	 */
	public function test_new_guard_instance_reads_a_fresh_snapshot(): void {
		$repository                = new InMemoryDomainRepository();
		$repository->blocked_hosts = array( 'example.com' );
		$first                     = new BlockedHostGuard( new HostNormalizer(), $repository );

		self::assertInstanceOf( \WP_Error::class, $first->filter( false, array(), 'https://example.com/' ) );
		$repository->blocked_hosts = array();
		self::assertInstanceOf( \WP_Error::class, $first->filter( false, array(), 'https://example.com/' ) );

		$second = new BlockedHostGuard( new HostNormalizer(), $repository );
		self::assertSame( 1, $repository->read_calls );
		self::assertFalse( $second->filter( false, array(), 'https://example.com/' ) );
		self::assertSame( 2, $repository->read_calls );
	}
}
