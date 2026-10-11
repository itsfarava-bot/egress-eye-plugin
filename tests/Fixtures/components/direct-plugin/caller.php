<?php
/**
 * Direct plugin HTTP caller fixture.
 *
 * @package Egress_Eye
 */

namespace EgressEye\Tests\Fixtures;

/**
 * Use the real HTTP API with a test-only local response.
 *
 * @return void
 */
function direct_plugin_request(): void {
	wp_remote_get( 'https://fixture.invalid/private?token=fixture-secret', array( 'headers' => array( 'Authorization' => 'fixture-secret' ) ) );
}
