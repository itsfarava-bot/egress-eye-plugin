<?php
/**
 * Shared administrator permission boundary.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uses the existing WordPress capability without creating roles or settings.
 */
final class Capabilities {
	/** Required existing capability. */
	public const MANAGE = 'manage_options';

	/**
	 * Explicitly reject unauthorized rendering or actions.
	 *
	 * @return bool
	 */
	public static function require_access(): bool {
		if ( current_user_can( self::MANAGE ) ) {
			return true;
		}
		wp_die( esc_html__( 'You are not allowed to manage Egress Eye.', 'egress-eye' ), '', array( 'response' => 403 ) );
		return false;
	}
}
