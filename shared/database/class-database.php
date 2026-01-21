<?php
/**
 * 数据库操作类
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_Database {
    
    /**
     * 创建数据库表
     */
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $errors = array();
        $created_tables = array();
        
        // 大模型API配置表
        $api_configs_table = $wpdb->prefix . 'content_auto_api_configs';
        $sql = 'CREATE TABLE IF NOT EXISTS `' . $api_configs_table . '` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL DEFAULT \'\',
            `api_url` text NOT NULL,
            `api_key` varchar(255) NOT NULL DEFAULT \'\',
            `model_name` varchar(100) NOT NULL DEFAULT \'\',
            `temperature` decimal(3,2) NOT NULL DEFAULT \'0.70\',
            `max_tokens` int(11) NOT NULL DEFAULT \'1000\',
            `temperature_enabled` tinyint(1) NOT NULL DEFAULT \'1\',
            `max_tokens_enabled` tinyint(1) NOT NULL DEFAULT \'1\',
            `is_active` tinyint(1) NOT NULL DEFAULT \'0\',
            `predefined_channel` varchar(50) NOT NULL DEFAULT \'\',
            `vector_api_url` text DEFAULT NULL COMMENT \'向量API地址\',
            `vector_api_key` varchar(255) DEFAULT NULL COMMENT \'向量API密钥\',
            `vector_model_name` varchar(100) DEFAULT NULL COMMENT \'向量模型名称\',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `is_active` (`is_active`),
            KEY `predefined_channel` (`predefined_channel`)
        ) ' . $charset_collate . ';';
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $result = dbDelta($sql);
        
        // 检查表是否创建成功
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$api_configs_table'") == $api_configs_table;
        if (!$table_exists) {
            $errors[] = "Failed to create table: $api_configs_table";
        } else {
            $created_tables[] = $api_configs_table;
        }
        
        // 规则表
        $rules_table = $wpdb->prefix . 'content_auto_rules';
        $sql = 'CREATE TABLE IF NOT EXISTS `' . $rules_table . '` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `rule_name` varchar(255) NOT NULL,
            `rule_type` varchar(50) NOT NULL,
            `rule_conditions` text NOT NULL,
            `item_count` int(11) NOT NULL DEFAULT 0,
            `rule_task_id` varchar(50) NOT NULL,
            `status` tinyint(1) NOT NULL DEFAULT 1,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ' . $charset_collate . ';';

        $result = dbDelta($sql);
        
        // 检查表是否创建成功
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$rules_table'") == $rules_table;
        if (!$table_exists) {
            $errors[] = "Failed to create table: $rules_table";
        } else {
            $created_tables[] = $rules_table;
        }

        // 子规则任务表
        $rule_items_table = $wpdb->prefix . 'content_auto_rule_items';
        $sql = 'CREATE TABLE IF NOT EXISTS `' . $rule_items_table . '` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `rule_id` bigint(20) NOT NULL,
            `rule_task_id` varchar(50) NOT NULL,
            `post_id` bigint(20) NOT NULL,
            `post_title` varchar(500) NOT NULL,
            `category_ids` text NOT NULL,
            `category_names` text NOT NULL,
            `category_descriptions` text NOT NULL,
            `tag_names` text NOT NULL,
            `upload_text` text NOT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `rule_id` (`rule_id`),
            KEY `rule_task_id` (`rule_task_id`)
        ) ' . $charset_collate . ';';

        $result = dbDelta($sql);
        
        // 检查表是否创建成功
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$rule_items_table'") == $rule_items_table;
        if (!$table_exists) {
            $errors[] = "Failed to create table: $rule_items_table";
        } else {
            $created_tables[] = $rule_items_table;
        }
        
        // 主题任务表 - 重构版本
        $topic_tasks_table = $wpdb->prefix . 'content_auto_topic_tasks';
        $sql = 'CREATE TABLE IF NOT EXISTS `' . $topic_tasks_table . '` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `topic_task_id` varchar(50) NOT NULL DEFAULT \'\',
            `rule_id` bigint(20) NOT NULL DEFAULT \'0\',
            `topic_count_per_item` int(11) NOT NULL DEFAULT \'0\',
            `total_rule_items` int(11) NOT NULL DEFAULT \'0\',
            `total_expected_topics` int(11) NOT NULL DEFAULT \'0\',
            `current_processing_item` int(11) NOT NULL DEFAULT \'0\',
            `generated_topics_count` int(11) NOT NULL DEFAULT \'0\',
            `status` varchar(20) NOT NULL DEFAULT \'' . CONTENT_AUTO_STATUS_PENDING . '\',
            `error_message` text NOT NULL DEFAULT \'\',
            `subtask_status` longtext NOT NULL COMMENT \'子任务状态JSON存储\',
            `last_processed_at` DATETIME NULL COMMENT \'最后处理时间\',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `topic_task_id` (`topic_task_id`),
            KEY `rule_id` (`rule_id`),
            KEY `status` (`status`),
            KEY `last_processed_at` (`last_processed_at`)
        ) ' . $charset_collate . ';';
        
        $result = dbDelta($sql);
        
        // 检查表是否创建成功
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$topic_tasks_table'") == $topic_tasks_table;
        if (!$table_exists) {
            $errors[] = "Failed to create table: $topic_tasks_table";
        } else {
            $created_tables[] = $topic_tasks_table;
        }
        
        // 主题表 - 重构版本
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        $sql = 'CREATE TABLE IF NOT EXISTS `' . $topics_table . '` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `task_id` varchar(50) NOT NULL DEFAULT \'\',
            `rule_id` bigint(20) NOT NULL DEFAULT \'0\',
            `rule_item_index` int(11) NOT NULL DEFAULT \'0\',
            `title` text NOT NULL,
            `source_angle` varchar(100) NOT NULL DEFAULT \'\',
            `user_value` text NOT NULL DEFAULT \'\',
            `seo_keywords` text NOT NULL DEFAULT \'\',
            `matched_category` varchar(100) NOT NULL DEFAULT \'\',
            `priority_score` int(11) NOT NULL DEFAULT \'3\',
            `status` varchar(20) NOT NULL DEFAULT \'' . CONTENT_AUTO_TOPIC_UNUSED . '\',
            `api_config_id` bigint(20) DEFAULT NULL,
            `api_config_name` varchar(255) DEFAULT NULL,
            `vector_embedding` longtext DEFAULT NULL COMMENT \'主题向量嵌入数据（JSON格式），用于存储1024维向量数据\',
            `vector_cluster_id` int(11) DEFAULT NULL COMMENT \'向量聚类ID\',
            `vector_status` varchar(20) NOT NULL DEFAULT \'pending\' COMMENT \'向量生成状态\',
            `vector_error` text DEFAULT NULL COMMENT \'向量生成错误信息\',
            `vector_retry_count` tinyint(4) NOT NULL DEFAULT 0 COMMENT \'向量生成重试次数\',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `task_id` (`task_id`),
            KEY `rule_id` (`rule_id`),
            KEY `status` (`status`),
            KEY `rule_item_index` (`rule_item_index`),
            KEY `priority_score` (`priority_score`),
            KEY `api_config_id` (`api_config_id`),
            KEY `vector_cluster_id` (`vector_cluster_id`),
            KEY `vector_status` (`vector_status`)
        ) ' . $charset_collate . ';';
        
        $result = dbDelta($sql);
        
        // 检查表是否创建成功
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$topics_table'") == $topics_table;
        if (!$table_exists) {
            $errors[] = "Failed to create table: $topics_table";
        } else {
            $created_tables[] = $topics_table;
        }
        
        // 文章父任务表
        $article_tasks_table = $wpdb->prefix . 'content_auto_article_tasks';
        $sql = 'CREATE TABLE IF NOT EXISTS `' . $article_tasks_table . '` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `article_task_id` varchar(50) NOT NULL DEFAULT \'\',
            `name` varchar(255) NOT NULL DEFAULT \'\',
            `topic_ids` longtext NOT NULL,
            `status` varchar(20) NOT NULL DEFAULT \'' . CONTENT_AUTO_STATUS_PENDING . '\',
            `subtask_status` longtext NOT NULL COMMENT \'子任务状态JSON存储\',
            `error_message` text NOT NULL DEFAULT \'\',
            `total_topics` int(11) NOT NULL DEFAULT \'0\',
            `completed_topics` int(11) NOT NULL DEFAULT \'0\',
            `failed_topics` int(11) NOT NULL DEFAULT \'0\',
            `last_processed_at` datetime NULL DEFAULT NULL COMMENT \'最后处理时间\',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `article_task_id` (`article_task_id`),
            KEY `status` (`status`),
            KEY `last_processed_at` (`last_processed_at`)
        ) ' . $charset_collate . ';';
        
        $result = dbDelta($sql);
        
        // 检查表是否创建成功
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$article_tasks_table'") == $article_tasks_table;
        if (!$table_exists) {
            $errors[] = "Failed to create table: $article_tasks_table";
        } else {
            $created_tables[] = $article_tasks_table;
        }
        
        // 文章记录表
        $articles_table = $wpdb->prefix . 'content_auto_articles';
        $sql = 'CREATE TABLE IF NOT EXISTS `' . $articles_table . '` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `job_id` bigint(20) NOT NULL DEFAULT \'0\',
            `topic_id` bigint(20) NOT NULL DEFAULT \'0\',
            `post_id` bigint(20) NOT NULL DEFAULT \'0\',
            `title` text NOT NULL,
            `content` longtext NOT NULL,
            `status` varchar(20) NOT NULL DEFAULT \'' . CONTENT_AUTO_STATUS_PENDING . '\',
            `error_message` text NOT NULL DEFAULT \'\',
            `processing_time` int(11) NOT NULL DEFAULT \'0\' COMMENT \'处理耗时(秒)\',
            `word_count` int(11) NOT NULL DEFAULT \'0\' COMMENT \'文章字数\',
            `api_config_id` bigint(20) DEFAULT NULL,
            `api_config_name` varchar(255) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `job_id` (`job_id`),
            KEY `topic_id` (`topic_id`),
            KEY `status` (`status`),
            KEY `api_config_id` (`api_config_id`)
        ) ' . $charset_collate . ';';
        
        $result = dbDelta($sql);
        
        // 检查表是否创建成功
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$articles_table'") == $articles_table;
        if (!$table_exists) {
            $errors[] = "Failed to create table: $articles_table";
        } else {
            $created_tables[] = $articles_table;
        }
        
        // 任务队列表 - 简化版本
        $job_queue_table = $wpdb->prefix . 'content_auto_job_queue';
        $sql = 'CREATE TABLE IF NOT EXISTS `' . $job_queue_table . '` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `job_type` varchar(20) NOT NULL,
            `job_id` bigint(20) NOT NULL DEFAULT \'0\',
            `subtask_id` varchar(50) DEFAULT NULL,
            `reference_id` bigint(20) DEFAULT NULL COMMENT \'引用ID，用于存储文章任务中的主题ID\',
            `priority` int(11) NOT NULL DEFAULT \'0\',
            `retry_count` int(11) DEFAULT 0,
            `status` varchar(20) NOT NULL DEFAULT \'' . CONTENT_AUTO_STATUS_PENDING . '\',
            `error_message` text NOT NULL DEFAULT \'\',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_subtask_id` (`subtask_id`),
            KEY `job_type` (`job_type`),
            KEY `status` (`status`),
            KEY `job_id` (`job_id`),
            KEY `reference_id` (`reference_id`)
        ) ' . $charset_collate . ';';

        $result = dbDelta($sql);
        
        // 检查表是否创建成功
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$job_queue_table'") == $job_queue_table;
        if (!$table_exists) {
            $errors[] = "Failed to create table: $job_queue_table";
        } else {
            $created_tables[] = $job_queue_table;
        }
        
        // 发布规则配置表
        $publish_rules_table = $wpdb->prefix . 'content_auto_publish_rules';
        $sql = 'CREATE TABLE IF NOT EXISTS `' . $publish_rules_table . '` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `post_status` varchar(20) NOT NULL DEFAULT \'' . CONTENT_AUTO_PUBLISH_STATUS_DRAFT . '\',
            `author_id` bigint(20) NOT NULL DEFAULT \'0\',
            `category_mode` varchar(20) NOT NULL DEFAULT \'manual\',
            `category_ids` text NOT NULL,
            `fallback_category_ids` text NOT NULL,
            `target_length` varchar(20) NOT NULL DEFAULT \'800-1500\',
            `knowledge_depth` varchar(20) NOT NULL DEFAULT \'未设置\',
            `reader_role` varchar(20) NOT NULL DEFAULT \'未设置\',
            `normalize_output` tinyint(1) NOT NULL DEFAULT \'0\',
            `auto_image_insertion` tinyint(1) NOT NULL DEFAULT \'0\',
            `max_auto_images` int(11) NOT NULL DEFAULT \'1\' COMMENT \'最大自动生成图片数量\',
            `skip_first_image_placeholder` tinyint(1) NOT NULL DEFAULT \'0\' COMMENT \'是否跳过首个图片占位符\',
            `enable_internal_linking` tinyint(1) NOT NULL DEFAULT \'0\' COMMENT \'启用文章内链功能\',
            `publish_interval_minutes` int(11) NOT NULL DEFAULT \'0\' COMMENT \'发布间隔时间（分钟），0表示立即发布\',
            `enable_brand_profile_insertion` tinyint(1) NOT NULL DEFAULT \'0\' COMMENT \'启用品牌资料插入功能\',
            `brand_profile_position` varchar(50) NOT NULL DEFAULT \'before_second_paragraph\' COMMENT \'品牌资料插入位置\',
            `enable_reference_material` tinyint(1) NOT NULL DEFAULT \'0\' COMMENT \'启用参考资料功能\',
            `enable_ai_reference_select` tinyint(1) NOT NULL DEFAULT \'0\' COMMENT \'启用大模型精选召回\',
            `enable_intent_inference` tinyint(1) NOT NULL DEFAULT \'0\' COMMENT \'启用搜索意图推断，生成更符合用户搜索意图的主题标题\',
            `publish_language` varchar(10) NOT NULL DEFAULT \'zh-CN\' COMMENT \'发布语言，影响内容生成的输出语言\',
            `role_description` text NOT NULL COMMENT \'AI角色描述，用于文章生成的提示词模板\',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ' . $charset_collate . ';';
        
        $result = dbDelta($sql);
        
        // 检查表是否创建成功
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$publish_rules_table'") == $publish_rules_table;
        if (!$table_exists) {
            $errors[] = "Failed to create table: $publish_rules_table";
        } else {
            $created_tables[] = $publish_rules_table;
        }

        // 文章结构表
        $article_structures_table = $wpdb->prefix . 'content_auto_article_structures';
        $sql = 'CREATE TABLE IF NOT EXISTS `' . $article_structures_table . '` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `content_angle` varchar(255) NOT NULL,
            `title` text NOT NULL,
            `structure` text NOT NULL,
            `title_vector` longtext,
            `usage_count` bigint(20) unsigned NOT NULL DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_content_angle` (`content_angle`)
        ) ' . $charset_collate . ';';

        $result = dbDelta($sql);

        // 检查表是否创建成功
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$article_structures_table'") == $article_structures_table;
        if (!$table_exists) {
            $errors[] = "Failed to create table: $article_structures_table";
        } else {
            $created_tables[] = $article_structures_table;
        }

        // 品牌资料表
        $brand_profiles_table = $wpdb->prefix . 'content_auto_brand_profiles';
        $sql = 'CREATE TABLE IF NOT EXISTS `' . $brand_profiles_table . '` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `title` text NOT NULL,
            `image_url` text NOT NULL,
            `description` text,
            `link` text,
            `vector` longtext,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ' . $charset_collate . ';';

        $result = dbDelta($sql);

        // 检查表是否创建成功
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$brand_profiles_table'") == $brand_profiles_table;
        if (!$table_exists) {
            $errors[] = "Failed to create table: $brand_profiles_table";
        } else {
            $created_tables[] = $brand_profiles_table;
        }
            
        // 更新任务队列表结构，添加reference_id字段
        $this->update_job_queue_table_structure();
        
        // 更新文章任务表结构，添加与主题任务一致的字段
        $this->update_article_tasks_table_structure();
        
        // 更新主题表结构，添加vector_embedding字段
        $this->update_topics_table_structure();
        
        // 更新API配置表结构，添加向量API相关字段
        $this->update_api_configs_table_structure();
        
        // 更新任务队列表，添加计划任务时间字段
        $this->update_job_queue_table_for_scheduling();

        // 更新发布规则表结构，添加文章内链功能字段
        $this->update_publish_rules_table_structure();
        
        // 更新文章表结构，添加自动配图字段
        $this->update_articles_table_for_auto_images();

        // 更新发布规则表，添加品牌资料功能字段
        $this->update_publish_rules_for_brand_profiles();

        // 更新发布规则表，添加图片生成控制字段
        $this->update_publish_rules_for_image_control();

        // 更新品牌资料表，添加自定义HTML类型支持
        $this->update_brand_profiles_for_custom_html();

        // 更新规则表，添加参考资料字段
        $this->update_rules_table_for_reference_material();

        // 更新发布规则表，添加参考资料功能字段
        $this->update_publish_rules_for_reference_material();

        // 确保发布规则表数据完整性（为全新安装和现有用户提供统一保障）
        $this->ensure_publish_rules_data_integrity();

        // 更新发布规则表，添加素材收集模式字段
        $this->update_database_for_material_collection_mode();

        // 运行智能结构优化迁移
        $this->run_structure_optimization_migration();

        // 提示词模板表
        $prompt_templates_table = $wpdb->prefix . 'content_auto_prompt_templates';
        $sql = 'CREATE TABLE IF NOT EXISTS `' . $prompt_templates_table . '` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `template_type` varchar(50) NOT NULL COMMENT \'topic_generation or article_generation\',
            `content` longtext NOT NULL,
            `source_file` varchar(255) DEFAULT NULL COMMENT \'源文件名，用于防止重复导入\',
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `template_type` (`template_type`),
            KEY `is_active` (`is_active`),
            UNIQUE KEY `source_file` (`source_file`)
        ) ' . $charset_collate . ';';

        $result = dbDelta($sql);

        // 检查表是否创建成功
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$prompt_templates_table'") == $prompt_templates_table;
        if (!$table_exists) {
            $errors[] = "Failed to create table: $prompt_templates_table";
        } else {
            $created_tables[] = $prompt_templates_table;
        }

        // 更新数据库结构以支持自动素材搜索
        $this->update_database_for_auto_material_search();

        // 更新提示词模板表结构
        $this->update_prompt_templates_table_structure($prompt_templates_table);

        // 预置默认提示词模板
        $this->seed_default_templates($prompt_templates_table);

        // 返回创建结果
        return array(
            'success' => empty($errors),
            'created_tables' => $created_tables,
            'errors' => $errors
        );
    }
    
    /**
     * 获取数据库表前缀
     */
    public function get_table_prefix() {
        global $wpdb;
        return $wpdb->prefix;
    }
    
    /**
     * 插入数据
     */
    public function insert($table, $data) {
        global $wpdb;
        
        // Check if the table name already includes the prefix
        if (strpos($table, $wpdb->prefix) === 0) {
            // Table name already includes prefix
            $table_name = $table;
        } else {
            // Add prefix to table name
            $table_name = $wpdb->prefix . $table;
        }
        
        $result = $wpdb->insert($table_name, $data);
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * 更新数据
     */
    public function update($table, $data, $where) {
        global $wpdb;
        
        // Check if the table name already includes the prefix
        if (strpos($table, $wpdb->prefix) === 0) {
            // Table name already includes prefix
            $table_name = $table;
        } else {
            // Add prefix to table name
            $table_name = $wpdb->prefix . $table;
        }
        
        return $wpdb->update($table_name, $data, $where);
    }
    
    /**
     * 删除数据
     */
    public function delete($table, $where) {
        global $wpdb;
        
        // Check if the table name already includes the prefix
        if (strpos($table, $wpdb->prefix) === 0) {
            // Table name already includes prefix
            $table_name = $table;
        } else {
            // Add prefix to table name
            $table_name = $wpdb->prefix . $table;
        }
        
        return $wpdb->delete($table_name, $where);
    }
    
    /**
     * 获取单行数据
     */
    public function get_row($table, $where = array(), $output = ARRAY_A) {
        global $wpdb;
        
        // Check if the table name already includes the prefix
        if (strpos($table, $wpdb->prefix) === 0) {
            // Table name already includes prefix
            $table_name = $table;
        } else {
            // Add prefix to table name
            $table_name = $wpdb->prefix . $table;
        }
        
        if (empty($where)) {
            return $wpdb->get_row("SELECT * FROM $table_name", $output);
        }
        
        $where_clause = array();
        $where_values = array();
        
        foreach ($where as $key => $value) {
            $where_clause[] = "$key = %s";
            $where_values[] = $value;
        }
        
        $where_sql = implode(' AND ', $where_clause);
        $sql = $wpdb->prepare("SELECT * FROM $table_name WHERE $where_sql", $where_values);
        
        return $wpdb->get_row($sql, $output);
    }
    
    /**
     * 获取多行数据
     */
    public function get_results($table, $where = array(), $output = ARRAY_A) {
        global $wpdb;
        
        // Check if the table name already includes the prefix
        if (strpos($table, $wpdb->prefix) === 0) {
            // Table name already includes prefix
            $table_name = $table;
        } else {
            // Add prefix to table name
            $table_name = $wpdb->prefix . $table;
        }
        
        if (empty($where)) {
            return $wpdb->get_results("SELECT * FROM $table_name", $output);
        }
        
        $where_clause = array();
        $where_values = array();
        
        foreach ($where as $key => $value) {
            $where_clause[] = "$key = %s";
            $where_values[] = $value;
        }
        
        $where_sql = implode(' AND ', $where_clause);
        $sql = $wpdb->prepare("SELECT * FROM $table_name WHERE $where_sql", $where_values);
        
        return $wpdb->get_results($sql, $output);
    }
    
    /**
     * 获取单个值
     */
    public function get_var($table, $field, $where = array()) {
        global $wpdb;
        $table_name = $wpdb->prefix . $table;
        
        if (empty($where)) {
            return $wpdb->get_var("SELECT $field FROM $table_name");
        }
        
        $where_clause = array();
        $where_values = array();
        
        foreach ($where as $key => $value) {
            $where_clause[] = "$key = %s";
            $where_values[] = $value;
        }
        
        $where_sql = implode(' AND ', $where_clause);
        $sql = $wpdb->prepare("SELECT $field FROM $table_name WHERE $where_sql", $where_values);
        
        return $wpdb->get_var($sql);
    }
    
    /**
     * 更新任务队列表结构，添加reference_id字段
     * 
     * @return bool 更新是否成功
     */
    public function update_job_queue_table_structure() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'content_auto_job_queue';
        
        // 检查表是否存在
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if (!$table_exists) {
            return false;
        }
        
        // 检查reference_id字段是否存在
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'reference_id'");
        
        if (!$column_exists) {
            // 添加reference_id字段
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `reference_id` bigint(20) DEFAULT NULL COMMENT '引用ID，用于存储文章任务中的主题ID' AFTER `subtask_id`");
            
            // 添加索引
            if ($result !== false) {
                $wpdb->query("ALTER TABLE $table_name ADD KEY `reference_id` (`reference_id`)");
                return true;
            } else {
                return false;
            }
        }
        
        return true; // 字段已存在
    }
    
    /**
     * 更新文章任务表结构，添加与主题任务一致的字段
     */
    public function update_article_tasks_table_structure() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'content_auto_article_tasks';
        
        // 检查表是否存在
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if (!$table_exists) {
            return false;
        }
        
        $updates_applied = false;
        
        // 检查并添加 current_processing_item 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'current_processing_item'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `current_processing_item` int(11) NOT NULL DEFAULT '0' COMMENT '当前处理的子任务数量' AFTER `completed_topics`");
            if ($result !== false) {
                $updates_applied = true;
            }
        }
        
        // 检查并添加 total_rule_items 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'total_rule_items'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `total_rule_items` int(11) NOT NULL DEFAULT '0' COMMENT '总子任务数量' AFTER `current_processing_item`");
            if ($result !== false) {
                $updates_applied = true;
            }
        }
        
        // 检查并添加 generated_articles_count 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'generated_articles_count'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `generated_articles_count` int(11) NOT NULL DEFAULT '0' COMMENT '已生成文章数量' AFTER `total_rule_items`");
            if ($result !== false) {
                $updates_applied = true;
            }
        }
        
        // 检查并添加 last_processed_at 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'last_processed_at'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `last_processed_at` datetime NULL DEFAULT NULL COMMENT '最后处理时间' AFTER `failed_topics`");
            if ($result !== false) {
                $updates_applied = true;
            }
        }
        
        // 添加索引
        $index_exists = $wpdb->get_var("SHOW INDEX FROM $table_name WHERE Column_name = 'last_processed_at'");
        if (!$index_exists) {
            $wpdb->query("ALTER TABLE $table_name ADD KEY `last_processed_at` (`last_processed_at`)");
        }
        
        return $updates_applied;
    }
    
    /**
     * 更新主题表结构，添加vector_embedding字段和reference_material字段
     */
    public function update_topics_table_structure() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'content_auto_topics';
        
        // 检查表是否存在
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if (!$table_exists) {
            return false;
        }
        
        $updates_applied = false;

        // 检查并添加 vector_embedding 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'vector_embedding'");
        if (!$column_exists) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN `vector_embedding` longtext DEFAULT NULL COMMENT '主题向量嵌入数据（JSON格式），用于存储1024维向量数据' AFTER `api_config_name`");
            $updates_applied = true;
        }

        // 检查并添加 vector_cluster_id 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'vector_cluster_id'");
        if (!$column_exists) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN `vector_cluster_id` int(11) DEFAULT NULL COMMENT '向量聚类ID' AFTER `vector_embedding`");
            $wpdb->query("ALTER TABLE $table_name ADD KEY `vector_cluster_id` (`vector_cluster_id`)");
            $updates_applied = true;
        }

        // 检查并添加 vector_status 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'vector_status'");
        if (!$column_exists) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN `vector_status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT '向量生成状态' AFTER `vector_cluster_id`");
            $wpdb->query("ALTER TABLE $table_name ADD KEY `vector_status` (`vector_status`)");
            $updates_applied = true;
        }

        // 检查并添加 vector_error 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'vector_error'");
        if (!$column_exists) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN `vector_error` text DEFAULT NULL COMMENT '向量生成错误信息' AFTER `vector_status`");
            $updates_applied = true;
        }

        // 检查并添加 vector_retry_count 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'vector_retry_count'");
        if (!$column_exists) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN `vector_retry_count` tinyint(4) NOT NULL DEFAULT 0 COMMENT '向量生成重试次数' AFTER `vector_error`");
            $updates_applied = true;
        }

        // 检查并添加 reference_material 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'reference_material'");
        if (!$column_exists) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN `reference_material` text DEFAULT NULL COMMENT '主题级参考资料，优先于规则级参考资料，最多500字符' AFTER `vector_retry_count`");
            $updates_applied = true;
        }
        
        return $updates_applied;
    }
    
    /**
     * 更新API配置表结构，添加向量API相关字段
     */
    public function update_api_configs_table_structure() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'content_auto_api_configs';
        
        // 检查表是否存在
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if (!$table_exists) {
            return false;
        }
        
        $updates_applied = [];
        
        // 检查并添加 vector_api_url 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'vector_api_url'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `vector_api_url` text DEFAULT NULL COMMENT '向量API地址' AFTER `predefined_channel`");
            if ($result !== false) {
                $updates_applied[] = "vector_api_url字段";
            }
        }
        
        // 检查并添加 vector_api_key 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'vector_api_key'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `vector_api_key` varchar(255) DEFAULT NULL COMMENT '向量API密钥' AFTER `vector_api_url`");
            if ($result !== false) {
                $updates_applied[] = "vector_api_key字段";
            }
        }
        
        // 检查并添加 vector_model_name 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'vector_model_name'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `vector_model_name` varchar(100) DEFAULT NULL COMMENT '向量模型名称' AFTER `vector_api_key`");
            if ($result !== false) {
                $updates_applied[] = "vector_model_name字段";
            }
        }
        
        // 检查并添加 vector_api_type 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'vector_api_type'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `vector_api_type` varchar(20) NOT NULL DEFAULT 'openai' COMMENT '向量API类型：openai/jina' AFTER `vector_model_name`");
            if ($result !== false) {
                $updates_applied[] = "vector_api_type字段";
            }
        }

        // 检查并添加 stream 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'stream'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `stream` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否启用流式输出' AFTER `max_tokens_enabled`");
            if ($result !== false) {
                $updates_applied[] = "stream字段";
            }
        }

        // 检查并添加 top_p 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'top_p'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `top_p` decimal(3,2) NOT NULL DEFAULT '1.00' COMMENT '核采样参数' AFTER `stream`");
            if ($result !== false) {
                $updates_applied[] = "top_p字段";
            }
        }

        // 检查并添加 stream_enabled 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'stream_enabled'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `stream_enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否启用stream参数控制' AFTER `top_p`");
            if ($result !== false) {
                $updates_applied[] = "stream_enabled字段";
            }
        }

        // 检查并添加 top_p_enabled 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'top_p_enabled'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `top_p_enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否启用top_p参数控制' AFTER `stream_enabled`");
            if ($result !== false) {
                $updates_applied[] = "top_p_enabled字段";
            }
        }

        return !empty($updates_applied);
    }
    
    /**
     * 更新任务队列表结构，添加 scheduled_at 字段
     */
    public function update_job_queue_table_for_scheduling() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'content_auto_job_queue';
        
        // 检查表是否存在
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if (!$table_exists) {
            return false;
        }
        
        $updates_applied = false;
        
        // 检查并添加 scheduled_at 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'scheduled_at'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `scheduled_at` datetime NULL DEFAULT NULL COMMENT '计划执行时间' AFTER `retry_count`");
            if ($result !== false) {
                $updates_applied = true;
            }
        }
        
        // 添加索引
        $index_exists = $wpdb->get_var("SHOW INDEX FROM $table_name WHERE Column_name = 'scheduled_at'");
        if (!$index_exists) {
            $wpdb->query("ALTER TABLE $table_name ADD KEY `scheduled_at` (`scheduled_at`)");
        }
        
        return $updates_applied;
    }
    
    /**
     * 更新发布规则表结构，添加文章内链功能字段
     */
    public function update_publish_rules_table_structure() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'content_auto_publish_rules';
        
        // 检查表是否存在
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if (!$table_exists) {
            return false;
        }
        
        $updates_applied = false;
        
        // 检查并添加 enable_internal_linking 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'enable_internal_linking'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `enable_internal_linking` tinyint(1) NOT NULL DEFAULT '0' COMMENT '启用文章内链功能' AFTER `auto_image_insertion`");
            if ($result !== false) {
                $updates_applied = true;
            }
        }

        // 检查并添加 publish_interval_minutes 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'publish_interval_minutes'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `publish_interval_minutes` int(11) NOT NULL DEFAULT '0' COMMENT '发布间隔时间（分钟），0表示立即发布' AFTER `enable_internal_linking`");
            if ($result !== false) {
                $updates_applied = true;
            }
        }

        // 检查并添加 publish_language 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'publish_language'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `publish_language` varchar(10) NOT NULL DEFAULT 'zh-CN' COMMENT '发布语言，影响内容生成的输出语言' AFTER `publish_interval_minutes`");
            if ($result !== false) {
                $updates_applied = true;
            }
        }

        return $updates_applied;
    }
    
    /**
     * 更新文章表结构，添加自动配图相关字段
     */
    public function update_articles_table_for_auto_images() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'content_auto_articles';
        
        // 检查表是否存在
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if (!$table_exists) {
            return false;
        }
        
        $updates_applied = false;
        
        // 检查并添加 auto_images_processed 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'auto_images_processed'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `auto_images_processed` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否已处理自动配图' AFTER `api_config_name`");
            if ($result !== false) {
                $updates_applied = true;
            }
        }
        
        // 检查并添加 auto_images_count 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'auto_images_count'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `auto_images_count` int(11) NOT NULL DEFAULT '0' COMMENT '生成的图片数量' AFTER `auto_images_processed`");
            if ($result !== false) {
                $updates_applied = true;
            }
        }
        
        // 添加自动配图字段的索引
        $index_exists = $wpdb->get_var("SHOW INDEX FROM $table_name WHERE Column_name = 'auto_images_processed'");
        if (!$index_exists) {
            $wpdb->query("ALTER TABLE $table_name ADD KEY `idx_auto_images` (`auto_images_processed`)");
        }
        
        return $updates_applied;
    }

    /**
     * 更新发布规则表，添加品牌资料功能字段
     */
    public function update_publish_rules_for_brand_profiles() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'content_auto_publish_rules';
        
        // 检查表是否存在
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if (!$table_exists) {
            return false;
        }
        
        $updates_applied = false;
        
        // 检查并添加 enable_brand_profile_insertion 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'enable_brand_profile_insertion'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `enable_brand_profile_insertion` tinyint(1) NOT NULL DEFAULT '0' COMMENT '启用品牌资料插入功能' AFTER `enable_internal_linking`");
            if ($result !== false) {
                $updates_applied = true;
            }
        }
        
        return $updates_applied;
    }

    /**
     * 更新发布规则表，添加图片生成控制字段
     */
    public function update_publish_rules_for_image_control() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'content_auto_publish_rules';
        
        // 检查表是否存在
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if (!$table_exists) {
            return false;
        }
        
        $updates_applied = false;
        
        // 检查并添加 max_auto_images 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'max_auto_images'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `max_auto_images` int(11) NOT NULL DEFAULT '1' COMMENT '最大自动生成图片数量' AFTER `auto_image_insertion`");
            if ($result !== false) {
                $updates_applied = true;
            }
        }
        
        // 检查并添加 skip_first_image_placeholder 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'skip_first_image_placeholder'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `skip_first_image_placeholder` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否跳过首个图片占位符' AFTER `max_auto_images`");
            if ($result !== false) {
                $updates_applied = true;
            }
        }
        
        // 检查并添加 brand_profile_position 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'brand_profile_position'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `brand_profile_position` varchar(50) NOT NULL DEFAULT 'before_second_paragraph' COMMENT '品牌资料插入位置' AFTER `enable_brand_profile_insertion`");
            if ($result !== false) {
                $updates_applied = true;
            }
        }
        
        return $updates_applied;
    }

    /**
     * 更新品牌资料表，添加自定义HTML类型支持
     */
    public function update_brand_profiles_for_custom_html() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'content_auto_brand_profiles';
        
        // 检查表是否存在
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if (!$table_exists) {
            return false;
        }
        
        $updates_applied = false;
        
        // 检查并添加 type 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'type'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `type` varchar(20) NOT NULL DEFAULT 'standard' COMMENT '物料类型：standard标准样式，custom_html自定义HTML，reference参考资料' AFTER `title`");
            if ($result !== false) {
                $updates_applied = true;
            }
        } else {
            // 更新已存在的type字段注释，增加参考资料类型说明
            $wpdb->query("ALTER TABLE $table_name MODIFY COLUMN `type` varchar(20) NOT NULL DEFAULT 'standard' COMMENT '物料类型：standard标准样式，custom_html自定义HTML，reference参考资料'");
        }
        
        // 检查并添加 custom_html 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'custom_html'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `custom_html` longtext DEFAULT NULL COMMENT '自定义HTML代码' AFTER `link`");
            if ($result !== false) {
                $updates_applied = true;
            }
        }
        
        // 修改image_url字段，允许为NULL（自定义HTML类型可能不需要图片）
        $column_info = $wpdb->get_row("SHOW COLUMNS FROM $table_name LIKE 'image_url'");
        if ($column_info && $column_info->Null === 'NO') {
            $wpdb->query("ALTER TABLE $table_name MODIFY COLUMN `image_url` text DEFAULT NULL COMMENT '图片URL，标准样式必填，自定义HTML可选'");
            $updates_applied = true;
        }
        
        // 添加类型字段的索引以优化查询性能
        $index_exists = $wpdb->get_var("SHOW INDEX FROM $table_name WHERE Column_name = 'type'");
        if (!$index_exists) {
            $wpdb->query("ALTER TABLE $table_name ADD KEY `idx_type` (`type`)");
        }
        
        return $updates_applied;
    }

  /**
     * 更新规则表结构，添加参考资料字段
     */
    public function update_rules_table_for_reference_material() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'content_auto_rules';

        // 检查表是否存在
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if (!$table_exists) {
            return false;
        }

        $updates_applied = false;

        // 检查并添加 reference_material 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'reference_material'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `reference_material` text DEFAULT NULL COMMENT '参考资料，用于文章生成提示词，最多500字符' AFTER `rule_task_id`");
            if ($result !== false) {
                $updates_applied = true;
            }
        }

        return $updates_applied;
    }

    /**
     * 更新发布规则表，添加参考资料功能字段
     */
    public function update_publish_rules_for_reference_material() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'content_auto_publish_rules';

        // 检查表是否存在
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if (!$table_exists) {
            return false;
        }

        $updates_applied = false;

        // 检查并添加 enable_reference_material 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'enable_reference_material'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `enable_reference_material` tinyint(1) NOT NULL DEFAULT '0' COMMENT '启用参考资料功能' AFTER `enable_brand_profile_insertion`");
            if ($result !== false) {
                $updates_applied = true;
            }
        }

        return $updates_applied;
    }

    /**
     * 获取仪表盘统计数据
     */
    public function get_dashboard_stats() {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $stats = array();

        // API配置统计
        $stats['api_configs'] = array(
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_api_configs"),
            'active' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_api_configs WHERE is_active = 1"),
            'with_vector' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_api_configs WHERE vector_api_url IS NOT NULL AND vector_api_url != ''")
        );

        // 规则统计
        $stats['rules'] = array(
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_rules"),
            'active' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_rules WHERE status = 1")
        );

        // 主题任务统计
        $stats['topic_tasks'] = array(
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_topic_tasks"),
            'pending' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_topic_tasks WHERE status = 'pending'"),
            'processing' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_topic_tasks WHERE status = 'processing'"),
            'completed' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_topic_tasks WHERE status = 'completed'"),
            'failed' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_topic_tasks WHERE status = 'failed'"),
            'total_expected_topics' => $wpdb->get_var("SELECT SUM(total_expected_topics) FROM {$prefix}content_auto_topic_tasks"),
            'generated_topics_count' => $wpdb->get_var("SELECT SUM(generated_topics_count) FROM {$prefix}content_auto_topic_tasks")
        );

        // 主题统计
        $stats['topics'] = array(
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_topics"),
            'unused' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_topics WHERE status = 'unused'"),
            'queued' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_topics WHERE status = 'queued'"),
            'used' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_topics WHERE status = 'used'"),
            'with_vectors' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_topics WHERE vector_embedding IS NOT NULL AND vector_embedding != ''"),
            'vector_pending' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_topics WHERE vector_status = 'pending'"),
            'vector_processing' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_topics WHERE vector_status = 'processing'"),
            'vector_completed' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_topics WHERE vector_status = 'completed'"),
            'vector_failed' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_topics WHERE vector_status = 'failed'"),
            'high_priority' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_topics WHERE priority_score >= 4"),
            'clusters' => $wpdb->get_var("SELECT COUNT(DISTINCT vector_cluster_id) FROM {$prefix}content_auto_topics WHERE vector_cluster_id IS NOT NULL")
        );

        // 文章任务统计
        $stats['article_tasks'] = array(
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_article_tasks"),
            'pending' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_article_tasks WHERE status = 'pending'"),
            'processing' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_article_tasks WHERE status = 'processing'"),
            'completed' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_article_tasks WHERE status = 'completed'"),
            'failed' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_article_tasks WHERE status = 'failed'"),
            'total_topics' => $wpdb->get_var("SELECT SUM(total_topics) FROM {$prefix}content_auto_article_tasks"),
            'completed_topics' => $wpdb->get_var("SELECT SUM(completed_topics) FROM {$prefix}content_auto_article_tasks"),
            'failed_topics' => $wpdb->get_var("SELECT SUM(failed_topics) FROM {$prefix}content_auto_article_tasks")
        );

        // 文章统计
        $stats['articles'] = array(
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_articles"),
            'pending' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_articles WHERE status = 'pending'"),
            'processing' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_articles WHERE status = 'processing'"),
            'completed' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_articles WHERE status = 'completed'"),
            'failed' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_articles WHERE status = 'failed'"),
            'published' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_articles WHERE post_id > 0"),
            'total_words' => $wpdb->get_var("SELECT SUM(word_count) FROM {$prefix}content_auto_articles"),
            'avg_processing_time' => $wpdb->get_var("SELECT AVG(processing_time) FROM {$prefix}content_auto_articles WHERE processing_time > 0"),
            'with_auto_images' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_articles WHERE auto_images_processed = 1"),
            'total_auto_images' => $wpdb->get_var("SELECT SUM(auto_images_count) FROM {$prefix}content_auto_articles")
        );

        // 队列统计
        $stats['queue'] = array(
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_job_queue"),
            'pending' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_job_queue WHERE status = 'pending'"),
            'processing' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_job_queue WHERE status = 'processing'"),
            'completed' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_job_queue WHERE status = 'completed'"),
            'failed' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_job_queue WHERE status = 'failed'"),
            'topic_jobs' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_job_queue WHERE job_type = 'topic'"),
            'article_jobs' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_job_queue WHERE job_type = 'article'"),
            'vector_jobs' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_job_queue WHERE job_type = 'vector'"),
            'high_priority' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_job_queue WHERE priority >= 8")
        );

        // 发布规则统计
        $stats['publish_rules'] = array(
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_publish_rules"),
            'auto_publish_enabled' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_publish_rules WHERE post_status = 'publish'"),
            'auto_images_enabled' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_publish_rules WHERE auto_image_insertion = 1"),
            'internal_linking_enabled' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_publish_rules WHERE enable_internal_linking = 1")
        );

        // 文章结构统计
        $stats['article_structures'] = array(
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_article_structures"),
            'with_vectors' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_article_structures WHERE title_vector IS NOT NULL AND title_vector != ''"),
            'total_usage' => $wpdb->get_var("SELECT SUM(usage_count) FROM {$prefix}content_auto_article_structures")
        );

        // 系统统计
        $stats['system'] = array(
            'total_generated_content' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_topics") + $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_articles"),
            'last_activity' => $wpdb->get_var("SELECT MAX(updated_at) FROM {$prefix}content_auto_job_queue UNION SELECT MAX(updated_at) FROM {$prefix}content_auto_topics UNION SELECT MAX(updated_at) FROM {$prefix}content_auto_articles ORDER BY MAX(updated_at) DESC LIMIT 1"),
            'success_rate' => $this->calculate_success_rate(),
            'avg_daily_output' => $this->calculate_daily_output_average()
        );

        return $stats;
    }

    /**
     * 计算任务成功率
     */
    private function calculate_success_rate() {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $total_completed = $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_job_queue WHERE status IN ('completed', 'failed')");
        $total_successful = $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_job_queue WHERE status = 'completed'");

        return $total_completed > 0 ? round(($total_successful / $total_completed) * 100, 2) : 0;
    }

    /**
     * 计算日均输出量
     */
    private function calculate_daily_output_average() {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $earliest_date = $wpdb->get_var("SELECT MIN(DATE(created_at)) FROM {$prefix}content_auto_articles WHERE created_at IS NOT NULL");

        if (!$earliest_date) {
            return 0;
        }

        $days_diff = max(1, (strtotime(date('Y-m-d')) - strtotime($earliest_date)) / 86400);
        $total_articles = $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}content_auto_articles");

        return round($total_articles / $days_diff, 2);
    }

    /**
     * 确保发布规则表数据完整性
     * 为全新安装和现有用户提供统一的数据结构保障
     */
    public function ensure_publish_rules_data_integrity() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'content_auto_publish_rules';

        // 检查表是否存在
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if (!$table_exists) {
            return false;
        }

        $updates_applied = [];

        // 定义所有应该存在的字段及其默认值
        $required_fields = [
            'max_auto_images' => [
                'sql' => "ALTER TABLE $table_name ADD COLUMN `max_auto_images` int(11) NOT NULL DEFAULT '1' COMMENT '最大自动生成图片数量' AFTER `auto_image_insertion`",
                'description' => '最大自动生成图片数量字段'
            ],
            'skip_first_image_placeholder' => [
                'sql' => "ALTER TABLE $table_name ADD COLUMN `skip_first_image_placeholder` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否跳过首个图片占位符' AFTER `max_auto_images`",
                'description' => '跳过首个图片占位符字段'
            ],
            'enable_internal_linking' => [
                'sql' => "ALTER TABLE $table_name ADD COLUMN `enable_internal_linking` tinyint(1) NOT NULL DEFAULT '0' COMMENT '启用文章内链功能' AFTER `skip_first_image_placeholder`",
                'description' => '文章内链功能字段'
            ],
            'publish_interval_minutes' => [
                'sql' => "ALTER TABLE $table_name ADD COLUMN `publish_interval_minutes` int(11) NOT NULL DEFAULT '0' COMMENT '发布间隔时间（分钟），0表示立即发布' AFTER `enable_internal_linking`",
                'description' => '发布间隔时间字段'
            ],
            'enable_brand_profile_insertion' => [
                'sql' => "ALTER TABLE $table_name ADD COLUMN `enable_brand_profile_insertion` tinyint(1) NOT NULL DEFAULT '0' COMMENT '启用品牌资料插入功能' AFTER `publish_interval_minutes`",
                'description' => '品牌资料插入功能字段'
            ],
            'brand_profile_position' => [
                'sql' => "ALTER TABLE $table_name ADD COLUMN `brand_profile_position` varchar(50) NOT NULL DEFAULT 'before_second_paragraph' COMMENT '品牌资料插入位置' AFTER `enable_brand_profile_insertion`",
                'description' => '品牌资料插入位置字段'
            ],
            'enable_reference_material' => [
                'sql' => "ALTER TABLE $table_name ADD COLUMN `enable_reference_material` tinyint(1) NOT NULL DEFAULT '0' COMMENT '启用参考资料功能' AFTER `brand_profile_position`",
                'description' => '参考资料功能字段'
            ],
            'enable_ai_reference_select' => [
                'sql' => "ALTER TABLE $table_name ADD COLUMN `enable_ai_reference_select` tinyint(1) NOT NULL DEFAULT '0' COMMENT '启用大模型精选召回' AFTER `enable_reference_material`",
                'description' => '大模型精选召回字段'
            ],
            'enable_intent_inference' => [
                'sql' => "ALTER TABLE $table_name ADD COLUMN `enable_intent_inference` tinyint(1) NOT NULL DEFAULT '0' COMMENT '启用搜索意图推断' AFTER `enable_ai_reference_select`",
                'description' => '搜索意图推断字段'
            ],
            'publish_language' => [
                'sql' => "ALTER TABLE $table_name ADD COLUMN `publish_language` varchar(10) NOT NULL DEFAULT 'zh-CN' COMMENT '发布语言，影响内容生成的输出语言' AFTER `enable_intent_inference`",
                'description' => '发布语言字段'
            ],
            'image_prompt_template' => [
                'sql' => "ALTER TABLE $table_name ADD COLUMN `image_prompt_template` text NOT NULL COMMENT '图片提示词模板' AFTER `role_description`",
                'description' => '图片提示词模板字段'
            ]
        ];

        // 检查并添加缺失的字段
        foreach ($required_fields as $field_name => $field_config) {
            $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE '$field_name'");
            if (!$column_exists) {
                $result = $wpdb->query($field_config['sql']);
                if ($result !== false) {
                    $updates_applied[] = $field_config['description'];
                }
            }
        }

        // 修正历史数据中不一致的默认值
        $this->fix_publish_rules_default_values();

        // 确保至少有一条默认发布规则记录
        $this->ensure_default_publish_rule_exists();

        return !empty($updates_applied);
    }

    /**
     * 修正发布规则表中不一致的默认值
     */
    private function fix_publish_rules_default_values() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'content_auto_publish_rules';

        // 检查并修正 knowledge_depth 和 reader_role 字段的默认值
        $field_updates = [
            'knowledge_depth' => [
                'old_default' => '实用指导',
                'new_default' => '未设置',
                'sql' => "ALTER TABLE $table_name MODIFY COLUMN `knowledge_depth` varchar(20) NOT NULL DEFAULT '未设置'"
            ],
            'reader_role' => [
                'old_default' => '潜在客户', 
                'new_default' => '未设置',
                'sql' => "ALTER TABLE $table_name MODIFY COLUMN `reader_role` varchar(20) NOT NULL DEFAULT '未设置'"
            ]
        ];

        foreach ($field_updates as $field_name => $config) {
            // 检查当前字段定义
            $column_info = $wpdb->get_row("SHOW COLUMNS FROM $table_name LIKE '$field_name'");
            
            if ($column_info && strpos($column_info->Default, $config['old_default']) !== false) {
                // 更新字段默认值
                $wpdb->query($config['sql']);
                
                // 更新现有记录中的旧默认值为新默认值
                $wpdb->update(
                    $table_name,
                    [$field_name => $config['new_default']],
                    [$field_name => $config['old_default']]
                );
            }
        }
    }

    /**
     * 确保存在默认发布规则记录
     */
    private function ensure_default_publish_rule_exists() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'content_auto_publish_rules';

        // 检查是否已存在发布规则记录
        $rule_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        
        if ($rule_count == 0) {
            // 创建默认发布规则记录
            $default_rule = [
                'post_status' => CONTENT_AUTO_PUBLISH_STATUS_DRAFT,
                'author_id' => get_current_user_id() ?: 1, // 如果无法获取当前用户，使用管理员ID
                'category_mode' => 'manual',
                'category_ids' => '',
                'fallback_category_ids' => '',
                'target_length' => '800-1500',
                'knowledge_depth' => '未设置',
                'reader_role' => '未设置',
                'normalize_output' => 0,
                'auto_image_insertion' => 0,
                'max_auto_images' => 1,
                'skip_first_image_placeholder' => 0,
                'enable_internal_linking' => 0,
                'publish_interval_minutes' => 0,
                'enable_brand_profile_insertion' => 0,
                'brand_profile_position' => 'before_second_paragraph',
                'enable_reference_material' => 0,
                'enable_ai_reference_select' => 0,
                'publish_language' => 'zh-CN',
                'image_prompt_template' => ''
            ];

            $wpdb->insert($table_name, $default_rule);
        }
    }

    /**
     * 运行智能结构优化数据库迁移
     * 
     * @return array 迁移结果
     */
    public function run_structure_optimization_migration() {
        // 加载迁移类
        require_once dirname(__FILE__) . '/class-structure-optimization-migration.php';
        
        $migration = new ContentAuto_StructureOptimizationMigration();
        return $migration->run();
    }

    /**
     * 验证智能结构优化数据库迁移状态
     * 
     * @return array 验证结果
     */
    public function verify_structure_optimization_migration() {
        // 加载迁移类
        require_once dirname(__FILE__) . '/class-structure-optimization-migration.php';
        
        $migration = new ContentAuto_StructureOptimizationMigration();
        return $migration->verify();
    }

    /**
     * 获取智能结构优化迁移状态摘要
     * 
     * @return string 状态摘要
     */
    public function get_structure_optimization_migration_status() {
        // 加载迁移类
        require_once dirname(__FILE__) . '/class-structure-optimization-migration.php';
        
        $migration = new ContentAuto_StructureOptimizationMigration();
        return $migration->get_status_summary();
    }

    /**
     * 更新数据库结构以支持自动素材搜索
     */
    public function update_database_for_auto_material_search() {
        global $wpdb;
        
        // 1. 更新发布规则表，添加 `enable_auto_material_search`
        $publish_rules_table = $wpdb->prefix . 'content_auto_publish_rules';
        // 检查表是否存在
        if ($wpdb->get_var("SHOW TABLES LIKE '$publish_rules_table'") != $publish_rules_table) {
            return;
        }
        
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $publish_rules_table LIKE 'enable_auto_material_search'");
        if (!$column_exists) {
            $wpdb->query("ALTER TABLE $publish_rules_table ADD COLUMN `enable_auto_material_search` tinyint(1) NOT NULL DEFAULT '0' COMMENT '启用自动素材搜索功能' AFTER `enable_ai_reference_select`");
        }
        
        // 2. 更新主题表，添加 `material_search_status` 和 `material_search_error`
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        // 检查表是否存在
        if ($wpdb->get_var("SHOW TABLES LIKE '$topics_table'") != $topics_table) {
            return;
        }
        
        $status_column_exists = $wpdb->get_var("SHOW COLUMNS FROM $topics_table LIKE 'material_search_status'");
        if (!$status_column_exists) {
            $wpdb->query("ALTER TABLE $topics_table ADD COLUMN `material_search_status` varchar(20) NOT NULL DEFAULT 'none' COMMENT '自动素材搜索状态' AFTER `status`");
        } else {
            // 确保默认值为 none (修复可能存在的旧 schema)
            $wpdb->query("ALTER TABLE $topics_table MODIFY COLUMN `material_search_status` varchar(20) NOT NULL DEFAULT 'none' COMMENT '自动素材搜索状态'");
        }
        
        $error_column_exists = $wpdb->get_var("SHOW COLUMNS FROM $topics_table LIKE 'material_search_error'");
        if (!$error_column_exists) {
            $wpdb->query("ALTER TABLE $topics_table ADD COLUMN `material_search_error` text DEFAULT NULL COMMENT '自动素材搜索错误信息' AFTER `material_search_status`");
        }
    }

    /**
     * 更新数据库结构以支持素材收集模式选择
     * 新增 material_collection_mode 字段：none / search_engine / extension_rag
     */
    public function update_database_for_material_collection_mode() {
        global $wpdb;
        
        $publish_rules_table = $wpdb->prefix . 'content_auto_publish_rules';
        
        // 检查表是否存在
        if ($wpdb->get_var("SHOW TABLES LIKE '$publish_rules_table'") != $publish_rules_table) {
            return;
        }
        
        // 检查 material_collection_mode 字段是否存在
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $publish_rules_table LIKE 'material_collection_mode'");
        if (!$column_exists) {
            // 添加新字段
            $wpdb->query("ALTER TABLE $publish_rules_table ADD COLUMN `material_collection_mode` varchar(20) NOT NULL DEFAULT 'none' COMMENT '素材收集模式：none/search_engine/extension_rag' AFTER `enable_auto_material_search`");
            
            // 迁移旧数据：如果 enable_auto_material_search = 1，则设置为 search_engine
            $wpdb->query("UPDATE $publish_rules_table SET material_collection_mode = 'search_engine' WHERE enable_auto_material_search = 1");
        }
    }

    /**
     * 更新提示词模板表结构，添加 source_file 字段
     */
    private function update_prompt_templates_table_structure($table_name) {
        global $wpdb;

        // 检查表是否存在
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if (!$table_exists) {
            return;
        }

        // 检查并添加 source_file 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'source_file'");
        if (!$column_exists) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN `source_file` varchar(255) DEFAULT NULL COMMENT '源文件名，用于防止重复导入' AFTER `content`");
            
            // 为现有模板数据填充 source_file 值（根据名称推断）
            $this->populate_source_file_for_existing_templates($table_name);
        }
    }

    /**
     * 为现有模板填充 source_file 值
     */
    private function populate_source_file_for_existing_templates($table_name) {
        global $wpdb;

        // 名称到文件名的映射（兼容新旧名称）
        $name_to_file_mapping = [
            // 旧英文名称
            'Article Generation - Optimized V12' => 'article-generation-prompt-optimized-v12.xml',
            'Article Generation - Default' => 'article-generation-prompt.xml',
            'Article Generation - Variant 1 (Exhaustive)' => 'article-generation-prompt1.xml',
            'Article Generation - Variant 2 (Concise)' => 'article-generation-prompt2.xml',
            'Topic Generation - Default' => 'topic-generation-prompt.xml',
            'Topic Generation - Variant 1 (Creative)' => 'topic1-generation-prompt.xml',
            // 新中文名称
            '文章生成 - V12优化版（反AI检测+E-E-A-T优化）' => 'article-generation-prompt-optimized-v12.xml',
            '文章生成 - 默认版（基础通用）' => 'article-generation-prompt.xml',
            '文章生成 - 变体1（详尽版）' => 'article-generation-prompt1.xml',
            '文章生成 - 变体2（精简版）' => 'article-generation-prompt2.xml',
            '文章生成 - 技术文档风格（专业改写、术语准确）' => 'article-generation-prompt-technical.xml',
            '文章生成 - 学术论文风格（降AI率、专家讲解感）' => 'article-generation-prompt-academic.xml',
            '文章生成 - 朴素对话风格（朋友聊天、真诚分享）' => 'article-generation-prompt-casual.xml',
            '文章生成 - 小红书风格（种草测评、互动感强）' => 'article-generation-prompt-xiaohongshu.xml',
            '文章生成 - 高级反AI检测（嵌入分散控制）' => 'article-generation-prompt-anti-ai.xml',
            '文章生成 - 人性化润色风格（通俗易懂、温度感强）' => 'article-generation-prompt-humanized.xml',
            '文章生成 - 产品实用指南（直接实用、结构清晰）' => 'article-generation-prompt-product-guide.xml',
            '主题生成 - 默认版（标准生成）' => 'topic-generation-prompt.xml',
            '主题生成 - 变体1（创意发散）' => 'topic1-generation-prompt.xml',
            '主题生成 - 术语实体型（百科词条式）' => 'topic-generation-prompt-entity.xml',
        ];

        foreach ($name_to_file_mapping as $name => $filename) {
            $wpdb->update(
                $table_name,
                array('source_file' => $filename),
                array('name' => $name, 'source_file' => null),
                array('%s'),
                array('%s', '%s')
            );
        }
    }

    /**
     * 预置默认提示词模板
     */
    private function seed_default_templates($table_name) {
        global $wpdb;

        // 定义要导入的模板文件及其名称和类型
        $templates_to_seed = [
            // 文章生成模板 - 核心版本（默认启用）
            'article-generation-prompt-optimized-v12.xml' => [
                'name' => '文章生成 - V12优化版（反AI检测+E-E-A-T优化）',
                'type' => 'article_generation',
                'is_active' => true
            ],
            'article-generation-prompt.xml' => [
                'name' => '文章生成 - 默认版（基础通用）',
                'type' => 'article_generation',
                'is_active' => true
            ],
            'article-generation-prompt1.xml' => [
                'name' => '文章生成 - 变体1（详尽版）',
                'type' => 'article_generation',
                'is_active' => true
            ],
            'article-generation-prompt2.xml' => [
                'name' => '文章生成 - 变体2（精简版）',
                'type' => 'article_generation',
                'is_active' => true
            ],
            // 文章生成模板 - 风格专用版本（默认禁用，需手动启用）
            'article-generation-prompt-technical.xml' => [
                'name' => '文章生成 - 技术文档风格（专业改写、术语准确）',
                'type' => 'article_generation',
                'is_active' => false
            ],
            'article-generation-prompt-academic.xml' => [
                'name' => '文章生成 - 学术论文风格（降AI率、专家讲解感）',
                'type' => 'article_generation',
                'is_active' => false
            ],
            'article-generation-prompt-casual.xml' => [
                'name' => '文章生成 - 朴素对话风格（朋友聊天、真诚分享）',
                'type' => 'article_generation',
                'is_active' => false
            ],
            'article-generation-prompt-xiaohongshu.xml' => [
                'name' => '文章生成 - 小红书风格（种草测评、互动感强）',
                'type' => 'article_generation',
                'is_active' => false
            ],
            'article-generation-prompt-anti-ai.xml' => [
                'name' => '文章生成 - 高级反AI检测（嵌入分散控制）',
                'type' => 'article_generation',
                'is_active' => false
            ],
            'article-generation-prompt-humanized.xml' => [
                'name' => '文章生成 - 人性化润色风格（通俗易懂、温度感强）',
                'type' => 'article_generation',
                'is_active' => false
            ],
            'article-generation-prompt-product-guide.xml' => [
                'name' => '文章生成 - 产品实用指南（直接实用、结构清晰）',
                'type' => 'article_generation',
                'is_active' => false
            ],
            // 主题生成模板（默认启用）
            'topic-generation-prompt.xml' => [
                'name' => '主题生成 - 默认版（标准生成）',
                'type' => 'topic_generation',
                'is_active' => true
            ],
            'topic1-generation-prompt.xml' => [
                'name' => '主题生成 - 变体1（创意发散）',
                'type' => 'topic_generation',
                'is_active' => true
            ],
            'topic-generation-prompt-entity.xml' => [
                'name' => '主题生成 - 术语实体型（百科词条式）',
                'type' => 'topic_generation',
                'is_active' => false
            ]
        ];

        $templates_dir = CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'prompt-templating/';

        foreach ($templates_to_seed as $filename => $info) {
            $file_path = $templates_dir . $filename;
            
            // 检查文件是否存在
            if (!file_exists($file_path)) {
                continue;
            }

            // 按源文件名检查是否已导入（即使用户修改了模板名称也不会重复导入）
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE source_file = %s",
                $filename
            ));

            if ($exists > 0) {
                continue; // 该文件已导入，跳过
            }

            // 读取文件内容
            $content = file_get_contents($file_path);
            if ($content === false) {
                continue;
            }

            // 插入数据库（使用模板配置的is_active值，记录源文件名）
            $wpdb->insert(
                $table_name,
                array(
                    'name' => $info['name'],
                    'template_type' => $info['type'],
                    'content' => $content,
                    'source_file' => $filename,
                    'is_active' => $info['is_active'] ? 1 : 0,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ),
                array('%s', '%s', '%s', '%s', '%d', '%s', '%s')
            );
        }
    }
}