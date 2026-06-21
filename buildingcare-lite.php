<?php
/**
 * Plugin Name:       BuildingCare Lite
 * Plugin URI:        https://mage-people.com/
 * Description:       Lightweight apartment management for building owners — flats, residents, service charges, expenses, and balance sheets.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      8.2
 * Author:            MagePeople Team
 * Author URI:        https://mage-people.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       buildingcare-lite
 * Domain Path:       /languages
 *
 * @package BuildingCareLite
 */

declare(strict_types=1);

namespace BuildingCareLite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BCL_VERSION', '1.2.0' );
define( 'BCL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BCL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BCL_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once BCL_PLUGIN_DIR . 'includes/class-loader.php';

/**
 * Bootstrap the plugin after all plugins are loaded.
 */
function bcl_init(): void {
	Loader::instance()->init();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\bcl_init' );

register_activation_hook( __FILE__, array( Loader::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Loader::class, 'deactivate' ) );
