<?php
/**
 * Must-use HTTP caller fixture.
 *
 * @package Egress_Eye
 */

namespace EgressEye\Tests\Fixtures;

/**
 * Initiate a real HTTP API request from must-use source.
 *
 * @return void
 */
function mu_request(): void {
	wp_remote_request( 'https://fixture.invalid/' );
}
