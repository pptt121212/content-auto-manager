<?php
/**
 * Prompt Templates AJAX Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

// 注册AJAX动作
add_action('wp_ajax_yali_ai_writer_get_templates', 'yali_ai_writer_get_templates');
add_action('wp_ajax_yali_ai_writer_save_template', 'yali_ai_writer_save_template');
add_action('wp_ajax_yali_ai_writer_delete_template', 'yali_ai_writer_delete_template');

function yali_ai_writer_get_templates() {
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
    }
    
    // 验证 nonce (使用与 save/delete 相同的 nonce)
    if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'yali_ai_writer_template_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    $type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : null;
    
    $manager = new Yali_AI_Writer_TemplateManager();
    $templates = $manager->get_templates($type);
    
    wp_send_json_success($templates);
}

function yali_ai_writer_save_template() {
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
    }
    
    // 验证nonce
    if (!check_ajax_referer('yali_ai_writer_template_nonce', 'security', false)) {
        wp_send_json_error('Security check failed');
    }
    
    $data = $_POST;
    
    // 简单的反斜杠处理 (wp_unslash)
    if (isset($data['content'])) {
        $data['content'] = wp_unslash($data['content']);
    }
    
    $manager = new Yali_AI_Writer_TemplateManager();
    $result = $manager->save_template($data);
    
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    } else {
        wp_send_json_success(array('id' => $result, 'message' => 'Template saved successfully'));
    }
}

function yali_ai_writer_delete_template() {
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
    }
    
    // 验证nonce
    if (!check_ajax_referer('yali_ai_writer_template_nonce', 'security', false)) {
        wp_send_json_error('Security check failed');
    }
    
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    
    if (!$id) {
        wp_send_json_error('Invalid ID');
    }
    
    $manager = new Yali_AI_Writer_TemplateManager();
    $result = $manager->delete_template($id);
    
    if ($result === false) {
        wp_send_json_error('Failed to delete template');
    } else {
        wp_send_json_success('Template deleted successfully');
    }
}
