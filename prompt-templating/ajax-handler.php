<?php
/**
 * Prompt Templates AJAX Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

// 注册AJAX动作
add_action('wp_ajax_content_auto_get_templates', 'content_auto_get_templates');
add_action('wp_ajax_content_auto_save_template', 'content_auto_save_template');
add_action('wp_ajax_content_auto_delete_template', 'content_auto_delete_template');

function content_auto_get_templates() {
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
    }
    
    $type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : null;
    
    $manager = new ContentAuto_TemplateManager();
    $templates = $manager->get_templates($type);
    
    wp_send_json_success($templates);
}

function content_auto_save_template() {
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
    }
    
    // 验证nonce
    if (!check_ajax_referer('content_auto_template_nonce', 'security', false)) {
        wp_send_json_error('Security check failed');
    }
    
    $data = $_POST;
    
    // 简单的反斜杠处理 (wp_unslash)
    if (isset($data['content'])) {
        $data['content'] = wp_unslash($data['content']);
    }
    
    $manager = new ContentAuto_TemplateManager();
    $result = $manager->save_template($data);
    
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    } else {
        wp_send_json_success(array('id' => $result, 'message' => 'Template saved successfully'));
    }
}

function content_auto_delete_template() {
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
    }
    
    // 验证nonce
    if (!check_ajax_referer('content_auto_template_nonce', 'security', false)) {
        wp_send_json_error('Security check failed');
    }
    
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    
    if (!$id) {
        wp_send_json_error('Invalid ID');
    }
    
    $manager = new ContentAuto_TemplateManager();
    $result = $manager->delete_template($id);
    
    if ($result === false) {
        wp_send_json_error('Failed to delete template');
    } else {
        wp_send_json_success('Template deleted successfully');
    }
}
