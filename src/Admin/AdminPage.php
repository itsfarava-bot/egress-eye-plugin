<?php
/**
 * Native server-rendered diagnostic management screen.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

namespace EgressEye\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EgressEye\Contracts\DiagnosticDomains;
use EgressEye\Contracts\AuditReader;
use EgressEye\Support\Capabilities;

/**
 * Applies authorization on render even when the callback is invoked directly.
 */
final class AdminPage {
	/**
	 * Bounded domain reader.
	 *
	 * @var DiagnosticDomains
	 */
	private $domains;
	/**
	 * Bounded audit reader.
	 *
	 * @var AuditReader
	 */
	private $audits;
	/**
	 * Escaping renderer.
	 *
	 * @var DomainTableRenderer
	 */
	private $domain_table;
	/**
	 * Escaping history renderer.
	 *
	 * @var AuditTableRenderer
	 */
	private $audit_table;

	/**
	 * Compose an inert admin page.
	 *
	 * @param DiagnosticDomains   $domains      Domain port.
	 * @param AuditReader         $audits       Audit port.
	 * @param DomainTableRenderer $domain_table Domain renderer.
	 * @param AuditTableRenderer  $audit_table  Audit renderer.
	 */
	public function __construct( DiagnosticDomains $domains, AuditReader $audits, DomainTableRenderer $domain_table, AuditTableRenderer $audit_table ) {
		$this->domains      = $domains;
		$this->audits       = $audits;
		$this->domain_table = $domain_table;
		$this->audit_table  = $audit_table;
	}

	/**
	 * Render only authorized local UI with no remote resources or scripts.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! Capabilities::require_access() ) {
			return;
		}
		echo '<div class="wrap egress-eye" dir="' . ( is_rtl() ? 'rtl' : 'ltr' ) . '"><h1 class="egress-eye-header">' . esc_html__( 'Egress Eye', 'egress-eye' ) . '</h1>';
		$outcome = ActionNotice::consume();
		$notices = array(
			'blocked'      => __( 'Host blocked.', 'egress-eye' ),
			'unblocked'    => __( 'Host unblocked.', 'egress-eye' ),
			'confirmation' => __( 'Confirm the loopback warning before blocking this host.', 'egress-eye' ),
			'error'        => __( 'The action was not saved; the host status is unchanged.', 'egress-eye' ),
		);
		if ( isset( $notices[ $outcome ] ) ) {
			echo '<div class="notice ' . ( in_array( $outcome, array( 'blocked', 'unblocked' ), true ) ? 'notice-success' : 'notice-warning' ) . '"><p>' . esc_html( $notices[ $outcome ] ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Counts and averages include only slow requests (at least 1 second).', 'egress-eye' ) . '</p><p>' . esc_html__( 'Only the exact host is blocked; subdomains and DNS-to-IP mappings are not inherited. Redirect destinations are not guaranteed.', 'egress-eye' ) . '</p><p>' . esc_html__( 'The probable source is a guess. Wrappers, cron and shared code can make attribution uncertain.', 'egress-eye' ) . '</p>';
		echo '<p>' . esc_html__( 'MyISAM tables can record and display diagnostics. Blocking and unblocking require InnoDB to save the change and its history together. Your hosting provider can help with the table format.', 'egress-eye' ) . '</p>';
		try {
			$rows = $this->domains->all();
			if ( count( $rows ) >= 500 ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'The 500-host capacity is full. Existing hosts remain tracked. New hosts cannot be tracked until retention removes an old unblocked host. Unblocking does not immediately free a slot.', 'egress-eye' ) . '</p></div>';
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes every dynamic field and supplies only native fixed markup.
			echo $this->domain_table->render( $rows );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes every dynamic field and supplies only native fixed markup.
			echo $this->audit_table->render( $this->audits->recent() );
		} catch ( \Throwable $exception ) {
			echo '<p>' . esc_html__( 'Diagnostic storage is unavailable. No block state was changed.', 'egress-eye' ) . '</p>';
		}
		echo '</div>';
	}
}
