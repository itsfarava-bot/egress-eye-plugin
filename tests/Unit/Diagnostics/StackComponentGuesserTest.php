<?php
/**
 * Probable component path attribution, without request payloads.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Unit\Diagnostics;

use EgressEye\Diagnostics\StackComponentGuesser;
use PHPUnit\Framework\TestCase;

/**
 * Literal fixture roots keep source attribution independent of installed paths.
 */
final class StackComponentGuesserTest extends TestCase {

	/**
	 * Attribute recognized paths while keeping every result explicitly a guess.
	 *
	 * @dataProvider component_cases
	 * @param array<int,array<string,mixed>> $trace Fixture frames.
	 * @param string                         $kind Expected category.
	 * @param string|null                    $key Expected safe key.
	 * @return void
	 */
	public function test_probable_component_mapping( array $trace, string $kind, ?string $key ): void {
		$guesser = new StackComponentGuesser( '/wp/wp-content/plugins', '/wp/wp-content/mu-plugins', '/wp/wp-content/themes', '/wp', '/wp/wp-content/plugins/egress-eye' );
		$guess   = $guesser->guess( $trace );
		self::assertSame( $kind, $guess->kind );
		self::assertSame( $key, $guess->key );
		self::assertTrue( $guess->is_guess );
		if ( null !== $guess->key ) {
			self::assertLessThanOrEqual( 191, strlen( $guess->key ) );
			self::assertMatchesRegularExpression( '/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/D', $guess->key );
		}
	}

	/**
	 * Hand-derived expected keys, including ambiguous and ignored frames.
	 *
	 * @return array<string,array{array<int,array<string,mixed>>,string,string|null}>
	 */
	public function component_cases(): array {
		$unsafe_frame = array(
			'file' => '/wp/wp-content/plugins/<script>/client.php',
			'args' => array( 'secret' ),
		);
		return array(
			'plugin'    => array( array( array( 'file' => '/wp/wp-content/plugins/payments/includes/client.php' ) ), 'plugin', 'payments' ),
			'theme'     => array( array( array( 'file' => '/wp/wp-content/themes/ahura/functions.php' ) ), 'theme', 'ahura' ),
			'mu plugin' => array( array( array( 'file' => '/wp/wp-content/mu-plugins/custom.php' ) ), 'mu-plugin', 'custom.php' ),
			'wrapper'   => array( array( array( 'file' => '/wp/wp-content/plugins/shop/vendor/action-scheduler/classes/runner.php' ), array( 'file' => '/wp/wp-content/plugins/shop/tasks.php' ) ), 'plugin', 'shop' ),
			'core'      => array( array( array( 'file' => '/wp/wp-includes/cron.php' ) ), 'core', 'wordpress' ),
			'unknown'   => array( array( array( 'file' => '/outside/client.php' ) ), 'unknown', null ),
			'ignored'   => array( array( array( 'file' => '/wp/wp-content/plugins/egress-eye/src/Diagnostics/recorder.php' ), array( 'file' => '/wp/wp-includes/class-wp-http.php' ), array( 'file' => '/wp/wp-includes/http.php' ) ), 'unknown', null ),
			'ambiguous' => array( array( array( 'file' => '/wp/wp-content/plugins/one/client.php' ), array( 'file' => '/wp/wp-content/plugins/two/client.php' ) ), 'unknown', null ),
			'unsafe'    => array( array( $unsafe_frame ), 'unknown', null ),
			'long key'  => array( array( array( 'file' => '/wp/wp-content/plugins/' . str_repeat( 'a', 192 ) . '/client.php' ) ), 'unknown', null ),
		);
	}

	/**
	 * Windows separators and drive casing normalize before prefix matching.
	 *
	 * @return void
	 */
	public function test_windows_paths_and_thirty_frame_limit(): void {
		$guesser = new StackComponentGuesser( 'C:\\wp\\wp-content\\plugins', 'C:\\wp\\wp-content\\mu-plugins', 'C:\\wp\\wp-content\\themes', 'C:\\wp', 'C:\\wp\\wp-content\\plugins\\egress-eye' );
		$guess   = $guesser->guess( array( array( 'file' => 'c:\\wp\\wp-content\\plugins\\payments\\client.php' ) ) );
		self::assertSame( 'plugin', $guess->kind );
		self::assertSame( 'payments', $guess->key );
		$trace   = array_fill( 0, 30, array( 'file' => '/outside/client.php' ) );
		$trace[] = array( 'file' => 'C:/wp/wp-content/plugins/hidden/client.php' );
		self::assertSame( 'unknown', $guesser->guess( $trace )->kind );
	}
	/**
	 * Own frames are ignored even when a development install renames the folder.
	 *
	 * @return void
	 */
	public function test_renamed_own_directory_is_never_a_candidate(): void {
		$guesser = new StackComponentGuesser( '/wp/wp-content/plugins', '/wp/wp-content/mu-plugins', '/wp/wp-content/themes', '/wp', '/wp/wp-content/plugins/egress-eye-dev' );
		$trace   = array(
			array( 'file' => '/wp/wp-content/plugins/egress-eye-dev/src/Diagnostics/recorder.php' ),
			array( 'file' => '/wp/wp-content/plugins/payments/client.php' ),
		);
		self::assertSame( 'payments', $guesser->guess( $trace )->key );
		self::assertSame( 'unknown', $guesser->guess( array( $trace[0] ) )->kind );
	}

	/**
	 * Unsafe keys preserve uncertainty rather than disappearing from candidate selection.
	 *
	 * @return void
	 */
	public function test_invalid_keys_preserve_ambiguity(): void {
		$guesser = new StackComponentGuesser( '/wp/wp-content/plugins', '/wp/wp-content/mu-plugins', '/wp/wp-content/themes', '/wp', '/wp/wp-content/plugins/egress-eye' );
		foreach ( array( 'my plugin', str_repeat( 'a', 192 ) ) as $unsafe ) {
			$trace = array(
				array( 'file' => '/wp/wp-content/plugins/payments/client.php' ),
				array( 'file' => '/wp/wp-content/plugins/' . $unsafe . '/client.php' ),
			);
			self::assertSame( 'unknown', $guesser->guess( $trace )->kind );
			$trace[0]['file'] = '/wp/wp-includes/cron.php';
			self::assertSame( 'unknown', $guesser->guess( $trace )->kind );
		}
	}

	/**
	 * Windows filesystem casing does not create two distinct candidates.
	 *
	 * @return void
	 */
	public function test_windows_component_casing_is_one_identity(): void {
		$guesser = new StackComponentGuesser( 'C:/wp/wp-content/plugins', 'C:/wp/wp-content/mu-plugins', 'C:/wp/wp-content/themes', 'C:/wp', 'C:/wp/wp-content/plugins/egress-eye' );
		$guess   = $guesser->guess(
			array(
				array( 'file' => 'C:/wp/wp-content/plugins/Payments/a.php' ),
				array( 'file' => 'c:/WP/wp-content/plugins/payments/b.php' ),
			)
		);
		self::assertSame( 'plugin', $guess->kind );
		self::assertSame( 'payments', $guess->key );
	}
	/**
	 * Windows network shares use the same case-insensitive component identity.
	 *
	 * @return void
	 */
	public function test_windows_unc_paths_are_case_insensitive(): void {
		$guesser = new StackComponentGuesser( '//SERVER/share/wp/plugins', '//SERVER/share/wp/mu', '//SERVER/share/wp/themes', '//SERVER/share/wp', '//SERVER/share/wp/plugins/egress-eye' );
		$guess   = $guesser->guess(
			array(
				array( 'file' => '//server/SHARE/wp/plugins/Payments/a.php' ),
				array( 'file' => '//SERVER/share/wp/plugins/payments/b.php' ),
			)
		);
		$single  = $guesser->guess( array( array( 'file' => '//server/SHARE/wp/plugins/Payments/a.php' ) ) );
		self::assertSame( 'plugin', $single->kind );
		self::assertSame( 'payments', $single->key );
		self::assertSame( 'plugin', $guess->kind );
		self::assertSame( 'payments', $guess->key );
	}

	/**
	 * The most specific configured content root wins over a parent directory.
	 *
	 * @return void
	 */
	public function test_nested_mu_root_preserves_category_and_ambiguity(): void {
		$guesser = new StackComponentGuesser( '/wp/plugins', '/wp/plugins/mu', '/wp/themes', '/wp', '/wp/plugins/egress-eye' );
		$one     = array( 'file' => '/wp/plugins/mu/first.php' );
		$two     = array( 'file' => '/wp/plugins/mu/second.php' );
		self::assertSame( 'mu-plugin', $guesser->guess( array( $one ) )->kind );
		self::assertSame( 'first.php', $guesser->guess( array( $one ) )->key );
		self::assertSame( 'unknown', $guesser->guess( array( $one, $two ) )->kind );
	}
}
