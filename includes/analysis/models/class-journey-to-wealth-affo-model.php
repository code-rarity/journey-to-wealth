<?php
/**
 * Adjusted Funds From Operations (AFFO) 2-Stage DCF Model for REITs.
 * Replicates the Simply Wall St. methodology.
 *
 * @link       https://example.com/journey-to-wealth/
 * @since      3.1.0
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
    private $terminal_growth_rate;
    private $equity_risk_premium;
    private $levered_beta;
    private $projection_years = 10;

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

    private function calculate_single_affo($income_report, $cash_flow_report) {
        $net_income = $this->get_av_value($income_report, 'netIncome');
        $depreciation_amortization = $this->get_av_value($income_report, 'depreciationAndAmortization');
        $capex = $this->get_av_value($cash_flow_report, 'capitalExpenditures');
        
        $affo = $net_income + $depreciation_amortization - abs($capex);
        
        return $affo;
    }

    private function calculate_historical_affo_growth($income_statement, $cash_flow) {
        $annual_affo_values = [];
        $annual_income = array_reverse($income_statement['annualReports']);
        $annual_cash_flow = array_reverse($cash_flow['annualReports']);

        $income_map = [];
        foreach($annual_income as $report) {
            $income_map[$report['fiscalDateEnding']] = $report;
        }

        $cash_flow_map = [];
        foreach($annual_cash_flow as $report) {
            $cash_flow_map[$report['fiscalDateEnding']] = $report;
        }

        $sorted_dates = array_keys($income_map);
        sort($sorted_dates);

        foreach ($sorted_dates as $date) {
            if (isset($cash_flow_map[$date])) {
                $affo = $this->calculate_single_affo($income_map[$date], $cash_flow_map[$date]);
                if ($affo > 0) {
                    $annual_affo_values[] = $affo;
                }
            }
        }
        
        if (count($annual_affo_values) < self::MIN_YEARS_FOR_AFFO_GROWTH) {
            return self::DEFAULT_AFFO_HISTORICAL_GROWTH_FLOOR;
        }

        $growth_rates = [];
        for ($i = 1; $i < count($annual_affo_values); $i++) {
            if ($annual_affo_values[$i-1] > 0) {
                $growth = ($annual_affo_values[$i] - $annual_affo_values[$i-1]) / $annual_affo_values[$i-1];
                $growth_rates[] = $growth;
            }
        }

        if (empty($growth_rates)) return self::DEFAULT_AFFO_HISTORICAL_GROWTH_FLOOR;

        $average_growth = array_sum($growth_rates) / count($growth_rates);
        return max(self::DEFAULT_AFFO_HISTORICAL_GROWTH_FLOOR, min($average_growth, self::DEFAULT_AFFO_HISTORICAL_GROWTH_CAP));
    }

    public function calculate($overview, $income_statement, $cash_flow, $treasury_yield, $latest_price, $beta_details) {
        if (is_wp_error($income_statement) || empty($income_statement['annualReports']) || is_wp_error($cash_flow) || empty($cash_flow['annualReports'])) {
            return new WP_Error('affo_missing_financials', __('AFFO Model: Insufficient financial statements.', 'journey-to-wealth'));
        }

        $risk_free_rate = (new Journey_To_Wealth_DCF_Model())->calculate_average_risk_free_rate($treasury_yield);
        $this->cost_of_equity = $risk_free_rate + ($this->levered_beta * $this->equity_risk_premium);
        $this->terminal_growth_rate = $risk_free_rate;

        $latest_is_report = $income_statement['annualReports'][0];
        $latest_cf_report = $cash_flow['annualReports'][0];
        
        $base_affo = $this->calculate_single_affo($latest_is_report, $latest_cf_report);
        if ($base_affo <= 0) {
            return new WP_Error('affo_base_affo_error', __('AFFO Model: Base AFFO is zero or negative, valuation cannot be completed.', 'journey-to-wealth'));
        }

        $initial_growth_rate = $this->calculate_historical_affo_growth($income_statement, $cash_flow);
        if ($this->cost_of_equity <= $this->terminal_growth_rate) {
            $this->terminal_growth_rate = $this->cost_of_equity - 0.0025;
        }

        $projected_affos = [];
        $projection_table = [];
        $current_affo = $base_affo;
        $growth_reduction_per_year = ($initial_growth_rate - $this->terminal_growth_rate) / $this->projection_years;

        $sum_discounted_affos = 0;
        $current_year = (int)date('Y');

        for ($i = 1; $i <= $this->projection_years; $i++) {
            $current_growth_rate = max($this->terminal_growth_rate, $initial_growth_rate - ($growth_reduction_per_year * ($i - 1)));
            $current_affo *= (1 + $current_growth_rate);
            $projected_affos[] = $current_affo;
            
            $pv_affo = $current_affo / pow((1 + $this->cost_of_equity), $i);
            $sum_discounted_affos += $pv_affo;

            $projection_table[] = [
                'year' => $current_year + $i,
                'affo' => $current_affo,
                'pv_affo' => $pv_affo
            ];
        }

        $affo_year_n = end($projected_affos);
        $terminal_value = ($affo_year_n * (1 + $this->terminal_growth_rate)) / ($this->cost_of_equity - $this->terminal_growth_rate);
        $discounted_terminal_value = $terminal_value / pow((1 + $this->cost_of_equity), $this->projection_years);
        
        // --- Calculation Fix ---
        $total_equity_value = (float)$sum_discounted_affos + (float)$discounted_terminal_value;
        $shares_outstanding = (float)$this->get_av_value($overview, 'SharesOutstanding');

        if ($shares_outstanding == 0) {
            return new WP_Error('affo_missing_shares', __('AFFO Model Error: Shares outstanding is zero.', 'journey-to-wealth'));
        }
        
        $intrinsic_value_per_share = $total_equity_value / $shares_outstanding;

        return [
            'intrinsic_value_per_share' => round($intrinsic_value_per_share, 2),
            'calculation_breakdown' => [
                'model_name' => 'AFFO Model',
                'inputs' => [
                    'cost_of_equity' => $this->cost_of_equity,
                    'terminal_growth_rate' => $this->terminal_growth_rate,
                    'initial_growth_rate' => $initial_growth_rate,
                ],
                'discount_rate_calc' => [
                    'risk_free_rate' => $risk_free_rate,
                    'risk_free_rate_source' => '5-Year Average of US Long-Term Govt Bond Rate',
                    'equity_risk_premium' => $this->equity_risk_premium,
                    'erp_source' => 'Plugin Setting',
                    'beta' => $this->levered_beta,
                    'beta_source' => $beta_details['beta_source'],
                    'beta_details' => $beta_details,
                    'cost_of_equity_calc' => 'Risk-Free Rate + (Levered Beta * Equity Risk Premium)',
                ],
                'projection_table' => $projection_table,
                'sum_of_pv_affos' => $sum_discounted_affos,
                'terminal_value' => $terminal_value,
                'pv_of_terminal_value' => $discounted_terminal_value,
                'total_equity_value' => $total_equity_value,
                'shares_outstanding' => $shares_outstanding,
                'current_price' => $latest_price,
            ]
        ];
    }
}
