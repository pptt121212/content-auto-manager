<?php
/**
 * GSC Admin Page Manager
 */
if (!defined('ABSPATH')) {
    exit;
}

class Yali_AI_Writer_GSC_Admin_Page {
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
        wp_enqueue_style('yali-gsc-dashboard', YALI_AI_WRITER_GSC_URL . 'assets/css/gsc-dashboard.css', [], time());
        
        // Enqueue Chart.js for the radar chart (localized)
        wp_enqueue_script('chart-js', plugin_dir_url(dirname(__FILE__)) . 'shared/assets/js/vendor/chart.min.js', [], '3.9.1', true);
        
        // Enqueue SweetAlert2 (localized)
        wp_enqueue_style('sweetalert2', plugin_dir_url(dirname(__FILE__)) . 'shared/assets/js/vendor/sweetalert2.min.css', [], '11.0.0');
        wp_enqueue_script('sweetalert2', plugin_dir_url(dirname(__FILE__)) . 'shared/assets/js/vendor/sweetalert2.all.min.js', [], '11.0.0', true);
        
        // Enqueue GSC dashboard specific styles (extracted from inline)
        wp_enqueue_style('yali-gsc-dashboard-inline', YALI_AI_WRITER_GSC_URL . 'assets/css/gsc-dashboard-inline.css', [], YALI_AI_WRITER_VERSION);
        
        wp_enqueue_script('yali-gsc-dashboard', YALI_AI_WRITER_GSC_URL . 'assets/js/gsc-dashboard.js', ['jquery', 'chart-js', 'sweetalert2', 'wp-i18n'], time(), true);
        
        // Load JS translations
        wp_set_script_translations('yali-gsc-dashboard', 'yali-ai-writer', YALI_AI_WRITER_PLUGIN_DIR . 'languages');

        // Pass global variables to JS
        wp_localize_script('yali-gsc-dashboard', 'gscDashboardData', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('yali_gsc_nonce'),
            'siteUrl' => Yali_AI_Writer_GSC_API_Client::get_selected_site()
        ]);
        
        // Ensure UI Kit items
        wp_enqueue_style('yali-ai-brand-tokens', plugin_dir_url(dirname(__FILE__)) . 'shared/assets/css/brand-tokens.css');
        wp_enqueue_style('yali-ai-ui-kit', plugin_dir_url(dirname(__FILE__)) . 'shared/assets/css/yali-ui-kit.css');
    }

    public function render_page() {
        $is_authorized = Yali_AI_Writer_GSC_API_Client::is_authorized();
        $selected_site = Yali_AI_Writer_GSC_API_Client::get_selected_site();

        include YALI_AI_WRITER_GSC_DIR . 'views/gsc-dashboard.php';
    }
}
