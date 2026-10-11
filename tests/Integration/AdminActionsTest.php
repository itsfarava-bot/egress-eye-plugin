<?php
/**
 * Installed admin POST and atomic audit acceptance.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Integration;

use EgressEye\Tests\Support\AdminTestCase;

/**
 * Real hooks, tables and WordPress users rather than controller-only assertions.
 */
final class AdminActionsTest extends AdminTestCase {
	/**
	 * RED to GREEN: block/unblock, DB identity, actor and safe redirect.
	 *
	 * @return void
	 */
	public function test_authenticated_post_changes_only_stored_host_and_audits_actor(): void {
		$id             = $this->domains->ensure_host( 'stored.example' );
		$fields         = $this->fields( $id );
		$fields['host'] = 'tampered.example';
		$destination    = $this->post_action( $fields );
		self::assertSame( 1, $this->state( $id ) );
		self::assertStringStartsWith( admin_url( 'tools.php?page=egress-eye' ), $destination );
		$audit = $this->database->row( 'SELECT * FROM %i', array( $this->database->table( 'audits' ) ) );
		self::assertSame( 'stored.example', $audit['host'] );
		self::assertSame( $this->actor, (int) $audit['user_id'] );
		self::assertSame( 'block', $audit['action'] );
		self::assertSame( 0, (int) $audit['previous_state'] );
		self::assertSame( 1, (int) $audit['new_state'] );
		$this->post_action( $this->fields( $id, 'unblock' ) );
		self::assertSame( 0, $this->state( $id ) );
		self::assertSame( 2, (int) $this->database->scalar( 'SELECT COUNT(*) FROM %i', array( $this->database->table( 'audits' ) ) ) );
	}

	/**
	 * RED to GREEN: authoritative duplicate state is idempotent inside transaction.
	 *
	 * @return void
	 */
	public function test_duplicate_desired_state_has_one_audit_and_notification_after_unlock(): void {
		$seen     = array();
		$listener = function ( $payload ) use ( &$seen ) {
			self::assertTrue( $this->database->outside_transaction() );
			foreach ( array( 'domains', 'audits' ) as $name ) {
				$lock = 'egress-eye:' . substr( hash( 'sha256', $this->database->options_table() . ':' . $name ), 0, 48 );
				self::assertNull( $this->database->scalar( 'SELECT IS_USED_LOCK(%s)', array( $lock ) ) );
			}
			$seen[] = $payload;
		};
		add_action( 'egress_eye_block_state_changed', $listener );
		try {
			self::assertTrue( $this->domains->set_blocked( 'repeat.example', true, $this->actor ) );
			self::assertTrue( $this->domains->set_blocked( 'repeat.example', true, $this->actor ) );
			self::assertSame( 1, (int) $this->database->scalar( 'SELECT COUNT(*) FROM %i', array( $this->database->table( 'audits' ) ) ) );
			self::assertCount( 1, $seen );
			self::assertSame(
				array(
					'host'           => 'repeat.example',
					'user_id'        => $this->actor,
					'previous_state' => false,
					'new_state'      => true,
				),
				$seen[0]
			);
			self::assertTrue( $this->domains->set_blocked( 'repeat.example', false, $this->actor ) );
			self::assertTrue( $this->domains->set_blocked( 'repeat.example', false, $this->actor ) );
			self::assertCount( 2, $seen );
			self::assertSame( 2, (int) $this->database->scalar( 'SELECT COUNT(*) FROM %i', array( $this->database->table( 'audits' ) ) ) );
		} finally {
			remove_action( 'egress_eye_block_state_changed', $listener );
		}
	}

	/**
	 * New coverage: real audit failure rolls back and MyISAM refuses mutations.
	 *
	 * @return void
	 */
	public function test_failed_audit_and_nontransactional_engine_leave_state_unchanged(): void {
		$id = $this->domains->ensure_host( 'atomic.example' );
		$this->database->execute( 'RENAME TABLE %i TO %i', array( $this->database->table( 'audits' ), $this->database->table( 'audits' ) . '_fixture' ) );
		try {
			self::assertFalse( $this->domains->set_blocked( 'atomic.example', true, $this->actor ) );
			self::assertSame( 0, $this->state( $id ) );
		} finally {
			$this->database->execute( 'RENAME TABLE %i TO %i', array( $this->database->table( 'audits' ) . '_fixture', $this->database->table( 'audits' ) ) );
		}
		$this->database->execute( 'ALTER TABLE %i ENGINE = MyISAM', array( $this->database->table( 'domains' ) ) );
		self::assertFalse( $this->domains->set_blocked( 'atomic.example', true, $this->actor ) );
		self::assertSame( 0, $this->state( $id ) );
		self::assertSame( 0, (int) $this->database->scalar( 'SELECT COUNT(*) FROM %i', array( $this->database->table( 'audits' ) ) ) );
	}

	/**
	 * New coverage: a real INSERT error after UPDATE rolls back on InnoDB.
	 *
	 * @return void
	 */
	public function test_audit_insert_failure_after_update_rolls_back_without_notification(): void {
		$id       = $this->domains->ensure_host( 'rollback.example' );
		$calls    = 0;
		$listener = static function () use ( &$calls ) {
			++$calls;
		};
		$this->database->execute( 'ALTER TABLE %i CHANGE action fixture_action varchar(20) NOT NULL', array( $this->database->table( 'audits' ) ) );
		add_action( 'egress_eye_block_state_changed', $listener );
		try {
			self::assertFalse( $this->domains->set_blocked( 'rollback.example', true, $this->actor ) );
			self::assertSame( 0, $this->state( $id ) );
			self::assertSame( 0, (int) $this->database->scalar( 'SELECT COUNT(*) FROM %i', array( $this->database->table( 'audits' ) ) ) );
			self::assertSame( 0, $calls );
		} finally {
			remove_action( 'egress_eye_block_state_changed', $listener );
			$this->database->execute( 'ALTER TABLE %i CHANGE fixture_action action varchar(20) NOT NULL', array( $this->database->table( 'audits' ) ) );
		}
	}

	/**
	 * RED to GREEN: listener Throwable cannot undo committed state.
	 *
	 * @return void
	 */
	public function test_throwing_listener_cannot_fail_committed_action(): void {
		$calls    = 0;
		$before   = current_filter();
		$listener = static function () use ( &$calls ) {
			++$calls;
			throw new \Error( 'fixture listener' );
		};
		add_action( 'egress_eye_block_state_changed', $listener );
		try {
			self::assertTrue( $this->domains->set_blocked( 'listener.example', true, $this->actor ) );
			self::assertSame( 1, $calls );
			self::assertSame( $before, current_filter() );
			self::assertFalse( doing_action( 'egress_eye_block_state_changed' ) );
			self::assertTrue( $this->domains->set_blocked( 'listener.example', false, $this->actor ) );
			self::assertSame( 2, $calls );
			self::assertSame( $before, current_filter() );
			self::assertFalse( doing_action( 'egress_eye_block_state_changed' ) );
			$this->domains->set_blocked( 'listener.example', true, $this->actor );
			self::assertContains( 'listener.example', $this->domains->getBlockedHosts() );
		} finally {
			remove_action( 'egress_eye_block_state_changed', $listener );
		}
	}
}
