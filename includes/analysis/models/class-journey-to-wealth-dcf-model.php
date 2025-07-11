<?php
/**
 * Discounted Cash Flow (DCF) Valuation Model for Journey to Wealth plugin.
 * Implements a 2-stage FCFE model and uses a hierarchical growth rate model.
 *
 * @link       https://example.com/journey-to-wealth/
 * @since      3.5.0
 *
 * @package    Journey_To_Wealth
 * @subpackage Journey_To_Wealth/includes/analysis/models
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Journey_To_Wealth_DCF_Model {

    private $cost_of_equity;
    private $terminal_growth_rate;
    private $equity_risk_premium;
    private $levered_beta;
    private $projection_years = 10;

    const DEFAULT_COST_OF_EQUITY = 0.085;
    const DEFAULT_RISK_FREE_RATE = 0.045;
    
    const MAX_YEARS_FOR_HISTORICAL_CALCS = 6; // Need 6 years for 5 years of growth
    const MIN_YEARS_FOR_GROWTH_CALC = 3;
    
    const HISTORICAL_EPS_GROWTH_CAP = 0.25;
    const ANALYST_PEG_GROWTH_CAP = 0.25;
    const DEFAULT_GROWTH_FLOOR = 0.02;
    
    // **NEW**: Threshold for using Net Income as FCFE proxy
    const HIGH_CAPEX_THRESHOLD = 0.70;

    public function __construct($equity_risk_premium = null, $levered_beta = null) {
        $this->equity_risk_premium = is_numeric($equity_risk_premium) ? $equity_risk_premium : 0.055;
        $this->levered_beta = is_numeric($levered_beta) ? $levered_beta : null;
    }

    private function get_av_value($report, $key, $default = 0) {
        return isset($report[$key]) && is_numeric($report[$key]) && $report[$key] !== 'None' ? (float)$report[$key] : $default;
    }

    public function calculate_average_risk_free_rate($treasury_yield_data) {
        if (is_wp_error($treasury_yield_data) || empty($treasury_yield_data['data'])) {
            return self::DEFAULT_RISK_FREE_RATE;
        }
        $yields = array_slice($treasury_yield_data['data'], 0, 60);
        $sum = 0;
        $count = 0;
        foreach ($yields as $yield_entry) {
            if (isset($yield_entry['value']) && is_numeric($yield_entry['value'])) {
                $sum += (float)$yield_entry['value'];
                $count++;
            }
        }
        return ($count > 0) ? ($sum / $count) / 100 : self::DEFAULT_RISK_FREE_RATE;
    }

    private function calculate_cost_of_equity($beta, $risk_free_rate) {
        return ($beta > 0) ? $risk_free_rate + ($beta * $this->equity_risk_premium) : self::DEFAULT_COST_OF_EQUITY;
    }

    private function get_historical_fcfe($income_reports, $balance_reports, $cash_flow_reports) {
        $historical_fcfe = [];
        $num_years = count($income_reports);

        for ($i = 0; $i < $num_years - 1; $i++) {
            $is_curr = $income_reports[$i];
            $cf_curr = $cash_flow_reports[$i];
            $bs_curr = $balance_reports[$i];
            $bs_prev = $balance_reports[$i+1];

            $net_income = $this->get_av_value($is_curr, 'netIncome');
            $d_and_a = $this->get_av_value($cf_curr, 'depreciationDepletionAndAmortization');
            $capex = abs($this->get_av_value($cf_curr, 'capitalExpenditures'));

            $nwc_curr = $this->get_av_value($bs_curr, 'totalCurrentAssets') - $this->get_av_value($bs_curr, 'cashAndCashEquivalentsAtCarryingValue') - $this->get_av_value($bs_curr, 'totalCurrentLiabilities') + $this->get_av_value($bs_curr, 'shortTermDebt');
            $nwc_prev = $this->get_av_value($bs_prev, 'totalCurrentAssets') - $this->get_av_value($bs_prev, 'cashAndCashEquivalentsAtCarryingValue') - $this->get_av_value($bs_prev, 'totalCurrentLiabilities') + $this->get_av_value($bs_prev, 'shortTermDebt');
            $change_in_nwc = $nwc_curr - $nwc_prev;

            $debt_curr = $this->get_av_value($bs_curr, 'longTermDebt') + $this->get_av_value($bs_curr, 'shortTermDebt');
            $debt_prev = $this->get_av_value($bs_prev, 'longTermDebt') + $this->get_av_value($bs_prev, 'shortTermDebt');
            $net_borrowing = $debt_curr - $debt_prev;

            $fcfe = $net_income + $d_and_a - $capex - $change_in_nwc + $net_borrowing;
            $historical_fcfe[] = $fcfe;
        }
        return array_reverse($historical_fcfe);
    }

    private function calculate_cagr($data_series, $years) {
        if (count($data_series) < $years + 1) {
            return null;
        }
        $series = array_slice($data_series, -($years + 1));
        
        $beginning_value = $series[0];
        $ending_value = end($series);

        if ($beginning_value <= 0 || $ending_value <= 0) {
            return null;
        }

        return pow(($ending_value / $beginning_value), (1 / $years)) - 1;
    }

    private function get_stage1_growth_rate($overview_data, $earnings_data) {
        if (!is_wp_error($earnings_data) && !empty($earnings_data['annualEarnings'])) {
            $current_year = date('Y');
            $full_year_earnings = array_filter($earnings_data['annualEarnings'], function($e) use ($current_year) {
                $fiscal_year = substr($e['fiscalDateEnding'], 0, 4);
                return $fiscal_year < $current_year;
            });

            $eps_series = array_map(function($e) { return $this->get_av_value($e, 'reportedEPS'); }, array_reverse($full_year_earnings));
            $five_year_eps_growth = $this->calculate_cagr($eps_series, 5);
            
            if ($five_year_eps_growth !== null && $five_year_eps_growth > 0) {
                return min($five_year_eps_growth, self::HISTORICAL_EPS_GROWTH_CAP);
            }
        }

        $peg_ratio = $this->get_av_value($overview_data, 'PEGRatio');
        $pe_ratio = $this->get_av_value($overview_data, 'PERatio');
        if ($peg_ratio > 0 && $pe_ratio > 0) {
            $analyst_growth = ($pe_ratio / $peg_ratio) / 100;
            if ($analyst_growth > 0) {
                return min($analyst_growth, self::ANALYST_PEG_GROWTH_CAP);
            }
        }
        
        return self::DEFAULT_GROWTH_FLOOR;
    }

    public function calculate($overview_data, $income_statement_data, $balance_sheet_data, $cash_flow_data, $earnings_data, $treasury_yield_data, $current_price, $beta_details = []) {
        $required_reports = ['annualReports', 'annualReports', 'annualReports'];
        $datasets = [$income_statement_data, $balance_sheet_data, $cash_flow_data];
        foreach($required_reports as $i => $key) {
            if (is_wp_error($datasets[$i]) || empty($datasets[$i][$key]) || count($datasets[$i][$key]) < self::MIN_YEARS_FOR_GROWTH_CALC) {
                return new WP_Error('dcf_missing_financials', __('DCF Error: At least 3 years of financial statements are required.', 'journey-to-wealth'));
            }
        }

        $income_reports = array_slice($income_statement_data['annualReports'], 0, self::MAX_YEARS_FOR_HISTORICAL_CALCS);
        $balance_reports = array_slice($balance_sheet_data['annualReports'], 0, self::MAX_YEARS_FOR_HISTORICAL_CALCS);
        $cash_flow_reports = array_slice($cash_flow_data['annualReports'], 0, self::MAX_YEARS_FOR_HISTORICAL_CALCS);
        
        $fcfe_series = $this->get_historical_fcfe($income_reports, $balance_reports, $cash_flow_reports);
        if(empty($fcfe_series)) return new WP_Error('dcf_calc_error', __('Could not calculate historical FCFE.', 'journey-to-wealth'));
        
        $risk_free_rate = $this->calculate_average_risk_free_rate($treasury_yield_data);
        $beta = $this->levered_beta ?? $beta_details['levered_beta'] ?? $this->get_av_value($overview_data, 'Beta');
        $this->cost_of_equity = $this->calculate_cost_of_equity($beta, $risk_free_rate);
        $this->terminal_growth_rate = $risk_free_rate;
        
        $base_cash_flow = end($fcfe_series);

        // **NEW**: Check for high CapEx companies and use Net Income as a proxy if needed.
        $latest_cf_report = $cash_flow_reports[0];
        $operating_cash_flow = $this->get_av_value($latest_cf_report, 'operatingCashflow');
        $capex = abs($this->get_av_value($latest_cf_report, 'capitalExpenditures'));

        if ($operating_cash_flow > 0 && ($capex / $operating_cash_flow) >= self::HIGH_CAPEX_THRESHOLD) {
            $base_cash_flow = $this->get_av_value($income_reports[0], 'netIncome');
        }

        if ($base_cash_flow <= 0) {
            $positive_cash_flows = array_filter($fcfe_series, function($cf) { return $cf > 0; });
            if (empty($positive_cash_flows)) return new WP_Error('dcf_negative_inputs', __('Company has no history of positive cash flows, cannot create valuation.', 'journey-to-wealth'));
            $base_cash_flow = end($positive_cash_flows);
        }

        if ($this->cost_of_equity <= $this->terminal_growth_rate) $this->terminal_growth_rate = $this->cost_of_equity - 0.005;

        $initial_growth_rate = $this->get_stage1_growth_rate($overview_data, $earnings_data);

        $projection_table = [];
        $sum_of_pv_cfs = 0;
        $future_cf = $base_cash_flow;

        for ($year = 1; $year <= $this->projection_years; $year++) {
            $decay_factor = ($year - 1) / ($this->projection_years - 1);
            $current_growth_rate = $initial_growth_rate * (1 - $decay_factor) + $this->terminal_growth_rate * $decay_factor;
            $future_cf *= (1 + $current_growth_rate);
            $discount_factor = pow((1 + $this->cost_of_equity), $year);
            $pv_of_cf = $future_cf / $discount_factor;
            $sum_of_pv_cfs += $pv_of_cf;
            $projection_table[] = ['year' => date('Y') + $year, 'cf' => $future_cf, 'pv_cf' => $pv_of_cf];
        }

        $terminal_value = ($future_cf * (1 + $this->terminal_growth_rate)) / ($this->cost_of_equity - $this->terminal_growth_rate);
        $pv_of_terminal_value = $terminal_value / pow((1 + $this->cost_of_equity), $this->projection_years);
        $total_equity_value = $sum_of_pv_cfs + $pv_of_terminal_value;
        
        $shares_outstanding = $this->get_av_value($overview_data, 'SharesOutstanding');
        if (empty($shares_outstanding)) return new WP_Error('dcf_missing_shares', __('DCF Error: Shares outstanding data not found.', 'journey-to-wealth'));

        $intrinsic_value_per_share = $total_equity_value / $shares_outstanding;
        
        return [
            'intrinsic_value_per_share' => round($intrinsic_value_per_share, 2),
            'calculation_breakdown' => [
                'model_name' => 'DCF Model (FCFE)',
                'inputs' => [ 'discount_rate' => $this->cost_of_equity, 'terminal_growth_rate' => $this->terminal_growth_rate, 'initial_growth_rate' => $initial_growth_rate, ],
                'discount_rate_calc' => [ 'risk_free_rate' => $risk_free_rate, 'risk_free_rate_source' => '5Y Average of 10Y Treasury', 'equity_risk_premium' => $this->equity_risk_premium, 'erp_source' => 'Plugin Setting', 'beta' => $beta, 'beta_source' => $beta_details['beta_source'] ?? 'Alpha Vantage', 'beta_details' => $beta_details, 'cost_of_equity_calc' => 'Risk-Free Rate + (Levered Beta * Equity Risk Premium)', 'wacc_details' => null, ],
                'projection_table' => $projection_table, 'sum_of_pv_cfs' => $sum_of_pv_cfs, 'terminal_value' => $terminal_value, 'pv_of_terminal_value' => $pv_of_terminal_value, 'total_equity_value' => $total_equity_value, 'shares_outstanding' => $shares_outstanding, 'current_price' => $current_price,
            ]
        ];
    }
}
