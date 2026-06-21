<?php
/**
 * In-dashboard CRUD for BuildingCare entities (buildings, flats, residents,
 * expenses, recurring expenses). Lists and add/edit forms are rendered inside
 * the single dashboard page; saves reuse the existing meta-box logic by posting
 * the same field names and nonces.
 *
 * @package BuildingCareLite
 */

declare(strict_types=1);

namespace BuildingCareLite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles entity list/form rendering and persistence within the dashboard.
 */
class Dashboard {

	private const PER_PAGE = 20;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_post_bcl_save_entity', array( $this, 'save_entity' ) );
		add_action( 'admin_post_bcl_delete_entity', array( $this, 'delete_entity' ) );
	}

	/**
	 * Entity configuration keyed by tab slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_entities(): array {
		return array(
			'buildings' => array(
				'post_type'   => 'bc_building',
				'cap'         => 'bc_manage_buildings',
				'singular'    => __( 'Building', 'buildingcare-lite' ),
				'plural'      => __( 'Buildings', 'buildingcare-lite' ),
				'title_label' => __( 'Building Name', 'buildingcare-lite' ),
				'nonce'       => array( 'bcl_save_building', 'bcl_building_nonce' ),
				'columns'     => array(
					'post_title'      => __( 'Building Name', 'buildingcare-lite' ),
					'bc_address'      => __( 'Address', 'buildingcare-lite' ),
					'bc_total_floors' => __( 'Floors', 'buildingcare-lite' ),
					'bc_status'       => __( 'Status', 'buildingcare-lite' ),
				),
				'fields'      => array(
					array( 'key' => 'bc_address', 'label' => __( 'Address', 'buildingcare-lite' ), 'type' => 'textarea' ),
					array( 'key' => 'bc_total_floors', 'label' => __( 'Total Floors', 'buildingcare-lite' ), 'type' => 'number_int' ),
					array( 'key' => 'bc_status', 'label' => __( 'Status', 'buildingcare-lite' ), 'type' => 'select', 'options' => 'building_statuses', 'default' => 'active' ),
				),
			),
			'flats'     => array(
				'post_type'   => 'bc_flat',
				'cap'         => 'bc_manage_flats',
				'singular'    => __( 'Flat', 'buildingcare-lite' ),
				'plural'      => __( 'Flats', 'buildingcare-lite' ),
				'title_label' => __( 'Flat Name', 'buildingcare-lite' ),
				'nonce'       => array( 'bcl_save_flat', 'bcl_flat_nonce' ),
				'columns'     => array(
					'post_title'                => __( 'Flat', 'buildingcare-lite' ),
					'bc_building_id'            => __( 'Building', 'buildingcare-lite' ),
					'bc_flat_number'            => __( 'Flat No.', 'buildingcare-lite' ),
					'bc_monthly_service_charge' => __( 'Service Charge', 'buildingcare-lite' ),
					'bc_occupancy_status'       => __( 'Occupancy', 'buildingcare-lite' ),
				),
				'fields'      => array(
					array( 'key' => 'bc_building_id', 'label' => __( 'Building', 'buildingcare-lite' ), 'type' => 'select', 'options' => 'buildings' ),
					array( 'key' => 'bc_flat_number', 'label' => __( 'Flat Number', 'buildingcare-lite' ), 'type' => 'text' ),
					array( 'key' => 'bc_floor_number', 'label' => __( 'Floor Number', 'buildingcare-lite' ), 'type' => 'number_int' ),
					array( 'key' => 'bc_flat_size', 'label' => __( 'Flat Size (sq ft)', 'buildingcare-lite' ), 'type' => 'number' ),
					array( 'key' => 'bc_monthly_service_charge', 'label' => __( 'Monthly Service Charge', 'buildingcare-lite' ), 'type' => 'number' ),
					array( 'key' => 'bc_occupancy_status', 'label' => __( 'Occupancy Status', 'buildingcare-lite' ), 'type' => 'select', 'options' => 'occupancy_statuses', 'default' => 'vacant' ),
				),
			),
			'residents' => array(
				'post_type'   => 'bc_resident',
				'cap'         => 'bc_manage_residents',
				'singular'    => __( 'Resident', 'buildingcare-lite' ),
				'plural'      => __( 'Residents', 'buildingcare-lite' ),
				'title_label' => __( 'Resident Name', 'buildingcare-lite' ),
				'nonce'       => array( 'bcl_save_resident', 'bcl_resident_nonce' ),
				'columns'     => array(
					'post_title'          => __( 'Resident', 'buildingcare-lite' ),
					'bc_mobile'           => __( 'Mobile', 'buildingcare-lite' ),
					'bc_email'            => __( 'Email', 'buildingcare-lite' ),
					'bc_assigned_flat_id' => __( 'Flat', 'buildingcare-lite' ),
				),
				'fields'      => array(
					array( 'key' => 'bc_mobile', 'label' => __( 'Mobile Number', 'buildingcare-lite' ), 'type' => 'text' ),
					array( 'key' => 'bc_email', 'label' => __( 'Email Address', 'buildingcare-lite' ), 'type' => 'email' ),
					array( 'key' => 'bc_emergency_contact', 'label' => __( 'Emergency Contact', 'buildingcare-lite' ), 'type' => 'text' ),
					array( 'key' => 'bc_move_in_date', 'label' => __( 'Move-in Date', 'buildingcare-lite' ), 'type' => 'date' ),
					array( 'key' => 'bc_move_out_date', 'label' => __( 'Move-out Date', 'buildingcare-lite' ), 'type' => 'date' ),
					array( 'key' => 'bc_assigned_flat_id', 'label' => __( 'Assigned Flat', 'buildingcare-lite' ), 'type' => 'select', 'options' => 'flats' ),
				),
			),
			'expenses'  => array(
				'post_type'   => 'bc_expense',
				'cap'         => 'bc_manage_expenses',
				'singular'    => __( 'Expense', 'buildingcare-lite' ),
				'plural'      => __( 'Expenses', 'buildingcare-lite' ),
				'title_label' => __( 'Expense Title', 'buildingcare-lite' ),
				'nonce'       => array( 'bcl_save_expense', 'bcl_expense_nonce' ),
				'taxonomy'    => 'bc_expense_category',
				'columns'     => array(
					'post_title'      => __( 'Expense', 'buildingcare-lite' ),
					'bc_expense_date' => __( 'Date', 'buildingcare-lite' ),
					'category'        => __( 'Category', 'buildingcare-lite' ),
					'bc_amount'       => __( 'Amount', 'buildingcare-lite' ),
					'bc_is_paid'      => __( 'Paid', 'buildingcare-lite' ),
				),
				'fields'      => array(
					array( 'key' => 'bc_expense_date', 'label' => __( 'Expense Date', 'buildingcare-lite' ), 'type' => 'date', 'default' => 'today' ),
					array( 'key' => 'bc_expense_category', 'label' => __( 'Category', 'buildingcare-lite' ), 'type' => 'taxonomy' ),
					array( 'key' => 'bc_description', 'label' => __( 'Description', 'buildingcare-lite' ), 'type' => 'textarea' ),
					array( 'key' => 'bc_amount', 'label' => __( 'Amount', 'buildingcare-lite' ), 'type' => 'number' ),
					array( 'key' => 'bc_is_paid', 'label' => __( 'Marked as Paid', 'buildingcare-lite' ), 'type' => 'select', 'options' => 'yes_no', 'default' => 'no' ),
					array( 'key' => 'bc_attachment_id', 'label' => __( 'Attachment', 'buildingcare-lite' ), 'type' => 'attachment' ),
				),
			),
			'recurring' => array(
				'post_type'   => 'bc_recurring_expense',
				'cap'         => 'bc_manage_expenses',
				'singular'    => __( 'Recurring Expense', 'buildingcare-lite' ),
				'plural'      => __( 'Recurring Expenses', 'buildingcare-lite' ),
				'title_label' => __( 'Title', 'buildingcare-lite' ),
				'nonce'       => array( 'bcl_save_recurring', 'bcl_recurring_nonce' ),
				'taxonomy'    => 'bc_expense_category',
				'columns'     => array(
					'post_title'       => __( 'Title', 'buildingcare-lite' ),
					'category'         => __( 'Category', 'buildingcare-lite' ),
					'bc_monthly_amount' => __( 'Monthly Amount', 'buildingcare-lite' ),
					'bc_active_status' => __( 'Active', 'buildingcare-lite' ),
				),
				'fields'      => array(
					array( 'key' => 'bc_expense_category', 'label' => __( 'Category', 'buildingcare-lite' ), 'type' => 'taxonomy' ),
					array( 'key' => 'bc_monthly_amount', 'label' => __( 'Monthly Amount', 'buildingcare-lite' ), 'type' => 'number' ),
					array( 'key' => 'bc_active_status', 'label' => __( 'Active', 'buildingcare-lite' ), 'type' => 'select', 'options' => 'yes_no', 'default' => 'yes' ),
				),
			),
			'maintenance' => array(
				'post_type'   => 'bc_ticket',
				'cap'         => 'bc_manage_tickets',
				'singular'    => __( 'Request', 'buildingcare-lite' ),
				'plural'      => __( 'Maintenance Requests', 'buildingcare-lite' ),
				'title_label' => __( 'Subject', 'buildingcare-lite' ),
				'nonce'       => array( 'bcl_save_ticket', 'bcl_ticket_nonce' ),
				'filter'      => array( 'key' => 'bc_ticket_status', 'label' => __( 'Status', 'buildingcare-lite' ), 'options' => 'ticket_statuses' ),
				'columns'     => array(
					'post_title'         => __( 'Subject', 'buildingcare-lite' ),
					'bc_flat_id'         => __( 'Flat', 'buildingcare-lite' ),
					'bc_ticket_category' => __( 'Category', 'buildingcare-lite' ),
					'bc_ticket_priority' => __( 'Priority', 'buildingcare-lite' ),
					'bc_ticket_status'   => __( 'Status', 'buildingcare-lite' ),
				),
				'fields'      => array(
					array( 'key' => 'bc_flat_id', 'label' => __( 'Flat', 'buildingcare-lite' ), 'type' => 'select', 'options' => 'flats' ),
					array( 'key' => 'bc_resident_id', 'label' => __( 'Resident', 'buildingcare-lite' ), 'type' => 'select', 'options' => 'residents' ),
					array( 'key' => 'bc_ticket_category', 'label' => __( 'Category', 'buildingcare-lite' ), 'type' => 'select', 'options' => 'ticket_categories', 'default' => 'other' ),
					array( 'key' => 'bc_ticket_priority', 'label' => __( 'Priority', 'buildingcare-lite' ), 'type' => 'select', 'options' => 'ticket_priorities', 'default' => 'normal' ),
					array( 'key' => 'bc_ticket_status', 'label' => __( 'Status', 'buildingcare-lite' ), 'type' => 'select', 'options' => 'ticket_statuses', 'default' => 'open' ),
					array( 'key' => 'bc_description', 'label' => __( 'Description', 'buildingcare-lite' ), 'type' => 'textarea' ),
					array( 'key' => 'bc_admin_response', 'label' => __( 'Response to Resident', 'buildingcare-lite' ), 'type' => 'textarea' ),
				),
			),
			'notices'   => array(
				'post_type'   => 'bc_notice',
				'cap'         => 'bc_manage_notices',
				'singular'    => __( 'Notice', 'buildingcare-lite' ),
				'plural'      => __( 'Notices', 'buildingcare-lite' ),
				'title_label' => __( 'Title', 'buildingcare-lite' ),
				'nonce'       => array( 'bcl_save_notice', 'bcl_notice_nonce' ),
				'columns'     => array(
					'post_title'     => __( 'Title', 'buildingcare-lite' ),
					'bc_notice_body' => __( 'Message', 'buildingcare-lite' ),
					'bc_pinned'      => __( 'Pinned', 'buildingcare-lite' ),
					'bc_expires_on'  => __( 'Expires', 'buildingcare-lite' ),
				),
				'fields'      => array(
					array( 'key' => 'bc_notice_body', 'label' => __( 'Message', 'buildingcare-lite' ), 'type' => 'textarea' ),
					array( 'key' => 'bc_pinned', 'label' => __( 'Pin to top', 'buildingcare-lite' ), 'type' => 'select', 'options' => 'yes_no', 'default' => 'no' ),
					array( 'key' => 'bc_expires_on', 'label' => __( 'Expires On (optional)', 'buildingcare-lite' ), 'type' => 'date' ),
				),
			),
		);
	}

	/**
	 * Render an entity tab: list or add/edit form depending on the request.
	 */
	public function render_entity_tab( string $tab ): void {
		$entities = $this->get_entities();
		if ( ! isset( $entities[ $tab ] ) ) {
			echo '<p>' . esc_html__( 'Unknown section.', 'buildingcare-lite' ) . '</p>';
			return;
		}

		$entity = $entities[ $tab ];
		if ( ! bcl_current_user_can( $entity['cap'] ) ) {
			echo '<p>' . esc_html__( 'Permission denied.', 'buildingcare-lite' ) . '</p>';
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';

		if ( 'new' === $action || 'edit' === $action ) {
			$post_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
			$this->render_form( $tab, $entity, $post_id );
			return;
		}

		$this->render_list( $tab, $entity );
	}

	/**
	 * Build a dashboard URL for a tab/action.
	 *
	 * @param array<string, mixed> $extra Extra query args.
	 */
	private function url( string $tab, array $extra = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => 'bcl-dashboard',
					'tab'  => $tab,
				),
				$extra
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Render the searchable, paginated list for an entity.
	 *
	 * @param array<string, mixed> $entity Entity config.
	 */
	private function render_list( string $tab, array $entity ): void {
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$filter_val = '';
		$query_args = array(
			'post_type'      => $entity['post_type'],
			'post_status'    => 'publish',
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
			's'              => $search,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( ! empty( $entity['filter'] ) ) {
			$filter_val = isset( $_GET['fval'] ) ? sanitize_key( wp_unslash( $_GET['fval'] ) ) : '';
			if ( '' !== $filter_val ) {
				$query_args['meta_query'] = array(
					array(
						'key'   => $entity['filter']['key'],
						'value' => $filter_val,
					),
				);
			}
		}

		$query = new \WP_Query( $query_args );

		if ( function_exists( __NAMESPACE__ . '\\bcl_prime_post_metas' ) && ! empty( $query->posts ) ) {
			bcl_prime_post_metas( wp_list_pluck( $query->posts, 'ID' ) );
		}
		?>
		<div class="bcl-tab-body">
			<div class="bcl-list-header">
				<div>
					<h2 class="bcl-section-title"><?php echo esc_html( $entity['plural'] ); ?></h2>
					<p class="bcl-subtitle">
						<?php
						printf(
							/* translators: %d: total count */
							esc_html__( '%d total', 'buildingcare-lite' ),
							(int) $query->found_posts
						);
						?>
					</p>
				</div>
				<a class="button button-primary bcl-add-new" href="<?php echo esc_url( $this->url( $tab, array( 'action' => 'new' ) ) ); ?>">
					<span class="dashicons dashicons-plus-alt2"></span>
					<?php
					/* translators: %s: entity singular */
					printf( esc_html__( 'Add %s', 'buildingcare-lite' ), esc_html( $entity['singular'] ) );
					?>
				</a>
			</div>

			<form method="get" class="bcl-list-search">
				<input type="hidden" name="page" value="bcl-dashboard">
				<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
				<p class="search-box">
					<?php if ( ! empty( $entity['filter'] ) ) : ?>
						<label class="screen-reader-text" for="bcl-list-filter"><?php echo esc_html( (string) $entity['filter']['label'] ); ?></label>
						<select name="fval" id="bcl-list-filter">
							<option value=""><?php echo esc_html( sprintf( /* translators: %s: filter label */ __( 'All %s', 'buildingcare-lite' ), strtolower( (string) $entity['filter']['label'] ) ) ); ?></option>
							<?php foreach ( $this->options_for( (string) $entity['filter']['options'] ) as $fkey => $flabel ) : ?>
								<option value="<?php echo esc_attr( (string) $fkey ); ?>" <?php selected( $filter_val, (string) $fkey ); ?>><?php echo esc_html( $flabel ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search…', 'buildingcare-lite' ); ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Search', 'buildingcare-lite' ); ?></button>
				</p>
			</form>

			<table class="widefat striped bcl-data-table">
				<thead>
					<tr>
						<?php foreach ( $entity['columns'] as $label ) : ?>
							<th><?php echo esc_html( $label ); ?></th>
						<?php endforeach; ?>
						<th class="bcl-col-actions"><?php esc_html_e( 'Actions', 'buildingcare-lite' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $query->posts ) ) : ?>
						<tr><td colspan="<?php echo esc_attr( (string) ( count( $entity['columns'] ) + 1 ) ); ?>"><?php esc_html_e( 'No records found.', 'buildingcare-lite' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $query->posts as $post ) : ?>
							<tr>
								<?php foreach ( array_keys( $entity['columns'] ) as $col ) : ?>
									<td><?php echo wp_kses_post( $this->column_value( $col, (int) $post->ID, $entity, $tab ) ); ?></td>
								<?php endforeach; ?>
								<td class="bcl-col-actions">
									<a class="button button-small" href="<?php echo esc_url( $this->url( $tab, array( 'action' => 'edit', 'id' => $post->ID ) ) ); ?>"><?php esc_html_e( 'Edit', 'buildingcare-lite' ); ?></a>
									<a class="button button-small button-link-delete bcl-delete-entity" href="<?php echo esc_url( $this->delete_url( $tab, (int) $post->ID ) ); ?>"><?php esc_html_e( 'Delete', 'buildingcare-lite' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php $this->render_pagination( $tab, $query, $paged, $search, $filter_val ); ?>
		</div>
		<?php
	}

	/**
	 * Pagination links.
	 */
	private function render_pagination( string $tab, \WP_Query $query, int $paged, string $search, string $filter_val = '' ): void {
		if ( $query->max_num_pages <= 1 ) {
			return;
		}

		$base_args = array( 's' => $search );
		if ( '' !== $filter_val ) {
			$base_args['fval'] = $filter_val;
		}

		$links = paginate_links(
			array(
				'base'      => $this->url( $tab, $base_args ) . '%_%',
				'format'    => '&paged=%#%',
				'current'   => $paged,
				'total'     => (int) $query->max_num_pages,
				'prev_text' => '‹',
				'next_text' => '›',
			)
		);

		if ( $links ) {
			echo '<div class="bcl-pagination tablenav-pages">' . wp_kses_post( $links ) . '</div>';
		}
	}

	/**
	 * Resolve a display value for a list column.
	 *
	 * @param array<string, mixed> $entity Entity config.
	 */
	private function column_value( string $col, int $post_id, array $entity, string $tab ): string {
		switch ( $col ) {
			case 'post_title':
				return sprintf(
					'<strong><a href="%s">%s</a></strong>',
					esc_url( $this->url( $tab, array( 'action' => 'edit', 'id' => $post_id ) ) ),
					esc_html( get_the_title( $post_id ) ?: '—' )
				);
			case 'bc_building_id':
			case 'bc_assigned_flat_id':
			case 'bc_flat_id':
			case 'bc_resident_id':
				$linked = (int) bcl_get_meta_float( $post_id, $col );
				return $linked ? esc_html( get_the_title( $linked ) ) : '—';
			case 'bc_ticket_status':
				$status = bcl_get_meta_string( $post_id, 'bc_ticket_status' );
				$label  = bcl_ticket_statuses()[ $status ] ?? ( $status ?: '—' );
				return '<span class="bcl-status bcl-ticket-status-' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
			case 'bc_ticket_priority':
				$priority = bcl_get_meta_string( $post_id, 'bc_ticket_priority' );
				return esc_html( bcl_ticket_priorities()[ $priority ] ?? ( $priority ?: '—' ) );
			case 'bc_ticket_category':
				$category = bcl_get_meta_string( $post_id, 'bc_ticket_category' );
				return esc_html( bcl_ticket_categories()[ $category ] ?? ( $category ?: '—' ) );
			case 'bc_pinned':
				return bcl_get_meta_string( $post_id, 'bc_pinned' ) === 'yes'
					? esc_html__( 'Yes', 'buildingcare-lite' )
					: esc_html__( 'No', 'buildingcare-lite' );
			case 'bc_expires_on':
				$expires = bcl_get_meta_string( $post_id, 'bc_expires_on' );
				return $expires ? esc_html( $expires ) : '—';
			case 'bc_notice_body':
				$body = bcl_get_meta_string( $post_id, 'bc_notice_body' );
				return $body ? esc_html( wp_trim_words( $body, 12 ) ) : '—';
			case 'bc_status':
				$status = bcl_get_meta_string( $post_id, 'bc_status' );
				return esc_html( bcl_building_statuses()[ $status ] ?? ( $status ?: '—' ) );
			case 'bc_occupancy_status':
				$status = bcl_get_meta_string( $post_id, 'bc_occupancy_status' );
				return esc_html( bcl_occupancy_statuses()[ $status ] ?? ( $status ?: '—' ) );
			case 'bc_monthly_service_charge':
			case 'bc_amount':
			case 'bc_monthly_amount':
				return esc_html( bcl_format_amount( bcl_get_meta_float( $post_id, $col ) ) );
			case 'bc_is_paid':
				return bcl_get_meta_string( $post_id, 'bc_is_paid' ) === 'yes'
					? esc_html__( 'Yes', 'buildingcare-lite' )
					: esc_html__( 'No', 'buildingcare-lite' );
			case 'bc_active_status':
				return bcl_get_meta_string( $post_id, 'bc_active_status' ) === 'no'
					? esc_html__( 'No', 'buildingcare-lite' )
					: esc_html__( 'Yes', 'buildingcare-lite' );
			case 'category':
				$terms = wp_get_post_terms( $post_id, $entity['taxonomy'] ?? 'bc_expense_category', array( 'fields' => 'names' ) );
				return ! is_wp_error( $terms ) && ! empty( $terms ) ? esc_html( implode( ', ', $terms ) ) : '—';
			default:
				$value = bcl_get_meta_string( $post_id, $col );
				return $value ? esc_html( $value ) : '—';
		}
	}

	/**
	 * Render the add/edit form for an entity.
	 *
	 * @param array<string, mixed> $entity Entity config.
	 */
	private function render_form( string $tab, array $entity, int $post_id ): void {
		$is_edit = $post_id > 0 && get_post_type( $post_id ) === $entity['post_type'];
		$title   = $is_edit ? get_the_title( $post_id ) : '';
		?>
		<div class="bcl-tab-body">
			<div class="bcl-list-header">
				<h2 class="bcl-section-title">
					<?php
					echo $is_edit
						/* translators: %s: entity singular */
						? esc_html( sprintf( __( 'Edit %s', 'buildingcare-lite' ), $entity['singular'] ) )
						/* translators: %s: entity singular */
						: esc_html( sprintf( __( 'Add %s', 'buildingcare-lite' ), $entity['singular'] ) );
					?>
				</h2>
				<a class="button" href="<?php echo esc_url( $this->url( $tab ) ); ?>">&larr; <?php esc_html_e( 'Back to list', 'buildingcare-lite' ); ?></a>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bcl-entity-form bcl-panel">
				<input type="hidden" name="action" value="bcl_save_entity">
				<input type="hidden" name="entity" value="<?php echo esc_attr( $tab ); ?>">
				<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post_id ); ?>">
				<?php wp_nonce_field( 'bcl_dashboard_save', 'bcl_dashboard_nonce' ); ?>
				<?php wp_nonce_field( $entity['nonce'][0], $entity['nonce'][1] ); ?>

				<div class="bcl-form-grid">
					<p class="bcl-form-row bcl-form-row--full">
						<label for="post_title"><strong><?php echo esc_html( $entity['title_label'] ); ?></strong></label>
						<input type="text" class="widefat" id="post_title" name="post_title" value="<?php echo esc_attr( $title ); ?>" required>
					</p>

					<?php foreach ( $entity['fields'] as $field ) : ?>
						<?php $this->render_field( $field, $post_id ); ?>
					<?php endforeach; ?>
				</div>

				<div class="bcl-form-actions">
					<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save', 'buildingcare-lite' ); ?></button>
					<a class="button button-hero" href="<?php echo esc_url( $this->url( $tab ) ); ?>"><?php esc_html_e( 'Cancel', 'buildingcare-lite' ); ?></a>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a single form field.
	 *
	 * @param array<string, mixed> $field   Field config.
	 */
	private function render_field( array $field, int $post_id ): void {
		$key   = (string) $field['key'];
		$label = (string) $field['label'];
		$type  = (string) $field['type'];
		$value = $post_id ? bcl_get_meta_string( $post_id, $key ) : '';

		if ( '' === $value && isset( $field['default'] ) ) {
			$value = 'today' === $field['default'] ? gmdate( 'Y-m-d' ) : (string) $field['default'];
		}

		echo '<p class="bcl-form-row' . ( in_array( $type, array( 'textarea' ), true ) ? ' bcl-form-row--full' : '' ) . '">';
		echo '<label for="' . esc_attr( $key ) . '"><strong>' . esc_html( $label ) . '</strong></label>';

		switch ( $type ) {
			case 'textarea':
				echo '<textarea class="widefat" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" rows="3">' . esc_textarea( $value ) . '</textarea>';
				break;
			case 'select':
				$this->render_select_options( $key, $this->options_for( (string) $field['options'] ), $value );
				break;
			case 'taxonomy':
				$this->render_taxonomy_select( $key, $post_id );
				break;
			case 'attachment':
				$attachment_id = (int) ( $post_id ? bcl_get_meta_float( $post_id, $key ) : 0 );
				echo '<span class="bcl-attachment-field">';
				echo '<input type="hidden" name="' . esc_attr( $key ) . '" id="bc_attachment_id" value="' . esc_attr( (string) $attachment_id ) . '">';
				echo '<button type="button" class="button bcl-upload-attachment">' . esc_html__( 'Select File', 'buildingcare-lite' ) . '</button> ';
				echo '<span class="bcl-attachment-preview">' . ( $attachment_id ? esc_html( get_the_title( $attachment_id ) ) : '' ) . '</span>';
				echo '</span>';
				break;
			case 'number':
				echo '<input type="number" step="0.01" class="widefat" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
				break;
			case 'number_int':
				echo '<input type="number" step="1" class="widefat" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
				break;
			case 'date':
				echo '<input type="date" class="widefat" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
				break;
			case 'email':
				echo '<input type="email" class="widefat" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
				break;
			default:
				echo '<input type="text" class="widefat" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
				break;
		}

		echo '</p>';
	}

	/**
	 * Resolve select options by name.
	 *
	 * @return array<int|string, string>
	 */
	private function options_for( string $name ): array {
		switch ( $name ) {
			case 'building_statuses':
				return bcl_building_statuses();
			case 'occupancy_statuses':
				return bcl_occupancy_statuses();
			case 'buildings':
				return bcl_get_buildings_options();
			case 'flats':
				return bcl_get_flats_options();
			case 'residents':
				return bcl_get_residents_options();
			case 'ticket_statuses':
				return bcl_ticket_statuses();
			case 'ticket_categories':
				return bcl_ticket_categories();
			case 'ticket_priorities':
				return bcl_ticket_priorities();
			case 'yes_no':
				return array(
					'yes' => __( 'Yes', 'buildingcare-lite' ),
					'no'  => __( 'No', 'buildingcare-lite' ),
				);
			default:
				return array();
		}
	}

	/**
	 * Render a select element.
	 *
	 * @param array<int|string, string> $options Options.
	 */
	private function render_select_options( string $name, array $options, string $selected ): void {
		echo '<select class="widefat" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		echo '<option value="">' . esc_html__( '— Select —', 'buildingcare-lite' ) . '</option>';
		foreach ( $options as $value => $text ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( (string) $value ),
				selected( $selected, (string) $value, false ),
				esc_html( $text )
			);
		}
		echo '</select>';
	}

	/**
	 * Render a taxonomy term select.
	 */
	private function render_taxonomy_select( string $name, int $post_id ): void {
		$terms = get_terms(
			array(
				'taxonomy'   => 'bc_expense_category',
				'hide_empty' => false,
			)
		);

		$selected = 0;
		if ( $post_id ) {
			$assigned = wp_get_post_terms( $post_id, 'bc_expense_category', array( 'fields' => 'ids' ) );
			$selected = ! is_wp_error( $assigned ) && ! empty( $assigned ) ? (int) $assigned[0] : 0;
		}

		echo '<select class="widefat" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		echo '<option value="0">' . esc_html__( '— Select —', 'buildingcare-lite' ) . '</option>';
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				printf(
					'<option value="%d" %s>%s</option>',
					(int) $term->term_id,
					selected( $selected, (int) $term->term_id, false ),
					esc_html( $term->name )
				);
			}
		}
		echo '</select>';
	}

	/**
	 * Delete URL with nonce.
	 */
	private function delete_url( string $tab, int $post_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'bcl_delete_entity',
					'entity' => $tab,
					'post_id' => $post_id,
				),
				admin_url( 'admin-post.php' )
			),
			'bcl_delete_entity_' . $post_id
		);
	}

	/**
	 * Persist an entity create/update. Meta is saved by the existing meta-box
	 * handlers (their nonces are present in the form); we only manage the post
	 * object title and taxonomy terms here.
	 */
	public function save_entity(): void {
		check_admin_referer( 'bcl_dashboard_save', 'bcl_dashboard_nonce' );

		$tab      = isset( $_POST['entity'] ) ? sanitize_key( wp_unslash( $_POST['entity'] ) ) : '';
		$entities = $this->get_entities();
		if ( ! isset( $entities[ $tab ] ) ) {
			wp_die( esc_html__( 'Unknown section.', 'buildingcare-lite' ) );
		}

		$entity = $entities[ $tab ];
		if ( ! bcl_current_user_can( $entity['cap'] ) ) {
			wp_die( esc_html__( 'Permission denied.', 'buildingcare-lite' ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$title   = sanitize_text_field( wp_unslash( $_POST['post_title'] ?? '' ) );
		if ( '' === $title ) {
			$title = $entity['singular'];
		}

		$postarr = array(
			'post_type'   => $entity['post_type'],
			'post_status' => 'publish',
			'post_title'  => $title,
		);

		if ( $post_id > 0 && get_post_type( $post_id ) === $entity['post_type'] ) {
			$postarr['ID'] = $post_id;
			$result        = wp_update_post( $postarr, true );
		} else {
			$result = wp_insert_post( $postarr, true );
		}

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		$saved_id = (int) $result;

		// Taxonomy (expense category) is not handled by the meta-box save routines.
		if ( ! empty( $entity['taxonomy'] ) ) {
			$term_id = isset( $_POST['bc_expense_category'] ) ? absint( $_POST['bc_expense_category'] ) : 0;
			wp_set_post_terms( $saved_id, $term_id ? array( $term_id ) : array(), $entity['taxonomy'] );
		}

		wp_safe_redirect( $this->url( $tab, array( 'saved' => 1 ) ) );
		exit;
	}

	/**
	 * Delete (trash) an entity.
	 */
	public function delete_entity(): void {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		check_admin_referer( 'bcl_delete_entity_' . $post_id );

		$tab      = isset( $_GET['entity'] ) ? sanitize_key( wp_unslash( $_GET['entity'] ) ) : '';
		$entities = $this->get_entities();
		if ( ! isset( $entities[ $tab ] ) || $post_id <= 0 ) {
			wp_die( esc_html__( 'Invalid request.', 'buildingcare-lite' ) );
		}

		$entity = $entities[ $tab ];
		if ( ! bcl_current_user_can( $entity['cap'] ) || get_post_type( $post_id ) !== $entity['post_type'] ) {
			wp_die( esc_html__( 'Permission denied.', 'buildingcare-lite' ) );
		}

		wp_trash_post( $post_id );

		bcl_audit_log(
			'entity_deleted',
			sprintf(
				/* translators: 1: post type, 2: id */
				__( '%1$s #%2$d moved to trash', 'buildingcare-lite' ),
				$entity['post_type'],
				$post_id
			)
		);

		wp_safe_redirect( $this->url( $tab, array( 'deleted' => 1 ) ) );
		exit;
	}
}
