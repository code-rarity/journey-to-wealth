<?php

/**
 * The file that defines the internationalization class.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @link       https://example.com/journey-to-wealth/
 * @since      1.0.0
 *
 * @package    Journey_To_Wealth
 * @subpackage Journey_To_Wealth/includes
 */

/**
 * Defines the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      1.0.0
 * @package    Journey_To_Wealth
 * @subpackage Journey_To_Wealth/includes
 * @author     Your Name or Company <email@example.com>
 */
class Journey_To_Wealth_i18n {

    /**
     * The domain specified for this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $domain    The domain identifier for this plugin.
     */
    private $domain;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param    string    $domain    The domain identifier for this plugin.
     */
    public function __construct( $domain = 'journey-to-wealth' ) { // Default to plugin slug
        $this->domain = $domain;
    }

    /**
     * Load the plugin text domain for translation.
     *
     * @since    1.0.0
     */
    public function load_plugin_textdomain() {
        /**
         * Loads the plugin's translated strings.
         *
         * The `load_plugin_textdomain` function loads the .mo file
         * from the /languages directory.
         *
         * The first argument is the plugin's unique text domain.
         * The second argument is deprecated (was for .po file path).
         * The third argument is the relative path to the .mo files directory.
         */
        load_plugin_textdomain(
            $this->domain,
            false, // Deprecated argument, set to false
            dirname( plugin_basename( __FILE__ ) ) . '/../languages/'
        );
    }

    /**
     * Sets the domain for the plugin. (Optional method if you need to change it after instantiation)
     *
     * @since    1.0.0
     * @param    string    $domain    The domain identifier for this plugin.
     */
    public function set_domain( $domain ) {
        $this->domain = $domain;
    }
}
