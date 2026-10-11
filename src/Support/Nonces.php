<?php
/**
 * Desired-state and stored-id nonce scope.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prevents a block form nonce from authorizing an unblock request.
 */
final class Nonces {
	/**
	 * Name an exact action.
	 *
	 * @param int    $id      Stored domain identifier.
	 * @param string $desired Block or unblock.
	 * @return string
	 */
	public static function action( int $id, string $desired ): string {
		return 'egress_eye_toggle_' . $id . '_' . $desired;
	}
}
