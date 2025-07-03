<?php
/**
 * Excess Returns Valuation Model for Journey to Wealth plugin.
 * Aligned with the single-stage per-share model used by Simply Wall St.
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

class Journey_To_Wealth_Excess_Return_Model {

    private $cost_of_equity;
    private $terminal_growth_rate;
    private $equity_risk_premium;
    private $levered_beta;

    public function __construct($equity_risk_premium, $levered_beta) {
        $this->equity_risk_premium = $equity_risk_premium;
        $this->levered_beta = $levered_beta;
    }

    private function get_av_value($report, $key, $default = 0) {
        if (isset($report[$key]) && is_numeric($report[$key]) && $report[$key] !== 'None') {
            return (float)$report[$key];
        }
        return null;
    }

    public function calculate($overview, $income_statement, $balance_sheet, $treasury_yield, $latest_price, $beta_details) {
        if (is_wp_error($balance_sheet) || empty($balance_sheet['annualReports']) || is_wp_error($income_statement) || empty($income_statement['annualReports'])) {
            return new WP_Error('erm_missing_financials', __('ERM Error: Financial statements missing.', 'journey-to-wealth'));
        }
        
        $shares_outstanding = $this->get_av_value($overview, 'SharesOutstanding');
        if (empty($shares_outstanding)) {
            return new WP_Error('erm_missing_shares', __('ERM Error: Shares outstanding missing.', 'journey-to-wealth'));
        }

        $risk_free_rate = (new Journey_To_Wealth_DCF_Model())->calculate_average_risk_free_rate($treasury_yield);
        $this->cost_of_equity = $risk_free_rate + ($this->levered_beta * $this->equity_risk_premium);
        $this->terminal_growth_rate = $risk_free_rate;

        $latest_bs_report = $balance_sheet['annualReports'][0];
        $latest_is_report = $income_statement['annualReports'][0];

        $current_book_value_equity = $this->get_av_value($latest_bs_report, 'totalShareholderEquity');
        $net_income = $this->get_av_value($latest_is_report, 'netIncome');

        if ($current_book_value_equity === null || $net_income === null || $current_book_value_equity <= 0) {
             return new WP_Error('erm_missing_b0_or_ni', __('ERM Error: Current Book Value or Net Income is missing or invalid.', 'journey-to-wealth'));
        }
        $roe = $net_income / $current_book_value_equity;
        
        if ($this->cost_of_equity <= $this->terminal_growth_rate) {
            $this->terminal_growth_rate = $this->cost_of_equity - 0.0025; // Ensure Cost of Equity is greater than growth
        }

        // --- Per-Share Calculations ---
        $book_value_per_share = $current_book_value_equity / $shares_outstanding;
        
        // Step 1: Calculate Excess Returns per share
        $excess_return_per_share = ($roe - $this->cost_of_equity) * $book_value_per_share;

        // Step 2: Calculate Terminal Value of Excess Returns per share
        $terminal_value_of_excess_returns_per_share = $excess_return_per_share / ($this->cost_of_equity - $this->terminal_growth_rate);

        // Step 3: Calculate the final Intrinsic Value per share
        $intrinsic_value_per_share = $book_value_per_share + $terminal_value_of_excess_returns_per_share;

        return [
            'intrinsic_value_per_share' => round($intrinsic_value_per_share, 2),
            'calculation_breakdown' => [
                'model_name' => 'Excess Return Model',
                'roe' => $roe,
                'cost_of_equity' => $this->cost_of_equity,
                'terminal_growth_rate' => $this->terminal_growth_rate,
                'current_book_value_per_share' => $book_value_per_share,
                'excess_return_per_share' => $excess_return_per_share,
                'terminal_value_of_excess_returns_per_share' => $terminal_value_of_excess_returns_per_share,
                'intrinsic_value_per_share' => $intrinsic_value_per_share,
                'current_price' => $latest_price,
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
            ]
        ];
    }
}
