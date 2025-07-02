<?php
/**
 * Polygon.io API Client for Journey to Wealth plugin.
 *
 * Handles communication with the Polygon.io API to fetch stock data.
 * Implements caching and specific error handling.
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

class Polygon_Client {

    private $api_key;
    private $base_url = 'https://api.polygon.io';
    private $cache_expiration = 14400; // 4 hours for most data

    public function __construct( $api_key ) {
        $this->api_key = $api_key;
    }

    private function do_request( $endpoint, $params = [] ) {
        $params['apiKey'] = $this->api_key;
        $url = $this->base_url . $endpoint . '?' . http_build_query($params);

        $response = wp_remote_get( $url, array( 'timeout' => 20 ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'http_request_failed', __( 'Failed to connect to Polygon.io API.', 'journey-to-wealth' ) . ' ' . $response->get_error_message() );
        }

        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) {
            return new WP_Error( 'empty_response', __( 'Received empty response from Polygon.io API.', 'journey-to-wealth' ) );
        }

        $data = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error( 'json_decode_error', __( 'Failed to decode JSON response from Polygon.io API.', 'journey-to-wealth' ) );
        }
        
        if ( isset($data['status']) && ($data['status'] === 'ERROR' || $data['status'] === 'DELAYED') ) {
            return new WP_Error( 'polygon_api_error', isset($data['error']) ? esc_html($data['error']) : 'Unknown Polygon.io API error.');
        }
        if ( isset($data['message']) && !isset($data['results']) ) {
            return new WP_Error( 'polygon_api_message', esc_html($data['message']));
        }

        return $data;
    }

    public function get_ticker_details( $ticker ) {
        $ticker = sanitize_text_field( strtoupper( $ticker ) );
        $transient_key = 'jtw_poly_details_' . $ticker;
        
        $cached_data = get_transient($transient_key);
        if (false !== $cached_data) {
            return $cached_data;
        }

        $endpoint = '/v3/reference/tickers/' . $ticker;
        // **FIX:** The 'expand' parameter is not needed here as this endpoint returns full details by default.
        $data = $this->do_request($endpoint);

        if (is_wp_error($data)) {
            return $data;
        }

        if (!isset($data['results'])) {
             return new WP_Error( 'no_ticker_details', sprintf( __( 'No ticker details found for %s.', 'journey-to-wealth' ), $ticker ) );
        }

        $results = $data['results'];
        set_transient($transient_key, $results, $this->cache_expiration);
        return $results;
    }
    
    public function get_previous_close( $ticker ) {
        $ticker = sanitize_text_field( strtoupper( $ticker ) );
        $transient_key = 'jtw_poly_prev_close_' . $ticker;

        $cached_data = get_transient($transient_key);
        if (false !== $cached_data) {
            return $cached_data;
        }

        $endpoint = '/v2/aggs/ticker/' . $ticker . '/prev';
        $data = $this->do_request($endpoint);
        
        if (is_wp_error($data)) {
            return $data;
        }

        if (!isset($data['results'][0]) || !isset($data['results'][0]['c'])) {
            return new WP_Error( 'no_prev_close', sprintf( __( 'No previous close price found for %s.', 'journey-to-wealth' ), $ticker ) );
        }
        
        $results = $data['results'][0];
        set_transient($transient_key, $results, $this->cache_expiration);
        return $results;
    }

    public function get_financials( $ticker, $details, $timeframe = 'annual' ) {
        $cik = $details['cik'] ?? null;
        $identifier = $cik ? $cik : sanitize_text_field(strtoupper($ticker));
        $identifier_type = $cik ? 'cik' : 'ticker';

        $limit = ($timeframe === 'annual') ? 10 : 12;
        $transient_key = 'jtw_poly_financials_' . $identifier . '_' . $timeframe . '_' . $limit;
        
        $cached_data = get_transient($transient_key);
        if (false !== $cached_data) {
            return $cached_data;
        }
        
        $params = [
            $identifier_type => $identifier,
            'timeframe' => $timeframe,
            'limit' => $limit,
            'sort' => 'filing_date',
            'order' => 'desc'
        ];
        $endpoint = '/vX/reference/financials';
        $data = $this->do_request($endpoint, $params);
        
        if (is_wp_error($data)) {
            return $data;
        }
        
        if (!isset($data['results'])) {
            return new WP_Error( 'no_financials_data', sprintf( __( 'Financials data is not available. This endpoint may require a paid Polygon.io plan.', 'journey-to-wealth' ), $timeframe, $ticker ) );
        }

        $results = $data['results'];
        set_transient($transient_key, $results, $this->cache_expiration);
        return $results;
    }

    public function get_daily_aggregates($ticker, $timespan = 'year', $multiplier = 10) {
        $ticker = sanitize_text_field( strtoupper( $ticker ) );
        $from = date('Y-m-d', strtotime("-{$multiplier} {$timespan}"));
        $to = date('Y-m-d');
        $transient_key = 'jtw_poly_aggs_' . md5($ticker . $from . $to);
        
        $cached_data = get_transient($transient_key);
        if (false !== $cached_data) return $cached_data;

        $endpoint = "/v2/aggs/ticker/{$ticker}/range/1/day/{$from}/{$to}";
        $params = ['adjusted' => 'true', 'sort' => 'desc', 'limit' => 50000];
        $data = $this->do_request($endpoint, $params);

        if (is_wp_error($data)) return $data;
        
        if (!isset($data['results'])) {
            return new WP_Error('no_aggregates_data', 'No historical price data found.');
        }

        set_transient($transient_key, $data['results'], $this->cache_expiration);
        return $data['results'];
    }

    public function get_dividends($ticker) {
        $ticker = sanitize_text_field( strtoupper( $ticker ) );
        $transient_key = 'jtw_poly_divs_' . $ticker;

        $cached_data = get_transient($transient_key);
        if (false !== $cached_data) return $cached_data;

        $endpoint = '/v3/reference/dividends';
        $params = [
            'ticker' => $ticker,
            'limit' => 1000,
            'sort' => 'ex_dividend_date',
            'order' => 'desc'
        ];
        $data = $this->do_request($endpoint, $params);

        if (is_wp_error($data)) return $data;

        if (!isset($data['results'])) {
            return new WP_Error('no_dividends_data', 'No dividend data found.');
        }
        
        set_transient($transient_key, $data['results'], $this->cache_expiration);
        return $data['results'];
    }

    public function get_benzinga_earnings($ticker) {
        $ticker = sanitize_text_field( strtoupper( $ticker ) );
        $transient_key = 'jtw_poly_benzinga_earnings_' . $ticker;

        $cached_data = get_transient($transient_key);
        if (false !== $cached_data) {
            return $cached_data;
        }

        $endpoint = '/benzinga/v1/earnings';
        $params = [
            'ticker' => $ticker,
            'limit' => 20,
        ];
        $data = $this->do_request($endpoint, $params);

        if (is_wp_error($data)) {
            return $data;
        }
        
        if (!isset($data['results'])) {
            return new WP_Error('no_earnings_data', 'No Benzinga earnings data found.');
        }

        set_transient($transient_key, $data['results'], 3600);
        return $data['results'];
    }

    /**
     * **REFACTORED:** Performs a general search and limits the results for performance.
     */
    public function search_tickers($query, $limit = 3) {
        $params = [
            'search' => $query,
            'active' => 'true',
            'limit' => $limit
        ];
        return $this->do_request('/v3/reference/tickers', $params);
    }
}
