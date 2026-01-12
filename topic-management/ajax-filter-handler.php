<?php
/**
 * 主题高级筛选AJAX处理器
 */

if (!defined('ABSPATH')) {
    exit;
}

// 注册AJAX动作
add_action('wp_ajax_cam_filter_topics', 'cam_ajax_filter_topics');
add_action('wp_ajax_cam_detect_duplicates', 'cam_ajax_detect_duplicates');
add_action('wp_ajax_cam_bulk_delete_topics', 'cam_ajax_bulk_delete_topics');
add_action('wp_ajax_cam_delete_duplicate_topics', 'cam_ajax_delete_duplicate_topics');
add_action('wp_ajax_cam_get_filter_stats', 'cam_ajax_get_filter_stats');
add_action('wp_ajax_cam_delete_all_filtered_topics', 'cam_ajax_delete_all_filtered_topics');

/**
 * 筛选主题
 */
function cam_ajax_filter_topics() {
    // 验证权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => '权限不足'));
    }
    
    // 验证nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cam_topic_filter')) {
        wp_send_json_error(array('message' => '安全验证失败'));
    }
    
    // 获取筛选参数
    $filters = array(
        'status' => isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'unused',
        'title_keyword' => isset($_POST['title_keyword']) ? sanitize_text_field($_POST['title_keyword']) : '',
        'matched_category' => isset($_POST['matched_category']) ? sanitize_text_field($_POST['matched_category']) : '',
        'priority_score' => isset($_POST['priority_score']) ? sanitize_text_field($_POST['priority_score']) : '',
        'has_vector' => isset($_POST['has_vector']) ? sanitize_text_field($_POST['has_vector']) : '',
        'has_reference' => isset($_POST['has_reference']) ? sanitize_text_field($_POST['has_reference']) : '',
        'task_id' => isset($_POST['task_id']) ? sanitize_text_field($_POST['task_id']) : ''
    );
    
    $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
    $per_page = isset($_POST['per_page']) ? max(10, min(100, intval($_POST['per_page']))) : 20;
    
    // 确保服务类已加载
    if (!class_exists('ContentAuto_TopicFilterService')) {
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'topic-management/class-topic-filter-service.php';
    }
    
    $filter_service = new ContentAuto_TopicFilterService();
    $result = $filter_service->get_filtered_topics($filters, $page, $per_page);
    
    // 添加额外信息
    foreach ($result['topics'] as &$topic) {
        $topic['has_vector'] = !empty($topic['vector_embedding']);
        $topic['has_reference'] = !empty($topic['reference_material']);
        $topic['status_label'] = content_auto_manager_get_topic_status_label($topic['status']);
        
        // 移除大字段减少传输
        unset($topic['vector_embedding']);
        unset($topic['reference_material']);
    }
    
    wp_send_json_success($result);
}

/**
 * 检测重复标题
 */
function cam_ajax_detect_duplicates() {
    // 验证权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => '权限不足'));
    }
    
    // 验证nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cam_topic_filter')) {
        wp_send_json_error(array('message' => '安全验证失败'));
    }
    
    $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'unused';
    $threshold = isset($_POST['threshold']) ? floatval($_POST['threshold']) : 0.90;
    
    // 确保阈值在有效范围内 (0.1 到 1.0)
    $threshold = max(0.1, min(1.0, $threshold));
    
    // 确保服务类已加载
    if (!class_exists('ContentAuto_TopicFilterService')) {
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'topic-management/class-topic-filter-service.php';
    }
    
    $filter_service = new ContentAuto_TopicFilterService();
    
    try {
        $result = $filter_service->detect_duplicate_topics($status, $threshold);
        wp_send_json_success($result);
    } catch (Exception $e) {
        wp_send_json_error(array('message' => '检测重复标题时出错: ' . $e->getMessage()));
    }
}

/**
 * 批量删除主题
 */
function cam_ajax_bulk_delete_topics() {
    // 验证权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => '权限不足'));
    }
    
    // 验证nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cam_topic_filter')) {
        wp_send_json_error(array('message' => '安全验证失败'));
    }
    
    $topic_ids = isset($_POST['topic_ids']) ? array_map('intval', (array)$_POST['topic_ids']) : array();
    
    if (empty($topic_ids)) {
        wp_send_json_error(array('message' => '请选择要删除的主题'));
    }
    
    // 确保服务类已加载
    if (!class_exists('ContentAuto_TopicFilterService')) {
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'topic-management/class-topic-filter-service.php';
    }
    
    $filter_service = new ContentAuto_TopicFilterService();
    $result = $filter_service->bulk_delete_topics($topic_ids);
    
    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}

/**
 * 删除重复主题
 */
function cam_ajax_delete_duplicate_topics() {
    // 验证权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => '权限不足'));
    }
    
    // 验证nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cam_topic_filter')) {
        wp_send_json_error(array('message' => '安全验证失败'));
    }
    
    $duplicate_type = isset($_POST['duplicate_type']) ? sanitize_text_field($_POST['duplicate_type']) : 'all';
    $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'unused';
    
    // 验证类型
    if (!in_array($duplicate_type, array('exact', 'similar', 'all'))) {
        wp_send_json_error(array('message' => '无效的重复类型'));
    }
    
    // 确保服务类已加载
    if (!class_exists('ContentAuto_TopicFilterService')) {
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'topic-management/class-topic-filter-service.php';
    }
    
    $filter_service = new ContentAuto_TopicFilterService();
    $result = $filter_service->delete_duplicate_topics($duplicate_type, $status);
    
    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}

/**
 * 获取筛选统计信息
 */
function cam_ajax_get_filter_stats() {
    // 验证权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => '权限不足'));
    }
    
    // 验证nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cam_topic_filter')) {
        wp_send_json_error(array('message' => '安全验证失败'));
    }
    
    // 确保服务类已加载
    if (!class_exists('ContentAuto_TopicFilterService')) {
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'topic-management/class-topic-filter-service.php';
    }
    
    $filter_service = new ContentAuto_TopicFilterService();
    $stats = $filter_service->get_filter_stats();
    $categories = $filter_service->get_available_categories();
    
    wp_send_json_success(array(
        'stats' => $stats,
        'categories' => $categories
    ));
}

/**
 * 删除所有符合筛选条件的主题
 */
function cam_ajax_delete_all_filtered_topics() {
    // 验证权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => '权限不足'));
    }
    
    // 验证nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cam_topic_filter')) {
        wp_send_json_error(array('message' => '安全验证失败'));
    }
    
    // 获取筛选参数
    $filters = array(
        'status' => isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'unused',
        'title_keyword' => isset($_POST['title_keyword']) ? sanitize_text_field($_POST['title_keyword']) : '',
        'matched_category' => isset($_POST['matched_category']) ? sanitize_text_field($_POST['matched_category']) : '',
        'priority_score' => isset($_POST['priority_score']) ? sanitize_text_field($_POST['priority_score']) : '',
        'has_vector' => isset($_POST['has_vector']) ? sanitize_text_field($_POST['has_vector']) : '',
        'has_reference' => isset($_POST['has_reference']) ? sanitize_text_field($_POST['has_reference']) : '',
        'task_id' => isset($_POST['task_id']) ? sanitize_text_field($_POST['task_id']) : ''
    );
    
    // 安全检查：只允许删除"未使用"状态的主题
    if ($filters['status'] !== 'unused') {
        wp_send_json_error(array('message' => '只能批量删除"未使用"状态的主题'));
    }
    
    // 确保服务类已加载
    if (!class_exists('ContentAuto_TopicFilterService')) {
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'topic-management/class-topic-filter-service.php';
    }
    
    $filter_service = new ContentAuto_TopicFilterService();
    $result = $filter_service->delete_all_filtered_topics($filters);
    
    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}
