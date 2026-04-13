<?php
/**
 * 鸭梨AI助手古腾堡块资源注册
 * 使用 editor.BlockEdit filter 向所有块注入工具栏按钮
 */

if (!defined('ABSPATH')) {
    exit;
}

function yali_ai_writer_editor_assistant_enqueue_block_editor_assets() {
    $script_path = YALI_AI_WRITER_PLUGIN_DIR . 'editor-assistant/gutenberg/index.js';
    $asset_path  = YALI_AI_WRITER_PLUGIN_DIR . 'editor-assistant/gutenberg/index.asset.php';
    $css_path    = YALI_AI_WRITER_PLUGIN_DIR . 'editor-assistant/gutenberg/index.css';

    if (!file_exists($script_path)) {
        return;
    }

    $asset      = file_exists($asset_path) ? require $asset_path : array('dependencies' => array(), 'version' => '1.0.0');
    $script_url = YALI_AI_WRITER_PLUGIN_URL . 'editor-assistant/gutenberg/index.js';
    $style_url  = YALI_AI_WRITER_PLUGIN_URL . 'editor-assistant/gutenberg/index.css';
    $icon_url   = get_template_directory_uri() . '/assets/images/logo-icon.svg';

    // 注册并入队编辑器工具栏脚本
    wp_register_script(
        'content-auto-editor-assistant-block',
        $script_url,
        $asset['dependencies'],
        $asset['version'],
        true
    );

    // 加载提示词（直接服务端注入）
    if (!class_exists('Yali_AI_Writer_Editor_Prompt_Manager')) {
        if (defined('YALI_AI_WRITER_PLUGIN_DIR') && file_exists(YALI_AI_WRITER_PLUGIN_DIR . 'editor-assistant/class-prompt-manager.php')) {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'editor-assistant/class-prompt-manager.php';
        }
    }

    $prompts = class_exists('Yali_AI_Writer_Editor_Prompt_Manager')
        ? Yali_AI_Writer_Editor_Prompt_Manager::get_prompts()
        : array();

    // 检查功能是否启用
    $enabled = false;
    global $wpdb;
    $table_name  = $wpdb->prefix . 'yali_ai_writer_publish_rules';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
    if ($table_exists) {
        $rule = $wpdb->get_row("SELECT enable_editor_assistant FROM $table_name LIMIT 1", ARRAY_A);
        $enabled = isset($rule['enable_editor_assistant']) ? (bool) $rule['enable_editor_assistant'] : false;
    }

    // 将所有数据注入 JS
    wp_localize_script('content-auto-editor-assistant-block', 'yaliEditorData', array(
        'nonce'      => wp_create_nonce('wp_rest'),
        'enabled'    => $enabled,
        'apiUrl'     => rest_url('content-auto-manager/v1/editor-assistant'),
        'prompts'    => class_exists('Yali_AI_Writer_Editor_Prompt_Manager') ? Yali_AI_Writer_Editor_Prompt_Manager::get_prompts() : array(),
        'allPrompts' => class_exists('Yali_AI_Writer_Editor_Prompt_Manager') ? Yali_AI_Writer_Editor_Prompt_Manager::get_flat_prompts() : array(),
        'iconUrl'    => $icon_url,
    ));

    // 加载 JS 语言包 (JED)
    wp_set_script_translations(
        'content-auto-editor-assistant-block',
        'yali-ai-writer',
        YALI_AI_WRITER_PLUGIN_DIR . 'languages'
    );

    wp_enqueue_script('content-auto-editor-assistant-block');

    // 注册并入队编辑器样式
    if (file_exists($css_path)) {
        wp_register_style(
            'content-auto-editor-assistant-style',
            $style_url,
            array(),
            $asset['version']
        );
        wp_enqueue_style('content-auto-editor-assistant-style');
    }
}
add_action('enqueue_block_editor_assets', 'yali_ai_writer_editor_assistant_enqueue_block_editor_assets');