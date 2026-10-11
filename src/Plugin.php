<?php
/**
 * The Egress Eye plugin composition root.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EgressEye\Contracts\Clock;
use EgressEye\Contracts\ContextStore;
use EgressEye\Contracts\ManualBlocker;
use EgressEye\Contracts\SlowRequestRecorder;
use EgressEye\Http\HookRegistrar;
use EgressEye\Http\HttpObserver;

/**
 * Provides side-effect-free plugin identity and dependency composition.
 */
final class Plugin {

	/**
	 * The plugin version exposed to the bootstrap.
	 *
	 * @var string
	 */
	public const VERSION = '1.0.0';

	/**
	 * The single composition root for this PHP request.
	 *
	 * @var self|null
	 */
	private static $instance;
	/**
	 * Main plugin path for translation catalog registration.
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Assemble inert services, then register the installed production runtime.
	 *
	 * @param string $plugin_file Main plugin path.
	 * @param \wpdb  $wpdb        Current site's connection.
	 */
	private function __construct( string $plugin_file, \wpdb $wpdb ) {
		$this->plugin_file = $plugin_file;
		$database          = new \EgressEye\Persistence\Database( $wpdb );
		$storage           = new \EgressEye\Persistence\StorageRuntime( $database );
		$storage->register_hooks( $plugin_file );
		if ( is_multisite() ) {
			return;
		}
		$retention  = new \EgressEye\Persistence\RetentionService( $database );
		$domains    = new \EgressEye\Persistence\DomainRepository( $database, $retention );
		$events     = new \EgressEye\Persistence\EventRepository( $database, $retention );
		$audits     = new \EgressEye\Persistence\AuditRepository( $database, $retention );
		$normalizer = new \EgressEye\Http\HostNormalizer();
		$loopback   = new \EgressEye\Http\LoopbackDetector( $normalizer, home_url(), site_url() );
		$clock      = new \EgressEye\Http\MonotonicClock();
		$store      = new \EgressEye\Http\InMemoryContextStore( $clock );
		$guard      = new \EgressEye\Http\BlockedHostGuard( $normalizer, $domains );
		$recorder   = new \EgressEye\Diagnostics\SlowRequestRecorderService( $domains, $events, null, null, $loopback );
		$registrar  = self::create_http_registrar( $clock, $store, $guard, $recorder );
		$page       = new \EgressEye\Admin\AdminPage( $domains, $audits, new \EgressEye\Admin\DomainTableRenderer( $loopback ), new \EgressEye\Admin\AuditTableRenderer() );
		$menu       = new \EgressEye\Admin\AdminMenu( $page, $plugin_file );
		$action     = new \EgressEye\Admin\ToggleDomainAction( $domains, $loopback );
		$registrar->register();
		add_action( 'init', array( $this, 'load_translations' ), 10, 0 );
		add_action( 'admin_menu', array( $menu, 'register' ), 10, 0 );
		add_action( 'admin_enqueue_scripts', array( $menu, 'enqueue' ), 10, 1 );
		add_action( 'admin_post_egress_eye_toggle_domain', array( $action, 'handle' ), 10, 0 );
	}

	/**
	 * Boot exactly one root and one guard per PHP request, without an HTTP call.
	 *
	 * @param string $plugin_file Main plugin path.
	 * @return void
	 */
	public static function boot( string $plugin_file ): void {
		global $wpdb;
		if ( null === self::$instance && $wpdb instanceof \wpdb ) {
			self::$instance = new self( $plugin_file, $wpdb );
		}
	}

	/**
	 * Register the bundled catalog at the normal i18n boundary.
	 *
	 * @return void
	 */
	public function load_translations(): void {
		load_plugin_textdomain( 'egress-eye', false, dirname( plugin_basename( $this->plugin_file ) ) . '/languages' );
	}

	/**
	 * Build the HTTP hook registrar without registering hooks automatically.
	 *
	 * The entry point can call register() after all production dependencies are
	 * available. Keeping construction side-effect-free prevents early boot work.
	 *
	 * @param Clock               $clock          Monotonic clock.
	 * @param ContextStore        $context_store  In-request context store.
	 * @param ManualBlocker       $manual_blocker Manual block provider.
	 * @param SlowRequestRecorder $recorder       Slow request recorder.
	 * @return HookRegistrar
	 */
	public static function create_http_registrar(
		Clock $clock,
		ContextStore $context_store,
		ManualBlocker $manual_blocker,
		SlowRequestRecorder $recorder
	): HookRegistrar {
		return new HookRegistrar(
			new HttpObserver( $clock, $context_store, $manual_blocker, $recorder )
		);
	}
}
