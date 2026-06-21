<?php
/**
 * Tenant user accounts: link residents to WordPress users, auto-provision
 * accounts with a set-password email, and keep tenants out of wp-admin.
 *
 * @package BuildingCareLite
 */

declare(strict_types=1);

namespace BuildingCareLite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the resident <-> user relationship and tenant access.
 */
class Tenant_Accounts {

	public const ROLE      = 'building_tenant';
	private const USER_META = 'bc_resident_id';
	private const RES_META  = 'bc_user_id';
	private const SENT_META = 'bc_welcome_sent';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'save_post_bc_resident', array( $this, 'sync_account' ), 30, 3 );
		add_action( 'admin_init', array( $this, 'block_admin_access' ) );
		add_filter( 'show_admin_bar', array( $this, 'maybe_hide_admin_bar' ) );
		add_action( 'admin_post_bcl_provision_tenants', array( $this, 'handle_bulk_provision' ) );
	}

	/**
	 * Resident ID linked to a user, or 0.
	 */
	public static function resident_for_user( int $user_id ): int {
		return (int) get_user_meta( $user_id, self::USER_META, true );
	}

	/**
	 * User ID linked to a resident, or 0.
	 */
	public static function user_for_resident( int $resident_id ): int {
		return (int) get_post_meta( $resident_id, self::RES_META, true );
	}

	/**
	 * Whether the current (or given) user is a tenant without elevated access.
	 */
	public static function is_pure_tenant( ?\WP_User $user = null ): bool {
		$user = $user ?: wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		$roles = (array) $user->roles;
		$elevated = array( 'administrator', 'building_admin', 'building_manager', 'editor' );

		return in_array( self::ROLE, $roles, true )
			&& ! user_can( $user, 'manage_options' )
			&& ! array_intersect( $elevated, $roles );
	}

	/**
	 * Create or update the WP user account for a resident on save.
	 *
	 * @param int      $resident_id Resident post ID.
	 * @param \WP_Post $post        Post object.
	 * @param bool     $update      Whether this is an update.
	 */
	public function sync_account( int $resident_id, \WP_Post $post, bool $update ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( 'publish' !== $post->post_status || wp_is_post_revision( $resident_id ) ) {
			return;
		}

		$this->provision_resident( $resident_id );
	}

	/**
	 * Create or link the WP user for one resident.
	 *
	 * @return string One of: created, linked, updated, no_email.
	 */
	public function provision_resident( int $resident_id ): string {
		$email = sanitize_email( bcl_get_meta_string( $resident_id, 'bc_email' ) );
		$name  = get_the_title( $resident_id );

		$linked_user = self::user_for_resident( $resident_id );

		// Already linked — keep email/display name roughly in sync.
		if ( $linked_user && get_userdata( $linked_user ) ) {
			if ( $email || $name ) {
				$update_args = array( 'ID' => $linked_user );
				if ( $email ) {
					$update_args['user_email'] = $email;
				}
				if ( $name ) {
					$update_args['display_name'] = $name;
				}
				wp_update_user( $update_args );
			}
			return 'updated';
		}

		if ( ! $email || ! is_email( $email ) ) {
			return 'no_email';
		}

		$existing = get_user_by( 'email', $email );
		if ( $existing ) {
			$this->link( $resident_id, (int) $existing->ID );
			$existing->add_role( self::ROLE );
			return 'linked';
		}

		$user_id = $this->create_user( $email, $name );
		if ( $user_id ) {
			$this->link( $resident_id, $user_id );
			$this->maybe_send_welcome( $resident_id, $user_id );
			return 'created';
		}

		return 'no_email';
	}

	/**
	 * Provision accounts for every resident that doesn't have one yet.
	 *
	 * @return array{created:int, linked:int, skipped:int}
	 */
	public function provision_all(): array {
		$residents = get_posts(
			array(
				'post_type'      => 'bc_resident',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$result = array(
			'created' => 0,
			'linked'  => 0,
			'skipped' => 0,
		);

		foreach ( $residents as $resident_id ) {
			$status = $this->provision_resident( (int) $resident_id );
			if ( 'created' === $status ) {
				++$result['created'];
			} elseif ( 'linked' === $status ) {
				++$result['linked'];
			} elseif ( 'no_email' === $status ) {
				++$result['skipped'];
			}
		}

		return $result;
	}

	/**
	 * Handle the "Create tenant logins" admin action.
	 */
	public function handle_bulk_provision(): void {
		check_admin_referer( 'bcl_provision_tenants' );

		if ( ! current_user_can( 'bc_manage_residents' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'buildingcare-lite' ) );
		}

		$result = $this->provision_all();

		$redirect = add_query_arg(
			array(
				'page'           => 'bcl-dashboard',
				'tenants_new'    => (int) $result['created'],
				'tenants_linked' => (int) $result['linked'],
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Create a tenant user.
	 */
	private function create_user( string $email, string $name ): int {
		$base = sanitize_user( current( explode( '@', $email ) ), true );
		if ( '' === $base ) {
			$base = 'tenant';
		}

		$username = $base;
		$i        = 1;
		while ( username_exists( $username ) ) {
			$username = $base . $i;
			++$i;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 24, true, true ),
				'display_name' => $name ? $name : $username,
				'role'         => self::ROLE,
			)
		);

		return is_wp_error( $user_id ) ? 0 : (int) $user_id;
	}

	/**
	 * Store the two-way link between resident and user.
	 */
	private function link( int $resident_id, int $user_id ): void {
		update_post_meta( $resident_id, self::RES_META, $user_id );
		update_user_meta( $user_id, self::USER_META, $resident_id );
	}

	/**
	 * Send a welcome email with a set-password link (once).
	 */
	private function maybe_send_welcome( int $resident_id, int $user_id ): void {
		if ( get_post_meta( $resident_id, self::SENT_META, true ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return;
		}

		$reset_url = network_site_url(
			'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ),
			'login'
		);

		$portal   = Tenant_Portal::url();
		$site     = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$subject  = sprintf( /* translators: %s: site name */ __( 'Your %s tenant account', 'buildingcare-lite' ), $site );

		$lines   = array();
		$lines[] = sprintf( /* translators: %s: name */ __( 'Hello %s,', 'buildingcare-lite' ), $user->display_name );
		$lines[] = '';
		$lines[] = __( 'An account has been created for you to view your flat bills, dues and payment history online.', 'buildingcare-lite' );
		$lines[] = '';
		$lines[] = sprintf( /* translators: %s: username */ __( 'Username: %s', 'buildingcare-lite' ), $user->user_login );
		$lines[] = __( 'Set your password using the link below:', 'buildingcare-lite' );
		$lines[] = $reset_url;
		$lines[] = '';
		$lines[] = sprintf( /* translators: %s: portal url */ __( 'Then log in at: %s', 'buildingcare-lite' ), $portal );

		wp_mail( $user->user_email, $subject, implode( "\n", $lines ) );

		update_post_meta( $resident_id, self::SENT_META, time() );
	}

	/**
	 * Redirect tenants away from wp-admin to their portal.
	 */
	public function block_admin_access(): void {
		if ( wp_doing_ajax() ) {
			return;
		}

		if ( self::is_pure_tenant() ) {
			wp_safe_redirect( Tenant_Portal::url() );
			exit;
		}
	}

	/**
	 * Hide the admin bar for pure tenants on the front end.
	 *
	 * @param bool $show Whether to show the admin bar.
	 */
	public function maybe_hide_admin_bar( bool $show ): bool {
		if ( self::is_pure_tenant() ) {
			return false;
		}

		return $show;
	}
}
