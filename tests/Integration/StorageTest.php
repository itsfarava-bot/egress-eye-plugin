<?php
/**
 * Real database storage, privacy, retention and lifecycle tests.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Integration;

use EgressEye\Diagnostics\SlowRequest;
use EgressEye\Diagnostics\SlowRequestRecorderService;
use EgressEye\Http\BlockedHostGuard;
use EgressEye\Http\HookRegistrar;
use EgressEye\Http\HostNormalizer;
use EgressEye\Http\HttpObserver;
use EgressEye\Http\InMemoryContextStore;
use EgressEye\Http\MonotonicClock;
use EgressEye\Http\RequestContext;
use EgressEye\Persistence\AuditRepository;
use EgressEye\Persistence\Database;
use EgressEye\Persistence\DomainRepository;
use EgressEye\Persistence\EventRepository;
use EgressEye\Persistence\RetentionService;
use EgressEye\Persistence\SchemaManager;
use EgressEye\Persistence\StorageRuntime;
use EgressEye\Tests\Support\LocalHttpServer;
use EgressEye\Tests\Support\SequenceClock;

/**
 * Exercises installed tables; no SQLite or in-memory repository substitutes.
 */
final class StorageTest extends \WP_UnitTestCase {

	/**
	 * Test service.
	 *
	 * @var Database
	 */
	private $database;
	/**
	 * Test service.
	 *
	 * @var RetentionService
	 */
	private $retention;
	/**
	 * Test service.
	 *
	 * @var DomainRepository
	 */
	private $domains;
	/**
	 * Test service.
	 *
	 * @var EventRepository
	 */
	private $events;
	/**
	 * Test service.
	 *
	 * @var AuditRepository
	 */
	private $audits;
	/**
	 * Test service.
	 *
	 * @var SchemaManager
	 */
	private $schema;
	/**
	 * Test service.
	 *
	 * @var SlowRequestRecorderService
	 */
	private $recorder;

	/**
	 * Install a fresh isolated plugin schema in the WordPress test database.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		// Storage acceptance requires persistent tables, including visibility to separate connections.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		global $wpdb;
		$this->database = new Database( $wpdb );
		$this->database->execute( 'COMMIT' );
		$this->database->execute( 'SET autocommit = %d', array( 1 ) );

		foreach ( array( 'events', 'audits', 'domains' ) as $table ) {
			$this->database->execute( 'DROP TABLE IF EXISTS %i', array( $this->database->table( $table ) ) );
		}
		foreach ( SchemaManager::option_names() as $option ) {
			delete_option( $option );
		}
		wp_cache_delete( $wpdb->prefix . 'blocked_hosts', 'egress-eye' );
		$this->retention = new RetentionService( $this->database );
		$this->domains   = new DomainRepository( $this->database, $this->retention );
		$this->events    = new EventRepository( $this->database, $this->retention );
		$this->audits    = new AuditRepository( $this->database, $this->retention );
		$this->schema    = new SchemaManager( $this->database );
		$this->recorder  = new SlowRequestRecorderService( $this->domains, $this->events );
		self::assertTrue( $this->schema->install() );
	}

	/**
	 * Remove this test's maintenance callback and state.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_action( 'shutdown', array( $this->retention, 'run_maintenance' ), 20 );
		wp_clear_scheduled_hook( RetentionService::CRON_HOOK );
		parent::tear_down();
	}

	/**
	 * Repeated installation is schema-idempotent and current checks issue no DDL.
	 *
	 * @return void
	 */
	public function test_schema_install_is_idempotent_and_version_checks_are_cheap(): void {
		$before = array();
		foreach ( array( 'domains', 'events', 'audits' ) as $table ) {
			$before[ $table ] = $this->database->row( 'SHOW CREATE TABLE %i', array( $this->database->table( $table ) ) );
		}
		self::assertTrue( $this->schema->install() );
		foreach ( $before as $table => $definition ) {
			self::assertSame( $definition, $this->database->row( 'SHOW CREATE TABLE %i', array( $this->database->table( $table ) ) ) );
		}
		self::assertSame( SchemaManager::VERSION, (int) get_option( SchemaManager::VERSION_OPTION ) );
		$queries = array();
		$spy     = static function ( $sql ) use ( &$queries ) {
			$queries[] = $sql;
			return $sql;
		};
		add_filter( 'query', $spy );
		try {
			self::assertTrue( $this->schema->ensure_current() );
		} finally {
			remove_filter( 'query', $spy );
		}
		foreach ( $queries as $sql ) {
			self::assertDoesNotMatchRegularExpression( '/^\s*(CREATE|ALTER|DROP)\b/i', $sql );
		}
	}

	/**
	 * Insert and duplicate-update return the same actual id and increment totals.
	 *
	 * @return void
	 */
	public function test_atomic_upsert_returns_inserted_and_existing_domain_id(): void {
		$sample = new SlowRequest( 'example.test', 2000000, 1710000000, 200, null, 'plugin', 'fixture-plugin', false );
		$id     = $this->domains->upsert_slow_aggregate( $sample );
		self::assertGreaterThan( 0, $id );
		global $wpdb;
		self::assertSame( $id, $this->domains->upsert_slow_aggregate( $sample ), $wpdb->last_error );
		$row = $this->database->row( 'SELECT * FROM %i WHERE id = %d', array( $this->database->table( 'domains' ), $id ) );
		self::assertSame( 2, (int) $row['slow_count'] );
		self::assertSame( 4000000, (int) $row['slow_duration_sum_us'] );
		self::assertSame( 2000000, intdiv( (int) $row['slow_duration_sum_us'], (int) $row['slow_count'] ) );
		self::assertSame( 'plugin', $row['last_component_kind'] );
		self::assertSame( 'fixture-plugin', $row['last_component_key'] );
	}

	/**
	 * 5000 actual event/audit writes never need a dashboard visit to stay bounded.
	 *
	 * @return void
	 */
	public function test_5000_writes_without_dashboard_keep_all_table_caps(): void {
		self::assertTrue( $this->domains->set_blocked( 'blocked.example', true, 1 ) );
		for ( $i = 0; $i < 600; ++$i ) {
			$this->domains->ensure_host( 'd' . $i . '.example' );
		}
		self::assertSame( 500, $this->count_rows( 'domains' ) );
		for ( $i = 0; $i < 5000; ++$i ) {
			self::assertTrue( $this->recorder->record_request( new SlowRequest( 'd0.example', 2000000, time() ) ) );
			self::assertGreaterThan( 0, $this->audits->record( 'd0.example', 1, 'block', false, true ) );
			if ( 0 === $i % 500 ) {
				foreach ( array( 'domains', 'events', 'audits' ) as $table ) {
					self::assertLessThanOrEqual( 500, $this->count_rows( $table ) );
				}
			}
		}
		foreach ( array( 'domains', 'events', 'audits' ) as $table ) {
			self::assertLessThanOrEqual( 500, $this->count_rows( $table ) );
		}
		self::assertContains( 'blocked.example', $this->domains->getBlockedHosts() );
	}

	/**
	 * Maintenance recovers expired locks, respects live leases and age policies.
	 *
	 * @return void
	 */
	public function test_stale_lock_recovery_age_cleanup_and_blocked_domain_preservation(): void {
		$old = time() - 200 * DAY_IN_SECONDS;
		self::assertTrue( $this->domains->set_blocked( 'blocked.example', true, 1 ) );
		$this->domains->ensure_host( 'unused.example' );
		self::assertTrue( $this->recorder->record_request( new SlowRequest( 'expired.example', 2000000, $old ) ) );
		$this->audits->record( 'expired.example', 1, 'block', false, true, $old );
		$this->database->execute( 'UPDATE %i SET created_at = %s, updated_at = %s, last_seen_at = %s', array( $this->database->table( 'domains' ), gmdate( 'Y-m-d H:i:s', $old ), gmdate( 'Y-m-d H:i:s', $old ), gmdate( 'Y-m-d H:i:s', $old ) ) );
		update_option( RetentionService::LOCK_OPTION, 'live:' . ( time() + 30 ), false );
		self::assertFalse( $this->retention->run_maintenance() );
		self::assertSame( 1, $this->count_rows( 'events' ) );
		update_option( RetentionService::LOCK_OPTION, 'stale:' . ( time() - 1 ), false );
		self::assertTrue( $this->retention->run_maintenance() );
		self::assertSame( 0, $this->count_rows( 'events' ) );
		self::assertSame( 1, $this->count_rows( 'domains' ) );
		self::assertContains( 'blocked.example', $this->domains->getBlockedHosts() );
		self::assertGreaterThan( 0, (int) get_option( RetentionService::LAST_OPTION ) );
		self::assertFalse( get_option( RetentionService::LOCK_OPTION, false ) );
		self::assertSame( 1, $this->count_rows( 'audits' ) );
	}

	/**
	 * Only a successful due write schedules shutdown; constructors and fast calls do not.
	 *
	 * @return void
	 */
	public function test_due_shutdown_is_registered_only_after_actual_successful_write(): void {
		self::assertFalse( has_action( 'shutdown', array( $this->retention, 'run_maintenance' ) ) );
		update_option( RetentionService::LAST_OPTION, time(), true );
		$this->domains->ensure_host( 'not-due.example' );
		self::assertFalse( has_action( 'shutdown', array( $this->retention, 'run_maintenance' ) ) );
		update_option( RetentionService::LAST_OPTION, time() - 61, true );
		$this->domains->ensure_host( 'due.example' );
		self::assertSame( 20, has_action( 'shutdown', array( $this->retention, 'run_maintenance' ) ) );
	}

	/**
	 * Long hosts never create records or manual blocked states.
	 *
	 * @return void
	 */
	public function test_long_host_has_no_storage_or_block_state(): void {
		$host = str_repeat( 'a', 63 ) . '.' . str_repeat( 'b', 63 ) . '.' . str_repeat( 'c', 63 ) . '.dddddddd';
		self::assertNull( $this->domains->upsert_slow_aggregate( new SlowRequest( $host, 2000000, time() ) ) );
		self::assertFalse( $this->domains->set_blocked( $host, true, 1 ) );
		self::assertFalse( $this->recorder->record_request( new SlowRequest( $host, 2000000, time() ) ) );
		self::assertSame( 0, $this->count_rows( 'domains' ) );
		self::assertSame( 0, $this->count_rows( 'events' ) );
		self::assertSame( 0, $this->count_rows( 'audits' ) );
	}

	/**
	 * Missing tables fail open with no printed SQL and original HTTP response preserved.
	 *
	 * @return void
	 */
	public function test_missing_table_fails_open_without_output_or_response_changes(): void {
		global $wpdb;
		self::assertTrue( $this->domains->set_blocked( '127.0.0.1', true, 1 ) );
		self::assertContains( '127.0.0.1', $this->domains->getBlockedHosts() );
		$this->database->execute( 'DROP TABLE %i', array( $this->database->table( 'domains' ) ) );
		$show      = $wpdb->show_errors( true );
		$suppress  = $wpdb->suppress_errors( false );
		$clock     = new SequenceClock( array( 0, 1000000000 ) );
		$store     = new InMemoryContextStore( $clock );
		$guard     = new BlockedHostGuard( new HostNormalizer(), $this->domains );
		$registrar = new HookRegistrar( new HttpObserver( $clock, $store, $guard, $this->recorder ) );
		$server    = new LocalHttpServer();
		$registrar->register();
		ob_start();
		try {
			$response = wp_remote_request( $server->get_url() );
			$output   = ob_get_contents();
		} finally {
			ob_end_clean();
			$registrar->unregister();
			$server->close();
			$wpdb->show_errors( $show );
			$wpdb->suppress_errors( $suppress );
		}
		self::assertSame( '', $output );
		self::assertSame( 200, wp_remote_retrieve_response_code( $response ) );
		self::assertSame( 'egress-eye fixture', wp_remote_retrieve_body( $response ) );
		self::assertSame( 0, $store->count() );
	}

	/**
	 * Real object-cache Throwable failures are contained with no retry by this guard.
	 *
	 * @return void
	 */
	public function test_real_cache_error_fails_open_once(): void {
		$original                   = $GLOBALS['wp_object_cache'];
		$cache                      = new class() extends \WP_Object_Cache {
			/**
			 * Test service.
			 *
			 * @var int
			 */
			public $reads = 0;
			/**
			 * Fail only the plugin group; preserve normal WordPress option reads.
			 *
			 * @param mixed  $key   Cache key.
			 * @param string $group Cache group.
			 * @param bool   $force Force flag.
			 * @param mixed  $found Found flag.
			 * @return mixed
			 * @throws \Error For the plugin cache group.
			 */
			public function get( $key, $group = 'default', $force = false, &$found = null ) {
				if ( 'egress-eye' === $group ) {
					++$this->reads;
					throw new \Error( 'Fixture cache failure.' );
				}
				return parent::get( $key, $group, $force, $found );
			}
		};
		$GLOBALS['wp_object_cache'] = $cache;
		try {
			$guard = new BlockedHostGuard( new HostNormalizer(), $this->domains );
			self::assertFalse( $guard->filter( false, array(), 'https://example.test/' ) );
			self::assertFalse( $guard->filter( false, array(), 'https://example.test/' ) );
			self::assertSame( 1, $cache->reads );
		} finally {
			$GLOBALS['wp_object_cache'] = $original;
		}
	}

	/**
	 * Storage accepts sanitized values only, independent of the server timezone.
	 *
	 * @return void
	 */
	public function test_privacy_component_passthrough_and_utc_dates(): void {
		$timezone = date_default_timezone_get();
		// phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- Test deliberately changes the server timezone and restores it in finally.
		date_default_timezone_set( 'Asia/Tehran' );
		try {
			$context = new RequestContext( 'fixture-token', 'https://user:password@example.test/private?token=query-secret', 0, true, 1 );
			$context->complete( 2000000000 );
			$args = array(
				'headers' => array( 'Authorization' => 'header-secret' ),
				'body'    => 'body-secret',
			);
			$this->recorder->record( $context, array( 'response' => array( 'code' => 200 ) ), 'fixture', $args, $context->getUrl() );
			self::assertTrue( $this->recorder->record_request( new SlowRequest( 'component.example', 2000000, 1710000000, 201, null, 'theme', 'fixture-theme' ) ) );
			$this->audits->record( 'component.example', 1, 'block', false, true, 1710000000 );
		} finally {
			// phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- Restore the timezone changed by this test only.
			date_default_timezone_set( $timezone );
		}
		$rows    = array();
		$payload = '';
		foreach ( array( 'domains', 'events', 'audits' ) as $table ) {
			$rows[ $table ] = $this->database->rows( 'SELECT * FROM %i ORDER BY id', array( $this->database->table( $table ) ) );
			$payload       .= wp_json_encode( $rows[ $table ] );
		}
		foreach ( array( 'https://', 'query-secret', 'header-secret', 'body-secret', '/private', 'password' ) as $secret ) {
			self::assertStringNotContainsString( $secret, $payload );
		}
		self::assertSame( gmdate( 'Y-m-d H:i:s', 1710000000 ), $rows['events'][1]['occurred_at'] );
		self::assertSame( gmdate( 'Y-m-d H:i:s', 1710000000 ), $rows['domains'][1]['last_seen_at'] );
		self::assertSame( gmdate( 'Y-m-d H:i:s', 1710000000 ), $rows['audits'][0]['occurred_at'] );
		self::assertSame( 'theme', $rows['events'][1]['component_kind'] );
		self::assertSame( 'fixture-theme', $rows['events'][1]['component_key'] );
	}

	/**
	 * Several real fast HTTP calls reuse one repository lookup and perform no writes.
	 *
	 * @return void
	 */
	public function test_real_fast_http_calls_have_one_block_list_read_and_no_writes(): void {
		global $wpdb;
		$queries   = array();
		$spy       = static function ( $sql ) use ( &$queries ) {
			$queries[] = $sql;
			return $sql;
		};
		$clock     = new MonotonicClock();
		$store     = new InMemoryContextStore( $clock );
		$guard     = new BlockedHostGuard( new HostNormalizer(), $this->domains );
		$registrar = new HookRegistrar( new HttpObserver( $clock, $store, $guard, $this->recorder ) );
		$server    = new LocalHttpServer();
		$registrar->register();
		add_filter( 'query', $spy );
		try {
			for ( $i = 0; $i < 3; ++$i ) {
				self::assertSame( 200, wp_remote_retrieve_response_code( wp_remote_request( $server->get_url() ) ) );
			}
		} finally {
			remove_filter( 'query', $spy );
			$registrar->unregister();
			$server->close();
		}
		$reads = 0;
		foreach ( $queries as $sql ) {
			self::assertDoesNotMatchRegularExpression( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER)\b/i', $sql );
			if ( false !== strpos( $sql, $wpdb->prefix . 'http_diag_domains' ) && false !== strpos( $sql, 'is_blocked = 1' ) ) {
				++$reads;
			}
		}
		self::assertSame( 1, $reads );
		self::assertSame( 0, $store->count() );
		self::assertSame( 0, $this->count_rows( 'events' ) );
	}

	/**
	 * State and audit commit together and invalidate actual cached snapshots.
	 *
	 * @return void
	 */
	public function test_manual_state_audit_and_cache_invalidation(): void {
		self::assertSame( array(), $this->domains->getBlockedHosts() );
		self::assertTrue( $this->domains->set_blocked( 'example.test', true, 1 ) );
		self::assertContains( 'example.test', $this->domains->getBlockedHosts() );
		self::assertTrue( $this->domains->set_blocked( 'example.test', false, 1 ) );
		self::assertSame( array(), $this->domains->getBlockedHosts() );
		self::assertSame( 2, $this->count_rows( 'audits' ) );
		$this->database->execute( 'DROP TABLE %i', array( $this->database->table( 'audits' ) ) );
		self::assertFalse( $this->domains->set_blocked( 'example.test', true, 1 ) );
		$row = $this->database->row( 'SELECT is_blocked FROM %i WHERE host = %s', array( $this->database->table( 'domains' ), 'example.test' ) );
		self::assertSame( 0, (int) $row['is_blocked'] );
	}

	/**
	 * Network activation is rejected rather than installing partial network tables.
	 *
	 * @return void
	 */
	public function test_network_activation_is_rejected_clearly(): void {
		$handler = static function () {
			return static function ( $message ): void {
				throw new \RuntimeException( esc_html( (string) $message ) );
			};
		};
		add_filter( 'wp_die_handler', $handler );
		try {
			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( 'Egress Eye supports single-site activation only.' );
			$this->schema->activate( true );
		} finally {
			remove_filter( 'wp_die_handler', $handler );
		}
	}

	/**
	 * Uninstall deletes all plugin tables/options and its fallback cron event.
	 *
	 * @return void
	 */
	public function test_uninstall_removes_all_storage_options_and_cron(): void {
		foreach ( SchemaManager::option_names() as $option ) {
			update_option( $option, 'fixture-value' );
		}
		wp_schedule_event( time() + 3600, 'hourly', RetentionService::CRON_HOOK );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'egress-eye/egress-eye.php' );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';
		foreach ( array( 'domains', 'events', 'audits' ) as $table ) {
			self::assertNull( $this->database->scalar( 'SHOW TABLES LIKE %s', array( $this->database->table( $table ) ) ) );
		}
		foreach ( SchemaManager::option_names() as $option ) {
			self::assertFalse( get_option( $option, false ) );
		}
		self::assertFalse( wp_next_scheduled( RetentionService::CRON_HOOK ) );
	}

	/**
	 * Manual state changes must never commit a caller-owned transaction.
	 *
	 * @return void
	 */
	public function test_block_mutation_preserves_caller_transaction(): void {
		$id = $this->domains->ensure_host( 'caller.example' );
		$this->database->execute( 'START TRANSACTION' );
		try {
			$this->database->execute( 'UPDATE %i SET slow_count = %d WHERE id = %d', array( $this->database->table( 'domains' ), 77, $id ) );
			$result = $this->domains->set_blocked( 'blocked.example', true, 1 );
		} finally {
			$this->database->execute( 'ROLLBACK' );
		}
		self::assertFalse( $result );
		self::assertSame( 0, (int) $this->database->scalar( 'SELECT slow_count FROM %i WHERE id = %d', array( $this->database->table( 'domains' ), $id ) ) );
		self::assertSame( 0, $this->count_rows( 'audits' ) );
	}

	/**
	 * Uncommitted diagnostic admission cannot outlive its capacity lock.
	 *
	 * @return void
	 */
	public function test_diagnostic_writes_decline_caller_transaction(): void {
		$id     = $this->domains->ensure_host( 'existing.example' );
		$sample = new SlowRequest( 'existing.example', 2000000, time() );
		$this->database->execute( 'START TRANSACTION' );
		try {
			$results = array(
				$this->domains->ensure_host( 'new.example' ),
				$this->domains->upsert_slow_aggregate( $sample ),
				$this->events->insert( $sample, $id ),
				$this->audits->record( 'existing.example', 1, 'block', false, true ),
				$this->retention->run_maintenance(),
				$this->schema->install(),
			);
		} finally {
			$this->database->execute( 'ROLLBACK' );
		}
		self::assertSame( array( null, null, null, null, false, false ), $results );
		self::assertSame( 1, $this->count_rows( 'domains' ) );
		self::assertSame( 0, $this->count_rows( 'events' ) );
		self::assertSame( 0, $this->count_rows( 'audits' ) );
	}

	/**
	 * Disabled autocommit also belongs to the caller, even before any DML.
	 *
	 * @return void
	 */
	public function test_disabled_autocommit_declines_storage(): void {
		$this->database->execute( 'SET autocommit = %d', array( 0 ) );
		try {
			$result = $this->domains->ensure_host( 'transaction.example' );
		} finally {
			$this->database->execute( 'ROLLBACK' );
			$this->database->execute( 'SET autocommit = %d', array( 1 ) );
		}
		self::assertNull( $result );
		self::assertSame( 0, $this->count_rows( 'domains' ) );
	}

	/**
	 * A stale cache publication after a committed mutation cannot overwrite freshness.
	 *
	 * @return void
	 */
	public function test_stale_reader_cannot_republish_current_block_snapshot(): void {
		$original                   = $GLOBALS['wp_object_cache'];
		$cache                      = new class() extends \WP_Object_Cache {
			/**
			 * Interleave a real database mutation immediately before snapshot publication.
			 *
			 * @var callable|null
			 */
			public $interleave;
			/**
			 * Publish through the real cache after the deterministic interleaving.
			 *
			 * @param mixed  $key    Cache key.
			 * @param mixed  $data   Cache value.
			 * @param string $group  Cache group.
			 * @param int    $expire Expiration.
			 * @return bool
			 */
			public function set( $key, $data, $group = 'default', $expire = 0 ) {
				if ( 'egress-eye' === $group && null !== $this->interleave ) {
					$callback         = $this->interleave;
					$this->interleave = null;
					$callback();
				}
				return parent::set( $key, $data, $group, $expire );
			}
		};
		$cache->interleave          = function (): void {
			self::assertTrue( $this->domains->set_blocked( 'raced.example', true, 1 ) );
		};
		$GLOBALS['wp_object_cache'] = $cache;
		try {
			self::assertSame( array(), $this->domains->getBlockedHosts() );
			self::assertContains( 'raced.example', $this->domains->getBlockedHosts() );
		} finally {
			$GLOBALS['wp_object_cache'] = $original;
		}
	}

	/**
	 * Cache deletion failure after commit must neither misreport nor retain stale blocks.
	 *
	 * @return void
	 */
	public function test_committed_state_survives_cache_delete_error(): void {
		self::assertTrue( $this->domains->set_blocked( 'cached.example', true, 1 ) );
		$original                   = $GLOBALS['wp_object_cache'];
		$cache                      = new class() extends \WP_Object_Cache {
			/**
			 * Reject only plugin snapshot deletion.
			 *
			 * @param mixed  $key        Cache key.
			 * @param string $group      Cache group.
			 * @param bool   $deprecated Deprecated force flag.
			 * @return bool
			 * @throws \Error For plugin snapshots.
			 */
			public function delete( $key, $group = 'default', $deprecated = false ) {
				if ( 'egress-eye' === $group ) {
					throw new \Error( 'Fixture deletion failure.' );
				}
				return parent::delete( $key, $group, $deprecated );
			}
		};
		$GLOBALS['wp_object_cache'] = $cache;
		try {
			self::assertContains( 'cached.example', $this->domains->getBlockedHosts() );
			self::assertTrue( $this->domains->set_blocked( 'cached.example', false, 1 ) );
			self::assertSame( array(), $this->domains->getBlockedHosts() );
			self::assertSame( 2, $this->count_rows( 'audits' ) );
		} finally {
			$GLOBALS['wp_object_cache'] = $original;
		}
	}

	/**
	 * Failed schema metadata never reports success or prints database errors.
	 *
	 * @return void
	 */
	public function test_schema_version_write_failure_is_silent_and_false(): void {
		global $wpdb;
		update_option( SchemaManager::VERSION_OPTION, 0, true );
		$trigger  = $wpdb->prefix . 'egress_eye_reject_version';
		$admin    = $this->trigger_admin( $trigger, SchemaManager::VERSION_OPTION );
		$show     = $wpdb->show_errors( true );
		$suppress = $wpdb->suppress_errors( false );
		ob_start();
		try {
			$result = $this->schema->install();
			$output = ob_get_contents();
		} finally {
			ob_end_clean();
			$wpdb->show_errors( $show );
			$wpdb->suppress_errors( $suppress );
			$admin->execute( 'DROP TRIGGER %i', array( $trigger ) );
		}
		self::assertSame( '', $output );
		self::assertFalse( $result );
		self::assertSame( 0, (int) get_option( SchemaManager::VERSION_OPTION ) );
	}

	/**
	 * Standalone audits use the same canonical host spelling as domains.
	 *
	 * @return void
	 */
	public function test_standalone_audit_stores_canonical_host(): void {
		$id = $this->audits->record( 'EXAMPLE.TEST.', 1, 'block', false, true );
		self::assertGreaterThan( 0, $id );
		self::assertSame( 'example.test', $this->database->scalar( 'SELECT host FROM %i WHERE id = %d', array( $this->database->table( 'audits' ), $id ) ) );
	}

	/**
	 * A legacy snapshot deletion error cannot leave the recurring cron event.
	 *
	 * @return void
	 */
	public function test_uninstall_clears_cron_despite_cache_error(): void {
		wp_schedule_event( time() + 3600, 'hourly', RetentionService::CRON_HOOK );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'egress-eye/egress-eye.php' );
		}
		$original                   = $GLOBALS['wp_object_cache'];
		$cache                      = new class() extends \WP_Object_Cache {
			/**
			 * Reject legacy plugin snapshots only.
			 *
			 * @param mixed  $key        Cache key.
			 * @param string $group      Cache group.
			 * @param bool   $deprecated Deprecated force flag.
			 * @return bool
			 * @throws \Error For plugin snapshots.
			 */
			public function delete( $key, $group = 'default', $deprecated = false ) {
				if ( 'egress-eye' === $group ) {
					throw new \Error( 'Fixture uninstall cache failure.' );
				}
				return parent::delete( $key, $group, $deprecated );
			}
		};
		$GLOBALS['wp_object_cache'] = $cache;
		$failure                    = null;
		try {
			require dirname( __DIR__, 2 ) . '/uninstall.php';
		} catch ( \Throwable $exception ) {
			$failure = $exception;
		} finally {
			$GLOBALS['wp_object_cache'] = $original;
			// The restored fixture cache predates the real cron update; discard its old core options.
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'cron', 'options' );
		}
		self::assertNull( $failure );
		self::assertFalse( wp_next_scheduled( RetentionService::CRON_HOOK ) );
		self::assertStringNotContainsString( RetentionService::CRON_HOOK, (string) $this->database->scalar( 'SELECT option_value FROM %i WHERE option_name = %s', array( $this->database->options_table(), 'cron' ) ) );
	}

	/**
	 * Optional cron setup must contain real core-options SQL errors.
	 *
	 * @return void
	 */
	public function test_optional_cron_storage_errors_are_silent(): void {
		global $wpdb;
		update_option( 'cron', array( 'version' => 2 ) );
		$trigger  = $wpdb->prefix . 'egress_eye_reject_cron';
		$admin    = $this->trigger_admin( $trigger, 'cron' );
		$show     = $wpdb->show_errors( true );
		$suppress = $wpdb->suppress_errors( false );
		ob_start();
		try {
			$this->invoke_storage_lifecycle();
			$output = ob_get_contents();
		} finally {
			ob_end_clean();
			$wpdb->show_errors( $show );
			$wpdb->suppress_errors( $suppress );
			$admin->execute( 'DROP TRIGGER %i', array( $trigger ) );
		}
		self::assertSame( '', $output );
		self::assertFalse( wp_next_scheduled( RetentionService::CRON_HOOK ) );
	}

	/**
	 * Core-option cache Throwable failures cannot escape plugin lifecycle boot.
	 *
	 * @return void
	 */
	public function test_storage_lifecycle_contains_core_option_cache_error(): void {
		$original                   = $GLOBALS['wp_object_cache'];
		$cache                      = new class() extends \WP_Object_Cache {
			/**
			 * Fail core option reads during plugin lifecycle only.
			 *
			 * @param mixed  $key   Cache key.
			 * @param string $group Cache group.
			 * @param bool   $force Force flag.
			 * @param mixed  $found Found flag.
			 * @return mixed
			 * @throws \Error For option reads.
			 */
			public function get( $key, $group = 'default', $force = false, &$found = null ) {
				if ( 'options' === $group ) {
					throw new \Error( 'Fixture core option cache failure.' );
				}
				return parent::get( $key, $group, $force, $found );
			}
		};
		$GLOBALS['wp_object_cache'] = $cache;
		$failure                    = null;
		try {
			$this->invoke_storage_lifecycle();
		} catch ( \Throwable $exception ) {
			$failure = $exception;
		} finally {
			$GLOBALS['wp_object_cache'] = $original;
		}
		self::assertNull( $failure );
	}

	/**
	 * Non-ASCII identity is never allowed to collide with an ASCII host.
	 *
	 * @return void
	 */
	public function test_accent_hosts_are_distinct_or_unicode_is_declined(): void {
		self::assertGreaterThan( 0, $this->domains->ensure_host( 'muller.de' ) );
		$unicode = $this->domains->ensure_host( 'müller.de' );
		if ( function_exists( 'idn_to_ascii' ) ) {
			self::assertGreaterThan( 0, $unicode );
			self::assertSame( 2, $this->count_rows( 'domains' ) );
			self::assertSame( 'xn--mller-kva.de', DomainRepository::canonical_host( 'müller.de' ) );
		} else {
			self::assertNull( $unicode );
			self::assertSame( 1, $this->count_rows( 'domains' ) );
			self::assertFalse( $this->domains->set_blocked( 'müller.de', true, 1 ) );
		}
		self::assertSame( 'muller.de', DomainRepository::canonical_host( 'muller.de' ) );
	}

	/**
	 * Rejected network activation leaves every diagnostic table absent.
	 *
	 * @return void
	 */
	public function test_network_activation_creates_no_tables(): void {
		foreach ( array( 'domains', 'events', 'audits' ) as $table ) {
			$this->database->execute( 'DROP TABLE %i', array( $this->database->table( $table ) ) );
		}
		$handler = static function () {
			return static function ( $message ): void {
				throw new \RuntimeException( esc_html( (string) $message ) );
			};
		};
		add_filter( 'wp_die_handler', $handler );
		$failure = null;
		try {
			$this->schema->activate( true );
		} catch ( \RuntimeException $exception ) {
			$failure = $exception;
		} finally {
			remove_filter( 'wp_die_handler', $handler );
		}
		self::assertInstanceOf( \RuntimeException::class, $failure );
		self::assertSame( 'Egress Eye supports single-site activation only.', $failure->getMessage() );
		foreach ( array( 'domains', 'events', 'audits' ) as $table ) {
			self::assertNull( $this->database->scalar( 'SHOW TABLES LIKE %s', array( $this->database->table( $table ) ) ) );
		}
	}

	/**
	 * Full-domain admission signals once with a non-autoload option until cleared.
	 *
	 * @return void
	 */
	public function test_domain_capacity_rejection_sets_notice_once_until_cleared(): void {
		global $wpdb;
		delete_option( 'egress_eye_domain_capacity_notice' );
		for ( $i = 0; $i < 500; ++$i ) {
			self::assertGreaterThan( 0, $this->domains->ensure_host( 'capacity-' . $i . '.example' ) );
		}
		$queries = array();
		$spy     = static function ( $sql ) use ( &$queries ) {
			if ( false !== strpos( $sql, 'egress_eye_domain_capacity_notice' ) && preg_match( '/^\s*(INSERT|UPDATE|REPLACE)/i', $sql ) ) {
				$queries[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $spy );
		try {
			self::assertNull( $this->domains->ensure_host( 'rejected.example' ) );
			self::assertNull( $this->domains->ensure_host( 'another-rejected.example' ) );
		} finally {
			remove_filter( 'query', $spy );
		}
		self::assertSame( '1', (string) get_option( 'egress_eye_domain_capacity_notice' ) );
		self::assertCount( 1, $queries );
		$autoload = $this->database->scalar( 'SELECT autoload FROM %i WHERE option_name = %s', array( $wpdb->options, 'egress_eye_domain_capacity_notice' ) );
		self::assertContains( $autoload, array( 'no', 'off' ) );
		delete_option( 'egress_eye_domain_capacity_notice' );
		self::assertNull( $this->domains->ensure_host( 'rejected-again.example' ) );
		self::assertSame( '1', (string) get_option( 'egress_eye_domain_capacity_notice' ) );
	}

	/**
	 * Fast or rejected samples capture no trace; an admitted slow sample captures once.
	 *
	 * @return void
	 */
	public function test_component_capture_only_for_admitted_slow_samples(): void {
		$trace    = new class() implements \EgressEye\Contracts\BacktraceProvider {
			/**
			 * Capture count.
			 *
			 * @var int
			 */
			public $calls = 0;
			/**
			 * Supply a selected plugin call path, without payloads.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function capture(): array {
				++$this->calls;
				return array( array( 'file' => WP_PLUGIN_DIR . '/example/client.php' ) );
			}
		};
		$recorder = new SlowRequestRecorderService( $this->domains, $this->events, new \EgressEye\Diagnostics\StackComponentGuesser(), $trace );
		$fast     = new RequestContext( 'fast', 'https://example.test/', 0, true, 1 );
		$fast->complete( 999999999 );
		$recorder->record( $fast, array(), '', array( 'body' => 'secret' ), $fast->getUrl() );
		self::assertSame( 0, $trace->calls );
		self::assertSame( 0, $this->count_rows( 'domains' ) );
		$slow = new RequestContext( 'slow', 'https://example.test/', 0, true, 1 );
		$slow->complete( 1000000000 );
		$recorder->record( $slow, array(), '', array(), $slow->getUrl() );
		self::assertSame( 1, $trace->calls );
		$row = $this->database->row( 'SELECT component_kind, component_key FROM %i', array( $this->database->table( 'events' ) ) );
		self::assertSame( 'plugin', $row['component_kind'] );
		self::assertSame( 'example', $row['component_key'] );
		for ( $i = 0; $i < 499; ++$i ) {
			$this->domains->ensure_host( 'trace-capacity-' . $i . '.example' );
		}
		$rejected = new RequestContext( 'rejected', 'https://not-admitted.example/', 0, true, 1 );
		$rejected->complete( 1000000000 );
		$recorder->record( $rejected, array(), '', array(), $rejected->getUrl() );
		self::assertSame( 1, $trace->calls );
	}

	/**
	 * Failed aggregate or event writes never pay for native trace capture.
	 *
	 * @dataProvider component_write_failures
	 * @param string $table Table whose write fails.
	 * @return void
	 */
	public function test_failed_recording_never_captures_component_trace( string $table ): void {
		self::assertGreaterThan( 0, $this->domains->ensure_host( 'write-failure.example' ) );
		$trace = new class() implements \EgressEye\Contracts\BacktraceProvider {
			/**
			 * Capture count.
			 *
			 * @var int
			 */
			public $calls = 0;
			/**
			 * Detect capture even when storage later fails.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function capture(): array {
				++$this->calls;
				return array();
			}
		};
		$admin = $this->trigger_admin( 'egress_eye_component_unused', 'egress_eye_component_unused' );
		$admin->execute( 'DROP TRIGGER %i', array( 'egress_eye_component_unused' ) );
		$admin->execute( 'CREATE TRIGGER %i BEFORE INSERT ON %i FOR EACH ROW SIGNAL SQLSTATE %s SET MESSAGE_TEXT = %s', array( 'egress_eye_component_failure', $this->database->table( $table ), '45000', 'Fixture component write failure.' ) );
		$recorder = new SlowRequestRecorderService( $this->domains, $this->events, new \EgressEye\Diagnostics\StackComponentGuesser(), $trace );
		$slow     = new RequestContext( 'write-failure', 'https://write-failure.example/', 0, true, 1 );
		$slow->complete( 1000000000 );
		ob_start();
		try {
			$recorder->record( $slow, array( 'response' => array( 'code' => 200 ) ), '', array(), $slow->getUrl() );
			$output = ob_get_contents();
		} finally {
			ob_end_clean();
			$admin->execute( 'DROP TRIGGER %i', array( 'egress_eye_component_failure' ) );
		}
		self::assertSame( '', $output );
		self::assertSame( 0, $trace->calls );
		self::assertSame( 0, $this->count_rows( 'events' ) );
	}

	/**
	 * Test both failure points after an existing host has been admitted.
	 *
	 * @return array<string,array{string}>
	 */
	public function component_write_failures(): array {
		return array(
			'aggregate failure' => array( 'domains' ),
			'event failure'     => array( 'events' ),
		);
	}

	/**
	 * Annotation failure preserves one unknown sample and unchanged counters.
	 *
	 * @return void
	 */
	public function test_failed_component_annotation_preserves_persisted_unknown(): void {
		$sample = new SlowRequest( 'annotation.example', 2000000, time(), 200, null, 'unknown' );
		$id     = $this->domains->upsert_slow_aggregate( $sample );
		$event  = $this->events->insert( $sample, $id );
		$admin  = $this->trigger_admin( 'egress_eye_annotation_unused', 'egress_eye_annotation_unused' );
		$admin->execute( 'DROP TRIGGER %i', array( 'egress_eye_annotation_unused' ) );
		$admin->execute( 'CREATE TRIGGER %i BEFORE UPDATE ON %i FOR EACH ROW SIGNAL SQLSTATE %s SET MESSAGE_TEXT = %s', array( 'egress_eye_annotation_failure', $this->database->table( 'events' ), '45000', 'Fixture annotation failure.' ) );
		ob_start();
		try {
			$result = $this->events->attribute( $event, new \EgressEye\Diagnostics\ComponentGuess( 'plugin', 'first' ) );
			$output = ob_get_contents();
		} finally {
			ob_end_clean();
			$admin->execute( 'DROP TRIGGER %i', array( 'egress_eye_annotation_failure' ) );
		}
		self::assertFalse( $result );
		self::assertSame( '', $output );
		self::assertSame( 1, $this->count_rows( 'events' ) );
		$domain = $this->database->row( 'SELECT slow_count, slow_duration_sum_us, last_component_kind, last_component_key FROM %i WHERE id = %d', array( $this->database->table( 'domains' ), $id ) );
		$saved  = $this->database->row( 'SELECT component_kind, component_key FROM %i WHERE id = %d', array( $this->database->table( 'events' ), $event ) );
		self::assertSame( '1', (string) $domain['slow_count'] );
		self::assertSame( '2000000', (string) $domain['slow_duration_sum_us'] );
		self::assertSame( 'unknown', $domain['last_component_kind'] );
		self::assertNull( $domain['last_component_key'] );
		self::assertSame( 'unknown', $saved['component_kind'] );
		self::assertNull( $saved['component_key'] );
	}

	/**
	 * Delayed attribution cannot clobber a later request's domain metadata.
	 *
	 * @return void
	 */
	public function test_out_of_order_component_annotation_preserves_newest_domain_sample(): void {
		$first     = new SlowRequest( 'ordered.example', 2000000, time(), 200, null, 'unknown' );
		$id        = $this->domains->upsert_slow_aggregate( $first );
		$old_event = $this->events->insert( $first, $id );
		$newer     = new SlowRequest( 'ordered.example', 3000000, time(), 200, null, 'theme', 'newest' );
		$this->domains->upsert_slow_aggregate( $newer );
		$new_event = $this->events->insert( $newer, $id );
		self::assertTrue( $this->events->attribute( $old_event, new \EgressEye\Diagnostics\ComponentGuess( 'plugin', 'older' ) ) );
		$domain = $this->database->row( 'SELECT slow_count, slow_duration_sum_us, last_component_kind, last_component_key FROM %i WHERE id = %d', array( $this->database->table( 'domains' ), $id ) );
		self::assertSame( '2', (string) $domain['slow_count'] );
		self::assertSame( '5000000', (string) $domain['slow_duration_sum_us'] );
		self::assertSame( 'theme', $domain['last_component_kind'] );
		self::assertSame( 'newest', $domain['last_component_key'] );
		self::assertSame( 'older', $this->database->scalar( 'SELECT component_key FROM %i WHERE id = %d', array( $this->database->table( 'events' ), $old_event ) ) );
		self::assertSame( 'newest', $this->database->scalar( 'SELECT component_key FROM %i WHERE id = %d', array( $this->database->table( 'events' ), $new_event ) ) );
		self::assertSame( 2, $this->count_rows( 'events' ) );
	}

	/**
	 * Event insertion order must not redefine atomic aggregate sample ownership.
	 *
	 * @dataProvider inverted_component_samples
	 * @param int $duration Newer aggregate duration, including an equal tuple.
	 * @return void
	 */
	public function test_inverted_event_order_keeps_newer_aggregate_attribution( int $duration ): void {
		$time  = time();
		$first = new SlowRequest( 'inverted.example', 2000000, $time, 200, null, 'unknown' );
		$id    = $this->domains->upsert_slow_aggregate( $first );
		$last  = new SlowRequest( 'inverted.example', $duration, $time, 200, null, 'unknown' );
		$this->domains->upsert_slow_aggregate( $last );
		$last_event  = $this->events->insert( $last, $id );
		$first_event = $this->events->insert( $first, $id );
		self::assertTrue( $this->events->attribute( $last_event, new \EgressEye\Diagnostics\ComponentGuess( 'theme', 'newest' ) ) );
		self::assertSame( 'newest', $this->database->scalar( 'SELECT last_component_key FROM %i WHERE id = %d', array( $this->database->table( 'domains' ), $id ) ) );
		self::assertTrue( $this->events->attribute( $first_event, new \EgressEye\Diagnostics\ComponentGuess( 'plugin', 'older' ) ) );
		self::assertSame( 'newest', $this->database->scalar( 'SELECT last_component_key FROM %i WHERE id = %d', array( $this->database->table( 'domains' ), $id ) ) );
		self::assertSame( 'newest', $this->database->scalar( 'SELECT component_key FROM %i WHERE id = %d', array( $this->database->table( 'events' ), $last_event ) ) );
		self::assertSame( 'older', $this->database->scalar( 'SELECT component_key FROM %i WHERE id = %d', array( $this->database->table( 'events' ), $first_event ) ) );
		self::assertSame( 2, $this->count_rows( 'events' ) );
		self::assertSame( '2', (string) $this->database->scalar( 'SELECT slow_count FROM %i WHERE id = %d', array( $this->database->table( 'domains' ), $id ) ) );
	}

	/**
	 * Include equal timestamps and durations, which cannot establish ownership.
	 *
	 * @return array<string,array{int}>
	 */
	public function inverted_component_samples(): array {
		return array(
			'equal tuple'     => array( 2000000 ),
			'different tuple' => array( 3000000 ),
		);
	}

	/**
	 * An older event cannot take ownership while a newer aggregate awaits its event.
	 *
	 * @dataProvider inverted_component_samples
	 * @param int $duration Newer aggregate duration.
	 * @return void
	 */
	public function test_pending_newer_event_keeps_aggregate_unknown( int $duration ): void {
		$time  = time();
		$first = new SlowRequest( 'pending.example', 2000000, $time, 200, null, 'unknown' );
		$id    = $this->domains->upsert_slow_aggregate( $first );
		$last  = new SlowRequest( 'pending.example', $duration, $time, 200, null, 'unknown' );
		$this->domains->upsert_slow_aggregate( $last );
		$first_event = $this->events->insert( $first, $id );
		self::assertTrue( $this->events->attribute( $first_event, new \EgressEye\Diagnostics\ComponentGuess( 'plugin', 'older' ) ) );
		self::assertNull( $this->database->scalar( 'SELECT last_component_key FROM %i WHERE id = %d', array( $this->database->table( 'domains' ), $id ) ) );
		$last_event = $this->events->insert( $last, $id );
		self::assertTrue( $this->events->attribute( $last_event, new \EgressEye\Diagnostics\ComponentGuess( 'theme', 'newest' ) ) );
		self::assertSame( 'newest', $this->database->scalar( 'SELECT last_component_key FROM %i WHERE id = %d', array( $this->database->table( 'domains' ), $id ) ) );
		self::assertSame( 'older', $this->database->scalar( 'SELECT component_key FROM %i WHERE id = %d', array( $this->database->table( 'events' ), $first_event ) ) );
	}

	/**
	 * Reusing an explicit sample must establish a new aggregate ownership identity.
	 *
	 * @return void
	 */
	public function test_reused_sample_cannot_restore_previous_event_attribution(): void {
		$sample = new SlowRequest( 'reused.example', 2000000, time(), 200, null, 'unknown' );
		$id     = $this->domains->upsert_slow_aggregate( $sample );
		$first  = $this->events->insert( $sample, $id );
		$this->domains->upsert_slow_aggregate( $sample );
		self::assertTrue( $this->events->attribute( $first, new \EgressEye\Diagnostics\ComponentGuess( 'plugin', 'older' ) ) );
		self::assertNull( $this->database->scalar( 'SELECT last_component_key FROM %i WHERE id = %d', array( $this->database->table( 'domains' ), $id ) ) );
		$last = $this->events->insert( $sample, $id );
		self::assertTrue( $this->events->attribute( $last, new \EgressEye\Diagnostics\ComponentGuess( 'theme', 'newest' ) ) );
		self::assertSame( 'newest', $this->database->scalar( 'SELECT last_component_key FROM %i WHERE id = %d', array( $this->database->table( 'domains' ), $id ) ) );
		self::assertSame( '2', (string) $this->database->scalar( 'SELECT slow_count FROM %i WHERE id = %d', array( $this->database->table( 'domains' ), $id ) ) );
	}

	/**
	 * Schema one upgrades preserve rows and introduce nullable internal identities.
	 *
	 * @return void
	 */
	public function test_schema_one_migrates_sample_identity_without_losing_data(): void {
		$sample = new SlowRequest( 'legacy.example', 2000000, time(), 200, null, 'plugin', 'legacy' );
		self::assertTrue( $this->recorder->record_request( $sample ) );
		$this->database->execute( 'ALTER TABLE %i DROP COLUMN last_sample_key', array( $this->database->table( 'domains' ) ) );
		$this->database->execute( 'ALTER TABLE %i DROP COLUMN sample_key', array( $this->database->table( 'events' ) ) );
		update_option( SchemaManager::VERSION_OPTION, 1 );
		self::assertTrue( $this->schema->ensure_current() );
		self::assertSame( 2, (int) get_option( SchemaManager::VERSION_OPTION ) );
		self::assertSame( 1, $this->count_rows( 'events' ) );
		$legacy = $this->database->row( 'SELECT host, slow_count, last_component_key, last_sample_key FROM %i', array( $this->database->table( 'domains' ) ) );
		self::assertSame( 'legacy.example', $legacy['host'] );
		self::assertSame( '1', (string) $legacy['slow_count'] );
		self::assertSame( 'legacy', $legacy['last_component_key'] );
		self::assertNull( $legacy['last_sample_key'] );
		self::assertNull( $this->database->scalar( 'SELECT sample_key FROM %i', array( $this->database->table( 'events' ) ) ) );
		self::assertTrue( $this->schema->install() );
		self::assertSame( 1, $this->count_rows( 'events' ) );
		$fresh = new SlowRequest( 'legacy.example', 3000000, time(), 200, null, 'theme', 'fresh' );
		self::assertTrue( $this->recorder->record_request( $fresh ) );
		$owner = $this->database->scalar( 'SELECT last_sample_key FROM %i', array( $this->database->table( 'domains' ) ) );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{32}$/D', $owner );
		self::assertSame( $owner, $this->database->scalar( 'SELECT sample_key FROM %i ORDER BY id DESC LIMIT %d', array( $this->database->table( 'events' ), 1 ) ) );
		self::assertArrayNotHasKey( 'sample_key', get_object_vars( $fresh ) );
		self::assertSame( '5000000', (string) $this->database->scalar( 'SELECT slow_duration_sum_us FROM %i', array( $this->database->table( 'domains' ) ) ) );
	}

	/**
	 * WordPress registered real-path mappings retain probable plugin identity.
	 *
	 * @return void
	 */
	public function test_registered_symlink_component_path_uses_wordpress_basename(): void {
		global $wp_plugin_paths;
		$original        = $wp_plugin_paths;
		$wp_plugin_paths = array( wp_normalize_path( WP_PLUGIN_DIR . '/linked-example' ) => '/srv/releases/linked-example' );
		try {
			self::assertSame( 'linked-example/client.php', plugin_basename( '/srv/releases/linked-example/client.php' ) );
			$guess = ( new \EgressEye\Diagnostics\StackComponentGuesser() )->guess( array( array( 'file' => '/srv/releases/linked-example/client.php' ) ) );
			self::assertSame( 'plugin', $guess->kind );
			self::assertSame( 'linked-example', $guess->key );
			self::assertTrue( $guess->is_guess );
		} finally {
			$wp_plugin_paths = $original;
		}
	}

	/**
	 * Registered targets inside recognized roots retain the logical plugin identity.
	 *
	 * @return void
	 */
	public function test_symlinks_inside_content_roots_keep_registered_identity(): void {
		global $wp_plugin_paths;
		$previous = $wp_plugin_paths;
		try {
			foreach ( array( WP_PLUGIN_DIR . '/shared/linked', WPMU_PLUGIN_DIR . '/linked-physical', 'C:/shared/Linked' ) as $physical ) {
				$wp_plugin_paths = array( wp_normalize_path( WP_PLUGIN_DIR . '/linked-example' ) => $physical );
				$file            = $physical . '/client.php';
				if ( 0 === strpos( $physical, 'C:' ) ) {
					$file = 'c:/SHARED/Linked/client.php';
				}
				$guess = ( new \EgressEye\Diagnostics\StackComponentGuesser() )->guess( array( array( 'file' => $file ) ) );
				self::assertSame( 'plugin', $guess->kind );
				self::assertSame( 'linked-example', $guess->key );
				self::assertTrue( $guess->is_guess );
			}
		} finally {
			$wp_plugin_paths = $previous;
		}
	}

	/**
	 * A nested test installation remains WordPress core rather than own source.
	 *
	 * @return void
	 */
	public function test_nested_wordpress_core_is_not_plugin_self(): void {
		$guess = ( new \EgressEye\Diagnostics\StackComponentGuesser() )->guess( array( array( 'file' => ABSPATH . 'wp-includes/cron.php' ) ) );
		self::assertSame( 'core', $guess->kind );
		self::assertSame( 'wordpress', $guess->key ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Stable machine key, not display text.
	}

	/**
	 * Windows casing cannot cause a parent symlink to hide a child registration.
	 *
	 * @return void
	 */
	public function test_overlapping_windows_symlinks_use_longest_physical_prefix(): void {
		global $wp_plugin_paths;
		$original        = $wp_plugin_paths;
		$wp_plugin_paths = array(
			wp_normalize_path( WP_PLUGIN_DIR . '/parent' ) => 'C:/shared',
			wp_normalize_path( WP_PLUGIN_DIR . '/child' )  => 'C:/SHARED/Linked',
		);
		try {
			$guess = ( new \EgressEye\Diagnostics\StackComponentGuesser() )->guess( array( array( 'file' => 'C:/SHARED/Linked/client.php' ) ) );
			self::assertSame( 'plugin', $guess->kind );
			self::assertSame( 'child', $guess->key );
			self::assertTrue( $guess->is_guess );
		} finally {
			$wp_plugin_paths = $original;
		}
	}

	/**
	 * Invoke the actual registered lifecycle callback without repeating core hooks.
	 *
	 * @return void
	 */
	private function invoke_storage_lifecycle(): void {
		StorageRuntime::register( dirname( __DIR__, 2 ) . '/egress-eye.php' );
		foreach ( $GLOBALS['wp_filter']['admin_init']->callbacks as $callbacks ) {
			foreach ( $callbacks as $entry ) {
				$callback = $entry['function'];
				if ( is_array( $callback ) && $callback[0] instanceof StorageRuntime ) {
					$callback();
					return;
				}
			}
		}
		self::fail( 'The real storage lifecycle callback was not registered.' );
	}

	/**
	 * Create failure triggers using existing fixture administrator privileges.
	 *
	 * @param string $trigger Trigger name.
	 * @param string $option  Option whose UPDATE must fail.
	 * @return Database
	 */
	private function trigger_admin( string $trigger, string $option ): Database {
		$user     = getenv( 'EGRESS_EYE_TEST_DB_ADMIN_USER' );
		$password = getenv( 'EGRESS_EYE_TEST_DB_ADMIN_PASSWORD' );
		$admin    = new Database( new \wpdb( false !== $user && '' !== $user ? $user : 'root', false !== $password && '' !== $password ? $password : 'root', DB_NAME, DB_HOST ) );
		$admin->execute( 'CREATE TRIGGER %i BEFORE UPDATE ON %i FOR EACH ROW BEGIN IF NEW.option_name = %s THEN SIGNAL SQLSTATE %s SET MESSAGE_TEXT = %s; END IF; END', array( $trigger, $this->database->options_table(), $option, '45000', 'Fixture metadata failure.' ) );
		return $admin;
	}

	/**
	 * Read row counts in assertions, never in a production write path.
	 *
	 * @param string $table Logical table key.
	 * @return int
	 */
	private function count_rows( string $table ): int {
		return (int) $this->database->scalar( 'SELECT COUNT(*) FROM %i', array( $this->database->table( $table ) ) );
	}
}
