<?php
/**
 * The public-facing functionality of the plugin.
 * This class handles the shortcode and AJAX request for the analyzer tool.
 * **REFACTORED for Alpha Vantage API**
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
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/api/class-alpha-vantage-client.php';
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
        wp_enqueue_script( 'chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js', array(), 'jquery', '3.9.1', true );
        wp_enqueue_script( 'chartjs-adapter-date-fns', 'https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js', array('chartjs'), '1.1.0', true );
        wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'assets/js/public-scripts.js', array( 'jquery', 'chartjs', 'chartjs-adapter-date-fns' ), $this->version, true );
        
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

    public function render_header_lookup_shortcode( $atts ) {
        if (!is_user_logged_in()) return '';
        $unique_id = 'jtw-header-lookup-' . uniqid();
        $output = '<div class="jtw-header-lookup-form jtw-header-lookup-container" id="' . esc_attr($unique_id) . '">';
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

    public function render_mobile_header_lookup_shortcode( $atts ) {
        if (!is_user_logged_in()) return '';
        $unique_id = 'jtw-mobile-header-lookup-' . uniqid();
        $output = '<div class="jtw-header-lookup-form jtw-mobile-header-lookup-container" id="' . esc_attr($unique_id) . '">';
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

    public function ajax_symbol_search() {
        check_ajax_referer('jtw_symbol_search_nonce_action', 'jtw_symbol_search_nonce');
    
        $keywords = isset($_POST['keywords']) ? sanitize_text_field($_POST['keywords']) : '';
        if (empty($keywords)) {
            wp_send_json_error(['matches' => []]);
            return;
        }
    
        $api_key = get_option('jtw_av_api_key');
        if (empty($api_key)) {
            wp_send_json_error(['message' => 'API Key not configured.']);
            return;
        }
    
        $av_client = new Alpha_Vantage_Client($api_key);
        $results = $av_client->search_symbols($keywords);
    
        if (is_wp_error($results) || empty($results)) {
            wp_send_json_success(['matches' => []]);
            return;
        }
    
        $matches = array_map(function($item) {
            return [
                'ticker'   => $item['1. symbol'],
                'name'     => $item['2. name'],
                'exchange' => $item['4. region'],
                'locale'   => strtolower(substr($item['8. currency'], 0, 2)),
                'icon_url' => '',
            ];
        }, $results);
    
        $limited_matches = array_slice($matches, 0, 3);
    
        wp_send_json_success(['matches' => $limited_matches]);
    }

    public function ajax_fetch_analyzer_data() {
        check_ajax_referer('jtw_analyzer_nonce_action', 'analyzer_nonce');
    
        $ticker = isset($_POST['ticker']) ? sanitize_text_field(strtoupper($_POST['ticker'])) : '';
        if (empty($ticker)) {
            wp_send_json_error(['message' => 'No ticker provided.']);
            return;
        }
    
        $api_key = get_option('jtw_av_api_key');
        if (empty($api_key)) {
            wp_send_json_error(['message' => 'API Key not configured.']);
            return;
        }
    
        $av_client = new Alpha_Vantage_Client($api_key);
    
        $overview = $av_client->get_company_overview($ticker);
        if (is_wp_error($overview) || empty($overview['Symbol'])) {
            wp_send_json_error(['message' => 'Could not retrieve company overview. Please check the ticker symbol or your API key.']);
            return;
        }
    
        $quote = $av_client->get_global_quote($ticker);
        $income_statement = $av_client->get_income_statement($ticker);
        $balance_sheet = $av_client->get_balance_sheet($ticker);
        $cash_flow = $av_client->get_cash_flow_statement($ticker);
        $earnings = $av_client->get_earnings_data($ticker);
        $daily_data = $av_client->get_daily_adjusted($ticker);
    
        $latest_price = !is_wp_error($quote) ? (float)($quote['05. price'] ?? 0) : 0;
        $details = [
            'name'        => $overview['Name'] ?? 'N/A',
            'ticker'      => $overview['Symbol'] ?? $ticker,
            'description' => $overview['Description'] ?? 'No company description available.',
        ];
        $market_cap = (float)($overview['MarketCapitalization'] ?? 0);
        $shares_outstanding = (float)($overview['SharesOutstanding'] ?? 0);
        $eps = (float)($overview['EPS'] ?? 0);
    
        $pe_ratio = $overview['PERatio'] !== 'None' ? (float)$overview['PERatio'] : 0;
        $peg_ratio = $overview['PEGRatio'] !== 'None' ? (float)$overview['PEGRatio'] : 0;

        $growth_rate = 0;
        if ($pe_ratio > 0 && $peg_ratio > 0) {
            $growth_rate = ($pe_ratio / $peg_ratio);
        }

        $metrics_data = [
            'peRatio'    => $pe_ratio > 0 ? $pe_ratio : 'N/A',
            'pbRatio'    => $overview['PriceToBookRatio'] !== 'None' ? (float)$overview['PriceToBookRatio'] : 'N/A',
            'psRatio'    => $overview['PriceToSalesRatioTTM'] !== 'None' ? (float)$overview['PriceToSalesRatioTTM'] : 'N/A',
            'evToEbitda' => $overview['EVToEBITDA'] !== 'None' ? (float)$overview['EVToEBITDA'] : 'N/A',
            'evToSales'  => $overview['EVToSales'] !== 'None' ? (float)$overview['EVToSales'] : 'N/A',
            'fcfYield'   => $this->calculate_av_fcf_yield($cash_flow, $market_cap),
            'pegRatio'   => [
                'pe' => $pe_ratio,
                'defaultGrowth' => $growth_rate
            ],
            'pegyRatio'  => [
                'pe' => $pe_ratio,
                'dividendYield' => (float)($overview['DividendYield'] ?? 0) * 100,
                'defaultGrowth' => $growth_rate
            ]
        ];
    
        $historical_data = $this->process_av_historical_data($daily_data, $income_statement, $balance_sheet, $cash_flow, $earnings);

        $valuation_data = [];
        $valuation_summary = [
            'current_price' => $latest_price,
            'fair_value' => 0, 
            'percentage_diff' => 0
        ];
    
        $html = $this->build_analyzer_html($details, $latest_price, $market_cap, $shares_outstanding, $metrics_data, $historical_data, $valuation_data, $valuation_summary, $eps);
    
        wp_send_json_success(['html' => $html]);
    }
    
    private function calculate_av_fcf_yield($cash_flow_data, $market_cap) {
        if (is_wp_error($cash_flow_data) || empty($cash_flow_data['annualReports']) || $market_cap <= 0) {
            return 'N/A';
        }
        $latest_report = $cash_flow_data['annualReports'][0] ?? null;
        if (!$latest_report) return 'N/A';

        $operating_cash_flow = (float)($latest_report['operatingCashflow'] ?? 0);
        $capex = (float)($latest_report['capitalExpenditures'] ?? 0);
        $fcf = $operating_cash_flow - $capex;

        return ($fcf / $market_cap) * 100;
    }

    private function process_av_historical_data($daily_data, $income_statement, $balance_sheet, $cash_flow, $earnings) {
        $master_labels_annual = $this->get_master_labels([$income_statement, $balance_sheet, $cash_flow, $earnings], 'annual');
        $master_labels_quarterly = $this->get_master_labels([$income_statement, $balance_sheet, $cash_flow, $earnings], 'quarterly');

        $annual = [
            'price' => $this->process_av_price_data($daily_data),
            'revenue' => $this->extract_av_financial_data($income_statement, 'totalRevenue', 'annual', $master_labels_annual),
            'net_income' => $this->extract_av_financial_data($income_statement, 'netIncome', 'annual', $master_labels_annual),
            'ebitda' => $this->extract_av_financial_data($income_statement, 'ebitda', 'annual', $master_labels_annual),
            'fcf' => $this->extract_av_fcf_data($cash_flow, 'annual', $master_labels_annual),
            'cash_and_debt' => $this->extract_av_cash_and_debt_data($balance_sheet, 'annual', $master_labels_annual),
            'dividend' => $this->aggregate_av_dividend_data($daily_data, 'annual', $master_labels_annual),
            'shares_outstanding' => $this->extract_av_financial_data($balance_sheet, 'commonStockSharesOutstanding', 'annual', $master_labels_annual),
            'expenses' => $this->extract_av_expenses_data($income_statement, 'annual', $master_labels_annual),
            'eps' => $this->extract_av_earnings_data($earnings, 'annual', $master_labels_annual),
        ];
        $quarterly = [
            'price' => $this->process_av_price_data($daily_data),
            'revenue' => $this->extract_av_financial_data($income_statement, 'totalRevenue', 'quarterly', $master_labels_quarterly),
            'net_income' => $this->extract_av_financial_data($income_statement, 'netIncome', 'quarterly', $master_labels_quarterly),
            'ebitda' => $this->extract_av_financial_data($income_statement, 'ebitda', 'quarterly', $master_labels_quarterly),
            'fcf' => $this->extract_av_fcf_data($cash_flow, 'quarterly', $master_labels_quarterly),
            'cash_and_debt' => $this->extract_av_cash_and_debt_data($balance_sheet, 'quarterly', $master_labels_quarterly),
            'dividend' => $this->aggregate_av_dividend_data($daily_data, 'quarterly', $master_labels_quarterly),
            'shares_outstanding' => $this->extract_av_financial_data($balance_sheet, 'commonStockSharesOutstanding', 'quarterly', $master_labels_quarterly),
            'expenses' => $this->extract_av_expenses_data($income_statement, 'quarterly', $master_labels_quarterly),
            'eps' => $this->extract_av_earnings_data($earnings, 'quarterly', $master_labels_quarterly),
        ];

        return ['annual' => $annual, 'quarterly' => $quarterly];
    }
    
    private function get_master_labels($datasets, $type = 'annual') {
        $all_dates = [];
        $report_key = ($type === 'annual') ? 'annualReports' : 'quarterlyReports';
        $earnings_key = ($type === 'annual') ? 'annualEarnings' : 'quarterlyEarnings';
    
        foreach ($datasets as $dataset) {
            if (is_wp_error($dataset)) continue;
    
            if (isset($dataset[$report_key])) {
                foreach ($dataset[$report_key] as $report) {
                    $all_dates[] = $report['fiscalDateEnding'];
                }
            } elseif (isset($dataset[$earnings_key])) {
                foreach ($dataset[$earnings_key] as $report) {
                    $all_dates[] = $report['fiscalDateEnding'];
                }
            }
        }
    
        $unique_dates = array_unique($all_dates);
        sort($unique_dates);
        
        // **FIX:** Change slice from -10 to -15 for annual reports
        $limit = ($type === 'annual') ? -15 : -10;
        $limited_dates = array_slice($unique_dates, $limit); 
        
        $final_labels = [];
        foreach($limited_dates as $date) {
            $final_labels[] = ($type === 'annual') ? substr($date, 0, 4) : $date;
        }

        return $final_labels;
    }

    private function extract_av_financial_data($reports, $key, $type, $master_labels) {
        $data = ['labels' => $master_labels, 'data' => array_fill(0, count($master_labels), 0)];
        if (is_wp_error($reports)) return $data;
        
        $report_key = ($type === 'annual') ? 'annualReports' : 'quarterlyReports';
        if (!isset($reports[$report_key])) return $data;
        
        $data_map = [];
        foreach ($reports[$report_key] as $report) {
            $label = ($type === 'annual') ? substr($report['fiscalDateEnding'], 0, 4) : $report['fiscalDateEnding'];
            $data_map[$label] = isset($report[$key]) && is_numeric($report[$key]) ? (float)$report[$key] : 0;
        }

        foreach($master_labels as $i => $label) {
            if(isset($data_map[$label])) {
                $data['data'][$i] = $data_map[$label];
            }
        }
        return $data;
    }

    private function extract_av_fcf_data($cash_flow_data, $type, $master_labels) {
        $data = ['labels' => $master_labels, 'data' => array_fill(0, count($master_labels), 0)];
        if (is_wp_error($cash_flow_data)) return $data;
        
        $report_key = ($type === 'annual') ? 'annualReports' : 'quarterlyReports';
        if (!isset($cash_flow_data[$report_key])) return $data;

        $data_map = [];
        foreach ($cash_flow_data[$report_key] as $report) {
            $label = ($type === 'annual') ? substr($report['fiscalDateEnding'], 0, 4) : $report['fiscalDateEnding'];
            $operating_cash_flow = (float)($report['operatingCashflow'] ?? 0);
            $capex = (float)($report['capitalExpenditures'] ?? 0);
            $data_map[$label] = $operating_cash_flow - $capex;
        }

        foreach($master_labels as $i => $label) {
            if(isset($data_map[$label])) {
                $data['data'][$i] = $data_map[$label];
            }
        }
        return $data;
    }

    private function extract_av_cash_and_debt_data($balance_sheet_data, $type, $master_labels) {
        $data = ['labels' => $master_labels, 'datasets' => [
            ['label' => 'Total Debt', 'data' => array_fill(0, count($master_labels), 0)],
            ['label' => 'Cash & Equivalents', 'data' => array_fill(0, count($master_labels), 0)]
        ]];
        if (is_wp_error($balance_sheet_data)) return $data;
        
        $report_key = ($type === 'annual') ? 'annualReports' : 'quarterlyReports';
        if (!isset($balance_sheet_data[$report_key])) return $data;

        $debt_map = [];
        $cash_map = [];
        foreach ($balance_sheet_data[$report_key] as $report) {
            $label = ($type === 'annual') ? substr($report['fiscalDateEnding'], 0, 4) : $report['fiscalDateEnding'];
            $short_term_debt = (float)($report['shortTermDebt'] ?? 0);
            $long_term_debt = (float)($report['longTermDebt'] ?? 0);
            $debt_map[$label] = $short_term_debt + $long_term_debt;
            $cash_map[$label] = isset($report['cashAndCashEquivalentsAtCarryingValue']) && is_numeric($report['cashAndCashEquivalentsAtCarryingValue']) ? (float)$report['cashAndCashEquivalentsAtCarryingValue'] : 0;
        }

        foreach($master_labels as $i => $label) {
            if(isset($debt_map[$label])) $data['datasets'][0]['data'][$i] = $debt_map[$label];
            if(isset($cash_map[$label])) $data['datasets'][1]['data'][$i] = $cash_map[$label];
        }
        return $data;
    }

    private function extract_av_expenses_data($income_statement_data, $type, $master_labels) {
        $data = ['labels' => $master_labels, 'datasets' => [
            ['label' => 'SG&A', 'data' => array_fill(0, count($master_labels), 0)],
            ['label' => 'R&D', 'data' => array_fill(0, count($master_labels), 0)],
            ['label' => 'Interest Expense', 'data' => array_fill(0, count($master_labels), 0)]
        ]];
        if (is_wp_error($income_statement_data)) return $data;
        
        $report_key = ($type === 'annual') ? 'annualReports' : 'quarterlyReports';
        if (!isset($income_statement_data[$report_key])) return $data;

        $sga_map = [];
        $rnd_map = [];
        $interest_map = [];
        foreach ($income_statement_data[$report_key] as $report) {
            $label = ($type === 'annual') ? substr($report['fiscalDateEnding'], 0, 4) : $report['fiscalDateEnding'];
            $sga_map[$label] = isset($report['sellingGeneralAndAdministrative']) && is_numeric($report['sellingGeneralAndAdministrative']) ? (float)$report['sellingGeneralAndAdministrative'] : 0;
            $rnd_map[$label] = isset($report['researchAndDevelopment']) && is_numeric($report['researchAndDevelopment']) ? (float)$report['researchAndDevelopment'] : 0;
            $interest_map[$label] = isset($report['interestExpense']) && is_numeric($report['interestExpense']) ? (float)$report['interestExpense'] : 0;
        }
        
        foreach($master_labels as $i => $label) {
            if(isset($sga_map[$label])) $data['datasets'][0]['data'][$i] = $sga_map[$label];
            if(isset($rnd_map[$label])) $data['datasets'][1]['data'][$i] = $rnd_map[$label];
            if(isset($interest_map[$label])) $data['datasets'][2]['data'][$i] = $interest_map[$label];
        }
        return $data;
    }

    private function aggregate_av_dividend_data($daily_data, $type, $master_labels) {
        $data = ['labels' => $master_labels, 'data' => array_fill(0, count($master_labels), 0)];
        if (is_wp_error($daily_data) || !isset($daily_data['Time Series (Daily)'])) return $data;
    
        $dividends_by_period = [];
        foreach ($daily_data['Time Series (Daily)'] as $date_str => $day_data) {
            $dividend_amount = (float)($day_data['7. dividend amount'] ?? 0);
            if ($dividend_amount > 0) {
                $dt = new DateTime($date_str);
                $period_key = '';
                if ($type === 'annual') {
                    $period_key = $dt->format('Y');
                } else {
                    $quarter = ceil((int)$dt->format('n') / 3);
                    $year = $dt->format('Y');
                    $month = $quarter * 3;
                    $day = date('t', mktime(0, 0, 0, $month, 1, $year));
                    $period_key = date('Y-m-d', strtotime("$year-$month-$day"));
                }
                
                if (!isset($dividends_by_period[$period_key])) $dividends_by_period[$period_key] = 0;
                $dividends_by_period[$period_key] += $dividend_amount;
            }
        }
    
        foreach($master_labels as $i => $label) {
            if(isset($dividends_by_period[$label])) {
                $data['data'][$i] = $dividends_by_period[$label];
            }
        }
        return $data;
    }
    
    private function extract_av_earnings_data($earnings, $type, $master_labels) {
        $data = ['labels' => $master_labels, 'data' => array_fill(0, count($master_labels), 0)];
        if (is_wp_error($earnings)) return $data;

        $report_key = ($type === 'annual') ? 'annualEarnings' : 'quarterlyEarnings';
        if (!isset($earnings[$report_key])) return $data;
        
        $data_map = [];
        foreach ($earnings[$report_key] as $report) {
            $label = ($type === 'annual') ? substr($report['fiscalDateEnding'], 0, 4) : $report['fiscalDateEnding'];
            $data_map[$label] = isset($report['reportedEPS']) && is_numeric($report['reportedEPS']) ? (float)$report['reportedEPS'] : 0;
        }

        foreach($master_labels as $i => $label) {
            if(isset($data_map[$label])) {
                $data['data'][$i] = $data_map[$label];
            }
        }
        return $data;
    }

    private function process_av_price_data($daily_data) {
        $data = ['labels' => [], 'data' => []];
        if (is_wp_error($daily_data) || !isset($daily_data['Time Series (Daily)'])) return $data;

        $time_series = array_slice($daily_data['Time Series (Daily)'], 0, 252 * 15, true); // **FIX:** Changed to 15 years
        $time_series = array_reverse($time_series, true);

        foreach($time_series as $date => $day_data) {
            $data['labels'][] = $date;
            $data['data'][] = (float)$day_data['4. close'];
        }
        return $data;
    }
    
    // --- HTML Building Functions ---
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
        
        $growth_default = number_format((float)($peg_data['defaultGrowth'] ?? 5), 2, '.', '');
        $dividend_yield_default = number_format((float)($pegy_data['dividendYield'] ?? 0), 2, '.', '');
    
        $output = '<section id="section-peg-pegy-ratios" class="jtw-content-section">';
        $output .= '<h4>' . esc_html__('PEG/PEGY Ratio Calculator', 'journey-to-wealth') . '</h4>';
        $output .= '<p class="jtw-section-description">' . esc_html__('The Price/Earnings-to-Growth (PEG) ratio adjusts the P/E ratio for a company\'s growth, with values below 1.0 often considered favorable. The PEGY ratio further refines this by including dividend yield, offering a more complete valuation picture for income-paying stocks.', 'journey-to-wealth') . '</p>';
        $output .= '<div class="jtw-metric-card jtw-interactive-card">';
        $output .= '  <div class="jtw-peg-pegy-calculator">';
        $output .= '    <div class="jtw-peg-pegy-inputs-grid">';
        $output .= '      <div class="jtw-form-group"><label for="jtw-sim-stock-price">Stock Price ($):</label><input type="number" step="0.01" id="jtw-sim-stock-price" class="jtw-sim-input" value="' . esc_attr($stock_price) . '"></div>';
        $output .= '      <div class="jtw-form-group"><label for="jtw-sim-eps">Earnings per Share ($):</label><input type="number" step="0.01" id="jtw-sim-eps" class="jtw-sim-input" value="' . esc_attr($eps) . '"></div>';
        $output .= '      <div class="jtw-form-group"><label for="jtw-sim-growth-rate">Estimated Annual Earnings Growth (%):</label><input type="number" step="0.1" id="jtw-sim-growth-rate" class="jtw-sim-input" value="' . esc_attr($growth_default) . '"></div>';
        $output .= '      <div class="jtw-form-group"><label for="jtw-sim-dividend-yield">Estimated Annual Dividend Yield (%):</label><input type="number" step="0.01" id="jtw-sim-dividend-yield" class="jtw-sim-input" value="' . esc_attr($dividend_yield_default) . '"></div>';
        $output .= '    </div>';
        
        $output .= '    <div class="jtw-peg-pegy-results">';
        $output .= '      <div class="jtw-bar-result">';
        $output .= '          <span class="jtw-result-label">PEG Ratio</span>';
        $output .= '          <div class="jtw-bar-container">';
        $output .= '              <div id="jtw-peg-bar" class="jtw-bar"><span id="jtw-peg-value" class="jtw-bar-value">-</span></div>';
        $output .= '          </div>';
        $output .= '      </div>';
        $output .= '      <div class="jtw-bar-result">';
        $output .= '          <span class="jtw-result-label">PEGY Ratio</span>';
        $output .= '          <div class="jtw-bar-container">';
        $output .= '              <div id="jtw-pegy-bar" class="jtw-bar"><span id="jtw-pegy-value" class="jtw-bar-value">-</span></div>';
        $output .= '          </div>';
        $output .= '      </div>';
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
        
        $output .= '<div class="jtw-chart-controls">';
        $output .= '    <div class="jtw-period-toggle">';
        $output .= '        <button class="jtw-period-button active" data-period="annual">Annual</button>';
        $output .= '        <button class="jtw-period-button" data-period="quarterly">Quarterly</button>';
        $output .= '    </div>';
        $output .= '    <div class="jtw-chart-filter-toggle">';
        $output .= '        <button class="jtw-category-button active" data-category="all">All Charts</button>';
        $output .= '        <button class="jtw-category-button" data-category="growth">Growth</button>';
        $output .= '        <button class="jtw-category-button" data-category="profitability">Profitability</button>';
        $output .= '        <button class="jtw-category-button" data-category="financial_health">Financial Health</button>';
        $output .= '        <button class="jtw-category-button" data-category="dividends_capital">Dividends & Capital</button>';
        $output .= '    </div>';
        $output .= '</div>';
        
        $output .= '<div class="jtw-historical-charts-grid" id="' . esc_attr($unique_id) . '">';
        
        $chart_configs = [
            'price' => ['title' => 'Price History (10Y)', 'type' => 'line', 'prefix' => '$', 'category' => 'growth', 'colors' => ['#007bff', 'rgba(0, 122, 255, 0.1)']],
            'revenue' => ['title' => 'Revenue', 'type' => 'bar', 'prefix' => '$', 'category' => 'growth', 'colors' => ['#ffc107']],
            'net_income' => ['title' => 'Net Income', 'type' => 'bar', 'prefix' => '$', 'category' => 'profitability', 'colors' => ['#fd7e14']],
            'ebitda' => ['title' => 'EBITDA', 'type' => 'bar', 'prefix' => '$', 'category' => 'profitability', 'colors' => ['#82ca9d']],
            'fcf' => ['title' => 'Free Cash Flow', 'type' => 'bar', 'prefix' => '$', 'category' => 'profitability', 'colors' => ['#20c997']],
            'cash_and_debt' => ['title' => 'Cash & Debt', 'type' => 'bar', 'prefix' => '$', 'category' => 'financial_health', 'colors' => ['#28a745', '#dc3545']],
            'expenses' => ['title' => 'Expenses', 'type' => 'bar', 'prefix' => '$', 'category' => 'profitability', 'colors' => ['#007bff', '#fd7e14', '#6c757d']],
            'dividend' => ['title' => 'Dividend Per Share', 'type' => 'bar', 'prefix' => '$', 'category' => 'dividends_capital', 'colors' => ['#6f42c1']],
            'shares_outstanding' => ['title' => 'Shares Outstanding', 'type' => 'bar', 'prefix' => '', 'category' => 'dividends_capital', 'colors' => ['#17a2b8']],
            'eps' => ['title' => 'EPS', 'type' => 'bar', 'prefix' => '$', 'category' => 'profitability', 'colors' => ['#ffc107']],
        ];

        foreach($chart_configs as $key => $config) {
            $annual_data = $historical_data['annual'][$key] ?? [];
            $quarterly_data = $historical_data['quarterly'][$key] ?? [];
    
            $has_data = !empty($annual_data) && (isset($annual_data['data']) || (isset($annual_data['datasets']) && !empty($annual_data['datasets'][0]['data'])));
    
            if (!$has_data) continue;

            $chart_id = 'chart-' . strtolower(str_replace(' ', '-', $key)) . '-' . uniqid();
            $output .= '<div class="jtw-chart-item" data-category="' . esc_attr($config['category']) . '">';
            $output .= '<h5>' . esc_html($config['title']) . '</h5>';
            $output .= '<div class="jtw-chart-wrapper">';
            $output .= '<canvas id="' . esc_attr($chart_id) . '"></canvas>';
            $output .= '</div>';
            $output .= "<script type='application/json' class='jtw-chart-data' 
                            data-chart-id='" . esc_attr($chart_id) . "' 
                            data-chart-type='" . esc_attr($config['type']) . "'
                            data-prefix='" . esc_attr($config['prefix']) . "'
                            data-annual='" . esc_attr(json_encode($annual_data)) . "'
                            data-quarterly='" . esc_attr(json_encode($quarterly_data)) . "'
                            data-colors='" . esc_attr(json_encode($config['colors'])) . "'>"
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
                            <canvas id="jtw-valuation-chart"></canvas>
                        </div>';
        } else {
            $output .= '<p>' . esc_html__('Not enough data to calculate an average fair value.', 'journey-to-wealth') . '</p>';
        }

        $output .= '<div class="jtw-valuation-summary-box-container">';
        foreach ($valuation_data as $model_name => $result) {
            if (is_wp_error($result)) {
                continue;
            }
            $modal_id = 'modal-' . sanitize_key($model_name);
            $output .= '<div class="jtw-valuation-summary-box">';
            $output .= '    <span class="jtw-summary-model-name">' . esc_html($model_name) . '</span>';
            $output .= '    <span class="jtw-summary-fair-value">$' . esc_html($result['intrinsic_value_per_share']) . '</span>';
            $output .= '    <button class="jtw-modal-trigger" data-modal-target="#' . $modal_id . '">' . esc_html__('View Assumptions', 'journey-to-wealth') . '</button>';
            $output .= '</div>';
            
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
        $output .= '</div>';
        $output .= '<div class="jtw-modal-overlay"></div>';
        $output .= '</section>';
        return $output;
    }

    private function create_metric_card($title, $value, $prefix = '', $custom_class = '', $use_large_number_format = false) {
        $formatted_value = 'N/A';
    
        if (is_numeric($value)) {
            if ($use_large_number_format) {
                $final_prefix = ($title === 'Shares Outstanding') ? '' : $prefix;
                $formatted_value = $this->format_large_number($value, $final_prefix);
            } else {
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
            $formatted_value = $value;
        }
    
        return '<div class="jtw-metric-card ' . esc_attr($custom_class) . '">
                    <h3 class="jtw-metric-title">' . esc_html($title) . '</h3>
                    <p class="jtw-metric-value">' . esc_html($formatted_value) . '</p>
                </div>';
    }
}
