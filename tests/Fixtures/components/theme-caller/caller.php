<?php
/**
 * Theme HTTP caller fixture.
 *
 * @package Egress_Eye
 */

namespace EgressEye\Tests\Fixtures;

/**
 * Initiate a real HTTP API request from theme source.
 *
 * @return void
 */
function theme_request(): void {
	wp_remote_post( 'https://fixture.invalid/', array( 'body' => 'fixture-secret' ) );
}
