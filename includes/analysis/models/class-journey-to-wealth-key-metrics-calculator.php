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

    private function find_financial_value($financial_statement_section, $label) {
        if (!is_array($financial_statement_section)) return null;
        foreach ($financial_statement_section as $item) {
            if (isset($item['label']) && strcasecmp($item['label'], $label) === 0 && isset($item['value'])) {
                return is_numeric($item['value']) ? (float)$item['value'] : null;
            }
        }
        return null;
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
        $long_term_debt = $this->find_financial_value($latest_bs_section, 'Long-Term Debt') ?? 0;
        $short_term_debt = $this->find_financial_value($latest_bs_section, 'Short-Term Debt') ?? 0;
        $total_debt = $long_term_debt + $short_term_debt;
        $cash_and_equivalents = $this->find_financial_value($latest_bs_section, 'Cash and Cash Equivalents') ?? 0;
        return $market_cap + $total_debt - $cash_and_equivalents;
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
        $op_cf = $this->find_financial_value($latest_cf_section, 'Net Cash Flow From Operating Activities, Continuing');
        $investing_cf = $this->find_financial_value($latest_cf_section, 'Net Cash Flow From Investing Activities');
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
            $eps = $this->find_financial_value($income_statement, 'Diluted Earnings Per Share');
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
