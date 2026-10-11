<?php
/**
 * Committed notification isolation with real WordPress hook execution.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Integration;

use EgressEye\Tests\Support\AdminTestCase;
use EgressEye\Diagnostics\SlowRequest;
use EgressEye\Diagnostics\SlowRequestRecorderService;
use EgressEye\Persistence\EventRepository;
use EgressEye\Persistence\RetentionService;

/**
 * Listener errors must not corrupt the named action, all hook or outer action.
 */
final class NotificationsTest extends AdminTestCase {
	/**
	 * RED to GREEN: an all listener can create the initially absent named hook.
	 *
	 * @return void
	 */
	public function test_throwing_hook_created_by_all_listener_is_not_stranded(): void {
		$hook     = 'egress_eye_block_state_changed';
		$previous = $GLOBALS['wp_filter'][ $hook ] ?? null;
		unset( $GLOBALS['wp_filter'][ $hook ] );
		$thrower   = static function () {
			throw new \Error( 'new named listener error' );
		};
		$installed = false;
		$all       = static function ( $name ) use ( $hook, $thrower, &$installed ) {
			if ( $hook === $name && ! $installed ) {
				$installed = true;
				add_action( $hook, $thrower );
			}
		};
		add_action( 'all', $all );
		$original_all = $GLOBALS['wp_filter']['all'];
		$calls        = 0;
		$benign       = static function () use ( &$calls ) {
			++$calls;
		};
		try {
			self::assertTrue( $this->domains->set_blocked( 'created-hook.example', true, $this->actor ) );
			self::assertSame( $original_all, $GLOBALS['wp_filter']['all'] );
			self::assertFalse( current_filter() );
			self::assertTrue( ! isset( $GLOBALS['wp_filter'][ $hook ] ) || false === $GLOBALS['wp_filter'][ $hook ]->current_priority() );
			remove_action( $hook, $thrower );
			add_action( $hook, $benign );
			self::assertTrue( $this->domains->set_blocked( 'created-hook.example', false, $this->actor ) );
			self::assertSame( 1, $calls );
			self::assertFalse( $GLOBALS['wp_filter'][ $hook ]->current_priority() );
			self::assertFalse( current_filter() );
		} finally {
			remove_action( 'all', $all );
			remove_action( $hook, $thrower );
			remove_action( $hook, $benign );
			if ( null !== $previous ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the exact pre-test hook fixture after deliberate initial-absence isolation.
				$GLOBALS['wp_filter'][ $hook ] = $previous;
			} else {
				unset( $GLOBALS['wp_filter'][ $hook ] );
			}
		}
	}

	/**
	 * New coverage: recursive notifications and direct Error leave outer WP clean.
	 *
	 * @return void
	 */
	public function test_recursive_throw_inside_outer_action_preserves_execution_state(): void {
		$calls    = 0;
		$listener = function () use ( &$calls ) {
			++$calls;
			if ( 1 === $calls ) {
				self::assertTrue( $this->domains->set_blocked( 'recursive.example', true, $this->actor ) );
			}
			throw new \Error( 'direct listener error' );
		};
		$outer    = function () {
			self::assertTrue( $this->domains->set_blocked( 'outer.example', true, $this->actor ) );
		};
		add_action( 'egress_eye_fixture_outer', $outer );
		add_action( 'egress_eye_block_state_changed', $listener );
		try {
			do_action( 'egress_eye_fixture_outer' );
			self::assertSame( 2, $calls );
			self::assertFalse( current_filter() );
			self::assertFalse( doing_action( 'egress_eye_fixture_outer' ) );
			self::assertFalse( doing_action( 'egress_eye_block_state_changed' ) );
			self::assertFalse( $GLOBALS['wp_filter']['egress_eye_fixture_outer']->current_priority() );
			self::assertFalse( $GLOBALS['wp_filter']['egress_eye_block_state_changed']->current_priority() );
			self::assertTrue( $this->domains->set_blocked( 'outer.example', false, $this->actor ) );
			self::assertSame( 3, $calls );
			do_action( 'egress_eye_fixture_outer' );
			self::assertSame( 4, $calls );
			self::assertFalse( current_filter() );
			self::assertFalse( $GLOBALS['wp_filter']['egress_eye_fixture_outer']->current_priority() );
		} finally {
			remove_action( 'egress_eye_fixture_outer', $outer );
			remove_action( 'egress_eye_block_state_changed', $listener );
		}
	}

	/**
	 * New coverage: ordinary successful listener add/remove/remove-all survives.
	 *
	 * @return void
	 */
	public function test_successful_listener_registration_changes_persist(): void {
		$first_count  = 0;
		$second_count = 0;
		$second       = static function () use ( &$second_count ) {
			++$second_count;
		};
		$first        = null;
		$first        = static function () use ( &$first_count, &$first, $second ) {
			++$first_count;
			remove_action( 'egress_eye_block_state_changed', $first );
			add_action( 'egress_eye_block_state_changed', $second );
		};
		add_action( 'egress_eye_block_state_changed', $first );
		try {
			self::assertTrue( $this->domains->set_blocked( 'registration.example', true, $this->actor ) );
			self::assertFalse( has_action( 'egress_eye_block_state_changed', $first ) );
			self::assertSame( 10, has_action( 'egress_eye_block_state_changed', $second ) );
			self::assertTrue( $this->domains->set_blocked( 'registration.example', false, $this->actor ) );
			self::assertSame( 1, $first_count );
			self::assertSame( 1, $second_count );
		} finally {
			remove_action( 'egress_eye_block_state_changed', $first );
			remove_action( 'egress_eye_block_state_changed', $second );
		}
		$remove_all = static function () {
			remove_all_actions( 'egress_eye_block_state_changed' );
		};
		add_action( 'egress_eye_block_state_changed', $remove_all );
		self::assertTrue( $this->domains->set_blocked( 'remove-all.example', true, $this->actor ) );
		self::assertFalse( has_action( 'egress_eye_block_state_changed' ) );
	}

	/**
	 * New coverage: throwing all listener is isolated from the current outer hook.
	 *
	 * @return void
	 */
	public function test_throwing_all_listener_preserves_all_and_outer_hook_objects(): void {
		$listener = static function ( $name ) {
			if ( 'egress_eye_block_state_changed' === $name ) {
				throw new \Error( 'all listener error' );
			}
		};
		add_action( 'all', $listener );
		$original = $GLOBALS['wp_filter']['all'];
		try {
			self::assertTrue( $this->domains->set_blocked( 'all.example', true, $this->actor ) );
			self::assertSame( $original, $GLOBALS['wp_filter']['all'] );
			self::assertFalse( $original->current_priority() );
			self::assertFalse( current_filter() );
			self::assertTrue( $this->domains->set_blocked( 'all.example', false, $this->actor ) );
			self::assertFalse( $original->current_priority() );
		} finally {
			remove_action( 'all', $listener );
		}
	}

	/**
	 * New coverage: no notification for fast or incomplete failed slow writes.
	 *
	 * @return void
	 */
	public function test_slow_notification_requires_complete_successful_record_and_released_locks(): void {
		$seen     = array();
		$listener = function ( $payload ) use ( &$seen ) {
			self::assertTrue( $this->database->outside_transaction() );
			$lock = 'egress-eye:' . substr( hash( 'sha256', $this->database->options_table() . ':events' ), 0, 48 );
			self::assertNull( $this->database->scalar( 'SELECT IS_USED_LOCK(%s)', array( $lock ) ) );
			$seen[] = $payload;
		};
		$recorder = new SlowRequestRecorderService( $this->domains, new EventRepository( $this->database, new RetentionService( $this->database ) ) );
		add_action( 'egress_eye_slow_request_recorded', $listener );
		try {
			self::assertFalse( $recorder->record_request( new SlowRequest( 'fast.example', 999999, time() ) ) );
			self::assertSame( array(), $seen );
			$this->database->execute( 'ALTER TABLE %i CHANGE duration_us fixture_duration_us bigint(20) unsigned NOT NULL', array( $this->database->table( 'events' ) ) );
			try {
				self::assertFalse( $recorder->record_request( new SlowRequest( 'failed.example', 1000000, time() ) ) );
				self::assertSame( array(), $seen );
			} finally {
				$this->database->execute( 'ALTER TABLE %i CHANGE fixture_duration_us duration_us bigint(20) unsigned NOT NULL', array( $this->database->table( 'events' ) ) );
			}
			self::assertTrue( $recorder->record_request( new SlowRequest( 'saved.example', 1000000, time(), 200, null, 'plugin', 'private-component', true ) ) );
			self::assertSame(
				array(
					array(
						'host'        => 'saved.example',
						'duration_us' => 1000000,
						'is_loopback' => true,
					),
				),
				$seen
			);
		} finally {
			remove_action( 'egress_eye_slow_request_recorded', $listener );
		}
	}
}
