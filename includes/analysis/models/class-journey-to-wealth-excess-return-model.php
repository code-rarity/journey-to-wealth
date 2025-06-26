<?php
/**
 * Excess Returns Valuation Model for Journey to Wealth plugin.
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

class Journey_To_Wealth_Excess_Return_Model {

    private $cost_of_equity;
    private $projection_years = 5;
    private $terminal_growth_rate_excess_returns;

    const DEFAULT_ERM_COST_OF_EQUITY = 0.10;
    const DEFAULT_ERM_TERMINAL_GROWTH = 0.01;
    const DEFAULT_EQUITY_GROWTH_RATE_FALLBACK = 0.03;
    const MIN_YEARS_FOR_BV_GROWTH = 3;
    const MAX_YEARS_FOR_BV_GROWTH_CALC = 7;
    const MAX_HISTORICAL_BV_GROWTH_CAP = 0.15;
    const MIN_HISTORICAL_BV_GROWTH_FLOOR = 0.00;

    public function __construct($cost_of_equity = null, $terminal_growth_rate = null) {
        $this->cost_of_equity = is_numeric($cost_of_equity) ? (float) $cost_of_equity : self::DEFAULT_ERM_COST_OF_EQUITY;
        $this->terminal_growth_rate_excess_returns = is_numeric($terminal_growth_rate) ? (float) $terminal_growth_rate : self::DEFAULT_ERM_TERMINAL_GROWTH;
    }

    private function get_polygon_value($statement_section, $key) {
        if (isset($statement_section[$key]['value']) && is_numeric($statement_section[$key]['value'])) {
            return (float)$statement_section[$key]['value'];
        }
        return null;
    }

    private function calculate_historical_book_value_growth($financials_annual, &$log_messages) {
        if (count($financials_annual) < self::MIN_YEARS_FOR_BV_GROWTH) return null;
        
        $book_values = [];
        $sorted_financials = array_reverse($financials_annual);

        foreach ($sorted_financials as $report) {
            $bv = $this->get_polygon_value($report['financials']['balance_sheet'] ?? [], 'equity');
            if ($bv !== null && $bv > 0) {
                $book_values[] = $bv;
            }
        }
        
        if (count($book_values) < 2) return null;

        $growth_rates = [];
        for ($i = 1; $i < count($book_values); $i++) {
            if ($book_values[$i-1] > 0) { 
                $growth = ($book_values[$i] - $book_values[$i-1]) / $book_values[$i-1];
                $growth_rates[] = $growth;
            }
        }

        if (empty($growth_rates)) return null;

        $average_growth = array_sum($growth_rates) / count($growth_rates);
        return max(self::MIN_HISTORICAL_BV_GROWTH_FLOOR, min($average_growth, self::MAX_HISTORICAL_BV_GROWTH_CAP));
    }

    public function calculate($financials_annual, $details, $prev_close_data) {
        $log_messages = [];

        if (is_wp_error($financials_annual) || empty($financials_annual)) {
            return new WP_Error('erm_missing_financials', __('ERM Error: Financial statements missing.', 'journey-to-wealth'));
        }
        
        $latest_report = $financials_annual[0];
        $latest_bs = $latest_report['financials']['balance_sheet'] ?? [];
        $latest_is = $latest_report['financials']['income_statement'] ?? [];

        $current_book_value_equity = $this->get_polygon_value($latest_bs, 'equity');
        $net_income = $this->get_polygon_value($latest_is, 'net_income_loss');

        if ($current_book_value_equity === null || $net_income === null || $current_book_value_equity <= 0) {
             return new WP_Error('erm_missing_b0_or_ni', __('ERM Error: Current Book Value or Net Income is missing or invalid.', 'journey-to-wealth'));
        }
        $roe = $net_income / $current_book_value_equity;
        $log_messages[] = sprintf(__('ERM: Calculated ROE is %.2f%%.', 'journey-to-wealth'), $roe * 100);

        $g_equity = $this->calculate_historical_book_value_growth($financials_annual, $log_messages);
        if ($g_equity === null) {
            $g_equity = self::DEFAULT_EQUITY_GROWTH_RATE_FALLBACK;
            $log_messages[] = sprintf(__('ERM: Using fallback equity growth rate: %.2f%%', 'journey-to-wealth'), $g_equity * 100);
        }

        $sum_pv_excess_returns = 0;
        $projected_book_value_current_year = $current_book_value_equity; 
        for ($i = 1; $i <= $this->projection_years; $i++) {
            $excess_return_amount = ($roe - $this->cost_of_equity) * $projected_book_value_current_year;
            $sum_pv_excess_returns += $excess_return_amount / pow((1 + $this->cost_of_equity), $i);
            $projected_book_value_current_year *= (1 + $g_equity); 
        }

        $book_value_at_terminal_year_start = $projected_book_value_current_year;
        $terminal_year_excess_return = ($roe - $this->cost_of_equity) * $book_value_at_terminal_year_start;
        $terminal_value_er = ($terminal_year_excess_return * (1 + $this->terminal_growth_rate_excess_returns)) / ($this->cost_of_equity - $this->terminal_growth_rate_excess_returns);
        $pv_terminal_value_excess_returns = $terminal_value_er / pow((1 + $this->cost_of_equity), $this->projection_years);

        $total_equity_value = $current_book_value_equity + $sum_pv_excess_returns + $pv_terminal_value_excess_returns;
        
        $shares_outstanding = $details['share_class_shares_outstanding'] ?? null;
        if (empty($shares_outstanding)) {
            return new WP_Error('erm_missing_shares', __('ERM Error: Shares outstanding missing.', 'journey-to-wealth'));
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
            return __('Cannot provide full ERM interpretation.', 'journey-to-wealth');
        }
        $diff_pct = (($intrinsic_value - $current_market_price) / $current_market_price) * 100;
        $status_text = '';
        if ($intrinsic_value < 0) { $status_text = __('suggests a negative intrinsic value.', 'journey-to-wealth');
        } elseif ($diff_pct > 20) { $status_text = sprintf(__('suggests potential undervaluation by %.1f%%.', 'journey-to-wealth'), $diff_pct);
        } elseif ($diff_pct < -20) { $status_text = sprintf(__('suggests potential overvaluation by %.1f%%.', 'journey-to-wealth'), abs($diff_pct));
        } else { $status_text = sprintf(__('suggests relative fair valuation (difference of %.1f%%).', 'journey-to-wealth'), $diff_pct); }
        return sprintf(__('Excess Returns Model Fair Value Estimate: $%.2f. Current Price: $%.2f. This %s', 'journey-to-wealth'), $intrinsic_value, $current_market_price, $status_text);
    }
}
