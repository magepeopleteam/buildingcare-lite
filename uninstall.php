<?php
/**
 * Uninstall BuildingCare Lite.
 *
 * @package BuildingCareLite
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings    = get_option( 'bcl_settings', array() );
$purge_posts = is_array( $settings ) && ! empty( $settings['purge_on_uninstall'] );

delete_option( 'bcl_settings' );
delete_option( 'bcl_audit_log' );
delete_option( 'bcl_opening_balance' );
delete_option( 'bcl_db_version' );
delete_option( 'bcl_expense_categories_seeded' );

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_bcl_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_bcl_' ) . '%'
	)
);

// Optionally remove all BuildingCare content (opt-in via Settings).
if ( $purge_posts ) {
	$post_types = array(
		'bc_building',
		'bc_flat',
		'bc_resident',
		'bc_bill',
		'bc_expense',
		'bc_recurring_expense',
		'bc_payment',
	);

	foreach ( $post_types as $post_type ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
				$post_type
			)
		);

		foreach ( $post_ids as $post_id ) {
			wp_delete_post( (int) $post_id, true );
		}
	}
}
