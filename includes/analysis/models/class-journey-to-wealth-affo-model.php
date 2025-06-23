<?php
/**
 * Adjusted Funds From Operations (AFFO) 2-Stage DCF Model for REITs.
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

class Journey_To_Wealth_AFFO_Model {

    private $cost_of_equity; 
    private $projection_years = 10; 
    private $terminal_growth_rate_affo; 

    // Defaults & Constants
    const DEFAULT_AFFO_COST_OF_EQUITY = 0.09;  
    const DEFAULT_AFFO_TERMINAL_GROWTH = 0.025; 
    const MAX_YEARS_FOR_AFFO_GROWTH_CALC = 7; // Using up to 7 years of data for growth calculation
    const DEFAULT_AFFO_HISTORICAL_GROWTH_CAP = 0.15; 
    const DEFAULT_AFFO_HISTORICAL_GROWTH_FLOOR = 0.01; 
    const MIN_YEARS_FOR_AFFO_GROWTH = 3; // Need at least 3 years of AFFO for 2 growth periods

    /**
     * Constructor.
     * @param float $cost_of_equity Optional. The discount rate (cost of equity).
     */
    public function __construct($cost_of_equity = null) {
        $this->cost_of_equity = (is_numeric($cost_of_equity) && $cost_of_equity > 0 && $cost_of_equity < 1)
                                ? (float) $cost_of_equity
                                : self::DEFAULT_AFFO_COST_OF_EQUITY;
        
        $this->terminal_growth_rate_affo = self::DEFAULT_AFFO_TERMINAL_GROWTH;
        if ($this->cost_of_equity <= $this->terminal_growth_rate_affo) {
            $this->terminal_growth_rate_affo = max(0, $this->cost_of_equity - 0.01);
        }
    }

    /**
     * Calculates Funds From Operations (FFO) from a single year's data.
     */
    private function calculate_single_ffo($income_statement_report, $cash_flow_report, &$log_messages) {
        if (!isset($income_statement_report['netIncome']) || !is_numeric($income_statement_report['netIncome'])) {
            $log_messages[] = __('AFFO Model: Net Income missing for FFO calculation.', 'journey-to-wealth');
            return null;
        }
        $net_income = (float) $income_statement_report['netIncome'];

        $depreciation_amortization = 0;
        if (isset($income_statement_report['depreciationAndAmortization']) && is_numeric($income_statement_report['depreciationAndAmortization'])) {
            $depreciation_amortization = (float) $income_statement_report['depreciationAndAmortization'];
        } elseif (isset($cash_flow_report['depreciationAndAmortization']) && is_numeric($cash_flow_report['depreciationAndAmortization'])) {
            $depreciation_amortization = (float) $cash_flow_report['depreciationAndAmortization'];
            $log_messages[] = __('AFFO Model: Used D&A from Cash Flow statement for FFO.', 'journey-to-wealth');
        } elseif (isset($cash_flow_report['depreciation']) && is_numeric($cash_flow_report['depreciation'])) { 
            $depreciation_amortization = (float) $cash_flow_report['depreciation'];
            $log_messages[] = __('AFFO Model: Used Depreciation (only) from Cash Flow statement for FFO.', 'journey-to-wealth');
        } 
        
        $gain_on_sale_of_property = 0;
        if (isset($income_statement_report['gainOnSaleOfFixedAssetsAndDisposalOfBusiness']) && is_numeric($income_statement_report['gainOnSaleOfFixedAssetsAndDisposalOfBusiness'])) {
            $gain_on_sale_of_property = (float) $income_statement_report['gainOnSaleOfFixedAssetsAndDisposalOfBusiness'];
        } elseif (isset($income_statement_report['gainOnSaleOfPpe']) && is_numeric($income_statement_report['gainOnSaleOfPpe'])) {
             $gain_on_sale_of_property = (float) $income_statement_report['gainOnSaleOfPpe'];
        }
        
        $ffo = $net_income + $depreciation_amortization - $gain_on_sale_of_property;
        return $ffo;
    }

    /**
     * Estimates AFFO from FFO.
     */
    private function calculate_single_affo($ffo, $cash_flow_report, &$log_messages) {
        if ($ffo === null) return null;

        $recurring_capex_proxy = 0;
        if (isset($cash_flow_report['depreciationAndAmortization']) && is_numeric($cash_flow_report['depreciationAndAmortization'])) {
            $recurring_capex_proxy = (float) $cash_flow_report['depreciationAndAmortization'];
        } elseif (isset($cash_flow_report['depreciation']) && is_numeric($cash_flow_report['depreciation'])) {
            $recurring_capex_proxy = (float) $cash_flow_report['depreciation'];
        } else {
            $log_messages[] = __('AFFO Model: D&A not found for recurring CapEx proxy. AFFO will equal FFO.', 'journey-to-wealth');
        }
        
        $affo = $ffo - $recurring_capex_proxy; 
        if ($recurring_capex_proxy != 0) {
             $log_messages[] = sprintf(__('AFFO Model: Estimated Recurring CapEx (using D&A as proxy): %.2f', 'journey-to-wealth'), $recurring_capex_proxy);
        }
        return $affo;
    }

    /**
     * Calculates historical average annual growth rate of AFFO.
     */
    private function calculate_historical_affo_growth($income_statement_data, $cash_flow_data, &$log_messages) {
        $annual_affo_values = [];
        if (!is_array($income_statement_data) || !is_array($cash_flow_data) || empty($income_statement_data) || empty($cash_flow_data)) {
            $log_messages[] = __('AFFO Model: Insufficient financial statements for AFFO growth.', 'journey-to-wealth');
            return null;
        }

        $cf_by_year = [];
        foreach ($cash_flow_data as $cf_report) {
            if (isset($cf_report['fiscalDateEnding'])) {
                $cf_by_year[substr($cf_report['fiscalDateEnding'], 0, 4)] = $cf_report;
            }
        }

        foreach ($income_statement_data as $is_report) {
            if (isset($is_report['fiscalDateEnding'])) {
                $year = substr($is_report['fiscalDateEnding'], 0, 4);
                if (isset($cf_by_year[$year])) {
                    $ffo = $this->calculate_single_ffo($is_report, $cf_by_year[$year], $log_messages);
                    if ($ffo !== null) {
                        $affo = $this->calculate_single_affo($ffo, $cf_by_year[$year], $log_messages);
                        if ($affo !== null && $affo > 0) { 
                            $annual_affo_values[$year] = $affo;
                        }
                    }
                }
            }
        }
        
        if (count($annual_affo_values) < self::MIN_YEARS_FOR_AFFO_GROWTH) {
            $log_messages[] = sprintf(__('AFFO Model: Insufficient years of positive AFFO data (%d found, %d required) for growth calculation.', 'journey-to-wealth'), count($annual_affo_values), self::MIN_YEARS_FOR_AFFO_GROWTH);
            return null;
        }

        ksort($annual_affo_values);
        // Use up to MAX_YEARS_FOR_AFFO_GROWTH_CALC for calculation (e.g., last 7 AFFO values for 6 growth periods)
        $relevant_affo_values_map = array_slice($annual_affo_values, -(self::MAX_YEARS_FOR_AFFO_GROWTH_CALC + 1), null, true);
        $relevant_affo_values = array_values($relevant_affo_values_map);
        
        if (count($relevant_affo_values) < 2) {
            $log_messages[] = __('AFFO Model: Not enough recent AFFO data points for growth.', 'journey-to-wealth');
            return null;
        }

        $growth_rates = [];
        for ($i = 1; $i < count($relevant_affo_values); $i++) {
            if ($relevant_affo_values[$i-1] > 0.0001) {
                $growth = ($relevant_affo_values[$i] - $relevant_affo_values[$i-1]) / $relevant_affo_values[$i-1];
                $growth_rates[] = $growth;
            }
        }

        if (empty($growth_rates)) {
            $log_messages[] = __('AFFO Model: Could not calculate valid historical AFFO growth rates.', 'journey-to-wealth');
            return null;
        }
        $average_growth = array_sum($growth_rates) / count($growth_rates);
        $calculated_g = max(self::DEFAULT_AFFO_HISTORICAL_GROWTH_FLOOR, min($average_growth, self::DEFAULT_AFFO_HISTORICAL_GROWTH_CAP));
        $log_messages[] = sprintf(__('AFFO Model: Calculated historical AFFO growth rate: %.2f%% (adjusted from %.2f%%, based on up to %d growth periods).', 'journey-to-wealth'), $calculated_g * 100, $average_growth * 100, count($growth_rates));
        return (float) $calculated_g;
    }


    /**
     * Calculates intrinsic value using the 2-Stage AFFO DCF Model.
     */
    public function calculate($income_statement_data, $cash_flow_data, $company_overview, &$log_messages) {
        // ... (Rest of the calculate method remains the same as your last confirmed version) ...
        // It will now use the potentially more robust initial_growth_rate from the updated
        // calculate_historical_affo_growth method.
        $log_messages[] = __('Attempting AFFO 2-Stage DCF Model calculation.', 'journey-to-wealth');

        if (!is_array($income_statement_data) || empty($income_statement_data) || !is_array($cash_flow_data) || empty($cash_flow_data)) {
            return new WP_Error('affo_missing_financials', __('AFFO Model: Insufficient financial statements.', 'journey-to-wealth'));
        }
        usort($income_statement_data, function($a, $b) { return isset($a['fiscalDateEnding'], $b['fiscalDateEnding']) ? strtotime($b['fiscalDateEnding']) - strtotime($a['fiscalDateEnding']) : 0; });
        usort($cash_flow_data, function($a, $b) { return isset($a['fiscalDateEnding'], $b['fiscalDateEnding']) ? strtotime($b['fiscalDateEnding']) - strtotime($a['fiscalDateEnding']) : 0; });
        
        $latest_is_report = $income_statement_data[0];
        $latest_cf_report = null;
        $latest_is_year = substr($latest_is_report['fiscalDateEnding'], 0, 4);
        foreach($cash_flow_data as $cf_report_item) {
            if (substr($cf_report_item['fiscalDateEnding'], 0, 4) === $latest_is_year) {
                $latest_cf_report = $cf_report_item; break;
            }
        }
        if ($latest_cf_report === null && !empty($cash_flow_data)) { 
            $latest_cf_report = $cash_flow_data[0];
            $log_messages[] = __('AFFO Model: Could not find exact year match for latest Cash Flow report, using latest available.', 'journey-to-wealth');
        }
        if ($latest_cf_report === null) {
             return new WP_Error('affo_missing_latest_cf', __('AFFO Model: Latest cash flow report missing for base AFFO.', 'journey-to-wealth'));
        }

        $base_ffo = $this->calculate_single_ffo($latest_is_report, $latest_cf_report, $log_messages);
        if ($base_ffo === null) return new WP_Error('affo_base_ffo_error', __('AFFO Model: Could not calculate base FFO.', 'journey-to-wealth'));
        $base_affo = $this->calculate_single_affo($base_ffo, $latest_cf_report, $log_messages);
        if ($base_affo === null) return new WP_Error('affo_base_affo_error', __('AFFO Model: Could not calculate base AFFO.', 'journey-to-wealth'));
        if ($base_affo <= 0) {
            $log_messages[] = sprintf(__('AFFO Model Warning: Base AFFO is $%.2f (zero or negative). Projections are speculative.', 'journey-to-wealth'), $base_affo);
        }

        $initial_growth_rate = $this->calculate_historical_affo_growth($income_statement_data, $cash_flow_data, $log_messages);
        if ($initial_growth_rate === null) { 
            $initial_growth_rate = self::DEFAULT_AFFO_HISTORICAL_GROWTH_FLOOR; 
            $log_messages[] = __('AFFO Model: Using default floor growth rate for AFFO projection.', 'journey-to-wealth');
        }

        $projected_affos = [];
        $current_affo = $base_affo;
        $current_growth_rate = $initial_growth_rate;
        $growth_reduction_per_year = 0;
        if ($this->projection_years > 1 && $initial_growth_rate > $this->terminal_growth_rate_affo) {
            $growth_reduction_per_year = ($initial_growth_rate - $this->terminal_growth_rate_affo) / ($this->projection_years);
        } elseif ($initial_growth_rate <= $this->terminal_growth_rate_affo) {
            $current_growth_rate = max($initial_growth_rate, $this->terminal_growth_rate_affo);
             $log_messages[] = sprintf(__('AFFO Model: Initial growth (%.1f%%) not significantly above terminal (%.1f%%). Adjusted projection growth.', 'journey-to-wealth'), $initial_growth_rate*100, $this->terminal_growth_rate_affo*100);
        }

        for ($i = 1; $i <= $this->projection_years; $i++) {
            $current_affo *= (1 + $current_growth_rate);
            $projected_affos[] = $current_affo;
            if ($initial_growth_rate > $this->terminal_growth_rate_affo) {
                $current_growth_rate = max($this->terminal_growth_rate_affo, $current_growth_rate - $growth_reduction_per_year);
            }
        }

        $sum_discounted_affos = 0;
        for ($i = 0; $i < $this->projection_years; $i++) {
            $sum_discounted_affos += $projected_affos[$i] / pow((1 + $this->cost_of_equity), $i + 1);
        }

        $affo_year_n = end($projected_affos);
        $terminal_value_affo = 0;
        if (($this->cost_of_equity - $this->terminal_growth_rate_affo) > 0.0001) {
            $terminal_value_affo = ($affo_year_n * (1 + $this->terminal_growth_rate_affo)) / ($this->cost_of_equity - $this->terminal_growth_rate_affo);
        } else {
            $log_messages[] = __('AFFO Model Warning: Cost of equity not sufficiently greater than terminal growth. Terminal value may be unreliable.', 'journey-to-wealth');
        }
        $discounted_terminal_value_affo = $terminal_value_affo / pow((1 + $this->cost_of_equity), $this->projection_years);
        $total_equity_value = $sum_discounted_affos + $discounted_terminal_value_affo;

        $shares_outstanding = null;
        if (is_array($company_overview) && isset($company_overview['SharesOutstanding']) && is_numeric($company_overview['SharesOutstanding']) && (float)$company_overview['SharesOutstanding'] > 0) {
            $shares_outstanding = (float) $company_overview['SharesOutstanding'];
        } 
        if ($shares_outstanding === null || $shares_outstanding <= 0) {
            return new WP_Error('affo_missing_shares', __('AFFO Model Error: Shares outstanding data missing or invalid.', 'journey-to-wealth'));
        }
        $intrinsic_value_per_share = $total_equity_value / $shares_outstanding;

        return array(
            'intrinsic_value_per_share' => round($intrinsic_value_per_share, 2),
            'model_used'                => __('AFFO 2-Stage Model', 'journey-to-wealth'),
            'base_affo'                 => round($base_affo, 0),
            'initial_affo_growth_used'  => round($initial_growth_rate * 100, 2), 
            'terminal_affo_growth_used' => round($this->terminal_growth_rate_affo * 100, 2), 
            'cost_of_equity_used'       => round($this->cost_of_equity * 100, 2), 
            'log_messages'              => $log_messages
        );
    }

    public function get_interpretation( $intrinsic_value, $current_market_price ) {
        // ... (interpretation logic remains the same) ...
        if (!is_numeric($intrinsic_value) || !is_numeric($current_market_price) || $current_market_price == 0) {
            if (!is_numeric($intrinsic_value)) { return __('AFFO Model Fair Value Estimate could not be determined numerically.', 'journey-to-wealth'); }
            return __('Cannot provide full AFFO interpretation due to missing current market price.', 'journey-to-wealth');
        }
        $ivps = (float) $intrinsic_value; $cmp = (float) $current_market_price;
        $diff_pct = (($ivps - $cmp) / $cmp) * 100; $status_text = '';
        if ($ivps < 0) { $status_text = __('AFFO model resulted in a negative value.', 'journey-to-wealth');
        } elseif ($diff_pct > 20) { $status_text = sprintf(__('suggests potential undervaluation by %.1f%%.', 'journey-to-wealth'), $diff_pct);
        } elseif ($diff_pct < -20) { $status_text = sprintf(__('suggests potential overvaluation by %.1f%%.', 'journey-to-wealth'), abs($diff_pct));
        } else { $status_text = sprintf(__('suggests relative fair valuation (difference of %.1f%%).', 'journey-to-wealth'), $diff_pct); }
        return sprintf(__('AFFO Model Fair Value Estimate: $%.2f. Current Price: $%.2f. This %s', 'journey-to-wealth'), $ivps, $cmp, $status_text);
    }
}
