<?php
/**
 * 智能文章结构优化系统 - 数据库迁移类
 * 
 * 负责创建和更新智能结构优化功能所需的数据库表结构
 * 
 * @package ContentAuto
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_StructureOptimizationMigration {
    
    /**
     * 数据库前缀
     */
    private $prefix;
    
    /**
     * 迁移结果
     */
    private $results = array(
        'success' => true,
        'tables_created' => array(),
        'columns_added' => array(),
        'indexes_added' => array(),
        'errors' => array()
    );
    
    /**
     * 构造函数
     */
    public function __construct() {
        global $wpdb;
        $this->prefix = $wpdb->prefix;
    }
    
    /**
     * 执行所有迁移
     * 
     * @return array 迁移结果
     */
    public function run() {
        // 1. 扩展 article_structures 表
        $this->extend_article_structures_table();
        
        // 2. 扩展 topics 表
        $this->extend_topics_table();
        
        // 3. 创建 structure_analytics 表
        $this->create_structure_analytics_table();
        
        // 4. 创建 optimization_config 表
        $this->create_optimization_config_table();
        
        // 5. 插入默认配置
        $this->insert_default_configs();
        
        return $this->results;
    }
    
    /**
     * 扩展 article_structures 表
     * 添加 source_type, source_article_id, extracted_at 字段
     */
    private function extend_article_structures_table() {
        global $wpdb;
        
        $table_name = $this->prefix . 'content_auto_article_structures';
        
        // 检查表是否存在
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if (!$table_exists) {
            $this->results['errors'][] = "表 $table_name 不存在，请先运行基础数据库迁移";
            $this->results['success'] = false;
            return;
        }
        
        // 添加 source_type 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'source_type'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `source_type` ENUM('ai_generated', 'data_driven') NOT NULL DEFAULT 'ai_generated' COMMENT '结构来源类型' AFTER `usage_count`");
            if ($result !== false) {
                $this->results['columns_added'][] = "$table_name.source_type";
            } else {
                $this->results['errors'][] = "添加 $table_name.source_type 字段失败: " . $wpdb->last_error;
                $this->results['success'] = false;
            }
        }
        
        // 添加 source_article_id 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'source_article_id'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `source_article_id` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT '来源文章ID（数据驱动结构）' AFTER `source_type`");
            if ($result !== false) {
                $this->results['columns_added'][] = "$table_name.source_article_id";
            } else {
                $this->results['errors'][] = "添加 $table_name.source_article_id 字段失败: " . $wpdb->last_error;
                $this->results['success'] = false;
            }
        }
        
        // 添加 extracted_at 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'extracted_at'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `extracted_at` DATETIME DEFAULT NULL COMMENT '结构提取时间' AFTER `source_article_id`");
            if ($result !== false) {
                $this->results['columns_added'][] = "$table_name.extracted_at";
            } else {
                $this->results['errors'][] = "添加 $table_name.extracted_at 字段失败: " . $wpdb->last_error;
                $this->results['success'] = false;
            }
        }
        
        // 添加 source_type 索引
        $index_exists = $wpdb->get_var("SHOW INDEX FROM $table_name WHERE Key_name = 'idx_source_type'");
        if (!$index_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD INDEX `idx_source_type` (`source_type`)");
            if ($result !== false) {
                $this->results['indexes_added'][] = "$table_name.idx_source_type";
            }
        }
        
        // 添加 source_article_id 索引
        $index_exists = $wpdb->get_var("SHOW INDEX FROM $table_name WHERE Key_name = 'idx_source_article_id'");
        if (!$index_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD INDEX `idx_source_article_id` (`source_article_id`)");
            if ($result !== false) {
                $this->results['indexes_added'][] = "$table_name.idx_source_article_id";
            }
        }
    }
    
    /**
     * 扩展 topics 表
     * 添加 used_structure_id, selection_method, selection_weight 字段
     */
    private function extend_topics_table() {
        global $wpdb;
        
        $table_name = $this->prefix . 'content_auto_topics';
        
        // 检查表是否存在
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if (!$table_exists) {
            $this->results['errors'][] = "表 $table_name 不存在，请先运行基础数据库迁移";
            $this->results['success'] = false;
            return;
        }
        
        // 添加 used_structure_id 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'used_structure_id'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `used_structure_id` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT '实际使用的结构ID' AFTER `reference_material`");
            if ($result !== false) {
                $this->results['columns_added'][] = "$table_name.used_structure_id";
            } else {
                $this->results['errors'][] = "添加 $table_name.used_structure_id 字段失败: " . $wpdb->last_error;
                $this->results['success'] = false;
            }
        }
        
        // 添加 selection_method 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'selection_method'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `selection_method` VARCHAR(20) DEFAULT NULL COMMENT '结构选择方法：exploration/exploitation/fallback' AFTER `used_structure_id`");
            if ($result !== false) {
                $this->results['columns_added'][] = "$table_name.selection_method";
            } else {
                $this->results['errors'][] = "添加 $table_name.selection_method 字段失败: " . $wpdb->last_error;
                $this->results['success'] = false;
            }
        }
        
        // 添加 selection_weight 字段
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'selection_weight'");
        if (!$column_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN `selection_weight` DECIMAL(10,4) DEFAULT NULL COMMENT '选择时的权重值' AFTER `selection_method`");
            if ($result !== false) {
                $this->results['columns_added'][] = "$table_name.selection_weight";
            } else {
                $this->results['errors'][] = "添加 $table_name.selection_weight 字段失败: " . $wpdb->last_error;
                $this->results['success'] = false;
            }
        }
        
        // 添加 used_structure_id 索引
        $index_exists = $wpdb->get_var("SHOW INDEX FROM $table_name WHERE Key_name = 'idx_used_structure_id'");
        if (!$index_exists) {
            $result = $wpdb->query("ALTER TABLE $table_name ADD INDEX `idx_used_structure_id` (`used_structure_id`)");
            if ($result !== false) {
                $this->results['indexes_added'][] = "$table_name.idx_used_structure_id";
            }
        }
    }

    
    /**
     * 创建 structure_analytics 表
     */
    private function create_structure_analytics_table() {
        global $wpdb;
        
        $table_name = $this->prefix . 'content_auto_structure_analytics';
        $charset_collate = $wpdb->get_charset_collate();
        
        // 检查表是否已存在
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if ($table_exists) {
            return; // 表已存在，跳过创建
        }
        
        $sql = "CREATE TABLE `$table_name` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `structure_id` BIGINT(20) UNSIGNED NOT NULL COMMENT '结构ID',
            `date` DATE NOT NULL COMMENT '统计日期',
            `usage_count` INT(11) NOT NULL DEFAULT 0 COMMENT '当日使用次数',
            `avg_visits` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '平均访问量',
            `popularity_index` DECIMAL(10,4) NOT NULL DEFAULT 100 COMMENT '受欢迎度指数',
            `entropy_contribution` DECIMAL(10,4) NOT NULL DEFAULT 0 COMMENT '熵贡献值',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_structure_date` (`structure_id`, `date`),
            KEY `idx_structure_id` (`structure_id`),
            KEY `idx_date` (`date`)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // 验证表是否创建成功
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if ($table_exists) {
            $this->results['tables_created'][] = $table_name;
        } else {
            $this->results['errors'][] = "创建表 $table_name 失败: " . $wpdb->last_error;
            $this->results['success'] = false;
        }
    }
    
    /**
     * 创建 optimization_config 表
     */
    private function create_optimization_config_table() {
        global $wpdb;
        
        $table_name = $this->prefix . 'content_auto_optimization_config';
        $charset_collate = $wpdb->get_charset_collate();
        
        // 检查表是否已存在
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if ($table_exists) {
            return; // 表已存在，跳过创建
        }
        
        $sql = "CREATE TABLE `$table_name` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `config_key` VARCHAR(100) NOT NULL COMMENT '配置键名',
            `config_value` TEXT COMMENT '配置值',
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_config_key` (`config_key`)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // 验证表是否创建成功
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if ($table_exists) {
            $this->results['tables_created'][] = $table_name;
        } else {
            $this->results['errors'][] = "创建表 $table_name 失败: " . $wpdb->last_error;
            $this->results['success'] = false;
        }
    }
    
    /**
     * 插入默认配置值
     */
    private function insert_default_configs() {
        global $wpdb;
        
        $table_name = $this->prefix . 'content_auto_optimization_config';
        
        // 检查表是否存在
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if (!$table_exists) {
            return;
        }
        
        // 默认配置值
        $defaults = array(
            'smart_optimization_enabled' => '0',
            'exploration_rate' => '0.3',
            'softmax_temperature' => '1.0',
            'visit_threshold_percentile' => '80',
            'batch_diversity_threshold' => '0.25',
            'batch_diversity_penalty' => '0.3',
            'window_diversity_threshold' => '0.30',
            'window_diversity_penalty' => '0.3',
            'new_structure_boost' => '2.0',
            'new_structure_boost_uses' => '5',
            'min_entropy_threshold' => '1.5',
            'analysis_schedule_hour' => '3',
            'min_articles_for_analysis' => '10',
            'min_days_published' => '7',
            'max_articles_per_angle' => '5',
            'time_decay_30_days' => '1.0',
            'time_decay_30_90_days' => '0.7',
            'time_decay_90_plus_days' => '0.4',
            'confidence_min_articles' => '3'
        );
        
        foreach ($defaults as $key => $value) {
            // 检查配置是否已存在
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE config_key = %s",
                $key
            ));
            
            if (!$exists) {
                $wpdb->insert(
                    $table_name,
                    array(
                        'config_key' => $key,
                        'config_value' => $value
                    ),
                    array('%s', '%s')
                );
            }
        }
    }
    
    /**
     * 验证迁移结果
     * 
     * @return array 验证结果
     */
    public function verify() {
        global $wpdb;
        
        $verification = array(
            'success' => true,
            'article_structures' => array(),
            'topics' => array(),
            'structure_analytics' => array(),
            'optimization_config' => array()
        );
        
        // 验证 article_structures 表扩展
        $table_name = $this->prefix . 'content_auto_article_structures';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        $verification['article_structures']['table_exists'] = $table_exists;
        
        if ($table_exists) {
            $verification['article_structures']['source_type'] = (bool) $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'source_type'");
            $verification['article_structures']['source_article_id'] = (bool) $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'source_article_id'");
            $verification['article_structures']['extracted_at'] = (bool) $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'extracted_at'");
            $verification['article_structures']['idx_source_type'] = (bool) $wpdb->get_var("SHOW INDEX FROM $table_name WHERE Key_name = 'idx_source_type'");
            $verification['article_structures']['idx_source_article_id'] = (bool) $wpdb->get_var("SHOW INDEX FROM $table_name WHERE Key_name = 'idx_source_article_id'");
        }
        
        // 验证 topics 表扩展
        $table_name = $this->prefix . 'content_auto_topics';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        $verification['topics']['table_exists'] = $table_exists;
        
        if ($table_exists) {
            $verification['topics']['used_structure_id'] = (bool) $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'used_structure_id'");
            $verification['topics']['selection_method'] = (bool) $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'selection_method'");
            $verification['topics']['selection_weight'] = (bool) $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'selection_weight'");
            $verification['topics']['idx_used_structure_id'] = (bool) $wpdb->get_var("SHOW INDEX FROM $table_name WHERE Key_name = 'idx_used_structure_id'");
        }
        
        // 验证 structure_analytics 表
        $table_name = $this->prefix . 'content_auto_structure_analytics';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        $verification['structure_analytics']['table_exists'] = $table_exists;
        
        if ($table_exists) {
            $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_name", ARRAY_A);
            $column_names = array_column($columns, 'Field');
            $verification['structure_analytics']['columns'] = $column_names;
        }
        
        // 验证 optimization_config 表
        $table_name = $this->prefix . 'content_auto_optimization_config';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        $verification['optimization_config']['table_exists'] = $table_exists;
        
        if ($table_exists) {
            $config_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
            $verification['optimization_config']['config_count'] = (int) $config_count;
            $verification['optimization_config']['has_defaults'] = $config_count >= 19; // 至少19个默认配置
        }
        
        // 检查是否所有验证都通过
        foreach ($verification as $key => $value) {
            if ($key === 'success') continue;
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    if ($v === false) {
                        $verification['success'] = false;
                        break 2;
                    }
                }
            }
        }
        
        return $verification;
    }
    
    /**
     * 回滚迁移（用于测试或错误恢复）
     * 
     * @return bool 是否成功
     */
    public function rollback() {
        global $wpdb;
        
        // 删除 structure_analytics 表
        $table_name = $this->prefix . 'content_auto_structure_analytics';
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
        
        // 删除 optimization_config 表
        $table_name = $this->prefix . 'content_auto_optimization_config';
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
        
        // 移除 article_structures 表的扩展字段
        $table_name = $this->prefix . 'content_auto_article_structures';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if ($table_exists) {
            // 移除索引
            $wpdb->query("ALTER TABLE $table_name DROP INDEX IF EXISTS `idx_source_type`");
            $wpdb->query("ALTER TABLE $table_name DROP INDEX IF EXISTS `idx_source_article_id`");
            // 移除字段
            $wpdb->query("ALTER TABLE $table_name DROP COLUMN IF EXISTS `source_type`");
            $wpdb->query("ALTER TABLE $table_name DROP COLUMN IF EXISTS `source_article_id`");
            $wpdb->query("ALTER TABLE $table_name DROP COLUMN IF EXISTS `extracted_at`");
        }
        
        // 移除 topics 表的扩展字段
        $table_name = $this->prefix . 'content_auto_topics';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if ($table_exists) {
            // 移除索引
            $wpdb->query("ALTER TABLE $table_name DROP INDEX IF EXISTS `idx_used_structure_id`");
            // 移除字段
            $wpdb->query("ALTER TABLE $table_name DROP COLUMN IF EXISTS `used_structure_id`");
            $wpdb->query("ALTER TABLE $table_name DROP COLUMN IF EXISTS `selection_method`");
            $wpdb->query("ALTER TABLE $table_name DROP COLUMN IF EXISTS `selection_weight`");
        }
        
        return true;
    }
    
    /**
     * 获取迁移状态摘要
     * 
     * @return string 状态摘要
     */
    public function get_status_summary() {
        $verification = $this->verify();
        
        $summary = "=== 智能结构优化数据库迁移状态 ===\n\n";
        
        // article_structures 表
        $summary .= "1. article_structures 表扩展:\n";
        if (isset($verification['article_structures']['table_exists']) && $verification['article_structures']['table_exists']) {
            $summary .= "   - 表存在: ✓\n";
            $summary .= "   - source_type 字段: " . ($verification['article_structures']['source_type'] ? '✓' : '✗') . "\n";
            $summary .= "   - source_article_id 字段: " . ($verification['article_structures']['source_article_id'] ? '✓' : '✗') . "\n";
            $summary .= "   - extracted_at 字段: " . ($verification['article_structures']['extracted_at'] ? '✓' : '✗') . "\n";
            $summary .= "   - idx_source_type 索引: " . ($verification['article_structures']['idx_source_type'] ? '✓' : '✗') . "\n";
            $summary .= "   - idx_source_article_id 索引: " . ($verification['article_structures']['idx_source_article_id'] ? '✓' : '✗') . "\n";
        } else {
            $summary .= "   - 表不存在: ✗\n";
        }
        
        // topics 表
        $summary .= "\n2. topics 表扩展:\n";
        if (isset($verification['topics']['table_exists']) && $verification['topics']['table_exists']) {
            $summary .= "   - 表存在: ✓\n";
            $summary .= "   - used_structure_id 字段: " . ($verification['topics']['used_structure_id'] ? '✓' : '✗') . "\n";
            $summary .= "   - selection_method 字段: " . ($verification['topics']['selection_method'] ? '✓' : '✗') . "\n";
            $summary .= "   - selection_weight 字段: " . ($verification['topics']['selection_weight'] ? '✓' : '✗') . "\n";
            $summary .= "   - idx_used_structure_id 索引: " . ($verification['topics']['idx_used_structure_id'] ? '✓' : '✗') . "\n";
        } else {
            $summary .= "   - 表不存在: ✗\n";
        }
        
        // structure_analytics 表
        $summary .= "\n3. structure_analytics 表:\n";
        if (isset($verification['structure_analytics']['table_exists']) && $verification['structure_analytics']['table_exists']) {
            $summary .= "   - 表存在: ✓\n";
            $summary .= "   - 字段: " . implode(', ', $verification['structure_analytics']['columns'] ?? []) . "\n";
        } else {
            $summary .= "   - 表不存在: ✗\n";
        }
        
        // optimization_config 表
        $summary .= "\n4. optimization_config 表:\n";
        if (isset($verification['optimization_config']['table_exists']) && $verification['optimization_config']['table_exists']) {
            $summary .= "   - 表存在: ✓\n";
            $summary .= "   - 配置数量: " . ($verification['optimization_config']['config_count'] ?? 0) . "\n";
            $summary .= "   - 默认配置完整: " . ($verification['optimization_config']['has_defaults'] ? '✓' : '✗') . "\n";
        } else {
            $summary .= "   - 表不存在: ✗\n";
        }
        
        $summary .= "\n总体状态: " . ($verification['success'] ? '✓ 全部通过' : '✗ 存在问题') . "\n";
        
        return $summary;
    }
}
