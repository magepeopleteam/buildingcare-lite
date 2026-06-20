<?php
/**
 * WordPress REST API endpoints.
 *
 * @package BuildingCareLite
 */

declare(strict_types=1);

namespace BuildingCareLite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers REST API routes for mobile/future integrations.
 */
class Rest_Api {

	public const NAMESPACE = 'buildingcare-lite/v1';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/dashboard',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_dashboard' ),
				'permission_callback' => array( $this, 'can_view_reports' ),
				'args'                => array(
					'month' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bills',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_bills' ),
				'permission_callback' => array( $this, 'can_manage_payments' ),
				'args'                => $this->list_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bills/(?P<id>\d+)/payment',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'record_payment' ),
				'permission_callback' => array( $this, 'can_manage_payments' ),
				'args'                => array(
					'id'              => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'amount'          => array(
						'type'              => 'number',
						'default'           => 0,
						'sanitize_callback' => 'floatval',
					),
					'payment_method'  => array(
						'type'              => 'string',
						'default'           => 'cash',
						'sanitize_callback' => 'sanitize_key',
					),
					'mark_full'       => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/flats',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_flats' ),
				'permission_callback' => array( $this, 'can_view_reports' ),
				'args'                => $this->list_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/residents',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_residents' ),
				'permission_callback' => array( $this, 'can_view_reports' ),
				'args'                => $this->list_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/expenses',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_expenses' ),
				'permission_callback' => array( $this, 'can_manage_expenses' ),
				'args'                => $this->list_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/reports/(?P<type>[a-z_]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_report' ),
				'permission_callback' => array( $this, 'can_view_reports' ),
				'args'                => array(
					'type'        => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'date_filter' => array(
						'type'              => 'string',
						'default'           => 'current_month',
						'sanitize_callback' => 'sanitize_key',
					),
					'start_date'  => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'end_date'    => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/audit-log',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_audit_log' ),
				'permission_callback' => array( $this, 'can_manage_settings' ),
			)
		);
	}

	/**
	 * Common list endpoint args.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function list_args(): array {
		return array(
			'page'     => array(
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'type'              => 'integer',
				'default'           => 20,
				'sanitize_callback' => 'absint',
			),
			'search'   => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'status'   => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
			),
			'month'    => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Dashboard endpoint.
	 */
	public function get_dashboard( \WP_REST_Request $request ): \WP_REST_Response {
		$month   = $request->get_param( 'month' ) ?: bcl_current_billing_month();
		$reports = new Reports();
		$stats   = $reports->get_dashboard_stats( $month );

		return new \WP_REST_Response( $stats, 200 );
	}

	/**
	 * Bills list endpoint.
	 */
	public function get_bills( \WP_REST_Request $request ): \WP_REST_Response {
		$query = $this->build_query( 'bc_bill', $request );

		if ( $request->get_param( 'status' ) ) {
			$query['meta_query'][] = array(
				'key'   => 'bc_payment_status',
				'value' => $request->get_param( 'status' ),
			);
		}

		if ( $request->get_param( 'month' ) ) {
			$query['meta_query'][] = array(
				'key'   => 'bc_billing_month',
				'value' => $request->get_param( 'month' ),
			);
		}

		return new \WP_REST_Response( $this->format_posts( get_posts( $query ) ), 200 );
	}

	/**
	 * Flats list endpoint.
	 */
	public function get_flats( \WP_REST_Request $request ): \WP_REST_Response {
		$query = $this->build_query( 'bc_flat', $request );
		return new \WP_REST_Response( $this->format_posts( get_posts( $query ) ), 200 );
	}

	/**
	 * Residents list endpoint.
	 */
	public function get_residents( \WP_REST_Request $request ): \WP_REST_Response {
		$query = $this->build_query( 'bc_resident', $request );
		return new \WP_REST_Response( $this->format_posts( get_posts( $query ) ), 200 );
	}

	/**
	 * Expenses list endpoint.
	 */
	public function get_expenses( \WP_REST_Request $request ): \WP_REST_Response {
		$query = $this->build_query( 'bc_expense', $request );
		return new \WP_REST_Response( $this->format_posts( get_posts( $query ) ), 200 );
	}

	/**
	 * Record payment via REST.
	 */
	public function record_payment( \WP_REST_Request $request ): \WP_REST_Response {
		$billing = new Billing();
		$result  = $billing->record_payment(
			(int) $request->get_param( 'id' ),
			(float) $request->get_param( 'amount' ),
			(string) $request->get_param( 'payment_method' ),
			(bool) $request->get_param( 'mark_full' )
		);

		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response(
				array( 'message' => $result->get_error_message() ),
				(int) ( $result->get_error_data()['status'] ?? 400 )
			);
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Report endpoint.
	 */
	public function get_report( \WP_REST_Request $request ): \WP_REST_Response {
		$reports = new Reports();
		$range   = $reports->resolve_date_range(
			(string) $request->get_param( 'date_filter' ),
			(string) $request->get_param( 'start_date' ),
			(string) $request->get_param( 'end_date' )
		);

		return new \WP_REST_Response(
			array(
				'rows'  => $reports->generate_report( (string) $request->get_param( 'type' ), $range['start'], $range['end'] ),
				'range' => $range,
			),
			200
		);
	}

	/**
	 * Audit log endpoint.
	 */
	public function get_audit_log(): \WP_REST_Response {
		$logs = get_option( 'bcl_audit_log', array() );
		$logs = is_array( $logs ) ? array_reverse( $logs ) : array();

		return new \WP_REST_Response( array_slice( $logs, 0, 100 ), 200 );
	}

	/**
	 * Build WP_Query-style get_posts args.
	 *
	 * @return array<string, mixed>
	 */
	private function build_query( string $post_type, \WP_REST_Request $request ): array {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(),
		);

		$search = (string) $request->get_param( 'search' );
		if ( $search ) {
			$args['s'] = $search;
		}

		return $args;
	}

	/**
	 * Format posts for API response.
	 *
	 * @param array<int, \WP_Post> $posts Posts.
	 * @return array<int, array<string, mixed>>
	 */
	private function format_posts( array $posts ): array {
		$data = array();
		foreach ( $posts as $post ) {
			$meta = get_post_meta( $post->ID );
			$flat = array();
			foreach ( $meta as $key => $values ) {
				if ( str_starts_with( $key, 'bc_' ) ) {
					$flat[ $key ] = maybe_unserialize( $values[0] ?? '' );
				}
			}

			$data[] = array(
				'id'         => $post->ID,
				'title'      => $post->post_title,
				'type'       => $post->post_type,
				'date'       => $post->post_date,
				'meta'       => $flat,
			);
		}

		return $data;
	}

	/**
	 * Permission: view reports.
	 */
	public function can_view_reports(): bool {
		return bcl_current_user_can( 'bc_view_reports' );
	}

	/**
	 * Permission: manage payments.
	 */
	public function can_manage_payments(): bool {
		return bcl_current_user_can( 'bc_manage_payments' );
	}

	/**
	 * Permission: manage expenses.
	 */
	public function can_manage_expenses(): bool {
		return bcl_current_user_can( 'bc_manage_expenses' );
	}

	/**
	 * Permission: manage settings.
	 */
	public function can_manage_settings(): bool {
		return bcl_current_user_can( 'bc_manage_settings' );
	}
}
