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
class Journey_To_Wealth_Admin {

    private $plugin_name;
    private $version;
    private $main_page_slug = 'jtw-industry-mapping';
    private $data_settings_page_slug = 'jtw-data-settings';
    private $plugin_settings_page_slug = 'jtw-plugin-settings';
    private $plugin_pages = [];


    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'admin_menu', array( $this, 'add_plugin_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_post_jtw_fetch_beta_data', array( $this, 'handle_beta_data_fetch' ) );
        add_action( 'wp_ajax_jtw_save_single_mapping', array( $this, 'ajax_save_single_mapping' ) );
        add_action( 'admin_post_jtw_reset_database', array( $this, 'handle_database_reset' ) );
    }

    public function enqueue_styles($hook) {
        if (!in_array($hook, $this->plugin_pages)) {
            return;
        }

        wp_enqueue_style( $this->plugin_name . '-admin', plugin_dir_url( __FILE__ ) . 'assets/css/admin-styles.css', array(), $this->version, 'all' );
        
        if ( in_array($hook, [$this->plugin_pages[0], $this->plugin_pages[1]])) { 
            wp_enqueue_style( 'jquery-ui-css', '//ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/base/jquery-ui.css' );
        }
    }

    public function enqueue_scripts($hook) {
        if (!in_array($hook, $this->plugin_pages)) {
            return;
        }
        
        wp_enqueue_script( $this->plugin_name . '-admin', plugin_dir_url( __FILE__ ) . 'assets/js/admin-scripts.js', array( 'jquery', 'jquery-ui-accordion' ), $this->version, true );

        if (in_array($hook, [$this->plugin_pages[0], $this->plugin_pages[1]])) {
            wp_localize_script($this->plugin_name . '-admin', 'jtw_mapping_ajax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('jtw_save_single_mapping_nonce')
            ]);
        }
    }

    public function add_plugin_admin_menu() {
        $this->plugin_pages[] = add_menu_page(
            __( 'Journey To Wealth', 'journey-to-wealth' ),
            __( 'Journey To Wealth', 'journey-to-wealth' ),
            'manage_options',
            $this->main_page_slug,
            array( $this, 'display_industry_mapping_page' ),
            'dashicons-chart-line',
            26
        );

        $this->plugin_pages[] = add_submenu_page( $this->main_page_slug, __( 'Industry Mapping', 'journey-to-wealth' ), __( 'Industry Mapping', 'journey-to-wealth' ), 'manage_options', $this->main_page_slug, array( $this, 'display_industry_mapping_page' ) );
        $this->plugin_pages[] = add_submenu_page( $this->main_page_slug, __( 'Data Settings', 'journey-to-wealth' ), __( 'Data Settings', 'journey-to-wealth' ), 'manage_options', $this->data_settings_page_slug, array( $this, 'display_data_settings_page' ) );
        $this->plugin_pages[] = add_submenu_page( $this->main_page_slug, __( 'Plugin Settings', 'journey-to-wealth' ), __( 'Plugin Settings', 'journey-to-wealth' ), 'manage_options', $this->plugin_settings_page_slug, array( $this, 'display_plugin_settings_page' ) );
    }

    public function display_industry_mapping_page() { require_once plugin_dir_path( __FILE__ ) . 'views/settings-industry-mapping-page.php'; }
    public function display_data_settings_page() { require_once plugin_dir_path( __FILE__ ) . 'views/settings-data-settings-page.php'; }
    public function display_plugin_settings_page() { require_once plugin_dir_path( __FILE__ ) . 'views/settings-plugin-settings-page.php'; }

    public function register_settings() {
        // Plugin Settings Page
        register_setting( 'jtw_plugin_settings_group', 'jtw_av_api_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
        add_settings_section( 'jtw_api_settings_section', __( 'Alpha Vantage API Key', 'journey-to-wealth' ), array( $this, 'jtw_api_settings_section_callback' ), $this->plugin_settings_page_slug );
        add_settings_field( 'jtw_av_api_key', __( 'API Key', 'journey-to-wealth' ), array( $this, 'jtw_api_key_render' ), $this->plugin_settings_page_slug, 'jtw_api_settings_section' );

        // Data Settings Page
        register_setting( 'jtw_data_settings_group', 'jtw_erp_setting', array( 'sanitize_callback' => 'floatval' ) );
        register_setting( 'jtw_data_settings_group', 'jtw_tax_rate_setting', array( 'sanitize_callback' => 'floatval' ) );
        register_setting( 'jtw_data_settings_group', 'jtw_beta_data_url', array( 'sanitize_callback' => 'esc_url_raw' ) );
        
        add_settings_section( 'jtw_dcf_assumptions_section', 'DCF Assumptions', null, $this->data_settings_page_slug );
        add_settings_field( 'jtw_erp_setting', 'Equity Risk Premium (%)', array( $this, 'render_erp_field' ), $this->data_settings_page_slug, 'jtw_dcf_assumptions_section' );
        add_settings_field( 'jtw_tax_rate_setting', 'Corporate Tax Rate (%)', array( $this, 'render_tax_rate_field' ), $this->data_settings_page_slug, 'jtw_dcf_assumptions_section' );
        
        add_settings_section( 'jtw_beta_data_section', 'Industry Beta Data', null, $this->data_settings_page_slug );
        add_settings_field( 'jtw_beta_data_url', 'Damodaran Beta Data URL', array( $this, 'render_beta_data_url_field' ), $this->data_settings_page_slug, 'jtw_beta_data_section' );
    }
    
    public function jtw_api_settings_section_callback() { echo '<p>' . esc_html__( 'Enter your premium API key from Alpha Vantage. This key is required for all data fetching.', 'journey-to-wealth' ) . '</p>'; }
    public function jtw_api_key_render() { $api_key = get_option( 'jtw_av_api_key' ); echo "<input type='text' name='jtw_av_api_key' value='" . esc_attr( $api_key ) . "' class='regular-text'>"; }
    public function render_erp_field() { $erp_value = get_option('jtw_erp_setting', '5.0'); echo '<input type="number" step="0.1" name="jtw_erp_setting" value="' . esc_attr( $erp_value ) . '" class="small-text" />'; echo '<p class="description">' . esc_html__('Set the Equity Risk Premium (ERP) for DCF calculations. A common value is between 4% and 6%.', 'journey-to-wealth') . '</p>'; }
    public function render_tax_rate_field() { $tax_rate_value = get_option('jtw_tax_rate_setting', '21.0'); echo '<input type="number" step="0.1" name="jtw_tax_rate_setting" value="' . esc_attr( $tax_rate_value ) . '" class="small-text" />'; echo '<p class="description">' . esc_html__('Set the Corporate Tax Rate for levering beta. The default is the current US corporate tax rate.', 'journey-to-wealth') . '</p>'; }
    public function render_beta_data_url_field() { $beta_data_url = get_option('jtw_beta_data_url', 'https://pages.stern.nyu.edu/~adamodar/New_Home_Page/datafile/Betas.html'); echo '<input type="url" name="jtw_beta_data_url" value="' . esc_url( $beta_data_url ) . '" class="large-text">'; }

    public function handle_beta_data_fetch() {
        if ( ! isset( $_POST['jtw_beta_nonce'] ) || ! wp_verify_nonce( $_POST['jtw_beta_nonce'], 'jtw_fetch_beta_data_nonce' ) ) { wp_die( 'Security check failed.' ); }
        $url = get_option('jtw_beta_data_url');
        if (empty($url)) { wp_redirect( admin_url( 'admin.php?page=' . $this->data_settings_page_slug . '&message=error' ) ); exit; }
        $response = wp_remote_get($url, ['timeout' => 30]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { wp_redirect( admin_url( 'admin.php?page=' . $this->data_settings_page_slug . '&message=error' ) ); exit; }
        $html = wp_remote_retrieve_body($response);
        $dom = new DOMDocument(); @$dom->loadHTML($html); $xpath = new DOMXPath($dom);
        $rows = $xpath->query('//table/tr');
        global $wpdb; $table_name = $wpdb->prefix . 'jtw_industry_betas'; $wpdb->query("TRUNCATE TABLE $table_name");
        $success = false;
        foreach ($rows as $row) {
            $cols = $row->getElementsByTagName('td');
            if ($cols->length >= 6) {
                $industry_name = trim($cols->item(0)->nodeValue); $unlevered_beta = floatval(trim($cols->item(5)->nodeValue));
                if (!empty($industry_name) && is_numeric($unlevered_beta) && $unlevered_beta != 0) {
                     $wpdb->insert( $table_name, array( 'industry_name'  => $industry_name, 'unlevered_beta' => $unlevered_beta, ), array( '%s', '%f' ) );
                    $success = true;
                }
            }
        }
        if ($success) { wp_redirect( admin_url( 'admin.php?page=' . $this->data_settings_page_slug . '&message=success' ) ); } 
        else { wp_redirect( admin_url( 'admin.php?page=' . $this->data_settings_page_slug . '&message=error' ) ); }
        exit;
    }

    public function ajax_save_single_mapping() {
        check_ajax_referer('jtw_save_single_mapping_nonce', 'nonce');

        if ( ! isset( $_POST['av_industry'] ) ) {
            wp_send_json_error(['message' => 'Missing Alpha Vantage industry.']);
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'jtw_industry_mappings';
        $av_industry = sanitize_text_field( stripslashes( $_POST['av_industry'] ) );
        $damodaran_industries = isset($_POST['damodaran_industries']) ? $_POST['damodaran_industries'] : [];

        // Delete existing mappings for this AV industry first
        $wpdb->delete($table_name, ['av_industry' => $av_industry], ['%s']);

        // Insert new mappings
        if (!empty($damodaran_industries)) {
            foreach ($damodaran_industries as $damodaran_industry) {
                $wpdb->insert(
                    $table_name,
                    [
                        'av_industry'        => $av_industry,
                        'damodaran_industry' => sanitize_text_field(stripslashes($damodaran_industry)),
                    ],
                    ['%s', '%s']
                );
            }
        }

        wp_send_json_success(['message' => 'Mapping saved.']);
    }

    public function handle_database_reset() {
        if ( ! isset( $_POST['jtw_reset_nonce'] ) || ! wp_verify_nonce( $_POST['jtw_reset_nonce'], 'jtw_reset_database_nonce' ) ) { wp_die( 'Security check failed.' ); }
        if ( ! isset( $_POST['jtw_confirm_reset'] ) || $_POST['jtw_confirm_reset'] !== 'yes' ) { wp_redirect( admin_url( 'admin.php?page=' . $this->plugin_settings_page_slug . '&message=no_confirmation' ) ); exit; }
        global $wpdb;
        $beta_table_name = $wpdb->prefix . 'jtw_industry_betas';
        $mapping_table_name = $wpdb->prefix . 'jtw_industry_mappings';
        $wpdb->query( "DROP TABLE IF EXISTS $beta_table_name" );
        $wpdb->query( "DROP TABLE IF EXISTS $mapping_table_name" );
        delete_option('jtw_discovered_av_industries');
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-journey-to-wealth-activator.php';
        Journey_To_Wealth_Activator::activate();
        wp_redirect( admin_url( 'admin.php?page=' . $this->plugin_settings_page_slug . '&message=reset_success' ) );
        exit;
    }
}
