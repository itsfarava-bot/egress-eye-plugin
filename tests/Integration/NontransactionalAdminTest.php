<?php
/**
 * Nontransactional tables retain diagnostics while denying state changes.
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
 * Uses actual MyISAM tables, the installed handler and the native page.
 */
final class NontransactionalAdminTest extends AdminTestCase {
	/**
	 * New coverage for storage; RED to GREEN for clear explanatory UI.
	 *
	 * @dataProvider engines_and_states
	 * @param string $table   Table using MyISAM.
	 * @param bool   $blocked Initial state before changing the engine.
	 * @return void
	 */
	public function test_myisam_keeps_recording_and_display_but_refuses_both_actions( string $table, bool $blocked ): void {
		$id = $this->domains->ensure_host( 'myisam.example' );
		if ( $blocked ) {
			self::assertTrue( $this->domains->set_blocked( 'myisam.example', true, $this->actor ) );
		}
		$this->database->execute( 'ALTER TABLE %i ENGINE = MyISAM', array( $this->database->table( $table ) ) );
		try {
			$recorder = new SlowRequestRecorderService( $this->domains, new EventRepository( $this->database, new RetentionService( $this->database ) ) );
			self::assertTrue( $recorder->record_request( new SlowRequest( 'myisam.example', 1500000, time() ) ) );
			self::assertSame( 1, (int) $this->database->scalar( 'SELECT COUNT(*) FROM %i', array( $this->database->table( 'events' ) ) ) );
			$destination = $this->post_action( $this->fields( $id, $blocked ? 'unblock' : 'block' ) );
			self::assertSame( (int) $blocked, $this->state( $id ) );
			self::assertSame( (int) $blocked, (int) $this->database->scalar( 'SELECT COUNT(*) FROM %i', array( $this->database->table( 'audits' ) ) ) );
			parse_str( (string) wp_parse_url( $destination, PHP_URL_QUERY ), $query );
			$_GET = $query;
			$html = $this->page();
			self::assertStringContainsString( 'myisam.example', $html );
			self::assertStringContainsString( '1.500', $html );
			self::assertStringContainsString( 'The action was not saved; the host status is unchanged.', $html );
			self::assertStringContainsString( 'MyISAM tables can record and display diagnostics. Blocking and unblocking require InnoDB to save the change and its history together. Your hosting provider can help with the table format.', $html );
		} finally {
			$this->database->execute( 'ALTER TABLE %i ENGINE = InnoDB', array( $this->database->table( $table ) ) );
		}
	}

	/**
	 * Each transaction participant and each direction must be protected.
	 *
	 * @return array<string,array{string,bool}>
	 */
	public function engines_and_states(): array {
		return array(
			'domains block'   => array( 'domains', false ),
			'domains unblock' => array( 'domains', true ),
			'audits block'    => array( 'audits', false ),
			'audits unblock'  => array( 'audits', true ),
		);
	}
}
