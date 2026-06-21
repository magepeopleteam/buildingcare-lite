<?php
/**
 * Expense and recurring expense handling.
 *
 * @package BuildingCareLite
 */

declare(strict_types=1);

namespace BuildingCareLite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages expenses and recurring expense automation.
 */
class Expenses {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_bcl_mark_expense_paid', array( $this, 'ajax_mark_expense_paid' ) );
		add_action( 'wp_ajax_bcl_pay_recurring_expense', array( $this, 'ajax_mark_expense_paid' ) );
		add_filter( 'post_row_actions', array( $this, 'expense_row_actions' ), 10, 2 );
	}

	/**
	 * Add quick "Mark Paid" action on expense list rows.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param \WP_Post              $post    Post object.
	 * @return array<string, string>
	 */
	public function expense_row_actions( array $actions, \WP_Post $post ): array {
		if ( 'bc_expense' !== $post->post_type || ! bcl_current_user_can( 'bc_manage_expenses' ) ) {
			return $actions;
		}

		if ( bcl_get_meta_string( $post->ID, 'bc_is_paid' ) !== 'yes' ) {
			$actions['bcl_mark_paid'] = sprintf(
				'<a href="#" class="bcl-mark-expense-paid" data-expense-id="%d">%s</a>',
				$post->ID,
				esc_html__( 'Mark Paid', 'buildingcare-lite' )
			);
		}

		return $actions;
	}

	/**
	 * Generate monthly vouchers from active recurring expenses.
	 *
	 * @return int Number of expenses created.
	 */
	public function generate_recurring_expenses( string $month ): int {
		$recurring = get_posts(
			array(
				'post_type'      => 'bc_recurring_expense',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => 'bc_active_status',
						'value' => 'yes',
					),
				),
			)
		);

		$created = 0;
		foreach ( $recurring as $recurring_id ) {
			$recurring_id = (int) $recurring_id;
			if ( $this->recurring_voucher_exists( $recurring_id, $month ) ) {
				continue;
			}

			$expense_id = $this->create_voucher_from_recurring( $recurring_id, $month );
			if ( $expense_id ) {
				++$created;
			}
		}

		if ( $created > 0 ) {
			bcl_audit_log(
				'recurring_expenses_generated',
				sprintf(
					/* translators: 1: count, 2: month */
					__( '%1$d recurring expense vouchers created for %2$s', 'buildingcare-lite' ),
					$created,
					$month
				),
				array( 'month' => $month )
			);
			bcl_clear_dashboard_cache();
		}

		return $created;
	}

	/**
	 * Check duplicate recurring voucher.
	 */
	public function recurring_voucher_exists( int $recurring_id, string $month ): bool {
		$existing = get_posts(
			array(
				'post_type'      => 'bc_expense',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => 'bc_recurring_expense_id',
						'value' => $recurring_id,
					),
					array(
						'key'   => 'bc_expense_month',
						'value' => $month,
					),
				),
			)
		);

		return ! empty( $existing );
	}

	/**
	 * Create expense voucher from recurring template.
	 */
	public function create_voucher_from_recurring( int $recurring_id, string $month ): int {
		$title  = get_the_title( $recurring_id );
		$amount = bcl_get_meta_float( $recurring_id, 'bc_monthly_amount' );
		$terms  = wp_get_post_terms( $recurring_id, 'bc_expense_category', array( 'fields' => 'ids' ) );

		$expense_id = wp_insert_post(
			array(
				'post_type'   => 'bc_expense',
				'post_status' => 'publish',
				'post_title'  => sprintf(
					/* translators: 1: title, 2: month */
					__( '%1$s — %2$s', 'buildingcare-lite' ),
					$title,
					$month
				),
			),
			true
		);

		if ( is_wp_error( $expense_id ) ) {
			return 0;
		}

		$expense_id = (int) $expense_id;
		$expense_date = $month . '-01';

		update_post_meta( $expense_id, 'bc_expense_date', $expense_date );
		update_post_meta( $expense_id, 'bc_description', $title );
		update_post_meta( $expense_id, 'bc_amount', $amount );
		update_post_meta( $expense_id, 'bc_recurring_expense_id', $recurring_id );
		update_post_meta( $expense_id, 'bc_expense_month', $month );
		update_post_meta( $expense_id, 'bc_is_paid', 'no' );

		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			wp_set_post_terms( $expense_id, $terms, 'bc_expense_category' );
		}

		return $expense_id;
	}

	/**
	 * Mark an expense as paid with one click.
	 */
	public function mark_expense_paid( int $expense_id ): bool {
		if ( ! bcl_current_user_can( 'bc_manage_expenses' ) ) {
			return false;
		}

		update_post_meta( $expense_id, 'bc_is_paid', 'yes' );
		bcl_audit_log(
			'expense_paid',
			sprintf(
				/* translators: %d: expense id */
				__( 'Expense #%d marked as paid', 'buildingcare-lite' ),
				$expense_id
			)
		);
		bcl_clear_dashboard_cache();

		return true;
	}

	/**
	 * AJAX handler for marking expense paid.
	 */
	public function ajax_mark_expense_paid(): void {
		check_ajax_referer( 'bcl_admin_nonce', 'nonce' );

		$expense_id = absint( $_POST['expense_id'] ?? 0 );
		if ( ! $expense_id || ! $this->mark_expense_paid( $expense_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unable to mark expense as paid.', 'buildingcare-lite' ) ) );
		}

		wp_send_json_success( array( 'expense_id' => $expense_id ) );
	}

	/**
	 * Sum expenses in a date range.
	 */
	public function sum_expenses( string $start_date, string $end_date, int $building_id = 0 ): float {
		if ( function_exists( __NAMESPACE__ . '\\bcl_sum_meta_between_dates' ) && 0 === $building_id ) {
			return bcl_sum_meta_between_dates( 'bc_expense', 'bc_amount', 'bc_expense_date', $start_date, $end_date );
		}

		$meta_query = array(
			array(
				'key'     => 'bc_expense_date',
				'value'   => array( $start_date, $end_date ),
				'compare' => 'BETWEEN',
				'type'    => 'DATE',
			),
		);

		$expenses = get_posts(
			array(
				'post_type'      => 'bc_expense',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => $meta_query,
			)
		);

		$total = 0.0;
		foreach ( $expenses as $expense_id ) {
			$total += bcl_get_meta_float( (int) $expense_id, 'bc_amount' );
		}

		return round( $total, 2 );
	}
}
