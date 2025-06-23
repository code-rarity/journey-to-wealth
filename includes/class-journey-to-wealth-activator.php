<?php

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @link       https://example.com/journey-to-wealth/
 * @since      1.0.0
 *
 * @package    Journey_To_Wealth
 * @subpackage Journey_To_Wealth/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Journey_To_Wealth
 * @subpackage Journey_To_Wealth/includes
 * @author     Your Name or Company <email@example.com>
 */
class Journey_To_Wealth_Activator {

    /**
     * Short Description. (use period)
     *
     * Long Description.
     *
     * @since    1.0.0
     */
    public static function activate() {
        // Example: Add a version option to the database.
        // This is useful for tracking plugin version for future updates or migrations.
        if ( false === get_option( 'journey_to_wealth_version' ) ) {
            add_option( 'journey_to_wealth_version', JOURNEY_TO_WEALTH_VERSION );
        } else {
            update_option( 'journey_to_wealth_version', JOURNEY_TO_WEALTH_VERSION );
        }

        // Example: Flush rewrite rules if you were registering custom post types or taxonomies.
        // Not strictly necessary for this plugin's initial scope, but good to know.
        // flush_rewrite_rules();

        // You can add other setup tasks here, like:
        // - Creating custom database tables (if absolutely necessary and not using options/CPTs)
        // - Setting default options
    }

}
