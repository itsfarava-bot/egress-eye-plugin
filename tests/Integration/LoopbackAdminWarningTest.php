<?php
/**
 * Explicit site/loopback confirmation and real translation acceptance.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Integration;

use EgressEye\Tests\Support\AdminTestCase;

/**
 * Loopback remains a user decision with a visible additional confirmation.
 */
final class LoopbackAdminWarningTest extends AdminTestCase {
	/**
	 * RED to GREEN: missing confirmation cannot block a site host or localhost.
	 *
	 * @return void
	 */
	public function test_loopback_warning_extra_confirmation_and_unblock_button(): void {
		foreach ( array( wp_parse_url( home_url(), PHP_URL_HOST ), '127.0.0.1', 'localhost', '::1' ) as $host ) {
			$id = $this->domains->ensure_host( $host );
			self::assertStringContainsString( 'Blocking this site or loopback host can interrupt cron and other essential tasks.', $this->page() );
			self::assertStringContainsString( 'confirm_loopback', $this->page() );
			$this->post_action( $this->fields( $id ) );
			self::assertSame( 0, $this->state( $id ) );
			$fields                     = $this->fields( $id );
			$fields['confirm_loopback'] = '1';
			$this->post_action( $fields );
			self::assertSame( 1, $this->state( $id ) );
			self::assertStringContainsString( 'Unblock', $this->page() );
			$this->post_action( $this->fields( $id, 'unblock' ) );
			self::assertSame( 0, $this->state( $id ) );
		}
	}

	/**
	 * New coverage: installation host differing from home receives the warning.
	 *
	 * @return void
	 */
	public function test_installation_host_is_loopback_without_inheriting_subdomains(): void {
		$detector = new \EgressEye\Http\LoopbackDetector( new \EgressEye\Http\HostNormalizer(), 'https://home.example', 'https://installation.example/wp' );
		self::assertTrue( $detector->isLoopback( 'installation.example' ) );
		self::assertTrue( $detector->isLoopback( 'home.example' ) );
		self::assertFalse( $detector->isLoopback( 'sub.installation.example' ) );
	}

	/**
	 * RED to GREEN: shipped compiled Persian catalog actually loads in WordPress.
	 *
	 * @return void
	 */
	public function test_persian_catalog_and_rtl_page(): void {
		$id = $this->domains->ensure_host( 'translated.example' );
		$this->database->execute( 'UPDATE %i SET slow_count = %d WHERE id = %d', array( $this->database->table( 'domains' ), 1, $id ) );
		// The test core has no language packs. Admit the fixture locale only;
		// plugin translations are still loaded from the real compiled catalog.
		$available = new \ReflectionProperty( $GLOBALS['wp_locale_switcher'], 'available_languages' );
		$available->setAccessible( true );
		$previous = $available->getValue( $GLOBALS['wp_locale_switcher'] );
		$available->setValue( $GLOBALS['wp_locale_switcher'], array_merge( $previous, array( 'fa_IR' ) ) );
		self::assertTrue( switch_to_locale( 'fa_IR' ) );
		$GLOBALS['wp_locale']->text_direction = 'rtl';
		try {
			load_plugin_textdomain( 'egress-eye', false, 'egress-eye/languages' );
			$html = $this->page();
			self::assertStringContainsString( 'حدس', $html );
			self::assertStringContainsString( 'dir="rtl"', $html );
			self::assertStringContainsString( 'قطع کن', $html );
		} finally {
			restore_previous_locale();
			$available->setValue( $GLOBALS['wp_locale_switcher'], $previous );
		}
	}
}
