<?php
/**
 * Authenticated native WordPress admin POST boundary.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EgressEye\Contracts\DiagnosticDomains;
use EgressEye\Http\LoopbackDetector;
use EgressEye\Support\Capabilities;
use EgressEye\Support\Nonces;

/**
 * Trusts only stored host identity and an explicit desired state.
 */
final class ToggleDomainAction {
	/**
	 * Audited persistence port.
	 *
	 * @var DiagnosticDomains
	 */
	private $domains;
	/**
	 * Site and loopback indication.
	 *
	 * @var LoopbackDetector
	 */
	private $loopback;

	/**
	 * Construct without dispatching an action.
	 *
	 * @param DiagnosticDomains $domains  Domain persistence.
	 * @param LoopbackDetector  $loopback Site marker.
	 */
	public function __construct( DiagnosticDomains $domains, LoopbackDetector $loopback ) {
		$this->domains  = $domains;
		$this->loopback = $loopback;
	}

	/**
	 * Reject unauthorized requests before reading or changing diagnostic storage.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! Capabilities::require_access() ) {
			return;
		}
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_die( esc_html__( 'A valid POST request is required.', 'egress-eye' ), '', array( 'response' => 405 ) );
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Strict id/state validation precedes the state-specific check_admin_referer below; no writes occur before that check.
		$id_text = isset( $_POST['domain_id'] ) && is_string( $_POST['domain_id'] ) ? sanitize_text_field( wp_unslash( $_POST['domain_id'] ) ) : '';
		$desired = isset( $_POST['desired'] ) && is_string( $_POST['desired'] ) ? sanitize_key( wp_unslash( $_POST['desired'] ) ) : '';
		if ( ! ctype_digit( $id_text ) || strlen( $id_text ) > 18 || (int) $id_text < 1 || ! in_array( $desired, array( 'block', 'unblock' ), true ) ) {
			wp_die( esc_html__( 'The requested action is invalid.', 'egress-eye' ), '', array( 'response' => 400 ) );
			return;
		}
		$id = (int) $id_text;
		check_admin_referer( Nonces::action( $id, $desired ) );
		$confirmed = isset( $_POST['confirm_loopback'] ) && '1' === $_POST['confirm_loopback'];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$outcome = 'error';
		try {
			$row = $this->domains->find( $id );
			if ( null !== $row ) {
				$host = (string) $row['host'];
				if ( 'block' === $desired && $this->loopback->isLoopback( $host ) && ! $confirmed ) {
					$outcome = 'confirmation';
				} elseif ( $this->domains->set_blocked( $host, 'block' === $desired, get_current_user_id() ) ) {
					$outcome = 'block' === $desired ? 'blocked' : 'unblocked';
				}
			}
		} catch ( \Throwable $exception ) {
			$outcome = 'error';
		}
		$token = ActionNotice::issue( $outcome );
		wp_safe_redirect( add_query_arg( 'egress_eye_notice', $token, admin_url( 'tools.php?page=egress-eye' ) ) );
		exit;
	}
}
