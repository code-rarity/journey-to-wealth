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

    private function find_financial_value($financial_statement_section, $concept_name) {
        if (!is_array($financial_statement_section)) return null;
        foreach ($financial_statement_section as $item) {
            if (isset($item['concept']) && strcasecmp($item['concept'], $concept_name) === 0 && isset($item['value'])) {
                return is_numeric($item['value']) ? (float)$item['value'] : null;
            }
        }
        return null;
    }
    
    private function format_large_number($number) {
        if (!is_numeric($number) || $number == 0) return '$0';
        $abs_number = abs($number);
        if ($abs_number >= 1.0e+12) return '$' . round($number / 1.0e+12, 2) . 'T';
        if ($abs_number >= 1.0e+9) return '$' . round($number / 1.0e+9, 2) . 'B';
        if ($abs_number >= 1.0e+6) return '$' . round($number / 1.0e+6, 2) . 'M';
        return '$' . number_format($number, 0);
    }

    private function build_analyzer_html($details, $stock_price, $market_cap, $shares_outstanding, $metrics_data, $historical_data) {
        $name = $details['name'] ?? 'N/A';
        $ticker = $details['ticker'] ?? 'N/A';
        $description = $details['description'] ?? 'No company description available.';
        
        $output = '<div class="jtw-content-container">';
        $output .= '<nav class="jtw-anchor-nav">';
        $output .= '<ul>';
        $output .= '<li><a href="#section-overview" class="jtw-anchor-link active">' . esc_html__('Company Overview', 'journey-to-wealth') . '</a></li>';
        $output .= '<li><a href="#section-relative-valuation" class="jtw-anchor-link">' . esc_html__('Relative Valuation', 'journey-to-wealth') . '</a></li>';
        $output .= '<li><a href="#section-historical-trends" class="jtw-anchor-link">' . esc_html__('Historical Trends', 'journey-to-wealth') . '</a></li>';
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
        
        $output .= $this->build_relative_valuation_section_html($metrics_data);
        $output .= $this->build_historical_trends_section_html($historical_data);
        $output .= '</main>';
        $output .= '</div>';
        return $output;
    }

    private function build_relative_valuation_section_html($metrics) {
        $output = '<section id="section-relative-valuation" class="jtw-content-section">';
        $output .= '<h4>' . esc_html__('Relative Valuation', 'journey-to-wealth') . '</h4>';
        $output .= '<div class="jtw-results-grid">';
        $output .= $this->create_metric_card('P/E Ratio (TTM)', $metrics['peRatio']);
        $output .= $this->create_metric_card('P/B Ratio (MRQ)', $metrics['pbRatio']);
        $output .= $this->create_metric_card('P/S Ratio (TTM)', $metrics['psRatio']);
        $output .= $this->create_metric_card('EV/EBITDA', $metrics['evToEbitda']);
        $output .= $this->create_metric_card('EV/Sales', $metrics['evToSales']);
        $output .= $this->create_metric_card('FCF Yield', $metrics['fcfYield'], '%');
        $output .= $this->create_peg_pegy_card($metrics['pegRatio'], $metrics['pegyRatio']);
        $output .= '</div></section>';
        return $output;
    }

    private function build_historical_trends_section_html($historical_data) {
        $unique_id = 'hist-trends-' . uniqid();
        $output = '<section id="section-historical-trends" class="jtw-content-section">';
        $output .= '<h4>' . esc_html__('Historical Trends', 'journey-to-wealth') . '</h4>';
        
        $output .= '<div class="jtw-period-toggle">';
        $output .= '<button class="jtw-period-button active" data-period="annual">Annual</button>';
        $output .= '<button class="jtw-period-button" data-period="quarterly">Quarterly</button>';
        $output .= '</div>';
        
        $output .= '<div class="jtw-historical-charts-grid" id="' . esc_attr($unique_id) . '">';
        
        $chart_configs = [
            'price' => ['title' => 'Price History (5Y)', 'type' => 'line', 'prefix' => '$'],
            'revenue' => ['title' => 'Revenue', 'type' => 'bar', 'prefix' => '$'],
            'net_income' => ['title' => 'Net Income', 'type' => 'bar', 'prefix' => '$'],
            'ebitda' => ['title' => 'EBITDA', 'type' => 'bar', 'prefix' => '$'],
            'eps' => ['title' => 'EPS', 'type' => 'bar', 'prefix' => '$'],
            'fcf' => ['title' => 'Free Cash Flow', 'type' => 'bar', 'prefix' => '$'],
            'cash_and_debt' => ['title' => 'Cash & Debt', 'type' => 'bar_stacked', 'prefix' => '$'],
            'shares' => ['title' => 'Shares Outstanding', 'type' => 'bar', 'prefix' => ''],
        ];

        foreach($chart_configs as $key => $config) {
            $chart_id = 'chart-' . strtolower(str_replace(' ', '-', $key)) . '-' . uniqid();
            $output .= '<div class="jtw-chart-item">';
            $output .= '<h5>' . esc_html($config['title']) . '</h5>';
            $output .= '<canvas id="' . esc_attr($chart_id) . '"></canvas>';
            $output .= "<script type='application/json' class='jtw-chart-data' 
                            data-chart-id='" . esc_attr($chart_id) . "' 
                            data-chart-type='" . esc_attr($config['type']) . "'
                            data-prefix='" . esc_attr($config['prefix']) . "'
                            data-annual='" . esc_attr(json_encode($historical_data['annual'][$key] ?? [])) . "'
                            data-quarterly='" . esc_attr(json_encode($historical_data['quarterly'][$key] ?? [])) . "'>"
                       . "</script>";
            $output .= '</div>';
        }
        
        $output .= '</div>';
        $output .= '</section>';
        return $output;
    }

    private function create_metric_card($title, $value, $prefix = '', $custom_class = '', $use_large_number_format = false) {
        $formatted_value = 'N/A';
        if ($use_large_number_format && is_numeric($value)) {
            $formatted_value = $this->format_large_number($value);
        } elseif (is_numeric($value)) {
            $formatted_value = number_format((float)$value, 2);
        }
        
        if ($prefix === '$' && is_numeric($value)) {
            $formatted_value = $prefix . $formatted_value;
        } elseif ($prefix === '%' && is_numeric($value)) {
             $formatted_value .= $prefix;
        }

        return '<div class="jtw-metric-card ' . esc_attr($custom_class) . '">
                    <h3 class="jtw-metric-title">' . esc_html($title) . '</h3>
                    <p class="jtw-metric-value">' . esc_html($formatted_value) . '</p>
                </div>';
    }
    
    private function create_peg_pegy_card($peg_data, $pegy_data) {
        $growth_default = $peg_data['defaultGrowth'] ?? 5;
        $html = '<div class="jtw-metric-card jtw-interactive-card" 
                     data-pe-value="' . esc_attr($peg_data['pe'] ?? '') . '"
                     data-dividend-yield="' . esc_attr($pegy_data['dividendYield'] ?? '') . '">
                    <h3 class="jtw-metric-title">PEG & PEGY Ratios</h3>
                    <div class="jtw-growth-input-group">
                        <label for="jtw-peg-growth-rate">Enter Growth Rate (%):</label>
                        <input type="number" id="jtw-peg-growth-rate" class="jtw-growth-input" value="' . esc_attr($growth_default) . '">
                        <small>Default is 5yr historical EPS growth.</small>
                    </div>
                    <div class="jtw-sub-metric-group">
                        <p><strong>PEG Ratio:</strong> <span id="jtw-peg-value" class="jtw-metric-value">-</span></p>
                        <p><strong>PEGY Ratio:</strong> <span id="jtw-pegy-value" class="jtw-metric-value">-</span></p>
                    </div>
                </div>';
        return $html;
    }

    public function ajax_fetch_analyzer_data() {
        check_ajax_referer( 'jtw_analyzer_nonce_action', 'analyzer_nonce' );

        $ticker = isset($_POST['ticker']) ? sanitize_text_field(strtoupper($_POST['ticker'])) : '';
        if (empty($ticker)) { wp_send_json_error(['message' => 'No ticker provided.']); return; }

        $api_key = get_option( 'jtw_api_key' );
        if ( empty( $api_key ) ) { wp_send_json_error(['message' => 'API Key not configured.']); return; }

        $api_client = new Polygon_Client( $api_key );
        $calculator = new Journey_To_Wealth_Key_Metrics_Calculator();

        // Fetch all data points
        $details = $api_client->get_ticker_details($ticker);
        if (is_wp_error($details)) { wp_send_json_error(['message' => 'API Error (Ticker Details): ' . $details->get_error_message()]); return; }
        
        $prev_close_data = $api_client->get_previous_close($ticker);
        if (is_wp_error($prev_close_data)) { wp_send_json_error(['message' => 'API Error (Previous Close): ' . $prev_close_data->get_error_message()]); return; }
        
        $financials_annual = $api_client->get_financials($ticker, 'annual');
        if (is_wp_error($financials_annual)) { wp_send_json_error(['message' => 'API Error (Annual Financials): ' . $financials_annual->get_error_message()]); return; }
        
        $financials_quarterly = $api_client->get_financials($ticker, 'quarterly');
        if (is_wp_error($financials_quarterly)) { $financials_quarterly = []; } // Degrade gracefully
        
        $historical_prices = $api_client->get_daily_aggregates($ticker);
        if (is_wp_error($historical_prices)) { $historical_prices = []; }
        
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

        $eps = $this->find_financial_value($latest_income_statement, 'Diluted Earnings Per Share');
        $revenue = $this->find_financial_value($latest_income_statement, 'Revenues');
        $ebitda = $this->find_financial_value($latest_income_statement, 'EBITDA');
        $book_value = $this->find_financial_value($latest_balance_sheet, 'Equity');

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
        
        // --- Process data for Historical Trends charts ---
        $historical_data = [
            'annual' => [
                'price' => $this->process_price_data($historical_prices),
                'revenue' => $this->process_financial_chart_data($financials_annual, 'Revenues'),
                'net_income' => $this->process_financial_chart_data($financials_annual, 'NetIncomeLoss'),
                'ebitda' => $this->process_financial_chart_data($financials_annual, 'EBITDA'),
                'eps' => $this->process_financial_chart_data($financials_annual, 'EarningsPerShareDiluted'),
                'fcf' => $this->process_fcf_chart_data($financials_annual),
                'cash_and_debt' => $this->process_cash_debt_chart_data($financials_annual),
                'shares' => $this->process_financial_chart_data($financials_annual, 'WeightedAverageSharesOutstandingDiluted', false),
            ],
            'quarterly' => [
                'revenue' => $this->process_financial_chart_data($financials_quarterly, 'Revenues'),
                'net_income' => $this->process_financial_chart_data($financials_quarterly, 'NetIncomeLoss'),
                'ebitda' => $this->process_financial_chart_data($financials_quarterly, 'EBITDA'),
                'eps' => $this->process_financial_chart_data($financials_quarterly, 'EarningsPerShareDiluted'),
                'fcf' => $this->process_fcf_chart_data($financials_quarterly),
                'cash_and_debt' => $this->process_cash_debt_chart_data($financials_quarterly),
                'shares' => $this->process_financial_chart_data($financials_quarterly, 'WeightedAverageSharesOutstandingDiluted', false),
            ]
        ];
        
        $html = $this->build_analyzer_html($details, $stock_price, $market_cap, $shares_outstanding, $metrics_data, $historical_data);
        
        wp_send_json_success(['html' => $html]);
    }
    
    // --- Data Processing Helpers for Charts ---
    private function process_price_data($price_data) {
        if (is_wp_error($price_data) || empty($price_data)) return [];
        $chart_data = ['labels' => [], 'data' => []];
        $yearly_data = [];
        foreach ($price_data as $day) {
            $year = date('Y', $day['t'] / 1000);
            $yearly_data[$year] = $day['c']; // Keep the last closing price for each year
        }
        foreach ($yearly_data as $year => $price) {
            $chart_data['labels'][] = $year;
            $chart_data['data'][] = $price;
        }
        return $chart_data;
    }

    private function process_financial_chart_data($financials, $label) {
        if (is_wp_error($financials) || empty($financials)) return [];
        $chart_data = ['labels' => [], 'data' => []];
        $financials = array_reverse($financials); // Oldest to newest
        foreach ($financials as $report) {
            $income_statement = $report['financials']['income_statement'] ?? [];
            $value = $this->find_financial_value($income_statement, $label);
            if ($value !== null) {
                $chart_data['labels'][] = $report['fiscal_year'] ?? substr($report['filing_date'], 0, 7);
                $chart_data['data'][] = $value;
            }
        }
        return $chart_data;
    }

    private function process_fcf_chart_data($financials) {
        if (is_wp_error($financials) || empty($financials)) return [];
        $chart_data = ['labels' => [], 'data' => []];
        $financials = array_reverse($financials);
        foreach ($financials as $report) {
            $cash_flow = $report['financials']['cash_flow_statement'] ?? [];
            $op_cf = $this->find_financial_value($cash_flow, 'NetCashProvidedByUsedInOperatingActivities');
            $investing_cf = $this->find_financial_value($cash_flow, 'NetCashProvidedByUsedInInvestingActivities');
            if ($op_cf !== null && $investing_cf !== null) {
                $chart_data['labels'][] = $report['fiscal_year'] ?? substr($report['filing_date'], 0, 7);
                $chart_data['data'][] = $op_cf + $investing_cf;
            }
        }
        return $chart_data;
    }

    private function process_cash_debt_chart_data($financials) {
        if (is_wp_error($financials) || empty($financials)) return [];
        $chart_data = ['labels' => [], 'datasets' => [['label' => 'Cash', 'data' => []], ['label' => 'Debt', 'data' => []]]];
        $financials = array_reverse($financials);
        foreach ($financials as $report) {
            $balance_sheet = $report['financials']['balance_sheet'] ?? [];
            $cash = $this->find_financial_value($balance_sheet, 'CashAndCashEquivalentsAtCarryingValue');
            $long_debt = $this->find_financial_value($balance_sheet, 'LongTermDebt');
            $short_debt = $this->find_financial_value($balance_sheet, 'ShortTermDebt');
            if ($cash !== null && ($long_debt !== null || $short_debt !== null)) {
                $chart_data['labels'][] = $report['fiscal_year'] ?? substr($report['filing_date'], 0, 7);
                $chart_data['datasets'][0]['data'][] = $cash;
                $chart_data['datasets'][1]['data'][] = (float)($long_debt ?? 0) + (float)($short_debt ?? 0);
            }
        }
        return $chart_data;
    }

}
