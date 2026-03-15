<?php
namespace ContentAutoManager\RestApi\Controllers;

/**
 * Controller for Editor Assistant API
 */
class Editor_Assistant_Controller extends Base_Controller {

    public function register_routes() {
        // 获取提示词列表
        register_rest_route($this->namespace, '/editor-assistant/prompts', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_prompts'),
            'permission_callback' => array($this, 'check_admin_permission'),
        ));

        // 生成内容
        register_rest_route($this->namespace, '/editor-assistant/generate', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'generate_content'),
            'permission_callback' => array($this, 'check_admin_permission'),
        ));

        // 检查功能是否启用
        register_rest_route($this->namespace, '/editor-assistant/check-enabled', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'check_enabled'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * 权限检查（重写 Base_Controller，使用 WordPress 编辑权限）
     */
    public function check_admin_permission($request) {
        return current_user_can('edit_posts');
    }

    /**
     * 获取提示词列表
     */
    public function get_prompts($request) {
        if (!class_exists('\ContentAuto_Editor_Prompt_Manager')) {
            if (defined('CONTENT_AUTO_MANAGER_PLUGIN_DIR')) {
                require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'editor-assistant/class-prompt-manager.php';
            }
        }
        // 返回分组结构（与原插件 aich_ajax.prompts 一致）和展平列表
        return rest_ensure_response(array(
            'success'     => true,
            'prompts'     => \ContentAuto_Editor_Prompt_Manager::get_prompts(),      // grouped
            'all_prompts' => \ContentAuto_Editor_Prompt_Manager::get_flat_prompts(), // flat for index lookup
        ));
    }

    /**
     * 检查编辑器助手功能是否启用
     */
    public function check_enabled($request) {
        global $wpdb;
        
        // 检查发布规则表是否存在
        $table_name = $wpdb->prefix . 'content_auto_publish_rules';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
        
        if (!$table_exists) {
            return rest_ensure_response(array(
                'enabled' => false,
                'reason' => '发布规则表不存在'
            ));
        }
        
        // 获取发布规则
        $publish_rule = $wpdb->get_row("SELECT * FROM $table_name LIMIT 1", ARRAY_A);
        
        if (!$publish_rule) {
            return rest_ensure_response(array(
                'enabled' => false,
                'reason' => '未配置发布规则'
            ));
        }
        
        // 检查是否启用编辑器助手
        $enabled = isset($publish_rule['enable_editor_assistant']) ? (bool) $publish_rule['enable_editor_assistant'] : false;
        
        return rest_ensure_response(array(
            'enabled' => $enabled,
            'reason' => $enabled ? '功能已启用' : '编辑器助手未启用'
        ));
    }

    /**
     * 生成内容
     */
    public function generate_content($request) {
        $params = $request->get_json_params();
        
        // 验证参数
        // 注意: 使用 isset 而非 empty，因为 empty(0) 为 true，会导致索引0的提示词无法使用
        if (!isset($params['promptIndex']) && empty($params['prompt'])) {
            return new \WP_Error('missing_params', '缺少必需参数: promptIndex 或 prompt', array('status' => 400));
        }
        
        if (empty($params['text'])) {
            return new \WP_Error('missing_text', '缺少必需参数: text', array('status' => 400));
        }
        
        // 检查功能是否启用
        $check_enabled = $this->check_enabled($request);
        $enabled_data = $check_enabled->get_data();
        
        if (!$enabled_data['enabled']) {
            return new \WP_Error('feature_disabled', '编辑器助手功能未启用，请在发布规则中开启', array('status' => 403));
        }
        
        // 获取提示词
        if (!class_exists('\ContentAuto_Editor_Prompt_Manager')) {
            if (defined('CONTENT_AUTO_MANAGER_PLUGIN_DIR')) {
                require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'editor-assistant/class-prompt-manager.php';
            }
        }

        if (isset($params['promptIndex'])) {
            // 使用 isset 而非 empty，确保索引 0（第一个提示词）也能正确取值
            $prompt = \ContentAuto_Editor_Prompt_Manager::get_prompt_by_index(intval($params['promptIndex']));
        } else {
            $prompt = $params['prompt'];
        }
        
        if (!$prompt) {
            return new \WP_Error('invalid_prompt', '无效的提示词', array('status' => 400));
        }
        
        // 准备提示词（替换占位符）
        $selected_text = is_array($params['text']) ? $params['text'] : array($params['text']);
        $filtered_prompt = $this->prepare_prompt($prompt['prompt_content'], $selected_text);
        
        // 隐式追加严格语言指令，强制输出语言与原文一致（修复原版插件英文回复缺陷）
        $filtered_prompt .= "\n\nCRITICAL INSTRUCTION: You MUST generate your response in the EXACT same language as the provided [[text_1]] content. For example, if the content is in Simplified Chinese, your entire response MUST be in Simplified Chinese. Do NOT respond in English unless the content is in English.";
        
        // 调用统一 API 处理器生成内容
        if (!class_exists('\ContentAuto_UnifiedApiHandler')) {
            if (defined('CONTENT_AUTO_MANAGER_PLUGIN_DIR')) {
                require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-unified-api-handler.php';
            }
        }
        
        $unified_api_handler = new \ContentAuto_UnifiedApiHandler();
        
        try {
            $result = $unified_api_handler->generate_content(
                $filtered_prompt,
                'editor_assistant',
                array(
                    'return_usage' => true,
                    'timeout' => 120, // 编辑器请求超时时间较短
                )
            );
            
            if (isset($result['error'])) {
                return new \WP_Error('api_error', $result['error'], array('status' => 500));
            }
            
            // 处理返回结果
            if (is_array($result) && isset($result['content'])) {
                $content = $result['content'];
                $usage = isset($result['usage']) ? $result['usage'] : array();
            } else {
                $content = $result;
                $usage = array();
            }
            
            return rest_ensure_response(array(
                'success' => true,
                'text' => $content,
                'tokens' => isset($prompt['word_count']) ? $prompt['word_count'] : 0,
                'usage' => $usage,
            ));
            
        } catch (\Exception $e) {
            return new \WP_Error('generation_error', $e->getMessage(), array('status' => 500));
        }
    }

    /**
     * 准备提示词（替换占位符）
     */
    private function prepare_prompt($prompt, $selected_text) {
        return preg_replace_callback('/\[\[text_(\d+)\]\]/', function ($matches) use ($selected_text) {
            $index = $matches[1] - 1;
            return isset($selected_text[$index]) ? $selected_text[$index] : '';
        }, $prompt);
    }
}