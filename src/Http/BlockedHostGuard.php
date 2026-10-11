<?php
/**
 * Lazy manual host blocker with fail-open repository failures.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EgressEye\Contracts\DomainRepository;
use EgressEye\Contracts\ManualBlocker;

/**
 * Uses one manually blocked-host snapshot for this request-scoped instance.
 */
final class BlockedHostGuard implements ManualBlocker {

	/**
	 * Exact host normalizer.
	 *
	 * @var HostNormalizer
	 */
	private $normalizer;

	/**
	 * Block-state repository.
	 *
	 * @var DomainRepository
	 */
	private $repository;

	/**
	 * Whether this request has already attempted a read.
	 *
	 * @var bool
	 */
	private $loaded = false;

	/**
	 * Canonical blocked host lookup.
	 *
	 * @var array<string,true>
	 */
	private $blocked_hosts = array();

	/**
	 * Create the request-scoped guard without reading storage or adding hooks.
	 *
	 * @param HostNormalizer   $normalizer Exact host normalizer.
	 * @param DomainRepository $repository Manual block-state reader.
	 */
	public function __construct( HostNormalizer $normalizer, DomainRepository $repository ) {
		$this->normalizer = $normalizer;
		$this->repository = $repository;
	}

	/**
	 * Preserve preemption, or return an immediate error for an exact manual block.
	 *
	 * @param mixed               $preempt Existing preemptive response.
	 * @param array<string,mixed> $args    HTTP arguments.
	 * @param mixed               $url     Raw initial HTTP API destination.
	 * @return mixed
	 */
	public function filter( $preempt, array $args, $url ) {
		if ( ! is_string( $url ) || false !== $preempt ) {
			return $preempt;
		}

		$host = $this->normalizer->fromUrl( $url );

		if ( null === $host ) {
			return false;
		}

		$this->loadBlockedHosts();

		if ( ! isset( $this->blocked_hosts[ $host ] ) ) {
			return false;
		}

		return new \WP_Error(
			'egress_eye_host_blocked',
			function_exists( 'did_action' ) && ! did_action( 'after_setup_theme' ) ? 'This host is blocked by Egress Eye.' : __( 'This host is blocked by Egress Eye.', 'egress-eye' )
		);
	}

	/**
	 * Attempt a single read; any Throwable leaves an empty snapshot, no retry.
	 *
	 * @return void
	 */
	private function loadBlockedHosts(): void {
		if ( $this->loaded ) {
			return;
		}

		$this->loaded = true;

		try {
			$hosts = $this->repository->getBlockedHosts();

			$this->blocked_hosts = $this->buildHostLookup( $hosts );
		} catch ( \Throwable $exception ) {
			$this->blocked_hosts = array();
		}
	}

	/**
	 * Validate the storage boundary before publishing its complete snapshot.
	 *
	 * @param array<int,mixed> $hosts Untrusted repository snapshot entries.
	 * @return array<string,true>
	 * @throws \UnexpectedValueException When a snapshot entry is invalid.
	 */
	private function buildHostLookup( array $hosts ): array {
		$lookup = array();

		foreach ( $hosts as $host ) {
			if ( ! is_string( $host ) || '' === $host ) {
				throw new \UnexpectedValueException( 'Invalid blocked host snapshot.' );
			}

			$lookup[ $host ] = true;
		}

		return $lookup;
	}
}
