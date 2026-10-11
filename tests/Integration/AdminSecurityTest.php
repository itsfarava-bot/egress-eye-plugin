<?php
/**
 * Real installed authorization and escaped admin screen boundaries.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Integration;

use EgressEye\Tests\Support\AdminTestCase;

/**
 * All mutations require a valid POST, actor, exact id and state-specific nonce.
 */
final class AdminSecurityTest extends AdminTestCase {
	/**
	 * New coverage: denied requests cannot alter stored state or audit history.
	 *
	 * @return void
	 */
	public function test_get_missing_invalid_nonce_and_tampered_id_are_denied(): void {
		$id = $this->domains->ensure_host( 'secure.example' );
		foreach ( array(
			array( $this->fields( $id ), 'GET' ),
			array(
				array(
					'domain_id' => $id,
					'desired'   => 'block',
				),
				'POST',
			),
			array(
				array(
					'domain_id' => $id,
					'desired'   => 'block',
					'_wpnonce'  => 'invalid',
				),
				'POST',
			),
			array( $this->fields( $id + 1000 ), 'POST' ),
			array( array_merge( $this->fields( $id ), array( 'domain_id' => $id . 'garbage' ) ), 'POST' ),
			array( array_merge( $this->fields( $id ), array( 'desired' => 'unblock' ) ), 'POST' ),
		) as $case ) {
			$this->post_action( $case[0], $case[1] );
			self::assertSame( 0, $this->state( $id ) );
			self::assertSame( 0, (int) $this->database->scalar( 'SELECT COUNT(*) FROM %i', array( $this->database->table( 'audits' ) ) ) );
		}
	}

	/**
	 * New coverage for handler and renderer authorization.
	 *
	 * @return void
	 */
	public function test_manage_options_required_in_handler_and_page(): void {
		$id = $this->domains->ensure_host( 'private.example' );
		// Register the real callback while authorized, then invoke it as a subscriber.
		do_action( 'admin_menu' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->post_action( $this->fields( $id ) );
		self::assertSame( 0, $this->state( $id ) );
		self::assertSame( 'denied', $this->page() );
	}

	/**
	 * New coverage: invalid and missing nonces reach the actual nonce boundary.
	 *
	 * @return void
	 */
	public function test_valid_id_and_state_with_invalid_or_missing_nonce_are_denied(): void {
		$id = $this->domains->ensure_host( 'invalid-nonce.example' );
		foreach ( array( 'invalid', null ) as $nonce ) {
			$fields = $this->fields( $id );
			if ( null === $nonce ) {
				unset( $fields['_wpnonce'] );
			} else {
				$fields['_wpnonce'] = $nonce;
			}
			self::assertSame( 'denied', $this->post_action( $fields ) );
			self::assertSame( 0, $this->state( $id ) );
			self::assertSame( 0, (int) $this->database->scalar( 'SELECT COUNT(*) FROM %i', array( $this->database->table( 'audits' ) ) ) );
		}
		$this->post_action( $this->fields( $id ) );
		self::assertSame( 1, $this->state( $id ) );
	}

	/**
	 * RED to GREEN: forged unblock nonce cannot pass as an idempotent no-op.
	 *
	 * @return void
	 */
	public function test_block_nonce_cannot_authorize_unblock_and_denials_are_explicit(): void {
		$id = $this->domains->ensure_host( 'nonce-state.example' );
		self::assertTrue( $this->domains->set_blocked( 'nonce-state.example', true, $this->actor ) );
		$fields            = $this->fields( $id );
		$fields['desired'] = 'unblock';
		self::assertSame( 'denied', $this->post_action( $fields ) );
		self::assertSame( 1, $this->state( $id ) );
		self::assertSame( 1, (int) $this->database->scalar( 'SELECT COUNT(*) FROM %i', array( $this->database->table( 'audits' ) ) ) );
		self::assertSame( 'denied', $this->post_action( $this->fields( $id ), 'GET' ) );
		self::assertSame(
			'denied',
			$this->post_action(
				array(
					'domain_id' => (string) $id,
					'desired'   => 'block',
				)
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		self::assertSame( 'denied', $this->post_action( $this->fields( $id ) ) );
		self::assertSame( 1, $this->state( $id ) );
	}

	/**
	 * RED to GREEN and sensitivity: malicious persisted component never renders HTML.
	 *
	 * @return void
	 */
	public function test_native_page_escapes_stored_values_and_has_exact_host_disclosures(): void {
		$id = $this->domains->ensure_host( 'visible.example' );
		$this->database->execute( 'UPDATE %i SET slow_count = %d, slow_duration_sum_us = %d, last_duration_us = %d, last_component_key = %s WHERE id = %d', array( $this->database->table( 'domains' ), 2, 5000000, 3000000, '<img src=x onerror=alert(1)>', $id ) );
		$html = $this->page();
		self::assertStringContainsString( 'visible.example', $html );
		self::assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
		self::assertStringNotContainsString( '<img src=x', $html );
		self::assertStringContainsString( 'Guess', $html );
		self::assertStringContainsString( 'Counts and averages include only slow requests (at least 1 second).', $html );
		self::assertStringContainsString( 'Only the exact host is blocked; subdomains and DNS-to-IP mappings are not inherited. Redirect destinations are not guaranteed.', $html );
		self::assertStringContainsString( '2.500', $html );
		self::assertStringContainsString( 'admin-post.php', $html );
		self::assertStringContainsString( 'Block', $html );
		self::assertStringNotContainsString( 'last_sample_key', $html );
	}

	/**
	 * New coverage: path-shaped corrupt component values are never disclosed.
	 *
	 * @return void
	 */
	public function test_absolute_component_paths_are_not_disclosed(): void {
		$id = $this->domains->ensure_host( 'paths.example' );
		foreach ( array( '/srv/private/component.php', 'C:/private/component.php', '\\\\private\\share\\component.php' ) as $path ) {
			$this->database->execute( 'UPDATE %i SET last_component_key = %s WHERE id = %d', array( $this->database->table( 'domains' ), $path, $id ) );
			self::assertStringNotContainsString( $path, $this->page() );
		}
	}

	/**
	 * RED to GREEN: arbitrary GET cannot forge success; authenticated POST can.
	 *
	 * @return void
	 */
	public function test_notices_require_server_issued_authenticated_outcome(): void {
		$_GET = array(
			'egress_eye_notice' => 'blocked',
			'notice'            => '<script>fixture</script>',
		);
		self::assertStringNotContainsString( 'Host blocked.', $this->page() );
		$id          = $this->domains->ensure_host( 'notice.example' );
		$destination = $this->post_action( $this->fields( $id ) );
		parse_str( (string) wp_parse_url( $destination, PHP_URL_QUERY ), $query );
		$_GET = $query;
		self::assertStringContainsString( 'Host blocked.', $this->page() );
		self::assertStringNotContainsString( 'Host blocked.', $this->page() );
	}

	/**
	 * RED to GREEN: local stylesheet is restricted to its page and has no scripts.
	 *
	 * @return void
	 */
	public function test_assets_are_local_and_page_scoped(): void {
		wp_dequeue_style( 'egress-eye-admin' );
		do_action( 'admin_enqueue_scripts', 'dashboard' );
		self::assertFalse( wp_style_is( 'egress-eye-admin', 'enqueued' ) );
		do_action( 'admin_enqueue_scripts', 'tools_page_egress-eye' );
		self::assertTrue( wp_style_is( 'egress-eye-admin', 'enqueued' ) );
		self::assertStringEndsWith( '/assets/admin.css', $GLOBALS['wp_styles']->registered['egress-eye-admin']->src );
		self::assertFalse( wp_script_is( 'egress-eye-admin', 'enqueued' ) );
	}
}
