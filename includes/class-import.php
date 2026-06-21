<?php
/**
 * CSV bulk import for flats and residents.
 *
 * @package BuildingCareLite
 */

declare(strict_types=1);

namespace BuildingCareLite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles CSV uploads that create/update flats and residents.
 */
class Import {

	private const MAX_BYTES = 2097152; // 2 MB.

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_post_bcl_import_csv', array( $this, 'handle_import' ) );
		add_action( 'admin_post_bcl_import_sample', array( $this, 'download_sample' ) );
	}

	/**
	 * Expected columns and example rows per import type.
	 *
	 * @return array<string, array{headers:string[], rows:array<int, string[]>}>
	 */
	public static function templates(): array {
		return array(
			'flats'     => array(
				'headers' => array( 'building', 'flat_number', 'floor_number', 'flat_size', 'monthly_service_charge', 'occupancy_status' ),
				'rows'    => array(
					array( 'Sunrise Tower', 'A-101', '1', '1200', '5000', 'occupied' ),
					array( 'Sunrise Tower', 'A-102', '1', '1100', '4500', 'vacant' ),
				),
			),
			'residents' => array(
				'headers' => array( 'name', 'mobile', 'email', 'flat_number', 'move_in_date' ),
				'rows'    => array(
					array( 'John Doe', '01700000000', 'john@example.com', 'A-101', '2026-01-15' ),
					array( 'Jane Roe', '01800000000', 'jane@example.com', 'A-102', '2026-02-01' ),
				),
			),
		);
	}

	/**
	 * Stream a sample CSV (headers + example rows) for an import type.
	 */
	public function download_sample(): void {
		$type = isset( $_GET['import_type'] ) ? sanitize_key( wp_unslash( $_GET['import_type'] ) ) : '';
		check_admin_referer( 'bcl_import_sample' );

		$templates = self::templates();
		if ( ! isset( $templates[ $type ] ) ) {
			wp_die( esc_html__( 'Unknown import type.', 'buildingcare-lite' ) );
		}

		$cap = 'flats' === $type ? 'bc_manage_flats' : 'bc_manage_residents';
		if ( ! bcl_current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Permission denied.', 'buildingcare-lite' ) );
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=buildingcare-' . $type . '-sample.csv' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			wp_die( esc_html__( 'Unable to generate sample.', 'buildingcare-lite' ) );
		}

		fwrite( $output, "\xEF\xBB\xBF" ); // UTF-8 BOM for Excel.
		fputcsv( $output, $templates[ $type ]['headers'] );
		foreach ( $templates[ $type ]['rows'] as $row ) {
			fputcsv( $output, $row );
		}
		fclose( $output );
		exit;
	}

	/**
	 * Build a nonce-protected sample-download URL for an import type.
	 */
	public static function sample_url( string $type ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'      => 'bcl_import_sample',
					'import_type' => $type,
				),
				admin_url( 'admin-post.php' )
			),
			'bcl_import_sample'
		);
	}

	/**
	 * Handle the CSV upload.
	 */
	public function handle_import(): void {
		check_admin_referer( 'bcl_import_csv' );

		$type = isset( $_POST['import_type'] ) ? sanitize_key( wp_unslash( $_POST['import_type'] ) ) : '';
		if ( ! in_array( $type, array( 'flats', 'residents' ), true ) ) {
			$this->redirect_error( __( 'Unknown import type.', 'buildingcare-lite' ) );
		}

		$cap = 'flats' === $type ? 'bc_manage_flats' : 'bc_manage_residents';
		if ( ! bcl_current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Permission denied.', 'buildingcare-lite' ) );
		}

		$rows = $this->read_uploaded_csv();
		if ( is_wp_error( $rows ) ) {
			$this->redirect_error( $rows->get_error_message() );
		}

		$result = 'flats' === $type ? $this->import_flats( $rows ) : $this->import_residents( $rows );

		bcl_audit_log(
			'csv_import',
			sprintf(
				/* translators: 1: type, 2: created, 3: updated, 4: skipped */
				__( 'CSV import (%1$s): %2$d created, %3$d updated, %4$d skipped', 'buildingcare-lite' ),
				$type,
				$result['created'],
				$result['updated'],
				$result['skipped']
			)
		);

		if ( function_exists( __NAMESPACE__ . '\\bcl_invalidate_options_caches' ) ) {
			bcl_invalidate_options_caches();
		}
		bcl_clear_dashboard_cache();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'bcl-dashboard',
					'tab'         => 'import',
					'imported'    => '1',
					'imp_created' => (int) $result['created'],
					'imp_updated' => (int) $result['updated'],
					'imp_skipped' => (int) $result['skipped'],
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Validate and parse the uploaded CSV into associative rows.
	 *
	 * @return array<int, array<string, string>>|\WP_Error
	 */
	private function read_uploaded_csv() {
		if ( empty( $_FILES['bcl_csv'] ) || ! isset( $_FILES['bcl_csv']['tmp_name'] ) ) {
			return new \WP_Error( 'no_file', __( 'No file was uploaded.', 'buildingcare-lite' ) );
		}

		$file = $_FILES['bcl_csv']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( ! empty( $file['error'] ) ) {
			return new \WP_Error( 'upload_error', __( 'The file could not be uploaded.', 'buildingcare-lite' ) );
		}

		$tmp_name = isset( $file['tmp_name'] ) ? sanitize_text_field( $file['tmp_name'] ) : '';
		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			return new \WP_Error( 'invalid_upload', __( 'Invalid upload.', 'buildingcare-lite' ) );
		}

		if ( (int) ( $file['size'] ?? 0 ) > self::MAX_BYTES ) {
			return new \WP_Error( 'too_large', __( 'The CSV file is too large (max 2 MB).', 'buildingcare-lite' ) );
		}

		$name      = isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : '';
		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( 'csv' !== $extension ) {
			return new \WP_Error( 'bad_type', __( 'Please upload a .csv file.', 'buildingcare-lite' ) );
		}

		$handle = fopen( $tmp_name, 'r' );
		if ( false === $handle ) {
			return new \WP_Error( 'unreadable', __( 'The file could not be read.', 'buildingcare-lite' ) );
		}

		$headers = fgetcsv( $handle );
		if ( ! is_array( $headers ) ) {
			fclose( $handle );
			return new \WP_Error( 'empty', __( 'The CSV file is empty.', 'buildingcare-lite' ) );
		}

		// Normalize headers: lowercase, trimmed, spaces -> underscores; strip a UTF-8 BOM.
		$headers = array_map(
			static function ( $h ): string {
				$h = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h );
				return str_replace( ' ', '_', strtolower( trim( (string) $h ) ) );
			},
			$headers
		);

		$rows  = array();
		$count = 0;
		while ( ( $data = fgetcsv( $handle ) ) !== false ) {
			if ( ++$count > 5000 ) {
				break; // Safety cap.
			}
			if ( count( array_filter( $data, static fn( $v ) => '' !== trim( (string) $v ) ) ) === 0 ) {
				continue; // Skip blank lines.
			}
			$row = array();
			foreach ( $headers as $i => $key ) {
				$row[ $key ] = isset( $data[ $i ] ) ? trim( (string) $data[ $i ] ) : '';
			}
			$rows[] = $row;
		}

		fclose( $handle );

		return $rows;
	}

	/**
	 * Import flats.
	 *
	 * @param array<int, array<string, string>> $rows Parsed rows.
	 * @return array{created:int, updated:int, skipped:int}
	 */
	private function import_flats( array $rows ): array {
		$created = 0;
		$updated = 0;
		$skipped = 0;

		foreach ( $rows as $row ) {
			$flat_number = sanitize_text_field( $row['flat_number'] ?? '' );
			if ( '' === $flat_number ) {
				++$skipped;
				continue;
			}

			$building_id = $this->resolve_building_id( $row['building'] ?? '' );
			$existing    = $this->find_flat_by_number( $flat_number, $building_id );

			if ( $existing ) {
				$flat_id = $existing;
				++$updated;
			} else {
				$flat_id = wp_insert_post(
					array(
						'post_type'   => 'bc_flat',
						'post_status' => 'publish',
						'post_title'  => $flat_number,
					),
					true
				);
				if ( is_wp_error( $flat_id ) ) {
					++$skipped;
					continue;
				}
				$flat_id = (int) $flat_id;
				++$created;
			}

			if ( $building_id ) {
				update_post_meta( $flat_id, 'bc_building_id', $building_id );
			}
			update_post_meta( $flat_id, 'bc_flat_number', $flat_number );

			if ( isset( $row['floor_number'] ) && '' !== $row['floor_number'] ) {
				update_post_meta( $flat_id, 'bc_floor_number', absint( $row['floor_number'] ) );
			}
			if ( isset( $row['flat_size'] ) && '' !== $row['flat_size'] ) {
				update_post_meta( $flat_id, 'bc_flat_size', (float) $row['flat_size'] );
			}
			if ( isset( $row['monthly_service_charge'] ) && '' !== $row['monthly_service_charge'] ) {
				update_post_meta( $flat_id, 'bc_monthly_service_charge', round( (float) $row['monthly_service_charge'], 2 ) );
			}

			$occupancy = strtolower( sanitize_text_field( $row['occupancy_status'] ?? '' ) );
			$occupancy = 'occupied' === $occupancy ? 'occupied' : 'vacant';
			update_post_meta( $flat_id, 'bc_occupancy_status', $occupancy );
		}

		return compact( 'created', 'updated', 'skipped' );
	}

	/**
	 * Import residents.
	 *
	 * @param array<int, array<string, string>> $rows Parsed rows.
	 * @return array{created:int, updated:int, skipped:int}
	 */
	private function import_residents( array $rows ): array {
		$created = 0;
		$updated = 0;
		$skipped = 0;

		foreach ( $rows as $row ) {
			$name = sanitize_text_field( $row['name'] ?? '' );
			if ( '' === $name ) {
				++$skipped;
				continue;
			}

			$email    = sanitize_email( $row['email'] ?? '' );
			$existing = ( $email && is_email( $email ) ) ? $this->find_resident_by_email( $email ) : 0;

			if ( $existing ) {
				$resident_id = $existing;
				wp_update_post(
					array(
						'ID'         => $resident_id,
						'post_title' => $name,
					)
				);
				++$updated;
			} else {
				$resident_id = wp_insert_post(
					array(
						'post_type'   => 'bc_resident',
						'post_status' => 'publish',
						'post_title'  => $name,
					),
					true
				);
				if ( is_wp_error( $resident_id ) ) {
					++$skipped;
					continue;
				}
				$resident_id = (int) $resident_id;
				++$created;
			}

			if ( isset( $row['mobile'] ) && '' !== $row['mobile'] ) {
				update_post_meta( $resident_id, 'bc_mobile', sanitize_text_field( $row['mobile'] ) );
			}
			if ( $email && is_email( $email ) ) {
				update_post_meta( $resident_id, 'bc_email', $email );
			}
			if ( isset( $row['move_in_date'] ) && '' !== $row['move_in_date'] ) {
				update_post_meta( $resident_id, 'bc_move_in_date', sanitize_text_field( $row['move_in_date'] ) );
			}

			$flat_number = sanitize_text_field( $row['flat_number'] ?? '' );
			if ( '' !== $flat_number ) {
				$flat_id = $this->find_flat_by_number( $flat_number, 0 );
				if ( $flat_id ) {
					update_post_meta( $resident_id, 'bc_assigned_flat_id', $flat_id );
					update_post_meta( $flat_id, 'bc_occupancy_status', 'occupied' );
				}
			}

			// Provision the tenant portal account (meta is now in place).
			if ( $email && is_email( $email ) && class_exists( __NAMESPACE__ . '\\Tenant_Accounts' ) ) {
				( new Tenant_Accounts() )->provision_resident( $resident_id );
			}
		}

		return compact( 'created', 'updated', 'skipped' );
	}

	/**
	 * Resolve a building reference (numeric ID or title) to a building post ID.
	 */
	private function resolve_building_id( string $reference ): int {
		$reference = trim( $reference );
		if ( '' === $reference ) {
			return 0;
		}

		if ( ctype_digit( $reference ) ) {
			$id = (int) $reference;
			return ( 'bc_building' === get_post_type( $id ) ) ? $id : 0;
		}

		$map = $this->building_title_map();
		return $map[ strtolower( $reference ) ] ?? 0;
	}

	/**
	 * Lazily-built lowercase building-title => ID map.
	 *
	 * @return array<string, int>
	 */
	private function building_title_map(): array {
		static $map = null;
		if ( null !== $map ) {
			return $map;
		}

		$map       = array();
		$buildings = get_posts(
			array(
				'post_type'      => 'bc_building',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);
		foreach ( $buildings as $building ) {
			$map[ strtolower( $building->post_title ) ] = (int) $building->ID;
		}

		return $map;
	}

	/**
	 * Find a flat by its flat number (optionally within a building).
	 */
	private function find_flat_by_number( string $flat_number, int $building_id ): int {
		$meta_query = array(
			array(
				'key'   => 'bc_flat_number',
				'value' => $flat_number,
			),
		);

		if ( $building_id > 0 ) {
			$meta_query[] = array(
				'key'   => 'bc_building_id',
				'value' => $building_id,
			);
		}

		$found = get_posts(
			array(
				'post_type'      => 'bc_flat',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => $meta_query,
			)
		);

		return ! empty( $found ) ? (int) $found[0] : 0;
	}

	/**
	 * Find a resident by email.
	 */
	private function find_resident_by_email( string $email ): int {
		$found = get_posts(
			array(
				'post_type'      => 'bc_resident',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => 'bc_email',
						'value' => $email,
					),
				),
			)
		);

		return ! empty( $found ) ? (int) $found[0] : 0;
	}

	/**
	 * Redirect back to the import tab with an error message.
	 */
	private function redirect_error( string $message ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'bcl-dashboard',
					'tab'       => 'import',
					'imp_error' => rawurlencode( $message ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
