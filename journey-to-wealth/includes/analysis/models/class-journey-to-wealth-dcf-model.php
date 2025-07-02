<?php
/**
 * Discounted Cash Flow (DCF) Valuation Model for Journey to Wealth plugin.
 * Implements a 2-stage FCFE model using data from Alpha Vantage.
 *
 * @link       https://example.com/journey-to-wealth/
 * @since      2.2.5
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
    
    const MAX_YEARS_FOR_HISTORICAL_CALCS = 7;
    const MIN_YEARS_FOR_GROWTH_CALC = 3;
    
    const DEFAULT_HISTORICAL_GROWTH_CAP = 0.15;
    const DEFAULT_HISTORICAL_GROWTH_FLOOR = 0.02;
    const ANALYST_GROWTH_CAP = 0.35;
    const HEAVY_REINVESTMENT_THRESHOLD = 0.60;

    public function __construct($equity_risk_premium = null, $levered_beta = null) {
        $this->equity_risk_premium = is_numeric($equity_risk_premium) ? $equity_risk_premium : 0.055;
        $this->levered_beta = is_numeric($levered_beta) ? $levered_beta : null;
    }

    private function get_av_value($report, $key, $default = 0) {
        if (isset($report[$key]) && is_numeric($report[$key]) && $report[$key] !== 'None') {
            return (float)$report[$key];
        }
        return $default;
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
        if ($count > 0) {
            return ($sum / $count) / 100;
        }
        return self::DEFAULT_RISK_FREE_RATE;
    }

    private function calculate_cost_of_equity($beta, $risk_free_rate) {
        if ($beta > 0) {
            return $risk_free_rate + ($beta * $this->equity_risk_premium);
        }
        return self::DEFAULT_COST_OF_EQUITY;
    }

    private function calculate_single_fcfe($cash_flow_report) {
        $cash_from_ops = $this->get_av_value($cash_flow_report, 'operatingCashflow');
        $capex = $this->get_av_value($cash_flow_report, 'capitalExpenditures');
        return $cash_from_ops - abs($capex);
    }

    private function calculate_historical_fcfe_growth($cash_flow_reports) {
        if (count($cash_flow_reports) < self::MIN_YEARS_FOR_GROWTH_CALC) return null;
        
        $fcfes = [];
        $sorted_reports = array_reverse($cash_flow_reports); 
        foreach ($sorted_reports as $report) {
            $fcfe = $this->calculate_single_fcfe($report);
            if ($fcfe > 0) {
                $fcfes[] = $fcfe;
            }
        }

        if (count($fcfes) < 2) return null;

        $growth_rates = [];
        for ($i = 1; $i < count($fcfes); $i++) {
            if ($fcfes[$i-1] != 0) {
                $growth = ($fcfes[$i] - $fcfes[$i-1]) / abs($fcfes[$i-1]);
                $growth_rates[] = $growth;
            }
        }

        if (empty($growth_rates)) return null;

        $average_growth = array_sum($growth_rates) / count($growth_rates);
        return max(self::DEFAULT_HISTORICAL_GROWTH_FLOOR, min($average_growth, self::DEFAULT_HISTORICAL_GROWTH_CAP));
    }

    private function get_stage1_growth_rate($overview_data, $income_statement_data, $cash_flow_reports) {
        $forward_pe = $this->get_av_value($overview_data, 'ForwardPE');
        $target_price = $this->get_av_value($overview_data, 'AnalystTargetPrice');
        $last_annual_eps = isset($income_statement_data['annualReports'][1]) ? $this->get_av_value($income_statement_data['annualReports'][1], 'reportedEPS') : null;

        if ($forward_pe > 0 && $target_price > 0 && $last_annual_eps > 0) {
            $forward_eps = $target_price / $forward_pe;
            $analyst_growth = ($forward_eps - $last_annual_eps) / $last_annual_eps;
            if ($analyst_growth > 0) return min($analyst_growth, self::ANALYST_GROWTH_CAP);
        }
        
        $peg_ratio = $this->get_av_value($overview_data, 'PEGRatio');
        $pe_ratio = $this->get_av_value($overview_data, 'PERatio');
        if ($peg_ratio > 0 && $pe_ratio > 0) {
            $analyst_growth = ($pe_ratio / $peg_ratio) / 100;
            if ($analyst_growth > 0) return min($analyst_growth, self::ANALYST_GROWTH_CAP);
        }

        $historical_growth = $this->calculate_historical_fcfe_growth($cash_flow_reports);
        if ($historical_growth !== null) return $historical_growth;

        return self::DEFAULT_HISTORICAL_GROWTH_FLOOR;
    }

    public function calculate($overview_data, $income_statement_data, $balance_sheet_data, $cash_flow_data, $treasury_yield_data, $current_price, $beta_details = []) {
        if (is_wp_error($cash_flow_data) || empty($cash_flow_data['annualReports']) || is_wp_error($income_statement_data) || empty($income_statement_data['annualReports'])) {
            return new WP_Error('dcf_missing_financials', __('DCF Error: Annual cash flow or income statements are required.', 'journey-to-wealth'));
        }

        $risk_free_rate = $this->calculate_average_risk_free_rate($treasury_yield_data);
        
        $beta = $this->levered_beta ?? $this->get_av_value($overview_data, 'Beta');
        $beta_source = $beta_details['beta_source'] ?? 'Alpha Vantage';

        if ($beta === 1.0 && $beta_source === 'Default') {
            $beta = $this->get_av_value($overview_data, 'Beta');
            $beta_source = 'Alpha Vantage (Fallback)';
        }

        $this->cost_of_equity = $this->calculate_cost_of_equity($beta, $risk_free_rate);
        $this->terminal_growth_rate = $risk_free_rate;

        if ($this->cost_of_equity <= $this->terminal_growth_rate) {
            $this->terminal_growth_rate = $this->cost_of_equity - 0.0025;
        }

        $cash_flow_reports = array_slice($cash_flow_data['annualReports'], 0, self::MAX_YEARS_FOR_HISTORICAL_CALCS);
        $initial_growth_rate = $this->get_stage1_growth_rate($overview_data, $income_statement_data, $cash_flow_reports);
        
        $latest_cash_flow_report = $cash_flow_reports[0];
        $operating_cash_flow = $this->get_av_value($latest_cash_flow_report, 'operatingCashflow');
        $capex = $this->get_av_value($latest_cash_flow_report, 'capitalExpenditures');
        $base_fcfe = $operating_cash_flow - abs($capex);

        if ($operating_cash_flow > 0 && (abs($capex) / $operating_cash_flow) >= self::HEAVY_REINVESTMENT_THRESHOLD) {
            $base_fcfe = $this->get_av_value($income_statement_data['annualReports'][0], 'netIncome');
        }
        
        if ($base_fcfe <= 0) return new WP_Error('dcf_negative_inputs', __('Normalized cash flow is negative.', 'journey-to-wealth'));
        
        $projection_table = [];
        $sum_of_pv_fcfs = 0;
        $future_fcfe = $base_fcfe;

        for ($year = 1; $year <= $this->projection_years; $year++) {
            $decay_factor = ($year - 1) / ($this->projection_years - 1);
            $current_growth_rate = $initial_growth_rate * (1 - $decay_factor) + $this->terminal_growth_rate * $decay_factor;
            $future_fcfe *= (1 + $current_growth_rate);
            $discount_factor = pow((1 + $this->cost_of_equity), $year);
            $pv_of_fcfe = $future_fcfe / $discount_factor;
            $sum_of_pv_fcfs += $pv_of_fcfe;

            $projection_table[] = [
                'year' => date('Y') + $year,
                'fcfe' => $future_fcfe,
                'pv_fcfe' => $pv_of_fcfe
            ];
        }

        $terminal_year_fcfe = $future_fcfe;
        $terminal_value = ($terminal_year_fcfe * (1 + $this->terminal_growth_rate)) / ($this->cost_of_equity - $this->terminal_growth_rate);
        $pv_of_terminal_value = $terminal_value / pow((1 + $this->cost_of_equity), $this->projection_years);
        $total_equity_value = $sum_of_pv_fcfs + $pv_of_terminal_value;
        
        $shares_outstanding = $this->get_av_value($overview_data, 'SharesOutstanding');
        if (empty($shares_outstanding)) {
            return new WP_Error('dcf_missing_shares', __('DCF Error: Shares outstanding data not found.', 'journey-to-wealth'));
        }
        
        $intrinsic_value_per_share = $total_equity_value / $shares_outstanding;
        
        $calculation_breakdown = [
            'model_name' => 'DCF Model', // **FIXED** Add the model name here
            'inputs' => [
                'model_type' => '2 Stage Free Cash Flow to Equity',
                'fcf_source' => 'Calculated from Alpha Vantage Data',
                'cost_of_equity' => $this->cost_of_equity,
                'perpetual_growth_rate' => $this->terminal_growth_rate,
                'perpetual_growth_rate_source' => 'Risk-Free Rate',
            ],
            'discount_rate_calc' => [
                'risk_free_rate' => $risk_free_rate,
                'risk_free_rate_source' => '5Y Average of 10Y Treasury',
                'equity_risk_premium' => $this->equity_risk_premium,
                'erp_source' => 'Plugin Setting',
                'beta' => $beta,
                'beta_source' => $beta_source,
                'beta_details' => $beta_details,
                'cost_of_equity_calc' => 'Risk-Free Rate + (Levered Beta * Equity Risk Premium)',
            ],
            'projection_table' => $projection_table,
            'sum_of_pv_fcfs' => $sum_of_pv_fcfs,
            'terminal_value_calc' => [
                'label' => 'Terminal Value',
                'calc' => '(FCF in Year 10 * (1 + Perpetual Growth Rate)) / (Discount Rate - Perpetual Growth Rate)',
                'result' => '$' . number_format($terminal_value / 1e6, 0) . 'm',
            ],
            'pv_terminal_value_calc' => [
                'label' => 'PV of Terminal Value',
                'calc' => 'Terminal Value / (1 + Discount Rate)^10',
                'result' => '$' . number_format($pv_of_terminal_value / 1e6, 0) . 'm',
            ],
            'equity_value_calc' => [
                'label' => 'Total Equity Value',
                'calc' => 'Sum of PV of FCFs + PV of Terminal Value',
                'result' => '$' . number_format($total_equity_value / 1e6, 0) . 'm',
            ],
            'value_per_share_calc' => [
                'label' => 'Implied Value Per Share',
                'calc' => 'Total Equity Value / Shares Outstanding',
                'result' => '$' . number_format($intrinsic_value_per_share, 2),
            ],
            'discount_calc' => [
                'label' => 'Discount / Premium',
                'calc' => '(Implied Value Per Share - Current Price) / Current Price',
                'result' => number_format((($intrinsic_value_per_share - $current_price) / $current_price) * 100, 1) . '%',
            ]
        ];

        return [
            'intrinsic_value_per_share' => round($intrinsic_value_per_share, 2),
            'calculation_breakdown' => $calculation_breakdown
        ];
    }
}
