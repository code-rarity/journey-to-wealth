<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://example.com/journey-to-wealth/
 * @since      1.0.0
 *
 * @package    Journey_To_Wealth
 * @subpackage Journey_To_Wealth/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * @since      1.0.0
 * @package    Journey_To_Wealth
 * @subpackage Journey_To_Wealth/includes
 * @author     Your Name or Company <email@example.com>
 */
class Journey_To_Wealth {

    protected $loader;
    protected $plugin_name;
    protected $version;

    public function __construct() {
        if ( defined( 'JOURNEY_TO_WEALTH_VERSION' ) ) {
            $this->version = JOURNEY_TO_WEALTH_VERSION;
        } else {
            $this->version = '1.0.0'; 
        }
        $this->plugin_name = 'journey-to-wealth';

        $this->load_dependencies();
        $this->set_locale();
        $this->define_admin_hooks();
        $this->define_public_hooks(); 
    }

    private function load_dependencies() {
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-journey-to-wealth-loader.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-journey-to-wealth-i18n.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-journey-to-wealth-admin.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-journey-to-wealth-public.php';
        $this->loader = new Journey_To_Wealth_Loader();
    }

    private function set_locale() {
        $plugin_i18n = new Journey_To_Wealth_i18n( $this->get_plugin_name() );
        $this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );
    }

    private function define_admin_hooks() {
        $plugin_admin = new Journey_To_Wealth_Admin( $this->get_plugin_name(), $this->get_version() );
        $this->loader->add_action( 'admin_menu', $plugin_admin, 'add_plugin_admin_menu' );
        $this->loader->add_action( 'admin_init', $plugin_admin, 'register_settings' );
    }

    /**
     * Register all of the hooks related to the public-facing functionality
     * of the plugin. This method is updated to register shortcodes directly.
     *
     * @since    1.0.0
     * @access   private
     */
    private function define_public_hooks() {

        $plugin_public = new Journey_To_Wealth_Public( $this->get_plugin_name(), $this->get_version() );

        // Enqueue public-facing scripts and styles
        $this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
        $this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );

        // --- Register Shortcodes Directly ---
        // Using the native WordPress function ensures the shortcodes are available early,
        // allowing them to be called from theme files like header.php.
        add_shortcode( 'jtw_stock_analyzer', array( $plugin_public, 'render_analyzer_layout_shortcode' ) );
        add_shortcode( 'jtw_header_lookup', array( $plugin_public, 'render_header_lookup_shortcode' ) );

        
        // --- Register AJAX handlers ---
        $this->loader->add_action( 'wp_ajax_jtw_fetch_analyzer_data', $plugin_public, 'ajax_fetch_analyzer_data' );
        $this->loader->add_action( 'wp_ajax_nopriv_jtw_fetch_analyzer_data', $plugin_public, 'ajax_fetch_analyzer_data' );
        $this->loader->add_action( 'wp_ajax_jtw_symbol_search', $plugin_public, 'ajax_symbol_search' );
        $this->loader->add_action( 'wp_ajax_nopriv_jtw_symbol_search', $plugin_public, 'ajax_symbol_search' );
    }

    public function run() { $this->loader->run(); }
    public function get_plugin_name() { return $this->plugin_name; }
    public function get_loader() { return $this->loader; }
    public function get_version() { return $this->version; }
}
