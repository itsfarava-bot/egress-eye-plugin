<?php
/**
 * Site/user-bound flash outcomes, capacity and full uninstall cleanup.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Integration;

use EgressEye\Tests\Support\AdminTestCase;
use EgressEye\Admin\ActionNotice;

/**
 * Arbitrary GET cannot issue a status and expired outcomes are silently removed.
 */
final class ActionNoticeTest extends AdminTestCase {
	/**
	 * New coverage: one slot, user/site scope, expiration and one-use outcomes.
	 *
	 * @return void
	 */
	public function test_outcome_is_bound_to_user_site_and_expiration(): void {
		$first  = ActionNotice::issue( 'blocked' );
		$second = ActionNotice::issue( 'unblocked' );
		$_GET   = array( 'egress_eye_notice' => $first );
		self::assertSame( '', ActionNotice::consume() );
		$_GET = array( 'egress_eye_notice' => $second );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::assertSame( '', ActionNotice::consume() );
		wp_set_current_user( $this->actor );
		self::assertSame( 'unblocked', ActionNotice::consume() );
		self::assertSame( '', ActionNotice::consume() );
		$token            = ActionNotice::issue( 'blocked' );
		$flash            = get_user_meta( $this->actor, ActionNotice::meta_key(), true );
		$flash['expires'] = time() - 1;
		update_user_meta( $this->actor, ActionNotice::meta_key(), $flash );
		$_GET = array( 'egress_eye_notice' => $token );
		self::assertSame( '', ActionNotice::consume() );
		self::assertSame( '', get_user_meta( $this->actor, ActionNotice::meta_key(), true ) );
		global $wpdb;
		$prefix = $wpdb->prefix;
		$key    = ActionNotice::meta_key();
		try {
			$wpdb->prefix = 'other_fixture_';
			self::assertNotSame( $key, ActionNotice::meta_key() );
		} finally {
			$wpdb->prefix = $prefix;
		}
	}

	/**
	 * New coverage: full capacity is actionable only inside the plugin page.
	 *
	 * @return void
	 */
	public function test_capacity_notice_only_when_actual_domain_cap_is_full(): void {
		self::assertStringNotContainsString( 'The 500-host capacity is full.', $this->page() );
		for ( $index = 0; $index < 500; ++$index ) {
			$this->database->execute( 'INSERT INTO %i (host, is_blocked, created_at, updated_at) VALUES (%s, %d, %s, %s)', array( $this->database->table( 'domains' ), 'capacity-' . $index . '.example', 1, gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s' ) ) );
		}
		self::assertStringContainsString( 'The 500-host capacity is full.', $this->page() );
		self::assertStringContainsString( 'Unblocking does not immediately free a slot.', $this->page() );
		self::assertFalse( has_action( 'admin_notices', array( ActionNotice::class, 'consume' ) ) );
	}

	/**
	 * New coverage: uninstall wipes all users' current-site slots only.
	 *
	 * @return void
	 */
	public function test_uninstall_removes_all_current_site_outcomes(): void {
		$key = ActionNotice::meta_key();
		update_user_meta( $this->actor, $key, array( 'outcome' => 'blocked' ) );
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $other, $key, array( 'outcome' => 'unblocked' ) );
		update_user_meta( $this->actor, '_egress_eye_action_notice_other_site', 'other-site' );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'egress-eye/egress-eye.php' );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';
		wp_cache_delete( $this->actor, 'user_meta' );
		wp_cache_delete( $other, 'user_meta' );
		self::assertSame( '', get_user_meta( $this->actor, $key, true ) );
		self::assertSame( '', get_user_meta( $other, $key, true ) );
		self::assertSame( 'other-site', get_user_meta( $this->actor, '_egress_eye_action_notice_other_site', true ) );
	}
}
