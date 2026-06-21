<?php
/**
 * Admin pages, menus, and list tables.
 *
 * @package BuildingCareLite
 */

declare(strict_types=1);

namespace BuildingCareLite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Bills list table for payment management.
 */
class Bills_List_Table extends \WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'bill',
				'plural'   => 'bills',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Define columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'           => '<input type="checkbox" />',
			'title'        => __( 'Flat', 'buildingcare-lite' ),
			'occupancy'    => __( 'Occupancy', 'buildingcare-lite' ),
			'month'        => __( 'Month', 'buildingcare-lite' ),
			'payable'      => __( 'Payable', 'buildingcare-lite' ),
			'paid'         => __( 'Paid', 'buildingcare-lite' ),
			'due'          => __( 'Due', 'buildingcare-lite' ),
			'status'       => __( 'Status', 'buildingcare-lite' ),
			'actions'      => __( 'Quick Actions', 'buildingcare-lite' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string, array<int, bool>>
	 */
	public function get_sortable_columns(): array {
		return array(
			'title'  => array( 'title', false ),
			'month'  => array( 'bc_billing_month', false ),
			'payable' => array( 'bc_total_payable_amount', false ),
			'status' => array( 'bc_payment_status', false ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string, string>
	 */
	public function get_bulk_actions(): array {
		return array(
			'mark_paid' => __( 'Mark as Paid', 'buildingcare-lite' ),
		);
	}

	/**
	 * Checkbox column.
	 *
	 * @param \WP_Post $item Bill post.
	 */
	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="bill_ids[]" value="%d" />', (int) $item->ID );
	}

	/**
	 * Default column renderer.
	 *
	 * @param \WP_Post $item Bill post.
	 */
	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'title':
				return sprintf(
					'<strong><a href="%s">%s</a></strong>',
					esc_url( get_edit_post_link( $item->ID ) ),
					esc_html( bcl_get_bill_display_title( (int) $item->ID ) )
				);
			case 'occupancy':
				$occupancy = bcl_get_meta_string( $item->ID, 'bc_occupancy_status' );
				if ( ! $occupancy ) {
					$flat_id   = (int) bcl_get_meta_float( $item->ID, 'bc_flat_id' );
					$occupancy = $flat_id ? bcl_get_meta_string( $flat_id, 'bc_occupancy_status' ) : '';
				}
				$label = bcl_occupancy_statuses()[ $occupancy ] ?? $occupancy;
				$class = 'vacant' === $occupancy ? ' bcl-status-vacant' : '';
				return '<span class="bcl-status' . esc_attr( $class ) . '">' . esc_html( $label ?: '—' ) . '</span>';
			case 'month':
				return esc_html( bcl_format_billing_month( bcl_get_meta_string( $item->ID, 'bc_billing_month' ) ) );
			case 'payable':
				return esc_html( bcl_format_amount( bcl_get_meta_float( $item->ID, 'bc_total_payable_amount' ) ) );
			case 'paid':
				return esc_html( bcl_format_amount( bcl_get_meta_float( $item->ID, 'bc_amount_paid' ) ) );
			case 'due':
				return esc_html( bcl_format_amount( bcl_get_meta_float( $item->ID, 'bc_remaining_due' ) ) );
			case 'status':
				$status = bcl_get_meta_string( $item->ID, 'bc_payment_status' );
				return '<span class="bcl-status bcl-status-' . esc_attr( $status ) . '">' . esc_html( bcl_payment_statuses()[ $status ] ?? $status ) . '</span>';
			case 'actions':
				if ( 'paid' === bcl_get_meta_string( $item->ID, 'bc_payment_status' ) ) {
					return '<span class="bcl-paid-label">' . esc_html__( 'Collected', 'buildingcare-lite' ) . '</span>';
				}
				ob_start();
				?>
				<button
					type="button"
					class="button button-primary button-small bcl-collect-payment"
					data-bill-id="<?php echo esc_attr( (string) $item->ID ); ?>"
					data-amount="<?php echo esc_attr( (string) bcl_get_meta_float( $item->ID, 'bc_remaining_due' ) ); ?>"
				>
					<?php esc_html_e( 'Collect Payment', 'buildingcare-lite' ); ?>
				</button>
				<button type="button" class="button button-small bcl-record-payment" data-bill-id="<?php echo esc_attr( (string) $item->ID ); ?>" data-due="<?php echo esc_attr( (string) bcl_get_meta_float( $item->ID, 'bc_remaining_due' ) ); ?>">
					<?php esc_html_e( 'Partial', 'buildingcare-lite' ); ?>
				</button>
				<?php
				return (string) ob_get_clean();
			default:
				return '';
		}
	}

	/**
	 * Prepare items with filters and pagination.
	 */
	public function prepare_items(): void {
		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$status       = isset( $_REQUEST['payment_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['payment_status'] ) ) : '';
		$month        = isset( $_REQUEST['billing_month'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['billing_month'] ) ) : bcl_current_billing_month();

		$meta_query = array(
			array(
				'key'   => 'bc_billing_month',
				'value' => $month,
			),
		);

		if ( $status ) {
			$meta_query[] = array(
				'key'   => 'bc_payment_status',
				'value' => $status,
			);
		}

		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'date';
		$order   = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'DESC';

		$args = array(
			'post_type'      => 'bc_bill',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $current_page,
			's'              => $search,
			'meta_query'     => $meta_query,
			'order'          => strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC',
		);

		if ( in_array( $orderby, array( 'bc_billing_month', 'bc_total_payable_amount', 'bc_payment_status' ), true ) ) {
			$args['orderby']  = 'meta_value';
			$args['meta_key'] = $orderby;
			if ( 'bc_total_payable_amount' === $orderby ) {
				$args['meta_type'] = 'NUMERIC';
			}
		} else {
			$args['orderby'] = 'title';
		}

		$query = new \WP_Query( $args );
		$this->items = $query->posts;

		// Prime metas for the current page of bills (reduces dozens of get_post_meta calls).
		if ( function_exists( __NAMESPACE__ . '\\bcl_prime_post_metas' ) && ! empty( $this->items ) ) {
			bcl_prime_post_metas( wp_list_pluck( $this->items, 'ID' ) );
		}

		$this->set_pagination_args(
			array(
				'total_items' => (int) $query->found_posts,
				'per_page'    => $per_page,
				'total_pages' => (int) $query->max_num_pages,
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}

	/**
	 * Process bulk actions.
	 */
	public function process_bulk_action(): void {
		if ( 'mark_paid' !== $this->current_action() ) {
			return;
		}

		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		if ( ! bcl_current_user_can( 'bc_manage_payments' ) ) {
			return;
		}

		$bill_ids = array_map( 'absint', $_REQUEST['bill_ids'] ?? array() );
		$billing  = new Billing();

		foreach ( $bill_ids as $bill_id ) {
			$billing->record_payment( $bill_id, 0, 'cash', true );
		}
	}
}

/**
 * Recurring expense payables list for Bills & Payments.
 */
class Recurring_Payments_List_Table extends \WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'recurring_payment',
				'plural'   => 'recurring_payments',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Define columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'       => '<input type="checkbox" />',
			'title'    => __( 'Recurring Bill', 'buildingcare-lite' ),
			'category' => __( 'Category', 'buildingcare-lite' ),
			'month'    => __( 'Month', 'buildingcare-lite' ),
			'amount'   => __( 'Amount', 'buildingcare-lite' ),
			'status'   => __( 'Status', 'buildingcare-lite' ),
			'actions'  => __( 'Quick Actions', 'buildingcare-lite' ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string, string>
	 */
	public function get_bulk_actions(): array {
		return array(
			'mark_paid' => __( 'Mark as Paid', 'buildingcare-lite' ),
		);
	}

	/**
	 * Checkbox column.
	 *
	 * @param \WP_Post $item Expense post.
	 */
	public function column_cb( $item ): string {
		if ( bcl_get_meta_string( $item->ID, 'bc_is_paid' ) === 'yes' ) {
			return '';
		}

		return sprintf( '<input type="checkbox" name="expense_ids[]" value="%d" />', (int) $item->ID );
	}

	/**
	 * Default column renderer.
	 *
	 * @param \WP_Post $item Expense post.
	 */
	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'title':
				return sprintf(
					'<strong><a href="%s">%s</a></strong>',
					esc_url( get_edit_post_link( $item->ID ) ),
					esc_html( $item->post_title )
				);
			case 'category':
				$terms = wp_get_post_terms( $item->ID, 'bc_expense_category', array( 'fields' => 'names' ) );
				return ! is_wp_error( $terms ) && ! empty( $terms ) ? esc_html( implode( ', ', $terms ) ) : '—';
			case 'month':
				return esc_html( bcl_get_meta_string( $item->ID, 'bc_expense_month' ) ?: bcl_month_from_date( bcl_get_meta_string( $item->ID, 'bc_expense_date' ) ) );
			case 'amount':
				return esc_html( bcl_format_amount( bcl_get_meta_float( $item->ID, 'bc_amount' ) ) );
			case 'status':
				$paid = bcl_get_meta_string( $item->ID, 'bc_is_paid' ) === 'yes';
				$class = $paid ? 'paid' : 'unpaid';
				$label = $paid ? __( 'Paid', 'buildingcare-lite' ) : __( 'Unpaid', 'buildingcare-lite' );
				return '<span class="bcl-status bcl-status-' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
			case 'actions':
				if ( bcl_get_meta_string( $item->ID, 'bc_is_paid' ) === 'yes' ) {
					return '<span class="bcl-paid-label">' . esc_html__( 'Paid', 'buildingcare-lite' ) . '</span>';
				}
				return sprintf(
					'<button type="button" class="button button-primary button-small bcl-pay-recurring" data-expense-id="%1$d" data-amount="%2$s">%3$s</button>',
					(int) $item->ID,
					esc_attr( (string) bcl_get_meta_float( $item->ID, 'bc_amount' ) ),
					esc_html__( 'Pay Now', 'buildingcare-lite' )
				);
			default:
				return '';
		}
	}

	/**
	 * Prepare recurring expense items.
	 */
	public function prepare_items(): void {
		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$month        = isset( $_REQUEST['billing_month'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['billing_month'] ) ) : bcl_current_billing_month();
		$paid_filter  = isset( $_REQUEST['recurring_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['recurring_status'] ) ) : '';

		$meta_query = array(
			array(
				'key'     => 'bc_recurring_expense_id',
				'compare' => 'EXISTS',
			),
			array(
				'key'   => 'bc_expense_month',
				'value' => $month,
			),
		);

		if ( 'unpaid' === $paid_filter ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'     => 'bc_is_paid',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => 'bc_is_paid',
					'value'   => 'yes',
					'compare' => '!=',
				),
			);
		} elseif ( 'paid' === $paid_filter ) {
			$meta_query[] = array(
				'key'   => 'bc_is_paid',
				'value' => 'yes',
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'bc_expense',
				'post_status'    => 'publish',
				'posts_per_page' => $per_page,
				'paged'          => $current_page,
				'meta_query'     => $meta_query,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$this->items = $query->posts;

		if ( function_exists( __NAMESPACE__ . '\\bcl_prime_post_metas' ) && ! empty( $this->items ) ) {
			bcl_prime_post_metas( wp_list_pluck( $this->items, 'ID' ) );
		}

		$this->set_pagination_args(
			array(
				'total_items' => (int) $query->found_posts,
				'per_page'    => $per_page,
				'total_pages' => (int) $query->max_num_pages,
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	/**
	 * Process bulk pay actions.
	 */
	public function process_bulk_action(): void {
		if ( 'mark_paid' !== $this->current_action() ) {
			return;
		}

		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		if ( ! bcl_current_user_can( 'bc_manage_expenses' ) ) {
			return;
		}

		$expense_ids = array_map( 'absint', $_REQUEST['expense_ids'] ?? array() );
		$expenses    = new Expenses();

		foreach ( $expense_ids as $expense_id ) {
			$expenses->mark_expense_paid( $expense_id );
		}
	}
}

/**
 * Admin menu and custom pages.
 */
class Admin_Pages {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'wp_ajax_bcl_load_panel', array( $this, 'ajax_load_panel' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_filter( 'parent_file', array( $this, 'set_parent_file' ) );
		add_filter( 'submenu_file', array( $this, 'set_submenu_file' ), 10, 2 );
		add_filter( 'redirect_post_location', array( $this, 'redirect_after_save' ), 10, 2 );
		add_filter( 'use_block_editor_for_post_type', array( $this, 'disable_block_editor' ), 10, 2 );
		add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
		add_filter( 'wp_insert_post_data', array( $this, 'sync_bill_title' ), 10, 2 );
	}

	/**
	 * Add a reliable body class on all BuildingCare admin screens.
	 *
	 * @param string $classes Space-separated admin body classes.
	 * @return string
	 */
	public function admin_body_class( string $classes ): string {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return $classes;
		}

		$is_bcl_screen = str_contains( $screen->id, 'bcl-' )
			|| in_array( $screen->post_type, array( 'bc_building', 'bc_flat', 'bc_resident', 'bc_bill', 'bc_expense', 'bc_recurring_expense' ), true );

		if ( $is_bcl_screen ) {
			$classes .= ' bcl-admin';
		}

		return $classes;
	}

	/**
	 * Keep bill post titles synced to the flat number.
	 *
	 * @param array<string, mixed> $data    Post data.
	 * @param array<string, mixed> $postarr Raw post data.
	 * @return array<string, mixed>
	 */
	public function sync_bill_title( array $data, array $postarr ): array {
		if ( 'bc_bill' !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}

		$flat_id = 0;
		if ( isset( $_POST['bc_flat_id'] ) ) {
			$flat_id = absint( wp_unslash( $_POST['bc_flat_id'] ) );
		} elseif ( ! empty( $postarr['ID'] ) ) {
			$flat_id = (int) bcl_get_meta_float( (int) $postarr['ID'], 'bc_flat_id' );
		}

		$flat_number = bcl_get_flat_number( $flat_id );
		if ( $flat_number ) {
			$data['post_title'] = $flat_number;
		}

		return $data;
	}

	/**
	 * Register a single top-level admin page. Everything else is rendered as
	 * tabs inside it (see render_dashboard()).
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'BuildingCare', 'buildingcare-lite' ),
			__( 'BuildingCare', 'buildingcare-lite' ),
			'bc_view_reports',
			'bcl-dashboard',
			array( $this, 'render_dashboard' ),
			'dashicons-building',
			26
		);
	}

	/**
	 * Tab definitions: slug => [label, capability].
	 *
	 * @return array<string, array{label:string, cap:string}>
	 */
	public function get_tabs(): array {
		return array(
			'overview'  => array( 'label' => __( 'Dashboard', 'buildingcare-lite' ), 'cap' => 'bc_view_reports' ),
			'buildings' => array( 'label' => __( 'Buildings', 'buildingcare-lite' ), 'cap' => 'bc_manage_buildings' ),
			'flats'     => array( 'label' => __( 'Flats', 'buildingcare-lite' ), 'cap' => 'bc_manage_flats' ),
			'residents' => array( 'label' => __( 'Residents', 'buildingcare-lite' ), 'cap' => 'bc_manage_residents' ),
			'bills'     => array( 'label' => __( 'Bills & Payments', 'buildingcare-lite' ), 'cap' => 'bc_manage_payments' ),
			'expenses'  => array( 'label' => __( 'Expenses', 'buildingcare-lite' ), 'cap' => 'bc_manage_expenses' ),
			'recurring' => array( 'label' => __( 'Recurring Expenses', 'buildingcare-lite' ), 'cap' => 'bc_manage_expenses' ),
			'reports'   => array( 'label' => __( 'Reports', 'buildingcare-lite' ), 'cap' => 'bc_view_reports' ),
			'settings'  => array( 'label' => __( 'Settings', 'buildingcare-lite' ), 'cap' => 'bc_manage_settings' ),
			'audit'     => array( 'label' => __( 'Audit Log', 'buildingcare-lite' ), 'cap' => 'bc_manage_settings' ),
		);
	}

	/**
	 * Resolve the active tab from the request, falling back to the first allowed tab.
	 */
	private function current_tab(): string {
		$tabs    = $this->get_tabs();
		$request = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';

		if ( isset( $tabs[ $request ] ) && bcl_current_user_can( $tabs[ $request ]['cap'] ) ) {
			return $request;
		}

		foreach ( $tabs as $slug => $tab ) {
			if ( bcl_current_user_can( $tab['cap'] ) ) {
				return $slug;
			}
		}

		return 'overview';
	}

	/**
	 * Render the tabbed dashboard shell and dispatch to the active tab.
	 */
	public function render_dashboard(): void {
		if ( ! bcl_current_user_can( 'bc_view_reports' )
			&& ! bcl_current_user_can( 'bc_manage_payments' )
			&& ! bcl_current_user_can( 'bc_manage_expenses' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'buildingcare-lite' ) );
		}

		$active = $this->current_tab();
		$tabs   = $this->get_tabs();
		?>
		<div class="wrap bcl-wrap bcl-app">
			<h1 class="bcl-app-title">
				<span class="dashicons dashicons-building"></span>
				<?php esc_html_e( 'BuildingCare', 'buildingcare-lite' ); ?>
				<button type="button" id="bcl-install-app" class="button button-secondary bcl-install-btn" hidden>
					<span class="dashicons dashicons-download"></span>
					<?php esc_html_e( 'Install app', 'buildingcare-lite' ); ?>
				</button>
			</h1>

			<button type="button" class="bcl-menu-toggle" aria-expanded="false" aria-controls="bcl-tabs-nav">
				<span class="dashicons dashicons-menu-alt2"></span>
				<span class="bcl-menu-current"><?php echo esc_html( $tabs[ $active ]['label'] ?? __( 'Menu', 'buildingcare-lite' ) ); ?></span>
				<span class="bcl-menu-caret dashicons dashicons-arrow-down-alt2"></span>
			</button>

			<nav id="bcl-tabs-nav" class="bcl-tabs">
				<?php
				foreach ( $tabs as $slug => $tab ) :
					if ( ! bcl_current_user_can( $tab['cap'] ) ) {
						continue;
					}
					$url = add_query_arg(
						array(
							'page' => 'bcl-dashboard',
							'tab'  => $slug,
						),
						admin_url( 'admin.php' )
					);
					?>
					<a class="bcl-tab <?php echo $active === $slug ? 'is-active' : ''; ?>" data-tab="<?php echo esc_attr( $slug ); ?>" href="<?php echo esc_url( $url ); ?>">
						<?php echo esc_html( $tab['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="bcl-tab-panel">
				<?php $this->render_tab_content( $active ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Dispatch tab content.
	 */
	private function render_tab_content( string $tab ): void {
		switch ( $tab ) {
			case 'buildings':
			case 'flats':
			case 'residents':
			case 'expenses':
			case 'recurring':
				( new Dashboard() )->render_entity_tab( $tab );
				break;
			case 'bills':
				$this->render_bills_page();
				break;
			case 'reports':
				$this->render_reports_page();
				break;
			case 'settings':
				$this->render_settings_page();
				break;
			case 'audit':
				$this->render_audit_log_page();
				break;
			case 'overview':
			default:
				$this->render_overview_tab();
				break;
		}
	}

	/**
	 * AJAX: render a single tab panel without a full page reload.
	 */
	public function ajax_load_panel(): void {
		check_ajax_referer( 'bcl_admin_nonce', 'nonce' );

		$params = array();
		if ( isset( $_POST['params'] ) && is_array( $_POST['params'] ) ) {
			foreach ( wp_unslash( $_POST['params'] ) as $key => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$clean_key = sanitize_key( (string) $key );
				if ( is_array( $value ) ) {
					$params[ $clean_key ] = array_map( 'sanitize_text_field', array_map( 'strval', $value ) );
				} else {
					$params[ $clean_key ] = sanitize_text_field( (string) $value );
				}
			}
		}

		// Make the rendering routines (which read superglobals) see the request.
		$_GET     = array_merge( $_GET, $params );
		$_REQUEST = array_merge( $_REQUEST, $params );

		// WP_List_Table builds pagination/sort URLs from REQUEST_URI; point it at
		// the real dashboard page instead of admin-ajax.php.
		$base_path = (string) wp_parse_url( admin_url( 'admin.php' ), PHP_URL_PATH );
		$_SERVER['REQUEST_URI'] = add_query_arg(
			array_merge( array( 'page' => 'bcl-dashboard' ), $params ),
			$base_path
		);

		$tab = isset( $params['tab'] ) ? sanitize_key( $params['tab'] ) : 'overview';

		ob_start();
		$this->render_tab_content( $tab );
		$html = ob_get_clean();

		wp_send_json_success(
			array(
				'html' => $html,
				'tab'  => $this->current_tab(),
			)
		);
	}

	/**
	 * Keep BuildingCare menu active on CPT edit screens.
	 *
	 * @param string|null $parent_file Parent menu file.
	 * @return string|null
	 */
	public function set_parent_file( ?string $parent_file ): ?string {
		$post_type = $this->get_current_bcl_post_type();
		if ( $post_type ) {
			return 'bcl-dashboard';
		}

		return $parent_file;
	}

	/**
	 * Highlight the correct BuildingCare submenu on CPT screens.
	 *
	 * @param string|null $submenu_file Submenu file.
	 * @param string|null $parent_file  Parent menu file.
	 * @return string|null
	 */
	public function set_submenu_file( ?string $submenu_file, ?string $parent_file ): ?string {
		$post_type = $this->get_current_bcl_post_type();
		if ( ! $post_type ) {
			return $submenu_file;
		}

		$menu_slug = $this->get_list_menu_slug( $post_type );
		return $menu_slug ?: $submenu_file;
	}

	/**
	 * Stay on the edit screen after saving a BuildingCare post.
	 */
	public function redirect_after_save( string $location, int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, bcl_get_post_types(), true ) ) {
			return $location;
		}

		if ( isset( $_POST['action'] ) && in_array( sanitize_key( wp_unslash( $_POST['action'] ) ), array( 'trash', 'delete', 'untrash' ), true ) ) {
			return $location;
		}

		if ( ! isset( $_POST['save'] ) && ! isset( $_POST['publish'] ) ) {
			return $location;
		}

		$message = 1;
		if ( isset( $_POST['publish'] ) ) {
			$message = 6;
		} elseif ( in_array( $post->post_status, array( 'draft', 'auto-draft', 'pending' ), true ) ) {
			$message = 10;
		}

		return add_query_arg(
			array(
				'post'    => $post_id,
				'action'  => 'edit',
				'message' => $message,
			),
			admin_url( 'post.php' )
		);
	}

	/**
	 * Use the classic editor — meta boxes are registered for it.
	 */
	public function disable_block_editor( bool $use_block_editor, string $post_type ): bool {
		if ( in_array( $post_type, bcl_get_post_types(), true ) ) {
			return false;
		}

		return $use_block_editor;
	}

	/**
	 * Detect the current BuildingCare post type in admin.
	 */
	private function get_current_bcl_post_type(): string {
		global $typenow;

		if ( $typenow && in_array( $typenow, bcl_get_post_types(), true ) ) {
			return $typenow;
		}

		if ( isset( $_GET['post_type'] ) ) {
			$post_type = sanitize_key( wp_unslash( $_GET['post_type'] ) );
			if ( in_array( $post_type, bcl_get_post_types(), true ) ) {
				return $post_type;
			}
		}

		if ( isset( $_GET['post'] ) ) {
			$post = get_post( absint( $_GET['post'] ) );
			if ( $post && in_array( $post->post_type, bcl_get_post_types(), true ) ) {
				return $post->post_type;
			}
		}

		return '';
	}

	/**
	 * Submenu slug for a CPT list screen.
	 */
	private function get_list_menu_slug( string $post_type ): string {
		$map = array(
			'bc_building'          => 'edit.php?post_type=bc_building',
			'bc_flat'              => 'edit.php?post_type=bc_flat',
			'bc_resident'          => 'edit.php?post_type=bc_resident',
			'bc_bill'              => 'edit.php?post_type=bc_bill',
			'bc_expense'           => 'edit.php?post_type=bc_expense',
			'bc_recurring_expense' => 'edit.php?post_type=bc_recurring_expense',
		);

		return $map[ $post_type ] ?? '';
	}

	/**
	 * Enqueue admin assets on plugin pages.
	 */
	public function enqueue_assets( string $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$is_bcl_page = str_contains( $hook, 'bcl-' )
			|| in_array( $screen->post_type, array( 'bc_building', 'bc_flat', 'bc_resident', 'bc_bill', 'bc_expense', 'bc_recurring_expense' ), true );

		if ( ! $is_bcl_page ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'bcl-admin',
			BCL_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			(string) filemtime( BCL_PLUGIN_DIR . 'assets/css/admin.css' )
		);

		wp_enqueue_script(
			'bcl-admin',
			BCL_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			(string) filemtime( BCL_PLUGIN_DIR . 'assets/js/admin.js' ),
			true
		);

		wp_localize_script(
			'bcl-admin',
			'bclAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'adminPostUrl' => admin_url( 'admin-post.php' ),
				'nonce'       => wp_create_nonce( 'bcl_admin_nonce' ),
				'exportNonce' => wp_create_nonce( 'bcl_export_csv' ),
				'i18n'    => array(
					'confirmPaid'    => __( 'Mark this bill as fully paid?', 'buildingcare-lite' ),
					'confirmExpense' => __( 'Mark this expense as paid?', 'buildingcare-lite' ),
					'enterAmount'    => __( 'Enter payment amount:', 'buildingcare-lite' ),
					'selectMethod'   => __( 'Select payment method:', 'buildingcare-lite' ),
					'error'          => __( 'Something went wrong. Please try again.', 'buildingcare-lite' ),
					'success'        => __( 'Payment recorded.', 'buildingcare-lite' ),
					'collecting'     => __( 'Collecting...', 'buildingcare-lite' ),
					'paying'         => __( 'Processing...', 'buildingcare-lite' ),
					'confirmDelete'  => __( 'Delete this record? This cannot be undone.', 'buildingcare-lite' ),
				),
				'paymentMethods' => bcl_payment_methods(),
			)
		);
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings(): void {
		register_setting(
			'bcl_settings_group',
			'bcl_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => bcl_get_settings(),
			)
		);
	}

	/**
	 * Sanitize settings input.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( array $input ): array {
		$current = bcl_get_settings();

		$current['opening_balance']       = round( (float) ( $input['opening_balance'] ?? 0 ), 2 );
		$current['default_building_id']   = absint( $input['default_building_id'] ?? 0 );
		$current['currency_symbol']       = sanitize_text_field( $input['currency_symbol'] ?? '৳' );
		$current['vacant_charge_percent'] = max( 0.0, min( 100.0, (float) ( $input['vacant_charge_percent'] ?? 50 ) ) );
		$current['late_fee_amount']       = max( 0.0, round( (float) ( $input['late_fee_amount'] ?? 0 ), 2 ) );
		$current['late_fee_type']         = in_array( $input['late_fee_type'] ?? 'fixed', array( 'fixed', 'percent' ), true ) ? (string) $input['late_fee_type'] : 'fixed';
		$current['due_day']               = min( 28, max( 1, absint( $input['due_day'] ?? 10 ) ) );
		$current['enable_emails']         = ! empty( $input['enable_emails'] ) ? 'yes' : 'no';
		$current['enable_reminders']      = ! empty( $input['enable_reminders'] ) ? 'yes' : 'no';
		$current['purge_on_uninstall']    = ! empty( $input['purge_on_uninstall'] ) ? 'yes' : 'no';

		bcl_clear_dashboard_cache();
		bcl_audit_log( 'settings_updated', __( 'Plugin settings updated', 'buildingcare-lite' ) );

		return $current;
	}

	/**
	 * Show admin notices.
	 */
	public function admin_notices(): void {
		if ( ! isset( $_GET['page'] ) || 'bcl-dashboard' !== $_GET['page'] ) {
			return;
		}

		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Record saved.', 'buildingcare-lite' ) . '</p></div>';
		}

		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Record deleted.', 'buildingcare-lite' ) . '</p></div>';
		}

		if ( isset( $_GET['tenants_new'] ) || isset( $_GET['tenants_linked'] ) ) {
			$new    = isset( $_GET['tenants_new'] ) ? absint( $_GET['tenants_new'] ) : 0;
			$linked = isset( $_GET['tenants_linked'] ) ? absint( $_GET['tenants_linked'] ) : 0;
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: 1: created count, 2: linked count */
					__( 'Tenant logins: %1$d created, %2$d linked to existing users.', 'buildingcare-lite' ),
					$new,
					$linked
				)
			) . '</p></div>';
		}

		if ( ! isset( $_GET['bills_created'] ) && ! isset( $_GET['recurring_created'] ) ) {
			return;
		}

		$bills_created     = isset( $_GET['bills_created'] ) ? absint( $_GET['bills_created'] ) : 0;
		$recurring_created = isset( $_GET['recurring_created'] ) ? absint( $_GET['recurring_created'] ) : 0;
		$month             = isset( $_GET['billing_month'] ) ? sanitize_text_field( wp_unslash( $_GET['billing_month'] ) ) : '';
		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				if ( $bills_created > 0 ) {
					printf(
						/* translators: 1: number of bills, 2: billing month */
						esc_html__( '%1$d service charge bills generated for %2$s. ', 'buildingcare-lite' ),
						$bills_created,
						esc_html( $month )
					);
				}
				if ( $recurring_created > 0 ) {
					printf(
						/* translators: 1: number of vouchers, 2: billing month */
						esc_html__( '%1$d recurring expense bills generated for %2$s.', 'buildingcare-lite' ),
						$recurring_created,
						esc_html( $month )
					);
				}
				if ( 0 === $bills_created && 0 === $recurring_created ) {
					esc_html_e( 'No new bills were generated — they may already exist for this month.', 'buildingcare-lite' );
				}
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the overview (stats) tab.
	 */
	public function render_overview_tab(): void {
		if ( ! bcl_current_user_can( 'bc_view_reports' ) ) {
			echo '<p>' . esc_html__( 'You do not have access to the dashboard overview.', 'buildingcare-lite' ) . '</p>';
			return;
		}

		$reports = new Reports();
		$stats   = $reports->get_dashboard_stats();
		$trend   = $reports->get_monthly_trend( 6 );
		$trend_max = 0.0;
		foreach ( $trend as $point ) {
			$trend_max = max( $trend_max, (float) $point['income'], (float) $point['expenses'] );
		}
		?>
		<div class="bcl-tab-body">
			<p class="bcl-subtitle"><?php echo esc_html( sprintf( __( 'Overview for %s', 'buildingcare-lite' ), $stats['month'] ) ); ?></p>

			<div class="bcl-portal-note">
				<span class="dashicons dashicons-smartphone"></span>
				<span class="bcl-portal-note__text">
					<?php esc_html_e( 'Tenant portal:', 'buildingcare-lite' ); ?>
					<a href="<?php echo esc_url( Tenant_Portal::url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( Tenant_Portal::url() ); ?></a>
					<?php esc_html_e( '— residents with an email get an account automatically and can log in here.', 'buildingcare-lite' ); ?>
				</span>
				<?php if ( bcl_current_user_can( 'bc_manage_residents' ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bcl-portal-note__action">
						<input type="hidden" name="action" value="bcl_provision_tenants">
						<?php wp_nonce_field( 'bcl_provision_tenants' ); ?>
						<button type="submit" class="button button-secondary"><?php esc_html_e( 'Create logins for all residents', 'buildingcare-lite' ); ?></button>
					</form>
				<?php endif; ?>
			</div>

			<div class="bcl-dashboard-grid">
				<div class="bcl-card bcl-card-income">
					<h3><?php esc_html_e( 'Current Month Income', 'buildingcare-lite' ); ?></h3>
					<p class="bcl-stat-value"><?php echo esc_html( bcl_format_amount( (float) $stats['income'] ) ); ?></p>
				</div>
				<div class="bcl-card bcl-card-expense">
					<h3><?php esc_html_e( 'Current Month Expenses', 'buildingcare-lite' ); ?></h3>
					<p class="bcl-stat-value"><?php echo esc_html( bcl_format_amount( (float) $stats['expenses'] ) ); ?></p>
				</div>
				<div class="bcl-card bcl-card-balance">
					<h3><?php esc_html_e( 'Current Balance', 'buildingcare-lite' ); ?></h3>
					<p class="bcl-stat-value"><?php echo esc_html( bcl_format_amount( (float) $stats['closing_balance'] ) ); ?></p>
				</div>
				<div class="bcl-card bcl-card-dues">
					<h3><?php esc_html_e( 'Outstanding Dues', 'buildingcare-lite' ); ?></h3>
					<p class="bcl-stat-value"><?php echo esc_html( bcl_format_amount( (float) $stats['outstanding_dues'] ) ); ?></p>
				</div>
				<div class="bcl-card">
					<h3><?php esc_html_e( 'Unpaid Flats', 'buildingcare-lite' ); ?></h3>
					<p class="bcl-stat-value"><?php echo esc_html( (string) (int) $stats['unpaid_flats'] ); ?></p>
				</div>
				<div class="bcl-card">
					<h3><?php esc_html_e( 'Collection %', 'buildingcare-lite' ); ?></h3>
					<p class="bcl-stat-value"><?php echo esc_html( (string) $stats['collection_percent'] ); ?>%</p>
				</div>
			</div>

			<div class="bcl-balance-sheet">
				<h2><?php esc_html_e( 'Balance Sheet', 'buildingcare-lite' ); ?></h2>
				<table class="widefat striped">
					<tbody>
						<tr>
							<td><?php esc_html_e( 'Opening Balance', 'buildingcare-lite' ); ?></td>
							<td><?php echo esc_html( bcl_format_amount( (float) $stats['opening_balance'] ) ); ?></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Total Collected', 'buildingcare-lite' ); ?></td>
							<td><?php echo esc_html( bcl_format_amount( (float) $stats['income'] ) ); ?></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Total Expenses', 'buildingcare-lite' ); ?></td>
							<td><?php echo esc_html( bcl_format_amount( (float) $stats['expenses'] ) ); ?></td>
						</tr>
						<tr class="bcl-total-row">
							<td><strong><?php esc_html_e( 'Closing Balance', 'buildingcare-lite' ); ?></strong></td>
							<td><strong><?php echo esc_html( bcl_format_amount( (float) $stats['closing_balance'] ) ); ?></strong></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Monthly Surplus', 'buildingcare-lite' ); ?></td>
							<td class="bcl-positive"><?php echo esc_html( bcl_format_amount( (float) $stats['surplus'] ) ); ?></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Monthly Deficit', 'buildingcare-lite' ); ?></td>
							<td class="bcl-negative"><?php echo esc_html( bcl_format_amount( (float) $stats['deficit'] ) ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="bcl-chart-section">
				<h2><?php esc_html_e( 'Income vs Expense (last 6 months)', 'buildingcare-lite' ); ?></h2>
				<div class="bcl-chart" role="img" aria-label="<?php esc_attr_e( 'Income versus expense chart', 'buildingcare-lite' ); ?>">
					<?php foreach ( $trend as $point ) : ?>
						<?php
						$income_h  = $trend_max > 0 ? round( ( (float) $point['income'] / $trend_max ) * 100 ) : 0;
						$expense_h = $trend_max > 0 ? round( ( (float) $point['expenses'] / $trend_max ) * 100 ) : 0;
						?>
						<div class="bcl-chart-col">
							<div class="bcl-chart-bars">
								<span class="bcl-bar bcl-bar-income" style="height: <?php echo esc_attr( (string) $income_h ); ?>%;" title="<?php echo esc_attr( bcl_format_amount( (float) $point['income'] ) ); ?>"></span>
								<span class="bcl-bar bcl-bar-expense" style="height: <?php echo esc_attr( (string) $expense_h ); ?>%;" title="<?php echo esc_attr( bcl_format_amount( (float) $point['expenses'] ) ); ?>"></span>
							</div>
							<span class="bcl-chart-label"><?php echo esc_html( (string) $point['label'] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
				<div class="bcl-chart-legend">
					<span><i class="bcl-swatch bcl-swatch-income"></i><?php esc_html_e( 'Income', 'buildingcare-lite' ); ?></span>
					<span><i class="bcl-swatch bcl-swatch-expense"></i><?php esc_html_e( 'Expense', 'buildingcare-lite' ); ?></span>
				</div>
			</div>

			<div class="bcl-recent-transactions">
				<h2><?php esc_html_e( 'Recent Transactions', 'buildingcare-lite' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Type', 'buildingcare-lite' ); ?></th>
							<th><?php esc_html_e( 'Description', 'buildingcare-lite' ); ?></th>
							<th><?php esc_html_e( 'Amount', 'buildingcare-lite' ); ?></th>
							<th><?php esc_html_e( 'Date', 'buildingcare-lite' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $stats['recent_transactions'] ) ) : ?>
							<tr><td colspan="4"><?php esc_html_e( 'No transactions yet.', 'buildingcare-lite' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $stats['recent_transactions'] as $tx ) : ?>
								<tr>
									<td><span class="bcl-tx-<?php echo esc_attr( (string) $tx['type'] ); ?>"><?php echo esc_html( ucfirst( (string) $tx['type'] ) ); ?></span></td>
									<td><?php echo esc_html( (string) $tx['title'] ); ?></td>
									<td><?php echo esc_html( bcl_format_amount( (float) $tx['amount'] ) ); ?></td>
									<td><?php echo esc_html( (string) $tx['date'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render bills and payments page.
	 */
	public function render_bills_page(): void {
		if ( ! bcl_current_user_can( 'bc_manage_payments' ) && ! bcl_current_user_can( 'bc_manage_expenses' ) ) {
			echo '<p>' . esc_html__( 'Permission denied.', 'buildingcare-lite' ) . '</p>';
			return;
		}

		$bills_table = new Bills_List_Table();
		$bills_table->process_bulk_action();
		$bills_table->prepare_items();

		$recurring_table = new Recurring_Payments_List_Table();
		$recurring_table->process_bulk_action();
		$recurring_table->prepare_items();

		$month = isset( $_REQUEST['billing_month'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['billing_month'] ) ) : bcl_current_billing_month();
		$can_generate_bills     = bcl_current_user_can( 'bc_generate_bills' );
		$can_generate_recurring = bcl_current_user_can( 'bc_manage_expenses' );
		?>
		<div class="bcl-tab-body bcl-page-bills">
			<p class="bcl-subtitle"><?php esc_html_e( 'Generate monthly bills and collect payments in one click — no manual amount entry needed.', 'buildingcare-lite' ); ?></p>

			<?php if ( $can_generate_bills || $can_generate_recurring ) : ?>
				<div class="bcl-panel bcl-generate-panel">
					<h2 class="bcl-panel-title"><?php esc_html_e( 'Generate Monthly Bills', 'buildingcare-lite' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bcl-generate-form">
						<?php wp_nonce_field( 'bcl_generate_monthly' ); ?>
						<input type="hidden" name="action" value="bcl_generate_monthly">
						<div class="bcl-generate-fields">
							<label for="billing_month_gen"><?php esc_html_e( 'Billing month', 'buildingcare-lite' ); ?></label>
							<input type="month" name="billing_month" id="billing_month_gen" value="<?php echo esc_attr( $month ); ?>">
						</div>
						<div class="bcl-generate-actions">
							<?php if ( $can_generate_bills && $can_generate_recurring ) : ?>
								<?php submit_button( __( 'Generate All Bills', 'buildingcare-lite' ), 'primary', 'generate_type', false, array( 'value' => 'all' ) ); ?>
							<?php endif; ?>
							<?php if ( $can_generate_bills ) : ?>
								<?php submit_button( __( 'Service Charge Bills', 'buildingcare-lite' ), 'secondary', 'generate_type', false, array( 'value' => 'bills' ) ); ?>
							<?php endif; ?>
							<?php if ( $can_generate_recurring ) : ?>
								<?php submit_button( __( 'Recurring Expense Bills', 'buildingcare-lite' ), 'secondary', 'generate_type', false, array( 'value' => 'recurring' ) ); ?>
							<?php endif; ?>
						</div>
					</form>
				</div>
			<?php endif; ?>

			<?php if ( bcl_current_user_can( 'bc_manage_payments' ) ) : ?>
				<div class="bcl-panel bcl-payments-section">
					<div class="bcl-panel-header">
						<h2 class="bcl-panel-title"><?php esc_html_e( 'Service Charge Collection', 'buildingcare-lite' ); ?></h2>
						<p class="bcl-panel-desc"><?php esc_html_e( 'Flat service charge bills — click Collect Payment to record the full due amount instantly.', 'buildingcare-lite' ); ?></p>
						<p class="bcl-collect-method-wrap">
							<label for="bcl-collect-method"><?php esc_html_e( 'Default collection method:', 'buildingcare-lite' ); ?></label>
							<select id="bcl-collect-method">
								<?php foreach ( bcl_payment_methods() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
					</div>

					<form method="get" class="bcl-list-form">
						<input type="hidden" name="page" value="bcl-dashboard">
						<input type="hidden" name="tab" value="bills">
						<div class="bcl-filter-bar">
							<div class="bcl-filter-bar__field">
								<label class="bcl-field-label" for="bcl-bills-month"><?php esc_html_e( 'Month', 'buildingcare-lite' ); ?></label>
								<input type="month" name="billing_month" id="bcl-bills-month" value="<?php echo esc_attr( $month ); ?>">
							</div>
							<div class="bcl-filter-bar__field">
								<label class="bcl-field-label" for="bcl-bills-status"><?php esc_html_e( 'Status', 'buildingcare-lite' ); ?></label>
								<select name="payment_status" id="bcl-bills-status">
									<option value=""><?php esc_html_e( 'All statuses', 'buildingcare-lite' ); ?></option>
									<?php foreach ( bcl_payment_statuses() as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( sanitize_key( wp_unslash( $_REQUEST['payment_status'] ?? '' ) ), $key ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="bcl-filter-bar__actions">
								<?php submit_button( __( 'Apply Filters', 'buildingcare-lite' ), 'secondary', 'filter_bills', false ); ?>
							</div>
						</div>
						<div class="bcl-list-table-shell">
							<?php $bills_table->search_box( __( 'Search Bills', 'buildingcare-lite' ), 'bcl-bill-search' ); ?>
							<?php $bills_table->display(); ?>
						</div>
					</form>
				</div>
			<?php endif; ?>

			<?php if ( bcl_current_user_can( 'bc_manage_expenses' ) ) : ?>
				<div class="bcl-panel bcl-payments-section">
					<div class="bcl-panel-header">
						<h2 class="bcl-panel-title"><?php esc_html_e( 'Recurring Expense Payments', 'buildingcare-lite' ); ?></h2>
						<p class="bcl-panel-desc"><?php esc_html_e( 'Auto-generated recurring bills — click Pay Now to mark as paid without entering details.', 'buildingcare-lite' ); ?></p>
					</div>

					<form method="get" class="bcl-list-form">
						<input type="hidden" name="page" value="bcl-dashboard">
						<input type="hidden" name="tab" value="bills">
						<input type="hidden" name="billing_month" value="<?php echo esc_attr( $month ); ?>">
						<div class="bcl-filter-bar">
							<div class="bcl-filter-bar__field">
								<label class="bcl-field-label" for="bcl-recurring-status"><?php esc_html_e( 'Status', 'buildingcare-lite' ); ?></label>
								<select name="recurring_status" id="bcl-recurring-status">
									<option value=""><?php esc_html_e( 'All statuses', 'buildingcare-lite' ); ?></option>
									<option value="unpaid" <?php selected( sanitize_key( wp_unslash( $_REQUEST['recurring_status'] ?? '' ) ), 'unpaid' ); ?>><?php esc_html_e( 'Unpaid', 'buildingcare-lite' ); ?></option>
									<option value="paid" <?php selected( sanitize_key( wp_unslash( $_REQUEST['recurring_status'] ?? '' ) ), 'paid' ); ?>><?php esc_html_e( 'Paid', 'buildingcare-lite' ); ?></option>
								</select>
							</div>
							<div class="bcl-filter-bar__actions">
								<?php submit_button( __( 'Apply Filters', 'buildingcare-lite' ), 'secondary', 'filter_recurring', false ); ?>
							</div>
						</div>
						<div class="bcl-list-table-shell">
							<?php $recurring_table->display(); ?>
						</div>
					</form>
				</div>
			<?php endif; ?>
		</div>

		<div id="bcl-payment-modal" class="bcl-modal" hidden>
			<div class="bcl-modal-content">
				<h3><?php esc_html_e( 'Record Payment', 'buildingcare-lite' ); ?></h3>
				<p>
					<label><?php esc_html_e( 'Amount', 'buildingcare-lite' ); ?></label>
					<input type="number" id="bcl-payment-amount" step="0.01" min="0" class="regular-text">
				</p>
				<p>
					<label><?php esc_html_e( 'Payment Method', 'buildingcare-lite' ); ?></label>
					<select id="bcl-payment-method">
						<?php foreach ( bcl_payment_methods() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="bcl-modal-actions">
					<button type="button" class="button button-primary" id="bcl-payment-submit"><?php esc_html_e( 'Save Payment', 'buildingcare-lite' ); ?></button>
					<button type="button" class="button" id="bcl-payment-cancel"><?php esc_html_e( 'Cancel', 'buildingcare-lite' ); ?></button>
				</p>
				<input type="hidden" id="bcl-payment-bill-id" value="">
			</div>
		</div>
		<?php
	}

	/**
	 * Render reports page.
	 */
	public function render_reports_page(): void {
		if ( ! bcl_current_user_can( 'bc_view_reports' ) ) {
			echo '<p>' . esc_html__( 'Permission denied.', 'buildingcare-lite' ) . '</p>';
			return;
		}

		$report_types = array(
			'collection'        => __( 'Monthly Collection', 'buildingcare-lite' ),
			'flat_wise'         => __( 'Flat-wise Report', 'buildingcare-lite' ),
			'resident_wise'     => __( 'Resident-wise Report', 'buildingcare-lite' ),
			'due'               => __( 'Due Report', 'buildingcare-lite' ),
			'expense'           => __( 'Monthly Expense', 'buildingcare-lite' ),
			'income_vs_expense' => __( 'Income vs Expense', 'buildingcare-lite' ),
		);
		?>
		<div class="bcl-tab-body bcl-page-reports">
			<p class="bcl-subtitle"><?php esc_html_e( 'Analyze collections, dues, expenses, and building performance across any time period.', 'buildingcare-lite' ); ?></p>

			<div class="bcl-panel bcl-report-toolbar-panel">
				<h2 class="bcl-panel-title"><?php esc_html_e( 'Report Filters', 'buildingcare-lite' ); ?></h2>
				<div class="bcl-report-filters">
					<div class="bcl-filter-field">
						<label class="bcl-field-label" for="bcl-report-type"><?php esc_html_e( 'Report type', 'buildingcare-lite' ); ?></label>
						<select id="bcl-report-type">
							<?php foreach ( $report_types as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="bcl-filter-field">
						<label class="bcl-field-label" for="bcl-date-filter"><?php esc_html_e( 'Date range', 'buildingcare-lite' ); ?></label>
						<select id="bcl-date-filter">
							<option value="current_month"><?php esc_html_e( 'Current Month', 'buildingcare-lite' ); ?></option>
							<option value="last_6_months"><?php esc_html_e( 'Last 6 Months', 'buildingcare-lite' ); ?></option>
							<option value="last_12_months"><?php esc_html_e( 'Last 12 Months', 'buildingcare-lite' ); ?></option>
							<option value="custom"><?php esc_html_e( 'Custom Range', 'buildingcare-lite' ); ?></option>
						</select>
					</div>
					<div class="bcl-filter-field bcl-custom-date-field" hidden>
						<label class="bcl-field-label" for="bcl-start-date"><?php esc_html_e( 'Start date', 'buildingcare-lite' ); ?></label>
						<input type="date" id="bcl-start-date" class="bcl-custom-date">
					</div>
					<div class="bcl-filter-field bcl-custom-date-field" hidden>
						<label class="bcl-field-label" for="bcl-end-date"><?php esc_html_e( 'End date', 'buildingcare-lite' ); ?></label>
						<input type="date" id="bcl-end-date" class="bcl-custom-date">
					</div>
					<div class="bcl-filter-actions">
						<button type="button" class="button button-primary" id="bcl-load-report"><?php esc_html_e( 'Load Report', 'buildingcare-lite' ); ?></button>
						<button type="button" class="button" id="bcl-export-csv"><?php esc_html_e( 'Export CSV', 'buildingcare-lite' ); ?></button>
					</div>
				</div>
			</div>

			<div class="bcl-panel bcl-report-results-panel" id="bcl-report-results">
				<h2 class="bcl-panel-title"><?php esc_html_e( 'Report Results', 'buildingcare-lite' ); ?></h2>
				<div class="bcl-table-wrap">
					<table class="widefat striped" id="bcl-report-table">
						<thead></thead>
						<tbody>
							<tr><td colspan="8" class="bcl-empty-state"><?php esc_html_e( 'Select a report and click Load Report.', 'buildingcare-lite' ); ?></td></tr>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page(): void {
		if ( ! bcl_current_user_can( 'bc_manage_settings' ) ) {
			echo '<p>' . esc_html__( 'Permission denied.', 'buildingcare-lite' ) . '</p>';
			return;
		}

		$settings = bcl_get_settings();
		?>
		<div class="bcl-tab-body bcl-page-settings">
			<p class="bcl-subtitle"><?php esc_html_e( 'Configure opening balance, currency, and default building for your account.', 'buildingcare-lite' ); ?></p>

			<div class="bcl-panel bcl-settings-panel">
				<h2 class="bcl-panel-title"><?php esc_html_e( 'General Settings', 'buildingcare-lite' ); ?></h2>
				<form method="post" action="options.php" class="bcl-settings-form">
					<?php settings_fields( 'bcl_settings_group' ); ?>
					<table class="form-table bcl-settings-table" role="presentation">
						<tr>
							<th scope="row"><label for="opening_balance"><?php esc_html_e( 'Opening Balance', 'buildingcare-lite' ); ?></label></th>
							<td><input type="number" step="0.01" name="bcl_settings[opening_balance]" id="opening_balance" value="<?php echo esc_attr( (string) $settings['opening_balance'] ); ?>" class="regular-text"></td>
						</tr>
						<tr>
							<th scope="row"><label for="currency_symbol"><?php esc_html_e( 'Currency Symbol', 'buildingcare-lite' ); ?></label></th>
							<td><input type="text" name="bcl_settings[currency_symbol]" id="currency_symbol" value="<?php echo esc_attr( (string) $settings['currency_symbol'] ); ?>" class="small-text"></td>
						</tr>
						<tr>
							<th scope="row"><label for="default_building_id"><?php esc_html_e( 'Default Building', 'buildingcare-lite' ); ?></label></th>
							<td>
								<select name="bcl_settings[default_building_id]" id="default_building_id">
									<option value="0"><?php esc_html_e( '— None —', 'buildingcare-lite' ); ?></option>
									<?php foreach ( bcl_get_buildings_options() as $id => $name ) : ?>
										<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( (int) $settings['default_building_id'], $id ); ?>>
											<?php echo esc_html( $name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="vacant_charge_percent"><?php esc_html_e( 'Vacant Flat Charge (%)', 'buildingcare-lite' ); ?></label></th>
							<td>
								<input type="number" step="1" min="0" max="100" name="bcl_settings[vacant_charge_percent]" id="vacant_charge_percent" value="<?php echo esc_attr( (string) $settings['vacant_charge_percent'] ); ?>" class="small-text">
								<p class="description"><?php esc_html_e( 'Percentage of the service charge billed to vacant flats.', 'buildingcare-lite' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="due_day"><?php esc_html_e( 'Payment Due Day', 'buildingcare-lite' ); ?></label></th>
							<td>
								<input type="number" step="1" min="1" max="28" name="bcl_settings[due_day]" id="due_day" value="<?php echo esc_attr( (string) $settings['due_day'] ); ?>" class="small-text">
								<p class="description"><?php esc_html_e( 'Day of the billing month that payment is due (1–28).', 'buildingcare-lite' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="late_fee_amount"><?php esc_html_e( 'Late Fee', 'buildingcare-lite' ); ?></label></th>
							<td>
								<input type="number" step="0.01" min="0" name="bcl_settings[late_fee_amount]" id="late_fee_amount" value="<?php echo esc_attr( (string) $settings['late_fee_amount'] ); ?>" class="small-text">
								<select name="bcl_settings[late_fee_type]" id="late_fee_type">
									<option value="fixed" <?php selected( $settings['late_fee_type'], 'fixed' ); ?>><?php esc_html_e( 'Fixed amount', 'buildingcare-lite' ); ?></option>
									<option value="percent" <?php selected( $settings['late_fee_type'], 'percent' ); ?>><?php esc_html_e( 'Percent of overdue', 'buildingcare-lite' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Applied to a new bill when it carries an unpaid balance forward. Set 0 to disable.', 'buildingcare-lite' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Email Notifications', 'buildingcare-lite' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="bcl_settings[enable_emails]" value="1" <?php checked( $settings['enable_emails'], 'yes' ); ?>>
									<?php esc_html_e( 'Email residents a receipt when a payment is recorded.', 'buildingcare-lite' ); ?>
								</label>
								<br>
								<label>
									<input type="checkbox" name="bcl_settings[enable_reminders]" value="1" <?php checked( $settings['enable_reminders'], 'yes' ); ?>>
									<?php esc_html_e( 'Send daily due/overdue payment reminders to residents.', 'buildingcare-lite' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Data Cleanup', 'buildingcare-lite' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="bcl_settings[purge_on_uninstall]" value="1" <?php checked( $settings['purge_on_uninstall'], 'yes' ); ?>>
									<?php esc_html_e( 'Delete all BuildingCare data (buildings, flats, residents, bills, expenses, payments) when the plugin is uninstalled.', 'buildingcare-lite' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Warning: this permanently removes all records on plugin deletion.', 'buildingcare-lite' ); ?></p>
							</td>
						</tr>
					</table>
					<div class="bcl-settings-actions">
						<?php submit_button( __( 'Save Settings', 'buildingcare-lite' ) ); ?>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render audit log page.
	 */
	public function render_audit_log_page(): void {
		if ( ! bcl_current_user_can( 'bc_manage_settings' ) ) {
			echo '<p>' . esc_html__( 'Permission denied.', 'buildingcare-lite' ) . '</p>';
			return;
		}

		$logs = get_option( 'bcl_audit_log', array() );
		$logs = is_array( $logs ) ? $logs : array();
		// Keep only recent without full reverse in memory for display (slice first).
		$logs = array_slice( array_reverse( $logs ), 0, 100 );
		?>
		<div class="bcl-tab-body">
			<p class="bcl-subtitle"><?php esc_html_e( 'Recent activity across bills, payments, expenses, and settings.', 'buildingcare-lite' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'buildingcare-lite' ); ?></th>
						<th><?php esc_html_e( 'User', 'buildingcare-lite' ); ?></th>
						<th><?php esc_html_e( 'Action', 'buildingcare-lite' ); ?></th>
						<th><?php esc_html_e( 'Message', 'buildingcare-lite' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $logs ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No audit entries yet.', 'buildingcare-lite' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( array_slice( $logs, 0, 100 ) as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( (string) ( $entry['time'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( bcl_get_audit_user_name( (int) ( $entry['user_id'] ?? 0 ) ) ); ?></td>
								<td><?php echo esc_html( (string) ( $entry['action'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $entry['message'] ?? '' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
