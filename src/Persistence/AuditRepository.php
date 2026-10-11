<?php
/**
 * Bounded manual block/unblock audit history.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists host snapshots and actor ids; no user IP or request payload.
 */
final class AuditRepository implements \EgressEye\Contracts\AuditReader {

	/**
	 * Prepared database boundary.
	 *
	 * @var Database
	 */
	private $database;
	/**
	 * Capacity coordinator.
	 *
	 * @var RetentionService
	 */
	private $retention;

	/**
	 * Construct without touching storage.
	 *
	 * @param Database         $database  Database boundary.
	 * @param RetentionService $retention Retention coordinator.
	 */
	public function __construct( Database $database, RetentionService $retention ) {
		$this->database  = $database;
		$this->retention = $retention;
	}

	/**
	 * Return a bounded recent history of sanitized snapshots.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function recent(): array {
		return $this->database->rows( 'SELECT host, user_id, action, occurred_at FROM %i ORDER BY id DESC LIMIT %d', array( $this->database->table( 'audits' ), 100 ) );
	}

	/**
	 * Store an explicitly supplied manual action.
	 *
	 * @param string   $host       Host snapshot.
	 * @param int      $user_id    Actor id.
	 * @param string   $action     Block or unblock.
	 * @param bool     $previous   Previous block state.
	 * @param bool     $new_state        New block state.
	 * @param int|null $unix_time  UTC timestamp or current time.
	 * @return int|null Inserted audit id, or null on failure.
	 */
	public function record( string $host, int $user_id, string $action, bool $previous, bool $new_state, ?int $unix_time = null ): ?int {
		$host = DomainRepository::canonical_host( $host );
		if ( null === $host || ! in_array( $action, array( 'block', 'unblock' ), true ) ) {
			return null;
		}
		try {
			if ( ! $this->database->outside_transaction() ) {
				return null;
			}
			return $this->database->with_lock(
				'audits',
				function () use ( $host, $user_id, $action, $previous, $new_state, $unix_time ): ?int {
					if ( ! $this->retention->make_room( 'audits' ) ) {
						return null;
					}
					$this->database->execute(
						'INSERT INTO %i (host, user_id, action, previous_state, new_state, occurred_at) VALUES (%s, NULLIF(%d, 0), %s, %d, %d, %s)',
						array( $this->database->table( 'audits' ), $host, max( 0, $user_id ), $action, (int) $previous, (int) $new_state, gmdate( 'Y-m-d H:i:s', $unix_time ?? time() ) )
					);
					$id = $this->database->inserted_id();
					$this->retention->after_write();
					return $id > 0 ? $id : null;
				}
			);
		} catch ( \Throwable $exception ) {
			return null;
		}
	}
}
