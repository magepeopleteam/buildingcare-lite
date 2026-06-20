<?php
/**
 * WordPress Cron scheduling.
 *
 * @package BuildingCareLite
 */

declare(strict_types=1);

namespace BuildingCareLite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedules monthly automation tasks.
 */
class Cron {

	public const HOOK_MONTHLY = 'bcl_monthly_tasks';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( self::HOOK_MONTHLY, array( $this, 'run_monthly_tasks' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
	}

	/**
	 * Schedule cron on init if missing.
	 */
	public function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK_MONTHLY ) ) {
			self::schedule_events();
		}
	}

	/**
	 * Schedule monthly event for the first day of next month.
	 */
	public static function schedule_events(): void {
		wp_clear_scheduled_hook( self::HOOK_MONTHLY );

		$timestamp = strtotime( 'first day of next month 00:05:00' );
		if ( ! $timestamp ) {
			$timestamp = time() + DAY_IN_SECONDS;
		}

		wp_schedule_event( $timestamp, 'monthly', self::HOOK_MONTHLY );
	}

	/**
	 * Clear scheduled events on deactivation.
	 */
	public static function clear_events(): void {
		wp_clear_scheduled_hook( self::HOOK_MONTHLY );
	}

	/**
	 * Run monthly bill and recurring expense generation.
	 */
	public function run_monthly_tasks(): void {
		$billing = new Billing();
		$billing->generate_monthly_bills( bcl_current_billing_month() );

		$expenses = new Expenses();
		$expenses->generate_recurring_expenses( bcl_current_billing_month() );

		bcl_audit_log(
			'monthly_cron',
			sprintf(
				/* translators: %s: billing month */
				__( 'Monthly cron executed for %s', 'buildingcare-lite' ),
				bcl_current_billing_month()
			)
		);
		bcl_clear_dashboard_cache();
	}
}

// Register monthly cron schedule.
add_filter(
	'cron_schedules',
	static function ( array $schedules ): array {
		if ( ! isset( $schedules['monthly'] ) ) {
			$schedules['monthly'] = array(
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => __( 'Once Monthly', 'buildingcare-lite' ),
			);
		}
		return $schedules;
	}
);
