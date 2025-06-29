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
    private $settings_page_slug = 'jtw-api-settings';


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
        add_options_page(
            __( 'Journey To Wealth Settings', 'journey-to-wealth' ), // Page title
            __( 'Journey To Wealth', 'journey-to-wealth' ),      // Menu title
            'manage_options',                                     // Capability
            $this->settings_page_slug,                            // Menu slug
            array( $this, 'display_plugin_settings_page' )       // Function to display the page
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
                submit_button( __( 'Save Settings', 'journey-to-wealth' ) );
                ?>
            </form>
        </div>
        <?php
    }

    public function register_settings() {
        $settings_group = 'jtw_api_settings_group';

        register_setting( $settings_group, 'jtw_api_key', array( $this, 'sanitize_api_key' ) );

        add_settings_section(
            'jtw_api_settings_section',
            __( 'Polygon.io API Key', 'journey-to-wealth' ),
            array( $this, 'jtw_api_settings_section_callback' ),
            $this->settings_page_slug
        );

        add_settings_field(
            'jtw_api_key',
            __( 'API Key', 'journey-to-wealth' ),
            array( $this, 'jtw_api_key_render' ),
            $this->settings_page_slug,
            'jtw_api_settings_section'
        );
    }

    public function sanitize_api_key( $input ) {
        return sanitize_text_field( $input );
    }
    
    public function jtw_api_settings_section_callback() {
        echo '<p>' . esc_html__( 'Enter your API key from Polygon.io. This key will be used for all data, including Benzinga analyst ratings if your plan supports it.', 'journey-to-wealth' ) . '</p>';
    }

    public function jtw_api_key_render() {
        $api_key = get_option( 'jtw_api_key' );
        echo "<input type='text' name='jtw_api_key' value='" . esc_attr( $api_key ) . "' class='regular-text'>";
    }
}
