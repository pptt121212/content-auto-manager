<?php
/**
 * Admin page for managing Editor Assistant settings and prompts.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Yali_AI_Writer_EditorAssistantAdminPage {

    public function __construct() {
        add_action('wp_ajax_yali_ai_writer_get_editor_assistant_prompts', [$this, 'ajax_get_prompts']);
        add_action('wp_ajax_yali_ai_writer_save_editor_assistant_prompts', [$this, 'ajax_save_prompts']);
        add_action('wp_ajax_yali_ai_writer_get_image_prompts_config', [$this, 'ajax_get_image_prompts_config']);
        add_action('wp_ajax_yali_ai_writer_save_image_prompts_config', [$this, 'ajax_save_image_prompts_config']);
    }

    public function render_page() {
        ?>
        <div class="wrap yali-plugin-wrapper">
            <?php 
            include YALI_AI_WRITER_PLUGIN_DIR . 'editor-assistant/views/editor-assistant-settings.php'; 
            ?>
        </div>
        <?php
    }

    public function ajax_get_prompts() {
        check_ajax_referer('editor_assistant_settings_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('您没有权限执行此操作。', 'yali-ai-writer')]);
            return;
        }

        if (!class_exists('Yali_AI_Writer_Editor_Prompt_Manager')) {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'editor-assistant/class-prompt-manager.php';
        }
        
        // We need both the custom prompts from DB and the default ones to allow resetting
        $default_prompts_obj = array(
            'en' => Yali_AI_Writer_Editor_Prompt_Manager::get_default_prompts()
        );
        
        $saved_prompts = get_option('yali_editor_assistant_prompts', []);
        
        // 动态翻译数据库中的默认文本
        if (!empty($saved_prompts) && is_array($saved_prompts)) {
            foreach ($saved_prompts as $lang => &$prompts_array) {
                if (is_array($prompts_array)) {
                    foreach ($prompts_array as &$prompt) {
                        if (isset($prompt['prompt_title'])) {
                            $prompt['prompt_title'] = __($prompt['prompt_title'], 'yali-ai-writer');
                        }
                        if (isset($prompt['prompt_desc'])) {
                            $prompt['prompt_desc'] = __($prompt['prompt_desc'], 'yali-ai-writer');
                        }
                    }
                    unset($prompt);
                }
            }
            unset($prompts_array);
        }

        // To make it easier for Vue/JS to render, we'll send it structured by language
        wp_send_json_success([
            'default_prompts' => $default_prompts_obj,
            'saved_prompts' => !empty($saved_prompts) ? $saved_prompts : $default_prompts_obj,
        ]);
    }

    public function ajax_save_prompts() {
        check_ajax_referer('editor_assistant_settings_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('您没有权限执行此操作。', 'yali-ai-writer')]);
            return;
        }

        if (!isset($_POST['prompts'])) {
            wp_send_json_error(['message' => __('未提供提示词数据', 'yali-ai-writer')]);
            return;
        }

        $prompts_json = stripslashes($_POST['prompts']);
        $prompts_data = json_decode($prompts_json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => __('无效的JSON数据格式', 'yali-ai-writer')]);
            return;
        }

        update_option('yali_editor_assistant_prompts', $prompts_data);

        wp_send_json_success(['message' => __('设置已成功保存！', 'yali-ai-writer')]);
    }

    /**
     * 获取图像提示词配置
     */
    public function ajax_get_image_prompts_config() {
        check_ajax_referer('editor_assistant_settings_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('您没有权限执行此操作。', 'yali-ai-writer')]);
            return;
        }

        // 从JSON文件读取配置
        $config_file = YALI_AI_WRITER_PLUGIN_DIR . 'editor-assistant/config/image-prompts.json';
        $default_config = file_exists($config_file) ? file_get_contents($config_file) : '[]';
        
        // 如果数据库中有保存的配置，则使用数据库中的
        $saved_config = get_option('yali_image_prompts_config', '');
        $config = !empty($saved_config) ? $saved_config : $default_config;
        
        // 解析JSON
        $config_array = json_decode($config, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $config_array = json_decode($default_config, true) ?: [];
        }

        wp_send_json_success([
            'config' => $config_array
        ]);
    }

    /**
     * 保存图像提示词配置
     */
    public function ajax_save_image_prompts_config() {
        check_ajax_referer('editor_assistant_settings_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('您没有权限执行此操作。', 'yali-ai-writer')]);
            return;
        }

        if (!isset($_POST['config'])) {
            wp_send_json_error(['message' => __('未提供配置数据', 'yali-ai-writer')]);
            return;
        }

        $config_json = stripslashes($_POST['config']);
        
        // 验证JSON格式
        $config_array = json_decode($config_json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => __('无效的JSON格式: ', 'yali-ai-writer') . json_last_error_msg()]);
            return;
        }

        // 保存到数据库
        update_option('yali_image_prompts_config', $config_json);

        wp_send_json_success(['message' => __('图像提示词配置已保存！', 'yali-ai-writer')]);
    }
}
