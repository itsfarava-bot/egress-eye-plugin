<?php
/**
 * Canonical manual domains and atomic slow-request aggregates.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EgressEye\Contracts\DiagnosticDomains;
use EgressEye\Support\Notifications;
use EgressEye\Diagnostics\SlowRequest;
use EgressEye\Http\HostNormalizer;

/**
 * The domains table is the sole source of truth for explicit block state.
 */
final class DomainRepository implements DiagnosticDomains {

	/**
	 * Prepared connection boundary.
	 *
	 * @var Database
	 */
	private $database;
	/**
	 * Bounded admission and cleanup.
	 *
	 * @var RetentionService
	 */
	private $retention;

	/**
	 * Construct without queries or loading the block list.
	 *
	 * @param Database         $database  Database boundary.
	 * @param RetentionService $retention Retention coordinator.
	 */
	public function __construct( Database $database, RetentionService $retention ) {
		$this->database  = $database;
		$this->retention = $retention;
	}

	/**
	 * Validate a host-only key without accepting a URL, userinfo or a port.
	 *
	 * @param string $host Host key.
	 * @return string|null
	 */
	public static function canonical_host( string $host ): ?string {
		if ( strlen( $host ) > 191 || '' === $host || preg_match( '/[\/@?#]/', $host ) ) {
			return null;
		}
		$url_host = false !== strpos( $host, ':' ) && '[' !== substr( $host, 0, 1 ) ? '[' . $host . ']' : $host;
		return ( new HostNormalizer() )->fromUrl( 'https://' . $url_host . '/' );
	}

	/**
	 * Read/cache the canonical user-blocked snapshot; failures propagate to guard.
	 *
	 * @return array<int,string>
	 */
	public function getBlockedHosts(): array {
		// Validate the domains table even on a cache hit; cached state cannot bypass fail-open.
		$generation = (string) $this->database->scalar( 'SELECT option_value FROM %i WHERE option_name = %s AND EXISTS (SELECT id FROM %i LIMIT %d)', array( $this->database->options_table(), 'egress_eye_cache_version', $this->database->table( 'domains' ), 1 ) );
		$key        = $this->cache_key() . ':' . $generation;
		$cached     = wp_cache_get( $key, 'egress-eye' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$hosts = array();
		foreach ( $this->database->rows( 'SELECT host FROM %i WHERE is_blocked = 1 AND CHAR_LENGTH(host) <= %d', array( $this->database->table( 'domains' ), 191 ) ) as $row ) {
			$host = self::canonical_host( (string) $row['host'] );
			if ( null !== $host ) {
				$hosts[] = $host;
			}
		}
		wp_cache_set( $key, $hosts, 'egress-eye', 60 );
		return $hosts;
	}

	/**
	 * Admit a new host only while a domain capacity slot is available.
	 *
	 * @param string $host Host only.
	 * @return int|null Domain id, or null on invalid input/admission/storage failure.
	 */
	public function ensure_host( string $host ): ?int {
		$host = self::canonical_host( $host );
		if ( null === $host ) {
			return null;
		}
		try {
			if ( ! $this->database->outside_transaction() ) {
				return null;
			}
			$id = $this->find_id( $host );
			if ( null !== $id ) {
				return $id;
			}
			return $this->database->with_lock(
				'domains',
				function () use ( $host ): ?int {
					$id = $this->find_id( $host );
					if ( null !== $id ) {
						return $id;
					}
					if ( ! $this->retention->make_room( 'domains' ) ) {
						return null;
					}
					$now = gmdate( 'Y-m-d H:i:s' );
					$this->database->execute( 'INSERT INTO %i (host, created_at, updated_at) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)', array( $this->database->table( 'domains' ), $host, $now, $now ) );
					$id = $this->database->inserted_id();
					$this->retention->after_write();
					return $id > 0 ? $id : null;
				}
			);
		} catch ( \Throwable $exception ) {
			return null;
		}
	}

	/**
	 * Increment count/sum in one prepared upsert, never PHP read-then-write totals.
	 *
	 * @param SlowRequest $sample Sanitized slow sample.
	 * @return int|null Actual inserted/existing domain id.
	 */
	public function upsert_slow_aggregate( SlowRequest $sample ): ?int {
		$host = self::canonical_host( $sample->host );
		if ( null === $host || $sample->duration_us < 1000000 ) {
			return null;
		}
		try {
			if ( ! $this->database->outside_transaction() ) {
				return null;
			}
			$id = $this->find_id( $host );
			if ( null !== $id ) {
				return $this->upsert( $sample, $host, $id );
			}
			return $this->database->with_lock(
				'domains',
				function () use ( $sample, $host ): ?int {
					$id = $this->find_id( $host );
					if ( null === $id && ! $this->retention->make_room( 'domains' ) ) {
						return null;
					}
					return $this->upsert( $sample, $host, $id );
				}
			);
		} catch ( \Throwable $exception ) {
			return null;
		}
	}

	/**
	 * Mutate explicit block state together with its audit in a real transaction.
	 *
	 * @param string $host    Host only.
	 * @param bool   $blocked Explicit desired state.
	 * @param int    $user_id Actor id.
	 * @return bool False means the mutation was not committed.
	 */
	public function set_blocked( string $host, bool $blocked, int $user_id ): bool {
		$host = self::canonical_host( $host );
		if ( null === $host ) {
			return false;
		}
		try {
			if ( ! $this->database->outside_transaction() ) {
				return false;
			}
			foreach ( array( 'domains', 'audits' ) as $table ) {
				$engine = $this->database->scalar( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', array( $this->database->table( $table ) ) );
				if ( 'innodb' !== strtolower( (string) $engine ) ) {
					return false;
				}
			}
			$changed  = false;
			$previous = false;
			$result   = $this->database->with_lock(
				'domains',
				function () use ( $host, $blocked, $user_id, &$changed, &$previous ): ?bool {
					return $this->database->with_lock(
						'audits',
						function () use ( $host, $blocked, $user_id, &$changed, &$previous ): bool {
							$this->database->execute( 'START TRANSACTION' );
							try {
								$id = $this->find_id( $host );
								if ( null === $id ) {
									if ( ! $this->retention->make_room( 'domains' ) ) {
										throw new \RuntimeException( 'Diagnostic domain capacity is exhausted.' );
									}
									$now = gmdate( 'Y-m-d H:i:s' );
									$this->database->execute( 'INSERT INTO %i (host, created_at, updated_at) VALUES (%s, %s, %s)', array( $this->database->table( 'domains' ), $host, $now, $now ) );
									$id = $this->database->inserted_id();
								}
								$previous = (bool) $this->database->scalar( 'SELECT is_blocked FROM %i WHERE id = %d FOR UPDATE', array( $this->database->table( 'domains' ), $id ) );
								if ( $previous === $blocked ) {
									$this->database->execute( 'COMMIT' );
									return true;
								}
								if ( ! $this->retention->make_room( 'audits' ) ) {
									throw new \RuntimeException( 'Diagnostic audit admission failed.' );
								}
								$this->database->execute( 'UPDATE %i SET is_blocked = %d, updated_at = %s WHERE id = %d', array( $this->database->table( 'domains' ), (int) $blocked, gmdate( 'Y-m-d H:i:s' ), $id ) );
								$this->database->execute( 'INSERT INTO %i (host, user_id, action, previous_state, new_state, occurred_at) VALUES (%s, NULLIF(%d, 0), %s, %d, %d, %s)', array( $this->database->table( 'audits' ), $host, max( 0, $user_id ), $blocked ? 'block' : 'unblock', (int) $previous, (int) $blocked, gmdate( 'Y-m-d H:i:s' ) ) );
								$this->database->execute( 'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value = %s', array( $this->database->options_table(), 'egress_eye_cache_version', bin2hex( random_bytes( 16 ) ), 'no', bin2hex( random_bytes( 16 ) ) ) );
								$this->database->execute( 'COMMIT' );
								$changed = true;
							} catch ( \Throwable $exception ) {
								$this->database->execute( 'ROLLBACK' );
								return false;
							}
							try {
								wp_cache_delete( $this->cache_key(), 'egress-eye' );
								$this->retention->after_write();
							} catch ( \Throwable $exception ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Commit is authoritative; generation makes stale snapshots unreachable.
								// Post-commit work must not turn an already committed action into failure.
							}
							return true;
						}
					);
				}
			);
			if ( true === $result && $changed ) {
				Notifications::block_changed( $host, $user_id, $previous, $blocked );
			}
			return true === $result;
		} catch ( \Throwable $exception ) {
			return false;
		}
	}

	/**
	 * Resolve a stored id without trusting posted host fields.
	 *
	 * @param int $id Domain identifier.
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		return $id > 0 ? $this->database->row( 'SELECT id, host, is_blocked FROM %i WHERE id = %d', array( $this->database->table( 'domains' ), $id ) ) : null;
	}

	/**
	 * Read bounded admin rows without exposing internal sample identities.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array {
		return $this->database->rows( 'SELECT id, host, is_blocked, slow_count, slow_duration_sum_us, last_duration_us, last_seen_at, last_component_kind, last_component_key, is_loopback FROM %i ORDER BY is_blocked DESC, last_duration_us DESC, id ASC LIMIT %d', array( $this->database->table( 'domains' ), 500 ) );
	}

	/**
	 * Locate identity only; aggregate values are never read for arithmetic.
	 *
	 * @param string $host Canonical host.
	 * @return int|null
	 */
	private function find_id( string $host ): ?int {
		$id = $this->database->scalar( 'SELECT id FROM %i WHERE host = %s', array( $this->database->table( 'domains' ), $host ) );
		return null !== $id ? (int) $id : null;
	}

	/**
	 * Keep insertion and duplicate increments in the same prepared SQL statement.
	 *
	 * @param SlowRequest $sample      Sample data.
	 * @param string      $host        Canonical host.
	 * @param int|null    $existing_id Existing identity, or a locked new-host slot.
	 * @return int|null
	 */
	private function upsert( SlowRequest $sample, string $host, ?int $existing_id ): ?int {
		$sample->begin_recording();
		$columns = '(host, slow_count, slow_duration_sum_us, last_duration_us, last_seen_at, last_component_kind, last_component_key, last_sample_key, is_loopback, created_at, updated_at)';
		$values  = '%s, %d, %d, %d, %s, NULLIF(%s, %s), NULLIF(%s, %s), %s, %d, %s, %s';
		$now     = gmdate( 'Y-m-d H:i:s' );
		$args    = array( $this->database->table( 'domains' ), $host, 1, $sample->duration_us, $sample->duration_us, $sample->occurred_at, $sample->component_kind ?? '', '', $sample->component_key ?? '', '', $sample->sample_key(), (int) $sample->is_loopback, $now, $now );
		$sql     = 'INSERT INTO %i ' . $columns;
		if ( null === $existing_id ) {
			$sql .= ' VALUES (' . $values . ')';
		} else {
			// Reuse the existing primary key instead of allocating a discarded bulk-insert id.
			$sql    = 'INSERT INTO %i (id, ' . substr( $columns, 1 );
			$sql   .= ' SELECT existing_domain.id, ' . $values . ' FROM %i AS existing_domain WHERE existing_domain.id = %d FOR UPDATE';
			$args[] = $this->database->table( 'domains' );
			$args[] = $existing_id;
		}
		$sql     .= ' ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(%i.id), slow_count = %i.slow_count + %d, slow_duration_sum_us = %i.slow_duration_sum_us + %d, last_duration_us = %d, last_seen_at = %s, last_component_kind = NULLIF(%s, %s), last_component_key = NULLIF(%s, %s), last_sample_key = %s, is_loopback = %d, updated_at = %s';
		$args     = array_merge( $args, array( $this->database->table( 'domains' ), $this->database->table( 'domains' ), 1, $this->database->table( 'domains' ), $sample->duration_us, $sample->duration_us, $sample->occurred_at, $sample->component_kind ?? '', '', $sample->component_key ?? '', '', $sample->sample_key(), (int) $sample->is_loopback, $now ) );
		$affected = $this->database->execute( $sql, $args );
		$id       = $this->database->inserted_id();
		if ( $affected < 1 || $id < 1 ) {
			return null;
		}
		$this->retention->after_write();
		return $id;
	}

	/**
	 * Scope block snapshots to the site's actual table prefix.
	 *
	 * @return string
	 */
	private function cache_key(): string {
		return substr( $this->database->table( 'domains' ), 0, -strlen( 'http_diag_domains' ) ) . 'blocked_hosts';
	}
}
