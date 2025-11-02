<?php
/**
 * 调试工具页面
 */

if (!defined('ABSPATH')) {
    exit;
}

// 获取WordPress数据库对象
global $wpdb;

// 检查权限
if (!current_user_can('manage_options')) {
    wp_die(__('抱歉，您没有权限访问此页面。'));
}

// 获取图像API设置
$image_api_settings = get_option('cam_image_api_settings', array());

// 处理表单提交
$message = '';
if (isset($_POST['action']) && isset($_POST['content_auto_debug_nonce'])) {
    // 验证nonce
    if (!wp_verify_nonce($_POST['content_auto_debug_nonce'], 'content_auto_debug_action')) {
        wp_die(__('安全验证失败。'));
    }
    
    $database = new ContentAuto_Database();
    $table_prefix = $database->get_table_prefix();
    
    switch ($_POST['action']) {
        case 'truncate_tables':
            // 清空所有表数据
            $tables = array(
                'content_auto_api_configs',
                'content_auto_rules',
                'content_auto_rule_items',
                'content_auto_topic_tasks',
                'content_auto_topics',
                'content_auto_article_tasks',
                'content_auto_articles',
                'content_auto_job_queue',
                'content_auto_publish_rules',
                'content_auto_article_structures'
            );
            
            foreach ($tables as $table) {
                $table_name = $table_prefix . $table;
                $wpdb->query("TRUNCATE TABLE `$table_name`");
            }
            
            $message = __('所有表数据已清空。', 'content-auto-manager');
            break;
            
        case 'drop_tables':
            // 删除所有表
            $tables = array(
                'content_auto_api_configs',
                'content_auto_rules',
                'content_auto_rule_items',
                'content_auto_topic_tasks',
                'content_auto_topics',
                'content_auto_article_tasks',
                'content_auto_articles',
                'content_auto_job_queue',
                'content_auto_publish_rules',
                'content_auto_article_structures'
            );
            
            foreach ($tables as $table) {
                $table_name = $table_prefix . $table;
                $wpdb->query("DROP TABLE IF EXISTS `$table_name`");
            }
            
            $message = __('所有表已删除。', 'content-auto-manager');
            break;
            
        case 'recreate_tables':
            // 重新创建所有表
            $result = $database->create_tables();
            if ($result['success']) {
                $message = __('所有表已重新创建。成功创建的表：' . implode(', ', $result['created_tables']), 'content-auto-manager');
            } else {
                $message = __('表创建过程中出现错误：' . implode('; ', $result['errors']), 'content-auto-manager');
                $error = true;
            }
            break;
            
        case 'update_database':
            // 更新数据库表结构
            $result = content_auto_manager_update_database_structure();
            if ($result['success']) {
                $message = __('数据库表结构已更新到最新版本。所有必要字段已同步。', 'content-auto-manager');
            } else {
                $message = __('数据库更新过程中出现错误：' . implode('; ', $result['errors']), 'content-auto-manager');
                $error = true;
            }
            break;
            
        case 'clear_logs':
            // 清空所有日志文件
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/logging/class-plugin-logger.php';
            $logger = new ContentAuto_PluginLogger();
            $logger->clear_log();
            $message = __('所有日志文件已清空。', 'content-auto-manager');
            break;

        case 'clear_completed_tasks':
            // 清理历史队列任务
            $database = new ContentAuto_Database();
            $table_prefix = $database->get_table_prefix();

            $deleted_count = 0;
            $tables_to_clean = array(
                'content_auto_job_queue',
                'content_auto_topic_tasks',
                'content_auto_article_tasks'
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

            $message = sprintf(__('已清理 %d 条历史队列任务记录。', 'content-auto-manager'), $deleted_count);
            break;

        case 'clear_image_api_settings':
            // 清空图像API设置
            delete_option('cam_image_api_settings');
            $message = __('图像API设置已清空。', 'content-auto-manager');
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
            $message = __('图像API设置已重置为默认值。', 'content-auto-manager');
            break;
            
        case 'clear_auto_image_postmeta':
            // 清理自动配图相关的postmeta字段
            $deleted_count = 0;
            $auto_image_meta_keys = array('_auto_images_processed', '_auto_images_count', '_auto_images_processed_time', '_ai_generated', '_ai_prompt', '_generation_date', '_source_post_id');
            
            foreach ($auto_image_meta_keys as $meta_key) {
                $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", $meta_key));
                $deleted_count += $deleted;
            }
            
            $message = sprintf(__('已清理 %d 条自动配图相关的postmeta记录。', 'content-auto-manager'), $deleted_count);
            break;
    }
}

// 重新获取更新后的图像API设置
$image_api_settings = get_option('cam_image_api_settings', array());

// 获取数据库统计
$stats = array();

// API配置统计
$api_configs_table = $wpdb->prefix . 'content_auto_api_configs';
$stats['api_configs'] = $wpdb->get_var("SELECT COUNT(*) FROM {$api_configs_table}");
$stats['active_api_configs'] = $wpdb->get_var("SELECT COUNT(*) FROM {$api_configs_table} WHERE is_active = 1");

// 品牌资料统计
$brand_profiles_table = $wpdb->prefix . 'content_auto_brand_profiles';
$stats['brand_profiles'] = $wpdb->get_var("SELECT COUNT(*) FROM {$brand_profiles_table}");

// 规则统计
$rules_table = $wpdb->prefix . 'content_auto_rules';
$stats['rules'] = $wpdb->get_var("SELECT COUNT(*) FROM {$rules_table}");
$stats['active_rules'] = $wpdb->get_var("SELECT COUNT(*) FROM {$rules_table} WHERE status = 1");

// 规则项目统计
$rule_items_table = $wpdb->prefix . 'content_auto_rule_items';
$stats['rule_items'] = $wpdb->get_var("SELECT COUNT(*) FROM {$rule_items_table}");

// 主题任务统计
$topic_tasks_table = $wpdb->prefix . 'content_auto_topic_tasks';
$stats['topic_tasks'] = $wpdb->get_var("SELECT COUNT(*) FROM {$topic_tasks_table}");
$stats['pending_topic_tasks'] = $wpdb->get_var("SELECT COUNT(*) FROM {$topic_tasks_table} WHERE status = CONTENT_AUTO_STATUS_PENDING");
$stats['processing_topic_tasks'] = $wpdb->get_var("SELECT COUNT(*) FROM {$topic_tasks_table} WHERE status = CONTENT_AUTO_STATUS_PROCESSING");
$stats['completed_topic_tasks'] = $wpdb->get_var("SELECT COUNT(*) FROM {$topic_tasks_table} WHERE status = CONTENT_AUTO_STATUS_COMPLETED");
$stats['failed_topic_tasks'] = $wpdb->get_var("SELECT COUNT(*) FROM {$topic_tasks_table} WHERE status = CONTENT_AUTO_STATUS_FAILED");

// 主题统计
$topics_table = $wpdb->prefix . 'content_auto_topics';
$stats['topics'] = $wpdb->get_var("SELECT COUNT(*) FROM {$topics_table}");
$stats['unused_topics'] = $wpdb->get_var("SELECT COUNT(*) FROM {$topics_table} WHERE status = CONTENT_AUTO_TOPIC_UNUSED");
$stats['queued_topics'] = $wpdb->get_var("SELECT COUNT(*) FROM {$topics_table} WHERE status = CONTENT_AUTO_TOPIC_QUEUED");
$stats['used_topics'] = $wpdb->get_var("SELECT COUNT(*) FROM {$topics_table} WHERE status = CONTENT_AUTO_TOPIC_USED");

// 文章任务统计
$article_tasks_table = $wpdb->prefix . 'content_auto_article_tasks';
$stats['article_jobs'] = $wpdb->get_var("SELECT COUNT(*) FROM {$article_tasks_table}");
$stats['pending_article_jobs'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$article_tasks_table} WHERE status = %s", CONTENT_AUTO_STATUS_PENDING));
$stats['processing_article_jobs'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$article_tasks_table} WHERE status = %s", CONTENT_AUTO_STATUS_PROCESSING));
$stats['completed_article_jobs'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$article_tasks_table} WHERE status = %s", CONTENT_AUTO_STATUS_COMPLETED));
$stats['failed_article_jobs'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$article_tasks_table} WHERE status = %s", CONTENT_AUTO_STATUS_FAILED));

// 文章统计
$articles_table = $wpdb->prefix . 'content_auto_articles';
$stats['articles'] = $wpdb->get_var("SELECT COUNT(*) FROM {$articles_table}");
$stats['articles_with_images'] = $wpdb->get_var("SELECT COUNT(*) FROM {$articles_table} WHERE auto_images_processed = 1");
$stats['pending_image_articles'] = $wpdb->get_var("SELECT COUNT(*) FROM {$articles_table} WHERE auto_images_processed = 0");

// 自动配图统计（从postmeta表）
$stats['ai_generated_images'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_ai_generated' AND meta_value = '1'");
$stats['posts_with_image_placeholders'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE '%<!-- image prompt:%' AND post_status IN ('publish', 'draft', 'future')");

// 文章结构统计
$article_structures_table = $wpdb->prefix . 'content_auto_article_structures';
$stats['article_structures'] = $wpdb->get_var("SELECT COUNT(*) FROM {$article_structures_table}");

// 队列统计
$queue_table = $wpdb->prefix . 'content_auto_job_queue';
$stats['queue_total'] = $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table}");
$stats['queue_pending'] = $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table} WHERE status = CONTENT_AUTO_STATUS_PENDING");
$stats['queue_processing'] = $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table} WHERE status = CONTENT_AUTO_STATUS_PROCESSING");
$stats['queue_completed'] = $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table} WHERE status = CONTENT_AUTO_STATUS_COMPLETED");
$stats['queue_failed'] = $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table} WHERE status = CONTENT_AUTO_STATUS_FAILED");

// 获取日志文件统计信息
$logs_dir = CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'logs';
$log_stats = array(
    'file_count' => 0,
    'total_size' => 0,
    'latest_file' => '',
    'latest_file_size' => 0,
    'latest_file_time' => ''
);

if (file_exists($logs_dir)) {
    $log_files = glob($logs_dir . '/*.log');
    $log_stats['file_count'] = count($log_files);

    $total_size = 0;
    $latest_file = '';
    $latest_file_size = 0;
    $latest_file_time = 0;

    foreach ($log_files as $file) {
        $file_size = filesize($file);
        $total_size += $file_size;

        $file_time = filemtime($file);
        if ($file_time > $latest_file_time) {
            $latest_file_time = $file_time;
            $latest_file = basename($file);
            $latest_file_size = $file_size;
        }
    }

    $log_stats['total_size'] = $total_size;
    $log_stats['latest_file'] = $latest_file;
    $log_stats['latest_file_size'] = $latest_file_size;
    $log_stats['latest_file_time'] = $latest_file_time ? date('Y-m-d H:i:s', $latest_file_time) : '';
}

// 表结构描述 - 完全同步最新结构
$table_descriptions = array(
    'content_auto_api_configs' => array(
        'name' => __('API配置表', 'content-auto-manager'),
        'description' => __('存储大模型API的配置信息', 'content-auto-manager'),
        'fields' => array(
            'id' => __('配置唯一标识符', 'content-auto-manager'),
            'name' => __('配置名称', 'content-auto-manager'),
            'api_url' => __('API地址', 'content-auto-manager'),
            'api_key' => __('API密钥', 'content-auto-manager'),
            'model_name' => __('模型名称', 'content-auto-manager'),
            'temperature' => __('温度参数，控制输出的随机性', 'content-auto-manager'),
            'max_tokens' => __('最大Token数，控制输出长度', 'content-auto-manager'),
            'temperature_enabled' => __('是否启用温度参数', 'content-auto-manager'),
            'max_tokens_enabled' => __('是否启用最大Token数参数', 'content-auto-manager'),
            'is_active' => __('是否为激活配置', 'content-auto-manager'),
            'predefined_channel' => __('预置API渠道（如pollinations等）', 'content-auto-manager'),
            'vector_api_url' => __('向量API地址', 'content-auto-manager'),
            'vector_api_key' => __('向量API密钥', 'content-auto-manager'),
            'vector_model_name' => __('向量模型名称', 'content-auto-manager'),
            'created_at' => __('创建时间', 'content-auto-manager'),
            'updated_at' => __('更新时间', 'content-auto-manager')
        )
    ),
    'content_auto_brand_profiles' => array(
        'name' => __('品牌资料表', 'content-auto-manager'),
        'description' => __('存储品牌资料信息，用于文章插入时的品牌内容匹配', 'content-auto-manager'),
        'fields' => array(
            'id' => __('品牌资料唯一标识符', 'content-auto-manager'),
            'brand_name' => __('品牌名称', 'content-auto-manager'),
            'brand_description' => __('品牌描述', 'content-auto-manager'),
            'brand_keywords' => __('品牌关键词（JSON格式）', 'content-auto-manager'),
            'brand_logo_url' => __('品牌标志图片URL', 'content-auto-manager'),
            'brand_website' => __('品牌官方网站URL', 'content-auto-manager'),
            'brand_contact_info' => __('品牌联系信息', 'content-auto-manager'),
            'brand_slogan' => __('品牌口号或标语', 'content-auto-manager'),
            'brand_features' => __('品牌特点或优势（JSON格式）', 'content-auto-manager'),
            'brand_target_audience' => __('品牌目标受众', 'content-auto-manager'),
            'brand_tone_of_voice' => __('品牌语调风格', 'content-auto-manager'),
            'brand_colors' => __('品牌代表色（JSON格式）', 'content-auto-manager'),
            'type' => __('品牌资料类型（brand、product等）', 'content-auto-manager'),
            'custom_html' => __('自定义HTML内容', 'content-auto-manager'),
            'created_at' => __('创建时间', 'content-auto-manager'),
            'updated_at' => __('更新时间', 'content-auto-manager')
        )
    ),
    'content_auto_rules' => array(
        'name' => __('规则表', 'content-auto-manager'),
        'description' => __('存储内容生成规则', 'content-auto-manager'),
        'fields' => array(
            'id' => __('规则唯一标识符', 'content-auto-manager'),
            'rule_name' => __('规则名称', 'content-auto-manager'),
            'rule_type' => __('规则类型（random_selection、fixed_articles、upload_text）', 'content-auto-manager'),
            'rule_conditions' => __('规则条件（序列化存储，根据不同规则类型存储分类ID、文章ID或上传文本内容）', 'content-auto-manager'),
            'item_count' => __('规则项目数量', 'content-auto-manager'),
            'rule_task_id' => __('规则任务ID', 'content-auto-manager'),
            'reference_material' => __('规则级参考资料，用于文章生成提示词，最多500字符', 'content-auto-manager'),
            'status' => __('规则状态（1启用，0禁用）', 'content-auto-manager'),
            'created_at' => __('创建时间', 'content-auto-manager'),
            'updated_at' => __('更新时间', 'content-auto-manager')
        )
    ),
    'content_auto_rule_items' => array(
        'name' => __('规则项目表', 'content-auto-manager'),
        'description' => __('存储规则的具体项目内容', 'content-auto-manager'),
        'fields' => array(
            'id' => __('项目唯一标识符', 'content-auto-manager'),
            'rule_id' => __('关联的规则ID', 'content-auto-manager'),
            'rule_task_id' => __('规则任务ID', 'content-auto-manager'),
            'post_id' => __('关联的文章ID', 'content-auto-manager'),
            'post_title' => __('文章标题', 'content-auto-manager'),
            'category_ids' => __('分类ID列表', 'content-auto-manager'),
            'category_names' => __('分类名称列表', 'content-auto-manager'),
            'category_descriptions' => __('分类描述列表', 'content-auto-manager'),
            'tag_names' => __('标签名称列表', 'content-auto-manager'),
            'upload_text' => __('上传的文本内容', 'content-auto-manager'),
            'created_at' => __('创建时间', 'content-auto-manager'),
            'updated_at' => __('更新时间', 'content-auto-manager')
        )
    ),
    'content_auto_topic_tasks' => array(
        'name' => __('主题任务表', 'content-auto-manager'),
        'description' => __('存储主题生成任务信息', 'content-auto-manager'),
        'fields' => array(
            'id' => __('任务唯一标识符', 'content-auto-manager'),
            'topic_task_id' => __('主题任务ID，用于全局查询的唯一ID', 'content-auto-manager'),
            'rule_id' => __('关联的规则ID', 'content-auto-manager'),
            'topic_count_per_item' => __('每个规则项目生成的主题数量', 'content-auto-manager'),
            'total_rule_items' => __('规则项目总数', 'content-auto-manager'),
            'total_expected_topics' => __('预期生成主题总数', 'content-auto-manager'),
            'current_processing_item' => __('当前处理的规则项目索引', 'content-auto-manager'),
            'generated_topics_count' => __('已生成主题数量', 'content-auto-manager'),
            'status' => __('任务状态（pending、processing、completed、failed）', 'content-auto-manager'),
            'error_message' => __('错误信息', 'content-auto-manager'),
            'subtask_status' => __('子任务状态JSON存储', 'content-auto-manager'),
            'last_processed_at' => __('最后处理时间', 'content-auto-manager'),
            'created_at' => __('创建时间', 'content-auto-manager'),
            'updated_at' => __('更新时间', 'content-auto-manager')
        )
    ),
    'content_auto_job_queue' => array(
            'name' => __('任务队列表', 'content-auto-manager'),
            'description' => __('存储系统中所有待处理的任务队列（包括主题生成、文章生成等）', 'content-auto-manager'),
            'fields' => array(
                'id' => __('队列项唯一标识符', 'content-auto-manager'),
                'job_type' => __('任务类型（topic_task、article等）', 'content-auto-manager'),
                'job_id' => __('关联的任务ID（指向具体任务表的主键ID，根据job_type字段确定具体表，如content_auto_topic_tasks表的id）', 'content-auto-manager'),
                'subtask_id' => __('子任务ID，用于唯一标识同一任务中的不同子任务', 'content-auto-manager'),
                'reference_id' => __('引用ID，用于存储文章任务中的主题ID，article任务类型时有效（重构新增）', 'content-auto-manager'),
                'priority' => __('任务优先级', 'content-auto-manager'),
                'retry_count' => __('重试次数，记录任务重试的次数（重构新增）', 'content-auto-manager'),
                'scheduled_at' => __('计划执行时间，用于定时任务调度', 'content-auto-manager'),
                'status' => __('任务状态（pending、processing、completed、failed）', 'content-auto-manager'),
                'error_message' => __('错误信息', 'content-auto-manager'),
                'created_at' => __('创建时间', 'content-auto-manager'),
                'updated_at' => __('更新时间', 'content-auto-manager')
            )
        ),
    'content_auto_topics' => array(
        'name' => __('主题表', 'content-auto-manager'),
        'description' => __('存储生成的主题内容及结构化数据，包括API配置信息和向量数据', 'content-auto-manager'),
        'fields' => array(
            'id' => __('主题唯一标识符', 'content-auto-manager'),
            'task_id' => __('关联的主题任务唯一标识符（来自content_auto_topic_tasks表的topic_task_id字段）', 'content-auto-manager'),
            'rule_id' => __('关联的规则ID', 'content-auto-manager'),
            'rule_item_index' => __('来源规则项目索引', 'content-auto-manager'),
            'title' => __('主题标题', 'content-auto-manager'),
            'source_angle' => __('内容角度', 'content-auto-manager'),
            'user_value' => __('用户价值描述', 'content-auto-manager'),
            'seo_keywords' => __('SEO关键词（JSON格式）', 'content-auto-manager'),
            'matched_category' => __('推荐匹配分类', 'content-auto-manager'),
            'priority_score' => __('优先级评分（1-5）', 'content-auto-manager'),
            'status' => __('主题状态（unused、used）', 'content-auto-manager'),
            'api_config_id' => __('关联的API配置ID，用于指定生成主题时使用的API配置', 'content-auto-manager'),
            'api_config_name' => __('API配置名称，记录生成主题时使用的具体API配置名称', 'content-auto-manager'),
            'vector_embedding' => __('主题向量嵌入数据（JSON格式），用于存储1024维向量数据', 'content-auto-manager'),
            'vector_cluster_id' => __('向量聚类ID，用于主题聚类分析', 'content-auto-manager'),
            'vector_status' => __('向量生成状态（pending、completed、failed）', 'content-auto-manager'),
            'vector_error' => __('向量生成错误信息', 'content-auto-manager'),
            'vector_retry_count' => __('向量生成重试次数', 'content-auto-manager'),
            'reference_material' => __('主题级参考资料，优先于规则级参考资料，最多500字符', 'content-auto-manager'),
            'created_at' => __('创建时间', 'content-auto-manager'),
            'updated_at' => __('更新时间', 'content-auto-manager')
        )
    ),
    'content_auto_article_tasks' => array(
        'name' => __('文章任务表', 'content-auto-manager'),
        'description' => __('存储文章生成父任务信息，实现与主题任务相同的父子任务架构', 'content-auto-manager'),
        'fields' => array(
            'id' => __('任务唯一标识符', 'content-auto-manager'),
            'article_task_id' => __('任务ID，用于全局查询的唯一ID', 'content-auto-manager'),
            'name' => __('任务名称', 'content-auto-manager'),
            'topic_ids' => __('关联的主题ID列表（JSON格式）', 'content-auto-manager'),
            'status' => __('任务状态（pending、processing、completed、failed）', 'content-auto-manager'),
            'subtask_status' => __('子任务状态JSON存储', 'content-auto-manager'),
            'error_message' => __('错误信息', 'content-auto-manager'),
            'total_topics' => __('主题总数', 'content-auto-manager'),
            'completed_topics' => __('已完成主题数', 'content-auto-manager'),
            'failed_topics' => __('失败主题数', 'content-auto-manager'),
            'current_processing_item' => __('当前处理的子任务数量（重构新增）', 'content-auto-manager'),
            'total_rule_items' => __('总子任务数量，每个主题作为一个子任务（重构新增）', 'content-auto-manager'),
            'generated_articles_count' => __('已生成文章数量（重构新增）', 'content-auto-manager'),
            'last_processed_at' => __('最后处理时间', 'content-auto-manager'),
            'created_at' => __('创建时间', 'content-auto-manager'),
            'updated_at' => __('更新时间', 'content-auto-manager')
        )
    ),
    'content_auto_articles' => array(
        'name' => __('文章表', 'content-auto-manager'),
        'description' => __('存储生成的文章内容', 'content-auto-manager'),
        'fields' => array(
            'id' => __('文章唯一标识符', 'content-auto-manager'),
            'job_id' => __('关联的任务ID', 'content-auto-manager'),
            'topic_id' => __('关联的主题ID', 'content-auto-manager'),
            'post_id' => __('关联的WordPress文章ID', 'content-auto-manager'),
            'title' => __('文章标题', 'content-auto-manager'),
            'content' => __('文章内容', 'content-auto-manager'),
            'status' => __('文章状态（pending、success、failed）', 'content-auto-manager'),
            'error_message' => __('错误信息', 'content-auto-manager'),
            'processing_time' => __('处理耗时(秒)', 'content-auto-manager'),
            'word_count' => __('文章字数', 'content-auto-manager'),
            'api_config_id' => __('关联的API配置ID，用于指定生成文章时使用的API配置', 'content-auto-manager'),
            'api_config_name' => __('API配置名称，记录生成文章时使用的具体API配置名称', 'content-auto-manager'),
            'auto_images_processed' => __('是否已处理自动配图（0未处理、1已处理）', 'content-auto-manager'),
            'auto_images_count' => __('生成的图片数量', 'content-auto-manager'),
            'created_at' => __('创建时间', 'content-auto-manager'),
            'updated_at' => __('更新时间', 'content-auto-manager')
        )
    ),
    'content_auto_publish_rules' => array(
        'name' => __('发布规则表', 'content-auto-manager'),
        'description' => __('存储文章发布的规则配置，包括内链功能和发布间隔设置', 'content-auto-manager'),
        'fields' => array(
            'id' => __('规则唯一标识符', 'content-auto-manager'),
            'post_status' => __('文章发布状态（draft、publish等）', 'content-auto-manager'),
            'author_id' => __('文章作者ID', 'content-auto-manager'),
            'category_mode' => __('分类选择模式（manual手动、auto自动）', 'content-auto-manager'),
            'category_ids' => __('手动选择的分类ID列表（序列化存储）', 'content-auto-manager'),
            'fallback_category_ids' => __('自动分类失败时的备用分类ID列表（序列化存储）', 'content-auto-manager'),
            'target_length' => __('目标文章长度（如800-1500）', 'content-auto-manager'),
            'knowledge_depth' => __('内容深度（浅层普及、实用指导、深度分析、全面综述）', 'content-auto-manager'),
            'reader_role' => __('目标受众（潜在客户、现有客户、行业同仁、决策者、泛流量用户）', 'content-auto-manager'),
            'normalize_output' => __('是否启用输出规范化（0关闭、1启用）', 'content-auto-manager'),
            'auto_image_insertion' => __('是否启用文章自动配图（0关闭、1启用）', 'content-auto-manager'),
            'max_auto_images' => __('最大自动生成图片数量（1-5张）', 'content-auto-manager'),
            'skip_first_image_placeholder' => __('是否跳过首个图片占位符（0关闭、1启用）', 'content-auto-manager'),
            'enable_internal_linking' => __('是否启用文章内链功能（0关闭、1启用）', 'content-auto-manager'),
            'publish_interval_minutes' => __('发布间隔时间（分钟），0表示立即发布', 'content-auto-manager'),
            'enable_brand_profile_insertion' => __('是否启用品牌资料插入功能（0关闭、1启用）', 'content-auto-manager'),
            'brand_profile_position' => __('品牌资料插入位置（before_second_paragraph或article_end）', 'content-auto-manager'),
            'enable_reference_material' => __('是否启用参考资料功能（0关闭、1启用）', 'content-auto-manager'),
            'publish_language' => __('发布语言代码（如zh-CN、en-US等），影响内容生成的输出语言', 'content-auto-manager'),
            'created_at' => __('创建时间', 'content-auto-manager'),
            'updated_at' => __('更新时间', 'content-auto-manager')
        )
    ),
    'content_auto_article_structures' => array(
        'name' => __('文章结构表', 'content-auto-manager'),
        'description' => __('存储不同内容角度的文章结构模板，用于指导AI生成结构化的文章内容', 'content-auto-manager'),
        'fields' => array(
            'id' => __('结构唯一标识符', 'content-auto-manager'),
            'content_angle' => __('内容角度，如产品介绍、使用指南、行业分析等', 'content-auto-manager'),
            'title' => __('结构标题模板', 'content-auto-manager'),
            'structure' => __('文章结构定义（JSON格式），包含章节、段落等结构信息', 'content-auto-manager'),
            'title_vector' => __('标题向量数据，用于结构相似度匹配', 'content-auto-manager'),
            'usage_count' => __('使用次数统计，用于跟踪结构模板的受欢迎程度', 'content-auto-manager'),
            'created_at' => __('创建时间', 'content-auto-manager'),
            'updated_at' => __('更新时间', 'content-auto-manager')
        )
    )
);
?>

<div class="wrap">
    <h1><?php _e('调试工具', 'content-auto-manager'); ?></h1>
    
    <!-- 调试模式控制面板 -->
    <div class="debug-mode-control" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0; border-radius: 5px;">
        <h2 style="margin-top: 0;">🔧 调试模式控制</h2>
        <?php
        $debug_mode = get_option('content_auto_debug_mode', false);
        $status_class = $debug_mode ? 'notice-success' : 'notice-info';
        $status_text = $debug_mode ? '已启用' : '已禁用';
        $status_icon = $debug_mode ? '✅' : '❌';
        ?>
        
        <div class="notice <?php echo $status_class; ?> inline" style="margin: 10px 0;">
            <p><strong><?php echo $status_icon; ?> 当前状态：调试模式<?php echo $status_text; ?></strong></p>
            <?php if ($debug_mode): ?>
            <p>📂 日志位置：<code><?php echo CONTENT_AUTO_MANAGER_PLUGIN_DIR; ?>logs/<?php echo date('Y-m-d'); ?>.log</code></p>
            <p>⚠️ 调试模式会记录完整的API提示词，建议获取所需日志后及时关闭。</p>
            <?php else: ?>
            <p>💡 启用后将记录完整的主题生成和文章生成API提示词到日志文件。</p>
            <?php endif; ?>
        </div>
        
        <div class="debug-mode-actions" style="margin-top: 15px;">
            <?php if ($debug_mode): ?>
            <button type="button" class="button button-secondary" id="disable-debug-mode">
                ❌ 关闭调试模式
            </button>
            <button type="button" class="button button-primary" id="view-debug-logs" style="margin-left: 10px;">
                📄 查看调试日志
            </button>
            <button type="button" class="button button-secondary" id="clear-debug-logs" style="margin-left: 10px;">
                🗑️ 清空日志
            </button>
            <?php else: ?>
            <button type="button" class="button button-primary" id="enable-debug-mode">
                ✅ 启用调试模式
            </button>
            <?php endif; ?>
        </div>
        
        <div id="debug-logs-content" style="display: none; margin-top: 20px; background: #f9f9f9; padding: 15px; border-radius: 5px; max-height: 400px; overflow-y: auto;">
            <h4>📋 最新调试日志</h4>
            <pre id="logs-display" style="background: #fff; padding: 10px; border: 1px solid #ddd; font-size: 12px; line-height: 1.4;"></pre>
        </div>
    </div>
      
    <?php if (!empty($message)): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html($message); ?></p>
        </div>
    <?php endif; ?>
    
    <div class="metabox-holder">
        <div class="postbox">
            <h2 class="hndle"><?php _e('数据库表操作', 'content-auto-manager'); ?></h2>
            <div class="inside">
                <p><?php _e('警告：以下操作将影响数据库中的数据，请谨慎使用。', 'content-auto-manager'); ?></p>
                
                <form method="post" style="display: inline-block; margin-right: 10px;">
                    <?php wp_nonce_field('content_auto_debug_action', 'content_auto_debug_nonce'); ?>
                    <input type="hidden" name="action" value="truncate_tables">
                    <?php submit_button(__('清空所有表数据', 'content-auto-manager'), 'secondary', 'submit', false); ?>
                </form>
                
                <form method="post" style="display: inline-block; margin-right: 10px;">
                    <?php wp_nonce_field('content_auto_debug_action', 'content_auto_debug_nonce'); ?>
                    <input type="hidden" name="action" value="drop_tables">
                    <?php submit_button(__('删除所有表', 'content-auto-manager'), 'secondary', 'submit', false); ?>
                </form>
                
                <form method="post" style="display: inline-block; margin-right: 10px;">
                    <?php wp_nonce_field('content_auto_debug_action', 'content_auto_debug_nonce'); ?>
                    <input type="hidden" name="action" value="recreate_tables">
                    <?php submit_button(__('重新创建所有表', 'content-auto-manager'), 'primary', 'submit', false); ?>
                </form>
                
                <form method="post" style="display: inline-block; margin-right: 10px;">
                    <?php wp_nonce_field('content_auto_debug_action', 'content_auto_debug_nonce'); ?>
                    <input type="hidden" name="action" value="update_database">
                    <?php submit_button(__('更新数据库表结构（保留数据）', 'content-auto-manager'), 'primary', 'submit', false); ?>
                </form>

                <form method="post" id="clear_completed_tasks_form" style="display: inline-block;">
                    <?php wp_nonce_field('content_auto_debug_action', 'content_auto_debug_nonce'); ?>
                    <input type="hidden" name="action" value="clear_completed_tasks">
                    <button type="button" class="button button-secondary" onclick="confirmClearCompletedTasks()">
                        <?php _e('清理历史队列任务', 'content-auto-manager'); ?>
                    </button>
                </form>

            </div>
        </div>
        

        
        <div class="postbox">
            <h2 class="hndle"><?php _e('数据库统计', 'content-auto-manager'); ?></h2>
            <div class="inside">
                <div class="stats-grid">
                    <div class="stat-item">
                        <h4><?php _e('API配置', 'content-auto-manager'); ?></h4>
                        <p><?php printf(__('总数: %d | 激活: %d', 'content-auto-manager'), $stats['api_configs'], $stats['active_api_configs']); ?></p>
                    </div>

                    <div class="stat-item">
                        <h4><?php _e('品牌资料', 'content-auto-manager'); ?></h4>
                        <p><?php printf(__('总数: %d', 'content-auto-manager'), $stats['brand_profiles']); ?></p>
                    </div>

                    <div class="stat-item">
                        <h4><?php _e('规则', 'content-auto-manager'); ?></h4>
                        <p><?php printf(__('总数: %d | 激活: %d | 项目: %d', 'content-auto-manager'), $stats['rules'], $stats['active_rules'], $stats['rule_items']); ?></p>
                    </div>
                    
                    <div class="stat-item">
                        <h4><?php _e('主题任务', 'content-auto-manager'); ?></h4>
                        <p><?php printf(__('总数: %d', 'content-auto-manager'), $stats['topic_tasks']); ?></p>
                        <p><?php printf(__('待处理: %d | 处理中: %d', 'content-auto-manager'), $stats['pending_topic_tasks'], $stats['processing_topic_tasks']); ?></p>
                        <p><?php printf(__('已完成: %d | 失败: %d', 'content-auto-manager'), $stats['completed_topic_tasks'], $stats['failed_topic_tasks']); ?></p>
                    </div>
                    
                    <div class="stat-item">
                        <h4><?php _e('主题', 'content-auto-manager'); ?></h4>
                        <p><?php printf(__('总数: %d | 未使用: %d | 队列中: %d | 已使用: %d', 'content-auto-manager'), $stats['topics'], $stats['unused_topics'], $stats['queued_topics'], $stats['used_topics']); ?></p>
                    </div>
                    
                    <div class="stat-item">
                        <h4><?php _e('文章任务', 'content-auto-manager'); ?></h4>
                        <p><?php printf(__('总数: %d', 'content-auto-manager'), $stats['article_jobs']); ?></p>
                        <p><?php printf(__('待处理: %d | 处理中: %d', 'content-auto-manager'), $stats['pending_article_jobs'], $stats['processing_article_jobs']); ?></p>
                        <p><?php printf(__('已完成: %d | 失败: %d', 'content-auto-manager'), $stats['completed_article_jobs'], $stats['failed_article_jobs']); ?></p>
                    </div>
                    
                    <div class="stat-item">
                        <h4><?php _e('文章', 'content-auto-manager'); ?></h4>
                        <p><?php printf(__('总数: %d | 已配图: %d | 待配图: %d', 'content-auto-manager'), $stats['articles'], $stats['articles_with_images'], $stats['pending_image_articles']); ?></p>
                    </div>
                    
                    <div class="stat-item">
                        <h4><?php _e('自动配图', 'content-auto-manager'); ?></h4>
                        <p><?php printf(__('AI生成图片: %d 张', 'content-auto-manager'), $stats['ai_generated_images']); ?></p>
                        <p><?php printf(__('包含占位符文章: %d 篇', 'content-auto-manager'), $stats['posts_with_image_placeholders']); ?></p>
                    </div>

                    <div class="stat-item">
                        <h4><?php _e('文章结构', 'content-auto-manager'); ?></h4>
                        <p><?php printf(__('总数: %d', 'content-auto-manager'), $stats['article_structures']); ?></p>
                    </div>
                    
                    <div class="stat-item">
                        <h4><?php _e('任务队列', 'content-auto-manager'); ?></h4>
                        <p><?php printf(__('总数: %d', 'content-auto-manager'), $stats['queue_total']); ?></p>
                        <p><?php printf(__('待处理: %d | 处理中: %d', 'content-auto-manager'), $stats['queue_pending'], $stats['queue_processing']); ?></p>
                        <p><?php printf(__('已完成: %d | 失败: %d', 'content-auto-manager'), $stats['queue_completed'], $stats['queue_failed']); ?></p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="postbox">
            <h2 class="hndle"><?php _e('日志文件管理', 'content-auto-manager'); ?></h2>
            <div class="inside">
                <p><?php _e('管理插件产生的日志文件。日志文件存储在插件根目录下的logs文件夹中。', 'content-auto-manager'); ?></p>
                
                <div class="log-stats" style="margin: 15px 0; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 5px;">
                    <h4><?php _e('日志文件统计', 'content-auto-manager'); ?></h4>
                    <p>
                        <?php printf(
                            __('日志文件数量: <strong>%d</strong> | 总大小: <strong>%s</strong>', 'content-auto-manager'),
                            $log_stats['file_count'],
                            $log_stats['total_size'] > 0 ? size_format($log_stats['total_size']) : '0 B'
                        ); ?>
                    </p>
                    <?php if ($log_stats['latest_file']): ?>
                        <p>
                            <?php printf(
                                __('最新日志: <strong>%s</strong> | 大小: <strong>%s</strong> | 更新时间: <strong>%s</strong>', 'content-auto-manager'),
                                esc_html($log_stats['latest_file']),
                                size_format($log_stats['latest_file_size']),
                                esc_html($log_stats['latest_file_time'])
                            ); ?>
                        </p>
                    <?php else: ?>
                        <p><?php _e('暂无日志文件', 'content-auto-manager'); ?></p>
                    <?php endif; ?>
                </div>
                
                <div class="log-actions" style="margin: 15px 0;">
                    <p><strong><?php _e('警告：此操作将永久删除所有日志文件，且无法恢复！', 'content-auto-manager'); ?></strong></p>
                    
                    <form method="post" id="clear_logs_form" style="display: inline-block;">
                        <?php wp_nonce_field('content_auto_debug_action', 'content_auto_debug_nonce'); ?>
                        <input type="hidden" name="action" value="clear_logs">
                        <button type="button" class="button button-danger" onclick="confirmClearLogs()">
                            <?php _e('清空所有日志文件', 'content-auto-manager'); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="postbox">
            <h2 class="hndle"><?php _e('图像API设置管理', 'content-auto-manager'); ?></h2>
            <div class="inside">
                <p><?php _e('管理图像API设置（存储在WordPress选项系统中，非数据库表）。', 'content-auto-manager'); ?></p>
                
                <?php if (!empty($image_api_settings)): ?>
                <div class="image-api-stats" style="margin: 15px 0; padding: 15px; background: #f0f8ff; border: 1px solid #b3d9ff; border-radius: 5px;">
                    <h4><?php _e('当前图像API设置', 'content-auto-manager'); ?></h4>
                    <p><strong><?php _e('激活的提供商:', 'content-auto-manager'); ?></strong> <span style="color: #0073aa;"><?php echo isset($image_api_settings['provider']) ? esc_html($image_api_settings['provider']) : __('未设置', 'content-auto-manager'); ?></span></p>
                    
                    <?php if (isset($image_api_settings['modelscope'])): ?>
                    <p><strong><?php _e('ModelScope:', 'content-auto-manager'); ?></strong> 
                        <?php echo !empty($image_api_settings['modelscope']['model_id']) ? __('已配置模型ID', 'content-auto-manager') : __('未配置模型ID', 'content-auto-manager'); ?>, 
                        <?php echo !empty($image_api_settings['modelscope']['api_key']) ? __('已配置API密钥', 'content-auto-manager') : __('未配置API密钥', 'content-auto-manager'); ?>
                    </p>
                    <?php endif; ?>
                    
                    <?php if (isset($image_api_settings['openai'])): ?>
                    <p><strong><?php _e('OpenAI:', 'content-auto-manager'); ?></strong> 
                        <?php echo !empty($image_api_settings['openai']['api_key']) ? __('已配置API密钥', 'content-auto-manager') : __('未配置API密钥', 'content-auto-manager'); ?>, 
                        <?php echo isset($image_api_settings['openai']['model']) ? esc_html($image_api_settings['openai']['model']) : __('未设置模型', 'content-auto-manager'); ?>
                    </p>
                    <?php endif; ?>
                    
                    <?php if (isset($image_api_settings['siliconflow'])): ?>
                    <p><strong><?php _e('SiliconFlow:', 'content-auto-manager'); ?></strong> 
                        <?php echo !empty($image_api_settings['siliconflow']['api_key']) ? __('已配置API密钥', 'content-auto-manager') : __('未配置API密钥', 'content-auto-manager'); ?>, 
                        <?php echo isset($image_api_settings['siliconflow']['model']) ? esc_html($image_api_settings['siliconflow']['model']) : __('未设置模型', 'content-auto-manager'); ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="image-api-stats" style="margin: 15px 0; padding: 15px; background: #fff2f2; border: 1px solid #ffcccc; border-radius: 5px;">
                    <p><?php _e('未配置图像API设置。', 'content-auto-manager'); ?></p>
                </div>
                <?php endif; ?>
                
                <div class="image-api-actions" style="margin: 15px 0;">
                    <p><strong><?php _e('警告：以下操作将影响图像API设置，且无法恢复！', 'content-auto-manager'); ?></strong></p>
                    
                    <form method="post" id="clear_image_api_settings_form" style="display: inline-block; margin-right: 10px;">
                        <?php wp_nonce_field('content_auto_debug_action', 'content_auto_debug_nonce'); ?>
                        <input type="hidden" name="action" value="clear_image_api_settings">
                        <button type="button" class="button button-secondary" onclick="confirmClearImageApiSettings()">
                            <?php _e('清空图像API设置', 'content-auto-manager'); ?>
                        </button>
                    </form>
                    
                    <form method="post" id="reset_image_api_settings_form" style="display: inline-block;">
                        <?php wp_nonce_field('content_auto_debug_action', 'content_auto_debug_nonce'); ?>
                        <input type="hidden" name="action" value="reset_image_api_settings">
                        <button type="button" class="button button-primary" onclick="confirmResetImageApiSettings()">
                            <?php _e('重置为默认值', 'content-auto-manager'); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="postbox">
            <h2 class="hndle"><?php _e('自动配图数据管理', 'content-auto-manager'); ?></h2>
            <div class="inside">
                <p><?php _e('管理自动配图功能相关的数据。包括插件表中的配图状态字段和WordPress原生postmeta表中的AI图片数据。', 'content-auto-manager'); ?></p>
                
                <div class="auto-image-stats" style="margin: 15px 0; padding: 15px; background: #f0f8ff; border: 1px solid #b3d9ff; border-radius: 5px;">
                    <h4><?php _e('自动配图数据统计', 'content-auto-manager'); ?></h4>
                    <p><strong><?php _e('插件表数据:', 'content-auto-manager'); ?></strong></p>
                    <ul style="margin-left: 20px;">
                        <li><?php printf(__('已配图文章: %d 篇', 'content-auto-manager'), $stats['articles_with_images']); ?></li>
                        <li><?php printf(__('待配图文章: %d 篇', 'content-auto-manager'), $stats['pending_image_articles']); ?></li>
                    </ul>
                    
                    <p><strong><?php _e('WordPress postmeta表数据:', 'content-auto-manager'); ?></strong></p>
                    <ul style="margin-left: 20px;">
                        <li><?php printf(__('AI生成的图片: %d 张', 'content-auto-manager'), $stats['ai_generated_images']); ?></li>
                        <li><?php printf(__('包含图片占位符的文章: %d 篇', 'content-auto-manager'), $stats['posts_with_image_placeholders']); ?></li>
                    </ul>
                    
                    <p><strong><?php _e('涉及的postmeta字段:', 'content-auto-manager'); ?></strong></p>
                    <ul style="margin-left: 20px; font-family: monospace; font-size: 12px;">
                        <li>_auto_images_processed - <?php _e('文章处理状态', 'content-auto-manager'); ?></li>
                        <li>_auto_images_count - <?php _e('文章图片数量', 'content-auto-manager'); ?></li>
                        <li>_auto_images_processed_time - <?php _e('处理时间', 'content-auto-manager'); ?></li>
                        <li>_ai_generated - <?php _e('AI生成图片标记', 'content-auto-manager'); ?></li>
                        <li>_ai_prompt - <?php _e('图片生成提示词', 'content-auto-manager'); ?></li>
                        <li>_generation_date - <?php _e('图片生成日期', 'content-auto-manager'); ?></li>
                        <li>_source_post_id - <?php _e('来源文章ID', 'content-auto-manager'); ?></li>
                    </ul>
                </div>
                
                <div class="auto-image-actions" style="margin: 15px 0;">
                    <p><strong><?php _e('警告：以下操作将永久删除自动配图相关数据，且无法恢复！', 'content-auto-manager'); ?></strong></p>
                    
                    <form method="post" id="clear_auto_image_postmeta_form" style="display: inline-block;">
                        <?php wp_nonce_field('content_auto_debug_action', 'content_auto_debug_nonce'); ?>
                        <input type="hidden" name="action" value="clear_auto_image_postmeta">
                        <button type="button" class="button button-danger" onclick="confirmClearAutoImagePostmeta()">
                            <?php _e('清理自动配图postmeta数据', 'content-auto-manager'); ?>
                        </button>
                    </form>
                    
                    <p style="margin-top: 10px; font-size: 12px; color: #666;">
                        <?php _e('注意：清理postmeta数据不会删除已生成的图片文件，只会删除相关的元数据记录。插件表中的auto_images_processed和auto_images_count字段可通过上方的"清空所有表数据"操作清理。', 'content-auto-manager'); ?>
                    </p>
                </div>
            </div>
        </div>
        
        <div class="postbox">
            <h2 class="hndle"><?php _e('数据库表结构', 'content-auto-manager'); ?></h2>
            <div class="inside">
                <?php
                $tables = array(
                    'content_auto_api_configs' => __('API配置表', 'content-auto-manager'),
                    'content_auto_brand_profiles' => __('品牌资料表', 'content-auto-manager'),
                    'content_auto_rules' => __('规则表', 'content-auto-manager'),
                    'content_auto_rule_items' => __('规则项目表', 'content-auto-manager'),
                    'content_auto_topic_tasks' => __('主题任务表（父任务）', 'content-auto-manager'),
                    'content_auto_topics' => __('主题表', 'content-auto-manager'),
                    'content_auto_article_tasks' => __('文章任务表（父任务）', 'content-auto-manager'),
                    'content_auto_articles' => __('文章表', 'content-auto-manager'),
                    'content_auto_job_queue' => __('任务队列表（包含所有子任务）', 'content-auto-manager'),
                    'content_auto_publish_rules' => __('发布规则表', 'content-auto-manager'),
                    'content_auto_article_structures' => __('文章结构表', 'content-auto-manager')
                );
                
                foreach ($tables as $table_key => $table_name) {
                    $table_full_name = $wpdb->prefix . $table_key;
                    $exists = $wpdb->get_var("SHOW TABLES LIKE '$table_full_name'") == $table_full_name;
                    
                    echo '<div class="table-info">';
                    echo '<h3>' . esc_html($table_descriptions[$table_key]['name']) . ' (' . esc_html($table_full_name) . ') ';
                    if ($exists) {
                        echo '<span class="status exists">' . __('存在', 'content-auto-manager') . '</span>';
                    } else {
                        echo '<span class="status missing">' . __('不存在', 'content-auto-manager') . '</span>';
                    }
                    echo '</h3>';
                    
                    if (isset($table_descriptions[$table_key]['description'])) {
                        echo '<p><em>' . esc_html($table_descriptions[$table_key]['description']) . '</em></p>';
                    }
                    
                    if ($exists) {
                        // 显示表结构
                        $columns = $wpdb->get_results("SHOW COLUMNS FROM `$table_full_name`");
                        if (!empty($columns)) {
                            echo '<table class="wp-list-table widefat fixed striped">';
                            echo '<thead><tr><th>' . __('字段名', 'content-auto-manager') . '</th><th>' . __('类型', 'content-auto-manager') . '</th><th>' . __('允许NULL', 'content-auto-manager') . '</th><th>' . __('默认值', 'content-auto-manager') . '</th><th>' . __('额外信息', 'content-auto-manager') . '</th><th>' . __('业务说明', 'content-auto-manager') . '</th></tr></thead>';
                            echo '<tbody>';
                            foreach ($columns as $column) {
                                echo '<tr>';

                                // 检查是否为重构新增字段
                                $new_fields = array();
                                if ($table_key === 'content_auto_article_tasks') {
                                    $new_fields = array('current_processing_item', 'total_rule_items', 'generated_articles_count');
                                } elseif ($table_key === 'content_auto_job_queue') {
                                    $new_fields = array('reference_id', 'retry_count', 'scheduled_at');
                                } elseif ($table_key === 'content_auto_topics') {
                                    $new_fields = array('vector_cluster_id', 'vector_status', 'vector_error', 'vector_retry_count', 'reference_material');
                                } elseif ($table_key === 'content_auto_publish_rules') {
                                    $new_fields = array('max_auto_images', 'skip_first_image_placeholder', 'enable_internal_linking', 'publish_interval_minutes', 'enable_brand_profile_insertion', 'brand_profile_position', 'enable_reference_material', 'publish_language');
                                } elseif ($table_key === 'content_auto_api_configs') {
                                    $new_fields = array('vector_api_url', 'vector_api_key', 'vector_model_name');
                                } elseif ($table_key === 'content_auto_articles') {
                                    $new_fields = array('auto_images_processed', 'auto_images_count');
                                } elseif ($table_key === 'content_auto_rules') {
                                    $new_fields = array('reference_material');
                                } elseif ($table_key === 'content_auto_brand_profiles') {
                                    $new_fields = array('type', 'custom_html');
                                }

                                $is_new_field = in_array($column->Field, $new_fields);
                                $field_class = $is_new_field ? 'field-new' : '';

                                echo '<td class="' . $field_class . '">' . esc_html($column->Field);
                                if ($is_new_field) {
                                    echo ' <span style="font-size: 11px; background: #00a32a; color: white; padding: 1px 4px; border-radius: 2px; margin-left: 5px;">重构新增</span>';
                                }
                                echo '</td>';
                                echo '<td>' . esc_html($column->Type) . '</td>';
                                echo '<td>' . esc_html($column->Null) . '</td>';
                                echo '<td>' . esc_html($column->Default ?? 'NULL') . '</td>';
                                echo '<td>' . esc_html($column->Extra) . '</td>';
                                // 添加字段业务说明
                                $field_description = isset($table_descriptions[$table_key]['fields'][$column->Field]) ? $table_descriptions[$table_key]['fields'][$column->Field] : '';
                                echo '<td>' . esc_html($field_description) . '</td>';
                                echo '</tr>';
                            }
                            echo '</tbody></table>';
                        }
                        
                        // 显示表数据（前10条记录）
                        echo '<h4>' . __('数据示例（前10条记录）', 'content-auto-manager') . '</h4>';
                        $table_data = $wpdb->get_results("SELECT * FROM `$table_full_name` ORDER BY updated_at DESC LIMIT 10");
                        
                        if (!empty($table_data)) {
                            // 获取字段名
                            $column_names = array_keys((array)$table_data[0]);
                            
                            // 显示数据表格
                            echo '<table class="wp-list-table widefat fixed striped">';
                            echo '<thead><tr>';
                            foreach ($column_names as $column) {
                                echo '<th>' . esc_html($column) . '</th>';
                            }
                            echo '</tr></thead>';
                            echo '<tbody>';
                            foreach ($table_data as $row) {
                                echo '<tr>';
                                foreach ($column_names as $column) {
                                    // 对所有文本类型字段进行统一截取处理，限制20字符
                                    $cell_value = $row->{$column};
                                    
                                    // 检查字段是否为文本类型，需要进行长度限制
                                    if ($cell_value !== null && is_string($cell_value) && mb_strlen($cell_value, 'UTF-8') > 20) {
                                        $cell_value = mb_substr($cell_value, 0, 20, 'UTF-8') . '...';
                                    }
                                    echo '<td>' . esc_html($cell_value) . '</td>';
                                }
                                echo '</tr>';
                            }
                            echo '</tbody></table>';
                        } else {
                            echo '<p>' . __('表中暂无数据', 'content-auto-manager') . '</p>';
                        }
                    }
                    echo '</div>';
                }
                ?>
            </div>
        </div>
    </div>
</div>

<style>
.status.exists {
    color: green;
    font-weight: bold;
}

.status.missing {
    color: red;
    font-weight: bold;
}

.table-info {
    margin-bottom: 30px;
}

.table-info h3 {
    margin-bottom: 10px;
}

.table-info p {
    margin-bottom: 15px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.stat-item {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    padding: 15px;
}

.stat-item h4 {
    margin: 0 0 10px 0;
    color: #495057;
    font-size: 14px;
    font-weight: 600;
}

.stat-item p {
    margin: 5px 0;
    font-size: 13px;
    color: #6c757d;
}
</style>



<script>



// 确认清空日志文件
function confirmClearLogs() {
    if (confirm('<?php _e('确定要清空所有日志文件吗？\\n\\n此操作将永久删除logs目录下的所有.log文件，且无法恢复！\\n\\n请确认您已备份重要日志后再继续。', 'content-auto-manager'); ?>')) {
        if (confirm('<?php _e('最后确认：\\n\\n您真的要删除所有日志文件吗？\\n\\n点击"确定"将继续删除，点击"取消"将放弃操作。', 'content-auto-manager'); ?>')) {
            document.getElementById('clear_logs_form').submit();
        }
    }
}

// 确认清空图像API设置
function confirmClearImageApiSettings() {
    if (confirm('<?php _e('确定要清空图像API设置吗？\\n\\n此操作将删除所有图像API提供商的配置，且无法恢复！\\n\\n请确认您已备份重要配置后再继续。', 'content-auto-manager'); ?>')) {
        if (confirm('<?php _e('最后确认：\\n\\n您真的要清空图像API设置吗？\\n\\n点击"确定"将继续删除，点击"取消"将放弃操作。', 'content-auto-manager'); ?>')) {
            // 提交清空图像API设置的表单
            var form = document.getElementById('clear_image_api_settings_form');
            if (form) {
                form.submit();
            }
        }
    }
}

// 确认重置图像API设置
function confirmResetImageApiSettings() {
    if (confirm('<?php _e('确定要重置图像API设置为默认值吗？\\n\\n此操作将覆盖所有当前配置，且无法恢复！\\n\\n请确认您已备份重要配置后再继续。', 'content-auto-manager'); ?>')) {
        if (confirm('<?php _e('最后确认：\\n\\n您真的要重置图像API设置吗？\\n\\n点击"确定"将继续重置，点击"取消"将放弃操作。', 'content-auto-manager'); ?>')) {
            // 提交重置图像API设置的表单
            var form = document.getElementById('reset_image_api_settings_form');
            if (form) {
                form.submit();
            }
        }
    }
}

// 确认清理自动配图postmeta数据
function confirmClearAutoImagePostmeta() {
    if (confirm('<?php _e('确定要清理自动配图postmeta数据吗？\\n\\n此操作将永久删除所有自动配图相关的postmeta记录，且无法恢复！\\n\\n请确认您已备份重要数据后再继续。', 'content-auto-manager'); ?>')) {
        if (confirm('<?php _e('最后确认：\\n\\n您真的要清理自动配图postmeta数据吗？\\n\\n点击"确定"将继续删除，点击"取消"将放弃操作。', 'content-auto-manager'); ?>')) {
            // 提交清理自动配图postmeta数据的表单
            var form = document.getElementById('clear_auto_image_postmeta_form');
            if (form) {
                form.submit();
            }
        }
    }
}

// 确认清理历史队列任务
function confirmClearCompletedTasks() {
    if (confirm('<?php _e('确定要清理历史队列任务吗？\\n\\n此操作将删除以下三个表中所有状态为"completed"的记录：\\n\\n• wp_content_auto_job_queue\\n• wp_content_auto_topic_tasks\\n• wp_content_auto_article_tasks\\n\\n此操作无法恢复！\\n\\n请确认您已备份重要数据后再继续。', 'content-auto-manager'); ?>')) {
        if (confirm('<?php _e('最后确认：\\n\\n您真的要清理所有已完成的队列任务记录吗？\\n\\n点击"确定"将继续删除，点击"取消"将放弃操作。', 'content-auto-manager'); ?>')) {
            // 提交清理历史队列任务的表单
            var form = document.getElementById('clear_completed_tasks_form');
            if (form) {
                form.submit();
            }
        }
    }
}

// 调试模式控制功能
jQuery(document).ready(function($) {
    $('#enable-debug-mode').on('click', function() {
        var button = $(this);
        button.prop('disabled', true).text('启用中...');
        
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'content_auto_toggle_debug_mode',
                mode: 'enable',
                nonce: '<?php echo wp_create_nonce("debug_mode_toggle"); ?>'
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('启用失败：' + (response.data || '未知错误'));
                    button.prop('disabled', false).text('✅ 启用调试模式');
                }
            },
            error: function() {
                alert('请求失败，请重试');
                button.prop('disabled', false).text('✅ 启用调试模式');
            }
        });
    });
    
    $('#disable-debug-mode').on('click', function() {
        var button = $(this);
        button.prop('disabled', true).text('关闭中...');
        
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'content_auto_toggle_debug_mode',
                mode: 'disable',
                nonce: '<?php echo wp_create_nonce("debug_mode_toggle"); ?>'
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('关闭失败：' + (response.data || '未知错误'));
                    button.prop('disabled', false).text('❌ 关闭调试模式');
                }
            },
            error: function() {
                alert('请求失败，请重试');
                button.prop('disabled', false).text('❌ 关闭调试模式');
            }
        });
    });
    
    $('#view-debug-logs').on('click', function() {
        var button = $(this);
        var logsContainer = $('#debug-logs-content');
        var logsDisplay = $('#logs-display');
        
        if (logsContainer.is(':visible')) {
            logsContainer.hide();
            button.text('📄 查看调试日志');
            return;
        }
        
        button.prop('disabled', true).text('加载中...');
        
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'content_auto_get_debug_logs',
                nonce: '<?php echo wp_create_nonce("debug_logs_view"); ?>'
            },
            success: function(response) {
                if (response.success) {
                    logsDisplay.text(response.data.logs || '暂无日志内容');
                    logsContainer.show();
                    button.text('🔼 隐藏日志');
                } else {
                    alert('获取日志失败：' + (response.data || '未知错误'));
                }
                button.prop('disabled', false);
            },
            error: function() {
                alert('请求失败，请重试');
                button.prop('disabled', false).text('📄 查看调试日志');
            }
        });
    });
    
    $('#clear-debug-logs').on('click', function() {
        if (!confirm('确定要清空所有调试日志吗？此操作不可逆！')) {
            return;
        }
        
        var button = $(this);
        button.prop('disabled', true).text('清空中...');
        
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'content_auto_clear_debug_logs',
                nonce: '<?php echo wp_create_nonce("debug_logs_clear"); ?>'
            },
            success: function(response) {
                if (response.success) {
                    alert('日志已清空');
                    $('#debug-logs-content').hide();
                    $('#view-debug-logs').text('📄 查看调试日志');
                } else {
                    alert('清空失败：' + (response.data || '未知错误'));
                }
                button.prop('disabled', false).text('🗑️ 清空日志');
            },
            error: function() {
                alert('请求失败，请重试');
                button.prop('disabled', false).text('🗑️ 清空日志');
            }
        });
    });
});
</script>


