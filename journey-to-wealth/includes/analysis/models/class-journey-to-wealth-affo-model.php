<?php
/**
 * Adjusted Funds From Operations (AFFO) 2-Stage DCF Model for REITs.
 * Refactored to use data from Alpha Vantage.
 *
 * @link       https://example.com/journey-to-wealth/
 * @since      3.0.0
 *
 * @package    Journey_To_Wealth
 * @subpackage Journey_To_Wealth/includes/analysis/models
 */

// Prevent direct access to this file.
if ( ! defined( 'WPINC' ) ) {
    die;
}

class Journey_To_Wealth_AFFO_Model {

    private $cost_of_equity; 
    private $projection_years = 10; 
    private $terminal_growth_rate; 
    private $equity_risk_premium;
    private $levered_beta;

    const DEFAULT_AFFO_COST_OF_EQUITY = 0.09;  
    const MAX_YEARS_FOR_AFFO_GROWTH_CALC = 7;
    const DEFAULT_AFFO_HISTORICAL_GROWTH_CAP = 0.15; 
    const DEFAULT_AFFO_HISTORICAL_GROWTH_FLOOR = 0.01; 
    const MIN_YEARS_FOR_AFFO_GROWTH = 3;

    public function __construct($equity_risk_premium, $levered_beta) {
        $this->equity_risk_premium = $equity_risk_premium;
        $this->levered_beta = $levered_beta;
    }
    
    private function get_av_value($report, $key, $default = 0) {
        if (isset($report[$key]) && is_numeric($report[$key]) && $report[$key] !== 'None') {
            return (float)$report[$key];
        }
        return $default;
    }

    private function calculate_single_affo($cash_flow_report) {
        $ffo = $this->get_av_value($cash_flow_report, 'fundsFromOperations');
        // Using depreciation as a proxy for recurring CapEx
        $recurring_capex = $this->get_av_value($cash_flow_report, 'depreciationDepletionAndAmortization');
        return $ffo - $recurring_capex;
    }

    private function calculate_historical_affo_growth($cash_flow_reports) {
        $annual_affo_values = [];
        $sorted_reports = array_reverse($cash_flow_reports);

        foreach ($sorted_reports as $report) {
            $affo = $this->calculate_single_affo($report);
            if ($affo > 0) {
                $annual_affo_values[] = $affo;
            }
        }
        
        if (count($annual_affo_values) < self::MIN_YEARS_FOR_AFFO_GROWTH) {
            return null;
        }

        $growth_rates = [];
        for ($i = 1; $i < count($annual_affo_values); $i++) {
            if ($annual_affo_values[$i-1] > 0) {
                $growth = ($annual_affo_values[$i] - $annual_affo_values[$i-1]) / $annual_affo_values[$i-1];
                $growth_rates[] = $growth;
            }
        }

        if (empty($growth_rates)) return null;

        $average_growth = array_sum($growth_rates) / count($growth_rates);
        return max(self::DEFAULT_AFFO_HISTORICAL_GROWTH_FLOOR, min($average_growth, self::DEFAULT_AFFO_HISTORICAL_GROWTH_CAP));
    }

    public function calculate($overview, $cash_flow, $treasury_yield, $current_price, $beta_details) {
        if (is_wp_error($cash_flow) || empty($cash_flow['annualReports'])) {
            return new WP_Error('affo_missing_financials', __('AFFO Model: Insufficient financial statements.', 'journey-to-wealth'));
        }

        $risk_free_rate = (new Journey_To_Wealth_DCF_Model())->calculate_average_risk_free_rate($treasury_yield);
        $this->cost_of_equity = $risk_free_rate + ($this->levered_beta * $this->equity_risk_premium);
        $this->terminal_growth_rate = $risk_free_rate;

        if ($this->cost_of_equity <= $this->terminal_growth_rate) {
            $this->terminal_growth_rate = $this->cost_of_equity - 0.0025;
        }
        
        $base_affo = $this->calculate_single_affo($cash_flow['annualReports'][0]);
        if ($base_affo <= 0) {
            return new WP_Error('affo_base_affo_error', __('AFFO Model: Base AFFO is zero or negative.', 'journey-to-wealth'));
        }

        $cash_flow_reports = array_slice($cash_flow['annualReports'], 0, self::MAX_YEARS_FOR_AFFO_GROWTH_CALC);
        $initial_growth_rate = $this->calculate_historical_affo_growth($cash_flow_reports);
        if ($initial_growth_rate === null) { 
            $initial_growth_rate = self::DEFAULT_AFFO_HISTORICAL_GROWTH_FLOOR; 
        }

        $projection_table = [];
        $sum_of_pv_affos = 0;
        $future_affo = $base_affo;

        for ($year = 1; $year <= $this->projection_years; $year++) {
            $decay_factor = ($year - 1) / ($this->projection_years - 1);
            $current_growth_rate = $initial_growth_rate * (1 - $decay_factor) + $this->terminal_growth_rate * $decay_factor;
            $future_affo *= (1 + $current_growth_rate);
            $discount_factor = pow((1 + $this->cost_of_equity), $year);
            $pv_of_affo = $future_affo / $discount_factor;
            $sum_of_pv_affos += $pv_of_affo;

            $projection_table[] = [
                'year' => date('Y') + $year,
                'affo' => $future_affo,
                'pv_affo' => $pv_of_affo
            ];
        }

        $terminal_year_affo = $future_affo;
        $terminal_value = ($terminal_year_affo * (1 + $this->terminal_growth_rate)) / ($this->cost_of_equity - $this->terminal_growth_rate);
        $pv_of_terminal_value = $terminal_value / pow((1 + $this->cost_of_equity), $this->projection_years);
        $total_equity_value = $sum_of_pv_affos + $pv_of_terminal_value;
        
        $shares_outstanding = $this->get_av_value($overview, 'SharesOutstanding');
        if (empty($shares_outstanding)) {
            return new WP_Error('affo_missing_shares', __('AFFO Model Error: Shares outstanding data missing.', 'journey-to-wealth'));
        }
        
        $intrinsic_value_per_share = $total_equity_value / $shares_outstanding;

        return [
            'intrinsic_value_per_share' => round($intrinsic_value_per_share, 2),
            'calculation_breakdown' => [
                'model_name' => 'AFFO Model',
                'discount_rate_calc' => [
                    'risk_free_rate' => $risk_free_rate,
                    'risk_free_rate_source' => '5Y Average of 10Y Treasury',
                    'equity_risk_premium' => $this->equity_risk_premium,
                    'erp_source' => 'Plugin Setting',
                    'beta' => $this->levered_beta,
                    'beta_source' => $beta_details['beta_source'],
                    'beta_details' => $beta_details,
                    'cost_of_equity_calc' => 'Risk-Free Rate + (Levered Beta * Equity Risk Premium)',
                ],
                'inputs' => [
                    'cost_of_equity' => $this->cost_of_equity,
                ],
                'base_affo' => $base_affo,
                'initial_growth_rate' => $initial_growth_rate,
                'terminal_growth_rate' => $this->terminal_growth_rate,
                'projection_table' => $projection_table,
                'sum_of_pv_affos' => $sum_of_pv_affos,
                'terminal_value' => $terminal_value,
                'pv_of_terminal_value' => $pv_of_terminal_value,
                'total_equity_value' => $total_equity_value,
                'shares_outstanding' => $shares_outstanding,
                'intrinsic_value_per_share' => $intrinsic_value_per_share,
                'current_price' => $current_price,
            ]
        ];
    }
}
