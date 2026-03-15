<?php
/**
 * GSC Admin Page Manager
 */
if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_GSC_Admin_Page {
    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_menu() {
        add_submenu_page(
            'yali-ai-writer',       // Parent slug
            __('SEO 增长雷达: 流量洞察', 'yali-ai-writer'), // Page title
            __('流量洞察', 'yali-ai-writer'),             // Menu title
            'manage_options',       // Capability
            'yali-gsc-dashboard',   // Menu slug
            [$this, 'render_page']  // Callback
        );
    }

    public function enqueue_assets($hook) {
        if (strpos($hook, 'yali-gsc-dashboard') === false) {
            return;
        }

        // Use core style handles where common
        wp_enqueue_style('yali-gsc-dashboard', YALI_GSC_URL . 'assets/css/gsc-dashboard.css', [], time());
        
        // Enqueue Chart.js for the radar chart
        wp_enqueue_script('chart-js', 'https://cdn.bootcdn.net/ajax/libs/Chart.js/3.9.1/chart.min.js', [], '3.9.1', true);
        
        wp_enqueue_script('yali-gsc-dashboard', YALI_GSC_URL . 'assets/js/gsc-dashboard.js', ['jquery', 'chart-js', 'wp-i18n'], time(), true);
        
        // Load JS translations
        wp_set_script_translations('yali-gsc-dashboard', 'yali-ai-writer', CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'languages');

        // Pass global variables to JS
        wp_localize_script('yali-gsc-dashboard', 'gscDashboardData', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('yali_gsc_nonce'),
            'siteUrl' => ContentAuto_GSC_API_Client::get_selected_site()
        ]);
        
        // Ensure Swal and UI Kit items
        wp_enqueue_style('yali-ai-brand-tokens', plugin_dir_url(dirname(__FILE__)) . 'shared/assets/css/brand-tokens.css');
        wp_enqueue_style('yali-ai-ui-kit', plugin_dir_url(dirname(__FILE__)) . 'shared/assets/css/yali-ui-kit.css');
        wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11');
    }

    public function render_page() {
        $is_authorized = ContentAuto_GSC_API_Client::is_authorized();
        $selected_site = ContentAuto_GSC_API_Client::get_selected_site();

        include YALI_GSC_DIR . 'views/gsc-dashboard.php';
    }
}
