<?php
/**
 * Custom post types and taxonomies.
 *
 * @package BuildingCareLite
 */

declare(strict_types=1);

namespace BuildingCareLite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers BuildingCare post types and taxonomies.
 */
class Post_Types {

	/**
	 * Post types that render Add New inside the list search toolbar.
	 *
	 * @var string[]
	 */
	private const LIST_TOOLBAR_POST_TYPES = array(
		'bc_building',
		'bc_flat',
		'bc_resident',
		'bc_expense',
		'bc_recurring_expense',
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_post_types' ) );
		add_action( 'init', array( $this, 'register_taxonomies' ) );
		add_action( 'init', array( $this, 'seed_expense_categories' ) );
		add_filter( 'post_row_actions', array( $this, 'flat_row_actions' ), 10, 2 );
		add_action( 'admin_action_bcl_duplicate_flat', array( $this, 'duplicate_flat' ) );
		add_filter( 'manage_bc_building_posts_columns', array( $this, 'building_columns' ) );
		add_action( 'manage_bc_building_posts_custom_column', array( $this, 'building_column_content' ), 10, 2 );
		add_filter( 'manage_bc_flat_posts_columns', array( $this, 'flat_columns' ) );
		add_action( 'manage_bc_flat_posts_custom_column', array( $this, 'flat_column_content' ), 10, 2 );
		add_filter( 'manage_bc_resident_posts_columns', array( $this, 'resident_columns' ) );
		add_action( 'manage_bc_resident_posts_custom_column', array( $this, 'resident_column_content' ), 10, 2 );
		add_filter( 'manage_bc_bill_posts_columns', array( $this, 'bill_columns' ) );
		add_action( 'manage_bc_bill_posts_custom_column', array( $this, 'bill_column_content' ), 10, 2 );
		add_filter( 'manage_bc_expense_posts_columns', array( $this, 'expense_columns' ) );
		add_action( 'manage_bc_expense_posts_custom_column', array( $this, 'expense_column_content' ), 10, 2 );
		add_filter( 'manage_bc_recurring_expense_posts_columns', array( $this, 'recurring_columns' ) );
		add_action( 'manage_bc_recurring_expense_posts_custom_column', array( $this, 'recurring_column_content' ), 10, 2 );
		add_action( 'load-post-new.php', array( $this, 'block_manual_bill_creation' ) );
		add_filter( 'the_title', array( $this, 'filter_bill_list_title' ), 10, 2 );
		add_filter( 'wp_list_table_class_name', array( $this, 'filter_posts_list_table_class' ), 10, 2 );

		// Invalidate select caches when buildings/flats/residents change.
		add_action( 'save_post', array( $this, 'maybe_invalidate_caches_on_save' ), 20, 2 );
		add_action( 'delete_post', array( $this, 'maybe_invalidate_caches_on_delete' ), 20 );
	}

	/**
	 * Register all custom post types.
	 */
	public function register_post_types(): void {
		$this->register_building();
		$this->register_flat();
		$this->register_resident();
		$this->register_bill();
		$this->register_expense();
		$this->register_recurring_expense();
	}

	/**
	 * Block manual bill creation — bills are generated automatically.
	 */
	public function block_manual_bill_creation(): void {
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'post';

		if ( 'bc_bill' !== $post_type ) {
			return;
		}

		wp_safe_redirect( admin_url( 'edit.php?post_type=bc_bill' ) );
		exit;
	}

	/**
	 * Show flat number as the bill title on the All Bills list.
	 *
	 * @param string $title   Post title.
	 * @param int    $post_id Post ID.
	 */
	public function filter_bill_list_title( string $title, int $post_id = 0 ): string {
		if ( ! is_admin() || $post_id <= 0 ) {
			return $title;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-bc_bill' !== $screen->id ) {
			return $title;
		}

		$post = get_post( $post_id );
		if ( ! $post || 'bc_bill' !== $post->post_type ) {
			return $title;
		}

		return bcl_get_bill_display_title( $post_id ) ?: $title;
	}

	/**
	 * Use custom list table so Add New renders before search (no JS move).
	 *
	 * @param string               $class List table class name.
	 * @param array<string, mixed> $args  List table args.
	 */
	public function filter_posts_list_table_class( string $class, array $args ): string {
		if ( 'WP_Posts_List_Table' !== $class ) {
			return $class;
		}

		$screen = $args['screen'] ?? null;
		if ( ! $screen || empty( $screen->post_type ) ) {
			return $class;
		}

		if ( ! in_array( $screen->post_type, self::LIST_TOOLBAR_POST_TYPES, true ) ) {
			return $class;
		}

		self::load_posts_list_table_class();

		return BCL_Posts_List_Table::class;
	}

	/**
	 * Load WordPress list-table deps and the custom list table class.
	 */
	private static function load_posts_list_table_class(): void {
		if ( class_exists( BCL_Posts_List_Table::class, false ) ) {
			return;
		}

		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		if ( ! class_exists( 'WP_Posts_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-posts-list-table.php';
		}

		require_once BCL_PLUGIN_DIR . 'includes/class-posts-list-table.php';
	}

	/**
	 * Shared capability mapping for internal CPTs.
	 *
	 * @return array<string, string>
	 */
	private function internal_caps( string $singular, string $plural ): array {
		return array(
			'edit_post'              => "bc_edit_{$singular}",
			'read_post'              => "bc_read_{$singular}",
			'delete_post'            => "bc_delete_{$singular}",
			'edit_posts'             => "bc_edit_{$plural}",
			'edit_others_posts'      => "bc_edit_others_{$plural}",
			'publish_posts'          => "bc_publish_{$plural}",
			'read_private_posts'     => "bc_read_private_{$plural}",
			'create_posts'           => "bc_create_{$plural}",
			'delete_posts'           => "bc_delete_{$plural}",
			'delete_others_posts'    => "bc_delete_others_{$plural}",
			'delete_private_posts'   => "bc_delete_private_{$plural}",
			'delete_published_posts' => "bc_delete_published_{$plural}",
		);
	}

	/**
	 * Add Duplicate row action for flats.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param \WP_Post              $post    Post object.
	 * @return array<string, string>
	 */
	public function flat_row_actions( array $actions, \WP_Post $post ): array {
		if ( 'bc_flat' !== $post->post_type ) {
			return $actions;
		}

		$edit_link = get_edit_post_link( $post->ID, 'raw' );
		if ( $edit_link ) {
			$actions['edit'] = sprintf(
				'<a href="%s" aria-label="%s">%s</a>',
				esc_url( $edit_link ),
				esc_attr(
					sprintf(
						/* translators: %s: flat title */
						__( 'Edit &#8220;%s&#8221;', 'buildingcare-lite' ),
						$post->post_title
					)
				),
				esc_html__( 'Edit', 'buildingcare-lite' )
			);
		}

		if ( $this->user_can_duplicate_flat( $post->ID ) ) {
			$duplicate_url = wp_nonce_url(
				admin_url( 'admin.php?action=bcl_duplicate_flat&post=' . $post->ID ),
				'bcl_duplicate_flat_' . $post->ID
			);

			$actions['bcl_duplicate'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $duplicate_url ),
				esc_html__( 'Duplicate', 'buildingcare-lite' )
			);
		}

		return $actions;
	}

	/**
	 * Duplicate a flat with all meta fields.
	 */
	public function duplicate_flat(): void {
		if ( ! isset( $_GET['post'] ) ) {
			wp_die( esc_html__( 'No flat specified.', 'buildingcare-lite' ) );
		}

		$post_id = absint( $_GET['post'] );
		check_admin_referer( 'bcl_duplicate_flat_' . $post_id );

		if ( ! $this->user_can_duplicate_flat( $post_id ) ) {
			wp_die( esc_html__( 'Permission denied.', 'buildingcare-lite' ) );
		}

		$original = get_post( $post_id );
		if ( ! $original || 'bc_flat' !== $original->post_type ) {
			wp_die( esc_html__( 'Invalid flat.', 'buildingcare-lite' ) );
		}

		$new_id = wp_insert_post(
			array(
				'post_type'    => 'bc_flat',
				'post_status'  => 'publish',
				'post_title'   => $original->post_title . ' ' . __( '(Copy)', 'buildingcare-lite' ),
				'post_content' => $original->post_content,
				'post_excerpt' => $original->post_excerpt,
			),
			true
		);

		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html( $new_id->get_error_message() ) );
		}

		$new_id = (int) $new_id;
		$meta   = get_post_meta( $post_id );

		foreach ( $meta as $key => $values ) {
			if ( ! str_starts_with( $key, 'bc_' ) ) {
				continue;
			}

			foreach ( $values as $value ) {
				add_post_meta( $new_id, $key, maybe_unserialize( $value ) );
			}
		}

		$flat_number = bcl_get_meta_string( $new_id, 'bc_flat_number' );
		if ( $flat_number ) {
			update_post_meta( $new_id, 'bc_flat_number', $flat_number . '-copy' );
		}

		update_post_meta( $new_id, 'bc_occupancy_status', 'vacant' );

		bcl_audit_log(
			'flat_duplicated',
			sprintf(
				/* translators: 1: original id, 2: new id */
				__( 'Flat #%1$d duplicated as #%2$d', 'buildingcare-lite' ),
				$post_id,
				$new_id
			)
		);

		wp_safe_redirect( get_edit_post_link( $new_id, 'raw' ) );
		exit;
	}

	/**
	 * Whether the current user may duplicate a flat.
	 */
	private function user_can_duplicate_flat( int $post_id ): bool {
		return bcl_current_user_can( 'bc_manage_flats' )
			|| bcl_current_user_can( 'bc_create_flats' )
			|| current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Register bc_building.
	 */
	private function register_building(): void {
		register_post_type(
			'bc_building',
			array(
				'labels'              => array(
					'name'          => __( 'Buildings', 'buildingcare-lite' ),
					'singular_name' => __( 'Building', 'buildingcare-lite' ),
					'add_new_item'  => __( 'Add New Building', 'buildingcare-lite' ),
					'edit_item'     => __( 'Edit Building', 'buildingcare-lite' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_rest'        => true,
				'capability_type'     => array( 'bc_building', 'bc_buildings' ),
				'map_meta_cap'        => true,
				'capabilities'        => $this->internal_caps( 'building', 'buildings' ),
				'supports'            => array( 'title' ),
				'has_archive'         => false,
				'rewrite'             => false,
			)
		);
	}

	/**
	 * Register bc_flat.
	 */
	private function register_flat(): void {
		register_post_type(
			'bc_flat',
			array(
				'labels'              => array(
					'name'          => __( 'Flats', 'buildingcare-lite' ),
					'singular_name' => __( 'Flat', 'buildingcare-lite' ),
					'add_new_item'  => __( 'Add New Flat', 'buildingcare-lite' ),
					'edit_item'     => __( 'Edit Flat', 'buildingcare-lite' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_rest'        => true,
				'capability_type'     => array( 'bc_flat', 'bc_flats' ),
				'map_meta_cap'        => true,
				'capabilities'        => $this->internal_caps( 'flat', 'flats' ),
				'supports'            => array( 'title' ),
				'has_archive'         => false,
				'rewrite'             => false,
			)
		);
	}

	/**
	 * Register bc_resident.
	 */
	private function register_resident(): void {
		register_post_type(
			'bc_resident',
			array(
				'labels'              => array(
					'name'          => __( 'Residents', 'buildingcare-lite' ),
					'singular_name' => __( 'Resident', 'buildingcare-lite' ),
					'add_new_item'  => __( 'Add New Resident', 'buildingcare-lite' ),
					'edit_item'     => __( 'Edit Resident', 'buildingcare-lite' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_rest'        => true,
				'capability_type'     => array( 'bc_resident', 'bc_residents' ),
				'map_meta_cap'        => true,
				'capabilities'        => $this->internal_caps( 'resident', 'residents' ),
				'supports'            => array( 'title' ),
				'has_archive'         => false,
				'rewrite'             => false,
			)
		);
	}

	/**
	 * Register bc_bill.
	 */
	private function register_bill(): void {
		register_post_type(
			'bc_bill',
			array(
				'labels'              => array(
					'name'               => __( 'Bills', 'buildingcare-lite' ),
					'singular_name'      => __( 'Bill', 'buildingcare-lite' ),
					'add_new'            => '',
					'add_new_item'       => __( 'Add New Bill', 'buildingcare-lite' ),
					'edit_item'          => __( 'Edit Bill', 'buildingcare-lite' ),
					'not_found'          => __( 'No bills found.', 'buildingcare-lite' ),
					'not_found_in_trash' => __( 'No bills found in Trash.', 'buildingcare-lite' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_rest'        => true,
				'capability_type'     => array( 'bc_bill', 'bc_bills' ),
				'map_meta_cap'        => true,
				'capabilities'        => $this->internal_caps( 'bill', 'bills' ),
				'supports'            => array( 'title' ),
				'has_archive'         => false,
				'rewrite'             => false,
			)
		);
	}

	/**
	 * Register bc_expense.
	 */
	private function register_expense(): void {
		register_post_type(
			'bc_expense',
			array(
				'labels'              => array(
					'name'          => __( 'Expenses', 'buildingcare-lite' ),
					'singular_name' => __( 'Expense', 'buildingcare-lite' ),
					'add_new_item'  => __( 'Add New Expense', 'buildingcare-lite' ),
					'edit_item'     => __( 'Edit Expense', 'buildingcare-lite' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_rest'        => true,
				'capability_type'     => array( 'bc_expense', 'bc_expenses' ),
				'map_meta_cap'        => true,
				'capabilities'        => $this->internal_caps( 'expense', 'expenses' ),
				'supports'            => array( 'title' ),
				'has_archive'         => false,
				'rewrite'             => false,
			)
		);
	}

	/**
	 * Register bc_recurring_expense.
	 */
	private function register_recurring_expense(): void {
		register_post_type(
			'bc_recurring_expense',
			array(
				'labels'              => array(
					'name'          => __( 'Recurring Expenses', 'buildingcare-lite' ),
					'singular_name' => __( 'Recurring Expense', 'buildingcare-lite' ),
					'add_new_item'  => __( 'Add Recurring Expense', 'buildingcare-lite' ),
					'edit_item'     => __( 'Edit Recurring Expense', 'buildingcare-lite' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_rest'        => true,
				'capability_type'     => array( 'bc_recurring_expense', 'bc_recurring_expenses' ),
				'map_meta_cap'        => true,
				'capabilities'        => $this->internal_caps( 'recurring_expense', 'recurring_expenses' ),
				'supports'            => array( 'title' ),
				'has_archive'         => false,
				'rewrite'             => false,
			)
		);
	}

	/**
	 * Register expense category taxonomy.
	 */
	public function register_taxonomies(): void {
		register_taxonomy(
			'bc_expense_category',
			array( 'bc_expense', 'bc_recurring_expense' ),
			array(
				'labels'            => array(
					'name'          => __( 'Expense Categories', 'buildingcare-lite' ),
					'singular_name' => __( 'Expense Category', 'buildingcare-lite' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'hierarchical'      => true,
				'rewrite'           => false,
			)
		);
	}

	/**
	 * Seed default expense categories once.
	 */
	public function seed_expense_categories(): void {
		if ( get_option( 'bcl_expense_categories_seeded' ) ) {
			return;
		}

		$categories = array(
			'Lift Maintenance',
			'Staff Salary',
			'Cleaner Salary',
			'Generator Expense',
			'Electricity',
			'Water',
			'Internet',
			'Repairs',
			'Miscellaneous',
		);

		foreach ( $categories as $category ) {
			if ( ! term_exists( $category, 'bc_expense_category' ) ) {
				wp_insert_term( $category, 'bc_expense_category' );
			}
		}

		update_option( 'bcl_expense_categories_seeded', 1, false );
	}

	/**
	 * Building list columns.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function building_columns( array $columns ): array {
		return array(
			'cb'            => $columns['cb'] ?? '',
			'title'         => __( 'Building Name', 'buildingcare-lite' ),
			'bc_address'    => __( 'Address', 'buildingcare-lite' ),
			'bc_floors'     => __( 'Floors', 'buildingcare-lite' ),
			'bc_status'     => __( 'Status', 'buildingcare-lite' ),
			'date'          => __( 'Date', 'buildingcare-lite' ),
		);
	}

	/**
	 * Building column content.
	 *
	 * @param string $column Column key.
	 * @param int    $post_id Post ID.
	 */
	public function building_column_content( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'bc_address':
				echo esc_html( bcl_get_meta_string( $post_id, 'bc_address' ) );
				break;
			case 'bc_floors':
				echo esc_html( (string) bcl_get_meta_float( $post_id, 'bc_total_floors' ) );
				break;
			case 'bc_status':
				$status = bcl_get_meta_string( $post_id, 'bc_status' );
				echo esc_html( bcl_building_statuses()[ $status ] ?? $status );
				break;
		}
	}

	/**
	 * Flat list columns.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function flat_columns( array $columns ): array {
		return array(
			'cb'              => $columns['cb'] ?? '',
			'title'           => __( 'Flat', 'buildingcare-lite' ),
			'bc_building'     => __( 'Building', 'buildingcare-lite' ),
			'bc_flat_number'  => __( 'Flat No.', 'buildingcare-lite' ),
			'bc_floor'        => __( 'Floor', 'buildingcare-lite' ),
			'bc_charge'       => __( 'Service Charge', 'buildingcare-lite' ),
			'bc_occupancy'    => __( 'Occupancy', 'buildingcare-lite' ),
			'date'            => __( 'Date', 'buildingcare-lite' ),
		);
	}

	/**
	 * Flat column content.
	 */
	public function flat_column_content( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'bc_building':
				$building_id = (int) bcl_get_meta_float( $post_id, 'bc_building_id' );
				echo $building_id ? esc_html( get_the_title( $building_id ) ) : '—';
				break;
			case 'bc_flat_number':
				echo esc_html( bcl_get_meta_string( $post_id, 'bc_flat_number' ) );
				break;
			case 'bc_floor':
				echo esc_html( (string) bcl_get_meta_float( $post_id, 'bc_floor_number' ) );
				break;
			case 'bc_charge':
				echo esc_html( bcl_format_amount( bcl_get_meta_float( $post_id, 'bc_monthly_service_charge' ) ) );
				break;
			case 'bc_occupancy':
				$status = bcl_get_meta_string( $post_id, 'bc_occupancy_status' );
				echo esc_html( bcl_occupancy_statuses()[ $status ] ?? $status );
				break;
		}
	}

	/**
	 * Resident list columns.
	 */
	public function resident_columns( array $columns ): array {
		return array(
			'cb'           => $columns['cb'] ?? '',
			'title'        => __( 'Resident', 'buildingcare-lite' ),
			'bc_mobile'    => __( 'Mobile', 'buildingcare-lite' ),
			'bc_email'     => __( 'Email', 'buildingcare-lite' ),
			'bc_flat'      => __( 'Flat', 'buildingcare-lite' ),
			'bc_move_in'   => __( 'Move-in', 'buildingcare-lite' ),
			'date'         => __( 'Date', 'buildingcare-lite' ),
		);
	}

	/**
	 * Resident column content.
	 */
	public function resident_column_content( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'bc_mobile':
				echo esc_html( bcl_get_meta_string( $post_id, 'bc_mobile' ) );
				break;
			case 'bc_email':
				echo esc_html( bcl_get_meta_string( $post_id, 'bc_email' ) );
				break;
			case 'bc_flat':
				$flat_id = (int) bcl_get_meta_float( $post_id, 'bc_assigned_flat_id' );
				echo $flat_id ? esc_html( get_the_title( $flat_id ) ) : '—';
				break;
			case 'bc_move_in':
				echo esc_html( bcl_get_meta_string( $post_id, 'bc_move_in_date' ) );
				break;
		}
	}

	/**
	 * Bill list columns.
	 */
	public function bill_columns( array $columns ): array {
		return array(
			'cb'           => $columns['cb'] ?? '',
			'title'        => __( 'Flat', 'buildingcare-lite' ),
			'bc_month'     => __( 'Month', 'buildingcare-lite' ),
			'bc_occupancy' => __( 'Occupancy', 'buildingcare-lite' ),
			'bc_payable'   => __( 'Payable', 'buildingcare-lite' ),
			'bc_paid'      => __( 'Paid', 'buildingcare-lite' ),
			'bc_due'       => __( 'Due', 'buildingcare-lite' ),
			'bc_status'    => __( 'Status', 'buildingcare-lite' ),
			'date'         => __( 'Date', 'buildingcare-lite' ),
		);
	}

	/**
	 * Bill column content.
	 */
	public function bill_column_content( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'bc_month':
				echo esc_html( bcl_format_billing_month( bcl_get_meta_string( $post_id, 'bc_billing_month' ) ) );
				break;
			case 'bc_occupancy':
				$occupancy = bcl_get_meta_string( $post_id, 'bc_occupancy_status' );
				echo esc_html( bcl_occupancy_statuses()[ $occupancy ] ?? ( $occupancy ?: '—' ) );
				break;
			case 'bc_payable':
				echo esc_html( bcl_format_amount( bcl_get_meta_float( $post_id, 'bc_total_payable_amount' ) ) );
				break;
			case 'bc_paid':
				echo esc_html( bcl_format_amount( bcl_get_meta_float( $post_id, 'bc_amount_paid' ) ) );
				break;
			case 'bc_due':
				echo esc_html( bcl_format_amount( bcl_get_meta_float( $post_id, 'bc_remaining_due' ) ) );
				break;
			case 'bc_status':
				$status = bcl_get_meta_string( $post_id, 'bc_payment_status' );
				echo esc_html( bcl_payment_statuses()[ $status ] ?? $status );
				break;
		}
	}

	/**
	 * Expense list columns.
	 */
	public function expense_columns( array $columns ): array {
		return array(
			'cb'           => $columns['cb'] ?? '',
			'title'        => __( 'Expense', 'buildingcare-lite' ),
			'bc_date'      => __( 'Date', 'buildingcare-lite' ),
			'taxonomy-bc_expense_category' => __( 'Category', 'buildingcare-lite' ),
			'bc_amount'    => __( 'Amount', 'buildingcare-lite' ),
			'bc_paid'      => __( 'Paid', 'buildingcare-lite' ),
			'date'         => __( 'Created', 'buildingcare-lite' ),
		);
	}

	/**
	 * Expense column content.
	 */
	public function expense_column_content( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'bc_date':
				echo esc_html( bcl_get_meta_string( $post_id, 'bc_expense_date' ) );
				break;
			case 'bc_amount':
				echo esc_html( bcl_format_amount( bcl_get_meta_float( $post_id, 'bc_amount' ) ) );
				break;
			case 'bc_paid':
				$paid = bcl_get_meta_string( $post_id, 'bc_is_paid' ) === 'yes';
				echo esc_html( $paid ? __( 'Yes', 'buildingcare-lite' ) : __( 'No', 'buildingcare-lite' ) );
				break;
		}
	}

	/**
	 * Recurring expense list columns.
	 */
	public function recurring_columns( array $columns ): array {
		return array(
			'cb'           => $columns['cb'] ?? '',
			'title'        => __( 'Title', 'buildingcare-lite' ),
			'bc_amount'    => __( 'Monthly Amount', 'buildingcare-lite' ),
			'taxonomy-bc_expense_category' => __( 'Category', 'buildingcare-lite' ),
			'bc_active'    => __( 'Active', 'buildingcare-lite' ),
			'date'         => __( 'Date', 'buildingcare-lite' ),
		);
	}

	/**
	 * Recurring expense column content.
	 */
	public function recurring_column_content( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'bc_amount':
				echo esc_html( bcl_format_amount( bcl_get_meta_float( $post_id, 'bc_monthly_amount' ) ) );
				break;
			case 'bc_active':
				$active = bcl_get_meta_string( $post_id, 'bc_active_status' ) === 'yes';
				echo esc_html( $active ? __( 'Yes', 'buildingcare-lite' ) : __( 'No', 'buildingcare-lite' ) );
				break;
		}
	}

	/**
	 * Invalidate option caches for relevant CPT saves.
	 */
	public function maybe_invalidate_caches_on_save( int $post_id, \WP_Post $post ): void {
		if ( in_array( $post->post_type, array( 'bc_building', 'bc_flat', 'bc_resident' ), true ) ) {
			if ( function_exists( 'bcl_invalidate_options_caches' ) ) {
				bcl_invalidate_options_caches();
			}
			bcl_clear_dashboard_cache();
		}
	}

	public function maybe_invalidate_caches_on_delete( int $post_id ): void {
		$post = get_post( $post_id );
		if ( $post && in_array( $post->post_type, array( 'bc_building', 'bc_flat', 'bc_resident' ), true ) ) {
			if ( function_exists( 'bcl_invalidate_options_caches' ) ) {
				bcl_invalidate_options_caches();
			}
			bcl_clear_dashboard_cache();
		}
	}
}
