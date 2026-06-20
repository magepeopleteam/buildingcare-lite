<?php
/**
 * Uninstall BuildingCare Lite.
 *
 * @package BuildingCareLite
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'bcl_settings' );
delete_option( 'bcl_audit_log' );
delete_option( 'bcl_opening_balance' );

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_bcl_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_bcl_' ) . '%'
	)
);
