<?php
/**
 * Dividend Discount Model (DDM) for Journey to Wealth plugin.
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

class Journey_To_Wealth_DDM_Model {

    private $discount_rate; 
    private $default_terminal_growth_rate; 

    const DEFAULT_DDM_DISCOUNT_RATE = 0.09;  
    const DEFAULT_DDM_TERMINAL_GROWTH_RATE = 0.025; 
    const MIN_YEARS_FOR_DIV_GROWTH = 3;    
    const MAX_YEARS_FOR_DIV_GROWTH_CALC = 7;
    const MAX_HISTORICAL_DIV_GROWTH_CAP = 0.15; 
    const MIN_HISTORICAL_DIV_GROWTH_FLOOR = 0.00; 

    public function __construct($discount_rate = null) {
        $this->discount_rate = is_numeric($discount_rate) ? (float) $discount_rate : self::DEFAULT_DDM_DISCOUNT_RATE;
        $this->default_terminal_growth_rate = self::DEFAULT_DDM_TERMINAL_GROWTH_RATE;
        
        if ($this->discount_rate <= $this->default_terminal_growth_rate) {
            $this->default_terminal_growth_rate = max(0, $this->discount_rate - 0.01); 
        }
    }

    private function calculate_historical_dividend_growth($dividends_data, &$log_messages) {
        if (is_wp_error($dividends_data) || empty($dividends_data) || !is_array($dividends_data['results'])) {
            $log_messages[] = __('DDM: Historical dividend data missing for growth calculation.', 'journey-to-wealth');
            return null;
        }

        $annual_dividends = [];
        foreach ($dividends_data['results'] as $dividend) {
            if (isset($dividend['ex_dividend_date']) && isset($dividend['cash_amount']) && is_numeric($dividend['cash_amount'])) {
                $year = substr($dividend['ex_dividend_date'], 0, 4);
                if (!isset($annual_dividends[$year])) {
                    $annual_dividends[$year] = 0.0;
                }
                $annual_dividends[$year] += (float)$dividend['cash_amount'];
            }
        }

        ksort($annual_dividends); 
        
        if (count($annual_dividends) < self::MIN_YEARS_FOR_DIV_GROWTH) {
            $log_messages[] = sprintf(__('DDM: Insufficient years of dividend data (%d found, %d required) for historical growth.', 'journey-to-wealth'), count($annual_dividends), self::MIN_YEARS_FOR_DIV_GROWTH);
            return null;
        }
        
        $relevant_dividends = array_slice($annual_dividends, - (self::MAX_YEARS_FOR_DIV_GROWTH_CALC + 1), null, true);
        $dividend_values = array_values($relevant_dividends); 
        
        $growth_rates = [];
        for ($i = 1; $i < count($dividend_values); $i++) {
            if ($dividend_values[$i-1] > 0.0001) { 
                $growth = ($dividend_values[$i] - $dividend_values[$i-1]) / $dividend_values[$i-1];
                $growth_rates[] = $growth;
            }
        }

        if (empty($growth_rates)) {
            $log_messages[] = __('DDM: Could not calculate valid historical dividend growth rates.', 'journey-to-wealth');
            return null;
        }

        $average_growth = array_sum($growth_rates) / count($growth_rates);
        $calculated_g = max(self::MIN_HISTORICAL_DIV_GROWTH_FLOOR, min($average_growth, self::MAX_HISTORICAL_DIV_GROWTH_CAP));
        
        $log_messages[] = sprintf(__('DDM: Calculated historical dividend growth rate: %.2f%%.', 'journey-to-wealth'), $calculated_g * 100);
        return (float) $calculated_g;
    }

    public function calculate($dividends, $details, $prev_close_data) {
        $log_messages = [];
        
        if (is_wp_error($dividends) || empty($dividends['results'])) {
             return new WP_Error('ddm_no_dividend_data', __('DDM: No historical dividend data available.', 'journey-to-wealth'));
        }

        $latest_dividend = $dividends['results'][0];
        $dividend_frequency = $latest_dividend['frequency'] ?? 4; // Assume quarterly if not specified
        $last_dividend_payment = $latest_dividend['cash_amount'] ?? 0;
        $d0 = $last_dividend_payment * $dividend_frequency;
        
        if ($d0 <= 0) {
            return new WP_Error('ddm_no_dividend', __('DDM: Company does not currently pay a dividend.', 'journey-to-wealth'));
        }
        $log_messages[] = "DDM Note: Calculated D0 (annualized dividend) is $" . round($d0, 2);

        $g = $this->calculate_historical_dividend_growth($dividends, $log_messages);
        
        if ($g === null || $g >= $this->discount_rate) {
            $log_messages[] = sprintf(__('DDM: Using default terminal growth rate (%.2f%%).', 'journey-to-wealth'), $this->default_terminal_growth_rate * 100);
            $g = $this->default_terminal_growth_rate;
        }
        
        if ($this->discount_rate <= $g) {
            return new WP_Error('ddm_k_not_greater_than_g', __('DDM: Discount rate must be greater than growth rate.', 'journey-to-wealth'));
        }

        $d1 = $d0 * (1 + $g); 
        $intrinsic_value = $d1 / ($this->discount_rate - $g);

        $current_market_price = $prev_close_data['c'] ?? null;
        $interpretation = $this->get_interpretation($intrinsic_value, $current_market_price);

        return [
            'intrinsic_value_per_share' => round($intrinsic_value, 2),
            'interpretation' => $interpretation,
            'log_messages' => $log_messages
        ];
    }

    public function get_interpretation( $intrinsic_value, $current_market_price ) {
        if (!is_numeric($intrinsic_value) || !is_numeric($current_market_price) || $current_market_price == 0) {
            return __('Cannot provide full DDM interpretation.', 'journey-to-wealth');
        }
        $diff_pct = (($intrinsic_value - $current_market_price) / $current_market_price) * 100;
        $status_text = '';

        if ($intrinsic_value < 0) {
            $status_text = __('suggests a negative value.', 'journey-to-wealth');
        } elseif ($diff_pct > 20) { 
            $status_text = sprintf(__('suggests potential undervaluation by %.1f%%.', 'journey-to-wealth'), $diff_pct);
        } elseif ($diff_pct < -20) { 
            $status_text = sprintf(__('suggests potential overvaluation by %.1f%%.', 'journey-to-wealth'), abs($diff_pct));
        } else {
            $status_text = sprintf(__('suggests relative fair valuation (difference of %.1f%%).', 'journey-to-wealth'), $diff_pct);
        }
        return sprintf(__('DDM Fair Value Estimate: $%.2f. Current Price: $%.2f. This %s', 'journey-to-wealth'), $intrinsic_value, $current_market_price, $status_text);
    }
}
