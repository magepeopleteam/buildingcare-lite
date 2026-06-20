<?php
/**
 * Custom posts list table for BuildingCare CPT list screens.
 *
 * @package BuildingCareLite
 */

declare(strict_types=1);

namespace BuildingCareLite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders Add New inside the search toolbar (no post-load DOM move).
 */
class BCL_Posts_List_Table extends \WP_Posts_List_Table {

	/**
	 * Post types that use the search-toolbar Add New layout.
	 *
	 * @var string[]
	 */
	public const TOOLBAR_POST_TYPES = array(
		'bc_building',
		'bc_flat',
		'bc_resident',
		'bc_expense',
		'bc_recurring_expense',
	);

	/**
	 * Whether this list table should render Add New before search.
	 */
	private function uses_search_toolbar_add_new(): bool {
		$post_type = $this->screen->post_type ?? '';

		return in_array( $post_type, self::TOOLBAR_POST_TYPES, true );
	}

	/**
	 * Search box with Add New rendered first (server-side).
	 *
	 * @param string $text     Search label.
	 * @param string $input_id Input ID prefix.
	 */
	public function search_box( $text, $input_id ): void {
		if ( ! $this->uses_search_toolbar_add_new() ) {
			parent::search_box( $text, $input_id );
			return;
		}

		$post_type_object = get_post_type_object( $this->screen->post_type );
		$can_create       = $post_type_object && current_user_can( $post_type_object->cap->create_posts );
		$show_search      = ! ( empty( $_REQUEST['s'] ) && ! $this->has_items() );

		if ( ! $can_create && ! $show_search ) {
			return;
		}

		if ( ! $can_create ) {
			parent::search_box( $text, $input_id );
			return;
		}

		$input_id  = $input_id . '-search-input';
		$add_new_url = admin_url(
			sprintf( 'post-new.php?post_type=%s', rawurlencode( (string) $this->screen->post_type ) )
		);
		?>
		<p class="search-box">
			<a href="<?php echo esc_url( $add_new_url ); ?>" class="page-title-action">
				<?php echo esc_html( $post_type_object->labels->add_new ); ?>
			</a>
			<?php if ( $show_search ) : ?>
				<label class="screen-reader-text" for="<?php echo esc_attr( $input_id ); ?>">
					<?php echo esc_html( $text ); ?>:
				</label>
				<input
					type="search"
					id="<?php echo esc_attr( $input_id ); ?>"
					name="s"
					value="<?php echo esc_attr( _admin_search_query() ); ?>"
				/>
				<?php submit_button( $text, '', '', false, array( 'id' => 'search-submit' ) ); ?>
			<?php endif; ?>
		</p>
		<?php
	}
}
