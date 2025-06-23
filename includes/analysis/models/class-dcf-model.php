<?php
/**
 * Discounted Cash Flow (DCF) Valuation Model for Journey to Wealth plugin.
 * Implements a 2-stage FCFE model with dynamic growth logic considering
 * high revenue growth companies, FCFE normalization, and CAPM for Cost of Equity.
 *
 * @link       https://example.com/journey-to-wealth/
 * @since      1.0.0
 *
 * @package    Journey_To_Wealth
 * @subpackage Journey_To_Wealth/includes/analysis/models
 */

// Prevent direct access to this file.
if ( ! defined( 'WPINC' ) ) {
    die;
}

class Journey_To_Wealth_DCF_Model {

    private $cost_of_equity;
    private $terminal_growth_rate;
    private $projection_years = 10;

    // --- Defaults & Constants ---
    const DEFAULT_COST_OF_EQUITY_FALLBACK = 0.11;
    const ASSUMED_EQUITY_RISK_PREMIUM = 0.050;
    const DEFAULT_TERMINAL_GROWTH_RATE_FALLBACK = 0.025;
    
    const MAX_YEARS_FOR_HISTORICAL_CALCS = 7;
    const MIN_YEARS_FOR_GROWTH_CALC = 3; // Min years of data to calculate at least 2 growth periods
    
    // For FCFE-based growth
    const DEFAULT_HISTORICAL_GROWTH_CAP = 0.15; // Corrected from DEFAULT_FCFE_GROWTH_CAP
    const DEFAULT_HISTORICAL_GROWTH_FLOOR = 0.02; // Corrected from DEFAULT_FCFE_GROWTH_FLOOR

    // For Revenue-based growth (for high-growth companies)
    const HIGH_REVENUE_GROWTH_THRESHOLD = 0.15;
    const DEFAULT_REVENUE_GROWTH_CAP = 0.20;
    const DEFAULT_FCFE_MARGIN_FLOOR = 0.01;
    const DEFAULT_FCFE_MARGIN_CAP = 0.25;

    const STAGE1_GROWTH_YEARS = 3; // Corrected from 5 to 3 as per last discussion on AMZN

    public function __construct() {
        $this->cost_of_equity = null;
        $this->terminal_growth_rate = null;
    }

    private function get_numeric_value($data, $key, $default = 0, &$log_messages = [], $context = '', $is_critical = false, $can_be_zero = true) {
        $fiscal_date_info = '';
        if ($context) {
            if (isset($data['fiscalDateEnding'])) { $fiscal_date_info = ' for ' . $data['fiscalDateEnding']; }
            elseif (isset($data['date'])) { $fiscal_date_info = ' for ' . $data['date']; }
        }
        if (isset($data[$key]) && is_numeric($data[$key]) && $data[$key] !== 'None') {
            $value = (float) $data[$key];
            if (!$can_be_zero && $value == 0 && $default !== 0) { /* If zero isn't allowed and default is not zero, use default */ }
            else { return $value; }
        }
        $log_level = $is_critical ? 'Error' : 'Warning';
        $log_messages[] = sprintf(__('DCF %s: Numeric value for "%s"%s missing or invalid. Using default: %s.', 'journey-to-wealth'), $log_level, $key, $context . $fiscal_date_info, $default === null ? 'N/A (critical)' : $default);
        if (isset($data[$key])) { $log_messages[] = sprintf(__('DCF Debug: Value found for "%s" was: %s', 'journey-to-wealth'), $key, print_r($data[$key], true)); }
        return $is_critical ? null : $default;
    }

    private function calculate_cost_of_equity_capm($company_overview, $latest_treasury_yield_data, &$log_messages) {
        $risk_free_rate = null; $avg_rfr_log = "N/A";
        if (is_array($latest_treasury_yield_data) && !empty($latest_treasury_yield_data)) {
            $yield_sum = 0; $yield_count = 0; $five_years_ago = strtotime('-5 years'); $data_points_checked = 0;
            foreach ($latest_treasury_yield_data as $yield_entry) {
                $data_points_checked++;
                if (isset($yield_entry['date']) && isset($yield_entry['value']) && is_numeric($yield_entry['value']) && $yield_entry['value'] !== '.') {
                    if (strtotime($yield_entry['date']) >= $five_years_ago) { $yield_sum += (float) $yield_entry['value']; $yield_count++; }
                }
                if ($yield_count >= 60 && isset($yield_entry['interval']) && $yield_entry['interval'] === 'monthly') break;
                if ($yield_count >= (5*52) && isset($yield_entry['interval']) && $yield_entry['interval'] === 'weekly') break;
                if ($data_points_checked > 1300 && $yield_count < 10 ) break;
            }
            if ($yield_count > 0) { $risk_free_rate = ($yield_sum / $yield_count) / 100; $avg_rfr_log = sprintf('%.2f%% (based on %d data points over up to 5 years)', $risk_free_rate * 100, $yield_count); }
        }
        $log_messages[] = __('DCF: Risk-Free Rate (Avg 10yr Treasury): ', 'journey-to-wealth') . $avg_rfr_log;
        if ($risk_free_rate === null) { $log_messages[] = __('DCF Warning: Could not calculate Risk-Free Rate. CAPM cannot proceed.', 'journey-to-wealth'); if (is_wp_error($latest_treasury_yield_data)) $log_messages[] = 'Treasury Yield API Error: ' . $latest_treasury_yield_data->get_error_message(); return null; }
        $this->terminal_growth_rate = min($risk_free_rate, 0.04);
        $log_messages[] = sprintf(__('DCF: Terminal Growth Rate set to: %.2f%% (min(RFR, 4%%)).', 'journey-to-wealth'), $this->terminal_growth_rate * 100);
        $beta = $this->get_numeric_value($company_overview, 'Beta', null, $log_messages, 'Company Overview', true);
        if ($beta === null) { $log_messages[] = __('DCF Warning: Beta not available. CAPM cannot proceed.', 'journey-to-wealth'); return null; }
        $beta = max(0.6, min($beta, 2.0));
        $log_messages[] = sprintf(__('DCF: Using Beta: %.2f (adjusted to 0.6-2.0 range if necessary).', 'journey-to-wealth'), $beta);
        $equity_risk_premium = self::ASSUMED_EQUITY_RISK_PREMIUM;
        $log_messages[] = sprintf(__('DCF: Using Assumed Equity Risk Premium (ERP): %.1f%%.', 'journey-to-wealth'), $equity_risk_premium * 100);
        $cost_of_equity = $risk_free_rate + ($beta * $equity_risk_premium);
        if ($cost_of_equity <= $this->terminal_growth_rate || $cost_of_equity >= 0.25) { $log_messages[] = sprintf(__('DCF Warning: Calculated Cost of Equity (%.2f%%) is problematic. Using fallback.', 'journey-to-wealth'), $cost_of_equity * 100); return null; }
        $log_messages[] = sprintf(__('DCF: Calculated Cost of Equity (CAPM): %.2f%%.', 'journey-to-wealth'), $cost_of_equity * 100);
        return (float) $cost_of_equity;
    }

    private function calculate_single_fcfe($income_report_current, $cash_flow_report_current, $balance_sheet_current, $balance_sheet_prior, &$log_messages) {
        $fiscal_date = $income_report_current['fiscalDateEnding'] ?? 'N/A';
        $log_context_prefix = 'FCFE for ' . $fiscal_date . ': ';
        $net_income = $this->get_numeric_value($income_report_current, 'netIncome', null, $log_messages, $log_context_prefix . 'Net Income', true);
        if ($net_income === null) { $log_messages[] = __('DCF Critical: calculate_single_fcfe returning NULL due to missing/invalid Net Income.', 'journey-to-wealth'); return null; }
        $depreciation_amortization_cf = $this->get_numeric_value($cash_flow_report_current, 'depreciationAndAmortization', 0, $log_messages, $log_context_prefix . 'D&A (CF)');
        $depreciation_amortization_is = $this->get_numeric_value($income_report_current, 'depreciationAndAmortization', 0, $log_messages, $log_context_prefix . 'D&A (IS)');
        $depreciation_cf = $this->get_numeric_value($cash_flow_report_current, 'depreciation', 0, $log_messages, $log_context_prefix . 'Depreciation (CF)');
        $depreciation_amortization = $depreciation_amortization_cf ?: $depreciation_amortization_is ?: $depreciation_cf ?: 0;
        if ($depreciation_amortization == 0 && $net_income != 0) $log_messages[] = __('DCF Warning: D&A is zero or missing for FCFE calc for ', 'journey-to-wealth') . $fiscal_date;
        $capital_expenditures_value = abs($this->get_numeric_value($cash_flow_report_current, 'capitalExpenditures', 0, $log_messages, $log_context_prefix . 'CapEx'));
        $net_capex = $capital_expenditures_value - $depreciation_amortization;
        $change_in_non_cash_wc = 0;
        if ($balance_sheet_prior && $balance_sheet_current && isset($balance_sheet_current['fiscalDateEnding']) && isset($balance_sheet_prior['fiscalDateEnding'])) {
            $wc_current_assets = $this->get_numeric_value($balance_sheet_current, 'totalCurrentAssets', 0, $log_messages, $log_context_prefix . 'Current Assets');
            $wc_current_cash = $this->get_numeric_value($balance_sheet_current, 'cashAndCashEquivalentsAtCarryingValue', $this->get_numeric_value($balance_sheet_current, 'cash', 0, $log_messages, $log_context_prefix . 'Cash (fallback)'), $log_messages, $log_context_prefix . 'Cash');
            $wc_current_liabilities = $this->get_numeric_value($balance_sheet_current, 'totalCurrentLiabilities', 0, $log_messages, $log_context_prefix . 'Current Liabilities');
            $wc_current_short_term_debt = $this->get_numeric_value($balance_sheet_current, 'shortTermDebt', 0, $log_messages, $log_context_prefix . 'Short Term Debt');
            $non_cash_wc_current = ($wc_current_assets - $wc_current_cash) - ($wc_current_liabilities - $wc_current_short_term_debt);
            $wc_prior_assets = $this->get_numeric_value($balance_sheet_prior, 'totalCurrentAssets', 0, $log_messages, $log_context_prefix . 'Prior Current Assets');
            $wc_prior_cash = $this->get_numeric_value($balance_sheet_prior, 'cashAndCashEquivalentsAtCarryingValue', $this->get_numeric_value($balance_sheet_prior, 'cash', 0, $log_messages, $log_context_prefix . 'Prior Cash (fallback)'), $log_messages, $log_context_prefix . 'Prior Cash');
            $wc_prior_liabilities = $this->get_numeric_value($balance_sheet_prior, 'totalCurrentLiabilities', 0, $log_messages, $log_context_prefix . 'Prior Current Liabilities');
            $wc_prior_short_term_debt = $this->get_numeric_value($balance_sheet_prior, 'shortTermDebt', 0, $log_messages, $log_context_prefix . 'Prior Short Term Debt');
            $non_cash_wc_prior = ($wc_prior_assets - $wc_prior_cash) - ($wc_prior_liabilities - $wc_prior_short_term_debt);
            $change_in_non_cash_wc = $non_cash_wc_current - $non_cash_wc_prior;
        } else { $log_messages[] = __('DCF Warning: Insufficient BS data for Change in Non-Cash WC for report ending ', 'journey-to-wealth') . esc_html($fiscal_date) . '. Assuming zero.'; }
        $net_borrowing = $this->get_numeric_value($cash_flow_report_current, 'netIssuanceOfDebt', 0, $log_messages, $log_context_prefix . 'Net Issuance of Debt');
        if ($this->get_numeric_value($cash_flow_report_current, 'netIssuanceOfDebt', 'not_set', $log_messages) === 'not_set' && $balance_sheet_prior && $balance_sheet_current) {
            $total_debt_current = $this->get_numeric_value($balance_sheet_current, 'shortLongTermDebtTotal', $this->get_numeric_value($balance_sheet_current, 'longTermDebtTotal', 0, $log_messages, $log_context_prefix . 'Current Total Debt (LTD fallback)'), $log_messages, $log_context_prefix . 'Current Total Debt');
            $total_debt_prior = $this->get_numeric_value($balance_sheet_prior, 'shortLongTermDebtTotal', $this->get_numeric_value($balance_sheet_prior, 'longTermDebtTotal', 0, $log_messages, $log_context_prefix . 'Prior Total Debt (LTD fallback)'), $log_messages, $log_context_prefix . 'Prior Total Debt');
            if ($total_debt_current != 0 || $total_debt_prior !=0) { $net_borrowing = $total_debt_current - $total_debt_prior; $log_messages[] = __('DCF Note: Net borrowing calculated from change in total debt on balance sheets for report ending ', 'journey-to-wealth') . esc_html($fiscal_date) . '.'; }
        }
        $fcfe = $net_income - $net_capex - $change_in_non_cash_wc + $net_borrowing;
        $log_messages[] = sprintf(__('DCF Detail: FCFE for %s: NI(%.0f) - (CapEx(%.0f) - D&A(%.0f)) - ChgWC(%.0f) + NetDebt(%.0f) = %.0f', 'journey-to-wealth'), $fiscal_date, $net_income, $capital_expenditures_value, $depreciation_amortization, $change_in_non_cash_wc, $net_borrowing, $fcfe);
        return $fcfe;
    }

    /**
     * Calculates historical average annual growth rate of a given metric.
     * This method is now defined and used by other growth calculation methods.
     */
    private function calculate_historical_average_growth($historical_values, $log_messages_prefix, &$log_messages, $cap, $floor) {
        if (count($historical_values) < 2) {
            $log_messages[] = sprintf(__('%s: Not enough data points (need at least 2) for historical growth. Using floor value of %.1f%%.', 'journey-to-wealth'), $log_messages_prefix, $floor * 100);
            return $floor;
        }
        $growth_rates = [];
        for ($i = 1; $i < count($historical_values); $i++) {
            if ($historical_values[$i-1] != 0) {
                if ($historical_values[$i-1] < 0 && $historical_values[$i] > 0) { $growth_rates[] = $cap;
                } elseif ($historical_values[$i-1] < 0 && $historical_values[$i] < 0) { $growth = ($historical_values[$i] - $historical_values[$i-1]) / abs($historical_values[$i-1]); $growth_rates[] = $growth;
                } elseif ($historical_values[$i-1] > 0) { $growth = ($historical_values[$i] - $historical_values[$i-1]) / $historical_values[$i-1]; $growth_rates[] = $growth; }
            }
        }
        if (empty($growth_rates)) { $log_messages[] = sprintf(__('%s: No valid growth rates calculated. Using floor value of %.1f%%.', 'journey-to-wealth'), $log_messages_prefix, $floor * 100); return $floor; }
        $average_growth = array_sum($growth_rates) / count($growth_rates);
        $calculated_g = $average_growth;
        if ($average_growth > $cap) { $calculated_g = $cap; $log_messages[] = sprintf(__('%s: Historical growth (%.1f%%) capped at %.1f%%.', 'journey-to-wealth'), $log_messages_prefix, $average_growth * 100, $calculated_g * 100);
        } elseif ($average_growth < $floor) { $calculated_g = $floor; $log_messages[] = sprintf(__('%s: Historical growth (%.1f%%) floored at %.1f%%.', 'journey-to-wealth'), $log_messages_prefix, $average_growth * 100, $calculated_g * 100);
        } else { $log_messages[] = sprintf(__('%s: Calculated historical growth rate: %.1f%%.', 'journey-to-wealth'), $log_messages_prefix, $calculated_g * 100); }
        return (float) $calculated_g;
    }

    private function calculate_historical_fcfe_growth( $income_statement_data, $cash_flow_data, $balance_sheet_data, &$log_messages ) {
        if (count($income_statement_data) < self::MIN_YEARS_FOR_GROWTH_CALC || count($cash_flow_data) < self::MIN_YEARS_FOR_GROWTH_CALC || count($balance_sheet_data) < self::MIN_YEARS_FOR_GROWTH_CALC +1) { $log_messages[] = __('DCF: Insufficient historical statements for FCFE growth. Using default floor growth.', 'journey-to-wealth'); return self::DEFAULT_HISTORICAL_GROWTH_FLOOR; }
        $is_map = []; foreach($income_statement_data as $r) { if(isset($r['fiscalDateEnding'])) $is_map[substr($r['fiscalDateEnding'],0,4)] = $r; }
        $cf_map = []; foreach($cash_flow_data as $r) { if(isset($r['fiscalDateEnding'])) $cf_map[substr($r['fiscalDateEnding'],0,4)] = $r; }
        $bs_map = []; foreach($balance_sheet_data as $r) { if(isset($r['fiscalDateEnding'])) $bs_map[substr($r['fiscalDateEnding'],0,4)] = $r; }
        $sorted_years = array_keys($is_map); rsort($sorted_years);
        $fcfes = []; $years_processed = 0;
        for ($i = 0; $i < count($sorted_years) -1 && $years_processed < (self::MAX_YEARS_FOR_HISTORICAL_CALCS + 1); $i++) {
            $current_year_str = $sorted_years[$i]; $prior_year_str = $sorted_years[$i+1];
            if (isset($is_map[$current_year_str]) && isset($cf_map[$current_year_str]) && isset($bs_map[$current_year_str]) && isset($bs_map[$prior_year_str])) {
                $fcfe = $this->calculate_single_fcfe($is_map[$current_year_str], $cf_map[$current_year_str], $bs_map[$current_year_str], $bs_map[$prior_year_str], $log_messages);
                if ($fcfe !== null && $fcfe > 0) { $fcfes[$current_year_str] = $fcfe; $years_processed++; }
                elseif ($fcfe !== null) { $log_messages[] = sprintf(__('DCF: Excluding non-positive FCFE (%.2f) for year %s from historical growth.', 'journey-to-wealth'), $fcfe, $current_year_str); }
            }
        }
        if (count($fcfes) > (self::MAX_YEARS_FOR_HISTORICAL_CALCS) ) { krsort($fcfes); $fcfes = array_slice($fcfes, 0, self::MAX_YEARS_FOR_HISTORICAL_CALCS, true); ksort($fcfes); $log_messages[] = sprintf(__('DCF: Using latest %d positive FCFE values for growth calculation.', 'journey-to-wealth'), count($fcfes)); }
        if (count($fcfes) < 2) { $log_messages[] = __('DCF: Not enough positive FCFE data for growth. Using default floor growth.', 'journey-to-wealth'); return self::DEFAULT_HISTORICAL_GROWTH_FLOOR; }
        $fcfe_values_for_growth = array_values($fcfes);
        return $this->calculate_historical_average_growth($fcfe_values_for_growth, 'FCFE Growth', $log_messages, self::DEFAULT_HISTORICAL_GROWTH_CAP, self::DEFAULT_HISTORICAL_GROWTH_FLOOR);
    }
    
    private function calculate_historical_revenue_growth($income_statement_data, &$log_messages) {
        if (!is_array($income_statement_data) || count($income_statement_data) < self::MIN_YEARS_FOR_GROWTH_CALC) { $log_messages[] = __('DCF: Insufficient historical income statements for revenue growth. Using default floor.', 'journey-to-wealth'); return self::DEFAULT_HISTORICAL_GROWTH_FLOOR; }
        usort($income_statement_data, function($a, $b) { return isset($a['fiscalDateEnding'], $b['fiscalDateEnding']) ? strtotime($b['fiscalDateEnding']) - strtotime($a['fiscalDateEnding']) : 0; });
        $revenues_map = []; $count = 0;
        foreach ($income_statement_data as $report) {
            if ($count >= self::MAX_YEARS_FOR_HISTORICAL_CALCS + 1) break;
            $revenue = $this->get_numeric_value($report, 'totalRevenue', null, $log_messages, 'Revenue History');
            if ($revenue !== null && $revenue > 0) { $revenues_map[$report['fiscalDateEnding']] = $revenue; $count++; }
        }
        if (count($revenues_map) < 2) { $log_messages[] = __('DCF: Not enough positive revenue data for growth. Using default floor.', 'journey-to-wealth'); return self::DEFAULT_HISTORICAL_GROWTH_FLOOR; }
        krsort($revenues_map);
        $revenues_for_growth = array_slice(array_values($revenues_map), 0, self::MAX_YEARS_FOR_HISTORICAL_CALCS + 1);
        $revenues_for_growth = array_reverse($revenues_for_growth);
        return $this->calculate_historical_average_growth($revenues_for_growth, 'Revenue Growth', $log_messages, self::DEFAULT_REVENUE_GROWTH_CAP, self::DEFAULT_HISTORICAL_GROWTH_FLOOR);
    }

    private function calculate_historical_fcfe_margin($historical_fcfes_map, $historical_revenues_map, &$log_messages) {
        if (empty($historical_fcfes_map) || empty($historical_revenues_map)) { $log_messages[] = __('DCF: Insufficient data for FCFE margin calculation.', 'journey-to-wealth'); return self::DEFAULT_FCFE_MARGIN_FLOOR; }
        $fcfe_margins = []; $common_years = array_intersect_key(array_keys($historical_fcfes_map), array_keys($historical_revenues_map));
        foreach ($common_years as $year) { if ($historical_revenues_map[$year] > 0 && $historical_fcfes_map[$year] > 0) { $fcfe_margins[] = $historical_fcfes_map[$year] / $historical_revenues_map[$year]; } }
        if (empty($fcfe_margins)) { $log_messages[] = __('DCF: No valid FCFE margins calculated. Using default floor.', 'journey-to-wealth'); return self::DEFAULT_FCFE_MARGIN_FLOOR; }
        $average_margin = array_sum($fcfe_margins) / count($fcfe_margins);
        $calculated_margin = max(self::DEFAULT_FCFE_MARGIN_FLOOR, min($average_margin, self::DEFAULT_FCFE_MARGIN_CAP));
        $log_messages[] = sprintf(__('DCF: Calculated FCFE Margin: %.1f%% (from %.1f%% historical avg).', 'journey-to-wealth'), $calculated_margin * 100, $average_margin * 100);
        return $calculated_margin;
    }

    public function calculate( $income_statement_data, $cash_flow_data, $balance_sheet_data, $company_overview, $global_quote_data, $latest_treasury_yield_data ) {
        $log_messages = array();
        $this->cost_of_equity = $this->calculate_cost_of_equity_capm($company_overview, $latest_treasury_yield_data, $log_messages);
        if ($this->cost_of_equity === null) { $this->cost_of_equity = self::DEFAULT_COST_OF_EQUITY_FALLBACK; $log_messages[] = sprintf(__('DCF: Using fallback Cost of Equity: %.1f%%.', 'journey-to-wealth'), $this->cost_of_equity * 100); }
        if ($this->terminal_growth_rate === null || $this->cost_of_equity <= $this->terminal_growth_rate) {
             $this->terminal_growth_rate = min(self::DEFAULT_TERMINAL_GROWTH_RATE_FALLBACK, max(0.005, $this->cost_of_equity - 0.01));
             $log_messages[] = sprintf(__('DCF: Adjusted/Set terminal growth rate to %.2f%%.', 'journey-to-wealth'), $this->terminal_growth_rate * 100);
        }

        if (!is_array($income_statement_data) || empty($income_statement_data) || !is_array($cash_flow_data) || empty($cash_flow_data) || !is_array($balance_sheet_data) || count($balance_sheet_data) < 2 ) { return new WP_Error('dcf_missing_financials_base', __('DCF Error: Insufficient financial statements for base FCFE (need latest IS, CF, and at least 2 BS reports).', 'journey-to-wealth')); }
        $temp_is_data = is_array($income_statement_data) ? $income_statement_data : []; $temp_cf_data = is_array($cash_flow_data) ? $cash_flow_data : []; $temp_bs_data = is_array($balance_sheet_data) ? $balance_sheet_data : [];
        usort($temp_is_data, function($a, $b) { return isset($a['fiscalDateEnding'], $b['fiscalDateEnding']) ? strtotime($b['fiscalDateEnding']) - strtotime($a['fiscalDateEnding']) : 0; });
        usort($temp_cf_data, function($a, $b) { return isset($a['fiscalDateEnding'], $b['fiscalDateEnding']) ? strtotime($b['fiscalDateEnding']) - strtotime($a['fiscalDateEnding']) : 0; });
        usort($temp_bs_data, function($a, $b) { return isset($a['fiscalDateEnding'], $b['fiscalDateEnding']) ? strtotime($b['fiscalDateEnding']) - strtotime($a['fiscalDateEnding']) : 0; });
        $latest_is = !empty($temp_is_data) ? $temp_is_data[0] : null; $latest_cf = null; $latest_bs_current = null; $latest_bs_prior = null;
        $latest_is_year = ($latest_is && isset($latest_is['fiscalDateEnding'])) ? substr($latest_is['fiscalDateEnding'],0,4) : null;
        if ($latest_is_year) {
            foreach($temp_cf_data as $cf) { if(isset($cf['fiscalDateEnding']) && substr($cf['fiscalDateEnding'],0,4) === $latest_is_year) {$latest_cf = $cf; break;} }
            $bs_reports_by_year = []; foreach($temp_bs_data as $bs) { if(isset($bs['fiscalDateEnding'])) $bs_reports_by_year[substr($bs['fiscalDateEnding'],0,4)] = $bs; }
            if(isset($bs_reports_by_year[$latest_is_year])) $latest_bs_current = $bs_reports_by_year[$latest_is_year];
            $prior_year_num_str = (string)((int)$latest_is_year - 1);
            if(isset($bs_reports_by_year[$prior_year_num_str])) $latest_bs_prior = $bs_reports_by_year[$prior_year_num_str];
        }
        if(!$latest_cf && !empty($temp_cf_data)) $latest_cf = $temp_cf_data[0];
        if(!$latest_bs_current && !empty($temp_bs_data)) $latest_bs_current = $temp_bs_data[0];
        if(!$latest_bs_prior && count($temp_bs_data) > 1 && $latest_bs_current && isset($bs_reports_by_year)) { $current_bs_year = isset($latest_bs_current['fiscalDateEnding']) ? substr($latest_bs_current['fiscalDateEnding'],0,4) : null; if($current_bs_year){ $prior_bs_year_lookup = (string)((int)$current_bs_year - 1); if(isset($bs_reports_by_year[$prior_bs_year_lookup])) $latest_bs_prior = $bs_reports_by_year[$prior_bs_year_lookup]; } if(!$latest_bs_prior) $latest_bs_prior = $temp_bs_data[1];
        } elseif (count($temp_bs_data) < 2) { $log_messages[] = __('DCF Warning: Less than 2 balance sheet reports available, cannot calculate change in WC properly for base FCFE.', 'journey-to-wealth'); }
        if (!$latest_is || !$latest_cf || !$latest_bs_current || !$latest_bs_prior) { $log_messages[] = "DCF Debug Base FCFE Data: IS Year: " . ($latest_is_year ?? 'N/A') . ", CF Found: " . ($latest_cf ? 'Yes' : 'No') . ", BS Current Found: " . ($latest_bs_current ? 'Yes' : 'No') . ", BS Prior Found: " . ($latest_bs_prior ? 'Yes' : 'No'); return new WP_Error('dcf_base_fcfe_data_missing', __('DCF Error: Missing critical financial statements for base FCFE. Could not align IS, CF, and two consecutive BS reports for the latest period.', 'journey-to-wealth')); }
        $base_fcfe = $this->calculate_single_fcfe($latest_is, $latest_cf, $latest_bs_current, $latest_bs_prior, $log_messages);
        $log_messages[] = 'DCF Debug: Base FCFE after calculate_single_fcfe call: ' . ($base_fcfe === null ? 'NULL' : $base_fcfe);
        if ($base_fcfe === null) { return new WP_Error('dcf_base_fcfe_error', __('DCF Error: Could not calculate base FCFE. Check "Calculation Notes" for details on missing/invalid FCFE components (e.g., Net Income).', 'journey-to-wealth')); }
        if ($base_fcfe <= 0) { $log_messages[] = sprintf(__('DCF Warning: Base FCFE is $%.2f. Projections are highly speculative.', 'journey-to-wealth'), $base_fcfe); }
        $historical_fcfes_map = []; $historical_revenues_map = [];
        $num_reports_for_growth = min(count($income_statement_data), self::MAX_YEARS_FOR_HISTORICAL_CALCS + 1);
        for ($i = 0; $i < $num_reports_for_growth; $i++) {
            $current_is_hist = $income_statement_data[$i]; $current_year_hist_str = isset($current_is_hist['fiscalDateEnding']) ? substr($current_is_hist['fiscalDateEnding'],0,4) : null; if (!$current_year_hist_str) continue;
            $current_cf_hist = null; $current_bs_hist = null; $prior_bs_hist = null;
            if(is_array($cash_flow_data)) foreach($cash_flow_data as $cf) { if(isset($cf['fiscalDateEnding']) && substr($cf['fiscalDateEnding'],0,4) === $current_year_hist_str) {$current_cf_hist = $cf; break;} }
            $bs_reports_by_year_hist = []; if(is_array($balance_sheet_data)) foreach($balance_sheet_data as $bs) { if(isset($bs['fiscalDateEnding'])) $bs_reports_by_year_hist[substr($bs['fiscalDateEnding'],0,4)] = $bs; }
            if(isset($bs_reports_by_year_hist[$current_year_hist_str])) $current_bs_hist = $bs_reports_by_year_hist[$current_year_hist_str];
            $prior_year_num_lookup_hist = (string)((int)$current_year_hist_str - 1);
            if(isset($bs_reports_by_year_hist[$prior_year_num_lookup_hist])) $prior_bs_hist = $bs_reports_by_year_hist[$prior_year_num_lookup_hist];
            if ($current_is_hist && $current_cf_hist && $current_bs_hist && $prior_bs_hist) { $fcfe = $this->calculate_single_fcfe($current_is_hist, $current_cf_hist, $current_bs_hist, $prior_bs_hist, $log_messages); if ($fcfe !== null) $historical_fcfes_map[$current_year_hist_str] = $fcfe; }
            $revenue = $this->get_numeric_value($current_is_hist, 'totalRevenue', null, $log_messages, "Revenue for $current_year_hist_str");
            if ($revenue !== null) $historical_revenues_map[$current_year_hist_str] = $revenue;
        }
        $initial_growth_rate = null; $projection_method_log = "FCFE Growth Extrapolation";
        $historical_revenue_growth = $this->calculate_historical_revenue_growth($income_statement_data, $log_messages);
        $historical_fcfe_growth = $this->calculate_historical_fcfe_growth($income_statement_data, $cash_flow_data, $balance_sheet_data, $log_messages);
        if ($historical_revenue_growth >= self::HIGH_REVENUE_GROWTH_THRESHOLD) {
            $log_messages[] = sprintf(__('DCF: High revenue growth detected (%.1f%%). Considering revenue-based FCFE projection.', 'journey-to-wealth'), $historical_revenue_growth * 100);
            $avg_fcfe_margin = $this->calculate_historical_fcfe_margin($historical_fcfes_map ?? [], $historical_revenues_map ?? [], $log_messages);
            if ($base_fcfe <= 0 || $historical_fcfe_growth < self::DEFAULT_HISTORICAL_GROWTH_FLOOR + 0.01) {
                $latest_revenue = null; if(!empty($historical_revenues_map)){ krsort($historical_revenues_map); $latest_revenue = reset($historical_revenues_map); }
                if ($latest_revenue && $latest_revenue > 0 && $avg_fcfe_margin > 0) { $base_fcfe = $latest_revenue * $avg_fcfe_margin; $initial_growth_rate = $historical_revenue_growth; $projection_method_log = sprintf("Revenue Growth (%.1f%%) with FCFE Margin (%.1f%%)", $initial_growth_rate*100, $avg_fcfe_margin*100); $log_messages[] = __('DCF: Using revenue growth and FCFE margin for projection. New base FCFE: ', 'journey-to-wealth') . round($base_fcfe,0);
                } else { $initial_growth_rate = $historical_fcfe_growth; $log_messages[] = __('DCF: Conditions for revenue-based projection not fully met, reverting to FCFE growth.', 'journey-to-wealth');}
            } else { $initial_growth_rate = $historical_fcfe_growth; }
        } else { $initial_growth_rate = $historical_fcfe_growth; }
        if ($initial_growth_rate === null) $initial_growth_rate = self::DEFAULT_HISTORICAL_GROWTH_FLOOR;
        $log_messages[] = __('DCF: Projection Method: ', 'journey-to-wealth') . $projection_method_log;
        $log_messages[] = __('DCF: Using Initial Growth Rate for Projections: ', 'journey-to-wealth') . round($initial_growth_rate * 100, 1) . '%.';
        $projected_fcfs = array(); $current_fcfe = $base_fcfe; $current_growth_rate = $initial_growth_rate; $growth_reduction_per_year = 0;
        $tapering_start_year = self::STAGE1_GROWTH_YEARS + 1; $tapering_duration = $this->projection_years - self::STAGE1_GROWTH_YEARS;
        if ($tapering_duration > 0 && $current_growth_rate > $this->terminal_growth_rate) { $growth_reduction_per_year = ($current_growth_rate - $this->terminal_growth_rate) / $tapering_duration;
        } elseif ($current_growth_rate <= $this->terminal_growth_rate) { $current_growth_rate = max($current_growth_rate, $this->terminal_growth_rate); if ($current_growth_rate < 0 && $this->terminal_growth_rate > 0) $current_growth_rate = $this->terminal_growth_rate; $growth_reduction_per_year = 0; if ($initial_growth_rate != $current_growth_rate) { $log_messages[] = sprintf(__('DCF: Initial growth (%.1f%%) adjusted to %.1f%% for stage 1.', 'journey-to-wealth'), $initial_growth_rate*100, $current_growth_rate*100); } }
        for ( $i = 1; $i <= $this->projection_years; $i++ ) { if ($i > self::STAGE1_GROWTH_YEARS && $initial_growth_rate > $this->terminal_growth_rate && $growth_reduction_per_year > 0) { $current_growth_rate = max($this->terminal_growth_rate, $current_growth_rate - $growth_reduction_per_year); } elseif ($i > self::STAGE1_GROWTH_YEARS && $initial_growth_rate <= $this->terminal_growth_rate) { $current_growth_rate = $this->terminal_growth_rate; } $current_fcfe *= ( 1 + $current_growth_rate ); $projected_fcfs[] = $current_fcfe; }
        $sum_discounted_fcfs = 0; for ( $i = 0; $i < $this->projection_years; $i++ ) { $sum_discounted_fcfs += $projected_fcfs[$i] / pow( ( 1 + $this->cost_of_equity ), $i + 1 ); }
        $fcfe_year_n = end( $projected_fcfs ); $terminal_value = 0;
        if ( ($this->cost_of_equity - $this->terminal_growth_rate) <= 0.0001 ) { $log_messages[] = __('DCF Warning: Cost of Equity not sufficiently greater than Terminal Growth Rate. TV may be unreliable.', 'journey-to-wealth'); if ($fcfe_year_n > 0 && $this->cost_of_equity > $this->terminal_growth_rate) { $terminal_value = ( $fcfe_year_n * ( 1 + $this->terminal_growth_rate ) ) / ( $this->cost_of_equity - $this->terminal_growth_rate ); } else { $terminal_value = 0; $log_messages[] = __('DCF: Terminal value set to 0.', 'journey-to-wealth'); }
        } else { $terminal_value = ( $fcfe_year_n * ( 1 + $this->terminal_growth_rate ) ) / ( $this->cost_of_equity - $this->terminal_growth_rate ); }
        $discounted_terminal_value = $terminal_value / pow( ( 1 + $this->cost_of_equity ), $this->projection_years );
        $total_equity_value = $sum_discounted_fcfs + $discounted_terminal_value;
        $shares_outstanding = null;
        if ( is_array($company_overview) && isset($company_overview['SharesOutstanding']) && is_numeric($company_overview['SharesOutstanding']) && (float)$company_overview['SharesOutstanding'] > 0 ) { $shares_outstanding = (float) $company_overview['SharesOutstanding'];
        } elseif (is_array($balance_sheet_data) && !empty($balance_sheet_data)) { usort($balance_sheet_data, function($a, $b) { return isset($a['fiscalDateEnding'], $b['fiscalDateEnding']) ? strtotime($b['fiscalDateEnding']) - strtotime($a['fiscalDateEnding']) : 0; }); $latest_balance_sheet = $balance_sheet_data[0]; if ( isset($latest_balance_sheet['commonStockSharesOutstanding']) && $latest_balance_sheet['commonStockSharesOutstanding'] !== 'None' && is_numeric($latest_balance_sheet['commonStockSharesOutstanding']) && (float)$latest_balance_sheet['commonStockSharesOutstanding'] > 0 ) { $shares_outstanding = (float) $latest_balance_sheet['commonStockSharesOutstanding']; $log_messages[] = __('DCF: Used shares outstanding from balance sheet.', 'journey-to-wealth'); } }
        if ( $shares_outstanding === null || $shares_outstanding <= 0 ) return new WP_Error('dcf_missing_shares_outstanding', __('DCF Error: Shares outstanding data not found or invalid.', 'journey-to-wealth'));
        $intrinsic_value_per_share = $total_equity_value / $shares_outstanding;
        $current_market_price = (is_array($global_quote_data) && isset($global_quote_data['05. price']) && is_numeric($global_quote_data['05. price'])) ? (float) $global_quote_data['05. price'] : null;
        return array( 'intrinsic_value_per_share' => round($intrinsic_value_per_share, 2), 'current_market_price' => $current_market_price !== null ? round($current_market_price, 2) : 'N/A', 'assumptions_used' => array( 'cost_of_equity' => $this->cost_of_equity, 'initial_growth_rate' => $initial_growth_rate, 'terminal_growth_rate' => $this->terminal_growth_rate, 'projection_years' => $this->projection_years, 'stage1_growth_years' => self::STAGE1_GROWTH_YEARS, 'projection_method' => $projection_method_log), 'base_fcfe' => round($base_fcfe, 0), 'log_messages' => $log_messages );
    }

    public function get_interpretation( $intrinsic_value_per_share, $current_market_price ) {
        if ( !is_numeric($intrinsic_value_per_share) || !is_numeric($current_market_price) || $current_market_price == 0 ) { if (!is_numeric($intrinsic_value_per_share)) { return __('DCF Fair Value Estimate could not be determined numerically.', 'journey-to-wealth'); } return __('Cannot provide full DCF interpretation due to missing current market price.', 'journey-to-wealth'); }
        $ivps = (float) $intrinsic_value_per_share; $cmp = (float) $current_market_price; $diff_pct = (($ivps - $cmp) / abs($cmp)) * 100; $status_text = '';
        if ($ivps < 0) { $status_text = __('The DCF model suggests a negative equity value per share. This can occur with sustained negative cash flows or very high discount rates relative to growth.', 'journey-to-wealth');
        } elseif ( $diff_pct > 20 ) { $status_text = sprintf( __('suggests potential undervaluation by %.1f%%.', 'journey-to-wealth'), $diff_pct );
        } elseif ( $diff_pct < -20 ) { $status_text = sprintf( __('suggests potential overvaluation by %.1f%%.', 'journey-to-wealth'), abs($diff_pct) );
        } else { $status_text = sprintf( __('suggests relative fair valuation (Difference: %.1f%%).', 'journey-to-wealth'), $diff_pct ); }
        return sprintf(__('DCF Fair Value Estimate: $%.2f. Current Price: $%.2f. This %s', 'journey-to-wealth'), $ivps, $cmp, $status_text);
    }
}
