<?php
/**
 * Consolidated Key Valuation Metrics Calculator for Journey to Wealth plugin.
 * This version is refactored to work with pre-processed values from the public class.
 *
 * @link       https://example.com/journey-to-wealth/
 * @since      1.0.0
 *
 * @package    Journey_To_Wealth
 * @subpackage Journey_To_Wealth/includes/analysis/models
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Journey_To_Wealth_Key_Metrics_Calculator {

    public function __construct() {}

    /**
     * Finds a financial value from a statement section using a direct key lookup.
     *
     * @param array  $financial_statement_section The associative array for the statement (e.g., income_statement).
     * @param string $key The key for the desired metric (e.g., 'revenues').
     * @return float|null The numeric value or null if not found.
     */
    private function find_financial_value($financial_statement_section, $key) {
        if (!is_array($financial_statement_section) || !isset($financial_statement_section[$key]['value'])) {
            return null;
        }
        $value = $financial_statement_section[$key]['value'];
        return is_numeric($value) ? (float)$value : null;
    }

    public function calculate_pe_ratio( $stock_price, $eps ) {
        if (!is_numeric($stock_price) || !is_numeric($eps) || $eps <= 0 || $stock_price <= 0) return 'N/A';
        return round($stock_price / $eps, 2);
    }

    public function calculate_pb_ratio( $market_cap, $book_value ) {
        if (!is_numeric($market_cap) || !is_numeric($book_value) || $book_value <= 0 || $market_cap <= 0) return 'N/A';
        return round($market_cap / $book_value, 2);
    }

    public function calculate_ps_ratio( $market_cap, $revenue ) {
        if (!is_numeric($market_cap) || !is_numeric($revenue) || $revenue <= 0 || $market_cap <= 0) return 'N/A';
        return round($market_cap / $revenue, 2);
    }
    
    public function calculate_enterprise_value($market_cap, $financials_reports) {
        if (!is_numeric($market_cap) || !is_array($financials_reports) || empty($financials_reports)) return null;
        $latest_bs_section = $financials_reports[0]['financials']['balance_sheet'] ?? [];
        if (empty($latest_bs_section)) return null;
        
        $long_term_debt = $this->find_financial_value($latest_bs_section, 'long_term_debt') ?? 0;
        // Using 'other_current_liabilities' as a proxy for short-term debt per user request.
        $other_current_liabilities = $this->find_financial_value($latest_bs_section, 'other_current_liabilities') ?? 0;
        $total_debt = $long_term_debt + $other_current_liabilities;

        // Using 'other_current_assets' as a proxy for cash and equivalents per user request.
        $cash_and_equivalents = $this->find_financial_value($latest_bs_section, 'other_current_assets') ?? 0;
        
        return $market_cap + $total_debt - $cash_and_equivalents;
    }

    /**
     * Calculates EBITDA (Earnings Before Interest, Taxes, Depreciation, and Amortization).
     *
     * @param array $financial_report A single financial report, which should be an annual report.
     * @return float|string|null The calculated EBITDA, a specific notice, or null if other components are missing.
     */
    public function calculate_ebitda($financial_report) {
        if (!is_array($financial_report) || empty($financial_report)) {
            return null;
        }

        $latest_income_statement = $financial_report['financials']['income_statement'] ?? [];
        if (empty($latest_income_statement)) {
            return null;
        }

        // EBITDA is calculated as: Operating Income + Depreciation & Amortization.
        $operating_income = $this->find_financial_value($latest_income_statement, 'operating_income_loss');
        
        // Per user request, using this specific key, which is only available in the annual timeframe.
        $depreciation_amortization = $this->find_financial_value($latest_income_statement, 'depreciation_and_amortization');

        if ($depreciation_amortization === null) {
            return "N/A - No Data On Depreciation & Amortization";
        }

        if (is_numeric($operating_income) && is_numeric($depreciation_amortization)) {
            return $operating_income + $depreciation_amortization;
        }
        
        // Fallback if operating income isn't numeric but D&A was found.
        return null;
    }

    public function calculate_ev_ebitda( $enterprise_value, $ebitda ) {
        if ($enterprise_value === null || !is_numeric($enterprise_value) || $enterprise_value <= 0) return 'N/A';
        if ($ebitda === null || !is_numeric($ebitda) || $ebitda <= 0) return 'N/A';
        return round($enterprise_value / $ebitda, 2);
    }

    public function calculate_ev_sales($enterprise_value, $revenue) {
        if ($enterprise_value === null || !is_numeric($enterprise_value) || $enterprise_value <= 0) return 'N/A';
        if ($revenue === null || !is_numeric($revenue) || $revenue <= 0) return 'N/A';
        return round($enterprise_value / $revenue, 2);
    }

    public function calculate_fcf_yield( $financials_reports, $market_cap ) {
        if (!is_array($financials_reports) || empty($financials_reports) || !is_numeric($market_cap) || $market_cap <= 0) return 'N/A';
        $latest_cf_section = $financials_reports[0]['financials']['cash_flow_statement'] ?? [];
        if (empty($latest_cf_section)) return 'N/A';

        $op_cf = $this->find_financial_value($latest_cf_section, 'net_cash_flow_from_operating_activities');
        $investing_cf = $this->find_financial_value($latest_cf_section, 'net_cash_flow_from_investing_activities');

        if ($op_cf === null || $investing_cf === null) return 'N/A';
        $fcf = $op_cf + $investing_cf;
        return round(($fcf / $market_cap) * 100, 2);
    }
    
    public function calculate_historical_eps_growth($financials_reports) {
        if (!is_array($financials_reports) || count($financials_reports) < 2) return null;
        $financials_reports = array_reverse($financials_reports);
        $relevant_financials = array_slice($financials_reports, -6);
        $positive_eps = [];
        foreach($relevant_financials as $report) {
            $income_statement = $report['financials']['income_statement'] ?? [];
            $eps = $this->find_financial_value($income_statement, 'diluted_earnings_per_share');
            if ($eps !== null && $eps > 0) {
                $positive_eps[] = $eps;
            }
        }
        if (count($positive_eps) < 2) return null;
        $growth_rates = [];
        for ($i = 1; $i < count($positive_eps); $i++) {
            $growth = ($positive_eps[$i] - $positive_eps[$i-1]) / $positive_eps[$i-1];
            $growth_rates[] = $growth;
        }
        if (empty($growth_rates)) return null;
        $average_growth = array_sum($growth_rates) / count($growth_rates);
        return max(0, min($average_growth, 0.50));
    }
}
