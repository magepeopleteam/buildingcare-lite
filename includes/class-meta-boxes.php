<?php
/**
 * Meta boxes for all post types.
 *
 * @package BuildingCareLite
 */

declare(strict_types=1);

namespace BuildingCareLite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and saves meta boxes.
 */
class Meta_Boxes {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_meta_boxes' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_media' ) );
	}

	/**
	 * Register meta boxes per post type.
	 */
	public function register_meta_boxes(): void {
		add_meta_box(
			'bcl_building_details',
			__( 'Building Details', 'buildingcare-lite' ),
			array( $this, 'render_building_box' ),
			'bc_building',
			'normal',
			'high'
		);

		add_meta_box(
			'bcl_flat_details',
			__( 'Flat Details', 'buildingcare-lite' ),
			array( $this, 'render_flat_box' ),
			'bc_flat',
			'normal',
			'high'
		);

		add_meta_box(
			'bcl_resident_details',
			__( 'Resident Details', 'buildingcare-lite' ),
			array( $this, 'render_resident_box' ),
			'bc_resident',
			'normal',
			'high'
		);

		add_meta_box(
			'bcl_bill_details',
			__( 'Bill Details', 'buildingcare-lite' ),
			array( $this, 'render_bill_box' ),
			'bc_bill',
			'normal',
			'high'
		);

		add_meta_box(
			'bcl_expense_details',
			__( 'Expense Details', 'buildingcare-lite' ),
			array( $this, 'render_expense_box' ),
			'bc_expense',
			'normal',
			'high'
		);

		add_meta_box(
			'bcl_recurring_details',
			__( 'Recurring Expense Details', 'buildingcare-lite' ),
			array( $this, 'render_recurring_box' ),
			'bc_recurring_expense',
			'normal',
			'high'
		);
	}

	/**
	 * Enqueue media uploader on expense screens.
	 */
	public function enqueue_media( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( $screen && in_array( $screen->post_type, array( 'bc_expense', 'bc_bill' ), true ) ) {
			wp_enqueue_media();
		}
	}

	/**
	 * Render building meta box.
	 *
	 * @param \WP_Post $post Post object.
	 */
	public function render_building_box( \WP_Post $post ): void {
		wp_nonce_field( 'bcl_save_building', 'bcl_building_nonce' );
		$this->render_field( 'bc_address', __( 'Address', 'buildingcare-lite' ), bcl_get_meta_string( $post->ID, 'bc_address' ), 'textarea' );
		$this->render_field( 'bc_total_floors', __( 'Total Floors', 'buildingcare-lite' ), (string) bcl_get_meta_float( $post->ID, 'bc_total_floors' ), 'number' );
		$this->render_select( 'bc_status', __( 'Status', 'buildingcare-lite' ), bcl_building_statuses(), bcl_get_meta_string( $post->ID, 'bc_status' ) ?: 'active' );
	}

	/**
	 * Render flat meta box.
	 */
	public function render_flat_box( \WP_Post $post ): void {
		wp_nonce_field( 'bcl_save_flat', 'bcl_flat_nonce' );
		$this->render_select( 'bc_building_id', __( 'Building', 'buildingcare-lite' ), bcl_get_buildings_options(), (string) (int) bcl_get_meta_float( $post->ID, 'bc_building_id' ) );
		$this->render_field( 'bc_flat_number', __( 'Flat Number', 'buildingcare-lite' ), bcl_get_meta_string( $post->ID, 'bc_flat_number' ) );
		$this->render_field( 'bc_floor_number', __( 'Floor Number', 'buildingcare-lite' ), (string) bcl_get_meta_float( $post->ID, 'bc_floor_number' ), 'number' );
		$this->render_field( 'bc_flat_size', __( 'Flat Size (sq ft)', 'buildingcare-lite' ), (string) bcl_get_meta_float( $post->ID, 'bc_flat_size' ), 'number' );
		$this->render_field( 'bc_monthly_service_charge', __( 'Monthly Service Charge', 'buildingcare-lite' ), (string) bcl_get_meta_float( $post->ID, 'bc_monthly_service_charge' ), 'number', '0.01' );
		$this->render_select( 'bc_occupancy_status', __( 'Occupancy Status', 'buildingcare-lite' ), bcl_occupancy_statuses(), bcl_get_meta_string( $post->ID, 'bc_occupancy_status' ) ?: 'vacant' );
	}

	/**
	 * Render resident meta box.
	 */
	public function render_resident_box( \WP_Post $post ): void {
		wp_nonce_field( 'bcl_save_resident', 'bcl_resident_nonce' );
		$this->render_field( 'bc_mobile', __( 'Mobile Number', 'buildingcare-lite' ), bcl_get_meta_string( $post->ID, 'bc_mobile' ) );
		$this->render_field( 'bc_email', __( 'Email Address', 'buildingcare-lite' ), bcl_get_meta_string( $post->ID, 'bc_email' ), 'email' );
		$this->render_field( 'bc_emergency_contact', __( 'Emergency Contact', 'buildingcare-lite' ), bcl_get_meta_string( $post->ID, 'bc_emergency_contact' ) );
		$this->render_field( 'bc_move_in_date', __( 'Move-in Date', 'buildingcare-lite' ), bcl_get_meta_string( $post->ID, 'bc_move_in_date' ), 'date' );
		$this->render_field( 'bc_move_out_date', __( 'Move-out Date', 'buildingcare-lite' ), bcl_get_meta_string( $post->ID, 'bc_move_out_date' ), 'date' );
		$this->render_select( 'bc_assigned_flat_id', __( 'Assigned Flat', 'buildingcare-lite' ), bcl_get_flats_options(), (string) (int) bcl_get_meta_float( $post->ID, 'bc_assigned_flat_id' ) );
	}

	/**
	 * Render bill meta box.
	 */
	public function render_bill_box( \WP_Post $post ): void {
		wp_nonce_field( 'bcl_save_bill', 'bcl_bill_nonce' );
		$this->render_select( 'bc_building_id', __( 'Building', 'buildingcare-lite' ), bcl_get_buildings_options(), (string) (int) bcl_get_meta_float( $post->ID, 'bc_building_id' ) );
		$this->render_select( 'bc_flat_id', __( 'Flat', 'buildingcare-lite' ), bcl_get_flats_options(), (string) (int) bcl_get_meta_float( $post->ID, 'bc_flat_id' ) );
		$this->render_select( 'bc_resident_id', __( 'Resident', 'buildingcare-lite' ), bcl_get_residents_options(), (string) (int) bcl_get_meta_float( $post->ID, 'bc_resident_id' ) );
		$this->render_field( 'bc_billing_month', __( 'Billing Month', 'buildingcare-lite' ), bcl_get_meta_string( $post->ID, 'bc_billing_month' ) ?: bcl_current_billing_month(), 'month' );
		$this->render_select( 'bc_occupancy_status', __( 'Occupancy', 'buildingcare-lite' ), bcl_occupancy_statuses(), bcl_get_meta_string( $post->ID, 'bc_occupancy_status' ) ?: 'occupied' );
		$this->render_field( 'bc_service_charge_amount', __( 'Service Charge', 'buildingcare-lite' ), (string) bcl_get_meta_float( $post->ID, 'bc_service_charge_amount' ), 'number', '0.01' );
		$this->render_field( 'bc_previous_due_amount', __( 'Previous Due', 'buildingcare-lite' ), (string) bcl_get_meta_float( $post->ID, 'bc_previous_due_amount' ), 'number', '0.01' );
		$this->render_field( 'bc_late_fee_amount', __( 'Late Fee', 'buildingcare-lite' ), (string) bcl_get_meta_float( $post->ID, 'bc_late_fee_amount' ), 'number', '0.01' );
		$this->render_field( 'bc_total_payable_amount', __( 'Total Payable', 'buildingcare-lite' ), (string) bcl_get_meta_float( $post->ID, 'bc_total_payable_amount' ), 'number', '0.01' );
		$this->render_field( 'bc_amount_paid', __( 'Amount Paid', 'buildingcare-lite' ), (string) bcl_get_meta_float( $post->ID, 'bc_amount_paid' ), 'number', '0.01' );
		$this->render_field( 'bc_remaining_due', __( 'Remaining Due', 'buildingcare-lite' ), (string) bcl_get_meta_float( $post->ID, 'bc_remaining_due' ), 'number', '0.01' );
		$this->render_field( 'bc_payment_date', __( 'Payment Date', 'buildingcare-lite' ), bcl_get_meta_string( $post->ID, 'bc_payment_date' ), 'date' );
		$this->render_select( 'bc_payment_method', __( 'Payment Method', 'buildingcare-lite' ), bcl_payment_methods(), bcl_get_meta_string( $post->ID, 'bc_payment_method' ) );
		$this->render_select( 'bc_payment_status', __( 'Payment Status', 'buildingcare-lite' ), bcl_payment_statuses(), bcl_get_meta_string( $post->ID, 'bc_payment_status' ) ?: 'unpaid' );

		if ( 'yes' === bcl_get_meta_string( $post->ID, 'bc_carried_forward' ) ) {
			echo '<p><em>' . esc_html__( 'This bill was carried forward into a later bill.', 'buildingcare-lite' ) . '</em></p>';
		}

		$history = class_exists( __NAMESPACE__ . '\\Payments' ) ? Payments::for_bill( $post->ID ) : array();
		if ( ! empty( $history ) ) {
			$methods = bcl_payment_methods();
			echo '<p><strong>' . esc_html__( 'Payment History', 'buildingcare-lite' ) . '</strong></p>';
			echo '<ul class="bcl-payment-history">';
			foreach ( $history as $entry ) {
				printf(
					'<li>%1$s — %2$s <span>(%3$s)</span></li>',
					esc_html( (string) $entry['date'] ),
					esc_html( bcl_format_amount( (float) $entry['amount'] ) ),
					esc_html( $methods[ $entry['method'] ] ?? (string) $entry['method'] )
				);
			}
			echo '</ul>';
		}
	}

	/**
	 * Render expense meta box.
	 */
	public function render_expense_box( \WP_Post $post ): void {
		wp_nonce_field( 'bcl_save_expense', 'bcl_expense_nonce' );
		$attachment_id = (int) bcl_get_meta_float( $post->ID, 'bc_attachment_id' );
		$this->render_field( 'bc_expense_date', __( 'Expense Date', 'buildingcare-lite' ), bcl_get_meta_string( $post->ID, 'bc_expense_date' ) ?: gmdate( 'Y-m-d' ), 'date' );
		$this->render_field( 'bc_description', __( 'Description', 'buildingcare-lite' ), bcl_get_meta_string( $post->ID, 'bc_description' ), 'textarea' );
		$this->render_field( 'bc_amount', __( 'Amount', 'buildingcare-lite' ), (string) bcl_get_meta_float( $post->ID, 'bc_amount' ), 'number', '0.01' );
		$this->render_select( 'bc_is_paid', __( 'Marked as Paid', 'buildingcare-lite' ), array( 'no' => __( 'No', 'buildingcare-lite' ), 'yes' => __( 'Yes', 'buildingcare-lite' ) ), bcl_get_meta_string( $post->ID, 'bc_is_paid' ) ?: 'no' );
		?>
		<p>
			<label for="bc_attachment_id"><strong><?php esc_html_e( 'Attachment', 'buildingcare-lite' ); ?></strong></label><br>
			<input type="hidden" name="bc_attachment_id" id="bc_attachment_id" value="<?php echo esc_attr( (string) $attachment_id ); ?>">
			<button type="button" class="button bcl-upload-attachment"><?php esc_html_e( 'Select File', 'buildingcare-lite' ); ?></button>
			<span class="bcl-attachment-preview">
				<?php
				if ( $attachment_id ) {
					echo esc_html( get_the_title( $attachment_id ) );
				}
				?>
			</span>
		</p>
		<?php
	}

	/**
	 * Render recurring expense meta box.
	 */
	public function render_recurring_box( \WP_Post $post ): void {
		wp_nonce_field( 'bcl_save_recurring', 'bcl_recurring_nonce' );
		$this->render_field( 'bc_monthly_amount', __( 'Monthly Amount', 'buildingcare-lite' ), (string) bcl_get_meta_float( $post->ID, 'bc_monthly_amount' ), 'number', '0.01' );
		$this->render_select( 'bc_active_status', __( 'Active', 'buildingcare-lite' ), array( 'yes' => __( 'Yes', 'buildingcare-lite' ), 'no' => __( 'No', 'buildingcare-lite' ) ), bcl_get_meta_string( $post->ID, 'bc_active_status' ) ?: 'yes' );
	}

	/**
	 * Save meta box data.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function save_meta_boxes( int $post_id, \WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		switch ( $post->post_type ) {
			case 'bc_building':
				$this->save_building( $post_id );
				break;
			case 'bc_flat':
				$this->save_flat( $post_id );
				break;
			case 'bc_resident':
				$this->save_resident( $post_id );
				break;
			case 'bc_bill':
				$this->save_bill( $post_id );
				break;
			case 'bc_expense':
				$this->save_expense( $post_id );
				break;
			case 'bc_recurring_expense':
				$this->save_recurring( $post_id );
				break;
		}
	}

	/**
	 * Save building meta.
	 */
	private function save_building( int $post_id ): void {
		if ( ! isset( $_POST['bcl_building_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bcl_building_nonce'] ) ), 'bcl_save_building' ) ) {
			return;
		}
		if ( ! current_user_can( 'bc_manage_buildings', $post_id ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		update_post_meta( $post_id, 'bc_address', sanitize_textarea_field( wp_unslash( $_POST['bc_address'] ?? '' ) ) );
		update_post_meta( $post_id, 'bc_total_floors', absint( $_POST['bc_total_floors'] ?? 0 ) );
		update_post_meta( $post_id, 'bc_status', sanitize_key( $_POST['bc_status'] ?? 'active' ) );
		bcl_clear_dashboard_cache();
		if ( function_exists( __NAMESPACE__ . '\\bcl_invalidate_options_caches' ) ) {
			bcl_invalidate_options_caches();
		}
	}

	/**
	 * Save flat meta.
	 */
	private function save_flat( int $post_id ): void {
		if ( ! isset( $_POST['bcl_flat_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bcl_flat_nonce'] ) ), 'bcl_save_flat' ) ) {
			return;
		}
		if ( ! bcl_current_user_can( 'bc_manage_flats' ) && ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, 'bc_building_id', absint( $_POST['bc_building_id'] ?? 0 ) );
		update_post_meta( $post_id, 'bc_flat_number', sanitize_text_field( wp_unslash( $_POST['bc_flat_number'] ?? '' ) ) );
		update_post_meta( $post_id, 'bc_floor_number', absint( $_POST['bc_floor_number'] ?? 0 ) );
		update_post_meta( $post_id, 'bc_flat_size', (float) ( $_POST['bc_flat_size'] ?? 0 ) );
		update_post_meta( $post_id, 'bc_monthly_service_charge', round( (float) ( $_POST['bc_monthly_service_charge'] ?? 0 ), 2 ) );
		update_post_meta( $post_id, 'bc_occupancy_status', sanitize_key( $_POST['bc_occupancy_status'] ?? 'vacant' ) );
		bcl_clear_dashboard_cache();
		if ( function_exists( __NAMESPACE__ . '\\bcl_invalidate_options_caches' ) ) {
			bcl_invalidate_options_caches();
		}
	}

	/**
	 * Save resident meta.
	 */
	private function save_resident( int $post_id ): void {
		if ( ! isset( $_POST['bcl_resident_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bcl_resident_nonce'] ) ), 'bcl_save_resident' ) ) {
			return;
		}
		if ( ! bcl_current_user_can( 'bc_manage_residents' ) ) {
			return;
		}

		update_post_meta( $post_id, 'bc_mobile', sanitize_text_field( wp_unslash( $_POST['bc_mobile'] ?? '' ) ) );
		update_post_meta( $post_id, 'bc_email', sanitize_email( wp_unslash( $_POST['bc_email'] ?? '' ) ) );
		update_post_meta( $post_id, 'bc_emergency_contact', sanitize_text_field( wp_unslash( $_POST['bc_emergency_contact'] ?? '' ) ) );
		update_post_meta( $post_id, 'bc_move_in_date', sanitize_text_field( wp_unslash( $_POST['bc_move_in_date'] ?? '' ) ) );
		update_post_meta( $post_id, 'bc_move_out_date', sanitize_text_field( wp_unslash( $_POST['bc_move_out_date'] ?? '' ) ) );
		update_post_meta( $post_id, 'bc_assigned_flat_id', absint( $_POST['bc_assigned_flat_id'] ?? 0 ) );

		$flat_id = absint( $_POST['bc_assigned_flat_id'] ?? 0 );
		if ( $flat_id ) {
			update_post_meta( $flat_id, 'bc_occupancy_status', 'occupied' );
		}
	}

	/**
	 * Save bill meta and recalculate totals.
	 */
	private function save_bill( int $post_id ): void {
		if ( ! isset( $_POST['bcl_bill_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bcl_bill_nonce'] ) ), 'bcl_save_bill' ) ) {
			return;
		}
		if ( ! bcl_current_user_can( 'bc_manage_payments' ) && ! bcl_current_user_can( 'bc_generate_bills' ) ) {
			return;
		}

		$service_charge = round( (float) ( $_POST['bc_service_charge_amount'] ?? 0 ), 2 );
		$previous_due   = round( (float) ( $_POST['bc_previous_due_amount'] ?? 0 ), 2 );
		$late_fee       = round( (float) ( $_POST['bc_late_fee_amount'] ?? 0 ), 2 );
		$amount_paid    = round( (float) ( $_POST['bc_amount_paid'] ?? 0 ), 2 );
		$total_payable  = round( $service_charge + $previous_due + $late_fee, 2 );

		$state          = bcl_compute_payment_state( $total_payable, $amount_paid );
		$amount_paid    = $state['amount_paid'];
		$remaining_due  = $state['remaining_due'];
		$status         = $state['payment_status'];

		update_post_meta( $post_id, 'bc_building_id', absint( $_POST['bc_building_id'] ?? 0 ) );
		update_post_meta( $post_id, 'bc_flat_id', absint( $_POST['bc_flat_id'] ?? 0 ) );
		update_post_meta( $post_id, 'bc_resident_id', absint( $_POST['bc_resident_id'] ?? 0 ) );
		update_post_meta( $post_id, 'bc_billing_month', sanitize_text_field( wp_unslash( $_POST['bc_billing_month'] ?? '' ) ) );
		update_post_meta( $post_id, 'bc_occupancy_status', sanitize_key( $_POST['bc_occupancy_status'] ?? 'occupied' ) );
		update_post_meta( $post_id, 'bc_service_charge_amount', $service_charge );
		update_post_meta( $post_id, 'bc_previous_due_amount', $previous_due );
		update_post_meta( $post_id, 'bc_late_fee_amount', $late_fee );
		update_post_meta( $post_id, 'bc_total_payable_amount', $total_payable );
		update_post_meta( $post_id, 'bc_amount_paid', $amount_paid );
		update_post_meta( $post_id, 'bc_remaining_due', $remaining_due );
		update_post_meta( $post_id, 'bc_payment_date', sanitize_text_field( wp_unslash( $_POST['bc_payment_date'] ?? '' ) ) );
		update_post_meta( $post_id, 'bc_payment_method', sanitize_key( $_POST['bc_payment_method'] ?? '' ) );
		update_post_meta( $post_id, 'bc_payment_status', $status );
		bcl_clear_dashboard_cache();
	}

	/**
	 * Save expense meta.
	 */
	private function save_expense( int $post_id ): void {
		if ( ! isset( $_POST['bcl_expense_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bcl_expense_nonce'] ) ), 'bcl_save_expense' ) ) {
			return;
		}
		if ( ! bcl_current_user_can( 'bc_manage_expenses' ) ) {
			return;
		}

		update_post_meta( $post_id, 'bc_expense_date', sanitize_text_field( wp_unslash( $_POST['bc_expense_date'] ?? '' ) ) );
		update_post_meta( $post_id, 'bc_description', sanitize_textarea_field( wp_unslash( $_POST['bc_description'] ?? '' ) ) );
		update_post_meta( $post_id, 'bc_amount', round( (float) ( $_POST['bc_amount'] ?? 0 ), 2 ) );
		update_post_meta( $post_id, 'bc_attachment_id', absint( $_POST['bc_attachment_id'] ?? 0 ) );
		update_post_meta( $post_id, 'bc_is_paid', sanitize_key( $_POST['bc_is_paid'] ?? 'no' ) );
		bcl_clear_dashboard_cache();
	}

	/**
	 * Save recurring expense meta.
	 */
	private function save_recurring( int $post_id ): void {
		if ( ! isset( $_POST['bcl_recurring_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bcl_recurring_nonce'] ) ), 'bcl_save_recurring' ) ) {
			return;
		}
		if ( ! bcl_current_user_can( 'bc_manage_expenses' ) ) {
			return;
		}

		update_post_meta( $post_id, 'bc_monthly_amount', round( (float) ( $_POST['bc_monthly_amount'] ?? 0 ), 2 ) );
		update_post_meta( $post_id, 'bc_active_status', sanitize_key( $_POST['bc_active_status'] ?? 'yes' ) );
	}

	/**
	 * Render a text/number/textarea field.
	 */
	private function render_field( string $name, string $label, string $value, string $type = 'text', string $step = '' ): void {
		?>
		<p>
			<label for="<?php echo esc_attr( $name ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label><br>
			<?php if ( 'textarea' === $type ) : ?>
				<textarea class="widefat" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="3"><?php echo esc_textarea( $value ); ?></textarea>
			<?php else : ?>
				<input
					class="widefat"
					type="<?php echo esc_attr( $type ); ?>"
					id="<?php echo esc_attr( $name ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
					<?php echo $step ? 'step="' . esc_attr( $step ) . '"' : ''; ?>
				>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Render a select field.
	 *
	 * @param array<int|string, string> $options Options.
	 */
	private function render_select( string $name, string $label, array $options, string $selected ): void {
		?>
		<p>
			<label for="<?php echo esc_attr( $name ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label><br>
			<select class="widefat" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>">
				<option value=""><?php esc_html_e( '— Select —', 'buildingcare-lite' ); ?></option>
				<?php foreach ( $options as $value => $text ) : ?>
					<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $selected, (string) $value ); ?>>
						<?php echo esc_html( $text ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}
}
