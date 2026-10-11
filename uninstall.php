<?php
/**
 * Unconditionally remove this single site's diagnostic data on uninstall.
 *
 * @package Egress_Eye
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$egress_eye_previous_suppression = $wpdb->suppress_errors( true );

try {
	foreach ( array( 'events', 'audits', 'domains' ) as $egress_eye_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Permanent uninstall of plugin-owned tables, explicitly required by the uninstall contract.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'http_diag_' . $egress_eye_table ) );
	}
	foreach ( array( 'egress_eye_schema_version', 'egress_eye_last_maintenance', 'egress_eye_retention_lock', 'egress_eye_cache_version', 'egress_eye_domain_capacity_notice' ) as $egress_eye_option ) {
		delete_option( $egress_eye_option );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Full uninstall includes the bounded user outcome slots.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key = %s', $wpdb->usermeta, '_egress_eye_action_notice_' . substr( hash( 'sha256', $wpdb->prefix ), 0, 16 ) ) );
	wp_unschedule_hook( 'egress_eye_retention' );
	try {
		wp_cache_delete( $wpdb->prefix . 'blocked_hosts', 'egress-eye' );
	} catch ( \Throwable $egress_eye_exception ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Legacy snapshot cleanup cannot undo completed uninstall or leave recurring cron.
		// Generation snapshots expire; storage and recurring cron are already removed.
	}
} finally {
	$wpdb->suppress_errors( $egress_eye_previous_suppression );
}
