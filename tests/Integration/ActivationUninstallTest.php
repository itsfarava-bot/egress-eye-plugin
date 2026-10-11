<?php
/**
 * Clean activation, populated migration and unconditional uninstall release gate.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Integration;

use EgressEye\Admin\ActionNotice;
use EgressEye\Persistence\RetentionService;
use EgressEye\Persistence\SchemaManager;
use EgressEye\Tests\Support\AdminTestCase;

/**
 * Exercises installed lifecycle callbacks without rebuilding a second root.
 */
final class ActivationUninstallTest extends AdminTestCase {
	/**
	 * Lifecycle preserves populated migration and wipes storage, metadata and cron.
	 *
	 * @return void
	 */
	public function test_clean_activation_populated_migration_and_complete_uninstall(): void {
		foreach ( array( 'events', 'audits', 'domains' ) as $table ) {
			$this->database->execute( 'DROP TABLE IF EXISTS %i', array( $this->database->table( $table ) ) );
		}
		foreach ( SchemaManager::option_names() as $option ) {
			delete_option( $option );
		}
		$entry = dirname( __DIR__, 2 ) . '/egress-eye.php';
		self::assertNotFalse( has_action( 'activate_' . plugin_basename( $entry ) ) );
		do_action( 'activate_' . plugin_basename( $entry ), false );
		self::assertSame( SchemaManager::VERSION, (int) get_option( SchemaManager::VERSION_OPTION ) );
		$id = $this->domains->ensure_host( 'migration.example' );
		self::assertGreaterThan( 0, $id );
		$this->database->execute( 'ALTER TABLE %i DROP COLUMN last_sample_key', array( $this->database->table( 'domains' ) ) );
		$this->database->execute( 'ALTER TABLE %i DROP COLUMN sample_key', array( $this->database->table( 'events' ) ) );
		update_option( SchemaManager::VERSION_OPTION, 1, true );
		$schema = new SchemaManager( $this->database );
		self::assertTrue( $schema->ensure_current() );
		self::assertTrue( $schema->ensure_current() );
		self::assertSame( $id, (int) $this->database->scalar( 'SELECT id FROM %i WHERE host = %s', array( $this->database->table( 'domains' ), 'migration.example' ) ) );
		self::assertNull( $this->database->scalar( 'SELECT last_sample_key FROM %i WHERE id = %d', array( $this->database->table( 'domains' ), $id ) ) );
		self::assertSame( 0, (int) $this->database->scalar( 'SELECT COUNT(sample_key) FROM %i', array( $this->database->table( 'events' ) ) ) );
		foreach ( SchemaManager::option_names() as $option ) {
			update_option( $option, 'uninstall-fixture' );
		}
		ActionNotice::issue( 'blocked' );
		wp_schedule_event( time() + 3600, 'hourly', RetentionService::CRON_HOOK );
		wp_schedule_single_event( time() + 7200, RetentionService::CRON_HOOK, array( 'fixture' ) );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', plugin_basename( $entry ) );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';
		foreach ( array( 'events', 'audits', 'domains' ) as $table ) {
			self::assertNull( $this->database->scalar( 'SHOW TABLES LIKE %s', array( $this->database->table( $table ) ) ) );
		}
		foreach ( SchemaManager::option_names() as $option ) {
			self::assertFalse( get_option( $option, false ) );
		}
		self::assertSame( '', get_user_meta( $this->actor, ActionNotice::meta_key(), true ) );
		self::assertFalse( wp_next_scheduled( RetentionService::CRON_HOOK ) );
		self::assertFalse( wp_next_scheduled( RetentionService::CRON_HOOK, array( 'fixture' ) ) );
	}
}
