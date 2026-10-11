<?php
/**
 * Separate plugin dispatch fixtures.
 *
 * @package Egress_Eye
 */

namespace EgressEye\Tests\Fixtures;

/**
 * Dispatch a stored WP-Cron event through the real WordPress hook dispatcher.
 *
 * @return void
 */
function cron_wrapper_request(): void {
	$hook = 'egress_eye_fixture_cron';
	add_action( $hook, __NAMESPACE__ . '\\direct_plugin_request' );
	wp_schedule_single_event( time() - 1, $hook );
	try {
		foreach ( wp_get_ready_cron_jobs() as $timestamp => $hooks ) {
			if ( ! isset( $hooks[ $hook ] ) ) {
				continue;
			}
			foreach ( $hooks[ $hook ] as $event ) {
				wp_unschedule_event( $timestamp, $hook, $event['args'] );
				do_action_ref_array( $hook, $event['args'] );
			}
		}
	} finally {
		remove_action( $hook, __NAMESPACE__ . '\\direct_plugin_request' );
		wp_clear_scheduled_hook( $hook );
	}
}

/**
 * Invoke a theme HTTP callback through a separate plugin.
 *
 * @return void
 */
function wrapped_theme_request(): void {
	theme_request();
}

/**
 * Invoke a must-use HTTP callback through a separate plugin.
 *
 * @return void
 */
function wrapped_mu_request(): void {
	mu_request();
}
