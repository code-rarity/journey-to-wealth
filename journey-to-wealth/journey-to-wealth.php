<?php
/**
 * Plugin Name:       Journey to Wealth
 * Plugin URI:        https://stockswithajay.com/journey-to-wealth/
 * Description:       Provides historical stock data, valuation analysis, and comparison tools to aid your journey to wealth.
 * Version:           1.0.0
 * Author:            Stocks With Ajay
 * Author URI:        https://stockswithajay.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       journey-to-wealth
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Currently plugin version.
 */
define( 'JOURNEY_TO_WEALTH_VERSION', '1.0.0' );

/**
 * Define plugin path and URL constants.
 */
define( 'JOURNEY_TO_WEALTH_PATH', plugin_dir_path( __FILE__ ) );
define( 'JOURNEY_TO_WEALTH_URL', plugin_dir_url( __FILE__ ) );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-journey-to-wealth-activator.php
 */
function activate_journey_to_wealth() {
    require_once JOURNEY_TO_WEALTH_PATH . 'includes/class-journey-to-wealth-activator.php';
    Journey_To_Wealth_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-journey-to-wealth-deactivator.php
 */
function deactivate_journey_to_wealth() {
    require_once JOURNEY_TO_WEALTH_PATH . 'includes/class-journey-to-wealth-deactivator.php';
    Journey_To_Wealth_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_journey_to_wealth' );
register_deactivation_hook( __FILE__, 'deactivate_journey_to_wealth' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require JOURNEY_TO_WEALTH_PATH . 'includes/class-journey-to-wealth.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_journey_to_wealth() {

    $plugin = new Journey_To_Wealth();
    $plugin->run();

}
run_journey_to_wealth();