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
    wp_die(__('抱歉，您没有权限访问此页面。', 'yali-ai-writer'));
}

// 获取图像API设置
$image_api_settings = get_option('cam_image_api_settings', array());

// 处理表单提交
$message = '';
if (isset($_POST['action']) && isset($_POST['yali_ai_writer_debug_nonce'])) {
    // 验证nonce
            if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['yali_ai_writer_debug_nonce'])), 'yali_ai_writer_debug_action')) {
                wp_die(__('安全验证失败。', 'yali-ai-writer'));
            }    
    $database = new Yali_AI_Writer_Database();
    $table_prefix = $database->get_table_prefix();
    
    $action = sanitize_text_field(wp_unslash($_POST['action']));
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
                'yali_ai_writer_article_structures'
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
                'yali_ai_writer_article_structures'
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
                $error = true;
            }
            break;
            
        case 'update_database':
            // 更新数据库表结构
            $result = yali_ai_writer_manager_update_database_structure();
            if ($result['success']) {
                $message = __('数据库表结构已更新到最新版本。所有必要字段已同步。', 'yali-ai-writer');
            } else {
                $message = __('数据库更新过程中出现错误：', 'yali-ai-writer') . implode('; ', $result['errors']);
                $error = true;
            }
            break;
            
        case 'clear_logs':
            // 清空所有日志文件
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
            
            $message = sprintf(__('已清理 %d 条自动配图相关的postmeta记录。', 'yali-ai-writer'), $deleted_count);
            break;
    }
}

// 重新获取更新后的图像API设置
$image_api_settings = get_option('cam_image_api_settings', array());

// 获取数据库统计
$stats = array();

// API配置统计
$api_configs_table = $wpdb->prefix . 'yali_ai_writer_api_configs';
$stats['api_configs'] = $wpdb->get_var("SELECT COUNT(*) FROM {$api_configs_table}");
$stats['active_api_configs'] = $wpdb->get_var("SELECT COUNT(*) FROM {$api_configs_table} WHERE is_active = 1");

// 品牌资料统计
$brand_profiles_table = $wpdb->prefix . 'yali_ai_writer_brand_profiles';
$stats['brand_profiles'] = $wpdb->get_var("SELECT COUNT(*) FROM {$brand_profiles_table}");

// 规则统计
$rules_table = $wpdb->prefix . 'yali_ai_writer_rules';
$stats['rules'] = $wpdb->get_var("SELECT COUNT(*) FROM {$rules_table}");
$stats['active_rules'] = $wpdb->get_var("SELECT COUNT(*) FROM {$rules_table} WHERE status = 1");

// 规则项目统计
$rule_items_table = $wpdb->prefix . 'yali_ai_writer_rule_items';
$stats['rule_items'] = $wpdb->get_var("SELECT COUNT(*) FROM {$rule_items_table}");

// 主题任务统计
$topic_tasks_table = $wpdb->prefix . 'yali_ai_writer_topic_tasks';
$stats['topic_tasks'] = $wpdb->get_var("SELECT COUNT(*) FROM {$topic_tasks_table}");
$stats['pending_topic_tasks'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$topic_tasks_table} WHERE status = %s", YALI_AI_WRITER_STATUS_PENDING));
$stats['processing_topic_tasks'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$topic_tasks_table} WHERE status = %s", YALI_AI_WRITER_STATUS_PROCESSING));
$stats['completed_topic_tasks'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$topic_tasks_table} WHERE status = %s", YALI_AI_WRITER_STATUS_COMPLETED));
$stats['failed_topic_tasks'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$topic_tasks_table} WHERE status = %s", YALI_AI_WRITER_STATUS_FAILED));

// 主题统计
$topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
$stats['topics'] = $wpdb->get_var("SELECT COUNT(*) FROM {$topics_table}");
$stats['unused_topics'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$topics_table} WHERE status = %s", YALI_AI_WRITER_TOPIC_UNUSED));
$stats['queued_topics'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$topics_table} WHERE status = %s", YALI_AI_WRITER_TOPIC_QUEUED));
$stats['used_topics'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$topics_table} WHERE status = %s", YALI_AI_WRITER_TOPIC_USED));

// 文章任务统计
$article_tasks_table = $wpdb->prefix . 'yali_ai_writer_article_tasks';
$stats['article_jobs'] = $wpdb->get_var("SELECT COUNT(*) FROM {$article_tasks_table}");
$stats['pending_article_jobs'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$article_tasks_table} WHERE status = %s", YALI_AI_WRITER_STATUS_PENDING));
$stats['processing_article_jobs'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$article_tasks_table} WHERE status = %s", YALI_AI_WRITER_STATUS_PROCESSING));
$stats['completed_article_jobs'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$article_tasks_table} WHERE status = %s", YALI_AI_WRITER_STATUS_COMPLETED));
$stats['failed_article_jobs'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$article_tasks_table} WHERE status = %s", YALI_AI_WRITER_STATUS_FAILED));

// 文章统计
$articles_table = $wpdb->prefix . 'yali_ai_writer_articles';
$stats['articles'] = $wpdb->get_var("SELECT COUNT(*) FROM {$articles_table}");
$stats['articles_with_images'] = $wpdb->get_var("SELECT COUNT(*) FROM {$articles_table} WHERE auto_images_processed = 1");
$stats['pending_image_articles'] = $wpdb->get_var("SELECT COUNT(*) FROM {$articles_table} WHERE auto_images_processed = 0");

// 自动配图统计（从postmeta表）
$stats['ai_generated_images'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_ai_generated' AND meta_value = '1'");
$stats['posts_with_image_placeholders'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE '%<!-- image prompt:%' AND post_status IN ('publish', 'draft', 'future')");

// 文章结构统计
$article_structures_table = $wpdb->prefix . 'yali_ai_writer_article_structures';
$stats['article_structures'] = $wpdb->get_var("SELECT COUNT(*) FROM {$article_structures_table}");

// 队列统计
$queue_table = $wpdb->prefix . 'yali_ai_writer_job_queue';
$stats['queue_total'] = $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table}");
$stats['queue_pending'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue_table} WHERE status = %s", YALI_AI_WRITER_STATUS_PENDING));
$stats['queue_processing'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue_table} WHERE status = %s", YALI_AI_WRITER_STATUS_PROCESSING));
$stats['queue_completed'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue_table} WHERE status = %s", YALI_AI_WRITER_STATUS_COMPLETED));
$stats['queue_failed'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue_table} WHERE status = %s", YALI_AI_WRITER_STATUS_FAILED));

// 获取日志文件统计信息
$logs_dir = YALI_AI_WRITER_PLUGIN_DIR . 'logs';
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
    'yali_ai_writer_api_configs' => array(
        'name' => 'API配置表',
        'description' => '存储大模型API的配置信息',
        'fields' => array(
            'id' => '配置唯一标识符',
            'name' => '配置名称',
            'api_url' => 'API地址',
            'api_key' => 'API密钥',
            'model_name' => '模型名称',
            'temperature' => __('温度参数，控制输出的随机性', 'yali-ai-writer'),
            'max_tokens' => __('最大Token数，控制输出长度', 'yali-ai-writer'),
            'temperature_enabled' => __('是否启用温度参数', 'yali-ai-writer'),
            'max_tokens_enabled' => __('是否启用最大Token数参数', 'yali-ai-writer'),
            'is_active' => __('是否为激活配置', 'yali-ai-writer'),
            'predefined_channel' => __('预置API渠道（如pollinations等）', 'yali-ai-writer'),
            'vector_api_url' => __('向量API地址', 'yali-ai-writer'),
            'vector_api_key' => __('向量API密钥', 'yali-ai-writer'),
            'vector_model_name' => __('向量模型名称', 'yali-ai-writer'),
            'vector_api_type' => __('向量API类型（openai/jina）', 'yali-ai-writer'),
            'stream' => __('是否启用流式输出', 'yali-ai-writer'),
            'top_p' => __('核采样参数', 'yali-ai-writer'),
            'stream_enabled' => __('是否启用stream参数控制', 'yali-ai-writer'),
            'top_p_enabled' => __('是否启用top_p参数控制', 'yali-ai-writer'),
            'created_at' => '创建时间',
            'updated_at' => '更新时间'
        )
    ),
    'yali_ai_writer_brand_profiles' => array(
        'name' => '品牌资料表',
        'description' => '存储品牌资料信息，用于文章插入时的品牌内容匹配',
        'fields' => array(
            'id' => '品牌资料唯一标识符',
            'title' => '品牌/产品名称',
            'type' => '物料类型：standard标准样式，custom_html自定义HTML，reference参考资料',
            'image_url' => '图片URL',
            'description' => '描述信息',
            'link' => __('相关链接', 'yali-ai-writer'),
            'custom_html' => __('自定义HTML代码', 'yali-ai-writer'),
            'vector' => __('向量数据（JSON格式）', 'yali-ai-writer'),
            'created_at' => '创建时间',
            'updated_at' => '更新时间'
        )
    ),
    'yali_ai_writer_rules' => array(
        'name' => '规则表',
        'description' => '存储内容生成规则',
        'fields' => array(
            'id' => '规则唯一标识符',
            'rule_name' => '规则名称',
            'rule_type' => __('规则类型（random_selection、fixed_articles、upload_text）', 'yali-ai-writer'),
            'rule_conditions' => __('规则条件（序列化存储，根据不同规则类型存储分类ID、文章ID或上传文本内容）', 'yali-ai-writer'),
            'item_count' => __('规则项目数量', 'yali-ai-writer'),
            'rule_task_id' => '规则任务ID',
            'reference_material' => '规则级参考资料，用于文章生成提示词，最多500字符',
            'status' => __('规则状态（1启用，0禁用）', 'yali-ai-writer'),
            'created_at' => '创建时间',
            'updated_at' => '更新时间'
        )
    ),
    'yali_ai_writer_rule_items' => array(
        'name' => '规则项目表',
        'description' => '存储规则的具体项目内容',
        'fields' => array(
            'id' => '项目唯一标识符',
            'rule_id' => '关联的规则ID',
            'rule_task_id' => '规则任务ID',
            'post_id' => '关联的文章ID',
            'post_title' => '文章标题',
            'category_ids' => '分类ID列表',
            'category_names' => '分类名称列表',
            'category_descriptions' => '分类描述列表',
            'tag_names' => '标签名称列表',
            'upload_text' => '上传的文本内容',
            'created_at' => '创建时间',
            'updated_at' => '更新时间'
        )
    ),
    'yali_ai_writer_topic_tasks' => array(
        'name' => '主题任务表',
        'description' => '存储主题生成任务信息',
        'fields' => array(
            'id' => '任务唯一标识符',
            'topic_task_id' => '主题任务ID，用于全局查询的唯一ID',
            'rule_id' => '关联的规则ID',
            'topic_count_per_item' => '每个规则项目生成的主题数量',
            'total_rule_items' => '规则项目总数',
            'total_expected_topics' => '预期生成主题总数',
            'current_processing_item' => '当前处理的规则项目索引',
            'generated_topics_count' => '已生成主题数量',
            'status' => '任务状态（pending、processing、completed、failed）',
            'error_message' => '错误信息',
            'subtask_status' => '子任务状态JSON存储',
            'last_processed_at' => '最后处理时间',
            'created_at' => '创建时间',
            'updated_at' => '更新时间'
        )
    ),
    'yali_ai_writer_job_queue' => array(
            'name' => '任务队列表',
            'description' => '存储系统中所有待处理的任务队列（包括主题生成、文章生成等）',
            'fields' => array(
                'id' => '队列项唯一标识符',
                'job_type' => '任务类型（topic_task、article等）',
                'job_id' => '关联的任务ID（指向具体任务表的主键ID，根据job_type字段确定具体表，如yali_ai_writer_topic_tasks表的id）',
                'subtask_id' => '子任务ID，用于唯一标识同一任务中的不同子任务',
                'reference_id' => '引用ID，用于存储文章任务中的主题ID，article任务类型时有效（重构新增）',
                'priority' => '任务优先级',
                'retry_count' => '重试次数，记录任务重试的次数（重构新增）',
                'scheduled_at' => '计划执行时间，用于定时任务调度',
                'status' => '任务状态（pending、processing、completed、failed）',
                'error_message' => '错误信息',
                'created_at' => '创建时间',
                'updated_at' => '更新时间'
            )
        ),
    'yali_ai_writer_topics' => array(
        'name' => '主题表',
        'description' => '存储生成的主题内容及结构化数据，包括API配置信息和向量数据',
        'fields' => array(
            'id' => '主题唯一标识符',
            'task_id' => '关联的主题任务唯一标识符（来自yali_ai_writer_topic_tasks表的topic_task_id字段）',
            'rule_id' => '关联的规则ID',
            'rule_item_index' => '来源规则项目索引',
            'title' => '主题标题',
            'source_angle' => '内容角度',
            'user_value' => '用户价值描述',
            'seo_keywords' => 'SEO关键词（JSON格式）',
            'matched_category' => '推荐匹配分类',
            'priority_score' => '优先级评分（1-5）',
            'status' => '主题状态（unused、used）',
            'api_config_id' => '关联的API配置ID，用于指定生成主题时使用的API配置',
            'api_config_name' => 'API配置名称，记录生成主题时使用的具体API配置名称',
            'vector_embedding' => '主题向量嵌入数据（JSON格式），用于存储1024维向量数据',
            'vector_cluster_id' => '向量聚类ID，用于主题聚类分析',
            'vector_status' => '向量生成状态（pending、completed、failed）',
            'vector_error' => '向量生成错误信息',
            'vector_retry_count' => '向量生成重试次数',
            'reference_material' => '主题级参考资料，优先于规则级参考资料，最多500字符',
            'material_search_status' => '素材搜索状态（none/waiting_for_extension/completed/failed）',
            'material_search_error' => '自动素材搜索错误信息',
            'created_at' => '创建时间',
            'updated_at' => '更新时间'
        )
    ),
    'yali_ai_writer_article_tasks' => array(
        'name' => '文章任务表',
        'description' => '存储文章生成父任务信息，实现与主题任务相同的父子任务架构',
        'fields' => array(
            'id' => '任务唯一标识符',
            'article_task_id' => '任务ID，用于全局查询的唯一ID',
            'name' => '任务名称',
            'topic_ids' => '关联的主题ID列表（JSON格式）',
            'status' => '任务状态（pending、processing、completed、failed）',
            'subtask_status' => '子任务状态JSON存储',
            'error_message' => '错误信息',
            'total_topics' => '主题总数',
            'completed_topics' => '已完成主题数',
            'failed_topics' => '失败主题数',
            'current_processing_item' => '当前处理的子任务数量',
            'total_rule_items' => '总子任务数量',
            'generated_articles_count' => '已生成文章数量',
            'last_processed_at' => '最后处理时间',
            'created_at' => '创建时间',
            'updated_at' => '更新时间'
        )
    ),
    'yali_ai_writer_articles' => array(
        'name' => '文章表',
        'description' => '存储生成的文章内容',
        'fields' => array(
            'id' => '文章唯一标识符',
            'job_id' => '关联的任务ID',
            'topic_id' => '关联的主题ID',
            'post_id' => '关联的WordPress文章ID',
            'title' => '文章标题',
            'content' => '文章内容',
            'status' => '文章状态（pending、success、failed）',
            'error_message' => '错误信息',
            'processing_time' => '处理耗时(秒)',
            'word_count' => '文章字数',
            'api_config_id' => '关联的API配置ID，用于指定生成文章时使用的API配置',
            'api_config_name' => 'API配置名称，记录生成文章时使用的具体API配置名称',
            'auto_images_processed' => '是否已处理自动配图（0未处理、1已处理）',
            'auto_images_count' => '生成的图片数量',
            'created_at' => '创建时间',
            'updated_at' => '更新时间'
        )
    ),
    'yali_ai_writer_publish_rules' => array(
        'name' => '发布规则表',
        'description' => '存储文章发布的规则配置，包括内链功能和发布间隔设置',
        'fields' => array(
            'id' => '规则唯一标识符',
            'post_status' => '文章发布状态（draft、publish等）',
            'author_id' => '文章作者ID',
            'category_mode' => '分类选择模式（manual手动、auto自动）',
            'category_ids' => '手动选择的分类ID列表（序列化存储）',
            'fallback_category_ids' => '自动分类失败时的备用分类ID列表（序列化存储）',
            'target_length' => '目标文章长度（如800-1500）',
            'knowledge_depth' => '内容深度（浅层普及、实用指导、深度分析、全面综述）',
            'reader_role' => '目标受众（潜在客户、现有客户、行业同仁、决策者、泛流量用户）',
            'normalize_output' => '是否启用输出规范化（0关闭、1启用）',
            'auto_image_insertion' => '是否启用文章自动配图（0关闭、1启用）',
            'max_auto_images' => '最大自动生成图片数量（1-5张）',
            'skip_first_image_placeholder' => '是否跳过首个图片占位符（0关闭、1启用）',
            'enable_internal_linking' => __('是否启用文章内链功能（0关闭、1启用）', 'yali-ai-writer'),
            'publish_interval_minutes' => '发布间隔时间（分钟），0表示立即发布',
            'enable_brand_profile_insertion' => '是否启用品牌资料插入功能（0关闭、1启用）',
            'brand_profile_position' => '品牌资料插入位置（before_second_paragraph或article_end）',
            'enable_reference_material' => '是否启用参考资料功能（0关闭、1启用）',
            'enable_ai_reference_select' => '是否启用大模型精选召回（0关闭、1启用）',
            'enable_auto_material_search' => '是否启用自动素材搜索（0关闭、1启用，兼容旧版本）',
            'material_collection_mode' => '素材收集模式（none关闭、search_engine网络搜索、extension_rag知识库搜索）',
            'enable_intent_inference' => '是否启用搜索意图推断（0关闭、1启用）',
            'publish_language' => '发布语言代码（如zh-CN、en-US等），影响内容生成的输出语言',
            'role_description' => 'AI角色描述，用于文章生成的提示词模板',
            'image_prompt_template' => '图片生成提示词模板，用于指导AI生成图片占位符',
            'created_at' => '创建时间',
            'updated_at' => '更新时间'
        )
    ),
    'yali_ai_writer_article_structures' => array(
        'name' => '文章结构表',
        'description' => '存储不同内容角度的文章结构模板，用于指导AI生成结构化的文章内容',
        'fields' => array(
            'id' => '结构唯一标识符',
            'content_angle' => '内容角度，如产品介绍、使用指南、行业分析等',
            'title' => '结构标题模板',
            'structure' => '文章结构定义（JSON格式），包含章节、段落等结构信息',
            'title_vector' => '标题向量数据，用于结构相似度匹配',
            'usage_count' => '使用次数统计，用于跟踪结构模板的受欢迎程度',
            'created_at' => '创建时间',
            'updated_at' => '更新时间'
        )
    ),
    'yali_ai_writer_prompt_templates' => array(
        'name' => '提示词模板表',
        'description' => '存储内容生成所使用的提示词模板，支持文章生成和主题生成等多种类型',
        'fields' => array(
            'id' => '模板唯一标识符',
            'name' => '模板名称',
            'template_type' => '模板类型（topic_generation、article_generation）',
            'content' => '模板内容（XML格式）',
            'source_file' => '源文件名，用于防止重复导入和版本追踪',
            'is_active' => '是否激活（1启用，0禁用）',
            'created_at' => '创建时间',
            'updated_at' => '更新时间'
        )
    )
);
?>

<div class="wrap yali-plugin-wrapper">
    <h1 class="yali-page-title"><span class="dashicons dashicons-hammer"></span> <?php _e('调试工具', 'yali-ai-writer'); ?></h1>
    
    <!-- 调试模式控制面板 -->
    <div class="debug-mode-control yali-card" style="margin-top:20px;">
        <h2 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:15px; margin-bottom:15px;"><?php _e('🔧 调试模式控制', 'yali-ai-writer'); ?></h2>
        <?php
        $debug_mode = get_option('yali_ai_writer_debug_mode', false);
        $status_class = $debug_mode ? 'yali-notice-success' : 'yali-notice-info';
        $status_text = $debug_mode ? __('已启用', 'yali-ai-writer') : __('已禁用', 'yali-ai-writer');
        $status_icon = $debug_mode ? '✅' : '❌';
        ?>
        
        <div class="yali-notice <?php echo esc_attr($status_class); ?>">
            <p><strong><?php echo esc_html($status_icon); ?> <?php _e('当前状态：调试模式', 'yali-ai-writer'); ?><?php echo esc_html($status_text); ?></strong></p>
            <?php if ($debug_mode): ?>
            <p>📂 <?php _e('日志位置：', 'yali-ai-writer'); ?><code><?php echo YALI_AI_WRITER_PLUGIN_DIR; ?>logs/<?php echo date('Y-m-d'); ?>.log</code></p>
            <p>⚠️ <?php _e('调试模式会记录完整的API提示词，建议获取所需日志后及时关闭。', 'yali-ai-writer'); ?></p>
            <?php else: ?>
            <p>💡 <?php _e('启用后将记录完整的主题生成和文章生成API提示词到日志文件。', 'yali-ai-writer'); ?></p>
            <?php endif; ?>
        </div>
        
        <div class="debug-mode-actions" style="margin-top: 20px;">
            <?php if ($debug_mode): ?>
            <button type="button" class="button button-secondary yali-btn yali-btn-secondary" id="disable-debug-mode">
                ❌ <?php _e('关闭调试模式', 'yali-ai-writer'); ?>
            </button>
            <button type="button" class="button button-primary yali-btn yali-btn-primary" id="view-debug-logs" style="margin-left: 10px;"
                data-view-text="📄 <?php echo esc_attr__('查看调试日志', 'yali-ai-writer'); ?>"
                data-hide-text="🔼 <?php echo esc_attr__('隐藏日志', 'yali-ai-writer'); ?>"
                data-loading-text="⌛ <?php echo esc_attr__('加载中...', 'yali-ai-writer'); ?>">
                📄 <?php _e('查看调试日志', 'yali-ai-writer'); ?>
            </button>
            <?php else: ?>
            <button type="button" class="button button-primary yali-btn yali-btn-primary" id="enable-debug-mode">
                ✅ <?php _e('启用调试模式', 'yali-ai-writer'); ?>
            </button>
            <?php endif; ?>
        </div>
        
        <div id="debug-logs-content" style="display: none; margin-top: 20px; background: #f9f9f9; padding: 15px; border-radius: 5px; max-height: 400px; overflow-y: auto;">
            <h4>📋 <?php _e('最新调试日志', 'yali-ai-writer'); ?></h4>
            <pre id="logs-display" style="background: #fff; padding: 10px; border: 1px solid #ddd; font-size: 12px; line-height: 1.4;"></pre>
        </div>
    </div>
      
    <?php if (!empty($message)): ?>
        <div class="notice notice-success is-dismissible yali-notice yali-notice-success">
            <p><?php echo esc_html($message); ?></p>
        </div>
    <?php endif; ?>
    
    <div class="tools-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap:20px; margin-top:20px;">
        
        <div class="yali-card">
            <h2 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:15px; margin-bottom:15px;"><?php _e('数据库表操作', 'yali-ai-writer'); ?></h2>
            <div class="yali-card-content">
                <p class="description yali-desc" style="color:#d63638;"><?php _e('⚠️ 警告：以下操作将影响数据库中的数据，请谨慎使用。', 'yali-ai-writer'); ?></p>
                
                <div style="display:flex; flex-wrap:wrap; gap:10px; margin-top:15px;">
                    <form method="post">
                        <?php wp_nonce_field('yali_ai_writer_debug_action', 'yali_ai_writer_debug_nonce'); ?>
                        <input type="hidden" name="action" value="truncate_tables">
                        <button type="submit" class="button button-secondary yali-btn yali-btn-secondary"><?php _e('清空所有表数据', 'yali-ai-writer'); ?></button>
                    </form>
                    
                    <form method="post">
                        <?php wp_nonce_field('yali_ai_writer_debug_action', 'yali_ai_writer_debug_nonce'); ?>
                        <input type="hidden" name="action" value="drop_tables">
                        <button type="submit" class="button button-secondary yali-btn yali-btn-secondary"><?php _e('删除所有表', 'yali-ai-writer'); ?></button>
                    </form>
                    
                    <form method="post">
                        <?php wp_nonce_field('yali_ai_writer_debug_action', 'yali_ai_writer_debug_nonce'); ?>
                        <input type="hidden" name="action" value="recreate_tables">
                        <button type="submit" class="button button-primary yali-btn yali-btn-primary"><?php _e('重新创建所有表', 'yali-ai-writer'); ?></button>
                    </form>
                    
                    <form method="post">
                        <?php wp_nonce_field('yali_ai_writer_debug_action', 'yali_ai_writer_debug_nonce'); ?>
                        <input type="hidden" name="action" value="update_database">
                         <button type="submit" class="button button-primary yali-btn yali-btn-primary"><?php _e('更新数据库表结构', 'yali-ai-writer'); ?></button>
                    </form>

                    <form method="post" id="clear_completed_tasks_form">
                        <?php wp_nonce_field('yali_ai_writer_debug_action', 'yali_ai_writer_debug_nonce'); ?>
                        <input type="hidden" name="action" value="clear_completed_tasks">
                        <button type="button" class="button button-secondary yali-btn yali-btn-secondary" onclick="confirmClearCompletedTasks()">
                            <?php _e('清理历史队列任务', 'yali-ai-writer'); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="yali-card">
            <h2 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:15px; margin-bottom:15px;"><?php _e('数据库统计', 'yali-ai-writer'); ?></h2>
            <div class="yali-card-content">
                <div class="stats-grid" style="grid-template-columns: repeat(2, 1fr);">
                    <div class="stat-item">
                        <h4><?php _e('API配置', 'yali-ai-writer'); ?></h4>
                        <p><?php printf(esc_html__('总数: %d | 激活: %d', 'yali-ai-writer'), intval($stats['api_configs']), intval($stats['active_api_configs'])); ?></p>
                    </div>

                    <div class="stat-item">
                        <h4><?php _e('品牌资料', 'yali-ai-writer'); ?></h4>
                        <p><?php printf(esc_html__('总数: %d', 'yali-ai-writer'), intval($stats['brand_profiles'])); ?></p>
                    </div>

                    <div class="stat-item">
                        <h4><?php _e('规则', 'yali-ai-writer'); ?></h4>
                        <p><?php printf(esc_html__('总数: %d | 激活: %d', 'yali-ai-writer'), intval($stats['rules']), intval($stats['active_rules'])); ?></p>
                    </div>
                    
                    <div class="stat-item">
                        <h4><?php _e('主题任务/主题', 'yali-ai-writer'); ?></h4>
                        <p><?php printf(esc_html__('任务: %d | 主题: %d', 'yali-ai-writer'), intval($stats['topic_tasks']), intval($stats['topics'])); ?></p>
                    </div>
                    
                    <div class="stat-item">
                        <h4><?php _e('文章任务/文章', 'yali-ai-writer'); ?></h4>
                        <p><?php printf(esc_html__('任务: %d | 文章: %d', 'yali-ai-writer'), intval($stats['article_jobs']), intval($stats['articles'])); ?></p>
                    </div>
                    
                    <div class="stat-item">
                        <h4><?php _e('任务队列', 'yali-ai-writer'); ?></h4>
                        <p><?php printf(esc_html__('总数: %d | 待处理: %d', 'yali-ai-writer'), intval($stats['queue_total']), intval($stats['queue_pending'])); ?></p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="yali-card">
            <h2 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:15px; margin-bottom:15px;"><?php _e('日志文件管理', 'yali-ai-writer'); ?></h2>
            <div class="yali-card-content">
                <p class="description yali-desc" style="margin-bottom:15px;"><?php _e('管理插件产生的日志文件。日志文件存储在插件根目录下的logs文件夹中。', 'yali-ai-writer'); ?></p>
                
                <div class="log-stats" style="margin: 15px 0; padding: 15px; background: #f9f9f9; border-radius: 4px;">
                    <h4 style="margin-top:0;"><?php _e('日志文件统计', 'yali-ai-writer'); ?></h4>
                    <p>
                        <?php printf(
                            esc_html__('日志文件数量: %s | 总大小: %s', 'yali-ai-writer'),
                            '<strong>' . intval($log_stats['file_count']) . '</strong>',
                            '<strong>' . ($log_stats['total_size'] > 0 ? size_format($log_stats['total_size']) : '0 B') . '</strong>'
                        ); ?>
                    </p>
                    <?php if ($log_stats['latest_file']): ?>
                        <p style="margin-bottom:0;">
                            <?php printf(
                                __('最新日志: %s | 大小: %s', 'yali-ai-writer'),
                                '<strong>' . esc_html($log_stats['latest_file']) . '</strong>',
                                '<strong>' . size_format($log_stats['latest_file_size']) . '</strong>'
                            ); ?>
                        </p>
                    <?php else: ?>
                        <p style="margin-bottom:0;"><?php _e('暂无日志文件', 'yali-ai-writer'); ?></p>
                    <?php endif; ?>
                </div>
                
                <div class="log-actions" style="margin-top: 20px;">
                    <form method="post" id="clear_logs_form">
                        <?php wp_nonce_field('yali_ai_writer_debug_action', 'yali_ai_writer_debug_nonce'); ?>
                        <input type="hidden" name="action" value="clear_logs">
                        <button type="button" class="button button-danger yali-btn yali-btn-danger" onclick="confirmClearLogs()">
                            🗑️ <?php _e('清空所有日志文件', 'yali-ai-writer'); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="yali-card">
            <h2 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:15px; margin-bottom:15px;"><?php _e('图像API设置管理', 'yali-ai-writer'); ?></h2>
            <div class="yali-card-content">
                <p class="description yali-desc"><?php _e('管理图像API设置（存储在WordPress选项系统中）。', 'yali-ai-writer'); ?></p>
                
                <?php if (!empty($image_api_settings)): ?>
                <div class="image-api-stats" style="margin: 15px 0; padding: 15px; background: #f0f8ff; border: 1px solid #b3d9ff; border-radius: 4px;">
                    <h4 style="margin-top:0;"><?php _e('当前图像API设置', 'yali-ai-writer'); ?></h4>
                    <p><strong><?php _e('激活的提供商', 'yali-ai-writer'); ?>:</strong> <span style="color: #0073aa;"><?php echo isset($image_api_settings['provider']) ? esc_html($image_api_settings['provider']) : __('未设置', 'yali-ai-writer'); ?></span></p>
                </div>
                <?php else: ?>
                <div class="yali-notice yali-notice-warning">
                    <p><?php _e('未配置图像API设置。', 'yali-ai-writer'); ?></p>
                </div>
                <?php endif; ?>
                
                <div class="image-api-actions" style="display:flex; gap:10px; margin-top:20px;">
                    <form method="post" id="clear_image_api_settings_form">
                        <?php wp_nonce_field('yali_ai_writer_debug_action', 'yali_ai_writer_debug_nonce'); ?>
                        <input type="hidden" name="action" value="clear_image_api_settings">
                        <button type="button" class="button button-secondary yali-btn yali-btn-secondary" onclick="confirmClearImageApiSettings()">
                            <?php _e('清空设置', 'yali-ai-writer'); ?>
                        </button>
                    </form>
                    
                    <form method="post" id="reset_image_api_settings_form">
                        <?php wp_nonce_field('yali_ai_writer_debug_action', 'yali_ai_writer_debug_nonce'); ?>
                        <input type="hidden" name="action" value="reset_image_api_settings">
                        <button type="button" class="button button-primary yali-btn yali-btn-primary" onclick="confirmResetImageApiSettings()">
                            <?php _e('重置为默认值', 'yali-ai-writer'); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="yali-card">
             <h2 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:15px; margin-bottom:15px;"><?php _e('自动配图数据管理', 'yali-ai-writer'); ?></h2>
             <div class="yali-card-content">
                <p class="description yali-desc"><?php _e('管理自动配图功能相关的数据。', 'yali-ai-writer'); ?></p>

                
                <div class="auto-image-stats yali-notice yali-notice-info" style="margin: 15px 0;">
                    <h4 style="margin-top:0;"><?php _e('自动配图数据统计', 'yali-ai-writer'); ?></h4>
                    <p><strong><?php _e('插件表数据：', 'yali-ai-writer'); ?></strong></p>
                    <ul style="margin-left: 20px;">
                        <li><?php printf(esc_html__('已配图文章: %d 篇', 'yali-ai-writer'), intval($stats['articles_with_images'])); ?></li>
                        <li><?php printf(esc_html__('待配图文章: %d 篇', 'yali-ai-writer'), intval($stats['pending_image_articles'])); ?></li>
                    </ul>
                    
                    <p><strong><?php _e('WordPress postmeta表数据：', 'yali-ai-writer'); ?></strong></p>
                    <ul style="margin-left: 20px;">
                        <li><?php printf(esc_html__('AI生成的图片: %d 张', 'yali-ai-writer'), intval($stats['ai_generated_images'])); ?></li>
                        <li><?php printf(esc_html__('包含图片占位符的文章: %d 篇', 'yali-ai-writer'), intval($stats['posts_with_image_placeholders'])); ?></li>
                    </ul>
                    
                    <p><strong><?php _e('涉及的postmeta字段：', 'yali-ai-writer'); ?></strong></p>
                    <ul style="margin-left: 20px; font-family: monospace; font-size: 12px;">
                        <li>_auto_images_processed - <?php _e('文章处理状态', 'yali-ai-writer'); ?></li>
                        <li>_auto_images_count - <?php _e('文章图片数量', 'yali-ai-writer'); ?></li>
                        <li>_auto_images_processed_time - <?php _e('处理时间', 'yali-ai-writer'); ?></li>
                        <li>_ai_generated - <?php _e('AI生成图片标记', 'yali-ai-writer'); ?></li>
                        <li>_ai_prompt - <?php _e('图片生成提示词', 'yali-ai-writer'); ?></li>
                        <li>_generation_date - <?php _e('图片生成日期', 'yali-ai-writer'); ?></li>
                        <li>_source_post_id - <?php _e('来源文章ID', 'yali-ai-writer'); ?></li>
                    </ul>
                </div>
                
                <div class="auto-image-actions" style="margin: 15px 0;">
                    <p class="yali-desc" style="color:#d63638;"><strong><?php _e('⚠️ 警告：以下操作将永久删除自动配图相关数据，且无法恢复！', 'yali-ai-writer'); ?></strong></p>
                    
                    <form method="post" id="clear_auto_image_postmeta_form" style="display: inline-block;">
                        <?php wp_nonce_field('yali_ai_writer_debug_action', 'yali_ai_writer_debug_nonce'); ?>
                        <input type="hidden" name="action" value="clear_auto_image_postmeta">
                        <button type="button" class="button button-danger yali-btn yali-btn-danger" onclick="confirmClearAutoImagePostmeta()">
                            <?php _e('清理自动配图postmeta数据', 'yali-ai-writer'); ?>
                        </button>
                    </form>
                    
                    <p class="yali-desc" style="margin-top: 10px;">
                        <?php _e('注意：清理postmeta数据不会删除已生成的图片文件，只会删除相关的元数据记录。插件表中的auto_images_processed和auto_images_count字段可通过上方的"清空所有表数据"操作清理。', 'yali-ai-writer'); ?>
                    </p>
                </div>
            </div>
        </div>
        
        <div class="yali-card" style="grid-column: 1 / -1;">
            <h2 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:15px; margin-bottom:15px;"><?php _e('数据库表结构', 'yali-ai-writer'); ?></h2>
            <div class="yali-card-content">
                <?php
                $tables = array(
                    'yali_ai_writer_api_configs' => __('API配置表', 'yali-ai-writer'),
                    'yali_ai_writer_brand_profiles' => __('品牌资料表', 'yali-ai-writer'),
                    'yali_ai_writer_rules' => __('规则表', 'yali-ai-writer'),
                    'yali_ai_writer_rule_items' => __('规则项目表', 'yali-ai-writer'),
                    'yali_ai_writer_topic_tasks' => __('主题任务表（父任务）', 'yali-ai-writer'),
                    'yali_ai_writer_topics' => __('主题表', 'yali-ai-writer'),
                    'yali_ai_writer_article_tasks' => __('文章任务表（父任务）', 'yali-ai-writer'),
                    'yali_ai_writer_articles' => __('文章表', 'yali-ai-writer'),
                    'yali_ai_writer_job_queue' => __('任务队列表（包含所有子任务）', 'yali-ai-writer'),
                    'yali_ai_writer_publish_rules' => __('发布规则表', 'yali-ai-writer'),
                    'yali_ai_writer_article_structures' => __('文章结构表', 'yali-ai-writer')
                );
                
                foreach ($tables as $table_key => $table_name) {
                    $table_full_name = $wpdb->prefix . $table_key;
                    $exists = $wpdb->get_var("SHOW TABLES LIKE '$table_full_name'") == $table_full_name;
                    
                    echo '<div class="table-info">';
                    echo '<h3>' . esc_html(__($table_descriptions[$table_key]['name'], 'yali-ai-writer')) . ' (' . esc_html($table_full_name) . ') ';
                    if ($exists) {
                        echo '<span class="status exists">' . __('存在', 'yali-ai-writer') . '</span>';
                    } else {
                        echo '<span class="status missing">' . __('不存在', 'yali-ai-writer') . '</span>';
                    }
                    echo '</h3>';
                    
                    if (isset($table_descriptions[$table_key]['description'])) {
                        echo '<p><em>' . esc_html(__($table_descriptions[$table_key]['description'], 'yali-ai-writer')) . '</em></p>';
                    }
                    
                    if ($exists) {
                        // 显示表结构
                        $columns = $wpdb->get_results("SHOW COLUMNS FROM `$table_full_name`");
                        if (!empty($columns)) {
                            echo '<div style="overflow-x:auto;">';
                            echo '<table class="yali-table widefat fixed striped">';
                            echo '<thead><tr><th>' . __('字段名', 'yali-ai-writer') . '</th><th>' . __('类型', 'yali-ai-writer') . '</th><th>' . __('允许NULL', 'yali-ai-writer') . '</th><th>' . __('默认值', 'yali-ai-writer') . '</th><th>' . __('额外信息', 'yali-ai-writer') . '</th><th>' . __('业务说明', 'yali-ai-writer') . '</th></tr></thead>';
                            echo '<tbody>';
                            foreach ($columns as $column) {
                                echo '<tr>';
                                // 检查是否为重构新增字段
                                $new_fields = array();
                                if ($table_key === 'yali_ai_writer_article_tasks') {
                                    $new_fields = array('current_processing_item', 'total_rule_items', 'generated_articles_count');
                                } elseif ($table_key === 'yali_ai_writer_job_queue') {
                                    $new_fields = array('reference_id', 'retry_count', 'scheduled_at');
                                } elseif ($table_key === 'yali_ai_writer_topics') {
                                    $new_fields = array('vector_cluster_id', 'vector_status', 'vector_error', 'vector_retry_count', 'reference_material');
                                } elseif ($table_key === 'yali_ai_writer_publish_rules') {
                                    $new_fields = array('max_auto_images', 'skip_first_image_placeholder', 'enable_internal_linking', 'publish_interval_minutes', 'enable_brand_profile_insertion', 'brand_profile_position', 'enable_reference_material', 'enable_ai_reference_select', 'publish_language');
                                } elseif ($table_key === 'yali_ai_writer_api_configs') {
                                    $new_fields = array('vector_api_url', 'vector_api_key', 'vector_model_name');
                                } elseif ($table_key === 'yali_ai_writer_articles') {
                                    $new_fields = array('auto_images_processed', 'auto_images_count');
                                } elseif ($table_key === 'yali_ai_writer_rules') {
                                    $new_fields = array('reference_material');
                                } elseif ($table_key === 'yali_ai_writer_brand_profiles') {
                                    $new_fields = array('type', 'custom_html');
                                }

                                $is_new_field = in_array($column->Field, $new_fields);
                                $field_class = $is_new_field ? 'field-new' : '';

                                echo '<td class="' . $field_class . '">' . esc_html($column->Field);
                                if ($is_new_field) {
                                    echo ' <span style="font-size: 11px; background: #00a32a; color: white; padding: 1px 4px; border-radius: 2px; margin-left: 5px;">' . __('重构新增', 'yali-ai-writer') . '</span>';
                                }
                                echo '</td>';
                                echo '<td>' . esc_html($column->Type) . '</td>';
                                echo '<td>' . esc_html($column->Null) . '</td>';
                                echo '<td>' . esc_html($column->Default ?? 'NULL') . '</td>';
                                echo '<td>' . esc_html($column->Extra) . '</td>';
                                // 添加字段业务说明
                                $field_description = isset($table_descriptions[$table_key]['fields'][$column->Field]) ? $table_descriptions[$table_key]['fields'][$column->Field] : '';
                                echo '<td>' . esc_html(__($field_description, 'yali-ai-writer')) . '</td>';
                                echo '</tr>';
                            }
                            echo '</tbody></table>';
                            echo '</div>';
                        }
                        
                        // 显示表数据（前10条记录）
                        echo '<h4 style="margin-top:20px;">' . __('数据示例（前10条记录）', 'yali-ai-writer') . '</h4>';
                        
                        // 根据表类型选择合适的排序字段
                        $order_by = ($table_key === 'yali_ai_writer_topics') ? 'id DESC' : 'updated_at DESC';
                        $table_data = $wpdb->get_results("SELECT * FROM `$table_full_name` ORDER BY $order_by LIMIT 10");
                        
                        if (!empty($table_data)) {
                            // 获取字段名
                            $column_names = array_keys((array)$table_data[0]);
                            
                            // 显示数据表格
                            echo '<div style="overflow-x:auto;">';
                            echo '<table class="yali-table widefat fixed striped">';
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
                            echo '</div>';
                        } else {
                            echo '<p>' . __('表中暂无数据', 'yali-ai-writer') . '</p>';
                        }
                    }
                    echo '</div>';
                }
                ?>
            </div>
        </div>
    </div>
</div>

