<?php
// image-api-settings/class-image-api-admin-page.php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class CAM_Image_API_Admin_Page {

    private static $option_name = 'cam_image_api_settings';



    public static function init() {
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
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
            ['jquery'],
            $version,
            true
        );

        // Localize the script with data for AJAX calls
        wp_localize_script(
            'cam-image-api-settings',
            'contentAutoManager',
            [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('content_auto_manager_nonce'),
            ]
        );
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

        update_option(self::$option_name, $settings);

        echo '<div class="updated"><p>设置已保存。</p></div>';
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


