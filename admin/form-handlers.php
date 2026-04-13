<?php
/**
 * 主题管理表单处理
 * 在 admin_init 钩子中处理表单提交，确保在使用 wp_safe_redirect() 之前没有输出
 */

if (!defined('ABSPATH')) {
    exit;
}

// 加载必要的类文件
require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/services/class-vector-generator.php';
require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/database/class-database.php';

/**
 * 处理主题任务创建表单
 */
add_action('admin_init', 'yali_ai_writer_handle_topic_jobs_form');
function yali_ai_writer_handle_topic_jobs_form() {
    // 检查是否在正确的页面
    if (!isset($_GET['page']) || sanitize_text_field(wp_unslash($_GET['page'])) !== 'yali-ai-writer-topic-jobs') {
        return;
    }

    // 检查是否是 POST 请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['submit'])) {
        return;
    }

    // 验证 nonce
    if (!isset($_POST['yali_ai_writer_manager_nonce']) || 
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['yali_ai_writer_manager_nonce'])), 'yali_ai_writer_manager_topic_jobs')) {
        wp_die(__('安全验证失败。', 'yali-ai-writer'));
    }

    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_die(__('抱歉，您没有权限执行此操作。', 'yali-ai-writer'));
    }

    // 获取表单数据
    $rule_id = isset($_POST['rule_id']) ? intval(wp_unslash($_POST['rule_id'])) : 0;
    $topic_count = isset($_POST['topic_count']) ? intval(wp_unslash($_POST['topic_count'])) : 0;

    // 验证数据
    if (empty($rule_id) || empty($topic_count) || $topic_count <= 0) {
        $redirect_url = add_query_arg(array(
            'page' => 'yali-ai-writer-topic-jobs',
            'yali_notice' => 'validation_error'
        ), admin_url('admin.php'));
        wp_safe_redirect($redirect_url);
        exit;
    }

    // 创建主题生成任务
    require_once YALI_AI_WRITER_PLUGIN_DIR . 'topic-management/class-topic-task-manager.php';
    $topic_task_manager = new Yali_AI_Writer_TopicTaskManager();
    $task_id = $topic_task_manager->create_topic_task($rule_id, $topic_count);

    if ($task_id) {
        $redirect_url = add_query_arg(array(
            'page' => 'yali-ai-writer-topic-jobs',
            'yali_notice' => 'topic_task_created'
        ), admin_url('admin.php'));
    } else {
        $redirect_url = add_query_arg(array(
            'page' => 'yali-ai-writer-topic-jobs',
            'yali_notice' => 'topic_task_failed'
        ), admin_url('admin.php'));
    }

    wp_safe_redirect($redirect_url);
    exit;
}

/**
 * 处理文章生成表单
 */
add_action('admin_init', 'yali_ai_writer_handle_topics_list_form');
function yali_ai_writer_handle_topics_list_form() {
    // 检查是否在正确的页面
    if (!isset($_GET['page']) || sanitize_text_field(wp_unslash($_GET['page'])) !== 'yali-ai-writer-topics') {
        return;
    }

    // 检查是否是 POST 请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['generate_articles'])) {
        return;
    }

    // 验证 nonce
    if (!isset($_POST['yali_ai_writer_manager_nonce']) || 
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['yali_ai_writer_manager_nonce'])), 'yali_ai_writer_manager_generate_articles')) {
        wp_die(__('安全验证失败。', 'yali-ai-writer'));
    }

    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_die(__('抱歉，您没有权限执行此操作。', 'yali-ai-writer'));
    }

    // 消毒 topic_ids 数组
    $topic_ids = isset($_POST['topic_ids']) && is_array($_POST['topic_ids']) 
        ? array_map('intval', wp_unslash($_POST['topic_ids'])) 
        : array();

    if (empty($topic_ids)) {
        $redirect_url = add_query_arg(array(
            'page' => 'yali-ai-writer-topics',
            'yali_notice' => 'no_topics_selected'
        ), admin_url('admin.php'));
        wp_safe_redirect($redirect_url);
        exit;
    }

    // 创建文章生成父任务
    require_once YALI_AI_WRITER_PLUGIN_DIR . 'article-tasks/class-article-task-manager.php';
    $article_task_manager = new Yali_AI_Writer_ArticleTaskManager();
    $task_id = $article_task_manager->create_article_task($topic_ids);

    if ($task_id) {
        $redirect_url = add_query_arg(array(
            'page' => 'yali-ai-writer-topics',
            'yali_notice' => 'task_created'
        ), admin_url('admin.php'));
    } else {
        $redirect_url = add_query_arg(array(
            'page' => 'yali-ai-writer-topics',
            'yali_notice' => 'task_failed'
        ), admin_url('admin.php'));
    }

    wp_safe_redirect($redirect_url);
    exit;
}

/**
 * 处理深度写作表单
 * 独立的深度写作发起入口，不进入现有文章生成队列
 */
add_action('admin_init', 'yali_ai_writer_handle_deep_writing_form');
function yali_ai_writer_handle_deep_writing_form() {
    // 检查是否在正确的页面
    if (!isset($_GET['page']) || sanitize_text_field(wp_unslash($_GET['page'])) !== 'yali-ai-writer-topics') {
        return;
    }

    // 检查是否是 POST 请求并且点击了深度写作按钮
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['deep_writing'])) {
        return;
    }

    // 验证 nonce (复用相同的nonce，因为是同一页面的不同操作)
    if (!isset($_POST['yali_ai_writer_manager_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['yali_ai_writer_manager_nonce'])), 'yali_ai_writer_manager_generate_articles')) {
        wp_die(__('安全验证失败。', 'yali-ai-writer'));
    }

    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_die(__('抱歉，您没有权限执行此操作。', 'yali-ai-writer'));
    }

    // 消毒 topic_ids 数组
    $topic_ids = isset($_POST['topic_ids']) && is_array($_POST['topic_ids'])
        ? array_map('intval', wp_unslash($_POST['topic_ids']))
        : array();

    if (empty($topic_ids)) {
        $redirect_url = add_query_arg(array(
            'page' => 'yali-ai-writer-topics',
            'yali_notice' => 'no_topics_selected_deep_writing'
        ), admin_url('admin.php'));
        wp_safe_redirect($redirect_url);
        exit;
    }

    // 深度写作发起入口：验证通过，但暂不进入文章生成队列
    // 后续将在此添加：
    // 1. 主题状态标记为 deep_writing_queued
    // 2. 组装深度写作请求载荷
    // 3. 发送到浏览器扩展队列

    // 临时占位：仅记录日志并显示成功通知
    do_action('yali_ai_writer_deep_writing_initiated', $topic_ids);

    $redirect_url = add_query_arg(array(
        'page' => 'yali-ai-writer-topics',
        'yali_notice' => 'deep_writing_initiated',
        'yali_count' => count($topic_ids)
    ), admin_url('admin.php'));

    wp_safe_redirect($redirect_url);
    exit;
}

/**
 * 显示 Toast 通知
 * 使用鸭梨AI的 JavaScript Toast 样式
 */
add_action('admin_enqueue_scripts', 'yali_ai_writer_topic_toast_notices');
function yali_ai_writer_topic_toast_notices() {
    // 只在相关页面显示通知
    if (!isset($_GET['page']) || !in_array($_GET['page'], array('yali-ai-writer-topic-jobs', 'yali-ai-writer-topics'))) {
        return;
    }

    if (!isset($_GET['yali_notice'])) {
        return;
    }

    $notice = isset($_GET['yali_notice']) ? sanitize_text_field(wp_unslash($_GET['yali_notice'])) : '';
    $notice_type = 'success';
    $message = '';

    switch ($notice) {
        case 'topic_task_created':
            $message = __('主题生成任务已创建。', 'yali-ai-writer');
            break;
        case 'topic_task_failed':
            $message = __('主题生成任务创建失败。', 'yali-ai-writer');
            $notice_type = 'error';
            break;
        case 'validation_error':
            $message = __('请填写所有必填字段。', 'yali-ai-writer');
            $notice_type = 'error';
            break;
        case 'task_created':
            $message = __('文章生成任务已创建。', 'yali-ai-writer');
            break;
        case 'task_failed':
            $message = __('文章生成任务创建失败。', 'yali-ai-writer');
            $notice_type = 'error';
            break;
        case 'no_topics_selected':
            $message = __('请选择要生成文章的主题。', 'yali-ai-writer');
            $notice_type = 'error';
            break;
        case 'deep_writing_initiated':
            $count = isset($_GET['yali_count']) ? intval($_GET['yali_count']) : 0;
            $message = sprintf(__('深度写作请求已接受：%d 个主题。', 'yali-ai-writer'), $count);
            break;
        case 'no_topics_selected_deep_writing':
            $message = __('请选择要进行深度写作的主题。', 'yali-ai-writer');
            $notice_type = 'error';
            break;
        default:
            return;
    }

    // 引入并本地化脚本
    wp_register_script('yali-toast-handler', plugin_dir_url(dirname(__FILE__)) . 'admin/assets/js/yali-toast-handler.js', array(), YALI_AI_WRITER_VERSION, true);
    wp_enqueue_script('yali-toast-handler');
    wp_localize_script('yali-toast-handler', 'yaliToastData', array(
        'message' => $message,
        'type'    => $notice_type
    ));
}

/**
 * 处理发布规则页面的授权码表单
 */
add_action('admin_init', 'yali_ai_writer_handle_publish_rules_license_form');
function yali_ai_writer_handle_publish_rules_license_form() {
    // 检查是否在正确的页面
    if (!isset($_GET['page']) || sanitize_text_field(wp_unslash($_GET['page'])) !== 'yali-ai-writer-publish-rules') {
        return;
    }

    // 检查是否是 POST 请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['submit_license'])) {
        return;
    }

    // 验证 nonce
    if (!isset($_POST['yali_ai_writer_manager_license_nonce']) || 
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['yali_ai_writer_manager_license_nonce'])), 'yali_ai_writer_manager_license')) {
        wp_die(__('安全验证失败。', 'yali-ai-writer'));
    }

    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_die(__('抱歉，您没有权限执行此操作。', 'yali-ai-writer'));
    }

    $license_key = isset($_POST['yali_ai_writer_manager_license_key']) ? sanitize_text_field(wp_unslash($_POST['yali_ai_writer_manager_license_key'])) : '';
    $notice_type = 'error';
    $message = '';

    if (empty($license_key)) {
        $message = __('请输入授权码。', 'yali-ai-writer');
    } elseif (!preg_match('/^CMT-[A-F0-9]{32}$/', $license_key)) {
        $message = __('授权码格式不正确。正确格式：CMT-XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX（32位十六进制字符）', 'yali-ai-writer');
    } else {
        // 格式正确，先验证再保存
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'includes/class-license-manager.php';
        $old_license = get_option('yali_ai_writer_manager_license_key', '');
        
        // 临时保存以供验证使用
        update_option('yali_ai_writer_manager_license_key', $license_key);
        
        // 进行远程验证
        Yali_AI_Writer_License_Manager::activate_license($license_key);
        
        // 检查验证结果
        $license_data = get_option(Yali_AI_Writer_License_Manager::LICENSE_OPTION, array());
        if (isset($license_data['status']) && $license_data['status'] === 'valid') {
            // 验证成功，保持新授权码
            $notice_type = 'success';
            $message = __('授权码验证成功！', 'yali-ai-writer');
        } else {
            // 验证失败，恢复旧授权码
            update_option('yali_ai_writer_manager_license_key', $old_license);
            $error_msg = isset($license_data['message']) ? $license_data['message'] : __('授权验证失败', 'yali-ai-writer');
            $message = sprintf(__('授权码验证失败：%s', 'yali-ai-writer'), $error_msg);
        }
    }

    $redirect_url = add_query_arg(array(
        'page' => 'yali-ai-writer-publish-rules',
        'yali_notice' => $notice_type === 'success' ? 'license_activated' : 'license_error',
        'yali_message' => urlencode($message)
    ), admin_url('admin.php'));

    wp_safe_redirect($redirect_url);
    exit;
}

/**
 * 处理分类过滤设置表单
 */
add_action('admin_init', 'yali_ai_writer_handle_category_filter_form');
function yali_ai_writer_handle_category_filter_form() {
    // 检查是否在正确的页面
    if (!isset($_GET['page']) || sanitize_text_field(wp_unslash($_GET['page'])) !== 'yali-ai-writer-publish-rules' || !isset($_GET['action']) || sanitize_text_field(wp_unslash($_GET['action'])) !== 'manage-categories') {
        return;
    }

    // 检查是否是 POST 请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['submit_category_filter'])) {
        return;
    }

    // 验证 nonce
    if (!isset($_POST['yali_ai_writer_manager_category_filter_nonce']) || 
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['yali_ai_writer_manager_category_filter_nonce'])), 'yali_ai_writer_manager_category_filter')) {
        wp_die(__('安全验证失败。', 'yali-ai-writer'));
    }

    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_die(__('抱歉，您没有权限执行此操作。', 'yali-ai-writer'));
    }

    // 获取"启用分类过滤"开关状态
    $is_enabled = isset($_POST['yali_enable_category_filter']) && wp_unslash($_POST['yali_enable_category_filter']) == '1' ? 1 : 0;
    update_option('yali_ai_writer_manager_category_filter_enabled', $is_enabled);

    // 获取选中的分类ID
    $allowed_category_ids = isset($_POST['allowed_category_ids']) ? array_map('intval', wp_unslash($_POST['allowed_category_ids'])) : array();

    // 保存设置
    update_option('yali_ai_writer_manager_allowed_categories', $allowed_category_ids);

    $redirect_url = add_query_arg(array(
        'page' => 'yali-ai-writer-publish-rules',
        'action' => 'manage-categories',
        'yali_notice' => 'settings_saved'
    ), admin_url('admin.php'));

    wp_safe_redirect($redirect_url);
    exit;
}

/**
 * 处理调试工具表单
 */
add_action('admin_init', 'yali_ai_writer_handle_debug_tools_form');
function yali_ai_writer_handle_debug_tools_form() {
    // 检查是否在正确的页面
    if (!isset($_GET['page']) || sanitize_text_field(wp_unslash($_GET['page'])) !== 'yali-ai-writer-debug-tools') {
        return;
    }

    // 检查是否是 POST 请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action'])) {
        return;
    }

    // 验证 nonce
    if (!isset($_POST['yali_ai_writer_debug_nonce']) || 
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['yali_ai_writer_debug_nonce'])), 'yali_ai_writer_debug_action')) {
        wp_die(__('安全验证失败。', 'yali-ai-writer'));
    }

    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_die(__('抱歉，您没有权限执行此操作。', 'yali-ai-writer'));
    }

    global $wpdb;
    require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/database/class-database.php';
    $database = new Yali_AI_Writer_Database();
    $table_prefix = $database->get_table_prefix();

    $action = sanitize_text_field(wp_unslash($_POST['action']));
    $notice_type = 'success';
    $message = '';

    switch ($action) {
        case 'truncate_tables':
            // 清空所有表数据
            $tables = array(
                'yali_ai_writer_api_configs',
                'yali_ai_writer_rules',
                'yali_ai_writer_rule_items',
                'yali_ai_writer_topic_tasks',
                'yali_ai_writer_topics',
                'yali_ai_writer_article_tasks',
                'yali_ai_writer_articles',
                'yali_ai_writer_job_queue',
                'yali_ai_writer_publish_rules',
                'yali_ai_writer_article_structures',
                'yali_ai_writer_brand_profiles',
                'yali_ai_writer_prompt_templates',
                'yali_ai_writer_gsc_used_keywords'
            );
            
            foreach ($tables as $table) {
                $table_name = $table_prefix . $table;
                $wpdb->query("TRUNCATE TABLE `$table_name`");
            }
            
            $message = __('所有表数据已清空。', 'yali-ai-writer');
            break;
            
        case 'drop_tables':
            // 删除所有表
            $tables = array(
                'yali_ai_writer_api_configs',
                'yali_ai_writer_rules',
                'yali_ai_writer_rule_items',
                'yali_ai_writer_topic_tasks',
                'yali_ai_writer_topics',
                'yali_ai_writer_article_tasks',
                'yali_ai_writer_articles',
                'yali_ai_writer_job_queue',
                'yali_ai_writer_publish_rules',
                'yali_ai_writer_article_structures',
                'yali_ai_writer_brand_profiles',
                'yali_ai_writer_prompt_templates',
                'yali_ai_writer_gsc_used_keywords'
            );
            
            foreach ($tables as $table) {
                $table_name = $table_prefix . $table;
                $wpdb->query("DROP TABLE IF EXISTS `$table_name`");
            }
            
            $message = __('所有表已删除。', 'yali-ai-writer');
            break;
            
        case 'recreate_tables':
            // 重新创建所有表
            $result = $database->create_tables();
            if ($result['success']) {
                $message = __('所有表已重新创建。成功创建的表：', 'yali-ai-writer') . implode(', ', $result['created_tables']);
            } else {
                $message = __('表创建过程中出现错误：', 'yali-ai-writer') . implode('; ', $result['errors']);
                $notice_type = 'error';
            }
            break;
            
        case 'update_database':
            // 更新数据库表结构
            $result = yali_ai_writer_manager_update_database_structure();
            if ($result['success']) {
                $message = __('数据库表结构已更新到最新版本。所有必要字段已同步。', 'yali-ai-writer');
            } else {
                $message = __('数据库更新过程中出现错误：', 'yali-ai-writer') . implode('; ', $result['errors']);
                $notice_type = 'error';
            }
            break;
            
        case 'enable_debug_mode':
            // 启用调试模式
            update_option('yali_ai_writer_debug_mode', true);
            $message = __('调试模式已启用。现在将记录文章生成过程的详细日志。', 'yali-ai-writer');
            break;

        case 'disable_debug_mode':
            // 禁用调试模式
            update_option('yali_ai_writer_debug_mode', false);
            $message = __('调试模式已禁用。', 'yali-ai-writer');
            break;

        case 'clear_logs':
            // 清空日志
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/logging/class-plugin-logger.php';
            $logger = new Yali_AI_Writer_PluginLogger();
            $logger->clear_log();
            $message = __('所有日志文件已清空。', 'yali-ai-writer');
            break;

        case 'clear_completed_tasks':
            // 清理历史队列任务
            $database = new Yali_AI_Writer_Database();
            $table_prefix = $database->get_table_prefix();

            $deleted_count = 0;
            $tables_to_clean = array(
                'yali_ai_writer_job_queue',
                'yali_ai_writer_topic_tasks',
                'yali_ai_writer_article_tasks'
            );

            foreach ($tables_to_clean as $table) {
                $table_name = $table_prefix . $table;
                $deleted = $wpdb->query($wpdb->prepare(
                    "DELETE FROM `$table_name` WHERE status = %s",
                    'completed'
                ));
                if ($deleted !== false) {
                    $deleted_count += $deleted;
                }
            }

            $message = sprintf(__('已清理 %d 条历史队列任务记录。', 'yali-ai-writer'), $deleted_count);
            break;

        case 'clear_image_api_settings':
            // 清空图像API设置
            delete_option('cam_image_api_settings');
            $message = __('图像API设置已清空。', 'yali-ai-writer');
            break;

        case 'reset_image_api_settings':
            // 重置图像API设置为默认值
            $default_settings = array(
                'provider' => 'modelscope',
                'modelscope' => array(
                    'model_id' => '',
                    'api_key' => '',
                ),
                'openai' => array(
                    'api_key' => '',
                    'model' => 'gpt-image-1',
                ),
                'siliconflow' => array(
                    'api_key' => '',
                    'model' => 'Qwen/Qwen-Image',
                ),
            );
            update_option('cam_image_api_settings', $default_settings);
            $message = __('图像API设置已重置为默认值。', 'yali-ai-writer');
            break;

        case 'clear_auto_image_postmeta':
            // 清理自动配图相关的postmeta字段
            $deleted_count = 0;
            $auto_image_meta_keys = array('_auto_images_processed', '_auto_images_count', '_auto_images_processed_time', '_ai_generated', '_ai_prompt', '_generation_date', '_source_post_id');

            foreach ($auto_image_meta_keys as $meta_key) {
                $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", $meta_key));
                $deleted_count += $deleted;
            }

            // 同时重置插件表中的配图状态
            $articles_table = $table_prefix . 'yali_ai_writer_articles';
            $wpdb->query("UPDATE `{$articles_table}` SET auto_images_processed = 0, auto_images_count = 0 WHERE auto_images_processed = 1");
            $reset_count = $wpdb->rows_affected;

            $message = sprintf(__('已清理 %d 条postmeta记录，并重置 %d 篇文章的配图状态。', 'yali-ai-writer'), $deleted_count, $reset_count);
            break;
            
        default:
            return;
    }

    $redirect_url = add_query_arg(array(
        'page' => 'yali-ai-writer-debug-tools',
        'yali_notice' => $notice_type === 'success' ? 'debug_action_success' : 'debug_action_error',
        'yali_message' => urlencode($message)
    ), admin_url('admin.php'));

    wp_safe_redirect($redirect_url);
    exit;
}

/**
 * 处理仪表盘分类缓存刷新
 */
add_action('admin_init', 'yali_ai_writer_handle_dashboard_cache_refresh');
function yali_ai_writer_handle_dashboard_cache_refresh() {
    // 检查是否在正确的页面（仪表盘主页面 slug 是 yali-ai-writer）
    if (!isset($_GET['page']) || sanitize_text_field(wp_unslash($_GET['page'])) !== 'yali-ai-writer') {
        return;
    }

    // 检查是否是 POST 请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['refresh_category_cache'])) {
        return;
    }

    // 验证 nonce
    if (!isset($_POST['yali_ai_writer_nonce']) || 
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['yali_ai_writer_nonce'])), 'yali_ai_writer_category_cache')) {
        wp_die(__('安全验证失败。', 'yali-ai-writer'));
    }

    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_die(__('抱歉，您没有权限执行此操作。', 'yali-ai-writer'));
    }

    $result = yali_ai_writer_refresh_category_cache();
    
    if (!empty($result)) {
        $notice_type = 'success';
        $message = sprintf(__('分类向量缓存已成功刷新！共处理 %d 个最子级分类。', 'yali-ai-writer'), count($result));
    } else {
        $notice_type = 'error';
        $message = __('分类向量缓存刷新失败，请检查向量API配置。', 'yali-ai-writer');
    }

    $redirect_url = add_query_arg(array(
        'page' => 'yali-ai-writer',
        'yali_notice' => $notice_type === 'success' ? 'cache_refresh_success' : 'cache_refresh_error',
        'yali_message' => urlencode($message)
    ), admin_url('admin.php'));

    wp_safe_redirect($redirect_url);
    exit;
}

/**
 * 显示全局 Toast 通知
 * 处理所有页面的 yali_notice 参数
 */
add_action('admin_enqueue_scripts', 'yali_ai_writer_global_toast_notices');
function yali_ai_writer_global_toast_notices() {
    // 检查是否有通知参数
    if (!isset($_GET['yali_notice'])) {
        return;
    }

    $notice = isset($_GET['yali_notice']) ? sanitize_text_field(wp_unslash($_GET['yali_notice'])) : '';
    $notice_type = 'success';
    $message = '';

    // 优先使用 URL 中传递的消息
    if (isset($_GET['yali_message'])) {
        $message = sanitize_text_field(wp_unslash($_GET['yali_message']));
    }

    switch ($notice) {
        // 发布规则页面
        case 'license_activated':
            if (empty($message)) $message = __('授权码验证成功！', 'yali-ai-writer');
            break;
        case 'license_error':
            if (empty($message)) $message = __('授权码验证失败。', 'yali-ai-writer');
            $notice_type = 'error';
            break;
        case 'settings_saved':
            if (empty($message)) $message = __('设置已保存。', 'yali-ai-writer');
            break;
            
        // 调试工具页面
        case 'debug_action_success':
            if (empty($message)) $message = __('操作成功。', 'yali-ai-writer');
            break;
        case 'debug_action_error':
            if (empty($message)) $message = __('操作失败。', 'yali-ai-writer');
            $notice_type = 'error';
            break;
            
        // 仪表盘页面
        case 'cache_refresh_success':
            if (empty($message)) $message = __('缓存刷新成功。', 'yali-ai-writer');
            break;
        case 'cache_refresh_error':
            if (empty($message)) $message = __('缓存刷新失败。', 'yali-ai-writer');
            $notice_type = 'error';
            break;
            
        default:
            return;
    }

    // 引入并本地化脚本
    wp_register_script('yali-toast-handler', plugin_dir_url(dirname(__FILE__)) . 'admin/assets/js/yali-toast-handler.js', array(), YALI_AI_WRITER_VERSION, true);
    wp_enqueue_script('yali-toast-handler');
    wp_localize_script('yali-toast-handler', 'yaliToastData', array(
        'message' => $message,
        'type'    => $notice_type
    ));
}

/**
 * 处理图像API设置表单
 */
add_action('admin_init', 'yali_ai_writer_handle_image_api_settings_form');
function yali_ai_writer_handle_image_api_settings_form() {
    // 检查是否在正确的页面
    if (!isset($_GET['page']) || sanitize_text_field(wp_unslash($_GET['page'])) !== 'cam-image-api-settings') {
        return;
    }

    // 检查是否是 POST 请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['cam_save_image_api_settings_nonce'])) {
        return;
    }

    // 验证 nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cam_save_image_api_settings_nonce'])), 'cam_save_image_api_settings')) {
        wp_die(__('安全验证失败。', 'yali-ai-writer'));
    }

    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_die(__('抱歉，您没有权限执行此操作。', 'yali-ai-writer'));
    }

    $option_name = 'cam_image_api_settings';
    $settings = get_option($option_name, array());

    // Update the active provider
    $settings['provider'] = isset($_POST['cam_image_api_provider']) ? sanitize_text_field(wp_unslash($_POST['cam_image_api_provider'])) : '';

    // Update ModelScope settings if submitted
    if (isset($_POST['modelscope'])) {
        $modelscope_settings = (array) wp_unslash($_POST['modelscope']);
        $settings['modelscope']['model_id'] = isset($modelscope_settings['model_id']) ? sanitize_text_field($modelscope_settings['model_id']) : '';
        $settings['modelscope']['api_key'] = isset($modelscope_settings['api_key']) ? sanitize_text_field($modelscope_settings['api_key']) : '';
    }

    // Update OpenAI settings if submitted
    if (isset($_POST['openai'])) {
        $openai_settings = (array) wp_unslash($_POST['openai']);
        $settings['openai']['api_key'] = isset($openai_settings['api_key']) ? sanitize_text_field($openai_settings['api_key']) : '';
        $settings['openai']['model'] = isset($openai_settings['model']) ? sanitize_text_field($openai_settings['model']) : 'gpt-image-1';
    }

    // Update Silicon Flow settings if submitted
    if (isset($_POST['siliconflow'])) {
        $siliconflow_settings = (array) wp_unslash($_POST['siliconflow']);
        $settings['siliconflow']['api_key'] = isset($siliconflow_settings['api_key']) ? sanitize_text_field($siliconflow_settings['api_key']) : '';
        $settings['siliconflow']['model'] = isset($siliconflow_settings['model']) ? sanitize_text_field($siliconflow_settings['model']) : 'Qwen/Qwen-Image';
    }

    // Update Pollinations.AI settings if submitted
    if (isset($_POST['pollinations'])) {
        $pollinations_settings = (array) wp_unslash($_POST['pollinations']);
        $settings['pollinations']['model'] = isset($pollinations_settings['model']) ? sanitize_text_field($pollinations_settings['model']) : 'flux';
        $settings['pollinations']['token'] = isset($pollinations_settings['token']) ? sanitize_text_field($pollinations_settings['token']) : '';
    }

    // Update Volcengine settings if submitted
    if (isset($_POST['volcengine'])) {
        $volcengine_settings = (array) wp_unslash($_POST['volcengine']);
        $settings['volcengine']['api_key'] = isset($volcengine_settings['api_key']) ? sanitize_text_field($volcengine_settings['api_key']) : '';
        $settings['volcengine']['model'] = isset($volcengine_settings['model']) ? sanitize_text_field($volcengine_settings['model']) : 'doubao-seedream-5-0-260128';
    }

    // Update Custom API settings if submitted
    if (isset($_POST['custom'])) {
        $custom_settings = (array) wp_unslash($_POST['custom']);
        $settings['custom']['base_url'] = isset($custom_settings['base_url']) ? esc_url_raw($custom_settings['base_url']) : '';
        $settings['custom']['api_key'] = isset($custom_settings['api_key']) ? sanitize_text_field($custom_settings['api_key']) : '';
        $settings['custom']['model'] = isset($custom_settings['model']) ? sanitize_text_field($custom_settings['model']) : 'dall-e-3';
    }

    update_option($option_name, $settings);

    $redirect_url = add_query_arg(array(
        'page' => 'cam-image-api-settings',
        'yali_notice' => 'image_settings_saved'
    ), admin_url('admin.php'));

    wp_safe_redirect($redirect_url);
    exit;
}

/**
 * 处理聚类相似标题搜索表单
 */
add_action('admin_init', 'yali_ai_writer_handle_clustering_similarity_search');
function yali_ai_writer_handle_clustering_similarity_search() {
    // 检查是否在正确的页面
    if (!isset($_GET['page']) || sanitize_text_field(wp_unslash($_GET['page'])) !== 'yali-ai-writer-clustering') {
        return;
    }

    // 检查是否是 POST 请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['find_similar_titles'])) {
        return;
    }

    // 验证 nonce
    if (!isset($_POST['similarity_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['similarity_nonce'])), 'find_similar_titles_action')) {
        wp_die(__('安全验证失败!', 'yali-ai-writer'));
    }

    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_die(__('抱歉，您没有权限执行此操作。', 'yali-ai-writer'));
    }

    // Check if required data is provided
    if (!isset($_POST['topic_id']) || empty($_POST['topic_id'])) {
        $redirect_url = add_query_arg(array(
            'page' => 'yali-ai-writer-clustering',
            'yali_notice' => 'similarity_error',
            'yali_message' => urlencode(__('请提供有效的文章ID。', 'yali-ai-writer'))
        ), admin_url('admin.php'));
        wp_safe_redirect($redirect_url);
        exit;
    }

    $topic_id = intval(wp_unslash($_POST['topic_id']));

    // Validate that the topic exists
    global $wpdb;
    $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
    $topic = $wpdb->get_row($wpdb->prepare("SELECT id, title FROM {$topics_table} WHERE id = %d", $topic_id));

    if (!$topic) {
        $redirect_url = add_query_arg(array(
            'page' => 'yali-ai-writer-clustering',
            'yali_notice' => 'similarity_error',
            'yali_message' => urlencode(sprintf(__('未找到ID为 %d 的文章。', 'yali-ai-writer'), $topic_id))
        ), admin_url('admin.php'));
        wp_safe_redirect($redirect_url);
        exit;
    }

    // Call the similarity function to find similar titles
    $similar_titles = yali_ai_writer_find_similar_titles($topic_id, 20); // Get top 20 similar titles

    // Store results in transient for display after redirect
    set_transient('yali_ai_writer_similarity_results_' . get_current_user_id(), array(
        'topic_title' => $topic->title,
        'topic_id' => $topic_id,
        'results' => $similar_titles
    ), 60);

    $redirect_url = add_query_arg(array(
        'page' => 'yali-ai-writer-clustering',
        'yali_notice' => !empty($similar_titles) ? 'similarity_success' : 'similarity_no_results',
        'yali_message' => urlencode(!empty($similar_titles) ? sprintf(__('找到 %d 个相似标题', 'yali-ai-writer'), count($similar_titles)) : __('未找到相似的标题。请确保已执行聚类操作。', 'yali-ai-writer'))
    ), admin_url('admin.php'));

    wp_safe_redirect($redirect_url);
    exit;
}

/**
 * 显示聚类相似标题搜索结果
 * 在聚类页面显示 transient 中存储的结果
 */
add_action('admin_footer', 'yali_ai_writer_display_similarity_results');
function yali_ai_writer_display_similarity_results() {
    // 只在聚类页面显示
    if (!isset($_GET['page']) || sanitize_text_field(wp_unslash($_GET['page'])) !== 'yali-ai-writer-clustering') {
        return;
    }

    $results_data = get_transient('yali_ai_writer_similarity_results_' . get_current_user_id());
    if (!$results_data) {
        return;
    }

    // 清除 transient
    delete_transient('yali_ai_writer_similarity_results_' . get_current_user_id());

    $topic_title = $results_data['topic_title'];
    $similar_titles = $results_data['results'];

    echo '<script>';
    echo 'window.addEventListener("load", function() {';
    echo 'var resultsHtml = \'<div class="yali-textarea-code" style="max-height: 500px; overflow-y: scroll; margin-top: 20px;">\';';
    echo 'resultsHtml += \'' . sprintf(__('开始查找与 "%s" 相似的标题...', 'yali-ai-writer'), esc_js($topic_title)) . '<br>\';';

    if (empty($similar_titles)) {
        echo 'resultsHtml += \'' . __('未找到相似的标题。请确保已执行聚类操作。', 'yali-ai-writer') . '<br>\';';
    } else {
        echo 'resultsHtml += \'' . sprintf(__('找到 %d 个相似标题：', 'yali-ai-writer'), count($similar_titles)) . '<br><br>\';';
        echo 'resultsHtml += \'<table class="yali-table"><thead><tr><th>' . __('排名', 'yali-ai-writer') . '</th><th>' . __('相似度', 'yali-ai-writer') . '</th><th>' . __('文章ID', 'yali-ai-writer') . '</th><th>' . __('标题', 'yali-ai-writer') . '</th></tr></thead><tbody>\';';

        foreach ($similar_titles as $index => $similar_title) {
            echo 'resultsHtml += \'<tr><td>' . ($index + 1) . '</td><td>' . number_format($similar_title['similarity'], 4) . '</td><td>' . $similar_title['id'] . '</td><td>' . esc_js($similar_title['title']) . '</td></tr>\';';
        }

        echo 'resultsHtml += \'</tbody></table>\';';
    }

    echo 'resultsHtml += \'</div>\';';

    // Insert after the form
    echo 'var form = document.querySelector("form[method=post]");';
    echo 'if (form) {';
    echo 'form.insertAdjacentHTML("afterend", resultsHtml);';
    echo '}';
    echo '});';
    echo '</script>';
}

/**
 * 添加新的通知类型到全局 Toast 通知
 */
add_action('admin_footer', 'yali_ai_writer_extended_toast_notices', 20);
function yali_ai_writer_extended_toast_notices() {
    // 检查是否有通知参数
    if (!isset($_GET['yali_notice'])) {
        return;
    }

    $notice = isset($_GET['yali_notice']) ? sanitize_text_field(wp_unslash($_GET['yali_notice'])) : '';
    $notice_type = 'success';
    $message = '';

    // 优先使用 URL 中传递的消息
    if (isset($_GET['yali_message'])) {
        $message = sanitize_text_field(wp_unslash($_GET['yali_message']));
    }

    switch ($notice) {
        // 图像API设置页面
        case 'image_settings_saved':
            if (empty($message)) $message = __('图像API设置已保存。', 'yali-ai-writer');
            break;

        // 聚类页面
        case 'similarity_success':
            if (empty($message)) $message = __('相似标题查找成功。', 'yali-ai-writer');
            break;
        case 'similarity_no_results':
            if (empty($message)) $message = __('未找到相似标题。', 'yali-ai-writer');
            $notice_type = 'warning';
            break;
        case 'similarity_error':
            if (empty($message)) $message = __('查找相似标题时出错。', 'yali-ai-writer');
            $notice_type = 'error';
            break;

        default:
            return;
    }

    // 输出 JavaScript Toast
    echo '<script>';
    echo 'window.addEventListener("load", function() {';
    echo 'if (typeof window.yaliToast === "function") {';
    echo 'window.yaliToast("' . esc_js($message) . '", "' . esc_js($notice_type) . '");';
    echo '} else {';
    echo 'alert("' . esc_js($message) . '");';
    echo '}';
    // 清除 URL 参数
    echo 'var url = new URL(window.location.href);';
    echo 'url.searchParams.delete("yali_notice");';
    echo 'url.searchParams.delete("yali_message");';
    echo 'window.history.replaceState({}, document.title, url.toString());';
    echo '});';
    echo '</script>';
}
