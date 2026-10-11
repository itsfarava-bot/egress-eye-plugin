<?php
/**
 * Escaped native domain table and explicit POST forms.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EgressEye\Http\LoopbackDetector;
use EgressEye\Support\Nonces;

/**
 * Shows slow-only aggregates and a probable source without exposing sample tokens.
 */
final class DomainTableRenderer {
	/**
	 * Site marker, shared with handler and recorder.
	 *
	 * @var LoopbackDetector
	 */
	private $loopback;

	/**
	 * Construct the renderer.
	 *
	 * @param LoopbackDetector $loopback Site marker.
	 */
	public function __construct( LoopbackDetector $loopback ) {
		$this->loopback = $loopback;
	}

	/**
	 * Render escaped rows and WordPress nonce-protected native forms.
	 *
	 * @param array<int,array<string,mixed>> $rows Bounded stored rows.
	 * @return string
	 */
	public function render( array $rows ): string {
		$html = '<table class="widefat striped egress-eye-domains"><caption>' . esc_html__( 'Slow requests by exact host', 'egress-eye' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Host', 'egress-eye' ), __( 'Slow count', 'egress-eye' ), __( 'Last duration (seconds)', 'egress-eye' ), __( 'Mean duration (seconds)', 'egress-eye' ), __( 'Probable source (Guess)', 'egress-eye' ), __( 'Status and action', 'egress-eye' ) ) as $heading ) {
			$html .= '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$blocked = (bool) $row['is_blocked'];
			$host    = (string) $row['host'];
			$id      = (int) $row['id'];
			$count   = (int) $row['slow_count'];
			$mean    = $count > 0 ? (float) $row['slow_duration_sum_us'] / $count / 1000000 : 0;
			$last    = null !== $row['last_duration_us'] ? number_format( (float) $row['last_duration_us'] / 1000000, 3, '.', '' ) : '—';
			$key     = (string) ( $row['last_component_key'] ?? '' );
			if ( '' === $key || '/' === substr( $key, 0, 1 ) || '\\' === substr( $key, 0, 1 ) || preg_match( '/^[a-z]:/i', $key ) || strlen( $key ) > 191 ) {
				$key = __( 'Unknown', 'egress-eye' );
			}
			$html    .= '<tr><th scope="row"><bdi>' . esc_html( $host ) . '</bdi></th><td>' . esc_html( (string) $count ) . '</td><td>' . esc_html( $last ) . '</td><td>' . esc_html( number_format( $mean, 3, '.', '' ) ) . '</td><td>' . esc_html__( 'Guess', 'egress-eye' ) . ': ' . esc_html( $key ) . '</td><td>';
			$html    .= '<strong class="' . ( $blocked ? 'egress-eye-blocked' : 'egress-eye-slow' ) . '">' . ( $blocked ? esc_html__( 'Blocked', 'egress-eye' ) : esc_html__( 'Unblocked', 'egress-eye' ) ) . '</strong>';
			$loopback = $this->loopback->isLoopback( $host );
			if ( $loopback ) {
				$html .= '<p class="egress-eye-warning">' . esc_html__( 'Blocking this site or loopback host can interrupt cron and other essential tasks.', 'egress-eye' ) . '</p>';
			}
			$desired = $blocked ? 'unblock' : 'block';
			$html   .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="egress_eye_toggle_domain"><input type="hidden" name="domain_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="desired" value="' . esc_attr( $desired ) . '">' . wp_nonce_field( Nonces::action( $id, $desired ), '_wpnonce', false, false );
			if ( $loopback && ! $blocked ) {
				$html .= '<p><label><input type="checkbox" name="confirm_loopback" value="1" required> ' . esc_html__( 'I understand the risk and confirm blocking this site or loopback host.', 'egress-eye' ) . '</label></p>';
			}
			$html .= '<button type="submit" class="button button-primary egress-eye-primary">' . ( $blocked ? esc_html__( 'Unblock', 'egress-eye' ) : esc_html__( 'Block', 'egress-eye' ) ) . '</button></form></td></tr>';
		}
		if ( array() === $rows ) {
			$html .= '<tr><td colspan="6">' . esc_html__( 'No slow requests have been recorded yet.', 'egress-eye' ) . '</td></tr>';
		}
		return $html . '</tbody></table>';
	}
}
