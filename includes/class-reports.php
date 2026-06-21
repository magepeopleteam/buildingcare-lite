<?php
/**
 * Dashboard and report calculations.
 *
 * @package BuildingCareLite
 */

declare(strict_types=1);

namespace BuildingCareLite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides dashboard stats and report data.
 */
class Reports {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_bcl_get_report_data', array( $this, 'ajax_get_report_data' ) );
	}

	/**
	 * Get cached dashboard statistics.
	 *
	 * @return array<string, mixed>
	 */
	public function get_dashboard_stats( string $month = '' ): array {
		$month = $month ?: bcl_current_billing_month();
		$key   = 'bcl_dashboard_' . $month;
		$stats = get_transient( $key );

		if ( false !== $stats && is_array( $stats ) ) {
			return $stats;
		}

		$range = $this->month_date_range( $month );
		$stats = $this->calculate_period_stats( $range['start'], $range['end'], $month );
		set_transient( $key, $stats, 15 * MINUTE_IN_SECONDS );

		return $stats;
	}

	/**
	 * Calculate stats for a period.
	 *
	 * @return array<string, mixed>
	 */
	public function calculate_period_stats( string $start_date, string $end_date, string $billing_month = '' ): array {
		$settings        = bcl_get_settings();
		$opening_balance = (float) ( $settings['opening_balance'] ?? 0 );
		$income          = $this->sum_collected( $start_date, $end_date );
		$expenses        = ( new Expenses() )->sum_expenses( $start_date, $end_date );
		$closing_balance = round( $opening_balance + $income - $expenses, 2 );
		$outstanding     = $this->sum_outstanding_dues();
		$bill_stats      = $this->get_bill_stats( $billing_month ?: bcl_month_from_date( $start_date ) );

		return array(
			'month'                => $billing_month ?: bcl_month_from_date( $start_date ),
			'opening_balance'      => $opening_balance,
			'income'               => $income,
			'expenses'             => $expenses,
			'closing_balance'      => $closing_balance,
			'surplus'              => max( 0, round( $income - $expenses, 2 ) ),
			'deficit'              => max( 0, round( $expenses - $income, 2 ) ),
			'outstanding_dues'     => $outstanding,
			'unpaid_flats'         => $bill_stats['unpaid_flats'],
			'collection_percent'   => $bill_stats['collection_percent'],
			'recent_transactions'  => $this->get_recent_transactions( 10 ),
		);
	}

	/**
	 * Sum collected payments in date range.
	 */
	public function sum_collected( string $start_date, string $end_date ): float {
		// Prefer the payment ledger so partial payments are attributed to their real dates.
		if ( class_exists( __NAMESPACE__ . '\\Payments' ) && Payments::has_entries() ) {
			return Payments::sum_between( $start_date, $end_date );
		}

		// Use fast direct SQL when possible (legacy data without a ledger).
		if ( function_exists( 'bcl_sum_meta_between_dates' ) ) {
			return bcl_sum_meta_between_dates( 'bc_bill', 'bc_amount_paid', 'bc_payment_date', $start_date, $end_date );
		}

		// Fallback (legacy).
		$bills = get_posts(
			array(
				'post_type'      => 'bc_bill',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => 'bc_payment_date',
						'value'   => array( $start_date, $end_date ),
						'compare' => 'BETWEEN',
						'type'    => 'DATE',
					),
				),
			)
		);

		$total = 0.0;
		foreach ( $bills as $bill_id ) {
			$total += bcl_get_meta_float( (int) $bill_id, 'bc_amount_paid' );
		}

		return round( $total, 2 );
	}

	/**
	 * Sum all outstanding dues across bills.
	 */
	public function sum_outstanding_dues(): float {
		if ( function_exists( 'bcl_sum_outstanding_dues_fast' ) ) {
			return bcl_sum_outstanding_dues_fast();
		}

		$bills = get_posts(
			array(
				'post_type'      => 'bc_bill',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
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

	/**
	 * Bill collection stats for a month.
	 *
	 * @return array{unpaid_flats:int, collection_percent:float}
	 */
	public function get_bill_stats( string $month ): array {
		if ( function_exists( 'bcl_get_bill_stats_fast' ) ) {
			$fast = bcl_get_bill_stats_fast( $month );
			return array(
				'unpaid_flats'       => $fast['unpaid_flats'],
				'collection_percent' => $fast['collection_percent'],
			);
		}

		$bills = get_posts(
			array(
				'post_type'      => 'bc_bill',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => 'bc_billing_month',
						'value' => $month,
					),
				),
			)
		);

		$unpaid_flats   = 0;
		$total_payable  = 0.0;
		$total_collected = 0.0;

		foreach ( $bills as $bill_id ) {
			$bill_id = (int) $bill_id;
			$status  = bcl_get_meta_string( $bill_id, 'bc_payment_status' );
			if ( 'paid' !== $status ) {
				++$unpaid_flats;
			}
			$total_payable   += bcl_get_meta_float( $bill_id, 'bc_total_payable_amount' );
			$total_collected += bcl_get_meta_float( $bill_id, 'bc_amount_paid' );
		}

		$collection_percent = $total_payable > 0 ? round( ( $total_collected / $total_payable ) * 100, 1 ) : 0.0;

		return array(
			'unpaid_flats'       => $unpaid_flats,
			'collection_percent' => $collection_percent,
		);
	}

	/**
	 * Recent payment and expense transactions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_recent_transactions( int $limit = 10 ): array {
		$payments = get_posts(
			array(
				'post_type'      => 'bc_bill',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'meta_query'     => array(
					array(
						'key'     => 'bc_amount_paid',
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
				'orderby'        => 'meta_value',
				'meta_key'       => 'bc_payment_date',
				'order'          => 'DESC',
			)
		);

		if ( function_exists( 'bcl_prime_post_metas' ) ) {
			bcl_prime_post_metas( wp_list_pluck( $payments, 'ID' ) );
		}

		$transactions = array();
		foreach ( $payments as $bill ) {
			$transactions[] = array(
				'type'   => 'income',
				'title'  => bcl_get_bill_display_title( (int) $bill->ID ),
				'amount' => bcl_get_meta_float( (int) $bill->ID, 'bc_amount_paid' ),
				'date'   => bcl_get_meta_string( (int) $bill->ID, 'bc_payment_date' ),
			);
		}

		$expenses = get_posts(
			array(
				'post_type'      => 'bc_expense',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'meta_value',
				'meta_key'       => 'bc_expense_date',
				'order'          => 'DESC',
			)
		);

		if ( function_exists( 'bcl_prime_post_metas' ) ) {
			bcl_prime_post_metas( wp_list_pluck( $expenses, 'ID' ) );
		}

		foreach ( $expenses as $expense ) {
			$transactions[] = array(
				'type'   => 'expense',
				'title'  => $expense->post_title,
				'amount' => bcl_get_meta_float( (int) $expense->ID, 'bc_amount' ),
				'date'   => bcl_get_meta_string( (int) $expense->ID, 'bc_expense_date' ),
			);
		}

		usort(
			$transactions,
			static function ( array $a, array $b ): int {
				return strcmp( (string) $b['date'], (string) $a['date'] );
			}
		);

		return array_slice( $transactions, 0, $limit );
	}

	/**
	 * Income vs expense series for the last N months (for dashboard charts).
	 *
	 * @return array<int, array{month:string, label:string, income:float, expenses:float}>
	 */
	public function get_monthly_trend( int $months = 6 ): array {
		$months = max( 1, min( 24, $months ) );
		$series = array();
		$expenses_obj = new Expenses();

		for ( $i = $months - 1; $i >= 0; $i-- ) {
			$month = gmdate( 'Y-m', strtotime( "-{$i} months" ) );
			$range = $this->month_date_range( $month );
			$series[] = array(
				'month'    => $month,
				'label'    => date_i18n( 'M y', strtotime( $month . '-01' ) ),
				'income'   => $this->sum_collected( $range['start'], $range['end'] ),
				'expenses' => $expenses_obj->sum_expenses( $range['start'], $range['end'] ),
			);
		}

		return $series;
	}

	/**
	 * Generate report rows by type.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function generate_report( string $report_type, string $start_date, string $end_date ): array {
		switch ( $report_type ) {
			case 'collection':
				return $this->collection_report( $start_date, $end_date );
			case 'flat_wise':
				return $this->flat_wise_report( $start_date, $end_date );
			case 'resident_wise':
				return $this->resident_wise_report( $start_date, $end_date );
			case 'due':
				return $this->due_report();
			case 'expense':
				return $this->expense_report( $start_date, $end_date );
			case 'income_vs_expense':
				return $this->income_vs_expense_report( $start_date, $end_date );
			default:
				return array();
		}
	}

	/**
	 * Monthly collection report.
	 */
	private function collection_report( string $start_date, string $end_date ): array {
		$bills = get_posts(
			array(
				'post_type'      => 'bc_bill',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'     => 'bc_billing_month',
						'value'   => array( bcl_month_from_date( $start_date ), bcl_month_from_date( $end_date ) ),
						'compare' => 'BETWEEN',
						'type'    => 'CHAR',
					),
				),
			)
		);

		// Prime all metas for these bills in 1 query (big win for column access).
		if ( function_exists( 'bcl_prime_post_metas' ) ) {
			bcl_prime_post_metas( wp_list_pluck( $bills, 'ID' ) );
		}

		$rows = array();
		foreach ( $bills as $bill ) {
			$rows[] = array(
				'bill'       => bcl_get_bill_display_title( (int) $bill->ID ),
				'month'      => bcl_format_billing_month( bcl_get_meta_string( (int) $bill->ID, 'bc_billing_month' ) ),
				'flat'       => bcl_get_flat_number( (int) bcl_get_meta_float( (int) $bill->ID, 'bc_flat_id' ) ),
				'occupancy'  => bcl_occupancy_statuses()[ bcl_get_meta_string( (int) $bill->ID, 'bc_occupancy_status' ) ] ?? bcl_get_meta_string( (int) $bill->ID, 'bc_occupancy_status' ),
				'payable'    => bcl_get_meta_float( (int) $bill->ID, 'bc_total_payable_amount' ),
				'collected'  => bcl_get_meta_float( (int) $bill->ID, 'bc_amount_paid' ),
				'due'        => bcl_get_meta_float( (int) $bill->ID, 'bc_remaining_due' ),
				'status'     => bcl_get_meta_string( (int) $bill->ID, 'bc_payment_status' ),
			);
		}

		return $rows;
	}

	/**
	 * Flat-wise report.
	 */
	private function flat_wise_report( string $start_date, string $end_date ): array {
		$flats = get_posts(
			array(
				'post_type'      => 'bc_flat',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		if ( function_exists( 'bcl_prime_post_metas' ) ) {
			bcl_prime_post_metas( wp_list_pluck( $flats, 'ID' ) );
		}

		$totals = bcl_aggregate_bill_sums_by(
			'bc_flat_id',
			bcl_month_from_date( $start_date ),
			bcl_month_from_date( $end_date )
		);

		$rows = array();
		foreach ( $flats as $flat ) {
			$flat_id = (int) $flat->ID;

			$rows[] = array(
				'flat'       => $flat->post_title,
				'flat_no'    => bcl_get_meta_string( $flat_id, 'bc_flat_number' ),
				'building'   => get_the_title( (int) bcl_get_meta_float( $flat_id, 'bc_building_id' ) ),
				'collected'  => $totals[ $flat_id ]['collected'] ?? 0.0,
				'due'        => $totals[ $flat_id ]['due'] ?? 0.0,
			);
		}

		return $rows;
	}

	/**
	 * Resident-wise report.
	 */
	private function resident_wise_report( string $start_date, string $end_date ): array {
		$residents = get_posts(
			array(
				'post_type'      => 'bc_resident',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		if ( function_exists( 'bcl_prime_post_metas' ) ) {
			bcl_prime_post_metas( wp_list_pluck( $residents, 'ID' ) );
		}

		$totals = bcl_aggregate_bill_sums_by(
			'bc_resident_id',
			bcl_month_from_date( $start_date ),
			bcl_month_from_date( $end_date )
		);

		$rows = array();
		foreach ( $residents as $resident ) {
			$resident_id = (int) $resident->ID;

			$rows[] = array(
				'resident'  => $resident->post_title,
				'mobile'    => bcl_get_meta_string( $resident_id, 'bc_mobile' ),
				'flat'      => get_the_title( (int) bcl_get_meta_float( $resident_id, 'bc_assigned_flat_id' ) ),
				'collected' => $totals[ $resident_id ]['collected'] ?? 0.0,
				'due'       => $totals[ $resident_id ]['due'] ?? 0.0,
			);
		}

		return $rows;
	}

	/**
	 * Due report for all unpaid/partial bills.
	 */
	private function due_report(): array {
		$bills = get_posts(
			array(
				'post_type'      => 'bc_bill',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => 'bc_payment_status',
						'value'   => 'paid',
						'compare' => '!=',
					),
					array(
						'key'     => 'bc_carried_forward',
						'compare' => 'NOT EXISTS',
					),
				),
				'orderby'        => 'meta_value',
				'meta_key'       => 'bc_billing_month',
				'order'          => 'DESC',
			)
		);

		if ( function_exists( 'bcl_prime_post_metas' ) ) {
			bcl_prime_post_metas( wp_list_pluck( $bills, 'ID' ) );
		}

		$rows = array();
		foreach ( $bills as $bill ) {
			$rows[] = array(
				'bill'     => bcl_get_bill_display_title( (int) $bill->ID ),
				'month'    => bcl_format_billing_month( bcl_get_meta_string( (int) $bill->ID, 'bc_billing_month' ) ),
				'flat'     => bcl_get_flat_number( (int) bcl_get_meta_float( (int) $bill->ID, 'bc_flat_id' ) ),
				'resident' => get_the_title( (int) bcl_get_meta_float( (int) $bill->ID, 'bc_resident_id' ) ),
				'payable'  => bcl_get_meta_float( (int) $bill->ID, 'bc_total_payable_amount' ),
				'paid'     => bcl_get_meta_float( (int) $bill->ID, 'bc_amount_paid' ),
				'due'      => bcl_get_meta_float( (int) $bill->ID, 'bc_remaining_due' ),
				'status'   => bcl_get_meta_string( (int) $bill->ID, 'bc_payment_status' ),
			);
		}

		return $rows;
	}

	/**
	 * Expense report.
	 */
	private function expense_report( string $start_date, string $end_date ): array {
		$expenses = get_posts(
			array(
				'post_type'      => 'bc_expense',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'     => 'bc_expense_date',
						'value'   => array( $start_date, $end_date ),
						'compare' => 'BETWEEN',
						'type'    => 'DATE',
					),
				),
				'orderby'        => 'meta_value',
				'meta_key'       => 'bc_expense_date',
				'order'          => 'DESC',
			)
		);

		$rows = array();
		foreach ( $expenses as $expense ) {
			$terms = wp_get_post_terms( $expense->ID, 'bc_expense_category', array( 'fields' => 'names' ) );
			$rows[] = array(
				'title'    => $expense->post_title,
				'date'     => bcl_get_meta_string( $expense->ID, 'bc_expense_date' ),
				'category' => ! is_wp_error( $terms ) ? implode( ', ', $terms ) : '',
				'amount'   => bcl_get_meta_float( $expense->ID, 'bc_amount' ),
				'paid'     => bcl_get_meta_string( $expense->ID, 'bc_is_paid' ),
			);
		}

		return $rows;
	}

	/**
	 * Income vs expense monthly breakdown.
	 */
	private function income_vs_expense_report( string $start_date, string $end_date ): array {
		$months = $this->months_between( $start_date, $end_date );
		$rows   = array();

		foreach ( $months as $month ) {
			$range = $this->month_date_range( $month );
			$stats = $this->calculate_period_stats( $range['start'], $range['end'], $month );
			$rows[] = array(
				'month'    => bcl_format_billing_month( $month ),
				'income'   => $stats['income'],
				'expenses' => $stats['expenses'],
				'balance'  => round( $stats['income'] - $stats['expenses'], 2 ),
			);
		}

		return $rows;
	}

	/**
	 * Resolve date range from filter preset.
	 *
	 * @return array{start:string, end:string}
	 */
	public function resolve_date_range( string $filter, string $custom_start = '', string $custom_end = '' ): array {
		$today = gmdate( 'Y-m-d' );

		switch ( $filter ) {
			case 'last_6_months':
				return array(
					'start' => gmdate( 'Y-m-01', strtotime( '-5 months' ) ),
					'end'   => $today,
				);
			case 'last_12_months':
				return array(
					'start' => gmdate( 'Y-m-01', strtotime( '-11 months' ) ),
					'end'   => $today,
				);
			case 'custom':
				return array(
					'start' => $custom_start ?: gmdate( 'Y-m-01' ),
					'end'   => $custom_end ?: $today,
				);
			case 'current_month':
			default:
				return $this->month_date_range( bcl_current_billing_month() );
		}
	}

	/**
	 * Get start/end dates for a billing month.
	 *
	 * @return array{start:string, end:string}
	 */
	public function month_date_range( string $month ): array {
		$start = $month . '-01';
		$end   = gmdate( 'Y-m-t', strtotime( $start ) );

		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * List Y-m months between two dates.
	 *
	 * @return string[]
	 */
	private function months_between( string $start_date, string $end_date ): array {
		$months  = array();
		$current = strtotime( gmdate( 'Y-m-01', strtotime( $start_date ) ) );
		$end     = strtotime( gmdate( 'Y-m-01', strtotime( $end_date ) ) );

		while ( $current <= $end ) {
			$months[] = gmdate( 'Y-m', $current );
			$current  = strtotime( '+1 month', $current );
		}

		return $months;
	}

	/**
	 * AJAX report data endpoint.
	 */
	public function ajax_get_report_data(): void {
		check_ajax_referer( 'bcl_admin_nonce', 'nonce' );

		if ( ! bcl_current_user_can( 'bc_view_reports' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'buildingcare-lite' ) ) );
		}

		$report_type = sanitize_key( $_POST['report_type'] ?? 'collection' );
		$filter      = sanitize_key( $_POST['date_filter'] ?? 'current_month' );
		$range       = $this->resolve_date_range(
			$filter,
			sanitize_text_field( wp_unslash( $_POST['start_date'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_POST['end_date'] ?? '' ) )
		);

		wp_send_json_success(
			array(
				'rows'  => $this->generate_report( $report_type, $range['start'], $range['end'] ),
				'range' => $range,
			)
		);
	}
}
