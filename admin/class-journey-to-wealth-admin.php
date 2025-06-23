<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 * Also handles the plugin settings page.
 *
 * @link       https://example.com/journey-to-wealth/
 * @since      1.0.0
 *
 * @package    Journey_To_Wealth
 * @subpackage Journey_To_Wealth/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * @package    Journey_To_Wealth
 * @subpackage Journey_To_Wealth/admin
 * @author     Your Name or Company <email@example.com>
 */
class Journey_To_Wealth_Admin {

    private $plugin_name;
    private $version;
    private $settings_page_slug = 'journey-to-wealth-settings';


    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
    }

    public function enqueue_styles() {
        wp_enqueue_style( $this->plugin_name . '-admin', plugin_dir_url( __FILE__ ) . 'assets/css/admin-styles.css', array(), $this->version, 'all' );
    }

    public function enqueue_scripts() {
        wp_enqueue_script( $this->plugin_name . '-admin', plugin_dir_url( __FILE__ ) . 'assets/js/admin-scripts.js', array( 'jquery' ), $this->version, false );
    }

    public function add_plugin_admin_menu() {
        // Add main menu page
        add_menu_page(
            __( 'Journey to Wealth', 'journey-to-wealth' ), // Page title
            __( 'Journey to Wealth', 'journey-to-wealth' ), // Menu title
            'manage_options',                         // Capability
            $this->settings_page_slug,                // Menu slug
            array( $this, 'display_plugin_settings_page' ), // Function
            'dashicons-chart-line',                   // Icon URL
            75                                        // Position
        );

        // Add API Settings submenu page (as the main settings page)
        add_submenu_page(
            $this->settings_page_slug,                // Parent slug
            __( 'API Settings', 'journey-to-wealth' ),  // Page title
            __( 'API Settings', 'journey-to-wealth' ),  // Menu title
            'manage_options',                         // Capability
            $this->settings_page_slug,                // Menu slug (same as parent to make it the default)
            array( $this, 'display_plugin_settings_page' )  // Function
        );
    }

    public function display_plugin_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'jtw_api_settings_group' );
                do_settings_sections( $this->settings_page_slug );
                submit_button( __( 'Save API Settings', 'journey-to-wealth' ) );
                ?>
            </form>
        </div>
        <?php
    }

    public function register_settings() {
        // Register API Settings Section
        add_settings_section(
            'jtw_api_settings_section',               // ID
            __( 'Polygon.io API Key', 'journey-to-wealth' ), // Title
            array( $this, 'jtw_api_settings_section_callback' ), // Callback
            $this->settings_page_slug                 // Page on which to show it
        );

        // Register API Key Setting Field
        add_settings_field(
            'jtw_api_key',                            // ID
            __( 'API Key', 'journey-to-wealth' ),       // Title
            array( $this, 'jtw_api_key_render' ),       // Callback
            $this->settings_page_slug,                // Page
            'jtw_api_settings_section'                // Section
        );
        register_setting( 'jtw_api_settings_group', 'jtw_api_key', array( $this, 'sanitize_api_key' ) );
    }

    public function sanitize_api_key( $input ) {
        return sanitize_text_field( $input );
    }
    
    public function jtw_api_settings_section_callback() {
        echo '<p>' . esc_html__( 'Enter your Polygon.io API key below. You can obtain a free API key from the Polygon.io website.', 'journey-to-wealth' ) . '</p>';
    }

    public function jtw_api_key_render() {
        $api_key = get_option( 'jtw_api_key' );
        ?>
        <input type='text' name='jtw_api_key' value='<?php echo esc_attr( $api_key ); ?>' class='regular-text'>
        <p class="description"><?php esc_html_e( 'Your API key for accessing Polygon.io data.', 'journey-to-wealth' ); ?></p>
        <?php
    }
}
