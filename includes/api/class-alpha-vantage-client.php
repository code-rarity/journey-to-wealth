<?php
/**
 * Alpha Vantage API Client for Journey to Wealth plugin.
 *
 * Handles communication with the Alpha Vantage API to fetch stock data.
 * Implements caching and specific rate limit error handling.
 *
 * @link       https://example.com/journey-to-wealth/
 * @since      1.0.0
 *
 * @package    Journey_To_Wealth
 * @subpackage Journey_To_Wealth/includes/api
 */

// Prevent direct access to this file.
if ( ! defined( 'WPINC' ) ) {
    die;
}

class Alpha_Vantage_Client {

    private $api_key;
    private $base_url = 'https://www.alphavantage.co/query';
    private $cache_expiration_long = 3600; // 1 hour for overview/daily data
    private $cache_expiration_short = 900; // 15 minutes for quotes
    private $cache_expiration_statements = 86400; // 24 hours for financial statements
    private $cache_expiration_search = 21600; // 6 hours for searches
    private $cache_expiration_yield = 86400; // 24 hours for treasury yield

    public function __construct( $api_key ) {
        $this->api_key = $api_key;
    }

    private function do_request( $params, $transient_key, $expiration ) {
        $cached_data = get_transient( $transient_key );
        if ( false !== $cached_data ) {
            return $cached_data;
        }

        $request_url = add_query_arg( $params, $this->base_url );
        $response = wp_remote_get( $request_url, array( 'timeout' => 20 ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'http_request_failed', __( 'Failed to connect to Alpha Vantage API.', 'journey-to-wealth' ) . ' ' . $response->get_error_message() );
        }

        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) {
            return new WP_Error( 'empty_response', __( 'Received empty response from Alpha Vantage API.', 'journey-to-wealth' ) );
        }

        $data = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error( 'json_decode_error', __( 'Failed to decode JSON response from Alpha Vantage API.', 'journey-to-wealth' ) );
        }

        if ( isset( $data['Information'] ) && strpos( $data['Information'], 'Thank you for using Alpha Vantage!' ) !== false ) {
            return new WP_Error( 'api_rate_limit', __( 'API call frequency limit reached. Please wait a moment and try again. Free keys have very strict limits.', 'journey-to-wealth' ) );
        }
        
        if ( isset( $data['Error Message'] ) ) {
            return new WP_Error( 'alpha_vantage_api_error', esc_html( $data['Error Message'] ) );
        }

        set_transient( $transient_key, $data, $expiration );
        return $data;
    }

    public function search_symbols( $keywords ) {
        if ( empty( $this->api_key ) ) return new WP_Error( 'api_key_missing', __( 'API Key not configured.', 'journey-to-wealth' ) );
        $keywords = sanitize_text_field( $keywords );
        $params = array( 'function' => 'SYMBOL_SEARCH', 'keywords' => $keywords, 'apikey' => $this->api_key );
        $transient_key = 'jtw_search_' . md5( $keywords );

        $data = $this->do_request( $params, $transient_key, $this->cache_expiration_search );
        if (is_wp_error($data)) return $data;

        if ( ! isset( $data['bestMatches'] ) ) {
            return new WP_Error( 'no_search_results', sprintf( __( 'No search results found for %s.', 'journey-to-wealth' ), $keywords ) );
        }
        return $data['bestMatches'];
    }

    public function get_company_overview( $symbol ) {
        if ( empty( $this->api_key ) ) return new WP_Error( 'api_key_missing', __( 'API Key not configured.', 'journey-to-wealth' ) );
        $symbol = sanitize_text_field( strtoupper( $symbol ) );
        $params = array( 'function' => 'OVERVIEW', 'symbol' => $symbol, 'apikey' => $this->api_key );
        $transient_key = 'jtw_overview_' . md5( $symbol );

        $data = $this->do_request( $params, $transient_key, $this->cache_expiration_long );
        if (is_wp_error($data)) return $data;

        if ( empty( (array) $data ) || (isset($data['Symbol']) && $data['Symbol'] !== $symbol && $data['Symbol'] !== null ) ){
             return new WP_Error( 'no_company_overview_data', sprintf( __( 'No company overview data found for symbol %s.', 'journey-to-wealth' ), $symbol ) );
        }
        return $data;
    }

    public function get_global_quote( $symbol ) {
        if ( empty( $this->api_key ) ) return new WP_Error( 'api_key_missing', __( 'API Key not configured.', 'journey-to-wealth' ) );
        $symbol = sanitize_text_field( strtoupper( $symbol ) );
        $params = array( 'function' => 'GLOBAL_QUOTE', 'symbol' => $symbol, 'apikey' => $this->api_key );
        $transient_key = 'jtw_quote_' . md5( $symbol );

        $data = $this->do_request( $params, $transient_key, $this->cache_expiration_short );
        if (is_wp_error($data)) return $data;

        if ( ! isset( $data['Global Quote'] ) || empty( $data['Global Quote'] ) ) {
            return new WP_Error( 'no_global_quote_data', sprintf( __( 'No global quote data found for symbol %s.', 'journey-to-wealth' ), $symbol ) );
        }
        return $data['Global Quote'];
    }
    
    public function get_income_statement( $symbol ) {
        if ( empty( $this->api_key ) ) return new WP_Error( 'api_key_missing', __( 'API Key not configured.', 'journey-to-wealth' ) );
        $symbol = sanitize_text_field( strtoupper( $symbol ) );
        $params = array( 'function' => 'INCOME_STATEMENT', 'symbol' => $symbol, 'apikey' => $this->api_key );
        $transient_key = 'jtw_incomestmt_' . md5( $symbol );

        $data = $this->do_request( $params, $transient_key, $this->cache_expiration_statements );
        if (is_wp_error($data)) return $data;
        
        if ( ! isset( $data['annualReports'] ) ) {
            return new WP_Error( 'no_income_statement_data', sprintf( __( 'No annual income statement data found for %s.', 'journey-to-wealth' ), $symbol ) );
        }
        return $data;
    }

    public function get_balance_sheet( $symbol ) {
        if ( empty( $this->api_key ) ) return new WP_Error( 'api_key_missing', __( 'API Key not configured.', 'journey-to-wealth' ) );
        $symbol = sanitize_text_field( strtoupper( $symbol ) );
        $params = array( 'function' => 'BALANCE_SHEET', 'symbol' => $symbol, 'apikey' => $this->api_key );
        $transient_key = 'jtw_balancesheet_' . md5( $symbol );
        
        $data = $this->do_request( $params, $transient_key, $this->cache_expiration_statements );
        if (is_wp_error($data)) return $data;

        if ( ! isset( $data['annualReports'] ) ) {
            return new WP_Error( 'no_balance_sheet_data', sprintf( __( 'No annual balance sheet data found for %s.', 'journey-to-wealth' ), $symbol ) );
        }
        return $data;
    }

    public function get_cash_flow_statement( $symbol ) {
        if ( empty( $this->api_key ) ) return new WP_Error( 'api_key_missing', __( 'API Key not configured.', 'journey-to-wealth' ) );
        $symbol = sanitize_text_field( strtoupper( $symbol ) );
        $params = array( 'function' => 'CASH_FLOW', 'symbol' => $symbol, 'apikey' => $this->api_key );
        $transient_key = 'jtw_cashflow_' . md5( $symbol );
        
        $data = $this->do_request( $params, $transient_key, $this->cache_expiration_statements );
        if (is_wp_error($data)) return $data;

        if ( ! isset( $data['annualReports'] ) ) {
            return new WP_Error( 'no_cash_flow_data', sprintf( __( 'No annual cash flow data found for %s.', 'journey-to-wealth' ), $symbol ) );
        }
        return $data;
    }
    
    public function get_earnings_data( $symbol ) {
        if ( empty( $this->api_key ) ) return new WP_Error( 'api_key_missing', __( 'API Key not configured.', 'journey-to-wealth' ) );
        $symbol = sanitize_text_field( strtoupper( $symbol ) );
        $params = array( 'function' => 'EARNINGS', 'symbol' => $symbol, 'apikey' => $this->api_key );
        $transient_key = 'jtw_earnings_' . md5( $symbol );

        $data = $this->do_request( $params, $transient_key, $this->cache_expiration_statements );
        if (is_wp_error($data)) return $data;

        if ( !isset( $data['symbol'] ) || ( !isset($data['annualEarnings']) && !isset($data['quarterlyEarnings']) ) ) {
            return new WP_Error( 'no_earnings_data', sprintf( __( 'No earnings data found or unexpected structure for symbol %s.', 'journey-to-wealth' ), $symbol ) );
        }
        return $data;
    }

    public function get_daily_adjusted( $symbol ) {
        if ( empty( $this->api_key ) ) return new WP_Error( 'api_key_missing', __( 'API Key not configured.', 'journey-to-wealth' ) );
        $symbol = sanitize_text_field( strtoupper( $symbol ) );
        $params = array( 'function' => 'TIME_SERIES_DAILY_ADJUSTED', 'symbol' => $symbol, 'outputsize' => 'full', 'apikey' => $this->api_key );
        $transient_key = 'jtw_daily_adjusted_' . md5( $symbol );

        $data = $this->do_request( $params, $transient_key, $this->cache_expiration_long );
        if (is_wp_error($data)) return $data;

        if ( !isset( $data['Time Series (Daily)'] ) ) {
            return new WP_Error( 'no_daily_data', sprintf( __( 'No daily time series data found for %s.', 'journey-to-wealth' ), $symbol ) );
        }
        return $data;
    }

    public function get_treasury_yield() {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'api_key_missing', __( 'API Key not configured.', 'journey-to-wealth' ) );
        }
        
        $params = [
            'function' => 'TREASURY_YIELD',
            'interval' => 'monthly',
            'maturity' => '10year',
            'apikey'   => $this->api_key
        ];
        $transient_key = 'jtw_treasury_yield_10y';
        
        $data = $this->do_request( $params, $transient_key, $this->cache_expiration_yield ); 

        if ( is_wp_error($data) || !isset( $data['data'][0]['value'] ) ) {
            return new WP_Error( 'no_treasury_yield_data', __( 'Could not retrieve Treasury Yield data.', 'journey-to-wealth' ) );
        }
        
        return $data;
    }

    public function get_currency_exchange_rate( $from_currency, $to_currency ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'api_key_missing', __( 'API Key not configured.', 'journey-to-wealth' ) );
        }
        
        $params = [
            'function'      => 'CURRENCY_EXCHANGE_RATE',
            'from_currency' => $from_currency,
            'to_currency'   => $to_currency,
            'apikey'        => $this->api_key
        ];
        $transient_key = 'jtw_exchange_rate_' . $from_currency . '_' . $to_currency;

        return $this->do_request( $params, $transient_key, $this->cache_expiration_short );
    }
}
