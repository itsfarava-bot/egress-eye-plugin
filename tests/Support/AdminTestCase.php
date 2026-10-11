<?php
/**
 * Real WordPress admin boundary fixture.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Tests\Support;

use EgressEye\Persistence\Database;
use EgressEye\Persistence\DomainRepository;
use EgressEye\Persistence\RetentionService;
use EgressEye\Persistence\SchemaManager;

/**
 * Invokes installed hooks and captures real WordPress redirects without exiting.
 */
abstract class AdminTestCase extends \WP_UnitTestCase {
	/**
	 * Fixture dependency or state.
	 *
	 * @var Database
	 */
	protected $database;
	/**
	 * Fixture dependency or state.
	 *
	 * @var DomainRepository
	 */
	protected $domains;
	/**
	 * Fixture dependency or state.
	 *
	 * @var int
	 */
	protected $actor;
	/**
	 * Fixture dependency or state.
	 *
	 * @var array<string,mixed>
	 */
	private $saved_server;

	/**
	 * Fresh persistent storage for actual transactions.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		global $wpdb;
		$this->saved_server = $_SERVER;
		$this->database     = new Database( $wpdb );
		$this->database->execute( 'COMMIT' );
		$this->database->execute( 'SET autocommit = %d', array( 1 ) );
		foreach ( array( 'events', 'audits', 'domains' ) as $table ) {
			$this->database->execute( 'DROP TABLE IF EXISTS %i', array( $this->database->table( $table ) ) );
		}
		foreach ( SchemaManager::option_names() as $option ) {
			delete_option( $option );
		}
		self::assertTrue( ( new SchemaManager( $this->database ) )->install() );
		$this->domains = new DomainRepository( $this->database, new RetentionService( $this->database ) );
		$this->actor   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->actor );
		// Native wp-admin/menu.php establishes the Tools parent before admin_menu.
		$GLOBALS['admin_page_hooks']['tools.php'] = 'tools';
		set_current_screen( 'tools_page_egress-eye' );
	}

	/**
	 * Restore request globals and screen.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		$_SERVER  = $this->saved_server;
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Dispatch through the real registered action, preserving absent-hook RED.
	 *
	 * @param array<string,mixed> $fields Posted fixture values.
	 * @param string              $method Request method.
	 * @return string Redirect destination or denial marker.
	 * @throws \RuntimeException For fixture redirect control flow only.
	 */
	protected function post_action( array $fields, string $method = 'POST' ): string {
		$_SERVER['REQUEST_METHOD'] = $method;
		$_POST                     = $fields;
		$_REQUEST                  = $fields;
		$destination               = '';
		$redirect                  = static function ( $location ) use ( &$destination ) {
			$destination = $location;
			throw new \RuntimeException( 'fixture-redirect' );
		};
		add_filter( 'wp_redirect', $redirect );
		try {
			do_action( 'admin_post_egress_eye_toggle_domain' );
		} catch ( \WPDieException $exception ) {
			$destination = 'denied';
		} catch ( \RuntimeException $exception ) {
			if ( 'fixture-redirect' !== $exception->getMessage() ) {
				throw $exception;
			}
		} finally {
			remove_filter( 'wp_redirect', $redirect );
		}
		return $destination;
	}

	/**
	 * Issue a real nonce for this user and requested stored id/state.
	 *
	 * @param int    $id      Domain identifier.
	 * @param string $desired Block or unblock.
	 * @return array<string,mixed>
	 */
	protected function fields( int $id, string $desired = 'block' ): array {
		return array(
			'domain_id' => (string) $id,
			'desired'   => $desired,
			'_wpnonce'  => wp_create_nonce( 'egress_eye_toggle_' . $id . '_' . $desired ),
		);
	}

	/**
	 * Render via the installed menu's registered callback.
	 *
	 * @return string
	 */
	protected function page(): string {
		do_action( 'admin_menu' );
		ob_start();
		try {
			do_action( 'tools_page_egress-eye' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Core generates this menu callback name from the plugin slug.
		} catch ( \WPDieException $exception ) {
			echo 'denied';
		}
		return (string) ob_get_clean();
	}

	/**
	 * Read exact fixture state.
	 *
	 * @param int $id Domain identifier.
	 * @return int
	 */
	protected function state( int $id ): int {
		return (int) $this->database->scalar( 'SELECT is_blocked FROM %i WHERE id = %d', array( $this->database->table( 'domains' ), $id ) );
	}
	/**
	 * Invoke an independent PHP request with no development autoloader.
	 *
	 * @param string      $url         Local fixture destination.
	 * @param string      $mode        Early, normal or throw.
	 * @param string|null $plugin_file Optional extracted production entry.
	 * @return array<string,mixed>
	 */
	protected function native_request( string $url, string $mode = 'normal', ?string $plugin_file = null ): array {
		$command = array( PHP_BINARY, dirname( __DIR__ ) . '/Fixtures/runtime-worker.php', $plugin_file ?? dirname( __DIR__, 2 ) . '/egress-eye.php', $url, $mode );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Test-only independent PHP request, not production execution.
		$process = proc_open( $command, array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Test process resource boundary.
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error  = stream_get_contents( $pipes[2] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Test process resource boundary.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Test process resource boundary.
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $error );
		self::assertSame( '', $error );
		self::assertMatchesRegularExpression( '/RESULT ([^\n]+)\n/', $output );
		preg_match( '/RESULT ([^\n]+)\n/', $output, $match );
		$result = json_decode( $match[1], true );
		self::assertIsArray( $result );
		return $result;
	}
}
