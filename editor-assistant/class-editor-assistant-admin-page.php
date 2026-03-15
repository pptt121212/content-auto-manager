<?php
/**
 * Admin page for managing Editor Assistant settings and prompts.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_EditorAssistantAdminPage {

    public function __construct() {
        add_action('wp_ajax_get_editor_assistant_prompts', [$this, 'ajax_get_prompts']);
        add_action('wp_ajax_save_editor_assistant_prompts', [$this, 'ajax_save_prompts']);
    }

    public function render_page() {
        // Localized data for JS
        $localized_data = [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('editor_assistant_settings_nonce')
        ];

        $tokens_path = CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/assets/css/brand-tokens.css';
        $base_kit_path = CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/assets/css/yali-ui-kit.css';
        $tokens_css = file_exists($tokens_path) ? file_get_contents($tokens_path) : '';
        $base_kit_css = file_exists($base_kit_path) ? file_get_contents($base_kit_path) : '';

        // Additional inline CSS for this specific page if needed
        $style_css_path = CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'editor-assistant/assets/css/editor-assistant-settings.css';
        $style_css = file_exists($style_css_path) ? file_get_contents($style_css_path) : '';

        ?>
        <div class="wrap yali-plugin-wrapper">
            <style type="text/css">
                <?php echo $tokens_css; ?>
                <?php echo $base_kit_css; ?>
                <?php echo $style_css; ?>
                
                /* Custom styles for the Editor Assistant configuration */
                .ea-prompt-card {
                    margin-bottom: 20px;
                    border: 1px solid #e2e8f0;
                    border-radius: 8px;
                    padding: 20px;
                    background: #fff;
                    transition: all 0.2s ease;
                }
                .ea-prompt-card:hover {
                    border-color: #cbd5e1;
                    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
                }
                .ea-prompt-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 15px;
                    padding-bottom: 10px;
                    border-bottom: 1px solid #f1f5f9;
                }
                .ea-prompt-title {
                    font-size: 16px;
                    font-weight: 600;
                    color: #1e293b;
                    margin: 0;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                }
                .ea-field-group {
                    margin-bottom: 15px;
                }
                .ea-field-group label {
                    display: block;
                    font-weight: 500;
                    margin-bottom: 6px;
                    color: #475569;
                    font-size: 13px;
                }
                .ea-field-group input, 
                .ea-field-group textarea {
                    width: 100%;
                    border: 1px solid #cbd5e1;
                    border-radius: 6px;
                    padding: 8px 12px;
                    font-size: 14px;
                }
                .ea-field-group textarea {
                    min-height: 80px;
                    font-family: inherit;
                    line-height: 1.5;
                }
                .ea-field-group input:focus, 
                .ea-field-group textarea:focus {
                    border-color: #3b82f6;
                    box-shadow: 0 0 0 1px #3b82f6;
                    outline: none;
                }
                .ea-number-input {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }
                .ea-number-input input {
                    width: 120px;
                }
                .ea-tabs-header {
                    display: flex;
                    gap: 10px;
                    border-bottom: 2px solid #e2e8f0;
                    margin-bottom: 20px;
                }
                .ea-tab {
                    padding: 10px 20px;
                    font-weight: 500;
                    color: #64748b;
                    cursor: pointer;
                    margin-bottom: -2px;
                    border-bottom: 2px solid transparent;
                    transition: all 0.2s;
                }
                .ea-tab:hover {
                    color: #3b82f6;
                }
                .ea-tab.active {
                    color: #3b82f6;
                    border-bottom-color: #3b82f6;
                }
                .ea-sticky-footer {
                    position: sticky;
                    bottom: 0;
                    background: #fff;
                    padding: 15px 20px;
                    border-top: 1px solid #e2e8f0;
                    box-shadow: 0 -4px 6px -1px rgba(0, 0, 0, 0.05);
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    z-index: 10;
                    margin-top: 40px;
                    border-radius: 0 0 8px 8px;
                }
            </style>
            
            <?php 
            include CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'editor-assistant/views/editor-assistant-settings.php'; 
            ?>

            <script type="text/javascript">
                window.editorAssistantSettings = <?php echo json_encode($localized_data); ?>;
            </script>
        </div>
        <?php
    }

    public function ajax_get_prompts() {
        check_ajax_referer('editor_assistant_settings_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('您没有权限执行此操作。', 'yali-ai-writer')]);
            return;
        }

        if (!class_exists('ContentAuto_Editor_Prompt_Manager')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'editor-assistant/class-prompt-manager.php';
        }
        
        // We need both the custom prompts from DB and the default ones to allow resetting
        $default_prompts_obj = array(
            'en' => ContentAuto_Editor_Prompt_Manager::get_default_prompts()
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
}
