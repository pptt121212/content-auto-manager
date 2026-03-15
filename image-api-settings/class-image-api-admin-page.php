<?php
// image-api-settings/class-image-api-admin-page.php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class CAM_Image_API_Admin_Page {

    private static $option_name = 'cam_image_api_settings';



    public static function init() {
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_cam_save_image_api_settings', [__CLASS__, 'save_settings_ajax']);
    }

    public static function enqueue_assets($hook) {
        // Check if this is the image API settings page
        // The hook format is: {parent_page}_page_{page_slug}
        if (strpos($hook, '_page_cam-image-api-settings') === false) {
            return;
        }
        
        $version = defined('CONTENT_AUTO_MANAGER_VERSION') ? CONTENT_AUTO_MANAGER_VERSION : '1.0.0';
        $plugin_dir_url = plugin_dir_url(__FILE__);

        wp_enqueue_style(
            'cam-image-api-settings',
            $plugin_dir_url . 'assets/css/image-api-settings.css',
            [],
            $version
        );
        wp_enqueue_script(
            'cam-image-api-settings',
            $plugin_dir_url . 'assets/js/image-api-settings.js',
            ['jquery', 'yali-ai-writer-admin-js'],
            $version,
            true
        );

        // Localize the script with data for AJAX calls
        wp_localize_script(
            'cam-image-api-settings',
            'contentAutoManager',
            [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('content_auto_manager_nonce'), // Generic nonce
                'save_nonce' => wp_create_nonce('cam_save_image_api_settings'), // Specific save nonce
            ]
        );

        // Load translations
        wp_set_script_translations('cam-image-api-settings', 'yali-ai-writer', CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'languages');
    }

    public static function create_page() {
        self::handle_form_submission();
        $settings = self::get_settings();
        include_once plugin_dir_path(__FILE__) . 'views/image-api-config-form.php';
    }

    private static function handle_form_submission() {
        if (!isset($_POST['cam_save_image_api_settings_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['cam_save_image_api_settings_nonce'], 'cam_save_image_api_settings')) {
            wp_die('Nonce verification failed.');
        }

        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }

        // Get existing settings to merge with
        $settings = self::get_settings();

        // Update the active provider
        $settings['provider'] = isset($_POST['cam_image_api_provider']) ? sanitize_text_field($_POST['cam_image_api_provider']) : '';

        // Update ModelScope settings if submitted
        if (isset($_POST['modelscope'])) {
            $modelscope_settings = (array) $_POST['modelscope'];
            $settings['modelscope']['model_id'] = isset($modelscope_settings['model_id']) ? sanitize_text_field($modelscope_settings['model_id']) : '';
            $settings['modelscope']['api_key'] = isset($modelscope_settings['api_key']) ? sanitize_text_field(stripslashes($modelscope_settings['api_key'])) : '';
        }

        // Update OpenAI settings if submitted
        if (isset($_POST['openai'])) {
            $openai_settings = (array) $_POST['openai'];
            $settings['openai']['api_key'] = isset($openai_settings['api_key']) ? sanitize_text_field(stripslashes($openai_settings['api_key'])) : '';
            $settings['openai']['model'] = isset($openai_settings['model']) ? sanitize_text_field($openai_settings['model']) : 'gpt-image-1';
        }

        // Update Silicon Flow settings if submitted
        if (isset($_POST['siliconflow'])) {
            $siliconflow_settings = (array) $_POST['siliconflow'];
            $settings['siliconflow']['api_key'] = isset($siliconflow_settings['api_key']) ? sanitize_text_field(stripslashes($siliconflow_settings['api_key'])) : '';
            $settings['siliconflow']['model'] = isset($siliconflow_settings['model']) ? sanitize_text_field($siliconflow_settings['model']) : 'Qwen/Qwen-Image';
        }

        // Update Pollinations.AI settings if submitted
        if (isset($_POST['pollinations'])) {
            $pollinations_settings = (array) $_POST['pollinations'];
            $settings['pollinations']['model'] = isset($pollinations_settings['model']) ? sanitize_text_field($pollinations_settings['model']) : 'flux';
            $settings['pollinations']['token'] = isset($pollinations_settings['token']) ? sanitize_text_field(stripslashes($pollinations_settings['token'])) : '';
        }

        // Update Volcengine settings if submitted
        if (isset($_POST['volcengine'])) {
            $volcengine_settings = (array) $_POST['volcengine'];
            $settings['volcengine']['api_key'] = isset($volcengine_settings['api_key']) ? sanitize_text_field(stripslashes($volcengine_settings['api_key'])) : '';
            $settings['volcengine']['model'] = isset($volcengine_settings['model']) ? sanitize_text_field($volcengine_settings['model']) : 'doubao-seedream-5-0-260128';
        }

        // Update Custom API settings if submitted
        if (isset($_POST['custom'])) {
            $custom_settings = (array) $_POST['custom'];
            $settings['custom']['base_url'] = isset($custom_settings['base_url']) ? esc_url_raw($custom_settings['base_url']) : '';
            $settings['custom']['api_key'] = isset($custom_settings['api_key']) ? sanitize_text_field(stripslashes($custom_settings['api_key'])) : '';
            $settings['custom']['model'] = isset($custom_settings['model']) ? sanitize_text_field($custom_settings['model']) : 'dall-e-3';
        }

        update_option(self::$option_name, $settings);

        echo '<div class="notice notice-success yali-notice yali-notice-success"><p>' . __('设置已保存', 'yali-ai-writer') . '</p></div>';
    }

    /**
     * AJAX Handler for saving settings (Premium UX - No Reload)
     */
    public static function save_settings_ajax() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cam_save_image_api_settings')) {
            wp_send_json_error(['message' => 'Nonce verification failed.']);
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        // Reuse the logic from handle_form_submission but adapted for AJAX data structure
        // Note: jQuery.serialize() sends data in the same format as standard POST
        
        $settings = self::get_settings();

        // Update active provider
        $settings['provider'] = isset($_POST['cam_image_api_provider']) ? sanitize_text_field($_POST['cam_image_api_provider']) : '';

        // Update ModelScope
        if (isset($_POST['modelscope'])) {
            $ms = $_POST['modelscope']; // Already an array from serialize
            $settings['modelscope']['model_id'] = isset($ms['model_id']) ? sanitize_text_field($ms['model_id']) : '';
            $settings['modelscope']['api_key'] = isset($ms['api_key']) ? sanitize_text_field(stripslashes($ms['api_key'])) : '';
        }

        // Update OpenAI
        if (isset($_POST['openai'])) {
            $oa = $_POST['openai'];
            $settings['openai']['api_key'] = isset($oa['api_key']) ? sanitize_text_field(stripslashes($oa['api_key'])) : '';
            $settings['openai']['model'] = isset($oa['model']) ? sanitize_text_field($oa['model']) : 'gpt-image-1';
        }

        // Update Silicon Flow
        if (isset($_POST['siliconflow'])) {
            $sf = $_POST['siliconflow'];
            $settings['siliconflow']['api_key'] = isset($sf['api_key']) ? sanitize_text_field(stripslashes($sf['api_key'])) : '';
            $settings['siliconflow']['model'] = isset($sf['model']) ? sanitize_text_field($sf['model']) : 'Qwen/Qwen-Image';
        }

        // Update Pollinations
        if (isset($_POST['pollinations'])) {
            $pl = $_POST['pollinations'];
            $settings['pollinations']['model'] = isset($pl['model']) ? sanitize_text_field($pl['model']) : 'flux';
            $settings['pollinations']['token'] = isset($pl['token']) ? sanitize_text_field(stripslashes($pl['token'])) : '';
        }

        // Update Volcengine
        if (isset($_POST['volcengine'])) {
            $ve = $_POST['volcengine'];
            $settings['volcengine']['api_key'] = isset($ve['api_key']) ? sanitize_text_field(stripslashes($ve['api_key'])) : '';
            $settings['volcengine']['model'] = isset($ve['model']) ? sanitize_text_field($ve['model']) : 'doubao-seedream-5-0-260128';
        }

        // Update Custom API
        if (isset($_POST['custom'])) {
            $cust = $_POST['custom'];
            $settings['custom']['base_url'] = isset($cust['base_url']) ? esc_url_raw($cust['base_url']) : '';
            $settings['custom']['api_key'] = isset($cust['api_key']) ? sanitize_text_field(stripslashes($cust['api_key'])) : '';
            $settings['custom']['model'] = isset($cust['model']) ? sanitize_text_field($cust['model']) : 'dall-e-3';
        }

        update_option(self::$option_name, $settings);

        wp_send_json_success(['message' => __('设置已保存', 'yali-ai-writer')]);
    }

    public static function get_settings() {
        $defaults = [
            'provider' => 'modelscope',
            'modelscope' => [
                'model_id' => '',
                'api_key' => '',
            ],
            'openai' => [
                'api_key' => '',
                'model' => 'gpt-image-1',
            ],
            'siliconflow' => [
                'api_key' => '',
                'model' => 'Qwen/Qwen-Image',
            ],
            'pollinations' => [
                'model' => 'flux',
                'token' => '',
            ],
            'volcengine' => [
                'model' => 'doubao-seedream-5-0-260128',
                'api_key' => '',
            ],
            'custom' => [
                'base_url' => '',
                'model' => 'dall-e-3',
                'api_key' => '',
            ],
        ];
        $settings = get_option(self::$option_name, $defaults);
        // Ensure settings is an array to prevent array_replace_recursive errors
        if (!is_array($settings)) {
            $settings = $defaults;
        }
        // Ensure all keys are present by merging with defaults
        return array_replace_recursive($defaults, $settings);
    }
}

CAM_Image_API_Admin_Page::init();


