<?php
/**
 * User-bound one-use authenticated action outcomes.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores one expiring flash slot per acting user, never caller-supplied text.
 */
final class ActionNotice {
	/** Namespaced user metadata key, also wiped on uninstall. */
	public const META_KEY = '_egress_eye_action_notice';

	/**
	 * Bind the outcome slot to the actual current site's table namespace.
	 *
	 * @return string
	 */
	public static function meta_key(): string {
		global $wpdb;
		return self::META_KEY . '_' . substr( hash( 'sha256', $wpdb->prefix ), 0, 16 );
	}

	/**
	 * Issue an opaque ticket for a fixed server outcome.
	 *
	 * @param string $outcome Fixed result code.
	 * @return string
	 */
	public static function issue( string $outcome ): string {
		$token = bin2hex( random_bytes( 16 ) );
		update_user_meta(
			get_current_user_id(),
			self::meta_key(),
			array(
				'token'   => $token,
				'outcome' => $outcome,
				'expires' => time() + 300,
			)
		);
		return $token;
	}

	/**
	 * Consume only a matching user-bound unexpired server ticket.
	 *
	 * @return string
	 */
	public static function consume(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Opaque server-issued one-use ticket only; this read never changes block state.
		$token = isset( $_GET['egress_eye_notice'] ) && is_string( $_GET['egress_eye_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['egress_eye_notice'] ) ) : '';
		$flash = get_user_meta( get_current_user_id(), self::meta_key(), true );
		if ( ! is_array( $flash ) ) {
			return '';
		}
		if ( ! isset( $flash['expires'], $flash['token'], $flash['outcome'] ) || time() > $flash['expires'] ) {
			delete_user_meta( get_current_user_id(), self::meta_key() );
			return '';
		}
		if ( ! is_string( $flash['token'] ) || ! hash_equals( $flash['token'], $token ) ) {
			return '';
		}
		delete_user_meta( get_current_user_id(), self::meta_key(), $flash );
		return is_string( $flash['outcome'] ) ? $flash['outcome'] : '';
	}
}
