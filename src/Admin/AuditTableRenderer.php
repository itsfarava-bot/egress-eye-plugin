<?php
/**
 * Escaped bounded native action history.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows UTC time, host, action and acting user id without request data.
 */
final class AuditTableRenderer {
	/**
	 * Render only escaped history snapshots.
	 *
	 * @param array<int,array<string,mixed>> $rows Recent stored audits.
	 * @return string
	 */
	public function render( array $rows ): string {
		$html = '<table class="widefat striped"><caption>' . esc_html__( 'Recent manual actions (UTC)', 'egress-eye' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Time (UTC)', 'egress-eye' ), __( 'Host', 'egress-eye' ), __( 'Action', 'egress-eye' ), __( 'User ID', 'egress-eye' ) ) as $heading ) {
			$html .= '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$action = 'block' === $row['action'] ? __( 'Block', 'egress-eye' ) : __( 'Unblock', 'egress-eye' );
			$html  .= '<tr><td>' . esc_html( (string) $row['occurred_at'] ) . '</td><th scope="row"><bdi>' . esc_html( (string) $row['host'] ) . '</bdi></th><td>' . esc_html( $action ) . '</td><td>' . esc_html( (string) $row['user_id'] ) . '</td></tr>';
		}
		if ( array() === $rows ) {
			$html .= '<tr><td colspan="4">' . esc_html__( 'No manual actions yet.', 'egress-eye' ) . '</td></tr>';
		}
		return $html . '</tbody></table>';
	}
}
