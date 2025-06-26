<?php
/**
 * Adjusted Funds From Operations (AFFO) 2-Stage DCF Model for REITs.
 * Refactored to use data exclusively from Polygon.io.
 *
 * @link       https://example.com/journey-to-wealth/
 * @since      1.1.0
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

    const DEFAULT_AFFO_COST_OF_EQUITY = 0.09;  
    const DEFAULT_AFFO_TERMINAL_GROWTH = 0.025; 
    const MAX_YEARS_FOR_AFFO_GROWTH_CALC = 7;
    const DEFAULT_AFFO_HISTORICAL_GROWTH_CAP = 0.15; 
    const DEFAULT_AFFO_HISTORICAL_GROWTH_FLOOR = 0.01; 
    const MIN_YEARS_FOR_AFFO_GROWTH = 3;

    public function __construct($cost_of_equity = null) {
        $this->cost_of_equity = is_numeric($cost_of_equity) ? (float) $cost_of_equity : self::DEFAULT_AFFO_COST_OF_EQUITY;
        $this->terminal_growth_rate_affo = self::DEFAULT_AFFO_TERMINAL_GROWTH;
    }
    
    private function get_polygon_value($statement_section, $key, $default = 0) {
        if (isset($statement_section[$key]['value']) && is_numeric($statement_section[$key]['value'])) {
            return (float)$statement_section[$key]['value'];
        }
        return $default;
    }

    private function calculate_single_affo($financial_report, &$log_messages) {
        $income_statement = $financial_report['financials']['income_statement'] ?? [];
        
        $net_income = $this->get_polygon_value($income_statement, 'net_income_loss');
        $depreciation_amortization = $this->get_polygon_value($income_statement, 'depreciation_and_amortization');
        
        // Gain on sale of property is not consistently available, so FFO is simplified
        $ffo = $net_income + $depreciation_amortization;
        $log_messages[] = "AFFO Note: FFO calculated as Net Income + D&A. Gain on sale of property is not included.";

        // AFFO is FFO - Recurring CapEx. Using D&A as a proxy for recurring CapEx.
        $affo = $ffo - $depreciation_amortization;
        
        return $affo;
    }

    private function calculate_historical_affo_growth($financials_annual, &$log_messages) {
        $annual_affo_values = [];
        
        $sorted_financials = array_reverse($financials_annual);

        foreach ($sorted_financials as $report) {
            $affo = $this->calculate_single_affo($report, $log_messages);
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

    public function calculate($financials_annual, $details, $prev_close_data) {
        $log_messages = [];
        if (is_wp_error($financials_annual) || empty($financials_annual)) {
            return new WP_Error('affo_missing_financials', __('AFFO Model: Insufficient financial statements.', 'journey-to-wealth'));
        }

        $base_affo = $this->calculate_single_affo($financials_annual[0], $log_messages);
        if ($base_affo <= 0) {
            return new WP_Error('affo_base_affo_error', __('AFFO Model: Base AFFO is zero or negative.', 'journey-to-wealth'));
        }

        $initial_growth_rate = $this->calculate_historical_affo_growth($financials_annual, $log_messages);
        if ($initial_growth_rate === null) { 
            $initial_growth_rate = self::DEFAULT_AFFO_HISTORICAL_GROWTH_FLOOR; 
            $log_messages[] = __('AFFO Model: Using default floor growth rate.', 'journey-to-wealth');
        }

        $projected_affos = [];
        $current_affo = $base_affo;
        $growth_reduction_per_year = ($initial_growth_rate - $this->terminal_growth_rate_affo) / $this->projection_years;

        for ($i = 1; $i <= $this->projection_years; $i++) {
            $current_growth_rate = max($this->terminal_growth_rate_affo, $initial_growth_rate - ($growth_reduction_per_year * ($i-1)));
            $current_affo *= (1 + $current_growth_rate);
            $projected_affos[] = $current_affo;
        }

        $sum_discounted_affos = 0;
        for ($i = 0; $i < $this->projection_years; $i++) {
            $sum_discounted_affos += $projected_affos[$i] / pow((1 + $this->cost_of_equity), $i + 1);
        }

        $affo_year_n = end($projected_affos);
        $terminal_value_affo = ($affo_year_n * (1 + $this->terminal_growth_rate_affo)) / ($this->cost_of_equity - $this->terminal_growth_rate_affo);
        $discounted_terminal_value_affo = $terminal_value_affo / pow((1 + $this->cost_of_equity), $this->projection_years);
        $total_equity_value = $sum_discounted_affos + $discounted_terminal_value_affo;

        $shares_outstanding = $details['share_class_shares_outstanding'] ?? null;
        if (empty($shares_outstanding)) {
            return new WP_Error('affo_missing_shares', __('AFFO Model Error: Shares outstanding data missing.', 'journey-to-wealth'));
        }
        
        $intrinsic_value_per_share = $total_equity_value / $shares_outstanding;
        $current_market_price = $prev_close_data['c'] ?? null;
        $interpretation = $this->get_interpretation($intrinsic_value_per_share, $current_market_price);

        return [
            'intrinsic_value_per_share' => round($intrinsic_value_per_share, 2),
            'interpretation' => $interpretation,
            'log_messages' => $log_messages
        ];
    }

    public function get_interpretation( $intrinsic_value, $current_market_price ) {
        if (!is_numeric($intrinsic_value) || !is_numeric($current_market_price) || $current_market_price == 0) {
            return __('Cannot provide full AFFO interpretation.', 'journey-to-wealth');
        }
        $diff_pct = (($intrinsic_value - $current_market_price) / $current_market_price) * 100;
        $status_text = '';
        if ($intrinsic_value < 0) { $status_text = __('suggests a negative value.', 'journey-to-wealth');
        } elseif ($diff_pct > 20) { $status_text = sprintf(__('suggests potential undervaluation by %.1f%%.', 'journey-to-wealth'), $diff_pct);
        } elseif ($diff_pct < -20) { $status_text = sprintf(__('suggests potential overvaluation by %.1f%%.', 'journey-to-wealth'), abs($diff_pct));
        } else { $status_text = sprintf(__('suggests relative fair valuation (difference of %.1f%%).', 'journey-to-wealth'), $diff_pct); }
        return sprintf(__('AFFO Model Fair Value Estimate: $%.2f. Current Price: $%.2f. This %s', 'journey-to-wealth'), $intrinsic_value, $current_market_price, $status_text);
    }
}
