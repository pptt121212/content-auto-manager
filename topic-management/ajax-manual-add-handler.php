<?php
/**
 * 手工添加主题 - AJAX处理器
 */

if (!defined('ABSPATH')) {
    exit;
}

// 注册AJAX动作
add_action('wp_ajax_cam_manual_add_topics', 'cam_ajax_manual_add_topics');

/**
 * 处理手工添加主题的AJAX请求
 */
function cam_ajax_manual_add_topics() {
    // 验证权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足', 'yali-ai-writer')));
    }
    
    // 验证nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'cam_manual_add_topics')) {
        wp_send_json_error(array('message' => __('安全验证失败', 'yali-ai-writer')));
    }
    
    // 获取表单数据
    $titles = isset($_POST['titles']) ? sanitize_textarea_field($_POST['titles']) : '';
    $reference_material = isset($_POST['reference_material']) ? sanitize_textarea_field($_POST['reference_material']) : '';
    $target_category_id = isset($_POST['target_category_id']) ? intval($_POST['target_category_id']) : 0;
    
    // 验证标题
    if (empty(trim($titles))) {
        wp_send_json_error(array('message' => __('请填写至少一个主题标题', 'yali-ai-writer')));
    }
    
    // 解析分类名称
    $matched_category = '';
    if ($target_category_id > 0) {
        $category_name = get_cat_name($target_category_id);
        if ($category_name) {
            $matched_category = $category_name;
        }
    }
    
    // 验证参考资料长度（最多800字符）
    $reference_truncated = false;
    if (mb_strlen($reference_material) > 800) {
        $reference_material = mb_substr($reference_material, 0, 800);
        $reference_truncated = true;
    }
    
    // 分割主题标题并插入数据库
    $title_array = explode("\n", $titles);
    $database = new Yali_AI_Writer_Database();
    $added_count = 0;
    $skipped_count = 0;
    
    foreach ($title_array as $title) {
        $title = trim($title);
        if (!empty($title)) {
            // 插入主题数据
            $data = array(
                'task_id' => '', // 手工添加的主题task_id为空字符串
                'rule_id' => 0, // 手工添加的主题rule_id为0
                'rule_item_index' => 0, // 手工添加的主题rule_item_index为0
                'title' => $title,
                'source_angle' => '',
                'user_value' => '',
                'seo_keywords' => '',
                'matched_category' => $matched_category,
                'priority_score' => 3,
                'status' => YALI_AI_WRITER_TOPIC_UNUSED,
                'reference_material' => $reference_material
            );
            
            $result = $database->insert('yali_ai_writer_topics', $data);
            if ($result) {
                $added_count++;
            } else {
                $skipped_count++;
            }
        }
    }
    
    // 构建响应消息
    $message = sprintf(__('成功添加 %d 个主题', 'yali-ai-writer'), $added_count);
    if ($skipped_count > 0) {
        $message .= sprintf(__('，%d 个跳过', 'yali-ai-writer'), $skipped_count);
    }
    if ($reference_truncated) {
        $message .= __('（参考资料已截断至800字符）', 'yali-ai-writer');
    }
    
    wp_send_json_success(array(
        'message' => $message,
        'added_count' => $added_count,
        'skipped_count' => $skipped_count,
        'reference_truncated' => $reference_truncated
    ));
}
