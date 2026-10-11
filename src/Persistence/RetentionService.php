<?php
/**
 * Write-path hard caps and bounded, lease-protected age cleanup.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Growth never depends on a dashboard visit or a successful cron spawn.
 */
final class RetentionService {

	/**
	 * Autoload last completed maintenance timestamp.
	 */
	public const LAST_OPTION = 'egress_eye_last_maintenance';
	/**
	 * Recoverable token and expiry lease option.
	 */
	public const LOCK_OPTION = 'egress_eye_retention_lock';
	/**
	 * Optional hourly fallback hook.
	 */
	public const CRON_HOOK = 'egress_eye_retention';
	/**
	 * Hard row limit for each table.
	 */
	public const CAP = 500;
	/**
	 * Non-autoload, write-once signal for a rejected new domain.
	 */
	public const CAPACITY_OPTION = 'egress_eye_domain_capacity_notice';
	/**
	 * Maximum records removed per small batch.
	 */
	public const BATCH = 50;

	/**
	 * Site database boundary.
	 *
	 * @var Database
	 */
	private $database;
	/**
	 * Whether this instance registered its due callback.
	 *
	 * @var bool
	 */
	private $scheduled = false;

	/**
	 * Construct without scheduling or performing any work.
	 *
	 * @param Database $database Site connection boundary.
	 */
	public function __construct( Database $database ) {
		$this->database = $database;
	}

	/**
	 * Make one insertion slot while the caller holds the table admission lock.
	 *
	 * @param string $table Logical table name.
	 * @return bool False when a new domain cannot be admitted safely.
	 */
	public function make_room( string $table ): bool {
		if ( ! $this->is_full( $table ) ) {
			return true;
		}
		if ( 'domains' === $table ) {
			$this->delete_stale_domains();
			$available = ! $this->is_full( $table );
			if ( ! $available ) {
				$this->database->signal_notice( self::CAPACITY_OPTION );
			}
			return $available;
		}
		$this->database->execute( 'DELETE FROM %i ORDER BY id ASC LIMIT %d', array( $this->database->table( $table ), self::BATCH ) );
		return true;
	}

	/**
	 * Only an actual successful write may register due shutdown maintenance.
	 *
	 * @return void
	 */
	public function after_write(): void {
		if ( $this->scheduled || time() - (int) $this->database->option( self::LAST_OPTION, 0 ) < 60 ) {
			return;
		}
		$this->scheduled = true;
		add_action( 'shutdown', array( $this, 'run_maintenance' ), 20, 0 );
	}

	/**
	 * Acquire or recover an expired lease and delete at most ten small batches/table.
	 *
	 * @return bool True only when this worker completed the bounded pass.
	 */
	public function run_maintenance(): bool {
		$token = '';
		try {
			if ( ! $this->database->outside_transaction() ) {
				return false;
			}
			$token = bin2hex( random_bytes( 16 ) ) . ':' . ( time() + 30 );
			$this->database->execute(
				'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value = IF(CAST(SUBSTRING_INDEX(option_value, %s, %d) AS UNSIGNED) < %d, %s, option_value)',
				array( $this->database->options_table(), self::LOCK_OPTION, $token, 'no', ':', -1, time(), $token )
			);
			$this->invalidate_lease_cache();
			$owner = $this->database->scalar( 'SELECT option_value FROM %i WHERE option_name = %s', array( $this->database->options_table(), self::LOCK_OPTION ) );
			if ( $token !== $owner ) {
				return false;
			}
			$days_by_table = array(
				'events'  => 30,
				'audits'  => 180,
				'domains' => 30,
			);
			foreach ( $days_by_table as $table => $days ) {
				$result = $this->database->with_lock(
					$table,
					function () use ( $table, $days ): bool {
						for ( $batch = 0; $batch < 10; ++$batch ) {
							$removed = 'domains' === $table
								? $this->delete_stale_domains()
								: $this->database->execute( 'DELETE FROM %i WHERE occurred_at < %s ORDER BY id ASC LIMIT %d', array( $this->database->table( $table ), gmdate( 'Y-m-d H:i:s', time() - $days * 86400 ), self::BATCH ) );
							if ( $removed < self::BATCH ) {
								break;
							}
						}
						return true;
					}
				);
				if ( true !== $result ) {
					return false;
				}
			}
			return $this->database->store_option( self::LAST_OPTION, time(), true );
		} catch ( \Throwable $exception ) {
			return false;
		} finally {
			if ( '' !== $token ) {
				try {
					$this->database->execute( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', array( $this->database->options_table(), self::LOCK_OPTION, $token ) );
					$this->invalidate_lease_cache();
				} catch ( \Throwable $exception ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The owned lease expires; a release error must not become an HTTP error.
					// An unreleased owned lease expires; another worker can recover it.
				}
			}
		}
	}

	/**
	 * Inspect the 500th row through the primary key, without COUNT(*).
	 *
	 * @phpstan-impure Depends on mutable database state before/after cleanup.
	 * @param string $table Logical table name.
	 * @return bool
	 */
	private function is_full( string $table ): bool {
		return null !== $this->database->scalar( 'SELECT id FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', array( $this->database->table( $table ), 1, self::CAP - 1 ) );
	}

	/**
	 * Delete only old free domains with no retained event; never a blocked domain.
	 *
	 * @return int Number removed in this batch.
	 */
	private function delete_stale_domains(): int {
		return $this->database->execute(
			'DELETE FROM %i WHERE is_blocked = %d AND COALESCE(last_seen_at, created_at) < %s AND NOT EXISTS (SELECT id FROM %i WHERE domain_id = %i.id) ORDER BY id ASC LIMIT %d',
			array( $this->database->table( 'domains' ), 0, gmdate( 'Y-m-d H:i:s', time() - 30 * 86400 ), $this->database->table( 'events' ), $this->database->table( 'domains' ), self::BATCH )
		);
	}

	/**
	 * Invalidate core option snapshots after a conditional direct lease mutation.
	 *
	 * @return void
	 */
	private function invalidate_lease_cache(): void {
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}
}
