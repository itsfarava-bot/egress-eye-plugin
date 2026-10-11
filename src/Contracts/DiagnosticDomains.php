<?php
/**
 * Domain persistence port for sanitized diagnostics and admin state.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EgressEye\Diagnostics\SlowRequest;

/**
 * Keeps HTTP read snapshots separate from explicit administrator mutations.
 */
interface DiagnosticDomains extends DomainRepository {
	/**
	 * Persist a slow aggregate.
	 *
	 * @param SlowRequest $sample Sanitized sample.
	 * @return int|null
	 */
	public function upsert_slow_aggregate( SlowRequest $sample ): ?int;

	/**
	 * Commit an audited desired state, with unchanged state considered success.
	 *
	 * @param string $host    Host only.
	 * @param bool   $blocked Desired state.
	 * @param int    $user_id Actor identifier.
	 * @return bool
	 */
	public function set_blocked( string $host, bool $blocked, int $user_id ): bool;

	/**
	 * Resolve a stored identity.
	 *
	 * @param int $id Stored identifier.
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array;

	/**
	 * Read at most the domain admission cap for the admin page.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array;
}
