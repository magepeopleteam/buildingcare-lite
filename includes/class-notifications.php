<?php
/**
 * Email notifications and reminders.
 *
 * @package BuildingCareLite
 */

declare(strict_types=1);

namespace BuildingCareLite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends transactional emails to residents.
 */
class Notifications {

	public const HOOK_REMINDERS = 'bcl_daily_reminders';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( self::HOOK_REMINDERS, array( $this, 'send_due_reminders' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
	}

	/**
	 * Schedule the daily reminder check if reminders are enabled.
	 */
	public function maybe_schedule(): void {
		$enabled = 'yes' === ( bcl_get_settings()['enable_reminders'] ?? 'no' );

		if ( $enabled && ! wp_next_scheduled( self::HOOK_REMINDERS ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK_REMINDERS );
		}

		if ( ! $enabled && wp_next_scheduled( self::HOOK_REMINDERS ) ) {
			wp_clear_scheduled_hook( self::HOOK_REMINDERS );
		}
	}

	/**
	 * Clear scheduled reminder events.
	 */
	public static function clear_events(): void {
		wp_clear_scheduled_hook( self::HOOK_REMINDERS );
	}

	/**
	 * Whether email notifications are enabled.
	 */
	private static function emails_enabled(): bool {
		return 'yes' === ( bcl_get_settings()['enable_emails'] ?? 'no' );
	}

	/**
	 * Resolve a resident's email address.
	 */
	private static function resident_email( int $resident_id ): string {
		if ( $resident_id <= 0 ) {
			return '';
		}

		$email = bcl_get_meta_string( $resident_id, 'bc_email' );

		return is_email( $email ) ? $email : '';
	}

	/**
	 * Notify the site admin that a new maintenance request was submitted.
	 *
	 * Sent regardless of the resident-email opt-in (it goes to the operator).
	 */
	public static function ticket_created( int $ticket_id ): void {
		$admin_email = sanitize_email( (string) get_option( 'admin_email' ) );
		if ( ! is_email( $admin_email ) ) {
			return;
		}

		$categories = bcl_ticket_categories();
		$priorities = bcl_ticket_priorities();
		$flat_id    = (int) bcl_get_meta_float( $ticket_id, 'bc_flat_id' );
		$flat       = $flat_id ? bcl_get_flat_number( $flat_id ) : '';

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] New maintenance request', 'buildingcare-lite' ),
			wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES )
		);

		$lines = array(
			__( 'A new maintenance request has been submitted.', 'buildingcare-lite' ),
			'',
			sprintf( /* translators: %s: subject */ __( 'Subject: %s', 'buildingcare-lite' ), get_the_title( $ticket_id ) ),
			sprintf( /* translators: %s: flat */ __( 'Flat: %s', 'buildingcare-lite' ), $flat ?: '—' ),
			sprintf( /* translators: %s: category */ __( 'Category: %s', 'buildingcare-lite' ), $categories[ bcl_get_meta_string( $ticket_id, 'bc_ticket_category' ) ] ?? '—' ),
			sprintf( /* translators: %s: priority */ __( 'Priority: %s', 'buildingcare-lite' ), $priorities[ bcl_get_meta_string( $ticket_id, 'bc_ticket_priority' ) ] ?? '—' ),
			'',
			bcl_get_meta_string( $ticket_id, 'bc_description' ),
		);

		wp_mail( $admin_email, $subject, implode( "\n", $lines ) );
	}

	/**
	 * Notify the resident that their maintenance request status/response changed.
	 */
	public static function ticket_updated( int $ticket_id ): void {
		if ( ! self::emails_enabled() ) {
			return;
		}

		$resident_id = (int) bcl_get_meta_float( $ticket_id, 'bc_resident_id' );
		$email       = self::resident_email( $resident_id );
		if ( '' === $email ) {
			return;
		}

		$statuses = bcl_ticket_statuses();
		$status   = bcl_get_meta_string( $ticket_id, 'bc_ticket_status' );
		$response = bcl_get_meta_string( $ticket_id, 'bc_admin_response' );

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Update on your maintenance request', 'buildingcare-lite' ),
			wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES )
		);

		$lines = array(
			sprintf( /* translators: %s: subject */ __( 'Your request "%s" has been updated.', 'buildingcare-lite' ), get_the_title( $ticket_id ) ),
			'',
			sprintf( /* translators: %s: status */ __( 'Status: %s', 'buildingcare-lite' ), $statuses[ $status ] ?? $status ),
		);

		if ( '' !== $response ) {
			$lines[] = '';
			$lines[] = __( 'Message from management:', 'buildingcare-lite' );
			$lines[] = $response;
		}

		wp_mail( $email, $subject, implode( "\n", $lines ) );
	}

	/**
	 * Resolve a resident email for a bill.
	 */
	private static function resident_email_for_bill( int $bill_id ): string {
		$resident_id = (int) bcl_get_meta_float( $bill_id, 'bc_resident_id' );
		if ( $resident_id <= 0 ) {
			$flat_id     = (int) bcl_get_meta_float( $bill_id, 'bc_flat_id' );
			$resident_id = $flat_id ? bcl_get_resident_for_flat( $flat_id ) : 0;
		}

		if ( $resident_id <= 0 ) {
			return '';
		}

		$email = bcl_get_meta_string( $resident_id, 'bc_email' );

		return is_email( $email ) ? $email : '';
	}

	/**
	 * Send a payment receipt email.
	 */
	public static function payment_received( int $bill_id, float $amount, float $remaining_due ): void {
		if ( ! self::emails_enabled() ) {
			return;
		}

		$email = self::resident_email_for_bill( $bill_id );
		if ( '' === $email ) {
			return;
		}

		$flat  = bcl_get_bill_display_title( $bill_id );
		$month = bcl_format_billing_month( bcl_get_meta_string( $bill_id, 'bc_billing_month' ) );

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Payment received', 'buildingcare-lite' ),
			wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES )
		);

		$lines = array(
			__( 'We have received your payment. Thank you.', 'buildingcare-lite' ),
			'',
			sprintf( /* translators: %s: flat */ __( 'Flat: %s', 'buildingcare-lite' ), $flat ),
			sprintf( /* translators: %s: month */ __( 'Billing month: %s', 'buildingcare-lite' ), $month ),
			sprintf( /* translators: %s: amount */ __( 'Amount paid: %s', 'buildingcare-lite' ), bcl_format_amount( $amount ) ),
			sprintf( /* translators: %s: due */ __( 'Remaining due: %s', 'buildingcare-lite' ), bcl_format_amount( $remaining_due ) ),
		);

		wp_mail( $email, $subject, implode( "\n", $lines ) );
	}

	/**
	 * Daily: email residents whose bills are due today or overdue and still unpaid.
	 */
	public function send_due_reminders(): void {
		if ( ! self::emails_enabled() ) {
			return;
		}

		$today = current_time( 'Y-m-d' );

		$bills = get_posts(
			array(
				'post_type'      => 'bc_bill',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => 'bc_payment_status',
						'value'   => 'paid',
						'compare' => '!=',
					),
					array(
						'key'     => 'bc_due_date',
						'value'   => $today,
						'compare' => '<=',
						'type'    => 'DATE',
					),
					array(
						'key'     => 'bc_carried_forward',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		if ( function_exists( __NAMESPACE__ . '\\bcl_prime_post_metas' ) && ! empty( $bills ) ) {
			bcl_prime_post_metas( wp_list_pluck( $bills, 'ID' ) );
		}

		$sent = 0;
		foreach ( $bills as $bill ) {
			$bill_id   = (int) $bill->ID;
			$remaining = bcl_get_meta_float( $bill_id, 'bc_remaining_due' );
			if ( $remaining <= 0 ) {
				continue;
			}

			$email = self::resident_email_for_bill( $bill_id );
			if ( '' === $email ) {
				continue;
			}

			$flat     = bcl_get_bill_display_title( $bill_id );
			$month    = bcl_format_billing_month( bcl_get_meta_string( $bill_id, 'bc_billing_month' ) );
			$due_date = bcl_get_meta_string( $bill_id, 'bc_due_date' );

			$subject = sprintf(
				/* translators: %s: site name */
				__( '[%s] Service charge payment reminder', 'buildingcare-lite' ),
				wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES )
			);

			$lines = array(
				__( 'This is a friendly reminder that your service charge is outstanding.', 'buildingcare-lite' ),
				'',
				sprintf( /* translators: %s: flat */ __( 'Flat: %s', 'buildingcare-lite' ), $flat ),
				sprintf( /* translators: %s: month */ __( 'Billing month: %s', 'buildingcare-lite' ), $month ),
				sprintf( /* translators: %s: due date */ __( 'Due date: %s', 'buildingcare-lite' ), $due_date ),
				sprintf( /* translators: %s: amount */ __( 'Amount due: %s', 'buildingcare-lite' ), bcl_format_amount( $remaining ) ),
			);

			if ( wp_mail( $email, $subject, implode( "\n", $lines ) ) ) {
				++$sent;
			}
		}

		if ( $sent > 0 ) {
			bcl_audit_log(
				'due_reminders_sent',
				sprintf(
					/* translators: %d: count */
					__( '%d due reminder emails sent', 'buildingcare-lite' ),
					$sent
				)
			);
		}
	}
}
