<?php
/**
 * Storage lifecycle service owned by the plugin composition root.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the explicitly requested schema and maintenance lifecycle hooks.
 */
final class StorageRuntime {

	/**
	 * Schema lifecycle.
	 *
	 * @var SchemaManager
	 */
	private $schema;
	/**
	 * Optional cron maintenance.
	 *
	 * @var RetentionService
	 */
	private $retention;

	/**
	 * Create storage services without registering an HTTP observer.
	 *
	 * @param Database $database WordPress database boundary.
	 */
	public function __construct( Database $database ) {
		$this->schema    = new SchemaManager( $database );
		$this->retention = new RetentionService( $database );
	}

	/**
	 * Register the single-site storage lifecycle once.
	 *
	 * @param string $plugin_file Main plugin path.
	 * @return void
	 */
	public static function register( string $plugin_file ): void {
		\EgressEye\Plugin::boot( $plugin_file );
	}

	/**
	 * Register this composition-owned service once during plugin boot.
	 *
	 * @param string $plugin_file Main plugin path.
	 * @return void
	 */
	public function register_hooks( string $plugin_file ): void {
		register_activation_hook( $plugin_file, array( $this->schema, 'activate' ) );
		add_action( 'plugins_loaded', array( $this, 'ensure_storage' ), 10, 0 );
		add_action( 'admin_init', array( $this, 'ensure_storage' ), 10, 0 );
		add_action( RetentionService::CRON_HOOK, array( $this->retention, 'run_maintenance' ), 10, 0 );
	}

	/**
	 * Compare the autoload version and add a fallback event only when absent.
	 *
	 * @return void
	 */
	public function ensure_storage(): void {
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		try {
			if ( $this->schema->ensure_current() && ! wp_next_scheduled( RetentionService::CRON_HOOK ) ) {
				wp_schedule_event( time() + 3600, 'hourly', RetentionService::CRON_HOOK );
			}
		} catch ( \Throwable $exception ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Optional diagnostic lifecycle failures must not interrupt WordPress boot.
			// Writes remain bounded without cron; a later lifecycle call can retry setup.
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}
}
