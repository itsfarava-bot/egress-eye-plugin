<?php
/**
 * Versioned single-site diagnostic schema.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs dbDelta only on explicit installation or a schema-version mismatch.
 */
final class SchemaManager {

	/**
	 * Installed schema version.
	 */
	public const VERSION = 2;
	/**
	 * Autoload schema version option.
	 */
	public const VERSION_OPTION = 'egress_eye_schema_version';

	/**
	 * Site-scoped database boundary.
	 *
	 * @var Database
	 */
	private $database;

	/**
	 * Construct without touching the database.
	 *
	 * @param Database $database Database boundary.
	 */
	public function __construct( Database $database ) {
		$this->database = $database;
	}

	/**
	 * Return the portable, unqualified dbDelta templates.
	 *
	 * @return array<string,string>
	 */
	public static function definition_templates(): array {
		return array(
			'domains' => 'CREATE TABLE %i (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
host varchar(191) NOT NULL,
is_blocked tinyint(1) NOT NULL DEFAULT 0,
slow_count bigint(20) unsigned NOT NULL DEFAULT 0,
slow_duration_sum_us bigint(20) unsigned NOT NULL DEFAULT 0,
last_duration_us bigint(20) unsigned DEFAULT NULL,
last_seen_at datetime DEFAULT NULL,
last_component_kind varchar(20) DEFAULT NULL,
last_component_key varchar(191) DEFAULT NULL,
last_sample_key varchar(32) DEFAULT NULL,
is_loopback tinyint(1) NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY host (host),
KEY blocked_host (is_blocked,host),
KEY last_seen_at (last_seen_at)
)',
			'events'  => 'CREATE TABLE %i (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
domain_id bigint(20) unsigned NOT NULL,
duration_us bigint(20) unsigned NOT NULL,
occurred_at datetime NOT NULL,
response_code smallint(5) unsigned DEFAULT NULL,
error_code varchar(100) DEFAULT NULL,
component_kind varchar(20) DEFAULT NULL,
component_key varchar(191) DEFAULT NULL,
sample_key varchar(32) DEFAULT NULL,
is_loopback tinyint(1) NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
KEY domain_time (domain_id,occurred_at),
KEY occurred_at (occurred_at)
)',
			'audits'  => 'CREATE TABLE %i (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
host varchar(191) NOT NULL,
user_id bigint(20) unsigned DEFAULT NULL,
action varchar(20) NOT NULL,
previous_state tinyint(1) NOT NULL,
new_state tinyint(1) NOT NULL,
occurred_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY occurred_at (occurred_at)
)',
		);
	}

	/**
	 * List every persistent option owned by this version.
	 *
	 * @return array<int,string>
	 */
	public static function option_names(): array {
		return array( self::VERSION_OPTION, RetentionService::LAST_OPTION, RetentionService::LOCK_OPTION, RetentionService::CAPACITY_OPTION, 'egress_eye_cache_version' );
	}

	/**
	 * Cheap autoload option comparison; no DDL when already current.
	 *
	 * @return bool
	 */
	public function ensure_current(): bool {
		if ( is_multisite() ) {
			return false;
		}
		return self::VERSION === (int) $this->database->option( self::VERSION_OPTION, 0 ) || $this->install();
	}

	/**
	 * Install and verify the actual three tables, containing all SQL failures.
	 *
	 * @return bool
	 */
	public function install(): bool {
		if ( is_multisite() ) {
			return false;
		}
		try {
			if ( ! $this->database->outside_transaction() ) {
				return false;
			}
			$definitions = array();
			foreach ( self::definition_templates() as $table => $template ) {
				$definitions[] = $this->database->schema_sql( $template, $table );
			}
			$this->database->migrate( $definitions );
			foreach ( array_keys( self::definition_templates() ) as $table ) {
				$this->database->scalar( 'SELECT id FROM %i LIMIT %d', array( $this->database->table( $table ), 1 ) );
			}
			$this->database->scalar( 'SELECT last_sample_key FROM %i LIMIT %d', array( $this->database->table( 'domains' ), 1 ) );
			$this->database->scalar( 'SELECT sample_key FROM %i LIMIT %d', array( $this->database->table( 'events' ), 1 ) );
			return $this->database->store_option( self::VERSION_OPTION, self::VERSION, true );
		} catch ( \Throwable $exception ) {
			return false;
		}
	}

	/**
	 * Reject unsupported network activation with a translated English message.
	 *
	 * @param bool $network_wide Whether network activation was requested.
	 * @return void
	 */
	public function activate( bool $network_wide = false ): void {
		if ( $network_wide || is_multisite() ) {
			wp_die( esc_html__( 'Egress Eye supports single-site activation only.', 'egress-eye' ) );
			return;
		}
		if ( ! $this->install() ) {
			wp_die( esc_html__( 'Egress Eye could not initialize its diagnostic storage.', 'egress-eye' ) );
		}
	}
}
