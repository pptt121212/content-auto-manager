<?php
/**
 * ContentAuto GSC API Client
 */
if (!defined('ABSPATH')) {
    exit;
}

class Yali_AI_Writer_GSC_API_Client {
    private static $instance = null;
    private $access_token = '';
    private $refresh_token = '';
    private $expires_at = 0;
    private $selected_site = '';
    private $proxy_url = '';

    public static function get_instance() {
        if (self::$instance == null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->access_token  = get_option('yali_gsc_access_token');
        $this->refresh_token = get_option('yali_gsc_refresh_token');
        $this->expires_at    = (int) get_option('yali_gsc_token_expires', 0);
        $this->selected_site = get_option('yali_gsc_selected_site');
        // Proxy URL is hardcoded by convention to look at root gsc-auth directory
        $this->proxy_url     = site_url('/gsc-auth/');
    }

    public static function is_authorized() {
        return !empty(get_option('yali_gsc_refresh_token'));
    }

    public static function has_selected_site() {
        return !empty(get_option('yali_gsc_selected_site'));
    }

    public static function get_selected_site() {
        return get_option('yali_gsc_selected_site');
    }

    /**
     * Get access token and refresh if expired
     */
    public function get_access_token() {
        if (empty($this->refresh_token)) {
            return false;
        }

        // If expired or about to expire in 5 mins
        if (empty($this->access_token) || ($this->expires_at - time()) < 300) {
            if (!$this->refresh_access_token()) {
                return false;
            }
        }

        return $this->access_token;
    }

    /**
     * Refresh access token via local proxy
     */
    public function refresh_access_token() {
        if (empty($this->refresh_token)) return false;

        $response = wp_remote_post($this->proxy_url . '?action=refresh', [
            'body' => json_encode(['refresh_token' => $this->refresh_token]),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 30
        ]);

        if (is_wp_error($response)) return false;

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['success']) && $body['success'] === true && isset($body['access_token'])) {
            $this->access_token = $body['access_token'];
            $this->expires_at   = time() + ($body['expires_in'] ?? 3600);
            
            update_option('yali_gsc_access_token', $this->access_token);
            update_option('yali_gsc_token_expires', $this->expires_at);
            return true;
        }

        return false;
    }

    /**
     * Get list of authorized sites
     */
    public function get_sites() {
        $token = $this->get_access_token();
        if (!$token) return false;

        $url = 'https://www.googleapis.com/webmasters/v3/sites';
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json'
            ],
            'timeout' => 30
        ]);

        if (is_wp_error($response)) return false;

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['siteEntry'] ?? [];
    }

    public function fetch_api_data($endpoint, $body = []) {
        $token = $this->get_access_token();
        if (!$token || empty($this->selected_site)) return false;

        $url = 'https://www.googleapis.com/webmasters/v3/sites/' . urlencode($this->selected_site) . $endpoint;
        
        $response = wp_remote_post($url, [
            'body' => json_encode($body),
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json'
            ],
            'timeout' => 60
        ]);

        if (is_wp_error($response)) return false;

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body;
    }

    /**
     * Batch fetch metrics
     */
    public function get_metrics($days = 30) {
        $end_date = date('Y-m-d', strtotime('-3 days')); // GSC typically has ~3 day lag
        $start_date = date('Y-m-d', strtotime("-$days days -3 days"));

        $body = [
            'startDate' => $start_date,
            'endDate'   => $end_date,
            'dimensions' => ['date']
        ];

        return $this->fetch_api_data('/searchAnalytics/query', $body);
    }

    /**
     * Get individual queries or pages
     */
    public function get_analytics_data($dimension = 'query', $days = 30, $rowLimit = 500) {
        $end_date = date('Y-m-d', strtotime('-3 days'));
        $start_date = date('Y-m-d', strtotime("-$days days -3 days"));

        $body = [
            'startDate' => $start_date,
            'endDate'   => $end_date,
            'dimensions' => [$dimension],
            'rowLimit'   => $rowLimit
        ];

        return $this->fetch_api_data('/searchAnalytics/query', $body);
    }

    /**
     * Disconnect and clear tokens
     */
    public static function disconnect() {
        delete_option('yali_gsc_access_token');
        delete_option('yali_gsc_refresh_token');
        delete_option('yali_gsc_token_expires');
        delete_option('yali_gsc_selected_site');
    }
}
