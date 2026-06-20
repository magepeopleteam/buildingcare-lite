<?php
/**
 * CSV export handler.
 *
 * @package BuildingCareLite
 */

declare(strict_types=1);

namespace BuildingCareLite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports report data to CSV.
 */
class Export {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_post_bcl_export_csv', array( $this, 'handle_export' ) );
	}

	/**
	 * Handle CSV export request.
	 */
	public function handle_export(): void {
		if ( ! bcl_current_user_can( 'bc_view_reports' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'buildingcare-lite' ) );
		}

		check_admin_referer( 'bcl_export_csv' );

		$report_type = sanitize_key( $_GET['report_type'] ?? 'collection' );
		$filter      = sanitize_key( $_GET['date_filter'] ?? 'current_month' );
		$reports     = new Reports();
		$range       = $reports->resolve_date_range(
			$filter,
			sanitize_text_field( wp_unslash( $_GET['start_date'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_GET['end_date'] ?? '' ) )
		);
		$rows        = $reports->generate_report( $report_type, $range['start'], $range['end'] );

		$filename = 'buildingcare-' . $report_type . '-' . gmdate( 'Y-m-d' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			wp_die( esc_html__( 'Unable to export CSV.', 'buildingcare-lite' ) );
		}

		// UTF-8 BOM for Excel compatibility.
		fwrite( $output, "\xEF\xBB\xBF" );

		if ( ! empty( $rows ) ) {
			fputcsv( $output, array_keys( $rows[0] ) );
			foreach ( $rows as $row ) {
				fputcsv( $output, array_values( $row ) );
			}
		}

		fclose( $output );
		bcl_audit_log( 'csv_export', sprintf( 'Exported %s report', $report_type ) );
		exit;
	}
}
