<?php
/**
 * Native Tools menu and local page-scoped stylesheet.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EgressEye\Support\Capabilities;

/**
 * Registers only the requested admin page, with no frontend assets or JS.
 */
final class AdminMenu {
	/**
	 * Authorized screen renderer.
	 *
	 * @var AdminPage
	 */
	private $page;
	/**
	 * Main plugin file used for its local stylesheet URL.
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Construct without registering menu hooks.
	 *
	 * @param AdminPage $page        Screen renderer.
	 * @param string    $plugin_file Main plugin file.
	 */
	public function __construct( AdminPage $page, string $plugin_file ) {
		$this->page        = $page;
		$this->plugin_file = $plugin_file;
	}

	/**
	 * Register the native capability-restricted Tools page.
	 *
	 * @return void
	 */
	public function register(): void {
		add_management_page( __( 'Egress Eye', 'egress-eye' ), __( 'Egress Eye', 'egress-eye' ), Capabilities::MANAGE, 'egress-eye', array( $this->page, 'render' ) );
	}

	/**
	 * Enqueue only the readable stylesheet on this screen.
	 *
	 * @param string $hook Current native screen hook.
	 * @return void
	 */
	public function enqueue( string $hook ): void {
		if ( 'tools_page_egress-eye' === $hook && current_user_can( Capabilities::MANAGE ) ) {
			wp_enqueue_style( 'egress-eye-admin', plugins_url( 'assets/admin.css', $this->plugin_file ), array(), \EgressEye\Plugin::VERSION );
		}
	}
}
