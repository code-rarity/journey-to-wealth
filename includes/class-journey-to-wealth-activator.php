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
class Journey_To_Wealth_Activator {

    /**
     * Runs activation tasks.
     *
     * @since    1.0.0
     */
    public static function activate() {
        // Add a version option to the database.
        if ( false === get_option( 'journey_to_wealth_version' ) ) {
            add_option( 'journey_to_wealth_version', '3.3.0' ); // Use a static version or constant
        } else {
            update_option( 'journey_to_wealth_version', '3.3.0' );
        }

        // Create the custom database tables.
        self::create_beta_table();
        self::create_company_mapping_table();
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
     * Create the custom table for company-to-industry mappings.
     *
     * @since    3.3.0
     */
    private static function create_company_mapping_table() {
        global $wpdb;
        // **FIX**: Corrected table name to match the rest of the plugin.
        $table_name = $wpdb->prefix . 'jtw_company_mappings';
        $charset_collate = $wpdb->get_charset_collate();

        // **FIX**: Corrected schema to use 'ticker' and a proper unique key.
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            ticker varchar(20) NOT NULL,
            damodaran_industry_id mediumint(9) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY ticker_damodaran_pair (ticker, damodaran_industry_id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

}
