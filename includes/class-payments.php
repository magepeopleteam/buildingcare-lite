<?php
/**
 * Payment ledger.
 *
 * Each recorded payment is stored as an immutable `bc_payment` entry so that
 * income can be attributed to the actual payment date and partial payment
 * history is preserved (the bill itself only keeps running totals).
 *
 * @package BuildingCareLite
 */

declare(strict_types=1);

namespace BuildingCareLite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the payment ledger custom post type.
 */
class Payments {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
	}

	/**
	 * Register the internal ledger post type.
	 */
	public function register_post_type(): void {
		register_post_type(
			'bc_payment',
			array(
				'labels'          => array(
					'name'          => __( 'Payments', 'buildingcare-lite' ),
					'singular_name' => __( 'Payment', 'buildingcare-lite' ),
				),
				'public'          => false,
				'show_ui'         => false,
				'show_in_menu'    => false,
				'show_in_rest'    => false,
				'capability_type' => array( 'bc_payment', 'bc_payments' ),
				'map_meta_cap'    => true,
				'capabilities'    => array(
					'create_posts' => 'bc_create_payments',
				),
				'supports'        => array( 'title' ),
				'has_archive'     => false,
				'rewrite'         => false,
			)
		);
	}

	/**
	 * Record a payment ledger entry.
	 *
	 * @param array{bill_id:int, flat_id:int, resident_id:int, amount:float, method:string, date:string} $args Payment data.
	 * @return int Ledger post ID (0 on failure).
	 */
	public static function record( array $args ): int {
		$bill_id = (int) ( $args['bill_id'] ?? 0 );
		$amount  = round( (float) ( $args['amount'] ?? 0 ), 2 );
		if ( $bill_id <= 0 || $amount <= 0 ) {
			return 0;
		}

		$date = (string) ( $args['date'] ?? current_time( 'Y-m-d' ) );

		$payment_id = bcl_run_with_payment_insert_allowed(
			static function () use ( $bill_id, $date ) {
				return wp_insert_post(
					array(
						'post_type'   => 'bc_payment',
						'post_status' => 'publish',
						'post_title'  => sprintf(
							/* translators: 1: bill id, 2: date */
							__( 'Payment for bill #%1$d on %2$s', 'buildingcare-lite' ),
							$bill_id,
							$date
						),
					),
					true
				);
			}
		);

		if ( is_wp_error( $payment_id ) ) {
			return 0;
		}

		$payment_id = (int) $payment_id;
		update_post_meta( $payment_id, 'bc_bill_id', $bill_id );
		update_post_meta( $payment_id, 'bc_flat_id', (int) ( $args['flat_id'] ?? 0 ) );
		update_post_meta( $payment_id, 'bc_resident_id', (int) ( $args['resident_id'] ?? 0 ) );
		update_post_meta( $payment_id, 'bc_amount', $amount );
		update_post_meta( $payment_id, 'bc_payment_method', sanitize_key( (string) ( $args['method'] ?? 'cash' ) ) );
		update_post_meta( $payment_id, 'bc_payment_date', $date );
		update_post_meta( $payment_id, 'bc_recorded_by', get_current_user_id() );

		return $payment_id;
	}

	/**
	 * Sum ledger payments between two dates (inclusive).
	 */
	public static function sum_between( string $start_date, string $end_date ): float {
		return bcl_sum_meta_between_dates( 'bc_payment', 'bc_amount', 'bc_payment_date', $start_date, $end_date );
	}

	/**
	 * Whether any ledger entries exist (used to decide income source for legacy data).
	 */
	public static function has_entries(): bool {
		$found = get_posts(
			array(
				'post_type'      => 'bc_payment',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		return ! empty( $found );
	}

	/**
	 * Get the payment history for a bill.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_bill( int $bill_id ): array {
		$payments = get_posts(
			array(
				'post_type'      => 'bc_payment',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'meta_key'       => 'bc_payment_date',
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'   => 'bc_bill_id',
						'value' => $bill_id,
					),
				),
			)
		);

		$rows = array();
		foreach ( $payments as $payment ) {
			$rows[] = array(
				'amount' => bcl_get_meta_float( (int) $payment->ID, 'bc_amount' ),
				'method' => bcl_get_meta_string( (int) $payment->ID, 'bc_payment_method' ),
				'date'   => bcl_get_meta_string( (int) $payment->ID, 'bc_payment_date' ),
			);
		}

		return $rows;
	}
}
