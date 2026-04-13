<?php
/**
 * 前缀迁移脚本
 * 将 content_auto_ 前缀的数据表和选项迁移到 yali_ai_writer_ 前缀
 *
 * @package Yali_AI_Writer
 */

if (!defined('ABSPATH')) {
    exit;
}

class Yali_AI_Writer_Prefix_Migration {

    /**
     * 迁移选项名称映射
     * 从旧的 content_auto_ 前缀到新的 yali_ai_writer_ 前缀
     */
    private static $option_mappings = array(
        'content_auto_manager_version' => 'yali_ai_writer_manager_version',
        'content_auto_manager_license_data' => 'yali_ai_writer_manager_license_data',
        'content_auto_manager_license_key' => 'yali_ai_writer_manager_license_key',
        'content_auto_manager_settings' => 'yali_ai_writer_manager_settings',
        'content_auto_manager_last_run' => 'yali_ai_writer_manager_last_run',
        'content_auto_debug_mode' => 'yali_ai_writer_debug_mode',
        'content_auto_jina_api_key' => 'yali_ai_writer_jina_api_key',
        'content_auto_material_search_blacklist' => 'yali_ai_writer_material_search_blacklist',
        'content_auto_search_settings' => 'yali_ai_writer_search_settings',
        'content_auto_manager_allowed_categories' => 'yali_ai_writer_manager_allowed_categories',
        'content_auto_manager_category_filter_enabled' => 'yali_ai_writer_manager_category_filter_enabled',
        'content_auto_last_api_request' => 'yali_ai_writer_last_api_request',
        'content_auto_failed_apis' => 'yali_ai_writer_failed_apis',
        'content_auto_current_processing_job' => 'yali_ai_writer_current_processing_job',
        'content_auto_vector_centroids' => 'yali_ai_writer_vector_centroids',
        'content_auto_manager_locale' => 'yali_ai_writer_manager_locale',
        'cam_extension_api_key' => 'yali_ai_writer_extension_api_key',
        'cam_extension_task_results' => 'yali_ai_writer_extension_task_results',
        'cam_extension_task_queue' => 'yali_ai_writer_extension_task_queue',
        'cam_image_api_settings' => 'yali_ai_writer_image_api_settings',
        'cam_cache_warmup_status' => 'yali_ai_writer_cache_warmup_status',
    );

    /**
     * 迁移数据表名称映射
     * 从旧的 content_auto_ 前缀到新的 yali_ai_writer_ 前缀
     */
    private static $table_mappings = array(
        'content_auto_topics' => 'yali_ai_writer_topics',
        'content_auto_articles' => 'yali_ai_writer_articles',
        'content_auto_jobs' => 'yali_ai_writer_jobs',
        'content_auto_rules' => 'yali_ai_writer_rules',
        'content_auto_rule_items' => 'yali_ai_writer_rule_items',
        'content_auto_api_configs' => 'yali_ai_writer_api_configs',
        'content_auto_topic_tasks' => 'yali_ai_writer_topic_tasks',
        'content_auto_article_tasks' => 'yali_ai_writer_article_tasks',
        'content_auto_job_queue' => 'yali_ai_writer_job_queue',
        'content_auto_publish_rules' => 'yali_ai_writer_publish_rules',
        'content_auto_article_structures' => 'yali_ai_writer_article_structures',
        'content_auto_brand_profiles' => 'yali_ai_writer_brand_profiles',
        'content_auto_gsc_used_keywords' => 'yali_ai_writer_gsc_used_keywords',
        'content_auto_optimization_config' => 'yali_ai_writer_optimization_config',
        'content_auto_prompt_templates' => 'yali_ai_writer_prompt_templates',
        'content_auto_structure_analytics' => 'yali_ai_writer_structure_analytics',
        'content_auto_task_progress' => 'yali_ai_writer_task_progress',
    );

    /**
     * 执行完整迁移
     */
    public static function migrate() {
        global $wpdb;

        $migrated = false;

        // 迁移选项
        $migrated = self::migrate_options() || $migrated;

        // 迁移数据表
        $migrated = self::migrate_tables() || $migrated;

        // 更新数据库版本
        if ($migrated) {
            update_option('yali_ai_writer_manager_version', YALI_AI_WRITER_VERSION);
        }

        return $migrated;
    }

    /**
     * 迁移选项
     */
    private static function migrate_options() {
        $migrated = false;

        foreach (self::$option_mappings as $old_name => $new_name) {
            $old_value = get_option($old_name, false);

            if ($old_value !== false) {
                // 检查新选项是否已存在
                $new_exists = get_option($new_name, false);

                if ($new_exists === false) {
                    // 复制旧值到新选项
                    update_option($new_name, $old_value, false);

                    // 删除旧选项（可选，建议保留一段时间后再删除）
                    // delete_option($old_name);

                    $migrated = true;
                }
            }
        }

        return $migrated;
    }

    /**
     * 迁移数据表
     */
    private static function migrate_tables() {
        global $wpdb;

        $migrated = false;

        foreach (self::$table_mappings as $old_name => $new_name) {
            $old_table = $wpdb->prefix . $old_name;
            $new_table = $wpdb->prefix . $new_name;

            // 检查旧表是否存在
            $old_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$old_table}'") === $old_table;

            if ($old_table_exists) {
                // 检查新表是否已存在
                $new_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$new_table}'") === $new_table;

                if (!$new_table_exists) {
                    // 新表不存在，直接重命名旧表
                    $result = $wpdb->query("RENAME TABLE `{$old_table}` TO `{$new_table}`");

                    if ($result !== false) {
                        $migrated = true;
                    }
                } else {
                    // 新表已存在，检查是否需要复制数据
                    $old_count = $wpdb->get_var("SELECT COUNT(*) FROM `{$old_table}`");
                    $new_count = $wpdb->get_var("SELECT COUNT(*) FROM `{$new_table}`");

                    // 如果旧表有数据且新表为空，复制数据
                    if ($old_count > 0 && $new_count == 0) {
                        // 获取表的列名（排除自增ID，让数据库自动生成）
                        $columns = $wpdb->get_results("SHOW COLUMNS FROM `{$new_table}`", ARRAY_A);
                        $column_names = array();
                        foreach ($columns as $col) {
                            $column_names[] = '`' . $col['Field'] . '`';
                        }
                        $column_list = implode(', ', $column_names);

                        // 复制数据
                        $result = $wpdb->query("INSERT INTO `{$new_table}` ({$column_list}) SELECT {$column_list} FROM `{$old_table}`");

                        if ($result !== false) {
                            $migrated = true;
                        }
                    }
                }
            }
        }

        return $migrated;
    }

    /**
     * 检查是否需要迁移
     */
    public static function needs_migration() {
        global $wpdb;

        // 检查是否有旧选项存在
        foreach (self::$option_mappings as $old_name => $new_name) {
            if (get_option($old_name, false) !== false) {
                // 检查新选项是否存在
                if (get_option($new_name, false) === false) {
                    return true;
                }
            }
        }

        // 检查是否有旧数据表存在
        foreach (self::$table_mappings as $old_name => $new_name) {
            $old_table = $wpdb->prefix . $old_name;
            $new_table = $wpdb->prefix . $new_name;

            $old_exists = $wpdb->get_var("SHOW TABLES LIKE '{$old_table}'") === $old_table;
            $new_exists = $wpdb->get_var("SHOW TABLES LIKE '{$new_table}'") === $new_table;

            if ($old_exists && !$new_exists) {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取迁移状态
     */
    public static function get_migration_status() {
        global $wpdb;

        $status = array(
            'needs_migration' => false,
            'options' => array(),
            'tables' => array(),
        );

        // 检查选项
        foreach (self::$option_mappings as $old_name => $new_name) {
            $old_exists = get_option($old_name, false) !== false;
            $new_exists = get_option($new_name, false) !== false;

            $status['options'][$old_name] = array(
                'old_exists' => $old_exists,
                'new_exists' => $new_exists,
                'needs_migration' => $old_exists && !$new_exists,
            );

            if ($old_exists && !$new_exists) {
                $status['needs_migration'] = true;
            }
        }

        // 检查数据表
        foreach (self::$table_mappings as $old_name => $new_name) {
            $old_table = $wpdb->prefix . $old_name;
            $new_table = $wpdb->prefix . $new_name;

            $old_exists = $wpdb->get_var("SHOW TABLES LIKE '{$old_table}'") === $old_table;
            $new_exists = $wpdb->get_var("SHOW TABLES LIKE '{$new_table}'") === $new_table;

            $status['tables'][$old_name] = array(
                'old_exists' => $old_exists,
                'new_exists' => $new_exists,
                'needs_migration' => $old_exists && !$new_exists,
            );

            if ($old_exists && !$new_exists) {
                $status['needs_migration'] = true;
            }
        }

        return $status;
    }
}
