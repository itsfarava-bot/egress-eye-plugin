<?php
/**
 * WordPress HTTP API hook registrar.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers exactly one observer callback for each public lifecycle point.
 */
final class HookRegistrar {

	/**
	 * Observer receiving the WordPress callbacks.
	 *
	 * @var HttpObserver
	 */
	private $observer;

	/**
	 * Whether this registrar has registered its callbacks.
	 *
	 * @var bool
	 */
	private $registered = false;

	/**
	 * Create a hook registrar.
	 *
	 * @param HttpObserver $observer HTTP lifecycle observer.
	 */
	public function __construct( HttpObserver $observer ) {
		$this->observer = $observer;
	}

	/**
	 * Register the three HTTP hooks and shutdown cleanup once.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( $this->registered || ! function_exists( 'add_filter' ) ) {
			return;
		}

		add_filter( 'http_request_args', array( $this->observer, 'filterRequestArgs' ), PHP_INT_MAX, 2 );
		add_filter( 'pre_http_request', array( $this->observer, 'filterPreHttpRequest' ), PHP_INT_MAX, 3 );
		add_action( 'http_api_debug', array( $this->observer, 'actionHttpApiDebug' ), PHP_INT_MAX, 5 );
		add_action( 'shutdown', array( $this->observer, 'reset' ), PHP_INT_MAX, 0 );

		$this->registered = true;
	}

	/**
	 * Remove the callbacks registered by this instance.
	 *
	 * @return void
	 */
	public function unregister(): void {
		if ( ! $this->registered || ! function_exists( 'remove_filter' ) ) {
			return;
		}

		remove_filter( 'http_request_args', array( $this->observer, 'filterRequestArgs' ), PHP_INT_MAX );
		remove_filter( 'pre_http_request', array( $this->observer, 'filterPreHttpRequest' ), PHP_INT_MAX );
		remove_action( 'http_api_debug', array( $this->observer, 'actionHttpApiDebug' ), PHP_INT_MAX );
		remove_action( 'shutdown', array( $this->observer, 'reset' ), PHP_INT_MAX );

		$this->registered = false;
	}
}
