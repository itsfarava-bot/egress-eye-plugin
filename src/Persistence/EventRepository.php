<?php
/**
 * Bounded storage for sanitized slow-request details.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EgressEye\Diagnostics\SlowRequest;
use EgressEye\Diagnostics\ComponentGuess;

/**
 * Serializes capacity admission, never the original HTTP transport.
 */
final class EventRepository implements \EgressEye\Contracts\DiagnosticEvents {

	/**
	 * Prepared database boundary.
	 *
	 * @var Database
	 */
	private $database;
	/**
	 * Write-path admission and maintenance.
	 *
	 * @var RetentionService
	 */
	private $retention;

	/**
	 * Construct without accessing storage.
	 *
	 * @param Database         $database  Database boundary.
	 * @param RetentionService $retention Retention coordinator.
	 */
	public function __construct( Database $database, RetentionService $retention ) {
		$this->database  = $database;
		$this->retention = $retention;
	}

	/**
	 * Insert one sanitized slow sample; contain storage failure.
	 *
	 * @param SlowRequest $sample    Host-only sample.
	 * @param int         $domain_id Existing domain id.
	 * @return int|null Inserted event id, or null if admission/storage failed.
	 */
	public function insert( SlowRequest $sample, int $domain_id ): ?int {
		if ( $domain_id < 1 || $sample->duration_us < 1000000 ) {
			return null;
		}
		try {
			if ( ! $this->database->outside_transaction() ) {
				return null;
			}
			return $this->database->with_lock(
				'events',
				function () use ( $sample, $domain_id ): ?int {
					if ( ! $this->retention->make_room( 'events' ) ) {
						return null;
					}
					$this->database->execute(
						'INSERT INTO %i (domain_id, duration_us, occurred_at, response_code, error_code, component_kind, component_key, sample_key, is_loopback) VALUES (%d, %d, %s, NULLIF(%d, 0), NULLIF(%s, %s), NULLIF(%s, %s), NULLIF(%s, %s), NULLIF(%s, %s), %d)',
						array( $this->database->table( 'events' ), $domain_id, $sample->duration_us, $sample->occurred_at, $sample->response_code ?? 0, $sample->error_code ?? '', '', $sample->component_kind ?? '', '', $sample->component_key ?? '', '', $sample->sample_key() ?? '', '', (int) $sample->is_loopback )
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

	/**
	 * Attach a bounded guess after persistence without repeating aggregate writes.
	 *
	 * Exact sample identity keeps its own attribution without overwriting a newer
	 * domain sample. A failed statement leaves the saved unknown result intact.
	 *
	 * @param int            $event_id Persisted event identifier.
	 * @param ComponentGuess $guess    Probable component metadata.
	 * @return bool Whether the attribution statement succeeded.
	 */
	public function attribute( int $event_id, ComponentGuess $guess ): bool {
		if ( $event_id < 1 ) {
			return false;
		}
		$safe = new ComponentGuess( $guess->kind, $guess->key );
		try {
			$this->database->execute(
				'UPDATE %i AS saved_event JOIN %i AS saved_domain ON saved_domain.id = saved_event.domain_id SET saved_event.component_kind = %s, saved_event.component_key = NULLIF(%s, %s), saved_domain.last_component_kind = IF(saved_event.sample_key IS NOT NULL AND saved_domain.last_sample_key = saved_event.sample_key, %s, saved_domain.last_component_kind), saved_domain.last_component_key = IF(saved_event.sample_key IS NOT NULL AND saved_domain.last_sample_key = saved_event.sample_key, NULLIF(%s, %s), saved_domain.last_component_key) WHERE saved_event.id = %d',
				array( $this->database->table( 'events' ), $this->database->table( 'domains' ), $safe->kind, $safe->key ?? '', '', $safe->kind, $safe->key ?? '', '', $event_id )
			);
			return true;
		} catch ( \Throwable $exception ) {
			return false;
		}
	}
}
