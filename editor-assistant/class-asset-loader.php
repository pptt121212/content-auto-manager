<?php
/**
 * 前端资源加载器
 * 负责加载古腾堡块和经典编辑器的资源
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_Editor_Asset_Loader {

    public function __construct() {
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_gutenberg_assets'));
    }

    /**
     * 加载后台管理页面资源
     */
    public function enqueue_admin_assets($hook) {
        // 只在文章编辑页面加载
        if (!in_array($hook, array('post.php', 'post-new.php'))) {
            return;
        }

        // 检查是否使用经典编辑器
        if ($this->is_classic_editor()) {
            $this->setup_classic_editor_plugin();
        }
    }

    /**
     * 加载古腾堡编辑器资源
     * 注意：古腾堡脚本现在由 editor-assistant/gutenberg/index.php 中的
     * enqueue_block_editor_assets 钩子直接管理，此方法已不再需要做任何事情。
     */
    public function enqueue_gutenberg_assets() {
        // Gutenberg 脚本由 gutenberg/index.php 独立注册，此处无需操作
    }

    /**
     * 注册经典编辑器 TinyMCE 插件
     * 通过 WordPress mce_external_plugins / mce_buttons 过滤器正确集成
     */
    private function setup_classic_editor_plugin() {
        // 检查功能是否启用
        if (!$this->is_feature_enabled()) {
            return;
        }

        // 检查当前用户是否有编辑权限
        if (!current_user_can('edit_posts') && !current_user_can('edit_pages')) {
            return;
        }

        // 检查是否启用了富文本编辑
        if (get_user_option('rich_editing') !== 'true') {
            return;
        }

        // 通过 WordPress 过滤器注册 TinyMCE 插件（关键步骤）
        add_filter('mce_external_plugins', array($this, 'add_tinymce_plugin'));
        add_filter('mce_buttons', array($this, 'add_tinymce_button'));
        // 将加载动画样式注入 TinyMCE iframe
        add_filter('mce_css', array($this, 'add_tinymce_css'));
        // 通过 admin_print_scripts 注入 JavaScript 数据
        add_action('admin_print_scripts', array($this, 'localize_classic_editor_data'));
    }

    /**
     * 注册 TinyMCE 外部插件路径
     */
    public function add_tinymce_plugin($plugins) {
        $plugins['yali_classic_plugin'] = CONTENT_AUTO_MANAGER_PLUGIN_URL . 'editor-assistant/classic-editor.js';
        return $plugins;
    }

    /**
     * 添加 TinyMCE 工具栏按钮
     */
    public function add_tinymce_button($buttons) {
        array_push($buttons, '|', 'yali_classic_plugin');
        return $buttons;
    }

    /**
     * 为 TinyMCE 注入必要的样式
     */
    public function add_tinymce_css($mce_css) {
        $tokens_url = CONTENT_AUTO_MANAGER_PLUGIN_URL . 'shared/assets/css/brand-tokens.css';
        $style_url = CONTENT_AUTO_MANAGER_PLUGIN_URL . 'dashboard/assets/css/enhanced-dashboard.css';
        
        if (!empty($mce_css)) {
            $mce_css .= ',';
        }
        $mce_css .= $tokens_url . ',' . $style_url;
        return $mce_css;
    }

    /**
     * 将 JS 数据（含提示词）直接注入页面，避免异步请求时序问题
     */
    public function localize_classic_editor_data() {
        if (!class_exists('ContentAuto_Editor_Prompt_Manager')) {
            if (defined('CONTENT_AUTO_MANAGER_PLUGIN_DIR')) {
                require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'editor-assistant/class-prompt-manager.php';
            }
        }

        $icon_url = CONTENT_AUTO_MANAGER_PLUGIN_URL . 'dashboard/assets/images/yali-logo-icon.svg';

        $data = array(
            'nonce'   => wp_create_nonce('wp_rest'),
            'enabled' => true,
            'apiUrl'  => rest_url('content-auto-manager/v1/editor-assistant'),
            'iconUrl' => $icon_url,
            // 分组格式的提示词数据
            'prompts'    => class_exists('ContentAuto_Editor_Prompt_Manager') ? ContentAuto_Editor_Prompt_Manager::get_prompts() : array(),
            'allPrompts' => class_exists('ContentAuto_Editor_Prompt_Manager') ? ContentAuto_Editor_Prompt_Manager::get_flat_prompts() : array(),
            // 经典编辑器特定的翻译字符串
            'i18n'       => array(
                'no_selection'         => __('请先在编辑器中选中要处理的文字。', 'yali-ai-writer'),
                'generate_failed'      => __('生成失败，请检查 API 设置', 'yali-ai-writer'),
                'button_title'         => __('鸭梨AI助手', 'yali-ai-writer'),
                'button_tooltip'       => __('鸭梨AI助手 — AI 写作辅助', 'yali-ai-writer'),
                'generate_failed_short'=> __('生成失败', 'yali-ai-writer'),
                'network_error'        => __('网络错误', 'yali-ai-writer'),
                'alert_prefix'         => __('鸭梨AI助手: ', 'yali-ai-writer'),
            )
        );

        echo '<script>var contentAutoEditorData = ' . wp_json_encode($data) . ';</script>' . "\n";
    }

    /**
     * 检查是否使用经典编辑器
     */
    private function is_classic_editor() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) {
            return false;
        }

        // 核心判断：是否为文章编辑页面且不是块编辑器
        if (in_array($screen->base, array('post', 'add')) && isset($screen->is_block_editor) && !$screen->is_block_editor) {
            return true;
        }

        // 备选方案：通过 URL 参数判断
        if (isset($_GET['classic-editor'])) {
            return true;
        }

        // 保留原有的插件配置判断作为补充
        if (class_exists('Classic_Editor')) {
            $replace = get_option('classic-editor-replace', 'no-replace');
            if ($replace === 'replace') return true;
            if (get_user_meta(get_current_user_id(), 'classic-editor-replace', true) === 'replace') return true;
        }

        return false;
    }

    /**
     * 检查功能是否启用
     */
    private function is_feature_enabled() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'content_auto_publish_rules';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");

        if (!$table_exists) {
            return false;
        }

        $publish_rule = $wpdb->get_row("SELECT enable_editor_assistant FROM $table_name LIMIT 1", ARRAY_A);

        if (!$publish_rule) {
            return false;
        }

        return isset($publish_rule['enable_editor_assistant']) ? (bool) $publish_rule['enable_editor_assistant'] : false;
    }
}

// 资源加载器将在 content_auto_manager_init 函数中统一初始化