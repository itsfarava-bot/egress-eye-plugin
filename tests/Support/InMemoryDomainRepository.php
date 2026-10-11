<?php
/**
 * In-memory repository boundary for Task 4; no SQL or object cache.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Support;

use EgressEye\Contracts\DomainRepository;

/**
 * Supplies controlled manual state and read failures.
 */
final class InMemoryDomainRepository implements DomainRepository {

	/**
	 * Manually blocked canonical hosts.
	 *
	 * @var array<int,string>
	 */
	public $blocked_hosts = array();

	/**
	 * Read attempts.
	 *
	 * @var int
	 */
	public $read_calls = 0;

	/**
	 * Optional repository/cache failure.
	 *
	 * @var \Throwable|null
	 */
	public $failure;

	/**
	 * Read the manually blocked host list.
	 *
	 * @return array<int,string>
	 * @throws \Throwable When a controlled failure is configured.
	 */
	public function getBlockedHosts(): array {
		++$this->read_calls;

		if ( null !== $this->failure ) {
			throw $this->failure;
		}

		return $this->blocked_hosts;
	}
}
