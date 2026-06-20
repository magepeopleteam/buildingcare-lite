<?php
/**
 * Billing generation and payment workflow.
 *
 * @package BuildingCareLite
 */

declare(strict_types=1);

namespace BuildingCareLite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles bill generation and payments.
 */
class Billing {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_bcl_record_payment', array( $this, 'ajax_record_payment' ) );
		add_action( 'wp_ajax_bcl_mark_bill_paid', array( $this, 'ajax_mark_bill_paid' ) );
		add_action( 'wp_ajax_bcl_collect_payment', array( $this, 'ajax_mark_bill_paid' ) );
		add_action( 'admin_post_bcl_generate_monthly', array( $this, 'handle_monthly_generation' ) );
		add_action( 'admin_post_bcl_generate_bills', array( $this, 'handle_monthly_generation' ) );
	}

	/**
	 * Generate bills for all flats in a month.
	 *
	 * Occupied flats are billed at 100% service charge.
	 * Vacant flats are billed at 50% service charge.
	 *
	 * @return int Number of bills created.
	 */
	public function generate_monthly_bills( string $billing_month ): int {
		$flats = get_posts(
			array(
				'post_type'      => 'bc_flat',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$created = 0;
		foreach ( $flats as $flat_id ) {
			$flat_id = (int) $flat_id;
			if ( $this->bill_exists( $flat_id, $billing_month ) ) {
				continue;
			}

			$bill_id = $this->create_bill_for_flat( $flat_id, $billing_month );
			if ( $bill_id ) {
				++$created;
			}
		}

		if ( $created > 0 ) {
			bcl_audit_log(
				'bills_generated',
				sprintf(
					/* translators: 1: count, 2: month */
					__( '%1$d bills generated for %2$s', 'buildingcare-lite' ),
					$created,
					$billing_month
				),
				array( 'month' => $billing_month )
			);
			bcl_clear_dashboard_cache();
		}

		return $created;
	}

	/**
	 * Check if a bill already exists for flat and month.
	 */
	public function bill_exists( int $flat_id, string $billing_month ): bool {
		$existing = get_posts(
			array(
				'post_type'      => 'bc_bill',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => 'bc_flat_id',
						'value' => $flat_id,
					),
					array(
						'key'   => 'bc_billing_month',
						'value' => $billing_month,
					),
				),
			)
		);

		return ! empty( $existing );
	}

	/**
	 * Create a bill for a single flat.
	 */
	public function create_bill_for_flat( int $flat_id, string $billing_month ): int {
		$building_id    = (int) bcl_get_meta_float( $flat_id, 'bc_building_id' );
		$billing        = bcl_get_flat_billing_details( $flat_id );
		$service_charge = $billing['service_charge'];
		$occupancy      = $billing['occupancy_status'];
		$previous_due   = bcl_calculate_previous_due( $flat_id, $billing_month );
		$resident_id    = 'occupied' === $occupancy ? bcl_get_resident_for_flat( $flat_id ) : 0;
		$total_payable  = round( $service_charge + $previous_due, 2 );
		$title          = bcl_get_flat_number( $flat_id ) ?: (string) $flat_id;

		$bill_id = bcl_run_with_bill_insert_allowed(
			static function () use ( $title ) {
				return wp_insert_post(
					array(
						'post_type'   => 'bc_bill',
						'post_status' => 'publish',
						'post_title'  => $title,
					),
					true
				);
			}
		);

		if ( is_wp_error( $bill_id ) ) {
			return 0;
		}

		$bill_id = (int) $bill_id;
		update_post_meta( $bill_id, 'bc_building_id', $building_id );
		update_post_meta( $bill_id, 'bc_flat_id', $flat_id );
		update_post_meta( $bill_id, 'bc_resident_id', $resident_id );
		update_post_meta( $bill_id, 'bc_billing_month', $billing_month );
		update_post_meta( $bill_id, 'bc_occupancy_status', $occupancy );
		update_post_meta( $bill_id, 'bc_service_charge_amount', $service_charge );
		update_post_meta( $bill_id, 'bc_previous_due_amount', $previous_due );
		update_post_meta( $bill_id, 'bc_total_payable_amount', $total_payable );
		update_post_meta( $bill_id, 'bc_amount_paid', 0 );
		update_post_meta( $bill_id, 'bc_remaining_due', $total_payable );
		update_post_meta( $bill_id, 'bc_payment_status', 'unpaid' );

		return $bill_id;
	}

	/**
	 * Record a payment against a bill.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function record_payment( int $bill_id, float $amount, string $method, bool $mark_full = false ) {
		if ( ! bcl_current_user_can( 'bc_manage_payments' ) ) {
			return new \WP_Error( 'forbidden', __( 'Permission denied.', 'buildingcare-lite' ), array( 'status' => 403 ) );
		}

		$total_payable = bcl_get_meta_float( $bill_id, 'bc_total_payable_amount' );
		$already_paid  = bcl_get_meta_float( $bill_id, 'bc_amount_paid' );

		if ( $mark_full ) {
			$amount = max( 0, $total_payable - $already_paid );
		}

		$amount = round( max( 0, $amount ), 2 );
		if ( $amount <= 0 && ! $mark_full ) {
			return new \WP_Error( 'invalid_amount', __( 'Payment amount must be greater than zero.', 'buildingcare-lite' ) );
		}

		$new_paid      = round( $already_paid + $amount, 2 );
		$remaining_due = max( 0, round( $total_payable - $new_paid, 2 ) );

		if ( $remaining_due <= 0 ) {
			$status        = 'paid';
			$remaining_due = 0;
			$new_paid      = $total_payable;
		} elseif ( $new_paid > 0 ) {
			$status = 'partial';
		} else {
			$status = 'unpaid';
		}

		update_post_meta( $bill_id, 'bc_amount_paid', $new_paid );
		update_post_meta( $bill_id, 'bc_remaining_due', $remaining_due );
		update_post_meta( $bill_id, 'bc_payment_status', $status );
		update_post_meta( $bill_id, 'bc_payment_date', gmdate( 'Y-m-d' ) );
		update_post_meta( $bill_id, 'bc_payment_method', sanitize_key( $method ) );

		bcl_audit_log(
			'payment_recorded',
			sprintf(
				/* translators: 1: bill id, 2: amount */
				__( 'Payment of %2$s recorded for bill #%1$d', 'buildingcare-lite' ),
				$bill_id,
				bcl_format_amount( $amount )
			),
			array(
				'bill_id' => $bill_id,
				'amount'  => $amount,
				'status'  => $status,
			)
		);
		bcl_clear_dashboard_cache();

		return array(
			'bill_id'         => $bill_id,
			'amount_paid'     => $new_paid,
			'remaining_due'   => $remaining_due,
			'payment_status'  => $status,
		);
	}

	/**
	 * AJAX: record partial or full payment.
	 */
	public function ajax_record_payment(): void {
		check_ajax_referer( 'bcl_admin_nonce', 'nonce' );

		$bill_id   = absint( $_POST['bill_id'] ?? 0 );
		$amount    = (float) ( $_POST['amount'] ?? 0 );
		$method    = sanitize_key( $_POST['payment_method'] ?? 'cash' );
		$mark_full = ! empty( $_POST['mark_full'] );

		$result = $this->record_payment( $bill_id, $amount, $method, $mark_full );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: one-click mark as paid.
	 */
	public function ajax_mark_bill_paid(): void {
		check_ajax_referer( 'bcl_admin_nonce', 'nonce' );

		$bill_id = absint( $_POST['bill_id'] ?? 0 );
		$method  = sanitize_key( $_POST['payment_method'] ?? 'cash' );
		$result  = $this->record_payment( $bill_id, 0, $method, true );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Handle monthly generation from Bills & Payments page.
	 */
	public function handle_monthly_generation(): void {
		check_admin_referer( 'bcl_generate_monthly' );

		$month = sanitize_text_field( wp_unslash( $_POST['billing_month'] ?? bcl_current_billing_month() ) );
		$type  = sanitize_key( wp_unslash( $_POST['generate_type'] ?? 'all' ) );

		if ( ! in_array( $type, array( 'bills', 'recurring', 'all' ), true ) ) {
			$type = 'all';
		}

		$bills_created     = 0;
		$recurring_created = 0;

		if ( in_array( $type, array( 'bills', 'all' ), true ) && bcl_current_user_can( 'bc_generate_bills' ) ) {
			$bills_created = $this->generate_monthly_bills( $month );
		}

		if ( in_array( $type, array( 'recurring', 'all' ), true ) && bcl_current_user_can( 'bc_manage_expenses' ) ) {
			$recurring_created = ( new Expenses() )->generate_recurring_expenses( $month );
		}

		if ( ! bcl_current_user_can( 'bc_generate_bills' ) && ! bcl_current_user_can( 'bc_manage_expenses' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'buildingcare-lite' ) );
		}

		$redirect = add_query_arg(
			array(
				'page'              => 'bcl-bills',
				'billing_month'     => $month,
				'bills_created'     => $bills_created,
				'recurring_created' => $recurring_created,
				'generate_type'     => $type,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}
}
