<?php
/**
 * Excess Returns Valuation Model for Journey to Wealth plugin.
 * Suited for financial institutions.
 *
 * Value = Book Value + PV of (ROE - k_e) * Book Value
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

class Journey_To_Wealth_Excess_Return_Model {

    private $cost_of_equity; // k_e
    private $projection_years = 5; // Explicit forecast period for excess returns
    private $terminal_growth_rate_excess_returns; // Growth of excess returns in terminal phase

    // Defaults
    const DEFAULT_ERM_COST_OF_EQUITY = 0.10; // 10%
    const DEFAULT_ERM_TERMINAL_GROWTH = 0.01; // 1% for excess returns, or assume they fade
    const DEFAULT_EQUITY_GROWTH_RATE_FALLBACK = 0.03; // Fallback equity growth rate if historical not calculable
    const MIN_YEARS_FOR_BV_GROWTH = 3; // Need at least 3 years of BV for 2 growth periods
    const MAX_YEARS_FOR_BV_GROWTH_CALC = 7; // Increased from 5 to 7, assuming premium data provides more history
    const MAX_HISTORICAL_BV_GROWTH_CAP = 0.15; // Cap historical BV growth at 15%
    const MIN_HISTORICAL_BV_GROWTH_FLOOR = 0.00; // Floor historical BV growth at 0%

    public function __construct($cost_of_equity = null, $terminal_growth_rate = null) {
        $this->cost_of_equity = (is_numeric($cost_of_equity) && $cost_of_equity > 0 && $cost_of_equity < 1)
                                ? (float) $cost_of_equity
                                : self::DEFAULT_ERM_COST_OF_EQUITY;
        $this->terminal_growth_rate_excess_returns = (is_numeric($terminal_growth_rate) && $terminal_growth_rate < $this->cost_of_equity)
                                ? (float) $terminal_growth_rate
                                : self::DEFAULT_ERM_TERMINAL_GROWTH;
        
        if ($this->cost_of_equity <= $this->terminal_growth_rate_excess_returns) {
            $this->terminal_growth_rate_excess_returns = max(0, $this->cost_of_equity - 0.01); // Ensure g_terminal_er < k_e
        }
    }

    /**
     * Calculates historical average annual growth rate of Book Value (Total Shareholder Equity).
     *
     * @param array $balance_sheet_data Array of annual balance sheet reports.
     * @param array &$log_messages Reference to an array for logging.
     * @return float|null Calculated growth rate or null if not possible.
     */
    private function calculate_historical_book_value_growth($balance_sheet_data, &$log_messages) {
        if (!is_array($balance_sheet_data) || count($balance_sheet_data) < self::MIN_YEARS_FOR_BV_GROWTH) {
            $log_messages[] = __('ERM: Insufficient historical balance sheet data to calculate book value growth. Using fallback.', 'journey-to-wealth');
            return null;
        }

        // Sort by fiscal date ascending to calculate growth correctly
        usort($balance_sheet_data, function($a, $b) {
            return isset($a['fiscalDateEnding'], $b['fiscalDateEnding']) ? strtotime($a['fiscalDateEnding']) - strtotime($b['fiscalDateEnding']) : 0;
        });

        $book_values = [];
        foreach ($balance_sheet_data as $report) {
            if (isset($report['totalShareholderEquity']) && is_numeric($report['totalShareholderEquity'])) {
                $bv = (float)$report['totalShareholderEquity'];
                if ($bv > 0) { // Only consider positive book values for growth calculation
                    $book_values[] = $bv;
                }
            }
        }
        
        // Use up to MAX_YEARS_FOR_BV_GROWTH_CALC for calculation (e.g., last 7 BV values for 6 growth periods)
        if (count($book_values) > (self::MAX_YEARS_FOR_BV_GROWTH_CALC + 1) ) {
            $book_values = array_slice($book_values, -(self::MAX_YEARS_FOR_BV_GROWTH_CALC + 1));
        }


        if (count($book_values) < 2) { // Need at least two points to calculate one growth rate
            $log_messages[] = __('ERM: Not enough valid positive book value data points to calculate historical growth. Using fallback.', 'journey-to-wealth');
            return null;
        }

        $growth_rates = [];
        for ($i = 1; $i < count($book_values); $i++) {
            // Book value at $i-1 must be positive for meaningful growth rate
            if ($book_values[$i-1] > 0.0001) { 
                $growth = ($book_values[$i] - $book_values[$i-1]) / $book_values[$i-1];
                $growth_rates[] = $growth;
            }
        }

        if (empty($growth_rates)) {
            $log_messages[] = __('ERM: Could not calculate any valid historical book value growth rates. Using fallback.', 'journey-to-wealth');
            return null;
        }

        $average_growth = array_sum($growth_rates) / count($growth_rates);
        
        $calculated_g = $average_growth;
        if ($average_growth > self::MAX_HISTORICAL_BV_GROWTH_CAP) {
            $calculated_g = self::MAX_HISTORICAL_BV_GROWTH_CAP;
            $log_messages[] = sprintf(__('ERM: Historical Book Value growth of %.2f%% capped at %.2f%%.', 'journey-to-wealth'), $average_growth * 100, $calculated_g * 100);
        } elseif ($average_growth < self::MIN_HISTORICAL_BV_GROWTH_FLOOR) {
            $calculated_g = self::MIN_HISTORICAL_BV_GROWTH_FLOOR;
             $log_messages[] = sprintf(__('ERM: Historical Book Value growth of %.2f%% floored at %.2f%%.', 'journey-to-wealth'), $average_growth * 100, $calculated_g * 100);
        } else {
            $log_messages[] = sprintf(__('ERM: Calculated historical Book Value growth rate: %.2f%% (based on up to %d growth periods).', 'journey-to-wealth'), $calculated_g * 100, count($growth_rates));
        }
        
        return (float) $calculated_g;
    }


    /**
     * Calculates intrinsic value using the Excess Returns model.
     *
     * @param array $company_overview      (For ROE, SharesOutstanding, potentially current BookValuePerShare)
     * @param array $balance_sheet_data  (Array of annual reports for Book Value of Equity)
     * @param array $income_statement_data (Array of annual reports for Net Income, to calculate ROE if needed)
     * @param array &$log_messages         Reference for logging.
     * @return array|WP_Error Result array or WP_Error.
     */
    public function calculate($company_overview, $balance_sheet_data, $income_statement_data, &$log_messages) {
        $log_messages[] = __('Attempting Excess Returns Model calculation.', 'journey-to-wealth');

        // 1. Get Current Book Value of Equity (B0)
        if (!is_array($balance_sheet_data) || empty($balance_sheet_data)) {
            return new WP_Error('erm_missing_bs', __('ERM Error: Balance sheet data missing.', 'journey-to-wealth'));
        }
        // Ensure latest is first for B0
        usort($balance_sheet_data, function($a, $b) {
            return isset($a['fiscalDateEnding'], $b['fiscalDateEnding']) ? strtotime($b['fiscalDateEnding']) - strtotime($a['fiscalDateEnding']) : 0;
        });
        $latest_bs = $balance_sheet_data[0];
        if (!isset($latest_bs['totalShareholderEquity']) || !is_numeric($latest_bs['totalShareholderEquity'])) {
            return new WP_Error('erm_missing_b0', __('ERM Error: Total Shareholder Equity (Book Value) missing from latest balance sheet.', 'journey-to-wealth'));
        }
        $current_book_value_equity = (float) $latest_bs['totalShareholderEquity'];
        if ($current_book_value_equity <= 0) {
             $log_messages[] = __('ERM Warning: Current Book Value of Equity is zero or negative. Model results will be highly sensitive or invalid.', 'journey-to-wealth');
        }

        // 2. Get Return on Equity (ROE)
        $roe = null;
        if (is_array($company_overview) && isset($company_overview['ReturnOnEquityTTM']) && is_numeric($company_overview['ReturnOnEquityTTM'])) {
            $roe = (float) $company_overview['ReturnOnEquityTTM'];
            $log_messages[] = sprintf(__('ERM: Using ROE (TTM) from overview: %.2f%%', 'journey-to-wealth'), $roe * 100);
        } elseif (is_array($income_statement_data) && !empty($income_statement_data) && $current_book_value_equity != 0) { 
            usort($income_statement_data, function($a, $b) {
                return isset($a['fiscalDateEnding'], $b['fiscalDateEnding']) ? strtotime($b['fiscalDateEnding']) - strtotime($a['fiscalDateEnding']) : 0;
            });
            $latest_is = $income_statement_data[0];
            if (isset($latest_is['netIncome']) && is_numeric($latest_is['netIncome'])) {
                $net_income = (float) $latest_is['netIncome'];
                $roe = $net_income / $current_book_value_equity; 
                $log_messages[] = sprintf(__('ERM: Calculated ROE from latest Net Income (%.2f) and current Book Value (%.2f): %.2f%%', 'journey-to-wealth'), $net_income, $current_book_value_equity, $roe * 100);
            }
        }
        if ($roe === null) {
            return new WP_Error('erm_missing_roe', __('ERM Error: Return on Equity (ROE) could not be determined.', 'journey-to-wealth'));
        }

        // 3. Determine Growth Rate for Equity (g_equity)
        $g_equity = $this->calculate_historical_book_value_growth($balance_sheet_data, $log_messages);
        if ($g_equity === null) {
            if (is_array($company_overview) && isset($company_overview['DividendPayoutRatio']) && is_numeric($company_overview['DividendPayoutRatio']) && $roe > 0) {
                $payout_ratio = (float) $company_overview['DividendPayoutRatio'];
                if ($payout_ratio >= 0 && $payout_ratio <=1) {
                    $sustainable_growth = $roe * (1 - $payout_ratio);
                    if ($sustainable_growth >= 0 && $sustainable_growth < $this->cost_of_equity && $sustainable_growth < self::MAX_HISTORICAL_BV_GROWTH_CAP) {
                         $g_equity = $sustainable_growth;
                         $log_messages[] = sprintf(__('ERM: Using sustainable growth rate for equity: %.2f%%', 'journey-to-wealth'), $g_equity * 100);
                    }
                }
            }
        }
        if ($g_equity === null) { 
            $g_equity = self::DEFAULT_EQUITY_GROWTH_RATE_FALLBACK;
            $log_messages[] = sprintf(__('ERM: Using fallback equity growth rate: %.2f%%', 'journey-to-wealth'), $g_equity * 100);
        }

        // 4. Project Future Excess Returns and Discount Them
        $sum_pv_excess_returns = 0;
        $projected_book_value_current_year = $current_book_value_equity; 
        for ($i = 1; $i <= $this->projection_years; $i++) {
            $projected_earnings = $roe * $projected_book_value_current_year; 
            $excess_return_amount = $projected_earnings - ($this->cost_of_equity * $projected_book_value_current_year);
            $pv_excess_return = $excess_return_amount / pow((1 + $this->cost_of_equity), $i);
            $sum_pv_excess_returns += $pv_excess_return;
            $projected_book_value_current_year *= (1 + $g_equity); 
        }

        // 5. Calculate Terminal Value of Excess Returns
        $pv_terminal_value_excess_returns = 0;
        $book_value_at_terminal_year_start = $projected_book_value_current_year; 
        if ($roe > $this->cost_of_equity) { 
            $terminal_year_excess_return = ($roe - $this->cost_of_equity) * $book_value_at_terminal_year_start;
            if (($this->cost_of_equity - $this->terminal_growth_rate_excess_returns) > 0.0001) { 
                $terminal_value_er = ($terminal_year_excess_return * (1 + $this->terminal_growth_rate_excess_returns)) /
                                     ($this->cost_of_equity - $this->terminal_growth_rate_excess_returns);
                $pv_terminal_value_excess_returns = $terminal_value_er / pow((1 + $this->cost_of_equity), $this->projection_years);
                $log_messages[] = sprintf(__('ERM: Calculated PV of Terminal Excess Returns: $%.0f', 'journey-to-wealth'), $pv_terminal_value_excess_returns);
            } else {
                $log_messages[] = __('ERM Warning: Cost of equity not sufficiently greater than terminal growth of excess returns. Terminal value of excess returns assumed zero.', 'journey-to-wealth');
            }
        } else {
            $log_messages[] = __('ERM Note: ROE not expected to sustainably exceed Cost of Equity. Terminal value of excess returns assumed zero.', 'journey-to-wealth');
        }

        // 6. Calculate Total Equity Value
        $total_equity_value = $current_book_value_equity + $sum_pv_excess_returns + $pv_terminal_value_excess_returns;

        // 7. Calculate Intrinsic Value per Share
        $shares_outstanding = null;
        if (is_array($company_overview) && isset($company_overview['SharesOutstanding']) && is_numeric($company_overview['SharesOutstanding']) && (float)$company_overview['SharesOutstanding'] > 0) {
            $shares_outstanding = (float) $company_overview['SharesOutstanding'];
        } elseif (is_array($balance_sheet_data) && !empty($balance_sheet_data)) {
             $latest_bs_for_shares = $balance_sheet_data[0]; 
             if (isset($latest_bs_for_shares['commonStockSharesOutstanding']) && is_numeric($latest_bs_for_shares['commonStockSharesOutstanding']) && (float)$latest_bs_for_shares['commonStockSharesOutstanding'] > 0) {
                 $shares_outstanding = (float) $latest_bs_for_shares['commonStockSharesOutstanding'];
                  $log_messages[] = __('ERM: Used shares outstanding from balance sheet.', 'journey-to-wealth');
             }
        }
        if ($shares_outstanding === null || $shares_outstanding <= 0) {
            return new WP_Error('erm_missing_shares', __('ERM Error: Shares outstanding data missing or invalid.', 'journey-to-wealth'));
        }
        $intrinsic_value_per_share = $total_equity_value / $shares_outstanding;

        return array(
            'intrinsic_value_per_share' => round($intrinsic_value_per_share, 2),
            'model_used'                => __('Excess Returns Model', 'journey-to-wealth'),
            'current_book_value_equity' => round($current_book_value_equity, 0),
            'calculated_roe'            => round($roe * 100, 2), 
            'cost_of_equity_used'       => round($this->cost_of_equity * 100, 2), 
            'equity_growth_rate_used'   => round($g_equity * 100, 2), 
            'sum_pv_excess_returns'     => round($sum_pv_excess_returns, 0),
            'pv_terminal_value_excess_returns' => round($pv_terminal_value_excess_returns, 0),
            'log_messages'              => $log_messages
        );
    }

    public function get_interpretation( $intrinsic_value, $current_market_price ) {
        // ... (interpretation logic remains the same) ...
        if (!is_numeric($intrinsic_value) || !is_numeric($current_market_price) || $current_market_price == 0) {
            if (!is_numeric($intrinsic_value)) { return __('ERM Fair Value Estimate could not be determined numerically.', 'journey-to-wealth'); }
            return __('Cannot provide full ERM interpretation due to missing current market price.', 'journey-to-wealth');
        }
        $ivps = (float) $intrinsic_value; $cmp = (float) $current_market_price;
        $diff_pct = (($ivps - $cmp) / $cmp) * 100; $status_text = '';
        if ($ivps < 0 && $cmp > 0) { $status_text = __('ERM resulted in a negative intrinsic value, suggesting the company may be destroying value relative to its book value and cost of equity. The stock appears significantly overvalued.', 'journey-to-wealth');
        } elseif ($ivps < 0) { $status_text = __('ERM resulted in a negative intrinsic value. This model may not be appropriate or indicates severe issues.', 'journey-to-wealth');
        } elseif ($diff_pct > 20) { $status_text = sprintf(__('suggests potential undervaluation by %.1f%%.', 'journey-to-wealth'), $diff_pct);
        } elseif ($diff_pct < -20) { $status_text = sprintf(__('suggests potential overvaluation by %.1f%%.', 'journey-to-wealth'), abs($diff_pct));
        } else { $status_text = sprintf(__('suggests relative fair valuation (difference of %.1f%%).', 'journey-to-wealth'), $diff_pct); }
        return sprintf(__('Excess Returns Model Fair Value Estimate: $%.2f. Current Price: $%.2f. This %s', 'journey-to-wealth'), $ivps, $cmp, $status_text);
    }
}
