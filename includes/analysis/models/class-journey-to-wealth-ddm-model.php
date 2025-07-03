<?php
/**
 * Dividend Discount Model (DDM) for Journey to Wealth plugin.
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

class Journey_To_Wealth_DDM_Model {

    private $discount_rate; 
    private $terminal_growth_rate;
    private $equity_risk_premium;
    private $levered_beta;

    const MIN_YEARS_FOR_DIV_GROWTH = 3;    
    const MAX_YEARS_FOR_DIV_GROWTH_CALC = 7;
    const MAX_HISTORICAL_DIV_GROWTH_CAP = 0.15; 
    const MIN_HISTORICAL_DIV_GROWTH_FLOOR = 0.00; 

    public function __construct($equity_risk_premium, $levered_beta) {
        $this->equity_risk_premium = $equity_risk_premium;
        $this->levered_beta = $levered_beta;
    }

    private function calculate_historical_dividend_growth($daily_data) {
        if (is_wp_error($daily_data) || empty($daily_data['Time Series (Daily)'])) {
            return null;
        }

        $annual_dividends = [];
        foreach ($daily_data['Time Series (Daily)'] as $date => $day_data) {
            if (isset($day_data['7. dividend amount']) && is_numeric($day_data['7. dividend amount']) && $day_data['7. dividend amount'] > 0) {
                $year = substr($date, 0, 4);
                if (!isset($annual_dividends[$year])) {
                    $annual_dividends[$year] = 0.0;
                }
                $annual_dividends[$year] += (float)$day_data['7. dividend amount'];
            }
        }

        ksort($annual_dividends);
        
        if (count($annual_dividends) < self::MIN_YEARS_FOR_DIV_GROWTH) {
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
            return null;
        }

        $average_growth = array_sum($growth_rates) / count($growth_rates);
        return max(self::MIN_HISTORICAL_DIV_GROWTH_FLOOR, min($average_growth, self::MAX_HISTORICAL_DIV_GROWTH_CAP));
    }

    public function calculate($overview, $treasury_yield, $latest_price, $daily_data, $beta_details) {
        $d0 = isset($overview['DividendPerShare']) && is_numeric($overview['DividendPerShare']) ? (float)$overview['DividendPerShare'] : 0;
        
        if ($d0 <= 0) {
            return new WP_Error('ddm_no_dividend', __('DDM: Company does not currently pay a dividend.', 'journey-to-wealth'));
        }

        $risk_free_rate = (new Journey_To_Wealth_DCF_Model())->calculate_average_risk_free_rate($treasury_yield);
        $this->discount_rate = $risk_free_rate + ($this->levered_beta * $this->equity_risk_premium);
        $this->terminal_growth_rate = $risk_free_rate;

        $g = $this->calculate_historical_dividend_growth($daily_data);
        
        if ($g === null) {
            $g = $this->terminal_growth_rate;
        }
        
        if ($this->discount_rate <= $g) {
            return new WP_Error('ddm_k_not_greater_than_g', __('DDM: Discount rate must be greater than growth rate.', 'journey-to-wealth'));
        }

        $d1 = $d0 * (1 + $g); 
        $intrinsic_value = $d1 / ($this->discount_rate - $g);

        return [
            'intrinsic_value_per_share' => round($intrinsic_value, 2),
            'calculation_breakdown' => [
                'model_name' => 'Dividend Discount Model',
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
                    'cost_of_equity' => $this->discount_rate,
                ],
                'd0' => $d0,
                'growth_rate' => $g,
                'd1' => $d1,
                'intrinsic_value' => $intrinsic_value,
                'current_price' => $latest_price,
            ]
        ];
    }
}
