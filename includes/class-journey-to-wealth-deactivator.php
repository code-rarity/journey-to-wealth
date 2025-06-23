<?php

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @link       https://example.com/journey-to-wealth/
 * @since      1.0.0
 *
 * @package    Journey_To_Wealth
 * @subpackage Journey_To_Wealth/includes
 */

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    Journey_To_Wealth
 * @subpackage Journey_To_Wealth/includes
 * @author     Your Name or Company <email@example.com>
 */
class Journey_To_Wealth_Deactivator {

    /**
     * Short Description. (use period)
     *
     * Long Description.
     *
     * @since    1.0.0
     */
    public static function deactivate() {
        // Example: Remove plugin-specific options if desired.
        // Be cautious with this, as users might want to retain settings if they plan to reactivate.
        // delete_option( 'journey_to_wealth_api_key' );
        // delete_option( 'journey_to_wealth_version' );

        // Example: Flush rewrite rules if you had custom post types.
        // flush_rewrite_rules();

        // IMPORTANT: Do NOT delete user data or content created by the plugin
        // on deactivation unless the user explicitly opts into this (e.g., via a setting).
        // Deactivation is often temporary. Uninstallation (uninstall.php) is for permanent removal.
    }

}
