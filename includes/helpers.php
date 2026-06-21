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
		'opening_balance'      => 0.0,
		'default_building_id'  => 0,
		'currency_symbol'      => '৳',
		'vacant_charge_percent' => 50.0,
		'late_fee_amount'      => 0.0,
		'late_fee_type'        => 'fixed',
		'due_day'              => 10,
		'enable_emails'        => 'no',
		'enable_reminders'     => 'no',
		'purge_on_uninstall'   => 'no',
	);
	$settings = get_option( 'bcl_settings', array() );

	return wp_parse_args( is_array( $settings ) ? $settings : array(), $defaults );
}

/**
 * Late fee configuration.
 *
 * @return array{amount:float, type:string}
 */
function bcl_get_late_fee_config(): array {
	$settings = bcl_get_settings();

	return array(
		'amount' => max( 0.0, (float) ( $settings['late_fee_amount'] ?? 0 ) ),
		'type'   => in_array( $settings['late_fee_type'] ?? 'fixed', array( 'fixed', 'percent' ), true ) ? (string) $settings['late_fee_type'] : 'fixed',
	);
}

/**
 * Resolve the late fee for a given carried-forward (overdue) amount.
 */
function bcl_calculate_late_fee( float $overdue_amount ): float {
	if ( $overdue_amount <= 0 ) {
		return 0.0;
	}

	$config = bcl_get_late_fee_config();
	if ( $config['amount'] <= 0 ) {
		return 0.0;
	}

	if ( 'percent' === $config['type'] ) {
		return round( $overdue_amount * ( $config['amount'] / 100 ), 2 );
	}

	return round( $config['amount'], 2 );
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
		'bc_payment',
		'bc_ticket',
		'bc_notice',
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
		'bc_manage_tickets'    => array( 'bc_ticket', 'bc_tickets' ),
		'bc_manage_notices'    => array( 'bc_notice', 'bc_notices' ),
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
		'bc_manage_tickets',
		'bc_manage_notices',
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

	$settings        = bcl_get_settings();
	$vacant_percent  = max( 0.0, min( 100.0, (float) ( $settings['vacant_charge_percent'] ?? 50 ) ) );
	$multiplier      = 'occupied' === $occupancy ? 1.0 : ( $vacant_percent / 100 );

	return array(
		'occupancy_status' => $occupancy,
		'service_charge'   => round( $base_charge * $multiplier, 2 ),
		'base_charge'      => $base_charge,
	);
}

/**
 * Compute the normalized payment state for a bill.
 *
 * Single source of truth used by both manual saves and recorded payments.
 *
 * @return array{amount_paid:float, remaining_due:float, payment_status:string}
 */
function bcl_compute_payment_state( float $total_payable, float $amount_paid ): array {
	$total_payable = round( max( 0.0, $total_payable ), 2 );
	$amount_paid   = round( max( 0.0, $amount_paid ), 2 );

	if ( $total_payable <= 0 ) {
		return array(
			'amount_paid'    => $amount_paid,
			'remaining_due'  => 0.0,
			'payment_status' => $amount_paid > 0 ? 'paid' : 'unpaid',
		);
	}

	$remaining = round( $total_payable - $amount_paid, 2 );

	if ( $remaining <= 0 ) {
		return array(
			'amount_paid'    => $total_payable,
			'remaining_due'  => 0.0,
			'payment_status' => 'paid',
		);
	}

	return array(
		'amount_paid'    => $amount_paid,
		'remaining_due'  => $remaining,
		'payment_status' => $amount_paid > 0 ? 'partial' : 'unpaid',
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
if ( ! function_exists( __NAMESPACE__ . '\\bcl_get_buildings_options' ) ) {
	function bcl_get_buildings_options(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		$transient_key = 'bcl_buildings_opts';
		$opts          = get_transient( $transient_key );
		if ( false !== $opts && is_array( $opts ) ) {
			$cache = $opts;
			return $cache;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title FROM {$wpdb->posts} p
				 WHERE p.post_type = %s AND p.post_status = 'publish'
				 ORDER BY p.post_title ASC",
				'bc_building'
			)
		);

		$options = array();
		foreach ( $rows as $row ) {
			$options[ (int) $row->ID ] = $row->post_title;
		}

		$cache = $options;
		set_transient( $transient_key, $options, 10 * MINUTE_IN_SECONDS );
		return $options;
	}
}

/**
 * Get flats for select fields.
 *
 * @param int $building_id Optional building filter.
 * @return array<int, string>
 */
if ( ! function_exists( __NAMESPACE__ . '\\bcl_get_flats_options' ) ) {
	function bcl_get_flats_options( int $building_id = 0 ): array {
	$cache_key = 'bcl_flats_opts_' . $building_id;
	static $static = array();
	if ( isset( $static[ $cache_key ] ) ) {
		return $static[ $cache_key ];
	}

	$transient_key = 'bcl_flats_opts_' . $building_id;
	$opts          = get_transient( $transient_key );
	if ( false !== $opts && is_array( $opts ) ) {
		$static[ $cache_key ] = $opts;
		return $opts;
	}

	global $wpdb;
	$sql = "SELECT p.ID, p.post_title,
		MAX(CASE WHEN pm.meta_key = 'bc_flat_number' THEN pm.meta_value END) AS flat_no,
		MAX(CASE WHEN pm.meta_key = 'bc_building_id' THEN pm.meta_value END) AS bld_id
		FROM {$wpdb->posts} p
		LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
		WHERE p.post_type = %s AND p.post_status = 'publish'";

	$args = array( 'bc_flat' );

	if ( $building_id > 0 ) {
		$sql   .= " AND p.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'bc_building_id' AND meta_value = %d)";
		$args[] = $building_id;
	}

	$sql .= " GROUP BY p.ID ORDER BY p.post_title ASC";

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );

	$options = array();
	foreach ( $rows as $row ) {
		$fid     = (int) $row->ID;
		$no      = $row->flat_no ? $row->flat_no . ' — ' : '';
		$options[ $fid ] = $no . $row->post_title;
	}

	$static[ $cache_key ] = $options;
	set_transient( $transient_key, $options, 5 * MINUTE_IN_SECONDS );
	return $options;
	}
}

/**
 * Get residents for select fields.
 *
 * @return array<int, string>
 */
if ( ! function_exists( __NAMESPACE__ . '\\bcl_get_residents_options' ) ) {
	function bcl_get_residents_options(): array {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}

	$transient_key = 'bcl_residents_opts';
	$opts          = get_transient( $transient_key );
	if ( false !== $opts && is_array( $opts ) ) {
		$cache = $opts;
		return $cache;
	}

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_title FROM {$wpdb->posts}
			 WHERE post_type = %s AND post_status = 'publish'
			 ORDER BY post_title ASC",
			'bc_resident'
		)
	);

	$options = array();
	foreach ( $rows as $row ) {
		$options[ (int) $row->ID ] = $row->post_title;
	}

	$cache = $options;
	set_transient( $transient_key, $options, 5 * MINUTE_IN_SECONDS );
	return $options;
	}
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
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$total = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT SUM( CAST( pm.meta_value AS DECIMAL(12,2) ) )
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pmf ON pmf.post_id = p.ID AND pmf.meta_key = 'bc_flat_id'
			 INNER JOIN {$wpdb->postmeta} pmm ON pmm.post_id = p.ID AND pmm.meta_key = 'bc_billing_month'
			 INNER JOIN {$wpdb->postmeta} pmr ON pmr.post_id = p.ID AND pmr.meta_key = 'bc_remaining_due'
			 INNER JOIN {$wpdb->postmeta} pms ON pms.post_id = p.ID AND pms.meta_key = 'bc_payment_status'
			 WHERE p.post_type = 'bc_bill' AND p.post_status = 'publish'
			   AND pmf.meta_value = %d
			   AND pmm.meta_value < %s
			   AND pms.meta_value != 'paid'",
			$flat_id,
			$billing_month
		)
	);

	return round( (float) $total, 2 );
}

/**
 * Carry forward unpaid balances from prior bills into the new bill.
 *
 * Prior unpaid/partial bills for the flat are "superseded": their remaining due is
 * zeroed and flagged so the same outstanding amount is not counted twice (once on the
 * old bill and again inside the new bill's total payable). The summed balance is
 * returned so the caller can roll it into the new bill.
 *
 * @return float Total carried-forward (overdue) amount.
 */
function bcl_carry_forward_dues( int $flat_id, string $billing_month ): float {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID AS bill_id, CAST( pmr.meta_value AS DECIMAL(12,2) ) AS remaining
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pmf ON pmf.post_id = p.ID AND pmf.meta_key = 'bc_flat_id'
			 INNER JOIN {$wpdb->postmeta} pmm ON pmm.post_id = p.ID AND pmm.meta_key = 'bc_billing_month'
			 INNER JOIN {$wpdb->postmeta} pmr ON pmr.post_id = p.ID AND pmr.meta_key = 'bc_remaining_due'
			 INNER JOIN {$wpdb->postmeta} pms ON pms.post_id = p.ID AND pms.meta_key = 'bc_payment_status'
			 WHERE p.post_type = 'bc_bill' AND p.post_status = 'publish'
			   AND pmf.meta_value = %d
			   AND pmm.meta_value < %s
			   AND pms.meta_value != 'paid'",
			$flat_id,
			$billing_month
		)
	);

	$total = 0.0;
	foreach ( $rows as $row ) {
		$remaining = round( (float) $row->remaining, 2 );
		if ( $remaining <= 0 ) {
			continue;
		}

		$total += $remaining;

		$bill_id = (int) $row->bill_id;
		update_post_meta( $bill_id, 'bc_remaining_due', 0 );
		update_post_meta( $bill_id, 'bc_carried_forward', 'yes' );
		update_post_meta( $bill_id, 'bc_carried_to_month', $billing_month );
	}

	return round( $total, 2 );
}

/**
 * Whether automated payment-ledger creation is currently allowed.
 */
function bcl_is_payment_insert_allowed(): bool {
	return ! empty( $GLOBALS['bcl_allow_payment_insert'] );
}

/**
 * Run a callback while automated payment-ledger creation is permitted.
 *
 * @template T
 * @param callable(): T $callback Callback.
 * @return T
 */
function bcl_run_with_payment_insert_allowed( callable $callback ) {
	$GLOBALS['bcl_allow_payment_insert'] = true;

	try {
		return $callback();
	} finally {
		unset( $GLOBALS['bcl_allow_payment_insert'] );
	}
}

/**
 * Invalidate options caches (call after create/update/delete of related CPTs).
 * Also call when you want fresh select options.
 */
function bcl_invalidate_options_caches(): void {
	delete_transient( 'bcl_buildings_opts' );
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_bcl_flats_opts_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_bcl_flats_opts_' ) . '%'
		)
	);
	delete_transient( 'bcl_residents_opts' );
}

/**
 * High-performance sum of a numeric meta for posts in a date range using direct SQL.
 */
function bcl_sum_meta_between_dates( string $post_type, string $value_meta_key, string $date_meta_key, string $start_date, string $end_date ): float {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$total = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT SUM( CAST( pmv.meta_value AS DECIMAL(12,2) ) )
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pmv ON pmv.post_id = p.ID AND pmv.meta_key = %s
			 INNER JOIN {$wpdb->postmeta} pmd ON pmd.post_id = p.ID AND pmd.meta_key = %s
			 WHERE p.post_type = %s AND p.post_status = 'publish'
			   AND pmd.meta_value BETWEEN %s AND %s",
			$value_meta_key,
			$date_meta_key,
			$post_type,
			$start_date,
			$end_date
		)
	);

	return round( (float) $total, 2 );
}

/**
 * High-performance sum of remaining dues (unpaid/partial bills).
 */
function bcl_sum_outstanding_dues_fast(): float {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	$total = $wpdb->get_var(
		"SELECT SUM( CAST( pm.meta_value AS DECIMAL(12,2) ) )
		 FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'bc_remaining_due'
		 INNER JOIN {$wpdb->postmeta} pms ON pms.post_id = p.ID AND pms.meta_key = 'bc_payment_status'
		 WHERE p.post_type = 'bc_bill' AND p.post_status = 'publish'
		   AND pms.meta_value != 'paid'"
	);

	return round( (float) $total, 2 );
}

/**
 * Resolve a display name for an audit-log user id without fataling on deleted users.
 */
function bcl_get_audit_user_name( int $user_id ): string {
	if ( $user_id <= 0 ) {
		return __( 'System', 'buildingcare-lite' );
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		/* translators: %d: user id */
		return sprintf( __( 'Deleted user #%d', 'buildingcare-lite' ), $user_id );
	}

	return $user->display_name;
}

/**
 * Get bill stats for a month using direct queries (count + sums).
 *
 * @return array{unpaid_flats:int, collection_percent:float, total_payable:float, total_collected:float}
 */
function bcl_get_bill_stats_fast( string $month ): array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT
				COUNT(*) AS total_bills,
				SUM( CASE WHEN pms.meta_value != 'paid' THEN 1 ELSE 0 END ) AS unpaid,
				SUM( CAST( pmp.meta_value AS DECIMAL(12,2) ) ) AS total_payable,
				SUM( CAST( pma.meta_value AS DECIMAL(12,2) ) ) AS total_paid
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pmm ON pmm.post_id = p.ID AND pmm.meta_key = 'bc_billing_month'
			 LEFT JOIN  {$wpdb->postmeta} pms ON pms.post_id = p.ID AND pms.meta_key = 'bc_payment_status'
			 LEFT JOIN  {$wpdb->postmeta} pmp ON pmp.post_id = p.ID AND pmp.meta_key = 'bc_total_payable_amount'
			 LEFT JOIN  {$wpdb->postmeta} pma ON pma.post_id = p.ID AND pma.meta_key = 'bc_amount_paid'
			 WHERE p.post_type = 'bc_bill' AND p.post_status = 'publish'
			   AND pmm.meta_value = %s",
			$month
		),
		ARRAY_A
	);

	$total_bills     = (int) ( $row['total_bills'] ?? 0 );
	$unpaid          = (int) ( $row['unpaid'] ?? 0 );
	$total_payable   = round( (float) ( $row['total_payable'] ?? 0 ), 2 );
	$total_collected = round( (float) ( $row['total_paid'] ?? 0 ), 2 );

	$percent = $total_payable > 0 ? round( ( $total_collected / $total_payable ) * 100, 1 ) : 0.0;

	return array(
		'unpaid_flats'       => $unpaid,
		'collection_percent' => $percent,
		'total_payable'      => $total_payable,
		'total_collected'    => $total_collected,
		'total_bills'        => $total_bills,
	);
}

/**
 * Aggregate bill collected/due totals grouped by a meta key over a month range.
 *
 * One query replaces per-entity bill lookups (avoids N+1 in flat/resident reports).
 *
 * @param string $group_meta_key e.g. 'bc_flat_id' or 'bc_resident_id'.
 * @return array<int, array{collected:float, due:float}>
 */
function bcl_aggregate_bill_sums_by( string $group_meta_key, string $start_month, string $end_month ): array {
	global $wpdb;

	$allowed = array( 'bc_flat_id', 'bc_resident_id', 'bc_building_id' );
	if ( ! in_array( $group_meta_key, $allowed, true ) ) {
		return array();
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT grp.meta_value AS group_id,
				SUM( CAST( paid.meta_value AS DECIMAL(12,2) ) ) AS collected,
				SUM( CAST( due.meta_value AS DECIMAL(12,2) ) ) AS due
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} mon ON mon.post_id = p.ID AND mon.meta_key = 'bc_billing_month'
			 INNER JOIN {$wpdb->postmeta} grp ON grp.post_id = p.ID AND grp.meta_key = %s
			 LEFT JOIN  {$wpdb->postmeta} paid ON paid.post_id = p.ID AND paid.meta_key = 'bc_amount_paid'
			 LEFT JOIN  {$wpdb->postmeta} due ON due.post_id = p.ID AND due.meta_key = 'bc_remaining_due'
			 WHERE p.post_type = 'bc_bill' AND p.post_status = 'publish'
			   AND mon.meta_value BETWEEN %s AND %s
			 GROUP BY grp.meta_value",
			$group_meta_key,
			$start_month,
			$end_month
		)
	);

	$totals = array();
	foreach ( $rows as $row ) {
		$totals[ (int) $row->group_id ] = array(
			'collected' => round( (float) $row->collected, 2 ),
			'due'       => round( (float) $row->due, 2 ),
		);
	}

	return $totals;
}

/**
 * Preload multiple post metas for a set of post IDs into WP cache in one query.
 * Call before rendering lists.
 *
 * @param int[] $post_ids
 */
function bcl_prime_post_metas( array $post_ids ): void {
	$post_ids = array_filter( array_map( 'absint', $post_ids ) );
	if ( empty( $post_ids ) ) {
		return;
	}
	update_meta_cache( 'post', $post_ids );
}

/**
 * Maintenance ticket status labels.
 *
 * @return array<string, string>
 */
function bcl_ticket_statuses(): array {
	return array(
		'open'        => __( 'Open', 'buildingcare-lite' ),
		'in_progress' => __( 'In Progress', 'buildingcare-lite' ),
		'resolved'    => __( 'Resolved', 'buildingcare-lite' ),
		'closed'      => __( 'Closed', 'buildingcare-lite' ),
	);
}

/**
 * Maintenance ticket category labels.
 *
 * @return array<string, string>
 */
function bcl_ticket_categories(): array {
	return array(
		'plumbing'    => __( 'Plumbing', 'buildingcare-lite' ),
		'electrical'  => __( 'Electrical', 'buildingcare-lite' ),
		'lift'        => __( 'Lift / Elevator', 'buildingcare-lite' ),
		'security'    => __( 'Security', 'buildingcare-lite' ),
		'cleaning'    => __( 'Cleaning', 'buildingcare-lite' ),
		'other'       => __( 'Other', 'buildingcare-lite' ),
	);
}

/**
 * Maintenance ticket priority labels.
 *
 * @return array<string, string>
 */
function bcl_ticket_priorities(): array {
	return array(
		'low'    => __( 'Low', 'buildingcare-lite' ),
		'normal' => __( 'Normal', 'buildingcare-lite' ),
		'high'   => __( 'High', 'buildingcare-lite' ),
	);
}

/**
 * Read a bill's ad-hoc (one-off) charge line items.
 *
 * @return array<int, array{label:string, amount:float}>
 */
function bcl_get_bill_extra_charges( int $bill_id ): array {
	$raw = get_post_meta( $bill_id, 'bc_extra_charges', true );
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$items = array();
	foreach ( $raw as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$label  = sanitize_text_field( (string) ( $item['label'] ?? '' ) );
		$amount = round( (float) ( $item['amount'] ?? 0 ), 2 );
		if ( '' === $label || $amount <= 0 ) {
			continue;
		}
		$items[] = array(
			'label'  => $label,
			'amount' => $amount,
		);
	}

	return $items;
}

/**
 * Sum of a bill's ad-hoc charge line items.
 */
function bcl_get_bill_extra_total( int $bill_id ): float {
	$total = 0.0;
	foreach ( bcl_get_bill_extra_charges( $bill_id ) as $item ) {
		$total += $item['amount'];
	}

	return round( $total, 2 );
}
