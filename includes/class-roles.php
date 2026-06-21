<?php
/**
 * User roles and capabilities.
 *
 * @package BuildingCareLite
 */

declare(strict_types=1);

namespace BuildingCareLite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers BuildingCare roles and capabilities.
 */
class Roles {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_roles' ), 11 );
		add_filter( 'map_meta_cap', array( $this, 'map_meta_cap' ), 10, 4 );
	}

	/**
	 * Register custom roles and assign capabilities.
	 */
	public function register_roles(): void {
		$admin_caps = array(
			'bc_manage_buildings'   => true,
			'bc_manage_flats'       => true,
			'bc_manage_residents'   => true,
			'bc_generate_bills'     => true,
			'bc_manage_payments'    => true,
			'bc_manage_expenses'    => true,
			'bc_view_reports'       => true,
			'bc_manage_settings'    => true,
			'read'                  => true,
		);

		$manager_caps = array(
			'bc_manage_payments' => true,
			'bc_manage_expenses' => true,
			'bc_view_reports'    => true,
			'read'               => true,
		);

		$tenant_caps = array(
			'read'          => true,
			'bc_view_portal' => true,
		);

		$this->add_role_caps( 'building_admin', __( 'Building Admin', 'buildingcare-lite' ), $admin_caps );
		$this->add_role_caps( 'building_manager', __( 'Manager', 'buildingcare-lite' ), $manager_caps );
		$this->add_role_caps( 'building_tenant', __( 'Building Tenant', 'buildingcare-lite' ), $tenant_caps );

		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			foreach ( array_keys( $admin_caps ) as $cap ) {
				$administrator->add_cap( $cap );
			}
			$this->assign_post_type_caps( $administrator );
		}

		$building_admin = get_role( 'building_admin' );
		if ( $building_admin ) {
			$this->assign_post_type_caps( $building_admin );
		}

		$manager = get_role( 'building_manager' );
		if ( $manager ) {
			$this->assign_limited_post_type_caps( $manager );
		}
	}

	/**
	 * Add or update a role with capabilities.
	 *
	 * @param array<string, bool> $caps Capabilities.
	 */
	private function add_role_caps( string $role_key, string $label, array $caps ): void {
		$role = get_role( $role_key );
		if ( ! $role ) {
			add_role( $role_key, $label, $caps );
			return;
		}

		foreach ( $caps as $cap => $grant ) {
			if ( $grant ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Assign full CPT capabilities to a role.
	 *
	 * @param \WP_Role $role Role object.
	 */
	private function assign_post_type_caps( \WP_Role $role ): void {
		$types = array(
			'building'           => 'buildings',
			'flat'               => 'flats',
			'resident'           => 'residents',
			'bill'               => 'bills',
			'expense'            => 'expenses',
			'recurring_expense'  => 'recurring_expenses',
		);

		foreach ( $types as $singular => $plural ) {
			$role->add_cap( "bc_edit_{$singular}" );
			$role->add_cap( "bc_read_{$singular}" );
			$role->add_cap( "bc_delete_{$singular}" );
			$role->add_cap( "bc_edit_{$plural}" );
			$role->add_cap( "bc_edit_others_{$plural}" );
			$role->add_cap( "bc_publish_{$plural}" );
			$role->add_cap( "bc_read_private_{$plural}" );
			$role->add_cap( "bc_create_{$plural}" );
			$role->add_cap( "bc_delete_{$plural}" );
			$role->add_cap( "bc_delete_others_{$plural}" );
			$role->add_cap( "bc_delete_private_{$plural}" );
			$role->add_cap( "bc_delete_published_{$plural}" );
		}
	}

	/**
	 * Assign read/edit caps for managers.
	 *
	 * @param \WP_Role $role Role object.
	 */
	private function assign_limited_post_type_caps( \WP_Role $role ): void {
		$types = array( 'bill', 'bills', 'expense', 'expenses' );

		foreach ( $types as $type ) {
			$role->add_cap( "bc_edit_{$type}" );
			$role->add_cap( "bc_read_{$type}" );
			$role->add_cap( "bc_edit_others_{$type}" );
			$role->add_cap( "bc_read_private_{$type}" );
			$role->add_cap( "bc_create_{$type}" );
			$role->add_cap( "bc_delete_{$type}" );
			$role->add_cap( "bc_delete_others_{$type}" );
		}
	}

	/**
	 * Map custom capabilities to primitive caps.
	 *
	 * Never call user_can() or current_user_can() here — it causes infinite recursion.
	 *
	 * @param array<int, string> $caps    Required caps.
	 * @param string             $cap     Requested cap.
	 * @param int                $user_id User ID.
	 * @param array<int, mixed>  $args    Extra args.
	 * @return array<int, string>
	 */
	public function map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
		if ( 'bc_create_bills' === $cap ) {
			if ( bcl_is_bill_insert_allowed() ) {
				return array( 'exist' );
			}

			return array( 'do_not_allow' );
		}

		if ( 'bc_create_payments' === $cap ) {
			if ( bcl_is_payment_insert_allowed() ) {
				return array( 'exist' );
			}

			return array( 'do_not_allow' );
		}

		if ( $this->user_is_super_admin( $user_id ) ) {
			if ( $this->is_plugin_capability( $cap, $args ) ) {
				return array( 'exist' );
			}

			return $caps;
		}

		$manage_caps = $this->get_manage_caps_for_request( $cap, $args );
		foreach ( $manage_caps as $manage_cap ) {
			if ( bcl_user_has_cap( $user_id, $manage_cap ) ) {
				return array( $manage_cap );
			}
		}

		if ( in_array( $cap, bcl_get_capabilities(), true ) && bcl_user_has_cap( $user_id, $cap ) ) {
			return array( $cap );
		}

		if ( str_starts_with( $cap, 'bc_' ) && bcl_user_has_cap( $user_id, $cap ) ) {
			return array( $cap );
		}

		return $caps;
	}

	/**
	 * Whether a capability belongs to this plugin.
	 *
	 * @param array<int, mixed> $args map_meta_cap args.
	 */
	private function is_plugin_capability( string $cap, array $args ): bool {
		if ( in_array( $cap, bcl_get_capabilities(), true ) || str_starts_with( $cap, 'bc_' ) ) {
			return true;
		}

		if ( in_array( $cap, array( 'edit_post', 'read_post', 'delete_post' ), true ) && ! empty( $args[0] ) ) {
			$post = get_post( (int) $args[0] );
			return $post && in_array( $post->post_type, bcl_get_post_types(), true );
		}

		return false;
	}

	/**
	 * Resolve manage_* caps from a meta or primitive cap request.
	 *
	 * @param array<int, mixed> $args map_meta_cap args.
	 * @return string[]
	 */
	private function get_manage_caps_for_request( string $cap, array $args ): array {
		if ( in_array( $cap, array( 'edit_post', 'read_post', 'delete_post' ), true ) && ! empty( $args[0] ) ) {
			$post = get_post( (int) $args[0] );
			if ( $post ) {
				return $this->get_manage_caps_for_post_type( $post->post_type );
			}
		}

		if ( ! str_starts_with( $cap, 'bc_' ) ) {
			return array();
		}

		$matched = array();
		foreach ( bcl_get_manage_cap_prefixes() as $manage_cap => $prefixes ) {
			foreach ( $prefixes as $prefix ) {
				if ( str_starts_with( $cap, $prefix ) ) {
					$matched[] = $manage_cap;
					break;
				}
			}
		}

		return array_values( array_unique( $matched ) );
	}

	/**
	 * Get umbrella manage caps for a post type.
	 *
	 * @return string[]
	 */
	private function get_manage_caps_for_post_type( string $post_type ): array {
		$map = array(
			'bc_building'          => array( 'bc_manage_buildings' ),
			'bc_flat'              => array( 'bc_manage_flats' ),
			'bc_resident'          => array( 'bc_manage_residents' ),
			'bc_bill'              => array( 'bc_generate_bills', 'bc_manage_payments' ),
			'bc_expense'           => array( 'bc_manage_expenses' ),
			'bc_recurring_expense' => array( 'bc_manage_expenses' ),
		);

		return $map[ $post_type ] ?? array();
	}

	/**
	 * Check if user is a WordPress administrator without calling user_can().
	 */
	private function user_is_super_admin( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		if ( is_multisite() && is_super_admin( $user_id ) ) {
			return true;
		}

		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			return true;
		}

		return ! empty( $user->allcaps['manage_options'] );
	}
}
