<?php
/**
 * Read-only committed event notifications with listener isolation.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uses disposable hook execution objects so a thrown listener cannot strand WP.
 */
final class Notifications {
	/**
	 * Notify only after a complete slow record and released persistence locks.
	 *
	 * @param string $host        Canonical host.
	 * @param int    $duration_us Elapsed microseconds.
	 * @param bool   $loopback    Loopback marker.
	 * @return void
	 */
	public static function slow_recorded( string $host, int $duration_us, bool $loopback ): void {
		self::dispatch(
			'egress_eye_slow_request_recorded',
			array(
				'host'        => $host,
				'duration_us' => $duration_us,
				'is_loopback' => $loopback,
			)
		);
	}

	/**
	 * Notify an actual committed transition after both advisory locks release.
	 *
	 * @param string $host           Canonical host.
	 * @param int    $user_id        Actor identifier.
	 * @param bool   $previous_state Authoritative transaction state.
	 * @param bool   $new_state      Committed desired state.
	 * @return void
	 */
	public static function block_changed( string $host, int $user_id, bool $previous_state, bool $new_state ): void {
		self::dispatch(
			'egress_eye_block_state_changed',
			array(
				'host'           => $host,
				'user_id'        => max( 0, $user_id ),
				'previous_state' => $previous_state,
				'new_state'      => $new_state,
			)
		);
	}

	/**
	 * Contain Throwable while preserving the identities of active outer hooks.
	 *
	 * WP_Hook and do_action do not unwind execution state when a callback throws.
	 * Only the notification and all-hook execution objects are temporarily cloned.
	 * Successful callback registrations propagate through normal WP_Hook methods.
	 *
	 * @param string                        $hook    Fixed notification name.
	 * @param array<string,int|string|bool> $payload Small sanitized values.
	 * @return void
	 */
	private static function dispatch( string $hook, array $payload ): void {
		/**
		 * WordPress hook execution objects.
		 *
		 * @var array<string,\WP_Hook> $wp_filter
		 */
		/**
		 * WordPress action execution stack.
		 *
		 * @var array<int,string> $wp_current_filter
		 */
		global $wp_filter, $wp_current_filter;
		$stack     = $wp_current_filter;
		$originals = array();
		$success   = false;
		foreach ( array( $hook, 'all' ) as $name ) {
			$originals[ $name ] = $wp_filter[ $name ] ?? null;
			if ( isset( $wp_filter[ $name ] ) ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Disposable clone is limited to our named notification and all; preserves active original identity.
				$wp_filter[ $name ] = clone $wp_filter[ $name ];
			}
		}
		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Private dispatch receives only the two fixed egress_eye_ notification names above.
			do_action( $hook, $payload );
			$success = true;
		} catch ( \Throwable $exception ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Notifications cannot undo a committed diagnostic or manual action.
			// Discard interrupted hook execution state and retain the outer call stack.
		} finally {
			foreach ( $originals as $name => $original ) {
				if ( null === $original ) {
					if ( ! $success ) {
						// A listener may have created the named hook during all dispatch.
						// Discard only that new interrupted execution object.
						unset( $wp_filter[ $name ] );
					}
					continue;
				}
				if ( $success ) {
					$final = isset( $wp_filter[ $name ] ) ? $wp_filter[ $name ]->callbacks : array();
					foreach ( $original->callbacks as $priority => $callbacks ) {
						foreach ( $callbacks as $key => $callback ) {
							if ( ! isset( $final[ $priority ][ $key ] ) ) {
								$original->remove_filter( $name, $callback['function'], $priority );
							}
						}
					}
					foreach ( $final as $priority => $callbacks ) {
						foreach ( $callbacks as $callback ) {
							$original->add_filter( $name, $callback['function'], $priority, $callback['accepted_args'] );
						}
					}
				}
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the active original after isolated named/all notification dispatch.
				$wp_filter[ $name ] = $original;
			}
			if ( ! $success ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Remove only the interrupted notification frames, retaining the exact outer execution stack.
				$wp_current_filter = $stack;
			}
		}
	}
}
