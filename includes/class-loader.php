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

		new Post_Types();
		new Roles();
		new Meta_Boxes();
		new Cron();
		new Billing();
		new Expenses();
		new Reports();
		new Export();
		new Rest_Api();

		if ( is_admin() ) {
			new Admin_Pages();
		}
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
			'class-billing.php',
			'class-expenses.php',
			'class-reports.php',
			'class-export.php',
			'class-rest-api.php',
		);

		if ( is_admin() ) {
			$files[] = 'class-admin-pages.php';
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

		$post_types = new Post_Types();
		$post_types->register_post_types();
		$post_types->register_taxonomies();
		$post_types->seed_expense_categories();

		$roles = new Roles();
		$roles->register_roles();

		Cron::schedule_events();

		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation.
	 */
	public static function deactivate(): void {
		require_once BCL_PLUGIN_DIR . 'includes/class-cron.php';
		Cron::clear_events();
		flush_rewrite_rules();
	}
}
