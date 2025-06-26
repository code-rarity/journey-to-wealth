<?php
/**
 * Discounted Cash Flow (DCF) Valuation Model for Journey to Wealth plugin.
 * Implements a 2-stage FCFE model using data exclusively from Polygon.io.
 * Refactored to align with a more standard FCF calculation methodology.
 *
 * @link       https://example.com/journey-to-wealth/
 * @since      1.2.0
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
    const DEFAULT_COST_OF_EQUITY = 0.095; // 9.5% as a fixed default
    const DEFAULT_TERMINAL_GROWTH_RATE = 0.025; // 2.5%
    
    const MAX_YEARS_FOR_HISTORICAL_CALCS = 7;
    const MIN_YEARS_FOR_GROWTH_CALC = 3;
    
    const DEFAULT_HISTORICAL_GROWTH_CAP = 0.15;
    const DEFAULT_HISTORICAL_GROWTH_FLOOR = 0.02;

    public function __construct() {
        $this->cost_of_equity = self::DEFAULT_COST_OF_EQUITY;
        $this->terminal_growth_rate = self::DEFAULT_TERMINAL_GROWTH_RATE;
    }

    private function get_polygon_value($statement_section, $key, $default = 0, &$log_messages = [], $context = '') {
        if (isset($statement_section[$key]['value']) && is_numeric($statement_section[$key]['value'])) {
            return (float)$statement_section[$key]['value'];
        }
        $log_messages[] = sprintf(__('DCF Warning: Numeric value for "%s" in %s missing or invalid. Using default: %s.', 'journey-to-wealth'), $key, $context, $default);
        return $default;
    }

    private function calculate_single_fcfe($financial_report, &$log_messages) {
        $cash_flow_statement = $financial_report['financials']['cash_flow_statement'] ?? [];
        $fiscal_year = $financial_report['fiscal_year'] ?? 'N/A';

        // Using a standard Free Cash Flow (FCF) calculation: Cash from Operations + Cash from Investing.
        // Cash from Investing is typically a negative number representing investments (CapEx), so adding it effectively subtracts the investments.
        $cash_from_ops = $this->get_polygon_value($cash_flow_statement, 'net_cash_flow_from_operating_activities', null, $log_messages, "Cash from Operations for $fiscal_year");
        $cash_from_inv = $this->get_polygon_value($cash_flow_statement, 'net_cash_flow_from_investing_activities', null, $log_messages, "Cash from Investing for $fiscal_year");

        if ($cash_from_ops === null || $cash_from_inv === null) {
            $log_messages[] = "DCF Error: Could not calculate FCF for $fiscal_year due to missing cash flow data.";
            return null;
        }

        $fcfe = $cash_from_ops + $cash_from_inv;
        $log_messages[] = "DCF Note: FCF for $fiscal_year calculated as CFO (%.0f) + CFI (%.0f) = %.0f.";
        
        return $fcfe;
    }

    private function calculate_historical_fcfe_growth($financials_annual, &$log_messages) {
        if (count($financials_annual) < self::MIN_YEARS_FOR_GROWTH_CALC) {
            $log_messages[] = __('DCF: Insufficient historical statements for FCFE growth. Using default floor growth.', 'journey-to-wealth');
            return self::DEFAULT_HISTORICAL_GROWTH_FLOOR;
        }

        $fcfes = [];
        // Data is newest first, so we reverse to calculate growth chronologically
        $sorted_financials = array_reverse($financials_annual);
        
        foreach ($sorted_financials as $report) {
            $fcfe = $this->calculate_single_fcfe($report, $log_messages);
            if ($fcfe !== null && $fcfe > 0) {
                $fcfes[] = $fcfe;
            }
        }
        
        if (count($fcfes) < 2) {
            $log_messages[] = __('DCF: Not enough positive FCFE data for growth. Using default floor growth.', 'journey-to-wealth');
            return self::DEFAULT_HISTORICAL_GROWTH_FLOOR;
        }

        $growth_rates = [];
        for ($i = 1; $i < count($fcfes); $i++) {
            if ($fcfes[$i-1] != 0) {
                $growth = ($fcfes[$i] - $fcfes[$i-1]) / $fcfes[$i-1];
                $growth_rates[] = $growth;
            }
        }

        if (empty($growth_rates)) {
            $log_messages[] = __('DCF: No valid growth rates calculated. Using default floor value.', 'journey-to-wealth');
            return self::DEFAULT_HISTORICAL_GROWTH_FLOOR;
        }

        $average_growth = array_sum($growth_rates) / count($growth_rates);
        $calculated_g = max(self::DEFAULT_HISTORICAL_GROWTH_FLOOR, min($average_growth, self::DEFAULT_HISTORICAL_GROWTH_CAP));
        $log_messages[] = sprintf(__('DCF: Historical FCFE growth (%.1f%%) capped/floored to %.1f%%.', 'journey-to-wealth'), $average_growth * 100, $calculated_g * 100);
        return (float) $calculated_g;
    }

    public function calculate($financials_annual, $details, $prev_close_data) {
        $log_messages = [];
        $log_messages[] = "DCF Note: Using Polygon.io data. Beta and Treasury Yield are unavailable, so a default Cost of Equity is used.";
        $log_messages[] = sprintf(__('DCF: Using default Cost of Equity: %.1f%%.', 'journey-to-wealth'), $this->cost_of_equity * 100);
        $log_messages[] = sprintf(__('DCF: Using default Terminal Growth Rate: %.1f%%.', 'journey-to-wealth'), $this->terminal_growth_rate * 100);

        if (is_wp_error($financials_annual) || empty($financials_annual)) {
            return new WP_Error('dcf_missing_financials', __('DCF Error: Annual financial statements are required.', 'journey-to-wealth'));
        }

        $base_fcfe = $this->calculate_single_fcfe($financials_annual[0], $log_messages);
        if ($base_fcfe === null) {
            return new WP_Error('dcf_base_fcfe_error', __('DCF Error: Could not calculate base FCFE from latest annual report.', 'journey-to-wealth'));
        }
         if ($base_fcfe <= 0) {
            $log_messages[] = sprintf(__('DCF Warning: Base FCFE is $%.2f. Projections are highly speculative.', 'journey-to-wealth'), $base_fcfe);
        }

        $initial_growth_rate = $this->calculate_historical_fcfe_growth($financials_annual, $log_messages);
        
        $projected_fcfs = [];
        $current_fcfe = $base_fcfe;
        $growth_reduction_per_year = ($initial_growth_rate - $this->terminal_growth_rate) / $this->projection_years;

        for ($i = 1; $i <= $this->projection_years; $i++) {
            $current_growth_rate = max($this->terminal_growth_rate, $initial_growth_rate - ($growth_reduction_per_year * ($i - 1)));
            $current_fcfe *= (1 + $current_growth_rate);
            $projected_fcfs[] = $current_fcfe;
        }

        $sum_discounted_fcfs = 0;
        for ($i = 0; $i < $this->projection_years; $i++) {
            $sum_discounted_fcfs += $projected_fcfs[$i] / pow((1 + $this->cost_of_equity), $i + 1);
        }

        $fcfe_year_n = end($projected_fcfs);
        $terminal_value = ($fcfe_year_n * (1 + $this->terminal_growth_rate)) / ($this->cost_of_equity - $this->terminal_growth_rate);
        $discounted_terminal_value = $terminal_value / pow((1 + $this->cost_of_equity), $this->projection_years);

        $total_equity_value = $sum_discounted_fcfs + $discounted_terminal_value;
        
        // Correctly check for multiple keys for shares outstanding
        $shares_outstanding = $details['share_class_shares_outstanding'] ?? $details['weighted_shares_outstanding'] ?? null;
        if (empty($shares_outstanding)) {
             return new WP_Error('dcf_missing_shares', __('DCF Error: Shares outstanding data not found.', 'journey-to-wealth'));
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
    
    public function get_interpretation($intrinsic_value, $current_market_price) {
        if (!is_numeric($intrinsic_value) || !is_numeric($current_market_price) || $current_market_price == 0) {
            return __('Cannot provide full DCF interpretation due to missing price data.', 'journey-to-wealth');
        }
        $diff_pct = (($intrinsic_value - $current_market_price) / $current_market_price) * 100;
        $status_text = '';
        if ($intrinsic_value < 0) {
            $status_text = __('suggests a negative equity value.', 'journey-to-wealth');
        } elseif ($diff_pct > 20) {
            $status_text = sprintf(__('suggests potential undervaluation by %.1f%%.', 'journey-to-wealth'), $diff_pct);
        } elseif ($diff_pct < -20) {
            $status_text = sprintf(__('suggests potential overvaluation by %.1f%%.', 'journey-to-wealth'), abs($diff_pct));
        } else {
            $status_text = sprintf(__('suggests relative fair valuation (Difference: %.1f%%).', 'journey-to-wealth'), $diff_pct);
        }
        return sprintf(__('DCF Fair Value Estimate: $%.2f. Current Price: $%.2f. This %s', 'journey-to-wealth'), $intrinsic_value, $current_market_price, $status_text);
    }
}
