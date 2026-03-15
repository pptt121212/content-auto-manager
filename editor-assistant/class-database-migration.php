<?php
/**
 * 编辑器助手数据库迁移
 * 确保 enable_editor_assistant 字段存在于发布规则表中
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_Editor_Database_Migration {

    public function __construct() {
        add_action('admin_init', array($this, 'run_migration'));
    }

    /**
     * 运行数据库迁移
     */
    public function run_migration() {
        // 检查是否已经运行过迁移
        $migration_version = get_option('yali_ai_editor_migration_version', '0');
        
        if (version_compare($migration_version, '1.0.0', '>=')) {
            return; // 已经是最新版本
        }

        // 添加 enable_editor_assistant 字段
        $this->add_enable_editor_assistant_field();

        // 更新迁移版本
        update_option('yali_ai_editor_migration_version', '1.0.0');
    }

    /**
     * 添加 enable_editor_assistant 字段到发布规则表
     */
    private function add_enable_editor_assistant_field() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'content_auto_publish_rules';
        
        // 检查字段是否存在
        $column_exists = $wpdb->get_var(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = '$table_name' 
             AND COLUMN_NAME = 'enable_editor_assistant'"
        );

        if ($column_exists) {
            return; // 字段已存在
        }

        // 添加字段
        $wpdb->query(
            "ALTER TABLE `$table_name` 
             ADD COLUMN `enable_editor_assistant` tinyint(1) NOT NULL DEFAULT '0' 
             COMMENT '启用编辑器AI助手功能' 
             AFTER `image_prompt_template`"
        );
    }
}

// 数据库迁移将在 content_auto_manager_init 函数中统一初始化