<?php
/**
 * Dividend Discount Model (DDM) for Journey to Wealth plugin.
 * Primarily focuses on the Gordon Growth Model.
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

class Journey_To_Wealth_DDM_Model {

    private $discount_rate; 
    private $default_terminal_growth_rate; 

    // Constants for defaults and calculation parameters
    const DEFAULT_DDM_DISCOUNT_RATE = 0.09;  
    const DEFAULT_DDM_TERMINAL_GROWTH_RATE = 0.025; 
    const MIN_YEARS_FOR_DIV_GROWTH = 3;    // Need at least 3 years of dividend data for 2 growth periods
    const MAX_YEARS_FOR_DIV_GROWTH_CALC = 7; // Increased from 5 to 7, assuming premium data provides more history
    const MAX_HISTORICAL_DIV_GROWTH_CAP = 0.15; 
    const MIN_HISTORICAL_DIV_GROWTH_FLOOR = 0.00; 

    /**
     * Constructor.
     *
     * @param float $discount_rate Optional. The discount rate (cost of equity) to use.
     */
    public function __construct($discount_rate = null) {
        $this->discount_rate = (is_numeric($discount_rate) && $discount_rate > 0 && $discount_rate < 1)
                               ? (float) $discount_rate
                               : self::DEFAULT_DDM_DISCOUNT_RATE;

        $this->default_terminal_growth_rate = self::DEFAULT_DDM_TERMINAL_GROWTH_RATE;
        
        if ($this->discount_rate <= $this->default_terminal_growth_rate) {
            $this->default_terminal_growth_rate = max(0, $this->discount_rate - 0.01); 
        }
    }

    /**
     * Calculates historical average annual dividend growth rate.
     * Needs historical price data which includes dividend amounts.
     *
     * @param array $historical_price_data From Alpha Vantage TIME_SERIES_DAILY_ADJUSTED.
     * @param array &$log_messages         Reference to an array for logging.
     * @return float|null Calculated growth rate or null if not possible.
     */
    private function calculate_historical_dividend_growth($historical_price_data, &$log_messages) {
        if (!is_array($historical_price_data) || empty($historical_price_data)) {
            $log_messages[] = __('DDM: Historical price/dividend data missing for growth calculation.', 'journey-to-wealth');
            return null;
        }

        $annual_dividends = [];
        foreach ($historical_price_data as $date => $daily_data) {
            if (isset($daily_data['7. dividend amount']) && is_numeric($daily_data['7. dividend amount'])) {
                $dividend_amount = (float)$daily_data['7. dividend amount'];
                if ($dividend_amount > 0) { 
                    $year = substr($date, 0, 4);
                    if (!isset($annual_dividends[$year])) {
                        $annual_dividends[$year] = 0.0;
                    }
                    $annual_dividends[$year] += $dividend_amount;
                }
            }
        }

        if (empty($annual_dividends)) {
            $log_messages[] = __('DDM: No dividend payments found in historical data.', 'journey-to-wealth');
            return null;
        }
        
        ksort($annual_dividends); 
        $positive_annual_dividends = array_filter($annual_dividends, function($div) { return $div > 0.0001; });

        if (count($positive_annual_dividends) < self::MIN_YEARS_FOR_DIV_GROWTH) {
            $log_messages[] = sprintf(__('DDM: Insufficient years of positive dividend data (%d found, %d required) for historical growth calculation.', 'journey-to-wealth'), count($positive_annual_dividends), self::MIN_YEARS_FOR_DIV_GROWTH);
            return null;
        }
        
        // Take up to the last MAX_YEARS_FOR_DIV_GROWTH_CALC + 1 years for growth calculation
        $relevant_dividends_map = array_slice($positive_annual_dividends, -(self::MAX_YEARS_FOR_DIV_GROWTH_CALC + 1), null, true);
        if (count($relevant_dividends_map) < 2) { 
             $log_messages[] = __('DDM: Not enough data points after filtering for recent years to calculate growth.', 'journey-to-wealth');
            return null;
        }

        $dividend_values = array_values($relevant_dividends_map); 
        $growth_rates = [];
        for ($i = 1; $i < count($dividend_values); $i++) {
            if ($dividend_values[$i-1] > 0.0001) { 
                $growth = ($dividend_values[$i] - $dividend_values[$i-1]) / $dividend_values[$i-1];
                $growth_rates[] = $growth;
            }
        }

        if (empty($growth_rates)) {
            $log_messages[] = __('DDM: Could not calculate any valid historical dividend growth rates from recent data.', 'journey-to-wealth');
            return null;
        }

        $average_growth = array_sum($growth_rates) / count($growth_rates);
        
        $calculated_g = $average_growth;
        if ($average_growth > self::MAX_HISTORICAL_DIV_GROWTH_CAP) {
            $calculated_g = self::MAX_HISTORICAL_DIV_GROWTH_CAP;
            $log_messages[] = sprintf(__('DDM: Historical dividend growth of %.2f%% capped at %.2f%%.', 'journey-to-wealth'), $average_growth * 100, $calculated_g * 100);
        } elseif ($average_growth < self::MIN_HISTORICAL_DIV_GROWTH_FLOOR) {
            $calculated_g = self::MIN_HISTORICAL_DIV_GROWTH_FLOOR;
             $log_messages[] = sprintf(__('DDM: Historical dividend growth of %.2f%% floored at %.2f%%.', 'journey-to-wealth'), $average_growth * 100, $calculated_g * 100);
        } else {
            $log_messages[] = sprintf(__('DDM: Calculated historical dividend growth rate: %.2f%% (based on up to %d growth periods).', 'journey-to-wealth'), $calculated_g * 100, count($growth_rates));
        }
        
        return (float) $calculated_g;
    }

    /**
     * Calculates value using the Gordon Growth Model.
     * V0 = D0 * (1 + g) / (k - g)  which is D1 / (k - g)
     *
     * @param array $company_overview      From OVERVIEW (for DividendPerShare as D0).
     * @param array $historical_price_data For calculating historical dividend growth (for 'g').
     * @param array &$log_messages         Reference for logging.
     * @return array|WP_Error Result array with 'intrinsic_value_per_share' or WP_Error.
     */
    public function calculate( $company_overview, $historical_price_data, &$log_messages ) {
        if ( !is_array($company_overview) || !isset($company_overview['DividendPerShare']) || !is_numeric($company_overview['DividendPerShare']) ) {
            $log_messages[] = __('DDM: Current Dividend Per Share (D0) missing or invalid from overview data.', 'journey-to-wealth');
            return new WP_Error('ddm_missing_d0', __('DDM: D0 missing or invalid.', 'journey-to-wealth'));
        }
        $d0 = (float) $company_overview['DividendPerShare'];

        if ($d0 <= 0) {
            $log_messages[] = __('DDM: Company does not currently pay a dividend (D0 is zero or negative). DDM is not applicable.', 'journey-to-wealth');
            return new WP_Error('ddm_no_dividend', __('DDM: No current dividend (D0 <= 0).', 'journey-to-wealth'));
        }

        $g = $this->calculate_historical_dividend_growth($historical_price_data, $log_messages);
        
        if ($g === null || $g >= $this->discount_rate) {
            if ($g !== null && $g >= $this->discount_rate) {
                $log_messages[] = sprintf(__('DDM: Calculated historical dividend growth (%.2f%%) is too high relative to discount rate (%.2f%%). Using default terminal growth rate.', 'journey-to-wealth'), $g * 100, $this->discount_rate * 100);
            } else {
                $log_messages[] = sprintf(__('DDM: Using default terminal growth rate (%.2f%%) for dividend growth as historical growth is unavailable/unsuitable.', 'journey-to-wealth'), $this->default_terminal_growth_rate * 100);
            }
            $g = $this->default_terminal_growth_rate;
        }
        
        if ($this->discount_rate <= $g) {
            $log_messages[] = sprintf(__('DDM Error: Discount rate (%.2f%%) must be greater than the final dividend growth rate (%.2f%%). Cannot calculate DDM reliably.', 'journey-to-wealth'), $this->discount_rate * 100, $g * 100);
            return new WP_Error('ddm_k_not_greater_than_g', __('DDM: Discount rate not sufficiently greater than growth rate (k <= g).', 'journey-to-wealth'));
        }

        $d1 = $d0 * (1 + $g); 
        $intrinsic_value = $d1 / ($this->discount_rate - $g);

        $log_messages[] = sprintf(__('DDM Calculation: D0=$%.2f, Final Est. g=%.2f%%, k=%.2f%%.', 'journey-to-wealth'), $d0, $g*100, $this->discount_rate*100);

        return array(
            'intrinsic_value_per_share' => round($intrinsic_value, 2),
            'model_used' => __('Dividend Discount Model (Gordon Growth)', 'journey-to-wealth'),
            'd0_used' => $d0,
            'g_used' => round($g, 4), 
            'k_e_used' => $this->discount_rate,
            'd1_projected' => round($d1, 4)
        );
    }

    public function get_interpretation( $intrinsic_value, $current_market_price ) {
        if (!is_numeric($intrinsic_value) || !is_numeric($current_market_price) || $current_market_price == 0) {
            if (!is_numeric($intrinsic_value)) {
                return __('DDM Fair Value Estimate could not be determined numerically.', 'journey-to-wealth');
            }
            return __('Cannot provide full DDM interpretation due to missing current market price.', 'journey-to-wealth');
        }
        $ivps = (float) $intrinsic_value;
        $cmp = (float) $current_market_price;
        $diff_pct = (($ivps - $cmp) / $cmp) * 100;
        $status_text = '';

        if ($ivps < 0) {
            $status_text = __('DDM resulted in a negative value, suggesting issues with inputs or model applicability.', 'journey-to-wealth');
        } elseif ($diff_pct > 20) { 
            $status_text = sprintf(__('suggests potential undervaluation by %.1f%%.', 'journey-to-wealth'), $diff_pct);
        } elseif ($diff_pct < -20) { 
            $status_text = sprintf(__('suggests potential overvaluation by %.1f%%.', 'journey-to-wealth'), abs($diff_pct));
        } else {
            $status_text = sprintf(__('suggests relative fair valuation (difference of %.1f%%).', 'journey-to-wealth'), $diff_pct);
        }
        return sprintf(__('DDM Fair Value Estimate: $%.2f. Current Price: $%.2f. This %s', 'journey-to-wealth'), $ivps, $cmp, $status_text);
    }
}
