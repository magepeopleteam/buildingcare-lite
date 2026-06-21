<?php
/**
 * Plugin loader.
 *
 * @package BuildingCareLite
 */

declare(strict_types=1);

namespace BuildingCareLite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton loader for all plugin components.
 */
final class Loader {

	/**
	 * Singleton instance.
	 */
	private static ?self $instance = null;

	/**
	 * Get singleton instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize plugin components.
	 */
	public function init(): void {
		$this->load_files();
		$this->load_textdomain();
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );

		new Post_Types();
		new Roles();
		new Meta_Boxes();
		new Cron();
		new Payments();
		new Billing();
		new Expenses();
		new Reports();
		new Export();
		new Import();
		new Notifications();
		new Rest_Api();
		new PWA();
		new Tenant_Accounts();
		new Tenant_Portal();

		if ( is_admin() ) {
			new Admin_Pages();
			new Dashboard();
		}
	}

	/**
	 * Run version-gated upgrade routines when the stored DB version is behind.
	 */
	public function maybe_upgrade(): void {
		$stored = (string) get_option( 'bcl_db_version', '' );
		if ( $stored === BCL_VERSION ) {
			return;
		}

		// Ensure roles/capabilities are refreshed and cron is scheduled after an update.
		( new Roles() )->register_roles();
		Cron::schedule_events();

		// Flush so new rewrite rules (e.g. the /tenant/ portal) take effect.
		flush_rewrite_rules();

		update_option( 'bcl_db_version', BCL_VERSION );
	}

	/**
	 * Require all class files.
	 */
	private function load_files(): void {
		$files = array(
			'helpers.php',
			'class-post-types.php',
			'class-roles.php',
			'class-meta-boxes.php',
			'class-cron.php',
			'class-payments.php',
			'class-billing.php',
			'class-expenses.php',
			'class-reports.php',
			'class-export.php',
			'class-import.php',
			'class-notifications.php',
			'class-rest-api.php',
			'class-pwa.php',
			'class-tenant-accounts.php',
			'class-tenant-portal.php',
		);

		if ( is_admin() ) {
			$files[] = 'class-admin-pages.php';
			$files[] = 'class-dashboard.php';
		}

		foreach ( $files as $file ) {
			require_once BCL_PLUGIN_DIR . 'includes/' . $file;
		}
	}

	/**
	 * Load translations.
	 */
	private function load_textdomain(): void {
		load_plugin_textdomain(
			'buildingcare-lite',
			false,
			dirname( BCL_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Plugin activation.
	 */
	public static function activate(): void {
		require_once BCL_PLUGIN_DIR . 'includes/helpers.php';
		require_once BCL_PLUGIN_DIR . 'includes/class-post-types.php';
		require_once BCL_PLUGIN_DIR . 'includes/class-roles.php';
		require_once BCL_PLUGIN_DIR . 'includes/class-cron.php';
		require_once BCL_PLUGIN_DIR . 'includes/class-payments.php';
		require_once BCL_PLUGIN_DIR . 'includes/class-tenant-portal.php';

		$post_types = new Post_Types();
		$post_types->register_post_types();
		$post_types->register_taxonomies();
		$post_types->seed_expense_categories();

		( new Payments() )->register_post_type();
		( new Tenant_Portal() )->add_rewrite();

		$roles = new Roles();
		$roles->register_roles();

		Cron::schedule_events();

		update_option( 'bcl_db_version', BCL_VERSION );

		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation.
	 */
	public static function deactivate(): void {
		require_once BCL_PLUGIN_DIR . 'includes/class-cron.php';
		require_once BCL_PLUGIN_DIR . 'includes/class-notifications.php';
		Cron::clear_events();
		Notifications::clear_events();
		flush_rewrite_rules();
	}
}
