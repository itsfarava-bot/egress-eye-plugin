<?php
/**
 * Read-only manual block-state repository contract.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supplies a snapshot of canonical hosts explicitly blocked by the user.
 */
interface DomainRepository {

	/**
	 * Return canonical manually blocked hosts or throw on storage/cache failure.
	 *
	 * @return array<int,string>
	 */
	public function getBlockedHosts(): array;
}
