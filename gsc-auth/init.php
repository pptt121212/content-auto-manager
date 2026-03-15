<?php
/**
 * GSC Auth Module Initialization
 */
if (!defined('ABSPATH')) {
    exit;
}

define('YALI_GSC_DIR', plugin_dir_path(__FILE__));
define('YALI_GSC_URL', plugin_dir_url(__FILE__));

// Require necessary files
if (file_exists(YALI_GSC_DIR . 'class-gsc-api-client.php')) {
    require_once YALI_GSC_DIR . 'class-gsc-api-client.php';
}
if (file_exists(YALI_GSC_DIR . 'class-gsc-admin-page.php')) {
    require_once YALI_GSC_DIR . 'class-gsc-admin-page.php';
}
if (file_exists(YALI_GSC_DIR . 'ajax-handler.php')) {
    require_once YALI_GSC_DIR . 'ajax-handler.php';
}

// Initialize the GSC Admin Page
add_action('plugins_loaded', function() {
    if (class_exists('ContentAuto_GSC_Admin_Page')) {
        new ContentAuto_GSC_Admin_Page();
    }
});

// Early Exchange Handler (Before any output)
add_action('admin_init', function() {
    if (isset($_GET['page']) && $_GET['page'] === 'yali-gsc-dashboard' && isset($_GET['gsc_exchange_code'])) {
        $code = sanitize_text_field($_GET['gsc_exchange_code']);
        $proxy_url = site_url('/gsc-auth/');
        
        $response = wp_remote_post($proxy_url . '?action=exchange', [
            'body' => json_encode(['exchange_code' => $code]),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 30
        ]);

        if (!is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['success']) && $body['success'] === true) {
                update_option('yali_gsc_access_token', $body['access_token']);
                if (!empty($body['refresh_token'])) {
                    update_option('yali_gsc_refresh_token', $body['refresh_token']);
                }
                update_option('yali_gsc_token_expires', time() + ($body['expires_in'] ?? 3600));
                
                // Success! Redirect to clean URL
                wp_safe_redirect(admin_url('admin.php?page=yali-gsc-dashboard&gsc_auth_success=1'));
                exit;
            }
        }
    }
});
