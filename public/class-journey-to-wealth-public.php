<?php
/**
 * The public-facing functionality of the plugin.
 * This class handles the shortcode and AJAX request for the analyzer tool.
 */
class Journey_To_Wealth_Public {

    private $plugin_name;
    private $version;

    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        $this->load_dependencies();
    }

    private function load_dependencies() {
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/api/class-polygon-client.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/analysis/models/class-journey-to-wealth-key-metrics-calculator.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/analysis/models/class-journey-to-wealth-dcf-model.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/analysis/models/class-journey-to-wealth-ddm-model.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/analysis/models/class-journey-to-wealth-affo-model.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/analysis/models/class-journey-to-wealth-excess-return-model.php';
    }

    public function enqueue_styles() {
        wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'assets/css/public-styles.css', array(), $this->version, 'all' );
    }
    
    public function enqueue_scripts() {
        wp_enqueue_script( 'chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js', array(), '3.9.1', true );
        wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'assets/js/public-scripts.js', array( 'jquery', 'chartjs' ), $this->version, true );
        
        $analysis_page_url = site_url('/stock-valuation-analysis/');

        wp_localize_script( $this->plugin_name, 'jtw_public_params', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'analyzer_nonce' => wp_create_nonce( 'jtw_analyzer_nonce_action'),
                'symbol_search_nonce' => wp_create_nonce('jtw_symbol_search_nonce_action'),
                'analysis_page_url' => $analysis_page_url,
                'text_loading' => __('Fetching data...', 'journey-to-wealth'),
                'text_error' => __('An error occurred. Please check the ticker and try again.', 'journey-to-wealth'),
            )
        );
    }

    /**
     * Renders the header search form shortcode.
     */
    public function render_header_lookup_shortcode( $atts ) {
        if (!is_user_logged_in()) {
            return '';
        }
        $unique_id = 'jtw-header-lookup-' . uniqid();
        $output = '<div class="jtw-header-lookup-form" id="' . esc_attr($unique_id) . '">';
        $output .= '<div class="jtw-input-group-seamless">';
        $output .= '<input type="text" class="jtw-header-ticker-input" placeholder="Search Ticker...">';
        $output .= '<button type="button" class="jtw-header-fetch-button" title="Analyze Stock">';
        $output .= '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>';
        $output .= '</button>';
        $output .= '</div>';
        $output .= '<div class="jtw-header-search-results"></div>';
        $output .= '</div>';
        return $output;
    }
    
    public function render_analyzer_layout_shortcode( $atts ) {
        $unique_id = 'jtw-analyzer-layout-' . uniqid();
        
        $output = '<div class="jtw-analyzer-wrapper" id="' . esc_attr($unique_id) . '">';
        $output .= '<div id="jtw-main-content-area" class="jtw-main-content-area">';
        $output .= '<p class="jtw-initial-prompt">' . esc_html__('Please use the search bar in the header to analyze a stock.', 'journey-to-wealth') . '</p>';
        $output .= '</div>';
        $output .= '</div>';
        return $output;
    }

    /**
     * Finds a financial value from a statement section using a direct key lookup.
     *
     * @param array  $financial_statement_section The associative array for the statement (e.g., income_statement).
     * @param string $key The key for the desired metric (e.g., 'revenues').
     * @return float|null The numeric value or null if not found.
     */
    private function find_financial_value($financial_statement_section, $key) {
        if (!is_array($financial_statement_section) || !isset($financial_statement_section[$key]['value'])) {
            return null;
        }
        $value = $financial_statement_section[$key]['value'];
        return is_numeric($value) ? (float)$value : null;
    }
    
    private function format_large_number($number, $prefix = '$') {
        if (!is_numeric($number) || $number == 0) {
            return $prefix === '$' ? '$0' : '0';
        }
        $abs_number = abs($number);
        $formatted_number = '';
        if ($abs_number >= 1.0e+12) {
            $formatted_number = round($number / 1.0e+12, 2) . 'T';
        } elseif ($abs_number >= 1.0e+9) {
            $formatted_number = round($number / 1.0e+9, 2) . 'B';
        } elseif ($abs_number >= 1.0e+6) {
            $formatted_number = round($number / 1.0e+6, 2) . 'M';
        } else {
            $formatted_number = number_format($number, 0);
        }
        return $prefix . $formatted_number;
    }

    private function build_analyzer_html($details, $stock_price, $market_cap, $shares_outstanding, $metrics_data, $historical_data, $valuation_data, $valuation_summary, $eps) {
        $name = $details['name'] ?? 'N/A';
        $ticker = $details['ticker'] ?? 'N/A';
        $description = $details['description'] ?? 'No company description available.';
        
        $output = '<div class="jtw-content-container">';
        $output .= '<nav class="jtw-anchor-nav">';
        $output .= '<ul>';
        $output .= '<li><a href="#section-overview" class="jtw-anchor-link active">' . esc_html__('Company Overview', 'journey-to-wealth') . '</a></li>';
        $output .= '<li><a href="#section-peg-pegy-ratios" class="jtw-anchor-link">' . esc_html__('PEG/PEGY Ratios', 'journey-to-wealth') . '</a></li>';
        $output .= '<li><a href="#section-past-performance" class="jtw-anchor-link">' . esc_html__('Past Performance', 'journey-to-wealth') . '</a></li>';
        $output .= '<li><a href="#section-metric-valuation" class="jtw-anchor-link">' . esc_html__('Metric Valuation', 'journey-to-wealth') . '</a></li>';
        $output .= '<li><a href="#section-intrinsic-valuation" class="jtw-anchor-link">' . esc_html__('Intrinsic Valuation', 'journey-to-wealth') . '</a></li>';
        $output .= '</ul></nav>';
        
        $output .= '<main class="jtw-content-main">';
        $output .= '<section id="section-overview" class="jtw-content-section">';
        $output .= '<h4>' . esc_html($ticker) . ' ' . esc_html__('Company Overview', 'journey-to-wealth') . '</h4>';
        $output .= '<div class="jtw-company-overview-grid">';
        $output .= '<div class="jtw-company-description"><p>' . esc_html($description) . '</p></div>';
        $output .= '<div class="jtw-company-stats">';
        $output .= $this->create_metric_card('Current Price', $stock_price, '$');
        $output .= $this->create_metric_card('Market Capitalization', $market_cap, '$', '', true);
        $output .= $this->create_metric_card('Shares Outstanding', $shares_outstanding, '', '', true);
        $output .= '</div></div></section>';
        
        $output .= $this->build_peg_pegy_ratios_section_html($metrics_data, $stock_price, $eps);
        $output .= $this->build_past_performance_section_html($historical_data);
        $output .= $this->build_metric_valuation_section_html($metrics_data);
        $output .= $this->build_intrinsic_valuation_section_html($valuation_data, $valuation_summary);
        $output .= '</main>';
        $output .= '</div>';
        return $output;
    }
    
    private function build_peg_pegy_ratios_section_html($metrics, $stock_price, $eps) {
        $peg_data = $metrics['pegRatio'] ?? [];
        $pegy_data = $metrics['pegyRatio'] ?? [];
        $growth_default = $peg_data['defaultGrowth'] ?? 5;
        $dividend_yield_default = $pegy_data['dividendYield'] ?? 0;
    
        $output = '<section id="section-peg-pegy-ratios" class="jtw-content-section">';
        $output .= '<h4>' . esc_html__('PEG/PEGY Ratio Calculator', 'journey-to-wealth') . '</h4>';
        $output .= '<div class="jtw-metric-card jtw-interactive-card">';
        $output .= '  <div class="jtw-interactive-card-inner-grid">';
        $output .= '    <div class="jtw-peg-pegy-form">';
        $output .= '      <div class="jtw-form-row"><label for="jtw-sim-stock-price">Stock Price ($):</label><input type="number" step="0.01" id="jtw-sim-stock-price" class="jtw-sim-input" value="' . esc_attr($stock_price) . '"></div>';
        $output .= '      <div class="jtw-form-row"><label for="jtw-sim-eps">Earnings per Share ($):</label><input type="number" step="0.01" id="jtw-sim-eps" class="jtw-sim-input" value="' . esc_attr($eps) . '"></div>';
        $output .= '      <div class="jtw-form-row"><label for="jtw-sim-growth-rate">Estimated Annual Earnings Growth (%):</label><input type="number" step="0.1" id="jtw-sim-growth-rate" class="jtw-sim-input" value="' . esc_attr($growth_default) . '"></div>';
        $output .= '      <div class="jtw-form-row"><label for="jtw-sim-dividend-yield">Estimated Annual Dividend Yield (%):</label><input type="number" step="0.01" id="jtw-sim-dividend-yield" class="jtw-sim-input" value="' . esc_attr($dividend_yield_default) . '"></div>';
        $output .= '    </div>';
        $output .= '    <div class="jtw-peg-pegy-results">';
        $output .= '      <div class="jtw-result-item"><span class="jtw-result-label">PEG Ratio:</span> <span id="jtw-peg-value" class="jtw-result-value">-</span></div>';
        $output .= '      <div class="jtw-result-item"><span class="jtw-result-label">PEGY Ratio:</span> <span id="jtw-pegy-value" class="jtw-result-value">-</span></div>';
        $output .= '    </div>';
        $output .= '  </div>';
        $output .= '</div></section>';
        return $output;
    }

    private function build_metric_valuation_section_html($metrics) {
        $output = '<section id="section-metric-valuation" class="jtw-content-section">';
        $output .= '<h4>' . esc_html__('Metric Valuation', 'journey-to-wealth') . '</h4>';
        $output .= '<div class="jtw-results-grid">';
        $output .= $this->create_metric_card('P/E Ratio (TTM)', $metrics['peRatio']);
        $output .= $this->create_metric_card('P/B Ratio (MRQ)', $metrics['pbRatio']);
        $output .= $this->create_metric_card('P/S Ratio (TTM)', $metrics['psRatio']);
        $output .= $this->create_metric_card('EV/EBITDA', $metrics['evToEbitda']);
        $output .= $this->create_metric_card('EV/Sales', $metrics['evToSales']);
        $output .= $this->create_metric_card('FCF Yield', $metrics['fcfYield'], '%');
        $output .= '</div></section>';
        return $output;
    }

    private function build_past_performance_section_html($historical_data) {
        $unique_id = 'hist-trends-' . uniqid();
        $output = '<section id="section-past-performance" class="jtw-content-section">';
        $output .= '<h4>' . esc_html__('Past Performance', 'journey-to-wealth') . '</h4>';
        
        $output .= '<div class="jtw-period-toggle">';
        $output .= '<button class="jtw-period-button active" data-period="annual">Annual</button>';
        $output .= '<button class="jtw-period-button" data-period="quarterly">Quarterly</button>';
        $output .= '</div>';
        
        $output .= '<div class="jtw-historical-charts-grid" id="' . esc_attr($unique_id) . '">';
        
        $chart_configs = [
            'price' => ['title' => 'Price History (10Y)', 'type' => 'line', 'prefix' => '$'],
            'revenue' => ['title' => 'Revenue', 'type' => 'bar', 'prefix' => '$'],
            'net_income' => ['title' => 'Net Income', 'type' => 'bar', 'prefix' => '$'],
            'ebitda' => ['title' => 'EBITDA', 'type' => 'bar', 'prefix' => '$'],
            'eps' => ['title' => 'EPS', 'type' => 'bar', 'prefix' => '$'],
            'cash_amount' => ['title' => 'Dividend Per Share', 'type' => 'bar', 'prefix' => '$'],
            'fcf' => ['title' => 'Free Cash Flow', 'type' => 'bar', 'prefix' => '$'],
            'cash_and_debt' => ['title' => 'Cash & Debt', 'type' => 'bar', 'prefix' => '$'],
            'shares' => ['title' => 'Shares Outstanding', 'type' => 'bar', 'prefix' => ''],
        ];

        foreach($chart_configs as $key => $config) {
            $annual_data = $historical_data['annual'][$key] ?? [];
            $quarterly_data = $historical_data['quarterly'][$key] ?? [];
    
            // Determine if there's data to show
            $has_data = false;
            if (!empty($annual_data)) {
                if (isset($annual_data['datasets'])) { // Stacked chart
                    $has_data = !empty($annual_data['datasets'][0]['data']);
                } else { // Simple chart
                    $has_data = !empty($annual_data['data']);
                }
            }
            if (!$has_data && !empty($quarterly_data)) {
                 if (isset($quarterly_data['datasets'])) { // Stacked chart
                    $has_data = !empty($quarterly_data['datasets'][0]['data']);
                } else { // Simple chart
                    $has_data = !empty($quarterly_data['data']);
                }
            }
    
            // If no data for either period, skip rendering this chart item
            if (!$has_data) {
                continue;
            }

            $chart_id = 'chart-' . strtolower(str_replace(' ', '-', $key)) . '-' . uniqid();
            $output .= '<div class="jtw-chart-item">';
            $output .= '<h5>' . esc_html($config['title']) . '</h5>';
            $output .= '<canvas id="' . esc_attr($chart_id) . '"></canvas>';
            $output .= "<script type='application/json' class='jtw-chart-data' 
                            data-chart-id='" . esc_attr($chart_id) . "' 
                            data-chart-type='" . esc_attr($config['type']) . "'
                            data-prefix='" . esc_attr($config['prefix']) . "'
                            data-annual='" . esc_attr(json_encode($annual_data)) . "'
                            data-quarterly='" . esc_attr(json_encode($quarterly_data)) . "'>"
                       . "</script>";
            $output .= '</div>';
        }
        
        $output .= '</div>';
        $output .= '</section>';
        return $output;
    }

    private function build_intrinsic_valuation_section_html($valuation_data, $summary) {
        $output = '<section id="section-intrinsic-valuation" class="jtw-content-section">';
        $output .= '<h4>' . esc_html__('Share Price vs Fair Value', 'journey-to-wealth') . '</h4>';

        if ($summary['fair_value'] > 0) {
            $output .= '<div class="jtw-valuation-chart-container" id="jtw-valuation-chart-container"
                             data-current-price="' . esc_attr($summary['current_price']) . '"
                             data-fair-value="' . esc_attr($summary['fair_value']) . '"
                             data-percentage-diff="' . esc_attr($summary['percentage_diff']) . '">
                             <div class="jtw-valuation-range-container">
                                <div class="jtw-range-undervalued"></div>
                                <div class="jtw-range-fair"></div>
                                <div class="jtw-range-overvalued"></div>
                             </div>
                            <canvas id="jtw-valuation-chart"></canvas>
                        </div>';
        } else {
            $output .= '<p>' . esc_html__('Not enough data to calculate an average fair value.', 'journey-to-wealth') . '</p>';
        }

        $output .= '<div class="jtw-valuation-summary-box-container">';
        foreach ($valuation_data as $model_name => $result) {
            if (is_wp_error($result)) {
                continue; // Skip failed models
            }
            $modal_id = 'modal-' . sanitize_key($model_name);
            $output .= '<div class="jtw-valuation-summary-box">';
            $output .= '    <span class="jtw-summary-model-name">' . esc_html($model_name) . '</span>';
            $output .= '    <span class="jtw-summary-fair-value">$' . esc_html($result['intrinsic_value_per_share']) . '</span>';
            $output .= '    <button class="jtw-modal-trigger" data-modal-target="#' . $modal_id . '">' . esc_html__('View Assumptions', 'journey-to-wealth') . '</button>';
            $output .= '</div>';
            
            // Modal Content for this model
            $output .= '<div id="' . $modal_id . '" class="jtw-modal">';
            $output .= '  <div class="jtw-modal-content">';
            $output .= '    <span class="jtw-modal-close">&times;</span>';
            $output .= '    <h4>' . esc_html($model_name) . ' Assumptions</h4>';
            $output .= '    <ul>';
            if(isset($result['log_messages']) && is_array($result['log_messages'])) {
                foreach($result['log_messages'] as $log) {
                    $output .= '<li>' . esc_html($log) . '</li>';
                }
            }
            $output .= '    </ul>';
            $output .= '  </div>';
            $output .= '</div>';
        }
        $output .= '</div>'; // Close summary-box-container
        $output .= '<div class="jtw-modal-overlay"></div>'; // Single overlay for all modals
        $output .= '</section>';
        return $output;
    }

    private function create_metric_card($title, $value, $prefix = '', $custom_class = '', $use_large_number_format = false) {
        $formatted_value = 'N/A'; // Default value
    
        if (is_numeric($value)) {
            if ($use_large_number_format) {
                $final_prefix = ($title === 'Shares Outstanding') ? '' : $prefix;
                $formatted_value = $this->format_large_number($value, $final_prefix);
            } else {
                // Standard number format
                $temp_val = number_format((float)$value, 2);
                if ($prefix === '$') {
                    $formatted_value = $prefix . $temp_val;
                } elseif ($prefix === '%') {
                    $formatted_value = $temp_val . $prefix;
                } else {
                    $formatted_value = $temp_val;
                }
            }
        } elseif (!empty($value)) {
            // It's not a number but it's not empty, so use the string value directly.
            // This handles cases like "N/A" or "N/A - No Data...".
            $formatted_value = $value;
        }
    
        return '<div class="jtw-metric-card ' . esc_attr($custom_class) . '">
                    <h3 class="jtw-metric-title">' . esc_html($title) . '</h3>
                    <p class="jtw-metric-value">' . esc_html($formatted_value) . '</p>
                </div>';
    }

    public function ajax_fetch_analyzer_data() {
        check_ajax_referer( 'jtw_analyzer_nonce_action', 'analyzer_nonce' );

        $ticker = isset($_POST['ticker']) ? sanitize_text_field(strtoupper($_POST['ticker'])) : '';
        if (empty($ticker)) { wp_send_json_error(['message' => 'No ticker provided.']); return; }

        $api_key = get_option( 'jtw_api_key' );
        if ( empty( $api_key ) ) { wp_send_json_error(['message' => 'API Key not configured.']); return; }

        $polygon_client = new Polygon_Client( $api_key );
        $calculator = new Journey_To_Wealth_Key_Metrics_Calculator();

        // Fetch all data points
        $details = $polygon_client->get_ticker_details($ticker);
        if (is_wp_error($details)) { wp_send_json_error(['message' => 'API Error (Ticker Details): ' . $details->get_error_message()]); return; }
        
        $prev_close_data = $polygon_client->get_previous_close($ticker);
        if (is_wp_error($prev_close_data)) { wp_send_json_error(['message' => 'API Error (Previous Close): ' . $prev_close_data->get_error_message()]); return; }
        
        $financials_annual_raw = $polygon_client->get_financials($ticker, $details, 'annual');
        if (is_wp_error($financials_annual_raw)) { wp_send_json_error(['message' => 'API Error (Annual Financials): ' . $financials_annual_raw->get_error_message()]); return; }
        
        $financials_quarterly_raw = $polygon_client->get_financials($ticker, $details, 'quarterly');
        if (is_wp_error($financials_quarterly_raw)) { $financials_quarterly_raw = []; }
        
        $historical_prices_raw = $polygon_client->get_daily_aggregates($ticker);
        if (is_wp_error($historical_prices_raw)) { $historical_prices_raw = []; }

        $dividends_raw = $polygon_client->get_dividends($ticker);
        if (is_wp_error($dividends_raw)) { $dividends_raw = []; }

        // Correctly extract the 'results' array from the raw API response.
        $financials_annual = !is_wp_error($financials_annual_raw) && isset($financials_annual_raw) ? $financials_annual_raw : [];
        $financials_quarterly = !is_wp_error($financials_quarterly_raw) && isset($financials_quarterly_raw) ? $financials_quarterly_raw : [];
        $dividends = !is_wp_error($dividends_raw) && isset($dividends_raw) ? $dividends_raw : [];
        $historical_prices = !is_wp_error($historical_prices_raw) && isset($historical_prices_raw) ? $historical_prices_raw : [];
        
        // --- Process Core & Relative Metrics Data ---
        $stock_price = $prev_close_data['c'] ?? 0;
        $market_cap = $details['market_cap'] ?? 0;
        $shares_outstanding = $details['share_class_shares_outstanding'] ?? 0;
        if (empty($shares_outstanding) && !empty($market_cap) && $stock_price > 0) { $shares_outstanding = $market_cap / $stock_price; }
        
        if (empty($stock_price) || empty($market_cap) || empty($shares_outstanding)) {
            wp_send_json_error(['message' => 'Core data missing from API response.']);
            return;
        }

        $latest_financials = !empty($financials_annual) ? $financials_annual[0] : null;
        $latest_income_statement = $latest_financials['financials']['income_statement'] ?? [];
        $latest_balance_sheet = $latest_financials['financials']['balance_sheet'] ?? [];

        $eps = $this->find_financial_value($latest_income_statement, 'diluted_earnings_per_share');
        $revenue = $this->find_financial_value($latest_income_statement, 'revenues');
        $ebitda = $calculator->calculate_ebitda($latest_financials);
        $book_value = $this->find_financial_value($latest_balance_sheet, 'equity');

        $metrics_data = [
            'peRatio' => $calculator->calculate_pe_ratio($stock_price, $eps),
            'pbRatio' => $calculator->calculate_pb_ratio($market_cap, $book_value),
            'psRatio' => $calculator->calculate_ps_ratio($market_cap, $revenue),
            'evToEbitda' => $calculator->calculate_ev_ebitda($calculator->calculate_enterprise_value($market_cap, $financials_annual), $ebitda),
            'evToSales' => $calculator->calculate_ev_sales($calculator->calculate_enterprise_value($market_cap, $financials_annual), $revenue),
            'fcfYield' => $calculator->calculate_fcf_yield($financials_annual, $market_cap),
            'pegRatio' => [
                'pe' => is_numeric($calculator->calculate_pe_ratio($stock_price, $eps)) ? $calculator->calculate_pe_ratio($stock_price, $eps) : null,
                'defaultGrowth' => is_numeric($calculator->calculate_historical_eps_growth($financials_annual)) ? $calculator->calculate_historical_eps_growth($financials_annual) * 100 : null,
            ],
            'pegyRatio' => [
                'pe' => is_numeric($calculator->calculate_pe_ratio($stock_price, $eps)) ? $calculator->calculate_pe_ratio($stock_price, $eps) : null,
                'dividendYield' => isset($details['dividend_yield']) && is_numeric($details['dividend_yield']) ? (float)$details['dividend_yield'] * 100 : 0,
                'defaultGrowth' => is_numeric($calculator->calculate_historical_eps_growth($financials_annual)) ? $calculator->calculate_historical_eps_growth($financials_annual) * 100 : null,
            ]
        ];
        
        // --- Create Master Labels for X-Axis Standardization ---
        $all_reports_annual = array_merge($financials_annual, $dividends);
        $all_reports_quarterly = array_merge($financials_quarterly, $dividends);
        
        $annual_labels = [];
        $quarterly_labels = [];
        
        foreach ($all_reports_annual as $report) {
            if (isset($report['fiscal_year'])) {
                $annual_labels[] = $report['fiscal_year'];
            } elseif (isset($report['ex_dividend_date'])) {
                $annual_labels[] = substr($report['ex_dividend_date'], 0, 4);
            }
        }
        
        foreach ($all_reports_quarterly as $report) {
             if (isset($report['fiscal_period'], $report['fiscal_year'])) {
                $year_short = substr($report['fiscal_year'], -2);
                $quarterly_labels[] = $report['fiscal_period'] . "'" . $year_short;
            } elseif (isset($report['ex_dividend_date'])) {
                 $date = new DateTime($report['ex_dividend_date']);
                 $quarter = 'Q' . ceil($date->format('n') / 3);
                 $year_short = $date->format('y');
                 $quarterly_labels[] = $quarter . "'" . $year_short;
            }
        }

        $master_labels_annual = array_unique($annual_labels);
        sort($master_labels_annual);

        $master_labels_quarterly = array_unique($quarterly_labels);
        usort($master_labels_quarterly, function($a, $b) {
            $yearA = (int)substr($a, 2);
            $quarterA = (int)substr($a, 1, 1);
            $yearB = (int)substr($b, 2);
            $quarterB = (int)substr($b, 1, 1);
            if ($yearA != $yearB) {
                return $yearA <=> $yearB;
            }
            return $quarterA <=> $quarterB;
        });

        // --- Process data for Historical Trends charts ---
        $historical_data = [
            'annual' => [
                'price' => $this->process_price_data($historical_prices),
                'revenue' => $this->process_financial_chart_data($financials_annual, $master_labels_annual, 'revenues', 'income_statement'),
                'net_income' => $this->process_financial_chart_data($financials_annual, $master_labels_annual, 'net_income_loss', 'income_statement'),
                'ebitda' => $this->process_ebitda_chart_data($financials_annual, $master_labels_annual),
                'eps' => $this->process_financial_chart_data($financials_annual, $master_labels_annual, 'diluted_earnings_per_share', 'income_statement'),
                'cash_amount' => $this->process_dividend_chart_data($dividends, $master_labels_annual, 'annual'),
                'fcf' => $this->process_fcf_chart_data($financials_annual, $master_labels_annual),
                'cash_and_debt' => $this->process_cash_debt_chart_data($financials_annual, $master_labels_annual),
                'shares' => $this->process_financial_chart_data($financials_annual, $master_labels_annual, 'diluted_average_shares', 'income_statement'),
            ],
            'quarterly' => [
                'price' => $this->process_price_data($historical_prices),
                'revenue' => $this->process_financial_chart_data($financials_quarterly, $master_labels_quarterly, 'revenues', 'income_statement'),
                'net_income' => $this->process_financial_chart_data($financials_quarterly, $master_labels_quarterly, 'net_income_loss', 'income_statement'),
                'ebitda' => $this->process_ebitda_chart_data($financials_quarterly, $master_labels_quarterly),
                'eps' => $this->process_financial_chart_data($financials_quarterly, $master_labels_quarterly, 'diluted_earnings_per_share', 'income_statement'),
                'cash_amount' => $this->process_dividend_chart_data($dividends, $master_labels_quarterly, 'quarterly'),
                'fcf' => $this->process_fcf_chart_data($financials_quarterly, $master_labels_quarterly),
                'cash_and_debt' => $this->process_cash_debt_chart_data($financials_quarterly, $master_labels_quarterly),
                'shares' => $this->process_financial_chart_data($financials_quarterly, $master_labels_quarterly, 'diluted_average_shares', 'income_statement'),
            ]
        ];
        
        // --- Intrinsic Valuation ---
        $valuation_data = [];
        $sic_description = strtolower($details['sic_description'] ?? '');
        $is_financial_or_reit = (strpos($sic_description, 'reit') !== false) || (strpos($sic_description, 'bank') !== false) || (strpos($sic_description, 'financial') !== false);

        if (strpos($sic_description, 'reit') !== false) {
            $affo_model = new Journey_To_Wealth_AFFO_Model();
            $valuation_data['AFFO Model (REIT)'] = $affo_model->calculate($financials_annual, $details, $prev_close_data);
        }
        
        if (strpos($sic_description, 'bank') !== false || strpos($sic_description, 'financial') !== false) {
            $er_model = new Journey_To_Wealth_Excess_Return_Model();
            $valuation_data['Excess Return Model'] = $er_model->calculate($financials_annual, $details, $prev_close_data);
        }

        if (!is_wp_error($dividends) && !empty($dividends)) {
            $ddm_model = new Journey_To_Wealth_DDM_Model();
            $valuation_data['Dividend Discount Model'] = $ddm_model->calculate($dividends, $details, $prev_close_data);
        }
        
        if (!$is_financial_or_reit) {
            $dcf_model = new Journey_To_Wealth_DCF_Model();
            $valuation_data['DCF Model'] = $dcf_model->calculate($financials_annual, $details, $prev_close_data);
        }

        // --- Valuation Summary for Chart ---
        $successful_valuations = [];
        foreach($valuation_data as $result) {
            if (!is_wp_error($result) && isset($result['intrinsic_value_per_share'])) {
                $successful_valuations[] = $result['intrinsic_value_per_share'];
            }
        }
        $average_fair_value = !empty($successful_valuations) ? array_sum($successful_valuations) / count($successful_valuations) : 0;
        $percentage_diff = 0;
        if ($average_fair_value > 0) {
            $percentage_diff = (($stock_price - $average_fair_value) / $average_fair_value) * 100;
        }
        $valuation_summary = [
            'current_price' => $stock_price,
            'fair_value' => $average_fair_value,
            'percentage_diff' => $percentage_diff
        ];
        
        $html = $this->build_analyzer_html($details, $stock_price, $market_cap, $shares_outstanding, $metrics_data, $historical_data, $valuation_data, $valuation_summary, $eps);
        
        wp_send_json_success(['html' => $html]);
    }
    
    // --- Data Processing Helpers for Charts ---
    private function process_price_data($price_data) {
        if (is_wp_error($price_data) || empty($price_data)) {
            return ['labels' => [], 'data' => []];
        }
    
        $chart_data = ['labels' => [], 'data' => []];
        $price_data = array_reverse($price_data);
    
        foreach ($price_data as $day) {
            if (is_array($day) && isset($day['t']) && isset($day['c'])) {
                $chart_data['labels'][] = date('Y-m-d', $day['t'] / 1000);
                $chart_data['data'][] = (float)$day['c'];
            }
        }
        return $chart_data;
    }

    private function process_financial_chart_data($financials, $master_labels, $key, $statement_type = 'income_statement') {
        if (is_wp_error($financials) || empty($financials)) {
            return ['labels' => $master_labels, 'data' => array_fill(0, count($master_labels), 0)];
        }
    
        $data_map = [];
        foreach ($financials as $report) {
            $label = '';
            if (isset($report['fiscal_period']) && isset($report['fiscal_year'])) {
                $year_short = substr($report['fiscal_year'], -2);
                $label = $report['fiscal_period'] . "'" . $year_short;
            } elseif (isset($report['fiscal_year'])) {
                $label = $report['fiscal_year'];
            }
    
            if (!empty($label)) {
                $statement_section = $report['financials'][$statement_type] ?? [];
                $value = $this->find_financial_value($statement_section, $key);
                if ($value !== null) {
                    $data_map[$label] = $value;
                }
            }
        }
    
        $final_data = [];
        foreach ($master_labels as $label) {
            $final_data[] = $data_map[$label] ?? 0;
        }
    
        return ['labels' => $master_labels, 'data' => $final_data];
    }
    
    private function process_fcf_chart_data($financials, $master_labels) {
        if (is_wp_error($financials) || empty($financials)) {
            return ['labels' => $master_labels, 'data' => array_fill(0, count($master_labels), 0)];
        }
    
        $data_map = [];
        foreach ($financials as $report) {
             $label = '';
            if (isset($report['fiscal_period']) && isset($report['fiscal_year'])) {
                $year_short = substr($report['fiscal_year'], -2);
                $label = $report['fiscal_period'] . "'" . $year_short;
            } elseif (isset($report['fiscal_year'])) {
                $label = $report['fiscal_year'];
            }

            if (!empty($label)) {
                $cash_flow = $report['financials']['cash_flow_statement'] ?? [];
                $op_cf = $this->find_financial_value($cash_flow, 'net_cash_flow_from_operating_activities');
                $investing_cf = $this->find_financial_value($cash_flow, 'net_cash_flow_from_investing_activities');
                if ($op_cf !== null && $investing_cf !== null) {
                    $data_map[$label] = $op_cf + $investing_cf;
                }
            }
        }

        $final_data = [];
        foreach ($master_labels as $label) {
            $final_data[] = $data_map[$label] ?? 0;
        }

        return ['labels' => $master_labels, 'data' => $final_data];
    }
    
    private function process_cash_debt_chart_data($financials, $master_labels) {
        if (is_wp_error($financials) || empty($financials)) {
            return ['labels' => $master_labels, 'datasets' => [['label' => 'Cash', 'data' => []], ['label' => 'Debt', 'data' => []]]];
        }
    
        $cash_map = [];
        $debt_map = [];
        foreach ($financials as $report) {
            $label = '';
            if (isset($report['fiscal_period']) && isset($report['fiscal_year'])) {
                $year_short = substr($report['fiscal_year'], -2);
                $label = $report['fiscal_period'] . "'" . $year_short;
            } elseif (isset($report['fiscal_year'])) {
                $label = $report['fiscal_year'];
            }
    
            if (!empty($label)) {
                $balance_sheet = $report['financials']['balance_sheet'] ?? [];
                $cash = $this->find_financial_value($balance_sheet, 'other_current_assets');
                $long_debt = $this->find_financial_value($balance_sheet, 'long_term_debt');
                $short_debt = $this->find_financial_value($balance_sheet, 'other_current_liabilities');
                if ($cash !== null) {
                    $cash_map[$label] = $cash;
                }
                if ($long_debt !== null || $short_debt !== null) {
                    $debt_map[$label] = (float)($long_debt ?? 0) + (float)($short_debt ?? 0);
                }
            }
        }
    
        $cash_data = [];
        $debt_data = [];
        foreach ($master_labels as $label) {
            $cash_data[] = $cash_map[$label] ?? 0;
            $debt_data[] = $debt_map[$label] ?? 0;
        }
    
        return ['labels' => $master_labels, 'datasets' => [['label' => 'Cash', 'data' => $cash_data], ['label' => 'Debt', 'data' => $debt_data]]];
    }

    private function process_ebitda_chart_data($financials, $master_labels) {
        if (is_wp_error($financials) || empty($financials)) {
            return ['labels' => $master_labels, 'data' => array_fill(0, count($master_labels), 0)];
        }
    
        $data_map = [];
        foreach ($financials as $report) {
            $label = '';
            if (isset($report['fiscal_period']) && isset($report['fiscal_year'])) {
                $year_short = substr($report['fiscal_year'], -2);
                $label = $report['fiscal_period'] . "'" . $year_short;
            } elseif (isset($report['fiscal_year'])) {
                $label = $report['fiscal_year'];
            }
    
            if (!empty($label)) {
                $income_statement = $report['financials']['income_statement'] ?? [];
                $operating_income = $this->find_financial_value($income_statement, 'operating_income_loss');
                $depreciation_amortization = $this->find_financial_value($income_statement, 'depreciation_and_amortization');
                if (is_numeric($operating_income) && is_numeric($depreciation_amortization)) {
                    $data_map[$label] = $operating_income + $depreciation_amortization;
                }
            }
        }
    
        $final_data = [];
        foreach ($master_labels as $label) {
            $final_data[] = $data_map[$label] ?? 0;
        }
    
        return ['labels' => $master_labels, 'data' => $final_data];
    }

    private function process_dividend_chart_data($dividends, $master_labels, $timeframe = 'annual') {
        if (is_wp_error($dividends) || empty($dividends)) {
            return ['labels' => $master_labels, 'data' => array_fill(0, count($master_labels), 0)];
        }
    
        $data_map = [];
        if ($timeframe === 'annual') {
            foreach ($dividends as $dividend) {
                if (isset($dividend['ex_dividend_date']) && isset($dividend['cash_amount'])) {
                    $year = substr($dividend['ex_dividend_date'], 0, 4);
                    if (!isset($data_map[$year])) {
                        $data_map[$year] = 0;
                    }
                    $data_map[$year] += (float)$dividend['cash_amount'];
                }
            }
        } else { // Quarterly
             foreach ($dividends as $dividend) {
                if (isset($dividend['ex_dividend_date']) && isset($dividend['cash_amount'])) {
                    $date = new DateTime($dividend['ex_dividend_date']);
                    $quarter = 'Q' . ceil($date->format('n') / 3);
                    $year_short = $date->format('y');
                    $label = $quarter . "'" . $year_short;
                     if (!isset($data_map[$label])) {
                        $data_map[$label] = 0;
                    }
                    $data_map[$label] += (float)$dividend['cash_amount'];
                }
            }
        }

        $final_data = [];
        foreach ($master_labels as $label) {
            $final_data[] = $data_map[$label] ?? 0;
        }
    
        return ['labels' => $master_labels, 'data' => $final_data];
    }
}
