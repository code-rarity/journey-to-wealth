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
     * Runs activation tasks.
     *
     * @since    1.0.0
     */
    public static function activate() {
        // Add a version option to the database.
        if ( false === get_option( 'journey_to_wealth_version' ) ) {
            add_option( 'journey_to_wealth_version', JOURNEY_TO_WEALTH_VERSION );
        } else {
            update_option( 'journey_to_wealth_version', JOURNEY_TO_WEALTH_VERSION );
        }

        // Create the custom database tables.
        self::create_beta_table();
        self::create_mapping_table();
    }

	/**
	 * Create the custom table for industry betas.
	 *
	 * @since    2.3.0
	 */
	private static function create_beta_table() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'jtw_industry_betas';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			industry_name varchar(255) NOT NULL,
			unlevered_beta float NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY industry_name (industry_name(191))
		) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
	}

    /**
     * Create the custom table for industry mappings.
     *
     * @since    2.4.0
     */
    private static function create_mapping_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'jtw_industry_mappings';
        $charset_collate = $wpdb->get_charset_collate();

        // **FIXED** The unique key now correctly covers the PAIR of industries,
        // allowing one AV industry to be mapped to multiple Damodaran industries.
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            av_industry varchar(255) NOT NULL,
            damodaran_industry varchar(255) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY av_damodaran_pair (av_industry(191), damodaran_industry(191))
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

}
