<?php
/**
 * Helper functions.
 *
 * @package BuildingCareLite
 */

declare(strict_types=1);

namespace BuildingCareLite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return plugin settings with defaults.
 *
 * @return array<string, mixed>
 */
function bcl_get_settings(): array {
	$defaults = array(
		'opening_balance'     => 0.0,
		'default_building_id' => 0,
		'currency_symbol'     => '৳',
	);
	$settings = get_option( 'bcl_settings', array() );

	return wp_parse_args( is_array( $settings ) ? $settings : array(), $defaults );
}

/**
 * Format a monetary amount.
 */
function bcl_format_amount( float $amount ): string {
	$settings = bcl_get_settings();
	$symbol   = isset( $settings['currency_symbol'] ) ? (string) $settings['currency_symbol'] : '৳';

	return $symbol . ' ' . number_format( $amount, 2 );
}

/**
 * Get post meta as float.
 */
function bcl_get_meta_float( int $post_id, string $key ): float {
	return (float) get_post_meta( $post_id, $key, true );
}

/**
 * Get post meta as string.
 */
function bcl_get_meta_string( int $post_id, string $key ): string {
	return (string) get_post_meta( $post_id, $key, true );
}

/**
 * Current billing month in Y-m format.
 */
function bcl_current_billing_month(): string {
	return gmdate( 'Y-m' );
}

/**
 * Month key for a given date.
 */
function bcl_month_from_date( string $date ): string {
	$timestamp = strtotime( $date );

	return $timestamp ? gmdate( 'Y-m', $timestamp ) : bcl_current_billing_month();
}

/**
 * Format a billing month key (Y-m) for display, e.g. June 2026.
 */
function bcl_format_billing_month( string $month ): string {
	$month = trim( $month );
	if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
		return $month;
	}

	$timestamp = strtotime( $month . '-01' );

	return $timestamp ? date_i18n( 'F Y', $timestamp ) : $month;
}

/**
 * Flat number for a flat post.
 */
function bcl_get_flat_number( int $flat_id ): string {
	if ( $flat_id <= 0 ) {
		return '';
	}

	$number = bcl_get_meta_string( $flat_id, 'bc_flat_number' );

	return $number ?: get_the_title( $flat_id );
}

/**
 * Display title for a bill (flat number only).
 */
function bcl_get_bill_display_title( int $bill_id ): string {
	$flat_id = (int) bcl_get_meta_float( $bill_id, 'bc_flat_id' );
	$number  = bcl_get_flat_number( $flat_id );

	if ( $number ) {
		return $number;
	}

	$post = get_post( $bill_id );

	return $post instanceof \WP_Post ? $post->post_title : '';
}

/**
 * Check whether the current user has a BuildingCare capability.
 */
function bcl_current_user_can( string $capability ): bool {
	return current_user_can( 'manage_options' ) || current_user_can( $capability );
}

/**
 * Whether automated bill creation is currently allowed (generation/cron).
 */
function bcl_is_bill_insert_allowed(): bool {
	return ! empty( $GLOBALS['bcl_allow_bill_insert'] );
}

/**
 * Run a callback while automated bill creation is permitted.
 *
 * @template T
 * @param callable(): T $callback Callback.
 * @return T
 */
function bcl_run_with_bill_insert_allowed( callable $callback ) {
	$GLOBALS['bcl_allow_bill_insert'] = true;

	try {
		return $callback();
	} finally {
		unset( $GLOBALS['bcl_allow_bill_insert'] );
	}
}

/**
 * All BuildingCare custom post types.
 *
 * @return string[]
 */
function bcl_get_post_types(): array {
	return array(
		'bc_building',
		'bc_flat',
		'bc_resident',
		'bc_bill',
		'bc_expense',
		'bc_recurring_expense',
	);
}

/**
 * Map high-level manage caps to CPT capability prefixes.
 *
 * @return array<string, string[]>
 */
function bcl_get_manage_cap_prefixes(): array {
	return array(
		'bc_manage_buildings'  => array( 'bc_building', 'bc_buildings' ),
		'bc_manage_flats'      => array( 'bc_flat', 'bc_flats' ),
		'bc_manage_residents'  => array( 'bc_resident', 'bc_residents' ),
		'bc_generate_bills'    => array( 'bc_bill', 'bc_bills' ),
		'bc_manage_payments'   => array( 'bc_bill', 'bc_bills' ),
		'bc_manage_expenses'   => array( 'bc_expense', 'bc_expenses', 'bc_recurring_expense', 'bc_recurring_expenses' ),
	);
}

/**
 * Check a capability from the user object without calling user_can().
 */
function bcl_user_has_cap( int $user_id, string $capability ): bool {
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return false;
	}

	return ! empty( $user->allcaps[ $capability ] );
}

/**
 * All custom capabilities used by the plugin.
 *
 * @return string[]
 */
function bcl_get_capabilities(): array {
	return array(
		'bc_manage_buildings',
		'bc_manage_flats',
		'bc_manage_residents',
		'bc_generate_bills',
		'bc_manage_payments',
		'bc_manage_expenses',
		'bc_view_reports',
		'bc_manage_settings',
	);
}

/**
 * Calculate service charge for billing based on flat occupancy.
 *
 * Occupied flats: 100% of monthly service charge.
 * Vacant flats: 50% of monthly service charge.
 *
 * @return array{occupancy_status:string, service_charge:float, base_charge:float}
 */
function bcl_get_flat_billing_details( int $flat_id ): array {
	$occupancy   = bcl_get_meta_string( $flat_id, 'bc_occupancy_status' );
	$occupancy   = 'occupied' === $occupancy ? 'occupied' : 'vacant';
	$base_charge = bcl_get_meta_float( $flat_id, 'bc_monthly_service_charge' );
	$multiplier  = 'occupied' === $occupancy ? 1.0 : 0.5;

	return array(
		'occupancy_status' => $occupancy,
		'service_charge'   => round( $base_charge * $multiplier, 2 ),
		'base_charge'      => $base_charge,
	);
}

/**
 * Append an audit log entry.
 *
 * @param string               $action  Action identifier.
 * @param string               $message Human-readable message.
 * @param array<string, mixed> $context Optional context.
 */
function bcl_audit_log( string $action, string $message, array $context = array() ): void {
	$logs   = get_option( 'bcl_audit_log', array() );
	$logs   = is_array( $logs ) ? $logs : array();
	$logs[] = array(
		'time'    => current_time( 'mysql' ),
		'user_id' => get_current_user_id(),
		'action'  => sanitize_key( $action ),
		'message' => sanitize_text_field( $message ),
		'context' => $context,
	);

	if ( count( $logs ) > 500 ) {
		$logs = array_slice( $logs, -500 );
	}

	update_option( 'bcl_audit_log', $logs, false );
}

/**
 * Invalidate dashboard cache transients.
 */
function bcl_clear_dashboard_cache(): void {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_bcl_dashboard_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_bcl_dashboard_' ) . '%'
		)
	);
}

/**
 * Get buildings for select fields.
 *
 * @return array<int, string>
 */
function bcl_get_buildings_options(): array {
	$posts = get_posts(
		array(
			'post_type'      => 'bc_building',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);

	$options = array();
	foreach ( $posts as $post ) {
		$options[ $post->ID ] = $post->post_title;
	}

	return $options;
}

/**
 * Get flats for select fields.
 *
 * @param int $building_id Optional building filter.
 * @return array<int, string>
 */
function bcl_get_flats_options( int $building_id = 0 ): array {
	$args = array(
		'post_type'      => 'bc_flat',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'ASC',
	);

	if ( $building_id > 0 ) {
		$args['meta_query'] = array(
			array(
				'key'   => 'bc_building_id',
				'value' => $building_id,
			),
		);
	}

	$posts   = get_posts( $args );
	$options = array();

	foreach ( $posts as $post ) {
		$flat_no            = bcl_get_meta_string( $post->ID, 'bc_flat_number' );
		$options[ $post->ID ] = $flat_no ? $flat_no . ' — ' . $post->post_title : $post->post_title;
	}

	return $options;
}

/**
 * Get residents for select fields.
 *
 * @return array<int, string>
 */
function bcl_get_residents_options(): array {
	$posts = get_posts(
		array(
			'post_type'      => 'bc_resident',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);

	$options = array();
	foreach ( $posts as $post ) {
		$options[ $post->ID ] = $post->post_title;
	}

	return $options;
}

/**
 * Payment status labels.
 *
 * @return array<string, string>
 */
function bcl_payment_statuses(): array {
	return array(
		'unpaid'  => __( 'Unpaid', 'buildingcare-lite' ),
		'partial' => __( 'Partially Paid', 'buildingcare-lite' ),
		'paid'    => __( 'Paid', 'buildingcare-lite' ),
	);
}

/**
 * Payment method labels.
 *
 * @return array<string, string>
 */
function bcl_payment_methods(): array {
	return array(
		'cash'            => __( 'Cash', 'buildingcare-lite' ),
		'bank_transfer'   => __( 'Bank Transfer', 'buildingcare-lite' ),
		'mobile_banking'  => __( 'Mobile Banking', 'buildingcare-lite' ),
	);
}

/**
 * Occupancy status labels.
 *
 * @return array<string, string>
 */
function bcl_occupancy_statuses(): array {
	return array(
		'occupied' => __( 'Occupied', 'buildingcare-lite' ),
		'vacant'   => __( 'Vacant', 'buildingcare-lite' ),
	);
}

/**
 * Building status labels.
 *
 * @return array<string, string>
 */
function bcl_building_statuses(): array {
	return array(
		'active'   => __( 'Active', 'buildingcare-lite' ),
		'inactive' => __( 'Inactive', 'buildingcare-lite' ),
	);
}

/**
 * Find resident assigned to a flat.
 */
function bcl_get_resident_for_flat( int $flat_id ): int {
	$residents = get_posts(
		array(
			'post_type'      => 'bc_resident',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'   => 'bc_assigned_flat_id',
					'value' => $flat_id,
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => 'bc_move_out_date',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => 'bc_move_out_date',
						'value'   => '',
						'compare' => '=',
					),
				),
			),
		)
	);

	return ! empty( $residents ) ? (int) $residents[0]->ID : 0;
}

/**
 * Calculate previous due for a flat before a billing month.
 */
function bcl_calculate_previous_due( int $flat_id, string $billing_month ): float {
	$bills = get_posts(
		array(
			'post_type'      => 'bc_bill',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'   => 'bc_flat_id',
					'value' => $flat_id,
				),
				array(
					'key'     => 'bc_billing_month',
					'value'   => $billing_month,
					'compare' => '<',
					'type'    => 'CHAR',
				),
				array(
					'key'     => 'bc_payment_status',
					'value'   => 'paid',
					'compare' => '!=',
				),
			),
		)
	);

	$total = 0.0;
	foreach ( $bills as $bill_id ) {
		$total += bcl_get_meta_float( (int) $bill_id, 'bc_remaining_due' );
	}

	return round( $total, 2 );
}
